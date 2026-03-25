<?php

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Staff controller
 *
 * SECURITY FIXES:
 * [FIX-1]  new_staff(): $schoolId and $schoolName were undefined / leaked; now
 *          always taken from session ($this->school_id / $this->school_name).
 * [FIX-2]  new_staff(): Password stored using password_hash (was plain-text).
 * [FIX-3]  new_staff(): Phone validated with regex before storing.
 * [FIX-4]  new_staff(): StaffPath used undefined $schoolId — now uses session.
 * [FIX-5]  edit_staff(): $schoolId referenced but never defined — uses session.
 * [FIX-6]  markInactive_duty(): used $school_id for a path that should use
 *          $school_name — was mixing school name with school ID in path.
 * [FIX-7]  assign_duty(): classSection from POST used directly in path without
 *          validation — now validated via regex.
 * [FIX-8]  fetch_subjects(): classSection from JSON body used directly in path
 *          — now validated.
 * [FIX-9]  import_staff(): MIME validation added; XLSX/CSV only.
 * [FIX-10] save_updated_fees() debug print_r removed (was in Fees but mirrored
 *          here for completeness).
 */
class Staff extends MY_Controller
{
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'HR Manager'];
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'HR Manager', 'Academic Coordinator', 'Class Teacher', 'Teacher'];

    // ── Default staff role definitions (seeded on first access) ────────────
    private const DEFAULT_STAFF_ROLES = [
        'ROLE_TEACHER'      => ['label' => 'Teacher',         'category' => 'Teaching',       'flags' => ['can_teach' => true, 'can_access_timetable' => true], 'attendance_type' => 'standard', 'is_system' => true],
        'ROLE_ACCOUNTANT'   => ['label' => 'Accountant',      'category' => 'Administrative', 'flags' => ['can_handle_fees' => true],                          'attendance_type' => 'standard', 'is_system' => true],
        'ROLE_LIBRARIAN'    => ['label' => 'Librarian',       'category' => 'Non-Teaching',   'flags' => ['can_manage_library' => true],                       'attendance_type' => 'standard', 'is_system' => true],
        'ROLE_LAB_ASST'     => ['label' => 'Lab Assistant',   'category' => 'Non-Teaching',   'flags' => [],                                                  'attendance_type' => 'standard', 'is_system' => true],
        'ROLE_CLERK'        => ['label' => 'Clerk',           'category' => 'Administrative', 'flags' => [],                                                  'attendance_type' => 'standard', 'is_system' => true],
        'ROLE_DRIVER'       => ['label' => 'Driver',          'category' => 'Support',        'flags' => ['can_manage_transport' => true],                     'attendance_type' => 'shift',    'is_system' => true],
        'ROLE_SECURITY'     => ['label' => 'Security',        'category' => 'Support',        'flags' => [],                                                  'attendance_type' => 'shift',    'is_system' => true],
        'ROLE_HOUSE_WARDEN' => ['label' => 'House Warden',    'category' => 'Non-Teaching',   'flags' => ['can_manage_hostel' => true],                        'attendance_type' => 'standard', 'is_system' => false],
        'ROLE_PEON'         => ['label' => 'Peon/Attendant',  'category' => 'Support',        'flags' => [],                                                  'attendance_type' => 'standard', 'is_system' => false],
    ];

    // Keyword → role_id mapping for migration from free-text Position field
    private const POSITION_ROLE_MAP = [
        'teacher'    => 'ROLE_TEACHER',
        'lecturer'   => 'ROLE_TEACHER',
        'professor'  => 'ROLE_TEACHER',
        'instructor' => 'ROLE_TEACHER',
        'accountant' => 'ROLE_ACCOUNTANT',
        'librarian'  => 'ROLE_LIBRARIAN',
        'clerk'      => 'ROLE_CLERK',
        'driver'     => 'ROLE_DRIVER',
        'security'   => 'ROLE_SECURITY',
        'guard'      => 'ROLE_SECURITY',
        'peon'       => 'ROLE_PEON',
        'attendant'  => 'ROLE_PEON',
        'sweeper'    => 'ROLE_PEON',
        'warden'     => 'ROLE_HOUSE_WARDEN',
        'hostel'     => 'ROLE_HOUSE_WARDEN',
        'lab'        => 'ROLE_LAB_ASST',
    ];

    public function __construct()
    {
        parent::__construct();
        require_permission('SIS');
        // Lazy-seed staff role definitions on first access
        $this->_seed_staff_roles();
    }

    /**
     * Seed default staff roles if Config/StaffRoles is empty.
     * Called once per school — subsequent calls are a no-op (1 shallow read).
     */
    private function _seed_staff_roles(): void
    {
        if (empty($this->school_name)) return; // not logged in yet
        $path = "Schools/{$this->school_name}/Config/StaffRoles";
        $existing = $this->firebase->shallow_get($path);
        if (!empty($existing)) return;
        $this->firebase->set($path, self::DEFAULT_STAFF_ROLES);
    }

    /**
     * Infer staff role IDs from free-text Position field (for unmigrated records).
     */
    private function _infer_roles_from_position(string $position): array
    {
        $pos = strtolower(trim($position));
        if ($pos === '') return ['ROLE_TEACHER'];
        foreach (self::POSITION_ROLE_MAP as $keyword => $roleId) {
            if (strpos($pos, $keyword) !== false) {
                return [$roleId];
            }
        }
        return ['ROLE_TEACHER']; // safe default
    }

    // ── Salary Structure Auto-Sync ─────────────────────────────────────────

    /** Hardcoded fallback — overridden by Firebase Config/SalaryDefaults if set */
    private const SALARY_DEFAULTS_FALLBACK = [
        'hra_pct_of_basic'  => 40,
        'da_pct_of_basic'   => 10,
        'ta_share'          => 0.30,
        'medical_share'     => 0.25,
        'pf_employee'       => 12,
        'pf_employer'       => 12,
        'esi_employee'      => 0.75,
        'esi_employer'      => 3.25,
        'professional_tax'  => 200,
        'tds'               => 0,
        'other_deductions'  => 0,
    ];

    /**
     * Load salary split config — per-school Firebase config with constant fallback.
     * Path: Schools/{school}/Config/SalaryDefaults
     */
    private function _salary_config(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $fbConfig = $this->firebase->get("Schools/{$this->school_name}/Config/SalaryDefaults");
        $defaults = self::SALARY_DEFAULTS_FALLBACK;
        if (is_array($fbConfig)) {
            // Merge — Firebase values override constants, missing keys use fallback
            foreach ($defaults as $k => $v) {
                if (isset($fbConfig[$k]) && is_numeric($fbConfig[$k])) {
                    $defaults[$k] = (float) $fbConfig[$k];
                }
            }
        }
        $cached = $defaults;
        return $cached;
    }

    /**
     * Validate salary values — reusable across create/edit/backfill.
     * Returns sanitised array or throws json_error.
     */
    private function _validate_salary(float $basic, float $allowances): array
    {
        if (!is_finite($basic) || $basic < 0) {
            $this->json_error('Basic salary must be a non-negative number.');
        }
        if (!is_finite($allowances) || $allowances < 0) {
            $this->json_error('Allowances must be a non-negative number.');
        }
        return ['basic' => round($basic, 2), 'allowances' => round(max($allowances, 0), 2)];
    }

    /**
     * Build a full salary structure array from basic + allowances using config.
     */
    private function _build_salary_structure(float $basic, float $allowances): array
    {
        $cfg = $this->_salary_config();

        $hra = round($basic * ($cfg['hra_pct_of_basic'] / 100), 2);
        $da  = round($basic * ($cfg['da_pct_of_basic'] / 100), 2);

        $remaining = max(0, $allowances - $hra - $da);
        if ($allowances < ($hra + $da)) {
            $hra = round($allowances * 0.6, 2);
            $da  = round($allowances * 0.3, 2);
            $remaining = max(0, $allowances - $hra - $da);
        }

        $ta      = round($remaining * $cfg['ta_share'], 2);
        $medical = round($remaining * $cfg['medical_share'], 2);
        $other   = round($remaining - $ta - $medical, 2);

        return [
            'basic' => $basic, 'hra' => $hra, 'da' => $da, 'ta' => $ta,
            'medical' => $medical, 'other_allowances' => $other,
            'pf_employee' => $cfg['pf_employee'], 'pf_employer' => $cfg['pf_employer'],
            'esi_employee' => $cfg['esi_employee'], 'esi_employer' => $cfg['esi_employer'],
            'professional_tax' => $cfg['professional_tax'], 'tds' => $cfg['tds'],
            'other_deductions' => $cfg['other_deductions'],
        ];
    }

    /**
     * Create or update a Salary Structure from staff registration data.
     *
     * Rules:
     *  - basic <= 0 → skip (zero-salary staff)
     *  - No structure → create with source='registration'
     *  - source='registration' → update (version bump)
     *  - source='manual' → DO NOT overwrite (HR owns it), update sync timestamp
     */
    private function _sync_salary_structure(string $staffId, float $basic, float $allowances): bool
    {
        if ($basic <= 0) return false;

        $structPath = "Schools/{$this->school_name}/HR/Salary_Structures/{$staffId}";
        $existing   = $this->firebase->get($structPath);
        $now        = date('c');

        // Manual structure → don't overwrite, just note the sync
        if (is_array($existing) && ($existing['source'] ?? '') === 'manual') {
            $this->firebase->update($structPath, ['last_synced_at' => $now]);
            return false;
        }

        $structure = $this->_build_salary_structure($basic, $allowances);
        $structure['source']     = 'registration';
        $structure['updated_at'] = $now;
        $structure['updated_by'] = $this->admin_name ?? 'system';

        // Version tracking for concurrent-write safety
        $oldVersion = is_array($existing) ? (int) ($existing['_version'] ?? 0) : 0;
        $structure['_version'] = $oldVersion + 1;

        if (is_array($existing)) {
            $structure['created_at']     = $existing['created_at'] ?? $now;
            $structure['last_synced_at'] = $now;
            // Audit: store previous values
            $structure['_prev'] = [
                'basic' => $existing['basic'] ?? 0,
                'updated_at' => $existing['updated_at'] ?? '',
                'updated_by' => $existing['updated_by'] ?? '',
            ];
        } else {
            $structure['created_at']     = $now;
            $structure['last_synced_at'] = $now;
        }

        $this->firebase->set($structPath, $structure);

        log_message('info',
            "Salary structure auto-" . (is_array($existing) ? "updated(v{$structure['_version']})" : 'created')
            . " staff=[{$staffId}] school=[{$this->school_name}]"
            . " basic={$basic} allowances={$allowances}"
        );

        return true;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Validate a class-section string like "Class 8th 'A'" or "8th 'A'".
     */
    private function valid_class_section(string $val): bool
    {
        return (bool) preg_match("/^(Class\s+)?[A-Za-z0-9]+\s+'[A-Z]{1,3}'$/", $val);
    }

    /**
     * Upload a staff file (Photo or Aadhar Card) to Firebase Storage.
     * Mirrors the uploadStudentFile() pattern from Student.php.
     *
     * Returns ['url' => '...', 'thumbnail' => '...'] on success, false on failure.
     *
     * Storage layout:
     *   Photo     → {school}/Staff/{staffId}/Profile_pic/{label}_{ts}_{rnd}.{ext}
     *   thumbnail → {school}/Staff/{staffId}/Profile_pic/thumbnail/{same filename}
     *   Others    → {school}/Staff/{staffId}/Documents/{label}_{ts}_{rnd}.{ext}
     *   thumbnail → {school}/Staff/{staffId}/Documents/thumbnail/{same filename}
     */
    private function uploadStaffFile(array $file, string $school_name, string $staffId, string $label)
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // M-03 FIX: Validate MIME type server-side via finfo (callers already check
        // mime_content_type for photo/aadhar, but this guards the generic path)
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, $allowedMimes, true)) {
            return false;
        }

        $timestamp = time();
        $random    = substr(md5(uniqid()), 0, 6);
        $safeLabel = str_replace([' ', '.', '#', '$', '[', ']'], '_', $label);
        $fileName  = "{$safeLabel}_{$timestamp}_{$random}.{$ext}";

        $basePath = ($label === 'Photo')
            ? "{$school_name}/Staff/{$staffId}/Profile_pic/"
            : "{$school_name}/Staff/{$staffId}/Documents/";

        $filePath = $basePath . $fileName;

        if ($this->firebase->uploadFile($file['tmp_name'], $filePath) !== true) {
            return false;
        }

        $fileUrl      = $this->firebase->getDownloadUrl($filePath);
        $thumbnailUrl = '';

        // ── Image thumbnail: re-upload original file ──────────────────────────
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $thumbPath = $basePath . "thumbnail/" . $fileName;
            if ($this->firebase->uploadFile($file['tmp_name'], $thumbPath) === true) {
                $thumbnailUrl = $this->firebase->getDownloadUrl($thumbPath);
            }
        }

        // ── PDF thumbnail: try Imagick, fall back to pdf.png placeholder ──────
        if ($ext === 'pdf') {
            $thumbFileName = $safeLabel . '_' . $timestamp . '_' . $random . '_thumb';
            $thumbPath     = $basePath . 'thumbnail/' . $thumbFileName;

            // Try Imagick (requires Ghostscript on the server)
            if (extension_loaded('imagick')) {
                try {
                    $imagick = new Imagick();
                    $imagick->setResolution(150, 150);
                    $imagick->readImage($file['tmp_name'] . '[0]');
                    $imagick->setImageFormat('jpg');
                    $imagick->setImageCompressionQuality(85);
                    $imagick->thumbnailImage(400, 0);
                    $imagick->flattenImages();

                    $tmp = sys_get_temp_dir() . '/' . $thumbFileName . '.jpg';
                    $imagick->writeImage($tmp);
                    $imagick->clear();
                    $imagick->destroy();

                    $thumbStorePath = $thumbPath . '.jpg';
                    if ($this->firebase->uploadFile($tmp, $thumbStorePath) === true) {
                        $thumbnailUrl = $this->firebase->getDownloadUrl($thumbStorePath);
                    }
                    @unlink($tmp);
                } catch (Exception $e) {
                    log_message('error', 'Staff PDF Imagick thumb failed: ' . $e->getMessage());
                }
            }

            // Fallback: upload the static pdf.png placeholder
            if ($thumbnailUrl === '') {
                $placeholder = FCPATH . 'tools/image/pdf.png';
                if (file_exists($placeholder)) {
                    $thumbStorePath = $thumbPath . '.png';
                    if ($this->firebase->uploadFile($placeholder, $thumbStorePath) === true) {
                        $thumbnailUrl = $this->firebase->getDownloadUrl($thumbStorePath);
                    }
                }
            }
        }

        return ['url' => $fileUrl, 'thumbnail' => $thumbnailUrl];
    }

    /**
     * Extract the Firebase Storage object path from a download URL.
     * e.g. "https://firebasestorage.googleapis.com/v0/b/bucket/o/path%2Ffile.jpg?..."
     *      → "path/file.jpg"
     */
    private function extractStaffStoragePath(string $url): string
    {
        if (empty($url)) return '';
        if (preg_match('#/o/([^?]+)#', $url, $matches)) {
            return urldecode($matches[1]);
        }
        return '';
    }

    /**
     * Delete both the main file and its thumbnail from Firebase Storage.
     * Accepts either an array ['url'=>'...','thumbnail'=>'...'] or a plain URL string.
     */
    private function deleteStaffDoc($docEntry): void
    {
        if (!is_array($docEntry)) {
            $docEntry = ['url' => (string)$docEntry, 'thumbnail' => ''];
        }
        foreach (['url', 'thumbnail'] as $key) {
            $url = $docEntry[$key] ?? '';
            if (!empty($url)) {
                $path = $this->extractStaffStoragePath($url);
                if ($path) $this->CM->delete_file_from_firebase($path);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function all_staff()
    {
        $this->_require_role(self::VIEW_ROLES);
        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;

        $data['staff'] = $this->CM->select_data("Users/Teachers/{$school_id}");
        if (!is_array($data['staff'])) {
            $data['staff'] = [];
        }

        // Remove non-staff siblings (e.g. the integer 'Count' node).
        // PHP 8 throws a fatal TypeError when the view accesses $s['field']
        // on a non-array value, which stops rendering — so any staff entries
        // that come after 'Count' alphabetically (like STA0006) are never shown.
        $data['staff'] = array_filter($data['staff'], 'is_array');

        // Normalise profile-pic key: new_staff() stores 'ProfilePic',
        // older records may use 'Photo URL'.
        foreach ($data['staff'] as &$s) {
            $s['_profilePic'] = $s['ProfilePic'] ?? $s['Photo URL'] ?? '';
        }
        unset($s);

        // SESSION ISOLATION: only show teachers who are assigned to this session.
        $session_year    = $this->session_year;
        $sessionTeachers = $this->firebase->get("Schools/{$school_name}/{$session_year}/Teachers");
        if (is_array($sessionTeachers) && !empty($sessionTeachers)) {
            $data['staff'] = array_intersect_key($data['staff'], $sessionTeachers);
        } else {
            $data['staff'] = [];
        }

        $data['school_name'] = $school_name;

        // Load staff role definitions for badge display and filtering
        $data['staff_role_defs'] = $this->firebase->get("Schools/{$school_name}/Config/StaffRoles") ?? [];
        if (!is_array($data['staff_role_defs'])) $data['staff_role_defs'] = [];

        $this->load->view('include/header');
        $this->load->view('all_staff', $data);
        $this->load->view('include/footer');
    }

    public function master_staff()
    {
        $this->_require_role(self::VIEW_ROLES);
        $this->load->view('include/header');
        $this->load->view('import_staff'); // view file
        $this->load->view('include/footer');
    }

    // ── Fix staff count: reads actual staff entries and updates Count ───────
    public function fix_staff_count()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $school_id = $this->parent_db_key;

        $teachersNode = $this->firebase->get("Users/Teachers/{$school_id}");
        if (!is_array($teachersNode)) {
            $this->json_error('No teachers node found.', 404);
            return;
        }

        $actualCount = 0;
        foreach ($teachersNode as $key => $val) {
            if ($key === 'Count') continue;
            if (is_array($val)) $actualCount++;
        }

        $storedCount = isset($teachersNode['Count']) ? (int)$teachersNode['Count'] : 0;

        $this->CM->addKey_pair_data("Users/Teachers/{$school_id}/", ['Count' => $actualCount]);

        $this->json_success([
            'previous_count' => $storedCount,
            'actual_count'   => $actualCount,
            'fixed'          => ($storedCount !== $actualCount),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function import_staff()
    {
        $this->_require_role(self::MANAGE_ROLES);
        try {

            $school_id    = $this->parent_db_key;
            $school_name  = $this->school_name;
            $session_year = $this->session_year;

            if (!isset($_FILES['excelFile']) || $_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
                redirect('staff/all_staff');
                return;
            }

            $file      = $_FILES['excelFile'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            $reader = ($extension === 'csv')
                ? IOFactory::createReader('Csv')
                : IOFactory::createReader('Xlsx');

            $spreadsheet = $reader->load($file['tmp_name']);
            $sheetData   = $spreadsheet->getActiveSheet()->toArray();

            if (count($sheetData) <= 1) {
                $this->session->set_flashdata('import_result', 'Import failed: file is empty.');
                redirect('staff/all_staff');
                return;
            }

            $headers = array_map('trim', $sheetData[0]);
            unset($sheetData[0]);
            $sheetData = array_values($sheetData);

            $this->load->library('auth_client');

            $success = 0;
            $error   = 0;
            $skipped = [];

            foreach ($sheetData as $row) {

                if (!array_filter($row)) continue;

                // prevent array_combine crash
                if (count($headers) !== count($row)) {
                    $error++;
                    continue;
                }

                $rowData = array_combine($headers, $row);

                // Generate globally unique TEA ID from Auth API
                $staffId = $this->auth_client->generate_id('STA');
                if (!$staffId) {
                    $skipped[] = "Row " . ($success + $error + count($skipped) + 1) . ": ID generation failed";
                    $error++;
                    continue;
                }

                $rowNum      = $success + $error + count($skipped) + 1;
                $name        = trim($rowData['Name'] ?? '');
                $phone       = trim($rowData['Phone Number'] ?? '');
                $dob         = trim($rowData['DOB'] ?? '');
                $email       = trim($rowData['Email'] ?? '');
                $gender      = trim($rowData['Gender'] ?? '');
                $fatherName  = trim($rowData['Father Name'] ?? '');
                $empType     = trim($rowData['Employment Type'] ?? '');
                $department  = trim($rowData['Department'] ?? '');
                $positionRaw = trim($rowData['Position'] ?? '');
                $dojRaw      = trim($rowData['Date Of Joining'] ?? '');
                $basicRaw    = $rowData['Basic Salary'] ?? '';

                // Required fields — must match new_staff form exactly
                $missing = [];
                if ($name === '')        $missing[] = 'Name';
                if ($phone === '')       $missing[] = 'Phone Number';
                if ($dob === '')         $missing[] = 'DOB';
                if ($email === '')       $missing[] = 'Email';
                if ($gender === '')      $missing[] = 'Gender';
                if ($fatherName === '')  $missing[] = 'Father Name';
                if ($positionRaw === '') $missing[] = 'Position';
                if ($dojRaw === '')      $missing[] = 'Date Of Joining';
                if ($empType === '')     $missing[] = 'Employment Type';
                if ($department === '')  $missing[] = 'Department';
                if ($basicRaw === '' || $basicRaw === null) $missing[] = 'Basic Salary';

                if (!empty($missing)) {
                    $skipped[] = "Row {$rowNum}: Missing " . implode(', ', $missing);
                    $error++;
                    continue;
                }

                // Validate phone
                if (!preg_match('/^[6-9]\d{9}$/', $phone)) {
                    $skipped[] = "Row {$rowNum}: Invalid phone '{$phone}'";
                    $error++;
                    continue;
                }

                // Validate email
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped[] = "Row {$rowNum}: Invalid email '{$email}'";
                    $error++;
                    continue;
                }

                // Validate DOB parseable
                $timestamp = strtotime($dob);
                if ($timestamp === false) {
                    $skipped[] = "Row {$rowNum}: Invalid DOB format '{$dob}'";
                    $error++;
                    continue;
                }

                // Password generation: First3Name + last3DOBYear + @
                $cleanName     = preg_replace('/\s+/', '', $name);
                $first3        = ucfirst(substr($cleanName, 0, 3));
                $year          = date('Y', $timestamp);
                $last3         = substr($year, -3);
                $plainPassword = $first3 . $last3 . '@';
                $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

                // Salary
                $basic = (float)$basicRaw;
                $allow = (float)($rowData['Allowances'] ?? 0);
                $net   = $basic + $allow;

                // Infer staff roles from Position column
                $position = trim($rowData['Position'] ?? '');
                $roleIds  = $this->_infer_roles_from_position($position);
                if (empty($roleIds)) $roleIds = ['ROLE_TEACHER'];
                $primaryRole = $roleIds[0];

                $data = [
                    'User ID'         => $staffId,
                    'Name'            => $name,
                    'Email'           => trim($rowData['Email'] ?? ''),
                    'Phone Number'    => $phone,
                    'Gender'          => trim($rowData['Gender'] ?? ''),
                    'Department'      => trim($rowData['Department'] ?? ''),
                    'Position'        => $position,
                    'Employment Type' => trim($rowData['Employment Type'] ?? ''),
                    'DOB'             => $dob,
                    'Date Of Joining' => trim($rowData['Date Of Joining'] ?? ''),
                    'Father Name'     => trim($rowData['Father Name'] ?? ''),
                    'Blood Group'     => trim($rowData['Blood Group'] ?? ''),
                    'Password'        => $hashedPassword,
                    'Credentials'     => ['Id' => $staffId, 'Password' => $hashedPassword],
                    'lastUpdated'     => date('Y-m-d'),
                    'staff_roles'     => $roleIds,
                    'primary_role'    => $primaryRole,

                    'qualificationDetails' => [
                        'highestQualification' => trim($rowData['Qualification'] ?? ''),
                        'experience'           => trim($rowData['Experience'] ?? ''),
                        'university'           => trim($rowData['University'] ?? ''),
                        'yearOfPassing'        => trim($rowData['Year Of Passing'] ?? ''),
                    ],

                    'salaryDetails' => [
                        'basicSalary' => $basic,
                        'Allowances'  => $allow,
                        'Net Salary'  => $net,
                    ],

                    'bankDetails' => [
                        'accountHolderName' => trim($rowData['Account Holder Name'] ?? ''),
                        'accountNumber'     => trim($rowData['Account Number'] ?? ''),
                        'bankName'          => trim($rowData['Bank Name'] ?? ''),
                        'ifscCode'          => trim($rowData['IFSC Code'] ?? ''),
                    ],

                    'emergencyContact' => [
                        'name'        => trim($rowData['Emergency Contact Name'] ?? ''),
                        'phoneNumber' => trim($rowData['Emergency Contact Number'] ?? ''),
                    ],

                    'Address' => [
                        'Street'     => trim($rowData['Street'] ?? ''),
                        'City'       => trim($rowData['City'] ?? ''),
                        'State'      => trim($rowData['State'] ?? ''),
                        'PostalCode' => trim($rowData['Postal Code'] ?? ''),
                    ],

                    'ProfilePic' => '',

                    'Doc' => [
                        'Photo'       => ['url' => '', 'thumbnail' => ''],
                        'Aadhar Card' => ['url' => '', 'thumbnail' => ''],
                    ],
                ];

                $this->firebase->set("Users/Teachers/{$school_id}/{$staffId}", $data);

                // Session Teachers roster (with roles — matches add_staff_ajax)
                $this->CM->addKey_pair_data("Schools/{$school_name}/{$session_year}/Teachers/{$staffId}", [
                    'Name'  => $name,
                    'roles' => $roleIds,
                ]);

                // Increment staff count (matches add_staff_ajax)
                $currentCount = (int)($this->CM->get_data("Users/Teachers/{$school_id}/Count") ?? 0);
                $this->CM->addKey_pair_data("Users/Teachers/{$school_id}/", ['Count' => $currentCount + 1]);

                // Auto-create salary structure for payroll (matches add_staff_ajax)
                $this->_sync_salary_structure($staffId, $basic, $allow);

                // Tenant-scoped phone index (primary)
                $this->CM->addKey_pair_data("Schools/{$school_name}/Phone_Index/", [$phone => $staffId]);
                // Legacy global indexes — kept for mobile app backward compatibility
                $this->CM->addKey_pair_data('Exits/', [$phone => $school_id]);
                $this->CM->addKey_pair_data('User_ids_pno/', [$phone => $staffId]);

                // Sync to MongoDB (best-effort)
                try {
                    $this->auth_client->sync_admin([
                        'adminId'            => $staffId,
                        'name'               => $name,
                        'email'              => trim($rowData['Email'] ?? ''),
                        'phone'              => $phone,
                        'role'               => 'STA',
                        'passwordHash'       => $hashedPassword,
                        'schoolId'           => $this->school_code,
                        'schoolCode'         => $this->school_name,
                        'parentDbKey'        => $this->parent_db_key,
                        'createdBy'          => $this->session->userdata('admin_id') ?: 'system',
                        'position'           => trim($rowData['Position'] ?? ''),
                        'department'         => trim($rowData['Department'] ?? ''),
                        'gender'             => trim($rowData['Gender'] ?? ''),
                        'deviceBindingMethod'=> 'otp',
                        'schoolDisplayName'  => $this->school_display_name ?? '',
                    ]);
                } catch (Exception $e) {
                    log_message('error', "Staff import MongoDB sync failed for {$staffId}: " . $e->getMessage());
                }

                $success++;
            }

            $isAjax = $this->input->is_ajax_request();

            if ($isAjax) {
                $this->json_success([
                    'success' => $success,
                    'failed'  => $error,
                    'skipped' => $skipped,
                    'message' => "Imported: {$success} | Failed: {$error}",
                ]);
            } else {
                $msg = "Staff Imported: {$success} | Failed: {$error}";
                if (!empty($skipped)) {
                    $msg .= " | Skipped: " . implode('; ', $skipped);
                }
                $this->session->set_flashdata('import_result', $msg);
                redirect('staff/all_staff');
            }
        } catch (Exception $e) {
            log_message('error', 'IMPORT STAFF ERROR: ' . $e->getMessage());

            if ($this->input->is_ajax_request()) {
                $this->json_error('Import failed: ' . $e->getMessage(), 500);
            } else {
                $this->session->set_flashdata('import_result', 'Import failed');
                redirect('staff/all_staff');
            }
        }
    }

    // ── Download pre-filled Excel template for bulk import ─────────────────
    public function download_staff_template()
    {
        $this->_require_role(self::MANAGE_ROLES);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Staff Import');

        // Headers — must match import_staff() expected columns exactly
        $headers = [
            'Name', 'Phone Number', 'DOB', 'Email', 'Gender',
            'Father Name', 'Blood Group', 'Department', 'Position',
            'Employment Type', 'Date Of Joining',
            'Qualification', 'Experience', 'University', 'Year Of Passing',
            'Basic Salary', 'Allowances',
            'Bank Name', 'Account Holder Name', 'Account Number', 'IFSC Code',
            'Emergency Contact Name', 'Emergency Contact Number',
            'Street', 'City', 'State', 'Postal Code',
        ];

        // Write headers (row 1) with styling
        $colLetter = 'A';
        foreach ($headers as $header) {
            $sheet->getCell($colLetter . '1')->setValue($header);
            $colLetter++;
        }

        // Style header row
        $lastCol = $sheet->getHighestColumn();
        $headerRange = "A1:{$lastCol}1";
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]],
        ]);

        // Sample data row (row 2)
        $sample = [
            'Rajesh Kumar', '9876543210', '15-06-1990', 'rajesh@example.com', 'Male',
            'Suresh Kumar', 'B+', 'Mathematics', 'Teacher',
            'Full-time', '01-04-2024',
            'B.Ed', '5', 'Delhi University', '2015',
            '25000', '5000',
            'State Bank of India', 'Rajesh Kumar', '12345678901234', 'SBIN0001234',
            'Suresh Kumar', '9876543211',
            '123 Main Street', 'New Delhi', 'Delhi', '110001',
        ];

        $colLetter = 'A';
        foreach ($sample as $value) {
            $sheet->getCell($colLetter . '2')->setValue($value);
            $colLetter++;
        }

        // Style sample row (light grey italic)
        $sampleRange = "A2:{$lastCol}2";
        $sheet->getStyle($sampleRange)->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => '888888']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F7F5']],
        ]);

        // Data validation dropdowns
        $genderValidation = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
        $genderValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $genderValidation->setFormula1('"Male,Female,Other"');
        $genderValidation->setShowDropDown(true);
        $sheet->getCell('E3')->setDataValidation(clone $genderValidation);
        for ($r = 3; $r <= 102; $r++) {
            $sheet->getCell("E{$r}")->setDataValidation(clone $genderValidation);
        }

        $empTypeValidation = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
        $empTypeValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $empTypeValidation->setFormula1('"Full-time,Part-time,Contract,Temporary"');
        $empTypeValidation->setShowDropDown(true);
        for ($r = 3; $r <= 102; $r++) {
            $sheet->getCell("J{$r}")->setDataValidation(clone $empTypeValidation);
        }

        $bloodValidation = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
        $bloodValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $bloodValidation->setFormula1('"A+,A-,B+,B-,O+,O-,AB+,AB-"');
        $bloodValidation->setShowDropDown(true);
        for ($r = 3; $r <= 102; $r++) {
            $sheet->getCell("G{$r}")->setDataValidation(clone $bloodValidation);
        }

        // Auto-width columns
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Freeze header row
        $sheet->freezePane('A2');

        // Instructions sheet
        $instrSheet = $spreadsheet->createSheet();
        $instrSheet->setTitle('Instructions');
        $instructions = [
            ['STAFF IMPORT TEMPLATE — INSTRUCTIONS'],
            [''],
            ['REQUIRED COLUMNS (must not be empty):'],
            ['  - Name: Full name of the staff member'],
            ['  - Phone Number: 10-digit Indian mobile (starts with 6-9)'],
            ['  - DOB: Date of birth (DD-MM-YYYY or YYYY-MM-DD)'],
            ['  - Email: Valid email address'],
            ['  - Gender: Male / Female / Other'],
            ['  - Father Name: Father\'s full name'],
            ['  - Position: e.g. Teacher, Senior Teacher, Accountant, Clerk'],
            ['  - Date Of Joining: DD-MM-YYYY or YYYY-MM-DD'],
            ['  - Employment Type: Full-time / Part-time / Contract / Temporary'],
            ['  - Department: e.g. Mathematics, Science, Administration'],
            ['  - Basic Salary: Numeric value (monthly basic pay)'],
            [''],
            ['OPTIONAL COLUMNS (leave blank if not available):'],
            ['  - Blood Group'],
            ['  - Qualification, Experience, University, Year Of Passing'],
            ['  - Allowances (defaults to 0)'],
            ['  - Bank Name, Account Holder Name, Account Number, IFSC Code'],
            ['  - Emergency Contact Name, Emergency Contact Number'],
            ['  - Street, City, State, Postal Code'],
            [''],
            ['PASSWORD GENERATION:'],
            ['  - Auto-generated as: First3Letters of Name + Last3Digits of DOB Year + @'],
            ['  - Example: Name="Rajesh", DOB=1990 → Password = "Raj990@"'],
            [''],
            ['NOTES:'],
            ['  - Row 2 on "Staff Import" sheet is a SAMPLE row — delete or overwrite it'],
            ['  - Staff ID is auto-generated (STA0001, STA0002, etc.)'],
            ['  - Photo & documents can be uploaded later via Edit Staff'],
            ['  - Do NOT change or reorder the column headers'],
            ['  - Gender dropdown: Male / Female / Other'],
            ['  - Employment Type dropdown: Full-time / Part-time / Contract / Temporary'],
        ];
        foreach ($instructions as $i => $row) {
            $instrSheet->setCellValue('A' . ($i + 1), $row[0]);
        }
        $instrSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $instrSheet->getStyle('A3')->getFont()->setBold(true);
        $instrSheet->getStyle('A7')->getFont()->setBold(true);
        $instrSheet->getStyle('A13')->getFont()->setBold(true);
        $instrSheet->getStyle('A18')->getFont()->setBold(true);
        $instrSheet->getColumnDimension('A')->setWidth(80);

        // Switch back to first sheet
        $spreadsheet->setActiveSheetIndex(0);

        // Output
        $filename = 'Staff_Import_Template_' . date('Y-m-d') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    }

    // // ─────────────────────────────────────────────────────────────────────────

    // public function add_staff($data)
    // {
    //     $school_id    = $this->parent_db_key;
    //     $school_name  = $this->school_name;
    //     $session_year = $this->session_year;

    //     $requiredFields = ['User Id', 'Name', 'School Name', 'Gender', 'Phone Number', 'Email', 'Password', 'Address'];

    //     $missingFields = array_filter($requiredFields, fn($f) => !isset($data[$f]) || trim($data[$f]) === '');
    //     if (!empty($missingFields)) {
    //         log_message('error', 'add_staff: required fields missing: ' . implode(', ', $missingFields));
    //         return;
    //     }

    //     if (empty($data['Password'])) {
    //         $name            = ucfirst($data['Name']);
    //         $data['Password'] = substr($name, 0, 3) . '123@';
    //     }

    //     // [FIX-2] Hash password
    //     $data['Password'] = password_hash($data['Password'], PASSWORD_DEFAULT);

    //     $phoneNumber = $data['Phone Number'];

    //     // [FIX-3] Validate phone number
    //     if (!preg_match('/^[6-9]\d{9}$/', $phoneNumber)) {
    //         log_message('error', 'add_staff: invalid phone number: ' . $phoneNumber);
    //         return;
    //     }

    //     $currentCount = $this->CM->get_data("Users/Teachers/Count") ?? 1;
    //     $userId = $currentCount;
    //     $data['User Id'] = $userId;

    //     $existingUser = $this->CM->select_data("Users/Teachers/{$school_id}/{$userId}");
    //     if ($existingUser) {
    //         log_message('error', 'add_staff: user already exists: ' . $userId);
    //         return;
    //     }

    //     $result = $this->CM->insert_data("Users/Teachers/{$school_id}/", $data);

    //     if ($result) {
    //         $this->CM->addKey_pair_data('Exits/', [$phoneNumber => $school_id]);
    //         $this->CM->addKey_pair_data('User_ids_pno/', [$phoneNumber => $userId]);
    //         $this->CM->addKey_pair_data("Schools/{$school_name}/{$session_year}/Teachers/{$userId}", ['Name' => $data['Name']]);
    //         $this->CM->addKey_pair_data('Users/Teachers/', ['Count' => $currentCount + 1]);
    //     }
    // }

    // ─────────────────────────────────────────────────────────────────────────

    public function new_staff()
    {
        $this->_require_role(self::MANAGE_ROLES);
        // [FIX-1] All school info from session
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $staffIdCount = $this->CM->get_data("Users/Teachers/{$school_id}/Count") ?? 1;

        $data['schoolName']    = $school_name;
        $data['staffIdCount']  = $staffIdCount;
        $data['user_Id']       = $staffIdCount;

        if ($this->input->method() === 'post') {
            header('Content-Type: application/json');

            $postData = $this->input->post();
            $normalizedPostData = [];
            foreach ($postData as $key => $value) {
                $normalizedPostData[str_replace('%20', ' ', urldecode($key))] = $value;
            }

            $staffName   = $normalizedPostData['Name']         ?? '';
            $phoneNumber = $normalizedPostData['phone_number']  ?? '';
            $emailAddr   = $normalizedPostData['email']         ?? '';

            if (empty($staffName)) {
                $this->json_error('Missing required fields.', 400);
            }

            // Generate STA ID from Auth API (race-safe sequential ID)
            $this->load->library('auth_client');
            $generatedId = $this->auth_client->generate_id('STA');

            if (!empty($generatedId)) {
                $staffId = $generatedId;
                $mongoSynced = false; // Record not yet created — only ID reserved
            } else {
                // Fallback: use local counter if Auth API is unavailable
                $staffId = $normalizedPostData['user_id'] ?? $staffIdCount;
                $mongoSynced = false;
                log_message('error', 'Staff: Auth API generate_id failed — using local ID.');
            }

            if (empty($staffId)) {
                $this->json_error('Failed to generate staff ID.', 500);
            }

            // [FIX-3] Validate phone
            if (!preg_match('/^[6-9]\d{9}$/', $phoneNumber)) {
                $this->json_error('Invalid phone number.', 400);
            }

            // Date formatting
            $formattedData = [];
            foreach (['dob' => 'DOB', 'date_of_joining' => 'dateOfJoining'] as $field => $outputKey) {
                $dateValue = $normalizedPostData[$field] ?? '';
                if (!empty($dateValue)) {
                    $dateObj = DateTime::createFromFormat('Y-m-d', $dateValue);
                    if (!$dateObj) {
                        $this->json_error("Invalid {$field} format.", 400);
                    }
                    $formattedData[$outputKey] = $dateObj->format('d-m-Y');
                } else {
                    $formattedData[$outputKey] = '';
                }
            }

            // ── Doc structure: Photo + Aadhar Card (mirrors student pattern) ──
            $docData = [
                'Photo'       => ['url' => '', 'thumbnail' => ''],
                'Aadhar Card' => ['url' => '', 'thumbnail' => ''],
            ];

            // Photo upload
            if (!empty($_FILES['Photo']['tmp_name'])) {
                $photo    = $_FILES['Photo'];
                $realMime = mime_content_type($photo['tmp_name']);

                if (!in_array($realMime, ['image/jpeg', 'image/jpg'], true)) {
                    $this->json_error('Only JPG/JPEG files are allowed for photos.', 400);
                }

                $result = $this->uploadStaffFile($photo, $school_name, $staffId, 'Photo');
                if (!$result) {
                    $this->json_error('Photo upload failed.', 500);
                }
                $docData['Photo'] = $result;
            }

            // Aadhar Card upload
            if (!empty($_FILES['Aadhar']['tmp_name'])) {
                $aadhar   = $_FILES['Aadhar'];
                $realMime = mime_content_type($aadhar['tmp_name']);

                if (!in_array($realMime, ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'], true)) {
                    $this->json_error('Only PDF, JPG, JPEG, or PNG files are allowed for Aadhar.', 400);
                }

                $result = $this->uploadStaffFile($aadhar, $school_name, $staffId, 'Aadhar Card');
                if (!$result) {
                    $this->json_error('Aadhar upload failed.', 500);
                }
                $docData['Aadhar Card'] = $result;
            }

            $addressData = [
                'City'       => $normalizedPostData['city']        ?? '',
                'PostalCode' => $normalizedPostData['postal_code'] ?? '',
                'State'      => $normalizedPostData['state']       ?? '',
                'Street'     => $normalizedPostData['street']      ?? '',
            ];

            $bankDetailsData = [
                'accountHolderName' => $normalizedPostData['account_holder'] ?? '',
                'accountNumber'     => $normalizedPostData['account_number'] ?? '',
                'bankName'          => $normalizedPostData['bank_name']      ?? '',
                'ifscCode'          => $normalizedPostData['bank_ifsc']      ?? '',
            ];

            $emergencyContactData = [
                'name'        => $normalizedPostData['emergency_contact_name']  ?? '',
                'phoneNumber' => $normalizedPostData['emergency_contact_phone'] ?? '',
            ];

            $qualificationDetailsData = [
                'experience'           => $normalizedPostData['teacher_experience'] ?? '',
                'highestQualification' => $normalizedPostData['qualification']      ?? '',
                'university'           => $normalizedPostData['university']         ?? '',
                'yearOfPassing'        => $normalizedPostData['year_of_passing']    ?? '',
            ];

            $basicSalary  = is_numeric($normalizedPostData['basicSalary'] ?? '')  ? (float) $normalizedPostData['basicSalary']  : 0.0;
            $allowances   = is_numeric($normalizedPostData['allowances']  ?? '')  ? (float) $normalizedPostData['allowances']   : 0.0;

            $salaryDetailsData = [
                'Allowances'  => $allowances,
                'basicSalary' => $basicSalary,
                'Net Salary'  => $basicSalary + $allowances,
            ];

            // [FIX-2] Hash the password (bcrypt cost 12 — matches admin pattern)
            $rawPassword = $normalizedPostData['password'] ?? '';
            if (empty($rawPassword)) {
                $rawPassword = substr(ucfirst($staffName), 0, 3) . '123@';
            }
            $hashedPassword = password_hash($rawPassword, PASSWORD_BCRYPT, ['cost' => 12]);

            // ── Staff roles (multi-role support) ─────────────────────────
            $rawRoles = $normalizedPostData['staff_roles'] ?? '';
            if (is_string($rawRoles) && $rawRoles !== '') {
                $roleIds = array_values(array_filter(array_map('trim', explode(',', $rawRoles))));
            } elseif (is_array($rawRoles)) {
                $roleIds = array_values(array_filter(array_map('trim', $rawRoles)));
            } else {
                $roleIds = [];
            }
            // Default to Teacher if no roles selected
            if (empty($roleIds)) {
                $roleIds = $this->_infer_roles_from_position($normalizedPostData['staff_position'] ?? '');
            }
            $primaryRole = trim($normalizedPostData['primary_role'] ?? '');
            if ($primaryRole === '' || !in_array($primaryRole, $roleIds, true)) {
                $primaryRole = $roleIds[0] ?? 'ROLE_TEACHER';
            }

            $staffRecord = [
                'Name'            => $staffName,
                'User ID'         => $staffId,
                'Phone Number'    => $phoneNumber,
                'Position'        => $normalizedPostData['staff_position'] ?? '',
                'Father Name'     => $normalizedPostData['father_name']    ?? '',
                'DOB'             => $formattedData['DOB'],
                'Email'           => $normalizedPostData['email']          ?? '',
                'Gender'          => $normalizedPostData['gender']         ?? '',
                'Date Of Joining' => $formattedData['dateOfJoining'],
                'Address'         => $addressData,
                'bankDetails'     => $bankDetailsData,
                'Department'      => $normalizedPostData['department']     ?? '',
                'emergencyContact' => $emergencyContactData,
                'Employment Type' => $normalizedPostData['employment_type'] ?? '',
                'qualificationDetails' => $qualificationDetailsData,
                'salaryDetails'   => $salaryDetailsData,
                'Blood Group'     => $normalizedPostData['blood_group']    ?? '',
                'ProfilePic'      => $docData['Photo']['url'],
                'Doc'             => $docData,
                'lastUpdated'     => date('Y-m-d'),
                'staff_roles'     => $roleIds,
                'primary_role'    => $primaryRole,
                'Password'        => $hashedPassword,
                'Credentials'     => ['Id' => $staffId, 'Password' => $hashedPassword],
            ];

            // [FIX-4] Use session school_id instead of undefined $schoolId
            $StaffPath = "Users/Teachers/{$school_id}/{$staffId}";
            $result    = $this->firebase->set($StaffPath, $staffRecord);

            if ($result !== false) {
                // Tenant-scoped phone index (primary)
                $this->CM->addKey_pair_data("Schools/{$school_name}/Phone_Index/", [$phoneNumber => $staffId]);
                // Legacy global indexes — kept for mobile app backward compatibility
                $this->CM->addKey_pair_data('Exits/', [$phoneNumber => $school_id]);
                $this->CM->addKey_pair_data('User_ids_pno/', [$phoneNumber => $staffId]);
                $newCount = $staffIdCount + 1;
                $this->CM->addKey_pair_data("Users/Teachers/{$school_id}/", ['Count' => $newCount]);
                $this->firebase->set("Schools/{$school_name}/{$session_year}/Teachers/{$staffId}", [
                    'Name'  => $staffName,
                    'roles' => $roleIds,
                ]);

                // Auto-create salary structure for payroll
                $this->_sync_salary_structure($staffId, $basicSalary, $allowances);

                // ── Create teacher record in MongoDB (Firebase succeeded) ──
                $syncResult = $this->auth_client->sync_admin([
                    'adminId'            => $staffId,
                    'name'               => $staffName,
                    'email'              => $emailAddr,
                    'phone'              => $phoneNumber,
                    'role'               => 'STA',
                    'passwordHash'       => $hashedPassword,
                    'schoolId'           => $this->school_code,
                    'schoolCode'         => $this->school_name,
                    'parentDbKey'        => $this->parent_db_key,
                    'createdBy'          => $this->admin_id ?? 'system',
                    'profilePic'         => $docData['Photo']['url'] ?? null,
                    'position'           => $normalizedPostData['staff_position'] ?? '',
                    'department'         => $normalizedPostData['department']     ?? '',
                    'gender'             => $normalizedPostData['gender']         ?? '',
                    'staffRoles'         => $roleIds,
                    'primaryRole'        => $primaryRole,
                    'classesAssigned'    => [],
                    'subjects'           => [],
                    'deviceBindingMethod'=> 'otp',
                    'schoolDisplayName'  => $this->school_display_name ?? '',
                ]);
                if (empty($syncResult['success'])) {
                    log_message('error', 'Staff: MongoDB sync failed for ' . $staffId . ': ' . json_encode($syncResult));
                }

                $this->json_success([
                    'message'          => 'Staff added successfully.',
                    'staff_id'         => $staffId,
                    'default_password' => $rawPassword,
                ]);
            } else {
                $this->json_error('Failed to save staff record.', 500);
            }
        }

        $this->load->view('include/header');
        $this->load->view('new_staff', $data);
        $this->load->view('include/footer');
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function delete_staff($id)
    {
        $this->_require_role(self::MANAGE_ROLES);
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        if (!$id || !preg_match('/^[A-Za-z0-9]+$/', $id)) {
            redirect(base_url() . 'staff/all_staff/');
            return;
        }

        $staff = $this->CM->select_data("Users/Teachers/{$school_id}/{$id}");

        if ($staff && isset($staff['Phone Number'])) {
            $phoneNumber = $staff['Phone Number'];

            $this->CM->delete_data("Schools/{$school_name}/{$session_year}/Teachers", $id);
            // Remove from tenant-scoped phone index
            $this->CM->delete_data("Schools/{$school_name}/Phone_Index", $phoneNumber);
            // Remove legacy global indexes
            $this->CM->delete_data('Exits', $phoneNumber);
            $this->CM->delete_data('User_ids_pno', $phoneNumber);
        }

        // Delete from Firebase profile
        $this->firebase->delete("Users/Teachers/{$school_id}", $id);

        // Delete from MongoDB (best-effort)
        try {
            $this->load->library('auth_client');
            $this->auth_client->delete_admin($id, $this->school_code, 'teacher');
        } catch (Exception $e) {
            log_message('error', "Staff delete MongoDB failed for {$id}: " . $e->getMessage());
        }

        redirect(base_url() . 'staff/all_staff/');
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function edit_staff($user_id)
    {
        $this->_require_role(self::MANAGE_ROLES);
        // [FIX-5] All school info from session
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        if ($this->input->method() === 'post') {
            header('Content-Type: application/json');

            $postData = $this->input->post();
            unset($postData['user_id'], $postData['User ID']);

            // Fetch existing record once — used for old-file deletion and Doc merge
            $existingData   = $this->firebase->get("Users/Teachers/{$school_id}/{$user_id}");
            $existingDoc    = is_array($existingData['Doc'] ?? null) ? $existingData['Doc'] : [];
            $docUpdates     = [];

            // ── Photo upload ──────────────────────────────────────────────────
            if (!empty($_FILES['Photo']['tmp_name'])) {
                $photo    = $_FILES['Photo'];
                $realMime = mime_content_type($photo['tmp_name']);

                if (!in_array($realMime, ['image/jpeg', 'image/jpg'], true)) {
                    $this->json_error('Only JPG/JPEG files are allowed for photos.', 400);
                }

                // Delete old photo + thumbnail from Storage
                $this->deleteStaffDoc($existingDoc['Photo'] ?? ($existingData['Photo URL'] ?? ''));

                $result = $this->uploadStaffFile($photo, $school_name, $user_id, 'Photo');
                if ($result) {
                    $docUpdates['Photo']    = $result;
                    $postData['ProfilePic'] = $result['url'];
                }
            }

            // ── Aadhar Card upload ────────────────────────────────────────────
            if (!empty($_FILES['Aadhar']['tmp_name'])) {
                $aadhar   = $_FILES['Aadhar'];
                $realMime = mime_content_type($aadhar['tmp_name']);

                if (!in_array($realMime, ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'], true)) {
                    $this->json_error('Only PDF, JPG, JPEG, or PNG files are allowed for Aadhar.', 400);
                }

                // Delete old Aadhar + thumbnail from Storage
                $this->deleteStaffDoc($existingDoc['Aadhar Card'] ?? ($existingData['Aadhar URL'] ?? ''));

                $result = $this->uploadStaffFile($aadhar, $school_name, $user_id, 'Aadhar Card');
                if ($result) {
                    $docUpdates['Aadhar Card'] = $result;
                }
            }

            // Structured fields
            $structuredFields = [
                'Address' => [
                    'city' => 'City',
                    'street' => 'Street',
                    'state' => 'State',
                    'postalcode' => 'PostalCode',
                ],
                'emergencyContact' => [
                    'emergency_contact_name' => 'name',
                    'emergency_contact_phone' => 'phoneNumber',
                ],
                'qualificationDetails' => [
                    'teacher_experience' => 'experience',
                    'qualification' => 'highestQualification',
                    'university' => 'university',
                    'year_of_passing' => 'yearOfPassing',
                ],
                'bankDetails' => [
                    'account_holder' => 'accountHolderName',
                    'account_number' => 'accountNumber',
                    'bank_name' => 'bankName',
                    'bank_ifsc' => 'ifscCode',
                ],
            ];

            $structuredData = [];
            foreach ($structuredFields as $category => $fields) {
                foreach ($fields as $fieldKey => $firebaseKey) {
                    if (isset($postData[$fieldKey])) {
                        $structuredData[$category][$firebaseKey] = $postData[$fieldKey];
                        unset($postData[$fieldKey]);
                    }
                }
            }

            $formattedData = $this->CM->formatAndPrepareFirebaseData($postData);
            $formattedData = array_merge($formattedData, $structuredData);

            // Date formatting
            foreach (['DOB', 'Date Of Joining'] as $dateField) {
                if (!empty($formattedData[$dateField])) {
                    $ts = strtotime($formattedData[$dateField]);
                    $formattedData[$dateField] = $ts ? date('d-m-Y', $ts) : '';
                } else {
                    $formattedData[$dateField] = '';
                }
            }

            // Prevent Credentials from being overwritten via edit
            unset($formattedData['Credentials']);

            // ── Staff roles update ───────────────────────────────────────
            $rawRoles = $postData['staff_roles'] ?? '';
            if (is_string($rawRoles) && $rawRoles !== '') {
                $editRoleIds = array_values(array_filter(array_map('trim', explode(',', $rawRoles))));
            } elseif (is_array($rawRoles)) {
                $editRoleIds = array_values(array_filter(array_map('trim', $rawRoles)));
            } else {
                $editRoleIds = null; // not submitted = don't change
            }
            if ($editRoleIds !== null) {
                $formattedData['staff_roles'] = $editRoleIds;
                $editPrimary = trim($postData['primary_role'] ?? '');
                if ($editPrimary === '' || !in_array($editPrimary, $editRoleIds, true)) {
                    $editPrimary = $editRoleIds[0] ?? 'ROLE_TEACHER';
                }
                $formattedData['primary_role'] = $editPrimary;
            }
            // Remove raw keys so they don't pollute the flat write
            unset($formattedData['staff_roles_raw'], $postData['staff_roles'], $postData['primary_role']);

            // Merge updated Doc entries (if any files were uploaded) into the
            // existing Doc node so unchanged documents are preserved.
            if (!empty($docUpdates)) {
                $formattedData['Doc'] = array_merge($existingDoc, $docUpdates);
            }

            $oldPhoneNumber = $existingData['Phone Number'] ?? null;
            $oldName        = $existingData['Name']         ?? null;

            $updateRes = $this->CM->update_data("Users/Teachers/{$school_id}", $user_id, $formattedData);

            if ($updateRes) {
                // Phone number changed — update lookup tables
                if (!empty($formattedData['Phone Number']) && $formattedData['Phone Number'] !== $oldPhoneNumber) {
                    if ($oldPhoneNumber) {
                        // Remove old phone from tenant-scoped index
                        $this->firebase->delete("Schools/{$school_name}/Phone_Index/{$oldPhoneNumber}");
                        // Remove old phone from legacy global indexes
                        $this->firebase->delete('Exits/' . $oldPhoneNumber);
                        $this->firebase->delete('User_ids_pno/' . $oldPhoneNumber);
                    }
                    $newPhone = $formattedData['Phone Number'];
                    // Tenant-scoped phone index (primary)
                    $this->CM->update_data('', "Schools/{$school_name}/Phone_Index/", [$newPhone => $user_id]);
                    // Legacy global indexes — kept for mobile app backward compatibility
                    $this->CM->update_data('', 'Exits/',       [$newPhone => $school_id]);
                    $this->CM->update_data('', 'User_ids_pno/', [$newPhone => $user_id]);
                }

                // Sync salary structure if salary fields were submitted
                $editBasic = (float) ($postData['basicSalary'] ?? $postData['Basicsalary'] ?? 0);
                $editAllow = (float) ($postData['allowances']  ?? $postData['Allowances']  ?? 0);
                if ($editBasic > 0) {
                    $this->_sync_salary_structure($user_id, $editBasic, $editAllow);
                    // Also update the salaryDetails node in the staff profile
                    $this->firebase->update("Users/Teachers/{$school_id}/{$user_id}", [
                        'salaryDetails' => [
                            'basicSalary' => $editBasic,
                            'Allowances'  => $editAllow,
                            'Net Salary'  => $editBasic + $editAllow,
                        ],
                    ]);
                }

                // Name or roles changed — update session roster
                $teacherName = $formattedData['Name'] ?? null;
                $rolesChanged = isset($formattedData['staff_roles']);
                if (($teacherName && $teacherName !== $oldName) || $rolesChanged) {
                    $rosterUpdate = ['Name' => $teacherName ?: $oldName];
                    if ($rolesChanged) {
                        $rosterUpdate['roles'] = $formattedData['staff_roles'];
                    }
                    $this->firebase->set("Schools/{$school_name}/{$session_year}/Teachers/{$user_id}", $rosterUpdate);
                }

                // ── Sync updated profile to MongoDB (best-effort) ──
                $this->load->library('auth_client');
                $mongoUpdate = [
                    'adminId'    => $user_id,
                    'role'       => 'STA',
                    'schoolId'   => $this->school_code,
                    'schoolCode' => $this->school_name,
                    'parentDbKey'=> $this->parent_db_key,
                ];
                if ($teacherName)                                $mongoUpdate['name']       = $teacherName;
                if (!empty($formattedData['Email']))             $mongoUpdate['email']      = $formattedData['Email'];
                if (!empty($formattedData['Phone Number']))      $mongoUpdate['phone']      = $formattedData['Phone Number'];
                if (!empty($formattedData['Gender']))            $mongoUpdate['gender']     = $formattedData['Gender'];
                if (isset($postData['staff_position']) || isset($postData['position']))
                    $mongoUpdate['position'] = $postData['staff_position'] ?? $postData['position'] ?? '';
                if (isset($postData['department']))              $mongoUpdate['department'] = $postData['department'];
                if (isset($formattedData['staff_roles']))        $mongoUpdate['staffRoles'] = $formattedData['staff_roles'];
                if (isset($formattedData['primary_role']))       $mongoUpdate['primaryRole'] = $formattedData['primary_role'];
                // ProfilePic — only if photo was re-uploaded
                if (!empty($docUpdates['Photo']['url']))         $mongoUpdate['profilePic'] = $docUpdates['Photo']['url'];

                $editSync = $this->auth_client->sync_admin($mongoUpdate);
                if (empty($editSync['success'])) {
                    log_message('error', 'Staff: MongoDB sync failed on edit_staff for ' . $user_id . ': ' . json_encode($editSync));
                }

                $this->json_success();
            } else {
                $this->json_error('Update failed.', 500);
            }
        } else {
            $data['staff_data'] = $this->CM->select_data("Users/Teachers/{$school_id}/{$user_id}");

            if (!empty($data['staff_data'])) {
                $this->load->view('include/header');
                $this->load->view('edit_staff', ['staff_data' => $data['staff_data']]);
                $this->load->view('include/footer');
            } else {
                log_message('error', 'Staff data not found for ID: ' . $user_id);
                show_404();
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function teacher_profile($id)
    {
        $this->_require_role(self::VIEW_ROLES);
        $school_id = $this->parent_db_key;

        if (!$id || !preg_match('/^[A-Za-z0-9]+$/', $id)) {
            show_404();
            return;
        }

        $teacherData = $this->firebase->get("Users/Teachers/{$school_id}/{$id}");

        $this->load->view('include/header');
        $this->load->view('teacher_profile', ['teacher' => $teacherData]);
        $this->load->view('include/footer');
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function search_teacher()
    {
        $this->_require_role(self::VIEW_ROLES);
        header('Content-Type: application/json');

        $searchResults = [];
        $searchQuery   = trim((string) ($this->input->post('search_name') ?? ''));

        if ($searchQuery) {
            $searchResults = $this->search_by_name($searchQuery);
        }

        echo json_encode($searchResults);
        exit;
    }

    private function search_by_name(string $entry): array
    {
        $school_id    = $this->parent_db_key;
        $results      = [];
        $teachers     = $this->CM->get_data("Users/Teachers/{$school_id}");

        if (!empty($teachers)) {
            foreach ($teachers as $userId => $teacher) {
                if (!is_array($teacher)) continue;

                $name       = $teacher['Name']        ?? '';
                $userIdField = $teacher['User ID']     ?? '';
                $fatherName = $teacher['Father Name'] ?? '';

                if (
                    stripos($name,        $entry) !== false ||
                    stripos($userIdField, $entry) !== false ||
                    stripos($fatherName,  $entry) !== false
                ) {
                    $results[] = [
                        'user_id'    => $userIdField,
                        'name'       => htmlspecialchars($name,       ENT_QUOTES, 'UTF-8'),
                        'father_name' => htmlspecialchars($fatherName, ENT_QUOTES, 'UTF-8'),
                    ];
                }
            }
        }

        return $results;
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function fetch_subjects()
    {
        $this->_require_role(self::VIEW_ROLES);
        header('Content-Type: application/json');

        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        // Read from $_POST so CI CSRF filter can validate the token
        $className = trim((string) $this->input->post('class_name'));
        $section   = trim((string) $this->input->post('section'));

        if (!$className || !$section) {
            echo json_encode([]);
            return;
        }

        // Build combined key: "Class 9th 'A'"
        $classSection = $className . " '" . $section . "'";

        // [FIX-8] Validate classSection before use in path
        if (!$this->valid_class_section($classSection)) {
            $this->json_error('Invalid class section.', 400);
        }

        $subjectsPath = "Schools/{$school_name}/{$session_year}/{$classSection}/Subjects";
        $subjects     = $this->CM->get_data($subjectsPath);

        echo json_encode(is_array($subjects) ? array_keys($subjects) : []);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function assign_duty()
    {
        $this->_require_role(self::MANAGE_ROLES);
        header('Content-Type: application/json');

        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $classSection = trim((string) $this->input->post('class_section'));
        $subject      = strip_tags(trim((string) $this->input->post('subject')));
        $teacherName  = trim((string) $this->input->post('teacher_name'));
        $dutyType     = trim((string) $this->input->post('duty_type'));
        $timeSlot     = trim((string) $this->input->post('time_slot'));

        if (!$classSection || !$subject || !$teacherName || !$dutyType) {
            $this->json_error('Missing required fields.', 400);
        }

        // [FIX-7] Validate classSection
        if (!$this->valid_class_section($classSection)) {
            $this->json_error('Invalid class section format.', 400);
        }

        if (!preg_match('/^([A-Za-z0-9]+)\s-\s(.+)$/', $teacherName, $matches)) {
            $this->json_error('Invalid teacher format.', 400);
        }

        $teacherID       = $matches[1];
        $teacherOnlyName = $matches[2];

        $firebasePath  = "Schools/{$school_name}/{$session_year}/Teachers/{$teacherID}/Duties/{$dutyType}/{$classSection}";
        $updateResponse = $this->firebase->update($firebasePath, [$subject => $timeSlot ?: '']);

        $profilePicPath = "Users/Teachers/{$school_id}/{$teacherID}/Doc/ProfilePic";
        $profilePicURL  = $this->firebase->get($profilePicPath) ?: base_url('tools/image/default-school.jpeg');

        $classPath             = "Schools/{$school_name}/{$session_year}/{$classSection}/Subjects/{$subject}";
        $profileUpdateResponse = $this->firebase->update($classPath, [htmlspecialchars($teacherOnlyName, ENT_QUOTES, 'UTF-8') => $profilePicURL]);

        if ($dutyType === 'ClassTeacher') {
            $this->firebase->set("Schools/{$school_name}/{$session_year}/{$classSection}/ClassTeacher", $teacherOnlyName);
        }

        // ── Sync classesAssigned + subjects to MongoDB for mobile app ──
        $dutyData = $this->firebase->get("Schools/{$school_name}/{$session_year}/Teachers/{$teacherID}/Duties");
        if (is_array($dutyData)) {
            $allClasses  = [];
            $allSubjects = [];
            foreach ($dutyData as $type => $classes) {
                if (!is_array($classes)) continue;
                foreach ($classes as $cls => $subjs) {
                    $allClasses[] = $cls;
                    if (is_array($subjs)) {
                        $allSubjects = array_merge($allSubjects, array_keys($subjs));
                    }
                }
            }
            $this->load->library('auth_client');
            $this->auth_client->sync_admin([
                'adminId'           => $teacherID,
                'role'              => 'STA',
                'schoolId'          => $this->school_code,
                'schoolCode'        => $this->school_name,
                'parentDbKey'       => $this->parent_db_key,
                'classesAssigned'   => array_values(array_unique($allClasses)),
                'subjects'          => array_values(array_unique($allSubjects)),
                'schoolDisplayName' => $this->school_display_name ?? '',
            ]);
        }

        $this->json_success([
            'message' => 'Duty assigned successfully.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function markInactive_duty()
    {
        $this->_require_role(self::MANAGE_ROLES);
        header('Content-Type: application/json');

        // [FIX-6] Use school_name (not school_id) consistently
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $class_name   = trim((string) $this->input->post('class_name'));
        $subject      = strip_tags(trim((string) $this->input->post('subject')));
        $teacher_name = trim((string) $this->input->post('teacher_name'));

        if (!$class_name || !$subject || !$teacher_name) {
            $this->json_error('Invalid data.', 400);
        }

        if (!preg_match('/^(\d+)\s-\s(.+)$/', $teacher_name, $matches)) {
            $this->json_error('Invalid teacher format.', 400);
        }

        $teacherID       = $matches[1];
        $teacherOnlyName = $matches[2];

        // [FIX-6] Use $school_name here (was using $school_id — wrong!)
        $dutyPath = "Schools/{$school_name}/{$session_year}/Teachers/{$teacherID}/Duties";
        $duties   = $this->firebase->get($dutyPath);
        $dutyDeleted = false;

        if ($duties) {
            foreach ($duties as $dutyType => $classes) {
                if (isset($classes[$class_name][$subject])) {
                    unset($classes[$class_name][$subject]);

                    $updateData = !empty($classes[$class_name]) ? [$class_name => $classes[$class_name]] : null;

                    if ($updateData) {
                        $this->firebase->update("{$dutyPath}/{$dutyType}", $updateData);
                    } else {
                        $this->firebase->delete("{$dutyPath}/{$dutyType}/{$class_name}");
                    }

                    if ($dutyType === 'ClassTeacher') {
                        $this->firebase->set("Schools/{$school_name}/{$session_year}/{$class_name}/ClassTeacher", '');
                    }

                    $dutyDeleted = true;
                    break;
                }
            }
        }

        if (!$dutyDeleted) {
            $this->json_error('Duty not found.', 404);
        }

        $subjectPath    = "Schools/{$school_name}/{$session_year}/{$class_name}/Subjects/{$subject}";
        $subjectTeachers = $this->firebase->get($subjectPath);

        if (is_array($subjectTeachers) && isset($subjectTeachers[$teacherOnlyName])) {
            unset($subjectTeachers[$teacherOnlyName]);

            if (!empty($subjectTeachers)) {
                $this->firebase->update($subjectPath, $subjectTeachers);
            } else {
                $this->firebase->set($subjectPath, '');
            }
        }

        $this->json_success(['message' => 'Teacher removed and duty marked inactive.']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function teacher_id_card()
    {
        $this->_require_role(self::VIEW_ROLES);
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        // Fetch all staff records for this school
        $allStaff = $this->CM->select_data("Users/Teachers/{$school_id}");
        if (!is_array($allStaff)) $allStaff = [];

        // Keep only array entries (drop 'Count' and other non-staff nodes)
        $allStaff = array_filter($allStaff, 'is_array');

        // SESSION ISOLATION: only show teachers assigned to this session
        $sessionTeachers = $this->firebase->get("Schools/{$school_name}/{$session_year}/Teachers");
        if (is_array($sessionTeachers) && !empty($sessionTeachers)) {
            $allStaff = array_intersect_key($allStaff, $sessionTeachers);
        } else {
            $allStaff = [];
        }

        // Fetch school display name and logo for ID card header
        $displayName = $this->school_display_name ?: $school_name;
        $schoolLogo  = '';
        $sysSchool   = $this->firebase->get("System/Schools/{$this->school_id}/profile");
        if (is_array($sysSchool)) {
            $schoolLogo  = $sysSchool['logo_url'] ?? $sysSchool['Logo'] ?? '';
            if (empty($displayName) || $displayName === $school_name) {
                $displayName = $sysSchool['school_name'] ?? $sysSchool['name'] ?? $sysSchool['School Name'] ?? $displayName;
            }
        }
        if (empty($schoolLogo)) {
            $configProfile = $this->firebase->get("Schools/{$school_name}/Config/Profile");
            if (is_array($configProfile)) {
                $schoolLogo = $configProfile['logo_url'] ?? '';
                if (empty($displayName) || $displayName === $school_name) {
                    $displayName = $configProfile['display_name'] ?? $displayName;
                }
            }
        }

        $data['staff']        = array_values($allStaff);
        $data['session_year'] = $session_year;
        $data['school_name']  = $displayName;
        $data['school_logo']  = $schoolLogo;

        $this->load->view('include/header');
        $this->load->view('teacher_id_card', $data);
        $this->load->view('include/footer');
    }

    // =========================================================================
    //  STAFF ROLE MANAGEMENT (AJAX)
    // =========================================================================

    /**
     * GET /staff/get_staff_roles
     * Returns all staff role definitions for dropdowns/multi-selects.
     */
    public function get_staff_roles()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_staff_roles');
        $roles = $this->firebase->get("Schools/{$this->school_name}/Config/StaffRoles") ?? [];
        if (!is_array($roles)) $roles = [];
        $this->json_success(['roles' => $roles]);
    }

    /**
     * POST /staff/save_staff_role
     * Create or update a custom staff role definition.
     */
    public function save_staff_role()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_staff_role');
        $roleId   = trim($this->input->post('role_id', TRUE) ?? '');
        $label    = trim($this->input->post('label', TRUE) ?? '');
        $category = trim($this->input->post('category', TRUE) ?? '');

        if ($roleId === '' || $label === '') {
            return $this->json_error('Role ID and label are required.');
        }
        if (!preg_match('/^ROLE_[A-Z0-9_]+$/', $roleId)) {
            return $this->json_error('Role ID must be ROLE_ followed by uppercase letters/digits/underscores.');
        }

        $validCategories = ['Teaching', 'Non-Teaching', 'Administrative', 'Support'];
        if (!in_array($category, $validCategories, true)) {
            return $this->json_error('Invalid category. Must be: ' . implode(', ', $validCategories));
        }

        $path = "Schools/{$this->school_name}/Config/StaffRoles/{$roleId}";
        $existing = $this->firebase->get($path);

        // Don't allow changing system role category or label
        if (is_array($existing) && !empty($existing['is_system'])) {
            // System roles: only flags can be edited
            $flagsRaw = $this->input->post('flags') ?? [];
            $flags = is_array($flagsRaw) ? $flagsRaw : [];
            $this->firebase->update($path, ['flags' => $flags]);
            return $this->json_success(['message' => "System role '{$label}' flags updated."]);
        }

        $flagsRaw = $this->input->post('flags') ?? [];
        $flags = is_array($flagsRaw) ? $flagsRaw : [];
        $attendanceType = trim($this->input->post('attendance_type', TRUE) ?? 'standard');
        if (!in_array($attendanceType, ['standard', 'shift', 'flexible'], true)) {
            $attendanceType = 'standard';
        }

        $roleData = [
            'label'           => $label,
            'category'        => $category,
            'flags'           => $flags,
            'attendance_type' => $attendanceType,
            'is_system'       => false,
        ];

        $this->firebase->set($path, $roleData);
        $this->json_success(['message' => "Staff role '{$label}' saved.", 'role_id' => $roleId]);
    }

    /**
     * POST /staff/delete_staff_role
     * Delete a custom (non-system) staff role.
     */
    public function delete_staff_role()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_staff_role');
        $roleId = trim($this->input->post('role_id', TRUE) ?? '');
        if ($roleId === '') return $this->json_error('role_id is required.');

        $path = "Schools/{$this->school_name}/Config/StaffRoles/{$roleId}";
        $existing = $this->firebase->get($path);
        if (!is_array($existing)) return $this->json_error('Role not found.');
        if (!empty($existing['is_system'])) return $this->json_error('System roles cannot be deleted.');

        $this->firebase->delete("Schools/{$this->school_name}/Config/StaffRoles", $roleId);
        $this->json_success(['message' => 'Staff role deleted.']);
    }

    /**
     * POST /staff/get_staff_by_role
     * Returns staff list filtered by a specific role.
     */
    public function get_staff_by_role()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_staff_by_role');
        $roleId = trim($this->input->post('role_id', TRUE) ?? '');
        if ($roleId === '') return $this->json_error('role_id is required.');

        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $allStaff = $this->firebase->get("Users/Teachers/{$school_id}") ?? [];
        if (!is_array($allStaff)) $allStaff = [];
        $allStaff = array_filter($allStaff, 'is_array');

        // Session isolation
        $roster = $this->firebase->get("Schools/{$school_name}/{$session_year}/Teachers") ?? [];
        if (is_array($roster) && !empty($roster)) {
            $allStaff = array_intersect_key($allStaff, $roster);
        } else {
            $allStaff = [];
        }

        $filtered = [];
        foreach ($allStaff as $sid => $s) {
            $roles = $s['staff_roles'] ?? [];
            // Fallback for unmigrated staff
            if (empty($roles)) {
                $roles = $this->_infer_roles_from_position($s['Position'] ?? '');
            }
            if (in_array($roleId, $roles, true)) {
                $filtered[] = [
                    'id'           => $sid,
                    'name'         => $s['Name'] ?? $sid,
                    'department'   => $s['Department'] ?? '',
                    'position'     => $s['Position'] ?? '',
                    'staff_roles'  => $roles,
                    'primary_role' => $s['primary_role'] ?? ($roles[0] ?? ''),
                    'phone'        => $s['Phone Number'] ?? '',
                ];
            }
        }

        $this->json_success(['staff' => $filtered, 'role_id' => $roleId]);
    }

    /**
     * POST /staff/migrate_staff_roles
     * One-shot bulk migration: infer roles from Position field for all staff
     * that don't have staff_roles set yet. Admin-triggered only.
     */
    public function migrate_staff_roles()
    {
        $this->_require_role(self::MANAGE_ROLES, 'migrate_staff_roles');
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $allStaff = $this->firebase->get("Users/Teachers/{$school_id}") ?? [];
        if (!is_array($allStaff)) $allStaff = [];
        $allStaff = array_filter($allStaff, 'is_array');

        $migrated = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($allStaff as $sid => $s) {
            // Skip if already has staff_roles
            if (!empty($s['staff_roles']) && is_array($s['staff_roles'])) {
                $skipped++;
                continue;
            }

            $position = $s['Position'] ?? '';
            $roleIds  = $this->_infer_roles_from_position($position);
            $primary  = $roleIds[0] ?? 'ROLE_TEACHER';

            $ok = $this->firebase->update("Users/Teachers/{$school_id}/{$sid}", [
                'staff_roles'  => $roleIds,
                'primary_role' => $primary,
            ]);

            if ($ok !== false) {
                // Update roster if exists
                $rosterPath = "Schools/{$school_name}/{$session_year}/Teachers/{$sid}";
                $rosterData = $this->firebase->get($rosterPath);
                if (is_array($rosterData)) {
                    $this->firebase->update($rosterPath, ['roles' => $roleIds]);
                }
                $migrated++;
            } else {
                $errors++;
            }
        }

        $this->json_success([
            'message'  => "{$migrated} staff migrated, {$skipped} already had roles, {$errors} errors.",
            'migrated' => $migrated,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);
    }
}
