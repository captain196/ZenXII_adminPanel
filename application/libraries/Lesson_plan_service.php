<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Service_exception.php';
require_once APPPATH . 'libraries/Entity_firestore_sync.php';

/**
 * Lesson_plan_service — Phase 6 Academic Planner.
 *
 * Owns lesson-plan CRUD and monthly aggregation. Reads (never writes) from
 * subjectAssignments / curriculum / timetables for validation. New
 * `lessonPlans` collection is the single source of truth for plans.
 *
 * Schema (lessonPlans/{docId}):
 *   docId = "{schoolId}_{session}_{teacherId}_{date}_P{periodIndex}"
 *   Composite key encodes the (teacher, date, period) uniqueness invariant.
 *
 * Required composite indexes:
 *   - (schoolId, session, teacherId, date)        — teacher day/month
 *   - (schoolId, session, classSection, date)     — class day view
 *
 * Auth model (mirrors Curriculum_service::_canEdit):
 *   - Admin roles (Super Admin, Principal, Academic Coordinator, …) bypass.
 *   - Teachers can only save plans where teacherId == their own admin_id
 *     AND a subjectAssignment ties them to (class, section, subject).
 */
class Lesson_plan_service
{
    /** @var object */ private $firebase;
    /** @var object */ private $fs;
    /** @var string */ private $schoolId   = '';
    /** @var string */ private $schoolName = '';
    /** @var string */ private $session    = '';
    /** @var string */ private $adminId    = '';
    /** @var string */ private $adminName  = '';
    /** @var string */ private $adminRole  = '';
    /** @var object|null */ private $audit = null;
    /**
     * The subject-assignment service the authority gate reads.
     *
     * Reached through the CI singleton when nothing is injected, which is how
     * it has always worked in a request — and why the gate had never been run
     * by a test: outside a request the locator hands back whatever happens to
     * be registered, so every attempt to exercise the gate proved something
     * about the locator instead.
     */
    /** @var object|null */ private $sas = null;
    /** @var bool   */ private $ready = false;

    const COLLECTION = 'lessonPlans';
    const COLLECTION_AUDIT = 'academicAuditLog';
    /** How far back one plan's history is read before it is truncated. */
    const HISTORY_SCAN_LIMIT = 100;
    /** One teacher, one day — far more than any timetable holds. */
    const DAY_SCAN_LIMIT = 200;
    /** One teacher, one session: six periods a day for a full year and more. */
    const MONTH_SCAN_LIMIT = 2000;
    const VALID_STATUSES = ['planned', 'completed', 'skipped', 'rescheduled'];
    const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'];

    public function init(
        $firebase, $fs,
        string $schoolId, string $schoolName, string $session,
        string $adminId, string $adminName, string $adminRole,
        $auditLogService = null
    ): self {
        $this->firebase   = $firebase;
        $this->fs         = $fs;
        $this->schoolId   = $schoolId;
        $this->schoolName = $schoolName;
        $this->session    = $session;
        $this->adminId    = $adminId;
        $this->adminName  = $adminName;
        $this->adminRole  = $adminRole;
        $this->audit      = $auditLogService;
        $this->ready      = ($firebase !== null && $schoolId !== '');
        return $this;
    }

    public function isReady(): bool { return $this->ready; }

    /** Inject the assignment service the gate should ask. */
    public function withAssignmentService($sas): self
    {
        $this->sas = $sas;
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PUBLIC API
    // ══════════════════════════════════════════════════════════════════

    /**
     * Fetch a single lesson plan for (teacherId, date, periodIndex).
     * Returns the plan doc (camelCase) or null if absent.
     */
    public function getLessonPlan(string $teacherId, string $date, int $periodIndex): ?array
    {
        if ($teacherId === '' || $date === '' || $periodIndex < 0) {
            throw new Service_exception('teacher_id, date, period_index required', 'validation');
        }
        if (!$this->_isIsoDate($date)) {
            throw new Service_exception('date must be YYYY-MM-DD', 'validation');
        }

        $docId = $this->_planDocId($teacherId, $date, $periodIndex);
        // null means "nothing planned in this period" to every caller, so it
        // cannot also mean "the read failed": the two lead somewhere
        // different, and only one of them invites writing a plan.
        $doc = null;
        try {
            $doc = $this->firebase->firestoreGetOrFail(self::COLLECTION, $docId);
        } catch (\Throwable $e) {
            log_message('error', 'getLessonPlan read failed: ' . $e->getMessage());
            throw new Service_exception(
                'This period could not be read. Try again in a moment.', 'unavailable');
        }

        return is_array($doc) ? $doc : null;
    }

    /**
     * Phase 6A: fetch ALL lesson plans for a single (teacherId, date), sorted
     * by periodIndex ascending. Returns [] when none. Backs the daily-view
     * UI without per-period round-trips.
     *
     * Single-equality query on teacherId (auto-indexed); schoolId/session/date
     * filtered client-side — same pattern as getMonthlyPlan, no composite
     * index required.
     */
    public function getDailyPlan(string $teacherId, string $date): array
    {
        if ($teacherId === '' || $date === '') {
            throw new Service_exception('teacher_id and date required', 'validation');
        }
        if (!$this->_isIsoDate($date)) {
            throw new Service_exception('date must be YYYY-MM-DD', 'validation');
        }

        // The day view is drawn from this list, and an empty list is drawn as
        // a day nobody has planned. A failed query must therefore not produce
        // one. (School, session and date are still filtered in PHP below —
        // dropping another tenant's row is filtering, not failing.)
        $docs = [];
        try {
            // Narrowed to this school, session, teacher and day. Asking for a
            // teacher's ENTIRE history and filtering the rest here meant the
            // cap applied to rows the server chose, not to rows that belong to
            // this day — so another tenant's plans could fill it and leave a
            // teacher looking at an empty day. Equality filters only: no
            // ordering, so no composite index is required.
            $docs = $this->firebase->firestoreQueryOrFail(self::COLLECTION, [
                ['schoolId',  '==', $this->schoolId],
                ['session',   '==', $this->session],
                ['teacherId', '==', $teacherId],
                ['date',      '==', $date],
            ], null, 'ASC', self::DAY_SCAN_LIMIT);
            if (count($docs) >= self::DAY_SCAN_LIMIT) {
                // A day cannot hold this many periods; something is wrong and
                // the list is not the whole day.
                log_message('error', 'getDailyPlan hit the scan limit for '
                    . $teacherId . ' on ' . $date);
            }
        } catch (\Throwable $e) {
            log_message('error', 'getDailyPlan query failed: ' . $e->getMessage());
            throw new Service_exception(
                'The plans for this day could not be read, so the day is not being '
                . 'shown as empty. Try again in a moment.', 'unavailable');
        }

        $plans = [];
        foreach ($docs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            if (($d['schoolId'] ?? '') !== $this->schoolId) continue;
            if (($d['session']  ?? '') !== $this->session)  continue;
            if (($d['date']     ?? '') !== $date)           continue;
            $plans[] = $d;
        }
        usort($plans, fn($a, $b) => ((int)($a['periodIndex'] ?? 0)) <=> ((int)($b['periodIndex'] ?? 0)));
        return $plans;
    }

    /**
     * Upsert a lesson plan. Validates (in order):
     *   - basic params + status enum
     *   - actor authority (admin role OR teacherId == adminId AND assignment exists)
     *   - topicId belongs to the curriculum for (classSection, subject) — if provided
     *   - period actually exists in the timetable for that section/day with matching
     *     teacherId + subject
     *
     * Optional optimistic concurrency via expected_version (skips check if null/'').
     *
     * @param array $p input map (see save_lesson_plan controller for keys)
     * @return array  saved plan doc
     */
    public function saveLessonPlan(array $p): array
    {
        $className     = trim((string)($p['class_name']     ?? ''));
        $section       = trim((string)($p['section_name']   ?? ''));
        $subject       = trim((string)($p['subject']        ?? ''));
        $teacherId     = trim((string)($p['teacher_id']     ?? ''));
        $teacherName   = trim((string)($p['teacher_name']   ?? ''));
        $date          = trim((string)($p['date']           ?? ''));
        $periodIndex   = (int)         ($p['period_index']  ?? -1);
        $topicId       = trim((string)($p['topic_id']       ?? ''));
        $notes         = (string)      ($p['notes']         ?? '');
        $status        = trim((string)($p['status']         ?? 'planned')) ?: 'planned';
        $rescheduledTo = trim((string)($p['rescheduled_to'] ?? ''));
        $expectedVer   = $p['expected_version'] ?? null;

        // ── Basic validation ──
        if ($className === '' || $section === '' || $subject === '' ||
            $teacherId === '' || $date === '' || $periodIndex < 0) {
            throw new Service_exception(
                'class_name, section_name, subject, teacher_id, date, period_index required',
                'validation'
            );
        }
        if (!$this->_isIsoDate($date)) {
            throw new Service_exception('date must be YYYY-MM-DD', 'validation');
        }
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new Service_exception(
                'status must be one of: ' . implode(', ', self::VALID_STATUSES),
                'validation'
            );
        }
        if (strlen($notes) > 2000) {
            throw new Service_exception('notes too long (max 2000 chars)', 'validation');
        }
        if ($rescheduledTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}_P\d+$/', $rescheduledTo)) {
            throw new Service_exception(
                'rescheduled_to must be in form YYYY-MM-DD_P{periodIndex} (e.g. 2026-05-15_P3)',
                'validation'
            );
        }
        if ($status === 'rescheduled' && $rescheduledTo === '') {
            throw new Service_exception(
                'rescheduled_to is required when status=rescheduled',
                'validation'
            );
        }

        $cs = \Entity_firestore_sync::normalizeClassSection($className, $section);
        $canonClass   = $cs['className'] !== '' ? $cs['className'] : $className;
        $canonSection = $cs['section']   !== '' ? $cs['section']   : $section;
        $classSection = "{$canonClass}/{$canonSection}";
        // Phase 6A: dayOfWeek is ALWAYS derived server-side from date — any
        // client-supplied 'day' value is ignored to keep timetable lookups
        // consistent regardless of caller bugs.
        $dayOfWeek = $this->_dayOfWeek($date);

        // ── 1. Authority check ──
        if (!$this->_canEdit($teacherId, $canonClass, $canonSection, $subject)) {
            log_message(
                'error',
                "saveLessonPlan DENIED — actor={$this->adminId} role={$this->adminRole} " .
                "teacher={$teacherId} cls={$classSection} subj={$subject}"
            );
            throw new Service_exception(
                "Not authorized: lesson plans can only be saved for your assigned teacher×subject×class.",
                'auth'
            );
        }

        // ── 2. Period existence + teacher/subject match ──
        // _resolveTimetableCell throws when the timetable cannot be READ and
        // returns null only when the day genuinely has no such period. The two
        // used to arrive here identically, so an outage told the teacher to go
        // and set a timetable cell that was already set.
        $cell = $this->_resolveTimetableCell($canonClass, $canonSection, $dayOfWeek, $periodIndex);
        if ($cell === null) {
            throw new Service_exception(
                "No timetable period found for {$classSection} {$dayOfWeek} P" . ($periodIndex + 1) .
                ". Set the timetable cell first.",
                'not_found'
            );
        }
        $cellTeacherId = (string)($cell['teacherId'] ?? '');
        $cellSubject   = (string)($cell['subject']   ?? '');
        if ($cellTeacherId !== '' && $cellTeacherId !== $teacherId) {
            throw new Service_exception(
                "Period mismatch: timetable shows a different teacher in this slot. Update the timetable or pick the correct slot.",
                'validation'
            );
        }
        if ($cellSubject !== '' && strcasecmp($cellSubject, $subject) !== 0) {
            throw new Service_exception(
                "Period mismatch: timetable shows '{$cellSubject}' in this slot, not '{$subject}'.",
                'validation'
            );
        }
        if ($teacherName === '') $teacherName = (string)($cell['teacher'] ?? '');

        // ── 3. Topic validation (only if topicId supplied) ──
        $curriculumDocId = $this->_currDocId($classSection, $subject);
        // Phase 6A: when topicId is supplied, ALWAYS snapshot topicTitle from
        // the live curriculum doc into the plan. Subsequent reads must not
        // depend on a fresh curriculum lookup — the title is denormalised
        // here so list views, calendar tiles, and exports stay readable even
        // if the topic is later renamed or deleted.
        $topicTitle = '';
        if ($topicId !== '') {
            // _fetchTopic throws when the curriculum cannot be read; null means
            // the topic is genuinely not there. A topic that is genuinely gone is
            // the ordinary case after somebody edits the syllabus: the plan's
            // form still shows the old topic selected, so the message has to say
            // what to do rather than only that the id is invalid.
            $topic = $this->_fetchTopic($curriculumDocId, $topicId);
            if (!$topic) {
                throw new Service_exception(
                    "The topic this plan was written for is no longer in the curriculum " .
                    "for {$classSection} / {$subject}. Choose another topic, or clear the " .
                    "topic, and save again.",
                    'not_found'
                );
            }
            if (($topic['parentDocId'] ?? '') !== $curriculumDocId) {
                throw new Service_exception(
                    "Topic does not belong to the curriculum for this class/subject.",
                    'validation'
                );
            }
            $topicTitle = (string)($topic['title'] ?? '');
        }

        // ── 4. Optimistic concurrency ──
        $docId    = $this->_planDocId($teacherId, $date, $periodIndex);
        // The version this edit is racing against comes from the stored plan, so
        // a failed read here is not an empty slot — it is an unknown one. Read
        // silently, the failure looked like a creation: the check compared
        // against version 0, $isNew went true, and a colleague's plan was
        // replaced wholesale, notes and status and all.
        $existing = null;
        try {
            $existing = $this->firebase->firestoreGetOrFail(self::COLLECTION, $docId);
        } catch (\Throwable $e) {
            log_message('error', 'saveLessonPlan existing read failed: ' . $e->getMessage());
            throw new Service_exception(
                'The plan already in this period could not be read, so saving over it ' .
                'is not safe. Try again in a moment.',
                'unavailable'
            );
        }

        $currVersion = is_array($existing) ? (int)($existing['version'] ?? 0) : 0;
        if ($expectedVer !== null && $expectedVer !== '') {
            if ((int)$expectedVer !== $currVersion) {
                throw new Service_exception(
                    "Conflict: this plan was modified by another user (server v{$currVersion}, you had v{$expectedVer}). Reload and retry.",
                    'conflict'
                );
            }
        }

        $now        = date('c');
        $newVersion = $currVersion + 1;
        $isNew      = !is_array($existing);

        $data = [
            'schoolId'        => $this->schoolId,
            'session'         => $this->session,
            'planId'          => $docId,
            'className'       => $canonClass,
            'section'         => $canonSection,
            'classSection'    => $classSection,
            'subject'         => $subject,
            'teacherId'       => $teacherId,
            'teacherName'     => $teacherName,
            'date'            => $date,
            'dayOfWeek'       => $dayOfWeek,
            'periodIndex'     => $periodIndex,
            'periodNumber'    => $periodIndex + 1,
            'topicId'         => $topicId,
            'topicTitle'      => $topicTitle,
            'curriculumDocId' => $curriculumDocId,
            'notes'           => $notes,
            'status'          => $status,
            'rescheduledTo'   => $rescheduledTo,
            'completedAt'     => ($status === 'completed')
                                    ? (!empty($existing['completedAt']) ? $existing['completedAt'] : $now)
                                    : '',
            'updatedAt'       => $now,
            'updatedByUid'    => $this->adminId,
            'updatedByName'   => $this->adminName,
            'version'         => $newVersion,
        ];
        if ($isNew) {
            $data['createdAt']     = $now;
            $data['createdByUid']  = $this->adminId;
            $data['createdByName'] = $this->adminName;
        }

        // The version compared above was read a while ago, and everything
        // since is a window another save fits through: both requests read
        // version 3, both pass the check, both write version 4, and the
        // second silently replaces work the first person was told had been
        // saved. The Teacher app does this same job inside a Firestore
        // transaction, so the admin panel was the unguarded one — racing the
        // app across the very same document.
        //
        // The condition travels with the write instead: the document must
        // still be the one this save was decided from, or still be absent if
        // this save is a creation.
        $op = [
            'collection' => self::COLLECTION,
            'docId'      => $docId,
            'data'       => $data,
            'merge'      => true,
        ];
        $ut = is_array($existing) ? (string) ($existing['__updateTime'] ?? '') : '';
        if ($isNew) {
            $op['precondition'] = ['exists' => false];
        } elseif ($ut !== '') {
            $op['precondition'] = ['updateTime' => $ut];
        } else {
            log_message('error', 'saveLessonPlan: no __updateTime on ' . $docId
                . ' — writing without a compare-and-set guard');
        }

        $commit = null;
        try {
            $commit = $this->firebase->firestoreCommitBatchStatus([$op]);
        } catch (\Throwable $e) {
            log_message('error', 'saveLessonPlan write failed: ' . $e->getMessage());
            throw new Service_exception('Failed to save lesson plan.', 'internal');
        }
        $commitStatus = (string) ($commit['status'] ?? '');
        if ($commitStatus === 'PRECONDITION_CONFLICT') {
            log_message('error', 'saveLessonPlan refused: the plan moved between the '
                . 'read and the write (doc=' . $docId . ' actor=' . $this->adminId . ')');
            throw new Service_exception(
                'Conflict: this plan was changed by another user while you were '
                . 'working on it, so nothing was changed. Reload and try again.',
                'conflict'
            );
        }
        if ($commitStatus !== 'SUCCESS') {
            log_message('error', 'saveLessonPlan commit returned ' . $commitStatus
                . ': ' . (string) ($commit['message'] ?? ''));
            throw new Service_exception('Failed to save lesson plan.', 'internal');
        }

        // Phase 8: update analytics summaries (best-effort — never blocks the save).
        $beforeStatus = is_array($existing) ? (string)($existing['status'] ?? '') : '';
        $this->_updateAnalyticsSummariesBestEffort($beforeStatus, $status, $data);

        $this->_audit(
            $isNew ? 'create' : 'update',
            'lessonPlan',
            $docId,
            $isNew ? null : [
                'status'     => (string)($existing['status']     ?? ''),
                'topicId'    => (string)($existing['topicId']    ?? ''),
                'version'    => $currVersion,
            ],
            ['status' => $status, 'topicId' => $topicId, 'version' => $newVersion],
            ['classSection' => $classSection, 'subject' => $subject, 'date' => $date, 'periodIndex' => $periodIndex]
        );

        // Merge createdAt from existing if it was a doc-level update without overwriting
        if (!$isNew && isset($existing['createdAt']) && empty($data['createdAt'])) {
            $data['createdAt']     = $existing['createdAt'];
            $data['createdByUid']  = $existing['createdByUid']  ?? '';
            $data['createdByName'] = $existing['createdByName'] ?? '';
        }
        return $data;
    }

    /**
     * Get all of a teacher's lesson plans for a given (year, month). Returns
     *   [
     *     'teacherId'  => …,
     *     'year'       => …,
     *     'month'      => …,
     *     'days'       => { "YYYY-MM-DD" => [plan, plan, …], … },
     *     'totals'     => { planned, completed, skipped, rescheduled, total },
     *   ]
     */
    public function getMonthlyPlan(string $teacherId, int $year, int $month): array
    {
        if ($teacherId === '') {
            throw new Service_exception('teacher_id required', 'validation');
        }
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new Service_exception('year/month out of range', 'validation');
        }

        $truncated = false;
        $first  = sprintf('%04d-%02d-01', $year, $month);
        $prefix = sprintf('%04d-%02d-',   $year, $month);
        $last   = sprintf('%04d-%02d-%02d', $year, $month, (int)date('t', strtotime($first)));

        // Query by single-equality on teacherId — auto-indexed, no composite
        // required. Filter (schoolId, session, date-prefix) client-side. A
        // teacher's plan count per session is bounded, so the cap of 1000 is
        // ample headroom.
        // A month of zeroes is what a teacher who has planned nothing looks
        // like, and this is the view somebody uses to decide whether to raise
        // that with them. A failed query must not be able to produce it.
        $docs = [];
        try {
            // Six periods a day across a session is well past a thousand plans,
            // so a teacher-only query capped at a thousand computed a month's
            // totals from whichever rows came back. School and session narrow
            // it; one more row than the cap is fetched so the caller can be
            // told the totals are not everything.
            $docs = $this->firebase->firestoreQueryOrFail(self::COLLECTION, [
                ['schoolId',  '==', $this->schoolId],
                ['session',   '==', $this->session],
                ['teacherId', '==', $teacherId],
            ], null, 'ASC', self::MONTH_SCAN_LIMIT + 1);
            if (count($docs) > self::MONTH_SCAN_LIMIT) {
                $truncated = true;
                $docs = array_slice($docs, 0, self::MONTH_SCAN_LIMIT);
            }
        } catch (\Throwable $e) {
            log_message('error', 'getMonthlyPlan query failed: ' . $e->getMessage());
            throw new Service_exception(
                'The plans for this month could not be read, so no totals are being '
                . 'shown. Try again in a moment.', 'unavailable');
        }

        $days = [];
        $totals = ['planned' => 0, 'completed' => 0, 'skipped' => 0, 'rescheduled' => 0, 'total' => 0];
        foreach ($docs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            if (($d['schoolId'] ?? '') !== $this->schoolId) continue;
            if (($d['session']  ?? '') !== $this->session)  continue;
            $date = (string)($d['date'] ?? '');
            if ($date === '' || strpos($date, $prefix) !== 0) continue;
            if (!isset($days[$date])) $days[$date] = [];
            $days[$date][] = $d;
            $st = (string)($d['status'] ?? '');
            if (isset($totals[$st])) $totals[$st]++;
            $totals['total']++;
        }
        ksort($days);
        // Sort each day's plans by periodIndex
        foreach ($days as $date => &$arr) {
            usort($arr, fn($a, $b) => ((int)($a['periodIndex'] ?? 0)) <=> ((int)($b['periodIndex'] ?? 0)));
        }
        unset($arr);

        return [
            'teacherId' => $teacherId,
            'year'      => $year,
            'month'     => $month,
            'days'      => $days,
            'totals'    => $totals,
            // Totals presented as a month are a claim about a teacher. When
            // the read was capped they are not that claim, and the screen has
            // to be able to say so.
            'truncated' => $truncated,
            'notice'    => $truncated
                ? 'This teacher has more plans in the session than could be read '
                . 'at once, so these totals may be incomplete.'
                : '',
        ];
    }

    /**
     * WHAT HAPPENED TO ONE LESSON PLAN, AND WHO DID IT.
     *
     * Both writers record every save to the platform's academicAuditLog —
     * this service through Audit_log_service, the Teacher app directly, in
     * the same shape. Until now nothing read any of it back, which is the
     * same state the substitute module's log was found in: entries written
     * faithfully for a year and retrievable by nobody.
     *
     * A plan is where a disagreement lands — a period marked completed that
     * the teacher says they never taught, notes that changed after the fact —
     * and answering that needs the actor and the before-state, not just the
     * current document.
     *
     * Deliberately does NOT require the plan to still exist.
     */
    public function planHistory(string $teacherId, string $date, int $periodIndex): array
    {
        $teacherId = trim($teacherId);
        if ($teacherId === '' || $date === '' || $periodIndex < 0) {
            throw new Service_exception('teacher_id, date, period_index required', 'validation');
        }
        if (!$this->_isIsoDate($date)) {
            throw new Service_exception('date must be YYYY-MM-DD', 'validation');
        }

        $docId = $this->_planDocId($teacherId, $date, $periodIndex);

        try {
            $docs = $this->firebase->firestoreQueryOrFail(
                self::COLLECTION_AUDIT,
                [
                    ['schoolId', '==', $this->schoolId],
                    ['entityId', '==', $docId],
                ], 'ts', 'DESC', self::HISTORY_SCAN_LIMIT + 1);
        } catch (\Throwable $e) {
            log_message('error', 'planHistory read failed (plan=' . $docId . '): '
                . $e->getMessage());
            throw new Service_exception(
                'The history of this plan could not be read. Try again in a moment — '
                . 'this is not the same as nothing having happened to it.',
                'unavailable'
            );
        }

        $truncated = count($docs) > self::HISTORY_SCAN_LIMIT;
        if ($truncated) { $docs = array_slice($docs, 0, self::HISTORY_SCAN_LIMIT); }

        $rows = [];
        foreach ($docs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            // The query is school-scoped; session is not a query field on this
            // collection, so it is filtered here. Last year's plan is not this
            // year's business even when the period repeats.
            if ((string) ($d['session'] ?? '') !== (string) $this->session) { continue; }
            if ((string) ($d['entityType'] ?? '') !== 'lessonPlan') { continue; }

            $actor = is_array($d['actor'] ?? null) ? $d['actor'] : [];
            $by    = trim((string) ($actor['name'] ?? ''));
            $meta  = is_array($d['metadata'] ?? null) ? $d['metadata'] : [];

            $rows[] = [
                'at'     => (string) ($d['ts'] ?? ''),
                // A user id is not a name. Where none was recorded the row says
                // so rather than printing an identifier at somebody.
                'by'     => $by !== '' ? $by : 'Someone whose name was not recorded',
                'role'   => (string) ($actor['role'] ?? ''),
                // Both clients write here. Which one was used is part of the
                // answer when a teacher and an office disagree about a change.
                'source' => ((string) ($meta['source'] ?? '')) === 'teacher_app'
                                ? 'Teacher app' : 'Admin panel',
                'action' => (string) ($d['action'] ?? ''),
                'what'   => $this->_describePlanChange(
                    (string) ($d['action'] ?? ''),
                    is_array($d['before'] ?? null) ? $d['before'] : null,
                    is_array($d['after']  ?? null) ? $d['after']  : null
                ),
            ];
        }

        return [
            'planId'    => $docId,
            'entries'   => $rows,
            'truncated' => $truncated,
            'notice'    => $truncated
                ? 'Showing the ' . self::HISTORY_SCAN_LIMIT . ' most recent changes.'
                : '',
        ];
    }

    /**
     * One line saying what actually changed, for somebody who was not there.
     * Identifiers never appear: the person reading this cannot look one up.
     */
    private function _describePlanChange(string $action, ?array $before, ?array $after): string
    {
        $beforeStatus = trim((string) ($before['status'] ?? ''));
        $afterStatus  = trim((string) ($after['status']  ?? ''));
        $beforeTopic  = trim((string) ($before['topicId'] ?? ''));
        $afterTopic   = trim((string) ($after['topicId']  ?? ''));

        if ($action === 'create') {
            return $afterStatus !== ''
                ? 'Planned, as ' . $afterStatus
                : 'Planned';
        }
        if ($action === 'delete') {
            return 'Removed';
        }

        $parts = [];
        if ($afterStatus !== '' && $afterStatus !== $beforeStatus) {
            $parts[] = $beforeStatus !== ''
                ? 'Marked ' . $afterStatus . ' (was ' . $beforeStatus . ')'
                : 'Marked ' . $afterStatus;
        }
        if ($afterTopic !== $beforeTopic) {
            if ($afterTopic === '')      { $parts[] = 'Topic link removed'; }
            elseif ($beforeTopic === '') { $parts[] = 'Linked to a curriculum topic'; }
            else                         { $parts[] = 'Linked to a different topic'; }
        }
        if (!$parts) { return 'Edited'; }
        return implode('; ', $parts);
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Phase 8 — incrementally update the two analytics summary collections
     * after a lesson-plan save. Read-modify-write per doc; non-atomic but
     * the nightly rebuild script (rebuild_analytics_summaries.js) corrects
     * any drift from concurrent writes. NEVER throws — failures here must
     * not break the user save.
     *
     * Stage 2 kill-switch: when ANALYTICS_VIA_FUNCTION is true, the Cloud
     * Function on lessonPlans writes is the single source of truth and this
     * hook becomes a no-op. See application/config/constants.php.
     */
    private function _updateAnalyticsSummariesBestEffort(string $beforeStatus, string $afterStatus, array $data): void
    {
        if (defined('ANALYTICS_VIA_FUNCTION') && ANALYTICS_VIA_FUNCTION === true) {
            return;
        }
        try {
            $now    = $data['updatedAt'] ?? date('c');
            $isNew  = ($beforeStatus === '');

            // Status counter delta:
            //   new plan         → totalPlans+1, $afterStatus+1
            //   status changed   → $beforeStatus-1, $afterStatus+1
            //   status unchanged → no counter change (skip the writes)
            if (!$isNew && $beforeStatus === $afterStatus) return;

            $afterKey  = $afterStatus . 'Count';   // e.g. 'completedCount'
            $beforeKey = $beforeStatus . 'Count';

            // ── 1. subjectPlanProgress ──────────────────────────────────
            $classSlug   = str_replace([' ', '/'], '_', (string)($data['classSection'] ?? ''));
            $subjectSlug = str_replace([' ', '/'], '_', (string)($data['subject']      ?? ''));
            $progId      = "{$this->schoolId}_{$this->session}_{$classSlug}_{$subjectSlug}";

            // These counters are maintained by read-modify-write, so a failed
            // read is not "no counters yet" — starting from zero and writing the
            // result replaces a term's totals with a set reading 1. Skipping the
            // write instead leaves the count one behind, which is precisely the
            // drift rebuild_analytics_summaries.js exists to absorb.
            $existing = null;
            $progReadable = true;
            try { $existing = $this->firebase->firestoreGetOrFail('subjectPlanProgress', $progId); }
            catch (\Throwable $e) {
                $progReadable = false;
                log_message('error', 'subjectPlanProgress read failed; counter left as it '
                    . 'stands: ' . $e->getMessage());
            }

            $totals = [
                'totalPlans'       => (int)($existing['totalPlans']       ?? 0),
                'plannedCount'     => (int)($existing['plannedCount']     ?? 0),
                'completedCount'   => (int)($existing['completedCount']   ?? 0),
                'skippedCount'     => (int)($existing['skippedCount']     ?? 0),
                'rescheduledCount' => (int)($existing['rescheduledCount'] ?? 0),
            ];
            if ($isNew) {
                $totals['totalPlans']++;
                if (isset($totals[$afterKey])) $totals[$afterKey]++;
            } else {
                if (isset($totals[$beforeKey])) $totals[$beforeKey] = max(0, $totals[$beforeKey] - 1);
                if (isset($totals[$afterKey]))  $totals[$afterKey]++;
            }
            $totals['percentComplete'] = $totals['totalPlans'] > 0
                ? round($totals['completedCount'] / $totals['totalPlans'] * 1000) / 10
                : 0.0;

            if ($progReadable) $this->firebase->firestoreSet('subjectPlanProgress', $progId, array_merge($totals, [
                'schoolId'      => $this->schoolId,
                'session'       => $this->session,
                'className'     => $data['className']    ?? '',
                'section'       => $data['section']      ?? '',
                'classSection'  => $data['classSection'] ?? '',
                'subject'       => $data['subject']      ?? '',
                'lastUpdatedAt' => $now,
            ]), true);

            // ── 2. dailyTeacherMonitoring ───────────────────────────────
            $date      = (string)($data['date']      ?? '');
            $teacherId = (string)($data['teacherId'] ?? '');
            if ($date !== '' && $teacherId !== '') {
                $tidSlug = preg_replace('/[^A-Za-z0-9_\-]/', '_', $teacherId);
                $monId   = "{$this->schoolId}_{$this->session}_{$date}_{$tidSlug}";

                $existingMon = null;
                $monReadable = true;
                try { $existingMon = $this->firebase->firestoreGetOrFail('dailyTeacherMonitoring', $monId); }
                catch (\Throwable $e) {
                    $monReadable = false;
                    log_message('error', 'dailyTeacherMonitoring read failed; counter left '
                        . 'as it stands: ' . $e->getMessage());
                }

                $monTotals = [
                    'plansSaved'       => (int)($existingMon['plansSaved']       ?? 0),
                    'plannedCount'     => (int)($existingMon['plannedCount']     ?? 0),
                    'completedCount'   => (int)($existingMon['completedCount']   ?? 0),
                    'skippedCount'     => (int)($existingMon['skippedCount']     ?? 0),
                    'rescheduledCount' => (int)($existingMon['rescheduledCount'] ?? 0),
                ];
                if ($isNew) {
                    $monTotals['plansSaved']++;
                    if (isset($monTotals[$afterKey])) $monTotals[$afterKey]++;
                } else {
                    if (isset($monTotals[$beforeKey])) $monTotals[$beforeKey] = max(0, $monTotals[$beforeKey] - 1);
                    if (isset($monTotals[$afterKey]))  $monTotals[$afterKey]++;
                }

                if ($monReadable) $this->firebase->firestoreSet('dailyTeacherMonitoring', $monId, array_merge($monTotals, [
                    'schoolId'      => $this->schoolId,
                    'session'       => $this->session,
                    'date'          => $date,
                    'teacherId'     => $teacherId,
                    'teacherName'   => $data['teacherName'] ?? '',
                    'lastUpdatedAt' => $now,
                ]), true);
            }
        } catch (\Throwable $e) {
            log_message('error', 'analytics summary update failed (non-fatal): ' . $e->getMessage());
        }
    }

    private function _planDocId(string $teacherId, string $date, int $periodIndex): string
    {
        $tid = preg_replace('/[^A-Za-z0-9_\-]/', '_', $teacherId);
        return "{$this->schoolId}_{$this->session}_{$tid}_{$date}_P{$periodIndex}";
    }

    /** Same docId formula Curriculum_service uses. */
    private function _currDocId(string $classSection, string $subject): string
    {
        $cs  = str_replace([' ', '/'], '_', $classSection);
        $sub = str_replace([' ', '/'], '_', $subject);
        return "{$this->schoolId}_{$this->session}_{$cs}_{$sub}";
    }

    private function _isIsoDate(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    }

    private function _dayOfWeek(string $isoDate): string
    {
        $ts = strtotime($isoDate);
        return $ts ? date('l', $ts) : '';
    }

    /**
     * Authority: admin roles bypass. Otherwise the actor MUST be the teacher
     * AND have an assignment for (class, section, subject). Mirrors
     * Curriculum_service::_canEdit semantics.
     */
    private function _canEdit(string $teacherId, string $className, string $section, string $subject): bool
    {
        if (in_array($this->adminRole, self::ADMIN_ROLES, true)) return true;
        if ($this->adminId === '' || $teacherId !== $this->adminId) return false;

        try {
            $ci = function_exists('get_instance') ? get_instance() : null;
            $sas = $this->sas;
            if ($sas !== null) {
                // injected — nothing to look up
            } elseif ($ci) {
                $ci->load->library('subject_assignment_service', null, 'sas');
                $sas = $ci->sas;
                if (!$sas->isReady()) {
                    $sas->init($this->fs, $this->firebase, $this->schoolId, $this->schoolName, $this->session);
                }
            } else {
                // Off-CI fallback (simulator) — instantiate directly.
                require_once APPPATH . 'libraries/Subject_assignment_service.php';
                $sas = new Subject_assignment_service();
                $sas->init($this->fs, $this->firebase, $this->schoolId, $this->schoolName, $this->session);
            }

            // getAssignmentsForClass() answers a failed query with [], which
            // this gate cannot tell from "assigned to nothing" — and the
            // difference decides between refusing a teacher and telling them
            // the system could not check. The fail-loud sibling exists for
            // exactly this.
            $merged = array_merge(
                $sas->getAssignmentsForClassOrFail($className, $section),
                $sas->getAssignmentsForClassOrFail($className, '')
            );
            foreach ($merged as $a) {
                if (($a['teacherId'] ?? '') !== $teacherId) continue;
                $aName = (string)($a['subjectName'] ?? '');
                $aCode = (string)($a['subjectCode'] ?? '');
                if (strcasecmp($aName, $subject) === 0 || $aCode === $subject) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // NOT false. Returning false here denies a teacher who IS assigned,
            // under a message that says lesson plans can only be saved for
            // their own class — an accusation manufactured by a failed read,
            // at the moment the assignment cannot be checked at all.
            log_message('error', 'lesson_plan _canEdit lookup failed: ' . $e->getMessage());
            throw new Service_exception(
                'Your class assignments could not be read, so this plan was not '
                . 'saved. Try again in a moment.',
                'unavailable'
            );
        }
        return false;
    }

    /**
     * Read the timetable doc for a section/day and return the period at
     * $periodIndex (0-based) — or null if absent.
     */
    private function _resolveTimetableCell(string $className, string $section, string $day, int $periodIndex): ?array
    {
        if ($day === '') return null;
        $sectionKey = "{$className}/{$section}";
        $safeKey    = str_replace('/', '_', $sectionKey);
        $docId      = "{$this->schoolId}_{$this->session}_{$safeKey}_{$day}";

        $doc = null;
        try {
            $doc = $this->firebase->firestoreGetOrFail('timetables', $docId);
        } catch (\Throwable $e) {
            log_message('error', 'lesson_plan timetable read failed: ' . $e->getMessage());
            throw new Service_exception(
                'The timetable for this day could not be read, so the period cannot be ' .
                'checked. Try again in a moment.',
                'unavailable'
            );
        }
        if (!is_array($doc)) return null;

        foreach (($doc['periods'] ?? []) as $p) {
            $pi = ((int)($p['periodNumber'] ?? 0)) - 1;
            if ($pi === $periodIndex) return is_array($p) ? $p : null;
        }
        return null;
    }

    /**
     * Read a topic document directly from the curriculum subcollection.
     */
    private function _fetchTopic(string $parentDocId, string $topicId): ?array
    {
        $coll = "curriculum/{$parentDocId}/topics";
        try {
            $doc = $this->firebase->firestoreGetOrFail($coll, $topicId);
        } catch (\Throwable $e) {
            log_message('error', 'lesson_plan _fetchTopic failed: ' . $e->getMessage());
            throw new Service_exception(
                'The curriculum topic could not be read, so this plan cannot be ' .
                'checked against it. Try again in a moment.',
                'unavailable'
            );
        }
        return is_array($doc) ? $doc : null;
    }

    private function _audit(string $action, string $entityType, string $entityId, ?array $before, ?array $after, array $metadata): void
    {
        if (!$this->audit) return;
        try { $this->audit->log($action, $entityType, $entityId, $before, $after, $metadata); }
        catch (\Throwable $e) { log_message('error', 'lesson_plan audit failed: ' . $e->getMessage()); }
    }
}
