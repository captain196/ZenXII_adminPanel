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
        // EXAM-DEF-FS-CUTOVER Phase 2A: exam definitions are read from Firestore
        // (`exams`/`examSchedule`) via the Exam_read adapter (legacy-shaped).
        $this->load->library('Exam_read', null, 'exam_read');
        $this->exam_read->init($this->firebase, $this->school_name, $this->session_year);
        // Phase 2B: Firestore-only exam-definition WRITES via the canonical sync lib.
        $this->load->library('Entity_firestore_sync', null, 'efs');
        $this->efs->init($this->firebase, $this->school_name, $this->session_year);
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

        $raw   = $this->exam_read->list_exams() ?? [];
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
    /**
     * MED-9 — shared schedule-row parser used by create() AND edit_exam().
     * Was ~120 duplicated lines in each. Validates every submitted row, skips
     * invalid ones (with a per-row reason + error log), and accumulates the
     * per-section examSchedule entries. Both callers write IDENTICAL schedule
     * docs from the returned `schedAccum` via efs->syncExamScheduleFull().
     *
     * @return array{schedAccum:array,classesSeen:array,savedCount:int,skippedRows:array}
     */
    private function _parse_schedule_rows(array $scheduleRows, array $structure, int $passingPct): array
    {
        $savedCount  = 0;
        $skippedRows = [];
        $rowIndex    = 0;
        $schedAccum  = []; // [className][sectionKey] => [ subject entry, … ]
        $classesSeen = []; // distinct classes → applicableClasses

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
                log_message('error', 'Exam::_parse_schedule_rows — incomplete row skipped: ' . json_encode($row));
                continue;
            }

            $dateDt = DateTime::createFromFormat('d/m/Y', $dateRaw);
            if (!$dateDt) {
                $skippedRows[] = "Row {$rowIndex} ({$subject}): Invalid date format '{$dateRaw}' — expected DD/MM/YYYY.";
                log_message('error', "Exam::_parse_schedule_rows — bad date [{$dateRaw}], skipping.");
                continue;
            }
            $dateKey = $dateDt->format('d-m-Y');

            $stDt = DateTime::createFromFormat('H:i', $startTime);
            $etDt = DateTime::createFromFormat('H:i', $endTime);
            if (!$stDt || !$etDt) {
                $skippedRows[] = "Row {$rowIndex} ({$subject}): Invalid time format '{$startTime}-{$endTime}'.";
                log_message('error', "Exam::_parse_schedule_rows — bad time [{$startTime}-{$endTime}], skipping.");
                continue;
            }
            // A1-3: per-row end time must be after start time.
            if ($etDt <= $stDt) {
                $skippedRows[] = "Row {$rowIndex} ({$subject}): End time must be after start time ('{$startTime}-{$endTime}').";
                log_message('error', "Exam::_parse_schedule_rows — end<=start time [{$startTime}-{$endTime}], skipping.");
                continue;
            }

            if ($passMks === null) {
                $passMks = (int) round($totalMarks * $passingPct / 100);
            }

            // Save to each section of the class.
            $sections = $structure[$className] ?? [];
            if (empty($sections)) {
                $skippedRows[] = "Row {$rowIndex} ({$subject}): No sections found for '{$className}'.";
                log_message('error', "Exam::_parse_schedule_rows — no sections for [{$className}], skipping.");
                continue;
            }
            $classesSeen[$className] = true;
            foreach ($sections as $sectionLetter) {
                $sectionKey = "Section {$sectionLetter}";
                $schedAccum[$className][$sectionKey][] = [
                    'subjectName'  => $subject,
                    'date'         => $dateKey,
                    'startTime'    => $stDt->format('h:iA'),
                    'endTime'      => $etDt->format('h:iA'),
                    'maxTheory'    => 0,
                    'maxPractical' => 0,
                    'maxTotal'     => $totalMarks,
                    'passingMarks' => $passMks,
                    'room'         => '',
                ];
            }
            $savedCount++;
        }

        return [
            'schedAccum'  => $schedAccum,
            'classesSeen' => $classesSeen,
            'savedCount'  => $savedCount,
            'skippedRows' => $skippedRows,
        ];
    }

    public function create()
    {
        $this->_require_role(self::ADMIN_ROLES, 'create exam');

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

            // — A1-6: duplicate-exam guard. Reject a second exam with the same
            //   Name + Type in this session (prevents double-submit duplicates).
            $existingExams = $this->exam_read->list_exams() ?? [];
            if (is_array($existingExams)) {
                foreach ($existingExams as $exId => $exMeta) {
                    if ($exId === 'Count' || !is_array($exMeta)) continue;
                    if (strcasecmp(trim((string) ($exMeta['Name'] ?? '')), $name) === 0
                        && strcasecmp(trim((string) ($exMeta['Type'] ?? '')), $type) === 0) {
                        $this->json_error("An exam named \"{$name}\" ({$type}) already exists for this session.", 409);
                    }
                }
            }

            // — Generate EXM ID
            $examId = $this->generate_exam_id();

            // — Phase 2B: accumulate the schedule (shared parser — MED-9), then
            //   write Firestore-only after the loop. No RTDB writes.
            $parsed      = $this->_parse_schedule_rows($scheduleRows, $structure, $passingPct);
            $schedAccum  = $parsed['schedAccum'];  // [className][sectionKey] => [ subject entry, … ]
            $classesSeen = $parsed['classesSeen']; // distinct classes → applicableClasses
            $savedCount  = $parsed['savedCount'];
            $skippedRows = $parsed['skippedRows']; // H-08: reported back to the user

            // — Phase 2B: Firestore-only writes (exam meta + per-section schedule).
            $this->efs->syncExam($examId, [
                'examName'            => $name,
                'examType'            => $type,
                'status'              => $status,
                'gradingScale'        => $scale,            // engine vocabulary verbatim
                'passingPercent'      => $passingPct,
                'startDate'           => $startDt->format('Y-m-d'),
                'endDate'             => $endDt->format('Y-m-d'),
                'applicableClasses'   => array_keys($classesSeen),
                'generalInstructions' => $instructions ?: [],
                'createdBy'           => $this->admin_id ?? '',
                // Phase 1 lifecycle: stamp publish audit if created directly as Published.
                'publishedBy'         => ($status === 'Published') ? ($this->admin_id ?? '') : null,
                'publishedAt'         => ($status === 'Published') ? (int) round(microtime(true) * 1000) : null,
                'createdAt'           => (int) round(microtime(true) * 1000),
            ]);
            foreach ($schedAccum as $accClass => $accSections) {
                foreach ($accSections as $accSection => $accSubjects) {
                    $this->efs->syncExamScheduleFull($examId, (string) $accClass, (string) $accSection, $accSubjects);
                }
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

        // GET — build subjects map from the CANONICAL Firestore `subjects`
        // collection (UX-1.4.1.1; NO RTDB). The prior inline read of the RTDB
        // path {class}/{firstSection}/Subjects pointed at an unpopulated node and
        // returned [] for every class, leaving the UX-1.4.1 combobox empty.
        // Shape unchanged: [className => [subjectName, …]].
        $subjects = $this->_build_subjects_map($structure);

        $this->load->view('include/header');
        $this->load->view('exam/create', [
            'classNames' => array_keys($structure),
            'subjects'   => $subjects,
            // UX-1.4: read-only [className => [sectionLetter,…]] for fan-out
            // visibility. Same $structure already used by the POST fan-out;
            // exposed verbatim — no business-logic/serializer/payload impact.
            'sections'   => $structure,
            // UX-2.0.1-B P6-B: read-only holiday dates (Firestore calendarEvents)
            // for auto-sequence/holiday-aware scheduling. No write, no contract impact.
            'holidays'   => $this->_holiday_dates(),
            // UX-2.0.1-B P7-B: brief exam list for the "Clone previous exam" picker (read-only).
            'exams'      => $this->_exam_list_brief(),
        ]);
        $this->load->view('include/footer');
    }

    // ── edit($id) — GET: render Create UI in edit mode (Phase 3.5) ────────
    // Draft-only render of the prefilled form. The POST save is edit_exam().
    public function edit($id = null)
    {
        $this->_require_role(self::ADMIN_ROLES, 'edit exam');
        $id = trim((string) $id);
        if ($id === '') { redirect('exam'); }

        $school = $this->school_name;

        $raw = $this->firebase->firestoreGet('exams', "{$school}_{$id}");
        if (!is_array($raw) || empty($raw)) {
            $this->session->set_flashdata('error', 'Exam not found.');
            redirect('exam');
            return;
        }
        if ((string) ($raw['status'] ?? 'Draft') !== 'Draft') {
            $this->session->set_flashdata('error', 'Only Draft exams can be edited. Unpublish it first.');
            redirect('exam/view/' . urlencode($id));
            return;
        }

        $structure = $this->exam_engine->get_class_structure();
        // UX-1.4.1.1: canonical Firestore subject source (see create()).
        $subjects  = $this->_build_subjects_map($structure);

        $instr = '';
        if (is_array($raw['generalInstructions'] ?? null) && !empty($raw['generalInstructions'])) {
            $instr = implode("\n", array_map(static fn($i) => '• ' . $i, $raw['generalInstructions']));
        }

        $editExam = [
            'id'             => $id,
            'name'           => (string) ($raw['examName']       ?? ''),
            'type'           => (string) ($raw['examType']       ?? ''),
            'scale'          => (string) ($raw['gradingScale']   ?? 'Percentage'),
            'passingPercent' => (int)    ($raw['passingPercent'] ?? 33),
            'startDate'      => (string) ($raw['startDate']      ?? ''), // Y-m-d
            'endDate'        => (string) ($raw['endDate']        ?? ''), // Y-m-d
            'instructions'   => $instr,
            'rows'           => $this->_build_edit_rows($id),
        ];

        $this->load->view('include/header');
        $this->load->view('exam/create', [
            'classNames' => array_keys($structure),
            'subjects'   => $subjects,
            'editExam'   => $editExam,
            // UX-1.4: read-only section map for fan-out visibility (see create()).
            'sections'   => $structure,
            'holidays'   => $this->_holiday_dates(), // UX-2.0.1-B P6-B (see create())
            'exams'      => $this->_exam_list_brief(), // P7-B clone source list
        ]);
        $this->load->view('include/footer');
    }

    /**
     * UX-1.4.1.1 — build [className => [subjectName,…]] from the CANONICAL
     * Firestore `subjects` collection (NO RTDB). One read for the whole school,
     * grouped by the stored `classKey`, then mapped onto the class names from
     * get_class_structure(). This is the same Firestore source the Subjects /
     * SIS / Academic modules use (`subjects` docs: {classKey, name, …}).
     * Output shape matches the legacy build consumed by the create.php combobox.
     */
    private function _build_subjects_map(array $structure): array
    {
        $byKey = [];
        $rows  = $this->fs->schoolWhere('subjects', []);
        foreach ((array) $rows as $row) {
            $d  = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
            $ck = trim((string) ($d['classKey'] ?? ''));
            $nm = trim((string) ($d['name'] ?? $d['subject_name'] ?? ''));
            if ($ck === '' || $nm === '') continue;
            $byKey[$ck][] = $nm;
        }

        $subjects = [];
        foreach (array_keys($structure) as $className) {
            $subjects[(string) $className] = $byKey[$this->_subject_class_key((string) $className)] ?? [];
        }
        return $subjects;
    }

    /** className → Firestore `subjects.classKey` (matches the Subjects module mapping). */
    private function _subject_class_key(string $className): string
    {
        $l = strtolower($className);
        if (strpos($l, 'nursery') !== false)   return 'Nursery';
        if (strpos($l, 'lkg') !== false)       return 'LKG';
        if (strpos($l, 'ukg') !== false)       return 'UKG';
        if (strpos($l, 'playgroup') !== false || strpos($l, 'play') !== false) return 'Playgroup';
        if (preg_match('/\d+/', $className, $m)) return (string) (int) $m[0];
        return '';
    }

    /**
     * UX-2.0.1-B P6-B — holiday dates for this school from the CANONICAL Firestore
     * `calendarEvents` collection (category=holiday). Each event's start_date..end_date
     * is expanded to a flat list of 'Y-m-d' strings. READ-ONLY, Firestore-only, no
     * RTDB; best-effort (returns [] on any failure). Drives client-side
     * auto-sequence / holiday-aware scheduling — no serializer/payload/lifecycle impact.
     */
    private function _holiday_dates(): array
    {
        $out = [];
        try {
            $rows = $this->fs->schoolWhere('calendarEvents', []);
            foreach ((array) $rows as $r) {
                $d   = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                $cat = strtolower((string) ($d['category'] ?? $d['type'] ?? ''));
                if ($cat !== 'holiday') continue;
                if (strtolower((string) ($d['status'] ?? '')) === 'cancelled') continue;
                $s = (string) ($d['start_date'] ?? $d['startDate'] ?? '');
                $e = (string) ($d['end_date']   ?? $d['endDate']   ?? $s);
                if ($s === '') continue;
                try {
                    $cur = new DateTime($s); $end = new DateTime($e !== '' ? $e : $s);
                    $guard = 0;
                    while ($cur <= $end && $guard++ < 370) { $out[$cur->format('Y-m-d')] = true; $cur->modify('+1 day'); }
                } catch (\Throwable $e2) { $out[$s] = true; }
            }
        } catch (\Throwable $e) { /* best-effort: no holidays */ }
        return array_keys($out);
    }

    /**
     * UX-2.0.1-B P7-B — brief exam list for the "Clone previous exam" picker.
     * Read-only; reuses Exam_read::list_exams() (Firestore exams). No writes.
     */
    private function _exam_list_brief(): array
    {
        $out = [];
        try {
            $raw = $this->exam_read->list_exams() ?? [];
            foreach ((array) $raw as $eid => $m) {
                if ($eid === 'Count' || !is_array($m)) continue;
                $out[] = [
                    'id'     => (string) $eid,
                    'name'   => (string) ($m['Name'] ?? ''),
                    'type'   => (string) ($m['Type'] ?? ''),
                    'status' => (string) ($m['Status'] ?? 'Draft'),
                ];
            }
        } catch (\Throwable $e) { /* best-effort: empty list */ }
        return $out;
    }

    /**
     * UX-2.0.1-B P7-B — datesheet of an existing exam as JSON, for client-side
     * cloning into the builder. READ-ONLY (no write, no lifecycle/serializer/
     * Firestore-contract change). Returns the same flat row shape the builder
     * hydrates from (className, subject, date, startTime, endTime, totalMarks,
     * passingMarks) + grading meta. Firestore-canonical via Exam_read.
     */
    public function datesheet_json($id = null)
    {
        $this->_require_role(self::ADMIN_ROLES, 'clone exam datesheet');
        header('Content-Type: application/json');
        $id = trim((string) $id);
        if ($id === '') { echo json_encode(['ok' => false, 'error' => 'Missing exam id']); return; }
        $meta = $this->exam_read->meta($id, false);
        if (!is_array($meta) || empty($meta)) { echo json_encode(['ok' => false, 'error' => 'Exam not found']); return; }
        echo json_encode([
            'ok'             => true,
            'rows'           => $this->_build_edit_rows($id),
            'gradingScale'   => (string) ($meta['GradingScale'] ?? ''),
            'passingPercent' => (int)    ($meta['PassingPercent'] ?? 33),
        ]);
    }

    /** Phase 3.5 — rebuild create-form rows from examSchedule (one section/class). */
    private function _build_edit_rows(string $id): array
    {
        $sched = $this->exam_read->schedule($id); // [class][section][date][subject] => [Time,TotalMarks,PassingMarks]
        $rows  = [];
        foreach ($sched as $class => $sections) {
            if (!is_array($sections) || empty($sections)) continue;
            $firstSection = reset($sections); // sections of a class are identical
            if (!is_array($firstSection)) continue;
            foreach ($firstSection as $dateKey => $subjs) {
                if (!is_array($subjs)) continue;
                foreach ($subjs as $subjName => $cell) {
                    if (!is_array($cell)) continue;
                    $dt      = DateTime::createFromFormat('d-m-Y', (string) $dateKey);
                    $dateYmd = $dt ? $dt->format('Y-m-d') : '';
                    [$st, $et] = array_pad(explode('-', (string) ($cell['Time'] ?? ''), 2), 2, '');
                    $rows[] = [
                        'date'         => $dateYmd,
                        'className'    => (string) $class,
                        'subject'      => (string) $subjName,
                        'startTime'    => $this->_to24($st),
                        'endTime'      => $this->_to24($et),
                        'totalMarks'   => (int) ($cell['TotalMarks']   ?? 0),
                        'passingMarks' => (int) ($cell['PassingMarks'] ?? 0),
                    ];
                }
            }
        }
        return $rows;
    }

    /** "10:00AM" → "10:00" (24h) for <input type=time>; '' on failure. */
    private function _to24(string $t): string
    {
        $t = trim($t);
        if ($t === '') return '';
        $d = DateTime::createFromFormat('h:iA', $t) ?: DateTime::createFromFormat('g:iA', $t);
        return $d ? $d->format('H:i') : '';
    }

    // ── edit_exam($id) — POST AJAX (Phase 3.4, Option B Draft-only edit) ──
    //
    // Rewrites an EXISTING exam (same examId — no generate_exam_id) only when
    // the exam exists, is in Draft, and has no marks. Status ownership stays
    // exclusively in update_status(); this method NEVER changes status. All
    // lifecycle audit history + created metadata are preserved; only
    // updatedAt is refreshed. Reachable via default routing exam/edit_exam/{id}.
    public function edit_exam($id = null)
    {
        $this->_require_role(self::ADMIN_ROLES, 'edit exam');
        header('Content-Type: application/json');

        if ($this->input->method() !== 'post') {
            $this->json_error('POST required.', 405);
        }

        $id     = trim((string) $id);
        $school = $this->school_name;
        if ($id === '') {
            $this->json_error('Missing exam id.', 400);
        }

        // — Gate 1: exam must exist. Read the RAW doc (camelCase) so lifecycle
        //   history + created metadata can be preserved verbatim.
        $existing = $this->firebase->firestoreGet('exams', "{$school}_{$id}");
        if (!is_array($existing) || empty($existing)) {
            $this->json_error('Exam not found.', 404);
        }

        // — Gate 2: Draft-only (Option B). Published/Completed are read-only.
        $curStatus = (string) ($existing['status'] ?? 'Draft');
        if ($curStatus !== 'Draft') {
            $this->json_error(
                'Only Draft exams can be edited. Unpublish a Published exam '
                . '(or Reopen then Unpublish a Completed exam) before editing.', 409
            );
        }

        // — Gate 3: marks-free defense-in-depth (Phase 3.3 already blocks the
        //   Unpublish path when marks exist; this is belt-and-suspenders).
        if ($this->_exam_has_marks($id)) {
            $this->json_error(
                'Cannot edit: marks have been entered for this exam. '
                . 'Clear all marks first.', 409
            );
        }

        $structure = $this->exam_engine->get_class_structure();

        // ── Validation (identical rules to create(); replicated to keep the
        //    shipped create() path untouched). NOTE: examStatus is NOT read —
        //    status is preserved, never changed here. ──
        $name = trim((string) $this->input->post('examName'));
        if (!preg_match('/^[\w\s\-\.]{2,80}$/u', $name)) {
            $this->json_error('Invalid exam name. Use letters, digits, spaces, hyphens, or dots (2–80 chars).', 400);
        }
        $type = trim((string) $this->input->post('examType'));
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $this->json_error('Invalid exam type.', 400);
        }
        $scale = strip_tags(trim((string) $this->input->post('gradingScale')));
        if (!in_array($scale, self::ALLOWED_SCALES, true)) {
            $this->json_error('Invalid grading scale.', 400);
        }
        $passingPct = (int) $this->input->post('passingPercent');
        if ($passingPct < 1 || $passingPct > 100) {
            $this->json_error('PassingPercent must be 1–100.', 400);
        }
        $startDt = DateTime::createFromFormat('Y-m-d', trim((string) $this->input->post('startDate')));
        $endDt   = DateTime::createFromFormat('Y-m-d', trim((string) $this->input->post('endDate')));
        if (!$startDt || !$endDt) {
            $this->json_error('Invalid date format.', 400);
        }
        if ($startDt > $endDt) {
            $this->json_error('Start date must not be after end date.', 400);
        }
        $instructions = [];
        $idx          = 0;
        foreach (explode("\n", (string) $this->input->post('generalInstructions')) as $line) {
            $c = trim(preg_replace('/^[•\-\*\s]+/', '', $line));
            if ($c !== '') $instructions[$idx++] = $c;
        }
        $scheduleJson = (string) $this->input->post('examSchedule');
        if (empty($scheduleJson)) {
            $this->json_error('Exam schedule is empty. Please add at least one row.', 400);
        }
        $scheduleRows = json_decode($scheduleJson, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($scheduleRows) || empty($scheduleRows)) {
            $this->json_error('Invalid schedule data.', 400);
        }

        // — Gate 4: duplicate name+type guard EXCLUDING self.
        $existingExams = $this->exam_read->list_exams() ?? [];
        if (is_array($existingExams)) {
            foreach ($existingExams as $exId => $exMeta) {
                if ($exId === 'Count' || !is_array($exMeta) || $exId === $id) continue; // skip self
                if (strcasecmp(trim((string) ($exMeta['Name'] ?? '')), $name) === 0
                    && strcasecmp(trim((string) ($exMeta['Type'] ?? '')), $type) === 0) {
                    $this->json_error("An exam named \"{$name}\" ({$type}) already exists for this session.", 409);
                }
            }
        }

        // ── Accumulate the new schedule (shared parser — MED-9; identical
        //    schedule docs to create()). ──
        $parsed      = $this->_parse_schedule_rows($scheduleRows, $structure, $passingPct);
        $schedAccum  = $parsed['schedAccum'];
        $classesSeen = $parsed['classesSeen'];
        $savedCount  = $parsed['savedCount'];
        $skippedRows = $parsed['skippedRows'];

        // ── Rewrite the SAME examId. Preserve created metadata + ALL lifecycle
        //    audit history; only updatedAt changes (buildExamDoc stamps it).
        //    Status is carried forward unchanged. ──
        $this->efs->syncExam($id, [
            'examName'            => $name,
            'examType'            => $type,
            'status'              => $curStatus,                 // PRESERVE — never change here
            'gradingScale'        => $scale,
            'passingPercent'      => $passingPct,
            'startDate'           => $startDt->format('Y-m-d'),
            'endDate'             => $endDt->format('Y-m-d'),
            'applicableClasses'   => array_keys($classesSeen),
            'generalInstructions' => $instructions ?: [],
            // Preserved created metadata + lifecycle audit (verbatim).
            'createdBy'           => $existing['createdBy']     ?? '',
            'createdAt'           => $existing['createdAt']     ?? null,
            'publishedBy'         => $existing['publishedBy']   ?? null,
            'publishedAt'         => $existing['publishedAt']   ?? null,
            'completedBy'         => $existing['completedBy']   ?? null,
            'completedAt'         => $existing['completedAt']   ?? null,
            'unpublishedBy'       => $existing['unpublishedBy'] ?? null,
            'unpublishedAt'       => $existing['unpublishedAt'] ?? null,
            'reopenedBy'          => $existing['reopenedBy']    ?? null,
            'reopenedAt'          => $existing['reopenedAt']    ?? null,
        ]);

        // ── Schedule reconciliation (full replace): delete every prior
        //    per-section schedule doc, then write the current set. Sections
        //    removed during the edit are thereby cleaned — no orphans.
        $oldSections = $this->exam_read->sections($id);
        foreach ($oldSections as $cs) {
            $oc = $cs[0] ?? '';
            $os = $cs[1] ?? '';
            if ($oc === '' || $os === '') continue;
            $this->firebase->firestoreDelete('examSchedule', "{$school}_{$id}_{$oc}_{$os}");
        }
        foreach ($schedAccum as $accClass => $accSections) {
            foreach ($accSections as $accSection => $accSubjects) {
                $this->efs->syncExamScheduleFull($id, (string) $accClass, (string) $accSection, $accSubjects);
            }
        }

        $response = [
            'examId'     => $id,
            'message'    => "Exam updated successfully ({$savedCount} entries saved).",
            'csrf_token' => $this->security->get_csrf_hash(),
        ];
        if (!empty($skippedRows)) {
            $response['warnings'] = $skippedRows;
            $response['message'] .= ' ' . count($skippedRows) . ' row(s) skipped due to validation errors.';
        }
        $this->json_success($response);
    }

    // ── view($id) ────────────────────────────────────────────────────────
    public function view($id = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'view_exam');
        if (!$id) { redirect('exam'); }

        $school = $this->school_name;
        $year   = $this->session_year;
        $exam   = $this->exam_read->meta($id);

        if (!$exam || !is_array($exam)) { redirect('exam'); }

        // Phase 3.5: server-rendered marks pre-check (UX). The authoritative
        // guard remains the 3.3 server check in update_status()/delete().
        $hasMarks = $this->_exam_has_marks($id);

        $this->load->view('include/header');
        $this->load->view('exam/view', ['examId' => $id, 'exam' => $exam, 'hasMarks' => $hasMarks]);
        $this->load->view('include/footer');
    }

    // ── delete($id) ──────────────────────────────────────────────────────
    public function delete($id = null)
    {
        $this->_require_role(self::ADMIN_ROLES, 'delete exam');
        if (!$id) { redirect('exam'); }

        // Phase 3.3 delete guard: a graded exam (marks entered) requires an
        // explicit confirm flag before the destructive cascade runs. Without
        // it, redirect back with a warning rather than silently destroying
        // marks/results. The existing cascade below is unchanged on confirm.
        $confirm = ($this->input->get('confirm') === '1' || $this->input->post('confirm') === '1');
        if (!$confirm && $this->_exam_has_marks($id)) {
            $this->session->set_flashdata('error',
                'This exam has marks entered. Deleting permanently removes all marks and '
                . 'results. Re-confirm to proceed.');
            redirect('exam');
            return;
        }

        // Phase-E: Published/Completed exams require an ADDITIONAL explicit
        // confirmation — student-visible results may already exist. Draft uses
        // the normal flow above.
        $examMeta = $this->exam_read->meta($id, false);
        $status   = is_array($examMeta) ? (string) ($examMeta['Status'] ?? 'Draft') : 'Draft';
        if (in_array($status, ['Published', 'Completed'], true)) {
            $confirmPub = ($this->input->get('confirm_published') === '1' || $this->input->post('confirm_published') === '1');
            if (!$confirmPub) {
                $this->session->set_flashdata('error',
                    "This exam is {$status}; student-visible results may already exist. "
                    . 'Re-confirm to permanently delete the exam and all of its results.');
                redirect('exam');
                return;
            }
        }

        $school   = $this->school_name;
        $year     = $this->session_year;
        // Phase 2B: enumerate sections from Firestore examSchedule (1:1 per section).
        $sections = $this->exam_read->sections($id);
        foreach ($sections as $cs) {
            $secClass   = $cs[0] ?? '';
            $secSection = $cs[1] ?? '';
            if ($secClass === '' || $secSection === '') continue;
            // Firestore exam-def: delete the per-section schedule doc.
            $this->firebase->firestoreDelete('examSchedule', "{$school}_{$id}_{$secClass}_{$secSection}");
            // Legacy cleanup: remove any pre-cutover RTDB per-section copy.
            $this->firebase->delete("Schools/{$school}/{$year}/{$secClass}/{$secSection}/Exams/{$id}");
        }

        // Delete central exam definition (Firestore).
        $this->firebase->firestoreDelete('exams', "{$school}_{$id}");

        // Phase-E: Firestore-only cascade — delete results, marks, examResultMeta,
        // resultsStaging, examTemplates for this exam. Audit retained + terminal
        // exam_deleted event. Firestore is the authoritative store.
        $this->load->library('exam_result_store');
        $actor = ['uid' => (string) ($this->admin_id ?? ''), 'role' => (string) ($this->admin_role ?? ''), 'name' => (string) ($this->admin_id ?? '')];
        $this->exam_result_store->init($this->firebase, $this->school_id, $year)
            ->cascadeDeleteExam($id, $actor, date('c'));

        // Cumulative migration (Firestore-only): drop the exam from cumulativeConfig
        // and mark ALL cumulative results stale — EX-4: an exam removal invalidates
        // every cumulative (we cannot selectively subtract one exam's contribution).
        $this->load->library('exam_result_store');
        $cers = $this->exam_result_store->init($this->firebase, $this->school_id, $year);
        $cers->removeExamFromCumulativeConfig($id);
        $cers->markCumulativeStale("Exam {$id} deleted", date('c'));

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

        // Phase 3.2 lifecycle state machine. Read the current status first;
        // the exam must exist (fail-closed).
        $meta = $this->exam_read->meta($id, false);
        if (!is_array($meta)) {
            $this->json_error('Exam not found.', 404);
        }
        $from = (string) ($meta['Status'] ?? 'Draft');
        $to   = $status;

        // Decide via the pure state machine (Phase 3.2).
        $decision = $this->_status_transition(
            $from, $to, $this->admin_id ?? '', (int) round(microtime(true) * 1000)
        );

        if ($decision['action'] === 'idempotent') {
            // Same -> same: success, no write, no stamp, no error.
            $this->json_success(['message' => "Status unchanged ({$to})."]);
            return;
        }
        if ($decision['action'] === 'blocked') {
            $this->json_error("Transition not allowed: {$from} \u{2192} {$to}.", 409);
        }

        // Phase 3.3 marks guard (Philosophy A): block Unpublish (Published ->
        // Draft) when marks exist. A graded exam must not be made editable;
        // this prevents marks/schedule divergence once the Draft-only edit
        // endpoint (Phase 3.4) lands. Clearing marks is the explicit prereq.
        if ($from === 'Published' && $to === 'Draft' && $this->_exam_has_marks($id)) {
            $this->json_error(
                'Cannot unpublish: marks have already been entered for this exam. '
                . 'Clear all marks before unpublishing to edit it.', 409
            );
        }

        $this->firebase->firestoreUpdate('exams', "{$school}_{$id}", $decision['update']);

        // ── HIGH-3 PUBLISH GATE — keep the parent-visible `results` collection
        //    in lock-step with the exam's publication state, reusing the staging
        //    pipeline (promoteExam / demoteExam). INVARIANT: `results` holds ONLY
        //    published results; the computed set lives in resultsStaging.
        $this->load->library('exam_result_store');
        $ers   = $this->exam_result_store->init($this->firebase, $this->school_id, $this->session_year);
        $actor = ['uid' => (string) ($this->admin_id ?? ''), 'role' => (string) ($this->admin_role ?? ''), 'name' => (string) ($this->admin_id ?? '')];

        if ($to === 'Published') {
            // Promote this exam's staged results → results (deletes staging rows
            // as it promotes). No-op when staging is empty (e.g. Completed→Published
            // reopen — already promoted on the first publish).
            try {
                $prom = $ers->promoteExam($id, $actor, $from, $to, date('c'));

                // ── HIGH-4 — fire the parent "results published" notification HERE
                //    (at publish, not compute), PER STUDENT, so each event carries a
                //    `student_id` and _resolve_recipient('parent') actually resolves.
                //    Fire-and-forget (uses the direct Push_service/messageQueue path).
                $recipients = $prom['notify'] ?? [];
                if (!empty($recipients)) {
                    $this->load->library('Communication_helper', null, 'comm');
                    $this->comm->init($this->firebase, $school, $this->session_year);
                    $bulk = [];
                    foreach ($recipients as $rc) {
                        $sid = (string) ($rc['student_id'] ?? '');
                        if ($sid === '') continue;
                        $bulk[] = [
                            'student_id' => $sid,
                            'exam_id'    => $id,
                            'exam_name'  => (string) ($rc['exam_name'] ?? ($meta['Name'] ?? $id)),
                            'class'      => (string) ($rc['class'] ?? ''),
                            'section'    => (string) ($rc['section'] ?? ''),
                        ];
                    }
                    if (!empty($bulk)) $this->comm->fire_event_bulk('exam_result', $bulk);
                }
            } catch (\Exception $e) {
                log_message('error', "update_status: publish promotion/notify failed [{$id}]: " . $e->getMessage());
            }
        } elseif ($from === 'Published' && $to === 'Draft') {
            // Unpublish: move published results back to staging so parents stop
            // seeing them. (The marks guard above already blocks this when marks
            // exist — this is belt-and-suspenders result cleanup.)
            try {
                $ers->demoteExam($id, $actor, date('c'));
            } catch (\Exception $e) {
                log_message('error', "update_status: unpublish demotion failed [{$id}]: " . $e->getMessage());
            }
        }

        $this->json_success(['message' => "Status updated: {$from} \u{2192} {$to}."]);
    }

    /**
     * Pure lifecycle state machine (Phase 3.2) — no I/O, unit-testable.
     *
     * Locked allowed-transition matrix:
     *   Draft     -> Published   (Publish)
     *   Published -> Completed   (Complete)
     *   Published -> Draft       (Unpublish)
     *   Completed -> Published   (Reopen)
     * Blocked: Draft->Completed (must Publish first),
     *          Completed->Draft (must Reopen to Published, then Unpublish).
     * Same -> same is idempotent (no write).
     *
     * @return array{action:string, update:?array}
     *   action ∈ {'idempotent','blocked','allowed'}; 'update' holds the
     *   Firestore payload (status + transition stamp) only when 'allowed'.
     */
    private function _status_transition(string $from, string $to, string $by, int $nowMs): array
    {
        if ($from === $to) {
            return ['action' => 'idempotent', 'update' => null];
        }
        static $ALLOWED = [
            'Draft'     => ['Published'],
            'Published' => ['Completed', 'Draft'],
            'Completed' => ['Published'],
        ];
        if (!in_array($to, $ALLOWED[$from] ?? [], true)) {
            return ['action' => 'blocked', 'update' => null];
        }
        // Forward stamps (Phase 1) + reverse stamps (Phase 3.1 schema).
        $update = ['status' => $to, 'updatedAt' => date('c')];
        if ($from === 'Draft' && $to === 'Published') {            // Publish
            $update['publishedBy'] = $by; $update['publishedAt'] = $nowMs;
        } elseif ($from === 'Published' && $to === 'Completed') {   // Complete
            $update['completedBy'] = $by; $update['completedAt'] = $nowMs;
        } elseif ($from === 'Published' && $to === 'Draft') {       // Unpublish
            $update['unpublishedBy'] = $by; $update['unpublishedAt'] = $nowMs;
        } elseif ($from === 'Completed' && $to === 'Published') {   // Reopen
            $update['reopenedBy'] = $by; $update['reopenedAt'] = $nowMs;
        }
        return ['action' => 'allowed', 'update' => $update];
    }

    /**
     * Phase 3.3 — TRUE if any student marks exist for this exam.
     *
     * B1 (results-engine cutover): the graded signal is now the canonical
     * Firestore `marks` collection (the legacy RTDB `Results/Marks` is no longer
     * written by save_marks, so reading it would fail OPEN for exams graded
     * after the migration). Existence is an equality-only `(schoolId, examId)`
     * query with `limit 1` — served by single-field indexes (no composite).
     *
     * FAIL-CLOSED: a query error returns TRUE ("assume marks may exist") so this
     * destructive-action guard can never fail open and silently permit a
     * delete/unpublish/edit that would lose marks.
     */
    private function _exam_has_marks(string $examId): bool
    {
        if ($examId === '') return false;
        try {
            $rows = $this->firebase->firestoreQuery('marks',
                [['schoolId', '==', $this->school_id], ['examId', '==', $examId]], null, 'ASC', 1);
            return is_array($rows) && count($rows) > 0;
        } catch (\Throwable $e) {
            log_message('error', "_exam_has_marks FS query failed [{$examId}]: " . $e->getMessage());
            return true; // fail-closed: block the destructive action rather than fail open
        }
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

        // UX-1.4.1.2: read from the CANONICAL Firestore `subjects` collection via
        // the shared UX-1.4.1.1 reader (NO RTDB Subject_list). The $classKey here
        // is the class label/key sent by callers (Marks Entry, Template Designer);
        // _subject_class_key() normalises both "Class 8th" and bare "8"/"LKG".
        // API contract unchanged: { "subjects": [name, …] }.
        $map = $this->_build_subjects_map([$classKey => true]);
        echo json_encode(['subjects' => $map[$classKey] ?? []]);
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
