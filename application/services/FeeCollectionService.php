<?php
defined('BASEPATH') or exit('No direct script access allowed');

class FeeCollectionService
{
    /**
     * Unified fee-collection pipeline.
     *
     *   P1 (current): widened $data contract so parent flows (wallet, later
     *   Razorpay) can reach the same service without touching counter behaviour.
     *   Every new key defaults to the counter's current value.
     *
     * Response contract:
     *   - write_response_to_output = true  (counter default):
     *       service writes JSON to $controller->output, returns:
     *         null                               on success / direct output,
     *         ['json_error' => 'message']        on user-validation errors
     *                                            (controller shim calls json_error).
     *   - write_response_to_output = false (parent flows):
     *       service never writes output. Returns:
     *         ['ok' => true,  'http_code' => 200, 'message' => …, 'data' => [ … ]]
     *         ['ok' => false, 'http_code' => int, 'message' => …]
     */
    public function submit($controller, $data = null): ?array
    {
        $data = is_array($data) ? $data : [];
        $writeToOutput = $data['write_response_to_output'] ?? true;

        // Unified early-error helper (for input-validation failures BEFORE
        // the lock + idempotency records are taken). Preserves the counter's
        // existing ['json_error' => …] contract when writing to output.
        $_earlyError = function (string $msg, int $httpCode = 400) use ($writeToOutput): array {
            if (!$writeToOutput) {
                return ['ok' => false, 'message' => $msg, 'http_code' => $httpCode];
            }
            return ['json_error' => $msg];
        };

        // Load the Firestore txn helper
        $controller->load->library('Fee_firestore_txn', null, 'fsTxn');
        $controller->fsTxn->init($controller->firebase, $controller->fs, $controller->fs->schoolId(), $data['session_year']);

        $schoolFs = $controller->fs->schoolId();
        $session  = $data['session_year'];

        // ── Parse inputs ───────────────────────────────────────────────
        //  Precedence: $data override (parent flows) → POST (counter) → default.
        //  Counter passes neither receipt_no_input/payment_mode/amount/…, so all
        //  fall-throughs hit the original POST reads and behaviour is unchanged.
        $receiptNoInput = isset($data['receipt_no_input'])
            ? (string) $data['receipt_no_input']
            : trim((string) $controller->input->post('receiptNo'));

        $paymentMode    = isset($data['payment_mode'])
            ? (string) $data['payment_mode']
            : trim((string) ($controller->input->post('paymentMode') ?: 'Cash in Hand'));

        $userId         = (string) ($data['safe_user_id'] ?? '');

        $schoolFees     = isset($data['amount'])
            ? (float) $data['amount']
            : floatval(str_replace(',', '', $controller->input->post('schoolFees') ?? '0'));

        $discountFees   = isset($data['discount'])
            ? (float) $data['discount']
            : floatval(str_replace(',', '', $controller->input->post('discountAmount') ?? '0'));

        $fineAmount     = isset($data['fine'])
            ? (float) $data['fine']
            : floatval(str_replace(',', '', $controller->input->post('fineAmount') ?? '0'));

        $submitAmount   = isset($data['submit_amount'])
            ? (float) $data['submit_amount']
            : floatval(str_replace(',', '', $controller->input->post('submitAmount') ?? '0'));

        $reference      = $data['remarks']
            ?? ($controller->input->post('reference') ?: 'Fees Submitted');

        if (isset($data['selected_months'])) {
            $selectedMonths = $data['selected_months'];
        } else {
            $selectedMonths = $controller->input->post('selectedMonths') ?? [];
        }
        if (!is_array($selectedMonths)) $selectedMonths = explode(',', (string) $selectedMonths);
        $selectedMonths = array_values(array_filter(array_map('trim', $selectedMonths)));

        $MonthTotal = $controller->input->post('monthTotals') ?? [];
        $monthTotalsArray = [];
        foreach ((array) $MonthTotal as $md) {
            if (isset($md['month'], $md['total'])) {
                $monthTotalsArray[trim((string) $md['month'])] = floatval(str_replace(',', '', (string) $md['total']));
            }
        }

        if ($userId === '')          { return $_earlyError('Missing student ID'); }
        if (empty($selectedMonths))  { return $_earlyError('No months selected'); }
        if ($schoolFees <= 0)        { return $_earlyError('Fee amount must be > 0'); }

        // ── Load student from Firestore ────────────────────────────────
        $student = $controller->fsTxn->getStudent($userId);
        if (!$student) { return $_earlyError("Student '{$userId}' not found", 404); }

        $class   = trim((string) ($student['className'] ?? ''));
        $section = trim((string) ($student['section']   ?? ''));
        if ($class === '' || $section === '') {
            return $_earlyError("Cannot resolve class/section for '{$userId}'");
        }
        $studentName = (string) ($student['name'] ?? $userId);

        // ── Generate / validate receipt number (Firestore counter) ─────
        //  When the UI submits a user-supplied receipt number we still use
        //  it as-is, but we also push the counter forward so subsequent
        //  page peeks don't keep suggesting an already-used value.
        $receiptNo = '';
        if ($receiptNoInput !== '' && preg_match('/^\d+$/', $receiptNoInput)
            && !$controller->fsTxn->receiptExists($receiptNoInput)) {
            $receiptNo = $receiptNoInput;
            $controller->fsTxn->advanceCounterTo('receipt_seq', (int) $receiptNo);
        }
        if ($receiptNo === '') {
            $seq = $controller->fsTxn->nextCounter('receipt_seq');
            if ($seq <= 0) { return $_earlyError('Failed to generate receipt number. Please retry.', 500); }
            $receiptNo = (string) $seq;
        }
        $receiptKey = 'F' . $receiptNo;
        // txn_id precedence: explicit $data['txn_id'] (Razorpay generates its
        // own 'RZP_YmdHis_hex6' upstream so we must preserve it byte-exact)
        // → otherwise compose from txn_prefix + our 8-hex random.
        $txnId      = isset($data['txn_id']) && $data['txn_id'] !== ''
            ? (string) $data['txn_id']
            : (($data['txn_prefix'] ?? 'TXN_') . date('YmdHis') . '_' . bin2hex(random_bytes(4)));

        // ── Idempotency check (Firestore) ──────────────────────────────
        $idempHash = $controller->fsTxn->idempKey($userId, $receiptNo, $selectedMonths, $schoolFees);
        $idemp     = $controller->fsTxn->getIdempotency($idempHash);
        if (is_array($idemp)) {
            $st = (string) ($idemp['status'] ?? '');
            if ($st === 'success') {
                $dupReceiptNo = $idemp['receiptNo'] ?? $receiptNo;
                if (!$writeToOutput) {
                    return [
                        'ok' => true, 'http_code' => 200, 'idempotent' => true,
                        'message' => 'Fees already submitted (duplicate request).',
                        'data' => ['receipt_no' => $dupReceiptNo],
                    ];
                }
                $controller->output->set_output(json_encode([
                    'status' => 'success', 'idempotent' => true,
                    'message' => 'Fees already submitted (duplicate request).',
                    'receipt_no' => $dupReceiptNo,
                ]));
                return null;
            }
            if ($st === 'processing') {
                $age = time() - strtotime((string) ($idemp['startedAt'] ?? '2000-01-01'));
                if ($age < 120) {
                    if (!$writeToOutput) {
                        return [
                            'ok' => false, 'http_code' => 409,
                            'message' => 'This payment is currently being processed. Please wait.',
                        ];
                    }
                    $controller->output->set_output(json_encode([
                        'status' => 'error',
                        'message' => 'This payment is currently being processed. Please wait.',
                    ]));
                    return null;
                }
            }
        }
        $controller->fsTxn->setIdempotencyProcessing($idempHash, [
            'userId' => $userId, 'receiptNo' => $receiptNo, 'months' => $selectedMonths, 'amount' => $schoolFees,
        ]);

        // ── Lock acquisition (Firestore) ───────────────────────────────
        $lockToken = $controller->fsTxn->acquireLock($userId, 120);
        if ($lockToken === '') {
            $controller->fsTxn->deleteIdempotency($idempHash);
            if (!$writeToOutput) {
                return [
                    'ok' => false, 'http_code' => 423,
                    'message' => 'Another payment for this student is in progress. Please wait and retry.',
                ];
            }
            $controller->output->set_output(json_encode([
                'status' => 'error',
                'message' => 'Another payment for this student is in progress. Please wait and retry.',
            ]));
            return null;
        }

        // Abort helper: release lock + clear idempotency + emit error.
        //   Dual-mode: if $writeToOutput, writes counter-style JSON + returns null;
        //   otherwise returns the struct the caller-shim will render.
        $_abort = function (string $msg, int $httpCode = 400) use ($controller, $userId, $lockToken, $idempHash, $receiptNo, $writeToOutput): ?array {
            $controller->fsTxn->releaseLock($userId, $lockToken);
            $controller->fsTxn->deleteIdempotency($idempHash);
            $controller->fsTxn->deleteReceiptIndex($receiptNo);
            if (!$writeToOutput) {
                return ['ok' => false, 'message' => $msg, 'http_code' => $httpCode];
            }
            $controller->output->set_output(json_encode(['status' => 'error', 'message' => $msg]));
            return null;
        };

        // ── Reserve receipt number (Firestore receiptIndex) ────────────
        if (!$controller->fsTxn->reserveReceipt($receiptNo, $userId)) {
            return $_abort("Receipt #{$receiptNo} is currently reserved or used. Refresh and retry.", 409);
        }

        // ── Duplicate month guard (feeDemands is single source of truth) ─
        // Phase 15: dropped the legacy `students.monthFee` binary read.
        // Reasons:
        //   1. monthFee can't represent partial — a partial-paid month
        //      flagged 0 used to mean "still selectable" which was right;
        //      flagged 1 used to mean "fully paid" which we re-check via
        //      demands anyway, making the cache redundant.
        //   2. Cache drift: monthFee writes happen at end-of-payment; if
        //      a previous payment partially failed, the cache could
        //      diverge from demands and either falsely block or falsely
        //      allow a payment.
        // demands now drives the guard — same semantics, no cache risk.
        $demands = $controller->fsTxn->demandsForStudent($userId); // [docId => data]

        // Helper to extract the canonical month label from a demand
        // period (strips the trailing year/session token but PRESERVES
        // multi-word labels like "Yearly Fees").
        $periodToLabel = static function (string $period): string {
            return trim((string) preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', $period));
        };

        // Razorpay path sets skip_duplicate_month_guard=true to preserve its
        // pre-P3 behaviour: a gateway payment against an already-fully-paid
        // month was silently accepted and the overflow went to wallet. We
        // keep that semantics for now; a separate phase will decide whether
        // to tighten it.
        if (!($data['skip_duplicate_month_guard'] ?? false)) {
            foreach ($selectedMonths as $m) {
                // A month is "fully paid" iff EVERY demand for that month is
                // status='paid' AND balance <= 0.005. Bail early when true so
                // the cashier can't accidentally double-pay.
                $monthDemands = [];
                foreach ($demands as $d) {
                    if ($periodToLabel((string) ($d['period'] ?? '')) === $m) {
                        $monthDemands[] = $d;
                    }
                }
                if (!empty($monthDemands)) {
                    $allPaid = true;
                    foreach ($monthDemands as $d) {
                        if (($d['status'] ?? '') !== 'paid' || (float) ($d['balance'] ?? 0) > 0.005) {
                            $allPaid = false;
                            break;
                        }
                    }
                    if ($allPaid) {
                        return $_abort("Month {$m} is already fully paid. Refresh and retry.", 409);
                    }
                }
            }
        }

        // ── PAY-OLDER-FIRST guard ───────────────────────────────────────
        // Reject the payment if any earlier month is still unpaid /
        // partial. Mirrors the parent flow's guard so the two entry
        // points enforce the SAME ordering rule. Yearly fees use
        // period_key "2026-04" (start of session) so they're treated as
        // part of April for ordering purposes.
        if (!($data['skip_pay_older_first_guard'] ?? false)) {
            $selectedKeys = [];
            foreach ($demands as $d) {
                $pk = (string) ($d['period_key'] ?? '');
                $label = $periodToLabel((string) ($d['period'] ?? ''));
                if ($pk !== '' && in_array($label, $selectedMonths, true)) {
                    $selectedKeys[] = $pk;
                }
            }
            if (!empty($selectedKeys)) {
                sort($selectedKeys);
                $earliestSelected = $selectedKeys[0];
                foreach ($demands as $d) {
                    $pk     = (string) ($d['period_key'] ?? '');
                    $status = (string) ($d['status']     ?? 'unpaid');
                    $bal    = (float)  ($d['balance']    ?? 0);
                    if ($pk !== '' && $pk < $earliestSelected && $status !== 'paid' && $bal > 0.005) {
                        $olderPeriod = (string) ($d['period'] ?? $pk);
                        return $_abort("Please clear the earlier pending fees for {$olderPeriod} (Rs. " . number_format($bal, 2) . ") before paying this month.", 409);
                    }
                }
            }
        }

        // ── OVERPAYMENT guard (Phase 9 — wallet removed) ────────────────
        // With the wallet subsystem gone, any surplus has nowhere to go.
        // Compute total outstanding for the selected months and reject
        // if the caller is trying to pay more than that.
        $selectedSetForGuard = array_flip($selectedMonths);
        $yearlyExplicitGuard = isset($selectedSetForGuard['Yearly Fees']);
        $totalUnpaidSelected = 0.0;
        foreach ($demands as $d) {
            if ((string) ($d['status'] ?? 'unpaid') === 'paid') continue;
            $label = $periodToLabel((string) ($d['period'] ?? ''));
            if ($label === '' || !isset($selectedSetForGuard[$label])) continue;
            $isYearlyDemand = (string) ($d['period_type'] ?? $d['periodType'] ?? '') === 'yearly';
            if ($isYearlyDemand && !$yearlyExplicitGuard) continue;
            $bal = (float) ($d['balance'] ?? 0);
            if ($bal > 0) $totalUnpaidSelected += $bal;
        }
        $totalUnpaidSelected = round($totalUnpaidSelected, 2);
        $totalInput = round(((float) $schoolFees) + ((float) $submitAmount), 2);
        if ($totalInput > $totalUnpaidSelected + 0.005) {
            return $_abort(sprintf(
                'Amount Rs. %s exceeds total due Rs. %s for the selected months. Overpayment is not allowed.',
                number_format($totalInput, 2),
                number_format($totalUnpaidSelected, 2)
            ), 409);
        }

        // Shared scaffolding for BOTH write paths (batch + sequential).
        // Each path MUST populate $allocations / $allocatedMonths /
        // $paidMonthsFlags — the discount / finalize / response code
        // below reads these unconditionally.
        $batchMode       = $data['batch_mode'] ?? false;
        $batchCompleted  = false;
        $allocations     = [];
        $allocatedMonths = [];
        $paidMonthsFlags = [];

        if ($batchMode) {
            // ═══════════════════════════════════════════════════════════════
            //  BATCH PATH — mirrors Fee_management::_record_parent_payment_receipt
            //  L2470–2772 byte-for-byte. Compute allocation + monthFee in
            //  memory, build ONE batch payload, commit atomically.
            // ═══════════════════════════════════════════════════════════════

            // Breakdown (same computation as sequential; duplicated for
            // isolation so a future sequential edit doesn't silently
            // change the batch receipt shape).
            $breakdown = [];
            try {
                $struct  = $controller->fs->get('feeStructures', "{$schoolFs}_{$session}_{$class}_{$section}");
                $heads   = is_array($struct['feeHeads'] ?? null) ? $struct['feeHeads'] : [];
                $monthCount = count(array_filter($selectedMonths, fn($x) => $x !== 'Yearly Fees'));
                foreach ($heads as $h) {
                    $nm  = (string) ($h['name']   ?? '');
                    $amt = (float)  ($h['amount'] ?? 0);
                    $frq = (string) ($h['frequency'] ?? 'monthly');
                    if ($nm === '' || $amt <= 0) continue;
                    if ($frq === 'annual') {
                        if (in_array('Yearly Fees', $selectedMonths, true)) {
                            $breakdown[] = ['head' => $nm, 'amount' => number_format($amt, 2, '.', ''), 'frequency' => 'annual'];
                        }
                    } elseif ($monthCount > 0) {
                        $breakdown[] = ['head' => $nm, 'amount' => number_format($amt * $monthCount, 2, '.', ''), 'frequency' => 'monthly'];
                    }
                }
            } catch (\Exception $e) {
                log_message('error', "FeeCollectionService: batch breakdown build failed: " . $e->getMessage());
            }

            $date     = date('d-m-Y');
            $netTotal = round($schoolFees - $discountFees + $fineAmount, 2);
            $now      = date('c');

            // Pre-allocate (in memory, no writes).
            $remaining      = $schoolFees + $submitAmount;
            $selectedSet    = array_flip($selectedMonths);
            $yearlyExplicit = isset($selectedSet['Yearly Fees']);
            $unpaid = [];
            foreach ($demands as $did => $d) {
                if ((string) ($d['status'] ?? 'unpaid') === 'paid') continue;
                $label = $periodToLabel((string) ($d['period'] ?? ''));
                if ($label === '' || !isset($selectedSet[$label])) continue;
                $isYearlyDemand = (string) ($d['period_type'] ?? $d['periodType'] ?? '') === 'yearly';
                if ($isYearlyDemand && !$yearlyExplicit) continue;
                $unpaid[$did] = $d;
            }
            uasort($unpaid, fn($a, $b) => strcmp((string)($a['period_key'] ?? ''), (string)($b['period_key'] ?? '')));

            log_message('debug', "[FCS BATCH ALLOC] student={$userId} unpaid_pool=" . count($unpaid) . " remaining={$remaining}");
            $allocationsForBatch = [];
            foreach ($unpaid as $did => $d) {
                if ($remaining <= 0.005) break;
                $bal = (float) ($d['balance'] ?? 0);
                if ($bal <= 0) continue;
                $alloc = round(min($remaining, $bal), 2);
                $newPaid    = round((float) ($d['paid_amount'] ?? 0) + $alloc, 2);
                $newBalance = round($bal - $alloc, 2);
                $newStatus  = ($newBalance <= 0.005) ? 'paid' : 'partial';
                $allocationsForBatch[] = [
                    'demand_id' => $did,
                    'fee_head'  => $d['fee_head'] ?? '',
                    'period'    => $d['period']   ?? '',
                    'allocated' => $alloc,
                    'balance'   => $newBalance,
                    'status'    => $newStatus,
                    'new_paid'  => $newPaid, // internal, stripped before persist
                ];
                $remaining = round($remaining - $alloc, 2);
            }
            // Post-Phase-9: overpayment guard above ensures $remaining ≈ 0.

            // Allocated months (from periodToMonth — preserves "Yearly Fees").
            $allocatedMonthsBatch = [];
            foreach ($allocationsForBatch as $a) {
                $m = Fee_firestore_txn::periodToMonth((string) ($a['period'] ?? ''));
                if ($m !== '' && !in_array($m, $allocatedMonthsBatch, true)) {
                    $allocatedMonthsBatch[] = $m;
                }
            }
            $effectiveMonths = !empty($allocatedMonthsBatch) ? $allocatedMonthsBatch : $selectedMonths;

            // monthFee flags from in-memory post-allocation snapshot. Uses
            // the SAME regex as the sequential path's $periodToLabel so
            // batch and fallback produce identical keys.
            $demandStateAfter = $demands;
            foreach ($allocationsForBatch as $a) {
                $did = $a['demand_id'] ?? '';
                if ($did !== '' && isset($demandStateAfter[$did])) {
                    $demandStateAfter[$did]['status']      = $a['status'];
                    $demandStateAfter[$did]['balance']     = $a['balance'];
                    $demandStateAfter[$did]['paid_amount'] = $a['new_paid'];
                }
            }
            $byMonthBatch = [];
            foreach ($demandStateAfter as $d) {
                $p = $periodToLabel((string) ($d['period'] ?? ''));
                if ($p === '') continue;
                $byMonthBatch[$p][] = (string) ($d['status'] ?? 'unpaid');
            }
            $paidMonthsFlagsBatch = [];
            foreach ($byMonthBatch as $m => $statuses) {
                $allPaid = true;
                foreach ($statuses as $s) { if ($s !== 'paid') { $allPaid = false; break; } }
                $paidMonthsFlagsBatch[$m] = $allPaid ? 1 : 0;
            }
            $mergedMonthFee = !empty($paidMonthsFlagsBatch)
                ? array_replace(is_array($student['monthFee'] ?? null) ? $student['monthFee'] : [], $paidMonthsFlagsBatch)
                : null;

            $allocatedAmountSumBatch = round((float) $schoolFees, 2);

            // Build batch payload.
            $batchOps = [];

            // 1. Per-demand updates (merge=true; keeps period_key, fee_head).
            foreach ($allocationsForBatch as $a) {
                $batchOps[] = [
                    'op'         => 'set',
                    'collection' => 'feeDemands',
                    'docId'      => $a['demand_id'],
                    'merge'      => true,
                    'data'       => [
                        'paid_amount'  => $a['new_paid'],
                        'paidAmount'   => $a['new_paid'],
                        'balance'      => $a['balance'],
                        'status'       => $a['status'],
                        'last_receipt' => $receiptKey,
                        'updatedAt'    => $now,
                    ],
                ];
            }

            // 2. Receipt (full replace; final allocated_amount baked in —
            //    no post-patch needed). Post-Phase-9: no advance_credit.
            // $data['extra_receipt_fields'] lets a caller splice extra flat
            // fields into the doc (e.g. Razorpay webhook passes orderId +
            // paymentId so the receipt carries the gateway identifiers).
            $batchReceiptData = [
                'receiptNo'        => $receiptNo,
                'receiptKey'       => $receiptKey,
                'schoolId'         => $schoolFs,
                'session'          => $session,
                'studentId'        => $userId,
                'studentName'      => $studentName,
                'className'        => $class,
                'section'          => $section,
                'fatherName'       => (string) ($student['fatherName'] ?? ''),
                'amount'           => $schoolFees,
                'input_amount'     => $schoolFees,
                'inputAmount'      => $schoolFees,
                'allocated_amount' => $allocatedAmountSumBatch,
                'allocatedAmount'  => $allocatedAmountSumBatch,
                'discount'         => $discountFees,
                'fine'             => $fineAmount,
                'netAmount'        => $netTotal,
                'paymentMode'      => $paymentMode,
                'remarks'          => $reference,
                'feeMonths'        => $effectiveMonths,
                'allocatedMonths'  => $allocatedMonthsBatch,
                'feeBreakdown'     => $breakdown,
                'date'             => $date,
                'txnId'            => $txnId,
                'collectedBy'      => $data['collected_by'] ?? $data['admin_name'] ?? 'System',
                'createdAt'        => $now,
                'updatedAt'        => $now,
            ];
            if (is_array($data['extra_receipt_fields'] ?? null)) {
                $batchReceiptData = array_merge($batchReceiptData, $data['extra_receipt_fields']);
            }
            $batchOps[] = [
                'op'         => 'set',
                'collection' => 'feeReceipts',
                'docId'      => "{$schoolFs}_{$receiptKey}",
                'merge'      => false,
                'data'       => $batchReceiptData,
            ];

            // 3. Receipt index (merge, clears reservation flag).
            $batchOps[] = [
                'op'         => 'set',
                'collection' => 'feeReceiptIndex',
                'docId'      => "{$schoolFs}_{$session}_{$receiptNo}",
                'merge'      => true,
                'data'       => [
                    'schoolId'   => $schoolFs,
                    'session'    => $session,
                    'receiptNo'  => $receiptNo,
                    'reserved'   => false,
                    'reservedAt' => '',
                    'date'       => $date,
                    'userId'     => $userId,
                    'className'  => $class,
                    'section'    => $section,
                    'amount'     => $netTotal,
                    'txnId'      => $txnId,
                    'updatedAt'  => $now,
                ],
            ];

            // 4. Receipt allocation (full doc; strip internal new_paid field).
            $allocationsForDoc = array_map(function ($a) {
                unset($a['new_paid']);
                return $a;
            }, $allocationsForBatch);
            $batchOps[] = [
                'op'         => 'set',
                'collection' => 'feeReceiptAllocations',
                'docId'      => "{$schoolFs}_{$session}_{$receiptKey}",
                'merge'      => false,
                'data'       => [
                    'schoolId'      => $schoolFs,
                    'session'       => $session,
                    'receiptKey'    => $receiptKey,
                    'receiptNo'     => $receiptNo,
                    'studentId'     => $userId,
                    'studentName'   => $studentName,
                    'className'     => $class,
                    'section'       => $section,
                    'totalAmount'   => round($schoolFees, 2),
                    'discount'      => round($discountFees, 2),
                    'fine'          => round($fineAmount, 2),
                    'netReceived'   => $netTotal,
                    'allocations'   => $allocationsForDoc,
                    'paymentMode'   => $paymentMode,
                    'date'          => $date,
                    'createdBy'     => $data['created_by'] ?? $data['admin_id'] ?? '',
                    'txnId'         => $txnId,
                    'updatedAt'     => $now,
                ],
            ];

            // 5. Student monthFee (merge).
            if ($mergedMonthFee !== null) {
                $batchOps[] = [
                    'op'         => 'set',
                    'collection' => 'students',
                    'docId'      => "{$schoolFs}_{$userId}",
                    'merge'      => true,
                    'data'       => [
                        'monthFee'  => $mergedMonthFee,
                        'updatedAt' => $now,
                    ],
                ];
            }

            log_message('debug', "[FCS BATCH COMMIT] ops=" . count($batchOps) . " receipt={$receiptKey}");
            $batchCompleted = (bool) $controller->firebase->firestoreCommitBatch($batchOps);
            log_message('debug', "[FCS BATCH COMMIT] ok=" . ($batchCompleted ? 'true' : 'false'));

            if ($batchCompleted) {
                // Expose the same variable shape the sequential path
                // produces so downstream response code works identically.
                $allocations     = $allocationsForDoc;
                $allocatedMonths = $allocatedMonthsBatch;
                $paidMonthsFlags = $paidMonthsFlagsBatch;
            } else {
                log_message('warning', "[FCS BATCH FALLBACK] receipt={$receiptKey} — commit failed, falling through to sequential writes");
                // Reset shared vars; sequential path will repopulate.
                $allocations     = [];
                $allocatedMonths = [];
                $paidMonthsFlags = [];
            }
        }

        if (!$batchCompleted) {
            // ═══════════════════════════════════════════════════════════════
            //  SEQUENTIAL PATH — unchanged from P1/P2. Runs for counter,
            //  parent-wallet, and as a fallback when batch commit fails.
            // ═══════════════════════════════════════════════════════════════

            // ── Mark pending-write (Firestore safety marker) ───────────────
            $controller->fsTxn->markPending($receiptKey, [
                'userId' => $userId, 'amount' => $schoolFees, 'months' => $selectedMonths, 'txnId' => $txnId,
            ]);

            // ── Compute per-head breakdown from fee structure ──────────────
            $breakdown = [];
            try {
                $struct  = $controller->fs->get('feeStructures', "{$schoolFs}_{$session}_{$class}_{$section}");
                $heads   = is_array($struct['feeHeads'] ?? null) ? $struct['feeHeads'] : [];
                foreach ($heads as $h) {
                    $nm  = (string) ($h['name']   ?? '');
                    $amt = (float)  ($h['amount'] ?? 0);
                    $frq = (string) ($h['frequency'] ?? 'monthly');
                    if ($nm === '' || $amt <= 0) continue;
                    // Monthly heads apply per selected month (excluding Yearly Fees tag);
                    // yearly heads apply once if Yearly Fees is in selection.
                    if ($frq === 'annual') {
                        if (in_array('Yearly Fees', $selectedMonths, true)) {
                            $breakdown[] = ['head' => $nm, 'amount' => number_format($amt, 2, '.', ''), 'frequency' => 'annual'];
                        }
                    } else {
                        $monthCount = count(array_filter($selectedMonths, fn($x) => $x !== 'Yearly Fees'));
                        if ($monthCount > 0) {
                            $breakdown[] = ['head' => $nm, 'amount' => number_format($amt * $monthCount, 2, '.', ''), 'frequency' => 'monthly'];
                        }
                    }
                }
            } catch (\Exception $e) {
                log_message('error', "submit_fees: breakdown build failed: " . $e->getMessage());
            }

            $date     = date('d-m-Y');
            $netTotal = round($schoolFees - $discountFees + $fineAmount, 2);

        // ── 1. Write the canonical fee receipt ─────────────────────────
        // Receipt fields (post-Phase-11 standardization):
        //   amount           — back-compat alias of input_amount
        //   input_amount     — what the cashier entered (excl. fine)
        //   allocated_amount — sum of demand allocations (patched after step 3)
        // Post-Phase-9: overpayment is rejected at the guard above, so
        // allocated_amount always equals input_amount. No advance_credit.
        $receiptDoc = [
            'receiptNo'        => $receiptNo,
            'studentId'        => $userId,
            'studentName'      => $studentName,
            'className'        => $class,
            'section'          => $section,
            'fatherName'       => (string) ($student['fatherName'] ?? ''),
            'amount'           => $schoolFees,
            'input_amount'     => $schoolFees,
            'inputAmount'      => $schoolFees,    // dual-emit for Android
            'allocated_amount' => 0.0,            // patched in step 3
            'allocatedAmount'  => 0.0,
            'discount'         => $discountFees,
            'fine'             => $fineAmount,
            'netAmount'        => $netTotal,
            'paymentMode'      => $paymentMode,
            'remarks'          => $reference,
            'feeMonths'        => $selectedMonths,
            'feeBreakdown'     => $breakdown,
            'monthTotals'      => $monthTotalsArray,
            'date'             => $date,
            'txnId'            => $txnId,
            'collectedBy'      => $data['collected_by'] ?? $data['admin_name'] ?? 'System',
            'createdAt'        => date('c'),
        ];
        // Splice caller-supplied extras (e.g. Razorpay webhook's orderId +
        // paymentId). Counter and parent-wallet pass nothing → no change.
        if (is_array($data['extra_receipt_fields'] ?? null)) {
            $receiptDoc = array_merge($receiptDoc, $data['extra_receipt_fields']);
        }
        if (!$controller->fsTxn->writeFeeReceipt($receiptKey, $receiptDoc)) {
            return $_abort('Failed to record fee receipt. No data written.', 500);
        }

        // ── 2. Finalise receipt index ──────────────────────────────────
        $controller->fsTxn->finalizeReceiptIndex($receiptNo, [
            'date'      => $date,
            'userId'    => $userId,
            'className' => $class,
            'section'   => $section,
            'amount'    => $netTotal,
            'txnId'     => $txnId,
        ]);

        // ── 3. Allocate to demands (oldest unpaid first) ───────────────
        //
        // CRITICAL: only allocate within demands whose month appears in
        // $selectedMonths. The previous version filtered by status only
        // ("any unpaid demand"), which silently auto-paid Yearly fees +
        // older months when admin selected a single later month — money
        // landed on demands the admin never picked. Parent flow already
        // enforces this filter; admin must match for the two paths to
        // produce the same allocation result.
        $allocations   = [];
        $remaining     = $schoolFees + $submitAmount;
        $selectedSet   = array_flip($selectedMonths);
        $yearlyExplicit = isset($selectedSet['Yearly Fees']);

        log_message('debug', '[ALLOC START] user=' . $userId . ' selected=' . json_encode($selectedMonths) . ' amount=' . $remaining);
        $unpaid = [];
        foreach ($demands as $did => $d) {
            $st = (string) ($d['status'] ?? 'unpaid');
            if ($st === 'paid') continue;
            $label = $periodToLabel((string) ($d['period'] ?? ''));
            if ($label === '' || !isset($selectedSet[$label])) continue;
            // Defensive: yearly demands must be EXPLICITLY selected. The
            // selectedSet filter above already enforces this via the
            // "Yearly Fees" label, but period_type ("yearly"|"monthly") is
            // a second authoritative gate that survives any future label
            // drift. period_type is stamped by Fee_firestore_txn::writeDemand.
            $isYearlyDemand = (string) ($d['period_type'] ?? $d['periodType'] ?? '') === 'yearly';
            if ($isYearlyDemand && !$yearlyExplicit) continue;
            $unpaid[$did] = $d;
        }
        uasort($unpaid, fn($a, $b) => strcmp((string)($a['period_key'] ?? ''), (string)($b['period_key'] ?? '')));
        log_message('debug', '[ALLOC POOL] count=' . count($unpaid) . ' demand_ids=' . implode(',', array_keys($unpaid)));

        foreach ($unpaid as $did => $d) {
            if ($remaining <= 0.005) break;
            $bal   = (float) ($d['balance'] ?? 0);
            if ($bal <= 0) continue;
            $alloc = round(min($remaining, $bal), 2);
            $newPaid    = round((float) ($d['paid_amount'] ?? 0) + $alloc, 2);
            $newBalance = round($bal - $alloc, 2);
            $newStatus  = ($newBalance <= 0.005) ? 'paid' : 'partial';
            $controller->fsTxn->updateDemand($did, [
                'paid_amount'  => $newPaid,
                'balance'      => $newBalance,
                'status'       => $newStatus,
                'last_receipt' => $receiptKey,
            ]);
            $allocations[] = [
                'demand_id' => $did,
                'fee_head'  => $d['fee_head'] ?? '',
                'period'    => $d['period']   ?? '',
                'allocated' => $alloc,
                'balance'   => $newBalance,
                'status'    => $newStatus,
            ];
            $remaining = round($remaining - $alloc, 2);
        }
        // Post-Phase-9: overpayment guard ensures $remaining ≈ 0.

        $controller->fsTxn->writeReceiptAllocation($receiptKey, [
            'receiptNo'     => $receiptNo,
            'studentId'     => $userId,
            'studentName'   => $studentName,
            'className'     => $class,
            'section'       => $section,
            'totalAmount'   => round($schoolFees, 2),
            'discount'      => round($discountFees, 2),
            'fine'          => round($fineAmount, 2),
            'netReceived'   => $netTotal,
            'allocations'   => $allocations,
            'paymentMode'   => $paymentMode,
            'date'          => $date,
            'createdBy'     => $data['created_by'] ?? $data['admin_id'] ?? '',
            'txnId'         => $txnId,
        ]);

        // ── 3b. Consistency: update receipt feeMonths + allocation totals ──
        //  The user may have selected "June" but the system allocated to April
        //  (oldest-first). The receipt must reflect truth, not selection.
        //
        //  Patch allocated_amount = sum of allocations actually applied to
        //  demands. Post-Phase-9 this equals input_amount.
        $allocatedAmountSum = 0.0;
        foreach ($allocations as $a) {
            $allocatedAmountSum += (float) ($a['allocated'] ?? 0);
        }
        $allocatedAmountSum = round($allocatedAmountSum, 2);

        $allocatedMonths = [];
        foreach ($allocations as $a) {
            // periodToMonth preserves "Yearly Fees" — never truncate.
            $m = Fee_firestore_txn::periodToMonth((string) ($a['period'] ?? ''));
            if ($m !== '' && !in_array($m, $allocatedMonths, true)) {
                $allocatedMonths[] = $m;
            }
        }

        // PATCH (merge=true) — using the full-replace writeFeeReceipt() here
        // previously nuked studentId/amount/paymentMode/etc on the doc,
        // leaving an orphan receipt invisible to admin's payment-history
        // query. updateFeeReceipt is merge=true.
        $patch = [
            'allocated_amount' => $allocatedAmountSum,
            'allocatedAmount'  => $allocatedAmountSum,
        ];
        if (!empty($allocatedMonths)) {
            $patch['feeMonths']       = $allocatedMonths;
            $patch['allocatedMonths'] = $allocatedMonths;
        }
        $controller->fsTxn->updateFeeReceipt($receiptKey, $patch);

        // ── 4. Month-fee flags on the student doc ──────────────────────
        $paidMonthsFlags = [];
        // Recompute from the just-updated demands view.
        $demandsAfter = $controller->fsTxn->demandsForStudent($userId);
        $byMonth = [];
        foreach ($demandsAfter as $d) {
            // Strip trailing year/session token but PRESERVE multi-word
            // labels like "Yearly Fees" — explode(' ',$p)[0] used to
            // truncate "Yearly Fees 2026-27" to "Yearly", which then
            // never matched the admin's fetch_months reader looking
            // for "Yearly Fees" key. Same fix already shipped in the
            // parent flow (Fee_management::_periodToMonthFeeKey).
            $rawPeriod = (string) ($d['period'] ?? '');
            $p = trim((string) preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', $rawPeriod));
            if ($p === '') continue;
            $byMonth[$p][] = (string) ($d['status'] ?? 'unpaid');
        }
        foreach ($byMonth as $m => $statuses) {
            $allPaid = true;
            foreach ($statuses as $s) { if ($s !== 'paid') { $allPaid = false; break; } }
            $paidMonthsFlags[$m] = $allPaid ? 1 : 0;
        }
        // Fallback for schools without demands: mark each fully-paid selected month.
        if (empty($demandsAfter)) {
            $budget = $schoolFees + $submitAmount;
            foreach ($selectedMonths as $m) {
                $need = (float) ($monthTotalsArray[$m] ?? 0);
                if ($need > 0 && $budget >= $need) {
                    $paidMonthsFlags[$m] = 1;
                    $budget -= $need;
                }
            }
        }
        if (!empty($paidMonthsFlags)) {
            $existingMap = is_array($student['monthFee'] ?? null) ? $student['monthFee'] : [];
            $newMap = array_replace($existingMap, $paidMonthsFlags);
            $controller->fsTxn->updateStudent($userId, ['monthFee' => $newMap]);
        }
        } // end if (!$batchCompleted) — sequential path

        // ── 5. (Removed in Phase 9) Advance-balance / wallet updates ──
        // The wallet subsystem has been removed. Overpayment is rejected
        // at the guard above, so there is no leftover to credit and no
        // caller-supplied wallet debit to honour.

        // ── 6. Discount update (if any) ────────────────────────────────
        if ($discountFees > 0.005) {
            $controller->fsTxn->updateDiscount($userId, [
                'totalDiscount' => $discountFees,
                'applied'       => [
                    'type'       => 'receipt',
                    'amount'     => $discountFees,
                    'applied_at' => date('c'),
                    'applied_by' => $data['collected_by'] ?? $data['admin_name'] ?? 'System',
                    'receipt'    => $receiptKey,
                ],
            ], [
                'studentName' => $studentName,
                'className'   => $class,
                'section'     => $section,
            ]);
        }

        // ── 7. Finalise idempotency + release lock + clear pending ─────
        //   Critical path: keep these BEFORE the response so a quick
        //   retry can't double-submit and the lock is released for the
        //   next payment. All three are single-doc Firestore writes
        //   (~100-300ms total).
        $controller->fsTxn->setIdempotencySuccess($idempHash, $receiptNo);
        $controller->fsTxn->clearPending($receiptKey);
        $controller->fsTxn->releaseLock($userId, $lockToken);

        // ── Send response NOW ──────────────────────────────────────────
        // Phase 23: defaulter recompute + accounting journal are slow
        // (~2-4 s of additional Firestore round-trips) and the cashier
        // doesn't need them to be current for the success response —
        // - Receipt + demands + monthFee are already committed
        // - Defaulter dashboard updates via its own Firestore listener
        // - Accounting reports run async / on demand
        // So we flush the response and run the slow bits in a
        // shutdown handler. Mirrors the parent Razorpay flow's
        // _record_parent_payment_receipt deferral pattern.
        $monthsMarked = array_keys(array_filter($paidMonthsFlags, fn($v) => $v === 1));
        $successReturn = null;
        if (!$writeToOutput) {
            $successReturn = [
                'ok' => true,
                'http_code' => 200,
                'message' => 'Fees submitted successfully.',
                'data' => [
                    'receipt_no'       => $receiptNo,
                    'receipt_key'      => $receiptKey,
                    'txn_id'           => $txnId,
                    'user_id'          => $userId,
                    'amount'           => $netTotal,
                    'amount_paid'      => $schoolFees,
                    'allocations'      => $allocations,
                    'allocated_months' => $allocatedMonths,
                    'months_marked'    => $monthsMarked,
                ],
            ];
        } else {
            $controller->output->set_output(json_encode([
                'status'           => 'success',
                'message'          => 'Fees submitted successfully.',
                'receipt_no'       => $receiptNo,
                'txn_id'           => $txnId,
                'user_id'          => $userId,
                'amount'           => $netTotal,
                'allocations'      => $allocations,
                'months_marked'    => $monthsMarked,
            ]));
        }

        // ── 8 + 9 deferred. Capture the values we need by-value into
        //    the closure (no $this references that might be torn down).
        $feeDefaulter = $controller->feeDefaulter ?? null;
        $firebase     = $controller->firebase;
        $schoolName   = $data['school_name'];
        $sessionYear  = $session;
        $adminId      = $data['admin_id'] ?? 'system';
        $opsAcctPayload = [
            'school_name'  => $schoolName,
            'session_year' => $sessionYear,
            'date'         => date('Y-m-d'),
            'amount'       => $netTotal,
            'payment_mode' => strtolower($paymentMode),
            'receipt_no'   => $receiptKey,
            'student_name' => $studentName,
            'student_id'   => $userId,
            'class'        => "{$class} {$section}",
            'admin_id'     => $adminId,
        ];
        $defaulterCtx = [
            'studentName' => $studentName,
            'className'   => $class,
            'section'     => $section,
            'schoolFs'    => $schoolFs,
        ];
        $ci = $controller; // for library loading inside the closure
        $deferDefaulter  = $data['defer_defaulter']  ?? true;
        $deferAccounting = $data['defer_accounting'] ?? true;

        if ($deferDefaulter || $deferAccounting) {
            register_shutdown_function(function () use (
                $ci, $feeDefaulter, $firebase, $schoolName, $sessionYear, $adminId,
                $opsAcctPayload, $defaulterCtx, $userId, $receiptKey,
                $deferDefaulter, $deferAccounting
            ) {
                // Flush the response so the cashier sees "Submitted" before
                // these slow operations begin.
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }

                // Defaulter recompute + sync.
                if ($deferDefaulter && $feeDefaulter !== null) {
                    try {
                        $defaulterStatus = $feeDefaulter->updateDefaulterStatus($userId);
                        $ci->load->library('Fee_firestore_sync', null, 'fsSyncDeferred');
                        $ci->fsSyncDeferred->init($firebase, $defaulterCtx['schoolFs'], $sessionYear);
                        $ci->fsSyncDeferred->syncDefaulterStatus(
                            $userId, $defaulterStatus,
                            $defaulterCtx['studentName'], $defaulterCtx['className'], $defaulterCtx['section']
                        );
                        log_message('debug', "[SUBMIT DEFAULTER SYNCED] student={$userId} (post-response)");
                    } catch (\Exception $e) {
                        log_message('error', "submit_fees: deferred defaulter sync failed for {$userId}: " . $e->getMessage());
                    }
                }

                // Accounting journal (still uses the same pipeline).
                if ($deferAccounting) {
                    try {
                        $ci->load->library('Operations_accounting', null, 'opsAcctDeferred');
                        $ci->opsAcctDeferred->init($firebase, $schoolName, $sessionYear, $adminId, $ci);
                        $ci->opsAcctDeferred->create_fee_journal($opsAcctPayload);
                        log_message('debug', "[SUBMIT JOURNAL POSTED] receipt={$receiptKey} (post-response)");
                    } catch (\Exception $e) {
                        log_message('error', "submit_fees: deferred accounting journal failed for {$receiptKey}: " . $e->getMessage());
                    }
                }
            });
        }

        return $successReturn;
    }
}
