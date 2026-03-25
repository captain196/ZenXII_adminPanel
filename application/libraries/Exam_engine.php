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

        $structure   = [];
        $sessionKeys = $this->firebase->shallow_get("Schools/{$this->school}/{$this->year}");
        if (!is_array($sessionKeys)) {
            $this->_structure_cache = $structure;
            return $structure;
        }

        foreach ($sessionKeys as $classKey) {
            if (strpos($classKey, 'Class ') !== 0) continue;
            $sectionKeys    = $this->firebase->shallow_get(
                "Schools/{$this->school}/{$this->year}/{$classKey}"
            );
            $sectionLetters = [];
            if (is_array($sectionKeys)) {
                foreach ($sectionKeys as $sk) {
                    if (strpos($sk, 'Section ') !== 0) continue;
                    $sectionLetters[] = str_replace('Section ', '', $sk);
                }
            }
            if (!empty($sectionLetters)) {
                sort($sectionLetters);
                $structure[$classKey] = $sectionLetters;
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

        $raw   = $this->firebase->get("Schools/{$this->school}/{$this->year}/Exams") ?? [];
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
     * Get student names from the class roster.
     *
     * Handles both formats:
     *   {userId: "Student Name"}              — simple string
     *   {userId: {Name: "Student Name"}}      — object with Name key
     *
     * @param string $classKey   e.g. "Class 9th"
     * @param string $sectionKey e.g. "Section A"
     * @return array [userId => name]
     */
    public function get_student_names(string $classKey, string $sectionKey): array
    {
        $roster = $this->firebase->get(
            "Schools/{$this->school}/{$this->year}/{$classKey}/{$sectionKey}/Students/List"
        ) ?? [];

        if (!is_array($roster)) return [];

        $names = [];
        foreach ($roster as $uid => $val) {
            if (is_string($val)) {
                $names[$uid] = $val;
            } elseif (is_array($val)) {
                $names[$uid] = $val['Name'] ?? $val['name'] ?? (string) $uid;
            }
        }

        return $names;
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
