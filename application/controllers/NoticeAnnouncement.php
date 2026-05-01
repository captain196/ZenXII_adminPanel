<?php

/**
 * NoticeAnnouncement — admin panel notices + announcement fanout.
 *
 * Phase 5 — fully migrated to Firestore. Previously the module dual-
 * wrote to ~8 RTDB nodes per notice (class/section badges, per-
 * teacher / per-admin Received, Sent log, per-audience Announcements
 * bucket) AND to the Firestore `circulars` collection that the Parent
 * and Teacher apps already subscribe to. The RTDB leaves are retired:
 *
 *   notices/{schoolId}_{noticeId}            canonical notice
 *   circulars/{noticeId}                     app-read mirror (existing)
 *   noticeRecipients/{schoolId}_{noticeId}_{recipientKey}
 *                                            one doc per recipient for
 *                                            delivery/read-state. Replaces
 *                                            the RTDB Notification /
 *                                            Received / Sent fanout.
 *
 * Public JSON surface is preserved byte-for-byte so the existing
 * admin view + ajax header bell call sites don't change.
 */
class NoticeAnnouncement extends MY_Controller
{
    /** Roles for notice management */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Front Office'];

    /** Roles that may view notices */
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher', 'Front Office'];

    private const COL_NOTICES           = 'notices';
    private const COL_CIRCULARS         = 'circulars';           // parent/teacher apps already watch this
    private const COL_NOTICE_RECIPIENTS = 'noticeRecipients';

    public function __construct()
    {
        parent::__construct();
        require_permission('Communication');
    }

    // ─── Page: list view ─────────────────────────────────────────────
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'notice_view');
        $data['notices'] = $this->_notices_as_legacy_map();
        $this->load->view('include/header');
        $this->load->view('notice_announcement/list', $data);
        $this->load->view('include/footer');
    }

    // ─── AJAX: header-bell recent notices ────────────────────────────
    public function fetch_recent_notices()
    {
        // Any authenticated admin can read recent notices.
        header('Content-Type: application/json');
        // Release session lock so this runs in parallel with other dashboard fetches.
        if (function_exists('session_write_close')) @session_write_close();
        echo json_encode($this->getRecentNotices(10));
    }

    /**
     * Returns the newest N notices across the school in the legacy
     * shape expected by the header bell ({id,Title,Description,Time_Stamp,source}).
     */
    private function getRecentNotices(int $limit = 10): array
    {
        $rows = $this->firebase->firestoreQuery(self::COL_NOTICES, [
            ['schoolId', '==', $this->school_name],
        ], 'timestamp', 'DESC', $limit);

        $out = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            $out[] = [
                'id'          => (string) ($d['noticeId'] ?? ($r['id'] ?? '')),
                'Title'       => (string) ($d['title']       ?? ''),
                'Description' => (string) ($d['description'] ?? ''),
                'Time_Stamp'  => (string) ($d['timestamp']   ?? $d['createdAt'] ?? ''),
                'source'      => 'firestore',
            ];
        }
        return $out;
    }

    /**
     * Pull every notice for this school+session and return them in the
     * same associative-map shape the legacy view expected
     * (`{ NOT0001: { Title, Description, Timestamp, … }, Count: N }`).
     */
    private function _notices_as_legacy_map(): array
    {
        $rows = $this->firebase->firestoreQuery(self::COL_NOTICES, [
            ['schoolId', '==', $this->school_name],
            ['session',  '==', $this->session_year],
        ], 'timestamp', 'DESC');

        $out = [];
        $count = 0;
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            $id = (string) ($d['noticeId'] ?? ($r['id'] ?? ''));
            if ($id === '') continue;
            $out[$id] = [
                'Title'       => (string) ($d['title']       ?? ''),
                'Description' => (string) ($d['description'] ?? ''),
                'Priority'    => (string) ($d['priority']    ?? 'Normal'),
                'Category'    => (string) ($d['category']    ?? 'General'),
                'From Id'     => (string) ($d['fromId']      ?? ''),
                'From Type'   => (string) ($d['fromType']    ?? 'Admin'),
                'Timestamp'   => (string) ($d['timestamp']   ?? $d['createdAt'] ?? ''),
                'To Id'       => is_array($d['toId'] ?? null) ? $d['toId'] : [],
            ];
            $count++;
        }
        $out['Count'] = $count;
        return $out;
    }

    // ─── AJAX: user search (admins/teachers/students) ────────────────
    public function search_users()
    {
        $this->_require_role(self::VIEW_ROLES, 'search_users');
        header('Content-Type: application/json');

        $query   = strtolower(trim((string) ($this->input->get('query') ?? '')));
        $results = [];

        // Admins — Firestore `admins` collection, auto-scoped via docId pattern.
        $adminRows = $this->firebase->firestoreQuery('admins', [
            ['schoolId', '==', $this->school_name],
        ]);
        foreach ((array) $adminRows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $id   = (string) ($d['adminId'] ?? ($r['id'] ?? ''));
            $name = (string) ($d['name']    ?? $d['Name'] ?? '');
            if ($id === '') continue;
            if ($query === '' || stripos($name, $query) !== false || stripos($id, $query) !== false) {
                $results[] = ['label' => "$name ($id)", 'type' => 'Admin', 'id' => $id, 'name' => $name];
            }
        }

        // Teachers / staff — Firestore `staff` collection.
        $teacherRows = $this->firebase->firestoreQuery('staff', [
            ['schoolId', '==', $this->school_name],
        ]);
        foreach ((array) $teacherRows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $id   = (string) ($d['staffId'] ?? $d['teacherId'] ?? ($r['id'] ?? ''));
            $name = (string) ($d['name']    ?? $d['Name']      ?? '');
            if ($id === '') continue;
            // Only surface teaching staff in notice recipient search.
            $role = strtolower((string) ($d['role'] ?? $d['jobFunction'] ?? ''));
            if ($role !== '' && strpos($role, 'teach') === false && strpos($role, 'coordinator') === false) continue;
            if ($query === '' || stripos($name, $query) !== false || stripos($id, $query) !== false) {
                $results[] = ['label' => "$name ($id)", 'type' => 'Teacher', 'id' => $id, 'name' => $name];
            }
        }

        // Students — Firestore `students` collection, auto-scoped by schoolId.
        $studentRows = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $this->school_name],
        ]);
        foreach ((array) $studentRows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $id   = (string) ($d['studentId'] ?? ($r['id'] ?? ''));
            $name = (string) ($d['name']      ?? $d['studentName'] ?? '');
            if ($id === '') continue;
            if ($query !== '' && stripos($name, $query) === false && stripos($id, $query) === false) continue;
            $classKey   = (string) ($d['className'] ?? '');
            $sectionKey = (string) ($d['section']   ?? '');
            $classLabel = trim("$classKey / $sectionKey");
            $results[] = [
                'label'       => "$name ($id) [{$classKey}|{$sectionKey}]",
                'type'        => 'Student',
                'id'          => $id,
                'name'        => $name,
                'class'       => $classLabel,
                'class_key'   => $classKey,
                'section_key' => $sectionKey,
            ];
        }

        echo json_encode($results);
    }

    // ─── Create notice ───────────────────────────────────────────────
    public function create_notice()
    {
        $this->_require_role(self::MANAGE_ROLES, 'create_notice');
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $admin_id     = $this->admin_id;

        // Class/section dropdown — sourced from Firestore `sections`
        // collection (replaces the legacy shallow_get on RTDB session root).
        $data['classes'] = $this->_class_section_dropdown();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title       = trim((string) $this->input->post('title', TRUE) ?? '');
            $description = trim((string) $this->input->post('description', TRUE) ?? '');
            $to_ids      = [];

            $allowedPriorities = ['High', 'Normal', 'Low'];
            $allowedCategories = ['General', 'Academic', 'Administrative', 'Holiday', 'Exam', 'Event'];
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

            // Deterministic-ish notice ID. Sortable by time; scoped by
            // school via the Firestore doc key so two schools can never
            // collide even if they both hit NOT_N at the same moment.
            $notice_id = 'NOT_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $now_iso   = date('c');
            $now_ms    = (int) round(microtime(true) * 1000);

            // ── 1. Canonical notice doc ─────────────────────────────
            $noticeDoc = [
                'schoolId'    => $school_name,
                'session'     => $session_year,
                'noticeId'    => $notice_id,
                'title'       => $title,
                'description' => $description,
                'fromId'      => $admin_id,
                'fromType'    => 'Admin',
                'fromName'    => $this->admin_name ?? $admin_id,
                'priority'    => $priority,
                'category'    => $category,
                'toId'        => [],       // filled below after fanout
                'timestamp'   => $now_iso,
                'timestampMs' => $now_ms,
                'createdAt'   => $now_iso,
            ];
            $this->firebase->firestoreSet(
                self::COL_NOTICES,
                "{$school_name}_{$notice_id}",
                $noticeDoc
            );

            // ── 2. Recipient fanout (one Firestore doc per recipient
            //        replaces the RTDB Notification/Received/Sent
            //        per-node writes) ─────────────────────────────────
            $sanitized_to_ids = [];
            $recipientRows    = [];
            foreach ($to_ids as $key => $label) {
                $rec = $this->_resolve_recipient_key($key, (string) $label, $school_name, $session_year, $admin_id);
                if (empty($rec)) {
                    log_message('error', "create_notice: unhandled recipient key=$key");
                    continue;
                }
                foreach ($rec['targets'] as $target) {
                    $recipientRows[] = [
                        'schoolId'      => $school_name,
                        'session'       => $session_year,
                        'noticeId'      => $notice_id,
                        'recipientKey'  => $target['key'],
                        'recipientType' => $target['type'],
                        'deliveredAt'   => $now_iso,
                        'status'        => 'delivered',
                    ];
                }
                $sanitized_to_ids[$rec['sanitizedKey']] = '';
            }

            // Sender Sent log — single recipient row keyed on the admin.
            $recipientRows[] = [
                'schoolId'      => $school_name,
                'session'       => $session_year,
                'noticeId'      => $notice_id,
                'recipientKey'  => $admin_id,
                'recipientType' => 'Sender',
                'deliveredAt'   => $now_iso,
                'status'        => 'sent',
            ];

            foreach ($recipientRows as $row) {
                $docId = "{$school_name}_{$notice_id}_{$row['recipientType']}_{$row['recipientKey']}";
                $this->firebase->firestoreSet(self::COL_NOTICE_RECIPIENTS, $docId, $row);
            }

            // ── 3. Patch canonical notice with resolved toId list ────
            $this->firebase->firestoreSet(
                self::COL_NOTICES,
                "{$school_name}_{$notice_id}",
                [
                    'toId'     => $sanitized_to_ids,
                    'updatedAt'=> date('c'),
                ],
                /* merge */ true
            );

            // ── 4. Circulars collection (already watched by Parent/
            //        Teacher apps) ─────────────────────────────────────
            try {
                $this->firebase->firestoreSet(self::COL_CIRCULARS, $notice_id, [
                    'schoolId'       => $school_name,
                    'session'        => $session_year,
                    'title'          => $title,
                    'body'           => $description,
                    'author'         => $this->admin_name ?? $admin_id,
                    'category'       => $category,
                    'priority'       => $priority,
                    'targetAudience' => array_values(array_map('strval', array_keys($sanitized_to_ids))),
                    'attachmentUrl'  => '',
                    'sentAt'         => $now_iso,
                    'status'         => 'sent',
                ]);
            } catch (\Exception $e) {
                log_message('error', "create_notice: circulars write failed [{$notice_id}]: " . $e->getMessage());
            }

            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'success', 'message' => 'Notice sent successfully.']));

        } else {
            // GET — render the create form with existing notices listed.
            $data['notices'] = $this->_notices_as_legacy_map();
            $this->load->view('include/header');
            $this->load->view('create_notice', $data);
            $this->load->view('include/footer');
        }
    }

    // ─── Delete notice ───────────────────────────────────────────────
    public function delete($id)
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_notice');
        $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
        if ($id === '') { redirect('NoticeAnnouncement'); return; }
        try {
            $this->firebase->firestoreDelete(self::COL_NOTICES, "{$this->school_name}_{$id}");
        } catch (\Exception $_) { /* best-effort */ }
        try {
            $this->firebase->firestoreDelete(self::COL_CIRCULARS, $id);
        } catch (\Exception $_) { /* best-effort */ }
        // noticeRecipients docs are left in place for audit — they
        // reference a deleted noticeId but record who actually received it.
        redirect('NoticeAnnouncement');
    }

    // ─── Private helpers ─────────────────────────────────────────────

    /**
     * Class/section options for the create-notice dropdown, sourced
     * from the Firestore `sections` collection.
     * Format: "Class 8th/Section A" => "Class 8th / Section A"
     */
    private function _class_section_dropdown(): array
    {
        $out = [];
        try {
            $rows = $this->firebase->firestoreQuery('sections', [
                ['schoolId', '==', $this->school_name],
            ], 'className', 'ASC');
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r; if (!is_array($d)) continue;
                $c = (string) ($d['className'] ?? '');
                $s = (string) ($d['section']   ?? '');
                if ($c === '' || $s === '') continue;
                if (stripos($c, 'Class ')   !== 0) $c = "Class {$c}";
                if (stripos($s, 'Section ') !== 0) $s = "Section {$s}";
                $out["{$c}/{$s}"] = "{$c} / {$s}";
            }
        } catch (\Exception $_) { /* empty dropdown is acceptable */ }
        return $out;
    }

    /**
     * Resolve a raw recipient key (from the create-notice form's
     * to_id_json) to a list of concrete Firestore recipient targets.
     * Returns ['sanitizedKey' => "…", 'targets' => [{key,type}, …]]
     * or [] if the key is unrecognised.
     */
    private function _resolve_recipient_key(string $key, string $label, string $school_name, string $session_year, string $admin_id): array
    {
        // ── Class/Section — "Class 8th/Section A" ───────────────────
        if (!preg_match('/^(STU|TEA|STA|ADM|All)/', $key) && strpos($key, '/Section ') !== false) {
            [$classNode, $sectionNode] = array_map('trim', explode('/', $key, 2));
            return [
                'sanitizedKey' => "{$classNode}|{$sectionNode}",
                'targets'      => [[
                    'key'  => "{$classNode}__{$sectionNode}",
                    'type' => 'Section',
                ]],
            ];
        }

        // ── Individual student (STU…) ───────────────────────────────
        if (preg_match('/^STU[A-Za-z0-9_]+$/', $key)) {
            return [
                'sanitizedKey' => $key,
                'targets'      => [['key' => $key, 'type' => 'Student']],
            ];
        }

        // ── Individual teacher (STA…) ───────────────────────────────
        if (preg_match('/^STA[A-Za-z0-9]+$/', $key)) {
            return [
                'sanitizedKey' => $key,
                'targets'      => [['key' => $key, 'type' => 'Teacher']],
            ];
        }

        // ── Individual admin (ADM…) — skip sender ───────────────────
        if (preg_match('/^ADM[0-9]+$/', $key)) {
            if ($key === $admin_id) {
                return ['sanitizedKey' => $key, 'targets' => []];
            }
            return [
                'sanitizedKey' => $key,
                'targets'      => [['key' => $key, 'type' => 'Admin']],
            ];
        }

        // ── Bulk audiences — materialise from Firestore ─────────────
        if ($key === 'All Students') {
            return [
                'sanitizedKey' => $key,
                'targets'      => $this->_all_student_targets(),
            ];
        }
        if ($key === 'All Teachers') {
            return [
                'sanitizedKey' => $key,
                'targets'      => $this->_all_staff_targets(),
            ];
        }
        if ($key === 'All Admins') {
            return [
                'sanitizedKey' => $key,
                'targets'      => $this->_all_admin_targets($admin_id),
            ];
        }
        if ($key === 'All School') {
            return [
                'sanitizedKey' => $key,
                'targets'      => array_merge(
                    $this->_all_student_targets(),
                    $this->_all_staff_targets(),
                    $this->_all_admin_targets($admin_id)
                ),
            ];
        }
        return [];
    }

    private function _all_student_targets(): array
    {
        $rows = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $this->school_name],
        ]);
        $out = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $sid = (string) ($d['studentId'] ?? ($r['id'] ?? ''));
            if ($sid !== '') $out[] = ['key' => $sid, 'type' => 'Student'];
        }
        return $out;
    }

    private function _all_staff_targets(): array
    {
        $rows = $this->firebase->firestoreQuery('staff', [
            ['schoolId', '==', $this->school_name],
        ]);
        $out = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $sid = (string) ($d['staffId'] ?? $d['teacherId'] ?? ($r['id'] ?? ''));
            if ($sid !== '') $out[] = ['key' => $sid, 'type' => 'Teacher'];
        }
        return $out;
    }

    private function _all_admin_targets(string $senderAdminId): array
    {
        $rows = $this->firebase->firestoreQuery('admins', [
            ['schoolId', '==', $this->school_name],
        ]);
        $out = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $aid = (string) ($d['adminId'] ?? ($r['id'] ?? ''));
            if ($aid === '' || $aid === $senderAdminId) continue;
            $out[] = ['key' => $aid, 'type' => 'Admin'];
        }
        return $out;
    }
}
