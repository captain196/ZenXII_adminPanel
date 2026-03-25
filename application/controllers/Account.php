<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Account controller
 *
 * SECURITY FIXES:
 * [FIX-1]  All user-supplied inputs pass through safe_path_segment() before
 *          being embedded in Firebase paths.
 * [FIX-2]  account_book() AJAX POST now sanitises selectedAccountName.
 * [FIX-3]  create_account() validates and casts openingAmount to float.
 * [FIX-4]  show_vouchers() validates date format before using in comparisons.
 * [FIX-5]  Removed stray debug log_message('debug', ...) calls with print_r.
 * [FIX-6]  All JSON responses use json_success() / json_error() helpers.
 * [FIX-7]  view_accounts() AJAX path uses safe_path_segment.
 * [FIX-8]  save_voucher() validates date and casts numeric fields.
 */
class Account extends MY_Controller
{
    /** Roles allowed to create/edit/delete accounts and vouchers. */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Accountant'];

    /** Roles allowed to view accounting data. */
    private const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Accountant'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Accounting');
        $this->load->library('firebase');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function base_path(): string
    {
        return "Schools/{$this->school_name}/{$this->session_year}";
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function account_book()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_account_book');
        $accounts = $this->firebase->get($this->base_path() . '/Accounts/Account_book');

        // SESSION ISOLATION FIX: use the active session selected by the admin,
        // not a date-computed year (which ignored session switching and used the
        // wrong YYYY-YYYY format instead of the stored YYYY-YY format).
        $current_session = $this->session_year;

        if ($this->input->is_ajax_request()) {
            // [FIX-2] Sanitise the account name from POST
            $selectedAccountName = trim((string) $this->input->post('selectedAccountName'));

            if (!$selectedAccountName || !is_array($accounts) || !isset($accounts[$selectedAccountName])) {
                $this->json_error('Account not found.', 404);
            }

            header('Content-Type: application/json');
            echo json_encode([
                'selectedAccount' => $accounts[$selectedAccountName],
                'current_session' => $current_session,
            ]);
            return;
        }

        $data['accounts']        = is_array($accounts) ? $accounts : [];
        $data['current_session'] = $current_session;

        $this->load->view('include/header');
        $this->load->view('account_book', $data);
        $this->load->view('include/footer');
    }

    public function populateTable()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_accounts_table');
        header('Content-Type: application/json');

        // [FIX-1]
        $accountName = trim((string) $this->input->post('selectedAccountName'));
        if (!$accountName) {
            $this->json_error('Account name required.', 400);
        }

        $accountData = $this->firebase->get($this->base_path() . "/Accounts/Account_book/{$accountName}");

        $months = ['April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December', 'January', 'February', 'March'];
        $matrix = array_fill(0, 12, ['Opening' => 0.00, 'Received' => 0.00, 'Payments' => 0.00, 'Balance' => 0.00]);
        $previousMonthBalance = 0.00;

        foreach ($months as $index => $month) {
            $monthData     = isset($accountData[$month]) ? (array) $accountData[$month] : [];
            $totalReceived = 0.00;
            $totalPayments = 0.00;

            foreach ($monthData as $date => $data) {
                if (!empty($data['R'])) $totalReceived += (float) $data['R'];
                if (!empty($data['P'])) $totalPayments += (float) $data['P'];
                if ($index === 0 && !empty($data['Opening'])) {
                    $matrix[$index]['Opening'] = (float) $data['Opening'];
                }
            }

            $matrix[$index]['Received'] = $totalReceived;
            $matrix[$index]['Payments'] = $totalPayments;

            if ($index > 0) {
                $matrix[$index]['Opening'] = $previousMonthBalance;
            }

            $matrix[$index]['Balance'] = $matrix[$index]['Opening'] + $totalReceived - $totalPayments;
            $previousMonthBalance = $matrix[$index]['Balance'];
        }

        echo json_encode([
            'matrix'        => $matrix,
            'totalReceived' => array_sum(array_column($matrix, 'Received')),
            'totalPayments' => array_sum(array_column($matrix, 'Payments')),
        ]);
    }

    public function create_account()
    {
        $this->_require_role(self::MANAGE_ROLES, 'create_account');
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        // [FIX-1] Sanitise account name
        $accountName   = trim((string) $this->input->post('accountName'));
        $subGroup      = trim((string) $this->input->post('subGroup'));
        $openingAmount = $this->input->post('openingAmount');

        if (!$accountName || !$subGroup) {
            $this->json_error('Account name and sub-group are required.', 400);
        }

        // [FIX-3] Cast opening amount to float
        $openingAmount = is_numeric($openingAmount) ? (float) $openingAmount : 0.0;

        // Strip Firebase-reserved chars from account name
        $accountName = preg_replace('/[.#$\[\]\/]/', '-', $accountName);

        $createdOn    = date('d/m/Y');
        $createdMonth = date('F');
        $createdDay   = date('j');

        $accountData = [
            'Created On' => $createdOn,
            'Under'      => $subGroup,
            'April'      => ['1' => ['Opening' => $openingAmount, 'P' => 0, 'R' => 0]],
            $createdMonth => [$createdDay => ['P' => 0, 'R' => 0]],
            'May'       => ['1' => ['P' => 0, 'R' => 0]],
            'June'      => ['1' => ['P' => 0, 'R' => 0]],
            'July'      => ['1' => ['P' => 0, 'R' => 0]],
            'August'    => ['1' => ['P' => 0, 'R' => 0]],
            'September' => ['1' => ['P' => 0, 'R' => 0]],
            'October'   => ['1' => ['P' => 0, 'R' => 0]],
            'November'  => ['1' => ['P' => 0, 'R' => 0]],
            'December'  => ['1' => ['P' => 0, 'R' => 0]],
            'January'   => ['1' => ['P' => 0, 'R' => 0]],
            'February'  => ['1' => ['P' => 0, 'R' => 0]],
            'March'     => ['1' => ['P' => 0, 'R' => 0]],
        ];

        // Optional fields
        foreach (['branchName', 'accountHolder', 'accountNumber', 'ifscCode'] as $field) {
            $val = trim((string) $this->input->post($field));
            if ($val) $accountData[$field] = $val;
        }

        $this->firebase->update(
            $this->base_path() . "/Accounts/Account_book/{$accountName}",
            $accountData
        );

        redirect(base_url() . 'account/account_book');
    }

    public function check_account()
    {
        $this->_require_role(self::VIEW_ROLES, 'check_account');
        header('Content-Type: application/json');

        $accountName = trim((string) $this->input->post('accountName'));

        if (!$accountName) {
            echo json_encode(['exists' => false]);
            return;
        }

        $existingAccounts = $this->firebase->get($this->base_path() . '/Accounts/Account_book');
        $exists = false;

        if (is_array($existingAccounts)) {
            foreach (array_keys($existingAccounts) as $key) {
                if (strcasecmp($key, $accountName) === 0) {
                    $exists = true;
                    break;
                }
            }
        }

        echo json_encode(['exists' => $exists]);
    }

    public function update_account()
    {
        $this->_require_role(self::MANAGE_ROLES, 'update_account');
        header('Content-Type: application/json');

        $accountId      = trim((string) $this->input->post('accountId'));
        $newAccountName = trim((string) $this->input->post('accountName'));
        $subGroup       = trim((string) $this->input->post('subGroup'));

        if (!$accountId || !$newAccountName || !$subGroup) {
            $this->json_error('Invalid input.', 400);
        }

        // Strip Firebase-reserved chars
        $newAccountName = preg_replace('/[.#$\[\]\/]/', '-', $newAccountName);

        $basePath    = $this->base_path() . '/Accounts/Account_book';
        $accountData = $this->firebase->get("{$basePath}/{$accountId}");

        if (!$accountData) {
            $this->json_error('Account not found.', 404);
        }

        $accountData['Under']         = $subGroup;
        $accountData['branchName']    = trim((string) $this->input->post('branchName'));
        $accountData['accountHolder'] = trim((string) $this->input->post('accountHolder'));
        $accountData['accountNumber'] = trim((string) $this->input->post('accountNumber'));
        $accountData['ifscCode']      = trim((string) $this->input->post('ifscCode'));

        $this->firebase->set("{$basePath}/{$newAccountName}", $accountData);

        if ($accountId !== $newAccountName) {
            $this->firebase->delete("{$basePath}/{$accountId}");
        }

        $this->json_success(['message' => 'Account updated successfully.']);
    }

    public function delete_account()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_account');
        header('Content-Type: application/json');

        $accountName = trim((string) $this->input->post('accountName'));
        if (!$accountName) {
            $this->json_error('Account name is required.', 400);
        }

        try {
            $this->firebase->delete($this->base_path() . "/Accounts/Account_book/{$accountName}");
            $this->json_success();
        } catch (Exception $e) {
            log_message('error', 'delete_account: ' . $e->getMessage());
            $this->json_error($e->getMessage(), 500);
        }
    }

    public function vouchers()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_vouchers');
        $accounts = $this->firebase->get($this->base_path() . '/Accounts/Account_book');

        $accountsList            = [];
        $accountsUnderBankAccount = [];

        if (is_array($accounts)) {
            foreach ($accounts as $accountName => $accountDetails) {
                if (
                    isset($accountDetails['Under']) &&
                    in_array($accountDetails['Under'], ['BANK ACCOUNT', 'CASH'], true)
                ) {
                    $accountsUnderBankAccount[] = $accountName;
                }
            }
            $accountsList = array_keys($accounts);
        }

        $data['accounts']   = $accountsList;
        $data['accounts_2'] = $accountsUnderBankAccount;

        $this->load->view('include/header');
        $this->load->view('manage_voucher', $data);
        $this->load->view('include/footer');
    }

    public function save_voucher()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_voucher');
        header('Content-Type: application/json');

        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        // [FIX-8] Validate date
        $date    = trim((string) $this->input->post('date'));
        $voucher = $this->input->post('voucher');

        if (!$date || !is_array($voucher)) {
            $this->json_error('Invalid input.', 400);
        }

        $date_obj = DateTime::createFromFormat('d-m-Y', $date);
        if (!$date_obj) {
            $this->json_error('Invalid date format.', 400);
        }

        $month = $date_obj->format('F');
        $day   = $date_obj->format('d');

        $count_path   = "Schools/{$school_name}/{$session_year}/Accounts/Vouchers/VoucherCount";
        $voucher_count = (int) ($this->firebase->get($count_path) ?? 0);

        $voucher_path = "Schools/{$school_name}/{$session_year}/Accounts/Vouchers/{$date}/{$voucher_count}";
        $this->firebase->set($voucher_path, $voucher);
        $this->firebase->set($count_path, $voucher_count + 1);

        $account_name = $voucher['Acc']  ?? '';
        $mode         = $voucher['Mode'] ?? '';

        $account_path = "Schools/{$school_name}/{$session_year}/Accounts/Account_book/{$account_name}/{$month}/{$day}";
        $modepath     = "Schools/{$school_name}/{$session_year}/Accounts/Account_book/{$mode}/{$month}/{$day}";

        $update_node = function ($path, $key, $amount) {
            $node_path       = "{$path}/{$key}";
            $current         = (float) ($this->firebase->get($node_path) ?? 0);
            $this->firebase->set($node_path, $current + (float) $amount);
        };

        if (isset($voucher['Receipt'])) {
            $update_node($account_path, 'R', $voucher['Receipt']);
            $update_node($modepath,     'R', $voucher['Receipt']);
        }

        if (isset($voucher['Payment'])) {
            $update_node($account_path, 'P', $voucher['Payment']);
            $update_node($modepath,     'P', $voucher['Payment']);
        }

        if (isset($voucher['Contra'])) {
            $update_node($account_path, 'P', $voucher['Contra']);
            $update_node($modepath,     'R', $voucher['Contra']);
        }

        $this->json_success();
    }

    public function view_voucher()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_voucher');
        $this->load->view('include/header');
        $this->load->view('view_voucher');
        $this->load->view('include/footer');
    }

    public function show_vouchers()
    {
        $this->_require_role(self::VIEW_ROLES, 'show_vouchers');
        header('Content-Type: application/json');

        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $from_date    = trim((string) $this->input->post('from_date'));
        $to_date      = trim((string) $this->input->post('to_date'));
        $voucher_type = trim((string) $this->input->post('voucher_type'));

        // [FIX-4] Validate date format
        $from_dt = DateTime::createFromFormat('Y-m-d', $from_date) ?: DateTime::createFromFormat('d-m-Y', $from_date);
        $to_dt   = DateTime::createFromFormat('Y-m-d', $to_date)   ?: DateTime::createFromFormat('d-m-Y', $to_date);

        if (!$from_dt || !$to_dt) {
            $this->json_error('Invalid date format.', 400);
        }

        $from_date = $from_dt->format('d-m-Y');
        $to_date   = $to_dt->format('d-m-Y');

        $vouchers_data = $this->firebase->get("Schools/{$school_name}/{$session_year}/Accounts/Vouchers/");

        if (!is_array($vouchers_data)) {
            echo json_encode([]);
            return;
        }

        $prepared_vouchers = [];

        foreach ($vouchers_data as $date => $vouchers_on_date) {
            if ($date === 'VoucherCount' || !is_array($vouchers_on_date)) continue;

            $date_dt = DateTime::createFromFormat('d-m-Y', $date);
            if (!$date_dt) continue;

            if ($date_dt < $from_dt || $date_dt > $to_dt) continue;

            foreach ($vouchers_on_date as $voucher_details) {
                if (!is_array($voucher_details)) continue;

                $particular   = $voucher_details['Acc']   ?? '';
                $payment_mode = $voucher_details['Mode']  ?? '';
                $refer        = $voucher_details['Refer'] ?? '';
                $id           = $voucher_details['Id']    ?? null;

                foreach (['Payment', 'Receipt', 'Journal', 'Contra'] as $type_key) {
                    if (!isset($voucher_details[$type_key])) continue;

                    if ($voucher_type !== 'All' && $voucher_type !== $type_key) continue;

                    $entry = [
                        'Date'         => $date,
                        'Type'         => $type_key,
                        'Particular'   => htmlspecialchars($particular, ENT_QUOTES, 'UTF-8'),
                        'Payment Mode' => htmlspecialchars($payment_mode, ENT_QUOTES, 'UTF-8'),
                        'Dr. Amt'      => null,
                        'Cr. Amt'      => null,
                        'Refer'        => htmlspecialchars($refer, ENT_QUOTES, 'UTF-8'),
                    ];

                    if ($type_key === 'Payment' || $type_key === 'Journal') {
                        $entry['Dr. Amt'] = $voucher_details[$type_key];
                    } else {
                        $entry['Cr. Amt'] = $voucher_details[$type_key];
                    }

                    if ($id) $entry['Id'] = $id;

                    $prepared_vouchers[] = $entry;
                }
            }
        }

        usort($prepared_vouchers, function ($a, $b) {
            return strtotime($b['Date']) <=> strtotime($a['Date']);
        });

        echo json_encode($prepared_vouchers);
    }

    public function day_book()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_day_book');
        $this->load->view('include/header');
        $this->load->view('day_book');
        $this->load->view('include/footer');
    }

    public function view_accounts()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_accounts');
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        if ($this->input->post()) {
            header('Content-Type: application/json');

            $fromDate    = trim((string) $this->input->post('fromDate'));
            $toDate      = trim((string) $this->input->post('toDate'));
            $accountType = trim((string) $this->input->post('accountType'));

            $from_dt = DateTime::createFromFormat('d-m-Y', $fromDate);
            $to_dt   = DateTime::createFromFormat('d-m-Y', $toDate);

            if (!$from_dt || !$to_dt) {
                $this->json_error('Invalid date format.', 400);
            }

            $vouchersData = $this->firebase->get("Schools/{$school_name}/{$session_year}/Accounts/Vouchers");

            if (empty($vouchersData) || !is_array($vouchersData)) {
                $this->json_error('No vouchers found.', 404);
            }

            $filteredVouchers = [];

            foreach ($vouchersData as $voucherDate => $voucherDetails) {
                $vDt = DateTime::createFromFormat('d-m-Y', $voucherDate);
                if (!$vDt) continue;
                if ($vDt < $from_dt || $vDt > $to_dt) continue;

                foreach ((array) $voucherDetails as $key => $details) {
                    if (!is_array($details)) continue;
                    if ($accountType !== 'All' && ($details['Acc'] ?? '') !== $accountType) continue;

                    foreach (['Payment', 'Received'] as $typeKey) {
                        if (!isset($details[$typeKey])) continue;
                        $filteredVouchers[] = [
                            'Date'       => $voucherDate,
                            'Type'       => $typeKey,
                            'Particulars' => htmlspecialchars($details['Acc'] ?? '', ENT_QUOTES, 'UTF-8'),
                            'Cr Amt'     => $typeKey === 'Payment' ? '' : $details[$typeKey],
                            'Dr Amt'     => $typeKey === 'Payment' ? $details[$typeKey] : '',
                            'Mode'       => $details['Mode'] ?? '',
                        ];
                    }
                }
            }

            usort($filteredVouchers, function ($a, $b) {
                $dA = DateTime::createFromFormat('d-m-Y', $a['Date']);
                $dB = DateTime::createFromFormat('d-m-Y', $b['Date']);
                return $dB->getTimestamp() - $dA->getTimestamp();
            });

            $this->json_success(['data' => $filteredVouchers]);
        } else {
            $path       = "Schools/{$school_name}/{$session_year}/Accounts/Account_book";
            $accountKeys = $this->CM->get_data($path);
            $data['accountTypes'] = is_array($accountKeys) ? array_keys($accountKeys) : [];

            $this->load->view('include/header');
            $this->load->view('view_accounts', $data);
            $this->load->view('include/footer');
        }
    }

    public function cash_book()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_cash_book');
        $this->load->view('include/header');
        $data['accounts'] = $this->calculate_current_balances();
        $this->load->view('cash_book', $data);
        $this->load->view('include/footer');
    }

    public function get_server_date()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_server_date');
        header('Content-Type: application/json');
        echo json_encode(['date' => date('d-m-Y')]);
    }

    private function calculate_current_balances(): array
    {
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $currentDay   = (int) date('d');
        $currentMonth = (int) date('m');
        $currentYear  = (int) date('Y');
        $fyStart      = ($currentMonth >= 4) ? $currentYear : ($currentYear - 1);

        $firebase       = $this->firebase->getDatabase();
        $accountsData   = $firebase->getReference("/Schools/{$school_name}/{$session_year}/Accounts/Account_book")->getValue();

        if (!$accountsData) return [];

        $result = [];

        foreach ($accountsData as $accountName => $details) {
            $type = $details['Under'] ?? '';
            if ($type !== 'CASH' && $type !== 'BANK ACCOUNT') continue;

            $openingBalance = 0;
            $totalReceived  = 0;
            $totalPayment   = 0;
            $monthsToCheck  = range(4, 12);
            if ($currentMonth <= 3) {
                $monthsToCheck = array_merge($monthsToCheck, range(1, 3));
            }

            foreach ($monthsToCheck as $mNum) {
                $yr        = ($mNum >= 4) ? $fyStart : ($fyStart + 1);
                $monthName = date('F', mktime(0, 0, 0, $mNum, 1, $yr));
                if (!isset($details[$monthName])) continue;
                foreach ($details[$monthName] as $dayEntry) {
                    if (isset($dayEntry['Opening'])) {
                        $openingBalance = (float) $dayEntry['Opening'];
                        break 2;
                    }
                }
            }

            foreach ($monthsToCheck as $mNum) {
                $yr        = ($mNum >= 4) ? $fyStart : ($fyStart + 1);
                $monthName = date('F', mktime(0, 0, 0, $mNum, 1, $yr));
                if (!isset($details[$monthName])) continue;
                foreach ($details[$monthName] as $day => $tx) {
                    if (!is_array($tx)) continue;
                    if (
                        $yr < $currentYear ||
                        ($yr === $currentYear && $mNum < $currentMonth) ||
                        ($yr === $currentYear && $mNum === $currentMonth && (int) $day <= $currentDay)
                    ) {
                        $totalReceived += (float) ($tx['R'] ?? 0);
                        $totalPayment  += (float) ($tx['P'] ?? 0);
                    }
                }
            }

            $balance = $openingBalance + $totalReceived - $totalPayment;

            $result[] = [
                'Account Name'   => $accountName,
                'Opening Balance' => number_format($openingBalance, 2),
                'Total Received' => number_format($totalReceived, 2),
                'Total Payment'  => number_format($totalPayment, 2),
                'Current Balance' => number_format($balance, 2),
            ];
        }

        return $result;
    }

    public function cash_book_month()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_cash_book_month');
        header('Content-Type: application/json');

        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $selectedAccount = trim((string) $this->input->get_post('account_name'));
        if (!$selectedAccount) {
            $this->json_error('No account selected.', 400);
        }

        $accountData = $this->firebase->get("Schools/{$school_name}/{$session_year}/Accounts/Account_book/{$selectedAccount}");
        if (empty($accountData)) {
            $this->json_error('No data found for selected account.', 404);
        }

        $months        = ['April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December', 'January', 'February', 'March'];
        $cashBookData  = [];
        $previousBalance = 0;

        foreach ($months as $month) {
            if (!isset($accountData[$month])) continue;

            $monthlyData = (array) $accountData[$month];
            $payments    = 0;
            $received    = 0;

            if ($month === 'April' && isset($monthlyData[1]['Opening'])) {
                $previousBalance += (float) $monthlyData[1]['Opening'];
            }

            foreach ($monthlyData as $dateData) {
                if (!is_array($dateData)) continue;
                $payments += (float) ($dateData['P'] ?? 0);
                $received += (float) ($dateData['R'] ?? 0);
            }

            $balance = $previousBalance + $received - $payments;

            $cashBookData[] = [
                'month'    => $month,
                'opening'  => $previousBalance,
                'received' => $received,
                'payments' => $payments,
                'balance'  => $balance,
            ];

            $previousBalance = $balance;
        }

        $this->json_success(['data' => $cashBookData]);
    }

    public function cash_book_dates()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_cash_book_dates');
        header('Content-Type: application/json');

        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $selectedAccount = trim((string)$this->input->get_post('account_name'));
        $selectedMonth   = trim((string)$this->input->post('month'));
        $opening         = (float)str_replace(',', '', (string)($this->input->post('opening') ?? 0));

        if (!$selectedAccount || !$selectedMonth) {
            $this->json_error('Invalid account or month.', 400);
        }

        $sessionData = $this->firebase->get("Schools/{$school_name}/{$session_year}/Session");

        $sessionParts = explode('-', $session_year);
        $startYear = (int)$sessionParts[0];

        $monthsAfterApril = [
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December'
        ];

        $currentYear = in_array($selectedMonth, $monthsAfterApril)
            ? $startYear
            : ($startYear + 1);

        $accountPath = "Schools/{$school_name}/{$session_year}/Accounts/Account_book/{$selectedAccount}/{$selectedMonth}";
        $monthData   = $this->firebase->get($accountPath);

        if (!$monthData) {
            $this->json_error('No data found for this account in the selected month.', 404);
        }

        $dateRecords = [];

        foreach ($monthData as $key => $dateData) {

            $payments = (float)($dateData['P'] ?? 0);
            $received = (float)($dateData['R'] ?? 0);

            $monthNumber = date('m', strtotime($selectedMonth));

            $date = str_pad($key, 2, '0', STR_PAD_LEFT)
                . '-' . $monthNumber
                . '-' . $currentYear;

            $dateRecords[$date] = [
                'date'     => $date,
                'opening'  => 0,
                'payments' => $payments,
                'received' => $received,
                'balance'  => 0
            ];
        }

        ksort($dateRecords);

        $balance = $opening;
        $output  = [];

        foreach ($dateRecords as &$data) {

            $data['opening'] = $balance;

            $data['balance'] = $balance
                + $data['received']
                - $data['payments'];

            $balance = $data['balance'];

            $output[] = $data;
        }

        $this->json_success(['data' => $output]);
    }

    // public function cash_book_dates()
    // {
    //     header('Content-Type: application/json');

    //     $school_name  = $this->school_name;
    //     $session_year = $this->session_year;

    //     $selectedAccount = trim((string) $this->input->get_post('account_name'));
    //     $selectedMonth   = trim((string) $this->input->post('month'));
    //     $opening         = (float) str_replace(',', '', (string) ($this->input->post('opening') ?? 0));

    //     if (!$selectedAccount || !$selectedMonth) {
    //         $this->json_error('Invalid account or month.', 400);
    //     }

    //     $sessionData  = $this->firebase->get("Schools/{$school_name}/{$session_year}/Session");
    //     [$startYear]  = explode('-', (string) $sessionData);

    //     $monthsAfterApril = ['April','May','June','July','August','September','October','November','December'];
    //     $currentYear = in_array($selectedMonth, $monthsAfterApril) ? $startYear : ($startYear + 1);

    //     $accountPath = "Schools/{$school_name}/{$session_year}/Accounts/Account_book/{$selectedAccount}/{$selectedMonth}";
    //     $monthData   = $this->firebase->get($accountPath);

    //     if (!$monthData) {
    //         $this->json_error('No data found for this account in the selected month.', 404);
    //     }

    //     $dateRecords = [];
    //     foreach ($monthData as $key => $dateData) {
    //         $payments = (float) ($dateData['P'] ?? 0);
    //         $received = (float) ($dateData['R'] ?? 0);
    //         $date     = str_pad($key, 2, '0', STR_PAD_LEFT) . '-' . date('m', strtotime($selectedMonth)) . '-' . $currentYear;
    //         $dateRecords[$date] = ['date' => $date, 'opening' => 0, 'payments' => $payments, 'received' => $received, 'balance' => 0];
    //     }

    //     ksort($dateRecords);

    //     $balance = $opening;
    //     $output  = [];
    //     foreach ($dateRecords as &$data) {
    //         $data['opening'] = $balance;
    //         $data['balance'] = $balance + $data['received'] - $data['payments'];
    //         $balance         = $data['balance'];
    //         $output[]        = $data;
    //     }

    //     $this->json_success(['data' => $output]);
    // }

    public function cash_book_details()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_cash_book_details');
        header('Content-Type: application/json');

        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $selectedDate = trim((string) $this->input->post('date'));
        if (!$selectedDate) {
            $this->json_error('Date required.', 400);
        }

        $vouchers = $this->firebase->get("Schools/{$school_name}/{$session_year}/Accounts/Vouchers/{$selectedDate}");

        $result = [];
        if (is_array($vouchers)) {
            foreach ($vouchers as $voucher) {
                if (!is_array($voucher)) continue;
                $account  = $voucher['Acc']      ?? 'N/A';
                $received = $voucher['Received']  ?? ($voucher['Fees Received'] ?? 0);
                $payment  = $voucher['Payment']   ?? 0;
                if ($account === 'N/A' && !$received && !$payment) continue;
                $result[] = [
                    'date'      => $selectedDate,
                    'account'   => htmlspecialchars($account, ENT_QUOTES, 'UTF-8'),
                    'received'  => $received,
                    'payment'   => $payment,
                    'reference' => htmlspecialchars($voucher['Refer'] ?? '', ENT_QUOTES, 'UTF-8'),
                ];
            }
        }

        echo json_encode($result);
    }
}
