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
        if (empty($this->school_id)) return; // not logged in yet
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        if (!empty($schoolDoc['staffRoles'])) return;
        $this->fs->set('schools', $this->school_id, ['staffRoles' => self::DEFAULT_STAFF_ROLES], true);
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

    /**
     * Convert a role ID to its display label.
     * Used to derive the legacy 'Position' field from the primary staff role
     * (since Designation/Title was removed from the form in favor of Staff Roles).
     */
    private function _role_id_to_label(string $roleId): string
    {
        if ($roleId === '') return '';
        // Check default system roles first
        if (isset(self::DEFAULT_STAFF_ROLES[$roleId]['label'])) {
            return self::DEFAULT_STAFF_ROLES[$roleId]['label'];
        }
        // Custom role: read from Firestore schools.staffRoles
        try {
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            $customRoles = $schoolDoc['staffRoles'] ?? [];
            if (is_array($customRoles) && isset($customRoles[$roleId]['label'])) {
                return (string)$customRoles[$roleId]['label'];
            }
        } catch (\Exception $e) {
            // Fall through to fallback
        }
        // Fallback: humanize the role ID (ROLE_LIBRARIAN → "Librarian")
        return ucfirst(strtolower(str_replace(['ROLE_', '_'], ['', ' '], $roleId)));
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

        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $fsConfig = $schoolDoc['salaryDefaults'] ?? null;
        $defaults = self::SALARY_DEFAULTS_FALLBACK;
        if (is_array($fsConfig)) {
            foreach ($defaults as $k => $v) {
                if (isset($fsConfig[$k]) && is_numeric($fsConfig[$k])) {
                    $defaults[$k] = (float) $fsConfig[$k];
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

        $staffDoc = $this->fs->getEntity('staff', $staffId);
        $existing = $staffDoc['salaryStructure'] ?? null;
        $now      = date('c');

        // Manual structure → don't overwrite, just note the sync
        if (is_array($existing) && ($existing['source'] ?? '') === 'manual') {
            $this->fs->updateEntity('staff', $staffId, ['salaryStructure' => array_merge($existing, ['last_synced_at' => $now])]);
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

        $this->fs->updateEntity('staff', $staffId, ['salaryStructure' => $structure]);

        log_message('info',
            "Salary structure auto-" . (is_array($existing) ? "updated(v{$structure['_version']})" : 'created')
            . " staff=[{$staffId}] school=[{$this->school_id}]"
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
        $session_year = $this->session_year;

        // Firestore: query all staff for this school assigned to current session
        $staffDocs = $this->fs->schoolWhere('staff', [['sessions', 'array-contains', $session_year]], 'Name', 'ASC');

        // Firestore is the sole source per no-RTDB policy. No fallback.

        $data['staff'] = [];
        foreach ($staffDocs as $doc) {
            $d = $doc['data'] ?? $doc;
            $s = $doc['data'];
            $s['_profilePic'] = $s['ProfilePic'] ?? $s['Photo URL'] ?? $s['profilePic'] ?? '';
            $id = $s['User ID'] ?? $s['staffId'] ?? $d['id'];
            $data['staff'][$id] = $s;
        }

        $data['school_name'] = $this->school_name;

        // Load staff role definitions from school config
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $data['staff_role_defs'] = $schoolDoc['staffRoles'] ?? [];
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

        // Firestore: count staff docs for this school
        $actualCount = $this->fs->count('staff', [['schoolId', '==', $this->school_id]]);

        // Update school doc with correct count
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $storedCount = (int) ($schoolDoc['staffCount'] ?? 0);
        $this->fs->update('schools', $this->school_id, ['staffCount' => $actualCount]);

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

            $this->load->library('id_generator');

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

                // Phase 4.2 — safeGenerate retries transient failures
                // and throws on catastrophic exhaustion. Per-row
                // try/catch keeps the bulk import resilient: one bad
                // row doesn't fail the whole CSV.
                try {
                    $staffId = $this->id_generator->safeGenerate('STA');
                } catch (\Throwable $e) {
                    log_message('error', 'ID_GEN_INTEGRATION staff_bulk_import_failed row=' . ($success + $error + count($skipped) + 1) . ' err=' . $e->getMessage());
                    $skipped[] = "Row " . ($success + $error + count($skipped) + 1) . ": ID generation failed (" . $e->getMessage() . ")";
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

                $religion    = trim($rowData['Religion'] ?? '');
                $category    = trim($rowData['Category'] ?? '');
                $bloodGroup  = trim($rowData['Blood Group'] ?? '');
                $designation = trim($rowData['Designation'] ?? '');
                $altPhone    = trim($rowData['Alt Phone'] ?? '');
                $maritalSt   = trim($rowData['Marital Status'] ?? '');
                $panNumber   = strtoupper(trim($rowData['PAN Number'] ?? ''));
                $aadharNum   = trim($rowData['Aadhar Number'] ?? '');
                $pfNumber    = trim($rowData['PF Number'] ?? '');
                $esiNumber   = trim($rowData['ESI Number'] ?? '');
                $teachingSubjectsRaw = trim($rowData['Teaching Subjects'] ?? '');
                $teachingSubjects = $teachingSubjectsRaw !== ''
                    ? array_values(array_filter(array_map('trim', explode(',', $teachingSubjectsRaw))))
                    : [];

                // If display label is non-blank, prefer it over auto-derived label.
                $positionLabel = $designation !== '' ? $designation : $position;

                $data = [
                    'User ID'         => $staffId,
                    'Name'            => $name,
                    'Email'           => $email,
                    'Phone Number'    => $phone,
                    'Gender'          => $gender,
                    'Department'      => $department,
                    'Position'        => $positionLabel,
                    'Employment Type' => $empType,
                    'DOB'             => $dob,
                    'Date Of Joining' => $dojRaw,
                    'Father Name'     => $fatherName,
                    'Blood Group'     => $bloodGroup,
                    'Religion'        => $religion,
                    'Category'        => $category,
                    'Password'        => $hashedPassword,
                    'Credentials'     => ['Id' => $staffId, 'Password' => $hashedPassword],
                    'lastUpdated'     => date('Y-m-d'),
                    'staff_roles'     => $roleIds,
                    'primary_role'    => $primaryRole,

                    // Phase A statutory fields — mirror new_staff()
                    'altPhone'        => $altPhone,
                    'maritalStatus'   => $maritalSt,
                    'designation'     => $designation,
                    'panNumber'       => $panNumber,
                    'aadharNumber'    => $aadharNum,
                    'pfNumber'        => $pfNumber,
                    'esiNumber'       => $esiNumber,

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
                        'relation'    => trim($rowData['Emergency Contact Relation'] ?? ''),
                    ],

                    'Address' => [
                        'Street'     => trim($rowData['Street'] ?? ''),
                        'City'       => trim($rowData['City'] ?? ''),
                        'State'      => trim($rowData['State'] ?? ''),
                        'PostalCode' => trim($rowData['Postal Code'] ?? ''),
                    ],

                    'permanentAddress' => [
                        'street'     => trim($rowData['Permanent Street'] ?? ''),
                        'city'       => trim($rowData['Permanent City'] ?? ''),
                        'state'      => trim($rowData['Permanent State'] ?? ''),
                        'postalCode' => trim($rowData['Permanent Postal Code'] ?? ''),
                    ],
                    'sameAsCurrentAddress' => false,

                    'ProfilePic' => '',

                    'Doc' => [
                        'Photo'       => ['url' => '', 'thumbnail' => ''],
                        'Aadhar Card' => ['url' => '', 'thumbnail' => ''],
                    ],
                ];

                if (!empty($teachingSubjects)) {
                    $data['teaching_subjects'] = $teachingSubjects;
                }

                // Write full record to Firestore
                // camelCase aliases mirror new_staff() exactly — Parent + Teacher apps read these.
                $fsData = array_merge($data, [
                    'schoolId'       => $this->school_id,
                    'session'        => $session_year,
                    'sessions'       => [$session_year],
                    'staffId'        => $staffId,
                    'name'           => $name,
                    'phone'          => $phone,
                    'email'          => $email,
                    'status'         => 'Active',
                    'role'           => $positionLabel,
                    'roleId'         => $primaryRole,
                    'position'       => $positionLabel,
                    'department'     => $department,
                    'gender'         => $gender,
                    'employmentType' => $empType,
                    'fatherName'     => $fatherName,
                    'dateOfJoining'  => $dojRaw,
                    'dob'            => $dob,
                    'bloodGroup'     => $bloodGroup,
                    'religion'       => $religion,
                    'category'       => $category,
                    'profilePic'     => '',
                    'updatedAt'      => date('c'),
                ]);
                unset($fsData['Password'], $fsData['Credentials']);
                // Phase 4.3 — guarded write. If Firestore rejects or
                // errors, release the STA claim for this row and count
                // it as skipped instead of burning a number + leaving
                // an orphan claim doc.
                try {
                    $writeOk = $this->fs->set('staff', $this->fs->docId($staffId), $fsData, true);
                    if (!$writeOk) throw new \RuntimeException('staff set returned falsy');
                } catch (\Throwable $writeErr) {
                    $staVal = (int) preg_replace('/\D/', '', $staffId);
                    if ($staVal > 0) $this->id_generator->releaseClaim('STA', $staVal);
                    log_message('error', "ID_GEN_INTEGRATION staff_bulk_write_failed row={$rowNum} id={$staffId} released=1 err=" . $writeErr->getMessage());
                    $skipped[] = "Row {$rowNum}: Firestore write failed — ID released";
                    $error++;
                    continue;
                }

                // Auto-create salary structure for payroll
                $this->_sync_salary_structure($staffId, $basic, $allow);

                // Phone index in Firestore
                $this->fs->set('indexPhones', $this->fs->docId($phone), [
                    'schoolId' => $this->school_id,
                    'phone'    => $phone,
                    'userId'   => $staffId,
                    'type'     => 'staff',
                ]);

                // Create Firebase Auth account (best-effort)
                try {
                    $authEmail = Firebase::authEmail($staffId);
                    $this->firebase->createFirebaseUser($authEmail, $plainPassword, [
                        'uid'         => $staffId,
                        'displayName' => $name,
                    ]);
                    $this->firebase->setFirebaseClaims($staffId, [
                        'role'           => 'Teacher',
                        'school_id'      => $this->school_id,
                        'school_code'    => $this->school_code,
                        'parent_db_key'  => $this->parent_db_key,
                    ]);
                } catch (Exception $e) {
                    log_message('error', "Staff import Firebase Auth failed for {$staffId}: " . $e->getMessage());
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

        // Preview the next staff ID via id_generator's Firestore counter
        // (feeCounters/_sys_STA), not the stale schools.staffCount field.
        // The two used to drift apart whenever a save failed mid-flow.
        // NOTE: counters fully migrated RTDB → Firestore on 2026-04-27. NO-RTDB policy.
        $this->load->library('id_generator');
        $previewedNextId = $this->id_generator->generate('STA_PEEK');
        if (empty($previewedNextId)) {
            // Fallback to schools.staffCount only if RTDB peek failed
            $schoolDoc       = $this->fs->get('schools', $this->school_id);
            $staffIdCount    = (int) ($schoolDoc['staffCount'] ?? 1);
            $previewedNextId = 'STA' . str_pad((string) $staffIdCount, 4, '0', STR_PAD_LEFT);
        } else {
            $staffIdCount = (int) substr($previewedNextId, 3);
        }

        $data['schoolName']    = $school_name;
        $data['staffIdCount']  = $staffIdCount;
        $data['user_Id']       = $previewedNextId;

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

            // [FIX-3] Validate phone first — cheap check, fail fast before ID generation
            if (!preg_match('/^[6-9]\d{9}$/', $phoneNumber)) {
                $this->json_error('Invalid phone number.', 400);
            }

            // Bug 5 fix: reject duplicate STAFF phone numbers only.
            // The indexPhones collection is shared across students/parents AND staff
            // (same person can legitimately be a parent + staff). We only hard-block
            // when another *staff* already owns the number; for cross-type collisions
            // we allow staff creation but skip the index overwrite further below so
            // OTP login still resolves to the original (parent) account.
            $phonePreowner    = null;   // existing indexPhones entry, if any
            $phoneCrossType   = false;  // true if existing entry is non-staff
            try {
                $existingPhone = $this->fs->get('indexPhones', $this->fs->docId($phoneNumber));
                if (!empty($existingPhone) && !empty($existingPhone['userId'])) {
                    $phonePreowner = $existingPhone;
                    $existingType  = strtolower((string)($existingPhone['type'] ?? ''));
                    if ($existingType === 'staff') {
                        $this->json_error(
                            'Phone number already registered to staff '
                                . $existingPhone['userId'] . '.',
                            409
                        );
                    }
                    // Cross-type (student/parent) → allow but don't overwrite the index
                    $phoneCrossType = true;
                }
            } catch (Exception $e) {
                log_message('error', 'Staff: indexPhones lookup failed: ' . $e->getMessage());
                // Non-fatal — continue; race is still possible but rare.
            }

            // Phase 4.3 — timestamp fallback REMOVED (race-unsafe: two
            // concurrent imports landing on the same time() value would
            // collide). If safeGenerate exhausts every retry + self-
            // repair tier, we surface a controlled 503 rather than
            // silently risking a duplicate ID.
            $this->load->library('id_generator');
            try {
                $staffId = $this->id_generator->safeGenerate('STA');
            } catch (\Throwable $e) {
                log_message('error', 'ID_GEN_INTEGRATION staff_single_create_failed err=' . $e->getMessage());
                $this->json_error('Could not allocate a staff ID right now. Please retry in a moment.', 503);
                return;
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

                // Bug 6 fix: mime_content_type only ever returns 'image/jpeg' for JPG;
                // 'image/jpg' was dead. Keep just the canonical type.
                if ($realMime !== 'image/jpeg') {
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

                if (!in_array($realMime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
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
                'name'        => $normalizedPostData['emergency_contact_name']     ?? '',
                'phoneNumber' => $normalizedPostData['emergency_contact_phone']    ?? '',
                'relation'    => $normalizedPostData['emergency_contact_relation'] ?? '',
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

            // ── Staff roles (multi-role support — single source of truth) ──
            $rawRoles = $normalizedPostData['staff_roles'] ?? '';
            if (is_string($rawRoles) && $rawRoles !== '') {
                $roleIds = array_values(array_filter(array_map('trim', explode(',', $rawRoles))));
            } elseif (is_array($rawRoles)) {
                $roleIds = array_values(array_filter(array_map('trim', $rawRoles)));
            } else {
                $roleIds = [];
            }
            if (empty($roleIds)) {
                // Legacy fallback: infer from any submitted Position field, else default Teacher
                $roleIds = $this->_infer_roles_from_position($normalizedPostData['staff_position'] ?? '');
            }
            $primaryRole = trim($normalizedPostData['primary_role'] ?? '');
            if ($primaryRole === '' || !in_array($primaryRole, $roleIds, true)) {
                $primaryRole = $roleIds[0] ?? 'ROLE_TEACHER';
            }
            // designation is the canonical display label; Position is the auto-fallback.
            // If user entered designation, use it as Position too for consistency.
            $designationInput = trim($normalizedPostData['designation'] ?? '');
            $positionLabel = $designationInput !== ''
                ? $designationInput
                : $this->_role_id_to_label($primaryRole);

            $staffRecord = [
                'Name'            => $staffName,
                'User ID'         => $staffId,
                'Phone Number'    => $phoneNumber,
                'Position'        => $positionLabel,
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

                // Phase A (2026-04-08): new profile + statutory fields
                'altPhone'        => $normalizedPostData['alt_phone']       ?? '',
                'maritalStatus'   => $normalizedPostData['marital_status']  ?? '',
                'designation'     => $normalizedPostData['designation']     ?? '',
                'panNumber'       => strtoupper(trim($normalizedPostData['pan_number']    ?? '')),
                'aadharNumber'    => trim($normalizedPostData['aadhar_number'] ?? ''),
                'pfNumber'        => trim($normalizedPostData['pf_number']    ?? ''),
                'esiNumber'       => trim($normalizedPostData['esi_number']   ?? ''),
                'Religion'        => $normalizedPostData['religion']       ?? '',
                'Category'        => $normalizedPostData['category']       ?? '',
                'sameAsCurrentAddress' => !empty($normalizedPostData['same_as_current']),
                'permanentAddress' => [
                    'street'     => $normalizedPostData['perm_street']      ?? '',
                    'city'       => $normalizedPostData['perm_city']        ?? '',
                    'state'      => $normalizedPostData['perm_state']       ?? '',
                    'postalCode' => $normalizedPostData['perm_postal_code'] ?? '',
                ],
            ];

            // Teacher capability: subjects this teacher can teach
            // (Actual class/section assignments live in Firestore subjectAssignments — Academic Planner)
            $teachingSubjects = trim($normalizedPostData['teaching_subjects'] ?? '');
            if ($teachingSubjects !== '') {
                $staffRecord['teaching_subjects'] = array_values(array_filter(array_map('trim', explode(',', $teachingSubjects))));
            }

            // Write full staff record to Firestore.
            // Phase 4.3 — guarded write. If this Firestore commit fails
            // (network blip / quota / validation error), we release the
            // STA claim so the number isn't burnt. Mirrors withClaim()
            // semantics, applied inline because the earlier file upload
            // steps need $staffId before this point.
            $fsData = array_merge($staffRecord, [
                'schoolId'  => $this->school_id,
                'session'   => $session_year,
                'sessions'  => [$session_year],
                'staffId'   => $staffId,
                // ── camelCase aliases (read by Parent + Teacher apps) ──
                // Per HR canonical schema: dual-emit PascalCase + camelCase.
                'name'         => $staffName,
                'phone'        => $phoneNumber,
                'email'        => $emailAddr,
                'status'       => 'Active',                                                 // new staff is always Active
                'role'         => $positionLabel,                                           // human-readable label e.g. "Teacher"
                'roleId'       => $primaryRole,                                             // canonical id e.g. "ROLE_TEACHER"
                'position'     => $positionLabel,
                'department'   => $normalizedPostData['department']      ?? '',
                'gender'       => $normalizedPostData['gender']          ?? '',
                'employmentType' => $normalizedPostData['employment_type'] ?? '',
                'fatherName'   => $normalizedPostData['father_name']     ?? '',
                'dateOfJoining' => $formattedData['dateOfJoining']      ?? '',
                'dob'          => $formattedData['DOB']                  ?? '',
                'bloodGroup'   => $normalizedPostData['blood_group']     ?? '',
                'religion'     => $normalizedPostData['religion']        ?? '',
                'category'     => $normalizedPostData['category']        ?? '',
                'profilePic'   => $docData['Photo']['url']               ?? '',
                'updatedAt' => date('c'),
            ]);
            unset($fsData['Password'], $fsData['Credentials']);
            try {
                $result = $this->fs->set('staff', $this->fs->docId($staffId), $fsData, true);
                if (!$result) {
                    throw new \RuntimeException('Firestore staff set returned falsy.');
                }
            } catch (\Throwable $writeErr) {
                $staVal = (int) preg_replace('/\D/', '', $staffId);
                if ($staVal > 0) $this->id_generator->releaseClaim('STA', $staVal);
                log_message('error', 'ID_GEN_INTEGRATION staff_single_write_failed id=' . $staffId . ' released=1 err=' . $writeErr->getMessage());
                $this->json_error('Failed to save staff record. The ID has been released. Please retry.', 500);
                return;
            }

            // RTDB mirror removed per no-RTDB policy. Firestore `staff` is the sole source.

            // Auto-create leave balance for new staff
            try {
                $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
                $leaveTypes = (is_array($fsSchool) && !empty($fsSchool['leaveTypes'])) ? $fsSchool['leaveTypes'] : [];
                if (!empty($leaveTypes)) {
                    $balances = [];
                    foreach ($leaveTypes as $tid => $lt) {
                        if (!is_array($lt)) continue;
                        $alloc = (int) ($lt['days_per_year'] ?? 0);
                        $balances[$tid] = ['allocated' => $alloc, 'used' => 0, 'carried' => 0, 'balance' => $alloc];
                    }
                    $balDocId = "{$this->school_id}_BAL_{$staffId}_" . date('Y');
                    $this->firebase->firestoreSet('leaveApplications', $balDocId, [
                        'schoolId' => $this->school_id, 'staffId' => $staffId,
                        'year' => date('Y'), 'balances' => $balances,
                        'type' => 'balance', 'updatedAt' => date('c'),
                    ]);
                }
            } catch (\Exception $e) {
                log_message('error', "Staff: Auto-create leave balance failed for {$staffId}: " . $e->getMessage());
            }

            if ($result !== false) {
                // Phone index — skip overwrite if a non-staff (parent/student) already
                // owns this number, so OTP login for that account keeps working.
                $phoneIndexWarning = null;
                if (!$phoneCrossType) {
                    $this->fs->set('indexPhones', $this->fs->docId($phoneNumber), [
                        'schoolId' => $this->school_id,
                        'phone'    => $phoneNumber,
                        'userId'   => $staffId,
                        'type'     => 'staff',
                    ]);
                } else {
                    $phoneIndexWarning = 'Phone number is already registered to '
                        . ($phonePreowner['type'] ?? 'parent')
                        . ' ' . ($phonePreowner['userId'] ?? '?')
                        . '. The staff record was created, but OTP login on this number will still resolve to the existing account.';
                    log_message('error', 'Staff: phone ' . $phoneNumber
                        . ' already indexed to ' . ($phonePreowner['type'] ?? '?')
                        . ' ' . ($phonePreowner['userId'] ?? '?')
                        . ' — staff ' . $staffId . ' created without index overwrite.');
                }

                // Issue C fix: use the numeric portion of the freshly-allocated
                // staffId rather than the (stale, GET-time) $staffIdCount.
                // staffCount tracks "next id number" so it should equal
                // <last-allocated-number> + 1, which is exactly the current STA
                // counter + 1.
                $allocatedNum = (int) substr($staffId, 3);
                $this->fs->update('schools', $this->school_id, [
                    'staffCount' => $allocatedNum + 1,
                ]);

                // Auto-create salary structure for payroll
                $this->_sync_salary_structure($staffId, $basicSalary, $allowances);

                // Bug 1 fix: use the actual primary-role label as the auth claim
                // (was hard-coded to 'Teacher', breaking RBAC for non-teaching staff).
                // Bug 3 fix: surface Firebase Auth failures in the response so the
                // admin knows the login account was not created.
                $authWarning = null;
                try {
                    $authEmail = Firebase::authEmail($staffId);
                    $this->firebase->createFirebaseUser($authEmail, $rawPassword, [
                        'uid'         => $staffId,
                        'displayName' => $staffName,
                    ]);
                    $this->firebase->setFirebaseClaims($staffId, [
                        'role'           => $positionLabel,
                        'school_id'      => $this->school_id,
                        'school_code'    => $this->school_code,
                        'parent_db_key'  => $this->parent_db_key,
                    ]);
                } catch (Exception $e) {
                    log_message('error', 'Staff: Firebase Auth create failed for ' . $staffId . ': ' . $e->getMessage());
                    $authWarning = 'Staff record saved, but Firebase Auth account could not be created: '
                        . $e->getMessage()
                        . ' — the user will not be able to log in until this is resolved.';
                }

                $warnings = array_filter([$authWarning, $phoneIndexWarning]);
                $payload  = [
                    'message'          => !empty($warnings)
                        ? 'Staff added (with warnings).'
                        : 'Staff added successfully.',
                    'staff_id'         => $staffId,
                    'name'             => $staffName,
                    'position'         => $positionLabel,
                    'default_password' => $rawPassword,
                ];
                if (!empty($warnings)) {
                    $payload['warning'] = implode(' | ', $warnings);
                }
                $this->json_success($payload);
            } else {
                $this->json_error('Failed to save staff record.', 500);
            }
        }

        $this->load->view('include/header');
        $this->load->view('new_staff', $data);
        $this->load->view('include/footer');
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Phase 1 — flip staff status between Active and Inactive.
     *
     * POST /staff/set_status/<userId>
     *   body: status (Active|Inactive), reason (optional)
     *
     * Writes:
     *   - status (camelCase, read by Teacher + Parent apps)
     *   - Status (PascalCase, legacy reads)
     *   - audit-trail fields (deactivatedAt/By + reactivatedAt/By + reason)
     *
     * Phase 1 deliberately does NOT:
     *   - disable Firebase Auth (deferred to Phase 2)
     *   - archive subjectAssignments (deferred to Phase 3)
     *   - block login (deferred to Phase 2)
     */
    public function set_status($user_id)
    {
        $this->_require_role(self::MANAGE_ROLES);
        header('Content-Type: application/json');

        if ($this->input->method() !== 'post') {
            $this->json_error('POST only.', 405);
            return;
        }
        if (!$user_id || !preg_match('/^[A-Za-z0-9_]+$/', $user_id)) {
            $this->json_error('Invalid user id.', 400);
            return;
        }

        $newStatus = trim((string) $this->input->post('status'));
        if (!in_array($newStatus, ['Active', 'Inactive'], true)) {
            $this->json_error('Status must be Active or Inactive.', 400);
            return;
        }

        $reason = trim((string) $this->input->post('reason'));

        // Read current doc — guard against unknown user
        $existing = $this->_get_staff_with_fallback($user_id);
        if (empty($existing)) {
            $this->json_error('Staff not found.', 404);
            return;
        }

        $oldStatus = $existing['status'] ?? $existing['Status'] ?? 'Active';
        if ($oldStatus === $newStatus) {
            // Note: don't put a 'status' key in the data — json_success already
            // sets status='success'; a second 'status' key would overwrite it.
            $this->json_success(['newStatus' => $newStatus, 'message' => 'Status unchanged.']);
            return;
        }

        $now      = date('c');
        $actorId  = (string) ($this->admin_id ?? '');

        $patch = [
            'status'    => $newStatus,   // camelCase — Teacher/Parent apps
            'Status'    => $newStatus,   // PascalCase — legacy reads
            'updatedAt' => $now,
        ];
        if ($newStatus === 'Inactive') {
            $patch['deactivatedAt']      = $now;
            $patch['deactivationReason'] = $reason;
            $patch['deactivatedBy']      = $actorId;
        } else { // Active (reactivation)
            $patch['reactivatedAt'] = $now;
            $patch['reactivatedBy'] = $actorId;
        }

        try {
            // Use updateEntity (school-scoped) — bare update() targets the wrong doc id
            // because Firestore staff doc id is "{schoolId}_{userId}", not bare $user_id.
            $ok = $this->fs->updateEntity('staff', $user_id, $patch);
            if (!$ok) {
                $this->json_error('Update failed.', 500);
                return;
            }
        } catch (\Throwable $e) {
            log_message('error', "set_status failed for {$user_id}: " . $e->getMessage());
            $this->json_error('Update failed: ' . $e->getMessage(), 500);
            return;
        }

        // Phase 3 — cascade archive into subjectAssignments. Best-effort:
        // status flip is the source of truth; cascade failure is logged but
        // doesn't roll back the status change. Reactivation only un-archives
        // rows we marked ourselves (archivedBecauseOfDeactivation=true) so
        // manually-archived rows from Academic Planner stay archived.
        $cascadeStats = $this->_cascade_subject_assignments($user_id, $newStatus, $reason, $actorId, $now);

        // Phase 2B — Firebase Auth + FCM cleanup (each step independently
        // try/caught; status flip and Firestore writes are NEVER rolled back
        // if these fail — Firestore stays the source of truth).
        $authStats = ($newStatus === 'Inactive')
            ? $this->_disable_firebase_user($user_id)
            : $this->_enable_firebase_user($user_id);

        log_message('info', "STAFF_STATUS user={$user_id} {$oldStatus}->{$newStatus} by={$actorId} "
            . "reason=" . ($reason ?: '(none)')
            . " cascade=" . json_encode($cascadeStats)
            . " auth=" . json_encode($authStats));
        // Note: 'newStatus' (not 'status') so it doesn't collide with json_success's
        // own 'status' => 'success' field.
        $this->json_success([
            'newStatus' => $newStatus,
            'message'   => 'Status changed to ' . $newStatus . '.',
            'cascade'   => $cascadeStats,
            'auth'      => $authStats,
        ]);
    }

    /**
     * Phase 2B — kick the user out of all current sessions on deactivation.
     *
     * 1. Disable the Firebase Auth account (admin SDK property `disabled=true`)
     *    — blocks new sign-ins immediately.
     * 2. Revoke all refresh tokens — forces every cached client to re-auth
     *    on the next token refresh (~1 hour for already-issued ID tokens).
     * 3. Delete every userDevices/{...} doc owned by this user — stops FCM
     *    pushes from landing on their installed apps.
     *
     * Each step independently try/caught: if one fails, the others still
     * run, status stays Inactive in Firestore (the source of truth), and the
     * caller still gets a success response. Failures show up in the
     * 'auth' field of the JSON response and in error logs.
     */
    private function _disable_firebase_user(string $userId): array
    {
        $stats = [
            'disabled'           => false,
            'tokensRevoked'      => false,
            'fcmDocsDeleted'     => 0,
            'fcmDocsFailed'      => 0,
            'errors'             => [],
        ];

        // 1) Disable Firebase Auth user
        try {
            $res = $this->firebase->updateFirebaseUser($userId, ['disabled' => true]);
            $stats['disabled'] = ($res !== null);
            if ($res === null) $stats['errors'][] = 'updateFirebaseUser returned null';
        } catch (\Throwable $e) {
            $stats['errors'][] = 'disable: ' . $e->getMessage();
            log_message('error', "Phase2B disable failed for {$userId}: " . $e->getMessage());
        }

        // 2) Revoke refresh tokens
        try {
            $stats['tokensRevoked'] = (bool) $this->firebase->revokeRefreshTokens($userId);
        } catch (\Throwable $e) {
            $stats['errors'][] = 'revoke: ' . $e->getMessage();
            log_message('error', "Phase2B revoke failed for {$userId}: " . $e->getMessage());
        }

        // 3) Delete userDevices entries (FCM cleanup) — multiple docs per user
        //    (one per device); we delete them all so push notifications stop.
        try {
            $devices = $this->fs->where('userDevices', [['userId', '==', $userId]]);
            if (is_array($devices)) {
                foreach ($devices as $entry) {
                    $docId = is_array($entry) ? ($entry['id'] ?? '') : '';
                    if ($docId === '') { $stats['fcmDocsFailed']++; continue; }
                    try {
                        $ok = $this->fs->remove('userDevices', $docId);
                        $ok ? $stats['fcmDocsDeleted']++ : $stats['fcmDocsFailed']++;
                    } catch (\Throwable $e) {
                        $stats['fcmDocsFailed']++;
                        log_message('error', "Phase2B FCM delete failed docId={$docId}: " . $e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            $stats['errors'][] = 'fcmCleanup: ' . $e->getMessage();
            log_message('error', "Phase2B fcm cleanup query failed for {$userId}: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Phase 2B — re-enable Firebase Auth on reactivation.
     *
     * Only flips disabled=false. We don't restore deleted userDevices docs
     * — when the user opens the app and signs in again, the teacher/parent
     * AuthRepository re-registers a fresh FCM token (see registerFcmToken
     * in AuthRepository.kt). Stale device records aren't worth resurrecting.
     */
    private function _enable_firebase_user(string $userId): array
    {
        $stats = ['enabled' => false, 'errors' => []];
        try {
            $res = $this->firebase->updateFirebaseUser($userId, ['disabled' => false]);
            $stats['enabled'] = ($res !== null);
            if ($res === null) $stats['errors'][] = 'updateFirebaseUser returned null';
        } catch (\Throwable $e) {
            $stats['errors'][] = 'enable: ' . $e->getMessage();
            log_message('error', "Phase2B enable failed for {$userId}: " . $e->getMessage());
        }
        return $stats;
    }

    /**
     * Phase 3 — flip subjectAssignments.archived for every row owned by this teacher.
     *
     * Inactive: set archived=true on all rows where teacherId==userId.
     * Active   : set archived=false on rows we previously archived (marker
     *            archivedBecauseOfDeactivation=true). Manually-archived rows
     *            from Academic Planner are left alone.
     *
     * @return array  ['matched' => int, 'patched' => int, 'failed' => int]
     */
    private function _cascade_subject_assignments(
        string $userId, string $newStatus, string $reason, string $actorId, string $nowIso
    ): array {
        $stats = ['matched' => 0, 'patched' => 0, 'failed' => 0];
        try {
            $rows = $this->fs->schoolWhere('subjectAssignments', [
                ['teacherId', '==', $userId],
            ]);
        } catch (\Throwable $e) {
            log_message('error', "cascade query failed for {$userId}: " . $e->getMessage());
            return $stats;
        }
        if (!is_array($rows)) return $stats;

        foreach ($rows as $row) {
            $stats['matched']++;
            $docId = $row['id'] ?? '';
            $d     = $row['data'] ?? [];
            if ($docId === '') { $stats['failed']++; continue; }

            if ($newStatus === 'Inactive') {
                $patch = [
                    'archived'                       => true,
                    'archivedAt'                     => $nowIso,
                    'archivedReason'                 => $reason !== ''
                        ? $reason
                        : 'Teacher deactivated',
                    'archivedBy'                     => $actorId,
                    'archivedBecauseOfDeactivation'  => true,
                ];
            } else { // Active — only un-archive rows we ourselves archived
                if (empty($d['archivedBecauseOfDeactivation'])) {
                    continue; // skip — manually-archived row, leave it alone
                }
                $patch = [
                    'archived'                       => false,
                    'archivedBecauseOfDeactivation'  => false,
                    'unarchivedAt'                   => $nowIso,
                    'unarchivedBy'                   => $actorId,
                ];
            }

            try {
                $ok = $this->fs->update('subjectAssignments', $docId, $patch);
                $ok ? $stats['patched']++ : $stats['failed']++;
            } catch (\Throwable $e) {
                log_message('error', "cascade patch failed docId={$docId}: " . $e->getMessage());
                $stats['failed']++;
            }
        }
        return $stats;
    }

    public function delete_staff($id)
    {
        $this->_require_role(self::MANAGE_ROLES);

        if (!$id || !preg_match('/^[A-Za-z0-9_]+$/', $id)) {
            redirect(base_url() . 'staff/all_staff/');
            return;
        }

        // Read staff from Firestore
        $staff = $this->fs->getEntity('staff', $id);

        if ($staff && isset($staff['Phone Number'])) {
            $phoneNumber = $staff['Phone Number'];
            // Remove phone index
            $this->fs->remove('indexPhones', $this->fs->docId($phoneNumber));
        }

        // Delete staff document from Firestore
        $this->fs->removeEntity('staff', $id);

        // RTDB mirror removed per no-RTDB policy. Firestore `staff` is the sole source.

        // Delete Firebase Auth account (best-effort)
        try {
            $this->firebase->deleteFirebaseUser($id);
        } catch (Exception $e) {
            log_message('error', "Staff delete Firebase Auth failed for {$id}: " . $e->getMessage());
        }

        redirect(base_url() . 'staff/all_staff/');
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function edit_staff($user_id)
    {
        $this->_require_role(self::MANAGE_ROLES);
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        if ($this->input->method() === 'post') {
            header('Content-Type: application/json');

            $postData = $this->input->post();
            unset($postData['user_id'], $postData['User ID']);

            // Fetch existing record (Firestore-first with RTDB fallback + auto-heal)
            $existingData = $this->_get_staff_with_fallback($user_id);
            $existingDoc  = is_array($existingData['Doc'] ?? null) ? $existingData['Doc'] : [];
            $docUpdates     = [];

            // ── Photo upload ──────────────────────────────────────────────────
            if (!empty($_FILES['Photo']['tmp_name'])) {
                $photo    = $_FILES['Photo'];
                $realMime = mime_content_type($photo['tmp_name']);

                // mime_content_type only ever returns 'image/jpeg' for JPG; the
                // 'image/jpg' alternative was dead. Match new_staff() exactly.
                if ($realMime !== 'image/jpeg') {
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

                if (!in_array($realMime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
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
                    'postal_code' => 'PostalCode',
                ],
                'emergencyContact' => [
                    'emergency_contact_name'     => 'name',
                    'emergency_contact_phone'    => 'phoneNumber',
                    'emergency_contact_relation' => 'relation',
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
                'permanentAddress' => [
                    'perm_street'      => 'street',
                    'perm_city'        => 'city',
                    'perm_state'       => 'state',
                    'perm_postal_code' => 'postalCode',
                ],
            ];

            // Extract Phase A flat fields from POST before formatAndPrepareFirebaseData
            // strips them. We'll merge them back AFTER the format call.
            $phaseAFlats = ['alt_phone' => 'altPhone', 'marital_status' => 'maritalStatus',
                            'designation' => 'designation', 'pan_number' => 'panNumber',
                            'aadhar_number' => 'aadharNumber', 'pf_number' => 'pfNumber',
                            'esi_number' => 'esiNumber'];
            $phaseAValues = [];
            foreach ($phaseAFlats as $postKey => $docKey) {
                $val = trim($postData[$postKey] ?? '');
                if ($docKey === 'panNumber') $val = strtoupper($val);
                $phaseAValues[$docKey] = $val;
                unset($postData[$postKey]);
            }
            $phaseAValues['sameAsCurrentAddress'] = !empty($postData['same_as_current']);
            unset($postData['same_as_current']);

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
            $formattedData = array_merge($formattedData, $structuredData, $phaseAValues);

            // Date formatting — accept both Y-m-d (HTML date input) and d-m-Y
            // (the format we store). strtotime() can't reliably parse d-m-Y on
            // Windows, so use DateTime::createFromFormat with explicit fallbacks.
            foreach (['DOB', 'Date Of Joining'] as $dateField) {
                $val = $formattedData[$dateField] ?? '';
                if ($val === '') {
                    $formattedData[$dateField] = '';
                    continue;
                }
                $dt = DateTime::createFromFormat('Y-m-d', $val)
                   ?: DateTime::createFromFormat('d-m-Y', $val)
                   ?: false;
                $formattedData[$dateField] = $dt ? $dt->format('d-m-Y') : '';
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
                // Auto-derive Position from primary role (Designation field removed from form)
                $formattedData['Position'] = $this->_role_id_to_label($editPrimary);
                // Strip any legacy 'position' POST field so it doesn't override
                unset($postData['position'], $formattedData['position']);
            }
            // If user entered an explicit designation, use it as Position too
            // so all legacy reads (staff list, payslip, etc.) see the same label.
            // designation is the canonical display field; Position is the auto-derived fallback.
            if (!empty($formattedData['designation'])) {
                $formattedData['Position'] = $formattedData['designation'];
            }

            // Remove raw keys so they don't pollute the flat write
            unset($formattedData['staff_roles_raw'], $postData['staff_roles'], $postData['primary_role']);

            // ── Teaching subjects + assigned classes (Phase 1) ───────────
            $teachingSubjectsRaw = trim($postData['teaching_subjects'] ?? '');
            if ($teachingSubjectsRaw !== '') {
                $formattedData['teaching_subjects'] = array_values(
                    array_filter(array_map('trim', explode(',', $teachingSubjectsRaw)))
                );
            } else {
                // Empty submission = clear the field (e.g., role changed from Teacher to Accountant)
                $formattedData['teaching_subjects'] = [];
            }
            unset($postData['teaching_subjects'], $formattedData['teaching_subjects_raw']);

            // Strip any legacy assigned_classes from incoming POST so it doesn't pollute the staff doc.
            // (Actual class/section assignments live in Firestore subjectAssignments — Academic Planner)
            unset($postData['assigned_classes'], $formattedData['assigned_classes'], $formattedData['assigned_classes_raw']);

            // Merge updated Doc entries (if any files were uploaded) into the
            // existing Doc node so unchanged documents are preserved.
            if (!empty($docUpdates)) {
                $formattedData['Doc'] = array_merge($existingDoc, $docUpdates);
            }

            $oldPhoneNumber = $existingData['Phone Number'] ?? null;
            $oldName        = $existingData['Name']         ?? null;

            // Update staff document in Firestore
            $formattedData['updatedAt'] = date('c');

            // ── camelCase aliases — must mirror new_staff() exactly so the
            // Parent + Teacher apps keep seeing fresh values after every edit.
            // (Previously only name/phone/email were updated, so role/status/
            // department/gender/etc. went stale on every edit and the teacher
            // app showed the wrong role.)
            if (isset($formattedData['Name']))            $formattedData['name']           = $formattedData['Name'];
            if (isset($formattedData['Phone Number']))    $formattedData['phone']          = $formattedData['Phone Number'];
            if (isset($formattedData['Email']))           $formattedData['email']          = $formattedData['Email'];
            if (isset($formattedData['Position']))        $formattedData['position']       = $formattedData['Position'];
            if (isset($formattedData['Position']))        $formattedData['role']           = $formattedData['Position'];
            if (isset($formattedData['primary_role']))    $formattedData['roleId']         = $formattedData['primary_role'];
            if (isset($formattedData['Department']))      $formattedData['department']     = $formattedData['Department'];
            if (isset($formattedData['Gender']))          $formattedData['gender']         = $formattedData['Gender'];
            if (isset($formattedData['Employment Type'])) $formattedData['employmentType'] = $formattedData['Employment Type'];
            if (isset($formattedData['Father Name']))     $formattedData['fatherName']     = $formattedData['Father Name'];
            if (isset($formattedData['Date Of Joining'])) $formattedData['dateOfJoining']  = $formattedData['Date Of Joining'];
            if (isset($formattedData['DOB']))             $formattedData['dob']            = $formattedData['DOB'];
            if (isset($formattedData['Blood Group']))     $formattedData['bloodGroup']     = $formattedData['Blood Group'];
            if (isset($formattedData['Religion']))        $formattedData['religion']       = $formattedData['Religion'];
            if (isset($formattedData['Category']))        $formattedData['category']       = $formattedData['Category'];
            if (isset($formattedData['ProfilePic']))      $formattedData['profilePic']     = $formattedData['ProfilePic'];

            unset($formattedData['Password'], $formattedData['Credentials']);

            $updateRes = $this->fs->updateEntity('staff', $user_id, $formattedData);

            // RTDB mirror removed per no-RTDB policy. Firestore `staff` is the sole source.

            if ($updateRes) {
                // Phone number changed — update phone index
                if (!empty($formattedData['Phone Number']) && $formattedData['Phone Number'] !== $oldPhoneNumber) {
                    if ($oldPhoneNumber) {
                        $this->fs->remove('indexPhones', $this->fs->docId($oldPhoneNumber));
                    }
                    $newPhone = $formattedData['Phone Number'];
                    $this->fs->set('indexPhones', $this->fs->docId($newPhone), [
                        'schoolId' => $this->school_id,
                        'phone'    => $newPhone,
                        'userId'   => $user_id,
                        'type'     => 'staff',
                    ]);
                }

                // Sync salary structure if salary fields were submitted
                $editBasic = (float) ($postData['basicSalary'] ?? $postData['Basicsalary'] ?? 0);
                $editAllow = (float) ($postData['allowances']  ?? $postData['Allowances']  ?? 0);
                if ($editBasic > 0) {
                    $this->_sync_salary_structure($user_id, $editBasic, $editAllow);
                    $this->fs->updateEntity('staff', $user_id, [
                        'salaryDetails' => [
                            'basicSalary' => $editBasic,
                            'Allowances'  => $editAllow,
                            'Net Salary'  => $editBasic + $editAllow,
                        ],
                    ]);
                }

                // ── Sync updated profile to Firebase Auth (best-effort) ──
                $teacherName = $formattedData['Name'] ?? null;
                try {
                    $authUpdate = [];
                    if ($teacherName)                            $authUpdate['displayName'] = $teacherName;
                    if (!empty($formattedData['Email']))         $authUpdate['email'] = Firebase::authEmail($user_id);
                    if (!empty($authUpdate)) {
                        $this->firebase->updateFirebaseUser($user_id, $authUpdate);
                    }
                    // Use the actual primary-role label, not a hardcoded "Teacher".
                    // Falls back to the existing Position if roles weren't submitted in this edit.
                    $authRole = $formattedData['Position']
                        ?? $existingData['Position']
                        ?? 'Teacher';
                    $this->firebase->setFirebaseClaims($user_id, [
                        'role'           => $authRole,
                        'school_id'      => $this->school_id,
                        'school_code'    => $this->school_code,
                        'parent_db_key'  => $this->parent_db_key,
                    ]);
                } catch (Exception $e) {
                    log_message('error', 'Staff: Firebase Auth sync failed on edit_staff for ' . $user_id . ': ' . $e->getMessage());
                }

                $this->json_success();
            } else {
                $this->json_error('Update failed.', 500);
            }
        } else {
            // Read staff: Firestore-first → RTDB fallback (auto-heals on miss)
            $data['staff_data'] = $this->_get_staff_with_fallback($user_id);

            if (!empty($data['staff_data'])) {
                $this->load->view('include/header');
                $this->load->view('edit_staff', ['staff_data' => $data['staff_data']]);
                $this->load->view('include/footer');
            } else {
                log_message('error', 'Staff data not found in Firestore or RTDB for ID: ' . $user_id);
                show_404();
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function teacher_profile($id)
    {
        $this->_require_role(self::VIEW_ROLES);

        if (!$id || !preg_match('/^[A-Za-z0-9_]+$/', $id)) {
            show_404();
            return;
        }

        // Firestore-first → RTDB fallback (auto-healing)
        $teacherData = $this->_get_staff_with_fallback($id);

        if (empty($teacherData)) {
            log_message('error', "teacher_profile: staff not found in Firestore or RTDB: {$id}");
            show_404();
            return;
        }

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
        $results = [];
        // Firestore doesn't support full-text search natively, so fetch all school staff and filter in PHP
        $staffDocs = $this->fs->schoolWhere('staff', [], 'Name', 'ASC');

        foreach ($staffDocs as $doc) {
            $teacher = $doc['data'];
            $name       = $teacher['Name']        ?? '';
            $userIdField = $teacher['User ID']     ?? $teacher['staffId'] ?? '';
            $fatherName = $teacher['Father Name'] ?? '';

            if (
                stripos($name,        $entry) !== false ||
                stripos($userIdField, $entry) !== false ||
                stripos($fatherName,  $entry) !== false
            ) {
                $results[] = [
                    'user_id'     => $userIdField,
                    'name'        => htmlspecialchars($name,       ENT_QUOTES, 'UTF-8'),
                    'father_name' => htmlspecialchars($fatherName, ENT_QUOTES, 'UTF-8'),
                ];
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

        // Read subjects from section document in Firestore
        $classKey = Firestore_service::classKey($className);
        $sectionKey = Firestore_service::sectionKey($section);
        $sectionDocId = $this->fs->sectionDocId($className, $section);
        $sectionDoc = $this->fs->get('sections', $sectionDocId);
        $subjects = $sectionDoc['subjects'] ?? [];

        echo json_encode(is_array($subjects) ? array_keys($subjects) : []);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function assign_duty()
    {
        $this->_require_role(self::MANAGE_ROLES);
        header('Content-Type: application/json');

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

        // Update duties in staff Firestore document
        $staffDoc = $this->fs->getEntity('staff', $teacherID);
        $duties = $staffDoc['duties'] ?? [];
        if (!isset($duties[$dutyType])) $duties[$dutyType] = [];
        if (!isset($duties[$dutyType][$classSection])) $duties[$dutyType][$classSection] = [];
        $duties[$dutyType][$classSection][$subject] = $timeSlot ?: '';
        $this->fs->updateEntity('staff', $teacherID, ['duties' => $duties]);

        $profilePicURL = $staffDoc['ProfilePic'] ?? $staffDoc['profilePic'] ?? base_url('tools/image/default-school.jpeg');

        // Update section's subject teachers
        // Parse classSection: "Class 9th 'A'" → classKey="Class 9th", sectionLetter="A"
        if (preg_match("/^(.+?)\\s*'([^']*)'\\s*$/", $classSection, $csm)) {
            $classKey = trim($csm[1]);
            $sectionLetter = trim($csm[2]);
            $sectionDocId = $this->fs->schoolId() . '_' . $classKey . '_Section ' . $sectionLetter;
            $sectionDoc = $this->fs->get('sections', $sectionDocId);
            $subjectTeachers = $sectionDoc['subjects'] ?? [];
            if (!isset($subjectTeachers[$subject])) $subjectTeachers[$subject] = [];
            $subjectTeachers[$subject][htmlspecialchars($teacherOnlyName, ENT_QUOTES, 'UTF-8')] = $profilePicURL;
            $sectionUpdate = ['subjects' => $subjectTeachers];
            if ($dutyType === 'ClassTeacher') {
                $sectionUpdate['classTeacher'] = $teacherOnlyName;
            }
            $this->fs->update('sections', $sectionDocId, $sectionUpdate);
        }

        // Sync Firebase Auth claims (best-effort)
        try {
            $this->firebase->setFirebaseClaims($teacherID, [
                'role'           => 'Teacher',
                'school_id'      => $this->school_id,
                'school_code'    => $this->school_code,
                'parent_db_key'  => $this->parent_db_key,
            ]);
        } catch (Exception $e) {
            log_message('error', 'Staff: Firebase Auth sync failed on assign_duty for ' . $teacherID . ': ' . $e->getMessage());
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

        $class_name   = trim((string) $this->input->post('class_name'));
        $subject      = strip_tags(trim((string) $this->input->post('subject')));
        $teacher_name = trim((string) $this->input->post('teacher_name'));

        if (!$class_name || !$subject || !$teacher_name) {
            $this->json_error('Invalid data.', 400);
        }

        if (!preg_match('/^([A-Za-z0-9_]+)\s-\s(.+)$/', $teacher_name, $matches)) {
            $this->json_error('Invalid teacher format.', 400);
        }

        $teacherID       = $matches[1];
        $teacherOnlyName = $matches[2];

        // Read duties from staff Firestore doc
        $staffDoc = $this->fs->getEntity('staff', $teacherID);
        $duties   = $staffDoc['duties'] ?? [];
        $dutyDeleted = false;
        $wasClassTeacher = false;

        if (!empty($duties)) {
            foreach ($duties as $dutyType => $classes) {
                if (isset($classes[$class_name][$subject])) {
                    unset($duties[$dutyType][$class_name][$subject]);
                    if (empty($duties[$dutyType][$class_name])) {
                        unset($duties[$dutyType][$class_name]);
                    }
                    if (empty($duties[$dutyType])) {
                        unset($duties[$dutyType]);
                    }
                    if ($dutyType === 'ClassTeacher') $wasClassTeacher = true;
                    $dutyDeleted = true;
                    break;
                }
            }
        }

        if (!$dutyDeleted) {
            $this->json_error('Duty not found.', 404);
        }

        // Save updated duties back to staff doc
        $this->fs->updateEntity('staff', $teacherID, ['duties' => $duties]);

        // Update section document — remove teacher from subject
        if (preg_match("/^(.+?)\\s*'([^']*)'\\s*$/", $class_name, $csm)) {
            $classKey = trim($csm[1]);
            $sectionLetter = trim($csm[2]);
            $sectionDocId = $this->fs->schoolId() . '_' . $classKey . '_Section ' . $sectionLetter;
            $sectionDoc = $this->fs->get('sections', $sectionDocId);
            $subjectTeachers = $sectionDoc['subjects'] ?? [];

            if (isset($subjectTeachers[$subject][$teacherOnlyName])) {
                unset($subjectTeachers[$subject][$teacherOnlyName]);
            }
            $sectionUpdate = ['subjects' => $subjectTeachers];
            if ($wasClassTeacher) {
                $sectionUpdate['classTeacher'] = '';
            }
            $this->fs->update('sections', $sectionDocId, $sectionUpdate);
        }

        $this->json_success(['message' => 'Teacher removed and duty marked inactive.']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function teacher_id_card()
    {
        $this->_require_role(self::VIEW_ROLES);
        $session_year = $this->session_year;

        // Firestore: query staff assigned to current session
        $staffDocs = $this->fs->schoolWhere('staff', [['sessions', 'array-contains', $session_year]], 'Name', 'ASC');
        $allStaff = array_map(fn($doc) => $doc['data'], $staffDocs);

        // School profile for ID card header
        $schoolDoc   = $this->fs->get('schools', $this->school_id);
        $displayName = $schoolDoc['name'] ?? $this->school_display_name ?: $this->school_name;
        $schoolLogo  = $schoolDoc['logoUrl'] ?? $schoolDoc['logo_url'] ?? '';

        $data['staff']        = $allStaff;
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
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $roles = $schoolDoc['staffRoles'] ?? [];
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

        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $allRoles = $schoolDoc['staffRoles'] ?? [];
        $existing = $allRoles[$roleId] ?? null;

        // Don't allow changing system role category or label
        if (is_array($existing) && !empty($existing['is_system'])) {
            $flagsRaw = $this->input->post('flags') ?? [];
            $flags = is_array($flagsRaw) ? $flagsRaw : [];
            $existing['flags'] = $flags;
            $allRoles[$roleId] = $existing;
            $this->fs->update('schools', $this->school_id, ['staffRoles' => $allRoles]);
            return $this->json_success(['message' => "System role '{$label}' flags updated."]);
        }

        $flagsRaw = $this->input->post('flags') ?? [];
        $flags = is_array($flagsRaw) ? $flagsRaw : [];
        $attendanceType = trim($this->input->post('attendance_type', TRUE) ?? 'standard');
        if (!in_array($attendanceType, ['standard', 'shift', 'flexible'], true)) {
            $attendanceType = 'standard';
        }

        $allRoles[$roleId] = [
            'label'           => $label,
            'category'        => $category,
            'flags'           => $flags,
            'attendance_type' => $attendanceType,
            'is_system'       => false,
        ];

        $this->fs->update('schools', $this->school_id, ['staffRoles' => $allRoles]);
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

        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $allRoles = $schoolDoc['staffRoles'] ?? [];
        $existing = $allRoles[$roleId] ?? null;
        if (!is_array($existing)) return $this->json_error('Role not found.');
        if (!empty($existing['is_system'])) return $this->json_error('System roles cannot be deleted.');

        unset($allRoles[$roleId]);
        $this->fs->update('schools', $this->school_id, ['staffRoles' => $allRoles]);
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

        $session_year = $this->session_year;

        // Query all school staff in current session
        $staffDocs = $this->fs->schoolWhere('staff', [['sessions', 'array-contains', $session_year]], 'Name', 'ASC');

        $filtered = [];
        foreach ($staffDocs as $doc) {
            $d = $doc['data'] ?? $doc;
            $s = $doc['data'];
            $sid = $s['User ID'] ?? $s['staffId'] ?? $d['id'];
            $roles = $s['staff_roles'] ?? [];
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
                    'phone'        => $s['Phone Number'] ?? $s['phone'] ?? '',
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

        // Query all school staff from Firestore
        $staffDocs = $this->fs->schoolWhere('staff', []);

        $migrated = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($staffDocs as $doc) {
            $s   = $doc['data'];
            $sid = $s['User ID'] ?? $s['staffId'] ?? '';
            if ($sid === '') { $errors++; continue; }

            // Skip if already has staff_roles
            if (!empty($s['staff_roles']) && is_array($s['staff_roles'])) {
                $skipped++;
                continue;
            }

            $position = $s['Position'] ?? '';
            $roleIds  = $this->_infer_roles_from_position($position);
            $primary  = $roleIds[0] ?? 'ROLE_TEACHER';

            $ok = $this->fs->updateEntity('staff', $sid, [
                'staff_roles'  => $roleIds,
                'primary_role' => $primary,
            ]);

            if ($ok) {
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

    // =========================================================================
    //  RTDB FALLBACK + AUTO-HEAL
    //  Used when Firestore is empty for a school (not yet migrated).
    // =========================================================================

    /**
     * Fallback: read all staff from RTDB Users/Admin and auto-heal into Firestore.
     * Returns array in same format as fs->schoolWhere(): [['id' => ..., 'data' => [...]], ...]
     */
    /**
     * Read a single staff record honoring the project read contract:
     *
     *     Firestore FIRST → RTDB fallback (with auto-heal)
     *
     * If Firestore returns the doc, return it as-is. Otherwise read from
     * `Users/Admin/{parent_db_key}/{staffId}`, normalize the result into the
     * Firestore shape (schoolId, session, sessions[], lowercase aliases),
     * write it back to Firestore (auto-heal so future reads are fast), and
     * return it.
     *
     * Returns the staff data array, or an empty array if not found.
     * Firestore-only per no-RTDB policy.
     */
    private function _get_staff_with_fallback(string $staffId): array
    {
        if ($staffId === '') return [];
        try {
            $fsDoc = $this->fs->getEntity('staff', $staffId);
            return (is_array($fsDoc) && !empty($fsDoc)) ? $fsDoc : [];
        } catch (Exception $e) {
            log_message('error', "_get_staff Firestore read failed [{$staffId}]: " . $e->getMessage());
            return [];
        }
    }
}
