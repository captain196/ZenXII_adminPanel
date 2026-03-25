<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Hr Controller — Human Resources & Staff Management
 *
 * Modules:
 *   - Dashboard (overview stats)
 *   - Departments (CRUD)
 *   - Recruitment (jobs + applicants)
 *   - Leave Management (types, balances, requests, approval)
 *   - Salary Structures & Payroll (generate, finalize, pay + accounting)
 *   - Appraisals (CRUD + submit/review)
 *   - Utility (staff list for dropdowns)
 *
 * Firebase schema under Schools/{school}/HR/ (year-independent)
 * and Schools/{school}/{session}/HR/Payroll/ (year-scoped).
 *
 * Extends MY_Controller which provides:
 *   $this->school_name, $this->school_id, $this->session_year,
 *   $this->admin_id, $this->admin_name, $this->admin_role,
 *   $this->firebase, safe_path_segment(), json_success(), json_error()
 */
class Hr extends MY_Controller
{
    /** Roles for payroll and salary management */
    private const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'HR Manager'];

    /** Roles for HR operations (departments, recruitment, leave mgmt) */
    private const HR_ROLES    = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'HR Manager'];

    /** Roles that may view HR data */
    private const VIEW_ROLES  = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'HR Manager', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
        require_permission('HR');
    }

    // ====================================================================
    //  PATH HELPERS
    // ====================================================================

    private function _hr()
    {
        return "Schools/{$this->school_name}/HR";
    }

    private function _dept($id = '')
    {
        return $this->_hr() . '/Departments' . ($id ? "/{$id}" : '');
    }

    private function _jobs($id = '')
    {
        return $this->_hr() . '/Recruitment/Jobs' . ($id ? "/{$id}" : '');
    }

    private function _applicants($id = '')
    {
        return $this->_hr() . '/Recruitment/Applicants' . ($id ? "/{$id}" : '');
    }

    private function _leave_types($id = '')
    {
        return $this->_hr() . '/Leaves/Types' . ($id ? "/{$id}" : '');
    }

    private function _leave_bal($year = '', $staff = '', $type = '')
    {
        $p = $this->_hr() . '/Leaves/Balances';
        if ($year)  $p .= "/{$year}";
        if ($staff) $p .= "/{$staff}";
        if ($type)  $p .= "/{$type}";
        return $p;
    }

    private function _leave_req($id = '')
    {
        return $this->_hr() . '/Leaves/Requests' . ($id ? "/{$id}" : '');
    }

    private function _leave_audit()
    {
        return $this->_hr() . '/Leaves/Audit_log';
    }

    /**
     * Write an immutable audit entry for leave/payroll decisions.
     */
    private function _log_leave_audit(array $data): void
    {
        $entry = array_merge([
            'school_id'  => $this->school_name,
            'admin_id'   => $this->admin_id ?? '',
            'admin_name' => $this->admin_name ?? '',
            'ip'         => $this->input->ip_address(),
            'timestamp'  => date('c'),
        ], $data);
        $this->firebase->push($this->_leave_audit(), $entry);
    }

    private function _salary($staff = '')
    {
        return $this->_hr() . '/Salary_Structures' . ($staff ? "/{$staff}" : '');
    }

    /**
     * Log a payroll warning to Firebase for admin visibility.
     * Path: System/Logs/Payroll_Warnings/{push_id}
     */
    private function _log_payroll_warning(string $type, string $staffId, string $message): void
    {
        $this->firebase->push('System/Logs/Payroll_Warnings', [
            'type'       => $type,
            'staff_id'   => $staffId,
            'school'     => $this->school_name,
            'message'    => $message,
            'admin'      => $this->admin_id ?? '',
            'timestamp'  => date('c'),
        ]);
    }

    /**
     * Validate a salary structure array — returns sanitised copy or null on failure.
     * Central validator for all payroll paths.
     */
    private function _validate_structure(array $sal): ?array
    {
        $fields = ['basic','hra','da','ta','medical','other_allowances',
                    'pf_employee','pf_employer','esi_employee','esi_employer',
                    'professional_tax','tds','other_deductions'];
        $clean = [];
        foreach ($fields as $f) {
            $v = $sal[$f] ?? 0;
            if (!is_numeric($v) || !is_finite((float) $v)) return null; // corrupt
            $clean[$f] = round(max((float) $v, 0), 2); // no negatives
        }
        if ($clean['basic'] <= 0) return null; // zero/negative basic = invalid
        // Copy metadata fields
        foreach (['source','created_at','updated_at','updated_by','_version','last_synced_at'] as $m) {
            if (isset($sal[$m])) $clean[$m] = $sal[$m];
        }
        return $clean;
    }

    /**
     * Auto-create a salary structure for a staff member using their registration data.
     * Used as safety fallback during payroll generation.
     */
    private function _auto_create_from_profile(string $staffId, array $profile): ?array
    {
        $salDet = $profile['salaryDetails'] ?? [];
        $basic  = (float) ($salDet['basicSalary'] ?? 0);
        $allow  = (float) ($salDet['Allowances'] ?? 0);
        if ($basic <= 0) return null;

        // Load per-school config with fallback
        $cfg = $this->firebase->get("Schools/{$this->school_name}/Config/SalaryDefaults");
        if (!is_array($cfg)) $cfg = [];
        $get = function ($k, $fb) use ($cfg) { return isset($cfg[$k]) && is_numeric($cfg[$k]) ? (float) $cfg[$k] : $fb; };

        $hraPct = $get('hra_pct_of_basic', 40);
        $daPct  = $get('da_pct_of_basic', 10);
        $hra = round($basic * ($hraPct / 100), 2);
        $da  = round($basic * ($daPct / 100), 2);
        $rem = max(0, $allow - $hra - $da);
        if ($allow < ($hra + $da)) {
            $hra = round($allow * 0.6, 2); $da = round($allow * 0.3, 2);
            $rem = max(0, $allow - $hra - $da);
        }
        $ta = round($rem * $get('ta_share', 0.30), 2);
        $med = round($rem * $get('medical_share', 0.25), 2);

        $now = date('c');
        $struct = [
            'basic' => $basic, 'hra' => $hra, 'da' => $da, 'ta' => $ta,
            'medical' => $med, 'other_allowances' => round($rem - $ta - $med, 2),
            'pf_employee' => $get('pf_employee', 12), 'pf_employer' => $get('pf_employer', 12),
            'esi_employee' => $get('esi_employee', 0.75), 'esi_employer' => $get('esi_employer', 3.25),
            'professional_tax' => $get('professional_tax', 200), 'tds' => $get('tds', 0),
            'other_deductions' => $get('other_deductions', 0),
            'source' => 'auto_payroll', 'created_at' => $now, 'updated_at' => $now,
            'updated_by' => 'system', '_version' => 1,
        ];

        $this->firebase->set($this->_salary($staffId), $struct);
        $this->_log_payroll_warning('auto_created', $staffId,
            "Auto-created salary structure during payroll (basic={$basic}, allowances={$allow})");

        return $struct;
    }

    private function _appraisals($id = '')
    {
        return $this->_hr() . '/Appraisals' . ($id ? "/{$id}" : '');
    }

    private function _payroll_runs($id = '')
    {
        return "Schools/{$this->school_name}/{$this->session_year}/HR/Payroll/Runs" . ($id ? "/{$id}" : '');
    }

    private function _payroll_slips($runId = '', $staff = '')
    {
        $p = "Schools/{$this->school_name}/{$this->session_year}/HR/Payroll/Slips";
        if ($runId) $p .= "/{$runId}";
        if ($staff) $p .= "/{$staff}";
        return $p;
    }

    private function _counters($type = '')
    {
        return $this->_hr() . '/Counters' . ($type ? "/{$type}" : '');
    }

    // ====================================================================
    //  ID GENERATOR
    // ====================================================================

    /**
     * Generate next sequential ID.
     *
     * @param string $prefix  e.g. 'DEPT', 'JOB', 'APP', 'LT', 'LR', 'PR', 'APR'
     * @param string $type    Counter key in Firebase
     * @param int    $pad     Pad length for numeric portion (default 4)
     * @return string         e.g. 'DEPT0001'
     */
    private function _next_id(string $prefix, string $type, int $pad = 4): string
    {
        $counterPath = $this->_counters($type);
        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $current = $this->firebase->get($counterPath);
            $next = is_numeric($current) ? (int) $current + 1 : 1;
            $this->firebase->set($counterPath, $next);

            // Verify-after-write: re-read to confirm we own this value
            $verify = $this->firebase->get($counterPath);
            if ((int) $verify === $next) {
                return $prefix . str_pad($next, $pad, '0', STR_PAD_LEFT);
            }
            // Another writer overwrote — retry with their higher value
            usleep(50000 * $attempt); // 50ms, 100ms, 150ms backoff
        }
        // Fallback: use timestamp-based unique suffix to guarantee uniqueness
        $fallback = (int) ($this->firebase->get($counterPath) ?? 0) + 1;
        $this->firebase->set($counterPath, $fallback);
        return $prefix . str_pad($fallback, $pad, '0', STR_PAD_LEFT) . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
    }

    // ====================================================================
    //  PAYROLL AUDIT LOG
    // ====================================================================

    /**
     * Write a payroll audit log entry to System/Logs/Payroll/.
     *
     * @param string $action  e.g. 'generated', 'finalized', 'paid', 'deleted'
     * @param string $runId   Payroll run ID (e.g. PR0001)
     * @param array  $extra   Optional additional data to store
     */
    private function _log_payroll(string $action, string $runId, array $extra = []): void
    {
        $logData = array_merge([
            'school_id'      => $this->school_name,
            'admin_id'       => $this->admin_id ?? '',
            'admin_name'     => $this->admin_name ?? '',
            'action'         => $action,
            'payroll_run_id' => $runId,
            'timestamp'      => date('c'),
            'ip'             => $this->input->ip_address(),
        ], $extra);

        $this->firebase->push('System/Logs/Payroll', $logData);
    }

    // ====================================================================
    //  ACCOUNTING INTEGRATION HELPERS
    //  Matches Accounting.php path structure exactly:
    //    Accounts:  Schools/{school}/Accounts/ChartOfAccounts/{code}
    //    Ledger:    Schools/{school}/{year}/Accounts/Ledger/{entryId}
    //    Index:     Schools/{school}/{year}/Accounts/Ledger_index/by_date|by_account
    //    Balances:  Schools/{school}/{year}/Accounts/Closing_balances/{code}
    //    Counter:   Schools/{school}/{year}/Accounts/Voucher_counters/{type}
    // ====================================================================

    /**
     * Validate that accounting accounts exist and are active.
     * Sends json_error and exits if any are missing.
     */
    /**
     * Canonical account definitions required by payroll.
     * Used by validation, preflight UI, and auto-create.
     */
    private const PAYROLL_ACCOUNTS = [
        '5010' => ['name' => 'Teaching Staff Salary',         'category' => 'Expense',   'sub' => 'Staff Expenses'],
        '5020' => ['name' => 'Non-Teaching Staff Salary',     'category' => 'Expense',   'sub' => 'Staff Expenses'],
        '2020' => ['name' => 'Salary Payable',                'category' => 'Liability', 'sub' => 'Current Liabilities'],
        '2030' => ['name' => 'PF Payable',                    'category' => 'Liability', 'sub' => 'Statutory Liabilities'],
        '2031' => ['name' => 'ESI Payable',                   'category' => 'Liability', 'sub' => 'Statutory Liabilities'],
        '2032' => ['name' => 'TDS Payable',                   'category' => 'Liability', 'sub' => 'Statutory Liabilities'],
        '2033' => ['name' => 'Professional Tax Payable',      'category' => 'Liability', 'sub' => 'Statutory Liabilities'],
        '2034' => ['name' => 'Other Deductions Payable',      'category' => 'Liability', 'sub' => 'Statutory Liabilities'],
        '1010' => ['name' => 'Cash in Hand',                  'category' => 'Asset',     'sub' => 'Current Assets'],
        '1020' => ['name' => 'Bank Account',                  'category' => 'Asset',     'sub' => 'Current Assets'],
    ];

    /**
     * Validate that required accounting accounts exist and are active.
     * Hard-blocks with json_error if any are missing.
     *
     * @param  array  $codes     Account codes to check
     * @param  bool   $structured If true, returns structured error for preflight UI
     */
    private function _validate_accounts(array $codes, bool $structured = false): void
    {
        $coaBase = "Schools/{$this->school_name}/Accounts/ChartOfAccounts";
        $coa = $this->firebase->get($coaBase);
        if (!is_array($coa)) $coa = [];

        $missing = [];
        foreach ($codes as $code) {
            $acct = $coa[$code] ?? null;
            if (!is_array($acct) || ($acct['status'] ?? '') !== 'active') {
                $def = self::PAYROLL_ACCOUNTS[$code] ?? ['name' => "Account {$code}", 'category' => 'Unknown'];
                $missing[] = [
                    'code'     => $code,
                    'name'     => $def['name'],
                    'category' => $def['category'],
                ];
            }
        }
        if (!empty($missing)) {
            if ($structured) {
                $this->json_error_data(
                    'Missing required accounting accounts. Please set them up before generating payroll.',
                    ['missing_accounts' => $missing, 'error_type' => 'missing_accounts']
                );
            }
            $this->json_error(
                'Missing or inactive accounting accounts: '
                . implode(', ', array_column($missing, 'code'))
                . '. Please set them up in Accounting first.'
            );
        }
    }

    /**
     * Return JSON error with extra data fields (for structured UI).
     */
    private function json_error_data(string $message, array $data): void
    {
        header('Content-Type: application/json');
        http_response_code(200); // 200 so jQuery .then() fires, not .catch()
        echo json_encode(array_merge([
            'status'  => 'error',
            'message' => $message,
        ], $data));
        exit;
    }

    /**
     * Create a journal entry compatible with the Accounting module.
     *
     * @param string $narration  Human-readable description
     * @param array  $lines      Array of [account_code, dr, cr] entries
     * @param string $sourceRef  Reference ID (e.g. payroll run ID)
     * @return string            The generated entry ID
     */
    /**
     * @param string $narration   Human-readable description
     * @param array  $lines       Array of [account_code, dr, cr, cost_center?]
     * @param string $sourceRef   Reference ID (e.g. payroll run ID)
     * @return string             The generated entry ID
     */
    private function _create_acct_journal(string $narration, array $lines, string $sourceRef = ''): string
    {
        $bp = "Schools/{$this->school_name}/{$this->session_year}";

        // Generate voucher number via the Accounting counter
        $counterPath = "{$bp}/Accounts/Voucher_counters/Journal";
        $maxAttempts = 3;
        $newSeq = 0;
        $resolved = false;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $currentSeq = (int) ($this->firebase->get($counterPath) ?? 0);
            $newSeq     = $currentSeq + 1;
            $this->firebase->set($counterPath, $newSeq);

            // Verify-after-write: re-read to confirm we own this value
            $verify = $this->firebase->get($counterPath);
            if ((int) $verify === $newSeq) {
                $resolved = true;
                break;
            }
            usleep(50000 * $attempt);
        }
        if (!$resolved) {
            $newSeq = (int) ($this->firebase->get($counterPath) ?? 0) + 1;
            $this->firebase->set($counterPath, $newSeq);
        }
        $voucherNo = 'JV-' . str_pad($newSeq, 6, '0', STR_PAD_LEFT);

        // Generate entry ID matching Accounting format
        $entryId = 'JE_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

        // Resolve account names from PAYROLL_ACCOUNTS or CoA
        $coaPath = "Schools/{$this->school_name}/Accounts/ChartOfAccounts";
        $coa = $this->firebase->get($coaPath);
        if (!is_array($coa)) $coa = [];

        // Calculate totals and affected accounts
        $totalDr = 0;
        $totalCr = 0;
        $affected = [];
        foreach ($lines as &$line) {
            $dr = round((float) ($line['dr'] ?? 0), 2);
            $cr = round((float) ($line['cr'] ?? 0), 2);
            $line['dr'] = $dr;
            $line['cr'] = $cr;
            $totalDr += $dr;
            $totalCr += $cr;
            $ac = $line['account_code'] ?? '';
            if ($ac !== '' && empty($line['account_name'])) {
                $line['account_name'] = $coa[$ac]['name']
                    ?? (self::PAYROLL_ACCOUNTS[$ac]['name'] ?? '');
            }
            if ($ac !== '') {
                $affected[$ac] = [
                    'dr' => ($affected[$ac]['dr'] ?? 0) + $dr,
                    'cr' => ($affected[$ac]['cr'] ?? 0) + $cr,
                ];
            }
        }
        unset($line);

        $entry = [
            'date'         => date('Y-m-d'),
            'voucher_no'   => $voucherNo,
            'voucher_type' => 'Journal',
            'narration'    => $narration,
            'lines'        => array_values($lines),
            'total_dr'     => round($totalDr, 2),
            'total_cr'     => round($totalCr, 2),
            'source'       => 'HR_Payroll',
            'source_ref'   => $sourceRef ?: null,
            'is_finalized' => false,
            'status'       => 'active',
            'created_by'   => $this->admin_id ?? '',
            'created_at'   => date('c'),
        ];

        // Write ledger entry
        $this->firebase->set("{$bp}/Accounts/Ledger/{$entryId}", $entry);

        // Write indices (by_date and by_account)
        $safeDate = date('Y-m-d');
        $this->firebase->set("{$bp}/Accounts/Ledger_index/by_date/{$safeDate}/{$entryId}", true);
        foreach (array_keys($affected) as $acCode) {
            $this->firebase->set("{$bp}/Accounts/Ledger_index/by_account/{$acCode}/{$entryId}", true);
        }

        // Update closing balances cache
        foreach ($affected as $code => $amounts) {
            $balPath = "{$bp}/Accounts/Closing_balances/{$code}";
            $current = $this->firebase->get($balPath);
            $pDr = (float) ($current['period_dr'] ?? 0) + $amounts['dr'];
            $pCr = (float) ($current['period_cr'] ?? 0) + $amounts['cr'];
            $this->firebase->set($balPath, [
                'period_dr'     => round($pDr, 2),
                'period_cr'     => round($pCr, 2),
                'last_computed' => date('c'),
            ]);
        }

        return $entryId;
    }

    /**
     * Soft-delete a journal entry, reverse balances, and clean indices.
     * Matches Accounting.php delete_journal_entry() behavior.
     */
    private function _delete_acct_journal(string $entryId): void
    {
        $bp    = "Schools/{$this->school_name}/{$this->session_year}";
        $entry = $this->firebase->get("{$bp}/Accounts/Ledger/{$entryId}");
        if (!is_array($entry) || ($entry['status'] ?? '') === 'deleted') {
            return;
        }

        // Collect affected accounts from lines
        $affected = [];
        foreach ($entry['lines'] ?? [] as $line) {
            $ac = $line['account_code'] ?? '';
            if ($ac === '') continue;
            $affected[$ac] = [
                'dr' => ($affected[$ac]['dr'] ?? 0) + (float) ($line['dr'] ?? 0),
                'cr' => ($affected[$ac]['cr'] ?? 0) + (float) ($line['cr'] ?? 0),
            ];
        }

        // Reverse closing balances
        foreach ($affected as $code => $amounts) {
            $balPath = "{$bp}/Accounts/Closing_balances/{$code}";
            $current = $this->firebase->get($balPath);
            $pDr = (float) ($current['period_dr'] ?? 0) - $amounts['dr'];
            $pCr = (float) ($current['period_cr'] ?? 0) - $amounts['cr'];
            $this->firebase->set($balPath, [
                'period_dr'     => round($pDr, 2),
                'period_cr'     => round($pCr, 2),
                'last_computed' => date('c'),
            ]);
        }

        // Remove indices
        $date = $entry['date'] ?? '';
        if ($date !== '') {
            $this->firebase->delete("{$bp}/Accounts/Ledger_index/by_date/{$date}", $entryId);
        }
        foreach (array_keys($affected) as $acCode) {
            $this->firebase->delete("{$bp}/Accounts/Ledger_index/by_account/{$acCode}", $entryId);
        }

        // Soft-delete the entry
        $this->firebase->update("{$bp}/Accounts/Ledger/{$entryId}", [
            'status'     => 'deleted',
            'deleted_by' => $this->admin_id ?? '',
            'deleted_at' => date('c'),
        ]);
    }

    /**
     * Create a REVERSAL journal entry — proper accounting correction.
     * The original entry stays intact (audit trail); a new entry with
     * swapped DR/CR is created to cancel its effect.
     *
     * @param  string $originalId  The ledger entry ID to reverse
     * @param  string $reason      Reason for reversal
     * @return string              The reversal entry ID
     */
    private function _create_reversal_journal(string $originalId, string $reason = ''): string
    {
        $bp = "Schools/{$this->school_name}/{$this->session_year}";
        $original = $this->firebase->get("{$bp}/Accounts/Ledger/{$originalId}");

        if (!is_array($original) || ($original['status'] ?? '') === 'deleted') {
            log_message('error', "Cannot reverse entry {$originalId} — not found or already deleted");
            return '';
        }

        // Swap DR/CR on every line
        $reversedLines = [];
        foreach ($original['lines'] ?? [] as $line) {
            $reversedLines[] = [
                'account_code' => $line['account_code'] ?? '',
                'account_name' => $line['account_name'] ?? '',
                'dr'           => round((float) ($line['cr'] ?? 0), 2),
                'cr'           => round((float) ($line['dr'] ?? 0), 2),
            ];
        }

        $narration = 'REVERSAL: ' . ($original['narration'] ?? '');
        if ($reason !== '') $narration .= " — Reason: {$reason}";

        $reversalId = $this->_create_acct_journal($narration, $reversedLines, $originalId);

        // Mark original as reversed (not deleted — it stays in the ledger)
        $this->firebase->update("{$bp}/Accounts/Ledger/{$originalId}", [
            'is_reversed'     => true,
            'reversal_id'     => $reversalId,
            'reversed_by'     => $this->admin_id ?? '',
            'reversed_at'     => date('c'),
            'reversal_reason' => $reason,
        ]);

        log_message('info',
            "Reversal journal created: original={$originalId} reversal={$reversalId} school=[{$this->school_name}]"
        );

        return $reversalId;
    }

    /**
     * POST — Lock a payroll month. Prevents regeneration or deletion after lock.
     * Params: month, year
     */
    public function lock_payroll_month()
    {
        $this->_require_role(self::ADMIN_ROLES, 'lock_payroll_month');

        $month = trim($this->input->post('month') ?? '');
        $year  = trim($this->input->post('year') ?? '');

        $validMonths = ['January','February','March','April','May','June',
                        'July','August','September','October','November','December'];
        if (!in_array($month, $validMonths, true) || !preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid month or year.');
        }

        $lockPath = "Schools/{$this->school_name}/{$this->session_year}/HR/Payroll/Locks/{$year}_{$month}";
        $existing = $this->firebase->get($lockPath);
        if (is_array($existing) && !empty($existing['locked'])) {
            $this->json_error("{$month} {$year} is already locked by " . ($existing['locked_by'] ?? 'unknown') . '.');
        }

        // Verify a Finalized or Paid run exists for this month
        $runs = $this->firebase->get($this->_payroll_runs());
        $runFound = false;
        if (is_array($runs)) {
            foreach ($runs as $rid => $r) {
                if (($r['month'] ?? '') === $month && ($r['year'] ?? '') === $year) {
                    $st = $r['status'] ?? '';
                    if ($st === 'Finalized' || $st === 'Paid' || $st === 'Partially Paid') {
                        $runFound = true;
                    }
                    break;
                }
            }
        }
        if (!$runFound) {
            $this->json_error("No finalized payroll run found for {$month} {$year}. Finalize first.");
        }

        $this->firebase->set($lockPath, [
            'locked'    => true,
            'locked_by' => $this->admin_name,
            'locked_at' => date('c'),
            'month'     => $month,
            'year'      => $year,
        ]);

        $this->_log_payroll('month_locked', '', ['month' => $month, 'year' => $year]);

        $this->json_success(['message' => "{$month} {$year} payroll locked. No further changes allowed."]);
    }

    /**
     * Check if a payroll month is locked. Used by generate_payroll and delete_payroll_run.
     */
    private function _check_payroll_lock(string $month, string $year): void
    {
        $lockPath = "Schools/{$this->school_name}/{$this->session_year}/HR/Payroll/Locks/{$year}_{$month}";
        $lock = $this->firebase->get($lockPath);
        if (is_array($lock) && !empty($lock['locked'])) {
            $this->json_error(
                "{$month} {$year} payroll is locked by " . ($lock['locked_by'] ?? 'admin')
                . " on " . ($lock['locked_at'] ?? 'unknown date') . '. Unlock before making changes.'
            );
        }
    }

    // ====================================================================
    //  PAGE ROUTE
    // ====================================================================

    /**
     * Single page entry point — loads view with active tab.
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'hr_view');

        $tab = $this->uri->segment(2, 'dashboard');
        $allowed = ['dashboard', 'departments', 'recruitment', 'leaves', 'payroll', 'appraisals'];
        if (!in_array($tab, $allowed, true)) {
            $tab = 'dashboard';
        }

        $data['active_tab'] = $tab;
        $this->load->view('include/header');
        $this->load->view('hr/index', $data);
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  DASHBOARD
    // ====================================================================

    /**
     * GET — Returns dashboard statistics.
     */
    public function get_dashboard()
    {
        $this->_require_role(self::VIEW_ROLES, 'hr_dashboard');

        // Staff count from session roster
        $roster = $this->firebase->get("Schools/{$this->school_name}/{$this->session_year}/Teachers");
        $staffCount = is_array($roster) ? count($roster) : 0;

        // Department count
        $depts = $this->firebase->get($this->_dept());
        $deptCount = is_array($depts) ? count($depts) : 0;

        // Open jobs & applicant counts
        $jobs = $this->firebase->get($this->_jobs());
        $openJobs = 0;
        if (is_array($jobs)) {
            foreach ($jobs as $j) {
                if (isset($j['status']) && $j['status'] === 'Open') {
                    $openJobs++;
                }
            }
        }

        $applicants = $this->firebase->get($this->_applicants());
        $totalApplicants = is_array($applicants) ? count($applicants) : 0;

        // Pending leave requests
        $leaveReqs = $this->firebase->get($this->_leave_req());
        $pendingLeaves = 0;
        if (is_array($leaveReqs)) {
            foreach ($leaveReqs as $lr) {
                if (isset($lr['status']) && $lr['status'] === 'Pending') {
                    $pendingLeaves++;
                }
            }
        }

        // Payroll runs this session
        $runs = $this->firebase->get($this->_payroll_runs());
        $payrollRuns = is_array($runs) ? count($runs) : 0;
        $lastPayroll = null;
        if (is_array($runs)) {
            $latest = null;
            foreach ($runs as $rid => $r) {
                if (!$latest || (isset($r['created_at']) && $r['created_at'] > ($latest['created_at'] ?? ''))) {
                    $latest = $r;
                    $latest['id'] = $rid;
                }
            }
            if ($latest) {
                $lastPayroll = [
                    'month'  => $latest['month'] ?? '',
                    'year'   => $latest['year'] ?? '',
                    'status' => $latest['status'] ?? '',
                ];
            }
        }

        // Recent leave requests (last 5)
        $recentLeaves = [];
        if (is_array($leaveReqs)) {
            uasort($leaveReqs, function ($a, $b) {
                return strcmp($b['applied_on'] ?? '', $a['applied_on'] ?? '');
            });
            $i = 0;
            foreach ($leaveReqs as $id => $lr) {
                if ($i >= 5) break;
                $recentLeaves[] = [
                    'id'         => $id,
                    'staff_name' => $lr['staff_name'] ?? '',
                    'type_name'  => $lr['type_name'] ?? '',
                    'status'     => $lr['status'] ?? '',
                    'from_date'  => $lr['from_date'] ?? '',
                    'to_date'    => $lr['to_date'] ?? '',
                ];
                $i++;
            }
        }

        // Appraisal count
        $appraisals = $this->firebase->get($this->_appraisals());
        $appraisalCount = is_array($appraisals) ? count($appraisals) : 0;

        // ATS pipeline summary
        $pipelineCounts = ['Applied' => 0, 'Shortlisted' => 0, 'Interviewed' => 0, 'Selected' => 0, 'Hired' => 0];
        $rejectedCount  = 0;
        $recentApplicants = [];
        $stageMap = [
            'Applied' => 'Applied', 'Shortlisted' => 'Shortlisted',
            'Interview' => 'Interviewed', 'Interviewed' => 'Interviewed',
            'Selected' => 'Selected', 'Joined' => 'Hired', 'Hired' => 'Hired',
        ];
        if (is_array($applicants)) {
            foreach ($applicants as $appId => $app) {
                if (!is_array($app)) continue;
                $rawStage = $app['stage'] ?? ($app['status'] ?? 'Applied');
                $stage    = $stageMap[$rawStage] ?? 'Applied';
                $atsStatus = $app['ats_status'] ?? 'active';

                if ($atsStatus === 'rejected') {
                    $rejectedCount++;
                } elseif (isset($pipelineCounts[$stage])) {
                    $pipelineCounts[$stage]++;
                }

                $app['id'] = $appId;
                $app['_stage'] = $stage;
                $recentApplicants[] = $app;
            }
            usort($recentApplicants, fn($a, $b) => strcmp($b['updated_at'] ?? $b['applied_date'] ?? '', $a['updated_at'] ?? $a['applied_date'] ?? ''));
            $recentApplicants = array_slice($recentApplicants, 0, 8);
        }

        // Build job title lookup for recent applicants
        $jobTitles = [];
        if (is_array($jobs)) {
            foreach ($jobs as $jid => $j) {
                if (is_array($j)) $jobTitles[$jid] = $j['title'] ?? '';
            }
        }
        foreach ($recentApplicants as &$ra) {
            $ra['job_title'] = $jobTitles[$ra['job_id'] ?? ''] ?? '';
        }
        unset($ra);

        $this->json_success([
            'staff_count'        => $staffCount,
            'dept_count'         => $deptCount,
            'open_jobs'          => $openJobs,
            'total_applicants'   => $totalApplicants,
            'pending_leaves'     => $pendingLeaves,
            'payroll_runs'       => $payrollRuns,
            'last_payroll'       => $lastPayroll,
            'appraisal_count'    => $appraisalCount,
            'recent_leaves'      => $recentLeaves,
            'pipeline_counts'    => $pipelineCounts,
            'rejected_count'     => $rejectedCount,
            'recent_applicants'  => $recentApplicants,
        ]);
    }

    // ====================================================================
    //  DEPARTMENTS
    // ====================================================================

    /**
     * GET — List all departments.
     */
    public function get_departments()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_departments');

        $depts = $this->firebase->get($this->_dept());
        if (!is_array($depts)) $depts = [];

        // Compute real staff counts from profiles
        $profiles = $this->firebase->get("Users/Teachers/{$this->school_name}");
        $countByDept = [];
        $staffByDept = [];
        if (is_array($profiles)) {
            foreach ($profiles as $sid => $p) {
                if (!is_array($p) || $sid === 'Count') continue;
                $dept = trim($p['Department'] ?? '');
                if ($dept === '') $dept = 'Unassigned';
                if (!isset($countByDept[$dept])) {
                    $countByDept[$dept] = 0;
                    $staffByDept[$dept] = [];
                }
                $countByDept[$dept]++;
                $staffByDept[$dept][] = [
                    'id'       => $sid,
                    'name'     => $p['Name'] ?? $sid,
                    'position' => $p['Position'] ?? '',
                    'phone'    => $p['Phone Number'] ?? '',
                ];
            }
        }

        // Load active job openings per department
        $allJobs = $this->firebase->get($this->_jobs());
        $jobsByDept = [];
        if (is_array($allJobs)) {
            foreach ($allJobs as $jid => $j) {
                if (!is_array($j)) continue;
                $jDept = $j['department'] ?? '';
                $jStatus = $j['status'] ?? '';
                if ($jDept === '' || $jStatus === 'Closed') continue;
                if (!isset($jobsByDept[$jDept])) $jobsByDept[$jDept] = [];
                $jobsByDept[$jDept][] = [
                    'id'               => $jid,
                    'title'            => $j['title'] ?? '',
                    'total_positions'  => (int) ($j['total_positions'] ?? $j['positions'] ?? 0),
                    'filled_positions' => (int) ($j['filled_positions'] ?? 0),
                    'remaining'        => (int) ($j['positions'] ?? 0),
                    'status'           => $jStatus,
                    'deadline'         => $j['deadline'] ?? '',
                ];
            }
        }

        $list = [];
        foreach ($depts as $id => $d) {
            $dName = $d['name'] ?? '';
            $d['id']           = $id;
            $d['staff_count']  = $countByDept[$dName] ?? 0;
            $d['staff']        = $staffByDept[$dName] ?? [];
            $d['openings']     = $jobsByDept[$dName] ?? [];
            $d['open_count']   = count($d['openings']);
            $list[] = $d;
        }

        $this->json_success(['departments' => $list]);
    }

    /**
     * POST — Create or update a department.
     * Params: id (optional for update), name, head_staff_id, description, status
     */
    public function save_department()
    {
        $this->_require_role(self::HR_ROLES, 'save_department');

        $id          = trim($this->input->post('id') ?? '');
        $name        = trim($this->input->post('name') ?? '');
        $headStaffId = trim($this->input->post('head_staff_id') ?? '');
        $description = trim($this->input->post('description') ?? '');
        $status      = trim($this->input->post('status') ?? 'Active');

        if ($name === '') {
            $this->json_error('Department name is required.');
        }
        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        $now = date('c');
        $isNew = ($id === '');

        if ($isNew) {
            $id = $this->_next_id('DEPT', 'Department');
        }

        // Check for duplicate name (excluding self when editing)
        $existing = $this->firebase->get($this->_dept());
        if (is_array($existing)) {
            foreach ($existing as $eid => $ed) {
                if ($eid !== $id && isset($ed['name']) && strtolower($ed['name']) === strtolower($name)) {
                    $this->json_error('A department with this name already exists.');
                }
            }
        }

        $data = [
            'name'          => $name,
            'head_staff_id' => $headStaffId,
            'description'   => $description,
            'status'        => $status,
            'updated_at'    => $now,
        ];
        if ($isNew) {
            $data['created_at'] = $now;
        }

        $this->firebase->set($this->_dept($id), $data);
        $this->json_success(['id' => $id, 'message' => $isNew ? 'Department created.' : 'Department updated.']);
    }

    /**
     * POST — Delete a department.
     * Params: id
     */
    public function delete_department()
    {
        $this->_require_role(self::HR_ROLES, 'delete_department');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        // Check if department exists
        $dept = $this->firebase->get($this->_dept($id));
        if (!is_array($dept)) {
            $this->json_error('Department not found.');
        }
        $deptName = $dept['name'] ?? '';

        // Check if staff are assigned to this department
        $staffProfiles = $this->firebase->get("Users/Teachers/{$this->school_name}");
        if (is_array($staffProfiles)) {
            foreach ($staffProfiles as $sid => $sp) {
                if (isset($sp['Department']) && $sp['Department'] === $deptName) {
                    $this->json_error('Cannot delete: staff members are assigned to this department. Reassign them first.');
                }
            }
        }

        // Check if any open jobs reference this department
        $jobs = $this->firebase->get($this->_jobs());
        if (is_array($jobs)) {
            foreach ($jobs as $jid => $j) {
                if (isset($j['department']) && $j['department'] === $deptName && isset($j['status']) && $j['status'] === 'Open') {
                    $this->json_error('Cannot delete: there are open job postings in this department.');
                }
            }
        }

        $this->firebase->delete($this->_dept($id));
        $this->json_success(['message' => 'Department deleted.']);
    }

    // ====================================================================
    //  RECRUITMENT — JOBS
    // ====================================================================

    /**
     * GET — List all job postings. Optional filter: ?status=Open
     */
    public function get_jobs()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_jobs');

        $filterStatus = trim($this->input->get('status') ?? '');
        $jobs = $this->firebase->get($this->_jobs());
        $list = [];
        if (is_array($jobs)) {
            foreach ($jobs as $id => $j) {
                if ($filterStatus !== '' && isset($j['status']) && $j['status'] !== $filterStatus) {
                    continue;
                }
                $j['id'] = $id;
                $j['applicant_count'] = 0;
                $list[] = $j;
            }
        }

        // Count applicants per job
        $applicants = $this->firebase->get($this->_applicants());
        if (is_array($applicants)) {
            $countsByJob = [];
            foreach ($applicants as $a) {
                $jid = $a['job_id'] ?? '';
                if ($jid !== '') {
                    $countsByJob[$jid] = ($countsByJob[$jid] ?? 0) + 1;
                }
            }
            foreach ($list as &$j) {
                $j['applicant_count'] = $countsByJob[$j['id']] ?? 0;
            }
            unset($j);
        }

        $this->json_success(['jobs' => $list]);
    }

    /**
     * POST — Create or update a job posting.
     */
    public function save_job()
    {
        $this->_require_role(self::HR_ROLES, 'save_job');

        $id                  = trim($this->input->post('id') ?? '');
        $title               = trim($this->input->post('title') ?? '');
        $department          = trim($this->input->post('department') ?? '');
        $positions           = (int) ($this->input->post('positions') ?? 1);
        $qualifications      = trim($this->input->post('qualifications') ?? '');
        $experienceRequired  = trim($this->input->post('experience_required') ?? '');
        $salaryRangeMin      = (float) ($this->input->post('salary_range_min') ?? 0);
        $salaryRangeMax      = (float) ($this->input->post('salary_range_max') ?? 0);
        $status              = trim($this->input->post('status') ?? 'Open');
        $deadline            = trim($this->input->post('deadline') ?? '');
        $description         = trim($this->input->post('description') ?? '');

        if ($title === '') {
            $this->json_error('Job title is required.');
        }
        if ($department === '') {
            $this->json_error('Department is required.');
        }
        if ($positions < 1) {
            $this->json_error('Number of positions must be at least 1.');
        }
        if (!in_array($status, ['Open', 'Closed', 'On_Hold'], true)) {
            $status = 'Open';
        }
        if ($deadline !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
            $this->json_error('Deadline must be in YYYY-MM-DD format.');
        }

        $now   = date('c');
        $isNew = ($id === '');

        if ($isNew) {
            $id = $this->_next_id('JOB', 'Job');
        }

        // Resolve department_id from name (link to HR/Departments entity)
        $departmentId = trim($this->input->post('department_id') ?? '');
        if ($departmentId === '') {
            // Try to find department by name
            $allDepts = $this->firebase->get($this->_dept());
            if (is_array($allDepts)) {
                foreach ($allDepts as $did => $dd) {
                    if (($dd['name'] ?? '') === $department) {
                        $departmentId = $did;
                        break;
                    }
                }
            }
        }

        $data = [
            'title'               => $title,
            'department'          => $department,
            'department_id'       => $departmentId,
            'total_positions'     => $positions,
            'positions'           => $positions,  // backward compat: remaining positions
            'qualifications'      => $qualifications,
            'experience_required' => $experienceRequired,
            'salary_range_min'    => $salaryRangeMin,
            'salary_range_max'    => $salaryRangeMax,
            'status'              => $status,
            'deadline'            => $deadline,
            'description'         => $description,
            'updated_at'          => $now,
        ];
        if ($isNew) {
            $data['created_at']       = $now;
            $data['created_by']       = $this->admin_name;
            $data['filled_positions'] = 0;
        }

        $this->firebase->set($this->_jobs($id), $data);

        // ── Auto-create a circular for new job postings ──────────────
        $circularId = '';
        if ($isNew && $status === 'Open') {
            $circularId = $this->_create_job_circular($id, $data);
            // Store circular reference on the job record
            if ($circularId) {
                $this->firebase->update($this->_jobs($id), ['circular_id' => $circularId]);
            }
        }

        $msg = $isNew ? 'Job posting created.' : 'Job posting updated.';
        if ($circularId) {
            $msg .= " Circular {$circularId} has been auto-generated and published.";
        }

        $this->json_success(['id' => $id, 'circular_id' => $circularId, 'message' => $msg]);
    }

    /**
     * Creates a circular in the Communication module for a new job posting.
     * Returns the circular ID on success, empty string on failure.
     */
    private function _create_job_circular(string $jobId, array $job): string
    {
        try {
            $school   = $this->school_name;
            $commBase = "Schools/{$school}/Communication";

            // Generate circular ID
            $counterPath = "{$commBase}/Counters/Circular";
            $cur  = (int) ($this->firebase->get($counterPath) ?? 0);
            $next = $cur + 1;
            $this->firebase->set($counterPath, $next);
            $circularId = 'CIR' . str_pad($next, 4, '0', STR_PAD_LEFT);

            $title   = "Hiring: " . ($job['title'] ?? 'Open Position');
            $poster  = $this->_build_circular_poster($job, $circularId);

            $this->firebase->set("{$commBase}/Circulars/{$circularId}", [
                'title'           => $title,
                'description'     => $poster,
                'category'        => 'Recruitment',
                'target_group'    => 'All Staff',
                'attachment_url'  => '',
                'attachment_name' => '',
                'issued_by'       => $this->admin_id,
                'issued_by_name'  => $this->admin_name,
                'issued_date'     => date('Y-m-d'),
                'expiry_date'     => $job['deadline'] ?? '',
                'status'          => 'Active',
                'created_at'      => date('c'),
                'updated_at'      => date('c'),
                'source'          => 'hr_recruitment',
                'source_id'       => $jobId,
                'is_poster'       => true,
            ]);

            // Legacy notices for mobile app
            $this->firebase->push("Schools/{$school}/All Notices/Announcements", [
                'title'       => "[Job Circular] {$title}",
                'description' => strip_tags($poster),
                'priority'    => 'High',
                'category'    => 'Recruitment',
                'target_group'=> 'All Staff',
                'issued_by'   => $this->admin_name,
                'date'        => date('Y-m-d'),
                'created_at'  => date('c'),
            ]);

            log_message('info', "HR: Auto-created circular {$circularId} for job {$jobId}");
            return $circularId;
        } catch (\Exception $e) {
            log_message('error', "HR: Failed to create circular for job {$jobId}: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Build a professional HTML poster for a job circular.
     */
    private function _build_circular_poster(array $job, string $circularId = ''): string
    {
        $e = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

        $schoolName = $e($this->school_display_name ?: $this->school_name);
        $title      = $e($job['title'] ?? 'Open Position');
        $dept       = $e($job['department'] ?? '');
        $positions  = (int)($job['positions'] ?? 1);
        $quals      = $e($job['qualifications'] ?? '');
        $exp        = $e($job['experience_required'] ?? '');
        $salMin     = (float)($job['salary_range_min'] ?? 0);
        $salMax     = (float)($job['salary_range_max'] ?? 0);
        $deadline   = $job['deadline'] ?? '';
        $desc       = $e($job['description'] ?? '');
        $refId      = $e($circularId);
        $today      = date('d M Y');

        $salary = '';
        if ($salMin > 0 && $salMax > 0) {
            $salary = number_format($salMin) . ' &ndash; ' . number_format($salMax);
        } elseif ($salMax > 0) {
            $salary = 'Up to ' . number_format($salMax);
        } elseif ($salMin > 0) {
            $salary = number_format($salMin) . '+';
        }

        $deadlineFormatted = $deadline ? date('d M Y', strtotime($deadline)) : '';

        // Build detail rows
        $details = '';
        $row = function($icon, $label, $value) use (&$details) {
            if (trim($value) === '') return;
            $details .= "<tr><td style=\"padding:8px 12px;width:36px;text-align:center;color:#0f766e;font-size:16px;\"><i class=\"fa {$icon}\"></i></td>"
                       . "<td style=\"padding:8px 0;font-size:13px;color:#64748b;width:130px;\">{$label}</td>"
                       . "<td style=\"padding:8px 0;font-size:14px;color:#1e293b;font-weight:600;\">{$value}</td></tr>";
        };

        $row('fa-building-o', 'Department',      $dept);
        $row('fa-users',      'Positions',        $positions . ($positions > 1 ? ' vacancies' : ' vacancy'));
        $row('fa-graduation-cap', 'Qualifications', $quals);
        $row('fa-clock-o',    'Experience',       $exp);
        if ($salary) $row('fa-inr', 'Salary Range', "&#x20B9; {$salary} /month");
        if ($deadlineFormatted) $row('fa-calendar', 'Last Date', $deadlineFormatted);

        $descHtml = '';
        if ($desc !== '') {
            $descHtml = "<div style=\"margin-top:20px;padding:14px 18px;background:#f0fdf4;border-left:4px solid #0f766e;border-radius:0 8px 8px 0;\">"
                      . "<div style=\"font-size:12px;font-weight:700;color:#0f766e;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;\">About the Role</div>"
                      . "<div style=\"font-size:13.5px;color:#334155;line-height:1.6;white-space:pre-line;\">{$desc}</div></div>";
        }

        return <<<HTML
<div style="font-family:'Segoe UI',system-ui,-apple-system,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">
  <!-- Header -->
  <div style="background:linear-gradient(135deg,#0f766e 0%,#14b8a6 100%);padding:32px 28px 26px;text-align:center;position:relative;">
    <div style="font-size:13px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,.75);margin-bottom:6px;">{$schoolName}</div>
    <div style="display:inline-block;padding:6px 20px;background:rgba(255,255,255,.2);border-radius:20px;margin-bottom:14px;">
      <span style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#fff;">We Are Hiring</span>
    </div>
    <h1 style="margin:0;font-size:26px;font-weight:800;color:#fff;line-height:1.3;">{$title}</h1>
  </div>

  <!-- Body -->
  <div style="padding:24px 28px;">
    <table style="width:100%;border-collapse:collapse;">{$details}</table>
    {$descHtml}

    <!-- CTA -->
    <div style="margin-top:24px;text-align:center;">
      <div style="display:inline-block;padding:12px 36px;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;font-size:15px;font-weight:700;border-radius:10px;letter-spacing:.5px;">
        Apply Now
      </div>
      <div style="margin-top:10px;font-size:12.5px;color:#64748b;">Contact the school administration for application details</div>
    </div>
  </div>

  <!-- Footer -->
  <div style="padding:14px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;">
    <div style="font-size:11px;color:#94a3b8;">{$refId} &middot; Issued: {$today}</div>
    <div style="font-size:11px;color:#94a3b8;">{$schoolName}</div>
  </div>
</div>
HTML;
    }

    /**
     * POST — Delete a job posting + cascade-deactivate its circular.
     * Params: id
     */
    public function delete_job()
    {
        $this->_require_role(self::HR_ROLES, 'delete_job');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        $existing = $this->firebase->get($this->_jobs($id));
        if (!is_array($existing)) {
            $this->json_error('Job posting not found.');
        }

        // Check if applicants exist for this job
        $applicants = $this->firebase->get($this->_applicants());
        if (is_array($applicants)) {
            foreach ($applicants as $a) {
                if (isset($a['job_id']) && $a['job_id'] === $id) {
                    $this->json_error('Cannot delete: applicants are linked to this job posting. Delete or reassign applicants first.');
                }
            }
        }

        // Cascade: mark linked circular as Inactive
        $circularId = $existing['circular_id'] ?? '';
        if ($circularId !== '') {
            $commPath = "Schools/{$this->school_name}/Communication/Circulars/{$circularId}";
            $circ = $this->firebase->get($commPath);
            if (is_array($circ)) {
                $this->firebase->update($commPath, [
                    'status'     => 'Inactive',
                    'updated_at' => date('c'),
                    'updated_by' => $this->admin_id,
                ]);
            }
        }

        $this->firebase->delete($this->_jobs($id));
        $this->json_success(['message' => 'Job posting deleted.' . ($circularId ? " Circular {$circularId} marked inactive." : '')]);
    }

    /**
     * GET — View circular poster HTML for a job posting.
     * Params: ?job_id=JOB0001  OR  ?circular_id=CIR0001
     */
    public function view_circular()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_circular');
        header('Content-Type: application/json');

        $jobId      = trim($this->input->get('job_id') ?? '');
        $circularId = trim($this->input->get('circular_id') ?? '');

        $school = $this->school_name;
        $job    = null;

        if ($jobId) {
            $job = $this->firebase->get($this->_jobs($jobId));
            if (!is_array($job)) {
                echo json_encode(['status' => 'error', 'message' => 'Job not found.']);
                return;
            }
            $circularId = $job['circular_id'] ?? '';
        }

        if ($circularId) {
            $circular = $this->firebase->get("Schools/{$school}/Communication/Circulars/{$circularId}");
            if (is_array($circular) && !$job) {
                $srcId = $circular['source_id'] ?? '';
                if ($srcId) $job = $this->firebase->get($this->_jobs($srcId));
            }
        }

        if (!is_array($job)) {
            echo json_encode(['status' => 'error', 'message' => 'No job data found.']);
            return;
        }

        $posterHtml = $this->_build_circular_poster($job, $circularId);

        echo json_encode([
            'status'      => 'success',
            'poster_html' => $posterHtml,
            'circular_id' => $circularId,
            'job_id'      => $jobId ?: ($circular['source_id'] ?? ''),
        ]);
    }

    /**
     * POST — Regenerate the circular for an existing job posting.
     * Params: job_id
     */
    public function regenerate_circular()
    {
        $this->_require_role(self::HR_ROLES, 'regenerate_circular');

        $jobId = trim($this->input->post('job_id') ?? '');
        if (!$jobId) $this->json_error('Job ID required.');
        $jobId = $this->safe_path_segment($jobId, 'job_id');

        $job = $this->firebase->get($this->_jobs($jobId));
        if (!is_array($job)) $this->json_error('Job posting not found.');

        $oldCircularId = $job['circular_id'] ?? '';

        // Delete old circular if it exists
        if ($oldCircularId) {
            $this->firebase->delete(
                "Schools/{$this->school_name}/Communication/Circulars",
                $oldCircularId
            );
        }

        // Create fresh circular
        $newCircularId = $this->_create_job_circular($jobId, $job);
        if ($newCircularId) {
            $this->firebase->update($this->_jobs($jobId), ['circular_id' => $newCircularId]);
        }

        $this->json_success([
            'circular_id' => $newCircularId,
            'message'     => $newCircularId
                ? "Circular regenerated as {$newCircularId}."
                : 'Failed to regenerate circular.',
        ]);
    }

    // ====================================================================
    //  RECRUITMENT — APPLICANTS
    // ====================================================================

    /**
     * GET — List applicants. Optional filters: ?job_id=JOB0001&status=Applied
     */
    public function get_applicants()
    {
        $this->_require_role(self::HR_ROLES, 'get_applicants');

        $filterJob    = trim($this->input->get('job_id') ?? '');
        $filterStatus = trim($this->input->get('status') ?? '');

        $applicants = $this->firebase->get($this->_applicants());
        $list = [];
        if (is_array($applicants)) {
            foreach ($applicants as $id => $a) {
                if ($filterJob !== '' && isset($a['job_id']) && $a['job_id'] !== $filterJob) {
                    continue;
                }
                if ($filterStatus !== '' && isset($a['status']) && $a['status'] !== $filterStatus) {
                    continue;
                }
                $a['id'] = $id;
                $list[] = $a;
            }
        }

        // Sort by applied date descending
        usort($list, function ($a, $b) {
            return strcmp($b['applied_date'] ?? '', $a['applied_date'] ?? '');
        });

        $this->json_success(['applicants' => $list]);
    }

    /**
     * POST — Create or update an applicant.
     */
    public function save_applicant()
    {
        $this->_require_role(self::HR_ROLES, 'save_applicant');

        $id            = trim($this->input->post('id') ?? '');
        $jobId         = trim($this->input->post('job_id') ?? '');
        if ($id !== '') {
            $id = $this->safe_path_segment($id, 'id');
        }
        $jobId         = $this->safe_path_segment($jobId, 'job_id');
        $name          = trim($this->input->post('name') ?? '');
        $email         = trim($this->input->post('email') ?? '');
        $phone         = trim($this->input->post('phone') ?? '');
        $qualification = trim($this->input->post('qualification') ?? '');
        $experience    = trim($this->input->post('experience') ?? '');
        $resumeNotes   = trim($this->input->post('resume_notes') ?? '');
        $interviewDate = trim($this->input->post('interview_date') ?? '');
        $interviewNotes= trim($this->input->post('interview_notes') ?? '');
        $rating        = (int) ($this->input->post('rating') ?? 0);
        $status        = trim($this->input->post('status') ?? 'Applied');
        $notes         = trim($this->input->post('notes') ?? '');

        if ($name === '') {
            $this->json_error('Applicant name is required.');
        }
        if ($jobId === '') {
            $this->json_error('Job posting is required.');
        }

        // Normalise status to title-case
        $status = ucfirst(strtolower($status));
        if ($status === 'Interviewed') $status = 'Interview';
        $validStatuses = ['Applied', 'Shortlisted', 'Interview', 'Selected', 'Rejected', 'Joined'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'Applied';
        }

        // Verify job exists
        $job = $this->firebase->get($this->_jobs($jobId));
        if (!is_array($job)) {
            $this->json_error('Job posting not found.');
        }

        $now   = date('c');
        $isNew = ($id === '');

        if ($isNew) {
            $id = $this->_next_id('APP', 'Applicant');
        }

        // Preserve applied_date on edit
        $existingApplicant = null;
        if (!$isNew) {
            $existingApplicant = $this->firebase->get($this->_applicants($id));
        }

        $data = [
            'job_id'          => $jobId,
            'name'            => $name,
            'email'           => $email,
            'phone'           => $phone,
            'qualification'   => $qualification,
            'experience'      => $experience,
            'resume_notes'    => $resumeNotes,
            'interview_date'  => $interviewDate,
            'interview_notes' => $interviewNotes,
            'rating'          => $rating,
            'status'          => $status,
            'notes'           => $notes,
            'updated_at'      => $now,
            'updated_by'      => $this->admin_name,
        ];
        if ($isNew) {
            $data['applied_date'] = date('Y-m-d');
        } else {
            $data['applied_date'] = $existingApplicant['applied_date'] ?? date('Y-m-d');
        }

        $this->firebase->set($this->_applicants($id), $data);
        $this->json_success(['id' => $id, 'message' => $isNew ? 'Applicant added.' : 'Applicant updated.']);
    }

    /**
     * POST — Update only the status of an applicant (quick action).
     * Params: id, status, notes (optional remark)
     */
    public function update_applicant_status()
    {
        $this->_require_role(self::HR_ROLES, 'update_applicant_status');

        $id     = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');
        $status = trim($this->input->post('status') ?? '');
        $notes  = trim($this->input->post('notes') ?? '');

        $validStatuses = ['Applied', 'Shortlisted', 'Interview', 'Selected', 'Rejected', 'Joined'];
        if (!in_array($status, $validStatuses, true)) {
            $this->json_error('Invalid status.');
        }

        $existing = $this->firebase->get($this->_applicants($id));
        if (!is_array($existing)) {
            $this->json_error('Applicant not found.');
        }

        $update = [
            'status'     => $status,
            'updated_at' => date('c'),
            'updated_by' => $this->admin_name,
        ];
        if ($notes !== '') {
            $update['notes'] = $notes;
        }

        $this->firebase->update($this->_applicants($id), $update);
        $this->json_success(['message' => "Applicant status updated to {$status}."]);
    }

    /**
     * POST — Delete an applicant.
     * Params: id
     */
    public function delete_applicant()
    {
        $this->_require_role(self::HR_ROLES, 'delete_applicant');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        $existing = $this->firebase->get($this->_applicants($id));
        if (!is_array($existing)) {
            $this->json_error('Applicant not found.');
        }

        $this->firebase->delete($this->_applicants($id));
        $this->json_success(['message' => 'Applicant deleted.']);
    }

    // ====================================================================
    //  LEAVE MANAGEMENT — TYPES
    // ====================================================================

    /**
     * GET — List all leave types.
     */
    public function get_leave_types()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_leave_types');

        $types = $this->firebase->get($this->_leave_types());
        $list = [];
        if (is_array($types)) {
            foreach ($types as $id => $t) {
                $t['id'] = $id;
                $list[] = $t;
            }
        }

        $this->json_success(['leave_types' => $list]);
    }

    /**
     * POST — Create or update a leave type.
     */
    public function save_leave_type()
    {
        $this->_require_role(self::HR_ROLES, 'save_leave_type');

        $id           = trim($this->input->post('id') ?? '');
        $name         = trim($this->input->post('name') ?? '');
        $code         = strtoupper(trim($this->input->post('code') ?? ''));
        $daysPerYear  = (int) ($this->input->post('days_per_year') ?? 0);
        $carryForward = filter_var($this->input->post('carry_forward') ?? false, FILTER_VALIDATE_BOOLEAN);
        $maxCarry     = (int) ($this->input->post('max_carry') ?? 0);
        $paid         = filter_var($this->input->post('paid') ?? false, FILTER_VALIDATE_BOOLEAN);
        $description  = trim($this->input->post('description') ?? '');
        $status       = trim($this->input->post('status') ?? 'Active');

        if ($name === '') {
            $this->json_error('Leave type name is required.');
        }
        if ($daysPerYear < 0 || $daysPerYear > 365) {
            $this->json_error('Days per year must be between 0 and 365.');
        }
        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        $now   = date('c');
        $isNew = ($id === '');

        if ($isNew) {
            $id = $this->_next_id('LT', 'LeaveType');
        }

        // Check duplicate name (excluding self)
        $existing = $this->firebase->get($this->_leave_types());
        if (is_array($existing)) {
            foreach ($existing as $eid => $et) {
                if ($eid !== $id && isset($et['name']) && strtolower($et['name']) === strtolower($name)) {
                    $this->json_error('A leave type with this name already exists.');
                }
            }
        }

        $data = [
            'name'          => $name,
            'code'          => $code,
            'days_per_year' => $daysPerYear,
            'carry_forward' => $carryForward,
            'max_carry'     => $maxCarry,
            'paid'          => $paid,
            'description'   => $description,
            'status'        => $status,
            'updated_at'    => $now,
        ];
        if ($isNew) {
            $data['created_at'] = $now;
        }

        $this->firebase->set($this->_leave_types($id), $data);
        $this->json_success(['id' => $id, 'message' => $isNew ? 'Leave type created.' : 'Leave type updated.']);
    }

    /**
     * POST — Delete a leave type.
     * Params: id
     */
    public function delete_leave_type()
    {
        $this->_require_role(self::HR_ROLES, 'delete_leave_type');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        $existing = $this->firebase->get($this->_leave_types($id));
        if (!is_array($existing)) {
            $this->json_error('Leave type not found.');
        }

        // Check if any pending/approved leave requests use this type
        $requests = $this->firebase->get($this->_leave_req());
        if (is_array($requests)) {
            foreach ($requests as $lr) {
                if (
                    isset($lr['type_id']) && $lr['type_id'] === $id &&
                    isset($lr['status']) && in_array($lr['status'], ['Pending', 'Approved'], true)
                ) {
                    $this->json_error('Cannot delete: active leave requests exist for this type.');
                }
            }
        }

        $this->firebase->delete($this->_leave_types($id));
        $this->json_success(['message' => 'Leave type deleted.']);
    }

    /**
     * POST — Seed default leave types (idempotent).
     * Skips any that already exist (matched by uppercase code).
     */
    public function seed_leave_types()
    {
        $this->_require_role(self::HR_ROLES, 'seed_leave_types');

        $defaults = [
            ['name' => 'Casual Leave',        'code' => 'CL',       'days_per_year' => 12, 'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Short-duration personal leave for unforeseen needs'],
            ['name' => 'Sick Leave',           'code' => 'SL',       'days_per_year' => 12, 'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Leave for illness or medical treatment'],
            ['name' => 'Earned Leave',         'code' => 'EL',       'days_per_year' => 15, 'paid' => true,  'carry_forward' => true,  'max_carry' => 30, 'description' => 'Privilege leave earned through service, can be carried forward'],
            ['name' => 'Maternity Leave',      'code' => 'MATERNITY','days_per_year' => 180,'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Maternity leave as per government norms'],
            ['name' => 'Paternity Leave',      'code' => 'PATERNITY','days_per_year' => 15, 'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Paternity leave for new fathers'],
            ['name' => 'Leave Without Pay',    'code' => 'LWP',      'days_per_year' => 0,  'paid' => false, 'carry_forward' => false, 'max_carry' => 0,  'description' => 'Unpaid leave — deducted from salary'],
            ['name' => 'Compensatory Off',     'code' => 'COMP_OFF', 'days_per_year' => 0,  'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Compensatory leave for working on holidays or weekends'],
            ['name' => 'Academic Leave',       'code' => 'ACADEMIC', 'days_per_year' => 10, 'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Leave for academic pursuits, conferences, or training'],
            ['name' => 'On Duty Leave',        'code' => 'DUTY',     'days_per_year' => 0,  'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Official duty outside school premises'],
        ];

        // Fetch existing leave types and build a set of uppercase codes
        $existing = $this->firebase->get($this->_leave_types());
        $existingCodes = [];
        if (is_array($existing)) {
            foreach ($existing as $et) {
                if (isset($et['code'])) {
                    $existingCodes[] = strtoupper(trim($et['code']));
                }
            }
        }

        $now     = date('c');
        $added   = [];
        $skipped = [];

        foreach ($defaults as $def) {
            $code = strtoupper($def['code']);
            if (in_array($code, $existingCodes, true)) {
                $skipped[] = $code;
                continue;
            }

            $id   = $this->_next_id('LT', 'LeaveType');
            $data = [
                'name'          => $def['name'],
                'code'          => $code,
                'days_per_year' => $def['days_per_year'],
                'paid'          => $def['paid'],
                'carry_forward' => $def['carry_forward'],
                'max_carry'     => $def['max_carry'],
                'description'   => $def['description'],
                'status'        => 'Active',
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
            $this->firebase->set($this->_leave_types($id), $data);
            $added[] = $code;
        }

        $msg = count($added) . ' leave type(s) added';
        if (count($skipped)) {
            $msg .= ', ' . count($skipped) . ' already existed';
        }

        $this->json_success([
            'message' => $msg,
            'added'   => $added,
            'skipped' => $skipped,
        ]);
    }

    /**
     * GET — Retrieve leave audit logs (most recent first, limit 200).
     */
    public function get_leave_audit_log()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_leave_audit_log');

        $logs = $this->firebase->get($this->_leave_audit());
        $list = [];
        if (is_array($logs)) {
            foreach ($logs as $id => $l) {
                $l['id'] = $id;
                $list[]  = $l;
            }
        }
        // Most recent first
        usort($list, function ($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });
        // Limit to 200
        $list = array_slice($list, 0, 200);

        $this->json_success(['audit_logs' => $list]);
    }

    // ====================================================================
    //  LEAVE MANAGEMENT — BALANCES
    // ====================================================================

    /**
     * GET — Get leave balances for a year (defaults to current calendar year).
     * Optional: ?year=2026&staff_id=STAFF001
     */
    public function get_leave_balances()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_leave_balances');

        $year    = trim($this->input->get('year') ?? date('Y'));
        $staffId = trim($this->input->get('staff_id') ?? '');

        if (!preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid year format.');
        }

        if ($staffId !== '') {
            $staffId = $this->safe_path_segment($staffId, 'staff_id');
            // Single staff balances
            $balances = $this->firebase->get($this->_leave_bal($year, $staffId));
            $this->json_success([
                'balances' => is_array($balances) ? $balances : [],
                'staff_id' => $staffId,
                'year'     => $year,
            ]);
            return;
        }

        // All staff balances for the year
        $allBal = $this->firebase->get($this->_leave_bal($year));
        $result = [];
        if (is_array($allBal)) {
            foreach ($allBal as $sid => $types) {
                $result[$sid] = is_array($types) ? $types : [];
            }
        }

        // Also return leave type names so the UI can build column headers
        $leaveTypes = $this->firebase->get($this->_leave_types());
        $typeNames = [];
        if (is_array($leaveTypes)) {
            foreach ($leaveTypes as $tid => $lt) {
                if (is_array($lt) && isset($lt['name'])) {
                    $typeNames[$tid] = $lt['name'];
                }
            }
        }

        $this->json_success(['balances' => $result, 'types' => $typeNames, 'year' => $year]);
    }

    /**
     * POST — Allocate leave balances for all staff in the session roster.
     * Creates/updates balance records for each active leave type.
     * Params: year (calendar year e.g. '2026')
     */
    public function allocate_leave_balances()
    {
        $this->_require_role(self::HR_ROLES, 'allocate_leave');

        $year = trim($this->input->post('year') ?? date('Y'));
        if (!preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid year format.');
        }

        // Get all active leave types
        $leaveTypes = $this->firebase->get($this->_leave_types());
        $activeTypes = [];
        if (is_array($leaveTypes)) {
            foreach ($leaveTypes as $tid => $lt) {
                if (isset($lt['status']) && $lt['status'] === 'Active') {
                    $activeTypes[$tid] = $lt;
                }
            }
        }

        if (empty($activeTypes)) {
            $this->json_error('No active leave types found. Please create leave types first.');
        }

        // Get staff from session roster
        $roster = $this->firebase->get("Schools/{$this->school_name}/{$this->session_year}/Teachers");
        if (!is_array($roster) || empty($roster)) {
            $this->json_error('No staff found in the current session roster.');
        }

        // Determine previous year for carry forward
        $prevYear = (string) ((int) $year - 1);
        $prevBalances = $this->firebase->get($this->_leave_bal($prevYear));

        // Reconcile: scan all approved leave requests for this year to compute actual used days
        $allRequests = $this->firebase->get($this->_leave_req());
        $usedMap = []; // usedMap[staffId][typeId] = total days used
        if (is_array($allRequests)) {
            foreach ($allRequests as $rid => $req) {
                if (!is_array($req)) continue;
                if (($req['status'] ?? '') !== 'Approved') continue;
                $reqYear = date('Y', strtotime($req['from_date'] ?? ''));
                if ($reqYear !== $year) continue;
                $sid = $req['staff_id'] ?? '';
                $tid = $req['type_id'] ?? '';
                if ($sid === '' || $tid === '') continue;
                $d = (int) ($req['days'] ?? 0);
                if (!isset($usedMap[$sid])) $usedMap[$sid] = [];
                if (!isset($usedMap[$sid][$tid])) $usedMap[$sid][$tid] = 0;
                $usedMap[$sid][$tid] += $d;
            }
        }

        $staffCount = 0;

        foreach ($roster as $staffId => $rosterData) {
            foreach ($activeTypes as $typeId => $lt) {
                $allocated = (int) ($lt['days_per_year'] ?? 0);
                $carried = 0;

                // Carry forward from previous year if enabled
                if (!empty($lt['carry_forward'])) {
                    $prevBal = 0;
                    if (is_array($prevBalances) && isset($prevBalances[$staffId][$typeId]['balance'])) {
                        $prevBal = (int) $prevBalances[$staffId][$typeId]['balance'];
                    }
                    $maxCarry = (int) ($lt['max_carry'] ?? 0);
                    $carried = ($maxCarry > 0) ? min($prevBal, $maxCarry) : $prevBal;
                    if ($carried < 0) $carried = 0;
                }

                // Reconcile used count from approved requests (authoritative source)
                $used = $usedMap[$staffId][$typeId] ?? 0;

                $balance = $allocated + $carried - $used;

                $balData = [
                    'allocated' => $allocated,
                    'used'      => $used,
                    'carried'   => $carried,
                    'balance'   => $balance,
                ];

                $this->firebase->set($this->_leave_bal($year, $staffId, $typeId), $balData);
            }
            $staffCount++;
        }

        $this->json_success([
            'message'     => "Leave balances allocated for {$staffCount} staff members across " . count($activeTypes) . " leave types.",
            'staff_count' => $staffCount,
            'type_count'  => count($activeTypes),
            'year'        => $year,
        ]);
    }

    // ====================================================================
    //  LEAVE MANAGEMENT — REQUESTS
    // ====================================================================

    /**
     * GET — List leave requests. Optional filters: ?status=Pending&staff_id=XXX
     */
    public function get_leave_requests()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_leave_requests');

        $filterStatus  = trim($this->input->get('status') ?? '');
        $filterStaffId = trim($this->input->get('staff_id') ?? '');

        $requests = $this->firebase->get($this->_leave_req());
        $list = [];
        if (is_array($requests)) {
            foreach ($requests as $id => $r) {
                if ($filterStatus !== '' && isset($r['status']) && $r['status'] !== $filterStatus) {
                    continue;
                }
                if ($filterStaffId !== '' && isset($r['staff_id']) && $r['staff_id'] !== $filterStaffId) {
                    continue;
                }
                $r['id'] = $id;
                $list[] = $r;
            }
        }

        // Sort by applied_on descending
        usort($list, function ($a, $b) {
            return strcmp($b['applied_on'] ?? '', $a['applied_on'] ?? '');
        });

        $this->json_success(['leave_requests' => $list]);
    }

    /**
     * POST — Apply for leave (by or on behalf of a staff member).
     * Params: staff_id, type_id, from_date, to_date, reason
     */
    public function apply_leave()
    {
        $this->_require_role(self::VIEW_ROLES, 'apply_leave');

        $staffId  = $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id');
        $typeId   = $this->safe_path_segment(trim($this->input->post('type_id') ?? ''), 'type_id');
        $fromDate = trim($this->input->post('from_date') ?? '');
        $toDate   = trim($this->input->post('to_date') ?? '');
        $reason   = trim($this->input->post('reason') ?? '');

        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            $this->json_error('From date must be in YYYY-MM-DD format.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $this->json_error('To date must be in YYYY-MM-DD format.');
        }
        if ($toDate < $fromDate) {
            $this->json_error('To date cannot be before from date.');
        }

        // Calculate days
        $from = new DateTime($fromDate);
        $to   = new DateTime($toDate);
        $days = (int) $from->diff($to)->days + 1;

        // Verify leave type exists and is active
        $leaveType = $this->firebase->get($this->_leave_types($typeId));
        if (!is_array($leaveType)) {
            $this->json_error('Leave type not found.');
        }
        if (isset($leaveType['status']) && $leaveType['status'] !== 'Active') {
            $this->json_error('This leave type is inactive.');
        }

        // Verify staff exists in roster
        $staffProfile = $this->firebase->get("Users/Teachers/{$this->school_name}/{$staffId}");
        if (!is_array($staffProfile)) {
            $this->json_error('Staff member not found.');
        }
        $staffName = $staffProfile['Name'] ?? $staffId;

        // Determine if leave type is paid — paid leaves never become LWP
        $isPaidType = filter_var($leaveType['paid'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $leaveCode  = strtoupper(trim($leaveType['code'] ?? ''));
        log_message('debug', "apply_leave: type_id={$typeId}, code={$leaveCode}, paid=" . ($isPaidType ? 'YES' : 'NO') . ", days={$days}, staff={$staffId}");

        // Check leave balance — only apply LWP logic for unpaid leave types
        $year = date('Y', strtotime($fromDate));
        $balance = $this->firebase->get($this->_leave_bal($year, $staffId, $typeId));
        $currentBalance = 0;
        if (is_array($balance) && isset($balance['balance'])) {
            $currentBalance = max(0, (int) $balance['balance']);
        }

        if (!$isPaidType) {
            // Unpaid leave type (e.g. LWP) — ALL days are LWP by definition
            $paidDays = 0;
            $lwpDays  = $days;
        } elseif ($currentBalance >= $days) {
            // Paid type with sufficient balance — fully paid
            $paidDays = $days;
            $lwpDays  = 0;
        } else {
            // Paid type but balance exhausted — excess becomes LWP
            $paidDays = $currentBalance;
            $lwpDays  = $days - $paidDays;
        }
        $lwpWarning = '';

        // Check for overlapping leave requests (Pending or Approved)
        $existingReqs = $this->firebase->get($this->_leave_req());
        if (is_array($existingReqs)) {
            foreach ($existingReqs as $rid => $er) {
                if (
                    isset($er['staff_id']) && $er['staff_id'] === $staffId &&
                    isset($er['status']) && in_array($er['status'], ['Pending', 'Approved'], true)
                ) {
                    $erFrom = $er['from_date'] ?? '';
                    $erTo   = $er['to_date'] ?? '';
                    // Check overlap: two date ranges overlap when start1 <= end2 AND start2 <= end1
                    if ($fromDate <= $erTo && $toDate >= $erFrom) {
                        $this->json_error("Overlapping leave request exists ({$erFrom} to {$erTo}).");
                    }
                }
            }
        }

        $reqId = $this->_next_id('LR', 'LeaveRequest');

        if ($lwpDays > 0) {
            $lwpWarning = "Warning: {$lwpDays} day(s) will be treated as Leave Without Pay (balance: {$currentBalance}).";
        }

        // Build human-readable calculation reason
        if (!$isPaidType) {
            $calcReason = "Unpaid leave type ({$leaveCode}) — all {$days} day(s) are LWP by definition";
            $payLabel   = 'Unpaid Leave (LWP)';
        } elseif ($lwpDays === 0) {
            $calcReason = "Paid leave ({$leaveCode}), balance sufficient ({$currentBalance} available)";
            $payLabel   = 'Fully Paid';
        } else {
            $calcReason = "Paid leave ({$leaveCode}) but balance exhausted — {$paidDays} paid (balance: {$currentBalance}), {$lwpDays} day(s) as LWP";
            $payLabel   = 'Partially Paid (Exceeds Balance)';
        }

        $data = [
            'staff_id'            => $staffId,
            'staff_name'          => $staffName,
            'type_id'             => $typeId,
            'type_name'           => $leaveType['name'] ?? '',
            'type_code'           => $leaveCode,
            'type_paid'           => $isPaidType,
            'from_date'           => $fromDate,
            'to_date'             => $toDate,
            'days'                => $days,
            'paid_days'           => $paidDays,
            'lwp_days'            => $lwpDays,
            'pay_label'           => $payLabel,
            'calculation_reason'  => $calcReason,
            'balance_at_apply'    => $currentBalance,
            'reason'              => $reason,
            'status'              => 'Pending',
            'applied_on'          => date('c'),
            'decided_by'          => '',
            'decided_on'          => '',
            'remarks'             => '',
        ];

        $this->firebase->set($this->_leave_req($reqId), $data);

        // Audit log
        $this->_log_leave_audit([
            'action'           => 'leave_applied',
            'leave_request_id' => $reqId,
            'staff_id'         => $staffId,
            'staff_name'       => $staffName,
            'leave_type'       => $leaveCode,
            'leave_type_id'    => $typeId,
            'paid_flag'        => $isPaidType,
            'total_days'       => $days,
            'paid_days'        => $paidDays,
            'lwp_days'         => $lwpDays,
            'balance_before'   => $currentBalance,
            'decision_reason'  => $calcReason,
        ]);

        $msg = "Leave request submitted for {$days} day(s).";
        if ($lwpWarning !== '') {
            $msg .= ' ' . $lwpWarning;
        }
        $this->json_success(['id' => $reqId, 'message' => $msg, 'lwp_days' => $lwpDays, 'lwp_warning' => $lwpWarning]);
    }

    /**
     * POST — Approve or reject a leave request.
     * Params: id, decision (Approved|Rejected), remarks (optional)
     */
    public function decide_leave()
    {
        $this->_require_role(self::HR_ROLES, 'decide_leave');

        $id       = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');
        $decision = trim($this->input->post('decision') ?? '');
        $remarks  = trim($this->input->post('remarks') ?? '');

        if (!in_array($decision, ['Approved', 'Rejected'], true)) {
            $this->json_error('Decision must be Approved or Rejected.');
        }

        $request = $this->firebase->get($this->_leave_req($id));
        if (!is_array($request)) {
            $this->json_error('Leave request not found.');
        }
        if (($request['status'] ?? '') !== 'Pending') {
            $this->json_error('Only pending requests can be decided.');
        }

        // M-04 FIX: Atomically mark request as "Processing" to prevent concurrent approvals
        // on the same leave request (optimistic lock via status transition).
        $this->firebase->update($this->_leave_req($id), ['status' => 'Processing']);

        // Re-read to verify we won the race (another thread may have set it too)
        $recheck = $this->firebase->get($this->_leave_req($id) . '/status');
        if ($recheck !== 'Processing') {
            $this->json_error('This request is being processed by another user. Please refresh.');
        }

        $staffId  = $request['staff_id'] ?? '';
        $typeId   = $request['type_id'] ?? '';
        $days     = (int) ($request['days'] ?? 0);
        $fromDate = $request['from_date'] ?? '';
        $year     = date('Y', strtotime($fromDate));

        // Fetch leave type config to determine paid/unpaid status
        $leaveType = $this->firebase->get($this->_leave_types($typeId));
        $isPaidType = true; // safe default: if leave type config is missing, do NOT deduct
        if (is_array($leaveType)) {
            $isPaidType = filter_var($leaveType['paid'] ?? false, FILTER_VALIDATE_BOOLEAN);
            log_message('debug', "decide_leave: type_id={$typeId}, code=" . ($leaveType['code'] ?? '?') . ", paid=" . ($isPaidType ? 'YES' : 'NO') . ", days={$days}, decision={$decision}");
        } else {
            // Leave type not found — log warning but do NOT assume unpaid
            log_message('error', "decide_leave: leave type '{$typeId}' not found in config — defaulting to paid (no deduction)");
        }

        // If approving, calculate paid/LWP split and deduct from balance
        $paidDays = $days;
        $lwpDays  = 0;
        if ($decision === 'Approved') {
            if (!$isPaidType) {
                // Unpaid leave type — ALL days are LWP, but still track usage in balance
                $paidDays = 0;
                $lwpDays  = $days;

                // Update balance record to reflect used days even for unpaid types
                $balance = $this->firebase->get($this->_leave_bal($year, $staffId, $typeId));
                if (is_array($balance)) {
                    $currentUsed    = (int) ($balance['used'] ?? 0);
                    $currentBalance = (int) ($balance['balance'] ?? 0);
                    $this->firebase->update($this->_leave_bal($year, $staffId, $typeId), [
                        'used'    => $currentUsed + $days,
                        'balance' => $currentBalance - $days,
                    ]);
                } else {
                    // No balance record exists — create one
                    $allocated = 0;
                    $lt = $this->firebase->get($this->_leave_types($typeId));
                    if (is_array($lt)) $allocated = (int) ($lt['days_per_year'] ?? 0);
                    $this->firebase->set($this->_leave_bal($year, $staffId, $typeId), [
                        'allocated' => $allocated,
                        'used'      => $days,
                        'carried'   => 0,
                        'balance'   => $allocated - $days,
                    ]);
                }
            } else {
                // Paid leave type — deduct from balance; excess becomes LWP
                $balance = $this->firebase->get($this->_leave_bal($year, $staffId, $typeId));
                if (is_array($balance)) {
                    $currentBalance = (int) ($balance['balance'] ?? 0);
                    $currentUsed    = (int) ($balance['used'] ?? 0);

                    // Split into paid and LWP portions
                    $paidDays = min(max(0, $currentBalance), $days);
                    $lwpDays  = $days - $paidDays;

                    // Only deduct the paid portion from balance
                    if ($paidDays > 0) {
                        $this->firebase->update($this->_leave_bal($year, $staffId, $typeId), [
                            'used'    => $currentUsed + $paidDays,
                            'balance' => $currentBalance - $paidDays,
                        ]);

                        // M-04 FIX: Post-write verification — re-read balance to detect concurrent deduction
                        $verifyBal = $this->firebase->get($this->_leave_bal($year, $staffId, $typeId));
                        if (is_array($verifyBal) && (int) ($verifyBal['balance'] ?? 0) < 0) {
                            $this->firebase->update($this->_leave_bal($year, $staffId, $typeId), [
                                'used'    => $currentUsed,
                                'balance' => $currentBalance,
                            ]);
                            $this->firebase->update($this->_leave_req($id), ['status' => 'Pending']);
                            $this->json_error('Leave balance was modified concurrently. Please try again.');
                        }
                    }
                } else {
                    // No balance record for a paid type — create one with 0 allocation
                    // All days still count as paid (no salary deduction), just no balance to deduct from
                    $paidDays = $days;
                    $lwpDays  = 0;
                    $this->firebase->set($this->_leave_bal($year, $staffId, $typeId), [
                        'allocated' => 0,
                        'used'      => $days,
                        'carried'   => 0,
                        'balance'   => 0 - $days,
                    ]);
                    log_message('info', "decide_leave: no balance record for paid type '{$typeId}' / staff '{$staffId}' — treating {$days} day(s) as paid with negative balance");
                }
            }
        }

        // Build calculation reason for audit trail
        $ltCode = is_array($leaveType) ? strtoupper(trim($leaveType['code'] ?? '')) : '?';
        if ($decision === 'Rejected') {
            $calcReason = 'Leave rejected — no salary impact';
            $payLabel   = 'Rejected';
        } elseif (!$isPaidType) {
            $calcReason = "Unpaid leave type ({$ltCode}) — all {$days} day(s) deducted from salary";
            $payLabel   = 'Unpaid Leave (LWP)';
        } elseif ($lwpDays === 0) {
            $calcReason = "Paid leave ({$ltCode}), fully covered by balance — no salary deduction";
            $payLabel   = 'Fully Paid';
        } else {
            $calcReason = "Paid leave ({$ltCode}) — {$paidDays} covered by balance, {$lwpDays} exceed balance (LWP, salary deducted)";
            $payLabel   = 'Partially Paid (Exceeds Balance)';
        }

        $updateData = [
            'status'              => $decision,
            'decided_by'          => $this->admin_name,
            'decided_on'          => date('c'),
            'remarks'             => $remarks,
            'paid_days'           => $paidDays,
            'lwp_days'            => $lwpDays,
            'type_code'           => $ltCode,
            'type_paid'           => $isPaidType,
            'pay_label'           => $payLabel,
            'calculation_reason'  => $calcReason,
        ];

        $this->firebase->update($this->_leave_req($id), $updateData);

        // On approval, automatically mark attendance as "L" (Leave) for each leave day
        if ($decision === 'Approved') {
            $this->_apply_leave_to_attendance(
                $staffId,
                $request['from_date'] ?? '',
                $request['to_date'] ?? '',
                $isPaidType ? 'Paid' : 'Unpaid',
                $paidDays,
                $lwpDays
            );
        }

        // Audit log
        $this->_log_leave_audit([
            'action'           => 'leave_decided',
            'leave_request_id' => $id,
            'staff_id'         => $staffId,
            'staff_name'       => $request['staff_name'] ?? $staffId,
            'leave_type'       => $ltCode,
            'leave_type_id'    => $typeId,
            'paid_flag'        => $isPaidType,
            'total_days'       => $days,
            'paid_days'        => $paidDays,
            'lwp_days'         => $lwpDays,
            'decision'         => $decision,
            'decision_reason'  => $calcReason,
            'remarks'          => $remarks,
        ]);

        $msg = "Leave request {$decision}.";
        if ($decision === 'Approved' && $lwpDays > 0) {
            $msg .= " ({$paidDays} paid, {$lwpDays} LWP)";
        }
        $this->json_success(['message' => $msg, 'paid_days' => $paidDays, 'lwp_days' => $lwpDays]);
    }

    /**
     * POST — Cancel a leave request.
     * Params: id
     * If the request was Approved, restores the balance.
     */
    public function cancel_leave()
    {
        $this->_require_role(self::HR_ROLES, 'cancel_leave');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        $request = $this->firebase->get($this->_leave_req($id));
        if (!is_array($request)) {
            $this->json_error('Leave request not found.');
        }

        $currentStatus = $request['status'] ?? '';
        if (!in_array($currentStatus, ['Pending', 'Approved'], true)) {
            $this->json_error('Only Pending or Approved requests can be cancelled.');
        }

        // M-04 FIX: Lock the request by transitioning to Cancelling status
        $this->firebase->update($this->_leave_req($id), ['status' => 'Cancelling']);

        $staffId  = $request['staff_id'] ?? '';
        $typeId   = $request['type_id'] ?? '';
        $days     = (int) ($request['days'] ?? 0);
        $fromDate = $request['from_date'] ?? '';
        $year     = date('Y', strtotime($fromDate));

        // If was Approved, restore balance (paid days + unpaid days both tracked in used count)
        $paidDays = (int) ($request['paid_days'] ?? $days); // fallback to full days for legacy requests
        $lwpDays  = (int) ($request['lwp_days'] ?? 0);
        $totalUsedDays = $paidDays + $lwpDays; // total days deducted from used count
        if ($totalUsedDays === 0) $totalUsedDays = $days; // legacy fallback
        if ($currentStatus === 'Approved' && $staffId !== '' && $typeId !== '' && $totalUsedDays > 0) {
            $balance = $this->firebase->get($this->_leave_bal($year, $staffId, $typeId));
            if (is_array($balance)) {
                $currentUsed    = (int) ($balance['used'] ?? 0);
                $currentBalance = (int) ($balance['balance'] ?? 0);

                $newUsed    = max(0, $currentUsed - $totalUsedDays);
                $newBalance = $currentBalance + $totalUsedDays;

                $this->firebase->update($this->_leave_bal($year, $staffId, $typeId), [
                    'used'    => $newUsed,
                    'balance' => $newBalance,
                ]);
            }
        }

        // Revert attendance marks if the leave was previously approved
        if ($currentStatus === 'Approved') {
            $this->_revert_leave_from_attendance(
                $staffId,
                $request['from_date'] ?? '',
                $request['to_date'] ?? ''
            );
        }

        $this->firebase->update($this->_leave_req($id), [
            'status'     => 'Cancelled',
            'decided_by' => $this->admin_name,
            'decided_on' => date('c'),
            'remarks'    => 'Cancelled' . ($currentStatus === 'Approved' ? ' (balance restored, attendance reverted)' : ''),
        ]);

        $this->json_success([
            'message' => 'Leave request cancelled.' . ($currentStatus === 'Approved' ? ' Balance restored, attendance reverted.' : ''),
        ]);
    }

    // ====================================================================
    //  LEAVE ↔ ATTENDANCE INTEGRATION
    // ====================================================================

    /**
     * Mark attendance as "L" (Leave) for each day of an approved leave request.
     * Called automatically when leave is approved.
     *
     * Staff attendance is stored at:
     *   Schools/{school}/{session}/Staff_Attendance/{Month Year}/{staffId}
     * as a string like "PPAPPP..." where each char = day status (P/A/L/H/T/V).
     *
     * @param string $staffId   Staff ID
     * @param string $fromDate  Leave start date (YYYY-MM-DD)
     * @param string $toDate    Leave end date (YYYY-MM-DD)
     * @param string $leaveType "Paid" or "Unpaid"
     * @param int    $paidDays  Number of paid leave days
     * @param int    $lwpDays   Number of unpaid (LWP) leave days
     */
    private function _apply_leave_to_attendance(
        string $staffId,
        string $fromDate,
        string $toDate,
        string $leaveType,
        int $paidDays,
        int $lwpDays
    ): void {
        if (!$staffId || !$fromDate || !$toDate) return;

        $school  = $this->school_name;
        $session = $this->session_year;
        $months  = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];

        // Iterate each day in the leave range
        $cursor = new DateTime($fromDate);
        $end    = new DateTime($toDate);

        while ($cursor <= $end) {
            $dayNum   = (int) $cursor->format('j');  // 1-based day of month
            $monthNum = (int) $cursor->format('n');
            $yearNum  = (int) $cursor->format('Y');
            $monthName = $months[$monthNum] ?? '';
            $attKey    = "{$monthName} {$yearNum}";
            $daysInMonth = (int) $cursor->format('t');

            $attPath = "Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$staffId}";
            $attStr  = $this->firebase->get($attPath);

            // Initialize empty attendance string if none exists
            if (!is_string($attStr) || strlen($attStr) === 0) {
                $attStr = str_repeat('V', $daysInMonth); // V = vacant/unmarked
            }

            // Pad if string is shorter than expected
            while (strlen($attStr) < $daysInMonth) {
                $attStr .= 'V';
            }

            // Only overwrite if the day is not already marked as leave
            $idx = $dayNum - 1; // 0-based index
            $currentMark = strtoupper($attStr[$idx] ?? 'V');
            if ($currentMark !== 'L') {
                // Mark as "L" (Leave) — prevents double-counting as Absent
                $attStr[$idx] = 'L';
                $this->firebase->set($attPath, $attStr);
            }

            $cursor->modify('+1 day');
        }

        // Store leave metadata for attendance cross-reference
        $metaPath = "Schools/{$school}/{$session}/Staff_Attendance/Leave_records/{$staffId}";
        $existing = $this->firebase->get($metaPath);
        if (!is_array($existing)) $existing = [];

        $recordKey = str_replace('-', '', $fromDate) . '_' . str_replace('-', '', $toDate);
        $existing[$recordKey] = [
            'from_date'  => $fromDate,
            'to_date'    => $toDate,
            'leave_type' => $leaveType,
            'paid_days'  => $paidDays,
            'lwp_days'   => $lwpDays,
            'marked_at'  => date('c'),
        ];
        $this->firebase->set($metaPath, $existing);

        // Rebuild summary cache for all affected months
        $this->load->helper('attendance');
        $cursor2 = new DateTime($fromDate);
        $end2    = new DateTime($toDate);
        $touchedMonths = [];
        while ($cursor2 <= $end2) {
            $mk = $cursor2->format('F Y');
            $touchedMonths[$mk] = [(int)$cursor2->format('n'), (int)$cursor2->format('Y')];
            $cursor2->modify('+1 day');
        }
        foreach ($touchedMonths as $attK => list($mn, $yr)) {
            update_staff_att_summary($this->firebase, $school, $session, $staffId, $attK, $mn, $yr);
        }
    }

    /**
     * Revert attendance marks from "L" back to "V" when a leave is cancelled.
     * Only reverts days that are currently marked as "L".
     */
    private function _revert_leave_from_attendance(string $staffId, string $fromDate, string $toDate): void
    {
        if (!$staffId || !$fromDate || !$toDate) return;

        $school  = $this->school_name;
        $session = $this->session_year;
        $months  = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];

        $cursor = new DateTime($fromDate);
        $end    = new DateTime($toDate);

        while ($cursor <= $end) {
            $dayNum   = (int) $cursor->format('j');
            $monthNum = (int) $cursor->format('n');
            $yearNum  = (int) $cursor->format('Y');
            $monthName = $months[$monthNum] ?? '';
            $attKey    = "{$monthName} {$yearNum}";
            $daysInMonth = (int) $cursor->format('t');

            $attPath = "Schools/{$school}/{$session}/Staff_Attendance/{$attKey}/{$staffId}";
            $attStr  = $this->firebase->get($attPath);

            if (is_string($attStr) && strlen($attStr) >= $dayNum) {
                $idx = $dayNum - 1;
                if (strtoupper($attStr[$idx]) === 'L') {
                    $attStr[$idx] = 'V'; // Reset to vacant
                    $this->firebase->set($attPath, $attStr);
                }
            }

            $cursor->modify('+1 day');
        }

        // Remove leave metadata record
        $metaPath = "Schools/{$school}/{$session}/Staff_Attendance/Leave_records/{$staffId}";
        $recordKey = str_replace('-', '', $fromDate) . '_' . str_replace('-', '', $toDate);
        $this->firebase->delete($metaPath, $recordKey);

        // Rebuild summary cache for all affected months
        $this->load->helper('attendance');
        $cursor2 = new DateTime($fromDate);
        $end2    = new DateTime($toDate);
        $touchedMonths = [];
        while ($cursor2 <= $end2) {
            $mk = $cursor2->format('F Y');
            $touchedMonths[$mk] = [(int)$cursor2->format('n'), (int)$cursor2->format('Y')];
            $cursor2->modify('+1 day');
        }
        foreach ($touchedMonths as $attK => list($mn, $yr)) {
            update_staff_att_summary($this->firebase, $school, $session, $staffId, $attK, $mn, $yr);
        }
    }

    // ====================================================================
    //  SALARY STRUCTURES
    // ====================================================================

    /**
     * GET — Get all salary structures or a single one (?staff_id=XXX).
     */
    public function get_salary_structures()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_salary_structures');

        $staffId = trim($this->input->get('staff_id') ?? '');

        if ($staffId !== '') {
            $staffId = $this->safe_path_segment($staffId, 'staff_id');
            $structure = $this->firebase->get($this->_salary($staffId));
            $this->json_success([
                'salary_structure' => is_array($structure) ? $structure : null,
                'staff_id'         => $staffId,
            ]);
            return;
        }

        $all = $this->firebase->get($this->_salary());
        $list = [];
        if (is_array($all)) {
            foreach ($all as $sid => $s) {
                $s['staff_id'] = $sid;
                $list[] = $s;
            }
        }

        // Count staff in roster to report coverage
        $roster = $this->firebase->get("Schools/{$this->school_name}/{$this->session_year}/Teachers");
        $rosterCount = is_array($roster) ? count($roster) : 0;
        $coveredIds  = is_array($all) ? array_keys($all) : [];
        $rosterIds   = is_array($roster) ? array_keys($roster) : [];
        $missing     = array_diff($rosterIds, $coveredIds);

        $this->json_success([
            'salary_structures' => $list,
            'coverage' => [
                'total_staff'   => $rosterCount,
                'with_structure' => count($coveredIds),
                'missing'       => count($missing),
                'missing_ids'   => array_values($missing),
            ],
        ]);
    }

    /**
     * POST — Save salary structure for a staff member.
     */
    public function save_salary_structure()
    {
        $this->_require_role(self::ADMIN_ROLES, 'save_salary_structure');

        $staffId = $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id');

        // Verify staff exists
        $staffProfile = $this->firebase->get("Users/Teachers/{$this->school_name}/{$staffId}");
        if (!is_array($staffProfile)) {
            $this->json_error('Staff member not found.');
        }

        $basic            = (float) ($this->input->post('basic') ?? 0);
        $hra              = (float) ($this->input->post('hra') ?? 0);
        $da               = (float) ($this->input->post('da') ?? 0);
        $ta               = (float) ($this->input->post('ta') ?? 0);
        $medical          = (float) ($this->input->post('medical') ?? 0);
        $otherAllowances  = (float) ($this->input->post('other_allowances') ?? $this->input->post('special_allowance') ?? 0);
        $pfEmployee       = (float) ($this->input->post('pf_employee') ?? 0);
        $pfEmployer       = (float) ($this->input->post('pf_employer') ?? 0);
        $esiEmployee      = (float) ($this->input->post('esi_employee') ?? 0);
        $esiEmployer      = (float) ($this->input->post('esi_employer') ?? 0);
        $professionalTax  = (float) ($this->input->post('professional_tax') ?? 0);
        $tds              = (float) ($this->input->post('tds') ?? 0);
        $otherDeductions  = (float) ($this->input->post('other_deductions') ?? 0);

        if ($basic <= 0) {
            $this->json_error('Basic salary must be greater than zero.');
        }

        $data = [
            'basic'            => $basic,
            'hra'              => $hra,
            'da'               => $da,
            'ta'               => $ta,
            'medical'          => $medical,
            'other_allowances' => $otherAllowances,
            'pf_employee'      => $pfEmployee,
            'pf_employer'      => $pfEmployer,
            'esi_employee'     => $esiEmployee,
            'esi_employer'     => $esiEmployer,
            'professional_tax' => $professionalTax,
            'tds'              => $tds,
            'other_deductions' => $otherDeductions,
            'source'           => 'manual',
            'updated_at'       => date('c'),
            'updated_by'       => $this->admin_name,
        ];

        // Preserve created_at, bump version, store audit trail
        $existingStruct = $this->firebase->get($this->_salary($staffId));
        $oldVersion = 0;
        if (is_array($existingStruct)) {
            $data['created_at'] = $existingStruct['created_at'] ?? date('c');
            $oldVersion = (int) ($existingStruct['_version'] ?? 0);
            $data['_prev'] = [
                'basic'      => $existingStruct['basic'] ?? 0,
                'source'     => $existingStruct['source'] ?? '',
                'updated_at' => $existingStruct['updated_at'] ?? '',
                'updated_by' => $existingStruct['updated_by'] ?? '',
            ];
        } else {
            $data['created_at'] = date('c');
        }
        $data['_version'] = $oldVersion + 1;

        $this->firebase->set($this->_salary($staffId), $data);

        $gross      = $basic + $hra + $da + $ta + $medical + $otherAllowances;
        // PF/ESI/TDS stored as percentages — compute actual amounts for summary
        $pfAmt      = round($basic * ($pfEmployee / 100), 2);
        $esiAmt     = round($gross * ($esiEmployee / 100), 2);
        $tdsAmt     = round($gross * ($tds / 100), 2);
        $deductions = $pfAmt + $esiAmt + $professionalTax + $tdsAmt + $otherDeductions;
        $net        = $gross - $deductions;

        $this->json_success([
            'message' => 'Salary structure saved.',
            'summary' => [
                'gross'      => round($gross, 2),
                'deductions' => round($deductions, 2),
                'net'        => round($net, 2),
            ],
        ]);
    }

    /**
     * POST — Delete a salary structure.
     * Params: id (staff_id key)
     */
    public function delete_salary_structure()
    {
        $this->_require_role(self::ADMIN_ROLES, 'delete_salary_structure');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'salary_id');

        $existing = $this->firebase->get($this->_salary($id));
        if (!is_array($existing)) {
            $this->json_error('Salary structure not found.');
        }

        // Block if any Finalized or Paid payroll includes this staff
        $runs = $this->firebase->get($this->_payroll_runs());
        if (is_array($runs)) {
            foreach ($runs as $runId => $run) {
                if (!is_array($run)) continue;
                $status = $run['status'] ?? '';
                if (!in_array($status, ['Finalized', 'Paid'], true)) continue;
                $slip = $this->firebase->get($this->_payroll_slips($runId, $id));
                if (is_array($slip) && !empty($slip)) {
                    $this->json_error(
                        "Cannot delete: staff has a {$status} payroll ({$run['month']} {$run['year']}). "
                        . "Reverse or delete the payroll run first."
                    );
                }
            }
        }

        // Archive before deletion for audit trail
        $archiveData = array_merge($existing, [
            'deleted_at' => date('c'),
            'deleted_by' => $this->admin_name ?? $this->admin_id ?? '',
            'is_deleted' => true,
        ]);
        $this->firebase->set(
            "Schools/{$this->school_name}/HR/Salary_Archives/{$id}/" . date('YmdHis'),
            $archiveData
        );

        // Audit log
        $this->_log_leave_audit([
            'action'          => 'salary_structure_deleted',
            'staff_id'        => $id,
            'staff_name'      => $existing['updated_by'] ?? $id,
            'leave_type'      => 'N/A',
            'total_days'      => 0,
            'decision'        => 'Deleted',
            'decision_reason' => 'Salary structure deleted. Basic: ' . ($existing['basic'] ?? 0)
                . ', Version: ' . ($existing['_version'] ?? 1),
        ]);

        // Remove from active structures
        $this->firebase->delete($this->_salary(), $id);

        $this->json_success(['message' => 'Salary structure deleted and archived.']);
    }

    // ====================================================================
    //  PAYROLL
    // ====================================================================

    /**
     * GET — List payroll runs for this session.
     */
    public function get_payroll_runs()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_payroll_runs');

        $runs = $this->firebase->get($this->_payroll_runs());
        $list = [];
        if (is_array($runs)) {
            foreach ($runs as $id => $r) {
                $r['id'] = $id;
                $list[] = $r;
            }
        }

        // Sort by created_at descending
        usort($list, function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        $this->json_success(['payroll_runs' => $list]);
    }

    /**
     * GET — Pre-flight check before payroll generation.
     * Params: month (e.g. 'January'), year (e.g. '2026')
     *
     * Returns warnings and readiness status without creating any data.
     */
    public function preflight_payroll()
    {
        $this->_require_role(self::ADMIN_ROLES, 'preflight_payroll');

        $month = trim($this->input->get('month') ?? '');
        $year  = trim($this->input->get('year') ?? '');

        $validMonths = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];
        if (!in_array($month, $validMonths, true) || !preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid month or year.');
        }

        $warnings = [];

        // Check payroll month lock
        $this->_check_payroll_lock($month, $year);

        // Check for existing run
        $existingRuns = $this->firebase->get($this->_payroll_runs());
        if (is_array($existingRuns)) {
            foreach ($existingRuns as $rid => $er) {
                if (($er['month'] ?? '') === $month && ($er['year'] ?? '') === $year) {
                    $this->json_error("A payroll run already exists for {$month} {$year} (ID: {$rid}). Delete or use it.");
                }
            }
        }

        // Check roster
        $roster = $this->firebase->get("Schools/{$this->school_name}/{$this->session_year}/Teachers");
        if (!is_array($roster) || empty($roster)) {
            $this->json_error('No staff found in the current session roster.');
        }

        // Check salary structures coverage
        $salaryStructures = $this->firebase->get($this->_salary());
        $staffWithSalary = is_array($salaryStructures) ? array_keys($salaryStructures) : [];
        $rosterIds = array_keys($roster);
        $missingStructures = array_diff($rosterIds, $staffWithSalary);
        if (!empty($staffWithSalary)) {
            $coveredCount = count(array_intersect($rosterIds, $staffWithSalary));
        } else {
            $coveredCount = 0;
        }

        if (empty($staffWithSalary)) {
            $this->json_error('No salary structures found. Please set up salary structures first.');
        }
        if (!empty($missingStructures)) {
            $warnings[] = count($missingStructures) . ' staff member(s) in the roster have no salary structure and will be skipped.';
        }

        // Check accounting accounts — structured error for preflight UI
        $this->_validate_accounts(['5010', '5020', '2020'], true);

        // Check attendance data
        $monthYear  = "{$month} {$year}";
        $attendance = $this->firebase->get(
            "Schools/{$this->school_name}/{$this->session_year}/Staff_Attendance/{$monthYear}"
        );
        if (!is_array($attendance) || empty($attendance)) {
            $warnings[] = "No attendance data found for {$month} {$year}. All staff will be treated as fully present.";
        } else {
            $attendanceCount = count($attendance);
            if ($attendanceCount < $coveredCount) {
                $warnings[] = "Attendance data exists for {$attendanceCount} of {$coveredCount} staff. Missing staff will be treated as fully present.";
            }
        }

        // Check pending leave requests for this month
        $allLeaveReqs = $this->firebase->get($this->_leave_req());
        $pendingCount = 0;
        if (is_array($allLeaveReqs)) {
            $monthNum   = array_search($month, $validMonths) + 1;
            $monthStart = sprintf('%04d-%02d-01', (int) $year, $monthNum);
            $monthEnd   = date('Y-m-t', strtotime($monthStart));
            foreach ($allLeaveReqs as $rid => $lr) {
                if (($lr['status'] ?? '') !== 'Pending') continue;
                $lrFrom = $lr['from_date'] ?? '';
                $lrTo   = $lr['to_date'] ?? '';
                if ($lrFrom <= $monthEnd && $lrTo >= $monthStart) {
                    $pendingCount++;
                }
            }
        }
        if ($pendingCount > 0) {
            $warnings[] = "{$pendingCount} pending leave request(s) overlap with {$month} {$year}. Consider approving/rejecting them before generating payroll.";
        }

        $this->json_success([
            'ready'          => true,
            'staff_covered'  => $coveredCount,
            'staff_total'    => count($rosterIds),
            'pending_leaves' => $pendingCount,
            'warnings'       => $warnings,
        ]);
    }

    /**
     * POST — Auto-create missing accounting accounts.
     * Seeds the FULL default chart (62 accounts) — not just the 10 payroll ones.
     * Skips any account that already exists. Safe to run multiple times.
     */
    public function auto_create_payroll_accounts()
    {
        $this->_require_role(self::ADMIN_ROLES, 'auto_create_payroll_accounts');

        $coaBase = "Schools/{$this->school_name}/Accounts/ChartOfAccounts";
        $coa     = $this->firebase->get($coaBase);
        if (!is_array($coa)) $coa = [];

        // Seed the FULL chart of accounts (62 accounts), not just the 10 payroll ones
        $now      = date('c');
        $defaults = $this->_full_coa_template($now);

        $created = [];
        $skipped = [];

        foreach ($defaults as $code => $acct) {
            if (isset($coa[$code]) && is_array($coa[$code]) && ($coa[$code]['status'] ?? '') === 'active') {
                $skipped[] = $code;
                continue;
            }
            $this->firebase->set("{$coaBase}/{$code}", $acct);
            $created[] = $code;
        }

        log_message('info',
            "auto_create_payroll_accounts: school=[{$this->school_name}] "
            . "created=[" . implode(',', $created) . "] "
            . "skipped=[" . implode(',', $skipped) . "] "
            . "admin=[{$this->admin_id}]"
        );

        $this->json_success([
            'message' => count($created) > 0
                ? count($created) . ' account(s) created successfully (full chart of accounts).'
                : 'All required accounts already exist.',
            'created' => $created,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Full Chart of Accounts template — mirrors Accounting::_default_coa_template().
     * Kept in sync: any new account added to Accounting should be added here too.
     */
    private function _full_coa_template(string $ts): array
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

    /**
     * POST — Generate payroll for a given month/year.
     * Params: month (e.g. 'January'), year (e.g. '2026')
     *
     * Creates a Draft run with individual slips for all staff with salary structures.
     * Factors in attendance (absent days reduce basic proportionally).
     * Also creates expense journal entries (Debit salary expense, Credit salary payable).
     */
    public function generate_payroll()
    {
        $this->_require_role(self::ADMIN_ROLES, 'generate_payroll');

        $month = trim($this->input->post('month') ?? '');
        $year  = trim($this->input->post('year') ?? '');

        $validMonths = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];
        if (!in_array($month, $validMonths, true)) {
            $this->json_error('Invalid month.');
        }
        if (!preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid year.');
        }

        // Check payroll month lock
        $this->_check_payroll_lock($month, $year);

        // Check for existing run for the same month/year
        $existingRuns = $this->firebase->get($this->_payroll_runs());
        if (is_array($existingRuns)) {
            foreach ($existingRuns as $rid => $er) {
                if (
                    isset($er['month']) && $er['month'] === $month &&
                    isset($er['year']) && $er['year'] === $year
                ) {
                    $this->json_error("A payroll run already exists for {$month} {$year} (ID: {$rid}). Delete or use it.");
                }
            }
        }

        // Get staff from session roster
        $roster = $this->firebase->get("Schools/{$this->school_name}/{$this->session_year}/Teachers");
        if (!is_array($roster) || empty($roster)) {
            $this->json_error('No staff found in the current session roster.');
        }

        // Get all salary structures
        $salaryStructures = $this->firebase->get($this->_salary());
        if (!is_array($salaryStructures) || empty($salaryStructures)) {
            $this->json_error('No salary structures found. Please set up salary structures first.');
        }

        // Get staff profiles for names/departments
        $staffProfiles = $this->firebase->get("Users/Teachers/{$this->school_name}");

        // Validate accounting accounts exist before proceeding (H3)
        // 2030=PF Payable, 2031=ESI Payable, 2032=TDS Payable,
        // 2033=Professional Tax Payable, 2034=Other Deductions Payable
        $this->_validate_accounts(['5010', '5020', '2020', '2030', '2031', '2032', '2033', '2034'], true);

        // Fetch all leave type configs to cross-reference paid/unpaid status
        $allLeaveTypes = $this->firebase->get($this->_leave_types());
        $leaveTypeConfig = is_array($allLeaveTypes) ? $allLeaveTypes : [];

        // Get approved leave requests for this month to calculate LWP days per staff
        $allLeaveReqs = $this->firebase->get($this->_leave_req());
        $lwpByStaff       = []; // staffId => total LWP days in this month
        $lwpDaysByStaff   = []; // staffId => array of day-of-month numbers that are LWP
        $leaveDetailStaff = []; // staffId => array of leave detail objects for payslip transparency
        if (is_array($allLeaveReqs)) {
            $monthStart = sprintf('%04d-%02d-01', (int) $year, array_search($month, $validMonths) + 1);
            $monthEnd   = date('Y-m-t', strtotime($monthStart));
            foreach ($allLeaveReqs as $rid => $lr) {
                if (($lr['status'] ?? '') !== 'Approved') continue;
                $sid = $lr['staff_id'] ?? '';
                if ($sid === '') continue;

                // Check if leave overlaps with this payroll month at all
                $lrFrom = $lr['from_date'] ?? '';
                $lrToRaw = $lr['to_date'] ?? '';
                if ($lrFrom > $monthEnd || $lrToRaw < $monthStart) continue;

                $ltId  = $lr['type_id'] ?? '';
                $ltCfg = $leaveTypeConfig[$ltId] ?? null;
                $ltCode = is_array($ltCfg) ? strtoupper(trim($ltCfg['code'] ?? '')) : ($lr['type_code'] ?? '?');
                $ltPaid = true; // safe default

                if (is_array($ltCfg)) {
                    $ltPaid = ($ltCfg['paid'] === true || $ltCfg['paid'] === 'true' || $ltCfg['paid'] === '1');
                } else {
                    log_message('error', "generate_payroll: leave type '{$ltId}' not found for request '{$rid}' — skipping LWP deduction (safe default)");
                }

                // Collect leave detail for payslip transparency
                if (!isset($leaveDetailStaff[$sid])) $leaveDetailStaff[$sid] = [];
                $leaveDetailStaff[$sid][] = [
                    'request_id' => $rid,
                    'type_code'  => $ltCode,
                    'type_name'  => $lr['type_name'] ?? ($ltCfg['name'] ?? 'Unknown'),
                    'paid'       => $ltPaid,
                    'from'       => $lrFrom,
                    'to'         => $lrToRaw,
                    'total_days' => (int) ($lr['days'] ?? 0),
                    'paid_days'  => (int) ($lr['paid_days'] ?? 0),
                    'lwp_days'   => (int) ($lr['lwp_days'] ?? 0),
                    'pay_label'  => $lr['pay_label'] ?? ($ltPaid ? 'Paid Leave' : 'Unpaid Leave (LWP)'),
                ];

                // Skip LWP deduction for paid leave types
                if ($ltPaid) {
                    log_message('debug', "generate_payroll: skipping LWP for request '{$rid}' — leave type '{$ltId}' is paid (code: {$ltCode})");
                    continue;
                }

                $lwp = (int) ($lr['lwp_days'] ?? 0);
                if ($lwp <= 0) continue;
                $sid = $lr['staff_id'] ?? '';
                if ($sid === '') continue;
                // LWP days are at the END of the leave period (paid days consumed first)
                $lrTo = $lr['to_date'] ?? '';
                // Calculate the start date of the LWP-only portion
                $lwpStart = date('Y-m-d', strtotime("{$lrTo} -" . ($lwp - 1) . " days"));
                // Check if LWP date range overlaps with this payroll month
                if ($lwpStart <= $monthEnd && $lrTo >= $monthStart) {
                    $overlapStart = max($lwpStart, $monthStart);
                    $overlapEnd   = min($lrTo, $monthEnd);
                    $lwpInMonth   = (int) (new DateTime($overlapStart))->diff(new DateTime($overlapEnd))->days + 1;
                    if (!isset($lwpByStaff[$sid])) $lwpByStaff[$sid] = 0;
                    $lwpByStaff[$sid] += $lwpInMonth;

                    // Track which day-of-month numbers are LWP days (1-based)
                    if (!isset($lwpDaysByStaff[$sid])) $lwpDaysByStaff[$sid] = [];
                    $cursor = new DateTime($overlapStart);
                    $end    = new DateTime($overlapEnd);
                    while ($cursor <= $end) {
                        $lwpDaysByStaff[$sid][(int) $cursor->format('j')] = true;
                        $cursor->modify('+1 day');
                    }
                }
            }
        }

        // Get attendance for the month
        $monthYear  = "{$month} {$year}";
        $attendance = $this->firebase->get(
            "Schools/{$this->school_name}/{$this->session_year}/Staff_Attendance/{$monthYear}"
        );

        // HR-5 FIX: Exclude Sundays, 2nd & 4th Saturdays, and school holidays
        $monthNum    = array_search($month, $validMonths) + 1;
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $monthNum, 1, (int) $year));

        // Load school holidays for this month (HR-11 FIX: merge both Events and Attendance holiday sources)
        $holidays = [];
        try {
            // Source 1: Events module — array of [{date: "YYYY-MM-DD", ...}]
            $holidayData = $this->firebase->get("Schools/{$this->school_name}/Events/Holidays/{$year}");
            if (is_array($holidayData)) {
                foreach ($holidayData as $h) {
                    $hDate = $h['date'] ?? '';
                    if ($hDate && (int) date('n', strtotime($hDate)) === $monthNum) {
                        $holidays[date('j', strtotime($hDate))] = true;
                    }
                }
            }
            // Source 2: Attendance settings — {"YYYY-MM-DD": "Holiday Name", ...}
            $attHolidays = $this->firebase->get("Schools/{$this->school_name}/Config/Attendance/holidays");
            if (is_array($attHolidays)) {
                foreach ($attHolidays as $date => $name) {
                    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
                        if ((int) $m[1] === (int) $year && (int) $m[2] === $monthNum) {
                            $holidays[(int) $m[3]] = true;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Non-fatal: proceed without holidays
        }

        $nonWorkingDays = 0;
        $sundayCount    = 0;
        $offSatCount    = 0;
        $holidayCount   = 0;
        $satCount       = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dow = (int) date('w', mktime(0, 0, 0, $monthNum, $d, (int) $year));
            if ($dow === 0) {
                $sundayCount++;
                $nonWorkingDays++;
            } elseif ($dow === 6) {
                $satCount++;
                if ($satCount === 2 || $satCount === 4) {
                    $offSatCount++;
                    $nonWorkingDays++;
                }
            } elseif (isset($holidays[$d])) {
                $holidayCount++;
                $nonWorkingDays++;
            }
        }
        $workingDays = $daysInMonth - $nonWorkingDays;

        // Generate run ID
        $runId = $this->_next_id('PR', 'PayrollRun');

        $totalGross          = 0;
        $totalDeductions     = 0;
        $totalNet            = 0;
        $totalPF             = 0;
        $totalESI            = 0;
        $totalTDS            = 0;
        $totalProfTax        = 0;
        $totalOtherDed       = 0;
        $staffCount          = 0;
        $totalTeachingExp    = 0;
        $totalNonTeachingExp = 0;
        $slips               = [];

        $payrollWarnings = [];
        $deptGross       = []; // department → total gross (cost center tracking)

        // Load attendance config for vacant-day handling
        $attConfig = $this->firebase->get("Schools/{$this->school_name}/Config/AttendanceRules");
        if (!is_array($attConfig)) $attConfig = [];

        foreach ($roster as $staffId => $rosterData) {
            $profile = (isset($staffProfiles[$staffId]) && is_array($staffProfiles[$staffId]))
                ? $staffProfiles[$staffId]
                : [];

            // ── Safety: auto-create missing structure from profile ──
            if (!isset($salaryStructures[$staffId]) || !is_array($salaryStructures[$staffId])) {
                $autoStruct = $this->_auto_create_from_profile($staffId, $profile);
                if ($autoStruct === null) {
                    $payrollWarnings[] = ($profile['Name'] ?? $staffId) . ': no salary data — skipped';
                    continue;
                }
                $salaryStructures[$staffId] = $autoStruct;
            }

            // ── Validate structure ──
            $sal = $this->_validate_structure($salaryStructures[$staffId]);
            if ($sal === null) {
                $this->_log_payroll_warning('corrupt_structure', $staffId,
                    'Salary structure has invalid/corrupt values — staff skipped');
                $payrollWarnings[] = ($profile['Name'] ?? $staffId) . ': corrupt salary data — skipped';
                continue;
            }

            $staffName  = $profile['Name'] ?? $staffId;
            $department = $profile['Department'] ?? '';
            $position   = $profile['Position'] ?? '';

            // Determine absent days and leave days from attendance string.
            // Attendance marks: P=Present, A=Absent, L=Leave, H=Holiday, T=Late, V=Vacant
            // "L" marks are set automatically when leave is approved (see _apply_leave_to_attendance).
            // NOTE: Skip days already covered by approved LWP leave to avoid double-counting.
            $daysAbsent      = 0;
            $paidLeaveDays   = 0;
            $unpaidLeaveDays = 0;
            $vacantDays      = 0; // days not marked at all
            $staffLwpSet     = isset($lwpDaysByStaff[$staffId]) ? $lwpDaysByStaff[$staffId] : [];

            // Config: how to treat vacant (unmarked) days
            // 'absent' = treat as absent (default), 'present' = treat as present, 'ignore' = exclude
            $vacantTreatment = is_array($attConfig) ? ($attConfig['vacant_treatment'] ?? 'absent') : 'absent';

            if (is_array($attendance) && isset($attendance[$staffId])) {
                $attStr = (string) $attendance[$staffId];
                $len    = strlen($attStr);
                for ($i = 0; $i < $len; $i++) {
                    $dayNum = $i + 1;
                    $ch = strtoupper($attStr[$i]);
                    if ($ch === 'A') {
                        if (!isset($staffLwpSet[$dayNum])) {
                            $daysAbsent++;
                        }
                    } elseif ($ch === 'L') {
                        if (isset($staffLwpSet[$dayNum])) {
                            $unpaidLeaveDays++;
                        } else {
                            $paidLeaveDays++;
                        }
                    } elseif ($ch === 'V') {
                        $vacantDays++;
                        if ($vacantTreatment === 'absent') {
                            $daysAbsent++;
                        }
                        // 'present' and 'ignore' = don't add to absent
                    }
                }
            } else {
                // No attendance at all — entire month is vacant
                $vacantDays = $workingDays;
                if ($vacantTreatment === 'absent') {
                    $daysAbsent = $workingDays;
                }
            }

            // Add warning if attendance has unmarked days
            if ($vacantDays > 0) {
                $payrollWarnings[] = ($staffName) . ": {$vacantDays} day(s) not marked (treated as {$vacantTreatment})";
            }

            // LWP days for this staff from approved leave requests
            $staffLwpDays = isset($lwpByStaff[$staffId]) ? (int) $lwpByStaff[$staffId] : 0;

            // Payable days = working_days - absent - LWP days
            // Paid leave is counted as present (no salary deduction)
            $daysWorked = $workingDays - $daysAbsent - $staffLwpDays;
            if ($daysWorked < 0) $daysWorked = 0;

            // Calculate pay — basic reduced proportionally for absent + LWP days only
            // Paid leave days do NOT reduce salary
            $basic = (float) ($sal['basic'] ?? 0);
            $deductionDays  = $daysAbsent + $staffLwpDays;
            $absentFraction = ($workingDays > 0) ? ($deductionDays / $workingDays) : 0;
            $effectiveBasic = round($basic * (1 - $absentFraction), 2);

            // Calculate LWP deduction amount separately for payslip display
            $dailySalary   = ($workingDays > 0) ? round($basic / $workingDays, 2) : 0;
            $lwpDeduction  = round($dailySalary * $staffLwpDays, 2);

            // HR-6 FIX: Pro-rate allowances by the same absent fraction as basic
            $hra             = round((float) ($sal['hra'] ?? 0) * (1 - $absentFraction), 2);
            $da              = round((float) ($sal['da'] ?? 0) * (1 - $absentFraction), 2);
            $ta              = round((float) ($sal['ta'] ?? 0) * (1 - $absentFraction), 2);
            $medical         = round((float) ($sal['medical'] ?? 0) * (1 - $absentFraction), 2);
            $otherAllowances = round((float) ($sal['other_allowances'] ?? 0) * (1 - $absentFraction), 2);

            $gross = round($effectiveBasic + $hra + $da + $ta + $medical + $otherAllowances, 2);

            // Deductions: PF/ESI/TDS are stored as PERCENTAGES in salary structure
            // PF = % of original basic (before pro-ration)
            // ESI = % of original gross
            // TDS = % of original gross
            // Professional Tax & Other Deductions = flat amounts (not %)
            $origBasic = (float) ($sal['basic'] ?? 0);
            $origGross = $origBasic
                + (float) ($sal['hra'] ?? 0)
                + (float) ($sal['da'] ?? 0)
                + (float) ($sal['ta'] ?? 0)
                + (float) ($sal['medical'] ?? 0)
                + (float) ($sal['other_allowances'] ?? 0);

            $pfPct               = (float) ($sal['pf_employee'] ?? 0);
            $pfEmployee          = round($origBasic * ($pfPct / 100), 2);
            $pfEmployerPct       = (float) ($sal['pf_employer'] ?? 0);
            $pfEmployer          = round($origBasic * ($pfEmployerPct / 100), 2);
            $esiPct              = (float) ($sal['esi_employee'] ?? 0);
            $esiEmployee         = round($origGross * ($esiPct / 100), 2);
            $esiEmployerPct      = (float) ($sal['esi_employer'] ?? 0);
            $esiEmployer         = round($origGross * ($esiEmployerPct / 100), 2);
            $tdsPct              = (float) ($sal['tds'] ?? 0);
            $tds                 = round($origGross * ($tdsPct / 100), 2);
            $professionalTax     = (float) ($sal['professional_tax'] ?? 0); // flat amount
            $otherDeductions     = (float) ($sal['other_deductions'] ?? 0); // flat amount

            $employeeDeductions = round(
                $pfEmployee + $esiEmployee + $professionalTax + $tds + $otherDeductions, 2
            );
            $netPay = round($gross - $employeeDeductions, 2);
            if ($netPay < 0) $netPay = 0;

            // Classify as Teaching or Non-Teaching for expense accounts
            // Primary: use staff_roles + role category from Config/StaffRoles
            $staffRoles = $profile['staff_roles'] ?? [];
            $isTeaching = false;
            if (!empty($staffRoles) && is_array($staffRoles)) {
                if (!isset($_roleDefs)) {
                    $_roleDefs = $this->firebase->get("Schools/{$this->school_name}/Config/StaffRoles") ?? [];
                    if (!is_array($_roleDefs)) $_roleDefs = [];
                }
                foreach ($staffRoles as $_rid) {
                    if (($_roleDefs[$_rid]['category'] ?? '') === 'Teaching') {
                        $isTeaching = true;
                        break;
                    }
                }
            } else {
                // Fallback for unmigrated staff: use Position text matching
                $isTeaching = (
                    stripos($position, 'teacher') !== false ||
                    stripos($position, 'lecturer') !== false ||
                    stripos($department, 'teaching') !== false
                );
            }
            if ($isTeaching) {
                $totalTeachingExp += $gross;
            } else {
                $totalNonTeachingExp += $gross;
            }

            // Track department-wise totals for cost center reporting
            $deptKey = $department ?: 'General';
            if (!isset($deptGross[$deptKey])) $deptGross[$deptKey] = 0;
            $deptGross[$deptKey] += $gross;

            $slips[$staffId] = [
                'staff_id'         => $staffId,
                'staff_name'       => $staffName,
                'department'       => $department,
                'basic'            => $effectiveBasic,
                'hra'              => $hra,
                'da'               => $da,
                'ta'               => $ta,
                'medical'          => $medical,
                'other_allowances' => $otherAllowances,
                'gross'            => $gross,
                'pf_employee'      => $pfEmployee,
                'pf_employer'      => $pfEmployer,
                'esi_employee'     => $esiEmployee,
                'esi_employer'     => $esiEmployer,
                'professional_tax' => $professionalTax,
                'tds'              => $tds,
                'other_deductions' => $otherDeductions,
                'total_deductions' => $employeeDeductions,
                'net_pay'          => $netPay,
                'days_worked'      => $daysWorked,
                'days_absent'      => $daysAbsent,
                'paid_leave_days'  => $paidLeaveDays,
                'leave_days'       => $paidLeaveDays + $unpaidLeaveDays,
                'lwp_days'         => $staffLwpDays,
                'lwp_deduction'    => $lwpDeduction,
                'working_days'     => $workingDays,
                'daily_salary'     => $dailySalary,
                'deduction_days'   => $deductionDays,
                'deduction_reason' => $staffLwpDays > 0
                    ? "{$staffLwpDays} LWP day(s) deducted at {$dailySalary}/day = {$lwpDeduction}"
                    : ($daysAbsent > 0 ? "{$daysAbsent} absent day(s) deducted" : 'No deduction'),
                'leave_details'    => $leaveDetailStaff[$staffId] ?? [],
                'vacant_days'      => $vacantDays,
                'vacant_treatment' => $vacantTreatment,
                'att_warning'      => $vacantDays > 0
                    ? "Attendance incomplete: {$vacantDays} day(s) not marked (treated as {$vacantTreatment})"
                    : '',
                'status'           => 'Draft',
                // Snapshot: freeze the salary structure used for this payslip
                // Future edits to Salary_Structures won't retroactively change this slip
                '_salary_snapshot' => [
                    'basic' => (float) ($sal['basic'] ?? 0),
                    'hra' => (float) ($sal['hra'] ?? 0), 'da' => (float) ($sal['da'] ?? 0),
                    'ta' => (float) ($sal['ta'] ?? 0), 'medical' => (float) ($sal['medical'] ?? 0),
                    'other_allowances' => (float) ($sal['other_allowances'] ?? 0),
                    'pf_employee' => (float) ($sal['pf_employee'] ?? 0), 'esi_employee' => (float) ($sal['esi_employee'] ?? 0),
                    'professional_tax' => (float) ($sal['professional_tax'] ?? 0), 'tds' => (float) ($sal['tds'] ?? 0),
                    'source' => $sal['source'] ?? '', '_version' => $sal['_version'] ?? 1,
                ],
            ];

            $totalGross      += $gross;
            $totalDeductions += $employeeDeductions;
            $totalNet        += $netPay;
            $totalPF         += $pfEmployee;
            $totalESI        += $esiEmployee;
            $totalTDS        += $tds;
            $totalProfTax    += $professionalTax;
            $totalOtherDed   += $otherDeductions;
            $staffCount++;
        }

        if ($staffCount === 0) {
            $this->json_error('No staff with salary structures found in the roster.');
        }

        $totalGross          = round($totalGross, 2);
        $totalDeductions     = round($totalDeductions, 2);
        $totalNet            = round($totalNet, 2);
        $totalPF             = round($totalPF, 2);
        $totalESI            = round($totalESI, 2);
        $totalTDS            = round($totalTDS, 2);
        $totalProfTax        = round($totalProfTax, 2);
        $totalOtherDed       = round($totalOtherDed, 2);
        $totalTeachingExp    = round($totalTeachingExp, 2);
        $totalNonTeachingExp = round($totalNonTeachingExp, 2);

        // Create payroll run record
        $runData = [
            'month'            => $month,
            'year'             => $year,
            'status'           => 'Draft',
            'total_gross'      => $totalGross,
            'total_deductions' => $totalDeductions,
            'total_net'        => $totalNet,
            'staff_count'      => $staffCount,
            'working_days'     => $workingDays,
            'days_in_month'    => $daysInMonth,
            'sundays'          => $sundayCount,
            'off_saturdays'    => $offSatCount,
            'holidays'         => $holidayCount,
            'created_at'       => date('c'),
            'created_by'       => $this->admin_name,
            'finalized_at'     => '',
            'finalized_by'     => '',
            'paid_at'          => '',
            'paid_by'          => '',
            'payment_mode'     => '',
            'journal_id'       => '',
            'total_paid'       => 0,
            'balance_due'      => $totalNet,
            'warnings'         => $payrollWarnings,
            'dept_breakdown'   => $deptGross,
        ];

        $this->firebase->set($this->_payroll_runs($runId), $runData);

        // Create individual slips (single batch write instead of N sequential calls)
        $this->firebase->set($this->_payroll_slips($runId), $slips);

        // ── Expense journal entry (Debit salary expenses, Credit payable accounts) ──
        // DR side: gross salary expense (teaching + non-teaching)
        // CR side: net salary payable (2020) + statutory deductions to separate liability accounts
        //   2030 = PF Payable, 2031 = ESI Payable, 2032 = TDS Payable
        // This ensures 2020 only holds the net amount cleared on payment day,
        // while statutory liabilities sit in their own accounts until remitted to government.
        $narration = "Salary accrual - {$month} {$year}";
        $journalLines = [];
        if ($totalTeachingExp > 0) {
            $journalLines[] = ['account_code' => '5010', 'dr' => $totalTeachingExp, 'cr' => 0];
        }
        if ($totalNonTeachingExp > 0) {
            $journalLines[] = ['account_code' => '5020', 'dr' => $totalNonTeachingExp, 'cr' => 0];
        }
        $journalLines[] = ['account_code' => '2020', 'dr' => 0, 'cr' => $totalNet];
        if ($totalPF > 0) {
            $journalLines[] = ['account_code' => '2030', 'dr' => 0, 'cr' => $totalPF];
        }
        if ($totalESI > 0) {
            $journalLines[] = ['account_code' => '2031', 'dr' => 0, 'cr' => $totalESI];
        }
        if ($totalTDS > 0) {
            $journalLines[] = ['account_code' => '2032', 'dr' => 0, 'cr' => $totalTDS];
        }
        if ($totalProfTax > 0) {
            $journalLines[] = ['account_code' => '2033', 'dr' => 0, 'cr' => $totalProfTax];
        }
        if ($totalOtherDed > 0) {
            $journalLines[] = ['account_code' => '2034', 'dr' => 0, 'cr' => $totalOtherDed];
        }

        $journalId = $this->_create_acct_journal($narration, $journalLines, $runId);

        // Store expense journal ID in run
        $this->firebase->update($this->_payroll_runs($runId), [
            'expense_journal_id' => $journalId,
        ]);

        $this->_log_payroll('generated', $runId, [
            'month' => $month, 'year' => $year,
            'staff_count' => $staffCount, 'total_net' => $totalNet,
            'journal_id' => $journalId,
        ]);

        $msg = "Payroll generated for {$month} {$year}: {$staffCount} staff, Net: {$totalNet}.";
        if (!empty($payrollWarnings)) {
            $msg .= ' (' . count($payrollWarnings) . ' warning(s))';
        }

        $this->json_success([
            'id'       => $runId,
            'message'  => $msg,
            'warnings' => $payrollWarnings,
            'summary'  => [
                'total_gross'      => $totalGross,
                'total_deductions' => $totalDeductions,
                'total_net'        => $totalNet,
                'staff_count'      => $staffCount,
                'working_days'     => $workingDays,
                'journal_id'       => $journalId,
            ],
        ]);
    }

    /**
     * GET — Get payroll slips for a run. ?run_id=PR0001
     */
    public function get_payroll_slips()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_payroll_slips');

        $runId = trim($this->input->get('run_id') ?? '');
        if ($runId === '') {
            $this->json_error('Payroll run ID is required.');
        }
        $runId = $this->safe_path_segment($runId, 'run_id');

        // Verify run exists
        $run = $this->firebase->get($this->_payroll_runs($runId));
        if (!is_array($run)) {
            $this->json_error('Payroll run not found.');
        }

        $slips = $this->firebase->get($this->_payroll_slips($runId));
        $list = [];
        if (is_array($slips)) {
            foreach ($slips as $staffId => $s) {
                $s['staff_id'] = $staffId;
                $list[] = $s;
            }
        }

        // Sort by staff name
        usort($list, function ($a, $b) {
            return strcmp($a['staff_name'] ?? '', $b['staff_name'] ?? '');
        });

        $this->json_success([
            'run'   => array_merge($run, ['id' => $runId]),
            'slips' => $list,
        ]);
    }

    /**
     * POST — Finalize a payroll run (lock it from edits).
     * Params: run_id
     */
    public function finalize_payroll()
    {
        $this->_require_role(self::ADMIN_ROLES, 'finalize_payroll');

        $runId = $this->safe_path_segment(trim($this->input->post('run_id') ?? ''), 'run_id');

        $run = $this->firebase->get($this->_payroll_runs($runId));
        if (!is_array($run)) {
            $this->json_error('Payroll run not found.');
        }
        if (($run['status'] ?? '') !== 'Draft') {
            $this->json_error('Only Draft payroll runs can be finalized.');
        }

        $this->firebase->update($this->_payroll_runs($runId), [
            'status'       => 'Finalized',
            'finalized_at' => date('c'),
            'finalized_by' => $this->admin_name,
        ]);

        // Batch-update all slip statuses in a single call
        $slips = $this->firebase->get($this->_payroll_slips($runId));
        if (is_array($slips)) {
            $statusPatch = [];
            foreach ($slips as $staffId => $s) {
                $statusPatch["{$staffId}/status"] = 'Finalized';
            }
            $this->firebase->update($this->_payroll_slips($runId), $statusPatch);
        }

        $this->_log_payroll('finalized', $runId);

        $this->json_success(['message' => 'Payroll run finalized.']);
    }

    /**
     * POST — Approve a finalized payroll run (approval workflow).
     * Adds an approval step between Finalize and Pay.
     * Only Admin/Principal can approve.
     */
    public function approve_payroll()
    {
        $this->_require_role(self::ADMIN_ROLES, 'approve_payroll');

        $runId = $this->safe_path_segment(trim($this->input->post('run_id') ?? ''), 'run_id');

        $run = $this->firebase->get($this->_payroll_runs($runId));
        if (!is_array($run)) {
            $this->json_error('Payroll run not found.');
        }
        $status = $run['status'] ?? '';
        if ($status !== 'Finalized') {
            $this->json_error("Only Finalized runs can be approved. Current status: {$status}.");
        }

        $this->firebase->update($this->_payroll_runs($runId), [
            'status'      => 'Approved',
            'approved_at' => date('c'),
            'approved_by' => $this->admin_name,
        ]);

        // Update slip statuses
        $slips = $this->firebase->get($this->_payroll_slips($runId));
        if (is_array($slips)) {
            $patch = [];
            foreach ($slips as $sid => $s) {
                $patch["{$sid}/status"] = 'Approved';
            }
            $this->firebase->update($this->_payroll_slips($runId), $patch);
        }

        $this->_log_payroll('approved', $runId);
        $this->json_success(['message' => 'Payroll approved. Ready for payment.']);
    }

    /**
     * GET — Download payslip as PDF.
     * Params: run_id, staff_id
     * Admin can download any; staff can download only their own.
     */
    public function download_payslip()
    {
        $this->_require_role(self::VIEW_ROLES, 'download_payslip');

        $runId   = $this->safe_path_segment(trim($this->input->get('run_id') ?? ''), 'run_id');
        $staffId = trim($this->input->get('staff_id') ?? '');

        // Non-admin can only download own
        if (!in_array($this->admin_role, self::ADMIN_ROLES, true)) {
            $staffId = $this->admin_id;
        }
        $staffId = $this->safe_path_segment($staffId, 'staff_id');

        $run  = $this->firebase->get($this->_payroll_runs($runId));
        if (!is_array($run)) show_error('Payroll run not found.', 404);
        $slip = $this->firebase->get($this->_payroll_slips($runId, $staffId));
        if (!is_array($slip)) show_error('Payslip not found.', 404);

        // Only allow download of Finalized/Approved/Paid runs
        $st = $run['status'] ?? '';
        if (!in_array($st, ['Finalized', 'Approved', 'Paid', 'Partially Paid'], true)) {
            show_error('Payslip not available yet (still in draft).', 403);
        }

        $profile = $this->firebase->get("Users/Teachers/{$this->school_name}/{$staffId}");
        $schoolLogo = $this->firebase->get("Schools/{$this->school_name}/Logo") ?: '';
        $schoolProfile = $this->firebase->get("Schools/{$this->school_name}/Config/Profile");
        $schoolName = $schoolProfile['name'] ?? ($this->school_display_name ?? $this->school_name);
        $schoolAddr = $schoolProfile['address'] ?? '';

        $html = $this->_render_payslip_html($run, $slip, $profile, $schoolName, $schoolAddr, $schoolLogo, $staffId);

        $this->load->library('pdf_generator');
        $filename = preg_replace('/[^A-Za-z0-9_]/', '', $schoolName)
            . '_Payslip_' . ($run['month'] ?? '') . '_' . ($run['year'] ?? '') . '_' . $staffId . '.pdf';
        $this->pdf_generator->download($html, $filename);
    }

    /**
     * GET — Export payroll report as Excel.
     * Params: type (salary_register|department|payment), run_id or month+year
     */
    public function export_payroll_report()
    {
        $this->_require_role(self::ADMIN_ROLES, 'export_payroll_report');

        $type  = trim($this->input->get('type') ?? 'salary_register');
        $runId = trim($this->input->get('run_id') ?? '');
        $month = trim($this->input->get('month') ?? '');
        $year  = trim($this->input->get('year') ?? '');

        // Resolve run
        $run = null;
        $slips = [];
        if ($runId !== '') {
            $runId = $this->safe_path_segment($runId, 'run_id');
            $run = $this->firebase->get($this->_payroll_runs($runId));
            $slips = $this->firebase->get($this->_payroll_slips($runId)) ?? [];
        } elseif ($month !== '' && $year !== '') {
            $allRuns = $this->firebase->get($this->_payroll_runs());
            if (is_array($allRuns)) {
                foreach ($allRuns as $rid => $r) {
                    if (($r['month'] ?? '') === $month && ($r['year'] ?? '') === $year) {
                        $run = $r; $runId = $rid;
                        $slips = $this->firebase->get($this->_payroll_slips($rid)) ?? [];
                        break;
                    }
                }
            }
        }
        if (!is_array($run)) show_error('Payroll run not found.', 404);
        if (!is_array($slips)) $slips = [];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $schoolName = preg_replace('/[^A-Za-z0-9_ ]/', '', $this->school_display_name ?? $this->school_name);
        $period = ($run['month'] ?? '') . ' ' . ($run['year'] ?? '');

        switch ($type) {
            case 'department':
                $this->_xl_dept_report($sheet, $run, $slips, $schoolName, $period);
                break;
            case 'payment':
                $this->_xl_payment_report($sheet, $run, $schoolName, $period);
                break;
            default: // salary_register
                $this->_xl_salary_register($sheet, $slips, $schoolName, $period);
                break;
        }

        // Auto-width + freeze
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A3');

        $filename = $schoolName . '_' . $type . '_' . str_replace(' ', '_', $period) . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        $spreadsheet->disconnectWorksheets();
        exit;
    }

    // ── Payslip PDF HTML renderer ──

    private function _render_payslip_html(array $run, array $slip, $profile, string $school, string $addr, string $logo, string $staffId): string
    {
        $p = is_array($profile) ? $profile : [];
        $month = $run['month'] ?? ''; $year = $run['year'] ?? '';
        $staffName = $p['Name'] ?? ($slip['staff_name'] ?? $staffId);
        $dept = $p['Department'] ?? ($slip['department'] ?? '');
        $pos = $p['Position'] ?? '';
        $doj = $p['Date Of Joining'] ?? '';
        $empId = $staffId;
        $bankName = $p['bankDetails']['bankName'] ?? '';
        $bankAcct = $p['bankDetails']['accountNumber'] ?? '';

        $gross = (float) ($slip['gross'] ?? 0);
        $totalDed = (float) ($slip['total_deductions'] ?? 0);
        $netPay = (float) ($slip['net_pay'] ?? 0);

        $fmt = function ($v) { return number_format((float) $v, 2, '.', ','); };

        $css = 'body{font-family:sans-serif;font-size:11px;color:#1a2e2a;margin:0;padding:20px 30px}
        table{width:100%;border-collapse:collapse}
        .hdr{text-align:center;margin-bottom:16px}
        .hdr h1{font-size:16px;margin:0;color:#0f766e}
        .hdr p{margin:2px 0;color:#4a6a60;font-size:10px}
        .hdr .period{font-size:13px;font-weight:700;color:#0f766e;margin-top:8px}
        .info td{padding:4px 8px;font-size:10px;border:1px solid #d1ddd8}
        .info .lbl{font-weight:700;color:#4a6a60;width:25%}
        .section{font-size:11px;font-weight:700;color:#0f766e;padding:8px 0 4px;border-bottom:2px solid #0f766e;margin-top:14px}
        .pay td{padding:5px 8px;border:1px solid #d1ddd8;font-size:10px}
        .pay .r{text-align:right;font-family:monospace}
        .pay .total{font-weight:700;background:#e6f4f1}
        .net{text-align:center;font-size:14px;font-weight:700;color:#0f766e;padding:12px;background:#e6f4f1;border-radius:6px;margin:14px 0}
        .footer{font-size:9px;color:#7a9a8e;text-align:center;margin-top:20px;border-top:1px solid #d1ddd8;padding-top:8px}';

        $h = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';

        // Header
        $h .= '<div class="hdr">';
        if ($logo) $h .= '<img src="' . htmlspecialchars($logo) . '" style="max-height:50px;margin-bottom:6px"><br>';
        $h .= '<h1>' . htmlspecialchars($school) . '</h1>';
        if ($addr) $h .= '<p>' . htmlspecialchars($addr) . '</p>';
        $h .= '<div class="period">Payslip for ' . htmlspecialchars($month . ' ' . $year) . '</div>';
        $h .= '</div>';

        // Employee info
        $h .= '<table class="info"><tr><td class="lbl">Employee Name</td><td>' . htmlspecialchars($staffName) . '</td><td class="lbl">Employee ID</td><td>' . htmlspecialchars($empId) . '</td></tr>';
        $h .= '<tr><td class="lbl">Department</td><td>' . htmlspecialchars($dept) . '</td><td class="lbl">Designation</td><td>' . htmlspecialchars($pos) . '</td></tr>';
        $h .= '<tr><td class="lbl">Date of Joining</td><td>' . htmlspecialchars($doj) . '</td><td class="lbl">Working Days</td><td>' . ($slip['working_days'] ?? '-') . ' (' . ($slip['days_worked'] ?? '-') . ' worked)</td></tr>';
        if ($bankName) $h .= '<tr><td class="lbl">Bank</td><td>' . htmlspecialchars($bankName) . '</td><td class="lbl">Account No.</td><td>' . htmlspecialchars($bankAcct) . '</td></tr>';
        $h .= '</table>';

        // Earnings & Deductions side-by-side
        $h .= '<table style="margin-top:14px"><tr><td style="width:50%;vertical-align:top;padding-right:8px">';
        $h .= '<div class="section">Earnings</div>';
        $h .= '<table class="pay">';
        $h .= '<tr><td>Basic Salary</td><td class="r">' . $fmt($slip['basic'] ?? 0) . '</td></tr>';
        foreach (['hra' => 'HRA', 'da' => 'DA', 'ta' => 'TA', 'medical' => 'Medical', 'other_allowances' => 'Other Allowances'] as $k => $lbl) {
            $v = (float) ($slip[$k] ?? 0);
            if ($v > 0) $h .= '<tr><td>' . $lbl . '</td><td class="r">' . $fmt($v) . '</td></tr>';
        }
        $h .= '<tr class="total"><td>Gross Pay</td><td class="r">' . $fmt($gross) . '</td></tr>';
        $h .= '</table>';
        $h .= '</td><td style="width:50%;vertical-align:top;padding-left:8px">';
        $h .= '<div class="section">Deductions</div>';
        $h .= '<table class="pay">';
        foreach (['pf_employee' => 'PF (Employee)', 'esi_employee' => 'ESI (Employee)', 'professional_tax' => 'Professional Tax', 'tds' => 'TDS', 'other_deductions' => 'Other Deductions'] as $k => $lbl) {
            $v = (float) ($slip[$k] ?? 0);
            if ($v > 0) $h .= '<tr><td>' . $lbl . '</td><td class="r">' . $fmt($v) . '</td></tr>';
        }
        if ((float) ($slip['lwp_deduction'] ?? 0) > 0) {
            $h .= '<tr><td>LWP Deduction (' . ($slip['lwp_days'] ?? 0) . ' days)</td><td class="r">' . $fmt($slip['lwp_deduction']) . '</td></tr>';
        }
        $h .= '<tr class="total"><td>Total Deductions</td><td class="r">' . $fmt($totalDed) . '</td></tr>';
        $h .= '</table>';
        $h .= '</td></tr></table>';

        // Net Pay
        $h .= '<div class="net">Net Pay: ₹ ' . $fmt($netPay) . '</div>';

        // Footer
        $h .= '<div class="footer">This is a computer-generated payslip. Generated on ' . date('d M Y, h:i A') . '</div>';
        $h .= '</body></html>';
        return $h;
    }

    // ── Excel report builders ──

    private function _xl_salary_register($sheet, array $slips, string $school, string $period): void
    {
        $sheet->setTitle('Salary Register');
        $sheet->setCellValue('A1', $school . ' — Salary Register — ' . $period);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $cols = ['#','Staff','Dept','Basic','HRA','DA','TA','Medical','Other','Gross','PF','ESI','PT','TDS','Deductions','Net Pay','Days Worked','Absent','LWP'];
        $c = 'A'; $r = 2;
        foreach ($cols as $label) {
            $sheet->setCellValue($c.$r, $label);
            $sheet->getStyle($c.$r)->getFont()->setBold(true);
            $sheet->getStyle($c.$r)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E6F4F1');
            $c++;
        }
        $r = 3; $i = 0;
        $tGross = 0; $tDed = 0; $tNet = 0;
        foreach ($slips as $sid => $s) {
            $i++; $c = 'A';
            $vals = [$i, $s['staff_name']??$sid, $s['department']??'', $s['basic']??0, $s['hra']??0, $s['da']??0, $s['ta']??0, $s['medical']??0, $s['other_allowances']??0, $s['gross']??0, $s['pf_employee']??0, $s['esi_employee']??0, $s['professional_tax']??0, $s['tds']??0, $s['total_deductions']??0, $s['net_pay']??0, $s['days_worked']??0, $s['days_absent']??0, $s['lwp_days']??0];
            foreach ($vals as $v) { $sheet->setCellValue($c.$r, $v); $c++; }
            $sheet->getStyle("D{$r}:P{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
            $tGross += (float)($s['gross']??0); $tDed += (float)($s['total_deductions']??0); $tNet += (float)($s['net_pay']??0);
            $r++;
        }
        // Totals
        $sheet->setCellValue("B{$r}", 'TOTALS'); $sheet->setCellValue("J{$r}", round($tGross,2)); $sheet->setCellValue("O{$r}", round($tDed,2)); $sheet->setCellValue("P{$r}", round($tNet,2));
        $sheet->getStyle("A{$r}:S{$r}")->getFont()->setBold(true);
        $sheet->getStyle("J{$r}:P{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
    }

    private function _xl_dept_report($sheet, array $run, array $slips, string $school, string $period): void
    {
        $sheet->setTitle('Dept Salary');
        $sheet->setCellValue('A1', $school . ' — Department Salary Report — ' . $period);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $depts = [];
        foreach ($slips as $s) {
            $d = $s['department'] ?? 'General';
            if (!isset($depts[$d])) $depts[$d] = ['count' => 0, 'gross' => 0, 'deductions' => 0, 'net' => 0];
            $depts[$d]['count']++;
            $depts[$d]['gross'] += (float)($s['gross']??0);
            $depts[$d]['deductions'] += (float)($s['total_deductions']??0);
            $depts[$d]['net'] += (float)($s['net_pay']??0);
        }

        $r = 2;
        foreach (['Department','Staff Count','Total Gross','Total Deductions','Total Net'] as $i => $lbl) {
            $c = chr(65+$i);
            $sheet->setCellValue($c.$r, $lbl);
            $sheet->getStyle($c.$r)->getFont()->setBold(true);
        }
        $r = 3;
        foreach ($depts as $name => $d) {
            $sheet->setCellValue("A{$r}", $name); $sheet->setCellValue("B{$r}", $d['count']);
            $sheet->setCellValue("C{$r}", round($d['gross'],2)); $sheet->setCellValue("D{$r}", round($d['deductions'],2)); $sheet->setCellValue("E{$r}", round($d['net'],2));
            $sheet->getStyle("C{$r}:E{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
            $r++;
        }
    }

    private function _xl_payment_report($sheet, array $run, string $school, string $period): void
    {
        $sheet->setTitle('Payment Report');
        $sheet->setCellValue('A1', $school . ' — Payment Report — ' . $period);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        // Summary
        $sheet->setCellValue('A2', 'Total Net: ' . number_format((float)($run['total_net']??0),2));
        $sheet->setCellValue('C2', 'Total Paid: ' . number_format((float)($run['total_paid']??0),2));
        $sheet->setCellValue('E2', 'Balance Due: ' . number_format((float)($run['balance_due']??0),2));
        $sheet->getStyle('A2:F2')->getFont()->setBold(true);

        $r = 4;
        foreach (['#','Date','Mode','Reference','Amount','Journal ID','Paid By'] as $i => $lbl) {
            $c = chr(65+$i); $sheet->setCellValue($c.$r, $lbl);
            $sheet->getStyle($c.$r)->getFont()->setBold(true);
        }
        $r = 5;
        $payments = $run['payments'] ?? [];
        if (is_array($payments)) {
            foreach ($payments as $seq => $pay) {
                $sheet->setCellValue("A{$r}", $pay['seq']??$seq); $sheet->setCellValue("B{$r}", $pay['payment_date']??'');
                $sheet->setCellValue("C{$r}", $pay['payment_mode']??''); $sheet->setCellValue("D{$r}", $pay['payment_reference']??'');
                $sheet->setCellValue("E{$r}", (float)($pay['amount']??0)); $sheet->setCellValue("F{$r}", $pay['journal_id']??'');
                $sheet->setCellValue("G{$r}", $pay['paid_by']??'');
                $sheet->getStyle("E{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                $r++;
            }
        }
    }

    /**
     * POST — Record a payroll payment. Supports PARTIAL payments.
     *
     * Params: run_id, payment_mode, payment_date, payment_reference, amount (optional)
     *
     * If amount is omitted or equals total_net → full payment (status=Paid).
     * If amount < total_net → partial payment (status=Partially Paid).
     * Multiple partial payments allowed until total is reached.
     *
     * Each payment creates its own journal entry (DR Salary Payable / CR Cash|Bank).
     * Payments are stored in run.payments[] array for audit trail.
     */
    public function mark_payroll_paid()
    {
        $this->_require_role(self::ADMIN_ROLES, 'mark_payroll_paid');

        $runId            = $this->safe_path_segment(trim($this->input->post('run_id') ?? ''), 'run_id');
        $paymentMode      = trim($this->input->post('payment_mode') ?? 'Bank Transfer');
        $paymentDate      = trim($this->input->post('payment_date') ?? '');
        $paymentReference = trim($this->input->post('payment_reference') ?? '');
        $payAmount        = $this->input->post('amount'); // null = full payment

        $validModes = ['Bank Transfer', 'Cash', 'UPI'];
        if (!in_array($paymentMode, $validModes, true)) {
            $paymentMode = 'Bank Transfer';
        }
        if ($paymentDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            $this->json_error('Invalid payment date format. Use YYYY-MM-DD.');
        }
        if ($paymentDate === '') $paymentDate = date('Y-m-d');

        $run = $this->firebase->get($this->_payroll_runs($runId));
        if (!is_array($run)) {
            $this->json_error('Payroll run not found.');
        }

        $runStatus = $run['status'] ?? '';
        if ($runStatus === 'Paid') {
            $this->json_error('This payroll run is already fully paid.');
        }
        if (!in_array($runStatus, ['Finalized', 'Approved', 'Partially Paid'], true)) {
            $this->json_error('Only Finalized, Approved, or Partially Paid payroll runs can receive payments.');
        }

        $totalNet   = round((float) ($run['total_net'] ?? 0), 2);
        $totalPaid  = round((float) ($run['total_paid'] ?? 0), 2);
        $remaining  = round($totalNet - $totalPaid, 2);
        $month      = $run['month'] ?? '';
        $year       = $run['year'] ?? '';

        // Determine payment amount
        if ($payAmount !== null && $payAmount !== '') {
            $payAmount = round((float) $payAmount, 2);
            if ($payAmount <= 0) {
                $this->json_error('Payment amount must be greater than zero.');
            }
            if ($payAmount > $remaining + 0.01) {
                $this->json_error("Payment amount ({$payAmount}) exceeds remaining balance ({$remaining}).");
            }
            // Clamp to remaining (handle rounding)
            if ($payAmount > $remaining) $payAmount = $remaining;
        } else {
            $payAmount = $remaining; // full payment
        }

        $isFullPayment = (abs($payAmount - $remaining) < 0.01);
        $newTotalPaid  = round($totalPaid + $payAmount, 2);

        // ── Create payment journal entry ──
        $payAccount = ($paymentMode === 'Cash') ? '1010' : '1020';
        $this->_validate_accounts(['2020', $payAccount]);

        $paymentSeq = count($run['payments'] ?? []) + 1;
        $narration  = "Salary payment" . ($isFullPayment ? '' : " (Part {$paymentSeq})")
            . " - {$month} {$year} via {$paymentMode}";
        if ($paymentReference !== '') $narration .= " (Ref: {$paymentReference})";

        $journalId = $this->_create_acct_journal($narration, [
            ['account_code' => '2020',      'dr' => $payAmount, 'cr' => 0],
            ['account_code' => $payAccount, 'dr' => 0,          'cr' => $payAmount],
        ], $runId);

        // Build payment record
        $paidAt = date('c');
        $paymentRecord = [
            'seq'               => $paymentSeq,
            'amount'            => $payAmount,
            'payment_mode'      => $paymentMode,
            'payment_date'      => $paymentDate,
            'payment_reference' => $paymentReference,
            'journal_id'        => $journalId,
            'paid_by'           => $this->admin_name,
            'paid_at'           => $paidAt,
            'recon_status'      => 'pending',  // bank reconciliation flag
        ];

        // Update run
        $newStatus = $isFullPayment ? 'Paid' : 'Partially Paid';
        $runUpdate = [
            'status'      => $newStatus,
            'total_paid'  => $newTotalPaid,
            'balance_due' => round($totalNet - $newTotalPaid, 2),
            'journal_id'  => $journalId,  // latest payment journal
        ];
        if ($isFullPayment) {
            $runUpdate['paid_at']           = $paidAt;
            $runUpdate['paid_by']           = $this->admin_name;
            $runUpdate['payment_mode']      = $paymentMode;
            $runUpdate['payment_date']      = $paymentDate;
            $runUpdate['payment_reference'] = $paymentReference;
        }
        $this->firebase->update($this->_payroll_runs($runId), $runUpdate);

        // Append payment to the payments array
        $this->firebase->set(
            $this->_payroll_runs($runId) . "/payments/{$paymentSeq}",
            $paymentRecord
        );

        // Update slip statuses only on full payment
        if ($isFullPayment) {
            $slips = $this->firebase->get($this->_payroll_slips($runId));
            if (is_array($slips)) {
                $statusPatch = [];
                foreach ($slips as $staffId => $s) {
                    $statusPatch["{$staffId}/status"]            = 'Paid';
                    $statusPatch["{$staffId}/paid_at"]           = $paidAt;
                    $statusPatch["{$staffId}/payment_mode"]      = $paymentMode;
                    $statusPatch["{$staffId}/payment_date"]      = $paymentDate;
                    $statusPatch["{$staffId}/payment_reference"] = $paymentReference;
                }
                $this->firebase->update($this->_payroll_slips($runId), $statusPatch);
            }
        }

        $this->_log_payroll($isFullPayment ? 'paid' : 'partial_payment', $runId, [
            'payment_seq'       => $paymentSeq,
            'amount'            => $payAmount,
            'total_paid'        => $newTotalPaid,
            'balance_due'       => round($totalNet - $newTotalPaid, 2),
            'payment_mode'      => $paymentMode,
            'payment_reference' => $paymentReference,
            'journal_id'        => $journalId,
        ]);

        $this->json_success([
            'message'     => $isFullPayment
                ? "Payroll fully paid via {$paymentMode}."
                : "Partial payment of " . number_format($payAmount, 2) . " recorded. Remaining: " . number_format($totalNet - $newTotalPaid, 2),
            'journal_id'  => $journalId,
            'total_paid'  => $newTotalPaid,
            'balance_due' => round($totalNet - $newTotalPaid, 2),
            'status'      => $newStatus,
        ]);
    }

    /**
     * GET — Get a single payslip. ?run_id=PR0001&staff_id=XXX
     */
    public function get_payslip()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_payslip');

        $runId   = trim($this->input->get('run_id') ?? '');
        $staffId = trim($this->input->get('staff_id') ?? '');

        if ($runId === '' || $staffId === '') {
            $this->json_error('Both run_id and staff_id are required.');
        }
        $runId   = $this->safe_path_segment($runId, 'run_id');
        $staffId = $this->safe_path_segment($staffId, 'staff_id');

        // Get run info
        $run = $this->firebase->get($this->_payroll_runs($runId));
        if (!is_array($run)) {
            $this->json_error('Payroll run not found.');
        }

        // Get slip
        $slip = $this->firebase->get($this->_payroll_slips($runId, $staffId));
        if (!is_array($slip)) {
            $this->json_error('Payslip not found for this staff member.');
        }

        // Get staff profile for additional info
        $staffProfile = $this->firebase->get("Users/Teachers/{$this->school_name}/{$staffId}");

        $this->json_success([
            'run' => [
                'id'     => $runId,
                'month'  => $run['month'] ?? '',
                'year'   => $run['year'] ?? '',
                'status' => $run['status'] ?? '',
            ],
            'slip' => array_merge($slip, ['staff_id' => $staffId]),
            'staff_profile' => is_array($staffProfile) ? [
                'name'          => $staffProfile['Name'] ?? '',
                'email'         => $staffProfile['Email'] ?? '',
                'phone'         => $staffProfile['Phone'] ?? '',
                'department'    => $staffProfile['Department'] ?? '',
                'position'      => $staffProfile['Position'] ?? '',
                'qualification' => $staffProfile['Qualification'] ?? '',
            ] : null,
        ]);
    }

    /**
     * GET — Staff self-service: view own payslips.
     * Any logged-in staff (VIEW_ROLES) can see their own payslips only.
     * Optional ?run_id=PR0001 for a single slip; otherwise returns all.
     */
    public function my_payslips()
    {
        $this->_require_role(self::VIEW_ROLES, 'my_payslips');

        $staffId = $this->admin_id;
        if (empty($staffId)) {
            $this->json_error('Could not determine your staff ID.');
        }
        $staffId = $this->safe_path_segment($staffId, 'staff_id');

        $runId = trim($this->input->get('run_id') ?? '');

        // If specific run requested, return single slip
        if ($runId !== '') {
            $runId = $this->safe_path_segment($runId, 'run_id');
            $run   = $this->firebase->get($this->_payroll_runs($runId));
            if (!is_array($run)) {
                $this->json_error('Payroll run not found.');
            }
            // Only show finalized/paid runs to staff
            if (!in_array($run['status'] ?? '', ['Finalized', 'Paid'], true)) {
                $this->json_error('This payroll run is not yet finalized.');
            }
            $slip = $this->firebase->get($this->_payroll_slips($runId, $staffId));
            if (!is_array($slip)) {
                $this->json_error('No payslip found for you in this run.');
            }
            $this->json_success([
                'run'  => ['id' => $runId, 'month' => $run['month'] ?? '', 'year' => $run['year'] ?? '', 'status' => $run['status'] ?? ''],
                'slip' => array_merge($slip, ['staff_id' => $staffId]),
            ]);
        }

        // Otherwise list all runs and return own slips from finalized/paid ones
        $allRuns = $this->firebase->get($this->_payroll_runs());
        if (!is_array($allRuns)) {
            $this->json_success(['payslips' => []]);
        }

        $result = [];
        foreach ($allRuns as $rid => $run) {
            if (!is_array($run)) continue;
            $status = $run['status'] ?? '';
            if (!in_array($status, ['Finalized', 'Paid'], true)) continue;

            $slip = $this->firebase->get($this->_payroll_slips($rid, $staffId));
            if (!is_array($slip)) continue;

            $result[] = [
                'run_id' => $rid,
                'month'  => $run['month'] ?? '',
                'year'   => $run['year'] ?? '',
                'status' => $status,
                'net_pay'          => $slip['net_pay'] ?? 0,
                'gross'            => $slip['gross'] ?? 0,
                'basic'            => $slip['basic'] ?? 0,
                'total_deductions' => $slip['total_deductions'] ?? 0,
                'days_worked'      => $slip['days_worked'] ?? '',
                'days_absent'      => $slip['days_absent'] ?? 0,
                'lwp_days'         => $slip['lwp_days'] ?? 0,
                'slip'             => $slip,
            ];
        }

        // Sort by year desc, month desc
        $monthOrder = array_flip(['January','February','March','April','May','June','July','August','September','October','November','December']);
        usort($result, function ($a, $b) use ($monthOrder) {
            $yc = ($b['year'] ?? 0) <=> ($a['year'] ?? 0);
            if ($yc !== 0) return $yc;
            return ($monthOrder[$b['month']] ?? 0) <=> ($monthOrder[$a['month']] ?? 0);
        });

        $this->json_success(['payslips' => $result]);
    }

    /**
     * POST — Delete a payroll run (Draft only).
     * Params: run_id
     */
    public function delete_payroll_run()
    {
        $this->_require_role(self::ADMIN_ROLES, 'delete_payroll_run');

        $runId = $this->safe_path_segment(trim($this->input->post('run_id') ?? ''), 'run_id');

        $run = $this->firebase->get($this->_payroll_runs($runId));
        if (!is_array($run)) {
            $this->json_error('Payroll run not found.');
        }
        if (($run['status'] ?? '') !== 'Draft') {
            $this->json_error('Only Draft payroll runs can be deleted.');
        }

        // Check month lock
        $this->_check_payroll_lock($run['month'] ?? '', $run['year'] ?? '');

        // Audit log before deletion (run data will be gone after)
        $this->_log_payroll('deleted', $runId, [
            'month' => $run['month'] ?? '', 'year' => $run['year'] ?? '',
            'total_net' => $run['total_net'] ?? 0,
        ]);

        // Delete slips
        $this->firebase->delete($this->_payroll_slips($runId));

        // Reverse the expense journal entry via proper reversal entry (audit-safe)
        $expenseJournalId = $run['expense_journal_id'] ?? '';
        if ($expenseJournalId !== '') {
            $this->_create_reversal_journal($expenseJournalId, "Payroll run {$runId} deleted");
        }

        // Delete run
        $this->firebase->delete($this->_payroll_runs($runId));

        $this->json_success(['message' => 'Payroll run and associated slips deleted.']);
    }

    // ====================================================================
    //  APPRAISALS
    // ====================================================================

    /**
     * GET — List appraisals. Optional filters: ?staff_id=XXX&status=Draft
     */
    public function get_appraisals()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_appraisals');

        $filterStaffId = trim($this->input->get('staff_id') ?? '');
        $filterStatus  = trim($this->input->get('status') ?? '');
        $filterPeriod  = trim($this->input->get('period') ?? '');

        $appraisals = $this->firebase->get($this->_appraisals());
        $list = [];
        if (is_array($appraisals)) {
            foreach ($appraisals as $id => $a) {
                if ($filterStaffId !== '' && isset($a['staff_id']) && $a['staff_id'] !== $filterStaffId) {
                    continue;
                }
                if ($filterStatus !== '' && isset($a['status']) && $a['status'] !== $filterStatus) {
                    continue;
                }
                if ($filterPeriod !== '' && isset($a['period']) && $a['period'] !== $filterPeriod) {
                    continue;
                }
                $a['id'] = $id;
                $list[] = $a;
            }
        }

        // Sort by created_at descending
        usort($list, function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        $this->json_success(['appraisals' => $list]);
    }

    /**
     * POST — Create or update an appraisal (Draft state only for updates).
     */
    public function save_appraisal()
    {
        $this->_require_role(self::HR_ROLES, 'save_appraisal');

        $id               = trim($this->input->post('id') ?? '');
        $staffId          = $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id');
        $period           = trim($this->input->post('period') ?? '');
        $appraisalType    = trim($this->input->post('appraisal_type') ?? 'Annual');
        // Accept both field-name conventions (view sends teaching/behavior/innovation)
        $teachingQuality  = (float) ($this->input->post('teaching') ?? $this->input->post('teaching_quality') ?? 0);
        $punctuality      = (float) ($this->input->post('punctuality') ?? 0);
        $studentFeedback  = (float) ($this->input->post('behavior') ?? $this->input->post('student_feedback') ?? 0);
        $initiative       = (float) ($this->input->post('innovation') ?? $this->input->post('initiative') ?? 0);
        $teamwork         = (float) ($this->input->post('teamwork') ?? 0);
        $overallRating    = (float) ($this->input->post('overall_rating') ?? 0);
        $strengths        = trim($this->input->post('strengths') ?? '');
        $areasImprovement = trim($this->input->post('areas_of_improvement') ?? '');
        $recommendation   = trim($this->input->post('recommendation') ?? 'none');
        $comments         = trim($this->input->post('comments') ?? '');
        $goals            = trim($this->input->post('goals') ?? '');

        if ($period === '') {
            $this->json_error('Appraisal period is required (e.g., "2025-26 Term 1").');
        }

        // Validate appraisal type
        $validTypes = ['Annual', 'Probation', 'Promotion', 'Mid-Year', 'Special'];
        if (!in_array($appraisalType, $validTypes, true)) {
            $this->json_error('Invalid appraisal type.');
        }

        // Validate ratings (0-10 scale)
        $ratings = [$teachingQuality, $punctuality, $studentFeedback, $initiative, $teamwork, $overallRating];
        foreach ($ratings as $r) {
            if ($r < 0 || $r > 10) {
                $this->json_error('Ratings must be between 0 and 10.');
            }
        }

        // Verify staff exists
        $staffProfile = $this->firebase->get("Users/Teachers/{$this->school_name}/{$staffId}");
        if (!is_array($staffProfile)) {
            $this->json_error('Staff member not found.');
        }
        $staffName  = $staffProfile['Name'] ?? $staffId;
        $department = $staffProfile['Department'] ?? '';

        if ($department === '') {
            $this->json_error('Selected staff has no department assigned. Please update staff profile first.');
        }

        $now   = date('c');
        $isNew = ($id === '');

        if ($isNew) {
            $id = $this->_next_id('APR', 'Appraisal');
        } else {
            // Only allow editing Draft appraisals
            $existing = $this->firebase->get($this->_appraisals($id));
            if (!is_array($existing)) {
                $this->json_error('Appraisal not found.');
            }
            if (($existing['status'] ?? '') !== 'Draft') {
                $this->json_error('Only Draft appraisals can be edited.');
            }
        }

        $data = [
            'staff_id'              => $staffId,
            'staff_name'            => $staffName,
            'department'            => $department,
            'appraisal_type'        => $appraisalType,
            'period'                => $period,
            'reviewer_id'           => $this->input->post('reviewer_id') ? $this->safe_path_segment(trim($this->input->post('reviewer_id')), 'reviewer_id') : $this->admin_id,
            'reviewer_name'         => $this->admin_name,
            'teaching_quality'      => $teachingQuality,
            'punctuality'           => $punctuality,
            'student_feedback'      => $studentFeedback,
            'initiative'            => $initiative,
            'teamwork'              => $teamwork,
            'overall_rating'        => $overallRating,
            'strengths'             => $strengths,
            'areas_of_improvement'  => $areasImprovement,
            'recommendation'        => $recommendation,
            'comments'              => $comments,
            'goals'                 => $goals,
            'status'                => 'Draft',
            'updated_at'            => $now,
            'updated_by'            => $this->admin_id,
        ];
        if ($isNew) {
            $data['created_at'] = $now;
            $data['created_by'] = $this->admin_id;
        }

        $this->firebase->set($this->_appraisals($id), $data);
        $this->json_success(['id' => $id, 'message' => $isNew ? 'Appraisal created.' : 'Appraisal updated.']);
    }

    /**
     * POST — Submit a draft appraisal for review.
     * Params: id
     */
    public function submit_appraisal()
    {
        $this->_require_role(self::HR_ROLES, 'submit_appraisal');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        $appraisal = $this->firebase->get($this->_appraisals($id));
        if (!is_array($appraisal)) {
            $this->json_error('Appraisal not found.');
        }
        if (($appraisal['status'] ?? '') !== 'Draft') {
            $this->json_error('Only Draft appraisals can be submitted.');
        }

        // Validate that key fields are filled
        $overallRating = (float) ($appraisal['overall_rating'] ?? 0);
        if ($overallRating <= 0) {
            $this->json_error('Overall rating must be set before submitting.');
        }

        $this->firebase->update($this->_appraisals($id), [
            'status'     => 'Submitted',
            'updated_at' => date('c'),
        ]);

        $this->json_success(['message' => 'Appraisal submitted for review.']);
    }

    /**
     * POST — Mark a submitted appraisal as reviewed (final).
     * Params: id, comments (optional additional reviewer comments)
     */
    public function review_appraisal()
    {
        $this->_require_role(self::HR_ROLES, 'review_appraisal');

        $id       = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');
        $comments = trim($this->input->post('comments') ?? '');

        $appraisal = $this->firebase->get($this->_appraisals($id));
        if (!is_array($appraisal)) {
            $this->json_error('Appraisal not found.');
        }
        if (($appraisal['status'] ?? '') !== 'Submitted') {
            $this->json_error('Only Submitted appraisals can be reviewed.');
        }

        $update = [
            'status'     => 'Reviewed',
            'updated_at' => date('c'),
        ];
        if ($comments !== '') {
            $existingComments = $appraisal['comments'] ?? '';
            $update['comments'] = $existingComments . "\n[Review: " . date('Y-m-d') . '] ' . $comments;
        }

        $this->firebase->update($this->_appraisals($id), $update);
        $this->json_success(['message' => 'Appraisal marked as reviewed.']);
    }

    /**
     * POST — Delete an appraisal (only Draft).
     * Params: id
     */
    public function delete_appraisal()
    {
        $this->_require_role(self::HR_ROLES, 'delete_appraisal');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        $appraisal = $this->firebase->get($this->_appraisals($id));
        if (!is_array($appraisal)) {
            $this->json_error('Appraisal not found.');
        }
        if (($appraisal['status'] ?? '') !== 'Draft') {
            $this->json_error('Only Draft appraisals can be deleted.');
        }

        $this->firebase->delete($this->_appraisals($id));
        $this->json_success(['message' => 'Appraisal deleted.']);
    }

    // ====================================================================
    //  UTILITY
    // ====================================================================

    /**
     * GET — Returns staff from session roster for dropdowns.
     * Returns: [{id, name, department, position, email, phone}]
     */
    public function get_staff_list()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_staff_list');

        // Get roster
        $roster = $this->firebase->get("Schools/{$this->school_name}/{$this->session_year}/Teachers");
        if (!is_array($roster) || empty($roster)) {
            $this->json_success(['staff' => []]);
            return;
        }

        // Get profiles
        $profiles = $this->firebase->get("Users/Teachers/{$this->school_name}");
        $list = [];

        foreach ($roster as $staffId => $rosterData) {
            $profile = (is_array($profiles) && isset($profiles[$staffId]) && is_array($profiles[$staffId]))
                ? $profiles[$staffId]
                : [];

            $list[] = [
                'id'           => $staffId,
                'name'         => $profile['Name'] ?? $staffId,
                'department'   => $profile['Department'] ?? '',
                'position'     => $profile['Position'] ?? '',
                'email'        => $profile['Email'] ?? '',
                'phone'        => $profile['Phone'] ?? '',
                'staff_roles'  => $profile['staff_roles'] ?? [],
                'primary_role' => $profile['primary_role'] ?? '',
            ];
        }

        // Sort alphabetically by name
        usort($list, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $this->json_success(['staff' => $list]);
    }

    /**
     * POST — Backfill salary structures for all staff who have registration
     * salary data but no Salary_Structures entry.
     *
     * Safe to run multiple times — skips existing structures.
     * Uses the same allocation logic as Staff::_sync_salary_structure().
     */
    public function backfill_salary_structures()
    {
        $this->_require_role(self::ADMIN_ROLES, 'backfill_salary_structures');

        $school  = $this->school_name;
        $session = $this->session_year;

        // Get all staff profiles
        $profiles = $this->firebase->get("Users/Teachers/{$school}");
        if (!is_array($profiles)) {
            $this->json_success(['message' => 'No staff found.', 'created' => 0, 'skipped' => 0]);
            return;
        }

        // Get existing structures (single read)
        $existing = $this->firebase->get($this->_salary());
        if (!is_array($existing)) $existing = [];

        // Get roster for current session
        $roster = $this->firebase->get("Schools/{$school}/{$session}/Teachers");
        if (!is_array($roster)) $roster = [];

        $created = [];
        $skipped = [];
        $noSalary = [];
        $now = date('c');

        // Default split ratios (same as Staff.php)
        $hraPct = 40; $daPct = 10;

        foreach ($roster as $staffId => $_) {
            // Already has a structure → skip
            if (isset($existing[$staffId]) && is_array($existing[$staffId])) {
                $skipped[] = $staffId;
                continue;
            }

            $profile = $profiles[$staffId] ?? null;
            if (!is_array($profile)) { $skipped[] = $staffId; continue; }

            $salDet = $profile['salaryDetails'] ?? [];
            $basic  = (float) ($salDet['basicSalary'] ?? 0);
            $allow  = (float) ($salDet['Allowances'] ?? 0);

            if ($basic <= 0) { $noSalary[] = $staffId; continue; }

            // Compute breakdown
            $hra = round($basic * ($hraPct / 100), 2);
            $da  = round($basic * ($daPct / 100), 2);
            $remaining = max(0, $allow - $hra - $da);
            if ($allow < ($hra + $da)) {
                $hra = round($allow * 0.6, 2);
                $da  = round($allow * 0.3, 2);
                $remaining = max(0, $allow - $hra - $da);
            }
            $ta      = round($remaining * 0.30, 2);
            $medical = round($remaining * 0.25, 2);
            $other   = round($remaining - $ta - $medical, 2);

            $structure = [
                'basic' => $basic, 'hra' => $hra, 'da' => $da, 'ta' => $ta,
                'medical' => $medical, 'other_allowances' => $other,
                'pf_employee' => 12, 'pf_employer' => 12,
                'esi_employee' => 0.75, 'esi_employer' => 3.25,
                'professional_tax' => 200, 'tds' => 0, 'other_deductions' => 0,
                'source' => 'registration', 'created_at' => $now, 'updated_at' => $now,
                'updated_by' => $this->admin_name ?? 'system', 'last_synced_at' => $now,
            ];

            $this->firebase->set($this->_salary($staffId), $structure);
            $created[] = $staffId;
        }

        log_message('info',
            "backfill_salary_structures: school=[{$school}] "
            . "created=" . count($created) . " skipped=" . count($skipped)
            . " no_salary=" . count($noSalary)
        );

        $this->json_success([
            'message'   => count($created) > 0
                ? count($created) . ' salary structure(s) created.'
                : 'All staff already have salary structures.',
            'created'   => count($created),
            'skipped'   => count($skipped),
            'no_salary' => count($noSalary),
        ]);
    }

    // ====================================================================
    //  REPORTS
    // ====================================================================

    /**
     * GET — Get HR summary report for export/print.
     * ?type=staff|leaves|payroll|departments
     */
    public function get_report()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_report');

        $type = trim($this->input->get('type') ?? 'staff');

        switch ($type) {
            case 'staff':
                return $this->_report_staff();
            case 'leaves':
                return $this->_report_leaves();
            case 'payroll':
                return $this->_report_payroll();
            case 'departments':
                return $this->_report_departments();
            default:
                $this->json_error('Invalid report type. Use: staff, leaves, payroll, departments.');
        }
    }

    /**
     * Staff report: roster with departments and salary info.
     */
    private function _report_staff()
    {
        $roster   = $this->firebase->get("Schools/{$this->school_name}/{$this->session_year}/Teachers");
        $profiles = $this->firebase->get("Users/Teachers/{$this->school_name}");
        $salaries = $this->firebase->get($this->_salary());

        $list = [];
        if (is_array($roster)) {
            foreach ($roster as $staffId => $rd) {
                $p = (is_array($profiles) && isset($profiles[$staffId]) && is_array($profiles[$staffId]))
                    ? $profiles[$staffId]
                    : [];
                $s = (is_array($salaries) && isset($salaries[$staffId]) && is_array($salaries[$staffId]))
                    ? $salaries[$staffId]
                    : [];

                $gross = 0;
                if (!empty($s)) {
                    $gross = (float) ($s['basic'] ?? 0)
                        + (float) ($s['hra'] ?? 0)
                        + (float) ($s['da'] ?? 0)
                        + (float) ($s['ta'] ?? 0)
                        + (float) ($s['medical'] ?? 0)
                        + (float) ($s['other_allowances'] ?? 0);
                }

                $list[] = [
                    'staff_id'      => $staffId,
                    'name'          => $p['Name'] ?? $staffId,
                    'department'    => $p['Department'] ?? '',
                    'position'      => $p['Position'] ?? '',
                    'phone'         => $p['Phone'] ?? '',
                    'email'         => $p['Email'] ?? '',
                    'qualification' => $p['Qualification'] ?? '',
                    'gross_salary'  => round($gross, 2),
                    'has_salary'    => !empty($s),
                ];
            }
        }

        usort($list, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $this->json_success(['report' => 'staff', 'data' => $list, 'total' => count($list)]);
    }

    /**
     * Leave report: summary of all leave requests and balances.
     */
    private function _report_leaves()
    {
        $year     = trim($this->input->get('year') ?? date('Y'));
        $requests = $this->firebase->get($this->_leave_req());
        $balances = $this->firebase->get($this->_leave_bal($year));

        $summary = [
            'total_requests' => 0,
            'pending'        => 0,
            'approved'       => 0,
            'rejected'       => 0,
            'cancelled'      => 0,
        ];

        $byStaff = [];
        if (is_array($requests)) {
            foreach ($requests as $r) {
                $summary['total_requests']++;
                $status = strtolower($r['status'] ?? '');
                if (isset($summary[$status])) {
                    $summary[$status]++;
                }

                $sid = $r['staff_id'] ?? '';
                if ($sid !== '') {
                    if (!isset($byStaff[$sid])) {
                        $byStaff[$sid] = [
                            'staff_name'    => $r['staff_name'] ?? $sid,
                            'total_days'    => 0,
                            'approved_days' => 0,
                        ];
                    }
                    $days = (int) ($r['days'] ?? 0);
                    $byStaff[$sid]['total_days'] += $days;
                    if (($r['status'] ?? '') === 'Approved') {
                        $byStaff[$sid]['approved_days'] += $days;
                    }
                }
            }
        }

        $this->json_success([
            'report'   => 'leaves',
            'year'     => $year,
            'summary'  => $summary,
            'by_staff' => array_values($byStaff),
            'balances' => is_array($balances) ? $balances : [],
        ]);
    }

    /**
     * Payroll report: all runs for this session.
     */
    private function _report_payroll()
    {
        $runs = $this->firebase->get($this->_payroll_runs());
        $list = [];
        $totalPaid = 0;

        if (is_array($runs)) {
            foreach ($runs as $id => $r) {
                $r['id'] = $id;
                $list[] = $r;
                if (($r['status'] ?? '') === 'Paid') {
                    $totalPaid += (float) ($r['total_net'] ?? 0);
                }
            }
        }

        usort($list, function ($a, $b) {
            return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
        });

        $this->json_success([
            'report'     => 'payroll',
            'session'    => $this->session_year,
            'runs'       => $list,
            'total_paid' => round($totalPaid, 2),
            'run_count'  => count($list),
        ]);
    }

    /**
     * Departments report: departments with staff counts.
     */
    private function _report_departments()
    {
        $depts    = $this->firebase->get($this->_dept());
        $profiles = $this->firebase->get("Users/Teachers/{$this->school_name}");
        $roster   = $this->firebase->get("Schools/{$this->school_name}/{$this->session_year}/Teachers");

        // Count staff per department
        $countByDept = [];
        if (is_array($roster) && is_array($profiles)) {
            foreach ($roster as $sid => $rd) {
                $dept = (isset($profiles[$sid]) && is_array($profiles[$sid]))
                    ? ($profiles[$sid]['Department'] ?? 'Unassigned')
                    : 'Unassigned';
                $countByDept[$dept] = ($countByDept[$dept] ?? 0) + 1;
            }
        }

        $list = [];
        $assignedDeptNames = [];
        if (is_array($depts)) {
            foreach ($depts as $id => $d) {
                $dName = $d['name'] ?? '';
                $d['id']          = $id;
                $d['staff_count'] = $countByDept[$dName] ?? 0;
                $list[] = $d;
                $assignedDeptNames[] = $dName;
            }
        }

        // Add "Unassigned" or any departments not in the formal list
        foreach ($countByDept as $dName => $cnt) {
            if (!in_array($dName, $assignedDeptNames, true)) {
                $list[] = [
                    'id'          => '',
                    'name'        => $dName,
                    'staff_count' => $cnt,
                    'status'      => 'N/A',
                ];
            }
        }

        $this->json_success(['report' => 'departments', 'data' => $list]);
    }
}
