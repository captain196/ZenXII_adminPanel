<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Ptm Controller — Parent-Teacher Meeting management.
 *
 * Firestore collections:
 *   ptmEvents/{schoolId}_{ptmEventId}      — meeting metadata + slots
 *   ptmRsvps/{schoolId}_{ptmEventId}_{studentId} — parent responses
 *
 * Counter:
 *   Schools/{school_name}/Ptm/Counters/Ptm   (PTM00001, PTM00002, ...)
 */
class Ptm extends MY_Controller
{
    private const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Principal', 'Vice Principal', 'Admin', 'Academic Coordinator'];
    private const VIEW_ROLES  = ['Super Admin', 'School Super Admin', 'Principal', 'Vice Principal', 'Admin', 'Academic Coordinator', 'Class Teacher', 'Teacher'];

    private const COL_PTMS  = 'ptmEvents';
    private const COL_RSVPS = 'ptmRsvps';

    private const ALLOWED_STATUSES = ['scheduled', 'completed', 'cancelled'];
    private const RSVP_STATUSES    = ['pending', 'confirmed', 'declined', 'attended', 'no-show'];

    /** Cached Firebase ID-token claims for parent/teacher API endpoints. */
    private array $_app_claims = [];

    public function __construct()
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        // Parent + teacher app endpoints authenticate via Firebase ID token
        // (Bearer header). They do NOT use the admin session and bypass
        // require_permission('Events'). School context is resolved from the
        // token claims (mirrors Fee_management::parent_create_order).
        $isAppApi = (
            strpos($uri, 'ptm/parent_submit_rsvp')      !== false ||
            strpos($uri, 'ptm/teacher_mark_attendance') !== false
        );

        if ($isAppApi) {
            CI_Controller::__construct();
            $this->load->library('firebase');
            $this->load->library('api_auth');
            $this->load->library('Firestore_service', null, 'fs');

            $claims = $this->api_auth->require_auth();
            $this->_app_claims  = $claims;
            $this->school_name  = (string) ($claims['school_id'] ?? '');
            $this->school_id    = (string) ($claims['school_id'] ?? '');
            $this->parent_db_key = (string) ($claims['parent_db_key'] ?? $this->school_name);
            $this->session_year = $this->_resolve_active_session_for_app($this->school_name);
            $this->admin_id     = 'app:' . ($claims['uid'] ?? 'unknown');
            $this->admin_role   = ucfirst((string) ($claims['role'] ?? ''));

            if ($this->school_name === '' || $this->session_year === '') {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Unable to resolve school/session from token.']);
                exit;
            }

            $this->fs->init($this->school_name, $this->session_year);
            return;
        }

        parent::__construct();
        require_permission('Events'); // share Events permission for now
        $this->load->library('operations_accounting');
        $this->operations_accounting->init(
            $this->firebase, $this->school_name, $this->session_year, $this->admin_id, $this, $this->parent_db_key
        );
    }

    /**
     * Resolve the active session for a school during an app-API request
     * (no admin session available). Reads from the school's settings doc.
     * Falls back to current calendar session if the lookup fails.
     */
    private function _resolve_active_session_for_app(string $schoolName): string
    {
        if ($schoolName === '') return '';
        try {
            $doc = $this->firebase->firestoreGet('schools', $schoolName);
            $session = (string) ($doc['active_session'] ?? $doc['session'] ?? '');
            if ($session !== '') return $session;
        } catch (\Exception $_) { /* fall through */ }
        // Calendar fallback: April–March year cycle (Indian academic year).
        $y = (int) date('Y');
        $m = (int) date('n');
        if ($m >= 4) return $y . '-' . substr((string) ($y + 1), -2);
        return ($y - 1) . '-' . substr((string) $y, -2);
    }

    private function _require_admin(): void
    {
        if (!in_array($this->admin_role, self::ADMIN_ROLES, true)) {
            if ($this->input->is_ajax_request()) $this->json_error('Access denied.', 403);
            redirect(base_url('admin'));
        }
    }

    private function _counter_path(): string
    {
        return "Schools/{$this->school_name}/Ptm/Counters/Ptm";
    }

    private function _doc_id(string $ptmId): string
    {
        return $this->fs->docId($ptmId);
    }

    private function _rsvp_doc_id(string $ptmId, string $studentId): string
    {
        return $this->fs->docId2($ptmId, $studentId);
    }

    /**
     * Per-request memoized lookup of a teacher's display name. Falls back
     * to "this teacher" when no name can be resolved — never returns an
     * empty string and never returns a raw staffId, which would be unhelpful
     * in error messages shown to admins.
     */
    private array $teacherNameCache = [];
    private function _resolve_teacher_name(string $teacherId, string $hint = ''): string
    {
        $hint = trim($hint);
        if ($hint !== '') {
            // Trust client-supplied name when present; cache for re-use.
            $this->teacherNameCache[$teacherId] = $hint;
            return $hint;
        }
        if ($teacherId === '') return 'this teacher';
        if (isset($this->teacherNameCache[$teacherId])) {
            return $this->teacherNameCache[$teacherId];
        }
        $name = '';
        try {
            $doc = $this->fs->getEntity('staff', $teacherId);
            if (is_array($doc)) {
                $name = trim((string) ($doc['name'] ?? $doc['Name'] ?? ''));
            }
        } catch (\Exception $_) { /* fall through to default */ }
        if ($name === '') $name = 'this teacher';
        $this->teacherNameCache[$teacherId] = $name;
        return $name;
    }

    // ─── Pages ──────────────────────────────────────────────────────────

    public function index()
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            redirect(base_url('admin'));
        }
        $data = ['active_tab' => 'ptm'];
        $this->load->view('include/header', $data);
        $this->load->view('ptm/list', $data);
        $this->load->view('include/footer');
    }

    public function create()
    {
        $this->_require_admin();
        $data = ['active_tab' => 'ptm', 'ptm' => null];
        $this->load->view('include/header', $data);
        $this->load->view('ptm/create', $data);
        $this->load->view('include/footer');
    }

    /**
     * Edit an existing PTM. Reuses ptm/create.php in edit mode — the form
     * detects a non-null `$ptm` payload and prefills + sends a hidden
     * `ptmEventId` so save() takes the merge-update path. Existing RSVPs
     * are preserved (they reference ptmEventId, not slot index identity).
     */
    public function edit(string $ptmEventId = '')
    {
        $this->_require_admin();
        if ($ptmEventId === '') redirect(base_url('ptm'));

        $ptm = $this->fs->getEntity(self::COL_PTMS, $ptmEventId);
        if (!is_array($ptm)) {
            redirect(base_url('ptm'));
        }
        $ptm['id'] = $ptmEventId;
        if (!isset($ptm['ptmEventId']) || $ptm['ptmEventId'] === '') {
            $ptm['ptmEventId'] = $ptmEventId;
        }

        $data = ['active_tab' => 'ptm', 'ptm' => $ptm];
        $this->load->view('include/header', $data);
        $this->load->view('ptm/create', $data);
        $this->load->view('include/footer');
    }

    public function rsvps(string $ptmEventId = '')
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            redirect(base_url('admin'));
        }
        if ($ptmEventId === '') redirect(base_url('ptm'));

        $ptm = $this->fs->getEntity(self::COL_PTMS, $ptmEventId);
        if (!is_array($ptm)) {
            redirect(base_url('ptm'));
        }
        $ptm['id'] = $ptmEventId;

        $data = [
            'active_tab' => 'ptm',
            'ptm'        => $ptm,
        ];
        $this->load->view('include/header', $data);
        $this->load->view('ptm/rsvps', $data);
        $this->load->view('include/footer');
    }

    // ─── JSON: list PTMs ────────────────────────────────────────────────

    public function get_list()
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }

        $rows = $this->firebase->firestoreQuery(self::COL_PTMS,
            [['schoolId', '==', $this->school_name]], 'date', 'DESC');

        $list = [];
        foreach ((array) $rows as $doc) {
            $p = is_array($doc['data'] ?? null) ? $doc['data'] : (is_array($doc) ? $doc : []);
            $id = (string) ($p['ptmEventId'] ?? ($doc['id'] ?? ''));
            if ($id === '') continue;
            $p['id'] = $id;
            $list[] = $p;
        }
        $this->json_success(['ptms' => $list, 'total' => count($list)]);
    }

    public function get_one(string $ptmEventId = '')
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
        if ($ptmEventId === '') $this->json_error('PTM id required.');

        $ptm = $this->fs->getEntity(self::COL_PTMS, $ptmEventId);
        if (!is_array($ptm)) $this->json_error('PTM not found.', 404);
        $ptm['id'] = $ptmEventId;

        // Aggregate RSVP counts.
        $rsvps = $this->firebase->firestoreQuery(self::COL_RSVPS, [
            ['schoolId',   '==', $this->school_name],
            ['ptmEventId', '==', $ptmEventId],
        ]);
        // Phase-C vocab: "applied" / "delivered" / "no-show" / "declined".
        // Legacy vocab: "confirmed" / "attended" / "pending". Map legacy onto
        // Phase-C buckets so the admin sees a unified count regardless of
        // which app build wrote the RSVP — and any unknown status falls
        // through to "pending" so it stays visible.
        $applied = 0; $declined = 0; $delivered = 0; $noShow = 0; $pending = 0;
        foreach ((array) $rsvps as $r) {
            $row = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            $s = strtolower((string) ($row['status'] ?? 'pending'));
            switch ($s) {
                case 'applied':
                case 'confirmed':  $applied++;   break;
                case 'declined':   $declined++;  break;
                case 'delivered':
                case 'attended':   $delivered++; break;
                case 'no-show':    $noShow++;    break;
                default:           $pending++;   break;
            }
        }
        $ptm['rsvp_summary'] = [
            'applied'   => $applied,
            'declined'  => $declined,
            'delivered' => $delivered,
            'no_show'   => $noShow,
            'pending'   => $pending,
            'total'     => $applied + $declined + $delivered + $noShow + $pending,
            // Legacy aliases — older admin views read these keys.
            'confirmed' => $applied,
            'attended'  => $delivered,
        ];

        $this->json_success(['ptm' => $ptm]);
    }

    // ════════════════════════════════════════════════════════════════════
    //  PHASE-B HELPERS — section→class-teacher resolution
    // ════════════════════════════════════════════════════════════════════

    /**
     * Per-request prefetch caches. Filled on first access by
     * [_prefetch_sections] and [_prefetch_staff]. Keyed by sectionKey
     * ("Class 8th/Section A") and staffId respectively. The combined
     * prefetch turns the section→teacher resolution from N×2+N round
     * trips into 2 — a ~15× speed-up on a 30-section school.
     */
    private array $sectionsCache  = [];
    private bool  $sectionsCacheLoaded = false;
    private array $staffNameCache = [];
    private bool  $staffCacheLoaded = false;

    /**
     * One-shot bulk read of the `sections` collection for this school.
     * Stores a map keyed by normalised sectionKey. Falls back to
     * [school_id] only when [school_name] yields nothing (mirrors the
     * dual-id tolerance in get_sections / get_teachers).
     */
    private function _prefetch_sections(): void
    {
        if ($this->sectionsCacheLoaded) return;
        $rows = [];
        foreach ([$this->school_name, $this->school_id] as $sid) {
            if ($sid === '') continue;
            try {
                $rows = $this->firebase->firestoreQuery('sections',
                    [['schoolId', '==', $sid]], 'className', 'ASC');
                if (!empty($rows)) break; // first non-empty wins
            } catch (\Exception $e) {
                log_message('error', "Ptm::_prefetch_sections (sid={$sid}) failed: " . $e->getMessage());
            }
        }
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $c = trim((string) ($d['className'] ?? ''));
            $s = trim((string) ($d['section']   ?? ''));
            if ($c === '' || $s === '') continue;
            if (stripos($c, 'Class ')   !== 0) $c = "Class {$c}";
            if (stripos($s, 'Section ') !== 0) $s = "Section {$s}";
            $this->sectionsCache["{$c}/{$s}"] = $d;
        }
        $this->sectionsCacheLoaded = true;
    }

    /**
     * One-shot bulk read of the `staff` collection. Builds a map of
     * staffId → display name so per-teacher lookups in the resolution
     * loop are pure in-memory hits.
     */
    private function _prefetch_staff(): void
    {
        if ($this->staffCacheLoaded) return;
        $rows = [];
        foreach ([$this->school_name, $this->school_id] as $sid) {
            if ($sid === '') continue;
            try {
                $rows = $this->firebase->firestoreQuery('staff',
                    [['schoolId', '==', $sid]], 'name', 'ASC');
                if (!empty($rows)) break;
            } catch (\Exception $e) {
                log_message('error', "Ptm::_prefetch_staff (sid={$sid}) failed: " . $e->getMessage());
            }
        }
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $sid2 = (string) ($d['staffId']  ?? $d['teacherId'] ?? ($r['id'] ?? ''));
            $name = (string) ($d['name']     ?? $d['Name']      ?? '');
            if ($sid2 !== '' && $name !== '') {
                $this->staffNameCache[$sid2] = $name;
                // Seed the per-request cache used by _resolve_teacher_name
                // so later calls also short-circuit.
                $this->teacherNameCache[$sid2] = $name;
            }
        }
        $this->staffCacheLoaded = true;
    }

    /**
     * Enumerate every (className, section) pair for this school. After the
     * Phase-D perf pass this just walks the prefetched sections cache —
     * zero extra round trips when called alongside _resolve_sections_to_teachers.
     */
    private function _enumerate_school_sections(): array
    {
        $this->_prefetch_sections();
        $pairs = [];
        foreach (array_keys($this->sectionsCache) as $key) {
            $parts = explode('/', $key, 2);
            if (count($parts) !== 2) continue;
            $pairs[] = ['className' => $parts[0], 'section' => $parts[1]];
        }
        if (empty($pairs)) {
            // Students fallback — matches the create form's section dropdown logic.
            foreach ($this->_derive_sections_from_students() as $row) {
                $pairs[] = ['className' => $row['className'], 'section' => $row['section']];
            }
        }
        return $pairs;
    }

    /**
     * Resolve a list of (className, section) pairs to their class teachers.
     * Returns a 2-tuple: [resolvedSections, missingSections]. Each resolved
     * entry carries the snapshot of `classTeacherId`/`classTeacherName` at
     * resolution time so a later change to the section's class teacher
     * doesn't retroactively reroute submitted RSVPs.
     */
    /**
     * Resolve a list of (className, section) pairs to their class teachers.
     *
     * Phase-D perf pass: prefetches the sections + staff collections each
     * once and resolves every pair from in-memory maps. For a 30-section
     * school this turns 90 round trips (~17 s on a slow connection) into
     * 2 round trips (~0.6 s).
     *
     * Returns [resolvedSections, missingSections]. Each resolved entry
     * carries the snapshot of `classTeacherId`/`classTeacherName` at
     * resolution time so a later change of section.classTeacherId doesn't
     * retroactively reroute submitted RSVPs.
     */
    private function _resolve_sections_to_teachers(array $pairs): array
    {
        $this->_prefetch_sections();
        $this->_prefetch_staff();

        // Dedupe input on normalised sectionKey so messy collection data
        // never produces duplicate sections[] entries.
        $seen     = [];
        $resolved = [];
        $missing  = [];
        foreach ($pairs as $pair) {
            $c = trim((string) ($pair['className'] ?? ''));
            $s = trim((string) ($pair['section']   ?? ''));
            if ($c === '' || $s === '') continue;
            $sectionKey = "{$c}/{$s}";
            if (isset($seen[$sectionKey])) continue;
            $seen[$sectionKey] = true;

            // Pure in-memory lookup — the prefetched cache is the source.
            $secDoc = $this->sectionsCache[$sectionKey] ?? null;
            $classTeacherId = '';
            if (is_array($secDoc)) {
                $classTeacherId = trim((string) (
                    $secDoc['classTeacherId']  ?? $secDoc['class_teacher_id'] ?? ''
                ));
            }

            if ($classTeacherId === '') {
                $missing[] = ['className' => $c, 'section' => $s, 'sectionKey' => $sectionKey];
                $resolved[] = [
                    'className'        => $c,
                    'section'          => $s,
                    'sectionKey'       => $sectionKey,
                    'classTeacherId'   => '',
                    'classTeacherName' => '',
                ];
                continue;
            }

            // Teacher name from prefetched staff cache. Fallback to the
            // single-doc resolver if a staff record exists outside the
            // bulk-query result (rare — usually means schoolId mismatch).
            $teacherName = $this->staffNameCache[$classTeacherId]
                ?? $this->_resolve_teacher_name($classTeacherId, '');
            $resolved[] = [
                'className'        => $c,
                'section'          => $s,
                'sectionKey'       => $sectionKey,
                'classTeacherId'   => $classTeacherId,
                'classTeacherName' => $teacherName,
            ];
        }
        return [$resolved, $missing];
    }

    /**
     * Emit a 422 with a structured payload listing the sections that lack
     * a class teacher. The create form recognises `code: MISSING_CLASS_TEACHER`
     * and surfaces an inline panel pointing the admin at Sections admin.
     */
    private function _emit_missing_class_teacher(array $missing): void
    {
        $names = array_map(fn($m) => $m['sectionKey'], $missing);
        $msg = (count($missing) === 1)
            ? "No class teacher set for {$names[0]}. Set one in Sections, then retry."
            : count($missing) . " sections have no class teacher: " . implode(', ', $names) . ". Set them in Sections, then retry.";
        log_message('warning', "Ptm::save MISSING_CLASS_TEACHER count=" . count($missing) . " sections=" . implode(',', $names));
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode([
            'status'           => 'error',
            'code'             => 'MISSING_CLASS_TEACHER',
            'message'          => $msg,
            'missingSections'  => array_values($missing),
            'csrf_token'       => $this->security->get_csrf_hash(),
        ]);
        exit;
    }

    /**
     * Live preview endpoint used by the create form to render the
     * section→class-teacher mapping before save. GET so the form can call
     * it on scope/section change without CSRF.
     *
     * Query: ?scope=all|specific [&className=&section=]
     * Returns: { sections: [...], missing: [...], scope }
     */
    public function resolve_class_teachers()
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
        $scope     = strtolower(trim((string) $this->input->get('scope')));
        $className = trim((string) $this->input->get('className'));
        $section   = trim((string) $this->input->get('section'));
        if (!in_array($scope, ['all', 'specific'], true)) $scope = 'all';

        $pairs = ($scope === 'specific')
            ? [['className' => $className, 'section' => $section]]
            : $this->_enumerate_school_sections();

        if (empty($pairs)) {
            $this->json_success([
                'scope'    => $scope,
                'sections' => [],
                'missing'  => [],
            ]);
        }

        [$resolved, $missing] = $this->_resolve_sections_to_teachers($pairs);
        $this->json_success([
            'scope'    => $scope,
            'sections' => $resolved,
            'missing'  => $missing,
        ]);
    }

    // ─── JSON: save / update PTM ────────────────────────────────────────

    public function save()
    {
        $this->_require_admin();
        if ($this->input->method() !== 'post') {
            $this->json_error('POST required.', 405);
        }

        // Per-request timing — surfaced as a `timing` field in the response
        // and as a single structured log line so we can grep production
        // saves and confirm we never regress past target (~3-5s ideal).
        $perfStart = microtime(true);
        $perf = [];

        $id          = trim($this->input->post('ptmEventId') ?? '');
        $title       = trim($this->input->post('title') ?? '');
        $description = trim($this->input->post('description') ?? '');
        $location    = trim($this->input->post('location') ?? '');
        $date        = trim($this->input->post('date') ?? '');
        $startTime   = trim($this->input->post('startTime') ?? '');
        $endTime     = trim($this->input->post('endTime') ?? '');
        $audienceScope = strtolower(trim($this->input->post('audienceScope') ?? 'all'));
        $className   = trim($this->input->post('className') ?? '');
        $section     = trim($this->input->post('section') ?? '');
        $status      = trim($this->input->post('status') ?? 'scheduled');

        if ($title === '')    $this->json_error('Title is required.');
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->json_error('Valid date (YYYY-MM-DD) is required.');
        }
        // Reject past dates only on create. Editing an existing scheduled
        // PTM whose date is already today is fine (clock-skew window). For
        // updates we trust the admin — past dates may be intentional (e.g.
        // post-meeting cleanup before marking completed).
        $isUpdateRequest = ($id !== '');
        $today = date('Y-m-d');
        if (!$isUpdateRequest && $status === 'scheduled' && $date < $today) {
            $this->json_error('Date must be today or later.');
        }
        $confirmDestructive = (bool) $this->input->post('confirmDestructive');
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $this->json_error('Invalid status.');
        }
        if (mb_strlen($title) > 200) $this->json_error('Title is too long.');
        if (mb_strlen($description) > 2000) $this->json_error('Description is too long.');

        // ── Phase-B time-window validation (replaces slot loop) ─────
        if (!preg_match('/^\d{1,2}:\d{2}$/', $startTime)) {
            $this->json_error('Valid start time (HH:MM) is required.');
        }
        if (!preg_match('/^\d{1,2}:\d{2}$/', $endTime)) {
            $this->json_error('Valid end time (HH:MM) is required.');
        }
        if (strcmp($startTime, $endTime) >= 0) {
            $this->json_error('End time must be after start time.');
        }

        // ── Audience scope + section enumeration ─────────────────────
        if (!in_array($audienceScope, ['all', 'specific'], true)) {
            $this->json_error('Invalid audience scope.');
        }
        if ($audienceScope === 'specific') {
            if ($className === '' || $section === '') {
                $this->json_error('Class and section are required for specific-section scope.');
            }
            if (stripos($className, 'Class ')   !== 0) $className = "Class {$className}";
            if (stripos($section,   'Section ') !== 0) $section   = "Section {$section}";
        }
        $tEnumStart = microtime(true);
        $sectionPairs = ($audienceScope === 'specific')
            ? [['className' => $className, 'section' => $section]]
            : $this->_enumerate_school_sections();
        $perf['enumerate_ms'] = (int) ((microtime(true) - $tEnumStart) * 1000);

        if (empty($sectionPairs)) {
            $this->json_error(
                'No sections configured for this school. Add sections in Sections admin first.'
            );
        }

        // ── Section → class teacher resolution (load-bearing check) ─
        $tResStart = microtime(true);
        [$resolvedSections, $missingSections] = $this->_resolve_sections_to_teachers($sectionPairs);
        $perf['resolve_ms']    = (int) ((microtime(true) - $tResStart) * 1000);
        $perf['section_count'] = count($sectionPairs);

        if (!empty($missingSections)) {
            $this->_emit_missing_class_teacher($missingSections);
            // exits with HTTP 422 + structured payload — see helper.
        }

        // RSVP integrity guard (Phase-B) — only on update. Phase-B PTMs are
        // window-based, so the diff space is: section list, time window,
        // date, per-section class teacher. The check fires only when the
        // PTM has at least one RSVP (no parents to strand → no warning).
        if ($isUpdateRequest && !$confirmDestructive) {
            $existing = $this->fs->getEntity(self::COL_PTMS, $id);
            if (is_array($existing)) {
                $rsvpCount = $this->_count_rsvps($id);
                if ($rsvpCount > 0) {
                    $changes = $this->_describe_phase_b_changes(
                        $existing, $resolvedSections, $startTime, $endTime, $date
                    );
                    if (!empty($changes)) {
                        $this->_emit_confirm_required($changes, $rsvpCount);
                        // exits with 409 + payload
                    }
                }
            }
        }

        // ── Cross-PTM teacher conflict (same date, window overlap) ──
        // Phase-B is single-window per PTM. A class teacher cannot be
        // assigned to two PTMs whose windows intersect on the same date.
        // Safety-critical: the same-day query retries once; persistent
        // failure blocks the save (no silent passthrough).
        $tConflictStart = microtime(true);
        $sameDayPtms = $this->_query_same_day_ptms_or_fail($date);
        $perf['conflict_ms'] = (int) ((microtime(true) - $tConflictStart) * 1000);
        $myTeacherIds = array_values(array_filter(
            array_map(fn($s) => $s['classTeacherId'], $resolvedSections)
        ));
        if (!empty($myTeacherIds)) {
            foreach ((array) $sameDayPtms as $r) {
                $other = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                $otherId = (string) ($other['ptmEventId'] ?? ($r['id'] ?? ''));
                if ($otherId === '' || $otherId === $id) continue;
                // Other PTM's window — prefer Phase-B root, fall back to
                // legacy slots[].first/last.
                $oStart = (string) ($other['startTime'] ?? ($other['slots'][0]['startTime'] ?? ''));
                $oEnd   = (string) ($other['endTime']   ?? '');
                if ($oEnd === '' && !empty($other['slots'])) {
                    $lastSlot = end($other['slots']);
                    $oEnd = (string) ($lastSlot['endTime'] ?? '');
                }
                if ($oStart === '' || $oEnd === '') continue;
                $disjoint = (strcmp($endTime, $oStart) <= 0) || (strcmp($oEnd, $startTime) <= 0);
                if ($disjoint) continue;

                // Other PTM's teacher set — Phase-B sections[] preferred.
                $otherTeachers = [];
                if (!empty($other['sections']) && is_array($other['sections'])) {
                    foreach ($other['sections'] as $os) {
                        $tid = (string) ($os['classTeacherId'] ?? '');
                        if ($tid !== '') $otherTeachers[] = $tid;
                    }
                } else {
                    foreach ((array) ($other['slots'] ?? []) as $os) {
                        $tid = (string) ($os['teacherId'] ?? '');
                        if ($tid !== '') $otherTeachers[] = $tid;
                    }
                }
                $clash = array_values(array_intersect($myTeacherIds, $otherTeachers));
                if (!empty($clash)) {
                    $tid = $clash[0];
                    $tname = $this->_resolve_teacher_name($tid, '');
                    $otherTitle = (string) ($other['title'] ?? $otherId);
                    $this->json_error(sprintf(
                        "%s is already assigned to PTM \"%s\" on %s (%s–%s).",
                        $tname, $otherTitle, $date, $oStart, $oEnd
                    ));
                }
            }
        }

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->operations_accounting->next_id($this->_counter_path(), 'PTM');
        }
        if ($id === '' || !preg_match('/^PTM\d{3,}$/', $id)) {
            $this->json_error('Failed to generate PTM id.');
        }

        // Top-level legacy mirror — old parent-app builds only know about
        // a single sectionKey. For all-school PTMs we use "ALL"; for
        // specific scope, the single section's key.
        $sectionKey = ($audienceScope === 'specific')
            ? ($className . '/' . $section)
            : 'ALL';

        // Legacy slot mirror — one slot covering the full window so older
        // (Round-3) parent app builds can still render the meeting until
        // Phase C ships.
        //
        // teacherId is set to a sentinel `__SECTIONWISE__` rather than blank:
        //   • Round-3 teacher app's getRsvpsForMyPtm filters with
        //     `b.teacherId == staffId || b.teacherId.isBlank()`. A blank
        //     teacher would surface every Phase-B booking to every teacher
        //     in the school (overshoot). The sentinel matches no real
        //     staffId AND is not blank, so old teacher apps cleanly skip
        //     these bookings — class teachers will see them once they
        //     pick up the Phase-C teacher build instead.
        //   • Phase-A's activeSections() synthesiser already ignores
        //     slot.teacherId, so the new-shape consumers are unaffected.
        //   • The parent app renders teacherName via `ifBlank { "Any teacher" }`,
        //     so leaving teacherName blank gives the right legacy UX.
        $legacySlots = [[
            'slotIndex'   => 0,
            'startTime'   => $startTime,
            'endTime'     => $endTime,
            'teacherId'   => '__SECTIONWISE__',
            'teacherName' => '',
            'capacity'    => 999,
        ]];

        $now = round(microtime(true) * 1000);
        $data = [
            'ptmEventId'  => $id,
            'schoolId'    => $this->school_name,
            'session'     => $this->session_year,
            'sectionKey'  => $sectionKey,
            'className'   => $audienceScope === 'specific' ? $className : '',
            'section'     => $audienceScope === 'specific' ? $section   : '',
            'date'        => $date,
            'title'       => $title,
            'description' => $description,
            'location'    => $location,
            'status'      => $status,

            // ── Phase-B canonical fields ──────────────────────────────
            'startTime'   => $startTime,
            'endTime'     => $endTime,
            'sections'    => $resolvedSections,

            // ── Legacy mirror (drop in Phase D) ───────────────────────
            'slots'         => $legacySlots,
            'totalCapacity' => 999,

            'updatedAt'   => $now,
            'updatedBy'   => $this->admin_id,
        ];
        if ($isNew) {
            $data['createdAt'] = $now;
            $data['createdBy'] = $this->admin_id;
        }

        $tWriteStart = microtime(true);
        try {
            $this->fs->setEntity(self::COL_PTMS, $id, $data, /* merge */ !$isNew);
        } catch (\Exception $e) {
            log_message('error', 'Ptm::save failed: ' . $e->getMessage());
            $this->json_error('Failed to save PTM. ' . $e->getMessage());
        }
        $perf['write_ms'] = (int) ((microtime(true) - $tWriteStart) * 1000);

        // Pre-create per-section queue counters so the parent app's
        // applyToPtm transaction always finds an existing counter doc.
        // Without this, two parents racing on the very first apply both
        // read a non-existent doc and risk both writing nextQueue=1 before
        // Firestore's optimistic concurrency check rejects one of them —
        // an extra retry round-trip we can simply skip by seeding the doc.
        // Uses create-if-not-exists, so re-saves and edits leave existing
        // counters (with their real nextQueue) untouched.
        $tCounterStart = microtime(true);
        $this->_seed_ptm_counters($id, $resolvedSections);
        $perf['counter_seed_ms'] = (int) ((microtime(true) - $tCounterStart) * 1000);

        $perf['total_ms'] = (int) ((microtime(true) - $perfStart) * 1000);

        log_message('info', sprintf(
            'Ptm::save OK id=%s scope=%s sections=%d enum=%dms resolve=%dms conflict=%dms write=%dms counter_seed=%dms total=%dms',
            $id, $audienceScope, $perf['section_count'] ?? 0,
            $perf['enumerate_ms']     ?? 0, $perf['resolve_ms']    ?? 0,
            $perf['conflict_ms']      ?? 0, $perf['write_ms']      ?? 0,
            $perf['counter_seed_ms']  ?? 0, $perf['total_ms']      ?? 0
        ));

        // Phase-D: FCM dispatch moved out of the request lifecycle. A new
        // Cloud Function (`onPtmCreated`) watches `ptmEvents/{id}` create
        // events and emits the parent + class-teacher pushRequests. The
        // synchronous _send_ptm_notification() call has been removed —
        // admin save returns as soon as the doc is committed (~1–2 s)
        // instead of waiting on 3–4 extra Firestore writes (~1–2 s saved).
        //
        // The CF deploy is the load-bearing part of this perf pass; if it
        // hasn't been deployed yet, no FCM goes out for new PTMs. Until
        // that's done, see deployment notes in PHASE_D_CHECKLIST.
        $warnings = [];

        $this->json_success([
            'ptmEventId' => $id,
            'created'    => $isNew,
            'warnings'   => $warnings,
            'sections'   => $resolvedSections,
            'timing'     => $perf ?? null,
        ]);
    }

    /**
     * Seed `ptmCounters/{schoolId}_{ptmEventId}_{sectionKey}` with
     * `nextQueue: 0` for every resolved section. Doc id format mirrors the
     * parent app's `applyToPtm` transaction (sectionKey "/" → "_") so the
     * IDs line up exactly. Uses firestoreCreate (create-if-not-exists), so:
     *   - On a fresh PTM: every counter is created.
     *   - On edit: untouched counters keep their real nextQueue.
     *   - On edit that adds a new section: the new section gets a seeded
     *     counter while existing ones are left intact.
     * Failures are logged but do NOT fail the save — the parent app's
     * transaction still works without a pre-seeded doc, just with an
     * extra retry round under racey first-applies.
     */
    private function _seed_ptm_counters(string $ptmEventId, array $resolvedSections): void
    {
        if ($ptmEventId === '' || empty($resolvedSections)) return;
        $schoolId = $this->school_name;
        if ($schoolId === '') return;
        $now = round(microtime(true) * 1000);
        $created = 0; $skipped = 0;
        foreach ($resolvedSections as $sec) {
            $sectionKey = (string) ($sec['sectionKey'] ?? '');
            if ($sectionKey === '') continue;
            $docId = "{$schoolId}_{$ptmEventId}_" . str_replace('/', '_', $sectionKey);
            try {
                $ok = $this->firebase->firestoreCreate('ptmCounters', $docId, [
                    'ptmEventId' => $ptmEventId,
                    'schoolId'   => $schoolId,
                    'sectionKey' => $sectionKey,
                    'nextQueue'  => 0,
                    'createdAt'  => $now,
                    'updatedAt'  => $now,
                ]);
                if ($ok) { $created++; } else { $skipped++; }
            } catch (\Exception $e) {
                log_message('warning', "Ptm::_seed_ptm_counters create failed docId={$docId}: " . $e->getMessage());
            }
        }
        log_message('info', "Ptm::_seed_ptm_counters ptm={$ptmEventId} created={$created} skipped={$skipped}");
    }

    /**
     * Diff old vs new slot arrays and return a list of human-readable
     * change descriptions that would invalidate or shift existing RSVPs.
     * Returns empty array when no destructive change is detected.
     *
     * Detection rules (anything that breaks the slotIndex → slot meaning):
     *  - Slot count reduced
     *  - At any overlapping index: teacherId changed
     *  - At any overlapping index: startTime or endTime shifted
     */
    private function _describe_destructive_changes(array $oldSlots, array $newSlots): array
    {
        $changes = [];
        $oldN = count($oldSlots);
        $newN = count($newSlots);
        if ($newN < $oldN) {
            $changes[] = "Slot count reduced from {$oldN} to {$newN}";
        }
        $cmpN = min($oldN, $newN);
        for ($i = 0; $i < $cmpN; $i++) {
            $a = is_array($oldSlots[$i]) ? $oldSlots[$i] : [];
            $b = is_array($newSlots[$i]) ? $newSlots[$i] : [];
            $aTid = (string) ($a['teacherId']  ?? '');
            $bTid = (string) ($b['teacherId']  ?? '');
            if ($aTid !== $bTid) {
                $aName = $this->_resolve_teacher_name($aTid, (string) ($a['teacherName'] ?? ''));
                $bName = $this->_resolve_teacher_name($bTid, (string) ($b['teacherName'] ?? ''));
                $changes[] = "Slot " . ($i + 1) . " teacher changed: {$aName} → {$bName}";
            }
            $aStart = (string) ($a['startTime'] ?? '');
            $bStart = (string) ($b['startTime'] ?? '');
            $aEnd   = (string) ($a['endTime']   ?? '');
            $bEnd   = (string) ($b['endTime']   ?? '');
            if ($aStart !== $bStart || $aEnd !== $bEnd) {
                $changes[] = sprintf(
                    "Slot %d time changed: %s–%s → %s–%s",
                    $i + 1, $aStart, $aEnd, $bStart, $bEnd
                );
            }
        }
        return $changes;
    }

    /**
     * Cheap RSVP count for a PTM. Used only on the destructive-change path
     * so the cost is bounded to the rare slow case.
     */
    private function _count_rsvps(string $ptmEventId): int
    {
        try {
            $rows = $this->firebase->firestoreQuery(self::COL_RSVPS, [
                ['schoolId',   '==', $this->school_name],
                ['ptmEventId', '==', $ptmEventId],
            ]);
            return is_array($rows) ? count($rows) : 0;
        } catch (\Exception $e) {
            log_message('error', "Ptm::_count_rsvps failed for {$ptmEventId}: " . $e->getMessage());
            // On count failure, conservatively treat as "RSVPs may exist"
            // so the destructive-change confirmation still fires — the
            // admin can re-confirm intent rather than silently overwriting.
            return -1;
        }
    }

    /**
     * Emit a structured 409 response that the create-form recognizes as
     * "show the destructive-change confirm dialog and retry with the flag
     * set". We bypass json_error() because we need the extra payload
     * fields (code, destructiveChanges, affectedRsvps).
     */
    private function _emit_confirm_required(array $changes, int $rsvpCount): void
    {
        $count = $rsvpCount === -1 ? 'one or more' : (string) $rsvpCount;
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode([
            'status'             => 'error',
            'code'               => 'CONFIRM_REQUIRED',
            'message'            => "This change may affect {$count} existing RSVP" .
                                    ($rsvpCount === 1 ? '' : 's') . '. Re-confirm to proceed.',
            'destructiveChanges' => array_values($changes),
            'affectedRsvps'      => $rsvpCount,
            'csrf_token'         => $this->security->get_csrf_hash(),
        ]);
        exit;
    }

    /**
     * Phase-B destructive-change detector. Phase B PTMs are window-based
     * (no slots), so the diff space is: section list, time window, date,
     * per-section class teacher. Returns human-readable change descriptions;
     * empty when the edit is non-destructive.
     */
    private function _describe_phase_b_changes(
        array $existing, array $newSections,
        string $newStart, string $newEnd, string $newDate
    ): array {
        $changes = [];

        $oldStart = (string) ($existing['startTime'] ?? ($existing['slots'][0]['startTime'] ?? ''));
        $oldEnd   = (string) ($existing['endTime']   ?? '');
        if ($oldEnd === '' && !empty($existing['slots'])) {
            $lastSlot = end($existing['slots']);
            $oldEnd = (string) ($lastSlot['endTime'] ?? '');
        }
        if ($oldStart !== '' && $oldEnd !== '' && ($oldStart !== $newStart || $oldEnd !== $newEnd)) {
            $changes[] = "Time window changed: {$oldStart}–{$oldEnd} → {$newStart}–{$newEnd}";
        }

        $oldDate = (string) ($existing['date'] ?? '');
        if ($oldDate !== '' && $oldDate !== $newDate) {
            $changes[] = "Date changed: {$oldDate} → {$newDate}";
        }

        $oldKeys = [];
        if (!empty($existing['sections']) && is_array($existing['sections'])) {
            foreach ($existing['sections'] as $os) {
                $key = (string) ($os['sectionKey'] ?? '');
                if ($key !== '') $oldKeys[] = $key;
            }
        } else {
            // Legacy single-section fallback — derive from sectionKey
            $oldKeys[] = (string) ($existing['sectionKey'] ?? 'ALL');
        }
        $newKeys = array_map(fn($s) => (string) $s['sectionKey'], $newSections);
        sort($oldKeys);
        sort($newKeys);
        if ($oldKeys !== $newKeys) {
            $removed = array_values(array_diff($oldKeys, $newKeys));
            $added   = array_values(array_diff($newKeys, $oldKeys));
            if (!empty($removed)) $changes[] = "Sections removed: " . implode(', ', $removed);
            if (!empty($added))   $changes[] = "Sections added: "   . implode(', ', $added);
        }

        // Per-section class-teacher reassignment
        $newTeacherBySection = [];
        foreach ($newSections as $ns) {
            $newTeacherBySection[(string) $ns['sectionKey']] = (string) $ns['classTeacherId'];
        }
        if (!empty($existing['sections']) && is_array($existing['sections'])) {
            foreach ($existing['sections'] as $os) {
                $key = (string) ($os['sectionKey'] ?? '');
                $oldTid = (string) ($os['classTeacherId'] ?? '');
                if (!isset($newTeacherBySection[$key])) continue;
                $newTid = $newTeacherBySection[$key];
                if ($oldTid !== '' && $newTid !== '' && $oldTid !== $newTid) {
                    $oldName = $this->_resolve_teacher_name($oldTid, (string) ($os['classTeacherName'] ?? ''));
                    $newName = '';
                    foreach ($newSections as $ns) {
                        if ((string) $ns['sectionKey'] === $key) {
                            $newName = (string) $ns['classTeacherName'];
                            break;
                        }
                    }
                    $changes[] = "Class teacher of {$key} changed: {$oldName} → {$newName}";
                }
            }
        }

        return $changes;
    }

    /**
     * Same-day-PTM query with one retry on transient Firestore failure.
     * Returns rows on success; emits 503 + exits on persistent failure.
     * Cross-PTM check is safety-critical — never silently passes through.
     */
    private function _query_same_day_ptms_or_fail(string $date): array
    {
        $rows = null;
        $err  = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $rows = $this->firebase->firestoreQuery(self::COL_PTMS, [
                    ['schoolId', '==', $this->school_name],
                    ['date',     '==', $date],
                    ['status',   '==', 'scheduled'],
                ]);
                $err = null;
                break;
            } catch (\Exception $e) {
                $err = $e;
                log_message('error', sprintf(
                    'Ptm::save same-day query failed (attempt %d): %s',
                    $attempt + 1, $e->getMessage()
                ));
                usleep(150000);
            }
        }
        if ($err !== null || $rows === null) {
            $this->json_error(
                'Could not verify cross-PTM scheduling conflicts. Please retry in a moment.',
                503
            );
        }
        return (array) $rows;
    }

    /**
     * Mirrors Communication::save_notice — writes a `notices` Firestore doc
     * and enqueues `pushRequests` docs so the existing Cloud Function
     * dispatches FCM. For class-specific PTMs we emit TWO pushRequests
     * because the CF's rolesForTarget() maps "Class …" target groups to
     * parents only. School-wide PTMs use a single "All School" enqueue
     * which the CF already routes to both roles.
     *
     * @return string[]  Non-fatal warnings to surface in the response.
     */
    private function _send_ptm_notification(string $ptmId, array $data): array
    {
        $warnings = [];
        try {
            // 1. Notice doc — parent/teacher apps render this in the notices
            //    inbox. We reuse Communication_helper::write_event_notice()
            //    to keep counter + format consistent with Events.php.
            $this->load->library('communication_helper');
            $this->communication_helper->init(
                $this->firebase, $this->school_name, $this->session_year,
                $this->parent_db_key, $this->fs, $this->school_id
            );
            $title    = (string) ($data['title']       ?? 'Parent-Teacher Meeting');
            $location = (string) ($data['location']    ?? '');
            $date     = (string) ($data['date']        ?? '');
            $desc     = trim((string) ($data['description'] ?? ''));
            $bodyParts = [];
            if ($desc !== '') $bodyParts[] = $desc;
            if ($date !== '') $bodyParts[] = "Date: {$date}";
            if ($location !== '') $bodyParts[] = "Venue: {$location}";
            $slots = $data['slots'] ?? [];
            if (is_array($slots) && count($slots) > 0) {
                $first = $slots[0];
                $last  = end($slots);
                $window = trim((string)($first['startTime'] ?? '')) . '–' . trim((string)($last['endTime'] ?? ''));
                if ($window !== '–') $bodyParts[] = "Time: {$window}";
            }
            $bodyText = implode("\n", $bodyParts);

            $noticeId = $this->communication_helper->write_event_notice(
                $ptmId,
                [
                    'title'       => "[PTM] " . $title,
                    'description' => $bodyText,
                    'category'    => 'meeting',
                    'startDate'   => $date,
                    'endDate'     => $date,
                    'location'    => $location,
                    'organizer'   => '',
                ],
                $this->admin_id
            );
            if ($noticeId === '') {
                $warnings[] = 'Notice doc could not be written; mobile inbox will not show this PTM.';
                log_message('error', "Ptm::_send_ptm_notification: notice write returned empty id for {$ptmId}");
                return $warnings;
            }

            // 2. Decide audience targets. Push payloads identical, only
            //    `target_group` differs.
            $sectionKey  = (string) ($data['sectionKey'] ?? 'ALL');
            $isAllSchool = ($sectionKey === 'ALL' || $sectionKey === '');
            $titleFull   = "[PTM] " . $title;
            $bodyShort   = mb_substr(strip_tags($bodyText), 0, 140);

            // ── Parents push ──────────────────────────────────────────
            // For specific-section PTMs, target the section's parents only.
            // For all-school PTMs, target the parent role across the school.
            // (Switched from "All School" to "All Parents" for all-school
            // PTMs in Phase D so we don't double-deliver to teachers via
            // both this push and the per-staffId push below.)
            $parentTarget = $isAllSchool ? 'All Parents' : str_replace('/', '|', $sectionKey);
            $okP = $this->_enqueue_ptm_push($ptmId, 'parents', $noticeId, $titleFull, $bodyShort, $parentTarget);
            if (!$okP) $warnings[] = 'Push to parents failed; they may not receive a notification.';

            // ── Class-teacher push (Phase D — replaces "All Teachers") ─
            // Collect the specific class teacher staffIds from the PTM's
            // sections snapshot. Each teacher gets the push exactly once,
            // even if they're listed in multiple sections.
            $staffIds = [];
            foreach ((array) ($data['sections'] ?? []) as $sec) {
                $tid = trim((string) ($sec['classTeacherId'] ?? ''));
                if ($tid !== '') $staffIds[] = $tid;
            }
            $staffIds = array_values(array_unique($staffIds));
            if (!empty($staffIds)) {
                $okT = $this->_enqueue_ptm_class_teacher_push(
                    $ptmId, '', $noticeId, $titleFull, $bodyShort, $staffIds
                );
                if (!$okT) $warnings[] = 'Per-class-teacher push failed; assigned teachers may not be notified in real-time.';
            } else {
                // No class teachers resolved (legacy PTM without sections[],
                // or all sections missing classTeacherId — admin-side
                // validation should have caught this on save).
                log_message('warning', "Ptm::_send_ptm_notification: no class teachers to notify for {$ptmId}");
            }
        } catch (\Exception $e) {
            log_message('error', 'Ptm::_send_ptm_notification failed: ' . $e->getMessage());
            $warnings[] = 'Notification subsystem error: ' . $e->getMessage();
        }
        return $warnings;
    }

    /**
     * Phase-D per-classTeacher push. Emits one `pushRequests` doc with
     * `mark: 'PTM_CLASS_TEACHER'` and the list of staff userIds the CF
     * should target. The CF resolves staff → device tokens via
     * `userDevices.userId in [...]`; only those teachers' devices receive
     * the push.
     *
     * Replaces the legacy "All Teachers" target_group overshoot for
     * class-specific PTMs.
     *
     * @param string[] $staffIds Class-teacher staffIds from sections[].
     */
    private function _enqueue_ptm_class_teacher_push(
        string $ptmId, string $reqIdSuffix, string $noticeId,
        string $title, string $body, array $staffIds
    ): bool {
        if (empty($staffIds)) return true; // nothing to do — caller's responsibility
        try {
            $reqSeed = $reqIdSuffix === ''
                ? "ptm_classteacher_{$ptmId}"
                : "ptm_classteacher_{$ptmId}_{$reqIdSuffix}";
            $reqId = $this->fs->docId($reqSeed);
            $payload = [
                'schoolId'           => $this->school_id ?: $this->school_name,
                'mark'               => 'PTM_CLASS_TEACHER',
                'source'             => 'ptm_class_teacher' . ($reqIdSuffix !== '' ? "_{$reqIdSuffix}" : ''),
                'status'             => 'pending',
                'noticeId'           => $noticeId,
                'ptmEventId'         => $ptmId,
                'title'              => $title,
                'body'               => $body,
                'category'           => 'meeting',
                'priority'           => 'Normal',
                'recipientStaffIds'  => array_values($staffIds),
                'markedBy'           => $this->admin_id,
                'createdAt'          => date('c'),
            ];
            $ok = $this->fs->set('pushRequests', $reqId, $payload, /* merge */ false);
            if (!$ok) {
                log_message('error', "Ptm::_enqueue_ptm_class_teacher_push: fs->set returned false for {$reqId} (n=" . count($staffIds) . ")");
                return false;
            }
            return true;
        } catch (\Exception $e) {
            log_message('error', "Ptm::_enqueue_ptm_class_teacher_push failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Emit one `pushRequests` doc. Returns false on any failure (logged).
     * The CF listens on this collection and dispatches FCM.
     */
    private function _enqueue_ptm_push(
        string $ptmId, string $reqIdSuffix, string $noticeId,
        string $title, string $body, string $targetGroup
    ): bool {
        try {
            $reqSeed = $reqIdSuffix === '' ? "ptm_created_{$ptmId}" : "ptm_created_{$ptmId}_{$reqIdSuffix}";
            $reqId   = $this->fs->docId($reqSeed);
            $payload = [
                'schoolId'     => $this->school_id ?: $this->school_name,
                'mark'         => 'NOTICE_CREATED',
                'source'       => 'ptm_created',
                'status'       => 'pending',
                'noticeId'     => $noticeId,
                'ptmEventId'   => $ptmId,
                'title'        => $title,
                'body'         => $body,
                'category'     => 'meeting',
                'priority'     => 'Normal',
                'target_group' => $targetGroup,
                'markedBy'     => $this->admin_id,
                'createdAt'    => date('c'),
            ];
            $ok = $this->fs->set('pushRequests', $reqId, $payload, /* merge */ false);
            if (!$ok) {
                log_message('error', "Ptm::_enqueue_ptm_push: fs->set returned false for {$reqId} (target={$targetGroup})");
                return false;
            }
            return true;
        } catch (\Exception $e) {
            log_message('error', "Ptm::_enqueue_ptm_push failed for target={$targetGroup}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Transition a PTM's status. Allowed:
     *   scheduled → completed | cancelled
     *   completed → scheduled              (re-open after accidental click)
     *   cancelled → scheduled              (re-open after accidental click)
     * Transitions are no-ops when the new status equals the current one.
     */
    public function set_status(string $ptmEventId = '')
    {
        $this->_require_admin();
        if ($this->input->method() !== 'post') {
            $this->json_error('POST required.', 405);
        }
        if ($ptmEventId === '') $this->json_error('PTM id required.');

        $newStatus = trim((string) $this->input->post('status'));
        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            $this->json_error('Invalid status.');
        }

        $existing = $this->fs->getEntity(self::COL_PTMS, $ptmEventId);
        if (!is_array($existing)) $this->json_error('PTM not found.', 404);

        $cur = (string) ($existing['status'] ?? 'scheduled');
        if ($cur === $newStatus) {
            $this->json_success(['ptmEventId' => $ptmEventId, 'status' => $cur, 'unchanged' => true]);
        }

        $allowed = [
            'scheduled' => ['completed', 'cancelled'],
            'completed' => ['scheduled'],
            'cancelled' => ['scheduled'],
        ];
        if (!isset($allowed[$cur]) || !in_array($newStatus, $allowed[$cur], true)) {
            $this->json_error("Cannot transition PTM from {$cur} to {$newStatus}.");
        }

        try {
            $this->fs->setEntity(self::COL_PTMS, $ptmEventId, [
                'status'    => $newStatus,
                'updatedAt' => round(microtime(true) * 1000),
                'updatedBy' => $this->admin_id,
            ], /* merge */ true);
        } catch (\Exception $e) {
            log_message('error', 'Ptm::set_status failed: ' . $e->getMessage());
            $this->json_error('Failed to update status.');
        }

        // Cancellation push — parents who already RSVPd would otherwise
        // discover the cancellation only on next app open (their PTM filter
        // hides non-scheduled PTMs). Reuse the create-time pipeline so the
        // notice doc + FCM both land via the existing CF.
        $warnings = [];
        if ($newStatus === 'cancelled') {
            $warnings = $this->_send_ptm_cancellation_notification($ptmEventId, $existing);
        }

        $this->json_success([
            'ptmEventId' => $ptmEventId,
            'status'     => $newStatus,
            'warnings'   => $warnings,
        ]);
    }

    /**
     * Cancellation counterpart to _send_ptm_notification(). Title is
     * prefixed "[PTM CANCELLED]"; body explicitly tells the recipient the
     * meeting won't take place. Audience targeting matches creation so
     * the same parents/teachers who got the original push hear the news.
     *
     * @return string[]  Non-fatal warnings to surface in the response.
     */
    private function _send_ptm_cancellation_notification(string $ptmId, array $data): array
    {
        $warnings = [];
        try {
            $this->load->library('communication_helper');
            $this->communication_helper->init(
                $this->firebase, $this->school_name, $this->session_year,
                $this->parent_db_key, $this->fs, $this->school_id
            );
            $title    = (string) ($data['title']       ?? 'Parent-Teacher Meeting');
            $location = (string) ($data['location']    ?? '');
            $date     = (string) ($data['date']        ?? '');
            $bodyParts = ['This Parent-Teacher Meeting has been CANCELLED.'];
            if ($date !== '')     $bodyParts[] = "Original date: {$date}";
            if ($location !== '') $bodyParts[] = "Venue: {$location}";
            $bodyParts[] = 'No action needed; any prior RSVP is no longer applicable.';
            $bodyText = implode("\n", $bodyParts);

            $noticeId = $this->communication_helper->write_event_notice(
                $ptmId,
                [
                    'title'       => "[PTM CANCELLED] " . $title,
                    'description' => $bodyText,
                    'category'    => 'meeting',
                    'startDate'   => $date,
                    'endDate'     => $date,
                    'location'    => $location,
                    'organizer'   => '',
                ],
                $this->admin_id
            );
            if ($noticeId === '') {
                $warnings[] = 'Cancellation notice could not be written; affected parents may not see it in their notices list.';
                log_message('error', "Ptm::_send_ptm_cancellation_notification: notice write returned empty id for {$ptmId}");
                return $warnings;
            }

            $sectionKey  = (string) ($data['sectionKey'] ?? 'ALL');
            $isAllSchool = ($sectionKey === 'ALL' || $sectionKey === '');
            $titleFull   = "[PTM CANCELLED] " . $title;
            $bodyShort   = mb_substr(strip_tags($bodyText), 0, 140);

            // Parents — same scoping as creation push.
            $parentTarget = $isAllSchool ? 'All Parents' : str_replace('/', '|', $sectionKey);
            $okP = $this->_enqueue_ptm_push($ptmId, 'cancel_parents', $noticeId, $titleFull, $bodyShort, $parentTarget);
            if (!$okP) $warnings[] = 'Cancellation push to parents failed.';

            // Teachers — Phase-D per-staffId targeting, never "All Teachers".
            $staffIds = [];
            foreach ((array) ($data['sections'] ?? []) as $sec) {
                $tid = trim((string) ($sec['classTeacherId'] ?? ''));
                if ($tid !== '') $staffIds[] = $tid;
            }
            $staffIds = array_values(array_unique($staffIds));
            if (!empty($staffIds)) {
                $okT = $this->_enqueue_ptm_class_teacher_push(
                    $ptmId, 'cancel', $noticeId, $titleFull, $bodyShort, $staffIds
                );
                if (!$okT) $warnings[] = 'Cancellation push to assigned class teachers failed.';
            }
        } catch (\Exception $e) {
            log_message('error', 'Ptm::_send_ptm_cancellation_notification failed: ' . $e->getMessage());
            $warnings[] = 'Cancellation notification subsystem error: ' . $e->getMessage();
        }
        return $warnings;
    }

    public function delete(string $ptmEventId = '')
    {
        $this->_require_admin();
        if ($this->input->method() !== 'post') {
            $this->json_error('POST required.', 405);
        }
        if ($ptmEventId === '') $this->json_error('PTM id required.');

        try {
            $this->fs->remove(self::COL_PTMS, $ptmEventId);
            // Best-effort: cascade delete RSVPs.
            $rsvps = $this->firebase->firestoreQuery(self::COL_RSVPS, [
                ['schoolId',   '==', $this->school_name],
                ['ptmEventId', '==', $ptmEventId],
            ]);
            $rsvpDeleted = 0;
            foreach ((array) $rsvps as $r) {
                $sid = (string) ($r['studentId'] ?? '');
                if ($sid !== '') {
                    try {
                        $this->fs->remove(self::COL_RSVPS, $this->_rsvp_doc_id($ptmEventId, $sid));
                        $rsvpDeleted++;
                    } catch (\Exception $e) {
                        log_message('warning', "Ptm::delete RSVP cascade failed ptm={$ptmEventId} student={$sid}: " . $e->getMessage());
                    }
                }
            }

            // Cascade delete per-section queue counters. Without this, a
            // re-created PTM that happens to reuse the same id (or any
            // school-wide audit) would see ghost counters from the deleted
            // PTM. The query is scoped by schoolId+ptmEventId so we never
            // touch counters from other PTMs.
            $counterDeleted = 0;
            try {
                $counters = $this->firebase->firestoreQuery('ptmCounters', [
                    ['schoolId',   '==', $this->school_name],
                    ['ptmEventId', '==', $ptmEventId],
                ]);
                foreach ((array) $counters as $c) {
                    $cid = (string) ($c['id'] ?? ($c['_id'] ?? ''));
                    if ($cid === '') continue;
                    try {
                        $this->firebase->firestoreDelete('ptmCounters', $cid);
                        $counterDeleted++;
                    } catch (\Exception $e) {
                        log_message('warning', "Ptm::delete counter cascade failed ptm={$ptmEventId} counter={$cid}: " . $e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                log_message('warning', "Ptm::delete counter query failed ptm={$ptmEventId}: " . $e->getMessage());
            }

            log_message('info', "Ptm::delete OK ptm={$ptmEventId} rsvps={$rsvpDeleted} counters={$counterDeleted}");
        } catch (\Exception $e) {
            log_message('error', 'Ptm::delete failed: ' . $e->getMessage());
            $this->json_error('Failed to delete PTM.');
        }

        $this->json_success(['deleted' => true]);
    }

    // ─── JSON: list RSVPs for a PTM ─────────────────────────────────────

    public function get_rsvps(string $ptmEventId = '')
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
        if ($ptmEventId === '') $this->json_error('PTM id required.');

        $rsvps = $this->firebase->firestoreQuery(self::COL_RSVPS, [
            ['schoolId',   '==', $this->school_name],
            ['ptmEventId', '==', $ptmEventId],
        ], 'respondedAt', 'DESC');

        $list = [];
        foreach ((array) $rsvps as $r) {
            $row = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            $list[] = $row;
        }
        $this->json_success(['rsvps' => $list, 'total' => count($list)]);
    }

    // ─── JSON: one-time legacy-RSVP backfill ────────────────────────────
    //
    // Backfills `teacherId` / `teacherName` on `ptmRsvps` docs that were
    // written by pre-Phase-C parent app builds. Those docs have either
    // teacherId="" or teacherId="__SECTIONWISE__" (the legacy slot-mirror
    // sentinel), which makes them invisible to the new teacher app's
    // `teacherId == staffId` filter. The teacher app currently ships a
    // TEMP-LEGACY-RSVP-FALLBACK that surfaces them via sectionKey match;
    // running this migration once the parent fleet has rolled forward
    // canonicalises the data and lets us delete that fallback.
    //
    // Resolution: for each target RSVP, look up the matching section in
    // `ptmEvents/{ptmEventId}.sections[]` by sectionKey and copy
    // classTeacherId / classTeacherName onto the RSVP. RSVPs written for
    // an all-school PTM (sectionKey="ALL" / blank) fall back to the
    // RSVP's className/section pair to find the right section assignment.
    //
    // Idempotent — re-running scopes by `teacherId == ""` /
    // `"__SECTIONWISE__"` so already-backfilled rows fall out of the
    // result set automatically. Defensive guards inside the loop also
    // skip docs whose teacherId no longer matches the legacy values.
    //
    // Scope: same school as the calling admin. Run once per school.
    //
    // Body params (POST, all optional):
    //   • dryRun  bool — count only, do not write. Defaults to false.
    //   • limit   int  — cap UPDATES (not scans) at this many. Default 500,
    //                    hard cap 2000 per call. If `hit_limit` comes back
    //                    true, re-run to continue.
    public function backfill_legacy_teacher_ids()
    {
        $this->_require_admin();
        if ($this->input->method() !== 'post') {
            $this->json_error('POST required.', 405);
        }

        $tStart = microtime(true);

        $dryRun     = filter_var($this->input->post('dryRun'), FILTER_VALIDATE_BOOLEAN);
        $maxUpdates = (int) ($this->input->post('limit') ?? 500);
        if ($maxUpdates < 1)    $maxUpdates = 500;
        if ($maxUpdates > 2000) $maxUpdates = 2000;

        $scanned          = 0;
        $updated          = 0;
        $skippedNoMatch   = 0;
        $skippedAlreadyOk = 0;
        $errors           = 0;
        $hitLimit         = false;

        // Per-request PTM doc cache — many RSVPs share the same PTM, so
        // caching the doc lookup turns N round-trips into ~(unique ptms).
        $ptmCache = [];

        // Firestore has no native OR — run two scoped queries (blank +
        // sentinel) and dedupe by RSVP doc id.
        $targets = [];
        $seen    = [];
        foreach (['', '__SECTIONWISE__'] as $needle) {
            try {
                $rows = $this->firebase->firestoreQuery(self::COL_RSVPS, [
                    ['schoolId',  '==', $this->school_name],
                    ['teacherId', '==', $needle],
                ]);
                foreach ((array) $rows as $r) {
                    $rid = (string) ($r['id'] ?? ($r['_id'] ?? ''));
                    if ($rid === '' || isset($seen[$rid])) continue;
                    $seen[$rid] = true;
                    $targets[] = $r;
                }
            } catch (\Exception $e) {
                log_message('error', "Ptm::backfill_legacy query failed (needle='{$needle}'): " . $e->getMessage());
                $errors++;
            }
        }

        foreach ($targets as $r) {
            if ($updated >= $maxUpdates) {
                $hitLimit = true;
                break;
            }
            $scanned++;

            $rsvpData  = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            $rsvpDocId = (string) ($r['id'] ?? ($r['_id'] ?? ''));
            if ($rsvpDocId === '') {
                $skippedNoMatch++;
                continue;
            }

            $ptmEventId       = (string) ($rsvpData['ptmEventId'] ?? '');
            $sectionKey       = (string) ($rsvpData['sectionKey'] ?? '');
            $currentTeacherId = (string) ($rsvpData['teacherId']  ?? '');

            // Idempotence guard — query already filtered, but guard
            // against a doc that was updated mid-run.
            if (!in_array($currentTeacherId, ['', '__SECTIONWISE__'], true)) {
                $skippedAlreadyOk++;
                continue;
            }
            if ($ptmEventId === '') {
                $skippedNoMatch++;
                continue;
            }

            // Resolve which section assignment to copy onto this RSVP.
            // Phase-C / Phase-B PTMs store the canonical sectionKey; legacy
            // all-school PTMs stored "ALL" on the RSVP, so fall back to
            // the parent's className/section pair to look up the right
            // section in the PTM's sections[] snapshot.
            $lookupKey = $sectionKey;
            if ($lookupKey === '' || strcasecmp($lookupKey, 'ALL') === 0) {
                $cn = trim((string) ($rsvpData['className'] ?? ''));
                $sc = trim((string) ($rsvpData['section']   ?? ''));
                if ($cn !== '' && $sc !== '') {
                    $lookupKey = "{$cn}/{$sc}";
                }
            }
            if ($lookupKey === '' || strcasecmp($lookupKey, 'ALL') === 0) {
                $skippedNoMatch++;
                continue;
            }

            // Fetch (or cache-hit) the PTM doc.
            if (!array_key_exists($ptmEventId, $ptmCache)) {
                try {
                    $doc = $this->fs->getEntity(self::COL_PTMS, $ptmEventId);
                    $ptmCache[$ptmEventId] = is_array($doc) ? $doc : null;
                } catch (\Exception $e) {
                    log_message('error', "Ptm::backfill_legacy ptm read failed ptm={$ptmEventId}: " . $e->getMessage());
                    $ptmCache[$ptmEventId] = null;
                }
            }
            $ptmDoc = $ptmCache[$ptmEventId];

            if (!is_array($ptmDoc) || empty($ptmDoc['sections']) || !is_array($ptmDoc['sections'])) {
                $skippedNoMatch++;
                continue;
            }

            $newTeacherId   = '';
            $newTeacherName = '';
            foreach ($ptmDoc['sections'] as $sec) {
                if (!is_array($sec)) continue;
                $secKey = (string) ($sec['sectionKey'] ?? '');
                if ($secKey === $lookupKey) {
                    $newTeacherId   = trim((string) ($sec['classTeacherId']   ?? ''));
                    $newTeacherName = trim((string) ($sec['classTeacherName'] ?? ''));
                    break;
                }
            }

            if ($newTeacherId === '') {
                // Either no matching section, or the section exists but
                // its classTeacherId is also blank — nothing safe to copy.
                $skippedNoMatch++;
                continue;
            }

            if ($dryRun) {
                $updated++;
                continue;
            }

            try {
                $now = round(microtime(true) * 1000);
                $this->firebase->firestoreSet(self::COL_RSVPS, $rsvpDocId, [
                    'teacherId'    => $newTeacherId,
                    'teacherName'  => $newTeacherName,
                    'updatedAt'    => $now,
                    'backfilledAt' => $now,
                    'backfilledBy' => $this->admin_id,
                ], /* merge */ true);
                $updated++;
            } catch (\Exception $e) {
                log_message('warning', "Ptm::backfill_legacy update failed rsvp={$rsvpDocId}: " . $e->getMessage());
                $errors++;
            }
        }

        $elapsedMs = (int) ((microtime(true) - $tStart) * 1000);

        log_message('info', sprintf(
            'Ptm::backfill_legacy_teacher_ids school=%s scanned=%d updated=%d skipped_no_match=%d skipped_already_ok=%d errors=%d dry_run=%d elapsed=%dms targets=%d',
            $this->school_name, $scanned, $updated, $skippedNoMatch, $skippedAlreadyOk, $errors,
            $dryRun ? 1 : 0, $elapsedMs, count($targets)
        ));

        $this->json_success([
            'scanned'             => $scanned,
            'updated'             => $updated,
            'skipped_no_match'    => $skippedNoMatch,
            'skipped_already_ok'  => $skippedAlreadyOk,
            'errors'              => $errors,
            'dry_run'             => $dryRun,
            'hit_limit'           => $hitLimit,
            'elapsed_ms'          => $elapsedMs,
            'total_targets_found' => count($targets),
        ]);
    }

    // ─── JSON: list of teachers for the slot picker ─────────────────────

    public function get_teachers()
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }

        // Same dual-id strategy as get_sections: writers populate `schoolId`
        // with either school_name or school_id depending on the controller,
        // so we try both before giving up.
        $list = $this->_load_staff_for($this->school_name);
        if (count($list) === 0 && $this->school_id !== '' && $this->school_id !== $this->school_name) {
            $list = $this->_load_staff_for($this->school_id);
        }
        $this->json_success(['teachers' => $list]);
    }

    private function _load_staff_for(string $schoolId): array
    {
        if ($schoolId === '') return [];
        try {
            $rows = $this->firebase->firestoreQuery('staff',
                [['schoolId', '==', $schoolId]], 'name', 'ASC');
        } catch (\Exception $e) {
            log_message('error', "Ptm::get_teachers staff query failed: " . $e->getMessage());
            return [];
        }
        $list = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $sid  = (string) ($d['staffId']  ?? $d['teacherId'] ?? ($r['id'] ?? ''));
            $name = (string) ($d['name']     ?? $d['Name']      ?? '');
            if ($sid === '' || $name === '') continue;

            // Only people who would realistically host a parent meeting:
            // teachers, coordinators, principals, vice-principals. Empty
            // role (legacy docs) is allowed through so unclassified staff
            // aren't silently dropped.
            $role = strtolower((string) ($d['role'] ?? $d['jobFunction'] ?? ''));
            if ($role !== ''
                && strpos($role, 'teach')      === false
                && strpos($role, 'coord')      === false
                && strpos($role, 'principal')  === false
                && strpos($role, 'head')       === false) {
                continue;
            }
            $list[] = [
                'id'   => $sid,
                'name' => $name,
                'role' => (string) ($d['role'] ?? $d['jobFunction'] ?? ''),
            ];
        }
        return $list;
    }

    // ─── JSON: list of class/section options ────────────────────────────

    /**
     * Returns the canonical class/section choices from the `sections`
     * collection. The form uses these to build a dropdown so admins
     * cannot type a value that fails to match the parent app's
     * normalized `sectionKey` ("Class 9th/Section A").
     */
    /**
     * Returns the subject teachers + class teacher for a given class/section,
     * sourced from the canonical `subjectAssignments` collection (per
     * subject_assignments_architecture memory). The create form calls this
     * to auto-generate one slot per teacher — admin no longer has to type
     * each teacher manually.
     *
     * Response shape:
     *   teachers: [
     *     { teacherId, teacherName, subject: "Mathematics", role: "Subject Teacher" },
     *     { teacherId, teacherName, subject: "",            role: "Class Teacher" },
     *     ...
     *   ]
     */
    public function get_class_teachers()
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
        $className = trim((string) $this->input->get('className'));
        $section   = trim((string) $this->input->get('section'));
        if ($className === '' || $section === '') {
            $this->json_error('className and section are required.');
        }

        $this->load->library('subject_assignment_service', null, 'sas');
        $this->sas->init($this->fs, $this->firebase, $this->school_id, $this->school_name, $this->session_year);

        $assignments = $this->sas->getAssignmentsForClass($className, $section);
        $byTeacher = []; // teacherId -> {teacherId, teacherName, subjects[]}
        foreach ((array) $assignments as $a) {
            if (!is_array($a)) continue;
            $tid   = (string) ($a['teacherId']   ?? '');
            $tname = (string) ($a['teacherName'] ?? '');
            $sname = (string) ($a['subjectName'] ?? ($a['subjectCode'] ?? ''));
            if ($tid === '' || $tname === '') continue;
            if (!isset($byTeacher[$tid])) {
                $byTeacher[$tid] = [
                    'teacherId'   => $tid,
                    'teacherName' => $tname,
                    'subjects'    => [],
                ];
            }
            if ($sname !== '') $byTeacher[$tid]['subjects'][] = $sname;
        }

        // Try to identify class teacher from the sections collection.
        $classTeacherId = '';
        try {
            $secRows = $this->firebase->firestoreQuery('sections', [
                ['schoolId',  '==', $this->school_name],
                ['className', '==', $className],
                ['section',   '==', $section],
            ]);
            foreach ((array) $secRows as $r) {
                $d = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                $classTeacherId = (string) ($d['classTeacherId'] ?? $d['class_teacher_id'] ?? '');
                if ($classTeacherId !== '') break;
            }
        } catch (\Exception $_) { /* sections is optional context */ }

        $list = [];
        foreach ($byTeacher as $tid => $row) {
            $list[] = [
                'teacherId'   => $row['teacherId'],
                'teacherName' => $row['teacherName'],
                'subject'     => implode(', ', array_unique($row['subjects'])),
                'role'        => ($tid === $classTeacherId) ? 'Class Teacher' : 'Subject Teacher',
            ];
        }
        // Class teacher first.
        usort($list, function ($a, $b) {
            if ($a['role'] === 'Class Teacher') return -1;
            if ($b['role'] === 'Class Teacher') return  1;
            return strcasecmp($a['teacherName'], $b['teacherName']);
        });

        $this->json_success(['teachers' => $list]);
    }

    public function get_sections()
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }

        // Source 1 — `sections` collection. Some schools provision sections
        // explicitly via Classes.php; for them this returns the canonical
        // list. Try both school_name and school_id since writers in this
        // codebase aren't 100% consistent on which one populates `schoolId`.
        $list = $this->_load_sections_from_collection($this->school_name);
        $source = 'sections_collection';

        if (count($list) === 0 && $this->school_id !== '' && $this->school_id !== $this->school_name) {
            $list = $this->_load_sections_from_collection($this->school_id);
        }

        // Source 2 — fall back to the unique (className, section) pairs
        // observed on `students`. Schools that imported a student roster
        // without ever using the Sections admin page still get a usable
        // dropdown; section labels are normalised so they round-trip
        // correctly into the parent app's `sectionKey` filter.
        if (count($list) === 0) {
            $list = $this->_derive_sections_from_students();
            if (count($list) > 0) $source = 'students_fallback';
        }

        $this->json_success([
            'sections' => $list,
            'source'   => $source,
        ]);
    }

    /** Read the canonical `sections` collection for a given schoolId value. */
    private function _load_sections_from_collection(string $schoolId): array
    {
        if ($schoolId === '') return [];
        try {
            $rows = $this->firebase->firestoreQuery('sections',
                [['schoolId', '==', $schoolId]], 'className', 'ASC');
        } catch (\Exception $e) {
            log_message('error', "Ptm::get_sections collection query failed: " . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $c = trim((string) ($d['className'] ?? ''));
            $s = trim((string) ($d['section']   ?? ''));
            if ($c === '' || $s === '') continue;
            if (stripos($c, 'Class ')   !== 0) $c = "Class {$c}";
            if (stripos($s, 'Section ') !== 0) $s = "Section {$s}";
            $key = "{$c}/{$s}";
            $out[$key] = [
                'className'  => $c,
                'section'    => $s,
                'sectionKey' => $key,
                'label'      => "{$c} · {$s}",
            ];
        }
        return array_values($out);
    }

    /**
     * Walk `students` once and return distinct (className, section) pairs.
     * Used when the sections collection is empty but students exist.
     */
    private function _derive_sections_from_students(): array
    {
        try {
            $rows = $this->firebase->firestoreQuery('students',
                [['schoolId', '==', $this->school_name]], null, 'ASC', 5000);
        } catch (\Exception $e) {
            log_message('error', "Ptm::get_sections students fallback failed: " . $e->getMessage());
            return [];
        }
        $seen = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $c = trim((string) ($d['className'] ?? $d['class']   ?? ''));
            $s = trim((string) ($d['section']   ?? ''));
            if ($c === '' || $s === '') continue;
            if (stripos($c, 'Class ')   !== 0) $c = "Class {$c}";
            if (stripos($s, 'Section ') !== 0) $s = "Section {$s}";
            $key = "{$c}/{$s}";
            if (!isset($seen[$key])) {
                $seen[$key] = [
                    'className'  => $c,
                    'section'    => $s,
                    'sectionKey' => $key,
                    'label'      => "{$c} · {$s}",
                ];
            }
        }
        // Sort by class then section for consistent dropdown ordering.
        $list = array_values($seen);
        usort($list, function ($a, $b) {
            $cmp = strnatcasecmp($a['className'], $b['className']);
            return $cmp !== 0 ? $cmp : strnatcasecmp($a['section'], $b['section']);
        });
        return $list;
    }

    // ════════════════════════════════════════════════════════════════════
    //  PARENT / TEACHER APP API — Firebase ID-token authenticated
    // ════════════════════════════════════════════════════════════════════

    /** Emit a JSON error tagged with a stable rejection code for log-grep. */
    private function _app_reject(string $code, string $message, int $http = 400, array $extra = []): void
    {
        $studentId = (string) ($this->_app_claims['uid'] ?? '');
        log_message('warning', sprintf(
            'Ptm::parent_submit_rsvp REJECT code=%s requester=%s detail=%s',
            $code, $studentId, json_encode($extra)
        ));
        header('Content-Type: application/json');
        http_response_code($http);
        echo json_encode(array_merge([
            'status'  => 'error',
            'code'    => $code,
            'message' => $message,
        ], $extra));
        exit;
    }

    private function _tm_reject(string $code, string $message, int $http = 400, array $extra = []): void
    {
        $staffId = (string) ($this->_app_claims['uid'] ?? '');
        log_message('warning', sprintf(
            'Ptm::teacher_mark_attendance REJECT code=%s requester=%s detail=%s',
            $code, $staffId, json_encode($extra)
        ));
        header('Content-Type: application/json');
        http_response_code($http);
        echo json_encode(array_merge([
            'status'  => 'error',
            'code'    => $code,
            'message' => $message,
        ], $extra));
        exit;
    }

    /** Read the JSON body or fall back to form-encoded POST. */
    private function _read_app_body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw !== '' && $raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return $decoded;
        }
        return $this->input->post() ?: [];
    }

    /**
     * POST /ptm/parent_submit_rsvp/{ptmEventId}
     *
     * Replaces the parent app's previous direct-Firestore RSVP write. All
     * server-side safeguards (capacity, overlap, slot existence, audience
     * match, attendance preservation) live here.
     *
     * Body: { studentId?, bookings: [{ slotIndex, status?, note? }, …] }
     * The token's uid is treated as authoritative for studentId — the body
     * field is accepted for symmetry but must match.
     */
    public function parent_submit_rsvp(string $ptmEventId = '')
    {
        header('Content-Type: application/json; charset=utf-8');

        $claims  = $this->_app_claims;
        $role    = strtolower((string) ($claims['role'] ?? ''));
        $reqUid  = (string) ($claims['uid'] ?? '');
        if ($reqUid === '') $this->_app_reject('AUTH_FAIL', 'Invalid auth token.', 401);
        if (!in_array($role, ['parent', 'student'], true)) {
            $this->_app_reject('AUTH_FAIL', 'Parent/student role required.', 403);
        }
        if ($ptmEventId === '') $this->_app_reject('PTM_NOT_FOUND', 'PTM id required.');

        $body      = $this->_read_app_body();
        $studentId = trim((string) ($body['studentId'] ?? $reqUid));
        $bookings  = is_array($body['bookings'] ?? null) ? $body['bookings'] : [];

        if ($studentId === '' || $studentId !== $reqUid) {
            // The auth model is uid==studentId. Any mismatch is suspicious.
            $this->_app_reject('OWNERSHIP_FAIL', 'Cannot submit RSVP for another student.', 403);
        }
        if (count($bookings) === 0) {
            $this->_app_reject('BOOKINGS_EMPTY', 'Pick at least one slot before submitting.');
        }

        // Load PTM
        $ptm = $this->fs->getEntity(self::COL_PTMS, $ptmEventId);
        if (!is_array($ptm)) $this->_app_reject('PTM_NOT_FOUND', 'PTM not found.', 404);

        $ptmStatus = (string) ($ptm['status'] ?? '');
        if ($ptmStatus !== 'scheduled') {
            $this->_app_reject('PTM_NOT_SCHEDULED', 'This PTM is no longer accepting RSVPs.', 409);
        }
        $today  = date('Y-m-d');
        $nowHm  = date('H:i');
        $ptmDate = (string) ($ptm['date'] ?? '');
        if ($ptmDate !== '' && $ptmDate < $today) {
            $this->_app_reject('PTM_DATE_PAST', 'This PTM has already taken place.', 409);
        }
        $ptmSlots = is_array($ptm['slots'] ?? null) ? array_values($ptm['slots']) : [];
        $slotCount = count($ptmSlots);
        if ($slotCount === 0) {
            $this->_app_reject('SLOT_INDEX_INVALID', 'This PTM has no bookable slots.');
        }

        // Look up student profile to fetch class/section + parent contact
        // info for the RSVP envelope. PHP enriches these — the client never
        // gets to set them.
        $studentDoc = null;
        try {
            $studentDoc = $this->firebase->firestoreGet('students', "{$this->school_name}_{$studentId}");
        } catch (\Exception $_) { /* fall through */ }
        if (!is_array($studentDoc)) {
            $this->_app_reject('OWNERSHIP_FAIL', 'Student profile not found for this account.', 403);
        }
        $studentName = (string) ($studentDoc['name']    ?? $studentDoc['studentName'] ?? '');
        $studentCls  = (string) ($studentDoc['className'] ?? $studentDoc['class']     ?? '');
        $studentSec  = (string) ($studentDoc['section']   ?? '');
        $rollNo      = (string) ($studentDoc['rollNo']    ?? $studentDoc['roll_no']   ?? '');
        $parentName  = (string) ($studentDoc['parentName'] ?? $studentDoc['guardianName'] ?? '');
        $parentPhone = (string) ($studentDoc['parentPhone'] ?? $studentDoc['guardianPhone'] ?? '');
        $parentEmail = (string) ($studentDoc['parentEmail'] ?? $studentDoc['guardianEmail'] ?? '');
        if (stripos($studentCls, 'Class ')   !== 0 && $studentCls !== '') $studentCls = "Class {$studentCls}";
        if (stripos($studentSec, 'Section ') !== 0 && $studentSec !== '') $studentSec = "Section {$studentSec}";

        // Audience match — sectionKey "ALL" passes any student; otherwise
        // student's normalised "Class X/Section Y" must match.
        $ptmSectionKey = (string) ($ptm['sectionKey'] ?? 'ALL');
        if ($ptmSectionKey !== 'ALL' && $ptmSectionKey !== '') {
            $studentKey = "{$studentCls}/{$studentSec}";
            if (strcasecmp($studentKey, $ptmSectionKey) !== 0) {
                $this->_app_reject('AUDIENCE_MISMATCH',
                    'This PTM is not for your class.', 403,
                    ['expected' => $ptmSectionKey, 'student' => $studentKey]);
            }
        }

        // Per-booking validation + server-side enrichment from PTM slots.
        // Client-supplied teacherId / startTime / endTime are IGNORED.
        $enriched = [];
        $seenTeachers = [];
        foreach ($bookings as $i => $b) {
            if (!is_array($b)) {
                $this->_app_reject('SLOT_INDEX_INVALID', "Booking {$i} is malformed.");
            }
            $rawIdx = $b['slotIndex'] ?? null;
            if (!is_numeric($rawIdx)) {
                $this->_app_reject('SLOT_INDEX_INVALID', "Booking {$i}: slotIndex required.");
            }
            $idx = (int) $rawIdx;
            if ($idx < 0 || $idx >= $slotCount) {
                $this->_app_reject('SLOT_INDEX_INVALID',
                    'This PTM was just updated. Please refresh and pick again.', 409,
                    ['slotIndex' => $idx, 'available' => $slotCount]);
            }
            $slot = $ptmSlots[$idx];
            if (!is_array($slot)) {
                $this->_app_reject('SLOT_INDEX_INVALID', 'Slot data missing on PTM.');
            }
            $teacherId   = (string) ($slot['teacherId']   ?? '');
            $teacherName = (string) ($slot['teacherName'] ?? '');
            $startTime   = (string) ($slot['startTime']   ?? '');
            $endTime     = (string) ($slot['endTime']     ?? '');

            // Today-time gate: parents cannot book a slot whose endTime has
            // already passed when the PTM is today.
            if ($ptmDate === $today && $endTime !== '' && strcmp($endTime, $nowHm) <= 0) {
                $this->_app_reject('PTM_TIME_ENDED',
                    "Slot " . ($idx + 1) . " has already ended.", 409,
                    ['slotIndex' => $idx, 'endTime' => $endTime, 'now' => $nowHm]);
            }

            if ($teacherId !== '') {
                if (isset($seenTeachers[$teacherId])) {
                    $tname = $this->_resolve_teacher_name($teacherId, $teacherName);
                    $this->_app_reject('TEACHER_DUP',
                        "You already picked a slot with {$tname}; one booking per teacher.", 422,
                        ['teacherId' => $teacherId]);
                }
                $seenTeachers[$teacherId] = true;
            }

            $rawStatus = strtolower(trim((string) ($b['status'] ?? 'confirmed')));
            if (!in_array($rawStatus, ['pending', 'confirmed'], true)) {
                $this->_app_reject('STATUS_BLOCKED',
                    "Status '{$rawStatus}' is not allowed for parent submissions.", 422);
            }

            $note = (string) ($b['note'] ?? '');
            if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

            $enriched[] = [
                'slotIndex'     => $idx,
                'teacherId'     => $teacherId,
                'teacherName'   => $teacherName,
                'slotStartTime' => $startTime,
                'slotEndTime'   => $endTime,
                'status'        => $rawStatus,
                'note'          => $note,
                'respondedAt'   => round(microtime(true) * 1000),
                'markedBy'      => '',
                'markedAt'      => null,
            ];
        }

        // No-overlap pairwise check (after enrichment so we use server times).
        $sorted = $enriched;
        usort($sorted, fn($a, $b) => strcmp($a['slotStartTime'], $b['slotStartTime']));
        for ($i = 1; $i < count($sorted); $i++) {
            $prev = $sorted[$i - 1];
            $cur  = $sorted[$i];
            if ($prev['slotEndTime'] !== '' && $cur['slotStartTime'] !== '' &&
                strcmp($prev['slotEndTime'], $cur['slotStartTime']) > 0) {
                $this->_app_reject('BOOKING_OVERLAP',
                    sprintf("Slots %s–%s and %s–%s overlap.",
                        $prev['slotStartTime'], $prev['slotEndTime'],
                        $cur['slotStartTime'],  $cur['slotEndTime']),
                    422);
            }
        }

        // Best-effort capacity check + attendance-preservation pass.
        // Single sweep over peer RSVPs; we'll count fills and (later) see if
        // this student already has any 'attended'/'no-show' bookings to
        // preserve.
        try {
            $rsvpRows = $this->firebase->firestoreQuery(self::COL_RSVPS, [
                ['schoolId',   '==', $this->school_name],
                ['ptmEventId', '==', $ptmEventId],
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Ptm::parent_submit_rsvp peer-RSVP query failed: ' . $e->getMessage());
            $rsvpRows = []; // best-effort — capacity check skipped on failure
        }

        // slotIndex → fills (confirmed/attended) for OTHER students.
        $fills = [];
        // Existing bookings from THIS student (used for attendance preserve).
        $myExisting = [];
        foreach ((array) $rsvpRows as $r) {
            $rd = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            $sid = (string) ($rd['studentId'] ?? '');
            $bookingsList = is_array($rd['bookings'] ?? null) ? $rd['bookings'] : [];
            if (count($bookingsList) === 0) {
                // Legacy single-booking shape — synthesise a one-element list.
                $legacyIdx = isset($rd['slotIndex']) ? (int) $rd['slotIndex'] : -1;
                if ($legacyIdx >= 0 && (string) ($rd['teacherId'] ?? '') !== '') {
                    $bookingsList = [[
                        'slotIndex' => $legacyIdx,
                        'teacherId' => (string) ($rd['teacherId'] ?? ''),
                        'status'    => (string) ($rd['status']    ?? 'pending'),
                    ]];
                }
            }
            foreach ($bookingsList as $bk) {
                if (!is_array($bk)) continue;
                $bIdx = isset($bk['slotIndex']) ? (int) $bk['slotIndex'] : -1;
                $bSt  = (string) ($bk['status'] ?? 'pending');
                if ($bIdx < 0) continue;
                if ($sid === $studentId) {
                    $myExisting[$bIdx] = $bk; // remember by slotIndex
                    continue;
                }
                if ($bSt === 'confirmed' || $bSt === 'attended') {
                    $fills[$bIdx] = ($fills[$bIdx] ?? 0) + 1;
                }
            }
        }

        // Capacity reject + attended-preserve merge.
        $finalBookings = [];
        $preserved     = [];
        foreach ($enriched as $b) {
            $idx  = $b['slotIndex'];
            $slot = $ptmSlots[$idx];
            $cap  = isset($slot['capacity']) ? (int) $slot['capacity'] : 1;

            // Preservation: if this student already had this booking in
            // 'attended' or 'no-show', keep that record verbatim — parent
            // cannot revert a teacher's attendance call.
            $existing = $myExisting[$idx] ?? null;
            if (is_array($existing)) {
                $exStatus = (string) ($existing['status'] ?? '');
                if ($exStatus === 'attended' || $exStatus === 'no-show') {
                    $finalBookings[] = $existing;
                    $preserved[] = [
                        'slotIndex' => $idx,
                        'status'    => $exStatus,
                        'reason'    => 'Attendance was already marked by the teacher.',
                    ];
                    continue;
                }
            }

            $existingFill = (int) ($fills[$idx] ?? 0);
            if ($cap > 0 && $existingFill >= $cap) {
                $tname = $this->_resolve_teacher_name($b['teacherId'], $b['teacherName']);
                $this->_app_reject('CAPACITY_FULL',
                    sprintf("Slot %d (%s–%s with %s) is fully booked.",
                        $idx + 1, $b['slotStartTime'], $b['slotEndTime'], $tname),
                    409,
                    ['slotIndex' => $idx, 'capacity' => $cap, 'fill' => $existingFill]);
            }

            $finalBookings[] = $b;
        }

        // Build the doc. Legacy mirror = bookings[0] for one release cycle.
        $rsvpDocId = $this->_rsvp_doc_id($ptmEventId, $studentId);
        $first     = $finalBookings[0] ?? null;
        $now       = round(microtime(true) * 1000);

        $payload = [
            'ptmEventId'   => $ptmEventId,
            'schoolId'     => $this->school_name,
            'sectionKey'   => $ptmSectionKey,
            'studentId'    => $studentId,
            'studentName'  => $studentName,
            'className'    => $studentCls,
            'section'      => $studentSec,
            'rollNo'       => $rollNo,
            'parentName'   => $parentName,
            'parentPhone'  => $parentPhone,
            'parentEmail'  => $parentEmail,

            'bookings'     => $finalBookings,

            // Legacy mirror — populated from bookings[0]. Older app builds
            // continue to read these top-level fields.
            'slotIndex'     => $first ? $first['slotIndex']     : -1,
            'slotStartTime' => $first ? $first['slotStartTime'] : '',
            'slotEndTime'   => $first ? $first['slotEndTime']   : '',
            'teacherId'     => $first ? $first['teacherId']     : '',
            'teacherName'   => $first ? $first['teacherName']   : '',
            'status'        => $first ? $first['status']        : 'pending',
            'note'          => $first ? $first['note']          : '',

            'respondedAt'  => $now,
            'respondedBy'  => $studentId,
            'updatedAt'    => $now,
        ];

        try {
            $this->fs->setEntity(self::COL_RSVPS, $rsvpDocId, $payload, /* merge */ false);
        } catch (\Exception $e) {
            log_message('error', "Ptm::parent_submit_rsvp write failed for {$rsvpDocId}: " . $e->getMessage());
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to save RSVP. Please try again.']);
            exit;
        }

        log_message('debug', sprintf(
            'Ptm::parent_submit_rsvp OK ptmEventId=%s studentId=%s bookingCount=%d preserved=%d',
            $ptmEventId, $studentId, count($finalBookings), count($preserved)
        ));

        header('Content-Type: application/json');
        echo json_encode([
            'status'              => 'success',
            'ptmEventId'          => $ptmEventId,
            'studentId'           => $studentId,
            'bookingCount'        => count($finalBookings),
            'preservedBookings'   => $preserved,
            'bookings'            => $finalBookings,
        ]);
    }

    /**
     * POST /ptm/teacher_mark_attendance
     *
     * Teacher flips a single booking's status to 'attended' or 'no-show'.
     * Authorisation: token's uid must equal the slot's teacherId. Status
     * whitelist enforced. Doc is rewritten via Admin SDK so this still
     * works after Phase-2 rules lockdown.
     *
     * Body: { ptmEventId, studentId, slotIndex, status, note? }
     */
    public function teacher_mark_attendance()
    {
        header('Content-Type: application/json; charset=utf-8');

        $claims = $this->_app_claims;
        $role   = strtolower((string) ($claims['role'] ?? ''));
        $staffId = (string) ($claims['uid'] ?? '');
        if ($staffId === '') $this->_tm_reject('TM_AUTH_FAIL', 'Invalid auth token.', 401);
        if (!in_array($role, ['staff', 'teacher'], true)) {
            $this->_tm_reject('TM_NOT_TEACHER', 'Teacher role required.', 403);
        }

        $body       = $this->_read_app_body();
        $ptmEventId = trim((string) ($body['ptmEventId'] ?? ''));
        $studentId  = trim((string) ($body['studentId']  ?? ''));
        $slotIdxRaw = $body['slotIndex'] ?? null;
        $newStatus  = strtolower(trim((string) ($body['status'] ?? '')));
        $noteIn     = (string) ($body['note'] ?? '');

        if ($ptmEventId === '' || $studentId === '') {
            $this->_tm_reject('TM_RSVP_NOT_FOUND', 'ptmEventId and studentId required.');
        }
        if (!is_numeric($slotIdxRaw)) {
            $this->_tm_reject('TM_SLOT_INVALID', 'slotIndex required.');
        }
        $slotIndex = (int) $slotIdxRaw;
        if (!in_array($newStatus, ['attended', 'no-show'], true)) {
            $this->_tm_reject('TM_STATUS_INVALID', "Status must be 'attended' or 'no-show'.");
        }

        // 1. Load PTM, validate slot, verify teacher is assigned to it.
        $ptm = $this->fs->getEntity(self::COL_PTMS, $ptmEventId);
        if (!is_array($ptm)) $this->_tm_reject('TM_PTM_NOT_FOUND', 'PTM not found.', 404);
        $slots = is_array($ptm['slots'] ?? null) ? array_values($ptm['slots']) : [];
        if ($slotIndex < 0 || $slotIndex >= count($slots) || !is_array($slots[$slotIndex])) {
            $this->_tm_reject('TM_SLOT_INVALID', 'Slot no longer exists.', 409);
        }
        $slotTeacherId = (string) ($slots[$slotIndex]['teacherId'] ?? '');
        if ($slotTeacherId === '' || $slotTeacherId !== $staffId) {
            $this->_tm_reject('TM_NOT_ASSIGNED',
                'You are not assigned to this slot.', 403,
                ['slotIndex' => $slotIndex, 'expected' => $slotTeacherId, 'actual' => $staffId]);
        }

        // 2. Load RSVP doc (one per (PTM, student)).
        $rsvpDocId = $this->_rsvp_doc_id($ptmEventId, $studentId);
        $rsvp = $this->fs->getEntity(self::COL_RSVPS, $rsvpDocId);
        if (!is_array($rsvp)) {
            $this->_tm_reject('TM_RSVP_NOT_FOUND', 'No RSVP from this student for this PTM.', 404);
        }

        // 3. Find or synthesise the booking for this slot. Two shapes:
        //    - New: bookings[] holds entries; find one with matching slotIndex.
        //    - Legacy: top-level fields describe a single booking; promote
        //      it into a one-element bookings[] before mutating.
        $bookings = is_array($rsvp['bookings'] ?? null) ? $rsvp['bookings'] : [];
        $touchIdx = -1;

        if (count($bookings) === 0) {
            // Legacy promotion (only if matching slotIndex on the legacy fields).
            $legacyIdx = isset($rsvp['slotIndex']) ? (int) $rsvp['slotIndex'] : -1;
            $legacyTid = (string) ($rsvp['teacherId'] ?? '');
            if ($legacyIdx === $slotIndex && $legacyTid === $staffId) {
                $bookings = [[
                    'slotIndex'     => $legacyIdx,
                    'teacherId'     => $legacyTid,
                    'teacherName'   => (string) ($rsvp['teacherName']   ?? ''),
                    'slotStartTime' => (string) ($rsvp['slotStartTime'] ?? ''),
                    'slotEndTime'   => (string) ($rsvp['slotEndTime']   ?? ''),
                    'status'        => (string) ($rsvp['status']        ?? 'pending'),
                    'note'          => (string) ($rsvp['note']          ?? ''),
                    'respondedAt'   => $rsvp['respondedAt']             ?? null,
                ]];
                $touchIdx = 0;
            }
        } else {
            foreach ($bookings as $i => $b) {
                if (!is_array($b)) continue;
                if (((int) ($b['slotIndex'] ?? -1)) === $slotIndex
                 && ((string) ($b['teacherId'] ?? '')) === $staffId) {
                    $touchIdx = $i;
                    break;
                }
            }
        }

        if ($touchIdx < 0) {
            $this->_tm_reject('TM_BOOKING_NOT_FOUND',
                'No booking by this student for your slot.', 404,
                ['slotIndex' => $slotIndex]);
        }

        // 4. Mutate the matching booking; leave all others untouched.
        $now = round(microtime(true) * 1000);
        $bookings[$touchIdx]['status']   = $newStatus;
        $bookings[$touchIdx]['markedBy'] = $staffId;
        $bookings[$touchIdx]['markedAt'] = $now;
        if ($noteIn !== '') {
            $bookings[$touchIdx]['note'] = mb_substr($noteIn, 0, 500);
        }

        // 5. Build the merge payload. Update the bookings array. Mirror to
        //    legacy top-level fields ONLY if we touched bookings[0] (the
        //    legacy mirror represents the first booking).
        $update = [
            'bookings'  => $bookings,
            'updatedAt' => $now,
        ];
        if ($touchIdx === 0) {
            $update['status']   = $newStatus;
            $update['markedBy'] = $staffId;
            $update['markedAt'] = $now;
            if ($noteIn !== '') $update['note'] = mb_substr($noteIn, 0, 500);
        }

        try {
            $this->fs->setEntity(self::COL_RSVPS, $rsvpDocId, $update, /* merge */ true);
        } catch (\Exception $e) {
            log_message('error', "Ptm::teacher_mark_attendance write failed for {$rsvpDocId}: " . $e->getMessage());
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to mark attendance. Please try again.']);
            exit;
        }

        log_message('debug', sprintf(
            'Ptm::teacher_mark_attendance OK ptm=%s student=%s slot=%d teacher=%s status=%s',
            $ptmEventId, $studentId, $slotIndex, $staffId, $newStatus
        ));

        header('Content-Type: application/json');
        echo json_encode([
            'status'     => 'success',
            'ptmEventId' => $ptmEventId,
            'studentId'  => $studentId,
            'booking'    => $bookings[$touchIdx],
        ]);
    }
}
