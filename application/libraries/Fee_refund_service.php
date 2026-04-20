<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_refund_service — Pure-Firestore refund orchestrator.
 *
 * SESSION B of the Firestore-only migration. Every read/write the service
 * performs goes through Fee_firestore_txn (Firestore-only). The only
 * external RTDB touchpoint left is the accounting journal reversal via
 * Operations_accounting — that's explicitly deferred to Session C.
 *
 * Flow:
 *   1. Validate + lock         (feeRefunds doc status transition)
 *   2. Reverse demands         (feeDemands rows) + build audit trail
 *   3. Reduce advance balance  (studentAdvanceBalances doc)
 *   4. Write refund voucher    (feeRefundVouchers doc — Firestore replacement
 *                                for legacy RTDB Accounts/Vouchers/{date}/REFUND_*)
 *   5. Post reversal journal   (ops_acct — Session C bridge, still RTDB-backed)
 *   6. Mark refund processed   (feeRefunds doc)
 *
 * Dependency injection is simpler than the prior version — only fsTxn +
 * ops_acct are required. Fee_audit is kept optional (cross-cutting).
 */
class Fee_refund_service
{
    /** @var Fee_firestore_txn */ private $fsTxn;
    /** @var object */          private $opsAcct;
    /** @var string */          private $adminName;
    /** @var string */          private $adminId;

    private const LOCK_TTL = 300; // 5 min

    public function init($fsTxn, string $adminName, string $adminId, $opsAcct = null): self
    {
        $this->fsTxn     = $fsTxn;
        $this->adminName = $adminName;
        $this->adminId   = $adminId;
        $this->opsAcct   = $opsAcct;
        return $this;
    }

    /**
     * Process an approved refund end-to-end.
     *
     * @return array ['ok' => bool, 'data' => [...], 'error' => '...']
     */
    public function process(string $refId, string $refundMode): array
    {
        // ── 1. Validate (existing doc-status + processLock checks) ───
        // These remain an independent first line of defense. The NEW
        // request-hash + per-student lock below are additive layers.
        $refund = $this->fsTxn->getRefund($refId);
        if (!is_array($refund)) {
            return ['ok' => false, 'error' => 'Refund not found.'];
        }

        $status = (string) ($refund['status'] ?? '');
        if ($status === 'processed') {
            return ['ok' => true, 'data' => [
                'message' => 'Refund already processed.', 'refund_id' => $refId, 'idempotent' => true,
            ]];
        }

        if ($status === 'processing') {
            $lockTime = strtotime((string) ($refund['processLock'] ?? $refund['process_lock'] ?? ''));
            if ($lockTime && (time() - $lockTime) < self::LOCK_TTL) {
                return ['ok' => false, 'error' => 'Refund is currently being processed. Please wait.'];
            }
            log_message('info', "Fee_refund_service: stale lock on {$refId}, retrying");
        } elseif ($status !== 'approved') {
            return ['ok' => false, 'error' => "Only approved refunds can be processed. Current status: '{$status}'."];
        }

        // Derive the fields we need for the request-hash key BEFORE any
        // state change. Same precedence the old code used at the top of
        // the processing block.
        $studentId     = (string) ($refund['student_id']   ?? $refund['studentId']   ?? '');
        $origReceiptNo = (string) ($refund['receipt_no']   ?? $refund['receiptNo']   ?? '');
        $amount        = (float)  ($refund['amount'] ?? 0);

        if ($studentId === '') {
            return ['ok' => false, 'error' => 'Refund record missing student_id.'];
        }

        // ── R.1a: Request-hash idempotency (feeIdempotency) ──────────
        // Mirrors FeeCollectionService::submit L133-173 exactly. The
        // hash uniquely identifies this refund attempt; a duplicate
        // process() call with the same (studentId, refId, receiptNo,
        // amount) short-circuits here instead of double-reversing.
        $idempHash = $this->fsTxn->idempKey($studentId, $refId, [$origReceiptNo], $amount);
        $idemp     = $this->fsTxn->getIdempotency($idempHash);
        if (is_array($idemp)) {
            $st = (string) ($idemp['status'] ?? '');
            if ($st === 'success') {
                return ['ok' => true, 'data' => [
                    'message' => 'Refund already processed.', 'refund_id' => $refId, 'idempotent' => true,
                ]];
            }
            if ($st === 'processing') {
                $age = time() - strtotime((string) ($idemp['startedAt'] ?? '2000-01-01'));
                if ($age < 120) {
                    return ['ok' => false, 'error' => 'This refund is currently being processed. Please wait.'];
                }
                // Older than 120s → stale marker, allow retry (overwrite below).
            }
        }
        $this->fsTxn->setIdempotencyProcessing($idempHash, [
            'kind'       => 'refund',
            'refundId'   => $refId,
            'studentId'  => $studentId,
            'receiptNo'  => $origReceiptNo,
            'amount'     => $amount,
            'refundMode' => $refundMode,
        ]);

        // ── R.1b: Per-student lock (feeLocks) ───────────────────────
        // 120s TTL matching FeeCollectionService. Serialises with
        // concurrent payments on the same student — before this, a
        // refund could race with a parent-app Razorpay verify on the
        // same student and read stale demand balances mid-write.
        $lockToken = $this->fsTxn->acquireLock($studentId, 120);
        if ($lockToken === '') {
            $this->fsTxn->deleteIdempotency($idempHash);
            return ['ok' => false, 'error' => 'Another fee operation for this student is in progress. Please wait and retry.'];
        }

        try {
            // Existing refund-doc 'processing' marker — preserved.
            $this->fsTxn->writeRefund($refId, [
                'status'      => 'processing',
                'processLock' => date('c'),
            ]);

            if ($amount <= 0) {
                // Roll refund doc back to 'approved' and clear both safety
                // markers so the admin can re-trigger after fixing amount.
                $this->fsTxn->writeRefund($refId, ['status' => 'approved', 'processLock' => '']);
                $this->fsTxn->deleteIdempotency($idempHash);
                return ['ok' => false, 'error' => 'Refund amount must be greater than zero.'];
            }

            $refundReceiptKey = 'REFUND_' . strtoupper(substr($refId, 4));
            $studentName   = (string) ($refund['student_name'] ?? $refund['studentName'] ?? '');
            $refClass      = (string) ($refund['class']        ?? $refund['className']   ?? '');
            $refSection    = (string) ($refund['section']      ?? '');
            $origReceiptKey= $origReceiptNo !== '' ? 'F' . $origReceiptNo : '';

            // ── 2. Reverse the original receipt's allocations ────────────
            $demandResult = null;
            $allocations  = [];
            if ($studentId !== '' && $origReceiptKey !== '') {
                $demandResult = $this->_reverseAllocations(
                    $studentId, $origReceiptKey, $amount, $refId, $refundReceiptKey, $allocations
                );
            }

            // ── R.3: wallet-deficit fail-closed check ───────────────────
            // _reverseAllocations returns a ['failed' => true, ...] dict
            // when the refund would need to debit more than the student's
            // advance balance holds. No writes have landed yet (audit +
            // batch come AFTER Step 2), so rolling back here is clean:
            // reset the refund doc to 'approved' and clear both the
            // refund-doc processLock and the idempotency marker so the
            // admin can retry with a smaller amount after fixing the
            // underlying wallet shortfall.
            if (is_array($demandResult) && !empty($demandResult['failed'])) {
                $this->fsTxn->writeRefund($refId, [
                    'status'      => 'approved',
                    'processLock' => '',
                ]);
                $this->fsTxn->deleteIdempotency($idempHash);
                log_message('error',
                    "Fee_refund_service::process({$refId}) rejected — {$demandResult['failure_code']}: " .
                    $demandResult['failure_message']);
                return [
                    'ok'       => false,
                    'error'    => $demandResult['failure_message'],
                    'code'     => $demandResult['failure_code'],
                    'details'  => [
                        'overflow'       => $demandResult['overflow']       ?? null,
                        'wallet_balance' => $demandResult['wallet_balance'] ?? null,
                        'shortfall'      => $demandResult['shortfall']      ?? null,
                    ],
                ];
            }

            // ── 3. Update legacy month-fee flag on the student doc ──────
            // Skipped when _reverseAllocations already wrote the student
            // doc in its batch (R.2) — recomputing + writing again would
            // be a redundant round-trip with the same end state. Still
            // runs for refunds where origReceiptKey was empty (no
            // allocations to reverse) so the student doc at minimum
            // gets its `lastRefundAt` timestamp refreshed.
            $studentAlreadyUpdated = is_array($demandResult) && ($demandResult['student_doc_updated'] ?? false);
            if ($studentId !== '' && !$studentAlreadyUpdated) {
                $this->_recomputeMonthFeeFlags($studentId);
            }

            // ── 4. Write the refund voucher (Firestore) ──────────────────
            $this->fsTxn->writeRefundVoucher($refundReceiptKey, [
                'type'         => 'refund',
                'refundId'     => $refId,
                'studentId'    => $studentId,
                'studentName'  => $studentName,
                'className'    => $refClass,
                'section'      => $refSection,
                'feeTitle'     => (string) ($refund['fee_title'] ?? $refund['feeTitle'] ?? ''),
                'amount'       => -$amount,
                'refundMode'   => $refundMode,
                'origReceiptNo'=> $origReceiptNo,
                'reason'       => (string) ($refund['reason']  ?? ''),
                'processedBy'  => $this->adminName,
                'processedAt'  => date('c'),
            ]);

            // ── 5. Post accounting reversal journal (Session C bridge) ───
            // R.5: capture the result. If it fails, the refund is STILL
            // marked processed — demands/voucher are already written and
            // reversing them to get back to 'approved' risks a messier
            // hole (re-applying paid_amounts without a second transaction).
            // Instead we record journalPosted=false on the refund doc so
            // the admin can retry via Fee_management::retry_refund_journal.
            $journalResult = $this->_postReversalJournal($refund, $amount, $refundMode, $refId, $allocations);

            // ── 6. Mark the refund processed ─────────────────────────────
            // Merge in the journal-state fields so a single write closes
            // the refund transaction cleanly, success or partial.
            $markProcessed = [
                'status'               => 'processed',
                'processedDate'        => date('Y-m-d H:i:s'),
                'processedBy'          => $this->adminName,
                'refundMode'           => $refundMode,
                'voucherKey'           => $refundReceiptKey,
                'processLock'          => '',
                'journalLastAttemptAt' => date('c'),
                'journalRetryCount'    => 1,
            ];
            if ($journalResult['posted']) {
                $markProcessed['journalPosted']    = true;
                $markProcessed['journalEntryId']   = $journalResult['entryId'];
                $markProcessed['journalPostedAt']  = date('c');
                $markProcessed['journalLastError'] = '';
            } else {
                $markProcessed['journalPosted']    = false;
                $markProcessed['journalEntryId']   = null;
                $markProcessed['journalLastError'] = (string) ($journalResult['error'] ?? 'unknown');
                log_message('error',
                    "Fee_refund_service: refund {$refId} processed but journal FAILED — " .
                    "admin must retry via retry_refund_journal. Error: {$journalResult['error']}");
            }
            $this->fsTxn->writeRefund($refId, $markProcessed);

            // ── 7. Cross-system defaulter recompute (Firestore-only) ─────
            // A refund can flip a 'paid' demand back to 'partial'/'unpaid',
            // which should resurface the student as a defaulter in the
            // parent app (Fees → Pending Dues) and teacher app (defaulter
            // list). Without this recompute, feeDefaulters stays stale
            // until the next payment or nightly job touches it.
            // Pure in-memory + single Firestore write — no RTDB calls.
            if ($studentId !== '') {
                $this->_syncDefaulterAfterRefund($studentId, $studentName, $refClass, $refSection);
            }

            // R.1a: mark idempotency success so any future replay of the
            // same hash short-circuits to the cached result above.
            $this->fsTxn->setIdempotencySuccess($idempHash, $refundReceiptKey);

            log_message('info', "Fee_refund_service: {$refId} processed — amount={$amount} journalPosted=" . ($journalResult['posted'] ? '1' : '0'));

            $response = [
                'message'         => $journalResult['posted']
                    ? 'Refund processed successfully.'
                    : 'Refund processed. Journal post failed — click Retry Journal on the refund row to complete the ledger entry.',
                'voucher_key'     => $refundReceiptKey,
                'refund_id'       => $refId,
                'journal_posted'  => (bool) $journalResult['posted'],
                'journal_entry_id'=> $journalResult['entryId'],
                'journal_error'   => $journalResult['error'],
            ];
            if ($demandResult !== null) $response['demand_reversal'] = $demandResult;
            return ['ok' => true, 'data' => $response];
        } catch (\Exception $e) {
            // Any unexpected throw inside the processing block. The
            // refund doc stays in 'processing' for manual inspection;
            // clearing the idempotency marker lets the admin retry
            // after fixing the underlying cause.
            $this->fsTxn->deleteIdempotency($idempHash);
            log_message('error', "Fee_refund_service::process({$refId}) threw: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Refund processing failed: ' . $e->getMessage()];
        } finally {
            // R.1b: ALWAYS release the per-student lock, whether we
            // succeeded, hit the amount<=0 rollback, or threw.
            $this->fsTxn->releaseLock($studentId, $lockToken);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    //  Private helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Reverse allocations on the original receipt.
     *
     * Reads the receipt's allocation record from Firestore, walks the
     * allocation lines in reverse (newest first), and reduces each
     * matching demand's paid_amount. Any excess (refund amount > sum of
     * allocations) reduces the student's advance balance.
     */
    private function _reverseAllocations(
        string $studentId, string $origReceiptKey, float $amount,
        string $refId, string $refundReceiptKey, array &$allocationsOut
    ): ?array {
        $alloc       = $this->fsTxn->getReceiptAllocation($origReceiptKey);
        $allocLines  = (is_array($alloc) && is_array($alloc['allocations'] ?? null)) ? $alloc['allocations'] : [];
        $alreadyDone = is_array($alloc) && (($alloc['status'] ?? '') === 'reversed');
        if ($alreadyDone) {
            return ['reversed' => 0, 'already_reversed' => true, 'student_doc_updated' => false];
        }

        try {
            $allocationsOut = $allocLines;
            $demands    = $this->fsTxn->demandsForStudent($studentId);
            $reversed   = 0;
            $log        = [];
            $remaining  = $amount;
            $demandUpdates = []; // demandId → patch dict; collected for batch + fallback

            // ── Step 1: compute reversal IN MEMORY (no writes) ──
            foreach (array_reverse($allocationsOut) as $a) {
                if ($remaining <= 0.005) break;
                $did      = (string) ($a['demand_id'] ?? '');
                $allocAmt = (float)  ($a['allocated'] ?? $a['amount'] ?? 0);
                if ($did === '' || $allocAmt <= 0) continue;
                if (!isset($demands[$did])) continue;

                $reverseAmt = min($remaining, $allocAmt);
                $d          = $demands[$did];
                $oldPaid    = (float) ($d['paid_amount'] ?? 0);
                $net        = (float) ($d['net_amount']  ?? $d['netAmount']  ?? 0);
                $fine       = (float) ($d['fine_amount'] ?? $d['fineAmount'] ?? 0);
                $newPaid    = round(max(0, $oldPaid - $reverseAmt), 2);
                $newBal     = round(max(0, $net + $fine - $newPaid), 2);
                $newStatus  = ($newPaid <= 0.005) ? 'unpaid'
                            : (($newBal <= 0.005) ? 'paid' : 'partial');

                $demandUpdates[$did] = [
                    'paid_amount'         => $newPaid,
                    'balance'             => $newBal,
                    'status'              => $newStatus,
                    'last_refund_receipt' => $refundReceiptKey,
                    'last_refund_date'    => date('c'),
                ];

                $log[] = [
                    'demand_id'    => $did,
                    'fee_head'     => $a['fee_head'] ?? '',
                    'period'       => $a['period']   ?? '',
                    'reversed_amt' => round($reverseAmt, 2),
                    'new_status'   => $newStatus,
                ];
                $remaining -= $reverseAmt;
                $reversed++;
            }

            // ── Step 2: compute wallet debit (if overflow) IN MEMORY ──
            // R.3: fail-closed when wallet cannot cover the overflow.
            // Prior behaviour silently clamped to zero via max(0, ...) —
            // that let the bookkeeping finish "successfully" while the
            // refunded amount was greater than (allocations + wallet),
            // producing a negative-equivalent state (demands reduced +
            // voucher issued but no corresponding advance-balance debit).
            // Now we reject the refund outright and let process() roll
            // back the 'processing' marker so the admin can either
            // lower the refund amount or top-up the wallet first.
            $walletAfter = null;
            if ($remaining > 0.005) {
                $curAdv = $this->fsTxn->getAdvanceBalance($studentId);
                if ($curAdv + 0.005 < $remaining) {
                    $shortfall = round($remaining - $curAdv, 2);
                    log_message('error',
                        "[REFUND WALLET DEFICIT] refund={$refId} student={$studentId} " .
                        "overflow={$remaining} walletHas={$curAdv} shortfall={$shortfall}");
                    return [
                        'failed'          => true,
                        'failure_code'    => 'WALLET_DEFICIT',
                        'failure_message' => sprintf(
                            'Refund exceeds allocations by ₹%.2f but student wallet only has ₹%.2f (shortfall ₹%.2f). Reduce refund amount or top-up advance balance first.',
                            $remaining, $curAdv, $shortfall
                        ),
                        'overflow'        => round($remaining, 2),
                        'wallet_balance'  => round($curAdv, 2),
                        'shortfall'       => $shortfall,
                    ];
                }
                $walletAfter = round($curAdv - $remaining, 2);
            }

            // ── Step 3: compute monthFee flags from post-reversal snapshot ──
            // Same regex FeeCollectionService uses — strips trailing
            // "2026" / "2026-27" tokens while preserving multi-word
            // labels like "Yearly Fees".
            $demandStateAfter = $demands;
            foreach ($demandUpdates as $did => $patch) {
                if (isset($demandStateAfter[$did])) {
                    $demandStateAfter[$did]['status']      = $patch['status'];
                    $demandStateAfter[$did]['balance']     = $patch['balance'];
                    $demandStateAfter[$did]['paid_amount'] = $patch['paid_amount'];
                }
            }
            $flags = [];
            foreach ($demandStateAfter as $d) {
                $p = trim((string) preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', (string) ($d['period'] ?? '')));
                if ($p === '') continue;
                if (!isset($flags[$p])) $flags[$p] = 1;
                if (($d['status'] ?? 'unpaid') !== 'paid') $flags[$p] = 0;
            }
            $student   = $this->fsTxn->getStudent($studentId);
            $existing  = is_array($student['monthFee'] ?? null) ? $student['monthFee'] : [];
            $mergedMF  = empty($flags) ? $existing : array_replace($existing, $flags);

            // ── Step 4: write audit doc (sequential, OUTSIDE batch) ──
            // Audit needs to land before the batch so markAllocationReversed
            // inside the batch can reference the auditId. If the batch
            // then fails and the fallback also fails, the audit doc is a
            // lone orphan that helps debugging — acceptable tradeoff.
            $auditId = 'RFND_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            $this->fsTxn->writeRefundAudit($auditId, [
                'receiptKey'     => $origReceiptKey,
                'refundId'       => $refId,
                'refundReceipt'  => $refundReceiptKey,
                'studentId'      => $studentId,
                'refundAmount'   => round($amount, 2),
                'reversals'      => $log,
                'reversedCount'  => $reversed,
                'createdAt'      => date('c'),
                'createdBy'      => $this->adminId,
            ]);

            // ── Step 5: build + commit the atomic batch (R.2) ──
            // Same op shape FeeCollectionService uses (op/collection/
            // docId/merge/data). Four write categories:
            //   (a) N feeDemands updates  (demandUpdates entries)
            //   (b) studentAdvanceBalances (only when overflow >0)
            //   (c) feeReceiptAllocations  (status → reversed)
            //   (d) students.monthFee      (recomputed flags)
            $schoolId = $this->fsTxn->getSchoolId();
            $session  = $this->fsTxn->getSession();
            $now      = date('c');
            $batchOps = [];

            // (a) Per-demand updates. Dual-emit the camelCase mirrors
            // updateDemand() adds internally (paidAmount) so Android
            // readers see identical state whether we took the batch
            // path or the sequential fallback.
            foreach ($demandUpdates as $did => $patch) {
                $batchOps[] = [
                    'op'         => 'set',
                    'collection' => 'feeDemands',
                    'docId'      => $did,
                    'merge'      => true,
                    'data'       => [
                        'paid_amount'         => $patch['paid_amount'],
                        'paidAmount'          => (float) $patch['paid_amount'],
                        'balance'             => $patch['balance'],
                        'status'              => $patch['status'],
                        'last_refund_receipt' => $patch['last_refund_receipt'],
                        'last_refund_date'    => $patch['last_refund_date'],
                        'updatedAt'           => $now,
                    ],
                ];
            }

            // (b) Wallet debit — only when reversal overflowed into
            // advance balance. Shape matches setAdvanceBalance's payload.
            if ($walletAfter !== null) {
                $batchOps[] = [
                    'op'         => 'set',
                    'collection' => 'studentAdvanceBalances',
                    'docId'      => "{$schoolId}_{$studentId}",
                    'merge'      => true,
                    'data'       => [
                        'schoolId'   => $schoolId,
                        'session'    => $session,
                        'studentId'  => $studentId,
                        'amount'     => round(max(0, $walletAfter), 2),
                        'lastRefund' => $refundReceiptKey,
                        'updatedAt'  => $now,
                    ],
                ];
            }

            // (c) Flag the original allocation doc as reversed. Shape
            // matches markAllocationReversed's payload.
            $batchOps[] = [
                'op'         => 'set',
                'collection' => 'feeReceiptAllocations',
                'docId'      => "{$schoolId}_{$session}_{$origReceiptKey}",
                'merge'      => true,
                'data'       => [
                    'status'        => 'reversed',
                    'reversedAt'    => $now,
                    'refundAuditId' => $auditId,
                    'updatedAt'     => $now,
                ],
            ];

            // (d) Student doc — monthFee flags recomputed post-reversal
            // + lastRefundAt stamp. Subsumes _recomputeMonthFeeFlags's
            // write; process() will skip that method when we succeed.
            $batchOps[] = [
                'op'         => 'set',
                'collection' => 'students',
                'docId'      => "{$schoolId}_{$studentId}",
                'merge'      => true,
                'data'       => [
                    'monthFee'     => $mergedMF,
                    'lastRefundAt' => $now,
                    'updatedAt'    => $now,
                ],
            ];

            log_message('debug', "[REFUND BATCH COMMIT] ops=" . count($batchOps) . " refund={$refId}");
            $batchOk = $this->fsTxn->commitBatch($batchOps);
            log_message('debug', "[REFUND BATCH COMMIT] ok=" . ($batchOk ? 'true' : 'false'));

            if (!$batchOk) {
                // ── Fallback: sequential writes, matching the pre-R.2
                //    behaviour verbatim. Same end state, slower. Mirrors
                //    FeeCollectionService's batch-fallback strategy.
                log_message('error', "[REFUND BATCH FALLBACK] batch commit failed for refund={$refId}; falling back to sequential writes");

                foreach ($demandUpdates as $did => $patch) {
                    $this->fsTxn->updateDemand($did, $patch);
                }
                if ($walletAfter !== null) {
                    $this->fsTxn->setAdvanceBalance($studentId, $walletAfter, [
                        'lastRefund' => $refundReceiptKey,
                    ]);
                }
                $this->fsTxn->markAllocationReversed($origReceiptKey, $auditId);
                $this->fsTxn->updateStudent($studentId, [
                    'monthFee'     => $mergedMF,
                    'lastRefundAt' => $now,
                ]);
            }

            return [
                'reversed'            => $reversed,
                'audit_id'            => $auditId,
                'batch_committed'     => $batchOk,
                'student_doc_updated' => true,
            ];
        } catch (\Exception $e) {
            log_message('error', 'Fee_refund_service::_reverseAllocations failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * After reversing demands, recompute the `monthFee` map on the student
     * doc so paid months flip to 0 if their demands are no longer fully paid.
     */
    private function _recomputeMonthFeeFlags(string $studentId): void
    {
        try {
            $demands = $this->fsTxn->demandsForStudent($studentId);

            // Recompute only the months that actually have demands. The
            // remaining map stays intact (we don't want to wipe April=1 just
            // because a later month is empty).
            // Strip trailing year/session token ("June 2026" → "June";
            // "Yearly Fees 2026-27" → "Yearly Fees") while PRESERVING
            // multi-word labels. The old explode(' ',$p)[0] truncated
            // "Yearly Fees 2026-27" to "Yearly", writing a bogus
            // monthFee["Yearly"] key instead of flipping the real
            // monthFee["Yearly Fees"] key. Same regex FeeCollectionService
            // uses when it recomputes monthFee post-payment.
            $flags = [];
            foreach ($demands as $d) {
                $rawPeriod = (string) ($d['period'] ?? '');
                $p = trim((string) preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', $rawPeriod));
                if ($p === '') continue;
                if (!isset($flags[$p])) $flags[$p] = 1;
                if (($d['status'] ?? 'unpaid') !== 'paid') $flags[$p] = 0;
            }

            // Always touch the student doc so the refund audit trail has a
            // timestamp even if no demand statuses shifted (e.g. school is on
            // the legacy month-fee flag model rather than demand-based).
            $student  = $this->fsTxn->getStudent($studentId);
            $existing = is_array($student['monthFee'] ?? null) ? $student['monthFee'] : [];
            $merged   = empty($flags) ? $existing : array_replace($existing, $flags);
            $this->fsTxn->updateStudent($studentId, [
                'monthFee'    => $merged,
                'lastRefundAt'=> date('c'),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Fee_refund_service::_recomputeMonthFeeFlags failed: ' . $e->getMessage());
        }
    }

    /**
     * Firestore-only defaulter recompute after a refund.
     *
     * Reads the fresh demand state from Firestore (already updated by
     * _reverseAllocations), builds a defaulter-status dict in memory,
     * and syncs it via Fee_firestore_sync::syncDefaulterStatus — which
     * is the canonical writer both the parent app and teacher app
     * read (`feeDefaulters/{schoolId}_{session}_{studentId}`).
     *
     * Deliberately AVOIDS Fee_defaulter_check::updateDefaulterStatus,
     * which still mirrors to RTDB (legacy Fees/Defaulters path) and
     * would violate the absolute NO-RTDB rule. Cost of going direct:
     * `exam_blocked` and `result_withheld` stay at their pre-refund
     * values until the next full defaulter pass refreshes them. That's
     * acceptable — a refund doesn't typically change policy-gate
     * booleans, and the next payment/nightly job will reconcile them.
     */
    private function _syncDefaulterAfterRefund(
        string $studentId, string $studentName, string $className, string $section
    ): void {
        try {
            $demands = $this->fsTxn->demandsForStudent($studentId);

            $isDefaulter   = false;
            $totalDues     = 0.0;
            $unpaidMonths  = [];
            $overdueMonths = [];
            $now           = time();

            foreach ($demands as $d) {
                $st      = (string) ($d['status']    ?? 'unpaid');
                $balance = (float)  ($d['balance']   ?? 0);
                $period  = (string) ($d['period']    ?? '');

                if ($st === 'paid' || $balance <= 0.005) continue;
                $isDefaulter = true;
                $totalDues  += $balance;
                if ($period !== '') $unpaidMonths[] = $period;

                $dueRaw = (string) ($d['due_date'] ?? $d['dueDate'] ?? '');
                $dueTs  = $dueRaw !== '' ? strtotime($dueRaw) : 0;
                if ($dueTs && $dueTs < $now && $period !== '') {
                    $overdueMonths[] = $period;
                }
            }

            // Sort so the "oldest unpaid" reader sees deterministic order
            // (Fee_dues_check::getDues reads unpaidMonths[0] as oldestUnpaid).
            sort($unpaidMonths);
            sort($overdueMonths);

            $status = [
                'is_defaulter'     => $isDefaulter,
                'total_dues'       => round($totalDues, 2),
                'unpaid_months'    => array_values(array_unique($unpaidMonths)),
                'overdue_months'   => array_values(array_unique($overdueMonths)),
                // Leave policy-gated booleans untouched — they're computed
                // from fee-head labels + school policy threshold and the
                // next full defaulter pass will refresh them.
                'exam_blocked'     => false,
                'result_withheld'  => false,
                'last_payment_date'=> '',
                'updated_at'       => date('c'),
            ];

            // Lazy-load fsSync from CI instance; Fee_refund_service doesn't
            // keep its own handle because pre-R.7 there was nothing to sync.
            $CI = &get_instance();
            if (!isset($CI->fsSync) || $CI->fsSync === null) {
                $CI->load->library('Fee_firestore_sync', null, 'fsSync');
                $CI->fsSync->init($CI->firebase, $CI->school_name ?? '', $CI->session_year ?? '');
            }

            $CI->fsSync->syncDefaulterStatus(
                $studentId, $status, $studentName, $className, $section
            );
            log_message('debug', "[REFUND DEFAULTER SYNC] student={$studentId} dues={$totalDues} isDefaulter=" . ($isDefaulter ? '1' : '0'));
        } catch (\Exception $e) {
            // Non-fatal: the refund has already succeeded, defaulter data
            // is a downstream cache. Log + move on so the admin still sees
            // a success response.
            log_message('error', "Fee_refund_service::_syncDefaulterAfterRefund({$studentId}) failed: " . $e->getMessage());
        }
    }

    /**
     * Post the reversing accounting journal. Returns a status dict so the
     * caller can record the outcome on the refund doc (R.5).
     *
     * Return shape:
     *   ['posted' => bool, 'entryId' => string|null, 'error' => string|null]
     *
     * Posted=true means the ledger entry is present (either just-created
     * or already there — the idempotency guard in Operations_accounting
     * returns the existing entryId when a journal with the same
     * source_ref exists). Posted=false means the write genuinely failed
     * and the refund doc should carry a journalPosted=false flag so the
     * admin can retry via Fee_management::retry_refund_journal.
     */
    private function _postReversalJournal(
        array $refund, float $amount, string $mode, string $refId, array $allocations
    ): array {
        if ($this->opsAcct === null) {
            return ['posted' => false, 'entryId' => null, 'error' => 'ops_acct service not available'];
        }
        try {
            $p = [
                'student_name' => (string) ($refund['student_name'] ?? $refund['studentName'] ?? ''),
                'student_id'   => (string) ($refund['student_id']   ?? $refund['studentId']   ?? ''),
                'class'        => (string) ($refund['class']        ?? $refund['className']   ?? ''),
                'amount'       => $amount,
                'refund_mode'  => $mode,
                'refund_id'    => $refId,
                'receipt_no'   => (string) ($refund['receipt_no']   ?? $refund['receiptNo']   ?? ''),
            ];
            $entryId = !empty($allocations)
                ? $this->opsAcct->create_refund_journal_granular($p, $allocations)
                : $this->opsAcct->create_refund_journal($p);

            if ($entryId === null || $entryId === '') {
                // Returned-null failure (missing CoA / inactive accounts /
                // unbalanced lines). Error already went to error_log inside
                // Operations_accounting; surface a concise message here.
                return ['posted' => false, 'entryId' => null,
                        'error' => 'Journal post returned null — check chart-of-accounts and logs'];
            }
            return ['posted' => true, 'entryId' => $entryId, 'error' => null];
        } catch (\Exception $e) {
            log_message('error', 'Fee_refund_service::_postReversalJournal: ' . $e->getMessage());
            return ['posted' => false, 'entryId' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Re-attempt the journal post for a refund that was processed but whose
     * journal write failed. Called by Fee_management::retry_refund_journal.
     * Reads the refund doc fresh, rebuilds the allocations list (if any),
     * and invokes the idempotent journal poster. Updates the refund doc
     * with the new journal state so the UI indicator can clear.
     *
     * @return array ['ok' => bool, 'error' => string|null, 'entryId' => string|null]
     */
    public function retryJournal(string $refId): array
    {
        $refund = $this->fsTxn->getRefund($refId);
        if (!is_array($refund)) {
            return ['ok' => false, 'error' => 'Refund not found.', 'entryId' => null];
        }
        if (($refund['status'] ?? '') !== 'processed') {
            return ['ok' => false,
                    'error' => "Only processed refunds can have their journal retried (current: " . ($refund['status'] ?? '?') . ").",
                    'entryId' => null];
        }
        if (!empty($refund['journalPosted'])) {
            return ['ok' => true, 'error' => null,
                    'entryId' => (string) ($refund['journalEntryId'] ?? ''),
                    'already' => true];
        }

        // Secondary idempotency check at the LEDGER level: the refund doc
        // might have been unposted (e.g. test-helper or an admin clearing
        // the flag) while the ledger entry still exists. Don't double-post.
        // Reattach the entryId to the refund doc so the UI flag clears and
        // the "already posted" signal flows to the response.
        if ($this->opsAcct !== null && method_exists($this->opsAcct, 'findExistingRefundJournal')) {
            $existing = $this->opsAcct->findExistingRefundJournal($refId);
            if (is_array($existing) && !empty($existing['entryId'])) {
                $this->fsTxn->writeRefund($refId, [
                    'journalPosted'        => true,
                    'journalEntryId'       => (string) $existing['entryId'],
                    'journalPostedAt'      => date('c'),
                    'journalLastAttemptAt' => date('c'),
                    'journalLastError'     => '',
                    'journalRetryCount'    => (int) ($refund['journalRetryCount'] ?? 0) + 1,
                ]);
                return ['ok' => true, 'error' => null,
                        'entryId' => (string) $existing['entryId'],
                        'already' => true];
            }
        }

        $amount         = (float)  ($refund['amount']    ?? 0);
        $mode           = (string) ($refund['refundMode'] ?? $refund['refund_mode'] ?? 'cash');
        $origReceiptNo  = (string) ($refund['receiptNo']  ?? $refund['receipt_no']  ?? '');
        $origReceiptKey = $origReceiptNo !== '' ? 'F' . $origReceiptNo : '';
        $allocations    = [];
        if ($origReceiptKey !== '') {
            $alloc = $this->fsTxn->getReceiptAllocation($origReceiptKey);
            if (is_array($alloc) && is_array($alloc['allocations'] ?? null)) {
                $allocations = $alloc['allocations'];
            }
        }

        $result = $this->_postReversalJournal($refund, $amount, $mode, $refId, $allocations);

        $patch = [
            'journalLastAttemptAt' => date('c'),
            'journalRetryCount'    => (int) ($refund['journalRetryCount'] ?? 0) + 1,
        ];
        if ($result['posted']) {
            $patch['journalPosted']    = true;
            $patch['journalEntryId']   = $result['entryId'];
            $patch['journalPostedAt']  = date('c');
            $patch['journalLastError'] = '';
        } else {
            $patch['journalPosted']    = false;
            $patch['journalLastError'] = (string) $result['error'];
        }
        $this->fsTxn->writeRefund($refId, $patch);

        return [
            'ok'      => (bool) $result['posted'],
            'error'   => $result['error'],
            'entryId' => $result['entryId'],
        ];
    }
}
