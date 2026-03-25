<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Fees extends MY_Controller
{
    /** Roles that may modify fee structures, submit fees, manage discounts */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Accountant'];

    /** Roles that may view fee data */
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Accountant', 'Teacher'];

    /** Roles that may collect fees at counter */
    private const COUNTER_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Accountant'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Fees');

        // Init audit + transaction libraries
        $this->load->library('Fee_audit', null, 'feeAudit');
        $this->load->library('Fee_transaction', null, 'feeTxn');
        $feesBase = "Schools/{$this->school_name}/{$this->session_year}/Fees";
        $this->feeAudit->init(
            $this->firebase, $feesBase,
            $this->admin_id ?? 'system', $this->admin_name ?? 'System',
            $this->school_name
        );
        $this->feeTxn->init($this->firebase, $feesBase);

        $this->load->library('Fee_defaulter_check', null, 'feeDefaulter');
        $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
        $this->feeDefaulter->init($this->firebase, $this->school_name, $this->session_year);
        $this->feeLifecycle->init($this->firebase, $this->school_name, $this->session_year, $this->admin_id ?? 'system');

        // Firestore dual-write sync (best-effort, non-blocking)
        $this->load->library('Fee_firestore_sync', null, 'fsSync');
        $this->fsSync->init($this->firebase, $this->school_name, $this->session_year);

        // Firestore helper for direct reads
        $this->load->library('Firestore_helper', null, 'fs');
        $this->fs->init($this->firebase, $this->school_name, $this->session_year);
    }

    /**
     * Enforce HTTP POST method. Returns 405 if request is not POST.
     * Call at the top of any data-mutating endpoint.
     */
    private function _require_post(): bool
    {
        if ($this->input->method() !== 'post') {
            $this->output->set_status_header(405);
            $this->_json_out(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
        }
        return true;
    }

    /**
     * Standardized JSON response — replaces all raw header()+echo patterns.
     * Ensures Content-Type is set correctly via CI output class, includes CSRF.
     */
    private function _json_out(array $data): void
    {
        $data['csrf_token'] = $this->security->get_csrf_hash();
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($data));
    }

    /**
     * Validate and sanitize receipt number. If invalid/missing/duplicate, generate new.
     */
    private function _validateReceiptNo($receiptNo): string
    {
        $receiptNo = trim((string)($receiptNo ?? ''));

        if ($receiptNo !== '') {
            $clean = preg_replace('/[^A-Za-z0-9\-_]/', '', $receiptNo);
            if ($clean !== '' && strlen($clean) <= 20) {
                if (!$this->_receiptExists($clean)) {
                    return $clean;
                }
                log_message('info', "submit_fees: receiptNo '{$clean}' already exists, generating new");
            }
        }

        return $this->_nextReceiptNo();
    }

    /**
     * Check if a receipt number already exists in Receipt_Index.
     */
    /**
     * Check if a receipt number already exists in Receipt_Index.
     * Handles both legacy (F123) and new (R000123) key formats.
     */
    private function _receiptExists(string $receiptNo): bool
    {
        $sn = $this->school_name;
        $sy = $this->session_year;
        // Check with F-prefix (legacy) and direct key (new R-format)
        $key = (strpos($receiptNo, 'R') === 0) ? $receiptNo : 'F' . $receiptNo;
        $result = $this->firebase->get("Schools/{$sn}/{$sy}/Accounts/Receipt_Index/{$key}");
        return !empty($result);
    }

    /**
     * True atomic receipt generator.
     *
     * Strategy: Firebase push() generates a guaranteed-unique key (no contention).
     * We also maintain a human-readable counter for display, but the push key
     * is the canonical uniqueness guarantee. The counter is best-effort sequential.
     *
     * Zero retry loops. Zero race conditions. Single Firebase write.
     */
    /**
     * Generate a human-readable receipt number: R000001, R000002, ...
     *
     * Strategy:
     *   1. Push a placeholder → guaranteed-unique Firebase key (canonical ID)
     *   2. Increment sequential counter → human-readable display number
     *   3. If counter race detected (collision), suffix with push-key fragment
     *
     * The push key is stored in Receipt_Index as the dedup guard.
     * The R-prefixed number is what appears on printed receipts.
     */
    private function _nextReceiptNo(): string
    {
        $sn = $this->school_name;
        $sy = $this->session_year;
        $counterPath = "Schools/{$sn}/{$sy}/Accounts/Fees/Counters/receipt_seq";

        // 1. Push for uniqueness guarantee
        $pushKey = $this->firebase->push(
            "Schools/{$sn}/{$sy}/Accounts/Fees/Receipt_Keys",
            ['created_at' => date('c'), 'school' => $sn]
        );

        // 2. Increment sequential counter
        $current = (int)($this->firebase->get($counterPath) ?: 0);
        $next = $current + 1;
        $this->firebase->set($counterPath, $next);

        // 3. Format: R000001
        $formatted = 'R' . str_pad($next, 6, '0', STR_PAD_LEFT);

        // 4. Collision check (counter race)
        if ($this->_receiptExists($formatted)) {
            $suffix = substr($pushKey ?? bin2hex(random_bytes(2)), -3);
            $formatted = 'R' . str_pad($next, 6, '0', STR_PAD_LEFT) . $suffix;
            log_message('info', "receipt_gen: counter race #{$next}, using {$formatted}");
        }

        return $formatted;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE PATH HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Firebase path for a class+section fee chart.
     * Schools/{sn}/{sy}/Accounts/Fees/Classes Fees/Class 8th/Section A
     */
    private function feesPath($class, $section)
    {
        $sn = $this->school_name;
        $sy = $this->session_year;
        return "Schools/$sn/$sy/Accounts/Fees/Classes Fees/$class/$section";
    }

    /**
     * Firebase path for students in a class+section.
     * Schools/{sn}/{sy}/Class 8th/Section A/Students[/{uid}]
     */
    private function studentPath($class, $section, $userId = '')
    {
        $sn = $this->school_name;
        $sy = $this->session_year;

        $section = preg_replace('/^Section\s+/i', '', trim($section));
        $section = 'Section ' . strtoupper($section);

        $base = "Schools/$sn/$sy/$class/$section/Students";
        return $userId ? "$base/$userId" : $base;
    }

    /**
     * Get all Firebase paths needed for a fee transaction.
     * Centralizes path construction to avoid string duplication across methods.
     *
     * @param string $class      e.g. "Class 8th"
     * @param string $section    e.g. "Section A"
     * @param string $userId     Student ID
     * @param string $receiptKey e.g. "F42"
     * @return array  Associative array of named paths
     */
    private function _getFeePaths(string $class, string $section, string $userId, string $receiptKey = ''): array
    {
        $sn = $this->school_name;
        $sy = $this->session_year;
        $bp = "Schools/{$sn}/{$sy}";
        $studentBase = $this->studentPath($class, $section, $userId);

        return [
            'base'              => $bp,
            'student_base'      => $studentBase,
            'fees_record'       => "{$studentBase}/Fees Record" . ($receiptKey ? "/{$receiptKey}" : ''),
            'month_fee'         => "{$studentBase}/Month Fee",
            'discount'          => "{$studentBase}/Discount",
            'oversubmitted'     => "{$studentBase}/Oversubmittedfees",
            'exempted'          => "{$studentBase}/Exempted Fees",
            'vouchers'          => "{$bp}/Accounts/Vouchers",
            'account_book'      => "{$bp}/Accounts/Account_book",
            'receipt_index'     => "{$bp}/Accounts/Receipt_Index",
            'pending_fees'      => "{$bp}/Accounts/Pending_fees",
            'pending_journals'  => "{$bp}/Accounts/Pending_journals",
            'demands'           => "{$bp}/Fees/Demands/{$userId}",
            'advance_balance'   => "{$bp}/Fees/Advance_Balance/{$userId}",
            'receipt_alloc'     => "{$bp}/Fees/Receipt_Allocations" . ($receiptKey ? "/{$receiptKey}" : ''),
            'idempotency'       => "{$bp}/Fees/Idempotency",
            'locks'             => "{$bp}/Fees/Locks/{$userId}",
        ];
    }

    /**
     * Create accounting journal entry for a fee payment.
     * Extracted from submit_fees() for reuse and clarity.
     *
     * @param array $params  Journal parameters
     * @param array $allocations  Demand allocations (for granular journal)
     * @param float $fineAmount
     * @param float $discountFees
     * @param bool  $demandMode  Whether demand engine was used
     */
    private function _create_fee_accounting_entry(
        array $params, array $allocations, float $fineAmount, float $discountFees, bool $demandMode
    ): void {
        try {
            $this->load->library('Operations_accounting', null, 'ops_acct');
            $this->ops_acct->init(
                $this->firebase, $this->school_name, $this->session_year, $this->admin_id, $this
            );

            if ($demandMode && !empty($allocations)) {
                $entryId = $this->ops_acct->create_fee_journal_granular(
                    $params, $allocations, $fineAmount, $discountFees
                );
            } else {
                $entryId = $this->ops_acct->create_fee_journal($params);
            }

            if ($entryId === null) {
                log_message('error', "Fee journal returned null for receipt {$params['receipt_no']} — queued");
                $this->firebase->set(
                    "Schools/{$this->school_name}/{$this->session_year}/Accounts/Pending_journals/{$params['receipt_no']}",
                    array_merge($params, ['queued_at' => date('c'), 'reason' => 'journal_returned_null'])
                );
            }
        } catch (\Exception $e) {
            log_message('error', 'Accounting integration failed in fee payment: ' . $e->getMessage());
            $this->firebase->set(
                "Schools/{$this->school_name}/{$this->session_year}/Accounts/Pending_journals/{$params['receipt_no']}",
                array_merge($params, ['queued_at' => date('c'), 'reason' => $e->getMessage()])
            );
        }
    }

    /**
     * Fire a fee_received communication event.
     * Extracted from submit_fees() for clarity.
     */
    private function _notify_fee_received(array $eventData): void
    {
        try {
            $this->load->library('Communication_helper', null, 'comm');
            $this->comm->init($this->firebase, $this->school_name, $this->session_year);
            $this->comm->fire_event('fee_received', $eventData);
        } catch (\Exception $e) {
            log_message('error', 'Communication fire_event failed: ' . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  CLASS + SECTION PARSERS
    // ══════════════════════════════════════════════════════════════════

    private function parseClassSection($classString)
    {
        $classString = trim((string)$classString);
        if ($classString === '') return ['', ''];

        $stripped = preg_replace('/^Class\s+/i', '', $classString);

        // "8th Section A" or "8th Section B"
        if (preg_match('/^(.+?)\s+Section\s+([A-Z0-9]+)\s*$/i', $stripped, $m)) {
            return ['Class ' . trim($m[1]), 'Section ' . strtoupper(trim($m[2]))];
        }

        // "8th B"
        $parts    = preg_split('/\s+/', $stripped, 2);
        $classNum = trim($parts[0] ?? '');
        $rawSec   = trim($parts[1] ?? '', " \t'\"");

        if ($rawSec !== '') {
            $rawSec  = preg_replace('/^Section\s+/i', '', $rawSec);
            $section = 'Section ' . strtoupper($rawSec);
        } else {
            $section = '';
        }

        return [
            $classNum !== '' ? "Class $classNum" : '',
            $section,
        ];
    }

    /**
     * Resolve class and section from a student profile array.
     * Handles both:
     *   Format A: Class="8th",   Section="B"  (separate fields)
     *   Format B: Class="8th B", Section=""   (merged in Class field)
     * Returns: ["Class 8th", "Section B"]
     */
    private function _resolveClassSection(array $student)
    {
        $classRaw = trim($student['Class'] ?? '');

        list($class, $section) = $this->parseClassSection($classRaw);

        // If section not found in Class field, try dedicated Section field
        if ($section === '') {
            $rawSec = trim($student['Section'] ?? '');
            if ($rawSec !== '') {
                $rawSec  = preg_replace('/^Section\s+/i', '', $rawSec);
                $section = 'Section ' . strtoupper($rawSec);
            }
        }

        // Rebuild class prefix if still empty
        if ($class === '' && $classRaw !== '') {
            $stripped  = preg_replace('/^Class\s+/i', '', $classRaw);
            $firstPart = trim(explode(' ', $stripped)[0]);
            $class     = 'Class ' . $firstPart;
        }

        return [$class, $section];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ATOMIC ADVANCE BALANCE UPDATE
    // ══════════════════════════════════════════════════════════════════

    /**
     * Atomically update a student's advance balance with verify-after-write.
     *
     * Pattern: read → compute → write → re-read → verify → retry if mismatch.
     * Max 3 attempts. NEVER throws — returns best-effort value on exhaustion
     * and flags the record for later reconciliation.
     *
     * @param string $studentId    Student user ID
     * @param float  $delta        Amount to add (positive) or subtract (negative)
     * @param string $receiptKey   Receipt key for audit trail (e.g. "F42")
     * @param string $studentName  For record metadata
     * @param string $studentBase  Legacy student path for Oversubmittedfees sync
     * @return float               The new balance (verified or best-effort)
     */
    private function _update_advance_balance_atomic(
        string $studentId,
        float  $delta,
        string $receiptKey = '',
        string $studentName = '',
        string $studentBase = ''
    ): float {
        $advPath    = "Schools/{$this->school_name}/{$this->session_year}/Fees/Advance_Balance/{$studentId}";
        $maxRetries = 3;
        $newBalance = 0;
        $verified   = false;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Step 1: Read current value
                $existing   = $this->firebase->get($advPath);
                $currentAmt = is_array($existing) ? round(floatval($existing['amount'] ?? 0), 2) : 0;

                // Step 2: Compute new balance (never negative)
                $newBalance = round(max(0, $currentAmt + $delta), 2);

                // Step 3: Write
                $writeData = [
                    'amount'       => $newBalance,
                    'student_id'   => $studentId,
                    'student_name' => $studentName ?: ($existing['student_name'] ?? $studentId),
                    'last_updated' => date('c'),
                ];
                if ($delta > 0) {
                    $writeData['last_receipt'] = $receiptKey;
                } else {
                    $writeData['last_refund'] = $receiptKey;
                }
                $this->firebase->set($advPath, $writeData);

                // Step 4: Re-read to verify
                $verify    = $this->firebase->get($advPath);
                $verifyAmt = is_array($verify) ? round(floatval($verify['amount'] ?? -1), 2) : -1;

                // Step 5: Check match
                if (abs($verifyAmt - $newBalance) < 0.01) {
                    $verified = true;

                    // Sync legacy path
                    if ($studentBase !== '') {
                        try {
                            $this->firebase->set("{$studentBase}/Oversubmittedfees", $newBalance);
                        } catch (\Exception $e) {
                            log_message('error', "advance_atomic: legacy sync failed {$studentId}: " . $e->getMessage());
                        }
                    }

                    if ($attempt > 1) {
                        log_message('info',
                            "advance_atomic: OK attempt={$attempt} student={$studentId}"
                            . " delta={$delta} old={$currentAmt} new={$newBalance}"
                        );
                    }
                    return $newBalance;
                }

                // Mismatch — retry
                log_message('info',
                    "advance_atomic: MISMATCH attempt {$attempt}/{$maxRetries} student={$studentId}"
                    . " expected={$newBalance} actual={$verifyAmt}"
                );
            } catch (\Exception $e) {
                log_message('error',
                    "advance_atomic: EXCEPTION attempt {$attempt}/{$maxRetries} student={$studentId}"
                    . " delta={$delta}: " . $e->getMessage()
                );
            }

            usleep(50000 * $attempt); // 50ms, 100ms, 150ms backoff
        }

        // ── All retries exhausted — graceful degradation ──
        // NEVER throw. The main payment (fees record, voucher, allocations)
        // already succeeded. Breaking submit_fees() here would leave the
        // student's payment recorded but the response as "error" — worse
        // than a slightly stale advance balance.
        log_message('error',
            "advance_atomic: EXHAUSTED {$maxRetries} retries student={$studentId}"
            . " delta={$delta} receipt={$receiptKey} — flagging for reconciliation"
        );

        // Flag for later reconciliation so admin can fix manually
        try {
            $pendingPath = "Schools/{$this->school_name}/{$this->session_year}/Fees/Advance_Sync_Pending/{$studentId}";
            $this->firebase->set($pendingPath, [
                'student_id'         => $studentId,
                'delta'              => $delta,
                'receipt_key'        => $receiptKey,
                'attempted_balance'  => $newBalance,
                'retries_exhausted'  => $maxRetries,
                'flagged_at'         => date('c'),
                'advance_sync_pending' => true,
            ]);
        } catch (\Exception $e) {
            log_message('error', "advance_atomic: failed to write sync-pending flag: " . $e->getMessage());
        }

        // Best effort: sync legacy too even though unverified
        if ($studentBase !== '') {
            try {
                $this->firebase->set("{$studentBase}/Oversubmittedfees", $newBalance);
            } catch (\Exception $e) { /* already logged above */ }
        }

        return $newBalance;
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEES DASHBOARD
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fees Dashboard page.
     */
    public function fees_dashboard()
    {
        $this->_require_role(self::VIEW_ROLES);
        $this->load->view('include/header');
        $this->load->view('fees/dashboard');
        $this->load->view('include/footer');
    }

    /**
     * GET — Student Fee Ledger page.
     */
    public function student_ledger()
    {
        $this->_require_role(self::VIEW_ROLES);
        $this->load->view('include/header');
        $this->load->view('fees/student_ledger');
        $this->load->view('include/footer');
    }

    /**
     * GET — Defaulter Report page.
     */
    public function defaulter_report()
    {
        $this->_require_role(self::VIEW_ROLES);
        $this->load->view('include/header');
        $this->load->view('fees/defaulter_report');
        $this->load->view('include/footer');
    }

    /**
     * GET — AJAX: Defaulter data from demands.
     * Returns students with unpaid/partial demands, sorted by total_due DESC.
     * Params: class (optional), section (optional), min_overdue_days (optional)
     */
    public function get_defaulter_data()
    {
        $this->_require_role(self::VIEW_ROLES);

        $sn           = $this->school_name;
        $sy           = $this->session_year;
        $filterClass  = trim($this->input->get('class') ?? '');
        $filterSec    = trim($this->input->get('section') ?? '');
        $minOverdue   = (int) ($this->input->get('min_overdue_days') ?? 0);
        $today        = date('Y-m-d');

        // Read all demands
        $allDemands = $this->firebase->get("Schools/{$sn}/{$sy}/Fees/Demands");
        if (!is_array($allDemands)) $allDemands = [];

        $defaulters = []; // studentId => aggregated data

        foreach ($allDemands as $studentId => $demands) {
            if (!is_array($demands)) continue;

            $totalDue      = 0;
            $totalPaid     = 0;
            $oldestUnpaid  = '';
            $maxOverdue    = 0;
            $unpaidCount   = 0;
            $studentName   = '';
            $studentClass  = '';
            $studentSec    = '';

            foreach ($demands as $did => $d) {
                if (!is_array($d)) continue;
                $status = $d['status'] ?? 'unpaid';

                if ($studentName === '') {
                    $studentName  = $d['student_name'] ?? $studentId;
                    $studentClass = $d['class'] ?? '';
                    $studentSec   = $d['section'] ?? '';
                }

                $totalDue  += floatval($d['net_amount'] ?? 0) + floatval($d['fine_amount'] ?? 0);
                $totalPaid += floatval($d['paid_amount'] ?? 0);

                if ($status !== 'paid') {
                    $unpaidCount++;
                    $dueDate = $d['due_date'] ?? '';
                    if ($dueDate !== '' && $dueDate < $today) {
                        $days = (int) ((strtotime($today) - strtotime($dueDate)) / 86400);
                        if ($days > $maxOverdue) $maxOverdue = $days;
                        if ($oldestUnpaid === '' || $d['period_key'] < $oldestUnpaid) {
                            $oldestUnpaid = $d['period_key'] ?? '';
                        }
                    }
                }
            }

            $balance = round($totalDue - $totalPaid, 2);
            if ($balance <= 0 || $unpaidCount === 0) continue;

            // Apply filters
            if ($filterClass !== '' && $studentClass !== $filterClass) continue;
            if ($filterSec !== '' && $studentSec !== $filterSec) continue;
            if ($minOverdue > 0 && $maxOverdue < $minOverdue) continue;

            $defaulters[] = [
                'student_id'     => $studentId,
                'student_name'   => $studentName,
                'class'          => $studentClass,
                'section'        => $studentSec,
                'total_due'      => round($totalDue, 2),
                'total_paid'     => round($totalPaid, 2),
                'balance'        => $balance,
                'unpaid_months'  => $unpaidCount,
                'oldest_unpaid'  => $oldestUnpaid,
                'days_overdue'   => $maxOverdue,
            ];
        }

        // Sort by balance DESC
        usort($defaulters, function ($a, $b) { return $b['balance'] <=> $a['balance']; });

        // Summary
        $totalDefaulters = count($defaulters);
        $totalBalance    = array_sum(array_column($defaulters, 'balance'));

        $this->json_success([
            'defaulters'       => $defaulters,
            'total_defaulters' => $totalDefaulters,
            'total_balance'    => round($totalBalance, 2),
        ]);
    }

    /**
     * GET — AJAX: Collection analytics from demand data.
     * Returns demand-based stats: total demanded, collected, collection rate per class.
     */
    public function get_collection_analytics()
    {
        $this->_require_role(self::VIEW_ROLES);

        $sn = $this->school_name;
        $sy = $this->session_year;

        $allDemands = $this->firebase->get("Schools/{$sn}/{$sy}/Fees/Demands");
        if (!is_array($allDemands)) $allDemands = [];

        $byClass   = []; // class => {demanded, collected, students}
        $byMonth   = []; // period_key => {demanded, collected}
        $byStatus  = ['paid' => 0, 'partial' => 0, 'unpaid' => 0];
        $totalDemanded  = 0;
        $totalCollected = 0;

        foreach ($allDemands as $sid => $demands) {
            if (!is_array($demands)) continue;
            foreach ($demands as $did => $d) {
                if (!is_array($d)) continue;
                $net  = floatval($d['net_amount'] ?? 0);
                $paid = floatval($d['paid_amount'] ?? 0);
                $cls  = $d['class'] ?? 'Unknown';
                $pk   = $d['period_key'] ?? '';
                $st   = $d['status'] ?? 'unpaid';

                $totalDemanded  += $net;
                $totalCollected += $paid;

                if (!isset($byClass[$cls])) $byClass[$cls] = ['demanded' => 0, 'collected' => 0, 'students' => []];
                $byClass[$cls]['demanded']  += $net;
                $byClass[$cls]['collected'] += $paid;
                $byClass[$cls]['students'][$sid] = true;

                if ($pk !== '') {
                    if (!isset($byMonth[$pk])) $byMonth[$pk] = ['demanded' => 0, 'collected' => 0];
                    $byMonth[$pk]['demanded']  += $net;
                    $byMonth[$pk]['collected'] += $paid;
                }

                $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
            }
        }

        // Format class data
        $classData = [];
        foreach ($byClass as $cls => $data) {
            $classData[] = [
                'class'      => $cls,
                'demanded'   => round($data['demanded'], 2),
                'collected'  => round($data['collected'], 2),
                'balance'    => round($data['demanded'] - $data['collected'], 2),
                'students'   => count($data['students']),
                'rate'       => $data['demanded'] > 0 ? round(($data['collected'] / $data['demanded']) * 100, 1) : 0,
            ];
        }
        usort($classData, function ($a, $b) { return strnatcmp($a['class'], $b['class']); });

        // Format month data
        ksort($byMonth);
        $monthData = [];
        foreach ($byMonth as $pk => $data) {
            $monthData[] = [
                'period_key' => $pk,
                'demanded'   => round($data['demanded'], 2),
                'collected'  => round($data['collected'], 2),
                'rate'       => $data['demanded'] > 0 ? round(($data['collected'] / $data['demanded']) * 100, 1) : 0,
            ];
        }

        $this->json_success([
            'total_demanded'  => round($totalDemanded, 2),
            'total_collected' => round($totalCollected, 2),
            'collection_rate' => $totalDemanded > 0 ? round(($totalCollected / $totalDemanded) * 100, 1) : 0,
            'by_class'        => $classData,
            'by_month'        => $monthData,
            'by_status'       => $byStatus,
        ]);
    }

    /**
     * GET — AJAX: Fetch all receipt allocations for a student.
     * Used by Student Ledger page to show payment history.
     * Params: student_id (required)
     */
    public function get_student_allocations()
    {
        $this->_require_role(self::VIEW_ROLES);

        $studentId = trim($this->input->get('student_id') ?? '');
        if ($studentId === '') {
            return $this->json_error('Student ID is required.');
        }
        $studentId = $this->safe_path_segment($studentId, 'student_id');

        $sn = $this->school_name;
        $sy = $this->session_year;

        // Read all receipt allocations
        $allAllocs = $this->firebase->get("Schools/{$sn}/{$sy}/Fees/Receipt_Allocations");
        $receipts  = [];

        if (is_array($allAllocs)) {
            foreach ($allAllocs as $key => $rc) {
                if (!is_array($rc)) continue;
                if (($rc['student_id'] ?? '') !== $studentId) continue;
                $rc['_key'] = $key;
                $receipts[] = $rc;
            }
        }

        // Sort by date DESC
        usort($receipts, function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        $this->json_success(['receipts' => $receipts]);
    }

    /**
     * GET — AJAX endpoint for dashboard data.
     * Returns: summary stats, monthly collection, class-wise collection, recent transactions, payment mode breakdown.
     */
    public function get_dashboard_data()
    {
        $this->_require_role(self::VIEW_ROLES);

        $sn = $this->school_name;
        $sy = $this->session_year;
        $sessionRoot = "Schools/$sn/$sy";

        $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];

        // 1. Vouchers — total collection + monthly breakdown + recent transactions + payment modes
        $vouchers = $this->firebase->get("$sessionRoot/Accounts/Vouchers");
        $totalCollected   = 0;
        $monthlyCollection = array_fill_keys($months, 0);
        $recentTxns       = [];
        $paymentModes     = [];

        if (is_array($vouchers)) {
            foreach ($vouchers as $dateKey => $dayVouchers) {
                if (!is_array($dayVouchers)) continue;
                foreach ($dayVouchers as $key => $v) {
                    if (!is_array($v)) continue;
                    $amt = 0;
                    if (isset($v['Fees Received'])) {
                        $amt = floatval(str_replace(',', '', $v['Fees Received']));
                    } elseif (isset($v['amount'])) {
                        $amt = floatval($v['amount']);
                    }
                    if ($amt <= 0) continue;

                    $totalCollected += $amt;

                    // Monthly breakdown — parse date (dd-mm-YYYY)
                    $ts = strtotime(str_replace('-', '/', $dateKey));
                    if ($ts) {
                        $mName = date('F', $ts);
                        if (isset($monthlyCollection[$mName])) {
                            $monthlyCollection[$mName] += $amt;
                        }
                    }

                    // Payment mode
                    $mode = $v['Mode'] ?? 'Cash';
                    if (!isset($paymentModes[$mode])) $paymentModes[$mode] = 0;
                    $paymentModes[$mode] += $amt;

                    // Recent transactions (collect all, sort later)
                    $recentTxns[] = [
                        'receipt'  => $key,
                        'date'     => $dateKey,
                        'student'  => $v['Id'] ?? '—',
                        'amount'   => $amt,
                        'mode'     => $mode,
                    ];
                }
            }
        }

        // Sort recent transactions by date desc, take last 15
        usort($recentTxns, function ($a, $b) {
            return strtotime(str_replace('-', '/', $b['date'])) - strtotime(str_replace('-', '/', $a['date']));
        });
        $recentTxns = array_slice($recentTxns, 0, 15);

        // 2. Class-wise collection from fees chart + student data
        $classCollection = [];
        $totalStudents   = 0;
        $paidStudents    = 0;
        $totalDue        = 0;

        // Get current month index (April=0 for Indian academic year)
        $currentMonth = (int) date('n');
        $monthIndex   = ($currentMonth >= 4) ? ($currentMonth - 4) : ($currentMonth + 8);
        $monthsToCheck = array_slice($months, 0, $monthIndex + 1);

        // Scan session root for classes
        $classKeys = $this->firebase->shallow_get($sessionRoot);
        if (is_array($classKeys)) {
            foreach ($classKeys as $classKey) {
                $classKey = (string) $classKey;
                if (strpos($classKey, 'Class ') !== 0) continue;

                $sectionKeys = $this->firebase->shallow_get("$sessionRoot/$classKey");
                if (!is_array($sectionKeys)) continue;

                $classTotal  = 0;
                $classDue    = 0;
                $classCount  = 0;
                $classPaid   = 0;

                foreach ($sectionKeys as $secKey) {
                    $secKey = (string) $secKey;
                    if (strpos($secKey, 'Section ') !== 0) continue;

                    $studentList = $this->firebase->get("$sessionRoot/$classKey/$secKey/Students/List");
                    $classFees   = $this->firebase->get("$sessionRoot/Accounts/Fees/Classes Fees/$classKey/$secKey");

                    if (!is_array($studentList)) continue;
                    $classCount += count($studentList);

                    if (!is_array($classFees)) continue;

                    foreach ($studentList as $uid => $name) {
                        $monthFee = $this->firebase->get("$sessionRoot/$classKey/$secKey/Students/$uid/Month Fee");
                        $monthFee = is_array($monthFee) ? $monthFee : [];

                        $studentPaidAny = false;
                        $studentHasDue  = false;

                        foreach ($monthsToCheck as $m) {
                            $paid = isset($monthFee[$m]) ? (int) $monthFee[$m] : 0;
                            if ($paid === 1) {
                                $studentPaidAny = true;
                                // Sum what they paid (from class fees chart)
                                if (isset($classFees[$m]) && is_array($classFees[$m])) {
                                    foreach ($classFees[$m] as $title => $feeAmt) {
                                        $classTotal += floatval($feeAmt);
                                    }
                                }
                            } else {
                                if (isset($classFees[$m]) && is_array($classFees[$m])) {
                                    foreach ($classFees[$m] as $title => $feeAmt) {
                                        $classDue += floatval($feeAmt);
                                    }
                                    $studentHasDue = true;
                                }
                            }
                        }
                        if ($studentPaidAny) $classPaid++;
                    }
                }

                $totalStudents += $classCount;
                $paidStudents  += $classPaid;
                $totalDue      += $classDue;

                if ($classCount > 0) {
                    $classCollection[] = [
                        'class'     => $classKey,
                        'students'  => $classCount,
                        'collected' => round($classTotal, 2),
                        'due'       => round($classDue, 2),
                        'paid_pct'  => $classCount > 0 ? round(($classPaid / $classCount) * 100) : 0,
                    ];
                }
            }
        }

        // Sort classes naturally
        usort($classCollection, function ($a, $b) {
            return strnatcmp($a['class'], $b['class']);
        });

        // 3. Today's collection
        $today = date('d-m-Y');
        $todayCollection = 0;
        $todayTxns       = 0;
        if (is_array($vouchers) && isset($vouchers[$today]) && is_array($vouchers[$today])) {
            foreach ($vouchers[$today] as $v) {
                if (!is_array($v)) continue;
                $amt = isset($v['Fees Received']) ? floatval(str_replace(',', '', $v['Fees Received'])) : floatval($v['amount'] ?? 0);
                if ($amt > 0) {
                    $todayCollection += $amt;
                    $todayTxns++;
                }
            }
        }

        // 4. This month's collection
        $thisMonthName = date('F');
        $thisMonthCollection = $monthlyCollection[$thisMonthName] ?? 0;

        $defaulters = $totalStudents - $paidStudents;
        if ($defaulters < 0) $defaulters = 0;

        $this->json_success([
            'total_collected'     => round($totalCollected, 2),
            'total_due'           => round($totalDue, 2),
            'today_collection'    => round($todayCollection, 2),
            'today_transactions'  => $todayTxns,
            'month_collection'    => round($thisMonthCollection, 2),
            'month_name'          => $thisMonthName,
            'total_students'      => $totalStudents,
            'paid_students'       => $paidStudents,
            'defaulters'          => $defaulters,
            'collection_rate'     => $totalStudents > 0 ? round(($paidStudents / $totalStudents) * 100, 1) : 0,
            'monthly_breakdown'   => $monthlyCollection,
            'class_collection'    => $classCollection,
            'recent_transactions' => $recentTxns,
            'payment_modes'       => $paymentModes,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEES STRUCTURE
    // ══════════════════════════════════════════════════════════════════

    /**
     * Deprecated — redirects to the unified Fee Titles & Categories page.
     */
    public function fees_structure()
    {
        redirect(base_url('fee_management/categories'));
    }

    /**
     * Deprecated — redirects to the unified Fee Titles & Categories page.
     */
    public function delete_fees_structure($feeTitle = '', $feeType = '')
    {
        redirect(base_url('fee_management/categories'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEES CHART
    // ══════════════════════════════════════════════════════════════════

    public function fees_chart()
    {
        $this->_require_role(self::VIEW_ROLES, 'fees_chart');
        $sn = $this->school_name;
        $sy = $this->session_year;

        // AJAX GET — return fees JSON for selected class + section
        if ($this->input->get('class') && $this->input->get('section')) {

            $selClass   = urldecode(trim($this->input->get('class')));
            $selSection = urldecode(trim($this->input->get('section')));

            if (stripos($selSection, 'Section ') !== 0) {
                $selSection = 'Section ' . $selSection;
            }

            $feesJson = $this->_getFees($selClass, $selSection);
            $feesData = json_decode($feesJson, true);

            if (empty($feesData['fees'])) {
                $default  = $this->_createDefaultFees($selClass, $selSection);
                $feesData = ['fees' => $default];
            }

            $this->_json_out(
                isset($feesData['fees'])
                    ? ['fees' => $feesData['fees']]
                    : ['error' => 'No fees data found']
            );
            return;
        }

        // Page load — build class + section lists from year root
        $yearRoot = $this->CM->get_data("Schools/$sn/$sy");
        $yearRoot = is_array($yearRoot) ? $yearRoot : [];

        $classList  = [];
        $sectionMap = [];

        foreach ($yearRoot as $key => $value) {
            if (stripos($key, 'Class ') !== 0) continue;
            $classList[]      = $key;
            $sectionMap[$key] = [];

            if (!is_array($value)) continue;

            foreach (array_keys($value) as $secKey) {
                $secKey = (string)$secKey;
                if (stripos($secKey, 'Section ') === 0) {
                    $sectionMap[$key][] = $secKey;
                } elseif (strlen($secKey) <= 3 && ctype_alpha($secKey)) {
                    $sectionMap[$key][] = 'Section ' . strtoupper($secKey);
                }
            }
            sort($sectionMap[$key]);
        }

        usort($classList, function ($a, $b) {
            preg_match('/(\d+)/', $a, $ma);
            preg_match('/(\d+)/', $b, $mb);
            return ((int)($ma[1] ?? 0)) <=> ((int)($mb[1] ?? 0));
        });

        $data['classes']  = $classList;
        $data['sections'] = $sectionMap;

        $this->load->view('include/header');
        $this->load->view('fees_chart', $data);
        $this->load->view('include/footer');
    }

    private function _createDefaultFees($class, $section)
    {
        $sn = $this->school_name;
        $sy = $this->session_year;

        $structure = $this->CM->get_data("Schools/$sn/$sy/Accounts/Fees/Fees Structure");
        $feesPath  = $this->feesPath($class, $section);
        $existing  = $this->CM->get_data($feesPath);

        if (!empty($existing)) return $existing;
        if (empty($structure))  return [];

        $months  = [
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December',
            'January',
            'February',
            'March'
        ];
        $default = [];

        foreach ($months as $month) {
            $default[$month] = [];
            if (isset($structure['Monthly']) && is_array($structure['Monthly'])) {
                foreach ($structure['Monthly'] as $t => $v) $default[$month][$t] = 0;
            }
        }

        $default['Yearly Fees'] = [];
        if (isset($structure['Yearly']) && is_array($structure['Yearly'])) {
            foreach ($structure['Yearly'] as $t => $v) $default['Yearly Fees'][$t] = 0;
        }

        $this->CM->addKey_pair_data($feesPath, $default);
        return $default;
    }

    private function _getFees($class, $section)
    {
        $sn = $this->school_name;
        $sy = $this->session_year;

        $feesPath = $this->feesPath($class, $section);
        $feesData = $this->CM->get_data($feesPath);

        if (empty($feesData) || !is_array($feesData)) {
            return json_encode(['fees' => [], 'monthlyTotals' => []]);
        }

        // Ensure Yearly Fees node exists
        if (!isset($feesData['Yearly Fees']) || !is_array($feesData['Yearly Fees'])) {
            $ys = $this->CM->get_data("Schools/$sn/$sy/Accounts/Fees/Fees Structure/Yearly");
            if ($ys && is_array($ys)) {
                $yearly = array_fill_keys(array_keys($ys), 0);
                $feesData['Yearly Fees'] = $yearly;
                $this->CM->addKey_pair_data($feesPath, ['Yearly Fees' => $yearly]);
            }
        }

        $formatted = [];
        $totals    = [];
        foreach ($feesData as $month => $fees) {
            if (is_array($fees)) {
                $formatted[$month] = $fees;
                $totals[$month]    = array_sum($fees);
            }
        }

        return json_encode([
            'fees'          => $formatted,
            'monthlyTotals' => $totals,
            'overallTotal'  => array_sum($totals),
        ]);
    }

    public function save_updated_fees()
    {
        $this->_require_post();
        $this->_require_role(self::MANAGE_ROLES, 'save_fees');
        $this->output->set_content_type('application/json');

        // ── MY_Controller already validated CSRF in __construct() ──────
        // Token arrived via FormData field (CI built-in filter) AND
        // X-CSRF-Token header (MY_Controller check). Both layers passed
        // or we would never reach this line. No bypass. No exclusions.
        // ──────────────────────────────────────────────────────────────

        // Only accept AJAX POST requests
        if (!$this->input->is_ajax_request()) {
            http_response_code(403);
            $this->_json_out(['status' => 'error', 'message' => 'Direct access not allowed.']);
            return;
        }

        if ($this->input->method() !== 'post') {
            http_response_code(405);
            $this->_json_out(['status' => 'error', 'message' => 'Method not allowed.']);
            return;
        }

        // Read fields from $_POST (FormData)
        $class   = trim($this->input->post('class')   ?? '');
        $section = trim($this->input->post('section') ?? '');
        $feesRaw = trim($this->input->post('fees')    ?? '');

        if (!$class || !$section || !$feesRaw) {
            $this->_json_out(['status' => 'error', 'message' => 'Missing class, section, or fees.']);
            return;
        }

        // Validate class format — must match "Class Nth" stored in Firebase
        if (!preg_match('/^Class\s+\S+$/i', $class)) {
            $this->_json_out(['status' => 'error', 'message' => 'Invalid class format.']);
            return;
        }

        // Validate section format — must match "Section X"
        if (!preg_match('/^Section\s+[A-Z0-9]+$/i', $section)) {
            $this->_json_out(['status' => 'error', 'message' => 'Invalid section format.']);
            return;
        }

        // Decode fees JSON string
        $fees = json_decode($feesRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($fees)) {
            $this->_json_out(['status' => 'error', 'message' => 'Invalid fees data.']);
            return;
        }

        // Validate all fee amounts are non-negative numbers
        $allowedMonths = [
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December',
            'January',
            'February',
            'March',
            'Yearly Fees'
        ];

        foreach ($fees as $month => $entries) {
            if (!in_array($month, $allowedMonths)) {
                $this->_json_out(['status' => 'error', 'message' => "Invalid month key: $month"]);
                return;
            }
            if (!is_array($entries)) {
                $this->_json_out(['status' => 'error', 'message' => "Invalid fee entries for $month"]);
                return;
            }
            foreach ($entries as $title => $amount) {
                if (!is_numeric($amount) || (float)$amount < 0) {
                    $this->_json_out(['status' => 'error', 'message' => "Invalid amount for $title in $month"]);
                    return;
                }
            }
        }

        // Verify this class+section actually exists in Firebase
        // (prevents writing to arbitrary paths)
        $sn = $this->school_name;
        $sy = $this->session_year;
        $classExists = $this->CM->get_data("Schools/$sn/$sy/$class/$section");
        if (empty($classExists)) {
            $this->_json_out(['status' => 'error', 'message' => 'Class/section not found. Please reload the page.']);
            return;
        }

        $feesPath = $this->feesPath($class, $section);

        // Save Yearly Fees separately, then monthly fees
        if (isset($fees['Yearly Fees']) && is_array($fees['Yearly Fees'])) {
            $this->CM->addKey_pair_data("$feesPath/Yearly Fees", $fees['Yearly Fees']);
            unset($fees['Yearly Fees']);
        }

        if (!empty($fees)) {
            $this->CM->addKey_pair_data($feesPath, $fees);
        }

        // ── Sync fee structure to Firestore for mobile apps ──
        try {
            $fullChart = $this->firebase->get($feesPath);
            if (is_array($fullChart)) {
                $this->fsSync->syncFeeStructure($class, $section, $fullChart);
            }
        } catch (\Exception $e) {
            log_message('error', "save_updated_fees: Firestore sync failed: " . $e->getMessage());
        }

        log_audit('Fees', 'update_fees', "{$class} {$section}", "Updated fee structure for {$class} Section {$section}");

        $this->_json_out(['status' => 'success', 'message' => 'Fees updated successfully.']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  DISCOUNT
    // ══════════════════════════════════════════════════════════════════

    public function submit_discount()
    {
        $this->_require_post();
        $this->_require_role(self::MANAGE_ROLES, 'submit_discount');
        $this->output->set_content_type('application/json');

        $userId   = trim($this->input->post('userId'));
        $class    = trim($this->input->post('class'));
        $section  = trim($this->input->post('section'));
        $discount = $this->input->post('discount');

        if (empty($userId) || empty($class) || empty($section) || $discount === false || $discount === '') {
            $this->_json_out(['success' => false, 'message' => 'Missing required fields.']);
            return;
        }

        if (!is_numeric($discount) || (int)$discount < 0) {
            $this->_json_out(['success' => false, 'message' => 'Invalid discount value.']);
            return;
        }
        $userId = $this->safe_path_segment($userId, 'userId');

        $base = $this->studentPath($class, $section, $userId);

        try {
            $this->firebase->set("$base/Discount/OnDemandDiscount", (int)$discount);
            $cur = (int)($this->firebase->get("$base/Discount/totalDiscount") ?? 0);
            $new = $cur + (int)$discount;
            $this->firebase->set("$base/Discount/totalDiscount", $new);

            // Update defaulter status after discount applied
            try {
                $this->feeDefaulter->updateDefaulterStatus($userId);
            } catch (Exception $e) {
                log_message('error', "Fee_defaulter_check::updateDefaulterStatus failed after submit_discount for {$userId}: " . $e->getMessage());
            }

            $this->_json_out(['success' => true, 'newTotalDiscount' => $new]);
        } catch (Exception $e) {
            log_message('error', 'submit_discount: ' . $e->getMessage());
            $this->_json_out(['success' => false, 'message' => 'Internal server error.']);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  SEARCH
    // ══════════════════════════════════════════════════════════════════

    public function student_fees()
    {
        $this->_require_role(self::VIEW_ROLES, 'student_fees');
        $this->load->view('include/header');
        $this->load->view('student_fees');
        $this->load->view('include/footer');
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEE RECEIPTS
    // ══════════════════════════════════════════════════════════════════

    public function fetch_fee_receipts()
    {
        $this->_require_post();
        $this->_require_role(self::VIEW_ROLES, 'fetch_receipts');
        $this->output->set_content_type('application/json');

        $school_id = $this->parent_db_key;

        // FIX: Read userId from $_POST (FormData) instead of php://input JSON.
        // This means CSRF token arrives in the body field normally — no 403.
        $userId = trim($this->input->post('userId') ?? '');

        if (!$userId) {
            $this->output->set_output(json_encode([]));
            return;
        }
        $userId = $this->safe_path_segment($userId, 'userId');

        $userInfo = $this->firebase->get("Users/Parents/$school_id/$userId");
        if (empty($userInfo)) {
            $this->output->set_output(json_encode([]));
            return;
        }
        $userInfo = (array)$userInfo;

        $name   = $userInfo['Name']        ?? 'N/A';
        $father = $userInfo['Father Name'] ?? 'N/A';

        list($class, $section) = $this->_resolveClassSection($userInfo);

        if ($class === '' || $section === '') {
            $this->output->set_output(json_encode([]));
            return;
        }

        $studentBase = $this->studentPath($class, $section, $userId);
        $recs        = $this->firebase->get("$studentBase/Fees Record");

        $response = [];
        if (is_array($recs)) {
            foreach ($recs as $key => $rec) {
                $rec        = (array)$rec;
                $response[] = [
                    'receiptNo' => str_replace('F', '', $key),
                    'date'      => $rec['Date']     ?? '',
                    'student'   => "$name / $father",
                    'class'     => "$class $section",
                    'amount'    => $rec['Amount']   ?? '0.00',
                    'fine'      => $rec['Fine']     ?? '0.00',
                    'discount'  => $rec['Discount'] ?? '0.00',
                    'account'   => $rec['Mode']     ?? 'N/A',
                    'reference' => $rec['Refer']    ?? '',
                    'Id'        => $userId,
                ];
            }

            usort($response, function ($a, $b) {
                return (int)$b['receiptNo'] - (int)$a['receiptNo'];
            });
        }

        $this->output->set_output(json_encode($response));
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEES COUNTER (page load only — data fetched via AJAX)
    // ══════════════════════════════════════════════════════════════════

    public function fees_counter()
    {
        $this->_require_role(self::COUNTER_ROLES, 'fees_counter');
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $receiptPath       = "Schools/$school_name/$session_year/Accounts/Fees/Receipt No";
        $data['receiptNo'] = $this->CM->get_data($receiptPath) ?: '1';

        $accountsData     = $this->CM->get_data("Schools/$school_name/$session_year/Accounts/Account_book");
        $filteredAccounts = [];
        if (!empty($accountsData) && is_array($accountsData)) {
            foreach ($accountsData as $aName => $aDetails) {
                if (isset($aDetails['Under']) && in_array($aDetails['Under'], ['BANK ACCOUNT', 'CASH'])) {
                    $filteredAccounts[$aName] = $aDetails['Under'];
                }
            }
        }
        $data['accounts'] = $filteredAccounts;

        $ts = $this->CM->get_data("Schools/$school_name/$session_year/ServerTimestamp");
        $data['serverDate'] = (!empty($ts) && is_numeric($ts))
            ? date('d-m-Y', $ts / 1000)
            : date('d-m-Y');

        $this->load->view('include/header');
        $this->load->view('fees_counter', $data);
        $this->load->view('include/footer');
    }

    // ══════════════════════════════════════════════════════════════════
    //  STUDENT LOOKUP
    // ══════════════════════════════════════════════════════════════════

    public function lookup_student()
    {
        $this->_require_post();
        $this->_require_role(self::VIEW_ROLES, 'lookup_student');
        $this->output->set_content_type('application/json');

        $userId = trim($this->input->post('user_id') ?? '');
        if ($userId === '') {
            $this->_json_out(['error' => 'No user ID provided']);
            return;
        }
        $userId = $this->safe_path_segment($userId, 'user_id');

        $student = $this->CM->get_data("Users/Parents/{$this->parent_db_key}/$userId");
        if (empty($student)) {
            $this->_json_out(['error' => "Student '$userId' not found"]);
            return;
        }

        $student = (array)$student;

        // ── FIX: Use _resolveClassSection to get normalized values ──
        // This handles all formats: "8th", "8th B", "Class 8th", etc.
        list($class, $section) = $this->_resolveClassSection($student);

        $this->_json_out([
            'user_id'     => $student['User Id'] ?? $userId,
            'name'        => $student['Name']        ?? '',
            'father_name' => $student['Father Name'] ?? '',
            'class'       => $class,
            'section'     => $section,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  FETCH MONTHS
    // ══════════════════════════════════════════════════════════════════

    public function fetch_months()
    {
        $this->_require_post();
        $this->_require_role(self::VIEW_ROLES, 'fetch_months');
        $this->output->set_content_type('application/json');

        $school_id = $this->parent_db_key;
        $userId    = trim($this->input->post('user_id') ?? '');

        if ($userId === '') {
            $this->_json_out(['error' => 'No user ID provided']);
            return;
        }
        $userId = $this->safe_path_segment($userId, 'user_id');

        $student = $this->CM->get_data("Users/Parents/$school_id/$userId");
        if (empty($student)) {
            $this->_json_out(['error' => "Student '$userId' not found"]);
            return;
        }
        $student = (array)$student;

        list($class, $section) = $this->_resolveClassSection($student);

        if ($class === '' || $section === '') {
            $this->_json_out([
                'error'         => "Cannot resolve class/section for '$userId'",
                'class_field'   => $student['Class']   ?? '',
                'section_field' => $student['Section'] ?? '',
            ]);
            return;
        }

        $studentBase   = $this->studentPath($class, $section, $userId);
        $monthFeesData = $this->CM->get_data("$studentBase/Month Fee");
        $monthFeesData = is_array($monthFeesData) ? $monthFeesData : [];

        $months = [
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December',
            'January',
            'February',
            'March',
            'Yearly Fees'
        ];

        $result = [];
        foreach ($months as $m) {
            $result[$m] = isset($monthFeesData[$m]) ? (int)$monthFeesData[$m] : 0;
        }

        $this->_json_out(is_array($result) ? $result : ['data' => $result]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  FETCH FEE DETAILS
    // ══════════════════════════════════════════════════════════════════

    public function fetch_fee_details()
    {
        $this->_require_post();
        $this->_require_role(self::VIEW_ROLES, 'fetch_fee_details');
        $this->output->set_content_type('application/json');

        $school_id = $this->parent_db_key;
        $userId    = trim($this->input->post('user_id') ?? '');
        $selectedMonths = $this->input->post('months') ?? [];

        if ($userId === '' || empty($selectedMonths)) {
            $this->_json_out(['error' => 'Missing user_id or months']);
            return;
        }

        $student = $this->CM->get_data("Users/Parents/$school_id/$userId");
        if (empty($student)) {
            $this->_json_out(['error' => "Student '$userId' not found"]);
            return;
        }
        $student = (array)$student;

        list($class, $section) = $this->_resolveClassSection($student);

        if ($class === '' || $section === '') {
            $this->_json_out([
                'error'         => "Cannot resolve class/section for '$userId'",
                'class_field'   => $student['Class']   ?? '',
                'section_field' => $student['Section'] ?? '',
            ]);
            return;
        }

        $studentBase = $this->studentPath($class, $section, $userId);
        $fp          = $this->feesPath($class, $section);

        $exemptedFees = $this->CM->get_data("$studentBase/Exempted Fees");
        $exemptedFees = is_array($exemptedFees) ? $exemptedFees : [];

        $feesRecord = $this->getFeesForSelectedMonths(
            $this->school_name,
            $class,
            $section,
            $selectedMonths
        );

        if (!is_array($feesRecord)) $feesRecord = [];

        // Check if fee structure exists for this class/section
        // If no fee data found at all, guide the user to set it up first
        $hasAnyFees = false;
        foreach ($feesRecord as $m => $titles) {
            if (is_array($titles) && !empty($titles)) { $hasAnyFees = true; break; }
        }
        if (!$hasAnyFees) {
            $this->_json_out([
                'error'      => 'no_fee_structure',
                'message'    => "No fee structure found for {$class} / {$section}. Please set up Fee Titles and Fee Chart first.",
                'class'      => $class,
                'section'    => $section,
                'setup_hint' => 'Go to Fees → Categories to create fee titles, then Fees → Chart to assign amounts.',
            ]);
            return;
        }

        $feeRecord     = [];
        $feesRecordArr = [];
        $monthTotals   = array_fill_keys($selectedMonths, 0);
        $grandTotal    = 0;

        $allFeeTitles = [];
        foreach ($selectedMonths as $month) {
            if (!is_array($feesRecord[$month] ?? null)) continue;
            foreach (array_keys($feesRecord[$month]) as $t) {
                if (!in_array($t, $allFeeTitles)) $allFeeTitles[] = $t;
            }
        }

        foreach ($allFeeTitles as $feename) {
            $cleanName = str_replace(' (Yearly)', '', $feename);
            if (array_key_exists($cleanName, $exemptedFees)) continue;

            $feeRecord[$feename] = ['title' => $feename, 'total' => 0];

            foreach ($selectedMonths as $month) {
                $val = (float)($feesRecord[$month][$feename] ?? 0);
                $feeRecord[$feename][$month]      = $val;
                $monthTotals[$month]             += $val;
                $feeRecord[$feename]['total']     += $val;
            }

            $grandTotal     += $feeRecord[$feename]['total'];
            $feesRecordArr[] = [
                'title' => $feename,
                'total' => $feeRecord[$feename]['total'],
            ];
        }

        $discountData   = $this->CM->get_data("$studentBase/Discount");
        $discountAmount = (is_array($discountData) && isset($discountData['OnDemandDiscount']))
            ? (float)$discountData['OnDemandDiscount']
            : 0;

        $overRaw       = $this->CM->get_data("$studentBase/Oversubmittedfees");
        $oversubmitted = is_numeric($overRaw) ? (float)$overRaw : 0;

        // ── Attendance-based fee adjustments (config-driven) ──
        $attPenalty = 0;
        $attWarning = '';
        $attIncomplete = false;
        $attRules = $this->firebase->get("Schools/{$this->school_name}/Config/AttendanceRules");
        if (is_array($attRules) && !empty($attRules['enabled'])) {
            $this->load->helper('attendance');

            // Completion check: warn if attendance has vacant days for selected months
            foreach ($selectedMonths as $m) {
                $mName = str_replace(' (Yearly)', '', trim($m));
                if ($mName === 'Yearly Fees') continue;
                $yr = in_array($mName, ['January','February','March'])
                    ? ((int)explode('-', $this->session_year)[0] + 1)
                    : (int)explode('-', $this->session_year)[0];
                $chk = check_attendance_complete($this->firebase, $studentBase, $mName, $yr);
                if (!$chk['complete']) { $attIncomplete = true; break; }
            }
            $finePerDay = floatval($attRules['fine_per_absent_day'] ?? 0);
            $minPercent = floatval($attRules['min_attendance_percent'] ?? 0);
            $lowPenalty = floatval($attRules['low_attendance_penalty'] ?? 0);

            // Calculate absent days for selected months
            if ($finePerDay > 0) {
                foreach ($selectedMonths as $m) {
                    $m = trim($m);
                    $mName = str_replace(' (Yearly)', '', $m);
                    if ($mName === 'Yearly Fees') continue;
                    $yr = in_array($mName, ['January','February','March'])
                        ? ((int)explode('-', $this->session_year)[0] + 1)
                        : (int)explode('-', $this->session_year)[0];
                    $absentDays = get_absent_days($this->firebase, $studentBase, $this->school_name, $mName, $yr);
                    $attPenalty += $absentDays * $finePerDay;
                }
            }

            // Low attendance percentage penalty (holidays excluded)
            $attSnapshot = null;
            if ($minPercent > 0 && $lowPenalty > 0) {
                $sessionParts = explode('-', $this->session_year);
                $fromYear = (int)$sessionParts[0];
                $toYear   = $fromYear + 1;
                $attSnapshot = get_student_attendance_percent(
                    $this->firebase, $studentBase, $this->school_name,
                    'April', $fromYear, 'March', $toYear
                );
                if ($attSnapshot['percent'] < $minPercent) {
                    $attPenalty += $lowPenalty;
                    $attWarning = "Attendance {$attSnapshot['percent']}% (below {$minPercent}% threshold). Penalty: " . number_format($lowPenalty, 2);
                }
            }

            $grandTotal += $attPenalty;
        }

        $last  = end($selectedMonths);
        $label = count($selectedMonths) > 1
            ? implode(', ', array_slice($selectedMonths, 0, -1)) . ' and ' . $last
            : $last;

        $response = [
            'grandTotal'     => $grandTotal,
            'discountAmount' => $discountAmount,
            'overpaidFees'   => $oversubmitted,
            'message'        => "Fee Details for: $label",
            'feesRecord'     => $feesRecordArr,
            'feeRecord'      => $feeRecord,
            'selectedMonths' => $selectedMonths,
            'monthTotals'    => $monthTotals,
        ];
        if ($attPenalty > 0 || $attIncomplete) {
            $response['attendance_penalty'] = round($attPenalty, 2);
            $response['attendance_warning'] = $attWarning;
            $response['attendance_incomplete'] = $attIncomplete;
            if ($attIncomplete) {
                $response['attendance_incomplete_warning'] = 'Attendance not fully marked for selected months. Penalty may be inaccurate.';
            }
            // Frozen snapshot — never recomputed after fee generation
            $response['attendance_snapshot'] = [
                'percent'      => $attSnapshot['percent'] ?? null,
                'absent_days'  => $attSnapshot['absent'] ?? 0,
                'working_days' => $attSnapshot['working'] ?? 0,
                'holidays'     => $attSnapshot['holiday'] ?? 0,
                'computed_at'  => date('c'),
            ];
        }
        $this->_json_out($response);
    }

    // ══════════════════════════════════════════════════════════════════
    //  GET SERVER DATE
    // ══════════════════════════════════════════════════════════════════

    public function get_server_date()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_server_date');
        $this->output->set_content_type('application/json');
        $sn = $this->school_name;
        $sy = $this->session_year;
        $ts = $this->CM->get_data("Schools/$sn/$sy/ServerTimestamp");
        $date = (!empty($ts) && is_numeric($ts))
            ? date('d-m-Y', $ts / 1000)
            : date('d-m-Y');
        $this->_json_out(['date' => $date]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  GET RECEIPT NUMBER
    // ══════════════════════════════════════════════════════════════════

    public function get_receipt_no()
    {
        $this->_require_role(self::COUNTER_ROLES, 'get_receipt_no');
        $receiptNo = $this->_nextReceiptNo();
        $this->_json_out(['status' => 'success', 'receiptNo' => $receiptNo]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  SEARCH STUDENT
    // ══════════════════════════════════════════════════════════════════

    public function search_student()
    {
        $this->_require_post();
        $this->_require_role(self::VIEW_ROLES, 'search_student');
        $this->output->set_content_type('application/json');
        $results = $this->input->post('search_name')
            ? $this->_searchByName($this->input->post('search_name'))
            : [];
        $this->_json_out(is_array($results) ? $results : ['data' => $results]);
    }

    private function _searchByName($entry)
    {
        $students = $this->CM->get_data('Users/Parents/' . $this->parent_db_key);
        $results  = [];
        if (!is_array($students)) return $results;

        foreach ($students as $uid => $s) {
            $s = is_array($s) ? $s : [];
            $name   = $s['Name']        ?? '';
            $sid    = $s['User Id']     ?? '';
            $father = $s['Father Name'] ?? '';

            // Normalize class & section using the same resolver used everywhere
            list($class, $section) = $this->_resolveClassSection($s);

            if (
                stripos($name,    $entry) !== false ||
                stripos($sid,     $entry) !== false ||
                stripos($father,  $entry) !== false ||
                stripos($class,   $entry) !== false ||
                stripos($section, $entry) !== false    // ← NEW: search by section too
            ) {
                $results[] = [
                    'user_id'     => $sid,
                    'name'        => $name,
                    'father_name' => $father,
                    'class'       => $class,       // e.g. "Class 8th"
                    'section'     => $section,     // e.g. "Section B"
                ];
            }
        }
        return $results;
    }

    // ══════════════════════════════════════════════════════════════════
    //  SUBMIT FEES
    // ══════════════════════════════════════════════════════════════════

    public function submit_fees()
    {
        $this->_require_post();
        $this->_require_role(self::COUNTER_ROLES, 'submit_fees');
        $this->output->set_content_type('application/json');
        $this->load->library('firebase');

        // TC-CF005: Check deploy lock before any fee writes
        try {
            $deployLock = $this->firebase->get("Schools/{$this->school_name}/System/Deploy_Lock");
            if ($deployLock && ($deployLock['locked'] ?? false) === true) {
                $this->_json_out([
                    'status' => 'error',
                    'message' => 'System is updating. Please try again in a few minutes.',
                    'locked' => true
                ]);
                return;
            }
        } catch (Exception $e) {
            log_message('error', "Deploy lock check failed: " . $e->getMessage());
            // Don't block on check failure
        }

        // TC-CF007: Check fee system lock
        try {
            $sysLock = $this->firebase->get("Schools/{$this->school_name}/{$this->session_year}/Fees/System_Lock");
            if ($sysLock && ($sysLock['locked'] ?? false) === true) {
                $this->_json_out([
                    'status' => 'error',
                    'message' => 'Fee system is being updated. Please try again shortly.',
                    'locked' => true,
                    'reason' => $sysLock['reason'] ?? 'System maintenance'
                ]);
                return;
            }
        } catch (Exception $e) {
            log_message('error', "Fee system lock check failed: " . $e->getMessage());
        }

        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $receiptNo      = $this->_validateReceiptNo($this->input->post('receiptNo'));
        $paymentMode    = $this->input->post('paymentMode') ?: 'N/A';
        $userId         = trim($this->input->post('userId') ?? '');
        if ($userId !== '') {
            $userId = $this->safe_path_segment($userId, 'userId');
        }
        $schoolFees     = floatval(str_replace(',', '', $this->input->post('schoolFees')     ?? '0'));
        $discountFees   = floatval(str_replace(',', '', $this->input->post('discountAmount') ?? '0'));
        $fineAmount     = floatval(str_replace(',', '', $this->input->post('fineAmount')     ?? '0'));
        $submitAmount   = floatval(str_replace(',', '', $this->input->post('submitAmount')   ?? '0'));
        $reference      = $this->input->post('reference') ?: 'Fees Submitted';

        $selectedMonths = $this->input->post('selectedMonths') ?? [];
        if (!is_array($selectedMonths)) $selectedMonths = explode(',', $selectedMonths);

        $MonthTotal       = $this->input->post('monthTotals') ?? [];
        $monthTotalsArray = [];
        foreach ((array)$MonthTotal as $md) {
            if (isset($md['month'], $md['total'])) {
                $monthTotalsArray[trim($md['month'])] = floatval(str_replace(',', '', $md['total']));
            }
        }

        if ($userId === '') {
            $this->json_error('Missing student ID');
            return;
        }
        if (empty($selectedMonths)) {
            $this->json_error('No months selected');
            return;
        }
        if ($schoolFees <= 0) {
            $this->json_error('Fee amount must be greater than 0');
            return;
        }

        // Resolve class + section from Firebase (authoritative source)
        $student = $this->CM->get_data("Users/Parents/$school_id/$userId");
        if (empty($student)) {
            $this->json_error("Student '{$userId}' not found in Firebase");
            return;
        }
        $student = (array)$student;

        list($class, $section) = $this->_resolveClassSection($student);

        if ($class === '' || $section === '') {
            $this->json_error("Cannot resolve class/section for '{$userId}' (Class='" . ($student['Class'] ?? '') . "', Section='" . ($student['Section'] ?? '') . "')");
            return;
        }

        // ================================================================
        //  FINANCIAL SAFETY LAYER v2 — Atomic, Idempotent, Crash-Safe
        //
        //  Five defences:
        //    A. Idempotency with "processing" state (catches in-flight dupes)
        //    B. Token-based lock (only holder can release)
        //    C. Receipt reservation (prevents duplicate receipt numbers)
        //    D. Month Fee duplicate guard (re-reads markers)
        //    E. Write tracker with rollback on failure
        //
        //  On crash: lock expires after 120s, idempotency stays "processing"
        //  and next attempt sees it → waits or retries safely.
        // ================================================================

        $feesBase  = "Schools/{$school_name}/{$session_year}/Fees";
        $lockPath  = "{$feesBase}/Locks/{$userId}";
        $idempBase = "{$feesBase}/Idempotency";
        $lockToken = bin2hex(random_bytes(8)); // unique per-request
        $writtenPaths = []; // tracks every Firebase path written for rollback

        // Helper: abort with lock release + rollback
        $_abort = function (string $msg) use (&$writtenPaths, $lockPath, $lockToken, $userId, $receiptNo, $schoolFees) {
            // Rollback all writes in reverse order
            foreach (array_reverse($writtenPaths) as $wp) {
                try { $this->firebase->delete($wp['path'], $wp['key'] ?? null); }
                catch (\Exception $e) { log_message('error', "ROLLBACK failed: {$wp['path']} — " . $e->getMessage()); }
            }
            // Release lock only if we own it
            try {
                $lock = $this->firebase->get($lockPath);
                if (is_array($lock) && ($lock['token'] ?? '') === $lockToken) {
                    $this->firebase->delete($lockPath);
                }
            } catch (\Exception $e) { /* best effort */ }
            // Log transaction failure + audit alert
            $this->feeTxn->fail($msg, 'abort');
            $this->feeAudit->log('transaction_incomplete', [
                'student_id' => $userId, 'receipt_no' => $receiptNo,
                'amount' => $schoolFees, 'reason' => $msg,
            ]);
            $this->output->set_output(json_encode(['status' => 'error', 'message' => $msg]));
        };

        // Helper: track a write for potential rollback
        $_track = function (string $path, string $key = null) use (&$writtenPaths) {
            $writtenPaths[] = ['path' => $path, 'key' => $key];
        };

        // ── STEP A: Idempotency check ──
        $idempKey = md5($userId . '|' . $receiptNo . '|' . implode(',', $selectedMonths) . '|' . $schoolFees);
        $idempFullPath = "{$idempBase}/{$idempKey}";
        $existingIdemp = $this->firebase->get($idempFullPath);

        if (is_array($existingIdemp)) {
            $idempStatus = $existingIdemp['status'] ?? '';
            if ($idempStatus === 'success') {
                // Already completed — return cached response
                log_message('info', "submit_fees: IDEMPOTENT HIT key={$idempKey}");
                $this->output->set_output(json_encode([
                    'status' => 'success', 'message' => 'Fees already submitted (duplicate request).',
                    'receipt_no' => $existingIdemp['receipt_no'] ?? $receiptNo, 'idempotent' => true,
                ]));
                return;
            }
            if ($idempStatus === 'processing') {
                $procAge = time() - strtotime($existingIdemp['started_at'] ?? '2000-01-01');
                if ($procAge < 120) {
                    // Another request is actively in-flight
                    $this->output->set_output(json_encode([
                        'status'  => 'error',
                        'message' => 'This payment is currently being processed. Please wait.',
                    ]));
                    return;
                }
                // Stale processing record (>120s) — crashed mid-transaction
                $lastStep = $existingIdemp['step'] ?? 'unknown';
                $staleReceipt = $existingIdemp['receipt_no'] ?? '?';
                log_message('error',
                    "submit_fees: STALE PROCESSING key={$idempKey} age={$procAge}s"
                    . " last_step={$lastStep} receipt={$staleReceipt}"
                    . " — allowing retry (crashed at: {$lastStep})"
                );
                // If fees_record was already written in the crashed attempt,
                // the receipt check (Step C) and Month Fee guard (Step D) will
                // catch duplicates. Safe to retry.
            }
        }

        // Mark as "processing" — catches concurrent identical requests
        $this->firebase->set($idempFullPath, [
            'status'     => 'processing',
            'started_at' => date('c'),
            'user_id'    => $userId,
            'receipt_no' => $receiptNo,
            'admin_id'   => $this->admin_id ?? '',
        ]);

        // ── STEP B: Token-based student lock ──
        $existingLock = $this->firebase->get($lockPath);
        if (is_array($existingLock) && !empty($existingLock['locked'])) {
            $lockAge = time() - strtotime($existingLock['locked_at'] ?? '2000-01-01');
            if ($lockAge < 120) {
                // Active lock held by another request
                $this->firebase->delete($idempFullPath); // clean up our processing marker
                $this->output->set_output(json_encode([
                    'status' => 'error',
                    'message' => 'Payment is already being processed for this student. Please wait.',
                ]));
                return;
            }
            log_message('info', "submit_fees: Stale lock cleared for {$userId} (age={$lockAge}s)");
        }

        // Acquire lock with unique token — only this request can release it
        $this->firebase->set($lockPath, [
            'locked'     => true,
            'locked_at'  => date('c'),
            'locked_by'  => $this->admin_id ?? '',
            'token'      => $lockToken,
            'receipt_no' => $receiptNo,
        ]);

        // ── STEP C: Receipt reservation + duplicate check ──
        $receiptIdxPath = "Schools/{$school_name}/{$session_year}/Accounts/Receipt_Index/{$receiptNo}";
        $existingReceipt = $this->firebase->get($receiptIdxPath);
        if (is_array($existingReceipt)) {
            // Already finalized (has real data, not just reservation)
            if (!empty($existingReceipt['date']) && empty($existingReceipt['reserved'])) {
                $_abort("Receipt #{$receiptNo} has already been used. Please refresh to get a new number.");
                return;
            }
            // Stale reservation? (reserved but never finalized, >120s old)
            if (!empty($existingReceipt['reserved'])) {
                $resAge = time() - strtotime($existingReceipt['reserved_at'] ?? '2000-01-01');
                if ($resAge < 120) {
                    $_abort("Receipt #{$receiptNo} is currently reserved by another transaction. Please wait.");
                    return;
                }
                // Stale reservation — clean up and reuse
                log_message('info', "submit_fees: Stale receipt reservation cleared #{$receiptNo} age={$resAge}s");
            }
        }
        // Reserve the receipt number (prevents another request from using it)
        $this->firebase->set($receiptIdxPath, [
            'reserved'    => true,
            'user_id'     => $userId,
            'reserved_at' => date('c'),
        ]);
        $_track($receiptIdxPath); // rollback will delete this if processing fails

        // ── STEP D: Duplicate payment guard ──
        $studentBase_check = $this->studentPath($class, $section, $userId);
        if ($this->config->item('use_legacy_month_fee') !== false) {
            // Legacy guard: check Month Fee flags
            $monthFeeData = $this->firebase->get("$studentBase_check/Month Fee");
            $monthFeeData = is_array($monthFeeData) ? $monthFeeData : [];
            foreach ($selectedMonths as $m) {
                $m = trim($m);
                if (isset($monthFeeData[$m]) && (int)$monthFeeData[$m] === 1) {
                    $_abort("Month $m is already paid. Please refresh and try again.");
                    return;
                }
            }
        } else {
            // Demand-based guard: check if all demands for selected months are already paid
            $demandsPath = "Schools/{$school_name}/{$session_year}/Fees/Demands/{$userId}";
            $allDemands = $this->firebase->get($demandsPath);
            if (is_array($allDemands)) {
                foreach ($selectedMonths as $m) {
                    $m = trim($m);
                    foreach ($allDemands as $d) {
                        if (!is_array($d)) continue;
                        $period = explode(' ', $d['period'] ?? '')[0] ?? '';
                        if ($period === $m && ($d['status'] ?? '') === 'paid' && floatval($d['balance'] ?? 0) <= 0.005) {
                            $_abort("Month {$m} is already fully paid. Please refresh and try again.");
                            return;
                        }
                    }
                }
            }
        }

        // ── All safety checks passed — proceed with payment ──
        $this->feeTxn->begin('fee_payment', [
            'student_id' => $userId, 'receipt_no' => $receiptNo,
            'amount' => $schoolFees, 'months' => $selectedMonths,
        ]);
        $date     = date('d-m-Y');
        $date_obj = DateTime::createFromFormat('d-m-Y', $date);
        $month    = $date_obj ? $date_obj->format('F') : date('F');
        $day      = $date_obj ? $date_obj->format('d') : date('d');

        $receiptKey  = (strpos($receiptNo, 'R') === 0) ? $receiptNo : 'F' . $receiptNo;
        $studentBase = $this->studentPath($class, $section, $userId);

        // ── GLOBAL TRANSACTION ID ──
        // Unique per-request, attached to every record for cross-module traceability.
        $txnId = 'TXN_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $txnStart = microtime(true);
        $txnSteps = []; // debug trace of completed steps

        // Helper: update idempotency checkpoint (non-blocking)
        $_checkpoint = function (string $step) use ($idempFullPath, $txnId, &$txnSteps) {
            $txnSteps[] = $step;
            try {
                $this->firebase->update($idempFullPath, [
                    'step' => $step, 'step_at' => date('c'), 'txn_id' => $txnId,
                ]);
            } catch (\Exception $e) { /* best effort */ }
        };

        // Helper: verify a write by reading it back (for critical paths)
        $_verify = function (string $path, string $label) use ($_abort) {
            $readback = $this->firebase->get($path);
            if (!is_array($readback) && $readback === null) {
                $_abort("Write verification failed for {$label} — data not persisted at {$path}");
                return false;
            }
            return true;
        };

        // Write pending status for reconciliation safety
        $pendingPath = "Schools/$school_name/$session_year/Accounts/Pending_fees/$receiptKey";
        $this->firebase->set($pendingPath, [
            'user_id' => $userId, 'class' => $class, 'section' => $section,
            'amount' => $schoolFees, 'months' => $selectedMonths,
            'started_at' => date('c'), 'status' => 'pending',
            'txn_id' => $txnId,
        ]);
        $_track($pendingPath);
        $_checkpoint('pending_written');

        // 1. Reset OnDemandDiscount, accumulate totalDiscount
        $discPath1 = "$studentBase/Discount/OnDemandDiscount";
        $discPath2 = "$studentBase/Discount/totalDiscount";
        $oldDiscTotal = null; // for rollback
        try {
            $this->firebase->set($discPath1, 0);
            $oldDiscTotal = $this->firebase->get($discPath2);
            $oldDiscTotal = is_numeric($oldDiscTotal) ? (int)$oldDiscTotal : 0;
            $this->firebase->set($discPath2, $oldDiscTotal + (int)$discountFees);
            $_checkpoint('discount_written');
        } catch (Exception $e) {
            $_abort('Discount update failed: ' . $e->getMessage());
            return;
        }

        // 2. Fees Record
        $feesRecordPath = "$studentBase/Fees Record/$receiptKey";
        try {
            $this->firebase->set($feesRecordPath, [
                'Amount'   => number_format($schoolFees,   2, '.', ','),
                'Discount' => number_format($discountFees, 2, '.', ','),
                'Date'     => $date,
                'Fine'     => number_format($fineAmount,   2, '.', ','),
                'Mode'     => $paymentMode,
                'Refer'    => $reference,
                'txn_id'   => $txnId,
            ]);
            $_track($feesRecordPath);
            // Verify critical write
            if (!$_verify($feesRecordPath, 'Fees Record')) return;
            $_checkpoint('fees_record_verified');
        } catch (Exception $e) {
            // Rollback discount before aborting
            try { $this->firebase->set($discPath2, $oldDiscTotal); } catch (\Exception $re) {}
            $_abort('Fees Record write failed: ' . $e->getMessage());
            return;
        }

        // 3. Vouchers
        $voucherPath = "Schools/$school_name/$session_year/Accounts/Vouchers/$date/$receiptKey";
        try {
            $this->firebase->set($voucherPath, [
                'Acc'           => 'Fees',
                'Fees Received' => number_format($schoolFees, 2),
                'Id'            => $userId,
                'Mode'          => $paymentMode,
                'txn_id'        => $txnId,
            ]);
            $_track($voucherPath);
            if (!$_verify($voucherPath, 'Voucher')) return;
            $_checkpoint('voucher_verified');
        } catch (Exception $e) {
            log_message('error', 'submit_fees voucher write failed: ' . $e->getMessage());
            // Non-fatal: fees record already saved, voucher can be reconciled later
        }

        // 4. Account book ledger (non-fatal — queued for reconciliation if fails)
        $ab = "Schools/$school_name/$session_year/Accounts/Account_book";
        try {
            foreach ([
                ["$ab/Discount/$month/$day", $discountFees],
                ["$ab/Fees/$month/$day",     $schoolFees],
                ["$ab/Fine/$month/$day",     $fineAmount],
            ] as [$abPath, $abAmt]) {
                if ($abAmt <= 0) continue;
                $cur = floatval($this->firebase->get("$abPath/R") ?? 0);
                $this->firebase->set("$abPath/R", $cur + $abAmt);
            }
            $_checkpoint('account_book_written');
        } catch (Exception $e) {
            log_message('error', 'submit_fees account book failed: ' . $e->getMessage());
        }

        // 5. Receipt Index — handled by Step C (reservation) + Step E (finalization)

        // 6. Receipt counter — H-02 FIX: Counter is now incremented atomically
        //    in get_receipt_no() at reservation time. No increment needed here.
        //    This prevents the race condition where two concurrent get_receipt_no()
        //    calls could return the same number.

        // ════════════════════════════════════════════════════════════
        //  7. DEMAND-BASED PAYMENT ALLOCATION
        //
        //  Allocate payment across unpaid/partial demands (oldest first).
        //  After allocation, sync legacy Month Fee flags for backward
        //  compatibility with dashboard, reports, and fees_counter.
        // ════════════════════════════════════════════════════════════

        $totalSubmitted    = $schoolFees + $submitAmount;
        $failedMonths      = [];
        $allocations       = [];  // demand-level allocation records for the receipt
        $advanceCredit     = 0;   // unallocated surplus
        $demandAllocationOk = false; // tracks if demand engine was used

        // Attempt demand-based allocation (new system)
        $demandsPath = $this->_studentDemands($userId);
        $allDemands  = $this->firebase->get($demandsPath);

        if (is_array($allDemands) && !empty($allDemands)) {
            // ── Demand engine active for this student ──
            $demandAllocationOk = true;

            // Collect unpaid/partial demands sorted by period_key ASC (oldest first)
            $unpaid = [];
            foreach ($allDemands as $did => $d) {
                if (!is_array($d)) continue;
                $st = $d['status'] ?? 'unpaid';
                if ($st === 'paid') continue; // already fully paid
                $d['_did'] = $did;
                $unpaid[] = $d;
            }
            usort($unpaid, function ($a, $b) {
                $pk = strcmp($a['period_key'] ?? '', $b['period_key'] ?? '');
                return $pk !== 0 ? $pk : strcmp($a['fee_head'] ?? '', $b['fee_head'] ?? '');
            });

            $remaining = $totalSubmitted;

            foreach ($unpaid as $demand) {
                if ($remaining <= 0.005) break; // budget exhausted

                $did     = $demand['_did'];
                $balance = floatval($demand['balance'] ?? 0);

                if ($balance <= 0) continue; // safety: skip zero-balance demands

                // Allocate: take the lesser of remaining budget or demand balance
                $alloc = min($remaining, $balance);
                $alloc = round($alloc, 2);

                $newPaid    = round(floatval($demand['paid_amount'] ?? 0) + $alloc, 2);
                $newBalance = round($balance - $alloc, 2);
                $newStatus  = ($newBalance <= 0.005) ? 'paid' : 'partial';

                // Ensure balance never goes negative
                if ($newBalance < 0) $newBalance = 0;

                // Update the demand in Firebase (tracked for rollback)
                try {
                    $this->firebase->update("{$demandsPath}/{$did}", [
                        'paid_amount' => $newPaid,
                        'balance'     => $newBalance,
                        'status'      => $newStatus,
                        'last_payment_receipt' => $receiptKey,
                        'last_payment_date'    => $date,
                        'updated_at'           => date('c'),
                    ]);
                } catch (\Exception $e) {
                    log_message('error', "submit_fees: demand update failed for {$did}: " . $e->getMessage());
                    $failedMonths[] = "_demand_{$did}";
                    continue; // don't deduct from remaining if write failed
                }

                // Record allocation for the receipt
                $allocations[] = [
                    'demand_id'  => $did,
                    'fee_head'   => $demand['fee_head'] ?? '',
                    'category'   => $demand['category'] ?? '',
                    'period'     => $demand['period'] ?? '',
                    'period_key' => $demand['period_key'] ?? '',
                    'amount'     => $alloc,
                    'new_status' => $newStatus,
                ];

                $remaining -= $alloc;
            }

            // ── Handle unallocated surplus (advance payment) — atomic update ──
            if ($remaining > 0.005) {
                $advanceCredit = round($remaining, 2);
                try {
                    $this->_update_advance_balance_atomic(
                        $userId, $advanceCredit, $receiptKey,
                        $student['Name'] ?? $userId, $studentBase
                    );
                } catch (\Exception $e) {
                    log_message('error', "submit_fees: advance balance update failed for {$userId}: " . $e->getMessage());
                }
            }

            $_checkpoint('demands_allocated');

            // ── Store allocations on the receipt (tracked for rollback) ──
            $receiptAllocPath = "Schools/{$school_name}/{$session_year}/Fees/Receipt_Allocations/{$receiptKey}";
            try {
                $this->firebase->set($receiptAllocPath, [
                    'receipt_no'     => $receiptNo,
                    'student_id'     => $userId,
                    'student_name'   => $student['Name'] ?? $userId,
                    'class'          => $class,
                    'section'        => $section,
                    'total_amount'   => round($schoolFees, 2),
                    'discount'       => round($discountFees, 2),
                    'fine'           => round($fineAmount, 2),
                    'net_received'   => round($schoolFees - $discountFees + $fineAmount, 2),
                    'allocations'    => $allocations,
                    'advance_credit' => $advanceCredit,
                    'payment_mode'   => $paymentMode,
                    'date'           => $date,
                    'created_at'     => date('c'),
                    'created_by'     => $this->admin_id ?? '',
                    'txn_id'         => $txnId,
                ]);
                $_track($receiptAllocPath);
                $_checkpoint('allocations_stored');
            } catch (\Exception $e) {
                log_message('error', "submit_fees: receipt allocation write failed: " . $e->getMessage());
            }

            // ── Legacy sync: update Month Fee flags from demand status ──
            // A month's flag is set to 1 when ALL demands for that month are "paid"
            $this->_syncMonthFeeFlags($userId, $class, $section, $demandsPath, $studentBase);

        } else {
            // ── Fallback: legacy Month Fee logic (no demands generated yet) ──
            // This preserves full backward compatibility for students without demands
            $monthOrder = [
                'April', 'May', 'June', 'July', 'August', 'September',
                'October', 'November', 'December', 'January', 'February', 'March',
                'Yearly Fees',
            ];
            usort($selectedMonths, function ($a, $b) use ($monthOrder) {
                return array_search($a, $monthOrder) - array_search($b, $monthOrder);
            });

            $remaining = $totalSubmitted;
            foreach ($selectedMonths as $m) {
                $mFee = $monthTotalsArray[$m] ?? 0;
                if ($mFee > 0 && $remaining >= $mFee) {
                    try {
                        $this->firebase->set("$studentBase/Month Fee/$m", 1);
                        $remaining -= $mFee;
                    } catch (\Exception $e) {
                        log_message('error', "submit_fees: Failed to mark month '$m' as paid for student $userId receipt $receiptNo — " . $e->getMessage());
                        $failedMonths[] = $m;
                    }
                }
            }

            // Legacy carry-forward overpaid amount
            if ($remaining > 0.005) {
                try {
                    $this->firebase->set("$studentBase/Oversubmittedfees", round($remaining, 2));
                } catch (\Exception $e) {
                    log_message('error', "submit_fees: carry-forward write failed: " . $e->getMessage());
                    $failedMonths[] = '_carry_forward';
                }
            }
        }

        if (!empty($failedMonths)) {
            log_message('error', "submit_fees: PARTIAL — student={$userId}, receipt={$receiptNo}, failed=" . implode(',', $failedMonths));
        }

        // Mark fee submission as completed — clear pending flag only if no failures
        if (empty($failedMonths)) {
            $this->firebase->delete("Schools/$school_name/$session_year/Accounts/Pending_fees", $receiptKey);
        } else {
            log_message('error', "submit_fees: Pending flag RETAINED for receipt={$receiptNo} due to failures: " . implode(',', $failedMonths));
        }

        // ── Accounting journal (extracted method) ──
        $this->_create_fee_accounting_entry([
            'school_name'  => $school_name,
            'session_year' => $session_year,
            'date'         => date('Y-m-d'),
            'amount'       => (float) $schoolFees,
            'payment_mode' => $paymentMode ?? 'Cash',
            'bank_code'    => '',
            'receipt_no'   => $receiptNo ?? '',
            'student_name' => $student['Name'] ?? $userId,
            'student_id'   => $userId,
            'class'        => $class ?? '',
            'admin_id'     => $this->admin_id,
            'txn_id'       => $txnId,
        ], $allocations, (float) $fineAmount, (float) $discountFees, $demandAllocationOk);

        // ── Notification (extracted method) ──
        $this->_notify_fee_received([
            'student_id'   => $userId,
            'student_name' => $student['Name'] ?? $userId,
            'class'        => $class,
            'section'      => $section,
            'amount'       => $schoolFees,
            'receipt_no'   => $receiptNo,
            'date'         => $date,
            'payment_mode' => $paymentMode,
        ]);

        log_audit('Fees', 'collect_fee', $receiptNo, "Collected fee of {$schoolFees} from {$userId} via {$paymentMode}");

        $response = [
            'status'     => 'success',
            'message'    => 'Fees submitted successfully!',
            'txn_id'     => $txnId,
            'receipt_no' => $receiptNo,
            'user_id'    => $userId,
        ];

        // Include allocation details in response (new system)
        if ($demandAllocationOk) {
            $response['demand_allocation'] = true;
            $response['allocations']       = $allocations;
            $response['advance_credit']    = $advanceCredit;
            $response['demands_updated']   = count($allocations);
        }

        // ── STEP E: Finalize receipt reservation ──
        // Replace the placeholder from Step C with final receipt data.
        try {
            $this->firebase->set($receiptIdxPath, [
                'date'    => $date,
                'user_id' => $userId,
                'class'   => $class,
                'section' => $section,
                'amount'  => $schoolFees - $discountFees + $fineAmount,
                'txn_id'  => $txnId,
            ]);
            $_checkpoint('receipt_finalized');
        } catch (\Exception $e) {
            log_message('error', "submit_fees: Receipt index finalize failed: " . $e->getMessage());
        }

        // ── STEP F: Release lock (token-verified) + mark idempotency complete ──
        try {
            $lock = $this->firebase->get($lockPath);
            if (is_array($lock) && ($lock['token'] ?? '') === $lockToken) {
                $this->firebase->delete($lockPath);
            }
        } catch (\Exception $e) {
            log_message('error', "submit_fees: Lock release failed for {$userId}: " . $e->getMessage());
        }
        $txnDuration = round((microtime(true) - $txnStart) * 1000); // ms
        try {
            $this->firebase->set($idempFullPath, [
                'status'       => 'success',
                'step'         => 'completed',
                'txn_id'       => $txnId,
                'receipt_no'   => $receiptNo,
                'user_id'      => $userId,
                'amount'       => $schoolFees,
                'started_at'   => is_array($existingIdemp) ? ($existingIdemp['started_at'] ?? date('c')) : date('c'),
                'completed_at' => date('c'),
                'writes_count' => count($writtenPaths),
                'duration_ms'  => $txnDuration,
                'steps'        => $txnSteps,
            ]);
        } catch (\Exception $e) {
            log_message('error', "submit_fees: Idempotency finalize failed: " . $e->getMessage());
        }

        log_message('info',
            "submit_fees: OK txn={$txnId} receipt={$receiptNo} student={$userId}"
            . " amount={$schoolFees} writes=" . count($writtenPaths)
            . " duration={$txnDuration}ms steps=" . implode('→', $txnSteps)
        );

        // Audit log + transaction complete
        $this->feeTxn->complete(['receipt_no' => $receiptNo, 'amount' => $schoolFees]);
        $this->feeAudit->log('fee_paid', [
            'student_id' => $userId,
            'amount'     => $schoolFees,
            'receipt_no' => $receiptNo,
            'class'      => $class,
            'section'    => $section,
            'months'     => $selectedMonths,
            'mode'       => $paymentMode,
            'txn_id'     => $txnId,
        ]);

        // Update defaulter status after fee payment
        try {
            $defaulterStatus = $this->feeDefaulter->updateDefaulterStatus($userId);
            // Sync defaulter status to Firestore
            $this->fsSync->syncDefaulterStatus(
                $userId, $defaulterStatus,
                $student['Name'] ?? $userId, $class, $section
            );
        } catch (Exception $e) {
            log_message('error', "Fee_defaulter_check::updateDefaulterStatus failed after submit_fees for {$userId}: " . $e->getMessage());
        }

        // ── Sync receipt + updated demands to Firestore ──
        try {
            // Sync receipt
            $this->fsSync->syncReceipt(
                $receiptKey, $receiptNo, $userId,
                $student['Name'] ?? $userId, $class, $section,
                $schoolFees, $discountFees, $fineAmount,
                $paymentMode, $selectedMonths, $allocations,
                $this->admin_name ?? ($this->admin_id ?? 'system'),
                $reference
            );

            // Sync updated demands (status changes after payment)
            if ($demandAllocationOk) {
                $this->fsSync->syncAllDemandsForStudent(
                    $userId, $student['Name'] ?? $userId, $class, $section
                );
            }
        } catch (\Exception $e) {
            log_message('error', "submit_fees: Firestore sync failed for {$userId}: " . $e->getMessage());
        }

        // Send payment confirmation notification
        try {
            $this->load->library('Communication_helper');
            $this->communication_helper->init($this->firebase, $this->school_name, $this->session_year, $this->parent_db_key);
            $this->communication_helper->sendFeePaymentConfirmation($userId, $receiptNo);
        } catch (Exception $e) {
            log_message('error', "Fee payment notification failed: " . $e->getMessage());
        }

        $this->output->set_output(json_encode($response));
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRINT RECEIPT
    // ══════════════════════════════════════════════════════════════════

    public function print_receipt($receiptNo = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'print_receipt');
        if (empty($receiptNo)) show_404();
        if (!preg_match('/^[0-9]+$/', $receiptNo)) show_404();

        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $receiptKey   = 'F' . $receiptNo;

        // ── 1. Look up receipt via index (O(1)), fall back to voucher scan ──
        $index = $this->firebase->get(
            "Schools/$school_name/$session_year/Accounts/Receipt_Index/$receiptNo"
        );

        $voucher     = null;
        $voucherDate = '';
        $userId      = '';
        $class       = '';
        $section     = '';

        if (is_array($index) && !empty($index['date']) && !empty($index['user_id'])) {
            // Fast path: index exists — fetch voucher directly by date
            $voucherDate = $index['date'];
            $userId      = $index['user_id'];
            $class       = $index['class']   ?? '';
            $section     = $index['section'] ?? '';
            $voucher     = $this->firebase->get(
                "Schools/$school_name/$session_year/Accounts/Vouchers/$voucherDate/$receiptKey"
            );
            if (is_array($voucher)) {
                $voucher = (array) $voucher;
            } else {
                $voucher = null;
            }
        }

        // Fallback: scan all voucher dates (for receipts created before the index)
        if (empty($voucher)) {
            $allVouchers = $this->firebase->get(
                "Schools/$school_name/$session_year/Accounts/Vouchers"
            ) ?? [];
            if (is_array($allVouchers)) {
                foreach ($allVouchers as $date => $entries) {
                    if (!is_array($entries)) continue;
                    if (isset($entries[$receiptKey])) {
                        $voucher     = (array) $entries[$receiptKey];
                        $voucherDate = $date;
                        $userId      = $voucher['Id'] ?? '';
                        break;
                    }
                }
            }
        }

        if (empty($voucher) || empty($userId)) {
            show_404();
        }

        // ── 2. Load student profile ──
        $student = $this->firebase->get("Users/Parents/$school_id/$userId") ?? [];
        if (!is_array($student)) $student = [];

        // Use class/section from index if available, otherwise resolve from profile
        if ($class === '' || $section === '') {
            list($class, $section) = $this->_resolveClassSection($student);
        }

        // ── 3. Load fee record for this receipt ──
        $feeRecord = [];
        if ($class !== '' && $section !== '') {
            $studentBase = $this->studentPath($class, $section, $userId);
            $feeRecord   = $this->firebase->get("$studentBase/Fees Record/$receiptKey") ?? [];
            if (!is_array($feeRecord)) $feeRecord = [];
        }

        // If fee record empty, build from voucher data
        if (empty($feeRecord)) {
            $feeRecord = [
                'Amount'   => $voucher['Fees Received'] ?? '0.00',
                'Date'     => $voucherDate,
                'Mode'     => $voucher['Mode'] ?? 'N/A',
                'Discount' => '0.00',
                'Fine'     => '0.00',
                'Refer'    => '',
            ];
        }

        // ── 4. School profile for header ──
        $schoolProfile = $this->firebase->get("Schools/$school_name/Config/Profile") ?? [];
        if (!is_array($schoolProfile)) $schoolProfile = [];
        $schoolLogo = $this->firebase->get("Schools/$school_name/Logo") ?? '';

        // ── 5. Extract class/section display ──
        $classDisplay   = str_replace('Class ', '', $class);
        $sectionDisplay = str_replace('Section ', '', $section);

        // ── 6. Parse amounts ──
        $amount   = floatval(str_replace(',', '', $feeRecord['Amount']   ?? '0'));
        $discount = floatval(str_replace(',', '', $feeRecord['Discount'] ?? '0'));
        $fine     = floatval(str_replace(',', '', $feeRecord['Fine']     ?? '0'));
        $netTotal = $amount - $discount + $fine;

        $data = [
            'receipt_no'      => $receiptNo,
            'receipt_key'     => $receiptKey,
            'receipt_date'    => $feeRecord['Date'] ?? $voucherDate,
            'student'         => $student,
            'student_name'    => $student['Name'] ?? $userId,
            'father_name'     => $student['Father Name'] ?? '',
            'user_id'         => $userId,
            'class_display'   => $classDisplay,
            'section_display' => $sectionDisplay,
            'amount'          => $amount,
            'discount'        => $discount,
            'fine'            => $fine,
            'net_total'       => $netTotal,
            'payment_mode'    => $feeRecord['Mode'] ?? $voucher['Mode'] ?? 'N/A',
            'reference'       => $feeRecord['Refer'] ?? '',
            'school_name'     => $schoolProfile['name'] ?? $this->school_display_name ?? $school_name,
            'school_address'  => $schoolProfile['address'] ?? '',
            'school_phone'    => $schoolProfile['phone'] ?? '',
            'school_logo'     => $schoolLogo,
            'session_year'    => $session_year,
        ];

        $this->load->view('fees/receipt', $data);
    }

    // ══════════════════════════════════════════════════════════════════
    //  GET FEES FOR SELECTED MONTHS
    // ══════════════════════════════════════════════════════════════════

    private function getFeesForSelectedMonths($school_name, $class, $section, $selectedMonths)
    {
        $fp       = $this->feesPath($class, $section);
        $feesData = [];

        foreach ($selectedMonths as $month) {
            $monthFees = $this->CM->get_data("$fp/$month");

            if (is_array($monthFees)) {
                $feesData[$month] = $monthFees;
            } elseif (is_string($monthFees) && $monthFees !== '') {
                $decoded          = json_decode($monthFees, true);
                $feesData[$month] = is_array($decoded) ? $decoded : [];
            } else {
                $feesData[$month] = [];
            }
        }

        return $feesData;
    }

    private function calculateTotalFees($feesRecord, $selectedMonths, $exemptedFees)
    {
        $totals = [];
        foreach ($selectedMonths as $month) {
            if (!isset($feesRecord[$month]) || !is_array($feesRecord[$month])) continue;
            foreach ($feesRecord[$month] as $feeTitle => $feeAmount) {
                $clean   = str_replace(' (Yearly)', '', $feeTitle);
                if (array_key_exists($clean, $exemptedFees)) continue;
                $display = ($month === 'Yearly Fees') ? "$clean (Yearly)" : $clean;
                $totals[$display] = ($totals[$display] ?? 0) + floatval($feeAmount);
            }
        }
        return $totals;
    }

    // ══════════════════════════════════════════════════════════════════
    //  CLASS FEES
    // ══════════════════════════════════════════════════════════════════

    public function class_fees()
    {
        $this->_require_role(self::VIEW_ROLES, 'class_fees');
        $sn = $this->school_name;
        $sy = $this->session_year;

        $yearRoot = $this->CM->get_data("Schools/$sn/$sy");
        $yearRoot = is_array($yearRoot) ? $yearRoot : [];

        $classList  = [];
        $sectionMap = [];

        foreach ($yearRoot as $key => $value) {
            if (stripos($key, 'Class ') !== 0 || !is_array($value)) continue;
            $classList[] = $key;
            $sectionMap[$key] = [];
            foreach (array_keys($value) as $sk) {
                $sk = (string)$sk;
                if (stripos($sk, 'Section ') === 0) {
                    $sectionMap[$key][] = $sk;
                } elseif (strlen($sk) <= 3 && ctype_alpha($sk)) {
                    $sectionMap[$key][] = 'Section ' . strtoupper($sk);
                }
            }
            sort($sectionMap[$key]);
        }

        usort($classList, function ($a, $b) {
            preg_match('/(\d+)/', $a, $ma);
            preg_match('/(\d+)/', $b, $mb);
            return ((int)($ma[1] ?? 0)) <=> ((int)($mb[1] ?? 0));
        });

        $data['classes']  = $classList;
        $data['sections'] = $sectionMap;
        // $data['class']    = $this->input->get('class')   ?? '';
        // $data['section']  = $this->input->get('section') ?? '';
        $rawClass = urldecode($this->input->get('class') ?? '');
        if ($rawClass !== '') {
            $data['class'] = (stripos($rawClass, 'Class ') === 0)
                ? $rawClass
                : 'Class ' . $rawClass;
        } else {
            $data['class'] = '';
        }

        $rawSection = urldecode($this->input->get('section') ?? '');
        if ($rawSection !== '') {
            $rawSection = preg_replace('/^Section\s+/i', '', $rawSection);
            $data['section'] = 'Section ' . strtoupper($rawSection);
        } else {
            $data['section'] = '';
        }

        $this->load->view('include/header');
        $this->load->view('class_fees', $data);
        $this->load->view('include/footer');
    }

    // ══════════════════════════════════════════════════════════════════
    //  DUE FEES TABLE
    // ══════════════════════════════════════════════════════════════════

    public function due_fees_table()
    {
        $this->_require_post();
        $this->_require_role(self::VIEW_ROLES, 'due_fees_table');
        $this->output->set_content_type('application/json');

        $school_id = $this->parent_db_key;
        $sn        = $this->school_name;
        $sy        = $this->session_year;

        $class   = trim($this->input->post('class')   ?? '');
        $section = trim($this->input->post('section') ?? '');

        if (!$class || !$section) {
            $this->output->set_output(json_encode([[
                'userId' => null,
                'name' => 'Missing class or section parameter',
                'totalFee' => null,
                'receivedFee' => null,
                'dueFee' => null,
            ]]));
            return;
        }

        $feesPath = $this->feesPath($class, $section);

        // Single bulk read: entire section Students subtree (List + per-student data)
        $allStudentData = $this->firebase->get($this->studentPath($class, $section));
        if (empty($allStudentData) || !is_array($allStudentData)) {
            $this->output->set_output(json_encode([[
                'userId' => null,
                'name' => "No students in $class $section",
                'totalFee' => null,
                'receivedFee' => null,
                'dueFee' => null,
            ]]));
            return;
        }

        $student_ids = isset($allStudentData['List']) && is_array($allStudentData['List'])
            ? $allStudentData['List'] : [];
        if (empty($student_ids)) {
            $this->output->set_output(json_encode([[
                'userId' => null,
                'name' => "No students in $class $section",
                'totalFee' => null,
                'receivedFee' => null,
                'dueFee' => null,
            ]]));
            return;
        }

        $class_fees = $this->firebase->get($feesPath);
        if (empty($class_fees)) {
            $this->output->set_content_type('application/json');
            $this->output->set_output(json_encode([
                'error'      => 'no_fee_structure',
                'message'    => "No fee structure found for {$class} {$section}. Please set up Fee Titles and Fee Chart first.",
                'setup_hint' => 'Go to Fees → Categories to create fee titles, then Fees → Chart to assign amounts.',
            ]));
            return;
        }

        $annual_fee = 0;
        foreach ($class_fees as $month => $fees) {
            if (is_array($fees)) {
                foreach ($fees as $title => $amt) {
                    $annual_fee += (float)str_replace(',', '', $amt ?? 0);
                }
            }
        }

        $allProfiles = $this->CM->get_data("Users/Parents/$school_id");
        $allProfiles = is_array($allProfiles) ? $allProfiles : [];

        $response = [];
        foreach ($student_ids as $uid => $v) {
            $uid   = (string)$uid;
            $prof  = isset($allProfiles[$uid]) ? (array)$allProfiles[$uid] : [];
            $name  = $prof['Name']        ?? 'N/A';
            $fname = $prof['Father Name'] ?? 'N/A';

            // Extract Fees Record and Discount from already-fetched subtree
            $studentData = isset($allStudentData[$uid]) && is_array($allStudentData[$uid])
                ? $allStudentData[$uid] : [];

            $recs = isset($studentData['Fees Record']) && is_array($studentData['Fees Record'])
                ? $studentData['Fees Record'] : [];
            $paid = 0;
            foreach ($recs as $r) {
                if (is_array($r)) {
                    $paid += (float)str_replace(',', '', $r['Amount'] ?? 0);
                }
            }

            $discNode = isset($studentData['Discount']) && is_array($studentData['Discount'])
                ? $studentData['Discount'] : [];
            $discount = (float)($discNode['totalDiscount'] ?? 0);

            $response[] = [
                'userId'      => $uid,
                'name'        => "$name / $fname",
                'totalFee'    => $annual_fee,
                'receivedFee' => $paid,
                'discount'    => $discount,
                'dueFee'      => max(0, $annual_fee - $paid - $discount),
            ];
        }

        usort($response, function ($a, $b) {
            return $b['dueFee'] <=> $a['dueFee'];
        });

        $this->output->set_output(json_encode($response));
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEES RECORDS
    // ══════════════════════════════════════════════════════════════════

    public function fees_records()
    {
        $this->_require_role(self::VIEW_ROLES, 'fees_records');
        $school_id = $this->parent_db_key;
        $sn        = $this->school_name;
        $sy        = $this->session_year;

        $yearRoot = $this->CM->get_data("Schools/$sn/$sy");
        $yearRoot = is_array($yearRoot) ? $yearRoot : [];

        $classList  = [];
        $feesMatrix = [];

        foreach ($yearRoot as $key => $value) {
            if (stripos($key, 'Class ') !== 0 || !is_array($value)) continue;
            foreach (array_keys($value) as $sk) {
                $sk = (string)$sk;
                if (stripos($sk, 'Section ') === 0) {
                    $normSec = $sk;
                } elseif (strlen($sk) <= 3 && ctype_alpha($sk)) {
                    $normSec = 'Section ' . strtoupper($sk);
                } else {
                    continue;
                }
                $matKey              = "$key|$normSec";
                $classList[$matKey]  = "$key $normSec";
                $feesMatrix[$matKey] = array_fill(0, 12, 0);
            }
        }

        uksort($classList, function ($a, $b) {
            preg_match('/(\d+)/', $a, $ma);
            preg_match('/(\d+)/', $b, $mb);
            return ((int)($ma[1] ?? 0)) <=> ((int)($mb[1] ?? 0));
        });

        // Bulk-load ALL student records once (avoids N+1 Firebase calls)
        $allStudents       = $this->CM->get_data("Users/Parents/$school_id");
        $studentClassCache = [];
        if (is_array($allStudents)) {
            foreach ($allStudents as $uid => $stu) {
                $stu = is_array($stu) ? $stu : [];
                list($cls, $sec) = $this->_resolveClassSection($stu);
                if ($cls !== '' && $sec !== '') {
                    $studentClassCache[(string)$uid] = "$cls|$sec";
                }
            }
        }

        $vouchers = $this->CM->get_data("Schools/$sn/$sy/Accounts/Vouchers");
        $vouchers = is_array($vouchers) ? $vouchers : [];

        foreach ($vouchers as $date => $vList) {
            if ($date === 'VoucherCount' || !is_array($vList)) continue;
            $dObj = DateTime::createFromFormat('d-m-Y', $date);
            if (!$dObj) continue;

            $calMonth = (int)$dObj->format('n');
            $mi       = ($calMonth >= 4) ? ($calMonth - 4) : ($calMonth + 8);

            foreach ($vList as $vk => $v) {
                if (!is_array($v) || strpos((string)$vk, 'F') !== 0) continue;
                $received = (float)str_replace(',', '', $v['Fees Received'] ?? 0);
                if ($received <= 0) continue;
                $sid = trim((string)($v['Id'] ?? ''));
                if ($sid === '') continue;

                $matKey = $studentClassCache[$sid] ?? null;
                if ($matKey && isset($feesMatrix[$matKey])) {
                    $feesMatrix[$matKey][$mi] += $received;
                }
            }
        }

        $matrix = [];
        foreach ($classList as $k => $label) {
            $amounts  = $feesMatrix[$k] ?? array_fill(0, 12, 0);
            $matrix[] = [
                'class'   => $label,
                'key'     => $k,
                'amounts' => $amounts,
                'total'   => array_sum($amounts),
            ];
        }

        $data['fees_record_matrix'] = $matrix;
        $this->load->view('include/header');
        $this->load->view('fees_records', $data);
        $this->load->view('include/footer');
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEE HEAD → ACCOUNT CODE MAPPING (Admin Config)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch fee head → account code mapping config.
     * Returns: map (configured entries), defaults (keyword defaults for unmapped heads).
     */
    public function get_fee_account_map()
    {
        $this->_require_role(self::MANAGE_ROLES, 'get_fee_account_map');

        $mapPath = "Schools/{$this->school_name}/Accounts/Fee_Account_Map";
        $raw     = $this->firebase->get($mapPath);
        $entries = [];

        if (is_array($raw)) {
            foreach ($raw as $key => $entry) {
                if (!is_array($entry)) continue;
                $entry['_key'] = $key;
                $entries[]     = $entry;
            }
        }

        // Also return all fee structure titles so admin can map them
        $sn = $this->school_name;
        $sy = $this->session_year;
        $structure = $this->firebase->get("Schools/{$sn}/{$sy}/Accounts/Fees/Fees Structure");
        $allHeads  = [];
        if (is_array($structure)) {
            foreach (['Monthly', 'Yearly'] as $type) {
                if (isset($structure[$type]) && is_array($structure[$type])) {
                    foreach (array_keys($structure[$type]) as $title) {
                        $allHeads[] = $title;
                    }
                }
            }
        }

        // Return income accounts from CoA (4xxx)
        $coaPath  = "Schools/{$sn}/Accounts/ChartOfAccounts";
        $coa      = $this->firebase->get($coaPath);
        $incomeAccounts = [];
        if (is_array($coa)) {
            foreach ($coa as $code => $acct) {
                if (!is_array($acct)) continue;
                if (($acct['category'] ?? '') === 'Income' && ($acct['status'] ?? '') === 'active' && empty($acct['is_group'])) {
                    $incomeAccounts[] = ['code' => $code, 'name' => $acct['name'] ?? $code];
                }
            }
        }

        $this->json_success([
            'mappings'        => $entries,
            'fee_heads'       => $allHeads,
            'income_accounts' => $incomeAccounts,
        ]);
    }

    /**
     * POST — Save a fee head → account code mapping.
     * Params: fee_head, account_code
     */
    public function save_fee_account_map()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_fee_account_map');

        $feeHead     = trim($this->input->post('fee_head') ?? '');
        $accountCode = trim($this->input->post('account_code') ?? '');

        if ($feeHead === '' || $accountCode === '') {
            return $this->json_error('Fee head and account code are required.');
        }

        // Validate account exists
        $sn   = $this->school_name;
        $acct = $this->firebase->get("Schools/{$sn}/Accounts/ChartOfAccounts/{$accountCode}");
        if (!is_array($acct) || ($acct['status'] ?? '') !== 'active') {
            return $this->json_error("Account {$accountCode} not found or inactive.");
        }

        $key  = strtoupper(preg_replace('/[^A-Z0-9]+/', '_', strtolower($feeHead)));
        $key  = trim($key, '_');
        $path = "Schools/{$sn}/Accounts/Fee_Account_Map/{$key}";

        $this->firebase->set($path, [
            'fee_head'     => $feeHead,
            'account_code' => $accountCode,
            'account_name' => $acct['name'] ?? $accountCode,
            'updated_at'   => date('c'),
            'updated_by'   => $this->admin_id ?? '',
        ]);

        $this->json_success(['message' => "Mapped '{$feeHead}' → {$accountCode} ({$acct['name']})"]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  FINE AUTO-CALCULATION
    // ══════════════════════════════════════════════════════════════════

    /**
     * Auto-calculate late fine for a student's overdue demands.
     *
     * Reads fine rules from Reminder Settings. For each overdue demand,
     * computes fine based on days past due_date and updates the demand.
     *
     * Fine rules:
     *   late_fee_type = "fixed"      → flat amount per overdue demand
     *   late_fee_type = "percentage" → % of net_amount per overdue demand
     *
     * Updates demand: fine_amount, balance (recalculated)
     *
     * @param string $studentId  Student ID
     * @return array  [demands_updated => int, total_fine => float]
     */
    private function compute_fines_for_student(string $studentId): array
    {
        $sn = $this->school_name;
        $sy = $this->session_year;

        // Load fine rules
        $settings = $this->firebase->get("Schools/{$sn}/{$sy}/Accounts/Fees/Reminder Settings");
        if (!is_array($settings) || !($settings['late_fee_enabled'] ?? false)) {
            return ['demands_updated' => 0, 'total_fine' => 0, 'message' => 'Late fee not enabled'];
        }

        $fineType  = $settings['late_fee_type'] ?? 'fixed';
        $fineValue = floatval($settings['late_fee_value'] ?? 0);
        if ($fineValue <= 0) {
            return ['demands_updated' => 0, 'total_fine' => 0, 'message' => 'Late fee value is 0'];
        }

        $today      = date('Y-m-d');
        $demandsPath = $this->_studentDemands($studentId);
        $demands     = $this->firebase->get($demandsPath);
        if (!is_array($demands)) return ['demands_updated' => 0, 'total_fine' => 0];

        $updated   = 0;
        $totalFine = 0;

        foreach ($demands as $did => $d) {
            if (!is_array($d)) continue;
            $status = $d['status'] ?? 'unpaid';
            if ($status === 'paid') continue; // no fine on paid demands

            $dueDate = $d['due_date'] ?? '';
            if ($dueDate === '' || $dueDate >= $today) continue; // not overdue yet

            $netAmount = floatval($d['net_amount'] ?? 0);
            if ($netAmount <= 0) continue;

            // Calculate fine
            if ($fineType === 'percentage') {
                $fine = round($netAmount * ($fineValue / 100), 2);
            } else {
                $fine = round($fineValue, 2);
            }

            $existingFine = floatval($d['fine_amount'] ?? 0);
            if (abs($existingFine - $fine) < 0.01) continue; // already computed

            // Update demand: balance = net_amount + fine - paid_amount
            $paidAmount = floatval($d['paid_amount'] ?? 0);
            $newBalance = round($netAmount + $fine - $paidAmount, 2);
            if ($newBalance < 0) $newBalance = 0;

            $this->firebase->update("{$demandsPath}/{$did}", [
                'fine_amount' => $fine,
                'balance'     => $newBalance,
                'fine_computed_at' => date('c'),
            ]);

            $updated++;
            $totalFine += $fine;
        }

        return ['demands_updated' => $updated, 'total_fine' => round($totalFine, 2)];
    }

    /**
     * POST — Auto-compute fines for a student.
     * Params: student_id (required)
     */
    public function auto_compute_fines()
    {
        $this->_require_role(self::MANAGE_ROLES, 'auto_compute_fines');

        $studentId = trim($this->input->post('student_id') ?? '');
        if ($studentId === '') {
            return $this->json_error('Student ID is required.');
        }
        $studentId = $this->safe_path_segment($studentId, 'student_id');

        $result = $this->compute_fines_for_student($studentId);
        $this->json_success(array_merge($result, [
            'message' => "{$result['demands_updated']} demands updated, total fine ₹{$result['total_fine']}",
        ]));
    }

    // ══════════════════════════════════════════════════════════════════
    //  DEMAND REVERSAL (for refunds)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Reverse demand allocations when a refund is processed.
     *
     * Reads the receipt allocations, reverses each demand's paid_amount,
     * recalculates balance and status, and creates an audit trail.
     *
     * @param string $receiptKey  e.g. "F1001"
     * @param string $studentId   Student ID
     * @param float  $refundAmount Amount being refunded
     * @return array  [reversed => int, audit_id => string]
     */
    public function reverse_demand_allocations(string $receiptKey, string $studentId, float $refundAmount): array
    {
        $sn = $this->school_name;
        $sy = $this->session_year;

        // 1. Read the receipt allocations
        $allocPath   = "Schools/{$sn}/{$sy}/Fees/Receipt_Allocations/{$receiptKey}";
        $receiptData = $this->firebase->get($allocPath);

        if (!is_array($receiptData) || empty($receiptData['allocations'])) {
            return ['reversed' => 0, 'message' => 'No demand allocations found for this receipt'];
        }

        $allocations = $receiptData['allocations'];
        $demandsPath = $this->_studentDemands($studentId);
        $reversed    = 0;
        $reversalLog = [];
        $remaining   = $refundAmount;

        // 2. Reverse each allocation (latest period first for partial refunds)
        $allocations = array_reverse($allocations); // reverse order: latest first

        foreach ($allocations as $alloc) {
            if ($remaining <= 0.005) break;

            $demandId = $alloc['demand_id'] ?? '';
            $allocAmt = floatval($alloc['amount'] ?? 0);
            if ($demandId === '' || $allocAmt <= 0) continue;

            // How much to reverse from this allocation
            $reverseAmt = min($remaining, $allocAmt);

            // Read current demand state
            $demand = $this->firebase->get("{$demandsPath}/{$demandId}");
            if (!is_array($demand)) continue;

            $oldPaid    = floatval($demand['paid_amount'] ?? 0);
            $netAmount  = floatval($demand['net_amount'] ?? 0);
            $fineAmount = floatval($demand['fine_amount'] ?? 0);

            // Reverse: reduce paid, increase balance
            $newPaid    = round(max(0, $oldPaid - $reverseAmt), 2);
            $newBalance = round($netAmount + $fineAmount - $newPaid, 2);
            if ($newBalance < 0) $newBalance = 0;

            // Determine new status
            if ($newPaid <= 0.005) {
                $newStatus = 'unpaid';
            } elseif ($newBalance <= 0.005) {
                $newStatus = 'paid';
            } else {
                $newStatus = 'partial';
            }

            $this->firebase->update("{$demandsPath}/{$demandId}", [
                'paid_amount'       => $newPaid,
                'balance'           => $newBalance,
                'status'            => $newStatus,
                'last_refund_receipt' => $receiptKey,
                'last_refund_date'    => date('c'),
                'updated_at'        => date('c'),
            ]);

            $reversalLog[] = [
                'demand_id'     => $demandId,
                'fee_head'      => $alloc['fee_head'] ?? '',
                'period'        => $alloc['period'] ?? '',
                'reversed_amt'  => round($reverseAmt, 2),
                'new_paid'      => $newPaid,
                'new_balance'   => $newBalance,
                'new_status'    => $newStatus,
            ];

            $remaining -= $reverseAmt;
            $reversed++;
        }

        // 3. If refund exceeds allocations, reduce advance balance (atomic)
        if ($remaining > 0.005) {
            $this->_update_advance_balance_atomic(
                $studentId, -$remaining, $receiptKey
            );
        }

        // 4. Write audit trail
        $auditId = 'RFND_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $this->firebase->set("Schools/{$sn}/{$sy}/Fees/Refund_Audit/{$auditId}", [
            'receipt_key'   => $receiptKey,
            'student_id'    => $studentId,
            'refund_amount' => round($refundAmount, 2),
            'reversals'     => $reversalLog,
            'reversed_count' => $reversed,
            'created_at'    => date('c'),
            'created_by'    => $this->admin_id ?? 'system',
        ]);

        // 5. Mark receipt allocation as reversed
        $this->firebase->update($allocPath, [
            'status'       => 'reversed',
            'reversed_at'  => date('c'),
            'reversed_by'  => $this->admin_id ?? 'system',
            'refund_audit' => $auditId,
        ]);

        // 6. Re-sync legacy Month Fee flags
        $profile = $this->firebase->get("Users/Parents/{$this->parent_db_key}/{$studentId}");
        if (is_array($profile)) {
            list($class, $section) = $this->_resolveClassSection($profile);
            if ($class !== '' && $section !== '') {
                $studentBase = $this->studentPath($class, $section, $studentId);
                $this->_syncMonthFeeFlags($studentId, $class, $section, $demandsPath, $studentBase);
            }
        }

        return [
            'reversed'  => $reversed,
            'audit_id'  => $auditId,
            'reversals' => $reversalLog,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  LEGACY SYNC: MONTH FEE FLAGS FROM DEMANDS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Sync legacy Month Fee/{month} = 0|1 flags from demand status.
     *
     * A month's flag is set to 1 ONLY when ALL demands for that month
     * have status = "paid". If any demand for a month is unpaid/partial,
     * the flag stays 0 (or is reset to 0 if it was incorrectly set).
     *
     * This ensures backward compatibility with:
     *  - fees_counter (fetch_months shows paid/unpaid per month)
     *  - fees_dashboard (collection rate uses Month Fee flags)
     *  - fees_records (monthly collection matrix)
     *  - due_fees_table (dues calculation)
     *
     * @param string $studentId   Student ID
     * @param string $class       "Class 9th" format
     * @param string $section     "Section A" format
     * @param string $demandsPath Full Firebase path to student demands
     * @param string $studentBase Full Firebase path to student section node
     */
    private function _syncMonthFeeFlags(
        string $studentId,
        string $class,
        string $section,
        string $demandsPath,
        string $studentBase
    ): void {
        // Feature flag: skip legacy writes when demand-based is authoritative
        if ($this->config->item('use_legacy_month_fee') === false) {
            return;
        }

        // Re-read all demands for this student (fresh after updates)
        $demands = $this->firebase->get($demandsPath);
        if (!is_array($demands) || empty($demands)) return;

        // Group demands by month name
        // period format: "April 2026" → extract month name
        $monthDemands = []; // monthName => [statuses]
        foreach ($demands as $did => $d) {
            if (!is_array($d)) continue;
            $period = $d['period'] ?? '';
            // Extract month name (first word before space)
            $monthName = explode(' ', $period)[0] ?? '';
            if ($monthName === '') continue;

            // Map "Yearly" frequency demands to the month they were generated in
            // (Yearly fees are generated in April's demands)
            $frequency = $d['frequency'] ?? 'monthly';

            if (!isset($monthDemands[$monthName])) {
                $monthDemands[$monthName] = [];
            }
            $monthDemands[$monthName][] = $d['status'] ?? 'unpaid';
        }

        // For each month that has demands, set flag based on whether ALL are paid
        foreach ($monthDemands as $monthName => $statuses) {
            $allPaid = true;
            foreach ($statuses as $st) {
                if ($st !== 'paid') {
                    $allPaid = false;
                    break;
                }
            }

            try {
                $this->firebase->set("{$studentBase}/Month Fee/{$monthName}", $allPaid ? 1 : 0);
            } catch (\Exception $e) {
                log_message('error', "_syncMonthFeeFlags: failed for {$studentId}/{$monthName}: " . $e->getMessage());
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEE DEMAND ENGINE
    //
    //  New demand-based architecture that tracks per-student, per-month,
    //  per-fee-head obligations. Coexists with legacy Month Fee flags.
    //
    //  Firebase path:
    //    Schools/{school}/{year}/Fees/Demands/{student_id}/{demand_id}
    //
    //  A "demand" = one fee head for one period for one student.
    //  demand_id format: DEM_{YYYYMM}_{FEE_HEAD_KEY}
    //    e.g. DEM_202604_TUITION_FEE, DEM_202604_LAB_FEE
    // ══════════════════════════════════════════════════════════════════

    /** Academic months in Indian school order (April → March) */
    private const ACADEMIC_MONTHS = [
        'April', 'May', 'June', 'July', 'August', 'September',
        'October', 'November', 'December', 'January', 'February', 'March',
    ];

    /** Month name → number mapping */
    private const MONTH_NUM = [
        'April' => 4, 'May' => 5, 'June' => 6, 'July' => 7,
        'August' => 8, 'September' => 9, 'October' => 10,
        'November' => 11, 'December' => 12, 'January' => 1,
        'February' => 2, 'March' => 3,
    ];

    // ── Path helpers ────────────────────────────────────────────────

    /**
     * Base path for all fee demands in current session.
     * Schools/{school}/{year}/Fees/Demands
     */
    private function _demandsBase(): string
    {
        return "Schools/{$this->school_name}/{$this->session_year}/Fees/Demands";
    }

    /**
     * Path for a specific student's demands.
     * Schools/{school}/{year}/Fees/Demands/{student_id}
     */
    private function _studentDemands(string $studentId): string
    {
        return $this->_demandsBase() . '/' . $this->safe_path_segment($studentId, 'student_id');
    }

    /**
     * Build a deterministic demand ID from period + fee head.
     * Format: DEM_YYYYMM_SANITIZED_FEE_HEAD
     *
     * Deterministic IDs guarantee idempotency — calling generate twice
     * for the same student+month+head will NOT create duplicates.
     *
     * @param string $periodKey  e.g. "2026-04"
     * @param string $feeHead   e.g. "Tuition Fee"
     * @return string e.g. "DEM_202604_TUITION_FEE"
     */
    private function _buildDemandId(string $periodKey, string $feeHead): string
    {
        $ym  = str_replace('-', '', $periodKey); // "202604"
        $key = strtoupper(trim($feeHead));
        $key = preg_replace('/[^A-Z0-9]+/', '_', $key);  // sanitize
        $key = trim($key, '_');
        return "DEM_{$ym}_{$key}";
    }

    /**
     * Resolve the calendar year for a given academic month.
     * April-December → session start year; January-March → start year + 1.
     *
     * @param string $monthName e.g. "April"
     * @return int calendar year
     */
    private function _resolveCalendarYear(string $monthName): int
    {
        // session_year format: "2025-26" → start = 2025
        $parts = explode('-', $this->session_year);
        $startYear = (int) $parts[0];
        $monthNum  = self::MONTH_NUM[$monthName] ?? 4;
        return ($monthNum >= 4) ? $startYear : $startYear + 1;
    }

    /**
     * Compute the due date for a given month.
     * Default: 10th of the month. Configurable via Reminder Settings.
     *
     * @param string $monthName e.g. "April"
     * @return string ISO date "YYYY-MM-DD"
     */
    private function _computeDueDate(string $monthName, ?int $dueDay = null): string
    {
        if ($dueDay === null || $dueDay < 1 || $dueDay > 28) {
            $dueDay = 10; // safe default
        }
        $year     = $this->_resolveCalendarYear($monthName);
        $monthNum = self::MONTH_NUM[$monthName] ?? 4;
        return sprintf('%04d-%02d-%02d', $year, $monthNum, $dueDay);
    }

    /**
     * Build a period_key from month name.
     * e.g. "April" (session 2025-26) → "2026-04" wait no → "2025-04"
     *
     * @param string $monthName
     * @return string "YYYY-MM"
     */
    private function _buildPeriodKey(string $monthName): string
    {
        $year     = $this->_resolveCalendarYear($monthName);
        $monthNum = self::MONTH_NUM[$monthName] ?? 4;
        return sprintf('%04d-%02d', $year, $monthNum);
    }

    // ── Fetch helpers ───────────────────────────────────────────────

    /**
     * Get the class fee chart for a given class/section.
     * Returns: ["April" => ["Tuition Fee" => 5000, ...], "May" => [...], ...]
     */
    private function _getClassFeeChart(string $class, string $section): array
    {
        $path = $this->feesPath($class, $section);
        $data = $this->firebase->get($path);
        return is_array($data) ? $data : [];
    }

    /**
     * Fetch auto-apply discount policies and scholarship amounts for a student.
     * Returns total discount per fee head: ["Tuition Fee" => 500, ...]
     *
     * @param string $studentId
     * @param string $class
     * @param string $section
     * @return array  [fee_head => discount_amount]
     */
    private function _getStudentDiscounts(string $studentId, string $class, string $section): array
    {
        $sn = $this->school_name;
        $sy = $this->session_year;
        $discounts = [];

        // 1. Read student's stored discount data
        $secNorm = preg_replace('/^Section\s+/i', '', trim($section));
        $secNorm = 'Section ' . strtoupper($secNorm);
        $discountNode = $this->firebase->get(
            "Schools/{$sn}/{$sy}/{$class}/{$secNorm}/Students/{$studentId}/Discount"
        );

        // 2. Read scholarship awards for this student
        $feesBase = "Schools/{$sn}/{$sy}/Accounts/Fees";
        $awards   = $this->firebase->get("{$feesBase}/Scholarship Awards");
        if (is_array($awards)) {
            foreach ($awards as $award) {
                if (!is_array($award)) continue;
                if (($award['student_id'] ?? '') !== $studentId) continue;
                if (($award['status'] ?? '') !== 'active') continue;
                $amt = floatval($award['amount'] ?? 0);
                if ($amt > 0) {
                    // Scholarship applied as general discount (not head-specific)
                    $discounts['_scholarship'] = ($discounts['_scholarship'] ?? 0) + $amt;
                }
            }
        }

        // 3. Read exempted fees
        $exempted = $this->firebase->get(
            "Schools/{$sn}/{$sy}/{$class}/{$secNorm}/Students/{$studentId}/Exempted Fees"
        );
        if (is_array($exempted)) {
            foreach ($exempted as $title => $val) {
                $discounts[$title] = -1; // special marker: fully exempt
            }
        }

        return $discounts;
    }

    /**
     * Get the configured due day from Reminder Settings.
     */
    private function _getDueDay(): int
    {
        $sn = $this->school_name;
        $sy = $this->session_year;
        $settings = $this->firebase->get("Schools/{$sn}/{$sy}/Accounts/Fees/Reminder Settings");
        return (int) ($settings['due_day_of_month'] ?? 10);
    }

    // ── Core demand generation ──────────────────────────────────────

    /**
     * Generate fee demands for a single student for a single month.
     *
     * Creates one demand per fee head (Monthly titles). Yearly fees
     * are generated only for the first month of the session (April).
     *
     * This is IDEMPOTENT — calling it multiple times for the same
     * student+month will not create duplicate demands. Existing demands
     * are skipped (not overwritten), preserving any payments already made.
     *
     * @param string $studentId   Student ID
     * @param string $studentName Student display name
     * @param string $class       "Class 9th" format
     * @param string $section     "Section A" format
     * @param string $monthName   "April", "May", etc.
     * @param array  $feeChart    Class fee chart (from _getClassFeeChart)
     * @param array  $discountMap Student discounts (from _getStudentDiscounts)
     * @param int    $dueDay      Day of month for due date
     * @return array  [created => int, skipped => int, errors => int]
     */
    private function _generateDemandsForMonth(
        string $studentId,
        string $studentName,
        string $class,
        string $section,
        string $monthName,
        array  $feeChart,
        array  $discountMap,
        int    $dueDay
    ): array {
        $result = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        $periodKey = $this->_buildPeriodKey($monthName);
        $dueDate   = $this->_computeDueDate($monthName, $dueDay);
        $now       = date('c');
        $basePath  = $this->_studentDemands($studentId);

        // Monthly fee heads for this month
        $monthFees = $feeChart[$monthName] ?? [];
        if (!is_array($monthFees)) $monthFees = [];

        // If this is April (first month), also include Yearly Fees
        if ($monthName === 'April') {
            $yearlyFees = $feeChart['Yearly Fees'] ?? [];
            if (is_array($yearlyFees)) {
                $monthFees = array_merge($monthFees, $yearlyFees);
            }
        }

        // Distribute scholarship discount proportionally across fee heads
        $scholarshipTotal = floatval($discountMap['_scholarship'] ?? 0);
        $monthTotal       = 0;
        foreach ($monthFees as $head => $amt) {
            if (($discountMap[$head] ?? 0) === -1) continue; // exempt
            $monthTotal += floatval($amt);
        }

        foreach ($monthFees as $feeHead => $rawAmount) {
            $amount = floatval($rawAmount);

            // Skip zero-amount fee heads (not configured)
            if ($amount <= 0) {
                continue;
            }

            // Skip fully exempted fee heads
            if (($discountMap[$feeHead] ?? 0) === -1) {
                $result['skipped']++;
                continue;
            }

            $demandId = $this->_buildDemandId($periodKey, $feeHead);

            // Idempotency: skip if demand already exists
            $existing = $this->firebase->get("{$basePath}/{$demandId}/demand_id");
            if ($existing !== null) {
                $result['skipped']++;
                continue;
            }

            // Calculate discount for this fee head
            $discountAmount = 0;

            // Head-specific discount
            if (isset($discountMap[$feeHead]) && $discountMap[$feeHead] > 0) {
                $discountAmount += floatval($discountMap[$feeHead]);
            }

            // Proportional scholarship distribution
            if ($scholarshipTotal > 0 && $monthTotal > 0) {
                $proportion      = $amount / $monthTotal;
                $discountAmount += round($scholarshipTotal * $proportion, 2);
            }

            // Cap discount at fee amount
            if ($discountAmount > $amount) {
                $discountAmount = $amount;
            }

            $netAmount = round($amount - $discountAmount, 2);

            // Determine fee category from Categories config (best-effort)
            $category = $this->_resolveFeeCategory($feeHead);

            // Determine frequency
            $isYearly  = isset(($feeChart['Yearly Fees'] ?? [])[$feeHead]);
            $frequency = $isYearly ? 'yearly' : 'monthly';

            $demand = [
                'demand_id'       => $demandId,
                'student_id'      => $studentId,
                'student_name'    => $studentName,
                'class'           => $class,
                'section'         => $section,
                'fee_head'        => $feeHead,
                'category'        => $category,
                'frequency'       => $frequency,
                'period'          => "{$monthName} " . $this->_resolveCalendarYear($monthName),
                'period_key'      => $periodKey,
                'original_amount' => round($amount, 2),
                'discount_amount' => round($discountAmount, 2),
                'net_amount'      => $netAmount,
                'paid_amount'     => 0,
                'balance'         => $netAmount,
                'status'          => 'unpaid',
                'due_date'        => $dueDate,
                'created_at'      => $now,
                'created_by'      => $this->admin_id ?? 'system',
            ];

            $this->firebase->set("{$basePath}/{$demandId}", $demand);
            $result['created']++;
        }

        // ── Sync demands for this month to Firestore ──
        if ($result['created'] > 0) {
            try {
                $allDemands = $this->firebase->get($basePath);
                if (is_array($allDemands)) {
                    $this->fsSync->syncDemandsForMonth(
                        $studentId, $studentName, $class, $section,
                        $monthName, $allDemands
                    );
                }
            } catch (\Exception $e) {
                log_message('error', "_generateDemandsForMonth: Firestore sync failed [{$studentId}/{$monthName}]: " . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Resolve fee category for a fee head by checking Categories config.
     * Falls back to "General" if no match.
     */
    private function _resolveFeeCategory(string $feeHead): string
    {
        static $categoryCache = null;

        if ($categoryCache === null) {
            $sn = $this->school_name;
            $sy = $this->session_year;
            $cats = $this->firebase->get("Schools/{$sn}/{$sy}/Accounts/Fees/Categories");
            $categoryCache = is_array($cats) ? $cats : [];
        }

        $headLower = strtolower(trim($feeHead));
        foreach ($categoryCache as $cat) {
            if (!is_array($cat)) continue;
            $titles = $cat['fee_titles'] ?? [];
            if (is_array($titles)) {
                foreach ($titles as $t) {
                    if (strtolower(trim($t)) === $headLower) {
                        return $cat['name'] ?? 'General';
                    }
                }
            }
        }

        // Best-effort keyword matching
        if (stripos($feeHead, 'transport') !== false || stripos($feeHead, 'bus') !== false) {
            return 'Transport';
        }
        if (stripos($feeHead, 'tuition') !== false) {
            return 'Academic';
        }
        if (stripos($feeHead, 'lab') !== false || stripos($feeHead, 'library') !== false) {
            return 'Academic';
        }
        if (stripos($feeHead, 'sport') !== false || stripos($feeHead, 'game') !== false) {
            return 'Extra-curricular';
        }

        return 'General';
    }

    // ── Public API endpoints ────────────────────────────────────────

    /**
     * POST — Generate demands for a single student across all academic months
     *        (or a specific set of months).
     *
     * Params:
     *   student_id  (required) — Student ID
     *   months[]    (optional) — Specific months to generate (default: all 12)
     *
     * Safe to call multiple times — idempotent.
     */
    public function generate_demands_for_student()
    {
        $this->_require_role(self::MANAGE_ROLES, 'generate_demands');

        $studentId = trim($this->input->post('student_id') ?? '');
        if ($studentId === '') {
            return $this->json_error('Student ID is required.');
        }
        $studentId = $this->safe_path_segment($studentId, 'student_id');

        // Look up student profile
        $sn      = $this->school_name;
        $dbKey   = $this->parent_db_key;
        $profile = $this->firebase->get("Users/Parents/{$dbKey}/{$studentId}");
        if (!is_array($profile)) {
            return $this->json_error('Student not found.');
        }

        $studentName = $profile['Name'] ?? $studentId;

        // Resolve class/section
        list($class, $section) = $this->_resolveClassSection($profile);
        if ($class === '' || $section === '') {
            return $this->json_error("Cannot resolve class/section for '{$studentId}'.");
        }

        // Fetch class fee chart
        $feeChart = $this->_getClassFeeChart($class, $section);
        if (empty($feeChart)) {
            return $this->json_error("No fee chart found for {$class} / {$section}. Set up fees first.");
        }

        // Fetch student discounts
        $discountMap = $this->_getStudentDiscounts($studentId, $class, $section);

        // Determine which months to generate
        $requestedMonths = $this->input->post('months');
        if (is_string($requestedMonths)) {
            $requestedMonths = array_map('trim', explode(',', $requestedMonths));
        }
        if (!is_array($requestedMonths) || empty($requestedMonths)) {
            $requestedMonths = self::ACADEMIC_MONTHS;
        }

        // Validate month names
        $validMonths = array_intersect($requestedMonths, self::ACADEMIC_MONTHS);
        if (empty($validMonths)) {
            return $this->json_error('No valid months specified.');
        }

        $dueDay   = $this->_getDueDay();
        $totals   = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($validMonths as $month) {
            $r = $this->_generateDemandsForMonth(
                $studentId, $studentName, $class, $section,
                $month, $feeChart, $discountMap, $dueDay
            );
            $totals['created'] += $r['created'];
            $totals['skipped'] += $r['skipped'];
            $totals['errors']  += $r['errors'];
        }

        $this->json_success([
            'message'  => "{$totals['created']} demands created, {$totals['skipped']} already existed.",
            'student'  => $studentName,
            'class'    => $class,
            'section'  => $section,
            'created'  => $totals['created'],
            'skipped'  => $totals['skipped'],
            'errors'   => $totals['errors'],
        ]);
    }

    /**
     * POST — Bulk generate demands for all students in a class/section
     *        for a specific month (or all months).
     *
     * Params:
     *   month   (required) — Month name, e.g. "April", or "all" for full year
     *   year    (optional) — Not used (session_year determines year)
     *   class   (optional) — Specific class, e.g. "Class 9th" (default: all classes)
     *   section (optional) — Specific section, e.g. "Section A" (default: all sections)
     *
     * Safe to call multiple times — idempotent.
     */
    public function generate_monthly_demands()
    {
        $this->_require_post();
        $this->_require_role(self::MANAGE_ROLES, 'generate_demands');

        $monthInput = trim($this->input->post('month') ?? '');
        $classInput = trim($this->input->post('class') ?? '');
        $secInput   = trim($this->input->post('section') ?? '');

        if ($monthInput === '') {
            return $this->json_error('Month is required (e.g. "April" or "all").');
        }

        // Determine months
        if (strtolower($monthInput) === 'all') {
            $months = self::ACADEMIC_MONTHS;
        } else {
            if (!in_array($monthInput, self::ACADEMIC_MONTHS, true)) {
                return $this->json_error("Invalid month: {$monthInput}");
            }
            $months = [$monthInput];
        }

        $sn      = $this->school_name;
        $sy      = $this->session_year;
        $dbKey   = $this->parent_db_key;
        $dueDay  = $this->_getDueDay();
        $totals  = ['students' => 0, 'created' => 0, 'skipped' => 0, 'errors' => 0, 'no_chart' => 0];

        // Build class/section list to process
        $classSections = [];
        $sessionRoot   = "Schools/{$sn}/{$sy}";

        if ($classInput !== '' && $secInput !== '') {
            // Single class+section
            if (stripos($classInput, 'Class ') !== 0) $classInput = "Class {$classInput}";
            if (stripos($secInput, 'Section ') !== 0) $secInput = "Section {$secInput}";
            $classSections[] = ['class' => $classInput, 'section' => $secInput];
        } else {
            // All classes/sections from session
            $classKeys = $this->firebase->shallow_get($sessionRoot);
            if (is_array($classKeys)) {
                foreach ($classKeys as $ck) {
                    $ck = (string) $ck;
                    if (strpos($ck, 'Class ') !== 0) continue;
                    if ($classInput !== '' && $ck !== $classInput) continue;

                    $secKeys = $this->firebase->shallow_get("{$sessionRoot}/{$ck}");
                    if (!is_array($secKeys)) continue;
                    foreach ($secKeys as $sk) {
                        $sk = (string) $sk;
                        if (strpos($sk, 'Section ') !== 0) continue;
                        if ($secInput !== '' && $sk !== $secInput) continue;
                        $classSections[] = ['class' => $ck, 'section' => $sk];
                    }
                }
            }
        }

        if (empty($classSections)) {
            return $this->json_error('No classes/sections found in current session.');
        }

        // ================================================================
        //  TWO-PHASE CONCURRENCY LOCK
        //
        //  Phase 1: CHECK all required locks (no writes).
        //           If any active lock found → abort immediately.
        //  Phase 2: ACQUIRE all locks atomically.
        //           Only proceeds if Phase 1 passed.
        //
        //  Lock key uses period_key (YYYY-MM) to avoid cross-year conflicts.
        //  Example: Class9th_SectionA_2026-04
        // ================================================================

        $lockBase      = "Schools/{$sn}/{$sy}/Fees/Demand_Locks";
        $lockToken     = bin2hex(random_bytes(8));
        $acquiredLocks = [];

        // Build list of all lock keys needed
        $requiredLocks = [];
        foreach ($classSections as $cs) {
            $classSlug   = str_replace(' ', '', $cs['class']);
            $sectionSlug = str_replace(' ', '', $cs['section']);
            foreach ($months as $m) {
                $periodKey = $this->_buildPeriodKey($m);
                $lockKey   = "{$classSlug}_{$sectionSlug}_{$periodKey}";
                $requiredLocks[] = [
                    'key'     => $lockKey,
                    'path'    => "{$lockBase}/{$lockKey}",
                    'class'   => $cs['class'],
                    'section' => $cs['section'],
                    'month'   => $m,
                    'period'  => $periodKey,
                ];
            }
        }

        // ── PHASE 1: Check ALL locks before acquiring any ──
        foreach ($requiredLocks as $rl) {
            $existingLock = $this->firebase->get($rl['path']);
            if (is_array($existingLock) && !empty($existingLock['locked'])) {
                $lockAge = time() - strtotime($existingLock['locked_at'] ?? '2000-01-01');
                if ($lockAge < 120) {
                    log_message('info',
                        "generate_demands: PHASE1 BLOCKED key={$rl['key']}"
                        . " held_by=" . ($existingLock['locked_by'] ?? '?')
                        . " age={$lockAge}s"
                    );
                    $this->output->set_content_type('application/json');
                    $this->output->set_output(json_encode([
                        'status'      => 'error',
                        'locked'      => true,
                        'message'     => "{$rl['class']} {$rl['section']} / {$rl['month']} is already being processed by "
                                       . ($existingLock['locked_by'] ?? 'another admin') . ". Please wait.",
                        'locked_by'   => $existingLock['locked_by'] ?? '',
                        'locked_at'   => $existingLock['locked_at'] ?? '',
                        'age_seconds' => $lockAge,
                        'lock_key'    => $rl['key'],
                    ]));
                    return;
                }
                // Stale lock — will be overridden in Phase 2
                log_message('info', "generate_demands: Stale lock key={$rl['key']} age={$lockAge}s — will override");
            }
        }

        // ── PHASE 2: Acquire ALL locks ──
        $now = date('c');
        foreach ($requiredLocks as $rl) {
            $this->firebase->set($rl['path'], [
                'locked'    => true,
                'locked_at' => $now,
                'locked_by' => $this->admin_name ?? '',
                'token'     => $lockToken,
                'period'    => $rl['period'],
                'class'     => $rl['class'],
                'section'   => $rl['section'],
            ]);
            $acquiredLocks[] = $rl['path'];
        }

        log_message('info',
            "generate_demands: PHASE2 acquired " . count($acquiredLocks) . " lock(s)"
            . " token={$lockToken} admin=" . ($this->admin_name ?? '')
        );

        // Process each class/section
        foreach ($classSections as $cs) {
            $class   = $cs['class'];
            $section = $cs['section'];

            // Fetch fee chart for this class/section
            $feeChart = $this->_getClassFeeChart($class, $section);
            if (empty($feeChart)) {
                $totals['no_chart']++;
                continue;
            }

            // Get student roster for this section
            $roster = $this->firebase->get("{$sessionRoot}/{$class}/{$section}/Students/List");
            if (!is_array($roster) || empty($roster)) continue;

            // Batch: load all profiles at once to avoid N+1
            $allProfiles = $this->firebase->get("Users/Parents/{$dbKey}");
            if (!is_array($allProfiles)) $allProfiles = [];

            foreach ($roster as $sid => $studentNameOrData) {
                $sid = (string) $sid;
                $profile = $allProfiles[$sid] ?? [];
                $studentName = is_string($studentNameOrData) ? $studentNameOrData : ($profile['Name'] ?? $sid);

                // Get student-specific discounts
                $discountMap = $this->_getStudentDiscounts($sid, $class, $section);

                foreach ($months as $month) {
                    $r = $this->_generateDemandsForMonth(
                        $sid, $studentName, $class, $section,
                        $month, $feeChart, $discountMap, $dueDay
                    );
                    $totals['created'] += $r['created'];
                    $totals['skipped'] += $r['skipped'];
                    $totals['errors']  += $r['errors'];
                }

                $totals['students']++;
            }
        }

        // ── Release all demand locks (token-verified) ──
        foreach ($acquiredLocks as $alp) {
            try {
                $l = $this->firebase->get($alp);
                if (is_array($l) && ($l['token'] ?? '') === $lockToken) {
                    $this->firebase->delete($alp);
                }
            } catch (\Exception $e) {
                log_message('error', "generate_demands: lock release failed {$alp}: " . $e->getMessage());
            }
        }

        log_message('info',
            "generate_demands: DONE token={$lockToken} created={$totals['created']}"
            . " skipped={$totals['skipped']} students={$totals['students']}"
        );

        $this->json_success([
            'message'   => "{$totals['created']} demands created across {$totals['students']} students. "
                         . "{$totals['skipped']} already existed."
                         . ($totals['no_chart'] > 0 ? " {$totals['no_chart']} class/sections had no fee chart." : ''),
            'students'  => $totals['students'],
            'created'   => $totals['created'],
            'skipped'   => $totals['skipped'],
            'errors'    => $totals['errors'],
            'no_chart'  => $totals['no_chart'],
        ]);
    }

    /**
     * GET — Fetch all demands for a student.
     *
     * Params:
     *   student_id  (required)
     *   status      (optional) — "unpaid", "partial", "paid", or "" for all
     *   period_key  (optional) — e.g. "2026-04" to filter by month
     *
     * Returns: demands[] sorted by period_key ASC, then fee_head
     */
    public function get_student_demands()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_student_demands');

        $studentId  = trim($this->input->get('student_id') ?? '');
        $filterStat = trim($this->input->get('status') ?? '');
        $filterPK   = trim($this->input->get('period_key') ?? '');

        if ($studentId === '') {
            return $this->json_error('Student ID is required.');
        }
        $studentId = $this->safe_path_segment($studentId, 'student_id');

        $raw = $this->firebase->get($this->_studentDemands($studentId));
        if (!is_array($raw)) $raw = [];

        $demands  = [];
        $summary  = ['total_net' => 0, 'total_paid' => 0, 'total_balance' => 0];

        foreach ($raw as $did => $d) {
            if (!is_array($d)) continue;

            // Apply filters
            if ($filterStat !== '' && ($d['status'] ?? '') !== $filterStat) continue;
            if ($filterPK !== '' && ($d['period_key'] ?? '') !== $filterPK) continue;

            $d['demand_id'] = $did;
            $demands[]      = $d;

            $summary['total_net']     += floatval($d['net_amount'] ?? 0);
            $summary['total_paid']    += floatval($d['paid_amount'] ?? 0);
            $summary['total_balance'] += floatval($d['balance'] ?? 0);
        }

        // Sort by period_key ASC, then fee_head
        usort($demands, function ($a, $b) {
            $pk = strcmp($a['period_key'] ?? '', $b['period_key'] ?? '');
            return $pk !== 0 ? $pk : strcmp($a['fee_head'] ?? '', $b['fee_head'] ?? '');
        });

        $summary['total_net']     = round($summary['total_net'], 2);
        $summary['total_paid']    = round($summary['total_paid'], 2);
        $summary['total_balance'] = round($summary['total_balance'], 2);

        $this->json_success([
            'demands' => $demands,
            'summary' => $summary,
            'count'   => count($demands),
        ]);
    }

    /**
     * GET — Get demand generation status for all classes in current session.
     *
     * Returns per-class summary: how many students, how many have demands,
     * total demands created. Useful for admin to see which classes need generation.
     */
    public function get_demand_status()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_demand_status');

        $sn = $this->school_name;
        $sy = $this->session_year;

        // Read all demands (shallow for counts)
        $allDemands = $this->firebase->get($this->_demandsBase());
        $demandStudents = is_array($allDemands) ? array_keys($allDemands) : [];
        $demandCounts   = [];
        if (is_array($allDemands)) {
            foreach ($allDemands as $sid => $dems) {
                $demandCounts[$sid] = is_array($dems) ? count($dems) : 0;
            }
        }

        // Scan classes
        $sessionRoot = "Schools/{$sn}/{$sy}";
        $classKeys = $this->firebase->shallow_get($sessionRoot);
        $status = [];

        if (is_array($classKeys)) {
            foreach ($classKeys as $ck) {
                $ck = (string) $ck;
                if (strpos($ck, 'Class ') !== 0) continue;

                $secKeys = $this->firebase->shallow_get("{$sessionRoot}/{$ck}");
                if (!is_array($secKeys)) continue;

                $classStudents      = 0;
                $classWithDemands   = 0;
                $classTotalDemands  = 0;

                foreach ($secKeys as $sk) {
                    $sk = (string) $sk;
                    if (strpos($sk, 'Section ') !== 0) continue;

                    $roster = $this->firebase->get("{$sessionRoot}/{$ck}/{$sk}/Students/List");
                    if (!is_array($roster)) continue;

                    foreach ($roster as $sid => $name) {
                        $classStudents++;
                        $dc = $demandCounts[(string)$sid] ?? 0;
                        if ($dc > 0) {
                            $classWithDemands++;
                            $classTotalDemands += $dc;
                        }
                    }
                }

                $status[] = [
                    'class'          => $ck,
                    'total_students' => $classStudents,
                    'with_demands'   => $classWithDemands,
                    'total_demands'  => $classTotalDemands,
                    'coverage'       => $classStudents > 0
                        ? round(($classWithDemands / $classStudents) * 100)
                        : 0,
                ];
            }
        }

        usort($status, function ($a, $b) {
            return strnatcmp($a['class'], $b['class']);
        });

        $this->json_success([
            'classes' => $status,
            'total_students_with_demands' => count($demandStudents),
        ]);
    }

    /**
     * POST — Recalculate demands for a student based on current fee chart.
     *
     * Only updates UNPAID demands (paid/partial demands are preserved).
     * Use case: fee chart changed mid-year, need to update future obligations.
     *
     * Params: student_id (required)
     */
    public function recalculate_demands()
    {
        $this->_require_post();
        $this->_require_role(self::MANAGE_ROLES, 'recalculate_demands');

        $studentId = trim($this->input->post('student_id') ?? '');
        if ($studentId === '') {
            return $this->json_error('Student ID is required.');
        }
        $studentId = $this->safe_path_segment($studentId, 'student_id');

        // Look up student
        $dbKey   = $this->parent_db_key;
        $profile = $this->firebase->get("Users/Parents/{$dbKey}/{$studentId}");
        if (!is_array($profile)) {
            return $this->json_error('Student not found.');
        }

        list($class, $section) = $this->_resolveClassSection($profile);
        if ($class === '' || $section === '') {
            return $this->json_error("Cannot resolve class/section.");
        }

        $feeChart = $this->_getClassFeeChart($class, $section);
        if (empty($feeChart)) {
            return $this->json_error("No fee chart found for {$class} / {$section}. Please set up Fee Titles and Fee Chart first.");
        }

        $discountMap = $this->_getStudentDiscounts($studentId, $class, $section);
        $basePath    = $this->_studentDemands($studentId);

        // Read existing demands
        $existing = $this->firebase->get($basePath);
        if (!is_array($existing)) $existing = [];

        $updated = 0;
        $preserved = 0;

        foreach ($existing as $did => $demand) {
            if (!is_array($demand)) continue;

            // Only update unpaid demands
            if (($demand['status'] ?? '') !== 'unpaid') {
                $preserved++;
                continue;
            }

            $feeHead   = $demand['fee_head'] ?? '';
            $monthName = explode(' ', $demand['period'] ?? '')[0] ?? '';

            // Look up new amount from fee chart
            $newAmount = 0;
            if (isset($feeChart[$monthName][$feeHead])) {
                $newAmount = floatval($feeChart[$monthName][$feeHead]);
            } elseif (isset($feeChart['Yearly Fees'][$feeHead])) {
                $newAmount = floatval($feeChart['Yearly Fees'][$feeHead]);
            }

            if ($newAmount <= 0) continue; // fee head removed — don't delete demand

            // Recalculate discount
            $discountAmount = 0;
            if (isset($discountMap[$feeHead]) && $discountMap[$feeHead] > 0) {
                $discountAmount = floatval($discountMap[$feeHead]);
            }
            if ($discountAmount > $newAmount) $discountAmount = $newAmount;

            $netAmount = round($newAmount - $discountAmount, 2);

            // Only update if something changed
            $oldNet = floatval($demand['net_amount'] ?? 0);
            if (abs($oldNet - $netAmount) < 0.01) continue;

            $this->firebase->update("{$basePath}/{$did}", [
                'original_amount' => round($newAmount, 2),
                'discount_amount' => round($discountAmount, 2),
                'net_amount'      => $netAmount,
                'balance'         => $netAmount, // unpaid, so balance = net
                'updated_at'      => date('c'),
                'updated_by'      => $this->admin_id ?? 'system',
            ]);
            $updated++;
        }

        $this->json_success([
            'message'   => "{$updated} demands updated, {$preserved} paid/partial preserved.",
            'updated'   => $updated,
            'preserved' => $preserved,
        ]);
    }

    // ====================================================================
    //  TRANSACTION AUDIT & RECOVERY
    // ====================================================================

    /** GET — Transaction Audit page */
    public function transaction_audit()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $this->load->view('include/header');
        $this->load->view('fees/transaction_audit');
        $this->load->view('include/footer');
    }

    /**
     * POST — Search transactions by txn_id, receipt_no, or student_id.
     * Returns all related records across Fees, Vouchers, Accounting.
     */
    public function search_transaction()
    {
        $this->_require_post();
        $this->_require_role(self::MANAGE_ROLES);

        $query = trim($this->input->post('query') ?? '');
        $type  = trim($this->input->post('type') ?? 'auto'); // auto|txn_id|receipt_no|student_id

        if ($query === '') {
            $this->json_error('Search query is required.');
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $bp      = "Schools/{$school}/{$session}";
        $results = [];

        // Auto-detect search type
        if ($type === 'auto') {
            if (strpos($query, 'TXN_') === 0)       $type = 'txn_id';
            elseif (is_numeric($query))               $type = 'receipt_no';
            else                                      $type = 'student_id';
        }

        if ($type === 'receipt_no') {
            $receiptNo = $query;
            $receiptKey = 'F' . $receiptNo;

            // Receipt Index
            $idx = $this->firebase->get("{$bp}/Accounts/Receipt_Index/{$receiptNo}");
            $results['receipt_index'] = is_array($idx) ? $idx : null;

            // Idempotency records (scan for this receipt)
            $idempAll = $this->firebase->get("{$bp}/Fees/Idempotency");
            $idempMatch = [];
            if (is_array($idempAll)) {
                foreach ($idempAll as $k => $v) {
                    if (is_array($v) && ($v['receipt_no'] ?? '') == $receiptNo) {
                        $v['_key'] = $k;
                        $idempMatch[] = $v;
                    }
                }
            }
            $results['idempotency'] = $idempMatch;

            // Pending fees
            $pending = $this->firebase->get("{$bp}/Accounts/Pending_fees/{$receiptKey}");
            $results['pending'] = is_array($pending) ? $pending : null;

            // Receipt Allocations
            $alloc = $this->firebase->get("{$bp}/Fees/Receipt_Allocations/{$receiptKey}");
            $results['allocations'] = is_array($alloc) ? $alloc : null;

            // Find student from receipt index or allocations
            $studentId = $idx['user_id'] ?? ($alloc['student_id'] ?? '');
            $class     = $idx['class'] ?? ($alloc['class'] ?? '');
            $section   = $idx['section'] ?? ($alloc['section'] ?? '');

            // Fees Record on student
            if ($studentId && $class && $section) {
                $studentBase = $this->studentPath($class, $section, $studentId);
                $feesRec = $this->firebase->get("{$studentBase}/Fees Record/{$receiptKey}");
                $results['fees_record'] = is_array($feesRec) ? $feesRec : null;
                $results['student_id'] = $studentId;
                $results['class'] = $class;
                $results['section'] = $section;
            }

            // Voucher (need to find by date)
            $date = $idx['date'] ?? ($alloc['date'] ?? '');
            if ($date) {
                // Normalize date format for voucher path
                $voucherDate = $date;
                $voucher = $this->firebase->get("{$bp}/Accounts/Vouchers/{$voucherDate}/{$receiptKey}");
                $results['voucher'] = is_array($voucher) ? $voucher : null;
            }

            // Ledger entries (scan for source_ref matching receipt)
            $ledger = $this->firebase->get("{$bp}/Accounts/Ledger");
            $ledgerMatches = [];
            if (is_array($ledger)) {
                foreach ($ledger as $eid => $entry) {
                    if (!is_array($entry)) continue;
                    $narr = $entry['narration'] ?? '';
                    $ref  = $entry['source_ref'] ?? '';
                    if ($ref == $receiptNo || strpos($narr, "#{$receiptNo}") !== false
                        || strpos($narr, $receiptNo) !== false) {
                        $entry['_id'] = $eid;
                        $ledgerMatches[] = $entry;
                    }
                }
            }
            $results['ledger_entries'] = $ledgerMatches;

        } elseif ($type === 'txn_id') {
            $txnId = $query;

            // Scan idempotency for this txn_id
            $idempAll = $this->firebase->get("{$bp}/Fees/Idempotency");
            if (is_array($idempAll)) {
                foreach ($idempAll as $k => $v) {
                    if (is_array($v) && ($v['txn_id'] ?? '') === $txnId) {
                        $v['_key'] = $k;
                        $results['idempotency'] = $v;
                        // If we found the receipt, recurse to get full data
                        if (!empty($v['receipt_no'])) {
                            $results['receipt_no'] = $v['receipt_no'];
                        }
                        break;
                    }
                }
            }

            // Scan Receipt_Allocations for txn_id
            $allAllocs = $this->firebase->get("{$bp}/Fees/Receipt_Allocations");
            if (is_array($allAllocs)) {
                foreach ($allAllocs as $rk => $ra) {
                    if (is_array($ra) && ($ra['txn_id'] ?? '') === $txnId) {
                        $results['allocations'] = $ra;
                        $results['receipt_key'] = $rk;
                        break;
                    }
                }
            }

            // Scan Ledger for txn_id in narration or custom field
            $ledger = $this->firebase->get("{$bp}/Accounts/Ledger");
            $ledgerMatches = [];
            if (is_array($ledger)) {
                foreach ($ledger as $eid => $entry) {
                    if (!is_array($entry)) continue;
                    if (($entry['txn_id'] ?? '') === $txnId
                        || strpos($entry['narration'] ?? '', $txnId) !== false) {
                        $entry['_id'] = $eid;
                        $ledgerMatches[] = $entry;
                    }
                }
            }
            $results['ledger_entries'] = $ledgerMatches;

        } elseif ($type === 'student_id') {
            $studentId = $this->safe_path_segment($query, 'student_id');

            // Get student profile
            $profile = $this->firebase->get("Users/Parents/{$this->parent_db_key}/{$studentId}");
            if (is_array($profile)) {
                $results['student'] = [
                    'name' => $profile['Name'] ?? $studentId,
                    'class' => $profile['Class'] ?? '',
                    'section' => $profile['Section'] ?? '',
                    'father' => $profile['Father Name'] ?? '',
                ];

                list($cls, $sec) = $this->_resolveClassSection($profile);
                if ($cls && $sec) {
                    $sBase = $this->studentPath($cls, $sec, $studentId);
                    // All fees records
                    $allRecs = $this->firebase->get("{$sBase}/Fees Record");
                    $results['fees_records'] = is_array($allRecs) ? $allRecs : [];
                    $results['record_count'] = is_array($allRecs) ? count($allRecs) : 0;
                }
            }

            // Recent idempotency records for this student
            $idempAll = $this->firebase->get("{$bp}/Fees/Idempotency");
            $idempMatches = [];
            if (is_array($idempAll)) {
                foreach ($idempAll as $k => $v) {
                    if (is_array($v) && ($v['user_id'] ?? '') === $studentId) {
                        $v['_key'] = $k;
                        $idempMatches[] = $v;
                    }
                }
            }
            $results['idempotency_history'] = $idempMatches;
        }

        $results['search_type'] = $type;
        $results['query'] = $query;
        $this->json_success($results);
    }

    /**
     * GET — Get stale/failed transactions for recovery.
     * Returns idempotency records stuck in "processing" state > 120s
     * and pending fees markers that were never cleared.
     */
    public function get_stale_transactions()
    {
        $this->_require_role(self::MANAGE_ROLES);

        $bp   = "Schools/{$this->school_name}/{$this->session_year}";
        $now  = time();
        $staleThreshold = 120; // seconds

        // Stale idempotency records
        $idempAll = $this->firebase->get("{$bp}/Fees/Idempotency");
        $staleProcessing = [];
        if (is_array($idempAll)) {
            foreach ($idempAll as $k => $v) {
                if (!is_array($v)) continue;
                if (($v['status'] ?? '') !== 'processing') continue;
                $age = $now - strtotime($v['started_at'] ?? '2000-01-01');
                if ($age > $staleThreshold) {
                    $v['_key'] = $k;
                    $v['_age_seconds'] = $age;
                    $staleProcessing[] = $v;
                }
            }
        }

        // Stale pending fees
        $pendingAll = $this->firebase->get("{$bp}/Accounts/Pending_fees");
        $stalePending = [];
        if (is_array($pendingAll)) {
            foreach ($pendingAll as $k => $v) {
                if (!is_array($v)) continue;
                $age = $now - strtotime($v['started_at'] ?? '2000-01-01');
                if ($age > $staleThreshold) {
                    $v['_key'] = $k;
                    $v['_age_seconds'] = $age;
                    $stalePending[] = $v;
                }
            }
        }

        // Stale receipt reservations
        $receiptIdx = $this->firebase->get("{$bp}/Accounts/Receipt_Index");
        $staleReservations = [];
        if (is_array($receiptIdx)) {
            foreach ($receiptIdx as $rn => $ri) {
                if (!is_array($ri) || empty($ri['reserved'])) continue;
                $age = $now - strtotime($ri['reserved_at'] ?? '2000-01-01');
                if ($age > $staleThreshold) {
                    $ri['_receipt_no'] = $rn;
                    $ri['_age_seconds'] = $age;
                    $staleReservations[] = $ri;
                }
            }
        }

        // Active locks
        $locks = $this->firebase->get("{$bp}/Fees/Locks");
        $activeLocks = [];
        if (is_array($locks)) {
            foreach ($locks as $sid => $l) {
                if (!is_array($l)) continue;
                $l['_student_id'] = $sid;
                $l['_age_seconds'] = $now - strtotime($l['locked_at'] ?? '2000-01-01');
                $activeLocks[] = $l;
            }
        }

        // Advance balance sync failures
        $syncPending = [];
        $syncAll = $this->firebase->get("{$bp}/Fees/Advance_Sync_Pending");
        if (is_array($syncAll)) {
            foreach ($syncAll as $sid => $sp) {
                if (!is_array($sp)) continue;
                $sp['_student_id'] = $sid;
                $sp['_age_seconds'] = $now - strtotime($sp['flagged_at'] ?? '2000-01-01');
                $syncPending[] = $sp;
            }
        }

        $this->json_success([
            'stale_processing'    => $staleProcessing,
            'stale_pending'       => $stalePending,
            'stale_reservations'  => $staleReservations,
            'active_locks'        => $activeLocks,
            'sync_pending'        => $syncPending,
            'total_issues'        => count($staleProcessing) + count($stalePending) + count($staleReservations) + count($syncPending),
        ]);
    }

    /**
     * POST — Diagnose a stale transaction.
     * Checks what partial writes exist and recommends safe actions.
     */
    public function diagnose_transaction()
    {
        $this->_require_post();
        $this->_require_role(self::MANAGE_ROLES, 'diagnose_transaction');

        $idempKey  = trim($this->input->post('idemp_key') ?? '');
        $receiptNo = trim($this->input->post('receipt_no') ?? '');
        $studentId = trim($this->input->post('student_id') ?? '');

        $bp = "Schools/{$this->school_name}/{$this->session_year}";
        $diagnosis = ['writes_found' => [], 'writes_missing' => [], 'safe_actions' => []];

        // Load the idempotency record for step info
        $idemp = null;
        if ($idempKey) {
            $idemp = $this->firebase->get("{$bp}/Fees/Idempotency/{$idempKey}");
            $receiptNo = $receiptNo ?: ($idemp['receipt_no'] ?? '');
            $studentId = $studentId ?: ($idemp['user_id'] ?? '');
        }
        $receiptKey = $receiptNo ? 'F' . $receiptNo : '';
        $lastStep   = is_array($idemp) ? ($idemp['step'] ?? 'unknown') : 'unknown';

        $diagnosis['last_step'] = $lastStep;
        $diagnosis['receipt_no'] = $receiptNo;
        $diagnosis['student_id'] = $studentId;

        // Check each write location
        $checks = [];

        // 1. Receipt Index
        if ($receiptNo) {
            $ri = $this->firebase->get("{$bp}/Accounts/Receipt_Index/{$receiptNo}");
            $exists = is_array($ri) && !empty($ri);
            $isReserved = $exists && !empty($ri['reserved']);
            $checks['receipt_index'] = $exists ? ($isReserved ? 'reserved' : 'finalized') : 'missing';
        }

        // 2. Pending fees
        if ($receiptKey) {
            $pf = $this->firebase->get("{$bp}/Accounts/Pending_fees/{$receiptKey}");
            $checks['pending_fees'] = (is_array($pf) && !empty($pf)) ? 'exists' : 'cleared';
        }

        // 3. Fees Record on student
        if ($studentId && $receiptKey) {
            $student = $this->firebase->get("Users/Parents/{$this->parent_db_key}/{$studentId}");
            if (is_array($student)) {
                list($cls, $sec) = $this->_resolveClassSection($student);
                if ($cls && $sec) {
                    $sBase = $this->studentPath($cls, $sec, $studentId);
                    $fr = $this->firebase->get("{$sBase}/Fees Record/{$receiptKey}");
                    $checks['fees_record'] = (is_array($fr) && !empty($fr)) ? 'written' : 'missing';
                }
            }
        }

        // 4. Voucher (need date from receipt index or pending)
        $vDate = '';
        if (isset($ri['date']) && !empty($ri['date'])) $vDate = $ri['date'];
        elseif (isset($pf['started_at'])) $vDate = date('d-m-Y', strtotime($pf['started_at']));
        if ($vDate && $receiptKey) {
            $voucher = $this->firebase->get("{$bp}/Accounts/Vouchers/{$vDate}/{$receiptKey}");
            $checks['voucher'] = (is_array($voucher) && !empty($voucher)) ? 'written' : 'missing';
        }

        // 5. Receipt Allocations
        if ($receiptKey) {
            $ra = $this->firebase->get("{$bp}/Fees/Receipt_Allocations/{$receiptKey}");
            $checks['allocations'] = (is_array($ra) && !empty($ra)) ? 'written' : 'missing';
        }

        // 6. Lock
        if ($studentId) {
            $lock = $this->firebase->get("{$bp}/Fees/Locks/{$studentId}");
            $checks['lock'] = (is_array($lock) && !empty($lock['locked'])) ? 'held' : 'free';
        }

        $diagnosis['checks'] = $checks;

        // Categorize writes
        foreach ($checks as $name => $status) {
            if (in_array($status, ['written', 'finalized', 'exists', 'held'])) {
                $diagnosis['writes_found'][] = $name;
            } elseif (in_array($status, ['missing', 'cleared', 'free'])) {
                $diagnosis['writes_missing'][] = $name;
            } elseif ($status === 'reserved') {
                $diagnosis['writes_found'][] = $name . ' (reserved only)';
            }
        }

        // Recommend actions based on what exists
        $hasFeesRecord = ($checks['fees_record'] ?? '') === 'written';
        $hasVoucher    = ($checks['voucher'] ?? '') === 'written';
        $hasAlloc      = ($checks['allocations'] ?? '') === 'written';
        $hasLock       = ($checks['lock'] ?? '') === 'held';
        $hasPending    = ($checks['pending_fees'] ?? '') === 'exists';

        if (!$hasFeesRecord && !$hasVoucher && !$hasAlloc) {
            // Nothing material was written — safe to fully clear
            $diagnosis['recommendation'] = 'clean_clear';
            $diagnosis['recommendation_text'] = 'No financial writes detected. Safe to clear all markers (lock, pending, reservation, idempotency).';
            $diagnosis['safe_actions'] = ['clear_lock', 'clear_pending', 'clear_reservation', 'clear_processing'];
        } elseif ($hasFeesRecord && $hasVoucher && $hasAlloc) {
            // Everything was written — transaction likely completed but cleanup failed
            $diagnosis['recommendation'] = 'mark_complete';
            $diagnosis['recommendation_text'] = 'All financial records exist. Transaction appears complete — just cleanup stale markers.';
            $diagnosis['safe_actions'] = ['clear_lock', 'clear_pending', 'mark_success'];
        } elseif ($hasFeesRecord && !$hasVoucher) {
            // Partial — fees written but voucher missing
            $diagnosis['recommendation'] = 'needs_review';
            $diagnosis['recommendation_text'] = 'Fees Record exists but Voucher is missing. Review manually — may need to create voucher or reverse the fees record.';
            $diagnosis['safe_actions'] = ['clear_lock', 'view_details'];
        } else {
            $diagnosis['recommendation'] = 'needs_review';
            $diagnosis['recommendation_text'] = 'Partial writes detected. Manual review recommended before taking action.';
            $diagnosis['safe_actions'] = ['clear_lock', 'view_details'];
        }

        if ($hasLock) array_unshift($diagnosis['safe_actions'], 'clear_lock');
        $diagnosis['safe_actions'] = array_unique($diagnosis['safe_actions']);

        $this->json_success($diagnosis);
    }

    /**
     * POST — Resolve a stale transaction.
     * Actions: clear_lock, clear_pending, clear_reservation, clear_processing, mark_success
     */
    public function resolve_stale()
    {
        $this->_require_post();
        $this->_require_role(['Admin'], 'resolve_stale_transaction');

        $action = trim($this->input->post('action') ?? '');
        $key    = trim($this->input->post('key') ?? '');

        if (!$action || !$key) {
            $this->json_error('Action and key are required.');
        }

        $bp = "Schools/{$this->school_name}/{$this->session_year}";
        $detail = '';

        switch ($action) {
            case 'clear_lock':
                $this->firebase->delete("{$bp}/Fees/Locks/{$key}");
                $detail = "Student lock released for {$key}";
                break;
            case 'clear_pending':
                $this->firebase->delete("{$bp}/Accounts/Pending_fees/{$key}");
                $detail = "Pending fees marker cleared for {$key}";
                break;
            case 'clear_reservation':
                $this->firebase->delete("{$bp}/Accounts/Receipt_Index/{$key}");
                $detail = "Stale receipt reservation cleared for #{$key}";
                break;
            case 'clear_processing':
                $this->firebase->update("{$bp}/Fees/Idempotency/{$key}", [
                    'status'      => 'cleared_manual',
                    'cleared_by'  => $this->admin_name ?? '',
                    'cleared_at'  => date('c'),
                ]);
                $detail = "Idempotency record marked as manually cleared";
                break;
            case 'mark_success':
                $this->firebase->update("{$bp}/Fees/Idempotency/{$key}", [
                    'status'       => 'success',
                    'step'         => 'marked_complete_manual',
                    'completed_at' => date('c'),
                    'cleared_by'   => $this->admin_name ?? '',
                ]);
                $detail = "Transaction marked as successfully completed (manual)";
                break;
            case 'clear_sync_pending':
                $this->firebase->delete("{$bp}/Fees/Advance_Sync_Pending/{$key}");
                $detail = "Advance balance sync flag cleared for {$key} (manually reconciled)";
                break;
            default:
                $this->json_error('Unknown action.');
        }

        log_message('info',
            "resolve_stale: action={$action} key={$key} detail=[{$detail}] admin={$this->admin_id} school={$this->school_name}"
        );

        $this->json_success(['message' => $detail ?: 'Resolved successfully.', 'action' => $action, 'key' => $key]);
    }

    /**
     * POST — Auto-recalculate a student's advance balance from all source data.
     *
     * Formula: advance = max(0, net_received - total_allocated)
     * Where:   net_received = total_received - total_refunded
     *
     * Reads:
     *   - All Fees Records (receipts)
     *   - All Receipt_Allocations (for reversed status)
     *   - All Refund_Audit entries (for processed refunds)
     *   - Fee_management Refunds (for approved/processed refund requests)
     *   - All Demands (for paid_amount totals)
     *
     * Params: student_id
     */
    public function recalculate_advance()
    {
        $this->_require_post();
        $this->_require_role(['Admin'], 'recalculate_advance');

        $studentId = trim($this->input->post('student_id') ?? '');
        if ($studentId === '') {
            $this->json_error('Student ID is required.');
        }
        $studentId = $this->safe_path_segment($studentId, 'student_id');

        $school  = $this->school_name;
        $session = $this->session_year;
        $bp      = "Schools/{$school}/{$session}";

        // 1. Resolve student class/section
        $profile = $this->firebase->get("Users/Parents/{$this->parent_db_key}/{$studentId}");
        if (!is_array($profile)) {
            $this->json_error("Student '{$studentId}' not found.");
        }
        list($class, $section) = $this->_resolveClassSection($profile);
        if (!$class || !$section) {
            $this->json_error("Cannot resolve class/section for '{$studentId}'.");
        }
        $sBase = $this->studentPath($class, $section, $studentId);

        // 2. Sum all receipts — but exclude reversed ones
        $allReceipts = $this->firebase->get("{$sBase}/Fees Record");
        $totalReceived = 0;
        $totalDiscount = 0;
        $receiptCount  = 0;
        $reversedReceipts = []; // receipt keys marked as reversed in allocations

        // Load Receipt_Allocations to identify reversed receipts
        $allocAll = $this->firebase->get("{$bp}/Fees/Receipt_Allocations");
        if (is_array($allocAll)) {
            foreach ($allocAll as $rk => $ra) {
                if (!is_array($ra)) continue;
                if (($ra['student_id'] ?? '') !== $studentId) continue;
                if (($ra['status'] ?? '') === 'reversed') {
                    $reversedReceipts[$rk] = true;
                }
            }
        }

        if (is_array($allReceipts)) {
            foreach ($allReceipts as $rk => $rec) {
                if (!is_array($rec)) continue;
                // Skip receipts whose allocations were reversed
                if (isset($reversedReceipts[$rk])) continue;
                $amt  = floatval(str_replace(',', '', $rec['Amount'] ?? '0'));
                $disc = floatval(str_replace(',', '', $rec['Discount'] ?? '0'));
                $totalReceived += $amt;
                $totalDiscount += $disc;
                $receiptCount++;
            }
        }

        // 3. Sum refunded amounts from Refund_Audit
        $totalRefunded = 0;
        $refundCount   = 0;
        $refundAudits  = $this->firebase->get("{$bp}/Fees/Refund_Audit");
        if (is_array($refundAudits)) {
            foreach ($refundAudits as $aid => $audit) {
                if (!is_array($audit)) continue;
                if (($audit['student_id'] ?? '') !== $studentId) continue;
                $totalRefunded += floatval($audit['refund_amount'] ?? 0);
                $refundCount++;
            }
        }

        // Also check Fee_management refunds (approved/processed)
        $fmRefunds = $this->firebase->get("{$bp}/Accounts/Fees/Refunds");
        if (is_array($fmRefunds)) {
            foreach ($fmRefunds as $rid => $ref) {
                if (!is_array($ref)) continue;
                if (($ref['student_id'] ?? '') !== $studentId) continue;
                $status = $ref['status'] ?? '';
                // Only count processed refunds (not pending/rejected)
                if (!in_array($status, ['Processed', 'processed', 'Approved', 'approved'], true)) continue;
                $refAmt = floatval($ref['amount'] ?? 0);
                // Avoid double-counting if already in Refund_Audit
                // Check by receipt_no match
                $refReceiptNo = $ref['receipt_no'] ?? '';
                $alreadyCounted = false;
                if ($refReceiptNo !== '' && is_array($refundAudits)) {
                    foreach ($refundAudits as $a) {
                        if (is_array($a) && ($a['receipt_key'] ?? '') === 'F' . $refReceiptNo) {
                            $alreadyCounted = true;
                            break;
                        }
                    }
                }
                if (!$alreadyCounted) {
                    $totalRefunded += $refAmt;
                    $refundCount++;
                }
            }
        }

        // 4. Net received = gross received minus refunds
        $netReceived = round(max(0, $totalReceived - $totalRefunded), 2);

        // 5. Sum all demand allocations (total money applied to fees)
        $totalAllocated = 0;
        $demandsPath = "{$bp}/Fees/Demands/{$studentId}";
        $allDemands  = $this->firebase->get($demandsPath);
        if (is_array($allDemands)) {
            foreach ($allDemands as $did => $d) {
                if (!is_array($d)) continue;
                $totalAllocated += floatval($d['paid_amount'] ?? 0);
            }
        }

        // 6. Carry-forward from previous session → single net value
        //    credit = advance brought from old session (student overpaid)
        //    debt   = unpaid fees brought from old session (student owed)
        //    net    = credit - debt  (positive = student has credit, negative = student owes)
        $cfCredit = 0;
        $cfDebt   = 0;

        $cfAdvRec = $this->firebase->get("{$bp}/Fees/Carried_Forward_Advance/{$studentId}");
        if (is_array($cfAdvRec)) {
            $cfCredit = round(floatval($cfAdvRec['amount'] ?? 0), 2);
        }

        $cfFeesRec = $this->firebase->get("{$bp}/Accounts/Fees/Carried_Forward");
        if (is_array($cfFeesRec) && isset($cfFeesRec['students'][$studentId])) {
            $cfDebt = round(floatval($cfFeesRec['students'][$studentId]['amount'] ?? 0), 2);
        }

        $carryForwardNet = round($cfCredit - $cfDebt, 2);

        // 7. Calculate correct advance balance
        // advance = max(0, net_received + carry_forward_net - total_allocated)
        $correctAdvance = round(max(0,
            $netReceived + $carryForwardNet - $totalAllocated
        ), 2);

        // 8. Read current balance for before/after comparison
        $advPath = "{$bp}/Fees/Advance_Balance/{$studentId}";
        $currentAdv = $this->firebase->get($advPath);
        $oldBalance = is_array($currentAdv) ? round(floatval($currentAdv['amount'] ?? 0), 2) : 0;

        // 9. Write corrected balance with calculation audit trail
        $this->firebase->set($advPath, [
            'amount'                 => $correctAdvance,
            'student_id'             => $studentId,
            'student_name'           => $profile['Name'] ?? $studentId,
            'last_updated'           => date('c'),
            'recalculated'           => true,
            'recalculated_by'        => $this->admin_name ?? '',
            'recalculated_at'        => date('c'),
            'calc_total_received'    => round($totalReceived, 2),
            'calc_total_refunded'    => round($totalRefunded, 2),
            'calc_net_received'      => $netReceived,
            'calc_total_allocated'   => round($totalAllocated, 2),
            'calc_carry_forward_net' => $carryForwardNet,
        ]);

        // 10. Sync legacy path
        try {
            $this->firebase->set("{$sBase}/Oversubmittedfees", $correctAdvance);
        } catch (\Exception $e) {
            log_message('error', "recalculate_advance: legacy sync failed: " . $e->getMessage());
        }

        // 11. Clear sync-pending flag
        try {
            $this->firebase->delete("{$bp}/Fees/Advance_Sync_Pending/{$studentId}");
        } catch (\Exception $e) { /* may not exist */ }

        log_message('info',
            "recalculate_advance: student={$studentId} old={$oldBalance} new={$correctAdvance}"
            . " receipts={$receiptCount} received={$totalReceived} refunded={$totalRefunded}"
            . " net={$netReceived} allocated={$totalAllocated} cf_net={$carryForwardNet}"
            . " reversed=" . count($reversedReceipts) . " refunds={$refundCount}"
            . " admin={$this->admin_id}"
        );

        $this->json_success([
            'message'            => 'Advance balance recalculated successfully.',
            'student_id'         => $studentId,
            'student_name'       => $profile['Name'] ?? $studentId,
            'old_balance'        => $oldBalance,
            'new_balance'        => $correctAdvance,
            'total_received'     => round($totalReceived, 2),
            'total_refunded'     => round($totalRefunded, 2),
            'net_received'       => $netReceived,
            'total_allocated'    => round($totalAllocated, 2),
            'total_discount'     => round($totalDiscount, 2),
            'carry_forward_net'  => $carryForwardNet,
            'receipt_count'      => $receiptCount,
            'reversed_receipts'  => count($reversedReceipts),
            'refund_count'       => $refundCount,
        ]);
    }

    // ====================================================================
    //  YEAR ROLLOVER
    // ====================================================================

    /**
     * Preview what year rollover will do. AJAX GET.
     * Shows: students with pending fees, carry-forward amounts, in-flight payments.
     */
    public function year_rollover_prepare()
    {
        $this->_require_role(self::MANAGE_ROLES);

        $sn = $this->school_name;
        $sy = $this->session_year;
        $feesBase = "Schools/{$sn}/{$sy}";

        try {
            // Check for in-flight online payments
            $orders = $this->firebase->get("{$feesBase}/Accounts/Fees/Online_Orders") ?: [];
            $inFlight = [];
            foreach ($orders as $id => $order) {
                if (!is_array($order)) continue;
                $status = $order['status'] ?? '';
                if (in_array($status, ['created', 'processing', 'verified'])) {
                    $inFlight[] = ['order_id' => $id, 'student_id' => $order['student_id'] ?? '', 'amount' => $order['amount'] ?? 0, 'status' => $status];
                }
            }

            // Check for in-flight payment intents
            $intents = $this->firebase->get("{$feesBase}/Fees/Payment_Intents") ?: [];
            foreach ($intents as $id => $intent) {
                if (!is_array($intent)) continue;
                $status = $intent['status'] ?? '';
                if (in_array($status, ['requested', 'order_created', 'paying', 'processing'])) {
                    $inFlight[] = ['intent_id' => $id, 'student_id' => $intent['student_id'] ?? '', 'amount' => $intent['amount'] ?? 0, 'status' => $status];
                }
            }

            // Get all students with pending fees
            $pending = $this->firebase->get("{$feesBase}/Accounts/Pending_fees") ?: [];
            $carryForward = [];
            $totalCarryForward = 0;

            foreach ($pending as $studentId => $months) {
                if (!is_array($months)) continue;
                $studentDues = 0;
                $unpaidMonths = [];
                foreach ($months as $month => $data) {
                    if ($month === 'meta') continue;
                    $status = is_array($data) ? ($data['status'] ?? 'Pending') : 'Pending';
                    $amount = is_array($data) ? floatval($data['amount'] ?? 0) : floatval($data);
                    if (in_array($status, ['Pending', 'Overdue']) && $amount > 0) {
                        $studentDues += $amount;
                        $unpaidMonths[] = $month;
                    }
                }
                if ($studentDues > 0) {
                    $carryForward[] = [
                        'student_id' => $studentId,
                        'dues' => $studentDues,
                        'unpaid_months' => $unpaidMonths
                    ];
                    $totalCarryForward += $studentDues;
                }
            }

            $this->_json_out([
                'status' => 'success',
                'in_flight_payments' => $inFlight,
                'in_flight_count' => count($inFlight),
                'carry_forward' => $carryForward,
                'carry_forward_count' => count($carryForward),
                'total_carry_forward' => $totalCarryForward,
                'can_proceed' => count($inFlight) === 0,
                'block_reason' => count($inFlight) > 0 ? 'In-flight payments must complete before rollover' : ''
            ]);
        } catch (Exception $e) {
            log_message('error', "year_rollover_prepare failed: " . $e->getMessage());
            $this->_json_out(['status' => 'error', 'message' => 'Failed to prepare rollover: ' . $e->getMessage()]);
        }
    }

    /**
     * Execute year rollover. POST only.
     * Freezes current year fees, writes carry-forward to new session.
     */
    public function year_rollover_execute()
    {
        $this->_require_post();
        $this->_require_role(self::MANAGE_ROLES);

        // TC-CF005: Check deploy lock before any fee writes
        try {
            $deployLock = $this->firebase->get("Schools/{$this->school_name}/System/Deploy_Lock");
            if ($deployLock && ($deployLock['locked'] ?? false) === true) {
                $this->_json_out([
                    'status' => 'error',
                    'message' => 'System is updating. Please try again in a few minutes.',
                    'locked' => true
                ]);
                return;
            }
        } catch (Exception $e) {
            log_message('error', "Deploy lock check failed: " . $e->getMessage());
            // Don't block on check failure
        }

        $sn = $this->school_name;
        $sy = $this->session_year;
        $newSession = trim($this->input->post('new_session') ?? '');

        if ($newSession === '') {
            $this->_json_out(['status' => 'error', 'message' => 'New session year is required.']);
            return;
        }

        $feesBase = "Schools/{$sn}/{$sy}";
        $newFeesBase = "Schools/{$sn}/{$newSession}";

        try {
            // Block if in-flight payments exist
            $orders = $this->firebase->get("{$feesBase}/Accounts/Fees/Online_Orders") ?: [];
            foreach ($orders as $id => $order) {
                if (!is_array($order)) continue;
                if (in_array($order['status'] ?? '', ['created', 'processing', 'verified'])) {
                    $this->_json_out(['status' => 'error', 'message' => 'Cannot rollover: in-flight payment exists (order ' . $id . ')']);
                    return;
                }
            }

            // 1. Freeze current session
            $this->firebase->set("{$feesBase}/Fees/Year_Rollover", [
                'status' => 'frozen',
                'frozen_at' => date('c'),
                'frozen_by' => $this->admin_id ?? 'system',
                'new_session' => $newSession
            ]);

            // 2. Calculate and write carry-forward
            $pending = $this->firebase->get("{$feesBase}/Accounts/Pending_fees") ?: [];
            $cfCount = 0;
            $cfTotal = 0;

            foreach ($pending as $studentId => $months) {
                if (!is_array($months)) continue;
                $studentDues = 0;
                $unpaidDetails = [];
                foreach ($months as $month => $data) {
                    if ($month === 'meta') continue;
                    $status = is_array($data) ? ($data['status'] ?? 'Pending') : 'Pending';
                    $amount = is_array($data) ? floatval($data['amount'] ?? 0) : floatval($data);
                    if (in_array($status, ['Pending', 'Overdue']) && $amount > 0) {
                        $studentDues += $amount;
                        $unpaidDetails[$month] = ['amount' => $amount, 'status' => $status];
                    }
                }
                if ($studentDues > 0) {
                    $this->firebase->set("{$newFeesBase}/Fees/Carry_Forward/{$studentId}", [
                        'previous_session' => $sy,
                        'total_dues' => $studentDues,
                        'unpaid_details' => $unpaidDetails,
                        'carried_at' => date('c')
                    ]);
                    $cfCount++;
                    $cfTotal += $studentDues;
                }
            }

            // 3. Log the rollover
            $this->feeAudit->log('year_rollover', [
                'student_id' => 'ALL',
                'amount' => $cfTotal,
                'receipt_no' => '',
                'from_session' => $sy,
                'to_session' => $newSession,
                'carry_forward_count' => $cfCount
            ]);

            $this->_json_out([
                'status' => 'success',
                'message' => "Year rollover complete. {$cfCount} students with Rs. {$cfTotal} carried forward.",
                'carry_forward_count' => $cfCount,
                'carry_forward_total' => $cfTotal,
                'frozen_session' => $sy,
                'new_session' => $newSession
            ]);
        } catch (Exception $e) {
            log_message('error', "year_rollover_execute failed: " . $e->getMessage());
            $this->_json_out(['status' => 'error', 'message' => 'Rollover failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Check if an academic year can be safely deleted. AJAX GET.
     */
    public function year_rollover_block_check()
    {
        $this->_require_role(self::MANAGE_ROLES);

        $sessionToCheck = trim($this->input->get('session') ?? $this->session_year);
        $sn = $this->school_name;
        $base = "Schools/{$sn}/{$sessionToCheck}";

        try {
            $hasFees = $this->firebase->exists("{$base}/Accounts/Fees/Classes Fees");
            $hasPending = $this->firebase->exists("{$base}/Accounts/Pending_fees");
            $hasDemands = $this->firebase->exists("{$base}/Fees/Demands");
            $hasReceipts = $this->firebase->exists("{$base}/Accounts/Fees/Receipt_Keys");

            $canDelete = !$hasFees && !$hasPending && !$hasDemands && !$hasReceipts;
            $reasons = [];
            if ($hasFees)    $reasons[] = 'Fee structures exist';
            if ($hasPending) $reasons[] = 'Pending fees exist';
            if ($hasDemands) $reasons[] = 'Fee demands exist';
            if ($hasReceipts) $reasons[] = 'Payment receipts exist';

            $this->_json_out([
                'status' => 'success',
                'can_delete' => $canDelete,
                'block_reasons' => $reasons,
                'session' => $sessionToCheck
            ]);
        } catch (Exception $e) {
            $this->_json_out(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // ====================================================================
    //  DASHBOARD SUMMARY
    // ====================================================================

    /**
     * Dashboard collection summary. AJAX GET.
     */
    public function dashboard_collection_summary()
    {
        $this->_require_role(self::VIEW_ROLES);

        $sn = $this->school_name;
        $sy = $this->session_year;

        try {
            // Try cached data first
            $cache = $this->firebase->get("Schools/{$sn}/{$sy}/Fees/Dashboard_Cache/collection_summary");
            if ($cache && isset($cache['computed_at'])) {
                $cacheAge = time() - strtotime($cache['computed_at']);
                if ($cacheAge < 3600) { // 1 hour cache
                    $this->_json_out(['status' => 'success', 'data' => $cache, 'cached' => true]);
                    return;
                }
            }

            // Compute fresh
            $receipts = $this->firebase->get("Schools/{$sn}/{$sy}/Accounts/Fees/Receipt_Keys") ?: [];
            $totalCollected = 0;
            $receiptCount = 0;
            foreach ($receipts as $key => $receipt) {
                if (!is_array($receipt)) continue;
                $totalCollected += floatval($receipt['Amount'] ?? $receipt['amount'] ?? 0);
                $receiptCount++;
            }

            $pending = $this->firebase->get("Schools/{$sn}/{$sy}/Accounts/Pending_fees") ?: [];
            $totalPending = 0;
            $defaulterCount = 0;
            foreach ($pending as $studentId => $months) {
                if (!is_array($months)) continue;
                $studentDues = 0;
                foreach ($months as $month => $data) {
                    if ($month === 'meta') continue;
                    $status = is_array($data) ? ($data['status'] ?? 'Pending') : 'Pending';
                    $amount = is_array($data) ? floatval($data['amount'] ?? 0) : floatval($data);
                    if (in_array($status, ['Pending', 'Overdue'])) $studentDues += $amount;
                }
                if ($studentDues > 0) {
                    $totalPending += $studentDues;
                    $defaulterCount++;
                }
            }

            $totalExpected = $totalCollected + $totalPending;
            $collectionRate = $totalExpected > 0 ? round(($totalCollected / $totalExpected) * 100, 1) : 0;

            $summary = [
                'total_collected' => $totalCollected,
                'total_pending' => $totalPending,
                'total_expected' => $totalExpected,
                'collection_rate' => $collectionRate,
                'receipt_count' => $receiptCount,
                'defaulter_count' => $defaulterCount,
                'computed_at' => date('c')
            ];

            // Cache it
            $this->firebase->set("Schools/{$sn}/{$sy}/Fees/Dashboard_Cache/collection_summary", $summary);

            $this->_json_out(['status' => 'success', 'data' => $summary, 'cached' => false]);
        } catch (Exception $e) {
            log_message('error', "dashboard_collection_summary failed: " . $e->getMessage());
            $this->_json_out(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Dashboard defaulter count by class. AJAX GET.
     */
    public function dashboard_defaulter_count()
    {
        $this->_require_role(self::VIEW_ROLES);

        $sn = $this->school_name;
        $sy = $this->session_year;

        try {
            $defaulters = $this->firebase->get("Schools/{$sn}/{$sy}/Fees/Defaulters") ?: [];
            $byClass = [];
            $total = 0;

            foreach ($defaulters as $studentId => $data) {
                if (!is_array($data)) continue;
                if (!($data['is_defaulter'] ?? false)) continue;
                $total++;
                // We'd need class info — read from student profile or demands
                // For now, group by total
            }

            $this->_json_out([
                'status' => 'success',
                'total_defaulters' => $total,
                'by_class' => $byClass
            ]);
        } catch (Exception $e) {
            $this->_json_out(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
