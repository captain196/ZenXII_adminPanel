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

        // ── L1.0 period-lock defensive gate ────────────────────────────
        // Belt-and-suspenders behind the controller-level gates at
        // submit_fees and parent_create_order. Catches any future
        // internal caller that bypasses the controller (e.g., a
        // worker / cron / migration script) and would otherwise post
        // a receipt into a closed accounting period.
        //
        // POLICY: only fires on counter-path calls (writeToOutput=true).
        // Parent-flow calls (writeToOutput=false) are the verify path,
        // which by design must complete for already-captured payments
        // even when the period is locked — see R1.1 precedent.
        if ($writeToOutput && method_exists($controller, '_abort_if_period_locked')) {
            $controller->_abort_if_period_locked();
        }

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

        // CORE-1 — Bank-ledger selection. Accounting (chartOfAccounts) is the
        // SINGLE SOURCE OF TRUTH; Fees only references the ledger by its
        // existing primary key (`code`). Cash payments send no bankLedgerCode
        // and are completely unchanged below.
        $bankLedgerCode = isset($data['bank_ledger_code'])
            ? trim((string) $data['bank_ledger_code'])
            : trim((string) ($controller->input->post('bankLedgerCode') ?? ''));
        $bankLedgerName = '';
        if ($bankLedgerCode !== '') {
            // Server-side validation — never trust the client. The selected
            // ledger must EXIST, be ACTIVE, be a Bank ledger (is_bank), and be
            // a postable leaf (not a group). Reuses Accounting's ledger store.
            $okBank = false;
            try {
                $coaRows = $controller->firebase->firestoreQuery('chartOfAccounts', [['schoolId', '==', $schoolFs]]);
                foreach ((array) $coaRows as $row) {
                    $cd = $row['data'] ?? $row;
                    if ((string) ($cd['code'] ?? '') !== $bankLedgerCode) continue;
                    if (!empty($cd['is_bank']) && empty($cd['is_group']) && ($cd['status'] ?? '') === 'active') {
                        $okBank = true;
                        $bankLedgerName = (string) ($cd['name'] ?? '');
                    }
                    break;
                }
            } catch (\Throwable $e) {
                log_message('error', 'FCS bank-ledger validation query failed: ' . $e->getMessage());
            }
            if (!$okBank) {
                return $_earlyError('Selected Bank Ledger is invalid or inactive. Please choose an active Bank Ledger configured in Accounting.', 422);
            }
        }

        // ── CORE-1 · RECORD THE EFFECTIVE BANK LEDGER, DO NOT LEAVE IT BLANK ──
        // An online payment has no operator at the till, so nobody selects a
        // bank ledger and the code above leaves it ''. Accounting then resolves
        // one of its own — "the first active bank ledger in the chart" — and the
        // receipt is left claiming no destination while the journal debits a
        // real bank account. Receipt F1 is the specimen: bankLedgerCode='' next
        // to a journal debiting 1020 SBI Bank A/c.
        //
        // That gap breaks the invariant AccountingReconciler depends on ("the
        // repair path must debit the SAME bank ledger the primary posting
        // used"): with '' on the receipt, a repair re-derives the ledger from a
        // chart that may have changed since, and can debit a DIFFERENT bank
        // account than the entry it is repairing.
        //
        // Resolving here — through the single shared rule, not a second copy of
        // it — makes the destination a recorded fact at collection time, and
        // feeds the SAME code into the journal payload so the two provably
        // agree. Best-effort: if the chart cannot be read, the previous
        // behaviour stands rather than failing a payment over bookkeeping
        // metadata.
        // Operations_accounting is normally pulled in by CI's loader inside the
        // deferred journal task, which has not run yet at this point. Require
        // the file directly for the static rule rather than instantiating the
        // library — resolveCashBankCode needs no state.
        if (!class_exists('Operations_accounting', false)) {
            $__opsAcctFile = APPPATH . 'libraries/Operations_accounting.php';
            if (is_file($__opsAcctFile)) require_once $__opsAcctFile;
        }
        $__payModeLower = strtolower((string) $paymentMode);
        $__settlementSource = '';
        if ($bankLedgerCode === '' && class_exists('Operations_accounting', false)
            && in_array($__payModeLower, Operations_accounting::NON_CASH_MODES, true)) {
            try {
                $coaMap = [];
                $coaRows = $controller->firebase->firestoreQuery('chartOfAccounts', [['schoolId', '==', $schoolFs]]);
                foreach ((array) $coaRows as $row) {
                    $cd = $row['data'] ?? $row;
                    if (!is_array($cd)) continue;
                    $code = (string) ($cd['code'] ?? '');
                    if ($code !== '') $coaMap[$code] = $cd;
                }

                if (!class_exists('Payment_settlement_route', false)) {
                    $__routeFile = APPPATH . 'libraries/Payment_settlement_route.php';
                    if (is_file($__routeFile)) require_once $__routeFile;
                }

                // The provider is OBSERVED from the caller, never assumed. When
                // it is unknown the route lookup is simply skipped — a guessed
                // provider would select some other school's settlement mapping,
                // which is worse than no mapping at all.
                $__provider = strtolower(trim((string) (
                    $data['gateway'] ?? $data['provider'] ?? ''
                )));
                $__env = strtolower(trim((string) ($data['gateway_mode'] ?? 'live')));

                $route = null;
                if ($__provider !== '' && class_exists('Payment_settlement_route', false)) {
                    $route = Payment_settlement_route::load(
                        $controller->firebase, $schoolFs, $__provider, $__env
                    );
                }

                if (class_exists('Payment_settlement_route', false)) {
                    $decision = Payment_settlement_route::resolveDebit(
                        $coaMap, $route, '', $__payModeLower, true
                    );
                    $resolvedCode = $decision['debit_account'];
                    // A null decision means the chart has no postable bank at
                    // all. Cash in Hand would be a lie for an online payment,
                    // so the field stays empty and the gap stays visible.
                    if ($resolvedCode !== null && $resolvedCode !== '1010' && isset($coaMap[$resolvedCode])) {
                        $bankLedgerCode     = $resolvedCode;
                        $bankLedgerName     = (string) ($coaMap[$resolvedCode]['name'] ?? '');
                        $__settlementSource = (string) $decision['source'];
                        // AMBIGUOUS means the school has more than one active
                        // bank ledger and has configured none for this
                        // provider, so the account debited is a guess that may
                        // be the wrong one. DETERMINISTIC means there was only
                        // one bank and no choice to get wrong. Both are logged;
                        // only the first is a mis-attribution risk.
                        if ($__settlementSource === Payment_settlement_route::SRC_AMBIGUOUS
                            || $__settlementSource === Payment_settlement_route::SRC_DETERMINISTIC) {
                            // Not an error — the payment is correct and
                            // reproducible — but the school has not told ZenX
                            // where gateway money lands, and someone should.
                            log_message('error', 'SETTLEMENT_ROUTE_' . strtoupper($__settlementSource)
                                . ' school=' . $schoolFs
                                . ' provider=' . ($__provider !== '' ? $__provider : 'unknown')
                                . ' debited=' . $resolvedCode
                                . ' — configure paymentSettlementRoutes to make this explicit');
                        }
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', 'FCS effective bank-ledger resolution failed: ' . $e->getMessage());
            }
        }

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

        // ── Phase 1 hardening: server-side discount authorization ──────────
        // A client-supplied discount must not exceed the student's already-
        // approved concession. Authorized source = the EXISTING
        // studentDiscounts.onDemandDiscount (the same field fetch_months reads
        // to compute netDue), honoring its validUntil expiry. This reuses
        // existing concession data only — no schema change, no new approval
        // workflow, no new roles. An unauthorized/over-cap discount is
        // rejected before any receipt/allocation/accounting write occurs.
        if ($discountFees > 0.005) {
            $approvedDiscountCap = 0.0;
            try {
                $__discDoc = $controller->fs->get('studentDiscounts', "{$schoolFs}_{$userId}");
                if (is_array($__discDoc)) {
                    $__vu = trim((string) ($__discDoc['validUntil'] ?? $__discDoc['valid_until'] ?? ''));
                    $__expired = ($__vu !== '' && $__vu < date('Y-m-d'));
                    // SESSION SCOPE. This is the server-side authorization cap
                    // on what a clerk may waive on a receipt, so a discount
                    // that belongs to a different session must not authorise
                    // money in this one. Unstamped (legacy) documents are
                    // honoured exactly as before; only an explicit mismatch is
                    // refused, which can only occur on writes made after
                    // stamping was introduced.
                    // `$session` (from $data['session_year']), NOT
                    // $controller->session_year: that property is PROTECTED on
                    // MY_Controller and this is a separate class, so reading it
                    // throws — and the \Throwable catch below would swallow the
                    // error, leave the cap at 0 and silently reject EVERY
                    // counter discount. Caught by driving the real endpoint and
                    // reading the log, not by any unit test.
                    $__docSession = trim((string) ($__discDoc['session'] ?? ''));
                    $__wrongSession = ($__docSession !== '' && $__docSession !== (string) $session);
                    if ($__wrongSession) {
                        log_message('error', 'FCS discount ignored — belongs to session ['
                            . $__docSession . '], collecting for [' . $session
                            . '] student=' . $userId);
                    }
                    if (!$__expired && !$__wrongSession) {
                        $approvedDiscountCap = (float) ($__discDoc['onDemandDiscount'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                // Fail CLOSED: if the approved cap can't be read, treat cap as 0
                // so an unverifiable discount is rejected rather than trusted.
                log_message('error', 'FCS discount-authorization read failed: ' . $e->getMessage());
            }
            if ($discountFees > $approvedDiscountCap + 0.005) {
                log_message('error', "FCS discount REJECTED student={$userId} requested={$discountFees} approved_cap={$approvedDiscountCap}");
                return $_earlyError(
                    'Discount Rs. ' . number_format($discountFees, 2)
                    . ' exceeds the approved concession Rs. ' . number_format($approvedDiscountCap, 2)
                    . ' for this student. Approve the concession first, then retry.',
                    422
                );
            }
        }

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

        // ── ACCOUNTING NUMBER, SEPARATE FROM THE INTERNAL KEY ──────────────
        // `$receiptKey` above is the IMMUTABLE identity: the feeReceipts doc
        // id, the allocation doc id, JE_FEE_{key}, source_ref, the
        // processedPayments marker. It is driven by a lifetime counter and
        // must NEVER reset, because resetting it would make a new session's
        // first receipt overwrite the previous session's F1 — a different
        // student's payment, silently replaced.
        //
        // Indian practice wants the number a parent quotes to restart each
        // year (Rule 46(b) CGST: a consecutive serial, ≤16 chars, unique for a
        // financial year; a fresh series each year is the audit-preferred
        // form). So the display number gets its OWN per-session counter and
        // the key keeps its own. Two questions, two counters.
        //
        // Best-effort: a receipt is never refused because a display serial
        // could not be allocated. Without one, the surfaces fall back to the
        // key, which is what every admin screen already shows.
        $receiptDisplayNo = '';
        $receiptSeries    = '';
        try {
            if (!class_exists('Receipt_number', false)) {
                $__rnFile = APPPATH . 'libraries/Receipt_number.php';
                if (is_file($__rnFile)) require_once $__rnFile;
            }
            if (class_exists('Receipt_number', false)) {
                $receiptSeries = Receipt_number::normaliseSession((string) $session);
                $dispSeq = $controller->fsTxn->nextCounter(
                    Receipt_number::sessionCounterKind((string) $session)
                );
                if ($dispSeq > 0) {
                    $receiptDisplayNo = Receipt_number::displayNumber((string) $session, $dispSeq);
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'receipt display-number allocation failed: ' . $e->getMessage());
        }

        $__mark('counter_claimed');
        // txn_id precedence: explicit $data['txn_id'] (Razorpay generates its
        // own 'RZP_YmdHis_hex6' upstream so we must preserve it byte-exact)
        // → otherwise compose from txn_prefix + our 8-hex random.
        $txnId      = isset($data['txn_id']) && $data['txn_id'] !== ''
            ? (string) $data['txn_id']
            : (($data['txn_prefix'] ?? 'TXN_') . date('YmdHis') . '_' . bin2hex(random_bytes(4)));

        // ── Idempotency check + claim-doc preload (Firestore) ──────────
        $idempHash = $controller->fsTxn->idempKey($userId, $receiptNo, $selectedMonths, $schoolFees);

        // Phase 7B intent: parallel-fetch FOUR docs every submit needs to
        // know about before the first write — idempotency, feeStructure,
        // lock, receiptIndex. The original Phase-7B code targeted a
        // `firestoreGetParallel()` method on the Firebase wrapper that
        // never existed (only Firestore_rest_client has the underlying
        // `getDocumentsParallel`). Calling it threw every time, which
        // surfaced as HTTP 500 on parent_verify_payment AFTER Razorpay
        // had already captured the money — a real-money-in-transit bug.
        //
        // Phase 1.X (2026-05-09) — collapsed to four sequential reads.
        // Same payload, same response shape; slower (~6 s vs the
        // theoretical 1.5 s parallel) but functionally correct. The
        // entire Phase 7B "speedup" was illusory because the code path
        // never actually ran. If a real parallel-read API is ever
        // exposed on the Firebase wrapper, this is the one place to
        // re-introduce it (preserve the keyed response shape so the
        // four assignments below still work).
        $__parPreloadT0  = microtime(true);
        // BUG-045 OP1' fix: true parallel reads via Firestore_rest_client.
        // Mirrors Curriculum_service.php:681-688 pattern. Falls back to
        // sequential firestoreGet if the rest client doesn't expose the
        // method (defensive — preserves correctness across environments).
        $__preloadReqs = [
            'idemp'        => ['collection' => 'feeIdempotency',  'docId' => "{$schoolFs}_{$idempHash}"],
            'feeStructure' => ['collection' => 'feeStructures',   'docId' => "{$schoolFs}_{$session}_{$class}_{$section}"],
            'lock'         => ['collection' => 'feeLocks',        'docId' => "{$schoolFs}_{$userId}"],
            'rcpt'         => ['collection' => 'feeReceiptIndex', 'docId' => "{$schoolFs}_{$session}_{$receiptNo}"],
        ];
        $__rest = $controller->firebase->getFirestoreDb();
        if ($__rest && method_exists($__rest, 'getDocumentsParallel')) {
            $__preloadDocs = $__rest->getDocumentsParallel($__preloadReqs);
        } else {
            $__preloadDocs = [];
            foreach ($__preloadReqs as $__tag => $__req) {
                $__preloadDocs[$__tag] = $controller->firebase->firestoreGet($__req['collection'], $__req['docId']);
            }
        }
        $idemp           = $__preloadDocs['idemp']        ?? null;
        $__feeStructure  = $__preloadDocs['feeStructure'] ?? null;
        $__existingLock  = $__preloadDocs['lock']         ?? null;
        $__existingRcpt  = $__preloadDocs['rcpt']         ?? null;
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
        // ══ R3 · A STALE TAKEOVER MUST ALSO BE GUARDED ═══════════════════════
        //
        // 1. CLASSIFICATION — bug (money-path concurrency), narrow window.
        // 2. ROOT CAUSE — the fresh case was made atomic with `exists:false`,
        //    but the STALE case was left with NO precondition at all, i.e. a
        //    blind overwrite. Two processes that both observe the same expired
        //    lock therefore both "recover" it and both enter the critical
        //    section — the very read-then-blind-write defect B-025 and REF-1
        //    removed elsewhere, surviving in the recovery path.
        //    Same for the receipt-index reservation, where the consequence is
        //    worse: two writers can take the SAME receipt number.
        // 3. BLAST RADIUS — subtractive. A guarded write can only turn a
        //    previously-succeeding double takeover into a refusal; it cannot
        //    block a caller that was alone. The 423 response and its message
        //    are unchanged, so the client contract is identical.
        // 4. BACKWARD COMPATIBILITY — none needed. The precondition is computed
        //    from the document just read; no stored shape changes. A lock
        //    written by the old code, by acquireLockStatus(), or by this batch
        //    is byte-identical.
        // 5. ROLLBACK — delete the two elseif branches. No data migration.
        // 6. CROSS-MODULE — `feeLocks` has exactly two producers (this service
        //    and Fee_firestore_txn) and `feeReceiptIndex` one; both verified by
        //    search. Server-only via the rules catch-all, so no client and
        //    neither app is affected.
        // 7. VERIFICATION — unit tests against a versioned Firestore double
        //    that enforces precondition semantics, plus emulator proof of the
        //    underlying primitives (payment_claim_cas.test.js), plus a revert
        //    test that must fail.
        //
        // WHY FAIL CLOSED IS SAFE HERE: this branch runs only when the document
        // EXISTS, and both preload paths decode through decodeDocument(), which
        // always surfaces `__updateTime` for an existing document. So a missing
        // version means something is genuinely wrong — not a routine condition —
        // and refusing costs a retry rather than exclusivity.
        $__staleTakeoverBlocked = '';

        if (!$__lockStale) {
            $claimOps[1]['precondition'] = ['exists' => false];
        } else {
            $__lockVersion = (string) ($__existingLock['__updateTime'] ?? '');
            if ($__lockVersion === '') {
                $__staleTakeoverBlocked = 'lock';
            } else {
                $claimOps[1]['precondition'] = ['updateTime' => $__lockVersion];
            }
        }

        if (!$__rcptStale) {
            $claimOps[2]['precondition'] = ['exists' => false];
        } elseif (is_array($__existingRcpt)) {
            $__rcptVersion = (string) ($__existingRcpt['__updateTime'] ?? '');
            if ($__rcptVersion === '') {
                $__staleTakeoverBlocked = $__staleTakeoverBlocked ?: 'receiptIndex';
            } else {
                $claimOps[2]['precondition'] = ['updateTime' => $__rcptVersion];
            }
        }

        if ($__staleTakeoverBlocked !== '') {
            log_message('error',
                'FeeCollectionService: refusing stale ' . $__staleTakeoverBlocked
                . ' takeover for student [' . $userId . '] — the document carries no version, '
                . 'so exclusivity cannot be guaranteed. Nothing applied.');

            if (!$writeToOutput) {
                return [
                    'ok' => false, 'http_code' => 423,
                    'message' => 'Previous payment is still processing. Please wait a moment and retry.',
                ];
            }
            $controller->output->set_output(json_encode([
                'status'  => 'error',
                'message' => 'Previous payment is still processing. Please wait a moment and retry.',
            ]));
            return null;
        }

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
            log_message('error', "[FCS CLAIM BATCH FAILED] user={$userId} receipt={$receiptNo} parMs={$__parPreloadMs} claimMs={$__claimMs}");
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
        //
        // ── B-027 (2026-08-22): STRICT read, because this is a SAFETY decision ──
        //
        // `demandsForStudent()` ends in `catch { return []; }`. Its own docblock
        // says the ten read-only callers "legitimately prefer degradation over an
        // exception" — but this is not a read-only caller. This demand set drives
        // BOTH the duplicate-month guard and the overpayment guard below.
        //
        // On a transient Firestore error the old reader returned [], so
        // $totalUnpaidSelected computed to 0.00 and EVERY payment was refused with
        //     "Amount Rs. X exceeds total due Rs. 0.00. Overpayment is not allowed."
        // That is fail-CLOSED, which is right — but it runs AFTER the gateway has
        // already captured the money, and it misdiagnoses a read blip as an
        // overpayment. The parent is told they overpaid when they did not, and the
        // payment sits unreconciled until someone runs retry_payment_processing.
        //
        // The strict variant throws instead, so the failure is reported as what it
        // is and is explicitly retryable. $_abort releases the lock, the
        // idempotency record, the receipt index and any counter claim, so nothing
        // financial is written and a retry starts clean.
        //
        // This GRANTS NOTHING — both shapes refuse the payment. It changes a
        // misleading refusal into an accurate, retryable one.
        try {
            $demands = $controller->fsTxn->demandsForStudentStrict($userId); // [docId => data]
        } catch (\RuntimeException $e) {
            log_message('error',
                'FeeCollectionService: demand set unreadable for student [' . $userId . '] — '
                . $e->getMessage() . ' — payment REFUSED, nothing applied');
            return $_abort(
                'We could not read your fee record just now. No money has been applied. '
                . 'Please retry in a moment.',
                503
            );
        }

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

        // ── Phase 15.5 (G-3): COMPUTED FINE IS CANONICAL ────────────────
        //
        // THE DEFECT THIS CLOSES: the fine was taken verbatim from cashier
        // POST input (`fineAmount`) and never compared against the fine the
        // system itself computed onto the demand
        // (Fees::compute_fines_for_student writes `fine_amount`). A cashier
        // holding only COUNTER_ROLES could therefore waive any computed
        // penalty by submitting 0 — silently, with no reason, no approval and
        // no audit record — while the financially equivalent act of granting
        // a DISCOUNT is gated on MANAGE_ROLES (Fees::submit_discount) and
        // capped server-side above. That asymmetry was a revenue-leakage path.
        //
        // RULE (mirrors the discount-authorization block above):
        //   • Default — the SYSTEM-COMPUTED fine wins. Whatever the client
        //     posted is ignored. This is what makes the computed fine
        //     canonical end-to-end: receipt and accounting both consume
        //     $fineAmount downstream, so fixing it here fixes both.
        //   • Override — only when the client EXPLICITLY requests one, and
        //     only with MANAGE-level authorization plus a reason. Anything
        //     else is refused before a single receipt/allocation/journal
        //     write occurs.
        //   • When nothing was computed (canonical 0) the posted value is
        //     honoured, preserving the existing manual/ad-hoc fine workflow
        //     that schools rely on today.
        //
        // COST: zero additional Firestore reads — $demands is already loaded
        // above for the duplicate-month guard.
        $__canonicalFine = 0.0;
        foreach ($demands as $__d) {
            if (strtolower((string) ($__d['status'] ?? '')) === 'archived') continue;
            if (!in_array($periodToLabel((string) ($__d['period'] ?? '')), $selectedMonths, true)) continue;
            $__canonicalFine += (float) ($__d['fine_amount'] ?? $__d['fineAmount'] ?? 0);
        }
        $__canonicalFine = round($__canonicalFine, 2);

        $__overrideAsked = (string) ($data['fine_override']
            ?? $controller->input->post('fine_override') ?? '') === '1';
        $__overrideReason = trim((string) ($data['fine_override_reason']
            ?? $controller->input->post('fine_override_reason') ?? ''));
        $__fineSubmitted  = $fineAmount;
        $__fineOverridden = false;

        if ($__overrideAsked) {
            if (!function_exists('has_permission')) {
                $controller->load->helper('rbac_helper');
            }
            $__mayOverride = function_exists('has_permission')
                ? has_permission('Fees', 'manage')
                : false;   // fail CLOSED when RBAC can't be evaluated
            if (!$__mayOverride) {
                log_message('error', "FCS fine-override REFUSED (unauthorized) student={$userId} "
                    . "computed={$__canonicalFine} requested={$__fineSubmitted}");
                return $_abort('Changing the calculated late fee requires Fees "manage" permission. '
                    . 'Ask an authorized user to approve this waiver.', 403);
            }
            if ($__overrideReason === '') {
                return $_abort('A reason is required to change the calculated late fee.', 422);
            }
            $__fineOverridden = true;
            // authorized → the submitted amount stands ($fineAmount unchanged)
        } elseif ($__canonicalFine > 0.005) {
            // No override requested → the computed fine is authoritative.
            $fineAmount = $__canonicalFine;
        }

        // Audit ANY divergence between what the system computed and what is
        // actually being collected — approved or not — so a school can always
        // answer "why did this family pay a different penalty than we
        // calculated?". Never fails the payment (logger is fail-soft).
        if (abs($__fineSubmitted - $fineAmount) > 0.005 || $__fineOverridden) {
            try {
                self::_auditLogger($controller, $schoolFs, $session)->record(
                    'update', 'fine_override', "{$userId}_{$receiptKey}",
                    ['fineAmount' => round($__canonicalFine, 2)],
                    ['fineAmount' => round($fineAmount, 2)],
                    self::_performedBy($data),
                    [
                        'source'          => (string) ($data['source'] ?? 'cashier'),
                        'studentId'       => $userId,
                        'receiptNo'       => $receiptKey,
                        'computedFine'    => round($__canonicalFine, 2),
                        'submittedFine'   => round($__fineSubmitted, 2),
                        'appliedFine'     => round($fineAmount, 2),
                        'authorized'      => $__fineOverridden,
                        'reason'          => $__overrideReason,
                        'selectedMonths'  => implode(',', $selectedMonths),
                    ]
                );
            } catch (\Throwable $e) {
                log_message('error', 'FCS fine-override audit failed: ' . $e->getMessage());
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
        // The receipt breakdown is built from ALLOCATIONS (below) — never
        // from the class fee chart.
        //
        // A chart-derived breakdown was the F1 defect: a 700 payment settling
        // ID Card Replacement 350 + Tuition Fee 350 printed Tuition 600 /
        // Library 50 / Transport 200 / Admission 1000 — line items summing to
        // 1,850 above a 700 total, with the ad-hoc charge absent entirely,
        // because ad-hoc charges exist in no structure by construction.
        //
        // It survived here as a FALLBACK: when the allocations produced no
        // rows, the chart-derived value was kept and written to the receipt.
        // That reintroduced chart-derived financial content on exactly the
        // edge case nobody exercises. An empty breakdown is honest; a wrong
        // one is not — and both the app and the PDF already render from the
        // allocations when the stored breakdown is absent.
        $breakdown = [];

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

            // Same correction as the sequential path's step-3 patch: the
            // breakdown computed further up came from the class fee STRUCTURE
            // and therefore described the fee chart rather than this payment.
            // The allocations are now known, so the receipt is rebuilt from
            // what the money was actually applied to. Ad-hoc student charges
            // exist in no structure and were silently dropped before this.
            // UNCONDITIONAL. Guarding this with !empty() meant that an empty
            // allocation set left the previous, chart-derived value standing —
            // which is the one thing a receipt must never carry. An empty
            // breakdown is honest; a chart-derived one is a false statement
            // about what the payment settled.
            $breakdown = self::_breakdownFromAllocations($allocationsForBatch);

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
                // The accounting number a parent quotes and an auditor asks for.
                // Restarts each session; the immutable key above does not.
                'receiptDisplayNo' => $receiptDisplayNo,
                'receiptSeries'    => $receiptSeries,
                'receiptKey'       => $receiptKey,
                'schoolId'         => $schoolFs,
                'session'          => $session,
                'studentId'        => $userId,
                'studentName'      => $studentName,
                'className'        => $class,
                'section'          => $section,
                'fatherName'       => (string) ($student['fatherName'] ?? ''),
                // Phase 13 (Workstream F) — see the note on the non-batch
                // receipt below. Display-only; ZERO extra reads ($student is
                // already loaded); `studentId` above remains the canonical key.
                // Same alias tolerance SIS applies (Sis.php canonicalises srNo /
            // srNumber / "SR Number"), and the same tolerance `rollNo` on the
            // next line already has. Reading a single alias here meant a
            // student admitted through a path that stores `srNumber` printed a
            // blank SR on the receipt with nothing to indicate it was a lookup
            // miss rather than a student without one.
            'srNo'             => (string) ($student['srNo'] ?? $student['srNumber'] ?? $student['SR Number'] ?? ''),
                'rollNo'           => (string) ($student['rollNo'] ?? $student['Roll No'] ?? ''),
                'amount'           => $schoolFees,
                'input_amount'     => $schoolFees,
                'inputAmount'      => $schoolFees,
                'allocated_amount' => $allocatedAmountSumBatch,
                'allocatedAmount'  => $allocatedAmountSumBatch,
                'discount'         => $discountFees,
                'fine'             => $fineAmount,
                'netAmount'        => $netTotal,
                'paymentMode'      => $paymentMode,
                'bankLedgerCode'   => $bankLedgerCode,
                'bankLedgerName'   => $bankLedgerName,
            // HOW the ledger was chosen, recorded next to WHICH one. An
            // auditor reading a receipt can tell a school's explicit mapping
            // ('configured') from a system fallback ('deterministic_fallback')
            // without re-deriving anything, and a report can list every school
            // still relying on the fallback. Empty for cash and for receipts
            // written before this existed.
            'bankLedgerSource' => $__settlementSource,
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

            // ── H1-F1 · THE PAYMENT MARKER BELONGS IN THIS BATCH ──────────
            // "One successful payment → one receipt" was NOT held by the
            // feeIdempotency layer, and it is worth being exact about why:
            //
            //     idempKey = md5(userId | receiptNo | months | amount)
            //
            // and `receiptNo` comes from nextCounter('receipt_seq'). A retry
            // allocates a NEW sequence number, so it computes a DIFFERENT hash
            // and the idempotency slot cannot match. That layer only guards a
            // duplicate submission of the SAME receipt number (a cashier
            // double-click), never a re-run of the same gateway payment.
            //
            // What actually held the invariant were two writes the CONTROLLER
            // performed AFTER this batch committed — feeOnlineOrders.status
            // ='paid' and the processedPayments marker. So a crash between this
            // commit and those writes left a durable receipt with NOTHING
            // recording that the payment had been applied: the gateway's retry
            // would find no marker and an unpaid order, re-enter the pipeline,
            // draw a fresh receipt number, and mint a SECOND receipt with a
            // second allocation set against the same money.
            //
            // Writing the marker HERE makes "a receipt exists" and "the payment
            // is recorded as applied" the same atomic fact, which is what the
            // invariant actually claims. The controller still writes it
            // afterwards; that write is now a harmless idempotent repeat rather
            // than the thing the invariant depends on.
            //
            // Conditional on a gateway payment id: cash/cheque collections have
            // none, and an empty-keyed marker would be a single junk document
            // capable of suppressing an unrelated payment.
            // The gateway ids reach this service through extra_receipt_fields
            // (camelCase), which is where both parent paths put them. The
            // snake_case top-level names are accepted as a fallback so a caller
            // that passes them directly still records a marker.
            $__extraRcpt   = is_array($data['extra_receipt_fields'] ?? null) ? $data['extra_receipt_fields'] : [];
            $__gwPaymentId = (string) ($__extraRcpt['paymentId'] ?? ($data['payment_id'] ?? ''));
            $__gwOrderId   = (string) ($__extraRcpt['orderId']   ?? ($data['gateway_order_id'] ?? ''));
            if ($__gwPaymentId !== '') {
                $batchOps[] = [
                    'op'         => 'set',
                    'collection' => 'processedPayments',
                    'docId'      => $__gwPaymentId,   // PAY-2: the payment IS the key
                    'merge'      => true,
                    'data'       => [
                        'paymentId'   => $__gwPaymentId,
                        'orderId'     => $__gwOrderId,
                        'schoolId'    => $schoolFs,
                        'session'     => $session,
                        'studentId'   => $userId,
                        'receiptNo'   => $receiptNo,
                        'receiptKey'  => $receiptKey,
                        'status'      => 'success',
                        'completedAt' => $now,
                        'updatedAt'   => $now,
                    ],
                ];
            }

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
                    'bankLedgerCode' => $bankLedgerCode,
                    'bankLedgerName' => $bankLedgerName,
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
                            'bank_code'    => $bankLedgerCode,
                            'receipt_no'   => $receiptKey,
                            'student_name' => $studentName,
                            'student_id'   => $userId,
                            'class'        => "{$class} {$section}",
                            'admin_id'     => $data['admin_id'] ?? 'system',
                            // Phase 7G (H2) — deterministic entry id so
                            // retries skip re-posting the same journal.
                            'journal_entry_id' => "JE_FEE_{$receiptKey}",
                            // Phase A0 (canonical fee-accounting
                            // normalization, 2026-05-10) — pass the
                            // full allocation breakdown so the v2
                            // journal path can build per-fee-head
                            // credit lines (Cr 4040 Transport, Cr 4010
                            // Tuition, etc.) instead of crediting all
                            // revenue to 4010. Fine + discount are
                            // also passed for proper double-entry —
                            // discount becomes Dr Discount-Allowed
                            // expense rather than reducing revenue.
                            // See Operations_accounting::_buildGranularLines.
                            'allocations'      => $allocationsForBatch,
                            'fine_amount'      => round((float) ($fineAmount ?? 0), 2),
                            'discount_amount'  => round((float) ($discountFees ?? 0), 2),
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
                    log_message('error', "[FCS ASYNC FALLBACK] receipt={$receiptKey} — sync commit failed, falling through to sequential writes");
                    $allocations     = [];
                    $allocatedMonths = [];
                    $paidMonthsFlags = [];
                }
            } else {
                // ── SYNC MODE (default) ─────────────────────────────────
                // BUG-045 OP4-A: append audit ops to $batchOps so audit-log
                // writes commit atomically with the financial writes —
                // eliminates the post-batch sequential audit-write phase
                // (~1.6-2s saving on demands_allocated phase). Audit ops
                // are appended only on success path; failure path retains
                // sequential audit writes via _auditAfterBatch (kept intact
                // for sequential fallback at line 1163+).
                $auditOps = self::_buildAuditOpsAfterBatch(
                    $controller, $schoolFs, $session, $data,
                    $receiptKey, $receiptNo, $userId,
                    $demands /* before */, $allocationsForBatch, $batchReceiptData
                );
                if (!empty($auditOps)) {
                    $batchOps = array_merge($batchOps, $auditOps);
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

                    // BUG-045 OP4-A: _auditAfterBatch call removed — audit
                    // writes are now embedded atomically in the $batchOps
                    // above. Stronger atomicity: audit row CANNOT land for
                    // a financial write that didn't persist (was previously
                    // ordered after commit but could diverge under partial
                    // failure).
                } else {
                    log_message('error', "[FCS BATCH FALLBACK] receipt={$receiptKey} — commit failed, falling through to sequential writes");
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
        // The receipt breakdown is built from ALLOCATIONS (below) — never
        // from the class fee chart.
        //
        // A chart-derived breakdown was the F1 defect: a 700 payment settling
        // ID Card Replacement 350 + Tuition Fee 350 printed Tuition 600 /
        // Library 50 / Transport 200 / Admission 1000 — line items summing to
        // 1,850 above a 700 total, with the ad-hoc charge absent entirely,
        // because ad-hoc charges exist in no structure by construction.
        //
        // It survived here as a FALLBACK: when the allocations produced no
        // rows, the chart-derived value was kept and written to the receipt.
        // That reintroduced chart-derived financial content on exactly the
        // edge case nobody exercises. An empty breakdown is honest; a wrong
        // one is not — and both the app and the PDF already render from the
        // allocations when the stored breakdown is absent.
        $breakdown = [];

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
            // The accounting number a parent quotes and an auditor asks for.
            // Restarts each session; the immutable key above does not.
            'receiptDisplayNo' => $receiptDisplayNo,
            'receiptSeries'    => $receiptSeries,
            'studentId'        => $userId,
            'studentName'      => $studentName,
            'className'        => $class,
            'section'          => $section,
            'fatherName'       => (string) ($student['fatherName'] ?? ''),
            // Phase 13 (Workstream F) — school-facing identifiers denormalised
            // onto the receipt so the PRINTED receipt can lead with the SR
            // Number the school actually operates on, without the print path
            // needing to read the student document.
            //
            // ZERO additional Firestore reads: `$student` is already loaded and
            // in scope here — the line above reads `fatherName` from it.
            //
            // These are DISPLAY fields. `studentId` above remains the canonical
            // persisted key for this receipt and for every allocation,
            // reconciliation and accounting linkage. Nothing keys on `srNo`.
            // Same alias tolerance SIS applies (Sis.php canonicalises srNo /
            // srNumber / "SR Number"), and the same tolerance `rollNo` on the
            // next line already has. Reading a single alias here meant a
            // student admitted through a path that stores `srNumber` printed a
            // blank SR on the receipt with nothing to indicate it was a lookup
            // miss rather than a student without one.
            'srNo'             => (string) ($student['srNo'] ?? $student['srNumber'] ?? $student['SR Number'] ?? ''),
            'rollNo'           => (string) ($student['rollNo'] ?? $student['Roll No'] ?? ''),
            'amount'           => $schoolFees,
            'input_amount'     => $schoolFees,
            'inputAmount'      => $schoolFees,    // dual-emit for Android
            'allocated_amount' => 0.0,            // patched in step 3
            'allocatedAmount'  => 0.0,
            'discount'         => $discountFees,
            'fine'             => $fineAmount,
            'netAmount'        => $netTotal,
            'paymentMode'      => $paymentMode,
            'bankLedgerCode'   => $bankLedgerCode,
            'bankLedgerName'   => $bankLedgerName,
            // HOW the ledger was chosen, recorded next to WHICH one. An
            // auditor reading a receipt can tell a school's explicit mapping
            // ('configured') from a system fallback ('deterministic_fallback')
            // without re-deriving anything, and a report can list every school
            // still relying on the fallback. Empty for cash and for receipts
            // written before this existed.
            'bankLedgerSource' => $__settlementSource,
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
            'bankLedgerCode' => $bankLedgerCode,
            'bankLedgerName' => $bankLedgerName,
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

        //  feeBreakdown belongs to the SAME "truth, not selection" rule as
        //  allocated_amount and feeMonths above — it was simply left behind.
        //
        //  It was built from the class fee STRUCTURE, so the receipt described
        //  the fee chart instead of the transaction. Demonstrated on the first
        //  live payment (receipt F1, 2026-08-26): ₹700 paid against ID Card
        //  Replacement ₹350 + Tuition Fee ₹350, and the receipt listed
        //  Tuition 600 / Library 50 / Transport 200 / Admission 1000 — items
        //  summing to ₹1,850 under a ₹700 total, with the ID Card charge, an
        //  ad-hoc student charge that exists in no structure, absent entirely.
        //
        //  Structure-derived rows cannot represent ad-hoc charges by
        //  construction, so this is rebuilt from the allocations, which are
        //  what the money was actually applied to.
        $breakdownFromAllocations = self::_breakdownFromAllocations($allocations);

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
        // Only overwrite when the allocations actually produced rows. If they
        // did not, leaving the previous value is strictly better than blanking
        // the breakdown on a receipt that has already been written.
        // UNCONDITIONAL — same reasoning as the batch path. An empty result
        // must BLANK the stored breakdown, not leave a chart-derived one in
        // place on the receipt.
        $patch['feeBreakdown'] = $breakdownFromAllocations;
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
            // ── ADJ-GOV-6 · THE AUTHORISATION MUST BE CONSUMED ──────────────
            //
            // `onDemandDiscount` is a SINGLE STANDING REDUCTION, not a
            // per-receipt allowance: `Fees::fetch_months` computes
            // `netDue = totalRemaining - onDemandDiscount` exactly once against
            // the whole outstanding balance.
            //
            // This block used to write only `totalDiscount` and leave
            // `onDemandDiscount` untouched, while the authorisation check above
            // re-reads that same field fresh on every receipt. So one approved
            // waiver could be spent again on the next receipt, and the next.
            //
            // PROVEN at runtime: a 500 waiver was accepted on receipt 1 AND on
            // receipt 2 — two receipts each recording discount=500 — with
            // `onDemandDiscount` still standing at 500 afterwards. A single
            // 500 approval became 500 x (number of receipts).
            //
            // Consuming it keeps the two readings coherent: as the outstanding
            // falls by what the receipt settled, the remaining authorisation
            // falls by what the receipt used, and `netDue` stays correct.
            // Never negative, and the `applied` history below still records the
            // full amount used on this receipt.
            $__remainingAuth = round(max(0.0, $approvedDiscountCap - $discountFees), 2);
            $__scholKeep     = 0.0;
            try {
                $__cur = $controller->fs->get('studentDiscounts', "{$schoolFs}_{$userId}");
                if (is_array($__cur)) {
                    $__scholKeep = (float) ($__cur['scholarshipDiscount']
                        ?? $__cur['ScholarshipDiscount'] ?? 0);
                }
            } catch (\Throwable $e) {
                log_message('error', 'ADJ_GOV_CONSUME_READ_FAILED student=' . $userId
                    . ': ' . $e->getMessage());
            }

            log_message('error', 'ACC_DISCOUNT_CONSUMED student=[' . $userId . '] receipt=['
                . $receiptKey . '] used=[' . $discountFees . '] authorised=['
                . $approvedDiscountCap . '] remaining=[' . $__remainingAuth . ']');

            $controller->fsTxn->updateDiscount($userId, [
                'onDemandDiscount' => $__remainingAuth,
                // SD-2 coherence: the reported total is the sum of its parts,
                // not the last receipt's discount.
                'totalDiscount' => round($__remainingAuth + $__scholKeep, 2),
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
                log_message('error', "[FCS CLEANUP FALLBACK] batch failed, committing idemp+pending sequentially receipt={$receiptKey}");
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
            'bank_code'    => $bankLedgerCode,
            'receipt_no'   => $receiptKey,
            'student_name' => $studentName,
            'student_id'   => $userId,
            'class'        => "{$class} {$section}",
            'admin_id'     => $adminId,
            // Phase A0 (canonical fee-accounting normalization,
            // 2026-05-10) — pass allocation breakdown so the v2
            // journal builds per-fee-head credit lines instead of
            // crediting all revenue to 4010. See the matching block
            // in the async opsAcctPayload above for full rationale.
            'allocations'      => is_array($allocations ?? null) ? $allocations : [],
            'fine_amount'      => round((float) ($fineAmount ?? 0), 2),
            'discount_amount'  => round((float) ($discountFees ?? 0), 2),
            // Deterministic entry id so retries skip re-posting the
            // same journal — was implicit in the async path above
            // but missing from the sync deferred path until A0.
            'journal_entry_id' => "JE_FEE_{$receiptKey}",
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

                // Dashboard fee-collection rollup — increment this receipt's
                // amount into the maintained rollup so the dashboard renders
                // the fee chart/breakdown WITHOUT scanning receipts. Reads the
                // just-written receipt for the canonical allocated_amount.
                // Post-response + best-effort; the dashboard cross-check
                // (count+sum aggregation) rebuilds if this is ever dropped.
                try {
                    $schoolFs = (string) ($defaulterCtx['schoolFs'] ?? '');
                    if ($schoolFs !== '') {
                        $rcpt = $firebase->firestoreGet('feeReceipts', "{$schoolFs}_{$receiptKey}");
                        if (is_array($rcpt)) {
                            $amt = (float) ($rcpt['allocated_amount'] ?? $rcpt['allocatedAmount'] ?? $rcpt['amount'] ?? 0);
                            $dt  = (string) ($rcpt['paidAt'] ?? $rcpt['date'] ?? date('Y-m-d'));
                            $ci->load->library('Fee_collection_rollup');
                            Fee_collection_rollup::applyDelta($firebase, $schoolFs, $sessionYear, $amt, $dt, 1);
                        }
                    }
                } catch (\Throwable $e) {
                    log_message('error', "submit_fees: deferred rollup increment failed for {$receiptKey}: " . $e->getMessage());
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

    /**
     * Per-fee-head breakdown of what a receipt ACTUALLY paid.
     *
     * Built from the allocations — the demands the money was applied to —
     * rather than from the class fee structure. The structure describes what
     * a student is billed over a year; a receipt describes one payment. They
     * are different questions, and answering the second with the first
     * produced a receipt whose line items contradicted its own total.
     *
     * Ad-hoc charges (ID card replacement, transport add-ons, anything from
     * Student_charge_service) appear in NO structure, so a structure-derived
     * breakdown cannot represent them at all — it silently omits exactly the
     * charges a parent is most likely to query.
     *
     * Heads are summed, because one receipt can settle several demands under
     * the same head across different periods. `amount` is a 2dp string to
     * match the shape every existing consumer already parses; no `frequency`
     * key is emitted, since frequency is a property of a fee chart and has no
     * meaning for a single settled allocation.
     *
     * @param array $allocations rows of ['fee_head' => string, 'allocated' => float]
     * @return array<int, array{head: string, amount: string}>
     */
    private static function _breakdownFromAllocations(array $allocations): array
    {
        $byHead = [];
        foreach ($allocations as $a) {
            if (!is_array($a)) continue;
            $head = trim((string) ($a['fee_head'] ?? $a['feeHead'] ?? ''));
            $amt  = (float) ($a['allocated'] ?? 0);
            // A zero-value row carries no information on a receipt, and a
            // headless one cannot be rendered — both are skipped rather than
            // shown as an empty line.
            if ($head === '' || $amt <= 0) continue;
            $byHead[$head] = ($byHead[$head] ?? 0.0) + $amt;
        }

        $out = [];
        foreach ($byHead as $head => $amt) {
            $out[] = [
                'head'   => $head,
                'amount' => number_format(round($amt, 2), 2, '.', ''),
            ];
        }
        return $out;
    }

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

    // ─────────────────────────────────────────────────────────────────
    //  BUG-045 OP4-A — build (don't write) variants of the audit helpers.
    //  Mirror the existing _auditDemandUpdate / _auditReceiptWrite /
    //  _auditAfterBatch signatures but return batch-op arrays for
    //  inclusion in firestoreCommitBatch. Used by the batch_path success
    //  branch to embed audit writes atomically with financial writes.
    //  Original audit helpers remain in place for sequential fallback.
    // ─────────────────────────────────────────────────────────────────

    /** OP4-A — build (don't write) a per-demand audit op. Mirrors _auditDemandUpdate. */
    private static function _buildAuditDemandUpdateOp(
        $controller, string $schoolFs, string $session, array $data,
        string $demandId, array $before, array $after
    ): ?array {
        $logger = self::_auditLogger($controller, $schoolFs, $session);
        return $logger->buildOp(
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

    /** OP4-A — build (don't write) the receipt-creation audit op. Mirrors _auditReceiptWrite. */
    private static function _buildAuditReceiptWriteOp(
        $controller, string $schoolFs, string $session, array $data,
        string $receiptKey, array $receiptDoc
    ): ?array {
        $logger = self::_auditLogger($controller, $schoolFs, $session);
        return $logger->buildOp(
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

    /** OP4-A — build (don't write) audit ops for a successful batch. Mirrors _auditAfterBatch. */
    private static function _buildAuditOpsAfterBatch(
        $controller, string $schoolFs, string $session, array $data,
        string $receiptKey, string $receiptNo, string $userId,
        array $demandsBefore, array $allocationsForBatch, array $batchReceiptData
    ): array {
        $ops = [];
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
            $op = self::_buildAuditDemandUpdateOp($controller, $schoolFs, $session, $data, $did, $before, $after);
            if ($op !== null) $ops[] = $op;
        }
        $op = self::_buildAuditReceiptWriteOp($controller, $schoolFs, $session, $data, $receiptKey, $batchReceiptData);
        if ($op !== null) $ops[] = $op;
        return $ops;
    }
}
