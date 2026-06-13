<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Exam_result_store — B1 FOUNDATIONAL SCAFFOLDING (Step 1, INERT).
 *
 * Reusable, Firestore-only building blocks for the unified results engine:
 *   - canonical doc-id builders (idToken strategy)
 *   - atomic commitBatch op-builders (set/delete + CAS preconditions)
 *   - correlationId support (retry-idempotent audit)
 *   - marks / results / examResultMeta / marksAudit / resultsAudit doc builders
 *   - a single atomic commit wrapper (delegates to Firebase::firestoreCommitBatch)
 *
 * STEP 1 CONTRACT: this class is NOT wired into any runtime path yet. No
 * save_marks / compute_results / reader uses it. It introduces NO dual-read,
 * dual-write, bridge, fallback, or RTDB access. Pure utility, Firestore-only.
 *
 * Approved contracts (frozen): examTemplates, marks(componentMarks canonical),
 * results(complete ResultDoc), examResultMeta, marksAudit, resultsAudit.
 *
 * @see Firestore_rest_client::commitBatch()  // atomic :commit, <=500 ops, CAS
 * @see Firestore_service::idToken()           // collision/Unicode-safe token
 */
class Exam_result_store
{
    /** @var object Firebase library (provides firestoreCommitBatch/firestoreGet) */
    private $firebase = null;
    private string $schoolId = '';
    private string $session  = '';
    private bool   $ready    = false;

    /** Firestore :commit hard limit. Audit-in-batch (~2-3 docs/student) lowers the
     *  effective student-per-commit ceiling — Step 2 chunking honours this. */
    const COMMIT_MAX_OPS = 500;

    public function init($firebase, string $schoolId, string $session): self
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ready    = ($firebase !== null && $schoolId !== '' && $session !== '');
        // Self-load the Firestore_helper collection-name constants so callers
        // never have to pre-load it (this library references Firestore_helper::*
        // throughout). Idempotent — CI caches the load.
        if (!class_exists('Firestore_helper') && function_exists('get_instance')) {
            get_instance()->load->library('firestore_helper');
        }
        return $this;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  IDENTITY (idToken strategy — collision-safe, Unicode-safe, deterministic)
    // ──────────────────────────────────────────────────────────────────────
    private function tok(string $raw): string { return Firestore_service::idToken($raw); }

    public function templateDocId(string $examId, string $class, string $section, string $subject): string
    { return "{$this->schoolId}_{$examId}_{$this->tok($class)}_{$this->tok($section)}_{$this->tok($subject)}"; }

    public function marksDocId(string $examId, string $class, string $section, string $subject, string $studentId): string
    { return "{$this->schoolId}_{$examId}_{$this->tok($class)}_{$this->tok($section)}_{$this->tok($subject)}_{$studentId}"; }

    public function resultDocId(string $examId, string $class, string $section, string $studentId): string
    { return "{$this->schoolId}_{$examId}_{$this->tok($class)}_{$this->tok($section)}_{$studentId}"; }

    public function metaDocId(string $examId, string $class, string $section): string
    { return "{$this->schoolId}_{$examId}_{$this->tok($class)}_{$this->tok($section)}"; }

    /** Deterministic audit id (correlationId-based) → retry-idempotent (no dup). */
    public function marksAuditDocId(string $correlationId, string $studentId, string $subject): string
    { return "{$this->schoolId}_{$correlationId}_{$studentId}_{$this->tok($subject)}"; }

    public function resultsAuditDocId(string $correlationId): string
    { return "{$this->schoolId}_{$correlationId}"; }

    /** Generate a correlationId for one logical save/compute/publish action. */
    public function newCorrelationId(string $prefix = 'b1'): string
    { return $prefix . '_' . bin2hex(random_bytes(8)); }

    // ──────────────────────────────────────────────────────────────────────
    //  COMMIT-BATCH OP BUILDERS  (shape per Firestore_rest_client::commitBatch)
    // ──────────────────────────────────────────────────────────────────────
    public function setOp(string $collection, string $docId, array $data, bool $merge = false, ?array $precondition = null): array
    {
        $op = ['op' => 'set', 'collection' => $collection, 'docId' => $docId, 'data' => $data, 'merge' => $merge];
        if ($precondition !== null) $op['precondition'] = $precondition;
        return $op;
    }
    public function deleteOp(string $collection, string $docId, ?array $precondition = null): array
    {
        $op = ['op' => 'delete', 'collection' => $collection, 'docId' => $docId];
        if ($precondition !== null) $op['precondition'] = $precondition;
        return $op;
    }
    /** CAS precondition: commit fails atomically if the doc moved since read. */
    public function casUpdateTime(string $updateTime): array { return ['updateTime' => $updateTime]; }
    /** create-only precondition (append-only audit). */
    public function casCreateOnly(): array { return ['exists' => false]; }

    // ──────────────────────────────────────────────────────────────────────
    //  DOC BUILDERS (approved schemas; pure — no I/O)
    // ──────────────────────────────────────────────────────────────────────
    /**
     * examTemplates: canonical marks-template for one exam/class/section/subject.
     * `components` is the ordered list [{name,maxMarks}]; totalMaxMarks = Σ maxMarks.
     * Schema matches the Phase-2A backfill (so authored + backfilled docs are
     * structurally identical).
     */
    public function buildTemplateDoc(string $examId, string $class, string $section, string $subject,
        array $components, int $totalMaxMarks, $createdAt, string $createdBy, string $updatedAt): array
    {
        $clean = [];
        foreach ($components as $c) {
            if (!is_array($c)) continue;
            $clean[] = ['name' => (string) ($c['name'] ?? ''), 'maxMarks' => (int) ($c['maxMarks'] ?? 0)];
        }
        return [
            'schoolId' => $this->schoolId, 'session' => $this->session, 'examId' => $examId,
            'className' => $class, 'section' => $section, 'subject' => $subject,
            'components' => $clean, 'totalMaxMarks' => $totalMaxMarks,
            'createdAt' => $createdAt, 'createdBy' => $createdBy, 'updatedAt' => $updatedAt,
        ];
    }

    /** Write/overwrite one examTemplates doc (designer save). Firestore-only. */
    public function writeTemplate(string $examId, string $class, string $section, string $subject, array $doc): bool
    {
        if (!$this->ready) return false;
        return $this->commit([ $this->setOp(Firestore_helper::EXAM_TEMPLATES,
            $this->templateDocId($examId, $class, $section, $subject), $doc, false) ]);
    }

    /** marks: componentMarks CANONICAL; theory/practical/total = derived convenience. */
    public function buildMarksDoc(string $examId, string $class, string $section, string $subject,
        string $studentId, string $studentName, array $componentMarks, bool $absent,
        int $maxMarks, string $savedBy, string $savedAt): array
    {
        $total = 0; $theory = 0; $practical = 0;
        foreach ($componentMarks as $cm) {
            $v = $absent ? 0 : (int) ($cm['value'] ?? 0);
            $total += $v;
            $n = strtolower((string) ($cm['name'] ?? ''));
            if (strpos($n, 'theory') !== false)    $theory    = $v;
            if (strpos($n, 'practical') !== false) $practical = $v;
        }
        return [
            'schoolId' => $this->schoolId, 'session' => $this->session, 'examId' => $examId,
            'className' => $class, 'section' => $section, 'subject' => $subject,
            'studentId' => $studentId, 'studentName' => $studentName,
            'componentMarks' => array_values($componentMarks),   // CANONICAL, ordered, arbitrary
            'absent' => $absent,
            'total' => $absent ? 0 : $total, 'theory' => $theory, 'practical' => $practical,
            'maxMarks' => $maxMarks,                              // convenience (from examTemplates)
            'savedAt' => $savedAt, 'savedBy' => $savedBy,
        ];
    }

    /** results: complete ResultDoc (+ subjects[].passFail, overall absent). */
    public function buildResultDoc(string $examId, string $examName, string $class, string $section,
        string $studentId, string $studentName, string $rollNo, array $subjects,
        $totalMarks, $maxMarks, $percentage, string $grade, string $passFail, bool $absent,
        array $absentSubjects, int $rank, string $gradingScale, int $passingPercent,
        string $computedBy, string $computedAt): array
    {
        return [
            'schoolId' => $this->schoolId, 'session' => $this->session, 'examId' => $examId,
            'examName' => $examName, 'className' => $class, 'section' => $section,
            // sectionKey "{className}/{section}" — REQUIRED by the Teacher app's
            // results queries (whereEqualTo("sectionKey", …)) and getSectionResults
            // (orderBy percentage). Format matches Teacher Constants.classKey/sectionKey.
            'sectionKey' => "{$class}/{$section}",
            'studentId' => $studentId, 'studentName' => $studentName, 'rollNo' => $rollNo,
            'subjects' => $subjects,   // {subject:{total,maxMarks,percentage,grade,passFail,absent}}
            'totalMarks' => $totalMarks, 'maxMarks' => $maxMarks, 'percentage' => $percentage, // percentage null preserved (CC-8)
            'grade' => $grade, 'passFail' => $passFail, 'absent' => $absent,
            'absentSubjects' => array_values($absentSubjects), 'rank' => $rank,
            'gradingScale' => $gradingScale, 'passingPercent' => $passingPercent,
            'computedAt' => $computedAt, 'computedBy' => $computedBy,
        ];
    }

    public function buildMetaStale(string $examId, string $class, string $section, string $lastMarksAt): array
    {
        return ['schoolId' => $this->schoolId, 'session' => $this->session, 'examId' => $examId,
            'className' => $class, 'section' => $section, 'stale' => true, 'lastMarksAt' => $lastMarksAt];
    }
    public function buildMetaClear(string $examId, string $class, string $section, string $lastComputedAt): array
    {
        return ['schoolId' => $this->schoolId, 'session' => $this->session, 'examId' => $examId,
            'className' => $class, 'section' => $section, 'stale' => false, 'lastComputedAt' => $lastComputedAt];
    }

    /** marksAudit: append-only; before/after componentMarks. */
    public function buildMarksAudit(string $correlationId, string $examId, string $class, string $section,
        string $subject, string $studentId, array $actor, string $action,
        ?array $before, ?array $after, string $publicationStateAtChange, string $reason, string $ts): array
    {
        return [
            'schoolId' => $this->schoolId, 'session' => $this->session, 'examId' => $examId,
            'className' => $class, 'section' => $section, 'subject' => $subject, 'studentId' => $studentId,
            'actor' => $actor, 'source' => ($actor['role'] ?? '') === 'teacher' ? 'teacher_app' : 'admin_panel',
            'action' => $action, 'before' => $before, 'after' => $after,
            'publicationStateAtChange' => $publicationStateAtChange,
            'reason' => $reason, 'correlationId' => $correlationId, 'timestamp' => $ts,
        ];
    }

    /** resultsAudit: append-only; compute/publish events + snapshot hash. */
    public function buildResultsAudit(string $correlationId, string $examId, string $class, string $section,
        array $actor, string $action, string $trigger, int $affectedStudents,
        string $prevStatus, string $newStatus, string $snapshotHash,
        string $lastMarksAt, string $lastComputedAt, string $reason, string $ts): array
    {
        return [
            'schoolId' => $this->schoolId, 'session' => $this->session, 'examId' => $examId,
            'className' => $class, 'section' => $section, 'actor' => $actor,
            'action' => $action, 'trigger' => $trigger, 'affectedStudents' => $affectedStudents,
            'prevPublicationStatus' => $prevStatus, 'newPublicationStatus' => $newStatus,
            'computedSnapshotHash' => $snapshotHash, 'lastMarksAt' => $lastMarksAt,
            'lastComputedAt' => $lastComputedAt, 'reason' => $reason,
            'correlationId' => $correlationId, 'timestamp' => $ts,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    //  STEP 2 — examResultMeta READ paths + stale lifecycle (read here; the
    //  WRITE happens only when a caller commits the op — NOT wired into
    //  save_marks/compute_results yet).
    // ──────────────────────────────────────────────────────────────────────
    public function readMeta(string $examId, string $class, string $section): ?array
    {
        if (!$this->ready) return null;
        return $this->firebase->firestoreGet(Firestore_helper::EXAM_RESULT_META, $this->metaDocId($examId, $class, $section));
    }
    public function isStale(string $examId, string $class, string $section): bool
    {
        $m = $this->readMeta($examId, $class, $section);

        // (1) Explicit server-set flag — admin marks-entry sets stale=true.
        if (is_array($m) && !empty($m['stale'])) return true;

        // (2) Option A derivation — marks edited AFTER the last compute → stale.
        //     This covers Teacher-app edits, which legitimately cannot write the
        //     server-only examResultMeta (Firestore rules deny client writes), so
        //     they never set the explicit flag. We compare the newest marks
        //     savedAt against the last compute time. Both are ISO-8601 strings
        //     (admin date('c'); the Teacher's serverTimestamp() reads back from
        //     the REST client as an ISO string too), so strtotime() handles both.
        $lastComputed = is_array($m) ? (string) ($m['lastComputedAt'] ?? '') : '';
        if ($lastComputed === '') return false;          // never computed → not "stale"
        $computedTs = strtotime($lastComputed);
        if ($computedTs === false) return false;

        $latestMarksTs = $this->latestMarksSavedAt($examId, $class, $section);
        if ($latestMarksTs <= 0) return false;

        // +1s tolerance so sub-second rounding / clock jitter can't false-trip.
        return $latestMarksTs > ($computedTs + 1);
    }

    /**
     * Newest marks.savedAt (epoch seconds) across all subjects of a section, or
     * 0 if none. Used by isStale() to detect post-compute edits (incl. teacher).
     */
    public function latestMarksSavedAt(string $examId, string $class, string $section): int
    {
        if (!$this->ready) return 0;
        $rows = $this->firebase->firestoreQuery(Firestore_helper::MARKS, [
            ['schoolId',  '==', $this->schoolId],
            ['examId',    '==', $examId],
            ['className',  '==', $class],
            ['section',   '==', $section],
        ], null, 'ASC', 5000);
        if (!is_array($rows)) return 0;
        $max = 0;
        foreach ($rows as $r) {
            $d  = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            $sa = $d['savedAt'] ?? '';
            // Tolerate a few decoded shapes: ISO string, sentinel array, epoch ms.
            if (is_array($sa))   $sa = (string) ($sa['value'] ?? ($sa['timestampValue'] ?? ''));
            if (is_int($sa) || (is_string($sa) && ctype_digit($sa))) {
                $ts = (int) ($sa > 1e12 ? (int) ($sa / 1000) : $sa); // ms → s if needed
            } else {
                $ts = is_string($sa) && $sa !== '' ? (int) strtotime($sa) : 0;
            }
            if ($ts > $max) $max = $ts;
        }
        return $max;
    }
    /** CAS token: pass to metaClearOp so the clear fails if marks changed mid-compute. */
    public function metaUpdateTime(string $examId, string $class, string $section): string
    {
        $m = $this->readMeta($examId, $class, $section);
        return is_array($m) ? (string) ($m['__updateTime'] ?? '') : '';
    }
    public function metaLastMarksAt(string $examId, string $class, string $section): string
    {
        $m = $this->readMeta($examId, $class, $section);
        return is_array($m) ? (string) ($m['lastMarksAt'] ?? '') : '';
    }

    // ── op-builders (returned for inclusion in a Step-3 atomic batch) ──
    /** stale=true + publicationDirty=true (marks changed). merge-write. */
    public function metaStaleOp(string $examId, string $class, string $section, string $lastMarksAt): array
    {
        $doc = array_merge($this->buildMetaStale($examId, $class, $section, $lastMarksAt), ['publicationDirty' => true]);
        return $this->setOp(Firestore_helper::EXAM_RESULT_META, $this->metaDocId($examId, $class, $section), $doc, true);
    }
    /** stale=false after a fresh compute. CAS on meta updateTime (optional) → clear
     *  fails atomically if marks bumped the doc since compute read it. */
    public function metaClearOp(string $examId, string $class, string $section, string $lastComputedAt, ?string $casUpdateTime = null): array
    {
        $pc = ($casUpdateTime !== null && $casUpdateTime !== '') ? $this->casUpdateTime($casUpdateTime) : null;
        return $this->setOp(Firestore_helper::EXAM_RESULT_META, $this->metaDocId($examId, $class, $section),
            $this->buildMetaClear($examId, $class, $section, $lastComputedAt), true, $pc);
    }
    /** publish marker: clears publicationDirty, stamps publishedAt + snapshot hash. */
    public function metaPublishOp(string $examId, string $class, string $section, string $snapshotHash, string $publishedAt): array
    {
        $doc = ['schoolId' => $this->schoolId, 'session' => $this->session, 'examId' => $examId,
            'className' => $class, 'section' => $section,
            'publicationDirty' => false, 'publishedAt' => $publishedAt, 'publishedSnapshotHash' => $snapshotHash];
        return $this->setOp(Firestore_helper::EXAM_RESULT_META, $this->metaDocId($examId, $class, $section), $doc, true);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  STEP 2 — Audit op-builders (append-only; create-only precondition)
    // ──────────────────────────────────────────────────────────────────────
    public function marksAuditOp(string $docId, array $auditDoc): array
    { return $this->setOp(Firestore_helper::MARKS_AUDIT, $docId, $auditDoc, false, $this->casCreateOnly()); }
    public function resultsAuditOp(string $docId, array $auditDoc): array
    { return $this->setOp(Firestore_helper::RESULTS_AUDIT, $docId, $auditDoc, false, $this->casCreateOnly()); }
    /** Standalone atomic audit commit (e.g., a publish event with no data change). */
    public function commitResultsAudit(array $auditDoc, string $correlationId): bool
    { return $this->commit([ $this->resultsAuditOp($this->resultsAuditDocId($correlationId), $auditDoc) ]); }

    // ──────────────────────────────────────────────────────────────────────
    //  STEP 2 — Staging / publication scaffolding (staged compute → promote)
    // ──────────────────────────────────────────────────────────────────────
    public function stagingDocId(string $examId, string $class, string $section, string $studentId): string
    { return $this->resultDocId($examId, $class, $section, $studentId); } // same id shape; different collection
    /** write a computed result to STAGING (admin-only; not parent-visible). */
    public function stagingSetOp(string $examId, string $class, string $section, string $studentId, array $resultDoc): array
    { return $this->setOp(Firestore_helper::RESULTS_STAGING, $this->stagingDocId($examId, $class, $section, $studentId), $resultDoc, false); }
    /**
     * Build the atomic PROMOTE op-set: staging → results (parent-visible), delete
     * staging, set publish marker, append resultsAudit. Caller passes the staged
     * result docs (already computed). Returns the ops for ONE atomic commit
     * (Step-3 chunks if > COMMIT_MAX_OPS, flipping the marker last).
     */
    public function promoteOps(string $examId, string $class, string $section, array $stagedResultDocsById,
        string $snapshotHash, string $publishedAt, array $resultsAuditDoc, string $correlationId): array
    {
        $ops = [];
        foreach ($stagedResultDocsById as $studentId => $resultDoc) {
            $ops[] = $this->setOp(Firestore_helper::RESULTS, $this->resultDocId($examId, $class, $section, (string) $studentId), $resultDoc, false);
            $ops[] = $this->deleteOp(Firestore_helper::RESULTS_STAGING, $this->stagingDocId($examId, $class, $section, (string) $studentId));
        }
        $ops[] = $this->metaPublishOp($examId, $class, $section, $snapshotHash, $publishedAt);
        $ops[] = $this->resultsAuditOp($this->resultsAuditDocId($correlationId), $resultsAuditDoc);
        return $ops;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  ATOMIC COMMIT (single :commit; Step 2 adds >limit chunking + marker)
    // ──────────────────────────────────────────────────────────────────────
    /** Commit one atomic batch (<= COMMIT_MAX_OPS). Returns false if not ready,
     *  empty, or over-limit (Step 2 chunking required for larger sets). */
    public function commit(array $ops): bool
    {
        if (!$this->ready || empty($ops)) return false;
        if (count($ops) > self::COMMIT_MAX_OPS) {
            log_message('error', 'Exam_result_store::commit() ops>' . self::COMMIT_MAX_OPS . ' — chunking is a Step 2 concern');
            return false;
        }
        return (bool) $this->firebase->firestoreCommitBatch($ops);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  C1 — canonical section results writer (Firestore-ONLY; one atomic batch
    //  per section: results set-ops + resultsAudit + metaClearOp(CAS)). No RTDB,
    //  no dual-write. $studentResults = exam_engine->compute_section() output
    //  (Title-case, null-preserving). $rosterNames = [studentId => name].
    // ──────────────────────────────────────────────────────────────────────
    public function commitSectionResults(string $examId, string $examName, string $class, string $section,
        array $studentResults, array $rosterNames, array $actor, string $gradingScale, int $passingPct,
        string $computedBy, string $computedAt, string $reason = ''): array
    {
        if (!$this->ready) return ['ok' => false, 'count' => 0, 'reason' => 'store not ready'];
        $cid = $this->newCorrelationId('compute');
        $ops = [];

        foreach ($studentResults as $uid => $L) {
            $uid = (string) $uid;
            if ($uid === '' || !is_array($L)) continue;
            $subjects = [];
            $absentSubjects = [];
            foreach (($L['Subjects'] ?? []) as $subj => $s) {
                if (!is_array($s)) continue;
                $subjects[(string) $subj] = [
                    'total'      => $s['Total'],          // null preserved (absent subject)
                    'maxMarks'   => $s['MaxMarks']   ?? 0,
                    'percentage' => $s['Percentage'],     // null preserved (absent subject)
                    'grade'      => (string) ($s['Grade'] ?? ''),
                    'passFail'   => (string) ($s['PassFail'] ?? ''),
                    'absent'     => !empty($s['Absent']),
                ];
                if (!empty($s['Absent'])) $absentSubjects[] = (string) $subj;
            }
            $doc = $this->buildResultDoc(
                $examId, $examName, $class, $section, $uid,
                (string) ($rosterNames[$uid] ?? ''), '', $subjects,
                $L['TotalMarks'] ?? 0, $L['MaxMarks'] ?? 0, $L['Percentage'] ?? null,   // overall percentage null preserved (CC-8)
                (string) ($L['Grade'] ?? ''), (string) ($L['PassFail'] ?? ''), !empty($L['Absent']),
                $absentSubjects, (int) ($L['Rank'] ?? 0), $gradingScale, $passingPct, $computedBy, $computedAt
            );
            $ops[] = $this->setOp(Firestore_helper::RESULTS, $this->resultDocId($examId, $class, $section, $uid), $doc, false);
        }

        if (empty($ops)) return ['ok' => true, 'count' => 0, 'reason' => 'no students'];

        // Single read of examResultMeta (supplies BOTH the audit's lastMarksAt and
        // the metaClearOp CAS token) — one Firestore get, not two.
        $meta        = $this->readMeta($examId, $class, $section);
        $lastMarksAt = is_array($meta) ? (string) ($meta['lastMarksAt'] ?? '') : '';
        $cas         = is_array($meta) ? (string) ($meta['__updateTime'] ?? '') : '';

        // resultsAudit — one append-only entry per compute run.
        $audit = $this->buildResultsAudit($cid, $examId, $class, $section, $actor, 'compute', 'admin_compute',
            count($ops), '', '', '', $lastMarksAt, $computedAt, $reason, $computedAt);
        $ops[] = $this->resultsAuditOp($this->resultsAuditDocId($cid), $audit);

        // metaClearOp with CAS on examResultMeta.updateTime — the whole commit
        // fails atomically if a marks edit bumped the meta doc mid-compute.
        $ops[] = $this->metaClearOp($examId, $class, $section, $computedAt, $cas !== '' ? $cas : null);

        // A single section's op count (students + 2) is always well under the
        // 500-op :commit limit → one atomic batch.
        if (count($ops) > self::COMMIT_MAX_OPS) {
            log_message('error', "Exam_result_store::commitSectionResults ops>" . self::COMMIT_MAX_OPS . " [{$examId}/{$class}/{$section}]");
            return ['ok' => false, 'count' => 0, 'reason' => 'section exceeds commit limit'];
        }
        $ok = $this->commit($ops);
        return ['ok' => $ok, 'count' => count($studentResults), 'reason' => $ok ? '' : 'commit failed'];
    }

    // ──────────────────────────────────────────────────────────────────────
    //  PHASE-E — Firestore-only delete cascade for one exam. Query-then-batch-
    //  delete the 5 runtime collections (results, marks, examResultMeta,
    //  resultsStaging, examTemplates). Audit collections (marksAudit/resultsAudit)
    //  are RETAINED for compliance; a terminal resultsAudit action='exam_deleted'
    //  event records actor + per-collection deleted counts. No RTDB, no bridge.
    // ──────────────────────────────────────────────────────────────────────
    public function cascadeDeleteExam(string $examId, array $actor, string $ts): array
    {
        if (!$this->ready || $examId === '') return ['ok' => false, 'counts' => [], 'reason' => 'not ready'];

        $cols = [
            Firestore_helper::RESULTS,
            Firestore_helper::MARKS,
            Firestore_helper::EXAM_RESULT_META,
            Firestore_helper::RESULTS_STAGING,
            Firestore_helper::EXAM_TEMPLATES,
        ];
        $counts = []; $ok = true;

        foreach ($cols as $col) {
            $total = 0;
            // Self-paginating: delete a page, re-query; the deleted docs no longer
            // match, so the next query returns the following page. Robust to any
            // size, always <= COMMIT_MAX_OPS ops per atomic commit.
            do {
                $rows = $this->firebase->firestoreQuery($col,
                    [['schoolId', '==', $this->schoolId], ['examId', '==', $examId]],
                    null, 'ASC', self::COMMIT_MAX_OPS);
                $ids = [];
                if (is_array($rows)) {
                    foreach ($rows as $r) {
                        $id = is_array($r) ? (string) ($r['id'] ?? '') : '';
                        if ($id !== '') $ids[] = $id;
                    }
                }
                if (empty($ids)) break;
                $delOps = array_map(fn($id) => $this->deleteOp($col, $id), $ids);
                $ok = $this->commit($delOps) && $ok;
                $total += count($ids);
            } while (count($ids) >= self::COMMIT_MAX_OPS && $ok);
            $counts[$col] = $total;
        }

        // Terminal append-only audit event (RETAINED — compliance trail of the deletion).
        $cid = $this->newCorrelationId('delete');
        $audit = [
            'schoolId' => $this->schoolId, 'session' => $this->session, 'examId' => $examId,
            'actor' => $actor, 'action' => 'exam_deleted', 'trigger' => 'exam_delete',
            'deletedCounts' => $counts, 'correlationId' => $cid, 'timestamp' => $ts,
        ];
        $ok = $this->commit([ $this->resultsAuditOp($this->resultsAuditDocId($cid), $audit) ]) && $ok;

        return ['ok' => $ok, 'counts' => $counts];
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CUMULATIVE — Firestore-only (cumulativeConfig + cumulative). Per-doc
    //  `stale` field replaces the legacy `_stale` sentinel row. No RTDB.
    // ──────────────────────────────────────────────────────────────────────
    public function cumulativeConfigDocId(): string { return "{$this->schoolId}_{$this->session}"; }
    public function cumulativeDocId(string $class, string $section, string $studentId): string
    { return "{$this->schoolId}_{$this->tok($class)}_{$this->tok($section)}_{$studentId}"; }

    /** cumulativeConfig: {exams:{examId:{weight,label}}, totalWeight}. */
    public function buildCumulativeConfigDoc(array $exams, int $totalWeight, string $updatedBy, string $updatedAt): array
    {
        return [
            'schoolId' => $this->schoolId, 'session' => $this->session,
            'exams' => $exams, 'totalWeight' => $totalWeight,
            'updatedBy' => $updatedBy, 'updatedAt' => $updatedAt,
        ];
    }
    public function writeCumulativeConfig(array $doc): bool
    {
        if (!$this->ready) return false;
        return $this->commit([ $this->setOp('cumulativeConfig', $this->cumulativeConfigDocId(), $doc, false) ]);
    }
    /** Read config doc (raw), remove one exam from the weights map, rewrite. */
    public function removeExamFromCumulativeConfig(string $examId): bool
    {
        if (!$this->ready) return true;
        $doc = $this->firebase->firestoreGet('cumulativeConfig', $this->cumulativeConfigDocId());
        if (!is_array($doc) || empty($doc['exams']) || !isset($doc['exams'][$examId])) return true; // nothing to do
        unset($doc['exams'][$examId]);
        unset($doc['__updateTime'], $doc['id']);
        return $this->commit([ $this->setOp('cumulativeConfig', $this->cumulativeConfigDocId(), $doc, false) ]);
    }

    /**
     * Atomically write all cumulative student docs for one section (stale=false).
     * $studentCumulative = the compute_cumulative Title-case map [uid => {...}].
     */
    public function commitCumulativeSection(string $class, string $section, array $studentCumulative, string $computedAt): array
    {
        if (!$this->ready) return ['ok' => false, 'count' => 0];
        $ops = [];
        foreach ($studentCumulative as $uid => $L) {
            $uid = (string) $uid;
            if ($uid === '' || !is_array($L)) continue;
            $subjects = [];
            foreach (($L['Subjects'] ?? []) as $sj => $s) {
                if (!is_array($s)) continue;
                $subjects[(string) $sj] = [
                    'weightedScore' => $s['WeightedScore'] ?? null,   // null preserved (CC-5 absent)
                    'grade'         => (string) ($s['Grade'] ?? ''),
                    'passFail'      => (string) ($s['PassFail'] ?? ''),
                    'absent'        => !empty($s['Absent']),
                ];
            }
            $doc = [
                'schoolId' => $this->schoolId, 'session' => $this->session,
                'className' => $class, 'section' => $section, 'studentId' => $uid,
                'weightedTotal' => $L['WeightedTotal'] ?? null,        // null preserved (CC-5)
                'grade' => (string) ($L['Grade'] ?? ''), 'passFail' => (string) ($L['PassFail'] ?? ''),
                'absent' => !empty($L['Absent']), 'subjects' => $subjects,
                'examsCovered' => (int) ($L['ExamsCovered'] ?? 0), 'totalExams' => (int) ($L['TotalExams'] ?? 0),
                'isPartial' => !empty($L['IsPartial']), 'rank' => $L['Rank'] ?? 'NR',
                'computedAt' => $computedAt, 'stale' => false,
            ];
            $ops[] = $this->setOp('cumulative', $this->cumulativeDocId($class, $section, $uid), $doc, false);
        }
        if (empty($ops)) return ['ok' => true, 'count' => 0];
        if (count($ops) > self::COMMIT_MAX_OPS) {
            log_message('error', "commitCumulativeSection ops>" . self::COMMIT_MAX_OPS . " [{$class}/{$section}]");
            return ['ok' => false, 'count' => 0];
        }
        $ok = $this->commit($ops);
        return ['ok' => $ok, 'count' => count($ops)];
    }

    /** Mark ALL cumulative docs (tenant/session) stale — exam delete invalidates cumulative. */
    public function markCumulativeStale(string $reason, string $staleAt): int
    {
        if (!$this->ready) return 0;
        $total = 0;
        do {
            $rows = $this->firebase->firestoreQuery('cumulative',
                [['schoolId', '==', $this->schoolId], ['session', '==', $this->session], ['stale', '==', false]],
                null, 'ASC', self::COMMIT_MAX_OPS);
            $ops = [];
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $id = is_array($r) ? (string) ($r['id'] ?? '') : '';
                    if ($id !== '') $ops[] = $this->setOp('cumulative', $id, ['stale' => true, 'staleReason' => $reason, 'staleAt' => $staleAt], true);
                }
            }
            if (empty($ops)) break;
            if (!$this->commit($ops)) break;
            $total += count($ops);
        } while (count($ops) >= self::COMMIT_MAX_OPS);
        return $total;
    }
}
