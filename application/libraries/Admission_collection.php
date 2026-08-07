<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admission_collection — the SINGLE admission receipt + accounting path.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  PURPOSE
 * ──────────────────────────────────────────────────────────────────────
 *  There is exactly ONE admission receipt-generation path and ONE accounting-
 *  posting path, shared by both the online (Admission_public webhook/callback)
 *  and offline (Sis admission counter) workflows. No duplicated business logic.
 *
 *  Reuse-first — this library owns NO engine; it orchestrates the certified ones:
 *    • Receipt numbering → the fee `feeCounters` mechanism, but on a DEDICATED
 *      key `{schoolId}_admissionReceiptNo` and the `ADM######` series (never
 *      collides with the fee `F######` series or the fee counter-repair tool).
 *    • Receipt store     → the dedicated `admissionReceipts` collection
 *      (domain-isolated; does NOT contaminate the certified Fees reports).
 *    • Accounting        → the canonical `Operations_accounting::create_fee_journal`
 *      (deterministic `JE_FEE_{receiptKey}`, period-lock, CAS). The single
 *      admission allocation credits the CONFIGURABLE income account resolved
 *      from the fee head (Admission/Registration/Prospectus → 4020 etc.,
 *      admin-mappable); the debit is cash/bank per payment mode. Tagged
 *      `collection_type='admission'` so it stays distinguishable from student
 *      fee collections — WITHOUT a second accounting system.
 *    • Timeline / Audit  → `crmApplications.timeline[]` + `auditLogs` (reused).
 *
 *  Tenant isolation: every write is tenant-scoped — the counter key and journal
 *  init carry `schoolId`; `fs->setEntity` auto-prefixes the receipt doc and
 *  stamps `schoolId`. School A can never write School B's receipts/ledger.
 */
class Admission_collection
{
    /** @var object */ private $firebase;
    /** @var object */ private $fs;
    /** @var object */ private $CI;
    private string $schoolId = '';
    private string $session  = '';

    public function __construct()
    {
        $this->CI = &get_instance();
    }

    /**
     * @param object $firebase  Firebase library (raw Firestore + Storage)
     * @param object $fs        firestore_service (tenant-scoped helper)
     * @param string $schoolId  Tenant id (SCH_...)
     * @param string $session   Academic session (e.g. 2026-27)
     */
    public function init($firebase, $fs, string $schoolId, string $session): self
    {
        $this->firebase = $firebase;
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        return $this;
    }

    /**
     * Record an admission payment: mint the ADM receipt, post the accounting
     * journal, append the timeline, write the audit. ONE path for online + offline.
     *
     * Idempotent by contract: the caller must not re-invoke once a receipt exists
     * (online guards via admissionPayments.receipt_no; offline via payment_status).
     *
     * @param array $p {
     *   app_id, amount, currency?, student_name?, father_name?, applied_class?,
     *   fee_head?, fee_label?,
     *   payment_mode  (ACCOUNTING mode: 'cash'|'online'|'upi'|'cheque'|'bank_transfer'|'dd'|'pos'),
     *   mode_label?   (display, e.g. 'Cash', 'UPI', 'Cheque', 'DD', 'Bank Transfer', 'POS/Card', 'Online'),
     *   bank_code?    (bank ledger for non-cash; '' for cash / online auto-resolve),
     *   collected_by?, source ('callback'|'webhook'|'offline'),
     *   admission_ref?, payment_id?, gateway_payment_id?, bank_reference?, remarks?
     * }
     * @return array|null  { receipt_no, receipt_type, amount } or null on no-op (amount<=0)
     */
    public function record_payment(array $p): ?array
    {
        $amount = round((float) ($p['amount'] ?? 0), 2);
        if ($amount <= 0) return null;

        $appId        = (string) ($p['app_id'] ?? '');
        $currency     = (string) ($p['currency'] ?? 'INR');
        $studentName  = (string) ($p['student_name'] ?? '');
        $fatherName   = (string) ($p['father_name'] ?? '');
        $appliedClass = (string) ($p['applied_class'] ?? '');
        $feeHead      = (string) ($p['fee_head'] ?? 'Admission Fee');
        $feeLabel     = (string) ($p['fee_label'] ?? $feeHead);
        $mode         = strtolower(trim((string) ($p['payment_mode'] ?? 'cash')));
        $modeLabel    = (string) ($p['mode_label'] ?? ucfirst($mode));
        $bankCode     = (string) ($p['bank_code'] ?? '');
        $collectedBy  = (string) ($p['collected_by'] ?? '');
        $collectedBy  = $collectedBy !== '' ? $collectedBy : 'SYSTEM_ADMISSION';
        $source       = (string) ($p['source'] ?? 'offline');
        $admissionRef = (string) ($p['admission_ref'] ?? $appId);
        $now   = date('Y-m-d H:i:s');
        $today = date('Y-m-d');

        // Receipt (admission-fee) type from the configured fee head.
        $hl = strtolower($feeHead);
        $receiptType = (strpos($hl, 'registration') !== false) ? 'registration'
            : ((strpos($hl, 'prospectus') !== false) ? 'prospectus'
            : (((strpos($hl, 'enquir') !== false) || (strpos($hl, 'inquir') !== false)) ? 'enquiry' : 'admission'));

        // Collection source label for the receipt (commercial ERP).
        $collectionSource = ($source === 'offline') ? 'Admission Counter' : 'Online Admission Portal';

        // ── Fix A (C1 + M2): atomic idempotency claim — reuse Accounting_idempotency ──
        // Exactly ONE admission fee per application, so key on app_id. This single
        // gate closes the callback-vs-webhook double-reconcile (C1) AND the double
        // offline-collect (M2), using the SAME claim primitive the accounting
        // journal already uses (create-if-not-exists is atomic) — no parallel
        // idempotency store. The slot's entryId holds the minted ADM key so a
        // deduped retry returns the ORIGINAL receipt instead of minting a second.
        $idempKey = 'ADM_COLLECT_' . ($appId !== ''
            ? $appId
            : (string) ($p['payment_id'] ?? ('R' . bin2hex(random_bytes(6)))));
        $this->CI->load->library('Accounting_idempotency', null, 'admIdemp');
        $this->CI->admIdemp->init($this->firebase, $this->schoolId, $this->session);
        $claim = $this->CI->admIdemp->claim($idempKey, 'admission_' . $source);
        if (!empty($claim['dedup'])) {
            $existingKey = (string) ($claim['entryId'] ?? '');
            log_message('error', "Admission_collection: DEDUP key={$idempKey} receipt={$existingKey} — no second receipt/journal");
            return $existingKey !== ''
                ? ['receipt_no' => $existingKey, 'receipt_type' => $receiptType, 'amount' => $amount, 'deduped' => true]
                : null;
        }
        if (!empty($claim['in_progress'])) {
            log_message('error', "Admission_collection: IN-PROGRESS key={$idempKey} — skipping duplicate reconcile");
            return null;
        }
        if (isset($claim['error'])) {
            // Fail CLOSED — proceeding without the gate risks a double mint.
            log_message('error', "Admission_collection: idempotency error key={$idempKey}: " . $claim['error']);
            return null;
        }
        // first_claim | stale_override ⇒ we own the slot; mint exactly once.

        try {
            // 1. Atomic ADM receipt number (Fix B — throws on allocator failure).
            $rc         = $this->next_receipt_no();
            $receiptKey = $rc['key'];
            $receiptSeq = $rc['number'];

            // 2. Persist the receipt in its dedicated, domain-isolated collection.
            //    Written BEFORE the journal (journalPosted=false) so a mid-flight
            //    crash still leaves a receipt the shared reconciler can find and
            //    re-drive. Journal-status fields reuse the accounting subsystem's
            //    EXISTING convention (journalPosted / journalEntryId /
            //    journalLastError) — not a parallel state model.
            $this->fs->setEntity('admissionReceipts', $receiptKey, [
                'receiptNo'        => $receiptSeq,
                'receiptKey'       => $receiptKey,
                'type'             => 'admission',
                'receiptType'      => $receiptType,
                'collectionType'   => 'admission',
                'schoolId'         => $this->schoolId,
                'session'          => $this->session,
                'appId'            => $appId,
                'admissionRef'     => $admissionRef,
                'studentName'      => $studentName,
                'fatherName'       => $fatherName,
                'appliedClass'     => $appliedClass,
                'feeHead'          => $feeHead,
                'feeLabel'         => $feeLabel,
                'amount'           => $amount,
                'netAmount'        => $amount,
                'currency'         => $currency,
                'paymentMode'      => $modeLabel,
                'accountingMode'   => $mode,
                'bankLedgerCode'   => $bankCode,
                'bankReference'    => (string) ($p['bank_reference'] ?? ''),
                'remarks'          => (string) ($p['remarks'] ?? ''),
                'gatewayPaymentId' => (string) ($p['gateway_payment_id'] ?? ''),
                'paymentId'        => (string) ($p['payment_id'] ?? ''),
                'collectionSource' => $collectionSource,
                'collectedBy'      => $collectedBy,
                'source'           => $source,
                'date'             => $today,
                'datetime'         => $now,
                'status'           => 'PAID',
                'journalPosted'    => false,
                'journalEntryId'   => '',
                'journalLastError' => '',
                'createdAt'        => $now,
                'updatedAt'        => $now,
            ]);

            // 3. Post the journal through the CANONICAL fee engine (reuse-only).
            //    Cr = configurable admission income account; Dr = cash/bank per
            //    mode. Deterministic JE_FEE_{receiptKey} ⇒ idempotent + reconciler
            //    re-drivable. Fix C: CAPTURE the return — null means period-lock /
            //    missing account / dedup, which must NOT be silent.
            $journalId  = '';
            $journalErr = '';
            try {
                $this->CI->load->library('Operations_accounting', null, 'opsAcctAdm');
                $this->CI->opsAcctAdm->init($this->firebase, $this->schoolId, $this->session, $collectedBy, $this->CI);
                $journalId = (string) ($this->CI->opsAcctAdm->create_fee_journal([
                    'school_name'      => $this->schoolId,
                    'session_year'     => $this->session,
                    'date'             => $today,
                    'amount'           => $amount,
                    'payment_mode'     => $mode,
                    'bank_code'        => $bankCode,
                    'receipt_no'       => $receiptKey,
                    'student_name'     => $studentName,
                    'student_id'       => $appId,
                    'admin_id'         => $collectedBy,
                    'allocations'      => [[
                        'fee_head' => $feeHead,
                        'category' => 'Admission',
                        'amount'   => $amount,
                    ]],
                    'fine_amount'      => 0,
                    'discount_amount'  => 0,
                    'collection_type'  => 'admission',
                    'journal_entry_id' => "JE_FEE_{$receiptKey}",
                ]) ?? '');
            } catch (\Throwable $e) {
                $journalErr = $e->getMessage();
                log_message('error', "Admission_collection: journal threw receipt={$receiptKey} school={$this->schoolId}: {$journalErr}");
            }
            $journalPosted = ($journalId !== '');
            if (!$journalPosted && $journalErr === '') {
                $journalErr = 'journal not posted (period-lock / missing account / dedup)';
            }

            // 3b. Stamp the journal outcome on the receipt (single update, existing
            //     convention). journalPosted=false is greppable + reconciler-drivable.
            $this->fs->updateEntity('admissionReceipts', $receiptKey, [
                'journalPosted'    => $journalPosted,
                'journalEntryId'   => $journalId,
                'journalLastError' => $journalPosted ? '' : substr($journalErr, 0, 300),
                'updatedAt'        => date('Y-m-d H:i:s'),
            ]);
            if (!$journalPosted) {
                log_message('error', "ADMISSION_JOURNAL_UNPOSTED receipt={$receiptKey} school={$this->schoolId} reason={$journalErr}");
            }

            // 4. Timeline event on the lead. M7-B: dedupeKey on the receipt key makes
            //    a callback/webhook race idempotent — one payment_collected entry.
            $this->append_timeline($appId, 'payment_collected',
                ucfirst($receiptType) . " fee {$currency} {$amount} collected via {$modeLabel} ({$collectionSource})"
                . ($admissionRef !== '' ? ", ref {$admissionRef}" : '') . " — receipt {$receiptKey}",
                $collectedBy, 'pay_' . $receiptKey);

            // 5. Audit (reused auditLogs). M7-A: additive appId correlation so the
            //    receipt/collection event links to the originating application.
            $this->audit($collectedBy, 'Admission', 'admission_collection', $receiptKey,
                "Admission {$receiptType} fee {$currency} {$amount} for app {$appId} via {$modeLabel} ({$collectionSource}) — Receipt {$receiptKey}",
                $appId);

            // Fix A: mark the slot success, storing the ADM key so a later
            // callback/webhook/offline retry dedups to THIS receipt.
            $this->CI->admIdemp->markSuccess($idempKey, $receiptKey);

            return ['receipt_no' => $receiptKey, 'receipt_type' => $receiptType, 'amount' => $amount];
        } catch (\Throwable $e) {
            // Failure BEFORE success (e.g. allocator exhausted) — release the slot
            // as failed so the webhook/offline retry can re-drive cleanly.
            $this->CI->admIdemp->markFailed($idempKey, $e->getMessage());
            log_message('error', "Admission_collection::record_payment failed key={$idempKey}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Next admission receipt number — ATOMIC via the shared claim-doc allocator
     * (`Firestore_service::nextSchoolCounter`, the SAME primitive the fee `F` and
     * CRM `APP/STU` series use). Replaces the prior non-atomic read-verify-write
     * loop (C2): two concurrent minters can no longer both return the same ADM
     * number. The ADM###### series continues without a gap — on first use the
     * SYSTEM_COUNTERS pointer is seeded ONCE from the legacy
     * `feeCounters/{schoolId}_admissionReceiptNo` value.
     *
     * On allocator exhaustion/unavailability we FAIL LOUD (throw) rather than
     * fabricate a colliding `microtime` number — record_payment catches it, marks
     * the idempotency slot failed, and the webhook/offline retry re-drives.
     *
     * @return array { number: int, key: string }
     * @throws \RuntimeException when no number could be allocated
     */
    public function next_receipt_no(): array
    {
        // One-time migration seed: carry the legacy counter value forward so the
        // ADM series does not restart. Invoked by nextSchoolCounter ONLY when the
        // SYSTEM_COUNTERS pointer has no recorded value (i.e. exactly once).
        $seed = function () {
            try {
                $doc = $this->firebase->firestoreGet('feeCounters', "{$this->schoolId}_admissionReceiptNo");
                return is_array($doc) ? (int) ($doc['value'] ?? 0) : 0;
            } catch (\Throwable $e) {
                return 0;
            }
        };

        $n = (int) $this->fs->nextSchoolCounter('admissionReceiptNo', 0, 8, [], $seed);
        if ($n <= 0) {
            log_message('error', "Admission_collection::next_receipt_no allocator failed school={$this->schoolId}");
            throw new \RuntimeException('Admission receipt number allocation failed.');
        }
        return ['number' => $n, 'key' => 'ADM' . str_pad((string) $n, 6, '0', STR_PAD_LEFT)];
    }

    /**
     * Append a structured event to the Admission Lead's activity timeline.
     * Non-blocking; reused by every admission phase (submission → approval).
     */
    public function append_timeline(string $appId, string $event, string $detail, string $actor, string $dedupeKey = ''): void
    {
        if ($appId === '') return;
        try {
            // M7-B: concurrency-safe CAS append to the BOUNDED timeline recent-cache
            // (permanent trail is auditLogs). Fire-and-forget: a cache-maintenance
            // failure is logged but never affects the caller (record_payment's
            // receipt/journal/idempotency are already committed by this point).
            // $dedupeKey (e.g. the receipt key) makes a callback/webhook race
            // idempotent — one payment_collected entry.
            $this->CI->load->helper('admission_crm');
            $this->fs->casAppendBounded('crmApplications', $appId, 'timeline',
                [
                    'event'     => $event,
                    'detail'    => $detail,
                    'actor'     => $actor,
                    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                ],
                crm_timeline_cap(),
                ['updated_at' => date('Y-m-d H:i:s')],
                $dedupeKey);
        } catch (\Exception $e) {
            log_message('error', 'Admission_collection::append_timeline failed: ' . $e->getMessage());
        }
    }

    /**
     * Write an audit entry (reused auditLogs). Non-blocking.
     */
    private function audit(string $actor, string $module, string $action, string $entityId, string $description, string $appId = ''): void
    {
        try {
            $logId = 'AL_' . date('Ymd_His') . '_' . substr(uniqid('', true), -6);
            $nowMs = (int) round(microtime(true) * 1000);
            $entry = [
                'userId'      => $actor,
                'userName'    => $actor,
                'userRole'    => 'Admission',
                'module'      => $module,
                'action'      => $action,
                'entityId'    => $entityId,
                'description' => $description,
                'timestamp'   => gmdate('Y-m-d\TH:i:s\Z', (int) ($nowMs / 1000)),
                'timestampMs' => $nowMs,
            ];
            // M7-A — additive application correlation. The receipt event keeps
            // entityId=receiptKey for accounting/receipt consumers; `appId` links
            // it to the originating application without changing that identifier.
            if ($appId !== '') $entry['appId'] = $appId;
            $this->fs->setEntity('auditLogs', $logId, $entry);
        } catch (\Exception $e) {
            log_message('error', 'Admission_collection::audit failed: ' . $e->getMessage());
        }
    }
}
