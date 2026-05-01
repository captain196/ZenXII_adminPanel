<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_summary_writer — Phase 5 read-optimization writer.
 *
 * Rebuilds denormalised summary docs (classFeeSummary + studentFeeSummary)
 * from source-of-truth feeDemands + feeReceipts. Called from post-response
 * deferred hooks on receipt submit, refund process, and demand generation.
 *
 *  • WRITES ONLY — every summary is a full RECOMPUTE from source, not an
 *    incremental delta. Trades a few extra reads for crash-safety: a
 *    partial/dropped delta never corrupts the summary.
 *  • Fire-and-forget: callers always continue on error. Reader library
 *    falls back to source queries when a summary is stale/missing.
 *  • Batched via Fee_batch_writer so many summary updates in a tight
 *    loop (backfill, bulk generation) coalesce into few commits.
 *
 * Doc shapes are documented in the Phase 5 spec.
 */
final class Fee_summary_writer
{
    private const COL_DEMANDS           = 'feeDemands';
    private const COL_RECEIPTS          = 'feeReceipts';
    private const COL_STUDENTS          = 'students';
    private const COL_CLASS_SUMMARY     = 'classFeeSummary';
    private const COL_STUDENT_SUMMARY   = 'studentFeeSummary';
    private const COL_DEFAULTERS        = 'feeDefaulters';

    /** @var object Firebase wrapper */ private $firebase;
    /** @var string */                  private string $schoolId  = '';
    /** @var string */                  private string $session   = '';
    /** @var bool */                    private bool   $ready     = false;

    public function __construct() {}

    public function init(object $firebase, string $schoolId, string $session): void
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ready    = ($firebase !== null && $schoolId !== '' && $session !== '');
    }

    // ════════════════════════════════════════════════════════════════════
    //  PUBLIC API
    // ════════════════════════════════════════════════════════════════════

    /**
     * Recompute a single student's summary doc from their live demands +
     * receipts. Safe to call any time; idempotent.
     *
     * @return bool true on write success, false on any error.
     */
    public function updateStudentSummary(string $studentId): bool
    {
        if (!$this->ready || $studentId === '') return false;

        try {
            // ── Load demands for this student in this session ──
            $demands = $this->firebase->firestoreQuery(self::COL_DEMANDS, [
                ['schoolId',  '==', $this->schoolId],
                ['session',   '==', $this->session],
                ['studentId', '==', $studentId],
            ]);

            if (empty($demands)) {
                // No demands → emit a zero-state summary so reads don't
                // hit the fallback for this legitimate case.
                return $this->_writeStudentSummary($studentId, $this->_emptyStudentSummary($studentId));
            }

            $agg = $this->_aggregateStudentDemands($demands);

            // ── Enrich with student profile (name, class, section) ──
            $profile = $this->_loadStudent($studentId);
            $agg['studentName'] = (string) ($profile['name']       ?? $profile['studentName'] ?? '');
            $agg['className']   = (string) ($profile['className']  ?? '');
            $agg['section']     = (string) ($profile['section']    ?? '');
            $agg['fatherName']  = (string) ($profile['fatherName'] ?? '');

            // ── Last payment: read 1 most-recent receipt doc for this student ──
            $lastReceipt = $this->_findLastReceipt($studentId);
            $agg['lastPaymentDate'] = (string) ($lastReceipt['createdAt'] ?? $lastReceipt['date'] ?? '');
            $agg['lastReceiptNo']   = (string) ($lastReceipt['receiptNo'] ?? '');

            return $this->_writeStudentSummary($studentId, $agg);
        } catch (\Throwable $e) {
            log_message('error', "Fee_summary_writer::updateStudentSummary({$studentId}) failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Recompute a single (class, section, month) cell from live demands.
     * Touches all students in that cell — O(N_in_class) Firestore reads.
     *
     * $className and $section are the full human form ("Class 8th",
     * "Section A") as stored on demand docs.
     */
    public function updateClassSummary(string $className, string $section, string $month): bool
    {
        if (!$this->ready || $className === '' || $section === '' || $month === '') return false;

        try {
            // Fetch demands for class+section+month. The indexed path is
            // (schoolId, session, className, section) — month filter is
            // applied in-memory since demand docs store period like
            // "April 2026" rather than a clean month field.
            $rows = $this->firebase->firestoreQuery(self::COL_DEMANDS, [
                ['schoolId',  '==', $this->schoolId],
                ['session',   '==', $this->session],
                ['className', '==', $className],
                ['section',   '==', $section],
            ]);

            // Group by studentId; filter by the target month label.
            $byStudent = []; // sid => [demand, …]
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                $rowMonth = $this->_extractMonthFromDemand($d);
                if ($rowMonth !== $month) continue;
                $sid = (string) ($d['studentId'] ?? $d['student_id'] ?? '');
                if ($sid === '') continue;
                $byStudent[$sid][] = $d;
            }

            // Aggregate per-student status, then bucket the class rollup.
            $paid = 0; $partial = 0; $unpaid = 0;
            $totalDemanded = 0.0; $totalCollected = 0.0;
            $lastReceiptAt = '';
            foreach ($byStudent as $sid => $demands) {
                $stuStatus = $this->_rollupStatus($demands);
                if ($stuStatus['status'] === 'paid')        $paid++;
                elseif ($stuStatus['status'] === 'partial') $partial++;
                else                                         $unpaid++;
                $totalDemanded  += $stuStatus['demanded'];
                $totalCollected += $stuStatus['collected'];
                if ($stuStatus['lastReceiptAt'] !== '' && $stuStatus['lastReceiptAt'] > $lastReceiptAt) {
                    $lastReceiptAt = $stuStatus['lastReceiptAt'];
                }
            }

            $totalStudents = count($byStudent);

            $docId = $this->_classSummaryDocId($className, $section, $month);
            $doc = [
                'schoolId'        => $this->schoolId,
                'session'         => $this->session,
                'className'       => $className,
                'section'         => $section,
                'month'           => $month,
                'totalStudents'   => $totalStudents,
                'paidStudents'    => $paid,
                'partialStudents' => $partial,
                'unpaidStudents'  => $unpaid,
                'totalDemanded'   => round($totalDemanded, 2),
                'totalCollected'  => round($totalCollected, 2),
                'totalBalance'    => round($totalDemanded - $totalCollected, 2),
                'lastReceiptAt'   => $lastReceiptAt,
                'updatedAt'       => date('c'),
            ];
            return (bool) $this->firebase->firestoreSet(self::COL_CLASS_SUMMARY, $docId, $doc);
        } catch (\Throwable $e) {
            log_message('error', "Fee_summary_writer::updateClassSummary({$className}, {$section}, {$month}) failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Convenience: on a receipt submit, update BOTH the affected student
     * and every class-cell their payment touched. Call from the submit
     * pipeline's DEFERRED hook (post-response flush) so user perceived
     * latency is unaffected.
     *
     * @param string   $studentId
     * @param string[] $monthsTouched  ["April", "Yearly Fees", …]
     * @param string   $className      denormalised on receipt doc
     * @param string   $section
     */
    public function onReceiptWritten(string $studentId, array $monthsTouched, string $className, string $section): void
    {
        if (!$this->ready) return;
        $this->updateStudentSummary($studentId);
        foreach (array_unique($monthsTouched) as $m) {
            if ($m === '') continue;
            $this->updateClassSummary($className, $section, (string) $m);
        }
    }

    /**
     * Called after a refund voucher is written. Same work as a receipt
     * (recompute student + affected class cells).
     */
    public function onRefundProcessed(string $studentId, array $monthsTouched, string $className, string $section): void
    {
        $this->onReceiptWritten($studentId, $monthsTouched, $className, $section);
    }

    /**
     * After bulk demand generation for a scope, refresh every class cell
     * and each touched student. Called from _processGenerationJob's
     * final-step hook. Intentionally sequential — generation itself ran
     * asynchronously; a few extra seconds to refresh summaries is fine.
     *
     * @param array $classSections [['class'=>..., 'section'=>...], …]
     * @param array $months        ["April", …]
     * @param array $touchedSids   student IDs whose demands were written
     */
    public function onBulkDemandsGenerated(array $classSections, array $months, array $touchedSids): void
    {
        if (!$this->ready) return;

        // Class cells first — bounded (sections × months), usually ≤100 docs.
        foreach ($classSections as $cs) {
            $cls = (string) ($cs['class']   ?? '');
            $sec = (string) ($cs['section'] ?? '');
            if ($cls === '' || $sec === '') continue;
            foreach ($months as $m) {
                $this->updateClassSummary($cls, $sec, (string) $m);
            }
            // Also cover the "Yearly Fees" cell since annual heads are
            // stored under month='April' in the generator but surfaced
            // as their own cell in the teacher UI.
            if (in_array('April', $months, true)) {
                $this->updateClassSummary($cls, $sec, 'Yearly Fees');
            }
        }

        // Student summaries — one per touched sid.
        foreach (array_unique($touchedSids) as $sid) {
            if ($sid === '') continue;
            $this->updateStudentSummary($sid);
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  INTERNAL
    // ════════════════════════════════════════════════════════════════════

    private function _aggregateStudentDemands(array $demands): array
    {
        $totalDemanded = 0.0;
        $totalCollected = 0.0;
        $paidMonths = [];
        $partialMonths = [];
        $unpaidMonths = [];
        $monthsSeen = []; // month => ['demanded'=>, 'paid'=>]

        foreach ($demands as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            $month = $this->_extractMonthFromDemand($d);
            if ($month === '') continue;
            $net  = (float) ($d['netAmount']  ?? $d['net_amount']  ?? 0);
            $paid = (float) ($d['paidAmount'] ?? $d['paid_amount'] ?? 0);
            $totalDemanded  += $net;
            $totalCollected += $paid;
            if (!isset($monthsSeen[$month])) {
                $monthsSeen[$month] = ['demanded' => 0.0, 'paid' => 0.0];
            }
            $monthsSeen[$month]['demanded'] += $net;
            $monthsSeen[$month]['paid']     += $paid;
        }

        foreach ($monthsSeen as $m => $v) {
            $bal = $v['demanded'] - $v['paid'];
            if ($bal <= 0.005)              $paidMonths[]    = $m;
            elseif ($v['paid'] > 0.005)     $partialMonths[] = $m;
            else                             $unpaidMonths[] = $m;
        }

        $status = 'unpaid';
        $balance = $totalDemanded - $totalCollected;
        if ($balance <= 0.005 && $totalDemanded > 0) $status = 'paid';
        elseif ($totalCollected > 0.005)              $status = 'partial';

        return [
            'totalDemanded'  => round($totalDemanded, 2),
            'totalCollected' => round($totalCollected, 2),
            'totalBalance'   => round($balance, 2),
            'paidMonths'     => array_values($paidMonths),
            'partialMonths'  => array_values($partialMonths),
            'unpaidMonths'   => array_values($unpaidMonths),
            'status'         => $status,
        ];
    }

    /** Roll up a class-cell's student list for the outer class-summary. */
    private function _rollupStatus(array $demands): array
    {
        $dem = 0.0; $paid = 0.0; $lastAt = '';
        foreach ($demands as $d) {
            $dem  += (float) ($d['netAmount']  ?? $d['net_amount']  ?? 0);
            $paid += (float) ($d['paidAmount'] ?? $d['paid_amount'] ?? 0);
            $u = (string) ($d['updatedAt'] ?? '');
            if ($u > $lastAt) $lastAt = $u;
        }
        $status = 'unpaid';
        if (($dem - $paid) <= 0.005 && $dem > 0) $status = 'paid';
        elseif ($paid > 0.005)                    $status = 'partial';
        return ['status' => $status, 'demanded' => $dem, 'collected' => $paid, 'lastReceiptAt' => $lastAt];
    }

    /** Derive month label from a demand doc; prefers explicit `month` field. */
    private function _extractMonthFromDemand(array $d): string
    {
        $month = (string) ($d['month'] ?? '');
        if ($month !== '') return $month;
        $period = (string) ($d['period'] ?? '');
        if ($period === '') return '';
        // "April 2026" → "April"
        return (string) preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', $period);
    }

    private function _writeStudentSummary(string $studentId, array $agg): bool
    {
        $docId = $this->_studentSummaryDocId($studentId);
        $base = [
            'schoolId'   => $this->schoolId,
            'session'    => $this->session,
            'studentId'  => $studentId,
            'updatedAt'  => date('c'),
        ];
        try {
            return (bool) $this->firebase->firestoreSet(self::COL_STUDENT_SUMMARY, $docId, array_merge($base, $agg));
        } catch (\Throwable $e) {
            log_message('error', "Fee_summary_writer::_writeStudentSummary({$studentId}) failed: " . $e->getMessage());
            return false;
        }
    }

    private function _emptyStudentSummary(string $studentId): array
    {
        return [
            'studentName'     => '',
            'className'       => '',
            'section'         => '',
            'fatherName'      => '',
            'totalDemanded'   => 0.0,
            'totalCollected'  => 0.0,
            'totalBalance'    => 0.0,
            'paidMonths'      => [],
            'partialMonths'   => [],
            'unpaidMonths'    => [],
            'status'          => 'unpaid',
            'lastPaymentDate' => '',
            'lastReceiptNo'   => '',
        ];
    }

    private function _loadStudent(string $studentId): array
    {
        try {
            $docId = "{$this->schoolId}_{$studentId}";
            $doc = $this->firebase->firestoreGet(self::COL_STUDENTS, $docId);
            return is_array($doc) ? $doc : [];
        } catch (\Throwable $_) { return []; }
    }

    private function _findLastReceipt(string $studentId): array
    {
        try {
            $rows = $this->firebase->firestoreQuery(self::COL_RECEIPTS, [
                ['schoolId',  '==', $this->schoolId],
                ['studentId', '==', $studentId],
            ], 'createdAt', 'DESC', 1);
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                if (is_array($d)) return $d;
            }
        } catch (\Throwable $_) { /* ignore */ }
        return [];
    }

    private function _classSummaryDocId(string $className, string $section, string $month): string
    {
        // Match the {schoolId}_{session}_{class}_{section}_{month} shape.
        $clsKey = preg_replace('/\s+/', '_', trim($className));
        $secKey = preg_replace('/\s+/', '_', trim($section));
        $monKey = preg_replace('/\s+/', '_', trim($month));
        return "{$this->schoolId}_{$this->session}_{$clsKey}_{$secKey}_{$monKey}";
    }

    private function _studentSummaryDocId(string $studentId): string
    {
        return "{$this->schoolId}_{$this->session}_{$studentId}";
    }
}
