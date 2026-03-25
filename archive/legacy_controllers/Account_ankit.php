<?php

class Account extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function account_book()
    {

        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Fetch account data from Firebase
        $accounts = $this->firebase->get("Schools/$school_name/$session_year/Accounts/Account_book");

        // Calculate the current financial session
        $currentYear = date('Y');
        $currentMonth = date('m');
        $current_session = ($currentMonth < 4) ? ($currentYear - 1) . '-' . $currentYear : $currentYear . '-' . ($currentYear + 1);

        // Check if this is an AJAX request
        if ($this->input->is_ajax_request()) {
            $selectedAccountName = $this->input->post('selectedAccountName');
            // log_message('debug', 'AJAX request received with selectedAccountName: ' . $selectedAccountName);

            if (isset($accounts[$selectedAccountName])) {
                echo json_encode([
                    'selectedAccount' => $accounts[$selectedAccountName],
                    'current_session' => $current_session,
                    //  log_message('debug', 'data: ' . print_r($accounts))


                ]);
            } else {
                log_message('error', 'Account not found: ' . $selectedAccountName);
                echo json_encode(['error' => 'Account not found']);
            }
            return;
        }
        // Pass the fetched data and the current session to the view
        $data['accounts'] = $accounts;
        $data['current_session'] = $current_session;
        //  log_message('debug', 'data: ' . print_r($data));

        // Load views
        $this->load->view('include/header');
        $this->load->view('account_book', $data); // Pass the data to the view
        $this->load->view('include/footer');
    }
    // Function to populate the table with month-wise transactions from Firebase
    public function populateTable()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Fetch account data from Firebase
        $accountName = $this->input->post('selectedAccountName'); // The selected account name from the view
        $accountData = $this->firebase->get("Schools/$school_name/$session_year/Accounts/Account_book/$accountName");

        // Initialize months and corresponding data
        $months = ['April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December', 'January', 'February', 'March'];
        $matrix = array_fill(0, 12, ['Opening' => 0.00, 'Received' => 0.00, 'Payments' => 0.00, 'Balance' => 0.00]);

        $previousMonthBalance = 0.00; // This will hold the balance of the previous month
        $accountCreationMonth = null;

        // Step 1: Loop through the months to calculate Opening, Received, Payments, and Balance
        foreach ($months as $index => $month) {
            $monthData = isset($accountData[$month]) ? $accountData[$month] : [];

            $totalReceived = 0.00;
            $totalPayments = 0.00;

            // Loop through each date in the month to sum up Received and Payments
            foreach ($monthData as $date => $data) {
                if (!empty($data['R'])) {
                    $totalReceived += (float) $data['R'];
                }
                if (!empty($data['P'])) {
                    $totalPayments += (float) $data['P'];
                }

                // Handle account opening for the month of creation
                if ($index == 0 && !empty($data['Opening'])) {
                    $matrix[$index]['Opening'] = (float) $data['Opening']; // Opening for April
                }
            }


            // Set the received and payments for the current month
            $matrix[$index]['Received'] = $totalReceived;
            $matrix[$index]['Payments'] = $totalPayments;

            // Calculate the opening for the month
            if ($index > 0) {
                // For months after the account creation, add the opening value for that month to the balance of the previous month
                if ($index == $accountCreationMonth && isset($monthData['1']['Opening'])) {
                    $matrix[$index]['Opening'] = $previousMonthBalance + (float) $monthData['1']['Opening'];
                } else {
                    $matrix[$index]['Opening'] = $previousMonthBalance;
                }
            }

            // Calculate the balance for the current month
            $matrix[$index]['Balance'] = $matrix[$index]['Opening'] + $matrix[$index]['Received'] - $matrix[$index]['Payments'];

            // Update the previous month's balance
            $previousMonthBalance = $matrix[$index]['Balance'];
        }

        // Step 2: Calculate totals for Received and Payments
        $totalReceived = array_sum(array_column($matrix, 'Received'));
        $totalPayments = array_sum(array_column($matrix, 'Payments'));

        // Step 3: Return the results as JSON
        echo json_encode([
            'matrix' => $matrix,
            'totalReceived' => $totalReceived,
            'totalPayments' => $totalPayments
        ]);
    }
    public function create_account()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Get form data
        $accountName = $this->input->post('accountName');
        $subGroup = $this->input->post('subGroup');
        $openingAmount = $this->input->post('openingAmount');

        // Ensure account name is sanitized for Firebase (no slashes or periods)
        $accountName = str_replace(['/', '.'], '-', $accountName);

        // Get the current date for the creation date
        $createdOn = date('d/m/Y');
        $createdMonth = date('F'); // Full month name (e.g., 'October')
        $createdDay = date('j');   // Day of the month (e.g., '13')

        // Set up the account structure in the desired format
        $accountData = [
            'Created On' => $createdOn,
            'Under' => $subGroup,
            'April' => [
                '1' => [
                    'Opening' => $openingAmount, // Financial year opening default
                    'P' => '0',
                    'R' => '0'
                ]
            ],
            $createdMonth => [
                $createdDay => [
                    'P' => '0',
                    'R' => '0'
                ]
            ],
            // Default month structure for other months
            'May' => ['1' => ['P' => '0', 'R' => '0']],
            'June' => ['1' => ['P' => '0', 'R' => '0']],
            'July' => ['1' => ['P' => '0', 'R' => '0']],
            'August' => ['1' => ['P' => '0', 'R' => '0']],
            'September' => ['1' => ['P' => '0', 'R' => '0']],
            'October' => ['1' => ['P' => '0', 'R' => '0']],
            'November' => ['1' => ['P' => '0', 'R' => '0']],
            'December' => ['1' => ['P' => '0', 'R' => '0']],
            'January' => ['1' => ['P' => '0', 'R' => '0']],
            'February' => ['1' => ['P' => '0', 'R' => '0']],
            'March' => ['1' => ['P' => '0', 'R' => '0']]
        ];

        // Add the current day to the specific month
        $accountData[$createdMonth][$createdDay] = [
            'P' => '0',
            'R' => '0'
        ];

        // Add additional fields if provided
        $optionalFields = [
            'branchName' => $this->input->post('branchName'),
            'accountHolder' => $this->input->post('accountHolder'),
            'accountNumber' => $this->input->post('accountNumber'),
            'ifscCode' => $this->input->post('ifscCode'),
        ];

        foreach ($optionalFields as $key => $value) {
            if (!empty($value)) {
                $accountData[$key] = $value;
            }
        }



        $firebasePath = "Schools/$school_name/$session_year/Accounts/Account_book";

        // Use update() instead of set() to avoid overwriting the entire Account_book node
        $this->firebase->update("$firebasePath/$accountName", $accountData);

        // Redirect to the Account Book page after creation
        redirect(base_url() . 'account/account_book');
    }
    public function check_account()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Get the account name from POST data
        $accountName = $this->input->post('accountName');


        // Check if accountName is null or empty
        if (is_null($accountName) || trim($accountName) === '') {
            echo json_encode(['exists' => false]);
            return;
        }

        // Trim whitespace
        $accountName = trim($accountName);

        // Fetch the existing accounts from Firebase
        $existingAccounts = $this->firebase->get("Schools/$school_name/$session_year/Accounts/Account_book");

        // Initialize the exists flag
        $exists = false;

        // Check if existingAccounts is an array
        if (is_array($existingAccounts)) {
            // Iterate through the keys to check for the account name
            foreach (array_keys($existingAccounts) as $key) {
                // If the account name matches the key
                if (strcasecmp($key, $accountName) === 0) { // Case-insensitive comparison
                    $exists = true; // Account exists
                    break; // Exit the loop as we found the account
                }
            }
        }

        // Return the result as JSON
        echo json_encode(['exists' => $exists]);
    }
    public function update_account()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Get form data
        $accountId = $this->input->post('accountId'); // Original account ID (current name)
        $newAccountName = $this->input->post('accountName'); // New account name
        $subGroup = $this->input->post('subGroup'); // Sub-group
        $branchName = $this->input->post('branchName');
        $accountHolder = $this->input->post('accountHolder');
        $accountNumber = $this->input->post('accountNumber');
        $ifscCode = $this->input->post('ifscCode');

        // Validate input
        if ($accountId && $newAccountName && $subGroup) {
            // Firebase path of the existing account
            $firebasePath = "Schools/$school_name/$session_year/Accounts/Account_book/$accountId";

            // Retrieve existing account data
            $accountData = $this->firebase->get($firebasePath);

            if ($accountData) {
                // Update account data
                $accountData['Under'] = $subGroup;
                $accountData['branchName'] = $branchName;
                $accountData['accountHolder'] = $accountHolder;
                $accountData['accountNumber'] = $accountNumber;
                $accountData['ifscCode'] = $ifscCode;

                // New Firebase path for the updated account
                $newFirebasePath = "Schools/$school_name/$session_year/Accounts/Account_book/$newAccountName";

                // Save the updated data
                $this->firebase->set($newFirebasePath, $accountData);

                // Delete old account if the name has changed
                if ($accountId !== $newAccountName) {
                    $this->firebase->delete($firebasePath);
                }

                // Success response
                $this->output
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['success' => true, 'message' => 'Account updated successfully']));
            } else {
                // Error: Account not found
                $this->output
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['success' => false, 'message' => 'Account not found']));
            }
        } else {
            // Error: Invalid input
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(['success' => false, 'message' => 'Invalid input']));
        }
    }


    public function delete_account()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        $accountName = $this->input->post('accountName');

        if ($accountName) {
            // Remove the account from Firebase
            try {
                $this->firebase->delete("Schools/$school_name/$session_year/Accounts/Account_book/$accountName");
                // Return success response
                echo json_encode(['status' => 'success']);
            } catch (Exception $e) {
                // Handle error and return failure response
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        } else {
            // Handle the case where accountName is not provided
            echo json_encode(['status' => 'error', 'message' => 'No account name provided']);
        }
    }
    public function test()
    {
        $this->load->view('include/header');
        $this->load->view('test');
        $this->load->view('include/footer');
    }




    public function vouchers()
    {

        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Fetching data using the predefined 'get' function from your Firebase library
        $accounts = $this->firebase->get("Schools/$school_name/$session_year/Accounts/Account_book");

        // Initialize arrays to store account names
        $accountsList = [];
        $accountsUnderBankAccount = [];

        // Check if accounts data is valid
        if (is_array($accounts) && !empty($accounts)) {
            foreach ($accounts as $accountName => $accountDetails) {
                // Check if 'Under' key exists and matches either "BANK ACCOUNT" or "CASH"
                if (isset($accountDetails['Under']) && ($accountDetails['Under'] === "BANK ACCOUNT" || $accountDetails['Under'] === "CASH")) {
                    $accountsUnderBankAccount[] = $accountName;
                }
            }
            $accountsList = array_keys($accounts); // Immediate children keys
        }

        // Prepare data to send to the view
        $data['accounts'] = $accountsList;
        $data['accounts_2'] = $accountsUnderBankAccount; // Accounts under "BANK ACCOUNT"

        // Load the manage_voucher view and pass the accounts data
        $this->load->view('include/header');
        $this->load->view('manage_voucher', $data);
        $this->load->view('include/footer');
    }


    public function save_voucher()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        $date = $this->input->post('date'); // Get the date from the request
        $voucher = $this->input->post('voucher'); // Get the voucher array from the request

        // Extract month and day from the date
        $date_obj = DateTime::createFromFormat('d-m-Y', $date);
        $month = $date_obj->format('F'); // e.g., "April"
        $day = $date_obj->format('d'); // e.g., "01"

        // Fetch the voucher count from Firebase
        $count_path = "Schools/{$school_name}/{$session_year}/Accounts/Vouchers/VoucherCount";
        $voucher_count = $this->firebase->get($count_path);

        // Define the path for the voucher
        $voucher_path = "Schools/{$school_name}/{$session_year}/Accounts/Vouchers/{$date}/{$voucher_count}";

        // Save the voucher in Firebase
        $this->firebase->set($voucher_path, $voucher);

        // Increment the VoucherCount
        $new_count = $voucher_count + 1;
        $this->firebase->set($count_path, $new_count);

        // Get the account name from the voucher data
        $account_name = $voucher['Acc'];
        $mode = $voucher['Mode'];

        // Define the base path for the account book
        $account_path = "Schools/{$school_name}/{$session_year}/Accounts/Account_book/{$account_name}/{$month}/{$day}";
        $modepath = "Schools/{$school_name}/{$session_year}/Accounts/Account_book/{$mode}/{$month}/{$day}";


        // Check if 'Received' or 'Payment' exists in the voucher and update the respective path
        if (isset($voucher['Receipt'])) {
            // Fetch current 'Received' value, if exists
            $received_path = "{$account_path}/R";
            $current_received = $this->firebase->get($received_path) ?? 0; // Default to 0 if not present

            // Add the new received amount to the existing value
            $new_received = $current_received + $voucher['Receipt'];
            $this->firebase->set($received_path, $new_received);

            $received_mode_path = "{$modepath}/R";
            $current_mode_received = $this->firebase->get($received_mode_path) ?? 0; // Default to 0 if not present

            // Add the new received amount to the existing value
            $new_received2 = $current_mode_received + $voucher['Receipt'];
            $this->firebase->set($received_mode_path, $new_received2);





            // Also set 'Payment' to 0 if not already set
            // $payment_path = "{$account_path}/P";
            // $current_payment = $this->firebase->get($payment_path) ?? 0;
            // $this->firebase->set($payment_path, $current_payment); // Ensure 'Payment' key exists
        }

        if (isset($voucher['Payment'])) {
            // Fetch current 'Payment' value, if exists
            $payment_path = "{$account_path}/P";
            $current_payment = $this->firebase->get($payment_path) ?? 0; // Default to 0 if not present

            // Add the new payment amount to the existing value
            $new_payment = $current_payment + $voucher['Payment'];
            $this->firebase->set($payment_path, $new_payment);

            $payment_mode_path = "{$modepath}/P";
            $current_mode_payment = $this->firebase->get($payment_mode_path) ?? 0; // Default to 0 if not present

            // Add the new received amount to the existing value
            $new_payment2 = $current_mode_payment + $voucher['Payment'];
            $this->firebase->set($payment_mode_path, $new_payment2);
        }
        if (isset($voucher['Contra'])) {
            // Fetch current 'Received' value, if exists
            $received_path = "{$account_path}/P";
            $current_received = $this->firebase->get($received_path) ?? 0; // Default to 0 if not present

            // Add the new received amount to the existing value
            $new_received = $current_received + $voucher['Contra'];
            $this->firebase->set($received_path, $new_received);

            $received_mode_path = "{$modepath}/R";
            $current_mode_received = $this->firebase->get($received_mode_path) ?? 0; // Default to 0 if not present

            // Add the new received amount to the existing value
            $new_received2 = $current_mode_received + $voucher['Contra'];
            $this->firebase->set($received_mode_path, $new_received2);
        }
        if (isset($voucher['Journal'])) {

            // // Fetch current 'Payment' value, if exists
            // $payment_path = "{$account_path}/P";
            // $current_payment = $this->firebase->get($payment_path) ?? 0; // Default to 0 if not present

            // // Add the new payment amount to the existing value
            // $new_payment = $current_payment + $voucher['Payment'];
            // $this->firebase->set($payment_path, $new_payment);

            // $payment_mode_path = "{$modepath}/P"; 
            // $current_mode_payment = $this->firebase->get($payment_mode_path) ?? 0; // Default to 0 if not present

            // // Add the new received amount to the existing value
            // $new_payment2 = $current_mode_payment + $voucher['Payment'];
            // $this->firebase->set($payment_mode_path, $new_payment2);


            // Also set 'Received' to 0 if not already set
            // $received_path = "{$account_path}/R";
            // $current_received = $this->firebase->get($received_path) ?? 0;
            // $this->firebase->set($received_path, $current_received); // Ensure 'Received' key exists
        }

        // Respond with success message
        echo json_encode(['status' => 'success']);
    }
    public function view_voucher()
    {
        $this->load->view('include/header');
        $this->load->view('view_voucher');
        $this->load->view('include/footer');
    }


    public function show_vouchers()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Get the POST data from the AJAX request
        $from_date = $this->input->post('from_date');
        $to_date = $this->input->post('to_date');
        $voucher_type = $this->input->post('voucher_type');


        // Convert dates into a format that Firebase can query
        $from_date = date('d-m-Y', strtotime($from_date));
        $to_date = date('d-m-Y', strtotime($to_date));

        // Define the path where vouchers are stored
        $vouchers_path = "Schools/{$school_name}/{$session_year}/Accounts/Vouchers/";
            log_message('debug', 'Firebase Path: ' . $vouchers_path);
        

        // Fetch vouchers from Firebase for the selected date range
        $vouchers_data = $this->firebase->get($vouchers_path);

        // Prepare the final data structure
        $prepared_vouchers = [];

        // Filter vouchers based on the date range and voucher type
        foreach ($vouchers_data as $date => $vouchers_on_date) {
            if ($date >= $from_date && $date <= $to_date) {
                foreach ($vouchers_on_date as $voucher_details) {
                    // Null check to skip empty entries (e.g., null entries)
                    if (is_null($voucher_details)) {
                        continue;
                    }

                    // Extract the 'Acc', 'Mode', and 'Refer' details once per voucher
                    $particular = $voucher_details['Acc'];
                    $payment_mode = $voucher_details['Mode'];
                    $refer = isset($voucher_details['Refer']) ? $voucher_details['Refer'] : '';
                    $id = isset($voucher_details['Id']) ? $voucher_details['Id'] : null;

                    // Loop through and identify type by checking specific keys
                    foreach (['Payment', 'Receipt', 'Journal', 'Contra'] as $type_key) {
                        if (isset($voucher_details[$type_key])) {
                            // Check if voucher type matches the selected type or is 'All'
                            if ($voucher_type == 'All' || $voucher_type == $type_key) {
                                // Prepare the voucher data in the desired format
                                $voucher_entry = [
                                    'Date' => $date,
                                    'Type' => $type_key, // Type based on key (Payment, Received, Fees Received)
                                    'Particular' => $particular, // Account Name (Particular)
                                    'Payment Mode' => $payment_mode, // Payment Mode
                                    'Dr. Amt' => null, // Initialize with 0
                                    'Cr. Amt' => null, // Initialize with 0
                                    'Refer' => $refer // Reference
                                ];

                                // Set Dr. Amt or Cr. Amt based on the type
                                if ($type_key == 'Payment' || $type_key == 'Journal') {
                                    $voucher_entry['Dr. Amt'] = $voucher_details[$type_key]; // Debit amount (Payment)
                                } elseif ($type_key == 'Receipt' || $type_key == 'Contra') {
                                    $voucher_entry['Cr. Amt'] = $voucher_details[$type_key]; // Credit amount (Received/Fee Received)
                                }

                                // Include Id if available
                                if ($id) {
                                    $voucher_entry['Id'] = $id;
                                }

                                // Add the prepared voucher to the final data
                                $prepared_vouchers[] = $voucher_entry;
                            }
                        }
                    }
                }
            }
        }

        // Sort the vouchers in descending order of dates
        usort($prepared_vouchers, function ($a, $b) {
            // Convert date strings to timestamps for comparison
            $dateA = strtotime($a['Date']);
            $dateB = strtotime($b['Date']);
            return $dateB <=> $dateA; // Descending order
        });

        // Return the prepared vouchers data as a response
        echo json_encode($prepared_vouchers);
    }


    public function day_book()
    {



        $this->load->view('include/header');
        $this->load->view('day_book');
        $this->load->view('include/footer');
    }
    public function view_accounts()
    {

        $school_name = $this->school_name;
        $session_year = $this->session_year;

        if ($this->input->post()) {
            log_message('debug', '✅ Received POST request.');


            $path = 'Schools/' . $school_name . '/' . $session_year . '/Accounts/Vouchers';
            log_message('debug', 'Firebase Path: ' . $path);

            // Fetch form inputs
            $fromDate = $this->input->post('fromDate');
            $toDate = $this->input->post('toDate');
            $accountType = $this->input->post('accountType');

            log_message('debug', "Filtering with From: {$fromDate}, To: {$toDate}, AccountType: {$accountType}");

            // Convert dates
            $fromDateFormatted = DateTime::createFromFormat('d-m-Y', $fromDate);
            $toDateFormatted = DateTime::createFromFormat('d-m-Y', $toDate);

            if ($fromDateFormatted && $toDateFormatted) {
                $fromDateFormatted = $fromDateFormatted->format('d-m-Y'); // Keeping same format
                $toDateFormatted = $toDateFormatted->format('d-m-Y');
                log_message('debug', "Formatted Dates - From: {$fromDateFormatted}, To: {$toDateFormatted}");
            } else {
                log_message('error', '❌ Invalid date format received.');
                echo json_encode(['status' => 'error', 'message' => 'Invalid date format']);
                return;
            }

            // Fetch data from Firebase
            $this->load->library('firebase');
            $vouchersData = $this->firebase->get($path);
            log_message('debug', '📂 Vouchers Data Retrieved: ' . print_r($vouchersData, true));

            $filteredVouchers = [];

            if (!empty($vouchersData) && is_array($vouchersData)) {
                log_message('debug', '✅ Processing Vouchers...');

                foreach ($vouchersData as $voucherDate => $voucherDetails) {
                    $voucherDateFormatted = DateTime::createFromFormat('d-m-Y', $voucherDate);

                    if (!$voucherDateFormatted) {
                        log_message('error', "⚠️ Skipping invalid date: {$voucherDate}");
                        continue;
                    }

                    $voucherDateFormatted = $voucherDateFormatted->format('d-m-Y'); // Keeping d-m-Y format

                    if ($voucherDateFormatted >= $fromDateFormatted && $voucherDateFormatted <= $toDateFormatted) {
                        foreach ($voucherDetails as $key => $details) {
                            if (is_array($details) && ($accountType == 'All' || (isset($details['Acc']) && $details['Acc'] == $accountType))) {
                                foreach (['Payment', 'Received'] as $typeKey) {
                                    if (isset($details[$typeKey])) {
                                        $voucherEntry = [
                                            'Date' => $voucherDateFormatted,
                                            'Type' => $typeKey,
                                            'Particulars' => $details['Acc'],
                                            'Cr Amt' => $typeKey == 'Payment' ? '' : $details[$typeKey],
                                            'Dr Amt' => $typeKey == 'Payment' ? $details[$typeKey] : '',
                                            'Mode' => $details['Mode'] ?? ''
                                        ];
                                        $filteredVouchers[] = $voucherEntry;
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                log_message('error', '❌ No vouchers found.');
                echo json_encode(['status' => 'error', 'message' => 'No vouchers found']);
                return;
            }

            // 🚀 Log unsorted vouchers
            log_message('debug', '🔍 Unsorted Vouchers: ' . print_r($filteredVouchers, true));

            // ✅ Sort vouchers by date descending (keeping d-m-Y format)
            usort($filteredVouchers, function ($a, $b) {
                $dateA = DateTime::createFromFormat('d-m-Y', $a['Date']);
                $dateB = DateTime::createFromFormat('d-m-Y', $b['Date']);
                return $dateB->getTimestamp() - $dateA->getTimestamp();
            });

            // 🚀 Log sorted vouchers
            log_message('debug', '✅ Sorted Vouchers: ' . print_r($filteredVouchers, true));

            // ✅ Return JSON response
            echo json_encode(['status' => 'success', 'data' => $filteredVouchers]);
        } else {
            log_message('debug', '🌐 Loading view_accounts page.');


            // Fetch account types
            $path = 'Schools/' . $school_name . '/' . $session_year . '/Accounts/Account_book';
            $accountKeys = $this->CM->get_data($path);
            $data['accountTypes'] = !empty($accountKeys) ? array_keys($accountKeys) : [];

            log_message('debug', '📂 Account Types Retrieved: ' . print_r($data['accountTypes'], true));

            $this->load->view('include/header');
            $this->load->view('view_accounts', $data);
            $this->load->view('include/footer');
        }
    }





    public function cash_book()
    {
        // Load Firebase Library
        $this->load->library('firebase');
        $this->load->view('include/header');

        // Fetch account balances
        $accountBalances = $this->calculate_current_balances();

        // Pass data to the view
        $data['accounts'] = $accountBalances;
        $this->load->view('cash_book', $data);
        $this->load->view('include/footer');
    }

    // Function to get today's date
    public function get_server_date()
    {
        echo json_encode(['date' => date('d-m-Y')]); // Format: DD-MM-YYYY
    }


    private function calculate_current_balances()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Fetch today's date
        $todayDate = date('d-m-Y');
        $todayParts = explode('-', $todayDate);
        $currentDay = (int) $todayParts[0];
        $currentMonth = (int) $todayParts[1]; // Month as number (1 = Jan, 12 = Dec)
        $currentYear = (int) $todayParts[2];

        // Determine financial year start (April 1st of current or previous year)
        $financialYearStart = ($currentMonth >= 4) ? $currentYear : ($currentYear - 1);

        log_message('debug', "Today's Date: $todayDate | Financial Year Start: $financialYearStart");

        // Fetch account data from Firebase
        $firebase = $this->firebase->getDatabase();
        $accountsRef = $firebase->getReference("/Schools/$school_name/$session_year/Accounts/Account_book");
        $accountsData = $accountsRef->getValue();

        if (!$accountsData) {
            log_message('error', 'No account data found in Firebase.');
            return [];
        }

        log_message('debug', 'Fetched account data from Firebase successfully.');

        $filteredAccounts = [];

        foreach ($accountsData as $accountName => $accountDetails) {
            if (!isset($accountDetails['Under'])) continue;

            $accountType = $accountDetails['Under'];

            // Only process "CASH" and "BANK ACCOUNT" types
            if ($accountType === "CASH" || $accountType === "BANK ACCOUNT") {
                log_message('debug', "Processing account: $accountName ($accountType)");

                $currentBalance = 0;
                $openingBalance = 0;
                $totalReceived = 0;
                $totalPayment = 0;
                $openingSet = false;

                // ✅ Get the opening balance (April-Dec first, then Jan-Mar)
                $monthsToCheck = range(4, 12); // April to December

                // If current month is Jan, Feb, or March, also check those months
                if ($currentMonth <= 3) {
                    $monthsToCheck = array_merge($monthsToCheck, range(1, 3));
                }

                foreach ($monthsToCheck as $month) {
                    $yearToUse = ($month >= 4) ? $financialYearStart : ($financialYearStart + 1);
                    $monthName = date('F', mktime(0, 0, 0, $month, 1, $yearToUse));

                    if (isset($accountDetails[$monthName])) {
                        foreach ($accountDetails[$monthName] as $day => $transaction) {
                            if (isset($transaction['Opening'])) {
                                $openingBalance = (float) $transaction['Opening'];
                                $openingSet = true;
                                log_message('debug', "Opening balance found in $monthName for $accountName: ₹$openingBalance");
                                break 2; // Exit both loops after finding the opening balance
                            }
                        }
                    }
                }

                // ✅ Process transactions only up to today’s date (ignoring future entries)
                foreach ($monthsToCheck as $month) {
                    $yearToUse = ($month >= 4) ? $financialYearStart : ($financialYearStart + 1);
                    $monthName = date('F', mktime(0, 0, 0, $month, 1, $yearToUse));

                    if (isset($accountDetails[$monthName])) {
                        foreach ($accountDetails[$monthName] as $day => $transaction) {
                            if (is_array($transaction)) {
                                // ✅ Ignore future transactions (only include up to today’s date)
                                if (
                                    ($yearToUse < $currentYear) ||  // Past years are always included
                                    ($yearToUse == $currentYear && $month < $currentMonth) || // Past months in the current year
                                    ($yearToUse == $currentYear && $month == $currentMonth && (int) $day <= $currentDay) // Current month but only up to today
                                ) {
                                    $receivedAmount = isset($transaction['R']) ? (float) $transaction['R'] : 0;
                                    $paymentAmount = isset($transaction['P']) ? (float) $transaction['P'] : 0;
                                    $totalReceived += $receivedAmount;
                                    $totalPayment += $paymentAmount;

                                    log_message('debug', "Month: $monthName | Date: $day | Received: ₹$receivedAmount | Payment: ₹$paymentAmount");
                                }
                            }
                        }
                    }
                }

                // ✅ Calculate current balance
                $currentBalance = $openingBalance + $totalReceived - $totalPayment;

                log_message('debug', "Final balance for $accountName: ₹$currentBalance");

                // ✅ Store the result
                $filteredAccounts[] = [
                    'Account Name' => $accountName,
                    'Opening Balance' => number_format($openingBalance, 2),
                    'Total Received' => number_format($totalReceived, 2),
                    'Total Payment' => number_format($totalPayment, 2),
                    'Current Balance' => number_format($currentBalance, 2)
                ];
            }
        }

        return $filteredAccounts;
    }




    public function cash_book_month()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Get the selected account name from AJAX request
        $selectedAccount = $this->input->get_post('account_name'); // ✅ Supports both GET & POST


        if (empty($selectedAccount)) {
            echo json_encode(['status' => 'error', 'message' => 'No account selected']);
            return;
        }

        // // School ID and fetch school name dynamically
        // $schoolId = '1111'; // Replace with dynamic value
        // $schoolName = $this->CM->get_school_name_by_id($schoolId);

        // Firebase path to Account Book
        $firebasePath = "Schools/$school_name/$session_year/Accounts/Account_book/$selectedAccount"; // Fetch only selected account data

        // Fetch data from Firebase
        $accountData = $this->firebase->get($firebasePath);

        if (empty($accountData)) {
            echo json_encode(['status' => 'error', 'message' => 'No data found for selected account']);
            return;
        }

        // Prepare months and initialize variables
        $months = [
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December',
            'January',
            'February',
            'March'
        ];
        $cashBookData = [];
        $previousBalance = 0; // Initial Opening Balance for April

        foreach ($months as $month) {
            $monthPayments = 0;
            $monthReceived = 0;

            // Process only the selected account data
            if (!isset($accountData[$month])) {
                continue;
            }

            // Extract monthly data
            $monthlyData = $accountData[$month];

            if (is_array($monthlyData)) {
                foreach ($monthlyData as $dateData) {
                    if (!is_array($dateData)) {
                        continue;
                    }

                    // Add Payments (P) and Received (R)
                    $monthPayments += isset($dateData['P']) ? (float)$dateData['P'] : 0;
                    $monthReceived += isset($dateData['R']) ? (float)$dateData['R'] : 0;
                }
            }

            // Set Opening balance for April (from the first transaction if available)
            if ($month === 'April' && isset($monthlyData[1]) && isset($monthlyData[1]['Opening'])) {
                $previousBalance += (float)$monthlyData[1]['Opening'];
            }

            // Calculate balance and prepare row data
            $balance = $previousBalance + $monthReceived - $monthPayments;

            $cashBookData[] = [
                'month' => $month,
                'opening' => $previousBalance,
                'received' => $monthReceived,
                'payments' => $monthPayments,
                'balance' => $balance,
            ];

            // Update the previous balance for the next month
            $previousBalance = $balance;
        }

        // Send data as JSON response
        echo json_encode(['status' => 'success', 'data' => $cashBookData]);
    }


    public function cash_book_dates()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Retrieve session year
        // $sessionPath = "Schools/$school_name/$session_year/Session";
        // $sessionData = $this->firebase->get($sessionPath);
        $sessionYear = $session_year; // e.g., "2024-25"

        // Parse session year
        [$startYear, $endYear] = explode('-', $sessionYear);

        // Get POST data
        $selectedAccount = $this->input->get_post('account_name');
        $selectedMonth = $this->input->post('month');
        $opening = $this->input->post('opening');

        // ✅ Fix: Ensure Opening is Numeric
        $opening = isset($opening) ? str_replace(',', '', $opening) : 0; // Remove commas
        $opening = is_numeric($opening) ? (float) $opening : 0; // Convert to float safely

        // Validate input
        if (empty($selectedAccount) || empty($selectedMonth)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid account or month']);
            return;
        }

        // Determine year based on the selected month
        $monthsAfterApril = ['April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $currentYear = in_array($selectedMonth, $monthsAfterApril) ? $startYear : $endYear;

        // Prepare Firebase path
        $accountPath = "Schools/$school_name/$session_year/Accounts/Account_book/$selectedAccount/$selectedMonth";
        $monthData = $this->firebase->get($accountPath);

        // If no data found for the selected account and month
        if (!$monthData) {
            echo json_encode(['status' => 'error', 'message' => 'No data found for this account in the selected month']);
            return;
        }

        // Process transactions for the selected account and month
        $dateRecords = [];
        $balance = $opening; // ✅ Correctly initializing opening balance

        foreach ($monthData as $key => $dateData) {
            // ✅ Ensure numeric values, treat missing P or R as zero
            $payments = isset($dateData['P']) && is_numeric($dateData['P']) ? (float) $dateData['P'] : 0;
            $received = isset($dateData['R']) && is_numeric($dateData['R']) ? (float) $dateData['R'] : 0;
            $date = str_pad($key, 2, '0', STR_PAD_LEFT) . '-' . date('m', strtotime($selectedMonth)) . '-' . $currentYear;

            // ✅ Ensure a valid date record
            $dateRecords[$date] = [
                'date' => $date,
                'opening' => 0, // Will be updated later
                'payments' => $payments,
                'received' => $received,
                'balance' => 0, // Will be updated later
            ];
        }

        // Sort dates to process chronologically
        ksort($dateRecords);

        // ✅ Process each date to calculate balance and set opening for the next date
        $output = [];
        foreach ($dateRecords as $date => &$data) {
            $data['opening'] = $balance;
            $data['balance'] = $balance + $data['received'] - $data['payments'];
            $balance = $data['balance']; // Carry forward the balance

            $output[] = $data;
        }

        // ✅ Send the response back as JSON
        echo json_encode(['status' => 'success', 'data' => $output]);
    }



    public function cash_book_details()
    {
        
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        // Get the selected date from POST data
        $selectedDate = $this->input->post('date');

        // Firebase path to fetch the vouchers
        $receiptPath = "Schools/$school_name/$session_year/Accounts/Vouchers/$selectedDate";

        // Fetch data from Firebase using the library
        $vouchers = $this->firebase->get($receiptPath);

        // Initialize result array
        $result = [];

        if ($vouchers && is_array($vouchers)) {
            // Format the data for each voucher
            foreach ($vouchers as $key => $voucher) {
                $account = isset($voucher['Acc']) ? $voucher['Acc'] : 'N/A';
                $received = isset($voucher['Received']) ? $voucher['Received'] : (isset($voucher['Fees Received']) ? $voucher['Fees Received'] : 0);
                $payment = isset($voucher['Payment']) ? $voucher['Payment'] : 0;

                // Skip rows where all fields are empty or zero
                if ($account === 'N/A' && $received == 0 && $payment == 0) {
                    continue;
                }

                $result[] = [
                    'date' => $selectedDate,
                    'account' => $account,
                    'received' => $received,
                    'payment' => $payment,
                    'reference' => isset($voucher['Refer']) ? $voucher['Refer'] : '',
                ];
            }
        }

        // Send the formatted data back as a JSON response
        echo json_encode($result);
    }
}
