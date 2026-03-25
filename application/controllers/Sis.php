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
        $this->crm_base = "Schools/{$this->school_name}/CRM/Admissions";
        $this->load->helper('notification');

        // Fee lifecycle & defaulter check libraries
        $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
        $this->load->library('Fee_defaulter_check', null, 'feeDefaulter');
        $this->feeLifecycle->init($this->firebase, $this->school_name, $this->session_year, $this->admin_id ?? 'system');
        $this->feeDefaulter->init($this->firebase, $this->school_name, $this->session_year);
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

        // Read lightweight index instead of full Users/Parents tree (OPT 1)
        $index = $this->firebase->get("Schools/{$school_name}/SIS/Students_Index") ?? [];
        if (!is_array($index)) $index = [];

        // Auto-build index if empty (first visit or after data migration)
        if (empty($index)) {
            $index = $this->_build_index_from_parents($school_id, $school_name);
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

        // Recent promotions
        $promotions = $this->firebase->get("Schools/{$school_name}/SIS/Promotions") ?? [];
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
        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        // Build class/section structure for filter dropdowns
        $classMap = [];
        $sessionClassKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session}");
        if (is_array($sessionClassKeys)) {
            foreach ($sessionClassKeys as $classKey) {
                if (strpos($classKey, 'Class ') !== 0) continue;
                $sectionKeys = $this->firebase->shallow_get(
                    "Schools/{$school_name}/{$session}/{$classKey}"
                );
                $sections = [];
                if (is_array($sectionKeys)) {
                    foreach ($sectionKeys as $sk) {
                        if (strpos($sk, 'Section ') === 0) {
                            $sections[] = str_replace('Section ', '', $sk);
                        }
                    }
                }
                $ordinal = str_replace('Class ', '', $classKey);
                $classMap[$ordinal] = $sections;
            }
        }

        $data['class_map']    = $classMap;
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

        // Build class/section map for dropdowns
        $classMap = [];
        $sessionClassKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session}");
        if (is_array($sessionClassKeys)) {
            foreach ($sessionClassKeys as $classKey) {
                if (strpos($classKey, 'Class ') !== 0) continue;
                $sectionKeys = $this->firebase->shallow_get(
                    "Schools/{$school_name}/{$session}/{$classKey}"
                );
                $sections = [];
                if (is_array($sectionKeys)) {
                    foreach ($sectionKeys as $sk) {
                        if (strpos($sk, 'Section ') === 0) {
                            $sections[] = str_replace('Section ', '', $sk);
                        }
                    }
                }
                $ordinal = str_replace('Class ', '', $classKey);
                $classMap[$ordinal] = $sections;
            }
        }

        // Preview next student ID (read-only — does NOT increment counter)
        $userId = $this->_peekNextStudentId($school_id);

        // Fee structure for exemptions
        $feesStructurePath = "Schools/{$school_name}/{$session}/Accounts/Fees/Fees Structure";
        $exemptedFees = $this->firebase->get($feesStructurePath);

        $data['class_map']     = $classMap;
        $data['session_year']  = $session;
        $data['school_name']   = $school_name;
        $data['user_Id']       = $userId;
        $data['exemptedFees']  = $exemptedFees;

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
        $classOrd    = trim($this->input->post('class')         ?? '');   // "9th"
        $section     = trim($this->input->post('section')       ?? '');   // "A"
        $phone       = trim($this->input->post('phone_number')  ?? $this->input->post('phone') ?? '');
        $email       = trim($this->input->post('email')         ?? '');
        $rollNo      = trim($this->input->post('roll_no')       ?? '');

        // M-07 FIX: Validate email format before storing
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json_error('Invalid email address format.');
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
        $existing = $this->firebase->get("Users/Parents/{$school_id}/{$userId}");
        if (!empty($existing)) {
            return $this->json_error("Student ID {$userId} already exists.");
        }

        // ── Password — same generation method as Student.php ────────
        $password = $this->_generatePassword($name, $dob);

        // ── Photo & Document uploads ────────────────────────────────
        $classKeyForPath = "Class {$classOrd}";
        $combinedClassPath = "{$classKeyForPath}/Section {$section}";

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

        // Save profile
        $this->firebase->set("Users/Parents/{$school_id}/{$userId}", $studentData);

        // Enroll in session roster
        $classKey   = "Class {$classOrd}";
        $sectionKey = "Section {$section}";
        $rosterPath = "Schools/{$school_name}/{$session}/{$classKey}/{$sectionKey}/Students/List/{$userId}";
        $this->firebase->set($rosterPath, $name);

        // Phone → school mapping (matches Student.php)
        if (!empty($phone)) {
            // Tenant-scoped phone index (primary)
            $this->firebase->update("Schools/{$school_name}/Phone_Index", [$phone => $userId]);
            // Legacy global indexes — kept for mobile app backward compatibility
            $this->firebase->update("Exits", [$phone => $school_id]);
            $this->firebase->update("User_ids_pno", [$phone => $userId]);
        }

        // Update Students_Index (OPT 1)
        $this->_update_student_index($school_name, $userId, $name, $classOrd, $section, 'Active', $gender);

        // ── B-A4 FIX: Initialize fee tracking for new student (matches legacy Student.php path) ──
        try {
            $feeClassKey = "{$classOrd} '{$section}'";
            $feeStructurePath = "Schools/{$school_name}/{$session}/Accounts/Fees/Classes Fees/{$feeClassKey}";
            $classFees = $this->firebase->get($feeStructurePath);

            // Initialize Month Fee markers as unpaid (0) for all months
            $studentFeePath = "Schools/{$school_name}/{$session}/{$classKey}/{$sectionKey}/Students/{$userId}";
            $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
            $monthFeeInit = [];
            foreach ($months as $m) {
                $monthFeeInit[$m] = 0;
            }
            $this->firebase->set("{$studentFeePath}/Month Fee", $monthFeeInit);
        } catch (Exception $e) {
            log_message('error', "SIS admit fee init failed for {$userId}: " . $e->getMessage());
        }

        // Auto-assign class fees for new admission
        try {
            $parentDbKey = $school_id;
            $this->feeLifecycle->assignInitialFees($userId, $classOrd, $section, $parentDbKey);
            log_message('info', "Fee_lifecycle: auto-assigned fees for new admission {$userId}");
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::assignInitialFees failed for {$userId}: " . $e->getMessage());
        }

        // Log history
        $this->_log_history($school_id, $userId, 'ADMISSION',
            "Student admitted to Class {$classOrd} / Section {$section} ({$session})",
            ['class' => $classOrd, 'section' => $section, 'session' => $session]
        );

        log_audit('SIS', 'admit_student', $userId, "Admitted student '{$name}' to Class {$classOrd} Section {$section}");

        // ── Subject assignment (merged from studentAdmission) ──────────
        $classNumber = 0;
        if (preg_match('/\d+/', $classOrd, $classMatch)) {
            $classNumber = (int)$classMatch[0];
        }
        if ($classNumber > 0) {
            $rawList = $this->firebase->get("Schools/{$school_name}/Subject_list/{$classNumber}");
            $coreSubjects = [];
            $allSubjects  = [];
            if (is_array($rawList)) {
                foreach ($rawList as $code => $item) {
                    if (!is_array($item)) continue;
                    $subName = trim($item['subject_name'] ?? $item['name'] ?? '');
                    if ($subName === '') continue;
                    $type = strtolower(trim($item['category'] ?? ''));
                    if ($type !== 'additional') $allSubjects[(string)$code] = $subName;
                    if ($type === 'core') $coreSubjects[(string)$code] = ['name' => $subName, 'type' => 'core'];
                }
            }
            if (!empty($allSubjects)) {
                $this->firebase->set("Schools/{$school_name}/{$session}/{$classKey}/All Subjects", $allSubjects);
            }
            if (!empty($coreSubjects)) {
                $this->firebase->set("Users/Parents/{$school_id}/{$userId}/Subjects", $coreSubjects);
            }
        }

        // Additional subjects selected by user
        $additionalSubjects = $this->input->post('additional_subjects');
        if (!empty($additionalSubjects) && is_array($additionalSubjects)) {
            $addSubData = [];
            foreach ($additionalSubjects as $sub) {
                $sub = trim($sub);
                if ($sub !== '') $addSubData[$sub] = '';
            }
            if (!empty($addSubData)) {
                $this->firebase->set(
                    "Schools/{$school_name}/{$session}/{$classKey}/{$sectionKey}/Students/{$userId}/Additional Subjects",
                    $addSubData
                );
            }
        }

        // Exempted fees selected by user
        $exemptedFees = $this->input->post('exempted_fees_multiple');
        if (!empty($exemptedFees) && is_array($exemptedFees)) {
            $exemptedData = [];
            foreach ($exemptedFees as $feeName) {
                $feeName = trim($feeName);
                if ($feeName !== '') $exemptedData[$feeName] = '';
            }
            if (!empty($exemptedData)) {
                $this->firebase->set(
                    "Schools/{$school_name}/{$session}/{$classKey}/{$sectionKey}/Students/{$userId}/Exempted Fees",
                    $exemptedData
                );
            }
        }

        // ── Sync student credentials to MongoDB (with retry) ──────────
        try {
            $this->load->library('auth_client');
            $syncPayload = [
                'studentId'          => $userId,
                'name'               => $name,
                'email'              => $email,
                'phone'              => $phone,
                'password'           => $password,        // Raw — Node.js will bcrypt it
                'schoolId'           => $this->school_code,   // Login code (e.g. 10005)
                'schoolCode'         => $this->school_name,   // Firebase key (e.g. SCH_XXXXXX)
                'parentDbKey'        => $this->parent_db_key, // Firebase Users/Parents key
                'parentPhone'        => $guardContact ?: $phone,
                'createdBy'          => $this->session->userdata('admin_id') ?: 'system',
                'className'          => $classOrd,
                'section'            => $section,
                'rollNo'             => $rollNo,
                'fatherName'         => $father,
                'motherName'         => $mother,
                'dob'                => $dob,
                'admissionDate'      => $admDate,
                'gender'             => $gender,
                'profilePic'         => $profilePicUrl,
                'schoolDisplayName'  => $this->school_display_name ?? '',
                'deviceBindingMethod'=> 'otp',  // Mobile login requires OTP to bind new devices
            ];

            $syncResult = null;
            $maxAttempts = 2;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $syncResult = $this->auth_client->sync_student($syncPayload);
                if (!empty($syncResult['success'])) break;
                if ($attempt < $maxAttempts) usleep(500000); // 500ms before retry
            }

            if (empty($syncResult['success'])) {
                log_message('error', "SIS MongoDB sync failed for {$userId} after {$maxAttempts} attempts: "
                    . ($syncResult['message'] ?? 'unknown')
                    . ' | payload=' . json_encode(['studentId' => $userId, 'schoolId' => $this->school_code, 'schoolCode' => $this->school_name]));
            }
        } catch (Exception $e) {
            log_message('error', "SIS MongoDB sync exception for {$userId}: " . $e->getMessage());
        }

        // LEAD SYSTEM — mark lead as admitted if this admission came from a lead
        $leadId = trim($this->input->post('lead_id') ?? '');
        if ($leadId !== '' && preg_match('/^[A-Za-z0-9_]+$/', $leadId)) {
            $now = date('Y-m-d H:i:s');
            $leadPath = "{$this->crm_base}/Applications/{$leadId}";
            $lead = $this->firebase->get($leadPath);
            if (is_array($lead)) {
                $history = $lead['history'] ?? [];
                $history[] = [
                    'action'    => "Converted to student {$userId}",
                    'by'        => $this->admin_name,
                    'timestamp' => $now,
                ];
                $this->firebase->update($leadPath, [
                    'status'     => 'admitted',
                    'stage'      => 'enrolled',
                    'student_id' => $userId,
                    'updated_at' => $now,
                    'history'    => $history,
                ]);
                log_audit('CRM', 'convert_lead', $leadId, "Lead converted to student {$userId}");
            }
        }

        // LEAD SYSTEM — notify parent of confirmed admission (fire-and-forget)
        if ($phone !== '') {
            notify_admission_confirmed($phone, $this->school_display_name ?? $this->school_name, $userId, $name);
        }

        return $this->json_success(['message' => 'Student admitted successfully.', 'user_id' => $userId]);
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

        // M-07 FIX: Validate email format on profile update
        if (isset($updates['Email']) && $updates['Email'] !== '' && !filter_var($updates['Email'], FILTER_VALIDATE_EMAIL)) {
            return $this->json_error('Invalid email address format.');
        }

        // Address is a nested object — posted as Address[Street], Address[City], etc.
        $addrPost = $this->input->post('Address');
        if (is_array($addrPost)) {
            $existing = $this->firebase->get("Users/Parents/{$school_id}/{$userId}/Address") ?? [];
            $existing = is_array($existing) ? $existing : [];
            $merged   = $existing;
            foreach (['Street', 'City', 'State', 'PostalCode'] as $sub) {
                if (isset($addrPost[$sub])) {
                    $merged[$sub] = trim($addrPost[$sub]);
                }
            }
            $updates['Address'] = $merged;
        }

        if (empty($updates)) {
            return $this->json_error('No valid fields to update.');
        }

        $this->firebase->update("Users/Parents/{$school_id}/{$userId}", $updates);

        // Sync Students_Index if name changed (OPT 1)
        if (isset($updates['Name'])) {
            $this->firebase->update(
                "Schools/{$this->school_name}/SIS/Students_Index/{$userId}",
                ['name' => $updates['Name']]
            );
        }

        $changed = implode(', ', array_keys($updates));
        $this->_log_history($school_id, $userId, 'PROFILE_UPDATE',
            "Profile updated: {$changed}", $updates
        );

        log_audit('SIS', 'update_profile', $userId, "Updated student profile: {$changed}");

        return $this->json_success(['message' => 'Profile updated successfully.']);
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT PROMOTION
    ══════════════════════════════════════════════════════════════════════ */

    public function promote()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_promote');
        $school_name = $this->school_name;
        $session     = $this->session_year;

        $classMap = [];
        $sessionClassKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session}");
        if (is_array($sessionClassKeys)) {
            foreach ($sessionClassKeys as $classKey) {
                if (strpos($classKey, 'Class ') !== 0) continue;
                $sectionKeys = $this->firebase->shallow_get(
                    "Schools/{$school_name}/{$session}/{$classKey}"
                );
                $sections = [];
                if (is_array($sectionKeys)) {
                    foreach ($sectionKeys as $sk) {
                        if (strpos($sk, 'Section ') === 0) {
                            $sections[] = str_replace('Section ', '', $sk);
                        }
                    }
                }
                $ordinal = str_replace('Class ', '', $classKey);
                $classMap[$ordinal] = $sections;
            }
        }

        $data['class_map']    = $classMap;
        $data['session_year'] = $session;

        // Build session options: available sessions + computed next session
        $available = $this->session->userdata('available_sessions') ?? [];
        $parts     = explode('-', $session);
        $nextYear  = ((int)$parts[0] + 1) . '-' . substr((string)((int)$parts[0] + 2), -2);
        if (!in_array($nextYear, $available, true)) {
            $available[] = $nextYear;
        }
        rsort($available);
        $data['session_options'] = $available;
        $data['next_session']    = $nextYear;

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

        // Validate toSession format (YYYY-YY) — reject arbitrary strings
        if (!preg_match('/^\d{4}-\d{2}$/', $toSession)) {
            $toSession = $session;
        }

        // Auto-register target session in Sessions list if it doesn't exist yet.
        // Promotion often targets the NEXT academic year before it's formally created.
        $available = $this->session->userdata('available_sessions') ?? [];
        if (!in_array($toSession, $available, true)) {
            $available[] = $toSession;
            rsort($available);
            $this->firebase->set("Schools/{$school_name}/Sessions", $available);
            $this->session->set_userdata('available_sessions', $available);
        }

        $students = $this->_get_students_in_class($fromClass, $fromSection, $session);
        if (empty($students)) {
            return $this->json_error('No students found in the selected class/section.');
        }

        // PROM-2 FIX: Check target section capacity before promotion
        $newClassKey   = "Class {$toClass}";
        $newSectionKey = "Section {$toSection}";
        $maxStrength = $this->firebase->get(
            "Schools/{$school_name}/{$toSession}/{$newClassKey}/{$newSectionKey}/max_strength"
        );
        if ($maxStrength) {
            $existingStudents = $this->firebase->get(
                "Schools/{$school_name}/{$toSession}/{$newClassKey}/{$newSectionKey}/Students/List"
            ) ?? [];
            $currentCount = is_array($existingStudents) ? count($existingStudents) : 0;
            $promotionCount = count($students);
            if (($currentCount + $promotionCount) > (int) $maxStrength) {
                return $this->json_error(
                    "Target section {$toClass}/{$toSection} capacity exceeded ({$currentCount}/{$maxStrength}). Cannot promote {$promotionCount} student(s)."
                );
            }
        }

        $adminName  = $this->session->userdata('admin_name') ?? 'Admin';
        $promoted   = [];
        $now        = date('Y-m-d H:i:s');
        $batchId    = date('YmdHis');
        $oldClassKey   = "Class {$fromClass}";
        $historyDesc   = "Promoted from Class {$fromClass}/{$fromSection} to Class {$toClass}/{$toSection} ({$toSession})";
        $historyMeta   = [
            'from_class' => $fromClass, 'from_section' => $fromSection,
            'to_class' => $toClass, 'to_section' => $toSection, 'to_session' => $toSession,
        ];

        // OPT 2: Build single multi-path update instead of N × 5 individual operations
        $batchUpdates = [];
        $counter      = 0;

        foreach ($students as $userId => $studentInfo) {
            $name = $studentInfo['name'] ?? $userId;
            $counter++;

            // Update profile class/section
            $batchUpdates["Users/Parents/{$school_id}/{$userId}/Class"]   = $toClass;
            $batchUpdates["Users/Parents/{$school_id}/{$userId}/Section"] = $toSection;

            // Remove from old roster (null = delete)
            $actualSection = ($fromSection === 'all')
                ? ($studentInfo['section'] ?? '')
                : $fromSection;
            if (!empty($actualSection)) {
                $batchUpdates["Schools/{$school_name}/{$session}/{$oldClassKey}/Section {$actualSection}/Students/List/{$userId}"] = null;
            }

            // Add to new roster
            $batchUpdates["Schools/{$school_name}/{$toSession}/{$newClassKey}/{$newSectionKey}/Students/List/{$userId}"] = $name;

            // Update Students_Index (OPT 1)
            $batchUpdates["Schools/{$school_name}/SIS/Students_Index/{$userId}/class"]   = $toClass;
            $batchUpdates["Schools/{$school_name}/SIS/Students_Index/{$userId}/section"] = $toSection;

            // History entry (generate key client-side for batch inclusion)
            $histKey = $batchId . '_' . str_pad($counter, 4, '0', STR_PAD_LEFT);
            $batchUpdates["Users/Parents/{$school_id}/{$userId}/History/{$histKey}"] = [
                'action'      => 'PROMOTION',
                'description' => $historyDesc,
                'changed_by'  => $adminName,
                'changed_at'  => $now,
                'metadata'    => $historyMeta,
            ];

            $promoted[] = ['user_id' => $userId, 'name' => $name];
        }

        // Promotion batch record
        $batchUpdates["Schools/{$school_name}/SIS/Promotions/{$batchId}"] = [
            'session_from' => $session,
            'session_to'   => $toSession,
            'promoted_at'  => $now,
            'promoted_by'  => $adminName,
            'from_class'   => $fromClass,
            'from_section' => $fromSection,
            'to_class'     => $toClass,
            'to_section'   => $toSection,
            'students'     => $promoted,
            'count'        => count($promoted),
        ];

        // Single atomic write for entire promotion batch
        $this->firebase->update("", $batchUpdates);

        // ── PROM-1 FIX: Initialize fee structure & subjects in new session for promoted students ──
        try {
            // Copy fee structure from old session/class to new session/class if not already present
            $oldFeeClassKey = "{$fromClass} '{$fromSection}'";
            $newFeeClassKey = "{$toClass} '{$toSection}'";
            $oldFeePath = "Schools/{$school_name}/{$session}/Accounts/Fees/Classes Fees/{$oldFeeClassKey}";
            $newFeePath = "Schools/{$school_name}/{$toSession}/Accounts/Fees/Classes Fees/{$newFeeClassKey}";
            $existingNewFees = $this->firebase->get($newFeePath);
            if (empty($existingNewFees)) {
                $oldFees = $this->firebase->get($oldFeePath);
                if (is_array($oldFees) && !empty($oldFees)) {
                    $this->firebase->set($newFeePath, $oldFees);
                }
            }

            // Subjects are at Schools/{school}/Subject_list/ — school-wide, session-independent.
            // No copy needed for subjects — they persist across sessions.

            // Initialize empty attendance node for new session class/section
            $attendancePath = "Schools/{$school_name}/{$toSession}/{$newClassKey}/{$newSectionKey}/Attendance";
            $existingAtt = $this->firebase->get($attendancePath);
            if (empty($existingAtt)) {
                $this->firebase->set($attendancePath, ['_initialized' => date('c')]);
            }
        } catch (Exception $e) {
            log_message('error', "Promotion session init failed: " . $e->getMessage());
            // Non-fatal: promotion itself succeeded, initialization is best-effort
        }

        // Reassign fees for promoted students
        foreach ($promoted as $p) {
            try {
                $this->feeLifecycle->reassignFeesOnPromotion(
                    $p['user_id'], $fromClass, $fromSection, $toClass, $toSection, $school_id
                );
            } catch (Exception $e) {
                log_message('error', "Fee_lifecycle::reassignFeesOnPromotion failed for {$p['user_id']}: " . $e->getMessage());
            }
        }

        return $this->json_success([
            'message'  => count($promoted) . ' student(s) promoted successfully.',
            'promoted' => $promoted,
            'skipped'  => [],
            'batch_id' => $batchId,
        ]);
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

        // Primary: read from TC index (O(1), populated by issue_tc/cancel_tc)
        $tcIndex = $this->firebase->get("Schools/{$school_name}/SIS/TC_Index") ?? [];
        if (!is_array($tcIndex)) $tcIndex = [];

        $tcRecords = [];
        if (!empty($tcIndex)) {
            // Fast path: build records directly from index
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
            // Fallback: full student scan (backward-compat with data issued before index existed)
            $allStudents = $this->firebase->get("Users/Parents/{$school_id}") ?? [];
            if (!is_array($allStudents)) $allStudents = [];
            foreach (['Count', 'TC Students', ''] as $k) unset($allStudents[$k]);

            foreach ($allStudents as $uid => $student) {
                if (!is_array($student)) continue;
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

        $student = $this->firebase->get("Users/Parents/{$school_id}/{$userId}");
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
        $adminName = $this->session->userdata('admin_name') ?? 'Admin';
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
        ];

        // Save TC under student profile
        $tcKey = $this->firebase->push("Users/Parents/{$school_id}/{$userId}/TC", $tcData);

        // Mark student status as TC
        $this->firebase->update("Users/Parents/{$school_id}/{$userId}", ['Status' => 'TC']);

        // Sync Students_Index (OPT 1)
        $this->firebase->update(
            "Schools/{$school_name}/SIS/Students_Index/{$userId}",
            ['status' => 'TC']
        );

        // Remove from current session roster (mirrors withdraw_student behaviour)
        $session    = $this->session_year;
        $classOrd   = $student['Class']   ?? '';
        $sectionLtr = $student['Section'] ?? '';
        if (!empty($classOrd) && !empty($sectionLtr)) {
            $rosterBase = "Schools/{$school_name}/{$session}/Class {$classOrd}/Section {$sectionLtr}/Students";
            // Primary roster path
            $this->firebase->delete("{$rosterBase}/List", $userId);
            // Legacy flat path
            $this->firebase->delete($rosterBase, $userId);
        }

        // Write to TC index for O(1) listing (avoids full student scan in tc_list)
        $this->firebase->set("Schools/{$school_name}/SIS/TC_Index/{$tcKey}", array_merge($tcData, [
            'user_id' => $userId,
            'tc_key'  => $tcKey,
        ]));

        // Log history
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

        // Fetch student profile — retry once on failure (Firebase connectivity is intermittent)
        $student = $this->firebase->get("Users/Parents/{$school_id}/{$userId}");
        if ($student === null || $student === false) {
            usleep(300000); // 300ms
            $student = $this->firebase->get("Users/Parents/{$school_id}/{$userId}");
        }
        if (empty($student)) {
            log_message('error', "print_tc: student not found — school_id={$school_id} userId={$userId}");

            // If Firebase is intermittently down, try reading TC data from the TC_Index as fallback
            if ($tcKey) {
                $indexTc = $this->firebase->get("Schools/{$school_name}/SIS/TC_Index/{$tcKey}");
                if (!empty($indexTc) && is_array($indexTc)) {
                    // Build minimal student data from the TC_Index entry
                    $student = [
                        'User Id' => $indexTc['user_id'] ?? $userId,
                        'Name'    => $indexTc['student_name'] ?? $indexTc['name'] ?? $userId,
                        'Class'   => $indexTc['class'] ?? '',
                        'Section' => $indexTc['section'] ?? '',
                    ];
                    $tc = $indexTc;
                }
            }
            if (empty($student)) show_404();
        }

        if (!isset($tc)) {
            $tc = null;
            if ($tcKey) {
                $tc = $this->firebase->get("Users/Parents/{$school_id}/{$userId}/TC/{$tcKey}");
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
        }

        if (empty($tc)) {
            log_message('error', "print_tc: TC not found — school_id={$school_id} userId={$userId} tcKey={$tcKey}");
            show_404();
        }

        // School profile for header
        $schoolProfile = $this->firebase->get("System/Schools/{$school_name}/profile") ?? [];

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

        $cancelled = ['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')];
        $this->firebase->update("Users/Parents/{$school_id}/{$userId}/TC/{$tcKey}", $cancelled);

        // Mirror cancellation to TC index
        $this->firebase->update("Schools/{$school_name}/SIS/TC_Index/{$tcKey}", $cancelled);

        $this->firebase->update("Users/Parents/{$school_id}/{$userId}", ['Status' => 'Active']);

        // Sync Students_Index (OPT 1)
        $this->firebase->update(
            "Schools/{$school_name}/SIS/Students_Index/{$userId}",
            ['status' => 'Active']
        );

        // Re-add student to session roster (reverse of issue_tc roster removal)
        $student = $this->firebase->get("Users/Parents/{$school_id}/{$userId}");
        if (!empty($student) && is_array($student)) {
            $classOrd   = $student['Class']   ?? '';
            $sectionLtr = $student['Section'] ?? '';
            $session    = $this->session_year;
            if (!empty($classOrd) && !empty($sectionLtr)) {
                $rosterBase = "Schools/{$school_name}/{$session}/Class {$classOrd}/Section {$sectionLtr}/Students";
                $rosterName = $student['Name'] ?? $userId;
                // Primary roster path — flat string value (consistent with all other roster writes)
                $this->firebase->set("{$rosterBase}/List/{$userId}", $rosterName);
            }
        }

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

        $student = $this->firebase->get("Users/Parents/{$school_id}/{$userId}");
        if (empty($student) || !is_array($student)) {
            return $this->json_error('Student not found.');
        }

        if (($student['Status'] ?? '') === 'Inactive') {
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

        // Mark as Inactive
        $this->firebase->update("Users/Parents/{$school_id}/{$userId}", ['Status' => 'Inactive']);

        // Freeze fee records for withdrawn student
        try {
            $this->feeLifecycle->freezeFeesOnSoftDelete($userId);
            log_message('info', "Fee_lifecycle: froze fees for withdrawn student {$userId}");
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::freezeFeesOnSoftDelete failed for {$userId}: " . $e->getMessage());
        }

        // Sync Students_Index (OPT 1)
        $this->firebase->update(
            "Schools/{$school_name}/SIS/Students_Index/{$userId}",
            ['status' => 'Inactive']
        );

        // Remove from current session roster
        $classOrd   = $student['Class']   ?? '';
        $sectionLtr = $student['Section'] ?? '';
        if (!empty($classOrd) && !empty($sectionLtr)) {
            $this->firebase->delete(
                "Schools/{$school_name}/{$session}/Class {$classOrd}/Section {$sectionLtr}/Students/List",
                $userId
            );
        }

        $this->_log_history($school_id, $userId, 'WITHDRAWAL',
            "Student withdrawn: {$reason}",
            ['reason' => $reason, 'session' => $session, 'class' => $classOrd, 'section' => $sectionLtr]
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

        $student = $this->firebase->get("Users/Parents/{$school_id}/{$userId}");
        if (empty($student)) return $this->json_error('Student not found.');

        $this->firebase->update("Users/Parents/{$school_id}/{$userId}", ['Status' => $newStatus]);

        // Sync Students_Index (OPT 1)
        $this->firebase->update(
            "Schools/{$this->school_name}/SIS/Students_Index/{$userId}",
            ['status' => $newStatus]
        );

        $this->_log_history($school_id, $userId, 'STATUS_CHANGE',
            "Status changed to {$newStatus}", ['status' => $newStatus]
        );

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
        $student   = $this->firebase->get("Users/Parents/{$school_id}/{$userId}");
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
        // ── Fix 2: File size limit (5 MB) ─────────────────────────────────
        if ($_FILES['document']['size'] > 5 * 1024 * 1024) {
            return $this->json_error('File too large. Maximum allowed size is 5 MB.');
        }
        if ($_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            return $this->json_error('File upload error (code ' . $_FILES['document']['error'] . ').');
        }

        $storagePath = "Students/{$school_id}/{$userId}/docs/{$docLabel}";
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

            $this->firebase->update(
                "Users/Parents/{$school_id}/{$userId}/Doc",
                [$docLabel => ['url' => $url, 'thumbnail' => $thumbUrl,
                               'uploaded_at' => date('Y-m-d H:i:s')]]
            );

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

        $this->firebase->delete("Users/Parents/{$school_id}/{$userId}/Doc", $docLabel);

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

        $school_id = $this->parent_db_key;
        $student   = $this->firebase->get("Users/Parents/{$school_id}/{$userId}");
        if (empty($student)) show_404();

        $history = $this->firebase->get("Users/Parents/{$school_id}/{$userId}/History") ?? [];
        if (!is_array($history)) $history = [];

        uasort($history, fn($a, $b) =>
            strcmp($b['changed_at'] ?? '', $a['changed_at'] ?? '')
        );

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

        $allStudents = $this->CM->select_data('Users/Parents/' . $school_id);
        if (!is_array($allStudents)) $allStudents = [];
        foreach (['Count', 'TC Students', ''] as $k) unset($allStudents[$k]);
        $allStudents = array_filter($allStudents, function ($s) {
            return is_array($s) && !empty($s['User Id']);
        });

        $enrolledIds = [];
        if (!empty($school_name)) {
            $sessionClassKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session_year}");
            if (!is_array($sessionClassKeys)) $sessionClassKeys = [];
            foreach ($sessionClassKeys as $classKey) {
                if (strpos($classKey, 'Class ') !== 0) continue;
                $sectionKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session_year}/{$classKey}");
                if (!is_array($sectionKeys)) continue;
                foreach ($sectionKeys as $sectionKey) {
                    if (strpos($sectionKey, 'Section ') !== 0) continue;
                    $list = $this->CM->select_data("Schools/{$school_name}/{$session_year}/{$classKey}/{$sectionKey}/Students/List");
                    if (is_array($list)) {
                        foreach (array_keys($list) as $sid) $enrolledIds[$sid] = true;
                    }
                }
            }
        }

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
        $profile = $this->firebase->get("Schools/{$school_name}/Config/Profile");
        if (!is_array($profile)) $profile = [];

        $data['students']       = $students;
        $data['session_year']   = $session_year;
        $data['school_name']    = $school_name;
        $data['school_profile'] = [
            'school_name' => $profile['display_name'] ?? $this->school_display_name,
            'address'     => $profile['address'] ?? '',
            'logo'        => $profile['logo'] ?? '',
            'phone'       => $profile['phone'] ?? '',
        ];

        $this->load->view('include/header');
        $this->load->view('sis/id_card', $data);
        $this->load->view('include/footer');
    }


    /**
     * One-time utility: rebuild the Students_Index from the full Users/Parents tree.
     * Call via GET: sis/rebuild_index — idempotent, safe to re-run.
     */
    public function rebuild_index()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_rebuild_index');
        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;

        $allStudents = $this->firebase->get("Users/Parents/{$school_id}") ?? [];
        if (!is_array($allStudents)) $allStudents = [];
        foreach (['Count', 'TC Students', ''] as $k) unset($allStudents[$k]);

        $index = [];
        foreach ($allStudents as $uid => $s) {
            if (!is_array($s) || empty($s['User Id'])) continue;
            $index[$uid] = [
                'name'    => $s['Name']    ?? '',
                'class'   => $s['Class']   ?? '',
                'section' => $s['Section'] ?? '',
                'status'  => $s['Status']  ?? 'Active',
                'gender'  => $s['Gender']  ?? '',
            ];
        }

        $this->firebase->set("Schools/{$school_name}/SIS/Students_Index", $index);

        return $this->json_success([
            'message' => 'Students_Index rebuilt.',
            'count'   => count($index),
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
        $page        = max(1, (int)($this->input->post('page') ?? 1));
        $perPage     = 30;

        // OPT 1: Read lightweight index instead of full Users/Parents tree
        $index = $this->firebase->get("Schools/{$school_name}/SIS/Students_Index") ?? [];
        if (!is_array($index)) $index = [];

        // Auto-build index if empty
        if (empty($index)) {
            $index = $this->_build_index_from_parents($school_id, $school_name);
        }

        $enrolledIds = $this->_get_enrolled_ids();

        // Filter using index fields (name, class, section, status) + userId
        $filtered = [];
        foreach ($index as $uid => $entry) {
            if (!is_array($entry)) continue;
            if (!isset($enrolledIds[$uid])) continue;
            if ($classFilter && ($entry['class'] ?? '') !== $classFilter) continue;
            if ($secFilter   && ($entry['section'] ?? '') !== $secFilter) continue;
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

        // Fetch full profiles only for the current page (max 30)
        $results = [];
        foreach ($pagedKeys as $uid) {
            $entry   = $filtered[$uid];
            $profile = $this->firebase->get("Users/Parents/{$school_id}/{$uid}");

            $photo = '';
            if (is_array($profile)) {
                if (!empty($profile['Profile Pic']) && is_string($profile['Profile Pic'])) {
                    $photo = $profile['Profile Pic'];
                } elseif (!empty($profile['Doc']['Photo'])) {
                    $p = $profile['Doc']['Photo'];
                    $photo = is_array($p) ? ($p['url'] ?? '') : (string)$p;
                }
            }

            $results[] = [
                'user_id'        => $uid,
                'name'           => $entry['name'] ?? '',
                'father_name'    => is_array($profile) ? ($profile['Father Name'] ?? '') : '',
                'class'          => $entry['class'] ?? '',
                'section'        => $entry['section'] ?? '',
                'phone'          => is_array($profile) ? ($profile['Phone Number'] ?? '') : '',
                'gender'         => is_array($profile) ? ($profile['Gender'] ?? '') : '',
                'admission_date' => is_array($profile) ? ($profile['Admission Date'] ?? '') : '',
                'dob'            => is_array($profile) ? ($profile['DOB'] ?? '') : '',
                'status'         => $entry['status'] ?? 'Active',
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

        $student = $this->firebase->get("Users/Parents/{$school_id}/{$userId}");
        if (empty($student)) return $this->json_error('Student not found.');

        return $this->json_success(['student' => $student]);
    }

    public function get_classes()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_get_classes');
        $school_name = $this->school_name;
        $session     = $this->session_year;

        $classKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session}");
        $classes   = [];
        if (is_array($classKeys)) {
            foreach ($classKeys as $k) {
                if (strpos($k, 'Class ') === 0) {
                    $classes[] = str_replace('Class ', '', $k);
                }
            }
        }

        return $this->json_success(['classes' => $classes]);
    }

    public function get_sections()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_get_sections');
        $school_name = $this->school_name;
        $session     = $this->session_year;
        $classOrd    = trim($this->input->get('class') ?? $this->input->post('class') ?? '');

        if (empty($classOrd)) return $this->json_error('Class required.');
        if (!$this->safe_path_segment($classOrd)) return $this->json_error('Invalid class value.');

        $classKey    = "Class {$classOrd}";
        $sectionKeys = $this->firebase->shallow_get(
            "Schools/{$school_name}/{$session}/{$classKey}"
        );

        $sections = [];
        if (is_array($sectionKeys)) {
            foreach ($sectionKeys as $sk) {
                if (strpos($sk, 'Section ') === 0) {
                    $sections[] = str_replace('Section ', '', $sk);
                }
            }
        }

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
        $allStudents = $this->firebase->get("Users/Parents/{$school_id}") ?? [];
        if (!is_array($allStudents)) return [];
        foreach (['Count', 'TC Students', ''] as $k) unset($allStudents[$k]);

        $index = [];
        foreach ($allStudents as $uid => $s) {
            if (!is_array($s) || empty($s['User Id'])) continue;
            $index[$uid] = [
                'name'    => $s['Name']    ?? '',
                'class'   => $s['Class']   ?? '',
                'section' => $s['Section'] ?? '',
                'status'  => $s['Status']  ?? 'Active',
                'gender'  => $s['Gender']  ?? '',
            ];
        }

        // Persist so subsequent requests use the fast index path
        if (!empty($index)) {
            $this->firebase->set("Schools/{$school_name}/SIS/Students_Index", $index);
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
    private function _uploadStudentFile($file, $schoolName, $combinedClassPath, $studentId, $folderLabel, $type = 'document')
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return false;

        $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed   = ($type === 'profile') ? ['jpg','jpeg','png','webp'] : ['jpg','jpeg','png','webp','pdf'];
        if (!in_array($ext, $allowed, true)) return false;
        if ($file['size'] > 5 * 1024 * 1024) return false;

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
        $this->firebase->set("Schools/{$schoolName}/SIS/Students_Index/{$userId}", [
            'name'    => $name,
            'class'   => $class,
            'section' => $section,
            'status'  => $status,
            'gender'  => $gender,
        ]);
    }

    /**
     * Append an entry to the student's History log.
     */
    private function _log_history(
        string $schoolId,
        string $userId,
        string $action,
        string $description,
        array  $metadata = []
    ): void {
        $adminName = $this->session->userdata('admin_name') ?? 'System';
        $entry = [
            'action'      => $action,
            'description' => $description,
            'changed_by'  => $adminName,
            'changed_at'  => date('Y-m-d H:i:s'),
            'metadata'    => $metadata,
        ];
        $this->firebase->push("Users/Parents/{$schoolId}/{$userId}/History", $entry);
    }

    /**
     * Preview the next student ID (read-only, does NOT increment).
     * Calls Auth API to peek at the next STU counter value.
     * Globally unique across all schools.
     */
    private function _peekNextStudentId(string $schoolId): string
    {
        try {
            $this->load->library('auth_client');
            $id = $this->auth_client->generate_id('STU_PEEK');
            if ($id) return $id;
        } catch (Exception $e) {
            log_message('error', 'peekNextStudentId Auth API failed: ' . $e->getMessage());
        }
        // Fallback: show placeholder if Auth API is down
        return 'STU****';
    }

    /**
     * Generate the next student ID via Auth API (atomic MongoDB counter).
     * Globally unique — no two students anywhere share the same ID.
     * Only call this when actually saving a student (not on page load).
     */
    private function _nextStudentId(string $schoolId): ?string
    {
        try {
            $this->load->library('auth_client');
            $userId = $this->auth_client->generate_id('STU');
            if ($userId) return $userId;
        } catch (Exception $e) {
            log_message('error', 'nextStudentId Auth API failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Get the next TC serial number and increment the counter.
     * Format: TC-{school_code}-{YYYY}-{0001}
     */
    private function _get_tc_number(string $schoolName): string
    {
        $counterPath = "Schools/{$schoolName}/SIS/TC_Counter";
        $current     = (int)($this->firebase->get($counterPath) ?? 0);
        $next        = $current + 1;
        $this->firebase->set($counterPath, $next);
        $year   = date('Y');
        $code   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($schoolName, 0, 6)));
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
        $classOrd    = trim($student['Class']   ?? '');
        $sectionLtr  = trim($student['Section'] ?? '');

        $result = ['has_dues' => false, 'total_due' => 0, 'unpaid_months' => [], 'summary' => ''];

        if ($classOrd === '' || $sectionLtr === '') return $result;

        // FIXED: use $sectionLtr directly (consistent with Fees.php and other SIS methods)
        $classKey    = "Class {$classOrd}";
        $sectionKey  = "Section {$sectionLtr}";

        // Read the student's month-wise payment status
        $studentFees = $this->firebase->get(
            "Schools/{$school_name}/{$session}/{$classKey}/{$sectionKey}/Students/{$userId}/Month Fee"
        );
        if (!is_array($studentFees)) $studentFees = [];

        // Read the class fee structure to calculate amounts
        $classFees = $this->firebase->get(
            "Schools/{$school_name}/{$session}/Accounts/Fees/Classes Fees/{$classKey}/{$sectionKey}"
        );
        if (!is_array($classFees)) {
            // FIXED: no fee chart configured yet — count unpaid months but can't calculate amount
            log_message('debug', "_check_outstanding_dues: no fee chart for {$classKey}/{$sectionKey}, userId={$userId}");
            $classFees = [];
        }

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
    private function _get_enrolled_ids(): array
    {
        $school_name = $this->school_name;
        $session     = $this->session_year;
        $enrolledIds = [];

        $sessionRoot = $this->firebase->get("Schools/{$school_name}/{$session}");
        if (!is_array($sessionRoot)) return $enrolledIds;

        foreach ($sessionRoot as $classKey => $classData) {
            if (strpos($classKey, 'Class ') !== 0 || !is_array($classData)) continue;
            foreach ($classData as $sectionKey => $sectionData) {
                if (strpos($sectionKey, 'Section ') !== 0 || !is_array($sectionData)) continue;
                $studentsNode = $sectionData['Students'] ?? [];
                if (!is_array($studentsNode)) continue;

                // New format: Students/List/{userId} = "Name"
                $list = $studentsNode['List'] ?? [];
                if (is_array($list) && !empty($list)) {
                    foreach (array_keys($list) as $sid) {
                        if (is_string($sid) && $sid !== '' && $sid !== 'Count') {
                            $enrolledIds[$sid] = true;
                        }
                    }
                } else {
                    // Legacy format: Students/{userId} = {Name: "..."}
                    foreach ($studentsNode as $sid => $data) {
                        if (!is_string($sid) || $sid === '' || $sid === 'List' || $sid === 'Count') continue;
                        $enrolledIds[$sid] = true;
                    }
                }
            }
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
        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $classKey    = "Class {$classOrd}";
        $students    = [];

        if ($section && $section !== 'all') {
            $students = $this->_fetch_section_students(
                $school_name, $session, $classKey, "Section {$section}", $classOrd, $section
            );
        } else {
            // All sections in this class
            $sectionKeys = $this->firebase->shallow_get(
                "Schools/{$school_name}/{$session}/{$classKey}"
            );
            if (is_array($sectionKeys)) {
                foreach ($sectionKeys as $sk) {
                    if (strpos($sk, 'Section ') !== 0) continue;
                    $sec = str_replace('Section ', '', $sk);
                    $sectionStudents = $this->_fetch_section_students(
                        $school_name, $session, $classKey, $sk, $classOrd, $sec
                    );
                    $students += $sectionStudents;
                }
            }
        }

        return $students;
    }

    /**
     * Fetch students from a specific section.
     * Checks Students/List first (new format: {userId: "Name"}).
     * Falls back to Students/ root (legacy format: {userId: {Name: "..."}}).
     */
    private function _fetch_section_students(
        string $school_name, string $session, string $classKey,
        string $sectionKey, string $classOrd, string $section
    ): array {
        $students = [];
        $basePath = "Schools/{$school_name}/{$session}/{$classKey}/{$sectionKey}/Students";

        // Try new format first: Students/List/{userId} = "Name"
        $list = $this->firebase->get("{$basePath}/List");
        if (is_array($list) && !empty($list)) {
            foreach ($list as $uid => $name) {
                if (!is_string($uid) || $uid === '' || $uid === 'Count') continue;
                $students[$uid] = [
                    'user_id' => $uid,
                    'name'    => is_string($name) ? $name : ($name['Name'] ?? $uid),
                    'class'   => $classOrd,
                    'section' => $section,
                ];
            }
            return $students;
        }

        // Fallback: legacy format — Students/{userId} = {Name: "...", ...}
        $allStudentData = $this->firebase->get($basePath);
        if (is_array($allStudentData)) {
            foreach ($allStudentData as $uid => $data) {
                // Skip non-student keys
                if (!is_string($uid) || $uid === '' || $uid === 'List' || $uid === 'Count') continue;
                if (is_array($data)) {
                    $name = $data['Name'] ?? $data['name'] ?? $uid;
                    $students[$uid] = [
                        'user_id' => $uid,
                        'name'    => $name,
                        'class'   => $classOrd,
                        'section' => $section,
                    ];
                } elseif (is_string($data)) {
                    // Could be flat: Students/{userId} = "Name"
                    $students[$uid] = [
                        'user_id' => $uid,
                        'name'    => $data,
                        'class'   => $classOrd,
                        'section' => $section,
                    ];
                }
            }
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

            // Load Auth API for globally unique student IDs
            $this->load->library('auth_client');

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

                $className = $classNumber . $suffix;
                $combinedClass = "Class {$className}/Section {$section}";

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
                    "Admission Date" => $formattedAdmDate, "Class" => $className, "Section" => $section,
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

                $this->firebase->update("Users/Parents/{$school_id}/{$studentId}", $studentData);
                $this->CM->addKey_pair_data("Schools/{$school_name}/{$session_year}/{$combinedClass}/Students/", [$studentId => ['Name' => $studentName]]);
                $this->CM->addKey_pair_data("Schools/{$school_name}/{$session_year}/{$combinedClass}/Students/List/", [$studentId => $studentName]);

                $phone = trim($rowData['Phone Number'] ?? '');
                if ($phone !== '') {
                    $this->CM->addKey_pair_data("Schools/{$school_name}/Phone_Index/", [$phone => $studentId]);
                    $this->CM->addKey_pair_data('Exits/', [$phone => $school_id]);
                    $this->CM->addKey_pair_data('User_ids_pno/', [$phone => $studentId]);
                }

                // Update Students_Index
                $this->_update_student_index($school_name, $studentId, $studentName, $className, $section, 'Active', trim($rowData['Gender'] ?? ''));

                // Initialize Month Fee markers as unpaid (0) for all 12 months
                try {
                    $classKey   = "Class {$className}";
                    $sectionKey = "Section {$section}";
                    $studentFeePath = "Schools/{$school_name}/{$session_year}/{$classKey}/{$sectionKey}/Students/{$studentId}";
                    $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
                    $monthFeeInit = [];
                    foreach ($months as $m) {
                        $monthFeeInit[$m] = 0;
                    }
                    $this->firebase->set("{$studentFeePath}/Month Fee", $monthFeeInit);
                } catch (Exception $e) {
                    log_message('error', "SIS import fee init failed for {$studentId}: " . $e->getMessage());
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
                    $rawList = $this->firebase->get("Schools/{$school_name}/Subject_list/{$classNumber}");
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
                    if (!empty($subjectCache[$classNumber]['allSubjects'])) {
                        $this->firebase->set("Schools/{$school_name}/{$session_year}/Class {$className}/All Subjects", $subjectCache[$classNumber]['allSubjects']);
                    }
                }

                if (!empty($subjectCache[$classNumber]['core'])) {
                    $this->firebase->set("Users/Parents/{$school_id}/{$studentId}/Subjects", $subjectCache[$classNumber]['core']);
                }
                if (!empty($subjectCache[$classNumber]['additionalSubjects'])) {
                    $this->firebase->set("Schools/{$school_name}/{$session_year}/{$combinedClass}/Students/{$studentId}/Additional Subjects", $subjectCache[$classNumber]['additionalSubjects']);
                }

                // Sync to MongoDB (best-effort, don't block import on failure)
                try {
                    $email   = trim($rowData['Email'] ?? '');
                    $phone   = trim($rowData['Phone Number'] ?? '');
                    $father  = trim($rowData['Father Name'] ?? '');
                    $mother  = trim($rowData['Mother Name'] ?? '');
                    $guardPh = trim($rowData['Guard Contact'] ?? '');
                    $this->auth_client->sync_student([
                        'studentId'          => $studentId,
                        'name'               => $studentName,
                        'email'              => $email,
                        'phone'              => $phone,
                        'password'           => $password,
                        'schoolId'           => $this->school_code,
                        'schoolCode'         => $this->school_name,
                        'parentDbKey'        => $this->parent_db_key,
                        'parentPhone'        => $guardPh ?: $phone,
                        'createdBy'          => $this->session->userdata('admin_id') ?: 'system',
                        'className'          => $className,
                        'section'            => $section,
                        'rollNo'             => trim($rowData['Roll No'] ?? ''),
                        'fatherName'         => $father,
                        'motherName'         => $mother,
                        'dob'                => $formattedDOB,
                        'admissionDate'      => $formattedAdmDate,
                        'gender'             => trim($rowData['Gender'] ?? ''),
                        'profilePic'         => '',
                        'schoolDisplayName'  => $this->school_display_name ?? '',
                        'deviceBindingMethod'=> 'otp',
                    ]);
                } catch (Exception $e) {
                    log_message('error', "SIS import MongoDB sync failed for {$studentId}: " . $e->getMessage());
                }

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
        $keys = $this->firebase->shallow_get("Schools/{$school_name}/{$session_year}/{$className}");
        // FIXED: shallow_get can return null/false if path doesn't exist — guard against foreach on non-array
        if (!is_array($keys)) $keys = [];
        $sections = [];
        foreach ($keys as $key) {
            if (strpos($key, 'Section ') === 0) $sections[] = str_replace('Section ', '', $key);
        }
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
        $subjectData = $this->CM->get_data("Schools/{$school_name}/Subject_list/{$classKey}");
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
        $studentPath = "Users/Parents/{$school_id}/{$userId}";
        $existing    = $this->CM->select_data($studentPath);
        if (!$existing) { show_404(); return; }

        $classKey          = trim($existing['Class']);
        $sectionKey        = trim($existing['Section']);
        $combinedClassPath = "Class {$classKey}/Section {$sectionKey}";

        if ($this->input->method() !== 'post') {
            $additionalPath = "Schools/$school_name/$session_year/$combinedClassPath/Students/$userId/Additional Subjects";
            $data['additional_subjects'] = $this->firebase->get($additionalPath) ?? [];
            $exemptedPath = "Schools/$school_name/$session_year/$combinedClassPath/Students/$userId/Exempted Fees";
            $rawFees = $this->firebase->get($exemptedPath);
            $data['selected_exempted_fees'] = is_array($rawFees) ? $rawFees : [];
            $feesStructurePath = "Schools/$school_name/$session_year/Accounts/Fees/Fees Structure";
            $data['exemptedFees'] = $this->firebase->get($feesStructurePath);

            $classNumKey = null;
            if (preg_match('/\d+/', $existing['Class'], $m)) $classNumKey = (int)$m[0];
            $allSubjects = [];
            if ($classNumKey) {
                $subjectData = $this->CM->get_data("Schools/$school_name/Subject_list/$classNumKey");
                if (is_array($subjectData)) {
                    foreach ($subjectData as $code => $item) {
                        if (!is_array($item)) continue;
                        $category = strtolower(trim($item['category'] ?? ''));
                        $name     = trim($item['subject_name'] ?? $item['name'] ?? '');
                        if ($name !== '' && in_array($category, ['additional', 'skill-based'], true)) $allSubjects[] = $name;
                    }
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

        $this->firebase->update("Users/Parents/$school_id/$userId", $updateData);

        // Additional subjects
        $additionalSubjects = [];
        if (!empty($post['additional_subjects']) && is_array($post['additional_subjects'])) {
            foreach ($post['additional_subjects'] as $sub) {
                $sub = trim($sub);
                if ($sub !== '') $additionalSubjects[$sub] = "";
            }
        }
        $this->firebase->set("Schools/$school_name/$session_year/$combinedClassPath/Students/$userId/Additional Subjects", $additionalSubjects);

        // Exempted fees
        $exemptedFeesData = [];
        if (!empty($post['exempted_fees_multiple']) && is_array($post['exempted_fees_multiple'])) {
            foreach ($post['exempted_fees_multiple'] as $fee) {
                $fee = trim($fee);
                if ($fee !== '') $exemptedFeesData[$fee] = "";
            }
        }
        $this->firebase->set("Schools/$school_name/$session_year/$combinedClassPath/Students/$userId/Exempted Fees", $exemptedFeesData);

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
        $student = $this->CM->select_data("Users/Parents/{$school_id}/{$id}");
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
        $combinedClassPath = "Class {$class}/Section {$section}";

        // Preserve fee records before deleting student
        try {
            $this->feeLifecycle->freezeFeesOnSoftDelete($id);
            log_message('info', "Fee_lifecycle: preserved fee records before deleting student {$id}");
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::freezeFeesOnSoftDelete failed for {$id}: " . $e->getMessage());
        }

        // FIXED: storage path now includes Section (was "Class X/{id}", should be "Class X/Section Y/{id}")
        $this->CM->delete_folder_from_firebase_storage("{$school_name}/Students/{$combinedClassPath}/{$id}");
        // Also clean upload_document() storage path (uses school_id not school_name)
        $this->CM->delete_folder_from_firebase_storage("Students/{$school_id}/{$id}");

        // Remove from class roster
        $this->CM->delete_data("Schools/{$school_name}/{$session_year}/{$combinedClassPath}/Students", $id);
        $this->CM->delete_data("Schools/{$school_name}/{$session_year}/{$combinedClassPath}/Students/List", $id);

        // FIXED: delete student profile from Users/Parents (was never deleted — orphaned data)
        $this->CM->delete_data("Users/Parents/{$school_id}", $id);

        // FIXED: delete from Students_Index (was never deleted — orphaned index entry)
        $this->firebase->delete("Schools/{$school_name}/SIS/Students_Index", $id);

        // Clean phone indexes
        if (!empty($phoneNumber)) {
            $this->CM->delete_data("Schools/{$school_name}/Phone_Index", $phoneNumber);
            $this->CM->delete_data('User_ids_pno', $phoneNumber);
            $this->CM->delete_data('Exits', $phoneNumber);
        }

        log_audit('SIS', 'delete_student', $id, "Deleted student '{$student['Name']}' from Class {$class} Section {$section}");

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

        $studentData = $this->firebase->get("Users/Parents/{$school_id}/{$userId}");
        if (!$studentData) { show_error("Student not found"); return; }

        $class   = $studentData['Class']   ?? '';
        $section = $studentData['Section'] ?? '';
        $basePath = "Schools/$school_name/$session_year/Class {$class}/Section $section";

        // Subjects
        $subjectsList = [];
        if (!empty($class)) {
            $classNumber = preg_replace('/[^0-9]/', '', $class);
            $subjects = $this->firebase->get("Schools/$school_name/Subject_list/$classNumber");
            if (!empty($subjects) && is_array($subjects)) {
                foreach ($subjects as $sd) {
                    $sn = $sd['subject_name'] ?? $sd['name'] ?? '';
                    if ($sn !== '') $subjectsList[] = $sn;
                }
            }
        }

        $additionalSubjects = $this->firebase->get("$basePath/Students/$userId/Additional Subjects");
        $finalSubjectsList = array_unique(array_merge($subjectsList, array_keys($additionalSubjects ?? [])));

        $rawExempted = $this->firebase->get("$basePath/Students/$userId/Exempted Fees");
        $exemptedFees = is_array($rawExempted) ? $rawExempted : [];

        $discountData = $this->firebase->get("$basePath/Students/$userId/Discount");
        $totalDiscount   = isset($discountData['totalDiscount'])   ? (float)$discountData['totalDiscount']   : 0;
        $currentDiscount = isset($discountData['currentDiscount']) ? (float)$discountData['currentDiscount'] : 0;

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
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $data = $this->CM->select_data("Schools/{$school_name}/{$session_year}");
        $ClassesData = [];
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (strpos($key, 'Class ') === 0 && is_array($value)) {
                    foreach ($value as $sectionKey => $sectionValue) {
                        if (strpos($sectionKey, 'Section ') === 0) {
                            $ClassesData[] = ['class_name' => $key, 'section' => str_replace('Section ', '', $sectionKey)];
                        }
                    }
                }
            }
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
        $basePath = "Schools/{$school_name}/{$session_year}/{$class}/Section {$section}/Students";
        $studentsList = $this->firebase->get("$basePath/List");
        if (empty($studentsList) || !is_array($studentsList)) {
            echo json_encode(["error" => "No students found for this class/section."]);
            return;
        }

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNumber, $year);
        $sundays     = $this->_getSundays($year, $monthNumber);
        $studentsData = [];
        foreach ($studentsList as $studentId => $studentName) {
            $attendanceString = $this->firebase->get("$basePath/$studentId/Attendance/$month $year");
            if (empty($attendanceString) || !is_string($attendanceString)) $attendanceString = str_repeat('V', $daysInMonth);
            $attendanceArray = array_pad(str_split($attendanceString), $daysInMonth, 'V');
            $displayName = is_string($studentName) ? $studentName : ($studentName['Name'] ?? (string)$studentId);
            $studentsData[] = ["userId" => $studentId, "name" => $displayName, "attendance" => $attendanceArray];
        }
        echo json_encode(["students" => $studentsData, "daysInMonth" => $daysInMonth, "sundays" => $sundays, "month" => $month, "year" => $year]);
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
        $applications = $this->firebase->get("{$this->crm_base}/Applications") ?? [];
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

        $lead = $this->firebase->get("{$this->crm_base}/Applications/{$leadId}");
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

        $lead = $this->firebase->get("{$this->crm_base}/Applications/{$leadId}");
        if (!is_array($lead)) return $this->json_error('Lead not found.');

        $now = date('Y-m-d H:i:s');
        $history = $lead['history'] ?? [];
        $history[] = ['action' => "Status changed to {$status}", 'by' => $this->admin_name, 'timestamp' => $now];

        $this->firebase->update("{$this->crm_base}/Applications/{$leadId}", [
            'status'     => $status,
            'updated_at' => $now,
            'history'    => $history,
        ]);
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

        $lead = $this->firebase->get("{$this->crm_base}/Applications/{$leadId}");
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
        $applications = $this->firebase->get("{$this->crm_base}/Applications") ?? [];
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

        $inquiries    = $this->firebase->get("{$this->crm_base}/Inquiries") ?? [];
        $applications = $this->firebase->get("{$this->crm_base}/Applications") ?? [];
        $waitlist     = $this->firebase->get("{$this->crm_base}/Waitlist") ?? [];
        $settings     = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
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
        $inquiries = $this->firebase->get("{$this->crm_base}/Inquiries") ?? [];
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
            $existing = $this->firebase->get("{$this->crm_base}/Inquiries/{$id}");
            if (!is_array($existing)) return $this->json_error('Inquiry not found');
            $data = array_merge($existing, compact('student_name','parent_name','phone','email','class','source','notes','status','follow_up_date') + ['updated_at'=>$now]);
            $this->firebase->set("{$this->crm_base}/Inquiries/{$id}", $data);
        } else {
            $counter = (int)($this->firebase->get("{$this->crm_base}/Counter") ?? 0) + 1;
            $id = 'INQ' . str_pad($counter, 5, '0', STR_PAD_LEFT);
            $data = compact('student_name','parent_name','phone','email','class','source','notes','status','follow_up_date') + [
                'inquiry_id'=>$id, 'session'=>$this->session_year, 'created_at'=>$now, 'updated_at'=>$now, 'created_by'=>$this->admin_name,
            ];
            $this->firebase->set("{$this->crm_base}/Inquiries/{$id}", $data);
            $this->firebase->set("{$this->crm_base}/Counter", $counter);
        }
        return $this->json_success(['id' => $id]);
    }

    public function delete_inquiry()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_delete_inquiry');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Inquiry ID required');
        $this->firebase->delete("{$this->crm_base}/Inquiries", $this->safe_path_segment($id, 'id'));
        return $this->json_success();
    }

    public function convert_to_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_convert');
        $inquiry_id = trim($this->input->post('inquiry_id') ?? '');
        if (!$inquiry_id) return $this->json_error('Inquiry ID required');
        $inquiry_id = $this->safe_path_segment($inquiry_id, 'inquiry_id');
        $inquiry = $this->firebase->get("{$this->crm_base}/Inquiries/{$inquiry_id}");
        if (!is_array($inquiry)) return $this->json_error('Inquiry not found');

        $counter = (int)($this->firebase->get("{$this->crm_base}/Counter") ?? 0) + 1;
        $app_id = 'APP' . str_pad($counter, 5, '0', STR_PAD_LEFT);
        $now = date('Y-m-d H:i:s');
        $application = [
            'application_id'=>$app_id, 'inquiry_id'=>$inquiry_id,
            'student_name'=>$inquiry['student_name']??'', 'parent_name'=>$inquiry['parent_name']??'',
            'phone'=>$inquiry['phone']??'', 'email'=>$inquiry['email']??'', 'class'=>$inquiry['class']??'',
            'session'=>$inquiry['session']??$this->session_year, 'status'=>'pending', 'stage'=>'document_collection',
            'created_at'=>$now, 'updated_at'=>$now, 'created_by'=>$this->admin_name,
            'source_inquiry'=>$inquiry_id, 'dob'=>'', 'gender'=>'', 'address'=>'',
            'father_name'=>$inquiry['parent_name']??'', 'mother_name'=>'', 'documents'=>[], 'notes'=>$inquiry['notes']??'',
            'history'=>[['action'=>'Application created from inquiry '.$inquiry_id, 'by'=>$this->admin_name, 'timestamp'=>$now]],
        ];
        $this->firebase->set("{$this->crm_base}/Applications/{$app_id}", $application);
        $this->firebase->set("{$this->crm_base}/Counter", $counter);
        $this->firebase->update("{$this->crm_base}/Inquiries/{$inquiry_id}", ['status'=>'converted','application_id'=>$app_id,'updated_at'=>$now]);
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
        $applications = $this->firebase->get("{$this->crm_base}/Applications") ?? [];
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
            $existing = $this->firebase->get("{$this->crm_base}/Applications/{$id}");
            if (!is_array($existing)) return $this->json_error('Application not found');
            $data['updated_at'] = $now;
            $history = $existing['history'] ?? [];
            $history[] = ['action'=>'Application updated','by'=>$this->admin_name,'timestamp'=>$now];
            $data['history'] = $history;
            $this->firebase->update("{$this->crm_base}/Applications/{$id}", $data);
            return $this->json_success(['id' => $id]);
        } else {
            $counter = (int)($this->firebase->get("{$this->crm_base}/Counter") ?? 0) + 1;
            $app_id = 'APP' . str_pad($counter, 5, '0', STR_PAD_LEFT);
            $data = array_merge($data, ['application_id'=>$app_id,'session'=>$this->session_year,'status'=>'pending','stage'=>'document_collection',
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$this->admin_name,'documents'=>[],
                'history'=>[['action'=>'Application created directly','by'=>$this->admin_name,'timestamp'=>$now]]]);
            $this->firebase->set("{$this->crm_base}/Applications/{$app_id}", $data);
            $this->firebase->set("{$this->crm_base}/Counter", $counter);
            return $this->json_success(['id' => $app_id]);
        }
    }

    public function get_application()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $id = trim($this->input->get('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->firebase->get("{$this->crm_base}/Applications/{$id}");
        if (!is_array($app)) return $this->json_error('Application not found');
        $app['id'] = $id;
        return $this->json_success(['application' => $app]);
    }

    public function delete_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_delete_app');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $this->firebase->delete("{$this->crm_base}/Applications", $this->safe_path_segment($id, 'id'));
        return $this->json_success();
    }

    public function pipeline()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_crm_classes();
        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        $data['settings'] = is_array($settings) ? $settings : [];
        $this->load->view('include/header');
        $this->load->view('admission_crm/pipeline', $data);
        $this->load->view('include/footer');
    }

    public function fetch_pipeline()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $applications = $this->firebase->get("{$this->crm_base}/Applications") ?? [];
        if (!is_array($applications)) $applications = [];
        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
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
        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        $allowedStages = (is_array($settings) && !empty($settings['stages'])) ? array_keys($settings['stages']) : array_keys($this->_default_stages());
        if (!in_array($stage, $allowedStages, true)) return $this->json_error('Invalid stage: '.$stage);
        $app = $this->firebase->get("{$this->crm_base}/Applications/{$id}");
        if (!is_array($app)) return $this->json_error('Application not found');
        $now = date('Y-m-d H:i:s');
        $history = $app['history'] ?? [];
        $history[] = ['action'=>"Stage changed: {$app['stage']} → {$stage}",'by'=>$this->admin_name,'timestamp'=>$now];
        $this->firebase->update("{$this->crm_base}/Applications/{$id}", ['stage'=>$stage,'updated_at'=>$now,'history'=>$history]);
        return $this->json_success();
    }

    public function approve_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_approve');
        $id = trim($this->input->post('id') ?? '');
        $remarks = trim($this->input->post('remarks') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->firebase->get("{$this->crm_base}/Applications/{$id}");
        if (!is_array($app)) return $this->json_error('Application not found');
        $cs = $app['status'] ?? 'pending';
        if ($cs === 'enrolled') return $this->json_error('Cannot approve an already enrolled application');
        if ($cs === 'approved') return $this->json_error('Application is already approved');
        $now = date('Y-m-d H:i:s');
        $history = $app['history'] ?? [];
        $history[] = ['action'=>'Application approved'.($remarks?": {$remarks}":''),'by'=>$this->admin_name,'timestamp'=>$now];
        $this->firebase->update("{$this->crm_base}/Applications/{$id}", ['status'=>'approved','stage'=>'approved','approved_by'=>$this->admin_name,'approved_at'=>$now,'remarks'=>$remarks,'updated_at'=>$now,'history'=>$history]);
        return $this->json_success();
    }

    public function reject_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_reject');
        $id = trim($this->input->post('id') ?? '');
        $reason = trim($this->input->post('reason') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->firebase->get("{$this->crm_base}/Applications/{$id}");
        if (!is_array($app)) return $this->json_error('Application not found');
        $cs = $app['status'] ?? 'pending';
        if ($cs === 'enrolled') return $this->json_error('Cannot reject an already enrolled application');
        if ($cs === 'rejected') return $this->json_error('Application is already rejected');
        $now = date('Y-m-d H:i:s');
        $history = $app['history'] ?? [];
        $history[] = ['action'=>'Application rejected'.($reason?": {$reason}":''),'by'=>$this->admin_name,'timestamp'=>$now];
        $this->firebase->update("{$this->crm_base}/Applications/{$id}", ['status'=>'rejected','stage'=>'rejected','rejected_by'=>$this->admin_name,'rejected_at'=>$now,'reject_reason'=>$reason,'updated_at'=>$now,'history'=>$history]);
        return $this->json_success();
    }

    public function enroll_student()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_enroll');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->firebase->get("{$this->crm_base}/Applications/{$id}");
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
        $className = trim($app['class'] ?? '');
        $section = trim($app['section'] ?? 'A');
        if ($className === '') return $this->json_error('Class not specified in application');

        $combinedPath = "Class {$className}/Section {$section}";
        $formattedDOB = !empty($app['dob']) ? date('d-m-Y', strtotime($app['dob'])) : '';
        $now = date('Y-m-d H:i:s');

        $studentData = [
            "Name"=>$app['student_name']??'', "User Id"=>$studentId, "DOB"=>$formattedDOB,
            "Admission Date"=>date('d-m-Y'), "Class"=>$className, "Section"=>$section,
            "Gender"=>$app['gender']??'', "Blood Group"=>$app['blood_group']??'',
            "Category"=>$app['category']??'', "Religion"=>$app['religion']??'',
            "Nationality"=>$app['nationality']??'',
            "Father Name"=>$app['father_name']??'', "Father Occupation"=>$app['father_occupation']??'',
            "Mother Name"=>$app['mother_name']??'', "Mother Occupation"=>$app['mother_occupation']??'',
            "Guard Contact"=>$app['guardian_phone']??'', "Guard Relation"=>$app['guardian_relation']??'',
            "Phone Number"=>$app['phone']??'', "Email"=>$app['email']??'',
            "Password"=>$this->_generatePassword($app['student_name']??'',$formattedDOB),
            "Address"=>["Street"=>$app['address']??'',"City"=>$app['city']??'',"State"=>$app['state']??'',"PostalCode"=>$app['pincode']??''],
            "Pre School"=>$app['previous_school']??'', "Pre Class"=>$app['previous_class']??'', "Pre Marks"=>$app['previous_marks']??'',
            "Profile Pic"=>"",
            "Doc"=>["Aadhar Card"=>["thumbnail"=>"","url"=>""],"Birth Certificate"=>["thumbnail"=>"","url"=>""],"Photo"=>["thumbnail"=>"","url"=>""],"Transfer Certificate"=>["thumbnail"=>"","url"=>""]],
            "Status"=>"Active",
        ];

        $this->firebase->set("Users/Parents/{$school_id}/{$studentId}", $studentData);
        $this->firebase->update("Schools/{$school_name}/{$session}/{$combinedPath}/Students", [$studentId => ['Name'=>$app['student_name']??'']]);
        $this->firebase->update("Schools/{$school_name}/{$session}/{$combinedPath}/Students/List", [$studentId => $app['student_name']??'']);
        // Counter already incremented inside _nextStudentId() — no manual write needed

        $phone = trim($app['phone'] ?? '');
        if ($phone !== '') {
            $this->firebase->update("Schools/{$school_name}/Phone_Index", [$phone => $studentId]);
            $this->firebase->update('Exits', [$phone => $school_id]);
            $this->firebase->update('User_ids_pno', [$phone => $studentId]);
        }

        // Update Students_Index (matches save_admission pattern)
        $gender = $app['gender'] ?? '';
        $this->_update_student_index($school_name, $studentId, $app['student_name'] ?? '', $className, $section, 'Active', $gender);

        // Initialize Month Fee markers as unpaid (0) for all 12 months
        $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
        $monthFeeData = array_fill_keys($months, 0);
        $this->firebase->set("Schools/{$school_name}/{$session}/{$combinedPath}/Students/{$studentId}/Month Fee", $monthFeeData);

        $history = $app['history'] ?? [];
        $history[] = ['action'=>"Enrolled as {$studentId} in Class {$className} Section {$section}",'by'=>$this->admin_name,'timestamp'=>$now];
        $this->firebase->update("{$this->crm_base}/Applications/{$id}", ['status'=>'enrolled','stage'=>'enrolled','student_id'=>$studentId,'enrolled_at'=>$now,'enrolled_by'=>$this->admin_name,'updated_at'=>$now,'history'=>$history]);

        // ── Sync student credentials to MongoDB (best-effort) ──────────
        try {
            $this->load->library('auth_client');
            $password = $studentData['Password'] ?? '';
            $this->auth_client->sync_student([
                'studentId'          => $studentId,
                'name'               => $app['student_name'] ?? '',
                'email'              => $app['email'] ?? '',
                'phone'              => $phone,
                'password'           => $password,
                'schoolId'           => $this->school_code,
                'schoolCode'         => $this->school_name,
                'parentDbKey'        => $this->parent_db_key,
                'parentPhone'        => $app['guardian_phone'] ?? $phone,
                'createdBy'          => $this->session->userdata('admin_id') ?: 'system',
                'className'          => $className,
                'section'            => $section,
                'rollNo'             => '',
                'fatherName'         => $app['father_name'] ?? '',
                'motherName'         => $app['mother_name'] ?? '',
                'dob'                => $studentData['DOB'] ?? '',
                'admissionDate'      => $studentData['Admission Date'] ?? '',
                'gender'             => $app['gender'] ?? '',
                'profilePic'         => '',
                'schoolDisplayName'  => $this->school_display_name ?? '',
                'deviceBindingMethod'=> 'otp',
            ]);
        } catch (Exception $e) {
            log_message('error', "SIS enroll MongoDB sync exception for {$studentId}: " . $e->getMessage());
        }

        // Auto-assign class fees for enrolled student
        try {
            $parentDbKey = $school_id;
            $this->feeLifecycle->assignInitialFees($studentId, $className, $section, $parentDbKey);
            log_message('info', "Fee_lifecycle: auto-assigned fees for new enrollment {$studentId}");
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::assignInitialFees failed for {$studentId}: " . $e->getMessage());
        }

        return $this->json_success(['student_id'=>$studentId,'class'=>$className,'section'=>$section]);
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
        $waitlist = $this->firebase->get("{$this->crm_base}/Waitlist") ?? [];
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
        $app = $this->firebase->get("{$this->crm_base}/Applications/{$app_id}");
        if (!is_array($app)) return $this->json_error('Application not found');
        $now = date('Y-m-d H:i:s');
        $counter = (int)($this->firebase->get("{$this->crm_base}/Counter") ?? 0) + 1;
        $wl_id = 'WL' . str_pad($counter, 5, '0', STR_PAD_LEFT);
        $waitEntry = ['waitlist_id'=>$wl_id,'application_id'=>$app_id,'student_name'=>$app['student_name']??'','parent_name'=>$app['parent_name']??'',
            'phone'=>$app['phone']??'','class'=>$app['class']??'','session'=>$app['session']??$this->session_year,
            'priority'=>$priority,'reason'=>$reason,'status'=>'waiting','created_at'=>$now,'updated_at'=>$now];
        $this->firebase->set("{$this->crm_base}/Waitlist/{$wl_id}", $waitEntry);
        $this->firebase->set("{$this->crm_base}/Counter", $counter);
        $history = $app['history'] ?? [];
        $history[] = ['action'=>'Added to waitlist','by'=>$this->admin_name,'timestamp'=>$now];
        $this->firebase->update("{$this->crm_base}/Applications/{$app_id}", ['status'=>'waitlisted','stage'=>'waitlisted','updated_at'=>$now,'history'=>$history]);
        return $this->json_success(['id' => $wl_id]);
    }

    public function remove_from_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_remove');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Waitlist ID required');
        $id = $this->safe_path_segment($id, 'id');
        $entry = $this->firebase->get("{$this->crm_base}/Waitlist/{$id}");
        if (is_array($entry) && !empty($entry['application_id'])) {
            $this->firebase->update("{$this->crm_base}/Applications/{$entry['application_id']}", ['status'=>'pending','stage'=>'document_collection','updated_at'=>date('Y-m-d H:i:s')]);
        }
        $this->firebase->delete("{$this->crm_base}/Waitlist", $id);
        return $this->json_success();
    }

    public function promote_from_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_promote');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Waitlist ID required');
        $id = $this->safe_path_segment($id, 'id');
        $entry = $this->firebase->get("{$this->crm_base}/Waitlist/{$id}");
        if (!is_array($entry)) return $this->json_error('Waitlist entry not found');
        $app_id = $entry['application_id'] ?? '';
        if (!$app_id) return $this->json_error('No linked application');
        $now = date('Y-m-d H:i:s');
        $app = $this->firebase->get("{$this->crm_base}/Applications/{$app_id}");
        if (is_array($app)) {
            $history = $app['history'] ?? [];
            $history[] = ['action'=>'Promoted from waitlist and approved','by'=>$this->admin_name,'timestamp'=>$now];
            $this->firebase->update("{$this->crm_base}/Applications/{$app_id}", ['status'=>'approved','stage'=>'approved','approved_by'=>$this->admin_name,'approved_at'=>$now,'updated_at'=>$now,'history'=>$history]);
        }
        $this->firebase->delete("{$this->crm_base}/Waitlist", $id);
        return $this->json_success();
    }

    public function crm_settings()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        if (!is_array($settings)) $settings = [];
        $data = ['settings'=>$settings,'session_year'=>$this->session_year,'classes'=>$this->_get_crm_classes()];
        $this->load->view('include/header');
        $this->load->view('admission_crm/settings', $data);
        $this->load->view('include/footer');
    }

    public function save_crm_settings()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_settings');
        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        if (!is_array($settings)) $settings = [];
        foreach (['stages','class_limits','form_fields','notifications'] as $key) {
            $val = $this->input->post($key);
            if ($val) { $decoded = json_decode($val, true); if (is_array($decoded)) $settings[$key] = $decoded; }
        }
        $settings['updated_at'] = date('Y-m-d H:i:s');
        $this->firebase->set("{$this->crm_base}/Settings", $settings);
        return $this->json_success();
    }

    public function get_crm_settings()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        if (!is_array($settings)) $settings = [];
        if (empty($settings['stages'])) $settings['stages'] = $this->_default_stages();
        return $this->json_success(['settings' => $settings]);
    }

    public function online_form()
    {
        $school_name = $this->school_name;
        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        $classes  = $this->_get_crm_classes();
        $profile  = $this->firebase->get("Schools/{$school_name}/Config/Profile") ?? [];
        $data = ['school_name'=>$school_name,'session_year'=>$this->session_year,'settings'=>is_array($settings)?$settings:[],'classes'=>$classes,'profile'=>is_array($profile)?$profile:[]];
        $this->load->view('admission_crm/online_form', $data);
    }

    public function submit_online_form()
    {
        // ── C-03 FIX: Rate limiting — max 10 submissions per IP per 15 minutes ──
        $clientIp = $this->input->ip_address();
        $ipKey    = preg_replace('/[^a-zA-Z0-9]/', '_', $clientIp);
        $ratePath = "System/RateLimits/online_form/{$ipKey}";
        $rateData = $this->firebase->get($ratePath);
        $windowStart = time() - 900; // 15-minute window
        if (is_array($rateData)) {
            $recentCount = 0;
            foreach ($rateData as $ts => $v) {
                if ((int) $ts >= $windowStart) {
                    $recentCount++;
                } else {
                    $this->firebase->delete($ratePath, (string) $ts);
                }
            }
            if ($recentCount >= 10) {
                return $this->json_error('Too many submissions. Please try again later.', 429);
            }
        }
        $this->firebase->set("{$ratePath}/" . time() . '_' . mt_rand(1000, 9999), 1);

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

        $existingApps = $this->firebase->get("{$this->crm_base}/Applications") ?? [];
        if (is_array($existingApps)) {
            foreach ($existingApps as $ea) {
                if (!is_array($ea)||($ea['session']??'')!==$this->session_year) continue;
                if (($ea['phone']??'')===$data['phone']&&in_array($ea['status']??'',['pending','approved','waitlisted','enrolled'])) {
                    return $this->json_error('An application with this phone number already exists for this session (ID: '.($ea['application_id']??'N/A').')');
                }
            }
        }

        $counter = (int)($this->firebase->get("{$this->crm_base}/Counter") ?? 0);
        $counter++;
        $inq_id = 'INQ'.str_pad($counter,5,'0',STR_PAD_LEFT);
        $this->firebase->set("{$this->crm_base}/Inquiries/{$inq_id}", ['inquiry_id'=>$inq_id,'student_name'=>$data['student_name'],'parent_name'=>$data['parent_name'],'phone'=>$data['phone'],'email'=>$data['email'],'class'=>$data['class'],'source'=>'Online Form','status'=>'converted','session'=>$this->session_year,'created_at'=>$now,'updated_at'=>$now,'created_by'=>'Online']);
        $counter++;
        $app_id = 'APP'.str_pad($counter,5,'0',STR_PAD_LEFT);
        $appData = array_merge($data, ['application_id'=>$app_id,'inquiry_id'=>$inq_id,'session'=>$this->session_year,'status'=>'pending','stage'=>'document_collection','created_at'=>$now,'updated_at'=>$now,'created_by'=>'Online','documents'=>[],'history'=>[['action'=>'Application submitted via online form','by'=>'Online','timestamp'=>$now]]]);
        $this->firebase->set("{$this->crm_base}/Applications/{$app_id}", $appData);
        $this->firebase->update("{$this->crm_base}/Inquiries/{$inq_id}", ['application_id'=>$app_id]);
        $this->firebase->set("{$this->crm_base}/Counter", $counter);
        return $this->json_success(['application_id' => $app_id]);
    }

    public function fetch_analytics()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $inquiries    = $this->firebase->get("{$this->crm_base}/Inquiries") ?? [];
        $applications = $this->firebase->get("{$this->crm_base}/Applications") ?? [];
        $waitlist     = $this->firebase->get("{$this->crm_base}/Waitlist") ?? [];
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
        $school_name = $this->school_name;
        $session_year = $this->session_year;
        $path = "Schools/{$school_name}/{$session_year}/Accounts/Fees/Classes Fees/{$className} '{$section}'";
        $feesData = $this->CM->get_data($path);
        if ($feesData && !empty($feesData)) {
            if (!isset($feesData['Yearly Fees']) || !is_array($feesData['Yearly Fees'])) {
                $feesStructure = $this->CM->get_data("Schools/$school_name/$session_year/Accounts/Fees/Fees Structure/Yearly");
                if ($feesStructure && is_array($feesStructure)) {
                    $yearlyFees = array_fill_keys(array_keys($feesStructure), 0);
                    $feesData['Yearly Fees'] = $yearlyFees;
                    $this->CM->addKey_pair_data($path, ['Yearly Fees' => $yearlyFees]);
                }
            }
            $formattedFees = [];
            $monthlyTotals = [];
            foreach ($feesData as $month => $fees) {
                if (is_array($fees)) {
                    $formattedFees[$month] = $fees;
                    $monthlyTotals[$month] = array_sum($fees);
                }
            }
            return json_encode(["fees"=>$formattedFees,"monthlyTotals"=>$monthlyTotals,"overallTotal"=>array_sum($monthlyTotals)]);
        }
        return json_encode(["fees"=>[],"monthlyTotals"=>[]]);
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
        $school_name = $this->school_name;
        $session     = $this->session_year;
        $classes = [];
        $sessionKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session}");
        if (!is_array($sessionKeys)) return $classes;
        foreach ($sessionKeys as $key) {
            if (strpos($key, 'Class ') !== 0) continue;
            $sectionKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session}/{$key}");
            if (!is_array($sectionKeys)) continue;
            foreach ($sectionKeys as $sk) {
                if (strpos($sk, 'Section ') !== 0) continue;
                $sec = str_replace('Section ', '', $sk);
                $classes[] = ['class_name'=>$key, 'section'=>$sec, 'label'=>$key.' / Section '.$sec];
            }
        }
        return $classes;
    }
}
