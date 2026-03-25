<?php

class NoticeAnnouncement extends MY_Controller
{
    /** Roles for notice management */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Front Office'];

    /** Roles that may view notices */
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher', 'Front Office'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Communication');
    }

    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'notice_view');
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $path    = 'Schools/' . $school_name . '/' . $session_year . '/All Notices';
        $notices = $this->firebase->get($path);

        $data['notices'] = is_array($notices) ? $notices : [];
        $this->load->view('include/header');
        $this->load->view('notice_announcement/list', $data);
        $this->load->view('include/footer');
    }

    // ── Fetch recent notices (called by header bell via AJAX) ─────
    public function fetch_recent_notices()
    {
        // No role check — any authenticated user can read recent notices.
        // MY_Controller already enforces authentication.
        header('Content-Type: application/json');
        echo json_encode($this->getRecentNotices(10));
    }

    // ── Private helper — merges legacy All Notices + Communication/Notices ──
    private function getRecentNotices($limit = 10)
    {
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $noticeList   = [];
        $seenTitles   = []; // de-duplicate dual-written notices

        // ── Source 1: Legacy All Notices ──────────────────────────
        $legacyPath = 'Schools/' . $school_name . '/' . $session_year . '/All Notices';
        $legacy     = $this->firebase->get($legacyPath);
        if (is_array($legacy)) {
            unset($legacy['Count']);
            foreach ($legacy as $id => $n) {
                if (!is_array($n)) continue;
                $ts = $n['Timestamp'] ?? $n['Time_Stamp'] ?? null;
                if ($ts === null) continue;

                $title = trim($n['Title'] ?? '');
                $dedupKey = strtolower($title) . '|' . substr((string)$ts, 0, 16);
                $seenTitles[$dedupKey] = true;

                $noticeList[] = [
                    'id'          => $id,
                    'Title'       => $title,
                    'Description' => $n['Description'] ?? $n['description'] ?? '',
                    'Time_Stamp'  => $ts,
                    'source'      => 'legacy',
                ];
            }
        }

        // ── Source 2: Communication/Notices ───────────────────────
        $commPath = 'Schools/' . $school_name . '/Communication/Notices';
        $commData = $this->firebase->get($commPath);
        if (is_array($commData)) {
            unset($commData['Counter']);
            foreach ($commData as $id => $n) {
                if (!is_array($n)) continue;
                $ts = $n['created_at'] ?? $n['Timestamp'] ?? null;
                if ($ts === null) continue;

                $title = trim($n['title'] ?? $n['Title'] ?? '');
                $dedupKey = strtolower($title) . '|' . substr((string)$ts, 0, 16);

                // Skip if already seen from legacy (dual-written)
                if (isset($seenTitles[$dedupKey])) continue;

                $noticeList[] = [
                    'id'          => $id,
                    'Title'       => $title,
                    'Description' => $n['body'] ?? $n['Description'] ?? '',
                    'Time_Stamp'  => $ts,
                    'source'      => 'communication',
                ];
            }
        }

        // Sort newest first and return top N
        usort($noticeList, fn($a, $b) => strcmp($b['Time_Stamp'], $a['Time_Stamp']));
        return array_slice($noticeList, 0, $limit);
    }

    // ── Search users ──────────────────────────────────────────────
    public function search_users()
    {
        $this->_require_role(self::VIEW_ROLES, 'search_users');
        header('Content-Type: application/json');

        $query        = strtolower(trim($this->input->get('query') ?? ''));
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $results      = [];

        // Admins
        $adminsData = $this->firebase->get("Schools/$school_name/$session_year/Admins");
        if (is_array($adminsData)) {
            foreach ($adminsData as $adminId => $admin) {
                if (!is_array($admin)) continue;
                $name = $admin['Name'] ?? '';
                if (stripos($name, $query) !== false || stripos((string)$adminId, $query) !== false) {
                    $results[] = ['label' => "$name ($adminId)", 'type' => 'Admin',   'id' => $adminId, 'name' => $name];
                }
            }
        }

        // Teachers
        $teachersData = $this->firebase->get("Schools/$school_name/$session_year/Teachers");
        if (is_array($teachersData)) {
            foreach ($teachersData as $teacherId => $teacher) {
                if (!is_array($teacher)) continue;
                $name = $teacher['Name'] ?? '';
                if (stripos($name, $query) !== false || stripos((string)$teacherId, $query) !== false) {
                    $results[] = ['label' => "$name ($teacherId)", 'type' => 'Teacher', 'id' => $teacherId, 'name' => $name];
                }
            }
        }

        // Students — new path: Class 8th / Section A / Students / List
        // We iterate Classes node to find class+section combos
        $schoolData = $this->firebase->get("Schools/$school_name/$session_year");
        if (is_array($schoolData)) {
            foreach ($schoolData as $classKey => $classData) {
                if (!is_array($classData) || stripos($classKey, 'Class ') !== 0) continue;

                // New structure: classData has section keys like "Section A"
                foreach ($classData as $sectionKey => $sectionData) {
                    if (!is_array($sectionData) || stripos($sectionKey, 'Section ') !== 0) continue;

                    $studentList = $sectionData['Students']['List'] ?? null;
                    if (!is_array($studentList)) continue;

                    // Display label uses "Class 8th / Section A" format
                    $classLabel = "$classKey / $sectionKey";

                    foreach ($studentList as $studentId => $studentName) {
                        if (stripos((string)$studentName, $query) !== false ||
                            stripos((string)$studentId,   $query) !== false) {
                            $results[] = [
                                'label' => "$studentName ($studentId) [$classKey|$sectionKey]",
                                'type'  => 'Student',
                                'id'    => $studentId,
                                'name'  => $studentName,
                                'class' => $classLabel,           // display
                                'class_key'   => $classKey,       // "Class 8th"
                                'section_key' => $sectionKey,     // "Section A"
                            ];
                        }
                    }
                }
            }
        }

        echo json_encode($results);
    }

    // ── Create notice ─────────────────────────────────────────────
    public function create_notice()
    {
        $this->_require_role(self::MANAGE_ROLES, 'create_notice');
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $admin_id     = $this->admin_id;

        $base_path = "Schools/{$school_name}/{$session_year}/All Notices";

        // ── Build class list for dropdown ─────────────────────────
        // Read class/section keys directly from the session root (correct path).
        $data['classes'] = [];
        $sessionClassKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session_year}");
        foreach ($sessionClassKeys as $classKey) {
            if (strpos($classKey, 'Class ') !== 0) continue;
            $sectionKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session_year}/{$classKey}");
            foreach ($sectionKeys as $sectionKey) {
                if (strpos($sectionKey, 'Section ') !== 0) continue;
                // Key uses "/" separator: "Class 8th/Section A"
                $data['classes']["{$classKey}/{$sectionKey}"] = "{$classKey} / {$sectionKey}";
            }
        }

        // ── POST handler ──────────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title       = trim($this->input->post('title', TRUE) ?? '');
            $description = trim($this->input->post('description', TRUE) ?? '');
            $to_ids      = [];

            $allowedPriorities  = ['High', 'Normal', 'Low'];
            $allowedCategories  = ['General', 'Academic', 'Administrative', 'Holiday', 'Exam', 'Event'];
            $priority = in_array($this->input->post('priority'), $allowedPriorities, true)
                ? $this->input->post('priority') : 'Normal';
            $category = in_array($this->input->post('category'), $allowedCategories, true)
                ? $this->input->post('category') : 'General';

            if (!empty($this->input->post('to_id_json'))) {
                $to_ids = json_decode($this->input->post('to_id_json'), true) ?? [];
            }

            if (empty($to_ids)) {
                $this->output
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['status' => 'error', 'message' => 'No recipients selected.']));
                return;
            }

            // Create notice node
            $current_data  = $this->firebase->get($base_path);
            $current_count = is_array($current_data) && isset($current_data['Count'])
                ? (int)$current_data['Count'] : 0;
            $notice_id = 'NOT' . str_pad($current_count, 4, '0', STR_PAD_LEFT);

            $new_notice = [
                'Title'       => $title,
                'Description' => $description,
                'From Id'     => $admin_id,
                'From Type'   => 'Admin',
                'Priority'    => $priority,
                'Category'    => $category,
                'Timestamp'   => [".sv" => "timestamp"],
                'To Id'       => [],
            ];
            $this->firebase->set("{$base_path}/{$notice_id}", $new_notice);
            $this->firebase->set("{$base_path}/Count", $current_count + 1);

            // Wait for Firebase server timestamp to resolve
            usleep(500000);

            $stored_notice   = $this->firebase->get("{$base_path}/{$notice_id}");
            $actualTimestamp = (is_array($stored_notice) && isset($stored_notice['Timestamp']))
                ? $stored_notice['Timestamp']
                : round(microtime(true) * 1000);

            $sanitized_to_ids = [];

            foreach ($to_ids as $key => $label) {
                log_message('debug', "create_notice: key=$key label=$label");

                // ── Class/Section format: "Class 8th/Section A" ───
                if (!preg_match('/^(STU|TEA|ADM|All)/', $key) && strpos($key, '/Section ') !== false) {
                    $parts       = explode('/', $key, 2);
                    $classNode   = trim($parts[0]);   // "Class 8th"
                    $sectionNode = trim($parts[1]);   // "Section A"

                    $classPath = "Schools/{$school_name}/{$session_year}/{$classNode}/{$sectionNode}/Notification/{$notice_id}";
                    $this->firebase->set($classPath, $actualTimestamp);
                    // Store in To Id with pipe separator (Firebase-safe: no slashes in keys)
                    $sanitized_to_ids["{$classNode}|{$sectionNode}"] = "";
                    log_message('debug', "create_notice: class path=$classPath");
                }

                // ── Student ───────────────────────────────────────
                elseif (preg_match('/^STU[0-9]+$/', $key)) {
                    // Label format: "Name (STU0005) [Class 8th|Section A]"
                    if (preg_match('/\[(.*?)\|(.*?)\]/', $label, $m)) {
                        $classNode   = trim($m[1]);  // "Class 8th"
                        $sectionNode = trim($m[2]);  // "Section A"
                        $studentPath = "Schools/{$school_name}/{$session_year}/{$classNode}/{$sectionNode}/Students/{$key}/Notification/{$notice_id}";
                        $this->firebase->set($studentPath, $actualTimestamp);
                        log_message('debug', "create_notice: student path=$studentPath");
                    } else {
                        log_message('error', "create_notice: cannot parse class from label: $label");
                    }
                    $sanitized_to_ids[$key] = "";
                }

                // ── Individual Teacher (STA prefix) ──────────────
                elseif (preg_match('/^STA[A-Za-z0-9]+$/', $key)) {
                    $this->firebase->set(
                        "Schools/{$school_name}/{$session_year}/Teachers/{$key}/Received/{$notice_id}",
                        $actualTimestamp
                    );
                    $sanitized_to_ids[$key] = "";
                }

                // ── Admin ─────────────────────────────────────────
                elseif (preg_match('/^ADM[0-9]+$/', $key)) {
                    if ($key !== $admin_id) {
                        $this->firebase->set(
                            "Schools/{$school_name}/{$session_year}/Admins/{$key}/Received/{$notice_id}",
                            $actualTimestamp
                        );
                    }
                    $sanitized_to_ids[$key] = "";
                }

                // ── All Students ──────────────────────────────────
                elseif ($key === 'All Students') {
                    // 1. Announcements node (app reads this for bulk push)
                    $this->firebase->set(
                        "Schools/{$school_name}/{$session_year}/Announcements/All Students/{$notice_id}",
                        $actualTimestamp
                    );
                    // 2. Each class → section Notification node
                    $sessionKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session_year}");
                    foreach ((array)$sessionKeys as $classKey) {
                        if (strpos($classKey, 'Class ') !== 0) continue;
                        $sectionKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session_year}/{$classKey}");
                        foreach ((array)$sectionKeys as $sectionKey) {
                            if (strpos($sectionKey, 'Section ') !== 0) continue;
                            $this->firebase->set(
                                "Schools/{$school_name}/{$session_year}/{$classKey}/{$sectionKey}/Notification/{$notice_id}",
                                $actualTimestamp
                            );
                        }
                    }
                    $sanitized_to_ids[$key] = "";
                }

                // ── All Teachers ──────────────────────────────────
                elseif ($key === 'All Teachers') {
                    // 1. Announcements node
                    $this->firebase->set(
                        "Schools/{$school_name}/{$session_year}/Announcements/All Teachers/{$notice_id}",
                        $actualTimestamp
                    );
                    // 2. Each teacher's Received node
                    $allTeachers = $this->firebase->get("Schools/{$school_name}/{$session_year}/Teachers");
                    if (is_array($allTeachers)) {
                        foreach ($allTeachers as $tid => $tData) {
                            if (!is_array($tData)) continue;
                            $this->firebase->set(
                                "Schools/{$school_name}/{$session_year}/Teachers/{$tid}/Received/{$notice_id}",
                                $actualTimestamp
                            );
                        }
                    }
                    $sanitized_to_ids[$key] = "";
                }

                // ── All Admins ────────────────────────────────────
                elseif ($key === 'All Admins') {
                    // 1. Announcements node
                    $this->firebase->set(
                        "Schools/{$school_name}/{$session_year}/Announcements/All Admins/{$notice_id}",
                        $actualTimestamp
                    );
                    // 2. Each admin's Received node (skip sender)
                    $allAdmins = $this->firebase->get("Schools/{$school_name}/{$session_year}/Admins");
                    if (is_array($allAdmins)) {
                        foreach ($allAdmins as $aid => $aData) {
                            if (!is_array($aData) || $aid === $admin_id) continue;
                            $this->firebase->set(
                                "Schools/{$school_name}/{$session_year}/Admins/{$aid}/Received/{$notice_id}",
                                $actualTimestamp
                            );
                        }
                    }
                    $sanitized_to_ids[$key] = "";
                }

                // ── All School ────────────────────────────────────
                elseif ($key === 'All School') {
                    // 1. Announcements node
                    $this->firebase->set(
                        "Schools/{$school_name}/{$session_year}/Announcements/All School/{$notice_id}",
                        $actualTimestamp
                    );
                    // 2. All class/section Notification nodes
                    $sessionKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session_year}");
                    foreach ((array)$sessionKeys as $classKey) {
                        if (strpos($classKey, 'Class ') !== 0) continue;
                        $sectionKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session_year}/{$classKey}");
                        foreach ((array)$sectionKeys as $sectionKey) {
                            if (strpos($sectionKey, 'Section ') !== 0) continue;
                            $this->firebase->set(
                                "Schools/{$school_name}/{$session_year}/{$classKey}/{$sectionKey}/Notification/{$notice_id}",
                                $actualTimestamp
                            );
                        }
                    }
                    // 3. All teachers' Received nodes
                    $allTeachers = $this->firebase->get("Schools/{$school_name}/{$session_year}/Teachers");
                    if (is_array($allTeachers)) {
                        foreach ($allTeachers as $tid => $tData) {
                            if (!is_array($tData)) continue;
                            $this->firebase->set(
                                "Schools/{$school_name}/{$session_year}/Teachers/{$tid}/Received/{$notice_id}",
                                $actualTimestamp
                            );
                        }
                    }
                    // 4. All admins' Received nodes (skip sender)
                    $allAdmins = $this->firebase->get("Schools/{$school_name}/{$session_year}/Admins");
                    if (is_array($allAdmins)) {
                        foreach ($allAdmins as $aid => $aData) {
                            if (!is_array($aData) || $aid === $admin_id) continue;
                            $this->firebase->set(
                                "Schools/{$school_name}/{$session_year}/Admins/{$aid}/Received/{$notice_id}",
                                $actualTimestamp
                            );
                        }
                    }
                    $sanitized_to_ids[$key] = "";
                }

                // ── Fallback (unknown key) ────────────────────────
                else {
                    log_message('error', "create_notice: unhandled recipient key=$key");
                }
            }

            // Sender's Sent log
            $this->firebase->set(
                "Schools/{$school_name}/{$session_year}/Admins/{$admin_id}/Sent/{$notice_id}",
                $actualTimestamp
            );

            // Update notice with final To Id + resolved timestamp
            $this->firebase->update("{$base_path}/{$notice_id}", [
                'To Id'     => $sanitized_to_ids,
                'Timestamp' => $actualTimestamp,
            ]);

            // ── Sync to Firestore 'circulars' collection for mobile apps ──
            try {
                $this->firebase->firestoreSet('circulars', $notice_id, [
                    'schoolId'      => $school_name,
                    'title'         => $title,
                    'body'          => $description,
                    'author'        => $this->admin_name ?? $admin_id,
                    'category'      => $category,
                    'priority'      => $priority,
                    'targetAudience' => array_values(array_map('strval', $sanitized_to_ids)),
                    'attachmentUrl' => '',
                    'sentAt'        => date('c'),
                    'status'        => 'sent',
                ]);
            } catch (\Exception $e) {
                log_message('error', "create_notice: Firestore sync failed [{$notice_id}]: " . $e->getMessage());
            }

            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'success', 'message' => 'Notice sent successfully.']));

        } else {
            // GET — show the form
            $notices          = $this->firebase->get($base_path);
            $data['notices']  = is_array($notices) ? $notices : [];
            $this->load->view('include/header');
            $this->load->view('create_notice', $data);
            $this->load->view('include/footer');
        }
    }

    // ── Delete notice ─────────────────────────────────────────────
    public function delete($id)
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_notice');
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        if ($id === '') {
            redirect('NoticeAnnouncement');
            return;
        }
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $path = 'Schools/' . $school_name . '/' . $session_year . '/All Notices/' . $id;
        $this->firebase->set($path, null);   // FIX: was using $this->firebase_db which doesn't exist
        redirect('NoticeAnnouncement');
    }
}