<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_refund_service — Orchestrator for refund processing.
 *
 * Delegates to:
 *   - RefundValidator  (idempotency, locking, status checks)
 *   - RefundProcessor  (demand reversal, legacy month flags, advance balance)
 *   - RefundAccounting (voucher, account book, journal entries)
 */
class Fee_refund_service
{
    private $firebase;
    private $sessionRoot;
    private $feesBase;
    private $adminName;
    private $adminId;
    private $opsAcct;

    public function init($firebase, string $sessionRoot, string $feesBase, string $adminName, string $adminId, $opsAcct = null): self
    {
        $this->firebase    = $firebase;
        $this->sessionRoot = $sessionRoot;
        $this->feesBase    = $feesBase;
        $this->adminName   = $adminName;
        $this->adminId     = $adminId;
        $this->opsAcct     = $opsAcct;
        return $this;
    }

    /**
     * Process a refund end-to-end.
     *
     * @return array ['ok' => bool, 'data' => [...]] or ['ok' => false, 'error' => '...']
     */
    public function process(string $refId, string $refundMode): array
    {
        $validator  = new _RefundValidator($this->firebase, $this->feesBase);
        $processor  = new _RefundProcessor($this->firebase, $this->sessionRoot, $this->feesBase, $this->adminId);
        $accounting = new _RefundAccounting($this->firebase, $this->sessionRoot, $this->adminName, $this->opsAcct);

        // ── 1. Validate & lock ──
        $validation = $validator->validateAndLock($refId);
        if (!$validation['ok']) {
            return $validation;
        }
        $refund = $validation['refund'];
        $amount = floatval($refund['amount'] ?? 0);

        if ($amount <= 0) {
            $validator->releaseLock($refId, 'approved');
            return ['ok' => false, 'error' => 'Refund amount must be greater than zero.'];
        }

        // ── 2. Create voucher + reverse fees ──
        $receiptKey     = 'REFUND_' . strtoupper(substr($refId, 4));
        $studentId      = $refund['student_id'] ?? '';
        $origReceiptNo  = $refund['receipt_no'] ?? '';
        $refClass       = $refund['class'] ?? '';
        $refSection     = $refund['section'] ?? '';

        $voucherResult = $accounting->createVoucher($refund, $amount, $refundMode, $refId, $receiptKey);
        $demandResult  = null;
        $allocations   = [];

        if ($studentId !== '' && $origReceiptNo !== '') {
            $demandResult = $processor->reverseDemands(
                $studentId, $origReceiptNo, $amount, $refId, $receiptKey, $allocations
            );
        }

        if ($studentId !== '' && $refClass !== '' && $refSection !== '') {
            $processor->reverseLegacyMonthFlags(
                $studentId, $origReceiptNo, $refClass, $refSection, $amount, $demandResult
            );
        }

        // ── 3. Accounting entries ──
        $accounting->reverseAccountBook($studentId, $origReceiptNo, $refClass, $refSection, $amount);
        $accounting->createJournal($refund, $amount, $refundMode, $refId, $allocations);

        // ── 4. Mark processed ──
        $this->firebase->update("{$this->feesBase}/Refunds/{$refId}", [
            'status'         => 'processed',
            'processed_date' => date('Y-m-d H:i:s'),
            'processed_by'   => $this->adminName,
            'refund_mode'    => $refundMode,
            'voucher_key'    => $receiptKey,
            'process_lock'   => null,
        ]);

        log_message('info', "Fee_refund_service: {$refId} processed — amount={$amount}");

        $response = ['message' => 'Refund processed successfully.', 'voucher_key' => $receiptKey, 'refund_id' => $refId];
        if ($demandResult !== null) $response['demand_reversal'] = $demandResult;
        return ['ok' => true, 'data' => $response];
    }
}

// ═════════════════════════════════════════════════════════════════════
//  SUB-CLASS 1: RefundValidator
// ═════════════════════════════════════════════════════════════════════

class _RefundValidator
{
    private $firebase;
    private $feesBase;
    private const LOCK_TTL = 300;

    public function __construct($firebase, string $feesBase)
    {
        $this->firebase = $firebase;
        $this->feesBase = $feesBase;
    }

    public function validateAndLock(string $refId): array
    {
        $refund = $this->firebase->get("{$this->feesBase}/Refunds/{$refId}");
        if (!is_array($refund)) {
            return ['ok' => false, 'error' => 'Refund not found.'];
        }

        $status = $refund['status'] ?? '';

        if ($status === 'processed') {
            log_message('info', "_RefundValidator: {$refId} already processed — idempotent");
            return [
                'ok' => true,
                'data' => ['message' => 'Refund already processed.', 'refund_id' => $refId, 'idempotent' => true],
            ];
        }

        if ($status === 'processing') {
            $lockTime = strtotime($refund['process_lock'] ?? '');
            if ($lockTime && (time() - $lockTime) < self::LOCK_TTL) {
                return ['ok' => false, 'error' => 'Refund is currently being processed. Please wait.'];
            }
            log_message('info', "_RefundValidator: stale lock on {$refId}, retrying");
        } elseif ($status !== 'approved') {
            return ['ok' => false, 'error' => "Only approved refunds can be processed. Current status: '{$status}'."];
        }

        // Acquire lock
        $this->firebase->update("{$this->feesBase}/Refunds/{$refId}", [
            'status' => 'processing', 'process_lock' => date('c'),
        ]);

        return ['ok' => true, 'refund' => $refund];
    }

    public function releaseLock(string $refId, string $restoreStatus): void
    {
        $this->firebase->update("{$this->feesBase}/Refunds/{$refId}", [
            'status' => $restoreStatus, 'process_lock' => null,
        ]);
    }
}

// ═════════════════════════════════════════════════════════════════════
//  SUB-CLASS 2: RefundProcessor
// ═════════════════════════════════════════════════════════════════════

class _RefundProcessor
{
    private $firebase;
    private $sessionRoot;
    private $feesBase;
    private $adminId;

    public function __construct($firebase, string $sessionRoot, string $feesBase, string $adminId)
    {
        $this->firebase    = $firebase;
        $this->sessionRoot = $sessionRoot;
        $this->feesBase    = $feesBase;
        $this->adminId     = $adminId;
    }

    public function reverseDemands(
        string $studentId, string $origReceiptNo, float $amount,
        string $refId, string $receiptKey, array &$allocationsOut
    ): ?array {
        $allocPath = "{$this->sessionRoot}/Fees/Receipt_Allocations/{$origReceiptNo}";
        $allocData = $this->firebase->get($allocPath);

        if (!is_array($allocData) || empty($allocData['allocations'])) return null;
        if (($allocData['status'] ?? '') === 'reversed') {
            return ['reversed' => 0, 'already_reversed' => true];
        }

        try {
            $allocationsOut = $allocData['allocations'];
            $demandsBase = "{$this->sessionRoot}/Fees/Demands/{$studentId}";
            $reversed = 0;
            $log = [];
            $remaining = $amount;

            foreach (array_reverse($allocationsOut) as $alloc) {
                if ($remaining <= 0.005) break;
                $did = $alloc['demand_id'] ?? '';
                $allocAmt = floatval($alloc['amount'] ?? 0);
                if ($did === '' || $allocAmt <= 0) continue;

                $reverseAmt = min($remaining, $allocAmt);
                $demand = $this->firebase->get("{$demandsBase}/{$did}");
                if (!is_array($demand)) continue;

                $oldPaid   = floatval($demand['paid_amount'] ?? 0);
                $net       = floatval($demand['net_amount'] ?? 0);
                $fine      = floatval($demand['fine_amount'] ?? 0);
                $newPaid   = round(max(0, $oldPaid - $reverseAmt), 2);
                $newBal    = round(max(0, $net + $fine - $newPaid), 2);
                $newStatus = ($newPaid <= 0.005) ? 'unpaid' : (($newBal <= 0.005) ? 'paid' : 'partial');

                $this->firebase->update("{$demandsBase}/{$did}", [
                    'paid_amount' => $newPaid, 'balance' => $newBal, 'status' => $newStatus,
                    'last_refund_receipt' => $receiptKey, 'last_refund_date' => date('c'), 'updated_at' => date('c'),
                ]);
                $log[] = ['demand_id' => $did, 'fee_head' => $alloc['fee_head'] ?? '', 'period' => $alloc['period'] ?? '', 'reversed_amt' => round($reverseAmt, 2), 'new_status' => $newStatus];
                $remaining -= $reverseAmt;
                $reversed++;
            }

            // Reduce advance balance if excess
            if ($remaining > 0.005) {
                $advPath = "{$this->sessionRoot}/Fees/Advance_Balance/{$studentId}";
                $adv = $this->firebase->get($advPath);
                $advAmt = is_array($adv) ? floatval($adv['amount'] ?? 0) : 0;
                $this->firebase->set($advPath, ['amount' => round(max(0, $advAmt - $remaining), 2), 'last_updated' => date('c'), 'last_refund' => $receiptKey]);
            }

            $auditId = 'RFND_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            $this->firebase->set("{$this->sessionRoot}/Fees/Refund_Audit/{$auditId}", [
                'receipt_key' => $origReceiptNo, 'refund_id' => $refId, 'student_id' => $studentId,
                'refund_amount' => round($amount, 2), 'reversals' => $log, 'reversed_count' => $reversed,
                'created_at' => date('c'), 'created_by' => $this->adminId,
            ]);
            $this->firebase->update($allocPath, ['status' => 'reversed', 'reversed_at' => date('c'), 'refund_audit' => $auditId]);
            return ['reversed' => $reversed, 'audit_id' => $auditId];
        } catch (\Exception $e) {
            log_message('error', '_RefundProcessor::reverseDemands failed: ' . $e->getMessage());
            return null;
        }
    }

    public function reverseLegacyMonthFlags(
        string $studentId, string $origReceiptNo, string $class, string $section,
        float $amount, ?array $demandResult
    ): void {
        // Feature flag: skip legacy writes if disabled
        $CI =& get_instance();
        if (isset($CI->config) && $CI->config->item('use_legacy_month_fee') === false) {
            return;
        }

        try {
            list($cn, $sn) = $this->_normalizeCS($class, $section);
            $studentBase = "{$this->sessionRoot}/{$cn}/{$sn}/Students/{$studentId}";

            if ($demandResult !== null && ($demandResult['reversed'] ?? 0) > 0) {
                $demandsBase = "{$this->sessionRoot}/Fees/Demands/{$studentId}";
                $allDemands = $this->firebase->get($demandsBase);
                if (is_array($allDemands)) {
                    $ms = [];
                    foreach ($allDemands as $d) {
                        if (!is_array($d)) continue;
                        $mn = explode(' ', $d['period'] ?? '')[0] ?? '';
                        if ($mn === '') continue;
                        $ms[$mn][] = $d['status'] ?? 'unpaid';
                    }
                    foreach ($ms as $mn => $sts) {
                        $paid = !in_array('unpaid', $sts) && !in_array('partial', $sts);
                        $this->firebase->set("{$studentBase}/Month Fee/{$mn}", $paid ? 1 : 0);
                    }
                }
            } else {
                $orig = $this->firebase->get("{$studentBase}/Fees Record/{$origReceiptNo}");
                if (!is_array($orig)) return;
                $mf = $this->firebase->get("{$studentBase}/Month Fee");
                $mf = is_array($mf) ? $mf : [];
                $rem = $amount;
                $fp = "{$this->sessionRoot}/Accounts/Fees/Classes Fees/{$cn}/{$sn}";
                $cf = $this->firebase->get($fp);
                $cf = is_array($cf) ? $cf : [];
                $months = ['April','May','June','July','August','September','October','November','December','January','February','March','Yearly Fees'];
                foreach (array_reverse($months) as $m) {
                    if ($rem <= 0) break;
                    if (!isset($mf[$m]) || (int)$mf[$m] !== 1) continue;
                    $t = 0;
                    if (isset($cf[$m]) && is_array($cf[$m])) {
                        foreach ($cf[$m] as $_ => $a) $t += floatval(str_replace(',', '', $a));
                    }
                    if ($t <= 0) $t = $rem;
                    if ($rem >= $t) { $this->firebase->set("{$studentBase}/Month Fee/{$m}", 0); $rem -= $t; }
                }
                if ($rem > 0.005) {
                    $os = floatval($this->firebase->get("{$studentBase}/Oversubmittedfees") ?? 0);
                    $this->firebase->set("{$studentBase}/Oversubmittedfees", round(max(0, $os - $rem), 2));
                }
            }
        } catch (\Exception $e) {
            log_message('error', '_RefundProcessor::reverseLegacyMonthFlags: ' . $e->getMessage());
        }
    }

    private function _normalizeCS(string $c, string $s): array
    {
        $c = trim($c);
        if (stripos($c, 'Class ') !== 0) $c = 'Class ' . $c;
        $s = trim($s);
        if (stripos($s, 'Section ') !== 0) $s = 'Section ' . strtoupper($s);
        return [$c, $s];
    }
}

// ═════════════════════════════════════════════════════════════════════
//  SUB-CLASS 3: RefundAccounting
// ═════════════════════════════════════════════════════════════════════

class _RefundAccounting
{
    private $firebase;
    private $sessionRoot;
    private $adminName;
    private $opsAcct;

    public function __construct($firebase, string $sessionRoot, string $adminName, $opsAcct = null)
    {
        $this->firebase    = $firebase;
        $this->sessionRoot = $sessionRoot;
        $this->adminName   = $adminName;
        $this->opsAcct     = $opsAcct;
    }

    public function createVoucher(array $refund, float $amount, string $mode, string $refId, string $receiptKey): void
    {
        $today = date('Y-m-d');
        $this->firebase->set("{$this->sessionRoot}/Accounts/Vouchers/{$today}/{$receiptKey}", [
            'type' => 'refund', 'student_id' => $refund['student_id'] ?? '', 'student_name' => $refund['student_name'] ?? '',
            'class' => $refund['class'] ?? '', 'section' => $refund['section'] ?? '', 'fee_title' => $refund['fee_title'] ?? '',
            'amount' => -$amount, 'refund_mode' => $mode, 'receipt_no' => $refund['receipt_no'] ?? '', 'refund_id' => $refId,
            'reason' => $refund['reason'] ?? '', 'processed_by' => $this->adminName, 'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    public function reverseAccountBook(string $studentId, string $receiptNo, string $class, string $section, float $amount): void
    {
        try {
            $origDate = '';
            if ($studentId !== '' && $receiptNo !== '' && $class !== '' && $section !== '') {
                $c = trim($class); if (stripos($c, 'Class ') !== 0) $c = 'Class ' . $c;
                $s = trim($section); if (stripos($s, 'Section ') !== 0) $s = 'Section ' . strtoupper($s);
                $sb = "{$this->sessionRoot}/{$c}/{$s}/Students/{$studentId}";
                $orig = $this->firebase->get("{$sb}/Fees Record/{$receiptNo}");
                if (is_array($orig) && isset($orig['Date'])) $origDate = $orig['Date'];
            }
            $d = ($origDate !== '') ? \DateTime::createFromFormat('d-m-Y', $origDate) : new \DateTime();
            $mon = $d ? $d->format('F') : date('F');
            $day = $d ? $d->format('d') : date('d');
            $ab = "{$this->sessionRoot}/Accounts/Account_book";
            $curF = floatval($this->firebase->get("{$ab}/Fees/{$mon}/{$day}/R") ?? 0);
            $this->firebase->set("{$ab}/Fees/{$mon}/{$day}/R", max(0, $curF - $amount));
            $curR = floatval($this->firebase->get("{$ab}/Refunds/{$mon}/{$day}/R") ?? 0);
            $this->firebase->set("{$ab}/Refunds/{$mon}/{$day}/R", $curR + $amount);
        } catch (\Exception $e) {
            log_message('error', '_RefundAccounting::reverseAccountBook: ' . $e->getMessage());
        }
    }

    public function createJournal(array $refund, float $amount, string $mode, string $refId, array $allocations): void
    {
        if ($this->opsAcct === null) return;
        try {
            $p = [
                'student_name' => $refund['student_name'] ?? '', 'student_id' => $refund['student_id'] ?? '',
                'class' => $refund['class'] ?? '', 'amount' => $amount, 'refund_mode' => $mode,
                'refund_id' => $refId, 'receipt_no' => $refund['receipt_no'] ?? '',
            ];
            if (!empty($allocations)) {
                $this->opsAcct->create_refund_journal_granular($p, $allocations);
            } else {
                $this->opsAcct->create_refund_journal($p);
            }
        } catch (\Exception $e) {
            log_message('error', '_RefundAccounting::createJournal: ' . $e->getMessage());
        }
    }
}
