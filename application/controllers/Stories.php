<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Stories Controller -- Teacher Stories Management & Moderation
 *
 * Provides admin oversight of teacher-posted stories from the mobile app.
 * Supports viewing, moderation (flag/remove/approve), analytics, and bulk actions.
 *
 * Firebase paths:
 *   Schools/{school_id}/Stories/{teacherId}/{storyId}
 *
 * Story node structure (written by teacher mobile app):
 *   teacherName, teacherProfilePic, mediaUrl, mediaType (image|video),
 *   caption, createdAt, expiresAt, viewCount, status (active|flagged|removed)
 */
class Stories extends MY_Controller
{
    /** Roles allowed to view stories */
    private const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher', 'Front Office'];

    /** Roles allowed to moderate (flag/remove/approve) */
    private const MODERATE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];

    /** Roles allowed to permanently delete */
    private const DELETE_ROLES = ['Super Admin', 'School Super Admin', 'Admin'];

    // ════════════════════════════════════════════════════════════════
    //  SHARED CONFIG — mirror of Kotlin StorySharedConfig on both apps.
    //  Keep these values in lockstep with:
    //    - D:/Projects/SchoolSyncTeacher/.../StorySharedConfig.kt
    //    - D:/Projects/SchoolSyncParent/.../StorySharedConfig.kt
    //  Any drift will produce silent cross-system validation failures.
    // ════════════════════════════════════════════════════════════════
    private const ALLOWED_STATUSES    = ['active', 'flagged', 'removed'];
    private const ALLOWED_TYPES       = ['image', 'video'];
    private const ALLOWED_PRIORITIES  = ['high', 'normal'];
    private const MAX_CAPTION_LENGTH  = 500;
    private const DEFAULT_EXPIRY_HOURS = 24;
    private const MAX_IMAGE_BYTES     = 10 * 1024 * 1024;   // 10 MB
    private const MAX_VIDEO_BYTES     = 50 * 1024 * 1024;   // 50 MB

    // Rate limits (Hardening #4) — rolling 24h per author.
    private const TEACHER_DAILY_LIMIT = 5;
    private const ADMIN_DAILY_LIMIT   = 10;

    public function __construct()
    {
        parent::__construct();
        require_permission('Stories');
    }

    // ── Access helpers ──────────────────────────────────────────────────

    private function _require_view(): void
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->_deny_access();
        }
    }

    private function _require_moderate(): void
    {
        if (!in_array($this->admin_role, self::MODERATE_ROLES, true)) {
            $this->_deny_access();
        }
    }

    private function _require_delete(): void
    {
        if (!in_array($this->admin_role, self::DELETE_ROLES, true)) {
            $this->_deny_access();
        }
    }

    private function _deny_access(): void
    {
        if ($this->input->is_ajax_request()) {
            $this->json_error('Access denied.', 403);
        }
        redirect(base_url('admin'));
    }

    // ── Path helpers ────────────────────────────────────────────────────

    /**
     * Build Firebase path for Stories node.
     * Stories sit outside the session year -- they are at school root level.
     */
    private function _path(string $sub = ''): string
    {
        $base = "Schools/{$this->school_name}/Stories";
        return $sub !== '' ? "{$base}/{$sub}" : $base;
    }

    // ── Text helpers ────────────────────────────────────────────────────

    /**
     * Strip control characters for safe Firebase storage / comparison.
     */
    private function _clean_text(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }

    // ====================================================================
    //  PAGE ROUTES
    // ====================================================================

    /**
     * Main stories dashboard -- SPA with tabs.
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'stories_view');
        $this->_require_view();

        $data = ['page_title' => 'Stories Management'];
        $this->load->view('include/header', $data);
        $this->load->view('stories/index', $data);
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  AJAX ENDPOINTS
    // ====================================================================

    /**
     * GET: Fetch all stories with teacher info.
     *
     * Query params:
     *   teacher   - filter by teacher ID
     *   status    - filter by status (active|flagged|removed)
     *   date_from - filter stories created on or after (YYYY-MM-DD)
     *   date_to   - filter stories created on or before (YYYY-MM-DD)
     *   search    - search in teacher name / caption
     */
    public function get_stories()
    {
        $this->_require_role(self::VIEW_ROLES, 'stories_view');
        $this->_require_view();

        $filterTeacher  = trim($this->input->get('teacher') ?? '');
        $filterStatus   = trim($this->input->get('status') ?? '');
        $filterDateFrom = trim($this->input->get('date_from') ?? '');
        $filterDateTo   = trim($this->input->get('date_to') ?? '');
        $filterSearch   = strtolower(trim($this->input->get('search') ?? ''));

        // Validate filter values
        if ($filterStatus !== '' && !in_array($filterStatus, self::ALLOWED_STATUSES, true)) {
            // Also allow 'expired' as a virtual status for filtering
            if ($filterStatus !== 'expired') {
                $this->json_error('Invalid status filter.');
            }
        }
        if ($filterDateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) {
            $this->json_error('Invalid date_from format. Use YYYY-MM-DD.');
        }
        if ($filterDateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo)) {
            $this->json_error('Invalid date_to format. Use YYYY-MM-DD.');
        }

        $now = time();
        $allTeachers = $this->firebase->get($this->_path());
        $stories = [];

        if (!is_array($allTeachers)) {
            $this->json_success(['stories' => [], 'total' => 0]);
        }

        foreach ($allTeachers as $teacherId => $teacherStories) {
            if (!is_array($teacherStories)) continue;

            // Teacher filter
            if ($filterTeacher !== '' && $teacherId !== $filterTeacher) continue;

            foreach ($teacherStories as $storyId => $story) {
                if (!is_array($story)) continue;

                $createdAt = $story['createdAt'] ?? 0;
                $expiresAt = $story['expiresAt'] ?? 0;
                $status    = $story['status'] ?? 'active';
                $caption   = $story['caption'] ?? '';
                $tName     = $story['teacherName'] ?? '';

                // Determine effective status (expired is virtual)
                $isExpired      = ($expiresAt > 0 && ($expiresAt / 1000) < $now);
                $effectiveStatus = $isExpired ? 'expired' : $status;

                // Status filter
                if ($filterStatus !== '') {
                    if ($filterStatus === 'expired' && !$isExpired) continue;
                    if ($filterStatus !== 'expired' && $status !== $filterStatus) continue;
                    if ($filterStatus !== 'expired' && $isExpired) continue;
                }

                // Date range filter (createdAt is timestamp in ms)
                if ($filterDateFrom !== '' && $createdAt > 0) {
                    $createdDate = date('Y-m-d', (int)($createdAt / 1000));
                    if ($createdDate < $filterDateFrom) continue;
                }
                if ($filterDateTo !== '' && $createdAt > 0) {
                    $createdDate = date('Y-m-d', (int)($createdAt / 1000));
                    if ($createdDate > $filterDateTo) continue;
                }

                // Search filter (teacher name or caption)
                if ($filterSearch !== '') {
                    $haystack = strtolower($tName . ' ' . $caption);
                    if (strpos($haystack, $filterSearch) === false) continue;
                }

                $stories[] = [
                    'storyId'           => $storyId,
                    'teacherId'         => $teacherId,
                    'teacherName'       => $tName,
                    'teacherProfilePic' => $story['teacherProfilePic'] ?? '',
                    'mediaUrl'          => $story['mediaUrl'] ?? '',
                    'mediaType'         => $story['mediaType'] ?? 'image',
                    'caption'           => $caption,
                    'createdAt'         => $createdAt,
                    'expiresAt'         => $expiresAt,
                    'viewCount'         => (int)($story['viewCount'] ?? 0),
                    'status'            => $status,
                    'effectiveStatus'   => $effectiveStatus,
                ];
            }
        }

        // Sort by createdAt descending (newest first)
        usort($stories, function ($a, $b) {
            return ($b['createdAt'] ?? 0) - ($a['createdAt'] ?? 0);
        });

        $this->json_success([
            'stories' => $stories,
            'total'   => count($stories),
        ]);
    }

    /**
     * GET: Get single story with full details.
     */
    public function get_story_detail(string $storyId = '')
    {
        $this->_require_role(self::VIEW_ROLES, 'stories_view');
        $this->_require_view();

        $teacherId = trim($this->input->get('teacher_id') ?? '');
        if ($storyId === '') {
            $storyId = trim($this->input->get('story_id') ?? '');
        }

        if ($teacherId === '') $this->json_error('Teacher ID is required.');
        if ($storyId === '')   $this->json_error('Story ID is required.');

        $teacherId = $this->safe_path_segment($teacherId, 'teacher_id');
        $storyId   = $this->safe_path_segment($storyId, 'story_id');

        $story = $this->firebase->get($this->_path("{$teacherId}/{$storyId}"));
        if (!is_array($story)) {
            $this->json_error('Story not found.', 404);
        }

        $now       = time();
        $expiresAt = $story['expiresAt'] ?? 0;
        $isExpired = ($expiresAt > 0 && ($expiresAt / 1000) < $now);

        $story['storyId']         = $storyId;
        $story['teacherId']       = $teacherId;
        $story['effectiveStatus'] = $isExpired ? 'expired' : ($story['status'] ?? 'active');

        $this->json_success(['story' => $story]);
    }

    /**
     * GET: Stories analytics.
     */
    public function get_analytics()
    {
        $this->_require_role(self::VIEW_ROLES, 'stories_view');
        $this->_require_view();

        $now         = time();
        $allTeachers = $this->firebase->get($this->_path());

        $total      = 0;
        $active     = 0;
        $expired    = 0;
        $flagged    = 0;
        $removed    = 0;
        $totalViews = 0;
        $byTeacher  = [];  // teacherId => [name, count, views]
        $byDay      = [];  // YYYY-MM-DD => count
        $viewDist   = [];  // ranges

        if (is_array($allTeachers)) {
            foreach ($allTeachers as $teacherId => $teacherStories) {
                if (!is_array($teacherStories)) continue;

                foreach ($teacherStories as $storyId => $story) {
                    if (!is_array($story)) continue;

                    $total++;
                    $createdAt = $story['createdAt'] ?? 0;
                    $expiresAt = $story['expiresAt'] ?? 0;
                    $status    = $story['status'] ?? 'active';
                    $views     = (int)($story['viewCount'] ?? 0);
                    $tName     = $story['teacherName'] ?? $teacherId;

                    $totalViews += $views;

                    // Status counting
                    $isExpired = ($expiresAt > 0 && ($expiresAt / 1000) < $now);
                    if ($status === 'flagged') {
                        $flagged++;
                    } elseif ($status === 'removed') {
                        $removed++;
                    } elseif ($isExpired) {
                        $expired++;
                    } else {
                        $active++;
                    }

                    // By teacher
                    if (!isset($byTeacher[$teacherId])) {
                        $byTeacher[$teacherId] = [
                            'name'   => $tName,
                            'count'  => 0,
                            'views'  => 0,
                            'pic'    => $story['teacherProfilePic'] ?? '',
                        ];
                    }
                    $byTeacher[$teacherId]['count']++;
                    $byTeacher[$teacherId]['views'] += $views;

                    // By day (last 30 days)
                    if ($createdAt > 0) {
                        $day = date('Y-m-d', (int)($createdAt / 1000));
                        $thirtyDaysAgo = date('Y-m-d', $now - 30 * 86400);
                        if ($day >= $thirtyDaysAgo) {
                            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
                        }
                    }

                    // View distribution
                    if ($views === 0)      $bucket = '0';
                    elseif ($views <= 10)  $bucket = '1-10';
                    elseif ($views <= 50)  $bucket = '11-50';
                    elseif ($views <= 100) $bucket = '51-100';
                    else                   $bucket = '100+';
                    $viewDist[$bucket] = ($viewDist[$bucket] ?? 0) + 1;
                }
            }
        }

        // Sort by-teacher by count descending
        uasort($byTeacher, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        // Fill in missing days for chart (last 30 days)
        $dailyData = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = date('Y-m-d', $now - $i * 86400);
            $dailyData[] = [
                'date'  => $day,
                'count' => $byDay[$day] ?? 0,
            ];
        }

        // Teacher leaderboard (top 20)
        $leaderboard = [];
        $rank = 0;
        foreach ($byTeacher as $tid => $t) {
            $rank++;
            if ($rank > 20) break;
            $leaderboard[] = [
                'rank'      => $rank,
                'teacherId' => $tid,
                'name'      => $t['name'],
                'pic'       => $t['pic'],
                'count'     => $t['count'],
                'views'     => $t['views'],
                'avgViews'  => $t['count'] > 0 ? round($t['views'] / $t['count'], 1) : 0,
            ];
        }

        $this->json_success([
            'total'        => $total,
            'active'       => $active,
            'expired'      => $expired,
            'flagged'      => $flagged,
            'removed'      => $removed,
            'totalViews'   => $totalViews,
            'avgViews'     => $total > 0 ? round($totalViews / $total, 1) : 0,
            'dailyData'    => $dailyData,
            'viewDist'     => $viewDist,
            'leaderboard'  => $leaderboard,
            'teacherCount' => count($byTeacher),
        ]);
    }

    /**
     * POST: Change story status (active/flagged/removed).
     */
    public function moderate_story()
    {
        $this->_require_role(self::MODERATE_ROLES, 'moderate_story');
        $this->_require_moderate();

        $teacherId = $this->safe_path_segment(trim($this->input->post('teacher_id') ?? ''), 'teacher_id');
        $storyId   = $this->safe_path_segment(trim($this->input->post('story_id') ?? ''), 'story_id');
        $newStatus = trim($this->input->post('status') ?? '');
        $reason    = $this->_clean_text(trim($this->input->post('reason') ?? ''));

        if ($teacherId === '') $this->json_error('Teacher ID is required.');
        if ($storyId === '')   $this->json_error('Story ID is required.');
        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            $this->json_error('Invalid status. Allowed: active, flagged, removed.');
        }

        // Verify story exists
        $story = $this->firebase->get($this->_path("{$teacherId}/{$storyId}"));
        if (!is_array($story)) {
            $this->json_error('Story not found.', 404);
        }

        $updateData = [
            'status'         => $newStatus,
            'moderatedBy'    => $this->admin_id,
            'moderatedByName'=> $this->admin_name,
            'moderatedAt'    => round(microtime(true) * 1000),
        ];

        if ($reason !== '') {
            $updateData['moderationReason'] = $reason;
        }

        $this->firebase->update($this->_path("{$teacherId}/{$storyId}"), $updateData);

        $statusLabel = ucfirst($newStatus);
        $this->json_success([
            'message' => "Story status changed to {$statusLabel}.",
        ]);
    }

    /**
     * POST: Permanently delete a story.
     */
    public function delete_story()
    {
        $this->_require_role(self::DELETE_ROLES, 'delete_story');
        $this->_require_delete();

        $teacherId = $this->safe_path_segment(trim($this->input->post('teacher_id') ?? ''), 'teacher_id');
        $storyId   = $this->safe_path_segment(trim($this->input->post('story_id') ?? ''), 'story_id');

        if ($teacherId === '') $this->json_error('Teacher ID is required.');
        if ($storyId === '')   $this->json_error('Story ID is required.');

        // Verify story exists
        $story = $this->firebase->get($this->_path("{$teacherId}/{$storyId}"));
        if (!is_array($story)) {
            $this->json_error('Story not found.', 404);
        }

        $this->firebase->delete($this->_path("{$teacherId}"), $storyId);

        $this->json_success(['message' => 'Story permanently deleted.']);
    }

    /**
     * GET: Get list of teachers who have stories.
     */
    public function get_teachers()
    {
        $this->_require_role(self::VIEW_ROLES, 'stories_view');
        $this->_require_view();

        $allTeachers = $this->firebase->get($this->_path());
        $teachers = [];

        if (is_array($allTeachers)) {
            foreach ($allTeachers as $teacherId => $teacherStories) {
                if (!is_array($teacherStories)) continue;

                $name = '';
                $pic  = '';
                $count = 0;

                foreach ($teacherStories as $storyId => $story) {
                    if (!is_array($story)) continue;
                    $count++;
                    // Use the most recent story's teacher info
                    if ($name === '') {
                        $name = $story['teacherName'] ?? '';
                        $pic  = $story['teacherProfilePic'] ?? '';
                    }
                }

                if ($count > 0) {
                    $teachers[] = [
                        'teacherId'  => $teacherId,
                        'name'       => $name,
                        'profilePic' => $pic,
                        'storyCount' => $count,
                    ];
                }
            }
        }

        // Sort alphabetically by name
        usort($teachers, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $this->json_success(['teachers' => $teachers]);
    }

    /**
     * POST: Bulk moderation -- change status for multiple stories.
     *
     * Expects JSON body: { items: [ {teacher_id, story_id}, ... ], status: "flagged" }
     */
    public function bulk_moderate()
    {
        $this->_require_role(self::MODERATE_ROLES, 'bulk_moderate');
        $this->_require_moderate();

        $newStatus = trim($this->input->post('status') ?? '');
        $reason    = $this->_clean_text(trim($this->input->post('reason') ?? ''));

        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            $this->json_error('Invalid status. Allowed: active, flagged, removed.');
        }

        // Items can come as JSON string or as POST array
        $itemsRaw = $this->input->post('items');
        if (is_string($itemsRaw)) {
            $itemsRaw = json_decode($itemsRaw, true);
        }

        if (!is_array($itemsRaw) || empty($itemsRaw)) {
            $this->json_error('No stories selected.');
        }

        if (count($itemsRaw) > 100) {
            $this->json_error('Maximum 100 stories per bulk operation.');
        }

        $success = 0;
        $failed  = 0;
        $now     = round(microtime(true) * 1000);

        foreach ($itemsRaw as $item) {
            if (!is_array($item)) { $failed++; continue; }

            $tid = trim($item['teacher_id'] ?? '');
            $sid = trim($item['story_id'] ?? '');

            if ($tid === '' || $sid === '') { $failed++; continue; }

            // Validate path segments
            if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $tid) ||
                !preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $sid)) {
                $failed++;
                continue;
            }

            // Verify story exists
            $story = $this->firebase->get($this->_path("{$tid}/{$sid}"));
            if (!is_array($story)) { $failed++; continue; }

            $updateData = [
                'status'          => $newStatus,
                'moderatedBy'     => $this->admin_id,
                'moderatedByName' => $this->admin_name,
                'moderatedAt'     => $now,
            ];
            if ($reason !== '') {
                $updateData['moderationReason'] = $reason;
            }

            $this->firebase->update($this->_path("{$tid}/{$sid}"), $updateData);
            $success++;
        }

        $statusLabel = ucfirst($newStatus);
        $this->json_success([
            'message' => "{$success} stories updated to {$statusLabel}." . ($failed > 0 ? " {$failed} failed." : ''),
            'success' => $success,
            'failed'  => $failed,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ADMIN UPLOAD (Phase C)
    //
    //  Accepts a multipart POST (file + caption + priority + type) from
    //  the Stories Management SPA, uploads the media to Firebase Storage,
    //  then writes a canonical feeReceiptAllocations-style Firestore doc
    //  with authorType='admin'.
    //
    //  Path layout (admin uploads):
    //    gs://…/stories/admin/{schoolId}/{adminId}/{epochMillis}.{ext}
    //
    //  Firestore doc written to:
    //    stories/{schoolId}_admin_{adminId}_{epochMillis}
    //
    //  POST fields:
    //    media      — file upload (image/* or video/*, max 50MB)
    //    caption    — optional, ≤ 500 chars
    //    type       — "image" | "video"
    //    priority   — "high" | "normal"
    // ══════════════════════════════════════════════════════════════════
    public function upload_story()
    {
        $this->_require_moderate();
        $this->output->set_content_type('application/json');

        // ── 1. Validate POST fields ───────────────────────────────────
        $caption  = trim((string) $this->input->post('caption'));
        $type     = strtolower(trim((string) $this->input->post('type')));
        $priority = strtolower(trim((string) $this->input->post('priority')));
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $this->json_error('Type must be image or video.');
        }
        if (!in_array($priority, self::ALLOWED_PRIORITIES, true)) {
            $priority = 'normal';
        }
        if (strlen($caption) > self::MAX_CAPTION_LENGTH) {
            $this->json_error('Caption exceeds ' . self::MAX_CAPTION_LENGTH . ' chars.');
        }

        // ── 2. Validate file ──────────────────────────────────────────
        if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
            $this->json_error('Media file is required.');
        }
        $file = $_FILES['media'];
        $mime = (string) ($file['type'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $maxBytes = $type === 'image' ? self::MAX_IMAGE_BYTES : self::MAX_VIDEO_BYTES;
        if ($size <= 0 || $size > $maxBytes) {
            $maxMb = $maxBytes / (1024 * 1024);
            $this->json_error(ucfirst($type) . " must be 0–{$maxMb} MB.");
        }
        if ($type === 'image' && !preg_match('#^image/#', $mime)) {
            $this->json_error('Uploaded file is not an image (got ' . $mime . ').');
        }
        if ($type === 'video' && !preg_match('#^video/#', $mime)) {
            $this->json_error('Uploaded file is not a video (got ' . $mime . ').');
        }

        // ── 2b. Rate limit (Hardening #4) ─────────────────────────────
        // Query active non-expired stories for THIS admin user; reject
        // at the cap. Fail-open if the query errors so a Firestore
        // blip doesn't block a legitimate upload.
        $schoolId = $this->fs->schoolId();
        $adminId  = (string) ($this->admin_id ?? 'admin');
        try {
            $existing = $this->firebase->firestoreQuery('stories', [
                ['schoolId', '==', $schoolId],
                ['authorId', '==', $adminId],
            ]);
            $nowMs = (int) (microtime(true) * 1000);
            $liveCount = 0;
            foreach ((array) $existing as $row) {
                $d = $row['data'] ?? $row;
                if (!is_array($d)) continue;
                $status = (string) ($d['status'] ?? 'active');
                $exp    = (int) ($d['expiresAt'] ?? 0);
                if ($status === 'active' && $exp > $nowMs) $liveCount++;
            }
            if ($liveCount >= self::ADMIN_DAILY_LIMIT) {
                $this->json_error(
                    'Daily limit reached (' . self::ADMIN_DAILY_LIMIT .
                    '/day). Wait for an earlier story to expire before posting again.'
                );
            }
        } catch (\Exception $e) {
            log_message('warning', 'Stories::upload_story rate-limit check failed: ' . $e->getMessage());
        }

        // ── 3. Derive extension + remote path ─────────────────────────
        $extMap = [
            'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
            'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov',
            'video/3gpp' => '3gp',
        ];
        $ext = $extMap[$mime] ?? ($type === 'image' ? 'jpg' : 'mp4');

        $ts         = (int) (microtime(true) * 1000);
        $remotePath = "stories/admin/{$schoolId}/{$adminId}/{$ts}.{$ext}";

        // ── 4. Upload to Firebase Storage ─────────────────────────────
        $okUp = $this->firebase->uploadFile($file['tmp_name'], $remotePath);
        if (!$okUp) {
            $this->json_error('Storage upload failed. Check Storage rules + service account.');
        }
        $downloadUrl = $this->firebase->getDownloadUrl($remotePath);
        if ($downloadUrl === '') {
            $this->json_error('Storage upload succeeded but download URL was empty.');
        }

        // ── 5. Write canonical Firestore doc ──────────────────────────
        $storyId   = "{$schoolId}_admin_{$adminId}_{$ts}";
        $expiresAt = $ts + (self::DEFAULT_EXPIRY_HOURS * 3600 * 1000);

        $adminName = (string) ($this->admin_name ?? 'School Admin');
        $adminPic  = '';   // admin profile pic is not currently denormalized

        $doc = [
            'schoolId'        => $schoolId,
            // Phase C canonical
            'authorId'        => $adminId,
            'authorName'      => $adminName,
            'authorPic'       => $adminPic,
            'authorType'      => 'admin',
            // Legacy aliases (parent app with legacy reader still works)
            'teacherId'       => $adminId,
            'teacherName'     => $adminName,
            'teacherPic'      => $adminPic,
            // Content
            'mediaUrl'        => $downloadUrl,
            'type'            => $type,
            'caption'         => $caption,
            'priority'        => $priority,
            // Lifecycle — use server-side timestamp iso; Firestore accepts
            // Firestore Timestamp via the client SDK, PHP wrapper sends
            // ISO string which works equally for display.
            // Canonical expiry = expiresAtTs (Firestore Timestamp).
            // Wrapped via the REST client's timestamp() helper so the
            // encoder emits { "timestampValue": ... } not { "stringValue": ... }.
            // Firestore TTL policy targets this field; client listeners
            // also filter on it with a Timestamp.now() comparison.
            // Legacy expiresAt (Long) written for one release only.
            'createdAt'       => date('c'),
            'expiresAtTs'     => Firestore_rest_client::timestamp($expiresAt),
            'expiresAt'       => $expiresAt,   // LEGACY — remove in v2.0
            'viewCount'       => 0,
            // Moderation defaults
            'status'          => 'active',
            'moderatedBy'     => '',
            'moderatedByName' => '',
            'moderatedAt'     => 0,
            'moderationReason' => '',
        ];

        $okFs = $this->firebase->firestoreSet('stories', $storyId, $doc);
        if (!$okFs) {
            // Storage upload happened but Firestore failed — try to clean
            // up the orphaned media so the bucket doesn't bloat over time.
            try { $this->firebase->getStorageBucket()->object($remotePath)->delete(); } catch (\Exception $_) {}
            $this->json_error('Firestore write failed. Storage media cleaned up.');
        }

        $this->json_success([
            'message'   => 'Story uploaded' . ($priority === 'high' ? ' (high priority)' : '') . '.',
            'storyId'   => $storyId,
            'mediaUrl'  => $downloadUrl,
            'priority'  => $priority,
        ]);
    }
}
