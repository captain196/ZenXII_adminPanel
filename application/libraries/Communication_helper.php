<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Communication_helper — Firestore-only shared library for firing automated
 * events. Other modules (Attendance, Fees, Examination, Result, Events,
 * Ptm, Lms, Fee_management) call fire_event() to queue notifications based
 * on configured triggers and templates.
 *
 * V7 hardening (2026-06-14):
 *   • Zero RTDB. No legacy paths, no fallback, no mirror.
 *   • CAS counter via Firestore commitBatch + updateTime precondition.
 *   • Cross-domain reads (students / feeReceipts / feeDemands) hit the
 *     Firestore canonical collections only.
 *   • Caller signature preserved (see init()).
 *
 * Usage:
 *   $this->load->library('communication_helper');
 *   $this->communication_helper->init(
 *       $this->firebase, $this->school_name, $this->session_year,
 *       $this->parent_db_key, $this->fs, $this->school_id);
 *   $this->communication_helper->fire_event('student_absent', [...]);
 */
class Communication_helper
{
    private $firebase;
    /** @var Firestore_service|null */
    private $fs;
    private $school_id;
    private $school_name;
    private $session_year;
    private $parent_db_key;

    // Firestore collection names — must match Communication.php constants.
    const FS_COL_TEMPLATES = 'messageTemplates';
    const FS_COL_TRIGGERS  = 'alertTriggers';
    const FS_COL_QUEUE     = 'messageQueue';
    const FS_COL_NOTICES   = 'notices';
    const FS_COL_STUDENTS  = 'students';
    const FS_COL_RECEIPT_INDEX = 'feeReceiptIndex';
    const FS_COL_RECEIPTS  = 'feeReceipts';
    const FS_COL_DEMANDS   = 'feeDemands';

    const ALLOWED_EVENTS = [
        'student_absent', 'student_late', 'low_attendance',
        'fee_due', 'fee_overdue', 'fee_received',
        'exam_result', 'exam_schedule',
        'admission_approved', 'admission_rejected',
        'salary_processed', 'leave_approved',
        'event_created', 'event_updated',
        'assignment_created', 'assignment_graded', 'quiz_result',
    ];

    const MAX_QUEUE_PER_EVENT = 200;

    // CAS counter retry bound — exceeds this and we fail loud.
    const CAS_MAX_RETRIES = 8;

    // Counter type → (collection, idPrefix) for self-seeding scan.
    private const COUNTER_SEED_SOURCES = [
        'Queue'  => [self::FS_COL_QUEUE,   'QUE'],
        'Notice' => [self::FS_COL_NOTICES, 'NOT'],
    ];

    /**
     * Initialize with controller context.
     *
     * Signature is backwards-compatible with every caller. $fs and
     * $school_id are required for Firestore-only operation; callers
     * that omit them will hit fail-loud throws from the runtime methods.
     */
    public function init($firebase, string $school_name, string $session_year, string $parent_db_key = '', $fs = null, string $school_id = ''): void
    {
        $this->firebase       = $firebase;
        $this->school_name    = $school_name;
        $this->session_year   = $session_year;
        $this->parent_db_key  = $parent_db_key !== '' ? $parent_db_key : $school_name;
        $this->fs             = $fs;
        $this->school_id      = $school_id !== '' ? $school_id : $school_name;
    }

    /**
     * List admin-internal entities from Firestore (school-scoped).
     * Returns map of entityId → data (matches the shape Communication
     * controller's _dwListAdmin returns).
     */
    private function _list(string $fsCollection): array
    {
        if ($this->fs === null) {
            throw new \RuntimeException("Communication_helper::_list requires Firestore_service ({$fsCollection}).");
        }
        $rows = $this->fs->schoolWhere($fsCollection, []);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $row) {
            $d    = $row['data'] ?? $row;
            $data = $row['data'] ?? [];
            $id   = $data['id'] ?? ($d['id'] ?? '');
            if ($id === '') continue;
            if (strpos($id, $this->school_id . '_') === 0) {
                $id = substr($id, strlen($this->school_id) + 1);
            }
            $out[$id] = $data;
        }
        return $out;
    }

    /** Single-doc Firestore fetch (entity-scoped via schoolId prefix). */
    private function _get(string $fsCollection, string $id): ?array
    {
        if ($this->fs === null) {
            throw new \RuntimeException("Communication_helper::_get requires Firestore_service ({$fsCollection}/{$id}).");
        }
        $doc = $this->fs->get($fsCollection, "{$this->school_id}_{$id}");
        return (is_array($doc) && !empty($doc)) ? $doc : null;
    }

    /** Firestore-only write for queue items. */
    private function _setQueue(string $id, array $data): void
    {
        if ($this->fs === null) {
            throw new \RuntimeException("Communication_helper::_setQueue requires Firestore_service ({$id}).");
        }
        $this->fs->set(self::FS_COL_QUEUE, "{$this->school_id}_{$id}", array_merge(
            ['schoolId' => $this->school_id, 'id' => $id],
            $data
        ), false);
    }

    /**
     * Mint the next monotonic counter value for a Communication-domain
     * counter, with verify-after-write CAS semantics.
     *
     * Self-seeds the first call by scanning the canonical collection for
     * the highest existing prefix-NNNN doc, so a missing field cannot
     * collide with historical IDs. Subsequent calls increment normally.
     *
     * Concurrency: writes go through fs->update which correctly nests the
     * dotted field path (commCounters.{type}) into the commCounters map
     * without disturbing sibling counters. After each write we re-read
     * and verify the landed value equals $next; mismatch means a racing
     * writer reached the same $next first, so we retry from a fresh read.
     *
     * Fails loud (RuntimeException) on CAS_MAX_RETRIES exhaustion — V7
     * contract: never silently lose a counter increment.
     *
     * @return string  Formatted ID, e.g. "QUE00001" / "NOT0001".
     */
    private function _nextCommCounter(string $type, int $width = 5): string
    {
        if ($this->fs === null) {
            throw new \RuntimeException("Communication_helper::_nextCommCounter requires Firestore_service.");
        }

        $profileDocId = $this->school_id . '_profile';
        $field        = "commCounters.{$type}";
        $prefix       = self::COUNTER_SEED_SOURCES[$type][1] ?? $type;

        $lastErr = null;
        for ($attempt = 0; $attempt < self::CAS_MAX_RETRIES; $attempt++) {
            $profile = $this->fs->get('schools', $profileDocId);
            $current = $this->_readCommCounterValue($profile, $type, $field);
            if ($current < 0) {
                $current = $this->_seedCommCounter($type);
            }
            $next = $current + 1;

            $ok = false;
            try {
                $ok = (bool) $this->fs->update('schools', $profileDocId, [$field => $next]);
            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();
            }

            if ($ok) {
                // Verify-after-write: re-read and confirm the landed value
                // equals $next. If a racing writer pushed it past $next,
                // their ID is theirs and we re-mint from the fresh state.
                $verify  = $this->fs->get('schools', $profileDocId);
                $landed  = $this->_readCommCounterValue($verify, $type, $field);
                if ($landed === $next) {
                    return $prefix . str_pad((string) $next, $width, '0', STR_PAD_LEFT);
                }
            }

            // Backoff up to ~640ms then retry on write failure or CAS miss.
            usleep(10000 * (1 << min($attempt, 5)));
        }

        throw new \RuntimeException(
            "Communication_helper: CAS counter '{$type}' exhausted after "
            . self::CAS_MAX_RETRIES . " retries"
            . ($lastErr ? " (last error: {$lastErr})" : '')
        );
    }

    /**
     * Read the current value of a commCounters.{type} field from a profile
     * doc, supporting both the nested commCounters.{type} map (canonical,
     * written by fs->update) and the legacy flat-keyed form (for any docs
     * still carrying historical Communication::_next_id writes).
     */
    private function _readCommCounterValue(?array $profile, string $type, string $flatKey): int
    {
        if (!is_array($profile)) return -1;
        if (isset($profile['commCounters']) && is_array($profile['commCounters'])
            && isset($profile['commCounters'][$type])
            && is_numeric($profile['commCounters'][$type])) {
            return (int) $profile['commCounters'][$type];
        }
        if (isset($profile[$flatKey]) && is_numeric($profile[$flatKey])) {
            return (int) $profile[$flatKey];
        }
        return -1;
    }

    /**
     * Self-heal seed: scan the canonical collection for the highest
     * existing prefix-NNNN doc and return that integer. Used by
     * _nextCommCounter on first call when commCounters.{type} is unset.
     */
    private function _seedCommCounter(string $type): int
    {
        $src = self::COUNTER_SEED_SOURCES[$type] ?? null;
        if ($src === null || $this->fs === null) return 0;
        [$collection, $idPrefix] = $src;
        $max = 0;
        try {
            $rows = $this->fs->schoolWhere($collection, []);
            if (is_array($rows)) {
                $schoolPrefix = $this->school_id . '_';
                foreach ($rows as $row) {
                    $d   = $row['data'] ?? $row;
                    $raw = (string) ($d['id'] ?? '');
                    $trimmed = (strpos($raw, $schoolPrefix) === 0)
                        ? substr($raw, strlen($schoolPrefix))
                        : $raw;
                    if (preg_match('/^' . preg_quote($idPrefix, '/') . '(\d+)$/', $trimmed, $m)) {
                        $n = (int) $m[1];
                        if ($n > $max) $max = $n;
                    }
                }
            }
        } catch (\Throwable $e) {
            log_message('error', "Communication_helper::_seedCommCounter [{$type}]: " . $e->getMessage());
        }
        return $max;
    }

    // ====================================================================
    //  FIRE EVENT — called by external modules
    // ====================================================================

    /**
     * Fire an event to trigger queued notifications.
     *
     * @param string $eventType  e.g. 'student_absent', 'fee_due', 'exam_result'
     * @param array  $data       Context data with template variable values
     * @return int               Number of messages queued
     */
    public function fire_event(string $eventType, array $data): int
    {
        if (!in_array($eventType, self::ALLOWED_EVENTS, true)) {
            log_message('error', "Communication_helper: invalid event type '{$eventType}'");
            return 0;
        }
        if (empty($this->school_name) || empty($this->firebase)) {
            log_message('error', 'Communication_helper: not initialized. Call init() first.');
            return 0;
        }

        $data     = $this->_sanitize_data($data);
        $triggers = $this->_list(self::FS_COL_TRIGGERS);
        if (empty($triggers)) return 0;

        $queued = 0;

        foreach ($triggers as $trgId => $trg) {
            if (!is_array($trg)) continue;
            if ($trgId === 'Counter') continue;
            if (($trg['event_type'] ?? '') !== $eventType) continue;
            if (empty($trg['enabled'])) continue;
            if ($queued >= self::MAX_QUEUE_PER_EVENT) break;

            $conditions = $trg['conditions'] ?? [];
            if (!is_array($conditions)) $conditions = [];
            if (!$this->_check_conditions($conditions, $data)) continue;

            $tplId = $trg['template_id'] ?? '';
            if ($tplId === '' || !preg_match('/^TPL\d+$/', $tplId)) continue;
            $tpl = $this->_get(self::FS_COL_TEMPLATES, $tplId);
            if (!is_array($tpl)) continue;

            $title = $this->_replace_vars($tpl['subject'] ?? ($tpl['name'] ?? ''), $data);
            $body  = $this->_replace_vars($tpl['body'] ?? '', $data);

            $recipientType = $trg['recipient_type'] ?? 'parent';
            if (!in_array($recipientType, ['parent', 'student', 'teacher', 'staff', 'broadcast'], true)) continue;
            $recipient = $this->_resolve_recipient($recipientType, $data);
            if (empty($recipient)) continue;

            $queueId = $this->_nextCommCounter('Queue', 5);

            $channel = $trg['channel'] ?? 'push';
            if (!in_array($channel, ['push', 'sms', 'email', 'in_app'], true)) $channel = 'push';

            $this->_setQueue($queueId, [
                'trigger_id'        => $trgId,
                'template_id'       => $tplId,
                'channel'           => $channel,
                'recipient_type'    => $recipientType,
                'recipient_id'      => $recipient['id'] ?? '',
                'recipient_name'    => $recipient['name'] ?? '',
                'recipient_contact' => $recipient['contact'] ?? '',
                'title'             => $title,
                'message_body'      => $body,
                'status'            => 'pending',
                'priority'          => $trg['priority'] ?? 'normal',
                'attempts'          => 0,
                'max_attempts'      => 3,
                'error_message'     => '',
                'created_at'        => date('c'),
                'scheduled_at'      => date('c'),
                'sent_at'           => '',
                'source'            => 'trigger',
                'event_type'        => $eventType,
            ]);

            $queued++;
        }

        return $queued;
    }

    // ====================================================================
    //  FIRE EVENT FOR BULK — multiple recipients
    // ====================================================================

    /**
     * Fire event for a list of recipients (e.g. exam results for a class).
     */
    public function fire_event_bulk(string $eventType, array $recipientDataList): int
    {
        $total = 0;
        $cap   = self::MAX_QUEUE_PER_EVENT;
        foreach ($recipientDataList as $data) {
            if ($total >= $cap) break;
            $total += $this->fire_event($eventType, $data);
        }
        return $total;
    }

    // ====================================================================
    //  EVENT NOTICE — writes the canonical Firestore `notices` doc
    // ====================================================================

    /**
     * Write an announcement notice for a school event.
     *
     * @param string $eventId  e.g. 'EVT0001'
     * @param array  $data     Event data (title, category, start_date, etc.)
     * @param string $adminId  Admin who created the event
     * @return string          The notice ID created
     */
    public function write_event_notice(string $eventId, array $data, string $adminId): string
    {
        if (empty($this->school_name) || empty($this->firebase)) {
            log_message('error', 'Communication_helper: not initialized for write_event_notice');
            return '';
        }
        if ($this->fs === null) {
            throw new \RuntimeException("Communication_helper::write_event_notice requires Firestore_service.");
        }

        $categoryLabels = [
            'event'       => 'School Event',
            'academic'    => 'Academic',
            'cultural'    => 'Cultural Program',
            'sports'      => 'Sports Competition',
            'celebration' => 'Celebration',
            'meeting'     => 'Meeting',
            'workshop'    => 'Workshop / Seminar',
            'excursion'   => 'Excursion / Field Trip',
            'competition' => 'Competition',
            'holiday'     => 'Holiday',
            'exam'        => 'Exam',
            'function'    => 'School Function',
            'other'       => 'Event',
        ];
        $catKey   = $data['category'] ?? 'event';
        $catLabel = $categoryLabels[$catKey] ?? 'Event';

        $startDate = $data['startDate'] ?? $data['start_date'] ?? '';
        $endDate   = $data['endDate']   ?? $data['end_date']   ?? '';

        $title = "[{$catLabel}] " . ($data['title'] ?? '');
        $descParts = [];
        if (!empty($data['description'])) $descParts[] = $data['description'];
        if (!empty($startDate)) {
            $descParts[] = "Date: " . $startDate
                . (!empty($endDate) && $endDate !== $startDate ? " to " . $endDate : '');
        }
        if (!empty($data['location']))    $descParts[] = "Venue: " . $data['location'];
        if (!empty($data['organizer']))   $descParts[] = "Organizer: " . $data['organizer'];
        $description = implode("\n", $descParts);

        // Canonical Firestore notices write. Counter is CAS-minted to NOT0001
        // shape, doc id school-scoped via fs->docId().
        $noticeId = $this->_nextCommCounter('Notice', 4);
        $nowIso   = date('c');

        $this->fs->set(self::FS_COL_NOTICES, $this->fs->docId($noticeId), [
            'noticeId'      => $noticeId,
            'schoolId'      => $this->school_id ?: $this->school_name,
            'title'         => $title,
            'description'   => $description,
            'category'      => 'Event',
            'eventCategory' => $catKey,
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'location'      => (string) ($data['location']  ?? ''),
            'organizer'     => (string) ($data['organizer'] ?? ''),
            'priority'      => 'Normal',
            'targetGroup'   => 'All School',
            'status'        => 'published',
            'source'        => 'event',
            'eventRef'      => $eventId,
            'createdBy'     => $adminId,
            'createdAt'     => $nowIso,
            'publishedAt'   => $nowIso,
            'sentAt'        => $nowIso,
        ]);

        return $noticeId;
    }

    // ====================================================================
    //  INTERNALS
    // ====================================================================

    /**
     * Sanitize all string values in data array — prevent XSS in templates.
     */
    private function _sanitize_data(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || !preg_match('/^\w+$/', $key)) continue;
            if (is_string($value)) {
                $clean[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (is_numeric($value)) {
                $clean[$key] = $value;
            }
            // Skip arrays/objects — templates only use flat values
        }
        return $clean;
    }

    /**
     * Replace {{variable}} placeholders in a string.
     * Only allows word-character keys (\w+) to prevent injection.
     */
    private function _replace_vars(string $template, array $data): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($data) {
            return $data[$m[1]] ?? $m[0];
        }, $template);
    }

    /**
     * Check trigger conditions against event data.
     * Only supports flat key→numeric threshold comparisons.
     */
    private function _check_conditions(array $conditions, array $data): bool
    {
        if (empty($conditions)) return true;

        foreach ($conditions as $key => $threshold) {
            if (!is_string($key) || !preg_match('/^\w+$/', $key)) continue;
            if (is_array($threshold) || is_object($threshold)) continue;

            $actual = $data[$key] ?? null;
            if ($actual === null) continue;
            if (is_numeric($threshold) && is_numeric($actual)) {
                if ((float) $actual < (float) $threshold) return false;
            }
        }
        return true;
    }

    /**
     * Resolve recipient from event data. Parent lookup hits the Firestore
     * canonical students/{schoolId}_{studentId} doc and reads camelCase
     * fields with Title-Case backfill for older docs.
     */
    private function _resolve_recipient(string $type, array $data): array
    {
        switch ($type) {
            case 'parent':
                $studentId = $data['student_id'] ?? '';
                if ($studentId === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $studentId)) return [];
                $student = ($this->fs !== null)
                    ? $this->fs->getEntity(self::FS_COL_STUDENTS, $studentId)
                    : null;
                if (!is_array($student) || empty($student)) return [];

                $fatherName = (string) ($student['fatherName'] ?? $student['Father Name'] ?? '');
                $motherName = (string) ($student['motherName'] ?? $student['Mother Name'] ?? '');
                $studentNm  = (string) ($student['name']       ?? $student['Name']        ?? '');
                $phone      = (string) ($student['phoneNumber'] ?? $student['phone'] ?? $student['Phone'] ?? '');
                $fatherPh   = (string) ($student['fatherPhone'] ?? $student['Father Phone'] ?? '');

                return [
                    'id'      => $studentId,
                    'name'    => $data['parent_name']
                                 ?? ($fatherName !== '' ? $fatherName
                                     : ($motherName !== '' ? $motherName : $studentNm)),
                    'contact' => $phone !== '' ? $phone : $fatherPh,
                ];

            case 'student':
                $sid = $data['student_id'] ?? '';
                if ($sid !== '' && !preg_match('/^[A-Za-z0-9_\-]+$/', $sid)) return [];
                return [
                    'id'      => $sid,
                    'name'    => $data['student_name']  ?? '',
                    'contact' => $data['student_phone'] ?? '',
                ];

            case 'teacher':
                $tid = $data['teacher_id'] ?? '';
                if ($tid !== '' && !preg_match('/^[A-Za-z0-9_\-]+$/', $tid)) return [];
                return [
                    'id'      => $tid,
                    'name'    => $data['teacher_name']  ?? '',
                    'contact' => $data['teacher_phone'] ?? '',
                ];

            case 'staff':
                $sid = $data['staff_id'] ?? '';
                if ($sid !== '' && !preg_match('/^[A-Za-z0-9_\-]+$/', $sid)) return [];
                return [
                    'id'      => $sid,
                    'name'    => $data['staff_name']  ?? '',
                    'contact' => $data['staff_phone'] ?? '',
                ];

            case 'broadcast':
                return [
                    'id'      => 'all_school',
                    'name'    => 'All School',
                    'contact' => '',
                ];

            default:
                return [];
        }
    }

    // ====================================================================
    //  FEE NOTIFICATION METHODS — TC-IM080, TC-IM081, TC-IM084
    // ====================================================================

    /**
     * Send fee payment confirmation notification to parent.
     * TC-IM080, TC-IM084: Always fetch real-time data, never cache amounts.
     *
     * Two-step Firestore lookup: feeReceiptIndex/{schoolId}_{session}_{receiptNo}
     * resolves the receiptKey, then feeReceipts/{schoolId}_{receiptKey} carries
     * the canonical amount/student/month payload.
     */
    public function sendFeePaymentConfirmation(string $studentId, string $receiptNo, string $parentPhone = ''): bool
    {
        if ($this->fs === null) {
            log_message('error', "sendFeePaymentConfirmation requires Firestore_service ({$studentId}/{$receiptNo})");
            return false;
        }
        try {
            $idxDocId = "{$this->school_id}_{$this->session_year}_{$receiptNo}";
            $index    = $this->fs->get(self::FS_COL_RECEIPT_INDEX, $idxDocId);
            if (!is_array($index) || empty($index)) return false;
            $receiptKey = (string) ($index['receiptKey'] ?? '');
            if ($receiptKey === '') return false;

            $rcptDocId = "{$this->school_id}_{$receiptKey}";
            $receipt   = $this->fs->get(self::FS_COL_RECEIPTS, $rcptDocId);
            if (!is_array($receipt) || empty($receipt)) return false;

            $amount      = $receipt['amount']      ?? $receipt['Amount']       ?? 0;
            $studentName = $receipt['studentName'] ?? $receipt['Student_Name'] ?? 'Student';
            $month       = $receipt['month']       ?? $receipt['Month']        ?? '';

            $this->fire_event('fee_received', [
                'student_id'   => $studentId,
                'student_name' => $studentName,
                'amount'       => $amount,
                'receipt_no'   => $receiptNo,
                'month'        => $month,
                'parent_phone' => $parentPhone,
            ]);

            $message = "Payment of Rs. {$amount} received for {$studentName}";
            if ($month) $message .= " (Month: {$month})";
            $message .= ". Receipt No: {$receiptNo}. Thank you!";

            $this->_queueDirectNotification($studentId, [
                'title' => 'Fee Payment Confirmed',
                'body'  => $message,
                'type'  => 'fee_payment_confirmed',
                'data'  => [
                    'receipt_no' => $receiptNo,
                    'amount'     => (string) $amount,
                    'student_id' => $studentId,
                ],
            ]);

            return true;
        } catch (Exception $e) {
            log_message('error', "sendFeePaymentConfirmation failed for {$studentId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send fee reminder to defaulter parents.
     * TC-IM081: Retry queue if SMS gateway is down.
     *
     * Pending dues are sourced from Firestore feeDemands by composite filter
     * (schoolId, studentId, status IN ['Pending','Overdue','unpaid','partial']).
     * Backing index: ./firestore.indexes.json (feeDemands composite #N).
     */
    public function sendFeeReminder(array $studentIds): array
    {
        $results = ['sent' => 0, 'failed' => 0, 'queued' => 0];
        if ($this->fs === null) {
            log_message('error', 'sendFeeReminder requires Firestore_service');
            $results['failed'] = count($studentIds);
            return $results;
        }

        foreach ($studentIds as $studentId) {
            try {
                $demands = $this->fs->where(self::FS_COL_DEMANDS, [
                    ['schoolId',  '==', $this->school_id],
                    ['studentId', '==', $studentId],
                    ['status',    'in', ['Pending', 'Overdue', 'unpaid', 'partial']],
                ]);
                if (!is_array($demands)) $demands = [];

                $totalDues = 0.0;
                foreach ($demands as $row) {
                    $d = is_array($row) ? ($row['data'] ?? $row) : [];
                    $net  = (float) ($d['netAmount']  ?? $d['amount']    ?? 0);
                    $paid = (float) ($d['paidAmount'] ?? 0);
                    $bal  = $net - $paid;
                    if ($bal > 0) $totalDues += $bal;
                }

                if ($totalDues <= 0) continue;

                $this->fire_event('fee_due', [
                    'student_id' => $studentId,
                    'total_dues' => $totalDues,
                ]);

                $message = "Fee reminder: Outstanding dues of Rs. {$totalDues}. Please clear your dues at the earliest.";
                $this->_queueDirectNotification($studentId, [
                    'title' => 'Fee Reminder',
                    'body'  => $message,
                    'type'  => 'fee_reminder',
                    'data'  => [
                        'total_dues' => (string) $totalDues,
                        'student_id' => $studentId,
                    ],
                ]);

                $results['sent']++;
            } catch (Exception $e) {
                log_message('error', "sendFeeReminder failed for {$studentId}: " . $e->getMessage());
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Send defaulter alert when a student becomes a fee defaulter.
     */
    public function sendDefaulterAlert(string $studentId, float $totalDues): bool
    {
        try {
            $this->fire_event('fee_overdue', [
                'student_id' => $studentId,
                'total_dues' => $totalDues,
            ]);

            $message = "Your child has outstanding fees of Rs. {$totalDues}. This may affect exam access and results. Please clear dues immediately.";
            $this->_queueDirectNotification($studentId, [
                'title' => 'Fee Defaulter Alert',
                'body'  => $message,
                'type'  => 'fee_defaulter_alert',
                'data'  => [
                    'total_dues' => (string) $totalDues,
                    'student_id' => $studentId,
                ],
            ]);

            return true;
        } catch (Exception $e) {
            log_message('error', "sendDefaulterAlert failed for {$studentId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Queue a direct push notification for a student's parent.
     * Firestore-only path — delegates the queue write to _setQueue() so
     * the canonical messageQueue shape stays in one place.
     */
    private function _queueDirectNotification(string $studentId, array $notification): void
    {
        if (empty($this->school_name) || empty($this->firebase)) return;

        $recipient = $this->_resolve_recipient('parent', ['student_id' => $studentId]);
        if (empty($recipient)) {
            log_message('info', "No parent found for student {$studentId} — skipping direct notification");
            return;
        }

        $queueId = $this->_nextCommCounter('Queue', 5);

        $this->_setQueue($queueId, [
            'trigger_id'        => 'DIRECT',
            'template_id'       => '',
            'channel'           => 'push',
            'recipient_type'    => 'parent',
            'recipient_id'      => $recipient['id'] ?? '',
            'recipient_name'    => $recipient['name'] ?? '',
            'recipient_contact' => $recipient['contact'] ?? '',
            'title'             => $notification['title'] ?? '',
            'message_body'      => $notification['body'] ?? '',
            'status'            => 'pending',
            'priority'          => 'high',
            'attempts'          => 0,
            'max_attempts'      => 3,
            'error_message'     => '',
            'created_at'        => date('c'),
            'scheduled_at'      => date('c'),
            'sent_at'           => '',
            'source'            => 'direct_fee_notification',
            'event_type'        => $notification['type'] ?? 'fee_notification',
            'extra_data'        => $notification['data'] ?? [],
        ]);

        log_message('info', "Direct notification queued ({$queueId}) for student {$studentId}: {$notification['title']}");
    }
}
