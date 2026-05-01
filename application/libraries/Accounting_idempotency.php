<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Accounting_idempotency — Phase 8A dedup gate for journal posting.
 *
 * Collection:  accountingIdempotency
 * Doc ID:      {schoolId}_{idempKey}
 * Schema:      {
 *                 schoolId, idempKey, source,
 *                 status:     "processing" | "success" | "failed",
 *                 entryId:    string,        // set when status=success
 *                 startedAt:  ISO-8601,
 *                 completedAt:ISO-8601,
 *                 attempts:   int,
 *                 lastError:  string
 *              }
 *
 * Contract (mirrors Fee_firestore_txn idempotency methods):
 *   claim(idempKey, source)  → returns one of:
 *       {first_claim: true}                     — we own the slot, proceed
 *       {dedup: true, entryId: "..."}           — already succeeded, caller returns entryId
 *       {in_progress: true, ageSec: N}          — another request is in-flight; caller 409s
 *       {stale_override: true}                  — claim was stale, we overwrote, proceed
 *   markSuccess(idempKey, entryId)
 *   markFailed(idempKey, error)
 *
 * Key derivation (caller's responsibility):
 *   Fee journal:    "JE_FEE_{receiptKey}"        — deterministic
 *   Refund journal: "JE_REFUND_{refundId}"       — deterministic
 *   Manual journal: "JE_MAN_" . md5(sorted_payload)
 *
 * Callers MUST use the same idempKey on retry, or the gate cannot dedup.
 */
final class Accounting_idempotency
{
    private const COL           = 'accountingIdempotency';
    private const STALE_SEC     = 120;   // >=120s old = safe to overwrite
    private const MAX_KEY_LEN   = 200;

    /** @var object|null $firebase  injected */
    private $firebase = null;
    private string $schoolId = '';
    private string $session  = '';
    private bool   $ready    = false;

    public function init($firebase, string $schoolId, string $session): void
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ready    = ($firebase !== null && $schoolId !== '' && $session !== '');
    }

    /**
     * Attempt to claim the idempotency slot for $idempKey.
     *
     * Returns an associative array describing the outcome. Callers read
     * the result keys directly — see contract above.
     */
    public function claim(string $idempKey, string $source = ''): array
    {
        if (!$this->ready || $idempKey === '') {
            return ['error' => 'idempotency library not initialised'];
        }
        if (strlen($idempKey) > self::MAX_KEY_LEN) {
            return ['error' => 'idempKey exceeds max length'];
        }
        $docId = $this->_docId($idempKey);
        $now   = date('c');
        $slot  = [
            'schoolId'  => $this->schoolId,
            'session'   => $this->session,
            'idempKey'  => $idempKey,
            'source'    => $source,
            'status'    => 'processing',
            'startedAt' => $now,
            'updatedAt' => $now,
            'attempts'  => 1,
        ];

        // Atomic create-if-not-exists. Exactly ONE concurrent caller
        // wins this write; the rest get 409 → firestoreCreate returns
        // false, and we fall through to the "read existing" branch.
        if ($this->firebase->firestoreCreate(self::COL, $docId, $slot)) {
            log_message('error', "ACC_IDEMP_CLAIMED key={$idempKey} source={$source}");
            return ['first_claim' => true];
        }

        // Someone already has the slot. Read its state and decide.
        $existing = $this->firebase->firestoreGet(self::COL, $docId);
        if (!is_array($existing)) {
            // Rare: create failed but read also failed. Treat as retry-
            // able in-flight rather than blindly overwriting.
            return ['in_progress' => true, 'ageSec' => 0];
        }
        $status = (string) ($existing['status'] ?? '');

        if ($status === 'success') {
            $entryId = (string) ($existing['entryId'] ?? '');
            log_message('error', "ACC_IDEMP_HIT key={$idempKey} entryId={$entryId}");
            return ['dedup' => true, 'entryId' => $entryId];
        }

        $ageSec = max(0, time() - strtotime((string) ($existing['startedAt'] ?? '2000-01-01')));

        if ($status === 'processing' && $ageSec < self::STALE_SEC) {
            return ['in_progress' => true, 'ageSec' => $ageSec];
        }

        // Stale (>= 120s) or failed → overwrite with a fresh claim.
        // attempts is incremented so operators can see chronic retries.
        $slot['attempts'] = ((int) ($existing['attempts'] ?? 0)) + 1;
        $this->firebase->firestoreSet(self::COL, $docId, $slot, /* merge */ false);
        log_message('error', "ACC_IDEMP_STALE_OVERRIDE key={$idempKey} priorStatus={$status} ageSec={$ageSec}");
        return ['stale_override' => true, 'attempts' => $slot['attempts']];
    }

    /**
     * Mark the slot as successfully completed. Stores the entryId so
     * future retries dedup to the same ledger row.
     */
    public function markSuccess(string $idempKey, string $entryId): bool
    {
        if (!$this->ready || $idempKey === '' || $entryId === '') return false;
        $docId = $this->_docId($idempKey);
        $now   = date('c');
        return (bool) $this->firebase->firestoreSet(self::COL, $docId, [
            'status'      => 'success',
            'entryId'     => $entryId,
            'completedAt' => $now,
            'updatedAt'   => $now,
        ], /* merge */ true);
    }

    /**
     * Mark the slot as failed. Retries AFTER the stale window can proceed.
     * Operator alert fires off the dashboard's failed count.
     */
    public function markFailed(string $idempKey, string $error): bool
    {
        if (!$this->ready || $idempKey === '') return false;
        $docId = $this->_docId($idempKey);
        $now   = date('c');
        return (bool) $this->firebase->firestoreSet(self::COL, $docId, [
            'status'      => 'failed',
            'lastError'   => substr($error, 0, 500),
            'completedAt' => $now,
            'updatedAt'   => $now,
        ], /* merge */ true);
    }

    /**
     * Admin helper: read the current slot. Used by the reconciliation
     * job to find orphaned 'processing' slots (status=processing AND
     * startedAt > 5 min ago) and decide recovery path.
     */
    public function read(string $idempKey): ?array
    {
        if (!$this->ready || $idempKey === '') return null;
        $doc = $this->firebase->firestoreGet(self::COL, $this->_docId($idempKey));
        return is_array($doc) ? $doc : null;
    }

    private function _docId(string $idempKey): string
    {
        return "{$this->schoolId}_{$idempKey}";
    }
}
