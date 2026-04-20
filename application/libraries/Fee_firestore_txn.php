<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_firestore_txn — Pure-Firestore transactional helpers for submit_fees.
 *
 * SESSION A of the Firestore-only migration. This library is the RTDB
 * replacement for every helper submit_fees used to call via
 * $this->firebase->*. It is self-contained and intentionally NEVER touches
 * RTDB — if any operation here returns failure, the caller must abort and
 * surface the error to the user. No silent RTDB fallback.
 *
 * Collections used:
 *   feeCounters              — {schoolId}_receipt_seq, _Journal, _Fee
 *   feeReceiptIndex          — {schoolId}_{session}_{receiptNo}   (dedup + reservation)
 *   feeReceipts              — {schoolId}_{receiptKey}            (canonical receipt doc)
 *   feeReceiptAllocations    — {schoolId}_{session}_{receiptKey}  (per-demand allocation record)
 *   feeDemands               — {schoolId}_{session}_{studentId}_{demandId}
 *   feeDefaulters            — {schoolId}_{session}_{studentId}
 *   feeLocks                 — {schoolId}_{userId}                (payment lock w/ TTL)
 *   feeIdempotency           — {schoolId}_{hash}                  (dedup by request hash)
 *   feePendingWrites         — {schoolId}_{receiptKey}            (safety: receipt in-flight)
 *   studentAdvanceBalances   — {schoolId}_{studentId}
 *   studentDiscounts         — {schoolId}_{studentId}
 *   students                 — {schoolId}_{studentId}             (monthFee map lives here)
 *
 * Concurrency model: Firestore REST client has no transactions, so we use
 * verify-after-write with short backoff retries — same pattern the RTDB
 * code used. Good enough for single-cashier schools; production multi-counter
 * deployments should upgrade to a transactional Firestore SDK later.
 */
class Fee_firestore_txn
{
    /** @var object */ private $firebase;
    /** @var object */ private $fs;
    /** @var string */ private $schoolId;
    /** @var string */ private $session;
    /** @var bool   */ private $ready = false;

    private const COL_COUNTERS    = 'feeCounters';
    private const COL_RCPT_INDEX  = 'feeReceiptIndex';
    private const COL_RCPT        = 'feeReceipts';
    private const COL_RCPT_ALLOC  = 'feeReceiptAllocations';
    private const COL_DEMANDS     = 'feeDemands';
    private const COL_DEFAULTERS  = 'feeDefaulters';
    private const COL_LOCKS       = 'feeLocks';
    private const COL_IDEMP       = 'feeIdempotency';
    private const COL_PENDING     = 'feePendingWrites';
    private const COL_ADVANCE     = 'studentAdvanceBalances';
    private const COL_DISCOUNTS   = 'studentDiscounts';
    private const COL_STUDENTS    = 'students';
    // Session B — refund flow collections
    private const COL_REFUNDS     = 'feeRefunds';
    private const COL_RFND_AUDIT  = 'feeRefundAudit';
    private const COL_RFND_VCHR   = 'feeRefundVouchers';
    // Session D — demand generation collections
    private const COL_FEE_STRUCT  = 'feeStructures';
    private const COL_SCHOLARSHIPS = 'scholarshipAwards';
    private const COL_DEMAND_LOCKS = 'feeDemandLocks';
    private const COL_FEE_SETTINGS = 'feeSettings';

    public function init($firebase, $fs, string $schoolId, string $session): self
    {
        $this->firebase = $firebase;
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ready    = ($firebase !== null && $fs !== null && $schoolId !== '' && $session !== '');
        return $this;
    }

    /**
     * Canonical period→month label conversion.
     *
     * Demand `period` strings carry a trailing year token: "April 2026",
     * "Yearly Fees 2026-27", "May 2026-2027". Stripping the year (and only
     * the year) keeps multi-word labels intact — critically "Yearly Fees".
     *
     * The previous `explode(' ', $period)[0]` chopped this to "Yearly",
     * which then mismatched the user's selection ("Yearly Fees") and
     * silently dropped Yearly demands from allocation + receipt feeMonths
     * + the camelCase `month` field readers in the Android apps consume.
     *
     * Use this helper everywhere a period needs to become a month label.
     */
    public static function periodToMonth(string $period): string
    {
        return trim((string) preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', $period));
    }

    // ─── Counters ──────────────────────────────────────────────────────

    /**
     * Allocate the next counter value using a per-value claim doc.
     *
     * The Firebase wrapper does not expose Firestore transactions, so we
     * implement an atomic increment as:
     *   1. Read the current counter value V (best-effort).
     *   2. Try to CREATE `feeCounters/{schoolId}_{kind}_claim_{V+1}` —
     *      Firestore returns 409 if it already exists, which means a
     *      concurrent writer already took V+1; we retry with V+2.
     *   3. On successful create, advance the main counter doc to V+1
     *      and return V+1.
     *
     * The claim doc itself is the source of truth for which numbers have
     * been handed out — the main counter is just a fast-skip pointer.
     * Up to 6 retries cover bursts of up to 5 concurrent writers; beyond
     * that returns 0 so the caller can surface a real error.
     */
    public function nextCounter(string $kind): int
    {
        if (!$this->ready) return 0;
        $counterDoc = "{$this->schoolId}_{$kind}";
        $candidate = 0;

        try {
            $cur = $this->firebase->firestoreGet(self::COL_COUNTERS, $counterDoc);
            $candidate = is_array($cur) ? (int) ($cur['value'] ?? 0) : 0;
        } catch (\Exception $_) { /* start at 0 */ }

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $candidate++;
            $claimId = "{$this->schoolId}_{$kind}_claim_{$candidate}";
            try {
                $created = $this->firebase->firestoreCreate(self::COL_COUNTERS, $claimId, [
                    'schoolId'  => $this->schoolId,
                    'session'   => $this->session,
                    'kind'      => $kind,
                    'value'     => $candidate,
                    'claimedAt' => date('c'),
                ]);
            } catch (\Exception $e) {
                log_message('error', "Fee_firestore_txn::nextCounter({$kind}) claim attempt {$attempt}: " . $e->getMessage());
                $created = false;
            }

            if ($created) {
                // Advance the fast-skip pointer (best-effort; not critical
                // for correctness — claim docs are what actually serialize).
                try {
                    $this->firebase->firestoreSet(self::COL_COUNTERS, $counterDoc, [
                        'schoolId'  => $this->schoolId,
                        'session'   => $this->session,
                        'kind'      => $kind,
                        'value'     => $candidate,
                        'updatedAt' => date('c'),
                    ]);
                } catch (\Exception $_) { /* non-fatal */ }
                return $candidate;
            }
            // 409 / write failure → another writer took this value, try the next.
        }

        log_message('error', "Fee_firestore_txn::nextCounter({$kind}) exhausted after 6 attempts");
        return 0;
    }

    public function getCounter(string $kind): int
    {
        if (!$this->ready) return 0;
        try {
            $cur = $this->firebase->firestoreGet(self::COL_COUNTERS, "{$this->schoolId}_{$kind}");
            return is_array($cur) ? (int) ($cur['value'] ?? 0) : 0;
        } catch (\Exception $_) { return 0; }
    }

    /**
     * Ensure the counter is at least `floor`. Used by submit_fees when a
     * user-supplied receipt number is consumed directly (bypassing
     * nextCounter) — otherwise the counter falls behind the highest used
     * receipt, and subsequent page loads keep suggesting already-used numbers.
     */
    public function advanceCounterTo(string $kind, int $floor): bool
    {
        if (!$this->ready || $kind === '' || $floor < 1) return false;
        $docId = "{$this->schoolId}_{$kind}";
        try {
            $cur = $this->firebase->firestoreGet(self::COL_COUNTERS, $docId);
            $curVal = is_array($cur) ? (int) ($cur['value'] ?? 0) : 0;
            if ($curVal >= $floor) return true; // already ahead
            return (bool) $this->firebase->firestoreSet(self::COL_COUNTERS, $docId, [
                'schoolId'  => $this->schoolId,
                'session'   => $this->session,
                'kind'      => $kind,
                'value'     => $floor,
                'updatedAt' => date('c'),
            ]);
        } catch (\Exception $_) { return false; }
    }

    // ─── Receipt index / exists check ──────────────────────────────────

    public function receiptExists(string $receiptNo): bool
    {
        if (!$this->ready || $receiptNo === '') return false;
        try {
            $doc = $this->firebase->firestoreGet(self::COL_RCPT_INDEX,
                "{$this->schoolId}_{$this->session}_{$receiptNo}");
            // An entry that only has `reserved:true` doesn't count as "used"
            // because it's either in-flight or stale. Used means finalised.
            if (!is_array($doc)) return false;
            return !empty($doc['date']) && empty($doc['reserved']);
        } catch (\Exception $_) { return false; }
    }

    /**
     * Reserve a receipt number (creates or overwrites the index doc with
     * {reserved:true, reservedAt, userId}). Returns false if reservation
     * failed or a conflicting live reservation exists.
     */
    public function reserveReceipt(string $receiptNo, string $userId): bool
    {
        if (!$this->ready || $receiptNo === '') return false;
        $docId = "{$this->schoolId}_{$this->session}_{$receiptNo}";
        try {
            $existing = $this->firebase->firestoreGet(self::COL_RCPT_INDEX, $docId);
            if (is_array($existing)) {
                // Finalised receipt — conflict.
                if (!empty($existing['date']) && empty($existing['reserved'])) return false;
                // Fresh reservation (< 120s) — conflict.
                if (!empty($existing['reserved'])) {
                    $age = time() - strtotime((string) ($existing['reservedAt'] ?? '2000-01-01'));
                    if ($age < 120) return false;
                }
            }
            return (bool) $this->firebase->firestoreSet(self::COL_RCPT_INDEX, $docId, [
                'schoolId'   => $this->schoolId,
                'session'    => $this->session,
                'receiptNo'  => $receiptNo,
                'reserved'   => true,
                'reservedAt' => date('c'),
                'userId'     => $userId,
                'updatedAt'  => date('c'),
            ]);
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_txn::reserveReceipt({$receiptNo}): " . $e->getMessage());
            return false;
        }
    }

    public function finalizeReceiptIndex(string $receiptNo, array $data): bool
    {
        if (!$this->ready || $receiptNo === '') return false;
        try {
            $doc = array_merge($data, [
                'schoolId'   => $this->schoolId,
                'session'    => $this->session,
                'receiptNo'  => $receiptNo,
                'reserved'   => false,
                'reservedAt' => '',
                'updatedAt'  => date('c'),
            ]);
            return (bool) $this->firebase->firestoreSet(self::COL_RCPT_INDEX,
                "{$this->schoolId}_{$this->session}_{$receiptNo}", $doc, /* merge */ true);
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_txn::finalizeReceiptIndex: " . $e->getMessage());
            return false;
        }
    }

    public function deleteReceiptIndex(string $receiptNo): bool
    {
        if (!$this->ready || $receiptNo === '') return false;
        try {
            return (bool) $this->firebase->firestoreDelete(self::COL_RCPT_INDEX,
                "{$this->schoolId}_{$this->session}_{$receiptNo}");
        } catch (\Exception $_) { return false; }
    }

    // ─── Locks ─────────────────────────────────────────────────────────

    /**
     * Try to acquire an exclusive payment lock for a student. Returns the
     * lock token on success, or empty string on conflict.
     */
    public function acquireLock(string $userId, int $ttlSeconds = 120): string
    {
        if (!$this->ready || $userId === '') return '';
        $docId = "{$this->schoolId}_{$userId}";
        try {
            $existing = $this->firebase->firestoreGet(self::COL_LOCKS, $docId);
            if (is_array($existing) && !empty($existing['token'])) {
                $age = time() - strtotime((string) ($existing['acquiredAt'] ?? '2000-01-01'));
                if ($age < $ttlSeconds) return ''; // still held
            }
            $token = bin2hex(random_bytes(8));
            $ok = $this->firebase->firestoreSet(self::COL_LOCKS, $docId, [
                'schoolId'   => $this->schoolId,
                'userId'     => $userId,
                'token'      => $token,
                'acquiredAt' => date('c'),
                'ttlSeconds' => $ttlSeconds,
            ]);
            return $ok ? $token : '';
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_txn::acquireLock({$userId}): " . $e->getMessage());
            return '';
        }
    }

    public function releaseLock(string $userId, string $token): bool
    {
        if (!$this->ready || $userId === '' || $token === '') return false;
        $docId = "{$this->schoolId}_{$userId}";
        try {
            $existing = $this->firebase->firestoreGet(self::COL_LOCKS, $docId);
            if (is_array($existing) && ($existing['token'] ?? '') === $token) {
                return (bool) $this->firebase->firestoreDelete(self::COL_LOCKS, $docId);
            }
            return false; // not our lock
        } catch (\Exception $_) { return false; }
    }

    /**
     * Token-independent lock release — used by the admin "unstick" flow
     * (R.7) when a crash orphaned a lock whose token is no longer known.
     * NEVER call from the regular payment/refund path; that path must
     * always present its own token via releaseLock() so we don't steal
     * an actively-held lock from a concurrent in-flight operation.
     */
    public function forceReleaseLock(string $userId): bool
    {
        if (!$this->ready || $userId === '') return false;
        $docId = "{$this->schoolId}_{$userId}";
        try {
            return (bool) $this->firebase->firestoreDelete(self::COL_LOCKS, $docId);
        } catch (\Exception $_) { return false; }
    }

    // ─── Idempotency ───────────────────────────────────────────────────

    public function idempKey(string $userId, string $receiptNo, array $months, float $amount): string
    {
        return md5($userId . '|' . $receiptNo . '|' . implode(',', $months) . '|' . $amount);
    }

    /** Returns stored idempotency doc or null. */
    public function getIdempotency(string $hash): ?array
    {
        if (!$this->ready) return null;
        try {
            $doc = $this->firebase->firestoreGet(self::COL_IDEMP, "{$this->schoolId}_{$hash}");
            return is_array($doc) ? $doc : null;
        } catch (\Exception $_) { return null; }
    }

    public function setIdempotencyProcessing(string $hash, array $meta): bool
    {
        if (!$this->ready) return false;
        try {
            return (bool) $this->firebase->firestoreSet(self::COL_IDEMP, "{$this->schoolId}_{$hash}", array_merge($meta, [
                'schoolId'  => $this->schoolId,
                'hash'      => $hash,
                'status'    => 'processing',
                'startedAt' => date('c'),
                'updatedAt' => date('c'),
            ]));
        } catch (\Exception $_) { return false; }
    }

    public function setIdempotencySuccess(string $hash, string $receiptNo): bool
    {
        if (!$this->ready) return false;
        try {
            return (bool) $this->firebase->firestoreSet(self::COL_IDEMP, "{$this->schoolId}_{$hash}", [
                'status'     => 'success',
                'receiptNo'  => $receiptNo,
                'completedAt'=> date('c'),
                'updatedAt'  => date('c'),
            ], /* merge */ true);
        } catch (\Exception $_) { return false; }
    }

    public function deleteIdempotency(string $hash): bool
    {
        if (!$this->ready) return false;
        try {
            return (bool) $this->firebase->firestoreDelete(self::COL_IDEMP, "{$this->schoolId}_{$hash}");
        } catch (\Exception $_) { return false; }
    }

    // ─── Payment-ID idempotency ─────────────────────────────────────────
    //
    // The legacy idempKey() folds the receipt number into its hash, but
    // receipt numbers are allocated fresh on every call — so two verify
    // attempts for the SAME Razorpay payment_id never collide on it.
    // This dedicated key uses the gateway's own payment_id, which the
    // gateway guarantees is stable across retries.

    private function _paymentIdDocId(string $paymentId): string
    {
        return "{$this->schoolId}_pay_{$paymentId}";
    }

    /**
     * Has this gateway payment_id already been fully processed?
     * Returns the stored receipt info on hit, null on miss.
     */
    public function getProcessedPayment(string $paymentId): ?array
    {
        if (!$this->ready || $paymentId === '') return null;
        try {
            $doc = $this->firebase->firestoreGet(self::COL_IDEMP, $this->_paymentIdDocId($paymentId));
            if (!is_array($doc)) return null;
            return ($doc['status'] ?? '') === 'success' ? $doc : null;
        } catch (\Exception $_) { return null; }
    }

    /**
     * Record that the gateway payment_id has been fully processed,
     * tied to the receipt that came out the other side. Called from
     * the controller after _record_parent_payment_receipt() succeeds.
     */
    public function markPaymentProcessed(
        string $paymentId,
        string $receiptNo,
        string $receiptKey,
        string $studentId
    ): bool {
        if (!$this->ready || $paymentId === '') return false;
        try {
            return (bool) $this->firebase->firestoreSet(
                self::COL_IDEMP,
                $this->_paymentIdDocId($paymentId),
                [
                    'schoolId'    => $this->schoolId,
                    'session'     => $this->session,
                    'paymentId'   => $paymentId,
                    'studentId'   => $studentId,
                    'receiptNo'   => $receiptNo,
                    'receiptKey'  => $receiptKey,
                    'status'      => 'success',
                    'completedAt' => date('c'),
                    'updatedAt'   => date('c'),
                ]
            );
        } catch (\Exception $_) { return false; }
    }

    // ─── Fee Receipt (the Fees Record replacement) ─────────────────────

    /**
     * Canonical fee receipt — replaces RTDB `Students/{id}/Fees Record/{key}`.
     * Everything a printer/report needs lives here; no RTDB read required.
     */
    public function writeFeeReceipt(string $receiptKey, array $data): bool
    {
        if (!$this->ready || $receiptKey === '') return false;
        try {
            $doc = array_merge($data, [
                'schoolId'   => $this->schoolId,
                'session'    => $this->session,
                'receiptKey' => $receiptKey,
                'updatedAt'  => date('c'),
            ]);
            return (bool) $this->firebase->firestoreSet(self::COL_RCPT, "{$this->schoolId}_{$receiptKey}", $doc);
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_txn::writeFeeReceipt({$receiptKey}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Patch specific fields on an existing receipt doc WITHOUT
     * destroying the rest. Use this when a caller wants to update a
     * subset (e.g. normalise feeMonths after allocation) — the
     * full-replace `writeFeeReceipt` was previously used for this and
     * nuked studentId/amount/paymentMode/etc, leaving an orphan doc
     * invisible to the admin's "WHERE studentId == X" query.
     */
    public function updateFeeReceipt(string $receiptKey, array $partial): bool
    {
        if (!$this->ready || $receiptKey === '') return false;
        try {
            $patch = array_merge($partial, [
                'updatedAt' => date('c'),
            ]);
            return (bool) $this->firebase->firestoreSet(
                self::COL_RCPT,
                "{$this->schoolId}_{$receiptKey}",
                $patch,
                /* merge */ true
            );
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_txn::updateFeeReceipt({$receiptKey}): " . $e->getMessage());
            return false;
        }
    }

    public function getFeeReceipt(string $receiptKey): ?array
    {
        if (!$this->ready) return null;
        try {
            $d = $this->firebase->firestoreGet(self::COL_RCPT, "{$this->schoolId}_{$receiptKey}");
            return is_array($d) ? $d : null;
        } catch (\Exception $_) { return null; }
    }

    public function deleteFeeReceipt(string $receiptKey): bool
    {
        if (!$this->ready) return false;
        try {
            return (bool) $this->firebase->firestoreDelete(self::COL_RCPT, "{$this->schoolId}_{$receiptKey}");
        } catch (\Exception $_) { return false; }
    }

    /** List all receipts for a student (newest first). */
    public function listReceiptsForStudent(string $userId): array
    {
        if (!$this->ready || $userId === '') return [];
        try {
            return $this->fs->schoolWhere(self::COL_RCPT, [['studentId', '==', $userId]], 'createdAt', 'DESC');
        } catch (\Exception $_) { return []; }
    }

    // ─── Receipt allocations (per-demand breakdown of the payment) ─────

    public function writeReceiptAllocation(string $receiptKey, array $data): bool
    {
        if (!$this->ready || $receiptKey === '') return false;
        try {
            $doc = array_merge($data, [
                'schoolId'   => $this->schoolId,
                'session'    => $this->session,
                'receiptKey' => $receiptKey,
                'updatedAt'  => date('c'),
            ]);
            return (bool) $this->firebase->firestoreSet(self::COL_RCPT_ALLOC,
                "{$this->schoolId}_{$this->session}_{$receiptKey}", $doc);
        } catch (\Exception $_) { return false; }
    }

    // ─── Demands (list + update for the student) ───────────────────────

    /** All demands for a student in the current session, keyed by docId. */
    public function demandsForStudent(string $userId): array
    {
        if (!$this->ready || $userId === '') return [];
        try {
            $rows = $this->fs->schoolWhere(self::COL_DEMANDS, [
                ['session', '==', $this->session],
                ['studentId', '==', $userId],
            ]);
            $out = [];
            foreach ((array) $rows as $r) {
                $d  = $r['data'] ?? [];
                $id = $r['id']   ?? '';
                if ($id !== '' && is_array($d)) $out[$id] = $d;
            }
            return $out;
        } catch (\Exception $_) { return []; }
    }

    public function updateDemand(string $demandId, array $data): bool
    {
        if (!$this->ready || $demandId === '') return false;
        try {
            // Dual-emit: keep snake_case for PHP admin reads, add camelCase
            // for Android app deserialization.
            $extra = ['updatedAt' => date('c')];
            if (isset($data['paid_amount']))     $extra['paidAmount']     = (float) $data['paid_amount'];
            if (isset($data['balance']))         $extra['balance']        = (float) $data['balance'];
            if (isset($data['net_amount']))      $extra['netAmount']      = (float) $data['net_amount'];
            if (isset($data['discount_amount'])) $extra['discountAmount'] = (float) $data['discount_amount'];
            if (isset($data['fine_amount']))      $extra['fineAmount']    = (float) $data['fine_amount'];
            if (isset($data['original_amount'])) $extra['grossAmount']   = (float) $data['original_amount'];

            return (bool) $this->firebase->firestoreSet(self::COL_DEMANDS, $demandId,
                array_merge($data, $extra), /* merge */ true);
        } catch (\Exception $_) { return false; }
    }

    /**
     * Create a demand document (Session D1). Adds schoolId + session markers
     * so downstream queries (demandsForStudent, defaulters, reports) filter
     * correctly.
     */
    public function writeDemand(string $demandId, array $data): bool
    {
        if (!$this->ready || $demandId === '') return false;
        try {
            // Emit BOTH snake_case (admin PHP reads) and camelCase (app reads)
            // so both systems can deserialize the same document.
            $section = (string) ($data['section'] ?? '');
            $sectionKey = preg_replace('/^Section\s+/i', '', $section);
            $period = (string) ($data['period'] ?? '');
            // Use canonical helper — preserves "Yearly Fees" (the previous
            // explode(' ',$period)[0] chopped it to "Yearly", which then
            // failed to match downstream selectionSet/feeMonths checks).
            $monthName = self::periodToMonth($period);
            // period_type drives the defensive guard in the allocators
            // (Phase 10) so a future month-label drift can't accidentally
            // sweep yearly demands into a payment that didn't select them.
            // Source of truth: $data['frequency'] from the fee structure
            // ('annual' or 'monthly'), with a fallback to the period label.
            $freq          = strtolower((string) ($data['frequency'] ?? ''));
            $isYearly      = ($freq === 'annual') || ($monthName === 'Yearly Fees');
            $periodType    = $isYearly ? 'yearly' : 'monthly';
            $origAmt = (float) ($data['original_amount'] ?? 0);
            $discAmt = (float) ($data['discount_amount'] ?? 0);
            $fineAmt = (float) ($data['fine_amount'] ?? 0);
            $netAmt  = (float) ($data['net_amount'] ?? 0);
            $paidAmt = (float) ($data['paid_amount'] ?? 0);

            // Class — always dual-emit `class` (snake) AND `className`
            // (camel) so dashboard / reports / Android can pick whichever
            // they prefer. Previously only `className` was emitted; the
            // dashboard reads `class` and silently bucketed yearly
            // demands into "Unknown" because of this drift.
            $classNorm = (string) ($data['class'] ?? $data['className'] ?? '');
            $doc = array_merge($data, [
                'schoolId'       => $this->schoolId,
                'session'        => $this->session,
                'demandId'       => $demandId,
                'studentId'      => (string) ($data['student_id'] ?? $data['studentId'] ?? ''),
                'studentName'    => (string) ($data['student_name'] ?? $data['studentName'] ?? ''),
                'class'          => $classNorm,
                'className'      => $classNorm,
                'feeHead'        => (string) ($data['fee_head'] ?? $data['feeHead'] ?? ''),
                'sectionKey'     => $sectionKey,
                'month'          => $monthName,
                'period_type'    => $periodType,           // 'monthly' | 'yearly'
                'periodType'     => $periodType,           // dual-emit (Android camelCase)
                'isYearly'       => $isYearly,             // convenience flag for UI
                'grossAmount'    => $origAmt,
                'discountAmount' => $discAmt,
                'fineAmount'     => $fineAmt,
                'netAmount'      => $netAmt,
                'paidAmount'     => $paidAmt,
                'updatedAt'      => date('c'),
            ]);
            return (bool) $this->firebase->firestoreSet(self::COL_DEMANDS, $demandId, $doc);
        } catch (\Exception $_) { return false; }
    }

    // ─── Session D — Demand generation helpers ──────────────────────────

    /**
     * Read a class/section fee chart in the legacy RTDB-compatible shape:
     *   ['April' => ['Tuition' => 5000, ...], 'May' => [...], ..., 'Yearly Fees' => [...]]
     *
     * Underlying Firestore doc stores monthly+annual heads as a flat list
     * with `frequency`; this helper expands them: monthly heads appear in
     * every academic month (Apr–Mar), annual heads go under 'Yearly Fees'.
     */
    public function readFeeStructure(string $className, string $section): array
    {
        if (!$this->ready) return [];
        try {
            $docId = "{$this->schoolId}_{$this->session}_{$className}_{$section}";
            $doc = $this->firebase->firestoreGet(self::COL_FEE_STRUCT, $docId);
            if (!is_array($doc) || empty($doc['feeHeads'])) return [];

            $months = ['April','May','June','July','August','September',
                       'October','November','December','January','February','March'];
            $chart = [];
            foreach ($months as $m) $chart[$m] = [];
            $chart['Yearly Fees'] = [];

            foreach ((array) $doc['feeHeads'] as $h) {
                if (!is_array($h)) continue;
                $name = trim((string) ($h['name'] ?? ''));
                if ($name === '') continue;
                $amt  = (float) ($h['amount'] ?? 0);
                $freq = strtolower((string) ($h['frequency'] ?? 'monthly'));
                if ($freq === 'annual' || $freq === 'yearly') {
                    $chart['Yearly Fees'][$name] = $amt;
                } else {
                    foreach ($months as $m) $chart[$m][$name] = $amt;
                }
            }
            return $chart;
        } catch (\Exception $_) { return []; }
    }

    /**
     * List every {className, section} pair that has a fee structure in this
     * session. Used by generate_monthly_demands to drive the loop.
     */
    public function listSectionsWithFeeChart(): array
    {
        if (!$this->ready) return [];
        try {
            $rows = $this->fs->schoolWhere(self::COL_FEE_STRUCT, [
                ['session', '==', $this->session],
            ]);
            $out = [];
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                $c = (string) ($d['className'] ?? '');
                $s = (string) ($d['section']   ?? '');
                if ($c === '' || $s === '') continue;
                $out[] = ['class' => $c, 'section' => $s];
            }
            return $out;
        } catch (\Exception $_) { return []; }
    }

    /**
     * List students in a class/section from the `students` collection.
     * Returns array keyed by studentId: [id => {name, className, section, ...}].
     */
    public function listStudentsInSection(string $className, string $section): array
    {
        if (!$this->ready) return [];
        try {
            $rows = $this->fs->schoolWhere(self::COL_STUDENTS, [
                ['className', '==', $className],
                ['section',   '==', $section],
            ]);
            $out = [];
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                $sid = (string) ($d['studentId'] ?? $d['userId'] ?? $r['id'] ?? '');
                if ($sid !== '') $out[$sid] = $d;
            }
            return $out;
        } catch (\Exception $_) { return []; }
    }

    /**
     * Active scholarship awards for a single student in this session.
     * Returns array of award docs.
     */
    public function readScholarshipAwards(string $studentId): array
    {
        if (!$this->ready || $studentId === '') return [];
        try {
            $rows = $this->fs->schoolWhere(self::COL_SCHOLARSHIPS, [
                ['studentId', '==', $studentId],
                ['status',    '==', 'active'],
            ]);
            $out = [];
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                if (is_array($d)) $out[] = $d;
            }
            return $out;
        } catch (\Exception $_) { return []; }
    }

    /**
     * Due-day of the month (1–28) for demand due dates. Stored in the
     * feeSettings doc; defaults to 10.
     */
    public function getDueDay(): int
    {
        if (!$this->ready) return 10;
        try {
            $d = $this->firebase->firestoreGet(self::COL_FEE_SETTINGS,
                "{$this->schoolId}_{$this->session}_reminders");
            if (is_array($d) && isset($d['due_day_of_month'])) {
                $v = (int) $d['due_day_of_month'];
                if ($v >= 1 && $v <= 28) return $v;
            }
        } catch (\Exception $_) {}
        return 10;
    }

    // ─── Demand concurrency locks (Firestore) ─────────────────────────

    /**
     * Peek at a demand-generation lock. Returns null if absent/expired.
     * Lock ttl is 120s to match the prior RTDB logic.
     */
    public function demandLockCheck(string $lockKey): ?array
    {
        if (!$this->ready || $lockKey === '') return null;
        try {
            $doc = $this->firebase->firestoreGet(self::COL_DEMAND_LOCKS,
                "{$this->schoolId}_{$this->session}_{$lockKey}");
            if (!is_array($doc) || empty($doc['locked'])) return null;
            $age = time() - strtotime((string) ($doc['locked_at'] ?? '2000-01-01'));
            if ($age >= 120) return null; // stale
            return $doc + ['age_seconds' => $age];
        } catch (\Exception $_) { return null; }
    }

    public function demandLockAcquire(string $lockKey, string $token, array $meta = []): bool
    {
        if (!$this->ready || $lockKey === '' || $token === '') return false;
        try {
            return (bool) $this->firebase->firestoreSet(self::COL_DEMAND_LOCKS,
                "{$this->schoolId}_{$this->session}_{$lockKey}", array_merge($meta, [
                    'schoolId'  => $this->schoolId,
                    'session'   => $this->session,
                    'lockKey'   => $lockKey,
                    'locked'    => true,
                    'locked_at' => date('c'),
                    'token'     => $token,
                ]));
        } catch (\Exception $_) { return false; }
    }

    public function demandLockRelease(string $lockKey, string $token): bool
    {
        if (!$this->ready || $lockKey === '') return false;
        try {
            $docId = "{$this->schoolId}_{$this->session}_{$lockKey}";
            $cur = $this->firebase->firestoreGet(self::COL_DEMAND_LOCKS, $docId);
            if (is_array($cur) && (string) ($cur['token'] ?? '') === $token) {
                return (bool) $this->firebase->firestoreDelete(self::COL_DEMAND_LOCKS, $docId);
            }
        } catch (\Exception $_) {}
        return false;
    }

    // ─── Pending-write marker (safety against mid-transaction crash) ───

    public function markPending(string $receiptKey, array $meta): bool
    {
        if (!$this->ready) return false;
        try {
            return (bool) $this->firebase->firestoreSet(self::COL_PENDING, "{$this->schoolId}_{$receiptKey}",
                array_merge($meta, [
                    'schoolId'  => $this->schoolId,
                    'session'   => $this->session,
                    'receiptKey'=> $receiptKey,
                    'status'    => 'pending',
                    'startedAt' => date('c'),
                ]));
        } catch (\Exception $_) { return false; }
    }

    public function clearPending(string $receiptKey): bool
    {
        if (!$this->ready) return false;
        try {
            return (bool) $this->firebase->firestoreDelete(self::COL_PENDING, "{$this->schoolId}_{$receiptKey}");
        } catch (\Exception $_) { return false; }
    }

    // ─── Student profile convenience ───────────────────────────────────

    public function getStudent(string $userId): ?array
    {
        if (!$this->ready || $userId === '') return null;
        try {
            $d = $this->firebase->firestoreGet(self::COL_STUDENTS, "{$this->schoolId}_{$userId}");
            return is_array($d) ? $d : null;
        } catch (\Exception $_) { return null; }
    }

    /** Merge-update the student doc (used for monthFee, status, etc.). */
    public function updateStudent(string $userId, array $data): bool
    {
        if (!$this->ready || $userId === '') return false;
        try {
            return (bool) $this->firebase->firestoreSet(self::COL_STUDENTS, "{$this->schoolId}_{$userId}",
                array_merge($data, ['updatedAt' => date('c')]), /* merge */ true);
        } catch (\Exception $_) { return false; }
    }

    // ─── Advance balance + discount (thin wrappers) ────────────────────

    public function getAdvanceBalance(string $userId): float
    {
        if (!$this->ready) return 0.0;
        try {
            $d = $this->firebase->firestoreGet(self::COL_ADVANCE, "{$this->schoolId}_{$userId}");
            return is_array($d) ? (float) ($d['amount'] ?? 0) : 0.0;
        } catch (\Exception $_) { return 0.0; }
    }

    public function setAdvanceBalance(string $userId, float $amount, array $meta = []): bool
    {
        if (!$this->ready || $userId === '') return false;
        try {
            return (bool) $this->firebase->firestoreSet(self::COL_ADVANCE, "{$this->schoolId}_{$userId}",
                array_merge([
                    'schoolId'  => $this->schoolId,
                    'session'   => $this->session,
                    'studentId' => $userId,
                    'amount'    => round(max(0, $amount), 2),
                    'updatedAt' => date('c'),
                ], $meta), /* merge */ true);
        } catch (\Exception $_) { return false; }
    }

    // ─── Public accessors + batch delegate (used by Fee_refund_service's
    //     R.2 batch path, which builds batchOps inline — same pattern
    //     FeeCollectionService uses via $controller->fs / $controller->firebase.) ─

    public function getSchoolId(): string { return $this->schoolId; }
    public function getSession(): string  { return $this->session;  }

    /**
     * Commit a batch of Firestore ops in one round-trip. Thin delegate
     * to Firebase::firestoreCommitBatch so callers holding only an
     * fsTxn handle don't have to reach through for the refund R.2 path.
     */
    public function commitBatch(array $ops): bool
    {
        if (!$this->ready) return false;
        try {
            return (bool) $this->firebase->firestoreCommitBatch($ops);
        } catch (\Exception $_) { return false; }
    }

    // ─── Refunds (Session B) ───────────────────────────────────────────

    /** Read a refund by id. */
    public function getRefund(string $refId): ?array
    {
        if (!$this->ready || $refId === '') return null;
        try {
            $d = $this->firebase->firestoreGet(self::COL_REFUNDS, "{$this->schoolId}_{$refId}");
            return is_array($d) ? $d : null;
        } catch (\Exception $_) { return null; }
    }

    /** Create or update a refund record (merge semantics). */
    public function writeRefund(string $refId, array $data): bool
    {
        if (!$this->ready || $refId === '') return false;
        try {
            $doc = array_merge($data, [
                'schoolId'  => $this->schoolId,
                'session'   => $this->session,
                'refundId'  => $refId,
                'updatedAt' => date('c'),
            ]);
            return (bool) $this->firebase->firestoreSet(self::COL_REFUNDS, "{$this->schoolId}_{$refId}", $doc, /* merge */ true);
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_txn::writeRefund({$refId}): " . $e->getMessage());
            return false;
        }
    }

    /** List refunds for the current school, optionally filtered by status. */
    public function listRefunds(string $statusFilter = ''): array
    {
        if (!$this->ready) return [];
        try {
            $cond = $statusFilter !== '' ? [['status', '==', $statusFilter]] : [];
            return $this->fs->schoolWhere(self::COL_REFUNDS, $cond, 'requestedDate', 'DESC');
        } catch (\Exception $_) { return []; }
    }

    /** Write a refund voucher (the audit-trail twin of the canonical refund). */
    public function writeRefundVoucher(string $receiptKey, array $data): bool
    {
        if (!$this->ready || $receiptKey === '') return false;
        try {
            $doc = array_merge($data, [
                'schoolId'   => $this->schoolId,
                'session'    => $this->session,
                'receiptKey' => $receiptKey,
                'updatedAt'  => date('c'),
            ]);
            return (bool) $this->firebase->firestoreSet(self::COL_RFND_VCHR,
                "{$this->schoolId}_{$this->session}_{$receiptKey}", $doc);
        } catch (\Exception $_) { return false; }
    }

    /** Write a refund audit entry (one per reversed allocation set). */
    public function writeRefundAudit(string $auditId, array $data): bool
    {
        if (!$this->ready || $auditId === '') return false;
        try {
            $doc = array_merge($data, [
                'schoolId'  => $this->schoolId,
                'session'   => $this->session,
                'auditId'   => $auditId,
                'updatedAt' => date('c'),
            ]);
            return (bool) $this->firebase->firestoreSet(self::COL_RFND_AUDIT,
                "{$this->schoolId}_{$this->session}_{$auditId}", $doc);
        } catch (\Exception $_) { return false; }
    }

    /** Fetch the receipt allocation record (set during submit_fees). */
    public function getReceiptAllocation(string $receiptKey): ?array
    {
        if (!$this->ready || $receiptKey === '') return null;
        try {
            $d = $this->firebase->firestoreGet(self::COL_RCPT_ALLOC,
                "{$this->schoolId}_{$this->session}_{$receiptKey}");
            return is_array($d) ? $d : null;
        } catch (\Exception $_) { return null; }
    }

    /** Mark an allocation record as reversed (refund audit hook). */
    public function markAllocationReversed(string $receiptKey, string $auditId): bool
    {
        if (!$this->ready || $receiptKey === '') return false;
        try {
            return (bool) $this->firebase->firestoreSet(self::COL_RCPT_ALLOC,
                "{$this->schoolId}_{$this->session}_{$receiptKey}", [
                    'status'        => 'reversed',
                    'reversedAt'    => date('c'),
                    'refundAuditId' => $auditId,
                    'updatedAt'     => date('c'),
                ], /* merge */ true);
        } catch (\Exception $_) { return false; }
    }

    public function updateDiscount(string $userId, array $summary, array $context = []): bool
    {
        if (!$this->ready || $userId === '') return false;
        try {
            $existing = $this->firebase->firestoreGet(self::COL_DISCOUNTS, "{$this->schoolId}_{$userId}");
            if (!is_array($existing)) $existing = [];
            $applied = is_array($existing['applied'] ?? null) ? $existing['applied'] : [];
            if (!empty($summary['applied']) && is_array($summary['applied'])) {
                $applied[] = $summary['applied'];
            }
            $doc = [
                'schoolId'            => $this->schoolId,
                'session'             => $this->session,
                'studentId'           => $userId,
                'onDemandDiscount'    => (float) ($summary['onDemandDiscount']    ?? $existing['onDemandDiscount']    ?? 0),
                'scholarshipDiscount' => (float) ($summary['scholarshipDiscount'] ?? $existing['scholarshipDiscount'] ?? 0),
                'totalDiscount'       => (float) ($summary['totalDiscount']       ?? $existing['totalDiscount']       ?? 0),
                'applied'             => $applied,
                'studentName'         => (string) ($context['studentName'] ?? $existing['studentName'] ?? ''),
                'className'           => (string) ($context['className']   ?? $existing['className']   ?? ''),
                'section'             => (string) ($context['section']     ?? $existing['section']     ?? ''),
                'updatedAt'           => date('c'),
            ];
            return (bool) $this->firebase->firestoreSet(self::COL_DISCOUNTS, "{$this->schoolId}_{$userId}", $doc);
        } catch (\Exception $_) { return false; }
    }
}
