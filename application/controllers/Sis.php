<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Student Information System (SIS) Controller
 *
 * Consolidated module: merges Student.php + Admission_crm.php into SIS.
 * Single entry point for the entire student lifecycle:
 *   Inquiry → Application → Admission → Enrollment → Profile/Edit →
 *   Promotion → Transfer Certificate → Alumni
 *
 * Handles: SIS dashboard, student list, admission, profile management,
 *          batch promotion, transfer certificates, documents, history, ID cards.
 *
 * Firebase schema additions:
 *   Users/Parents/{school_id}/{userId}/History/{push_key}
 *       { action, description, changed_by, changed_at, metadata:{} }
 *   Users/Parents/{school_id}/{userId}/TC/
 *       { tc_no, issued_date, issued_by, reason, destination, status:active|cancelled }
 *   Schools/{school_name}/SIS/TC_Counter           → integer
 *   Schools/{school_name}/SIS/Promotions/{batch_id}/
 *       { session_from, session_to, promoted_at, promoted_by,
 *         from_class, to_class, students:[{userId, name}] }
 */
class Sis extends MY_Controller
{
    /** Roles for student information management */
    /** SEAT-RACE-1: bounded re-decide attempts for a lost placement race. */
    private const SEAT_RETRY_ATTEMPTS = 4;

    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Front Office'];

    /** Roles that may view student information */
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher', 'Front Office'];

    /** CRM base Firebase path */
    private $crm_base;

    /** Methods accessible without SIS RBAC permission (e.g., public-facing admission form) */
    private const PUBLIC_METHODS = ['online_form', 'submit_online_form'];

    public function __construct()
    {
        parent::__construct();
        // FIXED: online_form/submit_online_form must be accessible to any logged-in user,
        // not just those with SIS module permission (e.g., parents filling admission form)
        $method = $this->router->fetch_method();
        if (!in_array($method, self::PUBLIC_METHODS, true)) {
            require_permission('SIS');
        }
        $this->crm_base = "Schools/{$this->school_name}/CRM/Admissions"; // Legacy path (being retired)
        $this->load->helper('notification');

        // Fee lifecycle & defaulter check libraries
        $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
        $this->load->library('Fee_defaulter_check', null, 'feeDefaulter');
        $this->feeLifecycle->init($this->firebase, $this->school_name, $this->session_year, $this->admin_id ?? 'system');
        $this->feeDefaulter->init($this->firebase, $this->school_name, $this->session_year);

        // Entity sync for Firestore dual-writes (Android app data)
        $this->load->library('entity_firestore_sync', null, 'entity_sync');
        $this->entity_sync->init($this->firebase, $this->school_name, $this->session_year, $this->school_code);
    }

    /**
     * Build class → sections map from Firestore sections collection.
     * Returns ['9th' => ['A','B'], 'Nursery' => ['A'], ...]
     */
    private function _fs_class_map(): array
    {
        $sectionDocs = $this->fs->schoolWhere('sections', []);
        $classMap = [];
        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'];
            $className = $sd['className'] ?? '';
            $sectionName = $sd['section'] ?? '';
            if (!$className || !$sectionName) continue;
            $ordinal = str_replace('Class ', '', $className);
            $sectionLetter = str_replace('Section ', '', $sectionName);
            if (!isset($classMap[$ordinal])) $classMap[$ordinal] = [];
            if (!in_array($sectionLetter, $classMap[$ordinal])) {
                $classMap[$ordinal][] = $sectionLetter;
            }
        }
        return $classMap;
    }

    /**
     * Session-scoped class→section map: { session: { classOrd: [sections] } }.
     * Unlike _fs_class_map (which unions sections across ALL sessions), this
     * preserves the session dimension so the promote dialog can show the
     * SOURCE class's sections in the current session and the DESTINATION
     * class's sections in the selected target session — instead of a
     * session-agnostic union that offered sections not present in the target
     * session.
     */
    private function _fs_session_class_map(): array
    {
        $sectionDocs = $this->fs->schoolWhere('sections', []);
        $map = [];
        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'] ?? $doc;
            $session     = (string) ($sd['session']   ?? '');
            $className   = (string) ($sd['className'] ?? '');
            $sectionName = (string) ($sd['section']   ?? '');
            if ($session === '' || $className === '' || $sectionName === '') continue;
            $ordinal       = str_replace('Class ', '', $className);
            $sectionLetter = str_replace('Section ', '', $sectionName);
            if (!isset($map[$session]))            $map[$session] = [];
            if (!isset($map[$session][$ordinal]))  $map[$session][$ordinal] = [];
            if (!in_array($sectionLetter, $map[$session][$ordinal], true)) {
                $map[$session][$ordinal][] = $sectionLetter;
            }
        }
        return $map;
    }

    /* ══════════════════════════════════════════════════════════════════════
       DASHBOARD
    ══════════════════════════════════════════════════════════════════════ */

    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_view', 'SIS', 'view');
        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        // Read all students from the cached snapshot (one Firestore read per
        // 90s, busted on write) — same smoothing as the student-list page.
        $studentList = $this->_cached_student_docs();
        $index = [];
        foreach ($studentList as $s) {
            $d = $s['data'] ?? $s;
            $uid = $d['studentId'] ?? $d['User Id'] ?? $d['userId'] ?? '';
            if ($uid === '') continue;
            $index[$uid] = [
                'name'    => $d['name'] ?? $d['Name'] ?? '',
                'class'   => $d['className'] ?? $d['Class'] ?? '',
                'section' => $d['section'] ?? $d['Section'] ?? '',
                'status'  => $d['status'] ?? $d['Status'] ?? 'Active',
                'gender'  => $d['gender'] ?? $d['Gender'] ?? '',
            ];
        }

        // Enrolled in current session (OPT 3: single bulk read)
        $enrolledIds = $this->_get_enrolled_ids();

        $totalStudents = count($index);
        $tcCount       = 0;
        $classCounts   = [];

        foreach ($index as $uid => $entry) {
            if (!is_array($entry)) continue;
            $status = $entry['status'] ?? 'Active';

            // TC count
            if ($status === 'TC') $tcCount++;

            // Class-wise enrolled count
            if (isset($enrolledIds[$uid])) {
                $cls = trim($entry['class'] ?? 'Unknown');
                $classCounts[$cls] = ($classCounts[$cls] ?? 0) + 1;
            }
        }
        ksort($classCounts);

        $enrolledCount = 0;
        foreach ($enrolledIds as $uid => $_) {
            if (isset($index[$uid])) $enrolledCount++;
        }

        // Recent promotions from school doc
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $promotions = $schoolDoc['promotions'] ?? [];
        if (!is_array($promotions)) $promotions = [];
        arsort($promotions);
        $recentPromotions = array_slice($promotions, 0, 5, true);

        $data['total_students']    = $totalStudents;
        $data['enrolled_count']    = $enrolledCount;
        $data['tc_count']          = $tcCount;
        $data['class_counts']      = $classCounts;
        $data['recent_promotions'] = $recentPromotions;
        $data['session_year']      = $session;

        $this->load->view('include/header');
        $this->load->view('sis/index', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT LIST
    ══════════════════════════════════════════════════════════════════════ */

    public function students()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_students', 'SIS', 'view');
        $session = $this->session_year;

        $data['class_map']    = $this->_fs_class_map();
        $data['session_year'] = $session;

        $this->load->view('include/header');
        $this->load->view('sis/students', $data);
        $this->load->view('include/footer');
    }

    /**
     * RBAC gate override — fixes the Layer-2 role-name gate (F3).
     *
     * The base MY_Controller::_require_role() hard-matches the ROLE NAME
     * against the allow-list. That silently locks out custom tenant roles
     * which legitimately hold the SIS module permission, and makes the
     * per-action labels ('crm_view'/'crm_approve'/…) purely decorative.
     * Mirroring the Attendance controller, defer to the real RBAC
     * permission: any role granted the SIS module (already required in the
     * constructor for every non-public method) passes. This also resolves the
     * teacher-PII over-exposure (F8) — access is now governed by the SIS grant
     * rather than a hardcoded name list, so a Teacher without SIS can't reach
     * the CRM at all. Pass $strict = true to force the hard name floor where a
     * specific role is genuinely required.
     */
    protected function _require_role(array $allowed, string $action = '', ?string $module = null, string $level = 'view'): void
    {
        // Signature matches the base MY_Controller::_require_role (Unified-RBAC
        // capability bridge: ?string $module + string $level). Graded path: a
        // role holding the SIS module AT THE REQUESTED LEVEL ($level:
        // view|edit|manage) passes without appearing in the hardcoded name
        // list. Pass the sentinel module 'strict' (was the boolean `true`) to
        // force the hard name floor. Callers pass module 'SIS' + the site's
        // real level; a bare/absent module keeps the legacy view-floor default.
        $strict = ($module === 'strict');
        if (!$strict && has_permission('SIS', $level)) {
            return;
        }
        // Non-strict sites pass the real module + level so the base enforces
        // graded authority (a holder at a LOWER level is denied, not re-granted by
        // the legacy name list). Strict sites keep the hard name floor.
        if ($strict) {
            parent::_require_role($allowed, $action);
        } else {
            parent::_require_role($allowed, $action, 'SIS', $level);
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       ADMISSION
    ══════════════════════════════════════════════════════════════════════ */

    public function admission()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_admission', 'SIS', 'manage');
        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        $classMap = $this->_fs_class_map();

        // Preview next student ID
        $userId = $this->_peekNextStudentId($school_id);

        // Fees Exemption v2 (P0-b): the legacy exemption-checkbox feed was
        // removed here. The checkbox iterated schoolList('feeStructures')'s
        // doc-FIELD names (schoolId/session/feeHeads/...) as checkbox values,
        // so it could never store a real fee-head name. Concessions are now
        // captured via the dedicated Fee_concessions screen (Phase 0+).
        $data['class_map']     = $classMap;
        $data['session_year']  = $session;
        $data['school_name']   = $school_name;
        $data['user_Id']       = $userId;

        // LEAD SYSTEM — pass lead_id to view for prefill via AJAX
        $data['lead_id'] = trim($this->input->get('lead_id') ?? '');

        $this->load->view('include/header');
        $this->load->view('studentAdmission', $data);
        $this->load->view('include/footer');
    }

    public function save_admission()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_save_admission', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        // ── Basic fields ────────────────────────────────────────────────
        $name        = trim($this->input->post('name')           ?? '');
        $userId      = trim($this->input->post('user_id')       ?? '');
        $classOrd    = Firestore_service::classKey(trim($this->input->post('class') ?? ''));   // "Class 9th"
        $section     = Firestore_service::sectionKey(trim($this->input->post('section') ?? ''));   // "Section A"
        $phone       = trim($this->input->post('phone_number')  ?? $this->input->post('phone') ?? '');
        $email       = trim($this->input->post('email')         ?? '');
        $rollNo      = trim($this->input->post('roll_no')       ?? '');
        // Phase 9.1 (2026-08-13) — SRNO is no longer read from POST here
        // (or anywhere in the admission flow) — it is ALWAYS server-
        // allocated inside _claimIdentityAttributes(), same as
        // enroll_student(). Any `sr_no` a client might still send is
        // silently ignored. APAAR remains optional/operator-entered.
        $apaarInput  = trim((string) $this->input->post('apaar_id'));

        // M-07 FIX: Validate email format before storing
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json_error('Invalid email address format.');
        }

        // ── Phase 15 (HIGH-1) — Section capacity, SERVER-AUTHORITATIVE ──────
        // Direct admission previously had NO capacity awareness at all: an
        // operator could admit the 41st student into a 40-seat section and
        // nothing objected. The CRM screen showed live headroom via
        // get_class_sections(), but that is a UI SUGGESTION helper — source
        // check confirmed enroll_student() had no server-side guard either,
        // so the client was the only thing standing between a school and an
        // over-full register on BOTH paths.
        //
        // Checked here, at commit time, against the LIVE roster — not against
        // whatever the browser saw when the form was opened. A stale tab whose
        // seats were consumed while the operator typed is correctly rejected.
        //
        // Placed BEFORE the roll check and before any write, so a rejection
        // burns no Student ID, claims no SRNO, and creates no document.
        // SEAT-RACE-1: capture the section version BEFORE counting occupancy, so
        // the version covers the count itself. It is carried into the placement
        // commit as the CAS precondition, which is what makes this decision
        // unfalsifiable by a concurrent admission rather than merely unchanged
        // during the commit. Empty means the version could not be established;
        // the guard then refuses, which is the fail-closed direction.
        require_once APPPATH . 'libraries/Section_seat_guard.php';
        $decisionVersion = $this->_sectionDecisionVersion($classOrd, $section, $this->session_year);
        $__occ = $this->_sectionActiveCountFresh($classOrd, $section, $this->session_year);
        Section_seat_guard::trace('decide', ['ver' => substr($decisionVersion, 11, 15), 'occ' => $__occ, 'cap' => $this->_sectionCapacity($classOrd, $section, $this->session_year)]);
        $capOver = $this->_sectionCapacityExceeded($classOrd, $section, $this->session_year, 1, '', /* fresh */ true);
        if ($capOver !== null) {
            return $this->json_error($this->_sectionCapacityMessage($capOver, $classOrd, $section));
        }

        // ── Phase 14.1 — Roll Number roster uniqueness ──────────────────────
        // Rejected BEFORE any write, so a collision can never leave a
        // half-created student (no Student ID burned, no SRNO claimed, no
        // Firestore document). Scoped to School+Session+Class+Section, so the
        // same roll stays legal in another section, class, session or school.
        // A blank roll skips the check entirely — assigning later is normal.
        if ($rollNo !== '') {
            $rollConflict = $this->_rollNoConflict($rollNo, $classOrd, $section, $this->session_year);
            if ($rollConflict !== null) {
                return $this->json_error($this->_rollNoConflictMessage($rollConflict, $classOrd, $section));
            }
        }

        // Phone format guard — matches the pattern in update_profile().
        if ($phone !== '' && !preg_match('/^[\d\s\+\-\(\)]{6,20}$/', $phone)) {
            return $this->json_error('Invalid phone number format.');
        }

        // ── Dates — format dd-mm-YYYY to match Student.php ──────────
        $rawDob  = trim($this->input->post('dob') ?? '');
        $rawAdm  = trim($this->input->post('admission_date') ?? '');
        $dob     = $rawDob ? date('d-m-Y', strtotime($rawDob)) : '';
        $admDate = $rawAdm ? date('d-m-Y', strtotime($rawAdm)) : date('d-m-Y');

        // ── Personal ────────────────────────────────────────────────
        $gender      = trim($this->input->post('gender')        ?? '');
        $category    = trim($this->input->post('category')      ?? '');
        $bloodGroup  = trim($this->input->post('blood_group')   ?? '');
        $religion    = trim($this->input->post('religion')      ?? '');
        // FIXED: "Other" religion should use the custom value (matches edit_student logic)
        if ($religion === 'Other') {
            $otherReligion = trim($this->input->post('other_religion') ?? '');
            if ($otherReligion !== '') $religion = $otherReligion;
        }
        $nationality = trim($this->input->post('nationality')   ?? '');

        // ── Family ──────────────────────────────────────────────────
        $father       = trim($this->input->post('father_name')       ?? '');
        $fatherOcc    = trim($this->input->post('father_occupation') ?? '');
        $mother       = trim($this->input->post('mother_name')       ?? '');
        $motherOcc    = trim($this->input->post('mother_occupation') ?? '');
        $guardContact = trim($this->input->post('guard_contact')     ?? '');
        $guardRelation= trim($this->input->post('guard_relation')    ?? '');

        // ── Previous Education ──────────────────────────────────────
        $preClass  = trim($this->input->post('pre_class')  ?? '');
        $preSchool = trim($this->input->post('pre_school') ?? '');
        $preMarks  = trim($this->input->post('pre_marks')  ?? '');
        if ($preMarks !== '' && substr($preMarks, -1) !== '%') {
            $preMarks .= '%';
        }

        // ── Address (separate fields matching Student.php) ──────────
        $street     = trim($this->input->post('street')      ?? $this->input->post('address') ?? '');
        $city       = trim($this->input->post('city')        ?? '');
        $state      = trim($this->input->post('state')       ?? '');
        $postalCode = trim($this->input->post('postal_code') ?? '');

        if (empty($name) || empty($classOrd) || empty($section)) {
            return $this->json_error('Name, class, and section are required.');
        }

        // Sanitize path segments (exits with json_error on invalid input)
        $this->safe_path_segment($classOrd, 'class');
        $this->safe_path_segment($section, 'section');

        // BUG-076 Part 2-A (walk-in path, 2026-08-12) — fee-structure
        // pre-flight. execute_promotion() and enroll_student() have carried
        // this guard since 2026-05-28/05-31; save_admission() never did, so the
        // walk-in path could still admit a student Active-with-zero-demands
        // (the STU0004-11 incident) whenever the operator had not configured
        // the destination fee structure. Placement is load-bearing: this runs
        // BEFORE _nextStudentId() below, so a blocked admission does not burn a
        // student ID off the counter (the burned-ID hazard documented further
        // down this method).
        $feeStructErr = $this->_fee_structure_missing($classOrd, $section, $session, 'Admission');
        if ($feeStructErr !== '') {
            return $this->json_error($feeStructErr);
        }

        // G10 (2026-08-08) — resolve + validate the admission fee BEFORE any
        // student/counter/Auth write, so a blocked or mis-configured collection
        // rejects the request cleanly rather than admitting a student whose fee
        // then can't be charged. Exits via json_error on any problem. Applied
        // after the student exists (see _admission_fee_apply below).
        $admissionFeePlan = $this->_admission_fee_precheck($classOrd);

        // Always generate userId at save time (counter increments only here, not on page load)
        $generated = $this->_nextStudentId($school_id);
        if (!$generated) {
            return $this->json_error('Failed to generate student ID. Please try again.');
        }
        $userId = $generated;

        // Check for duplicate — ensures no existing profile is overwritten
        $existing = $this->_getStudent($userId);
        if (!empty($existing)) {
            return $this->json_error("Student ID {$userId} already exists.");
        }

        // ── Password — same generation method as Student.php ────────
        $password = $this->_generatePassword($name, $dob);

        // ── Photo & Document uploads ────────────────────────────────
        $classKeyForPath = $classOrd;  // Already "Class 8th"
        $combinedClassPath = "{$classKeyForPath}/{$section}";  // "Class 8th/Section A"

        $profilePicUrl = '';
        $docData = [
            'Birth Certificate'    => ['url' => '', 'thumbnail' => ''],
            'Aadhar Card'          => ['url' => '', 'thumbnail' => ''],
            'Transfer Certificate' => ['url' => '', 'thumbnail' => ''],
            'Photo'                => ['url' => '', 'thumbnail' => ''],
        ];

        // Student photo (optional in SIS — can upload later via documents page)
        if (!empty($_FILES['student_photo']['tmp_name']) && is_uploaded_file($_FILES['student_photo']['tmp_name'])) {
            $photoResult = $this->_uploadStudentFile(
                $_FILES['student_photo'], $school_name, $combinedClassPath, $userId, 'profile', 'profile'
            );
            if ($photoResult) {
                $profilePicUrl = $photoResult['document'];
                $docData['Photo'] = ['url' => $photoResult['document'], 'thumbnail' => $photoResult['thumbnail']];
            }
        }

        // Documents (Birth Certificate, Aadhar Card, Transfer Certificate)
        $docInputs = [
            'birthCertificate'    => 'Birth Certificate',
            'aadharCard'          => 'Aadhar Card',
            'transferCertificate' => 'Transfer Certificate',
        ];
        foreach ($docInputs as $inputKey => $label) {
            if (!empty($_FILES[$inputKey]['tmp_name']) && is_uploaded_file($_FILES[$inputKey]['tmp_name'])) {
                $uploadResult = $this->_uploadStudentFile(
                    $_FILES[$inputKey], $school_name, $combinedClassPath, $userId, $label, 'document'
                );
                if ($uploadResult) {
                    $docData[$label] = ['url' => $uploadResult['document'], 'thumbnail' => $uploadResult['thumbnail']];
                }
            }
        }

        // ── Carry over files uploaded on the public admission form (lead) ──
        // When converting a public application, reuse the photo / Aadhaar /
        // supporting documents the parent already uploaded (they live in
        // Firebase Storage as permanent tokenized URLs) instead of forcing the
        // admin to re-scan them. URLs are read SERVER-SIDE from the lead doc —
        // never from client input — and only fill slots the admin did not just
        // upload above, so a fresh upload always wins.
        $leadIdForFiles = trim((string) $this->input->post('lead_id'));
        if ($leadIdForFiles !== '' && preg_match('/^[A-Za-z0-9_]+$/', $leadIdForFiles)) {
            $leadDocForFiles = $this->fs->get('crmApplications', $this->fs->docId($leadIdForFiles));
            if (is_array($leadDocForFiles)) {
                $leadPhoto = trim((string) ($leadDocForFiles['photo_url'] ?? ''));
                if ($profilePicUrl === '' && $leadPhoto !== '') {
                    $profilePicUrl    = $leadPhoto;
                    $docData['Photo'] = ['url' => $leadPhoto, 'thumbnail' => $leadPhoto];
                }
                $leadAadhaar = trim((string) ($leadDocForFiles['aadhaar_url'] ?? ''));
                if (($docData['Aadhar Card']['url'] ?? '') === '' && $leadAadhaar !== '') {
                    $docData['Aadhar Card'] = ['url' => $leadAadhaar, 'thumbnail' => ''];
                }
                if (is_array($leadDocForFiles['documents'] ?? null)) {
                    foreach ($leadDocForFiles['documents'] as $ld) {
                        if (!is_array($ld)) continue;
                        $lurl = trim((string) ($ld['url'] ?? ''));
                        $lname = trim((string) ($ld['name'] ?? ''));
                        if ($lurl === '') continue;
                        $key = $lname !== '' ? substr(preg_replace('/[^A-Za-z0-9 _.-]/', '', $lname), 0, 60) : '';
                        if ($key === '' || isset($docData[$key])) $key = 'Application Document ' . (count($docData) + 1);
                        $docData[$key] = ['url' => $lurl, 'thumbnail' => ''];
                    }
                }

                // ── Phase 1 (revised D1): REFERENCE the same canonical documents ──
                // Enrollment does NOT move or copy bytes. For every canonical
                // document the parent uploaded on the lead, add a 'student' link
                // so the one physical Storage object is now referenced by BOTH the
                // admission application and the student (reference-counted; central
                // retention). The Doc[] URLs above remain denormalized display
                // pointers. Old leads without documentRefs skip this untouched.
                if (is_array($leadDocForFiles['documentRefs'] ?? null) && $leadDocForFiles['documentRefs']) {
                    $this->load->library('document_service', null, 'docsvc');
                    $this->docsvc->init($this->firebase, $school_id);
                    $predefLabels = [
                        'birth_certificate'    => 'Birth Certificate',
                        'transfer_certificate' => 'Transfer Certificate',
                        'marksheet'            => 'Marksheet',
                        'medical_certificate'  => 'Medical Certificate',
                    ];
                    foreach ($leadDocForFiles['documentRefs'] as $ref) {
                        if (!is_array($ref)) continue;
                        $docId = trim((string) ($ref['documentId'] ?? ''));
                        if ($docId === '') continue;
                        $role  = (string) ($ref['role'] ?? ($ref['type'] ?? 'document'));
                        // Link the canonical document to the enrolled student (no copy).
                        $this->docsvc->link($docId, 'student', $userId, $role, (string) ($this->admin_id ?? 'system'));
                        // Surface the 4 predefined certificate types in the student
                        // Doc[] map (photo/aadhaar/additional are carried above already).
                        $rtype = (string) ($ref['type'] ?? '');
                        if (isset($predefLabels[$rtype])) {
                            $lbl  = $predefLabels[$rtype];
                            $rurl = trim((string) ($ref['url'] ?? ''));
                            if ($rurl !== '' && ($docData[$lbl]['url'] ?? '') === '') {
                                $docData[$lbl] = ['url' => $rurl, 'thumbnail' => ''];
                            }
                        }
                    }
                }
            }
        }

        // ── Build student data — exact schema match with Student.php ─
        $studentData = [
            'Name'           => $name,
            'User Id'        => $userId,
            'DOB'            => $dob,
            'Admission Date' => $admDate,

            'Class'          => $classOrd,
            'Section'        => $section,

            'Phone Number'   => $phone,
            'Email'          => $email,
            'Password'       => $password,

            'Category'       => $category,
            'Gender'         => $gender,
            'Blood Group'    => $bloodGroup,
            'Religion'       => $religion,
            'Nationality'    => $nationality,

            'Father Name'        => $father,
            'Father Occupation'  => $fatherOcc,
            'Mother Name'        => $mother,
            'Mother Occupation'  => $motherOcc,
            'Guard Contact'      => $guardContact,
            'Guard Relation'     => $guardRelation,

            'Pre Class'      => $preClass,
            'Pre School'     => $preSchool,
            'Pre Marks'      => $preMarks,

            'Address' => [
                'Street'     => $street,
                'City'       => $city,
                'State'      => $state,
                'PostalCode' => $postalCode,
            ],

            'Profile Pic'    => $profilePicUrl,
            'Doc'            => $docData,

            'Roll No'        => $rollNo,
            'Status'         => 'Active',
        ];

        $classKey   = $classOrd;   // Already "Class 8th"
        $sectionKey = $section;    // Already "Section A"

        // ══════════════════════════════════════════════════════════════
        // 1. FIRESTORE FIRST (primary) — Student profile for Android apps
        // ══════════════════════════════════════════════════════════════
        // SIS Wave-2 S6 (2026-05-31): capture sync return so silent failure
        // is visible in logs. Observability-only; no behavior change.
        // ── PHASE 20.3h — PATH 1/5: ADMISSION (P20.2-RACE-1) ────────────
        // Primary student write. When the atomic capacity authority is ON this
        // commits the student AND the destination-section CAS marker in one
        // Firestore batch; a concurrent writer that already moved the section
        // causes the whole batch to roll back and the admission is refused
        // without writing anything.
        //
        // A refusal returns HERE, before _claimIdentityAttributes() below, so
        // no SRNO or APAAR is claimed for an admission that did not happen
        // (ordering verified in Phase 20.3e-1).
        if (Section_seat_guard::enabled()) {
            // ── SEAT-RACE-1 · BOUNDED RE-DECIDE ─────────────────────────────
            //
            // A CAS conflict means a competing admission committed first, so
            // this request's capacity decision is stale — not wrong. Refusing
            // outright would be safe but would under-admit: five simultaneous
            // requests for two seats would fill one seat and reject four.
            //
            // So a loser re-decides against the roster as it is now, using the
            // UNCACHED count (the per-request memo still holds the roster that
            // lost) and a freshly captured section version, then retries. It
            // stops as soon as the section is genuinely full, which is the
            // answer the operator should get.
            //
            // The student id is minted before this point and is NOT re-minted
            // on retry, so a retry consumes no additional id.
            $placement = ['status' => '', 'message' => ''];
            $capacityMsg = '';
            for ($attempt = 1; $attempt <= self::SEAT_RETRY_ATTEMPTS; $attempt++) {
                if ($attempt > 1) {
                    // Re-decide FIRST: if the seats are gone, say so plainly
                    // instead of reporting a concurrency conflict.
                    // ORDER IS LOAD-BEARING — capture the version BEFORE counting.
                    // The initial decision already does this; the retry did not, and
                    // that asymmetry was the residual race. Captured:
                    //   .213710 STU0253 PLACED            -> occupancy becomes 2
                    //   .223117 STU0254 redecide occ=1    -> stale roster
                    //   .226557 STU0254 version captured  -> NEWER than that roster
                    //   .233429 STU0254 commit SUCCESS    -> CAS passes honestly
                    // A version taken after the count cannot witness a placement that
                    // landed during the count, so the CAS proves nothing about the
                    // decision. Taken before, any such placement invalidates it and we
                    // retry instead of consuming a seat that is already gone. The count
                    // may still be stale; the version makes that harmless.
                    $decisionVersion = $this->_sectionDecisionVersion(
                        $classOrd, $section, $this->session_year
                    );
                    $cap = $this->_sectionCapacity($classOrd, $section, $this->session_year);
                    if ($cap > 0) {
                        $now = $this->_sectionActiveCountFresh(
                            $classOrd, $section, $this->session_year
                        );
                        if ($now + 1 > $cap) {
                            // Match _sectionCapacityExceeded()'s return shape exactly —
                            // _sectionCapacityMessage() reads 'incoming' and 'over', and a
                            // hand-built array missing them produced a PHP warning instead
                            // of the operator-facing "section is full" message.
                            $capacityMsg = $this->_sectionCapacityMessage(
                                [
                                    'capacity'  => $cap,
                                    'current'   => $now,
                                    'incoming'  => 1,
                                    'projected' => $now + 1,
                                    'over'      => ($now + 1) - $cap,
                                ],
                                $classOrd, $section
                            );
                            break;
                        }
                    }
                    Section_seat_guard::trace('redecide', ['occ' => ($now ?? -1), 'cap' => ($cap ?? -1), 'ver' => substr($decisionVersion, 11, 15)]);
                }

                Section_seat_guard::trace('attempt', ['n' => $attempt, 'ver' => substr($decisionVersion, 11, 15), 'stu' => $userId]);
                $placement = $this->_placeStudentGuarded(
                    $userId, $studentData, $classOrd, $section, $this->session_year, 'save_admission',
                    $decisionVersion
                );

                Section_seat_guard::trace('outcome', ['n' => $attempt, 'status' => ($placement['status'] ?? '?'), 'stu' => $userId]);
                if (($placement['status'] ?? '') === Section_seat_guard::PLACED) break;

                // Only a CONFLICT is retryable. FAILURE means the outcome is
                // unknown and re-committing an unknown outcome is how duplicate
                // students are made.
                if (($placement['status'] ?? '') !== Section_seat_guard::CONFLICT) break;
            }

            if (($placement['status'] ?? '') !== Section_seat_guard::PLACED) {
                return $this->json_error(
                    $capacityMsg !== ''
                        ? $capacityMsg
                        : ($placement['message'] ?: 'Admission could not be saved.')
                );
            }
        } elseif (!$this->entity_sync->syncStudent($userId, $studentData)) {
            log_message('warning', "syncStudent returned false for {$userId} (save_admission)");
        }
        if (!$this->entity_sync->syncParent($userId, $studentData)) {
            log_message('warning', "syncParent returned false for {$userId} (save_admission)");
        }

        // G2 — Refresh sections.currentStrength after a new admit so the
        // admission section-picker's strength bars stay accurate.
        $this->_recompute_section_strength($classKey, $sectionKey);

        // Phone index — P14-1: append, never overwrite, so a sibling admitted
        // later does not evict this student from the shared phone.
        if (!empty($phone)) {
            $this->_registerPhoneIndex($phone, $userId, 'student');
        }

        // Fee month markers
        // SIS Wave-2 fix F5 (2026-05-31): fail-loud guard. Pre-fix, a
        // Firestore failure here was logged but admission continued —
        // student would exist with no monthFee field, defaulter engine
        // would report incorrect status, and admin would have no signal.
        // The current ordering ALREADY puts monthFee init before Firebase
        // Auth creation at L500+, so a fail-here abort cleanly avoids
        // orphan-Auth side-effects (Option-3 intent achieved without
        // reorder — current code is already correct).
        $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
        $monthFeeInit = [];
        foreach ($months as $m) $monthFeeInit[$m] = 0;
        $monthFeeOk = false;
        for ($attempt = 1; $attempt <= 3 && !$monthFeeOk; $attempt++) {
            try {
                $monthFeeOk = (bool) $this->fs->updateEntity('students', $userId, ['monthFee' => $monthFeeInit]);
            } catch (\Throwable $e) {
                log_message('error', "SIS admit fee init failed for {$userId} (attempt {$attempt}): " . $e->getMessage());
            }
        }
        if (!$monthFeeOk) {
            // The student doc, phone index and section strength were ALREADY
            // written above — the student DOES exist. The old message ("the
            // student record was not created") was false and made the operator
            // re-submit → a duplicate person + a burned student ID. Tell the
            // truth and direct them to re-initialize fees instead of re-adding.
            return $this->json_error('Student ' . $userId . ' was created, but fee tracking could not be initialized. Do NOT re-add the student — open their Fees page and retry fee initialization.');
        }

        // Subject assignment
        $classNumber = 0;
        if (preg_match('/\d+/', $classOrd, $classMatch)) {
            $classNumber = (int)$classMatch[0];
        }
        if ($classNumber > 0) {
            $subjectDocs = $this->fs->schoolWhere('subjects', [['classKey', '==', (string)$classNumber]]);
            $coreSubjects = [];
            foreach ($subjectDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $item = $doc['data'];
                $code = $item['subjectCode'] ?? $item['code'] ?? $d['id'];
                $subName = trim($item['name'] ?? $item['subject_name'] ?? '');
                if ($subName === '') continue;
                $type = strtolower(trim($item['category'] ?? ''));
                if ($type === 'core') $coreSubjects[(string)$code] = ['name' => $subName, 'type' => 'core'];
            }
            if (!empty($coreSubjects)) {
                $this->fs->updateEntity('students', $userId, ['subjects' => $coreSubjects]);
            }
        }

        // Additional subjects
        $additionalSubjects = $this->input->post('additional_subjects');
        if (!empty($additionalSubjects) && is_array($additionalSubjects)) {
            $addSubData = [];
            foreach ($additionalSubjects as $sub) {
                $sub = trim($sub);
                if ($sub !== '') $addSubData[$sub] = '';
            }
            if (!empty($addSubData)) {
                $this->fs->updateEntity('students', $userId, ['additionalSubjects' => $addSubData]);
            }
        }

        // Phase 9 correction pass (2026-08-13) — optional SRNO + APAAR
        // claim, wired at the SAME best-effort point subjects/
        // additionalSubjects already use (student record already exists
        // and is committed by this point). SAME shared helper
        // enroll_student() uses — one canonical wiring to
        // Sr_no_service/Apaar_service across both admission entry points.
        $srnoAssigned     = true;
        $identityWarnings = $this->_claimIdentityAttributes($userId, $school_id, $apaarInput, $studentData, $srnoAssigned);

        // ── SRNO-F1 (2026-08-17) — ADMISSION IS AN IDENTITY TRANSACTION ─────
        //
        // INVARIANT: student creation + SRNO allocation = successful admission.
        // `studentId` is the global system identity; SRNO is the school-scoped
        // OPERATIONAL identity the school works by. Half of that pair is not an
        // admission, and reporting it as one — which is exactly what this method
        // used to do, appending "SRNO requires administrator follow-up" to a
        // SUCCESS response — leaves an unusable student the operator believes is
        // finished.
        //
        // WHY FAILING HERE IS SAFE. At this point the student document, parent
        // record, phone index, section strength, monthFee and subjects exist —
        // but the Firebase Auth user, the entitlement, the fee demands, the
        // history/audit entry and the admission-fee receipt do NOT. Nothing
        // financial and no credential has been created, so this is the last
        // point at which the request can be undone cheaply. The ordering is
        // asserted by PhaseSrnoF1AdmissionIdentityTest, so a future reorder that
        // moved money above this line would fail the build.
        //
        // COMPENSATION mirrors the PROVEN Import contract (Sis:6190 and
        // Phase203kStep0ImportHarnessTest::test_GOLDEN_03_...): delete the
        // student and return the Student ID to the counter, so a refused
        // admission burns no identity. Section strength is recomputed because
        // THIS method inflated it above — leaving it would permanently consume a
        // seat for a student that does not exist, corrupting the very data the
        // atomic-capacity guard reads.
        //
        // DELIBERATELY NOT UNDONE: the phone index and the parent record. Both
        // are SHARED, append-shaped structures — the phone index is explicitly
        // append-never-overwrite so siblings can share a number — so removing
        // this student's entry risks damaging a DIFFERENT student's data. That
        // residue is reported rather than silently cleaned; a wrong repair here
        // is worse than a known one.
        if (!$srnoAssigned) {
            log_message('error', "ACC_ADMISSION_SRNO_FAILED student=[{$userId}] school=[{$school_id}] "
                . '— admission REFUSED and rolled back; no SRNO could be allocated.');

            try {
                $this->entity_sync->deleteStudent($userId);
            } catch (\Throwable $e) {
                log_message('error', "ACC_ADMISSION_SRNO_ROLLBACK_FAILED student=[{$userId}]: " . $e->getMessage());
            }
            $this->id_generator->releaseClaim('STU', (int) preg_replace('/\D/', '', $userId));
            $this->_recompute_section_strength($classKey, $sectionKey);

            return $this->json_error(
                'Admission could not be completed: an SR Number could not be allocated for this '
                . 'student, and every admitted student must have one. No student record, fee demand '
                . 'or receipt was created. Please retry; if it keeps failing, check the SR Number '
                . 'counter for this school before re-admitting.'
            );
        }

        // Fees Exemption v2 (P0-b): the exempted_fees_multiple admission write
        // was removed. Concessions are captured via Fee_concessions (Phase 0+),
        // applied by the unified generator (Phase 2+). Admission billing here
        // is unchanged — assignInitialFees still runs for this student.

        // RTDB mirror removed per no-RTDB policy. Firestore `students` is the sole source.

        // ══════════════════════════════════════════════════════════════
        // 2. FIREBASE AUTH — Parent app login
        // ══════════════════════════════════════════════════════════════
        // SIS Wave-3 D3 (2026-05-31): consolidated via _createFirebaseAuthStudent.
        // Return value intentionally ignored — save_admission's pre-fix
        // behavior was silent-continue on Auth failure. B3 fix (surface
        // Auth failure to operator) is separate Tier-2 territory.
        $this->_createFirebaseAuthStudent($userId, $password, $name, 'SIS save_admission');

        // ══════════════════════════════════════════════════════════════
        // 3.5 ENTITLEMENT RESOLUTION — PHASE 3B / B1 (must precede pricing)
        // ══════════════════════════════════════════════════════════════
        //
        // THE DEFECT THIS CLOSES. Entitlement capture used to be possible only
        // AFTER this request returned (ENTCAP.mount() runs in the response
        // handler), so a concession agreed at the admission desk could not
        // affect the demands this method was about to write. The operator saw
        // "Admission successful", the parent saw a full-price invoice, and
        // nothing anywhere reported a problem.
        //
        // An entitlement KNOWN at admission time is now submitted with the form
        // and resolved HERE — after identity, before pricing — so
        // assignInitialFees() below prices against it. Post-hoc capture through
        // ENTCAP is unchanged and still supported for concessions agreed later.
        //
        // Placement is load-bearing: after _claimIdentityAttributes() so the
        // student is complete, and before assignInitialFees() so the demand is
        // priced once, correctly, rather than written and then repaired.
        $entitlementState = $this->_resolve_admission_entitlement($userId, $school_id);

        // ══════════════════════════════════════════════════════════════
        // 4. POST-ADMISSION — Fees, history, leads, notifications
        // ══════════════════════════════════════════════════════════════
        //
        // PHASE 3B / B6 — A FINANCIAL OPERATION MUST NOT FAIL SILENTLY.
        // This used to catch, log, and carry on: the student was admitted, the
        // response said "success", and the student had ZERO demands. The
        // operator's next signal was a parent asking why no fee was due.
        //
        // The student genuinely exists by this point, so aborting with
        // json_error would be a lie in the other direction — hence an explicit
        // BILLING STATE rather than a boolean success. The admission stands; the
        // billing outcome is reported truthfully.
        $billingState = 'billed';
        $billingNote  = '';
        try {
            $assigned = $this->feeLifecycle->assignInitialFees($userId, $classOrd, $section, $school_id);
            if (!is_array($assigned) || $assigned === []) {
                // assignInitialFees() returns the assigned heads. Empty means no
                // demand was created — a missing/blocked fee structure, or a
                // failed commit that it logged internally.
                $billingState = 'billing_failed';
                $billingNote  = 'No fee demands were created. Open the student\'s Fees page and run '
                              . 'Generate Demands before collecting any payment.';
                log_message('error', "ACC_ADMISSION_BILLING_EMPTY student=[{$userId}] class=[{$classOrd}/{$section}] "
                    . '— admission succeeded with ZERO demands.');
            }
        } catch (\Throwable $e) {
            $billingState = 'billing_failed';
            $billingNote  = 'Fee generation failed for this admission. Do NOT collect payment until '
                          . 'demands have been generated from the student\'s Fees page.';
            log_message('error', "ACC_ADMISSION_BILLING_FAILED student=[{$userId}]: " . $e->getMessage());
        }

        // An entitlement that could not be resolved means the demand above was
        // priced WITHOUT it. That is a pending billing decision, not a success.
        if ($billingState === 'billed' && ($entitlementState['status'] ?? 'none') === 'failed') {
            $billingState = 'billing_pending';
            $billingNote  = 'The student was admitted and billed, but the concession could not be '
                          . 'recorded, so it is NOT reflected in these demands. Add it from the '
                          . 'student\'s record, then regenerate the demands.';
        }

        // ADM-ENT-1 — recorded, but into a store this tenant's pricing engine
        // does not read, so the demands above are FULL PRICE. Same operator
        // consequence as 'failed' (a concession not reflected in the demands),
        // so it gets the same treatment rather than a success message that is
        // not true. The wording differs because the remedy differs: re-adding
        // it from the student record will NOT help — the tenant needs the
        // canonical billing authority.
        if ($billingState === 'billed' && ($entitlementState['status'] ?? 'none') === 'recorded_not_priced') {
            $billingState = 'billing_pending';
            $billingNote  = 'The student was admitted and billed, and the concession WAS recorded — '
                          . 'but this school is not yet on entitlement-based billing, so the '
                          . 'concession is NOT reflected in these demands. They are at full price. '
                          . 'Do not collect against them until the concession has been applied.';
        }

        // Phase 3D (2026-05-09) — admission-time defaulter projection sync.
        // assignInitialFees creates feeDemands but does NOT emit the defaulter
        // event, so a never-paid student would have unpaid demands but no
        // feeDefaulters doc until first payment (verified leak: STU0004,
        // STU0005). updateDefaulterStatus reads feeDemands canonically and
        // writes the projection idempotently. Fail-soft: admission still
        // succeeds even if the projection write fails — the doc can be
        // recreated by a future payment event or by backfill_defaulters.js.
        try {
            $this->feeDefaulter->updateDefaulterStatus($userId);
        } catch (\Throwable $e) {
            log_message('error', "Phase 3D admission defaulter sync failed for {$userId}: " . $e->getMessage());
        }

        $this->_log_history($school_id, $userId, 'ADMISSION',
            "Student admitted to {$classOrd} / {$section} ({$session})",
            ['class' => $classOrd, 'section' => $section, 'session' => $session]
        );
        log_audit('SIS', 'admit_student', $userId, "Admitted student '{$name}' to {$classOrd} {$section}");

        // Lead conversion
        $leadId = trim($this->input->post('lead_id') ?? '');
        if ($leadId !== '' && preg_match('/^[A-Za-z0-9_]+$/', $leadId)) {
            $now = date('Y-m-d H:i:s');
            $lead = $this->fs->get('crmApplications', $this->fs->docId($leadId));
            if (is_array($lead)) {
                // M7-B: single CAS-atomic write (conversion fields + bounded history), concurrency-safe.
                $this->load->helper('admission_crm');
                $this->fs->casAppendBounded('crmApplications', $leadId, 'history',
                    ['action' => "Converted to student {$userId}", 'by' => $this->admin_name, 'timestamp' => $now],
                    crm_history_cap(),
                    ['status' => 'admitted', 'stage' => 'enrolled', 'student_id' => $userId, 'updated_at' => $now]);
            }
        }

        // G10 (2026-08-08) — collect (or record the audited waive of) the
        // admission fee, through the SAME Admission_collection receipt +
        // accounting path the online and CRM-counter pathways use. Runs after
        // lead conversion so a converted lead's crmApplications doc already
        // carries student_id when the receipt timeline is appended. Non-fatal:
        // the student is admitted at this point, so the outcome is reported
        // back rather than failing the request.
        $admissionFee = $this->_admission_fee_apply(
            $admissionFeePlan, $userId, $name, $father, $classOrd,
            ($leadId !== '' && preg_match('/^[A-Za-z0-9_]+$/', $leadId)) ? $leadId : ''
        );

        // Notify parent
        if ($phone !== '') {
            notify_admission_confirmed($phone, $this->school_display_name ?? $this->school_name, $userId, $name);
        }

        // PHASE 3B / B1 — the message must never claim more than the financial
        // state supports. "Admission successful" on a student with no demands,
        // or with a concession that was not applied, is the failure mode this
        // phase exists to remove.
        $message = $billingState === 'billed'
            ? 'Student admitted successfully.'
            : ($billingState === 'billing_pending'
                ? 'Student admitted — BILLING PENDING.'
                : 'Student admitted — BILLING FAILED.');
        if ($billingNote !== '') $message .= ' ' . $billingNote;
        if (($entitlementState['status'] ?? 'none') === 'applied') {
            $message .= ' Concession recorded and applied to the initial demands.';
        } elseif (($entitlementState['status'] ?? 'none') === 'recorded_not_priced') {
            // ADM-ENT-1: say what actually happened. Claiming "applied" here was
            // the whole defect — the operator had no way to know the invoice the
            // parent receives is at full price.
            $message .= ' Concession recorded, but NOT applied to these demands —'
                      . ' this school is not yet on entitlement-based billing.';
        } elseif (($entitlementState['status'] ?? 'none') === 'duplicate') {
            $message .= ' This concession was already on file — not added a second time.';
        }
        if (($admissionFee['status'] ?? '') === 'collected') {
            $message .= ' Admission fee ' . number_format((float) $admissionFee['amount'], 2)
                . ' collected — receipt ' . ($admissionFee['receipt_no'] ?? '') . '.';
        } elseif (($admissionFee['status'] ?? '') === 'already_collected') {
            $message .= ' Admission fee was already collected for this application — receipt '
                . ($admissionFee['receipt_no'] ?? '') . '. Not charged again.';
        } elseif (($admissionFee['status'] ?? '') === 'waived') {
            $message .= ' Admission fee waived.';
        } elseif (($admissionFee['status'] ?? '') === 'failed') {
            $message .= ' WARNING: the admission fee could not be collected — collect it from the'
                . ' student ledger before issuing a receipt.';
        }

        // Phase 9 correction pass — surface any SRNO/APAAR claim warnings
        // the SAME way this method already surfaces admission-fee status:
        // appended to $message. save_admission()'s response has no
        // separate `warnings` array (unlike enroll_student()), and its own
        // view (studentAdmission.php) only ever displays `res.message` —
        // adding an unused response key nothing reads would be dead
        // surface area, not a real fix.
        if (!empty($identityWarnings)) {
            $message .= ' ' . implode(' ', $identityWarnings);
        }

        // ── ONE CANONICAL TRANSPORT LIFECYCLE, BOTH ADMISSION PATHS ─────────
        //
        // Online admission already does this: public_form captures a transport
        // DECLARATION, and enroll_student() turns it into a pending
        // transportRequests row for the Transport → Requests queue.
        //
        // Direct staff admission did not, so a family admitted at the counter
        // never entered that lifecycle at all. The Services & Concessions panel
        // on this screen offered "Service enrolment (adds fee)", which wrote an
        // ACTIVE studentEntitlements kind='service' row that nothing reads — no
        // request, no queue entry, no route, no fee. Staff got a success
        // message for an outcome that never happened (SVC-ORPHAN-1).
        //
        // A DECLARATION IS NOT AN ASSIGNMENT. At this moment the school usually
        // has no route or stop for this child, and assignStudent() would raise a
        // transport FEE — so a bare declaration must never become an assignment,
        // or the family is billed for a service nobody has arranged. Route and
        // stop stay an operational decision made at approval, where the atomic
        // capacity reservation also lives.
        //
        // requestTransport() is idempotent per (student, session), so a
        // resubmitted admission cannot produce two requests.
        //
        // Best-effort BY DESIGN, exactly as enroll_student() does it: a
        // worklist entry must never fail an admission that has already created
        // the student and their Auth account.
        $transportRequest = null;
        try {
            if ((string) $this->input->post('decl_transport') === '1') {
                $this->load->library('numbering_service', null, 'trnNumbering');
                $this->trnNumbering->init($this->fs, $this->school_name, $this->session_year);
                $this->load->library('transport_service', null, 'trnSvc');
                $this->trnSvc->init($this->fs, $this->trnNumbering,
                    $this->school_name, $this->session_year, $this->admin_id ?? 'admission');
                $transportRequest = $this->trnSvc->requestTransport([
                    'studentId'      => $userId,
                    'student_name'   => (string) ($studentData['name'] ?? ''),
                    'student_class'  => (string) ($studentData['className'] ?? ''),
                    // MODEL A: a request is DESCRIPTIVE. No route_id/stop_id —
                    // management supplies the canonical pair at approval.
                    'type'           => (string) ($this->input->post('decl_transport_type') ?? 'both'),
                    'requested_area' => substr(trim((string) $this->input->post('decl_transport_area')), 0, 120),
                ], 'admission');
                $message .= ' Transport requested — it is now in Operations → Transport → Requests'
                          . ' for route and stop assignment. No transport fee has been raised yet.';
            }
        } catch (\Throwable $e) {
            log_message('error', "F14 direct-admission transport request failed for {$userId}: "
                . $e->getMessage());
        }

        // Verified relationships declared on this form. Every note is reported
        // to the operator — including the failures — because a relationship
        // that silently did not happen is the defect this replaced.
        foreach ($this->_captureAdmissionRelationships(
            $userId,
            (string) $this->input->post('decl_sibling_srno'),
            (string) $this->input->post('decl_staff_ward_id'),
            (string) $this->input->post('decl_rte_ref')
        ) as $note) {
            $message .= ' ' . $note;
        }

        return $this->json_success([
            'message'       => $message,
            'user_id'       => $userId,
            'sr_no'         => $studentData['srNo'] ?? '',   // Phase 9.1 — server-allocated, for the credentials/identity panel
            'admission_no'  => $studentData['Admission Number'] ?? '',   // Phase 10.1 — admission-event identifier, YYYY/CLASS/XXX
            'name'          => $name,
            'password'      => $password,
            'class'         => str_replace('Class ', '', $classOrd),
            'section'       => str_replace('Section ', '', $section),
            'admission_fee' => $admissionFee,
            // PHASE 3B / B1 — explicit financial state. The UI renders this
            // rather than inferring success from the HTTP status, so an
            // operator can never be told "successful" while billing is
            // incomplete. billed | billing_pending | billing_failed.
            'billing_state' => $billingState,
            'billing_note'  => $billingNote,
            'entitlement'   => $entitlementState,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PROFILE
    ══════════════════════════════════════════════════════════════════════ */

    public function profile($userId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_profile', 'SIS', 'view');
        if (empty($userId) || !$this->safe_path_segment($userId)) show_404();

        // Delegate to the comprehensive student_profile view
        $this->student_profile($userId);
    }

    public function update_profile()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_update_profile', 'SIS', 'edit');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id = $this->parent_db_key;
        $userId    = trim($this->input->post('user_id'));

        if (empty($userId)) return $this->json_error('User ID required.');
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');

        // Field names must exactly match those written by Student.php
        $allowed = [
            'Name', 'Father Name', 'Mother Name', 'Father Occupation', 'Mother Occupation',
            'Guard Contact', 'Guard Relation',
            'DOB', 'Gender', 'Blood Group', 'Category', 'Religion', 'Nationality',
            'Phone Number',   // existing field — NOT "Phone"
            'Email',
            'Roll No', 'Pre School', 'Pre Class', 'Pre Marks',
        ];

        $updates = [];
        foreach ($allowed as $field) {
            $val = $this->input->post($field);
            if ($val !== null) {
                $updates[$field] = trim($val);
            }
        }

        // Phone aliases: the admission form posts "Phone Number" (Title Case),
        // but other edit screens / Android push "phone" or "phoneNumber".
        // Accept any of them and normalize to the canonical "Phone Number" key
        // (which then maps to Firestore camelCase `phone` below).
        if (!isset($updates['Phone Number'])) {
            foreach (['phone', 'phoneNumber', 'phone_number'] as $alias) {
                $v = $this->input->post($alias);
                if ($v !== null && trim($v) !== '') {
                    $updates['Phone Number'] = trim($v);
                    break;
                }
            }
        }

        // M-07 FIX: Validate email format on profile update
        if (isset($updates['Email']) && $updates['Email'] !== '' && !filter_var($updates['Email'], FILTER_VALIDATE_EMAIL)) {
            return $this->json_error('Invalid email address format.');
        }

        // Validate phone format if provided (digits, spaces, +, -, parens).
        if (isset($updates['Phone Number']) && $updates['Phone Number'] !== ''
            && !preg_match('/^[\d\s\+\-\(\)]{6,20}$/', $updates['Phone Number'])) {
            return $this->json_error('Invalid phone number format.');
        }

        // Pre-update snapshot — fetched lazily and reused by both the
        // Address-merge branch (Step 4) AND the audit-log diff capture
        // below. Captured BEFORE the Firestore write so the before-state
        // is available for the timeline UI's old → new rendering.
        $beforeStudent = null;

        // Address is a nested object — posted as Address[Street],
        // Address[City], etc. Partial updates are common (parent only
        // wants to change the City), so we MUST merge on top of the
        // current doc rather than overwrite — otherwise unposted
        // sub-fields go blank.
        //
        // Tier-A Step 4: previously, when `_getStudent` returned null
        // (transient Firestore read failure / permission flake), the
        // code defaulted `$existing = []` and the merge produced a
        // patch containing only the posted sub-fields — silently
        // wiping every unposted sub-field on the doc. We now refuse
        // to write a partial address whenever the existing address
        // can't be confirmed, returning an explicit error so the
        // caller can retry instead of losing data.
        $addrPost = $this->input->post('Address');
        if (is_array($addrPost)) {
            $beforeStudent = $this->_getStudent($userId);
            if ($beforeStudent === null) {
                return $this->json_error(
                    'Could not load current address — please refresh and try again. ' .
                    'No fields were changed.'
                );
            }
            $existing = $beforeStudent['Address'] ?? $beforeStudent['address'] ?? [];
            $existing = is_array($existing) ? $existing : [];

            // Per-sub-field merge: only overwrite a sub-field when the
            // POST explicitly carried it. Empty-string values DO clear
            // the field (intentional — the form's "clear" UX is to
            // submit an empty input). Sub-fields absent from the POST
            // keep their current value, period.
            $merged = $existing;
            foreach (['Street', 'City', 'State', 'PostalCode'] as $sub) {
                if (array_key_exists($sub, $addrPost)) {
                    $merged[$sub] = trim((string) $addrPost[$sub]);
                }
            }
            $updates['Address'] = $merged;
        }

        if (empty($updates)) {
            return $this->json_error('No valid fields to update.');
        }

        // RTDB mirror removed per no-RTDB policy. Firestore `students` is the sole source.
        $updates['updatedAt'] = date('c');

        // ── Firestore with camelCase mapping ──────────
        // The doc gets both PascalCase (legacy admin readers) and
        // camelCase (mobile apps' StudentDoc). Mirroring Step-3's
        // edit_student coverage so a profile edit lands every field
        // the parent / teacher app reads, not just Name + Phone +
        // Email + parents. Address (nested map) is mirrored to
        // canonical `address` so the mobile profile screen sees
        // changes immediately, not only after the trailing
        // syncStudent call merges canonical keys in.
        $fsUpdates = $updates;
        if (isset($fsUpdates['Name']))              $fsUpdates['name']             = $fsUpdates['Name'];
        if (isset($fsUpdates['Phone Number'])) {
            // Mirror Entity_firestore_sync::syncStudent, which writes BOTH
            // `phone` (Android canonical) and `phoneNumber` (backward compat).
            $fsUpdates['phone']       = $fsUpdates['Phone Number'];
            $fsUpdates['phoneNumber'] = $fsUpdates['Phone Number'];
        }
        if (isset($fsUpdates['Email']))             $fsUpdates['email']            = $fsUpdates['Email'];
        if (isset($fsUpdates['DOB']))               $fsUpdates['dob']              = $fsUpdates['DOB'];
        if (isset($fsUpdates['Gender']))            $fsUpdates['gender']           = $fsUpdates['Gender'];
        if (isset($fsUpdates['Category']))          $fsUpdates['category']         = $fsUpdates['Category'];
        if (isset($fsUpdates['Blood Group']))       $fsUpdates['bloodGroup']       = $fsUpdates['Blood Group'];
        if (isset($fsUpdates['Religion']))          $fsUpdates['religion']         = $fsUpdates['Religion'];
        if (isset($fsUpdates['Nationality']))       $fsUpdates['nationality']      = $fsUpdates['Nationality'];
        if (isset($fsUpdates['Roll No']))           $fsUpdates['rollNo']           = $fsUpdates['Roll No'];
        if (isset($fsUpdates['Father Name']))       $fsUpdates['fatherName']       = $fsUpdates['Father Name'];
        if (isset($fsUpdates['Father Occupation'])) $fsUpdates['fatherOccupation'] = $fsUpdates['Father Occupation'];
        if (isset($fsUpdates['Mother Name']))       $fsUpdates['motherName']       = $fsUpdates['Mother Name'];
        if (isset($fsUpdates['Mother Occupation'])) $fsUpdates['motherOccupation'] = $fsUpdates['Mother Occupation'];
        if (isset($fsUpdates['Guard Contact']))     $fsUpdates['guardContact']     = $fsUpdates['Guard Contact'];
        if (isset($fsUpdates['Guard Relation']))    $fsUpdates['guardRelation']    = $fsUpdates['Guard Relation'];
        if (isset($fsUpdates['Pre Class']))         $fsUpdates['preClass']         = $fsUpdates['Pre Class'];
        if (isset($fsUpdates['Pre School']))        $fsUpdates['preSchool']        = $fsUpdates['Pre School'];
        if (isset($fsUpdates['Pre Marks']))         $fsUpdates['preMarks']         = $fsUpdates['Pre Marks'];
        if (isset($fsUpdates['Address']))           $fsUpdates['address']          = $fsUpdates['Address'];

        // Audit diff — captured BEFORE the write so the timeline UI can
        // show the parent / admin exactly what changed. Lazy fetch:
        // skipped if the Address branch already loaded the doc.
        if ($beforeStudent === null) {
            $beforeStudent = $this->_getStudent($userId);
        }
        $auditDiff = [];
        if (is_array($beforeStudent)) {
            foreach ($updates as $auditKey => $newVal) {
                if ($auditKey === 'updatedAt') continue;
                $oldVal = $beforeStudent[$auditKey] ?? null;
                if ($oldVal === $newVal) continue;
                $auditDiff[$auditKey] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        $this->fs->updateEntity('students', $userId, $fsUpdates);

        // ── FIX 4c: Update Firebase Auth displayName if name changed ──
        if (isset($updates['Name'])) {
            try {
                $this->firebase->updateFirebaseUser($userId, ['displayName' => $updates['Name']]);
            } catch (\Exception $e) {
                log_message('error', "update_profile: Firebase Auth update failed for {$userId}: " . $e->getMessage());
            }
        }

        $changed = implode(', ', array_keys($updates));
        // Pass the diff into metadata so the timeline UI can render
        // a clean old → new table per changed field. `fields` is
        // retained for back-compat with the existing collapsible
        // JSON view in history.php.
        $this->_log_history($school_id, $userId, 'PROFILE_UPDATE',
            "Profile updated: {$changed}",
            ['fields' => array_keys($updates), 'changes' => $auditDiff]
        );

        // Entity sync for Android apps
        try {
            // SIS Wave-2 S6 (2026-05-31): observability for sync return.
            if (!$this->entity_sync->syncStudent($userId, $updates)) {
                log_message('warning', "syncStudent returned false for {$userId} (update_profile)");
            }
        } catch (\Exception $e) {
            log_message('error', "entity_sync syncStudent failed for {$userId}: " . $e->getMessage());
        }

        log_audit('SIS', 'update_profile', $userId, "Updated student profile: {$changed}");

        return $this->json_success(['message' => 'Profile updated successfully.']);
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT PROMOTION
    ══════════════════════════════════════════════════════════════════════ */

    public function promote()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_promote', 'SIS', 'manage');
        $session     = $this->session_year;

        $data['class_map']         = $this->_fs_class_map();
        $data['session_class_map'] = $this->_fs_session_class_map();
        $data['session_year']      = $session;

        // Build session options. Issue B (2026-05-28): list ONLY sessions that
        // actually exist. Previously the computed next academic year was
        // silently appended to the list as if it were a real session, which
        // confused operators into promoting into a non-existent (and
        // fee-structure-less) session. The next year is now offered separately
        // as an explicit, clearly-labeled "create new" choice the view renders
        // distinctly; selecting it still auto-registers the session on submit
        // via the existing BUG-056 SW1 path in execute_promotion.
        $available = $this->session->userdata('available_sessions') ?? [];
        rsort($available);
        $parts     = explode('-', $session);
        $nextYear  = ((int)$parts[0] + 1) . '-' . substr((string)((int)$parts[0] + 2), -2);
        $data['session_options'] = $available;                                       // existing only
        $data['create_session']  = in_array($nextYear, $available, true) ? '' : $nextYear; // '' if it already exists
        $data['next_session']    = $nextYear;                                        // label the next academic year "(next)" when it already exists

        $this->load->view('include/header');
        $this->load->view('sis/promote', $data);
        $this->load->view('include/footer');
    }

    public function promote_preview()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_promote_preview', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        $fromClass   = trim($this->input->post('from_class'));   // "9th"
        $fromSection = trim($this->input->post('from_section')); // "A" or "all"

        if (empty($fromClass)) return $this->json_error('Source class is required.');
        if (!$this->safe_path_segment($fromClass)) return $this->json_error('Invalid class value.');
        if ($fromSection && $fromSection !== 'all' && !$this->safe_path_segment($fromSection)) {
            return $this->json_error('Invalid section value.');
        }

        $students = $this->_get_students_in_class($fromClass, $fromSection, $session);

        return $this->json_success([
            'message'      => 'Preview ready.',
            'students'     => array_values($students),
            'count'        => count($students),
            'from_class'   => $fromClass,
            'from_section' => $fromSection,
        ]);
    }

    public function execute_promotion()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_execute_promotion', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        $fromClass   = trim($this->input->post('from_class'));
        $fromSection = trim($this->input->post('from_section'));
        $toClass     = trim($this->input->post('to_class'));
        $toSection   = trim($this->input->post('to_section'));
        $toSession   = trim($this->input->post('to_session') ?? '') ?: $session;

        if (empty($fromClass) || empty($toClass) || empty($toSection)) {
            return $this->json_error('Source class, destination class, and section are required.');
        }
        if (!$this->safe_path_segment($fromClass)) return $this->json_error('Invalid source class.');
        if ($fromSection && $fromSection !== 'all' && !$this->safe_path_segment($fromSection)) {
            return $this->json_error('Invalid source section.');
        }
        if (!$this->safe_path_segment($toClass))   return $this->json_error('Invalid destination class.');
        if (!$this->safe_path_segment($toSection))  return $this->json_error('Invalid destination section.');

        // SC-Step9 Part A (Session Convergence — 2026-06-02): fail-loud
        // validation. Pre-Step-9 silently fell back to $session on malformed
        // to_session, masking input bugs. Per NEW-Q12 fail-closed mandate,
        // reject malformed input with HTTP 400 so callers can correct it.
        // The empty-string default to current session (L870) still applies
        // BEFORE this check — UI sending empty to_session for same-session
        // promotion is unaffected.
        if (!preg_match('/^\d{4}-\d{2}$/', $toSession)) {
            return $this->json_error(
                "Invalid target session format. Expected YYYY-YY (e.g. 2026-27), got: "
                . substr($toSession, 0, 32),
                400
            );
        }

        // SC-Step9 Part B (Session Convergence — 2026-06-02): route
        // session-add through canonical Session_lifecycle library per
        // operator-locked EX-C(b) decision. The library applies the SAME
        // lock + Firestore __updateTime CAS that School_config's canonical
        // lifecycle writers use, with cross-controller serialization
        // preserved via the shared lock-file path. Pre-Step-9 inline
        // fs->update at L902 was the LAST unhardened session writer in
        // the codebase; Step 9 closes it.
        //
        // BUG-056-SW1 invariant PRESERVED: promotion writes ONLY sessions[],
        // never currentSession. Active-session changes route exclusively
        // through School_config::set_active_session. The library's
        // add_session() method has no path to currentSession.
        $available = $this->session->userdata('available_sessions') ?? [];
        if (!in_array($toSession, $available, true)) {
            $this->load->library('Session_lifecycle', null, 'session_lifecycle');
            $libResult = $this->session_lifecycle->add_session(
                $this->school_id,
                $toSession,
                (string) $this->admin_id,
                'promotion'
            );
            if (!$libResult['success']) {
                log_message('error',
                    'SC-Step9: Session_lifecycle::add_session failed for promotion '
                    . 'target=[' . $toSession . '] school=[' . $this->school_id
                    . '] error=[' . ($libResult['error'] ?? 'unknown')
                    . '] — aborting promotion to prevent orphan state.');
                return $this->json_error(
                    'Could not register the target session in school configuration. '
                    . 'Promotion has been aborted to prevent partial state. Please retry.');
            }
            // Refresh PHP userdata cache from the post-write canonical sessions[]
            // returned by the library (includes the new session, sorted).
            $this->session->set_userdata('available_sessions', $libResult['sessions']);
        }

        $students = $this->_get_students_in_class($fromClass, $fromSection, $session);
        if (empty($students)) {
            return $this->json_error('No students found in the selected class/section.');
        }

        // ── PM-SELECT 2026-05-26: per-student selection filter ──
        $rawSelectedIds = $this->input->post('student_ids');
        if (is_array($rawSelectedIds) && !empty($rawSelectedIds)) {
            $cleanIds = [];
            foreach ($rawSelectedIds as $rid) {
                $rid = trim((string) $rid);
                if ($rid === '') continue;
                if (!preg_match('/^[A-Za-z0-9_]+$/', $rid)) continue;
                $cleanIds[$rid] = true;
            }
            if (!empty($cleanIds)) {
                $students = array_intersect_key($students, $cleanIds);
            }
            if (empty($students)) {
                return $this->json_error(
                    'None of the selected students were found in the source class. '
                    . 'Refresh the preview and try again.'
                );
            }
        }

        // Check target section capacity before promotion (uses post-filter count)
        $newClassKey   = Firestore_service::classKey($toClass);
        $newSectionKey = Firestore_service::sectionKey($toSection);
        $targetSectionDoc = $this->fs->get('sections', $this->fs->sectionDocId($toClass, $toSection));
        $maxStrength = (int) ($targetSectionDoc['maxStrength'] ?? $targetSectionDoc['max_strength'] ?? 0);
        if ($maxStrength > 0) {
            $existingStudents = $this->fs->schoolWhere('students', [
                ['className', '==', $newClassKey], ['section', '==', $newSectionKey], ['status', '==', 'Active'],
            ]);
            $currentCount = count($existingStudents);
            $promotionCount = count($students);
            if (($currentCount + $promotionCount) > (int) $maxStrength) {
                return $this->json_error(
                    "Target section {$newClassKey}/{$newSectionKey} capacity exceeded ({$currentCount}/{$maxStrength}). Cannot promote {$promotionCount} student(s)."
                );
            }
        }

        // BUG-076 Part 2-A (2026-05-28): destination fee-structure guard.
        // A promotion regenerates the destination class's demands via
        // assignInitialFees, which reads
        // feeStructures/{schoolId}_{toSession}_{class}_{section}. If that
        // structure is absent, regeneration silently produces nothing and the
        // promoted students land Active-with-zero-demands (the STU0004-11
        // incident root cause). Validate BEFORE moving any student so the
        // promotion is all-or-nothing. Operator decision 2026-05-28: BLOCK if
        // missing. ($school_name == school_id == SCH_xxx; classKey/sectionKey
        // are already applied to $newClassKey/$newSectionKey — identical to
        // the key assignInitialFees will read.)
        $destFeeStructDocId = "{$school_name}_{$toSession}_{$newClassKey}_{$newSectionKey}";
        $destFeeStruct      = $this->fs->get('feeStructures', $destFeeStructDocId);
        if (!is_array($destFeeStruct) || empty($destFeeStruct['feeHeads'])) {
            return $this->json_error(
                "No fee structure exists for {$newClassKey} / {$newSectionKey} in session "
                . "{$toSession}. Promotion aborted before moving any student, to prevent "
                . "students being enrolled without fee demands. Set up the fee structure for "
                . "this class/section in session {$toSession} first, then retry."
            );
        }

        $adminName     = $this->session->userdata('admin_name') ?? 'Admin';
        $promoted      = [];
        $now           = date('Y-m-d H:i:s');
        $batchId       = date('YmdHis');
        $oldClassKey   = Firestore_service::classKey($fromClass);
        $oldSectionKey = Firestore_service::sectionKey($fromSection);
        $historyDesc   = "Promoted from {$oldClassKey}/{$oldSectionKey} to {$newClassKey}/{$newSectionKey} ({$toSession})";
        $historyMeta   = [
            'from_class' => $oldClassKey, 'from_section' => $oldSectionKey,
            'to_class' => $newClassKey, 'to_section' => $newSectionKey, 'to_session' => $toSession,
        ];

        // Build batch map: [ userId => ['name'=>..., 'oldSection'=>...] ]
        $batchMap = [];
        foreach ($students as $userId => $studentInfo) {
            $stuOldSection = ($fromSection === 'all')
                ? Firestore_service::sectionKey($studentInfo['section'] ?? '')
                : $oldSectionKey;
            $batchMap[$userId] = [
                'name'       => $studentInfo['name'] ?? $userId,
                'oldSection' => $stuOldSection,
            ];
        }

        // Firestore-only bulk promotion (RTDB retired) — per-student syncStudent
        // with inline retry + async retry-queue; returns moved[]/failed[].
        $moveResult = $this->dw->batchMoveStudents(
            $batchMap, $oldClassKey, $session,
            $newClassKey, $newSectionKey, $toSession
        );

        // Log history for moved students
        foreach ($moveResult['moved'] as $userId) {
            $name = $batchMap[$userId]['name'] ?? $userId;
            $this->_log_history($school_id, $userId, 'PROMOTION', $historyDesc, $historyMeta);
            $promoted[] = ['user_id' => $userId, 'name' => $name];
        }

        $skipped = [];
        foreach ($moveResult['failed'] as $userId) {
            $skipped[] = ['user_id' => $userId, 'reason' => 'RTDB atomic write failed'];
        }

        // G2 — Refresh section strength on every (old, new) section
        // touched by the batch. Old sections drop students; the new
        // section gains them. Dedup via assoc-key set so we don't
        // recompute the same section twice when many students share
        // an oldSection.
        $touchedSections = [];
        $touchedSections["{$newClassKey}|{$newSectionKey}"] = [$newClassKey, $newSectionKey];
        foreach ($batchMap as $bmInfo) {
            $oldSec = $bmInfo['oldSection'] ?? '';
            if ($oldSec === '') continue;
            $touchedSections["{$oldClassKey}|{$oldSec}"] = [$oldClassKey, $oldSec];
        }
        // BUG-045 Item 1 (perf, 2026-05-29): the synchronous section-strength
        // recompute that used to run here was REMOVED. It duplicated the
        // recompute already performed in the post-response shutdown handler
        // (Phase D2 below), wasting ~6 Firestore round-trips on the operator-
        // facing critical path for zero correctness benefit — currentStrength
        // is informational (admission section-picker bars) and self-healing on
        // the next lifecycle write.
        // INVARIANT: the deferred Phase-D2 loop is now the SOLE section-strength
        // recompute path. $touchedSections is captured into the shutdown closure
        // ($__touchedLocal) and consumed there — do not re-add a synchronous
        // recompute here.

        // ── BUG-045 Phase 1 B1 (2026-05-25): post-response shutdown handler ──
        // Pre-fix: fee-reassignment + section-strength recompute + promotion-batch
        // save ran SYNCHRONOUSLY post-batchMoveStudents. For 60 students this
        // exceeded 120s PHP ceiling (4.7×). Now: write a promote_jobs/{batchId}
        // status doc, flush response to operator immediately, then defer the
        // heavy work to a shutdown closure that runs after Apache releases the
        // client socket. Pattern source: FeeCollectionService.php:1465-1648.

        // Out-of-band visibility: persist initial job status BEFORE response
        // so operator (or a future status-poll endpoint) can observe progress.
        $promoteJobDocId = "{$this->school_name}_{$batchId}";
        try {
            $this->firebase->firestoreSet('promote_jobs', $promoteJobDocId, [
                'schoolId'        => $this->school_name,
                'session'         => $session,
                'batchId'         => $batchId,
                'sessionFrom'     => $session,
                'sessionTo'       => $toSession,
                'fromClass'       => $oldClassKey,
                'fromSection'     => $oldSectionKey,
                'toClass'         => $newClassKey,
                'toSection'       => $newSectionKey,
                'expectedCount'   => count($promoted),
                'processedCount'  => 0,
                'failedStudents'  => [],
                'status'          => 'deferred-fees-pending',
                'startedAt'       => $now,
                'promotedBy'      => $adminName,
            ]);
        } catch (\Exception $e) {
            // Fail-safe: if status doc write fails, log and continue.
            // The shutdown handler still runs; only out-of-band visibility is lost.
            log_message('warning', "promote: promote_jobs status doc write failed for {$batchId}: " . $e->getMessage());
        }

        // Build response payload BEFORE flushing.
        $__responseJson = json_encode([
            'status'         => 'success',
            'message'        => count($promoted) . ' student(s) promoted successfully.',
            'promoted'       => $promoted,
            'skipped'        => $skipped,
            'batch_id'       => $batchId,
            'job_status_doc' => $promoteJobDocId,
            'csrf_token'     => $this->security->get_csrf_hash(),
        ]);

        // Apache mod_php early-response flush (mirror FCS:1481-1494).
        // XAMPP has no fastcgi_finish_request, so combine Connection: close +
        // Content-Length + ob_end_flush() + flush() to release the socket.
        @set_time_limit(0);
        @ignore_user_abort(true);
        if (!headers_sent()) {
            @header('Connection: close');
            @header('Content-Type: application/json');
            @header('Content-Length: ' . strlen($__responseJson));
            @header('Content-Encoding: none'); // disable mod_deflate so length is honest
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        echo $__responseJson;
        @flush();
        $this->output->set_output('');
        // From here on, every line runs after the operator's browser has
        // received the success banner. Errors below are background-only.

        // Capture by-value into the closure — no $this references that might
        // be torn down at shutdown time. Same hygiene pattern as FCS:1517-1521.
        $__feeLifecycle    = $this->feeLifecycle;
        $__firebase        = $this->firebase;
        $__fs              = $this->fs;
        $__schoolName      = $this->school_name;
        $__schoolId        = $this->school_id;
        $__oldClassKey     = $oldClassKey;
        $__oldSectionKey   = $oldSectionKey;
        $__newClassKey     = $newClassKey;
        $__newSectionKey   = $newSectionKey;
        $__sessionLocal    = $session;
        $__toSessionLocal  = $toSession;
        $__nowLocal        = $now;
        $__adminNameLocal  = $adminName;
        $__batchIdLocal    = $batchId;
        $__promotedLocal   = $promoted;
        $__touchedLocal    = $touchedSections;
        $__jobDocId        = $promoteJobDocId;

        register_shutdown_function(function () use (
            $__feeLifecycle, $__firebase, $__fs,
            $__schoolName, $__schoolId,
            $__oldClassKey, $__oldSectionKey, $__newClassKey, $__newSectionKey,
            $__sessionLocal, $__toSessionLocal, $__nowLocal, $__adminNameLocal,
            $__batchIdLocal, $__promotedLocal, $__touchedLocal, $__jobDocId
        ) {
            $deferFailedStudents = [];
            $processedCount      = 0;

            // BUG-076 Part 2-B (2026-05-28): session-threading. Demands for the
            // destination class must be created in the session students are
            // promoted INTO (toSession), not the admin's controller-boot
            // session. Re-point the shared fsTxn + Fee_lifecycle to toSession
            // before regeneration. The archive loop inside reassignFeesOnPromotion
            // uses _demandsForAllSessions (session-agnostic), so only the
            // regeneration target is affected; the boot session is restored
            // after the loop so Phase D2/D3 (which use $__fs) are unaffected.
            // Operator decision 2026-05-28: demands live in destination session.
            $__sessionThreaded = ($__toSessionLocal !== '' && $__toSessionLocal !== $__sessionLocal);
            if ($__sessionThreaded) {
                try {
                    $CI =& get_instance();
                    if (isset($CI->fsTxn) && is_object($CI->fsTxn)) {
                        $CI->fsTxn->init($__firebase, $__fs, $__schoolName, $__toSessionLocal);
                    }
                    $__feeLifecycle->init($__firebase, $__schoolName, $__toSessionLocal, $__adminNameLocal);
                } catch (\Throwable $e) {
                    log_message('error', "promote(deferred): session-thread re-point to {$__toSessionLocal} failed: " . $e->getMessage());
                    $__sessionThreaded = false;
                }
            }

            // Phase D1 — reassign fees per promoted student (the ~94% cost).
            foreach ($__promotedLocal as $p) {
                try {
                    // SIS Tier-1 fix B5 (2026-05-31): capture the return so we
                    // can detect the silent-zero-regenerated case. The upfront
                    // guard at L967 blocks promotion when the destination
                    // structure is missing AT REQUEST TIME, but this deferred
                    // loop runs later in a shutdown handler — a structure
                    // deleted between the guard and this call (admin race,
                    // Firestore transient, mid-loop deletion) would leave
                    // affected students Active-with-zero-demands while the
                    // promotion reported clean success. We now surface them
                    // in $deferFailedStudents like a thrown failure would.
                    $__regenResult = $__feeLifecycle->reassignFeesOnPromotion(
                        $p['user_id'], $__oldClassKey, $__oldSectionKey,
                        $__newClassKey, $__newSectionKey, $__schoolId
                    );
                    $__regenCount = is_array($__regenResult) ? (int) ($__regenResult['regenerated'] ?? 0) : 0;
                    if ($__regenCount === 0) {
                        $deferFailedStudents[] = [
                            'user_id' => $p['user_id'],
                            'name'    => $p['name'] ?? $p['user_id'],
                            'reason'  => "Zero demands regenerated for {$__newClassKey}/{$__newSectionKey} in session {$__toSessionLocal}. Destination fee structure may have been deleted after the upfront guard ran. Verify the structure exists and re-promote this student.",
                        ];
                    } else {
                        $processedCount++;

                        // SIS Wave-4 fix S1 (2026-05-31): propagate the new
                        // class/section to parent + teacher apps via entity_sync.
                        // Pre-fix, promoted students remained on their OLD
                        // class in the parents collection until next admin
                        // edit triggered a re-sync — parent app showed stale
                        // attendance/marks/fees for the old class; teacher
                        // app's new-class roster filter excluded them.
                        //
                        // Sync failures are LOG-AND-CONTINUE per operator
                        // direction (promotion + fee-regen are the PRIMARY
                        // transactional outcomes; app sync is post-write
                        // observability). We deliberately do NOT add
                        // sync-only failures to $deferFailedStudents — that
                        // queue is reserved for fee-regen failures.
                        //
                        // S7 (commit d19e9e0a) made syncParent safe under
                        // partial payloads — its $pick helper preserves
                        // identity fields when the payload omits them.
                        // Safe to pass a narrow 5-field update here.
                        //
                        // entity_sync is not in the closure's `use` list;
                        // we re-acquire it via get_instance() like the
                        // L1161 fsTxn re-init pattern (BUG-076 Part 2-B).
                        try {
                            $CI =& get_instance();
                            $entitySync = ($CI !== null && isset($CI->entity_sync)) ? $CI->entity_sync : null;
                            if ($entitySync !== null) {
                                $syncPayload = [
                                    'Name'    => $p['name'] ?? $p['user_id'],
                                    'Class'   => $__newClassKey,
                                    'Section' => $__newSectionKey,
                                    'Status'  => 'Active',
                                    'session' => $__toSessionLocal,
                                ];
                                if (!$entitySync->syncStudent($p['user_id'], $syncPayload)) {
                                    log_message('warning', "promote(deferred): syncStudent returned false for {$p['user_id']}");
                                }
                                if (!$entitySync->syncParent($p['user_id'], $syncPayload)) {
                                    log_message('warning', "promote(deferred): syncParent returned false for {$p['user_id']}");
                                }
                            } else {
                                log_message('warning', "promote(deferred): entity_sync library not accessible in shutdown context for {$p['user_id']} — app sync skipped (fee regen succeeded)");
                            }
                        } catch (\Throwable $eSync) {
                            log_message('error', "promote(deferred): entity_sync threw for {$p['user_id']}: " . $eSync->getMessage());
                        }
                    }
                } catch (\Throwable $e) {
                    log_message('error', "promote(deferred): reassignFeesOnPromotion failed for {$p['user_id']}: " . $e->getMessage());
                    $deferFailedStudents[] = [
                        'user_id' => $p['user_id'],
                        'name'    => $p['name'] ?? $p['user_id'],
                        'reason'  => $e->getMessage(),
                    ];
                }
            }

            // BUG-076 Part 2-B: restore the boot session on the shared fsTxn +
            // Fee_lifecycle so subsequent Phase D2/D3 work (and any later
            // shutdown code) operates against the original session.
            if ($__sessionThreaded) {
                try {
                    $CI =& get_instance();
                    if (isset($CI->fsTxn) && is_object($CI->fsTxn)) {
                        $CI->fsTxn->init($__firebase, $__fs, $__schoolName, $__sessionLocal);
                    }
                    $__feeLifecycle->init($__firebase, $__schoolName, $__sessionLocal, $__adminNameLocal);
                } catch (\Throwable $_) {}
            }

            // Phase D2 — section strength recompute. Outside the foreach
            // because it's O(touched-sections), not O(students).
            // Note: requires controller context; rebuild a minimal Sis instance
            // is too heavy. Instead inline the schoolWhere + fs->set pattern
            // here (mirror of _recompute_section_strength at Sis.php:2329-2353).
            foreach ($__touchedLocal as $pair) {
                try {
                    list($cKey, $sKey) = $pair;
                    $rows = $__fs->schoolWhere('students', [
                        ['className', '==', $cKey],
                        ['section',   '==', $sKey],
                        ['status',    '==', 'Active'],
                    ]);
                    $strength = is_array($rows) ? count($rows) : 0;
                    $secDocId = $__fs->sectionDocId($cKey, $sKey);
                    // BUG (2026-05-28): only update strength on an EXISTING section.
                    // set(merge=true) on a missing section created a field-less
                    // "ghost" doc (currentStrength/updatedAt only — no schoolId/
                    // className/section/session), which then blocked section
                    // creation ("already exists" by docId) while being invisible
                    // to the className+session list query. Strength recompute must
                    // never materialize a section.
                    if (is_array($__fs->get('sections', $secDocId))) {
                        $__fs->set('sections', $secDocId, [
                            'currentStrength' => $strength,
                            'updatedAt'       => date('c'),
                        ], true);
                    }
                } catch (\Throwable $e) {
                    log_message('warning', "promote(deferred): _recompute_section_strength failed for {$pair[0]}/{$pair[1]}: " . $e->getMessage());
                }
            }

            // Phase D3 — schools.promotions batch record.
            try {
                $schoolDoc = $__fs->get('schools', $__schoolId);
                $promotions = (is_array($schoolDoc) && isset($schoolDoc['promotions']) && is_array($schoolDoc['promotions']))
                              ? $schoolDoc['promotions'] : [];
                $promotions[$__batchIdLocal] = [
                    'session_from' => $__sessionLocal, 'session_to' => $__toSessionLocal,
                    'promoted_at' => $__nowLocal, 'promoted_by' => $__adminNameLocal,
                    'from_class' => $__oldClassKey, 'from_section' => $__oldSectionKey,
                    'to_class' => $__newClassKey, 'to_section' => $__newSectionKey,
                    'count' => count($__promotedLocal),
                ];
                $__fs->update('schools', $__schoolId, ['promotions' => $promotions]);
            } catch (\Throwable $e) {
                log_message('warning', "promote(deferred): schools.promotions update failed for {$__batchIdLocal}: " . $e->getMessage());
            }

            // Phase D4 — final promote_jobs status update for out-of-band observability.
            try {
                $finalStatus = empty($deferFailedStudents) ? 'completed' : 'completed_with_errors';
                $__firebase->firestoreSet('promote_jobs', $__jobDocId, [
                    'status'         => $finalStatus,
                    'processedCount' => $processedCount,
                    'failedStudents' => $deferFailedStudents,
                    'completedAt'    => date('c'),
                ], true);
            } catch (\Throwable $e) {
                log_message('error', "promote(deferred): final promote_jobs status write failed for {$__batchIdLocal}: " . $e->getMessage());
            }
        });

        return; // response already flushed; CI's _display() is silenced via set_output('').
    }

    /* ══════════════════════════════════════════════════════════════════════
       TRANSFER CERTIFICATES
    ══════════════════════════════════════════════════════════════════════ */

    public function tc_list()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_tc_list', 'SIS', 'view');
        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        // Read TC index from school doc
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $tcIndex = $schoolDoc['tcIndex'] ?? [];
        if (!is_array($tcIndex)) $tcIndex = [];

        $tcRecords = [];
        if (!empty($tcIndex)) {
            foreach ($tcIndex as $tcKey => $tc) {
                if (!is_array($tc)) continue;
                $tcRecords[] = [
                    'user_id'    => $tc['user_id']     ?? '',
                    'name'       => $tc['student_name'] ?? $tc['name'] ?? '',
                    'class'      => $tc['class']        ?? '',
                    'section'    => $tc['section']      ?? '',
                    'tc_key'     => $tc['tc_key']       ?? $tcKey,
                    'tc_no'      => $tc['tc_no']        ?? '',
                    'issued_date'=> $tc['issued_date']  ?? '',
                    'issued_by'  => $tc['issued_by']    ?? '',
                    'destination'=> $tc['destination']  ?? '',
                    'status'     => $tc['status']       ?? '',
                ];
            }
        } else {
            // Fallback: scan students with TC status
            $tcStudents = $this->fs->schoolWhere('students', [['status', '==', 'TC']]);
            if (empty($tcStudents)) {
                $tcStudents = $this->fs->schoolWhere('students', [['Status', '==', 'TC']]);
            }

            foreach ($tcStudents as $doc) {
                $d = $doc['data'] ?? $doc;
                $student = $this->_normalizeStudentDoc($doc['data']);
                if (!is_array($student)) continue;
                $uid = $student['User Id'] ?? $student['studentId'] ?? $d['id'];
                $tcs = $student['TC'] ?? [];
                if (!is_array($tcs)) continue;
                foreach ($tcs as $tcKey => $tc) {
                    if (!is_array($tc)) continue;
                    $tcRecords[] = [
                        'user_id'    => $uid,
                        'name'       => $student['Name']    ?? $uid,
                        'class'      => $student['Class']   ?? '',
                        'section'    => $student['Section'] ?? '',
                        'tc_key'     => $tcKey,
                        'tc_no'      => $tc['tc_no']        ?? '',
                        'issued_date'=> $tc['issued_date']  ?? '',
                        'issued_by'  => $tc['issued_by']    ?? '',
                        'destination'=> $tc['destination']  ?? '',
                        'status'     => $tc['status']       ?? '',
                    ];
                }
            }
        }

        // Sort by issued_date desc
        usort($tcRecords, fn($a, $b) =>
            strcmp($b['issued_date'] ?? '', $a['issued_date'] ?? '')
        );

        // Fix 5: Server-side pagination (50 per page)
        $perPage    = 50;
        $page       = max(1, (int)($this->input->get('page') ?? 1));
        $total      = count($tcRecords);
        $totalPages = (int)ceil($total / $perPage);
        $offset     = ($page - 1) * $perPage;
        $pagedTcs   = array_slice($tcRecords, $offset, $perPage);

        $data['tc_records']   = $pagedTcs;
        $data['tc_total']     = $total;
        $data['tc_page']      = $page;
        $data['tc_per_page']  = $perPage;
        $data['tc_pages']     = $totalPages;
        $data['session_year'] = $session;

        $this->load->view('include/header');
        $this->load->view('sis/tc_list', $data);
        $this->load->view('include/footer');
    }

    public function issue_tc()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_issue_tc', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;

        $userId      = trim($this->input->post('user_id')      ?? '');
        $reason      = trim($this->input->post('reason')      ?? '') ?: 'Transfer';
        $destination = trim($this->input->post('destination') ?? '');

        if (empty($userId)) return $this->json_error('Student ID required.');
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');

        $student = $this->_getStudent($userId);
        if (empty($student)) return $this->json_error('Student not found.');

        // Check outstanding fees — block TC if dues remain (unless force_override is set)
        $forceOverride = $this->input->post('force_override') === 'true';
        if (!$forceOverride) {
            $dues = $this->_check_outstanding_dues($userId, (array)$student);
            if ($dues['has_dues']) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode([
                    'status'  => 'error',
                    'message' => $dues['summary'] . '. Clear all dues before issuing a Transfer Certificate.',
                    'dues'    => $dues,
                    'can_override' => true,
                ]);
                return;
            }
        }

        // Check not already TC issued
        $existing = $student['TC'] ?? [];
        if (is_array($existing)) {
            foreach ($existing as $tc) {
                if (is_array($tc) && ($tc['status'] ?? '') === 'active') {
                    return $this->json_error('An active TC is already issued for this student.');
                }
            }
        }

        $tcNo      = $this->_get_tc_number($school_name);
        // SIS Wave-3 fix F4 (2026-05-31): _get_tc_number now returns '' when
        // the atomic claim is unavailable (previously it silently fell back
        // to stale-mirror math, risking duplicate TC numbers). Surface the
        // failure to the operator instead of writing a TC with empty number.
        if ($tcNo === '') {
            return $this->json_error('Could not allocate a TC number atomically (Firestore counter unavailable). Please retry in a moment. If the issue persists, contact support.');
        }
        $adminName = $this->session->userdata('admin_name') ?? 'Admin';
        $tcKey     = 'TC_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $tcData    = [
            'tc_no'       => $tcNo,
            'issued_date' => date('Y-m-d'),
            'issued_by'   => $adminName,
            'reason'      => $reason,
            'destination' => $destination,
            'status'      => 'active',
            'student_name'=> $student['Name'] ?? $userId,
            'class'       => $student['Class'] ?? '',
            'section'     => $student['Section'] ?? '',
            'user_id'     => $userId,
            'tc_key'      => $tcKey,
        ];

        // Update Firestore student doc
        $tcHistory = $student['TC'] ?? [];
        $tcHistory[$tcKey] = $tcData;
        $this->fs->updateEntity('students', $userId, [
            'status'    => 'TC',
            'TC'        => $tcHistory,
            'updatedAt' => date('c'),
        ]);

        // Store TC in school's tcIndex for fast listing
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $tcIndex = $schoolDoc['tcIndex'] ?? [];
        $tcIndex[$tcKey] = $tcData;
        $this->fs->update('schools', $this->school_id, ['tcIndex' => $tcIndex]);

        // ── FIX 2a: Remove from RTDB roster ──────────────────────────
        $stuClass   = Firestore_service::classKey($student['Class'] ?? $student['className'] ?? '');
        $stuSection = Firestore_service::sectionKey($student['Section'] ?? $student['section'] ?? '');
        if ($stuClass && $stuSection) {
            $this->dw->removeFromRoster($stuClass, $stuSection, $userId);
        }

        // Firestore student doc already carries status=TC (see fs->updateEntity
        // above). No RTDB mirror — Firestore is the source of truth.

        // ── Disable Firebase Auth (prevent login) ────────────────────
        try {
            $this->firebase->updateFirebaseUser($userId, ['disabled' => true]);
        } catch (\Exception $e) {
            log_message('error', "TC: disableFirebaseUser failed for {$userId}: " . $e->getMessage());
        }

        // Entity sync for Android apps
        try {
            // SIS Wave-2 S6 (2026-05-31): observability for sync return.
            if (!$this->entity_sync->syncStudent($userId, [
                'Name'    => $student['Name'] ?? $userId,
                'Class'   => $stuClass,
                'Section' => $stuSection,
                'Status'  => 'TC',
            ])) {
                log_message('warning', "syncStudent returned false for {$userId} (issue_tc)");
            }
            // S2 (2026-05-31) + S6 (2026-05-31): propagate Status=TC to
            // parent doc with array_merge to preserve identity (S7 anti-
            // pattern guard), and check the return for visibility.
            if (!$this->entity_sync->syncParent($userId, array_merge($student, [
                'Status' => 'TC', 'status' => 'TC',
            ]))) {
                log_message('warning', "syncParent returned false for {$userId} (issue_tc)");
            }
        } catch (\Exception $e) {
            log_message('error', "entity_sync sync TC failed for {$userId}: " . $e->getMessage());
        }

        // G1 — Blank current-month attendance from today onwards so the
        // student's % doesn't drift after TC issue.
        $this->_blank_summary_from_today($userId);

        // G2 — Section strength drops by one (student leaves the roster).
        $this->_recompute_section_strength($stuClass, $stuSection);

        $this->_log_history($school_id, $userId, 'TC_ISSUED',
            "Transfer Certificate issued (TC#{$tcNo}) — Reason: {$reason}",
            ['tc_no' => $tcNo, 'destination' => $destination]
        );

        return $this->json_success([
            'message' => "Transfer Certificate {$tcNo} issued.",
            'tc_no'   => $tcNo,
            'tc_key'  => $tcKey,
            'user_id' => $userId,
        ]);
    }

    public function print_tc($userId = null, $tcKey = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_print_tc', 'SIS', 'view');
        if (empty($userId)) show_404();
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $userId)) show_404();
        if (!empty($tcKey) && !preg_match('/^[A-Za-z0-9_\-]+$/', $tcKey)) show_404();

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;

        // Dues-based blocking — applied per the school's policy doc
        // `feeSettings/{school}_{session}_blocking_policy.block_tc`.
        // Admins can bypass with ?force_override=1 if the policy
        // permits it. Runs BEFORE student fetch so we don't leak an
        // unauthorised preview.
        try {
            // Phase TC-2 (2026-05-09) — canonical-reader alignment.
            // Was: Fee_dues_check::check (denormalized feeDefaulters;
            //   fees-only). Stale defaulter docs (e.g. STU0004/STU0005
            //   baseline pre-Phase-3D) could leak through this gate.
            // Now: Fee_dues_check for POLICY only (block_tc, threshold,
            //   admin_override_allowed); Fee_defaulter_check for canonical
            //   cross-module dues calculation (feeDemands + library +
            //   hostel + transport). Brings print_tc to parity with
            //   issue_tc + withdraw_student, which already use this path
            //   via _check_outstanding_dues().
            //
            // Side effect: calculateClearanceStatus refreshes the
            // studentClearance/{schoolId}_{studentId} doc (idempotent
            // merge, fail-soft). This same write already occurs on
            // every issue_tc + withdraw_student call today. No new write
            // class is introduced — only a new write-trigger location.

            // Step 1 — policy lookup (decides whether to block at all).
            $this->load->library('Fee_dues_check', null, 'duesCheck');
            $this->duesCheck->init($this->firebase, $this->school_name, $this->session_year);
            $policy = $this->duesCheck->getPolicy();

            // Step 2 — only run dues check if school policy says block_tc.
            if (!empty($policy['block_tc'])) {
                $override = (bool) $this->input->get('force_override');
                // Policy can disallow override.
                if (empty($policy['admin_override_allowed'])) $override = false;

                // Step 3 — canonical clearance via Fee_defaulter_check.
                $this->load->library('Fee_defaulter_check', null, 'feeDefaulter');
                $this->feeDefaulter->init($this->firebase, $this->school_name, $this->session_year);
                $clearance = $this->feeDefaulter->calculateClearanceStatus($userId);

                $threshold = (float) ($policy['threshold_amount'] ?? 0);
                $totalDues = (float) ($clearance['total_dues'] ?? 0);
                $blocked   = !$override && ($totalDues > $threshold);

                if ($blocked) {
                    // Compose summary across modules for operator clarity.
                    $parts = [];
                    if (!($clearance['fees_clear']      ?? true)) $parts[] = 'Fees Rs ' . number_format((float)($clearance['fees_dues']      ?? 0), 2);
                    if (!($clearance['library_clear']   ?? true)) {
                        $libPart = 'Library Rs ' . number_format((float)($clearance['library_dues'] ?? 0), 2);
                        if ((int)($clearance['library_unreturned_books'] ?? 0) > 0) $libPart .= ' + ' . (int)$clearance['library_unreturned_books'] . ' book(s)';
                        $parts[] = $libPart;
                    }
                    if (!($clearance['hostel_clear']    ?? true)) $parts[] = 'Hostel Rs ' . number_format((float)($clearance['hostel_dues']    ?? 0), 2);
                    if (!($clearance['transport_clear'] ?? true)) $parts[] = 'Transport Rs ' . number_format((float)($clearance['transport_dues'] ?? 0), 2);
                    $message = !empty($parts)
                        ? 'Outstanding dues — ' . implode('; ', $parts) . ' (Total Rs ' . number_format($totalDues, 2) . ').'
                        : 'Outstanding dues found (Rs ' . number_format($totalDues, 2) . ').';

                    // Keep the HTML error (this is a printable page, not JSON).
                    $this->output->set_status_header(403);
                    echo '<!DOCTYPE html><html><head><title>TC Withheld</title><style>body{font:15px/1.5 system-ui;padding:60px;color:#334155;text-align:center;}h1{color:#dc2626;}a{color:#BC5A3C;}</style></head><body>'
                       . '<h1>Transfer Certificate Withheld</h1>'
                       . '<p>' . htmlspecialchars($message) . '</p>'
                       . '<p><a href="' . base_url('sis/tc_list') . '">← Back to TC list</a></p>'
                       . '</body></html>';
                    return;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'print_tc: dues check failed: ' . $e->getMessage());
        }

        // Fetch student profile from Firestore
        $student = $this->_getStudent($userId);
        if (empty($student)) {
            log_message('error', "print_tc: student not found — userId={$userId}");
            show_404();
        }

        $tc = null;
        if ($tcKey && isset($student['TC'][$tcKey])) {
            $tc = $student['TC'][$tcKey];
        }
        // Fallback: get the active TC
        if (empty($tc) && is_array($student['TC'] ?? null)) {
            foreach ($student['TC'] as $k => $t) {
                if (is_array($t) && ($t['status'] ?? '') === 'active') {
                    $tc = $t;
                    break;
                }
            }
        }

        if (empty($tc)) {
            log_message('error', "print_tc: TC not found — userId={$userId} tcKey={$tcKey}");
            show_404();
        }

        // School profile for header
        $schoolProfile = $this->fs->get('schools', $this->school_id) ?? [];

        $data['student']       = $student;
        $data['tc']            = $tc;
        $data['school_profile']= $schoolProfile;
        $data['school_name']   = $school_name;

        // Standalone print view (no header/footer chrome)
        $this->load->view('sis/tc_print', $data);
    }

    public function cancel_tc()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_cancel_tc', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $userId      = trim($this->input->post('user_id'));
        $tcKey       = trim($this->input->post('tc_key'));

        if (empty($userId) || empty($tcKey)) {
            return $this->json_error('User ID and TC key required.');
        }
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');
        if (!$this->safe_path_segment($tcKey))  return $this->json_error('Invalid TC key.');

        // Cancel TC in student doc
        $student = $this->_getStudent($userId);
        $tcHistory = $student['TC'] ?? [];
        if (isset($tcHistory[$tcKey])) {
            $tcHistory[$tcKey]['status'] = 'cancelled';
            $tcHistory[$tcKey]['cancelled_at'] = date('Y-m-d H:i:s');
        }
        $this->fs->updateEntity('students', $userId, [
            'status' => 'Active',
            'TC' => $tcHistory, 'updatedAt' => date('c'),
        ]);

        // Update school's tcIndex
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $tcIdx = $schoolDoc['tcIndex'] ?? [];
        if (isset($tcIdx[$tcKey])) {
            $tcIdx[$tcKey]['status'] = 'cancelled';
            $tcIdx[$tcKey]['cancelled_at'] = date('Y-m-d H:i:s');
            $this->fs->update('schools', $this->school_id, ['tcIndex' => $tcIdx]);
        }

        // Re-add student to RTDB roster
        $stuClass   = Firestore_service::classKey($student['Class'] ?? $student['className'] ?? '');
        $stuSection = Firestore_service::sectionKey($student['Section'] ?? $student['section'] ?? '');
        $stuName    = $student['Name'] ?? $student['name'] ?? $userId;
        if ($stuClass && $stuSection) {
            $this->dw->addToRoster($stuClass, $stuSection, $userId, $stuName);
        }

        // Firestore student doc already carries status=Active (see updateEntity
        // above). No RTDB mirror — Firestore is the source of truth.

        // Re-enable Firebase Auth
        try { $this->firebase->updateFirebaseUser($userId, ['disabled' => false]); } catch (\Exception $e) {}

        // Entity sync for Android apps
        try {
            // SIS Wave-2 S6 (2026-05-31): observability for sync return.
            if (!$this->entity_sync->syncStudent($userId, [
                'Name' => $stuName, 'Class' => $stuClass, 'Section' => $stuSection, 'Status' => 'Active',
            ])) {
                log_message('warning', "syncStudent returned false for {$userId} (cancel_tc)");
            }
            // S2 + S6: parent propagation with S7-guarded array_merge + return check.
            if (!$this->entity_sync->syncParent($userId, array_merge($student, [
                'Status' => 'Active', 'status' => 'Active',
            ]))) {
                log_message('warning', "syncParent returned false for {$userId} (cancel_tc)");
            }
        } catch (\Exception $e) {
            log_message('error', "entity_sync cancel_tc failed for {$userId}: " . $e->getMessage());
        }

        // G1 — Blank current-month attendance from today onwards. The
        // student is reactivating; days during the TC window stay as
        // whatever they were (likely V), and from today onwards they
        // get a clean slate that future marks can fill in.
        $this->_blank_summary_from_today($userId);

        // G2 — Section strength rises by one (student returns to roster).
        $this->_recompute_section_strength($stuClass, $stuSection);

        $this->_log_history($school_id, $userId, 'TC_CANCELLED',
            'Transfer Certificate cancelled — student re-activated.'
        );

        return $this->json_success(['message' => 'TC cancelled and student re-activated.']);
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT WITHDRAWAL & STATUS
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Soft-withdraw a student: mark Inactive, remove from session roster, log.
     * Does NOT delete any data — student profile and documents are preserved.
     */
    public function withdraw_student()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_withdraw', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;
        $userId      = trim($this->input->post('user_id'));
        $reason      = trim($this->input->post('reason') ?? '') ?: 'Withdrawn';

        if (empty($userId)) return $this->json_error('User ID required.');
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');

        $student = $this->_getStudent($userId);
        if (empty($student) || !is_array($student)) {
            return $this->json_error('Student not found.');
        }

        if (($student['status'] ?? $student['Status'] ?? '') === 'Inactive') {
            return $this->json_error('Student is already inactive.');
        }

        // Check outstanding fees — block withdrawal if dues remain (unless force_override is set)
        $forceOverride = $this->input->post('force_override') === 'true';
        if (!$forceOverride) {
            $dues = $this->_check_outstanding_dues($userId, $student);
            if ($dues['has_dues']) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode([
                    'status'  => 'error',
                    'message' => $dues['summary'] . '. Clear all dues before withdrawing.',
                    'dues'    => $dues,
                    'can_override' => true,
                ]);
                return;
            }
        }

        // ── FIX 2: Remove from RTDB roster ───────────────────────────
        $stuClass   = Firestore_service::classKey($student['Class'] ?? $student['className'] ?? '');
        $stuSection = Firestore_service::sectionKey($student['Section'] ?? $student['section'] ?? '');
        if ($stuClass && $stuSection) {
            $this->dw->removeFromRoster($stuClass, $stuSection, $userId);
        }

        // Mark as Inactive in Firestore (source of truth — no RTDB mirror).
        $this->fs->updateEntity('students', $userId, ['status' => 'Inactive', 'updatedAt' => date('c')]);

        // Entity sync for Android apps
        try {
            // SIS Wave-2 S6 (2026-05-31): observability for sync return.
            if (!$this->entity_sync->syncStudent($userId, [
                'Name'    => $student['Name'] ?? $student['name'] ?? $userId,
                'Class'   => $stuClass,
                'Section' => $stuSection,
                'Status'  => 'Inactive',
            ])) {
                log_message('warning', "syncStudent returned false for {$userId} (withdraw_student)");
            }
            // S3 + S6: parent propagation with S7-guarded array_merge + return check.
            if (!$this->entity_sync->syncParent($userId, array_merge($student, [
                'Status' => 'Inactive', 'status' => 'Inactive',
            ]))) {
                log_message('warning', "syncParent returned false for {$userId} (withdraw_student)");
            }
        } catch (\Exception $e) {
            log_message('error', "entity_sync withdraw_student sync failed for {$userId}: " . $e->getMessage());
        }

        // Freeze fee records for withdrawn student
        try {
            $this->feeLifecycle->freezeFeesOnSoftDelete($userId);
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::freezeFeesOnSoftDelete failed for {$userId}: " . $e->getMessage());
        }

        // G1 — Blank current-month attendance from today onwards so the
        // withdrawn student's % stops drifting upward as the rest of
        // the month gets recorded for everyone else.
        $this->_blank_summary_from_today($userId);

        // G2 — Section strength drops by one.
        $this->_recompute_section_strength($stuClass, $stuSection);

        $this->_log_history($school_id, $userId, 'WITHDRAWAL',
            "Student withdrawn: {$reason}",
            ['reason' => $reason, 'session' => $session, 'class' => $stuClass, 'section' => $stuSection]
        );

        return $this->json_success(['message' => 'Student withdrawn and marked Inactive.']);
    }

    /**
     * Toggle or explicitly set a student's Status field (Active / Inactive).
     * TC status is managed through issue_tc / cancel_tc, not here.
     */
    public function change_status()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_change_status', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id = $this->parent_db_key;
        $userId    = trim($this->input->post('user_id'));
        $newStatus = trim($this->input->post('status'));

        if (empty($userId)) return $this->json_error('User ID required.');
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');
        if (!in_array($newStatus, ['Active', 'Inactive'], true)) {
            return $this->json_error('Status must be Active or Inactive.');
        }

        $student = $this->_getStudent($userId);
        if (empty($student)) return $this->json_error('Student not found.');

        // Phase 1 (2026-04-08): write camelCase only. The legacy `Status`
        // (capital S) duplicate caused case-sensitivity collisions in the
        // Teacher app's StudentDoc Kotlin class — see
        // memory/firestore_class_section_canonical.md for the full story.
        $this->fs->updateEntity('students', $userId, ['status' => $newStatus, 'updatedAt' => date('c')]);

        $this->_log_history($school_id, $userId, 'STATUS_CHANGE',
            "Status changed to {$newStatus}", ['status' => $newStatus]
        );

        // Firestore sync for Android apps (entity_sync loaded in constructor)
        // SIS Wave-2 S6 (2026-05-31): observability for sync return.
        // Note: the syncParent call below passes a Status-only payload which
        // is the S7 known-issue (full-doc rewrite clobbers fatherName/etc.).
        // S6 only adds return visibility; S7 fix is Wave-4 territory.
        if (!$this->entity_sync->syncStudent($userId, ['Status' => $newStatus])) {
            log_message('warning', "syncStudent returned false for {$userId} (toggle_status)");
        }
        if (!$this->entity_sync->syncParent($userId, ['Status' => $newStatus])) {
            log_message('warning', "syncParent returned false for {$userId} (toggle_status)");
        }

        // G1 — Blank current-month attendance from today onwards on
        // every status flip. For Active→Inactive this stops the % drift
        // for the rest of the month; for Inactive→Active it gives a
        // clean slate from today.
        $this->_blank_summary_from_today($userId);

        // G2 — Section strength changes by ±1 either direction. Pull
        // the student's class/section from the (already-fetched) doc
        // so we recompute the right section.
        $stuClass   = $student['Class']   ?? $student['className'] ?? '';
        $stuSection = $student['Section'] ?? $student['section']   ?? '';
        if ($stuClass !== '' && $stuSection !== '') {
            $this->_recompute_section_strength($stuClass, $stuSection);
        }

        return $this->json_success(['message' => "Status updated to {$newStatus}."]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PASSWORD RESET (admin-driven)
       Admins/Principals reset student/parent passwords here.
       Firebase Auth is the sole auth source for SIS users (Parent app).
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * POST /sis/reset_password
     *
     * Reset a student/parent Firebase Auth password on behalf of the user.
     * Sets must_change_password=true so the Parent app gates first launch
     * after reset and forces a self-chosen password.
     *
     */
    public function reset_password()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_reset_password', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $user_id      = trim((string) $this->input->post('user_id', TRUE));
        $new_password = (string) $this->input->post('new_password', FALSE);

        if ($user_id === '' || !$this->safe_path_segment($user_id)) {
            return $this->json_error('Invalid user id.');
        }
        if ($new_password === '') {
            return $this->json_error('New password is required.');
        }
        if (strlen($new_password) < 8 || strlen($new_password) > 72) {
            return $this->json_error('Password must be 8–72 characters.');
        }
        if (!preg_match('/[A-Z]/', $new_password)
            || !preg_match('/[a-z]/', $new_password)
            || !preg_match('/[0-9]/', $new_password)) {
            return $this->json_error('Password must contain an uppercase letter, a lowercase letter, and a digit.');
        }

        // Tenant check — student must belong to the current school.
        $student = $this->_getStudent($user_id);
        if (empty($student)) {
            return $this->json_error('Student not found.', 404);
        }
        $studentSchool = (string) ($student['schoolId'] ?? $student['School_id'] ?? '');
        if ($studentSchool !== $this->school_id) {
            log_message('error',
                "RBAC tenant breach attempt: admin={$this->admin_id} school={$this->school_id} "
                . "tried to reset student={$user_id} of school={$studentSchool}"
            );
            return $this->json_error('Student not found in your school.', 404);
        }

        $name = (string) ($student['Name'] ?? $student['name'] ?? $user_id);

        // 1. Update Firebase Auth password.
        $updated = $this->firebase->updateFirebaseUser($user_id, ['password' => $new_password]);
        if ($updated === null) {
            // Self-heal: a student can have a Firestore/RTDB record but no
            // Firebase Auth account (never created at admission, or later
            // deleted). In that case updateUser throws "no user record" and the
            // reset would dead-end. Detect the missing account and create it now
            // with uid = studentId + the requested password, so the reset
            // succeeds. A genuine failure on an EXISTING account still errors.
            if ($this->firebase->getFirebaseUser($user_id) === null) {
                $authEmail = Firebase::authEmail($user_id);
                $created   = $this->firebase->createFirebaseUser($authEmail, $new_password, [
                    'uid'         => $user_id,
                    'displayName' => $name,
                ]);
                if ($created === null) {
                    log_message('error', "Sis::reset_password self-heal create failed for {$user_id}");
                    return $this->json_error('Failed to update Firebase Auth password.');
                }
                log_message('info', "Sis::reset_password self-healed missing Auth account for {$user_id}");
            } else {
                return $this->json_error('Failed to update Firebase Auth password.');
            }
        }

        // 2. Set must-change-password claim — Parent app gates on this.
        $this->firebase->setCanonicalClaims($user_id, [
            'role'          => 'Parent',
            'school_id'     => $this->school_id,
            'school_code'   => $this->school_code,
            'parent_db_key' => $this->parent_db_key,
            // Parent logs in AS the student — auth.uid == studentId. Emit the
            // student identity claims the Parent app's red-flag listener needs.
            'student_id'    => $user_id,
            'extra'         => [
                'must_change_password' => true,
                'password_reset_at'    => time(),
                'password_reset_by'    => (string) ($this->admin_id ?? ''),
            ],
        ]);

        // 3. Revoke refresh tokens — kicks active app sessions.
        $this->firebase->revokeRefreshTokens($user_id);

        // 4. Mirror flag to Firestore students doc — the Parent app reads
        //    this field to trigger its force-change-password screen.
        try {
            $this->fs->set('students', $this->fs->docId($user_id), [
                'mustChangePassword' => true,
                'updatedAt'          => date('c'),
            ], true);
        } catch (\Exception $e) {
            log_message('error', 'Sis::reset_password Firestore mustChange write failed: ' . $e->getMessage());
        }

        log_audit('SIS', 'reset_password', $user_id, "Password reset for '{$name}'");

        return $this->json_success([
            'message' => "Password reset for '{$name}'. They will be required to change it on next login.",
            'user_id' => $user_id,
            'name'    => $name,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       DOCUMENTS
    ══════════════════════════════════════════════════════════════════════ */

    public function documents($userId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_documents', 'SIS', 'view');
        if (empty($userId) || !$this->safe_path_segment($userId)) show_404();

        $school_id = $this->parent_db_key;
        $student = $this->_getStudent($userId);
        if (empty($student)) show_404();

        $data['student'] = $student;

        $this->load->view('include/header');
        $this->load->view('sis/documents', $data);
        $this->load->view('include/footer');
    }

    public function upload_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_upload_doc', 'SIS', 'edit');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id = $this->parent_db_key;
        $userId    = trim($this->input->post('user_id'));
        $docLabel  = trim($this->input->post('doc_label'));

        // Sanitize label — Firebase keys cannot contain . $ # [ ] /
        $docLabel = trim(preg_replace('/[.\$#\[\]\/]/', '_', $docLabel));

        if (empty($userId) || empty($docLabel)) {
            return $this->json_error('User ID and document label are required.');
        }
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');

        if (empty($_FILES['document']['name'])) {
            return $this->json_error('No file uploaded.');
        }

        // ── Fix 1: Extension whitelist ────────────────────────────────────
        $allowedExt  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
        $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $ext  = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        // M-03 FIX: Use finfo for server-side MIME detection (don't trust client-supplied type)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['document']['tmp_name']);

        if (!in_array($ext, $allowedExt, true)) {
            return $this->json_error('Invalid file type. Allowed: JPG, PNG, GIF, WebP, PDF.');
        }
        if (!in_array($mime, $allowedMime, true)) {
            return $this->json_error('Invalid MIME type for uploaded file.');
        }
        if ($_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            return $this->json_error('File upload error (code ' . $_FILES['document']['error'] . ').');
        }

        // SIS Wave-3 DM6 (2026-05-31): per-student quota check. Replaces the
        // hardcoded 5 MB per-file check with config-driven per-file + aggregate
        // size + doc-count caps. Per-school override at
        // schools.{schoolId}.documentQuota takes precedence over defaults
        // (see application/config/sis_document_quota.php). Surface the quota
        // reason to the operator so they know exactly what limit was hit.
        $fileBytes = (int) $_FILES['document']['size'];
        $quota = $this->_check_doc_quota($userId, $fileBytes);
        if (!$quota['ok']) {
            return $this->json_error($quota['reason']);
        }

        // Canonical Storage scheme: schools/{schoolId}/students/{userId}/documents/...
        // matches _uploadStudentFile() so admission-time and later doc uploads share
        // ONE tree. Keyed by school_id (SCH_XXXXXX), not parent_db_key (which can be a
        // login code). Was: Students/{parent_db_key}/{userId}/docs/{label}.
        $storagePath = "schools/{$this->school_id}/students/{$userId}/documents/{$docLabel}";

        // ─────────────────────────────────────────────────────────────────
        // Storage-orphan fix (2026-05-30): delete the previous Storage
        // object before uploading the replacement. Mirrors the proven
        // edit_student@3434-3435 pattern via _deleteOldStorageFile. Closes
        // the cross-path orphan case where the prior upload landed at the
        // admission path (_uploadStudentFile: {school}/Students/{class}/...)
        // and the re-upload lands here at the deterministic docs path;
        // without this delete, the admission file lingers. Same-path
        // re-uploads remain correct (delete-then-write is idempotent).
        // ─────────────────────────────────────────────────────────────────
        $priorStudentDoc = $this->_getStudent($userId);
        $priorDocNode    = is_array($priorStudentDoc['documents'][$docLabel] ?? null)
            ? $priorStudentDoc['documents'][$docLabel]
            : (is_array($priorStudentDoc['Doc'][$docLabel] ?? null)
                ? $priorStudentDoc['Doc'][$docLabel]
                : []);

        // HARDENING (Objective 1/4) — the legacy label-keyed uploader must not
        // clobber a Canonical Document Service slot: doing so would raw-delete a
        // service object and diverge the Doc[] map from documentRefs. If this
        // label already holds a managed document, refuse and direct the caller to
        // the managed uploader (the Documents page). The service is the single
        // authority for managed-document bytes + lifecycle.
        if (is_array($priorDocNode) && !empty($priorDocNode['documentId'])) {
            return $this->json_error('This document is managed by the Document Service — use the Documents page to replace it.');
        }

        if (!empty($priorDocNode)) {
            $this->_deleteOldStorageFile($priorDocNode);
        }

        try {
            // FIXED: args were swapped (localPath, remotePath) and return is bool not URL
            $uploaded = $this->firebase->uploadFile($_FILES['document']['tmp_name'], $storagePath);
            if (!$uploaded) {
                return $this->json_error('Failed to upload file to storage.');
            }
            $url      = $this->firebase->getDownloadUrl($storagePath);
            $thumbUrl = '';
            // FIXED: use validated $mime instead of untrusted $_FILES type
            if (strpos($mime, 'image/') === 0) {
                $thumbUrl = $url;
            }

            // R1: write to the CANONICAL document map keys (documents + Doc) via
            // read-modify-write — NOT the dotted "doc.{label}" literal field, which
            // no reader sees (normalizer maps only documents<->Doc) and the REST
            // client mis-encodes. Mirrors delete_document() + edit_student()'s
            // Doc->documents mirror.
            $studentDoc = $this->_getStudent($userId);
            $docMap = is_array($studentDoc['documents'] ?? null) ? $studentDoc['documents']
                    : (is_array($studentDoc['Doc'] ?? null) ? $studentDoc['Doc'] : []);
            // SIS Wave-3 DM6 (2026-05-31): record `bytes` so future quota
            // aggregations sum accurately. Additive — readers that don't
            // care about size ignore the field; grandfathered docs without
            // bytes continue to render normally.
            $docMap[$docLabel] = [
                'url'         => $url,
                'thumbnail'   => $thumbUrl,
                'uploaded_at' => date('Y-m-d H:i:s'),
                'bytes'       => $fileBytes,
            ];
            $ok = $this->fs->updateEntity('students', $userId, ['documents' => $docMap, 'Doc' => $docMap]);
            // R2: do not report success if the persistence write failed.
            if (!$ok) {
                return $this->json_error('File uploaded to storage, but saving the document record failed. Please retry.');
            }

            $this->_log_history($school_id, $userId, 'DOCUMENT_UPLOAD',
                "Document uploaded: {$docLabel}", ['doc_label' => $docLabel]
            );

            return $this->json_success(['message' => 'Document uploaded.', 'url' => $url]);
        } catch (\Exception $e) {
            return $this->json_error('Upload failed: ' . $e->getMessage());
        }
    }

    public function delete_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_delete_doc', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id = $this->parent_db_key;
        $userId    = trim($this->input->post('user_id'));
        $docLabel  = trim($this->input->post('doc_label'));

        // Sanitize label — same as upload_document()
        $docLabel = trim(preg_replace('/[.\$#\[\]\/]/', '_', $docLabel));

        if (empty($userId) || empty($docLabel)) {
            return $this->json_error('User ID and doc label required.');
        }
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');

        // Remove document entry from student's documents map.
        // Phase 1 (2026-04-08): canonical key is `documents` (camelCase). The
        // legacy capitalised `Doc` key was the second leak alongside `Status`
        // — see memory/firestore_class_section_canonical.md.
        $studentDoc = $this->_getStudent($userId);
        $docMap = $studentDoc['documents'] ?? $studentDoc['Doc'] ?? [];

        $oldDocNode = $docMap[$docLabel] ?? [];

        // ─────────────────────────────────────────────────────────────────
        // HARDENING (Objective 1) — the legacy label-keyed delete must NEVER
        // touch a Canonical Document Service object. There is exactly ONE
        // authoritative deletion lifecycle for managed documents.
        //   (a) Node carries a `documentId` → it is a service-managed document:
        //       route through the service lifecycle (unlink + reference-counted
        //       purge + audit). No raw Storage delete, no bypass.
        //   (b) Node has no marker but its bytes live under the service prefix
        //       `documents/{schoolId}/…` (e.g. an admission document carried onto
        //       the student at enrollment, still referenced by the application):
        //       drop ONLY the student's display reference — never raw-delete a
        //       shared service object (that would break the admission record, W4).
        //   (c) A genuine legacy student-owned object → the historical raw delete.
        // ─────────────────────────────────────────────────────────────────
        $managedId = is_array($oldDocNode) ? trim((string) ($oldDocNode['documentId'] ?? '')) : '';
        if ($managedId !== '') {
            return $this->_delete_managed_student_document($userId, $managedId);
        }
        $svcPath = is_array($oldDocNode) ? $this->_extractStoragePathFromUrl((string) ($oldDocNode['url'] ?? '')) : '';
        if ($svcPath !== '' && strpos($svcPath, 'documents/' . $this->school_name . '/') === 0) {
            unset($docMap[$docLabel]);
            $this->fs->updateEntity('students', $userId, ['documents' => $docMap, 'Doc' => $docMap]);
            $this->_log_history($this->school_id, $userId, 'DOCUMENT_DELETE',
                "Document reference removed: {$docLabel}",
                ['doc_label' => $docLabel, 'note' => 'service-owned object retained (may be shared)']);
            return $this->json_success(['message' => 'Document removed.']);
        }

        // ─────────────────────────────────────────────────────────────────
        // (c) Genuine legacy object. Storage-orphan fix (2026-05-30): delete the
        // Storage object BEFORE removing the Firestore reference (mirrors the
        // edit_student@3435 pattern via _deleteOldStorageFile).
        // ─────────────────────────────────────────────────────────────────
        if (is_array($oldDocNode) && !empty($oldDocNode)) {
            $this->_deleteOldStorageFile($oldDocNode);
        }

        unset($docMap[$docLabel]);
        // R4: keep BOTH canonical keys in sync (upload now dual-writes documents+Doc).
        $ok = $this->fs->updateEntity('students', $userId, ['documents' => $docMap, 'Doc' => $docMap]);
        if (!$ok) {
            return $this->json_error('Could not update the document record. Please retry.');
        }

        $this->_log_history($school_id, $userId, 'DOCUMENT_DELETE',
            "Document deleted: {$docLabel}", ['doc_label' => $docLabel]
        );

        return $this->json_success(['message' => 'Document deleted.']);
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT DOCUMENTS — Canonical Document Service consumer (Phase 2)

       The Student "Documents" experience is the first production consumer of the
       (frozen) Document Service. New uploads become canonical documents
       (id-addressed, verified, versioned, reference-counted, audited) linked to
       the student; a denormalized `documentRefs[]` pointer on the student drives
       the admin UI, while the legacy `documents`/`Doc` map is kept in sync so the
       Parent/Teacher apps keep displaying documents with NO app change. Existing
       students' legacy `Doc[]` entries render read-only (view/download) for full
       backward compatibility — no migration. The Document Service, its APIs,
       schema, config, and concurrency model are NOT modified.
    ══════════════════════════════════════════════════════════════════════ */

    /** Student document types (reuse the frozen document_service config types; no 'photo' — profile pic is separate). */
    private function _student_doc_types(): array
    {
        return ['aadhaar', 'birth_certificate', 'transfer_certificate', 'marksheet', 'medical_certificate', 'additional'];
    }

    /** Display label for a student document type (mirrors the frozen document_service config labels). */
    private function _student_doc_label(string $type, string $custom = ''): string
    {
        $map = [
            'aadhaar'              => 'Aadhaar Card',
            'birth_certificate'    => 'Birth Certificate',
            'transfer_certificate' => 'Transfer Certificate',
            'marksheet'            => 'Marksheet',
            'medical_certificate'  => 'Medical Certificate',
        ];
        if (isset($map[$type])) return $map[$type];
        $c = trim(preg_replace('/[.\$#\[\]\/]/', '_', $custom));
        return $c !== '' ? substr($c, 0, 60) : 'Document';
    }

    /** Ownership guard — the documentId must be referenced by THIS student. */
    private function _student_doc_owned(array $student, string $documentId): bool
    {
        if ($documentId === '') return false;
        foreach ((array) ($student['documentRefs'] ?? []) as $r) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $documentId) return true;
        }
        return false;
    }

    /**
     * Upsert a document pointer onto the student: the `documentRefs[]` entry (the
     * admin-UI lifecycle source) AND the legacy `documents`/`Doc` map keyed by
     * label (Parent/Teacher app compatibility). Read-modify-write; every field is
     * a merge so no other student field is touched.
     */
    private function _student_sync_docref(string $userId, array $doc, string $label, string $type, int $bytes): void
    {
        $student = $this->_getStudent($userId) ?? [];
        $docId   = (string) ($doc['documentId'] ?? '');
        if ($docId === '') return;

        $ref = [
            'documentId'         => $docId,
            'type'               => $type,
            'role'               => $type,
            'label'              => $label,
            'url'                => (string) ($doc['downloadUrl'] ?? ''),
            'category'           => (string) ($doc['category'] ?? ($type === 'additional' ? 'additional' : 'predefined')),
            'verificationStatus' => (string) ($doc['verificationStatus'] ?? 'pending'),
            'version'            => (int) ($doc['version'] ?? 1),
            'uploaded_at'        => date('Y-m-d H:i:s'),
        ];
        $refs = is_array($student['documentRefs'] ?? null) ? array_values($student['documentRefs']) : [];
        $found = false;
        foreach ($refs as $i => $r) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) { $refs[$i] = $ref; $found = true; break; }
        }
        if (!$found) $refs[] = $ref;

        // Legacy map (documents + Doc), keyed by unique label — app compatibility.
        $map = is_array($student['documents'] ?? null) ? $student['documents']
             : (is_array($student['Doc'] ?? null) ? $student['Doc'] : []);
        $key = $label;
        if (!$found) {
            // Reuse an EMPTY placeholder slot of the same label (fill it) — or the
            // same document — instead of creating a "Label (2)" duplicate. Only
            // suffix when a DIFFERENT real document already holds that label.
            $n = 2;
            while (isset($map[$key])) {
                $ex    = $map[$key];
                $exId  = is_array($ex) ? (string) ($ex['documentId'] ?? '') : '';
                $exUrl = is_array($ex) ? (string) ($ex['url'] ?? '') : (string) $ex;
                if ($exId === $docId || ($exId === '' && $exUrl === '')) break; // same doc, or empty slot → use this key
                $key = $label . ' (' . $n++ . ')';
            }
        } else {
            // replace: reuse whatever label key currently points at this documentId
            foreach ($map as $k => $v) { if (is_array($v) && (string) ($v['documentId'] ?? '') === $docId) { $key = $k; break; } }
        }
        $thumb = strpos((string) ($doc['mime'] ?? ''), 'image/') === 0 ? (string) ($doc['downloadUrl'] ?? '') : '';
        $map[$key] = [
            'url'         => (string) ($doc['downloadUrl'] ?? ''),
            'thumbnail'   => $thumb,
            'uploaded_at' => date('Y-m-d H:i:s'),
            'bytes'       => $bytes,
            'documentId'  => $docId,
        ];
        $this->fs->updateEntity('students', $userId, ['documentRefs' => $refs, 'documents' => $map, 'Doc' => $map]);
    }

    /**
     * POST — upload a student document through the Document Service. Predefined
     * types are single-slot: re-uploading an existing type REPLACES it (new
     * version). `additional` always adds a new document. Per-student quota is
     * still enforced (existing business rule; not storage logic).
     */
    public function student_upload_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_upload_doc');
        if ($this->input->method() !== 'post') return $this->json_error('POST required');

        $userId = trim((string) $this->input->post('user_id'));
        $type   = trim((string) $this->input->post('doc_type'));
        $custom = trim((string) $this->input->post('doc_label'));
        if ($userId === '' || !$this->safe_path_segment($userId))   return $this->json_error('Invalid User ID.');
        if (!in_array($type, $this->_student_doc_types(), true))     return $this->json_error('Invalid document type.');
        if (empty($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->json_error('Please choose a file to upload.');
        }
        $student = $this->_getStudent($userId);
        if (empty($student)) return $this->json_error('Student not found.');

        // Reuse the existing per-student quota (business rule; not storage logic).
        $fileBytes = (int) ($_FILES['document']['size'] ?? 0);
        $quota = $this->_check_doc_quota($userId, $fileBytes);
        if (!$quota['ok']) return $this->json_error($quota['reason']);

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        $actor = (string) ($this->admin_id ?? 'system');
        $label = $this->_student_doc_label($type, $custom);
        $tmp   = (string) $_FILES['document']['tmp_name'];
        $orig  = (string) ($_FILES['document']['name'] ?? 'file');

        // Predefined single-slot: replace the existing document of this type.
        $existingId = '';
        if ($type !== 'additional') {
            foreach ((array) ($student['documentRefs'] ?? []) as $r) {
                if (is_array($r) && (string) ($r['type'] ?? '') === $type) { $existingId = (string) ($r['documentId'] ?? ''); break; }
            }
        }

        if ($existingId !== '') {
            $res = $this->docsvc->replace($existingId, $tmp, $orig, $actor);
            if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Upload failed.');
            $this->_student_sync_docref($userId, $res['document'], $label, $type, $fileBytes);
            $this->_log_history($this->school_id, $userId, 'DOCUMENT_REPLACE', "Document replaced: {$label}", ['documentId' => $existingId, 'type' => $type]);
            return $this->json_success(['message' => 'Document updated.', 'documentId' => $existingId, 'url' => $res['document']['downloadUrl'] ?? '']);
        }

        $res = $this->docsvc->upload($tmp, $orig, $type, ['uploadedBy' => $actor, 'label' => $label]);
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Upload failed.');
        $doc = $res['document'];
        $this->docsvc->link($doc['documentId'], 'student', $userId, $type, $actor);
        $this->_student_sync_docref($userId, $doc, $label, $type, $fileBytes);
        $this->_log_history($this->school_id, $userId, 'DOCUMENT_UPLOAD', "Document uploaded: {$label}", ['documentId' => $doc['documentId'], 'type' => $type]);
        return $this->json_success(['message' => 'Document uploaded.', 'documentId' => $doc['documentId'], 'url' => $doc['downloadUrl'] ?? '']);
    }

    /** POST — set a student document's verification status (verified|rejected|pending). */
    public function verify_student_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_upload_doc');
        $userId = trim((string) $this->input->post('user_id'));
        $docId  = trim((string) $this->input->post('document_id'));
        $status = trim((string) $this->input->post('status'));
        if ($userId === '' || !$this->safe_path_segment($userId))       return $this->json_error('Invalid User ID.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId))                    return $this->json_error('Invalid document id.');
        if (!in_array($status, ['verified', 'rejected', 'pending'], true)) return $this->json_error('Invalid status.');
        $student = $this->_getStudent($userId);
        if (empty($student))                          return $this->json_error('Student not found.');
        if (!$this->_student_doc_owned($student, $docId)) return $this->json_error('Document does not belong to this student.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        $res = $this->docsvc->verify($docId, $status, (string) ($this->admin_id ?? 'system'));
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Verification failed.');

        $refs = array_map(function ($r) use ($docId, $status) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) $r['verificationStatus'] = $status;
            return $r;
        }, (array) ($student['documentRefs'] ?? []));
        $this->fs->updateEntity('students', $userId, ['documentRefs' => array_values($refs)]);
        $this->_log_history($this->school_id, $userId, 'DOCUMENT_VERIFY', "Document {$docId} marked {$status}", ['documentId' => $docId, 'status' => $status]);
        // NOTE: do NOT return a 'status' key — json_success() already sets
        // status:'success', and a data 'status' would overwrite it (making the
        // frontend think the call failed). Return the value under a distinct key.
        return $this->json_success(['verificationStatus' => $status]);
    }

    /** POST (multipart) — replace a student document's bytes (new version, old retired). */
    public function replace_student_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_upload_doc');
        $userId = trim((string) $this->input->post('user_id'));
        $docId  = trim((string) $this->input->post('document_id'));
        if ($userId === '' || !$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId))             return $this->json_error('Invalid document id.');
        if (empty($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->json_error('Please choose a replacement file.');
        }
        $student = $this->_getStudent($userId);
        if (empty($student))                          return $this->json_error('Student not found.');
        if (!$this->_student_doc_owned($student, $docId)) return $this->json_error('Document does not belong to this student.');

        $fileBytes = (int) ($_FILES['document']['size'] ?? 0);
        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        $res = $this->docsvc->replace($docId, (string) $_FILES['document']['tmp_name'], (string) ($_FILES['document']['name'] ?? 'file'), (string) ($this->admin_id ?? 'system'));
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Replace failed.');

        $label = ''; $type = 'additional';
        foreach ((array) ($student['documentRefs'] ?? []) as $r) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) { $label = (string) ($r['label'] ?? ''); $type = (string) ($r['type'] ?? 'additional'); break; }
        }
        if ($label === '') $label = $this->_student_doc_label($type);
        $this->_student_sync_docref($userId, $res['document'], $label, $type, $fileBytes);
        $this->_log_history($this->school_id, $userId, 'DOCUMENT_REPLACE', "Document {$docId} replaced", ['documentId' => $docId]);
        return $this->json_success(['url' => $res['document']['downloadUrl'] ?? '', 'version' => $res['document']['version'] ?? 2]);
    }

    /**
     * The SINGLE authoritative deletion lifecycle for a service-managed student
     * document. Ownership-guarded → `docsvc->delete('student', …)` (unlink;
     * reference-counted purge only if orphaned & retention-eligible — a document
     * still linked elsewhere, e.g. its admission application, is never destroyed)
     * → strip the student's documentRefs pointer + legacy map entry → audit.
     * Reused by both the managed endpoint and the hardened legacy endpoint so no
     * managed document can ever bypass the Document Service lifecycle.
     */
    private function _delete_managed_student_document(string $userId, string $docId)
    {
        $student = $this->_getStudent($userId);
        if (empty($student))                              return $this->json_error('Student not found.');
        if (!$this->_student_doc_owned($student, $docId)) return $this->json_error('Document does not belong to this student.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        $res = $this->docsvc->delete($docId, 'student', $userId, (string) ($this->admin_id ?? 'system'), false);
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Delete failed.');

        $refs = [];
        foreach ((array) ($student['documentRefs'] ?? []) as $r) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) continue;
            $refs[] = $r;
        }
        $map = is_array($student['documents'] ?? null) ? $student['documents']
             : (is_array($student['Doc'] ?? null) ? $student['Doc'] : []);
        foreach ($map as $k => $v) {
            if (is_array($v) && (string) ($v['documentId'] ?? '') === $docId) unset($map[$k]);
        }
        $this->fs->updateEntity('students', $userId, ['documentRefs' => array_values($refs), 'documents' => $map, 'Doc' => $map]);
        $this->_log_history($this->school_id, $userId, 'DOCUMENT_DELETE', 'Document ' . $docId . ' deleted (purged=' . (!empty($res['purged']) ? 'yes' : 'no') . ')', ['documentId' => $docId, 'purged' => !empty($res['purged'])]);
        return $this->json_success(['purged' => !empty($res['purged'])]);
    }

    /** POST — delete a service-managed student document (via the single authoritative lifecycle). */
    public function delete_student_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_delete_doc');
        $userId = trim((string) $this->input->post('user_id'));
        $docId  = trim((string) $this->input->post('document_id'));
        if ($userId === '' || !$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId))             return $this->json_error('Invalid document id.');
        return $this->_delete_managed_student_document($userId, $docId);
    }

    /** GET/POST — the document audit trail for a student (DOCUMENT_* events from studentHistory; no new index). */
    public function student_document_audit()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_documents');
        $userId = trim((string) $this->input->get_post('user_id'));
        if ($userId === '' || !$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');
        $trail = [];
        try {
            $rows = $this->firebase->firestoreQuery('studentHistory', [
                ['schoolId',  '==', $this->school_id],
                ['studentId', '==', $userId],
            ], 'changed_at', 'DESC', 500);
            foreach ((array) $rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                if (!empty($d) && strpos((string) ($d['action'] ?? ''), 'DOCUMENT_') === 0) $trail[] = $d;
            }
        } catch (\Throwable $e) {
            log_message('error', "Sis::student_document_audit query failed for {$userId}: " . $e->getMessage());
        }
        return $this->json_success(['trail' => $trail]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       HISTORY
    ══════════════════════════════════════════════════════════════════════ */

    public function history($userId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_history', 'SIS', 'view');
        if (empty($userId) || !$this->safe_path_segment($userId)) show_404();

        $student = $this->_getStudent($userId);
        if (empty($student)) show_404();

        // History Canonicalization (2026-06-02): read from canonical
        // studentHistory collection (firestoreQuery composite index:
        // schoolId ASC + studentId ASC + changed_at DESC). Replaces
        // legacy students.{id}.History map read which is now retired
        // (writer cutover this same commit).
        $history = [];
        try {
            $rows = $this->firebase->firestoreQuery('studentHistory', [
                ['schoolId',  '==', $this->school_id],
                ['studentId', '==', $userId],
            ], 'changed_at', 'DESC', 5000);
            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                if (!empty($d)) $history[] = $d;
            }
        } catch (\Throwable $e) {
            log_message('error', "Sis::history studentHistory query failed for {$userId}: " . $e->getMessage());
        }

        $data['student'] = $student;
        $data['history'] = $history;

        $this->load->view('include/header');
        $this->load->view('sis/history', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       ID CARD
    ══════════════════════════════════════════════════════════════════════ */

    public function id_card()
    {
        $this->_require_role(self::VIEW_ROLES, 'id_card', 'SIS', 'view');
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        // Active students from the cached snapshot (one Firestore read per 90s,
        // busted on write). The status=='Active' gate that used to be a
        // Firestore `where` is applied in PHP — checking both field casings,
        // so it's strictly more tolerant than the old camelCase/TitleCase pair.
        $allStudentDocs = $this->_cached_student_docs();
        $allStudents = [];
        foreach ($allStudentDocs as $doc) {
            $d = $doc['data'] ?? $doc;
            $rowStatus = (string) ($d['status'] ?? $d['Status'] ?? 'Active');
            if (strcasecmp($rowStatus, 'Active') !== 0) continue;
            $s = $this->_normalizeStudentDoc($d);
            if (!$s) continue;
            $uid = $s['User Id'] ?? $s['studentId'] ?? $d['id'];
            $s['User Id'] = $uid;
            $allStudents[$uid] = $s;
        }

        // Enrolled IDs from Firestore
        $enrolledIds = $this->_get_enrolled_ids();

        $students = array_values(array_filter($allStudents, function ($s) use ($enrolledIds) {
            return isset($enrolledIds[$s['User Id']]);
        }));

        usort($students, function ($a, $b) {
            $c = strcmp($a['Class'] ?? '', $b['Class'] ?? '');
            if ($c) return $c;
            $c = strcmp($a['Section'] ?? '', $b['Section'] ?? '');
            if ($c) return $c;
            return strcmp($a['Name'] ?? '', $b['Name'] ?? '');
        });

        // Fetch school profile for display
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $profile = is_array($schoolDoc) ? $schoolDoc : [];

        $data['students']       = $students;
        $data['session_year']   = $session_year;
        $data['school_name']    = $school_name;
        $data['school_profile'] = [
            'school_name' => $profile['name'] ?? $profile['display_name'] ?? $this->school_display_name ?? '',
            'address'     => $profile['address'] ?? $profile['city'] ?? '',
            'logo'        => $profile['logoUrl'] ?? $profile['logo_url'] ?? $profile['logo'] ?? '',
            'phone'       => $profile['phone'] ?? '',
        ];

        $this->load->view('include/header');
        $this->load->view('sis/id_card', $data);
        $this->load->view('include/footer');
    }


    /**
     * Same-origin QR endpoint — local SVG generation (no external API).
     *
     * Post Tier-A QR upgrade: backed by `chillerlan/php-qrcode`. No
     * external network dependency, works offline, scales perfectly
     * for print. Returned as `image/svg+xml` so any consumer (HTML
     * `<img>`, CSS `background-image: url(…)`, Dompdf) can embed
     * the result without further processing. The legacy `?size=`
     * parameter is accepted but no longer meaningful — SVG is vector
     * and scales to any container size.
     *
     * No auth gate — the QR is just a visual encoding of identifiers
     * that are already printed in plaintext on the same ID card,
     * AND the token now carries an HMAC signature so a printed QR
     * doesn't reveal the secret either.
     *
     * Most call sites should prefer `qr_svg_data_uri()` directly to
     * skip the HTTP round-trip entirely; this endpoint stays for
     * back-compat and any consumer that needs a real URL.
     */
    public function qr_image($token = '')
    {
        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 256) {
            show_404();
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+={0,2}$/', $token)) {
            show_404();
        }

        $this->load->helper('qr_token');
        try {
            $options = new \chillerlan\QRCode\QROptions([
                'outputType'   => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
                'outputBase64' => false,        // raw SVG bytes for `image/svg+xml`
                'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::L,
                'scale'        => 5,
                'addQuietzone' => true,
            ]);
            $svg = (new \chillerlan\QRCode\QRCode($options))->render($token);
        } catch (\Throwable $e) {
            log_message('error', 'Sis::qr_image render failed: ' . $e->getMessage());
            // Tiny inline placeholder so the layout doesn't shift.
            header('Content-Type: image/svg+xml');
            header('Cache-Control: no-store');
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';
            return;
        }

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=86400, immutable');
        header('Content-Length: ' . strlen($svg));
        echo $svg;
    }

    /**
     * One-time utility: rebuild the Students_Index from the full Users/Parents tree.
     * Call via GET: sis/rebuild_index — idempotent, safe to re-run.
     */
    public function rebuild_index()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_rebuild_index', 'SIS', 'manage');

        // In Firestore, the students collection IS the index — no separate index needed.
        $count = $this->fs->count('students', [['schoolId', '==', $this->school_id]]);

        return $this->json_success([
            'message' => 'Students index is the Firestore students collection. No rebuild needed.',
            'count'   => $count,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       AJAX HELPERS
    ══════════════════════════════════════════════════════════════════════ */

    public function search_student()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_search', 'SIS', 'view');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $query       = strtolower(trim($this->input->post('query')));
        $classFilter = trim($this->input->post('class') ?? '');
        $secFilter   = trim($this->input->post('section') ?? '');
        $filterGender  = trim($this->input->post('gender')  ?? '');
        // Status filter — Active / Inactive / TC / '' (means All except
        // Deleted). Default to Active to match pre-toggle behaviour;
        // admins surface Inactive students by switching the dropdown.
        $statusFilter  = trim($this->input->post('status') ?? 'Active');
        $page        = max(1, (int)($this->input->post('page') ?? 1));
        $perPage     = 30;

        // PERF — `search_student` was making ~32 Firestore round-trips
        // per page on a 500-student school: 1 full schoolList read for
        // the index, 1 separate `_get_enrolled_ids()` collection read,
        // then a `_getStudent()` call per row in the current page.
        //
        // We now do ONE collection read and keep the full doc data in
        // memory so the per-row `_getStudent()` calls are gone, and
        // the enrolled-set is derived from the same in-memory map
        // instead of re-querying. Net: ~32 reads → 1 read.
        //
        // PERF-2 (2026-06-13) — that one read still fired on EVERY request:
        // every debounced keystroke, every filter flip, every page change
        // re-downloaded the whole students collection and rebuilt the index,
        // which is why search-as-you-type felt like it "loaded first, then
        // showed". The session/Deleted-filtered snapshot below does not depend
        // on the query/class/section/gender/status filters (those are applied
        // afterwards), so we cache it under a school+session key for 90s. The
        // snapshot is busted immediately on any students write (see
        // Firestore_service::_bustDashboard), so admissions/deletions/status
        // changes still show up on the next load with no staleness.
        $currentSession = $this->session_year;

        $this->load->driver('cache', ['adapter' => 'file']);
        $cacheKey = $this->fs->studentsListCacheKey();
        $snapshot = $this->cache->get($cacheKey);

        if (is_array($snapshot) && isset($snapshot['index'], $snapshot['rawDocs'])) {
            $index   = $snapshot['index'];
            $rawDocs = $snapshot['rawDocs'];
        } else {
            $studentList = $this->fs->schoolList('students');

            $index   = [];   // [uid => filter-fields]
            $rawDocs = [];   // [uid => full Firestore doc]   ← used for the page's row data
            foreach ($studentList as $s) {
                $d = $s['data'] ?? $s;
                $uid = $d['studentId'] ?? $d['User Id'] ?? $d['userId'] ?? '';
                if ($uid === '') continue;

                // Session enrollment check (mirrors _get_enrolled_ids).
                // SW-CONVERGE-STUDENTS-A (2026-05-26): canonical `session` singular
                // takes precedence; legacy `sessions[]` array is honored ONLY when
                // the singular field is absent/empty (back-compat for pre-convergence
                // admission records). v7 target = singular as sole student-side
                // authority across all filter sites.
                $session  = $d['session']  ?? null;
                $sessions = $d['sessions'] ?? null;
                if (is_string($session) && $session !== '') {
                    if ($session !== $currentSession) continue;
                } elseif (is_array($sessions) && !empty($sessions)) {
                    if (!in_array($currentSession, $sessions, true)) continue;
                }
                // else: no enrollment record on doc — preserve prior behavior
                // (do not skip here; downstream filters apply).

                $rowStatus = (string) ($d['status'] ?? $d['Status'] ?? 'Active');
                // Always exclude hard-deleted rows from the listing.
                if (strcasecmp($rowStatus, 'Deleted') === 0) continue;

                $rawDocs[$uid] = $d;
                $index[$uid] = [
                    'name'    => $d['name']     ?? $d['Name']    ?? '',
                    'class'   => $d['className']?? $d['Class']   ?? '',
                    'section' => $d['section']  ?? $d['Section'] ?? '',
                    'status'  => $rowStatus,
                    'gender'  => $d['gender']   ?? $d['Gender']  ?? '',
                ];
            }

            // 90s TTL — short enough that a TTL-only expiry is barely
            // perceptible, but the explicit write-bust means it's almost
            // always the bust (not the TTL) that refreshes the data.
            $this->cache->save($cacheKey, ['index' => $index, 'rawDocs' => $rawDocs], 90);
        }

        // Filter using index fields (name, class, section, status) + userId
        // Dropdown sends stripped values ("8th", "A") but index has prefixed ("Class 8th", "Section A")
        $filtered = [];
        foreach ($index as $uid => $entry) {
            if (!is_array($entry)) continue;
            // Status filter: blank means "any non-Deleted" (already
            // enforced above); a specific value matches case-insensitively.
            if ($statusFilter !== '' && strcasecmp($entry['status'] ?? '', $statusFilter) !== 0) continue;
            $entryClass = str_replace('Class ', '', $entry['class'] ?? '');
            $entrySec   = str_replace('Section ', '', $entry['section'] ?? '');
            if ($classFilter && $entryClass !== $classFilter) continue;
            if ($secFilter   && $entrySec   !== $secFilter) continue;
            if ($filterGender !== '' && strcasecmp($entry['gender'] ?? '', $filterGender) !== 0) continue;
            if ($query) {
                // Searchable by ID, Name, Class and Phone.
                $p     = $rawDocs[$uid] ?? [];
                $phone = (string) ($p['Phone Number'] ?? $p['phone'] ?? $p['Phone'] ?? '');
                $haystack = strtolower(
                    ($entry['name'] ?? '') . ' ' . $uid . ' '
                    . ($entry['class'] ?? '') . ' ' . $phone
                );
                if (strpos($haystack, $query) === false) continue;
            }
            $filtered[$uid] = $entry;
        }

        // Sort by class then name
        uasort($filtered, function ($a, $b) {
            $c = strcmp($a['class'] ?? '', $b['class'] ?? '');
            return $c ?: strcmp($a['name'] ?? '', $b['name'] ?? '');
        });

        $total     = count($filtered);
        $offset    = ($page - 1) * $perPage;
        $pagedKeys = array_slice(array_keys($filtered), $offset, $perPage);

        // Build the page's response rows from the in-memory `$rawDocs`
        // map — no Firestore round-trips here. Every field that used
        // to come from `_getStudent()` is already on the bulk doc.
        $results = [];
        foreach ($pagedKeys as $uid) {
            $entry = $filtered[$uid];
            $p     = $rawDocs[$uid] ?? [];

            // Photo: check all possible field names
            $photo = $p['Profile Pic'] ?? $p['profilePic'] ?? $p['profile_pic'] ?? '';
            if ($photo === '' && !empty($p['Doc']['Photo'])) {
                $dp = $p['Doc']['Photo'];
                $photo = is_array($dp) ? ($dp['url'] ?? '') : (string)$dp;
            }

            $results[] = [
                'user_id'        => $uid,
                'name'           => $entry['name'] ?? $p['name'] ?? $p['Name'] ?? '',
                'father_name'    => $p['Father Name'] ?? $p['fatherName'] ?? $p['father_name'] ?? '',
                'class'          => str_replace('Class ', '', $entry['class'] ?? $p['className'] ?? $p['Class'] ?? ''),
                'section'        => str_replace('Section ', '', $entry['section'] ?? $p['section'] ?? $p['Section'] ?? ''),
                'phone'          => $p['Phone Number'] ?? $p['phone'] ?? $p['Phone'] ?? '',
                'gender'         => $entry['gender'] ?? $p['Gender'] ?? $p['gender'] ?? '',
                'admission_date' => $p['Admission Date'] ?? $p['admissionDate'] ?? $p['admission_date'] ?? '',
                'dob'            => $p['DOB'] ?? $p['dob'] ?? '',
                'status'         => $entry['status'] ?? $p['status'] ?? $p['Status'] ?? 'Active',
                'photo'          => $photo,
            ];
        }

        return $this->json_success([
            'students' => $results,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    public function get_student()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_get_student', 'SIS', 'view');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id = $this->parent_db_key;
        $userId    = trim($this->input->post('user_id'));

        if (empty($userId)) return $this->json_error('User ID required.');
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');

        // Firestore-first read, RTDB fallback
        $student = $this->_getStudent($userId);
        if (empty($student)) return $this->json_error('Student not found.');

        return $this->json_success(['student' => $student]);
    }

    public function get_classes()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_get_classes', 'SIS', 'view');
        $classMap = $this->_fs_class_map();
        return $this->json_success(['classes' => array_keys($classMap)]);
    }

    public function get_sections()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_get_sections', 'SIS', 'view');
        $classOrd = trim($this->input->get('class') ?? $this->input->post('class') ?? '');

        if (empty($classOrd)) return $this->json_error('Class required.');

        $classMap = $this->_fs_class_map();
        $sections = $classMap[$classOrd] ?? [];

        return $this->json_success(['sections' => $sections]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PRIVATE HELPERS
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Build Students_Index from Users/Parents data and persist it.
     * Called automatically when the index is empty (first visit or migration).
     */
    private function _build_index_from_parents(string $school_id, string $school_name): array
    {
        // In Firestore, students collection IS the index
        $studentList = $this->fs->schoolList('students');
        $index = [];
        foreach ($studentList as $s) {
            $d = $s['data'] ?? $s;
            $uid = $d['studentId'] ?? $d['User Id'] ?? $d['userId'] ?? '';
            if ($uid === '') continue;
            $index[$uid] = [
                'name'    => $d['name'] ?? $d['Name'] ?? '',
                'class'   => $d['className'] ?? $d['Class'] ?? '',
                'section' => $d['section'] ?? $d['Section'] ?? '',
                'status'  => $d['status'] ?? $d['Status'] ?? 'Active',
                'gender'  => $d['gender'] ?? $d['Gender'] ?? '',
            ];
        }
        return $index;
    }

    /**
     * Generate student password — exact copy of Student.php::generatePassword().
     * Format: Ucfirst(first 3 letters of name) + first 4 DOB digits + @
     */
    private function _generatePassword(string $name, string $dob): string
    {
        $cleanName = preg_replace('/[^a-zA-Z]/', '', $name);
        $prefix    = strtolower(substr($cleanName, 0, 3));
        $dobPart   = preg_replace('/[^0-9]/', '', $dob);
        $suffix    = substr($dobPart, 0, 4);
        return ucfirst($prefix) . $suffix . '@';
    }

    /**
     * Upload a student file to Firebase Storage — mirrors Student.php::uploadStudentFile().
     * Returns ['document' => url, 'thumbnail' => url] or false on failure.
     */
    /**
     * SIS Wave-3 fix D3 (2026-05-31): consolidated Firebase Auth user creation.
     *
     * Replaces 3 duplicated inline blocks (save_admission L515-529,
     * import_students L3500-3514, enroll_student L5022-5044) with a single
     * helper. Pre-fix, each call site had its own empty-password handling
     * (only enroll_student checked), its own success-info-log (only
     * enroll_student emitted), and its own error-log message format (3
     * different strings).
     *
     * Helper provides a UNIFORM RETURN SHAPE so each caller decides what
     * to do on failure. Behavioral preservation at call sites:
     *   - save_admission: ignores return (silent continue, B3 territory)
     *   - import_students: ignores return (silent continue)
     *   - enroll_student: captures return into $authCreated + $authError
     *     for the existing json_success response. B3 (success-when-auth-
     *     fails anti-pattern) is NOT touched — separate Tier-2 fix.
     *
     * Returns ['success' => bool, 'error' => string].
     *   ['success' => true,  'error' => '']        Auth + claims succeeded
     *   ['success' => false, 'error' => 'reason']  Auth or claims failed,
     *                                              or password was empty
     *
     * $context is the originating method name (free-form string) — used
     * in log lines for forensic triage. Defaults to 'sis' for safety.
     */
    private function _createFirebaseAuthStudent(
        string $studentId,
        string $password,
        string $displayName,
        string $context = 'sis'
    ): array {
        if ($password === '') {
            $err = 'Generated password is empty.';
            log_message('error', "{$context}: Firebase Auth create skipped for {$studentId}: {$err}");
            return ['success' => false, 'error' => $err];
        }
        try {
            $authEmail = Firebase::authEmail($studentId);
            $this->firebase->createFirebaseUser($authEmail, $password, [
                'uid'         => $studentId,
                'displayName' => $displayName,
            ]);
            $this->firebase->setCanonicalClaims($studentId, [
                'role'          => 'student',
                'school_id'     => $this->school_id,
                'school_code'   => $this->school_code,
                'parent_db_key' => $this->parent_db_key,
                // auth.uid == studentId; emit student identity for studentFlags.
                'student_id'    => $studentId,
                // Force set-new-password on first login. Parent app gates on the
                // Firestore mirror below, not the claim, but emit both for parity
                // with the reset flow (reset_password@2048).
                'extra'         => [
                    'must_change_password' => true,
                ],
            ]);
            // Mirror to the students doc — the Parent app reads this field to
            // trigger its force-change-password screen on first login. Merge
            // write (upsert): the primary student doc already exists at every
            // caller (save_admission/import/enroll write it before this helper),
            // and Entity_firestore_sync::syncStudent never touches this key, so
            // the flag survives all subsequent syncs.
            try {
                $this->fs->set('students', $this->fs->docId($studentId), [
                    'mustChangePassword' => true,
                    'updatedAt'          => date('c'),
                ], true);
            } catch (\Exception $e) {
                log_message('error', "{$context}: Firestore mustChange write failed for {$studentId}: " . $e->getMessage());
            }
            log_message('info', "{$context}: Firebase Auth user created for {$studentId} (email={$authEmail}).");
            return ['success' => true, 'error' => ''];
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            log_message('error', "{$context}: Firebase Auth create failed for {$studentId}: {$msg}");
            return ['success' => false, 'error' => $msg];
        }
    }

    /**
     * SIS Wave-3 fix DM6 (2026-05-31): per-student document quota check.
     *
     * Defaults come from application/config/sis_document_quota.php. Per-school
     * override comes from schools.{schoolId}.documentQuota.{maxDocs|maxFileBytes|
     * maxTotalBytes} when present.
     *
     * Returns ['ok' => true] on pass, or ['ok' => false, 'reason' => msg] on fail.
     * Reason is operator-facing — call sites surface it via json_error.
     *
     * Grandfathered docs without a `bytes` field count toward the COUNT cap
     * but are treated as 0 in the SIZE aggregate. COUNT is the primary
     * safeguard for grandfathered students; SIZE becomes accurate over time
     * as docs are re-uploaded through upload_document (which now records
     * bytes). Admission-form uploads via _uploadStudentFile do NOT yet
     * record bytes — a residual gap deliberately left out of DM6 scope
     * (single-issue / surgical change discipline).
     */
    private function _check_doc_quota(string $userId, int $newFileBytes): array
    {
        $this->config->load('sis_document_quota', true);
        $defaults = $this->config->item('sis_document_quota') ?: [];
        $defaultMaxDocs       = (int) ($defaults['max_docs']        ?? 10);
        $defaultMaxFileBytes  = (int) ($defaults['max_file_bytes']  ?? 5 * 1024 * 1024);
        $defaultMaxTotalBytes = (int) ($defaults['max_total_bytes'] ?? 50 * 1024 * 1024);

        $schoolDoc = $this->fs->get('schools', $this->school_id) ?: [];
        $override  = is_array($schoolDoc['documentQuota'] ?? null) ? $schoolDoc['documentQuota'] : [];
        $maxDocs       = (int) ($override['maxDocs']       ?? $defaultMaxDocs);
        $maxFileBytes  = (int) ($override['maxFileBytes']  ?? $defaultMaxFileBytes);
        $maxTotalBytes = (int) ($override['maxTotalBytes'] ?? $defaultMaxTotalBytes);

        // Per-file size cap (operator-tunable; default 5 MB)
        if ($newFileBytes > $maxFileBytes) {
            $maxMb = number_format($maxFileBytes / 1024 / 1024, 1);
            $newMb = number_format($newFileBytes / 1024 / 1024, 1);
            return ['ok' => false, 'reason' => "File too large ({$newMb} MB). Per-file limit is {$maxMb} MB."];
        }

        // Read student's existing document map
        $studentDoc = $this->_getStudent($userId);
        $docMap = is_array($studentDoc['documents'] ?? null) ? $studentDoc['documents']
                : (is_array($studentDoc['Doc'] ?? null) ? $studentDoc['Doc'] : []);
        // Count only REAL documents (entries that actually hold a file). Empty
        // placeholder slots created at enrollment (Photo / Aadhar Card / Birth
        // Certificate / Transfer Certificate with no url) are NOT documents and
        // must not consume the per-student limit — otherwise a fresh student can
        // only ever add (maxDocs − 4) files. Bugfix 2026-07-22.
        $currentCount = 0;
        $currentBytes = 0;
        foreach ($docMap as $entry) {
            $url = is_array($entry) ? (string) ($entry['url'] ?? '') : (string) $entry;
            if ($url === '') continue;                 // empty placeholder slot → not a document
            $currentCount++;
            if (is_array($entry) && isset($entry['bytes'])) {
                $currentBytes += (int) $entry['bytes'];
            }
        }

        // Doc-count cap (new upload would push count to currentCount + 1).
        // A same-label re-upload does NOT add to the count — it replaces an
        // existing entry. Detect this and skip the count cap if applicable.
        // Per-site convention: callers pass $newFileBytes only; we cannot
        // know the doc label here, so the count check applies uniformly.
        // Net effect: a re-upload at full count (=maxDocs) is blocked — the
        // operator must delete-first then upload. Acceptable tradeoff for
        // surgical scope; callers that want the smarter re-upload-aware
        // check can do their own count-vs-replacement detection before
        // calling _check_doc_quota.
        if ($currentCount >= $maxDocs) {
            return ['ok' => false, 'reason' => "Student already has {$currentCount} documents (limit: {$maxDocs}). Delete an existing document first."];
        }

        // Aggregate size cap (tracked bytes only; grandfathered docs count
        // as 0 — see method docblock for rationale)
        if (($currentBytes + $newFileBytes) > $maxTotalBytes) {
            $currentMb = number_format($currentBytes / 1024 / 1024, 1);
            $newMb     = number_format($newFileBytes / 1024 / 1024, 1);
            $maxMb     = number_format($maxTotalBytes / 1024 / 1024, 1);
            return ['ok' => false, 'reason' => "Student total storage: {$currentMb} MB. Cannot upload {$newMb} MB file (would exceed {$maxMb} MB limit). Delete some documents first."];
        }

        return ['ok' => true];
    }

    private function _uploadStudentFile($file, $schoolName, $combinedClassPath, $studentId, $folderLabel, $type = 'document')
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return false;

        $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed   = ($type === 'profile') ? ['jpg','jpeg','png','webp'] : ['jpg','jpeg','png','webp','pdf'];
        if (!in_array($ext, $allowed, true)) return false;

        // SIS Wave-3 DM6 (2026-05-31): per-student quota check. Replaces the
        // hardcoded 5 MB check below with config-driven per-file + aggregate
        // size + doc-count caps. Per-school override at schools.{id}.documentQuota
        // takes precedence over the config default. Returns false on quota
        // fail (matches existing _uploadStudentFile failure convention; caller
        // sees a false return and can choose to surface error to operator).
        $quota = $this->_check_doc_quota($studentId, (int) $file['size']);
        if (!$quota['ok']) {
            log_message('warning', "Sis::_uploadStudentFile DM6 quota block for {$studentId}: " . $quota['reason']);
            return false;
        }

        // M-03 FIX: Validate MIME via finfo (don't trust client-supplied type)
        $allowedMimes = ($type === 'profile')
            ? ['image/jpeg','image/png','image/webp']
            : ['image/jpeg','image/png','image/webp','application/pdf'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, $allowedMimes, true)) return false;

        $timestamp = time();
        $random    = substr(md5(uniqid()), 0, 6);
        $safeLabel = str_replace([' ', '.', '#', '$', '[', ']'], '_', $folderLabel);
        $fileName  = "{$safeLabel}_{$timestamp}_{$random}.{$ext}";
        // Canonical Storage scheme: schools/{schoolId}/students/{studentId}/... so a
        // school's whole footprint sits under one ID-keyed prefix, matching
        // upload_document(). Previously rooted at the bare school id string with a
        // class segment ({schoolName}/Students/{class}/{studentId}/...).
        $basePath  = "schools/{$this->school_id}/students/{$studentId}/";

        $documentPath = ($type === 'profile')
            ? $basePath . "profile/{$fileName}"
            : $basePath . "documents/{$fileName}";

        if ($this->firebase->uploadFile($file['tmp_name'], $documentPath) !== true) return false;

        $documentUrl  = $this->firebase->getDownloadUrl($documentPath);
        $thumbnailUrl = '';

        // Image thumbnail (document mode)
        if ($type === 'document' && in_array($ext, ['jpg','jpeg','png','webp'])) {
            $thumbPath = $basePath . "documents/thumbnail/{$fileName}";
            if ($this->firebase->uploadFile($file['tmp_name'], $thumbPath) === true) {
                $thumbnailUrl = $this->firebase->getDownloadUrl($thumbPath);
            }
        }

        // PDF thumbnail (document mode)
        if ($type === 'document' && $ext === 'pdf') {
            $thumbnailUrl = $this->_generatePdfThumbnail($file['tmp_name'], $basePath."documents/", $safeLabel, $timestamp, $random);
        }

        // Profile photo thumbnail
        if ($type === 'profile' && in_array($ext, ['jpg','jpeg','png','webp'])) {
            $thumbPath = $basePath . "profile/thumbnail/{$fileName}";
            if ($this->firebase->uploadFile($file['tmp_name'], $thumbPath) === true) {
                $thumbnailUrl = $this->firebase->getDownloadUrl($thumbPath);
            }
        }

        return ['document' => $documentUrl, 'thumbnail' => $thumbnailUrl];
    }

    /**
     * Write/update the lightweight Students_Index entry for a student.
     * Path: Schools/{sn}/SIS/Students_Index/{userId}
     */
    private function _update_student_index(
        string $schoolName,
        string $userId,
        string $name,
        string $class,
        string $section,
        string $status,
        string $gender = ''
    ): void {
        // No-op: Students_Index is no longer needed — data lives in students collection
    }

    /**
     * Read a student from Firestore and normalize field names.
     * Single read — no duplicates.
     */
    private function _getStudent(string $userId): ?array
    {
        $doc = $this->fs->getEntity('students', $userId);
        return $this->_normalizeStudentDoc($doc);
    }

    /**
     * Normalize a Firestore student doc to include both Title Case (RTDB legacy)
     * and camelCase (Firestore native) field names.
     *
     * The controller and views historically used Title Case keys ('Name', 'Father Name',
     * 'Phone Number', 'Class', etc.) from RTDB. Firestore docs use camelCase
     * ('name', 'fatherName', 'phone', 'className', etc.).
     *
     * This method ensures both conventions exist so all downstream code works
     * regardless of which format the source document used.
     */
    private function _normalizeStudentDoc(?array $doc): ?array
    {
        if (!is_array($doc) || empty($doc)) return $doc;

        // Map: camelCase → Title Case (only set if missing)
        $camelToTitle = [
            'name'             => 'Name',
            'fatherName'       => 'Father Name',
            'motherName'       => 'Mother Name',
            'phone'            => 'Phone Number',
            'phoneNumber'      => 'Phone Number',
            'email'            => 'Email',
            'className'        => 'Class',
            'section'          => 'Section',
            'rollNo'           => 'Roll No',
            'gender'           => 'Gender',
            'dob'              => 'DOB',
            'admissionDate'    => 'Admission Date',
            'status'           => 'Status',
            'profilePic'       => 'Profile Pic',
            'studentId'        => 'User Id',
            'bloodGroup'       => 'Blood Group',
            'category'         => 'Category',
            'religion'         => 'Religion',
            'nationality'      => 'Nationality',
            'fatherOccupation' => 'Father Occupation',
            'motherOccupation' => 'Mother Occupation',
            'guardContact'     => 'Guard Contact',
            'guardRelation'    => 'Guard Relation',
            'preClass'         => 'Pre Class',
            'preSchool'        => 'Pre School',
            'preMarks'         => 'Pre Marks',
            'address'          => 'Address',
            'documents'        => 'Doc',
        ];

        // Map: Title Case → camelCase
        $titleToCamel = [
            'Name'              => 'name',
            'Father Name'       => 'fatherName',
            'Mother Name'       => 'motherName',
            'Phone Number'      => 'phone',
            'Email'             => 'email',
            'Class'             => 'className',
            'Section'           => 'section',
            'Roll No'           => 'rollNo',
            'Gender'            => 'gender',
            'DOB'               => 'dob',
            'Admission Date'    => 'admissionDate',
            'Status'            => 'status',
            'Profile Pic'       => 'profilePic',
            'User Id'           => 'studentId',
            'Blood Group'       => 'bloodGroup',
            'Category'          => 'category',
            'Religion'          => 'religion',
            'Nationality'       => 'nationality',
            'Father Occupation' => 'fatherOccupation',
            'Mother Occupation' => 'motherOccupation',
            'Guard Contact'     => 'guardContact',
            'Guard Relation'    => 'guardRelation',
            'Pre Class'         => 'preClass',
            'Pre School'        => 'preSchool',
            'Pre Marks'         => 'preMarks',
            'Address'           => 'address',
            'Doc'               => 'documents',
        ];

        // Fill missing Title Case from camelCase
        foreach ($camelToTitle as $camel => $title) {
            if (!isset($doc[$title]) && isset($doc[$camel])) {
                $doc[$title] = $doc[$camel];
            }
        }

        // Fill missing camelCase from Title Case
        foreach ($titleToCamel as $title => $camel) {
            if (!isset($doc[$camel]) && isset($doc[$title])) {
                $doc[$camel] = $doc[$title];
            }
        }

        return $doc;
    }

    /**
     * Append an entry to the canonical studentHistory collection.
     *
     * History Canonicalization (2026-06-02): D3.B cutover landing.
     * Writes one document per history event keyed
     * {schoolId}_{userId}_{histKey}. Replaces the prior dotted-path
     * PATCH on students.{id}.History map (F2, 47913a6f, 2026-05-31).
     *
     * createDocument is idempotent (fails-if-exists); $histKey
     * collision (timestamp + 6 hex random) remains astronomically
     * unlikely — same risk profile as the legacy-map writer.
     */
    private function _log_history(
        string $schoolId,
        string $userId,
        string $action,
        string $description,
        array  $metadata = []
    ): void {
        // Phase 16.0 (R1) — the writer now lives in Student_history_service so
        // that lifecycle events raised OUTSIDE this controller can be audited
        // through the same code. This method was private, which is why
        // Classes::transfer_students() recorded nothing (CS-2) while the
        // structurally identical promotion was fully audited.
        //
        // Record shape, doc-id and histKey format are unchanged, so existing
        // rows and Student_timeline_reader are unaffected.
        $adminName = $this->session->userdata('admin_name') ?? 'System';
        $this->load->library('Student_history_service', null, 'studentHistorySvc');
        $this->studentHistorySvc
             ->init($this->firebase, (string) $adminName)
             ->log($schoolId, $userId, $action, $description, $metadata);
    }

    /**
     * G1 — Blank the current month's `attendanceSummary.dayWise` from
     * today through end-of-month, so a student's withdrawal / TC /
     * status flip mid-month doesn't leave inflated percentages or stale
     * `V` (vacant) days that the next teacher mark would overwrite.
     *
     * The chosen replacement char is `'V'` — same as the "no mark yet"
     * character used elsewhere — so the parent / teacher / admin
     * dashboards naturally read these days as "no working day for this
     * student" and the working-day count drops accordingly. After
     * blanking we recompute counters + percentage and merge-set the
     * doc; older days (1..today-1) and other fields are preserved.
     *
     * Best-effort: any failure is logged but never blocks the parent
     * lifecycle op (status flip succeeded → audit trail intact, the
     * summary catch-up can be done later via a maintenance script).
     */
    private function _blank_summary_from_today(string $studentId): void
    {
        try {
            $today    = (int) date('j');
            $year     = (int) date('Y');
            $monthNum = (int) date('n');
            $monthKey = sprintf('%04d-%02d', $year, $monthNum);
            $daysInMonth = (int) cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

            $docId = $this->fs->docId2($studentId, $monthKey);
            $doc   = $this->fs->get('attendanceSummary', $docId);
            if (!is_array($doc)) return;     // no summary yet → nothing to blank

            $dayWise = (string) ($doc['dayWise'] ?? '');
            $dayWise = str_pad($dayWise, $daysInMonth, 'V');
            if (strlen($dayWise) > $daysInMonth) {
                $dayWise = substr($dayWise, 0, $daysInMonth);
            }
            for ($d = $today - 1; $d < $daysInMonth; $d++) {
                $dayWise[$d] = 'V';
            }

            $present = $absent = $leave = $holiday = $tardy = $working = 0;
            for ($i = 0, $n = strlen($dayWise); $i < $n; $i++) {
                $ch = $dayWise[$i];
                if      ($ch === 'P') { $present++; $working++; }
                elseif  ($ch === 'A') { $absent++;  $working++; }
                elseif  ($ch === 'L') { $leave++;   $working++; }
                elseif  ($ch === 'H') { $holiday++;             }
                elseif  ($ch === 'T') { $tardy++;   $working++; }
            }
            $pct = $working > 0 ? round(($present + $tardy) / $working * 100, 1) : 0;

            $this->fs->set('attendanceSummary', $docId, [
                'dayWise'    => $dayWise,
                'present'    => $present,
                'absent'     => $absent,
                'leave'      => $leave,
                'holiday'    => $holiday,
                'tardy'      => $tardy,
                'percentage' => $pct,
                'updatedAt'  => date('c'),
                'updatedBy'  => $this->admin_id ?? 'system',
            ], true);
        } catch (\Exception $e) {
            log_message('error',
                "_blank_summary_from_today({$studentId}) failed: " . $e->getMessage()
            );
        }
    }

    /**
     * G2 — Recompute `sections.currentStrength` from the live count of
     * Active students in the (className, section) tuple, and merge-write
     * it onto the canonical sections doc.
     *
     * Cheap: one indexed `students` query per call (covered by the
     * deployed schoolId+className+section+status compound index) and
     * one merge-set on `sections/{schoolId}_{session}_{class}_{section}`.
     * Synchronous, no background job, no schema change.
     *
     * Best-effort: failures are logged and never block the parent
     * lifecycle op. The strength field is informational (drives the
     * admission section-picker bars) — staleness for one request is
     * acceptable; we'll be back in sync on the next lifecycle write.
     */
    private function _recompute_section_strength(string $classKey, string $sectionKey): void
    {
        try {
            $ck = Firestore_service::classKey($classKey);
            $sk = Firestore_service::sectionKey($sectionKey);
            if ($ck === '' || $sk === '') return;

            $rows = $this->fs->schoolWhere('students', [
                ['className', '==', $ck],
                ['section',   '==', $sk],
                ['status',    '==', 'Active'],
            ]);
            $count = is_array($rows) ? count($rows) : 0;

            $sectionDocId = $this->fs->sectionDocId($ck, $sk);
            // BUG (2026-05-28): only update strength on an EXISTING section.
            // set(merge=true) on a missing section created a field-less "ghost"
            // doc that blocked section creation ("already exists") while staying
            // invisible to the list query. Recompute must not materialize a
            // section — that's saveSection/activate_classes' responsibility.
            if (is_array($this->fs->get('sections', $sectionDocId))) {
                $this->fs->set('sections', $sectionDocId, [
                    'currentStrength' => $count,
                    'updatedAt'       => date('c'),
                ], true);
            }
        } catch (\Exception $e) {
            log_message('error',
                "G2 _recompute_section_strength({$classKey}/{$sectionKey}) failed: " . $e->getMessage()
            );
        }
    }

    /**
     * Preview the next student ID (read-only, does NOT increment).
     * Routes through Id_generator's STU_PEEK path so the preview and
     * the save-time generate() read the SAME Firestore pointer doc
     * (collection feeCounters, doc _sys_STU). Previously read from
     * Firestore_service::getCounter() which queried a separate
     * SYSTEM_COUNTERS/global doc that the post-2026-04-27 migration
     * stopped maintaining — the admission page mis-displayed STU0001
     * while the real save would correctly produce STU0012.
     *
     * Firestore-only path. No RTDB, no MongoDB, no Auth API.
     */
    private function _peekNextStudentId(string $schoolId): string
    {
        try {
            $this->load->library('id_generator');
            // Id_generator::generate('<PREFIX>_PEEK') is a first-class
            // read-only path (Id_generator.php:103-105 → _peek():591-595):
            // reads the pointer, does NOT increment, does NOT create a
            // claim doc, formats with canonical padding.
            $peek = $this->id_generator->generate('STU_PEEK');
            if (is_string($peek) && $peek !== '') return $peek;
        } catch (Exception $e) {
            log_message('error', 'peekNextStudentId failed: ' . $e->getMessage());
        }
        return 'STU****';
    }

    /**
     * Generate the next student ID via Id_generator (Firestore-backed
     * atomic claim-and-pointer counter). Globally unique — no two
     * students anywhere share the same ID. Only call this when actually
     * saving a student (not on page load).
     *
     * Counter is stored in Firestore collection feeCounters
     * (pointer doc _sys_STU + per-value claim docs _sys_STU_claim_{N}).
     * Migrated from RTDB on 2026-04-27 (Id_generator.php:45-48).
     * Firestore-only — no RTDB, no MongoDB, no Auth API.
     */
    private function _nextStudentId(string $schoolId): ?string
    {
        try {
            $this->load->library('id_generator');
            $userId = $this->id_generator->generate('STU');
            if ($userId) return $userId;
        } catch (Exception $e) {
            log_message('error', 'nextStudentId failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Get the next TC serial number and increment the counter.
     * Format: TC-{school_code}-{YYYY}-{0001}
     */
    private function _get_tc_number(string $schoolName): string
    {
        // Atomic per-school allocation via claim-doc CAS — closes the
        // read-increment-write race that could hand two concurrent TCs the
        // same number. Seeds from the legacy schools.tcCounter so numbering
        // continues without restart (no re-issue of already-used numbers).
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $current   = (int) ($schoolDoc['tcCounter'] ?? 0);

        $next = $this->fs->nextSchoolCounter('tc', $current);
        if ($next <= 0) {
            // SIS Wave-3 fix F4 (2026-05-31): the previous fallback computed
            // $current + 1 from a possibly-stale schools.tcCounter mirror
            // without claim-doc verification. If a prior call's mirror-write
            // had failed silently, the mirror was stale and this fallback
            // could compute a number ALREADY ISSUED by an earlier atomic
            // claim — duplicate TC. We now abort TC issuance instead. Atomic
            // unavailability is rare and operator-retryable; better to block
            // briefly than to issue a duplicate. Caller (issue_tc) must
            // check for empty return and surface a json_error.
            log_message('error',
                "Sis::_get_tc_number atomic counter unavailable; TC issuance aborted to prevent duplicate-number race from stale-mirror fallback. " .
                "Retry the operation; if persistent, investigate feeCounters/{$this->school_id}_tc pointer doc.");
            return '';
        }

        // Mirror into schools.tcCounter (monotonic) so existing verifiers
        // (Sis_canonical_verify / Sis_tier2_verify) stay coherent.
        // SIS Wave-3 fix F4: capture return + ERROR log on failure. Pre-fix,
        // a silent failure here left the mirror stale, which contributed to
        // the duplicate-number window in the (now-removed) fallback path.
        // The atomic claim doc remains the source of truth; mirror failure
        // does NOT block the current TC issuance (the claim is already
        // recorded) but the next-call's duplicate-number risk via fallback
        // is now closed (fallback removed above).
        if ($next > $current) {
            $mirrorOk = false;
            try {
                $mirrorOk = (bool) $this->fs->update('schools', $this->school_id, ['tcCounter' => $next]);
            } catch (\Throwable $e) {
                log_message('error', "Sis::_get_tc_number mirror update threw for tcCounter={$next}: " . $e->getMessage());
            }
            if (!$mirrorOk) {
                log_message('error',
                    "Sis::_get_tc_number mirror update returned false; schools.tcCounter is now stale relative to atomic claim ({$next}). " .
                    "Verifiers may report drift until next successful mirror write. The atomic claim doc feeCounters/{$this->school_id}_tc_claim_{$next} remains authoritative.");
            }
        }

        $year = date('Y');
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($schoolName, 0, 6)));
        return "TC-{$code}-{$year}-" . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Check if a student has outstanding (unpaid) fees.
     * Returns ['has_dues' => bool, 'total_due' => float, 'unpaid_months' => [...], 'summary' => string]
     *
     * Fee payment status: Students/{userId}/Month Fee/{month} → 0=unpaid, 1=paid
     * Fee amounts: Accounts/Fees/Classes Fees/Class {X}/Section {Y}/{month}/{feeType} → amount
     */
    private function _check_outstanding_dues(string $userId, array $student): array
    {
        // Comprehensive clearance check via Fee_defaulter_check (enhanced)
        try {
            $clearance = $this->feeDefaulter->calculateClearanceStatus($userId);
            if (!$clearance['all_clear']) {
                $modules = [];
                foreach (($clearance['modules'] ?? []) as $mod => $info) {
                    if (!empty($info['has_dues'])) {
                        $modules[] = $mod . ': ' . ($info['summary'] ?? 'dues pending');
                    }
                }
                return [
                    'has_dues'      => true,
                    'total_due'     => $clearance['total_due'] ?? 0,
                    'unpaid_months' => $clearance['unpaid_months'] ?? [],
                    'summary'       => !empty($modules) ? implode('; ', $modules) : ($clearance['summary'] ?? 'Outstanding dues found'),
                    'clearance'     => $clearance,
                ];
            }
        } catch (Exception $e) {
            log_message('error', "Fee_defaulter_check::calculateClearanceStatus failed: " . $e->getMessage());
            // Fall through to existing check as fallback
        }

        $school_name = $this->school_name;
        $session     = $this->session_year;
        // Accept both Title Case (legacy admission doc) and camelCase (Firestore
        // canonical from Entity_firestore_sync::syncStudent). Without the
        // fallback a student doc that only carries `className`/`section`
        // would short-circuit the dues check and silently allow a TC
        // through when the primary clearance service is unavailable.
        $classOrd    = trim($student['Class']   ?? $student['className'] ?? '');
        $sectionLtr  = trim($student['Section'] ?? $student['section']   ?? '');

        $result = ['has_dues' => false, 'total_due' => 0, 'unpaid_months' => [], 'summary' => ''];

        if ($classOrd === '' || $sectionLtr === '') return $result;

        // FIXED: use Firestore_service helpers (idempotent — safe if already prefixed)
        $classKey    = Firestore_service::classKey($classOrd);
        $sectionKey  = Firestore_service::sectionKey($sectionLtr);

        // Read student's month-wise payment status from student doc
        $studentDoc = $this->_getStudent($userId);
        $studentFees = $studentDoc['monthFee'] ?? $studentDoc['Month Fee'] ?? [];
        if (!is_array($studentFees)) $studentFees = [];

        // Read class fee structure from Firestore (docId includes session)
        $feeDocId = $this->fs->sectionDocId($classOrd, $sectionLtr);
        $feeStructDoc = $this->fs->get('feeStructures', $feeDocId);
        $classFees = $feeStructDoc['heads'] ?? $feeStructDoc ?? [];
        if (!is_array($classFees)) $classFees = [];

        $months = [
            'April','May','June','July','August','September',
            'October','November','December','January','February','March',
        ];

        $totalDue     = 0;
        $unpaidMonths = [];

        foreach ($months as $month) {
            $isPaid = (int)($studentFees[$month] ?? 0);
            if ($isPaid) continue; // already paid

            // Calculate this month's fee total from the class fee structure
            $monthFees = $classFees[$month] ?? [];
            if (!is_array($monthFees)) continue;

            $monthTotal = 0;
            foreach ($monthFees as $feeType => $amount) {
                $monthTotal += (float)$amount;
            }

            if ($monthTotal > 0) {
                $totalDue += $monthTotal;
                $unpaidMonths[] = $month;
            }
        }

        // Check yearly fees too
        $yearlyPaid = (int)($studentFees['Yearly Fees'] ?? 0);
        if (!$yearlyPaid) {
            $yearlyFees = $classFees['Yearly Fees'] ?? [];
            if (is_array($yearlyFees)) {
                $yearlyTotal = 0;
                foreach ($yearlyFees as $feeType => $amount) {
                    $yearlyTotal += (float)$amount;
                }
                if ($yearlyTotal > 0) {
                    $totalDue += $yearlyTotal;
                    $unpaidMonths[] = 'Yearly Fees';
                }
            }
        }

        if ($totalDue > 0) {
            $result['has_dues']      = true;
            $result['total_due']     = round($totalDue, 2);
            $result['unpaid_months'] = $unpaidMonths;

            $monthCount = count($unpaidMonths);
            $monthList  = implode(', ', array_slice($unpaidMonths, 0, 4));
            if ($monthCount > 4) $monthList .= ' +' . ($monthCount - 4) . ' more';
            $result['summary'] = "Outstanding dues: \u{20B9}" . number_format($totalDue, 2)
                               . " ({$monthCount} unpaid: {$monthList})";
        }

        return $result;
    }

    /**
     * Build map of enrolled student IDs for the current session.
     * Returns [ userId => true ]
     *
     * OPT 3: Single bulk read of the session root instead of 1 + C + S per-section reads.
     */
    /**
     * Cached raw snapshot of the school's full `students` collection.
     *
     * Same "smooth" pattern as Sis::search_student / Staff::all_staff — one
     * Firestore read per 90s instead of one per page visit, busted instantly
     * on any students write (see Firestore_service::_bustDashboard). Returns
     * the `[['id'=>…, 'data'=>[…]], …]` shape (schoolWhere), so callers that
     * read `$doc['data']` / `$doc['id']` work unchanged. Filtering by status /
     * session stays in PHP at each call site, which is exactly what the
     * read-only display pages (dashboard, ID cards, enrolled-id lookups)
     * already do — so this is a drop-in source swap, not a behaviour change.
     */
    private function _cached_student_docs(): array
    {
        $this->load->driver('cache', ['adapter' => 'file']);
        $cacheKey = $this->fs->listCacheKey('students_raw');
        $docs = $this->cache->get($cacheKey);
        if (!is_array($docs)) {
            $docs = $this->fs->schoolWhere('students');
            if (!is_array($docs)) $docs = [];
            $this->cache->save($cacheKey, $docs, 90);
        }
        return $docs;
    }

    private function _get_enrolled_ids(bool $includeNonActive = false): array
    {
        // Get students for this school. By default `status='Active'` only,
        // matching the legacy contract. When `$includeNonActive` is true
        // we accept any non-`Deleted` status so the students-list page
        // can surface Inactive / TC rows for review + reactivation —
        // pre-fix an Inactive student was invisible the moment admin
        // flipped the status, leaving no UI path back.
        //
        // Field-name compat:
        //   - 'status' (camelCase, new docs)
        //   - 'Status' (Title Case, legacy docs)
        //   - 'session' (single string) or 'sessions' (array)
        //
        // Sourced from the cached full-collection snapshot; the status gate
        // that used to be a Firestore `where` is applied in PHP below (it
        // checks both field-name casings, so it's strictly more tolerant
        // than the old camelCase-then-TitleCase fallback query).
        $studentDocs = $this->_cached_student_docs();

        $enrolledIds = [];
        $currentSession = $this->session_year;
        foreach ($studentDocs as $doc) {
            $d   = $doc['data'];
            $uid = $d['studentId'] ?? $d['User Id'] ?? $d['userId'] ?? $d['id'];

            // Status gate (now applied in PHP since the snapshot is unfiltered):
            //   - default        → Active only (legacy contract)
            //   - includeNonActive→ any status except hard-deleted
            $rowStatus = (string) ($d['status'] ?? $d['Status'] ?? 'Active');
            if ($includeNonActive) {
                if (strcasecmp($rowStatus, 'Deleted') === 0) continue;
            } else {
                if (strcasecmp($rowStatus, 'Active') !== 0) continue;
            }

            // Check session enrollment: canonical `session` singular takes
            // precedence; legacy `sessions[]` array is honored ONLY when the
            // singular field is absent/empty.
            // SW-CONVERGE-STUDENTS-A (2026-05-26): see Sis.php:2036 for full
            // rationale. v7 target = singular session as sole student-side
            // authority across all filter sites.
            $session  = $d['session']  ?? null;
            $sessions = $d['sessions'] ?? null;
            if (is_string($session) && $session !== '') {
                if ($session !== $currentSession) continue;
            } elseif (is_array($sessions) && !empty($sessions)) {
                if (!in_array($currentSession, $sessions, true)) continue;
            }
            // else: no enrollment record on doc — preserve prior behavior
            // (do not skip; caller may apply additional gates).

            $enrolledIds[$uid] = true;
        }
        return $enrolledIds;
    }

    /**
     * Get students enrolled in a specific class (and optionally section).
     * Returns [ userId => ['name'=>..., 'class'=>..., 'section'=>...] ]
     */
    private function _get_students_in_class(
        string $classOrd,
        string $section,
        string $session
    ): array {
        $classKey = Firestore_service::classKey($classOrd);
        // Scope by session too — without this, bulk operations pull students
        // whose record still references the queried class but whose session
        // has already moved on (e.g. pending-rollback cases, Alumni with
        // stale className).
        $conditions = [
            ['className', '==', $classKey],
            ['status',    '==', 'Active'],
            ['session',   '==', $session],
        ];
        if ($section && $section !== 'all') {
            $sectionKey = Firestore_service::sectionKey($section);
            $conditions[] = ['section', '==', $sectionKey];
        }
        $studentDocs = $this->fs->schoolWhere('students', $conditions, 'name', 'ASC');

        $students = [];
        foreach ($studentDocs as $doc) {
            $d = $doc['data'] ?? $doc;
            $s = $doc['data'];
            $uid = $s['studentId'] ?? $s['User Id'] ?? $d['id'];
            $students[$uid] = [
                'user_id' => $uid,
                'name'    => $s['name'] ?? $s['Name'] ?? $uid,
                'class'   => $s['className'] ?? $s['Class'] ?? $classOrd,
                'section' => $s['section'] ?? $s['Section'] ?? $section,
            ];
        }
        return $students;
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT LIST (legacy all_student view)
       Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function all_student()
    {
        redirect('sis/students');
    }

    /* ══════════════════════════════════════════════════════════════════════
       BULK IMPORT — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function master_student()
    {
        $this->_require_role(self::VIEW_ROLES, 'master_student', 'SIS', 'view');
        $this->load->view('include/header');
        $this->load->view('import_students');
        $this->load->view('include/footer');
    }

    /**
     * Legacy direct-upload import (exact-template path). Parses the file and
     * runs every row through the shared importer. Kept as a fallback; the
     * primary UX is now the map → validate → commit flow (import_preview /
     * import_commit), which accepts any column layout.
     */
    public function import_students()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');
        try {
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) log_message('debug', '=== IMPORT FUNCTION STARTED ===');

            if (!isset($_FILES['excelFile']) || $_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
                redirect('sis/all_student');
                return;
            }

            $file = $_FILES['excelFile'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $reader = ($extension === 'csv') ? IOFactory::createReader('Csv') : IOFactory::createReader('Xlsx');
            $spreadsheet = $reader->load($file['tmp_name']);
            $sheetData   = $spreadsheet->getActiveSheet()->toArray();

            if (count($sheetData) <= 1) {
                redirect('sis/all_student');
                return;
            }

            $headers = array_map('trim', $sheetData[0]);
            unset($sheetData[0]);
            $sheetData = array_values($sheetData);

            // Build canonical associative rows (exact-template: header names
            // already match the canonical keys) and hand to the shared importer.
            $rows = [];
            foreach ($sheetData as $row) {
                if (!array_filter($row)) continue;
                if (count($headers) != count($row)) continue;
                $rows[] = array_combine($headers, $row);
            }

            $res = $this->_runStudentImport($rows);
            $this->session->set_flashdata('import_result', $this->_formatImportSummary($res));
            redirect('sis/all_student');
        } catch (Exception $e) {
            log_message('error', 'IMPORT ERROR: ' . $e->getMessage());
            $this->session->set_flashdata('import_result', "Import Failed! Check logs.");
            redirect('sis/all_student');
        }
    }

    /**
     * Shared per-row student persistence. Accepts a list of associative rows
     * keyed by canonical field names ("Name", "Class", "Section", "DOB", …).
     * Both the legacy exact-template path and the new mapped/validated commit
     * path funnel through here, so admission/fee/Auth/sync semantics stay
     * identical regardless of how the file was shaped.
     *
     * Returns ['success' => int, 'error' => int, 'skipped' => string[], 'feesPending' => string[]].
     */
    private function _runStudentImport(array $rows, array $opts = []): array
    {
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        // Duplicate policy (Phase 3, 2026-06-12):
        //   'create' — always create (legacy behaviour; default so the direct
        //              exact-template path is byte-for-byte unchanged).
        //   'skip'   — if a student with the same phone already exists (in the
        //              DB or earlier in this file), don't create; report it.
        //   'update' — merge the row's contact/demographic fields onto the
        //              existing student (class/section/status/fees preserved).
        // Default to the SAFE 'skip' (was 'create'): the legacy one-shot
        // import_students() path passes no opts, so defaulting to 'create' there
        // meant a re-run silently duplicated the whole roster. Callers that
        // truly want create-always must request it explicitly.
        $dupPolicy = in_array(($opts['dupPolicy'] ?? 'skip'), ['create', 'skip', 'update'], true)
            ? $opts['dupPolicy'] : 'skip';

        $success     = 0;
        $error       = 0;
        $skipped     = [];
        $feesPending = []; // imported students whose class/section has no fee chart yet
        $duplicates  = []; // rows skipped as duplicates (skip policy)
        $updated     = 0;  // existing students updated (update policy)
        $credentials = []; // {name,phone,userId,password} per newly-created student (for the handout PDF)

        $seenPhones  = []; // phone(10) => studentId created earlier in THIS file
        $seenNameDob = []; // "name|dob" => studentId created earlier in THIS file

        // Each row does ~13-15 sequential network writes. Relax the limit on
        // EVERY call — the commit is chunked into 25-row batches, so the old
        // `> 50` guard never fired on the chunked path, leaving each batch
        // exposed to the default max_execution_time.
        @set_time_limit(0);
        @ignore_user_abort(true);

        // Load ID generator for globally unique student IDs
        $this->load->library('id_generator');

        $subjectCache = [];

        foreach ($rows as $idx => $rowData) {
            if (!is_array($rowData)) { $error++; continue; }
            $rowNo = $idx + 1;

                $studentName = trim($rowData['Name'] ?? '');
                $classRaw    = trim($rowData['Class'] ?? '');
                $section     = trim($rowData['Section'] ?? '');
                if (!$studentName || !$classRaw || !$section) {
                    $skipped[] = "Row {$rowNo}: missing required Name/Class/Section";
                    $error++;
                    continue;
                }

                preg_match('/\d+/', $classRaw, $match);
                if (!isset($match[0])) {
                    $skipped[] = "Row {$rowNo}: {$studentName} — Class \"{$classRaw}\" has no recognizable number (1–12)";
                    $error++;
                    continue;
                }
                $classNumber = (int)$match[0];

                // ── Duplicate detection (skip/update policies) ───────────────
                $phone10 = preg_replace('/\D+/', '', (string) ($rowData['Phone Number'] ?? ''));
                if (strlen($phone10) > 10) $phone10 = substr($phone10, -10);
                $dobKey  = trim((string) ($rowData['DOB'] ?? ''));
                $nameKey = strtolower($studentName) . '|' . $dobKey;

                if ($dupPolicy !== 'create') {
                    $existingId = null; $dupReason = '';
                    if ($phone10 !== '' && strlen($phone10) === 10) {
                        if (isset($seenPhones[$phone10])) {
                            $existingId = $seenPhones[$phone10]; $dupReason = 'duplicate phone within file';
                        } else {
                            $dbId = $this->_existingStudentIdByPhone($phone10);
                            if ($dbId) { $existingId = $dbId; $dupReason = 'existing student (phone match)'; }
                        }
                    }
                    if (!$existingId && $dobKey !== '' && isset($seenNameDob[$nameKey])) {
                        $existingId = $seenNameDob[$nameKey]; $dupReason = 'duplicate name+DOB within file';
                    }

                    if ($existingId) {
                        if ($dupPolicy === 'update') {
                            if ($this->_updateExistingStudent($existingId, $rowData)) {
                                $updated++;
                            } else {
                                $skipped[] = "Row {$rowNo}: {$studentName} — update of {$existingId} failed (see log)";
                                $error++;
                            }
                        } else {
                            $duplicates[] = "Row {$rowNo}: {$studentName} — {$dupReason} ({$existingId})";
                        }
                        continue;
                    }
                }

                $suffix = 'th';
                if (!in_array(($classNumber % 100), [11, 12, 13])) {
                    switch ($classNumber % 10) {
                        case 1: $suffix = 'st'; break;
                        case 2: $suffix = 'nd'; break;
                        case 3: $suffix = 'rd'; break;
                    }
                }

                $className = Firestore_service::classKey($classNumber . $suffix);
                $section   = Firestore_service::sectionKey($section);
                $combinedClass = "{$className}/{$section}";

                // Phase 1 (2026-06-12): admission is no longer hard-blocked on a
                // missing fee chart. The earlier B6 guard (2026-05-31) skipped
                // the whole row when feeStructures/{school}_{session}_{class}_{section}
                // was absent, which meant a school that imports its roster BEFORE
                // configuring Fees → Chart got zero students imported and a
                // summary it never saw (redirect target didn't render it).
                //
                // New behaviour: import the student as Active regardless, and
                // record rows with no chart in $feesPending so the summary can
                // tell the operator which class/sections still need a chart.
                // assignInitialFees() below is a graceful no-op when the chart
                // is absent and is re-runnable once Fees → Chart is set up, which
                // back-fills demands — so no student is left silently un-billable.
                $destFeeStructDocId = "{$school_name}_{$session_year}_{$className}_{$section}";
                $destFeeStruct      = $this->fs->get('feeStructures', $destFeeStructDocId);
                $hasFeeChart        = is_array($destFeeStruct) && !empty($destFeeStruct['feeHeads']);

                // Generate globally unique student ID from central counter
                $studentId = $this->_nextStudentId($school_id);
                if (!$studentId) {
                    $skipped[] = "Row {$rowNo}: {$studentName} — ID generation failed";
                    $error++;
                    continue;
                }

                $formattedDOB = '';
                if (!empty($rowData['DOB'])) $formattedDOB = date('d-m-Y', strtotime($rowData['DOB']));
                $formattedAdmDate = '';
                if (!empty($rowData['Admission Date'])) $formattedAdmDate = date('d-m-Y', strtotime($rowData['Admission Date']));

                $password = $this->_generatePassword($studentName, $formattedDOB);

                $studentData = [
                    "Name" => $studentName, "User Id" => $studentId, "DOB" => $formattedDOB,
                    "Admission Date" => $formattedAdmDate, "Class" => $className, "Section" => $section,  // Already prefixed via classKey/sectionKey
                    "Gender" => trim($rowData['Gender'] ?? ''), "Blood Group" => trim($rowData['Blood Group'] ?? ''),
                    "Category" => trim($rowData['Category'] ?? ''), "Religion" => trim($rowData['Religion'] ?? ''),
                    "Nationality" => trim($rowData['Nationality'] ?? ''),
                    "Father Name" => trim($rowData['Father Name'] ?? ''), "Father Occupation" => trim($rowData['Father Occupation'] ?? ''),
                    "Mother Name" => trim($rowData['Mother Name'] ?? ''), "Mother Occupation" => trim($rowData['Mother Occupation'] ?? ''),
                    "Guard Contact" => trim($rowData['Guard Contact'] ?? ''), "Guard Relation" => trim($rowData['Guard Relation'] ?? ''),
                    "Phone Number" => trim($rowData['Phone Number'] ?? ''), "Email" => trim($rowData['Email'] ?? ''),
                    "Password" => $password,
                    "Address" => [
                        "Street" => trim($rowData['Street'] ?? ''), "City" => trim($rowData['City'] ?? ''),
                        "State" => trim($rowData['State'] ?? ''), "PostalCode" => trim($rowData['PostalCode'] ?? ''),
                    ],
                    "Pre School" => trim($rowData['Pre School'] ?? ''), "Pre Class" => trim($rowData['Pre Class'] ?? ''),
                    "Pre Marks" => trim($rowData['Pre Marks'] ?? ''),
                    "Profile Pic" => "",
                    "Status" => "Active",
                    "Doc" => [
                        "Aadhar Card" => ["thumbnail" => "", "url" => ""],
                        "Birth Certificate" => ["thumbnail" => "", "url" => ""],
                        "Photo" => ["thumbnail" => "", "url" => ""],
                        "Transfer Certificate" => ["thumbnail" => "", "url" => ""],
                    ],
                ];

                // Firestore-only per no-RTDB policy. RTDB profile + roster mirror removed.
                // SIS Tier-1 fix B2 (2026-05-31): saveStudent must be fail-loud
                // per row. Pre-fix, this call had no try/catch and no return
                // check — a Firestore failure either threw (killing the whole
                // import mid-loop via the outer catch) or returned false
                // silently (loop continued, row was counted as $success, but
                // the student doc never existed). The import summary lied
                // about which rows imported.
                // Mirrors the B1 pattern from enroll_student@4627; the
                // loop-shape difference is that a per-row failure increments
                // $error and adds a $skipped[] entry then `continue`s to the
                // next row, instead of aborting the entire import.
                $studentSaved = false;
                try {
                    $studentSaved = (bool) $this->fs->saveStudent($studentId, $studentData);
                } catch (\Exception $e) {
                    log_message('error', "SIS import saveStudent failed for {$studentId}: " . $e->getMessage());
                }
                if (!$studentSaved) {
                    // Release the claimed STU id so a failed write doesn't burn the number.
                    $this->id_generator->releaseClaim('STU', (int) preg_replace('/\D/', '', $studentId));
                    $skipped[] = "Row {$rowNo}: {$studentName} — Firestore save failed (see log)";
                    $error++;
                    continue;
                }

                $phone = trim($rowData['Phone Number'] ?? '');
                if ($phone !== '') {
                    $this->CM->addKey_pair_data("Schools/{$school_name}/Phone_Index/", [$phone => $studentId]);
                    $this->CM->addKey_pair_data('Exits/', [$phone => $school_id]);
                    $this->CM->addKey_pair_data('User_ids_pno/', [$phone => $studentId]);
                    // Firestore dual-write: phone index
                    try {
                        $this->fs->set('indexPhones', $this->fs->docId($phone), [
                            'schoolId' => $this->school_id, 'phone' => $phone,
                            'userId' => $studentId, 'type' => 'student',
                        ]);
                    } catch (\Exception $e) { log_message('error', "Firestore dual-write indexPhones failed: " . $e->getMessage()); }
                }

                // Update Students_Index
                $this->_update_student_index($school_name, $studentId, $studentName, $className, $section, 'Active', trim($rowData['Gender'] ?? ''));

                // Initialize Month Fee markers as unpaid (0) for all 12 months
                // SIS Wave-2 fix F5 (2026-05-31): fail-loud guard per row.
                // Pre-fix, a Firestore failure here was logged but the loop
                // continued — row counted as $success while student doc had
                // no monthFee. Current ordering already places this BEFORE
                // Firebase Auth at L3336+, so a per-row skip cleanly aborts
                // before any Auth/sync side-effect (Option-3 intent achieved
                // without reorder — current code is already correct).
                $classKey   = $className;   // Already prefixed ("Class 8th")
                $sectionKey = $section;    // Already prefixed ("Section A")
                $studentFeePath = "Schools/{$school_name}/{$session_year}/{$classKey}/{$sectionKey}/Students/{$studentId}";
                $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
                $monthFeeInit = [];
                foreach ($months as $m) {
                    $monthFeeInit[$m] = 0;
                }
                $monthFeeOk = false;
                try {
                    // Firestore-only per no-RTDB policy.
                    $monthFeeOk = (bool) $this->fs->updateEntity('students', $studentId, ['monthFee' => $monthFeeInit]);
                } catch (Exception $e) {
                    log_message('error', "SIS import fee init failed for {$studentId}: " . $e->getMessage());
                }
                if (!$monthFeeOk) {
                    $skipped[] = "Row {$rowNo}: {$studentName} — monthFee init failed (see log); student doc remains without fee tracking";
                    $error++;
                    continue;
                }

                // Auto-assign class fees for imported student
                try {
                    $this->feeLifecycle->assignInitialFees($studentId, $className, $section, $school_id);
                } catch (Exception $e) {
                    log_message('error', "Fee_lifecycle bulk import fee assign failed for {$studentId}: " . $e->getMessage());
                }

                // Subject assignment
                if (!isset($subjectCache[$classNumber])) {
                    $subjectCache[$classNumber] = ['core' => [], 'allSubjects' => [], 'additionalSubjects' => []];
                    // Firestore first → RTDB fallback
                    $rawList = [];
                    $fsDocs = $this->fs->schoolWhere('subjects', [['classKey', '==', (string)$classNumber]]);
                    if (is_array($fsDocs) && !empty($fsDocs)) {
                        foreach ($fsDocs as $doc) {
                            $d = $doc['data'] ?? $doc;
                            $code = $d['subject_code'] ?? $d['code'] ?? '';
                            if ($code !== '') $rawList[$code] = $d;
                        }
                    }
                    // RTDB subject fallback removed per no-RTDB policy.
                    if (is_array($rawList)) {
                        foreach ($rawList as $code => $item) {
                            if (!is_array($item)) continue;
                            $subName = trim($item['subject_name'] ?? $item['name'] ?? '');
                            if ($subName === '') continue;
                            $type = strtolower(trim($item['category'] ?? ''));
                            if ($type === 'additional') {
                                $subjectCache[$classNumber]['additionalSubjects'][$subName] = "";
                            } else {
                                $subjectCache[$classNumber]['allSubjects'][(string)$code] = $subName;
                                if ($type === 'core') {
                                    $subjectCache[$classNumber]['core'][(string)$code] = ['name' => $subName, 'type' => 'core'];
                                }
                            }
                        }
                    }
                    // RTDB All Subjects mirror removed per no-RTDB policy.
                }

                if (!empty($subjectCache[$classNumber]['core'])) {
                    // Firestore-only per no-RTDB policy.
                    $this->fs->updateEntity('students', $studentId, ['subjects' => $subjectCache[$classNumber]['core']]);
                }
                if (!empty($subjectCache[$classNumber]['additionalSubjects'])) {
                    $this->fs->updateEntity('students', $studentId, ['additionalSubjects' => $subjectCache[$classNumber]['additionalSubjects']]);
                }

                // Create Firebase Auth user (best-effort, don't block import on failure)
                // SIS Wave-3 D3 (2026-05-31): consolidated via _createFirebaseAuthStudent.
                // Return value intentionally ignored — import_students's pre-fix
                // behavior was silent-continue per row on Auth failure. Each row
                // becomes a separate Auth attempt; one row's failure doesn't
                // abort the rest of the import (matches B2 per-row pattern).
                $this->_createFirebaseAuthStudent($studentId, $password, $studentName, 'SIS import_students');

                // Firestore sync for Android apps (entity_sync loaded in constructor)
                // SIS Wave-2 S6 (2026-05-31): observability for sync return.
                if (!$this->entity_sync->syncStudent($studentId, $studentData)) {
                    log_message('warning', "syncStudent returned false for {$studentId} (import_students)");
                }
                if (!$this->entity_sync->syncParent($studentId, $studentData)) {
                    log_message('warning', "syncParent returned false for {$studentId} (import_students)");
                }

                // SIS Tier 2 carry (2026-05-30): emit ADMISSION history entry
                // mirroring save_admission@536. Without this, students imported
                // via the bulk-Excel path had no ADMISSION row in their History
                // array (forensic-documented gap).
                $this->_log_history($school_id, $studentId, 'ADMISSION',
                    "Student admitted to {$className} / {$section} ({$session_year})",
                    ['class' => $className, 'section' => $section, 'session' => $session_year]
                );

                // Imported, but flag if this class/section had no fee chart so the
                // operator knows to set one up (Fees → Chart) to generate demands.
                if (!$hasFeeChart) {
                    $feesPending[] = "{$studentName} ({$className}/{$section})";
                }

                // Record keys so a later row in the SAME file matching this
                // student (skip/update policies) is caught as a duplicate.
                if ($phone10 !== '' && strlen($phone10) === 10) $seenPhones[$phone10] = $studentId;
                if ($dobKey !== '') $seenNameDob[$nameKey] = $studentId;

                // Capture the login handout row (plaintext password is only known
                // here at creation time). Missing phone stays '' → blank in the PDF.
                $credentials[] = [
                    'name'     => $studentName,
                    'phone'    => $phone,
                    'userId'   => $studentId,
                    'password' => $password,
                ];

                $success++;
        }

        return [
            'success'     => $success,
            'error'       => $error,
            'skipped'     => $skipped,
            'feesPending' => $feesPending,
            'duplicates'  => $duplicates,
            'updated'     => $updated,
            'credentials' => $credentials,
        ];
    }

    /**
     * Look up an existing student in this school by 10-digit phone via the
     * indexPhones index. Returns the studentId or null. (Phase 3 dedup.)
     */
    private function _existingStudentIdByPhone(string $phone): ?string
    {
        if ($phone === '') return null;
        try {
            $rec = $this->fs->get('indexPhones', $this->fs->docId($phone));
            if (is_array($rec)) {
                $uid  = (string) ($rec['userId'] ?? '');
                $type = (string) ($rec['type'] ?? 'student');
                if ($uid !== '' && $type === 'student') return $uid;
            }
        } catch (\Throwable $e) {
            log_message('error', 'dedup phone lookup failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Merge a row's contact/demographic fields onto an existing student
     * (update policy). Class/Section/Status/Password/fees are PRESERVED —
     * this is a profile refresh, not a promotion or re-admission, so it never
     * touches fee state. saveStudent() is a merge-write, so untouched fields
     * (Doc, Profile Pic, subjects, monthFee) survive. Returns true on success.
     */
    private function _updateExistingStudent(string $existingId, array $rowData): bool
    {
        try {
            $existing = $this->fs->get('students', $this->fs->docId($existingId));
            if (!is_array($existing)) $existing = [];

            $keepClass   = (string) ($existing['Class']   ?? $existing['className'] ?? '');
            $keepSection = (string) ($existing['Section'] ?? $existing['section']   ?? '');
            $keepStatus  = (string) ($existing['Status']  ?? $existing['status']    ?? 'Active');
            $keepPass    = (string) ($existing['Password'] ?? '');

            // Prefer incoming non-empty value, else keep what's on the doc.
            $val = function (string $k) use ($rowData, $existing) {
                $v = trim((string) ($rowData[$k] ?? ''));
                return $v !== '' ? $v : (string) ($existing[$k] ?? '');
            };
            $ea = is_array($existing['Address'] ?? null) ? $existing['Address'] : [];
            $addr = function (string $k) use ($rowData, $ea) {
                $v = trim((string) ($rowData[$k] ?? ''));
                return $v !== '' ? $v : (string) ($ea[$k] ?? '');
            };

            $dob = trim((string) ($rowData['DOB'] ?? ''));
            $formattedDOB = $dob !== '' ? date('d-m-Y', strtotime($dob)) : (string) ($existing['DOB'] ?? '');
            $adm = trim((string) ($rowData['Admission Date'] ?? ''));
            $formattedAdm = $adm !== '' ? date('d-m-Y', strtotime($adm)) : (string) ($existing['Admission Date'] ?? '');

            $merged = [
                'Name' => $val('Name'), 'User Id' => $existingId, 'DOB' => $formattedDOB,
                'Admission Date' => $formattedAdm, 'Class' => $keepClass, 'Section' => $keepSection,
                'Status' => $keepStatus, 'Password' => $keepPass,
                'Gender' => $val('Gender'), 'Blood Group' => $val('Blood Group'),
                'Category' => $val('Category'), 'Religion' => $val('Religion'), 'Nationality' => $val('Nationality'),
                'Father Name' => $val('Father Name'), 'Father Occupation' => $val('Father Occupation'),
                'Mother Name' => $val('Mother Name'), 'Mother Occupation' => $val('Mother Occupation'),
                'Guard Contact' => $val('Guard Contact'), 'Guard Relation' => $val('Guard Relation'),
                'Phone Number' => $val('Phone Number'), 'Email' => $val('Email'),
                'Address' => [
                    'Street' => $addr('Street'), 'City' => $addr('City'),
                    'State' => $addr('State'), 'PostalCode' => $addr('PostalCode'),
                ],
                'Pre School' => $val('Pre School'), 'Pre Class' => $val('Pre Class'), 'Pre Marks' => $val('Pre Marks'),
            ];

            if (!$this->fs->saveStudent($existingId, $merged)) return false;

            try {
                $this->entity_sync->syncStudent($existingId, $merged);
                $this->entity_sync->syncParent($existingId, $merged);
            } catch (\Throwable $e) {
                log_message('warning', "import update sync failed for {$existingId}: " . $e->getMessage());
            }

            // Refresh phone index if a phone is present.
            $ph = preg_replace('/\D+/', '', $merged['Phone Number']);
            if (strlen($ph) > 10) $ph = substr($ph, -10);
            if ($ph !== '' && strlen($ph) === 10) {
                try {
                    $this->fs->set('indexPhones', $this->fs->docId($ph), [
                        'schoolId' => $this->school_id, 'phone' => $ph,
                        'userId' => $existingId, 'type' => 'student',
                    ]);
                } catch (\Throwable $e) { /* index refresh best-effort */ }
            }

            $this->_log_history($this->parent_db_key, $existingId, 'UPDATE',
                'Profile updated via bulk import', []);

            return true;
        } catch (\Throwable $e) {
            log_message('error', "import update failed for {$existingId}: " . $e->getMessage());
            return false;
        }
    }

    /** Build the human-readable one-line summary shown after an import. */
    private function _formatImportSummary(array $res): string
    {
        $success     = (int) ($res['success'] ?? 0);
        $error       = (int) ($res['error'] ?? 0);
        $updated     = (int) ($res['updated'] ?? 0);
        $skipped     = is_array($res['skipped'] ?? null) ? $res['skipped'] : [];
        $feesPending = is_array($res['feesPending'] ?? null) ? $res['feesPending'] : [];
        $duplicates  = is_array($res['duplicates'] ?? null) ? $res['duplicates'] : [];

        $msg = "Imported Successfully: {$success} | Failed: {$error}";
        if ($updated > 0) {
            $msg .= " | Updated existing: {$updated}";
        }
        if (!empty($duplicates)) {
            $msg .= " | Duplicates skipped: " . count($duplicates) . " — " . implode('; ', $duplicates);
        }
        if (!empty($feesPending)) {
            $msg .= " | Fees pending for " . count($feesPending)
                 . " (no fee chart yet — set up Fees → Chart for these class/sections, then demands auto-assign): "
                 . implode('; ', $feesPending);
        }
        if (!empty($skipped)) {
            $msg .= " | Skipped: " . count($skipped) . " — " . implode('; ', $skipped);
        }
        return $msg;
    }

    /* ══════════════════════════════════════════════════════════════════════
       FLEXIBLE IMPORT (Phase 2, 2026-06-12) — map → validate → commit.
       Accepts any column layout: the uploaded file's headers are fuzzy-mapped
       to canonical fields (Import_schema), values are normalized, and every
       row is dry-run validated in a preview before a single write happens.
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Step 1 — parse the uploaded file and render the mapping + preview UI.
     * No writes. The parsed rows are embedded in the page; the browser drives
     * mapping/validation against import_validate, then commits.
     */
    public function import_preview()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');

        // Single-page flow: the upload posts via AJAX and expects JSON back;
        // a plain (no-JS) POST still renders the standalone map view as before.
        $isAjax = $this->input->is_ajax_request();
        $fail = function ($msg) use ($isAjax) {
            if ($isAjax) {
                $this->output->set_content_type('application/json')
                     ->set_output(json_encode(['status' => 'error', 'message' => $msg]));
            } else {
                $this->session->set_flashdata('import_result', $msg);
                redirect('sis/master_student');
            }
        };

        if (!isset($_FILES['excelFile']) || $_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
            $fail('No file received, or the upload failed. Please choose a CSV or XLSX file.');
            return;
        }

        $file      = $_FILES['excelFile'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx', 'xls'], true)) {
            $fail('Unsupported file type. Please upload a .csv or .xlsx file.');
            return;
        }

        try {
            $reader      = ($extension === 'csv') ? IOFactory::createReader('Csv') : IOFactory::createReader('Xlsx');
            $spreadsheet = $reader->load($file['tmp_name']);
            // Formatted values, numeric column indices.
            $sheetData   = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            log_message('error', 'IMPORT PREVIEW read error: ' . $e->getMessage());
            $fail('Could not read the file. It may be corrupt or password-protected.');
            return;
        }

        // Drop fully-empty rows; first remaining row is the header.
        $sheetData = array_values(array_filter($sheetData, function ($r) {
            if (!is_array($r)) return false;
            foreach ($r as $v) { if (trim((string) $v) !== '') return true; }
            return false;
        }));

        if (count($sheetData) < 2) {
            $fail('The file has no data rows below the header.');
            return;
        }

        $headers  = array_map(function ($h) { return trim((string) $h); }, $sheetData[0]);
        $dataRows = array_slice($sheetData, 1);

        // Normalise ragged rows to header width so column alignment holds.
        $width = count($headers);
        foreach ($dataRows as &$r) {
            $r = array_map(function ($v) { return (string) $v; }, array_values($r));
            if (count($r) < $width) $r = array_pad($r, $width, '');
            elseif (count($r) > $width) $r = array_slice($r, 0, $width);
        }
        unset($r);

        // v1 cap — very large files belong on a background job (Phase 3).
        $cap    = 1500;
        $capped = count($dataRows) > $cap;
        if ($capped) $dataRows = array_slice($dataRows, 0, $cap);

        $this->load->library('import_schema');

        // Apply this school's learned header→field mappings from past imports.
        $learned = $this->_loadImportMapping();

        $data = [
            'headers'        => $headers,
            'rows'           => $dataRows,
            'autoMap'        => $this->import_schema->autoMap($headers, $learned),
            'schema'         => $this->import_schema->fieldsForUi(),
            'capped'         => $capped,
            'capLimit'       => $cap,
            'fileName'       => $file['name'],
            'learnedApplied' => $this->import_schema->learnedHitCount($headers, $learned),
        ];

        if ($isAjax) {
            $data['status'] = 'success';
            $this->output->set_content_type('application/json')->set_output(json_encode($data));
            return;
        }

        $this->load->view('include/header');
        $this->load->view('import_students_map', $data);
        $this->load->view('include/footer');
    }

    /**
     * Step 2 — dry-run validation (AJAX). Receives canonical rows as a JSON
     * string in the `payload` POST field; returns per-row normalized values
     * and OK/warning/error status. No writes.
     */
    public function import_validate()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');
        $this->output->set_content_type('application/json');

        $payload = json_decode((string) $this->input->post('payload'), true);
        $rows    = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        $this->load->library('import_schema');

        $out = [];
        $ok = 0; $warn = 0; $err = 0;
        foreach ($rows as $r) {
            $v = $this->import_schema->validateRow(is_array($r) ? $r : []);
            if ($v['status'] === 'error') $err++;
            elseif ($v['status'] === 'warning') $warn++;
            else $ok++;
            $out[] = $v;
        }

        echo json_encode([
            'rows'    => $out,
            'summary' => ['ok' => $ok, 'warning' => $warn, 'error' => $err, 'total' => count($rows)],
        ]);
    }

    /**
     * Step 3 — commit (AJAX). Receives canonical rows (`payload` JSON field),
     * re-validates server-side (never trust the client), imports every non-error
     * row via the shared importer, and returns the summary as JSON.
     */
    public function import_commit()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');
        $this->output->set_content_type('application/json');

        $payload = json_decode((string) $this->input->post('payload'), true);
        $rows    = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        // Duplicate policy chosen in the UI; default to the safe 'skip'.
        // 'create' is intentionally NOT accepted here — it would bypass dedupe
        // and let a re-run of the same file create a full duplicate roster. The
        // flexible (preview) path must only skip or update existing students.
        $dupPolicy = in_array(($payload['dupPolicy'] ?? 'skip'), ['skip', 'update'], true)
            ? $payload['dupPolicy'] : 'skip';

        if (empty($rows)) {
            echo json_encode(['status' => 'error', 'message' => 'No rows to import.']);
            return;
        }

        $this->load->library('import_schema');

        // Re-validate authoritatively; only OK/warning rows are written.
        $toImport  = [];
        $preErrors = 0;
        foreach ($rows as $r) {
            $v = $this->import_schema->validateRow(is_array($r) ? $r : []);
            if ($v['status'] === 'error') { $preErrors++; continue; }
            $toImport[] = $v['data'];
        }

        try {
            $res = $this->_runStudentImport($toImport, ['dupPolicy' => $dupPolicy]);
        } catch (\Throwable $e) {
            log_message('error', 'IMPORT COMMIT error: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Import failed — check server logs.']);
            return;
        }

        // Learn this school's column layout from a successful import so the next
        // upload of the same format auto-maps. Only persist when something was
        // actually imported/updated (don't memorise a failed/empty attempt).
        if (((int) ($res['success'] ?? 0) + (int) ($res['updated'] ?? 0)) > 0
            && is_array($payload['mapping'] ?? null)) {
            $this->_saveImportMapping($payload['mapping']);
        }

        // Rows the client sent that we refused before writing (defense-in-depth;
        // the UI already excludes error rows from the commit set).
        $res['error'] = (int) ($res['error'] ?? 0) + $preErrors;

        // Stash this batch's created logins so import_credentials_pdf() can build
        // the handout after the whole (chunked) import finishes. Reset on the
        // first batch so a fresh import doesn't append to a previous one.
        $this->_stashImportCredentials(
            'import_creds_student',
            is_array($res['credentials'] ?? null) ? $res['credentials'] : [],
            !empty($payload['firstBatch'])
        );

        echo json_encode([
            'status'  => 'success',
            'summary' => $this->_formatImportSummary($res),
            'counts'  => [
                'success'     => (int) ($res['success'] ?? 0),
                'updated'     => (int) ($res['updated'] ?? 0),
                'duplicates'  => count($res['duplicates'] ?? []),
                'error'       => (int) ($res['error'] ?? 0),
                'feesPending' => count($res['feesPending'] ?? []),
            ],
            'skipped'     => $res['skipped'] ?? [],
            'duplicates'  => $res['duplicates'] ?? [],
            'feesPending' => $res['feesPending'] ?? [],
        ]);
    }

    /**
     * Pre-commit duplicate scan (AJAX, no writes). Receives canonical rows in
     * the `payload` field; for each row with a phone, checks the DB phone index
     * and earlier rows in the same file. Returns per-row match info so the
     * preview can flag duplicates BEFORE the operator commits. Costs one
     * indexed read per row-with-phone, so it is opt-in (a button), not part of
     * every validate.
     */
    public function import_dedupe_check()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');
        $this->output->set_content_type('application/json');

        $payload = json_decode((string) $this->input->post('payload'), true);
        $rows    = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        $seenPhones  = [];
        $seenNameDob = [];
        $out = [];
        $dupCount = 0;

        foreach ($rows as $i => $r) {
            $r = is_array($r) ? $r : [];
            $name = trim((string) ($r['Name'] ?? ''));
            $phone10 = preg_replace('/\D+/', '', (string) ($r['Phone Number'] ?? ''));
            if (strlen($phone10) > 10) $phone10 = substr($phone10, -10);
            $dob = trim((string) ($r['DOB'] ?? ''));
            $nameKey = strtolower($name) . '|' . $dob;

            $match = null; $reason = '';
            if ($phone10 !== '' && strlen($phone10) === 10) {
                if (isset($seenPhones[$phone10])) { $match = '(earlier row)'; $reason = 'duplicate phone in file'; }
                else {
                    $dbId = $this->_existingStudentIdByPhone($phone10);
                    if ($dbId) { $match = $dbId; $reason = 'existing student (phone)'; }
                }
            }
            if (!$match && $dob !== '' && isset($seenNameDob[$nameKey])) { $match = '(earlier row)'; $reason = 'duplicate name+DOB in file'; }

            if ($match !== null) {
                $dupCount++;
                $out[] = ['index' => $i, 'duplicate' => true, 'existingId' => $match, 'reason' => $reason];
            } else {
                $out[] = ['index' => $i, 'duplicate' => false];
            }

            if ($phone10 !== '' && strlen($phone10) === 10) $seenPhones[$phone10] = true;
            if ($dob !== '') $seenNameDob[$nameKey] = true;
        }

        echo json_encode(['rows' => $out, 'duplicates' => $dupCount, 'total' => count($rows)]);
    }

    /**
     * Download the canonical CSV template (headers + one example row).
     */
    /**
     * Load this school's learned header→field mappings (Phase 3 saved mappings).
     * Returns [normalizedHeaderKey => fieldKey]; empty on first-ever import.
     */
    private function _loadImportMapping(): array
    {
        try {
            $doc = $this->fs->get('importMappings', $this->fs->docId('student_import'));
            if (is_array($doc) && is_array($doc['headerMap'] ?? null)) {
                return $doc['headerMap'];
            }
        } catch (\Throwable $e) {
            log_message('error', 'load import mapping failed: ' . $e->getMessage());
        }
        return [];
    }

    /**
     * Merge the column layout the operator used into this school's saved
     * mapping. Keys are normalized (Firestore-safe) so "D.O.B" and "DOB" learn
     * the same slot; values are canonical field keys. $mapping = [{header, field}].
     */
    private function _saveImportMapping(array $mapping): void
    {
        try {
            $this->load->library('import_schema');
            $docId    = $this->fs->docId('student_import');
            $existing = $this->fs->get('importMappings', $docId);
            $headerMap = is_array($existing['headerMap'] ?? null) ? $existing['headerMap'] : [];

            foreach ($mapping as $m) {
                if (!is_array($m)) continue;
                $key   = $this->import_schema->normalizeHeaderKey((string) ($m['header'] ?? ''));
                $field = trim((string) ($m['field'] ?? ''));
                if ($key !== '' && $field !== '') $headerMap[$key] = $field;
            }

            $this->fs->set('importMappings', $docId, [
                'schoolId'  => $this->school_id,
                'type'      => 'student',
                'headerMap' => $headerMap,
                'updatedAt' => date('c'),
            ], true);
        } catch (\Throwable $e) {
            log_message('error', 'save import mapping failed: ' . $e->getMessage());
        }
    }

    public function import_template()
    {
        $this->_require_role(self::VIEW_ROLES, 'import_template', 'SIS', 'view');
        $this->load->library('import_schema');

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $this->import_schema->templateHeaders());
        fputcsv($fh, $this->import_schema->exampleRow());
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $this->output
            ->set_content_type('text/csv')
            ->set_header('Content-Disposition: attachment; filename="ZenXii_student_import_template.csv"')
            ->set_header('Cache-Control: no-store, no-cache')
            ->set_output($csv);
    }

    /**
     * Download a PDF handout of the logins created by the most recent import
     * (Name · Mobile Number · User ID · Default Password). Reads the credentials
     * stashed in the session by import_commit(); a blank mobile is left blank.
     * GET (no CSRF) — the session bucket is the source of truth, so the browser
     * never has to send plaintext passwords back.
     */
    public function import_credentials_pdf()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');

        $creds = $this->session->userdata('import_creds_student');
        if (!is_array($creds) || empty($creds)) {
            show_error('No student credentials are available to export. Run an import first, then download from the result screen.', 404, 'Nothing to export');
            return;
        }

        $this->load->library('pdf_generator');
        $html = $this->load->view('import_credentials_pdf', [
            'rows'        => $creds,
            'entityLabel' => 'Student',
            'title'       => 'Student Login Credentials',
            'schoolName'  => $this->school_display_name ?: $this->school_name,
            'sessionYear' => $this->session_year,
            'generatedAt' => date('d-m-Y H:i'),
        ], true);

        $this->pdf_generator->download($html, 'Student_Credentials_' . date('Ymd_His') . '.pdf');
    }

    /**
     * Append a chunked import's created logins to a session bucket so the whole
     * roster can be exported as one PDF after all batches finish. $reset clears
     * the bucket first (used on the first batch of a fresh import). Capped to
     * keep the session small on very large rosters.
     */
    private function _stashImportCredentials(string $bucket, array $creds, bool $reset): void
    {
        $existing = $reset ? [] : $this->session->userdata($bucket);
        if (!is_array($existing)) $existing = [];
        foreach ($creds as $c) {
            if (count($existing) >= 5000) break; // safety cap
            $existing[] = $c;
        }
        $this->session->set_userdata($bucket, $existing);
    }

    /* ══════════════════════════════════════════════════════════════════════
       LEGACY ADMISSION FORM — Merged from Student.php
       (Includes photo/doc uploads and subject assignment)
    ══════════════════════════════════════════════════════════════════════ */

    public function studentAdmission()
    {
        redirect('sis/admission');
    }

    /* ══════════════════════════════════════════════════════════════════════
       AJAX HELPERS — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function get_sections_by_class()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_sections_by_class', 'SIS', 'view');
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $className = trim((string)$this->input->post('class_name'));
        if ($className === '') {
            header('Content-Type: application/json');
            echo json_encode([]);
            return;
        }
        $className = $this->safe_path_segment($className, 'class_name');

        // Normalize to canonical "Class 8th" format. The JS may send raw
        // values like "8th", "8", "LKG", or already-prefixed "Class 8th".
        // Use the Phase 1 normalizer which handles all variants.
        require_once APPPATH . 'libraries/Entity_firestore_sync.php';
        $cs = Entity_firestore_sync::normalizeClassSection($className, '');
        $classKey = $cs['className'] !== '' ? $cs['className'] : 'Class ' . $className;

        // Firestore first → RTDB fallback
        $sections = [];
        try {
            $fsDocs = $this->fs->schoolWhere('sections', [['className', '==', $classKey]]);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    // Firestore_rest_client::query() returns [{id, data: {...}}]
                    $d   = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sec = $d['section'] ?? '';
                    if ($sec !== '') {
                        // Return just the letter: "Section A" → "A"
                        $sections[] = str_replace('Section ', '', $sec);
                    }
                }
                $sections = array_values(array_unique($sections));
                sort($sections);
            }
        } catch (\Exception $e) {}

        // RTDB fallback removed per no-RTDB policy. Firestore `sections` is the sole source.
        header('Content-Type: application/json');
        echo json_encode($sections);
    }

    public function fetch_subjects()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_subjects', 'SIS', 'view');
        header('Content-Type: application/json');
        $school_name = $this->school_name;
        $rawClass = trim((string) $this->input->post('class_name'));
        if ($rawClass === '') {
            $input = json_decode(file_get_contents('php://input'), true);
            $rawClass = trim($input['class_name'] ?? '');
        }
        if ($rawClass === '' || !preg_match('/\d+/', $rawClass, $m)) {
            echo json_encode([]);
            return;
        }
        $classKey = (int)$m[0];
        // Firestore first → RTDB fallback
        $subjectData = [];
        $fsDocs = $this->fs->schoolWhere('subjects', [['classKey', '==', (string)$classKey]]);
        if (is_array($fsDocs) && !empty($fsDocs)) {
            foreach ($fsDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $code = $d['subject_code'] ?? $d['code'] ?? '';
                if ($code !== '') $subjectData[$code] = $d;
            }
        }
        if (empty($subjectData)) {
            $subjectData = $this->CM->get_data("Schools/{$school_name}/Subject_list/{$classKey}");
        }
        $subjects = [];
        if (is_array($subjectData)) {
            foreach ($subjectData as $code => $item) {
                if (!is_array($item)) continue;
                $category = strtolower(trim($item['category'] ?? ''));
                $name     = trim($item['subject_name'] ?? $item['name'] ?? '');
                if ($name !== '' && in_array($category, ['additional', 'skill-based'], true)) {
                    $subjects[] = $name;
                }
            }
        }
        echo json_encode(array_values(array_unique($subjects)));
    }

    /* ══════════════════════════════════════════════════════════════════════
       EDIT STUDENT — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function edit_student($userId)
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'edit');
        if (empty($userId) || !preg_match('/^[A-Za-z0-9_]+$/', $userId)) { show_404(); return; }

        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $existing = $this->_getStudent($userId);
        if (!$existing) { show_404(); return; }

        $classKey          = Firestore_service::classKey(trim($existing['Class'] ?? ''));
        $sectionKey        = Firestore_service::sectionKey(trim($existing['Section'] ?? ''));
        $combinedClassPath = "{$classKey}/{$sectionKey}";

        if ($this->input->method() !== 'post') {
            // Read additional subjects from student doc.
            $data['additional_subjects'] = $existing['additionalSubjects'] ?? $existing['Additional Subjects'] ?? [];
            // Fees Exemption v2 (P0-b): the legacy exemption-checkbox feed was
            // removed (kept fee-head NAMES nowhere — see save_admission comment).
            // Concessions are now managed via Fee_concessions screen.

            $classNumKey = null;
            if (preg_match('/\d+/', $existing['Class'] ?? '', $m)) $classNumKey = (int)$m[0];
            $allSubjects = [];
            if ($classNumKey) {
                $subjectDocs = $this->fs->schoolWhere('subjects', [['classKey', '==', (string)$classNumKey]]);
                foreach ($subjectDocs as $doc) {
                    $item = $doc['data'];
                    $category = strtolower(trim($item['category'] ?? ''));
                    $name     = trim($item['name'] ?? $item['subject_name'] ?? '');
                    if ($name !== '' && in_array($category, ['additional', 'skill-based'], true)) $allSubjects[] = $name;
                }
            }
            $data['allSubjects']  = array_values(array_unique($allSubjects));
            $data['student_data'] = $existing;
            $data['school_name']  = $school_name;
            $this->load->view('include/header');
            $this->load->view('edit_student', $data);
            $this->load->view('include/footer');
            return;
        }

        // POST mode
        header('Content-Type: application/json');
        $post = $this->input->post();

        $dob           = !empty($post['dob'])            ? trim($post['dob'])            : ($existing['DOB']            ?? '');
        $admissionDate = !empty($post['admission_date']) ? trim($post['admission_date']) : ($existing['Admission Date'] ?? '');
        $religion = $post['religion'] ?? ($existing['Religion'] ?? '');
        if ($religion === 'Other' && !empty($post['other_religion'])) $religion = trim($post['other_religion']);
        $preMarks = trim($post['pre_marks'] ?? '');
        if ($preMarks !== '' && substr($preMarks, -1) !== '%') $preMarks .= '%';

        $updateData = [
            "Name" => $post['Name'] ?? ($existing['Name'] ?? ''),
            "DOB" => $dob, "Admission Date" => $admissionDate,
            "Phone Number" => $post['phone_number'] ?? ($existing['Phone Number'] ?? ''),
            "Email" => $post['email'] ?? ($existing['Email'] ?? ''),
            "Gender" => $post['gender'] ?? ($existing['Gender'] ?? ''),
            "Category" => $post['category'] ?? ($existing['Category'] ?? ''),
            "Blood Group" => $post['blood_group'] ?? ($existing['Blood Group'] ?? ''),
            "Religion" => $religion,
            "Nationality" => $post['nationality'] ?? ($existing['Nationality'] ?? ''),
            "Father Name" => $post['father_name'] ?? ($existing['Father Name'] ?? ''),
            "Father Occupation" => $post['father_occupation'] ?? ($existing['Father Occupation'] ?? ''),
            "Mother Name" => $post['mother_name'] ?? ($existing['Mother Name'] ?? ''),
            "Mother Occupation" => $post['mother_occupation'] ?? ($existing['Mother Occupation'] ?? ''),
            "Guard Contact" => $post['guard_contact'] ?? ($existing['Guard Contact'] ?? ''),
            "Guard Relation" => $post['guard_relation'] ?? ($existing['Guard Relation'] ?? ''),
            "Pre Class" => $post['pre_class'] ?? ($existing['Pre Class'] ?? ''),
            "Pre School" => $post['pre_school'] ?? ($existing['Pre School'] ?? ''),
            "Pre Marks" => $preMarks !== '' ? $preMarks : ($existing['Pre Marks'] ?? ''),
            "Address" => [
                "Street" => $post['street'] ?? ($existing['Address']['Street'] ?? ''),
                "City" => $post['city'] ?? ($existing['Address']['City'] ?? ''),
                "State" => $post['state'] ?? ($existing['Address']['State'] ?? ''),
                "PostalCode" => $post['postal_code'] ?? ($existing['Address']['PostalCode'] ?? ''),
            ],
            "Class" => $existing["Class"], "Section" => $existing["Section"],
            "User Id" => $existing["User Id"], "Password" => $existing["Password"] ?? '',
        ];

        $updateData["Profile Pic"] = $existing["Profile Pic"] ?? '';
        $existingDoc = is_array($existing["Doc"] ?? null) ? $existing["Doc"] : [];
        foreach (['Birth Certificate', 'Aadhar Card', 'Transfer Certificate', 'Photo'] as $docKey) {
            if (isset($existingDoc[$docKey]) && !is_array($existingDoc[$docKey])) {
                $existingDoc[$docKey] = ['url' => (string)$existingDoc[$docKey], 'thumbnail' => ''];
            }
        }
        $updateData["Doc"] = $existingDoc;

        // Document re-upload
        $documents = ['birthCertificate' => 'Birth Certificate', 'aadharCard' => 'Aadhar Card', 'transferCertificate' => 'Transfer Certificate'];
        foreach ($documents as $inputKey => $label) {
            if (empty($_FILES[$inputKey]['tmp_name'])) continue;
            $oldDoc = $existingDoc[$label] ?? [];
            $this->_deleteOldStorageFile($oldDoc);
            $uploadResult = $this->_uploadStudentFile($_FILES[$inputKey], $school_name, $combinedClassPath, $userId, $label, 'document');
            if ($uploadResult) {
                $updateData["Doc"][$label] = ['url' => $uploadResult['document'] ?? '', 'thumbnail' => $uploadResult['thumbnail'] ?? ''];
            }
        }

        // Photo replace
        $photoUpdated = false;
        if (!empty($_FILES['student_photo']['tmp_name'])) {
            $photo = $_FILES['student_photo'];
            $ext = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $this->_deleteOldStorageFile($existingDoc['Photo'] ?? []);
                $photoResult = $this->_uploadStudentFile($photo, $school_name, $combinedClassPath, $userId, 'profile', 'profile');
                if ($photoResult) {
                    $updateData["Profile Pic"]  = $photoResult['document'];
                    $updateData["Doc"]["Photo"] = ['url' => $photoResult['document'] ?? '', 'thumbnail' => $photoResult['thumbnail'] ?? ''];
                    $photoUpdated = true;
                }
            }
        }

        // Update student in Firestore.
        //
        // Title→camelCase aliasing (Tier-A Step 3) — every PascalCase
        // key the admin form posts gets a camelCase mirror so the
        // mobile apps' StudentDoc (which reads camelCase only) sees
        // the change immediately on this `fs->updateEntity` write,
        // without waiting for the trailing `syncStudent` call to
        // canonicalise the doc. Pre-fix, only Name/Phone/Email/Class/
        // Section were aliased — DOB, Religion, Category, parents,
        // Guard*, Pre*, Address all landed on the doc in PascalCase
        // and silently disappeared from the parent / teacher screens
        // until the next syncStudent merge ran (or never, on partial
        // edits that didn't touch every field).
        $updateData['updatedAt'] = date('c');

        if (isset($updateData['Name']))              $updateData['name']             = $updateData['Name'];
        if (isset($updateData['Phone Number']))      {
            // Mirror Entity_firestore_sync::syncStudent — both `phone`
            // (Android canonical) and `phoneNumber` (back-compat).
            $updateData['phone']       = $updateData['Phone Number'];
            $updateData['phoneNumber'] = $updateData['Phone Number'];
        }
        if (isset($updateData['Email']))             $updateData['email']            = $updateData['Email'];
        if (isset($updateData['Class']))             $updateData['className']        = $updateData['Class'];
        if (isset($updateData['Section']))           $updateData['section']          = $updateData['Section'];
        if (isset($updateData['DOB']))               $updateData['dob']              = $updateData['DOB'];
        if (isset($updateData['Gender']))            $updateData['gender']           = $updateData['Gender'];
        if (isset($updateData['Category']))          $updateData['category']         = $updateData['Category'];
        if (isset($updateData['Blood Group']))       $updateData['bloodGroup']       = $updateData['Blood Group'];
        if (isset($updateData['Religion']))          $updateData['religion']         = $updateData['Religion'];
        if (isset($updateData['Nationality']))       $updateData['nationality']      = $updateData['Nationality'];
        if (isset($updateData['Admission Date']))    $updateData['admissionDate']    = $updateData['Admission Date'];
        if (isset($updateData['Father Name']))       $updateData['fatherName']       = $updateData['Father Name'];
        if (isset($updateData['Father Occupation'])) $updateData['fatherOccupation'] = $updateData['Father Occupation'];
        if (isset($updateData['Mother Name']))       $updateData['motherName']       = $updateData['Mother Name'];
        if (isset($updateData['Mother Occupation'])) $updateData['motherOccupation'] = $updateData['Mother Occupation'];
        if (isset($updateData['Guard Contact']))     $updateData['guardContact']     = $updateData['Guard Contact'];
        if (isset($updateData['Guard Relation']))    $updateData['guardRelation']    = $updateData['Guard Relation'];
        if (isset($updateData['Pre Class']))         $updateData['preClass']         = $updateData['Pre Class'];
        if (isset($updateData['Pre School']))        $updateData['preSchool']        = $updateData['Pre School'];
        if (isset($updateData['Pre Marks']))         $updateData['preMarks']         = $updateData['Pre Marks'];
        if (isset($updateData['Address']))           $updateData['address']          = $updateData['Address'];
        if (isset($updateData['Profile Pic']))       $updateData['profilePic']       = $updateData['Profile Pic'];
        if (isset($updateData['Roll No']))           $updateData['rollNo']           = $updateData['Roll No'];
        // The `Doc` nested map (Photo, BirthCert, AadharCard, …) is
        // mirrored to the canonical `documents` key the parent app
        // reads. Both shapes are kept on the doc so any legacy reader
        // that still expects `Doc` continues to work.
        if (isset($updateData['Doc']))               $updateData['documents']        = $updateData['Doc'];

        // Additional subjects
        $additionalSubjects = [];
        if (!empty($post['additional_subjects']) && is_array($post['additional_subjects'])) {
            foreach ($post['additional_subjects'] as $sub) {
                $sub = trim($sub);
                if ($sub !== '') $additionalSubjects[$sub] = "";
            }
        }
        $updateData['additionalSubjects'] = $additionalSubjects;

        // Fees Exemption v2 (P0-b): the exempted_fees_multiple edit-write was
        // removed. Concessions are captured via Fee_concessions (Phase 0+),
        // applied by the unified generator (Phase 2+). Edit billing unchanged.

        $this->fs->updateEntity('students', $userId, $updateData);

        // SIS Wave-2 fix H1 (2026-05-31): emit PROFILE_UPDATE history entry,
        // mirroring the canonical pattern from Sis::update_profile@736-771.
        // Pre-fix, edit_student persisted comprehensive profile edits to
        // Firestore but emitted no audit entry, leaving zero forensic trail
        // for compliance reviews. Diff computed against $existing (loaded at
        // L3499 via _getStudent). Skip 'updatedAt' (timestamp churn) and
        // camelCase mirrors (the L3624-3669 aliasing block duplicates
        // Title-Case keys for the Android apps — including both would
        // double-count every change). Use === for type-safe comparison.
        $auditDiff = [];
        foreach ($updateData as $auditKey => $newVal) {
            if ($auditKey === 'updatedAt') continue;
            if ($auditKey === '' || ctype_lower($auditKey[0])) continue;
            $oldVal = $existing[$auditKey] ?? null;
            if ($oldVal === $newVal) continue;
            $auditDiff[$auditKey] = ['old' => $oldVal, 'new' => $newVal];
        }
        if (!empty($auditDiff)) {
            $changedKeys = array_keys($auditDiff);
            $this->_log_history($school_id, $userId, 'PROFILE_UPDATE',
                "Profile updated: " . implode(', ', $changedKeys),
                ['fields' => $changedKeys, 'changes' => $auditDiff]
            );
        }

        // RTDB mirror removed per no-RTDB policy.

        // Entity sync: update student in Firestore (Android apps)
        try {
            // SIS Wave-2 S6 (2026-05-31): observability for sync return.
            if (!$this->entity_sync->syncStudent($userId, $updateData)) {
                log_message('warning', "syncStudent returned false for {$userId} (edit_student)");
            }
            if (!$this->entity_sync->syncParent($userId, $updateData)) {
                log_message('warning', "syncParent returned false for {$userId} (edit_student)");
            }
        } catch (\Exception $e) { log_message('error', "entity_sync syncStudent failed for {$userId}: " . $e->getMessage()); }

        $response = ['status' => 'success', 'message' => 'Student updated successfully'];
        if ($photoUpdated) $response['photo_notice'] = 'Profile photo updated with thumbnail.';
        echo json_encode($response);
    }

    /* ══════════════════════════════════════════════════════════════════════
       DELETE STUDENT — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function delete_student($id)
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');
        // FIXED: return JSON for AJAX requests instead of redirect (was breaking bulk delete)
        $isAjax = $this->input->is_ajax_request();
        if ($this->input->method() !== 'post') {
            if ($isAjax) return $this->json_error('POST required');
            redirect('sis/students'); return;
        }
        if (empty($id) || !preg_match('/^[A-Za-z0-9_]+$/', $id)) {
            if ($isAjax) return $this->json_error('Invalid student ID');
            redirect('sis/students'); return;
        }

        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $student = $this->_getStudent($id);
        if (!$student) {
            if ($isAjax) return $this->json_error('Student not found');
            redirect('sis/students'); return;
        }

        $phoneNumber = $student['Phone Number'] ?? '';
        $class       = $student['Class']   ?? '';
        $section     = $student['Section'] ?? '';
        if (!$class || !$section) {
            if ($isAjax) return $this->json_error('Class or Section missing from student profile');
            $this->session->set_flashdata('error', 'Class or Section missing');
            redirect('sis/students'); return;
        }
        $class   = Firestore_service::classKey($class);
        $section = Firestore_service::sectionKey($section);
        $combinedClassPath = "{$class}/{$section}";

        // Preserve fee records
        try {
            $this->feeLifecycle->freezeFeesOnSoftDelete($id);
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::freezeFeesOnSoftDelete failed for {$id}: " . $e->getMessage());
        }

        // Cascade — clear the back-link on any CRM application that
        // enrolled this student. Without this, the application doc
        // keeps a stale `student_id` field pointing at a deleted record.
        // We don't change `status` (keep it as "enrolled" for history)
        // but blank the link and append an audit-style history entry.
        try {
            $appId = (string) ($student['application_id'] ?? '');
            if ($appId !== '') {
                $existingApp = $this->_crm_get('crmApplications', $appId);
                if (is_array($existingApp)) {
                    $now = date('Y-m-d H:i:s');
                    $hist = is_array($existingApp['history'] ?? null) ? $existingApp['history'] : [];
                    $hist[] = [
                        'action'    => "Linked student {$id} was deleted from SIS",
                        'by'        => $this->admin_name,
                        'timestamp' => $now,
                    ];
                    $this->_crm_update('crmApplications', $appId, [
                        'student_id'      => '',
                        'student_deleted' => true,
                        'updated_at'      => $now,
                        'history'         => $hist,
                    ]);
                }
            }
        } catch (\Exception $e) {
            log_message('error', "delete_student: crmApplications back-link cleanup failed for {$id}: " . $e->getMessage());
        }

        // Determine if this is a hard delete or soft delete
        $hardDelete = $this->input->post('hard_delete') === 'true';

        if ($hardDelete) {
            // ── HARD DELETE: permanent removal ──────────────────────
            $this->dw->removeFromRoster($class, $section, $id);
            $this->dw->hardDeleteStudent($id);

            // Clean storage. Canonical path first (all post-2026-06-13 uploads
            // live here); the two legacy paths below are best-effort cleanup for
            // files admitted before the storage-path cutover and not yet migrated.
            $this->CM->delete_folder_from_firebase_storage("schools/{$this->school_id}/students/{$id}");
            $this->CM->delete_folder_from_firebase_storage("{$school_name}/Students/{$combinedClassPath}/{$id}");
            $this->CM->delete_folder_from_firebase_storage("Students/{$school_id}/{$id}");

            // Clean Firestore + phone index
            $this->fs->removeEntity('students', $id);
            if (!empty($phoneNumber)) {
                $this->fs->remove('indexPhones', $this->fs->docId($phoneNumber));
            }

            // G3 — Cascade delete orphan rows in collections keyed by
            // studentId. Pre-fix, hard-deleting a student left behind
            // months of attendanceSummary rows, every daily attendance
            // doc, every marks entry, and every fee record — surfacing
            // as ghost data on parent/teacher dashboards. Each
            // collection is queried+deleted in isolation; a failure on
            // one (e.g. a missing collection on a fresh school)
            // doesn't block the others.
            foreach (['attendanceSummary', 'attendance', 'marks', 'feeReceipts', 'feeDemands', 'studentFlags', 'submissions', 'teacherMarks'] as $cascadeCol) {
                try {
                    $rows = $this->fs->schoolWhere($cascadeCol, [
                        ['studentId', '==', $id],
                    ]);
                    foreach ($rows as $row) {
                        $docId = is_array($row) ? ($row['id'] ?? '') : '';
                        if ($docId === '') continue;
                        try { $this->fs->remove($cascadeCol, $docId); }
                        catch (\Exception $inner) {
                            log_message('error', "G3 cascade {$cascadeCol}/{$docId} delete failed: " . $inner->getMessage());
                        }
                    }
                } catch (\Exception $e) {
                    log_message('error', "G3 cascade {$cascadeCol} query failed for {$id}: " . $e->getMessage());
                }
            }

            // Delete Firebase Auth account
            try { $this->firebase->deleteFirebaseUser($id); } catch (Exception $e) {}

            log_audit('SIS', 'hard_delete_student', $id, "Permanently deleted student '{$student['Name']}' from {$class} {$section}");
        } else {
            // ── SOFT DELETE (default): recoverable ─────────────────
            $reason = trim($this->input->post('reason') ?? '') ?: 'Deleted by admin';
            $this->dw->softDeleteStudent($id, $class, $section, $reason);

            $this->_log_history($school_id, $id, 'DELETED',
                "Student soft-deleted: {$reason}",
                ['class' => $class, 'section' => $section, 'reason' => $reason]
            );

            log_audit('SIS', 'soft_delete_student', $id, "Soft-deleted student '{$student['Name']}' from {$class} {$section}");
        }

        // G2 — Section strength drops by one regardless of hard / soft
        // delete: both flip the row out of the `status='Active'` filter
        // (hard removes the doc entirely, soft sets `status='Deleted'`).
        $this->_recompute_section_strength($class, $section);

        // FIXED: return JSON for AJAX, redirect for direct form POST
        if ($isAjax) {
            return $this->json_success(['message' => 'Student deleted successfully.']);
        }
        $this->session->set_flashdata('success', 'Student deleted successfully');
        redirect('sis/students');
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT PROFILE (with fees) — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function student_profile($userId)
    {
        $this->_require_role(self::VIEW_ROLES, 'student_profile', 'SIS', 'view');
        if (empty($userId) || !preg_match('/^[A-Za-z0-9_]+$/', $userId)) { show_404(); return; }

        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $studentData = $this->_getStudent($userId);
        if (!$studentData) { show_error("Student not found"); return; }

        $class   = Firestore_service::classKey($studentData['Class'] ?? '');
        $section = Firestore_service::sectionKey($studentData['Section'] ?? '');
        $basePath = "Schools/$school_name/$session_year/{$class}/{$section}";

        // Subjects
        $subjectsList = [];
        if (!empty($class)) {
            $classNumber = (int) preg_replace('/[^0-9]/', '', $class);
            if ($classNumber > 0) {
                $subjectDocs = $this->fs->schoolWhere('subjects', [['classKey', '==', (string)$classNumber]]);
                foreach ($subjectDocs as $doc) {
                    $sn = $doc['data']['name'] ?? $doc['data']['subject_name'] ?? '';
                    if ($sn !== '') $subjectsList[] = $sn;
                }
            }
        }

        // Read additional subjects and fees from student doc in Firestore
        $additionalSubjects = $studentData['additionalSubjects'] ?? $studentData['Additional Subjects'] ?? [];
        $finalSubjectsList = array_unique(array_merge($subjectsList, array_keys(is_array($additionalSubjects) ? $additionalSubjects : [])));

        $rawExempted = $studentData['exemptedFees'] ?? $studentData['Exempted Fees'] ?? [];
        $exemptedFees = is_array($rawExempted) ? $rawExempted : [];

        // 2026-06-02: Firestore-only — read the canonical
        // studentDiscounts/{schoolId}_{userId} doc that Fees::submit_discount,
        // Fees::set_student_discount, and Fee_management::apply_discount all
        // write to. The legacy embedded students.{id}.Discount path was
        // verified to have zero active writers + zero readers + zero
        // production data across all tenants (audit 2026-05-29..2026-06-02)
        // and is no longer kept as a fallback per the Firestore-only
        // convergence policy. Field map: canonical onDemandDiscount -> view's
        // "Current Discount"; canonical totalDiscount -> view's "Total".
        // Legacy PascalCase 'OnDemandDiscount' is still tolerated for any
        // doc written by Fee_management::apply_discount before Fix #3
        // landed; with zero such docs in production it's a no-op today.
        $discountDoc = $this->fs->get('studentDiscounts', "{$this->fs->schoolId()}_{$userId}");
        $totalDiscount   = is_array($discountDoc) ? (float) ($discountDoc['totalDiscount'] ?? 0) : 0;
        $currentDiscount = is_array($discountDoc)
            ? (float) ($discountDoc['onDemandDiscount'] ?? $discountDoc['OnDemandDiscount'] ?? 0)
            : 0;

        $feesJson = $this->_getFees($class, $section);
        $feesData = json_decode($feesJson, true);

        $data = [
            'student' => $studentData, 'class' => $class, 'section' => $section,
            'fees' => $feesData['fees'] ?? null, 'monthlyTotals' => $feesData['monthlyTotals'] ?? null,
            'overallTotal' => $feesData['overallTotal'] ?? null, 'subjects' => $finalSubjectsList,
            'discount' => $totalDiscount, 'totaldiscount' => $totalDiscount,
            'currentdiscount' => $currentDiscount, 'exempted_fees' => $exemptedFees,
        ];

        $this->load->view('include/header');
        $this->load->view('student_profile', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       DOWNLOAD DOCUMENT — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function download_document()
    {
        $this->_require_role(self::VIEW_ROLES, 'download_document', 'SIS', 'view');
        $fileUrl = $this->input->get('file', TRUE);
        if (empty($fileUrl) || !filter_var($fileUrl, FILTER_VALIDATE_URL)) { show_error("Invalid file URL.", 400); return; }

        $parts = parse_url($fileUrl);
        if (empty($parts['scheme']) || empty($parts['host'])) { show_error("Malformed URL.", 400); return; }
        if ($parts['scheme'] !== 'https') { show_error("Only HTTPS allowed.", 403); return; }

        $allowedHosts = ['firebasestorage.googleapis.com', 'storage.googleapis.com'];
        if (!in_array($parts['host'], $allowedHosts, true)) { show_error("Access denied.", 403); return; }

        $ip = gethostbyname($parts['host']);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            show_error("Invalid host.", 403); return;
        }

        $fileName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($parts['path']));
        $ch = curl_init($fileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        curl_exec($ch);
        if (curl_errno($ch)) { curl_close($ch); show_error("Download failed.", 500); return; }
        curl_close($ch);
    }

    /* ══════════════════════════════════════════════════════════════════════
       ATTENDANCE — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function attendance()
    {
        $this->_require_role(self::VIEW_ROLES, 'attendance', 'Attendance', 'view');
        $sectionDocs = $this->fs->schoolWhere('sections', []);
        $ClassesData = [];
        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'];
            $ClassesData[] = [
                'class_name' => $sd['className'] ?? '',
                'section'    => str_replace('Section ', '', $sd['section'] ?? ''),
            ];
        }
        $viewData['Classes'] = $ClassesData;
        $this->load->view('include/header');
        $this->load->view('attendance', $viewData);
        $this->load->view('include/footer');
    }

    public function fetchAttendance()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_attendance', 'Attendance', 'view');
        header('Content-Type: application/json');
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $class   = $this->input->post('class');
        $section = $this->input->post('section');
        $month   = $this->input->post('month');
        if (empty($class) || empty($section) || empty($month)) {
            echo json_encode(["error" => "Class, Section and Month are required"]);
            return;
        }

        $monthToNumber = [
            'January'=>1,'February'=>2,'March'=>3,'April'=>4,'May'=>5,'June'=>6,
            'July'=>7,'August'=>8,'September'=>9,'October'=>10,'November'=>11,'December'=>12,
        ];
        $monthNumber = $monthToNumber[trim($month)] ?? 0;
        if ($monthNumber === 0) { echo json_encode(["error" => "Invalid month name."]); return; }

        $sessionParts = explode('-', $session_year);
        $startYear = (int)($sessionParts[0] ?? date('Y'));
        $endYear   = isset($sessionParts[1]) ? (int)$sessionParts[1] : $startYear + 1;
        $year = ($monthNumber >= 4) ? $startYear : $endYear;

        $class   = $this->safe_path_segment($class, 'class');
        $section = $this->safe_path_segment($section, 'section');

        // Get students from Firestore (use prefixed format for queries)
        $classKey = Firestore_service::classKey($class);
        $sectionKey = Firestore_service::sectionKey($section);
        $studentDocs = $this->fs->schoolWhere('students', [
            ['Class', '==', $classKey], ['Section', '==', $sectionKey], ['Status', '==', 'Active'],
        ], 'Name', 'ASC');

        if (empty($studentDocs)) {
            echo json_encode(["error" => "No students found for this class/section."]);
            return;
        }

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNumber, $year);
        $sundays     = $this->_getSundays($year, $monthNumber);
        $studentsData = [];
        foreach ($studentDocs as $doc) {
            $d = $doc['data'] ?? $doc;
            $s = $doc['data'];
            $studentId = $s['User Id'] ?? $s['studentId'] ?? $d['id'];
            $studentName = $s['Name'] ?? $s['name'] ?? $studentId;
            // Attendance from Firestore attendance collection
            $attDocId = $this->fs->docId2($studentId, date('Y-m', mktime(0, 0, 0, $monthNumber, 1, $year)));
            $attDoc = $this->fs->get('attendanceSummary', $attDocId);
            $attendanceString = $attDoc['dayWise'] ?? '';
            if (empty($attendanceString) || !is_string($attendanceString)) $attendanceString = str_repeat('V', $daysInMonth);
            $attendanceArray = array_pad(str_split($attendanceString), $daysInMonth, 'V');
            $displayName = is_string($studentName) ? $studentName : ($studentName['Name'] ?? (string)$studentId);
            $studentsData[] = ["userId" => $studentId, "name" => $displayName, "attendance" => $attendanceArray];
        }
        echo json_encode(["students" => $studentsData, "daysInMonth" => $daysInMonth, "sundays" => $sundays, "month" => $month, "year" => $year]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       CRM Firestore helpers — all CRM entities moved to Firestore:
         crmApplications, crmInquiries, crmWaitlist, crmSettings
       Replaces Schools/{school}/CRM/Admissions/* RTDB paths.
    ══════════════════════════════════════════════════════════════════════ */

    /** List all docs in a CRM collection, keyed by entity ID. */
    private function _crm_list(string $collection, array $conditions = []): array
    {
        // SCALE (closure item I): bound the scan to the CURRENT session instead of
        // reading every document across all historical sessions. Every _crm_list
        // caller (analytics / dashboard / inquiries / waitlist) already discards
        // non-current-session rows in PHP, so this is behaviour-identical while
        // preventing unbounded all-history accumulation. schoolId + session are two
        // equality filters (zig-zag merge) — no composite index required.
        try {
            // Push equality filters (e.g. session==) to Firestore instead of
            // fetching the whole collection and filtering in PHP (F5). Two
            // equality conditions (schoolId== injected by schoolWhere + the
            // caller's session==) are served from single-field indexes — no
            // composite index required.
            $docs = $this->fs->schoolWhere($collection, $conditions);
        } catch (\Exception $e) {
            log_message('error', "CRM list {$collection} failed: " . $e->getMessage());
            return [];
        }
        if (!is_array($docs)) return [];
        $result = [];
        $prefix = $this->school_id . '_';
        foreach ($docs as $d) {
            $d = $d['data'] ?? $d;
            $r = is_array($d['data'] ?? null) ? $d['data'] : $d;
            $rawId = (string) ($d['id'] ?? '');
            $id = (strpos($rawId, $prefix) === 0) ? substr($rawId, strlen($prefix)) : $rawId;
            if ($id !== '') $result[$id] = $r;
        }
        return $result;
    }

    /**
     * C7 — server-side, index-backed, cursor-paginated CRM query. Replaces the
     * unbounded _crm_list() full-scan for the Applications / Leads grids. School
     * + session isolation is enforced INSIDE Firestore_service::cursorQuery().
     *
     * Search picks exactly ONE indexed strategy so every path is backed by a
     * deployed index and FAILS LOUD on a missing index instead of silently
     * full-scanning: exact application-ID (doc get), exact phone (equality query,
     * zig-zag merge — no composite index), or name token (searchTokens
     * array-contains, composite index #5). During an active search, the
     * status/class/source filters are intentionally NOT applied — no deployed
     * index combines them with searchTokens, and adding one is out of C7 scope.
     *
     * @param array      $filters ['status'|'class'|'source'|'stage' => value]
     * @param string     $search  raw search text (name | appID | phone)
     * @param int        $limit   page size (clamped 1..100)
     * @param array|null $cursor  opaque ['created_at','id'] from a prior page
     * @return array ['items'=>[{...,id}], 'nextCursor'=>?, 'hasMore'=>bool, 'mode'=>string, 'error'=>?]
     */
    private function _crm_page(array $filters, string $search, int $limit, ?array $cursor): array
    {
        $this->load->helper('admission_search');
        $limit  = max(1, min(100, (int) $limit));
        $search = trim($search);

        // Each allowed equality-filter combo maps to exactly one deployed index
        // (#1-#4, #6-#9). Any other combo is rejected — never silently degraded
        // to a full scan.
        static $ALLOWED_COMBOS = ['', 'status', 'class', 'class|status', 'source', 'source|status', 'stage', 'class|stage'];

        $prefix = $this->school_id . '_';
        $strip = function (array $items) use ($prefix): array {
            $out = [];
            foreach ($items as $it) {
                $r = is_array($it['data'] ?? null) ? $it['data'] : $it;
                $rawId = (string) ($it['id'] ?? '');
                $r['id'] = (strpos($rawId, $prefix) === 0) ? substr($rawId, strlen($prefix)) : $rawId;
                $out[] = $r;
            }
            return $out;
        };

        // ── SEARCH MODES (mutually exclusive; browse filters ignored) ──
        if ($search !== '') {
            // (a) Exact application-ID — IDs start with "APP".
            if (preg_match('/^APP[_A-Za-z0-9]+$/i', $search)) {
                $doc   = $this->_crm_get('crmApplications', $search);
                $items = is_array($doc) ? [['id' => $prefix . $search, 'data' => $doc]] : [];
                return ['items' => $strip($items), 'nextCursor' => null, 'hasMore' => false, 'mode' => 'appid'];
            }
            // (b) Exact phone — predominantly digits, >=6 after normalization.
            $digits = preg_replace('/[^0-9]/', '', $search);
            $bare   = preg_replace('/\s/', '', $search);
            if (strlen($digits) >= 6 && strlen($digits) >= (int) floor(strlen($bare) * 0.6)) {
                $rows = $this->fs->where('crmApplications', [
                    ['schoolId', '==', $this->school_id],
                    ['session',  '==', $this->session_year],
                    ['phone_norm', '==', $digits],
                ], null, 'ASC', $limit);
                $rows = is_array($rows) ? $rows : [];
                usort($rows, fn($a, $b) => strcmp(($b['data']['created_at'] ?? ''), ($a['data']['created_at'] ?? '')));
                return ['items' => $strip($rows), 'nextCursor' => null, 'hasMore' => false, 'mode' => 'phone'];
            }
            // (c) Name token — searchTokens array-contains (composite index #5).
            $token = admission_search_query_token($search);
            if ($token === '') {
                return ['items' => [], 'nextCursor' => null, 'hasMore' => false, 'mode' => 'name'];
            }
            try {
                $res = $this->fs->cursorQuery('crmApplications', [['searchTokens', 'array-contains', $token]], $limit, $cursor);
            } catch (\Exception $e) {
                log_message('error', 'CRM name-search failed: ' . $e->getMessage());
                return ['items' => [], 'nextCursor' => null, 'hasMore' => false, 'mode' => 'name', 'error' => 'search_failed'];
            }
            return ['items' => $strip($res['items']), 'nextCursor' => $res['nextCursor'], 'hasMore' => $res['hasMore'], 'mode' => 'name'];
        }

        // ── BROWSE (no search) — indexed equality filters + cursor ──
        $conds = [];
        $keys  = [];
        foreach (['status', 'class', 'source', 'stage'] as $f) {
            $raw = $filters[$f] ?? '';
            if (is_array($raw)) {
                // Disjunctive filter (e.g. leads "New" bucket -> [new, pending]).
                // Uses the SAME composite index as the equality filter.
                $vals = array_values(array_filter($raw, fn($x) => trim((string) $x) !== ''));
                if (!empty($vals)) { $conds[] = [$f, 'in', $vals]; $keys[] = $f; }
            } else {
                $v = trim((string) $raw);
                if ($v !== '') { $conds[] = [$f, '==', $v]; $keys[] = $f; }
            }
        }
        sort($keys);
        if (!in_array(implode('|', $keys), $ALLOWED_COMBOS, true)) {
            return ['items' => [], 'nextCursor' => null, 'hasMore' => false, 'mode' => 'browse', 'error' => 'unsupported_filter'];
        }
        try {
            $res = $this->fs->cursorQuery('crmApplications', $conds, $limit, $cursor);
        } catch (\Exception $e) {
            log_message('error', 'CRM browse query failed: ' . $e->getMessage());
            return ['items' => [], 'nextCursor' => null, 'hasMore' => false, 'mode' => 'browse', 'error' => 'query_failed'];
        }
        return ['items' => $strip($res['items']), 'nextCursor' => $res['nextCursor'], 'hasMore' => $res['hasMore'], 'mode' => 'browse'];
    }

    /** Reconstruct the opaque pagination cursor from request input (null = page 1). */
    private function _crm_cursor_from_input(): ?array
    {
        $ca = trim((string) $this->input->get_post('cursor_created_at'));
        $id = trim((string) $this->input->get_post('cursor_id'));
        if ($ca === '' || $id === '') return null;
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $id)) return null; // opaque doc id
        return ['created_at' => $ca, 'id' => $id];
    }

    /** Get a single CRM doc. */
    private function _crm_get(string $collection, string $id): ?array
    {
        try {
            $d = $this->fs->getEntity($collection, $id);
            return (is_array($d) && !empty($d)) ? $d : null;
        } catch (\Exception $e) {
            log_message('error', "CRM get {$collection}/{$id} failed: " . $e->getMessage());
            return null;
        }
    }

    /** Write a CRM doc (create or overwrite). */
    private function _crm_set(string $collection, string $id, array $data): void
    {
        try { $this->fs->setEntity($collection, $id, $data); }
        catch (\Exception $e) { log_message('error', "CRM set {$collection}/{$id} failed: " . $e->getMessage()); }
    }

    /** Merge-update fields on a CRM doc. */
    private function _crm_update(string $collection, string $id, array $data): void
    {
        try { $this->fs->updateEntity($collection, $id, $data); }
        catch (\Exception $e) { log_message('error', "CRM update {$collection}/{$id} failed: " . $e->getMessage()); }
    }

    /** Delete a CRM doc. */
    private function _crm_delete(string $collection, string $id): void
    {
        try { $this->fs->removeEntity($collection, $id); }
        catch (\Exception $e) { log_message('error', "CRM delete {$collection}/{$id} failed: " . $e->getMessage()); }
    }

    /** CRM counter — allocates sequential IDs (INQ0001, APP0001, WL0001). */
    private function _crm_next_id(string $type, string $prefix, int $pad = 4): string
    {
        // Atomic claim-doc allocator (the same primitive TC uses). Replaces the
        // old racy read-increment-write that could hand two concurrent
        // submit_online_form / save_application calls the SAME id and then
        // silently overwrite one lead. Seed from any legacy crmCounters value so
        // a switchover never re-issues an existing id.
        $seed = 0;
        try {
            $doc = $this->fs->get('schools', $this->fs->docId('profile'));
            $flatKey = "crmCounters.{$type}";
            if (is_array($doc) && isset($doc[$flatKey]) && is_numeric($doc[$flatKey])) {
                $seed = (int) $doc[$flatKey];
            }
        } catch (\Throwable $e) { /* non-fatal — allocator handles base itself */ }

        $next = $this->fs->nextSchoolCounter('crm_' . $type, $seed);
        if ($next <= 0) {
            // Fail closed (F11): the old `$seed + 1` fallback handed two
            // concurrent callers the SAME id during a Firestore outage,
            // silently overwriting one admission record. A duplicate/clobbered
            // record is worse than a retry, so refuse to allocate rather than
            // risk a collision. This fires only when the allocator is down and
            // is thrown BEFORE any caller write, so it leaves no partial state.
            log_message('error', "CRM atomic counter unavailable for {$type}; refusing racy fallback id (fail-closed)");
            throw new \RuntimeException('CRM ID allocator is temporarily unavailable — please retry.');
        }
        return $prefix . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
    }

    /** CRM settings — single doc per school. */
    private function _crm_get_settings(): array
    {
        return $this->_crm_get('crmSettings', 'config') ?? [];
    }
    private function _crm_save_settings(array $data): void
    {
        $this->_crm_set('crmSettings', 'config', $data);
    }

    /* ══════════════════════════════════════════════════════════════════════
       LEAD SYSTEM — Public admission leads management
       View, filter, and convert public form leads into student admissions
    ══════════════════════════════════════════════════════════════════════ */

    // LEAD SYSTEM — List all public admission leads
    public function admission_leads()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $data['session_year'] = $this->session_year;
        $data['school_name']  = $this->school_name;
        $this->load->view('include/header');
        $this->load->view('sis/admission_leads', $data);
        $this->load->view('include/footer');
    }

    // LEAD SYSTEM — AJAX: fetch leads data
    public function fetch_leads()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        // C7 — server-side paginated + filtered + searched (was: full-scan +
        // client-side filter). The Leads grid's status dropdown uses NORMALIZED
        // buckets; expand each to the raw statuses it folds in (legacy spellings)
        // so an `in` filter surfaces them all — parity with the old client-side
        // normalizeStatus().
        $statusFilter = $this->_crm_lead_status_bucket((string) $this->input->get_post('status'));
        $page = $this->_crm_page(
            ['status' => $statusFilter, 'source' => $this->input->get_post('source')],
            (string) $this->input->get_post('search'),
            (int) ($this->input->get_post('limit') ?: 25),
            $this->_crm_cursor_from_input()
        );
        return $this->json_success([
            'leads'      => $page['items'],
            'nextCursor' => $page['nextCursor'],
            'hasMore'    => $page['hasMore'],
            'mode'       => $page['mode'],
            'error'      => $page['error'] ?? null,
        ]);
    }

    /** Expand a normalized Leads status bucket to the raw statuses it folds in. */
    private function _crm_lead_status_bucket(string $bucket)
    {
        $bucket = trim($bucket);
        if ($bucket === '') return '';
        static $MAP = [
            'new'       => ['new', 'pending'],
            'contacted' => ['contacted', 'interested'],
            'approved'  => ['approved'],
            'enrolled'  => ['enrolled', 'admitted'],
            'rejected'  => ['rejected'],
        ];
        return $MAP[$bucket] ?? $bucket; // unknown -> exact match
    }

    /**
     * C7 — Leads funnel counts via server-side COUNT aggregation (no document
     * reads, no full scan). Replaces the old client-side tally over the full
     * dataset. Reuses Firestore_service::count (runAggregationQuery); legacy
     * status spellings are folded to match the grid's normalized buckets.
     */
    public function fetch_leads_stats()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $base = [['schoolId', '==', $this->school_id], ['session', '==', $this->session_year]];
        $cnt  = fn($status) => $this->fs->count('crmApplications', array_merge($base, [['status', '==', $status]]));
        $stats = [
            'total'     => $this->fs->count('crmApplications', $base),
            'new'       => $cnt('new') + $cnt('pending'),
            'contacted' => $cnt('contacted') + $cnt('interested'),
            'approved'  => $cnt('approved'),
            'enrolled'  => $cnt('enrolled') + $cnt('admitted'),
            'rejected'  => $cnt('rejected'),
        ];
        return $this->json_success(['stats' => $stats]);
    }

    // LEAD SYSTEM — Single lead detail (AJAX)
    public function admission_lead()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $leadId = trim($this->input->get_post('lead_id') ?? '');
        if ($leadId === '') return $this->json_error('Lead ID required.');
        $leadId = $this->safe_path_segment($leadId, 'lead_id');

        $lead = $this->_crm_get('crmApplications', $leadId);
        if (!is_array($lead)) return $this->json_error('Lead not found.');
        $lead['id'] = $leadId;
        return $this->json_success(['lead' => $lead]);
    }

    // LEAD SYSTEM — Update lead status (AJAX)
    public function update_lead_status()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_manage', 'SIS', 'manage');
        $leadId = trim($this->input->post('lead_id') ?? '');
        $status = trim($this->input->post('status') ?? '');
        if ($leadId === '' || $status === '') return $this->json_error('Lead ID and status required.');
        $leadId = $this->safe_path_segment($leadId, 'lead_id');

        // MED-16: 'enrolled'/'admitted' are TERMINAL and must not be set by a
        // direct status write — enrollment creates a real student + Auth + fees
        // and only happens via enroll_student(). Writing status=enrolled here
        // produced a phantom "enrolled" lead with no backing student record.
        $allowed = ['new', 'contacted', 'interested', 'approved', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            return $this->json_error($status === 'enrolled' || $status === 'admitted'
                ? 'Use the Enroll action to enroll a student — status can’t be set to enrolled directly.'
                : 'Invalid status.');
        }

        $lead = $this->_crm_get('crmApplications', $leadId);
        if (!is_array($lead)) return $this->json_error('Lead not found.');

        $now = date('Y-m-d H:i:s');
        // M7-B: single CAS-atomic write — business fields (status) + bounded history
        // in one commit; concurrency-safe (no lost update); one Firestore write (M3).
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $leadId, 'history',
            ['action' => "Status changed to {$status}", 'by' => $this->admin_name, 'timestamp' => $now],
            crm_history_cap(),
            ['status' => $status, 'updated_at' => $now]);
        log_audit('CRM', 'update_lead_status', $leadId, "Lead status changed to '{$status}' for " . ($lead['student_name'] ?? ''),
            ['appId' => $leadId, 'entityType' => 'application', 'meta' => ['status' => $status]]);
        return $this->json_success(['message' => 'Status updated.']);
    }

    // LEAD SYSTEM — Fetch lead data for admission form prefill
    public function get_lead_data()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_admission', 'SIS', 'manage');
        $leadId = trim($this->input->get('lead_id') ?? '');
        if ($leadId === '') return $this->json_error('Lead ID required.');
        $leadId = $this->safe_path_segment($leadId, 'lead_id');

        $lead = $this->_crm_get('crmApplications', $leadId);
        if (!is_array($lead)) return $this->json_error('Lead not found.');
        $lead['id'] = $leadId;
        return $this->json_success(['lead' => $lead]);
    }

    // LEAD SYSTEM — Admission analytics dashboard (single Firebase read, all PHP computation)
    public function admission_analytics()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $session = $this->session_year;

        // Single read, session-scoped at Firestore (F5) — analytics is per-session.
        $applications = $this->_crm_list('crmApplications', [['session', '==', $session]]);
        if (!is_array($applications)) $applications = [];

        // Filter to current session + compute all metrics in one pass
        $total = 0;
        $byStatus = ['new'=>0,'contacted'=>0,'interested'=>0,'approved'=>0,'admitted'=>0,'enrolled'=>0,'rejected'=>0];
        $byClass  = [];
        $bySource = ['public_form'=>0,'manual'=>0,'crm'=>0];
        $byMonth  = [];  // month label → count
        $recentLeads = [];

        foreach ($applications as $id => $app) {
            if (!is_array($app)) continue;
            if (($app['session'] ?? '') !== $session) continue;

            $total++;
            $status = strtolower($app['status'] ?? 'new');
            if (isset($byStatus[$status])) $byStatus[$status]++;
            else $byStatus[$status] = 1;

            $cls = $app['class'] ?? 'Unknown';
            $byClass[$cls] = ($byClass[$cls] ?? 0) + 1;

            $src = $app['source'] ?? 'crm';
            if ($src === 'public_form') $bySource['public_form']++;
            else $bySource['crm']++;

            // Monthly trend from created_at
            $created = $app['created_at'] ?? '';
            if ($created !== '') {
                $ts = strtotime($created);
                if ($ts) {
                    $monthKey = date('Y-m', $ts);
                    $byMonth[$monthKey] = ($byMonth[$monthKey] ?? 0) + 1;
                }
            }

            // Collect recent 10 for quick-view table
            if (count($recentLeads) < 10) {
                $recentLeads[] = [
                    'id'     => $id,
                    'name'   => $app['student_name'] ?? '',
                    'class'  => $cls,
                    'status' => $status,
                    'source' => $src,
                    'date'   => substr($created, 0, 10),
                ];
            }
        }

        // Sort class keys naturally
        uksort($byClass, 'strnatcmp');
        ksort($byMonth);

        $admitted = ($byStatus['admitted'] ?? 0) + ($byStatus['enrolled'] ?? 0);
        $conversionRate = $total > 0 ? round(($admitted / $total) * 100, 1) : 0;

        $data = [
            'session_year'    => $session,
            'total'           => $total,
            'admitted'        => $admitted,
            'conversion_rate' => $conversionRate,
            'by_status'       => $byStatus,
            'by_class'        => $byClass,
            'by_source'       => $bySource,
            'by_month'        => $byMonth,
            'recent_leads'    => $recentLeads,
        ];

        $this->load->view('include/header');
        $this->load->view('sis/admission_analytics', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       ADMISSION CRM — All methods merged from Admission_crm.php
       Manages: Inquiry → Application → Pipeline → Approval → Waitlist → Enrollment
    ══════════════════════════════════════════════════════════════════════ */

    public function crm_dashboard()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $session = $this->session_year;

        // Session-scoped reads (F5): the dashboard only reports the active
        // session, so filter at Firestore instead of fetching every all-time
        // doc and discarding most in PHP (was 3 full-collection scans/load).
        $sessionCond  = [['session', '==', $session]];
        $inquiries    = $this->_crm_list('crmInquiries', $sessionCond);
        $applications = $this->_crm_list('crmApplications', $sessionCond);
        $waitlist     = $this->_crm_list('crmWaitlist', $sessionCond);
        $settings     = $this->_crm_get_settings();
        if (!is_array($inquiries))    $inquiries = [];
        if (!is_array($applications)) $applications = [];
        if (!is_array($waitlist))     $waitlist = [];
        if (!is_array($settings))     $settings = [];

        $sessionInquiries = array_filter($inquiries, fn($i) => is_array($i) && ($i['session'] ?? '') === $session);
        $sessionApps = array_filter($applications, fn($a) => is_array($a) && ($a['session'] ?? '') === $session);
        $sessionWaitlist = array_filter($waitlist, fn($w) => is_array($w) && ($w['session'] ?? '') === $session);

        $stats = ['total_inquiries' => count($sessionInquiries), 'total_applications' => count($sessionApps),
            'total_waitlist' => count($sessionWaitlist), 'pending_approval' => 0, 'approved' => 0, 'rejected' => 0, 'enrolled' => 0];
        foreach ($sessionApps as $app) {
            $status = $app['status'] ?? 'pending';
            if (isset($stats[$status])) $stats[$status]++;
            elseif ($status === 'pending') $stats['pending_approval']++;
        }

        $classBreakdown = [];
        foreach ($sessionApps as $app) {
            $cls = $app['class'] ?? 'Unknown';
            if (!isset($classBreakdown[$cls])) $classBreakdown[$cls] = ['applied'=>0,'approved'=>0,'enrolled'=>0,'waitlisted'=>0];
            $classBreakdown[$cls]['applied']++;
            $st = $app['status'] ?? '';
            if (isset($classBreakdown[$cls][$st])) $classBreakdown[$cls][$st]++;
        }
        foreach ($sessionWaitlist as $w) {
            $cls = $w['class'] ?? 'Unknown';
            if (!isset($classBreakdown[$cls])) $classBreakdown[$cls] = ['applied'=>0,'approved'=>0,'enrolled'=>0,'waitlisted'=>0];
            $classBreakdown[$cls]['waitlisted']++;
        }
        ksort($classBreakdown);

        $sourceBreakdown = [];
        foreach ($sessionInquiries as $inq) { $src = $inq['source'] ?? 'Walk-in'; $sourceBreakdown[$src] = ($sourceBreakdown[$src] ?? 0) + 1; }

        $monthlyTrend = [];
        foreach ($sessionInquiries as $inq) { $dt = $inq['created_at'] ?? ''; if ($dt) { $m = substr($dt,0,7); $monthlyTrend[$m] = ($monthlyTrend[$m] ?? 0)+1; } }
        ksort($monthlyTrend);
        $monthlyTrend = array_slice($monthlyTrend, -6, 6, true);

        // ── Overview redesign (2026-07): "In review" funnel count + the
        // "Needs attention" (aging / unpaid) and "Recent activity" feeds the
        // hub renders. Computed from the apps already loaded above. ──
        $inReview = 0; $attention = []; $activity = [];
        $nowTs = time();
        $reviewStages = ['document_collection','under_review','docs','interview','assessment'];
        foreach ($sessionApps as $app) {
            $stage  = (string) ($app['stage'] ?? '');
            $status = (string) ($app['status'] ?? 'pending');
            if (in_array($stage, $reviewStages, true)) $inReview++;
            $dt   = (string) ($app['updated_at'] ?? $app['created_at'] ?? '');
            $days = $dt !== '' ? (int) floor(($nowTs - strtotime($dt)) / 86400) : null;
            $unpaid = !empty($app['payment_status']) && $app['payment_status'] !== 'paid';
            if ($status !== 'enrolled' && $status !== 'rejected' && (($days !== null && $days > 7) || $unpaid)) {
                $attention[] = ['name'=>(string)($app['student_name']??''), 'class'=>(string)($app['class']??''),
                    'stage'=>$stage, 'days'=>$days, 'unpaid'=>$unpaid];
            }
            foreach ((is_array($app['history'] ?? null) ? $app['history'] : []) as $h) {
                $activity[] = ['name'=>(string)($app['student_name']??''), 'action'=>(string)($h['action']??''),
                    'ts'=>(string)($h['timestamp']??''), 'by'=>(string)($h['by']??'')];
            }
        }
        usort($attention, fn($a,$b) => ($b['days'] ?? 0) <=> ($a['days'] ?? 0));
        $attention = array_slice($attention, 0, 6);
        usort($activity, fn($a,$b) => strcmp($b['ts'] ?? '', $a['ts'] ?? ''));
        $activity = array_slice($activity, 0, 6);
        $stats['in_review'] = $inReview;

        $data = compact('stats') + ['class_breakdown'=>$classBreakdown,'source_breakdown'=>$sourceBreakdown,'monthly_trend'=>$monthlyTrend,'settings'=>$settings,'session_year'=>$session,'attention'=>$attention,'activity'=>$activity];
        $this->load->view('include/header');
        $this->load->view('admission_crm/index', $data);
        $this->load->view('include/footer');
    }

    public function inquiries()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_crm_classes();
        $this->load->view('include/header');
        $this->load->view('admission_crm/inquiries', $data);
        $this->load->view('include/footer');
    }

    public function fetch_inquiries()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $session = $this->session_year;
        // Session-scoped read (F5): inquiries screen shows only the active session.
        $inquiries = $this->_crm_list('crmInquiries', [['session', '==', $session]]);
        if (!is_array($inquiries)) $inquiries = [];
        $result = [];
        foreach ($inquiries as $id => $inq) {
            if (!is_array($inq) || ($inq['session'] ?? '') !== $session) continue;
            $inq['id'] = $id;
            $result[] = $inq;
        }
        usort($result, fn($a,$b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return $this->json_success(['inquiries' => $result]);
    }

    public function save_inquiry()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_inquiry', 'SIS', 'edit');
        $id = trim($this->input->post('id') ?? '');
        if ($id !== '') $id = $this->safe_path_segment($id, 'id');
        $student_name   = trim($this->input->post('student_name') ?? '');
        $parent_name    = trim($this->input->post('parent_name') ?? '');
        $phone          = trim($this->input->post('phone') ?? '');
        $email          = trim($this->input->post('email') ?? '');
        $class          = trim($this->input->post('class') ?? '');
        $source         = trim($this->input->post('source') ?? 'Walk-in');
        $notes          = trim($this->input->post('notes') ?? '');
        $status         = trim($this->input->post('status') ?? 'new');
        $follow_up_date = trim($this->input->post('follow_up_date') ?? '');

        if ($student_name === '' || $parent_name === '' || $phone === '') return $this->json_error('Student name, parent name, and phone are required');
        if (!preg_match('/^\+?\d{10,15}$/', preg_replace('/[\s\-]/', '', $phone))) return $this->json_error('Invalid phone number format');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->json_error('Invalid email address');

        $now = date('Y-m-d H:i:s');
        if ($id) {
            $existing = $this->_crm_get('crmInquiries', $id);
            if (!is_array($existing)) return $this->json_error('Inquiry not found');
            $data = array_merge($existing, compact('student_name','parent_name','phone','email','class','source','notes','status','follow_up_date') + ['updated_at'=>$now]);
            $this->_crm_set("crmInquiries", $id, $data);
            // M3: _crm_set is the single canonical Firestore write (removed redundant dual-write).
            // M7-A: permanent audit (canonical auditLogs).
            log_audit('CRM', 'update_inquiry', $id, "Updated inquiry for {$student_name}",
                ['entityType' => 'inquiry', 'meta' => ['status' => $status]]);
        } else {
            $id = $this->_crm_next_id('Inquiry', 'INQ', 5);
            $data = compact('student_name','parent_name','phone','email','class','source','notes','status','follow_up_date') + [
                'inquiry_id'=>$id, 'session'=>$this->session_year, 'created_at'=>$now, 'updated_at'=>$now, 'created_by'=>$this->admin_name,
            ];
            $this->_crm_set("crmInquiries", $id, $data);
            // Counter managed by _crm_next_id — no separate write needed.
            // M3: _crm_set is the single canonical Firestore write (removed redundant dual-write).
            // M7-A: permanent audit (canonical auditLogs).
            log_audit('CRM', 'create_inquiry', $id, "Created inquiry for {$student_name}",
                ['entityType' => 'inquiry', 'meta' => ['source' => $source]]);
        }
        return $this->json_success(['id' => $id, 'message' => 'Inquiry saved.']);
    }

    public function delete_inquiry()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_delete_inquiry', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Inquiry ID required');
        $safeId = $this->safe_path_segment($id, 'id');
        $this->_crm_delete('crmInquiries', $safeId);
        // M3: _crm_delete is the single canonical Firestore delete (removed redundant dual-write).
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'delete_inquiry', $safeId, "Deleted inquiry {$safeId}", ['entityType' => 'inquiry']);
        return $this->json_success();
    }

    public function convert_to_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_convert', 'SIS', 'manage');
        $inquiry_id = trim($this->input->post('inquiry_id') ?? '');
        if (!$inquiry_id) return $this->json_error('Inquiry ID required');
        $inquiry_id = $this->safe_path_segment($inquiry_id, 'inquiry_id');
        $inquiry = $this->_crm_get('crmInquiries', $inquiry_id);
        if (!is_array($inquiry)) return $this->json_error('Inquiry not found');
        // Idempotency (F6): a double-click / concurrent convert would mint a
        // second application and orphan the first (inquiry.application_id gets
        // overwritten). Reject if this inquiry was already converted.
        if (($inquiry['status'] ?? '') === 'converted' || !empty($inquiry['application_id'])) {
            return $this->json_error('This inquiry has already been converted to an application.');
        }

        $app_id = $this->_crm_next_id('Application', 'APP', 5);
        $now = date('Y-m-d H:i:s');
        $application = [
            'application_id'=>$app_id, 'inquiry_id'=>$inquiry_id,
            'student_name'=>$inquiry['student_name']??'', 'parent_name'=>$inquiry['parent_name']??'',
            'phone'=>$inquiry['phone']??'', 'email'=>$inquiry['email']??'', 'class'=>$inquiry['class']??'',
            'session'=>$inquiry['session']??$this->session_year, 'status'=>'pending', 'stage'=>'document_collection',
            'created_at'=>$now, 'updated_at'=>$now, 'created_by'=>$this->admin_name,
            'source'=>'admin', 'possible_duplicate'=>false,
            'source_inquiry'=>$inquiry_id, 'dob'=>'', 'gender'=>'', 'address'=>'',
            'father_name'=>$inquiry['parent_name']??'', 'mother_name'=>'', 'documents'=>[], 'notes'=>$inquiry['notes']??'',
            'history'=>[['action'=>'Application created from inquiry '.$inquiry_id, 'by'=>$this->admin_name, 'timestamp'=>$now]],
        ];
        $this->load->helper('admission_search');
        $application['searchTokens'] = admission_search_tokens($application['student_name'] ?? '');
        $this->_crm_set("crmApplications", $app_id, $application);
        // Counter managed by _crm_next_id — no separate write needed.
        $this->_crm_update("crmInquiries", $inquiry_id, ['status'=>'converted','application_id'=>$app_id,'updated_at'=>$now]);
        // M3: _crm_set/_crm_update above are the single canonical Firestore writes
        // (removed redundant dual-write of identical application + inquiry data).
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'convert_inquiry', $app_id, "Converted inquiry {$inquiry_id} to application for " . ($application['student_name'] ?? ''),
            ['appId' => $app_id, 'entityType' => 'application', 'meta' => ['from_inquiry' => $inquiry_id]]);
        return $this->json_success(['application_id' => $app_id]);
    }

    public function applications()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        // IA reorg (Phase 3): standalone → the tabbed Applications shell hosting
        // Table / Board / Funnel in isolated iframes. When loaded inside that
        // shell (?embed=1) → render the Table view itself, chrome-less. Same
        // proven pattern as the Attendance tab-shell; no JS merge, no collisions.
        if ($this->input->get('embed') === '1') {
            $data['session_year'] = $this->session_year;
            $data['classes']      = $this->_get_crm_classes();
            $this->load->view('include/header');
            $this->load->view('admission_crm/applications', $data);
            $this->load->view('include/footer');
            return;
        }
        $this->load->view('include/header');
        $this->load->view('admission_crm/applications_shell');
        $this->load->view('include/footer');
    }

    public function fetch_applications()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        // C7 — server-side paginated + filtered + searched (was: full-scan +
        // client-side filter). session/school isolation enforced in _crm_page.
        $page = $this->_crm_page(
            ['status' => $this->input->get_post('status'), 'class' => $this->input->get_post('class')],
            (string) $this->input->get_post('search'),
            (int) ($this->input->get_post('limit') ?: 25),
            $this->_crm_cursor_from_input()
        );
        return $this->json_success([
            'applications' => $page['items'],
            'nextCursor'   => $page['nextCursor'],
            'hasMore'      => $page['hasMore'],
            'mode'         => $page['mode'],
            'error'        => $page['error'] ?? null,
        ]);
    }

    public function save_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_app', 'SIS', 'edit');
        $id = trim($this->input->post('id') ?? '');
        if ($id !== '') $id = $this->safe_path_segment($id, 'id');
        $now = date('Y-m-d H:i:s');
        $fields = ['student_name','parent_name','father_name','mother_name','phone','email','class','section','dob','gender',
            'address','city','state','pincode','previous_school','previous_class','previous_marks',
            'blood_group','category','religion','nationality','father_occupation','mother_occupation',
            'guardian_name','guardian_phone','guardian_relation','notes'];
        $data = [];
        foreach ($fields as $f) $data[$f] = trim($this->input->post($f) ?? '');
        if ($data['student_name'] === '' || $data['class'] === '') return $this->json_error('Student name and class are required');
        if ($data['phone'] !== '' && !preg_match('/^\+?\d{10,15}$/', preg_replace('/[\s\-]/','',$data['phone']))) return $this->json_error('Invalid phone number format');
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) return $this->json_error('Invalid email address');

        // C7 — regenerate searchTokens from the (possibly edited) student name.
        // Applies to BOTH the update and create branches below; array_merge in the
        // create branch preserves this key.
        $this->load->helper('admission_search');
        $data['searchTokens'] = admission_search_tokens($data['student_name']);

        if ($id) {
            $existing = $this->_crm_get('crmApplications', $id);
            if (!is_array($existing)) return $this->json_error('Application not found');
            $data['updated_at'] = $now;
            // M7-B: single CAS-atomic write — the full edit payload (incl.
            // searchTokens) + bounded history in ONE commit; concurrency-safe; one
            // write (M3). schoolId/session preserved by the merge/patch.
            $this->load->helper('admission_crm');
            $this->fs->casAppendBounded('crmApplications', $id, 'history',
                ['action'=>'Application updated','by'=>$this->admin_name,'timestamp'=>$now],
                crm_history_cap(),
                $data);
            // M7-A: permanent audit (canonical auditLogs).
            log_audit('CRM', 'edit_application', $id, "Edited application for " . ($data['student_name'] ?? ''),
                ['appId' => $id, 'entityType' => 'application']);
            return $this->json_success(['id' => $id]);
        } else {
            $app_id = $this->_crm_next_id('Application', 'APP', 5);
            // `source: admin` distinguishes admin-created applications
            // from public-form ones in the CRM grid; admin-created apps
            // never get the soft-duplicate flag.
            $data = array_merge($data, ['application_id'=>$app_id,'session'=>$this->session_year,'status'=>'pending','stage'=>'document_collection',
                'source'=>'admin','possible_duplicate'=>false,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$this->admin_name,'documents'=>[],
                'history'=>[['action'=>'Application created directly','by'=>$this->admin_name,'timestamp'=>$now]]]);
            $this->_crm_set("crmApplications", $app_id, $data);
            // Counter managed by _crm_next_id — no separate write needed.
            // M3: _crm_set is the single canonical Firestore write (removed redundant dual-write).
            // M7-A: permanent audit (canonical auditLogs).
            log_audit('CRM', 'create_application', $app_id, "Created application for " . ($data['student_name'] ?? '') . " (class " . ($data['class'] ?? '') . ")",
                ['appId' => $app_id, 'entityType' => 'application', 'meta' => ['source' => 'admin']]);
            return $this->json_success(['id' => $app_id]);
        }
    }

    public function get_application()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $id = trim($this->input->get('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found');
        $app['id'] = $id;
        return $this->json_success(['application' => $app]);
    }

    /**
     * M7-B — Full permanent audit trail for one application, for the "View Full
     * Audit History" affordance. The crmApplications.history[]/timeline[] arrays
     * are bounded recent caches; this reads the COMPLETE trail from the canonical
     * auditLogs + auditArchive (M7-A) via the shared reader. Role-guarded +
     * tenant-scoped (audit_trail_for_app is schoolId-scoped internally).
     */
    public function admission_audit_trail()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $appId = trim($this->input->get_post('app_id') ?? '');
        if ($appId === '') return $this->json_error('Application ID required');
        $appId = $this->safe_path_segment($appId, 'app_id');
        $trail = function_exists('audit_trail_for_app') ? audit_trail_for_app($appId, 500) : [];
        return $this->json_success(['trail' => $trail, 'appId' => $appId]);
    }

    /**
     * POST — Admission counter: collect the admission fee OFFLINE.
     *
     * Cash / UPI / Cheque / DD / Bank Transfer / POS-Card. The amount is
     * SERVER-resolved (from the application; never the client) and NON-EDITABLE.
     * Reuses the SINGLE shared receipt + accounting path (Admission_collection)
     * — the same one the online flow uses — so there is no duplicate receipt or
     * accounting logic. Posts to the configured admission income account, tagged
     * collection_type='admission'. Hard period-lock pre-check (commercial-correct).
     */
    public function collect_admission_fee()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_manage');

        $id       = trim($this->input->post('app_id') ?? $this->input->post('id') ?? '');
        $mode     = trim((string) $this->input->post('payment_mode'));
        $bankCode = trim((string) $this->input->post('bank_ledger_code'));
        $bankRef  = trim((string) $this->input->post('bank_reference'));
        $remarks  = trim((string) $this->input->post('remarks'));

        if (!$id)          return $this->json_error('Application ID required.');
        if ($mode === '')  return $this->json_error('Payment mode required.');
        $id  = $this->safe_path_segment($id, 'id');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found.');
        if (($app['payment_status'] ?? '') === 'paid') {
            return $this->json_error('Admission fee has already been collected for this application.');
        }

        // ── Server-resolved amount (NEVER from the client) ──
        // Fix D — use the SINGLE canonical resolver shared with the online flow, so
        // the counter charges the exact same CLASS-SPECIFIC admission fee
        // (feeStructures → global schools.admissionFee). Previously the offline path
        // read only $app['fee_amount'] (unset for admin-created apps) then fell back
        // to the GLOBAL flat fee, silently under/over-charging where a class-specific
        // fee exists.
        $schoolDoc = $this->firebase->firestoreGet('schools', $this->school_name) ?? [];
        $this->load->helper('admission_crm');
        $feeInfo = admission_resolve_fee($this->fs, is_array($schoolDoc) ? $schoolDoc : [], $this->session_year, (string) ($app['class'] ?? ''));
        $amount  = round((float) ($feeInfo['amount'] ?? 0), 2);
        if ($amount <= 0) {
            // Legacy fallback: an amount explicitly stamped on the application
            // (e.g. by the online flow) when no structure/global fee resolves.
            $amount = round((float) ($app['fee_amount'] ?? 0), 2);
        }
        if ($amount <= 0) {
            return $this->json_error('Admission fee is not configured for this application. Set the fee before collecting.');
        }
        $resolved = ((float) ($feeInfo['amount'] ?? 0)) > 0;
        $feeHead  = $resolved ? (string) $feeInfo['fee_head'] : (string) ($app['fee_head'] ?? 'Admission Fee');
        $feeLabel = $resolved ? (string) $feeInfo['label']    : (string) ($app['fee_label'] ?? $feeHead);

        // ── Map display mode → accounting mode; validate bank ledger (non-cash) ──
        $modeMap = [
            'cash' => 'cash', 'upi' => 'upi', 'cheque' => 'cheque', 'neft' => 'neft', 'rtgs' => 'rtgs',
            'dd' => 'bank_transfer', 'demand draft' => 'bank_transfer', 'bank transfer' => 'bank_transfer',
            'pos' => 'bank_transfer', 'pos/card' => 'bank_transfer', 'card' => 'bank_transfer',
        ];
        $acctMode = $modeMap[strtolower($mode)] ?? 'bank_transfer';
        $isCash   = ($acctMode === 'cash');
        if ($isCash) {
            $bankCode = '';
        } else {
            if ($bankCode === '') return $this->json_error('Select a bank ledger for a non-cash collection.');
            $okBank = false;
            foreach ((array) $this->firebase->firestoreQuery('chartOfAccounts', [['schoolId', '==', $this->school_name]]) as $row) {
                $cd = $row['data'] ?? $row;
                if ((string) ($cd['code'] ?? '') !== $bankCode) continue;
                if (!empty($cd['is_bank']) && empty($cd['is_group']) && ($cd['status'] ?? '') === 'active') $okBank = true;
                break;
            }
            if (!$okBank) return $this->json_error('Invalid or inactive bank ledger selected.');
        }

        // ── Period-lock pre-check (reuse Accounting_period_lock) ──
        $this->load->library('Accounting_cache', null, 'acctCacheAdm');
        $this->load->library('Accounting_period_lock', null, 'acctLockAdm');
        $this->acctCacheAdm->init($this->school_name, $this->session_year);
        $this->acctLockAdm->init($this->firebase, $this->acctCacheAdm, $this->school_name, $this->session_year);
        $lock = $this->acctLockAdm->forceValidate(date('Y-m-d'));
        if (!empty($lock['locked'])) {
            return $this->json_error('The accounting period is closed up to ' . ($lock['lockedUntil'] ?? '') . '. Reopen it before collecting the admission fee.');
        }

        // ── SHARED receipt + accounting path (one path with online) ──
        $this->load->library('Admission_collection', null, 'admissionCol');
        $this->admissionCol->init($this->firebase, $this->fs, $this->school_name, $this->session_year);
        $rc = $this->admissionCol->record_payment([
            'app_id'         => $id,
            'amount'         => $amount,
            'currency'       => 'INR',
            'student_name'   => (string) ($app['student_name'] ?? ''),
            'father_name'    => (string) ($app['father_name'] ?? $app['parent_name'] ?? ''),
            'applied_class'  => (string) ($app['class'] ?? ''),
            'fee_head'       => $feeHead,
            'fee_label'      => $feeLabel,
            'payment_mode'   => $acctMode,
            'mode_label'     => $mode,
            'bank_code'      => $bankCode,
            'bank_reference' => $bankRef,
            'remarks'        => $remarks,
            'collected_by'   => $this->admin_name ?: ($this->admin_id ?: 'Admin'),
            'source'         => 'offline',
            'admission_ref'  => (string) ($app['admission_ref'] ?? ('ADMREF-' . strtoupper($id))),
        ]);
        if (!is_array($rc)) return $this->json_error('Collection failed — invalid amount.');
        $receiptNo = (string) $rc['receipt_no'];

        // ── Mark the lead paid + history ──
        // M7-B: single CAS-atomic write (payment fields + bounded history),
        // concurrency-safe. dedupeKey on the receipt prevents a duplicate history
        // entry if this collection is retried; financial truth is in
        // admissionReceipts + accounting journal (record_payment already ran).
        $now = date('Y-m-d H:i:s');
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $id, 'history',
            [
                'action'    => "Admission fee collected offline ({$mode}) — Receipt {$receiptNo}",
                'by'        => $this->admin_name ?: 'Admin',
                'timestamp' => $now,
            ],
            crm_history_cap(),
            [
                'payment_status' => 'paid',
                'payment_mode'   => $mode,
                'receipt_no'     => $receiptNo,
                'updated_at'     => $now,
            ],
            'paid_' . $receiptNo);

        return $this->json_success([
            'message'    => "Admission fee collected. Receipt {$receiptNo}.",
            'receipt_no' => $receiptNo,
            'amount'     => $amount,
        ]);
    }

    /**
     * GET — Print the commercial admission-fee receipt (PDF) by ADM number.
     * Reuses the shared receipt view; reads ONLY this tenant's admissionReceipts.
     */
    public function admission_receipt()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $rk = trim((string) $this->input->get('receipt'));
        if ($rk === '' || !preg_match('/^ADM[0-9]{4,10}$/', $rk)) return show_404();

        $receipt = $this->fs->get('admissionReceipts', $this->fs->docId($rk));
        if (!is_array($receipt) || (string) ($receipt['schoolId'] ?? '') !== $this->school_name) {
            return show_404();   // tenant-scoped: never expose another school's receipt
        }

        $schoolDoc = $this->firebase->firestoreGet('schools', $this->school_name) ?? [];
        // Embed the logo as a data: URI (dompdf can't fetch Firebase Storage URLs
        // reliably). CACHED + SSRF-hardened resolver (M4) — Firebase Storage is the
        // source of truth; this is a disposable CI-file-cache render optimization.
        $this->load->helper('public_url');
        $logoRaw = (string) ($schoolDoc['logo'] ?? $schoolDoc['logoUrl'] ?? $schoolDoc['logo_url'] ?? '');
        $logoEmbed = $logoRaw !== '' ? cached_school_logo_data_uri($this->school_name, $logoRaw) : '';
        $profile = array_merge(is_array($schoolDoc) ? $schoolDoc : [], [
            'display_name' => $schoolDoc['name'] ?? $schoolDoc['displayName'] ?? $this->school_name,
            'school_name'  => $schoolDoc['name'] ?? '',
            'logo_url'     => $logoEmbed, // '' → view hides it (no broken box)
            'address'      => $schoolDoc['address'] ?? '',
            'phone'        => $schoolDoc['phone']  ?? $schoolDoc['contact'] ?? '',
            'email'        => $schoolDoc['email']  ?? '',
        ]);

        $this->load->library('pdf_generator');
        $html = $this->load->view('admission/fee_receipt', ['receipt' => $receipt, 'school_profile' => $profile], true);
        $this->pdf_generator->inline($html, 'admission_receipt_' . $rk . '.pdf');
    }

    /**
     * GET — Admission QR poster (SCAN TO APPLY). HTML (print + PNG download) or
     * PDF via ?pdf=1. Client-side print; the QR encodes ONLY this school's
     * public admission URL (no secrets, tenant-scoped).
     */
    public function admission_poster()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $wantPdf = ((string) $this->input->get('pdf')) === '1';

        $schoolDoc  = $this->firebase->firestoreGet('schools', $this->school_name) ?? [];

        // Parent-facing link → canonical PRODUCTION origin (www.zenxii.com), never
        // the admin's current request host. A printed poster must resolve publicly
        // even though the admin may be on localhost/LAN/ngrok while generating it.
        // Prefer the readable slug over the raw SCH_ id (both resolve).
        $this->load->helper('public_url');
        $admissionRef = trim((string) ($schoolDoc['admissionSlug'] ?? ''));
        if ($admissionRef === '') {
            // Self-heal: name set (via School Profile page / onboarding) but no
            // slug yet — mint it now so the poster URL is professional too.
            $nm = trim((string) ($schoolDoc['name'] ?? $schoolDoc['displayName'] ?? ''));
            if ($nm !== '' && $nm !== $this->school_name) {
                $admissionRef = ensure_admission_slug($this->firebase, $this->school_name, $nm);
                if ($admissionRef !== '') $schoolDoc['admissionSlug'] = $admissionRef;
            }
            if ($admissionRef === '') $admissionRef = $this->school_name;
        }
        $admissionUrl = public_admission_form_url($admissionRef);

        // Render the QR locally as a data: URI — never an external QR API. External
        // hosts are blocked by our CSP img-src in-browser and fail offline; the local
        // SVG is vector so it stays crisp at any poster/banner print size.
        $this->load->helper('qr_token');

        // Resolve the school's branding from its profile. When the School Profile
        // has never been filled in, every field is blank — do NOT fall back to the
        // raw SCH_* id (that looks like a bug on a printed poster). Flag it instead
        // so the view can show a clean placeholder + an admin-only "configure" hint.
        $schoolName = trim((string) ($schoolDoc['name'] ?? $schoolDoc['displayName'] ?? $schoolDoc['schoolName'] ?? ''));
        if ($schoolName === '' || $schoolName === $this->school_name) {
            $schoolName = trim((string) ($this->school_display_name ?? ''));
        }
        $nameConfigured = ($schoolName !== '' && $schoolName !== $this->school_name);

        $addressParts = array_filter([
            trim((string) ($schoolDoc['address'] ?? '')),
            trim((string) ($schoolDoc['city'] ?? '')),
            trim((string) ($schoolDoc['state'] ?? '')),
            trim((string) ($schoolDoc['pincode'] ?? '')),
        ], fn($v) => $v !== '');

        // Logo. dompdf can't fetch the Firebase Storage URL (XAMPP SSL), so for
        // the PDF we embed the bytes as a data: URI; the browser HTML can use the
        // URL directly (CSP allows *.googleapis.com). If the PDF fetch fails, we
        // omit the logo rather than render a broken-image box.
        $logoUrl = (string) ($schoolDoc['logo'] ?? $schoolDoc['logoUrl'] ?? $schoolDoc['logo_url'] ?? '');
        $logoForView = $logoUrl;
        if ($wantPdf && $logoUrl !== '') {
            // CACHED + SSRF-hardened resolver (M4); '' on failure → view hides it.
            $logoForView = cached_school_logo_data_uri($this->school_name, $logoUrl);
        }

        $data = [
            'school_name'     => $nameConfigured ? $schoolName : 'Your School Name',
            'name_configured' => $nameConfigured,
            'logo_url'        => $logoForView,
            'address'         => implode(', ', $addressParts),
            'admission_url'   => $admissionUrl,
            'qr_svg'          => qr_svg_data_uri($admissionUrl),
            'want_pdf'        => $wantPdf,
        ];

        if ($wantPdf) {
            $this->load->library('pdf_generator');
            $html = $this->load->view('admission_crm/poster', $data, true);
            $this->pdf_generator->inline($html, 'admission_poster.pdf');
            return;
        }
        $this->load->view('include/header');
        $this->load->view('admission_crm/poster', $data);
        $this->load->view('include/footer');
    }

    public function delete_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_delete_app', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $safeId = $this->safe_path_segment($id, 'id');
        $this->_crm_delete('crmApplications', $safeId);
        // M3: _crm_delete is the single canonical Firestore delete (removed redundant dual-write).
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'delete_application', $safeId, "Deleted application {$safeId}",
            ['appId' => $safeId, 'entityType' => 'application']);
        return $this->json_success();
    }

    public function pipeline()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_crm_classes();
        $settings = $this->_crm_get_settings();
        $data['settings'] = is_array($settings) ? $settings : [];
        $this->load->view('include/header');
        $this->load->view('admission_crm/pipeline', $data);
        $this->load->view('include/footer');
    }

    public function fetch_pipeline()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $settings = $this->_crm_get_settings();
        $stages   = $settings['stages'] ?? $this->_default_stages();
        // C7 — each Kanban column is now a BOUNDED, index-backed per-stage query
        // (index #8 schoolId+session+stage+created_at, or #9 when a class filter
        // is applied) instead of the old unbounded full-scan grouped in memory.
        // Each column returns `hasMore` + `nextCursor` for per-column "Load More".
        $class    = trim((string) $this->input->get_post('class'));
        $pipeline = [];
        foreach ($stages as $key => $label) {
            $col = $this->_crm_pipeline_column((string) $key, $class, 50, null);
            $pipeline[$key] = array_merge(['label' => $label], $col);
        }
        return $this->json_success(['pipeline' => $pipeline, 'stages' => $stages]);
    }

    /**
     * One BOUNDED Kanban column page. Uses index #8 (stage) or #9 (stage+class).
     * `enrolled` is defensively excluded (it is not a pipeline stage). The
     * continuation cursor tracks the raw last row so it is stable regardless of
     * the display-side enrolled filter.
     */
    private function _crm_pipeline_column(string $stage, string $class, int $limit, ?array $cursor): array
    {
        $limit = max(1, min(100, $limit));
        $conds = [['stage', '==', $stage]];
        if ($class !== '') $conds[] = ['class', '==', $class];
        $items = [];
        $hasMore = false;
        $nextCursor = null;
        try {
            $res    = $this->fs->cursorQuery('crmApplications', $conds, $limit, $cursor);
            $prefix = $this->school_id . '_';
            foreach ($res['items'] as $it) {
                $r = is_array($it['data'] ?? null) ? $it['data'] : $it;
                if (($r['status'] ?? '') === 'enrolled') continue;
                $rawId   = (string) ($it['id'] ?? '');
                $r['id'] = (strpos($rawId, $prefix) === 0) ? substr($rawId, strlen($prefix)) : $rawId;
                $items[] = $r;
            }
            $hasMore    = $res['hasMore'];
            $nextCursor = $res['nextCursor'];
        } catch (\Exception $e) {
            log_message('error', "CRM pipeline stage {$stage} query failed: " . $e->getMessage());
        }
        return ['items' => $items, 'hasMore' => $hasMore, 'nextCursor' => $nextCursor];
    }

    /** Load one more bounded page for a SINGLE Kanban column (per-column pagination). */
    public function fetch_pipeline_column()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $stage = trim((string) $this->input->get_post('stage'));
        if ($stage === '') return $this->json_error('Stage required');
        $settings = $this->_crm_get_settings();
        $stages   = $settings['stages'] ?? $this->_default_stages();
        if (!array_key_exists($stage, $stages)) return $this->json_error('Invalid stage');
        $class = trim((string) $this->input->get_post('class'));
        $col   = $this->_crm_pipeline_column($stage, $class, 50, $this->_crm_cursor_from_input());
        return $this->json_success(array_merge(['stage' => $stage], $col));
    }

    public function update_stage()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_update_stage', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        $stage = $this->input->post('stage');
        if (!$id || !$stage) return $this->json_error('Application ID and stage required');
        $id = $this->safe_path_segment($id, 'id');
        $settings = $this->_crm_get_settings();
        $allowedStages = (is_array($settings) && !empty($settings['stages'])) ? array_keys($settings['stages']) : array_keys($this->_default_stages());
        if (!in_array($stage, $allowedStages, true)) return $this->json_error('Invalid stage: '.$stage);
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found');
        $now = date('Y-m-d H:i:s');
        $fromStage = (string) ($app['stage'] ?? '');
        // M7-B: single CAS-atomic write (stage + bounded history), concurrency-safe.
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $id, 'history',
            ['action'=>"Stage changed: {$fromStage} → {$stage}",'by'=>$this->admin_name,'timestamp'=>$now],
            crm_history_cap(),
            ['stage'=>$stage,'updated_at'=>$now]);
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'change_stage', $id, "Stage changed: {$fromStage} → {$stage} for " . ($app['student_name'] ?? ''),
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['from' => $fromStage, 'to' => $stage]]);
        return $this->json_success();
    }

    public function approve_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_approve', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        $remarks = trim($this->input->post('remarks') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found');
        $cs = $app['status'] ?? 'pending';
        if ($cs === 'enrolled') return $this->json_error('Cannot approve an already enrolled application');
        if ($cs === 'approved') return $this->json_error('Application is already approved');
        $now = date('Y-m-d H:i:s');
        // M7-B: single CAS-atomic write (approval fields + bounded history), concurrency-safe.
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $id, 'history',
            ['action'=>'Application approved'.($remarks?": {$remarks}":''),'by'=>$this->admin_name,'timestamp'=>$now],
            crm_history_cap(),
            ['status'=>'approved','stage'=>'approved','approved_by'=>$this->admin_name,'approved_at'=>$now,'remarks'=>$remarks,'updated_at'=>$now]);
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'approve_application', $id, "Approved application for " . ($app['student_name'] ?? '') . ($remarks ? " — {$remarks}" : ''),
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['status' => 'approved']]);
        return $this->json_success();
    }

    public function reject_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_reject', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        $reason = trim($this->input->post('reason') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found');
        $cs = $app['status'] ?? 'pending';
        if ($cs === 'enrolled') return $this->json_error('Cannot reject an already enrolled application');
        if ($cs === 'rejected') return $this->json_error('Application is already rejected');
        $now = date('Y-m-d H:i:s');
        // M7-B: single CAS-atomic write (rejection fields + bounded history), concurrency-safe.
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $id, 'history',
            ['action'=>'Application rejected'.($reason?": {$reason}":''),'by'=>$this->admin_name,'timestamp'=>$now],
            crm_history_cap(),
            ['status'=>'rejected','stage'=>'rejected','rejected_by'=>$this->admin_name,'rejected_at'=>$now,'reject_reason'=>$reason,'updated_at'=>$now]);
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'reject_application', $id, "Rejected application for " . ($app['student_name'] ?? '') . ($reason ? " — {$reason}" : ''),
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['status' => 'rejected', 'reason' => $reason]]);
        return $this->json_success();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Admission document lifecycle (Phase 1 — Canonical Document Service)
    //  verify / replace / delete a document attached to an application. Each is
    //  role-gated, tenant-scoped (docsvc init'd with this admin's school) and
    //  ownership-guarded (the documentId must be referenced by THIS application),
    //  then keeps the application's denormalized pointers in sync.
    // ─────────────────────────────────────────────────────────────────────

    /** True only if $documentId is referenced by this application (ownership guard). */
    private function _crm_doc_owned(array $app, string $documentId): bool
    {
        if ($documentId === '') return false;
        foreach ((array) ($app['documentRefs'] ?? []) as $r) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $documentId) return true;
        }
        foreach ((array) ($app['documents'] ?? []) as $d) {
            if (is_array($d) && (string) ($d['documentId'] ?? '') === $documentId) return true;
        }
        return (string) ($app['photoDocId'] ?? '') === $documentId
            || (string) ($app['aadhaarDocId'] ?? '') === $documentId;
    }

    /**
     * Build the student Profile Pic + Doc[] map from an application's uploaded
     * documents, and return the canonical document references to link on
     * enrollment (revised D1 — link, not copy). Prefers the enriched
     * documentRefs (Phase 1); falls back to the legacy *_url / documents[]
     * fields for applications created before the Document Service, so old
     * applications enroll identically with NO migration.
     *
     * @return array{profilePic:string, doc:array, links:array<int,array{documentId:string,role:string}>}
     */
    private function _admission_docs_for_student(array $app): array
    {
        $doc = []; $profilePic = ''; $links = [];
        $typeLabel = [
            'aadhaar'              => 'Aadhar Card',
            'birth_certificate'    => 'Birth Certificate',
            'transfer_certificate' => 'Transfer Certificate',
            'marksheet'            => 'Marksheet',
            'medical_certificate'  => 'Medical Certificate',
        ];
        $refs = is_array($app['documentRefs'] ?? null) ? $app['documentRefs'] : [];
        if ($refs) {
            foreach ($refs as $r) {
                if (!is_array($r)) continue;
                $docId = trim((string) ($r['documentId'] ?? ''));
                $type  = (string) ($r['type'] ?? '');
                $url   = trim((string) ($r['url'] ?? ''));
                if ($docId !== '') $links[] = ['documentId' => $docId, 'role' => (string) ($r['role'] ?? ($type !== '' ? $type : 'document'))];
                if ($url === '') continue;
                if ($type === 'photo')            { $profilePic = $url; $doc['Photo'] = ['url' => $url, 'thumbnail' => $url]; }
                elseif (isset($typeLabel[$type])) { $doc[$typeLabel[$type]] = ['url' => $url, 'thumbnail' => '']; }
                else {
                    $nm  = trim((string) ($r['label'] ?? 'Document'));
                    $key = $nm !== '' ? substr(preg_replace('/[^A-Za-z0-9 _.-]/', '', $nm), 0, 60) : '';
                    if ($key === '' || isset($doc[$key])) $key = 'Application Document ' . (count($doc) + 1);
                    $doc[$key] = ['url' => $url, 'thumbnail' => ''];
                }
            }
        } else {
            // Legacy fallback (pre-Document-Service applications) — reference the
            // stored URLs exactly as the prior behavior did (no links to create).
            $ph = trim((string) ($app['photo_url'] ?? ''));
            if ($ph !== '') { $profilePic = $ph; $doc['Photo'] = ['url' => $ph, 'thumbnail' => $ph]; }
            $aa = trim((string) ($app['aadhaar_url'] ?? ''));
            if ($aa !== '') $doc['Aadhar Card'] = ['url' => $aa, 'thumbnail' => ''];
            if (is_array($app['documents'] ?? null)) {
                foreach ($app['documents'] as $ld) {
                    if (!is_array($ld)) continue;
                    $lurl = trim((string) ($ld['url'] ?? ''));
                    if ($lurl === '') continue;
                    $nm  = trim((string) ($ld['name'] ?? ''));
                    $key = $nm !== '' ? substr(preg_replace('/[^A-Za-z0-9 _.-]/', '', $nm), 0, 60) : '';
                    if ($key === '' || isset($doc[$key])) $key = 'Application Document ' . (count($doc) + 1);
                    $doc[$key] = ['url' => $lurl, 'thumbnail' => ''];
                }
            }
        }
        return ['profilePic' => $profilePic, 'doc' => $doc, 'links' => $links];
    }

    /** POST — set a document's verification status: verified | rejected | pending. */
    public function verify_admission_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_verify_doc');
        $id     = $this->safe_path_segment(trim((string) $this->input->post('app_id')), 'app_id');
        $docId  = trim((string) $this->input->post('document_id'));
        $status = trim((string) $this->input->post('status'));
        if ($id === '' || $docId === '')                                return $this->json_error('Application and document are required.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId))                   return $this->json_error('Invalid document id.');
        if (!in_array($status, ['verified', 'rejected', 'pending'], true)) return $this->json_error('Invalid verification status.');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app))                    return $this->json_error('Application not found.');
        if (!$this->_crm_doc_owned($app, $docId)) return $this->json_error('Document does not belong to this application.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        $res = $this->docsvc->verify($docId, $status, (string) ($this->admin_id ?? 'system'));
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Verification failed.');

        // Keep the denormalized pointer fresh so the CRM reflects the new status
        // with no extra read.
        if (is_array($app['documentRefs'] ?? null)) {
            $refs = array_map(function ($r) use ($docId, $status) {
                if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) $r['verificationStatus'] = $status;
                return $r;
            }, $app['documentRefs']);
            $this->fs->updateEntity('crmApplications', $id, ['documentRefs' => $refs, 'updated_at' => date('Y-m-d H:i:s')]);
        }
        log_audit('CRM', 'verify_admission_document', $id, "Document {$docId} marked {$status}",
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['documentId' => $docId, 'status' => $status]]);
        // Distinct key — a data 'status' would overwrite json_success()'s
        // status:'success' and make the frontend read the call as failed.
        return $this->json_success(['verificationStatus' => $status]);
    }

    /** POST (multipart) — replace a document's bytes (new version; old object retired). */
    public function replace_admission_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_replace_doc');
        $id    = $this->safe_path_segment(trim((string) $this->input->post('app_id')), 'app_id');
        $docId = trim((string) $this->input->post('document_id'));
        if ($id === '' || $docId === '')             return $this->json_error('Application and document are required.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId)) return $this->json_error('Invalid document id.');
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->json_error('Please choose a replacement file.');
        }
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app))                    return $this->json_error('Application not found.');
        if (!$this->_crm_doc_owned($app, $docId)) return $this->json_error('Document does not belong to this application.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        $res = $this->docsvc->replace($docId, (string) $_FILES['file']['tmp_name'], (string) $_FILES['file']['name'], (string) ($this->admin_id ?? 'system'));
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Replace failed.');
        $newUrl = (string) ($res['document']['downloadUrl'] ?? '');

        // Refresh ALL denormalized pointers (URL changes; verification resets to
        // pending) so every existing reader — CRM, enrollment carry-over — sees
        // the new object.
        $now = date('Y-m-d H:i:s'); $patch = ['updated_at' => $now];
        if (is_array($app['documentRefs'] ?? null)) {
            $patch['documentRefs'] = array_map(function ($r) use ($docId, $newUrl) {
                if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) { $r['url'] = $newUrl; $r['verificationStatus'] = 'pending'; }
                return $r;
            }, $app['documentRefs']);
        }
        if (is_array($app['documents'] ?? null)) {
            $patch['documents'] = array_map(function ($d) use ($docId, $newUrl) {
                if (is_array($d) && (string) ($d['documentId'] ?? '') === $docId) $d['url'] = $newUrl;
                return $d;
            }, $app['documents']);
        }
        if ((string) ($app['photoDocId'] ?? '')   === $docId) $patch['photo_url']   = $newUrl;
        if ((string) ($app['aadhaarDocId'] ?? '') === $docId) $patch['aadhaar_url'] = $newUrl;
        $this->fs->updateEntity('crmApplications', $id, $patch);
        log_audit('CRM', 'replace_admission_document', $id, "Document {$docId} replaced",
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['documentId' => $docId]]);
        return $this->json_success(['url' => $newUrl]);
    }

    /** POST — remove a document from this application (unlink; purge only if orphaned). */
    public function delete_admission_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_delete_doc');
        $id    = $this->safe_path_segment(trim((string) $this->input->post('app_id')), 'app_id');
        $docId = trim((string) $this->input->post('document_id'));
        if ($id === '' || $docId === '')             return $this->json_error('Application and document are required.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId)) return $this->json_error('Invalid document id.');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app))                    return $this->json_error('Application not found.');
        if (!$this->_crm_doc_owned($app, $docId)) return $this->json_error('Document does not belong to this application.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        // Unlink from THIS application. The service purges the Storage object ONLY
        // when no other module still references it (e.g. an enrolled student) AND
        // retention has elapsed — so deleting a lead's document can never break an
        // enrolled student's file (resolves hazard W4).
        $res = $this->docsvc->delete($docId, 'admission', $id, (string) ($this->admin_id ?? 'system'), false);
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Delete failed.');

        // Strip the denormalized pointers from the application.
        $now = date('Y-m-d H:i:s'); $patch = ['updated_at' => $now];
        if (is_array($app['documentRefs'] ?? null)) {
            $patch['documentRefs'] = array_values(array_filter($app['documentRefs'],
                fn($r) => !(is_array($r) && (string) ($r['documentId'] ?? '') === $docId)));
        }
        if (is_array($app['documents'] ?? null)) {
            $patch['documents'] = array_values(array_filter($app['documents'],
                fn($d) => !(is_array($d) && (string) ($d['documentId'] ?? '') === $docId)));
        }
        if ((string) ($app['photoDocId'] ?? '')   === $docId) { $patch['photo_url']   = ''; $patch['photoDocId']   = ''; }
        if ((string) ($app['aadhaarDocId'] ?? '') === $docId) { $patch['aadhaar_url'] = ''; $patch['aadhaarDocId'] = ''; }
        $this->fs->updateEntity('crmApplications', $id, $patch);
        log_audit('CRM', 'delete_admission_document', $id, 'Document ' . $docId . ' deleted (purged=' . (!empty($res['purged']) ? 'yes' : 'no') . ')',
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['documentId' => $docId, 'purged' => !empty($res['purged'])]]);
        return $this->json_success(['purged' => !empty($res['purged'])]);
    }

    public function enroll_student()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_enroll', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found');
        if (($app['status'] ?? '') !== 'approved') return $this->json_error('Only approved applications can be enrolled');

        // Concurrency guard (F1) — TRUE atomic mutex. createIfAbsent() on a
        // per-application lock doc is a real cross-process compare-and-set
        // (Firestore returns 409 if the doc already exists), so two genuinely
        // simultaneous enrolls of the same application can NEVER both proceed —
        // exactly one wins the create; the loser is rejected. This closes the
        // duplicate student + Firebase Auth + double-fee race for good (not just
        // the double-click case). Stale-lock recovery: if an existing lock is
        // older than 120s (a crashed/abandoned enroll), reclaim it. The lock is
        // released on the success path and the common failure paths below, and
        // self-heals after 120s as a safety net. On success the status flips to
        // 'enrolled', which the approved-check above independently blocks.
        $lockId   = $this->fs->docId($id);   // crmEnrollLocks/{schoolId}_{appId}
        $lockData = ['appId' => $id, 'lockedBy' => $this->admin_name, 'lockedAt' => date('c'), 'ts' => time()];
        if (!$this->fs->createIfAbsent('crmEnrollLocks', $lockId, $lockData)) {
            $held   = $this->fs->get('crmEnrollLocks', $lockId);
            $heldTs = is_array($held) ? (int) ($held['ts'] ?? 0) : 0;
            if ($heldTs > 0 && (time() - $heldTs) < 120) {
                return $this->json_error('This application is already being enrolled. Please wait a moment and refresh before retrying.');
            }
            // Stale lock from a crashed enroll — take it over.
            $this->fs->set('crmEnrollLocks', $lockId, $lockData, false);
        }

        $school_id = $this->parent_db_key;
        $school_name = $this->school_name;
        $session = $this->session_year;
        // FIXED: use _nextStudentId() for duplicate-safe ID with retry loop (was inline with no check)
        $studentId = $this->_nextStudentId($school_id);
        if (!$studentId) {
            return $this->json_error('Failed to generate unique student ID. Please try again.');
        }
        $className = Firestore_service::classKey(trim($app['class'] ?? ''));
        if ($className === '') return $this->json_error('Class not specified in application');

        // Section resolution. Public-form applications never carry a
        // section (parents pick a class, the school assigns the section
        // at enroll time). Order:
        //   1. Admin override via POST `section`
        //   2. The application doc's own `section` field (internal flow)
        //   3. First alphabetical section that exists in the `sections`
        //      collection for this class + active session
        //   4. Hard fallback 'A'
        // The previous code went straight to step 4 — every public-form
        // enrollment landed in Section A even when other sections existed.
        $sectionRaw = trim((string) $this->input->post('section'));
        if ($sectionRaw === '') $sectionRaw = trim((string) ($app['section'] ?? ''));
        if ($sectionRaw === '') {
            try {
                $sectionDocs = $this->fs->schoolList('sections', [
                    ['session',   '==', $session],
                    ['className', '==', $className],
                ]);
                $sectionLetters = [];
                foreach ($sectionDocs as $sd) {
                    $sl = trim((string) ($sd['section'] ?? ''));
                    if ($sl !== '') $sectionLetters[$sl] = true;
                }
                if (!empty($sectionLetters)) {
                    $keys = array_keys($sectionLetters);
                    sort($keys);
                    $sectionRaw = (string) $keys[0];
                }
            } catch (\Exception $e) {
                log_message('error', "enroll_student: sections lookup failed for {$className}: " . $e->getMessage());
            }
        }
        if ($sectionRaw === '') $sectionRaw = 'A';
        $section = Firestore_service::sectionKey($sectionRaw);

        // SIS Tier-1 fix B6 (2026-05-31): destination fee-structure pre-flight,
        // mirroring the promotion guard at Sis.php:965-974 (BUG-076 Part 2-A).
        // Without this check, an enrollment whose destination class/section
        // has no feeStructure doc — or has one with empty feeHeads — would
        // proceed: student doc saved, Firebase Auth created, SMS dispatched,
        // CRM marked enrolled. Then assignInitialFees would silently return
        // [] and the student would land Active-with-zero-demands. Block here,
        // before any Firestore write or Auth side-effect, so the operator can
        // set up the fee structure first and retry the enrollment.
        $destFeeStructDocId = "{$school_name}_{$session}_{$className}_{$section}";
        $destFeeStruct      = $this->fs->get('feeStructures', $destFeeStructDocId);
        if (!is_array($destFeeStruct) || empty($destFeeStruct['feeHeads'])) {
            // Release the enroll lock so the operator can retry immediately
            // after creating the fee structure (the documented "set it up and
            // retry" workflow — a wait here would be pure friction).
            $this->fs->remove('crmEnrollLocks', $lockId);
            return $this->json_error(
                "No fee structure exists for {$className} / {$section} in session "
                . "{$session}. Enrollment aborted to prevent the student being "
                . "created without fee demands. Set up the fee structure for this "
                . "class/section in session {$session} first, then retry."
            );
        }

        $combinedPath = "{$className}/{$section}";
        $formattedDOB = !empty($app['dob']) ? date('d-m-Y', strtotime($app['dob'])) : '';
        $now = date('Y-m-d H:i:s');

        // Generate the student's initial password up-front so we can
        // validate it. The previous inline call inside the array literal
        // gave us no chance to fail loudly when DOB was missing or the
        // generator returned a blank string — the enroll then "succeeded"
        // but the parent could never log in. Now it's a single named
        // value with a guard.
        $generatedPassword = $this->_generatePassword($app['student_name'] ?? '', $formattedDOB);
        if (strlen(trim($generatedPassword)) < 4) {
            log_message('error', "enroll_student: password generation produced too-short result for {$studentId} (name='" . ($app['student_name'] ?? '') . "', dob='{$formattedDOB}'). Aborting enrollment.");
            return $this->json_error('Could not generate a valid password (student name or date of birth is missing). Please edit the application first.');
        }

        // Carry the parent-uploaded documents onto the enrolled student. Prefers
        // the canonical documentRefs (Phase 1); falls back to legacy URLs for
        // pre-Document-Service applications. The empty canonical slots are kept
        // (merged under) so the SIS profile always shows them; uploaded files
        // fill them. Links are created AFTER saveStudent (revised D1 — no copy).
        $carry       = $this->_admission_docs_for_student($app);
        $docTemplate = [
            "Aadhar Card"=>["thumbnail"=>"","url"=>""], "Birth Certificate"=>["thumbnail"=>"","url"=>""],
            "Photo"=>["thumbnail"=>"","url"=>""], "Transfer Certificate"=>["thumbnail"=>"","url"=>""],
        ];

        $studentData = [
            "Name"=>$app['student_name']??'', "User Id"=>$studentId, "DOB"=>$formattedDOB,
            "Admission Date"=>date('d-m-Y'), "Class"=>$className, "Section"=>$section,  // Already prefixed via classKey/sectionKey
            "Gender"=>$app['gender']??'', "Blood Group"=>$app['blood_group']??'',
            "Category"=>$app['category']??'', "Religion"=>$app['religion']??'',
            "Nationality"=>$app['nationality']??'',
            "Father Name"=>$app['father_name']??'', "Father Occupation"=>$app['father_occupation']??'',
            "Mother Name"=>$app['mother_name']??'', "Mother Occupation"=>$app['mother_occupation']??'',
            "Guard Contact"=>$app['guardian_phone']??'', "Guard Relation"=>$app['guardian_relation']??'',
            "Phone Number"=>$app['phone']??'', "Email"=>$app['email']??'',
            "Password"=>$generatedPassword,
            "Address"=>["Street"=>$app['address']??'',"City"=>$app['city']??'',"State"=>$app['state']??'',"PostalCode"=>$app['pincode']??''],
            "Pre School"=>$app['previous_school']??'', "Pre Class"=>$app['previous_class']??'', "Pre Marks"=>$app['previous_marks']??'',
            "Profile Pic"=>$carry['profilePic'],
            "Doc"=>array_merge($docTemplate, $carry['doc']),
            "Status"=>"Active",
            // Phase A — first-login force-change. Cleared once the parent
            // successfully changes the password from the parent app.
            "mustChangePassword" => true,
            // Back-link to the originating CRM application so audit /
            // reporting can trace any enrolled student to their admission
            // record without scanning crmApplications by student_id.
            "application_id" => $id,
        ];

        // Firestore-only per no-RTDB policy.
        // SIS Tier-1 fix B1 (2026-05-31): saveStudent must be fail-loud.
        // Pre-fix, a Firestore failure here was logged but the enrollment
        // continued — admin saw success while parent had no profile, the
        // Firebase Auth account was orphaned, no fee demands were
        // generated, and the CRM application was marked enrolled despite
        // no underlying student record. We now abort immediately and
        // surface the error so admin can retry cleanly; downstream Auth
        // creation, fee assignment, sync, and ADMISSION history are all
        // skipped, so no orphan side-effect can be left behind.
        // saveStudent returns bool; an exception is treated as a false
        // return so a single guard covers both failure modes.
        // NEW-1 — SERVER-SIDE ENROLLMENT IDEMPOTENCY. Reuse the SAME
        // production-certified primitive the admission payment path uses
        // (Accounting_idempotency), with the deterministic key ENROLL_{appId}.
        // This makes the whole create-student / Firebase-Auth / assignInitialFees /
        // mark-enrolled sequence EXACTLY-ONCE under concurrent admins, browser/
        // network retry, refresh, or duplicate POST. Acquired HERE — after every
        // abort-able validation (status/class/section/fee-structure/password) and
        // right before the first duplicating side-effect (saveStudent) — so a
        // validation failure can never leave the slot stuck 'processing'.
        $this->load->library('Accounting_idempotency', null, 'enrollIdemp');
        $this->enrollIdemp->init($this->firebase, $this->school_name, $this->session_year);
        $enrollKey = 'ENROLL_' . $id;
        $claim = $this->enrollIdemp->claim($enrollKey, 'crm_enroll');
        if (!empty($claim['dedup'])) {
            // Already enrolled — return the canonical already-processed response.
            // No second student / Auth / fee-demand is created.
            $existingSid = (string) ($claim['entryId'] ?? ($app['student_id'] ?? ''));
            return $this->json_success([
                'student_id'       => $existingSid,
                'already_enrolled' => true,
                'message'          => 'This application is already enrolled' . ($existingSid !== '' ? " as {$existingSid}" : '') . '.',
            ]);
        }
        if (!empty($claim['in_progress'])) {
            // A concurrent request owns the slot — safe no-op (creates nothing).
            return $this->json_error('Enrollment is already in progress for this application. Please wait a moment and refresh.');
        }
        if (isset($claim['error'])) {
            // Fail CLOSED — proceeding without the gate risks a duplicate student.
            log_message('error', "enroll_student: idempotency claim error key={$enrollKey}: " . $claim['error']);
            return $this->json_error('Could not acquire the enrollment lock. Please retry.');
        }
        // first_claim | stale_override ⇒ we own the slot; enroll exactly once.

        $studentSaved = false;
        try {
            $studentSaved = (bool) $this->fs->saveStudent($studentId, $studentData);
        } catch (\Exception $e) {
            log_message('error', "Firestore saveStudent failed for {$studentId}: " . $e->getMessage());
        }
        if (!$studentSaved) {
            // Release the slot so a corrected retry can immediately reclaim it.
            $this->enrollIdemp->markFailed($enrollKey, 'saveStudent failed');
            return $this->json_error('Failed to create student profile in database. Please retry. If the issue persists, contact support.');
        }

        // Revised D1 — reference (do NOT copy) the canonical documents on the
        // enrolled student. One physical object referenced by both the admission
        // application and the student; retention governed centrally by link-count.
        // link() is idempotent, so an enroll-idempotency retry never double-counts.
        if (!empty($carry['links'])) {
            $this->load->library('document_service', null, 'docsvc');
            $this->docsvc->init($this->firebase, $school_id);
            foreach ($carry['links'] as $lk) {
                $this->docsvc->link($lk['documentId'], 'student', $studentId, $lk['role'], (string) ($this->admin_id ?? 'system'));
            }
        }

        $phone = trim($app['phone'] ?? '');
        if ($phone !== '') {
            // Firestore dual-write: phone index
            try {
                $this->fs->set('indexPhones', $this->fs->docId($phone), [
                    'schoolId' => $this->school_id, 'phone' => $phone,
                    'userId' => $studentId, 'type' => 'student',
                ]);
            } catch (\Exception $e) { log_message('error', "Firestore dual-write indexPhones failed: " . $e->getMessage()); }
        }

        // Update Students_Index (matches save_admission pattern)
        $gender = $app['gender'] ?? '';
        $this->_update_student_index($school_name, $studentId, $app['student_name'] ?? '', $className, $section, 'Active', $gender);

        // Initialize Month Fee markers as unpaid (0) for all 12 months
        // SIS Wave-2 fix F5 (2026-05-31): fail-loud guard. Pre-fix, a
        // Firestore failure here was logged but enrollment continued —
        // student doc + CRM marker + Auth + SMS all proceeded against a
        // student with no monthFee. Current ordering already places this
        // BEFORE Firebase Auth at L4835+, so a fail-here abort cleanly
        // avoids orphan-Auth side-effects (Option-3 intent achieved
        // without reorder — current code is already correct).
        $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
        $monthFeeData = array_fill_keys($months, 0);
        $monthFeeOk = false;
        try {
            // Firestore-only per no-RTDB policy.
            $monthFeeOk = (bool) $this->fs->updateEntity('students', $studentId, ['monthFee' => $monthFeeData]);
        } catch (\Exception $e) {
            log_message('error', "Firestore dual-write monthFee failed for {$studentId}: " . $e->getMessage());
        }
        if (!$monthFeeOk) {
            // Release the slot so a corrected retry can immediately reclaim it.
            $this->enrollIdemp->markFailed($enrollKey, 'monthFee init failed');
            return $this->json_error('Failed to initialize fee tracking for student. Please retry. CRM application has NOT been marked enrolled; no Firebase Auth account was created.');
        }

        // M7-B: single CAS-atomic write (enrollment fields + bounded history),
        // concurrency-safe. schoolId/session/searchTokens on the existing doc are
        // preserved by the merge/patch (one write — M3-safe).
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $id, 'history',
            ['action'=>"Enrolled as {$studentId} in {$className} {$section}",'by'=>$this->admin_name,'timestamp'=>$now],
            crm_history_cap(),
            ['status'=>'enrolled','stage'=>'enrolled','student_id'=>$studentId,'enrolled_at'=>$now,'enrolled_by'=>$this->admin_name,'updated_at'=>$now]);
        // M7-A: permanent audit of the CRM enrollment event (canonical auditLogs).
        // Note: the SIS student-record side is separately audited via studentHistory;
        // this records the Admission-CRM lifecycle transition, correlated by appId.
        log_audit('CRM', 'enroll_student', $id, "Enrolled application for " . ($app['student_name'] ?? '') . " as student {$studentId} in {$className} {$section}",
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['student_id' => $studentId, 'class' => $className, 'section' => $section]]);

        // ── Create Firebase Auth user — REQUIRED, not best-effort ─────
        // Previously this was wrapped in try/catch with only a log_message.
        // Result: Firestore student doc was created, but if Auth creation
        // failed silently (network blip, duplicate, missing creds) the
        // parent could never log in and admin had no signal. Now we
        // surface the failure as a clear error response so admin can
        // retry. Enrollment side effects (Firestore docs, fees) stay in
        // place — admin can re-run a "create auth account" repair flow
        // if needed (Phase A2 will add that).
        // SIS Wave-3 D3 (2026-05-31): consolidated via _createFirebaseAuthStudent.
        // Preserved behavior: captures result into $authCreated + $authError
        // for the existing json_success response below (which surfaces the
        // failure state to the operator — but does NOT abort enrollment).
        // The B3 issue (success-when-auth-fails) is intentionally preserved;
        // its fix is Tier-2 territory paired with this consolidation.
        // Note: $password is sourced from $studentData (set at L4905+);
        // the helper's empty-password guard handles the corner case the
        // pre-fix block guarded inline.
        $authResult  = $this->_createFirebaseAuthStudent($studentId, $studentData['Password'] ?? '', $app['student_name'] ?? '', 'SIS enroll');
        $authCreated = (bool) $authResult['success'];
        $authError   = (string) $authResult['error'];

        // Phase A Part 1 — fire SMS with login credentials immediately
        // after a successful Firebase Auth creation. Skipped if Auth
        // creation failed (no point sending credentials that don't work).
        // Fire-and-forget; never blocks the enrollment response.
        $smsSent = false;
        if ($authCreated) {
            try {
                $this->load->helper('notification');
                $smsPhone = trim((string) ($app['phone'] ?? ''));
                $studentNameForSms = (string) ($app['student_name'] ?? $studentId);
                $schoolDisplayName = (string) (
                    $this->school_display_name
                    ?? $this->school_name
                    ?? 'your school'
                );
                if ($smsPhone !== '') {
                    $smsSent = notify_enrollment_credentials(
                        $smsPhone,
                        $schoolDisplayName,
                        $studentNameForSms,
                        $studentId,
                        $generatedPassword
                    );
                    log_message('info', "SIS enroll: credentials SMS dispatch=" . ($smsSent ? 'sent' : 'failed') . " for {$studentId} → {$smsPhone}");
                }
            } catch (\Exception $e) {
                log_message('error', "SIS enroll credentials-SMS failed for {$studentId}: " . $e->getMessage());
            }
        }

        // Post-enrollment steps that run AFTER the student is committed. These
        // were previously log-only, so an operator saw "Enrolled" success while
        // fees silently failed to assign or the app-sync didn't fire. Collect
        // any failures into $warnings and surface them in the response (MED-11)
        // so the operator can act (re-assign fees / re-sync) instead of finding
        // out later. Full transactional/outbox handling of these is a larger
        // follow-up; this at least makes partial failure VISIBLE.
        $warnings = [];

        // Auto-assign class fees for enrolled student
        try {
            $parentDbKey = $school_id;
            $this->feeLifecycle->assignInitialFees($studentId, $className, $section, $parentDbKey);
            log_message('info', "Fee_lifecycle: auto-assigned fees for new enrollment {$studentId}");
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::assignInitialFees failed for {$studentId}: " . $e->getMessage());
            $warnings[] = 'Initial fees could not be assigned automatically — assign them manually from Fees.';
        }

        // Firestore sync for Android apps (entity_sync loaded in constructor)
        // SIS Wave-2 S6 (2026-05-31): observability for sync return.
        if (!$this->entity_sync->syncStudent($studentId, $studentData)) {
            log_message('warning', "syncStudent returned false for {$studentId} (enroll_student)");
            $warnings[] = 'Student record did not sync to the mobile apps — it may take a moment or need a manual re-sync.';
        }
        if (!$this->entity_sync->syncParent($studentId, $studentData)) {
            log_message('warning', "syncParent returned false for {$studentId} (enroll_student)");
            $warnings[] = 'Parent record did not sync to the mobile apps — it may take a moment or need a manual re-sync.';
        }

        // SIS Tier 2 carry (2026-05-30): emit ADMISSION history entry
        // mirroring save_admission@536. Without this, students enrolled via
        // the CRM-approval path had no ADMISSION row in their History array
        // (forensic-documented gap, 7/9 students at SCH_D94FE8F7AD missing).
        $this->_log_history($school_id, $studentId, 'ADMISSION',
            "Student admitted to {$className} / {$section} ({$session})",
            ['class' => $className, 'section' => $section, 'session' => $session]
        );

        // NEW-1 — mark the enrollment slot SUCCESS, storing the studentId so any
        // later retry/duplicate POST dedups to THIS enrollment instead of creating
        // a second student. Reached on the success path even when Firebase Auth
        // creation failed (the student WAS created; Auth is repaired via
        // repair_auth) — so a retry must NOT re-create the student.
        $this->enrollIdemp->markSuccess($enrollKey, $studentId);

        // Response carries the credentials separately from the user-
        // facing message so the JS can show a clean toast AND a
        // copyable credentials panel. Password deliberately NOT in
        // `message` — toasts auto-dismiss and showing secrets in a
        // toast is bad UX + bad security.
        $this->fs->remove('crmEnrollLocks', $lockId);   // release enroll mutex — enrollment complete
        return $this->json_success([
            'student_id'   => $studentId,
            'class'        => $className,
            'section'      => $section,
            'password'     => $generatedPassword,           // plain — for the credentials panel
            'auth_created' => $authCreated,
            'auth_error'   => $authCreated ? '' : $authError,
            'sms_sent'     => $smsSent,
            'warnings'     => $warnings,   // non-fatal post-enroll issues (fees/sync) for the operator
            'message'      => $authCreated
                ? "Enrolled as {$studentId} in {$className} / {$section}." . ($smsSent ? ' Login credentials sent via SMS.' : '')
                : "Enrolled as {$studentId} but parent login is NOT ready — Firebase Auth account creation failed. Error: {$authError}",
        ]);
    }

    /**
     * SIS Tier-2 fix B3 (post-soak 2026-06-01): retry Firebase Auth
     * creation for a student whose original enrollment left an
     * orphan-Auth row (enroll_student returned json_success with
     * auth_created=false because Firebase Auth creation failed but
     * the student profile + fee assignments succeeded).
     *
     * Pre-fix: operator saw the warning modal but had no in-app
     * repair flow — only tech-support escalation could create the
     * missing Auth account. This endpoint re-runs the same
     * _createFirebaseAuthStudent helper used at enrollment, with no
     * password rotation, no operator override, and no auto-notify
     * (operator hand-delivers credentials from the json_success
     * payload).
     *
     * Q-decisions locked 2026-05-31:
     *   Q1 Option 1 — helper call only, no getFirebaseUserByEmail
     *      pre-check (deferred Option 2)
     *   Q2 MANAGE_ROLES gate (parity with change_status)
     *   Q3 NO force-rotate — does NOT set mustChangePassword
     *   Q4 Reuse stored password silently — no operator override
     *   Q5 No auto-notify — silent JSON-only return
     *   Q7 Password exposed in json_success (parity with
     *      enroll_student credentials panel)
     *
     * Idempotent retry semantics inherited from
     * _createFirebaseAuthStudent: on duplicate email Kreait throws,
     * Firebase::createFirebaseUser silently catches and returns null,
     * setFirebaseClaims then idempotently overwrites the 4-key
     * claim set, helper returns success=true honestly.
     *
     * Known carry per Q-pre-4: the AUTH_REPAIR audit entry lands in
     * legacy students.History map (not studentHistory collection)
     * until History Canonicalization ships as Slot 4. Visible in
     * admin History UI; invisible to studentHistory-only readers.
     *
     * POST  /sis/repair_auth  OR  /admission_crm/repair_auth
     *   user_id (required)
     *
     * Returns json_success with credentials-panel shape mirroring
     * enroll_student, OR json_error on any pre-flight failure or
     * helper-reported Auth-create failure.
     */
    public function repair_student_auth()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_repair_auth', 'SIS', 'manage');

        if (strtolower((string) $this->input->method()) !== 'post') {
            return $this->json_error('POST required.');
        }

        $userId = trim((string) $this->input->post('user_id', TRUE));
        if ($userId === '') {
            return $this->json_error('user_id is required.');
        }
        if (!$this->safe_path_segment($userId)) {
            return $this->json_error('Invalid user_id.');
        }

        $student = $this->_getStudent($userId);
        if (!is_array($student) || empty($student)) {
            return $this->json_error('Student not found.');
        }

        $status = (string) ($student['Status'] ?? $student['status'] ?? '');
        if (in_array($status, ['Withdrawn', 'Inactive'], true)) {
            return $this->json_error(
                'Cannot repair Auth for a withdrawn/inactive student.'
            );
        }

        // Q4: reuse stored password silently. Q3: no force-rotate.
        // Fallback chain: students.{id}.Password → _generatePassword
        // (Name, DOB) for legacy rows missing Password (pre-B1 rows).
        // If both empty we abort rather than write an empty-password
        // Auth account (which the helper would reject anyway).
        $password = (string) ($student['Password'] ?? '');
        if ($password === '') {
            $password = $this->_generatePassword(
                (string) ($student['Name'] ?? ''),
                (string) ($student['DOB'] ?? $student['dob'] ?? '')
            );
        }
        if ($password === '') {
            return $this->json_error(
                'No password available to retry: student record has no stored password and Name/DOB are missing.'
            );
        }

        $displayName = (string) ($student['Name'] ?? $userId);
        $result = $this->_createFirebaseAuthStudent(
            $userId,
            $password,
            $displayName,
            'SIS repair'
        );
        if (!($result['success'] ?? false)) {
            return $this->json_error(
                'Failed to repair Firebase Auth account: ' . ($result['error'] ?? 'unknown error')
            );
        }

        $school_id = $this->parent_db_key;
        $this->_log_history(
            $school_id,
            $userId,
            'AUTH_REPAIR',
            'Firebase Auth account repaired by ' . ($this->admin_name ?? 'system'),
            ['context' => 'SIS repair']
        );

        return $this->json_success([
            'user_id'      => $userId,
            'password'     => $password,
            'auth_created' => true,
            'message'      => "Firebase Auth account repaired for {$userId}.",
        ]);
    }

    /**
     * GET — list section letters that exist for a given class in the
     * current session. Used by the enrollment JS to let admin pick which
     * section to enroll a CRM application into instead of silently
     * defaulting to "A".
     */
    public function get_class_sections()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $cls = trim((string) $this->input->get('class'));
        if ($cls === '') return $this->json_success(['sections' => [], 'detail' => [], 'suggested' => '']);
        $className = Firestore_service::classKey($cls);

        $detail = [];
        try {
            $docs = $this->fs->schoolList('sections', [
                ['session',   '==', $this->session_year],
                ['className', '==', $className],
            ]);
            // Live counts in ONE query, tallied per section in PHP — avoids an
            // N+1 count() RPC per section (was one aggregation round-trip each
            // time the enroll dialog opened). Null signals a query failure so
            // the loop falls back to the section doc's stored studentCount.
            $secCounts = [];
            try {
                foreach ($this->fs->schoolList('students', [
                    ['className', '==', $className],
                    ['Status',    '==', 'Active'],
                ]) as $st) {
                    $sec = trim((string) ($st['section'] ?? ''));
                    if ($sec !== '') $secCounts[$sec] = ($secCounts[$sec] ?? 0) + 1;
                }
            } catch (\Exception $e) {
                log_message('error', "get_class_sections student tally failed for {$className}: " . $e->getMessage());
                $secCounts = null;
            }
            foreach ($docs as $d) {
                $s = trim((string) ($d['section'] ?? ''));
                if ($s === '') continue;
                // Strip the "Section " prefix so the picker shows just "A", "B", etc.
                $sNorm = preg_replace('/^Section\s+/i', '', $s);
                if ($sNorm === '') continue;

                // Capacity (admin-set on section creation; default 40 if missing).
                $capacity = (int) ($d['maxStrength'] ?? $d['max_strength'] ?? 40);
                if ($capacity <= 0) $capacity = 40;

                // Live count from `students` collection — more reliable than
                // the cached `studentCount` field on the section doc, which
                // can drift if other flows forget to bump it.
                $sectionStored = $d['section'] ?? $sNorm; // exact stored value (might be "A" or "Section A")
                // Use the pre-computed tally; fall back to the section doc's
                // stored studentCount only if the students query above failed.
                $current = is_array($secCounts)
                    ? (int) ($secCounts[$sectionStored] ?? 0)
                    : (int) ($d['studentCount'] ?? 0);

                $available = max(0, $capacity - $current);
                $detail[$sNorm] = [
                    'section'   => $sNorm,
                    'current'   => $current,
                    'capacity'  => $capacity,
                    'available' => $available,
                    'full'      => $available <= 0,
                ];
            }
        } catch (\Exception $e) {
            log_message('error', "get_class_sections failed for {$className}: " . $e->getMessage());
        }

        ksort($detail);
        $sections = array_keys($detail);

        // Suggestion: the non-full section with the most headroom. Falls
        // back to the first non-full alphabetically if all have the same
        // headroom. Empty when every section is full (admin must create
        // a new one or manually override).
        $suggested = '';
        $bestHead = -1;
        foreach ($detail as $s => $info) {
            if ($info['full']) continue;
            if ($info['available'] > $bestHead) {
                $bestHead = $info['available'];
                $suggested = $s;
            }
        }

        return $this->json_success([
            'class'     => $className,
            'sections'  => $sections,
            'detail'    => array_values($detail),
            'suggested' => $suggested,
        ]);
    }

    public function waitlist()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_crm_classes();
        $this->load->view('include/header');
        $this->load->view('admission_crm/waitlist', $data);
        $this->load->view('include/footer');
    }

    public function fetch_waitlist()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $session = $this->session_year;
        // Session-scoped read (F5): waitlist screen shows only the active session.
        $waitlist = $this->_crm_list('crmWaitlist', [['session', '==', $session]]);
        if (!is_array($waitlist)) $waitlist = [];
        $result = [];
        foreach ($waitlist as $id => $w) {
            if (!is_array($w) || ($w['session']??'') !== $session) continue;
            $w['id'] = $id;
            $result[] = $w;
        }
        usort($result, function($a,$b) {
            $p = ($a['priority']??999) - ($b['priority']??999);
            return $p !== 0 ? $p : strcmp($a['created_at']??'',$b['created_at']??'');
        });
        return $this->json_success(['waitlist' => $result]);
    }

    public function add_to_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_add', 'SIS', 'manage');
        $app_id = trim($this->input->post('application_id') ?? '');
        $reason = trim($this->input->post('reason') ?? '');
        $priority = (int)($this->input->post('priority') ?? 99);
        if (!$app_id) return $this->json_error('Application ID required');
        $app_id = $this->safe_path_segment($app_id, 'application_id');
        $app = $this->_crm_get('crmApplications', $app_id);
        if (!is_array($app)) return $this->json_error('Application not found');
        $now = date('Y-m-d H:i:s');
        $wl_id = $this->_crm_next_id('Waitlist', 'WL', 5);
        $waitEntry = ['waitlist_id'=>$wl_id,'application_id'=>$app_id,'student_name'=>$app['student_name']??'','parent_name'=>$app['parent_name']??'',
            'phone'=>$app['phone']??'','class'=>$app['class']??'','session'=>$app['session']??$this->session_year,
            'priority'=>$priority,'reason'=>$reason,'status'=>'waiting','created_at'=>$now,'updated_at'=>$now];
        $this->_crm_set("crmWaitlist", $wl_id, $waitEntry);
        // Counter managed by _crm_next_id — no separate write needed.
        // M7-B: single CAS-atomic write (waitlist status + bounded history), concurrency-safe.
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $app_id, 'history',
            ['action'=>'Added to waitlist','by'=>$this->admin_name,'timestamp'=>$now],
            crm_history_cap(),
            ['status'=>'waitlisted','stage'=>'waitlisted','updated_at'=>$now]);
        // (crmWaitlist entry written above via _crm_set — a separate document.)
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'waitlist_add', $app_id, "Added " . ($app['student_name'] ?? '') . " to waitlist (WL {$wl_id})",
            ['appId' => $app_id, 'entityType' => 'application', 'meta' => ['waitlist_id' => $wl_id, 'priority' => $priority]]);
        return $this->json_success(['id' => $wl_id]);
    }

    public function remove_from_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_remove', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Waitlist ID required');
        $id = $this->safe_path_segment($id, 'id');
        $entry = $this->_crm_get('crmWaitlist', $id);
        if (is_array($entry) && !empty($entry['application_id'])) {
            $this->_crm_update('crmApplications', $entry['application_id'], ['status'=>'pending','stage'=>'document_collection','updated_at'=>date('Y-m-d H:i:s')]);
            // M3: _crm_update is the single canonical Firestore write (removed redundant dual-write).
            // M7-A: permanent audit (canonical auditLogs).
            log_audit('CRM', 'waitlist_remove', (string) $entry['application_id'], "Removed application from waitlist (WL {$id})",
                ['appId' => (string) $entry['application_id'], 'entityType' => 'application', 'meta' => ['waitlist_id' => $id]]);
        }
        $this->_crm_delete('crmWaitlist', $id);
        // M3: _crm_delete is the single canonical Firestore delete (removed redundant dual-write).
        return $this->json_success();
    }

    public function promote_from_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_promote', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Waitlist ID required');
        $id = $this->safe_path_segment($id, 'id');
        $entry = $this->_crm_get('crmWaitlist', $id);
        if (!is_array($entry)) return $this->json_error('Waitlist entry not found');
        $app_id = $entry['application_id'] ?? '';
        if (!$app_id) return $this->json_error('No linked application');
        $now = date('Y-m-d H:i:s');
        $app = $this->_crm_get('crmApplications', $app_id);
        if (is_array($app)) {
            // M7-B: single CAS-atomic write (approval fields + bounded history), concurrency-safe.
            $this->load->helper('admission_crm');
            $this->fs->casAppendBounded('crmApplications', $app_id, 'history',
                ['action'=>'Promoted from waitlist and approved','by'=>$this->admin_name,'timestamp'=>$now],
                crm_history_cap(),
                ['status'=>'approved','stage'=>'approved','approved_by'=>$this->admin_name,'approved_at'=>$now,'updated_at'=>$now]);
            // M7-A: permanent audit (canonical auditLogs).
            log_audit('CRM', 'waitlist_promote', (string) $app_id, "Promoted " . ($app['student_name'] ?? '') . " from waitlist to approved (WL {$id})",
                ['appId' => (string) $app_id, 'entityType' => 'application', 'meta' => ['waitlist_id' => $id, 'status' => 'approved']]);
        }
        $this->_crm_delete('crmWaitlist', $id);
        // M3: _crm_delete is the single canonical Firestore delete (removed redundant dual-write).
        return $this->json_success();
    }

    public function crm_settings()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $settings = $this->_crm_get_settings();
        if (!is_array($settings)) $settings = [];
        // school_id (SCH_…) powers the Public Admission Form shareable link now
        // surfaced in the Settings hub (IA reorg 2026-07).
        $data = ['settings'=>$settings,'session_year'=>$this->session_year,'classes'=>$this->_get_crm_classes(),'school_id'=>$this->school_id];
        $this->load->view('include/header');
        $this->load->view('admission_crm/settings', $data);
        $this->load->view('include/footer');
    }

    public function save_crm_settings()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_settings', 'SIS', 'manage');
        $settings = $this->_crm_get_settings();
        if (!is_array($settings)) $settings = [];
        foreach (['stages','class_limits','form_fields','notifications'] as $key) {
            $val = $this->input->post($key);
            if (!$val) continue;
            $decoded = json_decode($val, true);
            if (!is_array($decoded)) continue;
            // Validation (F13): the payload was previously accepted with only an
            // is_array check, allowing unbounded / malformed settings to be
            // persisted. Cap collection size and sanitise stage keys/labels so
            // a bad payload can't bloat the doc or inject odd stage keys the
            // pipeline then can't render.
            if (count($decoded) > 100) {
                return $this->json_error("Too many entries for '{$key}' (max 100).");
            }
            if ($key === 'stages') {
                $clean = [];
                foreach ($decoded as $sk => $label) {
                    $sk = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $sk));
                    if ($sk === '') continue;                       // drop unusable keys
                    $clean[$sk] = mb_substr(trim((string) $label), 0, 60); // cap label length
                }
                if (empty($clean)) {
                    return $this->json_error('At least one valid stage is required.');
                }
                $decoded = $clean;
            }
            $settings[$key] = $decoded;
        }
        $settings['updated_at'] = date('Y-m-d H:i:s');
        $this->_crm_save_settings($settings);
        // M3: _crm_save_settings (-> _crm_set) is the single canonical Firestore write (removed redundant dual-write).
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'update_crm_settings', 'config', 'Updated CRM settings (stages / class limits / form fields / notifications)',
            ['entityType' => 'crm_settings']);
        return $this->json_success();
    }

    public function get_crm_settings()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $settings = $this->_crm_get_settings();
        if (!is_array($settings)) $settings = [];
        if (empty($settings['stages'])) $settings['stages'] = $this->_default_stages();
        return $this->json_success(['settings' => $settings]);
    }

    public function online_form()
    {
        // MED-6: this is behind admin auth (the real public form is
        // Admission_public::form) but had no role gate — any tenant user could
        // reach it. Require CRM view access.
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $school_name = $this->school_name;
        $settings = $this->_crm_get_settings();
        $classes  = $this->_get_crm_classes();
        $profileDoc = $this->fs->get('schools', $this->school_id);
        $profile = is_array($profileDoc) ? $profileDoc : [];
        $data = ['school_name'=>$school_name,'session_year'=>$this->session_year,'settings'=>is_array($settings)?$settings:[],'classes'=>$classes,'profile'=>is_array($profile)?$profile:[]];
        $this->load->view('admission_crm/online_form', $data);
    }

    public function submit_online_form()
    {
        // MED-6: gate this authenticated-but-unauthorized writer (it creates
        // crmInquiries + crmApplications and burns counter IDs). Requires CRM
        // manage access, matching save_application.
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_app', 'SIS', 'edit');
        // ── Rate limiting — max 10 submissions per IP per 15 minutes ──
        // Firestore-based: one doc per IP, stores recent submission timestamps.
        $clientIp = $this->input->ip_address();
        $ipKey    = preg_replace('/[^a-zA-Z0-9]/', '_', $clientIp);
        $rlDocId  = "online_form_{$ipKey}";
        $windowStart = time() - 900;
        try {
            $rlDoc = $this->fs->get('rateLimits', $rlDocId);
            $timestamps = is_array($rlDoc['timestamps'] ?? null) ? $rlDoc['timestamps'] : [];
            $recent = array_filter($timestamps, fn($ts) => (int) $ts >= $windowStart);
            if (count($recent) >= 10) {
                return $this->json_error('Too many submissions. Please try again later.', 429);
            }
            $recent[] = time();
            $this->fs->set('rateLimits', $rlDocId, ['timestamps' => array_values($recent), 'ip' => $clientIp, 'updatedAt' => date('c')], true);
        } catch (\Exception $e) {
            log_message('error', 'Rate limit check failed: ' . $e->getMessage());
            // Fail-open: allow submission if rate-limit check fails
        }

        $now = date('Y-m-d H:i:s');

        // H-05 FIX: Server-side input length limits per field
        $fieldLimits = [
            'student_name'=>100, 'parent_name'=>100, 'father_name'=>100, 'mother_name'=>100,
            'phone'=>20, 'email'=>150, 'class'=>50, 'dob'=>15, 'gender'=>15,
            'address'=>300, 'city'=>100, 'state'=>100, 'pincode'=>10,
            'previous_school'=>150, 'previous_class'=>50, 'blood_group'=>10,
            'category'=>50, 'religion'=>50, 'nationality'=>50,
            'father_occupation'=>100, 'mother_occupation'=>100, 'notes'=>500,
        ];
        $data = [];
        foreach ($fieldLimits as $f => $maxLen) {
            $val = trim($this->input->post($f) ?? '');
            if (mb_strlen($val) > $maxLen) {
                return $this->json_error("Field '{$f}' exceeds maximum length of {$maxLen} characters.");
            }
            $data[$f] = $val;
        }
        if ($data['student_name']===''||$data['phone']===''||$data['class']==='') return $this->json_error('Student name, phone, and class are required');
        if (!preg_match('/^\+?\d{10,15}$/', preg_replace('/[\s\-]/','',$data['phone']))) return $this->json_error('Invalid phone number format');
        if ($data['email']!==''&&!filter_var($data['email'],FILTER_VALIDATE_EMAIL)) return $this->json_error('Invalid email address');

        // SCALE (closure item I / Finding 1): duplicate-phone check via a BOUNDED
        // equality query (schoolId+session+phone — zig-zag, no composite index)
        // instead of a full-collection scan. Same exact-match semantics as before,
        // but reads only the 0–few docs sharing this phone rather than every
        // application in the school.
        $dupCandidates = $this->fs->schoolWhere('crmApplications', [
            ['session', '==', $this->session_year],
            ['phone',   '==', $data['phone']],
        ]);
        if (is_array($dupCandidates)) {
            foreach ($dupCandidates as $row) {
                $ea = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
                if (in_array($ea['status'] ?? '', ['pending','approved','waitlisted','enrolled'], true)) {
                    return $this->json_error('An application with this phone number already exists for this session (ID: '.($ea['application_id']??'N/A').')');
                }
            }
        }

        $inq_id = $this->_crm_next_id('Inquiry', 'INQ', 5);
        $inqData = ['inquiry_id'=>$inq_id,'student_name'=>$data['student_name'],'parent_name'=>$data['parent_name'],'phone'=>$data['phone'],'email'=>$data['email'],'class'=>$data['class'],'source'=>'Online Form','status'=>'converted','session'=>$this->session_year,'created_at'=>$now,'updated_at'=>$now,'created_by'=>'Online'];
        $this->_crm_set('crmInquiries', $inq_id, $inqData);
        $app_id = $this->_crm_next_id('Application', 'APP', 5);
        $appData = array_merge($data, ['application_id'=>$app_id,'inquiry_id'=>$inq_id,'session'=>$this->session_year,'status'=>'pending','stage'=>'document_collection','created_at'=>$now,'updated_at'=>$now,'created_by'=>'Online','documents'=>[],'history'=>[['action'=>'Application submitted via online form','by'=>'Online','timestamp'=>$now]]]);
        $this->load->helper('admission_search');
        $appData['searchTokens'] = admission_search_tokens($appData['student_name'] ?? '');
        $this->_crm_set("crmApplications", $app_id, $appData);
        $this->_crm_update("crmInquiries", $inq_id, ['application_id'=>$app_id]);
        // Counter managed by _crm_next_id — no separate write needed.
        // M3: _crm_set (inquiry + application) and _crm_update (inquiry application_id)
        // above are the single canonical Firestore writes. The removed dual-write
        // re-set the same crmApplications doc and re-set crmInquiries with the same
        // inqData+application_id already produced by _crm_set + _crm_update.
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'create_application', $app_id, "Application submitted via online form for " . ($appData['student_name'] ?? ''),
            ['appId' => $app_id, 'entityType' => 'application', 'meta' => ['source' => 'online_form', 'inquiry_id' => $inq_id]]);
        return $this->json_success(['application_id' => $app_id]);
    }

    public function fetch_analytics()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        // Scope to the current session at the query layer (was an all-time read
        // of every inquiry/application/waitlist doc, then filtered in PHP).
        $session = $this->session_year;
        $inquiries    = $this->_crm_list('crmInquiries',    [['session', '==', $session]]);
        $applications = $this->_crm_list('crmApplications', [['session', '==', $session]]);
        $waitlist     = $this->_crm_list('crmWaitlist',     [['session', '==', $session]]);
        if (!is_array($inquiries)) $inquiries=[]; if (!is_array($applications)) $applications=[]; if (!is_array($waitlist)) $waitlist=[];
        $sInq = array_filter($inquiries, fn($i) => is_array($i)&&($i['session']??'')===$session);
        $sApp = array_filter($applications, fn($a) => is_array($a)&&($a['session']??'')===$session);
        $sWl  = array_filter($waitlist, fn($w) => is_array($w)&&($w['session']??'')===$session);
        $funnel = ['inquiries'=>count($sInq),'applications'=>count($sApp),'approved'=>count(array_filter($sApp,fn($a)=>($a['status']??'')==='approved')),'enrolled'=>count(array_filter($sApp,fn($a)=>($a['status']??'')==='enrolled')),'rejected'=>count(array_filter($sApp,fn($a)=>($a['status']??'')==='rejected')),'waitlisted'=>count($sWl)];
        $sources = []; foreach ($sInq as $i) { $s=$i['source']??'Walk-in'; $sources[$s]=($sources[$s]??0)+1; }
        $classes = []; foreach ($sApp as $a) { $c=$a['class']??'Unknown'; $st=$a['status']??'pending'; if (!isset($classes[$c])) $classes[$c]=['total'=>0,'approved'=>0,'enrolled'=>0,'pending'=>0,'rejected'=>0]; $classes[$c]['total']++; if (isset($classes[$c][$st])) $classes[$c][$st]++; }
        $monthly = []; foreach ($sInq as $i) { $m=substr($i['created_at']??'',0,7); if ($m) $monthly[$m]=($monthly[$m]??0)+1; } ksort($monthly);
        return $this->json_success(['funnel'=>$funnel,'sources'=>$sources,'classes'=>$classes,'monthly'=>$monthly]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       ADDITIONAL PRIVATE HELPERS
       Merged from Student.php and Admission_crm.php
    ══════════════════════════════════════════════════════════════════════ */

    private function _generatePdfThumbnail($pdfTmpPath, $storagePath, $label, $timestamp, $random)
    {
        if (extension_loaded('imagick')) {
            try {
                $imagick = new Imagick();
                $imagick->setResolution(150, 150);
                $imagick->readImage($pdfTmpPath . '[0]');
                $imagick->setImageFormat('jpg');
                $imagick->setImageCompressionQuality(85);
                $imagick->thumbnailImage(400, 0);
                $imagick->flattenImages();
                $tmp = sys_get_temp_dir() . "/thumb_{$label}_{$timestamp}_{$random}.jpg";
                $imagick->writeImage($tmp);
                $imagick->clear();
                $imagick->destroy();
                $thumbPath = $storagePath . "thumbnail/{$label}_{$timestamp}_{$random}.jpg";
                if ($this->firebase->uploadFile($tmp, $thumbPath) === true) {
                    unlink($tmp);
                    return $this->firebase->getDownloadUrl($thumbPath);
                }
            } catch (Exception $e) {
                log_message('error', $e->getMessage());
            }
        }
        $placeholder = FCPATH . 'tools/image/pdf.png';
        if (file_exists($placeholder)) {
            $thumbPath = $storagePath . "thumbnail/{$label}_{$timestamp}_{$random}.png";
            if ($this->firebase->uploadFile($placeholder, $thumbPath) === true) {
                return $this->firebase->getDownloadUrl($thumbPath);
            }
        }
        return '';
    }

    private function _deleteOldStorageFile($docNode)
    {
        if (!is_array($docNode)) $docNode = ['url' => (string)$docNode, 'thumbnail' => ''];
        foreach (['url', 'thumbnail'] as $key) {
            $url = $docNode[$key] ?? '';
            if (!empty($url)) {
                $path = $this->_extractStoragePathFromUrl($url);
                if ($path) $this->CM->delete_file_from_firebase($path);
            }
        }
    }

    private function _extractStoragePathFromUrl($url)
    {
        if (empty($url)) return '';
        if (preg_match('#/o/([^?]+)#', $url, $matches)) return urldecode($matches[1]);
        return '';
    }

    private function _getFees($className, $section)
    {
        // Read fee structure from Firestore (docId includes session).
        $feeDocId = $this->fs->sectionDocId($className, $section);
        $feeDoc = $this->fs->get('feeStructures', $feeDocId);
        if (!is_array($feeDoc) || empty($feeDoc)) {
            return json_encode(["fees"=>[],"monthlyTotals"=>[]]);
        }

        $formattedFees = [];

        // Canonical 2026+ schema: a flat `feeHeads` array, each item
        // carrying { name, amount, frequency: 'monthly'|'annual' }.
        // Project monthly heads across the 12 academic-year months
        // (Apr–Mar) and bucket annual heads under the "Yearly Fees"
        // key, so the existing student-profile view — which expects
        // a { 'Yearly Fees' | <MonthName> => { title => amount } }
        // map — renders correctly without any view changes.
        if (!empty($feeDoc['feeHeads']) && is_array($feeDoc['feeHeads'])) {
            $months = ['April','May','June','July','August','September',
                       'October','November','December','January','February','March'];
            foreach ($feeDoc['feeHeads'] as $head) {
                if (!is_array($head)) continue;
                $name = trim((string)($head['name'] ?? ''));
                if ($name === '') continue;
                $amt  = (float)($head['amount'] ?? 0);
                $freq = strtolower((string)($head['frequency'] ?? 'monthly'));
                if ($freq === 'annual' || $freq === 'yearly') {
                    $formattedFees['Yearly Fees'][$name] = $amt;
                } else {
                    foreach ($months as $m) {
                        $formattedFees[$m][$name] = $amt;
                    }
                }
            }
        } else {
            // Pre-2026 legacy shape: heads.<Month>.<Title> = amount, or
            // the same map at the doc root. Retained so a school that
            // hasn't yet been migrated to the feeHeads schema still
            // renders.
            $feesData = $feeDoc['heads'] ?? $feeDoc;
            if (is_array($feesData)) {
                foreach ($feesData as $month => $fees) {
                    if (is_array($fees)) {
                        $formattedFees[$month] = $fees;
                    }
                }
            }
        }

        $monthlyTotals = [];
        foreach ($formattedFees as $month => $row) {
            $monthlyTotals[$month] = array_sum(array_map('floatval', $row));
        }
        return json_encode([
            "fees"          => $formattedFees,
            "monthlyTotals" => $monthlyTotals,
            "overallTotal"  => array_sum($monthlyTotals),
        ]);
    }

    private function _getSundays($year, $month)
    {
        $sundays = [];
        $date = new DateTime("$year-$month-01");
        while ($date->format('n') == $month) {
            if ($date->format('w') == 0) $sundays[] = (int)$date->format('j');
            $date->modify('+1 day');
        }
        return $sundays;
    }

    private function _default_stages()
    {
        return [
            'document_collection' => 'Document Collection',
            'under_review'        => 'Under Review',
            'interview'           => 'Interview / Test',
            'approved'            => 'Approved',
            'rejected'            => 'Rejected',
            'waitlisted'          => 'Waitlisted',
        ];
    }

    private function _get_crm_classes()
    {
        // Deduplicate by className. The CRM application form decides
        // section at enrollment time (via the section picker), not at
        // submission, so the dropdown should list each class exactly
        // once. Earlier this returned one entry per (class, section)
        // pair which made the Edit modal show "Class 8th / Section A"
        // for an unenrolled application.
        $sectionDocs = $this->fs->schoolWhere('sections', []);
        $seen = [];
        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'];
            $className = $sd['className'] ?? '';
            if ($className && !isset($seen[$className])) {
                $seen[$className] = true;
            }
        }
        $names = array_keys($seen);
        // Natural sort: "Class 1st", "Class 2nd", ... "Class 10th".
        usort($names, 'strnatcmp');

        $classes = [];
        foreach ($names as $cn) {
            $classes[] = [
                'class_name' => $cn,
                'section'    => '',     // resolved later, at enroll time
                'label'      => $cn,    // dropdown text — just the class
            ];
        }
        return $classes;
    }

    /**
     * BUG-076 Part 2-A shared guard (extracted 2026-08-12).
     *
     * Returns '' when a usable fee structure exists for the given
     * class/section/session, otherwise the operator-facing error message.
     *
     * A student must never land Active-with-zero-demands because the fee
     * structure for their class/section/session was never configured — that
     * is the STU0004-11 incident. assignInitialFees() reads
     * feeStructures/{schoolId}_{session}_{class}_{section} and silently
     * returns [] when the doc is missing or carries no feeHeads, so the only
     * safe place to detect it is BEFORE any student/counter/Auth write.
     *
     * This body previously existed as three separate copies — in
     * execute_promotion() and enroll_student() (both correct) and NOT AT ALL
     * in save_admission(), which is how the walk-in path reproduced the
     * incident the other two paths were hardened against. One implementation,
     * three callers. Do not re-inline it.
     *
     * $classKey/$sectionKey MUST already be normalized through
     * Firestore_service::classKey()/sectionKey() — the docId has to match the
     * exact key assignInitialFees will read. ($school_name == school_id ==
     * SCH_xxxxxx.)
     *
     * @param string $verb Operation name for the message ('Admission',
     *                     'Promotion', 'Enrollment').
     */
    private function _fee_structure_missing(
        string $classKey,
        string $sectionKey,
        string $session,
        string $verb
    ): string {
        $docId  = "{$this->school_name}_{$session}_{$classKey}_{$sectionKey}";
        $struct = $this->fs->get('feeStructures', $docId);
        if (is_array($struct) && !empty($struct['feeHeads'])) {
            return '';
        }

        return "No fee structure exists for {$classKey} / {$sectionKey} in session "
            . "{$session}. {$verb} aborted to prevent the student being enrolled "
            . "without fee demands. Set up the fee structure for this class/section "
            . "in session {$session} first, then retry.";
    }

    /**
     * G10 (2026-08-08) — admission-fee PRE-CHECK for the direct admission
     * screen (pathway 3).
     *
     * Background: the ERP has three admission pathways — (1) the public online
     * form, (2) the CRM application converted at the counter
     * (Sis::collect_admission_fee), and (3) THIS screen, direct entry with no
     * lead. Pathways 1 and 2 both charge the admission fee through
     * admission_resolve_fee() + Admission_collection::record_payment(); pathway
     * 3 had no payment logic whatsoever, so every student admitted directly was
     * silently never charged. Operator decision (recorded in
     * STUDENT_ENTITLEMENTS_ARCHITECTURE_BASELINE.md): charge it by default,
     * through the SAME shared path — no second collection engine — plus a
     * deliberate, separately-permissioned, audited waive override.
     *
     * Runs BEFORE the student doc is created so a mis-configured or blocked
     * collection fails the request cleanly instead of leaving an admitted
     * student with an uncollected fee. Every rejection path calls json_error(),
     * which exits.
     *
     * Waive authority is the graded `Fees:manage` capability — deliberately NOT
     * a side effect of the `SIS:manage` grant this screen already requires, so
     * an admissions clerk who can admit cannot also waive. (Reference: the
     * default role catalogue in Staff.php gives ROLE_FRONT_OFFICE `SIS` without
     * `Fees`.) Granting waive authority to a role that holds SIS but not Fees —
     * Principal, in the default catalogue — is a catalogue/per-person `extra`
     * decision, not a code change.
     *
     * @return array{due:bool,waived:bool,amount:float,fee_head:string,fee_label:string,
     *               mode:string,mode_label:string,bank_code:string,bank_reference:string,
     *               waive_reason:string}
     */
    private function _admission_fee_precheck(string $classOrd): array
    {
        $plan = [
            'due' => false, 'waived' => false, 'amount' => 0.0,
            'fee_head' => 'Admission Fee', 'fee_label' => 'Admission Fee',
            'mode' => '', 'mode_label' => '', 'bank_code' => '', 'bank_reference' => '',
            'waive_reason' => '',
        ];

        $this->load->helper('admission_crm');
        $schoolDoc = $this->firebase->firestoreGet('schools', $this->school_name) ?? [];
        $feeInfo   = admission_resolve_fee(
            $this->fs, is_array($schoolDoc) ? $schoolDoc : [], $this->session_year, $classOrd
        );
        $amount = round((float) ($feeInfo['amount'] ?? 0), 2);
        if (empty($feeInfo['enabled']) || $amount <= 0) {
            return $plan;   // school hasn't configured an admission fee — nothing to charge
        }

        $plan['due']       = true;
        $plan['amount']    = $amount;
        $plan['fee_head']  = (string) ($feeInfo['fee_head'] ?? 'Admission Fee');
        $plan['fee_label'] = (string) ($feeInfo['label']    ?? $plan['fee_head']);

        // ── Waive override — separate graded capability + mandatory reason ──
        if ((string) $this->input->post('waive_admission_fee') === '1') {
            if (!has_permission('Fees', 'manage')) {
                log_message('error',
                    'G10 waive DENIED: admin=[' . ($this->admin_id ?? '') . '] role=['
                    . ($this->admin_role ?? '') . '] school=[' . $this->school_name
                    . '] lacks Fees:manage');
                $this->json_error('You do not have permission to waive the admission fee.', 403);
            }
            $reason = trim((string) ($this->input->post('waive_reason') ?? ''));
            if ($reason === '') {
                $this->json_error('A reason is required to waive the admission fee.');
            }
            $plan['waived']       = true;
            $plan['waive_reason'] = $reason;
            return $plan;
        }

        // ── Collection inputs — mirror collect_admission_fee's validation ──
        $mode = trim((string) $this->input->post('admission_payment_mode'));
        if ($mode === '') {
            $this->json_error('Select how the admission fee of ' . number_format($amount, 2)
                . ' was collected, or waive it.');
        }
        $modeMap = [
            'cash' => 'cash', 'upi' => 'upi', 'cheque' => 'cheque', 'neft' => 'neft', 'rtgs' => 'rtgs',
            'dd' => 'bank_transfer', 'demand draft' => 'bank_transfer', 'bank transfer' => 'bank_transfer',
            'pos' => 'bank_transfer', 'pos/card' => 'bank_transfer', 'card' => 'bank_transfer',
        ];
        $acctMode = $modeMap[strtolower($mode)] ?? null;
        if ($acctMode === null) {
            $this->json_error('Invalid payment mode: ' . $mode);
        }
        $bankCode = trim((string) $this->input->post('admission_bank_ledger_code'));
        if ($acctMode === 'cash') {
            $bankCode = '';
        } else {
            if ($bankCode === '') {
                $this->json_error('Select a bank ledger for a non-cash admission-fee collection.');
            }
            $okBank = false;
            foreach ((array) $this->firebase->firestoreQuery('chartOfAccounts', [['schoolId', '==', $this->school_name]]) as $row) {
                $cd = $row['data'] ?? $row;
                if ((string) ($cd['code'] ?? '') !== $bankCode) continue;
                if (!empty($cd['is_bank']) && empty($cd['is_group']) && ($cd['status'] ?? '') === 'active') $okBank = true;
                break;
            }
            if (!$okBank) {
                $this->json_error('Invalid or inactive bank ledger selected.');
            }
        }

        // ── Period lock — a collection must never post into a closed period ──
        $this->load->library('Accounting_cache', null, 'acctCacheAdm');
        $this->load->library('Accounting_period_lock', null, 'acctLockAdm');
        $this->acctCacheAdm->init($this->school_name, $this->session_year);
        $this->acctLockAdm->init($this->firebase, $this->acctCacheAdm, $this->school_name, $this->session_year);
        $lock = $this->acctLockAdm->forceValidate(date('Y-m-d'));
        if (!empty($lock['locked'])) {
            $this->json_error('The accounting period is closed up to ' . ($lock['lockedUntil'] ?? '')
                . '. Reopen it before admitting a student with an admission fee.');
        }

        $plan['mode']           = $acctMode;
        $plan['mode_label']     = $mode;
        $plan['bank_code']      = $bankCode;
        $plan['bank_reference'] = trim((string) ($this->input->post('admission_bank_reference') ?? ''));
        return $plan;
    }

    /**
     * ── PHASE 20.3c (P20.2-RACE-1) — ATOMIC SEAT-GUARDED STUDENT WRITE ──────
     *
     * The single entry point every seat-consuming path in this controller uses
     * to write a student into a section. Replaces a bare
     * `entity_sync->syncStudent()` at each placement site.
     *
     * WHY: the capacity check and the student write were separate operations
     * with nothing between them, so two requests could both pass a 39/40 check
     * and both write — 41/40. This routes the write through
     * Section_seat_guard, which commits it atomically with a CAS marker on the
     * destination section. A concurrent writer that already moved that section
     * causes the whole batch to roll back — the student is NOT written — and
     * the placement is refused.
     *
     * IDENTITY SAFETY: this mints nothing. `$studentId` is fixed by the caller
     * before this is reached, the student document id is derived from it, and
     * a refusal writes nothing at all — so no SRNO, Student ID or Admission No
     * can be issued twice by a refused placement. In save_admission() the SRNO
     * / APAAR claim (_claimIdentityAttributes) runs AFTER this call, so a
     * refusal never reaches it.
     *
     * NOT used for non-placement student writes (profile edit, TC, withdrawal,
     * status change) — those consume no seat and keep calling syncStudent()
     * directly.
     *
     * @return array{status:string, message:string, attempts:int}
     */
    private function _placeStudentGuarded(
        string $studentId,
        array $studentData,
        string $classNode,
        string $sectionNode,
        string $session,
        string $context = '',
        string $decisionVersion = ''
    ): array {
        if (!isset($this->seatGuard)) {
            $this->load->library('section_seat_guard', null, 'seatGuard');
            $this->seatGuard->init(
                $this->fs,
                $this->firebase,
                (string) $this->school_id,
                (string) ($this->admin_id ?? 'system')
            );
        }

        $res = $this->seatGuard->placeStudent(
            $this->entity_sync, $studentId, $studentData, $classNode, $sectionNode, $session,
            $decisionVersion
        );

        if (($res['status'] ?? '') !== Section_seat_guard::PLACED) {
            log_message('error',
                "ACC_PLACEMENT_REFUSED context={$context} schoolId={$this->school_id} "
                . "student={$studentId} section={$classNode}/{$sectionNode} session={$session} "
                . "status=" . ($res['status'] ?? '?'));
        }
        return $res;
    }

    /**
     * P14-1 (Phase 14.1) — register a student against a phone WITHOUT evicting
     * the siblings who share it.
     *
     * ── THE DEFECT THIS CLOSES ──────────────────────────────────────────────
     * `indexPhones/{schoolId}_{phone}` held ONE `userId`, written with
     * `set(..., merge = false)`. A family shares a phone, so importing Rahul
     * then Priya left the document pointing only at Priya. Re-importing the
     * same file then resolved Rahul's row to PRIYA's id, the Phase 13 name
     * corroboration correctly said "different person", and a SECOND Rahul was
     * created. The Phase 13 fix corrected the dedup RULE; this corrects the
     * INDEX SHAPE, which is the actual root cause and exactly what
     * ZENX_ADMISSION_DOMAIN_SPEC.md already prescribed ("append, not
     * overwrite").
     *
     * ── SHAPE, AND WHY IT IS BACKWARD COMPATIBLE ────────────────────────────
     *   { phone, schoolId, type, userId: <first/primary>, userIds: [ … ] }
     *
     * `userId` is RETAINED as the first-registered student so every existing
     * reader (Staff.php's pre-owner checks, legacy lookups) keeps working
     * unchanged. `userIds[]` is the new authoritative set. A legacy document
     * with only `userId` reads as a single-element set — **no backfill and no
     * migration are required**.
     *
     * This is NOT a sibling graph: it records "these students share a contact
     * number", nothing more. No relationship, no guardian entity, no ordering.
     *
     * Cost: 1 read + 1 write per phone registration, replacing the previous
     * blind 1 write. The read is required to append without evicting; there is
     * no atomic array-union primitive exposed on this path. Idempotent —
     * re-registering the same student is a no-op set membership add.
     */
    private function _registerPhoneIndex(string $phone, string $studentId, string $type = 'student'): void
    {
        if ($phone === '' || $studentId === '') return;
        try {
            $docId    = $this->fs->docId($phone);
            $existing = $this->fs->get('indexPhones', $docId);

            $ids = [];
            if (is_array($existing)) {
                if (is_array($existing['userIds'] ?? null)) {
                    $ids = array_values(array_filter(array_map('strval', $existing['userIds'])));
                }
                // Legacy single-value document → seed the set from it.
                $legacy = trim((string) ($existing['userId'] ?? ''));
                if ($legacy !== '' && !in_array($legacy, $ids, true)) $ids[] = $legacy;
            }
            if (!in_array($studentId, $ids, true)) $ids[] = $studentId;

            $this->fs->set('indexPhones', $docId, [
                'schoolId' => $this->school_id,
                'phone'    => $phone,
                // Primary stays STABLE (first registrant) so legacy readers do
                // not silently change meaning when a sibling is added.
                'userId'   => $ids[0],
                'userIds'  => array_values($ids),
                'type'     => $type,
            ]);
        } catch (\Throwable $e) {
            log_message('error', "indexPhones registration failed for {$studentId}/{$phone}: " . $e->getMessage());
        }
    }

    /**
     * ── SRNO-F1 (2026-08-17) — SIGNAL SRNO FAILURE, DO NOT NARRATE IT ────────
     *
     * `$srnoAssigned` is an OUT parameter: it reports whether the SR Number was
     * actually allocated, so a caller can enforce the product invariant
     *
     *     student creation + SRNO allocation = successful admission
     *
     * rather than having to parse prose out of the warnings array.
     *
     * WHY. `studentId` is the global system identity; SRNO is the school-scoped
     * OPERATIONAL identity that schools actually work by. A student admitted
     * without one is not a complete student, and this method used to say the
     * opposite in its own message text ("The admission was completed; SRNO
     * requires administrator follow-up") while returning a warning that
     * save_admission() appended to a SUCCESS response.
     *
     * Defaulting to TRUE keeps every existing caller byte-compatible: a caller
     * that does not pass the parameter behaves exactly as before, which is why
     * enroll_student() is unaffected by this change (see SRNO-F2 there).
     */
    private function _claimIdentityAttributes(string $studentId, string $schoolId, string $apaarInput, array &$studentData, bool &$srnoAssigned = true): array
    {
        $warnings = [];
        $srnoAssigned = true;

        try {
            $this->load->library('Sr_no_service', null, 'srnoSvc');
            $srnoRes = $this->srnoSvc->init($this->firebase, $this->fs, $schoolId, (string) ($this->admin_id ?? 'system'))
                ->allocateNext($schoolId, $studentId);
            if (empty($srnoRes['ok'])) {
                $reason = (string) ($srnoRes['reason'] ?? 'unknown');
                $srnoAssigned = false;
                $warnings[] = "SRNO could not be assigned automatically (reason: {$reason}).";
                log_message('error', "Sr_no_service::allocateNext failed for {$studentId} school={$schoolId} reason={$reason}");
            } else {
                $assignedSrNo = (string) $srnoRes['srNo'];
                // Mirror the same display fields Sr_no_service::relink() writes
                // on the re-admission path, so both first-time and re-admitted
                // students converge on one field shape on the student doc.
                $norm = \Sr_no_service::normalize($assignedSrNo);
                $srnoFields = [
                    'srNo'       => $assignedSrNo,
                    'srNoNorm'   => $norm['norm'] ?? '',
                    'srNoSource' => 'admission_auto',
                    'updatedAt'  => date('c'),
                ];
                // Phase 10.2 — Admission Number for this FIRST-TIME admission
                // event, allocated by Admission_no_service (random token,
                // atomically claimed). Independent of class/section/SRNO/
                // Roll Number by construction. Frozen for the life of THIS
                // admission record: promotion never recomputes it, and a
                // future re-admission gets its own BRAND-NEW number issued
                // by Readmission::confirm() (which no longer leaves the
                // field stale — the Phase 10.1 open gap is now closed).
                $allocatedAdmNo = $this->_allocateAdmissionNumber($studentId, $schoolId);
                if ($allocatedAdmNo !== '') {
                    $srnoFields['Admission Number'] = $allocatedAdmNo;
                    $srnoFields['admissionNumber']  = $allocatedAdmNo; // camelCase dual-emit, Android/Firestore convention
                } else {
                    $warnings[] = 'Admission Number could not be generated automatically. The admission was completed; it requires administrator follow-up.';
                }
                // allocateNext() already wrote srNoIndex + these same fields
                // via claim() internally — this second write is redundant
                // for srNo/srNoNorm but harmless (same values); it exists
                // only to set srNoSource distinctly ('admission_auto') and
                // to merge into $studentData for enroll_student()'s later
                // sync, mirroring the pre-Phase-9.1 pattern exactly.
                $this->fs->update('students', $this->fs->docId($studentId), $srnoFields);
                $studentData = array_merge($studentData, $srnoFields);
                log_message('info', "Sr_no_service: auto-allocated SRNO {$assignedSrNo} for {$studentId} school={$schoolId}");
            }
        } catch (\Throwable $e) {
            // An exception is no less a failure than a returned error — the SRNO
            // does not exist either way, so the invariant is equally violated.
            $srnoAssigned = false;
            log_message('error', "Sr_no_service::allocateNext threw for {$studentId}: " . $e->getMessage());
            $warnings[] = 'SRNO could not be assigned automatically.';
        }

        // Deliberately a SEPARATE service (Apaar_service, not Sr_no_service)
        // per SR_NUMBER_AND_APAAR_ID_DESIGN.md §4/§6 — APAAR is a distinct
        // identity domain with its own claim collection (apaarIndex, not
        // srNoIndex). Format is validated (exactly 12 digits after
        // stripping spaces/hyphens — Apaar_service::normalize()); an
        // invalid format is rejected as a warning, never silently coerced
        // into "valid" data. Warning text never includes the raw APAAR
        // digits (PII discipline — matches Apaar_service's own audit calls,
        // which likewise never log the value).
        if ($apaarInput !== '') {
            try {
                $this->load->library('Apaar_service', null, 'apaarSvc');
                $apaarRes = $this->apaarSvc->init($this->firebase, $this->fs, $schoolId, (string) ($this->admin_id ?? 'system'))
                    ->claim($apaarInput, $studentId);
                if (empty($apaarRes['ok'])) {
                    $reason = (string) ($apaarRes['reason'] ?? 'unknown');
                    if ($reason === 'duplicate') {
                        $existingOwner = (string) ($apaarRes['currentStudentId'] ?? 'another student');
                        $warnings[] = "APAAR ID is already assigned to {$existingOwner} in this school — check for a typing mistake. The admission was completed without it; APAAR requires administrator follow-up.";
                    } elseif ($reason === 'invalid_format') {
                        $warnings[] = 'APAAR ID must be exactly 12 digits — it was not saved. The admission was completed; APAAR requires administrator follow-up.';
                    } else {
                        $warnings[] = "APAAR ID could not be linked automatically (reason: {$reason}). The admission was completed; APAAR requires administrator follow-up.";
                    }
                    log_message('warning', "Apaar_service::claim failed for {$studentId} reason={$reason}");
                } else {
                    $apaarFields = [
                        'apaarId'   => \Apaar_service::normalize($apaarInput),
                        'updatedAt' => date('c'),
                    ];
                    $this->fs->update('students', $this->fs->docId($studentId), $apaarFields);
                    $studentData = array_merge($studentData, $apaarFields);
                    log_message('info', "Apaar_service: claimed APAAR for {$studentId}");
                }
            } catch (\Throwable $e) {
                log_message('error', "Apaar_service::claim threw for {$studentId}: " . $e->getMessage());
                $warnings[] = 'APAAR ID could not be linked automatically. The admission was completed, but APAAR requires administrator follow-up.';
            }
        }

        return $warnings;
    }

    /**
     * ── PHASE 3B / B1 — resolve an entitlement submitted WITH the admission ──
     *
     * Runs after the student and their identity exist, and BEFORE
     * assignInitialFees() prices the demands, so a concession agreed at the
     * admission desk is priced into the first invoice rather than discovered
     * afterwards.
     *
     * Returns a state the caller folds into the admission's billing state:
     *   none      — no entitlement was submitted (the overwhelming common case)
     *   applied   — recorded and ACTIVE, so pricing below will see it
     *   pending   — recorded but awaiting approval; pricing will NOT see it yet
     *   duplicate — identical entitlement already on file (a retry); not re-added
     *   failed    — could not be recorded; the caller downgrades the billing state
     *
     * Deliberately NON-FATAL. The student already exists at this point, so
     * aborting would leave a half-made admission. The failure is surfaced
     * through the billing state instead, which is the honest signal.
     *
     * Approval policy is NOT re-implemented here: it defers to the same
     * has_permission('Fees','manage') rule Entitlements::_approval_required()
     * applies, so the desk and the API cannot diverge.
     */
    private function _resolve_admission_entitlement(string $studentId, string $schoolId): array
    {
        $kind = trim((string) $this->input->post('ent_kind'));
        if ($kind === '') return ['status' => 'none'];

        try {
            $this->load->library('Entitlement_service', null, 'entitlementSvc');
            // ── TENANT IDENTITY BOUNDARY (2026-08-18) ────────────────────────
            //
            // CANONICAL `school_id`, NOT the `$schoolId` argument.
            //
            // save_admission() sets `$school_id = $this->parent_db_key`, and
            // `parent_db_key = school_code ?: school_id` — on this tenant the
            // login code is "10012" while the canonical id is
            // "SCH_B56BB9A401". Passing the legacy key made
            // Entitlement_service ask Fee_head_resolver for
            // `10012_FH_DF3C200439E2`, a document that cannot exist: the Fee
            // Head Registry is keyed by canonical school_id
            // (`SCH_B56BB9A401_FH_DF3C200439E2`). Every head-scoped admission
            // entitlement therefore failed closed with "fee head not
            // registered", and the student was admitted BILLING PENDING.
            //
            // The rule this restores: a store keyed by canonical school_id must
            // be addressed with canonical school_id. `parent_db_key` stays
            // correct for the legacy/RTDB-shaped paths that own that key space
            // — including SRNO, whose `srNoIndex` rows are self-consistently
            // stored under the login code and would be ORPHANED by a blanket
            // rename. This is deliberately the narrowest possible change: one
            // argument, one boundary. See DEC-008.
            $this->entitlementSvc->init(
                $this->firebase, $this->fs, (string) $this->school_id, $this->session_year,
                (string) ($this->admin_id ?? 'system')
            );

            $concessionType = trim((string) ($this->input->post('ent_concession_type') ?? 'other'));
            // Same maker-checker rule as the standalone endpoint.
            $approvalRequired = ($kind === 'concession') && !has_permission('Fees', 'manage');

            $res = $this->entitlementSvc->propose([
                'studentId'            => $studentId,
                'kind'                 => $kind,
                'serviceType'          => trim((string) ($this->input->post('ent_service_type') ?? '')),
                'concessionType'       => $concessionType,
                'scope'                => trim((string) $this->input->post('ent_scope')),
                'valueType'            => trim((string) $this->input->post('ent_value_type')),
                'value'                => $this->input->post('ent_value'),
                // No `frequency` — retired at the P2 contract freeze (I1). The
                // server side of the same obsolete dependency the admission
                // bridge dropped in REG-P6-R1; the service already ignored it.
                'effectiveFrom'        => trim((string) $this->input->post('ent_effective_from')),
                'effectiveTo'          => trim((string) ($this->input->post('ent_effective_to') ?? '')),
                'reason'               => trim((string) ($this->input->post('ent_reason') ?? '')),
                'govtReceivableAmount' => $this->input->post('ent_govt_receivable_amount'),
                'source'               => 'direct_admission',
                'approvalRequired'     => $approvalRequired,
            ]);

            if (empty($res['ok'])) {
                log_message('error', "ACC_ADMISSION_ENTITLEMENT_FAILED student=[{$studentId}]: "
                    . (string) ($res['error'] ?? 'unknown'));
                return ['status' => 'failed', 'error' => (string) ($res['error'] ?? 'Could not record the concession.')];
            }

            if (!empty($res['duplicate'])) {
                return ['status' => 'duplicate', 'id' => (string) $res['id'], 'entitlement_status' => (string) $res['status']];
            }
            // ── ADM-ENT-1 · "RECORDED" IS NOT "APPLIED" ─────────────────────
            // Entitlement_service writes to `studentEntitlements` (canonical)
            // and is NOT gated by USE_ENTITLEMENT_BILLING. But pricing chooses
            // its reader FROM that flag (Fee_lifecycle ~L748): canonical when
            // true, `studentConcessions` when false.
            //
            // So on a tenant that has not been granted the canonical authority,
            // an admission-desk concession lands in a store the pricing engine
            // does not read. assignInitialFees() then prices at full rate, and
            // reporting this as 'applied' would tell the operator
            // "Concession recorded and applied to the initial demands" — which
            // would be false.
            //
            // That is precisely the failure this method's own header describes
            // as fixed: "The operator saw 'Admission successful', the parent saw
            // a full-price invoice, and nothing anywhere reported a problem."
            // The ORDERING was fixed; the store mismatch reproduces the same
            // symptom, so it is reported truthfully instead.
            //
            // Deliberately NOT resolved by making pricing read both stores: the
            // architecture enforces ONE authority ("NEVER BOTH"), and a fallback
            // would resurrect the split-authority hazard that design exists to
            // prevent. The real resolution is the per-tenant grant, which is
            // rollout — not something to fake here.
            $__canonicalPricing = false;
            try {
                $__canonicalPricing = (bool) $this->config->item(
                    'USE_ENTITLEMENT_BILLING', 'fees_exemption_v2_flags'
                );
            } catch (\Throwable $_) {
                $__canonicalPricing = false;   // unknown ⇒ assume not priced
            }

            $__isActive = ((string) $res['status'] === 'active');

            if ($__isActive && !$__canonicalPricing) {
                log_message('error',
                    'ACC_ADMISSION_ENTITLEMENT_NOT_PRICED student=[' . $studentId . '] — recorded in '
                    . 'studentEntitlements but this tenant prices from studentConcessions '
                    . '(USE_ENTITLEMENT_BILLING=false), so the initial demands are FULL PRICE.');
                return [
                    'status'             => 'recorded_not_priced',
                    'id'                 => (string) $res['id'],
                    'entitlement_status' => (string) $res['status'],
                ];
            }

            return [
                'status'             => $__isActive ? 'applied' : 'pending',
                'id'                 => (string) $res['id'],
                'entitlement_status' => (string) $res['status'],
            ];
        } catch (\Throwable $e) {
            log_message('error', "ACC_ADMISSION_ENTITLEMENT_EXCEPTION student=[{$studentId}]: " . $e->getMessage());
            return ['status' => 'failed', 'error' => 'The concession could not be recorded.'];
        }
    }

    /**
     * G10 (2026-08-08) — execute the plan built by _admission_fee_precheck().
     * Either collects through the shared Admission_collection path (the SAME
     * receipt + journal engine pathways 1 and 2 use — no second implementation)
     * or records the audited waive. Never throws: the student is already
     * admitted by this point, so the outcome is reported back to the operator
     * in the response payload instead of failing the request.
     *
     * @return array reportable outcome for the JSON response
     */
    private function _admission_fee_apply(array $plan, string $userId, string $studentName, string $fatherName, string $classOrd, string $leadId): array
    {
        if (empty($plan['due'])) {
            return ['status' => 'not_applicable'];
        }

        if (!empty($plan['waived'])) {
            // Audited exception path — never a silent skip. Uses the same
            // auditLogs mechanism the rest of SIS already writes through.
            log_audit('Fees', 'admission_fee_waived', $userId,
                'Admission fee ' . number_format((float) $plan['amount'], 2)
                . ' WAIVED for ' . $studentName . ' (' . $userId . ') — reason: ' . $plan['waive_reason']);
            $this->_log_history($this->parent_db_key, $userId, 'ADMISSION_FEE_WAIVED',
                'Admission fee of ' . number_format((float) $plan['amount'], 2) . ' waived',
                [
                    'amount'    => (float) $plan['amount'],
                    'fee_head'  => $plan['fee_head'],
                    'reason'    => $plan['waive_reason'],
                    'waived_by' => (string) ($this->admin_id ?? ''),
                    'session'   => $this->session_year,
                ]
            );
            return [
                'status' => 'waived',
                'amount' => (float) $plan['amount'],
                'reason' => $plan['waive_reason'],
            ];
        }

        try {
            $this->load->library('Admission_collection', null, 'admissionCol');
            $this->admissionCol->init($this->firebase, $this->fs, $this->school_name, $this->session_year);
            // app_id: the originating lead when this admission converted one (so
            // the receipt/timeline correlate to it), else the new student id —
            // which keeps Admission_collection's app_id-derived idempotency key
            // stable and unique per admission. append_timeline() is a safe no-op
            // when no crmApplications doc exists for the id.
            $rc = $this->admissionCol->record_payment([
                'app_id'         => $leadId !== '' ? $leadId : $userId,
                'amount'         => (float) $plan['amount'],
                'currency'       => 'INR',
                'student_name'   => $studentName,
                'father_name'    => $fatherName,
                'applied_class'  => $classOrd,
                'fee_head'       => $plan['fee_head'],
                'fee_label'      => $plan['fee_label'],
                'payment_mode'   => $plan['mode'],
                'mode_label'     => $plan['mode_label'],
                'bank_code'      => $plan['bank_code'],
                'bank_reference' => $plan['bank_reference'],
                'remarks'        => 'Direct admission (SIS admission screen)',
                'collected_by'   => $this->admin_name ?: ($this->admin_id ?: 'Admin'),
                'source'         => 'offline',
                'admission_ref'  => 'ADMREF-' . strtoupper($userId),
            ]);
            if (!is_array($rc)) {
                log_message('error',
                    "G10 admission-fee collection returned null for {$userId} (amount={$plan['amount']}) — student admitted, fee NOT collected");
                return ['status' => 'failed', 'amount' => (float) $plan['amount']];
            }
            // A `deduped` result means Admission_collection's idempotency gate
            // matched an EXISTING receipt for this app_id — the correct outcome
            // when a lead's admission fee was already collected at the CRM
            // counter (pathway 2) and that lead is now being converted here. No
            // second receipt, no second journal, no double charge; report the
            // original receipt so the operator isn't told to collect again.
            return [
                'status'     => !empty($rc['deduped']) ? 'already_collected' : 'collected',
                'amount'     => (float) ($rc['amount'] ?? $plan['amount']),
                'receipt_no' => (string) ($rc['receipt_no'] ?? ''),
            ];
        } catch (\Throwable $e) {
            log_message('error', "G10 admission-fee collection failed for {$userId}: " . $e->getMessage());
            return ['status' => 'failed', 'amount' => (float) $plan['amount']];
        }
    }

    /**
     * Record whatever relationships this admission VERIFIED.
     *
     * Runs after the student exists, best-effort: a relationship is valuable
     * but it is not worth failing an admission that already created the
     * student and their Auth account. Every identifier is re-resolved here —
     * the form's hidden ids are display state, and trusting them would let a
     * tampered field link a child to another tenant's student.
     *
     * NO MONEY. This writes relationships only. Any sibling or staff-ward
     * concession must still go through the canonical entitlement path with a
     * reason and an approver — see Student_relationship_service's header.
     *
     * @return string[] human-readable notes to append to the success message
     */
    private function _captureAdmissionRelationships(
        string $studentId,
        string $srNoRaw,
        string $staffIdRaw,
        string $rteRefRaw = ''
    ): array {
        $notes = [];

        // ── RTE / EWS · PERSIST THE REFERENCE, DECIDE NO MONEY ─────────────
        // The certificate reference was captured on the form and then dropped:
        // stored on the online application, never read, and on the direct path
        // not stored at all. A government-category claim that leaves no trace
        // on the student record cannot be audited or produced on request.
        //
        // ZenX ALREADY has the canonical financial path for this — an
        // entitlement of concessionType 'rte_ews' carrying govtReceivableAmount,
        // raised through the capture panel under maker-checker. So the only
        // thing missing was persistence of the identifier, which is a record-
        // keeping fix, not a policy one. Deliberately NO fee effect here: how
        // much an RTE/EWS student pays, and how much is recoverable from the
        // state, is a school-and-statute question this code must not guess.
        $rteRef = trim($rteRefRaw);
        if ($rteRef !== '') {
            try {
                $this->fs->set('students', $this->school_id . '_' . $studentId, [
                    'rteEwsDeclared' => true,
                    'rteEwsRef'      => substr($rteRef, 0, 120),
                    'rteEwsSource'   => 'admission',
                ], /* merge */ true);
                $notes[] = 'RTE/EWS reference recorded. Raise the concession from Services & Concessions '
                         . '— it is not applied automatically.';
            } catch (\Throwable $e) {
                log_message('error', "REL rte capture failed for {$studentId}: " . $e->getMessage());
                $notes[] = 'RTE/EWS reference could not be saved — please add it on the student record.';
            }
        }

        if (trim($srNoRaw) !== '') {
            try {
                $ref = $this->_resolveStudentRef(trim($srNoRaw));
                if (!empty($ref['ok']) && ($ref['via'] ?? '') === 'srNo') {
                    $sibId = (string) $ref['studentId'];
                    $sib   = $this->fs->get('students', $this->school_id . '_' . $sibId);
                    $res   = $this->_relationshipService()->linkSibling($studentId, [
                        'studentId' => $sibId,
                        'name'      => is_array($sib) ? trim((string) ($sib['name'] ?? $sib['Name'] ?? '')) : '',
                        'srNo'      => trim($srNoRaw),
                    ], 'admission');
                    $notes[] = !empty($res['ok'])
                        ? 'Sibling link recorded and verified.'
                        : 'Sibling link NOT recorded: ' . (string) ($res['error'] ?? 'unknown reason');
                } else {
                    // Truthful over convenient: the operator must know the
                    // number did not match rather than assume it did.
                    $notes[] = "Sibling NOT linked — SR No. '" . trim($srNoRaw) . "' did not match a student in this school.";
                }
            } catch (\Throwable $e) {
                log_message('error', "REL sibling capture failed for {$studentId}: " . $e->getMessage());
                $notes[] = 'Sibling link could not be recorded — please add it from the student record.';
            }
        }

        if (trim($staffIdRaw) !== '') {
            try {
                $svc  = $this->_relationshipService();
                $look = $svc->lookupStaff(trim($staffIdRaw));
                if (!empty($look['ok'])) {
                    $res = $svc->linkStaffWard($studentId, $look, 'admission');
                    $notes[] = !empty($res['ok'])
                        ? 'Employee relationship recorded and verified.'
                        : 'Employee link NOT recorded: ' . (string) ($res['error'] ?? 'unknown reason');
                } else {
                    $notes[] = 'Employee NOT linked — ' . (string) ($look['error'] ?? 'staff member not found.');
                }
            } catch (\Throwable $e) {
                log_message('error', "REL staff_ward capture failed for {$studentId}: " . $e->getMessage());
                $notes[] = 'Employee link could not be recorded — please add it from the student record.';
            }
        }

        return $notes;
    }

    /**
     * Phase 10.2 (2026-08-13) — CORRECTION of the Phase 10.1 design.
     *
     * Phase 10.1 generated YYYY/CLASS/SRNO (e.g. "2026/01/012"). That was
     * WRONG per the corrected business rule: an Admission Number must be
     * INDEPENDENT of class, section, SRNO and Roll Number. Embedding SRNO
     * made the two identifiers redundant (SRNO already IS the school's
     * sequential register number) and embedding class made the value
     * conceptually tied to an academic position the student outgrows.
     *
     * Corrected: a system-generated, collision-claimed, random-token
     * admission-EVENT identifier — YYYY/TTTTTT (e.g. "2026/7K4P92") —
     * produced by the dedicated Admission_no_service, a structural sibling
     * of Sr_no_service/Apaar_service. See that class for the format,
     * entropy, scope and atomic-claim rationale.
     *
     * This wrapper exists only to keep the service wiring in ONE place for
     * every admission path (the same "one canonical wiring" discipline
     * _claimIdentityAttributes() already applies to SRNO and APAAR).
     *
     * @param string $studentId  the student this admission event belongs to
     * @param string $schoolId   server-derived tenant (never client input)
     * @return string  the allocated Admission Number, or '' on failure
     *                 (callers treat '' as a non-fatal warning, exactly as
     *                 they already do for a failed SRNO/fee step — an
     *                 admission is never blocked by this)
     */
    private function _allocateAdmissionNumber(string $studentId, string $schoolId): string
    {
        $year = substr((string) $this->session_year, 0, 4);
        if (!preg_match('/^\d{4}$/', $year)) $year = date('Y'); // defensive only — session_year is always set in every real caller

        try {
            $this->load->library('Admission_no_service', null, 'admNoSvc');
            $res = $this->admNoSvc->init($this->firebase, $this->fs, $schoolId, (string) ($this->admin_id ?? 'system'))
                ->allocate($schoolId, $studentId, $year);
            if (!empty($res['ok'])) return (string) $res['admissionNo'];
            log_message('error', "Admission_no_service::allocate failed for {$studentId} school={$schoolId} reason=" . ($res['reason'] ?? 'unknown'));
        } catch (\Throwable $e) {
            log_message('error', "Admission_no_service::allocate threw for {$studentId}: " . $e->getMessage());
        }
        return '';
    }

    private function _relationshipService()
    {
        $this->load->library('student_relationship_service', null, 'relSvc');
        return $this->relSvc->init(
            $this->fs, $this->firebase, $this->school_id,
            $this->session_year, (string) ($this->admin_id ?? 'system')
        );
    }
}
