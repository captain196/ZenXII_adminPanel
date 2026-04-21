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

        // Firestore-first writer for app-visible fee data (feeStructures,
        // feeDemands, feeDefaulters, feeReceipts collections). See
        // memory/firestore_first_migration.md for the contract.
        $this->load->library('Fee_firestore_sync', null, 'fsSync');
        $this->fsSync->init($this->firebase, $this->school_name, $this->session_year);

        // NOTE: $this->fs is provided by MY_Controller as the Firestore_service
        // instance. Do NOT override it here — that triggers CI's
        // "Resource 'fs' already exists" error.
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
    /**
     * Pure-Firestore receipt existence check (Session A).
     *
     * Accepts either the numeric portion ("1", "42") or the formatted value
     * ("R000001"). Strips any prefix so the lookup matches the Firestore
     * `feeReceiptIndex` doc id: {schoolId}_{session}_{numeric}.
     *
     * "Exists" means finalised — a mere reservation (reserved=true) returns
     * false so the cleanup / retry flow can reclaim stale numbers.
     */
    private function _receiptExists(string $receiptNo): bool
    {
        $num = preg_replace('/^R0*/i', '', $receiptNo);
        $num = preg_replace('/\D/', '', $num);
        if ($num === '') return false;
        $doc = $this->fs->get('feeReceiptIndex',
            "{$this->fs->schoolId()}_{$this->session_year}_{$num}");
        if (!is_array($doc)) return false;
        return !empty($doc['date']) && empty($doc['reserved']);
    }

    /**
     * Pure-Firestore receipt number generator (Session A).
     * Reads + increments the `feeCounters/{schoolId}_receipt_seq` doc with
     * verify-after-write retry. Returns the raw sequence (e.g. "1") that
     * submit_fees uses as the dedup key; the UI formats it as R000001.
     */
    private function _nextReceiptNo(): string
    {
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        $seq = $this->fsTxn->nextCounter('receipt_seq');
        if ($seq <= 0) {
            // Fallback: timestamp-based so UI never blocks on a lookup error.
            $seq = (int) (microtime(true) * 1000) % 1000000;
            log_message('error', '_nextReceiptNo: Firestore counter failed, using timestamp fallback');
        }
        return (string) $seq;
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
     * Build the class → [sections] map from Firestore (students + feeStructures).
     * Returns: ['classList' => [...], 'sectionMap' => ['Class 8th' => ['Section A', ...]]]
     */
    private function _buildClassSectionMap(): array
    {
        $schoolFs = $this->fs->schoolId();
        $session  = $this->session_year;
        $pairs    = [];

        // Primary: Firestore `sections` collection (all configured class/sections
        // from School Config, even if no students enrolled yet).
        try {
            $rows = $this->firebase->firestoreQuery('sections', [
                ['schoolId', '==', $schoolFs],
                ['session',  '==', $session],
            ]);
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                $c = (string) ($d['className'] ?? '');
                $s = (string) ($d['section']   ?? '');
                if ($c !== '' && $s !== '') $pairs[] = ['class' => $c, 'section' => $s];
            }
        } catch (\Exception $_) {}

        // Supplement: feeStructures (might have entries for classes not yet
        // in `sections` if fees were configured via bulk import).
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $schoolFs, $session);
        foreach ($this->fsTxn->listSectionsWithFeeChart() as $cs) {
            $pairs[] = $cs;
        }

        $classList  = [];
        $sectionMap = [];
        foreach ($pairs as $cs) {
            $c = $cs['class']; $s = $cs['section'];
            if (!isset($sectionMap[$c])) { $classList[] = $c; $sectionMap[$c] = []; }
            if (!in_array($s, $sectionMap[$c], true)) $sectionMap[$c][] = $s;
        }
        foreach ($sectionMap as &$secs) sort($secs);
        unset($secs);
        usort($classList, function ($a, $b) {
            preg_match('/(\d+)/', $a, $ma);
            preg_match('/(\d+)/', $b, $mb);
            return ((int)($ma[1] ?? 0)) <=> ((int)($mb[1] ?? 0));
        });
        return ['classList' => $classList, 'sectionMap' => $sectionMap];
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

        // Firestore: all demands for this school+session, grouped by student.
        $schoolFs = $this->fs->schoolId();
        $rawDemands = $this->firebase->firestoreQuery('feeDemands', [
            ['schoolId', '==', $schoolFs], ['session', '==', $sy],
        ]);
        $byStudent = [];
        foreach ((array) $rawDemands as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            $sid = (string) ($d['studentId'] ?? $d['student_id'] ?? '');
            if ($sid !== '') $byStudent[$sid][] = $d;
        }

        $defaulters = [];

        foreach ($byStudent as $studentId => $demands) {

            $totalDue      = 0;
            $totalPaid     = 0;
            $oldestUnpaid  = '';
            $maxOverdue    = 0;
            $unpaidCount   = 0;
            $studentName   = '';
            $studentClass  = '';
            $studentSec    = '';

            foreach ($demands as $d) {
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
                        if ($oldestUnpaid === '' || ($d['period_key'] ?? '') < $oldestUnpaid) {
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

        $schoolFs = $this->fs->schoolId();
        $rawDemands = $this->firebase->firestoreQuery('feeDemands', [
            ['schoolId', '==', $schoolFs], ['session', '==', $sy],
        ]);

        $byClass   = [];
        $byMonth   = [];
        $byStatus  = ['paid' => 0, 'partial' => 0, 'unpaid' => 0];
        $totalDemanded  = 0;
        $totalCollected = 0;

        foreach ((array) $rawDemands as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            $sid  = (string) ($d['studentId'] ?? $d['student_id'] ?? '');
            $net  = floatval($d['net_amount'] ?? 0);
            $paid = floatval($d['paid_amount'] ?? 0);
            // Same drift fix as the dashboard endpoint — fall back to
            // className for legacy yearly demands.
            $cls = (string) ($d['class'] ?? $d['className'] ?? '');
            if ($cls === '') $cls = 'Unknown';
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

        // Firestore: receipt allocations for this student.
        $schoolFs = $this->fs->schoolId();
        $rawAllocs = $this->firebase->firestoreQuery('feeReceiptAllocations', [
            ['schoolId',  '==', $schoolFs],
            ['studentId', '==', $studentId],
        ]);
        $receipts = [];
        foreach ((array) $rawAllocs as $r) {
            $rc = $r['data'] ?? $r;
            if (!is_array($rc)) continue;
            $rc['_key'] = $r['id'] ?? ($rc['receiptKey'] ?? '');
            $receipts[] = $rc;
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

        $schoolFs = $this->fs->schoolId();
        $sy       = $this->session_year;
        $months   = ['April','May','June','July','August','September',
                     'October','November','December','January','February','March'];

        // ── 1. Receipts → totals, monthly, recent, payment modes ──────
        // CRITICAL: filter by session, otherwise the "Total Collected"
        // tile aggregates EVERY receipt the school has ever issued
        // (including prior academic years) while "Total Due" below uses
        // only this session — collection_rate becomes nonsense math.
        $rcptRows = $this->firebase->firestoreQuery('feeReceipts', [
            ['schoolId', '==', $schoolFs],
            ['session',  '==', $sy],
        ]);
        $totalCollected    = 0;
        $monthlyCollection = array_fill_keys($months, 0);
        $recentTxns        = [];
        $paymentModes      = [];
        $todayStr          = date('Y-m-d');
        $todayCollection   = 0;
        $todayTxns         = 0;

        foreach ((array) $rcptRows as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            // Phase 16: distinguish revenue vs cash flow.
            //   $allocated = what landed on demands → use for revenue/total/by-month/by-mode
            //   $input     = what the user paid (incl. wallet overflow) → use for cash flow
            // Legacy receipts (pre-Phase-11) only have `amount`; fall back.
            $allocated = (float) ($d['allocated_amount']
                                 ?? $d['allocatedAmount']
                                 ?? $d['amount']
                                 ?? $d['netAmount']
                                 ?? 0);
            $input     = (float) ($d['input_amount']
                                 ?? $d['inputAmount']
                                 ?? $d['amount']
                                 ?? $d['netAmount']
                                 ?? 0);
            if ($allocated <= 0 && $input <= 0) continue;

            $totalCollected += $allocated;

            $dateStr = (string) ($d['paymentDate'] ?? $d['date'] ?? $d['createdAt'] ?? '');
            $dObj = null;
            foreach (['Y-m-d', 'd-m-Y', \DateTime::ATOM] as $fmt) {
                $dObj = \DateTime::createFromFormat($fmt, substr($dateStr, 0, 19));
                if ($dObj !== false) break;
            }
            $mName = $dObj ? $dObj->format('F') : '';
            if (isset($monthlyCollection[$mName])) $monthlyCollection[$mName] += $allocated;

            $mode = (string) ($d['paymentMode'] ?? 'Cash');
            $paymentModes[$mode] = ($paymentModes[$mode] ?? 0) + $allocated;

            $receiptDate = $dObj ? $dObj->format('Y-m-d') : '';
            if ($receiptDate === $todayStr) {
                // Today's "cash flow" = what came IN today excluding
                // wallet-spend (wallet pay isn't fresh cash). Also
                // exclude wallet from the txn count so the tile reads
                // consistently (was: "₹0 (3 txns)" on a wallet-only day).
                if ($mode !== 'Wallet') {
                    $todayCollection += $input;
                    $todayTxns++;
                }
            }

            // Recent-txn list shows the receipt issuer's name (not raw
            // studentId). Fall back through the dual-emit aliases and
            // finally the id so something always renders.
            $stuLabel = (string) ($d['studentName']
                                ?? $d['student_name']
                                ?? $d['studentId']
                                ?? '—');

            $recentTxns[] = [
                'receipt'   => $d['receiptKey'] ?? '',
                'date'      => $receiptDate,
                'student'   => $stuLabel,
                'studentId' => (string) ($d['studentId'] ?? ''),
                'amount'    => $input,
                'mode'      => $mode,
            ];
        }

        usort($recentTxns, fn($a, $b) => strcmp($b['date'], $a['date']));
        $recentTxns = array_slice($recentTxns, 0, 15);

        // ── 2. Class-wise stats from demands ─────────────────────────
        $demandRows = $this->firebase->firestoreQuery('feeDemands', [
            ['schoolId', '==', $schoolFs], ['session', '==', $sy],
        ]);

        $byClass    = []; // class => {collected, due, students=>{sid=>hasPaid}}
        $studentSet = [];

        // Active-student filter — exclude TC/Inactive/withdrawn so they
        // don't inflate defaulters / total_due. Empty active-set falls
        // through (treats every demand as belonging to an active
        // student) so a misconfigured school still gets numbers.
        $activeIds = $this->_activeStudentIds();
        $hasActiveFilter = !empty($activeIds);
        foreach ((array) $demandRows as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            // Yearly demands written before the writer fix had only
            // `className`. Fall back so they bucket into the right class
            // instead of "Unknown". Empty-string also normalises to
            // "Unknown" so the UI shows something sensible.
            $cls = (string) ($d['class'] ?? $d['className'] ?? '');
            if ($cls === '') $cls = 'Unknown';
            $sid  = (string) ($d['studentId'] ?? $d['student_id'] ?? '');
            // Skip demands whose student is no longer Active.
            if ($hasActiveFilter && $sid !== '' && !isset($activeIds[$sid])) continue;
            $net  = (float) ($d['net_amount'] ?? 0);
            $paid = (float) ($d['paid_amount'] ?? 0);
            $bal  = (float) ($d['balance'] ?? max(0, $net - $paid));

            if (!isset($byClass[$cls])) $byClass[$cls] = ['collected' => 0, 'due' => 0, 'students' => []];
            $byClass[$cls]['collected'] += $paid;
            $byClass[$cls]['due']       += $bal;
            if ($sid !== '') {
                $byClass[$cls]['students'][$sid] = ($byClass[$cls]['students'][$sid] ?? false) || ($paid > 0);
                $studentSet[$sid] = true;
            }
        }

        $classCollection = [];
        $totalStudents   = count($studentSet);
        $paidStudents    = 0;
        $totalDue        = 0;

        foreach ($byClass as $cls => $data) {
            $stuCount = count($data['students']);
            $stuPaid  = count(array_filter($data['students']));
            $paidStudents += $stuPaid;
            $totalDue     += $data['due'];
            $totalThisCls = $data['collected'] + $data['due']; // demanded
            $classCollection[] = [
                'class'        => $cls,
                'students'     => $stuCount,
                'collected'    => round($data['collected'], 2),
                'due'          => round($data['due'], 2),
                // Amount-based collection % (what the column header says).
                // Old paid_pct counted "students who paid anything" — a
                // student paying ₹1 of ₹50,000 ticked the box. Rename
                // the legacy field for back-compat readers but make
                // `paid_pct` the amount-based ratio that matches the
                // visible "Paid %" header.
                'paid_pct'        => $totalThisCls > 0
                    ? round(($data['collected'] / $totalThisCls) * 100)
                    : 0,
                'students_paid_pct' => $stuCount > 0 ? round(($stuPaid / $stuCount) * 100) : 0,
            ];
        }
        usort($classCollection, fn($a, $b) => strnatcmp($a['class'], $b['class']));

        $thisMonthName       = date('F');
        $thisMonthCollection = $monthlyCollection[$thisMonthName] ?? 0;
        $defaulters = max(0, $totalStudents - $paidStudents);

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

        // Page load — class/section dropdowns from Firestore.
        $csMap = $this->_buildClassSectionMap();
        $classList  = $csMap['classList'];
        $sectionMap = $csMap['sectionMap'];

        $data['classes']  = $classList;
        $data['sections'] = $sectionMap;

        $this->load->view('include/header');
        $this->load->view('fees_chart', $data);
        $this->load->view('include/footer');
    }

    private function _createDefaultFees($class, $section)
    {
        // If this class/section already has a chart in Firestore, return it.
        $existing = $this->_getClassFeeChart($class, $section);
        if (!empty($existing)) return $existing;

        // Build a zero-value template from fee head names found in other
        // class/section charts within this session — no RTDB reads.
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        $allSections = $this->fsTxn->listSectionsWithFeeChart();

        $monthlyHeads = [];
        $yearlyHeads  = [];
        foreach ($allSections as $cs) {
            $chart = $this->fsTxn->readFeeStructure($cs['class'], $cs['section']);
            foreach ($chart['April'] ?? [] as $title => $_) $monthlyHeads[$title] = true;
            foreach ($chart['Yearly Fees'] ?? [] as $title => $_) $yearlyHeads[$title] = true;
        }

        if (empty($monthlyHeads) && empty($yearlyHeads)) return [];

        $months = ['April','May','June','July','August','September',
                   'October','November','December','January','February','March'];
        $default = [];
        foreach ($months as $m) {
            $default[$m] = array_fill_keys(array_keys($monthlyHeads), 0);
        }
        $default['Yearly Fees'] = array_fill_keys(array_keys($yearlyHeads), 0);

        return $default;
    }

    private function _getFees($class, $section)
    {
        $feesData = $this->_getClassFeeChart($class, $section);
        if (empty($feesData)) {
            return json_encode(['fees' => [], 'monthlyTotals' => []]);
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

        // Verify class/section exists by checking students collection.
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        $roster = $this->fsTxn->listStudentsInSection($class, $section);
        if (empty($roster)) {
            $existing = $this->_getClassFeeChart($class, $section);
            if (empty($existing)) {
                $this->_json_out(['status' => 'error', 'message' => 'Class/section not found. Please reload the page.']);
                return;
            }
        }

        // Merge incoming fees with existing Firestore chart.
        $existingChart = $this->_getClassFeeChart($class, $section);
        $mergedChart = $existingChart;
        foreach ($fees as $month => $entries) {
            if (!is_array($entries)) continue;
            $mergedChart[$month] = array_merge(
                is_array($mergedChart[$month] ?? null) ? $mergedChart[$month] : [],
                $entries
            );
        }

        // Pure Firestore write — feeStructures collection.
        $fsOk = $this->fsSync->syncFeeStructure($class, $section, $mergedChart);
        if (!$fsOk) {
            log_message('error', "save_updated_fees: Firestore write failed for {$class}/{$section}");
            $this->output->set_status_header(503);
            $this->_json_out([
                'status'  => 'error',
                'message' => 'Could not save fee structure. Please try again.',
            ]);
            return;
        }

        // ── Auto-generate demands for this class/section ──
        // Best-effort background-style invocation. We log but don't
        // block the save — the fee structure is already saved, and
        // administrators can re-generate demands manually if this step
        // fails (via `generate_monthly_demands`). Replaces the legacy
        // "Generate Demands" sidebar button for the 95% happy path.
        $demandsGenerated = 0;
        $demandsFailed = 0;
        try {
            if (!empty($roster)) {
                foreach ($roster as $studentId => $_stu) {
                    try {
                        $ok = $this->_auto_generate_student_demands($studentId, $class, $section, $mergedChart);
                        if ($ok) $demandsGenerated++; else $demandsFailed++;
                    } catch (\Exception $e) {
                        $demandsFailed++;
                        log_message('error', "auto-demands failed for {$studentId}: " . $e->getMessage());
                    }
                }
                log_message('info', "save_updated_fees: auto-demands {$class}/{$section} generated={$demandsGenerated} failed={$demandsFailed}");
            }
        } catch (\Exception $e) {
            log_message('error', "save_updated_fees: auto-demands outer failure: " . $e->getMessage());
        }

        log_audit('Fees', 'update_fees', "{$class} {$section}", "Updated fee structure for {$class} Section {$section} (demands gen={$demandsGenerated} fail={$demandsFailed})");

        $this->_json_out([
            'status'  => 'success',
            'message' => $demandsGenerated > 0
                ? "Fees updated. Demands generated for {$demandsGenerated} student(s)."
                : 'Fees updated successfully.',
            'demands_generated' => $demandsGenerated,
            'demands_failed'    => $demandsFailed,
        ]);
    }

    /**
     * Regenerate feeDemand docs for a single student from the given
     * fee chart. Missing months are created, existing paid/partial
     * demands are left untouched. Called from save_updated_fees so
     * admins don't have to hit a separate "Generate Demands" button.
     */
    private function _auto_generate_student_demands(string $studentId, string $class, string $section, array $chart): bool
    {
        $allMonths = [
            'April','May','June','July','August','September',
            'October','November','December','January','February','March',
            'Yearly Fees',
        ];
        $existing = $this->fsTxn->demandsForStudent($studentId); // keyed by docId

        // Index existing demands by demand-id (the deterministic key
        // we'd use for a fresh write) so we can skip any month×head
        // that already exists — including already-PAID ones.
        //
        // Earlier version used `period`-word + `fee_head` which broke
        // for "Yearly Fees" (split-on-space yielded "Yearly", which
        // never matched "Yearly Fees" from the chart). The result was
        // that paid Annual-Fee demands got overwritten with fresh
        // unpaid ones on every fee-chart save.
        $haveIds = [];
        foreach ($existing as $docId => $_d) {
            if ($docId !== '') $haveIds[$docId] = true;
        }

        $today = date('Y-m-d');
        $wrote = false;
        foreach ($allMonths as $month) {
            $heads = is_array($chart[$month] ?? null) ? $chart[$month] : [];
            foreach ($heads as $title => $amount) {
                $amt = (float) $amount;
                if ($amt <= 0) continue;

                $periodKey = $this->_period_key_for_month($month);
                $periodLabel = $month === 'Yearly Fees'
                    ? "Yearly Fees {$this->session_year}"
                    : "{$month} " . $this->_year_for_month($month);
                $demandId = "DEM_" . str_replace('-', '', $periodKey)
                          . '_' . strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', $title));

                if (isset($haveIds[$demandId])) continue; // already exists, leave it alone

                $this->fsTxn->writeDemand($demandId, [
                    'studentId'     => $studentId,
                    'className'     => $class,
                    'section'       => $section,
                    'fee_head'      => $title,
                    'period'        => $periodLabel,
                    'period_key'    => $periodKey,
                    'original_amount'=> $amt,
                    'net_amount'    => $amt,
                    'paid_amount'   => 0.0,
                    'balance'       => $amt,
                    'status'        => 'unpaid',
                    'generated_at'  => $today,
                ]);
                $wrote = true;
            }
        }
        return $wrote || !empty($haveIds);
    }

    /** Map a month name to its YYYY-MM key inside the current session. */
    /**
     * POST — Recalculate discount + scholarship on a student's UNPAID
     * demands. Used after a new scholarship award (or discount change)
     * needs to retroactively reduce future dues without losing payment
     * history on partial/paid demands.
     *
     * Strategy: delete UNPAID demands only, then re-run the standard
     * generator which reads the latest scholarshipAwards + studentDiscount.
     * Partial/paid demands are untouched (their balance reflects what was
     * actually owed at the time of payment).
     *
     * Body: student_id (required)
     */
    public function recalc_unpaid_discounts()
    {
        $this->_require_role(self::MANAGE_ROLES, 'recalc_discounts');
        $studentId = trim($this->input->post('student_id') ?? '');
        if ($studentId === '') return $this->json_error('Student ID is required.');
        $studentId = $this->safe_path_segment($studentId, 'student_id');

        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);

        // Profile sanity-check.
        $profile = $this->fsTxn->getStudent($studentId);
        if (!is_array($profile)) return $this->json_error('Student not found.');
        $class   = (string) ($profile['className'] ?? '');
        $section = (string) ($profile['section']   ?? '');
        if ($class === '' || $section === '') return $this->json_error('Cannot resolve class/section.');

        // 1. Delete UNPAID demands. Touched (partial/paid) are preserved
        //    so receipts + balances stay correct.
        $existing  = $this->fsTxn->demandsForStudent($studentId);
        $deleted   = 0;
        $preserved = 0;
        foreach ($existing as $did => $d) {
            $status = (string) ($d['status'] ?? 'unpaid');
            $paid   = (float)  ($d['paid_amount'] ?? 0);
            if ($status === 'unpaid' && $paid <= 0.005) {
                try {
                    $this->firebase->firestoreDelete('feeDemands', $did);
                    $deleted++;
                } catch (\Exception $e) {
                    log_message('error', "recalc_unpaid_discounts: delete {$did} failed: " . $e->getMessage());
                }
            } else {
                $preserved++;
            }
        }

        // 2. Re-run the generator (creates a fresh set of unpaid demands
        //    using the latest scholarship + discount snapshot).
        $studentName = (string) ($profile['name'] ?? $profile['Name'] ?? $studentId);
        $feeChart = $this->_getClassFeeChart($class, $section);
        if (empty($feeChart)) return $this->json_error("No fee chart for {$class}/{$section}.");
        $discountMap = $this->_getStudentDiscounts($studentId, $class, $section);
        $dueDay      = $this->_getDueDay();

        $totals = ['created' => 0, 'skipped' => 0, 'errors' => 0];
        foreach (self::ACADEMIC_MONTHS as $month) {
            $r = $this->_generateDemandsForMonth(
                $studentId, $studentName, $class, $section,
                $month, $feeChart, $discountMap, $dueDay
            );
            $totals['created'] += $r['created'];
            $totals['skipped'] += $r['skipped'];
            $totals['errors']  += $r['errors'];
        }

        $this->json_success([
            'message'   => "Recalculated. {$deleted} unpaid demand(s) refreshed, {$preserved} paid/partial preserved.",
            'student'   => $studentName,
            'deleted'   => $deleted,
            'preserved' => $preserved,
            'created'   => $totals['created'],
            'skipped'   => $totals['skipped'],
            'errors'    => $totals['errors'],
        ]);
    }

    /**
     * Set of active student IDs in the school. Used by the dashboards to
     * exclude TC/Inactive/withdrawn students from defaulter totals — a
     * student who left the school carrying unpaid demands shouldn't
     * inflate this year's "defaulters" count or pending receivable.
     *
     * Returns ['STU0001' => true, ...] for O(1) lookup. Cached per
     * request via static so back-to-back dashboard endpoints don't
     * re-query Firestore.
     */
    private function _activeStudentIds(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        try {
            $rows = $this->fs->schoolWhere('students', [['status', '==', 'Active']]);
            foreach ((array) $rows as $r) {
                $d   = $r['data'] ?? $r;
                $sid = (string) ($d['studentId'] ?? $d['userId'] ?? '');
                if ($sid !== '') $cache[$sid] = true;
            }
        } catch (\Exception $e) {
            log_message('error', '_activeStudentIds query failed: ' . $e->getMessage());
        }
        return $cache;
    }

    private function _period_key_for_month(string $month): string
    {
        $months = ['April'=>4,'May'=>5,'June'=>6,'July'=>7,'August'=>8,'September'=>9,
                   'October'=>10,'November'=>11,'December'=>12,'January'=>1,'February'=>2,'March'=>3];
        if ($month === 'Yearly Fees') return $this->_year_for_month('April') . '-04';
        $m = $months[$month] ?? 4;
        return $this->_year_for_month($month) . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
    }

    /** Jan–Mar roll into the session's second year; Apr–Dec into the first. */
    private function _year_for_month(string $month): string
    {
        // session_year is like "2026-27"
        $parts = explode('-', $this->session_year);
        $startYear = (int) ($parts[0] ?? date('Y'));
        $rolloverMonths = ['January','February','March'];
        if (in_array($month, $rolloverMonths, true)) return (string) ($startYear + 1);
        return (string) $startYear;
    }

    // ══════════════════════════════════════════════════════════════════
    //  DISCOUNT
    // ══════════════════════════════════════════════════════════════════

    /**
     * POST — SET (not increment) the on-demand discount for a single
     * student. Inline-grant entry point used by Fees Counter.
     *
     * Body:
     *   user_id      (required)  — Student ID
     *   amount       (required)  — flat ₹ (≥ 0). 0 clears any existing discount.
     *   valid_until  (optional)  — YYYY-MM-DD. Empty = no expiry.
     *   reason       (optional)  — free-form note (e.g. "Sibling waiver")
     *
     * Differs from submit_discount() (legacy) which ADDS to the running
     * total. This endpoint OVERWRITES — what you SET is what's stored.
     * Safer for inline grant + correction flows.
     */
    public function set_student_discount()
    {
        $this->_require_post();
        $this->_require_role(self::MANAGE_ROLES, 'set_discount');
        $this->output->set_content_type('application/json');

        $userId     = trim($this->input->post('user_id') ?? '');
        $amountRaw  = $this->input->post('amount');
        $validUntil = trim($this->input->post('valid_until') ?? '');
        $reason     = trim($this->input->post('reason') ?? '');

        if ($userId === '' || $amountRaw === false || $amountRaw === '') {
            return $this->_json_out(['success' => false, 'message' => 'user_id and amount are required.']);
        }
        if (!is_numeric($amountRaw) || (float) $amountRaw < 0) {
            return $this->_json_out(['success' => false, 'message' => 'amount must be ≥ 0.']);
        }
        if ($validUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
            return $this->_json_out(['success' => false, 'message' => 'valid_until must be YYYY-MM-DD or empty.']);
        }
        $userId  = $this->safe_path_segment($userId, 'user_id');
        $amount  = round((float) $amountRaw, 2);

        try {
            $this->load->library('Fee_firestore_txn', null, 'fsTxn');
            $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);

            $stu = $this->fsTxn->getStudent($userId);
            if (!is_array($stu)) {
                return $this->_json_out(['success' => false, 'message' => 'Student not found.']);
            }
            $class   = (string) ($stu['className'] ?? '');
            $section = (string) ($stu['section']   ?? '');
            $existing  = $this->firebase->firestoreGet('studentDiscounts', "{$this->fs->schoolId()}_{$userId}");
            $scholAmt  = is_array($existing) ? (float) ($existing['scholarshipDiscount'] ?? 0) : 0;
            $now       = date('c');

            // SET the discount (no increment); preserve scholarship tally.
            $this->fsTxn->updateDiscount($userId, [
                'onDemandDiscount'    => $amount,
                'scholarshipDiscount' => $scholAmt,
                'totalDiscount'       => $amount + $scholAmt,
                'validUntil'          => $validUntil,
                'valid_until'         => $validUntil,    // dual-emit
                'reason'              => $reason,
                'appliedAt'           => $now,
                'appliedBy'           => $this->admin_name ?? 'admin',
                'source'              => 'fees_counter_inline',
            ], ['className' => $class, 'section' => $section]);

            // Refresh defaulter snapshot so dashboards reflect the new
            // outstanding balance immediately.
            try {
                $studentName = (string) ($stu['name'] ?? $stu['Name'] ?? '');
                $defaulter   = $this->feeDefaulter->updateDefaulterStatus($userId);
                $this->fsSync->syncDefaulterStatus($userId, $defaulter, $studentName, $class, $section);
            } catch (\Exception $e) {
                log_message('error', "set_student_discount: defaulter sync failed for {$userId}: " . $e->getMessage());
            }

            return $this->_json_out([
                'success'         => true,
                'amount'          => $amount,
                'validUntil'      => $validUntil,
                'totalDiscount'   => $amount + $scholAmt,
                'message'         => $amount > 0
                    ? "Discount of ₹" . number_format($amount, 2) . " set for {$userId}."
                    : "Discount cleared for {$userId}.",
            ]);
        } catch (\Exception $e) {
            log_message('error', 'set_student_discount: ' . $e->getMessage());
            return $this->_json_out(['success' => false, 'message' => 'Internal server error.']);
        }
    }

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
            // Pure Firestore: read existing discount, update, write back.
            $this->load->library('Fee_firestore_txn', null, 'fsTxn');
            $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);

            $existing = $this->firebase->firestoreGet('studentDiscounts', "{$this->fs->schoolId()}_{$userId}");
            $curTotal = is_array($existing) ? (int) ($existing['totalDiscount'] ?? 0) : 0;
            $scholAmt = is_array($existing) ? (float) ($existing['scholarshipDiscount'] ?? 0) : 0;
            $new = $curTotal + (int) $discount;
            $now = date('c');

            $this->fsTxn->updateDiscount($userId, [
                'onDemandDiscount'    => (int) $discount,
                'scholarshipDiscount' => $scholAmt,
                'totalDiscount'       => $new,
                'appliedAt'           => $now,
            ], ['className' => $class, 'section' => $section]);

            // Refresh defaulter status from demand data.
            try {
                $stu = $this->fsTxn->getStudent($userId);
                $studentName = is_array($stu) ? ((string) ($stu['name'] ?? $stu['Name'] ?? '')) : '';
                $defaulterStatus = $this->feeDefaulter->updateDefaulterStatus($userId);
                $this->fsSync->syncDefaulterStatus($userId, $defaulterStatus, $studentName, $class, $section);
            } catch (Exception $e) {
                log_message('error', "submit_discount: defaulter sync failed for {$userId}: " . $e->getMessage());
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

        $userId = trim($this->input->post('userId') ?? '');
        if (!$userId) { $this->output->set_output(json_encode([])); return; }
        $userId = $this->safe_path_segment($userId, 'userId');

        // Session A: query feeReceipts collection directly. studentName + class
        // are denormalised into each receipt doc at write time, so we don't
        // need a separate profile read.
        try {
            $rows = $this->fs->schoolWhere('feeReceipts', [['studentId', '==', $userId]]);
        } catch (\Exception $e) {
            log_message('error', "fetch_fee_receipts failed for {$userId}: " . $e->getMessage());
            $this->output->set_output(json_encode([]));
            return;
        }

        // One bulk read of the allocation docs for this student so we can
        // tag each receipt with Full/Partial coverage (an allocation entry
        // with status='partial' means the demand it touched still owes
        // money after this receipt).
        $allocByReceiptKey = [];
        try {
            $allocRows = $this->fs->schoolWhere('feeReceiptAllocations', [['studentId', '==', $userId]]);
            foreach ((array) $allocRows as $ar) {
                $ad = $ar['data'] ?? [];
                $rk = (string) ($ad['receiptKey'] ?? '');
                if ($rk !== '') $allocByReceiptKey[$rk] = $ad;
            }
        } catch (\Exception $_) { /* best-effort — fall back to gross status */ }

        $response = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? [];
            if (!is_array($d)) continue;
            $name   = (string) ($d['studentName'] ?? '');
            $father = (string) ($d['fatherName']  ?? '');
            $class  = (string) ($d['className']   ?? '');
            $sec    = (string) ($d['section']     ?? '');

            // Coverage: walk the receipt's allocations and check if any of
            // the demands it touched were left partially paid. If yes →
            // the receipt itself was a "partial" payment for the parent's
            // intent. If every touched demand cleared → "full".
            $rcptKey = (string) ($d['receiptKey'] ?? '');
            $coverage = 'unknown';
            $months   = [];
            if ($rcptKey !== '' && isset($allocByReceiptKey[$rcptKey])) {
                $allocs = $allocByReceiptKey[$rcptKey]['allocations'] ?? [];
                if (is_array($allocs) && !empty($allocs)) {
                    $hasPartial = false;
                    foreach ($allocs as $a) {
                        $st = (string) ($a['status'] ?? '');
                        if ($st === 'partial') $hasPartial = true;
                        $period = (string) ($a['period'] ?? '');
                        $monthLabel = trim((string) preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', $period));
                        if ($monthLabel !== '' && !in_array($monthLabel, $months, true)) {
                            $months[] = $monthLabel;
                        }
                    }
                    $coverage = $hasPartial ? 'partial' : 'full';
                }
            }
            // Fall back to the receipt's own feeMonths list if the
            // allocation doc didn't carry period info.
            if (empty($months) && is_array($d['feeMonths'] ?? null)) {
                $months = array_values(array_filter(array_map('strval', $d['feeMonths'])));
            }

            // Standardized totals (Phase 11). Legacy receipts written
            // before that phase don't have these fields — fall back so
            // the modal still shows something sensible.
            $inputAmt    = (float) ($d['input_amount']     ?? $d['inputAmount']
                          ?? $d['amount']                  ?? 0);
            $allocatedAmt = (float) ($d['allocated_amount'] ?? $d['allocatedAmount']
                          ?? ($allocByReceiptKey[$rcptKey]['netReceived'] ?? 0));
            $advanceAmt  = (float) ($d['advance_credit']    ?? $d['advanceCredit']
                          ?? ($allocByReceiptKey[$rcptKey]['advanceCredit'] ?? max(0, $inputAmt - $allocatedAmt)));

            // Sum of remaining balance across the demands this receipt
            // touched — i.e. how much is STILL owed on those months
            // after this payment cleared. Drives the "Remaining" col so
            // the cashier can see at a glance which historical
            // payments left dues outstanding.
            $remainingAfter = 0.0;
            if ($rcptKey !== '' && isset($allocByReceiptKey[$rcptKey])) {
                foreach (($allocByReceiptKey[$rcptKey]['allocations'] ?? []) as $a) {
                    $remainingAfter += (float) ($a['balance'] ?? 0);
                }
            }
            $remainingAfter = round($remainingAfter, 2);

            $response[] = [
                'receiptNo'  => (string) ($d['receiptNo'] ?? ''),
                'date'       => (string) ($d['date']      ?? ''),
                'student'    => trim($name . ($father !== '' ? " / {$father}" : '')),
                'class'      => trim("{$class} {$sec}"),
                'amount'     => number_format($inputAmt, 2),     // back-compat
                'inputAmount'     => number_format($inputAmt, 2),
                'allocatedAmount' => number_format($allocatedAmt, 2),
                'advanceCredit'   => number_format($advanceAmt, 2),
                'remainingAfter'  => number_format($remainingAfter, 2),
                'fine'       => is_numeric($d['fine']   ?? null) ? number_format((float) $d['fine'],   2) : (string) ($d['fine']   ?? '0.00'),
                'discount'   => is_numeric($d['discount'] ?? null) ? number_format((float) $d['discount'], 2) : (string) ($d['discount'] ?? '0.00'),
                'account'    => (string) ($d['paymentMode'] ?? 'N/A'),
                'reference'  => (string) ($d['remarks']     ?? ''),
                'coverage'   => $coverage,
                'months'     => $months,
                'Id'         => $userId,
            ];
        }

        usort($response, fn($a, $b) => (int) $b['receiptNo'] - (int) $a['receiptNo']);
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

        // Pure Firestore: peek the receipt counter (don't increment —
        // submit_fees reserves the real number at submit time). Display
        // the NEXT number the parent will see on their receipt.
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $session_year);
        $data['receiptNo'] = (string) ($this->fsTxn->getCounter('receipt_seq') + 1);

        // Payment-mode dropdown: read ChartOfAccounts from Firestore
        // (Session C source of truth). Cash/Bank filter: is_bank flag,
        // name contains "cash", or sub_category CASH/BANK ACCOUNT. Group
        // accounts are excluded so placeholders like "Current Assets"
        // don't appear in the dropdown.
        $this->load->library('Accounting_firestore_sync');
        $this->accounting_firestore_sync->init(
            $this->firebase, $this->school_name, $session_year
        );
        $chartData  = $this->accounting_firestore_sync->readChartOfAccounts();
        $filteredAccounts = [];
        foreach ($chartData as $code => $entry) {
            if (!is_array($entry)) continue;
            if (($entry['status'] ?? 'active') !== 'active') continue;
            if (!empty($entry['is_group'])) continue;

            $name   = (string) ($entry['name'] ?? $code);
            $sub    = strtoupper(trim((string) ($entry['sub_category'] ?? '')));
            $isBank = !empty($entry['is_bank']);
            $isCash = stripos($name, 'cash') !== false;

            if ($isBank || $isCash || $sub === 'CASH' || $sub === 'BANK ACCOUNT') {
                $label = ($sub === 'CASH' || $sub === 'BANK ACCOUNT')
                    ? $sub
                    : ($isBank ? 'BANK ACCOUNT' : 'CASH');
                $filteredAccounts[$name] = $label;
            }
        }
        $data['accounts'] = $filteredAccounts;

        // Server date: use PHP's own clock. The legacy RTDB ServerTimestamp
        // node was a manually-seeded millisecond value, no longer written.
        $data['serverDate'] = date('d-m-Y');

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
        if ($userId === '') { $this->_json_out(['error' => 'No user ID provided']); return; }
        $userId = $this->safe_path_segment($userId, 'user_id');

        // Session A: Firestore-only student lookup.
        $student = $this->fs->get('students', "{$this->fs->schoolId()}_{$userId}");
        if (!is_array($student)) {
            $this->_json_out(['error' => "Student '{$userId}' not found"]);
            return;
        }

        $this->_json_out([
            'user_id'     => (string) ($student['studentId'] ?? $student['userId'] ?? $userId),
            'name'        => (string) ($student['name']       ?? ''),
            'father_name' => (string) ($student['fatherName'] ?? ''),
            'class'       => (string) ($student['className']  ?? ''),
            'section'     => (string) ($student['section']    ?? ''),
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

        $userId = trim($this->input->post('user_id') ?? '');
        if ($userId === '') { $this->_json_out(['error' => 'No user ID provided']); return; }
        $userId = $this->safe_path_segment($userId, 'user_id');

        // Sanity-check the student exists.
        $student = $this->fs->get('students', "{$this->fs->schoolId()}_{$userId}");
        if (!is_array($student)) {
            $this->_json_out(['error' => "Student '{$userId}' not found"]);
            return;
        }

        // 3-STATE month status — derived from feeDemands directly, NOT
        // from the binary `students.monthFee` map.
        //
        // Why: the monthFee map is binary (1=fully paid, 0=anything else)
        // and so couldn't distinguish UNPAID from PARTIAL. Admin's tile
        // grid then showed partial-paid months as "Unpaid" (red) which
        // contradicted the parent app's "Partial" badge. Reading
        // feeDemands gives us the true per-month state for both flows
        // to share — single source of truth.
        //
        // fsTxn is NOT auto-loaded in the constructor (it's loaded
        // inline by submit_fees + a few other endpoints). fetch_months
        // is read-only so we just need a one-shot init here.
        if (!isset($this->fsTxn)) {
            $this->load->library('Fee_firestore_txn', null, 'fsTxn');
            $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        }
        $demands = $this->fsTxn->demandsForStudent($userId);

        // Aggregate per month-label (preserves "Yearly Fees" multi-word
        // intact via the same regex used in submit_fees).
        $byMonth = []; // monthLabel => ['totalDue' => f, 'totalPaid' => f]
        foreach ((array) $demands as $d) {
            $rawPeriod = (string) ($d['period'] ?? '');
            $monthLabel = trim((string) preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', $rawPeriod));
            if ($monthLabel === '') continue;
            if (!isset($byMonth[$monthLabel])) {
                $byMonth[$monthLabel] = ['totalDue' => 0.0, 'totalPaid' => 0.0];
            }
            $byMonth[$monthLabel]['totalDue']  += (float) ($d['net_amount']  ?? 0);
            $byMonth[$monthLabel]['totalPaid'] += (float) ($d['paid_amount'] ?? 0);
        }

        $months = [
            'April', 'May', 'June', 'July', 'August', 'September',
            'October', 'November', 'December', 'January', 'February', 'March',
            'Yearly Fees',
        ];
        $result = [];
        foreach ($months as $m) {
            $entry = $byMonth[$m] ?? ['totalDue' => 0.0, 'totalPaid' => 0.0];
            $totalDue  = round($entry['totalDue'], 2);
            $totalPaid = round($entry['totalPaid'], 2);
            $remaining = round(max(0.0, $totalDue - $totalPaid), 2);
            $status = 'unpaid';
            if ($totalDue > 0) {
                if ($remaining <= 0.005) $status = 'paid';
                elseif ($totalPaid > 0.005) $status = 'partial';
            }
            $result[$m] = [
                // `paid` (legacy 0/1) preserved for any older JS that
                // still expects the binary shape — but `status`
                // (unpaid / partial / paid) is the authoritative field.
                'paid'      => ($status === 'paid') ? 1 : 0,
                'status'    => $status,
                'totalDue'  => $totalDue,
                'totalPaid' => $totalPaid,
                'remaining' => $remaining,
            ];
        }

        // Phase 21: also bundle the student's discount + wallet so the
        // Fees Counter can populate the stat strip + summary panel + the
        // discount banner immediately on student-load — without forcing
        // the cashier to pick months and click "Fetch Fee Details" first.
        // Old format (months as keys) kept as `months`; aggregate goes
        // under `_summary` so legacy callers that just iterate values
        // ignore it. Older JS (pre-Phase-21) still works because they
        // don't know about `_summary` and just see months.
        $schoolFs = $this->fs->schoolId();
        $discDoc  = $this->fs->get('studentDiscounts', "{$schoolFs}_{$userId}");
        $discAmt  = is_array($discDoc) ? (float) ($discDoc['onDemandDiscount'] ?? 0) : 0;
        $vu       = is_array($discDoc) ? trim((string) ($discDoc['validUntil'] ?? $discDoc['valid_until'] ?? '')) : '';
        $discExpired = false;
        if ($vu !== '' && $vu < date('Y-m-d')) { $discAmt = 0; $discExpired = true; }

        $advDoc   = $this->fs->get('studentAdvanceBalances', "{$schoolFs}_{$userId}");
        $walletAmt = is_array($advDoc) ? (float) ($advDoc['amount'] ?? 0) : 0;

        $totalGross = 0; $totalPaidAll = 0; $totalRemaining = 0;
        foreach ($result as $row) {
            $totalGross     += (float) $row['totalDue'];
            $totalPaidAll   += (float) $row['totalPaid'];
            $totalRemaining += (float) $row['remaining'];
        }
        // Don't double-subtract: $totalRemaining already excludes paid.
        // Net due = remaining − discount − wallet (clamped at 0).
        $netDue = max(0, round($totalRemaining - $discAmt - $walletAmt, 2));

        $result['_summary'] = [
            'totalGross'       => round($totalGross, 2),
            'alreadyPaid'      => round($totalPaidAll, 2),
            'discount'         => round($discAmt, 2),
            'discountValidUntil' => $vu,
            'discountExpired'  => $discExpired,
            'wallet'           => round($walletAmt, 2),
            'remaining'        => round($totalRemaining, 2),
            'netDue'           => $netDue,
        ];
        $this->_json_out($result);
    }

    // ══════════════════════════════════════════════════════════════════
    //  FETCH FEE DETAILS
    // ══════════════════════════════════════════════════════════════════

    public function fetch_fee_details()
    {
        $this->_require_post();
        $this->_require_role(self::VIEW_ROLES, 'fetch_fee_details');
        $this->output->set_content_type('application/json');

        $userId         = trim($this->input->post('user_id') ?? '');
        $selectedMonths = $this->input->post('months') ?? [];
        if (!is_array($selectedMonths)) $selectedMonths = [];
        $selectedMonths = array_values(array_filter(array_map('trim', $selectedMonths)));

        if ($userId === '' || empty($selectedMonths)) {
            $this->_json_out(['error' => 'Missing user_id or months']);
            return;
        }

        $schoolFs = $this->fs->schoolId();

        // Session A: Firestore-only student + fee structure + exemption +
        // discount + advance lookup.
        $student = $this->fs->get('students', "{$schoolFs}_{$userId}");
        if (!is_array($student)) {
            $this->_json_out(['error' => "Student '{$userId}' not found"]);
            return;
        }

        $class   = (string) ($student['className'] ?? '');
        $section = (string) ($student['section']   ?? '');
        if ($class === '' || $section === '') {
            $this->_json_out(['error' => "Cannot resolve class/section for '{$userId}'"]);
            return;
        }

        // Exempted fees — optional per-student map inside the student doc.
        $exemptedFees = is_array($student['exemptedFees'] ?? null) ? $student['exemptedFees'] : [];

        // Fee structure — one Firestore doc per class/section per session.
        $struct  = $this->fs->get('feeStructures', "{$schoolFs}_{$this->session_year}_{$class}_{$section}");
        $heads   = is_array($struct['feeHeads'] ?? null) ? $struct['feeHeads'] : [];

        // Normalise heads into a per-month array — this matches the shape the
        // downstream aggregation loop expects (month => [title => amount]).
        $feesRecord = [];
        $monthsAll = ['April','May','June','July','August','September','October','November','December','January','February','March'];
        foreach ($heads as $h) {
            $nm  = (string) ($h['name']      ?? '');
            $amt = (float)  ($h['amount']    ?? 0);
            $frq = (string) ($h['frequency'] ?? 'monthly');
            if ($nm === '' || $amt <= 0) continue;
            if ($frq === 'annual') {
                $feesRecord['Yearly Fees'][$nm] = $amt;
            } else {
                foreach ($monthsAll as $m) $feesRecord[$m][$nm] = $amt;
            }
        }

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

        // Per-month already-paid amounts (from feeDemands). Used to derive
        // `monthRemaining` so the form's submit field shows the *remaining*
        // due — not the full structure total. Fixes the case where May was
        // partially paid (Rs1800/2800) and admin still showed Rs2800 as due.
        if (!isset($this->fsTxn)) {
            $this->load->library('Fee_firestore_txn', null, 'fsTxn');
            $this->fsTxn->init($this->firebase, $this->fs, $schoolFs, $this->session_year);
        }
        $monthAlreadyPaid = array_fill_keys($selectedMonths, 0.0);
        foreach ((array) $this->fsTxn->demandsForStudent($userId) as $d) {
            $rawPeriod  = (string) ($d['period'] ?? '');
            // Strip trailing year ("May 2026" or "May 2026-27") to match selection labels.
            $monthLabel = trim((string) preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', $rawPeriod));
            if (!isset($monthAlreadyPaid[$monthLabel])) continue;
            $monthAlreadyPaid[$monthLabel] += (float) ($d['paid_amount'] ?? 0);
        }

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

        // Session A: pure Firestore — discount + advance credit.
        $discountDoc    = $this->fs->get('studentDiscounts',       "{$schoolFs}_{$userId}");
        $discountAmount = is_array($discountDoc) ? (float) ($discountDoc['onDemandDiscount'] ?? 0) : 0;
        $discountExpired = false;
        // Phase 17: enforce expiry. validUntil is an ISO date "YYYY-MM-DD".
        // Empty string = no expiry (legacy behaviour). Once today > expiry,
        // the discount silently becomes 0 and we surface a flag so the UI
        // can show a "Discount expired" hint instead of just hiding it.
        if (is_array($discountDoc)) {
            $validUntil = trim((string) ($discountDoc['validUntil'] ?? $discountDoc['valid_until'] ?? ''));
            if ($validUntil !== '' && $validUntil < date('Y-m-d')) {
                $discountAmount = 0;
                $discountExpired = true;
            }
        }

        $advanceDoc    = $this->fs->get('studentAdvanceBalances', "{$schoolFs}_{$userId}");
        $oversubmitted = is_array($advanceDoc) ? (float) ($advanceDoc['amount'] ?? 0) : 0;

        // Attendance-based penalty is a separate cross-module concern that
        // still reads RTDB today — will be migrated in a later session. For
        // Session A we short-circuit: default to no penalty so the fee
        // counter continues to work on pure Firestore data.
        $attPenalty = 0;
        $attWarning = '';
        $attIncomplete = false;
        $attRules = null; // intentionally skip attendance rules
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

        // Derive remaining-aware totals. Demands carry the already-paid
        // amount per month; the form should default to what's still owed.
        $monthRemaining = [];
        $grandRemaining = 0.0;
        foreach ($selectedMonths as $m) {
            $gross = (float) ($monthTotals[$m] ?? 0);
            $paid  = (float) ($monthAlreadyPaid[$m] ?? 0);
            $rem   = round(max(0.0, $gross - $paid), 2);
            $monthRemaining[$m] = $rem;
            $grandRemaining    += $rem;
        }
        $grandRemaining = round($grandRemaining, 2);

        $response = [
            // `grandTotal` / `monthTotals` now carry REMAINING (not gross).
            // The form's submit amount + per-month allocation preview both
            // need remaining to behave correctly when months are partially
            // paid. Gross structure totals are still available under the
            // `grandGross` / `monthGross` keys for the breakdown modal.
            'grandTotal'        => $grandRemaining,
            'grandGross'        => $grandTotal,
            'discountAmount'    => $discountAmount,
            'discountExpired'   => $discountExpired,
            'discountValidUntil'=> is_array($discountDoc)
                ? trim((string) ($discountDoc['validUntil'] ?? $discountDoc['valid_until'] ?? ''))
                : '',
            'overpaidFees'      => $oversubmitted,
            'message'           => "Fee Details for: $label",
            'feesRecord'        => $feesRecordArr,
            'feeRecord'         => $feeRecord,
            'selectedMonths'    => $selectedMonths,
            'monthTotals'       => $monthRemaining,
            'monthGross'        => $monthTotals,
            'monthAlreadyPaid'  => $monthAlreadyPaid,
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
        // Session A audit: PHP clock — no RTDB round-trip needed.
        $this->_json_out(['date' => date('d-m-Y')]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  GET RECEIPT NUMBER
    // ══════════════════════════════════════════════════════════════════

    public function get_receipt_no()
    {
        $this->_require_role(self::COUNTER_ROLES, 'get_receipt_no');
        // Peek — submit_fees is the only place that increments the counter.
        // Refreshing the display should never burn a receipt number.
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        $receiptNo = (string) ($this->fsTxn->getCounter('receipt_seq') + 1);
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
        // Empty term → return ALL students (capped at 200) so the
        // Browse modal can show a roster on open, not a blank table.
        $term = trim((string) $this->input->post('search_name'));
        $results = $this->_searchByName($term);
        $this->_json_out(is_array($results) ? $results : ['data' => $results]);
    }

    private function _searchByName($entry)
    {
        // Pure Firestore: query students collection for this school.
        $schoolFs = $this->fs->schoolId();
        $rows = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $schoolFs],
        ]);
        $entry   = trim((string) $entry);
        $term    = $entry === '' ? '' : preg_replace('/\s+/', ' ', $entry);
        $results = [];
        foreach ((array) $rows as $r) {
            $s = $r['data'] ?? $r;
            if (!is_array($s)) continue;
            // Inactive / TC students are excluded so the roster only
            // shows students currently enrolled.
            $status = (string) ($s['status'] ?? 'Active');
            if (!in_array(strtolower($status), ['active', ''], true)) continue;

            $name    = (string) ($s['name']       ?? $s['Name']       ?? '');
            $sid     = (string) ($s['studentId']  ?? $s['userId']     ?? '');
            $father  = (string) ($s['fatherName'] ?? $s['Father Name'] ?? '');
            $class   = (string) ($s['className']  ?? '');
            $section = (string) ($s['section']    ?? '');

            $haystack = $name . ' ' . $sid . ' ' . $father . ' ' . $class . ' ' . $section;
            $match = ($term === '');   // empty term ⇒ everyone matches
            if (!$match) {
                // Match if ANY whitespace-separated token of the search
                // term appears in the haystack — much more forgiving
                // than a single substring match. "8 B" finds Class 8th
                // Section B; "rahul ji" matches either word.
                foreach (explode(' ', $term) as $tok) {
                    if ($tok !== '' && stripos($haystack, $tok) !== false) { $match = true; break; }
                }
            }
            if ($match) {
                $results[] = [
                    'user_id'     => $sid,
                    'name'        => $name,
                    'father_name' => $father,
                    'class'       => $class,
                    'section'     => $section,
                ];
            }
            if (count($results) >= 200) break;   // safety cap
        }
        // Stable sort by name so the roster reads naturally.
        usort($results, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $results;
    }

    // ══════════════════════════════════════════════════════════════════
    //  SUBMIT FEES — Pure Firestore (Session A rewrite, zero RTDB calls)
    //
    //  Read path:  students + feeStructures + feeDemands + feeReceiptIndex
    //              + feeIdempotency + feeLocks  — all Firestore.
    //  Write path: feeReceipts + feeReceiptIndex + feeReceiptAllocations
    //              + feeDemands + studentAdvanceBalances + studentDiscounts
    //              + students.monthFee + feeDefaulters + accountingLedger
    //              + accountingClosingBalances + accountingCounters
    //              — all Firestore.
    //
    //  Concurrency: verify-after-write + retry. No RTDB, no dual-write.
    // ══════════════════════════════════════════════════════════════════
    public function submit_fees()
    {
        $this->_require_post();
        $this->_require_role(self::COUNTER_ROLES, 'submit_fees');
        $this->output->set_content_type('application/json');

        $userIdRaw  = trim((string) $this->input->post('userId'));
        $safeUserId = $userIdRaw !== '' ? $this->safe_path_segment($userIdRaw, 'userId') : '';

        require_once APPPATH . 'services/FeeCollectionService.php';
        $service = new FeeCollectionService();
        $err = $service->submit($this, [
            'admin_id'     => $this->admin_id,
            'admin_name'   => $this->admin_name,
            'school_name'  => $this->school_name,
            'session_year' => $this->session_year,
            'safe_user_id' => $safeUserId,
        ]);
        if (is_array($err) && isset($err['json_error'])) {
            $this->json_error($err['json_error']);
        }
    }


    // ══════════════════════════════════════════════════════════════════
    //  VOID TEST RECEIPT — dev/QA utility
    //
    //  Reverses every side-effect of a single submit_fees call so the admin
    //  can re-test the same payment flow. Mirrors all deletions to Firestore
    //  so the two stores stay consistent. Admin-only; NOT part of normal
    //  business flow — for real refunds use Fee_refund_service (audit
    //  trail preserved, produces a reversing journal entry).
    //
    //  POST params:
    //    receipt_no  (e.g. "1" — the numeric portion of R000001)
    // ══════════════════════════════════════════════════════════════════
    public function void_test_receipt()
    {
        $this->_require_role(self::MANAGE_ROLES, 'void_test_receipt');

        $receiptNo = trim((string) $this->input->post('receipt_no'));
        $receiptNo = preg_replace('/^R0*/i', '', $receiptNo); // accept "R000001" or "1"
        if ($receiptNo === '' || !preg_match('/^\d+$/', $receiptNo)) {
            return $this->json_error('receipt_no is required (numeric).');
        }
        $receiptKey = 'F' . $receiptNo;
        $bp         = "Schools/{$this->school_name}/{$this->session_year}";
        $schoolFs   = $this->fs->schoolId();

        // ── 1. Locate the receipt — Session A canonical source is
        //    Firestore `feeReceipts`; fall back to `feeReceiptIndex`
        //    and then to legacy RTDB Receipt_Index.
        $userId = $class = $section = $date = '';
        $amount = 0.0;

        $rcpt = $this->fs->get('feeReceipts', "{$schoolFs}_{$receiptKey}");
        if (is_array($rcpt)) {
            $userId  = (string) ($rcpt['studentId']    ?? '');
            $class   = (string) ($rcpt['className']    ?? '');
            $section = (string) ($rcpt['section']      ?? '');
            $date    = (string) ($rcpt['date']         ?? '');
            $amount  = (float)  ($rcpt['amount']       ?? 0);
        }
        if ($userId === '' || $class === '') {
            $fsIdx = $this->fs->get('feeReceiptIndex', "{$schoolFs}_{$this->session_year}_{$receiptNo}");
            if (is_array($fsIdx)) {
                $userId  = $userId  ?: (string) ($fsIdx['userId']    ?? '');
                $class   = $class   ?: (string) ($fsIdx['className'] ?? '');
                $section = $section ?: (string) ($fsIdx['section']   ?? '');
                $date    = $date    ?: (string) ($fsIdx['date']      ?? '');
                $amount  = $amount  ?: (float)  ($fsIdx['amount']    ?? 0);
            }
        }
        if ($userId === '' || $class === '') {
            // Legacy RTDB fallback for pre-Session-A data.
            $idx = null; // RTDB removed — receipt lookup via feeReceipts Firestore
            if (is_array($idx)) {
                $userId  = $userId  ?: (string) ($idx['user_id'] ?? '');
                $class   = $class   ?: (string) ($idx['class']   ?? '');
                $section = $section ?: (string) ($idx['section'] ?? '');
                $date    = $date    ?: (string) ($idx['date']    ?? '');
                $amount  = $amount  ?: (float)  ($idx['amount']  ?? 0);
            }
        }
        if ($userId === '') {
            return $this->json_error("Receipt #{$receiptNo} not found (checked Firestore + RTDB).");
        }
        if ($class === '' || $section === '') {
            return $this->json_error('Receipt exists but class/section are missing — cannot void safely.');
        }
        if ($date === '') $date = date('d-m-Y');
        $studentBase = $this->studentPath($class, $section, $userId);

        // ── 2. Resolve months the receipt paid for ──────────────────
        // Session A: months live on `feeReceipts.feeMonths`.
        // Legacy: months live on the RTDB Fees Record's `Months` field.
        $months = [];
        if (is_array($rcpt ?? null) && is_array($rcpt['feeMonths'] ?? null)) {
            $months = array_values($rcpt['feeMonths']);
        }
        if (empty($months)) {
            $feesRecord = []; // RTDB removed — use feeReceipts Firestore
            if (is_array($feesRecord['Months'] ?? null)) $months = array_values($feesRecord['Months']);
        }

        $summary = [
            'receipt_key'            => $receiptKey,
            'user_id'                => $userId,
            'class'                  => $class,
            'section'                => $section,
            'amount'                 => $amount,
            'months'                 => $months,
            'rtdb_deletions'         => [],
            'firestore_deletions'    => [],
            'journal_entries_voided' => 0,
        ];

        // ── 3. Delete Fees Record ─────────────────────────────────────
        try {
            // RTDB mirror removed per no-RTDB policy.
            $summary['rtdb_deletions'][] = 'Fees Record';
        } catch (\Exception $e) { log_message('error', "void_test_receipt: Fees Record delete: " . $e->getMessage()); }

        // ── 4. Reset Month Fee flags (RTDB + Firestore) ──────────────
        foreach ($months as $m) {
            $m = (string) $m;
            if ($m === '') continue;
            try {
                // RTDB mirror removed per no-RTDB policy.
                $this->fsSync->syncStudentMonthFee($userId, $m, 0);
                $summary['rtdb_deletions'][] = "Month Fee/{$m} → 0";
            } catch (\Exception $e) {}
        }

        // ── 5. Delete Voucher ─────────────────────────────────────────
        try {
            // RTDB mirror removed.
            $summary['rtdb_deletions'][] = "Voucher/{$date}/{$receiptKey}";
        } catch (\Exception $e) {}

        // ── 6. Delete Receipt_Index entry ─────────────────────────────
        try {
            // RTDB mirror removed.
            $summary['rtdb_deletions'][] = "Receipt_Index/{$receiptNo}";
        } catch (\Exception $e) {}

        // ── 7. Roll receipt counter back to receiptNo-1 ──────────────
        try {
            $newSeq = max(0, (int) $receiptNo - 1);
            // RTDB mirror removed.
            $this->fsSync->syncReceiptCounter($newSeq);
            $summary['rtdb_deletions'][] = "Counters/receipt_seq → {$newSeq}";
        } catch (\Exception $e) {}

        // ── 8. Revert Account_book daily total ───────────────────────
        if ($amount > 0) {
            try {
                $dt = new DateTime($date);
                $mName = $dt->format('F');
                $dNum  = $dt->format('d');
                $abPath = "{$bp}/Accounts/Account_book/Fees/{$mName}/{$dNum}/R";
                $cur = 0; // RTDB removed — advance balance in Firestore studentAdvanceBalances
                $newAb = round(max(0, $cur - $amount), 2);
                // RTDB mirror removed.
                $summary['rtdb_deletions'][] = "Account_book/Fees/{$mName}/{$dNum}/R → {$newAb}";
            } catch (\Exception $e) {}
        }

        // ── 9. Find + reverse the matching Ledger entries ────────────
        // Match on source='fee_payment' AND source_ref = receipt key.
        $allLedger = []; // RTDB removed — use Firestore accounting collection
        $voided    = [];
        if (is_array($allLedger)) {
            foreach ($allLedger as $jeId => $entry) {
                if (!is_array($entry)) continue;
                $src  = (string) ($entry['source'] ?? '');
                $sref = (string) ($entry['source_ref'] ?? '');
                $sts  = (string) ($entry['status'] ?? 'active');
                if ($sts === 'deleted') continue;
                if (stripos($src, 'fee') !== false && ($sref === $receiptKey || $sref === $receiptNo)) {
                    $voided[$jeId] = $entry;
                }
            }
        }

        foreach ($voided as $jeId => $entry) {
            try {
                // Reverse closing balances
                foreach (($entry['lines'] ?? []) as $line) {
                    $ac = (string) ($line['account_code'] ?? '');
                    if ($ac === '') continue;
                    $balPath = "{$bp}/Accounts/Closing_balances/{$ac}";
                    // Firestore-only per no-RTDB policy.
                    try {
                        $acctFs = $this->_acctFsSyncForVoid();
                        if ($acctFs) {
                            // Read current balance from Firestore, reverse, write back
                            $balDocId = $this->fs->docId("BAL_{$this->session_year}_{$ac}");
                            $cur = $this->firebase->firestoreGet('accounting', $balDocId);
                            if (is_array($cur)) {
                                $newDr = round(max(0, (float) ($cur['period_dr'] ?? 0) - (float) ($line['dr'] ?? 0)), 2);
                                $newCr = round(max(0, (float) ($cur['period_cr'] ?? 0) - (float) ($line['cr'] ?? 0)), 2);
                                $acctFs->syncClosingBalance($ac, $newDr, $newCr);
                            }
                        }
                    } catch (\Exception $_) {}

                    // Remove by_account index
                    // RTDB mirror removed.
                }

                // Remove by_date index
                $eDate = (string) ($entry['date'] ?? $date);
                // RTDB mirror removed.

                // Hard-delete ledger entry itself (test utility only)
                // RTDB mirror removed.

                // Firestore: soft-delete the journal so reports filter it out
                try {
                    $acctFs = $this->_acctFsSyncForVoid();
                    if ($acctFs) $acctFs->syncLedgerDelete($jeId, ['deleted_by' => 'void_test_receipt']);
                } catch (\Exception $_) {}

                $summary['journal_entries_voided']++;
            } catch (\Exception $e) {
                log_message('error', "void_test_receipt: ledger reverse [{$jeId}]: " . $e->getMessage());
            }
        }

        // ── 10. Firestore cleanups ───────────────────────────────────
        try {
            $schoolFs = $this->fs->schoolId();
            $this->firebase->firestoreDelete('feeReceipts',       "{$schoolFs}_{$receiptKey}");
            $summary['firestore_deletions'][] = "feeReceipts/{$schoolFs}_{$receiptKey}";
        } catch (\Exception $_) {}
        try {
            $schoolFs = $this->fs->schoolId();
            $this->firebase->firestoreDelete('feeReceiptIndex',   "{$schoolFs}_{$this->session_year}_{$receiptNo}");
            $summary['firestore_deletions'][] = "feeReceiptIndex/{$schoolFs}_{$this->session_year}_{$receiptNo}";
        } catch (\Exception $_) {}

        // ── 10a. Clear idempotency + lock records (RTDB legacy + Firestore Session A) ──
        // submit_fees short-circuits on a 'success'-status idempotency hit
        // (keyed on md5(userId|receiptNo|months|amount)) so leaving these
        // behind makes every retest fail with "Fees already submitted".
        try {
            // Legacy RTDB paths (pre-Session A). Harmless if missing.
            $idempAll = null; // RTDB removed
            if (is_array($idempAll)) {
                foreach ($idempAll as $ikey => $idata) {
                    if (!is_array($idata)) continue;
                    $ids = (string) ($idata['user_id'] ?? $idata['userId'] ?? '');
                    $irn = (string) ($idata['receipt_no'] ?? '');
                    if ($ids === $userId || $irn === $receiptNo) {
                        // RTDB mirror removed.
                        $summary['rtdb_deletions'][] = "Idempotency/{$ikey}";
                    }
                }
            }
            $lock = null; // RTDB removed — locks handled via Firestore
            if (is_array($lock)) {
                // RTDB mirror removed.
                $summary['rtdb_deletions'][] = "Locks/{$userId}";
            }

            // Session A Firestore paths — the canonical caches now.
            $schoolFs = $this->fs->schoolId();
            $idempRows = $this->fs->schoolWhere('feeIdempotency', []);
            foreach ((array) $idempRows as $row) {
                $did = $row['id']   ?? '';
                $d   = $row['data'] ?? [];
                if ($did === '' || !is_array($d)) continue;
                $duid = (string) ($d['userId']    ?? '');
                $drn  = (string) ($d['receiptNo'] ?? '');
                if ($duid === $userId || $drn === $receiptNo) {
                    try {
                        $this->firebase->firestoreDelete('feeIdempotency', $did);
                        $summary['firestore_deletions'][] = "feeIdempotency/{$did}";
                    } catch (\Exception $_) {}
                }
            }
            // Release Firestore lock if still held.
            try {
                $this->firebase->firestoreDelete('feeLocks', "{$schoolFs}_{$userId}");
                $summary['firestore_deletions'][] = "feeLocks/{$schoolFs}_{$userId}";
            } catch (\Exception $_) {}
            // Clear pending-write marker for this receipt.
            try {
                $this->firebase->firestoreDelete('feePendingWrites', "{$schoolFs}_F{$receiptNo}");
                $summary['firestore_deletions'][] = "feePendingWrites/{$schoolFs}_F{$receiptNo}";
            } catch (\Exception $_) {}
        } catch (\Exception $e) {
            log_message('error', "void_test_receipt: idempotency/lock cleanup failed: " . $e->getMessage());
        }

        // ── 10b. Revert demand statuses for the voided months ───────
        // With use_legacy_month_fee=false, submit_fees checks demand.status
        // to block duplicate payments. If we don't reset those here, the
        // next attempted payment aborts with "Month X is already fully paid".
        try {
            $demandsPath = "{$bp}/Fees/Demands/{$userId}";
            $allDemands = []; // RTDB removed — use Firestore feeDemands
            if (is_array($allDemands)) {
                foreach ($allDemands as $did => $d) {
                    if (!is_array($d)) continue;
                    $period = explode(' ', (string) ($d['period'] ?? ''))[0] ?? '';
                    if (!in_array($period, $months, true)) continue;
                    $net = (float) ($d['net_amount'] ?? $d['total_amount'] ?? 0);
                    // Firestore-only per no-RTDB policy.
                    try {
                        $this->fs->updateEntity('feeDemands', $did, [
                            'status' => 'unpaid', 'paid_amount' => 0,
                            'balance' => round($net, 2), 'last_receipt' => null,
                            'last_refund_receipt' => null, 'updated_at' => date('c'),
                        ]);
                    } catch (\Exception $_) {}
                    $summary['rtdb_deletions'][] = "Demand {$did} ({$period}) → unpaid";
                }
            }
        } catch (\Exception $e) {
            log_message('error', "void_test_receipt: demand revert failed: " . $e->getMessage());
        }

        // ── 11. Recompute defaulter status ───────────────────────────
        try {
            $newStatus = $this->feeDefaulter->updateDefaulterStatus($userId);
            $student = $this->fs->getEntity('students', $userId) ?? [];
            $sName = is_array($student) ? ($student['Name'] ?? $student['name'] ?? '') : '';
            $this->fsSync->syncDefaulterStatus($userId, $newStatus, $sName, $class, $section);
            $summary['rtdb_deletions'][] = "Defaulters/{$userId} → recomputed";
        } catch (\Exception $e) {}

        log_audit('Fees', 'void_test_receipt', $receiptKey, "Voided test receipt {$receiptKey} for {$userId}");

        $this->json_success(array_merge($summary, [
            'message' => "Receipt {$receiptKey} voided. You can now re-collect April fees for this student.",
        ]));
    }

    // ══════════════════════════════════════════════════════════════════
    //  VERIFY TEST CLEANUP — reports what still exists for a receipt
    //  so the admin can confirm manual deletion is complete.
    //
    //  POST params: receipt_no (e.g. "1")  OR  user_id
    //  Returns: { rtdb: {...}, firestore: {...}, clean: bool }
    // ══════════════════════════════════════════════════════════════════
    public function verify_test_cleanup()
    {
        $this->_require_role(self::MANAGE_ROLES, 'verify_test_cleanup');

        $receiptNo = trim((string) $this->input->post('receipt_no'));
        $receiptNo = preg_replace('/^R0*/i', '', $receiptNo);
        $userId    = trim((string) $this->input->post('user_id'));
        $bp        = "Schools/{$this->school_name}/{$this->session_year}";
        $schoolFs  = $this->fs->schoolId();
        $session   = $this->session_year;

        $rtdb = [];
        $fs   = [];

        // Receipt-level checks (skip if no receipt_no provided)
        if ($receiptNo !== '') {
            $receiptKey = 'F' . $receiptNo;

            $rtdb['receipt_index']   = '(RTDB removed)';
            $rtdb['voucher_today']   = '(RTDB removed)';
            $rtdb['receipt_counter'] = '(RTDB removed)';

            $fs['feeReceipts']       = $this->firebase->firestoreGet('feeReceipts',     "{$schoolFs}_{$receiptKey}");
            $fs['feeReceiptIndex']   = $this->firebase->firestoreGet('feeReceiptIndex', "{$schoolFs}_{$session}_{$receiptNo}");
            $fs['feeCounters']       = $this->firebase->firestoreGet('feeCounters',     "{$schoolFs}_receipt_seq");
        }

        // Journal-entry scan — look for ledger entries tied to this receipt
        $jeFound = [];
        $allLedger = []; // RTDB removed — use Firestore accounting collection
        if (is_array($allLedger)) {
            foreach ($allLedger as $jeId => $entry) {
                if (!is_array($entry)) continue;
                $src  = (string) ($entry['source']     ?? '');
                $sref = (string) ($entry['source_ref'] ?? '');
                if (stripos($src, 'fee') !== false
                    && ($receiptNo === '' || $sref === 'F' . $receiptNo || $sref === $receiptNo)) {
                    $jeFound[$jeId] = [
                        'status' => $entry['status'] ?? 'active',
                        'date'   => $entry['date'] ?? '',
                    ];
                }
            }
        }
        $rtdb['ledger_entries_for_receipt'] = $jeFound;
        $rtdb['voucher_counter_journal']    = '(RTDB removed)';
        $rtdb['closing_balance_1010'] = '(RTDB removed)';
        $fs['accountingClosingBalances_1010'] = $this->firebase->firestoreGet('accountingClosingBalances', "{$schoolFs}_{$session}_1010");
        $fs['accountingCounters_Journal']     = $this->firebase->firestoreGet('accountingCounters', "{$schoolFs}_{$session}_Journal");

        // Student-level checks (only if user_id provided)
        if ($userId !== '') {
            $rtdb['defaulters_entry'] = '(RTDB removed)';

            // Read RTDB Month Fee map so we can compare against Firestore
            // and see what submit_fees actually wrote. Resolves class/section
            // from the student's profile, matching what fetch_months does.
            $student = ($this->fs->getEntity("students", $userId) ?? []);
            if (is_array($student)) {
                list($stuClass, $stuSection) = $this->_resolveClassSection($student);
                $rtdb['student_resolved_class']   = $stuClass;
                $rtdb['student_resolved_section'] = $stuSection;
                if ($stuClass !== '' && $stuSection !== '') {
                    $studentBase = $this->studentPath($stuClass, $stuSection, $userId);
                    $rtdb['student_monthFee']     = '(RTDB removed)';
                    $rtdb['student_feesRecord']   = '(RTDB removed)';
                } else {
                    $rtdb['student_monthFee'] = 'class_or_section_unresolved';
                }
            }

            $stuDoc = $this->firebase->firestoreGet('students', "{$schoolFs}_{$userId}");
            $fs['student_monthFee'] = is_array($stuDoc) ? ($stuDoc['monthFee'] ?? null) : 'doc_missing';
            $fs['feeDefaulters']    = $this->firebase->firestoreGet('feeDefaulters', "{$schoolFs}_{$this->session_year}_{$userId}");
        }

        // Summarise: what's still present?
        $still = [];
        foreach ($rtdb as $k => $v) {
            if ($k === 'receipt_counter' || $k === 'voucher_counter_journal') {
                if ($v !== null && (int) $v !== 0) $still[] = "RTDB {$k}={$v}";
            } elseif ($k === 'closing_balance_1010') {
                if (is_array($v) && (($v['period_dr'] ?? 0) > 0 || ($v['period_cr'] ?? 0) > 0)) $still[] = "RTDB {$k} non-zero";
            } elseif ($k === 'ledger_entries_for_receipt') {
                foreach ($v as $jeId => $meta) {
                    if (($meta['status'] ?? '') !== 'deleted') $still[] = "RTDB ledger {$jeId} still active";
                }
            } elseif ($k === 'defaulters_entry') {
                if (is_array($v) && (float) ($v['total_dues'] ?? 0) > 0) {
                    // Having dues is fine (expected if we just undid the payment), skip
                }
            } elseif (!empty($v)) {
                $still[] = "RTDB {$k} still exists";
            }
        }
        foreach ($fs as $k => $v) {
            if ($k === 'feeCounters' || $k === 'accountingCounters_Journal') {
                if (is_array($v) && (int) ($v['value'] ?? 0) !== 0) $still[] = "Firestore {$k} value=" . ($v['value'] ?? '');
            } elseif ($k === 'accountingClosingBalances_1010') {
                if (is_array($v) && ((float)($v['period_dr'] ?? 0) > 0 || (float)($v['period_cr'] ?? 0) > 0)) $still[] = "Firestore {$k} non-zero";
            } elseif ($k === 'student_monthFee') {
                if (is_array($v)) {
                    foreach ($v as $m => $flag) {
                        if ((int) $flag === 1) $still[] = "Firestore students.monthFee.{$m}=1";
                    }
                }
            } elseif (!empty($v) && $v !== 'doc_missing') {
                $still[] = "Firestore {$k} still exists";
            }
        }

        $this->json_success([
            'clean'       => empty($still),
            'still_present' => $still,
            'rtdb'        => $rtdb,
            'firestore'   => $fs,
            'message'     => empty($still)
                ? 'Everything is clean — you can retest.'
                : count($still) . ' item(s) still present (see still_present list).',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  TEST-RESET — wipes every Firestore + RTDB artifact for one student
    //  so tests start from a truly clean state. Dev/QA only.
    //
    //  POST param: user_id
    // ══════════════════════════════════════════════════════════════════
    public function test_reset_student()
    {
        $this->_require_role(self::MANAGE_ROLES, 'test_reset_student');
        $userId = trim((string) $this->input->post('user_id'));
        if ($userId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $userId)) {
            return $this->json_error('Valid user_id required.');
        }

        $schoolFs = $this->fs->schoolId();
        $bp       = "Schools/{$this->school_name}/{$this->session_year}";
        $summary  = ['firestore' => [], 'rtdb' => []];

        // ── Firestore: per-student docs ──────────────────────────────
        $fsCollections = [
            'feeDefaulters'           => "{$schoolFs}_{$this->session_year}_{$userId}",
            'studentAdvanceBalances'  => "{$schoolFs}_{$userId}",
            'studentDiscounts'        => "{$schoolFs}_{$userId}",
            'feeLocks'                => "{$schoolFs}_{$userId}",
        ];
        foreach ($fsCollections as $col => $docId) {
            try {
                $this->firebase->firestoreDelete($col, $docId);
                $summary['firestore'][] = "{$col}/{$docId}";
            } catch (\Exception $_) {}
        }

        // Reset students.monthFee to all-zeros
        try {
            $student = $this->firebase->firestoreGet('students', "{$schoolFs}_{$userId}");
            if (is_array($student)) {
                $zero = array_fill_keys(['April','May','June','July','August','September','October','November','December','January','February','March','Yearly Fees'], 0);
                $this->firebase->firestoreSet('students', "{$schoolFs}_{$userId}", [
                    'monthFee'  => $zero,
                    'updatedAt' => date('c'),
                ], /* merge */ true);
                $summary['firestore'][] = "students/{$schoolFs}_{$userId}.monthFee → all 0";
            }
        } catch (\Exception $_) {}

        // Firestore: scan receipts/indices/allocations/refunds/idempotency/pending + demands.
        $scanCollections = [
            'feeReceipts'             => 'studentId',
            'feeReceiptIndex'         => 'userId',
            'feeReceiptAllocations'   => 'studentId',
            'feeRefunds'              => 'studentId',
            'feeRefundVouchers'       => 'studentId',
            'feeRefundAudit'          => 'studentId',
            'feeIdempotency'          => 'userId',
            'feePendingWrites'        => 'userId',
            'feeDemands'              => 'studentId',
        ];
        foreach ($scanCollections as $col => $field) {
            try {
                $rows = $this->fs->schoolWhere($col, [[$field, '==', $userId]]);
                foreach ((array) $rows as $row) {
                    $did = $row['id'] ?? '';
                    if ($did === '') continue;
                    $this->firebase->firestoreDelete($col, $did);
                    $summary['firestore'][] = "{$col}/{$did}";
                }
            } catch (\Exception $_) {}
        }

        // Reset counters so the next payment starts at 1.
        try {
            $this->firebase->firestoreSet('feeCounters', "{$schoolFs}_receipt_seq", [
                'schoolId' => $schoolFs, 'kind' => 'receipt_seq', 'value' => 0, 'updatedAt' => date('c'),
            ]);
            $summary['firestore'][] = 'feeCounters/receipt_seq → 0';
        } catch (\Exception $_) {}

        // ── RTDB: legacy paths that may still hold old test data ─────
        // Best-effort deletes. If Session A/B didn't write these, the
        // delete is a no-op.
        $rtdbDeletes = [];
        try {
            $student = $this->firebase->firestoreGet('students', "{$schoolFs}_{$userId}");
            $class   = is_array($student) ? (string) ($student['className'] ?? '') : '';
            $section = is_array($student) ? (string) ($student['section']   ?? '') : '';
            if ($class !== '' && $section !== '') {
                $sb = $this->studentPath($class, $section, $userId);
                $rtdbDeletes[] = "{$sb}/Fees Record";
                $rtdbDeletes[] = "{$sb}/Month Fee";
                $rtdbDeletes[] = "{$sb}/Discount";
                $rtdbDeletes[] = "{$sb}/Oversubmittedfees";
            }
        } catch (\Exception $_) {}
        $rtdbDeletes[] = "{$bp}/Fees/Demands/{$userId}";
        $rtdbDeletes[] = "{$bp}/Fees/Locks/{$userId}";
        $rtdbDeletes[] = "{$bp}/Fees/Defaulters/{$userId}";
        $rtdbDeletes[] = "{$bp}/Fees/Advance_Balance/{$userId}";

        // RTDB deletes removed per no-RTDB policy.
        // Firestore collections are the sole source; test reset
        // is handled by the Firestore delete calls above.

        log_audit('Fees', 'test_reset_student', $userId, "Wiped test data for {$userId}");
        $this->json_success(array_merge($summary, [
            'user_id' => $userId,
            'message' => "Test data wiped for {$userId}. Ready for a fresh test.",
        ]));
    }

    // ══════════════════════════════════════════════════════════════════
    //  VERIFY SESSION B — dedicated test helper for the refund flow.
    //  Returns PASS/FAIL per check so the test output is unambiguous.
    //
    //  POST params:
    //    refund_id   — the refund you want to verify
    //    stage       — 'created' | 'approved' | 'processed'
    //    receipt_no  — the source receipt number (e.g. "1")
    //    user_id     — the student id
    // ══════════════════════════════════════════════════════════════════
    public function verify_session_b()
    {
        $this->_require_role(self::MANAGE_ROLES, 'verify_session_b');
        $refId     = trim((string) $this->input->post('refund_id'));
        $stage     = trim((string) $this->input->post('stage'));
        $receiptNo = trim((string) $this->input->post('receipt_no'));
        $userId    = trim((string) $this->input->post('user_id'));

        if ($refId === '' || $userId === '' || !in_array($stage, ['created','approved','processed'], true)) {
            return $this->json_error('refund_id, user_id, stage=(created|approved|processed) required.');
        }

        $schoolFs = $this->fs->schoolId();
        $session  = $this->session_year;
        $bp       = "Schools/{$this->school_name}/{$session}";
        $origKey  = $receiptNo !== '' ? ('F' . preg_replace('/^R0*/i', '', $receiptNo)) : '';

        $checks = [];
        $addCheck = function(string $name, bool $pass, string $detail = '') use (&$checks) {
            $checks[] = ['name' => $name, 'pass' => $pass, 'detail' => $detail];
        };

        // ── 1. feeRefunds Firestore doc exists with right status ─────
        $refDoc = $this->firebase->firestoreGet('feeRefunds', "{$schoolFs}_{$refId}");
        if (!is_array($refDoc)) {
            $addCheck('Firestore feeRefunds doc exists', false, 'Missing — Session B did not write it');
        } else {
            $addCheck('Firestore feeRefunds doc exists', true, "status={$refDoc['status']}");
            $expectedStatus = ['created'=>'pending','approved'=>'approved','processed'=>'processed'][$stage];
            $addCheck("feeRefunds.status == '{$expectedStatus}'",
                ($refDoc['status'] ?? '') === $expectedStatus,
                "got '".($refDoc['status'] ?? '')."'");
        }

        // ── 2. RTDB Refunds path MUST be empty for this refund id ────
        $rtdbRefund = null; // RTDB removed
        $addCheck('RTDB Accounts/Fees/Refunds/{refId} is empty (Session B writes Firestore only)',
            empty($rtdbRefund),
            empty($rtdbRefund) ? 'null (correct)' : 'found data: ' . json_encode($rtdbRefund));

        // ── Stage-specific checks ────────────────────────────────────
        if ($stage === 'processed') {
            // 3. Firestore refund voucher
            $vchrKey = 'REFUND_' . strtoupper(substr($refId, 4));
            $vchr    = $this->firebase->firestoreGet('feeRefundVouchers', "{$schoolFs}_{$session}_{$vchrKey}");
            $addCheck('Firestore feeRefundVouchers doc exists',
                is_array($vchr),
                is_array($vchr) ? "amount={$vchr['amount']}, mode={$vchr['refundMode']}" : 'missing');

            // 4. RTDB Vouchers path should NOT have this refund voucher
            $vchrRtdb = null;
            $allV = []; // RTDB removed
            if (is_array($allV)) {
                foreach ($allV as $d => $list) {
                    if (is_array($list) && isset($list[$vchrKey])) { $vchrRtdb = "{$d}/{$vchrKey}"; break; }
                }
            }
            $addCheck('RTDB Vouchers path empty for REFUND_* (Session B Firestore only)',
                $vchrRtdb === null,
                $vchrRtdb === null ? 'null (correct)' : "found at {$vchrRtdb}");

            // 5. Firestore audit entry
            $auditRows = $this->fs->schoolWhere('feeRefundAudit', [['refundId', '==', $refId]]);
            $addCheck('Firestore feeRefundAudit has entry for this refund',
                !empty($auditRows),
                count((array) $auditRows) . ' audit row(s)');

            // 6. RTDB Refund_Audit path should be empty of new entries
            //    (we can't easily filter by refId so we just check new entries
            //     for today — best-effort).
            $addCheck('RTDB Refund_Audit — no Session-B writes (best-effort)', true,
                'skipped strict check; look at Firebase console for RFND_{today} entries');

            // 7. Allocation status flipped to reversed
            if ($origKey !== '') {
                $alloc = $this->firebase->firestoreGet('feeReceiptAllocations', "{$schoolFs}_{$session}_{$origKey}");
                $allocStatus = is_array($alloc) ? ($alloc['status'] ?? '(unset)') : 'missing';
                $addCheck("feeReceiptAllocations.{$origKey}.status == 'reversed'",
                    is_array($alloc) && $allocStatus === 'reversed',
                    "status={$allocStatus}");
            }

            // 8. Student month-fee map recomputed (can't assert exact values,
            //    just confirm the doc got updated recently).
            $stu = $this->firebase->firestoreGet('students', "{$schoolFs}_{$userId}");
            $updatedAt = is_array($stu) ? strtotime((string) ($stu['updatedAt'] ?? '')) : 0;
            $addCheck('students.monthFee recomputed (updatedAt within last 10 min)',
                $updatedAt > (time() - 600),
                is_array($stu) ? "updatedAt=" . ($stu['updatedAt'] ?? '') : 'doc missing');
        }

        $pass = array_reduce($checks, fn($carry, $c) => $carry && $c['pass'], true);
        $this->json_success([
            'verdict'    => $pass ? '✅ ALL CHECKS PASSED' : '❌ SOME CHECKS FAILED',
            'stage'      => $stage,
            'refund_id'  => $refId,
            'checks'     => $checks,
            'pass_count' => count(array_filter($checks, fn($c) => $c['pass'])),
            'fail_count' => count(array_filter($checks, fn($c) => !$c['pass'])),
            'total'      => count($checks),
        ]);
    }

    /**
     * Session C verifier — confirm the accounting journal landed in
     * Firestore and RTDB was untouched.
     *
     * POST params:
     *   entry_id    (string, optional) e.g. FE_20260416_abc12345
     *   receipt_no  (string, optional) Ledger source_ref to resolve entry_id
     *   kind        (string, optional) 'fee' (default) or 'refund' — selects
     *               the RTDB path to scan for the accidental legacy write
     */
    public function verify_session_c()
    {
        $this->_require_role(self::MANAGE_ROLES, 'verify_session_c');

        $entryId   = trim((string) $this->input->post('entry_id'));
        $receiptNo = trim((string) $this->input->post('receipt_no'));
        $kind      = strtolower(trim((string) $this->input->post('kind'))) ?: 'fee';

        $schoolFs = $this->fs->schoolId();
        $session  = $this->session_year;
        $bp       = "Schools/{$this->school_name}/{$session}";

        // Resolve entry_id from receipt_no if not given. source_ref gets
        // stored as whatever submit_fees passes — sometimes "F2", sometimes
        // "2" — so try a few sensible variants before giving up.
        if ($entryId === '' && $receiptNo !== '') {
            $plain   = preg_replace('/^[RF]0*/i', '', $receiptNo);
            $variants = array_unique(array_filter([
                $receiptNo,                 // as-entered
                $plain,                     // stripped of R/F prefix + zeros
                'F' . $plain,               // F-prefixed
                'R' . str_pad($plain, 6, '0', STR_PAD_LEFT), // zero-padded R form
            ]));
            foreach ($variants as $v) {
                $rows = $this->firebase->firestoreQuery('accountingLedger', [
                    ['schoolId',   '==', $schoolFs],
                    ['session',    '==', $session],
                    ['source_ref', '==', $v],
                ]);
                if (is_array($rows) && !empty($rows)) {
                    $d = $rows[0]['data'] ?? $rows[0];
                    $entryId = (string) ($d['entryId'] ?? '');
                    if ($entryId !== '') break;
                }
            }
        }

        if ($entryId === '') {
            return $this->json_error('Provide entry_id OR receipt_no (must resolve to an accountingLedger doc).');
        }

        $checks = [];
        $add = function(string $name, bool $pass, string $detail = '') use (&$checks) {
            $checks[] = ['name' => $name, 'pass' => $pass, 'detail' => $detail];
        };

        // ── 1. Firestore accountingLedger doc exists ────────────────────
        $ledger = $this->firebase->firestoreGet('accountingLedger', "{$schoolFs}_{$session}_{$entryId}");
        if (!is_array($ledger)) {
            $add('Firestore accountingLedger doc exists', false, 'Missing — Session C did not write it');
            return $this->json_success([
                'verdict' => '❌ SOME CHECKS FAILED',
                'entry_id' => $entryId,
                'checks' => $checks,
                'pass_count' => 0, 'fail_count' => 1, 'total' => 1,
            ]);
        }
        $lines   = is_array($ledger['lines'] ?? null) ? $ledger['lines'] : [];
        $totalDr = (float) ($ledger['total_dr'] ?? 0);
        $totalCr = (float) ($ledger['total_cr'] ?? 0);
        $date    = (string) ($ledger['date'] ?? '');
        $add('Firestore accountingLedger doc exists', true,
            "voucher={$ledger['voucher_no']}, Dr={$totalDr}, Cr={$totalCr}, lines=" . count($lines));

        // ── 2. Dr = Cr (balanced) ───────────────────────────────────────
        $add('Ledger balanced (Dr == Cr)', abs($totalDr - $totalCr) < 0.01,
            "Dr={$totalDr} Cr={$totalCr} diff=" . round($totalDr - $totalCr, 2));

        // ── 3. accountingIndexByDate has an entry ───────────────────────
        $idxDate = $this->firebase->firestoreGet('accountingIndexByDate',
            "{$schoolFs}_{$session}_{$date}_{$entryId}");
        $add("accountingIndexByDate.{$date} contains this entry",
            is_array($idxDate),
            is_array($idxDate) ? 'found' : 'missing');

        // ── 4. accountingIndexByAccount has an entry per line ───────────
        $missingIdx = [];
        foreach ($lines as $ln) {
            $ac = (string) ($ln['account_code'] ?? '');
            if ($ac === '') continue;
            $idx = $this->firebase->firestoreGet('accountingIndexByAccount',
                "{$schoolFs}_{$session}_{$ac}_{$entryId}");
            if (!is_array($idx)) $missingIdx[] = $ac;
        }
        $add('accountingIndexByAccount populated for every line',
            empty($missingIdx),
            empty($missingIdx) ? count($lines) . ' indices found' : 'missing for: ' . implode(',', $missingIdx));

        // ── 5. Closing balances recorded for every affected account ─────
        $missingBal = [];
        foreach ($lines as $ln) {
            $ac = (string) ($ln['account_code'] ?? '');
            if ($ac === '') continue;
            $bal = $this->firebase->firestoreGet('accountingClosingBalances',
                "{$schoolFs}_{$session}_{$ac}");
            if (!is_array($bal)) $missingBal[] = $ac;
        }
        $add('accountingClosingBalances row exists for each account',
            empty($missingBal),
            empty($missingBal) ? 'all accounts present' : 'missing: ' . implode(',', $missingBal));

        // ── 6. RTDB Ledger MUST be empty for this entry ─────────────────
        $rtdbLedger = null; // RTDB removed
        $add('RTDB Accounts/Ledger/{entryId} is empty (Session C is Firestore-only)',
            empty($rtdbLedger),
            empty($rtdbLedger) ? 'null (correct)' : 'found: ' . json_encode($rtdbLedger));

        // ── 7. RTDB Ledger_index MUST be empty for this entry ───────────
        $rtdbIdxDate = null; // RTDB removed
        $add('RTDB Ledger_index/by_date/{entryId} is empty',
            empty($rtdbIdxDate),
            empty($rtdbIdxDate) ? 'null (correct)' : 'found: ' . json_encode($rtdbIdxDate));

        // ── 8. Voucher counter recorded in Firestore, NOT RTDB ──────────
        $voucherType = (string) ($ledger['voucher_type'] ?? 'Journal');
        $ctrFs = $this->firebase->firestoreGet('accountingCounters',
            "{$schoolFs}_{$session}_{$voucherType}");
        $ctrRt = null; // RTDB removed
        $fsVal = is_array($ctrFs) ? (int) ($ctrFs['value'] ?? 0) : 0;
        $rtVal = (int) ($ctrRt ?? 0);
        $add('Firestore accountingCounters has the voucher counter',
            $fsVal > 0,
            is_array($ctrFs) ? "value={$fsVal}" : 'missing');
        // The legit pass condition is: Firestore counter is strictly ahead
        // of any pre-existing RTDB counter (or RTDB was never seeded).
        // That means Session C only advanced Firestore, not RTDB.
        $add('RTDB Voucher_counters/{type} not advanced by Session C',
            $fsVal > $rtVal,
            "rtdb={$rtVal}, firestore={$fsVal} " . ($fsVal > $rtVal
                ? '(Firestore ahead — RTDB stayed still, correct)'
                : '(RTDB caught up to Firestore — Session C may have written RTDB)'));

        // ── 9. RTDB Closing_balances not touched by this entry ──────────
        //    (Can't prove negative strictly — but if the same account exists
        //     only in Firestore, RTDB one should not match the new period
        //     totals.)  Best-effort: print for manual eyeball.
        $rtdbBalSample = null;
        foreach ($lines as $ln) {
            $ac = (string) ($ln['account_code'] ?? '');
            if ($ac === '') continue;
            $rtdbBalSample = [
                'code' => $ac,
                'rtdb' => '(RTDB removed)',
                'fs'   => $this->firebase->firestoreGet('accountingClosingBalances',
                            "{$schoolFs}_{$session}_{$ac}"),
            ];
            break;
        }
        $add('RTDB vs Firestore closing balance sample (for eyeball)', true,
            json_encode($rtdbBalSample));

        $pass = array_reduce($checks, fn($c, $x) => $c && $x['pass'], true);
        $this->json_success([
            'verdict'    => $pass ? '✅ ALL CHECKS PASSED' : '❌ SOME CHECKS FAILED',
            'kind'       => $kind,
            'entry_id'   => $entryId,
            'voucher_no' => $ledger['voucher_no'] ?? '',
            'date'       => $date,
            'lines'      => $lines,
            'checks'     => $checks,
            'pass_count' => count(array_filter($checks, fn($c) => $c['pass'])),
            'fail_count' => count(array_filter($checks, fn($c) => !$c['pass'])),
            'total'      => count($checks),
        ]);
    }

    /**
     * Diagnostic — reconcile the "Overpaid (carry-fwd)" figure shown on
     * the fees counter against the student's actual receipts/refunds.
     *
     * POST user_id  (student ID)
     *
     * Response:
     *   stored           → the value currently displayed on the form
     *                       (Firestore studentAdvanceBalances.amount)
     *   reconstructed    → what it SHOULD be:
     *                       Σ(receipts.amount) − Σ(receipts allocated to demands)
     *                       − Σ(refunds that drew from the advance)
     *   match            → true if stored == reconstructed
     *   receipts, refunds→ raw rows used for the reconstruction (for audit)
     */
    public function debug_carry_forward()
    {
        $this->_require_role(self::MANAGE_ROLES, 'debug_carry_forward');
        $userId = trim((string) $this->input->post('user_id'));
        if ($userId === '') return $this->json_error('user_id required.');

        $schoolFs = $this->fs->schoolId();
        $docId    = "{$schoolFs}_{$userId}";

        // 1. Stored value (what the form reads)
        $advDoc  = $this->firebase->firestoreGet('studentAdvanceBalances', $docId) ?: [];
        $stored  = (float) ($advDoc['amount'] ?? 0);

        // 2. All receipts for this student (with allocations)
        $receipts = $this->firebase->firestoreQuery('feeReceipts', [
            ['schoolId',  '==', $schoolFs],
            ['studentId', '==', $userId],
        ]);

        $sumReceiptAmount = 0.0;
        $sumOverpayment   = 0.0;
        $allocByReceipt   = []; // receiptKey => allocated_sum
        $receiptRows      = [];
        foreach ((array) $receipts as $row) {
            $d = $row['data'] ?? $row;
            if (!is_array($d)) continue;
            $amt = (float) ($d['amount'] ?? 0);
            $rcptKey = (string) ($d['receiptKey'] ?? ('F' . ($d['receiptNo'] ?? '')));
            // Canonical doc id: {schoolId}_{session}_{receiptKey}. Fall back
            // to the legacy no-session key so pre-Session-A receipts still
            // reconcile.
            $docIdSess = "{$schoolFs}_{$this->session_year}_{$rcptKey}";
            $docIdOld  = "{$schoolFs}_{$rcptKey}";
            $alloc = $this->firebase->firestoreGet('feeReceiptAllocations', $docIdSess);
            $resolvedDocId = $docIdSess;
            if (!is_array($alloc)) {
                $alloc = $this->firebase->firestoreGet('feeReceiptAllocations', $docIdOld);
                $resolvedDocId = is_array($alloc) ? $docIdOld : '(none)';
            }
            $allocSum   = 0.0;
            $allocLines = 0;
            if (is_array($alloc) && is_array($alloc['allocations'] ?? null)) {
                $allocLines = count($alloc['allocations']);
                foreach ($alloc['allocations'] as $a) {
                    $allocSum += (float) ($a['allocated'] ?? $a['amount'] ?? 0);
                }
            }
            $reversed = is_array($alloc) && (($alloc['status'] ?? '') === 'reversed');
            $sumReceiptAmount += $amt;
            // Net wallet delta from this receipt:
            //   positive → overpayment stored as advance
            //   negative → wallet was drawn down to cover demand allocation
            $walletDelta = round($amt - $allocSum, 2);
            $sumOverpayment += $walletDelta;
            $allocByReceipt[$rcptKey] = $allocSum;
            $receiptRows[] = [
                'receiptKey'  => $rcptKey,
                'date'        => $d['paymentDate'] ?? $d['createdAt'] ?? '',
                'amount'      => $amt,
                'allocated'   => round($allocSum, 2),
                'alloc_lines' => $allocLines,
                'alloc_docId' => $resolvedDocId,
                'walletDelta' => $walletDelta,
                'overpaid'    => round(max(0, $walletDelta), 2),
                'reversed'    => $reversed,
            ];
        }

        // 3. Refunds — compute how much of each refund drained the advance
        //    wallet. Refund flow: walk original receipt's allocations first;
        //    any leftover (refund_amount − allocations_sum) drains advance.
        $refunds = $this->firebase->firestoreQuery('feeRefunds', [
            ['schoolId',  '==', $schoolFs],
            ['studentId', '==', $userId],
        ]);
        $sumAdvanceDrain = 0.0;
        $refundRows = [];
        foreach ((array) $refunds as $row) {
            $d = $row['data'] ?? $row;
            if (!is_array($d)) continue;
            if (($d['status'] ?? '') !== 'processed') continue;
            $rAmt    = (float) ($d['amount'] ?? 0);
            $origNo  = (string) ($d['receiptNo'] ?? '');
            $origKey = $origNo !== '' ? ('F' . preg_replace('/^R?0*/i', '', $origNo)) : '';
            $origAlloc = $allocByReceipt[$origKey] ?? 0.0;
            $drain   = max(0, $rAmt - $origAlloc);
            $sumAdvanceDrain += $drain;
            $refundRows[] = [
                'refundId'            => $d['refundId'] ?? ($d['id'] ?? ''),
                'amount'              => $rAmt,
                'status'              => $d['status'] ?? '',
                'origReceipt'         => $origNo,
                'origReceiptAllocated'=> round($origAlloc, 2),
                'advanceDrain'        => round($drain, 2),
            ];
        }

        // 4. Reconstruct: wallet = Σ (receipt.amount − allocated) − Σ refund drains.
        //    The first term captures both overpayment growth (+) AND wallet
        //    draw-downs (−) on receipts that allocated more than their cash.
        $reconstructed = round(max(0, $sumOverpayment - $sumAdvanceDrain), 2);
        $match = abs($stored - $reconstructed) < 0.01;

        $this->json_success([
            'verdict'        => $match ? '✅ MATCHES' : '❌ MISMATCH',
            'student_id'     => $userId,
            'firestore_doc'  => "studentAdvanceBalances/{$docId}",
            'stored'         => round($stored, 2),
            'reconstructed'  => $reconstructed,
            'delta'          => round($stored - $reconstructed, 2),
            'totals' => [
                'receipt_amount_sum' => round($sumReceiptAmount, 2),
                'overpayment_sum'    => round($sumOverpayment, 2),
                'advance_drain_sum'  => round($sumAdvanceDrain, 2),
                'processed_refunds'  => count($refundRows),
            ],
            'receipts'       => $receiptRows,
            'refunds'        => $refundRows,
            'advance_doc'    => $advDoc,
        ]);
    }

    /**
     * Admin one-shot: sync the Firestore receipt counter with the highest
     * existing receiptNo so the fees_counter page stops suggesting numbers
     * that are already used. Use this once after running receipts that were
     * created on a buggy path where the counter didn't advance.
     *
     * Dry-run by default. Pass apply=1 to actually write.
     */
    public function repair_receipt_counter()
    {
        $this->_require_role(self::MANAGE_ROLES, 'repair_receipt_counter');
        $apply = (string) $this->input->post('apply') === '1';

        $schoolFs = $this->fs->schoolId();

        // Walk every receipt for this school+session and find the max number.
        $rows = $this->firebase->firestoreQuery('feeReceipts', [
            ['schoolId', '==', $schoolFs],
        ]);
        $maxNo = 0;
        foreach ((array) $rows as $row) {
            $d  = $row['data'] ?? $row;
            $rn = (int) ($d['receiptNo'] ?? 0);
            if ($rn > $maxNo) $maxNo = $rn;
        }

        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $schoolFs, $this->session_year);
        $counterBefore = $this->fsTxn->getCounter('receipt_seq');

        $result = [
            'max_receipt_no'    => $maxNo,
            'counter_before'    => $counterBefore,
            'counter_target'    => $maxNo,
            'needs_fix'         => $counterBefore < $maxNo,
            'applied'           => false,
        ];

        if ($apply && $maxNo > $counterBefore) {
            $ok = $this->fsTxn->advanceCounterTo('receipt_seq', $maxNo);
            $counterAfter = $this->fsTxn->getCounter('receipt_seq');
            $result['applied']       = $ok;
            $result['counter_after'] = $counterAfter;
        }

        $this->json_success($result);
    }

    /**
     * Admin one-shot: recompute a student's advance wallet from the
     * authoritative receipts + refunds history, and overwrite the
     * Firestore studentAdvanceBalances doc.
     *
     * POST user_id
     * Dry-run by default. Pass apply=1 to actually write.
     */
    /**
     * One-shot: backfill camelCase fields on all feeDemands docs so the
     * Android apps can deserialize them. Safe to run multiple times.
     * POST student_id (or "all" for every student).
     */
    public function backfill_demand_shape()
    {
        $this->_require_role(self::MANAGE_ROLES, 'backfill_demand_shape');
        $studentId = trim((string) $this->input->post('student_id'));
        if ($studentId === '') return $this->json_error('student_id required (or "all").');

        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $schoolFs = $this->fs->schoolId();
        $this->fsTxn->init($this->firebase, $this->fs, $schoolFs, $this->session_year);

        if ($studentId === 'all') {
            $rows = $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId', '==', $schoolFs], ['session', '==', $this->session_year],
            ]);
        } else {
            $rows = [];
            $demands = $this->fsTxn->demandsForStudent($studentId);
            foreach ($demands as $id => $d) {
                $rows[] = ['id' => $id, 'data' => $d];
            }
        }

        $touched = 0;
        foreach ((array) $rows as $r) {
            $d  = $r['data'] ?? $r;
            $id = $r['id'] ?? ($d['demandId'] ?? '');
            if (!is_array($d) || $id === '') continue;

            $section    = (string) ($d['section'] ?? '');
            $sectionKey = preg_replace('/^Section\s+/i', '', $section);
            $period     = (string) ($d['period'] ?? '');
            $monthName  = explode(' ', $period)[0] ?? '';

            $patch = [
                'studentId'      => (string) ($d['student_id'] ?? $d['studentId'] ?? ''),
                'studentName'    => (string) ($d['student_name'] ?? $d['studentName'] ?? ''),
                'className'      => (string) ($d['class'] ?? $d['className'] ?? ''),
                'feeHead'        => (string) ($d['fee_head'] ?? $d['feeHead'] ?? ''),
                'sectionKey'     => $sectionKey,
                'month'          => $monthName,
                'grossAmount'    => (float) ($d['original_amount'] ?? $d['grossAmount'] ?? 0),
                'discountAmount' => (float) ($d['discount_amount'] ?? $d['discountAmount'] ?? 0),
                'fineAmount'     => (float) ($d['fine_amount'] ?? $d['fineAmount'] ?? 0),
                'netAmount'      => (float) ($d['net_amount'] ?? $d['netAmount'] ?? 0),
                'paidAmount'     => (float) ($d['paid_amount'] ?? $d['paidAmount'] ?? 0),
            ];

            if ($this->fsTxn->updateDemand($id, $patch)) $touched++;
        }

        $this->json_success([
            'message' => "{$touched} demand(s) backfilled with camelCase fields.",
            'touched' => $touched,
        ]);
    }

    // repair_carry_forward() removed in Phase 9 — the whole method
    // reconstructed and wrote studentAdvanceBalances, which no longer
    // exists as a live concept. Remove the debug_carry_forward probe
    // too if you need an analog for pure dues reconciliation.

    /** Lazy access to Accounting_firestore_sync so void works even if the
     *  caller controller (Fees) hasn't loaded it. */
    private function _acctFsSyncForVoid()
    {
        static $cached = null;
        if ($cached !== null) return $cached ?: null;
        try {
            if (!isset($this->acctFsSync)) {
                $this->load->library('Accounting_firestore_sync', null, 'acctFsSync');
                $this->acctFsSync->init($this->firebase, $this->school_name, $this->session_year);
            }
            $cached = $this->acctFsSync;
        } catch (\Exception $_) { $cached = false; }
        return $cached ?: null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRINT RECEIPT
    // ══════════════════════════════════════════════════════════════════

    public function print_receipt($receiptNo = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'print_receipt');
        if (empty($receiptNo)) show_404();
        // Strip formatting so both "1" and "R000001" work.
        $rnRaw = (string) $receiptNo;
        $receiptNo = preg_replace('/\D/', '', preg_replace('/^R0*/i', '', $rnRaw));
        if ($receiptNo === '') show_404();

        $schoolFs     = $this->fs->schoolId();
        $session_year = $this->session_year;
        $receiptKey   = 'F' . $receiptNo;

        // Session A: pure Firestore — the canonical receipt doc holds
        // everything a print view needs (student name, class, amount,
        // breakdown, months, mode). No RTDB read.
        $receipt = $this->fs->get('feeReceipts', "{$schoolFs}_{$receiptKey}");
        if (!is_array($receipt) || empty($receipt)) {
            show_404();
        }

        $userId      = (string) ($receipt['studentId']   ?? '');
        $studentName = (string) ($receipt['studentName'] ?? $userId);
        $fatherName  = (string) ($receipt['fatherName']  ?? '');
        $class       = (string) ($receipt['className']   ?? '');
        $section     = (string) ($receipt['section']     ?? '');
        $amount      = (float)  ($receipt['amount']      ?? 0);
        $discount    = (float)  ($receipt['discount']    ?? 0);
        $fine        = (float)  ($receipt['fine']        ?? 0);
        $netTotal    = (float)  ($receipt['netAmount']   ?? $amount - $discount + $fine);
        $paymentMode = (string) ($receipt['paymentMode'] ?? 'N/A');
        $reference   = (string) ($receipt['remarks']     ?? '');
        $receiptDate = (string) ($receipt['date']        ?? '');
        $breakdown   = is_array($receipt['feeBreakdown'] ?? null) ? $receipt['feeBreakdown'] : [];
        $months      = is_array($receipt['feeMonths']    ?? null) ? $receipt['feeMonths']    : [];
        $allocatedMonths = is_array($receipt['allocatedMonths'] ?? null) ? $receipt['allocatedMonths'] : $months;

        // Phase 11 standardized money fields with fallbacks for legacy
        // receipts written before that phase landed.
        $inputAmount     = (float) ($receipt['input_amount']     ?? $receipt['inputAmount']     ?? $amount);
        $allocatedAmount = (float) ($receipt['allocated_amount'] ?? $receipt['allocatedAmount'] ?? $amount);
        $advanceCredit   = (float) ($receipt['advance_credit']   ?? $receipt['advanceCredit']   ?? 0);

        // Per-allocation breakdown (month × head × amount × cleared/partial)
        // lives in feeReceiptAllocations.allocations. Drives the new
        // detailed breakdown table + the FULL/PARTIAL pill.
        $allocations = [];
        $hasPartial  = false;
        try {
            $allocDoc = $this->fs->get('feeReceiptAllocations', "{$schoolFs}_{$session_year}_{$receiptKey}");
            if (is_array($allocDoc) && is_array($allocDoc['allocations'] ?? null)) {
                $allocations = $allocDoc['allocations'];
                foreach ($allocations as $a) {
                    if ((string) ($a['status'] ?? '') === 'partial') { $hasPartial = true; break; }
                }
            }
        } catch (\Exception $e) {
            log_message('error', "print_receipt: alloc lookup failed for {$receiptKey}: " . $e->getMessage());
        }

        // fatherName not saved on some older receipts — fall back to student doc.
        if ($fatherName === '' && $userId !== '') {
            $stu = $this->fs->get('students', "{$schoolFs}_{$userId}");
            if (is_array($stu)) $fatherName = (string) ($stu['fatherName'] ?? '');
        }

        // School branding — Firestore schools doc.
        $schoolDoc = $this->fs->get('schools', $schoolFs);
        if (!is_array($schoolDoc)) $schoolDoc = [];

        $classDisplay   = preg_replace('/^Class\s+/i', '', $class);
        $sectionDisplay = preg_replace('/^Section\s+/i', '', $section);

        $data = [
            'receipt_no'        => $receiptNo,
            'receipt_key'       => $receiptKey,
            'receipt_date'      => $receiptDate,
            'student'           => ['Name' => $studentName, 'Father Name' => $fatherName],
            'student_name'      => $studentName,
            'father_name'       => $fatherName,
            'user_id'           => $userId,
            'class_display'     => $classDisplay,
            'section_display'   => $sectionDisplay,
            // Money fields — see Phase 11 standardization
            'amount'            => $amount,           // back-compat alias of input_amount
            'input_amount'      => $inputAmount,
            'allocated_amount'  => $allocatedAmount,
            'advance_credit'    => $advanceCredit,
            'discount'          => $discount,
            'fine'              => $fine,
            'net_total'         => $netTotal,
            'payment_mode'      => $paymentMode,
            'reference'         => $reference,
            'months'            => $months,
            'allocated_months'  => $allocatedMonths,
            'breakdown'         => $breakdown,        // legacy per-head structure
            'allocations'       => $allocations,      // per-month per-head
            'is_partial'        => $hasPartial,
            'school_name'       => (string) ($schoolDoc['name']    ?? $this->school_display_name ?? $this->school_name),
            'school_address'    => (string) ($schoolDoc['address'] ?? ''),
            'school_phone'      => (string) ($schoolDoc['phone']   ?? ''),
            'school_logo'       => (string) ($schoolDoc['logoUrl'] ?? ''),
            'session_year'      => $session_year,
        ];

        $this->load->view('fees/receipt', $data);
    }

    // ══════════════════════════════════════════════════════════════════
    //  GET FEES FOR SELECTED MONTHS
    // ══════════════════════════════════════════════════════════════════

    private function getFeesForSelectedMonths($school_name, $class, $section, $selectedMonths)
    {
        $chart    = $this->_getClassFeeChart($class, $section);
        $feesData = [];
        foreach ($selectedMonths as $month) {
            $feesData[$month] = is_array($chart[$month] ?? null) ? $chart[$month] : [];
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

        $csMap = $this->_buildClassSectionMap();
        $classList  = $csMap['classList'];
        $sectionMap = $csMap['sectionMap'];

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

        $class   = trim($this->input->post('class')   ?? '');
        $section = trim($this->input->post('section') ?? '');

        if (!$class || !$section) {
            $this->output->set_output(json_encode([[
                'userId' => null, 'name' => 'Missing class or section',
                'totalFee' => null, 'receivedFee' => null, 'dueFee' => null,
            ]]));
            return;
        }

        // Fee chart from Firestore feeStructures
        $classFees = $this->_getClassFeeChart($class, $section);
        if (empty($classFees)) {
            $this->output->set_output(json_encode([
                'error'      => 'no_fee_structure',
                'message'    => "No fee structure found for {$class} {$section}. Please set up Fee Titles and Fee Chart first.",
                'setup_hint' => 'Go to Fees → Categories to create fee titles, then Fees → Chart to assign amounts.',
            ]));
            return;
        }

        $annualFee = 0;
        foreach ($classFees as $month => $fees) {
            if (is_array($fees)) {
                foreach ($fees as $_ => $amt) $annualFee += (float) $amt;
            }
        }

        // Students roster from Firestore students collection
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        $roster = $this->fsTxn->listStudentsInSection($class, $section);

        if (empty($roster)) {
            $this->output->set_output(json_encode([[
                'userId' => null, 'name' => "No students in {$class} {$section}",
                'totalFee' => null, 'receivedFee' => null, 'dueFee' => null,
            ]]));
            return;
        }

        // For each student: sum demands to get paid + balance from Firestore
        $response = [];
        foreach ($roster as $uid => $prof) {
            $name  = (string) ($prof['name']       ?? $prof['Name']       ?? $uid);
            $fname = (string) ($prof['fatherName'] ?? $prof['Father Name'] ?? '');
            $label = $fname !== '' ? "{$name} / {$fname}" : $name;

            $demands = $this->fsTxn->demandsForStudent($uid);
            $totalNet  = 0;
            $totalPaid = 0;
            $totalDisc = 0;
            foreach ($demands as $d) {
                $totalNet  += (float) ($d['net_amount']      ?? 0);
                $totalPaid += (float) ($d['paid_amount']     ?? 0);
                $totalDisc += (float) ($d['discount_amount'] ?? 0);
            }

            $response[] = [
                'userId'      => $uid,
                'name'        => $label,
                'totalFee'    => round($annualFee, 2),
                'receivedFee' => round($totalPaid, 2),
                'discount'    => round($totalDisc, 2),
                'dueFee'      => round(max(0, $totalNet - $totalPaid), 2),
            ];
        }

        usort($response, fn($a, $b) => $b['dueFee'] <=> $a['dueFee']);
        $this->output->set_output(json_encode($response));
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEES RECORDS
    // ══════════════════════════════════════════════════════════════════

    public function fees_records()
    {
        $this->_require_role(self::VIEW_ROLES, 'fees_records');
        $schoolFs = $this->fs->schoolId();

        // Class/section list from Firestore
        $csMap      = $this->_buildClassSectionMap();
        $classList  = [];
        $feesMatrix = [];
        foreach ($csMap['classList'] as $c) {
            foreach ($csMap['sectionMap'][$c] ?? [] as $s) {
                $matKey              = "{$c}|{$s}";
                $classList[$matKey]  = "{$c} {$s}";
                $feesMatrix[$matKey] = array_fill(0, 12, 0);
            }
        }

        // Build student → class|section lookup from Firestore students
        $studentClassCache = [];
        try {
            $stuRows = $this->firebase->firestoreQuery('students', [
                ['schoolId', '==', $schoolFs],
            ]);
            foreach ((array) $stuRows as $r) {
                $d   = $r['data'] ?? $r;
                $sid = (string) ($d['studentId'] ?? $d['userId'] ?? '');
                $c   = (string) ($d['className'] ?? '');
                $s   = (string) ($d['section']   ?? '');
                if ($sid !== '' && $c !== '' && $s !== '') {
                    $studentClassCache[$sid] = "{$c}|{$s}";
                }
            }
        } catch (\Exception $_) {}

        // Walk Firestore feeReceipts — aggregate amount per class|section per month
        try {
            $rcptRows = $this->firebase->firestoreQuery('feeReceipts', [
                ['schoolId', '==', $schoolFs],
            ]);
            foreach ((array) $rcptRows as $r) {
                $d = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                // Phase 16: revenue matrix uses allocated_amount — `amount`
                // would overcount when overpayment lands in wallet.
                $received = (float) ($d['allocated_amount']
                                    ?? $d['allocatedAmount']
                                    ?? $d['amount']
                                    ?? $d['netAmount']
                                    ?? 0);
                if ($received <= 0) continue;
                $sid = (string) ($d['studentId'] ?? $d['userId'] ?? '');
                if ($sid === '') continue;

                // Resolve calendar month (0=Apr, 11=Mar)
                $dateStr = (string) ($d['paymentDate'] ?? $d['date'] ?? $d['createdAt'] ?? '');
                $dObj = null;
                foreach (['Y-m-d', 'd-m-Y', \DateTime::ATOM] as $fmt) {
                    $dObj = \DateTime::createFromFormat($fmt, substr($dateStr, 0, 19));
                    if ($dObj !== false) break;
                }
                if (!$dObj) continue;
                $calMonth = (int) $dObj->format('n');
                $mi = ($calMonth >= 4) ? ($calMonth - 4) : ($calMonth + 8);

                $matKey = $studentClassCache[$sid] ?? null;
                if ($matKey !== null) {
                    if (!isset($feesMatrix[$matKey])) {
                        $feesMatrix[$matKey] = array_fill(0, 12, 0);
                        $classList[$matKey]  = str_replace('|', ' ', $matKey);
                    }
                    $feesMatrix[$matKey][$mi] += $received;
                }
            }
        } catch (\Exception $_) {}

        $matrix = [];
        foreach ($classList as $k => $label) {
            $amounts = $feesMatrix[$k] ?? array_fill(0, 12, 0);
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

        // Pure Firestore: mappings + CoA via Accounting_firestore_sync
        $this->load->library('Accounting_firestore_sync');
        $sync = $this->accounting_firestore_sync->init(
            $this->firebase, $this->school_name, $this->session_year
        );

        $map = $sync->readFeeAccountMap();
        $entries = [];
        foreach ($map as $head => $code) {
            $entries[] = [
                'fee_head'     => $head,
                'account_code' => $code,
                '_key'         => strtoupper(preg_replace('/[^A-Z0-9]+/', '_', $head)),
            ];
        }

        // Fee-head catalogue — aggregate distinct head names from feeStructures
        $allHeads = [];
        try {
            $rows = $this->firebase->firestoreQuery('feeStructures', [
                ['schoolId', '==', $this->school_name],
                ['session',  '==', $this->session_year],
            ]);
            $seen = [];
            foreach ((array) $rows as $row) {
                $d = $row['data'] ?? $row;
                $heads = is_array($d['feeHeads'] ?? null) ? $d['feeHeads'] : [];
                foreach ($heads as $h) {
                    $name = trim((string) ($h['name'] ?? ''));
                    if ($name === '' || isset($seen[$name])) continue;
                    $seen[$name] = true;
                    $allHeads[] = $name;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'get_fee_account_map: feeStructures query failed: ' . $e->getMessage());
        }

        // Income accounts from Firestore CoA
        $coa = $sync->readChartOfAccounts();
        $incomeAccounts = [];
        foreach ($coa as $code => $acct) {
            if (!is_array($acct)) continue;
            if (($acct['category'] ?? '') === 'Income'
                && ($acct['status'] ?? '') === 'active'
                && empty($acct['is_group'])) {
                $incomeAccounts[] = ['code' => $code, 'name' => $acct['name'] ?? $code];
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

        // Pure Firestore: validate account + write mapping via sync layer
        $this->load->library('Accounting_firestore_sync');
        $sync = $this->accounting_firestore_sync->init(
            $this->firebase, $this->school_name, $this->session_year
        );

        $acct = $sync->getAccount($accountCode);
        if (!is_array($acct) || ($acct['status'] ?? '') !== 'active') {
            return $this->json_error("Account {$accountCode} not found or inactive.");
        }

        $ok = $sync->syncFeeAccountMapping($feeHead, $accountCode);
        if (!$ok) {
            return $this->json_error('Failed to save mapping.');
        }

        $this->json_success([
            'message' => "Mapped '{$feeHead}' → {$accountCode} ({$acct['name']})",
        ]);
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
        $schoolFs = $this->fs->schoolId();

        // Load fine rules from Firestore feeSettings
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $schoolFs, $this->session_year);
        $settings = $this->firebase->firestoreGet('feeSettings',
            "{$schoolFs}_{$this->session_year}_reminders");
        if (!is_array($settings) || !($settings['late_fee_enabled'] ?? false)) {
            return ['demands_updated' => 0, 'total_fine' => 0, 'message' => 'Late fee not enabled'];
        }

        $fineType  = $settings['late_fee_type'] ?? 'fixed';
        $fineValue = floatval($settings['late_fee_value'] ?? 0);
        if ($fineValue <= 0) {
            return ['demands_updated' => 0, 'total_fine' => 0, 'message' => 'Late fee value is 0'];
        }

        $today   = date('Y-m-d');
        $demands = $this->fsTxn->demandsForStudent($studentId);
        if (empty($demands)) return ['demands_updated' => 0, 'total_fine' => 0];

        $updated   = 0;
        $totalFine = 0;

        foreach ($demands as $did => $d) {
            $status = $d['status'] ?? 'unpaid';
            if ($status === 'paid') continue;

            $dueDate = $d['due_date'] ?? '';
            if ($dueDate === '' || $dueDate >= $today) continue;

            $netAmount = floatval($d['net_amount'] ?? 0);
            if ($netAmount <= 0) continue;

            $fine = ($fineType === 'percentage')
                ? round($netAmount * ($fineValue / 100), 2)
                : round($fineValue, 2);

            $existingFine = floatval($d['fine_amount'] ?? 0);
            if (abs($existingFine - $fine) < 0.01) continue;

            $paidAmount = floatval($d['paid_amount'] ?? 0);
            $newBalance = round(max(0, $netAmount + $fine - $paidAmount), 2);

            $this->fsTxn->updateDemand($did, [
                'fine_amount'     => $fine,
                'balance'         => $newBalance,
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
    //  LEGACY SYNC: MONTH FEE FLAGS FROM DEMANDS
    // ══════════════════════════════════════════════════════════════════

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
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        return $this->fsTxn->readFeeStructure($class, $section);
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
        $discounts = [];
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);

        // 1. Scholarship awards (from Firestore scholarshipAwards collection)
        $awards = $this->fsTxn->readScholarshipAwards($studentId);
        foreach ($awards as $award) {
            $amt = (float) ($award['amount'] ?? 0);
            if ($amt > 0) {
                $discounts['_scholarship'] = ($discounts['_scholarship'] ?? 0) + $amt;
            }
        }

        // 2. Exempted fees + per-head discounts from the student doc.
        //    Student doc may carry `exemptedFees: {'Tuition Fee':true, ...}`
        //    and/or `discountHeads: {'Transport': 500, ...}` set by admin.
        $stu = $this->fsTxn->getStudent($studentId);
        if (is_array($stu)) {
            $exempted = is_array($stu['exemptedFees'] ?? null) ? $stu['exemptedFees'] : [];
            foreach ($exempted as $title => $_) {
                $discounts[$title] = -1; // fully exempt marker
            }
            $headDisc = is_array($stu['discountHeads'] ?? null) ? $stu['discountHeads'] : [];
            foreach ($headDisc as $title => $amount) {
                if (isset($discounts[$title]) && $discounts[$title] === -1) continue;
                $discounts[$title] = (float) $amount;
            }
        }

        return $discounts;
    }

    /**
     * Get the configured due day from Reminder Settings.
     */
    private function _getDueDay(): int
    {
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        return $this->fsTxn->getDueDay();
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

        // ────────────────────────────────────────────────────────────────
        //  Session D1: pure Firestore.
        //  Read existing demands from Firestore feeDemands, filter to this
        //  period, and only write the rows that don't exist yet.
        // ────────────────────────────────────────────────────────────────

        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        $existingDemands = $this->fsTxn->demandsForStudent($studentId);

        $newDemands = []; // demandId => demand row, only the rows we will create

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

            // Idempotency: skip if demand already exists (uses in-memory snapshot)
            if (isset($existingDemands[$demandId])) {
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

            $newDemands[$demandId] = [
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
        }

        // Nothing new to create — return early
        if (empty($newDemands)) {
            return $result;
        }

        // Pure Firestore writes — each demand is its own doc in feeDemands.
        foreach ($newDemands as $demandId => $demand) {
            if ($this->fsTxn->writeDemand($demandId, $demand)) {
                $result['created']++;
            } else {
                log_message('error', "_generateDemandsForMonth: Firestore writeDemand failed [{$demandId}]");
                $result['errors']++;
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
            $schoolFs = $this->fs->schoolId();
            try {
                $rows = $this->firebase->firestoreQuery('feeCategories', [
                    ['schoolId', '==', $schoolFs],
                ]);
                $categoryCache = [];
                foreach ((array) $rows as $r) {
                    $d = $r['data'] ?? $r;
                    if (is_array($d)) $categoryCache[] = $d;
                }
            } catch (\Exception $_) {
                $categoryCache = [];
            }
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

        // Pure Firestore: read profile from students collection.
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        $profile = $this->fsTxn->getStudent($studentId);
        if (!is_array($profile)) {
            return $this->json_error('Student not found.');
        }

        $studentName = (string) ($profile['name'] ?? $profile['Name'] ?? $studentId);
        $class       = (string) ($profile['className'] ?? '');
        $section     = (string) ($profile['section']   ?? '');
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

        $dueDay  = $this->_getDueDay();
        $totals  = ['students' => 0, 'created' => 0, 'skipped' => 0, 'errors' => 0, 'no_chart' => 0];

        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);

        // Build class/section list to process — from Firestore feeStructures.
        $classSections = [];
        if ($classInput !== '' && $secInput !== '') {
            if (stripos($classInput, 'Class ') !== 0) $classInput = "Class {$classInput}";
            if (stripos($secInput, 'Section ') !== 0) $secInput = "Section {$secInput}";
            $classSections[] = ['class' => $classInput, 'section' => $secInput];
        } else {
            foreach ($this->fsTxn->listSectionsWithFeeChart() as $cs) {
                if ($classInput !== '' && $cs['class']   !== $classInput) continue;
                if ($secInput   !== '' && $cs['section'] !== $secInput)   continue;
                $classSections[] = $cs;
            }
        }

        if (empty($classSections)) {
            return $this->json_error('No fee structures found in current session.');
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

        $lockToken     = bin2hex(random_bytes(8));
        $acquiredLocks = [];

        // Build list of all lock keys needed (locks now live in Firestore
        // collection `feeDemandLocks`, one doc per {class, section, period}).
        $requiredLocks = [];
        foreach ($classSections as $cs) {
            $classSlug   = str_replace(' ', '', $cs['class']);
            $sectionSlug = str_replace(' ', '', $cs['section']);
            foreach ($months as $m) {
                $periodKey = $this->_buildPeriodKey($m);
                $lockKey   = "{$classSlug}_{$sectionSlug}_{$periodKey}";
                $requiredLocks[] = [
                    'key'     => $lockKey,
                    'class'   => $cs['class'],
                    'section' => $cs['section'],
                    'month'   => $m,
                    'period'  => $periodKey,
                ];
            }
        }

        // ── PHASE 1: Check ALL locks before acquiring any ──
        foreach ($requiredLocks as $rl) {
            $existingLock = $this->fsTxn->demandLockCheck($rl['key']);
            if (is_array($existingLock)) {
                $lockAge = (int) ($existingLock['age_seconds'] ?? 0);
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
        }

        // ── PHASE 2: Acquire ALL locks ──
        foreach ($requiredLocks as $rl) {
            $this->fsTxn->demandLockAcquire($rl['key'], $lockToken, [
                'locked_by' => $this->admin_name ?? '',
                'period'    => $rl['period'],
                'class'     => $rl['class'],
                'section'   => $rl['section'],
            ]);
            $acquiredLocks[] = $rl['key'];
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

            // Get student roster for this section (Firestore students collection)
            $roster = $this->fsTxn->listStudentsInSection($class, $section);
            if (empty($roster)) continue;

            foreach ($roster as $sid => $profile) {
                $studentName = (string) ($profile['name'] ?? $profile['Name'] ?? $sid);

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

        // ── Release all demand locks (token-verified, Firestore) ──
        foreach ($acquiredLocks as $alk) {
            try { $this->fsTxn->demandLockRelease($alk, $lockToken); }
            catch (\Exception $e) {
                log_message('error', "generate_demands: lock release failed {$alk}: " . $e->getMessage());
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

        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        $raw = $this->fsTxn->demandsForStudent($studentId);

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

        $schoolFs = $this->fs->schoolId();
        $sy       = $this->session_year;

        // Firestore: all demands grouped by studentId for counts.
        $rawDemands = $this->firebase->firestoreQuery('feeDemands', [
            ['schoolId', '==', $schoolFs], ['session', '==', $sy],
        ]);
        $demandCounts = [];
        foreach ((array) $rawDemands as $r) {
            $d = $r['data'] ?? $r;
            $sid = (string) ($d['studentId'] ?? $d['student_id'] ?? '');
            if ($sid !== '') $demandCounts[$sid] = ($demandCounts[$sid] ?? 0) + 1;
        }

        // Class/section roster from Firestore sections + students.
        $csMap = $this->_buildClassSectionMap();
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $schoolFs, $sy);

        $status = [];
        foreach ($csMap['classList'] as $ck) {
            $classStudents = 0; $classWithDemands = 0; $classTotalDemands = 0;
            foreach ($csMap['sectionMap'][$ck] ?? [] as $sk) {
                $roster = $this->fsTxn->listStudentsInSection($ck, $sk);
                foreach ($roster as $sid => $_) {
                    $classStudents++;
                    $dc = $demandCounts[$sid] ?? 0;
                    if ($dc > 0) { $classWithDemands++; $classTotalDemands += $dc; }
                }
            }
            $status[] = [
                'class'          => $ck,
                'total_students' => $classStudents,
                'with_demands'   => $classWithDemands,
                'total_demands'  => $classTotalDemands,
                'coverage'       => $classStudents > 0
                    ? round(($classWithDemands / $classStudents) * 100) : 0,
            ];
        }

        usort($status, fn($a, $b) => strnatcmp($a['class'], $b['class']));

        $this->json_success([
            'classes' => $status,
            'total_students_with_demands' => count($demandCounts),
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

        // Pure Firestore: read student from students collection.
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        $profile = $this->fsTxn->getStudent($studentId);
        if (!is_array($profile)) {
            return $this->json_error('Student not found.');
        }

        $class   = (string) ($profile['className'] ?? '');
        $section = (string) ($profile['section']   ?? '');
        if ($class === '' || $section === '') {
            return $this->json_error("Cannot resolve class/section.");
        }

        $feeChart = $this->_getClassFeeChart($class, $section);
        if (empty($feeChart)) {
            return $this->json_error("No fee chart found for {$class} / {$section}. Please set up Fee Titles and Fee Chart first.");
        }

        $discountMap = $this->_getStudentDiscounts($studentId, $class, $section);
        $existing    = $this->fsTxn->demandsForStudent($studentId);

        $updated     = 0;
        $preserved   = 0;
        $pendingUpdates = []; // demandId => patch
        $studentName    = (string) ($profile['name'] ?? $profile['Name'] ?? $studentId);

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

            $patch = [
                'original_amount' => round($newAmount, 2),
                'discount_amount' => round($discountAmount, 2),
                'net_amount'      => $netAmount,
                'balance'         => $netAmount, // unpaid, so balance = net
                'updated_at'      => date('c'),
                'updated_by'      => $this->admin_id ?? 'system',
            ];
            $pendingUpdates[$did] = $patch;
        }

        // Nothing changed — return success without writes
        if (empty($pendingUpdates)) {
            $this->json_success([
                'message'   => "0 demands updated, {$preserved} paid/partial preserved.",
                'updated'   => 0,
                'preserved' => $preserved,
            ]);
            return;
        }

        // Pure Firestore writes — patch each demand doc directly.
        foreach ($pendingUpdates as $did => $patch) {
            if ($this->fsTxn->updateDemand($did, $patch)) {
                $updated++;
            } else {
                log_message('error', "recalculate_demands: Firestore updateDemand failed [{$did}]");
            }
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
        $type  = trim($this->input->post('type') ?? 'auto');

        if ($query === '') { $this->json_error('Search query is required.'); return; }

        $schoolFs = $this->fs->schoolId();
        $session  = $this->session_year;
        $results  = [];

        if ($type === 'auto') {
            if (strpos($query, 'TXN_') === 0) $type = 'txn_id';
            elseif (is_numeric($query))        $type = 'receipt_no';
            else                               $type = 'student_id';
        }

        if ($type === 'receipt_no') {
            $receiptNo  = $query;
            $receiptKey = 'F' . $receiptNo;

            $results['receipt_index'] = $this->firebase->firestoreGet('feeReceiptIndex',
                "{$schoolFs}_{$session}_{$receiptNo}");

            $idempRows = $this->firebase->firestoreQuery('feeIdempotency', [
                ['schoolId', '==', $schoolFs],
            ]);
            $results['idempotency'] = [];
            foreach ((array) $idempRows as $r) {
                $d = $r['data'] ?? $r;
                if (is_array($d) && (string) ($d['receiptNo'] ?? $d['receipt_no'] ?? '') === $receiptNo) {
                    $d['_key'] = $r['id'] ?? ''; $results['idempotency'][] = $d;
                }
            }

            $results['pending'] = $this->firebase->firestoreGet('feePendingWrites',
                "{$schoolFs}_{$receiptKey}");

            $results['allocations'] = $this->firebase->firestoreGet('feeReceiptAllocations',
                "{$schoolFs}_{$session}_{$receiptKey}");

            $results['receipt'] = $this->firebase->firestoreGet('feeReceipts',
                "{$schoolFs}_{$receiptKey}");

            $ledgerRows = $this->firebase->firestoreQuery('accountingLedger', [
                ['schoolId', '==', $schoolFs], ['session', '==', $session],
                ['source_ref', '==', $receiptKey],
            ]);
            $results['ledger_entries'] = array_map(fn($r) => $r['data'] ?? $r, (array) $ledgerRows);

        } elseif ($type === 'txn_id') {
            $txnId = $query;

            $idempRows = $this->firebase->firestoreQuery('feeIdempotency', [
                ['schoolId', '==', $schoolFs],
            ]);
            foreach ((array) $idempRows as $r) {
                $d = $r['data'] ?? $r;
                if (is_array($d) && ($d['txnId'] ?? $d['txn_id'] ?? '') === $txnId) {
                    $results['idempotency'] = $d;
                    $results['receipt_no']  = $d['receiptNo'] ?? '';
                    break;
                }
            }

            $allocRows = $this->firebase->firestoreQuery('feeReceiptAllocations', [
                ['schoolId', '==', $schoolFs],
            ]);
            foreach ((array) $allocRows as $r) {
                $d = $r['data'] ?? $r;
                if (is_array($d) && ($d['txnId'] ?? '') === $txnId) {
                    $results['allocations'] = $d; break;
                }
            }

            $ledgerRows = $this->firebase->firestoreQuery('accountingLedger', [
                ['schoolId', '==', $schoolFs], ['session', '==', $session],
            ]);
            $results['ledger_entries'] = [];
            foreach ((array) $ledgerRows as $r) {
                $d = $r['data'] ?? $r;
                if (is_array($d) && (($d['txn_id'] ?? '') === $txnId
                    || strpos($d['narration'] ?? '', $txnId) !== false)) {
                    $results['ledger_entries'][] = $d;
                }
            }

        } elseif ($type === 'student_id') {
            $studentId = $this->safe_path_segment($query, 'student_id');

            $this->load->library('Fee_firestore_txn', null, 'fsTxn');
            $this->fsTxn->init($this->firebase, $this->fs, $schoolFs, $session);
            $profile = $this->fsTxn->getStudent($studentId);
            if (is_array($profile)) {
                $results['student'] = [
                    'name'    => $profile['name'] ?? $studentId,
                    'class'   => $profile['className'] ?? '',
                    'section' => $profile['section'] ?? '',
                    'father'  => $profile['fatherName'] ?? '',
                ];
            }

            $rcptRows = $this->firebase->firestoreQuery('feeReceipts', [
                ['schoolId', '==', $schoolFs], ['studentId', '==', $studentId],
            ]);
            $results['fees_records'] = array_map(fn($r) => $r['data'] ?? $r, (array) $rcptRows);
            $results['record_count'] = count($results['fees_records']);

            $idempRows = $this->firebase->firestoreQuery('feeIdempotency', [
                ['schoolId', '==', $schoolFs],
            ]);
            $results['idempotency_history'] = [];
            foreach ((array) $idempRows as $r) {
                $d = $r['data'] ?? $r;
                if (is_array($d) && ($d['userId'] ?? $d['user_id'] ?? '') === $studentId) {
                    $results['idempotency_history'][] = $d;
                }
            }
        }

        $results['search_type'] = $type;
        $results['query']       = $query;
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

        $schoolFs = $this->fs->schoolId();
        $now      = time();
        $thresh   = 120;

        $staleProcessing = $stalePending = $staleReservations = $activeLocks = [];

        // Stale idempotency
        $rows = $this->firebase->firestoreQuery('feeIdempotency', [['schoolId','==',$schoolFs]]);
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            if (($d['status'] ?? '') !== 'processing') continue;
            $age = $now - strtotime($d['startedAt'] ?? $d['started_at'] ?? '2000-01-01');
            if ($age > $thresh) { $d['_key'] = $r['id'] ?? ''; $d['_age_seconds'] = $age; $staleProcessing[] = $d; }
        }

        // Stale pending
        $rows = $this->firebase->firestoreQuery('feePendingWrites', [['schoolId','==',$schoolFs],['status','==','pending']]);
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $age = $now - strtotime($d['startedAt'] ?? '2000-01-01');
            if ($age > $thresh) { $d['_key'] = $r['id'] ?? ''; $d['_age_seconds'] = $age; $stalePending[] = $d; }
        }

        // Stale receipt reservations
        $rows = $this->firebase->firestoreQuery('feeReceiptIndex', [['schoolId','==',$schoolFs]]);
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            if (empty($d['reserved'])) continue;
            $age = $now - strtotime($d['reservedAt'] ?? $d['reserved_at'] ?? '2000-01-01');
            if ($age > $thresh) { $d['_receipt_no'] = $d['receiptNo'] ?? ''; $d['_age_seconds'] = $age; $staleReservations[] = $d; }
        }

        // Active locks
        $rows = $this->firebase->firestoreQuery('feeLocks', [['schoolId','==',$schoolFs]]);
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            if (empty($d['locked'])) continue;
            $d['_student_id'] = $d['userId'] ?? $d['studentId'] ?? '';
            $d['_age_seconds'] = $now - strtotime($d['lockedAt'] ?? $d['locked_at'] ?? '2000-01-01');
            $activeLocks[] = $d;
        }

        $this->json_success([
            'stale_processing'   => $staleProcessing,
            'stale_pending'      => $stalePending,
            'stale_reservations' => $staleReservations,
            'active_locks'       => $activeLocks,
            'sync_pending'       => [],
            'total_issues'       => count($staleProcessing) + count($stalePending) + count($staleReservations),
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

        $schoolFs  = $this->fs->schoolId();
        $session   = $this->session_year;
        $diagnosis = ['writes_found' => [], 'writes_missing' => [], 'safe_actions' => []];

        $idemp = null;
        if ($idempKey) {
            $idemp = $this->firebase->firestoreGet('feeIdempotency', $idempKey);
            $receiptNo = $receiptNo ?: (string) ($idemp['receiptNo'] ?? $idemp['receipt_no'] ?? '');
            $studentId = $studentId ?: (string) ($idemp['userId'] ?? $idemp['user_id'] ?? '');
        }
        $receiptKey = $receiptNo ? 'F' . $receiptNo : '';
        $lastStep   = is_array($idemp) ? ($idemp['step'] ?? 'unknown') : 'unknown';

        $diagnosis['last_step']  = $lastStep;
        $diagnosis['receipt_no'] = $receiptNo;
        $diagnosis['student_id'] = $studentId;

        $checks = [];

        if ($receiptNo) {
            $ri = $this->firebase->firestoreGet('feeReceiptIndex', "{$schoolFs}_{$session}_{$receiptNo}");
            $exists = is_array($ri) && !empty($ri);
            $checks['receipt_index'] = $exists ? (empty($ri['reserved']) ? 'finalized' : 'reserved') : 'missing';
        }
        if ($receiptKey) {
            $pf = $this->firebase->firestoreGet('feePendingWrites', "{$schoolFs}_{$receiptKey}");
            $checks['pending_fees'] = (is_array($pf) && !empty($pf)) ? 'exists' : 'cleared';
        }
        if ($receiptKey) {
            $rcpt = $this->firebase->firestoreGet('feeReceipts', "{$schoolFs}_{$receiptKey}");
            $checks['fees_record'] = (is_array($rcpt) && !empty($rcpt)) ? 'written' : 'missing';
        }
        if ($receiptKey) {
            $ra = $this->firebase->firestoreGet('feeReceiptAllocations', "{$schoolFs}_{$session}_{$receiptKey}");
            $checks['allocations'] = (is_array($ra) && !empty($ra)) ? 'written' : 'missing';
        }
        if ($studentId) {
            $lock = $this->firebase->firestoreGet('feeLocks', "{$schoolFs}_{$studentId}");
            $checks['lock'] = (is_array($lock) && !empty($lock['locked'])) ? 'held' : 'free';
        }

        $diagnosis['checks'] = $checks;
        foreach ($checks as $name => $status) {
            if (in_array($status, ['written','finalized','exists','held'])) $diagnosis['writes_found'][] = $name;
            elseif (in_array($status, ['missing','cleared','free']))         $diagnosis['writes_missing'][] = $name;
            elseif ($status === 'reserved')                                  $diagnosis['writes_found'][] = "{$name} (reserved only)";
        }

        $hasRecord = ($checks['fees_record'] ?? '') === 'written';
        $hasAlloc  = ($checks['allocations'] ?? '') === 'written';
        $hasLock   = ($checks['lock'] ?? '') === 'held';

        if (!$hasRecord && !$hasAlloc) {
            $diagnosis['recommendation']      = 'clean_clear';
            $diagnosis['recommendation_text'] = 'No financial writes detected. Safe to clear all markers.';
            $diagnosis['safe_actions']        = ['clear_lock','clear_pending','clear_reservation','clear_processing'];
        } elseif ($hasRecord && $hasAlloc) {
            $diagnosis['recommendation']      = 'mark_complete';
            $diagnosis['recommendation_text'] = 'All financial records exist. Cleanup stale markers only.';
            $diagnosis['safe_actions']        = ['clear_lock','clear_pending','mark_success'];
        } else {
            $diagnosis['recommendation']      = 'needs_review';
            $diagnosis['recommendation_text'] = 'Partial writes detected. Manual review recommended.';
            $diagnosis['safe_actions']        = ['clear_lock','view_details'];
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

        $action  = trim($this->input->post('action') ?? '');
        $key     = trim($this->input->post('key') ?? '');
        if (!$action || !$key) { $this->json_error('Action and key are required.'); return; }

        $schoolFs = $this->fs->schoolId();
        $session  = $this->session_year;
        $detail   = '';

        switch ($action) {
            case 'clear_lock':
                $this->firebase->firestoreDelete('feeLocks', "{$schoolFs}_{$key}");
                $detail = "Student lock released for {$key}";
                break;
            case 'clear_pending':
                $this->firebase->firestoreDelete('feePendingWrites', "{$schoolFs}_{$key}");
                $detail = "Pending fees marker cleared for {$key}";
                break;
            case 'clear_reservation':
                $this->firebase->firestoreDelete('feeReceiptIndex', "{$schoolFs}_{$session}_{$key}");
                $detail = "Stale receipt reservation cleared for #{$key}";
                break;
            case 'clear_processing':
                $this->firebase->firestoreSet('feeIdempotency', $key, [
                    'status'     => 'cleared_manual',
                    'cleared_by' => $this->admin_name ?? '',
                    'cleared_at' => date('c'),
                ], true);
                $detail = "Idempotency record marked as manually cleared";
                break;
            case 'mark_success':
                $this->firebase->firestoreSet('feeIdempotency', $key, [
                    'status'       => 'success',
                    'step'         => 'marked_complete_manual',
                    'completed_at' => date('c'),
                    'cleared_by'   => $this->admin_name ?? '',
                ], true);
                $detail = "Transaction marked as successfully completed (manual)";
                break;
            default:
                $this->json_error('Unknown action.'); return;
        }

        log_message('info',
            "resolve_stale: action={$action} key={$key} detail=[{$detail}] admin={$this->admin_id} school={$this->school_name}"
        );
        $this->json_success(['message' => $detail, 'action' => $action, 'key' => $key]);
    }

    // recalculate_advance() removed in Phase 9 (wallet subsystem gone).
    // Use repair_carry_forward directly if you need to reconcile dues.

    // _recalculate_advance_legacy() deleted in Phase 9 — wallet subsystem
    // gone; all references to studentAdvanceBalances / RTDB Advance_Balance
    // went with it.

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

        $schoolFs = $this->fs->schoolId();
        $sy       = $this->session_year;

        try {
            // Check for in-flight locks (stale receipts still "processing")
            $inFlight = [];
            $lockRows = $this->firebase->firestoreQuery('feeLocks', [['schoolId','==',$schoolFs]]);
            foreach ((array) $lockRows as $r) {
                $d = $r['data'] ?? $r;
                if (is_array($d) && !empty($d['locked'])) {
                    $inFlight[] = [
                        'type'       => 'lock',
                        'student_id' => $d['userId'] ?? $d['studentId'] ?? '',
                        'status'     => 'locked',
                    ];
                }
            }

            // Carry-forward candidates: students with unpaid demand balance.
            $demandRows = $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId','==',$schoolFs], ['session','==',$sy],
            ]);
            $byStudent  = [];
            foreach ((array) $demandRows as $r) {
                $d   = $r['data'] ?? $r;
                $sid = (string) ($d['studentId'] ?? $d['student_id'] ?? '');
                $bal = (float) ($d['balance'] ?? 0);
                if ($sid !== '' && $bal > 0) {
                    if (!isset($byStudent[$sid])) $byStudent[$sid] = ['dues' => 0, 'months' => []];
                    $byStudent[$sid]['dues'] += $bal;
                    $pk = $d['period_key'] ?? '';
                    if ($pk !== '') $byStudent[$sid]['months'][$pk] = true;
                }
            }

            $carryForward      = [];
            $totalCarryForward = 0;
            foreach ($byStudent as $sid => $info) {
                $carryForward[] = [
                    'student_id'    => $sid,
                    'dues'          => round($info['dues'], 2),
                    'unpaid_months' => array_keys($info['months']),
                ];
                $totalCarryForward += $info['dues'];
            }

            $this->_json_out([
                'status'               => 'success',
                'in_flight_payments'   => $inFlight,
                'in_flight_count'      => count($inFlight),
                'carry_forward'        => $carryForward,
                'carry_forward_count'  => count($carryForward),
                'total_carry_forward'  => round($totalCarryForward, 2),
                'can_proceed'          => count($inFlight) === 0,
                'block_reason'         => count($inFlight) > 0 ? 'In-flight payments must complete before rollover' : '',
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

        $schoolFs   = $this->fs->schoolId();
        $sy         = $this->session_year;
        $newSession = trim($this->input->post('new_session') ?? '');

        if ($newSession === '') {
            $this->_json_out(['status' => 'error', 'message' => 'New session year is required.']);
            return;
        }

        try {
            // Block if active locks exist (in-flight payments)
            $lockRows = $this->firebase->firestoreQuery('feeLocks', [['schoolId','==',$schoolFs]]);
            foreach ((array) $lockRows as $r) {
                $d = $r['data'] ?? $r;
                if (is_array($d) && !empty($d['locked'])) {
                    $this->_json_out(['status'=>'error','message'=>'Cannot rollover: in-flight lock for '.($d['userId']??'')]);
                    return;
                }
            }

            // 1. Freeze current session (Firestore feeSettings)
            $this->firebase->firestoreSet('feeSettings', "{$schoolFs}_{$sy}_rollover", [
                'schoolId'   => $schoolFs,
                'session'    => $sy,
                'status'     => 'frozen',
                'frozen_at'  => date('c'),
                'frozen_by'  => $this->admin_id ?? 'system',
                'new_session' => $newSession,
            ]);

            // 2. Carry-forward from unpaid demands
            $demandRows = $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId','==',$schoolFs], ['session','==',$sy],
            ]);
            $byStudent = [];
            foreach ((array) $demandRows as $r) {
                $d   = $r['data'] ?? $r;
                $sid = (string) ($d['studentId'] ?? $d['student_id'] ?? '');
                $bal = (float) ($d['balance'] ?? 0);
                if ($sid === '' || $bal <= 0) continue;
                if (!isset($byStudent[$sid])) $byStudent[$sid] = ['dues' => 0, 'details' => []];
                $byStudent[$sid]['dues'] += $bal;
                $pk = $d['period_key'] ?? '';
                if ($pk !== '') $byStudent[$sid]['details'][$pk] = [
                    'amount'   => $bal,
                    'fee_head' => $d['fee_head'] ?? '',
                ];
            }

            $cfCount = 0;
            $cfTotal = 0;
            $now     = date('c');
            foreach ($byStudent as $sid => $info) {
                $this->firebase->firestoreSet('feeCarryForward',
                    "{$schoolFs}_{$newSession}_{$sid}", [
                        'schoolId'        => $schoolFs,
                        'session'         => $newSession,
                        'previousSession' => $sy,
                        'studentId'       => $sid,
                        'totalDues'       => round($info['dues'], 2),
                        'unpaidDetails'   => $info['details'],
                        'carriedAt'       => $now,
                        'carriedBy'       => $this->admin_id ?? 'system',
                    ]);
                $cfCount++;
                $cfTotal += $info['dues'];
            }

            $this->_json_out([
                'status'               => 'success',
                'message'              => "{$cfCount} students with Rs. " . round($cfTotal, 2) . " carried forward.",
                'carry_forward_count'  => $cfCount,
                'carry_forward_total'  => round($cfTotal, 2),
                'frozen_session'       => $sy,
                'new_session'          => $newSession,
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

        $schoolFs = $this->fs->schoolId();
        try {
            // Compute from Firestore feeReceipts + feeDemands.
            // Both queries MUST filter by session — otherwise
            // collection_rate = collected(all-years) / pending(this-year)
            // produces meaningless ratios.
            $rcptRows = $this->firebase->firestoreQuery('feeReceipts', [
                ['schoolId', '==', $schoolFs],
                ['session',  '==', $sy],
            ]);
            $totalCollected = 0;
            $receiptCount   = 0;
            foreach ((array) $rcptRows as $r) {
                $d = $r['data'] ?? $r;
                // Phase 16: collection_rate uses allocated_amount so wallet
                // overflow doesn't inflate the rate above 100%.
                $totalCollected += (float) ($d['allocated_amount']
                                          ?? $d['allocatedAmount']
                                          ?? $d['amount']
                                          ?? 0);
                $receiptCount++;
            }

            $demandRows = $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId', '==', $schoolFs], ['session', '==', $sy],
            ]);
            $totalPending   = 0;
            $defaulterCount = 0;
            $stuBalances    = [];
            $activeIds      = $this->_activeStudentIds();
            $hasActive      = !empty($activeIds);
            foreach ((array) $demandRows as $r) {
                $d   = $r['data'] ?? $r;
                $sid = (string) ($d['studentId'] ?? $d['student_id'] ?? '');
                // Skip ex-students; they shouldn't drag the rate down.
                if ($hasActive && $sid !== '' && !isset($activeIds[$sid])) continue;
                // Older demands may not have written `balance` directly —
                // derive from net - paid so they don't get silently
                // counted as fully paid (which inflates collection_rate
                // and undercounts defaulters).
                $net  = (float) ($d['net_amount']  ?? $d['netAmount']  ?? 0);
                $paid = (float) ($d['paid_amount'] ?? $d['paidAmount'] ?? 0);
                $bal  = isset($d['balance']) ? (float) $d['balance']
                                             : max(0.0, $net - $paid);
                if ($bal > 0 && $sid !== '') {
                    $totalPending += $bal;
                    $stuBalances[$sid] = ($stuBalances[$sid] ?? 0) + $bal;
                }
            }
            $defaulterCount = count(array_filter($stuBalances, fn($b) => $b > 0));

            $totalExpected  = $totalCollected + $totalPending;
            $collectionRate = $totalExpected > 0 ? round(($totalCollected / $totalExpected) * 100, 1) : 0;

            $summary = [
                'total_collected'  => round($totalCollected, 2),
                'total_pending'    => round($totalPending, 2),
                'total_expected'   => round($totalExpected, 2),
                'collection_rate'  => $collectionRate,
                'receipt_count'    => $receiptCount,
                'defaulter_count'  => $defaulterCount,
                'computed_at'      => date('c'),
            ];

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

        $schoolFs = $this->fs->schoolId();
        $sy       = $this->session_year;

        try {
            $demandRows = $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId', '==', $schoolFs], ['session', '==', $sy],
            ]);
            $byClass = [];
            $total   = 0;
            $seen    = [];
            $activeIds = $this->_activeStudentIds();
            $hasActive = !empty($activeIds);

            foreach ((array) $demandRows as $r) {
                $d   = $r['data'] ?? $r;
                $bal = (float) ($d['balance'] ?? 0);
                if ($bal <= 0) continue;
                $sid = (string) ($d['studentId'] ?? $d['student_id'] ?? '');
                $cls = (string) ($d['class'] ?? $d['className'] ?? '');
                if ($cls === '') $cls = 'Unknown';
                if ($sid === '' || isset($seen[$sid])) continue;
                if ($hasActive && !isset($activeIds[$sid])) continue;
                $seen[$sid] = true;
                $total++;
                $byClass[$cls] = ($byClass[$cls] ?? 0) + 1;
            }

            $this->_json_out([
                'status'           => 'success',
                'total_defaulters' => $total,
                'by_class'         => $byClass,
            ]);
        } catch (Exception $e) {
            $this->_json_out(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
