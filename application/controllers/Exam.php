<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Exam controller — full ERP-grade redesign
 *
 * Firebase structure:
 *   Central: Schools/{school}/{year}/Exams/{EXM_20260313_143052_a7f3b2}/...
 *   Per-section copy: Schools/{school}/{year}/Class 9th/Section A/Exams/{examId}/{date}/{subject}
 *
 * Methods:
 *   index()          — list all exams
 *   create()         — GET: form; POST AJAX: save
 *   view($id)        — exam detail page
 *   delete($id)      — cleanup per-section + delete central, redirect
 *   update_status()  — POST AJAX: update Status field
 *   get_subjects()   — GET AJAX: subjects for a class
 *   manage_exam()    — backward-compat redirect to index
 *
 * RBAC:
 *   Super Admin / Admin — full access
 *   Teacher             — index (read-only), view, get_subjects
 */
class Exam extends MY_Controller
{
    const ALLOWED_TYPES    = ['Mid-Term', 'Final Term', 'Unit Test', 'Weekly Test', 'Pre-Board', 'Annual'];
    const ALLOWED_SCALES   = ['Percentage', 'A-F Grades', 'O-E Grades', '10-Point', 'Pass/Fail'];
    const ALLOWED_STATUSES = ['Draft', 'Published', 'Completed'];

    /** Roles allowed to create, edit, delete exams and change status. */
    private const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator'];

    /** Roles allowed to view exam data. */
    private const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Examinations');
        $this->load->library('exam_engine');
        $this->exam_engine->init($this->firebase, $this->school_name, $this->session_year);
    }

    // ── Backward compatibility ────────────────────────────────────────────
    public function manage_exam()
    {
        redirect('exam');
    }

    // ── index() — Exam list ───────────────────────────────────────────────
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_exams');
        $school = $this->school_name;
        $year   = $this->session_year;

        $raw   = $this->firebase->get("Schools/{$school}/{$year}/Exams") ?? [];
        $exams = [];
        foreach ($raw as $id => $e) {
            if ($id === 'Count' || !is_array($e)) continue;
            $exams[] = array_merge(['id' => $id], $e);
        }
        usort($exams, function ($a, $b) {
            return ($b['CreatedAt'] ?? 0) <=> ($a['CreatedAt'] ?? 0);
        });

        $this->load->view('include/header');
        $this->load->view('exam/index', ['exams' => $exams]);
        $this->load->view('include/footer');
    }

    // ── create() — GET: form; POST AJAX: save ────────────────────────────
    public function create()
    {
        $this->_require_role(self::ADMIN_ROLES, 'create exam');

        $school    = $this->school_name;
        $year      = $this->session_year;
        $structure = $this->exam_engine->get_class_structure();

        if ($this->input->method() === 'post') {
            header('Content-Type: application/json');

            // — Exam name
            $name = trim((string) $this->input->post('examName'));
            if (!preg_match('/^[\w\s\-\.]{2,80}$/u', $name)) {
                $this->json_error('Invalid exam name. Use letters, digits, spaces, hyphens, or dots (2–80 chars).', 400);
            }

            // — Exam type
            $type = trim((string) $this->input->post('examType'));
            if (!in_array($type, self::ALLOWED_TYPES, true)) {
                $this->json_error('Invalid exam type.', 400);
            }

            // — Status
            $status = trim((string) $this->input->post('examStatus'));
            if (!in_array($status, self::ALLOWED_STATUSES, true)) {
                $this->json_error('Invalid exam status.', 400);
            }

            // — Grading scale
            $scale = strip_tags(trim((string) $this->input->post('gradingScale')));
            if (!in_array($scale, self::ALLOWED_SCALES, true)) {
                $this->json_error('Invalid grading scale.', 400);
            }

            // — Passing percent
            $passingPct = (int) $this->input->post('passingPercent');
            if ($passingPct < 1 || $passingPct > 100) {
                $this->json_error('PassingPercent must be 1–100.', 400);
            }

            // — Dates
            $startDt = DateTime::createFromFormat('Y-m-d', trim((string) $this->input->post('startDate')));
            $endDt   = DateTime::createFromFormat('Y-m-d', trim((string) $this->input->post('endDate')));
            if (!$startDt || !$endDt) {
                $this->json_error('Invalid date format.', 400);
            }
            if ($startDt > $endDt) {
                $this->json_error('Start date must not be after end date.', 400);
            }

            // — Instructions
            $instructions = [];
            $idx          = 0;
            foreach (explode("\n", (string) $this->input->post('generalInstructions')) as $line) {
                $c = trim(preg_replace('/^[•\-\*\s]+/', '', $line));
                if ($c !== '') $instructions[$idx++] = $c;
            }

            // — Schedule
            $scheduleJson = (string) $this->input->post('examSchedule');
            if (empty($scheduleJson)) {
                $this->json_error('Exam schedule is empty. Please add at least one row.', 400);
            }
            $scheduleRows = json_decode($scheduleJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($scheduleRows)) {
                $this->json_error('Invalid schedule data.', 400);
            }
            if (empty($scheduleRows)) {
                $this->json_error('Exam schedule has no entries. Please add at least one row.', 400);
            }

            // — Generate EXM ID
            $examId = $this->generate_exam_id();

            // — Save central metadata (without Schedule key — added incrementally below)
            $examMeta = [
                'Name'                => $name,
                'Type'                => $type,
                'Status'              => $status,
                'GradingScale'        => $scale,
                'PassingPercent'      => $passingPct,
                'StartDate'           => $startDt->format('d-m-Y'),
                'EndDate'             => $endDt->format('d-m-Y'),
                'GeneralInstructions' => $instructions ?: (object)[],
                'CreatedAt'           => (int) round(microtime(true) * 1000),
                'CreatedBy'           => $this->admin_id ?? '',
            ];
            $this->firebase->set("Schools/{$school}/{$year}/Exams/{$examId}", $examMeta);

            // — Process schedule rows
            $savedCount = 0;
            $skippedRows = []; // H-08 FIX: Track skipped rows to report back to user
            $rowIndex = 0;

            foreach ($scheduleRows as $row) {
                $rowIndex++;
                if (!is_array($row)) continue;

                $className  = trim((string) ($row['className']   ?? ''));
                $subject    = strip_tags(trim((string) ($row['subject']     ?? '')));
                $startTime  = trim((string) ($row['startTime']  ?? ''));
                $endTime    = trim((string) ($row['endTime']    ?? ''));
                $totalMarks = is_numeric($row['totalMarks']  ?? '') ? (int) $row['totalMarks'] : null;
                $passMks    = is_numeric($row['passingMarks'] ?? '') ? (int) $row['passingMarks'] : null;
                $dateRaw    = trim((string) ($row['date'] ?? ''));

                if (!$className || !$subject || !$startTime || !$endTime || $totalMarks === null || !$dateRaw) {
                    $skippedRows[] = "Row {$rowIndex}: Missing required fields (class/subject/time/marks/date).";
                    log_message('error', 'Exam::create — incomplete row skipped: ' . json_encode($row));
                    continue;
                }

                $dateDt = DateTime::createFromFormat('d/m/Y', $dateRaw);
                if (!$dateDt) {
                    $skippedRows[] = "Row {$rowIndex} ({$subject}): Invalid date format '{$dateRaw}' — expected DD/MM/YYYY.";
                    log_message('error', "Exam::create — bad date [{$dateRaw}], skipping.");
                    continue;
                }
                $dateKey = $dateDt->format('d-m-Y');

                $stDt = DateTime::createFromFormat('H:i', $startTime);
                $etDt = DateTime::createFromFormat('H:i', $endTime);
                if (!$stDt || !$etDt) {
                    $skippedRows[] = "Row {$rowIndex} ({$subject}): Invalid time format '{$startTime}-{$endTime}'.";
                    log_message('error', "Exam::create — bad time [{$startTime}-{$endTime}], skipping.");
                    continue;
                }
                $timeStr = $stDt->format('h:iA') . '-' . $etDt->format('h:iA');

                if ($passMks === null) {
                    $passMks = (int) round($totalMarks * $passingPct / 100);
                }

                $entry = [
                    'Time'         => $timeStr,
                    'TotalMarks'   => $totalMarks,
                    'PassingMarks' => $passMks,
                ];

                // Save to each section of the class
                $sections = $structure[$className] ?? [];
                if (empty($sections)) {
                    $skippedRows[] = "Row {$rowIndex} ({$subject}): No sections found for '{$className}'.";
                    log_message('error', "Exam::create — no sections for [{$className}], skipping.");
                    continue;
                }
                foreach ($sections as $sectionLetter) {
                    $sectionKey = "Section {$sectionLetter}";
                    // Central schedule copy
                    $this->firebase->set(
                        "Schools/{$school}/{$year}/Exams/{$examId}/Schedule/{$className}/{$sectionKey}/{$dateKey}/{$subject}",
                        $entry
                    );
                    // Per-section copy
                    $this->firebase->set(
                        "Schools/{$school}/{$year}/{$className}/{$sectionKey}/Exams/{$examId}/{$dateKey}/{$subject}",
                        $entry
                    );
                }
                $savedCount++;
            }

            // H-08 FIX: Include skipped row details so the user knows what failed
            $response = [
                'examId'     => $examId,
                'message'    => "Exam created successfully ({$savedCount} entries saved).",
                'csrf_token' => $this->security->get_csrf_hash(),
            ];
            if (!empty($skippedRows)) {
                $response['warnings'] = $skippedRows;
                $response['message'] .= ' ' . count($skippedRows) . ' row(s) skipped due to validation errors.';
            }
            $this->json_success($response);
            return;
        }

        // GET — build subjects map
        $subjects = [];
        foreach ($structure as $classKey => $sectionLetters) {
            if (empty($sectionLetters)) continue;
            $firstSection       = "Section {$sectionLetters[0]}";
            $subjectsRaw        = $this->firebase->get("Schools/{$school}/{$year}/{$classKey}/{$firstSection}/Subjects") ?? [];
            $subjects[$classKey] = array_keys(is_array($subjectsRaw) ? $subjectsRaw : []);
        }

        $this->load->view('include/header');
        $this->load->view('exam/create', [
            'classNames' => array_keys($structure),
            'subjects'   => $subjects,
        ]);
        $this->load->view('include/footer');
    }

    // ── view($id) ────────────────────────────────────────────────────────
    public function view($id = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'view_exam');
        if (!$id) { redirect('exam'); }

        $school = $this->school_name;
        $year   = $this->session_year;
        $exam   = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$id}");

        if (!$exam || !is_array($exam)) { redirect('exam'); }

        $this->load->view('include/header');
        $this->load->view('exam/view', ['examId' => $id, 'exam' => $exam]);
        $this->load->view('include/footer');
    }

    // ── delete($id) ──────────────────────────────────────────────────────
    public function delete($id = null)
    {
        $this->_require_role(self::ADMIN_ROLES, 'delete exam');
        if (!$id) { redirect('exam'); }

        $school   = $this->school_name;
        $year     = $this->session_year;
        $schedule = $this->firebase->get("Schools/{$school}/{$year}/Exams/{$id}/Schedule") ?? [];

        // Remove per-section copies
        foreach ($schedule as $classKey => $sectionData) {
            if (!is_array($sectionData)) continue;
            foreach (array_keys($sectionData) as $sectionKey) {
                $this->firebase->delete("Schools/{$school}/{$year}/{$classKey}/{$sectionKey}/Exams/{$id}");
            }
        }

        // Delete central record
        $this->firebase->delete("Schools/{$school}/{$year}/Exams/{$id}");

        // Cascade: remove Results nodes (Templates, Marks, Computed) for this exam
        $this->firebase->delete("Schools/{$school}/{$year}/Results/Templates/{$id}");
        $this->firebase->delete("Schools/{$school}/{$year}/Results/Marks/{$id}");
        $this->firebase->delete("Schools/{$school}/{$year}/Results/Computed/{$id}");
        // Remove from CumulativeConfig
        $this->firebase->delete("Schools/{$school}/{$year}/Results/CumulativeConfig/Exams/{$id}");

        // EX-4 FIX: Mark cumulative results as stale since an exam was removed
        // (full re-computation needed — we cannot selectively remove one exam's contribution)
        $cumulativePath = "Schools/{$school}/{$year}/Results/Cumulative";
        $cumulativeKeys = $this->firebase->shallow_get($cumulativePath);
        if (is_array($cumulativeKeys)) {
            foreach (array_keys($cumulativeKeys) as $ck) {
                $sectionKeys = $this->firebase->shallow_get("{$cumulativePath}/{$ck}");
                if (is_array($sectionKeys)) {
                    foreach (array_keys($sectionKeys) as $sk) {
                        $this->firebase->set("{$cumulativePath}/{$ck}/{$sk}/_stale", [
                            'reason' => "Exam {$id} deleted",
                            'deleted_at' => date('c'),
                        ]);
                    }
                }
            }
        }

        redirect('exam');
    }

    // ── update_status() — POST AJAX ──────────────────────────────────────
    public function update_status()
    {
        $this->_require_role(self::ADMIN_ROLES, 'update exam status');
        header('Content-Type: application/json');

        $id     = trim((string) $this->input->post('examId'));
        $status = trim((string) $this->input->post('status'));

        if (!$id || !in_array($status, self::ALLOWED_STATUSES, true)) {
            $this->json_error('Invalid parameters.', 400);
        }

        $school = $this->school_name;
        $year   = $this->session_year;
        $this->firebase->update("Schools/{$school}/{$year}/Exams/{$id}", ['Status' => $status]);
        $this->json_success(['message' => 'Status updated to ' . $status . '.']);
    }

    // ── get_subjects() — GET AJAX ────────────────────────────────────────
    public function get_subjects()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_subjects');
        header('Content-Type: application/json');

        $classKey = trim((string) $this->input->get('class'));

        if (!$classKey) {
            echo json_encode(['subjects' => []]);
            return;
        }

        echo json_encode(['subjects' => $this->exam_engine->get_subject_list($classKey)]);
    }

    // ── Private helpers ──────────────────────────────────────────────────

    /**
     * Generate a collision-safe exam ID without a shared counter.
     *
     * Format: EXM_{YYYYMMDD}_{HHmmss}_{6-char hex}
     * Example: EXM_20260313_143052_a7f3b2
     *
     * - No race condition (no read-increment-write cycle)
     * - Human-readable (date + time visible in the key)
     * - Sortable chronologically in Firebase (lexicographic order)
     * - 6 hex chars of randomness = 16M combinations per second — collision-proof
     * - Backward-compatible: existing EXM0001-style IDs remain valid Firebase keys
     */
    private function generate_exam_id(): string
    {
        $now  = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
        $rand = bin2hex(random_bytes(3)); // 6 hex chars

        return 'EXM_' . $now->format('Ymd_His') . '_' . $rand;
    }
}
