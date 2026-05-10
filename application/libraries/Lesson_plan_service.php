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
    /** @var bool   */ private $ready = false;

    const COLLECTION = 'lessonPlans';
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
        $doc   = null;
        try { $doc = $this->firebase->firestoreGet(self::COLLECTION, $docId); }
        catch (\Throwable $e) { log_message('error', 'getLessonPlan read failed: ' . $e->getMessage()); }

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

        $docs = [];
        try {
            $docs = $this->firebase->firestoreQuery(self::COLLECTION, [
                ['teacherId', '==', $teacherId],
            ], null, 'ASC', 1000);
        } catch (\Throwable $e) {
            log_message('error', 'getDailyPlan query failed: ' . $e->getMessage());
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
            $topic = $this->_fetchTopic($curriculumDocId, $topicId);
            if (!$topic) {
                throw new Service_exception(
                    "Invalid topic_id: topic not found in curriculum for {$classSection} / {$subject}.",
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
        $existing = null;
        try { $existing = $this->firebase->firestoreGet(self::COLLECTION, $docId); }
        catch (\Throwable $e) { log_message('error', 'saveLessonPlan existing read failed: ' . $e->getMessage()); }

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

        try {
            $this->firebase->firestoreSet(self::COLLECTION, $docId, $data, true);
        } catch (\Throwable $e) {
            log_message('error', 'saveLessonPlan write failed: ' . $e->getMessage());
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

        $first  = sprintf('%04d-%02d-01', $year, $month);
        $prefix = sprintf('%04d-%02d-',   $year, $month);
        $last   = sprintf('%04d-%02d-%02d', $year, $month, (int)date('t', strtotime($first)));

        // Query by single-equality on teacherId — auto-indexed, no composite
        // required. Filter (schoolId, session, date-prefix) client-side. A
        // teacher's plan count per session is bounded, so the cap of 1000 is
        // ample headroom.
        $docs = [];
        try {
            $docs = $this->firebase->firestoreQuery(self::COLLECTION, [
                ['teacherId', '==', $teacherId],
            ], null, 'ASC', 1000);
        } catch (\Throwable $e) {
            log_message('error', 'getMonthlyPlan query failed: ' . $e->getMessage());
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
        ];
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

            $existing = null;
            try { $existing = $this->firebase->firestoreGet('subjectPlanProgress', $progId); }
            catch (\Throwable $e) {}

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

            $this->firebase->firestoreSet('subjectPlanProgress', $progId, array_merge($totals, [
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
                try { $existingMon = $this->firebase->firestoreGet('dailyTeacherMonitoring', $monId); }
                catch (\Throwable $e) {}

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

                $this->firebase->firestoreSet('dailyTeacherMonitoring', $monId, array_merge($monTotals, [
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
            $sas = null;
            if ($ci) {
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

            $merged = array_merge(
                $sas->getAssignmentsForClass($className, $section),
                $sas->getAssignmentsForClass($className, '')
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
            log_message('error', 'lesson_plan _canEdit lookup failed: ' . $e->getMessage());
            return false;
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
        try { $doc = $this->firebase->firestoreGet('timetables', $docId); }
        catch (\Throwable $e) { log_message('error', 'lesson_plan timetable read failed: ' . $e->getMessage()); }
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
            $doc = $this->firebase->firestoreGet($coll, $topicId);
            return is_array($doc) ? $doc : null;
        } catch (\Throwable $e) {
            log_message('error', 'lesson_plan _fetchTopic failed: ' . $e->getMessage());
            return null;
        }
    }

    private function _audit(string $action, string $entityType, string $entityId, ?array $before, ?array $after, array $metadata): void
    {
        if (!$this->audit) return;
        try { $this->audit->log($action, $entityType, $entityId, $before, $after, $metadata); }
        catch (\Throwable $e) { log_message('error', 'lesson_plan audit failed: ' . $e->getMessage()); }
    }
}
