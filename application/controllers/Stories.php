<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Stories Controller -- Teacher/Admin Stories Management & Moderation
 *
 * Provides admin oversight of stories posted from the mobile apps and the
 * admin panel. Supports viewing, moderation (flag/remove/approve),
 * analytics, bulk actions, and admin uploads.
 *
 * SOURCE OF TRUTH: Firestore collection `stories` (canonical, shared with
 * the Teacher app, Parent app, and this panel). There is NO RTDB store —
 * the legacy `Schools/{school}/Stories/...` RTDB path was retired when the
 * apps moved to Firestore, so every read/moderate/delete here targets the
 * Firestore `stories` collection filtered by `schoolId`.
 *
 * Canonical doc id: `{schoolId}_{authorId}_{epochMillis}`
 *   teacher app  → SCH_..._STA00xx_...
 *   admin upload → SCH_..._admin_{adminId}_...
 *
 * Canonical doc schema (mirror of StoryDoc.kt on both apps):
 *   schoolId, authorId/authorName/authorPic, authorType (teacher|admin),
 *   teacher{Id,Name,Pic} (legacy aliases), mediaUrl, type (image|video),
 *   caption, priority (high|normal), createdAt (Timestamp), expiresAtTs
 *   (Timestamp, canonical) + expiresAt (legacy Long ms), viewCount,
 *   audienceClassKeys (array; EMPTY = whole-school), reactionCounts,
 *   status (active|flagged|removed), moderated{By,ByName,At}, moderationReason.
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
    //    - ZenXII_Teacher/.../StorySharedConfig.kt
    //    - ZenXII_Parent/.../StorySharedConfig.kt
    //  Any drift will produce silent cross-system validation failures.
    // ════════════════════════════════════════════════════════════════
    private const COLLECTION          = 'stories';
    // Whole-school audience sentinel. The apps now enforce audience
    // SERVER-SIDE with an array-contains-any query, so a whole-school story
    // MUST carry audienceClassKeys = ["*"] (NOT an empty array) or parents'
    // query won't match it. Mirror of StorySharedConfig.AUDIENCE_ALL on both apps.
    private const AUDIENCE_ALL        = '*';
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
        if (has_permission('Stories', 'view')) return;
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->_deny_access();
        }
    }

    private function _require_moderate(): void
    {
        if (has_permission('Stories', 'edit')) return;
        if (!in_array($this->admin_role, self::MODERATE_ROLES, true)) {
            $this->_deny_access();
        }
    }

    private function _require_delete(): void
    {
        if (has_permission('Stories', 'manage')) return;
        if (!in_array($this->admin_role, self::DELETE_ROLES, true)) {
            $this->_deny_access();
        }
    }

    private function _deny_access(): void
    {
        if ($this->input->is_ajax_request()) {
            $this->json_error('Access denied.', 403);
        }
        redirect(rbac_denied_url('Stories', 'view'));
    }

    // ── Text helpers ────────────────────────────────────────────────────

    /**
     * Strip control characters for safe Firebase storage / comparison.
     */
    private function _clean_text(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }

    // ── Audience helpers (PHP mirror of StorySharedConfig.audienceKey) ──
    //   Reduces ANY class/section representation to a single canonical
    //   token so the value stored here matches what the Parent app filters
    //   against (myKey = audienceKey(childClass, childSection)). MUST stay
    //   byte-identical to the Kotlin StorySharedConfig on both apps:
    //     "Class 9th"/"9th"/"9" → "9" ; "Section A"/"A" → "a" ; join "-".

    private function _canon_class_token(string $raw): string
    {
        $s = strtolower(trim($raw));
        if (strpos($s, 'class ') === 0) $s = trim(substr($s, 6));
        if (preg_match('/^(\d+)(st|nd|rd|th)$/', $s, $m)) $s = $m[1];
        return $s;
    }

    private function _canon_section_token(string $raw): string
    {
        $s = strtolower(trim($raw));
        if (strpos($s, 'section ') === 0) $s = trim(substr($s, 8));
        return $s;
    }

    private function _audience_key(string $className, string $section): string
    {
        return $this->_canon_class_token($className) . '-' . $this->_canon_section_token($section);
    }

    /**
     * The set of valid canonical audience keys for this school, derived from
     * the Firestore `sections` collection (the same source the Homework
     * audience picker uses). Used to (a) populate the upload picker and
     * (b) validate a submitted audience so a story can't target a bogus key.
     * Returns [ canonicalKey => ['key','label','className','section'] ].
     */
    private function _school_audience_map(): array
    {
        $schoolId = $this->fs->schoolId();
        if ($schoolId === '') return [];

        $map = [];
        try {
            $docs = $this->firebase->firestoreQuery('sections', [
                ['schoolId', '==', $schoolId],
            ]);
            foreach ((array) $docs as $doc) {
                $d = $doc['data'] ?? null;
                if (!is_array($d)) continue;
                $cls = (string) ($d['className'] ?? '');
                $sec = (string) ($d['section'] ?? '');
                if ($cls === '' || $sec === '') continue;
                $key = $this->_audience_key($cls, $sec);
                if ($key === '-' || isset($map[$key])) continue;
                $map[$key] = ['key' => $key, 'label' => $cls . ' / ' . $sec, 'className' => $cls, 'section' => $sec];
            }
        } catch (\Exception $e) {
            log_message('error', 'Stories::_school_audience_map failed: ' . $e->getMessage());
        }
        return $map;
    }

    // ── Firestore mapping helpers ───────────────────────────────────────

    /**
     * Coerce a Firestore date field to epoch-millis. The REST client
     * decodes Timestamp values to ISO strings; admin uploads write
     * createdAt as an ISO string too; legacy docs may carry an int (ms).
     */
    private function _to_millis($v): int
    {
        if (is_int($v) || is_float($v)) return (int) $v;
        if (is_string($v) && $v !== '') {
            $t = strtotime($v);
            return $t > 0 ? $t * 1000 : 0;
        }
        return 0;
    }

    /**
     * Canonical expiry in epoch-millis: prefer expiresAtTs (Timestamp),
     * fall back to the legacy expiresAt Long. Mirrors StoryDoc.expiresAtMillis.
     */
    private function _expiry_millis(array $d): int
    {
        if (!empty($d['expiresAtTs'])) return $this->_to_millis($d['expiresAtTs']);
        if (!empty($d['expiresAt']))   return $this->_to_millis($d['expiresAt']);
        return 0;
    }

    /**
     * Map a canonical Firestore story doc → the flat shape the SPA renders.
     * Resolves author* (canonical) with teacher* (legacy) fallbacks so both
     * app-written and admin-written docs render identically.
     */
    private function _map_story(string $docId, array $d): array
    {
        $authorId   = (string) (($d['authorId']   ?? '') !== '' ? $d['authorId']   : ($d['teacherId']   ?? ''));
        $authorName = (string) (($d['authorName'] ?? '') !== '' ? $d['authorName'] : ($d['teacherName'] ?? ''));
        $authorPic  = (string) (($d['authorPic']  ?? '') !== '' ? $d['authorPic']  : ($d['teacherPic']  ?? ''));
        $type       = (string) (($d['type']       ?? '') !== '' ? $d['type']       : ($d['mediaType']   ?? 'image'));
        $audience   = isset($d['audienceClassKeys']) && is_array($d['audienceClassKeys']) ? array_values($d['audienceClassKeys']) : [];

        $createdMs = $this->_to_millis($d['createdAt'] ?? 0);
        $expiryMs  = $this->_expiry_millis($d);
        $status    = (string) ($d['status'] ?? 'active');
        $isExpired = ($expiryMs > 0 && $expiryMs < (time() * 1000));

        return [
            'storyId'           => $docId,                 // Firestore doc id (addressing key)
            'teacherId'         => $authorId,              // display + back-compat
            'authorId'          => $authorId,
            'authorType'        => (string) ($d['authorType'] ?? 'teacher'),
            'priority'          => (string) ($d['priority'] ?? 'normal'),
            'teacherName'       => $authorName,
            'teacherProfilePic' => $authorPic,
            'mediaUrl'          => (string) ($d['mediaUrl'] ?? ''),
            'mediaType'         => $type,
            // Poster frame for video stories; "" for images and for videos
            // uploaded before posters were generated (callers fall back to the
            // <video> first-frame trick). Written by BOTH the panel and the
            // Teacher app under the same key.
            'thumbnailUrl'      => (string) ($d['thumbnailUrl'] ?? ''),
            'caption'           => (string) ($d['caption'] ?? ''),
            'createdAt'         => $createdMs,
            'expiresAt'         => $expiryMs,
            'viewCount'         => (int) ($d['viewCount'] ?? 0),
            // Denormalised emoji → count map (server-maintained by the
            // onStoryReactionWritten CF). Surfaced so the detail view can show
            // reaction analytics, not just who viewed.
            'reactionCounts'    => (isset($d['reactionCounts']) && is_array($d['reactionCounts']))
                                    ? array_map('intval', $d['reactionCounts'])
                                    : [],
            'status'            => $status,
            'effectiveStatus'   => $isExpired ? 'expired' : $status,
            'audienceClassKeys' => $audience,
            // Whole-school = empty (legacy) OR the ["*"] sentinel (new contract).
            'audienceLabel'     => (empty($audience) || $audience === [self::AUDIENCE_ALL])
                                    ? 'Whole school'
                                    : implode(', ', array_map('strval', $audience)),
            'moderatedBy'       => (string) ($d['moderatedBy'] ?? ''),
            'moderatedByName'   => (string) ($d['moderatedByName'] ?? ''),
            'moderatedAt'       => $this->_to_millis($d['moderatedAt'] ?? 0),
            'moderationReason'  => (string) ($d['moderationReason'] ?? ''),
        ];
    }

    /**
     * Fetch ALL stories for the current school from Firestore, mapped to the
     * SPA shape. Single-field equality query (schoolId) — auto-indexed, no
     * composite index required. Filtering/sorting done in PHP (per-school
     * story volume is small).
     */
    private function _fetch_school_stories(): array
    {
        $schoolId = $this->fs->schoolId();
        if ($schoolId === '') return [];

        $rows = $this->firebase->firestoreQuery(self::COLLECTION, [
            ['schoolId', '==', $schoolId],
        ]);

        $out = [];
        foreach ((array) $rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $d  = $row['data'] ?? null;
            if ($id === '' || !is_array($d)) continue;
            $out[] = $this->_map_story($id, $d);
        }
        return $out;
    }

    /**
     * Read a single story by Firestore doc id and enforce tenant isolation
     * (doc ids are GLOBAL in Firestore, so we must verify schoolId matches
     * the caller's school before returning — prevents cross-tenant IDOR).
     * Returns [docId, rawDocArray] or null if missing / other school.
     */
    private function _get_owned_story(string $docId): ?array
    {
        $d = $this->firebase->firestoreGet(self::COLLECTION, $docId);
        if (!is_array($d)) return null;
        if ((string) ($d['schoolId'] ?? '') !== $this->fs->schoolId()) return null;
        return [$docId, $d];
    }

    /** Validate a Firestore story doc id from request input. */
    private function _story_id_param(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') $this->json_error('Story ID is required.');
        return $this->safe_path_segment($raw, 'story_id');
    }

    // ====================================================================
    //  PAGE ROUTES
    // ====================================================================

    /**
     * Main stories dashboard -- SPA with tabs.
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'stories_view', 'Stories', 'view');
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
     * GET: Fetch all stories with author info.
     *
     * Query params:
     *   teacher   - filter by author ID
     *   status    - filter by status (active|flagged|removed|expired)
     *   date_from - filter stories created on or after (YYYY-MM-DD)
     *   date_to   - filter stories created on or before (YYYY-MM-DD)
     *   search    - search in author name / caption
     */
    public function get_stories()
    {
        $this->_require_role(self::VIEW_ROLES, 'stories_view', 'Stories', 'view');
        $this->_require_view();

        $filterTeacher  = trim($this->input->get('teacher') ?? '');
        $filterStatus   = trim($this->input->get('status') ?? '');
        $filterDateFrom = trim($this->input->get('date_from') ?? '');
        $filterDateTo   = trim($this->input->get('date_to') ?? '');
        $filterSearch   = strtolower(trim($this->input->get('search') ?? ''));

        // Validate filter values
        if ($filterStatus !== '' && !in_array($filterStatus, self::ALLOWED_STATUSES, true) && $filterStatus !== 'expired') {
            $this->json_error('Invalid status filter.');
        }
        if ($filterDateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) {
            $this->json_error('Invalid date_from format. Use YYYY-MM-DD.');
        }
        if ($filterDateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo)) {
            $this->json_error('Invalid date_to format. Use YYYY-MM-DD.');
        }

        $stories = [];
        foreach ($this->_fetch_school_stories() as $s) {
            // Author filter
            if ($filterTeacher !== '' && $s['teacherId'] !== $filterTeacher) continue;

            // Status filter (expired is a virtual status derived from expiry)
            $isExpired = ($s['effectiveStatus'] === 'expired');
            if ($filterStatus !== '') {
                if ($filterStatus === 'expired' && !$isExpired) continue;
                if ($filterStatus !== 'expired' && ($s['status'] !== $filterStatus || $isExpired)) continue;
            }

            // Date range filter (createdAt is ms)
            if ($s['createdAt'] > 0) {
                $createdDate = date('Y-m-d', (int) ($s['createdAt'] / 1000));
                if ($filterDateFrom !== '' && $createdDate < $filterDateFrom) continue;
                if ($filterDateTo   !== '' && $createdDate > $filterDateTo)   continue;
            }

            // Search filter (author name or caption)
            if ($filterSearch !== '') {
                $haystack = strtolower($s['teacherName'] . ' ' . $s['caption']);
                if (strpos($haystack, $filterSearch) === false) continue;
            }

            $stories[] = $s;
        }

        // Sort by createdAt descending (newest first)
        usort($stories, function ($a, $b) {
            return ($b['createdAt'] ?? 0) <=> ($a['createdAt'] ?? 0);
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
        $this->_require_role(self::VIEW_ROLES, 'stories_view', 'Stories', 'view');
        $this->_require_view();

        if ($storyId === '') {
            $storyId = trim($this->input->get('story_id') ?? '');
        }
        $storyId = $this->_story_id_param($storyId);

        $owned = $this->_get_owned_story($storyId);
        if ($owned === null) {
            $this->json_error('Story not found.', 404);
        }
        [$docId, $d] = $owned;

        $story = $this->_map_story($docId, $d);
        // Per-user reactions (userId → emoji), read once and merged into the
        // viewer list below so oversight sees WHO reacted with WHAT.
        $reactionByUser = $this->_story_reactions($docId);
        // "Who viewed" — names of everyone (students/parents + staff) who
        // opened this story, each annotated with their emoji (if any).
        // Admin-panel oversight always sees this list.
        $story['viewers'] = $this->_story_viewers($docId, $reactionByUser);

        $this->json_success(['story' => $story]);
    }

    /**
     * Read the `viewers` subcollection for a story → newest-first list of
     * [userId, userName, viewedAt(ms), emoji]. The optional $reactionByUser
     * map (userId → emoji) annotates each viewer with their reaction and
     * absorbs reactors who somehow aren't in viewers. Best-effort; empty on
     * any failure.
     */
    private function _story_viewers(string $docId, array $reactionByUser = []): array
    {
        $viewers = [];
        $seen = [];
        foreach ($this->firebase->firestoreListSubcollection(self::COLLECTION, $docId, 'viewers') as $row) {
            $vd = $row['data'] ?? null;
            if (!is_array($vd)) continue;
            $uid = (string) ($vd['userId'] ?? $row['id']);
            $seen[$uid] = true;
            $viewers[] = [
                'userId'   => $uid,
                'userName' => (string) ($vd['userName'] ?? ''),
                'viewedAt' => $this->_to_millis($vd['viewedAt'] ?? 0),
                'emoji'    => (string) ($reactionByUser[$uid] ?? ''),
            ];
        }
        // Defensive union: reactors with no viewer doc still appear.
        foreach ($reactionByUser as $uid => $emoji) {
            if (isset($seen[$uid])) continue;
            $viewers[] = [
                'userId'   => (string) $uid,
                'userName' => '',
                'viewedAt' => 0,
                'emoji'    => (string) $emoji,
            ];
        }
        usort($viewers, function ($a, $b) { return $b['viewedAt'] <=> $a['viewedAt']; });
        return $viewers;
    }

    /**
     * Read the `reactions` subcollection for a story → [userId => emoji].
     * Best-effort; empty on any failure.
     */
    private function _story_reactions(string $docId): array
    {
        $out = [];
        foreach ($this->firebase->firestoreListSubcollection(self::COLLECTION, $docId, 'reactions') as $row) {
            $rd = $row['data'] ?? null;
            if (!is_array($rd)) continue;
            $uid   = (string) ($rd['userId'] ?? $row['id']);
            $emoji = (string) ($rd['emoji'] ?? '');
            if ($uid !== '' && $emoji !== '') $out[$uid] = $emoji;
        }
        return $out;
    }

    /**
     * GET: Stories analytics.
     */
    public function get_analytics()
    {
        $this->_require_role(self::VIEW_ROLES, 'stories_view', 'Stories', 'view');
        $this->_require_view();

        $now = time();

        $total = 0; $active = 0; $expired = 0; $flagged = 0; $removed = 0; $totalViews = 0;
        $byTeacher = [];  // authorId => [name, count, views, pic]
        $byDay     = [];  // YYYY-MM-DD => count
        $viewDist  = [];  // ranges

        foreach ($this->_fetch_school_stories() as $s) {
            $total++;
            $views = (int) $s['viewCount'];
            $totalViews += $views;

            // Status counting (effectiveStatus already accounts for expiry)
            switch ($s['effectiveStatus']) {
                case 'flagged': $flagged++; break;
                case 'removed': $removed++; break;
                case 'expired': $expired++; break;
                default:        $active++;  break;
            }

            $tid = $s['teacherId'];
            if (!isset($byTeacher[$tid])) {
                $byTeacher[$tid] = ['name' => $s['teacherName'] ?: $tid, 'count' => 0, 'views' => 0, 'pic' => $s['teacherProfilePic']];
            }
            $byTeacher[$tid]['count']++;
            $byTeacher[$tid]['views'] += $views;

            // By day (last 30 days)
            if ($s['createdAt'] > 0) {
                $day = date('Y-m-d', (int) ($s['createdAt'] / 1000));
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

        // Sort by-teacher by count descending
        uasort($byTeacher, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        // Fill in missing days for chart (last 30 days)
        $dailyData = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = date('Y-m-d', $now - $i * 86400);
            $dailyData[] = ['date' => $day, 'count' => $byDay[$day] ?? 0];
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
        $this->_require_role(self::MODERATE_ROLES, 'moderate_story', 'Stories', 'edit');
        $this->_require_moderate();

        $storyId   = $this->_story_id_param($this->input->post('story_id') ?? '');
        $newStatus = trim($this->input->post('status') ?? '');
        $reason    = $this->_clean_text(trim($this->input->post('reason') ?? ''));

        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            $this->json_error('Invalid status. Allowed: active, flagged, removed.');
        }

        // Verify story exists AND belongs to this school (tenant isolation).
        if ($this->_get_owned_story($storyId) === null) {
            $this->json_error('Story not found.', 404);
        }

        $updateData = [
            'status'          => $newStatus,
            'moderatedBy'     => $this->admin_id,
            'moderatedByName' => $this->admin_name,
            'moderatedAt'     => (int) round(microtime(true) * 1000),
        ];
        if ($reason !== '') {
            $updateData['moderationReason'] = $reason;
        }

        if (!$this->firebase->firestoreUpdate(self::COLLECTION, $storyId, $updateData)) {
            $this->json_error('Failed to update story status.');
        }

        $this->json_success([
            'message' => 'Story status changed to ' . ucfirst($newStatus) . '.',
        ]);
    }

    /**
     * POST: Permanently delete a story (+ best-effort Storage media cleanup).
     */
    public function delete_story()
    {
        $this->_require_role(self::DELETE_ROLES, 'delete_story', 'Stories', 'manage');
        $this->_require_delete();

        $storyId = $this->_story_id_param($this->input->post('story_id') ?? '');

        $owned = $this->_get_owned_story($storyId);
        if ($owned === null) {
            $this->json_error('Story not found.', 404);
        }
        [$docId, $d] = $owned;

        if (!$this->firebase->firestoreDelete(self::COLLECTION, $docId)) {
            $this->json_error('Failed to delete story.');
        }

        // Best-effort: purge the backing Storage object so the bucket
        // doesn't accumulate orphans. Non-fatal — the doc is already gone.
        $this->_best_effort_delete_media((string) ($d['mediaUrl'] ?? ''));

        $this->json_success(['message' => 'Story permanently deleted.']);
    }

    /**
     * GET: Get list of authors who have stories.
     */
    public function get_teachers()
    {
        $this->_require_role(self::VIEW_ROLES, 'stories_view', 'Stories', 'view');
        $this->_require_view();

        $byAuthor = [];
        foreach ($this->_fetch_school_stories() as $s) {
            $tid = $s['teacherId'];
            if ($tid === '') continue;
            if (!isset($byAuthor[$tid])) {
                $byAuthor[$tid] = ['teacherId' => $tid, 'name' => $s['teacherName'], 'profilePic' => $s['teacherProfilePic'], 'storyCount' => 0];
            }
            $byAuthor[$tid]['storyCount']++;
            if ($byAuthor[$tid]['name'] === '' && $s['teacherName'] !== '') {
                $byAuthor[$tid]['name'] = $s['teacherName'];
                $byAuthor[$tid]['profilePic'] = $s['teacherProfilePic'];
            }
        }

        $teachers = array_values($byAuthor);
        usort($teachers, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $this->json_success(['teachers' => $teachers]);
    }

    /**
     * GET: Class-section options for the admin upload audience picker.
     * Empty selection = whole-school. Each option's `key` is the canonical
     * audience token the Parent app matches against.
     */
    public function get_audience_options()
    {
        $this->_require_role(self::MODERATE_ROLES, 'stories_audience', 'Stories', 'edit');
        $this->_require_moderate();

        $options = array_values($this->_school_audience_map());
        usort($options, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });

        $this->json_success(['options' => $options]);
    }

    /**
     * POST: Bulk moderation -- change status for multiple stories.
     *
     * Expects: items = JSON array of { story_id } (or {teacher_id, story_id}
     * for back-compat — teacher_id is ignored, story_id is the Firestore
     * doc id), status = "active|flagged|removed".
     */
    public function bulk_moderate()
    {
        $this->_require_role(self::MODERATE_ROLES, 'bulk_moderate', 'Stories', 'edit');
        $this->_require_moderate();

        $newStatus = trim($this->input->post('status') ?? '');
        $reason    = $this->_clean_text(trim($this->input->post('reason') ?? ''));

        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            $this->json_error('Invalid status. Allowed: active, flagged, removed.');
        }

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
        $now     = (int) round(microtime(true) * 1000);

        foreach ($itemsRaw as $item) {
            if (!is_array($item)) { $failed++; continue; }

            $sid = trim($item['story_id'] ?? '');
            // Same safe-segment charset as safe_path_segment(); validate inline
            // so one bad id skips just that item instead of aborting the batch.
            if ($sid === '' || !preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $sid)) { $failed++; continue; }

            // Tenant isolation: only touch docs owned by this school.
            if ($this->_get_owned_story($sid) === null) { $failed++; continue; }

            $updateData = [
                'status'          => $newStatus,
                'moderatedBy'     => $this->admin_id,
                'moderatedByName' => $this->admin_name,
                'moderatedAt'     => $now,
            ];
            if ($reason !== '') {
                $updateData['moderationReason'] = $reason;
            }

            if ($this->firebase->firestoreUpdate(self::COLLECTION, $sid, $updateData)) {
                $success++;
            } else {
                $failed++;
            }
        }

        $statusLabel = ucfirst($newStatus);
        $this->json_success([
            'message' => "{$success} stories updated to {$statusLabel}." . ($failed > 0 ? " {$failed} failed." : ''),
            'success' => $success,
            'failed'  => $failed,
        ]);
    }

    /**
     * Best-effort deletion of a Firebase Storage object given its public
     * download URL. Silent on any failure — callers treat it as advisory.
     */
    private function _best_effort_delete_media(string $mediaUrl): void
    {
        if ($mediaUrl === '') return;
        // Public URL form: https://firebasestorage.googleapis.com/v0/b/{bucket}/o/{ENCODED_PATH}?alt=media&token=...
        if (!preg_match('#/o/([^?]+)#', $mediaUrl, $m)) return;
        $objectPath = urldecode($m[1]);
        if ($objectPath === '') return;
        try {
            $this->firebase->getStorageBucket()->object($objectPath)->delete();
        } catch (\Throwable $_) {
            // orphaned media is non-fatal
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ADMIN UPLOAD (Phase C)
    //
    //  Accepts a multipart POST (file + caption + priority + type) from
    //  the Stories Management SPA, uploads the media to Firebase Storage,
    //  then writes a canonical Firestore doc with authorType='admin'.
    //  Whole-school admin stories carry audienceClassKeys = ["*"] (the
    //  AUDIENCE_ALL sentinel) so the apps' server-side audience query matches.
    //
    //  Storage path:  schools/{schoolId}/stories/{adminId}/{epochMillis}.{ext}
    //  Firestore doc: stories/{schoolId}_admin_{adminId}_{epochMillis}
    // ══════════════════════════════════════════════════════════════════
    /**
     * Receive ONE slice of a story media upload.
     *
     * Shared implementation lives in MY_Controller (_receive_chunk); the
     * endpoint stays here so it keeps the stories moderation gate. Stories
     * allow 50 MB video against a stock post_max_size of 8M, so a single POST
     * could never carry a real clip — see MY_Controller's CHUNKED UPLOAD
     * SUPPORT block for the full rationale.
     */
    public function upload_story_chunk()
    {
        $this->_require_moderate();
        $this->output->set_content_type('application/json');

        [$ok, $msg] = $this->_receive_chunk('stories');
        $this->output->set_output(json_encode($ok
            ? ['status' => 'success', 'received' => $msg]
            : ['status' => 'error', 'message' => $msg]));
    }

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

        // ── 1b. Audience — WHOLE-SCHOOL = ["*"] (AUDIENCE_ALL sentinel), NOT
        // an empty array: the apps' server-side array-contains-any audience
        // query only matches stories that carry either the child's class key
        // or the '*' sentinel. A submitted `audience` (JSON array of canonical
        // keys) is intersected with the school's REAL section keys so a story
        // can't target a bogus/other-school section. Unknown keys are dropped;
        // if nothing valid remains the post falls back to whole-school (["*"]).
        $audienceRaw = $this->input->post('audience');
        if (is_string($audienceRaw)) {
            $audienceRaw = json_decode($audienceRaw, true);
        }
        $audienceClassKeys = [];
        if (is_array($audienceRaw) && !empty($audienceRaw)) {
            $validKeys = $this->_school_audience_map();   // key => option
            foreach ($audienceRaw as $k) {
                $k = strtolower(trim((string) $k));
                if ($k !== '' && isset($validKeys[$k]) && !in_array($k, $audienceClassKeys, true)) {
                    $audienceClassKeys[] = $k;
                }
            }
        }
        // Whole-school (no specific classes chosen / none valid) → ["*"].
        if (empty($audienceClassKeys)) {
            $audienceClassKeys = [self::AUDIENCE_ALL];
        }

        // ── 2. Validate file ──────────────────────────────────────────
        // Resolve from staged chunks (default) or a single POST (legacy client).
        // Both produce the same $_FILES-shaped array so everything below is
        // identical either way. See MY_Controller CHUNKED UPLOAD SUPPORT.
        $chunkId  = trim((string) $this->input->post('chunk_upload_id'));
        $chunkDir = null;
        if ($chunkId !== '') {
            $chunkDir = $this->_chunk_dir($chunkId, 'stories');
            // Staged parts must not outlive the request on ANY exit path — every
            // json_error() below is a hard exit.
            $this->_chunk_cleanup_on_shutdown($chunkDir);
            $file = $this->_assemble_chunks(
                $chunkId,
                'stories',
                (string) $this->input->post('file_name'),
                $this->input->post('total_chunks')
            );
            if ($file === null) {
                $this->json_error('Upload was incomplete — some parts did not arrive. Please try again.');
            }
        } else {
            if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
                $this->json_error('Media file is required.');
            }
            $file = $_FILES['media'];
        }
        $size = (int) ($file['size'] ?? 0);
        $maxBytes = $type === 'image' ? self::MAX_IMAGE_BYTES : self::MAX_VIDEO_BYTES;
        if ($size <= 0 || $size > $maxBytes) {
            $maxMb = $maxBytes / (1024 * 1024);
            $this->json_error(ucfirst($type) . " must be 0–{$maxMb} MB.");
        }

        // ── MIME sniff (M-3) — the browser-supplied $_FILES['media']['type']
        // is spoofable, so we IGNORE it and derive the real content type from
        // the file bytes with finfo, then reconcile against a strict allow-list
        // that also matches the declared `type` (image vs video). The sniffed
        // MIME (never the client value) drives the stored extension below.
        $allowedMimes = [
            'image' => ['image/jpeg', 'image/png', 'image/webp'],
            'video' => ['video/mp4', 'video/quicktime', 'video/3gpp', 'video/webm'],
        ];
        $tmp = (string) ($file['tmp_name'] ?? '');
        // is_uploaded_file() is false for an assembled chunk file by definition,
        // so it only applies to the single-POST path. The assembled path is not
        // weaker: its tmp_name is built server-side from a hex-validated upload
        // id inside our own staging dir and is never client-supplied.
        $isAssembled = !empty($file['_zx_assembled']);
        if ($tmp === '' || (!$isAssembled && !is_uploaded_file($tmp)) || !is_file($tmp)) {
            $this->json_error('Invalid upload.');
        }
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }
        if ($mime === '') {
            $this->json_error('Could not determine the file type.');
        }
        if (!in_array($mime, $allowedMimes[$type] ?? [], true)) {
            $this->json_error(
                'File content (' . $mime . ') is not an allowed ' . $type . '. '
                . 'Allowed: ' . implode(', ', $allowedMimes[$type] ?? []) . '.'
            );
        }

        // ── 2b. Rate limit (Hardening #4) ─────────────────────────────
        // Query active non-expired stories for THIS admin user; reject
        // at the cap. Fail-open if the query errors so a Firestore
        // blip doesn't block a legitimate upload.
        $schoolId = $this->fs->schoolId();
        $adminId  = (string) ($this->admin_id ?? 'admin');
        try {
            $existing = $this->firebase->firestoreQuery(self::COLLECTION, [
                ['schoolId', '==', $schoolId],
                ['authorId', '==', $adminId],
            ]);
            $nowMs = (int) (microtime(true) * 1000);
            $liveCount = 0;
            foreach ((array) $existing as $row) {
                $d = $row['data'] ?? $row;
                if (!is_array($d)) continue;
                $status = (string) ($d['status'] ?? 'active');
                $exp    = $this->_expiry_millis($d);
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
        // Canonical Storage scheme: schools/{schoolId}/stories/... so a school's
        // entire footprint lives under one ID-keyed prefix.
        $remotePath = "schools/{$schoolId}/stories/{$adminId}/{$ts}.{$ext}";

        // ── 4. Upload to Firebase Storage ─────────────────────────────
        $okUp = $this->firebase->uploadFile($file['tmp_name'], $remotePath);
        if (!$okUp) {
            $this->json_error('Storage upload failed. Check Storage rules + service account.');
        }
        $downloadUrl = $this->firebase->getDownloadUrl($remotePath);
        if ($downloadUrl === '') {
            $this->json_error('Storage upload succeeded but download URL was empty.');
        }

        // ── 4b. Video poster frame ────────────────────────────────────
        // Stories previously stored NO poster, so both the admin grid and the
        // detail view fell back to rendering the <video> element itself as the
        // thumbnail — which downloads metadata for every story on the page (and
        // was blocked outright before media-src was added to the CSP). The
        // Teacher app already writes `thumbnailUrl`; this brings the panel to
        // the same contract so both apps can show a still while buffering.
        // Best-effort: a poster failure must never fail the upload.
        $thumbnailUrl = '';
        if ($type === 'video') {
            $ffmpeg     = defined('FFMPEG_PATH') ? FFMPEG_PATH : 'ffmpeg';
            $thumbName  = "thumb_{$ts}.jpg";
            $thumbLocal = rtrim($this->_upload_staging_dir(), "/\\") . DIRECTORY_SEPARATOR . $thumbName;
            $thumbCmd   = "\"$ffmpeg\" -i " . escapeshellarg($file['tmp_name'])
                        . " -ss 00:00:01.000 -vframes 1 -q:v 2 " . escapeshellarg($thumbLocal);
            shell_exec($thumbCmd);

            if (is_file($thumbLocal) && filesize($thumbLocal) > 0) {
                $thumbRemote = "schools/{$schoolId}/stories/{$adminId}/{$thumbName}";
                if ($this->firebase->uploadFile($thumbLocal, $thumbRemote)) {
                    $thumbnailUrl = $this->firebase->getDownloadUrl($thumbRemote);
                }
                @unlink($thumbLocal);
            } else {
                // Silent failure here is what left gallery videos posterless for
                // months — log it so the cause (missing ffmpeg / disabled
                // shell_exec) is visible instead of guessed at.
                log_message('error',
                    'Story video poster extraction FAILED (thumbnailUrl will be empty). '
                    . 'ffmpeg=' . $ffmpeg
                    . ' shell_exec_available=' . (function_exists('shell_exec') ? 'yes' : 'NO')
                    . ' story_ts=' . $ts . ' school=' . $schoolId);
            }
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
            // Poster frame for video ("" for images and when extraction failed).
            // Matches the Teacher app's field name — note stories use
            // `thumbnailUrl` (camel) while galleryMedia uses `thumbnail`.
            'thumbnailUrl'    => $thumbnailUrl,
            'caption'         => $caption,
            'priority'        => $priority,
            // Lifecycle — canonical expiry = expiresAtTs (Firestore Timestamp).
            // Legacy expiresAt (Long) written for one release only.
            'createdAt'       => date('c'),
            // NOTE: the class file is Firestore_rest_client.php but it DECLARES
            // `class FirestoreRestClient` — the static call MUST use that name
            // (the old `Firestore_rest_client::timestamp()` threw "Class not
            // found" → the admin upload's "unexpected error").
            'expiresAtTs'     => \FirestoreRestClient::timestamp($expiresAt),
            'expiresAt'       => $expiresAt,   // LEGACY — remove in v2.0
            'viewCount'       => 0,
            // Audience — ["*"] (AUDIENCE_ALL) = whole-school; else the
            // validated canonical class-section keys chosen in the upload
            // picker. Never an empty array (see §1b).
            'audienceClassKeys' => $audienceClassKeys,
            // reactionCounts is intentionally omitted — the apps default it
            // to an empty map and the reaction transaction lazily creates the
            // field on first reaction. Writing an empty PHP array here would
            // serialise as a Firestore arrayValue (not a map) and corrupt the
            // contract.
            // Moderation defaults
            'status'          => 'active',
            'moderatedBy'     => '',
            'moderatedByName' => '',
            'moderatedAt'     => 0,
            'moderationReason' => '',
        ];

        $okFs = $this->firebase->firestoreSet(self::COLLECTION, $storyId, $doc);
        if (!$okFs) {
            // Storage upload happened but Firestore failed — clean up the
            // orphaned media so the bucket doesn't bloat over time.
            $this->_best_effort_delete_media($downloadUrl);
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
