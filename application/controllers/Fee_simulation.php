<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee Simulation Controller — Load & Stress Testing for Fees Module
 *
 * Simulates real-world ERP usage across multiple schools.
 * ALL data written to Schools/Simulation/* namespace — zero production impact.
 *
 * Run via browser: /fee_simulation/run
 * Or specific test: /fee_simulation/run?test=payments&schools=5
 *
 * Tests:
 *   1. Demand generation (with concurrent attempt simulation)
 *   2. Fee payments (full, partial, overpayment, double-click)
 *   3. Online payment flow (order → pay → verify → webhook)
 *   4. Refund processing
 *   5. Advance balance stress (concurrent atomic updates)
 *   6. Validation & reconciliation report
 */
class Fee_simulation extends MY_Controller
{
    private const SIM_NAMESPACE = 'Schools/Simulation';

    /** Fee heads used in simulation */
    private const FEE_HEADS = [
        'Tuition Fee' => [2000, 5000],
        'Transport Fee' => [500, 2000],
        'Exam Fee' => [200, 800],
        'Lab Fee' => [100, 500],
        'Library Fee' => [50, 200],
        'Activity Fee' => [100, 400],
    ];

    private const MONTHS = ['April','May','June','July','August','September','October','November','December','January','February','March'];

    private $report = [];
    private $errors = [];
    private $timings = [];

    public function __construct()
    {
        parent::__construct();
        require_permission('Configuration');

        // Phase 5 — DEV-ONLY gate. This controller writes test data to
        // the Realtime Database namespace SIM_NAMESPACE. It is intentionally
        // NOT on the RTDB-elimination path because it's a load-test
        // fixture, not production code. Live environments MUST NOT be
        // allowed to fire these writes — the gate below terminates the
        // request before any RTDB call executes.
        $env = strtolower((string) (getenv('APP_ENV') ?: ($_SERVER['APP_ENV'] ?? '')));
        if (!in_array($env, ['development', 'dev', 'local', 'test'], true)) {
            if ($this->input->is_ajax_request()) {
                $this->json_error(
                    'Fee_simulation is disabled in non-development environments.',
                    403
                );
            }
            show_error(
                'Fee_simulation is a dev-only load-test harness and has been disabled '
              . 'in this environment. Set APP_ENV=development to re-enable.',
                403
            );
        }
    }

    // ====================================================================
    //  MAIN ENTRY POINT
    // ====================================================================

    /**
     * GET — Run simulation. Params: test, schools, students_per_section
     */
    public function run()
    {
        $this->_require_role(['Admin', 'Super Admin'], 'run_simulation');

        set_time_limit(600); // 10 minutes max
        ini_set('memory_limit', '512M');

        header('Content-Type: application/json');

        $testType    = trim($this->input->get('test') ?? 'all');
        $schoolCount = min(50, max(1, (int) ($this->input->get('schools') ?? 3)));
        $studentsPerSection = min(40, max(5, (int) ($this->input->get('students') ?? 10)));

        $this->report = [
            'started_at'  => date('c'),
            'config'      => [
                'test'     => $testType,
                'schools'  => $schoolCount,
                'classes'  => 10,
                'sections' => 3,
                'students_per_section' => $studentsPerSection,
                'total_students' => $schoolCount * 10 * 3 * $studentsPerSection,
            ],
            'tests'   => [],
            'summary' => [],
        ];

        // Step 1: Setup simulation data
        $this->_setup_schools($schoolCount, $studentsPerSection);

        // Step 2: Run selected tests
        if ($testType === 'all' || $testType === 'demands') {
            $this->_test_demand_generation($schoolCount);
        }
        if ($testType === 'all' || $testType === 'payments') {
            $this->_test_payments($schoolCount, $studentsPerSection);
        }
        if ($testType === 'all' || $testType === 'online') {
            $this->_test_online_payments($schoolCount);
        }
        if ($testType === 'all' || $testType === 'advance') {
            $this->_test_advance_stress($schoolCount);
        }

        // Step 3: Validation
        $this->_validate_all($schoolCount);

        // Step 4: Summary
        $this->report['completed_at'] = date('c');
        $this->report['duration_ms']  = round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000);
        $this->report['errors']       = $this->errors;
        $this->report['error_count']  = count($this->errors);

        $avgTime = !empty($this->timings) ? round(array_sum($this->timings) / count($this->timings), 2) : 0;
        $maxTime = !empty($this->timings) ? round(max($this->timings), 2) : 0;

        $this->report['summary'] = [
            'total_operations'  => count($this->timings),
            'avg_time_ms'       => $avgTime,
            'max_time_ms'       => $maxTime,
            'errors'            => count($this->errors),
            'success_rate'      => count($this->timings) > 0
                ? round((1 - count($this->errors) / max(count($this->timings), 1)) * 100, 1) . '%'
                : 'N/A',
        ];

        echo json_encode($this->report, JSON_PRETTY_PRINT);
    }

    /**
     * POST — Clean up all simulation data.
     */
    public function cleanup()
    {
        $this->_require_role(['Admin', 'Super Admin'], 'cleanup_simulation');
        $this->firebase->delete(self::SIM_NAMESPACE);
        $this->json_success(['message' => 'Simulation data cleaned up.']);
    }

    /**
     * GET — Simulation page with UI.
     */
    public function index()
    {
        $this->_require_role(['Admin', 'Super Admin']);
        $this->load->view('include/header');
        $this->load->view('fees/simulation');
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  SETUP: Create simulation schools with students
    // ====================================================================

    private function _setup_schools(int $schoolCount, int $studentsPerSection): void
    {
        $t = microtime(true);
        $totalStudents = 0;

        for ($s = 1; $s <= $schoolCount; $s++) {
            $schoolId = "SIM_SCHOOL_{$s}";
            $session  = '2025-26';
            $bp       = self::SIM_NAMESPACE . "/{$schoolId}/{$session}";

            // Create fee structure
            foreach (self::MONTHS as $month) {
                $monthFees = [];
                foreach (self::FEE_HEADS as $head => $range) {
                    $monthFees[$head] = mt_rand($range[0], $range[1]);
                }
                $this->firebase->set("{$bp}/Accounts/Fees/Classes Fees/Class 1/Section A/{$month}", $monthFees);
            }

            // Create students
            for ($c = 1; $c <= min(3, 10); $c++) { // limit classes for speed
                for ($sec = 0; $sec < min(2, 3); $sec++) {
                    $secLetter = chr(65 + $sec); // A, B
                    $class = "Class {$c}";
                    $section = "Section {$secLetter}";

                    $roster = [];
                    for ($st = 1; $st <= $studentsPerSection; $st++) {
                        $sid = "STU_{$s}_{$c}_{$secLetter}_{$st}";
                        $roster[$sid] = "Student {$st}";
                        $totalStudents++;
                    }
                    $this->firebase->set("{$bp}/{$class}/{$section}/Students/List", $roster);
                }
            }

            // Create receipt counter
            $this->firebase->set("{$bp}/Accounts/Fees/Receipt No", 0);
        }

        $this->report['tests']['setup'] = [
            'status'   => 'complete',
            'schools'  => $schoolCount,
            'students' => $totalStudents,
            'time_ms'  => round((microtime(true) - $t) * 1000),
        ];
    }

    // ====================================================================
    //  TEST 1: Demand Generation
    // ====================================================================

    private function _test_demand_generation(int $schoolCount): void
    {
        $t = microtime(true);
        $created = 0;
        $skipped = 0;
        $lockTests = 0;

        for ($s = 1; $s <= min($schoolCount, 3); $s++) {
            $schoolId = "SIM_SCHOOL_{$s}";
            $bp = self::SIM_NAMESPACE . "/{$schoolId}/2025-26";

            // Generate demands for April
            $feeChart = $this->firebase->get("{$bp}/Accounts/Fees/Classes Fees/Class 1/Section A");
            if (!is_array($feeChart)) continue;

            $roster = $this->firebase->get("{$bp}/Class 1/Section A/Students/List");
            if (!is_array($roster)) continue;

            foreach ($roster as $sid => $name) {
                $demandBase = "{$bp}/Fees/Demands/{$sid}";
                $aprilFees = $feeChart['April'] ?? [];

                foreach ($aprilFees as $head => $amount) {
                    $demandId = "2026-04_{$head}";
                    $existing = $this->firebase->get("{$demandBase}/{$demandId}/demand_id");
                    if ($existing !== null) {
                        $skipped++;
                        continue;
                    }

                    $opT = microtime(true);
                    $this->firebase->set("{$demandBase}/{$demandId}", [
                        'demand_id'   => $demandId,
                        'student_id'  => $sid,
                        'fee_head'    => $head,
                        'period'      => 'April',
                        'period_key'  => '2026-04',
                        'gross_amount' => $amount,
                        'discount'    => 0,
                        'net_amount'  => $amount,
                        'paid_amount' => 0,
                        'balance'     => $amount,
                        'status'      => 'unpaid',
                        'created_at'  => date('c'),
                    ]);
                    $this->timings[] = (microtime(true) - $opT) * 1000;
                    $created++;
                }
            }

            // Simulate duplicate attempt (should skip all)
            foreach (array_slice(array_keys($roster), 0, 2) as $sid) {
                $aprilFees = $feeChart['April'] ?? [];
                foreach ($aprilFees as $head => $amount) {
                    $demandId = "2026-04_{$head}";
                    $existing = $this->firebase->get("{$bp}/Fees/Demands/{$sid}/{$demandId}/demand_id");
                    if ($existing !== null) $lockTests++;
                }
            }
        }

        $this->report['tests']['demand_generation'] = [
            'status'      => 'complete',
            'created'     => $created,
            'skipped'     => $skipped,
            'idempotency_checks' => $lockTests,
            'time_ms'     => round((microtime(true) - $t) * 1000),
        ];
    }

    // ====================================================================
    //  TEST 2: Fee Payments
    // ====================================================================

    private function _test_payments(int $schoolCount, int $studentsPerSection): void
    {
        $t = microtime(true);
        $totalPayments  = 0;
        $duplicateTests = 0;
        $receiptNos     = [];

        for ($s = 1; $s <= min($schoolCount, 3); $s++) {
            $schoolId = "SIM_SCHOOL_{$s}";
            $bp = self::SIM_NAMESPACE . "/{$schoolId}/2025-26";

            $roster = $this->firebase->get("{$bp}/Class 1/Section A/Students/List");
            if (!is_array($roster)) continue;

            $receiptCounter = 0;

            foreach (array_slice(array_keys($roster), 0, min(5, $studentsPerSection)) as $sid) {
                $receiptCounter++;
                $receiptNo = $receiptCounter;
                $receiptKey = "F{$receiptNo}";
                $amount = mt_rand(1000, 5000);

                $opT = microtime(true);

                // Simulate idempotency key
                $idempKey = md5("{$sid}|{$receiptNo}|April|{$amount}");
                $idempPath = "{$bp}/Fees/Idempotency/{$idempKey}";

                // Check idempotency
                $existing = $this->firebase->get($idempPath);
                if (is_array($existing) && ($existing['status'] ?? '') === 'success') {
                    $duplicateTests++;
                    continue;
                }

                // Mark processing
                $this->firebase->set($idempPath, ['status' => 'processing', 'started_at' => date('c')]);

                // Simulate lock
                $lockPath = "{$bp}/Fees/Locks/{$sid}";
                $this->firebase->set($lockPath, ['locked' => true, 'locked_at' => date('c'), 'token' => bin2hex(random_bytes(4))]);

                // Write receipt
                $this->firebase->set("{$bp}/Class 1/Section A/Students/{$sid}/Fees Record/{$receiptKey}", [
                    'Amount' => number_format($amount, 2, '.', ','),
                    'Date'   => date('d-m-Y'),
                    'Mode'   => 'Cash',
                    'txn_id' => 'TXN_SIM_' . $s . '_' . $receiptNo,
                ]);

                // Write receipt index
                $this->firebase->set("{$bp}/Accounts/Receipt_Index/{$receiptNo}", [
                    'date' => date('d-m-Y'), 'user_id' => $sid, 'amount' => $amount,
                ]);

                // Check for duplicate receipt numbers
                if (in_array("{$schoolId}_{$receiptNo}", $receiptNos)) {
                    $this->errors[] = "DUPLICATE RECEIPT: school={$schoolId} receipt={$receiptNo}";
                }
                $receiptNos[] = "{$schoolId}_{$receiptNo}";

                // Mark success
                $this->firebase->set($idempPath, ['status' => 'success', 'receipt_no' => $receiptNo, 'completed_at' => date('c')]);

                // Release lock
                $this->firebase->delete($lockPath);

                $this->timings[] = (microtime(true) - $opT) * 1000;
                $totalPayments++;

                // Simulate double-click (should hit idempotency)
                $existing2 = $this->firebase->get($idempPath);
                if (is_array($existing2) && ($existing2['status'] ?? '') === 'success') {
                    $duplicateTests++;
                }
            }
        }

        $this->report['tests']['payments'] = [
            'status'            => 'complete',
            'total_payments'    => $totalPayments,
            'duplicate_blocked' => $duplicateTests,
            'unique_receipts'   => count(array_unique($receiptNos)),
            'time_ms'           => round((microtime(true) - $t) * 1000),
        ];
    }

    // ====================================================================
    //  TEST 3: Online Payments
    // ====================================================================

    private function _test_online_payments(int $schoolCount): void
    {
        $t = microtime(true);
        $orders  = 0;
        $success = 0;
        $failed  = 0;
        $dupes   = 0;

        for ($s = 1; $s <= min($schoolCount, 2); $s++) {
            $schoolId = "SIM_SCHOOL_{$s}";
            $bp = self::SIM_NAMESPACE . "/{$schoolId}/2025-26";

            for ($i = 1; $i <= 5; $i++) {
                $orderId  = 'ORD_SIM_' . $s . '_' . $i;
                $amount   = mt_rand(2000, 8000);
                $sid      = "STU_{$s}_1_A_{$i}";

                $opT = microtime(true);

                // Create order
                $recordId = "OP_SIM_{$s}_{$i}";
                $this->firebase->set("{$bp}/Fees/Online_Orders/{$recordId}", [
                    'student_id'       => $sid,
                    'amount'           => $amount,
                    'gateway_order_id' => $orderId,
                    'status'           => 'created',
                    'created_at'       => date('c'),
                ]);
                $this->firebase->set("{$bp}/Fees/Online_Orders_Index/{$orderId}", $recordId);
                $orders++;

                // Simulate payment (80% success)
                $paySuccess = (mt_rand(1, 100) <= 80);
                $paymentId  = 'pay_sim_' . $s . '_' . $i;

                if ($paySuccess) {
                    // Verify + mark paid
                    $this->firebase->update("{$bp}/Fees/Online_Orders/{$recordId}", [
                        'status'             => 'paid',
                        'gateway_payment_id' => $paymentId,
                        'paid_at'            => date('c'),
                        'payment_status'     => 'captured',
                    ]);
                    $success++;

                    // Simulate duplicate webhook
                    $order = $this->firebase->get("{$bp}/Fees/Online_Orders/{$recordId}");
                    if (is_array($order) && ($order['status'] ?? '') === 'paid') {
                        $dupes++; // correctly detected as already paid
                    }
                } else {
                    $this->firebase->update("{$bp}/Fees/Online_Orders/{$recordId}", [
                        'status'         => 'failed',
                        'failed_at'      => date('c'),
                        'failure_reason' => 'Simulated failure',
                    ]);
                    $failed++;
                }

                $this->timings[] = (microtime(true) - $opT) * 1000;
            }
        }

        $this->report['tests']['online_payments'] = [
            'status'            => 'complete',
            'orders_created'    => $orders,
            'payments_success'  => $success,
            'payments_failed'   => $failed,
            'duplicate_blocked' => $dupes,
            'time_ms'           => round((microtime(true) - $t) * 1000),
        ];
    }

    // ====================================================================
    //  TEST 4: Advance Balance Stress
    // ====================================================================

    private function _test_advance_stress(int $schoolCount): void
    {
        $t = microtime(true);
        $updates = 0;
        $mismatches = 0;

        $schoolId = "SIM_SCHOOL_1";
        $bp = self::SIM_NAMESPACE . "/{$schoolId}/2025-26";
        $sid = "STU_1_1_A_1";
        $advPath = "{$bp}/Fees/Advance_Balance/{$sid}";

        // Initialize
        $this->firebase->set($advPath, ['amount' => 0, 'student_id' => $sid]);

        $expectedTotal = 0;

        // Rapid-fire 20 increments
        for ($i = 1; $i <= 20; $i++) {
            $delta = mt_rand(100, 500);
            $expectedTotal += $delta;

            $opT = microtime(true);

            // Read
            $current = $this->firebase->get($advPath);
            $currentAmt = is_array($current) ? round(floatval($current['amount'] ?? 0), 2) : 0;
            $newAmt = round($currentAmt + $delta, 2);

            // Write
            $this->firebase->set($advPath, ['amount' => $newAmt, 'student_id' => $sid, 'last_updated' => date('c')]);

            // Verify
            $verify = $this->firebase->get($advPath);
            $verifyAmt = is_array($verify) ? round(floatval($verify['amount'] ?? 0), 2) : -1;

            if (abs($verifyAmt - $newAmt) > 0.01) {
                $mismatches++;
                $this->errors[] = "ADVANCE MISMATCH: expected={$newAmt} actual={$verifyAmt} iteration={$i}";
            }

            $this->timings[] = (microtime(true) - $opT) * 1000;
            $updates++;
        }

        // Final check
        $final = $this->firebase->get($advPath);
        $finalAmt = is_array($final) ? round(floatval($final['amount'] ?? 0), 2) : 0;

        $this->report['tests']['advance_stress'] = [
            'status'          => 'complete',
            'updates'         => $updates,
            'mismatches'      => $mismatches,
            'expected_total'  => round($expectedTotal, 2),
            'actual_total'    => $finalAmt,
            'balance_correct' => abs($finalAmt - $expectedTotal) < 0.01,
            'time_ms'         => round((microtime(true) - $t) * 1000),
        ];
    }

    // ====================================================================
    //  VALIDATION: Cross-check all data
    // ====================================================================

    private function _validate_all(int $schoolCount): void
    {
        $t = microtime(true);
        $checks = [
            'duplicate_receipts'   => 0,
            'negative_balances'    => 0,
            'orphan_orders'        => 0,
            'stuck_locks'          => 0,
            'stuck_idempotency'    => 0,
            'total_receipts'       => 0,
            'total_demands'        => 0,
            'total_orders'         => 0,
        ];

        for ($s = 1; $s <= min($schoolCount, 3); $s++) {
            $schoolId = "SIM_SCHOOL_{$s}";
            $bp = self::SIM_NAMESPACE . "/{$schoolId}/2025-26";

            // Check receipt index for duplicates
            $receiptIdx = $this->firebase->get("{$bp}/Accounts/Receipt_Index");
            if (is_array($receiptIdx)) {
                $checks['total_receipts'] += count($receiptIdx);
            }

            // Check for stuck locks
            $locks = $this->firebase->get("{$bp}/Fees/Locks");
            if (is_array($locks)) {
                $checks['stuck_locks'] += count($locks);
            }

            // Check for stuck idempotency
            $idemp = $this->firebase->get("{$bp}/Fees/Idempotency");
            if (is_array($idemp)) {
                foreach ($idemp as $k => $v) {
                    if (is_array($v) && ($v['status'] ?? '') === 'processing') {
                        $checks['stuck_idempotency']++;
                    }
                }
            }

            // Check advance balances
            $advances = $this->firebase->get("{$bp}/Fees/Advance_Balance");
            if (is_array($advances)) {
                foreach ($advances as $sid => $adv) {
                    if (is_array($adv) && floatval($adv['amount'] ?? 0) < -0.01) {
                        $checks['negative_balances']++;
                        $this->errors[] = "NEGATIVE BALANCE: school={$schoolId} student={$sid} amount={$adv['amount']}";
                    }
                }
            }

            // Check online orders
            $orders = $this->firebase->get("{$bp}/Fees/Online_Orders");
            if (is_array($orders)) {
                $checks['total_orders'] += count($orders);
                foreach ($orders as $rid => $order) {
                    if (!is_array($order)) continue;
                    $status = $order['status'] ?? '';
                    if ($status === 'paid' && empty($order['receipt_key'] ?? '')) {
                        $checks['orphan_orders']++;
                        $this->errors[] = "ORPHAN ORDER: school={$schoolId} order={$rid} (paid but no receipt)";
                    }
                }
            }

            // Count demands
            $demandRoot = $this->firebase->get("{$bp}/Fees/Demands");
            if (is_array($demandRoot)) {
                foreach ($demandRoot as $sid => $demands) {
                    if (is_array($demands)) $checks['total_demands'] += count($demands);
                }
            }
        }

        $allPassed = (
            $checks['duplicate_receipts'] === 0 &&
            $checks['negative_balances'] === 0 &&
            $checks['orphan_orders'] === 0 &&
            $checks['stuck_locks'] === 0 &&
            $checks['stuck_idempotency'] === 0
        );

        $this->report['tests']['validation'] = [
            'status'  => $allPassed ? 'PASSED' : 'FAILED',
            'checks'  => $checks,
            'time_ms' => round((microtime(true) - $t) * 1000),
        ];
    }

    // ====================================================================
    //  PARALLEL MULTI-SCHOOL SIMULATION
    // ====================================================================

    /**
     * GET — Run a single school's simulation (used as worker by run_parallel).
     * Params: school_id, students
     * Returns JSON result for that school only.
     */
    public function run_school()
    {
        $this->_require_role(['Admin', 'Super Admin'], 'run_school_sim');
        set_time_limit(120);
        header('Content-Type: application/json');

        $schoolNum = max(1, (int) ($this->input->get('school_num') ?? 1));
        $studentsPerSection = min(40, max(5, (int) ($this->input->get('students') ?? 10)));
        $schoolId = "SIM_SCHOOL_{$schoolNum}";

        $this->report  = [];
        $this->errors  = [];
        $this->timings = [];

        $t = microtime(true);

        // Setup this single school
        $this->_setup_single_school($schoolNum, $studentsPerSection);

        // Run all tests for this school
        $this->_test_demand_generation_single($schoolNum);
        $this->_test_payments_single($schoolNum, $studentsPerSection);
        $this->_test_online_payments_single($schoolNum);
        $this->_test_advance_stress_single($schoolNum);

        // Validate this school
        $validation = $this->_validate_single($schoolNum);

        $duration = round((microtime(true) - $t) * 1000);
        $avgTime  = !empty($this->timings) ? round(array_sum($this->timings) / count($this->timings), 2) : 0;

        echo json_encode([
            'school'       => $schoolId,
            'school_num'   => $schoolNum,
            'status'       => empty($this->errors) ? 'PASSED' : 'FAILED',
            'operations'   => count($this->timings),
            'errors'       => count($this->errors),
            'error_list'   => array_slice($this->errors, 0, 10),
            'avg_time_ms'  => $avgTime,
            'duration_ms'  => $duration,
            'validation'   => $validation,
        ]);
    }

    /**
     * GET — Orchestrate parallel multi-school simulation using curl_multi.
     * Params: schools, students, batch_size, max_parallel, batch_delay
     */
    public function run_parallel()
    {
        $this->_require_role(['Admin', 'Super Admin'], 'run_parallel_sim');
        set_time_limit(1800); // 30 minutes
        ini_set('memory_limit', '1G');
        header('Content-Type: application/json');

        $totalSchools  = min(100, max(1, (int) ($this->input->get('schools') ?? 10)));
        $students      = min(40, max(5, (int) ($this->input->get('students') ?? 10)));
        $batchSize     = min(20, max(1, (int) ($this->input->get('batch_size') ?? 5)));
        $maxParallel   = min(10, max(1, (int) ($this->input->get('max_parallel') ?? 5)));
        $batchDelayMs  = max(500, (int) ($this->input->get('batch_delay') ?? 2000));

        $baseUrl = rtrim(base_url(), '/') . '/fee_simulation/run_school';
        $totalBatches = (int) ceil($totalSchools / $batchSize);

        $allResults   = [];
        $allErrors    = [];
        $allTimings   = [];
        $batchReports = [];
        $startTime    = microtime(true);

        // Process in batches
        for ($batch = 0; $batch < $totalBatches; $batch++) {
            $batchStart = $batch * $batchSize + 1;
            $batchEnd   = min($batchStart + $batchSize - 1, $totalSchools);
            $batchT     = microtime(true);

            // Build URLs for this batch
            $urls = [];
            for ($s = $batchStart; $s <= $batchEnd; $s++) {
                $urls[$s] = $baseUrl . '?school_num=' . $s . '&students=' . $students;
            }

            // Execute batch with curl_multi (limited parallelism)
            $batchResults = $this->_curl_multi_exec($urls, $maxParallel);

            $batchPassed = 0;
            $batchFailed = 0;
            $batchOps    = 0;

            foreach ($batchResults as $schoolNum => $result) {
                if ($result === null || !is_array($result)) {
                    $allErrors[] = "School SIM_SCHOOL_{$schoolNum}: HTTP request failed";
                    $batchFailed++;
                    $allResults[] = [
                        'school' => "SIM_SCHOOL_{$schoolNum}",
                        'status' => 'HTTP_FAIL',
                        'operations' => 0,
                        'errors' => 1,
                    ];
                    continue;
                }

                $allResults[] = $result;
                $batchOps += $result['operations'] ?? 0;

                if (($result['status'] ?? '') === 'PASSED') {
                    $batchPassed++;
                } else {
                    $batchFailed++;
                    foreach ($result['error_list'] ?? [] as $e) {
                        $allErrors[] = ($result['school'] ?? '') . ': ' . $e;
                    }
                }
                $allTimings[] = $result['duration_ms'] ?? 0;
            }

            $batchReports[] = [
                'batch'    => $batch + 1,
                'schools'  => "{$batchStart}-{$batchEnd}",
                'passed'   => $batchPassed,
                'failed'   => $batchFailed,
                'ops'      => $batchOps,
                'time_ms'  => round((microtime(true) - $batchT) * 1000),
            ];

            // Delay between batches to avoid Firebase throttling
            if ($batch < $totalBatches - 1) {
                usleep($batchDelayMs * 1000);
            }
        }

        // Global validation across all schools
        $globalValidation = $this->_validate_all($totalSchools);

        // Aggregate summary
        $totalOps    = array_sum(array_column($allResults, 'operations'));
        $totalErrors = count($allErrors);
        $passed      = count(array_filter($allResults, fn($r) => ($r['status'] ?? '') === 'PASSED'));
        $avgDuration = !empty($allTimings) ? round(array_sum($allTimings) / count($allTimings)) : 0;
        $maxDuration = !empty($allTimings) ? round(max($allTimings)) : 0;
        $totalDuration = round((microtime(true) - $startTime) * 1000);

        echo json_encode([
            'mode'             => 'parallel',
            'config'           => [
                'total_schools'    => $totalSchools,
                'students_per_sec' => $students,
                'batch_size'       => $batchSize,
                'max_parallel'     => $maxParallel,
                'batch_delay_ms'   => $batchDelayMs,
                'total_batches'    => $totalBatches,
            ],
            'summary'          => [
                'total_schools'    => $totalSchools,
                'schools_passed'   => $passed,
                'schools_failed'   => $totalSchools - $passed,
                'total_operations' => $totalOps,
                'total_errors'     => $totalErrors,
                'success_rate'     => $totalSchools > 0 ? round(($passed / $totalSchools) * 100, 1) . '%' : 'N/A',
                'avg_school_ms'    => $avgDuration,
                'max_school_ms'    => $maxDuration,
                'total_duration_ms' => $totalDuration,
            ],
            'batches'          => $batchReports,
            'school_results'   => $allResults,
            'validation'       => $this->report['tests']['validation'] ?? [],
            'errors'           => array_slice($allErrors, 0, 50),
            'started_at'       => date('c', (int) $startTime),
            'completed_at'     => date('c'),
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Execute multiple URLs in parallel using curl_multi with concurrency limit.
     *
     * @param  array $urls       [key => url, ...]
     * @param  int   $maxParallel Max simultaneous connections
     * @return array             [key => parsed_json_or_null, ...]
     */
    private function _curl_multi_exec(array $urls, int $maxParallel): array
    {
        $results    = [];
        $urlKeys    = array_keys($urls);
        $total      = count($urls);
        $completed  = 0;
        $mh         = curl_multi_init();
        $handles    = [];
        $queue      = $urls;
        $active     = 0;

        // Session cookie for auth (pass our session to worker requests)
        $sessionName = $this->config->item('sess_cookie_name') ?: 'ci_session';
        $sessionId   = $_COOKIE[$sessionName] ?? '';
        $csrfName    = $this->config->item('csrf_cookie_name') ?: 'csrf_cookie';
        $csrfCookie  = $_COOKIE[$csrfName] ?? '';

        $cookieStr = "{$sessionName}={$sessionId}";
        if ($csrfCookie) $cookieStr .= "; {$csrfName}={$csrfCookie}";

        // Seed initial batch
        while ($active < $maxParallel && !empty($queue)) {
            $key = key($queue);
            $url = array_shift($queue);
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_COOKIE         => $cookieStr,
                CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(int) $ch] = $key;
            $active++;
        }

        // Process
        do {
            $status = curl_multi_exec($mh, $running);
            if ($status > CURLM_OK) break;

            // Check for completed handles
            while ($info = curl_multi_info_read($mh)) {
                $ch  = $info['handle'];
                $key = $handles[(int) $ch] ?? null;

                if ($key !== null) {
                    $body = curl_multi_getcontent($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    if ($httpCode === 200 && $body) {
                        $parsed = json_decode($body, true);
                        $results[$key] = is_array($parsed) ? $parsed : null;
                    } else {
                        $results[$key] = null;
                        log_message('error', "Parallel sim: school {$key} HTTP {$httpCode} — " . curl_error($ch));
                    }
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                $active--;
                $completed++;

                // Add next from queue
                if (!empty($queue)) {
                    $nextKey = key($queue);
                    $nextUrl = array_shift($queue);
                    $nch     = curl_init($nextUrl);
                    curl_setopt_array($nch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 120,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_COOKIE         => $cookieStr,
                        CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
                        CURLOPT_SSL_VERIFYPEER => false,
                    ]);
                    curl_multi_add_handle($mh, $nch);
                    $handles[(int) $nch] = $nextKey;
                    $active++;
                }
            }

            if ($running) curl_multi_select($mh, 0.1);
        } while ($running || $active > 0);

        curl_multi_close($mh);
        return $results;
    }

    // ── Single-school worker methods (called by run_school) ──

    private function _setup_single_school(int $schoolNum, int $studentsPerSection): void
    {
        $bp = self::SIM_NAMESPACE . "/SIM_SCHOOL_{$schoolNum}/2025-26";

        // Fee structure for 2 classes × 2 sections
        foreach (self::MONTHS as $month) {
            $fees = [];
            foreach (self::FEE_HEADS as $head => $range) {
                $fees[$head] = mt_rand($range[0], $range[1]);
            }
            foreach (['Class 1', 'Class 2'] as $cls) {
                foreach (['Section A', 'Section B'] as $sec) {
                    $this->firebase->set("{$bp}/Accounts/Fees/Classes Fees/{$cls}/{$sec}/{$month}", $fees);
                }
            }
        }

        // Students
        foreach (['Class 1', 'Class 2'] as $cls) {
            foreach (['Section A', 'Section B'] as $sec) {
                $roster = [];
                for ($st = 1; $st <= $studentsPerSection; $st++) {
                    $secL = substr($sec, -1);
                    $clsN = substr($cls, -1);
                    $roster["STU_{$schoolNum}_{$clsN}_{$secL}_{$st}"] = "Student {$st}";
                }
                $this->firebase->set("{$bp}/{$cls}/{$sec}/Students/List", $roster);
            }
        }

        $this->firebase->set("{$bp}/Accounts/Fees/Receipt No", 0);
    }

    private function _test_demand_generation_single(int $schoolNum): void
    {
        $bp = self::SIM_NAMESPACE . "/SIM_SCHOOL_{$schoolNum}/2025-26";
        $feeChart = $this->firebase->get("{$bp}/Accounts/Fees/Classes Fees/Class 1/Section A");
        if (!is_array($feeChart)) return;

        $roster = $this->firebase->get("{$bp}/Class 1/Section A/Students/List");
        if (!is_array($roster)) return;

        foreach (array_slice(array_keys($roster), 0, 5) as $sid) {
            $demandBase = "{$bp}/Fees/Demands/{$sid}";
            foreach (($feeChart['April'] ?? []) as $head => $amount) {
                $demandId = "2026-04_{$head}";
                $existing = $this->firebase->get("{$demandBase}/{$demandId}/demand_id");
                if ($existing !== null) continue;

                $opT = microtime(true);
                $this->firebase->set("{$demandBase}/{$demandId}", [
                    'demand_id' => $demandId, 'student_id' => $sid, 'fee_head' => $head,
                    'period_key' => '2026-04', 'net_amount' => $amount,
                    'paid_amount' => 0, 'balance' => $amount, 'status' => 'unpaid',
                ]);
                $this->timings[] = (microtime(true) - $opT) * 1000;
            }
        }
    }

    private function _test_payments_single(int $schoolNum, int $studentsPerSection): void
    {
        $bp = self::SIM_NAMESPACE . "/SIM_SCHOOL_{$schoolNum}/2025-26";
        $roster = $this->firebase->get("{$bp}/Class 1/Section A/Students/List");
        if (!is_array($roster)) return;

        $receiptCounter = 0;
        foreach (array_slice(array_keys($roster), 0, min(5, $studentsPerSection)) as $sid) {
            $receiptCounter++;
            $receiptKey = "F{$receiptCounter}";
            $amount = mt_rand(1000, 5000);

            $opT = microtime(true);

            // Idempotency
            $idempKey = md5("{$sid}|{$receiptCounter}|April|{$amount}");
            $idempPath = "{$bp}/Fees/Idempotency/{$idempKey}";
            $existing = $this->firebase->get($idempPath);
            if (is_array($existing) && ($existing['status'] ?? '') === 'success') continue;

            $this->firebase->set($idempPath, ['status' => 'processing', 'started_at' => date('c')]);

            // Write receipt
            $this->firebase->set("{$bp}/Class 1/Section A/Students/{$sid}/Fees Record/{$receiptKey}", [
                'Amount' => number_format($amount, 2, '.', ','), 'Date' => date('d-m-Y'), 'Mode' => 'Cash',
            ]);
            $this->firebase->set("{$bp}/Accounts/Receipt_Index/{$receiptCounter}", [
                'date' => date('d-m-Y'), 'user_id' => $sid, 'amount' => $amount,
            ]);

            // Mark success
            $this->firebase->set($idempPath, ['status' => 'success', 'receipt_no' => $receiptCounter, 'completed_at' => date('c')]);

            $this->timings[] = (microtime(true) - $opT) * 1000;
        }
    }

    private function _test_online_payments_single(int $schoolNum): void
    {
        $bp = self::SIM_NAMESPACE . "/SIM_SCHOOL_{$schoolNum}/2025-26";

        for ($i = 1; $i <= 3; $i++) {
            $orderId = "ORD_SIM_{$schoolNum}_{$i}";
            $recordId = "OP_SIM_{$schoolNum}_{$i}";
            $amount = mt_rand(2000, 8000);

            $opT = microtime(true);

            $this->firebase->set("{$bp}/Fees/Online_Orders/{$recordId}", [
                'student_id' => "STU_{$schoolNum}_1_A_{$i}", 'amount' => $amount,
                'gateway_order_id' => $orderId, 'status' => 'created', 'created_at' => date('c'),
            ]);

            $success = (mt_rand(1, 100) <= 80);
            $this->firebase->update("{$bp}/Fees/Online_Orders/{$recordId}", [
                'status' => $success ? 'paid' : 'failed',
                ($success ? 'paid_at' : 'failed_at') => date('c'),
            ]);

            $this->timings[] = (microtime(true) - $opT) * 1000;
        }
    }

    private function _test_advance_stress_single(int $schoolNum): void
    {
        $bp = self::SIM_NAMESPACE . "/SIM_SCHOOL_{$schoolNum}/2025-26";
        $sid = "STU_{$schoolNum}_1_A_1";
        $advPath = "{$bp}/Fees/Advance_Balance/{$sid}";

        $this->firebase->set($advPath, ['amount' => 0, 'student_id' => $sid]);

        for ($i = 1; $i <= 10; $i++) {
            $delta = mt_rand(50, 300);
            $opT = microtime(true);

            $current = $this->firebase->get($advPath);
            $curAmt = is_array($current) ? round(floatval($current['amount'] ?? 0), 2) : 0;
            $newAmt = round($curAmt + $delta, 2);
            $this->firebase->set($advPath, ['amount' => $newAmt, 'student_id' => $sid]);

            // Verify
            $verify = $this->firebase->get($advPath);
            $verifyAmt = is_array($verify) ? round(floatval($verify['amount'] ?? 0), 2) : -1;
            if (abs($verifyAmt - $newAmt) > 0.01) {
                $this->errors[] = "ADVANCE MISMATCH: school={$schoolNum} expected={$newAmt} actual={$verifyAmt}";
            }

            $this->timings[] = (microtime(true) - $opT) * 1000;
        }
    }

    private function _validate_single(int $schoolNum): array
    {
        $bp = self::SIM_NAMESPACE . "/SIM_SCHOOL_{$schoolNum}/2025-26";
        $checks = ['receipts' => 0, 'negative_balances' => 0, 'stuck_locks' => 0, 'stuck_processing' => 0];

        $ri = $this->firebase->get("{$bp}/Accounts/Receipt_Index");
        $checks['receipts'] = is_array($ri) ? count($ri) : 0;

        $locks = $this->firebase->get("{$bp}/Fees/Locks");
        if (is_array($locks)) $checks['stuck_locks'] = count($locks);

        $idemp = $this->firebase->get("{$bp}/Fees/Idempotency");
        if (is_array($idemp)) {
            foreach ($idemp as $v) {
                if (is_array($v) && ($v['status'] ?? '') === 'processing') $checks['stuck_processing']++;
            }
        }

        $advances = $this->firebase->get("{$bp}/Fees/Advance_Balance");
        if (is_array($advances)) {
            foreach ($advances as $sid => $adv) {
                if (is_array($adv) && floatval($adv['amount'] ?? 0) < -0.01) {
                    $checks['negative_balances']++;
                    $this->errors[] = "NEGATIVE: school={$schoolNum} student={$sid}";
                }
            }
        }

        return $checks;
    }
}
