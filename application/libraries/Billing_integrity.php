<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Wave B2.2-F F2 — Billing_integrity service.
 *
 * Firestore-native claim/lock primitive that arbitrates "this billing event
 * happens at most once" (payment idempotency) and "at most one open invoice
 * per school" (invoice locking). Built ONCE here, consulted claim-first by
 * the legacy RTDB billing path during the B2.3α bridge, and reused VERBATIM
 * by the Firestore-authoritative writer at cutover — single arbiter across
 * both eras.
 *
 * NO RTDB. NO call sites in B2.2-F (Batch 2). The call-site wiring is B2.3α,
 * downstream of this build. This file is the primitive only; the deterministic
 * idempotency key is minted at the call-site (per the locked §8.1 decision).
 *
 * Public API:
 *   init($firebase, $actor = [], $collection = '')
 *   beginPayment($idempKey, $ctx = [])    ->  outcome map (claimed|dedup|in_progress|reclaimed|error)
 *   completePayment($idempKey, $result)   ->  bool   (terminal SUCCESS; permanent dedup ledger)
 *   failPayment($idempKey, $reason)       ->  bool   (FAILED; reclaimable next attempt)
 *   claimOpenInvoice($schoolId, $periodStart, $invoiceId)
 *                                          ->  outcome map (locked|conflict|error)
 *   releaseOpenInvoice($schoolId, $expectedInvoiceId)  ->  bool   (CAS on invoiceId)
 *   invoiceDocId($schoolId, $periodStart)  ->  string (deterministic; the invoice doc
 *                                                     itself acts as the per-period claim
 *                                                     via createDocument exists:false)
 *
 * Backing primitives (all verified present in Firebase.php):
 *   firestoreCreate       — line 919, create-if-absent (409 = exists)
 *   firestoreCommitBatch  — line 933, atomic + currentDocument exists:false / updateTime CAS
 *   firestoreGet          — line 838, surfaces __updateTime for CAS reclaim
 *   firestoreUpdate       — line 949, merge PATCH for state-flip updates
 *   firestoreDelete       — line 955
 *
 * Fail-closed: every Firestore-unreachable path returns an explicit error /
 * false outcome; the caller MUST abort the billing op (per the §8.3 lock).
 * The service NEVER bypasses the claim on Firestore failure.
 *
 * Success-claim retention: completePayment stamps `expireAt` 400 days out
 * (§8.2 lock) for compliance/dispute evidence; a Firestore TTL policy on
 * `expireAt` performs eventual GC.
 *
 * @schema-locked  2026-05-30 (F2)
 */
class Billing_integrity
{
    const COLLECTION_DEFAULT  = 'billingClaims';
    const KIND_PAYMENT        = 'payment';
    const KIND_OPEN_INVOICE   = 'openInvoice';

    const STATUS_PROCESSING   = 'processing';
    const STATUS_SUCCESS      = 'success';
    const STATUS_FAILED       = 'failed';
    const STATUS_OPEN         = 'open';

    const STALE_SEC           = 120;
    const RETENTION_DAYS      = 400;
    const PAYMENT_PREFIX      = 'pay_';
    const OPEN_INVOICE_PREFIX = 'openInv_';

    /** @var object|null */ private $firebase = null;
    /** @var array */       private $actor    = ['uid' => '', 'role' => ''];
    /** @var string */      private $collection = self::COLLECTION_DEFAULT;
    /** @var bool */        private $ready    = false;

    /**
     * Bind dependencies. Idempotent.
     * @param string $collection  Override of 'billingClaims' — verifier-only
     *                            (probes use a throwaway namespace).
     */
    public function init($firebase, array $actor = [], string $collection = ''): self
    {
        $this->firebase   = $firebase;
        $this->actor      = [
            'uid'  => (string) ($actor['uid']  ?? ''),
            'role' => (string) ($actor['role'] ?? ''),
        ];
        $this->collection = $collection !== '' ? $collection : self::COLLECTION_DEFAULT;
        $this->ready      = ($firebase !== null);
        return $this;
    }

    public function isReady(): bool   { return $this->ready; }
    public function collection(): string { return $this->collection; }

    // ─── PAYMENT IDEMPOTENCY ──────────────────────────────────────────

    public function beginPayment(string $idempKey, array $ctx = []): array
    {
        if (!$this->ready)        return ['error' => true, 'reason' => 'not_ready'];
        if ($idempKey === '')     return ['error' => true, 'reason' => 'empty_key'];

        $docId = self::PAYMENT_PREFIX . $idempKey;
        $now   = time();
        $nowIso = date('c', $now);
        $slot  = [
            'claimId'         => $docId,
            'kind'            => self::KIND_PAYMENT,
            'status'          => self::STATUS_PROCESSING,
            'idempotencyKey'  => $idempKey,
            'schoolId'        => (string) ($ctx['schoolId']  ?? ''),
            'invoiceId'       => (string) ($ctx['invoiceId'] ?? ''),
            'amount'          => isset($ctx['amount']) ? (float) $ctx['amount'] : 0.0,
            'actor'           => $this->actor,
            'attempts'        => 1,
            'startedAt'       => $nowIso,
            'createdAt'       => $nowIso,
            'updatedAt'       => $nowIso,
        ];

        // First-claim attempt: create-if-absent. 409 ⇒ slot already exists.
        try {
            $ok = (bool) $this->firebase->firestoreCreate($this->collection, $docId, $slot);
        } catch (\Throwable $e) {
            return ['error' => true, 'reason' => 'firestore_unreachable'];
        }
        if ($ok) return ['claimed' => true, 'docId' => $docId];

        // Slot exists — read + decide.
        try {
            $existing = $this->firebase->firestoreGet($this->collection, $docId);
        } catch (\Throwable $e) {
            return ['error' => true, 'reason' => 'firestore_unreachable'];
        }
        if (!is_array($existing) || empty($existing)) {
            return ['error' => true, 'reason' => 'claim_vanished'];
        }

        $status = (string) ($existing['status'] ?? '');
        if ($status === self::STATUS_SUCCESS) {
            return ['dedup' => true, 'result' => $existing['result'] ?? null];
        }

        $startedTs = isset($existing['startedAt']) ? strtotime((string) $existing['startedAt']) : 0;
        $ageSec    = max(0, $now - $startedTs);
        if ($status === self::STATUS_PROCESSING && $ageSec < self::STALE_SEC) {
            return ['in_progress' => true, 'ageSec' => $ageSec];
        }

        // Stale processing OR failed → CAS reclaim on __updateTime.
        $ut = (string) ($existing['__updateTime'] ?? '');
        if ($ut === '') return ['error' => true, 'reason' => 'no_update_time'];

        $slot['attempts']  = ((int) ($existing['attempts'] ?? 0)) + 1;
        $slot['createdAt'] = (string) ($existing['createdAt'] ?? $nowIso);
        try {
            $casOk = (bool) $this->firebase->firestoreCommitBatch([[
                'op'           => 'set',
                'collection'   => $this->collection,
                'docId'        => $docId,
                'data'         => $slot,
                'precondition' => ['updateTime' => $ut],
            ]]);
        } catch (\Throwable $e) {
            return ['error' => true, 'reason' => 'firestore_unreachable'];
        }
        if (!$casOk) {
            return ['in_progress' => true, 'ageSec' => 0, 'reclaim_race' => true];
        }
        return ['reclaimed' => true, 'attempts' => $slot['attempts']];
    }

    public function completePayment(string $idempKey, array $result): bool
    {
        if (!$this->ready || $idempKey === '') return false;
        $docId    = self::PAYMENT_PREFIX . $idempKey;
        $now      = date('c');
        $expireAt = date('c', strtotime('+' . self::RETENTION_DAYS . ' days'));
        try {
            return (bool) $this->firebase->firestoreUpdate($this->collection, $docId, [
                'status'      => self::STATUS_SUCCESS,
                'result'      => $result,
                'completedAt' => $now,
                'updatedAt'   => $now,
                'expireAt'    => $expireAt,
            ]);
        } catch (\Throwable $e) { return false; }
    }

    public function failPayment(string $idempKey, string $reason): bool
    {
        if (!$this->ready || $idempKey === '') return false;
        $docId = self::PAYMENT_PREFIX . $idempKey;
        $now   = date('c');
        try {
            return (bool) $this->firebase->firestoreUpdate($this->collection, $docId, [
                'status'       => self::STATUS_FAILED,
                'failedReason' => $reason,
                'updatedAt'    => $now,
            ]);
        } catch (\Throwable $e) { return false; }
    }

    // ─── INVOICE LOCKING ──────────────────────────────────────────────

    public function invoiceDocId(string $schoolId, string $periodStart): string
    {
        $ps = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $periodStart);
        return 'INV_' . $schoolId . '_' . $ps;
    }

    public function claimOpenInvoice(string $schoolId, string $periodStart, string $invoiceId): array
    {
        if (!$this->ready || $schoolId === '' || $invoiceId === '') {
            return ['error' => true, 'reason' => 'bad_args'];
        }
        $docId = self::OPEN_INVOICE_PREFIX . $schoolId;
        $now   = date('c');
        $payload = [
            'claimId'     => $docId,
            'kind'        => self::KIND_OPEN_INVOICE,
            'status'      => self::STATUS_OPEN,
            'schoolId'    => $schoolId,
            'periodStart' => $periodStart,
            'invoiceId'   => $invoiceId,
            'actor'       => $this->actor,
            'openedAt'    => $now,
            'createdAt'   => $now,
            'updatedAt'   => $now,
        ];
        try {
            $ok = (bool) $this->firebase->firestoreCreate($this->collection, $docId, $payload);
        } catch (\Throwable $e) {
            return ['error' => true, 'reason' => 'firestore_unreachable'];
        }
        if ($ok) return ['locked' => true];

        try {
            $existing = $this->firebase->firestoreGet($this->collection, $docId);
        } catch (\Throwable $e) {
            return ['error' => true, 'reason' => 'firestore_unreachable'];
        }
        return [
            'conflict'          => true,
            'existingInvoiceId' => $existing['invoiceId']   ?? null,
            'existingPeriod'    => $existing['periodStart'] ?? null,
        ];
    }

    public function releaseOpenInvoice(string $schoolId, string $expectedInvoiceId): bool
    {
        if (!$this->ready || $schoolId === '' || $expectedInvoiceId === '') return false;
        $docId = self::OPEN_INVOICE_PREFIX . $schoolId;
        try {
            $existing = $this->firebase->firestoreGet($this->collection, $docId);
        } catch (\Throwable $e) { return false; }
        if (!is_array($existing) || empty($existing)) return true; // already released ⇒ idempotent OK
        if ((string) ($existing['invoiceId'] ?? '') !== $expectedInvoiceId) return false;
        $ut = (string) ($existing['__updateTime'] ?? '');
        if ($ut === '') return false;
        try {
            return (bool) $this->firebase->firestoreCommitBatch([[
                'op'           => 'delete',
                'collection'   => $this->collection,
                'docId'        => $docId,
                'precondition' => ['updateTime' => $ut],
            ]]);
        } catch (\Throwable $e) { return false; }
    }
}
