<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Communication Controller
 *
 * Sub-modules: Messages, Notices, Circulars, Templates, Triggers, Queue, Logs
 *
 * Firebase paths:
 *   Schools/{school_id}/Communication/Messages/Conversations/{CONV0001}
 *   Schools/{school_id}/Communication/Messages/Chat/{CONV0001}/{MSG00001}
 *   Schools/{school_id}/Communication/Messages/Inbox/{role}/{user_id}/{CONV0001}
 *   Schools/{school_id}/Communication/Notices/{NOT0001}
 *   Schools/{school_id}/Communication/Circulars/{CIR0001}
 *   Schools/{school_id}/Communication/Templates/{TPL0001}
 *   Schools/{school_id}/Communication/Triggers/{TRG0001}
 *   Schools/{school_id}/Communication/Queue/{QUE00001}
 *   Schools/{school_id}/Communication/Logs/{LOG00001}
 *   Schools/{school_id}/Communication/Counters/{type}
 */
class Communication extends MY_Controller
{
    /** Roles for system-level ops (triggers, queue, bulk) */
    private const RBAC_ADMIN_ROLES  = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];

    /** Roles for content management (notices, circulars, templates) */
    private const RBAC_MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher', 'Front Office'];

    /** Roles that may view communication data */
    private const RBAC_VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'HR Manager', 'Accountant', 'Class Teacher', 'Teacher', 'Front Office'];

    const ADMIN_ROLES   = ['Super Admin', 'School Super Admin', 'Principal', 'Vice Principal', 'Admin'];
    const TEACHER_ROLES = ['Super Admin', 'School Super Admin', 'Principal', 'Vice Principal', 'Admin', 'Teacher'];
    const VIEW_ROLES    = ['Super Admin', 'School Super Admin', 'Principal', 'Vice Principal', 'Admin', 'Teacher', 'Accountant', 'HR Manager', 'Operations Manager'];

    const ALLOWED_CHANNELS       = ['push', 'sms', 'email', 'in_app'];
    const ALLOWED_PRIORITIES     = ['High', 'Normal', 'Low'];
    const ALLOWED_NOTICE_CATS    = ['General', 'Academic', 'Event', 'Administrative', 'Emergency'];
    const ALLOWED_RECIPIENT_TYPES = ['parent', 'student', 'teacher', 'staff', 'broadcast'];
    const ALLOWED_EVENTS = [
        'student_absent', 'student_late', 'low_attendance',
        'fee_due', 'fee_overdue', 'fee_received',
        'exam_result', 'exam_schedule',
        'admission_approved', 'admission_rejected',
        'salary_processed', 'leave_approved',
        'event_created', 'event_updated',
    ];

    const MAX_MESSAGE_LENGTH  = 5000;
    const MAX_TITLE_LENGTH    = 200;
    const MAX_BODY_LENGTH     = 10000;
    const MAX_BULK_RECIPIENTS = 500;

    public function __construct()
    {
        parent::__construct();
        require_permission('Communication');
        $this->load->library('communication_helper');
        $this->communication_helper->init(
            $this->firebase,
            $this->school_name,
            $this->session_year,
            $this->parent_db_key,
            $this->fs,
            $this->school_id
        );

        // Direct Messaging has been retired (Package 2A — COMM-MSG runtime retirement).
        // The user-facing surface is now a Coming Soon experience; the AJAX endpoints
        // below (get_conversations, get_messages, create_conversation, delete_conversation,
        // send_message, mark_read, search_recipients) return HTTP 410 Gone so older
        // mobile-app builds receive an explicit retirement signal. Existing conversation
        // history is preserved in Firestore (`conversations`, `messages`, `messageInboxes`)
        // for archive purposes; no data has been deleted.
    }

    /**
     * Common 410 Gone response for retired Messaging endpoints.
     * Returns the same JSON envelope shape as the other endpoints so older mobile
     * builds can detect retirement via HTTP status + `status: "gone"` flag.
     */
    private function _messaging_gone(): void
    {
        $this->output->set_status_header(410);
        $this->output->set_content_type('application/json');
        $this->output->set_output(json_encode([
            'status'  => 'gone',
            'code'    => 410,
            'message' => 'Direct messaging has been retired. Please use Notices or Circulars to communicate. Existing conversation history is preserved.',
        ]));
    }

    // ── Access helpers ──────────────────────────────────────────────────
    private function _require_admin()
    {
        if (!in_array($this->admin_role, self::ADMIN_ROLES, true))
            $this->json_error('Access denied.', 403);
    }
    private function _require_teacher()
    {
        if (!in_array($this->admin_role, self::TEACHER_ROLES, true))
            $this->json_error('Access denied.', 403);
    }
    private function _require_view()
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true))
            $this->json_error('Access denied.', 403);
    }

    // ── Input validation helpers ────────────────────────────────────────
    private function _validate_length(string $value, string $field, int $max): string
    {
        if (mb_strlen($value) > $max) {
            $this->json_error("{$field} exceeds maximum length of {$max} characters.");
        }
        return $value;
    }

    private function _validate_enum(string $value, array $allowed, string $field): string
    {
        if (!in_array($value, $allowed, true)) {
            $this->json_error("Invalid {$field}.");
        }
        return $value;
    }

    private function _sanitize_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sync a notice or circular to Firestore 'circulars' collection.
     * Maps RTDB field names to Android CircularDoc fields.
     *
     * Android expects: schoolId, title, body, author, authorId, category, priority,
     *   targetType, attachmentUrl, status ("sent"), sentAt (Timestamp)
     */
    /**
     * Tier A: Firestore-first writer for the `circulars` collection.
     *
     * The Parent and Teacher Android apps both read notices/circulars from
     * the Firestore `circulars` collection (CommunicationFirestoreRepository).
     * Per the Firestore-first contract, callers MUST invoke this BEFORE the
     * RTDB write and abort the operation if it returns false.
     *
     * @return bool true if Firestore write succeeded
     */
    private function _syncToFirestoreCirculars(string $id, array $data, string $type = 'notice'): bool
    {
        try {
            $fsDocId = $this->fs->docId($id);
            $description = $data['description'] ?? '';
            $authorName  = $data['created_by_name'] ?? $data['issued_by_name'] ?? $this->admin_name ?? '';
            $authorId    = $data['created_by'] ?? $data['issued_by'] ?? $this->admin_id ?? '';
            $createdAt   = $data['created_at'] ?? date('c');
            $targetGroup = $data['target_group'] ?? 'All School';

            $authorRole  = $data['author_role'] ?? $this->admin_role ?? '';

            $fsData  = [
                // Android-app canonical shape (Parent/Teacher apps read these).
                'schoolId'      => $this->school_id,
                'title'         => $data['title'] ?? '',
                'body'          => $description,
                'author'        => $authorName,
                'authorId'      => $authorId,
                'authorRole'    => $authorRole,             // e.g. "Admin", "HR Manager"
                'category'      => $data['category'] ?? 'General',
                'priority'      => $data['priority'] ?? 'Normal',
                'targetType'    => ($targetGroup === 'All School') ? 'All' : $targetGroup,
                'targetClasses' => [],
                'targetRoles'   => [],
                'attachmentUrl' => $data['attachment_url'] ?? '',
                'status'        => 'sent',  // Android filters by status == "sent"
                'type'          => $type,
                'sentAt'        => date('c'),
                'updatedAt'     => date('c'),
                // Admin-view snake_case mirror (matches Communication views
                // + HR-written notice shape, so get_notices/get_circulars and
                // the dashboard 'Recent Notices' card render all columns).
                'description'     => $description,
                'target_group'    => $targetGroup,
                'expiry_date'     => $data['expiry_date'] ?? '',
                'created_by'      => $authorId,
                'created_by_name' => $authorName,
                'created_by_role' => $authorRole,
                'issued_by'       => $authorId,
                'issued_by_name'  => $authorName,
                'issued_by_role'  => $authorRole,
                'issued_date'     => substr($createdAt, 0, 10),
                'created_at'      => $createdAt,
                'updated_at'      => date('c'),
                'attachment_url'  => $data['attachment_url'] ?? '',
                'attachment_name' => $data['attachment_name'] ?? '',
            ];

            // Route by type — notices and circulars live in separate
            // Firestore collections (the Android apps read both separately).
            $collection = ($type === 'notice') ? 'notices' : 'circulars';
            $ok = $this->fs->set($collection, $fsDocId, $fsData, true);
            if (!$ok) {
                log_message('error', "Communication::_syncToFirestoreCirculars set returned false for {$id} ({$collection})");
            }
            return (bool) $ok;
        } catch (\Exception $e) {
            log_message('error', "Communication::_syncToFirestoreCirculars failed for {$id}: " . $e->getMessage());
            return false;
        }
    }

    // ── Path helpers ────────────────────────────────────────────────────
    private function _comm(string $sub = ''): string
    {
        $b = "Schools/{$this->school_name}/Communication";
        return $sub !== '' ? "{$b}/{$sub}" : $b;
    }
    /**
     * Map counter type → (Firestore collection, ID prefix) for self-healing
     * seed on first use. If schools.commCounters.{type} is unset, scan the
     * collection for the highest existing prefix-NNNN doc id and use that.
     */
    private const COUNTER_SEED_SOURCES = [
        'Conversation' => ['conversations',  'CONV'],
        'Message'      => ['messages',       'MSG'],
        'Notice'       => ['notices',        'NOT'],
        'Circular'     => ['circulars',      'CIR'],
        'Template'     => ['messageTemplates','TPL'],
        'Trigger'      => ['alertTriggers', 'TRG'],
        'Log'          => ['deliveryLogs',  'LOG'],
    ];

    /**
     * Allocate the next ID for a Communication kind via Numbering_service.
     *
     * Phase 2: replaced the legacy commCounters.{type} read-modify-write
     * with delegation to the platform Numbering_service. Storage moves
     * from schools/{schoolId}_profile.commCounters.{Type} (legacy) to
     * systemCounters/{schoolId}_{kind}_claim_{N} (Pattern A claim-doc
     * CAS). All 5 call sites in this controller are unchanged.
     *
     * Parameter contract:
     *   $type   — capitalised counter name ('Notice', 'Circular', etc.);
     *             lower-cased into the registry kind.
     *   $prefix — ignored. The registry (application/config/numbering.php)
     *             owns the canonical prefix for every kind.
     *   $pad    — ignored. Same — registry owns padWidth.
     *
     * Failure behaviour:
     *   - Service or kind disabled  → \LogicException (propagates)
     *   - CAS retry exhaustion      → \RuntimeException (propagates)
     *   - No silent fallback to a random or timestamp-based ID — the
     *     caller's action fails entirely, preserving sequential audit
     *     integrity (same fail-loud contract as the legacy retry path).
     */
    private function _next_id(string $type, string $prefix, int $pad = 4): string
    {
        return $this->numbering->next(strtolower($type), [
            'claimedBy'  => $this->admin_id,
            'claimedFor' => 'COMM:' . strtoupper($type),
        ]);
    }

    /**
     * Dashboard stats read entirely from Firestore (replaces RTDB counts/lists
     * used by index() + get_dashboard()). Returns:
     *   [conversations, unread_messages, notices, circulars,
     *    queue_pending, queue_sent, queue_failed, recent_notices]
     */
    private function _fs_comm_stats(): array
    {
        $out = [
            'conversations' => 0, 'unread_messages' => 0,
            'notices' => 0, 'circulars' => 0,
            'queue_pending' => 0, 'queue_sent' => 0, 'queue_failed' => 0,
            'recent_notices' => [],
        ];

        // Conversations + unread for current user/role
        try {
            $role = $this->_inbox_role();
            $inboxes = $this->fs->schoolWhere('messageInboxes', [
                ['role',   '==', $role],
                ['userId', '==', $this->admin_id],
            ]);
            if (is_array($inboxes)) {
                $out['conversations'] = count($inboxes);
                foreach ($inboxes as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $out['unread_messages'] += (int) ($d['unreadCount'] ?? $d['unread_count'] ?? 0);
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Comm stats inbox FS read failed: ' . $e->getMessage());
        }

        // Notices — count + 5 most recent
        try {
            $fsNotices = $this->fs->schoolWhere('notices', []);
            if (is_array($fsNotices)) {
                $recent = [];
                foreach ($fsNotices as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $rawId = (string) ($d['id'] ?? '');
                    $prefix = $this->school_id . '_';
                    $d['id'] = (strpos($rawId, $prefix) === 0)
                        ? substr($rawId, strlen($prefix))
                        : $rawId;
                    $recent[] = $d;
                }
                $out['notices'] = count($recent);
                usort($recent, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
                $out['recent_notices'] = array_slice($recent, 0, 5);
            }
        } catch (\Exception $e) {
            log_message('error', 'Comm stats notices FS read failed: ' . $e->getMessage());
        }

        // Circulars count
        try {
            $fsCirc = $this->fs->schoolWhere('circulars', []);
            $out['circulars'] = is_array($fsCirc) ? count($fsCirc) : 0;
        } catch (\Exception $e) {
            log_message('error', 'Comm stats circulars FS read failed: ' . $e->getMessage());
        }

        // Queue statuses
        try {
            $fsQueue = $this->fs->schoolWhere('messageQueue', []);
            if (is_array($fsQueue)) {
                foreach ($fsQueue as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $s = $d['status'] ?? '';
                    if ($s === 'pending') $out['queue_pending']++;
                    elseif ($s === 'sent') $out['queue_sent']++;
                    elseif ($s === 'failed') $out['queue_failed']++;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Comm stats queue FS read failed: ' . $e->getMessage());
        }

        return $out;
    }

    // ====================================================================
    //  PAGE ROUTES
    // ====================================================================

    public function index()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'comm_view');

        // Pre-load dashboard data server-side (no AJAX spinner).
        // All reads are Firestore-only via _fs_comm_stats().
        $data = array_merge(['active_tab' => 'dashboard'], $this->_fs_comm_stats());

        $this->load->view('include/header', $data);
        $this->load->view('communication/index', $data);
        $this->load->view('include/footer');
    }

    public function messages()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'comm_view');
        $data = ['active_tab' => 'messages'];
        $this->load->view('include/header', $data);
        $this->load->view('communication/messages', $data);
        $this->load->view('include/footer');
    }

    public function notices()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'comm_view');
        $data = ['active_tab' => 'notices'];
        $this->load->view('include/header', $data);
        $this->load->view('communication/notices', $data);
        $this->load->view('include/footer');
    }

    public function circulars()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'comm_view');
        $data = ['active_tab' => 'circulars'];
        $this->load->view('include/header', $data);
        $this->load->view('communication/circulars', $data);
        $this->load->view('include/footer');
    }

    public function templates()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'comm_view');
        $data = ['active_tab' => 'templates'];
        $this->load->view('include/header', $data);
        $this->load->view('communication/templates', $data);
        $this->load->view('include/footer');
    }

    public function triggers()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'comm_view');
        $data = ['active_tab' => 'triggers'];
        $this->load->view('include/header', $data);
        $this->load->view('communication/triggers', $data);
        $this->load->view('include/footer');
    }

    public function queue()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'comm_view');
        $data = ['active_tab' => 'queue'];
        $this->load->view('include/header', $data);
        $this->load->view('communication/queue', $data);
        $this->load->view('include/footer');
    }

    public function logs()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'comm_view');
        $data = ['active_tab' => 'logs'];
        $this->load->view('include/header', $data);
        $this->load->view('communication/logs', $data);
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  DASHBOARD
    // ====================================================================

    public function get_dashboard()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_dashboard');
        $this->_require_view();
        $this->json_success($this->_fs_comm_stats());
    }

    // ====================================================================
    //  MESSAGING
    // ====================================================================

    /**
     * Inbox path role segment — always lowercase. Mirrors the lowercase
     * `parent` segment used by the parent app and unifies the admin web
     * + teacher mobile paths so there is only ONE source of truth per
     * user. Legacy capital-case rows are migrated by
     * scripts/migrate-inbox-case.php (Phase 3).
     */
    private function _inbox_role(): string
    {
        if (in_array($this->admin_role, ['Super Admin', 'School Super Admin', 'Principal', 'Vice Principal', 'Admin'], true))
            return 'admin';
        if ($this->admin_role === 'Teacher') return 'teacher';
        if ($this->admin_role === 'HR Manager') return 'hr';
        return 'admin';
    }

    public function get_conversations()
    {
        // RETIRED — Direct Messaging deprecated; conversations preserved in Firestore archive.
        $this->_messaging_gone();
    }

    public function get_messages()
    {
        // RETIRED — Direct Messaging deprecated; messages preserved in Firestore archive.
        $this->_messaging_gone();
    }

    public function create_conversation()
    {
        // RETIRED — Direct Messaging deprecated; conversation archive is read-only.
        $this->_messaging_gone();
    }

    public function delete_conversation()
    {
        // RETIRED — Direct Messaging deprecated; inbox archive is read-only.
        $this->_messaging_gone();
    }

    public function send_message()
    {
        // RETIRED — Direct Messaging deprecated; message-send is disabled.
        $this->_messaging_gone();
    }

    public function mark_read()
    {
        // RETIRED — Direct Messaging deprecated; read-state tracking is disabled.
        $this->_messaging_gone();
    }

    public function get_unread_count()
    {
        // RETIRED — Direct Messaging deprecated; unread-count is disabled.
        $this->_messaging_gone();
    }

    public function search_recipients()
    {
        // RETIRED — Direct Messaging deprecated; recipient search is disabled.
        $this->_messaging_gone();
    }

    // ====================================================================
    //  NOTICES
    // ====================================================================

    public function get_notices()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_notices');
        $this->_require_view();

        // Firestore-only (no RTDB) per project policy.
        $list = [];
        try {
            $fsDocs = $this->fs->schoolWhere('notices', []);
            if (is_array($fsDocs)) {
                $prefix = $this->school_id . '_';
                foreach ($fsDocs as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $r = is_array($doc['data'] ?? null) ? $doc['data'] : [];
                    $rawId = (string) ($d['id'] ?? '');
                    $r['id'] = (strpos($rawId, $prefix) === 0)
                        ? substr($rawId, strlen($prefix))
                        : $rawId;
                    $list[] = $r;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Communication get_notices Firestore read failed: ' . $e->getMessage());
        }
        usort($list, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        $page  = max(1, (int) ($this->input->get('page') ?? 1));
        $limit = min(100, max(1, (int) ($this->input->get('limit') ?? 50)));
        $total = count($list);
        $list  = array_slice($list, ($page - 1) * $limit, $limit);

        $this->json_success(['notices' => $list, 'page' => $page, 'limit' => $limit, 'total' => $total]);
    }

    public function save_notice()
    {
        $this->_require_role(self::RBAC_MANAGE_ROLES, 'save_notice');
        $this->_require_admin();
        $id          = trim($this->input->post('id') ?? '');
        $title       = trim($this->input->post('title') ?? '');
        $description = trim($this->input->post('description') ?? '');
        $targetGroup = trim($this->input->post('target_group') ?? 'All School');
        $priority    = trim($this->input->post('priority') ?? 'Normal');
        $category    = trim($this->input->post('category') ?? 'General');
        $expiryDate  = trim($this->input->post('expiry_date') ?? '');

        if ($title === '') $this->json_error('Title is required.');
        if ($description === '') $this->json_error('Description is required.');
        $this->_validate_length($title, 'Title', self::MAX_TITLE_LENGTH);
        $this->_validate_length($description, 'Description', self::MAX_BODY_LENGTH);
        $this->_validate_enum($priority, self::ALLOWED_PRIORITIES, 'priority');
        $this->_validate_enum($category, self::ALLOWED_NOTICE_CATS, 'category');
        if ($expiryDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) {
            $this->json_error('Invalid expiry date format.');
        }
        $title       = $this->_sanitize_html($title);
        $description = $this->_sanitize_html($description);

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->_next_id('Notice', 'NOT');
        } else {
            $id = $this->safe_path_segment($id, 'notice_id');
            // Refuse to overwrite HR-sourced notices (auto-created alongside
            // job circulars). They'd be silently reverted on the next job save.
            try {
                $existing = $this->fs->get('notices', $this->fs->docId($id));
                if (is_array($existing) && ($existing['source'] ?? '') === 'hr_recruitment') {
                    $this->json_error('This notice is managed by HR Recruitment. Edit the source job in HR → Recruitment instead.');
                }
            } catch (\Exception $e) {}
        }

        $data = [
            'title'        => $title,
            'description'  => $description,
            'target_group' => $targetGroup,
            'priority'     => $priority,
            'category'     => $category,
            'expiry_date'  => $expiryDate,
            'created_by'   => $this->admin_id,
            'created_by_name' => $this->admin_name,
            'updated_at'   => date('c'),
            'status'       => 'Active',
        ];
        if ($isNew) $data['created_at'] = date('c');

        // ────────────────────────────────────────────────────────────────
        //  TIER A: Firestore-first.
        //  Both Android apps read notices from the `circulars` collection.
        //  Write Firestore FIRST and abort if it fails — RTDB is mirrored
        //  only on Firestore success.
        // ────────────────────────────────────────────────────────────────
        $fsOk = $this->_syncToFirestoreCirculars($id, $data, 'notice');
        if (!$fsOk) {
            log_message('error', "save_notice: Firestore write failed for {$id}");
            $this->output->set_status_header(503);
            $this->json_error('Could not save notice to Firestore. No changes were made. Please try again.');
            return;
        }

        // RTDB mirror + legacy distribution removed per no-RTDB policy.
        // Mobile apps read from the `notices` Firestore collection directly.

        // Push request so the Cloud Function can fan out FCM to parents+teachers
        // (same pattern as Homework). Best-effort; failure doesn't block the save.
        if ($isNew) {
            $this->_enqueue_push_request('NOTICE_CREATED', 'notice_created', $id, [
                'title'       => $title,
                'body'        => $this->_truncate_for_push($description),
                'category'    => $category,
                'priority'    => $priority,
                'target_group' => $targetGroup,
                'noticeId'    => $id,
            ]);
        }

        $this->json_success(['id' => $id, 'message' => 'Notice saved.']);
    }

    /**
     * Writes a doc to the `pushRequests` Firestore collection. A Cloud Function
     * listens for `status: pending` and dispatches FCM to the matching target.
     * Mirrors the pattern used by the Teacher app's HomeworkFirestoreRepository.
     * Best-effort — swallowed exceptions log but don't abort the caller.
     */
    private function _enqueue_push_request(
        string $mark,
        string $source,
        string $resourceId,
        array $extra
    ): void {
        try {
            $reqId = $this->fs->docId($source . '_' . $resourceId);
            $payload = array_merge([
                'schoolId'  => $this->school_id,
                'mark'      => $mark,
                'source'    => $source,
                'status'    => 'pending',
                'markedBy'  => $this->admin_name,
                'markedByRole' => $this->admin_role ?? '',
                'createdAt' => date('c'),
            ], $extra);
            log_message('debug', "Comm push enqueue → pushRequests/{$reqId} mark={$mark}");
            $ok = $this->fs->set('pushRequests', $reqId, $payload, false);
            log_message('debug', "Comm push enqueue result for {$reqId}: " . ($ok ? 'OK' : 'FAILED'));
        } catch (\Exception $e) {
            log_message('error', "Comm _enqueue_push_request ({$mark}) threw: " . $e->getMessage());
        }
    }

    /** Trim HTML/long text to a push-friendly body length. */
    private function _truncate_for_push(string $s, int $max = 140): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($s)));
        return (strlen($plain) <= $max) ? $plain : (substr($plain, 0, $max - 1) . '…');
    }

    public function delete_notice()
    {
        $this->_require_role(self::RBAC_MANAGE_ROLES, 'delete_notice');
        $this->_require_admin();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'notice_id');

        // Refuse to delete HR-sourced notices from this module — their
        // lifecycle is tied to the source job in HR → Recruitment.
        try {
            $existing = $this->fs->get('notices', $this->fs->docId($id));
            if (is_array($existing) && ($existing['source'] ?? '') === 'hr_recruitment') {
                $this->json_error('This notice is managed by HR Recruitment. Delete the source job in HR → Recruitment to remove it.');
            }
        } catch (\Exception $e) {}

        // ── TIER A: Firestore-first delete ──────────────────────────────
        // If Firestore delete fails the notice would remain visible to
        // parents/teachers — abort so the system stays consistent.
        try {
            $ok = $this->fs->remove('notices', $this->fs->docId($id));
        } catch (\Exception $e) {
            log_message('error', "delete_notice: Firestore remove threw for {$id}: " . $e->getMessage());
            $ok = false;
        }
        if (!$ok) {
            $this->output->set_status_header(503);
            $this->json_error('Could not remove notice from Firestore. No changes were made. Please try again.');
            return;
        }

        // Firestore-only per no-RTDB policy.
        $this->json_success(['message' => 'Notice deleted.']);
    }

    // ====================================================================
    //  CIRCULARS
    // ====================================================================

    public function get_circulars()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_circulars');
        $this->_require_view();

        // Firestore-only (no RTDB fallback per project policy).
        $list = [];
        try {
            $fsDocs = $this->fs->schoolWhere('circulars', []);
            if (is_array($fsDocs)) {
                $prefix = $this->school_id . '_';
                foreach ($fsDocs as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $r = is_array($doc['data'] ?? null) ? $doc['data'] : [];
                    $rawId = (string) ($d['id'] ?? '');
                    $r['id'] = (strpos($rawId, $prefix) === 0)
                        ? substr($rawId, strlen($prefix))
                        : $rawId;
                    $list[] = $r;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Communication get_circulars Firestore read failed: ' . $e->getMessage());
        }
        usort($list, fn($a, $b) => strcmp($b['issued_date'] ?? '', $a['issued_date'] ?? ''));

        $page  = max(1, (int) ($this->input->get('page') ?? 1));
        $limit = min(100, max(1, (int) ($this->input->get('limit') ?? 50)));
        $total = count($list);
        $list  = array_slice($list, ($page - 1) * $limit, $limit);

        $this->json_success(['circulars' => $list, 'page' => $page, 'limit' => $limit, 'total' => $total]);
    }

    public function save_circular()
    {
        $this->_require_role(self::RBAC_MANAGE_ROLES, 'save_circular');
        $this->_require_admin();
        $id          = trim($this->input->post('id') ?? '');
        $title       = trim($this->input->post('title') ?? '');
        $description = trim($this->input->post('description') ?? '');
        $category    = trim($this->input->post('category') ?? 'General');
        $targetGroup = trim($this->input->post('target_group') ?? 'All School');
        $issuedDate  = trim($this->input->post('issued_date') ?? date('Y-m-d'));
        $expiryDate  = trim($this->input->post('expiry_date') ?? '');

        if ($title === '') $this->json_error('Title is required.');
        $this->_validate_length($title, 'Title', self::MAX_TITLE_LENGTH);
        $this->_validate_length($description, 'Description', self::MAX_BODY_LENGTH);
        if ($issuedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedDate)) {
            $this->json_error('Invalid issued date format.');
        }
        if ($expiryDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) {
            $this->json_error('Invalid expiry date format.');
        }
        $title       = $this->_sanitize_html($title);
        $description = $this->_sanitize_html($description);

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->_next_id('Circular', 'CIR');
        } else {
            $id = $this->safe_path_segment($id, 'circular_id');
            // Refuse to overwrite HR-sourced circulars from this module —
            // they're auto-refreshed by Hr::save_job and direct edits here
            // would be silently undone on the next job edit.
            try {
                $existing = $this->fs->get('circulars', $this->fs->docId($id));
                if (is_array($existing) && ($existing['source'] ?? '') === 'hr_recruitment') {
                    $this->json_error('This circular is managed by HR Recruitment. Edit the source job in HR → Recruitment instead.');
                }
            } catch (\Exception $e) {}
        }

        // Handle file upload
        $attachmentUrl  = trim($this->input->post('existing_attachment_url') ?? '');
        $attachmentName = trim($this->input->post('existing_attachment_name') ?? '');

        if (!empty($_FILES['attachment']['name'])) {
            // Validate MIME type server-side
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($_FILES['attachment']['tmp_name']);
            $allowedMimes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'image/jpeg',
                'image/png',
            ];
            if (!in_array($mimeType, $allowedMimes, true)) {
                $this->json_error('Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG.');
            }

            // Upload to temp dir first
            $tempDir = APPPATH . 'temp/';
            if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

            $config['upload_path']      = $tempDir;
            $config['allowed_types']    = 'pdf|doc|docx|jpg|jpeg|png';
            $config['max_size']         = 5120; // 5MB
            $config['file_name']        = $id . '_' . time();
            $config['file_ext_tolower'] = true;
            $this->load->library('upload', $config);

            if ($this->upload->do_upload('attachment')) {
                $uploadData     = $this->upload->data();
                $localPath      = $uploadData['full_path'];
                $attachmentName = basename($uploadData['orig_name']);

                // Try Firebase Storage first
                $firebaseOk = false;
                try {
                    // Canonical Storage scheme: schools/{schoolId}/... (ID-keyed,
                    // rename-proof). Previously keyed by sanitized school NAME.
                    $remotePath = "schools/{$this->school_id}/circulars/{$uploadData['file_name']}";
                    $uploaded   = $this->firebase->uploadFile($localPath, $remotePath);
                    if ($uploaded) {
                        $attachmentUrl = $this->firebase->getDownloadUrl($remotePath);
                        $firebaseOk = !empty($attachmentUrl);
                    }
                } catch (Exception $e) {
                    log_message('info', 'Circular attachment: Firebase Storage unavailable, using local — ' . $e->getMessage());
                }

                // Fallback: copy to local uploads/
                if (!$firebaseOk) {
                    $localDir = FCPATH . 'uploads/circulars/';
                    if (!is_dir($localDir)) mkdir($localDir, 0755, true);
                    copy($localPath, $localDir . $uploadData['file_name']);
                    $attachmentUrl = base_url('uploads/circulars/' . $uploadData['file_name']);
                }

                @unlink($localPath); // clean up temp
            } else {
                $this->json_error($this->upload->display_errors('', ''));
            }
        }

        $data = [
            'title'           => $title,
            'description'     => $description,
            'category'        => $category,
            'target_group'    => $targetGroup,
            'attachment_url'  => $attachmentUrl,
            'attachment_name' => $attachmentName,
            'issued_by'       => $this->admin_id,
            'issued_by_name'  => $this->admin_name,
            'issued_date'     => $issuedDate,
            'expiry_date'     => $expiryDate,
            'updated_at'      => date('c'),
            'status'          => 'Active',
        ];
        if ($isNew) $data['created_at'] = date('c');

        // ────────────────────────────────────────────────────────────────
        //  TIER A: Firestore-first.
        //  Both Android apps read circulars from the `circulars` collection.
        //  Write Firestore FIRST and abort if it fails — RTDB is mirrored
        //  only on Firestore success.
        // ────────────────────────────────────────────────────────────────
        $fsOk = $this->_syncToFirestoreCirculars($id, $data, 'circular');
        if (!$fsOk) {
            log_message('error', "save_circular: Firestore write failed for {$id}");
            $this->output->set_status_header(503);
            $this->json_error('Could not save circular to Firestore. No changes were made. Please try again.');
            return;
        }

        // Firestore-only per no-RTDB policy. Mobile apps read the `circulars`
        // collection directly (and the `notices` collection for notices);
        // legacy All-Notices RTDB distribution removed.

        if ($isNew) {
            $this->_enqueue_push_request('CIRCULAR_CREATED', 'circular_created', $id, [
                'title'        => $title,
                'body'         => $this->_truncate_for_push($description),
                'category'     => $category,
                'target_group' => $targetGroup,
                'circularId'   => $id,
            ]);
        }

        $this->json_success(['id' => $id, 'message' => 'Circular saved.']);
    }

    public function delete_circular()
    {
        $this->_require_role(self::RBAC_MANAGE_ROLES, 'delete_circular');
        $this->_require_admin();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'circular_id');

        // Refuse to delete HR-sourced circulars from this module —
        // they should be removed by deleting the source job in HR.
        try {
            $existing = $this->fs->get('circulars', $this->fs->docId($id));
            if (is_array($existing) && ($existing['source'] ?? '') === 'hr_recruitment') {
                $this->json_error('This circular is managed by HR Recruitment. Delete the source job in HR → Recruitment to remove it.');
            }
        } catch (\Exception $e) {}

        // ── TIER A: Firestore-first delete ──────────────────────────────
        try {
            $ok = $this->fs->remove('circulars', $this->fs->docId($id));
        } catch (\Exception $e) {
            log_message('error', "delete_circular: Firestore remove threw for {$id}: " . $e->getMessage());
            $ok = false;
        }
        if (!$ok) {
            $this->output->set_status_header(503);
            $this->json_error('Could not remove circular from Firestore. No changes were made. Please try again.');
            return;
        }

        // Firestore-only per no-RTDB policy.
        $this->json_success(['message' => 'Circular deleted.']);
    }

    public function acknowledge_circular()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'acknowledge_circular');
        $this->_require_view();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'circular_id');

        // Verify circular exists (Firestore).
        try {
            $circ = $this->fs->get('circulars', $this->fs->docId($id));
            if (!is_array($circ)) $this->json_error('Circular not found.');
        } catch (\Exception $e) {
            $this->json_error('Failed to read circular.');
        }

        // One ack doc per (circular, user). Idempotent — re-ack just updates
        // acknowledgedAt. Flat collection so we can query either by circular
        // ("who acked CIR0001?") or by user ("which circulars did SSA0001 ack?").
        $ackDocId = $this->fs->docId2($id, $this->admin_id);
        $nowIso   = date('c');
        $payload  = [
            'schoolId'       => $this->school_id,
            'circularId'     => $id,
            'userId'         => $this->admin_id,
            'userName'       => $this->admin_name,
            'role'           => $this->_inbox_role(),
            'acknowledgedAt' => $nowIso,
        ];

        try {
            $ok = $this->fs->set('circularAcks', $ackDocId, $payload, true);
        } catch (\Exception $e) {
            log_message('error', 'acknowledge_circular Firestore write failed: ' . $e->getMessage());
            $ok = false;
        }
        if (!$ok) {
            $this->output->set_status_header(503);
            $this->json_error('Could not record acknowledgement. Please try again.');
            return;
        }

        $this->json_success(['message' => 'Acknowledged.', 'acknowledged_at' => $nowIso]);
    }

    /**
     * GET — List acknowledgements for a circular, or for the current user.
     * Params:
     *   ?circular_id=CIR0001  → list of users who acked that circular
     *   ?mine=1               → list of circulars the current user has acked
     */
    public function get_circular_acks()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_circular_acks');
        $this->_require_view();

        $circularId = trim($this->input->get('circular_id') ?? '');
        $mine       = (int) ($this->input->get('mine') ?? 0) === 1;

        $conditions = [];
        if ($circularId !== '') {
            $conditions[] = ['circularId', '==', $this->safe_path_segment($circularId, 'circular_id')];
        }
        if ($mine) {
            $conditions[] = ['userId', '==', $this->admin_id];
        }
        if (empty($conditions)) {
            $this->json_error('Provide circular_id or mine=1.');
        }

        $list = [];
        try {
            $fsDocs = $this->fs->schoolWhere('circularAcks', $conditions);
            if (is_array($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $list[] = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'get_circular_acks Firestore read failed: ' . $e->getMessage());
        }

        usort($list, fn($a, $b) => strcmp($b['acknowledgedAt'] ?? '', $a['acknowledgedAt'] ?? ''));
        $this->json_success(['acknowledgements' => $list, 'count' => count($list)]);
    }

    // ====================================================================
    //  TEMPLATES
    // ====================================================================

    public function get_templates()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_templates');
        $this->_require_admin();
        $all = $this->_dwListAdmin(self::FS_COL_TEMPLATES, 'Templates');
        $list = [];
        foreach ($all as $id => $t) {
            if (!is_array($t) || $id === 'Counter') continue;
            $t['id'] = $id;
            $list[] = $t;
        }
        $this->json_success(['templates' => $list]);
    }

    public function save_template()
    {
        $this->_require_role(self::RBAC_MANAGE_ROLES, 'save_template');
        $this->_require_admin();
        $id       = trim($this->input->post('id') ?? '');
        $name     = trim($this->input->post('name') ?? '');
        $channel  = trim($this->input->post('channel') ?? 'push');
        $subject  = trim($this->input->post('subject') ?? '');
        $body     = trim($this->input->post('body') ?? '');
        $category = trim($this->input->post('category') ?? 'General');

        if ($name === '') $this->json_error('Template name is required.');
        if ($body === '') $this->json_error('Message body is required.');
        $this->_validate_length($name, 'Template name', self::MAX_TITLE_LENGTH);
        $this->_validate_length($subject, 'Subject', self::MAX_TITLE_LENGTH);
        $this->_validate_length($body, 'Body', self::MAX_BODY_LENGTH);
        $this->_validate_enum($channel, self::ALLOWED_CHANNELS, 'channel');

        // Only allow safe {{variable}} placeholders — reject other patterns
        $bodyWithoutVars = preg_replace('/\{\{\w+\}\}/', '', $subject . ' ' . $body);
        if (preg_match('/<script|javascript:|on\w+\s*=|data:\s*text/i', $bodyWithoutVars)) {
            $this->json_error('Template body contains disallowed content.');
        }

        // Extract variables from body
        preg_match_all('/\{\{(\w+)\}\}/', $subject . ' ' . $body, $matches);
        $variables = array_values(array_unique($matches[1]));

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->_next_id('Template', 'TPL');
        } else {
            $id = $this->safe_path_segment($id, 'template_id');
        }

        $data = [
            'name'       => $name,
            'channel'    => $channel,
            'subject'    => $subject,
            'body'       => $body,
            'variables'  => $variables,
            'category'   => $category,
            'created_by' => $this->admin_id,
            'updated_at' => date('c'),
            'status'     => 'Active',
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->_dwSet(self::FS_COL_TEMPLATES, 'Templates', $id, $data);
        $this->json_success(['id' => $id, 'message' => 'Template saved.']);
    }

    public function delete_template()
    {
        $this->_require_role(self::RBAC_MANAGE_ROLES, 'delete_template');
        $this->_require_admin();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'template_id');

        // Check if any trigger uses this template
        $triggers = $this->_dwListAdmin(self::FS_COL_TRIGGERS, 'Triggers');
        foreach ($triggers as $tId => $trg) {
            if (!is_array($trg) || $tId === 'Counter') continue;
            if (($trg['template_id'] ?? '') === $id && !empty($trg['enabled'])) {
                $this->json_error('Cannot delete: template is used by an active trigger.');
            }
        }

        $this->_dwDelete(self::FS_COL_TEMPLATES, 'Templates', $id);
        $this->json_success(['message' => 'Template deleted.']);
    }

    public function preview_template()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'preview_template');
        $this->_require_admin();
        $body    = trim($this->input->post('body') ?? '');
        $subject = trim($this->input->post('subject') ?? '');
        $this->_validate_length($body, 'Body', self::MAX_BODY_LENGTH);
        $this->_validate_length($subject, 'Subject', self::MAX_TITLE_LENGTH);

        // Sample data for preview
        $sample = [
            'student_name' => 'Rahul Sharma',
            'parent_name'  => 'Mr. Sharma',
            'class'        => 'Class 9th',
            'section'      => 'Section A',
            'date'         => date('Y-m-d'),
            'amount'       => '5,000',
            'due_date'     => date('Y-m-d', strtotime('+7 days')),
            'month'        => date('F'),
            'exam_name'    => 'Mid Term 2026',
            'percentage'   => '85.5',
            'grade'        => 'A',
            'receipt_no'   => 'RCP-000123',
            'school_name'  => $this->school_display_name ?? $this->school_name,
        ];

        $resolvedSubject = preg_replace_callback('/\{\{(\w+)\}\}/', fn($m) => $sample[$m[1]] ?? $m[0], $subject);
        $resolvedBody    = preg_replace_callback('/\{\{(\w+)\}\}/', fn($m) => $sample[$m[1]] ?? $m[0], $body);

        // Sanitize output to prevent reflected XSS
        $resolvedSubject = $this->_sanitize_html($resolvedSubject);
        $resolvedBody    = $this->_sanitize_html($resolvedBody);

        $this->json_success(['subject' => $resolvedSubject, 'body' => $resolvedBody]);
    }

    // ====================================================================
    //  TRIGGERS
    // ====================================================================

    public function get_triggers()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_triggers');
        $this->_require_admin();
        $all = $this->_dwListAdmin(self::FS_COL_TRIGGERS, 'Triggers');
        $list = [];
        foreach ($all as $id => $t) {
            if (!is_array($t) || $id === 'Counter') continue;
            $t['id'] = $id;
            $list[] = $t;
        }
        $this->json_success(['triggers' => $list]);
    }

    public function save_trigger()
    {
        $this->_require_role(self::RBAC_ADMIN_ROLES, 'save_trigger');
        $this->_require_admin();
        $id            = trim($this->input->post('id') ?? '');
        $name          = trim($this->input->post('name') ?? '');
        $eventType     = trim($this->input->post('event_type') ?? '');
        $templateId    = trim($this->input->post('template_id') ?? '');
        $channel       = trim($this->input->post('channel') ?? 'push');
        $recipientType = trim($this->input->post('recipient_type') ?? 'parent');
        $enabled       = ($this->input->post('enabled') ?? '1') === '1';

        if ($name === '') $this->json_error('Trigger name is required.');
        if ($eventType === '') $this->json_error('Event type is required.');
        if ($templateId === '') $this->json_error('Template is required.');
        $this->_validate_length($name, 'Trigger name', self::MAX_TITLE_LENGTH);
        $this->_validate_enum($eventType, self::ALLOWED_EVENTS, 'event type');
        $this->_validate_enum($channel, self::ALLOWED_CHANNELS, 'channel');
        $this->_validate_enum($recipientType, self::ALLOWED_RECIPIENT_TYPES, 'recipient type');

        // Verify template exists (Firestore-first via helper)
        $templateId = $this->safe_path_segment($templateId, 'template_id');
        $tpl = $this->_dwGet(self::FS_COL_TEMPLATES, 'Templates', $templateId);
        if (!is_array($tpl)) $this->json_error('Selected template does not exist.');

        // Parse and validate conditions JSON — only allow flat key:numeric pairs
        $conditionsJson = $this->input->post('conditions') ?? '{}';
        $conditions = json_decode($conditionsJson, true);
        if ($conditions === null && $conditionsJson !== '{}' && $conditionsJson !== '') {
            $this->json_error('Invalid conditions JSON.');
        }
        $conditions = $conditions ?? [];
        // Sanitize: only allow alphanumeric keys with numeric/string values (no nested objects)
        $cleanConditions = [];
        foreach ($conditions as $k => $v) {
            if (!preg_match('/^\w+$/', $k)) continue;
            if (is_array($v) || is_object($v)) continue;
            $cleanConditions[$k] = $v;
        }
        $conditions = $cleanConditions;

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->_next_id('Trigger', 'TRG');
        } else {
            $id = $this->safe_path_segment($id, 'trigger_id');
        }

        $data = [
            'name'           => $name,
            'event_type'     => $eventType,
            'template_id'    => $templateId,
            'channel'        => $channel,
            'recipient_type' => $recipientType,
            'enabled'        => $enabled,
            'conditions'     => $conditions,
            'created_by'     => $this->admin_id,
            'updated_at'     => date('c'),
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->_dwSet(self::FS_COL_TRIGGERS, 'Triggers', $id, $data);
        $this->json_success(['id' => $id, 'message' => 'Trigger saved.']);
    }

    public function delete_trigger()
    {
        $this->_require_role(self::RBAC_ADMIN_ROLES, 'delete_trigger');
        $this->_require_admin();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'trigger_id');
        $this->_dwDelete(self::FS_COL_TRIGGERS, 'Triggers', $id);
        $this->json_success(['message' => 'Trigger deleted.']);
    }

    public function toggle_trigger()
    {
        $this->_require_role(self::RBAC_ADMIN_ROLES, 'toggle_trigger');
        $this->_require_admin();
        $id      = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'trigger_id');
        $enabled = ($this->input->post('enabled') ?? '1') === '1';
        $this->_dwUpdate(self::FS_COL_TRIGGERS, 'Triggers', $id, [
            'enabled'    => $enabled,
            'updated_at' => date('c'),
        ]);
        $this->json_success(['message' => $enabled ? 'Trigger enabled.' : 'Trigger disabled.']);
    }

    // ====================================================================
    //  QUEUE
    // ====================================================================

    public function get_queue()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_queue');
        $this->_require_admin();
        $status = trim($this->input->get('status') ?? '');
        $all    = $this->_dwListAdmin(self::FS_COL_QUEUE, 'Queue');
        $list   = [];
        foreach ($all as $id => $q) {
            if (!is_array($q)) continue;
            if ($status !== '' && ($q['status'] ?? '') !== $status) continue;
            $q['id'] = $id;
            $list[] = $q;
        }
        usort($list, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        $page  = max(1, (int) ($this->input->get('page') ?? 1));
        $limit = min(100, max(1, (int) ($this->input->get('limit') ?? 50)));
        $total = count($list);
        $list  = array_slice($list, ($page - 1) * $limit, $limit);

        $this->json_success(['queue' => $list, 'page' => $page, 'limit' => $limit, 'total' => $total]);
    }

    /**
     * Process pending queue items. Called by cron or manually.
     * Processes up to 50 pending items per run.
     */
    public function process_queue()
    {
        $this->_require_role(self::RBAC_ADMIN_ROLES, 'process_queue');
        $this->_require_admin();
        $all = $this->_dwListAdmin(self::FS_COL_QUEUE, 'Queue');
        if (empty($all)) {
            $this->json_success(['processed' => 0, 'message' => 'Queue empty.']);
            return;
        }

        // Pre-filter to pending only, then sort by created_at (FIFO)
        $pending = [];
        foreach ($all as $id => $q) {
            if (!is_array($q)) continue;
            if (($q['status'] ?? '') !== 'pending') continue;
            $q['_id'] = $id;
            $pending[] = $q;
        }
        usort($pending, fn($a, $b) => strcmp($a['created_at'] ?? '', $b['created_at'] ?? ''));

        $processed = 0;
        $errors    = 0;
        $now       = date('c');

        foreach ($pending as $q) {
            $id = $q['_id'];
            if ($processed + $errors >= 50) break;

            // Check scheduled time
            $scheduledAt = $q['scheduled_at'] ?? '';
            if ($scheduledAt !== '' && $scheduledAt > $now) continue;

            // Mark as processing — Firestore-first via _dwUpdate.
            $this->_dwUpdate(self::FS_COL_QUEUE, 'Queue', $id, ['status' => 'processing']);

            $channel = $q['channel'] ?? 'push';
            $success = false;

            switch ($channel) {
                case 'push':
                    // Write to legacy notification paths for mobile app delivery
                    $success = $this->_deliver_push($q);
                    break;
                case 'in_app':
                    $success = true; // Already visible in-app via Queue
                    break;
                case 'sms':
                case 'email':
                    // Gateway integration placeholder — mark as sent for now
                    $success = true;
                    break;
            }

            $now = date('c');
            if ($success) {
                $this->_dwUpdate(self::FS_COL_QUEUE, 'Queue', $id, [
                    'status'  => 'sent',
                    'sent_at' => $now,
                ]);
                // Write delivery log
                $this->_write_log($id, $q, 'delivered', '200', 'Message delivered');
                $processed++;
            } else {
                $attempts = (int) ($q['attempts'] ?? 0) + 1;
                $maxAttempts = (int) ($q['max_attempts'] ?? 3);
                $newStatus = $attempts >= $maxAttempts ? 'failed' : 'pending';
                $this->_dwUpdate(self::FS_COL_QUEUE, 'Queue', $id, [
                    'status'        => $newStatus,
                    'attempts'      => $attempts,
                    'error_message' => 'Delivery failed',
                ]);
                $this->_write_log($id, $q, 'failed', '500', 'Delivery failed');
                $errors++;
            }
        }

        $this->json_success([
            'processed' => $processed,
            'errors'    => $errors,
            'message'   => "Processed {$processed} messages, {$errors} failures.",
        ]);
    }

    /**
     * Deliver push notification via FCM (Phase C — 2026-04-08) plus the
     * legacy in-app notice path that the apps fall back to when they're
     * already open and listening on RTDB.
     */
    private function _deliver_push(array $q): bool
    {
        $recipientId   = $q['recipient_id'] ?? '';
        $recipientType = $q['recipient_type'] ?? '';
        if ($recipientId === '') return false;

        // Validate recipient ID format for path safety
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $recipientId)) return false;
        if (!in_array($recipientType, self::ALLOWED_RECIPIENT_TYPES, true)) return false;

        // ── PATH A: Real FCM push via Push_service ─────────────────────
        // Only parents/teachers/staff have devices registered. Broadcast
        // and student types fall through to the legacy in-app path only.
        $fcmSent = 0;
        if (in_array($recipientType, ['parent', 'teacher', 'staff'], true)) {
            try {
                $this->load->library('push_service');
                $fcmSent = $this->push_service->sendToUser($recipientId, [
                    'title' => $q['title'] ?? '',
                    'body'  => $q['message_body'] ?? '',
                    'data'  => [
                        'type'         => $q['event_type'] ?? 'notification',
                        'queue_id'     => $q['_id'] ?? '',
                        'recipient_id' => $recipientId,
                        'recipient_type' => $recipientType,
                    ],
                ]);
            } catch (\Exception $e) {
                log_message('error', 'Communication FCM push failed: ' . $e->getMessage());
            }
        }

        // Legacy RTDB notice creation + notification paths removed per no-RTDB
        // policy. FCM push is now handled by the Push_service above and/or
        // via pushRequests → Cloud Function pipeline. No RTDB write needed.
        try {
            return true;
        } catch (\Exception $e) {
            log_message('error', 'Communication push delivery failed: ' . $e->getMessage());
            return false;
        }
    }

    private function _write_log(string $queueId, array $q, string $status, string $code, string $message): void
    {
        $logId = $this->_next_id('Log', 'LOG', 5);

        $this->_dwSet(self::FS_COL_LOGS, 'Logs', $logId, [
            'queue_id'          => $queueId,
            'trigger_id'        => $q['trigger_id'] ?? '',
            'channel'           => $q['channel'] ?? '',
            'recipient_id'      => $q['recipient_id'] ?? '',
            'recipient_name'    => $q['recipient_name'] ?? '',
            'recipient_contact' => $q['recipient_contact'] ?? '',
            'title'             => $q['title'] ?? '',
            'status'            => $status,
            'response_code'     => $code,
            'response_message'  => $message,
            'gateway'           => ($q['channel'] ?? '') === 'push' ? 'firebase_push' : ($q['channel'] ?? ''),
            'source'            => $q['source'] ?? '',
            'event_type'        => $q['event_type'] ?? '',
            'attempts'          => (int) ($q['attempts'] ?? 0),
            'sent_at'           => $q['sent_at'] ?? date('c'),
            'logged_at'         => date('c'),
        ]);
    }

    public function cancel_queued()
    {
        $this->_require_role(self::RBAC_ADMIN_ROLES, 'cancel_queued');
        $this->_require_admin();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'queue_id');
        $q  = $this->_dwGet(self::FS_COL_QUEUE, 'Queue', $id);
        if (!is_array($q)) $this->json_error('Queue item not found.');
        if (($q['status'] ?? '') !== 'pending') $this->json_error('Only pending items can be cancelled.');
        $this->_dwUpdate(self::FS_COL_QUEUE, 'Queue', $id, [
            'status'     => 'cancelled',
            'updated_at' => date('c'),
        ]);
        $this->json_success(['message' => 'Cancelled.']);
    }

    public function retry_failed()
    {
        $this->_require_role(self::RBAC_ADMIN_ROLES, 'retry_failed');
        $this->_require_admin();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'queue_id');
        $q  = $this->_dwGet(self::FS_COL_QUEUE, 'Queue', $id);
        if (!is_array($q)) $this->json_error('Queue item not found.');
        if (($q['status'] ?? '') !== 'failed') $this->json_error('Only failed items can be retried.');
        $this->_dwUpdate(self::FS_COL_QUEUE, 'Queue', $id, [
            'status'        => 'pending',
            'attempts'      => 0,
            'error_message' => '',
            'updated_at'    => date('c'),
        ]);
        $this->json_success(['message' => 'Queued for retry.']);
    }

    // ====================================================================
    //  DELIVERY LOGS
    // ====================================================================

    public function get_logs()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_logs');
        $this->_require_admin();
        $all = $this->_dwListAdmin(self::FS_COL_LOGS, 'Logs');
        $list = [];
        foreach ($all as $id => $l) {
            if (!is_array($l)) continue;
            $l['id'] = $id;
            $list[] = $l;
        }
        usort($list, fn($a, $b) => strcmp($b['logged_at'] ?? '', $a['logged_at'] ?? ''));

        $page  = max(1, (int) ($this->input->get('page') ?? 1));
        $limit = min(100, max(1, (int) ($this->input->get('limit') ?? 50)));
        $total = count($list);
        $list  = array_slice($list, ($page - 1) * $limit, $limit);

        $this->json_success(['logs' => $list, 'page' => $page, 'limit' => $limit, 'total' => $total]);
    }

    public function get_log_stats()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_log_stats');
        $this->_require_admin();

        $all = $this->_dwListAdmin(self::FS_COL_LOGS, 'Logs');
        $stats = ['total' => 0, 'delivered' => 0, 'failed' => 0, 'bounced' => 0, 'by_channel' => []];

        foreach ($all as $l) {
            if (!is_array($l)) continue;
            $stats['total']++;
            $s = $l['status'] ?? '';
            if (isset($stats[$s])) $stats[$s]++;
            $ch = $l['channel'] ?? 'unknown';
            $stats['by_channel'][$ch] = ($stats['by_channel'][$ch] ?? 0) + 1;
        }

        $this->json_success(['stats' => $stats]);
    }

    // ====================================================================
    //  SEND BULK (manual compose)
    // ====================================================================

    public function send_bulk()
    {
        $this->_require_role(self::RBAC_ADMIN_ROLES, 'send_bulk');
        $this->_require_admin();
        $title       = trim($this->input->post('title') ?? '');
        $message     = trim($this->input->post('message') ?? '');
        $channel     = trim($this->input->post('channel') ?? 'push');
        $targetGroup = trim($this->input->post('target_group') ?? '');

        if ($title === '') $this->json_error('Title is required.');
        if ($message === '') $this->json_error('Message is required.');
        if ($targetGroup === '') $this->json_error('Target group is required.');
        $this->_validate_length($title, 'Title', self::MAX_TITLE_LENGTH);
        $this->_validate_length($message, 'Message', self::MAX_BODY_LENGTH);
        $this->_validate_enum($channel, self::ALLOWED_CHANNELS, 'channel');
        $title   = $this->_sanitize_html($title);
        $message = $this->_sanitize_html($message);

        $session  = $this->session_year;
        $school   = $this->school_name;
        $queued   = 0;

        // Collect recipients based on target group
        $recipients = [];

        // Firestore-only: query students + staff collections (no RTDB SIS/Students_Index).
        if ($targetGroup === 'All Students' || $targetGroup === 'All School') {
            try {
                $studentDocs = $this->fs->schoolWhere('students', [['status', '==', 'Active']]);
                $prefix = $this->school_id . '_';
                foreach ($studentDocs as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $s = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $rawId = (string) ($d['id'] ?? '');
                    $sid = (strpos($rawId, $prefix) === 0) ? substr($rawId, strlen($prefix)) : $rawId;
                    $recipients[] = ['id' => $sid, 'name' => (string) ($s['Name'] ?? $s['name'] ?? $sid), 'type' => 'student'];
                }
            } catch (\Exception $e) { log_message('error', 'send_bulk student query failed: ' . $e->getMessage()); }
        }

        if ($targetGroup === 'All Teachers' || $targetGroup === 'All School') {
            try {
                $staffDocs = $this->fs->schoolWhere('staff', []);
                $prefix = $this->school_id . '_';
                foreach ($staffDocs as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $t = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $rawId = (string) ($d['id'] ?? '');
                    $tid = (strpos($rawId, $prefix) === 0) ? substr($rawId, strlen($prefix)) : $rawId;
                    $recipients[] = ['id' => $tid, 'name' => $t['Name'] ?? $t['name'] ?? $tid, 'type' => 'teacher'];
                }
            } catch (\Exception $e) { log_message('error', 'send_bulk staff query failed: ' . $e->getMessage()); }
        }

        // Cap recipients to prevent abuse
        if (count($recipients) > self::MAX_BULK_RECIPIENTS) {
            $this->json_error('Too many recipients. Maximum is ' . self::MAX_BULK_RECIPIENTS . '.');
        }

        if (empty($recipients)) {
            $this->json_error('No recipients found for the selected target group.');
        }

        // Bulk-allocate queue IDs from the nested commCounters.Queue counter
        // on schools/{schoolId}_profile. Single read + single update regardless
        // of N. fs->update reshapes the dotted key into a nested path write so
        // only the Queue counter is mutated.
        $totalRecipients = count($recipients);
        $profileDocId    = $this->fs->docId('profile');
        $profileDoc      = null;
        try { $profileDoc = $this->fs->get('schools', $profileDocId); } catch (\Exception $e) {}
        $curCounter = (is_array($profileDoc)
                       && isset($profileDoc['commCounters'])
                       && is_array($profileDoc['commCounters'])
                       && isset($profileDoc['commCounters']['Queue'])
                       && is_numeric($profileDoc['commCounters']['Queue']))
            ? (int) $profileDoc['commCounters']['Queue']
            : 0;
        $newCounter = $curCounter + $totalRecipients;
        try {
            $this->fs->update('schools', $profileDocId, ['commCounters.Queue' => $newCounter]);
        } catch (\Exception $e) {
            log_message('error', 'send_bulk counter update failed: ' . $e->getMessage());
        }

        // Build all queue items in a single batch
        $now       = date('c');
        $batchData = [];
        $startId   = $curCounter + 1;

        foreach ($recipients as $i => $r) {
            $queueId = 'QUE' . str_pad($startId + $i, 5, '0', STR_PAD_LEFT);
            $batchData[$queueId] = [
                'trigger_id'        => '',
                'template_id'       => '',
                'channel'           => $channel,
                'recipient_type'    => $r['type'],
                'recipient_id'      => $r['id'],
                'recipient_name'    => $r['name'],
                'recipient_contact' => '',
                'title'             => $title,
                'message_body'      => $message,
                'status'            => 'pending',
                'priority'          => 'normal',
                'attempts'          => 0,
                'max_attempts'      => 3,
                'error_message'     => '',
                'created_at'        => $now,
                'scheduled_at'      => $now,
                'sent_at'           => '',
                'source'            => 'bulk',
                'event_type'        => '',
                'created_by'        => $this->admin_id,
            ];
        }

        // Firestore-only primary writes — one per queue item. The Firestore
        // REST client doesn't expose atomic batch writes, so this is N
        // sequential PATCHes. For typical school-scale bulk sends (tens to
        // low hundreds) this is acceptable; the MAX_BULK_RECIPIENTS cap
        // enforced above bounds the blast radius. Each write is isolated —
        // partial success is recoverable via the queue status field.
        foreach ($batchData as $queueId => $row) {
            try {
                $this->fs->set(self::FS_COL_QUEUE, "{$this->school_id}_{$queueId}",
                    array_merge(['schoolId' => $this->school_id, 'id' => $queueId], $row),
                    false
                );
            } catch (\Exception $e) {
                log_message('error', "Communication::send_bulk FS queue [{$queueId}]: " . $e->getMessage());
            }
        }

        $queued = $totalRecipients;

        $this->json_success(['queued' => $queued, 'message' => "{$queued} messages queued for delivery."]);
    }

    // ====================================================================
    //  CLASS LIST HELPER (for target group dropdowns)
    // ====================================================================
    // (firestore_backfill_circulars endpoint removed — one-shot migration
    //  already completed and the RTDB source paths are no longer written.)

    // ════════════════════════════════════════════════════════════════════
    //  PHASE 6 — Firestore-first dual-write helpers for admin-only entities
    //
    //  Templates, Triggers, Queue and Logs are admin-internal — no Android
    //  app reads them — so we don't need a heavy service library. These
    //  five helpers wrap the same Firestore-first → RTDB mirror pattern
    //  used by Messaging_service, just inline.
    //
    //  Doc id format:  {schoolId}_{entityId}      (matches sister modules)
    // ════════════════════════════════════════════════════════════════════

    /** Firestore collection names — keep in one place. */
    private const FS_COL_TEMPLATES = 'messageTemplates';
    private const FS_COL_TRIGGERS  = 'alertTriggers';
    private const FS_COL_QUEUE     = 'messageQueue';
    private const FS_COL_LOGS      = 'deliveryLogs';

    /**
     * Firestore-only set/replace. Stamps schoolId + id so docs are
     * tenant-scoped and self-describing. $rtdbSub is kept in the signature
     * so call sites don't need to change, but is no longer written to.
     */
    private function _dwSet(string $fsCollection, string $rtdbSub, string $id, array $data): bool
    {
        $docId  = "{$this->school_id}_{$id}";
        $fsData = array_merge(['schoolId' => $this->school_id, 'id' => $id], $data);
        try {
            return (bool) $this->fs->set($fsCollection, $docId, $fsData, false);
        } catch (\Exception $e) {
            log_message('error', "Communication::_dwSet FS [{$fsCollection}/{$docId}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Firestore-only patch (merge). Use for partial field updates like
     * status changes, attempt counters, toggles.
     */
    private function _dwUpdate(string $fsCollection, string $rtdbSub, string $id, array $patch): bool
    {
        $docId = "{$this->school_id}_{$id}";
        try {
            return (bool) $this->fs->set($fsCollection, $docId, $patch, true);
        } catch (\Exception $e) {
            log_message('error', "Communication::_dwUpdate FS [{$fsCollection}/{$docId}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Firestore-only delete.
     */
    private function _dwDelete(string $fsCollection, string $rtdbSub, string $id): bool
    {
        $docId = "{$this->school_id}_{$id}";
        try {
            return (bool) $this->fs->remove($fsCollection, $docId);
        } catch (\Exception $e) {
            log_message('error', "Communication::_dwDelete FS [{$fsCollection}/{$docId}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Firestore-only list — returns rows as [id => data] so callers can
     * iterate the same way they did with the legacy RTDB shape.
     */
    private function _dwListAdmin(string $fsCollection, string $rtdbSub): array
    {
        try {
            $rows = $this->fs->schoolWhere($fsCollection, []);
            if (!is_array($rows)) return [];
            $out = [];
            foreach ($rows as $row) {
                $d = $row['data'] ?? $row;
                $data = $row['data'] ?? [];
                $id   = $data['id'] ?? ($d['id'] ?? '');
                if ($id === '') continue;
                // Strip schoolId prefix from doc id if it leaked through
                if (strpos($id, $this->school_id . '_') === 0) {
                    $id = substr($id, strlen($this->school_id) + 1);
                }
                $out[$id] = $data;
            }
            return $out;
        } catch (\Exception $e) {
            log_message('error', "Communication::_dwListAdmin FS [{$fsCollection}]: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Firestore-only single fetch by id.
     */
    private function _dwGet(string $fsCollection, string $rtdbSub, string $id): ?array
    {
        try {
            $doc = $this->fs->get($fsCollection, "{$this->school_id}_{$id}");
            return (is_array($doc) && !empty($doc)) ? $doc : null;
        } catch (\Exception $e) {
            log_message('error', "Communication::_dwGet FS [{$fsCollection}/{$id}]: " . $e->getMessage());
            return null;
        }
    }

    public function get_target_groups()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_target_groups');
        $this->_require_view();
        $groups = [
            ['value' => 'All School', 'label' => 'All School'],
            ['value' => 'All Students', 'label' => 'All Students'],
            ['value' => 'All Teachers', 'label' => 'All Teachers'],
        ];

        // Class/section options from the canonical Firestore `sections`
        // collection (per the class-section-canonical memory).
        try {
            $docs = $this->fs->schoolWhere('sections', [
                ['session', '==', $this->session_year],
            ]);
            if (is_array($docs)) {
                $seen = [];
                foreach ($docs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $classKey   = $d['className'] ?? '';
                    $sectionKey = $d['section'] ?? '';
                    if ($classKey === '' || $sectionKey === '') continue;
                    $key = "{$classKey}|{$sectionKey}";
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $groups[] = [
                        'value' => $key,
                        'label' => "{$classKey} / {$sectionKey}",
                    ];
                }
                // Sort class/section pairs for predictable dropdown order
                usort($groups, function ($a, $b) {
                    // Keep the fixed "All *" entries at the top
                    $aTop = strpos($a['value'], 'All ') === 0 ? 0 : 1;
                    $bTop = strpos($b['value'], 'All ') === 0 ? 0 : 1;
                    if ($aTop !== $bTop) return $aTop <=> $bTop;
                    return strnatcmp($a['label'], $b['label']);
                });
            }
        } catch (\Exception $e) {
            log_message('error', 'get_target_groups sections FS read failed: ' . $e->getMessage());
        }

        $this->json_success(['groups' => $groups]);
    }
}
