<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Entity_firestore_sync.php';

/**
 * Subject_assignment_service — Single source of truth for teacher-subject-class assignments.
 *
 * ARCHITECTURE:
 *   Firestore collection: subjectAssignments (top-level, flat) — ONLY source.
 *   Doc ID format:        {schoolId}_{session}_{classKey}_{sectionKey}_{subjectCode}
 *                         (sectionKey can be "_ALL_" for class-wide assignments)
 *
 * VALIDATION RULES:
 *   1. Teacher must have the subject in their `teaching_subjects` capability list.
 *   2. Only ONE assignment can have isClassTeacher=true per (class, section) pair.
 *
 * USAGE:
 *   $this->load->library('subject_assignment_service', null, 'sas');
 *   $this->sas->init($this->fs, $this->school_id, $this->session_year);
 *
 *   // Old 5-arg signature (with $firebase and $schoolName) is still accepted for
 *   // backward compatibility; the extra arguments are ignored.
 */
class Subject_assignment_service
{
    /** @var Firestore_service */
    private $fs;

    /** @var string School ID (SCH_XXXXXX) */
    private $schoolId = '';

    /** @var string Academic session (e.g., "2026-27") */
    private $session = '';

    /** @var bool */
    private $ready = false;

    /** Firestore collection name */
    const COLLECTION = 'subjectAssignments';

    /**
     * Initialise the service. Supports both the new 3-arg signature and the
     * legacy 5-arg signature (fs, firebase, schoolId, schoolName, session) for
     * backward compatibility with callers that haven't migrated yet.
     */
    public function init(...$args): self
    {
        if (count($args) >= 5) {
            // Legacy: (fs, firebase, schoolId, schoolName, session)
            [$fs, , $schoolId, , $session] = $args;
        } else {
            // New: (fs, schoolId, session)
            [$fs, $schoolId, $session] = array_pad($args, 3, '');
        }

        $this->fs       = $fs;
        $this->schoolId = (string) $schoolId;
        $this->session  = (string) $session;
        $this->ready    = ($fs !== null && $this->schoolId !== '');
        return $this;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    // ══════════════════════════════════════════════════════════════════
    //  DOC ID HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Build doc ID for an assignment.
     * Format: {schoolId}_{session}_{classKey}_{sectionKey}_{subjectCode}
     * If sectionKey is empty, uses "_ALL_" placeholder (class-wide assignment).
     */
    public function docId(string $classKey, string $sectionKey, string $subjectCode): string
    {
        $sec = ($sectionKey === '' || $sectionKey === null) ? '_ALL_' : $sectionKey;
        $cls  = str_replace([' ', '/'], ['_', '_'], $classKey);
        $secK = str_replace([' ', '/'], ['_', '_'], $sec);
        $sub  = str_replace([' ', '/'], ['_', '_'], $subjectCode);
        return "{$this->schoolId}_{$this->session}_{$cls}_{$secK}_{$sub}";
    }

    // ══════════════════════════════════════════════════════════════════
    //  VALIDATION
    // ══════════════════════════════════════════════════════════════════

    /**
     * Check if a teacher can teach the given subject.
     * Reads staff/{schoolId}_{teacherId}.teaching_subjects.
     */
    public function teacherCanTeach(string $teacherId, string $subjectCode, string $subjectName = ''): bool
    {
        if ($teacherId === '') return true;

        try {
            $staff = $this->fs->getEntity('staff', $teacherId);
            if (!is_array($staff)) return false;

            $teachingSubjects = $staff['teaching_subjects'] ?? [];
            if (!is_array($teachingSubjects) || empty($teachingSubjects)) return false;

            $subjectName = $subjectName ?: $subjectCode;
            foreach ($teachingSubjects as $ts) {
                $ts = trim((string)$ts);
                if ($ts === '') continue;
                if (strcasecmp($ts, $subjectCode) === 0) return true;
                if (strcasecmp($ts, $subjectName) === 0) return true;
            }
        } catch (\Exception $e) {
            log_message('error', "Subject_assignment_service::teacherCanTeach failed: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Validate that no other assignment has isClassTeacher=true for the same class+section.
     *
     * @return string|null  Conflicting teacher ID if found, null otherwise
     */
    public function findExistingClassTeacher(string $classKey, string $sectionKey, string $excludeTeacherId = ''): ?string
    {
        try {
            $assignments = $this->fs->schoolWhere(self::COLLECTION, [
                ['session', '==', $this->session],
                ['className', '==', $classKey],
                ['section', '==', $sectionKey],
                ['isClassTeacher', '==', true],
            ]);

            foreach ($assignments as $assignment) {
                $d = $assignment['data'] ?? [];
                if (!empty($d['archived'])) continue;  // Phase 3 — skip archived rows
                $existingTeacher = $d['teacherId'] ?? '';
                if ($existingTeacher !== '' && $existingTeacher !== $excludeTeacherId) {
                    return $existingTeacher;
                }
            }
        } catch (\Exception $e) {
            log_message('error', "Subject_assignment_service::findExistingClassTeacher failed: " . $e->getMessage());
        }
        return null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  WRITE OPERATIONS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Save (replace) all subject assignments for a class+section.
     *
     * @return array {success: bool, errors: [], warnings: [], saved: int}
     */
    public function saveClassAssignments(string $classKey, string $sectionKey, array $subjects): array
    {
        $result = [
            'success'  => false,
            'errors'   => [],
            'warnings' => [],
            'saved'    => 0,
        ];

        if (!$this->ready) {
            $result['errors'][] = 'Service not initialized';
            return $result;
        }

        $classKey = trim($classKey);
        $sectionKey = trim($sectionKey);

        if ($classKey === '') {
            $result['errors'][] = 'Class is required';
            return $result;
        }

        // ── Validation pass ──────────────────────────────────────────
        $classTeacherCount = 0;
        $classTeacherTeacher = '';
        foreach ($subjects as $sub) {
            if (!is_array($sub) || empty($sub['code'])) continue;
            $code         = trim($sub['code']);
            $name         = trim($sub['name'] ?? '');
            $teacherId    = trim($sub['teacher_id'] ?? '');
            $isClsTeacher = !empty($sub['is_class_teacher']);

            if ($teacherId !== '' && !$this->teacherCanTeach($teacherId, $code, $name)) {
                $result['errors'][] = "Teacher {$teacherId} is not authorized to teach {$name} ({$code}). Add this subject to their teaching_subjects in Staff profile first.";
            }

            if ($isClsTeacher && $teacherId !== '') {
                $classTeacherCount++;
                $classTeacherTeacher = $teacherId;
            }
        }

        if ($classTeacherCount > 1) {
            $result['errors'][] = "Only one teacher can be marked as Class Teacher per section. You have {$classTeacherCount} marked.";
        }

        if ($classTeacherCount === 1) {
            $existingCT = $this->findExistingClassTeacher($classKey, $sectionKey, $classTeacherTeacher);
            if ($existingCT !== null) {
                $result['warnings'][] = "Existing class teacher {$existingCT} for {$classKey}/{$sectionKey} will be replaced by {$classTeacherTeacher}.";
            }
        }

        if (!empty($result['errors'])) {
            return $result;
        }

        // Delete existing assignments for this class+section (so re-saving with fewer subjects removes stale ones)
        $this->_deleteClassAssignments($classKey, $sectionKey);

        // Normalize class/section into canonical Phase 1 shape.
        $cs = Entity_firestore_sync::normalizeClassSection($classKey, $sectionKey);
        $canonicalClass   = $cs['className']  !== '' ? $cs['className']  : $classKey;
        $canonicalSection = $cs['section']    !== '' ? $cs['section']    : $sectionKey;
        $classOrder       = $cs['classOrder'];
        $sectionCode      = $cs['sectionCode'];
        $sectionKeyComposite = ($canonicalClass !== '' && $canonicalSection !== '')
            ? "{$canonicalClass}/{$canonicalSection}"
            : '';

        // If saving per-section, remove any stale class-wide (_ALL_) docs for the same class.
        if ($sectionKey !== '') {
            try {
                $allDocs = $this->fs->schoolWhere(self::COLLECTION, [
                    ['session', '==', $this->session],
                    ['className', '==', $canonicalClass],
                    ['section', '==', ''],
                ]);
                foreach ($allDocs as $aDoc) {
                    $d = $aDoc['data'] ?? $aDoc;
                    $aId = $d['id'] ?? '';
                    if ($aId !== '') {
                        try { $this->fs->remove(self::COLLECTION, $aId); }
                        catch (\Exception $e) {}
                    }
                }
            } catch (\Exception $e) {
                log_message('debug', 'Subject_assignment_service: class-wide cleanup skipped: ' . $e->getMessage());
            }
        }

        // ── Write to Firestore ───────────────────────────────────────
        $saved = 0;
        foreach ($subjects as $sub) {
            if (!is_array($sub) || empty($sub['code'])) continue;
            $code         = trim($sub['code']);
            $name         = trim($sub['name'] ?? $code);
            $category     = trim($sub['category'] ?? 'Core');
            $periods      = max(0, (int)($sub['periods_week'] ?? 0));
            $teacherId    = trim($sub['teacher_id'] ?? '');
            $teacherName  = trim($sub['teacher_name'] ?? '');
            $isClsTeacher = !empty($sub['is_class_teacher']);

            $docId = $this->docId($classKey, $sectionKey, $code);

            $doc = [
                'schoolId'        => $this->schoolId,
                'session'         => $this->session,
                'className'       => $canonicalClass,
                'section'         => $canonicalSection,
                'classOrder'      => $classOrder,
                'sectionCode'     => $sectionCode,
                'sectionKey'      => $sectionKeyComposite,
                'subjectCode'     => $code,
                'subjectName'     => $name,
                'category'        => $category,
                'periodsPerWeek'  => $periods,
                'teacherId'       => $teacherId,
                'teacherName'     => $teacherName,
                'isClassTeacher'  => $isClsTeacher,
                'updatedAt'       => date('c'),
            ];

            try {
                $this->fs->set(self::COLLECTION, $docId, $doc, true);
                $saved++;
            } catch (\Exception $e) {
                $result['errors'][] = "Failed to save {$code}: " . $e->getMessage();
                log_message('error', "Subject_assignment_service::saveClassAssignments [{$docId}] failed: " . $e->getMessage());
            }
        }

        // ── Cross-write classTeacherId on the section doc ────────────
        // Keeps `sections.classTeacherId` in sync with the canonical
        // `isClassTeacher` flag so mobile apps don't have to query
        // the assignments collection.
        if ($sectionKey !== '') {
            try {
                $sectionDocId = "{$this->schoolId}_{$this->session}_{$canonicalClass}_{$canonicalSection}";
                $this->fs->set('sections', $sectionDocId, [
                    'classTeacherId' => $classTeacherTeacher,
                    'className'      => $canonicalClass,
                    'section'        => $canonicalSection,
                    'classOrder'     => $classOrder,
                    'sectionCode'    => $sectionCode,
                    'sectionKey'     => $sectionKeyComposite,
                    'updatedAt'      => date('c'),
                ], /* merge */ true);
            } catch (\Exception $e) {
                log_message('error',
                    "Subject_assignment_service classTeacherId cross-write failed [{$classKey}/{$sectionKey}]: "
                    . $e->getMessage());
                $result['warnings'][] = 'classTeacherId cross-write to sections collection failed';
            }
        }

        $result['saved']   = $saved;
        $result['success'] = true;
        return $result;
    }

    /**
     * Delete all assignments for a class+section (used before re-saving).
     */
    private function _deleteClassAssignments(string $classKey, string $sectionKey): void
    {
        try {
            $existing = $this->fs->schoolWhere(self::COLLECTION, [
                ['session', '==', $this->session],
                ['className', '==', $classKey],
                ['section', '==', $sectionKey],
            ]);

            foreach ($existing as $assignment) {
                $d = $assignment['data'] ?? $assignment;
                $docId = $d['id'] ?? '';
                if ($docId !== '') {
                    $this->fs->remove(self::COLLECTION, $docId);
                }
            }
        } catch (\Exception $e) {
            log_message('error', "Subject_assignment_service::_deleteClassAssignments failed: " . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  READ OPERATIONS (Firestore-only)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Bulk-load ALL assignments for the current session in ONE Firestore query.
     * Returns map keyed by className: [ className => [ doc, doc, ... ] ]
     */
    public function getAllForSession(): array
    {
        if (!$this->ready) return [];

        $byClass = [];
        try {
            $results = $this->fs->sessionWhere(self::COLLECTION, []);
            foreach ($results as $r) {
                $d = $r['data'] ?? [];
                if (!empty($d['archived'])) continue;  // Phase 3 — skip archived rows
                $cls = $d['className'] ?? '';
                if ($cls === '') continue;
                $byClass[$cls][] = $d;
            }
        } catch (\Exception $e) {
            log_message('error', "Subject_assignment_service::getAllForSession failed: " . $e->getMessage());
        }
        return $byClass;
    }

    /**
     * Get all assignments for a class+section.
     */
    public function getAssignmentsForClass(string $classKey, string $sectionKey = ''): array
    {
        if (!$this->ready) return [];

        try {
            $conditions = [
                ['session', '==', $this->session],
                ['className', '==', $classKey],
            ];
            if ($sectionKey !== '') {
                $conditions[] = ['section', '==', $sectionKey];
            }
            $results = $this->fs->schoolWhere(self::COLLECTION, $conditions);

            $out = [];
            foreach ($results as $r) {
                $d = $r['data'] ?? [];
                if (!empty($d['archived'])) continue;  // Phase 3 — skip archived rows
                $out[] = $d;
            }
            return $out;
        } catch (\Exception $e) {
            log_message('error', "Subject_assignment_service::getAssignmentsForClass failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all assignments where this teacher is assigned.
     */
    public function getAssignmentsForTeacher(string $teacherId): array
    {
        if (!$this->ready || $teacherId === '') return [];

        try {
            $results = $this->fs->schoolWhere(self::COLLECTION, [
                ['teacherId', '==', $teacherId],
                ['session', '==', $this->session],
            ]);
            $out = [];
            foreach ($results as $r) {
                $d = $r['data'] ?? [];
                if (!empty($d['archived'])) continue;  // Phase 3 — skip archived rows
                $out[] = $d;
            }
            return $out;
        } catch (\Exception $e) {
            log_message('error', "Subject_assignment_service::getAssignmentsForTeacher failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get the list of teachers who can teach a given subject (for filtered dropdowns).
     *
     * Defensive logic (Phase 3 follow-up):
     *   1. Include staff whose `teaching_subjects` matches subjectCode/subjectName.
     *   2. Also include any staff who is currently assigned to this subject
     *      via subjectAssignments — even if their teaching_subjects list
     *      doesn't include it. subjectAssignments is the source of truth;
     *      teaching_subjects is just a UI helper that can drift out of sync.
     *      Without this fallback, a teacher with a stale teaching_subjects
     *      array silently disappears from the dropdown — and any existing
     *      assignment to them stops rendering.
     *
     *   Archived assignments (Phase 3 cascade) are skipped on purpose —
     *   if a teacher is deactivated, we don't surface them as eligible.
     *
     * @return array  [{id, name}, ...]
     */
    public function getEligibleTeachers(string $subjectCode, string $subjectName = ''): array
    {
        if (!$this->ready) return [];

        try {
            $staff = $this->fs->schoolWhere('staff', []);

            // Build a lookup of ACTIVE staff only — Inactive teachers must
            // disappear from active dropdowns even if their teaching_subjects
            // or a stale subjectAssignments row would otherwise surface them.
            $byId = [];
            foreach ($staff as $s) {
                $d   = $s['data'] ?? [];
                $rawStatus = (string) ($d['status'] ?? $d['Status'] ?? 'Active');
                if (strcasecmp(trim($rawStatus), 'Active') !== 0) continue; // skip Inactive
                $sid = $d['staffId'] ?? $d['User ID'] ?? '';
                if ($sid !== '') {
                    $byId[$sid] = [
                        'id'   => $sid,
                        'name' => $d['name'] ?? $d['Name'] ?? '',
                    ];
                }
            }

            $eligibleById = [];

            // ── 1. teaching_subjects-based eligibility ──────────────────
            foreach ($staff as $s) {
                $data = $s['data'] ?? [];
                $rawStatus = (string) ($data['status'] ?? $data['Status'] ?? 'Active');
                if (strcasecmp(trim($rawStatus), 'Active') !== 0) continue; // skip Inactive

                $teachingSubjects = $data['teaching_subjects'] ?? [];
                if (!is_array($teachingSubjects) || empty($teachingSubjects)) continue;

                $hasSubject = false;
                foreach ($teachingSubjects as $ts) {
                    $ts = trim((string)$ts);
                    if (strcasecmp($ts, $subjectCode) === 0) { $hasSubject = true; break; }
                    if ($subjectName && strcasecmp($ts, $subjectName) === 0) { $hasSubject = true; break; }
                }
                if (!$hasSubject) continue;

                $sid = $data['staffId'] ?? $data['User ID'] ?? '';
                if ($sid !== '') {
                    $eligibleById[$sid] = [
                        'id'   => $sid,
                        'name' => $data['name'] ?? $data['Name'] ?? '',
                    ];
                }
            }

            // ── 2. subjectAssignments-based eligibility (source of truth) ──
            // Pull every assignment for this subject (matches by subjectCode
            // OR subjectName) and resolve each unique teacherId.
            try {
                $assigns = $this->fs->schoolWhere(self::COLLECTION, [
                    ['session', '==', $this->session],
                ]);
                foreach ($assigns as $a) {
                    $ad = $a['data'] ?? [];
                    if (!empty($ad['archived'])) continue; // Phase 3 — skip archived

                    $aSubjCode = trim((string)($ad['subjectCode'] ?? ''));
                    $aSubjName = trim((string)($ad['subjectName'] ?? ''));
                    $matches =
                        ($subjectCode !== '' && strcasecmp($aSubjCode, $subjectCode) === 0)
                        || ($subjectName !== '' && strcasecmp($aSubjName, $subjectName) === 0);
                    if (!$matches) continue;

                    $tid = trim((string)($ad['teacherId'] ?? ''));
                    if ($tid === '' || isset($eligibleById[$tid])) continue;

                    // $byId contains only Active staff — if the teacher isn't there,
                    // they're either Inactive or genuinely deleted/cross-session.
                    // Either way we skip them so the dropdown stays clean.
                    if (!isset($byId[$tid])) continue;

                    $eligibleById[$tid] = $byId[$tid];
                }
            } catch (\Exception $e) {
                log_message('error', "getEligibleTeachers subjectAssignments fallback failed: " . $e->getMessage());
                // Non-fatal — primary path already populated eligibleById.
            }

            $eligible = array_values($eligibleById);
            usort($eligible, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            return $eligible;
        } catch (\Exception $e) {
            log_message('error', "Subject_assignment_service::getEligibleTeachers failed: " . $e->getMessage());
            return [];
        }
    }
}
