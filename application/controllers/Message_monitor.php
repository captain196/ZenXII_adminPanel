<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Message_monitor — Admin portal module for monitoring teacher-parent messages.
 *
 * Read-only surveillance of conversations created by the mobile apps, plus
 * moderation tools for admins. Provides analytics, keyword search, flagged-
 * content scanning, activity timelines, and per-teacher statistics.
 *
 * Firebase paths (written by mobile apps):
 *   Schools/{school_id}/Messages/Conversations/{conversationId}
 *     - teacherId, teacherName, parentId, parentName, studentName, studentClass
 *     - lastMessage, lastMessageTime, unreadCount: {userId: n}
 *     - messages/{pushId}: {senderId, senderName, message, timestamp, readBy}
 *
 * Admin-only paths (written by this module):
 *   Schools/{school_id}/Messages/FlaggedKeywords    — configurable word list
 *   Schools/{school_id}/Messages/ModerationLog      — admin action audit trail
 */
class Message_monitor extends MY_Controller
{
    /** Roles allowed full access (view + moderate + delete). */
    private const ADMIN_ROLES = [
        'Super Admin',
        'School Super Admin',
        'Admin',
        'Principal',
        'Vice Principal',
    ];

    /** Roles allowed view-only access. */
    private const VIEW_ROLES = [
        'Super Admin',
        'School Super Admin',
        'Admin',
        'Principal',
        'Vice Principal',
    ];

    /** Default flagged keywords when none are configured in Firebase. */
    private const DEFAULT_FLAGGED_KEYWORDS = [
        'abuse', 'threat', 'harass', 'bribe', 'cheat', 'fraud',
        'inappropriate', 'violence', 'bully', 'assault',
    ];

    /** Maximum response-time gap (24 h in ms) — ignore outliers beyond this. */
    private const MAX_RESPONSE_GAP_MS = 86400000;

    /** Maximum keyword count for the flagged-keywords list. */
    private const MAX_KEYWORD_COUNT = 100;

    /** Maximum search results returned in one request. */
    private const MAX_SEARCH_RESULTS = 200;

    // ─────────────────────────────────────────────────────────────────────
    //  CONSTRUCTOR
    // ─────────────────────────────────────────────────────────────────────

    public function __construct()
    {
        parent::__construct();
        require_permission('Message Monitor');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Enforce admin-level role for write/moderate operations.
     * Terminates with 403 JSON if not an admin role.
     */
    private function _require_admin(): void
    {
        if (!in_array($this->admin_role, self::ADMIN_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
    }

    /**
     * Build a Firebase path under the school's Messages node.
     *
     * @param  string $sub  Optional sub-path (e.g. "Conversations/abc123")
     * @return string       Full Firebase path
     */
    private function _msg_path(string $sub = ''): string
    {
        $base = "Schools/{$this->school_name}/Messages";
        return $sub !== '' ? "{$base}/{$sub}" : $base;
    }

    /**
     * Fetch all conversations from Firebase.
     * Returns an associative array or an empty array when nothing exists.
     */
    private function _fetch_all_conversations(): array
    {
        $data = $this->firebase->get($this->_msg_path('Conversations'));
        return is_array($data) ? $data : [];
    }

    /**
     * Compute teacher response times from a chronologically-sorted message list.
     *
     * Measures the gap between a parent message and the next teacher reply.
     * Ignores gaps > 24 hours as outliers.
     *
     * @param  array  $sorted     Messages sorted by timestamp ascending
     * @param  string $teacherId  The teacher's ID in this conversation
     * @return array              Array of response time values in milliseconds
     */
    private function _calc_response_times(array $sorted, string $teacherId): array
    {
        $times = [];
        $count = count($sorted);

        for ($i = 0; $i < $count - 1; $i++) {
            $curr = $sorted[$i];
            $next = $sorted[$i + 1];

            $currSender = $curr['senderId'] ?? '';
            $nextSender = $next['senderId'] ?? '';

            // Parent → Teacher transition
            if ($currSender !== $teacherId && $nextSender === $teacherId) {
                $diff = ((int) ($next['timestamp'] ?? 0)) - ((int) ($curr['timestamp'] ?? 0));
                if ($diff > 0 && $diff < self::MAX_RESPONSE_GAP_MS) {
                    $times[] = $diff;
                }
            }
        }

        return $times;
    }

    /**
     * Sort an associative array of messages by timestamp ascending.
     * Strips non-array entries and preserves the push-ID key.
     *
     * @param  array $messages  Raw messages node from Firebase
     * @return array            Indexed array of [pushId => ..., ...msg] sorted by timestamp
     */
    private function _sort_messages(array $messages): array
    {
        $sorted = [];
        foreach ($messages as $pushId => $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $msg['_id'] = $pushId;
            $sorted[]   = $msg;
        }
        usort($sorted, static fn($a, $b) => ((int) ($a['timestamp'] ?? 0)) - ((int) ($b['timestamp'] ?? 0)));
        return $sorted;
    }

    /**
     * Load the configurable flagged keyword list from Firebase.
     * Falls back to DEFAULT_FLAGGED_KEYWORDS when none are stored.
     *
     * @return array  Flat list of keyword strings (lower-cased)
     */
    private function _load_flagged_keywords(): array
    {
        $stored = $this->firebase->get($this->_msg_path('FlaggedKeywords'));

        if (is_array($stored) && !empty($stored)) {
            $keywords = array_values(array_filter($stored, 'is_string'));
            if (!empty($keywords)) {
                return array_map('strtolower', $keywords);
            }
        }

        return array_map('strtolower', self::DEFAULT_FLAGGED_KEYWORDS);
    }

    // =====================================================================
    //  PAGE ROUTE
    // =====================================================================

    /**
     * Main message monitoring SPA page.
     */
    public function index(): void
    {
        $this->_require_role(self::VIEW_ROLES, 'message_monitor');

        $data = [
            'page_title'  => 'Message Monitor',
            'active_tab'  => 'dashboard',
        ];

        $this->load->view('include/header', $data);
        $this->load->view('message_monitor/index', $data);
        $this->load->view('include/footer');
    }

    // =====================================================================
    //  AJAX: List conversations with filters & pagination
    // =====================================================================

    /**
     * Get paginated conversations list.
     *
     * POST params:
     *   teacher      — filter by teacher name or ID (partial match)
     *   search       — keyword search across names + last message
     *   class_filter — filter by studentClass (partial match)
     *   date_from    — ISO date string, include conversations active on or after
     *   date_to      — ISO date string, include conversations active on or before
     *   page         — page number (default 1)
     *   per_page     — items per page (10–100, default 50)
     *
     * Returns: { conversations: [...], total, page, pages }
     */
    public function get_conversations(): void
    {
        $this->_require_role(self::VIEW_ROLES, 'message_monitor_conversations');

        // ── Collect & sanitise filters ──
        $teacher     = trim($this->input->post('teacher') ?? '');
        $search      = trim($this->input->post('search') ?? '');
        $classFilter = trim($this->input->post('class_filter') ?? '');
        $dateFrom    = trim($this->input->post('date_from') ?? '');
        $dateTo      = trim($this->input->post('date_to') ?? '');
        $page        = max(1, (int) ($this->input->post('page') ?? 1));
        $perPage     = min(100, max(10, (int) ($this->input->post('per_page') ?? 50)));

        $allConvos = $this->_fetch_all_conversations();
        if (empty($allConvos)) {
            $this->json_success([
                'conversations' => [],
                'total'         => 0,
                'page'          => 1,
                'pages'         => 1,
            ]);
        }

        // ── Apply filters ──
        $filtered = [];

        foreach ($allConvos as $id => $conv) {
            if (!is_array($conv)) {
                continue;
            }

            // Teacher filter (name partial or exact ID match)
            if ($teacher !== '') {
                $nameMatch = stripos($conv['teacherName'] ?? '', $teacher) !== false;
                $idMatch   = ($conv['teacherId'] ?? '') === $teacher;
                if (!$nameMatch && !$idMatch) {
                    continue;
                }
            }

            // Class filter (partial match)
            if ($classFilter !== '' && stripos($conv['studentClass'] ?? '', $classFilter) === false) {
                continue;
            }

            // Date range on lastMessageTime (milliseconds)
            $lastTime = (int) ($conv['lastMessageTime'] ?? 0);

            if ($dateFrom !== '') {
                $fromTs = strtotime($dateFrom);
                if ($fromTs !== false && $lastTime < ($fromTs * 1000)) {
                    continue;
                }
            }
            if ($dateTo !== '') {
                $toTs = strtotime($dateTo);
                // End-of-day: include the entire target date
                if ($toTs !== false && $lastTime >= (($toTs + 86400) * 1000)) {
                    continue;
                }
            }

            // Text search across names + last message
            if ($search !== '') {
                $haystack = strtolower(
                    ($conv['teacherName'] ?? '') . ' ' .
                    ($conv['parentName'] ?? '') . ' ' .
                    ($conv['studentName'] ?? '') . ' ' .
                    ($conv['lastMessage'] ?? '')
                );
                if (strpos($haystack, strtolower($search)) === false) {
                    continue;
                }
            }

            // Message count
            $msgCount = 0;
            if (isset($conv['messages']) && is_array($conv['messages'])) {
                $msgCount = count($conv['messages']);
            }

            $filtered[] = [
                'id'              => $id,
                'teacherId'       => $conv['teacherId'] ?? '',
                'teacherName'     => $conv['teacherName'] ?? '',
                'parentId'        => $conv['parentId'] ?? '',
                'parentName'      => $conv['parentName'] ?? '',
                'studentName'     => $conv['studentName'] ?? '',
                'studentClass'    => $conv['studentClass'] ?? '',
                'lastMessage'     => $conv['lastMessage'] ?? '',
                'lastMessageTime' => $lastTime,
                'messageCount'    => $msgCount,
                'unreadCount'     => $conv['unreadCount'] ?? [],
            ];
        }

        // ── Sort by most recent activity ──
        usort($filtered, static fn($a, $b) => $b['lastMessageTime'] - $a['lastMessageTime']);

        // ── Paginate ──
        $total  = count($filtered);
        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $paged  = array_slice($filtered, $offset, $perPage);

        $this->json_success([
            'conversations' => $paged,
            'total'         => $total,
            'page'          => $page,
            'pages'         => $pages,
        ]);
    }

    // =====================================================================
    //  AJAX: Full conversation detail
    // =====================================================================

    /**
     * Get complete conversation with all messages sorted chronologically.
     *
     * POST param: conversation_id
     *
     * Returns: { conversation: {...meta}, messages: [...] }
     */
    public function get_conversation_detail(): void
    {
        $this->_require_role(self::VIEW_ROLES, 'message_monitor_detail');

        $conversationId = $this->safe_path_segment(
            trim($this->input->post('conversation_id') ?? ''),
            'conversation_id'
        );

        $conv = $this->firebase->get($this->_msg_path("Conversations/{$conversationId}"));
        if (!is_array($conv)) {
            $this->json_error('Conversation not found.', 404);
        }

        // ── Extract and sort messages ──
        $messages = [];
        if (isset($conv['messages']) && is_array($conv['messages'])) {
            $sorted = $this->_sort_messages($conv['messages']);
            foreach ($sorted as $msg) {
                $messages[] = [
                    'id'         => $msg['_id'],
                    'senderId'   => $msg['senderId'] ?? '',
                    'senderName' => $msg['senderName'] ?? '',
                    'message'    => $msg['message'] ?? '',
                    'timestamp'  => (int) ($msg['timestamp'] ?? 0),
                    'readBy'     => $msg['readBy'] ?? [],
                    'moderated'  => !empty($msg['moderated']),
                ];
            }
        }

        $this->json_success([
            'conversation' => [
                'id'              => $conversationId,
                'teacherId'       => $conv['teacherId'] ?? '',
                'teacherName'     => $conv['teacherName'] ?? '',
                'parentId'        => $conv['parentId'] ?? '',
                'parentName'      => $conv['parentName'] ?? '',
                'studentName'     => $conv['studentName'] ?? '',
                'studentClass'    => $conv['studentClass'] ?? '',
                'lastMessage'     => $conv['lastMessage'] ?? '',
                'lastMessageTime' => (int) ($conv['lastMessageTime'] ?? 0),
            ],
            'messages' => $messages,
        ]);
    }

    // =====================================================================
    //  AJAX: Analytics overview
    // =====================================================================

    /**
     * Communication analytics dashboard data.
     *
     * Returns:
     *   total_conversations  — count of all conversation threads
     *   total_messages       — sum of all individual messages
     *   active_today         — conversations with activity today
     *   avg_response_time    — average teacher response time in seconds
     *   daily_volume         — {labels: [...], values: [...]} for last 30 days
     *   teacher_stats        — top 10 teachers by message count
     */
    public function get_analytics(): void
    {
        $this->_require_role(self::VIEW_ROLES, 'message_monitor_analytics');

        $allConvos = $this->_fetch_all_conversations();

        if (empty($allConvos)) {
            $this->json_success([
                'total_conversations'     => 0,
                'total_messages'          => 0,
                'active_today'            => 0,
                'avg_response_time_seconds' => 0,
                'daily_volume'            => ['labels' => [], 'values' => []],
                'teacher_stats'           => [],
            ]);
        }

        $totalConversations = 0;
        $totalMessages      = 0;
        $activeToday        = 0;
        $responseTimes      = [];
        $todayStart         = strtotime('today') * 1000;
        $teacherMsgCounts   = []; // teacherId => [name, count]

        // Daily volume buckets (last 30 days)
        $dailyBuckets = [];
        $thirtyDaysAgo = (time() - (30 * 86400)) * 1000;
        for ($i = 29; $i >= 0; $i--) {
            $dailyBuckets[date('Y-m-d', time() - ($i * 86400))] = 0;
        }

        foreach ($allConvos as $id => $conv) {
            if (!is_array($conv)) {
                continue;
            }

            $totalConversations++;
            $teacherId = $conv['teacherId'] ?? '';

            // Active today?
            $lastTime = (int) ($conv['lastMessageTime'] ?? 0);
            if ($lastTime >= $todayStart) {
                $activeToday++;
            }

            // Process messages
            if (!isset($conv['messages']) || !is_array($conv['messages'])) {
                continue;
            }

            $msgArray = $conv['messages'];
            $totalMessages += count($msgArray);

            // Sort for response time calculation
            $sorted = $this->_sort_messages($msgArray);

            // Response times
            if ($teacherId !== '') {
                $rt = $this->_calc_response_times($sorted, $teacherId);
                if (!empty($rt)) {
                    array_push($responseTimes, ...$rt);
                }
            }

            // Daily volume + teacher stats
            foreach ($sorted as $msg) {
                $ts = (int) ($msg['timestamp'] ?? 0);

                // Daily bucket
                if ($ts >= $thirtyDaysAgo) {
                    $date = date('Y-m-d', (int) ($ts / 1000));
                    if (isset($dailyBuckets[$date])) {
                        $dailyBuckets[$date]++;
                    }
                }

                // Teacher message counts
                $sid = $msg['senderId'] ?? '';
                if ($sid === $teacherId && $teacherId !== '') {
                    if (!isset($teacherMsgCounts[$teacherId])) {
                        $teacherMsgCounts[$teacherId] = [
                            'teacherId'   => $teacherId,
                            'teacherName' => $conv['teacherName'] ?? 'Unknown',
                            'count'       => 0,
                        ];
                    }
                    $teacherMsgCounts[$teacherId]['count']++;
                }
            }
        }

        // Average response time in seconds
        $avgResponseSeconds = 0;
        if (!empty($responseTimes)) {
            $avgResponseSeconds = (int) round(
                (array_sum($responseTimes) / count($responseTimes)) / 1000
            );
        }

        // Top 10 teachers by message count
        usort($teacherMsgCounts, static fn($a, $b) => $b['count'] - $a['count']);
        $topTeachers = array_slice(array_values($teacherMsgCounts), 0, 10);

        $this->json_success([
            'total_conversations'       => $totalConversations,
            'total_messages'            => $totalMessages,
            'active_today'              => $activeToday,
            'avg_response_time_seconds' => $avgResponseSeconds,
            'daily_volume'              => [
                'labels' => array_keys($dailyBuckets),
                'values' => array_values($dailyBuckets),
            ],
            'teacher_stats' => $topTeachers,
        ]);
    }

    // =====================================================================
    //  AJAX: Full-text search across all messages
    // =====================================================================

    /**
     * Search all message texts for a keyword.
     *
     * POST param:
     *   query  — keyword to search for (required, min 2 chars)
     *   limit  — max results (10–200, default 50)
     *
     * Returns: { results: [...], total, query }
     */
    public function search_messages(): void
    {
        $this->_require_role(self::VIEW_ROLES, 'message_monitor_search');

        $query = trim($this->input->post('query') ?? '');
        if (mb_strlen($query) < 2) {
            $this->json_error('Search query must be at least 2 characters.');
        }

        $limit = min(self::MAX_SEARCH_RESULTS, max(10, (int) ($this->input->post('limit') ?? 50)));

        $allConvos = $this->_fetch_all_conversations();
        if (empty($allConvos)) {
            $this->json_success(['results' => [], 'total' => 0, 'query' => $query]);
        }

        $results  = [];
        $qLower   = strtolower($query);

        foreach ($allConvos as $convId => $conv) {
            if (!is_array($conv) || !isset($conv['messages']) || !is_array($conv['messages'])) {
                continue;
            }

            foreach ($conv['messages'] as $msgId => $msg) {
                if (!is_array($msg)) {
                    continue;
                }

                $text = $msg['message'] ?? '';
                if ($text === '' || stripos($text, $query) === false) {
                    continue;
                }

                $results[] = [
                    'conversationId' => $convId,
                    'messageId'      => $msgId,
                    'message'        => $text,
                    'senderId'       => $msg['senderId'] ?? '',
                    'senderName'     => $msg['senderName'] ?? '',
                    'timestamp'      => (int) ($msg['timestamp'] ?? 0),
                    'teacherName'    => $conv['teacherName'] ?? '',
                    'parentName'     => $conv['parentName'] ?? '',
                    'studentName'    => $conv['studentName'] ?? '',
                    'studentClass'   => $conv['studentClass'] ?? '',
                ];

                if (count($results) >= $limit) {
                    break 2;
                }
            }
        }

        // Most recent first
        usort($results, static fn($a, $b) => $b['timestamp'] - $a['timestamp']);

        $this->json_success([
            'results' => $results,
            'total'   => count($results),
            'query'   => $query,
        ]);
    }

    // =====================================================================
    //  AJAX: Per-teacher statistics
    // =====================================================================

    /**
     * Detailed per-teacher communication metrics.
     *
     * Returns per teacher:
     *   conversations_count, messages_sent, total_messages,
     *   avg_response_time (ms), last_active (timestamp ms)
     */
    public function get_teacher_stats(): void
    {
        $this->_require_role(self::VIEW_ROLES, 'message_monitor_teacher_stats');

        $allConvos = $this->_fetch_all_conversations();
        if (empty($allConvos)) {
            $this->json_success(['teachers' => []]);
        }

        $stats = []; // teacherId => {...}

        foreach ($allConvos as $id => $conv) {
            if (!is_array($conv)) {
                continue;
            }

            $teacherId   = $conv['teacherId'] ?? '';
            $teacherName = $conv['teacherName'] ?? 'Unknown';
            if ($teacherId === '') {
                continue;
            }

            // Initialise teacher record
            if (!isset($stats[$teacherId])) {
                $stats[$teacherId] = [
                    'teacherId'          => $teacherId,
                    'teacherName'        => $teacherName,
                    'conversations_count'=> 0,
                    'messages_sent'      => 0,
                    'total_messages'     => 0,
                    'response_times'     => [],
                    'last_active'        => 0,
                ];
            }

            $stats[$teacherId]['conversations_count']++;

            if (!isset($conv['messages']) || !is_array($conv['messages'])) {
                continue;
            }

            $sorted = $this->_sort_messages($conv['messages']);
            $stats[$teacherId]['total_messages'] += count($sorted);

            // Count teacher's sent messages and track last activity
            foreach ($sorted as $msg) {
                if (($msg['senderId'] ?? '') === $teacherId) {
                    $stats[$teacherId]['messages_sent']++;
                    $ts = (int) ($msg['timestamp'] ?? 0);
                    if ($ts > $stats[$teacherId]['last_active']) {
                        $stats[$teacherId]['last_active'] = $ts;
                    }
                }
            }

            // Response time calculation
            $rt = $this->_calc_response_times($sorted, $teacherId);
            if (!empty($rt)) {
                array_push($stats[$teacherId]['response_times'], ...$rt);
            }
        }

        // ── Build output: compute averages and remove raw data ──
        $teachers = [];
        foreach ($stats as $s) {
            $avgRT = 0;
            if (!empty($s['response_times'])) {
                $avgRT = (int) round(array_sum($s['response_times']) / count($s['response_times']));
            }
            $teachers[] = [
                'teacherId'          => $s['teacherId'],
                'teacherName'        => $s['teacherName'],
                'conversations_count'=> $s['conversations_count'],
                'messages_sent'      => $s['messages_sent'],
                'total_messages'     => $s['total_messages'],
                'avg_response_time'  => $avgRT, // milliseconds
                'last_active'        => $s['last_active'],
            ];
        }

        // Sort by conversation count descending
        usort($teachers, static fn($a, $b) => $b['conversations_count'] - $a['conversations_count']);

        $this->json_success(['teachers' => $teachers]);
    }

    // =====================================================================
    //  AJAX: Delete (moderate) a specific message
    // =====================================================================

    /**
     * Admin-delete a specific message. The message content is replaced with a
     * moderation notice; the original text is preserved in ModerationLog and
     * in the message's `originalMessage` field for audit purposes.
     *
     * POST params:
     *   conversation_id — conversation key
     *   message_id      — push-ID of the message to delete
     *   reason          — optional reason string
     */
    public function delete_message(): void
    {
        $this->_require_admin();

        $convId = $this->safe_path_segment(
            trim($this->input->post('conversation_id') ?? ''),
            'conversation_id'
        );
        $msgId = $this->safe_path_segment(
            trim($this->input->post('message_id') ?? ''),
            'message_id'
        );
        $reason = trim($this->input->post('reason') ?? '');
        if ($reason === '') {
            $reason = 'Admin moderation';
        }

        // ── Verify the message exists ──
        $msgPath = $this->_msg_path("Conversations/{$convId}/messages/{$msgId}");
        $msg     = $this->firebase->get($msgPath);
        if (!is_array($msg)) {
            $this->json_error('Message not found.', 404);
        }

        // ── Log moderation action ──
        $nowMs   = round(microtime(true) * 1000);
        $logKey  = "{$convId}_{$msgId}";
        $logData = [
            'action'          => 'deleted',
            'conversationId'  => $convId,
            'messageId'       => $msgId,
            'originalMessage' => $msg['message'] ?? '',
            'senderId'        => $msg['senderId'] ?? '',
            'senderName'      => $msg['senderName'] ?? '',
            'reason'          => $reason,
            'adminId'         => $this->admin_id,
            'adminName'       => $this->admin_name,
            'timestamp'       => $nowMs,
        ];
        $this->firebase->set($this->_msg_path("ModerationLog/{$logKey}"), $logData);

        // ── Replace message content with moderation notice ──
        $this->firebase->update($msgPath, [
            'message'          => '[Message removed by admin]',
            'originalMessage'  => $msg['message'] ?? '',
            'moderated'        => true,
            'moderatedBy'      => $this->admin_id,
            'moderatedAt'      => $nowMs,
            'moderationReason' => $reason,
        ]);

        log_audit(
            'Message Monitor',
            'delete_message',
            $msgId,
            "Moderated message in conversation {$convId}: {$reason}"
        );

        $this->json_success(['message' => 'Message has been moderated successfully.']);
    }

    // =====================================================================
    //  AJAX: Activity timeline (hourly distribution)
    // =====================================================================

    /**
     * Hourly message distribution for the specified period, plus daily counts.
     * Used for charting message activity patterns.
     *
     * POST param: period — number of days (7–90, default 7)
     *
     * Returns:
     *   daily   — {labels: [...dates], values: [...counts]}
     *   hourly  — {labels: ["00:00"…"23:00"], values: [...counts]}
     *   class_volume — {labels: [...classes], values: [...counts]}
     */
    public function get_activity_timeline(): void
    {
        $this->_require_role(self::VIEW_ROLES, 'message_monitor_timeline');

        $period  = min(90, max(7, (int) ($this->input->post('period') ?? 7)));
        $startTs = (time() - ($period * 86400)) * 1000;

        $allConvos = $this->_fetch_all_conversations();

        // ── Initialise buckets ──
        $dailyMessages  = [];
        $hourlyMessages = array_fill(0, 24, 0);
        $classCounts    = [];

        for ($i = $period - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', time() - ($i * 86400));
            $dailyMessages[$date] = 0;
        }

        // ── Process conversations ──
        foreach ($allConvos as $convId => $conv) {
            if (!is_array($conv) || !isset($conv['messages']) || !is_array($conv['messages'])) {
                continue;
            }

            $cls = $conv['studentClass'] ?? 'Unknown';
            if (!isset($classCounts[$cls])) {
                $classCounts[$cls] = 0;
            }

            foreach ($conv['messages'] as $msg) {
                if (!is_array($msg)) {
                    continue;
                }

                $ts = (int) ($msg['timestamp'] ?? 0);
                if ($ts < $startTs) {
                    continue;
                }

                $epochSec = (int) ($ts / 1000);
                $date     = date('Y-m-d', $epochSec);
                $hour     = (int) date('G', $epochSec);

                if (isset($dailyMessages[$date])) {
                    $dailyMessages[$date]++;
                }
                $hourlyMessages[$hour]++;
                $classCounts[$cls]++;
            }
        }

        // ── Format hourly labels ──
        $hourlyLabels = [];
        for ($h = 0; $h < 24; $h++) {
            $hourlyLabels[] = sprintf('%02d:00', $h);
        }

        // Sort classes by volume descending
        arsort($classCounts);

        $this->json_success([
            'daily' => [
                'labels' => array_keys($dailyMessages),
                'values' => array_values($dailyMessages),
            ],
            'hourly' => [
                'labels' => $hourlyLabels,
                'values' => array_values($hourlyMessages),
            ],
            'class_volume' => [
                'labels' => array_keys($classCounts),
                'values' => array_values($classCounts),
            ],
            'period' => $period,
        ]);
    }

    // =====================================================================
    //  AJAX: Flagged content detection
    // =====================================================================

    /**
     * Scan all messages for configurable flagged keywords.
     * Keywords are stored at Schools/{school}/Messages/FlaggedKeywords.
     *
     * Returns:
     *   flagged  — array of messages matching keywords, with moderation status
     *   keywords — the current keyword list
     *   total    — count of flagged messages
     */
    public function get_flagged_content(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'message_monitor_flagged');

        $keywords  = $this->_load_flagged_keywords();
        $allConvos = $this->_fetch_all_conversations();
        $flagged   = [];

        foreach ($allConvos as $convId => $conv) {
            if (!is_array($conv) || !isset($conv['messages']) || !is_array($conv['messages'])) {
                continue;
            }

            foreach ($conv['messages'] as $msgId => $msg) {
                if (!is_array($msg)) {
                    continue;
                }

                $text    = strtolower($msg['message'] ?? '');
                if ($text === '') {
                    continue;
                }

                $matched = [];
                foreach ($keywords as $kw) {
                    if (strpos($text, $kw) !== false) {
                        $matched[] = $kw;
                    }
                }

                if (empty($matched)) {
                    continue;
                }

                $flagged[] = [
                    'conversationId'  => $convId,
                    'messageId'       => $msgId,
                    'message'         => $msg['message'] ?? '',
                    'senderId'        => $msg['senderId'] ?? '',
                    'senderName'      => $msg['senderName'] ?? '',
                    'timestamp'       => (int) ($msg['timestamp'] ?? 0),
                    'teacherName'     => $conv['teacherName'] ?? '',
                    'parentName'      => $conv['parentName'] ?? '',
                    'studentName'     => $conv['studentName'] ?? '',
                    'studentClass'    => $conv['studentClass'] ?? '',
                    'flaggedKeywords' => $matched,
                ];
            }
        }

        // Most recent first
        usort($flagged, static fn($a, $b) => $b['timestamp'] - $a['timestamp']);

        // ── Attach moderation status from ModerationLog ──
        $moderationLog = $this->firebase->get($this->_msg_path('ModerationLog'));

        foreach ($flagged as &$entry) {
            $logKey = $entry['conversationId'] . '_' . $entry['messageId'];
            if (is_array($moderationLog) && isset($moderationLog[$logKey])) {
                $entry['moderation'] = $moderationLog[$logKey];
            } else {
                $entry['moderation'] = null;
            }
        }
        unset($entry);

        $this->json_success([
            'flagged'  => $flagged,
            'keywords' => $keywords,
            'total'    => count($flagged),
        ]);
    }

    // =====================================================================
    //  AJAX: Save flagged keywords
    // =====================================================================

    /**
     * Save the admin-configurable flagged keyword list.
     *
     * POST param: keywords — comma-separated keyword string
     *
     * Validates: non-empty, max 100 keywords, trims and deduplicates.
     */
    public function save_flagged_keywords(): void
    {
        $this->_require_admin();

        $raw = trim($this->input->post('keywords') ?? '');
        if ($raw === '') {
            $this->json_error('Keywords list cannot be empty.');
        }

        // Parse, trim, deduplicate, remove blanks
        $keywords = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn($k) => $k !== ''
        )));

        if (empty($keywords)) {
            $this->json_error('At least one valid keyword is required.');
        }

        if (count($keywords) > self::MAX_KEYWORD_COUNT) {
            $this->json_error('Maximum ' . self::MAX_KEYWORD_COUNT . ' keywords allowed.');
        }

        $this->firebase->set($this->_msg_path('FlaggedKeywords'), $keywords);

        log_audit(
            'Message Monitor',
            'update_flagged_keywords',
            '',
            'Updated flagged keywords list (' . count($keywords) . ' keywords)'
        );

        $this->json_success([
            'keywords' => $keywords,
            'message'  => 'Flagged keywords saved successfully.',
        ]);
    }

    // =====================================================================
    //  AJAX: Export conversation as text
    // =====================================================================

    /**
     * Export a conversation as a formatted plain-text document.
     *
     * POST param: conversation_id
     *
     * Returns: { text: "...", filename: "conversation_xxx_20260324_120000.txt" }
     */
    public function export_conversation(): void
    {
        $this->_require_role(self::VIEW_ROLES, 'message_monitor_export');

        $conversationId = $this->safe_path_segment(
            trim($this->input->post('conversation_id') ?? ''),
            'conversation_id'
        );

        $conv = $this->firebase->get($this->_msg_path("Conversations/{$conversationId}"));
        if (!is_array($conv)) {
            $this->json_error('Conversation not found.', 404);
        }

        // ── Build export header ──
        $lines   = [];
        $lines[] = '======================================================';
        $lines[] = '  MESSAGE EXPORT';
        $lines[] = '======================================================';
        $lines[] = '';
        $lines[] = 'Conversation ID : ' . $conversationId;
        $lines[] = 'Teacher         : ' . ($conv['teacherName'] ?? 'N/A') . ' (' . ($conv['teacherId'] ?? '') . ')';
        $lines[] = 'Parent          : ' . ($conv['parentName'] ?? 'N/A');
        $lines[] = 'Student         : ' . ($conv['studentName'] ?? 'N/A');
        $lines[] = 'Class           : ' . ($conv['studentClass'] ?? 'N/A');
        $lines[] = 'Exported by     : ' . ($this->admin_name ?? 'Admin');
        $lines[] = 'Export date     : ' . date('Y-m-d H:i:s');
        $lines[] = '';
        $lines[] = str_repeat('-', 54);
        $lines[] = '';

        // ── Build message body ──
        if (isset($conv['messages']) && is_array($conv['messages'])) {
            $sorted = $this->_sort_messages($conv['messages']);

            if (empty($sorted)) {
                $lines[] = '(No messages in this conversation)';
            } else {
                $msgNum = 0;
                foreach ($sorted as $msg) {
                    $msgNum++;
                    $ts     = (int) ($msg['timestamp'] ?? 0);
                    $time   = $ts > 0 ? date('Y-m-d H:i:s', (int) ($ts / 1000)) : 'Unknown time';
                    $sender = $msg['senderName'] ?? ($msg['senderId'] ?? 'Unknown');

                    $lines[] = "[{$msgNum}] {$time}";
                    $lines[] = "    From: {$sender}";
                    $lines[] = '    ' . ($msg['message'] ?? '');

                    if (!empty($msg['moderated'])) {
                        $lines[] = '    [MODERATED]';
                    }

                    $lines[] = '';
                }
            }
        } else {
            $lines[] = '(No messages in this conversation)';
        }

        $lines[] = str_repeat('-', 54);
        $lines[] = 'End of export. Total messages: ' . (isset($sorted) ? count($sorted) : 0);

        log_audit(
            'Message Monitor',
            'export_conversation',
            $conversationId,
            'Exported conversation'
        );

        $this->json_success([
            'text'     => implode("\n", $lines),
            'filename' => "conversation_{$conversationId}_" . date('Ymd_His') . '.txt',
        ]);
    }

    // =====================================================================
    //  AJAX: Moderate flagged message (approve / warn / dismiss)
    // =====================================================================

    /**
     * Record a moderation action on a flagged message without deleting it.
     *
     * POST params:
     *   conversation_id — conversation key
     *   message_id      — push-ID of the flagged message
     *   action          — one of: approve, warn, dismiss
     *   note            — optional admin note
     */
    public function moderate_message(): void
    {
        $this->_require_admin();

        $convId = $this->safe_path_segment(
            trim($this->input->post('conversation_id') ?? ''),
            'conversation_id'
        );
        $msgId = $this->safe_path_segment(
            trim($this->input->post('message_id') ?? ''),
            'message_id'
        );
        $action = trim($this->input->post('action') ?? '');
        $note   = trim($this->input->post('note') ?? '');

        $validActions = ['approve', 'warn', 'dismiss'];
        if (!in_array($action, $validActions, true)) {
            $this->json_error('Invalid moderation action. Must be one of: ' . implode(', ', $validActions));
        }

        // Verify the message exists
        $msgPath = $this->_msg_path("Conversations/{$convId}/messages/{$msgId}");
        $msg     = $this->firebase->get($msgPath);
        if (!is_array($msg)) {
            $this->json_error('Message not found.', 404);
        }

        $logKey  = "{$convId}_{$msgId}";
        $logData = [
            'action'         => $action,
            'conversationId' => $convId,
            'messageId'      => $msgId,
            'note'           => $note,
            'adminId'        => $this->admin_id,
            'adminName'      => $this->admin_name,
            'timestamp'      => round(microtime(true) * 1000),
        ];

        $this->firebase->set($this->_msg_path("ModerationLog/{$logKey}"), $logData);

        log_audit(
            'Message Monitor',
            'moderate_message',
            $msgId,
            "Moderation action [{$action}] on message in conversation {$convId}"
        );

        $this->json_success(['message' => 'Moderation action recorded.']);
    }
}
