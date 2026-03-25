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
        $this->communication_helper->init($this->firebase, $this->school_name, $this->session_year, $this->parent_db_key);
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

    // ── Path helpers ────────────────────────────────────────────────────
    private function _comm(string $sub = ''): string
    {
        $b = "Schools/{$this->school_name}/Communication";
        return $sub !== '' ? "{$b}/{$sub}" : $b;
    }
    private function _counter(string $type): string { return $this->_comm("Counters/{$type}"); }
    private function _next_id(string $type, string $prefix, int $pad = 4): string
    {
        $path = $this->_counter($type);
        $cur  = (int) ($this->firebase->get($path) ?? 0);
        $next = $cur + 1;
        $this->firebase->set($path, $next);
        return $prefix . str_pad($next, $pad, '0', STR_PAD_LEFT);
    }

    // ====================================================================
    //  PAGE ROUTES
    // ====================================================================

    public function index()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'comm_view');

        // ── Pre-load dashboard data server-side (no AJAX spinner) ────
        $role  = $this->_inbox_role();
        $inbox = $this->firebase->get($this->_comm("Messages/Inbox/{$role}/{$this->admin_id}"));
        $totalConversations = is_array($inbox) ? count($inbox) : 0;
        $totalUnread = 0;
        if (is_array($inbox)) {
            foreach ($inbox as $conv) {
                if (is_array($conv)) $totalUnread += (int) ($conv['unread_count'] ?? 0);
            }
        }

        $allNotices = $this->firebase->get($this->_comm('Notices'));
        $noticeCount = 0;
        $recentNotices = [];
        if (is_array($allNotices)) {
            foreach ($allNotices as $id => $n) {
                if (!is_array($n) || $id === 'Counter') continue;
                $noticeCount++;
                $n['id'] = $id;
                $recentNotices[] = $n;
            }
            usort($recentNotices, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            $recentNotices = array_slice($recentNotices, 0, 5);
        }

        $circulars = $this->firebase->shallow_get($this->_comm('Circulars'));
        $circularCount = is_array($circulars) ? count(array_filter($circulars, fn($k) => $k !== 'Counter', ARRAY_FILTER_USE_KEY)) : 0;

        $queueKeys = $this->firebase->shallow_get($this->_comm('Queue'));
        $queuePending = 0; $queueSent = 0; $queueFailed = 0;
        if (is_array($queueKeys)) {
            $queueCount = count($queueKeys);
            if ($queueCount <= 200) {
                $queue = $this->firebase->get($this->_comm('Queue'));
                if (is_array($queue)) {
                    foreach ($queue as $q) {
                        if (!is_array($q)) continue;
                        $s = $q['status'] ?? '';
                        if ($s === 'pending') $queuePending++;
                        elseif ($s === 'sent') $queueSent++;
                        elseif ($s === 'failed') $queueFailed++;
                    }
                }
            } else {
                $queuePending = $queueCount;
            }
        }

        $data = [
            'active_tab'     => 'dashboard',
            'conversations'  => $totalConversations,
            'unread_messages'=> $totalUnread,
            'notices'        => $noticeCount,
            'circulars'      => $circularCount,
            'queue_pending'  => $queuePending,
            'queue_sent'     => $queueSent,
            'queue_failed'   => $queueFailed,
            'recent_notices' => $recentNotices,
        ];

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

        // Count conversations + unread for current user
        $role = $this->_inbox_role();
        $inbox = $this->firebase->get($this->_comm("Messages/Inbox/{$role}/{$this->admin_id}"));
        $totalConversations = is_array($inbox) ? count($inbox) : 0;
        $totalUnread = 0;
        if (is_array($inbox)) {
            foreach ($inbox as $conv) {
                if (is_array($conv)) $totalUnread += (int) ($conv['unread_count'] ?? 0);
            }
        }

        // Count notices + fetch recent in single read (avoid duplicate fetch)
        $allNotices = $this->firebase->get($this->_comm('Notices'));
        $noticeCount = 0;
        $recentNotices = [];
        if (is_array($allNotices)) {
            foreach ($allNotices as $id => $n) {
                if (!is_array($n) || $id === 'Counter') continue;
                $noticeCount++;
                $n['id'] = $id;
                $recentNotices[] = $n;
            }
            usort($recentNotices, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            $recentNotices = array_slice($recentNotices, 0, 5);
        }

        // Count circulars (shallow — no need for full data)
        $circulars = $this->firebase->shallow_get($this->_comm('Circulars'));
        $circularCount = is_array($circulars) ? count(array_filter($circulars, fn($k) => $k !== 'Counter', ARRAY_FILTER_USE_KEY)) : 0;

        // Queue stats — use shallow_get + selective reads for pending/failed only
        $queueKeys = $this->firebase->shallow_get($this->_comm('Queue'));
        $queuePending = 0; $queueSent = 0; $queueFailed = 0;
        if (is_array($queueKeys)) {
            // Only fetch status field via targeted reads for recent items
            // For large queues, rely on counter-based stats
            $queueCount = count($queueKeys);
            if ($queueCount <= 200) {
                $queue = $this->firebase->get($this->_comm('Queue'));
                if (is_array($queue)) {
                    foreach ($queue as $q) {
                        if (!is_array($q)) continue;
                        $s = $q['status'] ?? '';
                        if ($s === 'pending') $queuePending++;
                        elseif ($s === 'sent') $queueSent++;
                        elseif ($s === 'failed') $queueFailed++;
                    }
                }
            } else {
                // Approximate: show total count, details via queue page
                $queuePending = $queueCount;
            }
        }

        $this->json_success([
            'conversations'   => $totalConversations,
            'unread_messages' => $totalUnread,
            'notices'         => $noticeCount,
            'circulars'       => $circularCount,
            'queue_pending'   => $queuePending,
            'queue_sent'      => $queueSent,
            'queue_failed'    => $queueFailed,
            'recent_notices'  => $recentNotices,
        ]);
    }

    // ====================================================================
    //  MESSAGING
    // ====================================================================

    private function _inbox_role(): string
    {
        if (in_array($this->admin_role, ['Super Admin', 'School Super Admin', 'Principal', 'Vice Principal', 'Admin'], true))
            return 'Admin';
        if ($this->admin_role === 'Teacher') return 'Teacher';
        if ($this->admin_role === 'HR Manager') return 'HR';
        return 'Admin';
    }

    public function get_conversations()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_conversations');
        $this->_require_view();
        $role  = $this->_inbox_role();
        $inbox = $this->firebase->get($this->_comm("Messages/Inbox/{$role}/{$this->admin_id}"));
        $list  = [];

        if (is_array($inbox)) {
            // Batch load: fetch all conversations in one read to avoid N+1
            $allConvs = $this->firebase->get($this->_comm('Messages/Conversations'));

            foreach ($inbox as $convId => $meta) {
                $conv = (is_array($allConvs) && isset($allConvs[$convId]) && is_array($allConvs[$convId]))
                    ? $allConvs[$convId]
                    : null;
                if ($conv === null) continue;
                $conv['id']           = $convId;
                $conv['unread_count'] = is_array($meta) ? (int) ($meta['unread_count'] ?? 0) : 0;
                $conv['last_seen_at'] = is_array($meta) ? ($meta['last_seen_at'] ?? '') : '';
                $list[] = $conv;
            }
            usort($list, fn($a, $b) => strcmp($b['last_message_at'] ?? '', $a['last_message_at'] ?? ''));
        }

        $page  = max(1, (int) ($this->input->get('page') ?? 1));
        $limit = min(100, max(1, (int) ($this->input->get('limit') ?? 20)));
        $total = count($list);
        $list  = array_slice($list, ($page - 1) * $limit, $limit);

        $this->json_success(['conversations' => $list, 'page' => $page, 'limit' => $limit, 'total' => $total]);
    }

    public function get_messages()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_messages');
        $this->_require_view();
        $convId = $this->safe_path_segment(trim($this->input->get('conversation_id') ?? ''), 'conversation_id');

        // Verify participant
        $conv = $this->firebase->get($this->_comm("Messages/Conversations/{$convId}"));
        if (!is_array($conv)) $this->json_error('Conversation not found.');
        $participants = $conv['participants'] ?? [];
        if (!isset($participants[$this->admin_id])) $this->json_error('Access denied.', 403);

        // Fetch messages
        $chat = $this->firebase->get($this->_comm("Messages/Chat/{$convId}"));
        $msgs = [];
        if (is_array($chat)) {
            foreach ($chat as $id => $m) {
                if (!is_array($m)) continue;
                $m['id'] = $id;
                $msgs[] = $m;
            }
            usort($msgs, fn($a, $b) => strcmp($a['sent_at'] ?? '', $b['sent_at'] ?? ''));
        }

        // Mark as read
        $role = $this->_inbox_role();
        $this->firebase->update($this->_comm("Messages/Inbox/{$role}/{$this->admin_id}/{$convId}"), [
            'unread_count' => 0,
            'last_seen_at' => date('c'),
        ]);

        // Always paginate — default 50, max 100
        $page  = max(1, (int) ($this->input->get('page') ?? 1));
        $limit = min(100, max(1, (int) ($this->input->get('limit') ?? 50)));
        $total = count($msgs);
        // Reverse paginate: page 1 = newest messages
        $reversed = array_reverse($msgs);
        $slice = array_slice($reversed, ($page - 1) * $limit, $limit);
        $msgs = array_reverse($slice);

        $this->json_success([
            'messages'     => $msgs,
            'conversation' => $conv,
            'page'         => $page,
            'limit'        => $limit,
            'total'        => $total,
        ]);
    }

    public function create_conversation()
    {
        $this->_require_role(self::RBAC_MANAGE_ROLES, 'create_conversation');
        $this->_require_teacher();
        $recipientId    = $this->safe_path_segment(trim($this->input->post('recipient_id') ?? ''), 'recipient_id');
        $recipientRole  = trim($this->input->post('recipient_role') ?? '');
        $recipientName  = $this->_sanitize_html(trim($this->input->post('recipient_name') ?? ''));
        $studentId      = trim($this->input->post('student_id') ?? '');
        $studentClass   = trim($this->input->post('student_class') ?? '');
        $studentSection = trim($this->input->post('student_section') ?? '');
        $initialMsg     = trim($this->input->post('message') ?? '');

        if ($recipientId === '' || $recipientName === '') $this->json_error('Recipient is required.');
        if ($initialMsg === '') $this->json_error('Message is required.');
        $this->_validate_length($initialMsg, 'Message', self::MAX_MESSAGE_LENGTH);
        $this->_validate_length($recipientName, 'Recipient name', self::MAX_TITLE_LENGTH);

        // Validate recipient exists
        $session = $this->session_year;
        $recipientExists = false;
        $adminData = $this->firebase->get("Schools/{$this->school_name}/{$session}/Admins/{$recipientId}");
        if (is_array($adminData)) { $recipientExists = true; }
        if (!$recipientExists) {
            $teacherData = $this->firebase->get("Schools/{$this->school_name}/{$session}/Teachers/{$recipientId}");
            if (is_array($teacherData)) { $recipientExists = true; }
        }
        if (!$recipientExists) $this->json_error('Recipient not found.');

        // Sanitize student context path segments
        if ($studentId !== '') $studentId = $this->safe_path_segment($studentId, 'student_id');
        if ($studentClass !== '') $studentClass = $this->_sanitize_html($studentClass);
        if ($studentSection !== '') $studentSection = $this->_sanitize_html($studentSection);

        // Check for existing conversation between these two about same student
        // Batch load all conversations to avoid N+1 reads
        $myRole = $this->_inbox_role();
        $myInbox = $this->firebase->get($this->_comm("Messages/Inbox/{$myRole}/{$this->admin_id}"));
        $existingConvId = null;
        if (is_array($myInbox) && count($myInbox) > 0) {
            $allConvs = $this->firebase->get($this->_comm('Messages/Conversations'));
            if (is_array($allConvs)) {
                foreach ($myInbox as $cId => $meta) {
                    $c = $allConvs[$cId] ?? null;
                    if (!is_array($c)) continue;
                    if (isset($c['participants'][$recipientId]) && isset($c['participants'][$this->admin_id])) {
                        $ctx = $c['context'] ?? [];
                        if (($ctx['student_id'] ?? '') === $studentId) {
                            $existingConvId = $cId;
                            break;
                        }
                    }
                }
            }
        }

        if ($existingConvId) {
            // Add message to existing conversation
            $convId = $existingConvId;
        } else {
            // Create new conversation
            $convId = $this->_next_id('Conversation', 'CONV');
            $participants = [
                $this->admin_id => $myRole,
                $recipientId    => $recipientRole,
            ];
            $this->firebase->set($this->_comm("Messages/Conversations/{$convId}"), [
                'participants'      => $participants,
                'participant_names' => [
                    $this->admin_id => $this->admin_name,
                    $recipientId    => $recipientName,
                ],
                'type'              => 'direct',
                'title'             => '',
                'context'           => [
                    'student_id' => $studentId,
                    'class'      => $studentClass,
                    'section'    => $studentSection,
                ],
                'last_message'      => '',
                'last_message_at'   => '',
                'last_sender_id'    => '',
                'created_by'        => $this->admin_id,
                'created_at'        => date('c'),
                'status'            => 'active',
            ]);

            // Create inbox entries for both participants
            $recipientInboxRole = $recipientRole === 'Teacher' ? 'Teacher' : 'Admin';
            $this->firebase->set($this->_comm("Messages/Inbox/{$myRole}/{$this->admin_id}/{$convId}"), [
                'unread_count' => 0,
                'last_seen_at' => date('c'),
            ]);
            $this->firebase->set($this->_comm("Messages/Inbox/{$recipientInboxRole}/{$recipientId}/{$convId}"), [
                'unread_count' => 0,
                'last_seen_at' => '',
            ]);
        }

        // Send the initial message
        $this->_add_message($convId, $initialMsg);

        $this->json_success(['conversation_id' => $convId, 'message' => 'Conversation created.']);
    }

    public function send_message()
    {
        $this->_require_role(self::RBAC_MANAGE_ROLES, 'send_message');
        $this->_require_teacher();
        $convId  = $this->safe_path_segment(trim($this->input->post('conversation_id') ?? ''), 'conversation_id');
        $message = trim($this->input->post('message') ?? '');

        if ($message === '') $this->json_error('Message is required.');
        $this->_validate_length($message, 'Message', self::MAX_MESSAGE_LENGTH);

        // Verify participant
        $conv = $this->firebase->get($this->_comm("Messages/Conversations/{$convId}"));
        if (!is_array($conv)) $this->json_error('Conversation not found.');
        if (!isset($conv['participants'][$this->admin_id])) $this->json_error('Access denied.', 403);

        $msgId = $this->_add_message($convId, $message);
        $this->json_success(['message_id' => $msgId, 'message' => 'Sent.']);
    }

    private function _add_message(string $convId, string $text, string $type = 'text'): string
    {
        $msgId = $this->_next_id('Message', 'MSG', 5);
        $now   = date('c');
        $role  = $this->_inbox_role();

        $this->firebase->set($this->_comm("Messages/Chat/{$convId}/{$msgId}"), [
            'sender_id'       => $this->admin_id,
            'sender_role'     => $role,
            'sender_name'     => $this->admin_name,
            'message_text'    => $text,
            'attachment_url'  => '',
            'attachment_name' => '',
            'sent_at'         => $now,
            'type'            => $type,
        ]);

        // Fetch conversation once (needed for participants), then update metadata
        $conv = $this->firebase->get($this->_comm("Messages/Conversations/{$convId}"));
        if (!is_array($conv)) return $msgId;

        $this->firebase->update($this->_comm("Messages/Conversations/{$convId}"), [
            'last_message'    => mb_substr($text, 0, 100),
            'last_message_at' => $now,
            'last_sender_id'  => $this->admin_id,
        ]);

        // Increment unread for other participants (uses already-fetched conv)
        if (is_array($conv['participants'] ?? null)) {
            foreach ($conv['participants'] as $pid => $pRole) {
                if ($pid === $this->admin_id) continue;
                $inboxRole = ($pRole === 'Teacher') ? 'Teacher' : 'Admin';
                $inboxPath = $this->_comm("Messages/Inbox/{$inboxRole}/{$pid}/{$convId}");
                $current   = $this->firebase->get($inboxPath);
                $unread    = is_array($current) ? (int) ($current['unread_count'] ?? 0) : 0;
                $this->firebase->update($inboxPath, ['unread_count' => $unread + 1]);
            }
        }

        return $msgId;
    }

    public function mark_read()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'mark_read');
        $this->_require_view();
        $convId = $this->safe_path_segment(trim($this->input->post('conversation_id') ?? ''), 'conversation_id');
        $role   = $this->_inbox_role();
        $this->firebase->update($this->_comm("Messages/Inbox/{$role}/{$this->admin_id}/{$convId}"), [
            'unread_count' => 0,
            'last_seen_at' => date('c'),
        ]);
        $this->json_success(['message' => 'Marked as read.']);
    }

    public function get_unread_count()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_unread_count');
        $this->_require_view();
        $role  = $this->_inbox_role();
        $inbox = $this->firebase->get($this->_comm("Messages/Inbox/{$role}/{$this->admin_id}"));
        $total = 0;
        if (is_array($inbox)) {
            foreach ($inbox as $conv) {
                if (is_array($conv)) $total += (int) ($conv['unread_count'] ?? 0);
            }
        }
        $this->json_success(['unread' => $total]);
    }

    public function search_recipients()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'search_recipients');
        $this->_require_teacher();
        $query = strtolower(trim($this->input->get('q') ?? ''));
        if (mb_strlen($query) < 2) $this->json_error('Enter at least 2 characters.');
        if (mb_strlen($query) > 100) $this->json_error('Search query too long.');

        $results = [];
        $session = $this->session_year;
        $maxResults = 20;

        // Search Admins
        $admins = $this->firebase->get("Schools/{$this->school_name}/{$session}/Admins");
        if (is_array($admins)) {
            foreach ($admins as $id => $a) {
                if (count($results) >= $maxResults) break;
                if (!is_array($a) || $id === $this->admin_id) continue;
                $name = $a['Name'] ?? '';
                if (stripos($name, $query) !== false || stripos($id, $query) !== false) {
                    $results[] = ['id' => $id, 'name' => $this->_sanitize_html($name), 'role' => 'Admin', 'label' => $this->_sanitize_html("{$name} ({$id}) - Admin")];
                }
            }
        }

        // Search Teachers (only if we haven't hit cap)
        if (count($results) < $maxResults) {
            $teachers = $this->firebase->get("Schools/{$this->school_name}/{$session}/Teachers");
            if (is_array($teachers)) {
                foreach ($teachers as $id => $t) {
                    if (count($results) >= $maxResults) break;
                    if (!is_array($t) || $id === $this->admin_id) continue;
                    $name = $t['Name'] ?? '';
                    if (stripos($name, $query) !== false || stripos($id, $query) !== false) {
                        $results[] = ['id' => $id, 'name' => $this->_sanitize_html($name), 'role' => 'Teacher', 'label' => $this->_sanitize_html("{$name} ({$id}) - Teacher")];
                    }
                }
            }
        }

        $this->json_success(['recipients' => $results]);
    }

    // ====================================================================
    //  NOTICES
    // ====================================================================

    public function get_notices()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_notices');
        $this->_require_view();
        $all = $this->firebase->get($this->_comm('Notices'));
        $list = [];
        if (is_array($all)) {
            foreach ($all as $id => $n) {
                if (!is_array($n) || $id === 'Counter') continue;
                $n['id'] = $id;
                $list[] = $n;
            }
            usort($list, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        }

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

        $this->firebase->set($this->_comm("Notices/{$id}"), $data);

        // Dual-write to legacy path for mobile app compatibility
        if ($isNew) {
            $this->_distribute_notice_legacy($id, $data);
        }

        $this->json_success(['id' => $id, 'message' => 'Notice saved.']);
    }

    /**
     * Write notice to legacy paths for mobile app compatibility.
     */
    private function _distribute_notice_legacy(string $noticeId, array $data): void
    {
        $session = $this->session_year;
        $school  = $this->school_name;
        $basePath = "Schools/{$school}/{$session}/All Notices";

        // Get counter
        $count = (int) ($this->firebase->get("{$basePath}/Count") ?? 0);
        $legacyId = 'NOT' . str_pad($count, 4, '0', STR_PAD_LEFT);
        $this->firebase->set("{$basePath}/Count", $count + 1);

        $ts = round(microtime(true) * 1000);
        $legacyNotice = [
            'Title'       => $data['title'],
            'Description' => $data['description'],
            'From Id'     => $this->admin_id,
            'From Type'   => 'Admin',
            'Priority'    => $data['priority'] ?? 'Normal',
            'Category'    => $data['category'] ?? 'General',
            'Timestamp'   => $ts,
            'To Id'       => [],
        ];
        $this->firebase->set("{$basePath}/{$legacyId}", $legacyNotice);

        $target = $data['target_group'] ?? 'All School';

        // Distribute based on target
        if ($target === 'All School' || $target === 'All Students') {
            $this->firebase->set(
                "Schools/{$school}/{$session}/Announcements/{$target}/{$legacyId}",
                $ts
            );
            // Write to each class/section Notification node
            $sessionKeys = $this->firebase->shallow_get("Schools/{$school}/{$session}");
            foreach ((array) $sessionKeys as $classKey) {
                if (strpos($classKey, 'Class ') !== 0) continue;
                $sectionKeys = $this->firebase->shallow_get("Schools/{$school}/{$session}/{$classKey}");
                foreach ((array) $sectionKeys as $sectionKey) {
                    if (strpos($sectionKey, 'Section ') !== 0) continue;
                    $this->firebase->set(
                        "Schools/{$school}/{$session}/{$classKey}/{$sectionKey}/Notification/{$legacyId}",
                        $ts
                    );
                }
            }
        }

        if ($target === 'All School' || $target === 'All Teachers') {
            $this->firebase->set(
                "Schools/{$school}/{$session}/Announcements/All Teachers/{$legacyId}",
                $ts
            );
            $teachers = $this->firebase->get("Schools/{$school}/{$session}/Teachers");
            if (is_array($teachers)) {
                foreach ($teachers as $tid => $t) {
                    if (!is_array($t)) continue;
                    $this->firebase->set(
                        "Schools/{$school}/{$session}/Teachers/{$tid}/Received/{$legacyId}",
                        $ts
                    );
                }
            }
        }

        // Sender's sent log
        $this->firebase->set(
            "Schools/{$school}/{$session}/Admins/{$this->admin_id}/Sent/{$legacyId}",
            $ts
        );

        // Update legacy notice To Id
        $toId = [];
        if ($target === 'All School') $toId['All School'] = '';
        elseif ($target === 'All Students') $toId['All Students'] = '';
        elseif ($target === 'All Teachers') $toId['All Teachers'] = '';
        else $toId[$target] = '';
        $this->firebase->update("{$basePath}/{$legacyId}", ['To Id' => $toId, 'Timestamp' => $ts]);
    }

    public function delete_notice()
    {
        $this->_require_role(self::RBAC_MANAGE_ROLES, 'delete_notice');
        $this->_require_admin();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'notice_id');
        $this->firebase->delete($this->_comm('Notices'), $id);
        $this->json_success(['message' => 'Notice deleted.']);
    }

    // ====================================================================
    //  CIRCULARS
    // ====================================================================

    public function get_circulars()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_circulars');
        $this->_require_view();
        $all = $this->firebase->get($this->_comm('Circulars'));
        $list = [];
        if (is_array($all)) {
            foreach ($all as $id => $c) {
                if (!is_array($c) || $id === 'Counter') continue;
                $c['id'] = $id;
                $list[] = $c;
            }
            usort($list, fn($a, $b) => strcmp($b['issued_date'] ?? '', $a['issued_date'] ?? ''));
        }

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
        }

        // Handle file upload
        $attachmentUrl  = trim($this->input->post('existing_attachment_url') ?? '');
        $attachmentName = trim($this->input->post('existing_attachment_name') ?? '');

        if (!empty($_FILES['attachment']['name'])) {
            $uploadDir = FCPATH . 'uploads/circulars/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Validate MIME type server-side (don't trust extension alone)
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

            $config['upload_path']   = $uploadDir;
            $config['allowed_types'] = 'pdf|doc|docx|jpg|jpeg|png';
            $config['max_size']      = 5120; // 5MB
            $config['file_name']     = $id . '_' . time();
            $config['file_ext_tolower'] = true;
            $this->load->library('upload', $config);

            if ($this->upload->do_upload('attachment')) {
                $uploadData     = $this->upload->data();
                $attachmentUrl  = base_url('uploads/circulars/' . $uploadData['file_name']);
                $attachmentName = basename($uploadData['orig_name']);
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

        $this->firebase->set($this->_comm("Circulars/{$id}"), $data);

        // Also distribute as a notice for mobile app visibility
        if ($isNew) {
            $this->_distribute_notice_legacy($id, [
                'title'       => "[Circular] {$title}",
                'description' => $description,
                'priority'    => 'High',
                'category'    => $category,
                'target_group' => $targetGroup,
            ]);
        }

        $this->json_success(['id' => $id, 'message' => 'Circular saved.']);
    }

    public function delete_circular()
    {
        $this->_require_role(self::RBAC_MANAGE_ROLES, 'delete_circular');
        $this->_require_admin();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'circular_id');
        $this->firebase->delete($this->_comm('Circulars'), $id);
        $this->json_success(['message' => 'Circular deleted.']);
    }

    public function acknowledge_circular()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'acknowledge_circular');
        $this->_require_view();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'circular_id');
        $this->firebase->set(
            $this->_comm("Circulars/{$id}/acknowledgements/{$this->admin_id}"),
            date('c')
        );
        $this->json_success(['message' => 'Acknowledged.']);
    }

    // ====================================================================
    //  TEMPLATES
    // ====================================================================

    public function get_templates()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_templates');
        $this->_require_admin();
        $all = $this->firebase->get($this->_comm('Templates'));
        $list = [];
        if (is_array($all)) {
            foreach ($all as $id => $t) {
                if (!is_array($t) || $id === 'Counter') continue;
                $t['id'] = $id;
                $list[] = $t;
            }
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

        $this->firebase->set($this->_comm("Templates/{$id}"), $data);
        $this->json_success(['id' => $id, 'message' => 'Template saved.']);
    }

    public function delete_template()
    {
        $this->_require_role(self::RBAC_MANAGE_ROLES, 'delete_template');
        $this->_require_admin();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'template_id');

        // Check if any trigger uses this template
        $triggers = $this->firebase->get($this->_comm('Triggers'));
        if (is_array($triggers)) {
            foreach ($triggers as $tId => $trg) {
                if (!is_array($trg) || $tId === 'Counter') continue;
                if (($trg['template_id'] ?? '') === $id && !empty($trg['enabled'])) {
                    $this->json_error('Cannot delete: template is used by an active trigger.');
                }
            }
        }

        $this->firebase->delete($this->_comm('Templates'), $id);
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
        $all = $this->firebase->get($this->_comm('Triggers'));
        $list = [];
        if (is_array($all)) {
            foreach ($all as $id => $t) {
                if (!is_array($t) || $id === 'Counter') continue;
                $t['id'] = $id;
                $list[] = $t;
            }
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

        // Verify template exists
        $templateId = $this->safe_path_segment($templateId, 'template_id');
        $tpl = $this->firebase->get($this->_comm("Templates/{$templateId}"));
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

        $this->firebase->set($this->_comm("Triggers/{$id}"), $data);
        $this->json_success(['id' => $id, 'message' => 'Trigger saved.']);
    }

    public function delete_trigger()
    {
        $this->_require_role(self::RBAC_ADMIN_ROLES, 'delete_trigger');
        $this->_require_admin();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'trigger_id');
        $this->firebase->delete($this->_comm('Triggers'), $id);
        $this->json_success(['message' => 'Trigger deleted.']);
    }

    public function toggle_trigger()
    {
        $this->_require_role(self::RBAC_ADMIN_ROLES, 'toggle_trigger');
        $this->_require_admin();
        $id      = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'trigger_id');
        $enabled = ($this->input->post('enabled') ?? '1') === '1';
        $this->firebase->update($this->_comm("Triggers/{$id}"), [
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
        $all    = $this->firebase->get($this->_comm('Queue'));
        $list   = [];
        if (is_array($all)) {
            foreach ($all as $id => $q) {
                if (!is_array($q)) continue;
                if ($status !== '' && ($q['status'] ?? '') !== $status) continue;
                $q['id'] = $id;
                $list[] = $q;
            }
            usort($list, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        }

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
        $all = $this->firebase->get($this->_comm('Queue'));
        if (!is_array($all)) {
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

            // Mark as processing
            $this->firebase->update($this->_comm("Queue/{$id}"), ['status' => 'processing']);

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
                $this->firebase->update($this->_comm("Queue/{$id}"), [
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
                $this->firebase->update($this->_comm("Queue/{$id}"), [
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
     * Deliver push notification via legacy Firebase paths.
     */
    private function _deliver_push(array $q): bool
    {
        $recipientId   = $q['recipient_id'] ?? '';
        $recipientType = $q['recipient_type'] ?? '';
        if ($recipientId === '') return false;

        // Validate recipient ID format for path safety
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $recipientId)) return false;
        if (!in_array($recipientType, self::ALLOWED_RECIPIENT_TYPES, true)) return false;

        $session = $this->session_year;
        $school  = $this->school_name;
        $ts      = round(microtime(true) * 1000);

        try {
            // Create a notice entry for push delivery
            $basePath = "Schools/{$school}/{$session}/All Notices";
            $count    = (int) ($this->firebase->get("{$basePath}/Count") ?? 0);
            $noticeId = 'NOT' . str_pad($count, 4, '0', STR_PAD_LEFT);
            $this->firebase->set("{$basePath}/Count", $count + 1);

            $this->firebase->set("{$basePath}/{$noticeId}", [
                'Title'       => $this->_sanitize_html($q['title'] ?? ''),
                'Description' => $this->_sanitize_html($q['message_body'] ?? ''),
                'From Id'     => 'SYSTEM',
                'From Type'   => 'System',
                'Priority'    => $q['priority'] ?? 'Normal',
                'Category'    => 'Automated',
                'Timestamp'   => $ts,
                'To Id'       => [$recipientId => ''],
            ]);

            // Deliver to recipient's notification path
            if ($recipientType === 'parent' || $recipientType === 'student') {
                $student = $this->firebase->get("Users/Parents/{$this->parent_db_key}/{$recipientId}");
                if (is_array($student)) {
                    $cls = $student['Class'] ?? '';
                    $sec = $student['Section'] ?? '';
                    if ($cls !== '' && $sec !== '') {
                        $cls = $this->safe_path_segment('Class ' . $cls, 'class');
                        $sec = $this->safe_path_segment('Section ' . $sec, 'section');
                        $this->firebase->set(
                            "Schools/{$school}/{$session}/{$cls}/{$sec}/Students/{$recipientId}/Notification/{$noticeId}",
                            $ts
                        );
                    }
                }
            } elseif ($recipientType === 'teacher') {
                $this->firebase->set(
                    "Schools/{$school}/{$session}/Teachers/{$recipientId}/Received/{$noticeId}",
                    $ts
                );
            }

            return true;
        } catch (\Exception $e) {
            log_message('error', 'Communication push delivery failed: ' . $e->getMessage());
            return false;
        }
    }

    private function _write_log(string $queueId, array $q, string $status, string $code, string $message): void
    {
        $logId = $this->_next_id('Log', 'LOG', 5);

        $this->firebase->set($this->_comm("Logs/{$logId}"), [
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
        $q  = $this->firebase->get($this->_comm("Queue/{$id}"));
        if (!is_array($q)) $this->json_error('Queue item not found.');
        if (($q['status'] ?? '') !== 'pending') $this->json_error('Only pending items can be cancelled.');
        $this->firebase->update($this->_comm("Queue/{$id}"), [
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
        $q  = $this->firebase->get($this->_comm("Queue/{$id}"));
        if (!is_array($q)) $this->json_error('Queue item not found.');
        if (($q['status'] ?? '') !== 'failed') $this->json_error('Only failed items can be retried.');
        $this->firebase->update($this->_comm("Queue/{$id}"), [
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
        $all = $this->firebase->get($this->_comm('Logs'));
        $list = [];
        if (is_array($all)) {
            foreach ($all as $id => $l) {
                if (!is_array($l)) continue;
                $l['id'] = $id;
                $list[] = $l;
            }
            usort($list, fn($a, $b) => strcmp($b['logged_at'] ?? '', $a['logged_at'] ?? ''));
        }

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

        $all = $this->firebase->get($this->_comm('Logs'));
        $stats = ['total' => 0, 'delivered' => 0, 'failed' => 0, 'bounced' => 0, 'by_channel' => []];

        if (is_array($all)) {
            foreach ($all as $l) {
                if (!is_array($l)) continue;
                $stats['total']++;
                $s = $l['status'] ?? '';
                if (isset($stats[$s])) $stats[$s]++;
                $ch = $l['channel'] ?? 'unknown';
                $stats['by_channel'][$ch] = ($stats['by_channel'][$ch] ?? 0) + 1;
            }
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

        // COMM-2 fix: use SIS/Students_Index (1 read) instead of traversing class/section tree (N reads)
        if ($targetGroup === 'All Students' || $targetGroup === 'All School') {
            $index = $this->firebase->get("Schools/{$school}/SIS/Students_Index");
            if (is_array($index)) {
                foreach ($index as $sid => $info) {
                    if (!is_array($info)) continue;
                    if (($info['status'] ?? '') !== 'Active') continue;
                    $recipients[] = ['id' => $sid, 'name' => (string) ($info['name'] ?? $sid), 'type' => 'student'];
                }
            }
        }

        if ($targetGroup === 'All Teachers' || $targetGroup === 'All School') {
            $teachers = $this->firebase->get("Schools/{$school}/{$session}/Teachers");
            if (is_array($teachers)) {
                foreach ($teachers as $tid => $t) {
                    if (!is_array($t)) continue;
                    $recipients[] = ['id' => $tid, 'name' => $t['Name'] ?? $tid, 'type' => 'teacher'];
                }
            }
        }

        // Cap recipients to prevent abuse
        if (count($recipients) > self::MAX_BULK_RECIPIENTS) {
            $this->json_error('Too many recipients. Maximum is ' . self::MAX_BULK_RECIPIENTS . '.');
        }

        if (empty($recipients)) {
            $this->json_error('No recipients found for the selected target group.');
        }

        // PERF-1 fix: bulk-allocate queue IDs (2 Firebase ops instead of 2*N)
        $totalRecipients = count($recipients);
        $counterPath     = $this->_counter('Queue');
        $curCounter      = (int) ($this->firebase->get($counterPath) ?? 0);
        $newCounter      = $curCounter + $totalRecipients;
        $this->firebase->set($counterPath, $newCounter);

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

        // PERF-1 fix: single batch write instead of N individual writes
        $this->firebase->update($this->_comm('Queue'), $batchData);
        $queued = $totalRecipients;

        $this->json_success(['queued' => $queued, 'message' => "{$queued} messages queued for delivery."]);
    }

    // ====================================================================
    //  CLASS LIST HELPER (for target group dropdowns)
    // ====================================================================

    public function get_target_groups()
    {
        $this->_require_role(self::RBAC_VIEW_ROLES, 'get_target_groups');
        $this->_require_view();
        $groups = [
            ['value' => 'All School', 'label' => 'All School'],
            ['value' => 'All Students', 'label' => 'All Students'],
            ['value' => 'All Teachers', 'label' => 'All Teachers'],
        ];

        // Add class/section options
        $sessionKeys = $this->firebase->shallow_get("Schools/{$this->school_name}/{$this->session_year}");
        foreach ((array) $sessionKeys as $classKey) {
            if (strpos($classKey, 'Class ') !== 0) continue;
            $sectionKeys = $this->firebase->shallow_get("Schools/{$this->school_name}/{$this->session_year}/{$classKey}");
            foreach ((array) $sectionKeys as $sectionKey) {
                if (strpos($sectionKey, 'Section ') !== 0) continue;
                $groups[] = [
                    'value' => "{$classKey}|{$sectionKey}",
                    'label' => "{$classKey} / {$sectionKey}",
                ];
            }
        }

        $this->json_success(['groups' => $groups]);
    }
}
