<?php

class Student extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();

        // if(!$this->session->userdata('admin_session')) 
        // {
        //     redirect(base_url());
        // }
    }


    public function master_student()
    {
        $this->load->view('include/header');
        $this->load->view('import_students'); // view file
        $this->load->view('include/footer');
    }
     public function import_students()
    {
        try {

            log_message('error', '=== IMPORT FUNCTION STARTED ===');

            $school_id    = $this->school_id;
            $school_name  = $this->school_name;
            $session_year = $this->session_year;

            log_message('error', 'School ID: ' . $school_id);

            if (!isset($_FILES['excelFile']) || $_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
                log_message('error', 'File not uploaded properly.');
                redirect('student/all_student');
                return;
            }

            $file = $_FILES['excelFile'];
            log_message('error', 'Uploaded File Name: ' . $file['name']);

            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            log_message('error', 'File Extension: ' . $extension);

            $reader = ($extension === 'csv')
                ? IOFactory::createReader('Csv')
                : IOFactory::createReader('Xlsx');

            $spreadsheet = $reader->load($file['tmp_name']);
            $sheetData   = $spreadsheet->getActiveSheet()->toArray();

            log_message('error', 'Total Rows Found: ' . count($sheetData));

            if (count($sheetData) <= 1) {
                log_message('error', 'Excel contains no data.');
                redirect('student/all_student');
                return;
            }


            // First row is header
            $headers = array_map('trim', $sheetData[0]);
            unset($sheetData[0]);
            $sheetData = array_values($sheetData);

            log_message('error', 'Headers: ' . print_r($headers, true));

            $success = 0;
            $error   = 0;

            $studentIdCount = $this->CM->get_data("Users/Parents/{$school_id}/Count");
            if (!$studentIdCount) {
                $studentIdCount = 1;
            }

            log_message('error', 'Starting Count: ' . $studentIdCount);

            foreach ($sheetData as $index => $row) {

                log_message('error', 'Processing Row: ' . $index);

                if (!array_filter($row)) {
                    log_message('error', 'Empty row skipped.');
                    continue;
                }

                if (count($headers) != count($row)) {
                    log_message('error', 'Header count mismatch.');
                    $error++;
                    continue;
                }

                $rowData = array_combine($headers, $row);

                log_message('error', 'Row Data: ' . print_r($rowData, true));

                $studentName = trim($rowData['Name'] ?? '');
                $classRaw    = trim($rowData['Class'] ?? '');
                $section     = trim($rowData['Section'] ?? '');

                if (!$studentName || !$classRaw || !$section) {
                    log_message('error', 'Required fields missing.');
                    $error++;
                    continue;
                }

                $studentId = 'STU000' . $studentIdCount;
                log_message('error', 'Generated Student ID: ' . $studentId);

                // Extract number from "Class 8"
                $classNumber = (int) filter_var($classRaw, FILTER_SANITIZE_NUMBER_INT);

                // Convert number to ordinal (8 → 8th)
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

                // Keep Firebase structure path same

                $combinedClass = "{$classRaw}/Section {$section}";
                $formattedDOB = date('d-m-Y', strtotime($rowData['DOB']));
                $password = $this->generatePassword($studentName, $formattedDOB);



                $studentData = [

                    "Name"           => $studentName,
                    "User Id"        => $studentId,
                    "DOB"            => trim($rowData['DOB'] ?? ''),
                    "Admission Date" => trim($rowData['Admission Date'] ?? ''),

                    "Class"   => $className,
                    "Section" => $section,

                    "Phone Number" => trim($rowData['Phone Number'] ?? ''),
                    "Email"        => trim($rowData['Email'] ?? ''),
                    "Password" => $password,

                    // "Password"     => substr($studentName, 0, 3) . '123@',


                    "Category"    => trim($rowData['Category'] ?? ''),
                    "Gender"      => trim($rowData['Gender'] ?? ''),
                    "Blood Group" => trim($rowData['Blood Group'] ?? ''),
                    "Religion"    => trim($rowData['Religion'] ?? ''),
                    "Nationality" => trim($rowData['Nationality'] ?? ''),

                    "Father Name"       => trim($rowData['Father Name'] ?? ''),
                    "Father Occupation" => trim($rowData['Father Occupation'] ?? ''),
                    "Mother Name"       => trim($rowData['Mother Name'] ?? ''),
                    "Mother Occupation" => trim($rowData['Mother Occupation'] ?? ''),
                    "Guard Contact"     => trim($rowData['Guard Contact'] ?? ''),
                    "Guard Relation"    => trim($rowData['Guard Relation'] ?? ''),

                    "Pre Class"  => trim($rowData['Pre Class'] ?? ''),
                    "Pre School" => trim($rowData['Pre School'] ?? ''),
                    "Pre Marks"  => trim($rowData['Pre Marks'] ?? ''),

                    "Address" => [
                        "Street"     => trim($rowData['Street'] ?? ''),
                        "City"       => trim($rowData['City'] ?? ''),
                        "State"      => trim($rowData['State'] ?? ''),
                        "PostalCode" => trim($rowData['Postal Code'] ?? '')
                    ],

                    "Profile Pic" => "",

                    "Doc" => [
                        "Birth Certificate" => "",
                        "Aadhar Card" => "",
                        "Previous School Leaving Certificate" => "",
                        "PhotoUrl" => ""
                    ]
                ];


                $studentPath = "Users/Parents/{$school_id}/{$studentId}";
                $result = $this->firebase->set($studentPath, $studentData);

                log_message('error', 'Firebase Insert Result: ' . json_encode($result));

                $studentIdCount++;
                $success++;
            }

            log_message('error', 'Total Success: ' . $success);
            log_message('error', 'Total Failed: ' . $error);

            $this->CM->addKey_pair_data(
                "Users/Parents/{$school_id}/",
                ['Count' => $studentIdCount]
            );

            log_message('error', 'Count Updated.');

            $this->session->set_flashdata(
                'import_result',
                "Imported Successfully: {$success} | Failed: {$error}"
            );

            log_message('error', '=== IMPORT FUNCTION COMPLETED ===');

            redirect('student/all_student');
        } catch (Exception $e) {

            log_message('error', 'IMPORT ERROR: ' . $e->getMessage());

            $this->session->set_flashdata(
                'import_result',
                "Import Failed! Check logs."
            );

            redirect('student/all_student');
        }
    }

    public function student_registration()
    {
        if ($this->input->method() == 'post') {
            $data = $this->input->post();

            // Validate and sanitize input data
            if (
                isset($data['Phone_Number']) && isset($data['User_Id'])
                && isset($data['Name']) && isset($data['Class']) && isset($data['Section'])
            ) {

                // Validate phone number
                $phoneNumber = $data['Phone_Number'];
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

                $phoneNumber = $data['Phone_Number'];
                $userId = $data['User_Id'];
                $studentName = $data['Name'];
                $sectionName = $data['Section'];
                $schoolName = $data['School_Name']; // Assuming this is passed in the form data

                // Combine class and section for school path with "Class " prefix
                $classSection = 'Class ' . $ordinalPart . " '" . $sectionName . "'";

                // Fetch the current count from Firebase for students
                $currentCount = $this->CM->get_data('Users/Parents/Count');
                if ($currentCount === null) {
                    $currentCount = 1; // Initialize count if it doesn't exist
                }

                // Set the new student ID as string
                $userId = $currentCount;
                $data['User_Id'] = $userId;

                // Insert data into Firebase
                $result = $this->CM->insert_data('Users/Parents/1111/', $data);

                if ($result) {
                    // Tenant-scoped phone index (primary)
                    $this->CM->addKey_pair_data("Schools/{$schoolName}/Phone_Index/", [$phoneNumber => $userId]);
                    // Legacy global indexes — kept for mobile app backward compatibility
                    $this->CM->addKey_pair_data('Exits/', [$phoneNumber => '1111']);
                    $this->CM->addKey_pair_data('User_ids_pno/', [$phoneNumber => $userId]);

                    // Increment and update the count in Firebase
                    $newCount = $currentCount + 1;
                    $this->CM->addKey_pair_data('Users/Parents/', ['Count' => $newCount]);

                    // Add student to the specific class and section
                    $this->CM->addKey_pair_data("Schools/$schoolName/$classSection/Students/", [$userId => ['Name' => $studentName]]);

                    // Add student to the List key inside the School->SchoolName->List
                    $this->CM->addKey_pair_data("Schools/$schoolName/$classSection/Students/List/", [$userId => $studentName]);

                    echo "1"; // If data is inserted, echo 1
                } else {
                    echo "0"; // If data is not inserted, echo 0
                }
            } else {
                echo "Error: Required fields missing";
            }
        } else {
            // Fetch data to populate the form
            $data['students'] = $this->CM->select_data('Users/Parents/1111');
            // echo '<pre>' . print_r($data, true) . '</pre>';


            // Filter out students who have been deleted from the school
            foreach ($data['students'] as $studentId => $studentData) {
                $schoolName = $studentData['School Name'];
                $className = $studentData['Class'];
                $sectionName = $studentData['Section'];

                // Combine class and section with "Class " prefix
                $classSection = 'Class ' . $className . " '" . $sectionName . "'";
                // echo '<pre>' . print_r($classSection, true) . '</pre>';


                // Check if the student still exists in the school's class and section
                $exists = $this->CM->select_data("Schools/$schoolName/$classSection/Students/$studentId");
                if (!$exists) {
                    unset($data['students'][$studentId]);
                }
            }

            $data['schools'] = $this->CM->select_data('School_ids');
            // Fetch the school name for the given school ID
            $schoolId = '1111';
            $data['school_name'] = $this->CM->get_school_name_by_id($schoolId);

            // Initialize arrays to store class names and sections
            $classNames = array();
            $classSections = array();

            if (!empty($data['school_name'])) {
                // Fetch classes data from Firebase
                $classes = $this->CM->select_data('Schools/' . $data['school_name'] . '/Classes');
                foreach ($classes as $className => $classData) {

                    // Extract the ordinal part from the class name (e.g., 1st, 2nd, 3rd)
                    preg_match('/\b\d+(st|nd|rd|th)\b/', $className, $matches);

                    if (!empty($matches)) {
                        $ordinalPart = $matches[0]; // Get the first match
                        $classNames[] = $ordinalPart; // Add the ordinal part to classNames array
                    }

                    // Store sections for each class
                    if (isset($classData['Section'])) {
                        $classSections[$ordinalPart] = $classData['Section'];
                    } else {
                        $classSections[$ordinalPart] = array(); // Handle case where sections are not present
                    }
                }
            }

            // Pass $classNames and $classSections to your view
            $data['classNames'] = $classNames;
            $data['classSections'] = $classSections;

            // Remove 'Class ' prefix for view display
            foreach ($data['students'] as &$student) {
                if (strpos($student['Class'], 'Class ') === 0) {
                    $student['Class'] = substr($student['Class'], 6); // Remove 'Class ' prefix
                }
            }

            $currentCount = $this->CM->get_data('Users/Parents/Count');
            if ($currentCount === null) {
                $currentCount = 0; // Initialize count if it doesn't exist
            }
            $data['newStudentId'] = $currentCount;

            $this->load->view('include/header');
            $this->load->view('student_registration', $data);
            $this->load->view('include/footer');
        }
    }




    public function delete_student($id)
    {
        // Retrieve the student's data to get the required information
        $student = $this->CM->select_data('Users/Parents/1111' . '/' . $id);

        if ($student && isset($student['Phone Number'])) {
            $phoneNumber = $student['Phone Number'];
            $className = $student['Class'];
            $sectionName = $student['Section'];
            $schoolName = $student['School Name']; // Assuming the school name is stored in the student data

            // Combine class and section with "Class " prefix
            $classSection = 'Class ' . $className . " '" . $sectionName . "'";

            // Remove from tenant-scoped phone index
            $this->CM->delete_data("Schools/{$schoolName}/Phone_Index", $phoneNumber);
            // Remove legacy global indexes
            $this->CM->delete_data('User_ids_pno', $phoneNumber);
            $this->CM->delete_data('Exits', $phoneNumber);

            // Delete the student data from the school's specific class and section
            $this->CM->delete_data("Schools/$schoolName/$classSection/Students", $id);

            // Redirect to the student registration page after successful deletion
            redirect(base_url() . 'index.php/student/student_registration/');
        } else {
            // Handle the error if the student data is not found
            redirect(base_url() . 'index.php/student/student_registration/');
        }
    }

    public function edit_student($userId)
    {
        if ($this->input->method() == 'post') {
            $data = $this->input->post();

            // Initialize an empty array for formatted data
            $formattedData = [];

            // Loop through the post data and format the keys
            foreach ($data as $key => $value) {
                // Replace '%20' and '_' with spaces
                $formattedKey = str_replace(['%20', '_'], ' ', $key);
                // Add the formatted key and corresponding value to the formattedData array
                $formattedData[$formattedKey] = $value;
            }

            // Ensure User ID is treated as an integer
            if (isset($formattedData['User Id'])) {
                $formattedData['User Id'] = (int) $formattedData['User Id'];
            }

            // Validate and sanitize input data
            if (isset($formattedData['Phone Number']) && isset($formattedData['Name']) && isset($formattedData['User Id']) && isset($formattedData['Father Name'])) {
                // Extract the ordinal part from the class name (e.g., 8th)
                preg_match('/\b\d+(st|nd|rd|th)\b/', $formattedData['Class'], $matches);
                $ordinalPart = !empty($matches) ? $matches[0] : $formattedData['Class'];

                // Store only the ordinal part in Users->Parents->1111->StudentId
                $formattedData['Class'] = $ordinalPart;

                $phoneNumber = $formattedData['Phone Number'];
                $studentName = $formattedData['Name'];
                $sectionName = $formattedData['Section'];
                $schoolName = $formattedData['School Name']; // Assuming this is passed in the form data
                
                // Generate password based on student name
                $name = isset($formattedData['Name']) ? $formattedData['Name'] : '';
                if (!empty($name)) {
                    $password = substr($name, 0, 3) . '123@';
                    $formattedData['Password'] = $password;
                } else {
                    $formattedData['Password'] = '';
                }
                ///////////////////////////////
                // Combine class and section for school path with "Class " prefix
                $classSection = 'Class ' . $ordinalPart . " '" . $sectionName . "'";

                // Update student data in Firebase
                $result = $this->CM->update_data("Users/Parents/1111", $userId, $formattedData);

                if ($result) {
                    // Tenant-scoped phone index (primary)
                    $this->CM->addKey_pair_data("Schools/{$schoolName}/Phone_Index/", [$phoneNumber => $userId]);
                    // Legacy global indexes — kept for mobile app backward compatibility
                    $this->CM->addKey_pair_data('User_ids_pno/', [$phoneNumber => $userId]);

                    // Update student in the specific class and section
                    $this->CM->addKey_pair_data("Schools/$schoolName/$classSection/Students/", [$userId => ['Name' => $studentName]]);

                    // Update student in the List key inside the School->SchoolName->List
                    $this->CM->addKey_pair_data("Schools/$schoolName/$classSection/Students/List/", [$userId => $studentName]);

                    echo "1"; // If data is updated, echo 1
                } else {
                    echo "0"; // If data is not updated, echo 0
                }
            } else {
                echo "Error: Required fields missing";
            }
        } else {
            // Fetch student data to populate the edit form
            $data['student_data'] = $this->CM->select_data("Users/Parents/1111/$userId");
            if (!$data['student_data']) {
                show_404(); // Show 404 if student not found
                return;
            }

            $data['gender_options'] = ['Male', 'Female']; // Assuming these are your gender options

            $data['schools'] = $this->CM->select_data('School_ids');
            // Fetch the school name for the given school ID
            $schoolId = '1111';
            $data['school_name'] = $this->CM->get_school_name_by_id($schoolId);

            // Initialize arrays to store class names and sections
            $classNames = array();
            $classSections = array();

            if (!empty($data['school_name'])) {
                // Fetch classes data from Firebase
                $classes = $this->CM->select_data('Schools/' . $data['school_name'] . '/Classes');
                foreach ($classes as $className => $classData) {
                    // Extract the ordinal part from the class name (e.g., 1st, 2nd, 3rd)
                    preg_match('/\b\d+(st|nd|rd|th)\b/', $className, $matches);
                    if (!empty($matches)) {
                        $ordinalPart = $matches[0]; // Get the first match
                        $classNames[] = $ordinalPart; // Add the ordinal part to classNames array
                    }

                    // Store sections for each class
                    if (isset($classData['Section'])) {
                        $classSections[$ordinalPart] = $classData['Section'];
                    } else {
                        $classSections[$ordinalPart] = array(); // Handle case where sections are not present
                    }
                }
            }

            // Pass $classNames and $classSections to your view
            $data['classNames'] = $classNames;
            $data['classSections'] = $classSections;

            // // Remove 'Class ' prefix for view display
            // if (strpos($data['student_data']['Class'], 'Class ') === 0) {
            //     $data['student_data']['Class'] = substr($data['student_data']['Class'], 6); // Remove 'Class ' prefix
            // }

            $this->load->view('include/header');
            $this->load->view('edit_student', $data);
            $this->load->view('include/footer');
        }
    }
}



// public function student_registration()
// {
//     // Check internet connection
//     $connected = $this->check_internet_connection();

//     if (!$connected) {
//         if ($this->input->method() == 'post') {
//             // Handle offline mode data insertion
//             $this->handleOfflineStudentRegistration();
//         } else {
//             // Load offline student registration form
//             $this->loadOfflineStudentRegistrationForm();
//         }
//     } else {
//         if ($this->input->method() == 'post') {
//             // Handle online mode data insertion
//             $this->handleOnlineStudentRegistration();
//         } else {
//             // Load online student registration form
//             $this->loadOnlineStudentRegistrationForm();
//         }
//     }
// }

// private function handleOfflineStudentRegistration()
// {
//     // Collect form data
//     $user_id = $this->input->post('User_Id');
//     $name = $this->input->post('Name');
//     $father_name = $this->input->post('Father_Name');
//     $mother_name = $this->input->post('Mother_Name');
//     $email = $this->input->post('Email');
//     $dob = $this->input->post('DOB');
//     $phone_number = $this->input->post('Phone_Number');
//     $gender = $this->input->post('Gender');
//     $school_name = $this->input->post('School_Name');
//     $class = $this->input->post('Class');
//     $section = $this->input->post('Section');
//     $address = $this->input->post('Address');
//     $password = $this->input->post('Password');

//     // Prepare data array
//     $data = array(
//         'User Id' => $user_id,
//         'Name' => $name,
//         'Father Name' => $father_name,
//         'Mother Name' => $mother_name,
//         'Email' => $email,
//         'DOB' => $dob,
//         'Phone Number' => $phone_number,
//         'Gender' => $gender,
//         'School Name' => $school_name,
//         'Class' => $class,
//         'Section' => $section,
//         'Address' => $address,
//         'Password' => $password
//     );

//     // Insert data into database using Common_sql_model
//     $affected_rows = $this->SM->insert_student($data);

//     // Check the result
//     if ($affected_rows > 0) {
//         echo '1'; // Success
//     } else {
//         echo '0'; // Failure
//     }
// }

// private function loadOfflineStudentRegistrationForm()
// {
//     // Load offline student data
//     $data['student_offline'] = $this->SM->select_data('student', '*');
//     // echo '<pre>' . print_r($data, true) . '</pre>';
//     $this->load->view('include/header');
//     $this->load->view('student_registration_offline', $data);
//     $this->load->view('include/footer');
// }

// private function handleOnlineStudentRegistration()
// {
//     $data = $this->input->post();

//     // Validate and sanitize input data
//     if (
//         isset($data['Phone_Number']) && isset($data['User_Id'])
//         && isset($data['Name']) && isset($data['Class']) && isset($data['Section'])
//     ) {
//         // Validate phone number
//         $phoneNumber = $data['Phone_Number'];
//         if (!preg_match('/^[6789]\d{9}$/', $phoneNumber)) {
//             echo 'Error: Invalid phone number';
//             return;
//         }

//         // Generate password based on student name
//         $name = isset($data['Name']) ? $data['Name'] : '';
//         if (!empty($name)) {
//             $password = substr($name, 0, 3) . '123@';
//             $data['Password'] = $password;
//         } else {
//             $data['Password'] = '';
//         }

//         // Check if password is empty
//         if (empty($data['Password'])) {
//             echo 'Error: Password field cannot be empty';
//             return;
//         }

//         // Extract the ordinal part from the class name (e.g., 8th)
//         preg_match('/\b\d+(st|nd|rd|th)\b/', $data['Class'], $matches);
//         $ordinalPart = !empty($matches) ? $matches[0] : $data['Class'];

//         // Store only the ordinal part in Users->Parents->1111->StudentId
//         $data['Class'] = $ordinalPart;

//         $phoneNumber = $data['Phone_Number'];
//         $userId = $data['User_Id'];
//         $studentName = $data['Name'];
//         $sectionName = $data['Section'];
//         $schoolName = $data['School_Name']; // Assuming this is passed in the form data

//         // Combine class and section for school path with "Class " prefix
//         $classSection = 'Class ' . $ordinalPart . " '" . $sectionName . "'";

//         // Fetch the current count from Firebase for students
//         $currentCount = $this->CM->get_data('Users/Parents/Count');
//         if ($currentCount === null) {
//             $currentCount = 1; // Initialize count if it doesn't exist
//         }

//         // Set the new student ID as string
//         $userId = $currentCount;
//         $data['User_Id'] = $userId;

//         // Insert data into Firebase
//         $result = $this->CM->insert_data('Users/Parents/1111/', $data);

//         if ($result) {
//             // Insert the phone number => school ID pair into "Exits"
//             $this->CM->addKey_pair_data('Exits/', [$phoneNumber => '1111']);

//             // Insert the phone number => user ID pair into "User_ids_pno"
//             $this->CM->addKey_pair_data('User_ids_pno/', [$phoneNumber => $userId]);

//             // Increment and update the count in Firebase
//             $newCount = $currentCount + 1;
//             $this->CM->addKey_pair_data('Users/Parents/', ['Count' => $newCount]);

//             // Add student to the specific class and section
//             $this->CM->addKey_pair_data("Schools/$schoolName/$classSection/Students/", [$userId => ['Name' => $studentName]]);

//             // Add student to the List key inside the School->SchoolName->List
//             $this->CM->addKey_pair_data("Schools/$schoolName/$classSection/Students/List/", [$userId => $studentName]);

//             echo "1"; // If data is inserted, echo 1
//         } else {
//             echo "0"; // If data is not inserted, echo 0
//         }
//     } else {
//         echo "Error: Required fields missing";
//     }
// }

// private function loadOnlineStudentRegistrationForm()
// {
//     // Fetch data to populate the form
//     $data['students'] = $this->CM->select_data('Users/Parents/1111');
//     // echo '<pre>' . print_r($data, true) . '</pre>';

//     // Filter out students who have been deleted from the school
//     foreach ($data['students'] as $studentId => $studentData) {
//         $schoolName = $studentData['School Name'];
//         $className = $studentData['Class'];
//         $sectionName = $studentData['Section'];

//         // Combine class and section with "Class " prefix
//         $classSection = 'Class ' . $className . " '" . $sectionName . "'";
//         // echo '<pre>' . print_r($classSection, true) . '</pre>';

//         // Check if the student still exists in the school's class and section
//         $exists = $this->CM->select_data("Schools/$schoolName/$classSection/Students/$studentId");
//         if (!$exists) {
//             unset($data['students'][$studentId]);
//         }
//     }

//     $data['schools'] = $this->CM->select_data('School_ids');
//     // Fetch the school name for the given school ID
//     $schoolId = '1111';
//     $data['school_name'] = $this->CM->get_school_name_by_id($schoolId);

//     // Initialize arrays to store class names and sections
//     $classNames = array();
//     $classSections = array();

//     if (!empty($data['school_name'])) {
//         // Fetch classes data from Firebase
//         $classes = $this->CM->select_data('Schools/' . $data['school_name'] . '/Classes');
//         foreach ($classes as $className => $classData) {

//             // Extract the ordinal part from the class name (e.g., 1st, 2nd, 3rd)
//             preg_match('/\b\d+(st|nd|rd|th)\b/', $className, $matches);

//             if (!empty($matches)) {
//                 $ordinalPart = $matches[0]; // Get the first match
//                 $classNames[] = $ordinalPart; // Add the ordinal part to classNames array
//             }

//             // Store sections for each class
//             if (isset($classData['Section'])) {
//                 $classSections[$ordinalPart] = $classData['Section'];
//             } else {
//                 $classSections[$ordinalPart] = array(); // Handle case where sections are not present
//             }
//         }
//     }

//     // Pass $classNames and $classSections to your view
//     $data['classNames'] = $classNames;
//     $data['classSections'] = $classSections;

//     // Remove 'Class ' prefix for view display
//     foreach ($data['students'] as &$student) {
//         if (strpos($student['Class'], 'Class ') === 0) {
//             $student['Class'] = substr($student['Class'], 6); // Remove 'Class ' prefix
//         }
//     }

//     $currentCount = $this->CM->get_data('Users/Parents/Count');
//     if ($currentCount === null) {
//         $currentCount = 0; // Initialize count if it doesn't exist
//     }
//     $data['newStudentId'] = $currentCount;

//     $this->load->view('include/header');
//     $this->load->view('student_registration', $data);
//     $this->load->view('include/footer');
// }


// private function check_internet_connection()
// {
//     // Check internet connection by pinging a reliable server
//     $connected = @fsockopen("www.google.com", 80);
//     if ($connected) {
//         fclose($connected);
//         return true; // Internet connection is available
//     }
//     return false; // No internet connection
// }








// Notice ANd NoticeAnnouncement
 // this code is working fine for the All checkbox 
    // public function create_notice()
    // {
    //     $school_name = $this->school_name;
    //     $session_year = $this->session_year;
    //     $admin_id = $this->admin_id;

    //     $base_path = 'Schools/' . $school_name . '/' . $session_year . '/All Notices';
    //     $classesPath = 'Schools/' . $school_name . '/' . $session_year . '/Classes';
    //     $classesData = $this->firebase->get($classesPath);

    //     $data['classes'] = [];
    //     foreach ($classesData as $className => $classDetails) {
    //         if (is_array($classDetails) && isset($classDetails['Section'])) {
    //             foreach ($classDetails['Section'] as $sectionName => $sectionDetails) {
    //                 $fullClass = $className . " '" . $sectionName . "'";
    //                 $data['classes'][$fullClass] = $fullClass;
    //             }
    //         }
    //     }

    //     if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    //         log_message('debug', 'Request method is POST. Processing notice creation.');

    //         $postData = $_POST;
    //         log_message('debug', 'Raw POST data: ' . json_encode($postData));

    //         $title = $postData['title'] ?? '';
    //         $description = $postData['description'] ?? '';

    //         // ✅ Extract and decode recipient list
    //         $to_ids = [];
    //         if (!empty($this->input->post('to_id_json'))) {
    //             $to_ids = json_decode($this->input->post('to_id_json'), true);
    //         }

    //         if (empty($to_ids)) {
    //             $this->output
    //                 ->set_content_type('application/json')
    //                 ->set_output(json_encode(['status' => 'error', 'message' => '❌ No recipients selected.']));
    //             return;
    //         }

    //         // 🔢 Generate new Notice ID
    //         $current_data = $this->firebase->get($base_path);
    //         $current_count = isset($current_data['Count']) ? (int)$current_data['Count'] : 0;
    //         $notice_id = 'Not' . str_pad($current_count, 4, '0', STR_PAD_LEFT);

    //         // 🕒 Generate real timestamp once
    //         // $realTimestamp = round(microtime(true) * 1000);
    //         $firebaseTimestamp = [".sv" => "timestamp"];

    //         // 📌 Process recipients
    //         $sanitized_to_ids = [];
    //         foreach ($to_ids as $key => $val) {
    //             $is_class_format = preg_match("/^Class\s+.+\s+'.+'$/", $key); // e.g., Class 10th 'A'

    //             if ($is_class_format) {
    //                 // Split class and section
    //                 $classParts = explode(" '", $key);
    //                 $classOnlyRaw = trim($classParts[0] ?? '');
    //                 $sectionOnly = rtrim($classParts[1] ?? '', "'");

    //                 // Normalize class without "Class " prefix
    //                 $classOnly = (strpos($classOnlyRaw, 'Class ') === 0)
    //                     ? substr($classOnlyRaw, strlen('Class '))
    //                     : $classOnlyRaw;

    //                 // Build Firebase-compatible class path
    //                 $mergedClassSection = "Class {$classOnly} '{$sectionOnly}'";
    //                 $notificationPath = "Schools/{$school_name}/{$session_year}/{$mergedClassSection}/Notification/{$notice_id}";
    //                 $this->firebase->set($notificationPath, $firebaseTimestamp);

    //                 // Use original label for To Id key (no sanitization)
    //                 $sanitized_to_ids[$key] = "";
    //             } else {
    //                 // For other recipients: sanitize the key
    //                 $safe_key = str_replace(['.', '#', '$', '[', ']', '/', "'"], '_', $key);
    //                 $announcementPath = "Schools/{$school_name}/{$session_year}/Announcements/{$safe_key}/{$notice_id}";
    //                 $this->firebase->set($announcementPath, $firebaseTimestamp);

    //                 $sanitized_to_ids[$safe_key] = "";
    //             }
    //         }
    //         // 📦 Compose final notice data
    //         $new_notice = [
    //             'Title' => $title,
    //             'Description' => $description,
    //             'From Id' => $admin_id,
    //             'From Type' => 'Admin',
    //             'Timestamp' => $firebaseTimestamp,
    //             'To Id' => $sanitized_to_ids
    //         ];

    //         log_message('debug', 'Final To Ids: ' . json_encode($sanitized_to_ids));
    //         log_message('debug', 'Final Notice Payload: ' . json_encode($new_notice));

    //         // ✅ Store notice and count
    //         $this->firebase->set("{$base_path}/{$notice_id}", $new_notice);
    //         $this->firebase->set("{$base_path}/Count", $current_count + 1);

    //         $this->output
    //             ->set_content_type('application/json')
    //             ->set_output(json_encode(['status' => 'success', 'message' => '✅ Notice sent successfully.']));
    //     } else {
    //         $data['notices'] = $this->firebase->get($base_path);
    //         $this->load->view('include/header');
    //         $this->load->view('create_notice', $data);
    //         $this->load->view('include/footer');
    //     }
    // }















    // Always use consistent storage path format
            $baseStoragePath = "{$schoolName}/{$session_year}/Students/{$combinedClassSection}/{$studentId}/Documents/";

            // Loop through documents and upload each one
            foreach ($documents as $document) {
                if (!empty($_FILES[$document]['tmp_name'])) {
                    $file = $_FILES[$document];
                    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $fileName = strtolower($document) . '_' . $studentId . '.' . $fileExtension; // E.g., aadhar_studentId.pdf

                    log_message('debug', "Processing upload for document: $document with filename: $fileName");

                    // Final path for each document
                    $documentPath = $baseStoragePath . $fileName;

                    log_message('debug', "Document path for $document: " . $documentPath);

                    // Upload file
                    $documentUrl = $this->CM->handleFileUpload($file, $schoolName, $documentPath, $studentId, true);
                    log_message('debug', "Upload result for $document: " . json_encode($documentUrl));

                    if ($documentUrl) {
                        $documentUrls[$document] = $documentUrl;
                    } else {
                        log_message('error', "Failed to upload $document.");
                        echo json_encode(['status' => 'error', 'message' => "Failed to upload $document."]);
                        return;
                    }
                } else {
                    log_message('debug', "No file provided for document: $document");
                }
            }

            // ✅ Student Photo Upload
            $docUrls = [];
            if (!empty($_FILES['student_photo']['tmp_name'])) {
                log_message('info', 'Student photo file details: ' . print_r($_FILES['student_photo'], true));

                $file = $_FILES['student_photo'];
                $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg'];

                if (!in_array($fileExtension, $allowedExtensions)) {
                    log_message('error', 'Invalid file type for student photo: ' . $fileExtension);
                    echo json_encode(['status' => 'error', 'message' => 'Only JPG or JPEG files are allowed.']);
                    return;
                }

                // Final file path for student photo inside Documents
                $photoFileName = 'photo_' . $studentId . '.' . $fileExtension;
                $photoStoragePath = "{$session_year}/Students/{$combinedClassSection}/{$studentId}/Documents/" . $photoFileName;

                log_message('debug', "Student photo file path: " . $photoStoragePath);

                $photoUrl = $this->CM->handleFileUpload($file, $schoolName, $photoStoragePath, $studentId, true);
                log_message('debug', "Photo upload result: " . json_encode($photoUrl));

                if (!empty($photoUrl)) {
                    $docUrls['PhotoUrl'] = $photoUrl;

                    $studentData['Profile Pic'] = $photoUrl; // ✅ Add to main studentData

                    log_message('info', "Saved Profile Pic to DB: Users/Parents/{$school_id}/{$studentId}/Profile Pic");

                    // ✅ Upload separately to Profile Pic path (organized)
                    $profilePicPath = "{$session_year}/Students/{$studentId}/Profile Pic/photo_{$studentId}.{$fileExtension}";
                    $this->CM->handleFileUpload($file, $schoolName, $profilePicPath, $studentId, true);
                    log_message('info', "Uploaded photo to clean profile path: {$profilePicPath}");

                    log_message('info', 'Photo URL successfully added: ' . $photoUrl);
                } else {
                    log_message('error', 'Photo upload failed. Photo URL is empty.');
                    echo json_encode(['status' => 'error', 'message' => 'Photo upload failed.']);
                    return;
                }
            } else {
                log_message('error', 'No photo uploaded. $_FILES[\'student_photo\'][\'tmp_name\'] is empty.');
                echo json_encode(['status' => 'error', 'message' => 'No photo uploaded.']);
                return;
            }