<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Homework_firestore_sync — Dual-writes homework data from RTDB to Firestore.
 *
 * The admin panel writes homework to RTDB. This library mirrors it to
 * Firestore (named database 'schoolsync') so the Parent and Teacher
 * Android apps can read it via their Firestore-primary HomeworkViewModels.
 *
 * Firestore collections synced:
 *   - homework      (class-level homework documents)
 *   - submissions   (per-student submission records)
 *
 * All writes are best-effort: RTDB remains source of truth.
 *
 * Format rule: className and section always use prefixed format
 * ("Class 8th" / "Section A") — same across all databases.
 */
class Homework_firestore_sync
{
    /** @var object Firebase library instance */
    private $firebase;

    /** @var string School identifier */
    private $schoolCode;

    /** @var string Academic session */
    private $session;

    /** @var bool Whether initialization succeeded */
    private $ready = false;

    // Firestore collection names — must match Android Constants.Firestore.*
    private const COL_HOMEWORK    = 'homework';
    private const COL_SUBMISSIONS = 'submissions';

    /**
     * Ensure "Class " prefix.
     */
    private static function _ensureClassPrefix(string $val): string
    {
        $s = trim($val);
        return ($s !== '' && stripos($s, 'Class ') !== 0) ? "Class {$s}" : $s;
    }

    /**
     * Ensure "Section " prefix.
     */
    private static function _ensureSectionPrefix(string $val): string
    {
        $s = trim($val);
        return ($s !== '' && stripos($s, 'Section ') !== 0) ? "Section {$s}" : $s;
    }

    /**
     * Initialise with Firebase instance and school context.
     */
    public function init($firebase, string $schoolCode, string $session): self
    {
        $this->firebase   = $firebase;
        $this->schoolCode = $schoolCode;
        $this->session    = $session;
        $this->ready      = ($firebase !== null && $schoolCode !== '' && $session !== '');
        return $this;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. CREATE HOMEWORK
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Sync a newly created homework to Firestore.
     *
     * Call after firebase->push() in create_homework().
     *
     * @param string $hwId       Generated homework ID from RTDB push
     * @param string $className  e.g. "Class 8th"
     * @param string $section    e.g. "Section A"
     * @param array  $hwData     Homework data written to RTDB
     */
    public function syncCreate(string $hwId, string $className, string $section, array $hwData): void
    {
        if (!$this->ready || $hwId === '') return;

        try {
            $cls = self::_ensureClassPrefix($className);
            $sec = self::_ensureSectionPrefix($section);
            $sectionKey = "{$cls}/{$sec}";

            // Map RTDB status to Firestore status (admin uses "Active", apps use "active")
            $status = strtolower($hwData['status'] ?? 'active');

            $doc = [
                'schoolId'        => $this->schoolCode,
                'session'         => $this->session,
                'className'       => $cls,
                'section'         => $sec,
                'sectionKey'      => $sectionKey,
                'title'           => $hwData['title'] ?? '',
                'description'     => $hwData['description'] ?? '',
                'subject'         => $hwData['subject'] ?? '',
                'teacherId'       => $hwData['teacherId'] ?? '',
                'teacherName'     => $hwData['teacherName'] ?? 'Admin',
                'dueDate'         => $hwData['dueDate'] ?? '',
                'createdAt'       => date('c'),
                'status'          => $status,
                'submissionCount' => 0,
                'totalStudents'   => $this->_countStudents($cls, $sec),
                'attachments'     => [],
            ];

            $this->firebase->firestoreSet(self::COL_HOMEWORK, $hwId, $doc);

        } catch (\Exception $e) {
            log_message('error', "Homework_firestore_sync::syncCreate() failed [{$hwId}]: " . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. UPDATE HOMEWORK
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Sync homework updates to Firestore.
     *
     * Call after firebase->update() in update_homework().
     *
     * @param string $hwId    Homework ID
     * @param array  $updates Fields that were updated in RTDB
     */
    public function syncUpdate(string $hwId, array $updates): void
    {
        if (!$this->ready || $hwId === '') return;

        try {
            $fsUpdates = [];

            if (isset($updates['title']))       $fsUpdates['title']       = $updates['title'];
            if (isset($updates['description'])) $fsUpdates['description'] = $updates['description'];
            if (isset($updates['subject']))     $fsUpdates['subject']     = $updates['subject'];
            if (isset($updates['dueDate']))     $fsUpdates['dueDate']     = $updates['dueDate'];
            if (isset($updates['status']))      $fsUpdates['status']      = strtolower($updates['status']);

            if (empty($fsUpdates)) return;

            $this->firebase->firestoreUpdate(self::COL_HOMEWORK, $hwId, $fsUpdates);

        } catch (\Exception $e) {
            log_message('error', "Homework_firestore_sync::syncUpdate() failed [{$hwId}]: " . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. DELETE HOMEWORK
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Delete homework from Firestore.
     *
     * Call after firebase->delete() in delete_homework().
     *
     * @param string $hwId Homework ID
     */
    public function syncDelete(string $hwId): void
    {
        if (!$this->ready || $hwId === '') return;

        try {
            $this->firebase->firestoreDelete(self::COL_HOMEWORK, $hwId);

            // Also delete all submissions for this homework
            $submissions = $this->firebase->firestoreQuery(
                self::COL_SUBMISSIONS,
                [['homeworkId', '=', $hwId]],
                null, 'ASC', 500
            );

            foreach ($submissions as $sub) {
                $this->firebase->firestoreDelete(self::COL_SUBMISSIONS, $sub['id']);
            }

        } catch (\Exception $e) {
            log_message('error', "Homework_firestore_sync::syncDelete() failed [{$hwId}]: " . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. CLOSE HOMEWORK
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Mark homework as closed in Firestore.
     *
     * @param string $hwId Homework ID
     */
    public function syncClose(string $hwId): void
    {
        $this->syncUpdate($hwId, ['status' => 'Closed']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  5. SYNC SUBMISSION STATUS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Sync a student's submission status to Firestore.
     *
     * Call when admin reviews/marks a student's homework status.
     *
     * @param string $hwId        Homework ID
     * @param string $studentId   Student ID
     * @param string $studentName Student display name
     * @param string $className   Class name
     * @param string $section     Section name
     * @param string $status      "submitted", "reviewed", "incomplete", etc.
     * @param string $remark      Admin remark
     * @param int    $score       Score (-1 = not graded)
     * @param int    $maxMarks    Maximum marks
     */
    public function syncSubmission(
        string $hwId,
        string $studentId,
        string $studentName,
        string $className,
        string $section,
        string $status,
        string $remark = '',
        int    $score = -1,
        int    $maxMarks = 0
    ): void {
        if (!$this->ready || $hwId === '' || $studentId === '') return;

        try {
            $cls = self::_ensureClassPrefix($className);
            $sec = self::_ensureSectionPrefix($section);
            $docId = "{$hwId}_{$studentId}";

            $doc = [
                'schoolId'    => $this->schoolCode,
                'homeworkId'  => $hwId,
                'studentId'   => $studentId,
                'studentName' => $studentName,
                'sectionKey'  => "{$cls}/{$sec}",
                'status'      => strtolower($status),
                'remark'      => $remark,
                'submittedAt' => date('c'),
                'reviewedBy'  => '',
                'reviewedAt'  => null,
                'files'       => [],
                'text'        => '',
                'score'       => $score,
                'maxMarks'    => $maxMarks,
            ];

            $this->firebase->firestoreSet(self::COL_SUBMISSIONS, $docId, $doc, true);

        } catch (\Exception $e) {
            log_message('error', "Homework_firestore_sync::syncSubmission() failed [{$hwId}/{$studentId}]: " . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  6. BULK SYNC (for initial migration)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Sync all existing homework from RTDB to Firestore.
     * Reads both admin path and app path for all classes.
     *
     * @return int Number of homework documents synced
     */
    public function syncAllHomework(): int
    {
        if (!$this->ready) return 0;

        $synced = 0;
        $sn = $this->schoolCode;
        $sy = $this->session;

        try {
            // Get all classes
            $classKeys = $this->firebase->shallow_get("Schools/{$sn}/{$sy}");

            foreach ($classKeys as $classKey) {
                if (stripos($classKey, 'Class ') !== 0) continue;

                $sections = $this->firebase->shallow_get("Schools/{$sn}/{$sy}/{$classKey}");
                foreach ($sections as $secKey) {
                    if (stripos($secKey, 'Section ') !== 0) continue;

                    // Admin path: Schools/{sn}/{sy}/Class X/Section Y/Homework/
                    $adminHw = $this->firebase->get(
                        "Schools/{$sn}/{$sy}/{$classKey}/{$secKey}/Homework"
                    );
                    if (is_array($adminHw)) {
                        foreach ($adminHw as $hwId => $hwData) {
                            if (!is_array($hwData)) continue;
                            $this->syncCreate($hwId, $classKey, $secKey, $hwData);
                            $synced++;
                        }
                    }

                    // App path: Schools/{sn}/{sy}/Homework/Class X/Section Y/
                    $appHw = $this->firebase->get(
                        "Schools/{$sn}/{$sy}/Homework/{$classKey}/{$secKey}"
                    );
                    if (is_array($appHw)) {
                        foreach ($appHw as $hwId => $hwData) {
                            if (!is_array($hwData)) continue;
                            $this->syncCreate($hwId, $classKey, $secKey, $hwData);
                            $synced++;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', "Homework_firestore_sync::syncAllHomework() failed: " . $e->getMessage());
        }

        return $synced;
    }

    // ─── Private helpers ────────────────────────────────────────────

    /**
     * Count students in a class/section (best-effort for totalStudents field).
     */
    private function _countStudents(string $className, string $section): int
    {
        try {
            $listPath = "Schools/{$this->schoolCode}/{$this->session}/{$className}/{$section}/Students/List";
            $keys = $this->firebase->shallow_get($listPath);
            return count($keys);
        } catch (\Exception $e) {
            return 0;
        }
    }
}
