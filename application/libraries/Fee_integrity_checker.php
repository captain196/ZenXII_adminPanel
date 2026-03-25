<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_integrity_checker — 10 database integrity checks for Firebase RTDB fee data.
 *
 * Each check returns a standardized report with violations found.
 * Designed for large schools (2000+ students) — uses shallow_get() where
 * possible and processes data in batches.
 *
 * Firebase paths (all under Schools/{school}/{session}/):
 *   Students:       {class}/{section}/Students/
 *   Demands:        Fees/Demands/{studentId}/
 *   Pending fees:   Accounts/Pending_fees/{studentId}
 *   Fee structure:  Accounts/Fees/Classes Fees/{class}/{section}/
 *   Receipts:       Accounts/Fees/Receipt_Keys/{key}
 *   Receipt index:  Accounts/Fees/Receipt_Index/
 *   Advance:        Fees/Advance_Balance/{studentId}
 *   Discounts:      Accounts/Fees/Discounts/{studentId}
 *   Sessions:       Config/Sessions
 */
class Fee_integrity_checker
{
    /** @var Firebase */
    private $firebase;

    /** @var string */
    private $schoolName;

    /** @var string */
    private $sessionYear;

    /** @var string Precomputed: Schools/{school}/{session} */
    private $sessionRoot;

    /** @var string Precomputed: Schools/{school} */
    private $schoolRoot;

    /** @var array Cached set of enrolled student IDs (built once, reused across checks) */
    private $enrolledStudents = [];

    /** @var bool Whether the enrolled student cache has been populated */
    private $rosterLoaded = false;

    // ── Initialisation ──────────────────────────────────────────────

    /**
     * Inject dependencies and return self for chaining.
     */
    public function init($firebase, string $schoolName, string $sessionYear): self
    {
        $this->firebase    = $firebase;
        $this->schoolName  = $schoolName;
        $this->sessionYear = $sessionYear;
        $this->sessionRoot = "Schools/{$schoolName}/{$sessionYear}";
        $this->schoolRoot  = "Schools/{$schoolName}";

        // Reset cache for each init
        $this->enrolledStudents = [];
        $this->rosterLoaded     = false;

        return $this;
    }

    // ── Run All ─────────────────────────────────────────────────────

    /**
     * Execute every integrity check and return a combined report.
     *
     * @return array ['passed' => int, 'failed' => int, 'checks' => [...]]
     */
    public function runAll(): array
    {
        $checks = [
            $this->checkOrphanRecords(),
            $this->checkNullClassId(),
            $this->checkDanglingPayments(),
            $this->checkOverpayment(),
            $this->checkOrphanYearRefs(),
            $this->checkDuplicateAssignments(),
            $this->checkReceiptGaps(),
            $this->checkConcessionOverflow(),
            $this->checkNegativeBalances(),
            $this->checkTemporalConsistency(),
        ];

        $passed = 0;
        $failed = 0;
        foreach ($checks as $c) {
            if ($c['status'] === 'pass') {
                $passed++;
            } else {
                $failed++;
            }
        }

        return [
            'passed' => $passed,
            'failed' => $failed,
            'checks' => $checks,
        ];
    }

    // ── DB-001: Orphan Fee Records ──────────────────────────────────

    /**
     * Students that have pending fees but do not exist in any class roster.
     */
    public function checkOrphanRecords(): array
    {
        $checkId = 'DB-001';
        $name    = 'Orphan Fee Records';

        try {
            $pendingPath = "{$this->sessionRoot}/Accounts/Pending_fees";
            $pendingIds  = $this->firebase->shallow_get($pendingPath);

            if (empty($pendingIds)) {
                return $this->_result($checkId, $name, 'pass', 0, []);
            }

            $roster  = $this->_getEnrolledStudents();
            $orphans = [];

            foreach ($pendingIds as $studentId) {
                $studentId = (string) $studentId;
                if (!isset($roster[$studentId])) {
                    $orphans[] = $studentId;
                }
            }

            $status = empty($orphans) ? 'pass' : 'fail';
            return $this->_result($checkId, $name, $status, count($orphans), $orphans);
        } catch (\Exception $e) {
            log_message('error', "Fee_integrity_checker::{$checkId} failed: " . $e->getMessage());
            return $this->_result($checkId, $name, 'fail', -1, ['error' => $e->getMessage()]);
        }
    }

    // ── DB-002: Null Class ID in Demands ────────────────────────────

    /**
     * Fee demands that have a null or empty class/section reference.
     */
    public function checkNullClassId(): array
    {
        $checkId = 'DB-002';
        $name    = 'Null Class ID in Demands';

        try {
            $demandsRoot = "{$this->sessionRoot}/Fees/Demands";
            $studentIds  = $this->firebase->shallow_get($demandsRoot);

            if (empty($studentIds)) {
                return $this->_result($checkId, $name, 'pass', 0, []);
            }

            $violations = [];

            foreach ($studentIds as $studentId) {
                $studentId = (string) $studentId;
                if ($studentId === '_migrated') continue;

                $demands = $this->firebase->get("{$demandsRoot}/{$studentId}");
                if (!is_array($demands)) continue;

                foreach ($demands as $demandId => $demand) {
                    if (!is_array($demand)) continue;
                    if ($demandId === '_migrated') continue;

                    $class   = $demand['class'] ?? null;
                    $section = $demand['section'] ?? null;

                    if ($class === null || $class === '' || $section === null || $section === '') {
                        $violations[] = [
                            'student_id' => $studentId,
                            'demand_id'  => $demandId,
                            'fee_head'   => $demand['fee_head'] ?? 'unknown',
                            'class'      => $class,
                            'section'    => $section,
                        ];
                    }
                }
            }

            $status = empty($violations) ? 'pass' : 'fail';
            return $this->_result($checkId, $name, $status, count($violations), $violations);
        } catch (\Exception $e) {
            log_message('error', "Fee_integrity_checker::{$checkId} failed: " . $e->getMessage());
            return $this->_result($checkId, $name, 'fail', -1, ['error' => $e->getMessage()]);
        }
    }

    // ── DB-003: Dangling Payments ───────────────────────────────────

    /**
     * Payment receipts that reference a non-existent student or a fee month
     * that has no corresponding demand.
     */
    public function checkDanglingPayments(): array
    {
        $checkId = 'DB-003';
        $name    = 'Dangling Payments';

        try {
            $receiptsPath = "{$this->sessionRoot}/Accounts/Fees/Receipt_Keys";
            $receiptKeys  = $this->firebase->shallow_get($receiptsPath);

            if (empty($receiptKeys)) {
                return $this->_result($checkId, $name, 'pass', 0, []);
            }

            $roster     = $this->_getEnrolledStudents();
            $violations = [];

            // Process in chunks to avoid overwhelming memory
            $chunks = array_chunk($receiptKeys, 50);
            foreach ($chunks as $chunk) {
                foreach ($chunk as $key) {
                    $key     = (string) $key;
                    $receipt = $this->firebase->get("{$receiptsPath}/{$key}");
                    if (!is_array($receipt)) continue;

                    $studentId = $receipt['student_id'] ?? '';
                    $feeMonth  = $receipt['fee_month'] ?? ($receipt['month'] ?? '');
                    $dangling  = false;
                    $reasons   = [];

                    // Check 1: student exists in roster
                    if ($studentId === '' || !isset($roster[$studentId])) {
                        $dangling  = true;
                        $reasons[] = 'student_not_found';
                    }

                    // Check 2: demand exists for this student + month
                    if ($studentId !== '' && $feeMonth !== '') {
                        $demandKeys = $this->firebase->shallow_get(
                            "{$this->sessionRoot}/Fees/Demands/{$studentId}"
                        );
                        if (!empty($demandKeys)) {
                            $demands = $this->firebase->get(
                                "{$this->sessionRoot}/Fees/Demands/{$studentId}"
                            );
                            $monthMatch = false;
                            if (is_array($demands)) {
                                foreach ($demands as $did => $d) {
                                    if (!is_array($d)) continue;
                                    $period = $d['period'] ?? '';
                                    if (stripos($period, $feeMonth) !== false) {
                                        $monthMatch = true;
                                        break;
                                    }
                                }
                            }
                            if (!$monthMatch) {
                                $dangling  = true;
                                $reasons[] = 'no_matching_demand';
                            }
                        } else {
                            $dangling  = true;
                            $reasons[] = 'no_demands_found';
                        }
                    }

                    if ($dangling) {
                        $violations[] = [
                            'receipt_key' => $key,
                            'student_id'  => $studentId,
                            'fee_month'   => $feeMonth,
                            'reasons'     => $reasons,
                        ];
                    }
                }
            }

            $status = empty($violations) ? 'pass' : 'fail';
            return $this->_result($checkId, $name, $status, count($violations), $violations);
        } catch (\Exception $e) {
            log_message('error', "Fee_integrity_checker::{$checkId} failed: " . $e->getMessage());
            return $this->_result($checkId, $name, 'fail', -1, ['error' => $e->getMessage()]);
        }
    }

    // ── DB-004: Overpayment Detection ───────────────────────────────

    /**
     * Students where total paid exceeds total demanded with no corresponding
     * advance balance or refund to account for it.
     */
    public function checkOverpayment(): array
    {
        $checkId = 'DB-004';
        $name    = 'Overpayment Detection';

        try {
            $demandsRoot = "{$this->sessionRoot}/Fees/Demands";
            $studentIds  = $this->firebase->shallow_get($demandsRoot);

            if (empty($studentIds)) {
                return $this->_result($checkId, $name, 'pass', 0, []);
            }

            $violations = [];

            foreach ($studentIds as $studentId) {
                $studentId = (string) $studentId;
                if ($studentId === '_migrated') continue;

                $demands = $this->firebase->get("{$demandsRoot}/{$studentId}");
                if (!is_array($demands)) continue;

                $totalDemanded = 0.0;
                $totalPaid     = 0.0;

                foreach ($demands as $did => $demand) {
                    if (!is_array($demand) || $did === '_migrated') continue;

                    $netAmount  = floatval($demand['net_amount'] ?? ($demand['amount'] ?? 0));
                    $fineAmount = floatval($demand['fine_amount'] ?? 0);
                    $paidAmount = floatval($demand['paid_amount'] ?? 0);

                    $totalDemanded += ($netAmount + $fineAmount);
                    $totalPaid     += $paidAmount;
                }

                // Check if overpaid
                $excess = round($totalPaid - $totalDemanded, 2);
                if ($excess > 0.01) {
                    // Check advance balance — if advance accounts for the excess, not a real issue
                    $advancePath = "{$this->sessionRoot}/Fees/Advance_Balance/{$studentId}";
                    $advance     = $this->firebase->get($advancePath);
                    $advAmount   = 0.0;
                    if (is_array($advance)) {
                        $advAmount = floatval($advance['amount'] ?? 0);
                    } elseif (is_numeric($advance)) {
                        $advAmount = floatval($advance);
                    }

                    // Check refunds
                    $refundPath = "{$this->sessionRoot}/Fees/Refunds";
                    $refunds    = $this->firebase->get($refundPath);
                    $refunded   = 0.0;
                    if (is_array($refunds)) {
                        foreach ($refunds as $ref) {
                            if (!is_array($ref)) continue;
                            if (($ref['student_id'] ?? '') === $studentId && ($ref['status'] ?? '') === 'processed') {
                                $refunded += floatval($ref['amount'] ?? 0);
                            }
                        }
                    }

                    $unaccounted = round($excess - $advAmount - $refunded, 2);
                    if ($unaccounted > 0.01) {
                        $violations[] = [
                            'student_id'     => $studentId,
                            'total_demanded' => $totalDemanded,
                            'total_paid'     => $totalPaid,
                            'excess'         => $excess,
                            'advance'        => $advAmount,
                            'refunded'       => $refunded,
                            'unaccounted'    => $unaccounted,
                        ];
                    }
                }
            }

            $status = empty($violations) ? 'pass' : 'fail';
            return $this->_result($checkId, $name, $status, count($violations), $violations);
        } catch (\Exception $e) {
            log_message('error', "Fee_integrity_checker::{$checkId} failed: " . $e->getMessage());
            return $this->_result($checkId, $name, 'fail', -1, ['error' => $e->getMessage()]);
        }
    }

    // ── DB-005: Orphan Year References ──────────────────────────────

    /**
     * Fee records that reference a session year that does not exist
     * (mainly relevant for carry-forward records).
     */
    public function checkOrphanYearRefs(): array
    {
        $checkId = 'DB-005';
        $name    = 'Orphan Year References';

        try {
            // Get all valid sessions for this school
            $validSessions = $this->_getValidSessions();

            $demandsRoot = "{$this->sessionRoot}/Fees/Demands";
            $studentIds  = $this->firebase->shallow_get($demandsRoot);

            if (empty($studentIds)) {
                return $this->_result($checkId, $name, 'pass', 0, []);
            }

            $violations = [];

            foreach ($studentIds as $studentId) {
                $studentId = (string) $studentId;
                if ($studentId === '_migrated') continue;

                $demands = $this->firebase->get("{$demandsRoot}/{$studentId}");
                if (!is_array($demands)) continue;

                foreach ($demands as $did => $demand) {
                    if (!is_array($demand) || $did === '_migrated') continue;

                    // Check for carry-forward source_session field
                    $sourceSession = $demand['source_session'] ?? '';
                    if ($sourceSession !== '' && !in_array($sourceSession, $validSessions, true)) {
                        $violations[] = [
                            'student_id'      => $studentId,
                            'demand_id'        => $did,
                            'referenced_year' => $sourceSession,
                            'fee_head'        => $demand['fee_head'] ?? 'unknown',
                        ];
                    }

                    // Also check the period_key for year validity
                    $periodKey = $demand['period_key'] ?? '';
                    if ($periodKey !== '' && $periodKey !== '0000-00') {
                        $year = (int) explode('-', $periodKey)[0];
                        if ($year > 0) {
                            $sessionFound = false;
                            foreach ($validSessions as $vs) {
                                $parts = explode('-', $vs);
                                $startYear = (int) ($parts[0] ?? 0);
                                $endYear   = (int) ($parts[1] ?? 0);
                                // A full year like 2024; session like 2024-25
                                if ($year === $startYear || $year === (2000 + $endYear) || $year === $endYear) {
                                    $sessionFound = true;
                                    break;
                                }
                            }
                            if (!$sessionFound) {
                                $violations[] = [
                                    'student_id'      => $studentId,
                                    'demand_id'        => $did,
                                    'referenced_year' => (string) $year,
                                    'fee_head'        => $demand['fee_head'] ?? 'unknown',
                                    'source'          => 'period_key',
                                ];
                            }
                        }
                    }
                }
            }

            $status = empty($violations) ? 'pass' : 'warn';
            if (empty($violations)) {
                $status = 'pass';
            }
            return $this->_result($checkId, $name, $status, count($violations), $violations);
        } catch (\Exception $e) {
            log_message('error', "Fee_integrity_checker::{$checkId} failed: " . $e->getMessage());
            return $this->_result($checkId, $name, 'fail', -1, ['error' => $e->getMessage()]);
        }
    }

    // ── DB-006: Duplicate Demand Assignments ────────────────────────

    /**
     * Students with duplicate demands for the same fee_head + period.
     */
    public function checkDuplicateAssignments(): array
    {
        $checkId = 'DB-006';
        $name    = 'Duplicate Demand Assignments';

        try {
            $demandsRoot = "{$this->sessionRoot}/Fees/Demands";
            $studentIds  = $this->firebase->shallow_get($demandsRoot);

            if (empty($studentIds)) {
                return $this->_result($checkId, $name, 'pass', 0, []);
            }

            $violations = [];

            foreach ($studentIds as $studentId) {
                $studentId = (string) $studentId;
                if ($studentId === '_migrated') continue;

                $demands = $this->firebase->get("{$demandsRoot}/{$studentId}");
                if (!is_array($demands)) continue;

                // Build a map: "fee_head|period" => [demand_ids]
                $seen = [];
                foreach ($demands as $did => $demand) {
                    if (!is_array($demand) || $did === '_migrated') continue;

                    $feeHead = $demand['fee_head'] ?? '';
                    $period  = $demand['period'] ?? '';
                    if ($feeHead === '' || $period === '') continue;

                    $compositeKey = strtolower("{$feeHead}|{$period}");
                    $seen[$compositeKey][] = $did;
                }

                foreach ($seen as $key => $demandIds) {
                    if (count($demandIds) > 1) {
                        $parts = explode('|', $key);
                        $violations[] = [
                            'student_id' => $studentId,
                            'fee_head'   => $parts[0] ?? '',
                            'period'     => $parts[1] ?? '',
                            'demand_ids' => $demandIds,
                            'count'      => count($demandIds),
                        ];
                    }
                }
            }

            $status = empty($violations) ? 'pass' : 'fail';
            return $this->_result($checkId, $name, $status, count($violations), $violations);
        } catch (\Exception $e) {
            log_message('error', "Fee_integrity_checker::{$checkId} failed: " . $e->getMessage());
            return $this->_result($checkId, $name, 'fail', -1, ['error' => $e->getMessage()]);
        }
    }

    // ── DB-007: Receipt Number Gaps ─────────────────────────────────

    /**
     * Detect gaps in the receipt number sequence. Informational only.
     */
    public function checkReceiptGaps(): array
    {
        $checkId = 'DB-007';
        $name    = 'Receipt Number Gaps';

        try {
            $indexPath  = "{$this->sessionRoot}/Accounts/Fees/Receipt_Index";
            $indexData  = $this->firebase->get($indexPath);

            if (!is_array($indexData) || empty($indexData)) {
                // Fall back to Receipt_Keys
                $keysPath = "{$this->sessionRoot}/Accounts/Fees/Receipt_Keys";
                $keys     = $this->firebase->shallow_get($keysPath);
                if (empty($keys)) {
                    return $this->_result($checkId, $name, 'pass', 0, []);
                }
                $receiptNumbers = $keys;
            } else {
                $receiptNumbers = array_keys($indexData);
            }

            // Extract numeric portions from receipt numbers
            $numbers = [];
            foreach ($receiptNumbers as $rn) {
                $rn = (string) $rn;
                // Match trailing digits (e.g., "REC-001" → 1, "2024-0042" → 42, "153" → 153)
                if (preg_match('/(\d+)\s*$/', $rn, $m)) {
                    $numbers[] = (int) $m[1];
                }
            }

            if (empty($numbers)) {
                return $this->_result($checkId, $name, 'pass', 0, []);
            }

            sort($numbers);
            $numbers = array_unique($numbers);
            $numbers = array_values($numbers);

            $gaps = [];
            for ($i = 1, $count = count($numbers); $i < $count; $i++) {
                $expected = $numbers[$i - 1] + 1;
                $actual   = $numbers[$i];
                if ($actual > $expected) {
                    // Record each missing number in the gap
                    for ($g = $expected; $g < $actual; $g++) {
                        $gaps[] = $g;
                        // Cap at 100 to avoid exploding on huge gaps (e.g., numbering reset)
                        if (count($gaps) >= 100) break 2;
                    }
                }
            }

            // Gaps are informational, not critical
            $status = empty($gaps) ? 'pass' : 'warn';
            return $this->_result($checkId, $name, $status, count($gaps), [
                'missing_numbers' => $gaps,
                'range_start'     => $numbers[0] ?? 0,
                'range_end'       => end($numbers) ?: 0,
                'total_receipts'  => count($numbers),
            ]);
        } catch (\Exception $e) {
            log_message('error', "Fee_integrity_checker::{$checkId} failed: " . $e->getMessage());
            return $this->_result($checkId, $name, 'fail', -1, ['error' => $e->getMessage()]);
        }
    }

    // ── DB-008: Concession/Discount Overflow ────────────────────────

    /**
     * Discount records where percentage > 100 or discount amount exceeds
     * the fee amount.
     */
    public function checkConcessionOverflow(): array
    {
        $checkId = 'DB-008';
        $name    = 'Concession Overflow';

        try {
            $discountsPath = "{$this->sessionRoot}/Accounts/Fees/Discounts";
            $studentIds    = $this->firebase->shallow_get($discountsPath);

            if (empty($studentIds)) {
                return $this->_result($checkId, $name, 'pass', 0, []);
            }

            $violations = [];

            foreach ($studentIds as $studentId) {
                $studentId = (string) $studentId;
                $discounts = $this->firebase->get("{$discountsPath}/{$studentId}");
                if (!is_array($discounts)) continue;

                foreach ($discounts as $discountId => $discount) {
                    if (!is_array($discount)) continue;

                    $percentage    = floatval($discount['percentage'] ?? ($discount['discount_percentage'] ?? 0));
                    $discountAmt   = floatval($discount['discount_amount'] ?? ($discount['amount'] ?? 0));
                    $feeAmount     = floatval($discount['fee_amount'] ?? ($discount['original_amount'] ?? 0));

                    $issue = null;

                    if ($percentage > 100) {
                        $issue = 'percentage_over_100';
                    } elseif ($feeAmount > 0 && $discountAmt > $feeAmount) {
                        $issue = 'discount_exceeds_fee';
                    } elseif ($percentage < 0 || $discountAmt < 0) {
                        $issue = 'negative_discount';
                    }

                    if ($issue !== null) {
                        $violations[] = [
                            'student_id'        => $studentId,
                            'discount_id'       => $discountId,
                            'issue'             => $issue,
                            'percentage'        => $percentage,
                            'discount_amount'   => $discountAmt,
                            'fee_amount'        => $feeAmount,
                            'fee_head'          => $discount['fee_head'] ?? 'unknown',
                        ];
                    }
                }
            }

            $status = empty($violations) ? 'pass' : 'fail';
            return $this->_result($checkId, $name, $status, count($violations), $violations);
        } catch (\Exception $e) {
            log_message('error', "Fee_integrity_checker::{$checkId} failed: " . $e->getMessage());
            return $this->_result($checkId, $name, 'fail', -1, ['error' => $e->getMessage()]);
        }
    }

    // ── DB-009: Negative Advance Balances ───────────────────────────

    /**
     * Advance balance should never be negative.
     */
    public function checkNegativeBalances(): array
    {
        $checkId = 'DB-009';
        $name    = 'Negative Advance Balances';

        try {
            $advancePath = "{$this->sessionRoot}/Fees/Advance_Balance";
            $studentIds  = $this->firebase->shallow_get($advancePath);

            if (empty($studentIds)) {
                return $this->_result($checkId, $name, 'pass', 0, []);
            }

            $violations = [];

            foreach ($studentIds as $studentId) {
                $studentId = (string) $studentId;
                $record    = $this->firebase->get("{$advancePath}/{$studentId}");

                $amount = 0.0;
                if (is_array($record)) {
                    $amount = floatval($record['amount'] ?? 0);
                } elseif (is_numeric($record)) {
                    $amount = floatval($record);
                }

                if ($amount < 0) {
                    $violations[] = [
                        'student_id' => $studentId,
                        'amount'     => $amount,
                    ];
                }
            }

            $status = empty($violations) ? 'pass' : 'fail';
            return $this->_result($checkId, $name, $status, count($violations), $violations);
        } catch (\Exception $e) {
            log_message('error', "Fee_integrity_checker::{$checkId} failed: " . $e->getMessage());
            return $this->_result($checkId, $name, 'fail', -1, ['error' => $e->getMessage()]);
        }
    }

    // ── DB-010: Temporal Consistency ────────────────────────────────

    /**
     * Fee demands created before the student's admission date.
     */
    public function checkTemporalConsistency(): array
    {
        $checkId = 'DB-010';
        $name    = 'Temporal Consistency';

        try {
            $demandsRoot = "{$this->sessionRoot}/Fees/Demands";
            $studentIds  = $this->firebase->shallow_get($demandsRoot);

            if (empty($studentIds)) {
                return $this->_result($checkId, $name, 'pass', 0, []);
            }

            $roster     = $this->_getEnrolledStudents();
            $violations = [];

            foreach ($studentIds as $studentId) {
                $studentId = (string) $studentId;
                if ($studentId === '_migrated') continue;

                // Look up student's class/section from roster to find their profile
                if (!isset($roster[$studentId])) continue;

                $info        = $roster[$studentId];
                $classKey    = $info['class'] ?? '';
                $sectionKey  = $info['section'] ?? '';

                if ($classKey === '' || $sectionKey === '') continue;

                // Get admission date from student profile
                $profilePath   = "{$this->sessionRoot}/{$classKey}/{$sectionKey}/Students/{$studentId}";
                $profile       = $this->firebase->get($profilePath);
                $admissionDate = null;

                if (is_array($profile)) {
                    $dateStr = $profile['admission_date']
                        ?? ($profile['Admission_Date']
                        ?? ($profile['admissionDate']
                        ?? ($profile['Date of Admission'] ?? '')));

                    if ($dateStr !== '') {
                        $admissionDate = $this->_parseDate($dateStr);
                    }
                }

                if ($admissionDate === null) continue;

                // Now check demands
                $demands = $this->firebase->get("{$demandsRoot}/{$studentId}");
                if (!is_array($demands)) continue;

                foreach ($demands as $did => $demand) {
                    if (!is_array($demand) || $did === '_migrated') continue;

                    $createdAt = $demand['created_at'] ?? '';
                    if ($createdAt === '') continue;

                    $demandDate = $this->_parseDate($createdAt);
                    if ($demandDate === null) continue;

                    // Demand created before admission — violation
                    if ($demandDate < $admissionDate) {
                        $violations[] = [
                            'student_id'     => $studentId,
                            'demand_id'      => $did,
                            'fee_head'       => $demand['fee_head'] ?? 'unknown',
                            'demand_created' => $createdAt,
                            'admission_date' => $admissionDate->format('Y-m-d'),
                        ];
                    }
                }
            }

            $status = empty($violations) ? 'pass' : 'warn';
            return $this->_result($checkId, $name, $status, count($violations), $violations);
        } catch (\Exception $e) {
            log_message('error', "Fee_integrity_checker::{$checkId} failed: " . $e->getMessage());
            return $this->_result($checkId, $name, 'fail', -1, ['error' => $e->getMessage()]);
        }
    }

    // ── Auto-fix ────────────────────────────────────────────────────

    /**
     * Auto-fix safe issues for supported check IDs.
     *
     * Supported:
     *   DB-009 — Negative advance balances: reset to 0
     *   DB-008 — Concession overflow: cap percentage at 100%, cap amount at fee amount
     *
     * @param  string $checkId  e.g. 'DB-009' or 'DB-008'
     * @return array  ['fixed' => int, 'errors' => int, 'details' => [...]]
     */
    public function autoFix(string $checkId): array
    {
        $result = ['fixed' => 0, 'errors' => 0, 'details' => []];

        try {
            switch ($checkId) {
                case 'DB-009':
                    $result = $this->_fixNegativeBalances();
                    break;

                case 'DB-008':
                    $result = $this->_fixConcessionOverflow();
                    break;

                default:
                    $result['details'][] = "Auto-fix not supported for {$checkId}";
                    log_message('info', "Fee_integrity_checker::autoFix — unsupported check: {$checkId}");
                    break;
            }
        } catch (\Exception $e) {
            log_message('error', "Fee_integrity_checker::autoFix({$checkId}) failed: " . $e->getMessage());
            $result['errors']++;
            $result['details'][] = 'Exception: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Fix DB-009: Reset negative advance balances to 0.
     */
    private function _fixNegativeBalances(): array
    {
        $fixed   = 0;
        $errors  = 0;
        $details = [];

        $advancePath = "{$this->sessionRoot}/Fees/Advance_Balance";
        $studentIds  = $this->firebase->shallow_get($advancePath);

        foreach ($studentIds as $studentId) {
            $studentId = (string) $studentId;
            $record    = $this->firebase->get("{$advancePath}/{$studentId}");

            $amount = 0.0;
            if (is_array($record)) {
                $amount = floatval($record['amount'] ?? 0);
            } elseif (is_numeric($record)) {
                $amount = floatval($record);
            }

            if ($amount < 0) {
                try {
                    if (is_array($record)) {
                        $ok = $this->firebase->update("{$advancePath}/{$studentId}", [
                            'amount'          => 0,
                            'last_updated'    => date('c'),
                            'auto_fixed'      => true,
                            'auto_fix_reason' => "Negative balance ({$amount}) reset to 0",
                            'auto_fix_date'   => date('c'),
                            'previous_amount' => $amount,
                        ]);
                    } else {
                        $ok = $this->firebase->set("{$advancePath}/{$studentId}", [
                            'amount'          => 0,
                            'last_updated'    => date('c'),
                            'auto_fixed'      => true,
                            'auto_fix_reason' => "Negative balance ({$amount}) reset to 0",
                            'auto_fix_date'   => date('c'),
                            'previous_amount' => $amount,
                        ]);
                    }

                    if ($ok) {
                        $fixed++;
                        $details[] = "{$studentId}: balance {$amount} → 0";
                    } else {
                        $errors++;
                        $details[] = "{$studentId}: write failed";
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $details[] = "{$studentId}: " . $e->getMessage();
                    log_message('error', "Fee_integrity_checker::_fixNegativeBalances {$studentId}: " . $e->getMessage());
                }
            }
        }

        log_message('info', "Fee_integrity_checker::_fixNegativeBalances — fixed={$fixed}, errors={$errors}");
        return ['fixed' => $fixed, 'errors' => $errors, 'details' => $details];
    }

    /**
     * Fix DB-008: Cap discount percentage at 100%, cap discount amount at fee amount.
     */
    private function _fixConcessionOverflow(): array
    {
        $fixed   = 0;
        $errors  = 0;
        $details = [];

        $discountsPath = "{$this->sessionRoot}/Accounts/Fees/Discounts";
        $studentIds    = $this->firebase->shallow_get($discountsPath);

        foreach ($studentIds as $studentId) {
            $studentId = (string) $studentId;
            $discounts = $this->firebase->get("{$discountsPath}/{$studentId}");
            if (!is_array($discounts)) continue;

            foreach ($discounts as $discountId => $discount) {
                if (!is_array($discount)) continue;

                $percentageKey = isset($discount['percentage']) ? 'percentage' : 'discount_percentage';
                $amountKey     = isset($discount['discount_amount']) ? 'discount_amount' : 'amount';
                $feeAmountKey  = isset($discount['fee_amount']) ? 'fee_amount' : 'original_amount';

                $percentage  = floatval($discount[$percentageKey] ?? 0);
                $discountAmt = floatval($discount[$amountKey] ?? 0);
                $feeAmount   = floatval($discount[$feeAmountKey] ?? 0);

                $updates = [];

                // Cap percentage at 100
                if ($percentage > 100) {
                    $updates[$percentageKey]      = 100;
                    $updates['auto_fixed']         = true;
                    $updates['auto_fix_date']      = date('c');
                    $updates['previous_percentage'] = $percentage;
                }

                // Cap discount amount at fee amount
                if ($feeAmount > 0 && $discountAmt > $feeAmount) {
                    $updates[$amountKey]             = $feeAmount;
                    $updates['auto_fixed']           = true;
                    $updates['auto_fix_date']        = date('c');
                    $updates['previous_discount_amount'] = $discountAmt;
                }

                // Fix negative discounts by setting to 0
                if ($percentage < 0) {
                    $updates[$percentageKey]        = 0;
                    $updates['auto_fixed']          = true;
                    $updates['auto_fix_date']       = date('c');
                    $updates['previous_percentage'] = $percentage;
                }
                if ($discountAmt < 0) {
                    $updates[$amountKey]                  = 0;
                    $updates['auto_fixed']                = true;
                    $updates['auto_fix_date']             = date('c');
                    $updates['previous_discount_amount']  = $discountAmt;
                }

                if (!empty($updates)) {
                    try {
                        $ok = $this->firebase->update(
                            "{$discountsPath}/{$studentId}/{$discountId}",
                            $updates
                        );
                        if ($ok) {
                            $fixed++;
                            $details[] = "{$studentId}/{$discountId}: " . implode(', ', array_keys($updates));
                        } else {
                            $errors++;
                            $details[] = "{$studentId}/{$discountId}: write failed";
                        }
                    } catch (\Exception $e) {
                        $errors++;
                        $details[] = "{$studentId}/{$discountId}: " . $e->getMessage();
                        log_message('error', "Fee_integrity_checker::_fixConcessionOverflow {$studentId}/{$discountId}: " . $e->getMessage());
                    }
                }
            }
        }

        log_message('info', "Fee_integrity_checker::_fixConcessionOverflow — fixed={$fixed}, errors={$errors}");
        return ['fixed' => $fixed, 'errors' => $errors, 'details' => $details];
    }

    // ── Private helpers ─────────────────────────────────────────────

    /**
     * Build a map of all enrolled student IDs across every class/section.
     * Cached after first call so multiple checks reuse the same data.
     *
     * @return array studentId => ['class' => ..., 'section' => ...]
     */
    private function _getEnrolledStudents(): array
    {
        if ($this->rosterLoaded) {
            return $this->enrolledStudents;
        }

        $roster = [];

        try {
            // Get top-level keys under the session to find class nodes
            $sessionKeys = $this->firebase->shallow_get($this->sessionRoot);

            foreach ($sessionKeys as $classKey) {
                $classKey = (string) $classKey;
                if (strpos($classKey, 'Class ') !== 0) continue;

                $sectionKeys = $this->firebase->shallow_get("{$this->sessionRoot}/{$classKey}");

                foreach ($sectionKeys as $secKey) {
                    $secKey = (string) $secKey;
                    if (strpos($secKey, 'Section ') !== 0) continue;

                    // shallow_get returns just the student ID keys — no full data download
                    $studentIds = $this->firebase->shallow_get(
                        "{$this->sessionRoot}/{$classKey}/{$secKey}/Students"
                    );

                    foreach ($studentIds as $sid) {
                        $roster[(string) $sid] = [
                            'class'   => $classKey,
                            'section' => $secKey,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Fee_integrity_checker::_getEnrolledStudents failed: ' . $e->getMessage());
        }

        $this->enrolledStudents = $roster;
        $this->rosterLoaded     = true;

        return $roster;
    }

    /**
     * Get all valid session years for this school.
     *
     * @return string[] e.g. ['2023-24', '2024-25']
     */
    private function _getValidSessions(): array
    {
        try {
            // Primary: Config/Sessions node
            $sessions = $this->firebase->get("{$this->schoolRoot}/Config/Sessions");
            if (is_array($sessions)) {
                // Could be ['2023-24' => true, '2024-25' => true] or indexed
                $result = [];
                foreach ($sessions as $k => $v) {
                    // If key is the session name
                    if (is_string($k) && preg_match('/\d{4}-\d{2,4}/', $k)) {
                        $result[] = $k;
                    }
                    // If value is the session name
                    if (is_string($v) && preg_match('/\d{4}-\d{2,4}/', $v)) {
                        $result[] = $v;
                    }
                }
                if (!empty($result)) {
                    return $result;
                }
            }

            // Fallback: shallow_get the school root to find year-like keys
            $schoolKeys = $this->firebase->shallow_get($this->schoolRoot);
            $result = [];
            foreach ($schoolKeys as $key) {
                $key = (string) $key;
                if (preg_match('/^\d{4}-\d{2,4}$/', $key)) {
                    $result[] = $key;
                }
            }
            return $result;
        } catch (\Exception $e) {
            log_message('error', 'Fee_integrity_checker::_getValidSessions failed: ' . $e->getMessage());
            // At minimum, the current session is valid
            return [$this->sessionYear];
        }
    }

    /**
     * Parse various date formats into a DateTime object.
     *
     * Handles: ISO 8601 (2024-01-15T10:30:00+05:30), Y-m-d, d-m-Y, d/m/Y, m/d/Y
     *
     * @return \DateTime|null
     */
    private function _parseDate(string $dateStr): ?\DateTime
    {
        $dateStr = trim($dateStr);
        if ($dateStr === '') return null;

        // ISO 8601 (from date('c'))
        try {
            $dt = new \DateTime($dateStr);
            return $dt;
        } catch (\Exception $e) {
            // Not a standard format — try manual parsing
        }

        $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'Y/m/d', 'd-m-Y H:i:s', 'Y-m-d H:i:s'];
        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $dateStr);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }

    /**
     * Build a standardized check result array.
     */
    private function _result(string $checkId, string $name, string $status, int $violations, $details): array
    {
        return [
            'check_id'   => $checkId,
            'name'       => $name,
            'status'     => $status,
            'violations' => $violations,
            'details'    => $details,
            'checked_at' => date('c'),
        ];
    }
}
