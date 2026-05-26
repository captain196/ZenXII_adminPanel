<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fees_test_residue_audit — READ-ONLY forensic audit for the
 * suspected test-residue receipt cluster surfaced by
 * Dashboard_fees_check (10× ₹2,800 from STU0001 on 22-23 May 2026).
 *
 * MODE: DIAGNOSTIC ONLY. This controller performs ZERO mutations.
 * No deletes, no updates, no journal reversals. It enumerates the
 * full downstream linkage graph for each suspect receipt so the
 * operator can evaluate whether a future bounded cleanup window
 * is safe and reversible.
 *
 * Per receipt, the report surfaces:
 *   • Receipt core fields (id, studentId, amount, paid date, mode,
 *     source, createdAt, gatewayPaymentId, status, receiptNo)
 *   • feeReceiptAllocations linkage (allocation child docs)
 *   • accountingLedger linkage (JE doc id, sourceRef / meta.receiptId
 *     match, doc-id fragment match — the canonical Firestore mirror
 *     of Operations_accounting JE writes)
 *   • accountingIdempotency claim (JE_FEE_{receiptKey} pattern)
 *   • feeOnlineOrders / gateway linkage (gatewayPaymentId)
 *   • feeDemands impact (which demand months/items were allocated)
 *   • feeDefaulters cascade (would deletion flip a paid month to
 *     defaulter, and for which months)
 *   • audit-log references (best-effort by entityId match)
 *   • rollback feasibility classification
 *   • orphan-risk classification
 *
 * The report ENDS with a deletion plan PREVIEW (operation order,
 * idempotency safety, cache invalidation requirements). The plan
 * is documentation only — no APPLY phase here.
 *
 * INVOCATION (defaults to the 2026-05-22/23 STU0001 ₹2,800 cluster):
 *   php index.php fees_test_residue_audit check
 *
 * INVOCATION (custom cluster):
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     RECEIPT_STUDENT=STU0001 RECEIPT_AMOUNT=2800 \
 *     DATE_FROM=2026-05-22 DATE_TO=2026-05-23 \
 *     php index.php fees_test_residue_audit check
 */
class Fees_test_residue_audit extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';
    private string $studentId   = '';
    private float  $amount      = 0.0;
    private string $dateFrom    = '';
    private string $dateTo      = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) show_error('CLI-only.', 403);
        $this->load->library('firebase');
        $this->load->library('firestore_service');
        $this->schoolFs    = (string) (getenv('SCHOOL_ID')        ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR')     ?: '');
        $this->studentId   = (string) (getenv('RECEIPT_STUDENT')  ?: 'STU0001');
        $this->amount      = (float)  (getenv('RECEIPT_AMOUNT')   ?: 2800);
        $this->dateFrom    = (string) (getenv('DATE_FROM')        ?: '2026-05-22');
        $this->dateTo      = (string) (getenv('DATE_TO')          ?: '2026-05-23');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR env vars.\n";
            exit(1);
        }
    }

    public function check(): void
    {
        echo "═══════════════════════════════════════════════════════════════════\n";
        echo " Fees Test-Residue Forensic Audit — READ-ONLY (no mutations)\n";
        echo "═══════════════════════════════════════════════════════════════════\n";
        echo " schoolFs:    {$this->schoolFs}\n";
        echo " session:     {$this->sessionYear}\n";
        echo " studentId:   {$this->studentId}\n";
        echo " amount:      ₹" . number_format($this->amount, 2) . "\n";
        echo " date range:  {$this->dateFrom} … {$this->dateTo} (inclusive)\n";
        echo " mode:        DIAGNOSTIC ONLY — zero writes, zero deletes.\n\n";

        // ── 1. Locate suspect receipts ──────────────────────────────────
        echo "── Step 1. Locate suspect receipts ──────────────────────────────\n";
        $receipts = $this->firebase->firestoreQuery('feeReceipts', [
            ['schoolId',  '==', $this->schoolFs],
            ['session',   '==', $this->sessionYear],
            ['studentId', '==', $this->studentId],
        ], null, 'ASC', 2000);

        $candidates = [];
        foreach ($receipts as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $amt = (float) ($d['allocated_amount'] ?? $d['allocatedAmount'] ?? $d['amount'] ?? 0);
            if (abs($amt - $this->amount) > 0.01) continue;

            $dateStr = (string) ($d['paidAt'] ?? $d['date'] ?? '');
            $iso = $this->_iso($dateStr);
            if ($iso === '') continue;
            if ($iso < $this->dateFrom || $iso > $this->dateTo) continue;

            $candidates[] = [
                'docId'      => (string) ($r['id'] ?? ''),
                'data'       => $d,
                'isoDate'    => $iso,
                'allocAmt'   => $amt,
            ];
        }

        echo "  Found " . count($candidates) . " candidate receipt(s) matching the cluster signature.\n";
        if (empty($candidates)) {
            echo "  (no matches — nothing to audit)\n";
            return;
        }
        echo "\n";

        // ── 2. Pre-load linkage indexes ─────────────────────────────────
        echo "── Step 2. Pre-load linkage indexes ─────────────────────────────\n";

        // accountingLedger (canonical Firestore mirror of JE writes from
        // Operations_accounting). Scoped by schoolId; doc-id pattern is
        // {schoolCode}_{session}_{entryId} so a substring match on the
        // receipt key inside sourceRef / meta gives us the linkage.
        $journals = $this->firebase->firestoreQuery('accountingLedger', [
            ['schoolId', '==', $this->schoolFs],
        ], null, 'ASC', 5000);
        echo "  accountingLedger scanned:     " . count($journals) . "\n";

        // accountingIdempotency keyed by JE_FEE_{receiptKey}
        $idempScan = [];
        try {
            $idempScan = $this->firebase->firestoreQuery('accountingIdempotency', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 5000);
        } catch (\Throwable $e) {
            echo "  accountingIdempotency scan error: " . $e->getMessage() . "\n";
        }
        echo "  accountingIdempotency scanned:" . count($idempScan) . "\n";

        // feeOnlineOrders (Razorpay)
        $orders = [];
        try {
            $orders = $this->firebase->firestoreQuery('feeOnlineOrders', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 5000);
        } catch (\Throwable $e) {
            echo "  feeOnlineOrders scan error:   " . $e->getMessage() . "\n";
        }
        echo "  feeOnlineOrders scanned:      " . count($orders) . "\n";

        // feeReceiptAllocations (child docs)
        $allocs = [];
        try {
            $allocs = $this->firebase->firestoreQuery('feeReceiptAllocations', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->sessionYear],
            ], null, 'ASC', 5000);
        } catch (\Throwable $e) {
            echo "  feeReceiptAllocations scan error: " . $e->getMessage() . "\n";
        }
        echo "  feeReceiptAllocations scanned:" . count($allocs) . "\n";

        // feeDemands for the student
        $demands = [];
        try {
            $demands = $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId',  '==', $this->schoolFs],
                ['session',   '==', $this->sessionYear],
                ['studentId', '==', $this->studentId],
            ], null, 'ASC', 1000);
        } catch (\Throwable $e) {
            echo "  feeDemands scan error:        " . $e->getMessage() . "\n";
        }
        echo "  feeDemands scanned (student): " . count($demands) . "\n";

        // current feeDefaulters row for student
        $defRow = null;
        try {
            $defDocs = $this->firebase->firestoreQuery('feeDefaulters', [
                ['schoolId',  '==', $this->schoolFs],
                ['session',   '==', $this->sessionYear],
                ['studentId', '==', $this->studentId],
            ], null, 'ASC', 5);
            $defRow = !empty($defDocs) ? ($defDocs[0]['data'] ?? null) : null;
        } catch (\Throwable $e) {
            echo "  feeDefaulters scan error:     " . $e->getMessage() . "\n";
        }
        echo "  feeDefaulters row for student:" . ($defRow ? "present" : "absent") . "\n";

        // audit_logs entityId match (best-effort)
        $auditLogs = [];
        try {
            $auditLogs = $this->firebase->firestoreQuery('audit_logs', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 5000);
        } catch (\Throwable $e) {
            // optional
        }
        echo "  audit_logs scanned (best effort): " . count($auditLogs) . "\n\n";

        // ── 3. Per-receipt forensic dossier ─────────────────────────────
        echo "── Step 3. Per-receipt forensic dossier ─────────────────────────\n";
        $clusterDeleteSafe   = 0;
        $clusterDeleteRisky  = 0;
        $clusterTotalAmount  = 0.0;
        $journalReversalList = [];

        foreach ($candidates as $i => $c) {
            $docId = $c['docId'];
            $d     = $c['data'];
            $idx   = $i + 1;
            $receiptNo = (string) ($d['receiptNo'] ?? $d['receipt_no'] ?? '');
            $clusterTotalAmount += $c['allocAmt'];

            echo "\n  ─── Receipt #{$idx}: {$docId} (receiptNo={$receiptNo}) ──────────\n";

            // Core fields
            echo "    Core:\n";
            echo "      docId:           {$docId}\n";
            echo "      receiptNo:       {$receiptNo}\n";
            echo "      studentId:       " . (string)($d['studentId'] ?? '') . "\n";
            echo "      allocated_amount:₹" . number_format($c['allocAmt'], 2) . "\n";
            echo "      paidAt/date:     " . (string)($d['paidAt'] ?? $d['date'] ?? '') . " (iso={$c['isoDate']})\n";
            echo "      mode:            " . (string)($d['mode'] ?? $d['paymentMode'] ?? '(none)') . "\n";
            echo "      source:          " . (string)($d['source'] ?? '(none)') . "\n";
            echo "      status:          " . (string)($d['status'] ?? '(none)') . "\n";
            echo "      gatewayPaymentId:" . (string)($d['gatewayPaymentId'] ?? $d['gateway_payment_id'] ?? '(none)') . "\n";
            echo "      createdAt:       " . (string)($d['createdAt'] ?? '(none)') . "\n";

            // feeReceiptAllocations — canonical link field is `receiptKey`
            // (see FeeCollectionService:801 and :1253-1268). doc-id pattern
            // is {schoolFs}_{session}_{receiptKey}, so we also match by id
            // substring as belt-and-suspenders for any drift.
            $expectedAllocDocId = "{$this->schoolFs}_{$this->sessionYear}_" . substr($docId, strlen($this->schoolFs) + 1);
            $relatedAllocs = array_filter($allocs, function ($a) use ($docId, $expectedAllocDocId) {
                $ad = is_array($a['data'] ?? null) ? $a['data'] : [];
                $rk  = (string) ($ad['receiptKey'] ?? '');
                $rid = (string) ($ad['receiptId'] ?? $ad['receipt_id'] ?? '');
                $aid = (string) ($a['id'] ?? '');
                $receiptKeySuffix = substr($docId, strlen($this->schoolFs) + 1); // F11, F12, etc.
                return ($rk === $receiptKeySuffix)
                    || ($rid === $docId)
                    || ($aid === $expectedAllocDocId)
                    || (stripos($aid, $receiptKeySuffix) !== false);
            });
            echo "    feeReceiptAllocations: " . count($relatedAllocs) . " linked doc(s)";
            if (!empty($relatedAllocs)) {
                echo " (expected docId: {$expectedAllocDocId})";
            }
            echo "\n";
            foreach ($relatedAllocs as $a) {
                $ad = is_array($a['data'] ?? null) ? $a['data'] : [];
                $aid = (string) ($a['id'] ?? '');
                $allocList = is_array($ad['allocations'] ?? null) ? $ad['allocations'] : [];
                $netRec = (float)  ($ad['netReceived'] ?? $ad['amount'] ?? 0);
                $allocCount = count($allocList);
                echo "      • {$aid}  allocations={$allocCount}  netReceived=₹" . number_format($netRec, 2) . "\n";
                foreach ($allocList as $line) {
                    $m = (string) ($line['period'] ?? $line['month'] ?? '?');
                    $h = (string) ($line['fee_head'] ?? $line['head'] ?? '?');
                    $amt = (float) ($line['allocated'] ?? $line['amount'] ?? 0);
                    echo "          - {$m} / {$h} → ₹" . number_format($amt, 2) . "\n";
                }
            }

            // accountingLedger — sourceRef / meta.receiptId match. Also
            // try matching the JE doc-id pattern fragment (entryId often
            // includes the receipt key when minted via the fee flow).
            $relatedJEs = [];
            foreach ($journals as $j) {
                $jd = is_array($j['data'] ?? null) ? $j['data'] : [];
                $jId = (string) ($j['id'] ?? '');
                $srcRef = (string) ($jd['sourceRef'] ?? $jd['source_ref'] ?? '');
                $meta   = is_array($jd['meta'] ?? null) ? $jd['meta'] : [];
                $metaR  = (string) ($meta['receiptId'] ?? $meta['receipt_id'] ?? '');
                $hit = ($srcRef === $docId) || ($metaR === $docId)
                    || ($srcRef === $receiptNo) || (stripos($jId, $docId) !== false);
                if ($hit) $relatedJEs[] = $j;
            }
            echo "    accountingLedger:      " . count($relatedJEs) . " linked JE doc(s)\n";
            foreach ($relatedJEs as $j) {
                $jd = is_array($j['data'] ?? null) ? $j['data'] : [];
                $jeId = (string) ($j['id'] ?? '');
                $vType = (string) ($jd['voucherType'] ?? $jd['voucher_type'] ?? '?');
                $src   = (string) ($jd['source'] ?? '?');
                $amt   = (float)  ($jd['amount'] ?? 0);
                $stat  = (string) ($jd['status'] ?? '?');
                echo "      • {$jeId}  voucherType={$vType}  source={$src}  ₹" . number_format($amt, 2) . "  status={$stat}\n";
                $journalReversalList[] = ['receiptDoc' => $docId, 'je' => $jeId, 'amount' => $amt, 'status' => $stat];
            }

            // accountingIdempotency — JE_FEE_{receiptKey}
            $expectedIdempKey = "JE_FEE_{$docId}";
            $relatedIdemp = [];
            foreach ($idempScan as $ix) {
                $id   = (string) ($ix['id'] ?? '');
                $ixd  = is_array($ix['data'] ?? null) ? $ix['data'] : [];
                $key  = (string) ($ixd['idempotencyKey'] ?? $ixd['idemp_key'] ?? $ixd['key'] ?? '');
                if ($key === $expectedIdempKey || stripos($id, $expectedIdempKey) !== false || stripos($id, $docId) !== false) {
                    $relatedIdemp[] = ['id' => $id, 'key' => $key, 'data' => $ixd];
                }
            }
            echo "    accountingIdempotency: " . count($relatedIdemp) . " claim(s) (expected key: {$expectedIdempKey})\n";
            foreach ($relatedIdemp as $ix) {
                echo "      • id={$ix['id']}  key={$ix['key']}  status=" . (string)($ix['data']['status'] ?? '?') . "\n";
            }

            // feeOnlineOrders — gateway linkage
            $gatewayPaymentId = (string) ($d['gatewayPaymentId'] ?? $d['gateway_payment_id'] ?? '');
            $relatedOrders = [];
            if ($gatewayPaymentId !== '') {
                foreach ($orders as $o) {
                    $od = is_array($o['data'] ?? null) ? $o['data'] : [];
                    $opid = (string) ($od['gateway_payment_id'] ?? $od['gatewayPaymentId'] ?? '');
                    $orid = (string) ($od['receiptId'] ?? $od['receipt_id'] ?? '');
                    if ($opid === $gatewayPaymentId || $orid === $docId) {
                        $relatedOrders[] = $o;
                    }
                }
            }
            echo "    feeOnlineOrders:       " . count($relatedOrders) . " linked order(s)\n";
            foreach ($relatedOrders as $o) {
                $od = is_array($o['data'] ?? null) ? $o['data'] : [];
                $oid = (string) ($o['id'] ?? '');
                echo "      • {$oid}  gatewayPaymentId=" . (string)($od['gateway_payment_id'] ?? '?') .
                     "  status=" . (string)($od['status'] ?? '?') . "\n";
            }

            // feeDemands impact — which demand months reference this receipt via allocations
            $touchedDemandMonths = [];
            foreach ($relatedAllocs as $a) {
                $ad = is_array($a['data'] ?? null) ? $a['data'] : [];
                $month = (string) ($ad['month'] ?? $ad['period'] ?? '');
                if ($month !== '') $touchedDemandMonths[$month] = true;
            }
            echo "    feeDemands months touched: " . (empty($touchedDemandMonths) ? '(none)' : implode(', ', array_keys($touchedDemandMonths))) . "\n";

            // audit_logs — entityId match
            $relatedLogs = [];
            foreach ($auditLogs as $L) {
                $ld   = is_array($L['data'] ?? null) ? $L['data'] : [];
                $eid  = (string) ($ld['entityId'] ?? $ld['entity_id'] ?? '');
                $eType = (string) ($ld['entityType'] ?? $ld['entity_type'] ?? '');
                if ($eid === $docId || $eid === $receiptNo) {
                    $relatedLogs[] = ['id' => $L['id'] ?? '', 'type' => $eType, 'action' => (string)($ld['action'] ?? '?'), 'ts' => (string)($ld['timestamp'] ?? $ld['createdAt'] ?? '?')];
                }
            }
            echo "    audit_logs references: " . count($relatedLogs) . " match(es)\n";
            foreach ($relatedLogs as $L) {
                echo "      • {$L['id']}  type={$L['type']}  action={$L['action']}  ts={$L['ts']}\n";
            }

            // Rollback feasibility classification
            echo "    Rollback feasibility:\n";
            $needsJEReversal = !empty($relatedJEs);
            $needsAllocCleanup = !empty($relatedAllocs);
            $needsIdempCleanup = !empty($relatedIdemp);
            $needsOrderCleanup = !empty($relatedOrders);
            $needsDefaulterRecompute = !empty($touchedDemandMonths);
            echo "      • JE reversal required:        " . ($needsJEReversal     ? "YES ({$expectedIdempKey})" : "no") . "\n";
            echo "      • Allocation cleanup required: " . ($needsAllocCleanup   ? "YES (" . count($relatedAllocs) . " docs)" : "no") . "\n";
            echo "      • Idempotency cleanup:         " . ($needsIdempCleanup   ? "YES (" . count($relatedIdemp) . " keys)" : "no") . "\n";
            echo "      • Gateway order linkage:       " . ($needsOrderCleanup   ? "YES (treat as refund-or-orphan decision)" : "no — manual/offline receipt") . "\n";
            echo "      • Defaulter recompute:         " . ($needsDefaulterRecompute ? "YES (months: " . implode(',', array_keys($touchedDemandMonths)) . ")" : "no") . "\n";

            // Orphan-risk classification
            $orphanRisk = 'LOW';
            if ($needsOrderCleanup) $orphanRisk = 'HIGH (real Razorpay payment — refund-vs-orphan decision required)';
            elseif ($needsJEReversal) $orphanRisk = 'MEDIUM (accounting JE will need explicit reversal to keep Σ=0)';
            elseif ($needsAllocCleanup) $orphanRisk = 'LOW-MEDIUM (allocation rows will be orphans; defaulter recompute fixes downstream)';
            echo "    Orphan risk: {$orphanRisk}\n";

            if ($orphanRisk === 'LOW' || stripos($orphanRisk, 'LOW') === 0) $clusterDeleteSafe++;
            else $clusterDeleteRisky++;
        }

        // ── 4. Parent-app balance dependency check ──────────────────────
        echo "\n── Step 4. Parent-app balance dependency ────────────────────────\n";
        // The parent app reads feeDemands.totalAmount / feeDefaulters.totalDues
        // and a recent receipts list from feeReceipts. If any of the suspect
        // receipts are within the most-recent-N window the parent app shows,
        // their disappearance would be parent-visible.
        $currentDefDues = $defRow ? (float) ($defRow['totalDues'] ?? 0) : 0.0;
        echo "  Current feeDefaulters row for {$this->studentId}:\n";
        if ($defRow) {
            echo "    totalDues:    ₹" . number_format($currentDefDues, 2) . "\n";
            $unpaidMonths = is_array($defRow['unpaidMonths'] ?? null) ? $defRow['unpaidMonths'] : [];
            echo "    unpaidMonths: " . (empty($unpaidMonths) ? '(none)' : implode(', ', $unpaidMonths)) . "\n";
            echo "    examBlocked:  " . (!empty($defRow['examBlocked']) ? 'true' : 'false') . "\n";
        } else {
            echo "    (no row — student currently shown as paid-up; deletion would CREATE a defaulter row)\n";
        }

        // Simulate post-deletion: which demand months would re-open?
        $impactedMonths = [];
        foreach ($candidates as $c) {
            $docId = $c['docId'];
            foreach ($allocs as $a) {
                $ad = is_array($a['data'] ?? null) ? $a['data'] : [];
                $rid = (string) ($ad['receiptId'] ?? $ad['receipt_id'] ?? '');
                if ($rid !== $docId) continue;
                $month = (string) ($ad['month'] ?? $ad['period'] ?? '');
                if ($month === '') continue;
                $impactedMonths[$month] = ($impactedMonths[$month] ?? 0) + (float)($ad['amount'] ?? $ad['allocatedAmount'] ?? 0);
            }
        }
        echo "  Post-deletion demand-reopen simulation:\n";
        if (empty($impactedMonths)) {
            echo "    (no allocation rows found — receipts may be unallocated drafts or test stubs)\n";
        } else {
            foreach ($impactedMonths as $month => $sum) {
                echo "    {$month}: ₹" . number_format($sum, 2) . " would un-allocate from demand → defaulter recompute required\n";
            }
        }

        // ── 5. Cluster summary
        echo "\n── Step 5. Cluster summary ──────────────────────────────────────\n";
        echo "  Total receipts in cluster:   " . count($candidates) . "\n";
        echo "  Total amount in cluster:     ₹" . number_format($clusterTotalAmount, 2) . "\n";
        echo "  Delete-safe candidates:      {$clusterDeleteSafe}\n";
        echo "  Risky candidates:            {$clusterDeleteRisky}\n";
        echo "  JE reversals required:       " . count($journalReversalList) . "\n";

        // ── 6. Deletion plan PREVIEW (documentation only)
        echo "\n── Step 6. Deletion plan PREVIEW (documentation only — NOT executed) ──\n";
        echo "  Suggested operation order for a future APPLY window:\n";
        echo "    1. Freeze window: stop fee receipts + payroll runs until cleanup completes.\n";
        echo "    2. For each gateway-linked receipt (orphan risk HIGH):\n";
        echo "         decide refund-or-keep with operator. Online payments cannot\n";
        echo "         silently disappear without breaking Razorpay reconciliation.\n";
        echo "    3. For each accountingJournals row (status='posted'):\n";
        echo "         post a REVERSAL JE via Operations_accounting::create_journal()\n";
        echo "         with source='fee_refund', voucherType='Refund', sourceRef={receipt}\n";
        echo "         claim idempotency key 'JE_REFUND_{receiptDoc}'.\n";
        echo "         Reversal sums must equal original sums (Σ closing balance unchanged).\n";
        echo "    4. Delete child docs in order: feeReceiptAllocations → feeReceipts.\n";
        echo "    5. Delete idempotency claim 'JE_FEE_{receiptDoc}' so a future legitimate\n";
        echo "         receipt with the same key can post.\n";
        echo "    6. Trigger Fee_defaulter_check recompute for the impacted student+months.\n";
        echo "    7. Invalidate dashboard cache (charts + data + activity per role).\n";
        echo "    8. Re-run Dashboard_fees_check + Accounting reconciler to confirm Σ=0\n";
        echo "         and no orphaned JEs / allocations / idempotency claims.\n\n";

        echo "  Idempotency safety:\n";
        echo "    • Reversal JE has its own idempotency key — safe to retry.\n";
        echo "    • Allocation deletion is idempotent (404-on-second-call tolerated).\n";
        echo "    • Receipt deletion must be LAST — anything pointing to a missing receipt\n";
        echo "      becomes an orphan that the reconciler will flag.\n\n";

        echo "  Cache invalidation requirements (post-cleanup):\n";
        echo "    • application/cache/dashboard/grader_dash_v1_*_dashboard_charts_*.json\n";
        echo "    • application/cache/dashboard/grader_dash_v1_*_dashboard_data_*.json\n";
        echo "    • application/cache/dashboard/grader_dash_v1_*_dashboard_activity_*.json\n";

        echo "\n═══════════════════════════════════════════════════════════════════\n";
        echo " End audit — NO mutations performed. Cluster ready for operator review.\n";
        echo "═══════════════════════════════════════════════════════════════════\n";
    }

    private function _iso(string $dateStr): string
    {
        if ($dateStr === '') return '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dateStr)) {
            return substr($dateStr, 0, 10);
        }
        $ts = strtotime($dateStr);
        return $ts ? date('Y-m-d', $ts) : '';
    }
}
