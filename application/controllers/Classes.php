<?php
defined('BASEPATH') or exit('No direct script access allowed');
// Phase 20.3 — Section_seat_guard is a STATIC utility, so CI's loader never brings
// it in (load->library() instantiates; nothing here holds an instance). Without this
// the class is undefined at every call site and placement fatals with
// "Class Section_seat_guard not found". require_once at file level is this repo's
// existing pattern for static libraries; idempotent, one include, no behaviour change
// beyond making the already-wired guard resolvable.
require_once APPPATH . 'libraries/Section_seat_guard.php';


class Classes extends MY_Controller
{
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator'];
    /**
     * Bounded re-decides after a CAS conflict. Same value and same reason as
     * Sis::SEAT_RETRY_ATTEMPTS: a loser re-decides against the roster as it is
     * now rather than being refused outright, and stops as soon as the section
     * is genuinely full — which is the answer the operator should get.
     */
    private const SEAT_RETRY_ATTEMPTS = 4;

    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Configuration');

        $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
        $this->feeLifecycle->init($this->firebase, $this->school_name, $this->session_year, $this->admin_id ?? 'system');
    }


    public function ensure_class_exists()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'Configuration', 'manage');

        $class_label = $this->safe_path_segment(
            $this->input->post('class_name'),
            'class_name'
        );

        // In Firestore, classes are implicit from sections — no separate node needed.
        // Just verify at least one section exists for this class, or return success.
        $this->json_success([
            'class' => $class_label
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // REMOVED 2026-08-29 — `manage_classes()`, `fetch_classes_grid()` and their
    // two private label/order helpers.
    //
    // CLS-NAV-1 (2026-08-16) restored that listing screen for ONE reason: it was
    // the only clickable path to a section`s `maxStrength`. Phase 20.2 (P20.2-UI-1
    // / P20.2-CAP-3) then put "Section Strength & Occupancy" on the school_config
    // Sections tab, backed by `School_config::save_section_capacity()` — the one
    // capacity writer on the modern surface, with server-computed occupancy and
    // the over-capacity confirm flow this screen never had. The doorway is no
    // longer missing, so the duplicate listing is retired.
    //
    // `view()` / class_profile is deliberately KEPT: studentAdmission deep-links
    // into it ("Change capacity" for the class being admitted into) and
    // section_students links back through it.
    // ─────────────────────────────────────────────────────────────────────

    public function fetch_class_sections()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Configuration', 'view');
        header('Content-Type: application/json');

        $class_name = trim((string) $this->input->post('class_name'));

        if (!$class_name) {
            echo json_encode([]);
            return;
        }

        // Query sections for this class from Firestore
        $classKey = Firestore_service::classKey($class_name);
        $sectionDocs = $this->fs->schoolWhere('sections', [['className', '==', $classKey]]);

        // RTDB fallback + auto-heal if Firestore is empty
        if (empty($sectionDocs)) {
            $sectionDocs = []; // Firestore-only; no RTDB fallback
        }

        if (empty($sectionDocs)) {
            echo json_encode([]);
            return;
        }

        $sections = [];
        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'];
            $sectionName = $sd['section'] ?? '';

            // Count students in this section from students collection
            $sectionPrefixed = Firestore_service::sectionKey($sectionName);
            $classPrefixed   = Firestore_service::classKey($classKey);
            // ── Phase 16.6 (SEC-11) — SESSION-SCOPED SECTION STRENGTH ───
            //
            // THE DEFECT THIS CLOSES: this query was school-scoped but NOT
            // session-scoped, so the strength shown on the Classes screen
            // counted students from EVERY session. A 5-A holding 40 students
            // last year and 5 this year displayed as 45/40 — "FULL" — while
            // the server (correctly, since Phase 16.5) still admitted more.
            // The UI and the server disagreed about the same section, which
            // is precisely what the capacity work exists to prevent.
            //
            // Filtered through the canonical roster rule so the number shown
            // is produced by the SAME logic the capacity guard enforces, and
            // so legacy blank-session students are still counted.
            // ── LC-1 — DUAL STATUS/CLASS FIELD SHAPE ────────────────────
            //
            // THE DEFECT THIS CLOSES: the `students` collection holds BOTH
            // legacy PascalCase (`Class`, `Section`, `Status`) and canonical
            // camelCase (`className`, `section`, `status`) field shapes, with
            // mixed value casing. A Firestore equality predicate matches ONE
            // exact field name, so `['Class','==',…]` + `['Status','==','Active']`
            // SILENTLY DROPPED every student stored in the camelCase shape.
            // No error — the class simply came back short.
            //
            // This already caused a production incident: see the note at
            // Attendance.php:1218 — "students disappear from parent/teacher
            // views after admin bulk saves".
            //
            // ⚠ MY OWN PHASE 16.6 FIX WAS INCOMPLETE HERE. It correctly added
            // session scoping via _filterSectionRoster(), but left these two
            // single-shape predicates in the query — so the tolerant PHP
            // filter never saw the rows Firestore had already discarded.
            //
            // Fixed by removing ALL field predicates and filtering entirely in
            // _filterSectionRoster(), which reads both shapes for class,
            // section, session and status, and treats a blank session as
            // legacy-but-valid.
            //
            // PERFORMANCE: this is now cheaper, not dearer. The shared
            // request-memoised snapshot (also file-cached, write-invalidated)
            // replaces a per-call Firestore query — so a page listing many
            // sections costs ONE read instead of one per section.
            $studentDocs = $this->_filterSectionRoster(
                $this->_cachedStudentDocsForRollCheck(),
                $classPrefixed, $sectionPrefixed, $this->session_year
            );

            $students = [];
            foreach ($studentDocs as $sDocs) {
                $d = $sDocs['data'] ?? $sDocs;
                $st = $sDocs['data'];
                $students[] = [
                    'id'   => $st['User ID'] ?? $st['studentId'] ?? $d['id'],
                    'name' => $st['Name'] ?? $st['name'] ?? '',
                ];
            }

            $sections[] = [
                'name'         => $sectionName,
                'strength'     => count($students),
                'max_strength' => (int) ($sd['maxStrength'] ?? $sd['max_strength'] ?? 0),
                'students'     => $students,
            ];
        }

        echo json_encode($sections);
    }

    public function add_section()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'Configuration', 'edit');
        header('Content-Type: application/json');

        $class_name   = trim((string) $this->input->post('class_name'));
        $section_name = trim((string) $this->input->post('section_name'));
        $max_strength = (int) $this->input->post('max_strength');

        if (!$class_name || !$section_name || $max_strength <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
            return;
        }

        if (stripos($section_name, 'Section') !== 0) {
            $section_name = 'Section ' . strtoupper($section_name);
        }

        $classKey = Firestore_service::classKey($class_name);

        // ── Phase 16.4 (SEC-2) — CANONICAL SECTION DOCUMENT ID ──────────
        //
        // THE DEFECT THIS CLOSES: this writer built the id by hand as
        // `{schoolId}_{classKey}_{section}` — WITHOUT the session — while
        // every reader and the other writer use
        // Firestore_service::sectionDocId(), which is
        // `{schoolId}_{session}_{classKey}_{sectionKey}`. They therefore
        // addressed DIFFERENT DOCUMENTS, so a section created here was
        // invisible to everything that later looked for it:
        //
        //   • get_section_settings() (:392) read the session-ful id, found
        //     nothing, and reported max_strength = 0 — the settings screen
        //     showed nothing even though a capacity had just been saved.
        //   • MY_Controller::_sectionCapacity() read the session-ful id,
        //     found nothing, and fell back to its HARDCODED DEFAULT OF 40.
        //     A school configuring 30 seats silently had 40 enforced, and a
        //     school configuring 50 silently had 40 enforced. The capacity
        //     guards added in Phase 15 were working correctly — they were
        //     simply being handed the wrong number.
        //   • save_section_settings() (:422) then wrote a SECOND, session-ful
        //     document, leaving two records for one section.
        //
        // Using the canonical helper makes create/read/update address one
        // document. `sectionKey()` is applied inside the helper, so the
        // stored id no longer depends on this method's own prefix handling.
        $sectionDocId = $this->fs->sectionDocId($class_name, $section_name);

        // Prevent overwrite
        $existing = $this->fs->get('sections', $sectionDocId);
        if ($existing) {
            echo json_encode(['status' => 'error', 'message' => 'Section already exists']);
            return;
        }

        $this->fs->set('sections', $sectionDocId, [
            'schoolId'     => $this->school_id,
            'className'    => $classKey,
            'section'      => $section_name,
            'maxStrength'  => $max_strength,
            'session'      => $this->session_year,
            'updatedAt'    => date('c'),
        ]);

        // RTDB mirror removed per no-RTDB policy.

        echo json_encode([
            'status'        => 'success',
            'section'       => $section_name,
            'max_strength'  => $max_strength,
        ]);
    }

    public function fetch_sections_list()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Configuration', 'view');
        header('Content-Type: application/json');

        $class = trim((string) $this->input->post('class_name'));
        if (!$class) {
            echo json_encode([]);
            return;
        }

        $classKey = Firestore_service::classKey($class);
        $sectionDocs = $this->fs->schoolWhere('sections', [['className', '==', $classKey]]);

        // RTDB fallback + auto-heal
        if (empty($sectionDocs)) {
            $sectionDocs = []; // Firestore-only; no RTDB fallback
        }

        $sections = [];
        foreach ($sectionDocs as $doc) {
            $sName = $doc['data']['section'] ?? '';
            if (preg_match('/^Section\s+[A-Z]$/', $sName)) {
                $sections[] = $sName;
            }
        }

        sort($sections);
        echo json_encode($sections);
    }

    public function get_class_details()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Configuration', 'view');
        header('Content-Type: application/json');

        // Get distinct class names from sections collection
        $sectionDocs = $this->fs->schoolWhere('sections', []);

        // Phase 16.6 (SEC-12) — dead call removed. `_rtdb_fallback_all_sections()`
        // was deleted with the other RTDB fallbacks (see the note near the end
        // of this file) but THREE call sites were left behind, so an empty
        // Firestore result raised a PHP fatal error instead of returning an
        // empty list. A school that has not configured any sections yet — i.e.
        // every school on its first run — crashed this screen. Firestore-only:
        // an empty result is a legitimate answer, not a reason to fall back.

        $classSet = [];
        foreach ($sectionDocs as $doc) {
            $className = $doc['data']['className'] ?? '';
            if ($className === '') continue;
            // Use prefixed format for the value (e.g. "Class 9th")
            $prefixed = Firestore_service::classKey($className);
            $classSet[$prefixed] = true;
        }

        $classes = [];
        foreach (array_keys($classSet) as $name) {
            // Display label strips prefix; value stays prefixed
            $displayName = str_replace('Class ', '', $name);
            if (preg_match('/^(Nursery|LKG|UKG)$/i', $displayName)) {
                $classes[] = ['value' => $name, 'label' => ucfirst(strtolower($displayName))];
            } elseif (is_numeric($displayName)) {
                $classes[] = ['value' => $name, 'label' => $this->format_class_name($displayName)];
            } else {
                $classes[] = ['value' => $name, 'label' => $name];
            }
        }

        usort($classes, function ($a, $b) {
            $order = ['class nursery' => 0, 'class lkg' => 1, 'class ukg' => 2];
            $aVal = strtolower($a['value']);
            $bVal = strtolower($b['value']);
            if (isset($order[$aVal]) && isset($order[$bVal])) return $order[$aVal] <=> $order[$bVal];
            if (isset($order[$aVal])) return -1;
            if (isset($order[$bVal])) return 1;
            // Extract numeric portion for sorting (e.g. "Class 9th" → 9)
            $aNum = (int) preg_replace('/\D/', '', $aVal);
            $bNum = (int) preg_replace('/\D/', '', $bVal);
            return $aNum <=> $bNum;
        });

        echo json_encode(array_values($classes));
    }




    public function view($class_slug = null)
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Configuration', 'view');
        if (!$class_slug) {
            show_404();
            return;
        }

        // Convert slug to Firebase key
        // 8th → Class 8th
        $class_name = 'Class ' . urldecode($class_slug);

        $data['class_name'] = $class_name;

        $this->load->view('include/header');
        $this->load->view('class_profile', $data);
        $this->load->view('include/footer');
    }


    public function fetch_section_students()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Configuration', 'view');
        header('Content-Type: application/json');

        $class   = trim((string) $this->input->post('class_name'));
        $section = trim((string) $this->input->post('section_name'));

        if (!$class || !$section) {
            echo json_encode([]);
            return;
        }

        // Use prefixed format for queries
        $classPrefixed   = Firestore_service::classKey($class);
        $sectionPrefixed = Firestore_service::sectionKey($section);

        // ── Phase 16.6 (SEC-11 + SEC-12) ────────────────────────────────
        //
        // SEC-12 🔴 THE CRASH THIS REMOVES: the block that stood here called
        // `$this->_rtdb_fallback_students(...)` whenever the Firestore query
        // came back empty. THAT METHOD DOES NOT EXIST — not on this
        // controller, not on MY_Controller, nowhere in the repository. So
        // opening a section with no active students (a newly created section,
        // or one whose students have all been promoted out or withdrawn)
        // raised a PHP fatal error instead of returning an empty roster.
        // It was also an RTDB fallback, which the Firestore-only rule forbids
        // outright — so it is deleted rather than implemented.
        //
        // ⚠ The SEC-11 fix below makes an empty result MORE likely (a section
        // holding only other-session students now correctly returns nothing),
        // so leaving the dead call would have widened the crash.
        //
        // SEC-11: session scoping — see the note in get_class_sections().
        // LC-1 — see the note in get_class_sections(). Single-shape `Class` /
        // `Status` predicates silently dropped camelCase students, so all
        // field filtering now happens in _filterSectionRoster(), which reads
        // both shapes. Sorting moves to PHP for the same reason: a Firestore
        // orderBy('Name') would exclude documents that only carry `name`.
        $studentDocs = $this->_filterSectionRoster(
            $this->_cachedStudentDocsForRollCheck(),
            $classPrefixed, $sectionPrefixed, $this->session_year
        );
        usort($studentDocs, static function ($a, $b) {
            $ad = is_array($a) ? ($a['data'] ?? $a) : [];
            $bd = is_array($b) ? ($b['data'] ?? $b) : [];
            return strcasecmp(
                trim((string) ($ad['name'] ?? $ad['Name'] ?? '')),
                trim((string) ($bd['name'] ?? $bd['Name'] ?? ''))
            );
        });

        $students = [];
        foreach ($studentDocs as $doc) {
            $d = $doc['data'] ?? $doc;
            $profile = $doc['data'];
            $stuId   = $profile['User ID'] ?? $profile['studentId'] ?? $d['id'];
            $students[] = [
                'id'          => $stuId,
                'name'        => $profile['Name'] ?? $profile['name'] ?? '',
                'phone'       => $profile['Phone Number'] ?? $profile['phone'] ?? '-',
                'photo'       => $profile['Profile Pic'] ?? $profile['profilePic'] ?? null,
                'last_result' => $profile['Last_result'] ?? 'N/A',
                // Phase 11 (P11-G1) — school identity on the student list. The
                // full student document is ALREADY loaded by the query above, so
                // these are free: no extra read, no extra query, no join against
                // srNoIndex / admissionNoIndex (those are server-only indexes and
                // must never be queried for display).
                // Alias-tolerant because older documents predate the camelCase
                // convergence. APAAR is deliberately absent — masked/audited
                // admin-only, never in a list payload.
                'sr_no'        => (string) ($profile['srNo'] ?? $profile['srNumber'] ?? $profile['SR Number'] ?? ''),
                'admission_no' => (string) ($profile['admissionNumber'] ?? $profile['Admission Number'] ?? ''),
            ];
        }

        echo json_encode($students);
    }




    public function get_section_settings()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Configuration', 'view');
        header('Content-Type: application/json');

        $class   = trim((string) $this->input->post('class_name'));
        $section = trim((string) $this->input->post('section_name'));

        if (!$class || !$section) {
            echo json_encode(['max_strength' => 0]);
            return;
        }

        $classKey = Firestore_service::classKey($class);
        $sectionKey = Firestore_service::sectionKey($section);
        $sectionDocId = $this->fs->sectionDocId($class, $section);
        $data = $this->fs->get('sections', $sectionDocId);

        // Firestore-only per no-RTDB policy.
        if (!is_array($data)) {
            echo json_encode(['max_strength' => 0]);
            return;
        }

        $ms = $data['maxStrength'] ?? $data['max_strength'] ?? 0;
        echo json_encode(['max_strength' => (int) $ms]);
    }


    public function save_section_settings()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'Configuration', 'edit');
        header('Content-Type: application/json');

        $class        = trim((string) $this->input->post('class_name'));
        $section      = trim((string) $this->input->post('section_name'));
        $max_strength = (int) $this->input->post('max_strength');

        if (!$class || !$section || $max_strength <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
            return;
        }

        $classKey = Firestore_service::classKey($class);
        $sectionKey = Firestore_service::sectionKey($section);
        $sectionDocId = $this->fs->sectionDocId($class, $section);

        $this->fs->set('sections', $sectionDocId, [
            'schoolId'    => $this->school_id,
            'className'   => $classKey,
            'section'     => $sectionKey,
            'session'     => $this->session_year,
            'maxStrength' => $max_strength,
            'updatedAt'   => date('c'),
        ], true);

        // RTDB mirror removed per no-RTDB policy.

        echo json_encode(['status' => 'success']);
    }






    private function format_class_name($className)
    {
        if (!is_numeric($className)) {
            return $className;
        }

        $num = (int)$className;

        if ($num % 100 >= 11 && $num % 100 <= 13) {
            $suffix = 'th';
        } else {
            switch ($num % 10) {
                case 1:
                    $suffix = 'st';
                    break;
                case 2:
                    $suffix = 'nd';
                    break;
                case 3:
                    $suffix = 'rd';
                    break;
                default:
                    $suffix = 'th';
            }
        }

        return "Class {$num}{$suffix}";
    }

    public function loadClassesForTransfer()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Configuration', 'view');
        header('Content-Type: application/json');

        // Query all sections for this school from Firestore
        $sectionDocs = $this->fs->schoolWhere('sections', []);

        // Phase 16.6 (SEC-12) — dead call removed. `_rtdb_fallback_all_sections()`
        // was deleted with the other RTDB fallbacks (see the note near the end
        // of this file) but THREE call sites were left behind, so an empty
        // Firestore result raised a PHP fatal error instead of returning an
        // empty list. A school that has not configured any sections yet — i.e.
        // every school on its first run — crashed this screen. Firestore-only:
        // an empty result is a legitimate answer, not a reason to fall back.

        $data = [
            'classes'  => [],
            'sections' => [],
        ];

        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'];
            $classKey   = $sd['className'] ?? '';
            $sectionKey = $sd['section'] ?? '';
            if (!$classKey || !$sectionKey) continue;

            if (!isset($data['classes'][$classKey])) {
                $data['classes'][$classKey] = $classKey;
                $data['sections'][$classKey] = [];
            }
            $data['sections'][$classKey][] = $sectionKey;
        }

        echo json_encode($data);
    }

    public function transfer_students()
    {
        // Phase 16.0 (R1 / D16) — SIS now governs this operation.
        //
        // THE MISMATCH THIS CLOSES: moving a child between sections is a
        // STUDENT-PLACEMENT action, but it was gated on `Configuration:manage`
        // while every other placement path (admission, import, promotion,
        // edit, delete) is gated on `SIS`. A user who administers school
        // settings but holds no student-record rights could relocate
        // children. Operator decision 2026-08-15: align with the other
        // placement operations.
        //
        // NOTE FOR ROLLOUT: anyone who performs section transfers today needs
        // SIS `manage`. This is a deliberate tightening, not a regression.
        $this->_require_role(self::MANAGE_ROLES, 'transfer_students', 'SIS', 'manage');
        header('Content-Type: application/json');

        $studentIds  = $this->input->post('student_ids');
        $fromClass   = trim((string) $this->input->post('from_class'));
        $fromSection = trim((string) $this->input->post('from_section'));
        $toClass     = trim((string) $this->input->post('to_class'));
        $toSection   = trim((string) $this->input->post('to_section'));

        if (
            empty($studentIds) ||
            !$fromClass || !$fromSection ||
            !$toClass || !$toSection
        ) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
            return;
        }

        if ($fromClass === $toClass && $fromSection === $toSection) {
            echo json_encode(['status' => 'error', 'message' => 'Same section selected']);
            return;
        }

        // ── LC-4 · VALIDATE EVERY ID IN THE BATCH, BEFORE ANY DOCUMENT ACCESS ──
        //
        // `$studentIds` came straight from POST into two document paths without
        // ever being checked:
        //
        //     $this->fs->get('students', $this->fs->docId($stuId))
        //     $this->fs->updateEntity('students', $stuId, [...])
        //
        // docId() scopes to the tenant, so a forged id cannot simply name
        // another school's student — but scoping is prefixing, and a segment
        // containing a path separator is exactly what defeats a prefix. That is
        // what safe_path_segment() exists to stop, and it is applied to single
        // ids throughout SIS (53 call sites) while this batch had none.
        //
        // These ids arrive by POST, so CodeIgniter's permitted_uri_chars — the
        // thing that incidentally narrowed the route-parameter case — never
        // applies here. This was the more exposed of the two.
        //
        // The WHOLE batch is refused rather than skipping bad members: a
        // half-applied transfer leaves some students moved and some not, with
        // section strengths and roll numbers already recomputed around the
        // partial result. A refused transfer is trivially retried; a partial one
        // has to be unpicked by hand.
        $invalidIds = [];
        foreach ((array) $studentIds as $stuId) {
            $stuId = trim((string) $stuId);
            if ($stuId === '' || !preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $stuId)) {
                $invalidIds[] = $stuId;
            }
        }
        if (!empty($invalidIds)) {
            log_message('error', 'transfer_students: rejected ' . count($invalidIds)
                . ' malformed student id(s) from ip=' . $this->input->ip_address());
            echo json_encode([
                'status'  => 'error',
                'message' => 'One or more selected students could not be identified. '
                           . 'Refresh the list and try again.',
            ]);
            return;
        }

        $toClassKey   = Firestore_service::classKey($toClass);
        $toSectionKey = Firestore_service::sectionKey($toSection);

        // ── Phase 16.5 (SEC-4 / SEC-7 / SEC-8) — CANONICAL CAPACITY ─────
        //
        // This block previously did its own capacity arithmetic and got three
        // things wrong at once:
        //
        //  1. ⚠ REGRESSION I INTRODUCED IN PHASE 16.4. It hand-built the
        //     section doc-id WITHOUT the session — the same SEC-2 shape I
        //     fixed in create_section() but missed HERE. Before that fix both
        //     sides were session-less and agreed, so this check worked. After
        //     it, create wrote session-ful while this still read session-less,
        //     found nothing, and `if ($maxStrength > 0)` fell through —
        //     SILENTLY DISABLING the transfer capacity check for every newly
        //     created section. Fixing one reader and not its sibling is
        //     exactly the half-fix this programme forbids.
        //
        //  2. Unset capacity was treated as UNLIMITED here, while admission /
        //     import / CRM treated it as 40 (_sectionCapacity's default). The
        //     same section enforced different limits depending on how a
        //     student arrived (SEC-4). Operator decision 2026-08-15: a
        //     configured positive value wins; missing/zero/invalid means 40,
        //     everywhere.
        //
        //  3. Occupancy was counted with schoolWhere(), which is school-scoped
        //     but NOT session-scoped, so students from other sessions were
        //     counted against this session's seats (SEC-8).
        //
        // The canonical validator resolves capacity through _sectionCapacity()
        // and counts Active students scoped to school + session + class +
        // section. It also reads the shared memoised snapshot, so this REMOVES
        // a Firestore query rather than adding one.
        // ── ORDERING: VERSION BEFORE OCCUPANCY ──────────────────────────
        // The destination's version must be captured BEFORE the occupancy it
        // protects. Captured after, a placement landing in between leaves this
        // request holding a version NEWER than the roster it decided on, so the
        // CAS passes honestly on a stale decision — the second of the two
        // SEAT-RACE-1 races. Same discipline as admission and promotion.
        $transferVersion = $this->_sectionDecisionVersion(
            $toClassKey, $toSectionKey, $this->session_year
        );

        // FRESH, not the memoised roster: the occupancy this CAS protects must be
        // read AFTER the version above. Served from the shared snapshot it can
        // predate the version, and then the commit passes honestly on a count that
        // was already stale when the version was taken — which is exactly how three
        // concurrent transfers put 5 students into a 3-seat section on the emulator.
        $capOver = $this->_sectionCapacityExceeded(
            $toClassKey, $toSectionKey, $this->session_year,
            count($studentIds), '', /* fresh */ true
        );
        if ($capOver !== null) {
            echo json_encode([
                'status'  => 'error',
                'message' => $this->_sectionCapacityMessage($capOver, $toClassKey, $toSectionKey),
            ]);
            return;
        }

        $cleanClass   = Firestore_service::classKey($toClass);
        $cleanSection = Firestore_service::sectionKey($toSection);

        $fromClassKey   = Firestore_service::classKey($fromClass);
        $fromSectionKey = Firestore_service::sectionKey($fromSection);

        // ── Phase 16.0 (CS-1): ROLL-NUMBER COLLISION PRE-FLIGHT ─────────
        //
        // THE DEFECT THIS CLOSES: this method updated Class/Section only and
        // left `rollNo` untouched — with NO roll validation anywhere. A
        // student therefore CARRIED THEIR ROLL INTO THE DESTINATION. Move
        // Rahul (Roll 18) from 5-A into 5-B where Priya already holds Roll 18
        // and 5-B silently ends up with two Roll 18s. Every other placement
        // path — admission, import, edit, promotion — validates this; section
        // transfer was the only one that did not, so the roll-uniqueness
        // guarantee (School+Session+Class+Section) had a hole on a routine
        // operation.
        //
        // Nothing is silently renumbered: choosing who keeps Roll 18 is a
        // school's decision, not ours. The batch is rejected with the
        // conflicting names so an operator can resolve it deliberately —
        // the same stance promotion takes.
        //
        // ALL-OR-NOTHING: validated BEFORE any write, so a rejected batch
        // leaves no student half-moved.
        //
        // COST: +0 Firestore reads — _rollNoConflict() reads the same
        // per-request memoised snapshot the capacity check and every other
        // guard already use.
        $rollClashes = [];
        $seenInBatch = [];   // catches two students INSIDE this batch sharing a roll
        foreach ((array) $studentIds as $stuId) {
            $stuId = (string) $stuId;
            if ($stuId === '') continue;
            try {
                $stuDoc = $this->fs->get('students', $this->fs->docId($stuId));
            } catch (\Throwable $e) {
                log_message('error', "transfer roll pre-check: student read failed {$stuId}: " . $e->getMessage());
                continue;
            }
            if (!is_array($stuDoc)) continue;

            $roll = trim((string) ($stuDoc['rollNo'] ?? $stuDoc['Roll No'] ?? ''));
            if ($roll === '') continue;   // no roll to clash with

            $name = (string) ($stuDoc['name'] ?? $stuDoc['Name'] ?? $stuId);

            // (a) clash with a student ALREADY in the destination section
            $conflict = $this->_rollNoConflict($roll, $cleanClass, $cleanSection, $this->session_year, $stuId);
            if ($conflict !== null) {
                $rollClashes[] = "{$name} (Roll {$roll}) — already used by "
                               . ($conflict['name'] !== '' ? $conflict['name'] : $conflict['studentId']);
                continue;
            }
            // (b) clash with another student INSIDE this same transfer batch
            $rk = mb_strtolower($roll);
            if (isset($seenInBatch[$rk])) {
                $rollClashes[] = "{$name} (Roll {$roll}) — same roll as {$seenInBatch[$rk]} in this transfer";
                continue;
            }
            $seenInBatch[$rk] = $name;
        }

        if (!empty($rollClashes)) {
            $cls = trim(preg_replace('/^Class\s+/i', '', $cleanClass));
            $sec = trim(preg_replace('/^Section\s+/i', '', $cleanSection));
            echo json_encode([
                'status'  => 'error',
                'message' => 'Transfer blocked — Roll Number conflicts in Class ' . $cls . '-' . $sec . ': '
                           . implode('; ', array_slice($rollClashes, 0, 10))
                           . (count($rollClashes) > 10 ? ' …and ' . (count($rollClashes) - 10) . ' more' : '')
                           . '. Change the Roll Numbers, then retry.',
                'conflicts' => $rollClashes,
            ]);
            return;
        }

        // ── Phase 16.0 (CS-2): audit the move ───────────────────────────
        // Promotion records a studentHistory event; this path recorded
        // NOTHING, so a child's section could change with no trace of who
        // moved them or when. Uses the shared Student_history_service — the
        // same writer Sis::_log_history now delegates to — rather than a
        // second copy of the record shape.
        $this->load->library('Student_history_service', null, 'studentHistorySvc');
        $this->studentHistorySvc->init(
            $this->firebase,
            (string) ($this->session->userdata('admin_name') ?? 'System')
        );

        // ── PHASE 20.3h — PATH 5/5: TRANSFER (P20.2-RACE-1) ─────────────
        // Every student in this call moves to the SAME destination, so the
        // whole operation is ONE destination-section group: one section read,
        // one atomic commit of N student writes + 1 CAS marker. No per-student
        // section read, no N+1.
        //
        // Atomic or nothing: if the destination section moved since it was
        // read, Firestore rolls the entire batch back and NOT ONE student is
        // transferred — so a concurrent admission can never push the
        // destination over capacity via a half-applied transfer.
        //
        // Mints no identity: transfer moves existing students, so SRNO, Student
        // ID and Admission Number are untouched (verified Phase 20.3e-1).
        $casTransferIds = [];
        if (Section_seat_guard::enabled()) {
            // CI3 has NO autoloader for libraries — both must be loaded here or
            // the first property access is a hard fatal. Classes.php does not
            // otherwise use entity_firestore_sync (Sis.php loads it at :75).
            $this->load->library('entity_firestore_sync', null, 'entity_sync');
            $this->load->library('section_seat_guard', null, 'seatGuard');
            $this->seatGuard->init($this->fs, $this->firebase,
                (string) $this->school_id, (string) ($this->admin_id ?? 'system'));

            $group = [];
            foreach ($studentIds as $stuId) {
                $group[] = ['studentId' => $stuId, 'data' => [
                    'Class'   => $cleanClass,
                    'Section' => $cleanSection,
                ]];
            }

            // ── BOUNDED RE-DECIDE, matching every other protected workflow ──
            // A CONFLICT means a competing placement committed first, so this
            // transfer's capacity decision is stale, not wrong. Re-decide against
            // the destination as it is NOW — version first, then a FRESH count
            // (the memoised roster still holds the one that lost) — and retry.
            // Only CONFLICT is retryable: a FAILURE outcome is unknown, and
            // re-issuing an unknown outcome is how duplicate placements are made.
            $res      = ['status' => '', 'message' => ''];
            $__capMsg = '';
            for ($__ta = 1; $__ta <= self::SEAT_RETRY_ATTEMPTS; $__ta++) {
                if ($__ta > 1) {
                    $transferVersion = $this->_sectionDecisionVersion(
                        $toClassKey, $toSectionKey, $this->session_year
                    );
                    $__over = $this->_sectionCapacityExceeded(
                        $toClassKey, $toSectionKey, $this->session_year,
                        count($studentIds), '', true
                    );
                    if ($__over !== null) {
                        $__capMsg = $this->_sectionCapacityMessage(
                            $__over, $toClassKey, $toSectionKey
                        );
                        break;
                    }
                }

                $res = $this->seatGuard->placeStudentGroup(
                    $this->entity_sync, $group, $cleanClass, $cleanSection,
                    $this->session_year, $transferVersion
                );
                if (($res['status'] ?? '') === Section_seat_guard::PLACED) break;
                if (($res['status'] ?? '') !== Section_seat_guard::CONFLICT) break;
            }

            if ($__capMsg !== '') {
                return $this->json_error($__capMsg);
            }
            if (($res['status'] ?? '') !== Section_seat_guard::PLACED) {
                log_message('error',
                    "ACC_TRANSFER_REFUSED schoolId={$this->school_id} actor={$this->admin_id} "
                    . "dest={$cleanClass}/{$cleanSection} count=" . count($group)
                    . " status=" . ($res['status'] ?? '?'));
                return $this->json_error($res['message']
                    ?: 'The transfer could not be completed. No student was moved.');
            }
            // Committed atomically — everyone moved. Fall through so the
            // existing audit/history loop below runs unchanged for each.
            $casTransferIds = $studentIds;
        }

        // DYNAMIC (decision C): does this school RE-PRICE fees on a class change?
        // Per-school flag `reprice_on_class_transfer` in the fees policy doc
        // (feeSettings/{school}_{session}_blocking_policy — same screen as the TC
        // dues policy); absent ⇒ false ⇒ keep existing demands and just WARN the
        // operator (the safe default). When true, a cross-class move archives
        // unpaid old-class tuition and regenerates at the new class chart (paid
        // history + one-off module charges preserved by reassignFeesOnPromotion).
        // Read once per request.
        $repriceOnClassChange = false;
        try {
            $polDoc = $this->firebase->firestoreGet(
                'feeSettings', "{$this->school_name}_{$this->session_year}_blocking_policy"
            );
            $repriceOnClassChange = is_array($polDoc) && !empty($polDoc['reprice_on_class_transfer'])
                && $polDoc['reprice_on_class_transfer'] !== 'false';
        } catch (\Throwable $e) { /* default false — keep + warn */ }
        $feesRepriced = 0;

        $transferred = 0;
        foreach ($studentIds as $stuId) {
            if (Section_seat_guard::enabled()) {
                // Already written in the atomic group above.
                $ok = in_array($stuId, $casTransferIds, true);
            } else {
                $ok = $this->fs->updateEntity('students', $stuId, [
                    'Class'     => $cleanClass,
                    'Section'   => $cleanSection,
                    'className' => $cleanClass,   // lowercase alias
                    'section'   => $cleanSection,  // lowercase alias
                    'updatedAt' => date('c'),
                ]);
            }
            if (!$ok) continue;
            $transferred++;

            // Lifecycle-critic fix: keep the denormalized class/section LABEL on
            // this student's current-session feeDemands in step with their new
            // placement, so section-filtered fee reports don't misplace their
            // dues. ONLY for a same-class section move — a cross-class transfer
            // needs a fee REASSIGNMENT (different chart), not a relabel, so we
            // deliberately skip it here rather than mask an amount mismatch.
            // Best-effort: a label refresh must never fail an executed transfer.
            if ($fromClassKey === $cleanClass) {
                try {
                    $this->feeLifecycle->updateDemandPlacement($stuId, $cleanClass, $cleanSection);
                } catch (\Throwable $e) {
                    log_message('error', "transfer_students: demand relabel failed for {$stuId}: " . $e->getMessage());
                }
            } elseif ($repriceOnClassChange) {
                // Cross-class move + school opted into re-pricing: archive unpaid
                // old-class tuition and regenerate at the new class chart. Paid /
                // partial demands and one-off module charges are preserved by
                // reassignFeesOnPromotion. Best-effort — never fail an executed move.
                try {
                    // prorate=true — a mid-year TRANSFER re-prices only the current
                    // month onward; past + already-paid months stay at the old class.
                    $this->feeLifecycle->reassignFeesOnPromotion(
                        $stuId, $fromClassKey, $fromSectionKey,
                        $cleanClass, $cleanSection, (string) $this->school_id, true
                    );
                    $feesRepriced++;
                } catch (\Throwable $e) {
                    log_message('error', "transfer_students: fee reprice failed for {$stuId}: " . $e->getMessage());
                }
            }

            // Audit AFTER a confirmed write, so history never records a move
            // that did not happen. A failed audit never fails the transfer.
            $this->studentHistorySvc->log(
                (string) $this->school_id,
                (string) $stuId,
                'SECTION_TRANSFER',
                "Transferred from {$fromClassKey} {$fromSectionKey} to {$cleanClass} {$cleanSection}",
                [
                    'from_class'   => $fromClassKey,
                    'from_section' => $fromSectionKey,
                    'to_class'     => $cleanClass,
                    'to_section'   => $cleanSection,
                    'session'      => $this->session_year,
                ]
            );

            // RTDB mirror removed per no-RTDB policy. Firestore `students` is the sole source.
        }

        // Lifecycle-critic fix: a CROSS-class move does not re-bill fees (the new
        // class may have a different chart) and we deliberately do NOT relabel or
        // silently re-price existing demands (that would mask an amount mismatch).
        // Tell the operator to review/regenerate fees rather than let it pass
        // silently. A same-class section move already relabelled the demands above.
        $crossClass = ($fromClassKey !== $cleanClass);
        $note = '';
        if ($crossClass && $feesRepriced > 0) {
            $note = ". Fees were re-priced at the {$cleanClass} chart for {$feesRepriced} student(s) "
                  . "(unpaid old-class demands archived; paid history preserved).";
        } elseif ($crossClass) {
            $note = ". NOTE: this is a class change — existing fee demands were NOT re-billed at the "
                  . "{$cleanClass} rates. Review or regenerate fees for the moved student(s).";
        }
        $resp = [
            'status'  => 'success',
            'message' => "{$transferred} student(s) transferred successfully" . $note,
        ];
        if ($crossClass && $feesRepriced === 0) { $resp['fee_review_required'] = true; }
        if ($feesRepriced > 0) { $resp['fees_repriced'] = $feesRepriced; }
        echo json_encode($resp);
    }

    /**
     * Delete a section. POST only.
     * Checks for pending student fees before allowing deletion.
     */
    public function delete_section()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'Configuration', 'manage');
        header('Content-Type: application/json');

        $class   = trim((string) $this->input->post('class_name'));
        $section = trim((string) $this->input->post('section_name'));

        if (!$class || !$section) {
            echo json_encode(['status' => 'error', 'message' => 'Class and section are required.']);
            return;
        }

        // Check if any students in this section have pending fee demands
        try {
            $classPrefixed   = Firestore_service::classKey($class);
            $sectionPrefixed = Firestore_service::sectionKey($section);

            // Phase 16.6 (SEC-11) — session-scoped. This guard blocks deleting
            // a section whose students still owe fees; counting EVERY session
            // meant last year's students could veto the deletion of a section
            // that is empty this year, with a dues figure the operator cannot
            // act on. Same canonical roster rule as the capacity guard.
            // LC-1 — see get_class_sections(). This guard blocks deleting a
            // section whose students still owe fees; a single-shape predicate
            // could hide camelCase students and let the section be deleted
            // while children were still assigned to it.
            $sectionStudents = $this->_filterSectionRoster(
                $this->_cachedStudentDocsForRollCheck(),
                $classPrefixed, $sectionPrefixed, $this->session_year
            );

            if (!empty($sectionStudents)) {
                $studentsWithDues = 0;
                $totalDues = 0;

                foreach ($sectionStudents as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $studentId = $doc['data']['User ID'] ?? $doc['data']['studentId'] ?? $d['id'];
                    $pendingDocs = $this->fs->schoolWhere('feeDemands', [
                        ['studentId', '==', $studentId],
                        ['status', 'in', ['pending', 'Pending', 'overdue', 'Overdue']],
                    ]);
                    $dues = 0;
                    foreach ($pendingDocs as $pd) {
                        $dues += (float) ($pd['data']['amount'] ?? 0);
                    }
                    if ($dues > 0) {
                        $studentsWithDues++;
                        $totalDues += $dues;
                    }
                }

                if ($studentsWithDues > 0) {
                    echo json_encode([
                        'status'             => 'error',
                        'message'            => "Cannot delete section: {$studentsWithDues} student(s) have pending fees totaling Rs. {$totalDues}. Clear dues first.",
                        'students_with_dues' => $studentsWithDues,
                        'total_dues'         => $totalDues,
                    ]);
                    return;
                }
            }
        } catch (Exception $e) {
            log_message('error', "Fee check on section delete failed: " . $e->getMessage());
        }

        // Delete section document from Firestore
        try {
            $classKey = Firestore_service::classKey($class);
            $sectionKey = Firestore_service::sectionKey($section);
            $sectionDocId = $this->fs->sectionDocId($class, $section);
            $this->fs->remove('sections', $sectionDocId);

            // RTDB mirror removed per no-RTDB policy.

            echo json_encode([
                'status'  => 'success',
                'message' => "Section {$section} deleted successfully.",
            ]);
        } catch (Exception $e) {
            log_message('error', "Section delete failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete section: ' . $e->getMessage()]);
        }
    }

    // RTDB fallback methods removed per no-RTDB policy.
    // All reads now use Firestore `sections` + `students` collections exclusively.

    // Lines below were the 3 deleted _rtdb_fallback methods. Removed entirely.
}
