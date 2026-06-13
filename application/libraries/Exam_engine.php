<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Exam_engine — Single source of truth for exam/result logic.
 *
 * Eliminates duplicate code across Exam.php, Result.php, and Examination.php.
 * All grading thresholds, ranking, class structure, and subject resolution
 * live here. Controllers call $this->exam_engine->method().
 *
 * Usage:
 *   $this->load->library('exam_engine');
 *   $this->exam_engine->init($this->firebase, $this->school_name, $this->session_year);
 *   $grade = $this->exam_engine->compute_grade(85.5, 'Percentage');
 */
class Exam_engine
{
    /** @var object Firebase library instance */
    private $firebase;

    /** @var string SCH_XXXXXX */
    private $school;

    /** @var string e.g. "2025-2026" */
    private $year;

    /** @var array Cached class structure for the current request */
    private $_structure_cache;

    /** @var array Cached active exams for the current request */
    private $_exams_cache;

    // =========================================================================
    //  INITIALISATION
    // =========================================================================

    /**
     * Bind a Firebase instance and school/session context.
     * Must be called once before any other method.
     */
    public function init($firebase, string $school, string $year): self
    {
        $this->firebase        = $firebase;
        $this->school          = $school;
        $this->year            = $year;
        $this->_structure_cache = null;
        $this->_exams_cache     = null;
        return $this;
    }

    // =========================================================================
    //  GRADING ENGINE
    // =========================================================================

    /**
     * Compute letter grade from percentage.
     *
     * This is the SINGLE source of truth. The JS mirror in marks_sheet.php
     * must be updated whenever these thresholds change.
     *
     * @param float  $pct   0–100
     * @param string $scale One of: Percentage, A-F Grades, O-E Grades, 10-Point, Pass/Fail
     * @return string Grade label
     */
    public function compute_grade(float $pct, string $scale): string
    {
        switch ($scale) {
            case 'Percentage':
                if ($pct >= 90) return 'A+';
                if ($pct >= 80) return 'A';
                if ($pct >= 70) return 'B+';
                if ($pct >= 60) return 'B';
                if ($pct >= 50) return 'C';
                if ($pct >= 33) return 'D';
                return 'F';

            case 'A-F Grades':
                if ($pct >= 90) return 'A';
                if ($pct >= 80) return 'B';
                if ($pct >= 70) return 'C';
                if ($pct >= 60) return 'D';
                if ($pct >= 50) return 'E';
                return 'F';

            case 'O-E Grades':
                if ($pct >= 91) return 'O';
                if ($pct >= 81) return 'E1';
                if ($pct >= 71) return 'E2';
                if ($pct >= 61) return 'B1';
                if ($pct >= 51) return 'B2';
                if ($pct >= 41) return 'C1';
                if ($pct >= 33) return 'C2';
                return 'D';

            case '10-Point':
                if ($pct >= 91) return '10';
                if ($pct >= 81) return '9';
                if ($pct >= 71) return '8';
                if ($pct >= 61) return '7';
                if ($pct >= 51) return '6';
                if ($pct >= 41) return '5';
                if ($pct >= 33) return '4';
                return 'F';

            case 'Pass/Fail':
                return '';

            default:
                return '';
        }
    }

    /**
     * Determine Pass or Fail.
     *
     * @param float $pct        0–100
     * @param int   $passingPct Minimum percentage to pass (e.g. 33)
     * @return string 'Pass' or 'Fail'
     */
    public function compute_pass_fail(float $pct, int $passingPct): string
    {
        return $pct >= $passingPct ? 'Pass' : 'Fail';
    }

    // =========================================================================
    //  RANKING
    // =========================================================================

    /**
     * Assign competition ranks (1,1,3 — not 1,1,2) to an array of items.
     *
     * The array MUST already be sorted descending by the score field.
     * Each item receives a 'rank' key (lowercase) added in place.
     *
     * @param array  $items    Sorted desc. Each item must have [$scoreField].
     * @param string $scoreField Key name holding the numeric score (default 'percentage').
     * @return array Same items with 'rank' added.
     */
    public function assign_ranks(array $items, string $scoreField = 'percentage'): array
    {
        $rank     = 0;
        $prevVal  = null;
        $prevRank = 0;

        foreach ($items as $i => &$item) {
            $rank++;
            $val = (float) ($item[$scoreField] ?? 0);
            if ($val === $prevVal) {
                $item['rank'] = $prevRank;
            } else {
                $item['rank'] = $rank;
                $prevRank     = $rank;
            }
            $prevVal = $val;
        }
        unset($item);

        return $items;
    }

    /**
     * Assign competition ranks to an associative array (keyed by userId).
     *
     * Same algorithm as assign_ranks() but works on [uid => data] maps
     * and writes an uppercase 'Rank' key (matching Firebase Computed schema).
     *
     * The array MUST already be sorted descending by $scoreField.
     *
     * @param array  &$map        Associative [uid => data]. Modified by reference.
     * @param string $scoreField  Key name holding the numeric score (default 'Percentage').
     */
    public function assign_ranks_assoc(array &$map, string $scoreField = 'Percentage'): void
    {
        $rank     = 0;
        $prevVal  = null;
        $prevRank = 0;

        foreach ($map as $uid => &$res) {
            $rank++;
            $val = (float) ($res[$scoreField] ?? 0);
            if ($val === $prevVal) {
                $res['Rank'] = $prevRank;
            } else {
                $res['Rank'] = $rank;
                $prevRank    = $rank;
            }
            $prevVal = $val;
        }
        unset($res);
    }

    // =========================================================================
    //  SECTION RESULT COMPUTATION (single source of truth — CC-8 policy)
    // =========================================================================

    /**
     * Compute the per-student result map for one exam/class/section.
     *
     * SINGLE SOURCE OF TRUTH for the CC-8 absent policy, shared by
     * Result.php::compute_results and Examination.php::_compute_section_results
     * (Phase 1 convergence). PURE and STORAGE-AGNOSTIC: it takes the already
     * loaded templates + marks and returns the ranked result map. It performs
     * NO I/O — no RTDB, no Firestore — so it adds no storage dependency and is
     * reusable by any future Firestore-first caller without modification.
     *
     * CC-8 policy: an absent (AB) paper is excluded from the percentage
     * denominator and from overall pass/fail (kept visibly 'AB'); a fully-absent
     * student is AB overall (Percentage=null, Grade='AB', PassFail='AB').
     *
     * @param array  $templatesNode [subject => ['TotalMaxMarks'=>int, ...]]
     * @param array  $allMarksNode  [subject => [uid => ['Total'=>?, 'Absent'=>?]]]
     * @param string $scale         Grading scale (e.g. 'Percentage')
     * @param int    $passingPct    Passing percentage threshold
     * @return array [uid => ['TotalMarks','MaxMarks','Percentage','Grade',
     *                        'PassFail','Absent','Subjects','ComputedAt','Rank']]
     */
    public function compute_section(array $templatesNode, array $allMarksNode, string $scale, int $passingPct): array
    {
        // Collect unique student IDs across all subjects.
        $allUserIds = [];
        foreach ($allMarksNode as $stuMarks) {
            if (is_array($stuMarks)) {
                foreach (array_keys($stuMarks) as $uid) {
                    $allUserIds[$uid] = true;
                }
            }
        }
        $allUserIds = array_keys($allUserIds);

        $studentResults = [];
        foreach ($allUserIds as $uid) {
            $totalMarks = 0;
            $maxMarks   = 0;
            $subjects   = [];
            $allPass    = true;
            $attempted  = 0;

            foreach ($templatesNode as $subj => $tmpl) {
                if (!is_array($tmpl)) continue;
                $subjMax  = (int) ($tmpl['TotalMaxMarks'] ?? 0);
                $stuMarks = $allMarksNode[$subj][$uid] ?? [];

                // CC-8: an absent (AB) paper is NOT zero and does NOT fail the
                // student. It is excluded from the percentage denominator and
                // from the overall pass/fail, but stays visibly 'AB'.
                if (!empty($stuMarks['Absent'])) {
                    $subjects[$subj] = [
                        'Total'      => null,
                        'MaxMarks'   => $subjMax,
                        'Percentage' => null,
                        'Grade'      => 'AB',
                        'PassFail'   => 'AB',
                        'Absent'     => true,
                    ];
                    continue;
                }

                $subjTotal = (int) ($stuMarks['Total'] ?? 0);
                $subjPct   = $subjMax > 0 ? ($subjTotal / $subjMax * 100) : 0;
                $subjPass  = $this->compute_pass_fail($subjPct, $passingPct);

                if ($subjPass === 'Fail') $allPass = false;

                $subjects[$subj] = [
                    'Total'      => $subjTotal,
                    'MaxMarks'   => $subjMax,
                    'Percentage' => round($subjPct, 2),
                    'Grade'      => $this->compute_grade($subjPct, $scale),
                    'PassFail'   => $subjPass,
                    'Absent'     => false,
                ];

                $totalMarks += $subjTotal;
                $maxMarks   += $subjMax;
                $attempted++;
            }

            // CC-8: percentage + pass/fail derive from attempted subjects only.
            // Fully-absent student (no attempted papers) → AB overall: no 0%,
            // no Fail, no new status beyond AB.
            if ($attempted === 0 || $maxMarks === 0) {
                $overallPct   = null;
                $overallGrade = 'AB';
                $overallPass  = 'AB';
                $fullyAbsent  = true;
            } else {
                $overallPct   = $totalMarks / $maxMarks * 100;
                $overallGrade = $this->compute_grade($overallPct, $scale);
                $overallPass  = $allPass ? $this->compute_pass_fail($overallPct, $passingPct) : 'Fail';
                $fullyAbsent  = false;
            }

            $studentResults[$uid] = [
                'TotalMarks' => $totalMarks,
                'MaxMarks'   => $maxMarks,
                'Percentage' => $overallPct === null ? null : round($overallPct, 2),
                'Grade'      => $overallGrade,
                'PassFail'   => $overallPass,
                'Absent'     => $fullyAbsent,
                'Subjects'   => $subjects,
                'ComputedAt' => (int) round(microtime(true) * 1000),
            ];
        }

        // Sort by Percentage desc → assign competition ranks (1,1,3).
        uasort($studentResults, fn($a, $b) => $b['Percentage'] <=> $a['Percentage']);
        $this->assign_ranks_assoc($studentResults, 'Percentage');

        return $studentResults;
    }

    // =========================================================================
    //  CLASS / SECTION STRUCTURE
    // =========================================================================

    /**
     * Build [classKey => [sectionLetters]] from the session root using shallow_get.
     *
     * Example return:
     *   ['Class 9th' => ['A','B'], 'Class 10th' => ['A']]
     *
     * Result is cached per-request so repeated calls cost zero Firebase reads.
     *
     * @return array
     */
    public function get_class_structure(): array
    {
        if ($this->_structure_cache !== null) {
            return $this->_structure_cache;
        }

        // PERF/Firestore-only: ONE query on the canonical `sections` collection
        // (schoolId + session) replaces the former 1+N RTDB shallow_get (one read
        // per class) — ~14x faster (≈10s → ≈0.7s) and byte-identical structure.
        $structure = [];
        $rows = $this->firebase->firestoreQuery('sections',
            [['schoolId', '==', $this->school], ['session', '==', $this->year]], null, 'ASC', 2000);
        if (is_array($rows)) {
            $byClass = [];
            foreach ($rows as $r) {
                $d   = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                $ck  = trim((string) ($d['className'] ?? ''));
                $sec = trim((string) ($d['section'] ?? ''));
                if ($ck === '' || $sec === '') continue;
                if (strpos($ck, 'Class ') !== 0) $ck = 'Class ' . $ck;       // normalize "8th" → "Class 8th"
                $sec = preg_replace('/^Section\s+/', '', $sec);              // store the letter only (legacy shape)
                $byClass[$ck][$sec] = true;                                  // dedupe sections per class
            }
            foreach ($byClass as $ck => $secs) {
                $letters = array_keys($secs);
                sort($letters);
                $structure[$ck] = $letters;
            }
        }

        ksort($structure);
        $this->_structure_cache = $structure;
        return $structure;
    }

    // =========================================================================
    //  ACTIVE EXAMS
    // =========================================================================

    /**
     * Load non-Draft exams for the current session, sorted by StartDate ascending.
     *
     * Each exam has an 'id' key prepended.
     * Result is cached per-request.
     *
     * @return array
     */
    public function get_active_exams(): array
    {
        if ($this->_exams_cache !== null) {
            return $this->_exams_cache;
        }

        // EXAM-DEF-FS-CUTOVER Phase 2A: exam list from Firestore via adapter
        // (legacy-shaped; StartDate stays d-m-Y so the existing sort is unchanged).
        $CI =& get_instance();
        $CI->load->library('Exam_read', null, 'exam_read');
        $CI->exam_read->init($this->firebase, $this->school, $this->year);
        $raw   = $CI->exam_read->list_exams();
        $exams = [];
        foreach ($raw as $id => $e) {
            if ($id === 'Count' || !is_array($e)) continue;
            if (($e['Status'] ?? '') === 'Draft') continue;
            $exams[] = array_merge(['id' => $id], $e);
        }
        usort($exams, fn($a, $b) => ($a['StartDate'] ?? '') <=> ($b['StartDate'] ?? ''));

        $this->_exams_cache = $exams;
        return $exams;
    }

    // =========================================================================
    //  STUDENT ROSTER
    // =========================================================================

    /**
     * Get student names from the class roster — Firestore-only (R2 migration).
     *
     * Replaces the legacy `Schools/{school}/{year}/{class}/{section}/Students/List`
     * RTDB read with a Firestore `students` query via Roster_helper.
     *
     * The output contract is unchanged: `[userId => name]` flat string-keyed
     * map. Every caller (Result.php × 2, Examination.php × 4) uses it as a
     * lookup (`$roster[$uid] ?? $uid`) and overrides ordering with its own
     * `sort($userIds)` or driver iteration, so the helper's RollNo-then-name
     * sort doesn't change observable behaviour at any consumer.
     *
     * Resolution path:
     *   1. Prefer the controller's `$CI->roster` (set by MY_Controller for
     *      every per-school request).
     *   2. Fall back to a direct `firestoreQuery` mirroring Roster_helper's
     *      shape, for CLI / cron / non-MY_Controller call paths.
     *
     * @param string $classKey   e.g. "Class 9th"
     * @param string $sectionKey e.g. "Section A"
     * @return array<string, string> [userId => name]
     */
    public function get_student_names(string $classKey, string $sectionKey): array
    {
        try {
            // No `=&` — get_instance() returns an object (handle), and
            // PHP 8 emits "Only variables should be assigned by reference"
            // for the `=& fn()` form even though CI documents it.
            $CI = get_instance();
            if ($CI !== null && isset($CI->roster) && method_exists($CI->roster, 'for_class')) {
                $roster = $CI->roster->for_class($classKey, $sectionKey);
                $names = [];
                foreach ($roster as $uid => $fields) {
                    $names[$uid] = is_array($fields)
                        ? (string) ($fields['Name'] ?? $uid)
                        : (string) $uid;
                }
                return $names;
            }
        } catch (\Exception $e) {
            log_message('error', 'Exam_engine::get_student_names — CI roster path failed: ' . $e->getMessage());
        }

        // Fallback — direct Firestore query (same shape as Roster_helper).
        try {
            $ck = ($classKey !== '' && stripos($classKey, 'Class ') !== 0)   ? "Class {$classKey}"   : $classKey;
            $sk = ($sectionKey !== '' && stripos($sectionKey, 'Section ') !== 0) ? "Section {$sectionKey}" : $sectionKey;
            if ($ck === '' || $sk === '') return [];

            $rows = $this->firebase->firestoreQuery('students', [
                ['schoolId',  '==', $this->school],
                ['className', '==', $ck],
                ['section',   '==', $sk],
                ['status',    '==', 'Active'],
            ]);
            if (!is_array($rows)) return [];

            $names = [];
            foreach ($rows as $entry) {
                $data = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($data)) continue;
                $uid  = (string) ($data['userId'] ?? $data['studentId'] ?? '');
                if ($uid === '' && is_array($entry) && isset($entry['id'])) {
                    // Doc id is `{schoolId}_{userId}` — strip the exact prefix.
                    $prefix = $this->school . '_';
                    $uid = (strncmp($entry['id'], $prefix, strlen($prefix)) === 0)
                        ? substr($entry['id'], strlen($prefix))
                        : $entry['id'];
                }
                if ($uid === '') continue;
                $names[$uid] = (string) ($data['name'] ?? $data['Name'] ?? $uid);
            }
            return $names;
        } catch (\Exception $e) {
            log_message('error', 'Exam_engine::get_student_names — Firestore fallback failed: ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================================
    //  SUBJECT LIST
    // =========================================================================

    /**
     * Get subject names for a class from the school-level Subject_list node.
     *
     * Resolves the class key to the correct Subject_list index:
     *   "Class 9th"    → Subject_list/9
     *   "Class Nursery" → Subject_list/Nursery
     *   "Class LKG"     → Subject_list/LKG
     *
     * @param string $classKey e.g. "Class 9th"
     * @return array ['Mathematics', 'English', ...]
     */
    public function get_subject_list(string $classKey): array
    {
        $raw = strtolower($classKey);

        if (strpos($raw, 'nursery') !== false)                                   $listKey = 'Nursery';
        elseif (strpos($raw, 'lkg') !== false)                                   $listKey = 'LKG';
        elseif (strpos($raw, 'ukg') !== false)                                   $listKey = 'UKG';
        elseif (strpos($raw, 'playgroup') !== false || strpos($raw, 'play') !== false) $listKey = 'Playgroup';
        elseif (preg_match('/\d+/', $classKey, $m))                              $listKey = (int) $m[0];
        else return [];

        $node  = $this->firebase->get("Schools/{$this->school}/Subject_list/{$listKey}") ?? [];
        $names = [];
        if (is_array($node)) {
            foreach ($node as $code => $data) {
                if ($code === 'pattern_type') continue;
                if (is_array($data)) {
                    $subName = $data['subject_name'] ?? $data['name'] ?? '';
                    if ($subName !== '') $names[] = $subName;
                }
            }
        }
        return $names;
    }

    // =========================================================================
    //  GRADE THRESHOLDS (single source of truth for PHP + JS)
    // =========================================================================

    /**
     * Return all grading thresholds as a structured array.
     *
     * Each scale maps to an ordered list of [minPct, grade] pairs (descending).
     * The JS mirror in marks_sheet.php consumes this via json_encode() so that
     * threshold changes in PHP automatically propagate to the client.
     *
     * @return array
     */
    public function get_grade_thresholds(): array
    {
        return [
            'Percentage' => [
                [90, 'A+'], [80, 'A'], [70, 'B+'], [60, 'B'], [50, 'C'], [33, 'D'], [0, 'F'],
            ],
            'A-F Grades' => [
                [90, 'A'], [80, 'B'], [70, 'C'], [60, 'D'], [50, 'E'], [0, 'F'],
            ],
            'O-E Grades' => [
                [91, 'O'], [81, 'E1'], [71, 'E2'], [61, 'B1'], [51, 'B2'], [41, 'C1'], [33, 'C2'], [0, 'D'],
            ],
            '10-Point' => [
                [91, '10'], [81, '9'], [71, '8'], [61, '7'], [51, '6'], [41, '5'], [33, '4'], [0, 'F'],
            ],
            'Pass/Fail' => [],
        ];
    }
}
