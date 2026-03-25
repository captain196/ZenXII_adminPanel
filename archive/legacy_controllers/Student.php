<?php

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class Student extends MY_Controller
{
    private const MANAGE_ROLES = ['Admin', 'Principal'];
    private const VIEW_ROLES   = ['Admin', 'Principal', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
    }


    public function all_student()
    {
        $this->_require_role(self::VIEW_ROLES);
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        /* ══════════════════════════════════════════════════════
           FETCH ALL STUDENTS
        ══════════════════════════════════════════════════════ */
        $data['students'] = $this->CM->select_data('Users/Parents/' . $school_id);

        if (!is_array($data['students'])) {
            $data['students'] = [];
        }

        /* Remove known non-student keys */
        $nonStudentKeys = ['Count', 'TC Students', ''];
        // NOTE: null removed — isset($arr[null]) converts null→'' duplicating the '' entry
        foreach ($nonStudentKeys as $key) {
            if (isset($data['students'][$key])) {
                unset($data['students'][$key]);
            }
        }

        /* Final cleanup: must be array with a valid User Id */
        $data['students'] = array_filter($data['students'], function ($student) {
            return is_array($student)
                && isset($student['User Id'])
                && !empty($student['User Id']);
        });



        $classNames    = [];
        $classSections = [];
        $sessionNode   = [];   // initialised here so the enrollment filter below is always safe

        if (!empty($school_name)) {
            /*
             * BUG FIX 2: correct path — iterate the session year
             * node and pick keys that start with "Class "
             */
            $sessionNode = $this->CM->select_data(
                'Schools/' . $school_name . '/' . $session_year
            );

            /* BUG FIX 3: guard against null before foreach */
            if (is_array($sessionNode)) {
                foreach ($sessionNode as $nodeKey => $nodeValue) {

                    if (strpos($nodeKey, 'Class ') !== 0 || !is_array($nodeValue)) {
                        continue;
                    }

                    // Extract ordinal part: "Class 8th" → "8th"
                    preg_match('/\b\d+(st|nd|rd|th)\b/i', $nodeKey, $matches);

                    /* BUG FIX 4: skip if regex didn't match to avoid
                       $ordinalPart leaking from a previous iteration */
                    if (empty($matches)) continue;

                    $ordinalPart   = $matches[0];
                    $classNames[]  = $ordinalPart;

                    // Collect sections: pick "Section X" sub-keys
                    $sections = [];
                    foreach ($nodeValue as $subKey => $subVal) {
                        if (strpos($subKey, 'Section ') === 0) {
                            $sections[] = str_replace('Section ', '', $subKey);
                        }
                    }
                    $classSections[$ordinalPart] = $sections;
                }
            }
        }

        /*
         * SESSION ISOLATION FIX: Only show students enrolled in the current session.
         * The $sessionNode already contains Students/List data for every class-section,
         * so we can collect enrolled IDs without any additional Firebase call and then
         * discard global profiles that don't belong to this session.
         *
         * In a brand-new session (no classes yet) the list is correctly empty.
         */
        $enrolledIds = [];
        if (is_array($sessionNode)) {
            foreach ($sessionNode as $classKey => $classVal) {
                if (strpos($classKey, 'Class ') !== 0 || !is_array($classVal)) continue;
                foreach ($classVal as $sectionKey => $sectionVal) {
                    if (strpos($sectionKey, 'Section ') !== 0 || !is_array($sectionVal)) continue;
                    $list = $sectionVal['Students']['List'] ?? null;
                    if (is_array($list)) {
                        foreach (array_keys($list) as $sid) {
                            $enrolledIds[$sid] = true;
                        }
                    }
                }
            }
        }
        $data['students'] = array_filter($data['students'], function ($student) use ($enrolledIds) {
            return isset($enrolledIds[$student['User Id']]);
        });

        $data['classNames']    = $classNames;
        $data['classSections'] = $classSections;


        $this->load->view('include/header');
        $this->load->view('all_student', $data);
        $this->load->view('include/footer');
    }



    public function id_card()
    {
        $this->_require_role(self::VIEW_ROLES);
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        /* ── 1. Fetch all registered students globally ── */
        $allStudents = $this->CM->select_data('Users/Parents/' . $school_id);
        if (!is_array($allStudents)) {
            $allStudents = [];
        }
        foreach (['Count', 'TC Students', ''] as $k) {
            unset($allStudents[$k]);
        }
        $allStudents = array_filter($allStudents, function ($s) {
            return is_array($s) && !empty($s['User Id']);
        });

        /* ── 2. Session isolation: targeted Students/List fetches ── */
        $enrolledIds = [];
        if (!empty($school_name)) {
            $sessionClassKeys = $this->firebase->shallow_get(
                'Schools/' . $school_name . '/' . $session_year
            );
            if (!is_array($sessionClassKeys)) $sessionClassKeys = [];
            foreach ($sessionClassKeys as $classKey) {
                if (strpos($classKey, 'Class ') !== 0) continue;
                $sectionKeys = $this->firebase->shallow_get(
                    'Schools/' . $school_name . '/' . $session_year . '/' . $classKey
                );
                if (!is_array($sectionKeys)) continue;
                foreach ($sectionKeys as $sectionKey) {
                    if (strpos($sectionKey, 'Section ') !== 0) continue;
                    $list = $this->CM->select_data(
                        'Schools/' . $school_name . '/' . $session_year
                        . '/' . $classKey . '/' . $sectionKey . '/Students/List'
                    );
                    if (is_array($list)) {
                        foreach (array_keys($list) as $sid) {
                            $enrolledIds[$sid] = true;
                        }
                    }
                }
            }
        }

        /* ── 3. Filter & sort ── */
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

        $data['students']     = $students;
        $data['session_year'] = $session_year;
        $data['school_name']  = $school_name;

        $this->load->view('include/header');
        $this->load->view('student_id_card', $data);
        $this->load->view('include/footer');
    }

    public function master_student()
    {
        $this->_require_role(self::VIEW_ROLES);
        $this->load->view('include/header');
        $this->load->view('import_students'); // view file
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
                redirect('student/all_student');
                return;
            }

            $file = $_FILES['excelFile'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            $reader = ($extension === 'csv')
                ? IOFactory::createReader('Csv')
                : IOFactory::createReader('Xlsx');

            $spreadsheet = $reader->load($file['tmp_name']);
            $sheetData   = $spreadsheet->getActiveSheet()->toArray();

            if (count($sheetData) <= 1) {
                redirect('student/all_student');
                return;
            }

            $headers = array_map('trim', $sheetData[0]);
            unset($sheetData[0]);
            $sheetData = array_values($sheetData);

            $success = 0;
            $error   = 0;

            $studentIdCount = $this->CM->get_data("Users/Parents/{$school_id}/Count");
            if (!$studentIdCount) $studentIdCount = 1;

            $subjectCache = [];

            foreach ($sheetData as $row) {

                if (!array_filter($row)) continue;

                if (count($headers) != count($row)) {
                    $error++;
                    continue;
                }

                $rowData = array_combine($headers, $row);

                // ✅ Required fields only: Name, Class, Section
                $studentName = trim($rowData['Name'] ?? '');
                $classRaw    = trim($rowData['Class'] ?? '');
                $section     = trim($rowData['Section'] ?? '');

                if (!$studentName || !$classRaw || !$section) {
                    $error++;
                    continue;
                }

                // ✅ Extract class number from any format
                preg_match('/\d+/', $classRaw, $match);
                if (!isset($match[0])) {
                    $error++;
                    continue;
                }

                $classNumber = (int)$match[0];

                // ✅ Convert to ordinal
                $suffix = 'th';
                if (!in_array(($classNumber % 100), [11, 12, 13])) {
                    switch ($classNumber % 10) {
                        case 1:
                            $suffix = 'st';
                            break;
                        case 2:
                            $suffix = 'nd';
                            break;
                        case 3:
                            $suffix = 'rd';
                            break;
                    }
                }

                $className = $classNumber . $suffix;
                $combinedClass = "Class {$className}/Section {$section}";

                $studentId = 'STU' . str_pad($studentIdCount, 4, '0', STR_PAD_LEFT);

                // ✅ Format DOB
                $formattedDOB = '';
                if (!empty($rowData['DOB'])) {
                    $formattedDOB = date('d-m-Y', strtotime($rowData['DOB']));
                }

                // ✅ Format Admission Date
                $formattedAdmDate = '';
                if (!empty($rowData['Admission Date'])) {
                    $formattedAdmDate = date('d-m-Y', strtotime($rowData['Admission Date']));
                }

                $password = $this->generatePassword($studentName, $formattedDOB);

                // ✅ Full student data matching Firebase structure
                $studentData = [
                    "Name"              => $studentName,
                    "User Id"           => $studentId,
                    "DOB"               => $formattedDOB,
                    "Admission Date"    => $formattedAdmDate,
                    "Class"             => $className,
                    "Section"           => $section,
                    "Gender"            => trim($rowData['Gender'] ?? ''),
                    "Blood Group"       => trim($rowData['Blood Group'] ?? ''),
                    "Category"          => trim($rowData['Category'] ?? ''),
                    "Religion"          => trim($rowData['Religion'] ?? ''),
                    "Nationality"       => trim($rowData['Nationality'] ?? ''),

                    "Father Name"       => trim($rowData['Father Name'] ?? ''),
                    "Father Occupation" => trim($rowData['Father Occupation'] ?? ''),
                    "Mother Name"       => trim($rowData['Mother Name'] ?? ''),
                    "Mother Occupation" => trim($rowData['Mother Occupation'] ?? ''),
                    "Guard Contact"     => trim($rowData['Guard Contact'] ?? ''),
                    "Guard Relation"    => trim($rowData['Guard Relation'] ?? ''),

                    "Phone Number"      => trim($rowData['Phone Number'] ?? ''),
                    "Email"             => trim($rowData['Email'] ?? ''),
                    "Password"          => $password,

                    // ✅ Address as nested object
                    "Address" => [
                        "Street"     => trim($rowData['Street'] ?? ''),
                        "City"       => trim($rowData['City'] ?? ''),
                        "State"      => trim($rowData['State'] ?? ''),
                        "PostalCode" => trim($rowData['PostalCode'] ?? ''),
                    ],

                    // ✅ Previous school details
                    "Pre School"        => trim($rowData['Pre School'] ?? ''),
                    "Pre Class"         => trim($rowData['Pre Class'] ?? ''),
                    "Pre Marks"         => trim($rowData['Pre Marks'] ?? ''),

                    // ✅ Profile Pic empty until edited
                    "Profile Pic"       => "",

                    // ✅ Doc with empty nested structure (ready for Edit Student)
                    "Doc" => [
                        "Aadhar Card" => [
                            "thumbnail" => "",
                            "url"       => "",
                        ],
                        "Birth Certificate" => [
                            "thumbnail" => "",
                            "url"       => "",
                        ],
                        "Photo" => [
                            "thumbnail" => "",
                            "url"       => "",
                        ],
                        "Transfer Certificate" => [
                            "thumbnail" => "",
                            "url"       => "",
                        ],
                    ],
                ];

                // ✅ Insert student
                $studentPath = "Users/Parents/{$school_id}/{$studentId}";
                $this->firebase->set($studentPath, $studentData);

                // ✅ Add to class roster
                $this->CM->addKey_pair_data(
                    "Schools/{$school_name}/{$session_year}/{$combinedClass}/Students/",
                    [$studentId => ['Name' => $studentName]]
                );
                // ✅ Add to class roster List (simple key:value)
                $this->CM->addKey_pair_data(
                    "Schools/{$school_name}/{$session_year}/{$combinedClass}/Students/List/",
                    [$studentId => $studentName]
                );

                // Add inside the foreach loop in import_students(), after the student insert
                $phone = trim($rowData['Phone Number'] ?? '');
                if ($phone !== '') {
                    // Tenant-scoped phone index (primary)
                    $this->CM->addKey_pair_data("Schools/{$school_name}/Phone_Index/", [$phone => $studentId]);
                    // Legacy global indexes — kept for mobile app backward compatibility
                    $this->CM->addKey_pair_data('Exits/', [$phone => $school_id]);
                    $this->CM->addKey_pair_data('User_ids_pno/', [$phone => $studentId]);
                }


                // ✅ FETCH SUBJECTS
                if (!isset($subjectCache[$classNumber])) {

                    $subjectCache[$classNumber] = [
                        'core'              => [],   // core subjects → student's Subjects node
                        'allSubjects'       => [],   // everything except additional → All Subjects path
                        'additionalSubjects' => [],  // additional only → student's Additional Subjects node
                    ];

                    $rawList = $this->firebase->get(
                        "Schools/{$school_name}/Subject_list/{$classNumber}"
                    );

                    if (defined('GRADER_DEBUG') && GRADER_DEBUG) log_message('debug', 'Raw subject list: ' . json_encode($rawList));

                    if (is_array($rawList)) {

                        foreach ($rawList as $code => $item) {

                            if (!is_array($item)) continue;

                            $subName = trim($item['subject_name'] ?? $item['name'] ?? '');
                            if ($subName === '') continue;

                            $type = strtolower(trim($item['category'] ?? ''));

                            if ($type === 'additional') {
                                // ⭐ Additional subjects → Student's Additional Subjects node
                                $subjectCache[$classNumber]['additionalSubjects'][$subName] = "";
                            } else {
                                // ⭐ Everything except additional → All Subjects path
                                $subjectCache[$classNumber]['allSubjects'][(string)$code] = $subName;

                                // ⭐ Only CORE → student's Subjects node
                                if ($type === 'core') {
                                    $subjectCache[$classNumber]['core'][(string)$code] = [
                                        'name' => $subName,
                                        'type' => 'core'
                                    ];
                                }
                            }
                        }
                    }

                    if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                        log_message('debug', 'Core subject cache: ' . json_encode($subjectCache[$classNumber]['core']));
                        log_message('debug', 'All subjects cache: ' . json_encode($subjectCache[$classNumber]['allSubjects']));
                        log_message('debug', 'Additional subjects cache: ' . json_encode($subjectCache[$classNumber]['additionalSubjects']));
                    }

                    // ✅ Insert ALL subjects (except additional) to class path (only once per class)
                    if (!empty($subjectCache[$classNumber]['allSubjects'])) {
                        $this->firebase->set(
                            "Schools/{$school_name}/{$session_year}/Class {$className}/All Subjects",
                            $subjectCache[$classNumber]['allSubjects']
                        );
                    }
                }

                // ✅ Assign core subjects to student
                if (!empty($subjectCache[$classNumber]['core'])) {
                    $this->firebase->set(
                        "Users/Parents/{$school_id}/{$studentId}/Subjects",
                        $subjectCache[$classNumber]['core']
                    );
                }

                // ✅ Assign additional subjects to student under class roster
                if (!empty($subjectCache[$classNumber]['additionalSubjects'])) {
                    $this->firebase->set(
                        "Schools/{$school_name}/{$session_year}/{$combinedClass}/Students/{$studentId}/Additional Subjects",
                        $subjectCache[$classNumber]['additionalSubjects']
                    );
                }

                $studentIdCount++;
                $success++;
            }

            // ✅ Update count
            $this->CM->addKey_pair_data(
                "Users/Parents/{$school_id}/",
                ['Count' => $studentIdCount]
            );

            $this->session->set_flashdata(
                'import_result',
                "Imported Successfully: {$success} | Failed: {$error}"
            );

            redirect('student/all_student');
        } catch (Exception $e) {
            log_message('error', 'IMPORT ERROR: ' . $e->getMessage());
            $this->session->set_flashdata('import_result', "Import Failed! Check logs.");
            redirect('student/all_student');
        }
    }


    public function studentAdmission()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $data['school_name'] = $school_name;
        $schoolName = $school_name;

        /* ===============================
       STUDENT ID GENERATION
        =============================== */
        $studentIdCount = $this->CM->get_data("Users/Parents/{$school_id}/Count");

        if ($studentIdCount === null) {
            $studentIdCount = 1;
        }

        $userId = 'STU' . str_pad($studentIdCount, 4, '0', STR_PAD_LEFT);
        $data['user_Id'] = $userId;

        /* ===============================
       FETCH CLASSES & SECTIONS
        =============================== */
        $basePath = "Schools/{$school_name}/{$session_year}";
        $sessionData = $this->firebase->get($basePath);

        $ClassesData = [];
        if (is_array($sessionData)) {
            foreach ($sessionData as $classKey => $classVal) {
                if (strpos($classKey, 'Class ') === 0 && is_array($classVal)) {
                    foreach ($classVal as $sectionKey => $v) {
                        if (strpos($sectionKey, 'Section ') === 0) {
                            $ClassesData[] = [
                                'class_name' => $classKey,
                                'section'    => str_replace('Section ', '', $sectionKey)
                            ];
                        }
                    }
                }
            }
        }
        $data['Classes'] = $ClassesData;

        /* ===============================
       FEES STRUCTURE
        =============================== */
        $feesStructurePath = "Schools/{$schoolName}/{$session_year}/Accounts/Fees/Fees Structure";
        $data['exemptedFees'] = $this->firebase->get($feesStructurePath);


        /* ===============================
       HANDLE POST
        =============================== */
        if ($this->input->method() === 'post') {
            header('Content-Type: application/json');

            $postData = $this->input->post();
            $normalizedPostData = [];

            foreach ($postData as $key => $value) {
                $normalizedPostData[urldecode($key)] = $value;
            }

            /* ===============================
           REQUIRED FIELDS
            =============================== */
            $studentId   = $normalizedPostData['user_id'] ?? '';
            $studentName = $normalizedPostData['Name'] ?? '';
            $phoneNumber = $normalizedPostData['phone_number'] ?? '';

            $classNameRaw = $normalizedPostData['class'] ?? '';
            $className    = trim(str_replace('Class ', '', $classNameRaw));
            $section     = $normalizedPostData['section'] ?? '';

            if (!$studentId || !$studentName || !$className || !$section) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Student ID, Name, Class and Section are required'
                ]);
                return;
            }

            // Validate path segments
            $classNameRaw = $this->safe_path_segment($classNameRaw, 'class');
            $section      = $this->safe_path_segment($section, 'section');
            $studentId    = $this->safe_path_segment($studentId, 'user_id');

            $combinedClassPath = "{$classNameRaw}/Section {$section}";

            // log_message('error', 'Combined Path: ' . $combinedClassPath);



            /* ===============================
            DOCUMENT UPLOAD
            ================================= */

            $documents = [
                'birthCertificate'    => 'Birth Certificate',
                'aadharCard'          => 'Aadhar Card',
                'transferCertificate' => 'Transfer Certificate'
            ];

            $documentUrls  = [];
            $thumbnailUrls = [];

            foreach ($documents as $inputKey => $label) {

                if (!empty($_FILES[$inputKey]['tmp_name'])) {

                    $uploadResult = $this->uploadStudentFile(
                        $_FILES[$inputKey],
                        $schoolName,
                        $combinedClassPath,   // ✅ session removed
                        $studentId,
                        $label,               // keep readable
                        'document'            // explicitly document mode
                    );

                    if (!$uploadResult) {
                        echo json_encode([
                            'status'  => 'error',
                            'message' => "Failed to upload {$label}"
                        ]);
                        return;
                    }

                    $documentUrls[$label]  = $uploadResult['document']  ?? '';
                    $thumbnailUrls[$label] = $uploadResult['thumbnail'] ?? '';
                }
            }


            /* ===============================
            STUDENT PHOTO
            ================================= */

            if (empty($_FILES['student_photo']['tmp_name'])) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Student photo required'
                ]);
                return;
            }

            $photo = $_FILES['student_photo'];
            $ext   = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Only JPG, JPEG, PNG or WEBP allowed'
                ]);
                return;
            }

            $photoUpload = $this->uploadStudentFile(
                $photo,
                $schoolName,
                $combinedClassPath,   // ✅ no session
                $studentId,
                'profile',            // label
                'profile'             // ✅ PROFILE MODE → different folder
            );

            if (!$photoUpload) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Photo upload failed'
                ]);
                return;
            }



            // $photoUrl = $photoUpload['document'];


            /* ===============================
           FORMAT DATES
            =============================== */
            $formattedDOB = '';
            if (!empty($normalizedPostData['dob'])) {
                $formattedDOB = date('d-m-Y', strtotime($normalizedPostData['dob']));
            }

            $formattedAdmission = '';
            if (!empty($normalizedPostData['admission_date'])) {
                $formattedAdmission = date('d-m-Y', strtotime($normalizedPostData['admission_date']));
            }

            $preMarks = trim($normalizedPostData['pre_marks'] ?? '');
            if ($preMarks !== '' && substr($preMarks, -1) !== '%') {
                $preMarks = $preMarks . '%';
            }


            /* ===============================
           STUDENT DATA
            =============================== */
            $studentData = [

                "Name"           => $studentName,
                "User Id"        => $studentId,
                "DOB"            => $formattedDOB,
                "Admission Date" => $formattedAdmission,

                "Class"          => $className,
                "Section"        => $section,

                "Phone Number"   => $phoneNumber,
                "Email"          => $normalizedPostData['email'] ?? '',
                // "Password"       => substr($studentName, 0, 3) . '123@',
                "Password" => $this->generatePassword($studentName, $formattedDOB),


                "Category"       => $normalizedPostData['category'] ?? '',
                "Gender"         => $normalizedPostData['gender'] ?? '',
                "Blood Group"    => $normalizedPostData['blood_group'] ?? '',
                "Religion"       => $normalizedPostData['religion'] ?? '',
                "Nationality"    => $normalizedPostData['nationality'] ?? '',

                "Father Name"        => $normalizedPostData['father_name'] ?? '',
                "Father Occupation"  => $normalizedPostData['father_occupation'] ?? '',
                "Mother Name"        => $normalizedPostData['mother_name'] ?? '',
                "Mother Occupation"  => $normalizedPostData['mother_occupation'] ?? '',
                "Guard Contact"      => $normalizedPostData['guard_contact'] ?? '',
                "Guard Relation"     => $normalizedPostData['guard_relation'] ?? '',

                "Pre Class"  => $normalizedPostData['pre_class'] ?? '',
                "Pre School" => $normalizedPostData['pre_school'] ?? '',
                "Pre Marks"  => $preMarks,

                "Address" => [
                    "Street"     => $normalizedPostData['street'] ?? '',
                    "City"       => $normalizedPostData['city'] ?? '',
                    "State"      => $normalizedPostData['state'] ?? '',
                    "PostalCode" => $normalizedPostData['postal_code'] ?? ''
                ],

                "Profile Pic" => $photoUpload['document'],


                "Doc" => [
                    'Birth Certificate'    => ['url' => $documentUrls['Birth Certificate']    ?? '', 'thumbnail' => $thumbnailUrls['Birth Certificate']    ?? ''],
                    'Aadhar Card'          => ['url' => $documentUrls['Aadhar Card']          ?? '', 'thumbnail' => $thumbnailUrls['Aadhar Card']          ?? ''],
                    'Transfer Certificate' => ['url' => $documentUrls['Transfer Certificate'] ?? '', 'thumbnail' => $thumbnailUrls['Transfer Certificate'] ?? ''],
                    'Photo'                => ['url' => $photoUpload['document']               ?? '', 'thumbnail' => $photoUpload['thumbnail']               ?? '']
                ]

            ];


            /* ===============================
           SAVE STUDENT
            =============================== */
            $studentPath = "Users/Parents/{$school_id}/{$studentId}";
            $result = $this->firebase->set($studentPath, $studentData);

            if (!$result) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to save student']);
                return;
            }


            $this->CM->addKey_pair_data("Schools/{$schoolName}/{$session_year}/{$combinedClassPath}/Students/", [
                $studentId => ['Name' => $studentName]
            ]);

            // ✅ Add to class roster List (simple key:value)
            $this->CM->addKey_pair_data(
                "Schools/{$school_name}/{$session_year}/{$combinedClassPath}/Students/List/",
                [$studentId => $studentName]
            );

            /* ===============================
            FETCH & ASSIGN SUBJECTS
            =============================== */
            // Extract class number from className (e.g., "8th" → 8)
            preg_match('/\d+/', $className, $classMatch);
            $classNumber = isset($classMatch[0]) ? (int)$classMatch[0] : 0;

            if ($classNumber > 0) {

                $rawList = $this->firebase->get(
                    "Schools/{$schoolName}/Subject_list/{$classNumber}"
                );

                if (defined('GRADER_DEBUG') && GRADER_DEBUG) log_message('debug', 'Raw subject list (admission): ' . json_encode($rawList));

                $coreSubjects = [];
                $allSubjects  = [];

                if (is_array($rawList)) {

                    foreach ($rawList as $code => $item) {

                        if (!is_array($item)) continue;

                        $subName = trim($item['subject_name'] ?? $item['name'] ?? '');
                        if ($subName === '') continue;

                        $type = strtolower(trim($item['category'] ?? ''));
                        // ⭐ Skip additional subjects from All Subjects path
                        if ($type !== 'additional') {
                            $allSubjects[(string)$code] = $subName;
                        }

                        // // ⭐ ALL subjects → All Subjects path
                        // $allSubjects[(string)$code] = $subName;

                        // ⭐ Only CORE → student's Subjects node
                        if ($type === 'core') {
                            $coreSubjects[(string)$code] = [
                                'name' => $subName,
                                'type' => 'core'
                            ];
                        }
                    }
                }

                if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                    log_message('debug', 'Core subjects (admission): ' . json_encode($coreSubjects));
                    log_message('debug', 'All subjects (admission): ' . json_encode($allSubjects));
                }

                // ✅ Insert ALL subjects to class path
                if (!empty($allSubjects)) {
                    $this->firebase->set(
                        "Schools/{$schoolName}/{$session_year}/{$classNameRaw}/All Subjects",
                        $allSubjects
                    );
                }

                // ✅ Assign core subjects to student
                if (!empty($coreSubjects)) {
                    $this->firebase->set(
                        "Users/Parents/{$school_id}/{$studentId}/Subjects",
                        $coreSubjects
                    );
                }
            }

            /* ===============================
           ADDITIONAL SUBJECTS ✅
            =============================== */
            $additionalSubjects = [];
            if (!empty($normalizedPostData['additional_subjects'])) {
                foreach ($normalizedPostData['additional_subjects'] as $sub) {
                    $additionalSubjects[$sub] = "";
                }

                $subjectsPath = "Schools/{$schoolName}/{$session_year}/{$combinedClassPath}/Students/{$studentId}/Additional Subjects";
                $this->firebase->set($subjectsPath, $additionalSubjects);
            }

            /* ===============================
           MONTH FEE
            =============================== */
            $monthFee = [
                'January' => 0,
                'February' => 0,
                'March' => 0,
                'April' => 0,
                'May' => 0,
                'June' => 0,
                'July' => 0,
                'August' => 0,
                'September' => 0,
                'October' => 0,
                'November' => 0,
                'December' => 0,
                'Yearly Fees' => 0
            ];
            $monthFeePath = "Schools/{$schoolName}/{$session_year}/{$combinedClassPath}/Students/{$studentId}/Month Fee";
            $this->firebase->set($monthFeePath, $monthFee);


            /* ===============================
            SAVE EXEMPTED FEES
            ================================= */
            if (
                isset($normalizedPostData['exempted_fees_multiple']) &&
                is_array($normalizedPostData['exempted_fees_multiple'])
            ) {
                $exemptedFeesData = [];

                foreach ($normalizedPostData['exempted_fees_multiple'] as $feeName) {
                    $feeName = trim($feeName);
                    if ($feeName !== '') {
                        // ✅ FIX: store original key (no sanitisation)
                        // Firebase allows spaces — only . # $ [ ] / are banned
                        $exemptedFeesData[$feeName] = "";
                    }
                }

                $exemptedFeesPath =
                    "Schools/{$schoolName}/{$session_year}/{$combinedClassPath}/Students/{$studentId}/Exempted Fees";

                $this->firebase->set($exemptedFeesPath, $exemptedFeesData);
            }





            /* ===============================
            FINAL MAPPINGS
            =============================== */
            // Tenant-scoped phone index (primary)
            $this->CM->addKey_pair_data("Schools/{$schoolName}/Phone_Index/", [$phoneNumber => $studentId]);
            // Legacy global indexes — kept for mobile app backward compatibility
            $this->CM->addKey_pair_data('Exits/', [$phoneNumber => $school_id]);
            $this->CM->addKey_pair_data('User_ids_pno/', [$phoneNumber => $studentId]);
            $this->CM->addKey_pair_data("Users/Parents/{$school_id}/", ['Count' => $studentIdCount + 1]); //(Working correctly)

            // $this->CM->addKey_pair_data("Schools/{$schoolName}/{$session_year}/{$combinedClassPath}/Students/List/", [
            //     $studentId => $studentName //(Working correctly)
            // ]);



            echo json_encode(['status' => 'success', 'message' => 'Student admission successful']);
            return;
        }

        $this->load->view('include/header');
        $this->load->view('studentAdmission', $data);
        $this->load->view('include/footer');
    }
    
    private function generatePassword($name, $dob)
    {
        $cleanName = preg_replace('/[^a-zA-Z]/', '', $name);
        $prefix = strtolower(substr($cleanName, 0, 3));

        $dobPart = preg_replace('/[^0-9]/', '', $dob);
        $suffix  = substr($dobPart, 0, 4);

        return ucfirst($prefix) . $suffix . '@';
    }


    private function generatePdfThumbnail($pdfTmpPath, $storagePath, $label, $timestamp, $random)
    {
        // ========== IMAGICK ==========
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

        // ========== FALLBACK PNG ==========
        $placeholder = FCPATH . 'tools/image/pdf.png';

        if (file_exists($placeholder)) {

            $thumbPath = $storagePath . "thumbnail/{$label}_{$timestamp}_{$random}.png";

            if ($this->firebase->uploadFile($placeholder, $thumbPath) === true) {
                return $this->firebase->getDownloadUrl($thumbPath);
            }
        }

        return '';
    }

    private function uploadStudentFile($file, $schoolName, $combinedClassPath, $studentId, $folderLabel, $type = 'document')
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $timestamp = time();
        $random    = substr(md5(uniqid()), 0, 6);

        $safeLabel = str_replace([' ', '.', '#', '$', '[', ']'], '_', $folderLabel);
        $fileName  = "{$safeLabel}_{$timestamp}_{$random}.{$ext}";

        // Base path (no session year — already included in combinedClassPath via caller)
        $basePath = "{$schoolName}/Students/{$combinedClassPath}/{$studentId}/";

        if ($type === 'profile') {
            $documentPath = $basePath . "Profile_pic/{$fileName}";
        } else {
            $documentPath = $basePath . "Documents/{$fileName}";
        }

        // Upload main file
        if ($this->firebase->uploadFile($file['tmp_name'], $documentPath) !== true) {
            return false;
        }

        $documentUrl  = $this->firebase->getDownloadUrl($documentPath);
        $thumbnailUrl = '';

        // ── IMAGE THUMBNAIL (document mode) ──────────────────────
        if ($type === 'document' && in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $thumbPath = $basePath . "Documents/thumbnail/{$fileName}";
            if ($this->firebase->uploadFile($file['tmp_name'], $thumbPath) === true) {
                $thumbnailUrl = $this->firebase->getDownloadUrl($thumbPath);
            }
        }

        // ── PDF THUMBNAIL (document mode) ─────────────────────────
        if ($type === 'document' && $ext === 'pdf') {
            $thumbnailUrl = $this->generatePdfThumbnail(
                $file['tmp_name'],
                $basePath . "Documents/",
                $safeLabel,
                $timestamp,
                $random
            );
        }

        // ── PROFILE PHOTO THUMBNAIL ───────────────────────────────
        // FIX: profile photos are always images — generate thumbnail
        // by uploading the same file into Profile_pic/thumbnail/
        if ($type === 'profile' && in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $thumbPath = $basePath . "Profile_pic/thumbnail/{$fileName}";
            if ($this->firebase->uploadFile($file['tmp_name'], $thumbPath) === true) {
                $thumbnailUrl = $this->firebase->getDownloadUrl($thumbPath);
            }
        }

        return [
            'document'  => $documentUrl,
            'thumbnail' => $thumbnailUrl,
        ];
    }

    public function get_classes()
    {
        $this->_require_role(self::VIEW_ROLES);
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        // Shallow fetch — reads only top-level key names, not the full subtree
        $keys = $this->firebase->shallow_get("Schools/{$school_name}/{$session_year}");

        $classes = array_values(array_filter($keys, function ($k) {
            return strpos($k, 'Class ') === 0;
        }));

        header('Content-Type: application/json');
        echo json_encode($classes);
    }

    public function get_sections_by_class()
    {
        $this->_require_role(self::VIEW_ROLES);
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        // Read from $_POST (not php://input) so CI CSRF filter can validate the token
        $className = trim((string)$this->input->post('class_name'));

        if ($className === '') {
            header('Content-Type: application/json');
            echo json_encode([]);
            return;
        }

        $className = $this->safe_path_segment($className, 'class_name');
        // Shallow fetch — reads only section key names under the class node
        $keys = $this->firebase->shallow_get("Schools/{$school_name}/{$session_year}/{$className}");

        $sections = [];
        foreach ($keys as $key) {
            if (strpos($key, 'Section ') === 0) {
                $sections[] = str_replace('Section ', '', $key);
            }
        }

        header('Content-Type: application/json');
        echo json_encode($sections);
    }


    public function fetch_subjects()
    {
        $this->_require_role(self::VIEW_ROLES);
        header('Content-Type: application/json');

        $school_name = $this->school_name;

        // Use CI input to benefit from CSRF check; fall back to raw JSON body
        $rawClass = trim((string) $this->input->post('class_name'));
        if ($rawClass === '') {
            $input = json_decode(file_get_contents('php://input'), true);
            $rawClass = trim($input['class_name'] ?? '');
        }

        if ($rawClass === '') {
            echo json_encode([]);
            return;
        }

        // Extract numeric class (Class 8th → 8)
        if (preg_match('/\d+/', $rawClass, $m)) {
            $classKey = (int)$m[0];
        } else {
            echo json_encode([]);
            return;
        }

        $path = "Schools/{$school_name}/Subject_list/{$classKey}";
        $subjectData = $this->CM->get_data($path);

        $subjects = [];

        if (is_array($subjectData)) {
            foreach ($subjectData as $code => $item) {

                if (!is_array($item)) continue;

                $category = strtolower(trim($item['category'] ?? ''));
                $name     = trim($item['subject_name'] ?? $item['name'] ?? '');

                if ($name === '') continue;

                // ✅ ONLY Additional + Skill-Based
                if (in_array($category, ['additional', 'skill-based'], true)) {
                    $subjects[] = $name;
                }
            }
        }

        echo json_encode(array_values(array_unique($subjects)));
    }


    private function add_student($data)
    {

        $school_id = $this->parent_db_key;
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Define required fields
        $requiredFields = [
            'User Id',
            'Name',
            'Father Name',
            'Mother Name',
            'Email',
            'DOB',
            'Phone Number',
            'Gender',
            'School Name',
            'Class',
            'Section',
            'Address',
            'Password'
        ];

        // Check for missing fields
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            echo 'Error: Required fields missing - ' . implode(', ', $missingFields);
            return;
        }

        // Validate phone number
        $phoneNumber = $data['Phone Number'];
        if (!preg_match('/^[6789]\d{9}$/', $phoneNumber)) {
            echo 'Error: Invalid phone number';
            return;
        }

        // Generate password based on student name
        $name = isset($data['Name']) ? $data['Name'] : '';
        if (!empty($name)) {
            $password = substr($name, 0, 3) . '123@';
            $data['Password'] = $password;
        } else {
            $data['Password'] = '';
        }

        // Check if password is empty
        if (empty($data['Password'])) {
            echo 'Error: Password field cannot be empty';
            return;
        }

        // Extract the ordinal part from the class name (e.g., 8th)
        preg_match('/\b\d+(st|nd|rd|th)\b/', $data['Class'], $matches);
        $ordinalPart = !empty($matches) ? $matches[0] : $data['Class'];

        // Store only the ordinal part in Users->Parents->1111->StudentId
        $data['Class'] = $ordinalPart;

        // Fetch the current count from Firebase for students (tenant-scoped)
        $currentCount = $this->CM->get_data("Users/Parents/{$school_id}/Count");
        if ($currentCount === null) {
            $currentCount = 1; // Initialize count if it doesn't exist
        }

        // Set the new student ID as string
        $userId = $currentCount;
        $data['User Id'] = $userId;

        // Insert data into Firebase
        $result = $this->CM->insert_data('Users/Parents/' . $school_id . '/', $data);

        if ($result) {
            // Tenant-scoped phone index (primary)
            $this->CM->addKey_pair_data("Schools/{$school_name}/Phone_Index/", [$phoneNumber => $userId]);
            // Legacy global indexes — kept for mobile app backward compatibility
            $this->CM->addKey_pair_data('Exits/', [$phoneNumber => $school_id]);
            $this->CM->addKey_pair_data('User_ids_pno/', [$phoneNumber => $userId]);

            // Increment and update the count in Firebase (tenant-scoped)
            $newCount = $currentCount + 1;
            $this->CM->addKey_pair_data("Users/Parents/{$school_id}/", ['Count' => $newCount]);

            // Add student to the specific class and section
            $classSection = 'Class ' . $ordinalPart . " '" . $data['Section'] . "'";
            $this->CM->addKey_pair_data("Schools/{$school_name}/{$session_year}/$classSection/Students/", [$userId => ['Name' => $data['Name']]]);

            // Add student to the List key inside the School->SchoolName->List
            $this->CM->addKey_pair_data("Schools/{$school_name}/{$session_year}/$classSection/Students/List/", [$userId => $data['Name']]);

            echo "1"; // If data is inserted, echo 1
        } else {
            echo "0"; // If data is not inserted, echo 0
        }
    }


    public function delete_student($id)
    {
        $this->_require_role(self::MANAGE_ROLES);
        if ($this->input->method() !== 'post') {
            redirect('student/all_student');
            return;
        }
        if (empty($id) || !preg_match('/^[A-Za-z0-9_]+$/', $id)) {
            redirect('student/all_student');
            return;
        }
        $school_id = $this->parent_db_key;
        $school_name = $this->school_name;
        $session_year = $this->session_year;
        $studentPath = "Users/Parents/{$school_id}/{$id}";
        $student = $this->CM->select_data($studentPath);
        if (!$student) {
            redirect('student/all_student');
            return;
        }
        $phoneNumber = $student['Phone Number'] ?? '';
        $class = $student['Class'] ?? '';
        $section = $student['Section'] ?? '';
        if (!$class || !$section) {
            $this->session->set_flashdata('error', 'Class or Section missing');
            redirect('student/all_student');
            return;
        }
        $combinedClassPath = "Class {$class}/Section {$section}"; /* 🔥 DELETE STORAGE */

        $storagePath = "{$school_name}/Students/Class {$class}/{$id}";
        $this->CM->delete_folder_from_firebase_storage($storagePath); /* 🔥 DELETE FROM REALTIME DB */

        $this->CM->delete_data("Schools/{$school_name}/{$session_year}/{$combinedClassPath}/Students", $id);
        $this->CM->delete_data("Schools/{$school_name}/{$session_year}/{$combinedClassPath}/Students/List", $id); /* 🔥 DELETE PHONE MAPPINGS */
        if (!empty($phoneNumber)) {
            // Remove from tenant-scoped phone index
            $this->CM->delete_data("Schools/{$school_name}/Phone_Index", $phoneNumber);
            // Remove legacy global indexes
            $this->CM->delete_data('User_ids_pno', $phoneNumber);
            $this->CM->delete_data('Exits', $phoneNumber);
        }
        $this->session->set_flashdata('success', 'Student removed from class successfully');
        redirect('student/all_student');
    }



    public function edit_student($userId)
    {
        $this->_require_role(self::MANAGE_ROLES);
        if (empty($userId) || !preg_match('/^[A-Za-z0-9_]+$/', $userId)) {
            show_404();
            return;
        }

        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $studentPath = "Users/Parents/{$school_id}/{$userId}";
        $existing    = $this->CM->select_data($studentPath);

        if (!$existing) {
            show_404();
            return;
        }

        $classKey          = trim($existing['Class']);
        $sectionKey        = trim($existing['Section']);
        $combinedClassPath = "Class {$classKey}/Section {$sectionKey}";

        /* ===================== GET MODE ===================== */
        if ($this->input->method() !== 'post') {

            /* Fetch selected additional subjects */
            $additionalPath =
                "Schools/$school_name/$session_year/$combinedClassPath/Students/$userId/Additional Subjects";
            $data['additional_subjects'] = $this->firebase->get($additionalPath) ?? [];


            /* ── Fetch selected exempted fees ──── */
            $exemptedPath =
                "Schools/$school_name/$session_year/$combinedClassPath/Students/$userId/Exempted Fees";

            $rawFees = $this->firebase->get($exemptedPath);
            $data['selected_exempted_fees'] = is_array($rawFees) ? $rawFees : [];


            /* Fetch fees structure for checkboxes */
            $feesStructurePath =
                "Schools/$school_name/$session_year/Accounts/Fees/Fees Structure";
            $data['exemptedFees'] = $this->firebase->get($feesStructurePath);

            /* ── Fetch all available additional subjects ──
               FIX: use $classNumKey (not $classKey) so $classKey / 
               $combinedClassPath are not accidentally overwritten.
            ── */
            $classNumKey = null;
            if (preg_match('/\d+/', $existing['Class'], $m)) {
                $classNumKey = (int)$m[0];
            }

            $allSubjects = [];
            if ($classNumKey) {
                $subjectPath = "Schools/$school_name/Subject_list/$classNumKey";
                $subjectData = $this->CM->get_data($subjectPath);

                if (is_array($subjectData)) {
                    foreach ($subjectData as $code => $item) {
                        if (!is_array($item)) continue;
                        $category = strtolower(trim($item['category'] ?? ''));
                        $name     = trim($item['subject_name'] ?? $item['name'] ?? '');
                        if ($name === '') continue;
                        if (in_array($category, ['additional', 'skill-based'], true)) {
                            $allSubjects[] = $name;
                        }
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


        /* ===================== POST MODE ===================== */
        header('Content-Type: application/json');

        $post = $this->input->post();

        /* ── Dates ── */
        $dob           = !empty($post['dob'])            ? trim($post['dob'])            : ($existing['DOB']            ?? '');
        $admissionDate = !empty($post['admission_date']) ? trim($post['admission_date']) : ($existing['Admission Date'] ?? '');

        /* ── Religion ── */
        $religion = $post['religion'] ?? ($existing['Religion'] ?? '');
        if ($religion === 'Other' && !empty($post['other_religion'])) {
            $religion = trim($post['other_religion']);
        }

        $preMarks = trim($post['pre_marks'] ?? '');
        if ($preMarks !== '' && substr($preMarks, -1) !== '%') {
            $preMarks = $preMarks . '%';
        }

        /* ── Build update payload ── */
        $updateData = [
            "Name"              => $post['Name']              ?? ($existing['Name']              ?? ''),
            "DOB"               => $dob,
            "Admission Date"    => $admissionDate,
            "Phone Number"      => $post['phone_number']      ?? ($existing['Phone Number']      ?? ''),
            "Email"             => $post['email']             ?? ($existing['Email']             ?? ''),
            "Gender"            => $post['gender']            ?? ($existing['Gender']            ?? ''),
            "Category"          => $post['category']          ?? ($existing['Category']          ?? ''),
            "Blood Group"       => $post['blood_group']       ?? ($existing['Blood Group']       ?? ''),
            "Religion"          => $religion,
            "Nationality"       => $post['nationality']       ?? ($existing['Nationality']       ?? ''),
            "Father Name"       => $post['father_name']       ?? ($existing['Father Name']       ?? ''),
            "Father Occupation" => $post['father_occupation'] ?? ($existing['Father Occupation'] ?? ''),
            "Mother Name"       => $post['mother_name']       ?? ($existing['Mother Name']       ?? ''),
            "Mother Occupation" => $post['mother_occupation'] ?? ($existing['Mother Occupation'] ?? ''),
            "Guard Contact"     => $post['guard_contact']     ?? ($existing['Guard Contact']     ?? ''),
            "Guard Relation"    => $post['guard_relation']    ?? ($existing['Guard Relation']    ?? ''),
            "Pre Class"         => $post['pre_class']         ?? ($existing['Pre Class']         ?? ''),
            "Pre School"        => $post['pre_school']        ?? ($existing['Pre School']        ?? ''),
            "Pre Marks"         => $preMarks !== '' ? $preMarks : ($existing['Pre Marks'] ?? ''),
            "Address"           => [
                "Street"     => $post['street']      ?? ($existing['Address']['Street']     ?? ''),
                "City"       => $post['city']        ?? ($existing['Address']['City']       ?? ''),
                "State"      => $post['state']       ?? ($existing['Address']['State']      ?? ''),
                "PostalCode" => $post['postal_code'] ?? ($existing['Address']['PostalCode'] ?? ''),
            ],


            "Class"    => $existing["Class"],   // ← inside the array
            "Section"  => $existing["Section"],
            "User Id"  => $existing["User Id"],
            "Password" => $existing["Password"] ?? '',
        ];  // ← array closes cleanly here

        // Profile Pic and Doc set separately after (correct)
        $updateData["Profile Pic"] = $existing["Profile Pic"] ?? '';


        /* ── Carry forward Doc node, normalising any legacy flat strings ──
           Students admitted before thumbnail support have flat URL strings.
           Normalise them now so the view's getDocUrls() always gets an array.
        ── */
        $existingDoc = is_array($existing["Doc"] ?? null) ? $existing["Doc"] : [];

        foreach (['Birth Certificate', 'Aadhar Card', 'Transfer Certificate', 'Photo'] as $docKey) {
            if (isset($existingDoc[$docKey]) && !is_array($existingDoc[$docKey])) {
                $existingDoc[$docKey] = [
                    'url'       => (string)$existingDoc[$docKey],
                    'thumbnail' => ''
                ];
            }
        }

        $updateData["Doc"] = $existingDoc;

        /* ===================== DOCUMENT RE-UPLOAD =====================*/

        $documents = [
            'birthCertificate'    => 'Birth Certificate',
            'aadharCard'          => 'Aadhar Card',
            'transferCertificate' => 'Transfer Certificate',
        ];

        foreach ($documents as $inputKey => $label) {

            if (empty($_FILES[$inputKey]['tmp_name'])) {
                continue; // no new file — keep existing
            }

            /* ── Delete old file + thumbnail from Storage ── */
            $oldDoc = $existingDoc[$label] ?? [];
            $this->deleteOldStorageFile($oldDoc);

            $uploadResult = $this->uploadStudentFile(
                $_FILES[$inputKey],
                $school_name,
                $combinedClassPath,
                $userId,
                $label,      // readable label → used for filename
                'document'   // type → stores in Documents/ + generates thumbnail
            );

            if ($uploadResult) {
                // Replace only this entry — other docs untouched
                $updateData["Doc"][$label] = [
                    'url'       => $uploadResult['document']  ?? '',
                    'thumbnail' => $uploadResult['thumbnail'] ?? ''
                ];
            }
            // On upload failure: silently keep existing doc (no overwrite)
        }

        /* ===================== PHOTO REPLACE ===================== */
        $photoUpdated = false;

        if (!empty($_FILES['student_photo']['tmp_name'])) {

            $photo = $_FILES['student_photo'];
            $ext   = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));

            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {

                /* ── Delete old profile photo + thumbnail from Storage ── */
                $oldPhotoDoc = $existingDoc['Photo'] ?? [];
                $this->deleteOldStorageFile($oldPhotoDoc);

                /* ── Upload new photo ── */
                $photoResult = $this->uploadStudentFile(
                    $photo,
                    $school_name,
                    $combinedClassPath,
                    $userId,
                    'profile',  // folderLabel
                    'profile'   // type → Profile_pic/ + thumbnail
                );

                if ($photoResult) {
                    $updateData["Profile Pic"]  = $photoResult['document'];
                    $updateData["Doc"]["Photo"] = [
                        'url'       => $photoResult['document']  ?? '',
                        'thumbnail' => $photoResult['thumbnail'] ?? ''
                    ];
                    $photoUpdated = true;
                }
            }
        }

        /* ===================== SAVE STUDENT ===================== */

        // $this->CM->update_data("Users/Parents/$school_id", $userId, $updateData);
        $this->firebase->set("Users/Parents/$school_id/$userId", $updateData);

        /* ===================== SAVE ADDITIONAL SUBJECTS ===================== */
        $additionalSubjects = [];

        if (!empty($post['additional_subjects']) && is_array($post['additional_subjects'])) {
            foreach ($post['additional_subjects'] as $sub) {
                $sub = trim($sub);
                if ($sub !== '') {
                    $additionalSubjects[$sub] = "";
                }
            }
        }

        // Always overwrite — prevents leftover subjects from previous edits
        $this->firebase->set(
            "Schools/$school_name/$session_year/$combinedClassPath/Students/$userId/Additional Subjects",
            $additionalSubjects
        );

        /* ===================== SAVE EXEMPTED FEES ===================== */
        $exemptedFeesData = [];

        if (!empty($post['exempted_fees_multiple']) && is_array($post['exempted_fees_multiple'])) {
            foreach ($post['exempted_fees_multiple'] as $fee) {
                $fee = trim($fee);
                if ($fee !== '') {
                    // ✅ FIX: store original key (no sanitisation)
                    $exemptedFeesData[$fee] = "";
                }
            }
        }

        // Always overwrite — prevents leftover old fees
        $this->firebase->set(
            "Schools/$school_name/$session_year/$combinedClassPath/Students/$userId/Exempted Fees",
            $exemptedFeesData
        );

        /* ===================== RESPONSE ===================== */
        $response = [
            'status'  => 'success',
            'message' => 'Student updated successfully'
        ];

        if ($photoUpdated) {
            $response['photo_notice'] = 'Profile photo updated with thumbnail.';
        }

        echo json_encode($response);
    }


    public function download_document()
    {
        $this->_require_role(self::VIEW_ROLES);
        $fileUrl = $this->input->get('file', TRUE);

        if (empty($fileUrl)) {
            show_error("Invalid file URL.", 400);
            return;
        }

        // ✅ Validate URL format
        if (!filter_var($fileUrl, FILTER_VALIDATE_URL)) {
            show_error("Invalid URL.", 400);
            return;
        }

        $parts = parse_url($fileUrl);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            show_error("Malformed URL.", 400);
            return;
        }

        // ✅ Allow only HTTPS
        if ($parts['scheme'] !== 'https') {
            show_error("Only HTTPS allowed.", 403);
            return;
        }

        // ✅ Strict host whitelist
        $allowedHosts = [
            'firebasestorage.googleapis.com',
            'storage.googleapis.com'
        ];

        if (!in_array($parts['host'], $allowedHosts, true)) {
            show_error("Access denied.", 403);
            return;
        }

        // ✅ Prevent access to internal IPs (extra protection)
        $ip = gethostbyname($parts['host']);

        if (
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            show_error("Invalid host.", 403);
            return;
        }

        // ✅ Get filename safely — sanitize for Content-Disposition header
        $fileName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($parts['path']));

        // ✅ Stream download (no memory overload)
        $ch = curl_init($fileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');

        curl_exec($ch);

        if (curl_errno($ch)) {
            curl_close($ch);
            show_error("Download failed.", 500);
            return;
        }

        curl_close($ch);
    }

    private function deleteOldStorageFile($docNode)
    {
        /* Normalise to array */
        if (!is_array($docNode)) {
            $docNode = ['url' => (string)$docNode, 'thumbnail' => ''];
        }

        $mainUrl      = $docNode['url']       ?? '';
        $thumbnailUrl = $docNode['thumbnail'] ?? '';

        /* Delete main file */
        if (!empty($mainUrl)) {
            $path = $this->extractStoragePathFromUrl($mainUrl);
            if ($path) {
                $this->CM->delete_file_from_firebase($path);
            }
        }

        /* Delete thumbnail file */
        if (!empty($thumbnailUrl)) {
            $path = $this->extractStoragePathFromUrl($thumbnailUrl);
            if ($path) {
                $this->CM->delete_file_from_firebase($path);
            }
        }
    }

    private function extractStoragePathFromUrl($url)
    {
        if (empty($url)) return '';

        /* Extract the encoded path between /o/ and ?alt=media */
        if (preg_match('#/o/([^?]+)#', $url, $matches)) {
            return urldecode($matches[1]);
        }

        return '';
    }


    public function student_profile($userId)
    {
        $this->_require_role(self::VIEW_ROLES);
        if (empty($userId) || !preg_match('/^[A-Za-z0-9_]+$/', $userId)) {
            show_404();
            return;
        }

        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        /* ===============================
           FETCH STUDENT DATA
        =============================== */
        $firebasePath = "Users/Parents/{$school_id}/{$userId}";
        $studentData  = $this->firebase->get($firebasePath);

        if (!$studentData) {
            show_error("Student not found");
            return;
        }

        /* ===============================
           GET CLASS & SECTION
        =============================== */
        $class   = $studentData['Class']   ?? '';
        $section = $studentData['Section'] ?? '';

        $classPath = "Class " . $class;
        $basePath  = "Schools/$school_name/$session_year/$classPath/Section $section";

        /* ===============================
           FETCH SUBJECTS
        =============================== */
        $subjectsList = [];

        if (!empty($class)) {
            $classNumber = preg_replace('/[^0-9]/', '', $class);
            $subjectPath = "Schools/$school_name/Subject_list/$classNumber";
            $subjects    = $this->firebase->get($subjectPath);

            if (!empty($subjects) && is_array($subjects)) {
                foreach ($subjects as $subjectCode => $subjectData) {
                    $sn = $subjectData['subject_name'] ?? $subjectData['name'] ?? '';
                    if ($sn !== '') {
                        $subjectsList[] = $sn;
                    }
                }
            }
        }

        /* ===============================
           FETCH ADDITIONAL SUBJECTS
        =============================== */
        $additionalSubjectsPath = "$basePath/Students/$userId/Additional Subjects";
        $additionalSubjects     = $this->firebase->get($additionalSubjectsPath);
        $additionalSubjectsList = array_keys($additionalSubjects ?? []);

        /* ===============================
           FINAL SUBJECT LIST
        =============================== */
        $finalSubjectsList = array_unique(array_merge($subjectsList, $additionalSubjectsList));

        /* ===============================
           FETCH EXEMPTED FEES
           FIX: was never fetched before — view showed nothing
        =============================== */
        $exemptedFeesPath = "$basePath/Students/$userId/Exempted Fees";
        $rawExempted      = $this->firebase->get($exemptedFeesPath);
        // Returns ['Bus Fees' => '', 'Tuition Fee' => ''] or null
        $exemptedFees = is_array($rawExempted) ? $rawExempted : [];

        /* ===============================
           FETCH DISCOUNT
           Controller previously only fetched totalDiscount.
           Now fetches both currentDiscount and totalDiscount.
        =============================== */
        $discountBasePath = "$basePath/Students/$userId/Discount";
        $discountData     = $this->firebase->get($discountBasePath);

        // totalDiscount — running sum of all discounts ever applied
        $totalDiscount   = isset($discountData['totalDiscount'])   ? (float)$discountData['totalDiscount']   : 0;
        // currentDiscount — the most recent single discount applied
        $currentDiscount = isset($discountData['currentDiscount']) ? (float)$discountData['currentDiscount'] : 0;

        /* ===============================
           FETCH FEES
        =============================== */
        // Pass $class (e.g. "8th") NOT $classPath ("Class 8th"):
        // getFees() builds "Accounts/Fees/Classes Fees/{class} '{section}'"
        // and the Firebase key is "8th 'A'", not "Class 8th 'A'".
        $feesJson = $this->getFees($class, $section);
        $feesData = json_decode($feesJson, true);

        /* ===============================
           SEND DATA TO VIEW
        =============================== */
        $data = [
            'student'          => $studentData,
            'class'            => $class,
            'section'          => $section,
            'fees'             => $feesData['fees']          ?? null,
            'monthlyTotals'    => $feesData['monthlyTotals'] ?? null,
            'overallTotal'     => $feesData['overallTotal']  ?? null,
            'subjects'         => $finalSubjectsList,

            /* ── Discount ── */
            'discount'         => $totalDiscount,    // keep for backward compat
            'totaldiscount'    => $totalDiscount,    // view uses $totaldiscount
            'currentdiscount'  => $currentDiscount,  // view uses $currentdiscount

            /* ── Exempted fees ── NEW — was missing before */
            'exempted_fees'    => $exemptedFees,     // ['Bus Fees' => '', ...]
        ];

        $this->load->view('include/header');
        $this->load->view('student_profile', $data);
        $this->load->view('include/footer');
    }


    private function getFees($className, $section)
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        $path = "Schools/{$school_name}/{$session_year}/Accounts/Fees/Classes Fees/{$className} '{$section}'";

        // Fetching data from Firebase
        $feesData = $this->CM->get_data($path);
        // log_message('debug', 'Raw Fees Data Retrieved: ' . print_r($feesData, true));

        if ($feesData && !empty($feesData)) {
            // Ensure Yearly Fees key exists
            if (!isset($feesData['Yearly Fees']) || !is_array($feesData['Yearly Fees'])) {
                // Fetch fees structure
                $feesStructurePath = "Schools/$school_name/$session_year/Accounts/Fees/Fees Structure/Yearly";
                $feesStructure = $this->CM->get_data($feesStructurePath);

                if ($feesStructure && is_array($feesStructure)) {
                    // Create default Yearly Fees structure with 0 values
                    $yearlyFees = array_fill_keys(array_keys($feesStructure), 0);
                    $feesData['Yearly Fees'] = $yearlyFees;

                    // Save the updated fees data back to Firebase
                    $this->CM->addKey_pair_data($path, ['Yearly Fees' => $yearlyFees]);
                    log_message('info', 'Yearly Fees added for path: ' . $path);
                } else {
                    log_message('warning', 'Yearly Fees structure not found for path: ' . $feesStructurePath);
                }
            }

            // Structure the response
            $formattedFees = [];
            $monthlyTotals = [];

            foreach ($feesData as $month => $fees) {
                if (is_array($fees)) { // Ensure we are dealing with arrays
                    $formattedFees[$month] = $fees;

                    // Calculate row total
                    $rowTotal = array_sum($fees); // Sum all fee categories for the month
                    $monthlyTotals[$month] = $rowTotal; // Store monthly total
                } else {
                    log_message('warning', "Fees for month {$month} is not an array");
                }
            }

            // Add Overall Total
            $overallTotal = array_sum($monthlyTotals);




            return json_encode([
                "fees" => $formattedFees,
                "monthlyTotals" => $monthlyTotals,
                "overallTotal" => $overallTotal // Include overall total in response
            ]);
        } else {
            return json_encode(["fees" => [], "monthlyTotals" => []]); // Return empty if no data found
        }
    }

    public function attendance()
    {
        $this->_require_role(self::VIEW_ROLES);
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $basePath = "Schools/{$school_name}/{$session_year}";
        $data = $this->CM->select_data($basePath);

        $ClassesData = [];

        if (is_array($data)) {

            foreach ($data as $key => $value) {

                // Pick only Class nodes
                if (strpos($key, 'Class ') === 0 && is_array($value)) {

                    foreach ($value as $sectionKey => $sectionValue) {

                        // Pick only Section A, Section B etc
                        if (strpos($sectionKey, 'Section ') === 0) {

                            $ClassesData[] = [
                                'class_name' => $key,
                                'section' => str_replace('Section ', '', $sectionKey),
                            ];
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
        $session_year = $this->session_year;   // e.g. "2025-2026"

        $class   = $this->input->post('class');    // "Class 8th"
        $section = $this->input->post('section');  // "A"
        $month   = $this->input->post('month');    // "April"

        if (empty($class) || empty($section) || empty($month)) {
            echo json_encode(["error" => "Class, Section and Month are required"]);
            return;
        }

        /* ─────────────────────────────────────────────────────────
           BUG FIX 1: year resolution for Indian academic session
           date('Y') always returns the current calendar year.
           For a 2025-2026 session:
             April–December  → 2025  (session start year)
             January–March   → 2026  (session end year)
           Using date('Y') during Jan/Feb/March gives WRONG year
           → attendance stored under "January 2026" is never found.
        ───────────────────────────────────────────────────────── */
        $monthToNumber = [
            'January'   => 1,
            'February'  => 2,
            'March'     => 3,
            'April'     => 4,
            'May'        => 5,
            'June'      => 6,
            'July'      => 7,
            'August'     => 8,
            'September' => 9,
            'October'   => 10,
            'November'   => 11,
            'December'  => 12,
        ];

        $monthNumber = $monthToNumber[trim($month)] ?? 0;
        if ($monthNumber === 0) {
            echo json_encode(["error" => "Invalid month name."]);
            return;
        }

        // Parse "2025-2026" → startYear=2025, endYear=2026
        $sessionParts = explode('-', $session_year);
        $startYear    = (int)($sessionParts[0] ?? date('Y'));
        $endYear      = isset($sessionParts[1]) ? (int)$sessionParts[1] : $startYear + 1;

        // April(4)–December(12) use start year; Jan(1)–March(3) use end year
        $year = ($monthNumber >= 4) ? $startYear : $endYear;

        /* ─────────────────────────────────────────────────────────
           BUG FIX 2: use $this->firebase, not new Firebase()
           new Firebase() creates a second unauthenticated instance,
           bypasses the singleton, and may use stale/wrong credentials.
        ───────────────────────────────────────────────────────── */
        $class       = $this->safe_path_segment($class, 'class');
        $section     = $this->safe_path_segment($section, 'section');
        $sectionNode = "Section " . $section;
        $basePath    = "Schools/{$school_name}/{$session_year}/{$class}/{$sectionNode}/Students";

        $studentsListPath = "$basePath/List";
        $studentsList     = $this->firebase->get($studentsListPath);

        if (empty($studentsList) || !is_array($studentsList)) {
            echo json_encode(["error" => "No students found for this class/section."]);
            return;
        }

        /* ─────────────────────────────────────────────────────────
           BUG FIX 3: use $monthNumber directly, not strtotime()
           strtotime("April") returns false on many PHP configs
           because it is not a full parseable date string.
           cal_days_in_month and getSundays both need an integer.
        ───────────────────────────────────────────────────────── */
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNumber, $year);
        $sundays     = $this->getSundays($year, $monthNumber);

        $studentsData = [];

        foreach ($studentsList as $studentId => $studentName) {

            $attendancePath   = "$basePath/$studentId/Attendance/$month $year";
            $attendanceString = $this->firebase->get($attendancePath);

            // Default to all-Vacant if no record exists yet
            if (empty($attendanceString) || !is_string($attendanceString)) {
                $attendanceString = str_repeat('V', $daysInMonth);
            }

            $attendanceArray = str_split($attendanceString);
            // Pad in case stored string is shorter than days in month
            $attendanceArray = array_pad($attendanceArray, $daysInMonth, 'V');

            // studentName from List node may be a plain string or an object —
            // normalise to a display string
            $displayName = is_string($studentName)
                ? $studentName
                : ($studentName['Name'] ?? (string)$studentId);

            $studentsData[] = [
                "userId"     => $studentId,
                "name"       => $displayName,
                "attendance" => $attendanceArray,
            ];
        }

        echo json_encode([
            "students"    => $studentsData,
            "daysInMonth" => $daysInMonth,
            "sundays"     => $sundays,
            "month"       => $month,
            "year"        => $year,
        ]);
    }

    private function getSundays($year, $month)
    {
        $sundays = [];
        $date = new DateTime("$year-$month-01");

        while ($date->format('n') == $month) {
            if ($date->format('w') == 0) { // Sunday
                $sundays[] = (int)$date->format('j');
            }
            $date->modify('+1 day');
        }

        return $sundays;
    }
}
