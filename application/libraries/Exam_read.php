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
