<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_summary_reader — Phase 5 read path with graceful fallback.
 *
 *  • Tries the denormalised summary doc FIRST (1 Firestore get).
 *  • On MISS (doc missing) or STALE (updatedAt older than staleSec),
 *    falls back to recomputing from live feeDemands + warns via log.
 *  • Callers get the same response shape whether summary or fallback.
 *
 * Teacher + admin code should read through this library instead of
 * running their own aggregations.
 */
final class Fee_summary_reader
{
    private const COL_DEMANDS         = 'feeDemands';
    private const COL_STUDENT_SUMMARY = 'studentFeeSummary';
    private const COL_CLASS_SUMMARY   = 'classFeeSummary';

    /** Staleness threshold. A summary older than this is treated as
     *  missing and recomputed from source. 5 min covers typical write
     *  propagation delays while still catching a broken writer. */
    private const STALE_AFTER_SEC = 300;

    private $firebase;
    private string $schoolId = '';
    private string $session  = '';
    private bool   $ready    = false;

    public function init(object $firebase, string $schoolId, string $session): void
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ready    = ($firebase !== null && $schoolId !== '' && $session !== '');
    }

    /**
     * One summary doc per (class, section, month). Returns the canonical
     * fields whether read from summary or recomputed. `_source` signals
     * which path served this call — 'summary' | 'fallback'.
     */
    public function getClassSummary(string $className, string $section, string $month): array
    {
        if (!$this->ready) return [];

        $docId = $this->_classSummaryDocId($className, $section, $month);
        try {
            $doc = $this->firebase->firestoreGet(self::COL_CLASS_SUMMARY, $docId);
            if (is_array($doc) && !$this->_isStale($doc)) {
                $doc['_source'] = 'summary';
                return $doc;
            }
        } catch (\Throwable $_) { /* fall through to recompute */ }

        log_message('warning', "Fee_summary_reader::getClassSummary fallback class={$className} section={$section} month={$month}");
        return $this->_recomputeClass($className, $section, $month);
    }

    public function getStudentSummary(string $studentId): array
    {
        if (!$this->ready || $studentId === '') return [];

        $docId = "{$this->schoolId}_{$this->session}_{$studentId}";
        try {
            $doc = $this->firebase->firestoreGet(self::COL_STUDENT_SUMMARY, $docId);
            if (is_array($doc) && !$this->_isStale($doc)) {
                $doc['_source'] = 'summary';
                return $doc;
            }
        } catch (\Throwable $_) { /* fall through */ }

        log_message('warning', "Fee_summary_reader::getStudentSummary fallback student={$studentId}");
        return $this->_recomputeStudent($studentId);
    }

    // ════════════════════════════════════════════════════════════════════
    //  FALLBACK paths — same logic the writer uses
    // ════════════════════════════════════════════════════════════════════

    private function _recomputeClass(string $className, string $section, string $month): array
    {
        try {
            $rows = $this->firebase->firestoreQuery(self::COL_DEMANDS, [
                ['schoolId',  '==', $this->schoolId],
                ['session',   '==', $this->session],
                ['className', '==', $className],
                ['section',   '==', $section],
            ]);
            $byStudent = [];
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                if ($this->_extractMonth($d) !== $month) continue;
                $sid = (string) ($d['studentId'] ?? '');
                if ($sid !== '') $byStudent[$sid][] = $d;
            }
            $paid = 0; $partial = 0; $unpaid = 0;
            $dem  = 0.0; $col = 0.0; $lastAt = '';
            foreach ($byStudent as $sid => $demands) {
                $sDem = 0.0; $sCol = 0.0;
                foreach ($demands as $d) {
                    $sDem += (float) ($d['netAmount']  ?? $d['net_amount']  ?? 0);
                    $sCol += (float) ($d['paidAmount'] ?? $d['paid_amount'] ?? 0);
                    $u = (string) ($d['updatedAt'] ?? '');
                    if ($u > $lastAt) $lastAt = $u;
                }
                $dem += $sDem; $col += $sCol;
                $bal = $sDem - $sCol;
                if ($bal <= 0.005 && $sDem > 0) $paid++;
                elseif ($sCol > 0.005)           $partial++;
                else                              $unpaid++;
            }
            return [
                '_source'         => 'fallback',
                'schoolId'        => $this->schoolId,
                'session'         => $this->session,
                'className'       => $className,
                'section'         => $section,
                'month'           => $month,
                'totalStudents'   => count($byStudent),
                'paidStudents'    => $paid,
                'partialStudents' => $partial,
                'unpaidStudents'  => $unpaid,
                'totalDemanded'   => round($dem, 2),
                'totalCollected'  => round($col, 2),
                'totalBalance'    => round($dem - $col, 2),
                'lastReceiptAt'   => $lastAt,
                'updatedAt'       => date('c'),
            ];
        } catch (\Throwable $e) {
            log_message('error', 'Fee_summary_reader::_recomputeClass failed: ' . $e->getMessage());
            return ['_source' => 'fallback', '_error' => $e->getMessage()];
        }
    }

    private function _recomputeStudent(string $studentId): array
    {
        try {
            $rows = $this->firebase->firestoreQuery(self::COL_DEMANDS, [
                ['schoolId',  '==', $this->schoolId],
                ['session',   '==', $this->session],
                ['studentId', '==', $studentId],
            ]);
            $dem = 0.0; $col = 0.0;
            $months = [];
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                $net  = (float) ($d['netAmount']  ?? $d['net_amount']  ?? 0);
                $paid = (float) ($d['paidAmount'] ?? $d['paid_amount'] ?? 0);
                $dem += $net; $col += $paid;
                $m = $this->_extractMonth($d);
                if ($m !== '') {
                    if (!isset($months[$m])) $months[$m] = ['d' => 0, 'p' => 0];
                    $months[$m]['d'] += $net; $months[$m]['p'] += $paid;
                }
            }
            $paidM = $partialM = $unpaidM = [];
            foreach ($months as $m => $v) {
                $bal = $v['d'] - $v['p'];
                if ($bal <= 0.005)        $paidM[]    = $m;
                elseif ($v['p'] > 0.005)  $partialM[] = $m;
                else                       $unpaidM[] = $m;
            }
            $status = 'unpaid';
            if (($dem - $col) <= 0.005 && $dem > 0) $status = 'paid';
            elseif ($col > 0.005)                    $status = 'partial';
            return [
                '_source'        => 'fallback',
                'schoolId'       => $this->schoolId,
                'session'        => $this->session,
                'studentId'      => $studentId,
                'totalDemanded'  => round($dem, 2),
                'totalCollected' => round($col, 2),
                'totalBalance'   => round($dem - $col, 2),
                'paidMonths'     => array_values($paidM),
                'partialMonths'  => array_values($partialM),
                'unpaidMonths'   => array_values($unpaidM),
                'status'         => $status,
                'updatedAt'      => date('c'),
            ];
        } catch (\Throwable $e) {
            log_message('error', 'Fee_summary_reader::_recomputeStudent failed: ' . $e->getMessage());
            return ['_source' => 'fallback', '_error' => $e->getMessage()];
        }
    }

    private function _isStale(array $doc): bool
    {
        $u = (string) ($doc['updatedAt'] ?? '');
        if ($u === '') return true;
        $ts = strtotime($u);
        if (!$ts) return true;
        return (time() - $ts) > self::STALE_AFTER_SEC;
    }

    private function _extractMonth(array $d): string
    {
        $m = (string) ($d['month'] ?? '');
        if ($m !== '') return $m;
        $p = (string) ($d['period'] ?? '');
        return $p === '' ? '' : (string) preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', $p);
    }

    private function _classSummaryDocId(string $className, string $section, string $month): string
    {
        $c = preg_replace('/\s+/', '_', trim($className));
        $s = preg_replace('/\s+/', '_', trim($section));
        $m = preg_replace('/\s+/', '_', trim($month));
        return "{$this->schoolId}_{$this->session}_{$c}_{$s}_{$m}";
    }
}
