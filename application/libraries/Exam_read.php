<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Exam_read — EXAM-DEF-FS-CUTOVER Phase 2A read adapter.
 *
 * Single source for reading exam DEFINITIONS from Firestore (`exams` /
 * `examSchedule`) and returning them in the LEGACY RTDB-shaped arrays that
 * Exam/Examination/Result/Exam_engine + their views already consume — so
 * consumers and views change only their read source, not their logic.
 *
 * READ-ONLY. No writes, no RTDB dependency. gradingScale is returned verbatim
 * (engine vocabulary, e.g. "Percentage") and PassingPercent as int — preserving
 * CC-8 / CC-5 grading exactly.
 *
 * Known schema gaps (current `exams` collection does not store these — returned
 * as safe defaults; restore via a schema-additive follow-up):
 *   - GeneralInstructions → []
 *   - CreatedBy           → ''
 */
class Exam_read
{
    /**
     * RESIDUAL-3 (2026-07-08) — true cursor pagination for the section/subject
     * marks & results readers. The prior fixed cap (SECTION_READ_LIMIT) silently
     * truncated any section/subject that exceeded it. _queryAll() now walks the
     * whole result set page-by-page via the Firestore startAfter cursor, so ALL
     * docs are returned regardless of size.
     *
     * PAGE_SIZE — rows fetched per cursor page. A normal class-section fits in one
     *   page (≈40-60 students × subjects), so the common case is a single query.
     * HARD_CEILING — defensive absolute stop; if a single logical section ever
     *   exceeds this the loop bails and _warn_if_truncated() logs it (should be
     *   unreachable in practice — flags a data anomaly rather than truncating
     *   silently). SECTION_READ_LIMIT is retained as a back-compat alias.
     */
    const PAGE_SIZE          = 1000;
    const HARD_CEILING       = 100000;
    const SECTION_READ_LIMIT = 5000;  // legacy alias; no longer used as a query cap

    /** @var object Firebase wrapper (firestoreGet/firestoreQuery). */
    private $firebase;
    private $schoolId = '';
    private $session  = '';
    private $ready    = false;

    public function init($firebase, string $schoolId, string $session): self
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ready    = ($firebase !== null && $schoolId !== '' && $session !== '');
        return $this;
    }

    /** ISO `YYYY-MM-DD` → legacy display `d-m-Y` (matches old RTDB StartDate). */
    private function dispDate(string $iso): string
    {
        $iso = trim($iso); if ($iso === '') return '';
        $dt = DateTime::createFromFormat('Y-m-d', $iso);
        return ($dt && $dt->format('Y-m-d') === $iso) ? $dt->format('d-m-Y') : $iso;
    }

    /** ISO-8601 (date('c')) → epoch milliseconds (legacy CreatedAt is ms int). */
    private function isoToMs($v): int
    {
        if (is_int($v) || (is_string($v) && ctype_digit($v))) return (int) $v; // already ms
        $ts = is_string($v) ? strtotime($v) : 0;
        return $ts ? $ts * 1000 : 0;
    }

    /** Map a raw `exams` doc → legacy exam-meta shape (no Schedule). */
    private function mapMeta(array $d): array
    {
        return [
            'Name'                => (string) ($d['examName']  ?? ''),
            'Type'                => (string) ($d['examType']  ?? ''),
            'Status'              => (string) ($d['status']    ?? 'Draft'),
            'GradingScale'        => (string) ($d['gradingScale'] ?? ''),       // engine vocab verbatim
            'PassingPercent'      => (int)    ($d['passingPercent'] ?? 33),     // int preserved
            'StartDate'           => $this->dispDate((string) ($d['startDate'] ?? '')),
            'EndDate'             => $this->dispDate((string) ($d['endDate']   ?? '')),
            'CreatedAt'           => $this->isoToMs($d['createdAt'] ?? 0),       // ISO → ms for legacy consumers
            'CreatedBy'           => (string) ($d['createdBy'] ?? ''),
            // Phase 1 lifecycle audit (null until stamped on transition).
            'PublishedBy'         => (string) ($d['publishedBy'] ?? ''),
            'PublishedAt'         => ($d['publishedAt'] ?? null) === null ? null : $this->isoToMs($d['publishedAt']),
            'CompletedBy'         => (string) ($d['completedBy'] ?? ''),
            'CompletedAt'         => ($d['completedAt'] ?? null) === null ? null : $this->isoToMs($d['completedAt']),
            // Phase 3.1 reverse-lifecycle audit (null until stamped in Phase 3.2).
            'UnpublishedBy'       => (string) ($d['unpublishedBy'] ?? ''),
            'UnpublishedAt'       => ($d['unpublishedAt'] ?? null) === null ? null : $this->isoToMs($d['unpublishedAt']),
            'ReopenedBy'          => (string) ($d['reopenedBy'] ?? ''),
            'ReopenedAt'          => ($d['reopenedAt'] ?? null) === null ? null : $this->isoToMs($d['reopenedAt']),
            'GeneralInstructions' => is_array($d['generalInstructions'] ?? null) ? $d['generalInstructions'] : [],
        ];
    }

    /** Single exam meta (legacy shape) incl. reconstructed Schedule, or null. */
    public function meta(string $examId, bool $withSchedule = true): ?array
    {
        if (!$this->ready || $examId === '') return null;
        $doc = $this->firebase->firestoreGet('exams', "{$this->schoolId}_{$examId}");
        if (!is_array($doc) || empty($doc)) return null;
        $meta = $this->mapMeta($doc);
        if ($withSchedule) $meta['Schedule'] = $this->schedule($examId, $meta['PassingPercent']);
        return $meta;
    }

    /** All exams for this school/session → [examId => legacy meta] (no Schedule). */
    public function list_exams(): array
    {
        if (!$this->ready) return [];
        $rows = $this->firebase->firestoreQuery('exams',
            [['schoolId', '==', $this->schoolId], ['session', '==', $this->session]], null, 'ASC', 500);
        if (!is_array($rows)) return [];
        // LOW-12: 500 is ample for one school-session's exam count; flag if ever full.
        $this->_warn_if_truncated($rows, 500, 'list_exams');
        $out = [];
        foreach ($rows as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            $eid = (string) ($d['examId'] ?? '');
            if ($eid === '') continue;
            $out[$eid] = $this->mapMeta($d);
        }
        return $out;
    }

    /** Reconstruct legacy Schedule[class][section][date][subject] from examSchedule. */
    public function schedule(string $examId, ?int $passingPct = null): array
    {
        if (!$this->ready || $examId === '') return [];
        if ($passingPct === null) {
            $m = $this->meta($examId, false);
            $passingPct = $m['PassingPercent'] ?? 33;
        }
        $docs = $this->firebase->firestoreQuery('examSchedule',
            [['schoolId', '==', $this->schoolId], ['examId', '==', $examId]], null, 'ASC', 500);
        if (!is_array($docs)) return [];
        $sched = [];
        foreach ($docs as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            $class = (string) ($d['className'] ?? '');
            $sect  = (string) ($d['section'] ?? '');
            if ($class === '' || $sect === '') continue;
            foreach (($d['subjects'] ?? []) as $s) {
                if (!is_array($s)) continue;
                $subj = (string) ($s['subjectName'] ?? '');
                $date = (string) ($s['date'] ?? '');
                if ($subj === '' || $date === '') continue;
                $total = (int) ($s['maxTotal'] ?? 0);
                $sched[$class][$sect][$date][$subj] = [
                    'Time'         => trim((string)($s['startTime'] ?? '')) . '-' . trim((string)($s['endTime'] ?? '')),
                    'TotalMarks'   => $total,
                    // Phase 2B: prefer the stored passingMarks (byte-identical to legacy,
                    // incl. user overrides); fall back to recompute for legacy backfilled docs.
                    'PassingMarks' => (int) ($s['passingMarks'] ?? round($total * $passingPct / 100)),
                ];
            }
        }
        return $sched;
    }

    /**
     * B1 marks-reader cutover — canonical Firestore `marks` for an exam/class/
     * section, reshaped into the LEGACY RTDB-nested map `[subject][userId] =>
     * entry` so existing report-card / PDF / tabulation consumers + views read
     * Firestore without any logic change.
     *
     * Each entry is byte-faithful to the retired RTDB node:
     *   { <ComponentName>:value, …, Total, Absent, SavedAt, SavedBy }
     * Component values are emitted in canonical componentMarks order (=
     * examTemplates order). Consumers index components by Name (report-card view
     * `$stuMarks[$cn]`; tabulation JS `stuMarks[c.name]`) and pull Total/Absent
     * from the entry — so the reconstructed map renders identically.
     *
     * `className`/`section`/`subject` filter on the verbatim values save_marks
     * persists (e.g. "Class 9" / "Section A") — the same keys the callers hold.
     *
     * Firestore-only. Returns [] on not-ready / empty / error — the same
     * fail-soft contract as the retired `$this->firebase->get(...) ?? []`.
     */
    public function marks_section(string $examId, string $classKey, string $sectionKey): array
    {
        if (!$this->ready || $examId === '' || $classKey === '' || $sectionKey === '') return [];
        // RESIDUAL-3: paginate ALL docs (a section spans every subject, so this
        // set can exceed one page). orderBy studentId is non-unique here (one doc
        // per subject per student) — _queryAll handles the boundary ties.
        $rows = $this->_queryAll('marks', [
            ['schoolId',  '==', $this->schoolId],
            ['examId',    '==', $examId],
            ['className',  '==', $classKey],
            ['section',   '==', $sectionKey],
        ], 'studentId');
        if (!is_array($rows)) return [];

        $out = [];
        foreach ($rows as $r) {
            $d = $this->docData($r);
            if (!$d) continue;
            $subject = (string) ($d['subject']   ?? '');
            $userId  = (string) ($d['studentId'] ?? '');
            if ($subject === '' || $userId === '') continue;
            $out[$subject][$userId] = $this->marksEntry($d);
        }
        return $out;
    }

    /**
     * B1 marks re-edit reader — canonical Firestore `marks` for a single
     * exam/class/section/SUBJECT, reshaped into the LEGACY SUBJECT-SCOPED flat
     * map `[userId => entry]` (the shape the retired
     * `$this->firebase->get(.../Results/Marks/.../{subject})` returned). Used by
     * the marks-entry prefill path (get_marks AJAX + marks_sheet view) so the
     * edit flow reopens componentMarks without loss.
     *
     * Entry is byte-faithful to the retired node:
     *   { <ComponentName>:value, …, Total, Absent, SavedAt, SavedBy }
     *
     * Firestore-only. Returns [] on not-ready / empty / error — same fail-soft
     * contract as the retired `$this->firebase->get(...) ?? []`.
     */
    public function marks_subject(string $examId, string $classKey, string $sectionKey, string $subject): array
    {
        if (!$this->ready || $examId === '' || $classKey === '' || $sectionKey === '' || $subject === '') return [];
        // RESIDUAL-3: paginate ALL docs. Scoped to one subject → studentId is
        // unique within this query, so pagination is a clean per-student walk.
        $rows = $this->_queryAll('marks', [
            ['schoolId',  '==', $this->schoolId],
            ['examId',    '==', $examId],
            ['className',  '==', $classKey],
            ['section',   '==', $sectionKey],
            ['subject',   '==', $subject],
        ], 'studentId');
        if (!is_array($rows)) return [];

        $out = [];
        foreach ($rows as $r) {
            $d = $this->docData($r);
            if (!$d) continue;
            $userId = (string) ($d['studentId'] ?? '');
            if ($userId === '') continue;
            $out[$userId] = $this->marksEntry($d);
        }
        return $out;
    }

    /** Normalize a firestoreQuery row to its data map ({id,data} or flat). */
    private function docData($r): array
    {
        if (is_array($r) && isset($r['data']) && is_array($r['data'])) return $r['data'];
        return is_array($r) ? $r : [];
    }

    /**
     * LOW-12 / RESIDUAL-3 — defensive truncation log. The section/subject marks
     * & results readers now paginate fully via _queryAll(), so this fires only if
     * a single logical section exceeds the HARD_CEILING safety stop (unreachable
     * in practice — signals a data anomaly, not routine truncation). Still used by
     * the fixed-limit readers (list_exams etc.) whose scope genuinely can't grow.
     */
    private function _warn_if_truncated($rows, int $limit, string $where): void
    {
        if (is_array($rows) && count($rows) >= $limit) {
            log_message('error',
                "Exam_read::{$where} — result page filled the {$limit}-row limit; "
                . 'possible silent truncation — section may exceed the read ceiling (pagination needed).');
        }
    }

    /**
     * RESIDUAL-3 — fetch EVERY doc matching $conditions via Firestore cursor
     * pagination, so a large section/subject is never silently truncated.
     *
     * Walks pages of PAGE_SIZE ordered ASC by $orderBy, passing the last kept
     * row's $orderBy value as the exclusive `startAfter` cursor for the next page,
     * until a page comes back short (the final page).
     *
     * Non-unique orderBy (marks_section orders by studentId, but a student has one
     * doc per subject): the Firestore cursor is EXCLUSIVE on the orderBy VALUE, so
     * resuming at the last row's value would skip the remaining tied rows of the
     * boundary group. To stay lossless we DROP the trailing rows sharing the page's
     * last orderBy value and resume from the last strictly-smaller value — the whole
     * boundary group is then re-fetched intact on the next page (no loss, no dup).
     * For a unique orderBy (marks_subject / results_section) nothing is ever dropped,
     * so it degrades to a plain per-row walk.
     *
     * Requires a composite index covering the equality conditions + $orderBy. If
     * that index is absent the Firestore REST layer retries WITHOUT orderBy (client-
     * side sort) — correct & complete for any section that fits in one page (the
     * common case), but multi-page pagination needs the index deployed. See the
     * RESIDUAL-3 index additions in firebase-rules/firestore.indexes.json.
     *
     * @return array List of {id,data} rows (same shape firestoreQuery returns).
     */
    private function _queryAll(string $collection, array $conditions, string $orderBy): array
    {
        $all        = [];
        $startAfter = null;
        do {
            $page = $this->firebase->firestoreQuery(
                $collection, $conditions, $orderBy, 'ASC', self::PAGE_SIZE, $startAfter
            );
            if (!is_array($page) || empty($page)) break;
            $n = count($page);

            if ($n < self::PAGE_SIZE) {                 // final (short) page — keep all
                foreach ($page as $r) $all[] = $r;
                break;
            }

            // Full page → more may follow. Drop the trailing boundary group so the
            // exclusive cursor can't skip its tied rows on the next page.
            $lastVal = (string) ($this->docData($page[$n - 1])[$orderBy] ?? '');
            $keep = $n;
            while ($keep > 0
                && (string) ($this->docData($page[$keep - 1])[$orderBy] ?? '') === $lastVal) {
                $keep--;
            }

            if ($keep === 0) {
                // A whole page shares ONE orderBy value (>= PAGE_SIZE tied rows) —
                // unreachable for marks/results (a student has far fewer docs). Keep
                // the page and advance exclusively to avoid an infinite loop; log it.
                foreach ($page as $r) $all[] = $r;
                $startAfter = $lastVal;
                log_message('error',
                    "Exam_read::_queryAll — {$collection} page of " . self::PAGE_SIZE
                    . " rows all share {$orderBy}={$lastVal}; advancing may skip ties (unexpected at this scale).");
            } else {
                for ($i = 0; $i < $keep; $i++) $all[] = $page[$i];
                $startAfter = (string) ($this->docData($page[$keep - 1])[$orderBy] ?? '');
            }

            if (count($all) >= self::HARD_CEILING) {    // defensive absolute stop
                $this->_warn_if_truncated($all, self::HARD_CEILING, "_queryAll({$collection})");
                break;
            }
        } while (true);

        return $all;
    }

    /**
     * Reconstruct the LEGACY RTDB marks entry from a canonical `marks` doc:
     *   { <ComponentName>:value, …, Total, Absent, SavedAt, SavedBy }
     * Component values are emitted in canonical componentMarks order (=
     * examTemplates order); consumers index components by Name, so order is
     * display-faithful either way.
     */
    private function marksEntry(array $d): array
    {
        $entry = [];
        $comps = is_array($d['componentMarks'] ?? null) ? $d['componentMarks'] : [];
        foreach ($comps as $cm) {
            if (!is_array($cm)) continue;
            $cn = (string) ($cm['name'] ?? '');
            if ($cn === '') continue;
            $entry[$cn] = $cm['value'] ?? 0;              // preserve stored value type
        }
        $entry['Total']  = $d['total'] ?? 0;
        $entry['Absent'] = !empty($d['absent']);
        if (isset($d['savedAt'])) $entry['SavedAt'] = $d['savedAt'];
        if (isset($d['savedBy'])) $entry['SavedBy'] = $d['savedBy'];
        return $entry;
    }

    // ──────────────────────────────────────────────────────────────────
    // C2 — Firestore `results` reader adapter (canonical ResultDoc →
    // legacy RTDB `Results/Computed` Title-case shape). READ-ONLY; these are
    // additive and UNUSED until the reader cutover. No dual-read/bridge.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Canonical `results` for an exam/class/section, reshaped to the LEGACY
     * Computed map `[studentId => entry]` that every admin reader/view consumes.
     * Query: schoolId+examId+className+section (existing composite index).
     */
    public function results_section(string $examId, string $classKey, string $sectionKey): array
    {
        if (!$this->ready || $examId === '' || $classKey === '' || $sectionKey === '') return [];
        $cond = [
            ['schoolId',  '==', $this->schoolId],
            ['examId',    '==', $examId],
            ['className',  '==', $classKey],
            ['section',   '==', $sectionKey],
        ];
        // RESIDUAL-3: paginate ALL docs. results carry one doc per student →
        // studentId is unique within this query (clean per-student walk).
        $rows = $this->_queryAll('results', $cond, 'studentId');
        // HIGH-3 admin preview: `results` now holds ONLY published results. When a
        // section has been computed but not yet published (Draft exam), or was just
        // unpublished, its docs live in `resultsStaging`. This is an ADMIN-internal
        // reader (parent/teacher apps query Firestore `results` directly via the SDK,
        // which stays published-only), so fall back to staging when `results` is
        // empty. Promote/demote move a whole section atomically, so the two never
        // split one section — no double-read.
        if (empty($rows) || !is_array($rows)) {
            $rows = $this->_queryAll('resultsStaging', $cond, 'studentId');
        }
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            $d = $this->docData($r);
            if (!$d) continue;
            $uid = (string) ($d['studentId'] ?? '');
            if ($uid === '') continue;
            $out[$uid] = $this->resultEntry($d);
        }
        return $out;
    }

    /**
     * Single student's canonical result → legacy Computed entry (or [] if none).
     * Query: schoolId+examId+studentId (existing composite index), limit 1.
     */
    public function results_student(string $examId, string $studentId): array
    {
        if (!$this->ready || $examId === '' || $studentId === '') return [];
        $cond = [
            ['schoolId',  '==', $this->schoolId],
            ['examId',    '==', $examId],
            ['studentId', '==', $studentId],
        ];
        $rows = $this->firebase->firestoreQuery('results', $cond, null, 'ASC', 1);
        // HIGH-3 admin preview: fall back to the unpublished staged result when the
        // exam isn't published yet (see results_section for the rationale). Admin-
        // internal reader only; parent/teacher apps read `results` directly.
        if (!is_array($rows) || !$rows) {
            $rows = $this->firebase->firestoreQuery('resultsStaging', $cond, null, 'ASC', 1);
        }
        if (!is_array($rows) || !$rows) return [];
        $d = $this->docData(reset($rows));
        return $d ? $this->resultEntry($d) : [];
    }

    /**
     * Reconstruct the LEGACY `Results/Computed` per-student entry from a
     * canonical `results` ResultDoc. Lowercase → Title-case, byte-faithful to
     * the shape `exam_engine->compute_section()` produced and every reader/view
     * + Parent/Teacher consumer expects.
     *
     * CC-8 (critical): `percentage` is passed through verbatim — a stored `null`
     * (fully-absent student / absent subject) stays `null`, NEVER coerced to 0,
     * so `compute_cumulative` keeps excluding it from numerator AND denominator.
     */
    private function resultEntry(array $d): array
    {
        $subjects = [];
        $subs = is_array($d['subjects'] ?? null) ? $d['subjects'] : [];
        foreach ($subs as $name => $s) {
            if (!is_array($s)) continue;
            $subjects[(string) $name] = [
                'Total'      => $s['total']      ?? null,    // null preserved (absent subject; engine emits null)
                'MaxMarks'   => $s['maxMarks']   ?? 0,
                'Percentage' => $s['percentage'] ?? null,    // null preserved (absent subject)
                'Grade'      => $s['grade']      ?? '',
                'Absent'     => !empty($s['absent']),
                'PassFail'   => (string) ($s['passFail'] ?? ''),
            ];
        }
        return [
            'TotalMarks' => $d['totalMarks'] ?? 0,
            'MaxMarks'   => $d['maxMarks']   ?? 0,
            'Percentage' => $d['percentage'] ?? null,        // null preserved (CC-8 fully-absent)
            'Grade'      => $d['grade']      ?? '',
            'PassFail'   => (string) ($d['passFail'] ?? ''),
            'Rank'       => $d['rank']       ?? 0,
            'Absent'     => !empty($d['absent']),
            'ComputedAt' => $d['computedAt'] ?? null,
            'Subjects'   => $subjects,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // PHASE B — Firestore `examTemplates` reader adapter (canonical → legacy
    // RTDB `Results/Templates` shape {Components:[{Name,MaxMarks}], TotalMaxMarks,
    // CreatedAt, CreatedBy}). READ-ONLY. No dual-read/bridge.
    // ──────────────────────────────────────────────────────────────────

    /** All subject templates for an exam/class/section → [subject => legacyTemplate]. */
    public function templates_section(string $examId, string $classKey, string $sectionKey): array
    {
        if (!$this->ready || $examId === '' || $classKey === '' || $sectionKey === '') return [];
        $rows = $this->firebase->firestoreQuery('examTemplates', [
            ['schoolId',  '==', $this->schoolId],
            ['examId',    '==', $examId],
            ['className',  '==', $classKey],
            ['section',   '==', $sectionKey],
        ], null, 'ASC', 1000);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            $d = $this->docData($r);
            if (!$d) continue;
            $subj = (string) ($d['subject'] ?? '');
            if ($subj === '') continue;
            $out[$subj] = $this->templateEntry($d);
        }
        return $out;
    }

    /** Single subject template (legacy shape) or null. Point-read by canonical doc-id. */
    public function template_subject(string $examId, string $classKey, string $sectionKey, string $subject): ?array
    {
        if (!$this->ready || $examId === '' || $classKey === '' || $sectionKey === '' || $subject === '') return null;
        $d = $this->firebase->firestoreGet('examTemplates', $this->tplDocId($examId, $classKey, $sectionKey, $subject));
        return (is_array($d) && $d) ? $this->templateEntry($d) : null;
    }

    /** Canonical examTemplates doc-id (matches Exam_result_store::templateDocId + backfill). */
    private function tplDocId(string $examId, string $class, string $section, string $subject): string
    {
        return "{$this->schoolId}_{$examId}_" . Firestore_service::idToken($class)
            . '_' . Firestore_service::idToken($section) . '_' . Firestore_service::idToken($subject);
    }

    /** Reconstruct legacy RTDB template entry from a canonical examTemplates doc. */
    private function templateEntry(array $d): array
    {
        $comps = [];
        foreach (($d['components'] ?? []) as $c) {
            if (!is_array($c)) continue;
            $comps[] = ['Name' => (string) ($c['name'] ?? ''), 'MaxMarks' => (int) ($c['maxMarks'] ?? 0)];
        }
        return [
            'Components'    => $comps,                              // ordered list (numeric keys), as RTDB
            'TotalMaxMarks' => (int) ($d['totalMaxMarks'] ?? 0),
            'CreatedAt'     => $d['createdAt'] ?? null,
            'CreatedBy'     => (string) ($d['createdBy'] ?? ''),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // CUMULATIVE — Firestore `cumulativeConfig` + `cumulative` reader adapter
    // (canonical lowercase → legacy Title-case). READ-ONLY.
    // ──────────────────────────────────────────────────────────────────

    /** cumulativeConfig → legacy {Exams:{examId:{Weight,Label}}, TotalWeight} or []. */
    public function cumulative_config(): array
    {
        if (!$this->ready) return [];
        $d = $this->firebase->firestoreGet('cumulativeConfig', "{$this->schoolId}_{$this->session}");
        if (!is_array($d) || empty($d)) return [];
        $exams = [];
        foreach (($d['exams'] ?? []) as $eid => $e) {
            if (!is_array($e)) continue;
            $exams[(string) $eid] = ['Weight' => (int) ($e['weight'] ?? 0), 'Label' => (string) ($e['label'] ?? $eid)];
        }
        return ['Exams' => $exams, 'TotalWeight' => (int) ($d['totalWeight'] ?? 0)];
    }

    /** All cumulative student docs for a class/section → [studentId => legacyEntry]. */
    public function cumulative_section(string $classKey, string $sectionKey): array
    {
        if (!$this->ready || $classKey === '' || $sectionKey === '') return [];
        $rows = $this->firebase->firestoreQuery('cumulative', [
            ['schoolId', '==', $this->schoolId],
            ['session',  '==', $this->session],
            ['className', '==', $classKey],
            ['section',  '==', $sectionKey],
        ], null, 'ASC', 1000);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            $d = $this->docData($r);
            if (!$d) continue;
            $uid = (string) ($d['studentId'] ?? '');
            if ($uid === '') continue;
            $out[$uid] = $this->cumulativeEntry($d);
        }
        return $out;
    }

    /** TRUE if the section's cumulative is stale (per-doc `stale` flag; uniform per section). */
    public function cumulative_stale(string $classKey, string $sectionKey): bool
    {
        if (!$this->ready || $classKey === '' || $sectionKey === '') return false;
        $rows = $this->firebase->firestoreQuery('cumulative', [
            ['schoolId', '==', $this->schoolId],
            ['session',  '==', $this->session],
            ['className', '==', $classKey],
            ['section',  '==', $sectionKey],
        ], null, 'ASC', 1);
        if (!is_array($rows) || !$rows) return false;
        $d = $this->docData(reset($rows));
        return is_array($d) && !empty($d['stale']);
    }

    /** Reconstruct legacy cumulative entry from a canonical `cumulative` doc. */
    private function cumulativeEntry(array $d): array
    {
        $subjects = [];
        foreach (($d['subjects'] ?? []) as $sj => $s) {
            if (!is_array($s)) continue;
            $subjects[(string) $sj] = [
                'WeightedScore' => $s['weightedScore'] ?? null,   // null preserved (CC-5)
                'Grade'         => (string) ($s['grade'] ?? ''),
                'PassFail'      => (string) ($s['passFail'] ?? ''),
                'Absent'        => !empty($s['absent']),
            ];
        }
        return [
            'WeightedTotal' => $d['weightedTotal'] ?? null,        // null preserved (CC-5)
            'Grade'         => (string) ($d['grade'] ?? ''),
            'PassFail'      => (string) ($d['passFail'] ?? ''),
            'Absent'        => !empty($d['absent']),
            'Subjects'      => $subjects,
            'ComputedAt'    => $d['computedAt'] ?? null,
            'ExamsCovered'  => (int) ($d['examsCovered'] ?? 0),
            'TotalExams'    => (int) ($d['totalExams'] ?? 0),
            'IsPartial'     => !empty($d['isPartial']),
            'Rank'          => $d['rank'] ?? 'NR',
        ];
    }

    /** [ [className, section], … ] for delete-cascade enumeration. */
    public function sections(string $examId): array
    {
        if (!$this->ready || $examId === '') return [];
        $docs = $this->firebase->firestoreQuery('examSchedule',
            [['schoolId', '==', $this->schoolId], ['examId', '==', $examId]], null, 'ASC', 500);
        if (!is_array($docs)) return [];
        $out = [];
        foreach ($docs as $r) {
            $d = $r['data'] ?? $r;
            if (is_array($d) && !empty($d['className']) && !empty($d['section'])) {
                $out[] = [(string) $d['className'], (string) $d['section']];
            }
        }
        return $out;
    }
}
