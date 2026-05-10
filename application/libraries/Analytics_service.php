<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Service_exception.php';

/**
 * Analytics_service — Phase 8 Academic Planner analytics.
 *
 * Read-only service that returns pre-computed counters from two summary
 * collections plus existing curriculum docs:
 *
 *   curriculum                    — Phase 1 syllabus counters (totalTopics, completedTopics, percentComplete)
 *   subjectPlanProgress           — Phase 8 per-(class,subject) plan counters
 *   dailyTeacherMonitoring        — Phase 8 per-(date,teacher) plan counters
 *
 * The summary collections are kept up-to-date by a write hook in
 * Lesson_plan_service::saveLessonPlan. A nightly rebuild script
 * (scripts/rebuild_analytics_summaries.js) recomputes them from
 * lessonPlans for drift recovery.
 *
 * No collection schema changes; existing APIs untouched.
 */
class Analytics_service
{
    /** @var object */ private $firebase;
    /** @var object */ private $fs;
    /** @var string */ private $schoolId = '';
    /** @var string */ private $session  = '';
    /** @var bool   */ private $ready    = false;

    const COLL_SUBJECT_PROGRESS    = 'subjectPlanProgress';
    const COLL_DAILY_MONITORING    = 'dailyTeacherMonitoring';
    const COLL_CURRICULUM          = 'curriculum';
    const COLL_LESSON_PLANS        = 'lessonPlans';
    const COLL_TIMETABLES          = 'timetables';

    public function init($firebase, $fs, string $schoolId, string $session): self
    {
        $this->firebase = $firebase;
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ready    = ($firebase !== null && $schoolId !== '');
        return $this;
    }

    public function isReady(): bool { return $this->ready; }

    // ══════════════════════════════════════════════════════════════════
    //  ADMIN ANALYTICS  (Phase 8A)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Phase 8A.1 — list every (class_section, subject) curriculum with its
     * topic completion counters. Reuses the existing `curriculum` doc
     * counters maintained by Curriculum_service (Phase 1).
     *
     * Returns: [ {classSection, subject, totalTopics, completedTopics, percentComplete}, ... ]
     */
    public function getSyllabusProgress(): array
    {
        $out = [];
        try {
            $docs = $this->fs->sessionWhere(self::COLL_CURRICULUM, []);
            foreach ($docs as $doc) {
                $d = $doc['data'] ?? $doc;
                $out[] = [
                    'classSection'    => (string)($d['classSection'] ?? ''),
                    'subject'         => (string)($d['subject']      ?? ''),
                    'totalTopics'     => (int)   ($d['totalTopics']     ?? 0),
                    'completedTopics' => (int)   ($d['completedTopics'] ?? 0),
                    'percentComplete' => (float) ($d['percentComplete'] ?? 0),
                ];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Analytics::getSyllabusProgress failed: ' . $e->getMessage());
        }
        usort($out, fn($a, $b) => strcmp($a['classSection'].$a['subject'], $b['classSection'].$b['subject']));
        return $out;
    }

    /**
     * Phase 8A.2 — for a given date, list every teacher with:
     *   - plansSaved      (from dailyTeacherMonitoring)
     *   - byStatus        (planned/completed/skipped/rescheduled)
     *   - expectedSlots   (from timetable for that day's day-of-week)
     *   - missingCount    (expectedSlots - plansSaved, never negative)
     *
     * expectedSlots is computed on-read (1 timetable query) so it stays
     * correct after admin edits the timetable without a counter rebuild.
     */
    public function getDailyMonitoring(string $date): array
    {
        if ($date === '') throw new Service_exception('date required', 'validation');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Service_exception('date must be YYYY-MM-DD', 'validation');
        }

        // 1. counters per teacher for this date
        $rows = [];
        try {
            $docs = $this->firebase->firestoreQuery(self::COLL_DAILY_MONITORING, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
                ['date',     '==', $date],
            ], null, 'ASC', 500);
            foreach ($docs as $doc) {
                $d = $doc['data'] ?? $doc;
                $tid = (string)($d['teacherId'] ?? '');
                if ($tid === '') continue;
                $rows[$tid] = [
                    'teacherId'        => $tid,
                    'teacherName'      => (string)($d['teacherName']      ?? ''),
                    'plansSaved'       => (int)   ($d['plansSaved']       ?? 0),
                    'plannedCount'     => (int)   ($d['plannedCount']     ?? 0),
                    'completedCount'   => (int)   ($d['completedCount']   ?? 0),
                    'skippedCount'     => (int)   ($d['skippedCount']     ?? 0),
                    'rescheduledCount' => (int)   ($d['rescheduledCount'] ?? 0),
                ];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Analytics::getDailyMonitoring counters failed: ' . $e->getMessage());
        }

        // 2. expected slots from timetable for that day-of-week
        $dayName = $this->_dayOfWeekFromIso($date);
        $expectedByTeacher = [];
        if ($dayName !== '') {
            try {
                $ttDocs = $this->firebase->firestoreQuery(self::COLL_TIMETABLES, [
                    ['schoolId', '==', $this->schoolId],
                    ['session',  '==', $this->session],
                    ['day',      '==', $dayName],
                ], null, 'ASC', 500);
                foreach ($ttDocs as $doc) {
                    $d = $doc['data'] ?? $doc;
                    foreach (($d['periods'] ?? []) as $p) {
                        if (!is_array($p)) continue;
                        $tid  = (string)($p['teacherId'] ?? '');
                        $subj = (string)($p['subject']   ?? '');
                        if ($tid === '' || $subj === '') continue;
                        $expectedByTeacher[$tid] = ($expectedByTeacher[$tid] ?? 0) + 1;
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', 'Analytics::getDailyMonitoring timetable read failed: ' . $e->getMessage());
            }
        }

        // 3. merge — include teachers with EITHER plans OR scheduled slots
        $out = [];
        $allIds = array_unique(array_merge(array_keys($rows), array_keys($expectedByTeacher)));
        foreach ($allIds as $tid) {
            $r = $rows[$tid] ?? [
                'teacherId' => $tid, 'teacherName' => '',
                'plansSaved' => 0, 'plannedCount' => 0, 'completedCount' => 0,
                'skippedCount' => 0, 'rescheduledCount' => 0,
            ];
            $expected = (int)($expectedByTeacher[$tid] ?? 0);
            $missing  = max(0, $expected - $r['plansSaved']);
            $r['expectedSlots'] = $expected;
            $r['missingCount']  = $missing;
            $out[] = $r;
        }
        usort($out, fn($a, $b) => $b['missingCount'] - $a['missingCount']);  // worst offenders first
        return [
            'date'    => $date,
            'dayName' => $dayName,
            'rows'    => $out,
        ];
    }

    /**
     * Phase 8A.3 — flag delays:
     *   - plans with status='skipped'
     *   - plans with status='planned' AND date < today (overdue / unfinished)
     *
     * Returned as two flat lists, capped at 200 rows each (most recent first).
     */
    public function getDelays(): array
    {
        $today = date('Y-m-d');

        $skipped   = [];
        $unfinished = [];
        try {
            // Skipped — single equality query, indexed on status auto
            $skippedDocs = $this->firebase->firestoreQuery(self::COLL_LESSON_PLANS, [
                ['status', '==', 'skipped'],
            ], 'date', 'DESC', 500);
            foreach ($skippedDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                if (($d['schoolId'] ?? '') !== $this->schoolId) continue;
                if (($d['session']  ?? '') !== $this->session)  continue;
                $skipped[] = $this->_compactPlan($d);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Analytics::getDelays skipped query failed: ' . $e->getMessage());
        }

        try {
            $plannedDocs = $this->firebase->firestoreQuery(self::COLL_LESSON_PLANS, [
                ['status', '==', 'planned'],
            ], 'date', 'DESC', 500);
            foreach ($plannedDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                if (($d['schoolId'] ?? '') !== $this->schoolId) continue;
                if (($d['session']  ?? '') !== $this->session)  continue;
                $date = (string)($d['date'] ?? '');
                if ($date === '' || $date >= $today) continue;  // only past-dated planned = unfinished
                $unfinished[] = $this->_compactPlan($d);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Analytics::getDelays planned query failed: ' . $e->getMessage());
        }

        return [
            'skipped'    => array_slice($skipped,    0, 200),
            'unfinished' => array_slice($unfinished, 0, 200),
            'asOf'       => $today,
        ];
    }

    /**
     * List subjectPlanProgress counters. Optional filter by classSection.
     */
    public function getSubjectProgress(string $classSection = ''): array
    {
        $out = [];
        try {
            $cond = [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
            ];
            if ($classSection !== '') $cond[] = ['classSection', '==', $classSection];
            $docs = $this->firebase->firestoreQuery(self::COLL_SUBJECT_PROGRESS, $cond, null, 'ASC', 500);
            foreach ($docs as $doc) {
                $d = $doc['data'] ?? $doc;
                $out[] = [
                    'classSection'     => (string)($d['classSection']     ?? ''),
                    'subject'          => (string)($d['subject']          ?? ''),
                    'totalPlans'       => (int)   ($d['totalPlans']       ?? 0),
                    'plannedCount'     => (int)   ($d['plannedCount']     ?? 0),
                    'completedCount'   => (int)   ($d['completedCount']   ?? 0),
                    'skippedCount'     => (int)   ($d['skippedCount']     ?? 0),
                    'rescheduledCount' => (int)   ($d['rescheduledCount'] ?? 0),
                    'percentComplete'  => (float) ($d['percentComplete']  ?? 0),
                ];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Analytics::getSubjectProgress failed: ' . $e->getMessage());
        }
        usort($out, fn($a, $b) => strcmp($a['classSection'].$a['subject'], $b['classSection'].$b['subject']));
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PARENT VIEW  (Phase 8B)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Phase 8B.1 — daily lesson summary for a child's class. Returns the
     * lesson plans saved for (classSection, date), sorted by periodIndex.
     * Notes are returned as-is; admin must redact before exposing externally
     * if needed.
     */
    public function getParentDailyLessons(string $classSection, string $date): array
    {
        if ($classSection === '' || $date === '') {
            throw new Service_exception('classSection and date required', 'validation');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Service_exception('date must be YYYY-MM-DD', 'validation');
        }

        $out = [];
        try {
            $docs = $this->firebase->firestoreQuery(self::COLL_LESSON_PLANS, [
                ['schoolId',     '==', $this->schoolId],
                ['session',      '==', $this->session],
                ['classSection', '==', $classSection],
                ['date',         '==', $date],
            ], 'periodIndex', 'ASC', 50);
            foreach ($docs as $doc) {
                $d = $doc['data'] ?? $doc;
                $out[] = [
                    'periodNumber' => (int)   ($d['periodNumber'] ?? 0),
                    'subject'      => (string)($d['subject']      ?? ''),
                    'teacherName'  => (string)($d['teacherName']  ?? ''),
                    'topicTitle'   => (string)($d['topicTitle']   ?? ''),
                    'notes'        => (string)($d['notes']        ?? ''),
                    'status'       => (string)($d['status']       ?? 'planned'),
                ];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Analytics::getParentDailyLessons failed: ' . $e->getMessage());
        }
        return [
            'classSection' => $classSection,
            'date'         => $date,
            'lessons'      => $out,
        ];
    }

    /**
     * Phase 8B.2 — per-subject completion for the child's class.
     * Same data as getSubjectProgress filtered to one classSection,
     * but returns the parent-friendly subset (no internal counter names).
     */
    public function getParentSubjectProgress(string $classSection): array
    {
        if ($classSection === '') {
            throw new Service_exception('classSection required', 'validation');
        }
        $rows = $this->getSubjectProgress($classSection);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'subject'          => $r['subject'],
                'totalPlans'       => $r['totalPlans'],
                'completedPlans'   => $r['completedCount'],
                'percentComplete'  => $r['percentComplete'],
            ];
        }
        return [
            'classSection' => $classSection,
            'subjects'     => $out,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════

    /** Project a lessonPlan doc to the slim shape returned by /delays. */
    private function _compactPlan(array $d): array
    {
        return [
            'planId'       => (string)($d['planId']       ?? ''),
            'classSection' => (string)($d['classSection'] ?? ''),
            'subject'      => (string)($d['subject']      ?? ''),
            'teacherId'    => (string)($d['teacherId']    ?? ''),
            'teacherName'  => (string)($d['teacherName']  ?? ''),
            'date'         => (string)($d['date']         ?? ''),
            'periodNumber' => (int)   ($d['periodNumber'] ?? 0),
            'topicTitle'   => (string)($d['topicTitle']   ?? ''),
            'status'       => (string)($d['status']       ?? ''),
        ];
    }

    private function _dayOfWeekFromIso(string $iso): string
    {
        $ts = strtotime($iso . 'T00:00:00');
        return $ts ? date('l', $ts) : '';
    }
}
