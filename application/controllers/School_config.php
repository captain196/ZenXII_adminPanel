<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * School_config — Core School Configuration
 *
 * Manages all foundational school configuration:
 *   - School profile (display name, contact, logo, affiliation)
 *   - Academic sessions (list, add, set active)
 *   - Board configuration (type, grading pattern, grade scale)
 *   - Master class list (ordinal + foundational classes) with soft-delete
 *   - Sections per class per session
 *   - Subject assignments per class
 *   - Stream configuration (for Classes 11-12)
 *
 * Firebase paths:
 *   Schools/{school}/Config/Profile/          — school profile fields
 *   Schools/{school}/Config/Board/            — board + grading config
 *   Schools/{school}/Config/Classes/          — master class list array
 *   Schools/{school}/Config/Streams/          — stream definitions
 *   Schools/{school}/Config/ActiveSession     — active session string
 *   Schools/{school}/Sessions                 — session list (existing)
 *   Schools/{school}/Subject_list/{key}/      — subjects (existing)
 *   Schools/{school}/{session}/Class {n}/     — class nodes (existing)
 *   System/Schools/{school_id}/profile        — onboarding profile (canonical)
 */
class School_config extends MY_Controller
{
    /** Only Admin/Principal may configure school settings */
    private const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Configuration');
        // Firestore_service ($this->fs) already loaded and initialized by MY_Controller
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET  /school_config
    // ─────────────────────────────────────────────────────────────────────
    public function index()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_index');
        $this->load->view('include/header');
        $this->load->view('school_config/index', [
            'session_year' => $this->session_year,
        ]);
        $this->load->view('include/footer');
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/get_config
    // Returns all config sections in one shot for initial page load
    // ─────────────────────────────────────────────────────────────────────
    public function get_config()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_config');
        $school    = $this->school_name;
        $school_id = $this->school_id;

        // ── Read school doc from Firestore (single read for all config) ──
        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        if (!is_array($fsSchool)) $fsSchool = [];

        // 2026-05-21 BUG-031 — removed write-on-read auto-sync that survived
        // Phase 1's cleanup. Same drawbacks as the removed session auto-seed:
        // silent writes on every page load, races with concurrent save_profile,
        // quota burn under refresh storms. Now: in-memory only via the
        // name_not_configured flag. Operator invokes save_profile to persist.
        $nameNotConfigured = empty($fsSchool['name']) && !empty($this->school_display_name);
        if ($nameNotConfigured) {
            // In-memory only — DO NOT persist. UI shows "Configure display name" prompt.
            $fsSchool['name'] = $this->school_display_name;
        }

        // Map Firestore fields to config field names
        $profile = [
            'display_name'     => $fsSchool['name'] ?? '',
            'address'          => $fsSchool['address'] ?? '',
            'city'             => $fsSchool['city'] ?? '',
            'state'            => $fsSchool['state'] ?? '',
            'phone'            => $fsSchool['phone'] ?? '',
            'email'            => $fsSchool['email'] ?? '',
            'logo_url'         => $fsSchool['logoUrl'] ?? '',
            'principal_name'   => $fsSchool['principal'] ?? '',
            'affiliation_board'=> $fsSchool['affiliationBoard'] ?? $fsSchool['board'] ?? '',
            'affiliation_no'   => $fsSchool['affiliationNo'] ?? '',
            'established_year' => $fsSchool['establishedYear'] ?? '',
            'website'          => $fsSchool['website'] ?? '',
            'pincode'          => $fsSchool['pincode'] ?? '',
        ];

        $board    = (is_array($fsSchool['board_config'] ?? null)) ? $fsSchool['board_config'] : [];
        $classes  = (is_array($fsSchool['classes'] ?? null))      ? $fsSchool['classes']      : [];
        $streams  = (is_array($fsSchool['streams'] ?? null))      ? $fsSchool['streams']      : [];
        $sessions = (is_array($fsSchool['sessions'] ?? null))     ? $fsSchool['sessions']     : [];
        $activeSess = !empty($fsSchool['currentSession'])
                        ? $fsSchool['currentSession']
                        : $this->session_year;

        // Normalise classes to plain array, filter soft-deleted for UI
        $classes = is_array($classes) ? array_values($classes) : [];

        // If classes list is empty, enumerate from Firestore sections collection
        // so schools with existing data can still use the Sections and Subjects tabs.
        if (empty($classes)) {
            $fsSections = $this->fs->schoolWhere('sections', []);
            $classNodes = [];
            if (is_array($fsSections)) {
                foreach ($fsSections as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $cn = $d['className'] ?? '';
                    if ($cn !== '' && strpos($cn, 'Class ') === 0) {
                        $classNodes[$cn] = true;
                    }
                }
            }
            $order = 0;
            foreach (array_keys($classNodes) as $nodeKey) {
                $raw     = trim(str_replace('Class ', '', $nodeKey));
                $lower   = strtolower($raw);
                $isFound = in_array($lower, ['nursery', 'lkg', 'ukg', 'playgroup'], true);
                $key     = $isFound ? strtoupper($raw) : (string) (int) preg_replace('/\D/', '', $raw);
                if ($key === '' || $key === '0') continue;
                $classes[] = [
                    'key'             => $key,
                    'label'           => $nodeKey,
                    'type'            => $isFound ? 'foundational' : 'primary',
                    'order'           => $order++,
                    'streams_enabled' => in_array($key, ['11', '12'], true),
                    'deleted'         => false,
                ];
            }
        }

        // Normalise sessions to plain array.
        $sessions = is_array($sessions)
            ? array_values(array_filter($sessions, 'is_string'))
            : [];

        // 2026-05-15 Phase 1 — get_config is now read-only. The previous
        // auto-seed writes (Firestore update on read) caused: (a) silent
        // writes on every page load by any operator with config-read
        // permission, (b) races with concurrent add_session, (c) quota
        // burn under refresh storms. The UI now receives the unmodified
        // state and a `session_not_configured` flag; operators must use
        // the explicit `add_session` / `set_active_session` endpoints.
        $sessionNotConfigured = false;
        if (empty($sessions)) {
            $sessionNotConfigured = true;
            if (!empty($activeSess) && preg_match('/^\d{4}-\d{2}$/', (string) $activeSess)) {
                // Surface the dangling currentSession to the UI in-memory
                // only — DO NOT persist. The Sessions tab will show this
                // as a recoverable "needs configuration" state.
                $sessions = [(string) $activeSess];
            }
        } elseif (!empty($activeSess)
                  && preg_match('/^\d{4}-\d{2}$/', (string) $activeSess)
                  && !in_array((string) $activeSess, $sessions, true)) {
            // Inconsistent state: currentSession points outside sessions[].
            // Surface to UI in-memory only — DO NOT persist.
            $sessions[] = (string) $activeSess;
            sort($sessions);
            $sessionNotConfigured = true;
            log_message('error',
                "ACC_STALE_SESSION schoolId={$this->school_id} "
                . "currentSession={$activeSess} not in sessions[]");
        }

        // ── Sync PHP session cache to match Firebase ──────────────────────
        if (!empty($sessions)) {
            $this->session->set_userdata('available_sessions', $sessions);
        }

        // Report card template from Firestore school doc
        $rcTemplate = $fsSchool['reportCardTemplate'] ?? null;
        $rcAllowed  = ['classic', 'cbse', 'minimal', 'modern', 'elegant'];
        if (!$rcTemplate || !is_string($rcTemplate) || !in_array($rcTemplate, $rcAllowed, true)) $rcTemplate = 'classic';

        $archivedSess = (is_array($fsSchool['archivedSessions'] ?? null))
                            ? array_values(array_filter($fsSchool['archivedSessions'], 'is_string'))
                            : [];

        $this->json_success([
            'profile'                 => is_array($profile)  ? $profile  : [],
            'board'                   => is_array($board)     ? $board    : [],
            'classes'                 => $classes,
            'streams'                 => (object) (is_array($streams) ? $streams : []),
            'sessions'                => $sessions,
            'active_session'          => (string) $activeSess,
            'archived_sessions'       => $archivedSess,
            'firebase_path'           => "Schools/{$school}/Sessions",
            'report_card_template'    => $rcTemplate,
            'session_not_configured'  => $sessionNotConfigured,
            'name_not_configured'     => $nameNotConfigured,  // BUG-031
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Phase 1 (2026-05-15) — school-config concurrency lock + CAS helpers
    //
    // A filesystem lock keyed by (schoolFs, lockName) serializes brief
    // controller-level critical sections (add_session, set_active_session,
    // rollover_session start). It does NOT replace Firestore CAS — it
    // narrows the read-then-write race window before the CAS attempt.
    //
    // Lock TTL is enforced via flock + brief acquire timeout. If a
    // previous PHP request died without releasing, the next acquire
    // will succeed once the prior lock file handle is garbage-collected
    // by the OS (Apache worker recycle / fastcgi process end).
    // ─────────────────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    // Phase 2 (2026-05-15) — dependency-summary + telemetry helpers
    //
    // Centralises the "count rows in a dependent collection, never throw"
    // pattern used by every harden-delete endpoint. Returns a structured
    // [collection => count] map suitable for direct inclusion in the
    // response payload. Each per-collection query is wrapped in its own
    // try/catch so one Firestore index miss never sinks the whole check.
    //
    // Signature:
    //   _dep_count_safe([
    //     ['key' => 'students', 'collection' => 'students',
    //      'filters' => [['sectionKey', '==', $sectionKey], ['status', '==', 'Active']],
    //      'label' => 'enrolled student(s)'],
    //     ...
    //   ]): [
    //     'has_any'      => bool,
    //     'total'        => int,
    //     'by_key'       => ['students' => 3, ...],
    //     'human'        => ['3 enrolled student(s)', '12 attendance row(s)', ...],
    //     'errors'       => ['students' => 'index missing', ...],  // only on failures
    //   ]
    //
    // Cap is 200 per query — enough to know "there are dependencies" without
    // burning quota; the response surfaces the count, not the rows.
    // ─────────────────────────────────────────────────────────────────────
    private function _dep_count_safe(array $checks): array
    {
        $out = [
            'has_any' => false,
            'total'   => 0,
            'by_key'  => [],
            'human'   => [],
            'errors'  => [],
        ];
        foreach ($checks as $check) {
            if (!is_array($check)) continue;
            $key   = (string) ($check['key'] ?? '');
            $coll  = (string) ($check['collection'] ?? '');
            $label = (string) ($check['label'] ?? $key);
            $flts  = is_array($check['filters'] ?? null) ? $check['filters'] : [];
            if ($key === '' || $coll === '') continue;
            try {
                $rows = $this->fs->schoolWhere($coll, $flts, null, 'ASC', 200);
                $n    = is_array($rows) ? count($rows) : 0;
                $out['by_key'][$key] = $n;
                if ($n > 0) {
                    $out['has_any'] = true;
                    $out['total'] += $n;
                    $suffix = ($n >= 200) ? '+' : '';
                    $out['human'][] = "{$n}{$suffix} {$label}";
                }
            } catch (\Throwable $e) {
                $out['errors'][$key] = $e->getMessage();
                log_message('error',
                    "ACC_DEP_CHECK_FAILED schoolId={$this->school_id} "
                    . "collection={$coll} key={$key} err=" . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Phase 2 (2026-05-15) — local structured-error emitter. The shared
     * `json_error()` on MY_Controller only accepts a string message;
     * for dependency-blocked deletes we want to surface a structured
     * `dependencies: {…}` payload alongside the human message so the
     * UI can render per-collection counts without re-querying. Mirrors
     * `json_error()`'s exit semantics for compatibility — calls exit
     * after writing the response.
     */
    private function _json_error_with_data(string $message, array $data, int $httpCode = 400): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        $payload = array_merge([
            'status'     => 'error',
            'message'    => $message,
            'csrf_token' => $this->security->get_csrf_hash(),
        ], $data);
        echo json_encode($payload);
        exit;
    }

    /**
     * Phase 2 (2026-05-15) — uniform dependency-block telemetry. Emitted
     * by every delete endpoint that refuses a destructive action because
     * of live references. Operators / log aggregators grep
     * `ACC_DELETE_BLOCKED endpoint=…` to spot governance-relevant
     * rejections.
     */
    private function _log_dep_block(string $endpoint, string $entityId, array $depSummary): void
    {
        $kv = [];
        foreach ((array) ($depSummary['by_key'] ?? []) as $k => $n) {
            if ((int) $n > 0) $kv[] = "{$k}={$n}";
        }
        log_message('error',
            "ACC_DELETE_BLOCKED endpoint={$endpoint} schoolId={$this->school_id} "
            . "actor={$this->admin_id} entityId={$entityId} "
            . "total={$depSummary['total']} deps=" . (empty($kv) ? '(none)' : implode(',', $kv)));
    }

    private function _config_lock_acquire(string $lockName, int $timeoutMs = 2000)
    {
        $dir = APPPATH . 'cache/school_config_locks/';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $this->school_id . '__' . $lockName);
        $path = $dir . $safe . '.lock';
        $fh = @fopen($path, 'c+');
        if (!$fh) return null;
        $deadline = microtime(true) + ($timeoutMs / 1000.0);
        do {
            if (@flock($fh, LOCK_EX | LOCK_NB)) {
                @ftruncate($fh, 0);
                @fwrite($fh, json_encode([
                    'acquired_at' => date('c'),
                    'admin_id'    => $this->admin_id,
                    'pid'         => function_exists('getmypid') ? getmypid() : 0,
                ]));
                return $fh;
            }
            usleep(50000); // 50 ms
        } while (microtime(true) < $deadline);
        @fclose($fh);
        return null;
    }
    private function _config_lock_release($fh): void
    {
        if (is_resource($fh)) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/save_profile
    // ─────────────────────────────────────────────────────────────────────
    public function save_profile()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_profile');
        $school    = $this->school_name;
        $school_id = $this->school_id;

        $allowed = [
            'display_name', 'address', 'city', 'state', 'pincode',
            'phone', 'email', 'website', 'principal_name',
            'affiliation_board', 'affiliation_no', 'established_year',
        ];

        // BUG-029: byte-length caps at trust boundary prevent Firestore DocTooLarge.
        // strlen() byte count matches Firestore 1MB doc-cap semantics.
        $maxLengths = [
            'display_name'      => 200, 'address'           => 500,
            'city'              => 100, 'state'             => 100,
            'pincode'           => 20,  'phone'             => 50,
            'email'             => 200, 'website'           => 500,
            'principal_name'    => 200, 'affiliation_board' => 100,
            'affiliation_no'    => 100, 'established_year'  => 10,
        ];

        $data = [];
        foreach ($allowed as $field) {
            $val = trim((string) $this->input->post($field, TRUE));
            if ($val !== '') {
                if (strlen($val) > $maxLengths[$field]) {
                    return $this->json_error("Field '{$field}' exceeds {$maxLengths[$field]} characters.");
                }
                $data[$field] = $val;
            }
        }

        if (empty($data)) {
            return $this->json_error('No data provided.');
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->json_error('Invalid email address.');
        }

        if (!empty($data['phone']) && !preg_match('/^[\d\s\+\-\(\)]+$/', $data['phone'])) {
            return $this->json_error('Invalid phone number.');
        }

        if (!empty($data['established_year'])) {
            $yr = (int) $data['established_year'];
            if ($yr < 1800 || $yr > (int) date('Y')) {
                return $this->json_error('Invalid established year.');
            }
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        // Firestore (sole write target)
        $this->fs->saveSchool($data);

        log_audit('Configuration', 'save_profile', $school, 'Updated school profile');

        $this->json_success(['message' => 'Profile saved successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/upload_logo
    // ─────────────────────────────────────────────────────────────────────
    public function upload_logo()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_upload_logo');
        $school    = $this->school_name;
        $school_id = $this->school_id;

        if (empty($_FILES['logo']['name'])) {
            return $this->json_error('No file uploaded.');
        }

        $tempDir = APPPATH . 'temp/';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'upload_path'   => $tempDir,
            'allowed_types' => 'jpg|jpeg|png|gif|webp',
            'max_size'      => 2048,
            'encrypt_name'  => TRUE,
        ];

        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('logo')) {
            return $this->json_error($this->upload->display_errors('', ''));
        }

        $info       = $this->upload->data();
        $localPath  = $info['full_path'];
        $safe       = preg_replace('/[^A-Za-z0-9_\-]/', '_', $school);
        $remotePath = "schools/{$safe}/logo/" . $info['file_name'];

        $uploaded = $this->firebase->uploadFile($localPath, $remotePath);
        @unlink($localPath);

        if (!$uploaded) {
            return $this->json_error('Failed to upload logo to storage.');
        }

        $url = $this->firebase->getDownloadUrl($remotePath);

        // Firestore (sole write target)
        $this->fs->saveSchool(['logo_url' => $url, 'logo_updated_at' => date('Y-m-d H:i:s')]);

        log_audit('Configuration', 'upload_logo', $school, 'Uploaded school logo');

        $this->json_success(['logo_url' => $url, 'message' => 'Logo uploaded successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/upload_document
    // Uploads Holidays Calendar or Academic Calendar to Firebase Storage
    // and stores the URL at Config/Profile/{holidays_calendar|academic_calendar}
    // ─────────────────────────────────────────────────────────────────────
    public function upload_document()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_upload_document');
        $school = $this->school_name;
        $type   = trim((string) $this->input->post('doc_type', TRUE));

        $allowed = ['holidays_calendar', 'academic_calendar'];
        if (!in_array($type, $allowed, true)) {
            return $this->json_error('Invalid document type.');
        }

        if (empty($_FILES['document']['name'])) {
            return $this->json_error('No file uploaded.');
        }

        $tempDir = APPPATH . 'temp/';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'upload_path'   => $tempDir,
            'allowed_types' => 'pdf|jpg|jpeg|png|gif|webp|doc|docx',
            'max_size'      => 5120, // 5 MB
            'encrypt_name'  => TRUE,
        ];

        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('document')) {
            return $this->json_error($this->upload->display_errors('', ''));
        }

        $info       = $this->upload->data();
        $localPath  = $info['full_path'];
        $safe       = preg_replace('/[^A-Za-z0-9_\-]/', '_', $school);
        $folder     = $type === 'holidays_calendar' ? 'holidays' : 'academic';
        $remotePath = "schools/{$safe}/{$folder}/" . $info['file_name'];

        $uploaded = $this->firebase->uploadFile($localPath, $remotePath);
        @unlink($localPath);

        if (!$uploaded) {
            return $this->json_error('Failed to upload document to storage.');
        }

        $url = $this->firebase->getDownloadUrl($remotePath);

        // Firestore (sole write target)
        $this->fs->update('schools', $this->fs->schoolId(), [$type => $url, 'updatedAt' => date('c')]);

        $label = $type === 'holidays_calendar' ? 'Holidays Calendar' : 'Academic Calendar';

        log_audit('Configuration', 'upload_document', $type, "Uploaded {$label}");

        $this->json_success(['url' => $url, 'message' => "{$label} uploaded successfully."]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/save_board
    // ─────────────────────────────────────────────────────────────────────
    public function save_board()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_board');
        $school = $this->school_name;

        $type            = trim((string) $this->input->post('type', TRUE));
        $customBoardName = trim((string) $this->input->post('custom_board_name', TRUE));
        $gradingPattern  = trim((string) $this->input->post('grading_pattern', TRUE));
        $passingMarks    = (int) $this->input->post('passing_marks');
        $gradeScaleRaw   = (string) $this->input->post('grade_scale');

        // Issue 3: Added IB to valid types
        $validTypes    = ['CBSE', 'ICSE', 'State', 'IB', 'Custom'];
        $validPatterns = ['marks', 'grades', 'cgpa'];

        if (!in_array($type, $validTypes, true)) {
            return $this->json_error('Invalid board type. Allowed: ' . implode(', ', $validTypes));
        }

        if (!in_array($gradingPattern, $validPatterns, true)) {
            return $this->json_error('Invalid grading pattern. Allowed: ' . implode(', ', $validPatterns));
        }

        $data = [
            'type'            => $type,
            'grading_pattern' => $gradingPattern,
            'passing_marks'   => max(0, min(100, $passingMarks)),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        // Issue 3: Save custom name for State, IB, and Custom types
        if (in_array($type, ['State', 'IB', 'Custom'], true) && $customBoardName !== '') {
            $data['custom_board_name'] = $customBoardName;
        }

        // Grade scale — only for grades / cgpa patterns
        if ($gradingPattern !== 'marks' && $gradeScaleRaw !== '') {
            $gradeScale = json_decode($gradeScaleRaw, true);
            if (is_array($gradeScale)) {
                $clean = [];
                foreach ($gradeScale as $entry) {
                    $grade  = trim((string) ($entry['grade']   ?? ''));
                    $minPct = (float)              ($entry['min_pct'] ?? 0);
                    $maxPct = (float)              ($entry['max_pct'] ?? 100);
                    if ($grade !== '' && $minPct >= 0 && $maxPct <= 100 && $minPct < $maxPct) {
                        $clean[] = ['grade' => $grade, 'min_pct' => $minPct, 'max_pct' => $maxPct];
                    }
                }
                if (!empty($clean)) {
                    $data['grade_scale'] = $clean;
                }
            }
        }

        // Firestore (sole write target)
        $this->fs->update('schools', $this->fs->schoolId(), ['board_config' => $data, 'updatedAt' => date('c')]);

        log_audit('Configuration', 'save_board', $school, "Board {$type} ({$gradingPattern}) saved");

        $this->json_success(['message' => 'Board configuration saved.']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/save_classes
    // Saves the complete master class list (with soft-delete support)
    // ─────────────────────────────────────────────────────────────────────
    public function save_classes()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_classes');
        $school     = $this->school_name;
        $rawClasses = json_decode($this->input->post('classes') ?? '[]', true);

        if (!is_array($rawClasses) || empty($rawClasses)) {
            return $this->json_error('No classes provided.');
        }

        // BUG-029: cardinality cap — classes stored as single array field on
        // school doc; oversize array trips Firestore 1MB DocTooLarge.
        if (count($rawClasses) > 200) {
            return $this->json_error('Too many classes (max 200).');
        }

        $validTypes = ['foundational', 'primary', 'middle', 'secondary', 'senior'];
        $clean      = [];

        foreach ($rawClasses as $i => $cls) {
            $key   = trim((string) ($cls['key']   ?? ''));
            $label = trim((string) ($cls['label'] ?? ''));
            $type  = trim((string) ($cls['type']  ?? 'primary'));
            $order = (int) ($cls['order'] ?? $i);

            if ($key === '' || $label === '') {
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9_]+$/', $key)) {
                continue;
            }

            // BUG-029: per-label byte cap
            if (strlen($label) > 100) {
                return $this->json_error("Class label '{$label}' exceeds 100 characters.");
            }

            if (!in_array($type, $validTypes, true)) {
                $type = 'primary';
            }

            // 2026-05-15 Phase 3 — canonicalize the label. For known key
            // patterns (numeric grades + foundational keywords) the label
            // is rewritten to the canonical form derived from the key.
            // For custom keys (e.g. Montessori "primary_a") the client
            // label is preserved verbatim. See _normalize_class_label.
            $canonicalLabel = $this->_normalize_class_label($key, $label);
            if ($canonicalLabel !== $label) {
                log_message('info',
                    "ACC_CLASS_LABEL_NORMALIZED schoolId={$this->school_id} "
                    . "actor=" . ($this->admin_id ?? 'unknown') . " "
                    . "key={$key} from=" . json_encode($label) . " to=" . json_encode($canonicalLabel));
            }

            // Issue 7: Preserve soft-delete flag
            $clean[] = [
                'key'             => $key,
                'label'           => $canonicalLabel,
                'type'            => $type,
                'order'           => $order,
                'streams_enabled' => !empty($cls['streams_enabled']),
                'deleted'         => !empty($cls['deleted']),
            ];
        }

        if (empty($clean)) {
            return $this->json_error('No valid classes found in request.');
        }

        // Firestore (sole write target)
        $this->fs->update('schools', $this->fs->schoolId(), ['classes' => $clean, 'updatedAt' => date('c')]);

        log_audit('Configuration', 'save_classes', $school, 'Saved class list (' . count($clean) . ' classes)');

        $this->json_success(['message' => 'Class list saved.', 'count' => count($clean)]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/activate_classes
    // Issue 5: Creates class nodes in the active session for saved classes
    // ─────────────────────────────────────────────────────────────────────
    public function activate_classes()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_activate_classes');
        $school      = $this->school_name;
        $sessionYear = trim((string) $this->input->post('session', TRUE));

        if ($sessionYear === '') {
            $sessionYear = $this->session_year;
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $sessionYear)) {
            return $this->json_error('Invalid session format.');
        }

        // Read classes from Firestore school doc
        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        $classes = (is_array($fsSchool['classes'] ?? null)) ? $fsSchool['classes'] : [];
        if (empty($classes)) {
            return $this->json_error('No classes configured. Save class list first.');
        }

        // Pre-fetch existing sections for this session to check which classes already have sections
        $existingSections = $this->fs->schoolWhere('sections', [['session', '==', $sessionYear]]);
        $existingClassNodes = [];
        if (is_array($existingSections)) {
            foreach ($existingSections as $doc) {
                $d = $doc['data'] ?? $doc;
                $cn = $d['className'] ?? '';
                if ($cn !== '') $existingClassNodes[$cn] = true;
            }
        }

        $created = 0;
        $skipped = 0;

        foreach ($classes as $cls) {
            if (!is_array($cls)) continue;
            if (!empty($cls['deleted'])) continue;

            $key = $cls['key'] ?? '';
            if ($key === '') continue;

            $classNode = $this->_class_node_name($key);

            // If sections already exist for this class in this session, skip
            if (isset($existingClassNodes[$classNode])) {
                $skipped++;
                continue;
            }

            // Create a default "Section A" in Firestore for this class
            $this->fs->saveSection($classNode, 'A', [
                'created_at'  => date('Y-m-d H:i:s'),
                'created_by'  => 'School_config::activate_classes',
            ]);
            $created++;
        }

        log_audit('Configuration', 'activate_classes', $sessionYear, "Activated {$created} class(es) in {$sessionYear} ({$skipped} already existed)");

        $this->json_success([
            'message' => "{$created} class(es) activated in {$sessionYear}. {$skipped} already existed.",
            'created' => $created,
            'skipped' => $skipped,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/soft_delete_class
    // Issue 7: Soft-delete a class (set deleted flag, don't remove data)
    // ─────────────────────────────────────────────────────────────────────
    public function soft_delete_class()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_soft_delete_class');
        $school   = $this->school_name;
        $classKey = trim((string) $this->input->post('class_key', TRUE));

        if ($classKey === '') {
            return $this->json_error('class_key is required.');
        }

        // Read classes from Firestore school doc
        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        $classes = (is_array($fsSchool['classes'] ?? null)) ? $fsSchool['classes'] : [];
        if (empty($classes)) {
            return $this->json_error('No classes configured.');
        }

        $found = false;
        foreach ($classes as &$cls) {
            if (is_array($cls) && ($cls['key'] ?? '') === $classKey) {
                $cls['deleted']    = true;
                $cls['deleted_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        unset($cls);

        if (!$found) {
            return $this->json_error('Class not found.');
        }

        // 2026-05-15 Phase 2 — extended referential-integrity check.
        // Was: students-in-each-section only (per-section iteration).
        // Now: students + class-level attendance/timetable/marks/feeDemands
        // scoped to the current session. A single class-level query is
        // cheaper than per-section iteration AND catches data tied to
        // the class without a section binding (e.g. class-wide notices,
        // class-default fees). Section-level students check is retained
        // for backwards-compat error messaging.
        $classNode  = $this->_class_node_name($classKey);
        $curSession = $this->session_year;

        // First, the existing per-section enrolled-students sweep (kept
        // because it produces the most informative error message:
        // "students enrolled in class 5 / Section A").
        $fsSections = $this->fs->schoolWhere('sections', [['className', '==', $classNode]]);
        if (is_array($fsSections)) {
            foreach ($fsSections as $secDoc) {
                $d = $secDoc['data'] ?? $secDoc;
                $secName = $d['section'] ?? '';
                if ($secName === '') continue;
                $sectionKey = "{$classNode}/{$secName}";
                $stuDocs = $this->fs->schoolWhere('students', [
                    ['sectionKey', '==', $sectionKey],
                    ['status', '==', 'Active'],
                ]);
                if (!empty($stuDocs)) {
                    $stuCount = count($stuDocs);
                    log_message('error',
                        "ACC_DELETE_BLOCKED endpoint=soft_delete_class schoolId={$this->school_id} "
                        . "actor={$this->admin_id} classKey={$classKey} reason=enrolled_students "
                        . "section={$sectionKey} count={$stuCount}");
                    $this->_json_error_with_data(
                        "Cannot delete: {$stuCount} active student(s) enrolled in {$classNode} / {$secName}. "
                        . "Transfer or remove students first.",
                        [
                            'dependencies' => ['students' => $stuCount],
                            'sectionKey'   => $sectionKey,
                            'classKey'     => $classKey,
                        ],
                        409
                    );
                }
            }
        }

        // Class-level dependency sweep — catches non-section-keyed records
        // that would orphan against the deleted class.
        $deps = $this->_dep_count_safe([
            [
                'key'        => 'attendance',
                'collection' => 'attendance',
                'filters'    => [['className', '==', $classNode], ['session', '==', $curSession]],
                'label'      => 'attendance record(s)',
            ],
            [
                'key'        => 'timetables',
                'collection' => 'timetables',
                'filters'    => [['className', '==', $classNode], ['session', '==', $curSession]],
                'label'      => 'timetable entry/entries',
            ],
            [
                'key'        => 'marks',
                'collection' => 'marks',
                'filters'    => [['className', '==', $classNode], ['session', '==', $curSession]],
                'label'      => 'marks record(s)',
            ],
            [
                'key'        => 'feeDemands',
                'collection' => 'feeDemands',
                'filters'    => [['className', '==', $classNode], ['session', '==', $curSession]],
                'label'      => 'fee demand(s)',
            ],
            [
                'key'        => 'subjectAssignments',
                'collection' => 'subjectAssignments',
                'filters'    => [['classKey', '==', $this->_numeric_class_key($classKey)], ['session', '==', $curSession]],
                'label'      => 'subject assignment(s)',
            ],
        ]);
        if (!empty($deps['has_any'])) {
            $this->_log_dep_block('soft_delete_class', $classKey, $deps);
            $this->_json_error_with_data(
                "Cannot delete class {$classKey}: " . implode(', ', $deps['human'])
                . ' reference this class. Migrate or archive the data first.',
                [
                    'dependencies' => $deps['by_key'],
                    'classKey'     => $classKey,
                    'session'      => $curSession,
                ],
                409
            );
        }

        $cleanClasses = array_values($classes);

        // Firestore (sole write target)
        $this->fs->update('schools', $this->fs->schoolId(), ['classes' => $cleanClasses, 'updatedAt' => date('c')]);

        log_message('error',
            "ACC_CLASS_SOFT_DELETED schoolId={$this->school_id} actor={$this->admin_id} "
            . "classKey={$classKey}");
        log_audit('Configuration', 'soft_delete_class', $classKey, "Soft-deleted class '{$classKey}'");

        $this->json_success(['message' => "Class '{$classKey}' soft-deleted. It can be restored later."]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/restore_class
    // Issue 7: Restore a soft-deleted class
    // ─────────────────────────────────────────────────────────────────────
    public function restore_class()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_restore_class');
        $school   = $this->school_name;
        $classKey = trim((string) $this->input->post('class_key', TRUE));

        if ($classKey === '') {
            return $this->json_error('class_key is required.');
        }

        // Read classes from Firestore school doc
        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        $classes = (is_array($fsSchool['classes'] ?? null)) ? $fsSchool['classes'] : [];
        if (empty($classes)) {
            return $this->json_error('No classes configured.');
        }

        $found = false;
        foreach ($classes as &$cls) {
            if (is_array($cls) && ($cls['key'] ?? '') === $classKey) {
                $cls['deleted']    = false;
                $cls['deleted_at'] = null;
                $found = true;
                break;
            }
        }
        unset($cls);

        if (!$found) {
            return $this->json_error('Class not found.');
        }

        $cleanClasses = array_values($classes);

        // Firestore (sole write target)
        $this->fs->update('schools', $this->fs->schoolId(), ['classes' => $cleanClasses, 'updatedAt' => date('c')]);

        log_audit('Configuration', 'restore_class', $classKey, "Restored class '{$classKey}'");

        $this->json_success(['message' => "Class '{$classKey}' restored."]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/seed_streams
    // Issue 6: Seed standard streams (Science, Commerce, Arts, General)
    // ─────────────────────────────────────────────────────────────────────
    public function seed_streams()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_seed_streams');
        $school = $this->school_name;

        $defaults = [
            'Science'  => ['key' => 'Science',  'label' => 'Science',  'enabled' => true],
            'Commerce' => ['key' => 'Commerce', 'label' => 'Commerce', 'enabled' => true],
            'Arts'     => ['key' => 'Arts',     'label' => 'Arts',     'enabled' => true],
            'General'  => ['key' => 'General',  'label' => 'General',  'enabled' => true],
        ];

        // BUG-028 Phase 1: concurrency hardening — shared 'streams' lock serializes
        // against delete_stream + save_stream. Firestore __updateTime precondition
        // catches cross-process races. Mirror of Phase 2 delete_stream canon.
        $lock = $this->_config_lock_acquire('streams');
        if ($lock === null) {
            log_message('error',
                "ACC_CONCURRENT_REJECTED endpoint=seed_streams schoolId={$this->school_id} "
                . "actor={$this->admin_id} reason=lock_timeout");
            return $this->json_error(
                'Another stream-management operation is in progress. Please retry in a few seconds.',
                409
            );
        }
        try {
            $schoolDocId = $this->fs->schoolId();
            $fsSchool    = $this->firebase->firestoreGet('schools', $schoolDocId);
            if (!is_array($fsSchool)) {
                return $this->json_error('School profile is not yet initialised.');
            }
            $existing = [];
            if (isset($fsSchool['streams']) && is_array($fsSchool['streams'])) {
                foreach ($fsSchool['streams'] as $k => $v) {
                    $existing[$k] = is_array($v) ? $v : (array) $v;
                }
            }
            $updateTime = (string) ($fsSchool['__updateTime'] ?? '');

            $added   = 0;
            $skipped = 0;

            foreach ($defaults as $key => $streamData) {
                if (isset($existing[$key])) {
                    $skipped++;
                    continue;
                }
                $existing[$key] = $streamData;
                $added++;
            }

            // BUG-028 Phase 1: CAS commit — refuse if another writer mutated the school
            // doc between our read and write. Caller is expected to refresh and retry on 409.
            $ops = [[
                'op'         => 'update',
                'collection' => 'schools',
                'docId'      => $schoolDocId,
                // 2026-05-27 — DOC-MERGE-FIX: emit updateMask so this PATCH
                // touches only the listed field paths. Without `merge=>true`,
                // commitBatch sends an `update` write without updateMask,
                // which Firestore REST treats as a full-document REPLACE —
                // wiping every other field on schools/{schoolId}.
                'merge'      => true,
                'data'       => [
                    'streams'   => $existing,
                    'updatedAt' => date('c'),
                ],
            ]];
            if ($updateTime !== '') {
                $ops[0]['precondition'] = ['updateTime' => $updateTime];
            }
            $committed = false;
            try {
                $committed = (bool) $this->firebase->firestoreCommitBatch($ops);
            } catch (\Throwable $e) {
                log_message('error',
                    "ACC_SEED_STREAMS_COMMIT_FAILED schoolId={$this->school_id} "
                    . "err=" . $e->getMessage());
            }
            if (!$committed) {
                log_message('error',
                    "ACC_CONCURRENT_REJECTED endpoint=seed_streams schoolId={$this->school_id} "
                    . "actor={$this->admin_id} reason=cas_failed");
                return $this->json_error(
                    'Another administrator updated school configuration during your request. '
                    . 'Please reload the page and retry.', 409);
            }

            log_audit('Configuration', 'seed_streams', $school, "Seeded {$added} stream(s) ({$skipped} already existed)");

            $this->json_success([
                'message' => "{$added} stream(s) seeded. {$skipped} already existed.",
                'streams' => $existing,
                'added'   => $added,
                'skipped' => $skipped,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'seed_streams error: ' . $e->getMessage());
            $this->json_error('Failed to seed streams. Contact administrator.');
        } finally {
            $this->_config_lock_release($lock);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/get_sections
    // ─────────────────────────────────────────────────────────────────────
    public function get_sections()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_sections');
        $school      = $this->school_name;
        $classKey    = trim((string) $this->input->post('class_key', TRUE));
        $sessionYear = trim((string) $this->input->post('session',   TRUE));

        if ($classKey === '') {
            return $this->json_error('class_key is required.');
        }

        if ($sessionYear === '') {
            $sessionYear = $this->session_year;
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $sessionYear)) {
            return $this->json_error('Invalid session format.');
        }

        $classNode = $this->_class_node_name($classKey);
        $sections  = [];

        // Read sections from Firestore
        $fsDocs = $this->fs->schoolWhere('sections', [
            ['className', '==', $classNode],
            ['session', '==', $sessionYear],
        ]);
        if (is_array($fsDocs) && !empty($fsDocs)) {
            foreach ($fsDocs as $doc) {
                // Firestore REST client returns docs as ['id' => ..., 'data' => [...]],
                // so the section field lives under $doc['data']['section'].
                $d = $doc['data'] ?? $doc;
                $sec = $d['section'] ?? '';
                if ($sec !== '') $sections[] = $sec;
            }
            sort($sections);
        }

        $this->json_success(['sections' => $sections, 'class_node' => $classNode]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/save_section
    // ─────────────────────────────────────────────────────────────────────
    public function save_section()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_section');
        $school        = $this->school_name;
        $classKey      = trim((string) $this->input->post('class_key', TRUE));
        $sectionLetter = strtoupper(trim((string) $this->input->post('section', TRUE)));
        $sessionYear   = trim((string) $this->input->post('session',   TRUE));

        if ($classKey === '') {
            return $this->json_error('class_key is required.');
        }

        if (!preg_match('/^[A-Z]$/', $sectionLetter)) {
            return $this->json_error('Section must be a single capital letter (A-Z).');
        }

        if ($sessionYear === '') {
            $sessionYear = $this->session_year;
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $sessionYear)) {
            return $this->json_error('Invalid session format.');
        }

        $classNode   = $this->_class_node_name($classKey);
        $sectionNode = "Section {$sectionLetter}";
        $path        = "Schools/{$school}/{$sessionYear}/{$classNode}/{$sectionNode}";

        // Check existence in Firestore
        $fsDocId = $this->fs->sectionDocId($classNode, $sectionLetter);
        $fsExists = is_array($this->fs->get('sections', $fsDocId));
        if ($fsExists) {
            return $this->json_error("{$classNode} / {$sectionNode} already exists in {$sessionYear}.");
        }

        $sectionData = ['created_at' => date('Y-m-d H:i:s')];

        // Firestore (sole write target)
        $this->fs->saveSection($classNode, $sectionLetter, $sectionData);

        log_audit('Configuration', 'save_section', "{$classNode}/{$sectionNode}", "Created section {$classNode} / {$sectionNode}");

        $this->json_success([
            'message'      => "{$classNode} / {$sectionNode} created.",
            'class_node'   => $classNode,
            'section_node' => $sectionNode,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/delete_section
    // ─────────────────────────────────────────────────────────────────────
    public function delete_section()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_delete_section');
        $school        = $this->school_name;
        $classKey      = trim((string) $this->input->post('class_key', TRUE));
        $sectionLetter = strtoupper(trim((string) $this->input->post('section', TRUE)));
        $sessionYear   = trim((string) $this->input->post('session',   TRUE));

        if ($classKey === '') {
            return $this->json_error('class_key is required.');
        }

        if (!preg_match('/^[A-Z]$/', $sectionLetter)) {
            return $this->json_error('Invalid section.');
        }

        if ($sessionYear === '') {
            $sessionYear = $this->session_year;
        }

        $classNode   = $this->_class_node_name($classKey);
        $sectionNode = "Section {$sectionLetter}";
        $path        = "Schools/{$school}/{$sessionYear}/{$classNode}/{$sectionNode}";
        $fsDocId     = $this->fs->sectionDocId($classNode, $sectionLetter);

        // Check existence in Firestore
        $fsSection = $this->fs->get('sections', $fsDocId);
        if (!is_array($fsSection)) {
            return $this->json_error('Section not found.');
        }

        // 2026-05-15 Phase 2 — extended referential-integrity check.
        // Was: students-only check. Now: students + feeDemands + attendance
        // + marks + timetables, scoped to this section. Prevents orphaning
        // historical/financial records that would silently reference a
        // section that no longer exists. Each per-collection query is
        // bounded at 200 rows (count is authoritative, not the rows).
        $sectionKey = "{$classNode}/{$sectionNode}";
        $deps = $this->_dep_count_safe([
            [
                'key'        => 'students',
                'collection' => 'students',
                'filters'    => [['sectionKey', '==', $sectionKey], ['status', '==', 'Active']],
                'label'      => 'active student(s)',
            ],
            [
                'key'        => 'feeDemands',
                'collection' => 'feeDemands',
                'filters'    => [['sectionKey', '==', $sectionKey], ['session', '==', $sessionYear]],
                'label'      => 'fee demand(s)',
            ],
            [
                'key'        => 'attendance',
                'collection' => 'attendance',
                'filters'    => [['sectionKey', '==', $sectionKey], ['session', '==', $sessionYear]],
                'label'      => 'attendance record(s)',
            ],
            [
                'key'        => 'marks',
                'collection' => 'marks',
                'filters'    => [['sectionKey', '==', $sectionKey], ['session', '==', $sessionYear]],
                'label'      => 'marks record(s)',
            ],
            [
                'key'        => 'timetables',
                'collection' => 'timetables',
                'filters'    => [['sectionKey', '==', $sectionKey], ['session', '==', $sessionYear]],
                'label'      => 'timetable entry/entries',
            ],
            [
                'key'        => 'subjectAssignments',
                'collection' => 'subjectAssignments',
                'filters'    => [['sectionKey', '==', $sectionKey], ['session', '==', $sessionYear]],
                'label'      => 'subject assignment(s)',
            ],
        ]);
        if (!empty($deps['has_any'])) {
            $this->_log_dep_block('delete_section', $sectionKey, $deps);
            $this->_json_error_with_data(
                'Cannot delete section ' . $sectionKey . ': '
                . implode(', ', $deps['human'])
                . '. Migrate or remove dependent data first.',
                [
                    'dependencies' => $deps['by_key'],
                    'sectionKey'   => $sectionKey,
                    'session'      => $sessionYear,
                ],
                409
            );
        }

        // Firestore (sole write target)
        $this->fs->remove('sections', $fsDocId);

        log_message('error',
            "ACC_SECTION_DELETED schoolId={$this->school_id} actor={$this->admin_id} "
            . "sectionKey={$sectionKey} session={$sessionYear}");
        log_audit('Configuration', 'delete_section', $sectionKey, "Deleted section {$sectionKey} (session={$sessionYear})");

        $this->json_success(['message' => "{$classNode} / {$sectionNode} deleted."]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/get_all_sections
    // Returns sections for ALL classes in the given session (bulk view)
    // For stream-enabled classes (11, 12), sections are grouped by stream.
    // ─────────────────────────────────────────────────────────────────────
    public function get_all_sections()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_all_sections');
        $school      = $this->school_name;
        $sessionYear = trim((string) $this->input->post('session', TRUE));

        if ($sessionYear === '') {
            $sessionYear = $this->session_year;
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $sessionYear)) {
            return $this->json_error('Invalid session format.');
        }

        // Read classes and streams from Firestore school doc
        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        if (!is_array($fsSchool)) $fsSchool = [];
        $classes = (is_array($fsSchool['classes'] ?? null)) ? $fsSchool['classes'] : [];
        $classes = array_values(array_filter($classes, function ($c) {
            return is_array($c) && empty($c['deleted']);
        }));

        // If classes empty, enumerate from Firestore sections collection
        if (empty($classes)) {
            $fsSectionsAll = $this->fs->schoolWhere('sections', [['session', '==', $sessionYear]]);
            $classNodes = [];
            if (is_array($fsSectionsAll)) {
                foreach ($fsSectionsAll as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $cn = $d['className'] ?? '';
                    if ($cn !== '' && strpos($cn, 'Class ') === 0) {
                        $classNodes[$cn] = true;
                    }
                }
            }
            $order = 0;
            foreach (array_keys($classNodes) as $nodeKey) {
                $raw   = trim(str_replace('Class ', '', $nodeKey));
                $lower = strtolower($raw);
                $isF   = in_array($lower, ['nursery', 'lkg', 'ukg', 'playgroup'], true);
                $key   = $isF ? strtoupper($raw) : (string)(int) preg_replace('/\D/', '', $raw);
                if ($key === '' || $key === '0') continue;
                $classes[] = [
                    'key'             => $key,
                    'label'           => $nodeKey,
                    'order'           => $order++,
                    'streams_enabled' => in_array($key, ['11', '12'], true),
                ];
            }
        }

        $streams = (is_array($fsSchool['streams'] ?? null)) ? $fsSchool['streams'] : [];
        if (!is_array($streams)) $streams = [];
        $enabledStreams = [];
        foreach ($streams as $sk => $sv) {
            if (is_array($sv) && !empty($sv['enabled'])) {
                $enabledStreams[] = $sv['key'] ?? $sk;
            }
        }

        // Pre-fetch ALL sections from Firestore for this school+session
        $fsSections = [];
        $fsDocs = $this->fs->schoolWhere('sections', [
            ['session', '==', $sessionYear],
        ]);
        if (is_array($fsDocs)) {
            foreach ($fsDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $cn = $d['className'] ?? '';
                $sn = $d['section'] ?? '';
                if ($cn !== '' && $sn !== '') {
                    $fsSections[$cn][] = $sn;
                }
            }
        }

        $result = [];
        foreach ($classes as $cls) {
            $classNode       = $this->_class_node_name($cls['key']);
            $streamsEnabled  = !empty($cls['streams_enabled']);

            // Read sections from Firestore
            $rawSections = $fsSections[$classNode] ?? [];

            $sections        = [];
            $streamSections  = []; // { "Science": ["A","B"], "Commerce": ["A"] }

            foreach ($rawSections as $k) {
                // Normalize: may already be "Section A" or just "A"
                if (strpos($k, 'Section ') === 0) {
                    $sectionLabel = str_replace('Section ', '', $k);
                } else {
                    $sectionLabel = $k;
                }

                if ($streamsEnabled && !empty($enabledStreams)) {
                    $matched = false;
                    foreach ($enabledStreams as $stm) {
                        if (strpos($sectionLabel, $stm . ' ') === 0) {
                            $letter = trim(substr($sectionLabel, strlen($stm)));
                            if (!isset($streamSections[$stm])) $streamSections[$stm] = [];
                            $streamSections[$stm][] = $letter;
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        $sections[] = $sectionLabel;
                    }
                } else {
                    $sections[] = $sectionLabel;
                }
            }
            sort($sections);
            foreach ($streamSections as &$letters) sort($letters);
            unset($letters);

            $entry = [
                'key'             => $cls['key'],
                'label'           => $cls['label'] ?? $classNode,
                'node'            => $classNode,
                'sections'        => $sections,
                'streams_enabled' => $streamsEnabled,
            ];
            if ($streamsEnabled) {
                $entry['stream_sections'] = $streamSections;
            }
            $result[] = $entry;
        }

        $this->json_success([
            'classes' => $result,
            'session' => $sessionYear,
            'streams' => $enabledStreams,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/bulk_save_sections
    // Accepts JSON: { session, changes: [ {class_key, add:["A","B"], remove:["C"]} ] }
    // ─────────────────────────────────────────────────────────────────────
    public function bulk_save_sections()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_bulk_save_sections');
        $school      = $this->school_name;
        $sessionYear = trim((string) $this->input->post('session', TRUE));
        $changesRaw  = $this->input->post('changes', TRUE);

        if ($sessionYear === '') {
            $sessionYear = $this->session_year;
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $sessionYear)) {
            return $this->json_error('Invalid session format.');
        }

        $changes = is_string($changesRaw) ? json_decode($changesRaw, true) : $changesRaw;
        if (!is_array($changes) || empty($changes)) {
            return $this->json_error('No changes provided.');
        }

        $created  = 0;
        $removed  = 0;
        $skipped  = [];   // legacy compat — human-readable strings
        $failed   = [];   // 2026-05-15 Phase 2 — structured rows
        $now      = date('Y-m-d H:i:s');

        // 2026-05-15 Phase 2 — every per-row write is wrapped in a
        // try/catch so a single failure does not abort the whole batch.
        // Each failure is captured as a structured `failed[]` entry
        // ({class_key, section, action, reason}); the existing
        // `skipped[]` array remains populated with the legacy
        // human-readable strings for backward compatibility.
        foreach ($changes as $ch) {
            if (!is_array($ch) || empty($ch['class_key'])) continue;
            $classKey  = (string) $ch['class_key'];
            $classNode = $this->_class_node_name($classKey);

            // Add sections — supports both plain ("A") and stream-based ("Science A")
            foreach (($ch['add'] ?? []) as $sectionLabel) {
                $sectionLabel = trim((string) $sectionLabel);
                if (!preg_match('/^(?:[A-Za-z]+(?: [A-Za-z]+)* )?[A-Z]$/', $sectionLabel)) {
                    $failed[] = [
                        'class_key' => $classKey,
                        'section'   => $sectionLabel,
                        'action'    => 'add',
                        'reason'    => 'invalid_format',
                    ];
                    continue;
                }
                try {
                    $fsDocId = $this->fs->sectionDocId($classNode, $sectionLabel);
                    if (is_array($this->fs->get('sections', $fsDocId))) {
                        // Already exists — neither created nor failed; record as skipped.
                        $skipped[] = "{$classNode} Section {$sectionLabel} (already exists)";
                        $failed[]  = [
                            'class_key' => $classKey,
                            'section'   => $sectionLabel,
                            'action'    => 'add',
                            'reason'    => 'already_exists',
                        ];
                        continue;
                    }
                    $this->fs->saveSection($classNode, $sectionLabel, ['created_at' => $now]);
                    $created++;
                } catch (\Throwable $e) {
                    $failed[] = [
                        'class_key' => $classKey,
                        'section'   => $sectionLabel,
                        'action'    => 'add',
                        'reason'    => $e->getMessage(),
                    ];
                    log_message('error',
                        "ACC_BULK_ROW_FAILED endpoint=bulk_save_sections schoolId={$this->school_id} "
                        . "action=add class={$classNode} section={$sectionLabel} err=" . $e->getMessage());
                }
            }

            // Remove sections
            foreach (($ch['remove'] ?? []) as $sectionLabel) {
                $sectionLabel = trim((string) $sectionLabel);
                if (!preg_match('/^(?:[A-Za-z]+(?: [A-Za-z]+)* )?[A-Z]$/', $sectionLabel)) {
                    $failed[] = [
                        'class_key' => $classKey,
                        'section'   => $sectionLabel,
                        'action'    => 'remove',
                        'reason'    => 'invalid_format',
                    ];
                    continue;
                }
                try {
                    $fsDocId = $this->fs->sectionDocId($classNode, $sectionLabel);
                    $fsDoc   = $this->fs->get('sections', $fsDocId);
                    if (!is_array($fsDoc)) {
                        // Already gone — not an error, but record for visibility.
                        $skipped[] = "{$classNode} Section {$sectionLabel} (does not exist)";
                        $failed[]  = [
                            'class_key' => $classKey,
                            'section'   => $sectionLabel,
                            'action'    => 'remove',
                            'reason'    => 'not_found',
                        ];
                        continue;
                    }
                    $sectionKey = "{$classNode}/Section {$sectionLabel}";
                    $stuDocs    = $this->fs->schoolWhere('students', [
                        ['sectionKey', '==', $sectionKey],
                        ['status', '==', 'Active'],
                    ]);
                    if (!empty($stuDocs)) {
                        $skipped[] = "{$classNode} Section {$sectionLabel} (has students)";
                        $failed[]  = [
                            'class_key' => $classKey,
                            'section'   => $sectionLabel,
                            'action'    => 'remove',
                            'reason'    => 'has_active_students',
                            'count'     => count($stuDocs),
                        ];
                        log_message('error',
                            "ACC_ORPHAN_PREVENTED endpoint=bulk_save_sections "
                            . "schoolId={$this->school_id} actor={$this->admin_id} "
                            . "sectionKey={$sectionKey} students=" . count($stuDocs));
                        continue;
                    }
                    $this->fs->remove('sections', $fsDocId);
                    $removed++;
                } catch (\Throwable $e) {
                    $failed[] = [
                        'class_key' => $classKey,
                        'section'   => $sectionLabel,
                        'action'    => 'remove',
                        'reason'    => $e->getMessage(),
                    ];
                    log_message('error',
                        "ACC_BULK_ROW_FAILED endpoint=bulk_save_sections schoolId={$this->school_id} "
                        . "action=remove class={$classNode} section={$sectionLabel} err=" . $e->getMessage());
                }
            }
        }

        $failedCount = count($failed);
        $msg = "Done: {$created} created, {$removed} removed.";
        if ($failedCount > 0) {
            $msg .= " {$failedCount} row(s) failed or skipped — see failed[] for details.";
            log_message('error',
                "ACC_BULK_PARTIAL_FAILURE endpoint=bulk_save_sections schoolId={$this->school_id} "
                . "actor={$this->admin_id} created={$created} removed={$removed} failed={$failedCount}");
        }
        if (!empty($skipped)) {
            $msg .= ' Skipped: ' . implode(', ', $skipped);
        }

        log_audit('Configuration', 'bulk_save_sections', $sessionYear, "Bulk section update (session={$sessionYear}): {$created} created, {$removed} removed, {$failedCount} failed");

        $this->json_success([
            'message'         => $msg,
            'created'         => $created,
            'removed'         => $removed,
            'skipped'         => $skipped,
            'failed'          => $failed,
            'failed_count'    => $failedCount,
            'partial_failure' => $failedCount > 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/get_subjects
    // ─────────────────────────────────────────────────────────────────────
    public function get_subjects()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_subjects');

        try {
            $school   = $this->school_name;
            $classKey = trim((string) $this->input->post('class_key', TRUE));

            if ($classKey === '') {
                return $this->json_error('class_key is required.');
            }

            $numKey   = $this->_numeric_class_key($classKey);
            $subjects = [];

            // Read subjects from Firestore
            $fsRows = $this->fs->schoolWhere(
                'subjects',
                [['classKey', '==', (string) $numKey]]
            );

            if (!empty($fsRows)) {
                foreach ($fsRows as $row) {
                    $d = $row['data'] ?? [];
                    if (!is_array($d)) continue;
                    $subjects[] = [
                        'code'     => (string) ($d['subjectCode'] ?? $d['id'] ?? ''),
                        'name'     => (string) ($d['name'] ?? $d['subject_name'] ?? ''),
                        'category' => (string) ($d['category'] ?? 'Core'),
                        'stream'   => (string) ($d['stream'] ?? 'common'),
                    ];
                }
                usort($subjects, function ($a, $b) {
                    return strnatcmp($a['code'], $b['code']);
                });
            }

            $this->json_success([
                'subjects'     => $subjects,
                'pattern_type' => null,
                'source'       => 'firestore',
            ]);
        } catch (\Exception $e) {
            log_message('error', 'get_subjects failed: ' . $e->getMessage());
            $this->json_error('Failed to load subjects. Contact administrator.');
        }
    }

    /**
     * GET/POST  /school_config/get_all_subjects
     * Returns ALL unique subjects across all classes (deduped by name).
     * Used by the staff form for the "Subjects this teacher can teach" picker.
     */
    public function get_all_subjects()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_all_subjects');

        try {
            $school = $this->school_name;
            $unique = [];

            // 1. Firestore FIRST (primary)
            $fsRows = $this->fs->schoolWhere('subjects', []);
            if (!empty($fsRows)) {
                foreach ($fsRows as $row) {
                    $d = $row['data'] ?? [];
                    if (!is_array($d)) continue;
                    $name = trim((string) ($d['name'] ?? $d['subject_name'] ?? ''));
                    if ($name === '') continue;
                    $key = strtolower($name);
                    if (isset($unique[$key])) continue;
                    $unique[$key] = [
                        'code'     => (string) ($d['subjectCode'] ?? $d['id'] ?? $name),
                        'name'     => $name,
                        'category' => (string) ($d['category'] ?? 'Core'),
                    ];
                }
            }

            // 2. Also include ALL subjects from the master curriculum catalog
            // (so staff form shows every possible subject, not just class-assigned ones)
            $allCurriculumSubjects = [
                ['name'=>'English','category'=>'Language'],
                ['name'=>'Hindi','category'=>'Language'],
                ['name'=>'Sanskrit','category'=>'Language'],
                ['name'=>'Mathematics','category'=>'Core'],
                ['name'=>'Science','category'=>'Core'],
                ['name'=>'Social Science','category'=>'Core'],
                ['name'=>'Computer Science','category'=>'Core'],
                ['name'=>'General Knowledge','category'=>'Additional'],
                ['name'=>'Physical Education','category'=>'Co-curricular'],
                ['name'=>'Art Education','category'=>'Additional'],
                ['name'=>'Art & Craft','category'=>'Additional'],
                ['name'=>'Moral Science','category'=>'Additional'],
                ['name'=>'Environmental Studies','category'=>'Core'],
                ['name'=>'Physics','category'=>'Core'],
                ['name'=>'Chemistry','category'=>'Core'],
                ['name'=>'Biology','category'=>'Core'],
                ['name'=>'History','category'=>'Core'],
                ['name'=>'Geography','category'=>'Core'],
                ['name'=>'Political Science','category'=>'Core'],
                ['name'=>'Economics','category'=>'Core'],
                ['name'=>'Accountancy','category'=>'Core'],
                ['name'=>'Business Studies','category'=>'Core'],
                ['name'=>'Computer Applications','category'=>'Elective'],
                ['name'=>'Information Technology','category'=>'Elective'],
                ['name'=>'Informatics Practices','category'=>'Elective'],
                ['name'=>'Home Science','category'=>'Elective'],
                ['name'=>'Psychology','category'=>'Elective'],
                ['name'=>'Sociology','category'=>'Elective'],
                ['name'=>'Fine Arts','category'=>'Elective'],
                ['name'=>'Music','category'=>'Elective'],
                ['name'=>'Biotechnology','category'=>'Elective'],
                ['name'=>'Entrepreneurship','category'=>'Elective'],
            ];
            foreach ($allCurriculumSubjects as $s) {
                $key = strtolower($s['name']);
                if (!isset($unique[$key])) {
                    $unique[$key] = [
                        'code'     => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $s['name']), 0, 4)),
                        'name'     => $s['name'],
                        'category' => $s['category'],
                    ];
                }
            }

            $subjects = array_values($unique);
            usort($subjects, fn($a, $b) => strcasecmp($a['name'], $b['name']));

            $this->json_success([
                'subjects' => $subjects,
                'count'    => count($subjects),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'get_all_subjects failed: ' . $e->getMessage());
            $this->json_error('Failed to load subjects. Contact administrator.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/get_suggested_subjects
    // Returns subjects from Subject Master_List grouped by category
    // for the school's configured board + mapped class range.
    // ─────────────────────────────────────────────────────────────────────
    public function get_suggested_subjects()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_suggested_subjects');

        try {
            $school   = $this->school_name;
            $classKey = trim((string) $this->input->post('class_key', TRUE));

            if ($classKey === '') {
                return $this->json_error('class_key is required.');
            }

            // 1. Read school's board config from Firestore
            $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
            $board = (is_array($fsSchool['board_config'] ?? null)) ? $fsSchool['board_config'] : [];
            $boardType = trim($board['type'] ?? '');
            if ($boardType === '') {
                return $this->json_error('No board configured. Set your board in the Board tab first.');
            }

            // 2. Map class key to master list range
            $classRange = $this->_class_key_to_range($classKey);
            if ($classRange === '') {
                return $this->json_error("Cannot determine class range for '{$classKey}'.");
            }

            // 3. Read subject master list from Firestore global collection
            // Doc ID convention: {boardType}_{pattern}_{classRange}
            // Try to find docs for this board type using Firestore query
            $masterData = null;
            $pattern = '';
            $masterDoc = $this->firebase->firestoreGet('subjectMasterList', "{$boardType}_{$classRange}");
            if (is_array($masterDoc) && !empty($masterDoc)) {
                $masterData = $masterDoc;
                $pattern = $masterDoc['pattern'] ?? 'default';
            }

            if (!is_array($masterData) || empty($masterData)) {
                // Master list not yet migrated to Firestore — return helpful error
                return $this->json_error("No subject master list found in Firestore for {$boardType} / range {$classRange}. Use 'Load Defaults' instead.");
            }

            // 5. Detect if this range has stream-specific subjects (11-12)
            $hasStreams   = isset($masterData['Streams']) && is_array($masterData['Streams']);
            $streamNames = [];

            // 6. Build grouped response — common subjects first
            $groups = [];
            foreach ($masterData as $groupName => $groupData) {
                if (in_array($groupName, ['Assessment', 'rules', 'Streams', '_created'], true)) continue;
                if (!is_array($groupData)) continue;

                $compulsory = !empty($groupData['compulsory']);
                $options    = $groupData['options'] ?? $groupData;
                if (!is_array($options)) continue;

                $subjects = [];
                foreach ($options as $subKey => $subjectName) {
                    if (!is_string($subjectName) || trim($subjectName) === '') continue;
                    $subjects[] = trim($subjectName);
                }
                if (empty($subjects)) continue;

                // Derive category from group name
                $category = $compulsory ? 'Core' : 'Elective';
                if (stripos($groupName, 'Language') !== false) $category = 'Language';
                if (stripos($groupName, 'Vocational') !== false) $category = 'Vocational';
                if (stripos($groupName, 'Additional') !== false) $category = 'Additional';

                $groups[] = [
                    'group'      => $groupName,
                    'compulsory' => $compulsory,
                    'category'   => $category,
                    'stream'     => 'common',
                    'subjects'   => $subjects,
                ];
            }

            // 7. Process stream-specific subjects (e.g. Science, Commerce, Arts)
            if ($hasStreams) {
                foreach ($masterData['Streams'] as $streamName => $streamData) {
                    if (!is_array($streamData)) continue;
                    $streamNames[] = $streamName;

                    foreach ($streamData as $sgName => $sgData) {
                        if (!is_array($sgData)) continue;

                        $compulsory = !empty($sgData['compulsory']);
                        $options    = $sgData['options'] ?? $sgData;
                        if (!is_array($options)) continue;

                        $subjects = [];
                        foreach ($options as $subKey => $subVal) {
                            if (is_string($subVal) && trim($subVal) !== '') {
                                $subjects[] = trim($subVal);
                            }
                        }
                        if (empty($subjects)) continue;

                        $category = $compulsory ? 'Core' : 'Elective';
                        if (stripos($sgName, 'Language') !== false) $category = 'Language';
                        if (stripos($sgName, 'Vocational') !== false) $category = 'Vocational';
                        if (stripos($sgName, 'Additional') !== false) $category = 'Additional';

                        $groups[] = [
                            'group'      => $streamName . ' — ' . $sgName,
                            'compulsory' => $compulsory,
                            'category'   => $category,
                            'stream'     => $streamName,
                            'subjects'   => $subjects,
                        ];
                    }
                }
            }

            if (empty($groups)) {
                return $this->json_error("No subject groups found for range {$classRange}.");
            }

            $this->json_success([
                'board'      => $boardType,
                'pattern'    => $pattern,
                'classRange' => $classRange,
                'hasStreams'  => $hasStreams,
                'streams'    => $streamNames,
                'groups'     => $groups,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'get_suggested_subjects failed: ' . $e->getMessage());
            $this->json_error('Failed to load suggestions. Contact administrator.');
        }
    }

    /**
     * Map a class key (e.g. "9th", "Nursery") to the master list range.
     */
    private function _class_key_to_range(string $classKey): string
    {
        $lower = strtolower(trim($classKey));
        $foundational = [
            'playgroup' => 'Playgroup', 'play' => 'Playgroup',
            'nursery'   => 'Nursery',
            'lkg'       => 'LKG',
            'ukg'       => 'UKG',
        ];
        foreach ($foundational as $kw => $range) {
            if (strpos($lower, $kw) !== false) return $range;
        }
        preg_match('/\d+/', $classKey, $m);
        $n = isset($m[0]) ? (int) $m[0] : 0;
        if ($n >= 1 && $n <= 5)  return '1-5';
        if ($n >= 6 && $n <= 8)  return '6-8';
        if ($n >= 9 && $n <= 10) return '9-10';
        if ($n >= 11 && $n <= 12) return '11-12';
        return '';
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/save_subject
    // ─────────────────────────────────────────────────────────────────────
    public function save_subject()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_subject');
        $school   = $this->school_name;
        $classKey = trim((string) $this->input->post('class_key', TRUE));
        $name     = trim((string) $this->input->post('name',      TRUE));
        $category = trim((string) $this->input->post('category',  TRUE)) ?: 'Core';
        $stream   = trim((string) $this->input->post('stream',    TRUE)) ?: 'common';
        $code     = trim((string) $this->input->post('code',      TRUE));

        if ($classKey === '' || $name === '') {
            return $this->json_error('class_key and name are required.');
        }

        // BUG-029: byte-length cap to prevent Firestore DocTooLarge
        if (strlen($name) > 200) {
            return $this->json_error('Subject name exceeds 200 characters.');
        }

        // Issue 8: Added Assessment category
        $validCategories = ['Core', 'Elective', 'Additional', 'Language', 'Vocational', 'Assessment'];
        if (!in_array($category, $validCategories, true)) {
            $category = 'Core';
        }

        $numKey = $this->_numeric_class_key($classKey);

        // Duplicate-subject guard (Firestore only).
        // Reads the row's data payload (`$row['data']`), not the row wrapper —
        // the earlier version looked at the wrapper and silently never found
        // any duplicates. Handles both the canonical camelCase shape and the
        // legacy snake_case one for rows written before the shape was unified.
        $existingFs = $this->fs->schoolWhere('subjects', [['classKey', '==', (string) $numKey]]);
        $existing = [];
        if (is_array($existingFs)) {
            foreach ($existingFs as $row) {
                $d = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
                $c = (string) ($d['subjectCode'] ?? $d['subject_code'] ?? $d['code'] ?? '');
                if ($c !== '') $existing[$c] = $d;
            }
        }
        foreach ($existing as $existCode => $existSub) {
            $existName = (string) ($existSub['name'] ?? $existSub['subject_name'] ?? '');
            if ($existName !== '' && strtolower(trim($existName)) === strtolower($name) && $existCode !== $code) {
                return $this->json_error("Subject '{$name}' already exists in this class.");
            }
        }

        // Auto-generate code if not provided
        if ($code === '') {
            $count = is_array($existing)
                ? count(array_filter(array_keys($existing), function($k) { return $k !== 'pattern_type'; }))
                : 0;

            if (is_numeric($numKey)) {
                $code = (string) ((int) $numKey * 100 + $count + 1);
            } else {
                $prefix = strtoupper(substr($numKey, 0, 3));
                $code   = $prefix . str_pad($count + 1, 2, '0', STR_PAD_LEFT);
            }
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $code)) {
            return $this->json_error('Invalid subject code.');
        }

        // Firestore (sole write target). Canonical camelCase shape; readers
        // in this controller and the Subjects/Staff modules all use these field names.
        $this->fs->setEntity('subjects', "{$numKey}_{$code}", [
            'classKey'    => (string) $numKey,
            'subjectCode' => $code,
            'name'        => $name,
            'category'    => $category,
            'stream'      => $stream,
        ]);

        log_audit('Configuration', 'save_subject', "{$numKey}_{$code}", "Saved subject '{$name}' (classKey={$numKey}, code={$code})");

        $this->json_success(['message' => "Subject '{$name}' saved.", 'code' => $code]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/delete_subject
    // ─────────────────────────────────────────────────────────────────────
    public function delete_subject()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_delete_subject');
        $school   = $this->school_name;
        $classKey = trim((string) $this->input->post('class_key', TRUE));
        $code     = trim((string) $this->input->post('code',      TRUE));

        if ($classKey === '' || $code === '') {
            return $this->json_error('class_key and code are required.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $code)) {
            return $this->json_error('Invalid subject code.');
        }

        $numKey = $this->_numeric_class_key($classKey);
        $entityId = "{$numKey}_{$code}";

        // 2026-05-15 Phase 2 — referential integrity check. Block deletion
        // when active subjectAssignments reference this subject, otherwise
        // the Teacher app's auth gate (MY_Controller::_get_teacher_assignments)
        // would surface orphaned assignments and let teachers post against
        // a phantom subject. Cap the surfaced teacher list at 25 to keep
        // the response payload small; the count is the authoritative figure.
        $deps = $this->_dep_count_safe([
            [
                'key'        => 'subjectAssignments',
                'collection' => 'subjectAssignments',
                'filters'    => [
                    ['classKey',    '==', $numKey],
                    ['subjectCode', '==', $code],
                ],
                'label'      => 'active teacher assignment(s)',
            ],
        ]);
        if (!empty($deps['has_any'])) {
            $this->_log_dep_block('delete_subject', $entityId, $deps);
            // Surface up to 25 dependent teacher identifiers so the operator
            // knows who to reassign before retrying. Best-effort: failures
            // here are non-fatal (count is already authoritative).
            $dependentTeachers = [];
            try {
                $rows = $this->fs->schoolWhere('subjectAssignments', [
                    ['classKey',    '==', $numKey],
                    ['subjectCode', '==', $code],
                ], null, 'ASC', 25);
                foreach ((array) $rows as $r) {
                    $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                    $tid = (string) ($d['teacherId'] ?? '');
                    if ($tid === '') continue;
                    $dependentTeachers[] = [
                        'teacherId'   => $tid,
                        'teacherName' => (string) ($d['teacherName'] ?? ''),
                        'sectionKey'  => (string) ($d['sectionKey']  ?? ''),
                    ];
                }
            } catch (\Throwable $_) { /* count already captured above */ }
            return $this->json_error(
                "Cannot delete subject {$code}: it is currently assigned to "
                . $deps['by_key']['subjectAssignments'] . " teacher(s). "
                . "Reassign or remove those assignments first.",
                409
            );
        }

        // Firestore (sole write target)
        $this->fs->removeEntity('subjects', $entityId);

        log_message('error',
            "ACC_SUBJECT_DELETED schoolId={$this->school_id} actor={$this->admin_id} "
            . "classKey={$numKey} subjectCode={$code}");
        log_audit('Configuration', 'delete_subject', $entityId, "Deleted subject {$entityId}");

        $this->json_success(['message' => 'Subject deleted.']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/get_default_subjects
    // Returns a built-in foundational subject list for a class level.
    // No Firebase master list dependency — works out of the box.
    // ─────────────────────────────────────────────────────────────────────
    public function get_default_subjects()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_default_subjects');

        $classKey = trim((string) $this->input->post('class_key', TRUE));
        if ($classKey === '') {
            return $this->json_error('class_key is required.');
        }

        $range = $this->_class_key_to_range($classKey);

        // ── Comprehensive Indian curriculum defaults ──────────────────
        $curriculum = [
            'Playgroup' => [
                'common' => [
                    ['name'=>'Rhymes & Songs',        'category'=>'Core'],
                    ['name'=>'Story Time',            'category'=>'Core'],
                    ['name'=>'Drawing & Colouring',   'category'=>'Core'],
                    ['name'=>'Free Play & Motor Skills','category'=>'Core'],
                    ['name'=>'Number Fun',            'category'=>'Core'],
                    ['name'=>'Language Development',   'category'=>'Language'],
                ],
            ],
            'Nursery' => [
                'common' => [
                    ['name'=>'English',               'category'=>'Language'],
                    ['name'=>'Hindi',                 'category'=>'Language'],
                    ['name'=>'Mathematics',            'category'=>'Core'],
                    ['name'=>'Environmental Studies',  'category'=>'Core'],
                    ['name'=>'Art & Craft',            'category'=>'Core'],
                    ['name'=>'Rhymes & Music',         'category'=>'Core'],
                    ['name'=>'Physical Activity',      'category'=>'Assessment'],
                ],
            ],
            'LKG' => [
                'common' => [
                    ['name'=>'English',               'category'=>'Language'],
                    ['name'=>'Hindi',                 'category'=>'Language'],
                    ['name'=>'Mathematics',            'category'=>'Core'],
                    ['name'=>'Environmental Studies',  'category'=>'Core'],
                    ['name'=>'Art & Craft',            'category'=>'Core'],
                    ['name'=>'Rhymes & Music',         'category'=>'Core'],
                    ['name'=>'General Knowledge',      'category'=>'Core'],
                    ['name'=>'Physical Activity',      'category'=>'Assessment'],
                ],
            ],
            'UKG' => [
                'common' => [
                    ['name'=>'English',               'category'=>'Language'],
                    ['name'=>'Hindi',                 'category'=>'Language'],
                    ['name'=>'Mathematics',            'category'=>'Core'],
                    ['name'=>'Environmental Studies',  'category'=>'Core'],
                    ['name'=>'Art & Craft',            'category'=>'Core'],
                    ['name'=>'General Knowledge',      'category'=>'Core'],
                    ['name'=>'Computer Awareness',     'category'=>'Core'],
                    ['name'=>'Physical Activity',      'category'=>'Assessment'],
                ],
            ],
            '1-5' => [
                'common' => [
                    ['name'=>'English',               'category'=>'Language'],
                    ['name'=>'Hindi',                 'category'=>'Language'],
                    ['name'=>'Mathematics',            'category'=>'Core'],
                    ['name'=>'Environmental Studies',  'category'=>'Core'],
                    ['name'=>'General Knowledge',      'category'=>'Core'],
                    ['name'=>'Computer Science',       'category'=>'Core'],
                    ['name'=>'Art & Craft',            'category'=>'Additional'],
                    ['name'=>'Moral Science',          'category'=>'Additional'],
                    ['name'=>'Physical Education',     'category'=>'Assessment'],
                ],
            ],
            '6-8' => [
                'common' => [
                    ['name'=>'English',               'category'=>'Language'],
                    ['name'=>'Hindi',                 'category'=>'Language'],
                    ['name'=>'Sanskrit',              'category'=>'Language'],
                    ['name'=>'Mathematics',            'category'=>'Core'],
                    ['name'=>'Science',                'category'=>'Core'],
                    ['name'=>'Social Science',         'category'=>'Core'],
                    ['name'=>'Computer Science',       'category'=>'Core'],
                    ['name'=>'General Knowledge',      'category'=>'Additional'],
                    ['name'=>'Art Education',          'category'=>'Additional'],
                    ['name'=>'Physical Education',     'category'=>'Assessment'],
                    ['name'=>'Moral Science',          'category'=>'Additional'],
                ],
            ],
            '9-10' => [
                'common' => [
                    ['name'=>'English',                   'category'=>'Language'],
                    ['name'=>'Hindi',                     'category'=>'Language'],
                    ['name'=>'Mathematics',                'category'=>'Core'],
                    ['name'=>'Science',                    'category'=>'Core'],
                    ['name'=>'Social Science',             'category'=>'Core'],
                    ['name'=>'Sanskrit',                  'category'=>'Language'],
                    ['name'=>'Computer Applications',     'category'=>'Elective'],
                    ['name'=>'Information Technology',     'category'=>'Elective'],
                    ['name'=>'Home Science',               'category'=>'Elective'],
                    ['name'=>'Art Education',              'category'=>'Additional'],
                    ['name'=>'Physical Education',         'category'=>'Assessment'],
                ],
            ],
            '11-12' => [
                'common' => [
                    ['name'=>'English',                   'category'=>'Language'],
                    ['name'=>'Hindi',                     'category'=>'Language'],
                    ['name'=>'Physical Education',         'category'=>'Assessment'],
                ],
                'Science' => [
                    ['name'=>'Physics',                    'category'=>'Core'],
                    ['name'=>'Chemistry',                  'category'=>'Core'],
                    ['name'=>'Mathematics',                'category'=>'Core'],
                    ['name'=>'Biology',                    'category'=>'Core'],
                    ['name'=>'Computer Science',           'category'=>'Elective'],
                    ['name'=>'Informatics Practices',      'category'=>'Elective'],
                    ['name'=>'Biotechnology',              'category'=>'Elective'],
                    ['name'=>'Physical Education',         'category'=>'Elective'],
                ],
                'Commerce' => [
                    ['name'=>'Accountancy',                'category'=>'Core'],
                    ['name'=>'Business Studies',           'category'=>'Core'],
                    ['name'=>'Economics',                  'category'=>'Core'],
                    ['name'=>'Mathematics',                'category'=>'Elective'],
                    ['name'=>'Informatics Practices',      'category'=>'Elective'],
                    ['name'=>'Entrepreneurship',           'category'=>'Elective'],
                    ['name'=>'Physical Education',         'category'=>'Elective'],
                ],
                'Arts' => [
                    ['name'=>'History',                    'category'=>'Core'],
                    ['name'=>'Geography',                  'category'=>'Core'],
                    ['name'=>'Political Science',          'category'=>'Core'],
                    ['name'=>'Economics',                  'category'=>'Elective'],
                    ['name'=>'Psychology',                 'category'=>'Elective'],
                    ['name'=>'Sociology',                  'category'=>'Elective'],
                    ['name'=>'Home Science',               'category'=>'Elective'],
                    ['name'=>'Fine Arts',                  'category'=>'Elective'],
                    ['name'=>'Music',                      'category'=>'Elective'],
                    ['name'=>'Physical Education',         'category'=>'Elective'],
                ],
            ],
        ];

        $defaults = $curriculum[$range] ?? [];
        if (empty($defaults)) {
            return $this->json_error("No default subjects available for class range '{$range}'.");
        }

        $hasStreams = isset($defaults['Science']) || isset($defaults['Commerce']) || isset($defaults['Arts']);
        $streams    = [];
        if ($hasStreams) {
            foreach (array_keys($defaults) as $k) {
                if ($k !== 'common') $streams[] = $k;
            }
        }

        $this->json_success([
            'defaults'   => $defaults,
            'hasStreams'  => $hasStreams,
            'streams'    => $streams,
            'classRange' => $range,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/save_bulk_subjects
    // Saves an entire subjects list for a class in one shot.
    // Replaces existing subjects for the class.
    // ─────────────────────────────────────────────────────────────────────
    public function save_bulk_subjects()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_bulk_subjects');

        $school   = $this->school_name;
        $classKey = trim((string) $this->input->post('class_key', TRUE));
        $jsonData = $this->input->post('subjects', TRUE); // JSON string

        if ($classKey === '' || empty($jsonData)) {
            return $this->json_error('class_key and subjects are required.');
        }

        $subjects = json_decode($jsonData, true);
        if (!is_array($subjects)) {
            return $this->json_error('Invalid subjects format.');
        }

        $numKey = $this->_numeric_class_key($classKey);
        $validCategories = ['Core', 'Elective', 'Additional', 'Language', 'Vocational', 'Assessment'];

        // Build the full subject map to write
        $subjectMap = [];
        $usedNames  = [];

        foreach ($subjects as $idx => $sub) {
            $name     = trim($sub['name'] ?? '');
            $category = trim($sub['category'] ?? 'Core');
            $stream   = trim($sub['stream'] ?? 'common');
            $code     = trim($sub['code'] ?? '');

            if ($name === '') continue; // skip blank entries

            // Skip duplicates (case-insensitive, same stream)
            $dedupeKey = strtolower($name) . '|' . strtolower($stream);
            if (isset($usedNames[$dedupeKey])) continue;
            $usedNames[$dedupeKey] = true;

            if (!in_array($category, $validCategories, true)) $category = 'Core';

            // Auto-generate code if blank
            if ($code === '') {
                if (is_numeric($numKey)) {
                    $code = (string) ((int) $numKey * 100 + count($subjectMap) + 1);
                } else {
                    $prefix = strtoupper(substr($numKey, 0, 3));
                    $code   = $prefix . str_pad(count($subjectMap) + 1, 2, '0', STR_PAD_LEFT);
                }
            }

            if (!preg_match('/^[A-Za-z0-9_]+$/', $code)) {
                $code = 'SUB' . str_pad(count($subjectMap) + 1, 3, '0', STR_PAD_LEFT);
            }

            $entry = [
                'subject_name' => $name,
                'name'         => $name,
                'category'     => $category,
                'subject_code' => $code,
            ];
            if ($stream !== 'common') $entry['stream'] = $stream;

            $subjectMap[$code] = $entry;
        }

        if (empty($subjectMap)) {
            return $this->json_error('No valid subjects to save.');
        }

        // 2026-05-15 Phase 2 — per-row try/catch so a single failure does
        // not abort the whole batch. Each failure is captured as a
        // structured `failed[]` entry; partial-success responses now
        // carry `partial_failure: true` instead of returning HTTP 200
        // with a single missing-subject silently dropped.
        $saved  = 0;
        $failed = [];
        foreach ($subjectMap as $code => $entry) {
            try {
                $this->fs->setEntity('subjects', "{$numKey}_{$code}", [
                    'classKey'    => $numKey,
                    'subjectCode' => $code,
                    'name'        => $entry['name'] ?? $entry['subject_name'] ?? '',
                    'category'    => $entry['category'] ?? 'Core',
                    'stream'      => $entry['stream'] ?? 'common',
                ]);
                $saved++;
            } catch (\Throwable $e) {
                $failed[] = [
                    'subject_code' => (string) $code,
                    'name'         => $entry['name'] ?? '',
                    'reason'       => $e->getMessage(),
                ];
                log_message('error',
                    "ACC_BULK_ROW_FAILED endpoint=save_bulk_subjects schoolId={$this->school_id} "
                    . "classKey={$numKey} subjectCode={$code} err=" . $e->getMessage());
            }
        }

        $failedCount = count($failed);
        if ($failedCount > 0) {
            log_message('error',
                "ACC_BULK_PARTIAL_FAILURE endpoint=save_bulk_subjects schoolId={$this->school_id} "
                . "actor={$this->admin_id} classKey={$numKey} saved={$saved} failed={$failedCount}");
        }

        if ($saved === 0 && $failedCount > 0) {
            // Total failure — surface as HTTP 500 so the caller treats it
            // as a hard error instead of "saved nothing successfully".
            $this->_json_error_with_data(
                "Failed to save any subjects ({$failedCount} attempted).",
                [
                    'count'           => 0,
                    'failed'          => $failed,
                    'failed_count'    => $failedCount,
                    'partial_failure' => false,
                ],
                500
            );
        }

        log_audit('Configuration', 'save_bulk_subjects', $classKey, "Bulk subjects update for class {$classKey}: {$saved} saved, {$failedCount} failed");

        $this->json_success([
            'message'         => $saved . ' subjects saved for class ' . $classKey
                . ($failedCount > 0 ? ", {$failedCount} failed — see failed[]." : '.'),
            'count'           => $saved,
            'failed'          => $failed,
            'failed_count'    => $failedCount,
            'partial_failure' => $failedCount > 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/save_stream
    // ─────────────────────────────────────────────────────────────────────
    public function save_stream()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_stream');
        $school    = $this->school_name;
        $streamKey = trim((string) $this->input->post('stream_key', TRUE));
        $label     = trim((string) $this->input->post('label',      TRUE));
        $rawEnabled = $this->input->post('enabled');
        $enabled   = ($rawEnabled === true || $rawEnabled === '1' || $rawEnabled === 'true');

        if ($streamKey === '' || $label === '') {
            return $this->json_error('stream_key and label are required.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $streamKey)) {
            return $this->json_error('Invalid stream key. Use letters, digits, underscores only.');
        }

        // BUG-029: byte-length cap on label
        if (strlen($label) > 100) {
            return $this->json_error('Stream label exceeds 100 characters.');
        }

        // BUG-028 Phase 1: concurrency hardening — shared 'streams' lock serializes
        // against delete_stream + seed_streams. Firestore __updateTime precondition
        // catches cross-process races. Mirror of Phase 2 delete_stream canon.
        $lock = $this->_config_lock_acquire('streams');
        if ($lock === null) {
            log_message('error',
                "ACC_CONCURRENT_REJECTED endpoint=save_stream schoolId={$this->school_id} "
                . "actor={$this->admin_id} reason=lock_timeout streamKey={$streamKey}");
            return $this->json_error(
                'Another stream-management operation is in progress. Please retry in a few seconds.',
                409
            );
        }
        try {
            $streamData = [
                'key'     => $streamKey,
                'label'   => $label,
                'enabled' => $enabled,
            ];

            $schoolDocId = $this->fs->schoolId();
            $fsSchool    = $this->firebase->firestoreGet('schools', $schoolDocId);
            if (!is_array($fsSchool)) {
                return $this->json_error('School profile is not yet initialised.');
            }
            $allStreams = [];
            if (isset($fsSchool['streams']) && is_array($fsSchool['streams'])) {
                foreach ($fsSchool['streams'] as $k => $v) {
                    $allStreams[$k] = is_array($v) ? $v : (array) $v;
                }
            }
            $updateTime = (string) ($fsSchool['__updateTime'] ?? '');

            $allStreams[$streamKey] = $streamData;

            // BUG-028 Phase 1: CAS commit — refuse if another writer mutated the school
            // doc between our read and write. Caller is expected to refresh and retry on 409.
            $ops = [[
                'op'         => 'update',
                'collection' => 'schools',
                'docId'      => $schoolDocId,
                // 2026-05-27 — DOC-MERGE-FIX: see rationale at seed_streams above.
                'merge'      => true,
                'data'       => [
                    'streams'   => $allStreams,
                    'updatedAt' => date('c'),
                ],
            ]];
            if ($updateTime !== '') {
                $ops[0]['precondition'] = ['updateTime' => $updateTime];
            }
            $committed = false;
            try {
                $committed = (bool) $this->firebase->firestoreCommitBatch($ops);
            } catch (\Throwable $e) {
                log_message('error',
                    "ACC_SAVE_STREAM_COMMIT_FAILED schoolId={$this->school_id} "
                    . "streamKey={$streamKey} err=" . $e->getMessage());
            }
            if (!$committed) {
                log_message('error',
                    "ACC_CONCURRENT_REJECTED endpoint=save_stream schoolId={$this->school_id} "
                    . "actor={$this->admin_id} reason=cas_failed streamKey={$streamKey}");
                return $this->json_error(
                    'Another administrator updated school configuration during your request. '
                    . 'Please reload the page and retry.', 409);
            }

            log_audit('Configuration', 'save_stream', $streamKey, "Saved stream '{$label}'");

            $this->json_success(['message' => "Stream '{$label}' saved.", 'streams' => (object) $allStreams]);
        } catch (\Throwable $e) {
            log_message('error', 'save_stream error: ' . $e->getMessage());
            $this->json_error('Failed to save stream. Contact administrator.');
        } finally {
            $this->_config_lock_release($lock);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/delete_stream
    // ─────────────────────────────────────────────────────────────────────
    public function delete_stream()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_delete_stream');
        $school    = $this->school_name;
        $streamKey = trim((string) $this->input->post('stream_key', TRUE));

        if ($streamKey === '' || !preg_match('/^[A-Za-z0-9_]+$/', $streamKey)) {
            return $this->json_error('Invalid stream key.');
        }

        // 2026-05-15 Phase 2 — concurrency hardening + dependency check.
        // Pre-fix this method did read-then-modify-write on the whole
        // streams map: a concurrent "add stream" by another admin between
        // the read and the write would be lost because the writer
        // replaced the entire `streams` field with its stale snapshot.
        // Fix mirrors the Phase 1 add_session pattern: filesystem lock
        // serializes the brief critical section within this PHP worker
        // pool, Firestore __updateTime precondition catches cross-process
        // races, and a sectionAssignment / sections dependency sweep
        // refuses delete when live class sections still reference the
        // stream.
        $lock = $this->_config_lock_acquire('streams');
        if ($lock === null) {
            log_message('error',
                "ACC_CONCURRENT_REJECTED endpoint=delete_stream schoolId={$this->school_id} "
                . "actor={$this->admin_id} reason=lock_timeout streamKey={$streamKey}");
            return $this->json_error(
                'Another stream-management operation is in progress. Please retry in a few seconds.',
                409
            );
        }
        try {
            $schoolDocId = $this->fs->schoolId();
            $fsSchool    = $this->firebase->firestoreGet('schools', $schoolDocId);
            if (!is_array($fsSchool)) {
                return $this->json_error('School profile is not yet initialised.');
            }
            $allStreams = [];
            if (isset($fsSchool['streams']) && is_array($fsSchool['streams'])) {
                foreach ($fsSchool['streams'] as $k => $v) {
                    $allStreams[$k] = is_array($v) ? $v : (array) $v;
                }
            }
            $updateTime = (string) ($fsSchool['__updateTime'] ?? '');

            if (!array_key_exists($streamKey, $allStreams)) {
                return $this->json_error("Stream '{$streamKey}' does not exist.");
            }

            // Dependency check — sections / subjectAssignments / students
            // that reference this stream. Cap each at 200 (count is
            // authoritative, not the rows).
            $curSession = $this->session_year;
            $deps = $this->_dep_count_safe([
                [
                    'key'        => 'sections',
                    'collection' => 'sections',
                    'filters'    => [['streamKey', '==', $streamKey], ['session', '==', $curSession]],
                    'label'      => 'live section(s)',
                ],
                [
                    'key'        => 'subjectAssignments',
                    'collection' => 'subjectAssignments',
                    'filters'    => [['stream', '==', $streamKey], ['session', '==', $curSession]],
                    'label'      => 'subject assignment(s)',
                ],
                [
                    'key'        => 'students',
                    'collection' => 'students',
                    'filters'    => [['streamKey', '==', $streamKey], ['status', '==', 'Active']],
                    'label'      => 'active student(s)',
                ],
            ]);
            if (!empty($deps['has_any'])) {
                $this->_log_dep_block('delete_stream', $streamKey, $deps);
                $this->_json_error_with_data(
                    "Cannot delete stream '{$streamKey}': " . implode(', ', $deps['human'])
                    . ' reference it. Reassign or remove dependent data first.',
                    [
                        'dependencies' => $deps['by_key'],
                        'streamKey'    => $streamKey,
                        'session'      => $curSession,
                    ],
                    409
                );
            }

            unset($allStreams[$streamKey]);

            // CAS commit — refuse if another writer mutated the school
            // doc between our read and write. Caller is expected to
            // refresh and retry on 409.
            $ops = [[
                'op'         => 'update',
                'collection' => 'schools',
                'docId'      => $schoolDocId,
                // 2026-05-27 — DOC-MERGE-FIX: see rationale at seed_streams above.
                'merge'      => true,
                'data'       => [
                    'streams'   => $allStreams,
                    'updatedAt' => date('c'),
                ],
            ]];
            if ($updateTime !== '') {
                $ops[0]['precondition'] = ['updateTime' => $updateTime];
            }
            $committed = false;
            try {
                $committed = (bool) $this->firebase->firestoreCommitBatch($ops);
            } catch (\Throwable $e) {
                log_message('error',
                    "ACC_STREAM_DELETE_COMMIT_FAILED schoolId={$this->school_id} "
                    . "streamKey={$streamKey} err=" . $e->getMessage());
            }
            if (!$committed) {
                log_message('error',
                    "ACC_CONCURRENT_REJECTED endpoint=delete_stream schoolId={$this->school_id} "
                    . "actor={$this->admin_id} reason=cas_failed streamKey={$streamKey}");
                return $this->json_error(
                    'Another administrator updated stream configuration during your request. '
                    . 'Please reload the page and retry.',
                    409
                );
            }

            log_message('error',
                "ACC_STREAM_DELETED schoolId={$this->school_id} actor={$this->admin_id} "
                . "streamKey={$streamKey}");
            log_audit('Configuration', 'delete_stream', $streamKey, "Deleted stream '{$streamKey}'");

            return $this->json_success(['message' => 'Stream deleted.']);
        } catch (\Throwable $e) {
            log_message('error', 'delete_stream error: ' . $e->getMessage());
            $this->json_error('Failed to delete stream. Contact administrator.');
        } finally {
            $this->_config_lock_release($lock);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET   /school_config/test_sessions  (dev diagnostic — debug only)
    // ─────────────────────────────────────────────────────────────────────
    public function test_sessions()
    {
        $this->_assert_diagnostic_allowed('school_config_test_sessions');

        // BUG-030: diagnostic-endpoint access telemetry — fail-loud on dev-surface in prod
        if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
            $this->sec_telem->emit('CONFIG_DIAGNOSTIC_ACCESSED', 'info', ['endpoint' => __FUNCTION__, 'actor' => $this->admin_id]);
        }
        $school = $this->school_name;

        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        if (!is_array($fsSchool)) $fsSchool = [];

        $fromFirestore = (is_array($fsSchool['sessions'] ?? null))
            ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
            : [];

        $fromPhpSession = $this->session->userdata('available_sessions') ?? [];
        $fromPhpSession = is_array($fromPhpSession) ? array_values($fromPhpSession) : [];

        $activeConfig    = $fsSchool['currentSession'] ?? '';
        $sessionYearPhp  = (string) ($this->session->userdata('session') ?? $this->session_year);

        sort($fromFirestore);
        $fsSorted  = $fromFirestore;
        $phpSorted = $fromPhpSession;
        sort($phpSorted);
        $inSync = ($fsSorted === $phpSorted);

        $this->json_success([
            'firestore_sessions'   => $fromFirestore,
            'php_session_sessions' => $fromPhpSession,
            'in_sync'              => $inSync,
            'divergence'           => [
                'only_in_firestore'   => array_values(array_diff($fromFirestore,   $fromPhpSession)),
                'only_in_php_session' => array_values(array_diff($fromPhpSession, $fromFirestore)),
            ],
            'current_session_year' => $sessionYearPhp,
            'active_session'       => (string) $activeConfig,
            'school_name'          => $school,
            'school_id'            => $this->school_id,
            'note'                 => 'If firestore_sessions differs from php_session_sessions, call sync_sessions (POST) to reconcile.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/sync_sessions
    // ─────────────────────────────────────────────────────────────────────
    public function sync_sessions()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_sync_sessions');

        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        if (!is_array($fsSchool)) $fsSchool = [];

        $sessions = (is_array($fsSchool['sessions'] ?? null))
            ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
            : [];

        $activeSess = !empty($fsSchool['currentSession'])
                        ? (string) $fsSchool['currentSession']
                        : (string) $this->session_year;

        // Auto-seed: if sessions list is empty but we have a valid active
        // session, seed it. Also ensure active session is always present.
        $didSeed = false;
        if (!empty($activeSess) && preg_match('/^\d{4}-\d{2}$/', $activeSess)) {
            if (empty($sessions)) {
                $sessions = [$activeSess];
                $didSeed  = true;
            } elseif (!in_array($activeSess, $sessions, true)) {
                $sessions[] = $activeSess;
                sort($sessions);
                $didSeed = true;
            }
            if ($didSeed) {
                $this->fs->update('schools', $this->fs->schoolId(), [
                    'sessions'  => $sessions,
                    'updatedAt' => date('c'),
                ]);
            }
        }

        if (empty($sessions)) {
            $this->json_success([
                'sessions'       => [],
                'active_session' => $activeSess,
                'synced'         => false,
                'message'        => 'No sessions configured and no active session detected. Add a session to begin.',
            ]);
            return;
        }

        $this->session->set_userdata('available_sessions', $sessions);

        $archivedSess = (is_array($fsSchool['archivedSessions'] ?? null))
                            ? array_values(array_filter($fsSchool['archivedSessions'], 'is_string'))
                            : [];

        $this->json_success([
            'sessions'          => $sessions,
            'active_session'    => $activeSess,
            'archived_sessions' => $archivedSess,
            'synced'            => true,
            'message'           => $didSeed
                ? "Sessions list seeded with active session {$activeSess}."
                : 'PHP session synced from Firestore. Header dropdown will update on next render.',
        ]);
    }

    // 2026-05-15 Phase 3 — /school_config/csrf_token endpoint removed.
    // Reason: every json_success()/json_error() response auto-includes the
    // current csrf_token (see MY_Controller::json_success), so the standalone
    // endpoint was never actually called from any view. Removing both the
    // method and the route shrinks the attack surface without changing
    // any consumer behavior.

    // ─────────────────────────────────────────────────────────────────────
    // GET  /school_config/test_profile  (dev diagnostic — debug only)
    // ─────────────────────────────────────────────────────────────────────
    public function test_profile()
    {
        $this->_assert_diagnostic_allowed('school_config_test_profile');
        // BUG-030: diagnostic-endpoint access telemetry
        if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
            $this->sec_telem->emit('CONFIG_DIAGNOSTIC_ACCESSED', 'info', ['endpoint' => __FUNCTION__, 'actor' => $this->admin_id]);
        }
        $data = $this->fs->get('schools', $this->fs->schoolId());
        $this->json_success(['data' => is_array($data) ? $data : []]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET  /school_config/test_classes  (dev diagnostic — debug only)
    // ─────────────────────────────────────────────────────────────────────
    public function test_classes()
    {
        $this->_assert_diagnostic_allowed('school_config_test_classes');
        // BUG-030: diagnostic-endpoint access telemetry
        if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
            $this->sec_telem->emit('CONFIG_DIAGNOSTIC_ACCESSED', 'info', ['endpoint' => __FUNCTION__, 'actor' => $this->admin_id]);
        }
        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        $raw = (is_array($fsSchool['classes'] ?? null)) ? $fsSchool['classes'] : [];
        $data = array_values($raw);
        $this->json_success(['data' => $data]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET  /school_config/test_sections  (dev diagnostic — debug only)
    // ─────────────────────────────────────────────────────────────────────
    public function test_sections()
    {
        $this->_assert_diagnostic_allowed('school_config_test_sections');
        // BUG-030: diagnostic-endpoint access telemetry
        if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
            $this->sec_telem->emit('CONFIG_DIAGNOSTIC_ACCESSED', 'info', ['endpoint' => __FUNCTION__, 'actor' => $this->admin_id]);
        }
        $session = $this->session_year;

        $fsDocs = $this->fs->schoolWhere('sections', [['session', '==', $session]]);
        $data = [];
        if (is_array($fsDocs)) {
            foreach ($fsDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $cn = $d['className'] ?? '';
                $sn = $d['section'] ?? '';
                if ($cn !== '' && $sn !== '') {
                    $data[$cn][] = $sn;
                }
            }
        }
        foreach ($data as &$secs) sort($secs);
        unset($secs);

        $this->json_success(['data' => $data, 'session' => $session]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET  /school_config/test_subjects  (dev diagnostic — debug only)
    // ─────────────────────────────────────────────────────────────────────
    public function test_subjects()
    {
        $this->_assert_diagnostic_allowed('school_config_test_subjects');
        // BUG-030: diagnostic-endpoint access telemetry
        if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
            $this->sec_telem->emit('CONFIG_DIAGNOSTIC_ACCESSED', 'info', ['endpoint' => __FUNCTION__, 'actor' => $this->admin_id]);
        }
        $fsRows = $this->fs->schoolWhere('subjects', []);
        $data = [];
        if (is_array($fsRows)) {
            foreach ($fsRows as $row) {
                $d = $row['data'] ?? [];
                $classKey = $d['classKey'] ?? 'unknown';
                $code = $d['subjectCode'] ?? $d['id'] ?? '';
                $data[$classKey][$code] = $d;
            }
        }
        $this->json_success(['data' => $data]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/add_session
    // ─────────────────────────────────────────────────────────────────────
    public function add_session()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_add_session');
        $school  = $this->school_name;
        $session = trim((string) $this->input->post('session', TRUE));

        if (!preg_match('/^\d{4}-\d{2}$/', $session)) {
            return $this->json_error('Session must be in YYYY-YY format (e.g. 2025-26).');
        }

        $startYear = (int) substr($session, 0, 4);
        $endYY     = (int) substr($session, 5, 2);
        if ($endYY !== ($startYear + 1) % 100) {
            return $this->json_error('Invalid session: end year must follow start year (e.g. 2025-26).');
        }

        // Phase 1 (2026-05-15) — concurrency hardening. Brief filesystem
        // lock serializes concurrent add_session calls for THIS school;
        // Firestore __updateTime precondition (CAS) on the commit is the
        // authoritative guard against cross-process races. Idempotent on
        // re-submit: if the session is already in the list after the
        // re-read inside the lock, return success without writing.
        $lock = $this->_config_lock_acquire('sessions');
        if ($lock === null) {
            log_message('error',
                "ACC_CONCURRENT_REJECTED endpoint=add_session schoolId={$this->school_id} "
                . "actor={$this->admin_id} reason=lock_timeout session={$session}");
            return $this->json_error('Another session-management operation is in progress. Please retry in a few seconds.', 409);
        }
        try {
            // Re-read INSIDE the lock so we see the freshest sessions[].
            $schoolDocId = $this->fs->schoolId();
            $fsSchool    = $this->firebase->firestoreGet('schools', $schoolDocId);
            if (!is_array($fsSchool)) {
                return $this->json_error('School profile is not yet initialised.');
            }
            $sessions   = (is_array($fsSchool['sessions'] ?? null)) ? $fsSchool['sessions'] : [];
            $sessions   = array_values(array_filter($sessions, 'is_string'));
            $updateTime = (string) ($fsSchool['__updateTime'] ?? '');

            // Idempotent re-submit: already present → success without write.
            if (in_array($session, $sessions, true)) {
                log_message('error',
                    "ACC_SESSION_ADD_IDEMPOTENT schoolId={$this->school_id} "
                    . "session={$session} actor={$this->admin_id}");
                $this->session->set_userdata('available_sessions', $sessions);
                return $this->json_success([
                    'message'    => "Session {$session} already exists.",
                    'sessions'   => $sessions,
                    'idempotent' => true,
                ]);
            }

            $sessions[] = $session;
            sort($sessions);

            $ops = [[
                'op'         => 'update',
                'collection' => 'schools',
                'docId'      => $schoolDocId,
                // 2026-05-27 — DOC-MERGE-FIX: emit updateMask so this PATCH
                // touches only `sessions` + `updatedAt`. Without this flag,
                // commitBatch sent a maskless `update` which Firestore REST
                // treats as full-document REPLACE — wiping currentSession,
                // classes, streams, profile, board, subscription, etc.
                // Evidence: 2026-05-27 forensic, schools/SCH_D94FE8F7AD
                // reduced to 4 fields after repeated session mutations.
                'merge'      => true,
                'data'       => [
                    'sessions'  => $sessions,
                    'updatedAt' => date('c'),
                ],
            ]];
            if ($updateTime !== '') {
                $ops[0]['precondition'] = ['updateTime' => $updateTime];
            }
            $committed = false;
            try {
                $committed = (bool) $this->firebase->firestoreCommitBatch($ops);
            } catch (\Throwable $e) {
                log_message('error',
                    "ACC_SESSION_ADD_COMMIT_FAILED schoolId={$this->school_id} "
                    . "session={$session} err=" . $e->getMessage());
            }
            if (!$committed) {
                log_message('error',
                    "ACC_CONCURRENT_REJECTED endpoint=add_session schoolId={$this->school_id} "
                    . "actor={$this->admin_id} reason=cas_failed session={$session}");
                return $this->json_error(
                    'Another administrator updated school configuration during your request. '
                    . 'Please reload the page and retry.', 409);
            }

            $this->session->set_userdata('available_sessions', $sessions);

            log_message('error',
                "ACC_SESSION_ADDED schoolId={$this->school_id} session={$session} "
                . "actor={$this->admin_id} total=" . count($sessions));
            log_audit('Configuration', 'add_session', $session, "Added session {$session}");

            return $this->json_success(['message' => "Session {$session} added.", 'sessions' => $sessions]);
        } finally {
            $this->_config_lock_release($lock);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/set_active_session
    // Issue 2: Now also updates all 3 PHP session keys so the entire
    // application switches immediately without re-login.
    // ─────────────────────────────────────────────────────────────────────
    public function set_active_session()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_set_active_session');
        $school  = $this->school_name;
        $session = trim((string) $this->input->post('session', TRUE));

        if (!preg_match('/^\d{4}-\d{2}$/', $session)) {
            return $this->json_error('Invalid session format.');
        }

        // Phase 1 (2026-05-15) — concurrency hardening. Same lock+CAS
        // pattern as add_session: brief filesystem lock for THIS school,
        // Firestore __updateTime precondition on the commit. The PHP
        // session keys are mutated ONLY after the Firestore commit
        // succeeds, eliminating PHP/Firestore divergence.
        $lock = $this->_config_lock_acquire('sessions');
        if ($lock === null) {
            log_message('error',
                "ACC_CONCURRENT_REJECTED endpoint=set_active_session "
                . "schoolId={$this->school_id} actor={$this->admin_id} "
                . "reason=lock_timeout session={$session}");
            return $this->json_error('Another session-management operation is in progress. Please retry in a few seconds.', 409);
        }
        try {
            // Re-read INSIDE the lock for the freshest sessions[] and
            // the __updateTime token for the CAS commit.
            $schoolDocId = $this->fs->schoolId();
            $fsSchool    = $this->firebase->firestoreGet('schools', $schoolDocId);
            if (!is_array($fsSchool)) {
                return $this->json_error('School profile is not yet initialised.');
            }
            $sessions   = (is_array($fsSchool['sessions'] ?? null)) ? $fsSchool['sessions'] : [];
            $sessions   = array_values(array_filter($sessions, 'is_string'));
            $updateTime = (string) ($fsSchool['__updateTime'] ?? '');
            $priorActive = (string) ($fsSchool['currentSession'] ?? '');

            if (!in_array($session, $sessions, true)) {
                return $this->json_error('Session not found. Add it first.');
            }

            // Refuse switching INTO an archived session — defence-in-depth
            // for the reversibility gap noted in audit (P1 #16). Cheap
            // check; doesn't redesign anything.
            $archived = (is_array($fsSchool['archivedSessions'] ?? null))
                ? array_values(array_filter($fsSchool['archivedSessions'], 'is_string'))
                : [];
            if (in_array($session, $archived, true)) {
                log_message('error',
                    "ACC_SESSION_SWITCH_REJECTED schoolId={$this->school_id} "
                    . "target={$session} reason=archived actor={$this->admin_id}");
                return $this->json_error("Session {$session} is archived. Unarchive it before activation.", 409);
            }

            // Idempotent: same active session → success, no write, no log_audit noise.
            if ($priorActive === $session) {
                return $this->json_success([
                    'message'        => "Active session is already {$session}.",
                    'active_session' => $session,
                    'idempotent'     => true,
                ]);
            }

            $ops = [[
                'op'         => 'update',
                'collection' => 'schools',
                'docId'      => $schoolDocId,
                // 2026-05-27 — DOC-MERGE-FIX: see rationale at add_session above.
                // This PATCH must touch only `currentSession` + `updatedAt`;
                // sessions[], classes, streams, profile, board, subscription
                // must remain intact.
                'merge'      => true,
                'data'       => [
                    'currentSession' => $session,
                    'updatedAt'      => date('c'),
                ],
            ]];
            if ($updateTime !== '') {
                $ops[0]['precondition'] = ['updateTime' => $updateTime];
            }
            $committed = false;
            try {
                $committed = (bool) $this->firebase->firestoreCommitBatch($ops);
            } catch (\Throwable $e) {
                log_message('error',
                    "ACC_SESSION_SWITCH_COMMIT_FAILED schoolId={$this->school_id} "
                    . "session={$session} err=" . $e->getMessage());
            }
            if (!$committed) {
                log_message('error',
                    "ACC_CONCURRENT_REJECTED endpoint=set_active_session "
                    . "schoolId={$this->school_id} actor={$this->admin_id} "
                    . "reason=cas_failed prior={$priorActive} attempted={$session}");
                return $this->json_error(
                    'Another administrator updated school configuration during your request. '
                    . 'Please reload the page and retry.', 409);
            }

            // PHP session updates ONLY after successful Firestore commit.
            // Pre-fix order had a small window where Firestore was already
            // written but PHP session keys were stale (or vice-versa).
            $this->session->set_userdata([
                'session'         => $session,
                'current_session' => $session,
                'session_year'    => $session,
            ]);
            $this->session_year = $session;

            log_message('error',
                "ACC_SESSION_SWITCH schoolId={$this->school_id} "
                . "from={$priorActive} to={$session} actor={$this->admin_id}");
            log_audit('Configuration', 'set_active_session', $session,
                "Changed active session from {$priorActive} to {$session}");

            return $this->json_success([
                'message'        => "Active session set to {$session}. All modules will now use this session.",
                'active_session' => $session,
            ]);
        } finally {
            $this->_config_lock_release($lock);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/preview_rollover
    // Dry-run: shows what rollover would do. No writes.
    // Inputs: from_session, to_session
    // ─────────────────────────────────────────────────────────────────────
    public function preview_rollover()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_preview_rollover');

        $from = trim((string) $this->input->post('from_session', TRUE));
        $to   = trim((string) $this->input->post('to_session',   TRUE));

        if (!preg_match('/^\d{4}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}$/', $to)) {
            return $this->json_error('Both from_session and to_session must be in YYYY-YY format.');
        }
        if ($from === $to) {
            return $this->json_error('Source and target sessions must differ.');
        }

        // Guard: from_session must exist on the school doc. Without this a
        // typo silently returns a zero-count preview and the user thinks the
        // source session is empty.
        $fsSchoolEarly = $this->fs->get('schools', $this->fs->schoolId());
        $listedEarly = (is_array($fsSchoolEarly['sessions'] ?? null))
            ? array_values(array_filter($fsSchoolEarly['sessions'], 'is_string'))
            : [];
        if (!in_array($from, $listedEarly, true)) {
            return $this->json_error("Source session '{$from}' is not in the sessions list. Add it first or pick a listed session.");
        }

        // Source sections
        $srcSections = $this->fs->schoolWhere('sections', [['session', '==', $from]]);
        $srcSections = is_array($srcSections) ? $srcSections : [];
        $sectionList = [];
        foreach ($srcSections as $row) {
            $d = $row['data'] ?? [];
            $sectionList[] = [
                'className' => (string) ($d['className'] ?? ''),
                'section'   => (string) ($d['section']   ?? ''),
            ];
        }

        // Source students (for promotion preview)
        $srcStudents = $this->fs->schoolWhere('students', [['session', '==', $from]]);
        $srcStudents = is_array($srcStudents) ? $srcStudents : [];
        $byClass = []; $graduating = 0; $active = 0;
        foreach ($srcStudents as $row) {
            $d = $row['data'] ?? [];
            if (($d['status'] ?? 'Active') !== 'Active') continue;
            $active++;
            $order = $d['classOrder'] ?? null;
            $label = $d['className'] ?? 'Unknown';
            $byClass[$label] = ($byClass[$label] ?? 0) + 1;
            if ($order === 12) $graduating++;
        }

        // Target session checks
        $dstSections = $this->fs->schoolWhere('sections', [['session', '==', $to]]);
        $dstSections = is_array($dstSections) ? $dstSections : [];
        $dstStudents = $this->fs->schoolWhere('students', [['session', '==', $to]]);
        $dstStudents = is_array($dstStudents) ? $dstStudents : [];

        $warnings = [];
        if (count($dstSections) > 0) $warnings[] = count($dstSections) . ' section(s) already exist in ' . $to . '. Existing sections will be skipped (not overwritten).';
        if (count($dstStudents) > 0) $warnings[] = count($dstStudents) . ' student(s) already exist in ' . $to . '. Promotion will be skipped for students already in target.';

        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        $listed = (is_array($fsSchool['sessions'] ?? null))
                    ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
                    : [];
        $targetListed = in_array($to, $listed, true);

        $this->json_success([
            'from_session'       => $from,
            'to_session'         => $to,
            'target_in_list'     => $targetListed,
            'sections_to_copy'   => count($sectionList),
            'sections'           => $sectionList,
            'active_students'    => $active,
            'students_by_class'  => $byClass,
            'graduating'         => $graduating,
            'existing_sections'  => count($dstSections),
            'existing_students'  => count($dstStudents),
            'warnings'           => $warnings,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/rollover_session
    // Creates target session (if missing), copies section structure from
    // source, and optionally promotes students (Class N → N+1, Class 12 → Alumni).
    //
    // Inputs:
    //   from_session      (required)
    //   to_session        (required)
    //   copy_sections     (1 default)
    //   promote_students  (0 default)
    //   set_active        (0 default)
    // ─────────────────────────────────────────────────────────────────────
    public function rollover_session()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_rollover_session');

        $from = trim((string) $this->input->post('from_session', TRUE));
        $to   = trim((string) $this->input->post('to_session',   TRUE));
        $copySections    = (int) $this->input->post('copy_sections',    TRUE) !== 0; // default on
        $promoteStudents = (int) $this->input->post('promote_students', TRUE) === 1;
        $setActive       = (int) $this->input->post('set_active',       TRUE) === 1;

        if (!preg_match('/^\d{4}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}$/', $to)) {
            return $this->json_error('Both from_session and to_session must be in YYYY-YY format.');
        }
        if ($from === $to) {
            return $this->json_error('Source and target sessions must differ.');
        }

        // Guard: promoting without copying sections would orphan students into
        // non-existent target sections. Force-couple the two actions.
        $coupledNotice = '';
        if ($promoteStudents && !$copySections) {
            $copySections = true;
            $coupledNotice = 'Copy sections was auto-enabled because promotion requires target sections to exist. ';
        }

        $schoolId = $this->fs->schoolId();
        $fsSchool = $this->fs->get('schools', $schoolId);
        if (!is_array($fsSchool)) $fsSchool = [];

        $sessions = (is_array($fsSchool['sessions'] ?? null))
            ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
            : [];

        if (!in_array($from, $sessions, true)) {
            return $this->json_error("Source session '{$from}' is not in the sessions list.");
        }

        // Phase 1 (2026-05-15) — rollover intent + duplicate-protection.
        // The intent map lives on the schools doc (no new collection)
        // and tracks (from→to) attempts. A previous attempt that
        // partially completed leaves a `running` entry; a retry sees
        // that entry, switches to `resuming` mode, and skips students
        // whose `session==to` (already promoted). Retry across worker
        // restarts is therefore idempotent without redesigning the
        // students collection.
        $intentKey   = "{$from}__to__{$to}";
        $rolloverMap = (is_array($fsSchool['rolloverIntents'] ?? null)) ? $fsSchool['rolloverIntents'] : [];
        $prior       = (is_array($rolloverMap[$intentKey] ?? null))     ? $rolloverMap[$intentKey]     : [];
        $resuming    = false;
        if (!empty($prior) && (($prior['status'] ?? '') === 'running')) {
            $resuming = true;
            log_message('error',
                "ACC_ROLLOVER_RESUME schoolId={$this->school_id} key={$intentKey} "
                . "started_at=" . (string) ($prior['started_at'] ?? '?'));
        } elseif (!empty($prior) && (($prior['status'] ?? '') === 'completed')) {
            // Idempotent: same rollover already finished. Return the prior summary.
            log_message('error',
                "ACC_ROLLOVER_IDEMPOTENT schoolId={$this->school_id} key={$intentKey} "
                . "completed_at=" . (string) ($prior['completed_at'] ?? '?'));
            return $this->json_success([
                'message'    => "Rollover {$from} → {$to} was already completed.",
                'summary'    => is_array($prior['summary'] ?? null) ? $prior['summary'] : [],
                'idempotent' => true,
            ]);
        }

        // Write the `running` intent before any mutation.
        $now = date('c');
        try {
            $this->fs->update('schools', $schoolId, [
                'rolloverIntents.' . $intentKey => [
                    'status'     => 'running',
                    'from'       => $from,
                    'to'         => $to,
                    'started_at' => $now,
                    'started_by' => $this->admin_id,
                    'attempt'    => (int) (($prior['attempt'] ?? 0)) + 1,
                ],
                'updatedAt' => $now,
            ]);
        } catch (\Throwable $e) {
            log_message('error',
                "ACC_ROLLOVER_INTENT_WRITE_FAILED schoolId={$this->school_id} "
                . "key={$intentKey} err=" . $e->getMessage());
            return $this->json_error('Could not record rollover intent. Retry.', 500);
        }

        log_message('error',
            "ACC_ROLLOVER_START schoolId={$this->school_id} from={$from} to={$to} "
            . "actor={$this->admin_id} resuming=" . ($resuming ? '1' : '0'));

        // Ensure target session is in the list
        if (!in_array($to, $sessions, true)) {
            $sessions[] = $to;
            sort($sessions);
            $this->fs->update('schools', $schoolId, ['sessions' => $sessions, 'updatedAt' => date('c')]);
        }

        $summary = [
            'sections_copied'   => 0,
            'sections_skipped'  => 0,
            'students_promoted' => 0,
            'students_graduated'=> 0,
            'students_skipped'  => 0,
            'errors'            => [],
            'failed_rows'       => [],   // structured: [{id, kind, reason}]
            'resuming'          => $resuming,
        ];

        // ── Copy sections ──────────────────────────────────────────────
        if ($copySections) {
            $srcSections = $this->fs->schoolWhere('sections', [['session', '==', $from]]);
            $srcSections = is_array($srcSections) ? $srcSections : [];

            // Pre-load existing target section IDs to skip
            $dstSections = $this->fs->schoolWhere('sections', [['session', '==', $to]]);
            $existingIds = [];
            foreach (is_array($dstSections) ? $dstSections : [] as $row) {
                $id = $row['id'] ?? '';
                if ($id !== '') $existingIds[$id] = true;
            }

            foreach ($srcSections as $row) {
                $d = $row['data'] ?? [];
                $className = (string) ($d['className'] ?? '');
                $section   = (string) ($d['section']   ?? '');
                if ($className === '' || $section === '') { $summary['sections_skipped']++; continue; }

                $newDocId = "{$schoolId}_{$to}_{$className}_{$section}";
                if (isset($existingIds[$newDocId])) {
                    $summary['sections_skipped']++;
                    continue;
                }

                $newDoc = [
                    'schoolId'     => $schoolId,
                    'className'    => $className,
                    'section'      => $section,
                    'classOrder'   => $d['classOrder']  ?? null,
                    'sectionCode'  => $d['sectionCode'] ?? '',
                    'sectionKey'   => "{$className}/{$section}",
                    'classTeacher' => '',          // reset; re-assign in new session
                    'classTeacherId' => '',
                    'session'      => $to,
                    'studentCount' => 0,
                    'updatedAt'    => date('c'),
                ];
                try {
                    $this->fs->set('sections', $newDocId, $newDoc, true);
                    $summary['sections_copied']++;
                } catch (\Exception $e) {
                    $summary['errors'][] = "Section {$className}/{$section}: " . $e->getMessage();
                }
            }
        }

        // ── Promote students ───────────────────────────────────────────
        // 2026-05-15 Phase 1 critical-bug fix — the promotion loop had
        // `$id = $d['id'] ?? ''` BEFORE `$d = $row['data'] ?? []`, so
        // $d was a stale value from the previous iteration (or empty on
        // the first row). The block now:
        //   1. Reads $row → $stuId + $stuData explicitly (no $d alias)
        //   2. Validates the student ID up front; SKIPS + logs on empty
        //   3. Skips students whose `session==to` (already-promoted on a
        //      prior failed attempt — idempotent resume)
        //   4. Captures each failure as a structured failed_rows[] entry
        //   5. Aborts the loop and marks the intent `failed` if the
        //      consecutive failure rate exceeds 10 — protects against a
        //      Firestore-side outage silently halving the school roster.
        if ($promoteStudents) {
            $srcStudents = $this->fs->schoolWhere('students', [['session', '==', $from]]);
            $srcStudents = is_array($srcStudents) ? $srcStudents : [];

            $consecFails = 0;
            $consecCap   = 10;

            foreach ($srcStudents as $row) {
                if (!is_array($row)) {
                    $summary['students_skipped']++;
                    log_message('error',
                        "ACC_ROLLOVER_SKIP schoolId={$this->school_id} reason=row_not_array");
                    continue;
                }
                $stuData = is_array($row['data'] ?? null) ? $row['data'] : [];
                $stuId   = (string) ($row['id'] ?? ($stuData['id'] ?? ''));

                if ($stuId === '') {
                    $summary['students_skipped']++;
                    $summary['failed_rows'][] = [
                        'id'     => '(none)',
                        'kind'   => 'promotion',
                        'reason' => 'empty_student_id',
                    ];
                    log_message('error',
                        "ACC_ROLLOVER_SKIP schoolId={$this->school_id} reason=empty_id "
                        . "session_from={$from} session_to={$to}");
                    continue;
                }
                if (($stuData['status'] ?? 'Active') !== 'Active') {
                    $summary['students_skipped']++;
                    continue;
                }
                // Idempotent resume: skip students already in the target session
                // (a prior partial attempt promoted them).
                if (((string) ($stuData['session'] ?? '')) === $to) {
                    $summary['students_skipped']++;
                    log_message('error',
                        "ACC_ROLLOVER_SKIP schoolId={$this->school_id} reason=already_promoted "
                        . "id={$stuId} target={$to}");
                    continue;
                }

                $order = $stuData['classOrder'] ?? null;

                // Class 12 → Alumni (keep session, mark graduated)
                if ($order === 12 || (is_numeric($order) && (int)$order === 12)) {
                    try {
                        $this->fs->update('students', $stuId, [
                            'status'         => 'Alumni',
                            'graduatedFrom'  => $from,
                            'updatedAt'      => date('c'),
                        ]);
                        $summary['students_graduated']++;
                        $consecFails = 0;
                    } catch (\Exception $e) {
                        $consecFails++;
                        $msg = "Student {$stuId} graduate: " . $e->getMessage();
                        $summary['errors'][]      = $msg;
                        $summary['failed_rows'][] = ['id' => $stuId, 'kind' => 'graduate', 'reason' => $e->getMessage()];
                        log_message('error',
                            "ACC_ROLLOVER_FAIL schoolId={$this->school_id} id={$stuId} "
                            . "kind=graduate err=" . $e->getMessage());
                        if ($consecFails >= $consecCap) break;
                    }
                    continue;
                }

                // Numeric classes → increment
                if (is_numeric($order) && (int)$order >= 1 && (int)$order <= 11) {
                    $nextNum = (int)$order + 1;
                    $nextLabel = 'Class ' . $nextNum . (in_array($nextNum, [11,12], true) ? 'th' : $this->_ordinal($nextNum));
                    try {
                        $this->fs->update('students', $stuId, [
                            'session'      => $to,
                            'className'    => $nextLabel,
                            'classOrder'   => $nextNum,
                            'sectionKey'   => "{$nextLabel}/" . ($stuData['section'] ?? ''),
                            'promotedFrom' => $from,
                            'updatedAt'    => date('c'),
                        ]);
                        $summary['students_promoted']++;
                        $consecFails = 0;
                    } catch (\Exception $e) {
                        $consecFails++;
                        $msg = "Student {$stuId} promote: " . $e->getMessage();
                        $summary['errors'][]      = $msg;
                        $summary['failed_rows'][] = ['id' => $stuId, 'kind' => 'promote', 'reason' => $e->getMessage()];
                        log_message('error',
                            "ACC_ROLLOVER_FAIL schoolId={$this->school_id} id={$stuId} "
                            . "kind=promote err=" . $e->getMessage());
                        if ($consecFails >= $consecCap) break;
                    }
                    continue;
                }

                // Foundational (Nursery, LKG, UKG) — advance through that ladder
                $foundationalNext = [-5 => -4, -4 => -3, -3 => -2, -2 => -1, -1 => 1];
                $foundationalLabel = [-4 => 'Class Pre-Nursery', -3 => 'Class Nursery', -2 => 'Class LKG', -1 => 'Class UKG', 1 => 'Class 1st'];
                if (is_numeric($order) && isset($foundationalNext[(int)$order])) {
                    $nextOrder = $foundationalNext[(int)$order];
                    $nextLabel = $foundationalLabel[$nextOrder] ?? '';
                    if ($nextLabel === '') {
                        $summary['students_skipped']++;
                        log_message('error',
                            "ACC_ROLLOVER_SKIP schoolId={$this->school_id} reason=no_foundational_label "
                            . "id={$stuId} classOrder={$order}");
                        continue;
                    }
                    try {
                        $this->fs->update('students', $stuId, [
                            'session'      => $to,
                            'className'    => $nextLabel,
                            'classOrder'   => $nextOrder,
                            'sectionKey'   => "{$nextLabel}/" . ($stuData['section'] ?? ''),
                            'promotedFrom' => $from,
                            'updatedAt'    => date('c'),
                        ]);
                        $summary['students_promoted']++;
                        $consecFails = 0;
                    } catch (\Exception $e) {
                        $consecFails++;
                        $msg = "Student {$stuId} promote: " . $e->getMessage();
                        $summary['errors'][]      = $msg;
                        $summary['failed_rows'][] = ['id' => $stuId, 'kind' => 'promote', 'reason' => $e->getMessage()];
                        log_message('error',
                            "ACC_ROLLOVER_FAIL schoolId={$this->school_id} id={$stuId} "
                            . "kind=promote err=" . $e->getMessage());
                        if ($consecFails >= $consecCap) break;
                    }
                    continue;
                }

                // Non-numeric classOrder, no foundational match — explicit skip with reason
                $summary['students_skipped']++;
                log_message('error',
                    "ACC_ROLLOVER_SKIP schoolId={$this->school_id} reason=unknown_classOrder "
                    . "id={$stuId} classOrder=" . var_export($order, true));
            }

            if ($consecFails >= $consecCap) {
                $summary['errors'][]  = "Aborted promotion loop after {$consecCap} consecutive failures.";
                log_message('error',
                    "ACC_ROLLOVER_ABORT schoolId={$this->school_id} consec_fails={$consecFails} "
                    . "promoted_before_abort={$summary['students_promoted']}");
            }
        }

        // ── Optionally set target as active ────────────────────────────
        if ($setActive) {
            $this->fs->update('schools', $schoolId, ['currentSession' => $to, 'updatedAt' => date('c')]);
            $this->session->set_userdata([
                'session'         => $to,
                'current_session' => $to,
                'session_year'    => $to,
            ]);
            $this->session_year = $to;
        }

        // Phase 1 (2026-05-15) — finalise the rollover intent.
        // `completed` when there are no errors AND no failed rows.
        // `partial` when some rows succeeded but errors are present.
        // `failed` when nothing succeeded.
        $touched = $summary['sections_copied']
            + $summary['students_promoted']
            + $summary['students_graduated'];
        $hasFailures = !empty($summary['errors']) || !empty($summary['failed_rows']);
        $intentStatus = $hasFailures
            ? ($touched > 0 ? 'partial' : 'failed')
            : 'completed';
        $summary['partial_failure'] = ($intentStatus === 'partial');

        try {
            $this->fs->update('schools', $schoolId, [
                'rolloverIntents.' . $intentKey => [
                    'status'        => $intentStatus,
                    'from'          => $from,
                    'to'            => $to,
                    'started_at'    => $now,
                    'completed_at'  => date('c'),
                    'started_by'    => $this->admin_id,
                    'summary'       => $summary,
                ],
                'updatedAt' => date('c'),
            ]);
        } catch (\Throwable $e) {
            log_message('error',
                "ACC_ROLLOVER_INTENT_FINALISE_FAILED schoolId={$this->school_id} "
                . "key={$intentKey} err=" . $e->getMessage());
        }

        log_message('error',
            "ACC_ROLLOVER_END schoolId={$this->school_id} from={$from} to={$to} "
            . "status={$intentStatus} sections_copied={$summary['sections_copied']} "
            . "promoted={$summary['students_promoted']} graduated={$summary['students_graduated']} "
            . "skipped={$summary['students_skipped']} errors=" . count($summary['errors']));

        log_audit('Configuration', 'rollover_session', $to,
            "Rolled over {$from} → {$to} (status={$intentStatus}): " . json_encode($summary));

        $parts = [];
        if ($summary['sections_copied'])    $parts[] = $summary['sections_copied']    . ' sections copied';
        if ($summary['students_promoted'])  $parts[] = $summary['students_promoted']  . ' students promoted';
        if ($summary['students_graduated']) $parts[] = $summary['students_graduated'] . ' graduated to Alumni';
        $msg = 'Rollover ' . $from . ' → ' . $to . ' complete: ' . (empty($parts) ? 'no changes' : implode(', ', $parts)) . '.';
        if ($setActive) $msg .= ' Active session is now ' . $to . '.';
        if ($coupledNotice !== '') $msg = $coupledNotice . $msg;

        $this->json_success([
            'message'        => $msg,
            'summary'        => $summary,
            'sessions'       => $sessions,
            'active_session' => $setActive ? $to : ($fsSchool['currentSession'] ?? ''),
        ]);
    }

    private function _ordinal(int $n): string
    {
        if ($n <= 0) return '';
        $suffix = 'th';
        $mod100 = $n % 100;
        if ($mod100 < 11 || $mod100 > 13) {
            switch ($n % 10) {
                case 1: $suffix = 'st'; break;
                case 2: $suffix = 'nd'; break;
                case 3: $suffix = 'rd'; break;
            }
        }
        return $suffix;
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/delete_session
    // Guard-railed deletion: refuses if any students/sections/staff/attendance
    // reference this session. Also refuses if it's the currently-active session.
    // ─────────────────────────────────────────────────────────────────────
    public function delete_session()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_delete_session');

        $session = trim((string) $this->input->post('session', TRUE));
        if (!preg_match('/^\d{4}-\d{2}$/', $session)) {
            return $this->json_error('Invalid session format.');
        }

        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        if (!is_array($fsSchool)) $fsSchool = [];

        $sessions = (is_array($fsSchool['sessions'] ?? null))
            ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
            : [];
        $active = (string) ($fsSchool['currentSession'] ?? '');

        if (!in_array($session, $sessions, true)) {
            return $this->json_error("Session {$session} is not in the sessions list.");
        }
        if ($session === $active) {
            return $this->json_error("Cannot delete the active session. Set a different session as active first.");
        }

        // 2026-05-15 Phase 2 — extended dependency sweep + structured
        // response. Was: 3 collections (students/sections/staff). Now:
        // also attendance / marks / feeDemands / feeReceipts / timetables
        // / subjectAssignments — same collections delete_section /
        // soft_delete_class already check, applied at the session level.
        // The legacy `blockers[]` string list is retained for backward
        // compat; the new `dependencies` object exposes per-collection
        // counts that UI can render as a structured breakdown.
        $deps = $this->_dep_count_safe([
            ['key' => 'students',           'collection' => 'students',           'filters' => [['session', '==', $session]], 'label' => 'student(s)'],
            ['key' => 'sections',           'collection' => 'sections',           'filters' => [['session', '==', $session]], 'label' => 'section(s)'],
            ['key' => 'staff',              'collection' => 'staff',              'filters' => [['session', '==', $session]], 'label' => 'staff record(s)'],
            ['key' => 'attendance',         'collection' => 'attendance',         'filters' => [['session', '==', $session]], 'label' => 'attendance record(s)'],
            ['key' => 'marks',              'collection' => 'marks',              'filters' => [['session', '==', $session]], 'label' => 'marks record(s)'],
            ['key' => 'feeDemands',         'collection' => 'feeDemands',         'filters' => [['session', '==', $session]], 'label' => 'fee demand(s)'],
            ['key' => 'feeReceipts',        'collection' => 'feeReceipts',        'filters' => [['session', '==', $session]], 'label' => 'fee receipt(s)'],
            ['key' => 'timetables',         'collection' => 'timetables',         'filters' => [['session', '==', $session]], 'label' => 'timetable entry/entries'],
            ['key' => 'subjectAssignments', 'collection' => 'subjectAssignments', 'filters' => [['session', '==', $session]], 'label' => 'subject assignment(s)'],
        ]);
        $blockers = $deps['human']; // backward-compat alias for legacy callers
        if (!empty($deps['has_any'])) {
            $this->_log_dep_block('delete_session', $session, $deps);
            $this->_json_error_with_data(
                "Cannot delete {$session}. It is referenced by: " . implode(', ', $blockers)
                . '. Use Archive instead to hide the session while preserving all data.',
                [
                    'dependencies'      => $deps['by_key'],
                    'blockers'          => $blockers,  // legacy compat
                    'session'           => $session,
                    'recommend_archive' => true,
                ],
                409
            );
        }

        // ── Remove from sessions list ──────────────────────────────────
        $sessions = array_values(array_filter($sessions, fn($s) => $s !== $session));
        $update = [
            'sessions'  => $sessions,
            'updatedAt' => date('c'),
        ];
        // If the deleted session was archived, clean that flag too.
        if (is_array($fsSchool['archivedSessions'] ?? null)) {
            $arch = array_values(array_filter($fsSchool['archivedSessions'], 'is_string'));
            $update['archivedSessions'] = array_values(array_filter($arch, fn($s) => $s !== $session));
        }
        $this->fs->update('schools', $this->fs->schoolId(), $update);
        $this->session->set_userdata('available_sessions', $sessions);

        log_audit('Configuration', 'delete_session', $session, "Deleted empty session {$session}");

        $this->json_success([
            'message'  => "Session {$session} deleted.",
            'sessions' => $sessions,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/archive_session
    // Hides a session from normal dropdowns but keeps all data intact.
    // Toggle: pass archive=1 to archive, archive=0 to unarchive.
    // ─────────────────────────────────────────────────────────────────────
    public function archive_session()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_archive_session');

        $session = trim((string) $this->input->post('session', TRUE));
        $doArchive = (int) $this->input->post('archive', TRUE) === 1;

        if (!preg_match('/^\d{4}-\d{2}$/', $session)) {
            return $this->json_error('Invalid session format.');
        }

        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        if (!is_array($fsSchool)) $fsSchool = [];

        $sessions = (is_array($fsSchool['sessions'] ?? null))
            ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
            : [];
        $active = (string) ($fsSchool['currentSession'] ?? '');

        if (!in_array($session, $sessions, true)) {
            return $this->json_error("Session {$session} is not in the sessions list.");
        }
        if ($doArchive && $session === $active) {
            // 2026-05-15 Phase 2 — structured rejection telemetry for
            // governance audits. Pairs with set_active_session's archive
            // rejection (added in Phase 1) — together they enforce the
            // invariant: an archived session is never simultaneously
            // active.
            log_message('error',
                "ACC_SESSION_ARCHIVE_REJECTED schoolId={$this->school_id} "
                . "actor={$this->admin_id} target={$session} reason=is_active_session");
            return $this->json_error("Cannot archive the active session. Set a different session as active first.");
        }

        $archived = (is_array($fsSchool['archivedSessions'] ?? null))
            ? array_values(array_filter($fsSchool['archivedSessions'], 'is_string'))
            : [];

        if ($doArchive) {
            if (!in_array($session, $archived, true)) $archived[] = $session;
            sort($archived);
            $msg = "Session {$session} archived. It will be hidden from default dropdowns but data is preserved.";
            log_message('error',
                "ACC_SESSION_ARCHIVED schoolId={$this->school_id} actor={$this->admin_id} target={$session}");
        } else {
            $archived = array_values(array_filter($archived, fn($s) => $s !== $session));
            $msg = "Session {$session} unarchived.";
            log_message('error',
                "ACC_SESSION_UNARCHIVED schoolId={$this->school_id} actor={$this->admin_id} target={$session}");
        }

        $this->fs->update('schools', $this->fs->schoolId(), [
            'archivedSessions' => $archived,
            'updatedAt'        => date('c'),
        ]);

        log_audit('Configuration', 'archive_session', $session,
            $doArchive ? "Archived session {$session}" : "Unarchived session {$session}");

        $this->json_success([
            'message'           => $msg,
            'archived_sessions' => $archived,
            'session'           => $session,
            'archived'          => $doArchive,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/session_stats
    // Returns per-session counts: students, sections, staff. Used by the
    // Sessions tab to surface activity at a glance (spot empty/phantom sessions).
    // ─────────────────────────────────────────────────────────────────────
    public function session_stats()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_session_stats');

        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        $sessions = (is_array($fsSchool['sessions'] ?? null))
            ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
            : [];

        // One read per collection, then group in-memory (fewer round trips).
        $allStudents = $this->fs->schoolWhere('students', []);
        $allSections = $this->fs->schoolWhere('sections', []);
        $allStaff    = $this->fs->schoolWhere('staff',    []);

        $index = [];
        foreach ($sessions as $s) {
            $index[$s] = ['session' => $s, 'students' => 0, 'sections' => 0, 'staff' => 0, 'classes' => 0];
        }
        $classSet = []; // session => [className => true]

        $bump = function(array $rows, string $key) use (&$index, &$classSet) {
            foreach ($rows as $row) {
                $d = $row['data'] ?? $row;
                $sess = (string) ($d['session'] ?? '');
                if ($sess === '') continue;
                if (!isset($index[$sess])) {
                    $index[$sess] = ['session' => $sess, 'students' => 0, 'sections' => 0, 'staff' => 0, 'classes' => 0];
                }
                $index[$sess][$key]++;
                if ($key === 'sections') {
                    $cn = (string) ($d['className'] ?? '');
                    if ($cn !== '') $classSet[$sess][$cn] = true;
                }
            }
        };
        $bump(is_array($allStudents) ? $allStudents : [], 'students');
        $bump(is_array($allSections) ? $allSections : [], 'sections');
        $bump(is_array($allStaff)    ? $allStaff    : [], 'staff');

        foreach ($classSet as $sess => $set) {
            $index[$sess]['classes'] = count($set);
        }

        $this->json_success(['stats' => array_values($index)]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/check_sessions
    // Consistency check: compares sessions[] in school doc vs actual data
    // found in Firestore collections. Reports orphans and missing entries.
    // ─────────────────────────────────────────────────────────────────────
    public function check_sessions()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_check_sessions');

        $fsSchool  = $this->fs->get('schools', $this->fs->schoolId());
        $listed    = (is_array($fsSchool['sessions'] ?? null))
                        ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
                        : [];
        $active    = (string) ($fsSchool['currentSession'] ?? '');

        // Collect session values found across collections.
        $found = [];
        foreach (['students', 'sections', 'staff'] as $coll) {
            $rows = $this->fs->schoolWhere($coll, []);
            if (!is_array($rows)) continue;
            foreach ($rows as $row) {
                $d = $row['data'] ?? $row;
                $s = (string) ($d['session'] ?? '');
                if ($s !== '' && preg_match('/^\d{4}-\d{2}$/', $s)) $found[$s] = true;
            }
        }
        $foundList = array_keys($found);
        sort($foundList);

        $orphans = array_values(array_diff($foundList, $listed));   // data exists, not in list
        $empty   = array_values(array_diff($listed, $foundList));   // listed, no data
        $issues  = [];
        if ($active !== '' && !in_array($active, $listed, true)) {
            $issues[] = "Active session '{$active}' is not in sessions list.";
        }
        if (!empty($orphans)) {
            $issues[] = count($orphans) . ' session(s) have data but are missing from list: ' . implode(', ', $orphans);
        }
        if (!empty($empty)) {
            $issues[] = count($empty) . ' session(s) listed but contain no data: ' . implode(', ', $empty);
        }

        $this->json_success([
            'listed'   => $listed,
            'found'    => $foundList,
            'orphans'  => $orphans,
            'empty'    => $empty,
            'active'   => $active,
            'issues'   => $issues,
            'healthy'  => empty($issues),
            'message'  => empty($issues) ? 'All sessions are consistent.' : 'Found ' . count($issues) . ' issue(s).',
        ]);
    }

    // =========================================================================
    //  PRIVATE HELPERS
    // =========================================================================

    /**
     * 2026-05-15 Phase 3 — uniform diagnostic-endpoint gate.
     *
     * Single source of truth for "is this caller allowed to hit a debug-only
     * diagnostic endpoint?". Returns void after emitting a 404 if the gate
     * fails, so the caller can `return $this->_assert_diagnostic_allowed(...)`
     * and rely on `exit` semantics (show_404 calls exit internally).
     *
     * Gate composition (all must hold):
     *   1. Caller is in ADMIN_ROLES (already enforced by per-method
     *      _require_role, but re-checked here as defense-in-depth).
     *   2. GRADER_DEBUG flag is on. GRADER_DEBUG is file-flag based
     *      (application/logs/.debug_enabled) — production deployments
     *      never have that file, so the flag is false and diagnostic
     *      endpoints become invisible (show_404, not 403, so attackers
     *      cannot enumerate which endpoints exist).
     *
     * Why 404 instead of 403: 403 confirms the endpoint exists. 404
     * makes the endpoint indistinguishable from a non-route. Combined
     * with the route still being declared in routes.php, this is
     * deliberate misdirection — the route resolves, but the controller
     * pretends the URL never existed.
     */
    private function _assert_diagnostic_allowed(string $action): void
    {
        $this->_require_role(self::ADMIN_ROLES, $action);
        if (!defined('GRADER_DEBUG') || !GRADER_DEBUG) {
            log_message('info',
                "ACC_DIAG_ROUTE_BLOCKED action={$action} schoolId={$this->school_id} "
                . "actor=" . ($this->admin_id ?? 'unknown') . " reason=debug_flag_off");
            show_404();
        }
    }

    /**
     * 2026-05-15 Phase 3 — canonicalize class labels for known key patterns.
     *
     * Problem this solves: `save_classes()` accepted whatever label the
     * client posted ("class 5", "Class V", "Std 5", "5th grade"), which
     * caused shape drift between systems. The class/section canonical
     * contract requires labels in the form "Class N{ordinal}" for numeric
     * grades and "Nursery|LKG|UKG|Playgroup" for foundational classes.
     *
     * Strategy: derive the canonical label from the key (already validated
     * by save_classes' regex `^[A-Za-z0-9_]+$`). For unknown keys, return
     * the trimmed client label unchanged so custom schools (e.g. Montessori
     * "primary_a") continue to function. This means existing data is NOT
     * rewritten; new saves are canonical only for the common cases.
     */
    private function _normalize_class_label(string $key, string $clientLabel): string
    {
        $lower = strtolower(trim($key));
        $map = [
            'nursery'   => 'Nursery',
            'lkg'       => 'LKG',
            'ukg'       => 'UKG',
            'playgroup' => 'Playgroup',
        ];
        if (isset($map[$lower])) {
            return $map[$lower];
        }
        if (preg_match('/^\d+$/', $key)) {
            $n      = (int) $key;
            $suffix = $this->_ordinal_suffix($n);
            return "Class {$n}{$suffix}";
        }
        // Non-canonical key — preserve the client label (trimmed by save_classes).
        return trim($clientLabel);
    }

    private function _class_node_name(string $classKey): string
    {
        static $map = [
            'nursery'   => 'Nursery',
            'lkg'       => 'LKG',
            'ukg'       => 'UKG',
            'playgroup' => 'Playgroup',
        ];
        $lower = strtolower(trim($classKey));
        if (isset($map[$lower])) {
            return 'Class ' . $map[$lower];
        }
        $num    = (int) $classKey;
        $suffix = $this->_ordinal_suffix($num);
        return "Class {$num}{$suffix}";
    }

    private function _numeric_class_key(string $classKey): string
    {
        $lower = strtolower(trim($classKey));
        if ($lower === 'nursery')   return 'Nursery';
        if ($lower === 'lkg')       return 'LKG';
        if ($lower === 'ukg')       return 'UKG';
        if ($lower === 'playgroup') return 'Playgroup';
        return (string) (int) $classKey;
    }

    private function _ordinal_suffix(int $n): string
    {
        if ($n >= 11 && $n <= 13) return 'th';
        switch ($n % 10) {
            case 1:  return 'st';
            case 2:  return 'nd';
            case 3:  return 'rd';
            default: return 'th';
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/save_report_card_template
    // ─────────────────────────────────────────────────────────────────────
    public function save_report_card_template()
    {
        $this->_require_role(self::ADMIN_ROLES, 'save_rc_template');
        $school   = $this->school_name;
        $template = trim((string) $this->input->post('template', TRUE));

        $allowed = ['classic', 'cbse', 'minimal', 'modern', 'elegant'];
        if (!in_array($template, $allowed, true)) {
            return $this->json_error('Invalid template selection.');
        }

        // Firestore (sole write target)
        $this->fs->update('schools', $this->fs->schoolId(), ['reportCardTemplate' => $template, 'updatedAt' => date('c')]);

        log_audit('Configuration', 'save_report_card_template', $school, "Set report card template to {$template}");

        $this->json_success(['message' => 'Report card template saved.', 'template' => $template]);
    }

    // ── Admission Payment Configuration ────────────────────────────────

    /**
     * Render the admission payment configuration page.
     * Reads Schools/{school}/Config/AdmissionFee
     */
    public function admission_payment_config()
    {
        $this->_require_role(self::ADMIN_ROLES, 'admission_payment_config');
        $school = $this->school_name;

        // Read from Firestore
        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        $config = (is_array($fsSchool['admissionFee'] ?? null)) ? $fsSchool['admissionFee'] : [];

        $data = [
            'config'       => $config,
            'school_name'  => $school,
            'school_id'    => $this->school_id,
            'session_year' => $this->session_year,
            'public_form_url' => base_url('admission/form/' . urlencode($school)),
        ];

        $this->load->view('include/header');
        $this->load->view('school_config/admission_payment', $data);
        $this->load->view('include/footer');
    }

    /**
     * Save admission payment configuration (AJAX POST).
     * Writes to Schools/{school}/Config/AdmissionFee
     */
    public function save_admission_payment_config()
    {
        $this->_require_role(self::ADMIN_ROLES, 'save_admission_payment_config');
        $school = $this->school_name;

        $enabled  = $this->input->post('enabled') === 'true' || $this->input->post('enabled') === '1';
        $amount   = (float) ($this->input->post('amount') ?? 0);
        $currency = trim($this->input->post('currency') ?? 'INR');
        $label    = trim($this->input->post('label') ?? 'Admission Fee');

        // Validate
        if ($enabled && $amount <= 0) {
            return $this->json_error('Amount must be greater than 0 when payment is enabled.');
        }
        if ($amount > 500000) {
            return $this->json_error('Amount exceeds maximum allowed (5,00,000).');
        }

        $allowedCurrencies = ['INR', 'USD', 'GBP', 'EUR'];
        if (!in_array($currency, $allowedCurrencies, true)) {
            $currency = 'INR';
        }

        // Sanitize label
        if (mb_strlen($label) > 100) $label = mb_substr($label, 0, 100);
        if ($label === '') $label = 'Admission Fee';

        $config = [
            'enabled'    => $enabled,
            'amount'     => round($amount, 2),
            'currency'   => $currency,
            'label'      => $label,
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => $this->admin_name ?? 'admin',
        ];

        // Firestore (sole write target)
        $this->fs->update('schools', $this->fs->schoolId(), ['admissionFee' => $config, 'updatedAt' => date('c')]);

        log_audit('School Config', 'admission_payment', $school,
            ($enabled ? "Enabled" : "Disabled") . " admission fee: {$currency} {$amount}"
        );

        return $this->json_success(['message' => 'Admission payment settings saved.', 'config' => $config]);
    }

    // =========================================================================
    //  HEALTH CHECK — Academic Setup Diagnostic
    // =========================================================================

    /**
     * GET  /school_config/health_check
     *
     * Reads ALL school config data from Firestore and returns a detailed
     * report with pass/warn/fail status per check.
     */
    public function health_check()
    {
        // 2026-05-15 Phase 3 — gated behind GRADER_DEBUG.
        // The user-facing health-check UI is served by the separate
        // /health_check controller (see views/health_check/index.php +
        // header.php sidebar link). This endpoint is the legacy
        // school_config diagnostic that dumps the full school doc and
        // every classes/sections/subjects row — useful in dev, but a
        // PII/credential-leak surface in production. show_404 in prod.
        $this->_assert_diagnostic_allowed('school_config_health_check');

        $school    = $this->school_name;
        $schoolId  = $this->school_id;
        $session   = $this->session_year;
        $checks    = [];
        $pass = 0; $warn = 0; $fail = 0;

        // Helper to add check result
        $add = function (string $category, string $name, string $status, string $detail, $data = null) use (&$checks, &$pass, &$warn, &$fail) {
            $checks[] = ['category' => $category, 'check' => $name, 'status' => $status, 'detail' => $detail, 'data' => $data];
            if ($status === 'PASS') $pass++;
            elseif ($status === 'WARN') $warn++;
            else $fail++;
        };

        // ── 1. SCHOOL IDENTITY ────────────────────────────────────────
        $add('Identity', 'school_name', $school ? 'PASS' : 'FAIL',
            $school ? "school_name = {$school}" : 'school_name is empty — session data missing');
        $add('Identity', 'school_id', $schoolId ? 'PASS' : 'FAIL',
            $schoolId ? "school_id = {$schoolId}" : 'school_id is empty');
        $add('Identity', 'session_year', $session ? 'PASS' : 'FAIL',
            $session ? "session_year = {$session}" : 'session_year is empty');
        $add('Identity', 'school_code', $this->school_code ? 'PASS' : 'WARN',
            $this->school_code ? "school_code = {$this->school_code}" : 'school_code is empty (may break app login)');
        $add('Identity', 'parent_db_key', $this->parent_db_key ? 'PASS' : 'WARN',
            $this->parent_db_key ? "parent_db_key = {$this->parent_db_key}" : 'parent_db_key is empty');

        // ── 2. FIRESTORE SERVICE ──────────────────────────────────────
        $fsReady = $this->fs->isReady();
        $add('Firestore', 'fs_init', $fsReady ? 'PASS' : 'FAIL',
            $fsReady ? "Firestore_service initialized (schoolId={$this->fs->schoolId()}, session={$this->fs->session()})"
                     : 'Firestore_service NOT ready — writes will silently fail');

        // ── 3. FIRESTORE SCHOOL DOC ──────────────────────────────────
        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        if (!is_array($fsSchool)) $fsSchool = [];
        $add('Profile', 'fs_school_doc',
            !empty($fsSchool) ? 'PASS' : 'FAIL',
            !empty($fsSchool) ? 'Firestore schools doc exists (' . count($fsSchool) . ' fields)'
                              : 'Firestore schools doc MISSING — apps cannot load school data');

        // Profile fields check
        $profileFieldMap = [
            'name'    => 'display_name',
            'phone'   => 'phone',
            'email'   => 'email',
            'address' => 'address',
            'city'    => 'city',
        ];
        foreach ($profileFieldMap as $fsKey => $label) {
            $has = !empty($fsSchool[$fsKey]);
            $add('Profile', "fs_profile_{$label}",
                $has ? 'PASS' : 'WARN',
                $has ? "{$label} = " . substr($fsSchool[$fsKey], 0, 60) : "{$label} is empty");
        }

        if (!empty($fsSchool)) {
            // Check Android-required fields
            $androidFields = [
                'schoolCode'      => 'Android SchoolDoc.schoolCode',
                'currentSession'  => 'Android SchoolDoc.currentSession',
                'name'            => 'Android SchoolDoc.name',
                'status'          => 'Android SchoolDoc.status',
            ];
            foreach ($androidFields as $fld => $label) {
                $val = $fsSchool[$fld] ?? '';
                $add('Profile', "fs_{$fld}",
                    ($val !== '' && $val !== null) ? 'PASS' : 'FAIL',
                    ($val !== '' && $val !== null) ? "{$label} = {$val}" : "{$label} is MISSING — Android apps will fail");
            }

            // Detect legacy field names (should not exist)
            if (!empty($fsSchool['activeSession'])) {
                $add('Profile', 'fs_legacy_activeSession', 'WARN',
                    "Legacy field 'activeSession' found (value={$fsSchool['activeSession']}). Apps read 'currentSession' instead.");
            }
            if (!empty($fsSchool['schoolLoginCode'])) {
                $add('Profile', 'fs_legacy_schoolLoginCode', 'WARN',
                    "Legacy field 'schoolLoginCode' found. Apps read 'schoolCode' instead.");
            }
        }

        // ── 4. SESSIONS ──────────────────────────────────────────────
        $sessions = (is_array($fsSchool['sessions'] ?? null))
            ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
            : [];
        $add('Sessions', 'sessions_list',
            !empty($sessions) ? 'PASS' : 'FAIL',
            !empty($sessions) ? count($sessions) . ' sessions: ' . implode(', ', $sessions) : 'No sessions configured');

        $activeSess = $fsSchool['currentSession'] ?? '';
        $add('Sessions', 'active_session',
            !empty($activeSess) ? 'PASS' : 'FAIL',
            !empty($activeSess) ? "currentSession = {$activeSess}" : 'currentSession not set — apps cannot determine current session');

        if (!empty($activeSess) && !empty($sessions)) {
            $add('Sessions', 'active_in_list',
                in_array($activeSess, $sessions, true) ? 'PASS' : 'FAIL',
                in_array($activeSess, $sessions, true) ? 'Active session exists in sessions list'
                    : "Active session \"{$activeSess}\" NOT in sessions list [" . implode(',', $sessions) . "]");
        }

        // PHP session sync
        $phpSessions = $this->session->userdata('available_sessions') ?? [];
        if (is_array($phpSessions)) {
            $phpSorted = array_values($phpSessions);
            sort($phpSorted);
            $fsSorted = $sessions;
            sort($fsSorted);
            $add('Sessions', 'php_session_sync',
                $phpSorted === $fsSorted ? 'PASS' : 'WARN',
                $phpSorted === $fsSorted ? 'PHP session matches Firestore'
                    : 'PHP session has ' . count($phpSessions) . ' sessions vs Firestore ' . count($sessions) . ' — click Sync');
        }

        // ── 5. BOARD CONFIG ──────────────────────────────────────────
        $board = (is_array($fsSchool['board_config'] ?? null)) ? $fsSchool['board_config'] : [];
        $add('Board', 'board_config',
            !empty($board['type']) ? 'PASS' : 'WARN',
            !empty($board['type']) ? "Board type={$board['type']}, pattern=" . ($board['grading_pattern'] ?? 'N/A')
                                   : 'Board not configured — subjects suggestion won\'t work');
        if (!empty($board['grading_pattern']) && $board['grading_pattern'] !== 'marks') {
            $hasScale = !empty($board['grade_scale']) && is_array($board['grade_scale']);
            $add('Board', 'grade_scale',
                $hasScale ? 'PASS' : 'WARN',
                $hasScale ? count($board['grade_scale']) . ' grade entries defined'
                          : 'Grading pattern is ' . $board['grading_pattern'] . ' but no grade scale defined');
        }

        // ── 6. CLASSES ───────────────────────────────────────────────
        $classes = (is_array($fsSchool['classes'] ?? null)) ? array_values($fsSchool['classes']) : [];
        $activeClasses = array_filter($classes, function ($c) { return is_array($c) && empty($c['deleted']); });
        $deletedClasses = array_filter($classes, function ($c) { return is_array($c) && !empty($c['deleted']); });

        $add('Classes', 'classes_configured',
            !empty($activeClasses) ? 'PASS' : 'WARN',
            !empty($activeClasses) ? count($activeClasses) . ' active classes, ' . count($deletedClasses) . ' soft-deleted'
                                    : 'No classes configured — use "Seed Standard Classes"');

        // ── 7. SECTIONS ──────────────────────────────────────────────
        // Pre-fetch all sections from Firestore for this session
        $fsSectionDocs = $this->fs->schoolWhere('sections', [['session', '==', $session]]);
        $sectionDetails = [];
        $totalSections = 0;
        if (is_array($fsSectionDocs)) {
            foreach ($fsSectionDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $cn = $d['className'] ?? '';
                $sn = $d['section'] ?? '';
                if ($cn !== '' && $sn !== '') {
                    $sectionDetails[$cn][] = $sn;
                    $totalSections++;
                }
            }
        }

        // Check if active classes have sections
        $classNodeIssues = [];
        foreach ($activeClasses as $cls) {
            if (!is_array($cls)) continue;
            $key = $cls['key'] ?? '';
            if ($key === '') continue;
            $classNode = $this->_class_node_name($key);
            if (empty($sectionDetails[$classNode])) {
                $classNodeIssues[] = $classNode;
            }
        }
        if (empty($classNodeIssues)) {
            $add('Classes', 'class_sections_in_session',
                !empty($activeClasses) ? 'PASS' : 'WARN',
                !empty($activeClasses) ? 'All ' . count($activeClasses) . ' classes have sections in session ' . $session : 'No classes to check');
        } else {
            $add('Classes', 'class_sections_in_session', 'WARN',
                count($classNodeIssues) . ' classes have no sections in session ' . $session . ': ' . implode(', ', $classNodeIssues)
                . ' — click "Activate Classes in Session"');
        }

        $add('Sections', 'total_sections',
            $totalSections > 0 ? 'PASS' : 'WARN',
            $totalSections > 0 ? "{$totalSections} sections across " . count($sectionDetails) . " classes"
                                : 'No sections found — configure in Sections tab');

        // ── 8. SUBJECTS ─────────────────────────────────────────────
        $fsSubjects = $this->fs->schoolWhere('subjects', []);
        $totalSubjects = 0;
        $classesWithSubjects = [];
        if (is_array($fsSubjects)) {
            foreach ($fsSubjects as $row) {
                $d = $row['data'] ?? [];
                if (!is_array($d)) continue;
                $ck = $d['classKey'] ?? '';
                if ($ck !== '') {
                    $classesWithSubjects[$ck] = ($classesWithSubjects[$ck] ?? 0) + 1;
                    $totalSubjects++;
                }
            }
        }
        $add('Subjects', 'subjects_configured',
            $totalSubjects > 0 ? 'PASS' : 'WARN',
            $totalSubjects > 0 ? "{$totalSubjects} subjects across " . count($classesWithSubjects) . " classes"
                                : 'No subjects configured — go to Subjects tab');

        // Classes with sections but no subjects
        $missingSubjects = [];
        foreach ($activeClasses as $cls) {
            if (!is_array($cls)) continue;
            $key = $cls['key'] ?? '';
            if ($key === '') continue;
            $numKey = $this->_numeric_class_key($key);
            $hasSections = !empty($sectionDetails[$this->_class_node_name($key)] ?? []);
            $hasSubjects = !empty($classesWithSubjects[$numKey]);
            if ($hasSections && !$hasSubjects) {
                $missingSubjects[] = $cls['label'] ?? $key;
            }
        }
        if (!empty($missingSubjects)) {
            $add('Subjects', 'classes_without_subjects', 'WARN',
                count($missingSubjects) . ' class(es) have sections but no subjects: ' . implode(', ', $missingSubjects));
        }

        // ── 9. STREAMS ──────────────────────────────────────────────
        $streams = (is_array($fsSchool['streams'] ?? null)) ? $fsSchool['streams'] : [];
        $streamCount = count($streams);
        $hasStreamClasses = !empty(array_filter($activeClasses, function ($c) { return !empty($c['streams_enabled']); }));

        if ($hasStreamClasses) {
            $add('Streams', 'streams_configured',
                $streamCount > 0 ? 'PASS' : 'FAIL',
                $streamCount > 0 ? "{$streamCount} streams defined"
                                  : 'Stream-enabled classes exist but no streams configured — go to Streams tab');
        } else {
            $add('Streams', 'streams_configured',
                'PASS',
                $streamCount > 0 ? "{$streamCount} streams defined (no stream-enabled classes)" : 'No stream-enabled classes — streams not needed');
        }

        // ── 10. REPORT CARD TEMPLATE ─────────────────────────────────
        $rcTemplate = $fsSchool['reportCardTemplate'] ?? '';
        $add('ReportCard', 'template',
            !empty($rcTemplate) ? 'PASS' : 'WARN',
            !empty($rcTemplate) ? "Template = {$rcTemplate}" : 'No report card template set — defaults to "classic"');

        // ── 11. LOGO / DOCUMENTS ─────────────────────────────────────
        $logoUrl = $fsSchool['logoUrl'] ?? $fsSchool['logo_url'] ?? '';
        $add('Assets', 'logo',
            $logoUrl !== '' ? 'PASS' : 'WARN',
            $logoUrl !== '' ? 'Logo configured' : 'No logo uploaded');

        // ── SUMMARY ──────────────────────────────────────────────────
        $overallStatus = $fail > 0 ? 'UNHEALTHY' : ($warn > 3 ? 'NEEDS_ATTENTION' : 'HEALTHY');

        $this->json_success([
            'status'       => $overallStatus,
            'summary'      => ['pass' => $pass, 'warn' => $warn, 'fail' => $fail, 'total' => $pass + $warn + $fail],
            'school'       => $school,
            'school_id'    => $schoolId,
            'session'      => $session,
            'checks'       => $checks,
            'section_map'  => $sectionDetails,
            'timestamp'    => date('Y-m-d H:i:s'),
        ]);
    }
}
