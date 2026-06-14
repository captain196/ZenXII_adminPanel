<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Staff_attendance_writer — Firestore-only writer for Stream B.
 *
 * Phase II Step II.3 deliverable. Pure-PHP scaffolding; no caller yet.
 *
 * Scope (Phase II Step II.3)
 * --------------------------
 *   IMPLEMENTED:   markSingleDay() — W5 path with CAS-protected summary
 *   STUBBED:       bulkMarkDay(), bulkAutofillDay() — throw on call
 *                  (Phase V deliverable; requires commitBatchesParallel)
 *
 * Architectural lock
 * ------------------
 *   - Firestore = sole authority. The writer uses ONLY $this->firebase->firestore*
 *     methods. ZERO RTDB calls anywhere in this class.
 *   - No fallback. On any Firestore failure within the CAS retry budget, the
 *     writer THROWS — caller surfaces error to UI. There is no try/catch path
 *     that consults RTDB.
 *   - CAS precondition applies to EXACTLY ONE write per call: the
 *     staffAttendanceSummary update. The staffAttendance per-day write uses
 *     composite-ID overwrite semantics (last-write-wins is correct for the
 *     canonical per-day record).
 *   - Mutually exclusive flag dispatch is handled by the CALLER (controller
 *     entry point). The writer does not check the flag itself.
 *
 * Document model
 * --------------
 *   staffAttendance/{schoolId}_{dateISO}_{staffId}
 *     - Per-day canonical record. Composite ID; last-write-wins.
 *     - Fields: schoolId, session, date, staffId, status, markedBy, markedAt,
 *               source, lateMinutes, previousStatus, plus optional context.
 *
 *   staffAttendanceSummary/{schoolId}_{staffId}_{monthKey}
 *     - Per-month aggregate. CAS-protected via precondition.updateTime.
 *     - Fields: schoolId, staffId, month, year, monthNumber, dayWise (string),
 *               totalDays, present, absent, leave, holiday, tardy, void,
 *               workingDays, _updatedAt.
 */

/* Exception types used by this writer.
   Defined inline to keep Step II.3 to a single file. */
if (!class_exists('MonthLockedException', false)) {
    class MonthLockedException extends \RuntimeException {}
}
if (!class_exists('CASWriteException', false)) {
    class CASWriteException extends \RuntimeException {}
}
if (!class_exists('CASRetryExhaustedException', false)) {
    class CASRetryExhaustedException extends \RuntimeException {}
}

class Staff_attendance_writer
{
    /** CAS retry budget (initial attempt + this many retries on conflict). */
    const CAS_MAX_RETRIES = 3;
    /** Base exponential backoff in milliseconds (×2^attempt + jitter). */
    const CAS_BACKOFF_BASE_MS = 50;

    /** Allowed dayWise status chars. Pinned by verifier A2. */
    const ALLOWED_STATUS = ['P', 'A', 'L', 'H', 'T', 'V'];

    /** Map from status char to count field on the summary doc. */
    private const STATUS_TO_FIELD = [
        'P' => 'present',
        'A' => 'absent',
        'L' => 'leave',
        'H' => 'holiday',
        'T' => 'tardy',
        'V' => 'void',
    ];

    /** @var \CI_Controller */
    private $ci;
    /** @var \Firebase */
    private $firebase;
    /** @var \Lock_cache */
    private $lock_cache;

    private string $schoolId    = '';
    private string $sessionYear = '';
    private bool   $ready       = false;

    public function __construct()
    {
        $this->ci =& get_instance();
    }

    public function init($firebase, string $schoolId, string $sessionYear): void
    {
        if ($schoolId === '' || $sessionYear === '') {
            throw new \InvalidArgumentException('schoolId and sessionYear are required');
        }
        $this->firebase    = $firebase;
        $this->schoolId    = $schoolId;
        $this->sessionYear = $sessionYear;
        $this->ci->load->library('lock_cache');
        $this->lock_cache  = $this->ci->lock_cache;
        $this->ready       = true;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * Mark a single staff for a single day. Phase II W5 path.
     *
     * Operations (cache hit, no CAS retry):
     *   - 0 ops: lock check (cache)
     *   - 1 op:  firestoreGet on staffAttendanceSummary (captures __updateTime)
     *   - 1 op:  firestoreCommitBatch with staffAttendance set + summary set
     *
     * Composite IDs:
     *   - staffAttendance:        {schoolId}_{dateISO}_{staffId}
     *   - staffAttendanceSummary: {schoolId}_{staffId}_{monthKey}
     *
     * @throws MonthLockedException        if lock cache says month is locked
     * @throws CASRetryExhaustedException  after max CAS retries fail
     * @throws CASWriteException           on permanent Firestore failure (Phase III/V can refine; current REST client doesn't expose error codes — all commit failures treated as retryable)
     * @throws \InvalidArgumentException   on bad input
     *
     * @return array ['ok'=>bool, 'attempts'=>int, 'fs_reads'=>int, 'fs_writes'=>int,
     *               'duration_ms'=>int, 'cache_source'=>string, 'cache_age_ms'=>int,
     *               'previous_status'=>string, 'new_status'=>string]
     */
    public function markSingleDay(string $staffId, string $dateISO, string $status, array $context = []): array
    {
        if (!$this->ready) {
            throw new \LogicException('Staff_attendance_writer not initialised; call init() first');
        }
        $this->_validate_inputs($staffId, $dateISO, $status);
        $t0       = microtime(true);
        $monthKey = $this->_month_key_from_date($dateISO);
        $year     = (int) substr($dateISO, 0, 4);
        $monthNum = (int) substr($dateISO, 5, 2);
        $day      = (int) substr($dateISO, 8, 2);
        $daysIn   = (int) cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

        // 1. Lock check (cached). Throws MonthLockedException if locked.
        $lock = $this->lock_cache->is_locked($this->schoolId, $this->sessionYear, $monthKey);
        if (!empty($lock['is_locked'])) {
            throw new MonthLockedException("month {$monthKey} is locked for {$this->schoolId}");
        }

        $summaryDocId = $this->_summary_doc_id($staffId, $monthKey);
        $attDocId     = $this->_att_doc_id($dateISO, $staffId);

        $fsReads  = 0;
        $fsWrites = 0;
        $previousStatus = 'V';

        // 2. CAS retry loop
        for ($attempt = 0; $attempt <= self::CAS_MAX_RETRIES; $attempt++) {
            // 2a. Read summary with __updateTime
            $sum = $this->firebase->firestoreGet('staffAttendanceSummary', $summaryDocId);
            $fsReads++;
            if (is_array($sum)) {
                $captured = (string) ($sum['__updateTime'] ?? '');
                $dayWise  = (string) ($sum['dayWise'] ?? str_repeat('V', $daysIn));
                if (strlen($dayWise) < $daysIn) $dayWise = str_pad($dayWise, $daysIn, 'V');
                $precondition = ($captured !== '') ? ['updateTime' => $captured] : ['exists' => false];
            } else {
                $captured = '';
                $dayWise  = str_repeat('V', $daysIn);
                $precondition = ['exists' => false];
            }

            // 2b. Compute deltas
            $idx = $day - 1;
            $previousStatus = strtoupper($dayWise[$idx] ?? 'V');
            $newDayWise = substr($dayWise, 0, $idx) . $status . substr($dayWise, $idx + 1);
            $deltaCounts = $this->_compute_delta($previousStatus, $status);

            // 2c. Build summary payload (only count fields touched + dayWise + bookkeeping)
            $summaryPayload = [
                'schoolId'    => $this->schoolId,
                'session'     => $this->sessionYear,
                'staffId'     => $staffId,
                'month'       => $monthKey,
                'year'        => $year,
                'monthNumber' => $monthNum,
                'dayWise'     => $newDayWise,
                'totalDays'   => $daysIn,
                '_updatedAt'  => date('c'),
            ];
            // Carry forward existing counts; apply delta
            $counts = $this->_counts_with_delta($sum, $daysIn, $deltaCounts);
            $summaryPayload = array_merge($summaryPayload, $counts);
            // workingDays = present + leave + tardy
            $summaryPayload['workingDays'] = (int) ($counts['present'] + $counts['leave'] + $counts['tardy']);

            // 2d. Build staffAttendance payload
            $attPayload = [
                'schoolId'       => $this->schoolId,
                'session'        => $this->sessionYear,
                'date'           => $dateISO,
                'staffId'        => $staffId,
                'status'         => $status,
                'markedBy'       => (string) ($context['markedBy'] ?? 'unknown'),
                'markedAt'       => date('c'),
                'source'         => (string) ($context['source']   ?? 'manual'),
                'lateMinutes'    => (int) ($context['lateMinutes'] ?? 0),
                'previousStatus' => $previousStatus,
                '_updatedAt'     => date('c'),
            ];
            // Optional context fields
            foreach (['leaveType', 'leaveAppId', 'punchEventId', 'capturedById', 'correctionId'] as $k) {
                if (!empty($context[$k])) $attPayload[$k] = (string) $context[$k];
            }

            // 2e. Atomic batch: staffAttendance set (no precondition) + summary set (CAS precondition)
            $ops = [
                ['op' => 'set', 'collection' => 'staffAttendance',        'docId' => $attDocId,     'data' => $attPayload],
                ['op' => 'set', 'collection' => 'staffAttendanceSummary', 'docId' => $summaryDocId, 'data' => $summaryPayload, 'merge' => true, 'precondition' => $precondition],
            ];
            $ok = $this->firebase->firestoreCommitBatch($ops);
            $fsWrites += 2;
            if ($ok === true) {
                return [
                    'ok'              => true,
                    'attempts'        => $attempt + 1,
                    'fs_reads'        => $fsReads,
                    'fs_writes'       => $fsWrites,
                    'duration_ms'     => (int) ((microtime(true) - $t0) * 1000),
                    'cache_source'    => $lock['source'] ?? 'unknown',
                    'cache_age_ms'    => $lock['age_ms'] ?? 0,
                    'previous_status' => $previousStatus,
                    'new_status'      => $status,
                ];
            }

            // Treat false return as conflict-or-transient — retry within budget.
            // The current Firestore_rest_client::commitBatch returns bool only,
            // logging error context. Phase V/VIII may extend it to expose codes
            // so we can distinguish FAILED_PRECONDITION from permanent 4xx.
            $this->_backoff($attempt);
        }

        throw new CASRetryExhaustedException(
            "CAS retry exhausted for staffAttendanceSummary/{$summaryDocId} after "
            . (self::CAS_MAX_RETRIES + 1) . ' attempts'
        );
    }

    /**
     * Bulk-mark M staff for the same day, each with their own target status.
     *
     * Phase III Step III.1 — SEQUENTIAL implementation. Phase V will replace
     * the per-staff sequential commit with commitBatchesParallel for the
     * wall-clock collapse from O(M) to O(⌈2M/400⌉ chunks).
     *
     * Operations per call (M staff, cache hit, no CAS retry):
     *   - 0 ops: lock check (cache)
     *   - 1 op:  firestoreQuery F-SB-4 to read all summaries + __updateTime
     *   - M ops: per-staff commitBatch (att set + summary set with CAS)
     *
     * Returns aggregate result; failed staff are reported via failed_ids,
     * NOT a thrown exception (callers can retry just the failed subset).
     *
     * @throws MonthLockedException       if lock cache says month is locked
     * @throws \InvalidArgumentException  on bad input
     * @return array ['ok'=>bool, 'committed'=>int, 'failed_ids'=>string[],
     *               'attempts_total'=>int, 'fs_reads'=>int, 'fs_writes'=>int,
     *               'duration_ms'=>int, 'cache_source'=>string]
     */
    public function bulkMarkDay(array $statusByStaffId, string $dateISO, array $context = []): array
    {
        if (!$this->ready) {
            throw new \LogicException('Staff_attendance_writer not initialised; call init() first');
        }
        if (empty($statusByStaffId)) {
            return ['ok' => true, 'committed' => 0, 'failed_ids' => [],
                    'attempts_total' => 0, 'fs_reads' => 0, 'fs_writes' => 0,
                    'duration_ms' => 0, 'cache_source' => 'unused'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateISO)) {
            throw new \InvalidArgumentException("dateISO must be YYYY-MM-DD, got '{$dateISO}'");
        }
        foreach ($statusByStaffId as $sid => $status) {
            if ($sid === '' || !is_string($sid)) {
                throw new \InvalidArgumentException('staffId keys must be non-empty strings');
            }
            if (!in_array($status, self::ALLOWED_STATUS, true)) {
                throw new \InvalidArgumentException("invalid status '{$status}' for staff '{$sid}'");
            }
        }

        $t0       = microtime(true);
        $monthKey = $this->_month_key_from_date($dateISO);
        $year     = (int) substr($dateISO, 0, 4);
        $monthNum = (int) substr($dateISO, 5, 2);
        $day      = (int) substr($dateISO, 8, 2);
        $daysIn   = (int) cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

        // 1. Lock check (cached)
        $lock = $this->lock_cache->is_locked($this->schoolId, $this->sessionYear, $monthKey);
        if (!empty($lock['is_locked'])) {
            throw new MonthLockedException("month {$monthKey} is locked for {$this->schoolId}");
        }

        // 2. Batch-read all summaries for the month via F-SB-4
        $summaries = []; // staffId => ['data' => ..., '__updateTime' => ...]
        $fsReads   = 1;
        try {
            $rows = $this->firebase->firestoreQuery('staffAttendanceSummary', [
                ['schoolId', '==', $this->schoolId],
                ['month',    '==', $monthKey],
            ]);
            foreach ($rows as $row) {
                $data = is_array($row['data'] ?? null) ? $row['data'] : [];
                $sid  = (string) ($data['staffId'] ?? '');
                if ($sid === '') continue;
                $summaries[$sid] = [
                    'data'         => $data,
                    '__updateTime' => (string) ($data['__updateTime'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            // Query failed — propagate to caller (fail-loud per architecture).
            throw $e;
        }

        // 3. Per-staff sequential commit with CAS retry budget
        $committed      = 0;
        $failedIds      = [];
        $attemptsTotal  = 0;
        $fsWrites       = 0;

        foreach ($statusByStaffId as $staffId => $status) {
            $summaryDocId = $this->_summary_doc_id($staffId, $monthKey);
            $attDocId     = $this->_att_doc_id($dateISO, $staffId);

            $success = false;
            for ($attempt = 0; $attempt <= self::CAS_MAX_RETRIES; $attempt++) {
                $attemptsTotal++;

                // Use pre-fetched summary on first attempt; re-read on retries.
                if ($attempt === 0 && isset($summaries[$staffId])) {
                    $sum      = $summaries[$staffId]['data'];
                    $captured = $summaries[$staffId]['__updateTime'];
                } else {
                    $sum = $this->firebase->firestoreGet('staffAttendanceSummary', $summaryDocId);
                    $fsReads++;
                    $captured = is_array($sum) ? (string) ($sum['__updateTime'] ?? '') : '';
                }

                // Compute new state
                $dayWise = is_array($sum)
                    ? (string) ($sum['dayWise'] ?? str_repeat('V', $daysIn))
                    : str_repeat('V', $daysIn);
                if (strlen($dayWise) < $daysIn) $dayWise = str_pad($dayWise, $daysIn, 'V');

                $idx            = $day - 1;
                $previousStatus = strtoupper($dayWise[$idx] ?? 'V');
                $newDayWise     = substr($dayWise, 0, $idx) . $status . substr($dayWise, $idx + 1);
                $deltaCounts    = $this->_compute_delta($previousStatus, $status);
                $counts         = $this->_counts_with_delta($sum, $daysIn, $deltaCounts);

                $precondition = ($captured !== '')
                    ? ['updateTime' => $captured]
                    : ['exists' => false];

                $summaryPayload = array_merge([
                    'schoolId'    => $this->schoolId,
                    'session'     => $this->sessionYear,
                    'staffId'     => $staffId,
                    'month'       => $monthKey,
                    'year'        => $year,
                    'monthNumber' => $monthNum,
                    'dayWise'     => $newDayWise,
                    'totalDays'   => $daysIn,
                    '_updatedAt'  => date('c'),
                ], $counts);
                $summaryPayload['workingDays'] =
                    (int) ($counts['present'] + $counts['leave'] + $counts['tardy']);

                $attPayload = [
                    'schoolId'       => $this->schoolId,
                    'session'        => $this->sessionYear,
                    'date'           => $dateISO,
                    'staffId'        => $staffId,
                    'status'         => $status,
                    'markedBy'       => (string) ($context['markedBy'] ?? 'unknown'),
                    'markedAt'       => date('c'),
                    'source'         => (string) ($context['source'] ?? 'bulk_mark'),
                    'lateMinutes'    => 0,
                    'previousStatus' => $previousStatus,
                    '_updatedAt'     => date('c'),
                ];

                $ops = [
                    ['op' => 'set', 'collection' => 'staffAttendance',        'docId' => $attDocId,     'data' => $attPayload],
                    ['op' => 'set', 'collection' => 'staffAttendanceSummary', 'docId' => $summaryDocId, 'data' => $summaryPayload, 'merge' => true, 'precondition' => $precondition],
                ];
                $ok = $this->firebase->firestoreCommitBatch($ops);
                if ($ok === true) {
                    $fsWrites += 2;
                    $success   = true;
                    break;
                }
                // CAS conflict OR transient — backoff and retry.
                $this->_backoff($attempt);
            }
            if ($success) {
                $committed++;
            } else {
                $failedIds[] = $staffId;
            }
        }

        return [
            'ok'             => empty($failedIds),
            'committed'      => $committed,
            'failed_ids'     => $failedIds,
            'attempts_total' => $attemptsTotal,
            'fs_reads'       => $fsReads,
            'fs_writes'      => $fsWrites,
            'duration_ms'    => (int) ((microtime(true) - $t0) * 1000),
            'cache_source'   => $lock['source'] ?? 'unknown',
        ];
    }

    /**
     * Autofill: broadcast same status to M staff on a date.
     * Thin wrapper over bulkMarkDay.
     */
    public function bulkAutofillDay(array $staffIds, string $dateISO, string $status, array $context = []): array
    {
        if (!in_array($status, self::ALLOWED_STATUS, true)) {
            throw new \InvalidArgumentException("invalid status '{$status}'");
        }
        $statusByStaffId = [];
        foreach ($staffIds as $sid) $statusByStaffId[(string) $sid] = $status;
        $ctx = array_merge(['source' => 'bulk_autofill'], $context);
        return $this->bulkMarkDay($statusByStaffId, $dateISO, $ctx);
    }

    /* ─── private helpers ──────────────────────────────────────────────── */

    private function _validate_inputs(string $staffId, string $dateISO, string $status): void
    {
        if ($staffId === '') throw new \InvalidArgumentException('staffId required');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateISO)) {
            throw new \InvalidArgumentException("dateISO must be YYYY-MM-DD, got '{$dateISO}'");
        }
        if (!in_array($status, self::ALLOWED_STATUS, true)) {
            throw new \InvalidArgumentException("status must be one of " . implode(',', self::ALLOWED_STATUS) . ", got '{$status}'");
        }
    }

    private function _summary_doc_id(string $staffId, string $monthKey): string
    {
        return "{$this->schoolId}_{$staffId}_{$monthKey}";
    }

    private function _att_doc_id(string $dateISO, string $staffId): string
    {
        return "{$this->schoolId}_{$dateISO}_{$staffId}";
    }

    private function _month_key_from_date(string $dateISO): string
    {
        return substr($dateISO, 0, 7);
    }

    /**
     * Compute count deltas for previous→new status transition.
     * Returns map like ['void' => -1, 'present' => 1].
     * Empty map if no transition needed (status unchanged).
     */
    private function _compute_delta(string $previousStatus, string $newStatus): array
    {
        if ($previousStatus === $newStatus) return [];
        $prevField = self::STATUS_TO_FIELD[$previousStatus] ?? 'void';
        $newField  = self::STATUS_TO_FIELD[$newStatus]      ?? 'void';
        if ($prevField === $newField) return [];
        return [$prevField => -1, $newField => 1];
    }

    /**
     * Carry existing counts forward + apply delta.
     * Returns the count fields ONLY (does not include dayWise, _updatedAt).
     */
    private function _counts_with_delta($existingSum, int $daysIn, array $delta): array
    {
        $fields = ['present', 'absent', 'leave', 'holiday', 'tardy', 'void'];
        $out = [];
        foreach ($fields as $f) {
            $cur = is_array($existingSum) ? (int) ($existingSum[$f] ?? 0) : 0;
            // If no existing summary, initialise void=daysIn and others to 0
            if (!is_array($existingSum) && $f === 'void') $cur = $daysIn;
            $out[$f] = $cur + (int) ($delta[$f] ?? 0);
            if ($out[$f] < 0) $out[$f] = 0;  // clamp
        }
        return $out;
    }

    /**
     * Exponential backoff with jitter. Sleeps via usleep.
     * attempt 0: ~50ms, 1: ~100ms, 2: ~200ms, 3: ~400ms.
     */
    private function _backoff(int $attempt): void
    {
        $base = self::CAS_BACKOFF_BASE_MS * (2 ** $attempt);
        $jitter = mt_rand(0, self::CAS_BACKOFF_BASE_MS);
        usleep(((int) $base + (int) $jitter) * 1000);
    }
}
