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

    // ── Audit C1: PII split ────────────────────────────────────────────────
    // The `staff/{schoolId}_{staffId}` doc is SAME-SCHOOL READABLE (parents +
    // students can query it — the Parent app legitimately reads staff for
    // search/PTM). These sensitive fields must NOT ride on that readable doc;
    // they live in the server-only `staffPrivate/{schoolId}_{staffId}` mirror
    // (Firestore rules deny ALL clients; the service account bypasses).
    // Keep this list in lockstep with Hr.php and Staff_pii_migrate.php.
    // Phase 3A.1 — added exitReason/deactivationReason/deactivatedBy/reactivatedBy
    // (separation-metadata privacy closure). Deliberately NOT added:
    // exitDate/lastWorkingDate — Hr.php's payroll final-pay roster
    // (schoolWhere('staff', ...) bulk reads, e.g. Hr.php:6183) reads these two
    // directly off the bulk staff row; moving them would require changing
    // Payroll's read path, which is out of this phase's authorized scope.
    // exitReason/deactivationReason/deactivatedBy/reactivatedBy have zero
    // consumers outside Staff.php (verified) so they move with no cross-module
    // impact — the narrative/actor fields are the sensitive part, not the dates.
    private const PII_KEYS = ['panNumber', 'aadharNumber', 'pfNumber', 'esiNumber', 'salaryDetails', 'salaryStructure', 'bankDetails', 'exitReason', 'deactivationReason', 'deactivatedBy', 'reactivatedBy'];

    // ── Unified role catalogue (single source of truth) ────────────────────
    // ONE role entity now serves BOTH staff (HR: category/flags/attendance_type)
    // AND admin access (RBAC: permissions[]/tier/sort_order). A role is assignable
    // to staff and/or admins. Keyed by ROLE_*; the `label` is the admin-facing
    // identifier carried in the Firebase claim, so labels here MUST match the
    // legacy RBAC role names exactly (Admin, Principal, Teacher, …) or existing
    // admins lose their permissions. Flags = LIST of capability keys.
    //   tier/sort_order → admin-side grouping;  category → staff/payroll grouping.
    private const ALL_MODULES = [
        'SIS','Fees','Accounting','Attendance','Examinations','Results','LMS','Certificates',
        'HR','Events','Communication','Operations','Academic','Reports','Configuration',
        'Admin Users','Stories','Homework',
        // Unified RBAC (2026-07-18): ROLE_ADMIN must equal the FULL RBAC_MODULES set
        // so that once 'Admin' is demoted from RBAC_BYPASS_ROLES it resolves every
        // module via the catalogue (previously Red Flags + the Operations children
        // were missing — a demoted Admin would silently lose them; Ops children were
        // only reachable via the umbrella).
        'Red Flags','Library','Transport','Hostel','Inventory','Assets',
    ];
    /**
     * ROLE-LEVEL `surface` — which LOGIN SURFACE a role admits its holder to:
     * 'app' (staff app only — web panel login REFUSED), 'web', or 'both'.
     * Distinct from RBAC_MODULE_META's module-level surface, which only decides
     * where a module is DISPLAYED. This one is enforced, at Admin_login.
     * ABSENT = web permitted, so every untagged role keeps its existing behaviour.
     * Union rule (rbac_roles_allow_web): a staff member reaches the panel if ANY
     * held role is non-'app' — which is why the auto-assigned ROLE_BASELINE_APP is
     * tagged 'app'; otherwise it alone would re-open the panel to everyone.
     */
    private const DEFAULT_STAFF_ROLES = [
        // ── System roles ─────────────────────────────────────────────────────
        // Global auto-assigned baseline: every staff member (peon → principal)
        // receives it, granting the common app modules at view. is_global keeps it
        // out of the department tree; auto_assign means onboarding always attaches
        // it. Whether its "both"-surface modules also grant PANEL access is settled
        // by the surface-tagging rule in Phase B (baseline is app-facing).
        'ROLE_BASELINE_APP'         => ['label' => 'App Baseline',         'description' => 'Baseline app modules every staff member receives (Stories, Events, Communication).', 'category' => 'System',         'flags' => [], 'attendance_type' => 'standard', 'is_system' => true, 'is_global' => true, 'auto_assign' => true, 'tier' => 9, 'sort_order' => 0, 'surface' => 'app', 'permissions' => ['Stories','Events','Communication'], 'permissionLevels' => ['Stories' => 'view', 'Events' => 'view', 'Communication' => 'view']],
        // ── Access-oriented roles (formerly the RBAC catalogue) ──────────────
        'ROLE_ADMIN'                => ['label' => 'Admin',                'description' => 'Full school-level access, all modules',                 'category' => 'Administrative', 'flags' => [],                                     'attendance_type' => 'standard', 'is_system' => true, 'tier' => 1, 'sort_order' => 1,  'permissions' => self::ALL_MODULES],
        'ROLE_PRINCIPAL'            => ['label' => 'Principal',            'description' => 'Academic oversight, approvals, reports (no accounting)', 'category' => 'Administrative', 'flags' => [],                                     'attendance_type' => 'standard', 'is_system' => true, 'tier' => 2, 'sort_order' => 2,  'permissions' => ['SIS','Attendance','Examinations','Results','LMS','Certificates','Academic','Reports','Events','Communication','Stories','Configuration']],
        'ROLE_VICE_PRINCIPAL'       => ['label' => 'Vice Principal',       'description' => 'Academic oversight, limited approvals (no config)',       'category' => 'Administrative', 'flags' => [],                                     'attendance_type' => 'standard', 'is_system' => true, 'tier' => 2, 'sort_order' => 3,  'permissions' => ['SIS','Attendance','Examinations','Results','LMS','Certificates','Academic','Reports','Events','Communication','Stories']],
        'ROLE_ACADEMIC_COORDINATOR' => ['label' => 'Academic Coordinator', 'description' => 'Classes, exams, results, timetable, homework',           'category' => 'Administrative', 'flags' => [],                                     'attendance_type' => 'standard', 'is_system' => true, 'tier' => 3, 'sort_order' => 4,  'permissions' => ['SIS','Attendance','Examinations','Results','LMS','Academic','Reports','Stories']],
        'ROLE_HR_MANAGER'           => ['label' => 'HR Manager',          'description' => 'Staff, payroll, leaves, recruitment, appraisals',        'category' => 'Administrative', 'flags' => [],                                     'attendance_type' => 'standard', 'is_system' => true, 'tier' => 3, 'sort_order' => 5,  'permissions' => ['HR','Attendance','Reports']],
        'ROLE_ACCOUNTANT'           => ['label' => 'Accountant',          'description' => 'Fees, accounting, ledgers, bank recon, reports',         'category' => 'Administrative', 'flags' => ['can_handle_fees'],                    'attendance_type' => 'standard', 'is_system' => true, 'tier' => 3, 'sort_order' => 6,  'permissions' => ['Fees','Accounting','Reports']],
        'ROLE_FRONT_OFFICE'         => ['label' => 'Front Office',         'description' => 'Admissions CRM, visitor log, communication, certificates','category' => 'Administrative', 'flags' => [],                                     'attendance_type' => 'standard', 'is_system' => true, 'tier' => 4, 'sort_order' => 7,  'permissions' => ['SIS','Communication','Certificates','Events','Stories']],
        // 'Academic' added to both teaching roles (TT-C8, 2026-09-13): each already
        // carries the `can_access_timetable` flag — the declared intent that teachers
        // reach the timetable — but the Teacher App gates the timetable screen on the
        // 'Academic' module (ModuleGate: "timetable"→"Academic"), which was absent, so
        // a freshly onboarded school's teachers could not open it. Granted at VIEW
        // level in Rbac_levels_backfill (teachers see the timetable; coordinators and
        // principals still manage it), following the same convention Stories/Communication use.
        // permissionLevels pins Academic to VIEW inline (not just in the flat
        // permissions[]), because a freshly-seeded role carries no other level
        // entries and the capability CF defaults an un-levelled module to
        // 'manage' — which would give a new-school teacher timetable MANAGE, not
        // view. Only 'Academic' is pinned; every other module keeps exactly the
        // level it resolves to today (unchanged). Existing schools get the same
        // Academic=>view through Rbac_levels_backfill's LEVEL_MAP.
        'ROLE_CLASS_TEACHER'        => ['label' => 'Class Teacher',        'description' => 'Teacher + section reports, parent communication, flags',  'category' => 'Teaching',       'flags' => ['can_teach','can_access_timetable'],   'attendance_type' => 'standard', 'is_system' => true, 'tier' => 4, 'sort_order' => 8,  'permissions' => ['SIS','Attendance','Examinations','Results','LMS','Academic','Stories','Communication','Reports','Events'], 'permissionLevels' => ['Academic' => 'view']],
        'ROLE_TEACHER'              => ['label' => 'Teacher',             'description' => 'Own class attendance, homework, marks, stories, messages','category' => 'Teaching',       'flags' => ['can_teach','can_access_timetable'],   'attendance_type' => 'standard', 'is_system' => true, 'tier' => 4, 'sort_order' => 9,  'permissions' => ['Attendance','Examinations','Results','LMS','Academic','Stories','Communication'], 'permissionLevels' => ['Academic' => 'view']],
        'ROLE_LIBRARIAN'            => ['label' => 'Librarian',           'description' => 'Library module',                                         'category' => 'Non-Teaching',   'flags' => ['can_manage_library'],                 'attendance_type' => 'standard', 'is_system' => true, 'tier' => 5, 'sort_order' => 10, 'permissions' => ['Library']],
        'ROLE_TRANSPORT_MANAGER'    => ['label' => 'Transport Manager',   'description' => 'Transport, routes, vehicles, GPS tracking',              'category' => 'Support',        'flags' => ['can_manage_transport'],               'attendance_type' => 'standard', 'is_system' => true, 'tier' => 5, 'sort_order' => 11, 'surface' => 'both', 'permissions' => ['Transport','Reports']],
        'ROLE_HOSTEL_WARDEN'        => ['label' => 'Hostel Warden',       'description' => 'Hostel allocation, hostel attendance',                   'category' => 'Non-Teaching',   'flags' => ['can_manage_hostel'],                  'attendance_type' => 'standard', 'is_system' => true, 'tier' => 5, 'sort_order' => 12, 'permissions' => ['Hostel']],
        'ROLE_STAFF'                => ['label' => 'Staff',               'description' => 'View-only access, no module permissions',                'category' => 'Support',        'flags' => [],                                     'attendance_type' => 'standard', 'is_system' => true, 'tier' => 6, 'sort_order' => 13, 'permissions' => []],
        // ── Job-oriented roles (formerly staff-only; no default panel access) ─
        'ROLE_LAB_ASST'             => ['label' => 'Lab Assistant',       'description' => 'Laboratory support',                                     'category' => 'Non-Teaching',   'flags' => [],                                     'attendance_type' => 'standard', 'is_system' => true, 'tier' => 6, 'sort_order' => 20, 'permissions' => []],
        'ROLE_CLERK'                => ['label' => 'Clerk',               'description' => 'Office clerical work',                                    'category' => 'Administrative', 'flags' => [],                                     'attendance_type' => 'standard', 'is_system' => true, 'tier' => 6, 'sort_order' => 21, 'permissions' => []],
        'ROLE_DRIVER'               => ['label' => 'Driver',              'description' => 'Transport driver (staff app only)',                      'category' => 'Support',        'flags' => ['can_manage_transport'],               'attendance_type' => 'shift',    'is_system' => true, 'tier' => 6, 'sort_order' => 22, 'surface' => 'app', 'permissions' => []],
        'ROLE_SECURITY'             => ['label' => 'Security',            'description' => 'Campus security / guard (staff app only)',                'category' => 'Support',        'flags' => [],                                     'attendance_type' => 'shift',    'is_system' => true, 'tier' => 6, 'sort_order' => 23, 'surface' => 'app', 'permissions' => []],
        'ROLE_HOUSE_WARDEN'         => ['label' => 'House Warden',        'description' => 'Boarding house supervision',                             'category' => 'Non-Teaching',   'flags' => ['can_manage_hostel'],                  'attendance_type' => 'standard', 'is_system' => false,'tier' => 6, 'sort_order' => 24, 'permissions' => []],
        'ROLE_PEON'                 => ['label' => 'Peon',                'description' => 'General attendant (staff app only)',                     'category' => 'Support',        'flags' => [],                                     'attendance_type' => 'standard', 'is_system' => false,'tier' => 6, 'sort_order' => 25, 'surface' => 'app', 'permissions' => []],
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

    /** Phones already seen during the current import scan (in-file dedupe). */
    private $_importSeenPhones = [];

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

    /** Canonical default staff-role catalogue — single source so other modules
     *  (e.g. Org "Departments & Roles") can seed from the exact same set. */
    public static function default_staff_roles(): array
    {
        return self::DEFAULT_STAFF_ROLES;
    }

    /** Free-text Position → role_id keyword map (exposed for self-tests so they
     *  validate against the real map, not a copy). */
    public static function position_role_map(): array
    {
        return self::POSITION_ROLE_MAP;
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

        // Staff Lifecycle audit finding: salaryStructure now lives in
        // staffPrivate (PII_KEYS), same as salaryDetails/bankDetails — read
        // the private mirror, not the public staff doc.
        $existing = $this->_load_staff_private($staffId)['salaryStructure'] ?? null;
        $now      = date('c');

        // Manual structure → don't overwrite, just note the sync
        if (is_array($existing) && ($existing['source'] ?? '') === 'manual') {
            $patch = ['salaryStructure' => array_merge($existing, ['last_synced_at' => $now])];
            $this->_split_staff_private($staffId, $patch);
            // $patch['salaryStructure'] is now null (scrubbed by the split) —
            // write it back so any pre-existing public copy is actively cleared.
            $this->fs->updateEntity('staff', $staffId, $patch);
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

        $patch = ['salaryStructure' => $structure];
        $this->_split_staff_private($staffId, $patch);
        // Same active-scrub pattern as the manual branch above.
        $this->fs->updateEntity('staff', $staffId, $patch);

        // SAL-D1. The private mirror is where the structure is read back for
        // editing, but it is not the payroll authority. Compensation_reader --
        // and the HR Salary Structure screen -- both resolve a salary from
        // salarySlips/{schoolId}_SAL_{staffId}, the document Hr::_fsSyncSalary()
        // writes. Registration wrote only the mirror, so a salary captured on the
        // hiring form was invisible to both: HR showed a blank structure and
        // payroll reported the employee as having no effective salary. Writing the
        // canonical document here closes the hire -> payroll seam.
        $this->_fs_sync_canonical_salary($staffId, $structure);

        log_message('info',
            "Salary structure auto-" . (is_array($existing) ? "updated(v{$structure['_version']})" : 'created')
            . " staff=[{$staffId}] school=[{$this->school_id}]"
            . " basic={$basic} allowances={$allowances}"
        );

        return true;
    }

    /**
     * Mirror a registration-built salary structure into the canonical payroll
     * document, matching Hr::_fsSyncSalary() exactly so two writers of the same
     * concept cannot drift into two different shapes.
     *
     * Best-effort: a failure here must not fail the hire, but it is logged at
     * error level because the consequence -- an employee who cannot be paid --
     * is not something to discover at the end of the month.
     */
    private function _fs_sync_canonical_salary(string $staffId, array $structure): void
    {
        try {
            $doc = array_merge($structure, [
                'schoolId'  => $this->school_id,
                'staffId'   => $staffId,
                'type'      => 'salary_structure',
                'updatedAt' => date('c'),
            ]);
            unset($doc['_prev']);   // the audit trail stays on the private mirror
            $this->fs->set('salarySlips', $this->fs->docId("SAL_{$staffId}"), $doc, true);
            log_message('info', "Staff: canonical salary structure written for {$staffId}");
        } catch (Exception $e) {
            log_message('error',
                "Staff: canonical salary structure write FAILED for {$staffId}: " . $e->getMessage()
                . ' -- this employee is not payable until a salary is saved from the HR screen.');
        }
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

        // Server-side size cap (2 MB). The browser enforces this too, but a
        // crafted multipart POST can bypass the JS — never trust the client.
        if (!empty($file['error']) || ($file['size'] ?? PHP_INT_MAX) > 2 * 1024 * 1024) {
            log_message('error', "uploadStaffFile rejected {$label} for {$staffId}: size/error (size=" . ($file['size'] ?? '?') . ", err=" . ($file['error'] ?? '?') . ')');
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

        // Canonical Storage scheme: schools/{schoolId}/staff/{staffId}/... so the
        // school's whole footprint sits under one ID-keyed prefix. Previously
        // rooted at the bare school NAME ({school_name}/Staff/...).
        $basePath = ($label === 'Photo')
            ? "schools/{$this->school_id}/staff/{$staffId}/profile/"
            : "schools/{$this->school_id}/staff/{$staffId}/documents/";

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
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");
        $session_year = $this->session_year;

        // PERF (2026-06-13) — this page server-renders the full roster, so its
        // only cost is the blocking Firestore read below (the whole staff
        // collection + the school doc for role defs). It used to fire on every
        // visit; we now cache the assembled list under a school+session key for
        // 90s. The snapshot is busted immediately on any staff write (see
        // Firestore_service::_bustDashboard), so new hires / deletions / status
        // changes still appear on the next load with no staleness. Search and
        // filtering are already client-side, so nothing about typing changes.
        $this->load->driver('cache', ['adapter' => 'file']);
        $cacheKey = $this->fs->staffListCacheKey();
        $snapshot = $this->cache->get($cacheKey);

        if (is_array($snapshot) && isset($snapshot['staff'], $snapshot['staff_role_defs'])) {
            $data['staff']           = $snapshot['staff'];
            $data['staff_role_defs'] = $snapshot['staff_role_defs'];
        } else {
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

            // Load staff role definitions from school config
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            $data['staff_role_defs'] = $schoolDoc['staffRoles'] ?? [];
            if (!is_array($data['staff_role_defs'])) $data['staff_role_defs'] = [];

            $this->cache->save($cacheKey, [
                'staff'           => $data['staff'],
                'staff_role_defs' => $data['staff_role_defs'],
            ], 90);
        }

        $data['school_name'] = $this->school_name;

        $this->load->view('include/header');
        $this->load->view('all_staff', $data);
        $this->load->view('include/footer');
    }

    public function master_staff()
    {
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");
        $this->load->view('include/header');
        $this->load->view('import_staff'); // view file
        $this->load->view('include/footer');
    }

    // ── Fix staff count: reads actual staff entries and updates Count ───────
    public function fix_staff_count()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");

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
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");

        // Bulk import does ~7 sequential network calls per row (Firestore +
        // Firebase Auth). Without a relaxed limit a moderate roster blows PHP's
        // max_execution_time mid-loop → fatal 500 after partially importing.
        // (The chunked commit below also bounds each request, but this is the
        // belt-and-suspenders for the legacy single-shot path.)
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {

            $school_id    = $this->parent_db_key;
            $school_name  = $this->school_name;
            $session_year = $this->session_year;

            // Two entry paths:
            //  (a) confirm a previewed file via `import_token` (preferred — see
            //      preview_import()), or
            //  (b) a direct upload of `excelFile` (back-compat).
            $token    = $this->_safe_import_token($this->input->post('import_token'));
            $tmpPath  = $token ? $this->_import_tmp_path($token) : '';

            if ($token && is_file($tmpPath)) {
                $extension = strtolower(pathinfo($tmpPath, PATHINFO_EXTENSION));
                $loadPath  = $tmpPath;
            } elseif (isset($_FILES['excelFile']) && $_FILES['excelFile']['error'] === UPLOAD_ERR_OK) {
                $extension = strtolower(pathinfo($_FILES['excelFile']['name'], PATHINFO_EXTENSION));
                $loadPath  = $_FILES['excelFile']['tmp_name'];
            } else {
                if ($this->input->is_ajax_request()) {
                    $this->json_error('No file to import (upload expired — please re-select).', 400);
                } else {
                    redirect('staff/all_staff');
                }
                return;
            }

            $reader = ($extension === 'csv')
                ? IOFactory::createReader('Csv')
                : IOFactory::createReader('Xlsx');

            $spreadsheet = $reader->load($loadPath);
            $sheetData   = $spreadsheet->getActiveSheet()->toArray();

            if (count($sheetData) <= 1) {
                if ($token) { @unlink($tmpPath); }
                if ($this->input->is_ajax_request()) {
                    $this->json_error('Import failed: file is empty.', 400);
                } else {
                    $this->session->set_flashdata('import_result', 'Import failed: file is empty.');
                    redirect('staff/all_staff');
                }
                return;
            }

            $headers = array_map('trim', $sheetData[0]);
            unset($sheetData[0]);

            // Keep only non-empty data rows, re-indexed, so chunking can slice
            // by a stable absolute index across requests.
            $dataRows = array_values(array_filter($sheetData, function ($r) {
                if (!is_array($r)) return false;
                foreach ($r as $v) { if (trim((string) $v) !== '') return true; }
                return false;
            }));
            $total = count($dataRows);

            $this->load->library('id_generator');
            $this->load->library('staff_import_mapper');

            // Resolve the uploaded headers to canonical fields ONCE (alias +
            // fuzzy match), so arbitrary column names/order/extra columns work.
            $headerRes = $this->staff_import_mapper->resolveHeaders($headers);

            // Chunked/resumable commit: the client confirms a previewed file in
            // slices of CHUNK rows, so each request stays well under any
            // execution limit, reports progress, and resumes after a blip.
            // offset/chunk apply only to the token (preview→confirm) path; a
            // direct upload still imports in one shot.
            $CHUNK   = 25;
            $chunked = ($token !== '');
            $offset  = $chunked ? max(0, (int) $this->input->post('offset')) : 0;
            $end     = $chunked ? min($total, $offset + $CHUNK) : $total;

            $success = 0;
            $error   = 0;
            $skipped = [];
            // In-file phone dedupe; cross-chunk duplicates are caught by the
            // committed indexPhones entries from earlier chunks.
            $this->_importSeenPhones = [];

            for ($i = $offset; $i < $end; $i++) {
                // Map through the canonical schema — tolerant of missing/extra
                // columns, applies per-field transforms (phone digits, dates, …).
                $rowData = $this->staff_import_mapper->mapRow($dataRows[$i], $headerRes);
                $rowNum  = $i + 1;

                $res = $this->_commit_staff_row($rowData);
                if ($res['status'] === 'created') {
                    $success++;
                } else {
                    $error++;
                    $label = ($res['name'] ?? '') !== '' ? $res['name'] : 'row';
                    $skipped[] = "Row {$rowNum} ({$label}): " . ($res['reason'] ?? $res['status']);
                }
            }

            // ── Chunked path: return this slice's progress; client loops ──
            if ($chunked) {
                $done = ($end >= $total);
                if ($done) { @unlink($tmpPath); } // last chunk → drop parked file
                $this->json_success([
                    'success'    => $success,        // this chunk
                    'failed'     => $error,          // this chunk
                    'skipped'    => $skipped,        // this chunk
                    'processed'  => $end - $offset,
                    'nextOffset' => $end,
                    'total'      => $total,
                    'done'       => $done,
                ]);
                return;
            }

            // ── Legacy single-shot path (direct upload) ──
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
        } catch (\Throwable $e) {
            // \Throwable (not just Exception) so a fatal/Error returns a clean
            // message instead of a raw 500 with no body.
            log_message('error', 'IMPORT STAFF ERROR: ' . $e->getMessage());

            if ($this->input->is_ajax_request()) {
                $this->json_error('Import failed: ' . $e->getMessage(), 500);
            } else {
                $this->session->set_flashdata('import_result', 'Import failed');
                redirect('staff/all_staff');
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Import preview (dry-run) + supporting helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Parse the uploaded file and render the interactive MAP & PREVIEW page
     * (import_staff_map): the admin matches columns → fields, then validates and
     * commits in chunks. Mirrors the SIS student-import flow.
     */
    public function preview_import()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");

        // Single-page flow: the upload posts via AJAX and expects JSON back;
        // a plain (no-JS) POST still renders the standalone map view as before.
        $isAjax = $this->input->is_ajax_request();
        $fail = function ($msg) use ($isAjax) {
            if ($isAjax) {
                $this->output->set_content_type('application/json')
                     ->set_output(json_encode(['status' => 'error', 'message' => $msg]));
            } else {
                $this->session->set_flashdata('import_result', $msg);
                redirect('staff/all_staff');
            }
        };

        if (!isset($_FILES['excelFile']) || $_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
            $fail('No file received, or the upload failed. Choose a CSV or XLSX file.');
            return;
        }

        $origName  = $_FILES['excelFile']['name'];
        $extension = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx', 'xls'], true)) {
            $fail('Unsupported file type. Upload a .csv or .xlsx file.');
            return;
        }

        try {
            $reader = ($extension === 'csv') ? IOFactory::createReader('Csv') : IOFactory::createReader('Xlsx');
            $spreadsheet = $reader->load($_FILES['excelFile']['tmp_name']);
            $sheetData   = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            log_message('error', 'STAFF IMPORT PREVIEW read error: ' . $e->getMessage());
            $fail('Could not read the file. It may be corrupt or password-protected.');
            return;
        }

        // Drop fully-empty rows; first remaining row is the header.
        $sheetData = array_values(array_filter($sheetData, function ($r) {
            if (!is_array($r)) return false;
            foreach ($r as $v) { if (trim((string) $v) !== '') return true; }
            return false;
        }));
        if (count($sheetData) < 2) {
            $fail('The file has no data rows below the header.');
            return;
        }

        $headers  = array_map(function ($h) { return trim((string) $h); }, $sheetData[0]);
        $dataRows = array_slice($sheetData, 1);

        // Normalize ragged rows to header width so column alignment holds.
        $width = count($headers);
        foreach ($dataRows as &$r) {
            $r = array_map(function ($v) { return (string) $v; }, array_values($r));
            if (count($r) < $width) $r = array_pad($r, $width, '');
            elseif (count($r) > $width) $r = array_slice($r, 0, $width);
        }
        unset($r);

        $cap    = 1500;
        $capped = count($dataRows) > $cap;
        if ($capped) $dataRows = array_slice($dataRows, 0, $cap);

        $this->load->library('staff_import_mapper');

        $data = [
            'headers'  => $headers,
            'rows'     => $dataRows,
            'autoMap'  => $this->staff_import_mapper->autoMap($headers),
            'schema'   => $this->staff_import_mapper->fieldsForUi(),
            'capped'   => $capped,
            'capLimit' => $cap,
            'fileName' => $origName,
        ];

        if ($isAjax) {
            $data['status'] = 'success';
            $this->output->set_content_type('application/json')->set_output(json_encode($data));
            return;
        }

        $this->load->view('include/header');
        $this->load->view('import_staff_map', $data);
        $this->load->view('include/footer');
    }

    /**
     * Step 2 — dry-run validation (AJAX). Receives canonical rows (keyed by
     * field key) as JSON in `payload`; returns per-row {data, errors, warnings,
     * status} + summary. No writes.
     */
    public function import_validate()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $this->output->set_content_type('application/json');

        $payload = json_decode((string) $this->input->post('payload'), true);
        $rows    = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        $this->load->library('staff_import_mapper');
        $deptCtx = $this->_load_dept_role_map();

        $out = []; $ok = 0; $warn = 0; $err = 0;
        foreach ($rows as $r) {
            $v = $this->staff_import_mapper->validateCanonical(is_array($r) ? $r : []);
            // Role chain (no Firestore needed at validate time). Unresolved role
            // is a WARNING, not an error — the staff still imports (with no role,
            // assign later), so a missing/odd Position never blocks the row.
            $roleSource = trim($v['data']['role'] ?? '');
            if ($roleSource === '') $roleSource = trim($v['data']['designation'] ?? '');
            $roleIds = $this->_match_roles_no_default($roleSource);
            if (empty($roleIds)) {
                $v['warnings'][] = 'Role not recognized — will import with no role (set it later in Edit Staff)';
                if ($v['status'] === 'ok') $v['status'] = 'warning';
            }

            // Department ↔ role consistency (Departments & Roles). Warn-but-import:
            // never blocks a row. Auto-fills a blank department only when the role
            // maps to exactly one department (and isn't the teacher special case,
            // whose Department column may legitimately carry the subject).
            $deptName = trim($v['data']['department'] ?? '');
            if ($deptName !== '') {
                $known = array_key_exists(strtolower($deptName), $deptCtx['byDeptLower']);
                if (!$known) {
                    // Genuinely unknown department name — previously silent. Warn so the
                    // admin creates it in Departments & Roles (else it imports as a bare label).
                    $v['warnings'][] = 'Department "' . $deptName . '" isn\'t in Departments & Roles — create it there first, or it imports as a plain label.';
                    if ($v['status'] === 'ok') $v['status'] = 'warning';
                } elseif (!empty($roleIds)) {
                    $allowed = $deptCtx['byDeptLower'][strtolower($deptName)] ?? null;
                    if (is_array($allowed) && !empty($allowed) && array_diff($roleIds, $allowed)) {
                        $v['warnings'][] = 'Role isn\'t listed under department "' . $deptName . '" — will still import; fix in Departments & Roles or Edit Staff.';
                        if ($v['status'] === 'ok') $v['status'] = 'warning';
                    }
                }
            } elseif (count($roleIds) === 1 && $roleIds[0] !== 'ROLE_TEACHER') {
                $onlyDepts = $deptCtx['byRole'][$roleIds[0]] ?? [];
                if (count($onlyDepts) === 1) {
                    $v['data']['department'] = $onlyDepts[0];
                    $v['warnings'][] = 'Department set to "' . $onlyDepts[0] . '" from the role.';
                    if ($v['status'] === 'ok') $v['status'] = 'warning';
                }
            }

            if ($v['status'] === 'error') $err++;
            elseif ($v['status'] === 'warning') $warn++;
            else $ok++;
            $out[] = $v;
        }

        echo json_encode([
            'rows'    => $out,
            'summary' => ['ok' => $ok, 'warning' => $warn, 'error' => $err, 'total' => count($rows)],
        ]);
    }

    /**
     * Step 3 — commit (AJAX, chunked). Receives a batch of canonical rows in
     * `payload.rows`; writes every non-error row via the shared committer and
     * returns counts. The client loops batches of ~25 so no single request can
     * time out.
     */
    public function import_commit()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $this->output->set_content_type('application/json');
        @set_time_limit(0);
        @ignore_user_abort(true);

        $payload = json_decode((string) $this->input->post('payload'), true);
        $rows    = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        if (empty($rows)) {
            echo json_encode(['status' => 'error', 'message' => 'No rows to import.']);
            return;
        }

        $this->load->library('id_generator');
        $this->load->library('staff_import_mapper');
        // Reset per chunk; cross-chunk duplicates are caught by the indexPhones
        // entries written by earlier chunks.
        $this->_importSeenPhones = [];

        $created = 0; $failed = 0; $dups = 0; $skipped = [];
        $credentials = []; // {name,phone,userId,password} per newly-created staff (for the handout PDF)
        foreach ($rows as $r) {
            $rowData = $this->_canon_to_label(is_array($r) ? $r : []);
            try {
                $res = $this->_commit_staff_row($rowData);
            } catch (\Throwable $e) {
                log_message('error', 'STAFF import_commit row error: ' . $e->getMessage());
                $res = ['status' => 'failed', 'reason' => 'unexpected error', 'name' => trim($rowData['Name'] ?? '')];
            }
            if ($res['status'] === 'created') {
                $created++;
                $credentials[] = [
                    'name'     => $res['name'] ?? '',
                    'phone'    => $res['phone'] ?? '',
                    'userId'   => $res['staffId'] ?? '',
                    'password' => $res['password'] ?? '',
                ];
            } elseif ($res['status'] === 'duplicate') {
                $dups++;
            } else {
                $failed++;
                $skipped[] = (($res['name'] ?? '') ?: 'row') . ': ' . ($res['reason'] ?? 'failed');
            }
        }

        // Stash created logins so import_credentials_pdf() can build the handout
        // after all chunked batches finish. Reset on the first batch of a fresh import.
        $this->_stashImportCredentials(
            'import_creds_staff',
            $credentials,
            !empty($payload['firstBatch'])
        );

        echo json_encode([
            'status'  => 'success',
            'counts'  => ['success' => $created, 'duplicates' => $dups, 'error' => $failed],
            'skipped' => $skipped,
        ]);
    }

    /**
     * Download a PDF handout of the logins created by the most recent staff
     * import (Name · Mobile Number · User ID · Default Password). Reads the
     * credentials stashed in the session by import_commit(); a blank mobile is
     * left blank. GET (no CSRF) — the session bucket is the source of truth, so
     * the plaintext passwords never travel back from the browser.
     */
    public function import_credentials_pdf()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");

        $creds = $this->session->userdata('import_creds_staff');
        if (!is_array($creds) || empty($creds)) {
            show_error('No staff credentials are available to export. Run an import first, then download from the result screen.', 404, 'Nothing to export');
            return;
        }

        $this->load->library('pdf_generator');
        $html = $this->load->view('import_credentials_pdf', [
            'rows'        => $creds,
            'entityLabel' => 'Staff',
            'title'       => 'Staff Login Credentials',
            'schoolName'  => $this->school_display_name ?: $this->school_name,
            'sessionYear' => $this->session_year,
            'generatedAt' => date('d-m-Y H:i'),
        ], true);

        $this->pdf_generator->download($html, 'Staff_Credentials_' . date('Ymd_His') . '.pdf');
    }

    /**
     * Append a chunked import's created logins to a session bucket so the whole
     * roster exports as one PDF after all batches finish. $reset clears the
     * bucket first (first batch of a fresh import). Capped to keep session small.
     */
    private function _stashImportCredentials(string $bucket, array $creds, bool $reset): void
    {
        $existing = $reset ? [] : $this->session->userdata($bucket);
        if (!is_array($existing)) $existing = [];
        foreach ($creds as $c) {
            if (count($existing) >= 5000) break; // safety cap
            $existing[] = $c;
        }
        $this->session->set_userdata($bucket, $existing);
    }

    /**
     * Validate one mapped row + resolve its role + phone-dedupe.
     * Returns ['errors'=>[], 'roleIds'=>[], 'roleSource'=>'']. Shared by
     * preview_import() (dry-run) and import_staff() (commit) so they can never
     * disagree about what is importable.
     */
    private function _scan_import_row(array $rowData): array
    {
        $errors = $this->staff_import_mapper->validateRow($rowData);

        // Role chain: Position column → Designation column → flag for review.
        // Uses the NO-DEFAULT matcher so an unknown/blank role is genuinely held
        // back (the shared _infer_roles_from_position falls back to ROLE_TEACHER,
        // which would silently mislabel non-teaching staff — the bug this avoids).
        $roleSource = trim($rowData['Role'] ?? $rowData['Position'] ?? '');
        if ($roleSource === '') $roleSource = trim($rowData['Designation'] ?? '');
        $roleIds = $this->_match_roles_no_default($roleSource);
        if (empty($roleIds)) {
            $errors[] = 'Role could not be determined — add a Position/Role (e.g. Teacher, Accountant)';
        }

        // Phone dedupe (phone-only key):
        //  (a) within THIS file — first occurrence wins, later duplicates flagged;
        //  (b) against existing staff in indexPhones — re-import won't duplicate.
        $phone = trim($rowData['Phone Number'] ?? '');
        if ($phone !== '' && preg_match('/^[6-9]\d{9}$/', $phone)) {
            if (isset($this->_importSeenPhones[$phone])) {
                $errors[] = 'Duplicate — phone ' . $phone . ' appears earlier in this file';
            } else {
                $this->_importSeenPhones[$phone] = true;
                try {
                    $existing = $this->fs->get('indexPhones', $this->fs->docId($phone));
                    if (!empty($existing['userId'])
                        && strtolower((string)($existing['type'] ?? '')) === 'staff') {
                        $errors[] = 'Duplicate — phone already registered to staff ' . $existing['userId'];
                    }
                } catch (\Exception $e) {
                    // Non-fatal: a lookup blip shouldn't block the whole preview.
                }
            }
        }

        return ['errors' => $errors, 'roleIds' => $roleIds, 'roleSource' => $roleSource];
    }

    /**
     * Resolve role IDs from free-text WITHOUT the ROLE_TEACHER fallback that
     * _infer_roles_from_position() applies. Returns [] when nothing matches, so
     * the importer can flag the row for review instead of mislabeling it.
     * Also accepts an explicit canonical id (e.g. "ROLE_ACCOUNTANT").
     */
    /**
     * Build the Department↔Role maps from schools.departments for import checks:
     *   byDeptLower[lower(name)] = [ROLE_* allowed in it]
     *   byRole[ROLE_*]          = [department names offering it]
     * Departments with no role_ids simply don't appear in the constraint.
     */
    /** @var array|null cached dept↔role map for one import run */
    private $_importDeptCtx = null;
    /** @var bool ensure_unified_roles() runs once per import run, not per row */
    private $_importCatalogueEnsured = false;

    /** Cached dept↔role map (byDeptLower/byRole) for the current import run. */
    private function _import_dept_ctx(): array
    {
        if ($this->_importDeptCtx === null) $this->_importDeptCtx = $this->_load_dept_role_map();
        return $this->_importDeptCtx;
    }

    /**
     * Once per import run: guarantee schools.staffRoles exists so resolved role ids
     * (e.g. ROLE_ACCOUNTANT) actually resolve to modules downstream — mirrors what
     * Staff_access::onboard_staff() does. Idempotent + additive (never overwrites).
     */
    private function _ensure_import_catalogue(): void
    {
        if ($this->_importCatalogueEnsured) return;
        $this->_importCatalogueEnsured = true;
        try {
            $this->load->helper('deptrole');
            if (function_exists('ensure_unified_roles')) ensure_unified_roles($this->fs, $this->school_id);
        } catch (\Throwable $e) {
            log_message('error', 'import ensure_unified_roles: ' . $e->getMessage());
        }
        $this->_importDeptCtx = null;   // rebuild the map against the ensured catalogue
        $this->_roleLabelCache = null;  // and the role label/id lookup
    }

    private function _load_dept_role_map(): array
    {
        $byDeptLower = []; $byRole = [];
        try {
            $school = $this->fs->get('schools', $this->school_id);
            $depts  = is_array($school['departments'] ?? null) ? $school['departments'] : [];
            foreach ($depts as $d) {
                $d    = (array) $d;
                $name = trim((string) ($d['name'] ?? ''));
                if ($name === '') continue;
                $rids = is_array($d['role_ids'] ?? null) ? array_values($d['role_ids']) : [];
                $byDeptLower[strtolower($name)] = $rids;
                foreach ($rids as $rid) { $byRole[$rid][] = $name; }
            }
        } catch (\Throwable $e) {
            log_message('error', 'load dept-role map failed: ' . $e->getMessage());
        }
        return ['byDeptLower' => $byDeptLower, 'byRole' => $byRole];
    }

    private function _match_roles_no_default(string $text): array
    {
        $t = strtolower(trim($text));
        if ($t === '') return [];

        $upper = strtoupper(trim($text));
        $map   = $this->_staffRoleLabelMap();

        // Explicit canonical id (system OR custom, e.g. "ROLE_COUNSELLOR").
        if (isset($map['ids'][$upper]))              return [$upper];
        if (isset(self::DEFAULT_STAFF_ROLES[$upper])) return [$upper];

        // Exact label match — covers custom roles and the Excel role dropdown
        // (which emits labels like "Counsellor"/"Teacher"), not just keywords.
        if (isset($map['labels'][$t]))               return [$map['labels'][$t]];

        // Keyword substring map (legacy free-text Position values).
        foreach (self::POSITION_ROLE_MAP as $keyword => $roleId) {
            if (strpos($t, $keyword) !== false) return [$roleId];
        }
        return [];
    }

    /** @var array|null cached [labels=>lower(label)=>ROLE_ID, ids=>ROLE_ID=>true] */
    private $_roleLabelCache = null;

    /**
     * Label/id lookup for this school's staff roles (system + custom), cached
     * per request. Powers label-based role resolution in imports.
     */
    private function _staffRoleLabelMap(): array
    {
        if ($this->_roleLabelCache !== null) return $this->_roleLabelCache;
        $roles = self::DEFAULT_STAFF_ROLES;
        try {
            $school = $this->fs->get('schools', $this->school_id);
            if (is_array($school['staffRoles'] ?? null) && !empty($school['staffRoles'])) {
                $roles = $school['staffRoles'];
            }
        } catch (\Throwable $e) {
            log_message('error', 'staff role label map failed: ' . $e->getMessage());
        }
        $labels = []; $ids = [];
        foreach ($roles as $rid => $r) {
            $ids[strtoupper((string) $rid)] = true;
            $lbl = strtolower(trim((string) (((array) $r)['label'] ?? '')));
            if ($lbl !== '') $labels[$lbl] = $rid;
        }
        return $this->_roleLabelCache = ['labels' => $labels, 'ids' => $ids];
    }

    /**
     * Active departments + their role LABELS for the import Excel template's
     * cascading dropdown. STRICT: a department offers ONLY its assigned role_ids;
     * one with none emits an empty list (the sheet shows "(no roles configured)")
     * — matching the staff add/edit forms and the Departments & Roles module.
     * No departments configured → empty (caller leaves Department/Role free text).
     */
    private function _template_department_roles(): array
    {
        $out = [];
        try {
            $school = $this->fs->get('schools', $this->school_id);
            $depts  = is_array($school['departments'] ?? null) ? $school['departments'] : [];
            $roles  = is_array($school['staffRoles'] ?? null) && !empty($school['staffRoles'])
                ? $school['staffRoles'] : self::DEFAULT_STAFF_ROLES;

            $labelById = [];
            foreach ($roles as $rid => $r) {
                $lbl = trim((string) (((array) $r)['label'] ?? $rid));
                if ($lbl !== '') $labelById[$rid] = $lbl;
            }

            foreach ($depts as $d) {
                $d = (array) $d;
                if (($d['status'] ?? 'Active') !== 'Active') continue;
                $name = trim((string) ($d['name'] ?? ''));
                if ($name === '') continue;
                $rids   = is_array($d['role_ids'] ?? null) ? $d['role_ids'] : [];
                $labels = [];
                foreach ($rids as $rid) {
                    if (!empty($labelById[$rid])) $labels[] = $labelById[$rid];
                }
                // Strict sync: a department offers ONLY its assigned roles. If none
                // are assigned it emits an empty list (the sheet shows "(no roles
                // configured)") so the template mirrors Departments & Roles exactly —
                // no silent fall-through to every role in the school.
                $out[] = ['name' => $name, 'role_labels' => array_values(array_unique($labels))];
            }
        } catch (\Throwable $e) {
            log_message('error', 'template dept roles failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Commit one staff row (label-keyed canonical $rowData): validate fields +
     * resolve role + phone-dedupe + write the full record (Firestore + salary +
     * phone index + Firebase Auth). Shared by import_staff (file path) and
     * import_commit (mapping-UI payload path) so both write identically.
     * Returns ['status'=>'created'|'duplicate'|'failed', 'reason'=>?, 'name'=>?, 'staffId'=>?].
     */
    private function _commit_staff_row(array $rowData): array
    {
        // Guarantee the tenant role catalogue exists (once per run) so resolved role
        // ids actually map to modules downstream — same guarantee as onboard.
        $this->_ensure_import_catalogue();

        $name  = trim($rowData['Name'] ?? '');
        $phone = trim($rowData['Phone Number'] ?? '');

        // 1) Field validation — only a MISSING REQUIRED field (Name/Phone) blocks.
        $valid = $this->staff_import_mapper->validateRow($rowData);
        if (!empty($valid['errors'])) {
            return ['status' => 'failed', 'reason' => implode('; ', $valid['errors']), 'name' => $name];
        }

        // 2) Role chain: Position → Designation. Unresolved is NON-blocking —
        // the staff imports with NO nominal role (assign later from Edit Staff); we
        // never silently default to Teacher.
        $roleSource = trim($rowData['Role'] ?? $rowData['Position'] ?? '');
        if ($roleSource === '') $roleSource = trim($rowData['Designation'] ?? '');
        $roleIds = $this->_match_roles_no_default($roleSource);

        // 3) Phone-only dedupe (skip, don't fail): in-file + against existing staff.
        if ($phone !== '' && preg_match('/^[6-9]\d{9}$/', $phone)) {
            if (isset($this->_importSeenPhones[$phone])) {
                return ['status' => 'duplicate', 'reason' => "phone {$phone} repeated in this file", 'name' => $name];
            }
            $this->_importSeenPhones[$phone] = true;
            try {
                $ex = $this->fs->get('indexPhones', $this->fs->docId($phone));
                if (!empty($ex['userId']) && strtolower((string)($ex['type'] ?? '')) === 'staff') {
                    return ['status' => 'duplicate', 'reason' => 'phone already registered to staff ' . $ex['userId'], 'name' => $name];
                }
            } catch (\Exception $e) { /* non-fatal */ }
        }

        // 4) Allocate ID only now (invalid/dup rows never burn one).
        try {
            $staffId = $this->id_generator->safeGenerate('STA');
        } catch (\Throwable $e) {
            log_message('error', 'ID_GEN_INTEGRATION staff_commit_idgen_failed err=' . $e->getMessage());
            return ['status' => 'failed', 'reason' => 'ID generation failed', 'name' => $name];
        }

        $session_year = $this->session_year;
        $dob          = trim($rowData['DOB'] ?? '');
        $email        = trim($rowData['Email'] ?? '');
        $gender       = trim($rowData['Gender'] ?? '');
        $fatherName   = trim($rowData['Father Name'] ?? '');
        $empType      = trim($rowData['Employment Type'] ?? '');
        $department   = trim($rowData['Department'] ?? '');
        $dojRaw       = trim($rowData['Date Of Joining'] ?? '');
        $basicRaw     = $rowData['Basic Salary'] ?? '';
        $primaryRole  = $roleIds[0] ?? '';   // '' when role unresolved (assign later)

        // Password: First3Name + (last3 DOB year | last3 phone | "123") + @.
        // DOB is optional now, so fall back when it's missing so the generated
        // password is never just "Nam@".
        $timestamp      = strtotime($dob);
        $cleanName      = preg_replace('/\s+/', '', $name);
        $first3         = ucfirst(substr($cleanName, 0, 3));
        $year           = $timestamp ? date('Y', $timestamp) : '';
        $last3          = substr($year, -3);
        if ($last3 === '') {
            $digits = preg_replace('/\D/', '', $phone);
            $last3  = $digits !== '' ? substr($digits, -3) : '123';
        }
        $plainPassword  = $first3 . $last3 . '@';
        $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

        $basic = (float) $basicRaw;
        $allow = (float) ($rowData['Allowances'] ?? 0);
        $net   = $basic + $allow;

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

        // Teaching subjects: explicit column wins; else for teaching roles the
        // Department column carries the subject (move it, blank the department).
        $teachingSubjectsRaw = trim($rowData['Teaching Subjects'] ?? '');
        $teachingSubjects = $teachingSubjectsRaw !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $teachingSubjectsRaw))))
            : [];
        if (empty($teachingSubjects) && in_array('ROLE_TEACHER', $roleIds, true) && $department !== '') {
            $teachingSubjects = array_values(array_filter(array_map('trim', explode(',', $department))));
            $department = '';
        }

        // Department ↔ role reconciliation — Departments & Roles is the source of
        // truth. Conservative (only acts when unambiguous, never destructive):
        //   • blank department  → fill from the role when it maps to exactly one dept;
        //   • mismatched dept    → repoint to the role's real dept when unambiguous.
        // Roles that live in several departments (or none) leave the sheet value as-is.
        if (!empty($roleIds)) {
            $ctx = $this->_import_dept_ctx();
            $roleDepts = $ctx['byRole'][$roleIds[0]] ?? [];
            if ($department === '') {
                if (count($roleDepts) === 1) $department = $roleDepts[0];
            } else {
                $allowed = $ctx['byDeptLower'][strtolower($department)] ?? null;
                if (is_array($allowed) && !in_array($roleIds[0], $allowed, true) && count($roleDepts) === 1) {
                    $department = $roleDepts[0];
                }
            }
        }

        // Every imported staff carries the auto-assigned app baseline so they get the
        // common app modules even before a nominal role is set — same as onboard.
        // primary_role stays the resolved nominal role (baseline is never "primary").
        $staffRolesForDoc = array_values(array_unique(array_merge(['ROLE_BASELINE_APP'], $roleIds)));

        $positionLabel = $designation !== '' ? $designation : $roleSource;
        // Claim/`role` must be a real ROLE LABEL (panel resolves access by it) — derive
        // from the resolved role, never the free-text designation/position column.
        $roleClaimLabel = $primaryRole !== '' ? $this->_role_id_to_label($primaryRole) : $positionLabel;

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
            'staff_roles'     => $staffRolesForDoc,
            'primary_role'    => $primaryRole,
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

        // camelCase aliases mirror new_staff() so Parent + Teacher apps read fresh values.
        $fsData = array_merge($data, [
            'schoolId'       => $this->school_id,
            'session'        => $session_year,
            'sessions'       => [$session_year],
            'staffId'        => $staffId,
            'name'           => $name,
            'phone'          => $phone,
            'email'          => $email,
            'status'         => 'Active',
            'role'           => $roleClaimLabel,
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
            // Pre-Phase-1 foundational metadata — see the identical block in
            // new_staff() for the full rationale. Bulk import is a second live
            // staff-creation entry point and must carry the same immutable
            // creation record.
            'createdAt'      => date('c'),
            'createdBy'      => (string) ($this->admin_id ?? ''),
        ]);
        unset($fsData['Password'], $fsData['Credentials']);
        // Audit C1: route PAN/Aadhar/PF/ESI/salary/bank to the server-only
        // staffPrivate mirror; the readable staff doc gets nulls in their place.
        $this->_split_staff_private($staffId, $fsData);

        // Guarded write — release the STA claim on failure so the number isn't burnt.
        try {
            $writeOk = $this->fs->set('staff', $this->fs->docId($staffId), $fsData, true);
            if (!$writeOk) throw new \RuntimeException('staff set returned falsy');
        } catch (\Throwable $writeErr) {
            $staVal = (int) preg_replace('/\D/', '', $staffId);
            if ($staVal > 0) $this->id_generator->releaseClaim('STA', $staVal);
            log_message('error', "ID_GEN_INTEGRATION staff_commit_write_failed id={$staffId} released=1 err=" . $writeErr->getMessage());
            return ['status' => 'failed', 'reason' => 'Firestore write failed — ID released', 'name' => $name];
        }

        // Salary structure + phone index.
        $this->_sync_salary_structure($staffId, $basic, $allow);
        $this->fs->set('indexPhones', $this->fs->docId($phone), [
            'schoolId' => $this->school_id,
            'phone'    => $phone,
            'userId'   => $staffId,
            'type'     => 'staff',
        ]);

        // Employment History Phase 1 — JOINED event, same rationale/sequencing
        // as new_staff() (see Staff_employment_history_service docblock).
        // Bulk import is a second live staff-creation entry point and must
        // carry the same JOINED trail. Non-fatal on failure.
        $employmentHistoryWarning = null;
        try {
            $this->load->library('staff_employment_history_service');
            $ehRes = $this->staff_employment_history_service
                ->init($this->fs, $this->school_id, (string) ($this->admin_id ?? ''))
                ->recordJoined($staffId, [
                    'position'           => $positionLabel,
                    'department'         => $department,
                    'employmentType'     => $empType,
                    // Bulk import has no Reporting Manager column — always blank
                    // at creation, assignable later via Staff Edit (§16 design).
                    'reportingManagerId' => '',
                    'employmentStatus'   => 'Active',
                ]);
            if (empty($ehRes['ok'])) {
                $employmentHistoryWarning = 'employment history JOINED event could not be recorded';
                log_message('error', "Staff::_commit_staff_row JOINED event failed staff={$staffId} reason=" . ($ehRes['reason'] ?? 'unknown'));
            }
        } catch (\Throwable $ehErr) {
            $employmentHistoryWarning = 'employment history JOINED event could not be recorded';
            log_message('error', 'Staff::_commit_staff_row JOINED event threw for ' . $staffId . ': ' . $ehErr->getMessage());
        }

        // Firebase Auth (best-effort).
        try {
            $authEmail = Firebase::authEmail($staffId);
            $this->firebase->createFirebaseUser($authEmail, $plainPassword, [
                'uid'         => $staffId,
                'displayName' => $name,
            ]);
            $this->firebase->setCanonicalClaims($staffId, [
                'role'           => $roleClaimLabel,   // ROLE LABEL — panel resolves access by this
                'school_id'      => $this->school_id,
                'school_code'    => $this->school_code,
                'parent_db_key'  => $this->parent_db_key,
                // Force set-new-password on first login (mirrors reset_password@2506).
                'extra'          => [
                    'must_change_password' => true,
                ],
            ]);
            // Mirror the flag to the staff doc (Teacher app + web-panel gate).
            $this->fs->set('staff', $this->fs->docId($staffId), [
                'mustChangePassword' => true,
                'updatedAt'          => date('c'),
            ], true);
        } catch (Exception $e) {
            log_message('error', "Staff import Firebase Auth failed for {$staffId}: " . $e->getMessage());
        }

        // Return the plaintext password too — it's hashed on the doc, so this is
        // the only point it's available for the login-handout PDF.
        $row = ['status' => 'created', 'staffId' => $staffId, 'name' => $name, 'phone' => $phone, 'password' => $plainPassword];
        if ($employmentHistoryWarning !== null) {
            $row['warning'] = $employmentHistoryWarning;
        }
        return $row;
    }

    /** Convert a mapping-UI canonical row (keyed by field key) → label-keyed,
     *  applying the schema transforms so commit normalizes like validate. */
    private function _canon_to_label(array $rowByKey): array
    {
        $out = [];
        foreach ($this->staff_import_mapper->fields() as $key => $def) {
            if (!array_key_exists($key, $rowByKey)) continue;
            $label = $def['label'] ?? $key;
            $out[$label] = Staff_import_mapper::transform($rowByKey[$key], $def['transform'] ?? 'trim');
        }
        return $out;
    }

    /** Directory where previewed import files are parked between preview→confirm. */
    private function _import_tmp_dir(): string
    {
        $dir = FCPATH . 'uploads/staff_import_tmp/';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        // Belt-and-suspenders: deny direct web access to parked uploads.
        $deny = $dir . '.htaccess';
        if (!is_file($deny)) { @file_put_contents($deny, "Require all denied\nDeny from all\n"); }
        return $dir;
    }

    /**
     * Delete parked preview files older than 1 hour. Previews that are never
     * confirmed (tab closed, all rows flagged) would otherwise leak disk space.
     */
    private function _sweep_import_tmp(): void
    {
        $cutoff = time() - 3600;
        foreach (glob($this->_import_tmp_dir() . '*.{xlsx,csv}', GLOB_BRACE) ?: [] as $f) {
            if (@filemtime($f) < $cutoff) { @unlink($f); }
        }
    }

    private function _new_import_token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Sanitize a token from user input (hex only) to keep paths safe. */
    private function _safe_import_token($token): string
    {
        $token = (string) $token;
        return preg_match('/^[a-f0-9]{16,64}$/', $token) ? $token : '';
    }

    private function _import_tmp_path(string $token): string
    {
        // Extension is unknown at confirm time; resolve by globbing the token.
        foreach (['xlsx', 'csv'] as $ext) {
            $p = $this->_import_tmp_dir() . $token . '.' . $ext;
            if (is_file($p)) return $p;
        }
        return $this->_import_tmp_dir() . $token . '.xlsx';
    }

    private function _park_import_file(string $src, string $token, string $ext): bool
    {
        $dest = $this->_import_tmp_dir() . $token . '.' . ($ext === 'csv' ? 'csv' : 'xlsx');
        // move_uploaded_file isn't reliable here (already-read tmp); copy instead.
        return @copy($src, $dest);
    }

    // ── Download pre-filled Excel template for bulk import ─────────────────
    public function download_staff_template()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "view");

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Staff Import');

        // Headers — must match import_staff() expected columns exactly
        $headers = [
            'Name', 'Phone Number', 'DOB', 'Email', 'Gender',
            'Father Name', 'Blood Group', 'Department', 'Role',
            'Employment Type', 'Date Of Joining',
            'Qualification', 'Experience', 'University', 'Year Of Passing',
            'Basic Salary', 'Allowances',
            'Bank Name', 'Account Holder Name', 'Account Number', 'IFSC Code',
            'Emergency Contact Name', 'Emergency Contact Number',
            'Street', 'City', 'State', 'Postal Code',
            'Teaching Subjects',
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
            'Suresh Kumar', 'B+', 'Teaching', 'Teacher',
            'Full-time', '01-04-2024',
            'B.Ed', '5', 'Delhi University', '2015',
            '25000', '5000',
            'State Bank of India', 'Rajesh Kumar', '12345678901234', 'SBIN0001234',
            'Suresh Kumar', '9876543211',
            '123 Main Street', 'New Delhi', 'Delhi', '110001',
            'Mathematics, Science',
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

        // ── Department (col H) → Role/Position (col I) CASCADING dropdowns ─────
        // Sourced from Departments & Roles: each department offers only its roles.
        // Technique: a hidden "Lists" sheet holds the department list + a
        // name→index table + one column of role labels per department, each
        // exposed as a named range DRoles_{i}. The Role cell resolves its list
        // with INDIRECT("DRoles_"&VLOOKUP(<dept cell>, name→index, 2)). The index
        // indirection keeps it robust to spaces/symbols in department names.
        $activeDepts = $this->_template_department_roles();
        if (!empty($activeDepts)) {
            $lists = $spreadsheet->createSheet();
            $lists->setTitle('Lists');

            $lists->setCellValue('A1', 'Department');
            $lists->setCellValue('B1', 'Idx');
            $i = 0;
            foreach ($activeDepts as $dept) {
                $i++;
                $row = $i + 1;                       // data starts row 2
                $lists->setCellValue("A{$row}", $dept['name']);
                $lists->setCellValueExplicit("B{$row}", $i, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);

                // Role labels for this department in its own column (C, D, E …).
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(2 + $i);
                $labels = !empty($dept['role_labels']) ? $dept['role_labels'] : ['(no roles configured)'];
                $lists->setCellValue("{$col}1", 'Roles: ' . $dept['name']);
                $rr = 1;
                foreach ($labels as $lbl) {
                    $rr++;
                    $lists->setCellValue("{$col}{$rr}", $lbl);
                }
                $spreadsheet->addNamedRange(new \PhpOffice\PhpSpreadsheet\NamedRange(
                    'DRoles_' . $i, $lists, "\${$col}\$2:\${$col}\$" . $rr
                ));
            }
            $lastDeptRow = count($activeDepts) + 1;   // last row of dept list

            // Per-row validations (formulas can't be blindly cloned — the H-cell
            // reference must track the row), rows 3..102.
            for ($r = 3; $r <= 102; $r++) {
                $dv = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
                $dv->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
                $dv->setAllowBlank(true);
                $dv->setShowDropDown(true);
                $dv->setShowErrorMessage(false);
                $dv->setFormula1("Lists!\$A\$2:\$A\$" . $lastDeptRow);
                $sheet->getCell("H{$r}")->setDataValidation($dv);

                $rv = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
                $rv->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
                $rv->setAllowBlank(true);
                $rv->setShowDropDown(true);
                $rv->setShowErrorMessage(false);
                $rv->setFormula1('INDIRECT("DRoles_"&VLOOKUP(H' . $r . ',Lists!$A$2:$B$' . $lastDeptRow . ',2,FALSE))');
                $sheet->getCell("I{$r}")->setDataValidation($rv);
            }

            // Make the sample row consistent with the real dropdowns — prefer the
            // first department that actually has roles so H2/I2 form a valid pair.
            $sampleDept = null;
            foreach ($activeDepts as $dept) {
                if (!empty($dept['role_labels'])) { $sampleDept = $dept; break; }
            }
            if ($sampleDept === null) $sampleDept = $activeDepts[0];
            $sheet->getCell('H2')->setValue($sampleDept['name']);
            $sheet->getCell('I2')->setValue($sampleDept['role_labels'][0] ?? '');

            // Hide the helper sheet (kept in the file so the formulas resolve).
            $lists->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
        }

        // ── State (col Z) → District (col Y) CASCADING dropdowns ─────────────
        // Same hidden-sheet + INDIRECT technique as Department→Role above, sourced
        // from the canonical India geo dataset (assets/data/india_geo.json).
        if (function_exists('india_geo_states')) {
            $geoStates = india_geo_states();
            if (!empty($geoStates)) {
                $geo = $spreadsheet->createSheet();
                $geo->setTitle('Geo');
                $geo->setCellValue('A1', 'State');
                $geo->setCellValue('B1', 'Idx');
                $gi = 0;
                foreach ($geoStates as $stName) {
                    $gi++;
                    $grow = $gi + 1;                      // data starts row 2
                    $geo->setCellValue("A{$grow}", $stName);
                    $geo->setCellValueExplicit("B{$grow}", $gi, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);

                    // Districts for this state in their own column (C, D, E …).
                    $gcol  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(2 + $gi);
                    $dists = india_geo_districts($stName);
                    if (empty($dists)) $dists = ['(no districts listed)'];
                    $geo->setCellValue("{$gcol}1", 'Districts: ' . $stName);
                    $grr = 1;
                    foreach ($dists as $dName) {
                        $grr++;
                        $geo->setCellValue("{$gcol}{$grr}", $dName);
                    }
                    $spreadsheet->addNamedRange(new \PhpOffice\PhpSpreadsheet\NamedRange(
                        'GDist_' . $gi, $geo, "\${$gcol}\$2:\${$gcol}\$" . $grr
                    ));
                }
                $lastStateRow = count($geoStates) + 1;

                for ($r = 3; $r <= 102; $r++) {
                    // State dropdown (col Z)
                    $sv = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
                    $sv->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
                    $sv->setAllowBlank(true);
                    $sv->setShowDropDown(true);
                    $sv->setShowErrorMessage(false);
                    $sv->setFormula1("Geo!\$A\$2:\$A\$" . $lastStateRow);
                    $sheet->getCell("Z{$r}")->setDataValidation($sv);

                    // District dropdown (col Y) — depends on the State cell in the same row
                    $dv2 = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
                    $dv2->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
                    $dv2->setAllowBlank(true);
                    $dv2->setShowDropDown(true);
                    $dv2->setShowErrorMessage(false);
                    $dv2->setFormula1('INDIRECT("GDist_"&VLOOKUP(Z' . $r . ',Geo!$A$2:$B$' . $lastStateRow . ',2,FALSE))');
                    $sheet->getCell("Y{$r}")->setDataValidation($dv2);
                }

                // Hide the helper sheet (kept in the file so the formulas resolve).
                $geo->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
            }
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
            ['REQUIRED COLUMNS (a row is skipped only if these are empty):'],
            ['  - Name: Full name of the staff member'],
            ['  - Phone Number: 10-digit Indian mobile (starts with 6-9)'],
            [''],
            ['EVERYTHING ELSE IS OPTIONAL:'],
            ['  - Any other column may be left blank. A missing or unrecognized value is'],
            ['    shown as a WARNING in the upload preview but never blocks the row —'],
            ['    you can always fill it in later from Edit Staff.'],
            [''],
            ['DEPARTMENT & ROLE (managed in "Departments & Roles"):'],
            ['  - Department (col H): pick from the dropdown — it lists YOUR school\'s'],
            ['    departments. Choosing one narrows the Role list to that department\'s roles.'],
            ['  - Role (col I): pick from the dependent dropdown that appears after you'],
            ['    choose a Department.'],
            ['  - Typing a Department or Role that does not exist still imports, but the'],
            ['    preview flags it — create it first in Departments & Roles for a clean match.'],
            ['  - You can also fix Department/Role right in the upload preview (dropdowns).'],
            ['  - Every imported staff automatically gets baseline app access; their role'],
            ['    adds module permissions on top.'],
            [''],
            ['TEACHING SUBJECTS (col AB) — NOT the same as Department:'],
            ['  - For teachers, list the subjects here, comma-separated (e.g. "Mathematics, Science").'],
            ['  - Department stays the org unit (e.g. "Teaching"); Subjects are what they teach.'],
            [''],
            ['OTHER OPTIONAL COLUMNS:'],
            ['  - DOB, Email, Gender, Father Name, Date Of Joining, Employment Type'],
            ['  - Blood Group, Qualification, Experience, University, Year Of Passing'],
            ['  - Basic Salary, Allowances (defaults to 0)'],
            ['  - Bank Name, Account Holder Name, Account Number, IFSC Code'],
            ['  - Emergency Contact Name, Emergency Contact Number'],
            ['  - Street, City, State, Postal Code'],
            [''],
            ['PASSWORD GENERATION:'],
            ['  - Auto-generated as: First3Letters of Name + Last3Digits of DOB Year + @'],
            ['  - Example: Name="Rajesh", DOB=1990 → Password = "Raj990@"'],
            ['  - If DOB is blank, the last 3 digits of the phone number are used instead.'],
            [''],
            ['NOTES:'],
            ['  - Row 2 on "Staff Import" sheet is a SAMPLE row — delete or overwrite it'],
            ['  - Staff ID is auto-generated (STA0001, STA0002, etc.)'],
            ['  - Photo & documents can be uploaded later via Edit Staff'],
            ['  - Columns may be renamed/reordered — the importer auto-detects common'],
            ['    header names (e.g. "Mobile" = Phone Number) and shows a preview to confirm'],
            ['  - Upload shows a PREVIEW first: valid rows vs rows needing attention,'],
            ['    so nothing is saved until you confirm'],
            ['  - Gender dropdown: Male / Female / Other'],
            ['  - Employment Type dropdown: Full-time / Part-time / Contract / Temporary'],
            ['  - State dropdown: pick from the 36 India states/UTs (col "State")'],
            ['  - District dropdown: after choosing a State, col "City" offers that'],
            ['    state\'s districts (dependent dropdown). Free text is still accepted —'],
            ['    the preview flags any State/District it does not recognize, but never'],
            ['    blocks the row; spellings like "Orissa"/"Pondicherry" are auto-corrected'],
        ];
        foreach ($instructions as $i => $row) {
            $txt  = (string) $row[0];
            $cell = 'A' . ($i + 1);
            $instrSheet->setCellValue($cell, $txt);
            // Title = big bold; section headers (top-level lines ending ":") = bold.
            if ($i === 0) {
                $instrSheet->getStyle($cell)->getFont()->setBold(true)->setSize(14);
            } elseif ($txt !== '' && $txt[0] !== ' ' && substr($txt, -1) === ':') {
                $instrSheet->getStyle($cell)->getFont()->setBold(true);
            }
        }
        $instrSheet->getColumnDimension('A')->setWidth(84);

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
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
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

            // Creating one staff fans out into a long chain of sequential
            // network calls: up to 2 Storage uploads per image (file +
            // thumbnail), the staff doc, leave-balance doc, phone index,
            // schools counter, salary sync, and 2 Firebase Auth calls. On a
            // slow link or a large Aadhar file this easily exceeds PHP's
            // default 30s max_execution_time, which fatal-kills the request
            // (HTTP 500) AFTER the staff doc is already written — the user
            // sees an error but the record was half-saved. Give the handler
            // headroom so the full chain completes. Matches Timetable_service.
            @set_time_limit(180);

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

            // Date formatting — validated BEFORE allocating the STA id.
            // A bad date is pure POST-data validation and must NOT burn a
            // staff number: if we claimed first, json_error()'s exit would
            // leave the claim doc + advanced pointer behind and the next
            // staff would skip this id (the STA0009-skipped bug).
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

            // From here on $staffId is CLAIMED. Every early exit before the
            // Firestore write below MUST release the claim, or the number is
            // burnt (claim doc persists + pointer stays advanced) and the
            // next staff skips it. Use $failAndRelease() instead of
            // json_error() for any failure between here and the write.
            $staVal = (int) substr($staffId, 3);
            $failAndRelease = function (string $msg, int $code) use ($staffId, $staVal) {
                if ($staVal > 0) {
                    $this->id_generator->releaseClaim('STA', $staVal);
                    log_message('error', "ID_GEN_INTEGRATION staff_single_early_exit id={$staffId} released=1 code={$code} reason=" . $msg);
                }
                $this->json_error($msg, $code);
            };

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
                    $failAndRelease('Only JPG/JPEG files are allowed for photos.', 400);
                }

                $result = $this->uploadStaffFile($photo, $school_name, $staffId, 'Photo');
                if (!$result) {
                    $failAndRelease('Photo upload failed.', 500);
                }
                $docData['Photo'] = $result;
            }

            // Aadhar Card upload
            if (!empty($_FILES['Aadhar']['tmp_name'])) {
                $aadhar   = $_FILES['Aadhar'];
                $realMime = mime_content_type($aadhar['tmp_name']);

                if (!in_array($realMime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
                    $failAndRelease('Only PDF, JPG, JPEG, or PNG files are allowed for Aadhar.', 400);
                }

                $result = $this->uploadStaffFile($aadhar, $school_name, $staffId, 'Aadhar Card');
                if (!$result) {
                    $failAndRelease('Aadhar upload failed.', 500);
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

            // CRITICAL: the login CLAIM `role` and the staff `role` field must be a
            // real ROLE LABEL — the admin panel resolves module access by matching
            // this against the role catalogue (schools.staffRoles). Designation is a
            // free-text display title; if it drove the claim (as it used to), a typo
            // or a stray value like a phone number would match no role and silently
            // zero the user's panel access. Always derive from the SELECTED role.
            $roleClaimLabel = $this->_role_id_to_label($primaryRole);
            if ($roleClaimLabel === '') $roleClaimLabel = $positionLabel;

            // Reporting Manager (Phase 2C) — optional at admission; validated
            // server-side regardless of what the client sends (self-reference,
            // same-school existence, Active-only eligibility, bounded cycle
            // check — see Staff_employment_history_service::validateManagerAssignment()).
            $reportingManagerId = trim($normalizedPostData['reporting_manager_id'] ?? '');
            if ($reportingManagerId !== '') {
                $this->load->library('staff_employment_history_service');
                $rmValidation = $this->staff_employment_history_service
                    ->init($this->fs, $this->school_id, (string) ($this->admin_id ?? ''))
                    ->validateManagerAssignment($staffId, $reportingManagerId);
                if (!$rmValidation['ok']) {
                    $this->json_error('Invalid Reporting Manager: ' . $rmValidation['reason'], 400);
                    return;
                }
            }

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
                'role'         => $roleClaimLabel,                                          // ROLE LABEL (panel resolves access by this) — NOT the free-text designation
                'roleId'       => $primaryRole,                                             // canonical id e.g. "ROLE_TEACHER"
                'position'     => $positionLabel,                                           // free-text display title (designation)
                'department'   => $normalizedPostData['department']      ?? '',
                'gender'       => $normalizedPostData['gender']          ?? '',
                'employmentType' => $normalizedPostData['employment_type'] ?? '',
                'reportingManagerId' => $reportingManagerId,
                'fatherName'   => $normalizedPostData['father_name']     ?? '',
                'dateOfJoining' => $formattedData['dateOfJoining']      ?? '',
                'dob'          => $formattedData['DOB']                  ?? '',
                'bloodGroup'   => $normalizedPostData['blood_group']     ?? '',
                'religion'     => $normalizedPostData['religion']        ?? '',
                'category'     => $normalizedPostData['category']        ?? '',
                'profilePic'   => $docData['Photo']['url']               ?? '',
                'updatedAt' => date('c'),
                // Pre-Phase-1 foundational metadata (authorized narrowly, ahead of
                // Employment History): the TRUE creation event, immutable —
                // written ONLY on this create path, never touched by edit_staff()
                // or any other update site, so Firestore's merge-write semantics
                // preserve it automatically once set. createdBy is the server-
                // trusted session actor ($this->admin_id, MY_Controller.php:293),
                // never client input — mirrors the existing createdBy/publishedBy
                // convention already used for exam-lifecycle events (Exam.php).
                'createdAt' => date('c'),
                'createdBy' => (string) ($this->admin_id ?? ''),
            ]);
            // Phase 1 ZenX HRMS — additive, optional, capability-gated. A
            // school without HRMS_CORE_CAPABILITY never sees this field on
            // its form, so $normalizedPostData won't carry it; the explicit
            // gate here is defense-in-depth against a raw POST regardless.
            if (has_capability(HRMS_CORE_CAPABILITY)) {
                $locationIdInput = trim((string) ($normalizedPostData['location_id'] ?? ''));
                if ($locationIdInput !== '') {
                    $fsData['locationId'] = $locationIdInput;
                }
            }
            unset($fsData['Password'], $fsData['Credentials']);
            // Audit C1: PAN/Aadhar/PF/ESI/salary/bank → server-only staffPrivate.
            $this->_split_staff_private($staffId, $fsData);
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

            // Employment History Phase 1 — JOINED event + Position/Department/
            // EmploymentType timeline entries. Fired only AFTER the staff doc
            // create above is confirmed (never before) — see
            // Staff_employment_history_service's class docblock for the
            // sequencing rationale. A failure here never touches the
            // already-successful staff record; it is logged and surfaced as
            // a soft warning, same pattern as $authWarning below.
            $employmentHistoryWarning = null;
            try {
                $this->load->library('staff_employment_history_service');
                $ehRes = $this->staff_employment_history_service
                    ->init($this->fs, $this->school_id, (string) ($this->admin_id ?? ''))
                    ->recordJoined($staffId, [
                        'position'           => $positionLabel,
                        'department'         => $normalizedPostData['department']      ?? '',
                        'employmentType'     => $normalizedPostData['employment_type'] ?? '',
                        'reportingManagerId' => $reportingManagerId,
                        'employmentStatus'   => 'Active',
                    ]);
                if (empty($ehRes['ok'])) {
                    $employmentHistoryWarning = 'Staff record saved, but the employment history JOINED event could not be recorded.';
                    log_message('error', "Staff::new_staff JOINED event failed staff={$staffId} reason=" . ($ehRes['reason'] ?? 'unknown'));
                }
            } catch (\Throwable $ehErr) {
                $employmentHistoryWarning = 'Staff record saved, but the employment history JOINED event could not be recorded.';
                log_message('error', 'Staff::new_staff JOINED event threw for ' . $staffId . ': ' . $ehErr->getMessage());
            }

            // Auto-create leave balance for new staff
            try {
                $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
                $leaveTypes = (is_array($fsSchool) && !empty($fsSchool['leaveTypes'])) ? $fsSchool['leaveTypes'] : [];
                if (!empty($leaveTypes)) {
                    // Leave Policy Programme Phase 4 (2026-08-18) — pro-rate
                    // the initial allocation for a mid-year joiner. This is
                    // the choke point that actually gates what a new hire
                    // can apply for (Hr::get_leave_balances() only DISPLAYS
                    // a derived figure; this seed is what apply_leave()'s
                    // balance check reads). OFF (default): $alloc stays the
                    // full flat days_per_year, byte-identical to before —
                    // every existing school's new-hire seeding is untouched
                    // until LEAVE_PRORATA_ENABLED is explicitly turned on.
                    // PURE per-school (2026-09-20): resolved from
                    // schools.leavePolicy.LEAVE_PRORATA_ENABLED (same key
                    // Hr::_leave_flag reads); the legacy fleet-wide platform-flag
                    // fallback has been cut. Absent => OFF, fail-safe on error.
                    $prorataOn = false;
                    try {
                        $doc = $this->fs->get('schools', $this->school_id);
                        $lp  = (is_array($doc) && is_array($doc['leavePolicy'] ?? null)) ? $doc['leavePolicy'] : [];
                        $v   = $lp['LEAVE_PRORATA_ENABLED'] ?? false;
                        $prorataOn = ($v === true || $v === 1 || $v === '1' || $v === 'yes' || $v === 'true');
                    } catch (\Throwable $e) { /* fails safe to prorataOn=false */ }
                    $joinDateForProrate = $prorataOn ? ($formattedData['dateOfJoining'] ?? null) : null;

                    $balances = [];
                    foreach ($leaveTypes as $tid => $lt) {
                        if (!is_array($lt)) continue;
                        $alloc = (int) ($lt['days_per_year'] ?? 0);
                        if ($prorataOn && $joinDateForProrate) {
                            require_once APPPATH . 'libraries/Leave_year_service.php';
                            $pr = \Leave_year_service::prorateEntitlement(
                                (float) $alloc, $joinDateForProrate, null, date('Y')
                            );
                            $alloc = $pr['days'];
                        }
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
                    $this->firebase->setCanonicalClaims($staffId, [
                        'role'           => $roleClaimLabel,   // ROLE LABEL — panel resolves module access by this (not the designation)
                        'school_id'      => $this->school_id,
                        'school_code'    => $this->school_code,
                        'parent_db_key'  => $this->parent_db_key,
                        // Force set-new-password on first login (mirrors reset_password@2506).
                        'extra'          => [
                            'must_change_password' => true,
                        ],
                    ]);
                    // Mirror the flag to the staff doc (Teacher app + web-panel gate).
                    $this->fs->set('staff', $this->fs->docId($staffId), [
                        'mustChangePassword' => true,
                        'updatedAt'          => date('c'),
                    ], true);
                } catch (Exception $e) {
                    log_message('error', 'Staff: Firebase Auth create failed for ' . $staffId . ': ' . $e->getMessage());
                    $authWarning = 'Staff record saved, but Firebase Auth account could not be created: '
                        . $e->getMessage()
                        . ' — the user will not be able to log in until this is resolved.';
                }

                $warnings = array_filter([$authWarning, $phoneIndexWarning, $employmentHistoryWarning]);
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

        // Phase 1 ZenX HRMS — additive, capability-gated. A school without
        // HRMS_CORE_CAPABILITY gets an empty list and the flag off; the view
        // hides the location field entirely in that case (no visible change).
        $this->load->helper('terminology');
        $data['hrms_capability_enabled'] = has_capability(HRMS_CORE_CAPABILITY);
        $data['locations'] = $data['hrms_capability_enabled']
            ? org_locations_for_school($this->fs, $this->school_id)
            : [];

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
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
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

        // Rehire Workflow — optional attribute fields, ONLY meaningful on an
        // Active transition. Read here (before the no-op short-circuit below)
        // so an accidental "Rehire" submit against an already-Active staff
        // member gets a clear rejection instead of a silent no-op that would
        // otherwise drop the submitted attribute changes on the floor.
        $rehireFieldsSubmitted = $newStatus === 'Active' && (
            array_key_exists('position', $_POST) || array_key_exists('department', $_POST)
            || array_key_exists('employment_type', $_POST) || array_key_exists('reporting_manager_id', $_POST)
        );
        if ($rehireFieldsSubmitted && $oldStatus === $newStatus) {
            $this->json_error('Staff is already Active — Rehire only applies to a currently separated (Inactive) staff member.', 409);
            return;
        }

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

            // P0-6/C1 — canonical employment EXIT model.
            //
            // deactivatedAt records WHEN THE ADMIN FLIPPED THE SWITCH; it is not
            // the employee's last working day (an exit is routinely recorded days
            // or weeks late). Payroll pro-rates on the employment WINDOW, so it
            // needs the real last working day — and previously no field on the
            // staff record supplied one: Hr.php reads exitDate / 'Resignation
            // Date' / leaveDate and NONE of the three was ever written by any
            // path. That left only two outcomes, both wrong: leave the record
            // Active and pay a full month after the person left, or flip it to
            // Inactive and have them vanish from the roster so the final partial
            // month was never paid at all.
            //
            // ONE canonical field (`exitDate`) is written here rather than
            // extending the legacy alias chain. `lastWorkingDate` mirrors it
            // unless the caller distinguishes the two (notice served vs actually
            // worked). Absent an explicit date we fall back to today, which is
            // the best available signal and matches the previous implicit
            // assumption — but it is recorded so payroll can pro-rate instead of
            // silently dropping the employee.
            $exitDateIn = trim((string) $this->input->post('exit_date'));
            $lastWorkIn = trim((string) $this->input->post('last_working_date'));

            $exitDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $exitDateIn) ? $exitDateIn : date('Y-m-d');
            $lastWork = preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastWorkIn) ? $lastWorkIn : $exitDate;

            // A last working day AFTER the exit date is not a policy question —
            // it is impossible: nobody works after their employment has ended.
            // Both dates feed leave proration (_prorate_entitlement) and payroll
            // period eligibility, so an inverted pair produces a wrong entitlement
            // and a wrong payslip with nothing downstream to catch it.
            //
            // Future dates are DELIBERATELY still allowed: a notice period means
            // resigning today and exiting next month, which is the normal case.
            if (strtotime($lastWork) > strtotime($exitDate)) {
                $this->json_error(
                    'The last working day cannot be after the exit date. '
                    . 'Check both dates and try again.', 400);
            }

            $patch['exitDate']        = $exitDate;
            $patch['lastWorkingDate'] = $lastWork;
            $patch['exitReason']      = $reason;
        } else { // Active (reactivation / rehire)
            $patch['reactivatedAt'] = $now;
            $patch['reactivatedBy'] = $actorId;

            // P0-6/C1 — a rehire/reactivation must clear the exit window, or the
            // payroll employment-window calculation would keep clamping pay to a
            // stale exit date. Nulled (not removed) so the field's absence still
            // means "never exited" for records that never had one.
            $patch['exitDate']        = null;
            $patch['lastWorkingDate'] = null;
            $patch['exitReason']      = null;

            // D-005 (2026-08-21) — clear a separation that never completed.
            //
            // There is ONE notice document per staff member. Clearing the exit
            // window while leaving that record in a decided-but-incomplete state
            // (approved / waived / reduced) produced a permanent dead end: a new
            // resignation was refused because an "active" notice existed,
            // withdraw and decide both require 'pending', and complete requires
            // the staff member to be Inactive — which a rehire has just undone.
            // The employee could never resign again through the system.
            //
            // 'cancelled' rather than a new state: it is the vocabulary
            // established HRMS platforms already use for a separation that did
            // not take effect (rehire flows key off the "most recent
            // non-cancelled separation record"), it is what Fnf_state_machine
            // already means by the word, and unlike the alternatives it
            // misstates nothing — HR did not reject it, the employee did not
            // withdraw it, and it certainly did not complete.
            //
            // Cancelling only ever RELEASES a stuck record: terminal notices are
            // left untouched, so a normal rehire after a completed separation is
            // byte-identical to before.
            $this->_cancel_notice_on_rehire($user_id, $actorId);

            // Rehire Workflow — optional attribute fields, present only when
            // the caller is a Rehire action (not a bare Reactivate). Each is
            // read individually via array_key_exists so a plain Reactivate
            // (which submits none of these) is byte-identical to before this
            // change. Position/Department/EmploymentType are accepted as
            // simple direct values here — deliberately NOT routed through
            // edit_staff()'s role-derivation/Designation logic, which is
            // RBAC-adjacent and out of this phase's authorized scope; HR
            // types the new Position freely, exactly as on Staff Admission.
            $rehireOtherChanges = [];
            if (array_key_exists('position', $_POST)) {
                $v = trim((string) $this->input->post('position'));
                if ($v !== '' && $v !== (string) ($existing['Position'] ?? $existing['position'] ?? '')) {
                    $rehireOtherChanges['position'] = $v;
                    $patch['Position'] = $v;
                    $patch['position'] = $v;
                    $patch['role']     = $v; // mirrors edit_staff()'s own Position->role mirroring convention
                }
            }
            if (array_key_exists('department', $_POST)) {
                $v = trim((string) $this->input->post('department'));
                if ($v !== (string) ($existing['Department'] ?? $existing['department'] ?? '')) {
                    $rehireOtherChanges['department'] = $v;
                    $patch['Department'] = $v;
                    $patch['department'] = $v;
                }
            }
            if (array_key_exists('employment_type', $_POST)) {
                $v = trim((string) $this->input->post('employment_type'));
                if ($v !== (string) ($existing['Employment Type'] ?? $existing['employmentType'] ?? '')) {
                    $rehireOtherChanges['employmentType'] = $v;
                    $patch['Employment Type'] = $v;
                    $patch['employmentType']  = $v;
                }
            }
            if (array_key_exists('reporting_manager_id', $_POST)) {
                $v = trim((string) $this->input->post('reporting_manager_id'));
                if ($v !== (string) ($existing['reportingManagerId'] ?? '')) {
                    $this->load->library('staff_employment_history_service');
                    $rmValidation = $this->staff_employment_history_service
                        ->init($this->fs, $this->school_id, $actorId)
                        ->validateManagerAssignment($user_id, $v);
                    if (!$rmValidation['ok']) {
                        $this->json_error('Invalid Reporting Manager: ' . $rmValidation['reason'], 400);
                        return;
                    }
                    $rehireOtherChanges['reportingManagerId'] = $v;
                    $patch['reportingManagerId'] = $v;
                }
            }

            $rehireEffectiveDateIn = trim((string) $this->input->post('effective_date'));
            $rehireEffectiveDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rehireEffectiveDateIn) ? $rehireEffectiveDateIn : date('Y-m-d');
        }

        // Phase 3A.1 — separation-metadata privacy closure. set_status() is a
        // 4th staff-write path (alongside new_staff/_commit_staff_row/edit_staff)
        // that never called _split_staff_private() — meaning exitReason/
        // deactivationReason/deactivatedBy/reactivatedBy were written straight
        // onto the same-school-readable staff doc. Same call, same place in the
        // sequence (immediately before the write) as the other 3 call sites.
        $this->_split_staff_private($user_id, $patch);

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

        // Phase 3B.1 (+ Rehire Workflow) — Employment History integration.
        // Added AFTER the existing write + cascades succeed (same non-fatal,
        // try/catch, never-rolls-back pattern already used for Position/
        // Department/EmploymentType/ReportingManagerId) — set_status()'s own
        // logic above this point is completely unchanged. On Inactive: fires
        // SEPARATED and creates the linked staffSeparations record in the
        // SAME atomic commitBatch as the event. On Active: fires REACTIVATED
        // (bare status flip, no attribute changes submitted) or REHIRED (one
        // or more of position/department/employmentType/reportingManagerId
        // submitted — see determineReactivationEventType()). REHIRED's
        // accompanying attribute changes are recorded via a SECOND,
        // independent recordChanges() call — two atomic operations, not one
        // combined mega-batch, per the documented, reviewed Phase 3B.1
        // design tradeoff (recordEmploymentStatusChange() and recordChanges()
        // remain two separate, already-proven mechanisms; not merged here).
        $employmentHistoryWarning = null;
        try {
            $this->load->library('staff_employment_history_service');
            $ehSvc = $this->staff_employment_history_service
                ->init($this->fs, $this->school_id, $actorId);

            if ($newStatus === 'Inactive') {
                $separationType = trim((string) $this->input->post('separation_type'));
                if ($separationType === '') $separationType = 'Other';
                $ehRes = $ehSvc->recordEmploymentStatusChange(
                    $user_id,
                    'SEPARATED',
                    'Inactive',
                    $lastWork,           // effectiveFrom = actualLastWorkingDate, never expectedLastWorkingDate
                    $reason,
                    [],
                    $separationType,
                    $lastWork            // actualLastWorkingDate, explicit
                );
            } else {
                $otherChanges   = $rehireOtherChanges ?? [];
                $effectiveDate  = $rehireEffectiveDate ?? date('Y-m-d');
                $eventType      = $ehSvc->determineReactivationEventType($otherChanges);
                $ehRes = $ehSvc->recordEmploymentStatusChange(
                    $user_id,
                    $eventType,
                    'Active',
                    $effectiveDate,
                    $reason,
                    []
                );
                if (!empty($ehRes['ok']) && !empty($otherChanges)) {
                    $attrRes = $ehSvc->recordChanges($user_id, $otherChanges, $effectiveDate, $reason);
                    if (empty($attrRes['ok'])) {
                        $employmentHistoryWarning = 'Staff rehired, but the accompanying Position/Department/'
                            . 'Employment Type/Reporting Manager change could not be fully recorded in Employment History ('
                            . ($attrRes['reason'] ?? 'unknown') . ').';
                        log_message('error', "Staff::set_status rehire attribute history failed staff={$user_id} reason=" . ($attrRes['reason'] ?? 'unknown'));
                    }
                }
            }
            if (empty($ehRes['ok'])) {
                $employmentHistoryWarning = 'Staff status updated, but employment history could not be recorded ('
                    . ($ehRes['reason'] ?? 'unknown') . ').';
                log_message('error', "Staff::set_status employment history failed staff={$user_id} reason=" . ($ehRes['reason'] ?? 'unknown'));
            }
        } catch (\Throwable $ehErr) {
            $employmentHistoryWarning = 'Staff status updated, but employment history could not be recorded.';
            log_message('error', 'Staff::set_status employment history threw for ' . $user_id . ': ' . $ehErr->getMessage());
        }

        log_message('info', "STAFF_STATUS user={$user_id} {$oldStatus}->{$newStatus} by={$actorId} "
            . "reason=" . ($reason ?: '(none)')
            . " cascade=" . json_encode($cascadeStats)
            . " auth=" . json_encode($authStats));
        // Note: 'newStatus' (not 'status') so it doesn't collide with json_success's
        // own 'status' => 'success' field.
        $payload = [
            'newStatus' => $newStatus,
            'message'   => 'Status changed to ' . $newStatus . '.',
            'cascade'   => $cascadeStats,
            'auth'      => $authStats,
        ];
        if ($employmentHistoryWarning !== null) {
            $payload['warning'] = $employmentHistoryWarning;
        }
        $this->json_success($payload);
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
        //    Phase 0D (Staff Lifecycle security hardening): was ->where(),
        //    unscoped by schoolId — this runs via the Admin SDK service
        //    account, which BYPASSES Firestore rules entirely, so the rules
        //    fix on userDevices (Phase 0C) does not cover this query. userId
        //    (staffId) is only unique PER SCHOOL (sequential counter), so an
        //    unscoped query risked deleting another school's same-id staff
        //    member's device docs. schoolWhere() prepends the tenant filter.
        try {
            $devices = $this->fs->schoolWhere('userDevices', [['userId', '==', $userId]]);
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
     * Academic Assignment Policy (Option B, locked): Staff Employment
     * Lifecycle and Academic Assignment Lifecycle are separate. Deactivation
     * archives this teacher's subjectAssignments rows so they stop appearing
     * as live/eligible — that half is unchanged. Reactivation/Rehire
     * deliberately does NOT restore them anymore: Academic Planner is the
     * sole source of truth for current academic responsibility, and the
     * school must explicitly re-assign. (Previously this un-archived rows
     * carrying archivedBecauseOfDeactivation=true on the Active transition —
     * that auto-restore path is removed, not merely gated, per the approved
     * design report.)
     *
     * Also closes a verified gap: archiving a class-teacher row left
     * `sections.classTeacherId` pointing at the departed/inactive staff
     * member (every consumer — Ptm.php, Gate_pass_service.php, the Teacher
     * and Parent apps — already treats a blank classTeacherId as a normal,
     * handled "no class teacher configured" state, so clearing it is safe).
     * The clear is compare-and-clear (only if the section doc still names
     * THIS teacher) so a class teacher reassigned to someone else in the
     * meantime is never overwritten — see the conflict scenario documented
     * in the design report.
     *
     * @return array  ['matched' => int, 'patched' => int, 'failed' => int,
     *                 'classTeacherPointersCleared' => int, 'classTeacherPointerClearFailed' => int]
     */
    private function _cascade_subject_assignments(
        string $userId, string $newStatus, string $reason, string $actorId, string $nowIso
    ): array {
        $stats = [
            'matched' => 0, 'patched' => 0, 'failed' => 0,
            'classTeacherPointersCleared' => 0, 'classTeacherPointerClearFailed' => 0,
        ];

        // Reactivation/Rehire: no assignment restoration, ever — nothing to
        // do here. Academic Planner is the only path back to an active
        // assignment; see the Academic Assignment Policy design report.
        if ($newStatus !== 'Inactive') {
            return $stats;
        }

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

            $patch = [
                'archived'                       => true,
                'archivedAt'                     => $nowIso,
                'archivedReason'                 => $reason !== ''
                    ? $reason
                    : 'Teacher deactivated',
                'archivedBy'                     => $actorId,
                'archivedBecauseOfDeactivation'  => true,
            ];

            try {
                $ok = $this->fs->update('subjectAssignments', $docId, $patch);
                $ok ? $stats['patched']++ : $stats['failed']++;
            } catch (\Throwable $e) {
                log_message('error', "cascade patch failed docId={$docId}: " . $e->getMessage());
                $stats['failed']++;
                continue;
            }

            if (!empty($d['isClassTeacher'])) {
                $this->_clear_stale_class_teacher_pointer($userId, $d, $stats);
            }
        }
        return $stats;
    }

    /**
     * Compare-and-clear: only blanks sections.classTeacherId if it still
     * names the teacher being deactivated — never overwrites a pointer that
     * already points at a different (e.g. newly reassigned) teacher. Same
     * school-scoped doc-id construction Subject_assignment_service uses when
     * it originally cross-wrote the field, using this row's own
     * schoolId/session/className/section (not the current session), since
     * the cascade query itself is not session-filtered.
     */
    private function _clear_stale_class_teacher_pointer(string $userId, array $assignmentRow, array &$stats): void
    {
        $schoolId = (string) ($assignmentRow['schoolId'] ?? $this->school_id);
        $session  = (string) ($assignmentRow['session']  ?? '');
        $class    = (string) ($assignmentRow['className'] ?? '');
        $section  = (string) ($assignmentRow['section']   ?? '');
        if ($schoolId === '' || $session === '' || $class === '' || $section === '') {
            return; // can't safely resolve the section doc — leave it alone
        }
        $sectionDocId = "{$schoolId}_{$session}_{$class}_{$section}";

        try {
            $sectionDoc = $this->fs->get('sections', $sectionDocId);
            if (!is_array($sectionDoc)) return;
            $currentClassTeacherId = trim((string) ($sectionDoc['classTeacherId'] ?? ''));
            if ($currentClassTeacherId !== $userId) {
                return; // already reassigned to someone else — never overwrite
            }
            $ok = $this->fs->update('sections', $sectionDocId, ['classTeacherId' => '']);
            $ok ? $stats['classTeacherPointersCleared']++ : $stats['classTeacherPointerClearFailed']++;
        } catch (\Throwable $e) {
            log_message('error', "classTeacherId clear failed section={$sectionDocId} teacher={$userId}: " . $e->getMessage());
            $stats['classTeacherPointerClearFailed']++;
        }
    }

    /**
     * POST /staff/reset_password
     *
     * School-admin / principal driven password reset for a staff member.
     * Writes the new password to Firebase Auth (primary auth source for
     * teacher/staff mobile apps), flags must_change_password=true so the
     * app forces a self-set on next login, and revokes existing refresh
     * tokens so other devices re-authenticate.
     *
     * Mirror: the new bcrypt hash is also written to the staff Firestore
     * doc's Credentials.Password field — write-only, nothing reads it,
     * kept for record-keeping per project policy.
     *
     * @return void
     */
    public function reset_password(): void
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin', 'Principal'], 'reset_staff_password', "SIS", "manage");
        header('Content-Type: application/json');

        if ($this->input->method() !== 'post') {
            $this->json_error('POST only.', 405);
            return;
        }

        $staff_id     = trim((string) $this->input->post('user_id', TRUE));
        $new_password = (string) $this->input->post('new_password', FALSE);

        if ($staff_id === '' || !preg_match('/^[A-Za-z0-9_]+$/', $staff_id)) {
            $this->json_error('Invalid staff id.', 400);
            return;
        }
        if ($new_password === '') {
            $this->json_error('New password is required.');
            return;
        }
        if (strlen($new_password) < 8 || strlen($new_password) > 72) {
            $this->json_error('Password must be 8–72 characters.');
            return;
        }
        if (!preg_match('/[A-Z]/', $new_password)
            || !preg_match('/[a-z]/', $new_password)
            || !preg_match('/[0-9]/', $new_password)) {
            $this->json_error('Password must contain an uppercase letter, a lowercase letter, and a digit.');
            return;
        }

        // Tenant check — staff must belong to the current school.
        try {
            $staffDoc = $this->fs->getEntity('staff', $staff_id);
        } catch (\Exception $e) {
            log_message('error', 'Staff::reset_password fetch failed: ' . $e->getMessage());
            $this->json_error('Failed to load staff record.');
            return;
        }
        if (empty($staffDoc) || !is_array($staffDoc)) {
            $this->json_error('Staff not found.', 404);
            return;
        }
        if (((string) ($staffDoc['schoolId'] ?? '')) !== $this->school_id) {
            log_message('error', "RBAC tenant breach attempt: admin={$this->admin_id} school={$this->school_id} tried to reset staff={$staff_id} of school={$staffDoc['schoolId']}");
            $this->json_error('Staff not found in your school.', 404);
            return;
        }

        $name = (string) ($staffDoc['name'] ?? $staffDoc['Name'] ?? $staff_id);

        // 1. Update Firebase Auth password (primary auth source).
        $updated = $this->firebase->updateFirebaseUser($staff_id, ['password' => $new_password]);
        if ($updated === null) {
            $this->json_error('Failed to update Firebase Auth password.');
            return;
        }

        // 2. Set must-change-password claim — the app gates first login on this.
        // Self-heal the role claim from the canonical role id (roleId/primary_role)
        // so a reset repairs any staff whose free-text `role` field is stale/bad
        // (e.g. a designation or phone that would zero their panel access).
        $rpId = (string) ($staffDoc['roleId'] ?? $staffDoc['primary_role'] ?? '');
        $roleClaim = $rpId !== '' ? $this->_role_id_to_label($rpId) : (string) ($staffDoc['role'] ?? 'Teacher');
        if ($roleClaim === '') $roleClaim = (string) ($staffDoc['role'] ?? 'Teacher');
        $this->firebase->setCanonicalClaims($staff_id, [
            'role'          => $roleClaim,
            'role_fallback' => 'Teacher',
            'school_id'     => $this->school_id,
            'school_code'   => $this->school_code,
            'parent_db_key' => $this->parent_db_key,
            'extra'         => [
                'must_change_password' => true,
                'password_reset_at'    => time(),
                'password_reset_by'    => (string) ($this->admin_id ?? ''),
            ],
        ]);

        // 3. Revoke refresh tokens — kicks active sessions on other devices.
        $this->firebase->revokeRefreshTokens($staff_id);

        // 4. Mirror ONLY the must-change flag to the Firestore staff doc.
        //    SECURITY (audit C1): the bcrypt hash is NO LONGER written here. The
        //    staff doc is same-school readable (parents/students included), so
        //    storing a password hash — even bcrypt — was a credential leak with
        //    zero legitimate reader (Firebase Auth is the sole password authority;
        //    nothing reads Password/Credentials back). mustChangePassword IS read
        //    by the Teacher app to trigger its force-change-password screen, so it
        //    stays. Existing docs are scrubbed by Staff_pii_cleanup.
        try {
            $this->fs->set('staff', $this->fs->docId($staff_id), [
                'mustChangePassword' => true,
                'lastUpdated'        => date('Y-m-d'),
                'updatedAt'          => date('c'),
            ], true);
        } catch (\Exception $e) {
            log_message('error', 'Staff::reset_password Firestore mirror failed: ' . $e->getMessage());
            // Non-fatal: Firebase Auth is the truth.
        }

        log_audit('Staff', 'reset_password', $staff_id, "Password reset for '{$name}'");

        $this->json_success([
            'message' => "Password reset for '{$name}'. They will be required to change it on next login.",
            'user_id' => $staff_id,
            'name'    => $name,
        ]);
    }

    /**
     * POST /staff/delete_staff/{userId}  (AJAX, JSON)
     *
     * Hard-deletes a staff member and their LIVE links across the system:
     *   - staff doc (master record)
     *   - indexPhones reverse-index
     *   - userDevices (FCM / push)
     *   - subjectAssignments (class/subject wiring)
     *   - Firebase Auth account
     *
     * Historical records — attendance, salarySlips, marks, leaveApplications,
     * appraisals — are deliberately KEPT so audit / payroll history survives.
     *
     * The cascade runs FIRST (best-effort, each step independently caught),
     * then the master staff doc is removed, then the Auth account. The staff
     * doc is the source of truth: if its removal fails we abort and report.
     */
    public function delete_staff($id = null)
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        header('Content-Type: application/json');

        if ($this->input->method() !== 'post') {
            $this->json_error('POST only.', 405);
            return;
        }
        if (!$id || !preg_match('/^[A-Za-z0-9_]+$/', $id)) {
            $this->json_error('Invalid user id.', 400);
            return;
        }

        // Read the staff doc before we touch anything (need phone + name).
        $staff = $this->fs->getEntity('staff', $id);
        if (empty($staff)) {
            $this->json_error('Staff not found.', 404);
            return;
        }
        $name = $staff['Name'] ?? $staff['name'] ?? $id;

        // ── Cascade: remove the teacher's LIVE links everywhere ──
        $cascade = $this->_delete_live_links($id, $staff);

        // ── Remove the master staff doc (source of truth) ──
        try {
            $ok = $this->fs->removeEntity('staff', $id);
            if (!$ok) {
                $this->json_error('Failed to delete staff record.', 500);
                return;
            }
        } catch (\Throwable $e) {
            log_message('error', "delete_staff removeEntity failed for {$id}: " . $e->getMessage());
            $this->json_error('Failed to delete staff record: ' . $e->getMessage(), 500);
            return;
        }

        // ── Delete Firebase Auth account (best-effort) ──
        $authDeleted = false;
        try {
            $authDeleted = (bool) $this->firebase->deleteFirebaseUser($id);
        } catch (\Throwable $e) {
            log_message('error', "delete_staff Firebase Auth failed for {$id}: " . $e->getMessage());
        }

        log_message('info', "STAFF_DELETE user={$id} name={$name} by=" . ($this->admin_id ?? '')
            . " cascade=" . json_encode($cascade)
            . " authDeleted=" . ($authDeleted ? '1' : '0'));

        $this->json_success([
            'message'     => $name . ' deleted, along with their live links.',
            'cascade'     => $cascade,
            'authDeleted' => $authDeleted,
        ]);
    }

    /**
     * Hard-delete a staff member's LIVE links across Firestore.
     *
     * "Live links" keep a teacher wired into day-to-day operations: their
     * phone reverse-index, push-notification devices, and subject/class
     * assignments. Historical records (attendance, salarySlips, marks,
     * leaveApplications, appraisals) are deliberately left untouched so
     * audit / payroll history survives the delete.
     *
     * Every step is independently try/caught — a failure in one collection
     * is logged and counted but never aborts the rest of the cascade.
     *
     * @return array per-collection deletion counts + any errors
     */
    private function _delete_live_links(string $userId, array $staff): array
    {
        $stats = [
            'indexPhones'        => 0,
            'userDevices'        => 0,
            'subjectAssignments' => 0,
            'errors'             => [],
        ];

        // 1) Phone reverse-index (doc id is school-scoped: {schoolId}_{phone})
        try {
            $phone = trim((string) ($staff['Phone Number'] ?? $staff['phone'] ?? ''));
            if ($phone !== '' && $this->fs->remove('indexPhones', $this->fs->docId($phone))) {
                $stats['indexPhones'] = 1;
            }
        } catch (\Throwable $e) {
            $stats['errors'][] = 'indexPhones: ' . $e->getMessage();
            log_message('error', "delete cascade indexPhones failed for {$userId}: " . $e->getMessage());
        }

        // 2) userDevices (FCM) — one doc per device.
        //    Phase 0D (Staff Lifecycle security hardening): was ->where(),
        //    unscoped by schoolId. Same fix as _disable_firebase_user() above
        //    — schoolWhere() prepends the tenant filter so this hard-delete
        //    cascade can't touch another school's same-id staff member's
        //    device docs.
        try {
            $devices = $this->fs->schoolWhere('userDevices', [['userId', '==', $userId]]);
            if (is_array($devices)) {
                foreach ($devices as $entry) {
                    $docId = $entry['id'] ?? '';
                    if ($docId === '') continue;
                    try {
                        if ($this->fs->remove('userDevices', $docId)) $stats['userDevices']++;
                    } catch (\Throwable $e) {
                        log_message('error', "delete cascade userDevices failed docId={$docId}: " . $e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            $stats['errors'][] = 'userDevices: ' . $e->getMessage();
            log_message('error', "delete cascade userDevices query failed for {$userId}: " . $e->getMessage());
        }

        // 3) subjectAssignments — teacher↔class/subject wiring (school-scoped).
        //    Field is teacherId on newer rows, staffId on older ones; cover both.
        try {
            $seen = [];
            foreach (['teacherId', 'staffId'] as $field) {
                $rows = $this->fs->schoolWhere('subjectAssignments', [[$field, '==', $userId]]);
                if (!is_array($rows)) continue;
                foreach ($rows as $row) {
                    $docId = $row['id'] ?? '';
                    if ($docId === '' || isset($seen[$docId])) continue;
                    $seen[$docId] = true;
                    try {
                        if ($this->fs->remove('subjectAssignments', $docId)) $stats['subjectAssignments']++;
                    } catch (\Throwable $e) {
                        log_message('error', "delete cascade subjectAssignments failed docId={$docId}: " . $e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            $stats['errors'][] = 'subjectAssignments: ' . $e->getMessage();
            log_message('error', "delete cascade subjectAssignments query failed for {$userId}: " . $e->getMessage());
        }

        return $stats;
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function edit_staff($user_id)
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "edit");
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
            // Only touch teaching_subjects when the form actually submitted the
            // field. A stale/cached form (or any edit page that doesn't render
            // the subjects picker) would otherwise silently WIPE a teacher's
            // saved subjects on every save. When the key IS present, an empty
            // value still clears it on purpose (e.g. role changed away from Teacher).
            if (array_key_exists('teaching_subjects', $postData)) {
                $teachingSubjectsRaw = trim((string) $postData['teaching_subjects']);
                $formattedData['teaching_subjects'] = $teachingSubjectsRaw !== ''
                    ? array_values(array_filter(array_map('trim', explode(',', $teachingSubjectsRaw))))
                    : [];
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

            // Phase 1 ZenX HRMS — additive, optional, capability-gated. Same
            // "only touch when the form actually submitted the field" rule as
            // teaching_subjects above, so a stale/cached edit form never
            // silently clears an existing assignment.
            if (has_capability(HRMS_CORE_CAPABILITY) && array_key_exists('location_id', $postData)) {
                $formattedData['locationId'] = trim((string) $postData['location_id']);
            }

            // Audit C1: PAN/Aadhar/PF/ESI/bank submitted on this edit go to the
            // server-only staffPrivate mirror; the readable staff doc is nulled
            // for those keys. (salaryDetails is handled by the salary block below.)
            $this->_split_staff_private($user_id, $formattedData);

            // P0-8 — session membership upkeep. `sessions[]` was written ONCE at
            // creation and never refreshed on edit, so a staff member created in
            // session A stayed invisible to session-scoped operations in session B
            // (e.g. Attendance::bulk_mark_staff filters
            // sessions array-contains <current session>). Merge-append the current
            // session here; existing entries are preserved and the array stays
            // unique, so this can never drop a historical session or duplicate a
            // record. Only touched when a current session is actually resolved.
            if (!empty($this->session_year)) {
                $existingSessions = $existingData['sessions'] ?? [];
                if (!is_array($existingSessions)) $existingSessions = [];
                if (!in_array($this->session_year, $existingSessions, true)) {
                    $existingSessions[] = $this->session_year;
                    $formattedData['sessions'] = array_values(array_unique($existingSessions));
                }
            }

            // Reporting Manager (Phase 2C) — optional; only touched when the
            // edit form actually submitted the field (same "don't silently
            // clear on a stale form" discipline as teaching_subjects/location_id
            // above). Validated server-side regardless of client input.
            $reportingManagerSubmitted = array_key_exists('reporting_manager_id', $postData);
            if ($reportingManagerSubmitted) {
                $rmCandidate = trim((string) ($postData['reporting_manager_id'] ?? ''));
                $this->load->library('staff_employment_history_service');
                $rmValidation = $this->staff_employment_history_service
                    ->init($this->fs, $this->school_id, (string) ($this->admin_id ?? ''))
                    ->validateManagerAssignment($user_id, $rmCandidate);
                if (!$rmValidation['ok']) {
                    $this->json_error('Invalid Reporting Manager: ' . $rmValidation['reason'], 400);
                    return;
                }
                $formattedData['reportingManagerId'] = $rmCandidate;
            }

            $updateRes = $this->fs->updateEntity('staff', $user_id, $formattedData);

            // RTDB mirror removed per no-RTDB policy. Firestore `staff` is the sole source.

            if ($updateRes) {
                // Employment History Phase 1 — fired only AFTER updateEntity()
                // above is confirmed to have succeeded (never before), and only
                // for the Phase-1 attributes that were both actually submitted
                // on this edit AND actually changed value. See
                // Staff_employment_history_service's class docblock for the
                // full sequencing rationale (the staff doc write itself is
                // left completely unmodified by this phase).
                $employmentHistoryWarning = null;
                $ehChanges = [];
                if (isset($formattedData['position'])) {
                    $oldPosition = (string) ($existingData['position'] ?? $existingData['Position'] ?? '');
                    if ($oldPosition !== (string) $formattedData['position']) {
                        $ehChanges['position'] = (string) $formattedData['position'];
                    }
                }
                if (isset($formattedData['department'])) {
                    $oldDepartment = (string) ($existingData['department'] ?? $existingData['Department'] ?? '');
                    if ($oldDepartment !== (string) $formattedData['department']) {
                        $ehChanges['department'] = (string) $formattedData['department'];
                    }
                }
                if (isset($formattedData['employmentType'])) {
                    $oldEmpType = (string) ($existingData['employmentType'] ?? $existingData['Employment Type'] ?? '');
                    if ($oldEmpType !== (string) $formattedData['employmentType']) {
                        $ehChanges['employmentType'] = (string) $formattedData['employmentType'];
                    }
                }
                if ($reportingManagerSubmitted && isset($formattedData['reportingManagerId'])) {
                    $oldReportingManager = (string) ($existingData['reportingManagerId'] ?? '');
                    if ($oldReportingManager !== (string) $formattedData['reportingManagerId']) {
                        $ehChanges['reportingManagerId'] = (string) $formattedData['reportingManagerId'];
                    }
                }
                if (!empty($ehChanges)) {
                    try {
                        $this->load->library('staff_employment_history_service');
                        $ehRes = $this->staff_employment_history_service
                            ->init($this->fs, $this->school_id, (string) ($this->admin_id ?? ''))
                            ->recordChanges($user_id, $ehChanges);
                        if (empty($ehRes['ok'])) {
                            $employmentHistoryWarning = 'Staff record updated, but employment history could not be recorded ('
                                . ($ehRes['reason'] ?? 'unknown') . ').';
                            log_message('error', "Staff::edit_staff employment history recordChanges failed staff={$user_id} reason=" . ($ehRes['reason'] ?? 'unknown'));
                        }
                    } catch (\Throwable $ehErr) {
                        $employmentHistoryWarning = 'Staff record updated, but employment history could not be recorded.';
                        log_message('error', 'Staff::edit_staff employment history threw for ' . $user_id . ': ' . $ehErr->getMessage());
                    }
                }

                // Phone number changed — update phone index, but DON'T hijack a
                // number another account already owns (mirrors new_staff). If a
                // different staff/parent/student owns it, skip the index swap and
                // warn — the staff doc keeps the new number, but OTP for it stays
                // with the original owner.
                $phoneWarning = null;
                if (!empty($formattedData['Phone Number']) && $formattedData['Phone Number'] !== $oldPhoneNumber) {
                    $newPhone = $formattedData['Phone Number'];
                    $blocked  = false;
                    try {
                        $pre = $this->fs->get('indexPhones', $this->fs->docId($newPhone));
                        if (!empty($pre['userId']) && $pre['userId'] !== $user_id) {
                            $blocked = true;
                            $preType = strtolower((string)($pre['type'] ?? 'account'));
                            $phoneWarning = 'Phone is already registered to ' . $preType . ' ' . $pre['userId']
                                . '. The record was saved, but OTP login on this number still resolves to that account.';
                            log_message('error', "edit_staff: phone {$newPhone} owned by {$preType} {$pre['userId']} — index NOT changed for {$user_id}");
                        }
                    } catch (\Exception $e) { /* non-fatal */ }

                    if (!$blocked) {
                        if ($oldPhoneNumber) {
                            $this->fs->remove('indexPhones', $this->fs->docId($oldPhoneNumber));
                        }
                        $this->fs->set('indexPhones', $this->fs->docId($newPhone), [
                            'schoolId' => $this->school_id,
                            'phone'    => $newPhone,
                            'userId'   => $user_id,
                            'type'     => 'staff',
                        ]);
                    }
                }

                // Persist the submitted salary ALWAYS (was gated on basic>0, so a
                // zero-salary staff could never have salary/allowance edits saved).
                if (array_key_exists('basicSalary', $postData) || array_key_exists('Basicsalary', $postData)
                    || array_key_exists('allowances', $postData) || array_key_exists('Allowances', $postData)) {
                    $editBasic = (float) ($postData['basicSalary'] ?? $postData['Basicsalary'] ?? 0);
                    $editAllow = (float) ($postData['allowances']  ?? $postData['Allowances']  ?? 0);
                    // Audit C1: salary is PII — write it to the server-only
                    // staffPrivate mirror (upsert), and null the readable staff
                    // doc's copy so it no longer leaks to parents/students.
                    $this->fs->setEntity('staffPrivate', $user_id, [
                        'staffId'       => $user_id,
                        'salaryDetails' => [
                            'basicSalary' => $editBasic,
                            'Allowances'  => $editAllow,
                            'Net Salary'  => $editBasic + $editAllow,
                        ],
                    ], true);
                    $this->fs->updateEntity('staff', $user_id, ['salaryDetails' => null]);
                    if ($editBasic > 0) {
                        $this->_sync_salary_structure($user_id, $editBasic, $editAllow);
                    }
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
                    // RBAC claim from the CANONICAL role label (primary_role),
                    // not the free-text Position/designation — otherwise a custom
                    // designation ("People Lead") would become the role claim and
                    // could cost the staff admin-panel access. Fall back to the
                    // existing Position only when no role is set.
                    $authRole = !empty($formattedData['primary_role'])
                        ? $this->_role_id_to_label($formattedData['primary_role'])
                        : ($formattedData['Position'] ?? $existingData['Position'] ?? '');
                    $this->firebase->setCanonicalClaims($user_id, [
                        'role'           => $authRole,
                        'school_id'      => $this->school_id,
                        'school_code'    => $this->school_code,
                        'parent_db_key'  => $this->parent_db_key,
                    ]);
                } catch (Exception $e) {
                    log_message('error', 'Staff: Firebase Auth sync failed on edit_staff for ' . $user_id . ': ' . $e->getMessage());
                }

                $editWarnings = array_filter([$phoneWarning, $employmentHistoryWarning]);
                $this->json_success(!empty($editWarnings) ? ['warning' => implode(' | ', $editWarnings)] : []);
            } else {
                $this->json_error('Update failed.', 500);
            }
        } else {
            // Read staff: Firestore-first → RTDB fallback (auto-heals on miss)
            $data['staff_data'] = $this->_get_staff_with_fallback($user_id);

            if (!empty($data['staff_data'])) {
                // Phase 1 ZenX HRMS — additive, capability-gated (see new_staff()
                // for the same pattern and rationale).
                $this->load->helper('terminology');
                $hrmsEnabled = has_capability(HRMS_CORE_CAPABILITY);

                // Reporting Manager (Phase 2C) — resolve the CURRENT manager's
                // status for the view's inactive-manager warning. get_staff_list()
                // (used to populate the picker) already filters to Active-only, so
                // an inactive current manager would never appear there — this is a
                // small necessary read on the render path, not the write path, to
                // surface that fact to HR without changing anything.
                $reportingManagerStatus = null;
                $reportingManagerName   = null;
                $currentRmId = trim((string) ($data['staff_data']['reportingManagerId'] ?? ''));
                if ($currentRmId !== '') {
                    try {
                        $rmDoc = $this->fs->get('staff', $this->fs->docId($currentRmId));
                        if (is_array($rmDoc)) {
                            $reportingManagerStatus = (string) ($rmDoc['status'] ?? '');
                            $reportingManagerName   = (string) ($rmDoc['Name'] ?? $rmDoc['name'] ?? $currentRmId);
                        }
                    } catch (\Throwable $e) {
                        $reportingManagerStatus = null; // non-fatal — warning simply won't show
                    }
                }

                // Priority 3 (Trail 2, 2026-08-19) — the Transfer modal's
                // optional Work Schedule composition step calls the
                // EXISTING Work_schedules::save_assignment() (gated
                // HR:manage), a genuinely different RBAC module from this
                // controller's own SIS:manage gate (established, not a
                // bug — see the RBAC audit above transfer_staff()). Rather
                // than weakening either gate, the option is offered ONLY
                // to an admin who already independently holds HR:manage —
                // has_permission()'s graded ranking means 'manage' already
                // implies 'view', so this single flag is sufficient to
                // gate both previewing the current assignment (via
                // work_schedules/get_data) and saving the new one.
                $hrManage = function_exists('has_permission') && has_permission('HR', 'manage');

                // CROSS-D1 (2026-08-25) — the staff cycle and the academic cycle
                // never meet. Hiring a teacher creates the record, the login and
                // the app access, but `subjectAssignments` is the single source of
                // truth the Teacher app reads for classes, and NOTHING in the staff
                // lifecycle writes one. The result is a working sign-in to an empty
                // product: no classes, no students, no timetable — with nobody told
                // the hire is only half done. `teaching_subjects` captured on this
                // form only filters the eligible-teacher dropdown in Academic; it
                // does not assign anybody to anything.
                //
                // Surfaced here, where the hire is managed, rather than left for a
                // teacher to discover on their phone. Session-scoped on purpose: an
                // assignment from a previous session does not populate this year.
                $teachingNeedsAssignment = false;
                try {
                    $sd      = $data['staff_data'] ?? [];
                    $roleIds = is_array($sd['staff_roles'] ?? null) ? $sd['staff_roles'] : [];
                    $isTeaching = in_array('ROLE_TEACHER', $roleIds, true)
                        || stripos((string) ($sd['role'] ?? ''), 'teacher') !== false;

                    if ($isTeaching) {
                        $assigned = $this->fs->sessionWhere(
                            'subjectAssignments', [['teacherId', '==', $user_id]]);
                        $teachingNeedsAssignment = empty($assigned);
                    }
                } catch (\Throwable $e) {
                    // Never block the profile over an advisory banner.
                    log_message('error', 'Staff::edit_staff subjectAssignments probe failed for '
                        . $user_id . ': ' . $e->getMessage());
                    $teachingNeedsAssignment = false;
                }

                $this->load->view('include/header');
                $this->load->view('edit_staff', [
                    'staff_data'               => $data['staff_data'],
                    'hrms_capability_enabled'  => $hrmsEnabled,
                    'locations'                => $hrmsEnabled ? org_locations_for_school($this->fs, $this->school_id) : [],
                    'reporting_manager_status' => $reportingManagerStatus,
                    'reporting_manager_name'   => $reportingManagerName,
                    'hr_manage'                => $hrManage,
                    'teaching_needs_assignment' => $teachingNeedsAssignment,
                ]);
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
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");

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

        // Employment History Read Surface — resolve the CURRENT Reporting
        // Manager's display name for the Professional tab (Phase 2C wrote
        // reportingManagerId; this is the first UI surface that ever reads
        // it — previously the field existed but was never rendered anywhere).
        // Consistency fix: edit_staff() already resolves and warns on an
        // Inactive current manager (Phase 2C) — this read-only profile view
        // resolved only the name, with no inactive indicator. Same
        // read-only, additive pattern; reportingManagerId itself is never
        // touched, reassigned, or cleared here or anywhere else (locked
        // policy: HR reviews and reassigns manually via Staff Edit).
        $reportingManagerName   = null;
        $reportingManagerStatus = null;
        $rmId = trim((string) ($teacherData['reportingManagerId'] ?? ''));
        if ($rmId !== '') {
            $rmDoc = $this->_get_staff_with_fallback($rmId);
            $reportingManagerName   = !empty($rmDoc) ? (string) ($rmDoc['Name'] ?? $rmDoc['name'] ?? $rmId) : null;
            $reportingManagerStatus = !empty($rmDoc) ? (string) ($rmDoc['status'] ?? $rmDoc['Status'] ?? '') : null;
        }

        $this->load->view('include/header');
        $this->load->view('teacher_profile', [
            'teacher'                => $teacherData,
            'reportingManagerName'   => $reportingManagerName,
            'reportingManagerStatus' => $reportingManagerStatus,
        ]);
        $this->load->view('include/footer');
    }

    /**
     * Employment History Read Surface — read-only. Returns the full
     * staffEmploymentEvents log for one staff member plus derived episode
     * boundaries, gated at two levels within the SAME response:
     *   - SIS/view (this action's own gate, identical to teacher_profile()):
     *     event existence/type/effectiveFrom/fromValue/toValue/changed_by,
     *     and Reporting Manager history — none of this is sensitive, matches
     *     what Position/Department/EmploymentType already show same-tab.
     *   - SIS/manage (checked WITHIN this action via has_permission(), never
     *     denying the request — only shaping the payload): the `reason` on
     *     each event, plus the entire `staffSeparations` linked-record fetch,
     *     mirroring the exact read boundary already locked for
     *     staffSeparations' own Firestore rule (Phase 3B.1) — a view-only
     *     caller must never receive separation-reason text in the response
     *     body at all, not merely have it hidden client-side.
     *
     * No write. No Employment History schema change. Reuses the existing
     * staffEmploymentEvents/staffSeparations collections and their existing
     * rules verbatim.
     */
    public function get_employment_history($id)
    {
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");
        header('Content-Type: application/json');

        if (!$id || !preg_match('/^[A-Za-z0-9_]+$/', $id)) {
            $this->json_error('Invalid staff id.', 400);
            return;
        }

        $canViewSensitive = function_exists('has_permission') && has_permission('SIS', 'manage');

        try {
            $rows = $this->fs->schoolWhere('staffEmploymentEvents', [['staffId', '==', $id]], 'changed_at', 'ASC');
        } catch (\Throwable $e) {
            log_message('error', "get_employment_history query failed for {$id}: " . $e->getMessage());
            $this->json_error('Could not load employment history.', 500);
            return;
        }

        $events       = [];
        $changedByIds = [];
        foreach ((array) $rows as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : [];
            if (empty($d)) continue;
            $evt = [
                'eventType'     => (string) ($d['eventType'] ?? ''),
                'attribute'     => (string) ($d['attribute'] ?? ''),
                'fromValue'     => (string) ($d['fromValue'] ?? ''),
                'toValue'       => (string) ($d['toValue'] ?? ''),
                'effectiveFrom' => (string) ($d['effectiveFrom'] ?? ''),
                'changed_by'    => (string) ($d['changed_by'] ?? ''),
                'changed_at'    => (string) ($d['changed_at'] ?? ''),
                'metadata'      => is_array($d['metadata'] ?? null) ? $d['metadata'] : [],
            ];
            if ($canViewSensitive) {
                $evt['reason'] = (string) ($d['reason'] ?? '');
            }
            if ($evt['changed_by'] !== '') $changedByIds[$evt['changed_by']] = true;
            $events[] = $evt;
        }

        // Batched changed_by -> display name resolution (one lookup per
        // DISTINCT actor across the whole event set, never per-card).
        $changedByNames = [];
        foreach (array_keys($changedByIds) as $actorId) {
            try {
                $actorDoc = $this->_get_staff_with_fallback($actorId);
                $changedByNames[$actorId] = !empty($actorDoc) ? (string) ($actorDoc['Name'] ?? $actorDoc['name'] ?? $actorId) : $actorId;
            } catch (\Throwable $e) {
                $changedByNames[$actorId] = $actorId; // best-effort — never fails the whole response
            }
        }

        // Episode derivation — JOINED/REHIRED open, SEPARATED closes,
        // REACTIVATED continues the currently-open episode. Pure read-time
        // derivation over the already-fetched event list — no schema change,
        // no new collection (Phase 3B.1's own locked decision, reaffirmed).
        //
        // EXTRACTED 2026-08-22 into Staff_employment_history_service so the
        // Full & Final settlement can resolve service duration from the SAME
        // derivation this screen shows. Duplicating it would have created a
        // second source of truth about how long someone worked here — the one
        // number a gratuity calculation turns on. Behaviour is unchanged; this
        // is a move, not a rewrite.
        $this->load->library('staff_employment_history_service');
        $episodes = Staff_employment_history_service::episodesFromEvents($events);

        $separations = [];
        if ($canViewSensitive) {
            try {
                $sepRows = $this->fs->schoolWhere('staffSeparations', [['staffId', '==', $id]]);
                foreach ((array) $sepRows as $row) {
                    $d = is_array($row['data'] ?? null) ? $row['data'] : [];
                    if (empty($d)) continue;
                    $separations[] = [
                        'separationType'        => (string) ($d['separationType'] ?? ''),
                        'reason'                => (string) ($d['reason'] ?? ''),
                        'status'                => (string) ($d['status'] ?? ''),
                        'actualLastWorkingDate' => (string) ($d['actualLastWorkingDate'] ?? ''),
                        'employmentEventRef'    => (string) ($d['employmentEventRef'] ?? ''),
                    ];
                }
            } catch (\Throwable $e) {
                log_message('error', "get_employment_history staffSeparations query failed for {$id}: " . $e->getMessage());
                // Non-fatal — the general timeline still renders without separation detail.
            }
        }

        $this->json_success([
            'events'           => $events,
            'episodes'         => $episodes,
            'changedByNames'   => $changedByNames,
            'separations'      => $separations,
            'canViewSensitive' => $canViewSensitive,
            'hasHistory'       => !empty($events),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function search_teacher()
    {
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");
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
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");
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
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "edit");
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
            // Phase 16.5 (SEC-2 completion) — use the canonical section
            // doc-id. This hand-built form omitted the session, so once
            // create_section() was corrected in Phase 16.4 this addressed a
            // DIFFERENT document: class-teacher and subject-teacher
            // assignments would have been written to an orphaned record that
            // no section reader ever looks at, silently losing them.
            $sectionDocId = $this->fs->sectionDocId($classKey, $sectionLetter);
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

        // Sync Firebase Auth claims (best-effort).
        // Use the staff member's actual role, not a hard-coded 'Teacher' — a
        // duty assignment must not overwrite the RBAC role of e.g. a Principal
        // who also teaches a class.
        $dutyRoleClaim = $staffDoc['Position'] ?? $staffDoc['role'] ?? 'Teacher';
        try {
            $this->firebase->setCanonicalClaims($teacherID, [
                'role'           => $dutyRoleClaim,
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
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "edit");
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
            // Phase 16.5 (SEC-2 completion) — use the canonical section
            // doc-id. This hand-built form omitted the session, so once
            // create_section() was corrected in Phase 16.4 this addressed a
            // DIFFERENT document: class-teacher and subject-teacher
            // assignments would have been written to an orphaned record that
            // no section reader ever looks at, silently losing them.
            $sectionDocId = $this->fs->sectionDocId($classKey, $sectionLetter);
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
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");
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
        $this->_require_role(self::VIEW_ROLES, 'get_staff_roles', "SIS", "view");
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $roles = $schoolDoc['staffRoles'] ?? [];
        if (!is_array($roles)) $roles = [];
        $this->json_success(['roles' => $roles]);
    }

    /**
     * Lean catalogue for the import preview's Department→Role dropdowns:
     * active departments (sorted, each with its role_ids[]) + the flat role list
     * (id/label/department). No staff scan — mirrors the Departments & Roles module.
     */
    public function import_catalogue()
    {
        $this->_require_role(self::VIEW_ROLES, 'import_catalogue', 'SIS', 'view');
        $this->output->set_content_type('application/json');

        $school   = $this->fs->get('schools', $this->school_id);
        $rolesRaw = is_array($school['staffRoles'] ?? null) && !empty($school['staffRoles'])
            ? $school['staffRoles'] : self::DEFAULT_STAFF_ROLES;

        $roles = [];
        foreach ($rolesRaw as $rid => $r) {
            $r = (array) $r;
            $roles[] = [
                'id'         => (string) $rid,
                'label'      => (string) ($r['label'] ?? $rid),
                'department' => (string) ($r['department'] ?? $r['category'] ?? ''),
            ];
        }

        $draw = is_array($school['departments'] ?? null) ? $school['departments'] : [];
        uasort($draw, static fn($a, $b) => ((int) (((array) $a)['sort_order'] ?? 99)) <=> ((int) (((array) $b)['sort_order'] ?? 99)));
        $depts = [];
        foreach ($draw as $d) {
            $d = (array) $d;
            if (($d['status'] ?? 'Active') !== 'Active') continue;
            $nm = trim((string) ($d['name'] ?? ''));
            if ($nm === '') continue;
            $depts[] = ['name' => $nm, 'role_ids' => array_values(is_array($d['role_ids'] ?? null) ? $d['role_ids'] : [])];
        }

        echo json_encode(['status' => 'success', 'departments' => $depts, 'roles' => $roles]);
    }

    /**
     * POST /staff/save_staff_role  — RETIRED (single-writer consolidation, 2026-07-07)
     *
     * Staff-role definitions are now written in exactly ONE place: the
     * "Departments & Roles" module (Org::save_role). This former twin writer of
     * schools.staffRoles is retired to eliminate the two-writer drift (it used a
     * different category list, no in-use guard and no department-scrub, so it
     * could fork the catalogue). No UI ever posted here; kept as a loud, safe
     * stub so any stale bookmark/integration gets guidance instead of silently
     * corrupting the catalogue. Canonical writer: Org::save_role.
     */
    public function save_staff_role()
    {
        $this->json_error('Staff roles are now managed in Departments & Roles. Open that screen to add or edit a staff role.', 410);
    }

    /**
     * POST /staff/delete_staff_role  — RETIRED (single-writer consolidation, 2026-07-07)
     * Deletions now go through Org::delete_role, which also scrubs the role from
     * every department's role_ids[] and blocks deletion while staff still hold it.
     */
    public function delete_staff_role()
    {
        $this->json_error('Staff roles are now managed in Departments & Roles. Open that screen to delete a staff role.', 410);
    }

    /**
     * POST /staff/get_staff_by_role
     * Returns staff list filtered by a specific role.
     */
    public function get_staff_by_role()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_staff_by_role', "SIS", "view");
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
        $this->_require_role(self::MANAGE_ROLES, 'migrate_staff_roles', "SIS", "manage");

        // Query all school staff from Firestore
        $staffDocs = $this->fs->schoolWhere('staff', []);

        $migrated   = 0;
        $skipped    = 0;
        $errors     = 0;
        $unresolved = 0;

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
            // Use the NO-DEFAULT matcher: only assign a role when the Position
            // confidently maps to one. Never silently default to Teacher — that
            // would mis-label Principals/Receptionists/Nurses AND rewrite their
            // Firebase auth claim to 'Teacher'. Unmatched staff are left for
            // manual assignment (reported as "unresolved").
            $roleIds = $this->_match_roles_no_default($position);
            if (empty($roleIds)) { $unresolved++; continue; }
            $primary = $roleIds[0];

            $ok = $this->fs->updateEntity('staff', $sid, [
                'staff_roles'  => $roleIds,
                'primary_role' => $primary,
            ]);

            if ($ok) {
                $migrated++;
                // Refresh the Auth role claim too, so a migrated non-teacher
                // (Accountant, Clerk, …) stops carrying a stale 'Teacher' claim.
                try {
                    $this->firebase->setCanonicalClaims($sid, [
                        'role'          => $this->_role_id_to_label($primary),
                        'school_id'     => $this->school_id,
                        'school_code'   => $this->school_code,
                        'parent_db_key' => $this->parent_db_key,
                    ]);
                } catch (\Exception $e) {
                    log_message('error', "migrate_staff_roles claim refresh failed for {$sid}: " . $e->getMessage());
                }
            } else {
                $errors++;
            }
        }

        $umsg = $unresolved > 0 ? ", {$unresolved} need manual role assignment" : '';
        $this->json_success([
            'message'    => "{$migrated} staff migrated, {$skipped} already had roles{$umsg}, {$errors} errors.",
            'migrated'   => $migrated,
            'skipped'    => $skipped,
            'unresolved' => $unresolved,
            'errors'     => $errors,
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
            $staff = (is_array($fsDoc) && !empty($fsDoc)) ? $fsDoc : [];
            // Audit C1: PII lives on the server-only staffPrivate mirror — merge it
            // back so the edit form + teacher profile still see PAN/salary/bank.
            return $staff ? $this->_merge_staff_private($staff, $staffId) : $staff;
        } catch (Exception $e) {
            log_message('error', "_get_staff Firestore read failed [{$staffId}]: " . $e->getMessage());
            return [];
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Audit C1 — staffPrivate PII split helpers
    // ══════════════════════════════════════════════════════════════════════

    /**
     * WRITE side. Move the PII cluster off a staff-doc payload and onto the
     * server-only `staffPrivate/{schoolId}_{staffId}` mirror. Mutates
     * $staffDoc in place: every PII key it carries is copied to the private doc
     * (via setEntity, upsert/merge) and then NULLED on the readable doc — the
     * same "set to null" scrub idiom the credential cleanup uses, so the field
     * is actively cleared on a merge-update, never left to leak.
     *
     * Only keys PRESENT on $staffDoc are moved/nulled — an edit that doesn't
     * submit a given field never blanks the private doc's copy of it.
     */
    private function _split_staff_private(string $staffId, array &$staffDoc): void
    {
        if ($staffId === '') return;
        $private = [];
        foreach (self::PII_KEYS as $k) {
            if (!array_key_exists($k, $staffDoc)) continue;
            if ($staffDoc[$k] !== null) $private[$k] = $staffDoc[$k];
            $staffDoc[$k] = null; // scrub from the same-school-readable doc
        }
        if (empty($private)) return;
        $private['staffId'] = $staffId;
        try {
            $this->fs->setEntity('staffPrivate', $staffId, $private, true);
        } catch (\Throwable $e) {
            log_message('error', "staffPrivate write failed [{$staffId}]: " . $e->getMessage());
        }
    }

    /**
     * READ side. Fetch the server-only staffPrivate mirror and return only its
     * PII cluster (non-null values), or [] when absent (un-migrated staff keep
     * their inline values, so nothing overrides and nothing is lost).
     */
    private function _load_staff_private(string $staffId): array
    {
        if ($staffId === '') return [];
        try {
            $doc = $this->fs->getEntity('staffPrivate', $staffId);
        } catch (\Throwable $e) {
            log_message('error', "staffPrivate read failed [{$staffId}]: " . $e->getMessage());
            return [];
        }
        if (!is_array($doc)) return [];
        $out = [];
        foreach (self::PII_KEYS as $k) {
            if (array_key_exists($k, $doc) && $doc[$k] !== null) $out[$k] = $doc[$k];
        }
        return $out;
    }

    /** Merge the staffPrivate PII cluster onto a staff profile (private wins). */
    private function _merge_staff_private(array $profile, string $staffId): array
    {
        $priv = $this->_load_staff_private($staffId);
        return $priv ? array_merge($profile, $priv) : $profile;
    }

    // ════════════════════════════════════════════════════════════════════
    //  STAFF HR ADVANCED CAPABILITY PROGRAMME — Phase A: Document Management
    //  (2026-08-18)
    // ════════════════════════════════════════════════════════════════════
    //
    // Reuses the canonical Document Service exactly as it was designed to
    // be reused ("Student/HR/Hostel/... can adopt it later WITHOUT
    // redesign") — no new collection, no new library, config-only addition
    // (application/config/document_service.php). The endpoint shape below
    // mirrors Sis.php's proven student-document pattern
    // (upload_student_document/verify_student_document/
    // replace_student_document/_delete_managed_student_document) field for
    // field, so the two document surfaces stay structurally identical
    // rather than diverging into two different conventions for the same
    // underlying service.
    //
    // documentRefs lives on the PUBLIC `staff` doc — same placement the
    // proven student precedent already uses for equally sensitive document
    // TYPES (birth certificate, Aadhaar). The pointer array itself
    // (documentId/type/label/verificationStatus) is not the sensitive
    // payload; the actual file bytes are Storage-gated and the `documents`/
    // `documentLinks` Firestore collections carry their own access rules,
    // unchanged by this phase. Upload/replace/verify/delete stay HR-managed
    // (MANAGE_ROLES) — this is an admin-panel HR record, not a staff
    // self-service portal (no self-upload path exists elsewhere in this
    // controller to extend consistently).

    /** GET — list a staff member's uploaded documents. */
    public function get_staff_documents()
    {
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");
        $staffId = $this->safe_path_segment(trim((string) $this->input->get('staff_id')), 'staff_id');
        if ($staffId === '') return $this->json_error('Invalid staff ID.');

        $staff = $this->_get_staff_with_fallback($staffId);
        if (empty($staff)) return $this->json_error('Staff member not found.');

        $refs = array_values(array_filter((array) ($staff['documentRefs'] ?? []), 'is_array'));
        $this->json_success(['documents' => $refs]);
    }

    /** POST (multipart) — upload or replace-in-place a staff document. */
    public function upload_staff_document()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $staffId = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        $type    = trim((string) $this->input->post('type'));
        $custom  = trim((string) $this->input->post('label'));
        if ($staffId === '' || $type === '') return $this->json_error('Staff ID and document type are required.');
        if (empty($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->json_error('Please choose a file to upload.');
        }

        $staff = $this->_get_staff_with_fallback($staffId);
        if (empty($staff)) return $this->json_error('Staff member not found.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_id);
        $actor = (string) ($this->admin_id ?? 'system');
        $label = $custom !== '' ? $custom : '';
        $tmp   = (string) $_FILES['document']['tmp_name'];
        $orig  = (string) ($_FILES['document']['name'] ?? 'file');
        $fileBytes = (int) ($_FILES['document']['size'] ?? 0);

        // Predefined single-slot: replace the existing document of this type
        // (mirrors Sis::upload_student_document()'s exact rule).
        $existingId = '';
        if ($type !== 'additional') {
            foreach ((array) ($staff['documentRefs'] ?? []) as $r) {
                if (is_array($r) && (string) ($r['type'] ?? '') === $type) { $existingId = (string) ($r['documentId'] ?? ''); break; }
            }
        }

        if ($existingId !== '') {
            $res = $this->docsvc->replace($existingId, $tmp, $orig, $actor);
            if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Upload failed.');
            $this->_staff_sync_docref($staffId, $res['document'], $label, $type, $fileBytes);
            log_audit('Staff', 'document_replace', $staffId, "Document replaced: " . ($label ?: $type));
            return $this->json_success(['message' => 'Document updated.', 'documentId' => $existingId, 'url' => $res['document']['downloadUrl'] ?? '']);
        }

        $res = $this->docsvc->upload($tmp, $orig, $type, ['uploadedBy' => $actor, 'label' => $label]);
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Upload failed.');
        $doc = $res['document'];
        $this->docsvc->link($doc['documentId'], 'staff', $staffId, $type, $actor);
        $this->_staff_sync_docref($staffId, $doc, $label, $type, $fileBytes);
        log_audit('Staff', 'document_upload', $staffId, "Document uploaded: " . ($label ?: $type));
        return $this->json_success(['message' => 'Document uploaded.', 'documentId' => $doc['documentId'], 'url' => $doc['downloadUrl'] ?? '']);
    }

    /** POST — set a staff document's verification status (verified|rejected|pending). */
    public function verify_staff_document()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $staffId = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        $docId   = trim((string) $this->input->post('document_id'));
        $status  = trim((string) $this->input->post('status'));
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId)) return $this->json_error('Invalid document id.');
        if (!in_array($status, ['verified', 'rejected', 'pending'], true)) return $this->json_error('Invalid status.');

        $staff = $this->_get_staff_with_fallback($staffId);
        if (empty($staff)) return $this->json_error('Staff member not found.');
        if (!$this->_staff_doc_owned($staff, $docId)) return $this->json_error('Document does not belong to this staff member.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_id);
        $res = $this->docsvc->verify($docId, $status, (string) ($this->admin_id ?? 'system'));
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Verification failed.');

        $refs = array_map(function ($r) use ($docId, $status) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) $r['verificationStatus'] = $status;
            return $r;
        }, (array) ($staff['documentRefs'] ?? []));
        $this->fs->updateEntity('staff', $staffId, ['documentRefs' => array_values($refs)]);
        log_audit('Staff', 'document_verify', $staffId, "Document {$docId} marked {$status}");
        // NOTE: do NOT return a 'status' key — json_success() already sets
        // status:'success' (same gotcha Sis::verify_student_document() documents).
        return $this->json_success(['verificationStatus' => $status]);
    }

    /** POST — delete a staff document (ownership-guarded, reference-counted purge via the Document Service). */
    public function delete_staff_document()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $staffId = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        $docId   = trim((string) $this->input->post('document_id'));
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId)) return $this->json_error('Invalid document id.');

        $staff = $this->_get_staff_with_fallback($staffId);
        if (empty($staff)) return $this->json_error('Staff member not found.');
        if (!$this->_staff_doc_owned($staff, $docId)) return $this->json_error('Document does not belong to this staff member.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_id);
        $res = $this->docsvc->delete($docId, 'staff', $staffId, (string) ($this->admin_id ?? 'system'), false);
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Delete failed.');

        $refs = [];
        foreach ((array) ($staff['documentRefs'] ?? []) as $r) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) continue;
            $refs[] = $r;
        }
        $this->fs->updateEntity('staff', $staffId, ['documentRefs' => array_values($refs)]);
        log_audit('Staff', 'document_delete', $staffId, "Document {$docId} deleted");
        return $this->json_success(['message' => 'Document deleted.']);
    }

    /** Is $docId one of $staff's own documentRefs? (ownership guard, mirrors Sis::_student_doc_owned()) */
    private function _staff_doc_owned(array $staff, string $docId): bool
    {
        foreach ((array) ($staff['documentRefs'] ?? []) as $r) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) return true;
        }
        return false;
    }

    /** Upsert one documentRefs[] pointer entry on the staff doc (predefined types: one slot; additional: appended). */
    private function _staff_sync_docref(string $staffId, array $doc, string $label, string $type, int $fileBytes): void
    {
        $staff = $this->_get_staff_with_fallback($staffId);
        $refs  = array_values(array_filter((array) ($staff['documentRefs'] ?? []), 'is_array'));

        $entry = [
            'documentId'         => (string) $doc['documentId'],
            'type'               => $type,
            'label'              => $label !== '' ? $label : (string) ($doc['label'] ?? $type),
            'sizeBytes'          => $fileBytes,
            'verificationStatus' => (string) ($doc['verificationStatus'] ?? 'pending'),
            'uploadedAt'         => (string) ($doc['uploadedAt'] ?? date('c')),
        ];

        $found = false;
        foreach ($refs as $i => $r) {
            if ((string) ($r['documentId'] ?? '') === $entry['documentId']) { $refs[$i] = $entry; $found = true; break; }
        }
        if (!$found) $refs[] = $entry;

        $this->fs->updateEntity('staff', $staffId, ['documentRefs' => $refs]);
    }

    // ════════════════════════════════════════════════════════════════════
    //  STAFF HR ADVANCED CAPABILITY PROGRAMME — Phase I: Promotion
    //  (2026-08-18)
    // ════════════════════════════════════════════════════════════════════
    //
    // DELIBERATELY does NOT add a new event type to
    // Staff_employment_history_service. That service's own vocabulary
    // (ATTRIBUTES/EMPLOYMENT_STATUS_EVENT_TYPES) is explicitly commented
    // "Phase 1+2C+3B.1 locked vocabulary — do not extend without a new
    // authorized phase" — a generic "Phase I — Promotion" authorization is
    // not, on its own, the deliberate sign-off that comment calls for. The
    // underlying safety property a Promotion feature actually needs
    // (position/department changes are tracked as effective-dated
    // episodes, never a silent overwrite) is ALREADY satisfied by the
    // existing, unmodified POSITION_CHANGED/DEPARTMENT_CHANGED generic
    // attribute-change events — confirmed by reading edit_staff()'s
    // existing recordChanges() call site. What was actually missing was a
    // *business-meaningful* action: a dedicated endpoint that captures WHY
    // (reason) and WHEN (effective date) a change happened, using
    // recordChanges()'s own $reason/$effectiveFrom parameters — both
    // already existed on that method's signature, just never populated by
    // the generic edit form. This is additive use of an existing,
    // untouched service, not an architecture change.
    //
    // Explicitly does NOT touch salary/Payroll — per the standing
    // instruction, promotion must never silently trigger a Payroll change.
    // A salary revision remains its own, separate, explicit action via the
    // existing Salary Structure screen.

    /** POST — record a promotion (position/department/reporting-manager change with a reason). */
    public function promote_staff()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");

        $staffId          = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        $newPosition      = trim((string) $this->input->post('new_position'));
        $newDepartment    = trim((string) $this->input->post('new_department'));
        $reportingManagerSubmitted = array_key_exists('new_reporting_manager_id', $_POST);
        $newReportingMgr  = trim((string) $this->input->post('new_reporting_manager_id'));
        $effectiveFrom    = trim((string) $this->input->post('effective_from'));
        $reasonNote       = trim((string) $this->input->post('reason'));

        if ($staffId === '') return $this->json_error('Staff ID is required.');
        if ($newPosition === '' && $newDepartment === '' && !$reportingManagerSubmitted) {
            return $this->json_error('Provide a new position, department, and/or reporting manager.');
        }
        if ($effectiveFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
            return $this->json_error('Effective date must be in YYYY-MM-DD format.');
        }

        $staff = $this->_get_staff_with_fallback($staffId);
        if (empty($staff)) return $this->json_error('Staff member not found.');
        $rawStatus = (string) ($staff['status'] ?? $staff['Status'] ?? 'Active');
        if (strtolower(trim($rawStatus)) !== 'active') {
            return $this->json_error('Cannot record a promotion for an inactive staff member.');
        }

        $oldPosition   = (string) ($staff['position'] ?? $staff['Position'] ?? '');
        $oldDepartment = (string) ($staff['department'] ?? $staff['Department'] ?? '');
        $oldManagerId  = (string) ($staff['reportingManagerId'] ?? '');

        $this->load->library('staff_employment_history_service');
        $ehSvc = $this->staff_employment_history_service
            ->init($this->fs, $this->school_id, (string) ($this->admin_id ?? ''));

        $fieldUpdate = [];
        $ehChanges   = [];
        if ($newPosition !== '' && $newPosition !== $oldPosition) {
            $fieldUpdate['position'] = $newPosition;
            $fieldUpdate['Position'] = $newPosition; // dual-cased, matching every other write site in this controller
            $ehChanges['position']  = $newPosition;
        }
        if ($newDepartment !== '' && $newDepartment !== $oldDepartment) {
            $fieldUpdate['department'] = $newDepartment;
            $fieldUpdate['Department'] = $newDepartment;
            $ehChanges['department']  = $newDepartment;
        }
        if ($reportingManagerSubmitted && $newReportingMgr !== $oldManagerId) {
            // Same validation edit_staff() already applies to a manager
            // change — reused, not reimplemented (self-reference/inactive/
            // circular-chain guards).
            $rmValidation = $ehSvc->validateManagerAssignment($staffId, $newReportingMgr);
            if (!$rmValidation['ok']) {
                return $this->json_error('Invalid Reporting Manager: ' . $rmValidation['reason'], 400);
            }
            $fieldUpdate['reportingManagerId'] = $newReportingMgr;
            $ehChanges['reportingManagerId']  = $newReportingMgr;
        }
        if (empty($ehChanges)) {
            return $this->json_error('At least one of position, department, or reporting manager must differ from the current value.');
        }

        $fieldUpdate['updated_at'] = date('c');
        $ok = $this->fs->updateEntity('staff', $staffId, $fieldUpdate);
        if (!$ok) return $this->json_error('Could not update the staff record. Please try again.');

        $reason = 'Promotion' . ($reasonNote !== '' ? ": {$reasonNote}" : '')
            . " ({$oldPosition}" . ($oldDepartment !== '' ? "/{$oldDepartment}" : '') . " -> "
            . ($newPosition !== '' ? $newPosition : $oldPosition)
            . ($newDepartment !== '' ? "/{$newDepartment}" : '') . ')';

        $historyWarning = null;
        try {
            $ehRes = $ehSvc->recordChanges($staffId, $ehChanges, $effectiveFrom, $reason);
            if (empty($ehRes['ok'])) {
                $historyWarning = 'Promotion recorded, but employment history could not be updated ('
                    . ($ehRes['reason'] ?? 'unknown') . ').';
                log_message('error', "Staff::promote_staff employment history recordChanges failed staff={$staffId} reason=" . ($ehRes['reason'] ?? 'unknown'));
            }
        } catch (\Throwable $ehErr) {
            $historyWarning = 'Promotion recorded, but employment history could not be updated.';
            log_message('error', 'Staff::promote_staff employment history threw for ' . $staffId . ': ' . $ehErr->getMessage());
        }

        log_audit('Staff', 'promotion', $staffId, $reason);

        $resp = [
            'message'          => 'Promotion recorded.',
            'old_position'     => $oldPosition,
            'new_position'     => $newPosition !== '' ? $newPosition : $oldPosition,
            'old_department'   => $oldDepartment,
            'new_department'   => $newDepartment !== '' ? $newDepartment : $oldDepartment,
            'old_manager_id'   => $oldManagerId,
            'new_manager_id'   => $reportingManagerSubmitted ? $newReportingMgr : $oldManagerId,
        ];
        if ($historyWarning !== null) $resp['warning'] = $historyWarning;
        return $this->json_success($resp);
    }

    // ════════════════════════════════════════════════════════════════════
    //  STAFF HR ADVANCED CAPABILITY PROGRAMME — Phase 2: Notice Period
    //  (2026-08-18)
    // ════════════════════════════════════════════════════════════════════
    //
    // New collection: staffNoticePeriods/{schoolId}_{staffId} — ONE active
    // notice record per staff member (same "one active thing, keyed by
    // subject" shape as leaveYearClosures). Deliberately upstream of, and
    // never overlapping, the EXISTING, UNMODIFIED Staff::set_status()
    // separation path — this phase never calls set_status() internally
    // (it reads $_POST directly and is HTTP-response-shaped, not a clean
    // internal API; wrapping it would mean either duplicating its logic or
    // fragile superglobal manipulation, both explicitly out of bounds).
    // Instead, the finalize step is a thin, AFTER-the-fact bookkeeping
    // action: HR calls the existing set_status endpoint exactly as the
    // pre-existing separation modal already does, then calls
    // complete_notice_period() to close out the notice record — the two
    // features compose at the workflow level, set_status() itself is
    // never touched.
    //
    // NOTICE DURATION POLICY — explicitly a technical default, not an
    // invented business rule. schools/{id}.noticePeriodDays is an OPTIONAL
    // per-employment-type map (falls back to a single 'default' key, and
    // finally to the hardcoded 30 below if the school has configured
    // nothing at all) — the same "ships with a sane, operator-editable
    // default" shape every other configurable policy in this codebase
    // already uses (leave type day counts, etc.). The number itself is
    // NOT asserted as correct policy for any real school; it is
    // deliberately overridable per-tenant and even per-submission (HR can
    // adjust notice_start_date/see the computed date before submitting).
    // No money is calculated anywhere in this phase.
    private const NOTICE_PERIOD_DEFAULT_DAYS = 30;
    private const NOTICE_PERIOD_COL = 'staffNoticePeriods';
    private const NOTICE_ACTIVE_STATUSES = ['pending', 'approved', 'waived', 'reduced'];

    private function _notice_doc_id(string $staffId): string
    {
        return "{$this->school_id}_{$staffId}";
    }

    /**
     * D-005 — cancel a separation that a rehire has just made void.
     *
     * Called only from the reactivation branch of set_status(). Terminal
     * notices (completed / rejected / withdrawn / cancelled) are left exactly
     * as they are, so this can only ever RELEASE the single notice slot, never
     * rewrite a concluded one.
     *
     * Deliberately best-effort: a rehire must not fail because the notice
     * record could not be read or written. The worst case is the pre-existing
     * behaviour — a stuck record — which is logged rather than silently
     * swallowed.
     */
    private function _cancel_notice_on_rehire(string $staffId, string $actorId): void
    {
        if ($staffId === '') return;

        $docId = $this->_notice_doc_id($staffId);

        try {
            $notice = $this->fs->get(self::NOTICE_PERIOD_COL, $docId);
        } catch (\Throwable $e) {
            log_message('error', "Staff::_cancel_notice_on_rehire read failed for {$staffId}: " . $e->getMessage());
            return;
        }

        if (!is_array($notice) || empty($notice)) return;

        $status = (string) ($notice['status'] ?? '');
        if (!in_array($status, self::NOTICE_ACTIVE_STATUSES, true)) return;   // terminal — untouched

        try {
            $ok = $this->fs->update(self::NOTICE_PERIOD_COL, $docId, [
                'status'           => 'cancelled',
                'cancelledAt'      => date('c'),
                'cancelledBy'      => $actorId,
                'cancelledReason'  => 'rehire',
                'cancelledFrom'    => $status,   // what it was, for the audit trail
                'updatedAt'        => date('c'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', "Staff::_cancel_notice_on_rehire write failed for {$staffId}: " . $e->getMessage());
            return;
        }

        if ($ok) {
            log_audit(
                'Staff',
                'notice_period_cancelled_on_rehire',
                $staffId,
                "Notice period cancelled by rehire (was '{$status}')"
            );
        }
    }

    /** Resolve the configured notice-period duration for a staff member's employment type (falls back to 'default', then the hardcoded constant). */
    private function _notice_period_days(string $employmentType): int
    {
        try {
            $school = $this->fs->get('schools', $this->school_id);
            $map = is_array($school['noticePeriodDays'] ?? null) ? $school['noticePeriodDays'] : [];
            if ($employmentType !== '' && isset($map[$employmentType]) && (int) $map[$employmentType] > 0) {
                return (int) $map[$employmentType];
            }
            if (isset($map['default']) && (int) $map['default'] > 0) {
                return (int) $map['default'];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Staff::_notice_period_days config read failed: ' . $e->getMessage());
        }
        return self::NOTICE_PERIOD_DEFAULT_DAYS;
    }

    /** GET — the current (if any) notice-period record for a staff member. */
    public function get_notice_period()
    {
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");
        $staffId = $this->safe_path_segment(trim((string) $this->input->get('staff_id')), 'staff_id');
        if ($staffId === '') return $this->json_error('Invalid staff ID.');

        try {
            $doc = $this->fs->get(self::NOTICE_PERIOD_COL, $this->_notice_doc_id($staffId));
        } catch (\Throwable $e) {
            log_message('error', 'Staff::get_notice_period read failed: ' . $e->getMessage());
            return $this->json_error('Could not load notice period status.');
        }
        return $this->json_success(['notice' => is_array($doc) ? $doc : null]);
    }

    /** POST — HR submits (on behalf of) a resignation, opening a notice-period record. */
    public function submit_resignation()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");

        $staffId        = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        $noticeStartRaw = trim((string) $this->input->post('notice_start_date'));
        $reason         = trim((string) $this->input->post('reason'));

        if ($staffId === '') return $this->json_error('Staff ID is required.');
        $noticeStart = $noticeStartRaw !== '' ? $noticeStartRaw : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $noticeStart)) {
            return $this->json_error('Notice start date must be in YYYY-MM-DD format.');
        }

        $staff = $this->_get_staff_with_fallback($staffId);
        if (empty($staff)) return $this->json_error('Staff member not found.');
        $rawStatus = (string) ($staff['status'] ?? $staff['Status'] ?? 'Active');
        if (strtolower(trim($rawStatus)) !== 'active') {
            return $this->json_error('Cannot open a notice period for an already-inactive staff member.');
        }

        $docId = $this->_notice_doc_id($staffId);
        try {
            $existing = $this->fs->get(self::NOTICE_PERIOD_COL, $docId);
        } catch (\Throwable $e) {
            $existing = null;
        }
        if (is_array($existing) && in_array((string) ($existing['status'] ?? ''), self::NOTICE_ACTIVE_STATUSES, true)) {
            return $this->json_error('An active notice period already exists for this staff member. Withdraw or decide it first.');
        }

        $employmentType = (string) ($staff['employmentType'] ?? $staff['Employment Type'] ?? '');
        $durationDays = $this->_notice_period_days($employmentType);
        $expectedLwd = date('Y-m-d', strtotime("{$noticeStart} +{$durationDays} days"));

        $now = date('c');
        $data = [
            'schoolId'               => $this->school_id,
            'staffId'                => $staffId,
            'staffName'              => (string) ($staff['Name'] ?? $staff['name'] ?? $staffId),
            'status'                 => 'pending',
            'submittedBy'            => (string) ($this->admin_id ?? ''),
            'submittedAt'            => $now,
            'noticeStartDate'        => $noticeStart,
            'noticeDurationDays'     => $durationDays,
            'expectedLastWorkingDay' => $expectedLwd,
            'reason'                 => $reason,
            'decidedBy'              => null,
            'decidedAt'              => null,
            'decision'               => null,
            'decisionNote'           => null,
            'actualLastWorkingDay'   => null,
            'separationCompleted'    => false,
            'createdAt'              => $now,
            'updatedAt'              => $now,
        ];

        try {
            $ok = $this->fs->set(self::NOTICE_PERIOD_COL, $docId, $data, true);
        } catch (\Throwable $e) {
            log_message('error', 'Staff::submit_resignation write failed: ' . $e->getMessage());
            $ok = false;
        }
        if (!$ok) return $this->json_error('Could not open the notice period. Please try again.');

        log_audit('Staff', 'notice_period_submitted', $staffId, "Notice period opened, expected last working day {$expectedLwd}");
        return $this->json_success(['message' => 'Notice period recorded.', 'notice' => $data]);
    }

    /** POST — HR decides a pending notice period: approve as-is, waive (release early), reduce (shorten), or reject. */
    public function decide_notice_period()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");

        $staffId  = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        $decision = trim((string) $this->input->post('decision'));
        $actualLwdRaw = trim((string) $this->input->post('actual_last_working_day'));
        $note     = trim((string) $this->input->post('decision_note'));

        if ($staffId === '') return $this->json_error('Staff ID is required.');
        if (!in_array($decision, ['approve', 'waive', 'reduce', 'reject'], true)) {
            return $this->json_error('Decision must be approve, waive, reduce, or reject.');
        }

        $docId = $this->_notice_doc_id($staffId);
        try {
            $notice = $this->fs->get(self::NOTICE_PERIOD_COL, $docId);
        } catch (\Throwable $e) {
            $notice = null;
        }
        if (!is_array($notice) || empty($notice)) return $this->json_error('No notice period found for this staff member.');
        if ((string) ($notice['status'] ?? '') !== 'pending') {
            return $this->json_error('This notice period has already been decided.');
        }

        $expectedLwd = (string) ($notice['expectedLastWorkingDay'] ?? '');
        $actualLwd = $expectedLwd;
        if (in_array($decision, ['waive', 'reduce'], true)) {
            if ($actualLwdRaw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $actualLwdRaw)) {
                return $this->json_error('An actual last working day (YYYY-MM-DD) is required to waive or reduce notice.');
            }
            if ($actualLwdRaw > $expectedLwd) {
                return $this->json_error('The adjusted last working day cannot be LATER than the original expected date — use Approve for that.');
            }
            $actualLwd = $actualLwdRaw;
        }
        if ($decision === 'reject') $actualLwd = null;

        $statusMap = ['approve' => 'approved', 'waive' => 'waived', 'reduce' => 'reduced', 'reject' => 'rejected'];
        $patch = [
            'status'               => $statusMap[$decision],
            'decision'             => $decision,
            'decidedBy'            => (string) ($this->admin_id ?? ''),
            'decidedAt'            => date('c'),
            'decisionNote'         => $note,
            'actualLastWorkingDay' => $actualLwd,
            'updatedAt'            => date('c'),
        ];

        try {
            $ok = $this->fs->update(self::NOTICE_PERIOD_COL, $docId, $patch);
        } catch (\Throwable $e) {
            log_message('error', 'Staff::decide_notice_period write failed: ' . $e->getMessage());
            $ok = false;
        }
        if (!$ok) return $this->json_error('Could not record the decision. Please try again.');

        log_audit('Staff', 'notice_period_decided', $staffId, "Notice period {$decision}" . ($actualLwd ? ", last working day {$actualLwd}" : ''));
        return $this->json_success(['message' => 'Decision recorded.', 'status' => $statusMap[$decision], 'actual_last_working_day' => $actualLwd]);
    }

    /** POST — HR withdraws a still-pending notice period (employee changed their mind, or it was filed in error). */
    public function withdraw_resignation()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $staffId = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        if ($staffId === '') return $this->json_error('Staff ID is required.');

        $docId = $this->_notice_doc_id($staffId);
        try {
            $notice = $this->fs->get(self::NOTICE_PERIOD_COL, $docId);
        } catch (\Throwable $e) {
            $notice = null;
        }
        if (!is_array($notice) || empty($notice)) return $this->json_error('No notice period found for this staff member.');
        if ((string) ($notice['status'] ?? '') !== 'pending') {
            return $this->json_error('Only a pending notice period can be withdrawn.');
        }

        $ok = $this->fs->update(self::NOTICE_PERIOD_COL, $docId, [
            'status' => 'withdrawn', 'updatedAt' => date('c'),
        ]);
        if (!$ok) return $this->json_error('Could not withdraw the notice period. Please try again.');

        log_audit('Staff', 'notice_period_withdrawn', $staffId, 'Notice period withdrawn');
        return $this->json_success(['message' => 'Notice period withdrawn.']);
    }

    /**
     * POST — mark a decided notice period's separation as completed. Called
     * by the UI ONLY AFTER the existing, unmodified Staff::set_status()
     * endpoint has already been called (Inactive) — this never triggers
     * that separation itself, purely closes out the notice record so it
     * stops showing as "awaiting separation".
     */
    public function complete_notice_period()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $staffId = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        if ($staffId === '') return $this->json_error('Staff ID is required.');

        $docId = $this->_notice_doc_id($staffId);
        try {
            $notice = $this->fs->get(self::NOTICE_PERIOD_COL, $docId);
        } catch (\Throwable $e) {
            $notice = null;
        }
        if (!is_array($notice) || empty($notice)) return $this->json_error('No notice period found for this staff member.');
        if (!in_array((string) ($notice['status'] ?? ''), ['approved', 'waived', 'reduced'], true)) {
            return $this->json_error('Only an approved/waived/reduced notice period can be completed.');
        }

        // Confirm the staff record is now actually Inactive — the UI must
        // have already called set_status() before this. Refuses rather
        // than silently trusting the caller's sequencing.
        $staff = $this->_get_staff_with_fallback($staffId);
        $rawStatus = (string) ($staff['status'] ?? $staff['Status'] ?? 'Active');
        if (strtolower(trim($rawStatus)) !== 'inactive') {
            return $this->json_error('The staff member must be marked Inactive (via Separation) before the notice period can be completed.');
        }

        $ok = $this->fs->update(self::NOTICE_PERIOD_COL, $docId, [
            'status' => 'completed', 'separationCompleted' => true, 'updatedAt' => date('c'),
        ]);
        if (!$ok) return $this->json_error('Could not complete the notice period. Please try again.');

        log_audit('Staff', 'notice_period_completed', $staffId, 'Notice period closed out after separation');
        return $this->json_success(['message' => 'Notice period completed.']);
    }

    // ════════════════════════════════════════════════════════════════════
    //  STAFF HR ADVANCED CAPABILITY PROGRAMME — Phase 3: Clearance / Handover
    //  (2026-08-18)
    // ════════════════════════════════════════════════════════════════════
    //
    // New collection: staffClearances/{schoolId}_{staffId} — confirmed
    // green-field: no clearance/handover/asset-return/NOC concept exists
    // anywhere else in this codebase (HR or otherwise) to reuse or risk
    // duplicating.
    //
    // Department LIST is configurable per tenant (schools.clearanceDepartments),
    // NOT a fixed 9-department assumption — "do not assume every school
    // needs every department." Falls back to a standard default list only
    // when a school hasn't configured its own.
    //
    // Explicitly does NOT create a Payroll deduction of any kind when a
    // department is flagged — a flagged department blocks THIS workflow's
    // own completion (real-world meaning of "flagged"), nothing more. Any
    // link from an outstanding due to an actual salary deduction is a
    // separate, not-yet-authorized financial-policy decision.
    private const CLEARANCE_COL = 'staffClearances';
    private const CLEARANCE_DEFAULT_DEPARTMENTS = ['HR', 'Accounts', 'IT', 'Library', 'Transport', 'Hostel', 'Academic', 'Administration', 'Assets'];
    private const CLEARANCE_DEPT_STATUSES = ['pending', 'cleared', 'flagged'];

    private function _clearance_doc_id(string $staffId): string
    {
        return "{$this->school_id}_{$staffId}";
    }

    /** The configured clearance department list for this tenant (operator-editable, sane default). */
    private function _clearance_departments(): array
    {
        try {
            $school = $this->fs->get('schools', $this->school_id);
            $list = is_array($school['clearanceDepartments'] ?? null) ? $school['clearanceDepartments'] : [];
            $list = array_values(array_filter(array_map('strval', $list), fn($d) => trim($d) !== ''));
            if (!empty($list)) return $list;
        } catch (\Throwable $e) {
            log_message('error', 'Staff::_clearance_departments config read failed: ' . $e->getMessage());
        }
        return self::CLEARANCE_DEFAULT_DEPARTMENTS;
    }

    /** GET — the current clearance record for a staff member (or null if none started). */
    public function get_clearance()
    {
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");
        $staffId = $this->safe_path_segment(trim((string) $this->input->get('staff_id')), 'staff_id');
        if ($staffId === '') return $this->json_error('Invalid staff ID.');

        try {
            $doc = $this->fs->get(self::CLEARANCE_COL, $this->_clearance_doc_id($staffId));
        } catch (\Throwable $e) {
            log_message('error', 'Staff::get_clearance read failed: ' . $e->getMessage());
            return $this->json_error('Could not load clearance status.');
        }
        return $this->json_success(['clearance' => is_array($doc) ? $doc : null]);
    }

    /** POST — HR starts a clearance process for a staff member, opening one entry per configured department. */
    public function initiate_clearance()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $staffId = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        if ($staffId === '') return $this->json_error('Staff ID is required.');

        $staff = $this->_get_staff_with_fallback($staffId);
        if (empty($staff)) return $this->json_error('Staff member not found.');

        $docId = $this->_clearance_doc_id($staffId);
        try {
            $existing = $this->fs->get(self::CLEARANCE_COL, $docId);
        } catch (\Throwable $e) {
            $existing = null;
        }
        if (is_array($existing) && (string) ($existing['status'] ?? '') === 'in_progress') {
            return $this->json_error('A clearance process is already in progress for this staff member.');
        }

        $departments = $this->_clearance_departments();
        $deptState = [];
        foreach ($departments as $dept) {
            $deptState[$dept] = ['status' => 'pending', 'note' => '', 'decidedBy' => null, 'decidedAt' => null];
        }

        $now = date('c');
        $data = [
            'schoolId'     => $this->school_id,
            'staffId'      => $staffId,
            'staffName'    => (string) ($staff['Name'] ?? $staff['name'] ?? $staffId),
            'status'       => 'in_progress',
            'departments'  => $deptState,
            'initiatedBy'  => (string) ($this->admin_id ?? ''),
            'initiatedAt'  => $now,
            'completedAt'  => null,
            'createdAt'    => $now,
            'updatedAt'    => $now,
        ];

        try {
            $ok = $this->fs->set(self::CLEARANCE_COL, $docId, $data, true);
        } catch (\Throwable $e) {
            log_message('error', 'Staff::initiate_clearance write failed: ' . $e->getMessage());
            $ok = false;
        }
        if (!$ok) return $this->json_error('Could not start the clearance process. Please try again.');

        log_audit('Staff', 'clearance_initiated', $staffId, 'Clearance process started (' . implode(', ', $departments) . ')');
        return $this->json_success(['message' => 'Clearance process started.', 'clearance' => $data]);
    }

    /** POST — mark one department's clearance status (pending/cleared/flagged) + note. Recomputes overall completion. */
    public function update_clearance_department()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $staffId = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        $dept    = trim((string) $this->input->post('department'));
        $status  = trim((string) $this->input->post('dept_status'));
        $note    = trim((string) $this->input->post('note'));

        if ($staffId === '' || $dept === '') return $this->json_error('Staff ID and department are required.');
        if (!in_array($status, self::CLEARANCE_DEPT_STATUSES, true)) {
            return $this->json_error('Status must be pending, cleared, or flagged.');
        }

        $docId = $this->_clearance_doc_id($staffId);
        try {
            $clearance = $this->fs->get(self::CLEARANCE_COL, $docId);
        } catch (\Throwable $e) {
            $clearance = null;
        }
        if (!is_array($clearance) || empty($clearance)) return $this->json_error('No clearance process found for this staff member.');
        if ((string) ($clearance['status'] ?? '') !== 'in_progress') {
            return $this->json_error('This clearance process is already completed.');
        }

        $departments = is_array($clearance['departments'] ?? null) ? $clearance['departments'] : [];
        if (!array_key_exists($dept, $departments)) {
            return $this->json_error("'{$dept}' is not part of this clearance process.");
        }

        $departments[$dept] = [
            'status'    => $status,
            'note'      => $note,
            'decidedBy' => (string) ($this->admin_id ?? ''),
            'decidedAt' => date('c'),
        ];

        // Overall completion: every department must be explicitly 'cleared'
        // — a single still-'pending' or 'flagged' department blocks it.
        // Never auto-clears a department that wasn't explicitly reviewed.
        $allCleared = true;
        foreach ($departments as $d) {
            if (!is_array($d) || (string) ($d['status'] ?? '') !== 'cleared') { $allCleared = false; break; }
        }

        $patch = [
            'departments' => $departments,
            'updatedAt'   => date('c'),
        ];
        if ($allCleared) {
            $patch['status'] = 'completed';
            $patch['completedAt'] = date('c');
        }

        try {
            $ok = $this->fs->update(self::CLEARANCE_COL, $docId, $patch);
        } catch (\Throwable $e) {
            log_message('error', 'Staff::update_clearance_department write failed: ' . $e->getMessage());
            $ok = false;
        }
        if (!$ok) return $this->json_error('Could not update the clearance department. Please try again.');

        log_audit('Staff', 'clearance_department_updated', $staffId, "{$dept} marked {$status}" . ($allCleared ? ' — clearance fully completed' : ''));
        return $this->json_success(['message' => 'Clearance updated.', 'overall_status' => $allCleared ? 'completed' : 'in_progress']);
    }

    // ════════════════════════════════════════════════════════════════════
    //  STAFF HR ADVANCED CAPABILITY PROGRAMME — Phase 4: Exit Documents
    //  (2026-08-18)
    // ════════════════════════════════════════════════════════════════════
    //
    // Follows the EXISTING, closest precedent — Sis::print_tc() — rather
    // than inventing a new PDF architecture: a standalone HTML view (no
    // header/footer chrome), browser print-to-PDF via window.print(). This
    // codebase's Dompdf usage (Pdf_generator.php) is for report cards only,
    // an unrelated document class; exit documents are shaped like the TC,
    // not a report card.
    //
    // Uses ONLY verified employment facts already on the staff record
    // (name, position, department, join date, last working day) — NO
    // settlement figures of any kind, per the standing instruction (Phase 5
    // is a separate, explicitly-scoped, read-only-aggregation-only phase
    // that itself stops short of any financial calculation).
    private const EXIT_DOCUMENT_TYPES = ['relieving', 'experience', 'service'];

    /** GET — standalone printable exit document (Relieving Letter / Experience Certificate / Service Certificate). */
    public function print_exit_document($staffId = null)
    {
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");
        if (empty($staffId) || !preg_match('/^[A-Za-z0-9_\-]+$/', $staffId)) show_404();

        $docType = trim((string) $this->input->get('type'));
        if (!in_array($docType, self::EXIT_DOCUMENT_TYPES, true)) $docType = 'relieving';

        $staff = $this->_get_staff_with_fallback($staffId);
        if (empty($staff)) show_404();

        $rawStatus = (string) ($staff['status'] ?? $staff['Status'] ?? 'Active');
        $exitDate = (string) ($staff['exitDate'] ?? $staff['Resignation Date'] ?? $staff['leaveDate'] ?? '');
        if (strtolower(trim($rawStatus)) !== 'inactive' || $exitDate === '') {
            $this->output->set_status_header(409);
            echo '<!DOCTYPE html><html><head><title>Exit Document Unavailable</title>'
               . '<style>body{font:15px/1.5 system-ui;padding:60px;color:#334155;text-align:center;}h1{color:#dc2626;}</style></head><body>'
               . '<h1>Exit Document Unavailable</h1>'
               . '<p>This document can only be generated for a separated staff member with a recorded last working day.</p>'
               . '</body></html>';
            return;
        }

        $schoolProfile = $this->fs->get('schools', $this->school_id) ?? [];

        $data['staff']         = $staff;
        $data['doc_type']      = $docType;
        $data['school_profile']= $schoolProfile;
        $data['school_name']   = $this->school_name;

        $this->load->view('staff/exit_document_print', $data);
    }

    // ════════════════════════════════════════════════════════════════════
    //  STAFF HR ADVANCED CAPABILITY PROGRAMME — Phase 5: Read-Only Exit
    //  Summary (2026-08-18)
    // ════════════════════════════════════════════════════════════════════
    //
    // PURE AGGREGATION. Every field below is READ, verbatim, from a
    // collection/computation that already exists and is already owned by
    // its own module (Payroll's salarySlips, Leave's leaveApplications
    // balance doc, this programme's own Notice Period / Clearance
    // records). This endpoint computes NOTHING — no leave-encashment rate,
    // no notice-recovery formula, no deduction priority, no statutory
    // treatment, no final payable figure. Where a concept has no existing
    // ZenX implementation to read from (pending reimbursements — confirmed
    // by this programme's own Phase-0 research: no expense-claim workflow
    // exists anywhere in this codebase), the field is returned as
    // `not_tracked: true` rather than a fabricated zero — a silent 0 would
    // misrepresent "nothing owed" when the true answer is "ZenX has no way
    // to know".
    //
    // The LOP/attendance/payroll figures shown are whatever the MOST
    // RECENT finalized/paid payslip already contains — this endpoint does
    // not re-derive LOP itself (that would risk silently duplicating or
    // diverging from Payroll's own `_compute_payslip()`), it only surfaces
    // what that engine already decided and locked into a slip.
    public function get_exit_summary()
    {
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");
        $staffId = $this->safe_path_segment(trim((string) $this->input->get('staff_id')), 'staff_id');
        if ($staffId === '') return $this->json_error('Invalid staff ID.');

        $staff = $this->_get_staff_with_fallback($staffId);
        if (empty($staff)) return $this->json_error('Staff member not found.');

        $summary = [
            'staffId'   => $staffId,
            'staffName' => (string) ($staff['Name'] ?? $staff['name'] ?? $staffId),
            'status'    => (string) ($staff['status'] ?? $staff['Status'] ?? 'Active'),
            'lastWorkingDay' => (string) ($staff['exitDate'] ?? $staff['Resignation Date'] ?? $staff['leaveDate'] ?? ''),
        ];

        // Notice period — read-only.
        try {
            $notice = $this->fs->get(self::NOTICE_PERIOD_COL, $this->_notice_doc_id($staffId));
            $summary['noticePeriod'] = is_array($notice) ? $notice : null;
            if (is_array($notice) && !empty($notice['actualLastWorkingDay']) && $summary['lastWorkingDay'] === '') {
                $summary['lastWorkingDay'] = (string) $notice['actualLastWorkingDay'];
            }
        } catch (\Throwable $e) {
            $summary['noticePeriod'] = null;
        }

        // Clearance / outstanding dues status — read-only.
        try {
            $clearance = $this->fs->get(self::CLEARANCE_COL, $this->_clearance_doc_id($staffId));
            $summary['clearance'] = is_array($clearance) ? $clearance : null;
        } catch (\Throwable $e) {
            $summary['clearance'] = null;
        }

        // Leave balance — read-only, straight from the existing Leave
        // balance doc (same doc Hr::get_leave_balances() itself reads);
        // no recomputation.
        try {
            $year = date('Y');
            $balDoc = $this->fs->get('leaveApplications', $this->fs->docId("BAL_{$staffId}_{$year}"));
            $summary['leaveBalance'] = (is_array($balDoc) && isset($balDoc['balances'])) ? $balDoc['balances'] : null;
        } catch (\Throwable $e) {
            $summary['leaveBalance'] = null;
        }

        // Most recent FINALIZED/PAID payslip — read-only, same query shape
        // and field names Hr::my_payslips() already uses for the identical
        // "list this staff member's own slips" lookup.
        $summary['latestPayslip'] = null;
        try {
            $fsDocs = $this->fs->schoolWhere('salarySlips', [
                ['type', '==', 'payslip'],
                ['staffId', '==', $staffId],
            ]);
            $latest = null;
            $latestKey = '';
            foreach ((is_array($fsDocs) ? $fsDocs : []) as $doc) {
                $s = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                if (!is_array($s)) continue;
                $rid = (string) ($s['runId'] ?? '');
                if ($rid === '') continue;
                $run = null;
                try { $run = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$rid}")); } catch (\Throwable $e) {}
                if (!is_array($run) || !in_array((string) ($run['status'] ?? ''), ['Finalized', 'Paid'], true)) continue;
                $sortKey = sprintf('%s-%02d', $run['year'] ?? '0000', (int) ($run['month'] ?? 0));
                if ($sortKey > $latestKey) {
                    $latestKey = $sortKey;
                    $latest = [
                        'run_id'           => $rid,
                        'month'            => $run['month'] ?? '',
                        'year'             => $run['year'] ?? '',
                        'status'           => $run['status'] ?? '',
                        'gross'            => $s['gross'] ?? 0,
                        'net_pay'          => $s['net_pay'] ?? 0,
                        'total_deductions' => $s['total_deductions'] ?? 0,
                        'days_worked'      => $s['days_worked'] ?? '',
                        'days_absent'      => $s['days_absent'] ?? 0,
                        'lwp_days'         => $s['lwp_days'] ?? 0,
                    ];
                }
            }
            $summary['latestPayslip'] = $latest;
        } catch (\Throwable $e) {
            log_message('error', 'Staff::get_exit_summary payslip read failed: ' . $e->getMessage());
        }

        // Pending reimbursements — no expense-claim/reimbursement workflow
        // exists anywhere in this codebase (confirmed by this programme's
        // own research pass). Reported explicitly as untracked, never as a
        // fabricated zero.
        $summary['pendingReimbursements'] = ['not_tracked' => true, 'note' => 'ZenX has no expense/reimbursement claim workflow to read from.'];

        return $this->json_success(['summary' => $summary]);
    }

    // ════════════════════════════════════════════════════════════════════
    //  STAFF HR ADVANCED CAPABILITY PROGRAMME — Phase 6: Structured
    //  Onboarding (2026-08-19)
    // ════════════════════════════════════════════════════════════════════
    //
    // Confirmed green-field by direct research pass: new_staff() creates a
    // staff RECORD only (position, department, docs, salary) — there was no
    // post-hire task/checklist concept (IT setup, ID card, orientation,
    // documents collected, induction) anywhere in this codebase. Modeled
    // directly on Phase 3's Clearance/Handover — the closest existing
    // precedent for "a configurable, per-department-or-per-task checklist
    // with an overall completion state" — same doc-id shape
    // (staffOnboarding/{schoolId}_{staffId}), same tenant-configurable-list-
    // with-sane-default pattern, same manual-initiate + per-item-update +
    // recomputed-overall-status choreography.
    //
    // Task LIST is configurable per tenant (schools.onboardingTasks), not a
    // fixed assumption — same "don't assume every school needs every task"
    // reasoning Clearance already established for its department list.
    //
    // Deliberately MANUAL-initiate, not auto-triggered from new_staff() —
    // new_staff() is an already-proven, already-tested creation flow;
    // composing at the workflow level (HR clicks "Start Onboarding" once
    // the hire is ready to begin, same as Clearance is HR-initiated at
    // exit) avoids touching that flow at all, consistent with this whole
    // Trail's "compose existing/adjacent actions, never entangle a proven
    // engine" discipline.
    //
    // Three-state per task ('pending' | 'done' | 'skipped') rather than
    // Clearance's binary-toward-cleared: a real onboarding checklist
    // legitimately has tasks that don't apply to every hire (e.g. "Desk/IT
    // Setup" for a fully-remote role) — 'skipped' lets HR explicitly opt a
    // task out of blocking completion without silently marking it done
    // (misrepresenting "not needed" as "actually completed").
    private const ONBOARDING_COL = 'staffOnboarding';
    private const ONBOARDING_DEFAULT_TASKS = [
        'Offer Letter Signed', 'Documents Collected', 'ID Card Issued',
        'Desk / IT Setup', 'Bank Details Verified', 'Policy Handbook Acknowledged',
        'Induction / Orientation Completed',
    ];
    private const ONBOARDING_TASK_STATUSES = ['pending', 'done', 'skipped'];

    private function _onboarding_doc_id(string $staffId): string
    {
        return "{$this->school_id}_{$staffId}";
    }

    /** The configured onboarding task list for this tenant (operator-editable, sane default). */
    private function _onboarding_tasks(): array
    {
        try {
            $school = $this->fs->get('schools', $this->school_id);
            $list = is_array($school['onboardingTasks'] ?? null) ? $school['onboardingTasks'] : [];
            $list = array_values(array_filter(array_map('strval', $list), fn($t) => trim($t) !== ''));
            if (!empty($list)) return $list;
        } catch (\Throwable $e) {
            log_message('error', 'Staff::_onboarding_tasks config read failed: ' . $e->getMessage());
        }
        return self::ONBOARDING_DEFAULT_TASKS;
    }

    /** GET — the current onboarding checklist for a staff member (or null if none started). */
    public function get_onboarding()
    {
        $this->_require_role(self::VIEW_ROLES, "", "SIS", "view");
        $staffId = $this->safe_path_segment(trim((string) $this->input->get('staff_id')), 'staff_id');
        if ($staffId === '') return $this->json_error('Invalid staff ID.');

        try {
            $doc = $this->fs->get(self::ONBOARDING_COL, $this->_onboarding_doc_id($staffId));
        } catch (\Throwable $e) {
            log_message('error', 'Staff::get_onboarding read failed: ' . $e->getMessage());
            return $this->json_error('Could not load onboarding status.');
        }
        return $this->json_success(['onboarding' => is_array($doc) ? $doc : null]);
    }

    /** POST — HR starts an onboarding checklist for a staff member, opening one entry per configured task. */
    public function initiate_onboarding()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $staffId = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        if ($staffId === '') return $this->json_error('Staff ID is required.');

        $staff = $this->_get_staff_with_fallback($staffId);
        if (empty($staff)) return $this->json_error('Staff member not found.');

        $docId = $this->_onboarding_doc_id($staffId);
        try {
            $existing = $this->fs->get(self::ONBOARDING_COL, $docId);
        } catch (\Throwable $e) {
            $existing = null;
        }
        if (is_array($existing) && (string) ($existing['status'] ?? '') === 'in_progress') {
            return $this->json_error('An onboarding checklist is already in progress for this staff member.');
        }

        $tasks = $this->_onboarding_tasks();
        $taskState = [];
        foreach ($tasks as $task) {
            $taskState[$task] = ['status' => 'pending', 'note' => '', 'decidedBy' => null, 'decidedAt' => null];
        }

        $now = date('c');
        $data = [
            'schoolId'     => $this->school_id,
            'staffId'      => $staffId,
            'staffName'    => (string) ($staff['Name'] ?? $staff['name'] ?? $staffId),
            'status'       => 'in_progress',
            'tasks'        => $taskState,
            'initiatedBy'  => (string) ($this->admin_id ?? ''),
            'initiatedAt'  => $now,
            'completedAt'  => null,
            'createdAt'    => $now,
            'updatedAt'    => $now,
        ];

        try {
            $ok = $this->fs->set(self::ONBOARDING_COL, $docId, $data, true);
        } catch (\Throwable $e) {
            log_message('error', 'Staff::initiate_onboarding write failed: ' . $e->getMessage());
            $ok = false;
        }
        if (!$ok) return $this->json_error('Could not start the onboarding checklist. Please try again.');

        log_audit('Staff', 'onboarding_initiated', $staffId, 'Onboarding checklist started (' . implode(', ', $tasks) . ')');
        return $this->json_success(['message' => 'Onboarding checklist started.', 'onboarding' => $data]);
    }

    /** POST — mark one task's onboarding status (pending/done/skipped) + note. Recomputes overall completion. */
    public function update_onboarding_task()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $staffId = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        $task    = trim((string) $this->input->post('task'));
        $status  = trim((string) $this->input->post('task_status'));
        $note    = trim((string) $this->input->post('note'));

        if ($staffId === '' || $task === '') return $this->json_error('Staff ID and task are required.');
        if (!in_array($status, self::ONBOARDING_TASK_STATUSES, true)) {
            return $this->json_error('Status must be pending, done, or skipped.');
        }

        $docId = $this->_onboarding_doc_id($staffId);
        try {
            $onboarding = $this->fs->get(self::ONBOARDING_COL, $docId);
        } catch (\Throwable $e) {
            $onboarding = null;
        }
        if (!is_array($onboarding) || empty($onboarding)) return $this->json_error('No onboarding checklist found for this staff member.');
        if ((string) ($onboarding['status'] ?? '') !== 'in_progress') {
            return $this->json_error('This onboarding checklist is already completed.');
        }

        $tasks = is_array($onboarding['tasks'] ?? null) ? $onboarding['tasks'] : [];
        if (!array_key_exists($task, $tasks)) {
            return $this->json_error("'{$task}' is not part of this onboarding checklist.");
        }

        $tasks[$task] = [
            'status'    => $status,
            'note'      => $note,
            'decidedBy' => (string) ($this->admin_id ?? ''),
            'decidedAt' => date('c'),
        ];

        // Overall completion: every task must be explicitly 'done' or
        // 'skipped' — a single still-'pending' task blocks it. Never
        // auto-completes a task that wasn't explicitly reviewed.
        $allResolved = true;
        foreach ($tasks as $t) {
            $st = is_array($t) ? (string) ($t['status'] ?? '') : '';
            if ($st !== 'done' && $st !== 'skipped') { $allResolved = false; break; }
        }

        $patch = [
            'tasks'     => $tasks,
            'updatedAt' => date('c'),
        ];
        if ($allResolved) {
            $patch['status'] = 'completed';
            $patch['completedAt'] = date('c');
        }

        try {
            $ok = $this->fs->update(self::ONBOARDING_COL, $docId, $patch);
        } catch (\Throwable $e) {
            log_message('error', 'Staff::update_onboarding_task write failed: ' . $e->getMessage());
            $ok = false;
        }
        if (!$ok) return $this->json_error('Could not update the onboarding task. Please try again.');

        log_audit('Staff', 'onboarding_task_updated', $staffId, "{$task} marked {$status}" . ($allResolved ? ' — onboarding fully completed' : ''));
        return $this->json_success(['message' => 'Onboarding task updated.', 'overall_status' => $allResolved ? 'completed' : 'in_progress']);
    }

    // ════════════════════════════════════════════════════════════════════
    //  Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP →
    //  Payroll), Phase I — Staff Transfer (2026-08-18)
    // ════════════════════════════════════════════════════════════════════
    //
    // "Do not implement Transfer until this dependency is understood"
    // (the standing instruction) — Trail 2 Phase J just verified and fixed
    // exactly this dependency: Payroll now resolves an employee's
    // department for a given period from the effective-dated employment-
    // history timeline (Staff_employment_history_service::resolveAttributeAsOf()),
    // not the live staff.Department field. Transfer is safe to build now
    // because that resolver already exists and is already wired in.
    //
    // Deliberately modeled on promote_staff() — SAME pattern, not a new
    // one: the live staff.Department/reportingManagerId fields are updated
    // IMMEDIATELY (matching every other attribute-change path in this
    // controller), while Staff_employment_history_service::recordChanges()
    // records the change against the caller-supplied $effectiveFrom. A
    // downstream resolver (today: Payroll's department resolution; Attendance/
    // Leave dept-scoped calendar reads would need the same treatment in a
    // future phase if they're ever found to have the same live-read gap)
    // is what makes an EFFECTIVE-in-the-future or backdated transfer behave
    // correctly — this endpoint's own write is intentionally immediate,
    // consistent with the rest of the controller.
    //
    // Work Schedule reassignment is DELIBERATELY NOT part of this endpoint.
    // Work_schedules::save_assignment() already fully and safely supports
    // creating/closing a staff-level schedule assignment (overlap
    // validation via Policy_assignment_service::validateAssignment()/
    // findOverlaps(), already proven, already in production) — reimplementing
    // that here would be exactly the "second writer for the same collection"
    // this whole Trail has been eliminating. A transfer that also needs a
    // new work schedule composes at the WORKFLOW level: complete this
    // transfer, then use the existing Work Schedules → Assignments screen
    // to close the old assignment (set its effectiveTo) and open the new
    // one — the same "compose existing actions, don't build a new engine"
    // choreography already established for Notice Period's separation flow
    // in this same controller.
    public function transfer_staff()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");

        $staffId         = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        $newDepartment   = trim((string) $this->input->post('new_department'));
        $reportingManagerSubmitted = array_key_exists('new_reporting_manager_id', $_POST);
        $newReportingMgr = trim((string) $this->input->post('new_reporting_manager_id'));
        $effectiveFrom   = trim((string) $this->input->post('effective_from'));
        $reasonNote      = trim((string) $this->input->post('reason'));

        if ($staffId === '') return $this->json_error('Staff ID is required.');
        if ($newDepartment === '' && !$reportingManagerSubmitted) {
            return $this->json_error('Provide a new department and/or reporting manager.');
        }
        // Unlike promote_staff() (effective date optional, defaults to
        // today), a transfer's effective date is the entire point of the
        // action — required, not optional.
        if ($effectiveFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
            return $this->json_error('Effective date is required (YYYY-MM-DD).');
        }

        $staff = $this->_get_staff_with_fallback($staffId);
        if (empty($staff)) return $this->json_error('Staff member not found.');
        $rawStatus = (string) ($staff['status'] ?? $staff['Status'] ?? 'Active');
        if (strtolower(trim($rawStatus)) !== 'active') {
            return $this->json_error('Cannot record a transfer for an inactive staff member.');
        }

        $oldDepartment = (string) ($staff['department'] ?? $staff['Department'] ?? '');
        $oldManagerId  = (string) ($staff['reportingManagerId'] ?? '');

        $this->load->library('staff_employment_history_service');
        $ehSvc = $this->staff_employment_history_service
            ->init($this->fs, $this->school_id, (string) ($this->admin_id ?? ''));

        $fieldUpdate = [];
        $ehChanges   = [];
        if ($newDepartment !== '' && $newDepartment !== $oldDepartment) {
            $fieldUpdate['department'] = $newDepartment;
            $fieldUpdate['Department'] = $newDepartment; // dual-cased, matching every other write site in this controller
            $ehChanges['department']  = $newDepartment;
        }
        if ($reportingManagerSubmitted && $newReportingMgr !== $oldManagerId) {
            // Same validation promote_staff()/edit_staff() already apply —
            // reused, not reimplemented (self-reference/inactive/circular-
            // chain guards).
            $rmValidation = $ehSvc->validateManagerAssignment($staffId, $newReportingMgr);
            if (!$rmValidation['ok']) {
                return $this->json_error('Invalid Reporting Manager: ' . $rmValidation['reason'], 400);
            }
            $fieldUpdate['reportingManagerId'] = $newReportingMgr;
            $ehChanges['reportingManagerId']  = $newReportingMgr;
        }
        if (empty($ehChanges)) {
            return $this->json_error('At least one of department or reporting manager must differ from the current value.');
        }

        $fieldUpdate['updated_at'] = date('c');
        $ok = $this->fs->updateEntity('staff', $staffId, $fieldUpdate);
        if (!$ok) return $this->json_error('Could not update the staff record. Please try again.');

        $reason = 'Transfer' . ($reasonNote !== '' ? ": {$reasonNote}" : '')
            . " ({$oldDepartment} -> " . ($newDepartment !== '' ? $newDepartment : $oldDepartment)
            . "), effective {$effectiveFrom}";

        $historyWarning = null;
        try {
            $ehRes = $ehSvc->recordChanges($staffId, $ehChanges, $effectiveFrom, $reason);
            if (empty($ehRes['ok'])) {
                $historyWarning = 'Transfer recorded, but employment history could not be updated ('
                    . ($ehRes['reason'] ?? 'unknown') . ').';
                log_message('error', "Staff::transfer_staff employment history recordChanges failed staff={$staffId} reason=" . ($ehRes['reason'] ?? 'unknown'));
            }
        } catch (\Throwable $ehErr) {
            $historyWarning = 'Transfer recorded, but employment history could not be updated.';
            log_message('error', 'Staff::transfer_staff employment history threw for ' . $staffId . ': ' . $ehErr->getMessage());
        }

        log_audit('Staff', 'transfer', $staffId, $reason);

        $resp = [
            'message'          => 'Transfer recorded.' . ($newDepartment !== '' && $newDepartment !== $oldDepartment
                ? ' If this employee also needs a new Work Schedule, set it separately under Attendance → Work Schedules → Assignments (close the old assignment, open a new one effective ' . $effectiveFrom . ').'
                : ''),
            'old_department'   => $oldDepartment,
            'new_department'   => $newDepartment !== '' ? $newDepartment : $oldDepartment,
            'old_manager_id'   => $oldManagerId,
            'new_manager_id'   => $reportingManagerSubmitted ? $newReportingMgr : $oldManagerId,
            'effective_from'   => $effectiveFrom,
        ];
        if ($historyWarning !== null) $resp['warning'] = $historyWarning;
        return $this->json_success($resp);
    }

    // ════════════════════════════════════════════════════════════════════
    //  TRANSFER × WORK SCHEDULE — persistent follow-up reminder
    //  (Trail 2 Priority 3/4, 2026-08-19)
    // ════════════════════════════════════════════════════════════════════
    //
    // The ratified Transfer × Work Schedule Decision requires the optional
    // schedule-change step to compose at the WORKFLOW level: the admin
    // panel calls this endpoint's transfer_staff() first, then — only if
    // the admin explicitly opted in — the panel separately calls the
    // EXISTING, UNMODIFIED Work_schedules::save_assignment() to close the
    // old staff-level assignment and open the new one. Department transfer
    // and Schedule assignment are independent facts: if that second call
    // fails, the already-successful transfer is never rolled back — but a
    // silently-dropped failure would leave the employee on the wrong
    // schedule with nothing pointing HR back at it. These two endpoints
    // give that failure a PERSISTENT (Firestore-backed, survives reload/
    // relogin/different admin session), dismissible reminder — a flag
    // field on the `staff` doc, exactly like every other profile attribute
    // this controller already writes. This is NOT a second writer for
    // `staffScheduleAssignments` — it never touches that collection.
    private const SCHEDULE_REVIEW_FIELD = 'pendingScheduleReview';

    /** POST — record that a post-transfer Work Schedule composition attempt failed and needs manual follow-up. */
    public function flag_pending_schedule_review()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $staffId = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        if ($staffId === '') return $this->json_error('Staff ID is required.');

        $reason        = trim((string) $this->input->post('reason'));
        $effectiveFrom = trim((string) $this->input->post('effective_from'));

        $ok = $this->fs->updateEntity('staff', $staffId, [
            self::SCHEDULE_REVIEW_FIELD => [
                'reason'        => $reason !== '' ? $reason : 'Work Schedule composition failed after a transfer.',
                'effectiveFrom' => $effectiveFrom,
                'since'         => date('c'),
                'setBy'         => (string) ($this->admin_id ?? ''),
            ],
        ]);
        if (!$ok) return $this->json_error('Could not record the follow-up reminder. Please try again.');

        log_audit('Staff', 'schedule_review_flagged', $staffId, $reason);
        return $this->json_success();
    }

    /** POST — dismiss a pending Work Schedule review reminder (composition was completed manually, or is no longer needed). */
    public function clear_pending_schedule_review()
    {
        $this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");
        $staffId = $this->safe_path_segment(trim((string) $this->input->post('staff_id')), 'staff_id');
        if ($staffId === '') return $this->json_error('Staff ID is required.');

        $ok = $this->fs->updateEntity('staff', $staffId, [self::SCHEDULE_REVIEW_FIELD => null]);
        if (!$ok) return $this->json_error('Could not clear the reminder. Please try again.');

        log_audit('Staff', 'schedule_review_dismissed', $staffId, '');
        return $this->json_success();
    }
}
