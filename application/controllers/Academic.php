<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Academic Management Controller
 *
 * Unified hub for subject assignment, period scheduling, curriculum planning,
 * academic calendar, master timetable grid, and substitute teacher management.
 *
 * Firebase paths:
 *   Schools/{school}/{session}/Academic/Subject_Assignments/{class}   (per-class subject assignments)
 *   Schools/{school}/{session}/Academic/Curriculum/{classSection}/{subject}/topics/
 *   Schools/{school}/{session}/Academic/Calendar/{eventId}
 *   Schools/{school}/{session}/Academic/Substitutes/{id}
 *   Schools/{school}/{session}/Time_table_settings   (periods, timings, recesses, working_days)
 *
 * Reads existing paths:
 *   Schools/{school}/Config/Classes          (structural class list from School Config)
 *   Schools/{school}/Subject_list/{classNum} (master subject catalog from School Config)
 *   Schools/{school}/{session}/Class {n}/Section {X}/Time_table
 *   Schools/{school}/{session}/Teachers
 */
class Academic extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        require_permission('Academic');
    }

    /* ══════════════════════════════════════════════════════════════════════
       PAGE LOAD
    ══════════════════════════════════════════════════════════════════════ */

    public function index()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'academic_view');

        $data['session_year'] = $this->session_year;
        $data['school_name']  = $this->school_name;
        $data['school_id']    = $this->school_id;
        $data['admin_role']   = $this->admin_role ?? '';

        $this->load->view('include/header');
        $this->load->view('academic/index', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       SHARED: CLASS / SUBJECT / TEACHER DATA
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Return all class-sections + subjects for dropdowns
     */
    public function get_classes_subjects()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'academic_data');
        $school  = $this->school_name;
        $session = $this->session_year;

        $classes  = $this->_get_session_classes();
        $subjects = [];

        // Build subject map per class number
        $subjectList = $this->firebase->get("Schools/{$school}/Subject_list") ?? [];
        if (is_array($subjectList)) {
            foreach ($subjectList as $classNum => $subs) {
                if (!is_array($subs)) continue;
                foreach ($subs as $code => $sub) {
                    if (!is_array($sub)) continue;
                    $subjects[$classNum][] = [
                        'code' => $code,
                        'name' => $sub['subject_name'] ?? $sub['name'] ?? (string) $code,
                    ];
                }
            }
        }

        return $this->json_success([
            'classes'  => $classes,
            'subjects' => $subjects,
        ]);
    }

    /**
     * Return all teachers for the current session (batch read)
     */
    public function get_all_teachers()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'academic_data');
        $school  = $this->school_name;
        $session = $this->session_year;

        // 1. Try session roster first (teachers assigned to current session)
        $sessionRoster = $this->firebase->get("Schools/{$school}/{$session}/Teachers") ?? [];
        if (!is_array($sessionRoster)) $sessionRoster = [];

        // 2. Load all teacher profiles (single batch read)
        //    Staff.php stores profiles under school_id which equals school_name
        //    for both legacy ("Demo") and new ("SCH_XXXXXX") schools.
        $allProfiles = $this->firebase->get("Users/Teachers/{$school}") ?? [];
        if (!is_array($allProfiles)) $allProfiles = [];

        $teachers = [];

        if (!empty($sessionRoster)) {
            // Session roster exists — use it (preferred: only teachers in this session)
            foreach ($sessionRoster as $id => $val) {
                if ($id === 'Count') continue;
                $profile = $allProfiles[$id] ?? [];
                $name    = is_array($val) ? ($val['Name'] ?? '') : '';
                if (!$name && is_array($profile)) {
                    $name = $profile['Name'] ?? '';
                }
                $teachers[] = [
                    'id'   => $id,
                    'name' => $name ?: $id,
                ];
            }
        } else {
            // Fallback: session roster empty — load from master profiles
            // This handles new sessions where teachers haven't been assigned yet
            foreach ($allProfiles as $id => $profile) {
                if ($id === 'Count' || !is_array($profile)) continue;
                $teachers[] = [
                    'id'   => $id,
                    'name' => $profile['Name'] ?? $id,
                ];
            }
        }

        // Sort alphabetically by name
        usort($teachers, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $this->json_success(['teachers' => $teachers]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       SUBJECT ASSIGNMENT
       Reads classes from  Schools/{school}/Config/Classes  (structural data in School Config)
       Reads subjects from Schools/{school}/Subject_list    (master catalog in School Config)
       Stores assignments  Schools/{school}/{session}/Academic/Subject_Assignments/{class}
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Get available subjects from global Subject_list, Config/Classes list,
     * and current session assignments per class.
     */
    public function get_subject_assignments()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_subject_assignments');

        $school  = $this->school_name;
        $session = $this->session_year;

        // 1. Read class list from Config/Classes (structural, managed in School Config)
        $configClasses = $this->firebase->get("Schools/{$school}/Config/Classes") ?? [];
        if (!is_array($configClasses)) $configClasses = [];

        // If Config/Classes is empty, fall back to live enumeration
        $classes = [];
        if (!empty($configClasses)) {
            foreach ($configClasses as $cls) {
                if (!is_array($cls)) continue;
                if (!empty($cls['deleted'])) continue;
                $classes[] = [
                    'key'   => $cls['key'] ?? $cls['name'] ?? '',
                    'label' => $cls['label'] ?? $cls['name'] ?? $cls['key'] ?? '',
                ];
            }
        }
        if (empty($classes)) {
            // Fallback: enumerate from session root
            $sessionClasses = $this->_get_session_classes();
            $seen = [];
            foreach ($sessionClasses as $sc) {
                $k = $sc['class_key'];
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $classes[] = ['key' => $k, 'label' => $k];
            }
        }

        // 2. Global Subject_list (master catalog, created in School Config)
        $subjectList = $this->firebase->get("Schools/{$school}/Subject_list") ?? [];
        $catalog = [];
        if (is_array($subjectList)) {
            foreach ($subjectList as $classNum => $subs) {
                if ($classNum === 'pattern_type' || !is_array($subs)) continue;
                foreach ($subs as $code => $sub) {
                    if ($code === 'pattern_type' || !is_array($sub)) continue;
                    $catalog[$classNum][] = [
                        'code'     => (string)$code,
                        'name'     => $sub['subject_name'] ?? $sub['name'] ?? (string)$code,
                        'category' => $sub['category'] ?? 'Core',
                        'stream'   => $sub['stream'] ?? '',
                    ];
                }
            }
        }

        // 3. Current session assignments per class
        $assignPath = "Schools/{$school}/{$session}/Academic/Subject_Assignments";
        $allAssignments = $this->firebase->get($assignPath) ?? [];
        if (!is_array($allAssignments)) $allAssignments = [];

        $assignments = [];
        foreach ($classes as $cls) {
            $classKey = $this->_class_to_firebase_key($cls['key']);
            $assigned = [];
            if (isset($allAssignments[$classKey]) && is_array($allAssignments[$classKey])) {
                foreach ($allAssignments[$classKey] as $code => $info) {
                    if (!is_array($info)) continue;
                    $assigned[] = [
                        'code'         => (string)$code,
                        'name'         => $info['name'] ?? '',
                        'category'     => $info['category'] ?? 'Core',
                        'periods_week' => (int)($info['periods_week'] ?? 0),
                        'teacher_id'   => $info['teacher_id'] ?? '',
                        'teacher_name' => $info['teacher_name'] ?? '',
                    ];
                }
            }
            $assignments[$classKey] = $assigned;
        }

        return $this->json_success([
            'classes'     => $classes,
            'catalog'     => $catalog,
            'assignments' => $assignments,
        ]);
    }

    /**
     * Save subject assignments for a class.
     * POST: class_key, subjects (JSON array)
     */
    public function save_subject_assignments()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'save_subject_assignments');

        $classKey = trim($this->input->post('class_key') ?? '');
        $rawSubs  = $this->input->post('subjects');

        if (empty($classKey)) {
            return $this->json_error('Class is required');
        }

        $fbKey = $this->safe_path_segment($this->_class_to_firebase_key($classKey), 'class_key');

        $subjects = is_string($rawSubs) ? json_decode($rawSubs, true) : $rawSubs;
        if (!is_array($subjects)) $subjects = [];

        $data = [];
        foreach ($subjects as $s) {
            if (!is_array($s) || empty($s['code'])) continue;
            $code = $this->safe_path_segment(trim($s['code']), 'subject_code');
            $data[$code] = [
                'name'         => trim($s['name'] ?? ''),
                'category'     => trim($s['category'] ?? 'Core'),
                'periods_week' => max(0, (int)($s['periods_week'] ?? 0)),
                'teacher_id'   => trim($s['teacher_id'] ?? ''),
                'teacher_name' => trim($s['teacher_name'] ?? ''),
            ];
        }

        $path = "Schools/{$this->school_name}/{$this->session_year}/Academic/Subject_Assignments/{$fbKey}";
        $this->firebase->set($path, empty($data) ? null : $data);

        return $this->json_success(['class' => $fbKey, 'count' => count($data)]);
    }

    /**
     * Copy subject assignments from one class to another.
     */
    public function copy_subject_assignments()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'copy_subject_assignments');

        $fromKey = $this->safe_path_segment(trim($this->input->post('from_key') ?? ''), 'from_key');
        $toKey   = $this->safe_path_segment(trim($this->input->post('to_key') ?? ''), 'to_key');

        if (empty($fromKey) || empty($toKey)) {
            return $this->json_error('Source and destination are required');
        }
        if ($fromKey === $toKey) {
            return $this->json_error('Source and destination cannot be the same');
        }

        $basePath = "Schools/{$this->school_name}/{$this->session_year}/Academic/Subject_Assignments";
        $source = $this->firebase->get("{$basePath}/{$fromKey}");
        if (!is_array($source) || empty($source)) {
            return $this->json_error('No assignments found in source class');
        }

        $this->firebase->set("{$basePath}/{$toKey}", $source);

        return $this->json_success(['from' => $fromKey, 'to' => $toKey, 'count' => count($source)]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       CURRICULUM PLANNING
    ══════════════════════════════════════════════════════════════════════ */

    public function get_curriculum()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_curriculum');

        $classSection = trim($this->input->post('class_section') ?? '');
        $subject      = trim($this->input->post('subject') ?? '');

        if (empty($classSection) || empty($subject)) {
            return $this->json_error('Class and subject required');
        }

        // Sanitize path segments to prevent traversal
        $classSection = $this->safe_path_segment($classSection, 'class_section');
        $subject      = $this->safe_path_segment($subject, 'subject');

        $path = "Schools/{$this->school_name}/{$this->session_year}/Academic/Curriculum/{$classSection}/{$subject}";
        $data = $this->firebase->get($path) ?? [];

        $topics = [];
        if (is_array($data) && isset($data['topics']) && is_array($data['topics'])) {
            $topics = array_values($data['topics']);
        }

        return $this->json_success([
            'topics'        => $topics,
            'class_section' => $classSection,
            'subject'       => $subject,
        ]);
    }

    public function save_curriculum()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'save_curriculum');

        $classSection = trim($this->input->post('class_section') ?? '');
        $subject      = trim($this->input->post('subject') ?? '');
        $topicsRaw    = $this->input->post('topics');

        if (empty($classSection) || empty($subject)) {
            return $this->json_error('Class and subject required');
        }

        $classSection = $this->safe_path_segment($classSection, 'class_section');
        $subject      = $this->safe_path_segment($subject, 'subject');

        $topics = is_string($topicsRaw) ? json_decode($topicsRaw, true) : $topicsRaw;
        if (!is_array($topics)) $topics = [];

        // Sanitize topics
        $clean = [];
        foreach ($topics as $i => $t) {
            if (!is_array($t) || empty(trim($t['title'] ?? ''))) continue;
            $clean[] = [
                'title'          => trim($t['title']),
                'chapter'        => trim($t['chapter'] ?? ''),
                'est_periods'    => max(0, (int)($t['est_periods'] ?? 0)),
                'status'         => in_array($t['status'] ?? '', ['not_started', 'in_progress', 'completed'])
                                        ? $t['status'] : 'not_started',
                'completed_date' => ($t['status'] ?? '') === 'completed' ? ($t['completed_date'] ?? date('Y-m-d')) : '',
                'sort_order'     => $i,
            ];
        }

        $path = "Schools/{$this->school_name}/{$this->session_year}/Academic/Curriculum/{$classSection}/{$subject}";
        $this->firebase->set($path, ['topics' => $clean, 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->json_success(['topics' => $clean]);
    }

    public function update_topic_status()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'update_curriculum');
        $classSection = $this->safe_path_segment(trim($this->input->post('class_section') ?? ''), 'class_section');
        $subject      = $this->safe_path_segment(trim($this->input->post('subject') ?? ''), 'subject');
        $index        = (int)($this->input->post('index') ?? -1);
        $status       = trim($this->input->post('status') ?? '');

        if (empty($classSection) || empty($subject) || $index < 0) {
            return $this->json_error('Invalid parameters');
        }
        if (!in_array($status, ['not_started', 'in_progress', 'completed'])) {
            return $this->json_error('Invalid status');
        }

        $path = "Schools/{$this->school_name}/{$this->session_year}/Academic/Curriculum/{$classSection}/{$subject}/topics/{$index}";
        $update = ['status' => $status];
        if ($status === 'completed') $update['completed_date'] = date('Y-m-d');
        else $update['completed_date'] = '';

        $this->firebase->update($path, $update);

        return $this->json_success(['index' => $index, 'status' => $status]);
    }

    public function delete_topic()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'delete_curriculum');
        $classSection = $this->safe_path_segment(trim($this->input->post('class_section') ?? ''), 'class_section');
        $subject      = $this->safe_path_segment(trim($this->input->post('subject') ?? ''), 'subject');
        $index        = (int)($this->input->post('index') ?? -1);

        if (empty($classSection) || empty($subject) || $index < 0) {
            return $this->json_error('Invalid parameters');
        }

        // Read all topics, remove by index, re-index, save
        $path = "Schools/{$this->school_name}/{$this->session_year}/Academic/Curriculum/{$classSection}/{$subject}";
        $data = $this->firebase->get($path) ?? [];
        $topics = (is_array($data) && isset($data['topics'])) ? array_values($data['topics']) : [];

        if (!isset($topics[$index])) {
            return $this->json_error('Topic not found');
        }

        array_splice($topics, $index, 1);
        // Re-index sort_order
        foreach ($topics as $i => &$t) { $t['sort_order'] = $i; }
        unset($t);

        $this->firebase->set($path, ['topics' => $topics, 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->json_success(['topics' => $topics]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       ACADEMIC CALENDAR
    ══════════════════════════════════════════════════════════════════════ */

    public function get_calendar_events()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_calendar');
        $month = trim($this->input->post('month') ?? '');  // YYYY-MM or empty for all
        $path  = "Schools/{$this->school_name}/{$this->session_year}/Academic/Calendar";
        $raw   = $this->firebase->get($path) ?? [];

        $events = [];
        if (is_array($raw)) {
            foreach ($raw as $id => $ev) {
                if (!is_array($ev)) continue;
                // Optional month filter — include events that overlap the month
                if ($month !== '') {
                    $evStart = $ev['start_date'] ?? '';
                    $evEnd   = $ev['end_date'] ?? $evStart;
                    $monthStart = $month . '-01';
                    $monthEnd   = date('Y-m-t', strtotime($monthStart));
                    // Skip if event ends before month starts or starts after month ends
                    if ($evEnd < $monthStart || $evStart > $monthEnd) continue;
                }
                $ev['id'] = $id;
                $events[] = $ev;
            }
        }

        // Sort by start_date
        usort($events, fn($a, $b) => strcmp($a['start_date'] ?? '', $b['start_date'] ?? ''));

        return $this->json_success(['events' => $events]);
    }

    public function save_event()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_calendar');
        $id         = trim($this->input->post('id') ?? '');
        $title      = trim($this->input->post('title') ?? '');
        $type       = trim($this->input->post('type') ?? 'event');
        $startDate  = trim($this->input->post('start_date') ?? '');
        $endDate    = trim($this->input->post('end_date') ?? '') ?: $startDate;
        $desc       = trim($this->input->post('description') ?? '');

        if (empty($title) || empty($startDate)) {
            return $this->json_error('Title and start date are required');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !strtotime($startDate)) {
            return $this->json_error('Invalid start date format');
        }
        if ($endDate !== $startDate && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || !strtotime($endDate))) {
            return $this->json_error('Invalid end date format');
        }
        if ($endDate < $startDate) {
            return $this->json_error('End date cannot be before start date');
        }

        $validTypes = ['holiday', 'exam', 'meeting', 'event', 'activity'];
        if (!in_array($type, $validTypes)) $type = 'event';

        $data = [
            'title'       => $title,
            'type'        => $type,
            'start_date'  => $startDate,
            'end_date'    => $endDate,
            'description' => $desc,
            'updated_at'  => date('Y-m-d H:i:s'),
        ];

        $basePath = "Schools/{$this->school_name}/{$this->session_year}/Academic/Calendar";

        if ($id !== '') {
            // Update existing
            $this->firebase->update("{$basePath}/{$id}", $data);
        } else {
            // Create new
            $data['created_at'] = date('Y-m-d H:i:s');
            $id = $this->firebase->push($basePath, $data);
        }

        return $this->json_success(['id' => $id, 'event' => $data]);
    }

    public function delete_event()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_calendar');
        $id = trim($this->input->post('id') ?? '');
        if (empty($id)) return $this->json_error('Event ID required');

        $this->firebase->delete(
            "Schools/{$this->school_name}/{$this->session_year}/Academic/Calendar", $id
        );

        return $this->json_success([]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       MASTER TIMETABLE
    ══════════════════════════════════════════════════════════════════════ */

    public function get_master_timetable()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_timetable');
        $school  = $this->school_name;
        $session = $this->session_year;

        // 1. Timetable settings
        $settings = $this->firebase->get("Schools/{$school}/{$session}/Time_table_settings") ?? [];
        if (!is_array($settings)) $settings = [];

        // Normalize recesses
        $recesses = [];
        if (isset($settings['Recesses']) && is_array($settings['Recesses'])) {
            foreach ($settings['Recesses'] as $r) {
                if (is_array($r) && isset($r['after_period'], $r['duration'])) {
                    $recesses[] = ['after_period' => (int)$r['after_period'], 'duration' => (int)$r['duration']];
                }
            }
        }

        // Working days for conflict scanning scope
        $defaultDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $workingDays = isset($settings['Working_days']) && is_array($settings['Working_days'])
            ? $settings['Working_days'] : $defaultDays;

        $settingsClean = [
            'start_time'       => $settings['Start_time'] ?? '9:00AM',
            'end_time'         => $settings['End_time'] ?? '3:00PM',
            'no_of_periods'    => (int)($settings['No_of_periods'] ?? 6),
            'length_of_period' => (float)($settings['Length_of_period'] ?? 45),
            'recesses'         => $recesses,
            'working_days'     => $workingDays,
        ];

        // 2. All class-section timetables
        $classes    = $this->_get_session_classes();
        $timetables = [];

        foreach ($classes as $cls) {
            $key   = $cls['class_key'];
            $sec   = $cls['section'];
            $label = $cls['label'];
            $path  = "Schools/{$school}/{$session}/{$key}/Section {$sec}/Time_table";
            $tt    = $this->firebase->get($path);
            $timetables[$label] = is_array($tt) ? $tt : [];
        }

        // 3. Subject assignments for period-limit conflict checks (client-side)
        $assignPath = "Schools/{$school}/{$session}/Academic/Subject_Assignments";
        $assignments = $this->firebase->get($assignPath) ?? [];
        if (!is_array($assignments)) $assignments = [];

        return $this->json_success([
            'settings'            => $settingsClean,
            'timetables'          => $timetables,
            'classes'             => $classes,
            'subject_assignments' => $assignments,
        ]);
    }

    public function save_period()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'edit_timetable');
        $classKey   = $this->safe_path_segment(trim($this->input->post('class_key') ?? ''), 'class_key');
        $section    = $this->safe_path_segment(trim($this->input->post('section') ?? ''), 'section');
        $day        = trim($this->input->post('day') ?? '');
        $periodIdx  = (int)($this->input->post('period_index') ?? -1);
        $subject    = trim($this->input->post('subject') ?? '');
        $teacherId  = trim($this->input->post('teacher_id') ?? '');
        $teacherName = trim($this->input->post('teacher_name') ?? '');

        $validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        if (empty($classKey) || empty($section) || !in_array($day, $validDays) || $periodIdx < 0) {
            return $this->json_error('Missing or invalid parameters');
        }

        $school  = $this->school_name;
        $session = $this->session_year;

        // ── CONFLICT CHECKS (before any write) ──

        $warnings = [];

        if ($subject !== '') {
            $classes = $this->_get_session_classes();

            // 1. Teacher conflict: same teacher in same day+period in another class (HARD BLOCK)
            if ($teacherId !== '') {
                foreach ($classes as $cls) {
                    if ($cls['class_key'] === $classKey && $cls['section'] === $section) continue;
                    $otherPath = "Schools/{$school}/{$session}/{$cls['class_key']}/Section {$cls['section']}/Time_table/{$day}";
                    $otherPeriods = $this->firebase->get($otherPath);
                    if (!is_array($otherPeriods)) continue;
                    $otherCell = $otherPeriods[$periodIdx] ?? '';
                    $otherTeacherId = is_array($otherCell) ? ($otherCell['teacher_id'] ?? '') : '';
                    if ($otherTeacherId !== '' && $otherTeacherId === $teacherId) {
                        return $this->json_error(
                            "Teacher conflict: {$teacherName} is already assigned to {$cls['label']} on {$day} period " . ($periodIdx + 1) . ". Remove that assignment first."
                        );
                    }
                }
            }

            // 2. Duplicate subject in same class on same day (WARNING only — double periods are valid)
            $dayPath = "Schools/{$school}/{$session}/{$classKey}/Section {$section}/Time_table/{$day}";
            $dayTt   = $this->firebase->get($dayPath) ?? [];
            if (!is_array($dayTt)) $dayTt = [];

            $subjectCountToday = 0;
            foreach ($dayTt as $i => $cell) {
                if ((int)$i === $periodIdx) continue; // skip the cell being edited
                $cellSub = is_array($cell) ? ($cell['subject'] ?? '') : (string)$cell;
                if ($cellSub !== '' && strcasecmp($cellSub, $subject) === 0) {
                    $subjectCountToday++;
                }
            }
            if ($subjectCountToday > 0) {
                $warnings[] = "{$subject} already appears {$subjectCountToday} other time(s) on {$day}";
            }

            // 3. Weekly period-limit check from Subject_Assignments
            $fbClassKey = $this->_class_to_firebase_key($classKey);
            $assignData = $this->firebase->get(
                "Schools/{$school}/{$session}/Academic/Subject_Assignments/{$fbClassKey}"
            );
            if (is_array($assignData)) {
                // Find the limit for this subject
                $limit = 0;
                foreach ($assignData as $code => $info) {
                    if (!is_array($info)) continue;
                    $aName = $info['name'] ?? '';
                    if (strcasecmp($aName, $subject) === 0) {
                        $limit = (int)($info['periods_week'] ?? 0);
                        break;
                    }
                }

                if ($limit > 0) {
                    // Count this subject across all days for this class-section
                    $ttPath = "Schools/{$school}/{$session}/{$classKey}/Section {$section}/Time_table";
                    $fullTt = $this->firebase->get($ttPath) ?? [];
                    $weekCount = 0;
                    if (is_array($fullTt)) {
                        foreach ($fullTt as $d => $periods) {
                            if (!is_array($periods)) continue;
                            foreach ($periods as $pi => $cell) {
                                // Skip the cell being edited (it will be replaced)
                                if ($d === $day && (int)$pi === $periodIdx) continue;
                                $cellSub = is_array($cell) ? ($cell['subject'] ?? '') : (string)$cell;
                                if ($cellSub !== '' && strcasecmp($cellSub, $subject) === 0) {
                                    $weekCount++;
                                }
                            }
                        }
                    }
                    $newTotal = $weekCount + 1; // +1 for the period being saved
                    if ($newTotal > $limit) {
                        $warnings[] = "{$subject} will have {$newTotal} periods/week (limit: {$limit})";
                    }
                }
            }
        } else {
            // Clearing a cell — just read the day
            $dayPath = "Schools/{$school}/{$session}/{$classKey}/Section {$section}/Time_table/{$day}";
            $dayTt   = $this->firebase->get($dayPath) ?? [];
            if (!is_array($dayTt)) $dayTt = [];
        }

        // ── WRITE ──

        // Pad array if needed
        while (count($dayTt) <= $periodIdx) {
            $dayTt[] = '';
        }

        // Store as object {subject, teacher_id, teacher_name} when teacher_id provided,
        // or plain string for backward compat when no teacher specified
        if ($subject === '') {
            $dayTt[$periodIdx] = '';
        } elseif ($teacherId !== '') {
            $dayTt[$periodIdx] = [
                'subject'      => $subject,
                'teacher_id'   => $teacherId,
                'teacher_name' => $teacherName,
            ];
        } else {
            $dayTt[$periodIdx] = $subject;
        }

        // Write only the specific day back (narrower write = less race risk)
        $dayPath = "Schools/{$school}/{$session}/{$classKey}/Section {$section}/Time_table/{$day}";
        $this->firebase->set($dayPath, $dayTt);

        return $this->json_success([
            'day'          => $day,
            'period_index' => $periodIdx,
            'subject'      => $subject,
            'teacher_id'   => $teacherId,
            'teacher_name' => $teacherName,
            'warnings'     => $warnings,
        ]);
    }

    /**
     * Pre-save conflict check for a single cell.
     *
     * Checks: (1) teacher double-booked in another class for same day/period,
     *         (2) weekly period-limit exceeded for the subject in this class.
     *
     * Returns {conflict:bool, type, message, severity} or {conflict:false, warnings:[]}.
     */
    public function detect_conflicts()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_timetable');
        $subject   = trim($this->input->post('subject') ?? '');
        $teacherId = trim($this->input->post('teacher_id') ?? '');
        $day       = trim($this->input->post('day') ?? '');
        $periodIdx = (int)($this->input->post('period_index') ?? -1);
        $excludeClass   = trim($this->input->post('exclude_class') ?? '');
        $excludeSection = trim($this->input->post('exclude_section') ?? '');

        if ((empty($subject) && empty($teacherId)) || empty($day) || $periodIdx < 0) {
            return $this->json_success(['conflict' => false, 'warnings' => []]);
        }

        $school  = $this->school_name;
        $session = $this->session_year;
        $classes = $this->_get_session_classes();
        $warnings = [];

        // ── 1. Teacher conflict (HARD — blocks save) ──
        foreach ($classes as $cls) {
            if ($cls['class_key'] === $excludeClass && $cls['section'] === $excludeSection) continue;

            $path = "Schools/{$school}/{$session}/{$cls['class_key']}/Section {$cls['section']}/Time_table/{$day}";
            $periods = $this->firebase->get($path);
            if (!is_array($periods)) continue;

            $cell = $periods[$periodIdx] ?? '';
            $cellTeacherId = is_array($cell) ? ($cell['teacher_id'] ?? '') : '';

            if ($teacherId !== '' && $cellTeacherId !== '' && $cellTeacherId === $teacherId) {
                return $this->json_success([
                    'conflict' => true,
                    'type'     => 'teacher',
                    'severity' => 'error',
                    'message'  => "Teacher conflict: already assigned to {$cls['label']} on {$day} P" . ($periodIdx + 1),
                ]);
            }
        }

        // ── 2. Duplicate subject same day (SOFT warning) ──
        if ($excludeClass !== '' && $subject !== '') {
            $ownPath = "Schools/{$school}/{$session}/{$excludeClass}/Section {$excludeSection}/Time_table/{$day}";
            $ownPeriods = $this->firebase->get($ownPath);
            if (is_array($ownPeriods)) {
                $dupCount = 0;
                foreach ($ownPeriods as $i => $cell) {
                    if ((int)$i === $periodIdx) continue;
                    $cellSub = is_array($cell) ? ($cell['subject'] ?? '') : (string)$cell;
                    if ($cellSub !== '' && strcasecmp($cellSub, $subject) === 0) $dupCount++;
                }
                if ($dupCount > 0) {
                    $warnings[] = [
                        'type'    => 'duplicate',
                        'message' => "{$subject} already has {$dupCount} other period(s) on {$day}",
                    ];
                }
            }
        }

        // ── 3. Weekly period-limit from Subject_Assignments (SOFT warning) ──
        if ($excludeClass !== '' && $subject !== '') {
            $fbKey = $this->_class_to_firebase_key($excludeClass);
            $assignData = $this->firebase->get(
                "Schools/{$school}/{$session}/Academic/Subject_Assignments/{$fbKey}"
            );
            if (is_array($assignData)) {
                $limit = 0;
                foreach ($assignData as $code => $info) {
                    if (!is_array($info)) continue;
                    if (strcasecmp($info['name'] ?? '', $subject) === 0) {
                        $limit = (int)($info['periods_week'] ?? 0);
                        break;
                    }
                }
                if ($limit > 0) {
                    $ttPath = "Schools/{$school}/{$session}/{$excludeClass}/Section {$excludeSection}/Time_table";
                    $fullTt = $this->firebase->get($ttPath) ?? [];
                    $weekCount = 0;
                    if (is_array($fullTt)) {
                        foreach ($fullTt as $d => $pds) {
                            if (!is_array($pds)) continue;
                            foreach ($pds as $pi => $cell) {
                                if ($d === $day && (int)$pi === $periodIdx) continue;
                                $cellSub = is_array($cell) ? ($cell['subject'] ?? '') : (string)$cell;
                                if ($cellSub !== '' && strcasecmp($cellSub, $subject) === 0) $weekCount++;
                            }
                        }
                    }
                    $newTotal = $weekCount + 1;
                    if ($newTotal > $limit) {
                        $warnings[] = [
                            'type'    => 'period_limit',
                            'message' => "{$subject}: {$newTotal} periods/week exceeds limit of {$limit}",
                        ];
                    }
                }
            }
        }

        return $this->json_success(['conflict' => false, 'warnings' => $warnings]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       SUBSTITUTE MANAGEMENT
    ══════════════════════════════════════════════════════════════════════ */

    public function get_substitutes()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_substitutes');
        $date     = trim($this->input->post('date') ?? '');     // YYYY-MM-DD
        $dateFrom = trim($this->input->post('date_from') ?? ''); // range start
        $dateTo   = trim($this->input->post('date_to') ?? '');   // range end
        $path     = "Schools/{$this->school_name}/{$this->session_year}/Academic/Substitutes";
        $raw      = $this->firebase->get($path) ?? [];

        $records = [];
        if (is_array($raw)) {
            foreach ($raw as $id => $rec) {
                if (!is_array($rec)) continue;
                $recDate = $rec['date'] ?? '';
                // Single date filter
                if ($date !== '' && $recDate !== $date) continue;
                // Range filter
                if ($dateFrom !== '' && $recDate < $dateFrom) continue;
                if ($dateTo !== '' && $recDate > $dateTo) continue;
                $rec['id'] = $id;
                $records[] = $rec;
            }
        }

        usort($records, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

        // Default: limit to 100 most recent if no filter provided
        if ($date === '' && $dateFrom === '' && $dateTo === '') {
            $records = array_slice($records, 0, 100);
        }

        return $this->json_success(['substitutes' => $records]);
    }

    public function save_substitute()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes');
        $id             = trim($this->input->post('id') ?? '');
        $dateStart      = trim($this->input->post('date') ?? '');
        $dateEnd        = trim($this->input->post('date_end') ?? '') ?: $dateStart;
        $absentId       = trim($this->input->post('absent_teacher_id') ?? '');
        $absentName     = trim($this->input->post('absent_teacher_name') ?? '');
        $substituteId   = trim($this->input->post('substitute_teacher_id') ?? '');
        $substituteName = trim($this->input->post('substitute_teacher_name') ?? '');
        $classSection   = $this->safe_path_segment(trim($this->input->post('class_section') ?? ''), 'class_section');
        $periodsRaw     = $this->input->post('periods');
        $subject        = trim($this->input->post('subject') ?? '');
        $reason         = trim($this->input->post('reason') ?? '');

        // ── Required field validation ──
        if (empty($dateStart) || empty($absentId) || empty($substituteId) || empty($classSection)) {
            return $this->json_error('Date, both teachers, and class are required');
        }

        // ── Date format validation ──
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart) || !strtotime($dateStart)) {
            return $this->json_error('Invalid start date format');
        }
        if ($dateEnd !== $dateStart && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd) || !strtotime($dateEnd))) {
            return $this->json_error('Invalid end date format');
        }
        if ($dateEnd < $dateStart) {
            return $this->json_error('End date cannot be before start date');
        }

        // ── Same teacher check ──
        if ($absentId === $substituteId) {
            return $this->json_error('Absent teacher and substitute cannot be the same person');
        }

        // ── Period validation ──
        $periods = is_string($periodsRaw) ? json_decode($periodsRaw, true) : $periodsRaw;
        if (!is_array($periods)) $periods = [];
        $periods = array_values(array_unique(array_filter(array_map('intval', $periods), fn($p) => $p >= 1)));

        // Validate against timetable settings
        $settings = $this->firebase->get("Schools/{$this->school_name}/{$this->session_year}/Time_table_settings") ?? [];
        $maxPeriods = (int)($settings['No_of_periods'] ?? 10);
        $periods = array_filter($periods, fn($p) => $p <= $maxPeriods);
        sort($periods);

        if (empty($periods)) {
            return $this->json_error('At least one valid period is required (max: ' . $maxPeriods . ')');
        }

        $basePath = "Schools/{$this->school_name}/{$this->session_year}/Academic/Substitutes";

        // ── Duplicate / conflict detection ──
        $existing = $this->firebase->get($basePath) ?? [];
        if (is_array($existing)) {
            foreach ($existing as $exId => $ex) {
                if (!is_array($ex)) continue;
                if ($id !== '' && $exId === $id) continue; // skip self on edit
                if (($ex['status'] ?? '') === 'cancelled') continue;

                $exDate = $ex['date'] ?? '';
                $exDateEnd = $ex['date_end'] ?? $exDate;

                // Check date overlap
                if ($dateStart > $exDateEnd || $dateEnd < $exDate) continue;

                $exPeriods = is_array($ex['periods'] ?? null) ? $ex['periods'] : [];

                // Check 1: Same absent teacher, same class, overlapping periods
                if (($ex['absent_teacher_id'] ?? '') === $absentId
                    && ($ex['class_section'] ?? '') === $classSection
                    && !empty(array_intersect($periods, $exPeriods))) {
                    return $this->json_error('A substitute is already assigned for this teacher, class, and periods on overlapping dates (ID: ' . $exId . ')');
                }

                // Check 2: Substitute teacher double-booked
                if (($ex['substitute_teacher_id'] ?? '') === $substituteId
                    && !empty(array_intersect($periods, $exPeriods))) {
                    return $this->json_error($substituteName . ' is already covering another class during period(s) ' . implode(',', array_intersect($periods, $exPeriods)) . ' on overlapping dates');
                }
            }
        }

        $adminName = $this->session->userdata('admin_name') ?? 'Admin';

        $data = [
            'date'                    => $dateStart,
            'date_end'                => $dateEnd,
            'absent_teacher_id'       => $absentId,
            'absent_teacher_name'     => $absentName,
            'substitute_teacher_id'   => $substituteId,
            'substitute_teacher_name' => $substituteName,
            'class_section'           => $classSection,
            'periods'                 => $periods,
            'subject'                 => $subject,
            'reason'                  => $reason,
            'updated_at'              => date('Y-m-d H:i:s'),
            'updated_by'              => $adminName,
        ];

        if ($id !== '') {
            // Preserve original status on edit
            $current = $this->firebase->get("{$basePath}/{$id}");
            $data['status'] = is_array($current) ? ($current['status'] ?? 'assigned') : 'assigned';
            $data['created_at'] = is_array($current) ? ($current['created_at'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s');
            $data['created_by'] = is_array($current) ? ($current['created_by'] ?? $adminName) : $adminName;
            $this->firebase->set("{$basePath}/{$id}", $data);
        } else {
            $data['status']     = 'assigned';
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['created_by'] = $adminName;
            $id = $this->firebase->push($basePath, $data);
        }

        return $this->json_success(['id' => $id]);
    }

    public function update_substitute()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes');
        $id     = trim($this->input->post('id') ?? '');
        $status = trim($this->input->post('status') ?? '');

        if (empty($id)) return $this->json_error('Substitute ID required');
        if (!in_array($status, ['assigned', 'completed', 'cancelled'])) {
            return $this->json_error('Invalid status');
        }

        $adminName = $this->session->userdata('admin_name') ?? 'Admin';
        $path = "Schools/{$this->school_name}/{$this->session_year}/Academic/Substitutes/{$id}";
        $this->firebase->update($path, [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => $adminName,
        ]);

        return $this->json_success(['id' => $id, 'status' => $status]);
    }

    public function delete_substitute()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'manage_substitutes');
        $id = trim($this->input->post('id') ?? '');
        if (empty($id)) return $this->json_error('Substitute ID required');

        $this->firebase->delete(
            "Schools/{$this->school_name}/{$this->session_year}/Academic/Substitutes", $id
        );

        return $this->json_success([]);
    }

    /**
     * Get a teacher's timetable schedule for substitute availability check
     */
    public function get_teacher_schedule()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_schedule');
        $teacherId = trim($this->input->post('teacher_id') ?? '');
        $date      = trim($this->input->post('date') ?? '');

        if (empty($teacherId)) return $this->json_error('Teacher ID required');

        $school  = $this->school_name;
        $session = $this->session_year;

        // Determine day of week from date
        $dayName = '';
        if ($date !== '' && strtotime($date)) {
            $dayName = date('l', strtotime($date)); // "Monday", "Tuesday", etc.
        }

        $classes  = $this->_get_session_classes();
        $schedule = [];

        // Also check existing substitute assignments for this date
        $basePath = "Schools/{$school}/{$session}/Academic/Substitutes";
        $allSubs  = $this->firebase->get($basePath) ?? [];
        $busyPeriods = []; // periods where this teacher is already covering

        if (is_array($allSubs)) {
            foreach ($allSubs as $sId => $sub) {
                if (!is_array($sub)) continue;
                if (($sub['status'] ?? '') === 'cancelled') continue;
                $subDate    = $sub['date'] ?? '';
                $subDateEnd = $sub['date_end'] ?? $subDate;
                if ($date < $subDate || $date > $subDateEnd) continue;
                if (($sub['substitute_teacher_id'] ?? '') === $teacherId) {
                    $busyPeriods = array_merge($busyPeriods, is_array($sub['periods'] ?? null) ? $sub['periods'] : []);
                }
            }
        }
        $busyPeriods = array_unique($busyPeriods);

        // Get timetable settings for period count
        $settings   = $this->firebase->get("Schools/{$school}/{$session}/Time_table_settings") ?? [];
        $maxPeriods = (int)($settings['No_of_periods'] ?? 6);

        return $this->json_success([
            'teacher_id'   => $teacherId,
            'date'         => $date,
            'day'          => $dayName,
            'busy_periods' => array_values($busyPeriods),
            'max_periods'  => $maxPeriods,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PERIOD SCHEDULING  (timings, periods, recesses, working days)
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Get timetable settings + working days for the current session
     */
    public function get_timetable_settings()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_tt_settings');

        $path     = "Schools/{$this->school_name}/{$this->session_year}/Time_table_settings";
        $settings = $this->firebase->get($path) ?? [];
        if (!is_array($settings)) $settings = [];

        // Normalize recesses (handle both new and legacy format)
        $recesses = [];
        if (isset($settings['Recesses']) && is_array($settings['Recesses'])) {
            foreach ($settings['Recesses'] as $r) {
                if (is_array($r) && isset($r['after_period'], $r['duration'])) {
                    $recesses[] = ['after_period' => (int)$r['after_period'], 'duration' => (int)$r['duration']];
                }
            }
        } elseif (isset($settings['Recess_breaks']) && is_array($settings['Recess_breaks'])) {
            // Legacy backward compat
            foreach ($settings['Recess_breaks'] as $range) {
                if (!is_string($range) || strpos($range, '-') === false) continue;
                $parts = array_map('trim', explode('-', $range));
                $fromMin = $this->_time_to_minutes($parts[0]);
                $toMin   = $this->_time_to_minutes($parts[1]);
                if ($toMin > $fromMin) {
                    $recesses[] = ['after_period' => null, 'duration' => $toMin - $fromMin];
                }
            }
        }

        // Working days (default Mon-Sat)
        $defaultDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $workingDays = isset($settings['Working_days']) && is_array($settings['Working_days'])
            ? $settings['Working_days'] : $defaultDays;

        return $this->json_success([
            'start_time'       => $settings['Start_time'] ?? '9:00AM',
            'end_time'         => $settings['End_time'] ?? '3:00PM',
            'no_of_periods'    => (int)($settings['No_of_periods'] ?? 6),
            'length_of_period' => (float)($settings['Length_of_period'] ?? 45),
            'recesses'         => $recesses,
            'working_days'     => $workingDays,
        ]);
    }

    /**
     * Save period scheduling (periods, times, recesses, working days)
     */
    public function save_timetable_settings()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'save_tt_settings');

        $startRaw = trim($this->input->post('start_time') ?? ''); // HH:mm (24h)
        $endRaw   = trim($this->input->post('end_time') ?? '');   // HH:mm (24h)
        $periods  = (int)($this->input->post('no_of_periods') ?? 0);
        $recessRaw = $this->input->post('recesses') ?? '[]';
        $recesses  = is_string($recessRaw) ? json_decode($recessRaw, true) : $recessRaw;
        if (!is_array($recesses)) $recesses = [];

        // Working days
        $daysRaw     = $this->input->post('working_days') ?? '[]';
        $workingDays = is_string($daysRaw) ? json_decode($daysRaw, true) : $daysRaw;
        $allDays     = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        if (!is_array($workingDays) || empty($workingDays)) {
            $workingDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        }
        $workingDays = array_values(array_intersect($workingDays, $allDays));

        if (empty($startRaw) || empty($endRaw) || $periods <= 0) {
            return $this->json_error('Start time, end time, and number of periods are required');
        }

        $startMin = $this->_time_to_minutes($startRaw);
        $endMin   = $this->_time_to_minutes($endRaw);

        if ($endMin <= $startMin) {
            return $this->json_error('End time must be after start time');
        }

        // Validate and total recess minutes
        $cleanRecesses = [];
        $recessMinutes = 0;
        foreach ($recesses as $r) {
            if (!is_array($r)) continue;
            $dur   = (int)($r['duration'] ?? 0);
            $after = (int)($r['after_period'] ?? 0);
            if ($dur > 0 && $after > 0 && $after < $periods) {
                $cleanRecesses[] = ['after_period' => $after, 'duration' => $dur];
                $recessMinutes += $dur;
            }
        }

        $available = $endMin - $startMin - $recessMinutes;
        if ($available <= 0) {
            return $this->json_error('Recess duration exceeds available time');
        }

        $periodLength = round($available / $periods, 1);

        // Convert 24h to AM/PM for Firebase storage (matches existing format)
        $startAmPm = $this->_to_ampm($startRaw);
        $endAmPm   = $this->_to_ampm($endRaw);

        $data = [
            'Start_time'       => $startAmPm,
            'End_time'         => $endAmPm,
            'No_of_periods'    => $periods,
            'Length_of_period'  => $periodLength,
            'Recesses'         => array_values($cleanRecesses),
            'Working_days'     => $workingDays,
        ];

        $path = "Schools/{$this->school_name}/{$this->session_year}/Time_table_settings";
        $this->firebase->set($path, $data);

        return $this->json_success(['length_of_period' => $periodLength, 'settings' => $data]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PRIVATE HELPERS
    ══════════════════════════════════════════════════════════════════════ */

    private function _time_to_minutes(string $time): int
    {
        // Accept both "HH:mm" (24h) and "h:mmAM/PM" formats
        $time = strtoupper(trim($time));
        // Try AM/PM first
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/', $time, $m)) {
            $h = (int)$m[1];
            $min = (int)$m[2];
            if ($m[3] === 'PM' && $h !== 12) $h += 12;
            if ($m[3] === 'AM' && $h === 12) $h = 0;
            return $h * 60 + $min;
        }
        // 24h format
        if (strpos($time, ':') !== false) {
            $parts = explode(':', $time);
            return ((int)$parts[0] * 60) + (int)$parts[1];
        }
        return 0;
    }

    private function _to_ampm(string $time24): string
    {
        $dt = \DateTime::createFromFormat('H:i', trim($time24));
        return $dt ? $dt->format('g:iA') : $time24;
    }

    /* ══════════════════════════════════════════════════════════════════════
       SECTION-LEVEL TIMETABLE ENDPOINTS
       Used by section_students.php for single class-section timetable view
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Get timetable for a single class-section.
     * POST: class_name, section_name
     */
    public function get_section_timetable()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'view_timetable');

        $class   = $this->safe_path_segment(trim($this->input->post('class_name') ?? ''), 'class_name');
        $section = $this->safe_path_segment(trim($this->input->post('section_name') ?? ''), 'section_name');

        if (empty($class) || empty($section)) {
            return $this->json_error('Class and section are required');
        }

        $path = "Schools/{$this->school_name}/{$this->session_year}/{$class}/{$section}/Time_table";
        $data = $this->firebase->get($path);

        if (is_object($data)) $data = json_decode(json_encode($data), true);

        return $this->json_success(is_array($data) ? $data : []);
    }

    /**
     * Save full timetable for a single class-section (bulk write).
     * POST: class_name, section_name, timetable (JSON string)
     */
    public function save_section_timetable()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator'], 'edit_timetable');

        $class   = $this->safe_path_segment(trim($this->input->post('class_name') ?? ''), 'class_name');
        $section = $this->safe_path_segment(trim($this->input->post('section_name') ?? ''), 'section_name');
        $raw     = $this->input->post('timetable');

        if (empty($class) || empty($section)) {
            return $this->json_error('Class and section are required');
        }

        $timetable = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($timetable)) {
            return $this->json_error('Invalid timetable data');
        }

        // Validate day keys
        $validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $clean = [];
        foreach ($timetable as $day => $periods) {
            if (!in_array($day, $validDays)) continue;
            $clean[$day] = is_array($periods) ? $periods : [];
        }

        $path = "Schools/{$this->school_name}/{$this->session_year}/{$class}/{$section}/Time_table";
        $this->firebase->set($path, !empty($clean) ? $clean : new \stdClass());

        // ── Sync to Firestore 'timetables' collection ──
        try {
            $sn = $this->school_name;
            $sy = $this->session_year;
            $sectionKey = "{$class}/{$section}";

            foreach ($clean as $day => $periods) {
                $docId = "{$sn}_{$sy}_{$sectionKey}_{$day}";
                $periodDocs = [];
                $periodNum = 1;

                foreach ($periods as $key => $entry) {
                    if (!is_array($entry)) continue;
                    $type = strtolower(trim($entry['type'] ?? 'class'));
                    $isBreak = ($type === 'break' || $type === 'lunch'
                        || stripos($key, 'Break') === 0 || strcasecmp($key, 'Lunch') === 0);

                    $periodDocs[] = [
                        'periodNumber' => is_numeric($key) ? intval($key) : $periodNum,
                        'subject'      => $isBreak ? '' : ($entry['subject'] ?? $entry['Subject'] ?? ''),
                        'teacher'      => $isBreak ? '' : ($entry['teacher'] ?? $entry['Teacher'] ?? ''),
                        'teacherId'    => $entry['teacherId'] ?? $entry['teacher_id'] ?? '',
                        'startTime'    => $entry['startTime'] ?? $entry['start_time'] ?? '',
                        'endTime'      => $entry['endTime'] ?? $entry['end_time'] ?? '',
                        'room'         => $entry['room'] ?? $entry['Room'] ?? '',
                        'type'         => $isBreak ? ($type === 'lunch' ? 'lunch' : 'break') : 'class',
                    ];
                    $periodNum++;
                }

                $this->firebase->firestoreSet('timetables', $docId, [
                    'schoolId'   => $sn,
                    'session'    => $sy,
                    'className'  => $class,
                    'section'    => $section,
                    'sectionKey' => $sectionKey,
                    'day'        => $day,
                    'periods'    => $periodDocs,
                    'updatedAt'  => date('c'),
                ]);
            }
        } catch (\Exception $e) {
            log_message('error', "save_section_timetable: Firestore sync failed: " . $e->getMessage());
        }

        return $this->json_success(['message' => 'Timetable saved successfully']);
    }

    /**
     * Get subjects for a given class (for timetable subject picker).
     * POST: class_name (e.g. "Class 8th")
     * Returns: {class_subjects: [{name,code}], all_subjects: [{name,code}]}
     */
    public function get_class_subjects()
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Academic Coordinator', 'Teacher'], 'academic_data');

        $className = trim($this->input->post('class_name') ?? '');

        if (!$className) {
            return $this->json_success(['class_subjects' => [], 'all_subjects' => []]);
        }

        // Extract numeric class key or text key (Nursery/LKG/UKG)
        $requestedKey = null;
        if (preg_match('/(\d+)/', $className, $m)) {
            $requestedKey = (int)$m[1];
        } elseif (preg_match('/(Nursery|LKG|UKG)/i', $className, $m2)) {
            $requestedKey = ucfirst(strtolower($m2[1]));
        }

        $path = "Schools/{$this->school_name}/Subject_list";
        $data = $this->firebase->get($path);
        if (is_object($data)) $data = (array)$data;
        if (!is_array($data)) $data = [];

        $classSubjects = [];
        $allSubjects   = [];

        foreach ($data as $classNum => $subjects) {
            if (is_object($subjects)) $subjects = (array)$subjects;
            if (!is_array($subjects) || empty($subjects)) continue;

            foreach ($subjects as $code => $subject) {
                if (is_object($subject)) $subject = (array)$subject;
                $name = trim($subject['subject_name'] ?? $subject['name'] ?? '');
                if (empty($name)) continue;

                $allSubjects[$name] = ['name' => $name, 'code' => (string)$code];

                // Match requested class
                if ($requestedKey !== null) {
                    if (is_int($requestedKey) && is_numeric($classNum) && (int)$classNum === $requestedKey) {
                        $classSubjects[$name] = ['name' => $name, 'code' => (string)$code];
                    } elseif (is_string($requestedKey) && strcasecmp($classNum, $requestedKey) === 0) {
                        $classSubjects[$name] = ['name' => $name, 'code' => (string)$code];
                    }
                }
            }
        }

        return $this->json_success([
            'class_subjects' => array_values($classSubjects),
            'all_subjects'   => array_values($allSubjects),
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PRIVATE HELPERS
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Convert a class key (e.g. "Class 9th") to a Firebase-safe path segment.
     * Config/Classes stores keys like "Class 9th" which are valid Firebase keys.
     */
    private function _class_to_firebase_key(string $key): string
    {
        $key = trim($key);
        // Firebase keys cannot contain . $ # [ ] /
        return str_replace(['.', '$', '#', '[', ']', '/'], '_', $key);
    }

    // _get_session_classes() is inherited from MY_Controller (protected)
}
