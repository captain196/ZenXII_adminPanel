<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  B-025 — EXCLUSIVE PAYMENT CLAIM WITH A LEASE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Replaces the read-then-blind-set guard at the head of
 * Fee_management::_verify_and_process() — the shared core of BOTH the parent
 * frontend callback AND the gateway webhook.
 *
 * ── THE DEFECT ────────────────────────────────────────────────────────────
 * The previous guard read the lock, judged its age, then unconditionally SET
 * it. Two callers that both read before either wrote both proceeded into the
 * money pipeline. Measured on the emulator
 * (firebase-rules/tests/payment_claim_primitive.test.js):
 *
 *     12 concurrent `set`    → 12 winners      ← the old guard
 *     12 concurrent `create` →  1 winner       ← this class
 *     40 concurrent `create` →  1 winner
 *
 * Razorpay fires the webhook WHILE the client callback runs, so the race is
 * the normal case, not an edge case.
 *
 * ── THE PROPERTY THAT MUST NOT BE LOST ────────────────────────────────────
 * The old guard SELF-HEALS: it re-judged staleness on every read, so a crashed
 * process blocked the payment for at most 120s. A naive atomic create does NOT
 * self-heal — once created it is owned forever. Trading a double-charge risk
 * for a permanently wedged payment is not an improvement.
 *
 * So the claim carries a LEASE, and an expired lease is taken over by a
 * COMPARE-AND-SWAP on the document's server updateTime — never by a second
 * blind write, which would reintroduce the very defect being fixed. Two
 * processes that both observe an expired lease cannot both take it over: one
 * CAS wins, the other aborts against fresh state.
 *
 * ── WHY THERE IS NO TERMINAL 'done' STATE ─────────────────────────────────
 * An earlier draft retained the claim as `state:'done'` carrying the receipt,
 * so a late webhook could be answered idempotently from the claim itself.
 * That draft was WRONG, and the reason is the key:
 *
 *   The claim is keyed by gateway ORDER id, not payment id (preserving the
 *   previous guard's exclusion scope exactly). An order can carry more than
 *   one payment. A retained `done` claim would answer a SECOND, GENUINE
 *   payment on that order with the FIRST payment's receipt — money received,
 *   nothing applied, and a receipt returned that does not describe it.
 *
 * Per-payment idempotency is NOT this class's job. It belongs to
 * `processedPayments/{gatewayPaymentId}`, which is keyed correctly. This class
 * does exactly one thing: mutual exclusion with recovery. It is deleted on
 * release, exactly as the previous guard was.
 *
 * ── ONE CLIENT, ONE DOCUMENT ID (learned the hard way) ────────────────────
 * The claim doc is `feeLocks/{schoolId}_{orderId}` and EVERY operation here
 * addresses it through the same unscoped client.
 *
 * An earlier revision did the takeover through the SCOPED Firestore_service,
 * which prepends the school id itself — so it had to be given the BARE order
 * id while the create was given the FULL one. Two addressing conventions for
 * one document is a bug waiting to happen, and it was worse than that: the
 * webhook path never loads `fs` at all (Fee_management::__construct() calls
 * CI_Controller::__construct() for webhooks, bypassing MY_Controller), so
 * recovery there could never succeed. A crashed frontend attempt would have
 * blocked webhook processing for that order permanently.
 *
 * ── WHAT THIS CLASS DOES NOT CLOSE ────────────────────────────────────────
 * A crash between the receipt write and release. The lease expires, a retry
 * takes over, and the pipeline re-runs against a receipt that already exists.
 * What prevents a duplicate there is FeeCollectionService's own idempotency
 * record and receipt reservation — NOT this class. Exclusion and per-payment
 * idempotency are jointly responsible for "one payment, one receipt", and
 * that combination has never been exercised against a real payment: the
 * estate holds zero receipts.
 */
class Fee_payment_claim
{
    /** Seconds a claim stays exclusive. Deliberately the previous guard's window. */
    const LEASE_SECONDS = 120;

    const COLLECTION = 'feeLocks';

    /** acquire() outcomes. */
    const ACQUIRED    = 'ACQUIRED';     // we own it — proceed
    const IN_PROGRESS = 'IN_PROGRESS';  // someone else owns a live lease — refuse
    const UNKNOWN     = 'UNKNOWN';      // outcome undetermined — refuse, retryable

    /**
     * ONE collaborator, deliberately. Everything this class does — the atomic
     * create, the fresh read, the CAS takeover, the release — goes through the
     * unscoped client, addressing exactly one document id. An earlier revision
     * also took the SCOPED Firestore_service for the takeover; that is what
     * created the double-prefix hazard and broke recovery on the webhook path,
     * where `fs` is never loaded at all. See _takeover().
     */
    private $firebase = null;   // UNSCOPED — full doc ids
    private string $schoolId = '';

    public function init($firebase, string $schoolId): void
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
    }

    /** The full document id, as the UNSCOPED client addresses it. */
    public function docId(string $orderId): string
    {
        return "{$this->schoolId}_{$orderId}";
    }

    /**
     * Attempt to become the exclusive owner of this order's payment processing.
     *
     * @return array{status:string, token:string, reason:string}
     *         status ∈ ACQUIRED | IN_PROGRESS | UNKNOWN
     */
    public function acquire(string $orderId, string $source): array
    {
        if ($this->firebase === null) {
            return $this->_out(self::UNKNOWN, '', 'claim service not initialised');
        }

        $token  = bin2hex(random_bytes(16));
        $docId  = $this->docId($orderId);
        $now    = time();

        $claim = [
            'schoolId'   => $this->schoolId,
            'orderId'    => $orderId,
            'locked'     => true,
            'locked_at'  => date('c', $now),
            'expires_at' => date('c', $now + self::LEASE_SECONDS),
            'token'      => $token,
            'source'     => $source,
        ];

        $res = $this->firebase->firestoreCreateStatus(self::COLLECTION, $docId, $claim);
        $status = (string) ($res['status'] ?? 'FAILURE');

        // Exclusive create succeeded — nobody else can be inside.
        if ($status === 'SUCCESS') {
            return $this->_out(self::ACQUIRED, $token, 'created');
        }

        // NOT a collision. The write may or may not have landed, so ownership is
        // genuinely undetermined. Treating this as "someone else has it" would
        // skip a payment nobody owns; treating it as "mine" would double-apply.
        // Refuse, and let the caller retry — the next attempt resolves it through
        // the COLLISION branch below.
        if ($status !== 'COLLISION') {
            log_message('error',
                'Fee_payment_claim: acquire UNKNOWN for order [' . $orderId . '] — '
                . (string) ($res['message'] ?? '') . ' — refusing, nothing applied');
            return $this->_out(self::UNKNOWN, '', 'create failed: ' . (string) ($res['message'] ?? ''));
        }

        // ── COLLISION — a claim already exists. Is its lease still alive? ──
        $existing = $this->firebase->firestoreGet(self::COLLECTION, $docId);
        if (!is_array($existing)) {
            // It existed a moment ago and cannot be read now. Undetermined.
            return $this->_out(self::UNKNOWN, '', 'claim exists but is unreadable');
        }

        if (!$this->_isExpired($existing, $now)) {
            return $this->_out(self::IN_PROGRESS, '', 'live lease held by another process');
        }

        return $this->_takeover($orderId, $token, $source, $existing);
    }

    /**
     * Take over an EXPIRED claim by compare-and-swap.
     *
     * The precondition is the document version observed by the SAME read that
     * judged the lease expired. If another process took it over in between, the
     * document has moved, the precondition fails, and this attempt is refused
     * rather than stealing a live owner's exclusivity. No unguarded write is
     * ever performed.
     */
    private function _takeover(string $orderId, string $token, string $source, array $observed): array
    {
        if (!method_exists($this->firebase, 'firestoreCommitBatchStatus')) {
            return $this->_out(self::UNKNOWN, '', 'CAS primitive unavailable — cannot recover lease');
        }

        // ── WHY THE CAS GOES THROUGH THE UNSCOPED CLIENT ──────────────────
        // An earlier revision recovered the lease via Firestore_service::
        // casUpdateEntity(). That was WRONG on the very path that needs
        // recovery most. Fee_management::__construct() detects a webhook and
        // calls CI_Controller::__construct() directly — deliberately, because a
        // gateway webhook carries no session — so MY_Controller never runs and
        // `fs` is never loaded AT ALL. Lease recovery would have returned
        // UNKNOWN forever, and a crashed frontend attempt would then have
        // PERMANENTLY blocked webhook processing for that order: strictly worse
        // than the blind SET this class replaces, which at least proceeded.
        //
        // Doing the compare-and-swap through the same unscoped client that
        // created the claim removes that collaborator entirely, and with it the
        // scoped/unscoped document-id hazard — there is now exactly ONE way this
        // class addresses its document.
        $docId      = $this->docId($orderId);
        $priorToken = (string) ($observed['token'] ?? '');

        // The CAS version MUST come from the same read that judged expiry.
        // That pairing is what makes "it was expired" and "take it" atomic.
        $updateTime = (string) ($observed['__updateTime'] ?? '');
        if ($updateTime === '') {
            // An existing document always carries a server updateTime. Without
            // one the CAS cannot be guaranteed, and a merge with no precondition
            // would clobber a concurrent takeover — the exact defect being
            // fixed. Fail closed; never write unguarded.
            log_message('error',
                'Fee_payment_claim: no __updateTime on claim for order [' . $orderId
                . '] — refusing unguarded lease takeover');
            return $this->_out(self::UNKNOWN, '', 'claim carries no version — cannot CAS');
        }

        $res = $this->firebase->firestoreCommitBatchStatus([[
            'op'         => 'update',
            'collection' => self::COLLECTION,
            'docId'      => $docId,
            'merge'      => true,
            'data'       => [
                'locked'          => true,
                'locked_at'       => date('c'),
                'expires_at'      => date('c', time() + self::LEASE_SECONDS),
                'token'           => $token,
                'source'          => $source,
                'taken_over_from' => $priorToken,
            ],
            'precondition' => ['updateTime' => $updateTime],
        ]]);

        $status = (string) ($res['status'] ?? 'FAILURE');

        if ($status === 'SUCCESS') {
            log_message('info',
                'Fee_payment_claim: recovered expired lease on order [' . $orderId
                . '] from token [' . $priorToken . ']');
            return $this->_out(self::ACQUIRED, $token, 'lease taken over');
        }

        // The document moved between our read and our write: another process
        // recovered it first. Exactly one takeover can win.
        if ($status === 'PRECONDITION_CONFLICT') {
            return $this->_out(self::IN_PROGRESS, '', 'another process recovered the lease first');
        }

        return $this->_out(self::UNKNOWN, '', 'lease takeover failed: '
            . (string) ($res['message'] ?? $status));
    }
    /**
     * Release the claim — ONLY if we still own it.
     *
     * A token mismatch means our lease expired and another process legitimately
     * took over; deleting then would strip a live owner's exclusivity and let a
     * third process in alongside it. Best effort: a failed release simply leaves
     * the claim to expire.
     */
    public function release(string $orderId, string $token): bool
    {
        if ($this->firebase === null || $token === '') return false;
        $docId = $this->docId($orderId);
        try {
            $current = $this->firebase->firestoreGet(self::COLLECTION, $docId);
            if (is_array($current) && (string) ($current['token'] ?? '') === $token) {
                $this->firebase->firestoreDelete(self::COLLECTION, $docId);
                return true;
            }
        } catch (\Exception $e) {
            log_message('error', 'Fee_payment_claim: release failed for order ['
                . $orderId . '] — ' . $e->getMessage());
        }
        return false;
    }

    /**
     * A claim is expired when its lease has elapsed.
     *
     * Falls back to `locked_at` + LEASE for claims written by the previous guard,
     * which carried no `expires_at` — so a rollback or an in-flight deploy cannot
     * produce a claim this class considers immortal.
     *
     * An unparseable or absent timestamp is treated as EXPIRED, matching the
     * previous guard exactly (it compared against '2000-01-01'). That is the
     * safe direction here: exclusivity is still enforced by the CAS takeover,
     * whereas treating it as live would wedge the order permanently.
     */
    private function _isExpired(array $claim, int $now): bool
    {
        if (empty($claim['locked'])) return true;

        $expiresAt = (string) ($claim['expires_at'] ?? '');
        if ($expiresAt !== '') {
            $ts = strtotime($expiresAt);
            return ($ts === false) ? true : ($now >= $ts);
        }

        $lockedAt = (string) ($claim['locked_at'] ?? '');
        $ts = $lockedAt !== '' ? strtotime($lockedAt) : false;
        if ($ts === false) return true;
        return ($now - $ts) >= self::LEASE_SECONDS;
    }

    private function _out(string $status, string $token, string $reason): array
    {
        return ['status' => $status, 'token' => $token, 'reason' => $reason];
    }
}
