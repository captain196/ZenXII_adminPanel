<?php

class Fees extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function test()
    {
        $this->load->view('include/header');
        $this->load->view('test');
        $this->load->view('include/footer');
    }

    public function fees_structure()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Check if form is submitted for saving fee title
        if ($this->input->method() == 'post') {
            // Retrieve fee title from POST data
            $feeTitle = $this->input->post('fee_title');
            $feeType = $this->input->post('fee_type'); // Assume you're passing fee type from the form
            $feeTitle = trim(ucwords(strtolower($feeTitle)));

            // Validate if fee title is not empty
            if (!empty($feeTitle) && !empty($feeType)) {
                // Save fee title to Firebase
                $feesStructurePath = "Schools/$school_name/$session_year/Accounts/Fees/Fees Structure/" . $feeType;

                $result = $this->CM->addKey_pair_data($feesStructurePath, [$feeTitle => '']);

                if ($result) {

                    echo '1';
                    return;
                } else {

                    echo '0'; // Failure response
                    return; // End script execution here
                }
            } else {

                echo '0'; // Failure response due to empty title
                return; // End script execution here
            }
        } else {

            // Firebase path to Fees Structure
            $feesStructurePath = 'Schools/' . $school_name . '/' . $session_year . '/Accounts/Fees/Fees Structure';
            $feesStructure = $this->CM->get_data($feesStructurePath);


            $data['feesStructure'] = $feesStructure;
            // echo '<pre>' . print_r($data, true) . '</pre>';


            $this->load->view('include/header');
            $this->load->view('fees_structure', $data);
            $this->load->view('include/footer');
        }
    }
    public function delete_fees_structure($feeTitle, $feeType)
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Decode the fee title to handle any URL encoding
        $feeTitle = urldecode($feeTitle);
        $feeType = urldecode($feeType);


        // Firebase path to the Fees Structure
        $feesStructurePath = "Schools/$school_name/$session_year/Accounts/Fees/Fees Structure/" . $feeType;

        // Call the model function to delete the fee title
        $result = $this->CM->delete_data($feesStructurePath, $feeTitle);

        if ($result) {
            // Redirect to the fees structure page with a success message
            redirect(base_url() . 'fees/fees_structure');
        } else {
            redirect(base_url() . 'fees/fees_structure');
        }

        redirect(base_url() . 'fees/fees_structure');
    }

    public function submit_discount()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Check if Firebase Library is Loaded
        if (!isset($this->firebase)) {
            echo json_encode(["success" => false, "message" => "Firebase library not loaded."]);
            return;
        }

        // Retrieve and sanitize input
        $userId = trim($this->input->post('userId'));
        $class = trim($this->input->post('class'));
        $section = trim($this->input->post('section'));
        $discount = trim($this->input->post('discount'));

        // Validate Inputs
        if (empty($userId) || empty($class) || empty($section) || empty($discount)) {
            echo json_encode(["success" => false, "message" => "Missing required fields."]);
            return;
        }
        $mergedClassSection = $class . " '$section'";


        $firebasePath = "/Schools/$school_name/$session_year/$mergedClassSection/Students/$userId/Discount/OnDemandDiscount";
        $firebasePath2 = "/Schools/$school_name/$session_year/$mergedClassSection/Students/$userId/Discount/totalDiscount";

        try {
            // Save OnDemandDiscount
            $this->firebase->set($firebasePath, (int)$discount);

            // Fetch current totalDiscount
            $currentTotal = $this->firebase->get($firebasePath2);
            $currentTotal = is_numeric($currentTotal) ? (int)$currentTotal : 0; // Ensure numeric value

            // Calculate new totalDiscount
            $newTotal = $currentTotal + (int)$discount;

            // Update totalDiscount
            $this->firebase->set($firebasePath2, $newTotal);

            echo json_encode(["success" => true, "newTotalDiscount" => $newTotal]);
        } catch (Exception $e) {
            log_message('error', "Firebase Error: " . $e->getMessage());
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
    }


    public function fees_chart()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;


        // Construct the path for classes
        $classesPath = 'Schools/' . $school_name . '/' . $session_year . '/Classes';


        // Fetch classes and sections data from Firebase
        $classesData = $this->CM->get_data($classesPath);
        // log_message('debug', 'Classes Data Retrieved: ' . print_r($classesData, true));

        $data['classes'] = [];
        $data['sections'] = [];

        // Process class and section details
        $classesData = is_array($classesData) ? $classesData : [];
        foreach ($classesData as $className => $classDetails) {
            if (is_array($classDetails)) {
                $formattedClassName = $className;
                $data['classes'][$formattedClassName] = $formattedClassName;

                // Check if 'Section' key exists and process sections
                if (isset($classDetails['Section'])) {
                    $sections = $classDetails['Section'];
                    $filteredSections = array_keys($sections);
                    $data['sections'][$formattedClassName] = $filteredSections;
                    // log_message('debug', 'Sections for Class ' . $formattedClassName . ': ' . print_r($filteredSections, true));
                }
            }
        }

        // Handle search query for fetching fee data
        if ($this->input->get('class') && $this->input->get('section')) {
            $selectedClass = urldecode(trim($this->input->get('class')));
            $selectedSection = urldecode(trim($this->input->get('section')));



            // Fetch fees using getFees function
            $feesJson = $this->getFees($selectedClass, $selectedSection);
            $feesData = json_decode($feesJson, true);

            // If fees data is not found, create a default structure
            if (empty($feesData['fees'])) {
                $defaultFeesData = $this->createDefaultFeesForClass("$selectedClass '$selectedSection'", $school_name);
                // $feesData = $this->getFees($selectedClass, $selectedSection); // Re-fetch fees after creating default
                $feesData = [
                    'fees' => $defaultFeesData
                ];
            }

            // Check if fees data is returned properly
            if (isset($feesData['fees'])) {

                // Send fees data as JSON for the frontend
                echo json_encode(['fees' => $feesData['fees']]);
                return;
            } else {

                echo json_encode(['error' => 'No fees data found']);
                return;
            }
        }



        $this->load->view('include/header');
        $this->load->view('fees_chart', $data);
        $this->load->view('include/footer');
    }


    private function createDefaultFeesForClass($classSectionKey, $school_name)
    {
        $school_id = $this->parent_db_key;
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Fetch fee structure
        $feesStructurePath = "Schools/$school_name/$session_year/Accounts/Fees/Fees Structure";
        $feesStructure = $this->CM->get_data($feesStructurePath);

        // Target path for class fees
        $existingFeesPath = "Schools/$school_name/$session_year/Accounts/Fees/Classes Fees/$classSectionKey";
        $existingFees = $this->CM->get_data($existingFeesPath);

        if (empty($existingFees) && !empty($feesStructure)) {
            $defaultFees = [];

            $months = ["April", "May", "June", "July", "August", "September", "October", "November", "December", "January", "February", "March"];

            // Loop through each month and assign monthly fees
            foreach ($months as $month) {
                $defaultFees[$month] = [];

                if (isset($feesStructure['Monthly'])) {
                    foreach ($feesStructure['Monthly'] as $feeTitle => $value) {
                        $defaultFees[$month][$feeTitle] = 0;
                    }
                }
            }

            // Handle yearly fees
            $defaultFees["Yearly Fees"] = [];
            if (isset($feesStructure['Yearly'])) {
                foreach ($feesStructure['Yearly'] as $yearlyFeeTitle => $value) {
                    $defaultFees["Yearly Fees"][$yearlyFeeTitle] = 0;
                }
            }

            // Save to Firebase
            $this->CM->addKey_pair_data($existingFeesPath, $defaultFees);

            return $defaultFees;
        } else {
            log_message('info', "Fees for $classSectionKey already exist or structure is empty.");
        }
    }

    function getFees($className, $section)
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        $path = "/Schools/$school_name/$session_year/Accounts/Fees/Classes Fees/$className '$section'"; // Construct path dynamically
        $feesData = $this->CM->get_data($path);

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
                    log_message('error', "Fees for month $month is not an array: " . print_r($fees, true));
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



    public function save_updated_fees()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Get the raw POST data and decode it
        $jsonData = file_get_contents("php://input");
        $updatedFees = json_decode($jsonData, true); // Decode JSON to array

        // Check for JSON decoding errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo 'JSON decode error: ' . json_last_error_msg();
            return;
        }


        // Ensure the data is structured correctly
        if (!empty($updatedFees)) {
            foreach ($updatedFees as $classWithSection => $feesData) {

                // Check if Yearly Fees exist in the submitted data
                if (isset($feesData['Yearly Fees'])) {
                    $yearlyFees = $feesData['Yearly Fees'];

                    // Save Yearly Fees
                    $this->firebase->set("Schools/$school_name/$session_year/Accounts/Fees/Classes Fees/$classWithSection/Yearly Fees", $yearlyFees);

                    // For debugging purposes
                    echo "Updated Yearly Fees for $classWithSection: " . json_encode($yearlyFees) . "<br>";
                }
                // Remove Yearly Fees from the main data to avoid overwriting
                unset($feesData['Yearly Fees']);

                // Use 'update' to merge the new data into the existing structure
                $this->firebase->update("Schools/$school_name/$session_year/Accounts/Fees/Classes Fees/$classWithSection", $feesData);
            }
            echo 'Fees updated successfully.';
        } else {
            echo 'No data to save.';
        }
    }






    public function class_fees()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        $classesPath = 'Schools/' . $school_name . '/' . $session_year . '/Classes';

        $classesData = $this->CM->get_data($classesPath);


        $data['classes'] = [];
        $data['sections'] = [];

        // Process class and section details
        // foreach ($classesData as $className => $classDetails) {
        //     if (is_array($classDetails)) {
        //         $formattedClassName = $className;
        //         $data['classes'][$formattedClassName] = $formattedClassName;

        //         // Check if 'Section' key exists and process sections
        //         if (isset($classDetails['Section'])) {
        //             $sections = $classDetails['Section'];
        //             $filteredSections = array_keys($sections);
        //             $data['sections'][$formattedClassName] = $filteredSections;
        //             log_message('debug', 'Sections for Class ' . $formattedClassName . ': ' . print_r($filteredSections, true));
        //         }
        //     }
        // }
        foreach ($classesData as $className => $classDetails) {
            if (is_array($classDetails)) {

                // Push simple class name
                $data['classes'][] = $className;

                // Handle Sections
                if (!empty($classDetails['Section']) && is_array($classDetails['Section'])) {
                    $data['sections'][$className] = array_keys($classDetails['Section']);
                } else {
                    $data['sections'][$className] = [];
                }
            }
        }

        // Get the class name from the URL parameter
        $class = $this->input->get('class');

        // Pass the class name to the view if needed
        $data['class'] = $class;

        $this->load->view('include/header');
        $this->load->view('class_fees', $data);
        $this->load->view('include/footer');
    }

    public function due_fees_table()
    {
        $school_id = $this->parent_db_key;
        $school_name = $this->school_name;
        $session_year = $this->session_year;


        // Get class and section from POST data
        $class = $this->input->post('class');
        $section = $this->input->post('section');
        $formatted_class = $class . " '" . $section . "'";

        // Firebase paths
        $students_path = "Schools/{$school_name}/{$session_year}/{$formatted_class}/Students/List";
        $fees_path = "Schools/{$school_name}/{$session_year}/Accounts/Fees/Classes Fees/{$formatted_class}";
        $response = [];

        try {
            // Step 1: Fetch the list of student User IDs
            $student_ids = $this->firebase->get($students_path);
            if (empty($student_ids)) {
                $response[] = [
                    'userId' => null,
                    'name' => "No students found for class $formatted_class",
                    'totalFee' => null,
                    'receivedFee' => null,
                    'dueFee' => null,
                ];
                echo json_encode($response);
                return;
            }

            // Step 2: Calculate the Total Fee for the class
            $class_fees = $this->firebase->get($fees_path);
            if (empty($class_fees)) {
                $response[] = [
                    'userId' => null,
                    'name' => "No fee structure found for class $formatted_class",
                    'totalFee' => null,
                    'receivedFee' => null,
                    'dueFee' => null,
                ];
                echo json_encode($response);
                return;
            }

            $total_fee = 0;
            foreach ($class_fees as $month => $fees) {
                foreach ($fees as $key => $amount) {
                    $total_fee += $amount ?? 0; // Add 0 if the value is null
                }
            }

            // Step 3: Iterate over each student to fetch data
            foreach ($student_ids as $user_id => $value) {
                // Fetch student's name and father's name
                $name_path = "Users/Parents/{$school_id}/{$user_id}/Name";
                $father_name_path = "Users/Parents/{$school_id}/{$user_id}/Father Name";

                $name = $this->firebase->get($name_path) ?? "N/A";
                $father_name = $this->firebase->get($father_name_path) ?? "N/A";

                // Fetch submitted fees
                $fees_record_path = "Schools/{$school_name}/{$session_year}/{$formatted_class}/Students/{$user_id}/Fees Record";
                $fees_records = $this->firebase->get($fees_record_path);
                $submitted_fee = 0;

                if (!empty($fees_records)) {
                    foreach ($fees_records as $record) {
                        $submitted_fee += $record['Amount'] ?? 0;
                    }
                }

                // Calculate Due Fee
                $due_fee = $total_fee - $submitted_fee;

                // Prepare response entry
                $response[] = [
                    'userId' => $user_id,
                    'name' => "{$name} / {$father_name}",
                    'totalFee' => $total_fee,
                    'receivedFee' => $submitted_fee,
                    'dueFee' => $due_fee,
                ];
            }

            // Output the final JSON response
            echo json_encode($response);
        } catch (Exception $e) {
            // Handle Firebase or other exceptions
            $response[] = [
                'userId' => null,
                'name' => "Error: " . $e->getMessage(),
                'totalFee' => null,
                'receivedFee' => null,
                'dueFee' => null,
            ];
            echo json_encode($response);
        }
    }


    public function student_fees()
    {
        $this->load->view('include/header');
        $this->load->view('student_fees');
        $this->load->view('include/footer');
    }
    public function search_student()
    {

        // Initialize variables
        $searchResults = [];

        // Check if a search is triggered by name or user ID
        if ($this->input->post('search_name')) {
            $searchQuery = $this->input->post('search_name');
            // Call the search_by_name method to get search results
            $searchResults = $this->search_by_name($searchQuery);
        }

        // Make sure the output is clean and JSON-encoded
        header('Content-Type: application/json');
        // Return the search results as JSON
        echo json_encode($searchResults);
        exit;
    }
    private function search_by_name($entry)
    {
        $school_id = $this->parent_db_key;

        // Fetch data from Firebase based on the name
        $searchResults = [];
        $students = $this->CM->get_data('Users/Parents/' . $school_id); // Path to fetch all students

        if (!empty($students)) {
            foreach ($students as $userId => $student) {

                // Only include the data you need for the search and ensure the fields exist
                $name = isset($student['Name']) ? $student['Name'] : '';
                $userIdField = isset($student['User Id']) ? $student['User Id'] : '';
                $fatherName = isset($student['Father Name']) ? $student['Father Name'] : '';
                $class = isset($student['Class']) ? $student['Class'] : '';

                // Perform the search on the selected fields
                if (
                    stripos($name, $entry) !== false ||
                    stripos($userIdField, $entry) !== false ||
                    stripos($fatherName, $entry) !== false ||
                    stripos($class, $entry) !== false
                ) {

                    // If a match is found, add the student data to the results
                    $searchResults[] = [
                        'user_id' => $userIdField,
                        'name' => $name,
                        'father_name' => $fatherName,
                        'class' => $class,
                    ];
                }
            }
        }
        return $searchResults;
    }

    public function fetch_fee_receipts()
    {
        // $this->load->library('firebase');
        // $firebase = new Firebase();

        $school_id = $this->parent_db_key;
        $school_name = $this->school_name;
        $session_year = $this->session_year;


        $input = json_decode(file_get_contents('php://input'), true);
        $userId = $input['userId'];

        // Fetch User Information
        $userInfo = $this->firebase->get("Users/Parents/$school_id/$userId/");
        $studentName = $userInfo['Name'];
        $fatherName = $userInfo['Father Name'];
        $classInfo = $userInfo['Class'];

        $formatted_class = "Class " . $classInfo;

        // Fetch Fee Records
        $feeRecords = $this->firebase->get("Schools/$school_name/$session_year/$formatted_class/Students/$userId/Fees Record");

        $response = [];
        if (is_array($feeRecords)) {
            foreach ($feeRecords as $key => $record) {
                $receiptNo = str_replace('F', '', $key);
                $response[] = [
                    'receiptNo' => $receiptNo,
                    'date' => $record['Date'],
                    'student' => "$studentName / $fatherName",
                    'class' => $classInfo,
                    'amount' => $record['Amount'],
                    // 'convey' => $record['Conveyance'],
                    'fine' => $record['Fine'],
                    'account' => $record['Mode'],
                    'Id' => $userId
                ];
            }
        }
        echo json_encode($response);
    }


    public function fees_records()
    {
        $school_id = $this->parent_db_key;
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Step 1: Fetch Classes and Sections
        $classesData = $this->firebase->get("Schools/$school_name/$session_year/Classes");

        $classList = [];
        foreach ($classesData as $class => $details) {
            $trimmedClass = str_replace('Class ', '', $class);
            foreach ($details['Section'] as $section => $value) {
                $classList[] = "$trimmedClass '$section'";
            }
        }


        // Step 3: Initialize Matrix for 12 Months
        $feesMatrix = [];
        foreach ($classList as $class) {
            $feesMatrix[$class] = array_fill(0, 12, 0);
        }

        // Step 4: Fetch Vouchers
        $vouchers = $this->firebase->get("Schools/$school_name/$session_year/Accounts/Vouchers");


        // Step 5: Process Vouchers
        foreach ($vouchers as $date => $voucherList) {
            if ($date === "VoucherCount") continue;

            $dateObject = DateTime::createFromFormat('d-m-Y', $date);
            if (!$dateObject) {
                echo "<pre>Invalid Date Format: $date</pre>";
                continue;
            }

            $monthIndex = $dateObject->format('n') - 4;
            if ($monthIndex < 0) $monthIndex += 12;

            foreach ($voucherList as $key => $voucher) {
                if (!is_array($voucher) || !isset($voucher['Fees Received']) || strpos($key, 'F') !== 0) {
                    continue;
                }



                $studentId = $voucher['Id'];
                $classInfo = $this->firebase->get("Users/Parents/$school_id/$studentId/Class");
                $classInfo = trim($classInfo);

                if (in_array($classInfo, $classList)) {
                    $feesMatrix[$classInfo][$monthIndex] += $voucher['Fees Received'];
                }
            }
        }

        // Step 6: Create Fees Record Matrix
        $feesRecordMatrix = [];
        foreach ($feesMatrix as $class => $amounts) {
            $total = array_sum($amounts);
            $feesRecordMatrix[] = [
                'class' => $class,
                'amounts' => $amounts,
                'total' => $total
            ];
        }

        // Step 7: Pass Data to the View
        $data['fees_record_matrix'] = $feesRecordMatrix;
        $this->load->view('include/header');
        $this->load->view('fees_records', $data);
        $this->load->view('include/footer');
    }
    public function fees_counter()
    {

        $school_id = $this->parent_db_key;
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Initialize variables
        $studentData = [];
        $data['receiptNo'] = null;
        $totalAmount = 0;
        $totalSubmittedAmount = 0;
        $dueAmount = 0;
        $feeDetails = [];
        $classOnly = 'Class Name';
        $section = 'Section Name';
        $data['message'] = '';

        // Fetch receipt number from Firebase
        $receiptPath = 'Schools/' . $school_name . '/' . $session_year .  '/Accounts/Fees/Receipt No';
        $receiptData = $this->CM->get_data($receiptPath);
        $data['receiptNo'] = !empty($receiptData) ? $receiptData : '1';



        // Fetch accounts under "BANK ACCOUNT" and "CASH"
        $accountPath = 'Schools/' . $school_name . '/' . $session_year . '/Accounts/Account_book';
        $accountsData = $this->CM->get_data($accountPath);

        $filteredAccounts = [];

        if (!empty($accountsData) && is_array($accountsData)) {
            foreach ($accountsData as $accountName => $accountDetails) {
                if (isset($accountDetails['Under']) && in_array($accountDetails['Under'], ["BANK ACCOUNT", "CASH"])) {
                    $filteredAccounts[$accountName] = $accountDetails['Under'];
                }
            }
        }



        // Fetch server timestamp from Firebase
        $timestampPath = 'Schools/' . $school_name . '/' . $session_year . '/ServerTimestamp';
        $serverTimestamp = $this->CM->get_data($timestampPath);

        if (!empty($serverTimestamp)) {
            // Convert Firebase timestamp to readable format
            // $data['serverDate'] = date('Y-m-d H:i:s', $serverTimestamp / 1000);
            $data['serverDate'] = date('d-m-Y', $serverTimestamp / 1000);
        } else {
            $data['serverDate'] = 'Timestamp Not Found';
        }


        // Collect selected months from the form submission
        $selectedMonths = $this->input->post('months'); // Example: ['April', 'May', 'June']



        if (is_array($selectedMonths) && !empty($selectedMonths)) {
            $lastMonth = end($selectedMonths); // Get last element without removing it

            // Format the output properly
            $formattedMonths = count($selectedMonths) > 1
                ? implode(", ", array_slice($selectedMonths, 0, -1)) . " and " . $lastMonth
                : $lastMonth;
        } else {
            $formattedMonths = 'No Months Selected';
        }




        if ($this->input->post('user_id')) {
            $userId = $this->input->post('user_id');

            // Fetch student data using the user ID
            $studentData = $this->CM->get_data('Users/Parents/' . $school_id . '/' . $userId);

            if (!empty($studentData)) {
                $studentData = (array)$studentData;

                // Retrieve class and section from student data
                $classWithSection = $studentData['Class'] ?? 'Not Found';

                if (!empty($classWithSection)) {
                    $classParts = explode(' ', trim($classWithSection));

                    $classOnly = isset($classParts[0]) ? $classParts[0] : 'Not Found';
                    $section = isset($classParts[1]) ? trim($classParts[1], "'") : 'Not Found';

                    // Format class input box value (without "Class" prefix)
                    $data['classOnly'] = !empty($section) ? "{$classOnly} '{$section}'" : $classOnly;

                    // Merge class and section for the Firebase path
                    $mergedClassSection = !empty($section) ? "Class {$classOnly} '{$section}'" : "Class {$classOnly}";


                    // Fetch receipt number from Firebase
                    $receiptrecordPath = 'Schools/' . $school_name . '/' . $session_year . '/' . $mergedClassSection . '/Students/' . $userId . '/Fees Record';
                    $receiptrecordData = $this->CM->get_data($receiptrecordPath);

                    // Check if receipt data is found
                    $data['fee_records'] = !empty($receiptrecordData) ? $receiptrecordData : 'Not Found';
                    log_message('debug', "Fee details today: " . json_encode($data['fee_records']));


                    // Check if feeRecords are not empty and process them

                    // if ($data['fee_records'] && is_array($data['fee_records'])) {
                    if (!empty($data['fee_records']) && is_array($data['fee_records'])) {
                        foreach ($data['fee_records'] as $receiptno => $record) {
                            // Ensure it's an array (in case it's an object or something else)
                            $record = (array)$record;

                            // Use Firebase timestamp instead of system date
                            $feeDate = isset($record['Date']) ? $record['Date'] : 'N/A';

                            // Add the fee details to the feeDetails array
                            $feeDetails[] = [
                                'receiptno'  => $receiptno,
                                // 'Amount'     => number_format(floatval($record['Amount'] ?? 0), 2),
                                'Amount' => number_format(floatval(str_replace(',', '', $record['Amount'] ?? 0)), 2),

                                'Discount'   => number_format(floatval(str_replace(',', '', $record['Discount'] ?? 0)), 2),
                                'Date'       => $feeDate,
                                'Fine'       => number_format(floatval(str_replace(',', '', $record['Fine'] ?? 0)), 2),
                                'Mode'       => $record['Mode'] ?? 'N/A'
                            ];
                            log_message('debug', "Fee detailsdsf: " . json_encode($feeDetails));
                        }
                    } else {
                        echo 'No valid fee records found.';
                    }

                    //             $data['feeDetails'] = $feeDetails; // Save fee details to data
                    // echo '<pre>' . print_r($data['feeDetails'], true) . '</pre>';


                    log_message('debug', "Fee details bb: " . json_encode($feeDetails));

                    // Calculate the total submitted amount
                    if (!empty($feeDetails)) {
                        foreach ($feeDetails as $feeDetail) {
                            // Remove commas from the Amount value and convert to a float
                            $amount = isset($feeDetail['Amount']) ? floatval(str_replace(',', '', $feeDetail['Amount'])) : 0;
                            $totalSubmittedAmount += $amount;
                        }
                    }
                    log_message('debug', "Fee details: " . json_encode($feeDetails));


                    $oversubmittedFeesPath = "Schools/{$school_name}/{$session_year}/$mergedClassSection/Students/$userId/Oversubmittedfees";
                    $oversubmittedFees = $this->CM->get_data($oversubmittedFeesPath);

                    $totalSubmittedAmount = $totalSubmittedAmount ?? 0;
                    $oversubmittedFees = $oversubmittedFees ?? 0;

                    // Format the total submitted amount with 2 decimal places
                    $data['totalSubmittedAmount'] = number_format($totalSubmittedAmount, 2, '.', ',');
                    $data['oversubmittedFees'] = number_format($oversubmittedFees, 2, '.', ',');

                    $data['showMonthDropdown'] = true;


                    $data['message'] = '';


                    // If there are selected months, also calculate the total fees
                    if (!empty($selectedMonths) && is_array($selectedMonths)) {
                        // Call the function to get fees for selected months
                        $feesRecord = $this->getFeesForSelectedMonths($school_name, $mergedClassSection, $selectedMonths);

                        $exemptedFeesPath = "Schools/{$school_name}/{$session_year}/$mergedClassSection/Students/$userId/exempted_fees";
                        $exemptedFees = $this->CM->get_data($exemptedFeesPath);
                        $exemptedFees = !empty($exemptedFees) ? (array) $exemptedFees : []; // Convert to array if not empty

                        // Calculate total fees for the selected months
                        $totals = $this->calculateTotalFees($feesRecord, $selectedMonths, $exemptedFees); // Dynamic fee calculation

                        $feesRecord = [];

                        $discount = 0;
                        $totalAmount = 0;

                        // Accumulate fees and separate discounts
                        foreach ($totals as $feeTitle => $feeTotal) {
                            if (strtolower($feeTitle) === 'discount') {
                                $discount = $feeTotal;
                            } else {
                                $totalAmount += $feeTotal;
                            }
                            $feesRecord[] = [
                                'title' => $feeTitle,
                                'total' => $feeTotal
                            ];
                        }

                        // Apply the discount to the total amount
                        $totalAmount += $discount;

                        $DiscountPath = "Schools/{$school_name}/{$session_year}/$mergedClassSection/Students/$userId/Discount";
                        $DiscountData = $this->CM->get_data($DiscountPath);
                        // $discountAmount = $DiscountData['OnDemandDiscount'];
                        $discountAmount = isset($DiscountData['OnDemandDiscount']) ? $DiscountData['OnDemandDiscount'] : 0;


                        $data['feesRecord'] = $feesRecord;
                        $data['discountAmount'] = number_format($discountAmount, 2); // Total after discount

                        $data['totalAmount'] = number_format($totalAmount, 2); // Total after discount
                        $data['message'] = "Fee Details for :- " . $formattedMonths;
                        $data['selectedMonths'] = $selectedMonths;


                        // // Initialize Fee Data
                        $feeRecord = [];
                        $monthTotals = array_fill_keys($selectedMonths, 0);
                        $grandTotal = 0;
                        // Format Firebase Path
                        $basePath = "Schools/{$school_name}/{$session_year}/Accounts/Fees/Classes Fees/$mergedClassSection/";

                        $feeTitlesArray = []; // Initialize an empty array

                        foreach ($totals as $feetitle => $feeTotal) {
                            // Remove "(Yearly)" from the fee title
                            $cleanFeeTitle = str_replace(" (Yearly)", "", $feetitle);
                            $feeTitlesArray[] = $cleanFeeTitle;
                        }



                        log_message('debug', "Fee titles to be fetched: " . json_encode($feeTitlesArray));


                        // Fetch Data from Firebase
                        foreach ($feeTitlesArray as $feename) {

                            log_message('debug', "Fetching fee data for: {$feename}" . json_encode($selectedMonths));
                            $feeRecord[$feename] = ['title' => $feename, 'total' => 0];

                            foreach ($selectedMonths as $month) {
                                $feePath = $basePath . "$month/$feename";
                                $feeValue = $this->CM->get_data($feePath);

                                log_message('debug', "Fetched fee value for {$feename} in {$month}: " . json_encode($feeValue));

                                // Ensure feeValue is numeric (if null, default to 0)
                                $feeValue = is_numeric($feeValue) ? (float) $feeValue : 0;

                                // Store data
                                $feeRecord[$feename][$month] = $feeValue;
                                $monthTotals[$month] += $feeValue;
                                $feeRecord[$feename]['total'] += $feeValue;
                            }


                            log_message('debug', "Total fee for {$feename}: " . $feeRecord[$feename]['total']);
                            $grandTotal += $feeRecord[$feename]['total'];
                        }

                        log_message('debug', "Grand total: {$grandTotal}");
                        $data['feeRecord'] = $feeRecord;
                        $data['monthTotals'] = $monthTotals;
                        $data['grandTotal'] = $grandTotal;
                    }

                    // Calculate Due Amount
                    $dueAmount = $totalAmount - $oversubmittedFees - $discountAmount;
                    $data['dueAmount'] = number_format($dueAmount, 2);
                } else {
                    $data['error'] = 'Class not found for the student.';
                }
            } else {
                $studentData = [
                    'Name' => 'Not Found',
                    'Father Name' => 'Not Found',
                    'Class' => 'Not Found',
                    'Section' => 'Not Found',
                ];
                $data['error'] = 'Student not found. Please check the User ID.';
            }
        }

        $data['accounts'] = $filteredAccounts;
        // Pass data to the view
        $data['studentData'] = $studentData;
        // log_message('info', 'Fee Details Data: ' . json_encode($feeDetails));

        $data['feeDetails'] = $feeDetails; // Save fee details to data
        // echo '<pre>' . print_r($data['feeDetails'], true) . '</pre>';



        // $data['classOnly'] = $studentData['Class'];
        $data['classOnly'] = isset($studentData['Class']) ? $studentData['Class'] : 'Not Found';


        $data['section'] = $section;


        // Pass default values if not calculated
        if (!isset($data['totalSubmittedAmount'])) $data['totalSubmittedAmount'] = '00.00';
        if (!isset($data['dueAmount'])) $data['dueAmount'] = '00.00';

        // Load views
        $this->load->view('include/header');
        $this->load->view('fees_counter', $data);
        $this->load->view('include/footer');
    }

    public function fetch_months()
    {

        $school_id = $this->parent_db_key;
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        if ($this->input->post('user_id')) {
            $userId = $this->input->post('user_id');

            // Fetch student data using the user ID
            $studentData = $this->CM->get_data('Users/Parents/' . $school_id . '/' . $userId);

            if (!empty($studentData)) {
                $studentData = (array)$studentData;

                // Retrieve class and section from student data
                $classWithSection = isset($studentData['Class']) ? trim($studentData['Class']) : '';

                if (!empty($classWithSection)) {
                    $classParts = explode(' ', $classWithSection);
                    $classOnly = isset($classParts[0]) ? 'Class ' . $classParts[0] : ''; // Ensure proper class formatting
                    $section = isset($classParts[1]) ? trim($classParts[1], "'") : '';

                    // Merge class and section for Firebase path
                    $mergedClassSection = !empty($section) ? "{$classOnly} '$section'" : $classOnly;

                    // Firebase path to fetch month fee data
                    $monthFeePath = "Schools/{$school_name}/{$session_year}/{$mergedClassSection}/Students/{$userId}/Month Fee";
                    $monthFeesData = $this->CM->get_data($monthFeePath);

                    // Define all possible months
                    $months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December", "Yearly Fees"];
                    $monthFees = [];

                    foreach ($months as $month) {
                        $monthFees[$month] = isset($monthFeesData[$month]) ? $monthFeesData[$month] : 0;
                    }

                    // Send response
                    echo json_encode($monthFees);
                    return; // Stop execution after successful response
                }
            }
        }

        // If request is invalid or student not found
        echo json_encode(["error" => "Invalid request or student not found"]);
    }


    public function get_server_date()
    {
        echo json_encode(['date' => date('d-m-Y')]); // Format: DD-MM-YYYY
    }


    public function submit_fees()
    {
        $this->load->library('firebase');

        // $school_id = $this->parent_db_key;
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Collect and sanitize data
        $receiptNo = $this->input->post('receiptNo');
        $studentName = $this->input->post('studentName');
        $paymentMode = $this->input->post('paymentMode') ?: 'N/A';
        $fatherName = $this->input->post('fatherName');
        $class = $this->input->post('class');
        $userId = $this->input->post('userId');

        $totalAmount = floatval(str_replace(',', '', $this->input->post('totalAmount') ?? '0'));
        $submitAmount = floatval(str_replace(',', '', $this->input->post('submitAmount') ?? '0'));
        $dueAmount = floatval(str_replace(',', '', $this->input->post('dueAmount') ?? '0'));
        $schoolFees = floatval(str_replace(',', '', $this->input->post('schoolFees') ?? '0'));
        $discountFees = floatval(str_replace(',', '', $this->input->post('discountAmount') ?? '0'));
        $fineAmount = floatval(str_replace(',', '', $this->input->post('fineAmount') ?? '0'));

        $reference = $this->input->post('reference') ?: "Fees Submitted";
        $selectedMonths = $this->input->post('selectedMonths') ?? [];
        $MonthTotal = $this->input->post('monthTotals') ?? [];

        if (!is_array($selectedMonths)) {
            $selectedMonths = explode(',', $selectedMonths);
        }

        $monthTotalsArray = [];
        foreach ($MonthTotal as $monthData) {
            if (isset($monthData['month']) && isset($monthData['total'])) {
                $monthTotalsArray[trim($monthData['month'])] = floatval(str_replace(',', '', $monthData['total']));
            }
        }

        $date = date('d-m-Y');
        $date_obj = DateTime::createFromFormat('d-m-Y', $date);
        $month = ($date_obj !== false) ? $date_obj->format('F') : date('F');
        $day = ($date_obj !== false) ? $date_obj->format('d') : date('d');

        $mergedClassSection = 'Class ' . $class;
        $receiptKey = 'F' . $receiptNo;

        // Firebase Paths for Discount
        $firebasePath = "Schools/$school_name/$session_year/$mergedClassSection/Students/$userId/Discount/OnDemandDiscount";
        $firebasePath2 = "Schools/$school_name/$session_year/$mergedClassSection/Students/$userId/Discount/totalDiscount";

        try {
            log_message('info', "Attempting to save OnDemandDiscount at path: " . $firebasePath);
            $this->firebase->set($firebasePath, 0);

            log_message('info', "Fetching current totalDiscount from path: " . $firebasePath2);
            $currentTotal = $this->firebase->get($firebasePath2);
            $currentTotal = is_numeric($currentTotal) ? (int)$currentTotal : 0;

            log_message('info', "Fetched totalDiscount: " . $currentTotal);
            $newTotal = $currentTotal + (int)$discountFees;
            log_message('info', "Calculated new totalDiscount: " . $newTotal);

            $this->firebase->set($firebasePath2, $newTotal);
            log_message('info', "Updated totalDiscount successfully at path: " . $firebasePath2);
        } catch (Exception $e) {
            log_message('error', "Firebase Error: " . $e->getMessage());
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
            return;
        }

        // Store Fees Record
        $feesRecordPath = "Schools/$school_name/$session_year/$mergedClassSection/Students/$userId/Fees Record";
        $feesRecordData = [
            $receiptKey => [

                'Amount' => number_format($schoolFees, 2, '.', ','),  // Fixed: Adds thousands separator
                'Discount' => number_format($discountFees, 2, '.', ','),
                'Date' => $date,
                'Fine' => number_format($fineAmount, 2, '.', ','),
                'Mode' => $paymentMode,
                'Refer' => $reference,
            ]
        ];
        $this->firebase->update($feesRecordPath, $feesRecordData);

        // Store Vouchers Data
        $vouchersPath = "Schools/$school_name/$session_year/Accounts/Vouchers/$date";
        $vouchersData = [
            $receiptKey => [
                'Acc' => 'Fees',
                'Fees Received' => number_format($schoolFees, 2),
                'Id' => $userId,
                'Mode' => $paymentMode,
            ]
        ];
        $this->firebase->update($vouchersPath, $vouchersData);

        // Account Paths
        $account_discount = "Schools/$school_name/$session_year/Accounts/Account_book/Discount/{$month}/{$day}";
        $account_fees = "Schools/$school_name/$session_year/Accounts/Account_book/Fees/{$month}/{$day}";
        $account_fine = "Schools/$school_name/$session_year/Accounts/Account_book/Fine/{$month}/{$day}";

        $updateReceived = function ($path, $amount) {
            $received_path = "{$path}/R";
            $current_received = floatval($this->firebase->get($received_path) ?? "0");
            $new_received = $current_received + $amount;
            $this->firebase->set($received_path, $new_received);
        };

        $updateReceived($account_discount, $discountFees);
        $updateReceived($account_fees, $schoolFees);
        $updateReceived($account_fine, $fineAmount);

        // Increment Receipt Count
        $receiptCountPath = "Schools/$school_name/$session_year/Accounts/Fees/Receipt No";
        $currentCount = $this->firebase->get($receiptCountPath) ?: 0;
        $this->firebase->set($receiptCountPath, $currentCount + 1);

        // Monthly Fee Processing
        $totalSubmitted = $schoolFees + $submitAmount;
        $monthOrder = ["April", "May", "June", "July", "August", "September", "October", "November", "December", "January", "February", "March", "Yearly Fees"];

        usort($selectedMonths, function ($a, $b) use ($monthOrder) {
            return array_search($a, $monthOrder) - array_search($b, $monthOrder);
        });

        foreach ($selectedMonths as $month) {
            $monthFee = $monthTotalsArray[$month] ?? 0;

            if ($monthFee > 0 && $totalSubmitted >= $monthFee) {
                $firebaseMonthPath = "Schools/$school_name/$session_year/$mergedClassSection/Students/$userId/Month Fee/$month";
                $this->firebase->set($firebaseMonthPath, 1);
                $totalSubmitted -= $monthFee;
            }
        }

        if ($totalSubmitted > 0) {
            $oversubmittedPath = "Schools/$school_name/$session_year/$mergedClassSection/Students/$userId/Oversubmittedfees";
            $this->firebase->set($oversubmittedPath, $totalSubmitted);
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Fees submitted successfully!',
        ]);
    }


    public function getFeesForSelectedMonths($school_name, $classSection, $selectedMonths)
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        $feesData = []; // Array to store fees data for each month
        // Loop through each selected month and fetch the data from Firebase
        foreach ($selectedMonths as $month) {

            $path = "Schools/$school_name/$session_year/Accounts/Fees/Classes Fees/$classSection/$month";
            // echo '<pre>' . print_r($path, true) . '</pre>';

            $monthFees = $this->CM->get_data($path);

            if (is_array($monthFees)) {
                // Decode the JSON response and store it in the feesData array
                $feesData[$month] = $monthFees;
            } else {

                // If it's a string (assumed to be JSON), decode it
                $feesData[$month] = json_decode($monthFees, true);
            }
        }

        return $feesData;
    }

    // Function to calculate the total fees for selected months dynamically
    public function calculateTotalFees($feesRecord, $selectedMonths, $exemptedFees)
    {
        $totals = [];

        foreach ($selectedMonths as $month) {
            if (isset($feesRecord[$month]) && is_array($feesRecord[$month])) {
                foreach ($feesRecord[$month] as $feeTitle => $feeAmount) {

                    // Remove "(Yearly)" from the fee title while fetching values
                    $cleanFeeTitle = str_replace(" (Yearly)", "", $feeTitle);

                    // Check if fee title is in exemptedFees, if yes, skip it
                    if (array_key_exists($cleanFeeTitle, $exemptedFees)) {
                        continue; // Skip this fee as it's exempted
                    }

                    // If the fee belongs to "Yearly Fees", keep the "(Yearly)" suffix for display
                    $displayFeeTitle = ($month === "Yearly Fees") ? "$cleanFeeTitle (Yearly)" : $cleanFeeTitle;

                    if (!isset($totals[$displayFeeTitle])) {
                        $totals[$displayFeeTitle] = 0;
                    }

                    $totals[$displayFeeTitle] += floatval($feeAmount);
                }
            } else {
                log_message('error', "Missing data for month: $month in feesRecord.");
            }
        }

        return $totals;
    }
}
