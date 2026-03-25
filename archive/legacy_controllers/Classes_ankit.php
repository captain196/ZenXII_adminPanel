<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Classes extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function manage_classes()
    {
        $school_id = $this->school_id;
        $school_name = $this->school_name;
        $session_year = $this->session_year;


        if ($this->input->method() == 'post') {

            $schoolName = $this->input->post('school_name');
            $className = $this->input->post('class_name');
            $section = $this->input->post('section');
            $subjectsJson = $this->input->post('subjects');
            $additionalsubjectsJson = $this->input->post('additionalsubjects');
            $subjects = json_decode($subjectsJson, true) ?: [];
            $additionalsubjects = json_decode($additionalsubjectsJson, true) ?: [];
            $classIsNumeric = is_numeric($className);

            if (is_string($className) && strpos($className, 'Class') === false) {
                if ($classIsNumeric) {
                    $classNumber = filter_var($className, FILTER_SANITIZE_NUMBER_INT); // Extract numeric part
                    $suffix = '';
                    if ($classNumber % 10 == 1 && $classNumber % 100 != 11) {
                        $suffix = 'st';
                    } elseif ($classNumber % 10 == 2 && $classNumber % 100 != 12) {
                        $suffix = 'nd';
                    } elseif ($classNumber % 10 == 3 && $classNumber % 100 != 13) {
                        $suffix = 'rd';
                    } else {
                        $suffix = 'th';
                    }
                    $className = "Class {$classNumber}{$suffix}";
                } else {
                    $className = "Class {$className}";
                }
            }

            if (empty($section)) {
                $section = 'A';
            }

            $sections = explode(',', $section);
            $sectionsData = [];
            foreach ($sections as $section) {
                $sectionsData[trim($section)] = '';
            }

            $classKey = 'Schools/' . $schoolName . '/' . $session_year . '/Classes/' . $className;

            $existingData = $this->CM->select_data($classKey);
            $existingSections = isset($existingData['Section']) ? $existingData['Section'] : [];

            $updatedSections = array_merge($existingSections, $sectionsData);

            $classData = [

                'Section' => $updatedSections,
                'Time_stamp' => $classNumber ? (int) filter_var($className, FILTER_SANITIZE_NUMBER_INT) : $className
            ];

            $result1 = $this->CM->addKey_pair_data($classKey, $classData);

            $result2 = true;
            foreach ($sections as $section) {
                $key = $className . " '" . trim($section) . "'";
                $path = 'Schools/' . $schoolName . '/' . $session_year . '/' . $key;

                // Merge both lists into Subjects
                $mergedSubjects = array_merge($subjects, $additionalsubjects);

                // Data to store
                $data = [
                    'Subjects' => $mergedSubjects,  // Subjects list includes both subjects and additional subjects
                    'AdditionalSubjects' => $additionalsubjects  // Keeping record of additional subjects separately
                ];

                // Save both subjects and additional subjects
                $result = $this->CM->addKey_pair_data($path, $data);

                if (!$result) {
                    $result2 = false;  // Set result2 to false if any insert fails
                }
            }

            // Handle the timetable file upload
            if (isset($_FILES['timetable_file']) && $_FILES['timetable_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['timetable_file'];
                $folderName = $className . " '" . $section . "'";
                $uploadResult = $this->CM->handleFileUpload($file, $schoolName, $folderName, 'time_table', true);

                if ($uploadResult) {
                    $firebasePath = "Schools/$schoolName/$session_year/$className '$section'/";
                    $updateData = ['Time_table' => $uploadResult];
                    $updateResult = $this->CM->addKey_pair_data($firebasePath, $updateData);
                    if (!$updateResult) {
                        echo "Failed to store timetable URL in Firebase.";
                        return;
                    }
                } else {
                    echo "Failed to upload file to Firebase Storage.";
                    return;
                }
            }


            if ($result1 && $result2) {
                echo '1'; // Success indicator
            } else {
                echo '0'; // Failure indicator
            }
        } else {

            // echo '<pre>' . print_r($selectedSchool, true) . '</pre>';


            // Fetch classes data from Firebase
            $classes = $this->CM->select_data('Schools/' . $school_name . '/' . $session_year . '/Classes');

            // echo '<pre>' . print_r($classes, true) . '</pre>';


            // Prepare classes data for the view
            $classesData = [];
            foreach ($classes as $className => $classInfo) {
                // Check if 'Section' key exists and is an array
                if (isset($classInfo['Section']) && is_array($classInfo['Section'])) {
                    // If 'Section' is empty, add a default section 'A'
                    if (empty($classInfo['Section'])) {
                        $classInfo['Section']['A'] = '';
                    }

                    // Iterate over each section in the 'Section' array
                    foreach ($classInfo['Section'] as $sectionName => $_) {
                        $classesData[] = [
                            'school_name' => $school_name,
                            'class_name' => $className,
                            'section' => $sectionName,
                        ];
                    }
                }
            }

            // Pass the selected school to the view
            $data['selected_school'] = $school_name;
            $data['Classes'] = $classesData;
            // echo '<pre>' . print_r($data['Classes'], true) . '</pre>';


            $this->load->view('include/header');
            $this->load->view('manage_classes', $data);
            $this->load->view('include/footer');
        }
    }


    //     public function fetch_subjects()
    // {
    //     $school_id = $this->school_id;
    //     $school_name = $this->school_name;
    //     $session_year = $this->session_year;

    //     $class = strtolower(trim($this->input->post('class')));

    //     $classRaw = trim(strtolower($this->input->post('class')));
    // $foundationalClasses = [
    //     'playgroup' => 'Playgroup',
    //     'nursery'   => 'Nursery',
    //     'lkg'       => 'LKG',
    //     'ukg'       => 'UKG'
    // ];

    // // Default no match
    // $classRange = '';
    // $isFoundational = false;

    // // Search for keywords inside the input string
    // foreach ($foundationalClasses as $keyword => $range) {
    //     if (strpos($classRaw, $keyword) !== false) {
    //         $classRange = $range;
    //         $isFoundational = true;
    //         break;
    //     }
    // }

    // // If not a foundational class, do numeric parsing
    // if (!$isFoundational) {
    //     preg_match('/\d+/', $classRaw, $matches);
    //     $classNumber = isset($matches[0]) ? (int)$matches[0] : null;

    //     if ($classNumber >= 1 && $classNumber <= 5) {
    //         $classRange = '1-5';
    //     } elseif ($classNumber >= 6 && $classNumber <= 8) {
    //         $classRange = '6-8';
    //     } elseif ($classNumber >= 9 && $classNumber <= 10) {
    //         $classRange = '9-10';
    //     } elseif ($classNumber >= 11 && $classNumber <= 12) {
    //         $classRange = '11-12';
    //     } else {
    //         $classRange = '';
    //     }
    // }

    //     // Now your code continues...
    //     $this->load->library('firebase');
    //     $firebasePath = "/Subject Master_List/CBSE/CBSE_Pattern_2025_26/{$classRange}/";
    //     $subjectGroups = $this->firebase->get($firebasePath);

    //     $subjectsData = [];
    //     $languageRules = ['min_select' => 2, 'max_select' => 2]; // default fallback

    //     if (!empty($subjectGroups)) {
    //         foreach ($subjectGroups as $groupName => $groupData) {
    //             if (isset($groupData['options']) && is_array($groupData['options'])) {
    //                 $subjectsData[$groupName] = $groupData['options'];
    //             }
    //             // Check if this group is Languages and has rules
    //             if (strtolower($groupName) === 'languages') {
    //                 if (isset($groupData['min_select'])) {
    //                     $languageRules['min_select'] = (int)$groupData['min_select'];
    //                 }
    //                 if (isset($groupData['max_select'])) {
    //                     $languageRules['max_select'] = (int)$groupData['max_select'];
    //                 }
    //             }
    //         }
    //     }

    //     // Fetch the Languages from global list
    //     $languagesPath = "/Subject Master_List/CBSE/Languages/";
    //     $languagesList = $this->firebase->get($languagesPath);

    //     $languageOptions = [];
    //     if (!empty($languagesList)) {
    //         foreach ($languagesList as $lang) {
    //             $languageOptions[] = $lang;
    //         }
    //     }
    //     // Always include English
    //     array_unshift($languageOptions, "English");

    //     // Add or override Languages section
    //     $subjectsData['Languages'] = [
    //         'options' => $languageOptions,
    //         'rules'   => $languageRules
    //     ];

    //     echo json_encode($subjectsData);
    // }

    public function fetch_subjects()
    {
        $school_id = $this->school_id;
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        $classRaw = trim(strtolower($this->input->post('class')));

        $foundationalClasses = [
            'playgroup' => 'Playgroup',
            'nursery'   => 'Nursery',
            'lkg'       => 'LKG',
            'ukg'       => 'UKG'
        ];

        $classRange = '';
        $isFoundational = false;

        foreach ($foundationalClasses as $keyword => $range) {
            if (strpos($classRaw, $keyword) !== false) {
                $classRange = $range;
                $isFoundational = true;
                break;
            }
        }

        if (!$isFoundational) {
            preg_match('/\d+/', $classRaw, $matches);
            $classNumber = isset($matches[0]) ? (int)$matches[0] : null;

            if ($classNumber >= 1 && $classNumber <= 5) {
                $classRange = '1-5';
            } elseif ($classNumber >= 6 && $classNumber <= 8) {
                $classRange = '6-8';
            } elseif ($classNumber >= 9 && $classNumber <= 10) {
                $classRange = '9-10';
            } elseif ($classNumber >= 11 && $classNumber <= 12) {
                $classRange = '11-12';
            } else {
                $classRange = '';
            }
        }

        $this->load->library('firebase');
        $firebasePath = "/Subject Master_List/CBSE/CBSE_Pattern_2025_26/{$classRange}/";
        $subjectGroups = $this->firebase->get($firebasePath);

        $response = [];
        $collectedRules = []; // Store all rules

        // Go through each category, picking compulsory/info/rules
        if (!empty($subjectGroups)) {
            foreach ($subjectGroups as $groupName => $groupData) {
                // Process Assessment and Rules at classrange root
                if ($groupName === "Assessment") {
                    $response["Assessment"] = $groupData;
                    continue;
                }
                if ($groupName === "rules") {
                    // Merge these rules into top-level Rules
                    if (is_array($groupData)) {
                        $collectedRules = array_merge($collectedRules, $groupData);
                    } else {
                        $collectedRules[] = $groupData;
                    }
                    continue;
                }
                // For Streams, merge any found rules too
                if ($groupName === "Streams") {
                    $response["Streams"] = $groupData;
                    // Check for rules inside streams
                    foreach ($groupData as $streamKey => $stream) {
                        if (isset($stream["restrictions"])) {
                            $collectedRules = array_merge($collectedRules, $stream["restrictions"]);
                        }
                        if (isset($stream["note"])) {
                            $collectedRules[] = $stream["note"];
                        }
                        // You can expand merging as needed for other rule keys
                    }
                    continue;
                }

                // Standard category processing
                $category = [];
                // Get compulsory flag if exists
                $category["compulsory"] = isset($groupData["compulsory"]) ? $groupData["compulsory"] : false;

                // Get options (subjects list)
                if (isset($groupData["options"])) {
                    $category["options"] = $groupData["options"];
                } elseif (is_array($groupData)) {
                    // Handle case where groupData is only array of subject names
                    $category["options"] = $groupData;
                } else {
                    $category["options"] = [];
                }

                // Get category-level rules, if exist, and merge them to global
                foreach ($groupData as $key => $value) {
                    if (!in_array($key, ["compulsory", "options"])) {
                        if (!isset($category["rules"])) $category["rules"] = [];
                        $category["rules"][$key] = $value;
                        // Also collect for global Rules output
                        $collectedRules[$groupName . "_" . $key] = $value;
                    }
                }

                $response[$groupName] = $category;
            }
        }

        // Merge in global CBSE language list if desired for certain categories
        // --- example: you may place this logic per your requirements ---

        // Append full merged rules to the result
        if (!empty($collectedRules)) {
            $response["rules"] = $collectedRules;
        }

        echo json_encode($response);
    }

    public function get_class_details()
    {
        // $school_id = $this->school_id;
        $school_name = $this->school_name;
        $session_year = $this->session_year;


        $schoolName = $this->input->post('school_name');
        $className = $this->input->post('class_name');
        $section = $this->input->post('section');
        $classIsNumeric = is_numeric($className);

        if (is_string($className) && strpos($className, 'Class') === false) {
            if ($classIsNumeric) {
                $classNumber = filter_var($className, FILTER_SANITIZE_NUMBER_INT); // Extract numeric part
                $suffix = '';
                if ($classNumber % 10 == 1 && $classNumber % 100 != 11) {
                    $suffix = 'st';
                } elseif ($classNumber % 10 == 2 && $classNumber % 100 != 12) {
                    $suffix = 'nd';
                } elseif ($classNumber % 10 == 3 && $classNumber % 100 != 13) {
                    $suffix = 'rd';
                } else {
                    $suffix = 'th';
                }
                $className = "Class {$classNumber}{$suffix}";
            } else {
                $className = "Class {$className}";
            }
        }


        $classSection = $className . " '" . trim($section) . "'";
        // $path = "Schools/$schoolName/$session_year/$classSection";
        $path = "Schools/$school_name/$session_year/$classSection";

        $classData = $this->CM->select_data($path);

        if ($classData) {
            // Extract subjects and additional subjects
            // Convert Subjects & AdditionalSubjects to arrays if they are associative
            $subjects = isset($classData['Subjects']) ? array_keys($classData['Subjects']) : [];
            $additionalSubjects = isset($classData['AdditionalSubjects']) ? array_keys($classData['AdditionalSubjects']) : [];

            // Remove additional subjects from subjects list
            $filteredSubjects = array_diff($subjects, $additionalSubjects);

            $response = [
                "subjects" => array_values($filteredSubjects), // Re-index array
                "optionalSubjects" => $additionalSubjects,
                "timetable" => $classData['Time_table'] ?? ""
            ];

            echo json_encode($response);
        } else {
            echo json_encode([]);
        }
    }


    public function update_class_details()
    {
        $session_year = $this->session_year;

        if ($this->input->method() == 'post') {
            $schoolName = $this->input->post('edit_school_name');
            $prevClassName = $this->input->post('prev_class_name');
            $prevSection = $this->input->post('prev_section');
            $className = $this->input->post('edit_class_name');
            $section = $this->input->post('edit_section');
            $subjects = json_decode($this->input->post('subjects'), true) ?: [];
            $optionalSubjects = json_decode($this->input->post('optional_subjects'), true) ?: [];

            // Ensure class naming format
            $className = $this->format_class_name($className);
            $prevClassName = $this->format_class_name($prevClassName);

            if (empty($section)) {
                $section = 'A';
            }

            $sections = explode(',', $section);
            $prevClassSection = "$prevClassName '$prevSection'";
            $newClassSection = "$className '$section'";

            $prevPath = "Schools/$schoolName/$session_year/$prevClassSection";
            $newPath = "Schools/$schoolName/$session_year/$newClassSection";

            // Step 1: Copy previous class data
            $prevData = $this->CM->get_data($prevPath);
            if (!$prevData) {
                echo "error: Previous class data not found";
                return;
            }

            // Step 2: Remove previous class and section
            $this->CM->delete_data("Schools/$schoolName/$session_year/Classes/$prevClassName/Section/", $prevSection);
            $this->CM->delete_data("Schools/$schoolName/$session_year/$prevClassSection", null);

            // Step 2: Check if any sections are left
            $remainingSections = $this->CM->get_data("Schools/$schoolName/$session_year/Classes/$prevClassName/Section");

            // Step 3: If no sections remain, delete the entire class node
            if (empty($remainingSections)) {
                $this->CM->delete_data("Schools/$schoolName/$session_year/Classes/$prevClassName", null);
            }

            // Step 3: Add new classSection entry
            $classData = [
                'Section' => [$section => ''],
                'Time_stamp' => is_numeric($className) ? (int) filter_var($className, FILTER_SANITIZE_NUMBER_INT) : $className
            ];
            $this->CM->addKey_pair_data("Schools/$schoolName/$session_year/Classes/$className/", $classData);

            // Copy previous data to the new location
            $this->CM->addKey_pair_data($newPath, $prevData);

            // Step 4: Update subjects & additional subjects
            foreach ($sections as $section) {
                $path = "Schools/$schoolName/$session_year/$className '$section'";

                // Merging subjects and ensuring key-value structure
                $mergedSubjects = array_fill_keys($subjects, "");  // Regular subjects
                $optionalSubjectsArray = array_fill_keys($optionalSubjects, "");  // Optional subjects

                // Data structure for Firebase
                $data = [
                    'Subjects' => $mergedSubjects,
                    'AdditionalSubjects' => $optionalSubjectsArray  // Keeping optional subjects separate
                ];
                // Save data to Firebase
                $this->CM->addKey_pair_data($path, $data);
            }

            // Step 5: Handle timetable file replacement
            $prevClassData = $this->firebase->get("Schools/$schoolName/$session_year/$className '$section'");
            if (!empty($prevClassData['Time_table'])) {
                $prevFilePath = $this->extract_firebase_storage_path($prevClassData['Time_table']);
                $this->CM->delete_file_from_firebase($prevFilePath);
            }

            if (isset($_FILES['timetable_file']) && $_FILES['timetable_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['timetable_file'];
                $folderName = "$className '$section'";
                $uploadResult = $this->CM->handleFileUpload($file, $schoolName, $folderName, 'time_table', true);

                if ($uploadResult) {
                    $this->CM->addKey_pair_data("Schools/$schoolName/$session_year/$className '$section'/", ['Time_table' => $uploadResult]);
                } else {
                    echo "Failed to upload new timetable";
                    return;
                }
            }

            echo "success";
        }
    }

    /**
     * Formats class name properly.
     */
    private function format_class_name($className)
    {
        if (is_string($className) && strpos($className, 'Class') === false) {
            if (is_numeric($className)) {
                $classNumber = filter_var($className, FILTER_SANITIZE_NUMBER_INT);
                $suffix = ($classNumber % 10 == 1 && $classNumber % 100 != 11) ? 'st' : (($classNumber % 10 == 2 && $classNumber % 100 != 12) ? 'nd' : (($classNumber % 10 == 3 && $classNumber % 100 != 13) ? 'rd' : 'th'));
                return "Class {$classNumber}{$suffix}";
            } else {
                return "Class {$className}";
            }
        }
        return $className;
    }

    /**
     * Extracts Firebase Storage file path from a URL.
     */
    private function extract_firebase_storage_path($url)
    {
        $parsedUrl = parse_url($url, PHP_URL_PATH);
        return str_replace(['/v0/b/graders-1c047.appspot.com/o/', '%2F'], ['', '/'], urldecode($parsedUrl));
    }







    public function upload_timetable()
    {
        $session_year = $this->session_year;

        if ($this->input->server('REQUEST_METHOD') === 'POST') {
            $schoolName = $this->input->post('school_name');
            $className = $this->input->post('class_name');
            $section = $this->input->post('section');

            // Check if the school name, class name, and section are provided
            if (empty($schoolName) || empty($className) || empty($section)) {
                echo "School name, class name, or section is missing.";
                return;
            }

            $folderName = "$className '$section'"; // Combine class name and section

            // Handle file upload
            if (!isset($_FILES['timetable_file']) || $_FILES['timetable_file']['error'] !== UPLOAD_ERR_OK) {
                echo "Error uploading file.";
                return;
            }

            // Upload the file to Firebase Storage using Common_model method
            $file = $_FILES['timetable_file'];
            $uploadResult = $this->CM->handleFileUpload($file, $schoolName, $folderName, 'time_table', true);

            if ($uploadResult) {
                // File uploaded successfully
                // Example: Store the timetable URL in Firebase Realtime Database
                $firebasePath = "Schools/$schoolName/$session_year/$className '$section'/";
                $updateData = ['Time_table' => $uploadResult]; // Assuming $uploadResult is the URL
                $updateResult = $this->CM->addKey_pair_data($firebasePath, $updateData);
                if ($updateResult) {
                    redirect('classes/manage_classes');
                } else {
                    echo "Failed to store URL in Firebase.";
                }
            } else {
                // File upload failed
                echo "Failed to upload file to Firebase Storage.";
            }
        } else {
            // Handle invalid request method
            echo "Invalid request method.";
        }
    }

    public function delete_class()
    {
        $session_year = $this->session_year;

        if ($this->input->method() == 'post') {
            // Get the class details from the POST request
            $schoolName = $this->input->post('school_name');
            $className = $this->input->post('class_name');
            $section = $this->input->post('section');

            // Construct the paths
            $classesPath = 'Schools/' . $schoolName  . '/' . $session_year . '/Classes/' . $className . '/section/';
            $specificSectionPath = 'Schools/' . $schoolName;

            // Delete the class and section from Firebase
            $result1 = $this->CM->delete_data($classesPath, $section);
            $result2 = $this->CM->delete_data($specificSectionPath, $className . " '" . trim($section) . "'");


            if ($result1 && $result2) {
                // Redirect after successful deletion
                redirect(base_url() . 'classes/manage_classes/');
            } else {
                // Handle failure scenario
                echo '0'; // Failure indicator
            }
        } else {
            echo 'Invalid request method.';
        }
    }


    public function class_profile()
    {
        $school_id = $this->school_id;
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Get the encoded class name from the URL
        $encodedClassSection = $this->input->get('class_name');
        $decodedClassSection = urldecode($encodedClassSection);

        // Fetch data from Firebase
        $classPath = "Schools/$school_name/$session_year/{$decodedClassSection}";
        $classData = $this->firebase->get($classPath);

        if (!$classData) {
            show_error('No data found for the specified class.', 404);
            return;
        }

        // Process student data
        $students = [];
        if (isset($classData['Students']['List']) && is_array($classData['Students']['List'])) {
            foreach ($classData['Students']['List'] as $userId => $studentName) {
                // Skip invalid or empty user IDs or missing student names
                if (empty($userId) || empty($studentName) || $studentName === 'Not Found') {
                    continue;
                }

                // Fetch father name
                $fatherNamePath = "Users/Parents/$school_id/{$userId}/Father Name";
                $fatherName = $this->firebase->get($fatherNamePath);

                $students[] = [
                    'user_id' => $userId,
                    'student_name' => $studentName,
                    'father_name' => $fatherName ?? 'Not Found'
                ];
            }
        }


        // Process subjects and additional subjects
        $subjects = [];
        $additionalSubjects = [];
        $additionalSubjectStudents = [];

        if (isset($classData['Subjects']) && is_array($classData['Subjects'])) {
            foreach (array_keys($classData['Subjects']) as $subject) {
                $subjects[] = $subject;
            }
        }

        if (isset($classData['AdditionalSubjects']) && is_array($classData['AdditionalSubjects'])) {
            foreach (array_keys($classData['AdditionalSubjects']) as $subject) {
                $additionalSubjects[] = $subject;

                // Fetch student list for this optional subject
                $subjectListKey = "List_{$subject}";
                if (isset($classData[$subjectListKey]) && is_array($classData[$subjectListKey])) {
                    $additionalSubjectStudents[$subject] = array_keys($classData[$subjectListKey]);
                } else {
                    $additionalSubjectStudents[$subject] = []; // No students found for this optional subject
                }
            }
        }

        // Get time table URL
        $timeTableUrl = $classData['Time_table'] ?? null;

        // Pass data to the view
        $data = [
            'class_name' => $decodedClassSection,
            'class_teacher' => $classData['ClassTeacher'] ?? 'Not Assigned',
            'total_students' => count($students),
            'students' => $students,
            'subjects' => $subjects,
            'additionalSubjects' => $additionalSubjects,
            'additionalSubjectStudents' => $additionalSubjectStudents, // User IDs grouped by optional subject
            'time_table_url' => $timeTableUrl
        ];
        log_message('error', 'Class Profile Data: ' . print_r($data, true));

        $this->load->view('include/header');
        $this->load->view('class_profile', $data);
        $this->load->view('include/footer');
    }



    public function get_class_data($class_name)
    {
        $school_id = $this->school_id;
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Decode the class name to handle any special characters or spaces
        $class_name = urldecode($class_name);

        // Construct the Firebase path using the decoded class_name
        $class_path = "Schools/$school_name/$session_year/{$class_name}";

        // Fetch class data from Firebase
        $class_data = $this->firebase->get($class_path);
        echo '<pre>' . print_r($class_data, true) . '</pre>';

        if ($class_data) {
            $students = [];
            echo '<pre>' . print_r($students, true) . '</pre>';


            // Check if Students data exists and process it
            if (isset($class_data['Students']['List']) && is_array($class_data['Students']['List'])) {
                foreach ($class_data['Students']['List'] as $user_id => $student_name) {
                    // Fetch father name for each student
                    $father_name_path = "Users/Parents/$school_id/{$user_id}/Father Name";
                    $father_name = $this->firebase->get($father_name_path);

                    // Handle cases where father name is not found
                    $father_name = $father_name ?? 'N/A';

                    $students[] = [
                        'user_id' => $user_id,
                        'student_name' => $student_name,
                        'father_name' => $father_name
                    ];
                }
            }

            // Extract class teacher and total students
            $class_teacher = $class_data['Class Teacher'] ?? 'N/A';
            echo '<pre>' . print_r($class_teacher, true) . '</pre>';

            $total_students = count($students);
            echo '<pre>' . print_r($total_students, true) . '</pre>';


            // Prepare and return the response
            $response = [
                'class_teacher' => $class_teacher,
                'total_students' => $total_students,
                'students' => $students
            ];

            echo '<pre>' . print_r($response, true) . '</pre>';

            echo json_encode($response);
        } else {
            // Handle cases where no class data is found
            echo json_encode(['error' => 'No data found']);
        }
    }
}
