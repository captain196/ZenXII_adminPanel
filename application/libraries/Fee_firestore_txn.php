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

    /**
     * Canonical balance epsilon for the entire fee subsystem.
     *
     * IEEE-754 double arithmetic on rupee×paise values introduces
     * sub-paise noise (e.g. 1500 - 200 - 300 - 1000 - 0 may produce
     * 4.547e-13 instead of 0). Treat any balance whose magnitude is
     * within half a paise as zero so a "fully paid" demand never
     * reads as "partial" due to float noise — and vice versa.
     *
     * 0.005 (half-paise) is the value already used implicitly across
     * FeeCollectionService, Fee_firestore_txn allocation logic,
     * Fee_refund_service, Fee_summary_reader and Fee_summary_writer.
     * Stage A hardening (2026-05-10) brought Fee_defaulter_check —
     * which was using `> 0` directly — into line with the same value.
     *
     * Accounting/integrity layers (Fee_integrity_checker,
     * Operations_accounting) intentionally use a coarser 0.01 epsilon
     * for ledger-balanced (Dr == Cr) checks; that is a separate
     * concept from the per-demand balance comparison this constant
     * governs. Do NOT replace those usages with this constant.
     */
    public const BALANCE_EPSILON = 0.005;

    private const COL_COUNTERS    = 'feeCounters';
    private const COL_RCPT_INDEX  = 'feeReceiptIndex';
    private const COL_RCPT        = 'feeReceipts';
    private const COL_RCPT_ALLOC  = 'feeReceiptAllocations';
    private const COL_DEMANDS     = 'feeDemands';
    private const COL_DEFAULTERS  = 'feeDefaulters';
    private const COL_LOCKS       = 'feeLocks';
    private const COL_IDEMP       = 'feeIdempotency';
    private const COL_PENDING     = 'feePendingWrites';
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
    /**
     * Route to sharded counter when enabled, with automatic fallback to
     * the legacy single-pointer path on ANY sharded-side failure. This
     * lets us roll out Phase 4 safely: flip the flag per-school, monitor,
     * roll back by flipping the flag off if anything surprises us.
     */
    public function nextCounter(string $kind): int
    {
        if (!$this->ready) return 0;

        // Phase 4 — opt-in sharded counter. Gated by feeSettings doc
        // `{schoolId}_{session}_counters.shardedEnabled` (bool). We cache
        // the flag per-request to avoid extra Firestore reads.
        if ($kind === 'receipt_seq' && $this->_shardedCounterEnabled()) {
            try {
                $CI =& get_instance();
                if (!isset($CI->feeShardedCounter)) {
                    $CI->load->library('Fee_sharded_counter', null, 'feeShardedCounter');
                    $CI->feeShardedCounter->init($this->firebase, $this->schoolId, $this->session);
                }
                $v = $CI->feeShardedCounter->nextCounter($kind);
                if ($v > 0) return $v;
                log_message('error', "Fee_firestore_txn::nextCounter({$kind}) sharded returned 0; falling back to legacy");
            } catch (\Throwable $e) {
                log_message('error', "Fee_firestore_txn::nextCounter({$kind}) sharded threw; falling back to legacy: " . $e->getMessage());
            }
            // fall through to legacy path
        }

        return $this->nextCounterLegacy($kind);
    }

    /**
     * Per-request cached read of the sharded-counter flag. The flag is
     * intentionally session-scoped (feeSettings/{schoolId}_{session}_counters)
     * so a mid-session rollback cleanly reverts without touching any
     * school-wide config. Defaults to false on any read error — safer to
     * run legacy than to accidentally enable sharding on a miscfg doc.
     */
    private ?bool $_shardedCounterEnabledCache = null;
    private function _shardedCounterEnabled(): bool
    {
        if ($this->_shardedCounterEnabledCache !== null) {
            return $this->_shardedCounterEnabledCache;
        }
        $enabled = false;
        try {
            $cfg = $this->firebase->firestoreGet('feeSettings', "{$this->schoolId}_{$this->session}_counters");
            if (is_array($cfg) && !empty($cfg['shardedEnabled'])) {
                $enabled = true;
            }
        } catch (\Throwable $_) { /* default false on error */ }
        $this->_shardedCounterEnabledCache = $enabled;
        return $enabled;
    }

    /**
     * Legacy single-pointer claim path. Renamed from the original
     * nextCounter() — preserved byte-for-byte as the fallback and as the
     * code path for any counter kind other than receipt_seq.
     */
    private function nextCounterLegacy(string $kind): int
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
     * Release a previously-claimed counter value on abort (e.g. payment
     * failed at the overpayment guard AFTER nextCounter returned a number).
     * Without this, every failed attempt burned a receipt number — the
     * user saw F#3 for what should have been F#1 when their first two
     * retries failed. Deletes the claim doc and rewinds the fast-skip
     * pointer iff it's currently pointing at this exact value, so a
     * single-cashier retry gets the same number back. In the multi-
     * cashier case a concurrent claim already advanced the pointer past
     * us — leave it alone, and let nextCounter find the next free slot.
     */
    public function releaseCounterClaim(string $kind, int $value): bool
    {
        if (!$this->ready || $kind === '' || $value < 1) return false;
        $counterDoc = "{$this->schoolId}_{$kind}";
        $claimId    = "{$this->schoolId}_{$kind}_claim_{$value}";
        try {
            $this->firebase->firestoreDelete(self::COL_COUNTERS, $claimId);
        } catch (\Exception $_) { /* non-fatal */ }
        try {
            $cur = $this->firebase->firestoreGet(self::COL_COUNTERS, $counterDoc);
            $curVal = is_array($cur) ? (int) ($cur['value'] ?? 0) : 0;
            if ($curVal === $value) {
                $this->firebase->firestoreSet(self::COL_COUNTERS, $counterDoc, [
                    'schoolId'  => $this->schoolId,
                    'session'   => $this->session,
                    'kind'      => $kind,
                    'value'     => max(0, $value - 1),
                    'updatedAt' => date('c'),
                ]);
            }
        } catch (\Exception $_) { /* non-fatal */ }
        return true;
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
    /** acquireLockStatus() outcomes. */
    const LOCK_OWNER    = 'OWNER';      // we hold it — proceed
    const LOCK_CONFLICT = 'CONFLICT';   // someone else holds a live lease — refuse, retryable
    const LOCK_UNKNOWN  = 'UNKNOWN';    // outcome undetermined — refuse, assume NOTHING

    /**
     * ── REF-1 · ATOMIC PER-STUDENT LOCK WITH A LEASE ──────────────────────
     *
     * `feeLocks/{school}_{userId}` has TWO producers, and until now only one of
     * them was correct:
     *
     *   FeeCollectionService  claim batch with `precondition: exists:false`
     *                         — already atomic for the fresh case.
     *   acquireLock()         read → judge age → BLIND SET
     *                         — two callers that read before either writes
     *                           BOTH acquire, and the blind set also clobbers
     *                           a lock the collection service holds correctly.
     *
     * Both write an IDENTICAL field set (schoolId·userId·token·acquiredAt·
     * ttlSeconds), verified rather than assumed, so one predicate can judge a
     * lock written by either.
     *
     * ── WHY A STATUS AND NOT A STRING ─────────────────────────────────────
     * acquireLock() returns '' for THREE different things — a live lock, a
     * thrown exception, and an uninitialised helper. Those demand OPPOSITE
     * recoveries:
     *
     *   CONFLICT → the write definitely did NOT land. Safe to retry, safe to
     *              report to the user as "in progress".
     *   UNKNOWN  → the write MAY have landed. The caller must not retry
     *              blindly and — for a refund — must NOT delete its
     *              idempotency guard, because doing so could permit a second
     *              disbursement.
     *
     * Collapsing them is the defect. `acquireLock()` below keeps its exact
     * signature and contract so the existing caller is untouched.
     *
     * @return array{status:string, token:string, recovered:bool}
     */
    public function acquireLockStatus(string $userId, int $ttlSeconds = 120): array
    {
        if (!$this->ready || $userId === '') {
            return $this->_lockOut(self::LOCK_UNKNOWN, '', false);
        }

        $docId = "{$this->schoolId}_{$userId}";
        $token = bin2hex(random_bytes(8));
        $now   = time();

        $payload = [
            'schoolId'   => $this->schoolId,
            'userId'     => $userId,
            'token'      => $token,
            'acquiredAt' => date('c', $now),
            'ttlSeconds' => $ttlSeconds,
        ];

        try {
            $res = $this->firebase->firestoreCreateStatus(self::COL_LOCKS, $docId, $payload);
        } catch (\Throwable $e) {
            log_message('error', "Fee_firestore_txn::acquireLockStatus({$userId}) create threw: " . $e->getMessage());
            return $this->_lockOut(self::LOCK_UNKNOWN, '', false);
        }

        $status = (string) ($res['status'] ?? 'FAILURE');

        // Exclusive create — decided server-side, so no read-then-write window.
        if ($status === 'SUCCESS') {
            return $this->_lockOut(self::LOCK_OWNER, $token, false);
        }

        // NOT a collision: the write may or may not have landed. Treating this
        // as "someone else has it" would let a caller retry into a lock it
        // actually owns; treating it as ours would run two writers at once.
        if ($status !== 'COLLISION') {
            log_message('error',
                "Fee_firestore_txn::acquireLockStatus({$userId}) UNKNOWN — "
                . (string) ($res['message'] ?? ''));
            return $this->_lockOut(self::LOCK_UNKNOWN, '', false);
        }

        // ── A lock exists. Is its lease still alive? ──
        try {
            $existing = $this->firebase->firestoreGet(self::COL_LOCKS, $docId);
        } catch (\Throwable $e) {
            return $this->_lockOut(self::LOCK_UNKNOWN, '', false);
        }
        if (!is_array($existing)) {
            // It existed a moment ago and cannot be read now. Undetermined.
            return $this->_lockOut(self::LOCK_UNKNOWN, '', false);
        }

        if (!$this->_lockExpired($existing, $now, $ttlSeconds)) {
            return $this->_lockOut(self::LOCK_CONFLICT, '', false);
        }

        // ── Expired: recover by COMPARE-AND-SWAP, never a second blind set ──
        // The precondition carries the version from the read that judged the
        // lease dead, so two processes that both see it expired cannot both
        // take it. Without a version we cannot guarantee that, and an
        // unguarded merge would clobber a concurrent recoverer — the very
        // defect being fixed — so we fail closed instead.
        $updateTime = (string) ($existing['__updateTime'] ?? '');
        if ($updateTime === '' || !method_exists($this->firebase, 'firestoreCommitBatchStatus')) {
            log_message('error',
                "Fee_firestore_txn::acquireLockStatus({$userId}) — cannot CAS a stale lock "
                . '(no version or no CAS primitive); refusing rather than writing unguarded');
            return $this->_lockOut(self::LOCK_UNKNOWN, '', false);
        }

        $cas = $this->firebase->firestoreCommitBatchStatus([[
            'op'           => 'update',
            'collection'   => self::COL_LOCKS,
            'docId'        => $docId,
            'merge'        => true,
            'data'         => $payload + ['takenOverFrom' => (string) ($existing['token'] ?? '')],
            'precondition' => ['updateTime' => $updateTime],
        ]]);

        $casStatus = (string) ($cas['status'] ?? 'FAILURE');

        if ($casStatus === 'SUCCESS') {
            log_message('info',
                "Fee_firestore_txn: recovered expired lock on student [{$userId}] from token ["
                . (string) ($existing['token'] ?? '') . ']');
            return $this->_lockOut(self::LOCK_OWNER, $token, true);
        }

        // The document moved between our read and our write — another process
        // recovered it first. Exactly one recoverer can win.
        if ($casStatus === 'PRECONDITION_CONFLICT') {
            return $this->_lockOut(self::LOCK_CONFLICT, '', false);
        }

        return $this->_lockOut(self::LOCK_UNKNOWN, '', false);
    }

    /**
     * A lock is expired once its lease has elapsed.
     *
     * The lease is taken from the DOCUMENT's own `ttlSeconds` when present, not
     * the caller's — a lock the collection service took for 120 s must be
     * honoured on its own terms, or a caller passing a shorter ttl could
     * declare a live lock dead and displace it.
     *
     * A missing or unparseable `acquiredAt` counts as EXPIRED, which is exactly
     * what the previous implementation did (it compared against '2000-01-01').
     * Keeping that direction matters: a malformed lock must never be immortal,
     * and exclusivity is still enforced by the CAS on the takeover.
     */
    private function _lockExpired(array $lock, int $now, int $callerTtl): bool
    {
        if (empty($lock['token'])) return true;

        $ttl = (int) ($lock['ttlSeconds'] ?? 0);
        if ($ttl <= 0) $ttl = $callerTtl;

        $acquiredAt = (string) ($lock['acquiredAt'] ?? '');
        if ($acquiredAt === '') return true;

        $ts = strtotime($acquiredAt);
        if ($ts === false) return true;

        return ($now - $ts) >= $ttl;
    }

    private function _lockOut(string $status, string $token, bool $recovered): array
    {
        return ['status' => $status, 'token' => $token, 'recovered' => $recovered];
    }

    /**
     * Back-compat wrapper — unchanged signature and contract (token on success,
     * '' otherwise). Callers that need to tell CONFLICT from UNKNOWN must use
     * acquireLockStatus(); this one deliberately still collapses them, because
     * changing what '' means would silently alter every existing caller's
     * recovery behaviour.
     */
    public function acquireLock(string $userId, int $ttlSeconds = 120): string
    {
        return $this->acquireLockStatus($userId, $ttlSeconds)['token'];
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

    // ─── Payment-ID idempotency (Phase 2.5 Step 4) ─────────────────────
    //
    // Canonical store: processedPayments/{paymentId}. The gateway's own
    // payment_id is the natural idempotency key — globally unique,
    // stable across retries. Previously we stored this under the
    // feeIdempotency collection with a composite "{schoolId}_pay_{id}"
    // doc ID; that contract is now retired but a read-through fallback
    // is kept for the duration of one migration cycle so webhook
    // retries against already-processed payments still short-circuit.
    //
    // processedPayments doc schema:
    //   { paymentId, schoolId, session, studentId, receiptNo, receiptKey,
    //     status:'success', completedAt, updatedAt }

    private const COL_PROCESSED_PAYMENTS = 'processedPayments';

    /**
     * Has this gateway payment_id already been fully processed?
     * Returns the stored receipt info on hit, null on miss.
     */
    public function getProcessedPayment(string $paymentId): ?array
    {
        if (!$this->ready || $paymentId === '') return null;
        try {
            $doc = $this->firebase->firestoreGet(self::COL_PROCESSED_PAYMENTS, $paymentId);
            if (is_array($doc) && ($doc['status'] ?? '') === 'success') {
                // ── PAY-1 — processedPayments is a GLOBAL keyspace ──────────
                // The doc id is the bare gateway payment id with no school
                // prefix, unlike the legacy feeIdempotency fallback below
                // ("{schoolId}_pay_{paymentId}"), which IS scoped. Without this
                // check a marker belonging to another tenant would answer this
                // tenant's payment as "already processed" — the parent would be
                // told it was paid while nothing was applied here.
                //
                // Not currently reachable: gateway payment ids carry far too
                // much entropy to collide (the mock adapter uses random_bytes(8),
                // and Razorpay ids are globally unique). This is defence in
                // depth, and it restores the scoping the migration to a global
                // keyspace dropped. A marker with NO schoolId is accepted, so
                // pre-migration rows are unaffected.
                $owner = (string) ($doc['schoolId'] ?? '');
                if ($owner !== '' && $owner !== $this->schoolId) {
                    log_message('error',
                        'Fee_firestore_txn::getProcessedPayment(' . $paymentId . ') — marker '
                        . 'belongs to tenant [' . $owner . '] but this context is ['
                        . $this->schoolId . ']; refusing to treat it as processed');
                    return null;
                }
                return $doc;
            }
        } catch (\Exception $_) {}
        // Legacy fallback — entries written pre-migration live in
        // feeIdempotency under "{schoolId}_pay_{paymentId}". Delete-safe:
        // after the backfill script runs, nothing remains here.
        try {
            $legacy = $this->firebase->firestoreGet(self::COL_IDEMP, "{$this->schoolId}_pay_{$paymentId}");
            if (is_array($legacy) && ($legacy['status'] ?? '') === 'success') return $legacy;
        } catch (\Exception $_) {}
        return null;
    }

    /**
     * Record that the gateway payment_id has been fully processed,
     * tied to the receipt that came out the other side. Called from
     * the controller after _record_parent_payment_receipt() succeeds.
     */
    /**
     * ── PAY-2 · WHY THE KEY IS THE PAYMENT ID AND NOTHING ELSE ────────────
     *
     * An idempotency key must be EXACTLY the identity of the thing that may
     * happen only once. Here that is the gateway payment.
     *
     * A gateway payment id is globally unique and belongs to exactly ONE order
     * (Razorpay mints it per captured payment, not per account sequence). So
     * `paymentId` alone already identifies the operation completely.
     *
     * Keying by `{school}_{orderId}_{paymentId}` would be WORSE, not safer, and
     * this is the trap worth naming: it would create TWO keys for ONE payment
     * whenever the order id supplied on a retry differed, so the same payment
     * could be applied twice — under two different keys, each looking pristine.
     * A more specific key is not a stronger guarantee; it is a weaker one, when
     * the extra component is not part of the identity.
     *
     * So: `orderId` is recorded as a VERIFIED FIELD, never as part of the key.
     * A marker claiming payment P belonged to order X, presented while
     * processing payment P for order Y, is an anomaly — one payment cannot
     * belong to two orders — and the caller must refuse rather than treat it as
     * either "already processed" or "fresh".
     *
     * `schoolId` is likewise a field, not a key component, for the same reason:
     * the gateway identity is already global, so scoping the key by tenant
     * would let the same payment be applied once per tenant.
     */
    public function markPaymentProcessed(
        string $paymentId,
        string $receiptNo,
        string $receiptKey,
        string $studentId,
        string $orderId = ''
    ): bool {
        if (!$this->ready || $paymentId === '') return false;
        try {
            return (bool) $this->firebase->firestoreSet(
                self::COL_PROCESSED_PAYMENTS,
                $paymentId,
                [
                    'paymentId'   => $paymentId,
                    'orderId'     => $orderId,   // PAY-2: verified, never keyed
                    'schoolId'    => $this->schoolId,
                    'session'     => $this->session,
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

    /**
     * Read-boundary normaliser for a single demand doc.
     *
     * Phase 2.5 contract: Firestore stores ONLY camelCase. Legacy PHP
     * call-sites still reach for snake_case keys (fee_head, paid_amount,
     * period_key, ...). This helper populates the legacy aliases on
     * in-memory copies of the doc so those readers keep returning the
     * same numbers — without round-tripping them back to Firestore.
     *
     * Any reader that writes the demand back MUST go through
     * updateDemand() / writeDemand(), which strip snake_case before
     * committing. This keeps the storage single-source even while the
     * PHP read-sites are progressively migrated to camelCase.
     */
    public static function normalizeDemandDoc(array $d): array
    {
        // camel → snake aliases for legacy readers
        static $aliases = [
            'studentId'      => 'student_id',
            'studentName'    => 'student_name',
            'demandId'       => 'demand_id',
            'feeHead'        => 'fee_head',
            'periodKey'      => 'period_key',
            'periodType'     => 'period_type',
            'grossAmount'    => 'original_amount',
            'discountAmount' => 'discount_amount',
            'fineAmount'     => 'fine_amount',
            'netAmount'      => 'net_amount',
            'paidAmount'     => 'paid_amount',
            'dueDate'        => 'due_date',
            'createdAt'      => 'created_at',
            'createdBy'      => 'created_by',
        ];
        // Canonical camelCase ALWAYS wins when both shapes are present.
        // Pre-fix: the old bidirectional code only filled a missing key,
        // so when a legacy demand had paid_amount=0 from the generator
        // AND updateDemand later wrote paidAmount=1, BOTH keys stuck
        // around with divergent values. Every admin-side reader still on
        // snake_case (fetch_months, defaulter calc, collection report…)
        // then saw the stale 0 and rendered "Unpaid" for a partially-paid
        // demand — while the parent app (reads camelCase) showed the
        // correct partial state. Resolving in favor of camelCase here
        // unblocks every legacy reader with one surgical change.
        foreach ($aliases as $camel => $snake) {
            if (isset($d[$camel])) {
                $d[$snake] = $d[$camel];
            } elseif (isset($d[$snake])) {
                $d[$camel] = $d[$snake];
            }
        }
        return $d;
    }

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
                if ($id !== '' && is_array($d)) $out[$id] = self::normalizeDemandDoc($d);
            }
            return $out;
        } catch (\Exception $_) { return []; }
    }

    /**
     * B-011 (2026-08-19) — FAIL-CLOSED variant of demandsForStudent().
     *
     * THE DEFECT THIS CLOSES. `demandsForStudent()` ends in
     * `catch (\Exception $_) { return []; }`. For a READER that is a benign
     * degradation. For the DEMAND-GENERATION DUPLICATE GUARD it is a
     * double-billing mechanism, because the guard reads "no existing demands"
     * and concludes there is nothing to deduplicate against.
     *
     * That would be survivable if demand ids were deterministic — a re-write
     * would simply land on the same document. They are not: the monthly
     * generator mints `bin2hex(random_bytes(10))`
     * (`newDemandId()`), so every regenerated charge takes a BRAND NEW document
     * id and cannot collide with the one it duplicates. Structural protection
     * is therefore absent, and the application-level guard is the only thing
     * standing between a transient Firestore error and a family being charged
     * twice.
     *
     * Measured consequence of exactly this shape: STU0032 carried 26 duplicate
     * pairs — ₹11,800 of phantom debt — across two id schemes.
     *
     * This variant THROWS instead of returning an empty set, so the caller can
     * refuse to generate rather than generate blind. Reading is cheap; billing
     * a family twice is not.
     *
     * `demandsForStudent()` is deliberately left unchanged — its ten read-only
     * callers legitimately prefer degradation over an exception.
     *
     * @throws \RuntimeException when the demand set cannot be read
     */
    public function demandsForStudentStrict(string $userId): array
    {
        if (!$this->ready) {
            throw new \RuntimeException('Fee_firestore_txn not ready — demand set unreadable');
        }
        if ($userId === '') {
            throw new \RuntimeException('demandsForStudentStrict: empty studentId');
        }
        try {
            $rows = $this->fs->schoolWhere(self::COL_DEMANDS, [
                ['session',   '==', $this->session],
                ['studentId', '==', $userId],
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'demand read failed for student [' . $userId . ']: ' . $e->getMessage(), 0, $e);
        }
        $out = [];
        foreach ((array) $rows as $r) {
            $d  = $r['data'] ?? [];
            $id = $r['id']   ?? '';
            if ($id !== '' && is_array($d)) $out[$id] = self::normalizeDemandDoc($d);
        }
        return $out;
    }

    /**
     * BUG-076 (2026-05-27): session-independent demand fetch for a student.
     *
     * demandsForStudent() filters by $this->session, which is pinned to the
     * admin's selected session at controller boot. Lifecycle events
     * (promotion archival, soft-delete freeze, admission reversal) need to
     * see demands wherever they live — the archive decision is driven by
     * className/section, not session. This wrapper omits the session
     * predicate so archive loops can iterate the student's full demand set
     * regardless of operator session-selector drift.
     */
    public function demandsForStudentAllSessions(string $userId): array
    {
        if (!$this->ready || $userId === '') return [];
        try {
            $rows = $this->fs->schoolWhere(self::COL_DEMANDS, [
                ['studentId', '==', $userId],
            ]);
            $out = [];
            foreach ((array) $rows as $r) {
                $d  = $r['data'] ?? [];
                $id = $r['id']   ?? '';
                if ($id !== '' && is_array($d)) $out[$id] = self::normalizeDemandDoc($d);
            }
            return $out;
        } catch (\Exception $_) { return []; }
    }

    /**
     * Partial-update a demand (merge). Callers still pass snake_case keys
     * from legacy call-sites; this canonicalises every field to camelCase
     * before the Firestore write, so the stored doc is single-source.
     */
    public function updateDemand(string $demandId, array $data): bool
    {
        if (!$this->ready || $demandId === '') return false;
        try {
            $patch = ['updatedAt' => date('c')];

            // Canonical camelCase-only patch. Accept both shapes from the
            // caller (snake_case wins for back-compat while the callers
            // still use older names) and only ever emit camelCase.
            static $map = [
                'paidAmount'     => ['paid_amount', 'paidAmount'],
                'balance'        => ['balance'],
                'netAmount'      => ['net_amount', 'netAmount'],
                'discountAmount' => ['discount_amount', 'discountAmount'],
                'fineAmount'     => ['fine_amount', 'fineAmount'],
                'grossAmount'    => ['original_amount', 'grossAmount'],
                'status'         => ['status'],
                'lastReceipt'    => ['last_receipt', 'lastReceipt'],
                'dueDate'        => ['due_date', 'dueDate'],
                'periodKey'      => ['period_key', 'periodKey'],
                'periodType'     => ['period_type', 'periodType'],
                'isYearly'       => ['isYearly'],
                'feeHead'        => ['fee_head', 'feeHead'],
                'studentId'      => ['student_id', 'studentId'],
                'studentName'    => ['student_name', 'studentName'],
                'className'      => ['class', 'className'],
                // ── DEC-012 (2026-08-18) ────────────────────────────────────
                // This map is a STRICT WHITELIST with no passthrough, so any
                // key missing from it is silently discarded. `proRateFees()`
                // has always passed `prorated` and `prorateFactor`; both were
                // dropped here and never reached Firestore, which is why the
                // estate contains ZERO demands carrying a proration marker.
                // The audit trail for a money-changing operation was being
                // thrown away at the write boundary.
                'prorated'       => ['prorated'],
                'prorateFactor'  => ['prorateFactor'],
                // The LIST (pre-proration) charge. Written once and never
                // rewritten, so proration always recomputes from the original
                // instead of compounding on a re-run — and so the original
                // charge remains recoverable. This is the first, narrowest
                // instance of the DEC-001 charge anchor.
                'listGrossAmount'    => ['listGrossAmount'],
                'listDiscountAmount' => ['listDiscountAmount'],
                // ── PROV-1 (2026-08-23) · THE "WHO AND WHEN" OF A CLOSURE ────
                // Same class of loss as DEC-012 above, and found the same way.
                // Every status change below is already whitelisted and lands
                // on the document — but the provenance fields written ALONGSIDE
                // it were dropped here, so a demand records THAT it was frozen,
                // archived or reversed and never by whom or when:
                //
                //   freezeFeesOnSoftDelete()  status:'frozen'   + frozenAt/By
                //   the merge archive loop    status:'archived' + archivedAt/By
                //   reverseAdmissionFees()    status:'reversed' + reversedAt/By
                //   Fee_late_fee_service      fine_amount       + fine_computed_at
                //
                // The freeze case is the one that bites: it is live from three
                // call sites (Sis::withdraw_student, Sis::delete_student,
                // Hostel.php) and stops collection on a student's whole fee
                // book, with nothing anywhere recording who did it.
                //
                // The inconsistency is what makes this unambiguous rather than
                // a judgement call. `commitDemandBatch()` passes an archive op's
                // data VERBATIM, so the promotion archive path persists
                // archivedAt while the single-doc merge archive path — the same
                // logical operation — silently loses it. The whitelist was
                // therefore never a boundary either: any caller can route the
                // same field through the batch API and have it stick.
                'frozenAt'       => ['frozenAt'],
                'frozenBy'       => ['frozenBy'],
                // FRZ-4 — the reverse transition needs the same provenance.
                'unfrozenAt'     => ['unfrozenAt'],
                'unfrozenBy'     => ['unfrozenBy'],
                'archivedAt'     => ['archivedAt'],
                'archivedBy'     => ['archivedBy'],
                'archiveReason'  => ['archiveReason'],
                'reversedAt'     => ['reversedAt'],
                'reversedBy'     => ['reversedBy'],
                'fineComputedAt' => ['fine_computed_at', 'fineComputedAt'],

            ];
            foreach ($map as $camel => $sources) {
                foreach ($sources as $k) {
                    if (array_key_exists($k, $data)) {
                        $val = $data[$k];
                        if (in_array($camel, ['paidAmount','balance','netAmount','discountAmount',
                                              'fineAmount','grossAmount','prorateFactor',
                                              'listGrossAmount','listDiscountAmount'], true)) {
                            $val = (float) $val;
                        }
                        if ($camel === 'prorated') $val = (bool) $val;
                        $patch[$camel] = $val;
                        break;
                    }
                }
            }

            return (bool) $this->firebase->firestoreSet(self::COL_DEMANDS, $demandId,
                $patch, /* merge */ true);
        } catch (\Exception $_) { return false; }
    }

    /**
     * Create / idempotently upsert a demand document.
     *
     * Phase 2.5: camelCase is the ONLY shape written to Firestore. The
     * caller may still pass snake_case fields (legacy generator code);
     * this function canonicalises every input before emitting the doc.
     * merge=true + deterministic demand IDs keep re-runs idempotent:
     * paidAmount / balance / status on an already-paid demand are
     * preserved because we never overwrite them during generation — only
     * the structural fields (amounts, dates, metadata) get refreshed.
     */
    /**
     * P5 · THE MONEY-COHERENCE RULE —  netAmount == grossAmount − discountAmount
     *
     * Returns null when the demand is coherent, or the offending figures when it
     * is not. Pure: no logging, no I/O, no mutation — so it can be asserted
     * directly and reused by a verifier, a report or a migration check.
     *
     * A zero/absent gross is NOT judged: module and legacy shapes legitimately
     * omit it, and there is nothing to be coherent about.
     *
     * `fineAmount` is deliberately excluded — a fine is added to the BALANCE,
     * not netted into the charge (`balance = netAmount + fine − paidAmount`).
     *
     * Tolerance is half a paisa, matching every other money comparison here.
     *
     * @return array{gross:float,discount:float,net:float,expectedNet:float,delta:float}|null
     */
    public static function moneyIncoherence(array $doc): ?array
    {
        $gross    = (float) ($doc['grossAmount']    ?? 0);
        $discount = (float) ($doc['discountAmount'] ?? 0);
        $net      = (float) ($doc['netAmount']      ?? 0);
        if ($gross <= 0) return null;

        $expected = $gross - $discount;
        $delta    = $expected - $net;
        if (abs($delta) <= 0.005) return null;

        return ['gross' => $gross, 'discount' => $discount, 'net' => $net,
                'expectedNet' => $expected, 'delta' => $delta];
    }

    public function writeDemand(string $demandId, array $data): bool
    {
        if (!$this->ready || $demandId === '') return false;
        try {
            // BUG-045 Phase 2 (2026-05-25): body extracted to _buildDemandDoc()
            // helper so single-write path (this method) and batched-write path
            // (commitDemandBatch) share byte-identical normalization.
            $doc = $this->_buildDemandDoc($demandId, $data);
            return (bool) $this->firebase->firestoreSet(self::COL_DEMANDS, $demandId, $doc, /* merge */ true);
        } catch (\Exception $_) { return false; }
    }

    /**
     * BUG-045 Phase 2 (2026-05-25): demand-doc normalization extracted from
     * writeDemand() body. Pure transformation — no Firestore I/O.
     *
     * Called by:
     *   - writeDemand() (single-write path; unchanged behavior)
     *   - commitDemandBatch() (batched-write path; new in Phase 2)
     *
     * Output is byte-identical to the pre-extraction writeDemand emit.
     * Legacy snake-case inputs are consumed and never re-emitted —
     * Firestore stays clean (single-source camelCase payload).
     */
    private function _buildDemandDoc(string $demandId, array $data): array
    {
        $pick = function (array $d, array $keys, $default = '') {
            foreach ($keys as $k) {
                if (array_key_exists($k, $d) && $d[$k] !== null && $d[$k] !== '') return $d[$k];
            }
            return $default;
        };

        $section    = (string) $pick($data, ['section']);
        $sectionKey = (string) preg_replace('/^Section\s+/i', '', $section);
        $period     = (string) $pick($data, ['period']);
        $monthName  = self::periodToMonth($period);

        $freq     = strtolower((string) $pick($data, ['frequency']));
        $isYearly = in_array($freq, ['annual', 'yearly', 'one-time', 'onetime'], true)
                    || ($monthName === 'Yearly Fees');
        $periodType = $isYearly ? 'yearly' : 'monthly';

        // Fees V7: canonical billingType — caller-provided, else derived from cadence
        // so every demand is self-describing (INV-1). Only {Monthly,Yearly} permitted.
        $billingType = (string) $pick($data, ['billingType']);
        if ($billingType !== 'Monthly' && $billingType !== 'Yearly') {
            $billingType = $isYearly ? 'Yearly' : 'Monthly';
        }

        $classNorm = (string) $pick($data, ['className', 'class']);

        // BUG-075 fix (2026-05-26): build structural fields first; emit
        // payment-state fields (paidAmount/balance/status) ONLY when the
        // caller explicitly provides them. Pre-fix unconditional emission
        // with default-0 values was silently overwriting existing paid
        // state via Firestore merge=true SET during re-runs (promote /
        // reverse-promote re-invokes assignInitialFees against same
        // demandId). Now: caller (assignInitialFees / payment posting /
        // refund flow) explicitly decides whether to set these fields.
        // Absent fields are preserved by merge=true from the prior write.
        $doc = [
            'schoolId'       => $this->schoolId,
            // ── ROLL-2 · AN EXPLICIT TARGET SESSION ─────────────────────
            // This was unconditionally $this->session, so a demand could only
            // ever be written into the session the txn instance was inited
            // with. Session ROLLOVER needs to write into the session it is
            // rolling INTO, which is not the one the admin is operating in.
            //
            // Optional and defaulted, exactly like the other caller-supplied
            // fields here: no existing caller passes 'session' (verified by
            // search), so every current write is byte-identical.
            'session'        => (string) $pick($data, ['session'], $this->session),
            'demandId'       => $demandId,
            'studentId'      => (string) $pick($data, ['studentId', 'student_id']),
            'studentName'    => (string) $pick($data, ['studentName', 'student_name']),
            'className'      => $classNorm,
            'section'        => $section,
            'sectionKey'     => $sectionKey,
            'feeHead'        => (string) $pick($data, ['feeHead', 'fee_head']),
            'feeHeadId'      => (string) $pick($data, ['feeHeadId']),
            'category'       => (string) $pick($data, ['category']),
            'frequency'      => $freq,
            'period'         => $period,
            'month'          => $monthName,
            'periodKey'      => (string) $pick($data, ['periodKey', 'period_key']),
            'periodType'     => $periodType,
            'isYearly'       => $isYearly,
            'billingType'    => $billingType,
            'grossAmount'    => (float) $pick($data, ['grossAmount', 'original_amount'], 0),
            'discountAmount' => (float) $pick($data, ['discountAmount', 'discount_amount'], 0),
            'fineAmount'     => (float) $pick($data, ['fineAmount', 'fine_amount'], 0),
            'netAmount'      => (float) $pick($data, ['netAmount', 'net_amount'], 0),
            'dueDate'        => (string) $pick($data, ['dueDate', 'due_date']),
            // PA-FEE-GRACE — the last day this demand is still on time,
            // `dueDate + overdue_grace_days`, derived by Fee_overdue_policy at
            // generation. This allowlist is why it must be named here: a field
            // the builder does not know about is silently dropped, which is
            // exactly what happened on the first attempt (0 of 25 demands
            // carried it).
            //
            // Stamped rather than sent as a policy so the Parent App holds no
            // overdue rule of its own — it compares this date to today.
            // Defaults to '' for demands with no due date (Transport, Hostel),
            // and the app then falls back to `dueDate`, which is the correct
            // zero-grace answer.
            'overdueFrom'    => (string) $pick($data, ['overdueFrom', 'overdue_from']),
            'createdAt'      => (string) $pick($data, ['createdAt', 'created_at'], date('c')),
            'createdBy'      => (string) $pick($data, ['createdBy', 'created_by']),
            'updatedAt'      => date('c'),
        ];

        // Payment-state fields — conditionally emitted (BUG-075 fix).
        if (array_key_exists('paidAmount', $data) || array_key_exists('paid_amount', $data)) {
            $doc['paidAmount'] = (float) $pick($data, ['paidAmount', 'paid_amount'], 0);
        }
        if (array_key_exists('balance', $data)) {
            $doc['balance'] = (float) $pick($data, ['balance'], 0);
        }
        if (array_key_exists('status', $data)) {
            $doc['status'] = (string) $pick($data, ['status'], 'unpaid');
        }
        // Item 1: persist concession attribution ONLY for concession demands
        // (conditional — keeps non-concession demands byte-identical; no snake alias needed).
        if (array_key_exists('concessionApplied', $data)) {
            $doc['concessionApplied'] = (float) $pick($data, ['concessionApplied'], 0);
        }

        // ── PROMO-SC-1 · WHICH MODULE RAISED THIS CHARGE ────────────────────
        //
        // Present only on demands written by `createModuleFee()`, and its
        // PRESENCE is the meaningful part: this charge was not priced from a
        // class fee chart, so a class or section change must not archive it.
        //
        // Deliberately separate from `category`, which the M5 registry stamp
        // overrides with the registry's authoritative value — so `category`
        // cannot answer "was this class-derived?" once the registry is on.
        //
        // Conditional, like concessionApplied and chargeInstance: a chart
        // caller supplies nothing and writes a byte-identical document.
        if (array_key_exists('moduleType', $data)) {
            $moduleType = trim((string) $pick($data, ['moduleType'], ''));
            if ($moduleType !== '') $doc['moduleType'] = $moduleType;
        }

        // ── PRORATE-1 · THE LIST ANCHOR MUST TRACK THE LIST CHARGE ──────────
        //
        // `listGrossAmount` / `listDiscountAmount` are what proRateFees() scales
        // by the remaining-months factor. They were written ONLY by proRateFees()
        // and never by a generator, and this whitelist silently discarded them —
        // so a generator that RE-PRICED a demand could not refresh the figure its
        // own price would later be prorated from.
        //
        // Conditional, exactly like concessionApplied and chargeInstance above: a
        // caller that supplies nothing gets NO field, so every existing caller
        // still writes a byte-identical document.
        foreach (['listGrossAmount', 'listDiscountAmount'] as $__anchor) {
            if (array_key_exists($__anchor, $data)) {
                $doc[$__anchor] = (float) $pick($data, [$__anchor], 0);
            }
        }
        // ── CHARGE INSTANCE (Fee Head Registry phase, 2026-08-17) ───────────
        //
        // WHICH OCCURRENCE of a fee head this demand is — distinct from the
        // head itself ("what type of charge") and from the demand id ("which
        // exact demand"). Currently supplied only by the ID-card replacement
        // path as 'V{cardVersion}', where cardVersion is the atomically
        // claimed, strictly monotonic, never-reused version minted by
        // Id_card_service::issue().
        //
        // WHY IT IS STORED, not merely encoded in the demand id: recalculation
        // and regeneration must rebuild the identical demand id from CANONICAL
        // STORED DATA. Without this field the only surviving copy of the
        // instance would be inside the id string (or, before this change, the
        // DISPLAY NAME "ID Card Replacement (Card v2)") — and re-deriving
        // identity by parsing a display string is the exact anti-pattern this
        // whole phase exists to remove.
        //
        // CONDITIONAL, exactly like the payment-state fields above: a caller
        // that supplies nothing gets NO field and therefore a byte-identical
        // document. No synthetic value is ever invented — an empty instance is
        // meaningless and would only create a second way to express "none".
        //
        // ⚠ This whitelist is the reason the field exists at all: writeDemand()
        // does NOT merge caller data, it rebuilds $doc from named keys, so any
        // payload key absent from here is SILENTLY DISCARDED — no error, no
        // log. Adding a demand field anywhere else without touching this list
        // does nothing at all.
        if (array_key_exists('chargeInstance', $data)) {
            $instance = trim((string) $pick($data, ['chargeInstance'], ''));
            if ($instance !== '') $doc['chargeInstance'] = $instance;
        }
        // ── CLASSIFICATION PROVENANCE (Fee Head Registry prerequisite) ──────
        //
        // WHICH AUTHORITY decided this demand's `category`. Set to 'registry'
        // by the (not yet wired) Fee Head Registry path; absent on every
        // demand written before that path exists.
        //
        // WHY IT IS NEEDED: classification is a THREE-state problem, not two.
        // Live data holds 1,190 demands with category='' AND 52 pre-registry
        // demands carrying out-of-contract values — capitalised 'Academic' /
        // 'Transport', plus 'General', which is in no vocabulary at all. So
        // "non-empty" does NOT mean "authoritative", and emptiness alone
        // cannot identify the legacy era. Without this marker a later reader
        // would have to GUESS which values it may trust — and the one thing
        // that must never happen is a legacy demand silently becoming
        // 'academic' and thereby scholarship-eligible.
        //
        // CONDITIONAL, exactly like chargeInstance and the payment-state
        // fields: a caller that supplies nothing gets NO field, so every
        // existing caller keeps writing a byte-identical document. Historical
        // demands are never backfilled — absence IS the legacy signal.
        if (array_key_exists('categorySource', $data)) {
            $catSrc = trim((string) $pick($data, ['categorySource'], ''));
            if ($catSrc !== '') $doc['categorySource'] = $catSrc;
        }
        // ── FEE HEAD REGISTRY IDENTITY (M5) ────────────────────────────────
        //
        // The school-scoped identity of the head this demand was raised
        // against — stable across rename, repricing and session rollover,
        // unlike `feeHeadId` (a per-chart-row value) and `canonicalFeeHeadId`
        // (frozen legacy provenance, three distinct values per head in live
        // data).
        //
        // ⚠ CLASSIFICATION IDENTITY ONLY — this must NEVER become demand-id
        // material. `Fee_lifecycle::_demandId()` deliberately excludes it:
        // feeding it into the id would re-identify future Transport/Hostel
        // demands against unchanged historical ones and duplicate live charges.
        //
        // Conditional, like the fields above: absent or empty ⇒ no field, so
        // every flag-off write stays byte-identical.
        if (array_key_exists('feeHeadRef', $data)) {
            $headRef = trim((string) $pick($data, ['feeHeadRef'], ''));
            if ($headRef !== '') $doc['feeHeadRef'] = $headRef;
        }
        // Fees V7: canonical head identity. Caller-provided wins; otherwise resolve
        // from the school-level feeHeads registry by the demand's feeHead NAME
        // (owner-by-name). Original feeHeadId above remains the demand's
        // historical/issued identity (immutable). This guarantees INV-1 for every
        // create path without editing each generator.
        $canonical = (string) $pick($data, ['canonicalFeeHeadId']);
        if ($canonical === '') {
            $canonical = $this->canonicalHeadIdMap()[strtolower(trim((string) $doc['feeHead']))] ?? '';
        }
        if ($canonical !== '') {
            $doc['canonicalFeeHeadId'] = $canonical;
        }

        // ══ P5 · MONEY-COHERENCE INVARIANT — DETECTION ONLY ═════════════════
        //
        //     netAmount == grossAmount − discountAmount
        //
        // Every demand write in the system funnels through this method (the
        // single-write path and the batch path both build their doc here), so
        // this is the one place that can see EVERY demand the system will ever
        // store. That makes it the natural home for the invariant P5 will
        // eventually enforce.
        //
        // WHY THIS EXISTS. Two separate defects were found by hand that this
        // check would have caught automatically the moment they wrote a demand:
        //   • DEC-012 — proRateFees() re-derives netAmount from gross and leaves
        //     discountAmount stale, so a prorated discounted demand stores
        //     gross 2400 / discount 240 / net 1200.
        //   • DEC-013 — legacy recalculation rebuilds an entitlement-priced
        //     demand at gross, dropping the discount entirely.
        // Both were silent. Neither had a detector. This is that detector.
        //
        // MEASURED BEFORE SHIPPING (2026-08-18, all tenants): 1,258 real demands,
        // **ZERO violations**. The only 98 violating documents are synthetic
        // `DEM_TEST_LOAD_*` load-test residue carrying no schoolId, no session
        // and no payments. So this line cannot be noisy today — anything it
        // reports is a genuine regression, which is exactly what makes it worth
        // having.
        //
        // DELIBERATELY LOG-ONLY. Refusing the write would change billing
        // behaviour, and a detector must never be the thing that breaks
        // billing. P5 promotes this to a hard refusal once the pipeline is
        // canonical and every writer is known to satisfy it.
        //
        // fineAmount is NOT part of the identity: a fine is added to the
        // BALANCE, not netted into the charge (see the fine-aware balance rule
        // `balance = netAmount + fine − paidAmount`).
        // The rule itself is a named, pure predicate (below) so it can be tested
        // directly and so P5 can promote it from a log to a refusal in ONE place.
        $incoherence = self::moneyIncoherence($doc);
        if ($incoherence !== null) {
            log_message('error', sprintf(
                'ACC_DEMAND_MONEY_INCOHERENT demand=[%s] student=[%s] school=[%s] head=[%s] '
                . 'period=[%s] gross=%.2f discount=%.2f net=%.2f expectedNet=%.2f delta=%.2f '
                . '— gross-discount != net. See DEC-012/DEC-013.',
                $demandId,
                (string) ($doc['studentId'] ?? '?'),
                (string) ($doc['schoolId'] ?? '?'),
                (string) ($doc['feeHead'] ?? '?'),
                (string) ($doc['periodKey'] ?? '?'),
                $incoherence['gross'], $incoherence['discount'], $incoherence['net'],
                $incoherence['expectedNet'], $incoherence['delta']
            ));
        }

        return $doc;
    }

    /**
     * BUG-045 Phase 2 (2026-05-25): batched demand commit.
     *
     * Collapses N per-demand PATCH RTTs into a single atomic Firestore
     * `:commit` RTT. Used by:
     *   - Fee_lifecycle::assignInitialFees (N=36 typical for monthly+yearly chart)
     *   - Fee_lifecycle::reassignFeesOnPromotion archive loop (K=12 typical)
     *
     * Op shape per entry:
     *   ['op' => 'write',   'demandId' => 'X', 'data' => [...]]  // assign new
     *   ['op' => 'archive', 'demandId' => 'Y', 'data' => [...]]  // partial update
     *
     * Atomicity (per Firestore :commit semantics): all ops succeed
     * together or NONE persist. Caller falls back to per-doc writeDemand /
     * updateDemand loop on failure (preserves existing semantics).
     *
     * Capped at Firestore's 500-ops-per-commit limit at call sites
     * (per-student batches of ~48 ops are well within limit).
     *
     * @return bool true on commit success, false otherwise
     */
    public function commitDemandBatch(array $entries): bool
    {
        if (!$this->ready || empty($entries)) return true;
        $ops = [];
        foreach ($entries as $e) {
            $did = trim((string) ($e['demandId'] ?? ''));
            if ($did === '') continue;
            $op  = (string) ($e['op'] ?? 'write');
            if ($op === 'write') {
                $doc = $this->_buildDemandDoc($did, is_array($e['data'] ?? null) ? $e['data'] : []);
                $ops[] = [
                    'op'         => 'update',
                    'collection' => self::COL_DEMANDS,
                    'docId'      => $did,
                    'data'       => $doc,
                    'merge'      => true,
                ];
            } else {
                // 'archive' or 'update' — partial update; pass $data verbatim.
                $ops[] = [
                    'op'         => 'update',
                    'collection' => self::COL_DEMANDS,
                    'docId'      => $did,
                    'data'       => is_array($e['data'] ?? null) ? $e['data'] : [],
                    'merge'      => true,
                ];
            }
        }
        if (empty($ops)) return true;
        return (bool) $this->firebase->firestoreCommitBatch($ops);
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

    /** @var array|null cached normalized-name -> canonical feeHeadId map */
    private $_canonHeadMap = null;

    /**
     * Fees V7: name(lowercased) -> canonical feeHeadId from the school-level
     * `feeHeads` registry (the owner). Cached per request. Used by
     * _buildDemandDoc (stamp canonicalFeeHeadId) and by generators (canonical
     * duplicate-detection key) so dedup is correct regardless of chart re-point.
     */
    public function canonicalHeadIdMap(): array
    {
        if ($this->_canonHeadMap !== null) return $this->_canonHeadMap;
        $map = [];
        try {
            $rows = $this->firebase->firestoreQuery('feeHeads', [['schoolId', '==', $this->schoolId]]);
            foreach ((array) $rows as $r) {
                $d  = $r['data'] ?? $r;
                $nm = strtolower(trim((string) ($d['name'] ?? '')));
                $id = (string) ($d['feeHeadId'] ?? '');
                if ($nm !== '' && $id !== '') $map[$nm] = $id;
            }
        } catch (\Throwable $_) {}
        return $this->_canonHeadMap = $map;
    }

    /** Fees V7: opaque demand document id (no business meaning encoded). */
    public function newDemandId(): string
    {
        return bin2hex(random_bytes(10));
    }

    /**
     * Phase 2.5 Step 2 — companion to readFeeStructure().
     *
     * Returns a `name → feeHeadId` map for the given class/section so the
     * demand generator can stamp each demand with its stable opaque ID
     * without re-reading the structure. Heads without an ID yet (should
     * only happen mid-migration) are skipped silently — the caller will
     * fall back to the legacy name-slug path in _buildDemandId.
     */
    public function readFeeHeadIds(string $className, string $section): array
    {
        if (!$this->ready) return [];
        try {
            $docId = "{$this->schoolId}_{$this->session}_{$className}_{$section}";
            $doc = $this->firebase->firestoreGet(self::COL_FEE_STRUCT, $docId);
            if (!is_array($doc) || empty($doc['feeHeads'])) return [];
            $out = [];
            foreach ((array) $doc['feeHeads'] as $h) {
                if (!is_array($h)) continue;
                $nm  = trim((string) ($h['name'] ?? ''));
                $fid = trim((string) ($h['feeHeadId'] ?? ''));
                if ($nm !== '' && $fid !== '') $out[$nm] = $fid;
            }
            return $out;
        } catch (\Exception $_) { return []; }
    }

    /**
     * BUG-045 Phase 1 B3 (2026-05-25): merged readFeeStructure + readFeeHeadIds.
     *
     * Pre-fix the two methods above hit the SAME Firestore doc
     * (`feeStructures/{schoolId}_{session}_{className}_{section}`) in two separate
     * firestoreGet calls — a wasted RTT per student during bulk promote where
     * Fee_lifecycle::assignInitialFees calls BOTH back-to-back. This merged
     * method does ONE firestoreGet and projects into both views in a single pass.
     *
     * Backward-compat: readFeeStructure() and readFeeHeadIds() remain unchanged
     * for single-projection callers. New callers (Fee_lifecycle::assignInitialFees
     * via B2 cache wrapper) use this merged method to save 1 RPC/student.
     *
     * @return array ['chart' => [...month maps...], 'headIds' => [name => id, ...]]
     */
    public function readFeeStructureWithIds(string $className, string $section): array
    {
        if (!$this->ready) return ['chart' => [], 'headIds' => [], 'headRefs' => []];
        try {
            $docId = "{$this->schoolId}_{$this->session}_{$className}_{$section}";
            $doc = $this->firebase->firestoreGet(self::COL_FEE_STRUCT, $docId);
            if (!is_array($doc) || empty($doc['feeHeads'])) return ['chart' => [], 'headIds' => [], 'headRefs' => []];

            $months = ['April','May','June','July','August','September',
                       'October','November','December','January','February','March'];
            $chart = [];
            foreach ($months as $m) $chart[$m] = [];
            $chart['Yearly Fees'] = [];
            $headIds  = [];
            $headRefs = [];

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
                $fid = trim((string) ($h['feeHeadId'] ?? ''));
                if ($fid !== '') $headIds[$name] = $fid;
                // M5: carry the school-scoped registry identity through the
                // expansion. Without this the chart collapses to name=>amount
                // and the generator loses the only authoritative binding it
                // has. Additive — existing callers read chart/headIds only.
                $ref = trim((string) ($h['feeHeadRef'] ?? ''));
                if ($ref !== '') $headRefs[$name] = $ref;
            }
            return ['chart' => $chart, 'headIds' => $headIds, 'headRefs' => $headRefs];
        } catch (\Exception $_) { return ['chart' => [], 'headIds' => [], 'headRefs' => []]; }
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
                $sid = (string) ($d['studentId'] ?? $d['userId'] ?? $d['id'] ?? '');
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

    /**
     * Phase 7A — concurrent preload of the three single-doc reads every
     * submit_fees() invocation needs before the first write:
     *    student / feeIdempotency / feeStructures
     *
     * Runs all three in ONE network round-trip (curl_multi). Demands
     * come from a query (multi-doc) so they stay separate — this helper
     * covers only the doc-GET set. feeStructures is optional: pass null
     * for $class / $section on paths where the structure isn't needed.
     *
     * Returns:
     *   [
     *     'student'       => ?array,
     *     'idempotency'   => ?array,
     *     'feeStructure'  => ?array,   // null when class/section not supplied
     *   ]
     */
    public function preloadSubmitContext(
        string $userId,
        string $idempHash,
        ?string $class = null,
        ?string $section = null
    ): array {
        $out = ['student' => null, 'idempotency' => null, 'feeStructure' => null];
        if (!$this->ready || $userId === '') return $out;

        $reqs = [
            'student'     => ['collection' => self::COL_STUDENTS, 'docId' => "{$this->schoolId}_{$userId}"],
            'idempotency' => ['collection' => self::COL_IDEMP,    'docId' => "{$this->schoolId}_{$idempHash}"],
        ];
        if ($class !== null && $section !== null && $class !== '' && $section !== '') {
            $reqs['feeStructure'] = [
                'collection' => 'feeStructures',
                'docId'      => "{$this->schoolId}_{$this->session}_{$class}_{$section}",
            ];
        }
        try {
            $docs = $this->firebase->firestoreGetParallel($reqs);
            $out['student']      = is_array($docs['student']      ?? null) ? $docs['student']      : null;
            $out['idempotency']  = is_array($docs['idempotency']  ?? null) ? $docs['idempotency']  : null;
            $out['feeStructure'] = is_array($docs['feeStructure'] ?? null) ? $docs['feeStructure'] : null;
        } catch (\Throwable $e) {
            if (function_exists('log_message')) log_message('error', 'Fee_firestore_txn::preloadSubmitContext: ' . $e->getMessage());
        }
        return $out;
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

    /**
     * All non-reversed allocation docs for a student in the current session.
     * Used by the R.6 stale-allocation guard — the refund flow needs to
     * detect whether another newer receipt already covers a demand whose
     * allocation it's about to reverse. Filters status != 'reversed' in
     * memory since Firestore can't do negated equality in a composite
     * query without extra indices.
     */
    public function allocationsForStudent(string $studentId): array
    {
        if (!$this->ready || $studentId === '') return [];
        try {
            $rows = $this->fs->schoolWhere(self::COL_RCPT_ALLOC, [
                ['session',   '==', $this->session],
                ['studentId', '==', $studentId],
            ]);
            $out = [];
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? [];
                if (!is_array($d)) continue;
                if (($d['status'] ?? '') === 'reversed') continue;
                // FEE-ENV: the docId lives at the schoolWhere ENVELOPE level
                // (the row id = "{schoolId}_{session}_{receiptKey}"), NOT in the
                // data dict — allocation docs store no id field there. Reading it
                // from the data dict was always empty → every row skipped → []
                // returned → the R.6 stale-allocation guard never fired → a
                // refund could reverse a superseded allocation and corrupt a
                // newer receipt. Read the envelope id, then fall back.
                $id = (string) ($r['id'] ?? $d['id'] ?? '');
                if ($id === '') continue;
                $d['_docId'] = $id;
                $out[] = $d;
            }
            return $out;
        } catch (\Exception $e) {
            log_message('error', "Fee_firestore_txn::allocationsForStudent({$studentId}) failed: " . $e->getMessage());
            return [];
        }
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

            // ── ADJ-GOV-3 · ATTRIBUTION MUST SURVIVE THE WRITE BOUNDARY ─────
            //
            // This method used to build a FIXED document, so every field its
            // callers passed for provenance — `reason`, `appliedBy`,
            // `appliedAt`, `source` — was silently discarded, and the write is
            // a full overwrite, so anything previously stored was erased too.
            //
            // That is the root cause of SD-1 in STUDENT_DISCOUNTS_FORENSIC_AUDIT:
            // a live school carries a permanent, unattributed reduction on a
            // child's fees, and the audit concluded its provenance was
            // "unknown and unknowable from code". It was unknowable because the
            // writer threw it away. A gate that makes a reason MANDATORY is
            // worthless if the storage layer drops it one call later.
            //
            // `validUntil` matters just as much: it is the ONLY temporal
            // control on a discount that is otherwise permanent, and both the
            // counter (`fetch_months`) and the server-side receipt cap in
            // FeeCollectionService read it. Dropping it silently converted an
            // expiring waiver into a forever one.
            //
            // Existing values are preserved when a caller does not supply one,
            // so no stored attribution is lost by an unrelated update.
            foreach (['reason', 'appliedBy', 'appliedAt', 'source',
                      'validUntil', 'valid_until'] as $keep) {
                $v = $summary[$keep] ?? $existing[$keep] ?? null;
                if ($v !== null) $doc[$keep] = is_string($v) ? $v : (string) $v;
            }

            // ADJ-GOV-8 — the dual-emitted expiry pair must agree. Each writer
            // supplies only one of the two keys, so the preserve loop can pair
            // a caller's camel value with a STALE stored snake value. Every
            // reader is camel-first, so that key is authoritative and the snake
            // key is a compatibility echo of it. A record that contradicts
            // itself sets up the dangerous reading: "" = never expires.
            if (array_key_exists('validUntil', $doc) || array_key_exists('valid_until', $doc)) {
                $__vu = (string) ($doc['validUntil'] ?? '');
                if ($__vu === '') $__vu = (string) ($doc['valid_until'] ?? '');
                $doc['validUntil']  = $__vu;
                $doc['valid_until'] = $__vu;
            }
            return (bool) $this->firebase->firestoreSet(self::COL_DISCOUNTS, "{$this->schoolId}_{$userId}", $doc);
        } catch (\Exception $_) { return false; }
    }
}
