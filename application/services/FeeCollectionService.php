<?php
defined('BASEPATH') or exit('No direct script access allowed');

class FeeCollectionService
{
    /**
     * Unified fee-collection pipeline.
     *
     *   P1 (current): widened $data contract so parent flows (Razorpay)
     *   can reach the same service without touching counter behaviour.
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

        // ── Perf instrumentation (temporary) ───────────────────────────
        //  Captures wall-clock ms at each phase of submit. On return we
        //  log a single JSON line to help identify where the 44s spent.
        //  Remove after the perf investigation is closed.
        $__t0 = microtime(true);
        $__times = [];
        $__mark = function (string $name) use (&$__t0, &$__times) {
            $__times[$name] = (int) round((microtime(true) - $__t0) * 1000);
        };

        // Unified early-error helper (for input-validation failures BEFORE
        // the lock + idempotency records are taken). Preserves the counter's
        // existing ['json_error' => …] contract when writing to output.
        $_earlyError = function (string $msg, int $httpCode = 400) use ($writeToOutput, &$__times): array {
            // Even on early errors, emit the timing so we see if the slow
            // phase was pre-lock (e.g., student load, counter contention).
            log_message('error', 'FC_TIMING(err): ' . json_encode($__times));
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

        $__mark('inputs_parsed');

        // ── Phase 7A: release CI's session lock now. submit_fees has no
        //    more session reads/writes after this line; keeping the file
        //    lock held would serialise every other admin request behind
        //    the ~5 s fee submit on the same session. No-op if CI's
        //    session wasn't loaded (some POST paths auth via ID token).
        if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        // ── Load student from Firestore ────────────────────────────────
        $student = $controller->fsTxn->getStudent($userId);
        $__mark('student_loaded');
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
        // Track whether we auto-claimed vs. accepted a user-supplied
        // receipt number. Only auto-claimed numbers are safe to release
        // on abort — if the cashier typed in "R000007" and we push it
        // back to the counter, a concurrent claim for 7 would collide.
        $autoClaimedSeq = 0;
        if ($receiptNo === '') {
            $seq = $controller->fsTxn->nextCounter('receipt_seq');
            if ($seq <= 0) { return $_earlyError('Failed to generate receipt number. Please retry.', 500); }
            $receiptNo = (string) $seq;
            $autoClaimedSeq = $seq;
        }
        $receiptKey = 'F' . $receiptNo;
        $__mark('counter_claimed');
        // txn_id precedence: explicit $data['txn_id'] (Razorpay generates its
        // own 'RZP_YmdHis_hex6' upstream so we must preserve it byte-exact)
        // → otherwise compose from txn_prefix + our 8-hex random.
        $txnId      = isset($data['txn_id']) && $data['txn_id'] !== ''
            ? (string) $data['txn_id']
            : (($data['txn_prefix'] ?? 'TXN_') . date('YmdHis') . '_' . bin2hex(random_bytes(4)));

        // ── Idempotency check + claim-doc preload (Firestore) ──────────
        $idempHash = $controller->fsTxn->idempKey($userId, $receiptNo, $selectedMonths, $schoolFees);

        // Phase 7B: parallel-fetch FOUR docs every submit needs to know
        // about before the first write — idempotency, feeStructure,
        // lock, receiptIndex. One curl_multi round-trip replaces what
        // used to be four separate reads (idempotency, feeStructure,
        // lock-check inside acquireLock, receipt-check inside
        // reserveReceipt). Collapsing these from ~6 s sequential to
        // ~1.5 s parallel is the single biggest win in this phase.
        $__parPreloadT0 = microtime(true);
        $__parReq = [
            'idemp'  => ['collection' => 'feeIdempotency',  'docId' => "{$schoolFs}_{$idempHash}"],
            'struct' => ['collection' => 'feeStructures',   'docId' => "{$schoolFs}_{$session}_{$class}_{$section}"],
            'lock'   => ['collection' => 'feeLocks',        'docId' => "{$schoolFs}_{$userId}"],
            'rcpt'   => ['collection' => 'feeReceiptIndex', 'docId' => "{$schoolFs}_{$session}_{$receiptNo}"],
        ];
        $__parResp = $controller->firebase->firestoreGetParallel($__parReq);
        $idemp           = is_array($__parResp['idemp']  ?? null) ? $__parResp['idemp']  : null;
        $__feeStructure  = is_array($__parResp['struct'] ?? null) ? $__parResp['struct'] : null;
        $__existingLock  = is_array($__parResp['lock']   ?? null) ? $__parResp['lock']   : null;
        $__existingRcpt  = is_array($__parResp['rcpt']   ?? null) ? $__parResp['rcpt']   : null;
        $__parPreloadMs  = (int) round((microtime(true) - $__parPreloadT0) * 1000);

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

        // Pre-batch staleness checks from the preloaded docs. These
        // mirror the semantics the individual acquireLock /
        // reserveReceipt helpers used to enforce via read-then-write,
        // but done here on the already-fetched docs so we can skip 2
        // more round-trips.
        $__lockStale = true;
        if (is_array($__existingLock) && !empty($__existingLock['token'])) {
            $__lockAge = time() - strtotime((string) ($__existingLock['acquiredAt'] ?? '2000-01-01'));
            $__lockStale = ($__lockAge >= 120);
            if (!$__lockStale) {
                if (!$writeToOutput) {
                    return [
                        'ok' => false, 'http_code' => 423,
                        'message' => 'Previous payment is still processing. Please wait a moment and retry.',
                    ];
                }
                $controller->output->set_output(json_encode([
                    'status' => 'error',
                    'message' => 'Previous payment is still processing. Please wait a moment and retry.',
                ]));
                return null;
            }
        } else {
            $__lockStale = false; // no existing doc = clean create
        }

        $__rcptStale = true;
        if (is_array($__existingRcpt)) {
            // Finalised receipt blocks unconditionally.
            if (!empty($__existingRcpt['date']) && empty($__existingRcpt['reserved'])) {
                if (!$writeToOutput) {
                    return [
                        'ok' => false, 'http_code' => 409,
                        'message' => "Receipt #{$receiptNo} is currently reserved or used. Refresh and retry.",
                    ];
                }
                $controller->output->set_output(json_encode([
                    'status' => 'error',
                    'message' => "Receipt #{$receiptNo} is currently reserved or used. Refresh and retry.",
                ]));
                return null;
            }
            // Fresh (<120s) reservation blocks too.
            if (!empty($__existingRcpt['reserved'])) {
                $__rcptAge = time() - strtotime((string) ($__existingRcpt['reservedAt'] ?? '2000-01-01'));
                $__rcptStale = ($__rcptAge >= 120);
                if (!$__rcptStale) {
                    if (!$writeToOutput) {
                        return [
                            'ok' => false, 'http_code' => 409,
                            'message' => "Receipt #{$receiptNo} is currently reserved or used. Refresh and retry.",
                        ];
                    }
                    $controller->output->set_output(json_encode([
                        'status' => 'error',
                        'message' => "Receipt #{$receiptNo} is currently reserved or used. Refresh and retry.",
                    ]));
                    return null;
                }
            }
        } else {
            $__rcptStale = false; // no existing doc = clean create
        }

        // ── Phase 7B: SINGLE BATCH CLAIM ───────────────────────────────
        // Collapse idempotency-processing + lock + receiptIndex into
        // ONE Firestore :commit (1 round-trip, 3 writes). Trade-off the
        // user opted into: we lose per-op error granularity — a
        // concurrent race on ANY of the three preconditioned writes
        // returns the same generic "retry" message.
        //
        // Precondition strategy:
        //   • idempotency  → no precondition (may overwrite a stale
        //                    'processing' doc we already vetted above).
        //   • lock         → `exists: false` if no doc found in preload,
        //                    else overwrite (doc was stale >120s).
        //   • receiptIndex → same pattern.
        //
        // A commit-time conflict is rare (requires a racing writer to
        // claim the same lock / receipt between our preload and our
        // commit, ~1-2s window). When it fires we release the
        // counter-claim so the next retry re-uses the same receipt #
        // instead of burning a new one.
        $lockToken  = bin2hex(random_bytes(8));
        $__nowIso   = date('c');
        $claimOps   = [
            [
                'op'         => 'set',
                'collection' => 'feeIdempotency',
                'docId'      => "{$schoolFs}_{$idempHash}",
                'data'       => [
                    'userId'    => $userId,
                    'receiptNo' => $receiptNo,
                    'months'    => $selectedMonths,
                    'amount'    => $schoolFees,
                    'schoolId'  => $schoolFs,
                    'hash'      => $idempHash,
                    'status'    => 'processing',
                    'startedAt' => $__nowIso,
                    'updatedAt' => $__nowIso,
                ],
            ],
            [
                'op'         => 'set',
                'collection' => 'feeLocks',
                'docId'      => "{$schoolFs}_{$userId}",
                'data'       => [
                    'schoolId'   => $schoolFs,
                    'userId'     => $userId,
                    'token'      => $lockToken,
                    'acquiredAt' => $__nowIso,
                    'ttlSeconds' => 120,
                ],
            ],
            [
                'op'         => 'set',
                'collection' => 'feeReceiptIndex',
                'docId'      => "{$schoolFs}_{$session}_{$receiptNo}",
                'data'       => [
                    'schoolId'   => $schoolFs,
                    'session'    => $session,
                    'receiptNo'  => $receiptNo,
                    'reserved'   => true,
                    'reservedAt' => $__nowIso,
                    'userId'     => $userId,
                    'updatedAt'  => $__nowIso,
                ],
            ],
        ];
        // Apply exists:false precondition ONLY when the preload saw no
        // existing doc. A stale doc (age > 120s) must be overwritten —
        // Firestore evaluates preconditions against batch-start state,
        // so exists:false would fail against the stale doc and lock us
        // out of retrying until the ghost aged out naturally.
        if (!$__lockStale)  { $claimOps[1]['precondition'] = ['exists' => false]; }
        if (!$__rcptStale)  { $claimOps[2]['precondition'] = ['exists' => false]; }

        $__claimT0 = microtime(true);
        $__claimOk = (bool) $controller->firebase->firestoreCommitBatch($claimOps);
        $__claimMs = (int) round((microtime(true) - $__claimT0) * 1000);
        if (!$__claimOk) {
            // Atomic failure — release counter claim so retry re-uses
            // this receipt #. Deliberately generic message (the
            // trade-off we accepted for the latency win).
            if ($autoClaimedSeq > 0) {
                try { $controller->fsTxn->releaseCounterClaim('receipt_seq', $autoClaimedSeq); } catch (\Throwable $_) {}
            }
            $msg = 'Please retry — concurrent update detected on this payment.';
            log_message('warning', "[FCS CLAIM BATCH FAILED] user={$userId} receipt={$receiptNo} parMs={$__parPreloadMs} claimMs={$__claimMs}");
            if (!$writeToOutput) {
                return ['ok' => false, 'http_code' => 409, 'message' => $msg];
            }
            $controller->output->set_output(json_encode(['status' => 'error', 'message' => $msg]));
            return null;
        }

        // Abort helper: rolls back the three claim writes + counter.
        // Used by guards BELOW the claim batch (pay-older-first,
        // overpayment, etc.). Sequential here is fine — abort path
        // isn't latency-critical.
        $_abort = function (string $msg, int $httpCode = 400) use ($controller, $userId, $lockToken, $idempHash, $receiptNo, $autoClaimedSeq, $writeToOutput): ?array {
            $controller->fsTxn->releaseLock($userId, $lockToken);
            $controller->fsTxn->deleteIdempotency($idempHash);
            $controller->fsTxn->deleteReceiptIndex($receiptNo);
            if ($autoClaimedSeq > 0) {
                $controller->fsTxn->releaseCounterClaim('receipt_seq', $autoClaimedSeq);
            }
            if (!$writeToOutput) {
                return ['ok' => false, 'message' => $msg, 'http_code' => $httpCode];
            }
            $controller->output->set_output(json_encode(['status' => 'error', 'message' => $msg]));
            return null;
        };

        $__mark('lock_acquired');

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

        // Razorpay path sets skip_duplicate_month_guard=true so a webhook
        // retry against an already-paid month doesn't 409 — the overpayment
        // guard further down now rejects any excess, so this bypass is
        // safe (no surplus can leak anywhere).
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
        //
        // NOTE: we DO NOT add a "yearly-demand must have 'Yearly Fees' in
        // selectedMonths" exclusion here. The current generator bundles
        // Annual Fee into April (month=April, period='April 2026'), so its
        // period-label IS "April" and it legitimately participates in an
        // April payment. parent_create_order uses this same period-label
        // filter, so the two paths agree on what a "₹3800 April payment"
        // covers; the old exclusion silently trimmed the verify total to
        // ₹2800 and every Annual-Fee-inclusive payment flipped to pending.
        $selectedSetForGuard = array_flip($selectedMonths);
        $totalUnpaidSelected = 0.0;
        foreach ($demands as $d) {
            if ((string) ($d['status'] ?? 'unpaid') === 'paid') continue;
            $label = $periodToLabel((string) ($d['period'] ?? ''));
            if ($label === '' || !isset($selectedSetForGuard[$label])) continue;
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
        //
        // 2026-04-24: batch mode is now the DEFAULT. The counter-flow
        // controller (Fees::submit_fees) never passed `batch_mode=true`
        // → every cashier submit silently fell through to the legacy
        // sequential path, bypassing every Phase 7A/B/G optimisation
        // (parallel preload, single-commit claim batch, drift guard).
        // Production logs showed batch_path=false on every submit,
        // 20+ s wall-clock where the batch path takes ~5-7 s. Callers
        // who genuinely need the sequential fallback can still opt
        // out by passing $data['batch_mode'] = false.
        $batchMode       = $data['batch_mode'] ?? true;
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
            //
            // Annual heads are bundled into April by the generator, so an
            // April-inclusive payment must list them in the breakdown —
            // the old check only matched a literal 'Yearly Fees' entry in
            // selectedMonths, which no parent flow ever sends, and every
            // Annual Fee row was silently dropped from the printed receipt.
            $breakdown = [];
            try {
                // Phase 7A — reuse the feeStructure we parallel-fetched
                // during idempotency preload. Fallback to a fresh read
                // keeps the path working if the preload returned null.
                $struct  = is_array($__feeStructure) ? $__feeStructure
                         : $controller->fs->get('feeStructures', "{$schoolFs}_{$session}_{$class}_{$section}");
                $heads   = is_array($struct['feeHeads'] ?? null) ? $struct['feeHeads'] : [];
                $monthCount = count(array_filter($selectedMonths, fn($x) => $x !== 'Yearly Fees'));
                $includeAnnual = in_array('Yearly Fees', $selectedMonths, true)
                                 || in_array('April', $selectedMonths, true);
                foreach ($heads as $h) {
                    $nm  = (string) ($h['name']   ?? '');
                    $amt = (float)  ($h['amount'] ?? 0);
                    $frq = strtolower((string) ($h['frequency'] ?? 'monthly'));
                    if ($nm === '' || $amt <= 0) continue;
                    $isAnnual = in_array($frq, ['annual', 'yearly', 'one-time', 'onetime'], true);
                    if ($isAnnual) {
                        if ($includeAnnual) {
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

            // Pre-allocate (in memory, no writes). Same eligibility rule
            // as the overpayment guard above: a demand is in-scope iff its
            // period-label matches a selected month. No yearly-specific
            // exclusion — Annual Fee demands carry month='April' today, so
            // an April payment must allocate to them too.
            $remaining      = $schoolFees + $submitAmount;
            $selectedSet    = array_flip($selectedMonths);
            $unpaid = [];
            foreach ($demands as $did => $d) {
                if ((string) ($d['status'] ?? 'unpaid') === 'paid') continue;
                $label = $periodToLabel((string) ($d['period'] ?? ''));
                if ($label === '' || !isset($selectedSet[$label])) continue;
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

            // 1. Per-demand updates (merge=true; keeps periodKey, feeHead).
            //    Phase 2.5: camelCase-only payload — snake_case aliases are
            //    canonicalised at read time by Fee_firestore_txn::normalizeDemandDoc.
            foreach ($allocationsForBatch as $a) {
                $batchOps[] = [
                    'op'         => 'set',
                    'collection' => 'feeDemands',
                    'docId'      => $a['demand_id'],
                    'merge'      => true,
                    'data'       => [
                        'paidAmount'  => $a['new_paid'],
                        'balance'     => $a['balance'],
                        'status'      => $a['status'],
                        'lastReceipt' => $receiptKey,
                        'updatedAt'   => $now,
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

            // 6. feeDefaulters update — Phase 2.5 Step 5: atomic with the
            //    demand writes. Computed from the in-memory post-allocation
            //    snapshot ($demandStateAfter) so the batch commits as a
            //    single Firestore round-trip. If the commit fails, every
            //    write above is reverted and the defaulter stays consistent
            //    with the (unchanged) demands.
            $rawClassForDef   = preg_replace('/^Class\s+/i', '', $class);  $rawClassForDef = 'Class ' . $rawClassForDef;
            $rawSectionForDef = preg_replace('/^Section\s+/i', '', $section); $rawSectionForDef = 'Section ' . $rawSectionForDef;
            $defDueTotal     = 0.0;
            $defUnpaidMonths = [];
            foreach ($demandStateAfter as $d) {
                $st = (string) ($d['status'] ?? 'unpaid');
                $b  = (float)  ($d['balance'] ?? 0);
                if ($st === 'paid' || $b <= 0.005) continue;
                $defDueTotal += $b;
                $p = $periodToLabel((string) ($d['period'] ?? ''));
                if ($p !== '' && !in_array($p, $defUnpaidMonths, true)) $defUnpaidMonths[] = $p;
            }
            $defaulterDocId = "{$schoolFs}_{$session}_{$userId}";
            if ($defDueTotal > 0.005) {
                $batchOps[] = [
                    'op'         => 'set',
                    'collection' => 'feeDefaulters',
                    'docId'      => $defaulterDocId,
                    'merge'      => false, // authoritative snapshot
                    'data'       => [
                        'schoolId'        => $schoolFs,
                        'session'         => $session,
                        'studentId'       => $userId,
                        'studentName'     => $studentName,
                        'className'       => $rawClassForDef,
                        'section'         => $rawSectionForDef,
                        'totalDues'       => round($defDueTotal, 2),
                        'unpaidMonths'    => $defUnpaidMonths,
                        'overdueMonths'   => [], // overdue is a time-based view; recomputed on read
                        'examBlocked'     => false,
                        'resultWithheld'  => false,
                        'lastPaymentDate' => $date,
                        'flaggedAt'       => $now,
                        'updatedAt'       => $now,
                    ],
                ];
            } else {
                // Fully cleared — delete the doc so Admin & Teacher views
                // stop listing this student as a defaulter.
                $batchOps[] = [
                    'op'         => 'delete',
                    'collection' => 'feeDefaulters',
                    'docId'      => $defaulterDocId,
                ];
            }

            // ── Phase 7C: ASYNC MODE BRANCH ─────────────────────────────
            // Feature-flagged off by default so the sync path stays
            // unchanged. When FEES_ASYNC_ALLOCATION=1 (env var) or
            // $data['async_allocation']=true is set, we split the
            // single atomic batch into:
            //   • SYNC COMMIT (returned to cashier):
            //       ── receipt doc      (status='queued', full shape)
            //       ── receipt allocation
            //       ── feeJob doc       (stashes the remaining ops)
            //   • DEFERRED TO WORKER (FeeWorker::run):
            //       ── demand patches
            //       ── receipt-index finalise (clears reservation)
            //       ── student monthFee merge
            //       ── feeDefaulters upsert/delete
            //       ── flip receipt.status → 'posted'
            //       ── releaseLock + idempotency success + clearPending
            //       ── defaulter recompute + summary refresh + journal
            //
            // Correctness invariants preserved:
            //   - Allocation math still runs SYNC (while demand state is
            //     fresh), only the COMMIT of that math runs async.
            //   - Lock is held until the worker releases it, so no
            //     concurrent submit can race into the same student.
            //   - Receipt doc carries status='queued' until the worker
            //     flips it; readers filter or display as "Processing".
            $asyncEnabled = (bool) (
                ($data['async_allocation'] ?? null) === true
                || (string) getenv('FEES_ASYNC_ALLOCATION') === '1'
            );

            if ($asyncEnabled) {
                // Inject status so the stub is distinguishable from
                // posted receipts by every reader (admin / parent / teacher).
                $batchReceiptData['status']   = 'queued';
                $batchReceiptData['queuedAt'] = $now;
                $batchReceiptData['postedAt'] = null;

                // Pull the receipt doc + allocation doc OUT of $batchOps
                // (we'll commit them sync). Everything else goes into
                // the worker's payload.
                $asyncOps   = [];
                $syncReceiptOps = [];
                foreach ($batchOps as $op) {
                    $coll = (string) ($op['collection'] ?? '');
                    if ($coll === 'feeReceipts' || $coll === 'feeReceiptAllocations') {
                        // feeReceipts needs the status override we just
                        // set — re-point it at the mutated payload.
                        if ($coll === 'feeReceipts') $op['data'] = $batchReceiptData;
                        $syncReceiptOps[] = $op;
                    } else {
                        $asyncOps[] = $op;
                    }
                }

                $jobId = "{$schoolFs}_{$receiptNo}";
                // Phase 7G (H4) — baseline `updatedAt` for every demand we
                // are about to patch. The worker re-reads each demand
                // before committing payload.ops; if the live doc's
                // updatedAt moved past the baseline, a concurrent refund
                // (or any other writer) has touched it since we computed
                // the allocation. Blind re-apply would overwrite those
                // edits — instead we abort the job with a clear error.
                $demandBaselineByDid = [];
                foreach ($allocationsForBatch as $_alloc) {
                    $_did = (string) ($_alloc['demand_id'] ?? '');
                    if ($_did !== '' && isset($demands[$_did])) {
                        $demandBaselineByDid[$_did] = (string) ($demands[$_did]['updatedAt'] ?? '');
                    }
                }

                $jobDoc = [
                    'jobId'     => $jobId,
                    'schoolId'  => $schoolFs,
                    'session'   => $session,
                    'receiptNo' => $receiptNo,
                    'receiptKey'=> $receiptKey,
                    'studentId' => $userId,
                    'status'    => 'queued',
                    'attempts'  => 0,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                    'payload'   => [
                        'ops'       => $asyncOps,
                        'idempHash' => $idempHash,
                        'months'    => $allocatedMonthsBatch,
                        'demandUpdateAtByDid' => $demandBaselineByDid,
                        'opsAcctPayload' => [
                            'school_name'  => $data['school_name'] ?? '',
                            'session_year' => $session,
                            'date'         => date('Y-m-d'),
                            'amount'       => $netTotal,
                            'payment_mode' => strtolower($paymentMode),
                            'receipt_no'   => $receiptKey,
                            'student_name' => $studentName,
                            'student_id'   => $userId,
                            'class'        => "{$class} {$section}",
                            'admin_id'     => $data['admin_id'] ?? 'system',
                            // Phase 7G (H2) — deterministic entry id so
                            // retries skip re-posting the same journal.
                            'journal_entry_id' => "JE_FEE_{$receiptKey}",
                        ],
                        'context'   => [
                            'userId'      => $userId,
                            'schoolFs'    => $schoolFs,
                            'session'     => $session,
                            'studentName' => $studentName,
                            'className'   => $class,
                            'section'     => $section,
                            'schoolName'  => $data['school_name'] ?? '',
                            'adminId'     => $data['admin_id'] ?? 'system',
                            // Phase 7D — lock token so the worker's
                            // finally-release can verify ownership and
                            // avoid stealing a freshly-claimed lock.
                            'lockToken'   => $lockToken,
                        ],
                    ],
                ];

                $syncOps = array_merge($syncReceiptOps, [[
                    'op'         => 'set',
                    'collection' => 'feeJobs',
                    'docId'      => $jobId,
                    'merge'      => false,
                    'data'       => $jobDoc,
                ]]);

                log_message('debug', "[FCS ASYNC COMMIT] sync_ops=" . count($syncOps) . " async_ops=" . count($asyncOps) . " receipt={$receiptKey}");
                $batchCompleted = (bool) $controller->firebase->firestoreCommitBatch($syncOps);
                if ($batchCompleted) {
                    $allocations     = $allocationsForDoc;
                    $allocatedMonths = $allocatedMonthsBatch;
                    $paidMonthsFlags = $paidMonthsFlagsBatch;
                    // T1 audit: receipt creation only (demand writes will
                    // be audited in the worker after they actually land).
                    self::_auditReceiptWrite($controller, $schoolFs, $session, $data, $receiptKey, $batchReceiptData);
                    log_message('error', "FEE_ASYNC_JOB_QUEUED jobId={$jobId} receipt={$receiptKey} ops=" . count($asyncOps));
                } else {
                    log_message('warning', "[FCS ASYNC FALLBACK] receipt={$receiptKey} — sync commit failed, falling through to sequential writes");
                    $allocations     = [];
                    $allocatedMonths = [];
                    $paidMonthsFlags = [];
                }
            } else {
                // ── SYNC MODE (default) ─────────────────────────────────
                log_message('debug', "[FCS BATCH COMMIT] ops=" . count($batchOps) . " receipt={$receiptKey}");
                $batchCompleted = (bool) $controller->firebase->firestoreCommitBatch($batchOps);
                log_message('debug', "[FCS BATCH COMMIT] ok=" . ($batchCompleted ? 'true' : 'false'));

                if ($batchCompleted) {
                    // Expose the same variable shape the sequential path
                    // produces so downstream response code works identically.
                    $allocations     = $allocationsForDoc;
                    $allocatedMonths = $allocatedMonthsBatch;
                    $paidMonthsFlags = $paidMonthsFlagsBatch;

                    // T1 audit trail — one row per demand mutation + one
                    // for the receipt creation. Fires AFTER the atomic batch
                    // lands in Firestore so we never audit a write that
                    // didn't persist. Safe against logger failures (see
                    // Fee_audit_logger::record — it returns false on error
                    // instead of throwing).
                    self::_auditAfterBatch(
                        $controller, $schoolFs, $session, $data,
                        $receiptKey, $receiptNo, $userId,
                        $demands /* before */, $allocationsForBatch, $batchReceiptData
                    );
                } else {
                    log_message('warning', "[FCS BATCH FALLBACK] receipt={$receiptKey} — commit failed, falling through to sequential writes");
                    // Reset shared vars; sequential path will repopulate.
                    $allocations     = [];
                    $allocatedMonths = [];
                    $paidMonthsFlags = [];
                }
            }
        }

        if (!$batchCompleted) {
            // ═══════════════════════════════════════════════════════════════
            //  SEQUENTIAL PATH — unchanged from P1/P2. Runs for the admin
            //  counter flow and as a fallback when batch commit fails.
            // ═══════════════════════════════════════════════════════════════

            // ── Mark pending-write (Firestore safety marker) ───────────────
            $controller->fsTxn->markPending($receiptKey, [
                'userId' => $userId, 'amount' => $schoolFees, 'months' => $selectedMonths, 'txnId' => $txnId,
            ]);

            // ── Compute per-head breakdown from fee structure ──────────────
            // See the batch path above for the rationale: annual heads are
            // bundled into April at generation time, so any April-inclusive
            // payment must list them. Synonyms for "yearly" frequency are
            // also accepted (annual/yearly/one-time) — drift between
            // generators previously erased the Annual Fee row.
            $breakdown = [];
            try {
                // Phase 7A — reuse the preloaded feeStructure; fallback to a
                // fresh read if the preload returned null (resilient path).
                $struct  = is_array($__feeStructure) ? $__feeStructure
                         : $controller->fs->get('feeStructures', "{$schoolFs}_{$session}_{$class}_{$section}");
                $heads   = is_array($struct['feeHeads'] ?? null) ? $struct['feeHeads'] : [];
                $includeAnnual = in_array('Yearly Fees', $selectedMonths, true)
                                 || in_array('April', $selectedMonths, true);
                foreach ($heads as $h) {
                    $nm  = (string) ($h['name']   ?? '');
                    $amt = (float)  ($h['amount'] ?? 0);
                    $frq = strtolower((string) ($h['frequency'] ?? 'monthly'));
                    if ($nm === '' || $amt <= 0) continue;
                    $isAnnual = in_array($frq, ['annual', 'yearly', 'one-time', 'onetime'], true);
                    if ($isAnnual) {
                        if ($includeAnnual) {
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
        // paymentId). Counter flows pass nothing → no change.
        if (is_array($data['extra_receipt_fields'] ?? null)) {
            $receiptDoc = array_merge($receiptDoc, $data['extra_receipt_fields']);
        }
        if (!$controller->fsTxn->writeFeeReceipt($receiptKey, $receiptDoc)) {
            return $_abort('Failed to record fee receipt. No data written.', 500);
        }
        $__mark('receipt_written');
        // T1 audit — receipt creation (sequential path).
        self::_auditReceiptWrite($controller, $schoolFs, $session, $data, $receiptKey, $receiptDoc);

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

        log_message('debug', '[ALLOC START] user=' . $userId . ' selected=' . json_encode($selectedMonths) . ' amount=' . $remaining);
        $unpaid = [];
        foreach ($demands as $did => $d) {
            $st = (string) ($d['status'] ?? 'unpaid');
            if ($st === 'paid') continue;
            $label = $periodToLabel((string) ($d['period'] ?? ''));
            if ($label === '' || !isset($selectedSet[$label])) continue;
            // No yearly-specific exclusion: Annual Fee demands carry
            // month='April' under the current generator, so they're
            // legitimately in-scope when April is selected. Any future
            // generator that reintroduces a standalone 'Yearly Fees'
            // bucket would still be correctly handled by the period-label
            // filter above.
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
            // T1 audit — per-demand payment update (sequential path).
            self::_auditDemandUpdate(
                $controller, $schoolFs, $session, $data,
                $did, $d,
                ['paidAmount' => $newPaid, 'balance' => $newBalance, 'status' => $newStatus, 'lastReceipt' => $receiptKey]
            );
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

        // ── 5. Discount update (if any) ────────────────────────────────
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

        $__mark('demands_allocated');

        // ── 7. Finalise idempotency + release lock + clear pending ─────
        //   Critical path: keep these BEFORE the response so a quick
        //   retry can't double-submit and the lock is released for the
        //   next payment.
        //
        //   Phase 7A: coalesce the idempotency-success write and the
        //   pending-write cleanup into ONE commitBatch (1 round-trip
        //   instead of 2). releaseLock stays sequential because its
        //   token-equality check is a read-then-delete guard that
        //   protects against force-release races — Firestore :commit
        //   supports `exists` preconditions but not field-value
        //   preconditions, so we can't move that check into the batch
        //   safely. Falls back to per-op writes if batch fails.
        // Phase 7C: in async mode the WORKER handles idempotency-success,
        // pending-clear, and lock-release after it commits the deferred
        // batch. Skipping them here keeps the sync path's round-trip count
        // down (~3 fewer calls) and preserves the invariant that the lock
        // is held until the async job finishes.
        $__asyncRunning = (bool) ($batchCompleted && ($asyncEnabled ?? false));
        if (!$__asyncRunning) {
            $cleanupOps = [
                [
                    'op'         => 'set',
                    'collection' => 'feeIdempotency',
                    'docId'      => "{$schoolFs}_{$idempHash}",
                    'merge'      => true,
                    'data'       => [
                        'status'      => 'success',
                        'receiptNo'   => $receiptNo,
                        'completedAt' => date('c'),
                        'updatedAt'   => date('c'),
                    ],
                ],
                [
                    'op'         => 'delete',
                    'collection' => 'feePendingWrites',
                    'docId'      => "{$schoolFs}_{$receiptKey}",
                ],
            ];
            if (!$controller->firebase->firestoreCommitBatch($cleanupOps)) {
                // Batch failed — fall back to the two sequential writes so we
                // never leave idempotency in 'processing' state.
                log_message('warning', "[FCS CLEANUP FALLBACK] batch failed, committing idemp+pending sequentially receipt={$receiptKey}");
                $controller->fsTxn->setIdempotencySuccess($idempHash, $receiptNo);
                $controller->fsTxn->clearPending($receiptKey);
            }
            $controller->fsTxn->releaseLock($userId, $lockToken);
        }
        $__mark('lock_released');

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
            // Phase 7D — surface the async/queued state so the UI can
            // warn the cashier "receipt still processing" instead of
            // offering a Print button before the worker posts.
            $__receiptStatus = ($__asyncRunning ?? false) ? 'queued' : 'posted';
            $__responseJson = json_encode([
                'status'           => 'success',
                'message'          => $__receiptStatus === 'queued'
                    ? 'Fees submitted. Receipt is being posted in the background — please wait a few seconds before printing.'
                    : 'Fees submitted successfully.',
                'receipt_no'       => $receiptNo,
                'receipt_status'   => $__receiptStatus,
                'async'            => (bool) ($__asyncRunning ?? false),
                'txn_id'           => $txnId,
                'user_id'          => $userId,
                'amount'           => $netTotal,
                'allocations'      => $allocations,
                'months_marked'    => $monthsMarked,
            ]);
            $controller->output->set_output($__responseJson);

            // ── Option B: EARLY RESPONSE FLUSH (Apache mod_php) ────────
            // XAMPP has no fastcgi_finish_request, so the response
            // otherwise sits in Apache's buffer until every shutdown
            // handler finishes. Push it to the client NOW — deferred
            // defaulter / journal / summary work then runs invisibly
            // in register_shutdown_function below.
            //
            // Ordering matters:
            //   1. Keep the client alive for deferred PHP work.
            //   2. Set Connection: close + Content-Length BEFORE any
            //      byte goes out (header() silently no-ops once the
            //      first byte is written).
            //   3. Drain every nested output buffer, then flush() the
            //      SAPI write-buffer so Apache releases the socket.
            //   4. Blank CI's output so CI_Output::_display() at
            //      request end doesn't double-emit the body.
            @set_time_limit(0);
            @ignore_user_abort(true);
            if (!headers_sent()) {
                @header('Connection: close');
                @header('Content-Type: application/json');
                @header('Content-Length: ' . strlen($__responseJson));
                @header('Content-Encoding: none'); // disable mod_deflate so length is honest
            }
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            echo $__responseJson;
            @flush();
            $controller->output->set_output('');
            // ──────────────────────────────────────────────────────────
        }
        $__mark('response_ready');
        // Log the phase timings up to response-send. Anything after this
        // runs in a shutdown handler — it adds to the user-perceived time
        // only if the PHP request doesn't flush the response headers first.
        log_message('error', 'FC_TIMING: ' . json_encode($__times));
        // Phase 7A — structured perf log. batch_size = how many ops went
        // into the demand/receipt/monthFee/defaulter commitBatch;
        // read_parallel_ms = idempotency+feeStructure curl_multi read;
        // batch_path = whether the atomic batch committed or we fell
        // through to sequential writes.
        log_message('error', 'FC_OPTIMIZED ' . json_encode([
            'timing_ms'        => $__times['response_ready'] ?? null,
            'batch_size'       => isset($batchOps) ? count($batchOps) : 0,
            'batch_path'       => (bool) ($batchCompleted ?? false),
            'read_parallel_ms' => $__parPreloadMs ?? null,
            'receipt_no'       => $receiptNo,
        ]));

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
        // Phase 6-Option-A (2026-04-24): defaulter default flipped from
        // INLINE to DEFERRED. Inline defaulter recompute was adding
        // ~5-8 s to user-perceived submit latency because it ran
        // after the `response_ready` marker but before the controller
        // returned (critical path, not truly post-response). User
        // accepted "eventual consistency (few seconds delay)" for
        // summary reads; defaulter rollout sits in the same tier.
        // Callers who need strictly-inline defaulter can override:
        //    $data['defer_defaulter'] = false
        // Accounting journal stays deferred as before.
        $deferDefaulter  = $data['defer_defaulter']  ?? true;
        $deferAccounting = $data['defer_accounting'] ?? true;
        // Phase 7C: in async mode the WORKER runs defaulter / summary /
        // journal after it commits the deferred batch. Skip the shutdown
        // handler entirely so we don't double-write (worker + shutdown
        // computing from the same stale demand state).
        if ($__asyncRunning ?? false) {
            $deferDefaulter  = false;
            $deferAccounting = false;
        }

        // Inline defaulter sync — Phase 2.5 Step 5: when the batch path
        // succeeded, the feeDefaulters doc was ALREADY written atomically
        // inside the same firestoreCommitBatch as the demand updates, so
        // we skip the separate recompute here (avoids doing the same
        // work twice and re-introducing a non-atomic window).
        // The sequential path still needs it because that path commits
        // demands one-by-one without a batched defaulter write.
        if (!$deferDefaulter && $feeDefaulter !== null && !$batchCompleted) {
            try {
                $defaulterStatus = $feeDefaulter->updateDefaulterStatus($userId);
                $controller->load->library('Fee_firestore_sync', null, 'fsSyncInline');
                $controller->fsSyncInline->init($firebase, $defaulterCtx['schoolFs'], $sessionYear);
                $controller->fsSyncInline->syncDefaulterStatus(
                    $userId, $defaulterStatus,
                    $defaulterCtx['studentName'], $defaulterCtx['className'], $defaulterCtx['section']
                );
                log_message('debug', "[SUBMIT DEFAULTER SYNCED INLINE] student={$userId}");
            } catch (\Exception $e) {
                log_message('error', "submit_fees: inline defaulter sync failed for {$userId}: " . $e->getMessage());
            }
        }

        // Phase 5 — capture the paid/allocated months for the deferred
        // summary refresh. `$monthsMarked` holds months flipped to fully
        // paid by this receipt; `$allocatedMonths` holds months the
        // payment touched at all. Use the latter so partial-paid months
        // also get their class summary refreshed.
        $phase5Months = is_array($allocatedMonths) ? $allocatedMonths : [];
        if (empty($phase5Months) && is_array($monthsMarked)) $phase5Months = $monthsMarked;

        if ($deferDefaulter || $deferAccounting) {
            register_shutdown_function(function () use (
                $ci, $feeDefaulter, $firebase, $schoolName, $sessionYear, $adminId,
                $opsAcctPayload, $defaulterCtx, $userId, $receiptKey,
                $deferDefaulter, $deferAccounting, $__t0, &$__times,
                $phase5Months
            ) {
                // Response was already flushed in Option B's early-flush
                // block (right after set_output above). Shutdown runs
                // post-response here, so all we do is make sure PHP
                // won't die mid-work if the client closed the socket.
                @set_time_limit(0);
                @ignore_user_abort(true);
                if (function_exists('fastcgi_finish_request')) {
                    // PHP-FPM safety net — no-op on Apache mod_php.
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

                // Phase 5 — read-optimisation summary refresh. Runs after
                // the response flush, so user-perceived latency is not
                // affected. If either summary write fails, the reader
                // library's staleness/fallback logic papers over it.
                try {
                    $ci->load->library('Fee_summary_writer', null, 'feeSummaryWriter');
                    $ci->feeSummaryWriter->init($firebase, $defaulterCtx['schoolFs'], $sessionYear);
                    $ci->feeSummaryWriter->onReceiptWritten(
                        $userId,
                        is_array($phase5Months) ? $phase5Months : [],
                        (string) ($defaulterCtx['className'] ?? ''),
                        (string) ($defaulterCtx['section']   ?? '')
                    );
                    log_message('debug', "[SUBMIT SUMMARY REFRESHED] student={$userId} receipt={$receiptKey}");
                } catch (\Throwable $e) {
                    log_message('error', "submit_fees: deferred summary refresh failed for {$userId}: " . $e->getMessage());
                }

                // Log timings including deferred phases — reveals whether
                // the 44s the user sees is on the critical path or if PHP
                // is holding the connection open for the shutdown handler.
                $__times['deferred_done'] = (int) round((microtime(true) - $__t0) * 1000);
                log_message('error', 'FC_TIMING_FINAL: ' . json_encode($__times));
            });
        }

        return $successReturn;
    }

    // ─────────────────────────────────────────────────────────────────
    //  T1 — Audit-logger hooks (Phase 4 critical fix).
    //
    //  One helper per financial touchpoint so call-sites stay clean.
    //  All three are static + private: they take the controller handle
    //  so they can reach $controller->firebase without needing the
    //  service to hold logger state. A write failure in the audit
    //  logger never fails the caller (Fee_audit_logger::record
    //  returns false on error instead of throwing).
    // ─────────────────────────────────────────────────────────────────

    private static function _auditLogger($controller, string $schoolFs, string $session)
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        require_once APPPATH . 'libraries/Fee_audit_logger.php';
        $cache = new Fee_audit_logger($controller->firebase, $schoolFs, $session);
        return $cache;
    }

    private static function _performedBy(array $data): string
    {
        return (string) ($data['admin_id']
                      ?? $data['collected_by']
                      ?? $data['created_by']
                      ?? ($data['source'] ?? 'system'));
    }

    /** Per-demand payment update (used by both batch and sequential paths). */
    private static function _auditDemandUpdate(
        $controller, string $schoolFs, string $session, array $data,
        string $demandId, array $before, array $after
    ): void {
        $logger = self::_auditLogger($controller, $schoolFs, $session);
        $logger->record(
            'update', 'demand', $demandId,
            [
                'paidAmount'  => (float) ($before['paidAmount']  ?? $before['paid_amount']  ?? 0),
                'balance'     => (float) ($before['balance']     ?? 0),
                'status'      => (string) ($before['status']     ?? 'unpaid'),
                'lastReceipt' => (string) ($before['lastReceipt'] ?? $before['last_receipt'] ?? ''),
            ],
            [
                'paidAmount'  => (float) ($after['paidAmount']  ?? 0),
                'balance'     => (float) ($after['balance']     ?? 0),
                'status'      => (string) ($after['status']     ?? ''),
                'lastReceipt' => (string) ($after['lastReceipt'] ?? ''),
            ],
            self::_performedBy($data),
            ['source' => (string) ($data['source'] ?? 'cashier')]
        );
    }

    /** Receipt creation (pure create — `before` is empty). */
    private static function _auditReceiptWrite(
        $controller, string $schoolFs, string $session, array $data,
        string $receiptKey, array $receiptDoc
    ): void {
        $logger = self::_auditLogger($controller, $schoolFs, $session);
        $logger->record(
            'create', 'receipt', $receiptKey,
            /* before */ [],
            [
                'receiptNo'       => (string) ($receiptDoc['receiptNo']      ?? ''),
                'studentId'       => (string) ($receiptDoc['studentId']      ?? ''),
                'totalAmount'     => (float)  ($receiptDoc['inputAmount']    ?? $receiptDoc['amount']          ?? 0),
                'paidAmount'      => (float)  ($receiptDoc['allocatedAmount'] ?? $receiptDoc['allocated_amount'] ?? 0),
                'discount'        => (float)  ($receiptDoc['discount']       ?? 0),
                'fine'            => (float)  ($receiptDoc['fine']           ?? 0),
                'paymentMode'     => (string) ($receiptDoc['paymentMode']    ?? ''),
                'feeMonths'       => is_array($receiptDoc['feeMonths'] ?? null) ? $receiptDoc['feeMonths'] : [],
                'txnId'           => (string) ($receiptDoc['txnId']          ?? ''),
                'date'            => (string) ($receiptDoc['date']           ?? ''),
            ],
            self::_performedBy($data),
            [
                'source' => (string) ($data['source'] ?? 'cashier'),
                'reason' => 'submit_fees',
            ]
        );
    }

    /**
     * Batch-commit audit — iterates the allocationsForBatch list and
     * emits one 'update' row per demand plus one 'create' row for the
     * receipt itself. `before` snapshots come from the in-memory
     * `$demands` map captured BEFORE the allocator mutated anything.
     */
    private static function _auditAfterBatch(
        $controller, string $schoolFs, string $session, array $data,
        string $receiptKey, string $receiptNo, string $userId,
        array $demandsBefore, array $allocationsForBatch, array $batchReceiptData
    ): void {
        foreach ($allocationsForBatch as $a) {
            $did = (string) ($a['demand_id'] ?? '');
            if ($did === '') continue;
            $before = $demandsBefore[$did] ?? [];
            $after = [
                'paidAmount'  => (float)  ($a['new_paid']  ?? 0),
                'balance'     => (float)  ($a['balance']   ?? 0),
                'status'      => (string) ($a['status']    ?? ''),
                'lastReceipt' => $receiptKey,
            ];
            self::_auditDemandUpdate($controller, $schoolFs, $session, $data, $did, $before, $after);
        }
        self::_auditReceiptWrite($controller, $schoolFs, $session, $data, $receiptKey, $batchReceiptData);
    }
}
