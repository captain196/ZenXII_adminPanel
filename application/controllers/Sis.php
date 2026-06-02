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
        $this->_require_role(self::VIEW_ROLES, 'sis_view');
        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        // Read all students from Firestore
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
        $this->_require_role(self::VIEW_ROLES, 'sis_students');
        $session = $this->session_year;

        $data['class_map']    = $this->_fs_class_map();
        $data['session_year'] = $session;

        $this->load->view('include/header');
        $this->load->view('sis/students', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       ADMISSION
    ══════════════════════════════════════════════════════════════════════ */

    public function admission()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_admission');
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
        $this->_require_role(self::MANAGE_ROLES, 'sis_save_admission');
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

        // M-07 FIX: Validate email format before storing
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json_error('Invalid email address format.');
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
        if (!$this->entity_sync->syncStudent($userId, $studentData)) {
            log_message('warning', "syncStudent returned false for {$userId} (save_admission)");
        }
        if (!$this->entity_sync->syncParent($userId, $studentData)) {
            log_message('warning', "syncParent returned false for {$userId} (save_admission)");
        }

        // G2 — Refresh sections.currentStrength after a new admit so the
        // admission section-picker's strength bars stay accurate.
        $this->_recompute_section_strength($classKey, $sectionKey);

        // Phone index
        if (!empty($phone)) {
            $this->fs->set('indexPhones', $this->fs->docId($phone), [
                'schoolId' => $this->school_id, 'phone' => $phone,
                'userId' => $userId, 'type' => 'student',
            ]);
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
        try {
            $monthFeeOk = (bool) $this->fs->updateEntity('students', $userId, ['monthFee' => $monthFeeInit]);
        } catch (Exception $e) {
            log_message('error', "SIS admit fee init failed for {$userId}: " . $e->getMessage());
        }
        if (!$monthFeeOk) {
            return $this->json_error('Failed to initialize fee tracking for student. Please retry. The student record was not created.');
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
        // 4. POST-ADMISSION — Fees, history, leads, notifications
        // ══════════════════════════════════════════════════════════════
        try {
            $this->feeLifecycle->assignInitialFees($userId, $classOrd, $section, $school_id);
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::assignInitialFees failed for {$userId}: " . $e->getMessage());
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
                $history = $lead['history'] ?? [];
                $history[] = ['action' => "Converted to student {$userId}", 'by' => $this->admin_name, 'timestamp' => $now];
                $this->fs->update('crmApplications', $this->fs->docId($leadId), [
                    'status' => 'admitted', 'stage' => 'enrolled',
                    'student_id' => $userId, 'updated_at' => $now, 'history' => $history,
                ]);
            }
        }

        // Notify parent
        if ($phone !== '') {
            notify_admission_confirmed($phone, $this->school_display_name ?? $this->school_name, $userId, $name);
        }

        return $this->json_success([
            'message'  => 'Student admitted successfully.',
            'user_id'  => $userId,
            'name'     => $name,
            'password' => $password,
            'class'    => str_replace('Class ', '', $classOrd),
            'section'  => str_replace('Section ', '', $section),
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PROFILE
    ══════════════════════════════════════════════════════════════════════ */

    public function profile($userId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_profile');
        if (empty($userId) || !$this->safe_path_segment($userId)) show_404();

        // Delegate to the comprehensive student_profile view
        $this->student_profile($userId);
    }

    public function update_profile()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_update_profile');
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
        $this->_require_role(self::MANAGE_ROLES, 'sis_promote');
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
        $this->_require_role(self::MANAGE_ROLES, 'sis_promote_preview');
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
        $this->_require_role(self::MANAGE_ROLES, 'sis_execute_promotion');
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

        // Single atomic RTDB multi-path update for ALL students
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
        $this->_require_role(self::VIEW_ROLES, 'sis_tc_list');
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
        $this->_require_role(self::MANAGE_ROLES, 'sis_issue_tc');
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
        $this->_require_role(self::VIEW_ROLES, 'sis_print_tc');
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
                    echo '<!DOCTYPE html><html><head><title>TC Withheld</title><style>body{font:15px/1.5 system-ui;padding:60px;color:#334155;text-align:center;}h1{color:#dc2626;}a{color:#0f766e;}</style></head><body>'
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
        $this->_require_role(self::MANAGE_ROLES, 'sis_cancel_tc');
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
        $this->_require_role(self::MANAGE_ROLES, 'sis_withdraw');
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
        $this->_require_role(self::MANAGE_ROLES, 'sis_change_status');
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
       DOCUMENTS
    ══════════════════════════════════════════════════════════════════════ */

    public function documents($userId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_documents');
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
        $this->_require_role(self::MANAGE_ROLES, 'sis_upload_doc');
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

        $storagePath = "Students/{$school_id}/{$userId}/docs/{$docLabel}";

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
        $this->_require_role(self::MANAGE_ROLES, 'sis_delete_doc');
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

        // ─────────────────────────────────────────────────────────────────
        // Storage-orphan fix (2026-05-30): delete the Storage object BEFORE
        // removing the Firestore reference. Mirrors the proven
        // edit_student@3435 pattern via _deleteOldStorageFile. Without this
        // call the Firestore entry vanished but the underlying Storage file
        // lingered indefinitely (only cleaned at full student deletion).
        // ─────────────────────────────────────────────────────────────────
        $oldDocNode = $docMap[$docLabel] ?? [];
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
       HISTORY
    ══════════════════════════════════════════════════════════════════════ */

    public function history($userId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_history');
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
        $this->_require_role(self::VIEW_ROLES);
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $allStudentDocs = $this->fs->schoolWhere('students', [['status', '==', 'Active']], 'name', 'ASC');
        if (empty($allStudentDocs)) {
            $allStudentDocs = $this->fs->schoolWhere('students', [['Status', '==', 'Active']], 'Name', 'ASC');
        }
        $allStudents = [];
        foreach ($allStudentDocs as $doc) {
            $d = $doc['data'] ?? $doc;
            $s = $this->_normalizeStudentDoc($doc['data']);
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
        $this->_require_role(self::MANAGE_ROLES, 'sis_rebuild_index');

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
        $this->_require_role(self::VIEW_ROLES, 'sis_search');
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
        $studentList = $this->fs->schoolList('students');

        $currentSession = $this->session_year;
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
                $haystack = strtolower(($entry['name'] ?? '') . ' ' . $uid);
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
        $this->_require_role(self::VIEW_ROLES, 'sis_get_student');
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
        $this->_require_role(self::VIEW_ROLES, 'sis_get_classes');
        $classMap = $this->_fs_class_map();
        return $this->json_success(['classes' => array_keys($classMap)]);
    }

    public function get_sections()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_get_sections');
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
            $this->firebase->setFirebaseClaims($studentId, [
                'role'          => 'student',
                'school_id'     => $this->school_id,
                'school_code'   => $this->school_code,
                'parent_db_key' => $this->parent_db_key,
            ]);
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
        $currentCount = count($docMap);
        $currentBytes = 0;
        foreach ($docMap as $entry) {
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
        $basePath  = "{$schoolName}/Students/{$combinedClassPath}/{$studentId}/";

        $documentPath = ($type === 'profile')
            ? $basePath . "Profile_pic/{$fileName}"
            : $basePath . "Documents/{$fileName}";

        if ($this->firebase->uploadFile($file['tmp_name'], $documentPath) !== true) return false;

        $documentUrl  = $this->firebase->getDownloadUrl($documentPath);
        $thumbnailUrl = '';

        // Image thumbnail (document mode)
        if ($type === 'document' && in_array($ext, ['jpg','jpeg','png','webp'])) {
            $thumbPath = $basePath . "Documents/thumbnail/{$fileName}";
            if ($this->firebase->uploadFile($file['tmp_name'], $thumbPath) === true) {
                $thumbnailUrl = $this->firebase->getDownloadUrl($thumbPath);
            }
        }

        // PDF thumbnail (document mode)
        if ($type === 'document' && $ext === 'pdf') {
            $thumbnailUrl = $this->_generatePdfThumbnail($file['tmp_name'], $basePath."Documents/", $safeLabel, $timestamp, $random);
        }

        // Profile photo thumbnail
        if ($type === 'profile' && in_array($ext, ['jpg','jpeg','png','webp'])) {
            $thumbPath = $basePath . "Profile_pic/thumbnail/{$fileName}";
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
        $adminName = $this->session->userdata('admin_name') ?? 'System';
        $histKey   = date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $docId     = $schoolId . '_' . $userId . '_' . $histKey;
        $data = [
            'schoolId'    => $schoolId,
            'studentId'   => $userId,
            'histKey'     => $histKey,
            'action'      => $action,
            'description' => $description,
            'changed_by'  => $adminName,
            'changed_at'  => date('Y-m-d H:i:s'),
            'metadata'    => $metadata,
        ];
        try {
            $ok = $this->firebase->firestoreCreate('studentHistory', $docId, $data);
            if (!$ok) {
                log_message('error', "Sis::_log_history studentHistory create returned false: {$docId} action={$action}");
            }
        } catch (\Throwable $e) {
            log_message('error', "Sis::_log_history studentHistory write failed: {$docId} action={$action} err=" . $e->getMessage());
        }
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
        if ($includeNonActive) {
            $studentDocs = $this->fs->schoolWhere('students');
        } else {
            $studentDocs = $this->fs->schoolWhere('students', [['status', '==', 'Active']]);
            // Fallback: if no results with camelCase, try Title Case
            if (empty($studentDocs)) {
                $studentDocs = $this->fs->schoolWhere('students', [['Status', '==', 'Active']]);
            }
        }

        $enrolledIds = [];
        $currentSession = $this->session_year;
        foreach ($studentDocs as $doc) {
            $d   = $doc['data'];
            $uid = $d['studentId'] ?? $d['User Id'] ?? $d['userId'] ?? $d['id'];

            // When pulling all statuses, drop hard-deleted rows — they
            // shouldn't surface in any list. Active / Inactive / TC pass.
            if ($includeNonActive) {
                $rowStatus = (string) ($d['status'] ?? $d['Status'] ?? '');
                if (strcasecmp($rowStatus, 'Deleted') === 0) continue;
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
        $this->_require_role(self::VIEW_ROLES);
        $this->load->view('include/header');
        $this->load->view('import_students');
        $this->load->view('include/footer');
    }

    public function import_students()
    {
        $this->_require_role(self::MANAGE_ROLES);
        try {
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) log_message('debug', '=== IMPORT FUNCTION STARTED ===');

            $school_id    = $this->parent_db_key;
            $school_name  = $this->school_name;
            $session_year = $this->session_year;

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

            $success = 0;
            $error   = 0;
            $skipped = [];

            // Load ID generator for globally unique student IDs
            $this->load->library('id_generator');

            $subjectCache = [];

            foreach ($sheetData as $row) {
                if (!array_filter($row)) continue;
                if (count($headers) != count($row)) { $error++; continue; }

                $rowData = array_combine($headers, $row);

                $studentName = trim($rowData['Name'] ?? '');
                $classRaw    = trim($rowData['Class'] ?? '');
                $section     = trim($rowData['Section'] ?? '');
                if (!$studentName || !$classRaw || !$section) { $error++; continue; }

                preg_match('/\d+/', $classRaw, $match);
                if (!isset($match[0])) { $error++; continue; }
                $classNumber = (int)$match[0];

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

                // SIS Tier-1 fix B6 (2026-05-31): destination fee-structure
                // pre-flight per row, mirroring the promotion guard at
                // Sis.php:965-974 and the enroll_student sibling above.
                // Without this check, an imported row whose class/section
                // has no feeStructure doc would still get a studentId
                // consumed, saveStudent, indexes, Auth, syncStudent — and
                // assignInitialFees would then silently return [] leaving
                // the student Active-with-zero-demands. Block the row here,
                // before any studentId is consumed; the row appears in the
                // import-summary skipped list with a specific reason.
                $destFeeStructDocId = "{$school_name}_{$session_year}_{$className}_{$section}";
                $destFeeStruct      = $this->fs->get('feeStructures', $destFeeStructDocId);
                if (!is_array($destFeeStruct) || empty($destFeeStruct['feeHeads'])) {
                    $skipped[] = "Row " . ($success + $error + count($skipped) + 1)
                        . ": {$studentName} — No fee structure for {$className}/{$section} in session {$session_year}";
                    $error++;
                    continue;
                }

                // Generate globally unique student ID from central counter
                $studentId = $this->_nextStudentId($school_id);
                if (!$studentId) {
                    $skipped[] = "Row " . ($success + $error + count($skipped) + 1) . ": {$studentName} — ID generation failed";
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
                    $skipped[] = "Row " . ($success + $error + count($skipped) + 1) . ": {$studentName} — Firestore save failed (see log)";
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
                    $skipped[] = "Row " . ($success + $error + count($skipped) + 1)
                        . ": {$studentName} — monthFee init failed (see log); student doc remains without fee tracking";
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

                $success++;
            }

            $msg = "Imported Successfully: {$success} | Failed: {$error}";
            if (!empty($skipped)) {
                $msg .= " | Skipped (ID collision): " . count($skipped) . " — " . implode('; ', $skipped);
            }
            $this->session->set_flashdata('import_result', $msg);
            redirect('sis/all_student');
        } catch (Exception $e) {
            log_message('error', 'IMPORT ERROR: ' . $e->getMessage());
            $this->session->set_flashdata('import_result', "Import Failed! Check logs.");
            redirect('sis/all_student');
        }
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
        $this->_require_role(self::VIEW_ROLES);
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
        $this->_require_role(self::VIEW_ROLES);
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
        $this->_require_role(self::MANAGE_ROLES);
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
        $this->_require_role(self::MANAGE_ROLES);
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

            // Clean storage
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
        $this->_require_role(self::VIEW_ROLES);
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
        $this->_require_role(self::VIEW_ROLES);
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
        $this->_require_role(self::VIEW_ROLES);
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
        $this->_require_role(self::VIEW_ROLES);
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
    private function _crm_list(string $collection): array
    {
        try {
            $docs = $this->fs->schoolWhere($collection, []);
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
        $flatKey = "crmCounters.{$type}";
        $profileDocId = $this->fs->docId('profile');
        $doc = null;
        try { $doc = $this->fs->get('schools', $profileDocId); } catch (\Exception $e) {}
        $cur = (is_array($doc) && isset($doc[$flatKey]) && is_numeric($doc[$flatKey]))
            ? (int) $doc[$flatKey] : 0;
        $next = $cur + 1;
        try { $this->fs->update('schools', $profileDocId, [$flatKey => $next]); }
        catch (\Exception $e) { log_message('error', "CRM counter update failed for {$type}: " . $e->getMessage()); }
        return $prefix . str_pad($next, $pad, '0', STR_PAD_LEFT);
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
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $data['session_year'] = $this->session_year;
        $data['school_name']  = $this->school_name;
        $this->load->view('include/header');
        $this->load->view('sis/admission_leads', $data);
        $this->load->view('include/footer');
    }

    // LEAD SYSTEM — AJAX: fetch leads data
    public function fetch_leads()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $applications = $this->_crm_list('crmApplications');
        if (!is_array($applications)) $applications = [];

        $session = $this->session_year;
        $leads = [];
        foreach ($applications as $id => $app) {
            if (!is_array($app)) continue;
            // Show all leads for current session (both public and CRM)
            if (($app['session'] ?? '') !== $session) continue;
            $app['id'] = $id;
            $leads[] = $app;
        }
        // Newest first
        usort($leads, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return $this->json_success(['leads' => $leads]);
    }

    // LEAD SYSTEM — Single lead detail (AJAX)
    public function admission_lead()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
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
        $this->_require_role(self::MANAGE_ROLES, 'crm_manage');
        $leadId = trim($this->input->post('lead_id') ?? '');
        $status = trim($this->input->post('status') ?? '');
        if ($leadId === '' || $status === '') return $this->json_error('Lead ID and status required.');
        $leadId = $this->safe_path_segment($leadId, 'lead_id');

        $allowed = ['new', 'contacted', 'interested', 'approved', 'rejected', 'enrolled', 'admitted'];
        if (!in_array($status, $allowed, true)) return $this->json_error('Invalid status.');

        $lead = $this->_crm_get('crmApplications', $leadId);
        if (!is_array($lead)) return $this->json_error('Lead not found.');

        $now = date('Y-m-d H:i:s');
        $history = $lead['history'] ?? [];
        $history[] = ['action' => "Status changed to {$status}", 'by' => $this->admin_name, 'timestamp' => $now];

        $this->_crm_update("crmApplications", $leadId, [
            'status'     => $status,
            'updated_at' => $now,
            'history'    => $history,
        ]);
        // Firestore dual-write: lead status update
        try { $this->fs->updateEntity('crmApplications', $leadId, ['status' => $status, 'updated_at' => $now]); } catch (\Exception $e) { log_message('error', "Firestore dual-write update_lead_status failed: " . $e->getMessage()); }
        log_audit('CRM', 'update_lead_status', $leadId, "Lead status changed to '{$status}' for " . ($lead['student_name'] ?? ''));
        return $this->json_success(['message' => 'Status updated.']);
    }

    // LEAD SYSTEM — Fetch lead data for admission form prefill
    public function get_lead_data()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_admission');
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
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $session = $this->session_year;

        // Single read — fetch all applications once
        $applications = $this->_crm_list('crmApplications');
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
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $session = $this->session_year;

        $inquiries    = $this->_crm_list('crmInquiries');
        $applications = $this->_crm_list('crmApplications');
        $waitlist     = $this->_crm_list('crmWaitlist');
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

        $data = compact('stats') + ['class_breakdown'=>$classBreakdown,'source_breakdown'=>$sourceBreakdown,'monthly_trend'=>$monthlyTrend,'settings'=>$settings,'session_year'=>$session];
        $this->load->view('include/header');
        $this->load->view('admission_crm/index', $data);
        $this->load->view('include/footer');
    }

    public function inquiries()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_crm_classes();
        $this->load->view('include/header');
        $this->load->view('admission_crm/inquiries', $data);
        $this->load->view('include/footer');
    }

    public function fetch_inquiries()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $inquiries = $this->_crm_list('crmInquiries');
        if (!is_array($inquiries)) $inquiries = [];
        $session = $this->session_year;
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
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_inquiry');
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
            // Firestore dual-write: update inquiry
            try { $this->fs->setEntity('crmInquiries', $id, $data); } catch (\Exception $e) { log_message('error', "Firestore dual-write crmInquiries failed for {$id}: " . $e->getMessage()); }
        } else {
            $id = $this->_crm_next_id('Inquiry', 'INQ', 5);
            $data = compact('student_name','parent_name','phone','email','class','source','notes','status','follow_up_date') + [
                'inquiry_id'=>$id, 'session'=>$this->session_year, 'created_at'=>$now, 'updated_at'=>$now, 'created_by'=>$this->admin_name,
            ];
            $this->_crm_set("crmInquiries", $id, $data);
            // Counter managed by _crm_next_id — no separate write needed.
            // Firestore dual-write: new inquiry
            try { $this->fs->setEntity('crmInquiries', $id, $data); } catch (\Exception $e) { log_message('error', "Firestore dual-write crmInquiries failed for {$id}: " . $e->getMessage()); }
        }
        return $this->json_success(['id' => $id]);
    }

    public function delete_inquiry()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_delete_inquiry');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Inquiry ID required');
        $safeId = $this->safe_path_segment($id, 'id');
        $this->_crm_delete('crmInquiries', $safeId);
        // Firestore dual-write: delete inquiry
        try { $this->fs->removeEntity('crmInquiries', $safeId); } catch (\Exception $e) { log_message('error', "Firestore dual-write delete crmInquiries failed: " . $e->getMessage()); }
        return $this->json_success();
    }

    public function convert_to_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_convert');
        $inquiry_id = trim($this->input->post('inquiry_id') ?? '');
        if (!$inquiry_id) return $this->json_error('Inquiry ID required');
        $inquiry_id = $this->safe_path_segment($inquiry_id, 'inquiry_id');
        $inquiry = $this->_crm_get('crmInquiries', $inquiry_id);
        if (!is_array($inquiry)) return $this->json_error('Inquiry not found');

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
        $this->_crm_set("crmApplications", $app_id, $application);
        // Counter managed by _crm_next_id — no separate write needed.
        $this->_crm_update("crmInquiries", $inquiry_id, ['status'=>'converted','application_id'=>$app_id,'updated_at'=>$now]);
        // Firestore dual-write: new application + inquiry status update
        try {
            $this->fs->setEntity('crmApplications', $app_id, $application);
            $this->fs->updateEntity('crmInquiries', $inquiry_id, ['status'=>'converted','application_id'=>$app_id,'updated_at'=>$now]);
        } catch (\Exception $e) { log_message('error', "Firestore dual-write convert_to_application failed: " . $e->getMessage()); }
        return $this->json_success(['application_id' => $app_id]);
    }

    public function applications()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_crm_classes();
        $this->load->view('include/header');
        $this->load->view('admission_crm/applications', $data);
        $this->load->view('include/footer');
    }

    public function fetch_applications()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $applications = $this->_crm_list('crmApplications');
        if (!is_array($applications)) $applications = [];
        $session = $this->session_year;
        $result = [];
        foreach ($applications as $id => $app) {
            if (!is_array($app) || ($app['session'] ?? '') !== $session) continue;
            $app['id'] = $id;
            $result[] = $app;
        }
        usort($result, fn($a,$b) => strcmp($b['created_at']??'',$a['created_at']??''));
        return $this->json_success(['applications' => $result]);
    }

    public function save_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_app');
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

        if ($id) {
            $existing = $this->_crm_get('crmApplications', $id);
            if (!is_array($existing)) return $this->json_error('Application not found');
            $data['updated_at'] = $now;
            $history = $existing['history'] ?? [];
            $history[] = ['action'=>'Application updated','by'=>$this->admin_name,'timestamp'=>$now];
            $data['history'] = $history;
            $this->_crm_update("crmApplications", $id, $data);
            // Firestore dual-write: update application
            try { $this->fs->setEntity('crmApplications', $id, $data); } catch (\Exception $e) { log_message('error', "Firestore dual-write crmApplications update failed for {$id}: " . $e->getMessage()); }
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
            // Firestore dual-write: new application
            try { $this->fs->setEntity('crmApplications', $app_id, $data); } catch (\Exception $e) { log_message('error', "Firestore dual-write crmApplications create failed for {$app_id}: " . $e->getMessage()); }
            return $this->json_success(['id' => $app_id]);
        }
    }

    public function get_application()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $id = trim($this->input->get('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found');
        $app['id'] = $id;
        return $this->json_success(['application' => $app]);
    }

    public function delete_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_delete_app');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $safeId = $this->safe_path_segment($id, 'id');
        $this->_crm_delete('crmApplications', $safeId);
        // Firestore dual-write: delete application
        try { $this->fs->removeEntity('crmApplications', $safeId); } catch (\Exception $e) { log_message('error', "Firestore dual-write delete crmApplications failed: " . $e->getMessage()); }
        return $this->json_success();
    }

    public function pipeline()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
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
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $applications = $this->_crm_list('crmApplications');
        if (!is_array($applications)) $applications = [];
        $settings = $this->_crm_get_settings();
        $stages = $settings['stages'] ?? $this->_default_stages();
        $session = $this->session_year;
        $pipeline = [];
        foreach ($stages as $key => $label) $pipeline[$key] = ['label'=>$label,'items'=>[]];
        foreach ($applications as $id => $app) {
            if (!is_array($app) || ($app['session']??'') !== $session || ($app['status']??'') === 'enrolled') continue;
            $stage = $app['stage'] ?? 'document_collection';
            $app['id'] = $id;
            if (isset($pipeline[$stage])) $pipeline[$stage]['items'][] = $app;
            else { $fk = array_key_first($pipeline); if ($fk) $pipeline[$fk]['items'][] = $app; }
        }
        return $this->json_success(['pipeline'=>$pipeline,'stages'=>$stages]);
    }

    public function update_stage()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_update_stage');
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
        $history = $app['history'] ?? [];
        $history[] = ['action'=>"Stage changed: {$app['stage']} → {$stage}",'by'=>$this->admin_name,'timestamp'=>$now];
        $this->_crm_update("crmApplications", $id, ['stage'=>$stage,'updated_at'=>$now,'history'=>$history]);
        // Firestore dual-write: stage update
        try { $this->fs->updateEntity('crmApplications', $id, ['stage'=>$stage,'updated_at'=>$now]); } catch (\Exception $e) { log_message('error', "Firestore dual-write update_stage failed: " . $e->getMessage()); }
        return $this->json_success();
    }

    public function approve_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_approve');
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
        $history = $app['history'] ?? [];
        $history[] = ['action'=>'Application approved'.($remarks?": {$remarks}":''),'by'=>$this->admin_name,'timestamp'=>$now];
        $this->_crm_update("crmApplications", $id, ['status'=>'approved','stage'=>'approved','approved_by'=>$this->admin_name,'approved_at'=>$now,'remarks'=>$remarks,'updated_at'=>$now,'history'=>$history]);
        // Firestore dual-write: approve application
        try { $this->fs->updateEntity('crmApplications', $id, ['status'=>'approved','stage'=>'approved','approved_by'=>$this->admin_name,'approved_at'=>$now,'remarks'=>$remarks,'updated_at'=>$now]); } catch (\Exception $e) { log_message('error', "Firestore dual-write approve_application failed: " . $e->getMessage()); }
        return $this->json_success();
    }

    public function reject_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_reject');
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
        $history = $app['history'] ?? [];
        $history[] = ['action'=>'Application rejected'.($reason?": {$reason}":''),'by'=>$this->admin_name,'timestamp'=>$now];
        $this->_crm_update("crmApplications", $id, ['status'=>'rejected','stage'=>'rejected','rejected_by'=>$this->admin_name,'rejected_at'=>$now,'reject_reason'=>$reason,'updated_at'=>$now,'history'=>$history]);
        // Firestore dual-write: reject application
        try { $this->fs->updateEntity('crmApplications', $id, ['status'=>'rejected','stage'=>'rejected','rejected_by'=>$this->admin_name,'rejected_at'=>$now,'reject_reason'=>$reason,'updated_at'=>$now]); } catch (\Exception $e) { log_message('error', "Firestore dual-write reject_application failed: " . $e->getMessage()); }
        return $this->json_success();
    }

    public function enroll_student()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_enroll');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found');
        if (($app['status'] ?? '') !== 'approved') return $this->json_error('Only approved applications can be enrolled');

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
            "Profile Pic"=>"",
            "Doc"=>["Aadhar Card"=>["thumbnail"=>"","url"=>""],"Birth Certificate"=>["thumbnail"=>"","url"=>""],"Photo"=>["thumbnail"=>"","url"=>""],"Transfer Certificate"=>["thumbnail"=>"","url"=>""]],
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
        $studentSaved = false;
        try {
            $studentSaved = (bool) $this->fs->saveStudent($studentId, $studentData);
        } catch (\Exception $e) {
            log_message('error', "Firestore saveStudent failed for {$studentId}: " . $e->getMessage());
        }
        if (!$studentSaved) {
            return $this->json_error('Failed to create student profile in database. Please retry. If the issue persists, contact support.');
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
            return $this->json_error('Failed to initialize fee tracking for student. Please retry. CRM application has NOT been marked enrolled; no Firebase Auth account was created.');
        }

        $history = $app['history'] ?? [];
        $history[] = ['action'=>"Enrolled as {$studentId} in {$className} {$section}",'by'=>$this->admin_name,'timestamp'=>$now];
        $this->_crm_update("crmApplications", $id, ['status'=>'enrolled','stage'=>'enrolled','student_id'=>$studentId,'enrolled_at'=>$now,'enrolled_by'=>$this->admin_name,'updated_at'=>$now,'history'=>$history]);
        // Firestore dual-write: CRM application status
        try { $this->fs->setEntity('crmApplications', $id, ['status'=>'enrolled','stage'=>'enrolled','student_id'=>$studentId,'enrolled_at'=>$now,'enrolled_by'=>$this->admin_name,'updated_at'=>$now]); } catch (\Exception $e) { log_message('error', "Firestore dual-write crmApplications failed for {$id}: " . $e->getMessage()); }

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

        // Auto-assign class fees for enrolled student
        try {
            $parentDbKey = $school_id;
            $this->feeLifecycle->assignInitialFees($studentId, $className, $section, $parentDbKey);
            log_message('info', "Fee_lifecycle: auto-assigned fees for new enrollment {$studentId}");
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::assignInitialFees failed for {$studentId}: " . $e->getMessage());
        }

        // Firestore sync for Android apps (entity_sync loaded in constructor)
        // SIS Wave-2 S6 (2026-05-31): observability for sync return.
        if (!$this->entity_sync->syncStudent($studentId, $studentData)) {
            log_message('warning', "syncStudent returned false for {$studentId} (enroll_student)");
        }
        if (!$this->entity_sync->syncParent($studentId, $studentData)) {
            log_message('warning', "syncParent returned false for {$studentId} (enroll_student)");
        }

        // SIS Tier 2 carry (2026-05-30): emit ADMISSION history entry
        // mirroring save_admission@536. Without this, students enrolled via
        // the CRM-approval path had no ADMISSION row in their History array
        // (forensic-documented gap, 7/9 students at SCH_D94FE8F7AD missing).
        $this->_log_history($school_id, $studentId, 'ADMISSION',
            "Student admitted to {$className} / {$section} ({$session})",
            ['class' => $className, 'section' => $section, 'session' => $session]
        );

        // Response carries the credentials separately from the user-
        // facing message so the JS can show a clean toast AND a
        // copyable credentials panel. Password deliberately NOT in
        // `message` — toasts auto-dismiss and showing secrets in a
        // toast is bad UX + bad security.
        return $this->json_success([
            'student_id'   => $studentId,
            'class'        => $className,
            'section'      => $section,
            'password'     => $generatedPassword,           // plain — for the credentials panel
            'auth_created' => $authCreated,
            'auth_error'   => $authCreated ? '' : $authError,
            'sms_sent'     => $smsSent,
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
        $this->_require_role(self::MANAGE_ROLES, 'sis_repair_auth');

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
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $cls = trim((string) $this->input->get('class'));
        if ($cls === '') return $this->json_success(['sections' => [], 'detail' => [], 'suggested' => '']);
        $className = Firestore_service::classKey($cls);

        $detail = [];
        try {
            $docs = $this->fs->schoolList('sections', [
                ['session',   '==', $this->session_year],
                ['className', '==', $className],
            ]);
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
                $current = 0;
                try {
                    $current = $this->fs->count('students', [
                        ['schoolId',  '==', $this->school_id],
                        ['className', '==', $className],
                        ['section',   '==', $sectionStored],
                        ['Status',    '==', 'Active'],
                    ]);
                } catch (\Exception $e) {
                    // Fallback to stored studentCount on count() failure.
                    $current = (int) ($d['studentCount'] ?? 0);
                }

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
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_crm_classes();
        $this->load->view('include/header');
        $this->load->view('admission_crm/waitlist', $data);
        $this->load->view('include/footer');
    }

    public function fetch_waitlist()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $waitlist = $this->_crm_list('crmWaitlist');
        if (!is_array($waitlist)) $waitlist = [];
        $session = $this->session_year;
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
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_add');
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
        $history = $app['history'] ?? [];
        $history[] = ['action'=>'Added to waitlist','by'=>$this->admin_name,'timestamp'=>$now];
        $this->_crm_update("crmApplications", $app_id, ['status'=>'waitlisted','stage'=>'waitlisted','updated_at'=>$now,'history'=>$history]);
        // Firestore dual-write: waitlist entry + application status
        try {
            $this->fs->setEntity('crmWaitlist', $wl_id, $waitEntry);
            $this->fs->updateEntity('crmApplications', $app_id, ['status'=>'waitlisted','stage'=>'waitlisted','updated_at'=>$now]);
        } catch (\Exception $e) { log_message('error', "Firestore dual-write add_to_waitlist failed: " . $e->getMessage()); }
        return $this->json_success(['id' => $wl_id]);
    }

    public function remove_from_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_remove');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Waitlist ID required');
        $id = $this->safe_path_segment($id, 'id');
        $entry = $this->_crm_get('crmWaitlist', $id);
        if (is_array($entry) && !empty($entry['application_id'])) {
            $this->_crm_update('crmApplications', $entry['application_id'], ['status'=>'pending','stage'=>'document_collection','updated_at'=>date('Y-m-d H:i:s')]);
            // Firestore dual-write: revert application status
            try { $this->fs->updateEntity('crmApplications', $entry['application_id'], ['status'=>'pending','stage'=>'document_collection','updated_at'=>date('Y-m-d H:i:s')]); } catch (\Exception $e) { log_message('error', "Firestore dual-write remove_from_waitlist app failed: " . $e->getMessage()); }
        }
        $this->_crm_delete('crmWaitlist', $id);
        // Firestore dual-write: delete waitlist entry
        try { $this->fs->removeEntity('crmWaitlist', $id); } catch (\Exception $e) { log_message('error', "Firestore dual-write delete crmWaitlist failed: " . $e->getMessage()); }
        return $this->json_success();
    }

    public function promote_from_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_promote');
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
            $history = $app['history'] ?? [];
            $history[] = ['action'=>'Promoted from waitlist and approved','by'=>$this->admin_name,'timestamp'=>$now];
            $this->_crm_update("crmApplications", $app_id, ['status'=>'approved','stage'=>'approved','approved_by'=>$this->admin_name,'approved_at'=>$now,'updated_at'=>$now,'history'=>$history]);
            // Firestore dual-write: approve from waitlist
            try { $this->fs->updateEntity('crmApplications', $app_id, ['status'=>'approved','stage'=>'approved','approved_by'=>$this->admin_name,'approved_at'=>$now,'updated_at'=>$now]); } catch (\Exception $e) { log_message('error', "Firestore dual-write promote_from_waitlist failed: " . $e->getMessage()); }
        }
        $this->_crm_delete('crmWaitlist', $id);
        // Firestore dual-write: delete waitlist entry
        try { $this->fs->removeEntity('crmWaitlist', $id); } catch (\Exception $e) { log_message('error', "Firestore dual-write delete crmWaitlist failed: " . $e->getMessage()); }
        return $this->json_success();
    }

    public function crm_settings()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $settings = $this->_crm_get_settings();
        if (!is_array($settings)) $settings = [];
        $data = ['settings'=>$settings,'session_year'=>$this->session_year,'classes'=>$this->_get_crm_classes()];
        $this->load->view('include/header');
        $this->load->view('admission_crm/settings', $data);
        $this->load->view('include/footer');
    }

    public function save_crm_settings()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_settings');
        $settings = $this->_crm_get_settings();
        if (!is_array($settings)) $settings = [];
        foreach (['stages','class_limits','form_fields','notifications'] as $key) {
            $val = $this->input->post($key);
            if ($val) { $decoded = json_decode($val, true); if (is_array($decoded)) $settings[$key] = $decoded; }
        }
        $settings['updated_at'] = date('Y-m-d H:i:s');
        $this->_crm_save_settings($settings);
        // Firestore dual-write: CRM settings
        try { $this->fs->setEntity('crmSettings', 'config', $settings); } catch (\Exception $e) { log_message('error', "Firestore dual-write crmSettings failed: " . $e->getMessage()); }
        return $this->json_success();
    }

    public function get_crm_settings()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $settings = $this->_crm_get_settings();
        if (!is_array($settings)) $settings = [];
        if (empty($settings['stages'])) $settings['stages'] = $this->_default_stages();
        return $this->json_success(['settings' => $settings]);
    }

    public function online_form()
    {
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

        $existingApps = $this->_crm_list('crmApplications');
        if (is_array($existingApps)) {
            foreach ($existingApps as $ea) {
                if (!is_array($ea)||($ea['session']??'')!==$this->session_year) continue;
                if (($ea['phone']??'')===$data['phone']&&in_array($ea['status']??'',['pending','approved','waitlisted','enrolled'])) {
                    return $this->json_error('An application with this phone number already exists for this session (ID: '.($ea['application_id']??'N/A').')');
                }
            }
        }

        $inq_id = $this->_crm_next_id('Inquiry', 'INQ', 5);
        $inqData = ['inquiry_id'=>$inq_id,'student_name'=>$data['student_name'],'parent_name'=>$data['parent_name'],'phone'=>$data['phone'],'email'=>$data['email'],'class'=>$data['class'],'source'=>'Online Form','status'=>'converted','session'=>$this->session_year,'created_at'=>$now,'updated_at'=>$now,'created_by'=>'Online'];
        $this->_crm_set('crmInquiries', $inq_id, $inqData);
        $app_id = $this->_crm_next_id('Application', 'APP', 5);
        $appData = array_merge($data, ['application_id'=>$app_id,'inquiry_id'=>$inq_id,'session'=>$this->session_year,'status'=>'pending','stage'=>'document_collection','created_at'=>$now,'updated_at'=>$now,'created_by'=>'Online','documents'=>[],'history'=>[['action'=>'Application submitted via online form','by'=>'Online','timestamp'=>$now]]]);
        $this->_crm_set("crmApplications", $app_id, $appData);
        $this->_crm_update("crmInquiries", $inq_id, ['application_id'=>$app_id]);
        // Counter managed by _crm_next_id — no separate write needed.
        // Firestore dual-write: online form inquiry + application
        try {
            $inqData['application_id'] = $app_id;
            $this->fs->setEntity('crmInquiries', $inq_id, $inqData);
            $this->fs->setEntity('crmApplications', $app_id, $appData);
        } catch (\Exception $e) { log_message('error', "Firestore dual-write submit_online_form failed: " . $e->getMessage()); }
        return $this->json_success(['application_id' => $app_id]);
    }

    public function fetch_analytics()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $inquiries    = $this->_crm_list('crmInquiries');
        $applications = $this->_crm_list('crmApplications');
        $waitlist     = $this->_crm_list('crmWaitlist');
        if (!is_array($inquiries)) $inquiries=[]; if (!is_array($applications)) $applications=[]; if (!is_array($waitlist)) $waitlist=[];
        $session = $this->session_year;
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
}
