<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Entity_firestore_sync.php';
require_once APPPATH . 'libraries/Teaching_staff.php';

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
     * One document per (school, session, class, section), holding nothing
     * anybody reads.
     *
     * Saving a section's subjects is a REPLACE, and the delete set that
     * decides what it replaces is read at the start of the request. CONC-D1
     * made the delete and the writes one atomic commit, so a crash can no
     * longer lose the rows — but it cannot notice that somebody else
     * replaced the same section in between. Their rows are not in this
     * request's delete set, so the replace quietly becomes an ADD and the
     * section carries subjects nobody chose.
     *
     * That is the same revocation failure ACAD-D14 aborts the save to
     * prevent: firestore.rules authorizes a marks write by reading these
     * rows, so a row that survives a replace is a teacher who kept access to
     * a class they were removed from.
     *
     * This document is read before the delete set and committed WITH the
     * batch, conditional on not having moved. Different sections touch
     * different documents and never block each other.
     */
    const COLLECTION_SECTION = 'subjectAssignmentSections';

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

    /**
     * The serialisation point for one section. Same substitution scheme as
     * docId(), so the two are recognisably about the same thing.
     */
    public function sectionLedgerId(string $classKey, string $sectionKey): string
    {
        $sec  = ($sectionKey === '') ? '_ALL_' : $sectionKey;
        $cls  = str_replace([' ', '/'], ['_', '_'], $classKey);
        $secK = str_replace([' ', '/'], ['_', '_'], $sec);
        return "{$this->schoolId}_{$this->session}_{$cls}_{$secK}";
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

        // A staff record that could not be READ is not a teacher who lacks the
        // subject. Both used to return false, and the caller's message told the
        // operator to go and add the subject to that teacher's profile — advice
        // that is simply wrong during an outage, about a colleague who is
        // perfectly well qualified. The refusal was safe; the explanation was
        // not. Callers catch the throw and say what actually happened.
        $staff = $this->staffRecordOrFail($teacherId);
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
        return false;
    }

    /**
     * One staff record, read fail-loud.
     *
     * null means the record is genuinely ABSENT; a read that FAILED throws.
     * Addressed by document id, exactly as before — the raw client is the
     * same one this service already uses to commit batches, so nothing new
     * is wired and Firestore_service is left alone. A client that cannot do
     * a fail-loud get keeps the previous behaviour rather than losing the
     * check altogether.
     *
     * @throws \Throwable when the record cannot be read
     */
    public function staffRecordOrFail(string $teacherId): ?array
    {
        $raw = method_exists($this->fs, 'raw_client') ? $this->fs->raw_client() : null;
        if ($raw !== null && method_exists($raw, 'getDocumentOrFail')) {
            return $raw->getDocumentOrFail('staff', $this->fs->docId($teacherId));
        }
        return $this->fs->getEntity('staff', $teacherId);
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

        // ── ONE LABEL FOR ONE SECTION, DECIDED HERE ──────────────────────
        //
        // Three things are derived from these two strings: the delete set that
        // decides which rows this save replaces, the document id, and the
        // document body. The body was already being written with the canonical
        // labels while the other two used whatever the caller passed, and each
        // disagreement costs something real.
        //
        // The delete set is queried by className, so "8" finds nothing filed
        // as "Class 8th": the old rows survive beside the new ones and every
        // teacher who should have lost access keeps it. And the document id is
        // what firestore.rules reconstructs to authorize a marks or homework
        // write — the Teacher app builds that hint from the canonical class
        // name, so a row filed as "8" is invisible to it and a properly
        // assigned teacher is refused.
        //
        // Academic::save_subject_assignments normalises before calling in;
        // Academic::copy_subject_assignments does not. The guard belongs here,
        // where every caller gets it. normalizeClassSection() is idempotent, so
        // a caller that already normalised loses nothing.
        $canon      = Entity_firestore_sync::normalizeClassSection($classKey, $sectionKey);
        $classKey   = $canon['className'] !== '' ? $canon['className'] : $classKey;
        $sectionKey = $canon['section']   !== '' ? $canon['section']   : $sectionKey;

        // The state this save is about to replace, captured BEFORE the delete
        // set is read. A concurrent save for the same section moves it, and
        // the commit below is refused rather than merging into a section it
        // never saw. A failed read yields no token, which becomes an
        // "expected to not exist" precondition — so an unreadable ledger
        // refuses the save rather than waving it through.
        $sectionLedgerId = $this->sectionLedgerId($classKey, $sectionKey);
        $ledgerDoc = $this->fs->get(self::COLLECTION_SECTION, $sectionLedgerId);
        $ledgerAt  = is_array($ledgerDoc)
            ? (string) ($ledgerDoc['__updateTime'] ?? '') : '';

        // ── Validation pass ──────────────────────────────────────────
        // The staff records fetched here are kept: the write below needs the
        // same ones for the teacher's real name, and reading them twice would
        // be two round trips for one answer.
        $knownStaff = [];
        $classTeacherCount = 0;
        $classTeacherTeacher = '';
        foreach ($subjects as $sub) {
            if (!is_array($sub) || empty($sub['code'])) continue;
            $code         = trim($sub['code']);
            $name         = trim($sub['name'] ?? '');
            $teacherId    = trim($sub['teacher_id'] ?? '');
            $isClsTeacher = !empty($sub['is_class_teacher']);

            if ($teacherId !== '') {
                try {
                    if (!array_key_exists($teacherId, $knownStaff)) {
                        $knownStaff[$teacherId] = $this->staffRecordOrFail($teacherId);
                    }
                    $canTeach = $this->teacherCanTeach($teacherId, $code, $name);
                } catch (\Throwable $e) {
                    log_message('error', 'Subject_assignment_service: staff record '
                        . 'unreadable for ' . $teacherId . ': ' . $e->getMessage());
                    $result['errors'][] = 'The staff records could not be read, so who '
                        . 'teaches what could not be checked and nothing was saved. '
                        . 'Please try again in a moment.';
                    return $result;
                }
                if (!$canTeach) {
                    $result['errors'][] = "Teacher {$teacherId} is not authorized to teach {$name} ({$code}). Add this subject to their teaching_subjects in Staff profile first.";
                }
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
        // CONC-D1: collect the replace as ONE batch instead of deleting rows
        // and then writing them back one at a time. Between those two steps the
        // section had NO assignments, and a failure mid-loop left them deleted
        // and never rewritten — permanent loss of the rows that attendance and
        // marks authorization resolve through.
        //
        // NOTE: batchRemove() is a LOOP of single removes, not a transaction —
        // only commitBatch() maps to the Firestore :commit endpoint.
        $batchOps  = [];
        $rawClient = method_exists($this->fs, 'raw_client') ? $this->fs->raw_client() : null;
        $canBatch  = ($rawClient !== null && method_exists($rawClient, 'commitBatch'));

        // ACAD-D14: if the delete set cannot be read we must NOT fall through to
        // the writes below. Doing so saves the new rows while leaving every
        // removed teacher's row in place — a silent failure to revoke access
        // that firestore.rules then honours on marks writes.
        try {
            if ($canBatch) {
                foreach ($this->_collectExistingDocIds($classKey, $sectionKey) as $staleId) {
                    $batchOps[] = ['op' => 'delete', 'collection' => self::COLLECTION, 'docId' => $staleId];
                }
            } else {
                // Fallback: a client that cannot batch must still save.
                $this->_deleteClassAssignments($classKey, $sectionKey);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Subject_assignment_service: aborting save — existing '
                . 'assignments unreadable (school=' . $this->schoolId . ' class=' . $classKey
                . ' section=' . $sectionKey . '): ' . $e->getMessage());
            $result['errors'][] = 'The assignments already saved for this class could not '
                . 'be read, so nothing was changed. Saving now would have left teachers you '
                . 'removed still assigned. Please try again in a moment.';
            return $result;
        }

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
                // ACAD-D14: stale class-wide (_ALL_) rows grant WHOLE-CLASS access.
                // Two independent silent failures used to live here: the read
                // returned [] when it failed, and remove() returns BOOL (it never
                // throws), so the catch around it was inspecting nothing. Either
                // one left the old class-wide assignment in place.
                $allDocs = $this->fs->whereOrFail(self::COLLECTION, [
                    ['schoolId',  '==', $this->schoolId],
                    ['session',   '==', $this->session],
                    ['className', '==', $canonicalClass],
                    ['section',   '==', ''],
                ]);
                foreach ($allDocs as $aDoc) {
                    $d = $aDoc['data'] ?? $aDoc;
                    // query() returns [{id, data:{...}}] — the doc id is at the TOP
                    // level, not inside ['data']. Reading ['data']['id'] silently
                    // no-op'd, leaving stale class-wide rows behind.
                    $aId = $aDoc['id'] ?? ($d['id'] ?? '');
                    if ($aId !== '') {
                        if (!$this->fs->remove(self::COLLECTION, $aId)) {
                            throw new \RuntimeException('CLASSWIDE_CLEANUP_FAILED:' . $aId);
                        }
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', 'Subject_assignment_service: aborting save — class-wide '
                    . 'cleanup failed (school=' . $this->schoolId . ' class=' . $canonicalClass
                    . '): ' . $e->getMessage());
                $result['errors'][] = 'The existing class-wide assignments for this class '
                    . 'could not be cleared, so nothing was changed. Saving now would have '
                    . 'left a teacher assigned to the whole class. Please try again in a moment.';
                return $result;
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

            // The NAME is what everybody sees — the matrix, the Teacher app,
            // the timetable cell, the marks screen. Taking it from the request
            // lets it say anything at all about a real member of staff, and the
            // school's own record is right there. A record carrying no name
            // leaves the submitted one alone rather than blanking the row.
            if ($teacherId !== '') {
                $rec = $knownStaff[$teacherId] ?? null;
                if (is_array($rec)) {
                    $realName = \Teaching_staff::name($rec);
                    if ($realName !== '') { $teacherName = $realName; }
                }
            }

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

            if ($canBatch) {
                $batchOps[] = ['op' => 'set', 'collection' => self::COLLECTION, 'docId' => $docId, 'data' => $doc];
                $saved++;
                continue;
            }

            try {
                $this->fs->set(self::COLLECTION, $docId, $doc, true);
                $saved++;
            } catch (\Exception $e) {
                $result['errors'][] = "Failed to save {$code}: " . $e->getMessage();
                log_message('error', "Subject_assignment_service::saveClassAssignments [{$docId}] failed: " . $e->getMessage());
            }
        }

        // CONC-D1: one commit for the whole replace. Either every stale row is
        // removed AND every new row lands, or nothing changes at all.
        if ($canBatch && !empty($batchOps)) {
            // The serialisation point rides along, conditional on not having
            // moved since it was read above.
            $batchOps[] = [
                'op'         => 'set',
                'collection' => self::COLLECTION_SECTION,
                'docId'      => $sectionLedgerId,
                'data'       => [
                    'schoolId'  => $this->schoolId,
                    'session'   => $this->session,
                    'className' => $canonicalClass,
                    'section'   => $canonicalSection,
                    'updatedAt' => date('c'),
                ],
                'merge'        => true,
                'precondition' => $ledgerAt !== ''
                    ? ['updateTime' => $ledgerAt]
                    : ['exists' => false],
            ];

            if (method_exists($rawClient, 'commitBatchStatus')) {
                try {
                    $commit = $rawClient->commitBatchStatus($batchOps);
                } catch (\Exception $e) {
                    $result['errors'][] = 'Could not save the assignments. Nothing was changed.';
                    log_message('error', 'Subject_assignment_service: commitBatchStatus failed: '
                        . $e->getMessage());
                    return $result;
                }

                $status = (string) ($commit['status'] ?? '');
                if ($status === 'PRECONDITION_CONFLICT') {
                    log_message('error', 'Subject_assignment_service: section CAS conflict ('
                        . $sectionLedgerId . ')');
                    $result['errors'][] = 'Somebody else changed the subjects for this '
                        . 'class while you were editing them, so nothing was saved — '
                        . 'saving now would have merged your list into theirs and left '
                        . 'teachers assigned that neither of you chose. Reload the class '
                        . 'and try again.';
                    return $result;
                }
                if ($status !== 'SUCCESS') {
                    $result['errors'][] = 'Could not save the assignments. Nothing was changed.';
                    log_message('error', 'Subject_assignment_service: commit returned '
                        . $status . ' for ' . $classKey . '/' . $sectionKey);
                    return $result;
                }
            } else {
                // A client that cannot report a status cannot be made
                // conditional either; the batch is still atomic.
                try {
                    if (!$rawClient->commitBatch($batchOps)) {
                        $result['errors'][] = 'Could not save the assignments. Nothing was changed.';
                        $saved = 0;
                        log_message('error', 'Subject_assignment_service: commitBatch returned false for '
                            . $classKey . '/' . $sectionKey);
                        return $result;
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = 'Could not save the assignments. Nothing was changed.';
                    $saved = 0;
                    log_message('error', 'Subject_assignment_service: commitBatch failed: ' . $e->getMessage());
                    return $result;
                }
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
    /**
     * CONC-D1: doc ids of the rows this save replaces, scoped to exactly one
     * session/class/section. Shared by the batch path and the fallback so the
     * two can never disagree about WHICH rows are being replaced.
     *
     * @return string[]
     */
    private function _collectExistingDocIds(string $classKey, string $sectionKey): array
    {
        // ACAD-D14: this returns a DELETE SET. schoolWhere() -> firestoreQuery()
        // returns [] for a FAILED read exactly as it does for "no existing
        // assignments", and an empty delete set means the caller writes the new
        // rows while every removed teacher's row SURVIVES.
        //
        // That is a revocation failure, not a cosmetic one: firestore.rules
        // authorizes a marks write by reading
        // subjectAssignments/{school}_{session}_{class}_{section}_{subjectCode}
        // directly. docIds are deterministic per subject, so re-saved subjects
        // overwrite cleanly and ONLY the removed ones survive — the failure
        // lands precisely on the teachers who were supposed to lose access.
        //
        // Read fail-loud and let the caller abort before any write.
        $ids = [];
        try {
            $existing = $this->fs->whereOrFail(self::COLLECTION, [
                ['schoolId',  '==', $this->schoolId],
                ['session',   '==', $this->session],
                ['className', '==', $classKey],
                ['section',   '==', $sectionKey],
            ]);
            foreach ($existing as $assignment) {
                $d = $assignment['data'] ?? $assignment;
                $docId = $assignment['id'] ?? ($d['id'] ?? '');
                if ($docId !== '') $ids[] = (string) $docId;
            }
        } catch (\Throwable $e) {
            log_message('error', 'Subject_assignment_service::_collectExistingDocIds failed: '
                . $e->getMessage());
            throw new \RuntimeException('ASSIGNMENT_DELETE_SET_UNREADABLE');
        }
        return $ids;
    }

    private function _deleteClassAssignments(string $classKey, string $sectionKey): void
    {
        // ACAD-D14 — same revocation hazard as _collectExistingDocIds above.
        try {
            $existing = $this->fs->whereOrFail(self::COLLECTION, [
                ['schoolId',  '==', $this->schoolId],
                ['session',   '==', $this->session],
                ['className', '==', $classKey],
                ['section',   '==', $sectionKey],
            ]);

            foreach ($existing as $assignment) {
                $d = $assignment['data'] ?? $assignment;
                // query() returns the doc id at the TOP level ($assignment['id']),
                // not nested inside ['data'] — reading ['data']['id'] silently
                // no-op'd, so "delete before re-save" never actually deleted.
                $docId = $assignment['id'] ?? ($d['id'] ?? '');
                if ($docId !== '') {
                    // remove() returns BOOL and never throws — an ignored false
                    // here is a row that was supposed to be revoked and was not.
                    if (!$this->fs->remove(self::COLLECTION, $docId)) {
                        throw new \RuntimeException('ASSIGNMENT_DELETE_FAILED:' . $docId);
                    }
                }
            }
        } catch (\Throwable $e) {
            // ACAD-D14: this used to log and return, so the caller carried on and
            // wrote the new rows while the old ones survived. Rethrow so the save
            // aborts — a revocation that did not happen must not report success.
            log_message('error', 'Subject_assignment_service::_deleteClassAssignments failed: '
                . $e->getMessage());
            throw new \RuntimeException('ASSIGNMENT_DELETE_SET_UNREADABLE');
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

        // Rows are STORED under the canonical labels, so a caller asking for
        // "8" must be asking about "Class 8th" — otherwise the query matches
        // nothing and the caller concludes the class has no subjects. The copy
        // screen reads its source through here.
        $canon      = Entity_firestore_sync::normalizeClassSection($classKey, $sectionKey);
        $classKey   = $canon['className'] !== '' ? $canon['className'] : $classKey;
        $sectionKey = $canon['section']   !== '' ? $canon['section']   : $sectionKey;

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
     * The same read, fail-loud, for callers that ACT on an empty result.
     *
     * getAssignmentsForClass() returns [] for a failed read exactly as it does
     * for a class with no subjects, and it has sixteen callers across seven
     * files — most of which only display what comes back. Converting the
     * shared contract would reach into two modules already closed, for the
     * benefit of a better message.
     *
     * So this follows the precedent the timetable module set at ACAD-D25 and
     * that Firestore_service sets with where()/whereOrFail(): the tolerant
     * read stays, and a caller that would do something irreversible on an
     * empty answer asks the loud one instead. The copy screen is that caller —
     * it reports "no assignments found in source class" and stops, which is
     * simply untrue when the read failed.
     *
     * @throws \Throwable when the assignments cannot be read
     */
    public function getAssignmentsForClassOrFail(string $classKey, string $sectionKey = ''): array
    {
        if (!$this->ready) {
            throw new \RuntimeException('SUBJECT_ASSIGNMENT_SERVICE_NOT_READY');
        }

        $canon      = Entity_firestore_sync::normalizeClassSection($classKey, $sectionKey);
        $classKey   = $canon['className'] !== '' ? $canon['className'] : $classKey;
        $sectionKey = $canon['section']   !== '' ? $canon['section']   : $sectionKey;

        $conditions = [
            ['schoolId',  '==', $this->schoolId],
            ['session',   '==', $this->session],
            ['className', '==', $classKey],
        ];
        if ($sectionKey !== '') { $conditions[] = ['section', '==', $sectionKey]; }

        $out = [];
        foreach ($this->fs->whereOrFail(self::COLLECTION, $conditions) as $r) {
            $d = $r['data'] ?? [];
            if (!empty($d['archived'])) { continue; }
            $out[] = $d;
        }
        return $out;
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
