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

    /**
     * SC-Step6 (2026-06-02): superset of ADMIN_ROLES that may set the
     * active session view. Mirrors Admin::VIEW_ROLES exactly so that
     * after Step 6 retires Admin::switch_session, all 14 roles that
     * could previously switch via the header dropdown retain that
     * capability via school_config/set_active_session. Used ONLY by
     * set_active_session's role gate; the rest of School_config's
     * mutating endpoints remain ADMIN_ROLES-gated.
     */
    /** Bounded re-decides after a CAS conflict on a rollover destination. */
    private const ROLLOVER_SEAT_ATTEMPTS = 4;

    private const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'HR Manager', 'Accountant', 'Front Office', 'Class Teacher', 'Teacher', 'Librarian', 'Transport Manager', 'Hostel Warden'];

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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_index', 'Configuration', 'view');
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_config', 'Configuration', 'view');
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

        // Password-recovery contact block (forgot-password feature). Name/email/
        // number are editable via save_forget_password_details(); the SSA id is
        // not part of the block (the school's primarySsaId is the identity).
        $fpd = is_array($fsSchool['forget_password_details'] ?? null) ? $fsSchool['forget_password_details'] : [];
        $forgetPwd = [
            'name'   => (string) ($fpd['name']   ?? ''),
            'email'  => (string) ($fpd['email']  ?? ''),
            'number' => (string) ($fpd['number'] ?? ''),
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
        $rcAllowed  = ['classic', 'cbse', 'minimal', 'modern', 'elegant', 'professional'];
        if (!$rcTemplate || !is_string($rcTemplate) || !in_array($rcTemplate, $rcAllowed, true)) $rcTemplate = 'classic';

        // Report card CUSTOMISATION config (accent, section toggles, text, assets)
        $rcConfig = (is_array($fsSchool['reportCardConfig'] ?? null)) ? $fsSchool['reportCardConfig'] : [];

        $archivedSess = (is_array($fsSchool['archivedSessions'] ?? null))
                            ? array_values(array_filter($fsSchool['archivedSessions'], 'is_string'))
                            : [];

        // ── Phase 1 ZenX HRMS: Organisation / terminology (capability-gated) ──
        // Entirely additive to this shared endpoint. When the tenant does not
        // have HRMS_CORE_CAPABILITY, we do nothing here — no read beyond the
        // one already-loaded $fsSchool, no write (ensure_org_profile is NOT
        // called), and the response omits org data. This is what keeps
        // "existing school tenants behave exactly as before when the
        // capability is not enabled" true at the field level, not just the
        // endpoint level, since get_config() is shared with pre-existing
        // profile/session/board/class functionality that must keep working
        // regardless of this capability's state.
        $hrmsEnabled = has_capability(HRMS_CORE_CAPABILITY);
        $orgPayload  = ['hrms_capability_enabled' => $hrmsEnabled];
        if ($hrmsEnabled) {
            $this->load->helper('terminology');
            ensure_org_profile($this->fs, $this->school_id);
            $fsSchoolForOrg = $this->fs->get('schools', $this->school_id);
            $fsSchoolForOrg = is_array($fsSchoolForOrg) ? $fsSchoolForOrg : [];
            $orgPayload['org_type'] = (string) ($fsSchoolForOrg['orgType'] ?? 'EDUCATION');
            $orgPayload['labels']   = is_array($fsSchoolForOrg['labels'] ?? null) ? $fsSchoolForOrg['labels'] : [];
            $orgPayload['terms']    = [
                'person'   => org_term('person', $fsSchoolForOrg),
                'location' => org_term('location', $fsSchoolForOrg),
                'manager'  => org_term('manager', $fsSchoolForOrg),
            ];
        }

        $this->json_success([
            'profile'                 => is_array($profile)  ? $profile  : [],
            'forget_password_details' => $forgetPwd,
            'board'                   => is_array($board)     ? $board    : [],
            'classes'                 => $classes,
            // ADM-AGE-1 — the tenant's own class-vs-age rule. Absent means never
            // configured, which the editor renders as "off" rather than inventing a
            // default band: a school that has not stated a rule must not be given one.
            'admission_age_policy'     => is_array($fsSchool['admissionAgePolicy'] ?? null)
                                            ? $fsSchool['admissionAgePolicy'] : [],
            'streams'                 => (object) (is_array($streams) ? $streams : []),
            'sessions'                => $sessions,
            'active_session'          => (string) $activeSess,
            'archived_sessions'       => $archivedSess,
            'firebase_path'           => "Schools/{$school}/Sessions",
            'report_card_template'    => $rcTemplate,
            'report_card_config'      => (object) $rcConfig,
            'session_not_configured'  => $sessionNotConfigured,
            'name_not_configured'     => $nameNotConfigured,  // BUG-031
            'organisation'            => $orgPayload,
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
    /**
     * ── PHASE 20.1 (P20-F1) — THE SINGLE DEFINITION OF "IS THIS SECTION EMPTY?" ──
     *
     * THE DEFECT THIS CLOSES: both section-delete guards decided emptiness with
     *
     *     schoolWhere('students', [['sectionKey','==',$k], ['status','==','Active']])
     *
     * A Firestore equality predicate binds to ONE exact field name. The
     * `students` collection carries BOTH legacy PascalCase (`Status`,
     * `Class`, `Section`) and canonical camelCase shapes, and legacy documents
     * may carry no `sectionKey` composite at all. Such a student is invisible
     * to that predicate — so the guard saw zero students, reported the section
     * empty, and DELETED A SECTION THAT STILL HELD CHILDREN.
     *
     * This is the same anti-pattern documented at Classes.php:245-255, which
     * already caused a production incident (Attendance.php:1218 — "students
     * disappear from parent/teacher views after admin bulk saves").
     *
     * ── WHY TWO SIGNALS, NOT ONE ────────────────────────────────────────────
     * Both are passed through the SAME canonical filter
     * (MY_Controller::_filterSectionRoster) — there is exactly one definition
     * of membership here, not two. They differ only in where the documents
     * come from, because each source has a failure mode the other does not:
     *
     *   A. cached snapshot — shape-tolerant and catches students with no
     *      `sectionKey`, but _cachedStudentDocsForRollCheck() returns [] on
     *      error (MY_Controller:1404). An empty snapshot is indistinguishable
     *      from "school has no students", so ALONE it can still fail open.
     *
     *   B. indexed query — independent of snapshot health, so it catches the
     *      case above; narrower, because it needs `sectionKey`.
     *
     * Blocking on max(A,B) means a section is deletable only when BOTH agree
     * it is empty. Neither source alone is trusted.
     *
     * Both results are filtered by session, so a 2025-26 student never blocks
     * a 2026-27 section (Phase 20.1 Part 4). Disagreement therefore indicates
     * snapshot staleness or failure — not a legitimate cross-session student —
     * and is logged with both counts.
     *
     * FAIL CLOSED: if either source throws, `determined` is false and the
     * caller MUST refuse the delete. Uncertainty is never an empty section.
     *
     * @return array{count:int, determined:bool, canonical:?int, indexed:?int, errors:string[]}
     */
    private function _section_active_students(string $classNode, string $sectionNode, string $sessionYear): array
    {
        $canonical = null;
        $indexed   = null;
        $errors    = [];

        // A — shared snapshot (memoised per request + file-cached 90s).
        try {
            $canonical = count($this->_filterSectionRoster(
                $this->_cachedStudentDocsForRollCheck(),
                $classNode, $sectionNode, $sessionYear
            ));
        } catch (\Throwable $e) {
            $errors[] = 'snapshot: ' . $e->getMessage();
        }

        // B — indexed query, then through the SAME canonical filter so this
        // signal is session-scoped and shape-tolerant too. No second rule.
        try {
            $rows = $this->fs->schoolWhere('students', [
                ['sectionKey', '==', "{$classNode}/{$sectionNode}"],
                ['status',     '==', 'Active'],
            ], null, 'ASC', 200);
            $indexed = count($this->_filterSectionRoster(
                is_array($rows) ? $rows : [],
                $classNode, $sectionNode, $sessionYear
            ));
        } catch (\Throwable $e) {
            $errors[] = 'indexed: ' . $e->getMessage();
        }

        $determined = ($canonical !== null && $indexed !== null);

        if ($determined && $canonical !== $indexed) {
            log_message('error',
                "ACC_SECTION_ROSTER_DIVERGENCE schoolId={$this->school_id} "
                . "section={$classNode}/{$sectionNode} session={$sessionYear} "
                . "snapshot={$canonical} indexed={$indexed} — blocking delete (fail-closed)");
        }

        return [
            'count'      => max((int) $canonical, (int) $indexed),
            'determined' => $determined,
            'canonical'  => $canonical,
            'indexed'    => $indexed,
            'errors'     => $errors,
        ];
    }

    /**
     * ── PHASE 20.1b (P20-F8) — CANONICAL STREAM MEMBERSHIP ──────────────────
     *
     * WHAT SOURCE TRACING ACTUALLY FOUND — this is NOT the defect Phase 20.1
     * predicted, and the difference matters.
     *
     * delete_stream() blocked deletion with
     *     schoolWhere('students', [['streamKey','==',$k], ['status','==','Active']])
     * and Phase 20.1 registered it as another single-shape `status` predicate.
     *
     * It is worse than that. **No code anywhere in this application writes
     * `streamKey` — or any other stream field — onto a student document.**
     * Verified by exhaustive grep (streamKey appears only in this controller,
     * the school_config view and log lines) and confirmed on live data by
     * scripts/p20f8_stream_delete_exposure_probe.js: 0 of 23 students carry
     * `streamKey`, `stream`, `Stream`, `streamName`, `StreamKey` or
     * `stream_key`.
     *
     * So that predicate ALWAYS returned 0. It was inert, not merely
     * shape-sensitive — correcting `status` → `Status` would not have changed
     * its result by a single row. The student-protection check on stream
     * deletion has never been able to block anything.
     *
     * ── THE REAL RELATIONSHIP ───────────────────────────────────────────────
     * A student's stream membership is expressed through a STREAM-NAMED
     * SECTION, which is the shape bulk_save_sections() already accepts
     * (its label regex allows "Science A", not just "A"). Such a section
     * normalises via Entity_firestore_sync::normalizeClassSection() to
     *     section     = "Section Science A"
     *     sectionCode = "Science A"
     * so a student in stream "Science" is one whose section token is
     * "{stream} {letter}". That is the only link the data model actually has.
     *
     * ── WHY ONE SOURCE, NOT TWO (Phase 20.1b Part 6) ────────────────────────
     * Phase 20.1 needed two signals because both a snapshot and an indexed
     * query were available and each had a distinct blind spot. Here a second
     * indexed signal is IMPOSSIBLE: there is no student-level stream field to
     * index on. Copying that architecture would have meant inventing an index
     * for data that does not exist.
     *
     * The snapshot's own failure mode is still handled:
     * _cachedStudentDocsForRollCheck() returns [] on error
     * (MY_Controller:1404), indistinguishable from "school has no students".
     * A bounded ONE-document existence probe separates those two cases without
     * duplicating any membership logic and without an N+1.
     *
     * ── TRI-STATE, FAIL CLOSED ──────────────────────────────────────────────
     * Returns state EMPTY | NON_EMPTY | UNDETERMINED. Deletion requires an
     * explicit EMPTY. Uncertainty is never "zero students".
     *
     * @return array{state:string, count:int, reason:string}
     */
    private function _stream_active_students(string $streamKey, string $session): array
    {
        $streamKey = trim($streamKey);
        if ($streamKey === '') {
            return ['state' => 'UNDETERMINED', 'count' => 0, 'reason' => 'blank_stream_key'];
        }

        try {
            $docs = $this->_cachedStudentDocsForRollCheck();
        } catch (\Throwable $e) {
            return ['state' => 'UNDETERMINED', 'count' => 0, 'reason' => 'snapshot: ' . $e->getMessage()];
        }

        // Snapshot health: [] is returned both for "no students" and on error.
        // One bounded document tells the two apart. This is an existence
        // probe, not a roster query — it applies no membership rule.
        if (empty($docs)) {
            try {
                $probe = $this->fs->schoolWhere('students', [], null, 'ASC', 1);
                if (!empty($probe)) {
                    log_message('error',
                        "ACC_STREAM_ROSTER_SNAPSHOT_SUSPECT schoolId={$this->school_id} "
                        . "stream={$streamKey} — snapshot empty but students exist; failing closed");
                    return ['state' => 'UNDETERMINED', 'count' => 0, 'reason' => 'snapshot_empty_but_students_exist'];
                }
            } catch (\Throwable $e) {
                return ['state' => 'UNDETERMINED', 'count' => 0, 'reason' => 'probe: ' . $e->getMessage()];
            }
            return ['state' => 'EMPTY', 'count' => 0, 'reason' => 'school_has_no_students'];
        }

        $sessionCmp = mb_strtolower(trim($session));
        $count = 0;

        foreach ($docs as $row) {
            $d = is_array($row) ? ($row['data'] ?? $row) : null;
            if (!is_array($d)) continue;

            $sid = (string) ($d['studentId'] ?? $d['userId'] ?? $d['User Id'] ?? '');
            if ($sid === '') continue;

            // Same status semantics as the canonical roster rule
            // (MY_Controller:1280) — both shapes, ABSENT means Active.
            $status = trim((string) ($d['status'] ?? $d['Status'] ?? 'Active'));
            if ($status !== '' && strcasecmp($status, 'Active') !== 0) continue;

            // Same blank-session tolerance as the canonical roster rule
            // (MY_Controller:1288): a legacy blank session is kept, never
            // excluded. Stream definitions themselves are NOT session-scoped
            // (they live on the school document), but the students blocking a
            // delete must belong to the session being operated on.
            $rowSession = mb_strtolower(trim((string) ($d['session'] ?? '')));
            if ($sessionCmp !== '' && $rowSession !== '' && $rowSession !== $sessionCmp) continue;

            $section = (string) ($d['section'] ?? $d['Section'] ?? '');
            if (self::_sectionBelongsToStream($section, $streamKey)) $count++;
        }

        return [
            'state'  => $count > 0 ? 'NON_EMPTY' : 'EMPTY',
            'count'  => $count,
            'reason' => 'stream_named_section',
        ];
    }

    /**
     * Does a section token belong to $streamKey?
     *
     * Accepts either stored form — "Section Science A" or "Science A" — and
     * matches "{stream} {letter}", the shape bulk_save_sections() creates and
     * normalizeClassSection() preserves. Case-insensitive because stream keys
     * are `[A-Za-z0-9_]+` while section labels are operator-typed.
     */
    private static function _sectionBelongsToStream(string $sectionToken, string $streamKey): bool
    {
        $t = trim(preg_replace('/^Section\s+/i', '', trim($sectionToken)));
        if ($t === '' || $streamKey === '') return false;
        return (bool) preg_match('/^' . preg_quote($streamKey, '/') . '\s+[A-Za-z]$/i', $t);
    }

    /**
     * Sections belonging to $streamKey in $session.
     *
     * The existing `sections` dependency check filters on `sections.streamKey`
     * — a field NO writer produces (Firestore_service::saveSection does not
     * stamp it; Academic.php:847 and Classes.php:340/595 do not either), and
     * which the live probe found on 0 of 35 section documents. That check was
     * therefore inert too. This restores its INTENDED behaviour using the same
     * stream-named-section rule as the student check; it does not add a new
     * class of block.
     *
     * @return array{state:string, count:int, reason:string}
     */
    private function _stream_live_sections(string $streamKey, string $session): array
    {
        $cap = 500;
        try {
            $rows = $this->fs->schoolWhere('sections', [], null, 'ASC', $cap);
        } catch (\Throwable $e) {
            return ['state' => 'UNDETERMINED', 'count' => 0, 'reason' => 'sections: ' . $e->getMessage()];
        }

        // Cap saturation would silently truncate the scan, and a truncated
        // scan that finds nothing is NOT proof of emptiness — it is exactly
        // the fail-open this phase exists to remove. Refuse instead.
        if (is_array($rows) && count($rows) >= $cap) {
            log_message('error',
                "ACC_STREAM_SECTIONS_SCAN_SATURATED schoolId={$this->school_id} "
                . "stream={$streamKey} cap={$cap} — cannot prove emptiness; failing closed");
            return ['state' => 'UNDETERMINED', 'count' => 0, 'reason' => 'sections_scan_saturated'];
        }

        $sessionCmp = mb_strtolower(trim($session));
        $count = 0;
        foreach ((is_array($rows) ? $rows : []) as $row) {
            $d = is_array($row) ? ($row['data'] ?? $row) : null;
            if (!is_array($d)) continue;

            $rowSession = mb_strtolower(trim((string) ($d['session'] ?? '')));
            if ($sessionCmp !== '' && $rowSession !== '' && $rowSession !== $sessionCmp) continue;

            // Honour an explicit streamKey where one exists (nothing writes it
            // today, but a future writer must not be ignored), else fall back
            // to the stream-named section rule.
            $explicit = trim((string) ($d['streamKey'] ?? $d['stream'] ?? ''));
            if ($explicit !== '' && strcasecmp($explicit, $streamKey) === 0) { $count++; continue; }

            if (self::_sectionBelongsToStream((string) ($d['section'] ?? ''), $streamKey)) $count++;
        }

        return ['state' => $count > 0 ? 'NON_EMPTY' : 'EMPTY', 'count' => $count, 'reason' => 'stream_named_section'];
    }

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
    /**
     * ── PHASE 20.1c (P20-F9) — FAIL CLOSED ON AN UNVERIFIABLE DEPENDENCY ────
     *
     * THE DEFECT THIS CLOSES: _dep_count_safe() catches each check's exception,
     * records `errors[$key]`, and then leaves `has_any` false and `by_key[$key]`
     * unset. A dependency query that FAILED is therefore indistinguishable from
     * one that found nothing, and every caller's `if (!empty($deps['has_any']))`
     * evaluates false — so the delete proceeds. Proven by reading the catch
     * block at :597-599.
     *
     * That is a fail-OPEN on the checks that exist specifically to prevent
     * orphaning live records. Phase 20.1 closed it inline at delete_section()
     * and Phase 20.1b at delete_stream(); this helper closes the remaining
     * three callers uniformly.
     *
     * ── WHY NOT FIX _dep_count_safe() ITSELF ────────────────────────────────
     * Changing it centrally would alter behaviour at five endpoints in one
     * edit, including two that are already correct and proven by tests. The
     * partial-result contract is also deliberate: `errors[]` exists so a caller
     * CAN distinguish the states. The defect was never that _dep_count_safe()
     * hides failures — it reports them — but that callers ignored the report.
     * This helper makes honouring it a one-line call, without touching the
     * collector or the two already-fixed paths.
     *
     * Does not return when it fires: _json_error_with_data() calls exit (:626).
     */
    private function _dep_fail_closed(array $deps, string $endpoint, string $entityLabel, string $entityId, array $extra = []): void
    {
        if (empty($deps['errors'])) return;

        $failed = array_keys($deps['errors']);
        log_message('error',
            "ACC_DEP_UNDETERMINED endpoint={$endpoint} schoolId={$this->school_id} "
            . "actor={$this->admin_id} entity={$entityId} failed_checks=" . implode(',', $failed));

        $this->_json_error_with_data(
            "Cannot delete {$entityLabel} '{$entityId}': dependent records could not be verified ("
            . implode(', ', $failed) . '). Nothing has been changed. Please retry in a moment.',
            array_merge([
                'reason'        => 'dependencies_undetermined',
                'failed_checks' => $failed,
            ], $extra),
            409
        );
    }

    /**
     * ── PHASE 20.2 (P20.2-CAP-1) — DISPLAY-ONLY CAPACITY RESOLUTION ─────────
     *
     * Mirrors MY_Controller::_sectionCapacity()'s D17 rule EXACTLY:
     *   configured positive wins · absent / 0 / negative / non-numeric → 40.
     *
     * ── WHY A MIRROR RATHER THAN THE CANONICAL ACCESSOR ─────────────────────
     * _sectionCapacity() resolves ONE section and issues an uncached
     * Firestore_service::get() per call (:310 — a live round-trip, no cache).
     * The sections listing already holds every section document in memory from
     * one schoolWhere(); routing it through the canonical accessor would add
     * one network round-trip PER SECTION — 35 on the probed tenant, at the
     * ~1-3s/op this codebase documents (see bulk_save_sections' set_time_limit
     * rationale). That is precisely the N+1 this phase is required to avoid,
     * and it would be slower than the 2,092 ms screen it replaces.
     *
     * ── WHY THIS IS SAFE DESPITE BEING A SECOND COPY OF THE RULE ────────────
     * This value is DISPLAY ONLY. No placement decision consults it. Every
     * admission / promotion / import / enroll / rollover / transfer continues
     * to resolve capacity through _sectionCapacityExceeded() →
     * _sectionCapacity(), which is untouched. If this mirror ever drifted, a
     * number on screen would be wrong — no student would be wrongly admitted
     * or refused.
     *
     * Drift is additionally guarded: Phase201cCapacityWorkflowTest asserts this
     * resolver and the canonical rule agree across the whole input space
     * (absent, 0, negative, non-numeric, string-numeric, positive).
     *
     * ⚠ Do NOT call this from an enforcement path. Registered follow-up
     * P20.2-CAP-4: a bulk capacity accessor on MY_Controller would remove the
     * mirror entirely, but MY_Controller is a protected file requiring separate
     * authorization.
     */
    private static function _display_capacity($rawDoc): int
    {
        $raw = 0;
        if (is_array($rawDoc)) {
            $raw = (int) ($rawDoc['maxStrength'] ?? $rawDoc['max_strength'] ?? 0);
        }
        return $raw > 0 ? $raw : 40;
    }

    /** True when the section document carries an operator-configured capacity. */
    private static function _capacity_is_configured($rawDoc): bool
    {
        if (!is_array($rawDoc)) return false;
        return ((int) ($rawDoc['maxStrength'] ?? $rawDoc['max_strength'] ?? 0)) > 0;
    }

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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_profile', 'Configuration', 'edit');
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

        // Phase 1 ZenX HRMS: org_type is capability-gated separately from the
        // rest of this endpoint's fields — a tenant without HRMS_CORE_CAPABILITY
        // simply cannot submit it, so its (pre-existing) profile fields keep
        // saving exactly as before regardless of this capability's state.
        $orgTypeRaw = trim((string) $this->input->post('org_type', TRUE));
        if ($orgTypeRaw !== '') {
            if (!has_capability(HRMS_CORE_CAPABILITY)) {
                return $this->json_error('This feature is not enabled for your school.');
            }
            if (!in_array($orgTypeRaw, ['EDUCATION', 'CORPORATE', 'RETAIL'], true)) {
                return $this->json_error('org_type must be one of EDUCATION, CORPORATE, RETAIL.');
            }
            $data['orgType'] = $orgTypeRaw;
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

        // Firestore (sole write target). This routes through Firestore_service::set
        // → _bustDashboard, so the dashboard data cache is invalidated automatically.
        $this->fs->saveSchool($data);

        // Live-refresh the operator's session so the new name shows immediately
        // across the UI (sidebar header + dashboard "Welcome" line read the
        // school name from session['school_display_name'], which is otherwise
        // only seeded at login — hence a renamed school looked unchanged until
        // re-login). Only the acting user's session is updated here; other
        // signed-in admins pick it up on their next login.
        if (!empty($data['display_name'])) {
            $this->session->set_userdata('school_display_name', $data['display_name']);
        }

        // Ensure this school has a readable public admission slug so the QR /
        // shareable link parents see is `…/admission/form/st-marys-school`
        // instead of the raw SCH_ id. Centralized + self-healing (see
        // ensure_admission_slug in public_url_helper). Non-fatal: a slug
        // failure must never block a profile save (raw-id URL always works).
        $admissionSlug = '';
        if (!empty($data['display_name'])) {
            $this->load->helper('public_url');
            $admissionSlug = ensure_admission_slug($this->firebase, $school_id, $data['display_name']);
        }

        log_audit('Configuration', 'save_profile', $school, 'Updated school profile');

        $this->json_success([
            'message'          => 'Profile saved successfully.',
            'display_name'     => $data['display_name'] ?? null, // let the page update the header live
            'admission_slug'   => $admissionSlug !== '' ? $admissionSlug : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/save_terminology  — Phase 1 ZenX HRMS
    //
    // Saves the 3-key terminology dictionary (person/location/manager).
    // A whole new endpoint, not folded into save_profile(), since it has a
    // materially different shape (a nested map vs. flat scalar fields) and
    // its own capability gate — every check here is in addition to, never
    // instead of, save_profile()'s existing ADMIN_ROLES/Configuration gate.
    // ─────────────────────────────────────────────────────────────────────
    public function save_terminology()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_terminology', 'Configuration', 'edit');
        require_capability(HRMS_CORE_CAPABILITY, 'school_config_save_terminology');

        $keys = ['person', 'location', 'manager'];
        $labels = [];
        foreach ($keys as $key) {
            $val = trim((string) $this->input->post($key, TRUE));
            if ($val === '') {
                return $this->json_error("Please provide a label for '{$key}'.");
            }
            if (strlen($val) > 60) {
                return $this->json_error("Label for '{$key}' exceeds 60 characters.");
            }
            $labels[$key] = $val;
        }

        try {
            $this->fs->update('schools', $this->school_id, ['labels' => $labels]);
        } catch (\Throwable $e) {
            log_message('error', 'School_config::save_terminology write failed: ' . $e->getMessage());
            return $this->json_error('Could not save terminology — please try again.');
        }

        log_audit('Configuration', 'save_terminology', $this->school_name, 'Updated HRMS terminology labels');
        $this->json_success(['message' => 'Terminology saved successfully.', 'labels' => $labels]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/save_forget_password_details
    //
    // Edits the school super admin's password-recovery contact (name/email/
    // number). Writes ONLY the denormalized recovery block on schools/{id} —
    // it deliberately does NOT touch the canonical schoolSsa/{ssaId} doc, so
    // editing the recovery contact never mutates the SSA's user record.
    // ─────────────────────────────────────────────────────────────────────
    public function save_forget_password_details()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_forget_password_details', 'Configuration', 'manage');

        $name   = trim((string) $this->input->post('name',   TRUE));
        $email  = strtolower(trim((string) $this->input->post('email',  TRUE)));
        $number = trim((string) $this->input->post('number', TRUE));

        if ($name === '' || $email === '' || $number === '') {
            return $this->json_error('Name, email and number are all required.');
        }
        if (strlen($name) > 200 || strlen($email) > 200 || strlen($number) > 50) {
            return $this->json_error('One or more fields exceed the allowed length.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json_error('Invalid email address.');
        }
        if (!preg_match('/^[\d\s\+\-\(\)]{7,20}$/', $number)) {
            return $this->json_error('Invalid phone number.');
        }

        $block = ['name' => $name, 'email' => $email, 'number' => $number];

        // Recovery block on the school doc only. update() replaces the whole
        // forget_password_details map, so any legacy `id` subfield is dropped.
        $okSchool = $this->fs->update('schools', $this->fs->schoolId(), [
            'forget_password_details' => $block,
            'updatedAt'               => date('c'),
        ]);
        if (!$okSchool) {
            return $this->json_error('Failed to save recovery contact. Please try again.');
        }

        log_audit('Configuration', 'save_forget_password_details', $this->school_name, 'Updated password-recovery contact');

        return $this->json_success([
            'message'                 => 'Password-recovery contact saved.',
            'forget_password_details' => $block,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/upload_logo
    // ─────────────────────────────────────────────────────────────────────
    public function upload_logo()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_upload_logo', 'Configuration', 'edit');
        $school    = $this->school_name;
        $school_id = $this->school_id;

        // Storage path keys on the SCH_ id; bail if the session lacks it
        // rather than writing to an "schools//logos/" path.
        if (empty($school_id)) {
            return $this->json_error('Session missing school id; please re-login.');
        }

        if (empty($_FILES['logo']['name'])) {
            return $this->json_error('No file uploaded.');
        }

        $tempDir = $this->_upload_staging_dir();

        $config = [
            'upload_path'   => $tempDir,
            'allowed_types' => 'jpg|jpeg|png|gif|webp',
            'max_size'      => 2048,
            'encrypt_name'  => TRUE,
        ];

        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('logo')) {
            // BUG diagnostic (2026-05-28): CI's "filetype not allowed" can fire
            // even for a valid PNG when MIME detection returns something not in
            // mimes.php for the extension. Surface the ACTUAL detected MIME so
            // the cause is pinned (real bad file vs detection mismatch) instead
            // of guessed. fileinfo is the same detector CI uses internally.
            $detected = '';
            $tmp = $_FILES['logo']['tmp_name'] ?? '';
            if ($tmp !== '' && is_uploaded_file($tmp) && function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) { $detected = (string) finfo_file($fi, $tmp); finfo_close($fi); }
            }
            $err = $this->upload->display_errors('', '');
            log_message('error',
                "upload_logo rejected: {$err} | detected_mime=" . ($detected ?: 'unknown')
                . " | browser_type=" . ($_FILES['logo']['type'] ?? 'n/a')
                . " | name=" . ($_FILES['logo']['name'] ?? 'n/a'));
            return $this->json_error($err . ($detected !== '' ? " (detected type: {$detected})" : ''));
        }

        $info       = $this->upload->data();
        $localPath  = $info['full_path'];
        // Align with onboarding (Superadmin_schools::_promote_temp_logo_to_storage):
        // store under the canonical SCH_ id, plural "logos" folder. The previous
        // file is deleted below via the stored logoUrl (not a reconstructed path),
        // so changing this convention does not orphan existing logos.
        $remotePath = "schools/{$school_id}/logos/" . $info['file_name'];

        // Capture the PREVIOUS logo's Storage path BEFORE we overwrite the
        // pointer, so we can delete the old file after the new one commits
        // (orphan-leak cleanup — each upload uses a fresh random filename, so
        // the old object would otherwise linger forever).
        $prevPath = '';
        try {
            $schoolDoc = $this->fs->get('schools', $this->fs->schoolId());
            $prevUrl   = is_array($schoolDoc) ? (string)($schoolDoc['logoUrl'] ?? '') : '';
            if ($prevUrl !== '') $prevPath = $this->firebase->storagePathFromUrl($prevUrl);
        } catch (\Throwable $_) {}

        $uploaded = $this->firebase->uploadFile($localPath, $remotePath);
        @unlink($localPath);

        if (!$uploaded) {
            return $this->json_error('Failed to upload logo to storage.');
        }

        $url = $this->firebase->getDownloadUrl($remotePath);

        // Firestore (sole write target)
        $this->fs->saveSchool(['logo_url' => $url, 'logo_updated_at' => date('Y-m-d H:i:s')]);

        // Now that the new logo is committed, delete the previous file.
        // Best-effort: a failed cleanup never fails the upload; the path
        // guard ensures we never delete the file we just uploaded.
        if ($prevPath !== '' && $prevPath !== $remotePath) {
            $this->firebase->deleteStorageFile($prevPath);
        }

        // M4: evict the PREVIOUS logo's render-cache entry immediately. The new
        // URL already maps to a new cache key (auto-miss → re-fetch), so this is
        // a best-effort cleanup, not a correctness requirement. Disposable cache.
        if ($prevUrl !== '') {
            try {
                $this->load->helper('public_url');
                $this->load->driver('cache', ['adapter' => 'file']);
                $this->cache->delete(school_logo_cache_key($school_id, $prevUrl));
            } catch (\Throwable $_) { /* non-fatal */ }
        }

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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_upload_document', 'Configuration', 'edit');
        $school = $this->school_name;
        $type   = trim((string) $this->input->post('doc_type', TRUE));

        $allowed = ['holidays_calendar', 'academic_calendar'];
        if (!in_array($type, $allowed, true)) {
            return $this->json_error('Invalid document type.');
        }

        if (empty($_FILES['document']['name'])) {
            return $this->json_error('No file uploaded.');
        }

        $tempDir = $this->_upload_staging_dir();

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
        $folder     = $type === 'holidays_calendar' ? 'holidays' : 'academic';
        // Canonical Storage scheme: schools/{schoolId}/... (ID-keyed, rename-proof).
        // Previously keyed by sanitized school NAME.
        $remotePath = "schools/{$this->school_id}/{$folder}/" . $info['file_name'];

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
    /**
     * ADM-AGE-1 (2026-09-19) — save the class-vs-age admission policy.
     *
     * DELIBERATELY TENANT POLICY, NOT CODE. The age band for a class is not a
     * property of this software:
     *   - education is a concurrent subject, so states differ;
     *   - CBSE, ICSE and the state boards expect different things;
     *   - the cut-off date is a school choice (31 March, 31 May, 30 September).
     *
     * Hard-coding "6+ as on 31 March" would block every legitimate admission in a
     * state that permits 5+, the front office would start overriding the system on
     * day one, and once staff are routing around a check, every check you own is
     * decorative. So the school states its own rule and chooses how hard it bites.
     */
    public function save_age_policy()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_age_policy', 'Configuration', 'edit');

        $mode = trim((string) $this->input->post('mode', TRUE));
        if (!in_array($mode, ['off', 'warn', 'block'], true)) {
            return $this->json_error('Mode must be off, warn or block.');
        }

        // Cut-off as MM-DD. Validated as a real calendar day so 02-31 cannot be
        // stored and then silently roll every year the policy is evaluated.
        $cutoff = trim((string) $this->input->post('cutoff', TRUE));
        if ($cutoff === '') $cutoff = '03-31';
        if (!preg_match('/^(\d{2})-(\d{2})$/', $cutoff, $m)
            || !checkdate((int) $m[1], (int) $m[2], 2024)) {
            return $this->json_error('Cut-off must be a real date in MM-DD form, e.g. 03-31.');
        }

        // bands[<class name>][min|max] — a class with neither is simply unbanded,
        // which is how a school opts a class out without deleting its row.
        $raw   = $this->input->post('bands');
        $bands = [];
        if (is_array($raw)) {
            foreach ($raw as $className => $b) {
                $className = trim((string) $className);
                if ($className === '') continue;
                $min = trim((string) ($b['min'] ?? ''));
                $max = trim((string) ($b['max'] ?? ''));
                if ($min === '' && $max === '') continue;

                $min = $min === '' ? null : max(0, min(30, (int) $min));
                $max = $max === '' ? null : max(0, min(30, (int) $max));
                if ($min !== null && $max !== null && $min > $max) {
                    return $this->json_error(
                        "Minimum age is greater than maximum for {$className}. No admission could satisfy that."
                    );
                }
                $bands[$className] = ['min' => $min, 'max' => $max];
            }
        }

        if ($mode !== 'off' && !$bands) {
            return $this->json_error(
                'Set an age band for at least one class before turning the policy on, '
                . 'or it would check nothing while claiming to.'
            );
        }

        $policy = [
            'mode'      => $mode,
            'cutoff'    => $cutoff,
            'bands'     => $bands,
            'updatedAt' => date('c'),
        ];

        $this->fs->update('schools', $this->fs->schoolId(),
            ['admissionAgePolicy' => $policy, 'updatedAt' => date('c')]);

        log_audit('Configuration', 'save_age_policy', $this->school_name, sprintf(
            'Admission age policy set to %s with cut-off %s across %d class band(s).',
            $mode, $cutoff, count($bands)
        ), ['mode' => $mode, 'cutoff' => $cutoff, 'classes' => array_keys($bands)]);

        return $this->json_success([
            'message' => $mode === 'off'
                ? 'Age policy saved and turned off — admissions will not be age-checked.'
                : sprintf('Age policy saved: %s, cut-off %s, %d class band(s).',
                          $mode === 'block' ? 'blocking' : 'warning only', $cutoff, count($bands)),
        ]);
    }

    public function save_board()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_board', 'Configuration', 'edit');
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_classes', 'Configuration', 'edit');
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_activate_classes', 'Configuration', 'manage');
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

            // Create a default "Section A" in Firestore for this class.
            // BUG (2026-05-28): pass $sessionYear so activation writes Section A
            // into the TARGET session. Previously saveSection used fs->session
            // (current viewing session), so "Activate Classes for 2027-28" while
            // viewing 2026-27 created sections in 2026-27 instead — the existence
            // pre-check (line ~719) already scoped to $sessionYear, so the two
            // disagreed. Same session-divergence as the other section endpoints.
            $this->fs->saveSection($classNode, 'A', [
                'created_at'  => date('Y-m-d H:i:s'),
                'created_by'  => 'School_config::activate_classes',
            ], $sessionYear);
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_soft_delete_class', 'Configuration', 'manage');
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

                // ── PHASE 20.1 (P20-F1), THIRD SITE ─────────────────────
                // Found during the Part 8 regression sweep — this repeats the
                // same single-shape predicate as the two section-delete
                // guards, with a LARGER blast radius: on a false "empty" it
                // removes the whole class from `schools.classes[]` while
                // students are still enrolled in it. Routed through the same
                // canonical definition.
                //
                // Session comes from the section document being examined, not
                // the operator's viewing session: this loop walks the class's
                // sections across sessions, and a student enrolled in ANY of
                // them must block removal of the class definition.
                $secSession = trim((string) ($d['session'] ?? '')) ?: $this->session_year;
                $roster     = $this->_section_active_students($classNode, $secName, $secSession);

                if (!$roster['determined']) {
                    log_message('error',
                        "ACC_SECTION_DELETE_UNDETERMINED endpoint=soft_delete_class "
                        . "schoolId={$this->school_id} actor={$this->admin_id} "
                        . "sectionKey={$sectionKey} session={$secSession} errors="
                        . implode('; ', $roster['errors']));
                    $this->_json_error_with_data(
                        "Cannot delete class {$classNode}: enrolment for {$secName} could not be "
                        . "verified right now. Nothing has been changed. Please retry in a moment.",
                        [
                            'sectionKey' => $sectionKey,
                            'session'    => $secSession,
                            'reason'     => 'roster_undetermined',
                        ],
                        409
                    );
                    return;
                }

                if ($roster['count'] > 0) {
                    $stuCount = $roster['count'];
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
        // P20-F9 — refuse when a dependency check could not be evaluated.
        $this->_dep_fail_closed($deps, 'soft_delete_class', 'class', $classKey, ['classKey' => $classKey]);

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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_restore_class', 'Configuration', 'manage');
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_seed_streams', 'Configuration', 'manage');
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_sections', 'Configuration', 'view');
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_section', 'Configuration', 'edit');
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

        // Check existence in Firestore — BUG (2026-05-28): scope to the POSTed
        // target session, not the operator's current viewing session. Without
        // the explicit $sessionYear, sectionDocId/saveSection used fs->session,
        // so adding "Section A for 2027-28" wrongly checked/wrote 2026-27 →
        // "already exists" while the (session-filtered) list showed otherwise.
        $fsDocId = $this->fs->sectionDocId($classNode, $sectionLetter, $sessionYear);
        $fsExists = is_array($this->fs->get('sections', $fsDocId));
        if ($fsExists) {
            return $this->json_error("{$classNode} / {$sectionNode} already exists in {$sessionYear}.");
        }

        $sectionData = ['created_at' => date('Y-m-d H:i:s')];

        // Firestore (sole write target)
        $this->fs->saveSection($classNode, $sectionLetter, $sectionData, $sessionYear);

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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_delete_section', 'Configuration', 'manage');
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
        // BUG (2026-05-28): scope the lookup to the POSTed target session.
        $fsDocId     = $this->fs->sectionDocId($classNode, $sectionLetter, $sessionYear);

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

        // ── PHASE 20.1 (P20-F1) ─────────────────────────────────────────
        // The `students` check MOVED OUT of _dep_count_safe() and now runs
        // through the canonical roster rule. Two reasons it could not stay:
        //
        //   1. Its predicate was single-shape and missed legacy students
        //      (see _section_active_students() for the full rationale).
        //   2. _dep_count_safe() SWALLOWS a query exception — it records
        //      `errors[key]` but leaves `has_any` false, so a failed students
        //      query read as "no students" and the delete proceeded. That is
        //      a fail-OPEN on the one check that protects children.
        //
        // The other five checks stay: they guard non-student collections
        // (feeDemands/attendance/marks/timetables/subjectAssignments) which
        // are canonically written WITH `sectionKey`, so the predicate is
        // correct for them. Their fail-open is closed separately below.
        $roster = $this->_section_active_students($classNode, $sectionNode, $sessionYear);

        if (!$roster['determined']) {
            log_message('error',
                "ACC_SECTION_DELETE_UNDETERMINED schoolId={$this->school_id} actor={$this->admin_id} "
                . "sectionKey={$sectionKey} session={$sessionYear} errors="
                . implode('; ', $roster['errors']));
            return $this->_json_error_with_data(
                'Cannot delete section ' . $sectionKey . ': the student roster could not be '
                . 'verified right now. Nothing has been changed. Please retry in a moment.',
                ['sectionKey' => $sectionKey, 'session' => $sessionYear, 'reason' => 'roster_undetermined'],
                409
            );
        }

        $deps = $this->_dep_count_safe([
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

        // Fold the canonically-derived student count in, so the response shape
        // (`dependencies.students`) is unchanged for the UI.
        $deps['by_key']['students'] = $roster['count'];
        if ($roster['count'] > 0) {
            $deps['has_any'] = true;
            $deps['total']  += $roster['count'];
            array_unshift($deps['human'], $roster['count'] . ' active student(s)');
        }

        // P20-F1 companion fix — fail CLOSED on an unverifiable dependency.
        // _dep_count_safe() catches per-check exceptions and returns partial
        // results; previously a failed check silently counted as zero and the
        // delete proceeded, orphaning the very records the check exists to
        // protect. Uncertainty must never read as "no dependencies".
        if (!empty($deps['errors'])) {
            log_message('error',
                "ACC_SECTION_DELETE_DEP_UNDETERMINED schoolId={$this->school_id} actor={$this->admin_id} "
                . "sectionKey={$sectionKey} session={$sessionYear} failed_checks="
                . implode(',', array_keys($deps['errors'])));
            return $this->_json_error_with_data(
                'Cannot delete section ' . $sectionKey . ': dependent records could not be verified ('
                . implode(', ', array_keys($deps['errors'])) . '). Nothing has been changed.',
                [
                    'sectionKey'     => $sectionKey,
                    'session'        => $sessionYear,
                    'reason'         => 'dependencies_undetermined',
                    'failed_checks'  => array_keys($deps['errors']),
                ],
                409
            );
        }

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
    // POST  /school_config/save_section_capacity
    //
    // PHASE 20.2 (P20.2-CAP-3) — the ONE capacity writer on the modern surface.
    //
    // Writes exactly one field: `maxStrength`, on the canonical section
    // document. It does NOT touch students, roll numbers, SRNO, section
    // identity (className / section / sectionCode / sectionKey / docId) or any
    // capacity RULE. The resolution semantics (D17) live in
    // MY_Controller::_sectionCapacity() and are untouched.
    //
    // ── CAPACITY-REDUCTION POLICY (P20-D5, operator-approved) ─────────────
    // Reducing capacity below current occupancy is ALLOWED, because refusing
    // it would leave a school unable to correct a mistyped 400 without first
    // moving children. It requires explicit confirmation, and it NEVER
    // removes, transfers or renumbers a student:
    //
    //   • first call (no confirm_over_capacity) → 409 `confirm_required`
    //     carrying occupancy + the exact consequence, so the UI can show a
    //     truthful warning instead of inventing one;
    //   • second call with confirmation → saved, every student retained.
    //
    // Existing students stay enrolled and the section simply reads
    // over-capacity until strength falls. Enforcement acts on the NEXT
    // placement, never retroactively.
    //
    // ⚠ Capacity is not race-safe against concurrent PLACEMENT — see
    // P20.2-RACE-1 in the Phase 20.2 report. That is a pre-existing engine
    // property this endpoint neither introduces nor fixes.
    // ─────────────────────────────────────────────────────────────────────
    public function save_section_capacity()
    {
        // RBAC: student-lifecycle capability, per the Phase 20.2 brief.
        // NOTE (P20.2-RBAC-1): section CREATE/DELETE on this same screen is
        // gated on Configuration:edit. The two halves therefore require
        // different capabilities — registered as an open decision.
        // `[]` (empty allow-list) deliberately REMOVES the legacy role-name
        // fallback, making the graded `SIS:manage` capability the sole
        // authority — a user merely NAMED "Principal" cannot bypass it
        // (MY_Controller:1699 falls through to the name list only when the
        // caller supplies one). Super Admin / School Super Admin still pass at
        // :1672-1673, so the operator account is unaffected.
        $this->_require_role([], 'school_config_save_section_capacity', 'SIS', 'manage');

        $classKey    = trim((string) $this->input->post('class_key', TRUE));
        $sectionRaw  = trim((string) $this->input->post('section',   TRUE));
        $sessionYear = trim((string) $this->input->post('session',   TRUE));
        $capacityRaw = (string) $this->input->post('capacity', TRUE);
        $confirmed   = in_array((string) $this->input->post('confirm_over_capacity', TRUE), ['1', 'true', 'yes'], true);

        if ($classKey === '' || $sectionRaw === '') {
            return $this->json_error('class_key and section are required.');
        }
        if ($sessionYear === '') $sessionYear = $this->session_year;
        if (!preg_match('/^\d{4}-\d{2}$/', $sessionYear)) {
            return $this->json_error('Invalid session format.');
        }

        // Capacity must be an explicit positive integer.
        //
        // 0 is REJECTED, not treated as "unlimited". P20-F2 established that
        // the engine's `<= 0 means unlimited` branch is unreachable dead code —
        // _sectionCapacity() initialises to 40 and only raises it — so storing
        // 0 would silently mean 40, the opposite of the operator's intent.
        // Exposing "unlimited" requires an engine change and is explicitly
        // NOT authorized (P20-D2 = NO).
        if (!preg_match('/^\d{1,4}$/', trim($capacityRaw))) {
            return $this->json_error('Capacity must be a whole number.');
        }
        $capacity = (int) trim($capacityRaw);
        if ($capacity < 1) {
            return $this->json_error('Capacity must be at least 1.');
        }
        if ($capacity > 500) {
            return $this->json_error('Capacity above 500 is not supported. Check the value and retry.');
        }

        $classNode   = $this->_class_node_name($classKey);
        $sectionNode = (stripos($sectionRaw, 'Section ') === 0) ? $sectionRaw : "Section {$sectionRaw}";

        // Canonical identity — never hand-built (SEC-2).
        $docId  = $this->fs->sectionDocId($classNode, $sectionNode, $sessionYear);
        $secDoc = $this->fs->get('sections', $docId);
        if (!is_array($secDoc)) {
            return $this->json_error("Section {$classNode} / {$sectionNode} does not exist in {$sessionYear}.");
        }

        // Occupancy via the CANONICAL roster rule — same derivation the
        // capacity guard enforces with. Fails CLOSED: if occupancy cannot be
        // determined we refuse rather than let the operator confirm a warning
        // computed from a number we do not trust.
        try {
            $occupied = count($this->_filterSectionRoster(
                $this->_cachedStudentDocsForRollCheck(), $classNode, $sectionNode, $sessionYear
            ));
        } catch (\Throwable $e) {
            log_message('error',
                "ACC_CAPACITY_OCCUPANCY_UNDETERMINED schoolId={$this->school_id} "
                . "section={$classNode}/{$sectionNode} err=" . $e->getMessage());
            return $this->_json_error_with_data(
                'Current occupancy could not be verified, so capacity was not changed. Please retry in a moment.',
                ['reason' => 'occupancy_undetermined'],
                409
            );
        }

        if ($capacity < $occupied && !$confirmed) {
            return $this->_json_error_with_data(
                "Current occupancy is {$occupied} student(s). The new capacity of {$capacity} is below "
                . 'that. Existing students will remain enrolled, but the section will be over capacity '
                . 'and further placements will be blocked until strength falls below the new capacity.',
                [
                    'reason'       => 'confirm_required',
                    'occupied'     => $occupied,
                    'requested'    => $capacity,
                    'current'      => self::_display_capacity($secDoc),
                    'over_by'      => $occupied - $capacity,
                    'class'        => $classNode,
                    'section'      => $sectionNode,
                ],
                409
            );
        }

        // Merge write — ONLY maxStrength. Identity fields and every other key
        // on the document are left exactly as they are.
        $ok = $this->fs->set('sections', $docId, [
            'maxStrength' => $capacity,
            'updatedAt'   => date('c'),
        ], true);

        if (!$ok) {
            return $this->json_error('Capacity could not be saved. Please retry.');
        }

        log_message('error',
            "ACC_SECTION_CAPACITY_SET schoolId={$this->school_id} actor={$this->admin_id} "
            . "section={$classNode}/{$sectionNode} session={$sessionYear} "
            . "capacity={$capacity} occupied={$occupied}"
            . (($capacity < $occupied) ? ' OVER_CAPACITY_CONFIRMED' : ''));
        log_audit('Configuration', 'save_section_capacity', "{$classNode}/{$sectionNode}",
            "Capacity set to {$capacity} (occupied={$occupied}, session={$sessionYear})");

        $this->json_success([
            'message'   => "Capacity for {$classNode} / {$sectionNode} set to {$capacity}.",
            'capacity'  => $capacity,
            'occupied'  => $occupied,
            'available' => max(0, $capacity - $occupied),
            'over'      => max(0, $occupied - $capacity),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/get_all_sections
    // Returns sections for ALL classes in the given session (bulk view)
    // For stream-enabled classes (11, 12), sections are grouped by stream.
    // ─────────────────────────────────────────────────────────────────────
    public function get_all_sections()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_all_sections', 'Configuration', 'view');
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
        $fsSections    = [];
        $fsSectionDocs = [];   // P20.2-CAP-1 — raw docs, so capacity needs no re-read
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
                    // P20.2-CAP-1 — keep the raw doc so capacity is resolved
                    // from data already fetched, never re-read per section.
                    $fsSectionDocs[$cn][$sn] = $d;
                }
            }
        }

        // ── PHASE 20.2 (P20.2-CAP-2) — OCCUPANCY ────────────────────────
        // ONE shared student snapshot for the whole screen, filtered per
        // section by the CANONICAL roster rule
        // (MY_Controller::_filterSectionRoster). This is the same derivation
        // the capacity guard enforces with, so the number shown and the number
        // enforced cannot disagree — the defect SEC-11 closed.
        //
        // COST: 0 extra Firestore reads when the snapshot is warm (it is
        // memoised per request and file-cached 90s under the key the student
        // list / ID card / fee search already populate); 1 collection read
        // when cold. NOT per-section, NOT per-student.
        //
        // Fails soft on the DISPLAY path only: if the snapshot is unavailable
        // occupancy is reported as null and the UI shows "—" rather than a
        // fabricated 0. Enforcement is unaffected and remains authoritative.
        $rosterSnapshot = null;
        try {
            $rosterSnapshot = $this->_cachedStudentDocsForRollCheck();
        } catch (\Throwable $e) {
            log_message('error', 'get_all_sections occupancy snapshot failed: ' . $e->getMessage());
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

            // ── P20.2-CAP-2 — per-section seat detail ────────────────────
            // Keyed by the SAME label the matrix renders, so the table and the
            // toggles cannot disagree about which section they describe.
            // `capacity_configured` distinguishes an operator decision from the
            // engine's 40 fallback — P20-F3 showed 35/35 sections inheriting
            // 40 that nobody chose. The UI must never present a fallback as a
            // setting (P20-D3: no backfill).
            $seats = [];
            foreach ($rawSections as $rawLabel) {
                $sectionNode = (strpos($rawLabel, 'Section ') === 0) ? $rawLabel : "Section {$rawLabel}";
                $label       = (strpos($rawLabel, 'Section ') === 0)
                    ? substr($rawLabel, 8) : $rawLabel;

                $doc      = $fsSectionDocs[$classNode][$rawLabel] ?? null;
                $capacity = self::_display_capacity($doc);

                $occupied = null;
                if (is_array($rosterSnapshot)) {
                    $occupied = count($this->_filterSectionRoster(
                        $rosterSnapshot, $classNode, $sectionNode, $sessionYear
                    ));
                }

                $seats[$label] = [
                    'capacity'            => $capacity,
                    'capacity_configured' => self::_capacity_is_configured($doc),
                    'occupied'            => $occupied,
                    'available'           => ($occupied === null) ? null : max(0, $capacity - $occupied),
                    'over'                => ($occupied === null) ? null : max(0, $occupied - $capacity),
                    'section_node'        => $sectionNode,
                ];
            }

            $entry = [
                'key'             => $cls['key'],
                'label'           => $cls['label'] ?? $classNode,
                'node'            => $classNode,
                'sections'        => $sections,
                'seats'           => $seats,
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_bulk_save_sections', 'Configuration', 'edit');

        // 2026-06-09 — This batch runs two sequential Firestore round-trips
        // per section (existence-check + write), each ~1-3s. A bulk add across
        // many classes/sections easily exceeds PHP's default 30s
        // max_execution_time, which fatals mid-curl (Firestore_rest_client.php:405)
        // and surfaces to the client as an opaque HTTP 500. Give the batch
        // generous headroom; the per-curl timeout (15s) still bounds any single op.
        @set_time_limit(180);

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
                    // BUG (2026-05-28): scope existence-check + write to the POSTed
                    // target session (was fs->session) so bulk add of "all classes"
                    // doesn't false-positive "already exists" against another session.
                    $fsDocId = $this->fs->sectionDocId($classNode, $sectionLabel, $sessionYear);
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
                    $this->fs->saveSection($classNode, $sectionLabel, ['created_at' => $now], $sessionYear);
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
                    // BUG (2026-05-28): scope to the POSTed target session.
                    $fsDocId = $this->fs->sectionDocId($classNode, $sectionLabel, $sessionYear);
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
                    $sectionNode = "Section {$sectionLabel}";
                    $sectionKey  = "{$classNode}/{$sectionNode}";

                    // ── PHASE 20.1 (P20-F1) ─────────────────────────────
                    // Was a single-shape predicate identical to the one in
                    // delete_section(); it missed legacy-shaped students and
                    // could delete a populated section. Now routed through the
                    // one canonical definition — the SAME helper the single
                    // delete path uses, so the two can no longer diverge.
                    $roster = $this->_section_active_students($classNode, $sectionNode, $sessionYear);

                    if (!$roster['determined']) {
                        // Fail closed: never delete on an unverifiable roster.
                        $skipped[] = "{$classNode} Section {$sectionLabel} (roster unverifiable)";
                        $failed[]  = [
                            'class_key' => $classKey,
                            'section'   => $sectionLabel,
                            'action'    => 'remove',
                            'reason'    => 'roster_undetermined',
                        ];
                        log_message('error',
                            "ACC_SECTION_DELETE_UNDETERMINED endpoint=bulk_save_sections "
                            . "schoolId={$this->school_id} actor={$this->admin_id} "
                            . "sectionKey={$sectionKey} session={$sessionYear} errors="
                            . implode('; ', $roster['errors']));
                        continue;
                    }

                    if ($roster['count'] > 0) {
                        $skipped[] = "{$classNode} Section {$sectionLabel} (has students)";
                        $failed[]  = [
                            'class_key' => $classKey,
                            'section'   => $sectionLabel,
                            'action'    => 'remove',
                            'reason'    => 'has_active_students',
                            'count'     => $roster['count'],
                        ];
                        log_message('error',
                            "ACC_ORPHAN_PREVENTED endpoint=bulk_save_sections "
                            . "schoolId={$this->school_id} actor={$this->admin_id} "
                            . "sectionKey={$sectionKey} students=" . $roster['count']);
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_subjects', 'Configuration', 'view');

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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_all_subjects', 'Configuration', 'view');

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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_suggested_subjects', 'Configuration', 'view');

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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_subject', 'Configuration', 'edit');
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_delete_subject', 'Configuration', 'manage');
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
        // P20-F9 — refuse when a dependency check could not be evaluated.
        $this->_dep_fail_closed($deps, 'delete_subject', 'subject', $entityId);

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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_default_subjects', 'Configuration', 'view');

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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_bulk_subjects', 'Configuration', 'edit');

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

        // ── EXISTING DOCS (read first, and read LOUD) ─────────────────────
        // Needed for two things below: reconciling deletions, and making sure a
        // newly added row is never handed a code that another subject already
        // owns. Read through the *OrFail variant deliberately: schoolWhere()
        // returns [] on failure exactly as it does for a genuinely empty class,
        // and "no existing subjects" is the one reading that would make this
        // endpoint skip every deletion while still reporting success.
        $existing           = [];      // subjectCode => stored name
        $existingReadFailed = false;
        try {
            foreach ($this->fs->schoolWhereOrFail('subjects', [['classKey', '==', (string) $numKey]]) as $row) {
                $d = is_array($row['data'] ?? null) ? $row['data'] : [];
                $c = trim((string) ($d['subjectCode'] ?? ''));
                if ($c === '') continue;
                $existing[$c] = (string) ($d['name'] ?? $d['subject_name'] ?? '');
            }
        } catch (\Throwable $e) {
            $existingReadFailed = true;
            log_message('error',
                "ACC_BULK_EXISTING_READ_FAILED endpoint=save_bulk_subjects schoolId={$this->school_id} "
                . "classKey={$numKey} err=" . $e->getMessage());
        }

        // Build the full subject map to write.
        //
        // ── CODE ALLOCATION ───────────────────────────────────────────────
        // The code IS the identity: subjects/{schoolId}_{classKey}_{code}. The
        // previous allocator derived a blank row's code from its POSITION in the
        // batch (classKey*100 + count+1) while rows loaded from Firestore kept
        // their stored code. Delete the 3rd of 6 subjects, add a replacement, and
        // the new row was handed 906 — a code an existing subject already owned —
        // so $subjectMap[906] silently overwrote it. The operator saw "6 subjects
        // saved" while an untouched subject had been renamed out from under them.
        //
        // Allocation is now two-pass: every code carried in from the client is
        // claimed first, then blank rows take the lowest code nobody holds —
        // "nobody" including subjects currently in Firestore, so a new row can
        // never land on top of an existing one.
        $subjectMap = [];
        $usedNames  = [];
        $claimed    = [];
        $pending    = [];
        foreach (array_keys($existing) as $c) $claimed[$c] = true;

        foreach ($subjects as $sub) {
            if (!is_array($sub)) continue;

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

            $entry = [
                'subject_name' => $name,
                'name'         => $name,
                'category'     => $category,
                'subject_code' => '',
            ];
            if ($stream !== 'common') $entry['stream'] = $stream;

            // A code the client carried back identifies an EXISTING document, so
            // writing to it is the rename/edit path. Anything blank, malformed, or
            // already taken by an earlier row in this same batch goes to pass 2.
            $isReclaim = ($code !== '')
                && preg_match('/^[A-Za-z0-9_]+$/', $code)
                && isset($existing[$code])
                && !isset($subjectMap[$code]);

            if ($isReclaim) {
                $entry['subject_code'] = $code;
                $subjectMap[$code]     = $entry;
                continue;
            }
            $pending[] = $entry;
        }

        // Pass 2 — every unclaimed row takes the lowest free code.
        $seq = 1;
        foreach ($pending as $entry) {
            $code = '';
            while ($seq < 10000) {
                if (is_numeric($numKey)) {
                    $candidate = (string) ((int) $numKey * 100 + $seq);
                } else {
                    $candidate = strtoupper(substr($numKey, 0, 3)) . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
                }
                if (!preg_match('/^[A-Za-z0-9_]+$/', $candidate)) {
                    $candidate = 'SUB' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
                }
                $seq++;
                if (!isset($claimed[$candidate])) { $code = $candidate; break; }
            }
            if ($code === '') continue;   // pathological: code space exhausted

            $claimed[$code]        = true;
            $entry['subject_code'] = $code;
            $subjectMap[$code]     = $entry;
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

        // ── RECONCILE DELETIONS ───────────────────────────────────────────
        // The editor is a whole-list editor and answers "N subjects saved", but
        // this endpoint was write-only: a subject removed in the UI was never
        // removed in Firestore. It reappeared on the next load, and "delete one
        // and replace it" silently kept both. Removals now actually happen.
        //
        // They go through the SAME referential-integrity guard delete_subject
        // enforces: a subject teachers are still assigned to is KEPT and reported,
        // never quietly deleted out from under the Teacher app's auth gate. Every
        // branch here fails SAFE — anything undetermined keeps the subject — and
        // each skip is reported in kept[] rather than swallowed, so a subject that
        // comes back always has a reason attached to it.
        //
        // Deleting is a stronger act than editing, so it is gated on
        // Configuration:manage, matching delete_subject, while saving stays at
        // Configuration:edit. An edit-only operator saves names and categories
        // exactly as before and is told which removals need a manager.
        $removed   = [];
        $kept      = [];
        $canManage = has_permission('Configuration', 'manage');

        if ($existingReadFailed) {
            $kept[] = [
                'subject_code' => '',
                'name'         => '',
                'reason'       => 'Existing subjects could not be read, so no removals were applied.',
            ];
        } else {
            foreach ($existing as $code => $existingName) {
                if (isset($subjectMap[$code])) continue;   // still in the submitted list

                if (!$canManage) {
                    $kept[] = [
                        'subject_code' => (string) $code,
                        'name'         => $existingName,
                        'reason'       => 'Removing a subject needs Configuration: manage.',
                    ];
                    continue;
                }

                $deps = $this->_dep_count_safe([
                    [
                        'key'        => 'subjectAssignments',
                        'collection' => 'subjectAssignments',
                        'filters'    => [
                            ['classKey',    '==', $numKey],
                            ['subjectCode', '==', (string) $code],
                        ],
                        'label'      => 'active teacher assignment(s)',
                    ],
                ]);

                if (!empty($deps['errors'])) {
                    log_message('error',
                        "ACC_DEP_UNDETERMINED endpoint=save_bulk_subjects schoolId={$this->school_id} "
                        . "actor={$this->admin_id} entity={$numKey}_{$code} failed_checks=subjectAssignments");
                    $kept[] = [
                        'subject_code' => (string) $code,
                        'name'         => $existingName,
                        'reason'       => 'Teacher assignments could not be verified, so it was not removed.',
                    ];
                    continue;
                }

                if (!empty($deps['has_any'])) {
                    $this->_log_dep_block('save_bulk_subjects', "{$numKey}_{$code}", $deps);
                    $kept[] = [
                        'subject_code' => (string) $code,
                        'name'         => $existingName,
                        'reason'       => 'Assigned to ' . $deps['by_key']['subjectAssignments']
                                          . ' teacher(s) — reassign those first.',
                    ];
                    continue;
                }

                try {
                    $this->fs->removeEntity('subjects', "{$numKey}_{$code}");
                    $removed[] = ['subject_code' => (string) $code, 'name' => $existingName];
                    log_message('error',
                        "ACC_SUBJECT_DELETED schoolId={$this->school_id} actor={$this->admin_id} "
                        . "classKey={$numKey} subjectCode={$code} via=save_bulk_subjects");
                } catch (\Throwable $e) {
                    $kept[] = [
                        'subject_code' => (string) $code,
                        'name'         => $existingName,
                        'reason'       => 'Removal failed: ' . $e->getMessage(),
                    ];
                    log_message('error',
                        "ACC_BULK_REMOVE_FAILED endpoint=save_bulk_subjects schoolId={$this->school_id} "
                        . "classKey={$numKey} subjectCode={$code} err=" . $e->getMessage());
                }
            }
        }

        $failedCount  = count($failed);
        $removedCount = count($removed);
        $keptCount    = count($kept);

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

        log_audit('Configuration', 'save_bulk_subjects', $classKey,
            "Bulk subjects update for class {$classKey}: {$saved} saved, {$failedCount} failed, "
            . "{$removedCount} removed, {$keptCount} kept");

        // The message reports removals and refusals too. "6 subjects saved" while
        // a 7th quietly survived in Firestore is precisely how this defect stayed
        // invisible: a count of what was written is not a description of the
        // resulting list.
        $message = $saved . ' subject(s) saved for class ' . $classKey;
        if ($removedCount > 0) $message .= ", {$removedCount} removed";
        if ($keptCount    > 0) $message .= ", {$keptCount} could not be removed — see below";
        if ($failedCount  > 0) $message .= ", {$failedCount} failed — see failed[]";
        $message .= '.';

        $this->json_success([
            'message'         => $message,
            'count'           => $saved,
            'failed'          => $failed,
            'failed_count'    => $failedCount,
            'removed'         => $removed,
            'removed_count'   => $removedCount,
            'kept'            => $kept,
            'kept_count'      => $keptCount,
            'partial_failure' => $failedCount > 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/save_stream
    // ─────────────────────────────────────────────────────────────────────
    public function save_stream()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_stream', 'Configuration', 'edit');
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
    // POST  /school_config/save_streams
    // Bulk "Save All Streams" — ONE read, ONE CAS check, ONE commitBatch write.
    // Atomically upserts the full client-supplied stream set into
    // schools/{id}.streams (all-or-nothing). Individual Add/Edit still uses
    // save_stream(); this endpoint backs ONLY the "Save All Streams" button, so
    // Streams matches the single-request bulk-save architecture already used by
    // Classes (save_classes) / Sections (bulk_save_sections) / Subjects
    // (save_bulk_subjects). Same data model, same 'streams' lock, same CAS
    // discipline, same validation as save_stream — no schema/contract change.
    // Upsert (non-destructive): deletion remains the job of delete_stream, so
    // Save-All semantics are preserved exactly (never wipes unlisted streams).
    // 2026-07-23.
    // ─────────────────────────────────────────────────────────────────────
    public function save_streams()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_streams');
        $school = $this->school_name;

        // Accept a JSON array [{stream_key|key, label, enabled}, ...] OR a JSON
        // object map {key:{key,label,enabled}}. Normalise to a validated map.
        $raw     = $this->input->post('streams', TRUE);
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded) || empty($decoded)) {
            return $this->json_error('No streams provided.');
        }

        // Validate EVERY stream up-front — atomic: reject the whole batch on any
        // invalid row, using the SAME rules as save_stream() (BUG-029 label cap).
        $incoming = [];
        foreach ($decoded as $k => $item) {
            $item       = is_array($item) ? $item : (array) $item;
            $streamKey  = trim((string) ($item['stream_key'] ?? $item['key'] ?? (is_string($k) ? $k : '')));
            $label      = trim((string) ($item['label'] ?? ''));
            $rawEnabled = $item['enabled'] ?? false;
            $enabled    = ($rawEnabled === true || $rawEnabled === 1 || $rawEnabled === '1' || $rawEnabled === 'true');

            if ($streamKey === '' || $label === '') {
                return $this->json_error('Each stream requires a key and a label.', 422);
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/', $streamKey)) {
                return $this->json_error("Invalid stream key '{$streamKey}'. Use letters, digits, underscores only.", 422);
            }
            if (strlen($label) > 100) {
                return $this->json_error("Stream label for '{$streamKey}' exceeds 100 characters.", 422);
            }
            // last-wins on duplicate keys (matches map semantics)
            $incoming[$streamKey] = ['key' => $streamKey, 'label' => $label, 'enabled' => $enabled];
        }
        if (empty($incoming)) {
            return $this->json_error('No valid streams provided.');
        }

        // Shared 'streams' lock — serialize against save_stream / seed_streams /
        // delete_stream (BUG-028 Phase 1). Firestore __updateTime precondition
        // catches cross-process races.
        $lock = $this->_config_lock_acquire('streams');
        if ($lock === null) {
            log_message('error',
                "ACC_CONCURRENT_REJECTED endpoint=save_streams schoolId={$this->school_id} "
                . "actor={$this->admin_id} reason=lock_timeout");
            return $this->json_error(
                'Another stream-management operation is in progress. Please retry in a few seconds.',
                409
            );
        }
        try {
            // ── Single read of the schools doc ──────────────────────────────
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

            // Upsert incoming set into the existing map (non-destructive).
            foreach ($incoming as $key => $data) {
                $allStreams[$key] = $data;
            }

            // ── Single CAS commit — one write, all-or-nothing ───────────────
            // merge=>true emits an updateMask so only `streams` + `updatedAt`
            // are touched (2026-05-27 DOC-MERGE-FIX). precondition=updateTime is
            // the optimistic-concurrency guard (identical to save_stream).
            $ops = [[
                'op'         => 'update',
                'collection' => 'schools',
                'docId'      => $schoolDocId,
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
                    "ACC_SAVE_STREAMS_COMMIT_FAILED schoolId={$this->school_id} "
                    . "err=" . $e->getMessage());
            }
            if (!$committed) {
                log_message('error',
                    "ACC_CONCURRENT_REJECTED endpoint=save_streams schoolId={$this->school_id} "
                    . "actor={$this->admin_id} reason=cas_failed");
                return $this->json_error(
                    'Another administrator updated school configuration during your request. '
                    . 'Please reload the page and retry.', 409);
            }

            $count = count($incoming);
            log_audit('Configuration', 'save_streams', $school, "Bulk-saved {$count} stream(s)");

            $this->json_success([
                'message' => "{$count} stream(s) saved.",
                'streams' => (object) $allStreams,
                'saved'   => $count,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'save_streams error: ' . $e->getMessage());
            $this->json_error('Failed to save streams. Contact administrator.');
        } finally {
            $this->_config_lock_release($lock);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/delete_stream
    // ─────────────────────────────────────────────────────────────────────
    public function delete_stream()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_delete_stream', 'Configuration', 'manage');
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

            // ── PHASE 20.1b (P20-F8) ────────────────────────────────────
            // The `students` and `sections` checks MOVED OUT of
            // _dep_count_safe(). Both filtered on `streamKey`, a field no
            // writer in this application ever produces — on students OR on
            // sections — so both were inert and could never block a delete.
            // See _stream_active_students() for the full trace.
            //
            // They are now resolved through the canonical stream-membership
            // rule (stream-named sections), tri-state and fail-closed.
            //
            // `subjectAssignments` stays in _dep_count_safe(): it filters on
            // `stream`, which IS the field written for that shape.
            $streamStudents = $this->_stream_active_students($streamKey, $curSession);
            $streamSections = $this->_stream_live_sections($streamKey, $curSession);

            foreach ([['students', $streamStudents], ['sections', $streamSections]] as [$what, $res]) {
                if ($res['state'] === 'UNDETERMINED') {
                    log_message('error',
                        "ACC_STREAM_DELETE_UNDETERMINED schoolId={$this->school_id} actor={$this->admin_id} "
                        . "streamKey={$streamKey} session={$curSession} check={$what} reason={$res['reason']}");
                    $this->_json_error_with_data(
                        "Cannot delete stream '{$streamKey}': {$what} could not be verified right now. "
                        . 'Nothing has been changed. Please retry in a moment.',
                        [
                            'streamKey' => $streamKey,
                            'session'   => $curSession,
                            'reason'    => 'dependencies_undetermined',
                            'check'     => $what,
                        ],
                        409
                    );
                    return;
                }
            }

            $deps = $this->_dep_count_safe([
                [
                    'key'        => 'subjectAssignments',
                    'collection' => 'subjectAssignments',
                    'filters'    => [['stream', '==', $streamKey], ['session', '==', $curSession]],
                    'label'      => 'subject assignment(s)',
                ],
            ]);

            // Fold the canonically-derived counts back in, preserving the
            // existing `dependencies: {...}` response shape for the UI.
            $deps['by_key']['students'] = $streamStudents['count'];
            $deps['by_key']['sections'] = $streamSections['count'];
            if ($streamSections['count'] > 0) {
                $deps['has_any'] = true;
                $deps['total']  += $streamSections['count'];
                array_unshift($deps['human'], $streamSections['count'] . ' live section(s)');
            }
            if ($streamStudents['count'] > 0) {
                $deps['has_any'] = true;
                $deps['total']  += $streamStudents['count'];
                array_unshift($deps['human'], $streamStudents['count'] . ' active student(s)');
            }

            // Fail CLOSED on an unverifiable remaining check — same defect
            // closed for section deletion in Phase 20.1.
            if (!empty($deps['errors'])) {
                log_message('error',
                    "ACC_STREAM_DELETE_DEP_UNDETERMINED schoolId={$this->school_id} actor={$this->admin_id} "
                    . "streamKey={$streamKey} failed_checks=" . implode(',', array_keys($deps['errors'])));
                $this->_json_error_with_data(
                    "Cannot delete stream '{$streamKey}': dependent records could not be verified ("
                    . implode(', ', array_keys($deps['errors'])) . '). Nothing has been changed.',
                    [
                        'streamKey'     => $streamKey,
                        'session'       => $curSession,
                        'reason'        => 'dependencies_undetermined',
                        'failed_checks' => array_keys($deps['errors']),
                    ],
                    409
                );
                return;
            }

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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_sync_sessions', 'Configuration', 'manage');

        // ── SC-Step3 (Session Convergence — 2026-06-02): lock + CAS ──
        // sync_sessions is a reconciliation read-modify-write. Pre-Step-3:
        // stale-read + write had a race window that could clobber a
        // concurrent add_session/delete_session/archive_session commit
        // (race class identical to SC-Step2 on switch_session).
        // Post-Step-3: same `sessions` filesystem lock + Firestore
        // __updateTime CAS precondition as the canonical lifecycle
        // writers. PHP userdata refresh occurs INSIDE the lock AFTER
        // successful FS commit (D1 — matches SC-Step2 ordering).
        //
        // Hot-path optimization: most calls don't need to write (sessions
        // already populated + active in list). Those calls bypass the
        // lock+CAS overhead entirely; only the auto-seed branch acquires.

        // Pre-read (BEFORE lock) for early-exit on no-write case.
        $fsSchoolPre = $this->fs->get('schools', $this->fs->schoolId());
        if (!is_array($fsSchoolPre)) $fsSchoolPre = [];
        $sessionsPre = (is_array($fsSchoolPre['sessions'] ?? null))
            ? array_values(array_filter($fsSchoolPre['sessions'], 'is_string'))
            : [];
        $activeSess  = !empty($fsSchoolPre['currentSession'])
                        ? (string) $fsSchoolPre['currentSession']
                        : (string) $this->session_year;

        $needsWrite = false;
        if (!empty($activeSess) && preg_match('/^\d{4}-\d{2}$/', $activeSess)) {
            if (empty($sessionsPre) || !in_array($activeSess, $sessionsPre, true)) {
                $needsWrite = true;
            }
        }

        // ── Hot-path: no write needed — refresh userdata + return ──
        if (!$needsWrite) {
            if (!empty($sessionsPre)) {
                $this->session->set_userdata('available_sessions', $sessionsPre);
            }
            if (empty($sessionsPre)) {
                $this->json_success([
                    'sessions'       => [],
                    'active_session' => $activeSess,
                    'synced'         => false,
                    'message'        => 'No sessions configured and no active session detected. Add a session to begin.',
                ]);
                return;
            }
            $archivedSess = (is_array($fsSchoolPre['archivedSessions'] ?? null))
                                ? array_values(array_filter($fsSchoolPre['archivedSessions'], 'is_string'))
                                : [];
            $this->json_success([
                'sessions'          => $sessionsPre,
                'active_session'    => $activeSess,
                'archived_sessions' => $archivedSess,
                'synced'            => true,
                'message'           => 'PHP session synced from Firestore. Header dropdown will update on next render.',
            ]);
            return;
        }

        // ── Write needed: acquire lock + re-read + CAS commit ──
        $lock = $this->_config_lock_acquire('sessions');
        if ($lock === null) {
            log_message('error',
                "ACC_CONCURRENT_REJECTED endpoint=school_config/sync_sessions "
                . "schoolId={$this->school_id} actor={$this->admin_id} "
                . "reason=lock_timeout activeSess={$activeSess}");
            $this->json_error(
                'Another session-management operation is in progress. Please retry in a few seconds.', 409);
            return;
        }
        try {
            // Re-read INSIDE the lock for freshest sessions[] + __updateTime
            $schoolDocId = $this->fs->schoolId();
            $fsSchool    = $this->firebase->firestoreGet('schools', $schoolDocId);
            if (!is_array($fsSchool)) $fsSchool = [];
            $sessions    = (is_array($fsSchool['sessions'] ?? null))
                ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
                : [];
            $activeFresh = !empty($fsSchool['currentSession'])
                            ? (string) $fsSchool['currentSession']
                            : (string) $this->session_year;
            $updateTime  = (string) ($fsSchool['__updateTime'] ?? '');

            // Re-evaluate auto-seed predicate against fresh state.
            // If another writer added the session between pre-read and
            // this fresh-read, didSeed stays false and we fall through
            // to userdata refresh + success response — idempotent.
            $didSeed = false;
            if (!empty($activeFresh) && preg_match('/^\d{4}-\d{2}$/', $activeFresh)) {
                if (empty($sessions)) {
                    $sessions = [$activeFresh];
                    $didSeed  = true;
                } elseif (!in_array($activeFresh, $sessions, true)) {
                    $sessions[] = $activeFresh;
                    sort($sessions);
                    $didSeed = true;
                }
            }

            if ($didSeed) {
                $ops = [[
                    'op'         => 'update',
                    'collection' => 'schools',
                    'docId'      => $schoolDocId,
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
                        "ACC_SESSION_SYNC_COMMIT_FAILED endpoint=school_config/sync_sessions "
                        . "schoolId={$this->school_id} session={$activeFresh} "
                        . "actor={$this->admin_id} err=" . $e->getMessage());
                }
                if (!$committed) {
                    log_message('error',
                        "ACC_CONCURRENT_REJECTED endpoint=school_config/sync_sessions "
                        . "schoolId={$this->school_id} actor={$this->admin_id} "
                        . "reason=cas_failed");
                    $this->json_error(
                        'Another administrator updated school configuration during your request. '
                        . 'Please reload the page and retry.', 409);
                    return;
                }
            }

            // PHP userdata refresh — D1: AFTER successful FS commit
            if (empty($sessions)) {
                $this->json_success([
                    'sessions'       => [],
                    'active_session' => $activeFresh,
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
                'active_session'    => $activeFresh,
                'archived_sessions' => $archivedSess,
                'synced'            => true,
                'message'           => $didSeed
                    ? "Sessions list seeded with active session {$activeFresh}."
                    : 'PHP session synced from Firestore. Header dropdown will update on next render.',
            ]);
        } finally {
            $this->_config_lock_release($lock);
        }
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_add_session', 'Configuration', 'manage');
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
        // SC-Step6 (2026-06-02): widened from ADMIN_ROLES to VIEW_ROLES to
        // preserve the capability that all 14 logged-in roles previously
        // had via Admin::switch_session (now retired). Defense-in-depth:
        // the School Config UI tab visibility remains separately gated;
        // lower roles can't reach the "Set Active" button in that tab.
        // The header dropdown's session switcher (every authenticated
        // admin page) is the primary caller of this widened gate.
        $this->_require_role(self::VIEW_ROLES, 'school_config_set_active_session', 'Configuration', 'view');
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_preview_rollover', 'Configuration', 'view');

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

        // ── Phase 16.7 (SEC-13 visibility) — CAPACITY + TARGET STATE ────
        //
        // The preview previously listed only className/section, so an operator
        // could not see what capacity was about to be carried, nor whether the
        // target session already held that section. Both are now surfaced:
        // the rollover copies `maxStrength` (SEC-13), and an operator must be
        // able to check that number BEFORE confirming rather than discover it
        // afterwards.
        //
        // Target sections are fetched in ONE bulk query and indexed, exactly
        // as rollover_session() does — deliberately not one lookup per section.
        $srcSections = $this->fs->schoolWhere('sections', [['session', '==', $from]]);
        $srcSections = is_array($srcSections) ? $srcSections : [];

        $dstSections = $this->fs->schoolWhere('sections', [['session', '==', $to]]);
        $dstById = [];
        foreach (is_array($dstSections) ? $dstSections : [] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') $dstById[$id] = $row['data'] ?? [];
        }

        $sectionList = [];
        foreach ($srcSections as $row) {
            $d         = $row['data'] ?? [];
            $className = (string) ($d['className'] ?? '');
            $section   = (string) ($d['section']   ?? '');
            if ($className === '' || $section === '') continue;

            $srcCap  = (int) ($d['maxStrength'] ?? $d['max_strength'] ?? 0);
            $targetId = $this->fs->sectionDocId($className, $section, $to);
            $exists   = array_key_exists($targetId, $dstById);
            $dstCap   = $exists
                ? (int) ($dstById[$targetId]['maxStrength'] ?? $dstById[$targetId]['max_strength'] ?? 0)
                : null;

            // Non-destructive states, per Phase 16.7 Part 4. A section that
            // already exists in the target is SKIPPED by rollover_session();
            // it is never overwritten, so a differing capacity is reported for
            // the operator to reconcile, not silently replaced.
            if (!$exists)                    $state = 'NEW';
            elseif ($dstCap === $srcCap)     $state = 'EXISTS_UNCHANGED';
            else                             $state = 'EXISTS_DIFFERENT_CAPACITY';

            $sectionList[] = [
                'className'       => $className,
                'section'         => $section,
                'currentCapacity' => $srcCap,          // 0 = never configured
                'targetCapacity'  => $dstCap,          // null = does not exist yet
                'state'           => $state,
                'willCopy'        => !$exists,
            ];
        }

        // Source students (for promotion preview)
        $srcStudents = $this->fs->schoolWhere('students', [['session', '==', $from]]);
        $srcStudents = is_array($srcStudents) ? $srcStudents : [];
        // LC-11 — the preview must count graduates by the SAME rule the
        // rollover applies. Hardcoded to 12, this told a school whose highest
        // class is Class 10 that nobody would graduate, and the rollover then
        // graduated its entire Class 10. A preview that disagrees with the
        // action it previews is worse than no preview: the operator confirms
        // one thing and gets another.
        $terminalOrder = $this->_terminalClassOrder();
        $byClass = []; $graduating = 0; $active = 0; $graduatingClass = '';
        foreach ($srcStudents as $row) {
            $d = $row['data'] ?? [];
            if (($d['status'] ?? 'Active') !== 'Active') continue;
            $active++;
            $order = $d['classOrder'] ?? null;
            $label = $d['className'] ?? 'Unknown';
            $byClass[$label] = ($byClass[$label] ?? 0) + 1;
            if (is_numeric($order) && (int) $order === $terminalOrder) {
                $graduating++;
                if ($graduatingClass === '') $graduatingClass = (string) $label;
            }
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
            // Named so the preview can say WHICH class completes, rather than
            // leaving the operator to assume it is Class 12.
            'graduating_class'   => $graduatingClass,
            'terminal_class_order' => $terminalOrder,
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
    /**
     * Phase 17 (SEC-17) — canonical class transition for session rollover.
     *
     * Extracted so the PRE-FLIGHT and the WRITE LOOP resolve a student's
     * destination through ONE implementation. If the pre-flight computed a
     * destination even slightly differently it would validate capacity and
     * roll numbers for a section the write never touches — the validation
     * would be worthless while looking correct.
     *
     * Returns:
     *   ['kind' => 'alumni']                          Class 12 → Alumni (no destination section)
     *   ['kind' => 'promote', 'label' => …, 'order' => …]
     *   ['kind' => 'skip',   'reason' => …]           unknown / unmappable classOrder
     *
     * Pure: no reads, no writes, no side effects.
     */
    private function _rolloverNextClass($order, $terminalOrder = 12): array
    {
        // ── LC-11 · COMPLETION HAPPENS AT THE SCHOOL'S LAST CLASS ────────
        //
        // This arm was hardcoded to classOrder 12. A school's classes are
        // CONFIGURED, in `schools.classes`, and a school ending at Class 10 is
        // an ordinary tenant — measured on live data, 2 of 3 tenanted schools
        // terminate below Class 12, one of them with 12 active students in its
        // terminal class.
        //
        // For those schools nobody ever completed schooling: the terminal
        // cohort fell into the +1 arm below and was promoted into a class the
        // school does not have.
        //
        // ── THIS IS NOT AN INVENTED POLICY ───────────────────────────────
        // UDISE+, the Government of India system every one of these schools
        // must already file into, records exactly three exits — "Left with
        // TC", "Dropout", and "Passed Out" — and applies "Passed Out" at the
        // school's OWN terminal class, naming Class 10 alongside Class 12. It
        // does not hardcode a grade. The same separation appears in mature
        // SIS products generally, where graduation is a distinct terminal exit
        // code rather than a kind of withdrawal.
        //
        // So the model ZenX already has (status 'Alumni' + `graduatedFrom`) is
        // correct; only the constant was wrong. `$terminalOrder` is the
        // school's highest CONFIGURED classOrder — the school's own
        // declaration of what it runs, which it must maintain anyway to have
        // sections and capacities.
        //
        // Defaults to 12, so a caller that cannot determine the configuration
        // behaves exactly as before rather than graduating an entire school.
        //
        // Strict equality on purpose. A student stamped ABOVE the terminal
        // class is not silently graduated; they fall through to the promote
        // arm and are refused by the unconfigured-destination guard, which
        // reports the problem instead of hiding it behind a graduation.
        $t = (is_numeric($terminalOrder) && (int) $terminalOrder !== 0) ? (int) $terminalOrder : 12;
        if (is_numeric($order) && (int) $order === $t) {
            return ['kind' => 'alumni'];
        }

        if (is_numeric($order) && (int) $order >= 1 && (int) $order <= 11) {
            $nextNum   = (int) $order + 1;
            $nextLabel = 'Class ' . $nextNum
                       . (in_array($nextNum, [11, 12], true) ? 'th' : $this->_ordinal($nextNum));
            return ['kind' => 'promote', 'label' => $nextLabel, 'order' => $nextNum];
        }

        $foundationalNext  = [-5 => -4, -4 => -3, -3 => -2, -2 => -1, -1 => 1];
        $foundationalLabel = [-4 => 'Class Pre-Nursery', -3 => 'Class Nursery',
                              -2 => 'Class LKG', -1 => 'Class UKG', 1 => 'Class 1st'];
        if (is_numeric($order) && isset($foundationalNext[(int) $order])) {
            $nextOrder = $foundationalNext[(int) $order];
            $nextLabel = $foundationalLabel[$nextOrder] ?? '';
            if ($nextLabel === '') return ['kind' => 'skip', 'reason' => 'no_foundational_label'];
            return ['kind' => 'promote', 'label' => $nextLabel, 'order' => $nextOrder];
        }

        return ['kind' => 'skip', 'reason' => 'unknown_classOrder'];
    }

    /**
     * The school's terminal classOrder — the class a student completes school
     * from — derived from `schools.classes`.
     *
     * Derived, never assumed: this is the school's own declaration of what it
     * runs, and it has to maintain it anyway to have sections and capacities.
     * A school opening a Class 11 next year configures it first, which moves
     * its terminal class before any rollover sees it.
     *
     * Returns 12 when the list is missing, unreadable or yields no orderable
     * class, so an unconfigured tenant behaves exactly as it did before
     * LC-11 rather than graduating its whole roll.
     *
     * Both label conventions are handled by normalising through
     * `Entity_firestore_sync`: `schools.classes` stores "Nursery" and
     * "Class 10th", and only the normaliser maps both onto a classOrder.
     */
    private function _terminalClassOrder(): int
    {
        require_once APPPATH . 'libraries/Entity_firestore_sync.php';
        try {
            $doc = $this->fs->get('schools', $this->fs->schoolId());
        } catch (\Throwable $e) {
            log_message('error', 'terminal class resolve failed: ' . $e->getMessage());
            return 12;
        }
        $raw = (is_array($doc) && is_array($doc['classes'] ?? null)) ? $doc['classes'] : [];
        $max = null;
        foreach ($raw as $c) {
            $label = is_string($c)
                ? $c
                : (string) (is_array($c) ? ($c['className'] ?? $c['name'] ?? $c['label'] ?? '') : '');
            if (trim($label) === '') continue;
            $n = Entity_firestore_sync::normalizeClassSection($label, '');
            $ord = $n['classOrder'] ?? null;
            if ($ord === null || !is_numeric($ord)) continue;
            if ($max === null || (int) $ord > $max) $max = (int) $ord;
        }
        return $max === null ? 12 : $max;
    }

    public function rollover_session()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_rollover_session', 'Configuration', 'manage');

        $from = trim((string) $this->input->post('from_session', TRUE));
        $to   = trim((string) $this->input->post('to_session',   TRUE));
        $copySections    = (int) $this->input->post('copy_sections',    TRUE) !== 0; // default on
        $promoteStudents = (int) $this->input->post('promote_students', TRUE) === 1;
        $setActive       = (int) $this->input->post('set_active',       TRUE) === 1;

        // ── STAGED ROLLOVER — an OPERATOR-REQUESTED bounded range ───────
        //
        // D21 keeps one requested rollover all-or-nothing, and
        // placeStudentGroupsAtomic() therefore REFUSES rather than splits
        // above Firestore's 500-write commit ceiling. The exact model is
        //
        //     writes = promoted pupils + graduations + one marker per
        //              DISTINCT destination section
        //
        // so a school whose whole-school rollover exceeds that had no path
        // at all. class_from/class_to give the operator one: each request
        // is a COMPLETE rollover of the classes they named, atomic and
        // all-or-nothing in itself.
        //
        // This is deliberately NOT chunking. Nothing here splits a request,
        // and nothing continues to the next range on its own — the operator
        // asks for each stage and sees each result. Omit both bounds and
        // the behaviour is exactly what it was: the whole school, one
        // atomic operation.
        //
        // The bounds are SOURCE classOrder, inclusive, matching the values
        // already stored on the student document (foundational classes are
        // negative, Class 1-12 are 1..12), so no new vocabulary is
        // introduced and _rolloverNextClass() stays the only place that
        // knows how a class advances.
        $rawFrom = $this->input->post('class_from', TRUE);
        $rawTo   = $this->input->post('class_to',   TRUE);
        $stageFrom = ($rawFrom === null || $rawFrom === '') ? null : (int) $rawFrom;
        $stageTo   = ($rawTo   === null || $rawTo   === '') ? null : (int) $rawTo;
        if ($stageFrom !== null && $stageTo !== null && $stageFrom > $stageTo) {
            return $this->json_error('The class range is inverted: class_from must not be above class_to.');
        }
        $staged = ($stageFrom !== null || $stageTo !== null);

        // ── Phase 16.9 (D20) — STUDENT PROMOTION REQUIRES SIS:manage ────
        //
        // THE BYPASS THIS CLOSES — this was a privilege-escalation path, not
        // merely an inconsistency:
        //
        //   Sis::execute_promotion()      → _require_role(…, 'SIS', 'manage')
        //   Classes::transfer_students()  → _require_role(…, 'SIS', 'manage')   (Phase 16.1, D16)
        //   THIS endpoint with promote_students=1 → 'Configuration', 'manage'
        //
        // The canonical promotion endpoint denies a Configuration-only
        // administrator, but this one performed the SAME student movement —
        // changing className, section and session on every student, and
        // graduating Class 12 to Alumni — under a configuration permission.
        // An operator blocked at the front door could walk in through the
        // rollover screen.
        //
        // Operator decision 2026-08-15 (Option A): SESSION STRUCTURE stays
        // under Configuration:manage — creating next year's classes, sections
        // and capacities is genuinely configuration. MOVING STUDENTS requires
        // SIS:manage, matching every other student-movement path.
        //
        // WHY AN EMPTY $allowed ARRAY: _require_role() falls back to a legacy
        // ROLE-NAME allow-list for users who do not hold the module at all.
        // Passing self::ADMIN_ROLES here would let a Configuration-only admin
        // pass on their role NAME and defeat the entire check. `[]` makes the
        // graded capability the sole authority — the established pattern in
        // Entitlements.php. Super Admin / School Super Admin still bypass by
        // design, unchanged.
        //
        // PLACED BEFORE ANY WRITE — and before the target session is added to
        // sessions[] — so a denied caller cannot leave a half-created session
        // behind. json_error() exits, so this genuinely terminates.
        if ($promoteStudents) {
            $this->_require_role([], 'rollover_promote_students', 'SIS', 'manage');
        }

        // ── Phase 16.8 (SEC-16) — OPERATOR-EDITED TARGET CAPACITY ───────
        //
        // The preview (Phase 16.7) shows the capacity each section will carry
        // forward; this lets the administrator CHANGE it before confirming,
        // e.g. 5-C moving from 50 to 45 next year. Without it the only route
        // was to roll over and then re-edit each section afterwards, which
        // means the wrong capacity is briefly live and enforced.
        //
        // Shape: section_capacities[<className>|<section>] = <int>
        // keyed on the SAME canonical class/section strings the preview
        // returns, so the operator can only address sections the preview
        // actually offered.
        //
        // ⚠ APPLIES TO NEWLY CREATED SECTIONS ONLY. A section that already
        // exists in the target session is SKIPPED by the copy loop below and
        // is NOT re-written here — that non-destructive rule was established
        // in Phase 16.7 and editing an existing section remains the job of
        // Section Settings. Rollover must never silently overwrite a capacity
        // the operator already set for next year.
        $capacityOverrides = [];
        $rawCaps = $this->input->post('section_capacities', TRUE);
        if (is_array($rawCaps)) {
            foreach ($rawCaps as $k => $v) {
                $key = trim((string) $k);
                if ($key === '') continue;
                // Reject anything that is not a non-negative whole number.
                // 0 is legitimate and means "leave unconfigured" — the
                // canonical resolver then supplies the default (D17). No
                // upper bound is imposed: no source establishes a maximum
                // section size, and inventing one would be a business rule.
                if (!is_scalar($v)) continue;
                $s = trim((string) $v);
                if ($s === '' || !preg_match('/^\d+$/', $s)) {
                    return $this->json_error(
                        "Capacity for '{$key}' must be a whole number (0 means 'not configured')."
                    );
                }
                $capacityOverrides[mb_strtolower($key)] = (int) $s;
            }
        }

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

        // ── SC-Step7 (Session Convergence — 2026-06-02): brief lock + CAS ──
        // Write the `running` intent + target-session-add in ONE batched
        // commit under ONE brief `sessions` lock. Stage B (long-running
        // students/sections work BELOW this block) runs UNLOCKED per D1=B.
        // Re-read INSIDE lock for freshest sessions[] + __updateTime so
        // concurrent add_session writes are not silently clobbered.
        $now = date('c');
        $rolloverStartLock = $this->_config_lock_acquire('sessions');
        if ($rolloverStartLock === null) {
            log_message('error',
                "ACC_CONCURRENT_REJECTED endpoint=school_config/rollover_session "
                . "stage=intent_start schoolId={$this->school_id} actor={$this->admin_id} "
                . "reason=lock_timeout key={$intentKey}");
            return $this->json_error(
                'Another session-management operation is in progress. Please retry in a few seconds.', 409);
        }
        try {
            // Re-read INSIDE lock for freshest sessions[] + __updateTime
            $fsSchoolFresh = $this->firebase->firestoreGet('schools', $schoolId);
            if (!is_array($fsSchoolFresh)) $fsSchoolFresh = [];
            $sessions = (is_array($fsSchoolFresh['sessions'] ?? null))
                ? array_values(array_filter($fsSchoolFresh['sessions'], 'is_string'))
                : $sessions; // fall back to pre-lock value if FS read returned nothing
            $startUpdateTime = (string) ($fsSchoolFresh['__updateTime'] ?? '');

            // Build batched payload: intent-running + (sessions[] add if needed)
            $startData = [
                'rolloverIntents.' . $intentKey => [
                    'status'     => 'running',
                    'from'       => $from,
                    'to'         => $to,
                    'started_at' => $now,
                    'started_by' => $this->admin_id,
                    'attempt'    => (int) (($prior['attempt'] ?? 0)) + 1,
                ],
                'updatedAt' => $now,
            ];
            if (!in_array($to, $sessions, true)) {
                $sessions[] = $to;
                sort($sessions);
                $startData['sessions'] = $sessions;
            }

            $ops = [[
                'op'         => 'update',
                'collection' => 'schools',
                'docId'      => $schoolId,
                'merge'      => true,
                'data'       => $startData,
            ]];
            if ($startUpdateTime !== '') {
                $ops[0]['precondition'] = ['updateTime' => $startUpdateTime];
            }
            $committed = false;
            try {
                $committed = (bool) $this->firebase->firestoreCommitBatch($ops);
            } catch (\Throwable $e) {
                log_message('error',
                    "ACC_ROLLOVER_INTENT_WRITE_FAILED schoolId={$this->school_id} "
                    . "key={$intentKey} err=" . $e->getMessage());
            }
            if (!$committed) {
                log_message('error',
                    "ACC_CONCURRENT_REJECTED endpoint=school_config/rollover_session "
                    . "stage=intent_start schoolId={$this->school_id} actor={$this->admin_id} "
                    . "reason=cas_failed key={$intentKey}");
                return $this->json_error('Could not record rollover intent. Retry.', 500);
            }
        } finally {
            $this->_config_lock_release($rolloverStartLock);
        }

        log_message('error',
            "ACC_ROLLOVER_START schoolId={$this->school_id} from={$from} to={$to} "
            . "actor={$this->admin_id} resuming=" . ($resuming ? '1' : '0'));

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

                // Phase 16.7 (SEC-2 family) — canonical doc-id. The hand-built
                // form happened to match, because className/section arrive
                // already normalised from the source document, but every other
                // section writer was moved onto the helper in Phases 16.4–16.5
                // precisely so no site can drift. This is the last one.
                $newDocId = $this->fs->sectionDocId($className, $section, $to);
                if (isset($existingIds[$newDocId])) {
                    $summary['sections_skipped']++;
                    continue;
                }

                // ── Phase 16.7 (SEC-13) — CARRY THE CONFIGURED CAPACITY ─────
                //
                // THE DEFECT THIS CLOSES: this copy rebuilt the section
                // document field by field and simply OMITTED `maxStrength`.
                // Every rolled-over section was therefore created with NO
                // configured capacity, and the canonical resolver
                // (MY_Controller::_sectionCapacity) fell back to its default
                // of 40. A school that had carefully set
                //     5-A = 30 · 5-B = 40 · 5-C = 50
                // silently became 40 · 40 · 40 in the new session — the exact
                // harm SEC-3 was raised to prevent. The rollover feature
                // existed; it was dropping the one field that mattered.
                //
                // Capacity is carried as a STARTING VALUE, not a permanent
                // rule: Section Settings remains editable in the target
                // session, and external sources do NOT establish automatic
                // capacity carry-forward as standard practice (Phase 16.6
                // research) — so this is a ZenX product choice, deliberately
                // pre-filling what the school already told us rather than
                // resetting it to a number they never chose.
                //
                // 0 / missing / invalid is written as 0, NOT as 40: the
                // canonical resolver owns the default, and baking 40 into the
                // stored document would turn "never configured" into
                // "explicitly configured as 40" and hide it from the operator.
                $srcCapacity = (int) ($d['maxStrength'] ?? $d['max_strength'] ?? 0);
                if ($srcCapacity < 0) $srcCapacity = 0;

                // Phase 16.8 (SEC-16) — the operator's edited value wins over
                // the carried-forward source capacity, for NEW sections only.
                $capKey = mb_strtolower("{$className}|{$section}");
                if (array_key_exists($capKey, $capacityOverrides)) {
                    $srcCapacity = $capacityOverrides[$capKey];
                    $summary['capacities_edited'] = ($summary['capacities_edited'] ?? 0) + 1;
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
                    'maxStrength'  => $srcCapacity,
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

            // Bound the population HERE and nowhere else. The pre-flight and
            // the write stage both iterate $srcStudents, so filtering once at
            // the source makes it impossible for them to disagree about who is
            // in the stage — the same reason LC-10 put the transition table in
            // one place. A pupil outside the range is not skipped, not
            // validated and not counted: they are simply not part of this
            // operation, and a later stage will move them.
            if ($staged) {
                $inStage = [];
                foreach ($srcStudents as $__row) {
                    if (!is_array($__row)) continue;
                    $__d = is_array($__row['data'] ?? null) ? $__row['data'] : [];
                    $__o = $__d['classOrder'] ?? null;
                    // A pupil with no usable classOrder cannot be placed in a
                    // range. Leaving them out of every stage would silently
                    // strand them, so they stay in the UNBOUNDED run only —
                    // where rollover already reports them as an explicit skip.
                    if (!is_numeric($__o)) continue;
                    $__o = (int) $__o;
                    if ($stageFrom !== null && $__o < $stageFrom) continue;
                    if ($stageTo   !== null && $__o > $stageTo)   continue;
                    $inStage[] = $__row;
                }
                $summary['stage_class_from'] = $stageFrom;
                $summary['stage_class_to']   = $stageTo;
                $summary['stage_population'] = count($inStage);
                log_message('error',
                    "ACC_ROLLOVER_STAGE schoolId={$this->school_id} from={$from} to={$to} "
                    . 'class_from=' . var_export($stageFrom, true)
                    . ' class_to=' . var_export($stageTo, true)
                    . ' population=' . count($inStage) . ' of ' . count($srcStudents));
                $srcStudents = $inStage;
            }

            // LC-11 — resolved ONCE, here, and handed to every call of
            // _rolloverNextClass() in both the pre-flight and the write loop.
            // Resolving it separately in each place is precisely how the two
            // would come to disagree about who graduates, which is the failure
            // LC-10 exists to make impossible.
            $terminalOrder = $this->_terminalClassOrder();
            log_message('info', "ACC_ROLLOVER_TERMINAL_CLASS schoolId={$this->school_id} "
                . "terminalClassOrder={$terminalOrder}");

            // ══ Phase 17 (SEC-17) — ALL-OR-NOTHING PROMOTION PRE-FLIGHT ══
            //
            // THE DEFECT THIS CLOSES: this loop moved students with NEITHER
            // the capacity guard NOR the roll-collision guard that
            // Sis::execute_promotion() applies. Rollover could therefore
            // overfill a destination section, or create duplicate Roll
            // Numbers where the destination already held students —
            // producing exactly the lifecycle state the normal promotion
            // screen refuses.
            //
            // D21 (operator decision 2026-08-15): ALL-OR-NOTHING. If ANY
            // student in the set would violate capacity or roll uniqueness,
            // the ENTIRE promotion is refused and NO student is moved. A
            // per-student skip would leave a school half-promoted — some
            // children in the new session, some stranded in the old — which
            // is materially worse to unpick than a clean refusal.
            //
            // Deliberately NOT a check inside the write loop: by the time the
            // loop reaches a violating student, earlier students are already
            // written. Validation must complete BEFORE the first write.
            //
            // ── READ COST ────────────────────────────────────────────────
            // O(destination sections), NOT O(students):
            //   • occupancy + rolls come from the SINGLE request-memoised
            //     student snapshot (_cachedStudentDocsForRollCheck), already
            //     warm — 1 read total, shared with every other guard;
            //   • capacity is one `sections` read per DISTINCT destination,
            //     memoised by _sectionCapacity() per session|class|section.
            // Destination rosters are indexed into in-memory maps ONCE, so
            // the per-student checks are O(1) lookups rather than repeated
            // scans (calling _rollNoConflict() per student would have been
            // O(students²) CPU over the snapshot).
            //
            // ── STALENESS ────────────────────────────────────────────────
            // The memoised snapshot is taken before any write. Because we
            // abort before writing, it cannot go stale mid-validation. The
            // running projection below accounts for students planned earlier
            // in THIS batch, which the snapshot cannot see.
            $plan            = [];   // [studentId => ['label','order','section','roll','name']]
            $incomingPerDest = [];   // destKey => count planned this run
            $capacityIssues  = [];
            $rollIssues      = [];
            $destRollOwner   = [];   // destKey => [lcRoll => existing student label]
            $batchRollOwner  = [];   // destKey => [lcRoll => incoming student label]
            $destSeen        = [];   // destKey => ['label'=>…, 'section'=>…]

            foreach ($srcStudents as $row) {
                if (!is_array($row)) continue;
                $sd  = is_array($row['data'] ?? null) ? $row['data'] : [];
                $sid = (string) ($row['id'] ?? ($sd['id'] ?? ''));
                if ($sid === '') continue;
                if (($sd['status'] ?? 'Active') !== 'Active') continue;
                // Already promoted by a prior partial attempt — the write loop
                // skips these, so the pre-flight must too or it would
                // double-count them against capacity and refuse a legitimate
                // resume.
                if (((string) ($sd['session'] ?? '')) === $to) continue;

                $next = $this->_rolloverNextClass($sd['classOrder'] ?? null, $terminalOrder);
                // Alumni has no destination section; unmappable rows are
                // skipped by the write loop. Neither consumes a seat.
                if ($next['kind'] !== 'promote') continue;

                $section = trim((string) ($sd['section'] ?? ''));
                $destKey = mb_strtolower("{$next['label']}|{$section}");
                $destSeen[$destKey] = ['label' => $next['label'], 'section' => $section];

                $plan[$sid] = [
                    'label'   => $next['label'],
                    'order'   => $next['order'],
                    'section' => $section,
                    'roll'    => trim((string) ($sd['rollNo'] ?? $sd['Roll No'] ?? '')),
                    'name'    => (string) ($sd['name'] ?? $sd['Name'] ?? $sid),
                ];
                $incomingPerDest[$destKey] = ($incomingPerDest[$destKey] ?? 0) + 1;
            }

            // Index each destination's EXISTING roster once — repeaters,
            // students already admitted into the target session, and anyone
            // promoted by an earlier partial run all legitimately occupy
            // seats and hold rolls.
            foreach ($destSeen as $destKey => $dest) {
                if ($dest['section'] === '') continue;
                foreach ($this->_filterSectionRoster(
                    $this->_cachedStudentDocsForRollCheck(),
                    $dest['label'], $dest['section'], $to
                ) as $r) {
                    $d = is_array($r) ? ($r['data'] ?? $r) : [];
                    if (!is_array($d)) continue;
                    $roll = trim((string) ($d['rollNo'] ?? $d['Roll No'] ?? ''));
                    if ($roll === '') continue;
                    $destRollOwner[$destKey][mb_strtolower($roll)] =
                        (string) ($d['name'] ?? $d['Name'] ?? ($d['studentId'] ?? 'an existing student'));
                }
            }

            // Capacity — canonical semantics (Phase 16.5 D17): configured
            // positive value wins, unset/zero/invalid means 40, withdrawn
            // students free their seat, TARGET session only.
            // ── ORDERING: VERSION BEFORE OCCUPANCY ──────────────────────────
            // Each destination's version must be captured BEFORE the occupancy
            // that decides it. Captured after, a placement landing in between
            // leaves this decision holding a version NEWER than the roster it
            // was made against, so the commit passes honestly on a stale
            // decision. These versions are the preconditions the atomic commit
            // below rides on.
            $destVersions = [];
            foreach ($destSeen as $destKey => $dest) {
                if ($dest['section'] === '') continue;
                $destVersions[$destKey] = $this->_sectionDecisionVersion(
                    $dest['label'], $dest['section'], $to
                );
            }

            foreach ($destSeen as $destKey => $dest) {
                if ($dest['section'] === '') continue;
                $incoming = (int) ($incomingPerDest[$destKey] ?? 0);
                if ($incoming < 1) continue;
                // FRESH, not the memoised roster: the occupancy a CAS protects
                // must be read AFTER the version above. Served from the shared
                // snapshot the count can predate the version, and the commit
                // then passes honestly on a count that was already stale when
                // the version was taken.
                $over = $this->_sectionCapacityExceeded(
                    $dest['label'], $dest['section'], $to, $incoming, '', /* fresh */ true
                );
                if ($over !== null) {
                    $cls = trim(preg_replace('/^Class\s+/i', '', $dest['label']));
                    $sec = trim(preg_replace('/^Section\s+/i', '', $dest['section']));
                    $capacityIssues[] = sprintf(
                        '%s-%s: %d student(s) would occupy %d seat(s) (already %d active)',
                        $cls, $sec, $incoming, (int) $over['capacity'], (int) $over['current']
                    );
                }
            }

            // Roll numbers — School+Session+Class+Section, case-insensitive,
            // alphanumeric-safe (string comparison, never a numeric cast).
            // Nothing is renumbered and no roll is cleared: the operator
            // resolves the conflict and retries.
            foreach ($plan as $sid => $p) {
                if ($p['roll'] === '' || $p['section'] === '') continue;
                $destKey = mb_strtolower("{$p['label']}|{$p['section']}");
                $lc      = mb_strtolower($p['roll']);
                $cls     = trim(preg_replace('/^Class\s+/i', '', $p['label']));
                $sec     = trim(preg_replace('/^Section\s+/i', '', $p['section']));

                if (isset($destRollOwner[$destKey][$lc])) {
                    $rollIssues[] = "{$cls}-{$sec} Roll {$p['roll']}: {$p['name']} conflicts with "
                                  . "{$destRollOwner[$destKey][$lc]} already in that section";
                    continue;
                }
                if (isset($batchRollOwner[$destKey][$lc])) {
                    $rollIssues[] = "{$cls}-{$sec} Roll {$p['roll']}: {$p['name']} and "
                                  . "{$batchRollOwner[$destKey][$lc]} would both arrive with the same roll";
                    continue;
                }
                $batchRollOwner[$destKey][$lc] = $p['name'];
            }

            // ── LC-7 · THE DESTINATION CLASS MUST ACTUALLY EXIST ─────────
            //
            // THE DEFECT THIS CLOSES. `_rolloverNextClass()` sends classOrder
            // 12 to Alumni and EVERY other numeric class to classOrder + 1.
            // That arm is hardcoded to 12, but a school's classes are
            // CONFIGURED, in `schools.classes` — a school ending at Class 10
            // is an ordinary tenant, not an edge case.
            //
            // For such a school the terminal cohort never graduates. It falls
            // into the +1 arm and is promoted into the class ABOVE the
            // school's last one — a class the school does not configure. The
            // two guards above could not see it: capacity reads a `sections`
            // record that does not exist for an unconfigured class, so the
            // default (40) applies and nothing objects, and an empty
            // destination has no rolls to collide with.
            //
            // Measured, not assumed: `scripts/graduation_policy_readonly.js`
            // against live data found 2 of 3 tenanted schools terminate below
            // Class 12, one of them with 12 active students sitting in its
            // terminal class right now.
            //
            // ── WHY THIS IS A GUARD AND NOT A POLICY ─────────────────────
            // It does NOT decide that the terminal cohort should graduate.
            // Whether a school ending at Class 10 wants those students
            // graduated, or is opening a Class 11 next year and wants them
            // promoted, is the school's decision and cannot be derived from
            // anything ZenX stores. What IS derivable — and what this asserts
            // — is that moving a student into a class the school has not
            // configured is never the intended outcome of either choice.
            //
            // All-or-nothing, matching D21: a per-student skip would strand
            // the terminal cohort in the old session while everyone else
            // moved, which is materially worse to unpick than a refusal that
            // says exactly what to do.
            //
            // FAILS OPEN on an unreadable or unconfigured class list, mirroring
            // this file's established fail-open-on-absence idiom: a school that
            // has never configured `classes` must not be newly unable to roll
            // over, and an infrastructure blip must not look like a policy.
            $unconfiguredIssues = [];
            // Explicit, matching Academic.php's idiom: this library is not
            // autoloaded, and CI's loader does not make its statics available.
            // Without this the call below is a fatal Error, and it would only
            // surface the first time an operator ran a rollover.
            require_once APPPATH . 'libraries/Entity_firestore_sync.php';
            $cfgSchoolDoc  = $this->fs->get('schools', $this->fs->schoolId());
            $cfgClassesRaw = (is_array($cfgSchoolDoc['classes'] ?? null)) ? $cfgSchoolDoc['classes'] : [];
            if (!empty($cfgClassesRaw)) {
                // Both sides go through the canonical normaliser before being
                // compared. They are NOT written the same way: `classes` holds
                // "Nursery" and "Class 10th", while _rolloverNextClass() emits
                // "Class Nursery" and "Class 11th". A raw string comparison
                // would report every foundational class as unconfigured.
                $configured = [];
                foreach ($cfgClassesRaw as $c) {
                    $label = is_string($c)
                        ? $c
                        : (string) (is_array($c) ? ($c['className'] ?? $c['name'] ?? $c['label'] ?? '') : '');
                    if (trim($label) === '') continue;
                    $norm = Entity_firestore_sync::normalizeClassSection($label, '');
                    if (($norm['className'] ?? '') !== '') {
                        $configured[mb_strtolower($norm['className'])] = true;
                    }
                }

                if (!empty($configured)) {
                    $unconfiguredCount = [];
                    foreach ($plan as $sid => $p) {
                        $norm = Entity_firestore_sync::normalizeClassSection($p['label'], '');
                        $key  = mb_strtolower((string) ($norm['className'] ?? $p['label']));
                        if (isset($configured[$key])) continue;
                        $unconfiguredCount[$p['label']] = ($unconfiguredCount[$p['label']] ?? 0) + 1;
                    }
                    foreach ($unconfiguredCount as $label => $n) {
                        $unconfiguredIssues[] = sprintf(
                            '%d student(s) would move into %s, which this school does not '
                            . 'configure as a class', $n, $label
                        );
                    }
                }
            }

            if (!empty($capacityIssues) || !empty($rollIssues) || !empty($unconfiguredIssues)) {
                $parts = ['Promotion blocked — no students were promoted.'];
                if (!empty($unconfiguredIssues)) {
                    $parts[] = 'Unconfigured destination: '
                             . implode('; ', array_slice($unconfiguredIssues, 0, 10))
                             . (count($unconfiguredIssues) > 10
                                 ? ' …and ' . (count($unconfiguredIssues) - 10) . ' more' : '')
                             . '. Add the class under Classes & Sections if these students '
                             . 'continue, or move them out of their current class first if '
                             . 'they are leaving the school.';
                }
                if (!empty($capacityIssues)) {
                    $parts[] = 'Capacity: ' . implode('; ', array_slice($capacityIssues, 0, 10))
                             . (count($capacityIssues) > 10 ? ' …and ' . (count($capacityIssues) - 10) . ' more' : '');
                }
                if (!empty($rollIssues)) {
                    $parts[] = 'Roll Number conflicts: ' . implode('; ', array_slice($rollIssues, 0, 10))
                             . (count($rollIssues) > 10 ? ' …and ' . (count($rollIssues) - 10) . ' more' : '');
                }
                log_message('error', sprintf(
                    'ACC_ROLLOVER_PROMOTION_BLOCKED schoolId=%s from=%s to=%s capacity_issues=%d '
                    . 'roll_issues=%d unconfigured_issues=%d planned=%d',
                    $this->school_id, $from, $to, count($capacityIssues), count($rollIssues),
                    count($unconfiguredIssues), count($plan)
                ));
                // Audit the REFUSAL explicitly so history can never imply a
                // promotion happened. studentsPromoted is 0 by construction —
                // no write has occurred.
                log_audit('Configuration', 'rollover_promotion_blocked', $to,
                    "Promotion refused for {$from} → {$to}: validation failed",
                    ['entityType' => 'session_rollover', 'meta' => [
                        'fromSession'      => $from,
                        'toSession'        => $to,
                        'outcome'          => 'blocked',
                        'studentsPromoted' => 0,
                        'plannedStudents'  => count($plan),
                        'capacityIssues'   => count($capacityIssues),
                        'rollIssues'       => count($rollIssues),
                        // LC-7 — recorded separately so a refusal caused by an
                        // unconfigured destination is never read back as a
                        // capacity problem, which would send the operator to
                        // the wrong screen.
                        'unconfiguredIssues' => count($unconfiguredIssues),
                    ]]);
                return $this->json_error(implode(' | ', $parts), 409);
            }
            // ══ END PRE-FLIGHT — every write below is now known safe ══════

            // ══ WRITE STAGE — ONE ATOMIC COMMIT (D21) ═══════════════════════
            //
            // D21 says the rollover is all-or-nothing: if any pupil would fail a
            // required validation, NO pupil moves. The pre-flight above proves
            // that up front — but a pre-flight alone cannot deliver it. Between
            // the check and the writes an admission, a transfer or a promotion
            // can take the last seat in a destination, and a per-pupil write
            // loop would then move some children and strand others. That is a
            // half-promoted school: exactly the state D21 exists to prevent,
            // and exactly what a read-then-write check produced elsewhere in
            // this programme (three concurrent transfers put five pupils into a
            // three-seat section).
            //
            // So every write goes in ONE Firestore commit: every promoted
            // pupil, every graduation, and one CAS marker per destination
            // section carrying the version captured BEFORE the occupancy that
            // decided it. Firestore applies the per-write preconditions
            // independently and rolls the whole commit back if any is stale, so
            // a seat taken underneath us refuses the entire rollover with
            // nothing written — which is what D21 asks for.
            //
            // Graduations ride in the same commit. A graduation releases a seat
            // and consumes none, so it needs no marker — but D21 covers it too:
            // a blocked rollover must not quietly graduate Class 12 either.
            $looseWrites  = [];
            $byDest       = [];
            $graduatedN   = 0;
            $promotedN    = 0;
            $schoolPrefix = $schoolId . '_';

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
                // (a prior partial attempt promoted them). They still count
                // toward destination occupancy — the pre-flight indexed them —
                // so a resumed run cannot double-fill a section.
                if (((string) ($stuData['session'] ?? '')) === $to) {
                    $summary['students_skipped']++;
                    log_message('error',
                        "ACC_ROLLOVER_SKIP schoolId={$this->school_id} reason=already_promoted "
                        . "id={$stuId} target={$to}");
                    continue;
                }

                // THE DOC ID IS NOT THE STUDENT ID. schoolWhere() returns the
                // full document id ({school}_{student}); the seat authority
                // takes a BARE student id and applies the school prefix itself.
                // Passing the doc id through wrote SCH_X_SCH_X_STU1203 — a
                // SECOND document, so the pupil existed in both sessions at once.
                $stuKey = trim((string) ($stuData['studentId'] ?? ''));
                if ($stuKey === '') {
                    $stuKey = (strpos($stuId, $schoolPrefix) === 0)
                        ? substr($stuId, strlen($schoolPrefix))
                        : $stuId;
                }

                // LC-10 — resolved through the one transition table, the same
                // call the pre-flight made, so validation and write can never
                // disagree about where a pupil is going.
                $next = $this->_rolloverNextClass($stuData['classOrder'] ?? null, $terminalOrder);

                if ($next['kind'] === 'alumni') {
                    $looseWrites[] = ['studentId' => $stuKey, 'data' => [
                        'status'        => 'Alumni',
                        'graduatedFrom' => $from,
                        'updatedAt'     => date('c'),
                    ]];
                    $graduatedN++;
                    continue;
                }

                if ($next['kind'] === 'promote') {
                    // The pre-flight validated $plan. A promote with no plan
                    // entry means the two disagreed — refuse rather than write
                    // an unvalidated placement.
                    if (!isset($plan[$stuId])) {
                        $summary['students_skipped']++;
                        $summary['failed_rows'][] = [
                            'id' => $stuId, 'kind' => 'promote',
                            'reason' => 'not present in the validated plan',
                        ];
                        log_message('error',
                            "ACC_ROLLOVER_SKIP schoolId={$this->school_id} reason=not_in_plan id={$stuId}");
                        continue;
                    }
                    $p       = $plan[$stuId];
                    $destKey = mb_strtolower("{$p['label']}|{$p['section']}");
                    $byDest[$destKey][] = ['studentId' => $stuKey, 'data' => [
                        'session'      => $to,
                        'className'    => $p['label'],
                        'section'      => $p['section'],
                        'classOrder'   => $p['order'],
                        'sectionKey'   => "{$p['label']}/{$p['section']}",
                        'status'       => 'Active',
                        'promotedFrom' => $from,
                        'updatedAt'    => date('c'),
                    ]];
                    $promotedN++;
                    continue;
                }

                $summary['students_skipped']++;
                log_message('error',
                    "ACC_ROLLOVER_SKIP schoolId={$this->school_id} reason=" . ($next['reason'] ?? 'unknown')
                    . " id={$stuId} classOrder=" . var_export($stuData['classOrder'] ?? null, true));
            }

            if ($promotedN > 0 || $graduatedN > 0) {
                // CI3 has no autoloader for libraries — both must be loaded
                // before the first property access or it is a hard fatal.
                $this->load->library('entity_firestore_sync', null, 'entity_sync');
                $this->load->library('section_seat_guard', null, 'seatGuard');
                $this->seatGuard->init($this->fs, $this->firebase,
                    (string) $this->school_id, (string) ($this->admin_id ?? 'system'));

                $placed = false;
                $lastRes = ['status' => '', 'message' => ''];
                for ($attempt = 1; $attempt <= self::ROLLOVER_SEAT_ATTEMPTS; $attempt++) {
                    if ($attempt > 1) {
                        // A CONFLICT means a competing placement committed first,
                        // so the capacity decision is stale, not wrong. Re-decide
                        // against the destinations as they are NOW — version
                        // first, then a FRESH count — and try again. If a seat is
                        // genuinely gone, D21 refuses the whole rollover, which is
                        // the answer the operator should get.
                        $stillOver = [];
                        foreach ($destSeen as $dk => $dest) {
                            if ($dest['section'] === '') continue;
                            $destVersions[$dk] = $this->_sectionDecisionVersion(
                                $dest['label'], $dest['section'], $to
                            );
                            $inc = (int) ($incomingPerDest[$dk] ?? 0);
                            if ($inc < 1) continue;
                            $ov = $this->_sectionCapacityExceeded(
                                $dest['label'], $dest['section'], $to, $inc, '', /* fresh */ true
                            );
                            if ($ov !== null) {
                                $cls = trim(preg_replace('/^Class\s+/i', '', $dest['label']));
                                $sec = trim(preg_replace('/^Section\s+/i', '', $dest['section']));
                                $stillOver[] = sprintf(
                                    '%s-%s: %d student(s) would occupy %d seat(s) (already %d active)',
                                    $cls, $sec, $inc, (int) $ov['capacity'], (int) $ov['current']
                                );
                            }
                        }
                        if (!empty($stillOver)) {
                            // ROLL-AUDIT-1 (2026-09-19) — the structured payload belongs in
                            // $context (5th), not $description (4th, typed string). Passing the
                            // array raised an uncaught TypeError, so the json_error() below was
                            // never reached and this audit row was never written: a blocked
                            // promotion crashed instead of refusing, and left no trail.
                            log_audit('Configuration', 'rollover_promotion_blocked', $to,
                                sprintf(
                                    'Promotion %s to %s blocked at the post-conflict capacity recheck: %d section(s) would still be over capacity. No students were promoted.',
                                    $from, $to, count($stillOver)
                                ),
                                [
                                    'from' => $from, 'to' => $to, 'outcome' => 'blocked',
                                    'stage' => 'post_conflict_recheck',
                                    'capacityIssues' => count($stillOver),
                                ]
                            );
                            return $this->json_error(
                                'Promotion blocked — no students were promoted. Capacity: '
                                . implode('; ', array_slice($stillOver, 0, 10)), 409);
                        }
                    }

                    $destinations = [];
                    foreach ($byDest as $dk => $students) {
                        $destinations[] = [
                            'classNode'       => $destSeen[$dk]['label'],
                            'sectionNode'     => $destSeen[$dk]['section'],
                            'session'         => $to,
                            'decisionVersion' => (string) ($destVersions[$dk] ?? ''),
                            'students'        => $students,
                        ];
                    }

                    $lastRes = $this->seatGuard->placeStudentGroupsAtomic(
                        $this->entity_sync, $destinations, $looseWrites
                    );
                    if (($lastRes['status'] ?? '') === Section_seat_guard::PLACED) { $placed = true; break; }
                    // Only CONFLICT is retryable. FAILURE means the outcome is
                    // unknown, and re-committing an unknown outcome is how
                    // duplicate placements are made.
                    if (($lastRes['status'] ?? '') !== Section_seat_guard::CONFLICT) break;
                }

                if (!$placed) {
                    $summary['errors'][] = (string) ($lastRes['message'] ?? 'The rollover could not be committed.');
                    log_message('error',
                        "ACC_ROLLOVER_FAIL schoolId={$this->school_id} stage=atomic_commit "
                        . "status=" . ($lastRes['status'] ?? '?') . " dests=" . count($byDest)
                        . " students=" . ($promotedN + $graduatedN));
                    // ROLL-AUDIT-1 (2026-09-19) — same defect as the capacity-recheck
                    // branch above. This one is the more serious of the two: it is the
                    // seat-guard refusal path, so the operator saw a fatal instead of
                    // "Promotion blocked — no students were promoted" and could not tell
                    // whether anything had been applied.
                    log_audit('Configuration', 'rollover_promotion_blocked', $to,
                        sprintf(
                            'Promotion %s to %s blocked at the atomic commit (seat guard status: %s). No students were promoted.',
                            $from, $to, (string) ($lastRes['status'] ?? '?')
                        ),
                        [
                            'from' => $from, 'to' => $to, 'outcome' => 'blocked',
                            'stage' => 'atomic_commit', 'status' => $lastRes['status'] ?? '?',
                        ]
                    );
                    return $this->json_error(
                        'Promotion blocked — no students were promoted. '
                        . ($lastRes['message'] ?? ''), 409);
                }

                $summary['students_promoted']  += $promotedN;
                $summary['students_graduated'] += $graduatedN;
                log_message('error',
                    "ACC_ROLLOVER_COMMIT schoolId={$this->school_id} dests=" . count($byDest)
                    . " promoted={$promotedN} graduated={$graduatedN} atomic=1");
            }
        }

        // ── Optionally set target as active ────────────────────────────
        // SC-Step7 (Session Convergence — 2026-06-02): brief lock + CAS
        // around the optional setActive currentSession write. Stage B
        // (above) ran unlocked; here we re-acquire briefly. PHP userdata
        // refreshed AFTER FS commit confirms (D3). On lock-busy or CAS
        // failure, log + continue (rollover itself completed; the
        // activation is a secondary effect — caller can manually
        // activate via header dropdown if needed).
        if ($setActive) {
            $setActiveLock = $this->_config_lock_acquire('sessions');
            if ($setActiveLock === null) {
                log_message('error',
                    "ACC_CONCURRENT_REJECTED endpoint=school_config/rollover_session "
                    . "stage=set_active schoolId={$this->school_id} actor={$this->admin_id} "
                    . "reason=lock_timeout target={$to}");
            } else {
                try {
                    $fsSchoolActive = $this->firebase->firestoreGet('schools', $schoolId);
                    $activeUpdateTime = is_array($fsSchoolActive)
                        ? (string) ($fsSchoolActive['__updateTime'] ?? '')
                        : '';
                    $ops = [[
                        'op'         => 'update',
                        'collection' => 'schools',
                        'docId'      => $schoolId,
                        'merge'      => true,
                        'data'       => ['currentSession' => $to, 'updatedAt' => date('c')],
                    ]];
                    if ($activeUpdateTime !== '') {
                        $ops[0]['precondition'] = ['updateTime' => $activeUpdateTime];
                    }
                    $activeCommitted = false;
                    try {
                        $activeCommitted = (bool) $this->firebase->firestoreCommitBatch($ops);
                    } catch (\Throwable $e) {
                        log_message('error',
                            "ACC_ROLLOVER_SETACTIVE_FAILED schoolId={$this->school_id} "
                            . "target={$to} err=" . $e->getMessage());
                    }
                    if (!$activeCommitted) {
                        log_message('error',
                            "ACC_CONCURRENT_REJECTED endpoint=school_config/rollover_session "
                            . "stage=set_active schoolId={$this->school_id} "
                            . "actor={$this->admin_id} reason=cas_failed target={$to}");
                    } else {
                        // Userdata refresh AFTER FS commit confirms (D3)
                        $this->session->set_userdata([
                            'session'         => $to,
                            'current_session' => $to,
                            'session_year'    => $to,
                        ]);
                        $this->session_year = $to;
                    }
                } finally {
                    $this->_config_lock_release($setActiveLock);
                }
            }
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

        // SC-Step7 (Session Convergence — 2026-06-02): brief lock + CAS
        // around the intent-completion write. If lock-busy or CAS fails,
        // log and continue — the rollover itself succeeded; the intent
        // record is an observability artifact for retry/idempotency.
        $finaliseLock = $this->_config_lock_acquire('sessions');
        if ($finaliseLock === null) {
            log_message('error',
                "ACC_CONCURRENT_REJECTED endpoint=school_config/rollover_session "
                . "stage=intent_finalise schoolId={$this->school_id} actor={$this->admin_id} "
                . "reason=lock_timeout key={$intentKey}");
        } else {
            try {
                $fsSchoolFin = $this->firebase->firestoreGet('schools', $schoolId);
                $finUpdateTime = is_array($fsSchoolFin)
                    ? (string) ($fsSchoolFin['__updateTime'] ?? '')
                    : '';
                $ops = [[
                    'op'         => 'update',
                    'collection' => 'schools',
                    'docId'      => $schoolId,
                    'merge'      => true,
                    'data'       => [
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
                    ],
                ]];
                if ($finUpdateTime !== '') {
                    $ops[0]['precondition'] = ['updateTime' => $finUpdateTime];
                }
                try {
                    $finCommitted = (bool) $this->firebase->firestoreCommitBatch($ops);
                    if (!$finCommitted) {
                        log_message('error',
                            "ACC_CONCURRENT_REJECTED endpoint=school_config/rollover_session "
                            . "stage=intent_finalise schoolId={$this->school_id} "
                            . "actor={$this->admin_id} reason=cas_failed key={$intentKey}");
                    }
                } catch (\Throwable $e) {
                    log_message('error',
                        "ACC_ROLLOVER_INTENT_FINALISE_FAILED schoolId={$this->school_id} "
                        . "key={$intentKey} err=" . $e->getMessage());
                }
            } finally {
                $this->_config_lock_release($finaliseLock);
            }
        }

        log_message('error',
            "ACC_ROLLOVER_END schoolId={$this->school_id} from={$from} to={$to} "
            . "status={$intentStatus} sections_copied={$summary['sections_copied']} "
            . "promoted={$summary['students_promoted']} graduated={$summary['students_graduated']} "
            . "skipped={$summary['students_skipped']} errors=" . count($summary['errors']));

        // Phase 16.8 (SEC-14 — verified present, quality-improved).
        //
        // ⚠ CORRECTION TO THE PHASE 16.7 REPORT: that report claimed rollover
        // wrote NO audit record. It was wrong — this call has always existed.
        // My 16.7 grep window stopped at line ~3960 and this sits well below
        // it. Rollover has been audited all along, via the same canonical
        // log_audit() helper this controller already uses 30 times.
        //
        // The one real weakness: the summary was JSON-stuffed into the
        // free-text DESCRIPTION, so it could be read by a human but not
        // queried or aggregated. log_audit() accepts a structured $context
        // array, so the operational facts now go there — who rolled what,
        // how many sections and students moved, and whether the operator
        // edited any capacity on the way through (SEC-16).
        //
        // Actor, role, tenant, IP and timestamp are added by log_audit()
        // itself and are deliberately NOT duplicated here.
        log_audit('Configuration', 'rollover_session', $to,
            "Rolled over {$from} → {$to} (status={$intentStatus})",
            [
                // ⚠ log_audit() persists ONLY `appId`, `entityType` and `meta`
                // from $context — any other top-level key is silently
                // DISCARDED. Verified in helpers/audit_helper.php before
                // writing this: a flat payload here would have looked correct
                // and stored nothing.
                'entityType' => 'session_rollover',
                'meta' => [
                    'fromSession'        => $from,
                    'toSession'          => $to,
                    'status'             => $intentStatus,
                    'sectionsCopied'     => (int) ($summary['sections_copied']    ?? 0),
                    'sectionsSkipped'    => (int) ($summary['sections_skipped']   ?? 0),
                    'capacitiesEdited'   => (int) ($summary['capacities_edited']  ?? 0),
                    'promotionRequested' => $promoteStudents ? 1 : 0,
                    'studentsPromoted'   => (int) ($summary['students_promoted']  ?? 0),
                    'studentsGraduated'  => (int) ($summary['students_graduated'] ?? 0),
                    'studentsSkipped'    => (int) ($summary['students_skipped']   ?? 0),
                    'errorCount'         => count($summary['errors'] ?? []),
                    'setActive'          => $setActive ? 1 : 0,
                ],
            ]);

        $parts = [];
        if ($summary['sections_copied'])    $parts[] = $summary['sections_copied']    . ' sections copied';
        if ($summary['students_promoted'])  $parts[] = $summary['students_promoted']  . ' students promoted';
        if ($summary['students_graduated']) $parts[] = $summary['students_graduated'] . ' graduated to Alumni';
        $msg = 'Rollover ' . $from . ' → ' . $to . ' complete: ' . (empty($parts) ? 'no changes' : implode(', ', $parts)) . '.';
        if ($setActive) $msg .= ' Active session is now ' . $to . '.';

        // ── LC-14 · SAY THAT THE PROMOTED STUDENTS OWE NOTHING YET ───────
        //
        // Rollover moves students; it deliberately does NOT raise fee demands
        // for the destination session, and that is correct — the new session's
        // fee structures may not exist yet, and admission is itself fail-closed
        // on them. Generating demands here would either fail or invent amounts.
        //
        // What was missing is TELLING anyone. The success message read
        // "12 sections copied, 3 students promoted" and stopped, leaving every
        // promoted student Active in the new session owing nothing, with no
        // indication that a further step is required. The codebase names that
        // state as the danger ("Active-with-zero-demands") and the promotion
        // path guards it with a deferred regeneration job; this path has no
        // such job, so the operator IS the recovery mechanism and has to know.
        //
        // Confirmed at runtime on the emulator: after a rollover promoting 4
        // students, `promote_jobs` was empty and every demand still belonged
        // to the source session.
        if ($summary['students_promoted'] > 0) {
            $msg .= ' Promoted students have no fee demands in ' . $to
                 . ' yet — set up the fee structure for ' . $to
                 . ' and generate demands, or they will show as owing nothing.';
        }
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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_delete_session', 'Configuration', 'manage');

        $session = trim((string) $this->input->post('session', TRUE));
        if (!preg_match('/^\d{4}-\d{2}$/', $session)) {
            return $this->json_error('Invalid session format.');
        }

        // ── SC-Step7 (Session Convergence — 2026-06-02): lock + CAS ──
        // Pre-Step-7: read-modify-write on sessions[] without serialization.
        // A concurrent add_session, archive_session, or rollover_session
        // could have its sessions[] mutation silently overwritten by
        // delete_session's write-back of stale state.
        // Post-Step-7: lock-held window is kept short (per D2=A):
        //   1. Initial validation + dependency sweep happen OUTSIDE lock
        //      (dep sweep can take time; no FS mutation yet so no race).
        //   2. Acquire `sessions` lock briefly.
        //   3. Re-read schools doc for fresh sessions[] + currentSession +
        //      archivedSessions + __updateTime.
        //   4. Re-validate (target still in sessions[] + still not active).
        //   5. firestoreCommitBatch with merge + __updateTime precondition.
        //   6. Userdata refresh AFTER successful FS commit (D3).
        //   7. Release lock in finally.

        // STAGE 1 (outside lock) — pre-validation
        $fsSchoolPre = $this->fs->get('schools', $this->fs->schoolId());
        if (!is_array($fsSchoolPre)) $fsSchoolPre = [];

        $sessionsPre = (is_array($fsSchoolPre['sessions'] ?? null))
            ? array_values(array_filter($fsSchoolPre['sessions'], 'is_string'))
            : [];
        $activePre = (string) ($fsSchoolPre['currentSession'] ?? '');

        if (!in_array($session, $sessionsPre, true)) {
            return $this->json_error("Session {$session} is not in the sessions list.");
        }
        if ($session === $activePre) {
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
        //
        // SC-Step7: dependency sweep runs OUTSIDE lock (D2=A) — it can
        // take time (9 collection counts) and acquiring the lock around
        // it would block other session-lifecycle ops unnecessarily.
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

        // ── P20-F9 (HIGHEST-RISK CALLER) ────────────────────────────────
        // Nine dependency checks guard removal of an entire academic session
        // from schools.sessions[]. Every one of them could throw and leave
        // `has_any` false, so a transient Firestore failure was enough to let
        // a session with live students, fees, marks and timetables be removed
        // from the session list. The records themselves are not deleted — but
        // they become unreachable through the session picker, which is a
        // governance and reporting failure, and recovery means re-adding the
        // session by hand.
        $this->_dep_fail_closed($deps, 'delete_session', 'session', $session, ['session' => $session]);

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

        // STAGE 2 — lock + CAS commit
        $lock = $this->_config_lock_acquire('sessions');
        if ($lock === null) {
            log_message('error',
                "ACC_CONCURRENT_REJECTED endpoint=school_config/delete_session "
                . "schoolId={$this->school_id} actor={$this->admin_id} "
                . "reason=lock_timeout target={$session}");
            return $this->json_error(
                'Another session-management operation is in progress. Please retry in a few seconds.', 409);
        }
        try {
            // Re-read INSIDE the lock for freshest state + __updateTime
            $schoolDocId = $this->fs->schoolId();
            $fsSchool    = $this->firebase->firestoreGet('schools', $schoolDocId);
            if (!is_array($fsSchool)) $fsSchool = [];
            $sessions    = (is_array($fsSchool['sessions'] ?? null))
                ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
                : [];
            $active      = (string) ($fsSchool['currentSession'] ?? '');
            $updateTime  = (string) ($fsSchool['__updateTime'] ?? '');

            // Re-validate against fresh state — concurrent set_active_session
            // or add/delete could have changed sessions[] or currentSession
            // between Stage 1 read and lock acquisition.
            if (!in_array($session, $sessions, true)) {
                return $this->json_error("Session {$session} is not in the sessions list.");
            }
            if ($session === $active) {
                return $this->json_error("Cannot delete the active session. Set a different session as active first.");
            }

            // Build update payload — filtered sessions + filtered archivedSessions
            $sessionsAfter = array_values(array_filter($sessions, fn($s) => $s !== $session));
            $payload = [
                'sessions'  => $sessionsAfter,
                'updatedAt' => date('c'),
            ];
            if (is_array($fsSchool['archivedSessions'] ?? null)) {
                $arch = array_values(array_filter($fsSchool['archivedSessions'], 'is_string'));
                $payload['archivedSessions'] = array_values(array_filter($arch, fn($s) => $s !== $session));
            }

            // CAS commit
            $ops = [[
                'op'         => 'update',
                'collection' => 'schools',
                'docId'      => $schoolDocId,
                'merge'      => true,
                'data'       => $payload,
            ]];
            if ($updateTime !== '') {
                $ops[0]['precondition'] = ['updateTime' => $updateTime];
            }
            $committed = false;
            try {
                $committed = (bool) $this->firebase->firestoreCommitBatch($ops);
            } catch (\Throwable $e) {
                log_message('error',
                    "ACC_SESSION_DELETE_COMMIT_FAILED schoolId={$this->school_id} "
                    . "target={$session} actor={$this->admin_id} err=" . $e->getMessage());
            }
            if (!$committed) {
                log_message('error',
                    "ACC_CONCURRENT_REJECTED endpoint=school_config/delete_session "
                    . "schoolId={$this->school_id} actor={$this->admin_id} "
                    . "reason=cas_failed target={$session}");
                return $this->json_error(
                    'Another administrator updated school configuration during your request. '
                    . 'Please reload the page and retry.', 409);
            }

            // Userdata refresh AFTER successful FS commit (D3)
            $this->session->set_userdata('available_sessions', $sessionsAfter);

            log_audit('Configuration', 'delete_session', $session, "Deleted empty session {$session}");

            $this->json_success([
                'message'  => "Session {$session} deleted.",
                'sessions' => $sessionsAfter,
            ]);
        } finally {
            $this->_config_lock_release($lock);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/archive_session
    // Hides a session from normal dropdowns but keeps all data intact.
    // Toggle: pass archive=1 to archive, archive=0 to unarchive.
    // ─────────────────────────────────────────────────────────────────────
    public function archive_session()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_archive_session', 'Configuration', 'manage');

        $session = trim((string) $this->input->post('session', TRUE));
        $doArchive = (int) $this->input->post('archive', TRUE) === 1;

        if (!preg_match('/^\d{4}-\d{2}$/', $session)) {
            return $this->json_error('Invalid session format.');
        }

        // ── SC-Step7 (Session Convergence — 2026-06-02): lock + CAS ──
        // Pre-Step-7: read-modify-write on archivedSessions[] without
        // serialization. A race with concurrent set_active_session could
        // violate the invariant "an archived session is never the active
        // session" (an admin archives session X while another admin
        // activates X — both reads see X as non-active, both writes
        // commit, end state has X both archived AND active).
        // Post-Step-7: same `sessions` lock + Firestore __updateTime CAS
        // pattern as add_session / set_active_session / sync_sessions.
        // Validation is performed outside the lock (D2=A); re-validation
        // happens inside the lock against fresh state before CAS commit.
        $lock = $this->_config_lock_acquire('sessions');
        if ($lock === null) {
            log_message('error',
                "ACC_CONCURRENT_REJECTED endpoint=school_config/archive_session "
                . "schoolId={$this->school_id} actor={$this->admin_id} "
                . "reason=lock_timeout target={$session}");
            return $this->json_error(
                'Another session-management operation is in progress. Please retry in a few seconds.', 409);
        }
        try {
            // Re-read INSIDE the lock for freshest state + __updateTime
            $schoolDocId = $this->fs->schoolId();
            $fsSchool    = $this->firebase->firestoreGet('schools', $schoolDocId);
            if (!is_array($fsSchool)) $fsSchool = [];
            $sessions    = (is_array($fsSchool['sessions'] ?? null))
                ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
                : [];
            $active      = (string) ($fsSchool['currentSession'] ?? '');
            $archived    = (is_array($fsSchool['archivedSessions'] ?? null))
                ? array_values(array_filter($fsSchool['archivedSessions'], 'is_string'))
                : [];
            $updateTime  = (string) ($fsSchool['__updateTime'] ?? '');

            // Re-validate against fresh state
            if (!in_array($session, $sessions, true)) {
                return $this->json_error("Session {$session} is not in the sessions list.");
            }
            if ($doArchive && $session === $active) {
                log_message('error',
                    "ACC_SESSION_ARCHIVE_REJECTED schoolId={$this->school_id} "
                    . "actor={$this->admin_id} target={$session} reason=is_active_session");
                return $this->json_error("Cannot archive the active session. Set a different session as active first.");
            }

            if ($doArchive) {
                if (!in_array($session, $archived, true)) $archived[] = $session;
                sort($archived);
                $msg = "Session {$session} archived. It will be hidden from default dropdowns but data is preserved.";
                $auditMsg = "Archived session {$session}";
                $telemEvent = 'ACC_SESSION_ARCHIVED';
            } else {
                $archived = array_values(array_filter($archived, fn($s) => $s !== $session));
                $msg = "Session {$session} unarchived.";
                $auditMsg = "Unarchived session {$session}";
                $telemEvent = 'ACC_SESSION_UNARCHIVED';
            }

            // CAS commit via firestoreCommitBatch with merge + precondition
            $ops = [[
                'op'         => 'update',
                'collection' => 'schools',
                'docId'      => $schoolDocId,
                'merge'      => true,
                'data'       => [
                    'archivedSessions' => $archived,
                    'updatedAt'        => date('c'),
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
                    "ACC_SESSION_ARCHIVE_COMMIT_FAILED schoolId={$this->school_id} "
                    . "target={$session} actor={$this->admin_id} err=" . $e->getMessage());
            }
            if (!$committed) {
                log_message('error',
                    "ACC_CONCURRENT_REJECTED endpoint=school_config/archive_session "
                    . "schoolId={$this->school_id} actor={$this->admin_id} "
                    . "reason=cas_failed target={$session}");
                return $this->json_error(
                    'Another administrator updated school configuration during your request. '
                    . 'Please reload the page and retry.', 409);
            }

            // Audit + telemetry AFTER successful FS commit (D3)
            log_message('error',
                "{$telemEvent} schoolId={$this->school_id} actor={$this->admin_id} target={$session}");
            log_audit('Configuration', 'archive_session', $session, $auditMsg);

            $this->json_success([
                'message'           => $msg,
                'archived_sessions' => $archived,
                'session'           => $session,
                'archived'          => $doArchive,
            ]);
        } finally {
            $this->_config_lock_release($lock);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/session_stats
    // Returns per-session counts: students, sections, staff. Used by the
    // Sessions tab to surface activity at a glance (spot empty/phantom sessions).
    // ─────────────────────────────────────────────────────────────────────
    public function session_stats()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_session_stats', 'Configuration', 'view');

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
        $this->_require_role(self::ADMIN_ROLES, 'school_config_check_sessions', 'Configuration', 'view');

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
        $this->_require_role(self::ADMIN_ROLES, $action, 'Configuration', 'manage');
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
        $this->_require_role(self::ADMIN_ROLES, 'save_rc_template', 'Configuration', 'edit');
        $school   = $this->school_name;
        $template = trim((string) $this->input->post('template', TRUE));

        $allowed = ['classic', 'cbse', 'minimal', 'modern', 'elegant', 'professional'];
        if (!in_array($template, $allowed, true)) {
            return $this->json_error('Invalid template selection.');
        }

        // Firestore (sole write target)
        $this->fs->update('schools', $this->fs->schoolId(), ['reportCardTemplate' => $template, 'updatedAt' => date('c')]);

        log_audit('Configuration', 'save_report_card_template', $school, "Set report card template to {$template}");

        $this->json_success(['message' => 'Report card template saved.', 'template' => $template]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/save_report_card_config
    // Persists the report-card customisation (accent colour, section
    // toggles, custom text). Asset image URLs are written separately by
    // upload_reportcard_asset(). Payload arrives as a JSON string in `config`.
    // ─────────────────────────────────────────────────────────────────────
    public function save_report_card_config()
    {
        $this->_require_role(self::ADMIN_ROLES, 'save_rc_config', 'Configuration', 'edit');
        $school = $this->school_name;

        $raw = (string) $this->input->post('config', TRUE);
        $in  = json_decode($raw, true);
        if (!is_array($in)) {
            return $this->json_error('Invalid configuration payload.');
        }

        // ── Sanitise accent colour ──
        $accent = (string)($in['accentColor'] ?? '');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '#1a3a6b';

        // ── Sanitise section toggles (booleans only) ──
        $sectionKeys = ['photo', 'resultStrip', 'gradingLegend', 'coScholastic', 'attendance', 'signatures'];
        $sectionsIn  = is_array($in['sections'] ?? null) ? $in['sections'] : [];
        $sections    = [];
        foreach ($sectionKeys as $k) {
            $sections[$k] = !array_key_exists($k, $sectionsIn) || filter_var($sectionsIn[$k], FILTER_VALIDATE_BOOLEAN);
        }

        // ── Sanitise custom text (trim + length cap) ──
        $textIn  = is_array($in['text'] ?? null) ? $in['text'] : [];
        $textKeys = [
            'title'             => 80,
            'footer'            => 240,
            'principalName'     => 80,
            'examControllerName'=> 80,
            'classTeacherName'  => 80,
            'defaultRemark'     => 240,
        ];
        $text = [];
        foreach ($textKeys as $k => $max) {
            $text[$k] = mb_substr(trim((string)($textIn[$k] ?? '')), 0, $max);
        }

        // Merge with any existing assets (those are managed by the upload endpoint).
        $fsSchool   = $this->fs->get('schools', $this->fs->schoolId());
        $existingCfg = (is_array($fsSchool['reportCardConfig'] ?? null)) ? $fsSchool['reportCardConfig'] : [];
        $assets      = is_array($existingCfg['assets'] ?? null) ? $existingCfg['assets'] : [];

        $config = [
            'accentColor' => $accent,
            'sections'    => $sections,
            'text'        => $text,
            'assets'      => $assets,
        ];

        $this->fs->update('schools', $this->fs->schoolId(), ['reportCardConfig' => $config, 'updatedAt' => date('c')]);
        log_audit('Configuration', 'save_report_card_config', $school, 'Updated report card customisation');

        $this->json_success(['message' => 'Report card customisation saved.', 'config' => $config]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/upload_reportcard_asset
    // Uploads a report-card image asset (custom logo / principal signature /
    // class-teacher signature) to Firebase Storage and stores its URL inside
    // reportCardConfig.assets.{slot}. `slot` ∈ logoUrl|principalSignUrl|classTeacherSignUrl.
    // ─────────────────────────────────────────────────────────────────────
    public function upload_reportcard_asset()
    {
        $this->_require_role(self::ADMIN_ROLES, 'upload_rc_asset', 'Configuration', 'edit');
        $school = $this->school_name;

        $slot    = trim((string) $this->input->post('slot', TRUE));
        $allowed = ['logoUrl', 'principalSignUrl', 'classTeacherSignUrl'];
        if (!in_array($slot, $allowed, true)) {
            return $this->json_error('Invalid asset slot.');
        }
        if (empty($_FILES['asset']['name'])) {
            return $this->json_error('No file uploaded.');
        }

        $tempDir = $this->_upload_staging_dir();

        $this->load->library('upload', [
            'upload_path'   => $tempDir,
            'allowed_types' => 'jpg|jpeg|png|gif|webp',
            'max_size'      => 2048,
            'encrypt_name'  => TRUE,
        ]);

        if (!$this->upload->do_upload('asset')) {
            return $this->json_error($this->upload->display_errors('', ''));
        }

        $info       = $this->upload->data();
        $localPath  = $info['full_path'];
        // Canonical Storage scheme: schools/{schoolId}/... (ID-keyed, rename-proof).
        // Previously keyed by sanitized school NAME.
        $remotePath = "schools/{$this->school_id}/reportcard/{$slot}_" . $info['file_name'];

        // Capture previous asset path for orphan cleanup.
        $prevPath = '';
        try {
            $schoolDoc = $this->fs->get('schools', $this->fs->schoolId());
            $cfg       = is_array($schoolDoc['reportCardConfig'] ?? null) ? $schoolDoc['reportCardConfig'] : [];
            $prevUrl   = (string)($cfg['assets'][$slot] ?? '');
            if ($prevUrl !== '') $prevPath = $this->firebase->storagePathFromUrl($prevUrl);
        } catch (\Throwable $_) {}

        $uploaded = $this->firebase->uploadFile($localPath, $remotePath);
        @unlink($localPath);
        if (!$uploaded) {
            return $this->json_error('Failed to upload asset to storage.');
        }

        $url = $this->firebase->getDownloadUrl($remotePath);

        // Merge the new URL into reportCardConfig.assets without clobbering the rest.
        $schoolDoc = $this->fs->get('schools', $this->fs->schoolId());
        $config    = is_array($schoolDoc['reportCardConfig'] ?? null) ? $schoolDoc['reportCardConfig'] : [];
        if (!isset($config['assets']) || !is_array($config['assets'])) $config['assets'] = [];
        $config['assets'][$slot] = $url;
        $this->fs->update('schools', $this->fs->schoolId(), ['reportCardConfig' => $config, 'updatedAt' => date('c')]);

        if ($prevPath !== '' && $prevPath !== $remotePath) {
            $this->firebase->deleteStorageFile($prevPath);
        }

        log_audit('Configuration', 'upload_reportcard_asset', $school, "Uploaded report card asset: {$slot}");
        $this->json_success(['url' => $url, 'slot' => $slot, 'message' => 'Asset uploaded.']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST  /school_config/remove_reportcard_asset
    // Clears one asset slot (sets URL empty + best-effort storage delete).
    // ─────────────────────────────────────────────────────────────────────
    public function remove_reportcard_asset()
    {
        $this->_require_role(self::ADMIN_ROLES, 'remove_rc_asset', 'Configuration', 'edit');
        $school = $this->school_name;

        $slot    = trim((string) $this->input->post('slot', TRUE));
        $allowed = ['logoUrl', 'principalSignUrl', 'classTeacherSignUrl'];
        if (!in_array($slot, $allowed, true)) {
            return $this->json_error('Invalid asset slot.');
        }

        $schoolDoc = $this->fs->get('schools', $this->fs->schoolId());
        $config    = is_array($schoolDoc['reportCardConfig'] ?? null) ? $schoolDoc['reportCardConfig'] : [];
        $prevUrl   = (string)($config['assets'][$slot] ?? '');
        if (!isset($config['assets']) || !is_array($config['assets'])) $config['assets'] = [];
        $config['assets'][$slot] = '';
        $this->fs->update('schools', $this->fs->schoolId(), ['reportCardConfig' => $config, 'updatedAt' => date('c')]);

        if ($prevUrl !== '') {
            try { $this->firebase->deleteStorageFile($this->firebase->storagePathFromUrl($prevUrl)); } catch (\Throwable $_) {}
        }

        log_audit('Configuration', 'remove_reportcard_asset', $school, "Removed report card asset: {$slot}");
        $this->json_success(['slot' => $slot, 'message' => 'Asset removed.']);
    }

    // ── Admission Payment Configuration ────────────────────────────────

    /**
     * Render the admission payment configuration page.
     * Reads Schools/{school}/Config/AdmissionFee
     */
    public function admission_payment_config()
    {
        $this->_require_role(self::ADMIN_ROLES, 'admission_payment_config', 'Configuration', 'manage');
        $school = $this->school_name;

        // Read from Firestore
        $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
        $config = (is_array($fsSchool['admissionFee'] ?? null)) ? $fsSchool['admissionFee'] : [];

        // Parent-facing link → canonical PRODUCTION origin (www.zenxii.com), never
        // the admin's current request host. A QR/link a school prints & shares must
        // resolve publicly even though the admin may be on localhost/LAN/ngrok.
        // Prefer the readable slug (…/admission/form/st-marys-school) over the raw
        // SCH_ id; both resolve, the slug is just professional-looking.
        $this->load->helper('public_url');
        $admissionRef = trim((string) ($fsSchool['admissionSlug'] ?? ''));
        if ($admissionRef === '') {
            // Self-heal: school has a name but no slug yet (name may have been set
            // via the School Profile page or SA onboarding — not this Config tab).
            // Mint it now so the link/QR is professional regardless of save path.
            $nm = trim((string) ($fsSchool['name'] ?? ''));
            if ($nm !== '' && $nm !== $school) {
                $admissionRef = ensure_admission_slug($this->firebase, $school, $nm);
            }
            if ($admissionRef === '') $admissionRef = $school;
        }
        $public_form_url = public_admission_form_url($admissionRef);

        // Render the QR locally as a data: URI (NOT an external QR API — those are
        // blocked by our CSP img-src and fail offline). Reuses the same canonical
        // local renderer as the SIS ID-card / attendance QR flow.
        $this->load->helper('qr_token');
        $admission_qr_svg = qr_svg_data_uri($public_form_url);

        // Is the branding (name/logo/address) filled in? Drives an inline nudge
        // next to the QR so the school knows a blank poster/form == blank profile.
        $profileName = trim((string) ($fsSchool['name'] ?? ''));
        $profileNameConfigured = ($profileName !== '' && $profileName !== $school);

        $data = [
            'config'           => $config,
            'school_name'      => $school,
            'school_id'        => $this->school_id,
            'session_year'     => $this->session_year,
            'public_form_url'  => $public_form_url,
            'admission_qr_svg' => $admission_qr_svg,
            'name_configured'  => $profileNameConfigured,
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
        $this->_require_role(self::ADMIN_ROLES, 'save_admission_payment_config', 'Configuration', 'manage');
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

    /* ================================================================
       NOTIFICATION PREFERENCES (per school)
       ----------------------------------------------------------------
       MARKET EVIDENCE: the Asanify admin demo shows a Company →
       Notifications area with per-event configuration and role-based
       recipient targeting (observations supplied by the operator; this
       programme did not watch the recording).

       ZenX's MARK_REGISTRY is a platform constant shared by every tenant,
       so before this a school could not switch an event off without a code
       change and a Cloud Functions deploy for all tenants at once.

       Enforcement lives in Push_enqueue_service::enqueue() — the one choke
       point every producer passes through. These endpoints only read and
       write the preference; they never send anything.

       DELIBERATELY NOT INCLUDED: recipient re-targeting. Which audience an
       event reaches is resolved inside MARK_REGISTRY in functions/, so
       changing it needs a Functions deploy, and the correct per-event
       audience is a product decision rather than an engineering one.
       ================================================================ */

    /**
     * The events a school may switch off, grouped for the screen.
     *
     * A DELIBERATE ALLOW-LIST, not a dump of MARK_REGISTRY. Two reasons:
     * a school must not be able to silence something it does not control,
     * and an event with no meaningful "off" (a direct message) should not be
     * offered as if it had one.
     */
    private const NOTIFICATION_EVENTS = [
        'Attendance' => [
            'STAFF_REG_DECIDED'   => 'Attendance request approved or rejected (to the employee)',
            'ATTENDANCE_MARKED'   => 'Student marked absent (to the parent)',
        ],
        'Leave' => [
            'STAFF_LEAVE_DECIDED' => 'Staff leave approved or rejected (to the employee)',
            'LEAVE_DECIDED'       => 'Student leave approved or rejected (to the parent)',
        ],
        'Payroll' => [
            'SALARY_PROCESSED'    => 'Salary processed (to the employee)',
        ],
        'Fees' => [
            'FEE_PAID'            => 'Fee payment received (to the parent)',
            'FEE_DUE'             => 'Fee reminder (to the parent)',
        ],
    ];

    /** POST — current notification preferences for this school. */
    public function get_notification_settings()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_notification_settings', 'Configuration', 'view');

        $saved = [];
        try {
            $doc = $this->fs->get('schools', $this->fs->docId('profile'));
            if (is_array($doc) && is_array($doc['notificationSettings'] ?? null)) {
                $saved = $doc['notificationSettings'];
            }
        } catch (\Throwable $e) {
            log_message('error', 'get_notification_settings read failed: ' . $e->getMessage());
            return $this->json_error('Could not read notification settings.', 500);
        }

        $groups = [];
        foreach (self::NOTIFICATION_EVENTS as $group => $events) {
            $rows = [];
            foreach ($events as $mark => $label) {
                $row = is_array($saved[$mark] ?? null) ? $saved[$mark] : [];
                $rows[] = [
                    'mark'      => $mark,
                    'label'     => $label,
                    // Absent means ON: that is what every school did before this
                    // screen existed, and the screen must not silently change it.
                    'enabled'   => !array_key_exists('enabled', $row) || (bool) $row['enabled'],
                    'updatedAt' => (string) ($row['updatedAt'] ?? ''),
                    'updatedBy' => (string) ($row['updatedBy'] ?? ''),
                ];
            }
            $groups[] = ['group' => $group, 'events' => $rows];
        }

        return $this->json_success(['groups' => $groups]);
    }

    /**
     * POST — switch one event on or off for this school.
     *
     * One event per call, deliberately: a per-event write is a per-event audit
     * row, which is what makes "who silenced the fee reminders, and when"
     * answerable.
     */
    public function save_notification_setting()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_notification_setting', 'Configuration', 'manage');

        $mark    = trim((string) $this->input->post('mark'));
        $enabled = $this->input->post('enabled');

        $known = [];
        foreach (self::NOTIFICATION_EVENTS as $events) {
            $known = array_merge($known, array_keys($events));
        }
        if (!in_array($mark, $known, true)) {
            return $this->json_error('Unknown notification event.');
        }
        if ($enabled === null) {
            return $this->json_error('enabled is required.');
        }

        $on = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);

        try {
            $this->fs->update('schools', $this->fs->docId('profile'), [
                "notificationSettings.{$mark}" => [
                    'enabled'   => $on,
                    'updatedAt' => date('c'),
                    'updatedBy' => (string) ($this->admin_name ?? $this->admin_id ?? ''),
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'save_notification_setting write failed: ' . $e->getMessage());
            return $this->json_error('Could not save. Please retry.', 500);
        }

        // Switching a school's alerts off is exactly the kind of change someone
        // later needs explained, so it is audited rather than merely stored.
        if (method_exists($this, '_log_config_change')) {
            $this->_log_config_change('NOTIFICATION_SETTING', [
                'mark'    => $mark,
                'enabled' => $on ? 'true' : 'false',
            ]);
        } else {
            log_message('info', 'NOTIFICATION_SETTING [' . $mark . '] set to '
                . ($on ? 'on' : 'off') . ' by [' . ($this->admin_name ?? '') . ']'
                . ' for [' . $this->school_id . ']');
        }

        return $this->json_success([
            'message' => $on ? 'Notification switched on.' : 'Notification switched off.',
            'mark'    => $mark,
            'enabled' => $on,
        ]);
    }

    /* ================================================================
       ONBOARDING TASK LIST (per school)
       ----------------------------------------------------------------
       Staff::_onboarding_tasks() already reads
       `schools/{schoolId}.onboardingTasks` and falls back to a shipped
       default. Nothing wrote it, so the list was configurable only with
       database access. These endpoints are the missing writer.

       CRITICAL: the field lives on `schools/{schoolId}` — the bare
       document the reader uses — NOT on `{schoolId}_profile`. Writing to
       the wrong one would store a list the checklist never reads.
       ================================================================ */

    /** POST — this school's onboarding task list (empty = the shipped default is in use). */
    public function get_onboarding_tasks()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_get_onboarding_tasks', 'Configuration', 'view');

        $tasks = [];
        try {
            $doc = $this->fs->get('schools', $this->school_id);
            $raw = is_array($doc) ? ($doc['onboardingTasks'] ?? null) : null;
            if (is_array($raw)) {
                $tasks = array_values(array_filter(array_map('strval', $raw), static fn($t) => trim($t) !== ''));
            }
        } catch (\Throwable $e) {
            log_message('error', 'get_onboarding_tasks read failed: ' . $e->getMessage());
            return $this->json_error('Could not read the onboarding task list.', 500);
        }

        return $this->json_success([
            'tasks'      => $tasks,
            // So the screen can say "you are using the built-in list" rather
            // than showing an empty box that looks like a broken load.
            'usingDefault' => empty($tasks),
        ]);
    }

    /**
     * POST — replace this school's onboarding task list.
     *
     * Whole-list replace rather than per-item edits: the list is short, order
     * is meaningful to whoever reads the checklist, and a replace makes the
     * audit row a single before/after rather than a stream of moves.
     *
     * An EMPTY list is legitimate and means "go back to the built-in default" —
     * the reader already treats empty that way, so this preserves its contract
     * instead of inventing a second meaning for empty.
     */
    public function save_onboarding_tasks()
    {
        $this->_require_role(self::ADMIN_ROLES, 'school_config_save_onboarding_tasks', 'Configuration', 'manage');

        $raw = $this->input->post('tasks');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return $this->json_error('Send the task list as an array.');
        }
        if (count($raw) > 30) {
            return $this->json_error('An onboarding checklist may have at most 30 tasks.');
        }

        $tasks = [];
        $seen  = [];
        foreach ($raw as $t) {
            $t = trim((string) $t);
            if ($t === '') continue;                       // a blank row is a deletion
            if (mb_strlen($t) > 120) {
                return $this->json_error('A task name may be at most 120 characters.');
            }
            $k = mb_strtolower($t);
            if (isset($seen[$k])) continue;                // duplicates collapse
            $seen[$k] = true;
            $tasks[] = $t;
        }

        try {
            $this->fs->update('schools', $this->school_id, ['onboardingTasks' => $tasks]);
        } catch (\Throwable $e) {
            log_message('error', 'save_onboarding_tasks write failed: ' . $e->getMessage());
            return $this->json_error('Could not save. Please retry.', 500);
        }

        log_message('info', 'ONBOARDING_TASKS updated for [' . $this->school_id . '] to '
            . count($tasks) . ' task(s) by [' . ($this->admin_name ?? '') . ']');

        return $this->json_success([
            'message'      => empty($tasks)
                ? 'Cleared — the built-in checklist will be used.'
                : 'Onboarding checklist saved.',
            'tasks'        => $tasks,
            'usingDefault' => empty($tasks),
            // Checklists ALREADY STARTED keep the tasks they were opened with;
            // the operator must not be left thinking this rewrites history.
            'note'         => 'Applies to checklists started from now on.',
        ]);
    }
}
