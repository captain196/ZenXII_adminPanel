<?php
defined('BASEPATH') or exit('No direct script access allowed');

class FeeCollectionService
{
    /**
     * Move of Fees::submit_fees() body. No behavioural changes.
     *
     * Returns null when the response has already been written to
     * $controller->output (every original path that used set_output()
     * directly or went through the $_abort closure), or
     * ['json_error' => 'message'] when the original path called
     * $this->json_error($message) — the controller renders that via its
     * protected json_error().
     */
    public function submit($controller, $data = null): ?array
    {
        $data = is_array($data) ? $data : [];

        // Load the Firestore txn helper
        $controller->load->library('Fee_firestore_txn', null, 'fsTxn');
        $controller->fsTxn->init($controller->firebase, $controller->fs, $controller->fs->schoolId(), $data['session_year']);

        $schoolFs = $controller->fs->schoolId();
        $session  = $data['session_year'];

        // ── Parse inputs ───────────────────────────────────────────────
        $receiptNoInput = trim((string) $controller->input->post('receiptNo'));
        $paymentMode    = trim((string) ($controller->input->post('paymentMode') ?: 'Cash in Hand'));
        $userId         = (string) ($data['safe_user_id'] ?? '');

        $schoolFees     = floatval(str_replace(',', '', $controller->input->post('schoolFees')     ?? '0'));
        $discountFees   = floatval(str_replace(',', '', $controller->input->post('discountAmount') ?? '0'));
        $fineAmount     = floatval(str_replace(',', '', $controller->input->post('fineAmount')     ?? '0'));
        $submitAmount   = floatval(str_replace(',', '', $controller->input->post('submitAmount')   ?? '0'));
        $reference      = $controller->input->post('reference') ?: 'Fees Submitted';

        $selectedMonths = $controller->input->post('selectedMonths') ?? [];
        if (!is_array($selectedMonths)) $selectedMonths = explode(',', (string) $selectedMonths);
        $selectedMonths = array_values(array_filter(array_map('trim', $selectedMonths)));

        $MonthTotal = $controller->input->post('monthTotals') ?? [];
        $monthTotalsArray = [];
        foreach ((array) $MonthTotal as $md) {
            if (isset($md['month'], $md['total'])) {
                $monthTotalsArray[trim((string) $md['month'])] = floatval(str_replace(',', '', (string) $md['total']));
            }
        }

        if ($userId === '')          { return ['json_error' => 'Missing student ID']; }
        if (empty($selectedMonths))  { return ['json_error' => 'No months selected']; }
        if ($schoolFees <= 0)        { return ['json_error' => 'Fee amount must be > 0']; }

        // ── Load student from Firestore ────────────────────────────────
        $student = $controller->fsTxn->getStudent($userId);
        if (!$student) { return ['json_error' => "Student '{$userId}' not found"]; }

        $class   = trim((string) ($student['className'] ?? ''));
        $section = trim((string) ($student['section']   ?? ''));
        if ($class === '' || $section === '') {
            return ['json_error' => "Cannot resolve class/section for '{$userId}'"];
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
            if ($seq <= 0) { return ['json_error' => 'Failed to generate receipt number. Please retry.']; }
            $receiptNo = (string) $seq;
        }
        $receiptKey = 'F' . $receiptNo;
        $txnId      = 'TXN_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));

        // ── Idempotency check (Firestore) ──────────────────────────────
        $idempHash = $controller->fsTxn->idempKey($userId, $receiptNo, $selectedMonths, $schoolFees);
        $idemp     = $controller->fsTxn->getIdempotency($idempHash);
        if (is_array($idemp)) {
            $st = (string) ($idemp['status'] ?? '');
            if ($st === 'success') {
                $controller->output->set_output(json_encode([
                    'status' => 'success', 'idempotent' => true,
                    'message' => 'Fees already submitted (duplicate request).',
                    'receipt_no' => $idemp['receiptNo'] ?? $receiptNo,
                ]));
                return null;
            }
            if ($st === 'processing') {
                $age = time() - strtotime((string) ($idemp['startedAt'] ?? '2000-01-01'));
                if ($age < 120) {
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
            $controller->output->set_output(json_encode([
                'status' => 'error',
                'message' => 'Another payment for this student is in progress. Please wait and retry.',
            ]));
            return null;
        }

        // Abort helper: release lock + clear idempotency + reply error.
        $_abort = function (string $msg) use ($controller, $userId, $lockToken, $idempHash, $receiptNo) {
            $controller->fsTxn->releaseLock($userId, $lockToken);
            $controller->fsTxn->deleteIdempotency($idempHash);
            $controller->fsTxn->deleteReceiptIndex($receiptNo);
            $controller->output->set_output(json_encode(['status' => 'error', 'message' => $msg]));
        };

        // ── Reserve receipt number (Firestore receiptIndex) ────────────
        if (!$controller->fsTxn->reserveReceipt($receiptNo, $userId)) {
            $_abort("Receipt #{$receiptNo} is currently reserved or used. Refresh and retry.");
            return null;
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
                    $_abort("Month {$m} is already fully paid. Refresh and retry.");
                    return null;
                }
            }
        }

        // ── PAY-OLDER-FIRST guard ───────────────────────────────────────
        // Reject the payment if any earlier month is still unpaid /
        // partial. Mirrors the parent flow's guard
        // (Fee_management::_record_parent_payment_receipt) so the two
        // entry points enforce the SAME ordering rule. Yearly fees use
        // period_key "2026-04" (start of session) so they're treated as
        // part of April for ordering purposes — exactly the user's
        // intended behaviour.
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
                    $_abort("Please clear the earlier pending fees for {$olderPeriod} (Rs. " . number_format($bal, 2) . ") before paying this month.");
                    return null;
                }
            }
        }

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
        //   advance_credit   — leftover that went to the wallet (patched after step 3)
        // Reports must use allocated_amount for "revenue actually collected
        // against demands" — input_amount can include overpayment that
        // landed in the wallet, not the dues ledger.
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
            'advance_credit'   => 0.0,            // patched in step 3
            'advanceCredit'    => 0.0,
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
            'collectedBy'      => $data['admin_name'] ?? 'System',
            'createdAt'        => date('c'),
        ];
        if (!$controller->fsTxn->writeFeeReceipt($receiptKey, $receiptDoc)) {
            $_abort('Failed to record fee receipt. No data written.');
            return null;
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
        $advanceCredit = round(max(0, $remaining), 2);

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
            'advanceCredit' => $advanceCredit,
            'paymentMode'   => $paymentMode,
            'date'          => $date,
            'createdBy'     => $data['admin_id'] ?? '',
            'txnId'         => $txnId,
        ]);

        // ── 3b. Consistency: update receipt feeMonths + allocation totals ──
        //  The user may have selected "June" but the system allocated to April
        //  (oldest-first). The receipt must reflect truth, not selection.
        //
        //  We also patch the standardized totals here:
        //    allocated_amount = sum of allocations actually applied to demands
        //    advance_credit   = leftover that flowed to the wallet
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
            'advance_credit'   => $advanceCredit,
            'advanceCredit'    => $advanceCredit,
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

        // ── 5. Advance balance update ──────────────────────────────────
        // The wallet after a receipt = leftover of (existing wallet + new pay)
        // after demand allocation. $remaining (= $advanceCredit) IS that
        // leftover — it already includes the existing wallet because the
        // allocation loop started with `$remaining = $schoolFees + $submitAmount`.
        // So we SET the wallet to $advanceCredit, not add to the old value.
        //
        // Touch the doc if either the wallet was applied this receipt
        // ($submitAmount > 0) or if new advance was created ($advanceCredit > 0).
        if ($advanceCredit > 0.005 || $submitAmount > 0.005) {
            $controller->fsTxn->setAdvanceBalance($userId, $advanceCredit, [
                'lastReceipt' => $receiptKey,
                'studentName' => $studentName,
                'className'   => $class,
                'section'     => $section,
            ]);
        }

        // ── 6. Discount update (if any) ────────────────────────────────
        if ($discountFees > 0.005) {
            $controller->fsTxn->updateDiscount($userId, [
                'totalDiscount' => $discountFees,
                'applied'       => [
                    'type'       => 'receipt',
                    'amount'     => $discountFees,
                    'applied_at' => date('c'),
                    'applied_by' => $data['admin_name'] ?? 'System',
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
        $controller->output->set_output(json_encode([
            'status'           => 'success',
            'message'          => 'Fees submitted successfully.',
            'receipt_no'       => $receiptNo,
            'txn_id'           => $txnId,
            'user_id'          => $userId,
            'amount'           => $netTotal,
            'advance_credit'   => $advanceCredit,
            'allocations'      => $allocations,
            'months_marked'    => array_keys(array_filter($paidMonthsFlags, fn($v) => $v === 1)),
        ]));

        // ── 8 + 9 deferred. Capture the values we need by-value into
        //    the closure (no $this references that might be torn down).
        $feeDefaulter = $controller->feeDefaulter;
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

        register_shutdown_function(function () use (
            $ci, $feeDefaulter, $firebase, $schoolName, $sessionYear, $adminId,
            $opsAcctPayload, $defaulterCtx, $userId, $receiptKey
        ) {
            // Flush the response so the cashier sees "Submitted" before
            // these slow operations begin.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            // Defaulter recompute + sync.
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

            // Accounting journal (still uses the same pipeline).
            try {
                $ci->load->library('Operations_accounting', null, 'opsAcctDeferred');
                $ci->opsAcctDeferred->init($firebase, $schoolName, $sessionYear, $adminId, $ci);
                $ci->opsAcctDeferred->create_fee_journal($opsAcctPayload);
                log_message('debug', "[SUBMIT JOURNAL POSTED] receipt={$receiptKey} (post-response)");
            } catch (\Exception $e) {
                log_message('error', "submit_fees: deferred accounting journal failed for {$receiptKey}: " . $e->getMessage());
            }
        });

        return null;
    }
}
