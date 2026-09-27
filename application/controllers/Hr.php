<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Hr Controller — Human Resources & Staff Management
 *
 * Modules:
 *   - Dashboard (overview stats)
 *   - Departments (CRUD)
 *   - Recruitment (jobs + applicants)
 *   - Leave Management (types, balances, requests, approval)
 *   - Salary Structures & Payroll (generate, finalize, pay + accounting)
 *   - Appraisals (CRUD + submit/review)
 *   - Utility (staff list for dropdowns)
 *
 * Firebase schema under Schools/{school}/HR/ (year-independent)
 * and Schools/{school}/{session}/HR/Payroll/ (year-scoped).
 *
 * Extends MY_Controller which provides:
 *   $this->school_name, $this->school_id, $this->session_year,
 *   $this->admin_id, $this->admin_name, $this->admin_role,
 *   $this->firebase, safe_path_segment(), json_success(), json_error()
 */
class Hr extends MY_Controller
{
    /** Roles for payroll and salary management */
    private const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'HR Manager'];

    /** Roles for HR operations (departments, recruitment, leave mgmt) */
    private const HR_ROLES    = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'HR Manager'];

    /** Roles that may view HR data */
    private const VIEW_ROLES  = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'HR Manager', 'Teacher'];

    // ── Audit C1: PII split ────────────────────────────────────────────────
    // These sensitive fields live on the server-only `staffPrivate` mirror, NOT
    // on the same-school-readable `staff` doc. HR payroll/payslip reads merge
    // them back via the helpers below. Keep in lockstep with Staff.php.
    private const PII_KEYS = ['panNumber', 'aadharNumber', 'pfNumber', 'esiNumber', 'salaryDetails', 'bankDetails'];

    // ── Leave tuning constants (LOW — extracted magic numbers) ──────────
    /** Reject approving a leave whose start date is older than this window. */
    /**
     * Payroll run kinds (2026-08-22).
     *
     * `monthly` is the default everywhere and describes every run written
     * before this distinction existed — consumers read
     * `$run['runType'] ?? self::RUN_TYPE_MONTHLY`, so no backfill is needed.
     *
     * The difference is a uniqueness rule, not a calculation one: exactly ONE
     * monthly run may exist per school/month/year (a second would pay the
     * roster twice), whereas an off-cycle run — Full & Final, arrears, a bonus
     * payout — is a distinct event that may legitimately sit alongside it.
     * Both use the SAME `_compute_payslip()` engine; nothing about the money
     * arithmetic branches on this field.
     */
    private const RUN_TYPE_MONTHLY  = 'monthly';
    private const RUN_TYPE_OFFCYCLE = 'offcycle';
    private const RUN_TYPES = [self::RUN_TYPE_MONTHLY, self::RUN_TYPE_OFFCYCLE];

    private const LEAVE_STALE_WINDOW_DAYS = 60;
    /** Max audit rows returned by get_leave_audit_log. */
    private const LEAVE_AUDIT_LIMIT       = 200;
    /** Hard cap on a single leave span (inclusive days) for staff. */
    private const LEAVE_MAX_SPAN_DAYS     = 366;
    /** Page size cap for leave-request list queries (pagination TODO). */
    private const LEAVE_QUERY_LIMIT       = 500;
    /** CAS retry budget for atomic balance / lock writes. */
    private const LEAVE_CAS_MAX_ATTEMPTS  = 5;
    /** Default HRA / DA percentage-of-basic when a school has no salaryDefaults. */
    private const DEFAULT_HRA_PCT_OF_BASIC = 40;
    private const DEFAULT_DA_PCT_OF_BASIC  = 10;

    public function __construct()
    {
        parent::__construct();
        // W1.3: recruitment vocabulary bridge + shared atomic HR id minting,
        // both also used by Ats.php so the two modules cannot drift.
        $this->load->helper('recruitment');
        $this->load->helper('hr_id');
        require_permission('HR');
    }

    // ====================================================================
    //  PATH HELPERS
    // ====================================================================

    private function _hr()
    {
        return "Schools/{$this->school_name}/HR";
    }

    // NOTE: the legacy RTDB Departments path (Schools/{name}/HR/Departments) is
    // retired — departments now live on Firestore schools/{id}.departments,
    // written only via Hr::save_department. The old _dept() helper was removed.

    // NOTE (W1.3): the legacy RTDB Recruitment paths
    // (Schools/{name}/HR/Recruitment/{Jobs,Applicants}) are retired — jobs and
    // applicants live in Firestore `hrJobs` / `hrApplicants`, now written by
    // BOTH this controller and Ats.php. The _jobs() and _applicants() helpers
    // had already lost every call site here and were removed; keeping dead RTDB
    // path builders around is how a "temporary" fallback gets reintroduced.

    private function _leave_types($id = '')
    {
        return $this->_hr() . '/Leaves/Types' . ($id ? "/{$id}" : '');
    }

    private function _leave_bal($year = '', $staff = '', $type = '')
    {
        $p = $this->_hr() . '/Leaves/Balances';
        if ($year)  $p .= "/{$year}";
        if ($staff) $p .= "/{$staff}";
        if ($type)  $p .= "/{$type}";
        return $p;
    }

    private function _leave_req($id = '')
    {
        return $this->_hr() . '/Leaves/Requests' . ($id ? "/{$id}" : '');
    }

    private function _leave_audit()
    {
        return $this->_hr() . '/Leaves/Audit_log';
    }

    // ====================================================================
    //  FIRESTORE-ONLY READ HELPERS — Payroll data
    // ====================================================================

    /** Read a payroll run from Firestore. */
    private function _fsGetRun(string $runId): ?array
    {
        try {
            $doc = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$runId}"));
            if (is_array($doc) && !empty($doc)) return $doc;
        } catch (\Exception $e) {
            log_message('error', "HR _fsGetRun failed for {$runId}: " . $e->getMessage());
        }
        return null;
    }

    /** Read a payroll slip from Firestore. */
    private function _fsGetSlip(string $runId, string $staffId): ?array
    {
        try {
            $doc = $this->fs->get('salarySlips', $this->fs->docId("SLIP_{$runId}_{$staffId}"));
            if (is_array($doc) && !empty($doc)) return $doc;
        } catch (\Exception $e) {
            log_message('error', "HR _fsGetSlip failed for {$runId}/{$staffId}: " . $e->getMessage());
        }
        return null;
    }

    /** Read all slips for a payroll run from Firestore. Keyed by staffId. */
    private function _fsGetAllSlips(string $runId): array
    {
        $slips = [];
        try {
            $fsDocs = $this->fs->schoolWhere('salarySlips', [
                ['type', '==', 'payslip'],
                ['runId', '==', $runId],
            ]);
            if (is_array($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $s = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $s['staffId'] ?? '';
                    if ($sid !== '') $slips[$sid] = $s;
                }
            }
        } catch (\Exception $e) {
            log_message('error', "HR _fsGetAllSlips failed for {$runId}: " . $e->getMessage());
        }
        return $slips;
    }

    /** Read all payroll runs from Firestore. Keyed by runId. */
    private function _fsGetAllRuns(): array
    {
        $runs = [];
        try {
            $fsDocs = $this->fs->schoolWhere('salarySlips', [['type', '==', 'run']]);
            if (is_array($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    // Wrapper-bug fix: id is on the wrapper, not the data dict.
                    $docId = $doc['id'] ?? '';
                    if ($docId === '') continue;
                    $r = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $parts = explode('_RUN_', $docId, 2);
                    $rid = isset($parts[1]) ? $parts[1] : $docId;
                    $runs[$rid] = $r;
                }
            }
        } catch (\Exception $e) {
            log_message('error', "HR _fsGetAllRuns failed: " . $e->getMessage());
        }
        return $runs;
    }

    /** Read a staff profile from Firestore. */
    private function _fsGetStaffProfile(string $staffId): ?array
    {
        try {
            $fsDocs = $this->fs->schoolWhere('staff', [['staffId', '==', $staffId]]);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                $p = is_array($fsDocs[0]['data'] ?? null) ? $fsDocs[0]['data'] : $fsDocs[0];
                // Audit C1: merge the server-only PII mirror so payslip PAN/PF/
                // ESI/bank + the salary-structure fallback still resolve.
                return is_array($p) ? $this->_merge_staff_private($p, $staffId) : $p;
            }
        } catch (\Exception $e) {
            log_message('error', "HR _fsGetStaffProfile failed for {$staffId}: " . $e->getMessage());
        }
        return null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Audit C1 — staffPrivate PII merge (READ side; HR never writes staff)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Read one staffPrivate/{schoolId}_{staffId} doc and return only its PII
     * cluster (non-null values), or [] when absent.
     */
    private function _load_staff_private(string $staffId): array
    {
        if ($staffId === '') return [];
        try {
            $doc = $this->fs->getEntity('staffPrivate', $staffId);
        } catch (\Throwable $e) {
            log_message('error', "HR staffPrivate read failed [{$staffId}]: " . $e->getMessage());
            return [];
        }
        if (!is_array($doc)) return [];
        $out = [];
        foreach (self::PII_KEYS as $k) {
            if (array_key_exists($k, $doc) && $doc[$k] !== null) $out[$k] = $doc[$k];
        }
        return $out;
    }

    /** Merge the staffPrivate PII cluster onto a single staff profile. */
    private function _merge_staff_private(array $profile, string $staffId): array
    {
        $priv = $this->_load_staff_private($staffId);
        return $priv ? array_merge($profile, $priv) : $profile;
    }

    /**
     * One-shot map of every staffPrivate doc in THIS tenant, keyed by staffId,
     * each value trimmed to the PII cluster. Lets bulk payroll loops merge the
     * mirror without an extra read per staff member.
     */
    private function _load_staff_private_map(): array
    {
        $map = [];
        try {
            $rows = $this->fs->schoolWhere('staffPrivate', []);
        } catch (\Throwable $e) {
            log_message('error', 'HR staffPrivate map read failed: ' . $e->getMessage());
            return [];
        }
        if (!is_array($rows)) return [];
        foreach ($rows as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            if (!is_array($d)) continue;
            $sid = $d['staffId'] ?? '';
            if ($sid === '') continue;
            $out = [];
            foreach (self::PII_KEYS as $k) {
                if (array_key_exists($k, $d) && $d[$k] !== null) $out[$k] = $d[$k];
            }
            if (!empty($out)) $map[$sid] = $out;
        }
        return $map;
    }

    /** Merge a staffPrivate map (from _load_staff_private_map) onto a
     *  staffId-keyed profiles array. Private values win. */
    private function _apply_staff_private_map(array $profiles): array
    {
        $priv = $this->_load_staff_private_map();
        if (empty($priv)) return $profiles;
        foreach ($profiles as $sid => $p) {
            if (is_array($p) && isset($priv[$sid])) {
                $profiles[$sid] = array_merge($p, $priv[$sid]);
            }
        }
        return $profiles;
    }

    // ====================================================================
    //  FIRESTORE-ONLY READ HELPERS — Leave data
    //  Each tries Firestore first; falls back to RTDB on exception.
    // ====================================================================

    /**
     * Read a single leave type (or ALL leave types when $typeId is empty).
     * Firestore source: schools/{schoolId} → leaveTypes map.
     * RTDB fallback:    Schools/{school}/HR/Leaves/Types[/{typeId}]
     *
     * @return array|null  Single type array, full map, or null if not found
     */
    private function _fsGetLeaveType(string $typeId = ''): ?array
    {
        try {
            $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
            if (is_array($fsSchool) && !empty($fsSchool['leaveTypes'])) {
                if ($typeId !== '') {
                    return isset($fsSchool['leaveTypes'][$typeId])
                        ? $fsSchool['leaveTypes'][$typeId]
                        : null;
                }
                return $fsSchool['leaveTypes'];
            }
            return null; // empty = no leave types configured yet
        } catch (\Exception $e) {
            log_message('error', "HR _fsGetLeaveType: Firestore failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Read leave balance(s).
     *   - ($staffId, $year, $typeId) → single type balance array
     *   - ($staffId, $year, '')      → all balances for that staff/year
     *   - ('', $year, '')            → all staff balances for the year (keyed by staffId)
     *
     * Firestore source: leaveApplications collection, doc BAL_{staffId}_{year}
     * RTDB fallback:    Schools/{school}/HR/Leaves/Balances/{year}[/{staffId}[/{typeId}]]
     *
     * @return array|null
     */
    private function _fsGetLeaveBalance(string $staffId = '', string $year = '', string $typeId = ''): ?array
    {
        if ($year === '') $year = date('Y');

        if ($staffId !== '') {
            // Single staff
            try {
                $fsDoc = $this->fs->get('leaveApplications', $this->fs->docId("BAL_{$staffId}_{$year}"));
                if (is_array($fsDoc) && !empty($fsDoc['balances'])) {
                    if ($typeId !== '') {
                        return isset($fsDoc['balances'][$typeId])
                            ? $fsDoc['balances'][$typeId]
                            : null;
                    }
                    return $fsDoc['balances'];
                }
                return null; // not found in Firestore = genuinely doesn't exist
            } catch (\Exception $e) {
                log_message('error', "HR _fsGetLeaveBalance: Firestore failed: " . $e->getMessage());
                return null;
            }
        }

        // All staff for a year
        try {
            $fsDocs = $this->fs->schoolWhere('leaveApplications', [
                ['type', '==', 'balance'],
                ['year', '==', $year],
            ]);
            $result = [];
            foreach ($fsDocs as $doc) {
                $d   = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $sid = $d['staffId'] ?? '';
                if ($sid !== '' && !empty($d['balances'])) {
                    $result[$sid] = $d['balances'];
                }
            }
            return $result; // empty is valid — means no balances initialized yet
        } catch (\Exception $e) {
            log_message('error', "HR _fsGetLeaveBalance(all): Firestore failed: " . $e->getMessage());
        }
        return null;
    }

    // --------------------------------------------------------------------
    //  LEAVE BALANCE — LIVE DERIVATION (single source of truth)
    //  The stored BAL_{staffId}_{year} doc is a SNAPSHOT of `allocated` taken at
    //  allocation time. To make the current leaveTypes config the single source
    //  of truth, `allocated` (and therefore `balance`) is derived LIVE from the
    //  current leave-type definition on every read — stored `used` and `carried`
    //  are preserved. See _deriveLiveBalances().
    // --------------------------------------------------------------------

    /**
     * Return the CURRENT active leave types keyed by typeId.
     * Status read case-insensitively (lowercase-write / case-insensitive-read).
     *
     * @return array typeId => leaveType map (may be empty)
     */
    private function _activeLeaveTypes(): array
    {
        $out = [];
        $all = $this->_fsGetLeaveType('');
        if (is_array($all)) {
            foreach ($all as $tid => $lt) {
                if (!is_array($lt)) continue;
                if (strtolower(trim((string) ($lt['status'] ?? 'Active'))) === 'active') {
                    $out[$tid] = $lt;
                }
            }
        }
        return $out;
    }

    /**
     * Return the active-staff roster keyed by staffId (Firestore-only).
     * Used to (a) show un-allocated staff their full entitlement and
     * (b) apply gender-based leave-type applicability.
     *
     * @return array staffId => staff data map (may be empty)
     */
    private function _activeStaffRoster(): array
    {
        $roster = [];
        try {
            $fsDocs = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            if (!empty($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $d   = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? '';
                    if ($sid !== '') $roster[$sid] = $d;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'HR _activeStaffRoster: Firestore failed: ' . $e->getMessage());
        }
        return $roster;
    }

    /**
     * Is this leave type applicable to a staff member, honouring an OPTIONAL
     * `gender` field on the type? A type with no gender (or 'all'/'any') applies
     * to everyone; a gendered type (e.g. Maternity=female) only applies when the
     * staff's gender matches. Unknown staff gender → applies (fail-open, never
     * hides an entitlement we can't disprove).
     */
    private function _leaveTypeAppliesToStaff(array $type, ?array $staffMeta): bool
    {
        $typeGender = strtolower(trim((string) ($type['gender'] ?? '')));
        if ($typeGender === '' || $typeGender === 'all' || $typeGender === 'any') {
            return true;
        }
        $g = '';
        if (is_array($staffMeta)) {
            $g = strtolower(trim((string) ($staffMeta['gender'] ?? $staffMeta['Gender'] ?? '')));
        }
        if ($g === '') return true; // unknown gender → fail-open
        return $g === $typeGender;
    }

    /**
     * Merge a stored balances map with the CURRENT active leave types so the
     * returned `allocated`/`balance` always reflect live leave-type config, NOT
     * the snapshot captured at allocation time. Preserves stored `used`+`carried`.
     *
     * Output shape is backward-compatible: balances[typeId] =
     *   {allocated, used, carried, balance}  where
     *   allocated = current days_per_year (live), used = stored (or 0),
     *   carried   = stored (or 0),           balance = max(0, allocated+carried-used).
     *
     * Active types with NO stored entry are included (used=0, carried=0) so
     * un-allocated staff still show their full entitlement. Stored types that are
     * no longer active are preserved as-is (keeps historical `used` visible).
     *
     * @param array      $stored      stored balances map (may be empty)
     * @param array      $activeTypes typeId => leaveType (already filtered active)
     * @param array|null $staffMeta   staff profile for gender applicability
     * @return array derived balances map (same keys as the stored shape)
     */
    /**
     * Leave Policy Programme Phase 4 (2026-08-18) — pro-rate an annual
     * entitlement for a staff member whose employment window doesn't cover
     * the full Leave Year. Thin wrapper: normalizes the raw staff-profile
     * date strings (which arrive in whatever format the profile stored
     * them, hence _normalize_date()) then delegates the actual math to
     * Leave_year_service::prorateEntitlement() — the SAME function
     * Staff::new_staff() calls at balance-seed time, so display (here) and
     * the actual balance gate (Staff.php) can never compute two different
     * answers to "how much leave did this person earn this year".
     *
     * @return array{days: float, prorated: bool, windowDays: int, totalDays: int}
     */
    private function _prorate_entitlement(float $fullDaysPerYear, ?string $joinDate, ?string $exitDate, string $year): array
    {
        require_once APPPATH . 'libraries/Leave_year_service.php';
        $jd = ($joinDate !== null) ? $this->_normalize_date($joinDate, 'prorate.joinDate') : null;
        $xd = ($exitDate !== null) ? $this->_normalize_date($exitDate, 'prorate.exitDate') : null;
        return \Leave_year_service::prorateEntitlement($fullDaysPerYear, $jd, $xd, $year);
    }

    /**
     * Per-SCHOOL leave-accrual switch (2026-09-19). Reads
     * `schools.leavePolicy.accrualEnabled` — the school's own choice of monthly
     * accrual vs annual up-front allocation, set from the Leave settings screen.
     *
     * DEFAULT FALSE, and fail-closed on any read error: a school that never
     * opted in (every existing tenant, and every brand-new school) behaves
     * EXACTLY as before — full annual granted up front. This replaces the
     * platform-wide `LEAVE_ACCRUAL_ENABLED` flag as the authority for whether
     * accrual runs, so the decision is the school's, not a fleet-wide toggle.
     * Accepts the shapes a config value actually arrives in.
     */
    private $_accrualSvcInstance = null;
    private function _accrualSvc(): Leave_accrual_service
    {
        if ($this->_accrualSvcInstance === null) {
            $this->load->library('leave_accrual_service', null, 'leave_accrual_service');
            $this->_accrualSvcInstance = $this->leave_accrual_service->init(
                $this->firebase, $this->fs, (string) ($this->school_id ?? ''), (string) ($this->session_year ?? ''));
        }
        return $this->_accrualSvcInstance;
    }

    private function _school_accrual_enabled(): bool
    {
        // Delegates to Leave_accrual_service (single accrual authority, 2026-09-20).
        return $this->_accrualSvc()->schoolAccrualEnabled();
    }

    /**
     * Whether a specific leave type accrues monthly for THIS school as of $asOf.
     * True only when the school opted into accrual AND the type's effective
     * version carries `accrual_mode === 'monthly'` — the SAME version source the
     * accrual engine (_accrue_monthly_leave_one_type) keys off, so what an
     * employee may take can never disagree with what actually gets credited.
     * A lump_sum type, or a school that never opted in, returns false → the
     * annual-up-front path is untouched.
     */
    private function _type_accrues(array $lt, string $asOf): bool
    {
        if (!$this->_school_accrual_enabled()) return false;
        require_once APPPATH . 'libraries/Leave_policy_service.php';
        $ver = \Leave_policy_service::effectiveVersion($lt, $asOf);
        return $ver !== null && ((string) ($ver['accrual_mode'] ?? 'lump_sum') === 'monthly');
    }

    private function _deriveLiveBalances(array $stored, array $activeTypes, ?array $staffMeta = null, ?string $asOfDate = null): array
    {
        // Phase 2 (2026-08-18) — when effective-dating is live, `allocated`
        // resolves from the version effective on $asOfDate instead of the
        // always-current flat fields, so a balance stays explainable
        // against the policy that was actually true for it. OFF (default):
        // behavior is byte-identical to before this phase — $allocated
        // always comes from the live flat `days_per_year`.
        $effectiveDatingOn = $this->_leave_flag('LEAVE_EFFECTIVE_DATING_ENABLED');
        if ($effectiveDatingOn) {
            require_once APPPATH . 'libraries/Leave_policy_service.php';
        }
        $resolveDate = $asOfDate ?? date('Y-m-d');

        $out = [];
        foreach ($activeTypes as $tid => $lt) {
            if (!is_array($lt)) continue;
            if (!$this->_leaveTypeAppliesToStaff($lt, $staffMeta)) continue;

            // Kept as (int) unless Phase 4 pro-rata actually fires below —
            // the JSON shape of `allocated` (int vs float) must stay
            // byte-identical to pre-Phase-4 for every school that hasn't
            // turned LEAVE_PRORATA_ENABLED on, since mobile app JSON
            // deserializers may be typed against the historical int shape.
            $allocated = (int) ($lt['days_per_year'] ?? 0);
            if ($effectiveDatingOn) {
                $ver = \Leave_policy_service::effectiveVersion($lt, $resolveDate);
                if ($ver !== null) $allocated = (int) ($ver['days_per_year'] ?? 0);
                // $ver === null (e.g. no versions[] yet on a pre-Phase-2 type,
                // or none effective by $resolveDate) → keep the live flat
                // value, same fail-open-to-current-behavior discipline used
                // throughout this whole programme.
            }

            // Phase 4 (2026-08-18) — pro-rate a mid-year join/separation.
            // Applied AFTER effective-dating resolves the full-year figure,
            // so the two phases compose correctly (prorate whichever
            // days_per_year is actually in force). OFF (default): $allocated
            // is untouched — every staff member gets the full annual figure
            // regardless of join/exit date, exactly as before this phase.
            if ($this->_leave_flag('LEAVE_PRORATA_ENABLED') && is_array($staffMeta)) {
                $joinDate = $staffMeta['joinDate'] ?? $staffMeta['dateOfJoining']
                    ?? $staffMeta['Date Of Joining'] ?? $staffMeta['Joining Date'] ?? null;
                $exitDate = $staffMeta['exitDate'] ?? $staffMeta['Resignation Date']
                    ?? $staffMeta['leaveDate'] ?? null;
                $pr = $this->_prorate_entitlement($allocated, $joinDate, $exitDate, substr($resolveDate, 0, 4));
                $allocated = $pr['days'];
            }

            $s         = is_array($stored[$tid] ?? null) ? $stored[$tid] : [];
            $used      = (float) ($s['used'] ?? 0);
            $carried   = (float) ($s['carried'] ?? 0);
            // Monthly accrual (per-school opt-in, 2026-09-19): the available
            // entitlement is only what has ACCRUED so far, not the full annual.
            // Cap `allocated` at accruedToDate so the shown balance matches what
            // apply_leave permits. Default (no accrual) leaves `allocated` as-is.
            if ($this->_type_accrues($lt, $resolveDate)) {
                $allocated = min($allocated, (float) ($s['accruedToDate'] ?? 0));
            }
            $balance   = $allocated + $carried - $used;
            if ($balance < 0) $balance = 0;

            $out[$tid] = [
                'allocated' => $allocated,
                'used'      => $used,
                'carried'   => $carried,
                'balance'   => $balance,
            ];
        }
        // Preserve stored-but-now-inactive types so historical `used` stays visible
        // (no live entitlement — allocated reflects whatever was last stored).
        foreach ($stored as $tid => $s) {
            if (isset($out[$tid]) || !is_array($s)) continue;
            $out[$tid] = [
                'allocated' => $s['allocated'] ?? 0,
                'used'      => (float) ($s['used'] ?? 0),
                'carried'   => (float) ($s['carried'] ?? 0),
                'balance'   => (float) ($s['balance'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Per-month leave-accrual installment (days), distributing EXACTLY
     * $daysPerYear across an accrual window $monthsInWindow months long in
     * 0.5-day-granular steps, with the final month trueing up to the exact annual.
     *
     * Fixes a divergence defect (2026-09-19): the previous fixed per-month
     * `round(($daysPerYear / 12) * 2) / 2` summed to 12× that and did NOT equal
     * the school's configured annual — 20 days/yr accrued only 18 (a 2-day
     * LOSS), 15/yr accrued 18 (a 3-day GIFT). Here the cumulative target after
     * month k is `round($daysPerYear * k / window * 2) / 2` (0.5 granular), and
     * at the final month exactly $daysPerYear; the installment is the delta, so
     * any full window telescopes to the annual to the day.
     *
     * Pure + static (no Firestore, no $this) — executed directly by
     * LeaveAccrualDivergenceTest via the PayrollEngine reflection harness.
     * LATENT: LEAVE_ACCRUAL_ENABLED is off in every tenant, so this corrects a
     * dormant engine and moves no existing balance. This is step 1 of making
     * accrual a per-school choice; consuming accrued-to-date in apply_leave and
     * the per-school toggle are held for owner sign-off (they change entitlements).
     */
    private static function _accrual_installment(float $daysPerYear, int $ordinal, int $monthsInWindow): float
    {
        // Delegates to Leave_accrual_service (single accrual authority, 2026-09-20).
        return \Leave_accrual_service::installment($daysPerYear, $ordinal, $monthsInWindow);
    }

    /**
     * Leave Policy Programme Phase 3 (2026-08-18) — monthly accrual for one
     * staff member, one leave type. Idempotent: tracks the last calendar
     * month already credited (`lastAccrualMonth`, stored ON the balance
     * entry) and only credits months strictly after it, so running this
     * twice for the same month is a no-op, and a missed month is simply
     * caught up on the next run (no separate receipts collection needed —
     * the marker IS the receipt).
     *
     * Does nothing (returns 'skipped') unless BOTH LEAVE_ACCRUAL_ENABLED is
     * on AND this specific type's effective version has
     * `accrual_mode==='monthly'` — every existing type, and every type
     * with no version at all, stays lump_sum (i.e. untouched) by
     * construction. Applies the Phase 6 max_balance ceiling only when
     * LEAVE_MAX_BALANCE_ENABLED is also on (Phase 6 not yet live by
     * default — see leave_policy_v2_flags.php).
     *
     * @return array{action:string, credited:float, months:array<string>}
     *   action: 'skipped_flag_off' | 'skipped_not_monthly' | 'skipped_no_version'
     *         | 'skipped_up_to_date' | 'credited' | 'skipped_write_failed'
     */
    private function _accrue_monthly_leave_one_type(
        string $staffId, string $year, string $typeId, array $type, string $throughMonth
    ): array {
        // Delegates to Leave_accrual_service (single accrual authority, 2026-09-20).
        return $this->_accrualSvc()->accrueOneType($staffId, $year, $typeId, $type, $throughMonth);
    }

    /** 'YYYY-MM' → the next calendar month in the same format. */
    private function _next_month(string $yyyyMm): string
    {
        $ts = mktime(0, 0, 0, (int) substr($yyyyMm, 5, 2) + 1, 1, (int) substr($yyyyMm, 0, 4));
        return date('Y-m', $ts);
    }

    /**
     * Run monthly accrual for every accrual-mode-eligible type, for every
     * active staff member, through the given month (defaults to the
     * current server month). Safe to call repeatedly — idempotent per
     * staff/type/month (see _accrue_monthly_leave_one_type()). Designed to
     * be hit by an external scheduler (this codebase has no in-process PHP
     * cron — see cron_auto_lock()/Transport_activity_cron.php's own
     * externally-triggered convention) or by an HR-initiated manual run;
     * either way, running it twice for the same month changes nothing the
     * second time.
     */
    public function run_leave_accrual()
    {
        $this->_require_role(self::HR_ROLES, 'run_leave_accrual', 'HR', 'manage');

        if (!$this->_school_accrual_enabled()) {
            return $this->json_success([
                'ran' => false, 'reason' => 'Monthly accrual is off for this school. Enable it in Leave settings (Accrual mode) first.',
            ]);
        }

        $throughMonth = trim((string) $this->input->post('through_month'));
        if (!preg_match('/^\d{4}-\d{2}$/', $throughMonth)) $throughMonth = date('Y-m');

        $this->json_success(array_merge(['ran' => true], $this->_accrue_for_school($throughMonth)));
    }

    /**
     * Credit monthly accrual for EVERY active staff member × every
     * accrual_mode='monthly' leave type of the CURRENT school context, through
     * $throughMonth (YYYY-MM). Pure orchestration over the idempotent per-type
     * engine — running it again for the same month credits nothing
     * (lastAccrualMonth). The caller establishes the school context (web:
     * session; cron: per tenant via fs->init) and has already confirmed the
     * school opted in. Returns stats only; never echoes and never gates on RBAC
     * (its two callers do). Single authority for "accrue one school's month".
     */
    private function _accrue_for_school(string $throughMonth): array
    {
        // Delegates to Leave_accrual_service (single accrual authority, 2026-09-20).
        return $this->_accrualSvc()->accrueSchool($throughMonth);
    }

    /**
     * Leave Policy Programme Phase 7 (2026-08-18) — count PENDING leave
     * requests whose from_date falls in $year. Used to BLOCK Leave-Year
     * closure while any exist ("unresolved boundary-crossing Leave
     * requests being silently ignored" is an explicit stop condition) —
     * an approved-after-closure request could still need to consume
     * balance from a year that's already been closed and carried forward.
     * Fails CLOSED (returns 1, never 0) on a read error — an unknown
     * pending-count must never let closure silently proceed.
     */
    private function _count_pending_leave_requests_for_year(string $year): int
    {
        try {
            $docs = $this->fs->schoolWhere('leaveApplications', [
                ['type', '==', 'request'],
                ['status', '==', 'pending'],
            ]);
            $count = 0;
            foreach ((is_array($docs) ? $docs : []) as $d) {
                $rd = is_array($d['data'] ?? null) ? $d['data'] : $d;
                if (!is_array($rd)) continue;
                $from = (string) ($rd['from_date'] ?? $rd['fromDate'] ?? '');
                if (substr($from, 0, 4) === $year) $count++;
            }
            return $count;
        } catch (\Exception $e) {
            log_message('error', 'Hr::_count_pending_leave_requests_for_year failed: ' . $e->getMessage());
            return 1; // fail CLOSED
        }
    }

    /**
     * POST/GET — Leave Policy Programme Phase 7, step 1: PREVIEW a
     * Leave-Year closure. Read-only against balances (computes, never
     * mutates a balance), but DOES persist the preview itself — confirm()
     * replays exactly this snapshot rather than recomputing, so what HR
     * reviewed is what actually gets carried (no TOCTOU between preview
     * and confirm if balances change in between).
     */
    public function preview_leave_year_closure()
    {
        $this->_require_role(self::HR_ROLES, 'preview_leave_year_closure', 'HR', 'view');

        if (!$this->_leave_flag('LEAVE_YEAR_CLOSURE_ENABLED')) {
            $this->json_error('Leave-Year closure is not enabled for this school.');
        }

        $year = trim((string) ($this->input->get('year') ?? $this->input->post('year') ?? date('Y')));
        if (!preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid year format.');
        }
        $nextYear = (string) ((int) $year + 1);

        $pendingCount = $this->_count_pending_leave_requests_for_year($year);
        if ($pendingCount > 0) {
            $this->json_error("Cannot preview closure — {$pendingCount} pending leave request(s) still open for {$year}. Resolve them first.");
        }

        require_once APPPATH . 'libraries/Leave_policy_service.php';
        $activeTypes = $this->_activeLeaveTypes();
        $allBalances = $this->_fsGetLeaveBalance('', $year);
        $allBalances = is_array($allBalances) ? $allBalances : [];

        $perStaff = [];
        foreach ($allBalances as $staffId => $balances) {
            if (!is_array($balances)) continue;
            foreach ($balances as $typeId => $bd) {
                if (!is_array($bd)) continue;
                $unused = max(0.0, (float) ($bd['balance'] ?? 0));
                if ($unused <= 0) continue;

                $type = $activeTypes[$typeId] ?? null;
                $carryForward = false;
                $maxCarry = 0.0;
                if (is_array($type)) {
                    $ver = $this->_leave_flag('LEAVE_EFFECTIVE_DATING_ENABLED')
                        ? \Leave_policy_service::effectiveVersion($type, "{$year}-12-31") : null;
                    $carryForward = (bool) ($ver['carry_forward'] ?? $type['carry_forward'] ?? false);
                    $maxCarry     = (float) ($ver['max_carry'] ?? $type['max_carry'] ?? 0);
                }

                $eligibleCarry = $carryForward ? min($unused, $maxCarry) : 0.0;
                $actualCarry   = $eligibleCarry; // no partial-approval concept — HR excludes rows client-side before confirm if needed
                $forfeited     = max(0.0, $unused - $actualCarry);

                $perStaff[] = [
                    'staffId'       => (string) $staffId,
                    'typeId'        => (string) $typeId,
                    'unused'        => $unused,
                    'maxCarry'      => $maxCarry,
                    'eligibleCarry' => $eligibleCarry,
                    'actualCarry'   => $actualCarry,
                    'forfeited'     => $forfeited,
                ];
            }
        }

        $docId = $this->fs->docId("{$this->school_id}_{$year}");
        try {
            $this->fs->set('leaveYearClosures', $docId, [
                'schoolId'    => $this->school_id,
                'year'        => $year,
                'nextYear'    => $nextYear,
                'status'      => 'previewed',
                'previewedAt' => date('c'),
                'previewedBy' => (string) ($this->admin_id ?? ''),
                'perStaff'    => $perStaff,
            ], true);
        } catch (\Exception $e) {
            log_message('error', 'Hr::preview_leave_year_closure write failed: ' . $e->getMessage());
            $this->json_error('Could not save the closure preview. Please try again.');
        }

        $this->json_success([
            'year'      => $year,
            'nextYear'  => $nextYear,
            'perStaff'  => $perStaff,
            'staffRows' => count($perStaff),
        ]);
    }

    /**
     * POST — Leave Policy Programme Phase 7, step 2: CONFIRM a previously
     * previewed Leave-Year closure. Idempotent via CAS: the closure doc's
     * status is atomically flipped previewed→closing via a
     * `currentDocument.updateTime` precondition BEFORE any balance is
     * touched, exactly mirroring decide_leave()'s pending→processing lock.
     * A second concurrent confirm click loses the CAS race and is refused
     * outright — it is structurally impossible for two callers to both
     * apply the carry, not merely unlikely.
     */
    public function confirm_leave_year_closure()
    {
        $this->_require_role(self::HR_ROLES, 'confirm_leave_year_closure', 'HR', 'manage');

        if (!$this->_leave_flag('LEAVE_YEAR_CLOSURE_ENABLED')) {
            $this->json_error('Leave-Year closure is not enabled for this school.');
        }

        $year = trim((string) $this->input->post('year'));
        if (!preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid year format.');
        }
        $nextYear = (string) ((int) $year + 1);
        $docId = $this->fs->docId("{$this->school_id}_{$year}");

        $closure = $this->fs->get('leaveYearClosures', $docId);
        if (!is_array($closure) || empty($closure)) {
            $this->json_error('No preview found for this year. Run the preview first.');
        }
        $status = (string) ($closure['status'] ?? '');
        if ($status === 'confirmed') {
            // Idempotent — already closed. Report success without
            // re-applying anything (H1 idempotency requirement — a second
            // Confirm click must never carry twice).
            return $this->json_success(['message' => 'This Leave Year was already closed.', 'idempotent' => true, 'year' => $year]);
        }
        if ($status !== 'previewed') {
            $this->json_error("Closure is in an unexpected state ({$status}). Re-run the preview.");
        }

        $pendingCount = $this->_count_pending_leave_requests_for_year($year);
        if ($pendingCount > 0) {
            $this->json_error("Cannot confirm closure — {$pendingCount} pending leave request(s) still open for {$year}. Resolve them first.");
        }

        // CAS lock: previewed -> closing. Mirrors decide_leave()'s own
        // pending->processing transition exactly.
        $lockToken = (string) ($closure['__updateTime'] ?? '');
        $wonLock = false;
        $client = $this->fs->raw_client();
        if ($lockToken !== '' && $client !== null && method_exists($client, 'commitBatch')) {
            $wonLock = $client->commitBatch([[
                'op'           => 'set',
                'collection'   => 'leaveYearClosures',
                'docId'        => $docId,
                'data'         => ['status' => 'closing', 'updatedAt' => date('c')],
                'merge'        => true,
                'precondition' => ['updateTime' => $lockToken],
            ]]);
        } else {
            // No CAS token available — best-effort lock + re-read (weaker
            // guarantee, same documented fallback used throughout Hr.php).
            try {
                $this->fs->update('leaveYearClosures', $docId, ['status' => 'closing', 'updatedAt' => date('c')]);
                $recheck = $this->fs->get('leaveYearClosures', $docId);
                $wonLock = is_array($recheck) && (string) ($recheck['status'] ?? '') === 'closing';
            } catch (\Exception $e) { $wonLock = false; }
        }
        if (!$wonLock) {
            $this->json_error('This closure is being processed by another user, or was already confirmed. Please refresh.');
        }

        $perStaff = is_array($closure['perStaff'] ?? null) ? $closure['perStaff'] : [];
        $byStaff = [];
        foreach ($perStaff as $row) {
            $sid = (string) ($row['staffId'] ?? '');
            if ($sid === '') continue;
            $byStaff[$sid][] = $row;
        }

        $appliedCount = 0;
        foreach ($byStaff as $staffId => $rows) {
            $ok = $this->_casLeaveBalance($staffId, $nextYear, function (array $balances) use ($rows) {
                foreach ($rows as $row) {
                    $tid = (string) ($row['typeId'] ?? '');
                    $carryAmt = (float) ($row['actualCarry'] ?? 0);
                    if ($tid === '' || $carryAmt <= 0) continue;
                    $entry = is_array($balances[$tid] ?? null) ? $balances[$tid] : [];
                    // Additive: a next-year BAL doc may already exist (e.g.
                    // staff created mid-way, or Phase 4 pro-rata already
                    // seeded it) — carry-forward ADDS to whatever carried
                    // already holds, never overwrites it.
                    $entry['carried'] = (float) ($entry['carried'] ?? 0) + $carryAmt;
                    $balances[$tid] = $entry;
                }
                return $balances;
            });
            if ($ok) $appliedCount++;
        }

        try {
            $this->fs->update('leaveYearClosures', $docId, [
                'status'      => 'confirmed',
                'confirmedAt' => date('c'),
                'confirmedBy' => (string) ($this->admin_id ?? ''),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Hr::confirm_leave_year_closure final status write failed: ' . $e->getMessage());
        }

        $this->_log_leave_audit([
            'action'        => 'leave_year_closed',
            'year'          => $year,
            'next_year'     => $nextYear,
            'staff_count'   => count($byStaff),
            'applied_count' => $appliedCount,
        ]);

        $this->json_success([
            'message'        => 'Leave Year closed.',
            'year'           => $year,
            'nextYear'       => $nextYear,
            'staffProcessed' => count($byStaff),
            'staffApplied'   => $appliedCount,
        ]);
    }

    /**
     * Re-propagate an allocation-affecting leave-type change to EVERY current-year
     * stored BAL doc (single source of truth, retroactive). For each staff BAL doc
     * that has — or, being active, SHOULD have — this type, set
     * balances[typeId].allocated = new days_per_year (0 when the type is inactive)
     * and recompute balance = max(0, allocated + carried - used). PRESERVES used +
     * carried. Uses the per-staff CAS path so each write is atomic and floored at 0.
     *
     * Un-allocated staff (no BAL doc) need no write — get_leave_balances derives
     * their entitlement live. Bounded by the active-staff roster / existing docs.
     *
     * @return int number of staff BAL docs updated
     */
    private function _propagate_leave_type_to_balances(string $typeId, array $type, string $year): int
    {
        $isActive  = strtolower(trim((string) ($type['status'] ?? 'Active'))) === 'active';
        $allocated = (int) ($type['days_per_year'] ?? 0);

        $stored = $this->_fsGetLeaveBalance('', $year);
        if (!is_array($stored)) $stored = [];

        // Roster only needed for gender applicability of a gendered type.
        $typeGender = strtolower(trim((string) ($type['gender'] ?? '')));
        $roster = ($typeGender !== '' && $typeGender !== 'all' && $typeGender !== 'any')
            ? $this->_activeStaffRoster()
            : [];

        $count = 0;
        foreach ($stored as $sid => $balances) {
            if (!is_array($balances)) continue;

            // Skip staff a gendered type doesn't apply to.
            if (!empty($roster) || ($typeGender !== '' && $typeGender !== 'all' && $typeGender !== 'any')) {
                $meta = is_array($roster[$sid] ?? null) ? $roster[$sid] : null;
                if (!$this->_leaveTypeAppliesToStaff($type, $meta)) continue;
            }

            $hasEntry = is_array($balances[$typeId] ?? null);
            // Nothing to do: no entry and the type grants no entitlement.
            if (!$hasEntry && !$isActive) continue;

            $ok = $this->_casLeaveBalance($sid, $year, function (array $existing) use ($typeId, $allocated, $isActive) {
                $cur      = is_array($existing[$typeId] ?? null) ? $existing[$typeId] : null;
                $used     = $cur !== null ? (float) ($cur['used'] ?? 0)    : 0.0;
                $carried  = $cur !== null ? (float) ($cur['carried'] ?? 0) : 0.0;
                $newAlloc = $isActive ? $allocated : 0;   // inactive type → no entitlement
                $balance  = $newAlloc + $carried - $used;
                if ($balance < 0) $balance = 0;
                $existing[$typeId] = [
                    'allocated' => $newAlloc,
                    'used'      => $used,
                    'carried'   => $carried,
                    'balance'   => $balance,
                ];
                return $existing;
            });
            if ($ok) $count++;
        }
        return $count;
    }

    /**
     * Read a single leave request by its ID.
     * Tries HR-format doc ID (LR_{id}), then direct doc ID, then RTDB.
     * Normalizes Teacher-app camelCase fields to HR snake_case.
     *
     * Firestore source: leaveApplications collection
     * RTDB fallback:    Schools/{school}/HR/Leaves/Requests/{id}
     *
     * @return array|null  Request data with normalized field names, or null
     */
    private function _fsGetLeaveRequest(string $id): ?array
    {
        $request = null;

        // Try 1: HR-format doc ID (LR_{id})
        try {
            $hrDocId = $this->fs->docId("LR_{$id}");
            $fsDoc = $this->fs->get('leaveApplications', $hrDocId);
            if (is_array($fsDoc) && !empty($fsDoc)) {
                $request = $fsDoc;
                $request['_fsDocId'] = $hrDocId;
            }
        } catch (\Exception $e) {}

        // Try 2: direct doc ID (Teacher-app format)
        if (!is_array($request)) {
            try {
                $fsDoc = $this->firebase->firestoreGet('leaveApplications', $id);
                if (is_array($fsDoc) && !empty($fsDoc)) {
                    $request = $fsDoc;
                    $request['_fsDocId'] = $id;
                }
            } catch (\Exception $e) {}
        }

        // If both Firestore attempts returned nothing, doc doesn't exist
        if (!is_array($request)) return null;

        // Normalize Teacher-app camelCase → HR snake_case
        if (!isset($request['staff_id']))   $request['staff_id']   = $request['staffId']       ?? $request['applicantId']   ?? '';
        if (!isset($request['staff_name'])) $request['staff_name'] = $request['staffName']     ?? $request['applicantName'] ?? '';
        if (!isset($request['type_id']))    $request['type_id']    = $request['typeId']        ?? $request['leaveType']     ?? '';
        // Consolidated reconciliation audit (2026-08-19) — DEFECT FIX.
        // type_code was the ONE field the sibling reader
        // _fsGetAllLeaveRequests() normalises that this one did not.
        // cancel_leave() branches on it to decide between releasing
        // Comp-Off ledger credits and restoring a standard leave balance
        // (Hr.php ~5536, an if/elseif — the two are mutually exclusive).
        // Without this line a Comp-Off request whose doc carries only the
        // camelCase `typeCode` — every Teacher-app-filed request, plus any
        // request where decide_leave() could not resolve the type and wrote
        // '?' — fell into the STANDARD-balance branch on cancellation,
        // which both credited a BAL doc that was never debited AND left the
        // consumed Comp-Off credits permanently stranded as 'consumed'.
        // Verified non-disruptive at all three callers: decide_leave reads
        // `leaveType` ahead of `type_code` for its display string and only
        // ever WRITES type_code otherwise, so only cancel_leave's branch
        // changes — which is the defect being repaired.
        if (!isset($request['type_code']))  $request['type_code']  = $request['typeCode']      ?? $request['leaveType']     ?? '';
        if (!isset($request['from_date']))  $request['from_date']  = $request['fromDate']      ?? $request['startDate']     ?? '';
        if (!isset($request['to_date']))    $request['to_date']    = $request['toDate']        ?? $request['endDate']       ?? '';
        if (!isset($request['paid_days']))  $request['paid_days']  = $request['paidDays']      ?? 0;
        if (!isset($request['lwp_days']))   $request['lwp_days']   = $request['lwpDays']       ?? 0;
        if (!isset($request['days']))       $request['days']       = $request['numberOfDays']  ?? 1;
        if (!isset($request['applied_on'])) $request['applied_on'] = $request['appliedOn']     ?? $request['appliedAt']     ?? '';

        return $request;
    }

    /**
     * Read ALL leave requests (optionally filtered by staff).
     * Firestore-first, RTDB fallback. Normalizes field names.
     *
     * @param  string $staffId  Optional staff filter
     * @return array  Map of requestId => request data (may be empty)
     */
    private function _fsGetAllLeaveRequests(string $staffId = ''): array
    {
        $result = null;

        try {
            // Query HR-format docs (type=request)
            $cond = [['type', '==', 'request']];
            if ($staffId !== '') $cond[] = ['staffId', '==', $staffId];
            $fsDocs = $this->fs->schoolWhere('leaveApplications', $cond);

            // Also query Teacher-app format (applicantType=staff)
            $cond2 = [['applicantType', '==', 'staff']];
            if ($staffId !== '') $cond2[] = ['applicantId', '==', $staffId];
            $fsDocs2 = $this->fs->schoolWhere('leaveApplications', $cond2);

            $allDocs = array_merge($fsDocs ?: [], $fsDocs2 ?: []);

            $result = [];
            $seen = [];
            foreach ($allDocs as $doc) {
                $docId = $doc['id'] ?? '';
                if ($docId === '' || isset($seen[$docId])) continue;
                $seen[$docId] = true;
                $rd = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                // Normalize
                if (!isset($rd['staff_id']))   $rd['staff_id']   = $rd['staffId']      ?? $rd['applicantId']   ?? '';
                if (!isset($rd['staff_name'])) $rd['staff_name'] = $rd['staffName']    ?? $rd['applicantName'] ?? '';
                if (!isset($rd['type_id']))    $rd['type_id']    = $rd['typeId']       ?? $rd['leaveType']     ?? '';
                if (!isset($rd['from_date']))  $rd['from_date']  = $rd['fromDate']     ?? $rd['startDate']     ?? '';
                if (!isset($rd['to_date']))    $rd['to_date']    = $rd['toDate']       ?? $rd['endDate']       ?? '';
                if (!isset($rd['type_code']))  $rd['type_code']  = $rd['typeCode']     ?? $rd['leaveType']     ?? '';
                if (!isset($rd['paid_days']))  $rd['paid_days']  = $rd['paidDays']     ?? 0;
                if (!isset($rd['lwp_days']))   $rd['lwp_days']   = $rd['lwpDays']      ?? 0;
                if (!isset($rd['days']))       $rd['days']       = $rd['numberOfDays'] ?? 1;
                // Teacher app writes `appliedAt` (camelCase ISO string).
                // Several admin widgets sort by `applied_on` and would
                // bucket Teacher rows with an empty timestamp without this.
                if (!isset($rd['applied_on'])) $rd['applied_on'] = $rd['appliedOn']    ?? $rd['appliedAt']     ?? '';
                $result[$docId] = $rd;
            }
        } catch (\Exception $e) {
            log_message('error', "HR _fsGetAllLeaveRequests: Firestore failed: " . $e->getMessage());
        }

        // If Firestore failed entirely, return empty
        if ($result === null) {
            $result = [];
        }

        return $result;
    }

    /**
     * Write an immutable audit entry for leave/payroll decisions.
     * Firestore-first → RTDB mirror.
     */
    private function _log_leave_audit(array $data): void
    {
        // MEDIUM (audit integrity): `school_id` must be the real school id
        // (SCH_XXXXXX), not the school NAME. Keep the display name in a
        // separate `school_name` field. Fixes cross-tenant audit ambiguity.
        // TODO(audit): tamper-evident hash-chaining (prev-hash link per entry)
        // is out of scope for this pass — add a `prev_hash`/`entry_hash` chain
        // so audit rows become append-only-verifiable.
        $entry = array_merge([
            'school_id'   => $this->school_id,
            'school_name' => $this->school_name,
            'admin_id'    => $this->admin_id ?? '',
            'admin_name'  => $this->admin_name ?? '',
            'ip'          => $this->input->ip_address(),
            'timestamp'   => date('c'),
        ], $data);

        // 1. Firestore FIRST
        try {
            $pushId = 'AUDIT_' . uniqid('', true);
            $fsEntry = $entry;
            $fsEntry['schoolId'] = $this->school_id;
            $fsEntry['type']     = 'audit';
            $this->fs->set('leaveApplications', $this->fs->docId($pushId), $fsEntry, true);
            log_message('debug', "HR: Leave audit → Firestore OK");
        } catch (\Exception $e) {
            log_message('error', "HR: Leave audit → Firestore FAILED: " . $e->getMessage());
        }

    }

    // ====================================================================
    //  FIRESTORE SYNC HELPERS — mirrors HR data for app/query access
    // ====================================================================

    /**
     * W3.2.1 — employment-window mask for the attendance snapshot.
     *
     * '1' = the day is inside the employment window and therefore countable;
     * '0' = pre-join or post-exit, which the calculation skips entirely.
     *
     * A per-day mask rather than the startDay/endDay pair already on the slip:
     * that pair describes a single contiguous window, and a rehire within one
     * month is not contiguous. Reproducing from the pair would quietly pay a
     * rehired employee for the gap.
     *
     * Static and pure so the reproducibility tests can exercise it without a
     * CodeIgniter context.
     */
    public static function _att_window_mask(array $inWindow, int $daysInMonth): string
    {
        require_once APPPATH . 'helpers/payroll_snapshot_helper.php';
        return payroll_window_mask($inWindow, $daysInMonth);
    }

    /**
     * W3.2.1 — leave-without-pay mask for the attendance snapshot.
     *
     *   '-' no LWP · 'F' full-day LWP · 'H' half-day LWP
     *
     * The distinction is load-bearing, not cosmetic: the calculation treats a
     * full-day LWP as fully covering an 'A' mark (0 absent added) but a
     * half-day LWP as covering only half (0.5 added). Collapsing both to a
     * boolean would make the snapshot unable to reproduce the absent count it
     * sits next to.
     */
    /**
     * How much of ONE day's expected work is already covered by approved leave,
     * expressed in expected-work units and capped at the day's expectation.
     *
     * WHY THIS EXISTS (2026-08-19). The absence loop had three branches — 'A',
     * 'V' and 'M' — each applying leave coverage differently:
     *   • 'A' honoured the LWP set but ignored PAID coverage;
     *   • 'M' honoured both, but as an all-or-nothing skip;
     *   • 'V' — the UNMARKED-day fill (Staff_attendance_writer initialises a
     *     month as str_repeat('V', …)) — honoured NEITHER.
     *
     * That last one is the damaging case, because a half-day leave deliberately
     * writes no attendance mark: the covered day stays 'V'. With vacant
     * treatment 'absent' the employee was charged the whole day's expectation
     * despite approved leave, and a full-day unpaid leave was charged TWICE —
     * once as lwpDays by the leave engine and again as daysAbsent here. Both
     * are over-deductions: the employee is underpaid.
     *
     * These are the deductions that decide someone's pay, so they now come from
     * one place instead of three. Coverage is capped at $dayExpected so leave
     * can never manufacture credit, and 'M' subtracts coverage from the half it
     * actually leaves uncovered rather than skipping wholesale — a partial LWP
     * portion that covers less than the missing half now correctly leaves the
     * remainder chargeable.
     *
     * @param float      $dayExpected expected work for this day (1.0, or 0.5 on a Calendar Half-Day)
     * @param mixed      $lwpEntry    LWP set entry: true = whole day, float = portion, null = none
     * @param mixed      $paidEntry   paid-leave coverage portion, or null
     */
    public static function _leave_coverage_for_day(float $dayExpected, $lwpEntry, $paidEntry): float
    {
        $cov = 0.0;
        if ($lwpEntry !== null) {
            $cov += ($lwpEntry === true) ? $dayExpected : (float) $lwpEntry;
        }
        if ($paidEntry !== null) {
            $cov += (float) $paidEntry;
        }
        if ($cov < 0.0) $cov = 0.0;
        return ($cov > $dayExpected) ? $dayExpected : $cov;
    }

    public static function _att_lwp_mask(array $lwpSet, int $daysInMonth): string
    {
        require_once APPPATH . 'helpers/payroll_snapshot_helper.php';
        return payroll_lwp_mask($lwpSet, $daysInMonth);
    }

    /**
     * Payroll-period override service (ZenX HRMS W3.2.2).
     *
     * Memoised per request: the review grid, a drill-down and a recalculation
     * can each ask for it without re-constructing, and `forRun()` is a single
     * bulk query rather than a per-employee read.
     */
    private function _payroll_overrides(): Payroll_override_service
    {
        static $svc = null;
        if ($svc !== null) return $svc;

        require_once APPPATH . 'libraries/Payroll_override_service.php';
        $svc = new Payroll_override_service();
        $svc->init($this->fs, $this->admin_name ?: $this->admin_id);
        return $svc;
    }

    /**
     * The compensation seam (ZenX HRMS W3.1).
     *
     * Every payroll read of "what is this employee paid" goes through here, so
     * the later W2 component cutover is a MODE change in one place rather than
     * a rewrite of the payroll run. With both flags false this resolves to
     * MODE_LEGACY, whose bulk read is the same single `schoolWhere()` query the
     * inline loop used to perform — the swap is byte-identical, not merely
     * equivalent.
     *
     * The flag is read here and nowhere else in the payroll path, and this
     * controller still queries none of the W2 component-model collections
     * itself — resolving those is the reader's job, and the source-level guards
     * in the W2 test suite assert that by searching this file for their names.
     * (Which is why those names are deliberately not written out here.)
     */
    private function _compensation_reader(): Compensation_reader
    {
        static $reader = null;
        if ($reader !== null) return $reader;

        require_once APPPATH . 'libraries/Compensation_reader.php';
        $this->config->load('hrms_compensation_flags', true);
        $mode = Compensation_reader::modeFromFlags(
            (bool) $this->config->item('USE_COMPONENT_COMPENSATION', 'hrms_compensation_flags'),
            (bool) $this->config->item('COMPENSATION_SHADOW_COMPARE', 'hrms_compensation_flags')
        );

        // Nothing about the component model is named here on purpose: the
        // reader builds whatever it needs for the mode it was given, so the
        // payroll controller depends on the seam and on nothing behind it.
        $reader = new Compensation_reader();
        $reader->init($this->fs, $mode);
        return $reader;
    }

    /**
     * The tenant's statutory configuration in force on a given date, or NULL.
     *
     * WHY THIS EXISTS (S-1, found 2026-08-22)
     * ---------------------------------------
     * `Statutory_config_service` is a complete, effective-dated, overlap-checked
     * configuration system with its own Admin UI (`Compensation::
     * get_statutory_config` / `save_statutory_config`). Every one of the four
     * `_compute_payslip()` call sites passed a hard-coded `null` for
     * `$statutory`, so the statutory-aware branch was unreachable and a tenant
     * that configured a PF ceiling, an eligibility rule or a PT slab table got
     * NOTHING — the configuration was accepted, stored, listed back in the UI,
     * and silently ignored by payroll.
     *
     * That is worse than an unimplemented feature: it is a setting that lies.
     *
     * FAIL-SAFE BY CONSTRUCTION
     * -------------------------
     * A tenant with NO statutory configuration resolves to `null`, which is
     * exactly the legacy branch and byte-for-byte today's behaviour. So wiring
     * this changes nothing until a tenant deliberately creates a configuration —
     * which is opt-in, per tenant, through the UI. There are zero statutory
     * configurations in any tenant at the time of writing.
     *
     * AS-OF, NOT NOW
     * --------------
     * Resolution is by the PERIOD date, never by today. A configuration created
     * in September must not reprice July, and `ed_select_for()` — the same
     * effective-dating helper salary assignments use — is what guarantees that.
     * A single shared helper is deliberate: two implementations would eventually
     * disagree, and the disagreement would surface as a period resolving to one
     * statutory config but a different salary assignment.
     *
     * Memoised per (date) for the life of the request, so a 200-employee run
     * costs ONE Firestore read rather than 200.
     */
    private function _statutory_config_for(string $asOfDate): ?array
    {
        static $memo = [];
        if (array_key_exists($asOfDate, $memo)) return $memo[$asOfDate];

        $resolved = null;
        try {
            if (!class_exists('Statutory_config_service')) {
                require_once APPPATH . 'libraries/Statutory_config_service.php';
            }
            $svc = new Statutory_config_service();
            $svc->init($this->fs, (string) ($this->admin_name ?? ''));

            $all = $svc->listAll();
            if (!empty($all)) {
                $resolved = Statutory_config_service::selectFor($all, $asOfDate);
            }
        } catch (\Throwable $e) {
            // A statutory read failure must NEVER silently fall back to a
            // different money answer. Null is the legacy branch, which is the
            // conservative outcome, and it is logged loudly rather than absorbed.
            log_message('error', 'HR statutory config resolution failed for '
                . $asOfDate . ' — ' . $e->getMessage());
            $resolved = null;
        }

        return $memo[$asOfDate] = $resolved;
    }

    /**
     * One work location, memoised for the life of the request.
     *
     * FEATURE 2. ESI applicability is a property of a PLACE, so a payroll run
     * has to know where each employee works. A school has a handful of
     * locations and hundreds of staff, so this is memoised per locationId —
     * without it a 200-employee run would issue 200 reads for perhaps three
     * distinct campuses.
     *
     * A missing or unreadable location returns null, which the resolver treats
     * as "inherit the tenant-wide setting" — never as "not applicable".
     */
    private function _location_doc(string $locationId): ?array
    {
        static $memo = [];
        $locationId = trim($locationId);
        if ($locationId === '') return null;
        if (array_key_exists($locationId, $memo)) return $memo[$locationId];

        $doc = null;
        try {
            $row = $this->fs->get('locations', $this->fs->docId($locationId));
            if (is_array($row) && $row !== []) $doc = $row;
        } catch (\Throwable $e) {
            log_message('error', 'HR location read failed for ' . $locationId . ' — ' . $e->getMessage());
        }

        return $memo[$locationId] = $doc;
    }

    /**
     * The employee-level statutory facts payroll needs, assembled once.
     *
     * Both the PF membership profile and the ESI work-location applicability
     * are facts about the PERSON, so they travel together. `_esiLocationApplicable` is
     * a RESOLVED hint written by the caller — null means nobody decided, which
     * the engine distinguishes from an explicit false.
     */
    private function _staff_statutory_facts(array $staffDoc, ?array $statutoryCfg): array
    {
        // Loaded HERE, not relied on from _compute_payslip: this method runs
        // FIRST, so the class would not yet exist. php -l cannot see that.
        if (!class_exists('Staff_statutory_profile_service')) {
            require_once APPPATH . 'libraries/Staff_statutory_profile_service.php';
        }
        if (!class_exists('Statutory_config_service')) {
            require_once APPPATH . 'libraries/Statutory_config_service.php';
        }

        $facts = is_array($staffDoc[Staff_statutory_profile_service::FIELD] ?? null)
            ? $staffDoc[Staff_statutory_profile_service::FIELD] : [];

        $locationId = trim((string) ($staffDoc['locationId'] ?? $staffDoc['location_id'] ?? ''));
        if (is_array($statutoryCfg)) {
            $resolved = Statutory_config_service::resolveEsiApplicability(
                $statutoryCfg,
                $locationId !== '' ? $this->_location_doc($locationId) : null
            );
            $facts['_esiLocationApplicable'] = $resolved['applicable'];
            $facts['_esiSource']     = $resolved['source'];
            $facts['_esiReason']     = $resolved['reason'];
        }

        return $facts;
    }

    /**
     * The reproducibility snapshot for a resolved statutory configuration.
     *
     * A finalized payslip must stay reproducible across a later statutory
     * change. Storing the resolved window's identity with the run is what makes
     * "why was July computed this way?" answerable in December.
     */
    private function _statutory_snapshot(?array $statutory): array
    {
        if (!is_array($statutory)) {
            return [
                'configured'    => false,
                'source'        => 'structure',
                'effectiveFrom' => null,
                'effectiveTo'   => null,
                'note'          => 'No tenant statutory configuration in force for this period; '
                    . 'rates were taken from the salary structure.',
            ];
        }

        return [
            'configured'    => true,
            'source'        => 'statutoryConfig',
            'effectiveFrom' => (string) ($statutory['effective_from'] ?? $statutory['effectiveFrom'] ?? ''),
            'effectiveTo'   => (string) ($statutory['effective_to'] ?? $statutory['effectiveTo'] ?? ''),
            'pf'            => is_array($statutory['pf'] ?? null) ? $statutory['pf'] : null,
            'esi'           => is_array($statutory['esi'] ?? null) ? $statutory['esi'] : null,
            'pt'            => is_array($statutory['pt'] ?? null) ? $statutory['pt'] : null,
            'tds'           => is_array($statutory['tds'] ?? null) ? $statutory['tds'] : null,
            'note'          => 'Resolved as of the period end and frozen with the run.',
        ];
    }
    /**
     * Sync salary structure to Firestore salarySlips collection.
     * Best-effort — never blocks RTDB writes.
     */
    private function _fsSyncSalary(string $staffId, array $data): void
    {
        try {
            $fsData = array_merge($data, [
                'schoolId'  => $this->school_id,
                'staffId'   => $staffId,
                'type'      => 'salary_structure',
                'updatedAt' => date('c'),
            ]);
            unset($fsData['_prev']); // don't store audit trail in Firestore
            $this->fs->set('salarySlips', $this->fs->docId("SAL_{$staffId}"), $fsData, true);
            log_message('debug', "HR: Salary structure → Firestore OK for {$staffId}");
        } catch (\Exception $e) {
            log_message('error', "HR: Salary structure → Firestore FAILED for {$staffId}: " . $e->getMessage());
        }
    }

    /**
     * Sync a payroll run to Firestore.
     *
     * Phase HR-2C — dual-emit canonical camelCase mirror alongside the
     * existing snake_case fields. Admin UI keeps reading snake_case; mobile
     * apps (Teacher run-summary) read camelCase. Same dual-emit pattern as
     * `_fsSyncPayslip` (already shipped in the April 15 canonical work) —
     * the run doc was overlooked at that time and the gap was confirmed by
     * today's audit (see memory `hr_payroll_canonical_schema.md`).
     */
    private function _fsSyncPayrollRun(string $runId, array $runData, ?string $casUpdateTime = null): bool
    {
        try {
            // ── Canonical camelCase mirror (Teacher app) ──
            $monthName = (string) ($runData['month'] ?? '');
            $year      = (string) ($runData['year']  ?? '');
            $monthIdx  = $monthName !== '' ? (int) date('m', strtotime("1 {$monthName} 2000")) : 0;
            $monthKey  = ($year !== '' && $monthIdx > 0)
                ? sprintf('%s-%02d', $year, $monthIdx)
                : '';

            $canonical = [
                'monthKey'  => $monthKey,
                'totalNet'  => (float) ($runData['total_net']  ?? 0),
                'totalPaid' => (float) ($runData['total_paid'] ?? 0),
                'status'    => (string) ($runData['status']    ?? 'Draft'),
            ];

            $fsData = array_merge($runData, $canonical, [
                'schoolId'  => $this->school_id,
                'session'   => $this->session_year,
                'type'      => 'run',
                // OFF-CYCLE (2026-08-22) — every run declares its kind.
                //
                // `monthly` is the default and is what every existing run is,
                // so a document written before this field existed reads as
                // monthly wherever it is consumed (`?? self::RUN_TYPE_MONTHLY`).
                // The distinction matters because monthly runs are unique per
                // school/month/year — two would pay the school's staff twice —
                // while an off-cycle run (F&F, arrears, bonus) is a different
                // event that may legitimately coexist with the monthly one.
                'runType'   => (string) ($runData['runType'] ?? self::RUN_TYPE_MONTHLY),
                'runId'     => $runId,
                'updatedAt' => date('c'),
            ]);
            // Never persist the read-only metadata token, even if a caller
            // threads the raw run doc through.
            unset($fsData['__updateTime']);

            // LC-P3b — optimistic-concurrency (CAS) merge write. When a caller
            // supplies the run doc's updateTime (captured from the read it
            // already performed — no extra read), the write is conditioned on
            // that token via Firestore currentDocument.updateTime. Same MERGE
            // semantics as fs->set(merge=true), but the whole write fails
            // (returns false) if a concurrent request modified the run since
            // it was read. The caller then aborts, so two simultaneous
            // payments cannot both apply. One write, one round trip — no
            // additional reads/writes/RTTs vs the legacy fs->set path.
            if ($casUpdateTime !== null && $casUpdateTime !== '') {
                $ok = (bool) $this->firebase->firestoreCommitBatch([[
                    'op'           => 'set',
                    'merge'        => true,
                    'collection'   => 'salarySlips',
                    'docId'        => $this->fs->docId("RUN_{$runId}"),
                    'data'         => $fsData,
                    'precondition' => ['updateTime' => $casUpdateTime],
                ]]);
                if (!$ok) {
                    log_message('error', "HR: Payroll run CAS conflict for {$runId} (updateTime={$casUpdateTime})");
                }
                return $ok;
            }

            $this->fs->set('salarySlips', $this->fs->docId("RUN_{$runId}"), $fsData, true);
            log_message('debug', "HR: Payroll run → Firestore OK for {$runId} (monthKey={$monthKey})");
            return true;
        } catch (\Exception $e) {
            log_message('error', "HR: Payroll run → Firestore FAILED for {$runId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Build the payslip document exactly as it is stored (ZenX HRMS W3.2.5).
     *
     * Extracted so the single-write and batched-write paths CANNOT diverge.
     * Two builders would eventually disagree on a field, and the difference
     * would show up as a payslip that renders differently depending on whether
     * it was written by a small run or a large one.
     */
    private function _buildPayslipDoc(string $runId, string $staffId, array $slipData): array
    {
        // Canonical camelCase mirror for cross-system consumers (Teacher app SalarySlipDoc).
        // Snake_case kept alongside so admin-side code that reads these docs continues to work.
        $canonical = [
                'staffName'       => (string) ($slipData['staff_name'] ?? ''),
                'netPayable'      => (float) ($slipData['net_pay'] ?? 0),
                'grossEarnings'   => (float) ($slipData['gross'] ?? 0),
                'totalDeductions' => (float) ($slipData['total_deductions'] ?? 0),
                // Fraction safety (2026-08-19) — these were (int) casts, which
                // TRUNCATED values that are already fractional today: an
                // attendance 'M' yields days_absent = 0.5, a half-day LWP
                // yields lwp_days = 0.5, and days_worked is derived from both.
                // The stored mirror therefore said 0 while the snake_case
                // source said 0.5, and the Teacher app read the truncated
                // value. Truncating at WRITE time destroys the information
                // permanently, so the document now carries the true number.
                //
                // The Teacher app's SalarySlipDoc now declares all five of these
                // as Double (widened 2026-08-20), so the true fractional value
                // survives the read as well as the write. Do NOT narrow either
                // side back to Int: an attendance 'M' or a half-day LWP is 0.5,
                // and truncating at either end destroys that permanently.
                // Covered by PayslipDayFormattingTest (Teacher, JVM).
                'workingDays'     => (float) ($slipData['working_days'] ?? 0),
                'presentDays'     => (float) ($slipData['days_worked'] ?? 0),
                'daysAbsent'      => (float) ($slipData['days_absent'] ?? 0),
                'lwpDays'         => (float) ($slipData['lwp_days'] ?? 0),
                'paidLeaveDays'   => (float) ($slipData['paid_leave_days'] ?? 0),
                // Accepts either spelling: generate_payroll() supplies `month_key`,
                // regenerate_staff_payroll() carries the already-camel `monthKey`.
                // Without the fallback, routing the regeneration path through this
                // builder would blank the key it already holds.
                'monthKey'        => (string) ($slipData['month_key'] ?? $slipData['monthKey'] ?? ''),
                'earnings'        => [
                    'basic'           => (float) ($slipData['basic'] ?? 0),
                    'hra'             => (float) ($slipData['hra'] ?? 0),
                    'da'              => (float) ($slipData['da'] ?? 0),
                    'ta'              => (float) ($slipData['ta'] ?? 0),
                    'medical'         => (float) ($slipData['medical'] ?? 0),
                    'otherAllowances' => (float) ($slipData['other_allowances'] ?? 0),
                ],
                'deductions'      => [
                    'pfEmployee'      => (float) ($slipData['pf_employee'] ?? 0),
                    'esiEmployee'     => (float) ($slipData['esi_employee'] ?? 0),
                    'professionalTax' => (float) ($slipData['professional_tax'] ?? 0),
                    'tds'             => (float) ($slipData['tds'] ?? 0),
                    'otherDeductions' => (float) ($slipData['other_deductions'] ?? 0),
                ],
            ];

        return array_merge($slipData, $canonical, [
            'schoolId'  => $this->school_id,
            'staffId'   => $staffId,
            'runId'     => $runId,
            'type'      => 'payslip',
            'updatedAt' => date('c'),
        ]);
    }

    /** Single-slip write. Unchanged behaviour; the document now comes from the shared builder. */
    private function _fsSyncPayslip(string $runId, string $staffId, array $slipData): void
    {
        try {
            $this->fs->set(
                'salarySlips',
                $this->fs->docId("SLIP_{$runId}_{$staffId}"),
                $this->_buildPayslipDoc($runId, $staffId, $slipData),
                true
            );
        } catch (\Exception $e) {
            log_message('error', "HR: Payslip → Firestore FAILED for {$staffId}: " . $e->getMessage());
        }
    }

    /**
     * BATCHED slip write for a whole run (ZenX HRMS W3.2.5).
     *
     * WHY THIS EXISTS — the measured bottleneck
     * -----------------------------------------
     * `generate_payroll()` wrote slips in a `foreach` of single `set()` calls:
     * one Firestore round-trip per employee, sequentially. Every INPUT was
     * already bulk-read in one query each, so reads were never the problem —
     * the run cost N round-trips on the write side and nothing else.
     *
     * `Firestore_service::batchWrite()` already existed, already chunks at 450
     * under Firestore's 500-per-request limit, and already falls back to
     * per-doc `set()` on a client that lacks batch support. Payroll simply
     * never called it.
     *
     * SAME BYTES. The documents come from `_buildPayslipDoc()` — the same
     * builder the single-write path uses — and `merge` stays `true`, so a
     * batched run stores exactly what a sequential run stored.
     *
     * FAILURE BEHAVIOUR IS PRESERVED, NOT IMPROVED HERE. The old loop swallowed
     * a per-slip exception and continued; this returns the ids that failed so
     * the caller can report them per employee instead of losing them. No slip
     * is silently dropped either way.
     *
     * @return array{written:int, failed:string[]}
     */
    private function _fsSyncPayslipsBatch(string $runId, array $slipsByStaff): array
    {
        if (empty($slipsByStaff)) return ['written' => 0, 'failed' => []];

        $docs = [];
        foreach ($slipsByStaff as $staffId => $slipData) {
            if (!is_array($slipData)) continue;
            $docs[$this->fs->docId("SLIP_{$runId}_{$staffId}")]
                = $this->_buildPayslipDoc($runId, (string) $staffId, $slipData);
        }
        if (empty($docs)) return ['written' => 0, 'failed' => []];

        $failed = [];
        try {
            $result = $this->fs->batchWrite('salarySlips', $docs, true);
            foreach ($docs as $docId => $_) {
                if (empty($result[$docId])) $failed[] = (string) $docId;
            }
        } catch (\Exception $e) {
            log_message('error', "HR: Payslip batch → Firestore FAILED run={$runId}: " . $e->getMessage());
            $failed = array_map('strval', array_keys($docs));
        }

        $written = count($docs) - count($failed);
        log_message('info', "PAYROLL_SLIP_BATCH run={$runId} docs=" . count($docs)
            . " written={$written} failed=" . count($failed));

        return ['written' => $written, 'failed' => $failed];
    }

    /**
     * Sync leave balance to Firestore — ATOMICALLY (HIGH H3).
     *
     * Was a plain read-merge-write (lost-update / balance-inflation prone).
     * Now delegates to _casLeaveBalance() which uses a documented compare-and-set
     * loop (Firestore `currentDocument.updateTime` precondition via commitBatch),
     * and floors every stored balance at 0 so NO branch can persist a negative.
     *
     * @return bool true only on a confirmed write (callers may check).
     */
    private function _fsSyncLeaveBalance(string $staffId, string $year, array $balances): bool
    {
        return $this->_casLeaveBalance($staffId, $year, function (array $existing) use ($balances) {
            // FIELD-LEVEL merge, not type-level (2026-08-20).
            //
            // `array_merge($existing, $balances)` replaced each TYPE's entry
            // wholesale, so every key the calling writer did not restate was
            // deleted. allocate_leave_balances() writes exactly four keys —
            // allocated / used / carried / balance — while the monthly accrual
            // engine owns two others on the same entry: `accruedToDate` and
            // `lastAccrualMonth`.
            //
            // One allocate run therefore erased the accrual ledger, and because
            // accrual resumes from `lastAccrualMonth`, the next run restarted at
            // the year's beginning and CREDITED THE SAME MONTHS AGAIN. Silent
            // data loss followed by silent double-credit.
            //
            // Merging per field keeps one canonical writer per fact: each engine
            // updates what it owns and leaves the rest untouched.
            $merged = $existing;
            foreach ($balances as $tid => $bd) {
                $prev = $existing[$tid] ?? null;
                $merged[$tid] = (is_array($prev) && is_array($bd)) ? array_merge($prev, $bd) : $bd;
            }
            // Negative guard (H3): no type may persist a negative balance/used.
            foreach ($merged as $tid => $bd) {
                if (!is_array($bd)) continue;
                if (isset($bd['balance']) && (float) $bd['balance'] < 0) $bd['balance'] = 0;
                if (isset($bd['used'])    && (float) $bd['used']    < 0) $bd['used']    = 0;
                $merged[$tid] = $bd;
            }
            return $merged;
        });
    }

    /**
     * Atomic read-modify-write of a staff member's leave-balance document via a
     * documented compare-and-set (CAS) loop. (HIGH H3)
     *
     * Our Firestore REST client exposes NO multi-document transaction primitive,
     * so we use the strongest one it DOES expose: read the doc (which surfaces
     * the server-assigned `__updateTime` through decodeDocument), compute the new
     * `balances` map in PHP, then `commitBatch` a single set with a
     * `currentDocument.updateTime` precondition. If a concurrent writer moved the
     * doc, the commit fails with FAILED_PRECONDITION and we retry against a fresh
     * read — eliminating the lost-update / repeatable-inflation window. A missing
     * doc is created with an `exists:false` precondition (create-or-collide).
     *
     * $mutate(array $balancesMap): array — MUST return the FULL new balances map.
     *
     * @return bool true on a confirmed successful write, false after exhausting
     *              retries (caller must treat as failure — never a false success).
     */
    private function _casLeaveBalance(string $staffId, string $year, callable $mutate, int $maxAttempts = self::LEAVE_CAS_MAX_ATTEMPTS): bool
    {
        $docId  = $this->fs->docId("BAL_{$staffId}_{$year}");
        $client = $this->fs->raw_client();

        for ($attempt = 0; $attempt < max(1, $maxAttempts); $attempt++) {
            $doc      = $this->fs->get('leaveApplications', $docId);
            $exists   = is_array($doc) && !empty($doc);
            $balances = ($exists && is_array($doc['balances'] ?? null)) ? $doc['balances'] : [];

            $newBalances = $mutate($balances);
            if (!is_array($newBalances)) return false;

            $data = [
                'schoolId'  => $this->school_id ?? $this->school_name,
                'staffId'   => $staffId,
                'year'      => $year,
                'balances'  => $newBalances,
                'type'      => 'balance',
                'updatedAt' => date('c'),
            ];

            // No CAS token available (e.g. non-REST client) → best-effort merge write.
            if ($client === null || !method_exists($client, 'commitBatch')
                || ($exists && empty($doc['__updateTime']))) {
                return (bool) $this->fs->set('leaveApplications', $docId, $data, true);
            }

            $precondition = $exists
                ? ['updateTime' => (string) $doc['__updateTime']]  // CAS
                : ['exists' => false];                             // create-or-collide

            $ok = $client->commitBatch([[
                'op'           => 'set',
                'collection'   => 'leaveApplications',
                'docId'        => $docId,
                'data'         => $data,
                'merge'        => true,
                'precondition' => $precondition,
            ]]);
            if ($ok) return true;

            // Precondition failed → a concurrent writer won; back off and retry
            // against a fresh read (commitBatch already flushed the read memo).
            usleep(40000 * ($attempt + 1));
        }
        log_message('error', "HR _casLeaveBalance: CAS exhausted {$maxAttempts} attempts for {$staffId}/{$year}");
        return false;
    }

    // ====================================================================
    //  COMP-OFF LEDGER
    //  Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP →
    //  Payroll), Phase E, 2026-08-18.
    //
    //  Prior audit (this same programme) confirmed COMP_OFF existed only as
    //  a seeded leave-type row with days_per_year=0 — no earn/consume
    //  ledger, so it could never behave as a genuine earned benefit (the
    //  standard balance formula `allocated + carried - used` is always 0
    //  when `allocated` is always 0). This section adds the missing
    //  lifecycle WITHOUT touching that formula or any other leave type —
    //  Comp-Off gets its own, entirely separate balance source.
    //
    //  Deliberately scoped to avoid inventing financial policy (per the
    //  standing instruction):
    //    - Earn trigger: an EXPLICIT HR/manager action (earn_comp_off()),
    //      never automatic from a punch alone — but it may ONLY be taken
    //      against a date already marked 'W' (extraWorked, "worked a
    //      scheduled rest day") by the EXISTING Attendance_policy engine,
    //      so no new "was this a rest day worked" judgement is invented
    //      here — it reuses the one that already exists.
    //    - Earn ratio: 1 worked rest day = 1 Comp-Off day. A technical
    //      default (not a negotiated policy) — the same posture the Staff
    //      HR programme already took for NOTICE_PERIOD_DEFAULT_DAYS=30.
    //    - Expiry: DELIBERATELY NOT IMPLEMENTED. An `expiresOn` field
    //      exists on each credit but is left null/unset — no automatic
    //      forfeiture job runs. Auto-discarding an employee's earned time
    //      off without an explicit, tenant-confirmed policy is exactly the
    //      kind of financial-consequence decision this phase must not
    //      invent; this is the one open decision from this feature (see
    //      the Phase E report).
    //    - Consumption: FIFO by earned date, whole credits only (no
    //      splitting a single credit across two leave requests) — any
    //      requested days beyond what whole available credits can cover
    //      become LWP, mirroring the exact paid/LWP overflow behaviour
    //      every other leave type already has.
    // ====================================================================

    private const COMP_OFF_TYPE_CODE = 'COMP_OFF';
    /** Technical default: 1 worked rest day earns 1 Comp-Off day. Not a negotiated policy. */
    private const COMP_OFF_DEFAULT_CREDIT_DAYS = 1.0;

    private function _comp_off_ledger_doc_id(string $staffId, string $earnedDate): string
    {
        return $this->fs->docId("{$staffId}_{$earnedDate}");
    }

    /**
     * All 'available' credits for a staff member, oldest earnedDate first
     * (FIFO consumption order).
     */
    private function _comp_off_available_entries(string $staffId): array
    {
        try {
            $rows = $this->fs->schoolWhere('compOffLedger', [
                ['staffId', '==', $staffId],
                ['status',  '==', 'available'],
            ]);
        } catch (\Throwable $e) {
            log_message('error', "HR _comp_off_available_entries query failed for {$staffId}: " . $e->getMessage());
            return [];
        }
        $today = date('Y-m-d');

        $entries = [];
        foreach ((is_array($rows) ? $rows : []) as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : null);
            if (!is_array($d)) continue;

            // A lapsed credit is not spendable. `expiresOn` is only ever set
            // when the tenant has configured an expiry window, so a credit
            // earned while the policy was off carries null and never lapses —
            // an entitlement already granted is not withdrawn retroactively by
            // switching the policy on.
            $expiresOn = (string) ($d['expiresOn'] ?? '');
            if ($expiresOn !== '' && $expiresOn < $today) continue;

            $entries[] = $d;
        }
        usort($entries, fn($a, $b) => strcmp((string) ($a['earnedDate'] ?? ''), (string) ($b['earnedDate'] ?? '')));
        return $entries;
    }

    /**
     * Per-tenant Comp-Off policy, read from `schools.attendancePolicy`.
     *
     * Both settings default to DISABLED, so a tenant that has configured
     * nothing behaves exactly as before: credits never lapse and no ceiling is
     * enforced. Neither is inferred from a market norm — expiring an earned
     * entitlement or refusing to grant one is the school's decision, not a
     * default this code may pick.
     *
     *   compOffExpiryDays      int   days a credit stays spendable. 0/absent = never.
     *                                Market practice sits between 30 and 90 days.
     *   compOffMaxBalanceDays  float ceiling on a staff member's live balance.
     *                                0/absent = uncapped. Market practice is 5-10 days.
     *
     * @return array{expiryDays: int, maxBalanceDays: float}
     */
    private function _comp_off_policy(): array
    {
        $pol = $this->_attendance_policy();
        $pol = is_array($pol) ? $pol : [];

        $days = (int) ($pol['compOffExpiryDays'] ?? 0);
        $cap  = (float) ($pol['compOffMaxBalanceDays'] ?? 0);

        return [
            'expiryDays'     => $days > 0 ? $days : 0,
            'maxBalanceDays' => $cap  > 0 ? $cap  : 0.0,
        ];
    }

    /** Sum of a staff member's available Comp-Off credit, in days. */
    private function _comp_off_available_balance(string $staffId): float
    {
        $sum = 0.0;
        foreach ($this->_comp_off_available_entries($staffId) as $e) {
            // D-001 — a credit may be PARTLY spent, so the balance is the sum
            // of what is left, not of what was originally granted. Falls back
            // to creditDays for credits written before partial consumption
            // existed, which by definition have their full value.
            $sum += (float) ($e['remainingDays'] ?? $e['creditDays'] ?? 0);
        }
        return round($sum, 2);
    }

    /**
     * Consume up to $daysNeeded of Comp-Off credit, FIFO, whole credits
     * only. Each credit is individually CAS-protected (its own doc,
     * independent precondition) so consuming several credits at once
     * cannot race with a concurrent earn/consume/release on a DIFFERENT
     * credit — only a genuine double-consumption of the SAME credit is
     * ever contended, and that fails closed (skipped, not double-spent).
     *
     * @return array{paid: float, lwp: float, consumedIds: string[]}
     *         'paid' = days actually covered by consumed credits;
     *         'lwp'  = the shortfall (requested minus covered), matching
     *         every other leave type's paid/LWP overflow behaviour.
     */
    private function _consumeCompOffLedger(string $staffId, float $daysNeeded): array
    {
        $covered = 0.0;
        $consumedIds = [];
        $client = $this->fs->raw_client();

        $takes = [];

        foreach ($this->_comp_off_available_entries($staffId) as $entry) {
            $need = round($daysNeeded - $covered, 2);
            if ($need <= 0) break;

            $docId = (string) ($entry['_docId'] ?? $entry['id'] ?? '');
            if ($docId === '') continue;
            $updateTime = (string) ($entry['__updateTime'] ?? '');

            // D-001 — PARTIAL CONSUMPTION.
            //
            // This loop used to mark the whole credit 'consumed' and add its
            // full creditDays to $covered. A half-day comp-off leave therefore
            // spent a whole 1.0 credit and SILENTLY FORFEITED the other half —
            // an entitlement the employee had already earned by working a rest
            // day. Take only what the request needs and leave the remainder
            // available.
            //
            // `remainingDays` defaults to `creditDays` so credits written
            // before this change (and any hand-seeded row) behave correctly
            // without a backfill.
            $remaining = round((float) ($entry['remainingDays'] ?? $entry['creditDays'] ?? 0), 2);
            if ($remaining <= 0) continue;

            $take       = min($remaining, $need);
            $leftOver   = round($remaining - $take, 2);
            $nowIso     = date('c');

            $data = $leftOver > 0
                ? [
                    // Still has value — stays available for the next request.
                    'status'        => 'available',
                    'remainingDays' => $leftOver,
                    'updatedAt'     => $nowIso,
                ]
                : [
                    'status'        => 'consumed',
                    'remainingDays' => 0.0,
                    'consumedAt'    => $nowIso,
                    'updatedAt'     => $nowIso,
                ];

            $ok = false;
            if ($updateTime !== '' && $client !== null && method_exists($client, 'commitBatch')) {
                $ok = $client->commitBatch([[
                    'op' => 'set', 'collection' => 'compOffLedger', 'docId' => $docId,
                    'data' => $data, 'merge' => true,
                    'precondition' => ['updateTime' => $updateTime],
                ]]);
            } else {
                $ok = (bool) $this->fs->set('compOffLedger', $docId, $data, true);
            }
            if (!$ok) continue; // lost a race for this credit — try the next one, never double-spend

            $covered      = round($covered + $take, 2);
            $consumedIds[] = $docId;
            $takes[$docId] = $take;   // exact amount taken, so a release restores exactly this much
        }

        $paid = min($covered, $daysNeeded);
        $lwp  = max(0.0, round($daysNeeded - $paid, 2));
        return ['paid' => $paid, 'lwp' => $lwp, 'consumedIds' => $consumedIds, 'takes' => $takes];
    }

    /** Reverse a consumption — used on leave cancellation/rejection rollback. */
    /**
     * Return credits to the ledger after a failed or cancelled consumption.
     *
     * D-001 — with partial consumption a credit may have been only PARTLY
     * spent, so a blanket reset to `available` would silently CREDIT the
     * employee with days they never had. `$takes` carries the exact amount
     * taken from each credit; when it is supplied the remainder is restored by
     * addition.
     *
     * `$takes` is optional so requests approved before this change — which
     * recorded only an id list — still release correctly: with no recorded
     * amount the credit was necessarily consumed whole, and resetting it to
     * `available` with a full `remainingDays` is exactly right.
     */
    private function _releaseCompOffLedger(array $ids, array $takes = []): void
    {
        foreach ($ids as $docId) {
            $docId = (string) $docId;
            if ($docId === '') continue;

            $restore = null;
            if (array_key_exists($docId, $takes)) {
                $restore = round((float) $takes[$docId], 2);
                if ($restore <= 0) continue;   // nothing was taken; nothing to give back
            }

            try {
                if ($restore !== null) {
                    // Partial: add back exactly what was taken.
                    $current = 0.0;
                    try {
                        $doc = $this->fs->get('compOffLedger', $docId);
                        $current = round((float) ($doc['remainingDays']
                            ?? $doc['creditDays'] ?? 0), 2);
                    } catch (\Throwable $e) {
                        log_message('error', "HR _releaseCompOffLedger read failed for {$docId}: " . $e->getMessage());
                    }

                    $this->fs->update('compOffLedger', $docId, [
                        'status'        => 'available',
                        'remainingDays' => round($current + $restore, 2),
                        'consumedAt'    => null,
                        'consumedBy'    => null,
                        'updatedAt'     => date('c'),
                    ]);
                    continue;
                }

                $this->fs->update('compOffLedger', $docId, [
                    'status'     => 'available',
                    'consumedAt' => null,
                    'consumedBy' => null,
                    'updatedAt'  => date('c'),
                ]);
            } catch (\Throwable $e) {
                log_message('error', "HR _releaseCompOffLedger failed for {$docId}: " . $e->getMessage());
            }
        }
    }

    /**
     * POST — HR/manager marks one already-worked rest day as Comp-Off
     * eligible, crediting the staff member's ledger.
     *
     * Deliberately requires the date to already carry the 'W' (extraWorked)
     * attendance mark — the EXISTING Attendance_policy engine's own
     * "worked a scheduled rest day" signal (Gate 6b). This endpoint never
     * makes that judgement itself; it only converts an already-established
     * fact into a Comp-Off credit. Idempotent — a second call for the same
     * staff+date returns the existing credit rather than creating a
     * duplicate (deterministic doc id).
     */
    public function earn_comp_off()
    {
        $this->_require_role(self::HR_ROLES, 'earn_comp_off', 'HR', 'edit');

        $staffId = $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id');
        $date    = trim($this->input->post('date') ?? '');
        if ($staffId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->json_error('staff_id and a valid date (YYYY-MM-DD) are required.');
        }
        if ($date > date('Y-m-d')) {
            $this->json_error('Cannot credit Comp-Off for a future date.');
        }

        $staffProfile = $this->_fsGetStaffProfile($staffId);
        if (!is_array($staffProfile)) {
            $this->json_error('Staff member not found.');
        }
        $rawStatus = (string) ($staffProfile['status'] ?? $staffProfile['Status'] ?? 'Active');
        if (strtolower(trim($rawStatus)) !== 'active') {
            $this->json_error('Cannot perform this action for an inactive staff member.');
        }

        // The one hard gate: the date must already carry the 'W' mark this
        // engine already produces for "worked a scheduled rest day" —
        // reusing the existing fact, not inventing a new eligibility check.
        $attDoc = null;
        try {
            $attDoc = $this->fs->get('staffAttendance', "{$this->school_id}_{$date}_{$staffId}");
        } catch (\Throwable $e) {
            log_message('error', "HR earn_comp_off attendance read failed for {$staffId} {$date}: " . $e->getMessage());
        }
        $mark = is_array($attDoc) ? strtoupper((string) ($attDoc['status'] ?? '')) : '';
        if ($mark !== 'W') {
            $this->json_error("This date is not marked as worked-on-a-rest-day ('W') for this staff member — nothing to credit.");
        }

        $docId = $this->_comp_off_ledger_doc_id($staffId, $date);
        $existing = null;
        try { $existing = $this->fs->get('compOffLedger', $docId); } catch (\Throwable $e) {}
        if (is_array($existing) && !empty($existing)) {
            $this->json_success(['message' => 'Comp-Off was already credited for this date.', 'entry' => $existing]);
            return;
        }

        // Per-tenant policy. Both settings are disabled unless the school has
        // configured them, so this block is inert for every tenant that has not.
        $compOffPolicy = $this->_comp_off_policy();

        // ACCUMULATION CAP — refuse rather than silently drop the credit, so
        // the person is told why and can take leave to make room. Checked here
        // and not at consumption time: a credit that was legitimately granted
        // is never confiscated later by a policy change.
        if ($compOffPolicy['maxBalanceDays'] > 0) {
            $liveBalance = $this->_comp_off_available_balance($staffId);
            if ($liveBalance + (float) self::COMP_OFF_DEFAULT_CREDIT_DAYS > $compOffPolicy['maxBalanceDays']) {
                $this->json_error(
                    'Comp-Off balance cap reached ('
                    . rtrim(rtrim(number_format($compOffPolicy['maxBalanceDays'], 2, '.', ''), '0'), '.')
                    . ' days). Use existing Comp-Off before earning more.'
                );
                return;
            }
        }

        // EXPIRY — stamped at earn time from the policy in force on that day.
        // Left null when no window is configured, and a credit keeps whatever
        // stamp it was born with: switching the policy on later does not
        // retroactively lapse entitlements already granted.
        $expiresOn = null;
        if ($compOffPolicy['expiryDays'] > 0) {
            $expiresOn = date('Y-m-d', strtotime($date . ' +' . $compOffPolicy['expiryDays'] . ' days'));
        }

        $entry = [
            'schoolId'    => $this->school_id,
            'session'     => $this->session_year,
            'staffId'     => $staffId,
            'earnedDate'  => $date,
            'creditDays'  => self::COMP_OFF_DEFAULT_CREDIT_DAYS,
            // D-001 — `creditDays` is what was GRANTED and never changes;
            // `remainingDays` is what is left to spend and moves with partial
            // consumption. Written explicitly here so a new credit does not
            // depend on the reader-side fallback.
            'remainingDays' => (float) self::COMP_OFF_DEFAULT_CREDIT_DAYS,
            'status'      => 'available',
            'earnedFrom'  => 'attendance_extra_worked',
            'approvedBy'  => (string) ($this->admin_id ?? ''),
            'approvedAt'  => date('c'),
            'consumedBy'  => null,
            'consumedAt'  => null,
            // Null unless the tenant configured `compOffExpiryDays`. A credit
            // keeps the stamp it was born with, so enabling expiry later never
            // lapses an entitlement already earned.
            'expiresOn'   => $expiresOn,
            'createdAt'   => date('c'),
            'updatedAt'   => date('c'),
        ];
        $ok = false;
        try { $ok = (bool) $this->fs->set('compOffLedger', $docId, $entry, true); }
        catch (\Throwable $e) { log_message('error', "HR earn_comp_off write failed for {$staffId} {$date}: " . $e->getMessage()); }
        if (!$ok) {
            $this->json_error('Could not credit Comp-Off. Please try again.');
        }

        $this->_log_leave_audit([
            'action'     => 'comp_off_earned',
            'staff_id'   => $staffId,
            'staff_name' => $staffProfile['Name'] ?? $staffProfile['name'] ?? $staffId,
            'earned_date' => $date,
            'credit_days' => self::COMP_OFF_DEFAULT_CREDIT_DAYS,
        ]);

        $this->json_success(['message' => "Comp-Off credited for {$date}.", 'entry' => $entry]);
    }

    /**
     * GET — a staff member's Comp-Off ledger (all entries + available
     * balance). Self-service (own ledger) or HR:view for any staff member.
     */
    public function get_comp_off_ledger()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_comp_off_ledger', 'HR', 'view');

        $staffId = $this->safe_path_segment(trim($this->input->get('staff_id') ?? ''), 'staff_id');
        if (!(function_exists('has_permission') && has_permission('HR', 'view'))) {
            $ownId = (string) ($this->admin_id ?? '');
            if ($ownId === '') $this->json_error('Not permitted.');
            $staffId = $ownId;
        }
        if ($staffId === '') $this->json_error('staff_id is required.');

        try {
            $rows = $this->fs->schoolWhere('compOffLedger', [['staffId', '==', $staffId]]);
        } catch (\Throwable $e) {
            log_message('error', "HR get_comp_off_ledger query failed for {$staffId}: " . $e->getMessage());
            $this->json_error('Could not load the Comp-Off ledger.');
            return;
        }
        $entries = [];
        foreach ((is_array($rows) ? $rows : []) as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : null);
            if (is_array($d)) $entries[] = $d;
        }
        usort($entries, fn($a, $b) => strcmp((string) ($b['earnedDate'] ?? ''), (string) ($a['earnedDate'] ?? '')));

        $this->json_success([
            'entries'          => $entries,
            'available_balance' => $this->_comp_off_available_balance($staffId),
        ]);
    }

    /**
     * Log a payroll warning to Firebase for admin visibility.
     * Path: System/Logs/Payroll_Warnings/{push_id}
     */
    private function _log_payroll_warning(string $type, string $staffId, string $message): void
    {
        try {
            $warnId = 'PWARN_' . uniqid('', true);
            $this->firebase->firestoreSet('systemLogs', $warnId, [
                'logType'    => 'payroll_warning',
                'type'       => $type,
                'staff_id'   => $staffId,
                'school'     => $this->school_name,
                'schoolId'   => $this->school_id,
                'message'    => $message,
                'admin'      => $this->admin_id ?? '',
                'timestamp'  => date('c'),
            ], true);
        } catch (\Exception $e) {
            log_message('error', "HR _log_payroll_warning Firestore failed: " . $e->getMessage());
        }
    }

    /**
     * Validate a salary structure array — returns sanitised copy or null on failure.
     * Central validator for all payroll paths.
     */
    private function _validate_structure(array $sal): ?array
    {
        $fields = ['basic','hra','da','ta','medical','other_allowances',
                    'pf_employee','pf_employer','esi_employee','esi_employer',
                    'professional_tax','tds','other_deductions'];
        $clean = [];
        foreach ($fields as $f) {
            $v = $sal[$f] ?? 0;
            if (!is_numeric($v) || !is_finite((float) $v)) return null; // corrupt
            $clean[$f] = round(max((float) $v, 0), 2); // no negatives
        }
        if ($clean['basic'] <= 0) return null; // zero/negative basic = invalid
        // Copy metadata fields
        foreach (['source','created_at','updated_at','updated_by','_version','last_synced_at'] as $m) {
            if (isset($sal[$m])) $clean[$m] = $sal[$m];
        }
        return $clean;
    }

    /**
     * Auto-create a salary structure for a staff member using their registration data.
     * Used as safety fallback during payroll generation.
     */
    private function _auto_create_from_profile(string $staffId, array $profile): ?array
    {
        $salDet = $profile['salaryDetails'] ?? [];
        $basic  = (float) ($salDet['basicSalary'] ?? 0);
        $allow  = (float) ($salDet['Allowances'] ?? 0);
        if ($basic <= 0) return null;

        // Load per-school config from Firestore
        $cfg = [];
        try {
            $schoolDoc = $this->fs->get('schools', $this->fs->schoolId());
            if (is_array($schoolDoc) && is_array($schoolDoc['salaryDefaults'] ?? null)) {
                $cfg = $schoolDoc['salaryDefaults'];
            }
        } catch (\Exception $e) {
            log_message('error', "HR _auto_create_from_profile: Firestore SalaryDefaults read failed: " . $e->getMessage());
        }
        $get = function ($k, $fb) use ($cfg) { return isset($cfg[$k]) && is_numeric($cfg[$k]) ? (float) $cfg[$k] : $fb; };

        $hraPct = $get('hra_pct_of_basic', self::DEFAULT_HRA_PCT_OF_BASIC);
        $daPct  = $get('da_pct_of_basic', self::DEFAULT_DA_PCT_OF_BASIC);
        $hra = round($basic * ($hraPct / 100), 2);
        $da  = round($basic * ($daPct / 100), 2);
        $rem = max(0, $allow - $hra - $da);
        if ($allow < ($hra + $da)) {
            $hra = round($allow * 0.6, 2); $da = round($allow * 0.3, 2);
            $rem = max(0, $allow - $hra - $da);
        }
        $ta = round($rem * $get('ta_share', 0.30), 2);
        $med = round($rem * $get('medical_share', 0.25), 2);

        $now = date('c');
        $struct = [
            'basic' => $basic, 'hra' => $hra, 'da' => $da, 'ta' => $ta,
            'medical' => $med, 'other_allowances' => round($rem - $ta - $med, 2),
            'pf_employee' => $get('pf_employee', 12), 'pf_employer' => $get('pf_employer', 12),
            'esi_employee' => $get('esi_employee', 0.75), 'esi_employer' => $get('esi_employer', 3.25),
            'professional_tax' => $get('professional_tax', 200), 'tds' => $get('tds', 0),
            'other_deductions' => $get('other_deductions', 0),
            'source' => 'auto_payroll', 'created_at' => $now, 'updated_at' => $now,
            'updated_by' => 'system', '_version' => 1,
        ];

        $this->_fsSyncSalary($staffId, $struct);
        $this->_log_payroll_warning('auto_created', $staffId,
            "Auto-created salary structure during payroll (basic={$basic}, allowances={$allow})");

        return $struct;
    }

    // ====================================================================
    //  ID GENERATOR
    // ====================================================================

    /**
     * Generate next sequential ID.
     *
     * @param string $prefix  e.g. 'DEPT', 'JOB', 'APP', 'LT', 'LR', 'PR', 'APR'
     * @param string $type    Counter key in Firebase
     * @param int    $pad     Pad length for numeric portion (default 4)
     * @return string         e.g. 'DEPT0001'
     */
    private function _next_id(string $prefix, string $type, int $pad = 4): string
    {
        // MEDIUM (#10): the previous read-modify-write on schools/{profile}
        // hrCounters was non-atomic — two concurrent applies could read the
        // same value and mint DUPLICATE LR/LT ids. Switch to the atomic
        // per-school claim-doc counter (Firestore_service::nextSchoolCounter),
        // which uses createDocument()'s 409-safe compare-and-set and RETURNS
        // the claimed integer, so concurrent callers can never collide. The
        // counter is seeded ONCE from the legacy hrCounters value (missing-
        // pointer callback) so we never re-issue an already-used number.
        // W1.3: the implementation moved verbatim to helpers/hr_id_helper.php so
        // Ats.php can mint APP ids from the SAME atomic counter. Previously ATS
        // kept a separate non-atomic RTDB counter, so once both modules wrote
        // hrApplicants they could issue the same id and overwrite each other's
        // candidates. Behaviour here is unchanged for all nine call sites.
        return hr_next_id($this->fs, $prefix, $type, $pad);
    }

    // ====================================================================
    //  PAYROLL AUDIT LOG
    // ====================================================================

    /**
     * Write a payroll audit log entry to System/Logs/Payroll/.
     *
     * @param string $action  e.g. 'generated', 'finalized', 'paid', 'deleted'
     * @param string $runId   Payroll run ID (e.g. PR0001)
     * @param array  $extra   Optional additional data to store
     */
    private function _log_payroll(string $action, string $runId, array $extra = []): void
    {
        $logData = array_merge([
            'school_id'      => $this->school_name,
            'admin_id'       => $this->admin_id ?? '',
            'admin_name'     => $this->admin_name ?? '',
            'action'         => $action,
            'payroll_run_id' => $runId,
            'timestamp'      => date('c'),
            'ip'             => $this->input->ip_address(),
        ], $extra);

        try {
            $logId = 'PLOG_' . uniqid('', true);
            $logData['schoolId'] = $this->school_id;
            $logData['logType']  = 'payroll';
            $this->firebase->firestoreSet('systemLogs', $logId, $logData, true);
        } catch (\Exception $e) {
            log_message('error', "HR _log_payroll Firestore failed: " . $e->getMessage());
        }
    }

    /**
     * Production hardening programme (2026-08-19) — PAYROLL AUDIT TRAIL.
     *
     * The consolidated reconciliation found that Hr.php called log_audit()
     * ZERO times, despite owning every money-mutating payroll operation.
     * `_log_payroll()` above writes to `systemLogs` — that is DIAGNOSTIC
     * telemetry (free-form, unindexed, not surfaced in any audit view), not
     * an audit trail. Both are kept: this method is additive and never
     * replaces a _log_payroll() call.
     *
     * NO NEW AUDIT SYSTEM IS INTRODUCED. This is a thin call-shape wrapper
     * over the ONE canonical mechanism — helpers/audit_helper.php's
     * log_audit(), writing the canonical `auditLogs` collection, already
     * loaded for every controller by MY_Controller::__construct (:295) and
     * already used throughout Staff.php. The wrapper exists only so that
     * nine call sites emit a consistent entityType/meta shape rather than
     * nine hand-rolled variants.
     *
     * The helper itself captures actor (userId/userName/userRole), school,
     * IP and timestamp. This adds: operation, the target run, the payroll
     * PERIOD, and a bounded previous→new state transition.
     *
     * Deliberately logs RUN-LEVEL aggregates only. Per-employee salary,
     * bank and PII values are never written to the audit trail — the
     * auditable fact is "who moved this run to this state, for which
     * period, at what total", not each individual's pay.
     *
     * @param string $action  canonical action key, e.g. 'payroll_finalized'
     * @param string $runId   the payroll run this concerns
     * @param string $desc    human-readable description
     * @param array  $meta    bounded context — period, from/to state, counts
     */
    private function _audit_payroll(string $action, string $runId, string $desc, array $meta = []): void
    {
        if (!function_exists('log_audit')) return;   // degrade silently, never block a payroll write
        try {
            log_audit('HR', $action, $runId, $desc, [
                'entityType' => 'payrollRun',
                'meta'       => $meta,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Hr::_audit_payroll failed for ' . $runId . ': ' . $e->getMessage());
        }
    }

    /**
     * Bounded period/state descriptor for a payroll run, for audit `meta`.
     * Reads only fields the run document already carries; never recomputes.
     */
    private function _audit_run_meta(?array $run, array $extra = []): array
    {
        $m = [];
        if (is_array($run)) {
            if (isset($run['month']))      $m['month']       = (string) $run['month'];
            if (isset($run['year']))       $m['year']        = (string) $run['year'];
            if (isset($run['status']))     $m['fromStatus']  = (string) $run['status'];
            if (isset($run['total_net']))  $m['totalNet']    = $run['total_net'];
            if (isset($run['staff_count'])) $m['staffCount'] = $run['staff_count'];
        }
        return array_merge($m, $extra);
    }

    // ====================================================================
    //  ACCOUNTING INTEGRATION HELPERS
    //  Matches Accounting.php path structure exactly:
    //    Accounts:  Schools/{school}/Accounts/ChartOfAccounts/{code}
    //    Ledger:    Schools/{school}/{year}/Accounts/Ledger/{entryId}
    //    Index:     Schools/{school}/{year}/Accounts/Ledger_index/by_date|by_account
    //    Balances:  Schools/{school}/{year}/Accounts/Closing_balances/{code}
    //    Counter:   Schools/{school}/{year}/Accounts/Voucher_counters/{type}
    // ====================================================================

    /**
     * Validate that accounting accounts exist and are active.
     * Sends json_error and exits if any are missing.
     */
    /**
     * Canonical account definitions required by payroll.
     * Used by validation, preflight UI, and auto-create.
     */
    private const PAYROLL_ACCOUNTS = [
        '5010' => ['name' => 'Teaching Staff Salary',         'category' => 'Expense',   'sub' => 'Staff Expenses'],
        '5020' => ['name' => 'Non-Teaching Staff Salary',     'category' => 'Expense',   'sub' => 'Staff Expenses'],
        '2020' => ['name' => 'Salary Payable',                'category' => 'Liability', 'sub' => 'Current Liabilities'],
        '2030' => ['name' => 'PF Payable',                    'category' => 'Liability', 'sub' => 'Statutory Liabilities'],
        '2031' => ['name' => 'ESI Payable',                   'category' => 'Liability', 'sub' => 'Statutory Liabilities'],
        '2032' => ['name' => 'TDS Payable',                   'category' => 'Liability', 'sub' => 'Statutory Liabilities'],
        '2033' => ['name' => 'Professional Tax Payable',      'category' => 'Liability', 'sub' => 'Statutory Liabilities'],
        '2034' => ['name' => 'Other Deductions Payable',      'category' => 'Liability', 'sub' => 'Statutory Liabilities'],
        '1010' => ['name' => 'Cash in Hand',                  'category' => 'Asset',     'sub' => 'Current Assets'],
        '1020' => ['name' => 'Bank Account',                  'category' => 'Asset',     'sub' => 'Current Assets'],
    ];

    /**
     * Validate that required accounting accounts exist and are active.
     * Hard-blocks with json_error if any are missing.
     *
     * @param  array  $codes     Account codes to check
     * @param  bool   $structured If true, returns structured error for preflight UI
     */
    private function _validate_accounts(array $codes, bool $structured = false): void
    {
        $coa = [];
        try {
            // COA-D1: the chart lives in its OWN collection. Accounting.php owns it
            // (reads :158, writes :202, deletes :209) and records at :1063 that the
            // old `accounting` mirror became a no-op duplicate after the migration.
            // Reading the pre-migration shape here meant payroll saw an EMPTY chart
            // and refused to run — 65 accounts in chartOfAccounts, 0 in accounting.
            //
            // Canonical first, legacy second, so a tenant still on the old shape is
            // not stranded and nothing needs migrating for this to work.
            $fsDocs = $this->fs->schoolWhere('chartOfAccounts', []);
            if (!is_array($fsDocs) || $fsDocs === []) {
                $fsDocs = $this->fs->schoolWhere('accounting', [['type', '==', 'chartOfAccounts']]);
            }
            if (is_array($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $code = $d['code'] ?? '';
                    if ($code !== '') $coa[$code] = $d;
                }
            }
        } catch (\Exception $e) {
            log_message('error', "HR _validate_accounts Firestore failed: " . $e->getMessage());
        }

        $missing = [];
        foreach ($codes as $code) {
            $acct = $coa[$code] ?? null;
            if (!is_array($acct) || ($acct['status'] ?? '') !== 'active') {
                $def = self::PAYROLL_ACCOUNTS[$code] ?? ['name' => "Account {$code}", 'category' => 'Unknown'];
                $missing[] = [
                    'code'     => $code,
                    'name'     => $def['name'],
                    'category' => $def['category'],
                ];
            }
        }
        if (!empty($missing)) {
            if ($structured) {
                $this->json_error_data(
                    'Missing required accounting accounts. Please set them up before generating payroll.',
                    ['missing_accounts' => $missing, 'error_type' => 'missing_accounts']
                );
            }
            $this->json_error(
                'Missing or inactive accounting accounts: '
                . implode(', ', array_column($missing, 'code'))
                . '. Please set them up in Accounting first.'
            );
        }
    }

    /**
     * LC-P2b — engine-path pre-flight validation. Verifies every accrual
     * posting account exists + is active AND the accounting period is open,
     * BEFORE any payroll run/slips are written. Hard-blocks (json_error) on
     * failure so no orphan operational record is created. Invoked ONLY on
     * the engine path (feature flag ON); the legacy path never calls this,
     * so flag-OFF behaviour is unchanged.
     *
     * The account set mirrors postRunAccrual()'s conditional lines exactly,
     * so validation matches precisely what will be posted (no over-blocking
     * on statutory accounts that carry no amount this run).
     */
    private function _preflight_engine_accrual(
        float $teaching, float $nonTeaching,
        float $pf, float $esi, float $tds, float $profTax, float $otherDed
    ): void {
        // 1. Mandatory posting accounts — same conditional set as the journal.
        $accounts = ['2020'];                       // net salary payable — always
        if ($teaching    > 0) $accounts[] = '5010';
        if ($nonTeaching > 0) $accounts[] = '5020';
        if ($pf       > 0) $accounts[] = '2030';
        if ($esi      > 0) $accounts[] = '2031';
        if ($tds      > 0) $accounts[] = '2032';
        if ($profTax  > 0) $accounts[] = '2033';
        if ($otherDed > 0) $accounts[] = '2034';
        $this->_validate_accounts($accounts);       // json_error if any missing/inactive

        // 2. Accounting period must be OPEN for the accrual journal date.
        $this->_assert_period_open('generate payroll');
    }

    /**
     * Engine-path guard: assert the accounting period is OPEN for today (the
     * journal date used by postRunAccrual/postRunPayment). Uses the canonical
     * Accounting_period_lock — the identical check the engine applies at post
     * time — so a closed period is caught BEFORE any operational write.
     * Hard-blocks (json_error) if closed.
     */
    private function _assert_period_open(string $context = 'post payroll'): void
    {
        $this->load->library('Accounting_cache', null, 'acctCache');
        $this->load->library('Accounting_period_lock', null, 'acctLock');
        $this->acctCache->init($this->school_id, $this->session_year);
        $this->acctLock->init($this->firebase, $this->acctCache, $this->school_id, $this->session_year);
        $lock = $this->acctLock->forceValidate(date('Y-m-d'));
        if (!empty($lock['locked'])) {
            $this->json_error(
                "Cannot {$context}: the accounting period is closed up to "
                . ($lock['lockedUntil'] ?? '')
                . '. Reopen the period in Accounting or post to an open period.'
            );
        }
    }

    /**
     * Non-halting sibling of _assert_period_open(): returns whether the
     * accounting period is OPEN for $date (today by default), WITHOUT emitting
     * json_error. Used by regenerate_staff_payroll's engine path so a closed
     * period DEFERS the adjustment journal (non-fatal, matching legacy) instead
     * of aborting the regenerate.
     */
    private function _period_is_open(string $date = ''): bool
    {
        if ($date === '') $date = date('Y-m-d');
        $this->load->library('Accounting_cache', null, 'acctCache');
        $this->load->library('Accounting_period_lock', null, 'acctLock');
        $this->acctCache->init($this->school_id, $this->session_year);
        $this->acctLock->init($this->firebase, $this->acctCache, $this->school_id, $this->session_year);
        $lock = $this->acctLock->forceValidate($date);
        return empty($lock['locked']);
    }

    /**
     * LC-P3b — engine-path payment application with a CAS-FIRST, contention-
     * only retry. The run write is the serialization point: winning the
     * compare-and-swap atomically CLAIMS the payment seq, and ONLY the winner
     * posts the (idempotent, deterministic JE_HR_PAYROLL_<runId>_<seq>)
     * journal. Two simultaneous payments therefore obtain DISTINCT seqs and
     * DISTINCT journals — duplicate payment state and ledger/run mismatches
     * are impossible, and partial-payment sequencing stays correct.
     *
     * Cost profile:
     *   • Normal (uncontended): reuses the run doc already loaded by the
     *     caller ($run) — NO extra read; one CAS write; then one journal post.
     *   • Contention only: each ACTUAL CAS conflict triggers one bounded
     *     re-read + recompute. Capped at MAX_ATTEMPTS.
     *
     * A pre-flight (accounts + period) runs ONCE before the loop so the
     * journal post AFTER the CAS win cannot fail for those reasons — no
     * run-marked-paid-without-journal.
     *
     * @return array {journal_id, payment_seq, pay_amount, new_total_paid,
     *                is_full, status, paid_at, attempts}
     */
    private function _engine_apply_payment(
        string $runId, array $run, float $totalNet,
        string $month, string $year, string $paymentMode,
        string $paymentDate, string $paymentReference, string $payAccount,
        $explicitPayAmount, $payAcct
    ): array {
        // Pre-flight ONCE — accounts + period. Guarantees the post AFTER the
        // CAS write cannot fail for account/period reasons.
        $this->_validate_accounts(['2020', $payAccount]);
        $this->_assert_period_open('record payroll payment');

        $MAX_ATTEMPTS = 5;
        $currentRun   = $run;   // reuse the caller's read; retries re-read

        for ($attempt = 1; $attempt <= $MAX_ATTEMPTS; $attempt++) {
            // Re-derive the payment from the freshest run snapshot so seq,
            // remaining, and amount are always correct for THIS attempt.
            $st = (string) ($currentRun['status'] ?? '');
            if ($st === 'Paid') {
                $this->json_error('This payroll run is already fully paid.');
            }
            if (!in_array($st, ['Finalized', 'Approved', 'Partially Paid'], true)) {
                $this->json_error('Only Finalized, Approved, or Partially Paid payroll runs can receive payments.');
            }

            $curPaid   = round((float) ($currentRun['total_paid'] ?? 0), 2);
            $remaining = round($totalNet - $curPaid, 2);
            if ($remaining <= 0.009) {
                $this->json_error('This payroll run has no remaining balance to pay.');
            }
            if ($explicitPayAmount !== null && $explicitPayAmount !== '') {
                $amt = round((float) $explicitPayAmount, 2);
                if ($amt <= 0) {
                    $this->json_error('Payment amount must be greater than zero.');
                }
                if ($amt > $remaining + 0.01) {
                    $this->json_error("Payment amount ({$amt}) exceeds remaining balance ({$remaining}).");
                }
                if ($amt > $remaining) $amt = $remaining;   // clamp rounding
            } else {
                $amt = $remaining;                          // full payment
            }

            $isFull  = (abs($amt - $remaining) < 0.01);
            $seq     = count($currentRun['payments'] ?? []) + 1;
            $newPaid = round($curPaid + $amt, 2);
            // R1 — use THE canonical journal-id algorithm (raw runId), which is
            // identical to the engine's entryId. No code path here normalizes
            // the runId differently, so the recorded journal_id always matches
            // the actual ledger entry.
            $journalId = PayrollAccountingService::journalIdFor($runId, $seq);

            $paidAt = date('c');
            $paymentRecord = [
                'seq'               => $seq,
                'amount'            => $amt,
                'payment_mode'      => $paymentMode,
                'payment_date'      => $paymentDate,
                'payment_reference' => $paymentReference,
                'journal_id'        => $journalId,
                'paid_by'           => $this->admin_name,
                'paid_at'           => $paidAt,
                'recon_status'      => 'pending',
                // LC-P3c transactional outbox — this record is the DURABLE
                // intent. It is committed (Phase 1) BEFORE the journal is
                // posted, and flipped to 'posted' (Phase 2) only after the
                // journal confirms. A record left at 'pending' is what the
                // PayrollReconciler sweep re-posts (idempotently). is_full is
                // stored so recovery reproduces the exact narration.
                'journal_status'    => 'pending',
                'is_full'           => $isFull,
            ];
            $newStatus = $isFull ? 'Paid' : 'Partially Paid';
            $runUpdate = [
                'status'      => $newStatus,
                'total_paid'  => $newPaid,
                'balance_due' => round($totalNet - $newPaid, 2),
                'journal_id'  => $journalId,
            ];
            if ($isFull) {
                $runUpdate['paid_at']           = $paidAt;
                $runUpdate['paid_by']           = $this->admin_name;
                $runUpdate['payment_mode']      = $paymentMode;
                $runUpdate['payment_date']      = $paymentDate;
                $runUpdate['payment_reference'] = $paymentReference;
            }

            $merged = array_merge($currentRun, $runUpdate);
            $pmts   = $currentRun['payments'] ?? [];
            $pmts[$seq] = $paymentRecord;
            $merged['payments'] = $pmts;

            // CAS write — conditioned on the snapshot's version token. No
            // extra read on the first attempt (token came from $run).
            $casToken = (string) ($currentRun['__updateTime'] ?? '');
            $ok = $this->_fsSyncPayrollRun($runId, $merged, $casToken);
            if ($ok) {
                // Won the CAS → seq is atomically ours, and the payment intent
                // is now DURABLE at journal_status='pending' (Phase 1). Post
                // the journal (Phase 2). Pre-flight guarantees no account/
                // period failure. Idempotent → yields exactly $journalId.
                $posted = (string) $payAcct->postRunPayment([
                    'run_id'            => $runId,
                    'month'             => $month,
                    'year'              => $year,
                    'amount'            => $amt,
                    'payment_mode'      => $paymentMode,
                    'payment_reference' => $paymentReference,
                    'payment_seq'       => $seq,
                    'is_full_payment'   => $isFull,
                ]);
                if ($posted !== '') {
                    // Journal confirmed → flip the intent to 'posted'. A single
                    // targeted nested-field update (updateMask
                    // payments.<seq>.journal_status) — no read, no clobber of
                    // sibling payments. If THIS write fails transiently, the
                    // record stays 'pending' and the reconciler re-posts (the
                    // engine dedups on the deterministic key) then re-flips —
                    // so a lost flip is self-healing, never a duplicate.
                    try {
                        $this->firebase->firestoreUpdate(
                            'salarySlips',
                            $this->fs->docId("RUN_{$runId}"),
                            [
                                "payments.{$seq}.journal_status" => 'posted',
                                'updatedAt'                       => date('c'),
                            ]
                        );
                    } catch (\Throwable $e) {
                        log_message('error',
                            "HR outbox flip failed run={$runId} seq={$seq} — reconciler will heal: "
                            . $e->getMessage());
                    }
                }
                return [
                    'journal_id'     => $posted !== '' ? $posted : $journalId,
                    'payment_seq'    => $seq,
                    'pay_amount'     => $amt,
                    'new_total_paid' => $newPaid,
                    'is_full'        => $isFull,
                    'status'         => $newStatus,
                    'paid_at'        => $paidAt,
                    'attempts'       => $attempt,
                ];
            }

            // CAS conflict → a concurrent payment landed. Re-read (bounded,
            // contention-only) and retry with a fresh seq/remaining.
            if ($attempt < $MAX_ATTEMPTS) {
                $fresh = $this->_fsGetRun($runId);
                if (is_array($fresh)) $currentRun = $fresh;
            }
        }

        $this->json_error('This payment could not be applied due to concurrent updates on the payroll run. Please refresh and retry.');
        return []; // unreachable (json_error exits) — keeps the type-checker happy
    }

    /**
     * Return JSON error with extra data fields (for structured UI).
     */
    private function json_error_data(string $message, array $data): void
    {
        header('Content-Type: application/json');
        http_response_code(200); // 200 so jQuery .then() fires, not .catch()
        echo json_encode(array_merge([
            'status'  => 'error',
            'message' => $message,
        ], $data));
        exit;
    }

    /**
     * POST — Lock a payroll month. Prevents regeneration or deletion after lock.
     * Params: month, year
     */
    public function lock_payroll_month()
    {
        $this->_require_role(self::ADMIN_ROLES, 'lock_payroll_month', 'HR', 'manage');

        $month = trim($this->input->post('month') ?? '');
        $year  = trim($this->input->post('year') ?? '');

        $validMonths = ['January','February','March','April','May','June',
                        'July','August','September','October','November','December'];
        if (!in_array($month, $validMonths, true) || !preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid month or year.');
        }

        // ── Firestore-first: check existing lock ──
        $lockKey = "{$year}_{$month}";
        $existing = null;
        try {
            $schoolDoc = $this->fs->get('schools', $this->fs->docId('profile'));
            if (is_array($schoolDoc) && isset($schoolDoc['payrollLocks'][$lockKey])) {
                $existing = $schoolDoc['payrollLocks'][$lockKey];
            }
        } catch (\Exception $e) {
            log_message('error', "HR lock_payroll_month FS read failed: " . $e->getMessage());
        }

        if (is_array($existing) && !empty($existing['locked'])) {
            $this->json_error("{$month} {$year} is already locked by " . ($existing['locked_by'] ?? 'unknown') . '.');
        }

        // ── Firestore-first: verify finalized run exists ──
        $runFound = false;
        try {
            $fsRuns = $this->fs->schoolWhere('salarySlips', [
                ['type', '==', 'run'],
                ['month', '==', $month],
                ['year', '==', $year],
            ]);
            foreach ($fsRuns as $doc) {
                $rd = is_array($doc['data'] ?? null) ? $doc['data'] : [];
                // G-1: only the MONTHLY run may satisfy the lock precondition.
                // Off-cycle runs (F&F, bonus, arrears) can be finalized and paid
                // for a period whose monthly payroll was never run at all — an
                // off-cycle run for one leaver would otherwise let an admin lock
                // the month and freeze out the payroll that still owes everyone
                // else. Absent field reads as monthly: runs written before
                // runType existed are monthly by definition.
                if ((string) ($rd['runType'] ?? self::RUN_TYPE_MONTHLY) !== self::RUN_TYPE_MONTHLY) continue;
                $st = $rd['status'] ?? '';
                if (in_array($st, ['Finalized', 'Paid', 'Partially Paid'], true)) {
                    $runFound = true;
                    break;
                }
            }
        } catch (\Exception $e) {
            log_message('error', "HR lock_payroll_month FS run check failed: " . $e->getMessage());
        }

        if (!$runFound) {
            $this->json_error("No finalized payroll run found for {$month} {$year}. Finalize first.");
        }

        $lockData = [
            'locked'    => true,
            'locked_by' => $this->admin_name,
            'locked_at' => date('c'),
            'month'     => $month,
            'year'      => $year,
        ];

        // 1. Firestore FIRST — store lock in schools doc
        try {
            $this->fs->update('schools', $this->fs->docId('profile'), [
                "payrollLocks.{$lockKey}" => $lockData,
            ]);
        } catch (\Exception $e) {
            log_message('error', "HR lock_payroll_month FS write failed: " . $e->getMessage());
        }

        $this->_log_payroll('month_locked', '', ['month' => $month, 'year' => $year]);
        // Period lock — entityId is the lock key rather than a run id,
        // because this closes a whole payroll PERIOD, not one run.
        $this->_audit_payroll('payroll_month_locked', $lockKey,
            "Payroll period locked for {$month} {$year}",
            ['month' => $month, 'year' => $year, 'toStatus' => 'locked']);

        $this->json_success(['message' => "{$month} {$year} payroll locked. No further changes allowed."]);
    }

    /**
     * POST — re-open a locked payroll month.
     *
     * The counterpart `lock_payroll_month()` never had. Without it the lock was
     * a one-way door, and `_check_payroll_lock()` told the operator to "Unlock
     * before making changes" — an instruction nothing could carry out.
     *
     * Params: month, year, reason (>= 10 chars)
     *
     * Deliberately does NOT reverse anything. Run statuses, slips and the
     * accounting books are untouched; this re-opens the PERIOD so the existing
     * run-level tools can be used. unlock_payroll() already states that books
     * are not reversed by an unlock, and inventing a reversal here would be
     * making up policy.
     */
    public function unlock_payroll_month()
    {
        $this->_require_role(self::ADMIN_ROLES, 'unlock_payroll_month', 'HR', 'manage');

        $month = trim($this->input->post('month') ?? '');
        $year  = trim($this->input->post('year') ?? '');

        $validMonths = ['January','February','March','April','May','June',
                        'July','August','September','October','November','December'];
        if (!in_array($month, $validMonths, true) || !preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid month or year.');
        }

        // Same bar unlock_payroll() sets for re-opening a finalised RUN.
        $reason = trim((string) $this->input->post('reason'));
        if (strlen($reason) < 10) {
            $this->json_error('Reason (at least 10 characters) is required to re-open a payroll period.');
        }
        $reason = mb_substr($reason, 0, 500);

        $lockKey = "{$year}_{$month}";

        // Must actually be locked — otherwise this silently "succeeds" and the
        // operator believes they changed something.
        $lock = null;
        try {
            $schoolDoc = $this->fs->get('schools', $this->fs->docId('profile'));
            if (is_array($schoolDoc) && isset($schoolDoc['payrollLocks'][$lockKey])) {
                $lock = $schoolDoc['payrollLocks'][$lockKey];
            }
        } catch (\Exception $e) {
            log_message('error', "HR unlock_payroll_month FS read failed: " . $e->getMessage());
            $this->json_error('Could not read the payroll lock. Please retry.');
        }

        if (!is_array($lock) || empty($lock['locked'])) {
            $this->json_error("{$month} {$year} payroll is not locked.");
        }

        // locked=false, never a delete: the record that the period WAS closed,
        // by whom and when, is the audit trail. Same shape as
        // Attendance::unlock_staff_attendance().
        $unlockData = array_merge(is_array($lock) ? $lock : [], [
            'locked'        => false,
            'unlocked_by'   => $this->admin_name,
            'unlocked_at'   => date('c'),
            'unlock_reason' => $reason,
        ]);

        try {
            $this->fs->update('schools', $this->fs->docId('profile'), [
                "payrollLocks.{$lockKey}" => $unlockData,
            ]);
        } catch (\Exception $e) {
            log_message('error', "HR unlock_payroll_month FS write failed: " . $e->getMessage());
            $this->json_error('Failed to re-open the payroll period. Please retry.');
        }

        $this->_log_payroll('month_unlocked', '', [
            'month' => $month, 'year' => $year, 'reason' => $reason,
        ]);
        $this->_audit_payroll('payroll_month_unlocked', $lockKey,
            "Payroll period re-opened for {$month} {$year}",
            ['month' => $month, 'year' => $year, 'reason' => $reason, 'toStatus' => 'unlocked']);

        $this->json_success(['message' => "{$month} {$year} payroll re-opened."]);
    }

    /**
     * POST — payroll lock state for the session's months.
     *
     * Read side of the period lock. Without it the panel could set a lock it
     * could not see, which is how the attendance lock went unnoticed for so
     * long (GAP-1). ONE document read: every lock lives in the `payrollLocks`
     * map on the school profile.
     */
    public function payroll_lock_status()
    {
        $this->_require_role(self::VIEW_ROLES, 'payroll_lock_status', 'HR', 'view');

        $locks = [];
        try {
            $schoolDoc = $this->fs->get('schools', $this->fs->docId('profile'));
            if (is_array($schoolDoc) && is_array($schoolDoc['payrollLocks'] ?? null)) {
                $locks = $schoolDoc['payrollLocks'];
            }
        } catch (\Exception $e) {
            log_message('error', "HR payroll_lock_status FS read failed: " . $e->getMessage());
            $this->json_error('Could not read payroll locks.');
        }

        $out = [];
        foreach ($locks as $key => $row) {
            if (!is_array($row)) continue;
            $parts = explode('_', (string) $key, 2);
            if (count($parts) !== 2) continue;

            $out[] = [
                'key'          => (string) $key,
                'year'         => $parts[0],
                'month'        => $parts[1],
                'locked'       => !empty($row['locked']),
                'lockedBy'     => (string) ($row['locked_by'] ?? ''),
                'lockedAt'     => (string) ($row['locked_at'] ?? ''),
                'unlockedBy'   => (string) ($row['unlocked_by'] ?? ''),
                'unlockedAt'   => (string) ($row['unlocked_at'] ?? ''),
                'unlockReason' => (string) ($row['unlock_reason'] ?? ''),
            ];
        }

        usort($out, static function ($a, $b) { return strcmp($b['key'], $a['key']); });

        $this->json_success([
            'locks'     => $out,
            'canManage' => function_exists('has_permission') ? has_permission('HR', 'manage') : false,
        ]);
    }
    /**
     * Check if a payroll month is locked. Used by generate_payroll and delete_payroll_run.
     */
    private function _check_payroll_lock(string $month, string $year): void
    {
        $lockKey = "{$year}_{$month}";

        // ── Firestore-first ──
        $lock = null;
        try {
            $schoolDoc = $this->fs->get('schools', $this->fs->docId('profile'));
            if (is_array($schoolDoc) && isset($schoolDoc['payrollLocks'][$lockKey])) {
                $lock = $schoolDoc['payrollLocks'][$lockKey];
            }
        } catch (\Exception $e) {
            log_message('error', "HR _check_payroll_lock FS failed: " . $e->getMessage());
        }

        if (is_array($lock) && !empty($lock['locked'])) {
            $this->json_error(
                "{$month} {$year} payroll is locked by " . ($lock['locked_by'] ?? 'admin')
                . " on " . ($lock['locked_at'] ?? 'unknown date') . '. Unlock before making changes.'
            );
        }
    }

    // ====================================================================
    //  PAGE ROUTE
    // ====================================================================

    /**
     * Single page entry point — loads view with active tab.
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'hr_view', 'HR', 'view');

        $tab = $this->uri->segment(2, 'dashboard');

        // The "Departments" tab was removed on 2026-08-23 — it had become a
        // placeholder pointing at Staff & Roles, which is where departments are
        // actually edited. An old bookmark REDIRECTS there rather than silently
        // landing on the dashboard, so the link still does what its owner meant.
        if ($tab === 'departments') {
            redirect('staff_access');
            return;
        }

        $allowed = ['dashboard', 'recruitment', 'leaves', 'payroll', 'appraisals', 'training', 'orgchart'];
        if (!in_array($tab, $allowed, true)) {
            $tab = 'dashboard';
        }

        $data['active_tab'] = $tab;

        // Phase 1 ZenX HRMS proof-of-concept: resolve one terminology label
        // through the capability-gated org_term() mechanism. A tenant
        // without HRMS_CORE_CAPABILITY gets the exact hardcoded default this
        // page always showed — zero visible change. This is deliberately
        // the ONLY heading wired this way in Phase 1; a full retrofit of
        // every HR/Staff/Attendance view is later-phase work.
        $data['staff_term'] = 'Staff';
        if (has_capability(HRMS_CORE_CAPABILITY)) {
            $this->load->helper('terminology');
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            if (is_array($schoolDoc)) {
                $data['staff_term'] = org_term('person', $schoolDoc);
            }
        }

        $this->load->view('include/header');
        $this->load->view('hr/index', $data);
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  DASHBOARD
    // ====================================================================

    /**
     * GET — Returns dashboard statistics.
     */
    public function get_dashboard()
    {
        $this->_require_role(self::VIEW_ROLES, 'hr_dashboard', 'HR', 'view');

        // Staff counts from Firestore — single read, bucketed into both
        // total and active so the dashboard can surface them as separate
        // KPI tiles. Active uses a case-insensitive compare so legacy docs
        // with `status: "active"` still count.
        $totalStaff  = 0;
        $activeStaff = 0;
        try {
            $fsStaff = $this->fs->schoolList('staff');
            if (is_array($fsStaff)) {
                foreach ($fsStaff as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? ($doc['id'] ?? '');
                    if ($sid === '' || $sid === 'Count') continue;
                    $totalStaff++;
                    // Default to Active when the field is missing — legacy
                    // staff docs predate the Active/Inactive feature.
                    $status = $d['status'] ?? 'Active';
                    if (strtolower($status) === 'active') $activeStaff++;
                }
            }
        } catch (\Exception $e) {}
        // staff_count alias kept for any older client code expecting it
        $staffCount = $activeStaff;

        // Department count from Firestore
        $deptCount = 0;
        try {
            $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
            if (is_array($fsSchool) && is_array($fsSchool['departments'] ?? null)) {
                $deptCount = count($fsSchool['departments']);
            }
        } catch (\Exception $e) {}

        // Open jobs & applicant counts from Firestore
        $jobs = [];
        $openJobs = 0;
        try {
            $fsJobs = $this->fs->schoolList('hrJobs');
            if (is_array($fsJobs)) {
                foreach ($fsJobs as $j) {
                    $d = $j['data'] ?? $j;
                    // Wrapper-bug fix: doc id lives on $j['id'], not in $d.
                    // Falling back to $d['id'] returned '' so every record
                    // stored under key '' and overwrote each other.
                    $jid = $j['id'] ?? ($d['id'] ?? '');
                    if ($jid === '') continue;
                    $jobs[$jid] = $d;
                    if (($d['status'] ?? '') === 'Open') {
                        $openJobs++;
                    }
                }
            }
        } catch (\Exception $e) {}

        $applicants = [];
        $totalApplicants = 0;
        try {
            $fsApps = $this->fs->schoolList('hrApplicants');
            if (is_array($fsApps)) {
                foreach ($fsApps as $a) {
                    $d = $a['data'] ?? $a;
                    $aid = $a['id'] ?? ($d['id'] ?? '');
                    if ($aid === '') continue;
                    $applicants[$aid] = $d;
                }
                $totalApplicants = count($fsApps);
            }
        } catch (\Exception $e) {}

        // Pending leave requests — Firestore-first via helper
        $leaveReqs = $this->_fsGetAllLeaveRequests();
        $pendingLeaves = 0;
        foreach ($leaveReqs as $lr) {
            // Case-insensitive — Teacher app writes "pending" (lowercase),
            // legacy admin docs use "Pending". Standardize via strtolower.
            if (isset($lr['status']) && strtolower($lr['status']) === 'pending') {
                $pendingLeaves++;
            }
        }

        // ── Firestore-first: payroll runs this session ──
        $runs = null;
        try {
            $fsDocs = $this->fs->schoolWhere('salarySlips', [['type', '==', 'run']]);
            if (!empty($fsDocs)) {
                $runs = [];
                foreach ($fsDocs as $doc) {
                    // Disaster-recovery wrapper bug fix: Firestore doc id lives on
                    // the WRAPPER (`$doc['id']`), not inside data. Reading $d['id']
                    // emitted "Undefined array key 'id'" warnings on the dashboard.
                    $docId = $doc['id'] ?? '';
                    if ($docId === '') continue;
                    $parts = explode('_RUN_', $docId, 2);
                    $rid = isset($parts[1]) ? $parts[1] : $docId;
                    $runs[$rid] = $doc['data'] ?? [];
                }
            }
        } catch (\Exception $e) {
            log_message('error', "HR get_dashboard FS runs failed: " . $e->getMessage());
        }
        if ($runs === null) $runs = [];

        $payrollRuns = is_array($runs) ? count($runs) : 0;
        $lastPayroll = null;
        if (is_array($runs)) {
            $latest = null;
            foreach ($runs as $rid => $r) {
                // G-2: "last payroll" on the dashboard means the last MONTHLY
                // run. An off-cycle settlement for a single leaver is newer by
                // created_at but is not the school's latest payroll, and showing
                // its one-person total as the headline figure understates the
                // month by the entire rest of the roster. The RUN COUNT above
                // deliberately still includes every run — that is a count of runs,
                // and off-cycle runs are runs.
                if ((string) ($r['runType'] ?? self::RUN_TYPE_MONTHLY) !== self::RUN_TYPE_MONTHLY) continue;
                if (!$latest || (isset($r['created_at']) && $r['created_at'] > ($latest['created_at'] ?? ''))) {
                    $latest = $r;
                    $latest['id'] = $rid;
                }
            }
            if ($latest) {
                $lastPayroll = [
                    'month'  => $latest['month'] ?? '',
                    'year'   => $latest['year'] ?? '',
                    'status' => $latest['status'] ?? '',
                ];
            }
        }

        // Recent leave requests (last 5)
        $recentLeaves = [];
        if (is_array($leaveReqs)) {
            uasort($leaveReqs, function ($a, $b) {
                return strcmp($b['applied_on'] ?? '', $a['applied_on'] ?? '');
            });
            $i = 0;
            foreach ($leaveReqs as $id => $lr) {
                if ($i >= 5) break;
                $recentLeaves[] = [
                    'id'         => $id,
                    'staff_name' => $lr['staff_name'] ?? '',
                    'type_name'  => $lr['type_name'] ?? '',
                    'status'     => $lr['status'] ?? '',
                    'from_date'  => $lr['from_date'] ?? '',
                    'to_date'    => $lr['to_date'] ?? '',
                ];
                $i++;
            }
        }

        // Appraisal count from Firestore
        $appraisalCount = 0;
        try {
            $fsAppr = $this->fs->schoolList('appraisals');
            $appraisalCount = is_array($fsAppr) ? count($fsAppr) : 0;
        } catch (\Exception $e) {}

        // ATS pipeline summary
        $pipelineCounts = ['Applied' => 0, 'Shortlisted' => 0, 'Interviewed' => 0, 'Selected' => 0, 'Hired' => 0];
        $rejectedCount  = 0;
        $recentApplicants = [];
        // W1.3: the local $stageMap that used to live here was a third private
        // copy of the same table (Ats.php held two more). All four now resolve
        // through the shared bridge in helpers/recruitment_helper.php.
        if (is_array($applicants)) {
            foreach ($applicants as $appId => $app) {
                if (!is_array($app)) continue;
                $rawStage = $app['stage'] ?? ($app['status'] ?? 'Applied');
                $stage    = recruitment_stage_from_status((string) $rawStage);
                $atsStatus = $app['ats_status'] ?? 'active';

                if ($atsStatus === 'rejected') {
                    $rejectedCount++;
                } elseif (isset($pipelineCounts[$stage])) {
                    $pipelineCounts[$stage]++;
                }

                $app['id'] = $appId;
                $app['_stage'] = $stage;
                $recentApplicants[] = $app;
            }
            usort($recentApplicants, fn($a, $b) => strcmp($b['updated_at'] ?? $b['applied_date'] ?? '', $a['updated_at'] ?? $a['applied_date'] ?? ''));
            $recentApplicants = array_slice($recentApplicants, 0, 8);
        }

        // Build job title lookup for recent applicants
        $jobTitles = [];
        if (is_array($jobs)) {
            foreach ($jobs as $jid => $j) {
                if (is_array($j)) $jobTitles[$jid] = $j['title'] ?? '';
            }
        }
        foreach ($recentApplicants as &$ra) {
            $ra['job_title'] = $jobTitles[$ra['job_id'] ?? ''] ?? '';
        }
        unset($ra);

        $this->json_success([
            'staff_count'        => $staffCount,
            'total_staff'        => $totalStaff,
            'active_staff'       => $activeStaff,
            'dept_count'         => $deptCount,
            'open_jobs'          => $openJobs,
            'total_applicants'   => $totalApplicants,
            'pending_leaves'     => $pendingLeaves,
            'payroll_runs'       => $payrollRuns,
            'last_payroll'       => $lastPayroll,
            'appraisal_count'    => $appraisalCount,
            'recent_leaves'      => $recentLeaves,
            'pipeline_counts'    => $pipelineCounts,
            'rejected_count'     => $rejectedCount,
            'recent_applicants'  => $recentApplicants,
        ]);
    }

    // ====================================================================
    //  DEPARTMENTS
    // ====================================================================

    /**
     * GET — List all departments.
     */
    public function get_departments()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_departments', 'HR', 'view');

        // 1. Firestore FIRST
        $depts = [];
        try {
            $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
            if (is_array($fsSchool) && is_array($fsSchool['departments'] ?? null)) {
                $raw = $fsSchool['departments'];
                foreach ($raw as $k => $v) {
                    $depts[$k] = is_array($v) ? $v : (array) $v;
                }
            }
        } catch (\Exception $e) {}

        // Compute real staff counts from Firestore staff profiles
        $profiles = [];
        try {
            $fsDocs = $this->fs->schoolWhere('staff', []);
            if (is_array($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? ($d['id'] ?? '');
                    if ($sid !== '') $profiles[$sid] = $d;
                }
            }
        } catch (\Exception $e) {}
        $countByDept = [];
        $staffByDept = [];
        if (is_array($profiles)) {
            foreach ($profiles as $sid => $p) {
                if (!is_array($p) || $sid === 'Count') continue;
                $dept = trim($p['Department'] ?? $p['department'] ?? '');
                if ($dept === '') $dept = 'Unassigned';
                $dk = dept_key($dept);   // key on normalized name so all case/space variants SUM
                if (!isset($countByDept[$dk])) {
                    $countByDept[$dk] = 0;
                    $staffByDept[$dk] = [];
                }
                $countByDept[$dk]++;
                $staffByDept[$dk][] = [
                    'id'       => $sid,
                    'name'     => $p['Name'] ?? $sid,
                    'position' => $p['Position'] ?? '',
                    'phone'    => $p['Phone Number'] ?? '',
                ];
            }
        }

        // Load active job openings per department from Firestore
        $allJobs = [];
        try {
            $fsJobs = $this->fs->schoolList('hrJobs');
            if (is_array($fsJobs)) {
                foreach ($fsJobs as $j) {
                    $d = $j['data'] ?? $j;
                    // Wrapper-bug fix: doc id is on the wrapper. The unwrapped
                    // data dict almost never carries `id`, so reading $d['id']
                    // returned '' → empty $allJobs → empty department vacancy panel.
                    $jid = $j['id'] ?? ($d['id'] ?? '');
                    if ($jid !== '') $allJobs[$jid] = $d;
                }
            }
        } catch (\Exception $e) {}
        $jobsByDept = [];
        if (is_array($allJobs)) {
            foreach ($allJobs as $jid => $j) {
                if (!is_array($j)) continue;
                $jDept = $j['department'] ?? '';
                $jStatus = $j['status'] ?? '';
                if ($jDept === '' || $jStatus === 'Closed') continue;
                $jk = dept_key($jDept);   // normalized so jobs join their department
                if (!isset($jobsByDept[$jk])) $jobsByDept[$jk] = [];
                $jobsByDept[$jk][] = [
                    'id'               => $jid,
                    'title'            => $j['title'] ?? '',
                    'total_positions'  => (int) ($j['total_positions'] ?? $j['positions'] ?? 0),
                    'filled_positions' => (int) ($j['filled_positions'] ?? 0),
                    'remaining'        => (int) ($j['positions'] ?? 0),
                    'status'           => $jStatus,
                    'deadline'         => $j['deadline'] ?? '',
                ];
            }
        }

        // Buckets are keyed by normalized name; join each department doc by the
        // same key so counts include every case/whitespace variant of its name.
        $list = [];
        foreach ($depts as $id => $d) {
            $dName = $d['name'] ?? '';
            $dk    = dept_key($dName);
            $d['id']           = $id;
            $d['staff_count']  = $countByDept[$dk] ?? 0;
            $d['staff']        = $staffByDept[$dk] ?? [];
            $d['openings']     = $jobsByDept[$dk] ?? [];
            $d['open_count']   = count($d['openings']);
            $list[] = $d;
        }

        $this->json_success(['departments' => $list]);
    }

    /**
     * RETIRED 2026-08-21 (Staff Lifecycle audit) — save_department() and
     * delete_department() were removed.
     *
     * They were a duplicate writer of schools.departments. "Staff & Roles"
     * (Staff_access) owns departments and staffRoles together and is the
     * superset; the HR "Departments" tab had already been replaced by a
     * signpost, and the Org page that also posted here has been retired.
     *
     * The two fields these endpoints accepted that Staff_access does not —
     * head_staff_id and department status — were verified DEAD before removal:
     * head_staff_id was written but read by nothing, and no code filters
     * departments by status. Nothing consumed them, so nothing was migrated.
     *
     * get_departments() above STAYS: edit_staff, new_staff, teacher_profile and
     * the HR page all read it to populate department dropdowns, and three test
     * suites assert on it.
     */

    // ====================================================================
    //  RECRUITMENT — JOBS
    // ====================================================================

    /**
     * GET — List all job postings. Optional filter: ?status=Open
     */
    public function get_jobs()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_jobs', 'HR', 'view');

        $filterStatus = trim($this->input->get('status') ?? '');
        $list = [];
        $fromFirestore = false;

        // ── Firestore-first read (use schoolWhere so we keep doc IDs) ──
        try {
            $conditions = [];
            if ($filterStatus !== '') {
                $conditions[] = ['status', '==', $filterStatus];
            }
            $fsDocs = $this->fs->schoolWhere('hrJobs', $conditions);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                $fromFirestore = true;
                $prefix = $this->school_id . '_';
                foreach ($fsDocs as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $r = is_array($doc['data'] ?? null) ? $doc['data'] : [];
                    // Wrapper bug (as in get_departments): the doc id is on the wrapper,
                    // not inside data. Reading it from the data dict yielded '' for every
                    // row, so the view fell back to the array index and row actions
                    // posted "0"/"1" as the entity id.
                    $rawId = (string) ($doc['id'] ?? ($d['id'] ?? ''));
                    $r['id'] = (strpos($rawId, $prefix) === 0)
                        ? substr($rawId, strlen($prefix))
                        : $rawId;
                    $r['applicant_count'] = 0;
                    $list[] = $r;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'HR get_jobs: Firestore read failed: ' . $e->getMessage());
        }

        // Count applicants per job (Firestore)
        $countsByJob = [];
        try {
            $fsApps = $this->fs->schoolList('hrApplicants');
            if (is_array($fsApps)) {
                foreach ($fsApps as $a) {
                    $d = $a['data'] ?? $a;
                    $jid = $d['job_id'] ?? $d['jobId'] ?? '';
                    if ($jid !== '') {
                        $countsByJob[$jid] = ($countsByJob[$jid] ?? 0) + 1;
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'HR get_jobs: Firestore applicant count failed: ' . $e->getMessage());
        }
        foreach ($list as &$j) {
            $j['applicant_count'] = $countsByJob[$j['id']] ?? 0;
        }
        unset($j);

        $this->json_success(['jobs' => $list]);
    }

    /**
     * POST — Create or update a job posting.
     */
    public function save_job()
    {
        $this->_require_role(self::HR_ROLES, 'save_job', 'HR', 'edit');

        $id                  = trim($this->input->post('id') ?? '');
        $title               = trim($this->input->post('title') ?? '');
        $department          = trim($this->input->post('department') ?? '');
        $positions           = (int) ($this->input->post('positions') ?? 1);
        $qualifications      = trim($this->input->post('qualifications') ?? '');
        $experienceRequired  = trim($this->input->post('experience_required') ?? '');
        $salaryRangeMin      = (float) ($this->input->post('salary_range_min') ?? 0);
        $salaryRangeMax      = (float) ($this->input->post('salary_range_max') ?? 0);
        $status              = trim($this->input->post('status') ?? 'Open');
        $deadline            = trim($this->input->post('deadline') ?? '');
        $description         = trim($this->input->post('description') ?? '');

        if ($title === '') {
            $this->json_error('Job title is required.');
        }
        if ($department === '') {
            $this->json_error('Department is required.');
        }
        if ($positions < 1) {
            $this->json_error('Number of positions must be at least 1.');
        }
        if (!in_array($status, ['Open', 'Closed', 'On_Hold'], true)) {
            $status = 'Open';
        }
        if ($deadline !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
            $this->json_error('Deadline must be in YYYY-MM-DD format.');
        }

        $now   = date('c');
        $isNew = ($id === '');

        if ($isNew) {
            $id = $this->_next_id('JOB', 'Job');
        }

        // Resolve department_id from name (Firestore)
        $departmentId = trim($this->input->post('department_id') ?? '');
        if ($departmentId === '') {
            try {
                $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
                if (is_array($fsSchool) && is_array($fsSchool['departments'] ?? null)) {
                    foreach ($fsSchool['departments'] as $did => $dd) {
                        if (($dd['name'] ?? '') === $department) {
                            $departmentId = $did;
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {}
        }

        // Canonical salary range string for cross-system display
        $salaryRangeStr = '';
        if ($salaryRangeMin > 0 || $salaryRangeMax > 0) {
            $salaryRangeStr = '₹' . number_format($salaryRangeMin, 0)
                . ' – ₹' . number_format($salaryRangeMax, 0);
        }

        $data = [
            'title'               => $title,
            'department'          => $department,
            'department_id'       => $departmentId,
            'total_positions'     => $positions,
            'positions'           => $positions,  // backward compat: remaining positions
            'qualifications'      => $qualifications,
            'experience_required' => $experienceRequired,
            'salary_range_min'    => $salaryRangeMin,
            'salary_range_max'    => $salaryRangeMax,
            'status'              => $status,
            'deadline'            => $deadline,
            'description'         => $description,
            'updated_at'          => $now,
            // ── Canonical camelCase mirror for cross-system consumers
            //    (Teacher app RecruitmentDoc reads from this collection).
            'departmentId'        => $departmentId,
            'vacancies'           => $positions,
            'qualification'       => $qualifications,
            'experience'          => $experienceRequired,
            'salaryRangeMin'      => $salaryRangeMin,
            'salaryRangeMax'      => $salaryRangeMax,
            'salaryRange'         => $salaryRangeStr,
            'closingDate'         => $deadline,
            'jobDescription'      => $description,
            'statusLower'         => strtolower(str_replace(' ', '_', $status)),  // open / closed / on_hold
        ];
        if ($isNew) {
            $data['created_at']       = $now;
            $data['created_by']       = $this->admin_name;
            $data['filled_positions'] = 0;
            $data['postedAt']         = $now;     // canonical: teacher orders by this
            $data['postedDate']       = substr($now, 0, 10);
        }

        // ── Firestore-first write ────────────────────────────────────
        try {
            $this->fs->setEntity('hrJobs', $id, $data);
            log_message('debug', "HR save_job: Firestore OK for {$id}");
        } catch (\Exception $e) {
            log_message('error', "HR save_job: Firestore FAILED for {$id}: " . $e->getMessage());
        }

        // ── Auto-create a circular for new job postings ──────────────
        $circularId = '';
        if ($isNew && $status === 'Open') {
            $circularId = $this->_create_job_circular($id, $data);
            // Store circular reference on the job record
            if ($circularId) {
                try {
                    $this->fs->updateEntity('hrJobs', $id, ['circular_id' => $circularId]);
                } catch (\Exception $e) {
                    log_message('error', "HR save_job: Firestore circular_id update FAILED for {$id}: " . $e->getMessage());
                }
            }
        } elseif (!$isNew) {
            // ── Update path: refresh the existing circular's poster + key fields
            //    so Communication → Circulars stays in sync with edited job data.
            $existing = null;
            try { $existing = $this->fs->getEntity('hrJobs', $id); } catch (\Exception $e) {}
            $existingCircularId = is_array($existing) ? ($existing['circular_id'] ?? '') : '';
            if ($existingCircularId) {
                try {
                    $refreshedPoster = $this->_build_circular_poster($data, $existingCircularId);
                    $this->firebase->firestoreSet('circulars', $this->fs->docId($existingCircularId), [
                        'title'       => 'Hiring: ' . ($data['title'] ?? 'Open Position'),
                        'description' => $refreshedPoster,
                        'expiry_date' => $data['deadline'] ?? '',
                        'updated_at'  => date('c'),
                    ], true);
                    $circularId = $existingCircularId;
                } catch (\Exception $e) {
                    log_message('error', "HR save_job: circular auto-refresh FAILED for {$id}: " . $e->getMessage());
                }
            }
        }

        $msg = $isNew ? 'Job posting created.' : 'Job posting updated.';
        if ($circularId) {
            $msg .= " Circular {$circularId} has been auto-generated and published.";
        }

        $this->json_success(['id' => $id, 'circular_id' => $circularId, 'message' => $msg]);
    }

    /**
     * Creates a circular in the Communication module for a new job posting.
     * Returns the circular ID on success, empty string on failure.
     */
    private function _create_job_circular(string $jobId, array $job): string
    {
        try {
            // Generate circular ID via Firestore counter
            $circularId = '';
            $schoolDoc = $this->fs->get('schools', $this->fs->docId('profile'));
            $counters = (is_array($schoolDoc) && isset($schoolDoc['commCounters'])) ? $schoolDoc['commCounters'] : [];
            $cur = isset($counters['Circular']) && is_numeric($counters['Circular']) ? (int) $counters['Circular'] : 0;
            $next = $cur + 1;
            $this->fs->update('schools', $this->fs->docId('profile'), ['commCounters.Circular' => $next]);
            $circularId = 'CIR' . str_pad($next, 4, '0', STR_PAD_LEFT);

            $title   = "Hiring: " . ($job['title'] ?? 'Open Position');
            $poster  = $this->_build_circular_poster($job, $circularId);

            // Build a clean plain-text summary for mobile list previews so the
            // styled HTML poster (stored in `description` for the admin "View
            // Poster" button) doesn't leak CSS into the Parent/Teacher UI.
            $deptTxt    = trim((string) ($job['department'] ?? ''));
            $posTxt     = (int) ($job['positions'] ?? 1);
            $qualTxt    = trim((string) ($job['qualifications'] ?? ''));
            $expTxt     = trim((string) ($job['experience_required'] ?? ''));
            $deadline   = trim((string) ($job['deadline'] ?? ''));
            $summaryParts = [];
            if ($deptTxt !== '')  $summaryParts[] = "Department: {$deptTxt}";
            if ($posTxt > 0)      $summaryParts[] = ($posTxt === 1 ? '1 vacancy' : "{$posTxt} vacancies");
            if ($qualTxt !== '')  $summaryParts[] = "Qualification: {$qualTxt}";
            if ($expTxt !== '')   $summaryParts[] = "Experience: {$expTxt}";
            if ($deadline !== '') $summaryParts[] = "Apply by: {$deadline}";
            $bodyPlain = implode(' • ', $summaryParts) ?: trim(strip_tags($job['description'] ?? ''));

            $nowIso = date('c');
            $circularData = [
                'title'           => $title,
                'description'     => $poster,
                'body'            => $bodyPlain,                 // Android canonical — plain text
                'category'        => 'Recruitment',
                'target_group'    => 'All Staff',
                'targetType'      => 'All Staff',                // Android canonical
                'attachment_url'  => '',
                'attachment_name' => '',
                'attachmentUrl'   => '',
                'issued_by'       => $this->admin_id,
                'issued_by_name'  => $this->admin_name,
                'issued_by_role'  => $this->admin_role ?? '',
                'created_by_role' => $this->admin_role ?? '',
                'author'          => $this->admin_name,          // Android canonical
                'authorId'        => $this->admin_id,
                'authorRole'      => $this->admin_role ?? '',
                'issued_date'     => date('Y-m-d'),
                'expiry_date'     => $job['deadline'] ?? '',
                // 'sent' is what Teacher/Parent app queries filter by (status == "sent").
                // Job open/closed lifecycle lives in a separate `jobStatus` field so
                // the HR module can still gate its own UI.
                'status'          => 'sent',
                'jobStatus'       => 'Active',
                'created_at'      => $nowIso,
                'updated_at'      => $nowIso,
                'sentAt'          => $nowIso,                    // Android canonical sort key
                'updatedAt'       => $nowIso,
                'source'          => 'hr_recruitment',
                'source_id'       => $jobId,
                'is_poster'       => true,
                'type'            => 'circular',
                'schoolId'        => $this->school_id,
            ];
            $this->firebase->firestoreSet('circulars', $this->fs->docId($circularId), $circularData, true);

            // Notice for mobile app (Firestore)
            $noticeId = 'NOTICE_' . uniqid('', true);
            $this->firebase->firestoreSet('notices', $this->fs->docId($noticeId), [
                'title'           => "[Job Circular] {$title}",
                'description'     => strip_tags($poster),
                'body'            => strip_tags($poster),   // Android canonical
                'priority'        => 'High',
                'category'        => 'Recruitment',
                'target_group'    => 'All Staff',
                'targetType'      => 'All Staff',            // Android canonical
                'issued_by'       => $this->admin_id,
                'issued_by_name'  => $this->admin_name,
                'issued_by_role'  => $this->admin_role ?? '',
                'created_by'      => $this->admin_id,
                'created_by_name' => $this->admin_name,
                'created_by_role' => $this->admin_role ?? '',
                'author'          => $this->admin_name,      // Android canonical
                'authorId'        => $this->admin_id,
                'authorRole'      => $this->admin_role ?? '',
                'date'            => date('Y-m-d'),
                'issued_date'     => date('Y-m-d'),
                'created_at'      => date('c'),
                'sentAt'          => date('c'),              // Android canonical
                // Header bell orders by `timestamp` (docs lacking it are
                // excluded) — surface HR recruitment notices there too.
                'timestamp'       => date('c'),
                'timestampMs'     => round(microtime(true) * 1000),
                'updated_at'      => date('c'),
                'updatedAt'       => date('c'),
                'status'          => 'sent',
                'schoolId'        => $this->school_id,
                'type'            => 'announcement',
                'source'          => 'hr_recruitment',
                'source_id'       => $jobId,
            ], true);

            log_message('info', "HR: Auto-created circular {$circularId} for job {$jobId}");

            // Push request — Cloud Function dispatches FCM to teachers+staff.
            // Target is "All Staff" so parents don't receive it (aligns with
            // the parent app's client-side staff-only filter).
            try {
                $pushId = $this->fs->docId('circular_created_' . $circularId);
                $this->firebase->firestoreSet('pushRequests', $pushId, [
                    'schoolId'    => $this->school_id,
                    'mark'        => 'CIRCULAR_CREATED',
                    'source'      => 'circular_created',
                    'status'      => 'pending',
                    'markedBy'    => $this->admin_name,
                    'markedByRole'=> $this->admin_role ?? '',
                    'createdAt'   => date('c'),
                    'title'       => $title,
                    'body'        => trim(substr($bodyPlain, 0, 140)),
                    'category'    => 'Recruitment',
                    'target_group'=> 'All Staff',
                    'circularId'  => $circularId,
                    'source_id'   => $jobId,
                ], true);
            } catch (\Exception $e) {
                log_message('error', "HR push request failed for circular {$circularId}: " . $e->getMessage());
            }
            return $circularId;
        } catch (\Exception $e) {
            log_message('error', "HR: Failed to create circular for job {$jobId}: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Build the recruitment circular.
     *
     * This is an official school notice, not a marketing graphic: letterhead,
     * reference number, dated, a bordered particulars table, and a signature
     * block. That is the form a school actually pins to a board, emails as a
     * PDF and files — the previous gradient card with a "We Are Hiring" pill
     * could not be used as any of those.
     *
     * Everything is driven by the tenant's own school profile. Fields the
     * tenant has not published are omitted rather than filled with
     * placeholders, so an incomplete profile yields a plainer — never a
     * broken or fictitious — notice.
     */
    private function _build_circular_poster(array $job, string $circularId = ''): string
    {
        $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

        /* ── School letterhead data ─────────────────────────────────── */
        $sch = [];
        try {
            $doc = $this->fs->get('schools', $this->fs->schoolId());
            if (is_array($doc)) $sch = $doc;
        } catch (\Exception $ex) {
            log_message('error', 'HR circular: school lookup failed: ' . $ex->getMessage());
        }
        $g = function (string ...$keys) use ($sch) {
            foreach ($keys as $k) {
                $v = trim((string) ($sch[$k] ?? ''));
                if ($v !== '') return $v;
            }
            return '';
        };

        $schoolName = $g('display_name') ?: ($this->school_display_name ?: $this->school_name);
        $schEmail   = $g('email');
        $schPhone   = $g('phone');
        $website    = $g('website');
        // upload_logo writes logo_url but reads logoUrl elsewhere — accept either.
        $logo       = $g('logo_url', 'logoUrl', 'logo');
        $principal  = $g('principal_name');
        $board      = $g('affiliation_board');
        $affNo      = $g('affiliation_no');

        $addrLine = implode(', ', array_filter([
            $g('address'), $g('city'), $g('state'), $g('pincode'),
        ], static function ($v) { return $v !== ''; }));

        /* ── Post data ──────────────────────────────────────────────── */
        $title     = $job['title'] ?? 'Open Position';
        $dept      = $job['department'] ?? '';
        $positions = (int) ($job['positions'] ?? 1);
        $quals     = $job['qualifications'] ?? '';
        $exp       = $job['experience_required'] ?? '';
        $desc      = $job['description'] ?? '';
        $salMin    = (float) ($job['salary_range_min'] ?? 0);
        $salMax    = (float) ($job['salary_range_max'] ?? 0);
        $deadline  = $job['deadline'] ?? '';
        $today     = date('d M Y');

        $salary = '';
        if ($salMin > 0 && $salMax > 0)      $salary = '&#x20B9; ' . number_format($salMin) . ' &ndash; &#x20B9; ' . number_format($salMax) . ' per month';
        elseif ($salMax > 0)                 $salary = 'Up to &#x20B9; ' . number_format($salMax) . ' per month';
        elseif ($salMin > 0)                 $salary = '&#x20B9; ' . number_format($salMin) . '+ per month';

        $deadlineFmt = $deadline ? date('d M Y', strtotime($deadline)) : '';

        /* ── Particulars table ──────────────────────────────────────── */
        $rows = '';
        $row = function (string $label, string $value, bool $raw = false) use (&$rows, $e) {
            if (trim($value) === '') return;
            $v = $raw ? $value : $e($value);
            $rows .= '<tr>'
                  . '<th class="zc-th">' . $e($label) . '</th>'
                  . '<td class="zc-td">' . $v . '</td>'
                  . '</tr>';
        };
        $row('Name of Post',   $title);
        $row('Department',     $dept);
        $row('No. of Vacancies', (string) $positions);
        $row('Qualification',  $quals);
        $row('Experience',     $exp);
        if ($salary !== '')     $row('Salary Range', $salary, true);
        if ($deadlineFmt !== '') $row('Last Date to Apply', $deadlineFmt);

        /* ── Role description ───────────────────────────────────────── */
        $descHtml = '';
        if (trim($desc) !== '') {
            $descHtml = '<div class="zc-sec"><div class="zc-sec-h">Job Description</div>'
                      . '<div class="zc-sec-b">' . $e($desc) . '</div></div>';
        }

        /* ── How to apply ───────────────────────────────────────────── */
        $subject = rawurlencode('Application: ' . $title . ($circularId !== '' ? ' (' . $circularId . ')' : ''));
        if ($schEmail !== '') {
            $applyBody = 'Eligible candidates may submit their application, along with a complete '
                       . 'curriculum vitae and self-attested copies of testimonials, to '
                       . '<strong>' . $e($schEmail) . '</strong>'
                       . ($schPhone !== '' ? ', or contact the school office at <strong>' . $e($schPhone) . '</strong>' : '')
                       . ($deadlineFmt !== '' ? ', on or before <strong>' . $e($deadlineFmt) . '</strong>' : '')
                       . '.';
            $applyBtn = '<a class="zc-btn" href="mailto:' . $e($schEmail) . '?subject=' . $subject . '">Apply Now</a>';
        } elseif ($schPhone !== '') {
            $applyBody = 'Eligible candidates may contact the school office at <strong>' . $e($schPhone) . '</strong>'
                       . ($deadlineFmt !== '' ? ', on or before <strong>' . $e($deadlineFmt) . '</strong>' : '')
                       . ', for application details.';
            $applyBtn  = '<a class="zc-btn" href="tel:' . $e(preg_replace('/[^0-9+]/', '', $schPhone)) . '">Call to Apply</a>';
        } else {
            // Nothing published to act on — state that plainly and draw no
            // control, rather than a button that cannot do anything.
            $applyBody = 'Eligible candidates may contact the school administration for application details.';
            $applyBtn  = '';
        }

        /* ── Letterhead identity ────────────────────────────────────── */
        if ($logo !== '') {
            $mark = '<img class="zc-logo" src="' . $e($logo) . '" alt="">';
        } else {
            // No logo published: a monogram keeps the letterhead balanced
            // instead of leaving a broken image or an empty column.
            $initials = '';
            foreach (preg_split('/\s+/', trim($schoolName)) as $w) {
                if ($w !== '' && $initials !== '' && strlen($initials) >= 2) break;
                if ($w !== '') $initials .= strtoupper(substr($w, 0, 1));
            }
            $mark = '<div class="zc-logo zc-mono">' . $e($initials ?: 'S') . '</div>';
        }

        $subLines = [];
        if ($addrLine !== '') $subLines[] = $e($addrLine);
        $contact = array_filter([
            $schPhone !== '' ? 'Tel: ' . $e($schPhone) : '',
            $schEmail !== '' ? $e($schEmail) : '',
            $website  !== '' ? $e($website)  : '',
        ], static function ($v) { return $v !== ''; });
        if ($contact) $subLines[] = implode(' &nbsp;&middot;&nbsp; ', $contact);
        if ($board !== '' || $affNo !== '') {
            $subLines[] = trim($e($board) . ($affNo !== '' ? ' &nbsp;&middot;&nbsp; Affiliation No. ' . $e($affNo) : ''));
        }
        $subHtml = $subLines ? '<div class="zc-sub">' . implode('<br>', $subLines) . '</div>' : '';

        $refHtml = $circularId !== ''
            ? '<span>Ref. No.: <strong>' . $e($circularId) . '</strong></span>'
            : '<span></span>';

        $signName = $principal !== '' ? $e($principal) : '';
        $signHtml = '<div class="zc-sign">'
                  . ($signName !== '' ? '<div class="zc-sign-n">' . $signName . '</div>' : '')
                  . '<div class="zc-sign-t">Principal</div>'
                  . '<div class="zc-sign-s">' . $e($schoolName) . '</div></div>';

        $schoolNameHtml = $e($schoolName);
        $titleHtml      = $e($title);
        $btnBlock       = $applyBtn !== '' ? '<div class="zc-cta">' . $applyBtn . '</div>' : '';

        /* Styles are scoped under .zx-circ so injecting this into the admin
           modal cannot disturb the surrounding page. @page is deliberately NOT
           set here — the print window owns page geometry. */
        return <<<HTML
<style>
.zx-circ{--zc-ink:#1f2937;--zc-mut:#6b7280;--zc-line:#d8dee7;--zc-acc:#BC5A3C;
  max-width:820px;margin:0 auto;background:#fff;color:var(--zc-ink);
  font:14px/1.65 "Segoe UI",system-ui,-apple-system,sans-serif;
  border:1px solid var(--zc-line);padding:38px 44px 30px;box-sizing:border-box;}
.zx-circ *{box-sizing:border-box;}
.zx-circ .zc-head{display:flex;align-items:center;gap:18px;}
.zx-circ .zc-logo{width:74px;height:74px;flex:0 0 74px;object-fit:contain;border-radius:6px;}
.zx-circ .zc-mono{display:flex;align-items:center;justify-content:center;
  background:var(--zc-acc);color:#fff;font:700 27px/1 Georgia,"Times New Roman",serif;letter-spacing:1px;}
.zx-circ .zc-name{margin:0;font:700 27px/1.2 Georgia,"Times New Roman",serif;letter-spacing:.3px;}
.zx-circ .zc-sub{margin-top:5px;font-size:12px;color:var(--zc-mut);line-height:1.55;}
.zx-circ .zc-rule{margin:16px 0 0;border-top:2.5px solid var(--zc-acc);}
.zx-circ .zc-rule2{margin-top:2px;border-top:1px solid var(--zc-line);}
.zx-circ .zc-meta{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;
  margin-top:14px;font-size:12.5px;color:var(--zc-mut);}
.zx-circ .zc-title{margin:22px 0 4px;text-align:center;font:700 17px/1.3 Georgia,"Times New Roman",serif;
  letter-spacing:2.2px;text-transform:uppercase;}
.zx-circ .zc-title-u{width:132px;height:2px;background:var(--zc-acc);margin:0 auto 20px;}
.zx-circ .zc-lead{margin:0 0 18px;text-align:justify;}
.zx-circ .zc-tbl{width:100%;border-collapse:collapse;margin-bottom:4px;}
.zx-circ .zc-th,.zx-circ .zc-td{border:1px solid var(--zc-line);padding:9px 13px;
  font-size:13.5px;text-align:left;vertical-align:top;}
.zx-circ .zc-th{width:210px;background:#f6f7f9;font-weight:600;color:var(--zc-mut);}
.zx-circ .zc-sec{margin-top:18px;}
.zx-circ .zc-sec-h{font-size:11.5px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;
  color:var(--zc-acc);margin-bottom:6px;}
.zx-circ .zc-sec-b{white-space:pre-line;text-align:justify;}
.zx-circ .zc-cta{margin-top:16px;}
.zx-circ .zc-btn{display:inline-block;padding:10px 30px;background:var(--zc-acc);color:#fff;
  font-size:14px;font-weight:600;border-radius:4px;text-decoration:none;}
.zx-circ .zc-sign{margin-top:44px;text-align:right;}
.zx-circ .zc-sign-n{font:600 15px/1.4 Georgia,"Times New Roman",serif;}
.zx-circ .zc-sign-t{font-size:13px;font-weight:600;margin-top:2px;}
.zx-circ .zc-sign-s{font-size:12px;color:var(--zc-mut);}
.zx-circ .zc-foot{margin-top:26px;padding-top:10px;border-top:1px solid var(--zc-line);
  display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:11px;color:var(--zc-mut);}
@media(max-width:640px){
  .zx-circ{padding:22px 18px;}
  .zx-circ .zc-head{flex-direction:column;text-align:center;}
  .zx-circ .zc-th{width:auto;}
  .zx-circ .zc-tbl,.zx-circ .zc-th,.zx-circ .zc-td{display:block;width:100%;}
  .zx-circ .zc-th{border-bottom:0;}
}
@media print{
  .zx-circ{border:0;padding:0;max-width:none;}
  .zx-circ .zc-btn{display:none;}          /* a link cannot be clicked on paper */
  .zx-circ .zc-tbl{page-break-inside:avoid;}
  .zx-circ .zc-sign{page-break-inside:avoid;}
}
</style>

<div class="zx-circ">
  <div class="zc-head">
    {$mark}
    <div>
      <h1 class="zc-name">{$schoolNameHtml}</h1>
      {$subHtml}
    </div>
  </div>
  <div class="zc-rule"></div>
  <div class="zc-rule2"></div>

  <div class="zc-meta">{$refHtml}<span>Date: <strong>{$today}</strong></span></div>

  <div class="zc-title">Recruitment Notice</div>
  <div class="zc-title-u"></div>

  <p class="zc-lead">Applications are invited from eligible candidates for the following post at
    {$schoolNameHtml}. The particulars of the post are given below.</p>

  <table class="zc-tbl">{$rows}</table>

  {$descHtml}

  <div class="zc-sec">
    <div class="zc-sec-h">How to Apply</div>
    <div class="zc-sec-b">{$applyBody}</div>
    {$btnBlock}
  </div>

  {$signHtml}

  <div class="zc-foot">
    <span>{$titleHtml}</span>
    <span>Issued {$today}</span>
  </div>
</div>
HTML;
    }
    public function delete_job()
    {
        $this->_require_role(self::HR_ROLES, 'delete_job', 'HR', 'manage');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        // ── Firestore-first read for existence check ─────────────────
        $existing = null;
        try {
            $existing = $this->fs->getEntity('hrJobs', $id);
        } catch (\Exception $e) {
            log_message('error', "HR delete_job: Firestore read FAILED for {$id}: " . $e->getMessage());
        }
        if (!is_array($existing)) {
            $this->json_error('Job posting not found.');
        }

        // Check if applicants exist for this job (Firestore)
        $hasLinkedApplicant = false;
        try {
            $fsApps = $this->fs->schoolWhere('hrApplicants', [['job_id', '=', $id]]);
            if (is_array($fsApps) && !empty($fsApps)) {
                $hasLinkedApplicant = true;
            }
        } catch (\Exception $e) {
            log_message('error', "HR delete_job: Firestore applicant check FAILED: " . $e->getMessage());
        }
        if ($hasLinkedApplicant) {
            $this->json_error('Cannot delete: applicants are linked to this job posting. Delete or reassign applicants first.');
        }

        // Cascade: mark linked circular as Inactive (Firestore)
        $circularId = $existing['circular_id'] ?? '';
        if ($circularId !== '') {
            try {
                $this->firebase->firestoreSet('circulars', $this->fs->docId($circularId), [
                    'status'     => 'Inactive',
                    'updated_at' => date('c'),
                    'updated_by' => $this->admin_id,
                ], true);
            } catch (\Exception $e) {
                log_message('error', "HR delete_job: Firestore circular update FAILED: " . $e->getMessage());
            }
        }

        // ── Firestore-first delete ──────────────────────────────────
        try {
            $this->fs->removeEntity('hrJobs', $id);
            log_message('debug', "HR delete_job: Firestore OK for {$id}");
        } catch (\Exception $e) {
            log_message('error', "HR delete_job: Firestore FAILED for {$id}: " . $e->getMessage());
        }

        $this->json_success(['message' => 'Job posting deleted.' . ($circularId ? " Circular {$circularId} marked inactive." : '')]);
    }

    /**
     * GET — View circular poster HTML for a job posting.
     * Params: ?job_id=JOB0001  OR  ?circular_id=CIR0001
     */
    public function view_circular()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_circular', 'HR', 'view');
        header('Content-Type: application/json');

        $jobId      = trim($this->input->get('job_id') ?? '');
        $circularId = trim($this->input->get('circular_id') ?? '');

        $school = $this->school_name;
        $job    = null;
        $circular = null;

        if ($jobId) {
            try { $job = $this->fs->getEntity('hrJobs', $jobId); } catch (\Exception $e) {}
            if (!is_array($job)) {
                echo json_encode(['status' => 'error', 'message' => 'Job not found.']);
                return;
            }
            $circularId = $job['circular_id'] ?? '';
        }

        if ($circularId) {
            try {
                $circular = $this->firebase->firestoreGet('circulars', $this->fs->docId($circularId));
            } catch (\Exception $e) {}
            if (is_array($circular) && !$job) {
                $srcId = $circular['source_id'] ?? '';
                if ($srcId) {
                    try { $job = $this->fs->getEntity('hrJobs', $srcId); } catch (\Exception $e) {}
                }
            }
        }

        if (!is_array($job)) {
            echo json_encode(['status' => 'error', 'message' => 'No job data found.']);
            return;
        }

        $posterHtml = $this->_build_circular_poster($job, $circularId);

        echo json_encode([
            'status'      => 'success',
            'poster_html' => $posterHtml,
            'circular_id' => $circularId,
            'job_id'      => $jobId ?: ($circular['source_id'] ?? ''),
        ]);
    }

    /**
     * POST — Regenerate the circular for an existing job posting.
     * Params: job_id
     */
    public function regenerate_circular()
    {
        $this->_require_role(self::HR_ROLES, 'regenerate_circular', 'HR', 'edit');

        $jobId = trim($this->input->post('job_id') ?? '');
        if (!$jobId) $this->json_error('Job ID required.');
        $jobId = $this->safe_path_segment($jobId, 'job_id');

        $job = null;
        try { $job = $this->fs->getEntity('hrJobs', $jobId); } catch (\Exception $e) {}
        if (!is_array($job)) $this->json_error('Job posting not found.');

        $oldCircularId = $job['circular_id'] ?? '';

        // Delete old circular if it exists (Firestore)
        if ($oldCircularId) {
            try {
                $this->firebase->firestoreDelete('circulars', $this->fs->docId($oldCircularId));
            } catch (\Exception $e) {}
        }

        // Create fresh circular
        $newCircularId = $this->_create_job_circular($jobId, $job);
        if ($newCircularId) {
            try { $this->fs->updateEntity('hrJobs', $jobId, ['circular_id' => $newCircularId]); } catch (\Exception $e) {}
        }

        $this->json_success([
            'circular_id' => $newCircularId,
            'message'     => $newCircularId
                ? "Circular regenerated as {$newCircularId}."
                : 'Failed to regenerate circular.',
        ]);
    }

    // ====================================================================
    //  RECRUITMENT — APPLICANTS
    // ====================================================================

    /**
     * GET — List applicants. Optional filters: ?job_id=JOB0001&status=Applied
     */
    public function get_applicants()
    {
        $this->_require_role(self::HR_ROLES, 'get_applicants', 'HR', 'edit');

        $filterJob    = trim($this->input->get('job_id') ?? '');
        $filterStatus = trim($this->input->get('status') ?? '');
        $list = [];
        $fromFirestore = false;

        // ── Firestore-first read (use schoolWhere so we keep doc IDs) ──
        try {
            $conditions = [];
            if ($filterJob !== '') {
                $conditions[] = ['job_id', '==', $filterJob];
            }
            if ($filterStatus !== '') {
                $conditions[] = ['status', '==', $filterStatus];
            }
            $fsDocs = $this->fs->schoolWhere('hrApplicants', $conditions);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                $fromFirestore = true;
                $prefix = $this->school_id . '_';
                foreach ($fsDocs as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $r = is_array($doc['data'] ?? null) ? $doc['data'] : [];
                    // Wrapper bug (as in get_departments): the doc id is on the wrapper,
                    // not inside data. Reading it from the data dict yielded '' for every
                    // row, so the view fell back to the array index and row actions
                    // posted "0"/"1" as the entity id.
                    $rawId = (string) ($doc['id'] ?? ($d['id'] ?? ''));
                    $r['id'] = (strpos($rawId, $prefix) === 0)
                        ? substr($rawId, strlen($prefix))
                        : $rawId;
                    $list[] = $r;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'HR get_applicants: Firestore read failed: ' . $e->getMessage());
        }

        // Sort by applied date descending
        usort($list, function ($a, $b) {
            return strcmp($b['applied_date'] ?? $b['appliedDate'] ?? '', $a['applied_date'] ?? $a['appliedDate'] ?? '');
        });

        $this->json_success(['applicants' => $list]);
    }

    /**
     * POST — Create or update an applicant.
     */
    public function save_applicant()
    {
        $this->_require_role(self::HR_ROLES, 'save_applicant', 'HR', 'edit');

        $id            = trim($this->input->post('id') ?? '');
        $jobId         = trim($this->input->post('job_id') ?? '');
        if ($id !== '') {
            $id = $this->safe_path_segment($id, 'id');
        }
        $jobId         = $this->safe_path_segment($jobId, 'job_id');
        $name          = trim($this->input->post('name') ?? '');
        $email         = trim($this->input->post('email') ?? '');
        $phone         = trim($this->input->post('phone') ?? '');
        $qualification = trim($this->input->post('qualification') ?? '');
        $experience    = trim($this->input->post('experience') ?? '');
        $resumeNotes   = trim($this->input->post('resume_notes') ?? '');
        $interviewDate = trim($this->input->post('interview_date') ?? '');
        $interviewNotes= trim($this->input->post('interview_notes') ?? '');
        $rating        = (int) ($this->input->post('rating') ?? 0);
        // REC-D5: ?? only catches a MISSING field. A select with nothing selected
        // posts an empty string, which slipped past it and stored status=''.
        $status        = trim($this->input->post('status') ?? '');
        if ($status === '') $status = 'Applied';
        $notes         = trim($this->input->post('notes') ?? '');

        if ($name === '') {
            $this->json_error('Applicant name is required.');
        }
        if ($jobId === '') {
            $this->json_error('Job posting is required.');
        }

        // Normalise status to title-case
        $status = ucfirst(strtolower($status));
        if ($status === 'Interviewed') $status = 'Interview';
        $validStatuses = ['Applied', 'Shortlisted', 'Interview', 'Selected', 'Rejected', 'Joined'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'Applied';
        }

        // Verify job exists (Firestore-first, RTDB fallback)
        $job = null;
        try {
            $job = $this->fs->getEntity('hrJobs', $jobId);
        } catch (\Exception $e) {
            log_message('error', "HR save_applicant: Firestore job check FAILED: " . $e->getMessage());
        }
        if (!is_array($job)) {
            $this->json_error('Job posting not found.');
        }

        $now   = date('c');
        $isNew = ($id === '');

        if ($isNew) {
            $id = $this->_next_id('APP', 'Applicant');
        }

        // Preserve applied_date on edit (Firestore)
        $existingApplicant = null;
        if (!$isNew) {
            try {
                $existingApplicant = $this->fs->getEntity('hrApplicants', $id);
            } catch (\Exception $e) {
                log_message('error', "HR save_applicant: Firestore read FAILED for {$id}: " . $e->getMessage());
            }
        }

        $data = [
            'job_id'          => $jobId,
            'name'            => $name,
            'email'           => $email,
            'phone'           => $phone,
            'qualification'   => $qualification,
            'experience'      => $experience,
            'resume_notes'    => $resumeNotes,
            'interview_date'  => $interviewDate,
            'interview_notes' => $interviewNotes,
            'rating'          => $rating,
            'status'          => $status,
            'notes'           => $notes,
            'updated_at'      => $now,
            'updated_by'      => $this->admin_name,
        ];
        if ($isNew) {
            $data['applied_date'] = date('Y-m-d');
        } else {
            $data['applied_date'] = $existingApplicant['applied_date'] ?? date('Y-m-d');
        }

        // ── W1.3 dual-emit: see update_applicant_status() for the reasoning ──
        // The HR status vocabulary and the ATS stage vocabulary are exact
        // inverses for every non-rejected value, so round-tripping an edit
        // through this form cannot move a candidate backwards.
        if ($status !== 'Rejected') {
            $data['stage'] = recruitment_stage_from_status($status);
        }
        $derivedAtsStatus = recruitment_ats_status_from_status($status);
        if ($derivedAtsStatus !== null) {
            $data['ats_status'] = $derivedAtsStatus;
        }

        // ── Firestore-first write ────────────────────────────────────
        try {
            $this->fs->setEntity('hrApplicants', $id, $data);
            log_message('debug', "HR save_applicant: Firestore OK for {$id}");
        } catch (\Exception $e) {
            log_message('error', "HR save_applicant: Firestore FAILED for {$id}: " . $e->getMessage());
        }

        $this->json_success(['id' => $id, 'message' => $isNew ? 'Applicant added.' : 'Applicant updated.']);
    }

    /**
     * POST — Update only the status of an applicant (quick action).
     * Params: id, status, notes (optional remark)
     */
    public function update_applicant_status()
    {
        $this->_require_role(self::HR_ROLES, 'update_applicant_status', 'HR', 'edit');

        $id     = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');
        $status = trim($this->input->post('status') ?? '');
        $notes  = trim($this->input->post('notes') ?? '');

        $validStatuses = ['Applied', 'Shortlisted', 'Interview', 'Selected', 'Rejected', 'Joined'];
        if (!in_array($status, $validStatuses, true)) {
            $this->json_error('Invalid status.');
        }

        // ── Firestore-first existence check ─────────────────────────
        $existing = null;
        try {
            $existing = $this->fs->getEntity('hrApplicants', $id);
        } catch (\Exception $e) {
            log_message('error', "HR update_applicant_status: Firestore read FAILED for {$id}: " . $e->getMessage());
        }
        if (!is_array($existing)) {
            $this->json_error('Applicant not found.');
        }

        $update = [
            'status'     => $status,
            'updated_at' => date('c'),
            'updated_by' => $this->admin_name,
        ];
        if ($notes !== '') {
            $update['notes'] = $notes;
        }

        // ── W1.3 dual-emit: keep the ATS view of this document in step ──
        // Ats.php reads `stage` in preference to `status`, so writing `status`
        // alone would leave a stale stage behind and the ATS board would keep
        // showing the candidate in their old column. 'Rejected' is deliberately
        // excluded from the stage write: rejection is carried by `ats_status`
        // and has no stage of its own, so mapping it would destroy the record
        // of how far the candidate actually got.
        if ($status !== 'Rejected') {
            $update['stage'] = recruitment_stage_from_status($status);
        }
        $derivedAtsStatus = recruitment_ats_status_from_status($status);
        if ($derivedAtsStatus !== null) {
            $update['ats_status'] = $derivedAtsStatus;
        }

        // ── Firestore write ────────────────────────────────────
        try {
            $this->fs->updateEntity('hrApplicants', $id, $update);
            log_message('debug', "HR update_applicant_status: Firestore OK for {$id}");
        } catch (\Exception $e) {
            log_message('error', "HR update_applicant_status: Firestore FAILED for {$id}: " . $e->getMessage());
        }

        $this->json_success(['message' => "Applicant status updated to {$status}."]);
    }

    /**
     * POST — Delete an applicant.
     * Params: id
     */
    public function delete_applicant()
    {
        $this->_require_role(self::HR_ROLES, 'delete_applicant', 'HR', 'manage');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        // ── Firestore-first existence check ─────────────────────────
        $existing = null;
        try {
            $existing = $this->fs->getEntity('hrApplicants', $id);
        } catch (\Exception $e) {
            log_message('error', "HR delete_applicant: Firestore read FAILED for {$id}: " . $e->getMessage());
        }
        if (!is_array($existing)) {
            $this->json_error('Applicant not found.');
        }

        // ── Firestore delete ──────────────────────────────────
        try {
            $this->fs->removeEntity('hrApplicants', $id);
            log_message('debug', "HR delete_applicant: Firestore OK for {$id}");
        } catch (\Exception $e) {
            log_message('error', "HR delete_applicant: Firestore FAILED for {$id}: " . $e->getMessage());
        }

        $this->json_success(['message' => 'Applicant deleted.']);
    }

    // ====================================================================
    //  LEAVE MANAGEMENT — TYPES
    // ====================================================================

    /**
     * GET — List all leave types.
     * Firestore-first → RTDB fallback via helper.
     */
    public function get_leave_types()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_leave_types', 'HR', 'view');

        $list = [];
        $allTypes = $this->_fsGetLeaveType('');
        if (is_array($allTypes)) {
            foreach ($allTypes as $id => $t) {
                if (is_array($t)) {
                    $t['id'] = $id;
                    $list[] = $t;
                }
            }
        }

        // Surface the school's OWN resolved leave-policy switches so the config
        // screen renders each toggle in its true state. Every value is resolved
        // per-school (schools.leavePolicy override, else platform default) — the
        // exact values the engine + apply_leave act on, so the UI never lies.
        $this->json_success([
            'leave_types'    => $list,
            'accrualEnabled' => $this->_school_accrual_enabled(),
            'leavePolicy'    => [
                'accrual_enabled'  => $this->_school_accrual_enabled(),
                'effective_dating' => $this->_leave_flag('LEAVE_EFFECTIVE_DATING_ENABLED'),
                'prorata'          => $this->_leave_flag('LEAVE_PRORATA_ENABLED'),
                'waiting_period'   => $this->_leave_flag('LEAVE_WAITING_PERIOD_ENABLED'),
                'max_balance'      => $this->_leave_flag('LEAVE_MAX_BALANCE_ENABLED'),
                'year_closure'     => $this->_leave_flag('LEAVE_YEAR_CLOSURE_ENABLED'),
            ],
        ]);
    }

    /**
     * POST — the school's master leave-accrual switch
     * (schools.leavePolicy.accrualEnabled). When ON, leave types whose version
     * carries accrual_mode='monthly' drip their entitlement across the year
     * (run via Run Monthly Accrual) and an employee may only take what has
     * accrued so far; when OFF (the default for every school), the full annual
     * is granted up front exactly as before. This is the per-school choice that
     * replaces the old fleet-wide LEAVE_ACCRUAL_ENABLED flag.
     */
    /**
     * The per-school leave-policy switches a school configures FOR ITSELF. Each
     * POST key maps to the schools.leavePolicy field the resolvers read
     * (_school_accrual_enabled / _leave_flag). Every one defaults OFF, so a new
     * school behaves exactly as before until it opts in from its own config page
     * — this is the multi-tenant contract: the developer ships the machinery,
     * the SCHOOL decides the policy. (accrualEnabled is the accrual master switch;
     * the LEAVE_* keys are the same names the platform-default flags use, so a
     * per-school value simply overrides the platform default for that tenant.)
     */
    private const LEAVE_POLICY_KEYS = [
        'accrual_enabled'  => 'accrualEnabled',
        'effective_dating' => 'LEAVE_EFFECTIVE_DATING_ENABLED',
        'prorata'          => 'LEAVE_PRORATA_ENABLED',
        'waiting_period'   => 'LEAVE_WAITING_PERIOD_ENABLED',
        'max_balance'      => 'LEAVE_MAX_BALANCE_ENABLED',
        'year_closure'     => 'LEAVE_YEAR_CLOSURE_ENABLED',
    ];

    public function save_leave_policy()
    {
        $this->_require_role(self::HR_ROLES, 'save_leave_policy', 'HR', 'manage');

        $truthy = function ($v) {
            return ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on' || $v === 'yes');
        };
        // Only update the toggles actually submitted (a partial save must not
        // silently reset the others).
        $patch = [];
        $changed = [];
        foreach (self::LEAVE_POLICY_KEYS as $postKey => $field) {
            $raw = $this->input->post($postKey);
            if ($raw === null) continue;
            $val = $truthy($raw);
            $patch["leavePolicy.{$field}"] = $val;
            $changed[$field] = $val;
        }
        if (empty($patch)) {
            return $this->json_error('No leave-policy setting was provided.');
        }

        $ok = $this->fs->update('schools', $this->fs->schoolId(), $patch);
        if (!$ok) {
            return $this->json_error('Could not save the leave policy. Please retry.');
        }

        $this->_log_leave_audit([
            'action'  => 'leave_policy_updated',
            'changed' => $changed,
        ]);

        // Back-compat for the accrual toggle, which reads r.accrualEnabled.
        $resp = [
            'leavePolicy' => $changed,
            'message'     => 'Leave policy saved — each setting is this school\'s own; defaults stay off until you enable them.',
        ];
        if (array_key_exists('accrualEnabled', $changed)) {
            $resp['accrualEnabled'] = $changed['accrualEnabled'];
            $resp['message'] = $changed['accrualEnabled']
                ? 'Monthly accrual is ON. Entitlement now accrues month by month — run Run Monthly Accrual to credit the year so far.'
                : 'Monthly accrual is OFF. The full annual leave is granted up front, as before.';
        }
        $this->json_success($resp);
    }

    /**
     * POST — Create or update a leave type.
     */
    public function save_leave_type()
    {
        $this->_require_role(self::HR_ROLES, 'save_leave_type', 'HR', 'edit');

        $id           = trim($this->input->post('id') ?? '');
        $name         = trim($this->input->post('name') ?? '');
        $code         = strtoupper(trim($this->input->post('code') ?? ''));
        $daysPerYear  = (int) ($this->input->post('days_per_year') ?? 0);
        $carryForward = filter_var($this->input->post('carry_forward') ?? false, FILTER_VALIDATE_BOOLEAN);
        $maxCarry     = (int) ($this->input->post('max_carry') ?? 0);
        $paid         = filter_var($this->input->post('paid') ?? false, FILTER_VALIDATE_BOOLEAN);
        $description  = trim($this->input->post('description') ?? '');
        $status       = trim($this->input->post('status') ?? 'Active');

        // Phase 3 (accrual) — per-type mode. Default 'lump_sum' preserves
        // current behavior for every type that never sets this explicitly.
        $accrualMode = trim((string) $this->input->post('accrual_mode'));
        if (!in_array($accrualMode, ['lump_sum', 'monthly'], true)) $accrualMode = 'lump_sum';

        // Phase 6 (maximum balance) — forward-declared in the version shape
        // now so Phase 6 doesn't need a second schema change later. null =
        // no ceiling (current behavior); only consulted when
        // LEAVE_MAX_BALANCE_ENABLED is on (Hr::_accrue_monthly_leave_one_type()).
        $maxBalanceRaw = trim((string) $this->input->post('max_balance'));
        $maxBalance    = ($maxBalanceRaw === '') ? null : (float) $maxBalanceRaw;

        // Phase 5 (waiting period) — same forward-declaration; default 0
        // preserves current behavior (no eligibility delay).
        $waitingPeriodDays = max(0, (int) ($this->input->post('waiting_period_days') ?? 0));

        if ($name === '') {
            $this->json_error('Leave type name is required.');
        }
        if ($daysPerYear < 0 || $daysPerYear > 365) {
            $this->json_error('Days per year must be between 0 and 365.');
        }
        if ($maxBalance !== null && $maxBalance < 0) {
            $this->json_error('Maximum balance cannot be negative.');
        }
        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        $now   = date('c');
        $isNew = ($id === '');

        if ($isNew) {
            $id = $this->_next_id('LT', 'LeaveType');
        }

        // Check duplicate name — Firestore-first via helper
        $existing = $this->_fsGetLeaveType('');
        // Snapshot the PREVIOUS value (for change detection → balance propagation).
        $prevType = (is_array($existing) && is_array($existing[$id] ?? null)) ? $existing[$id] : null;
        if (is_array($existing)) {
            foreach ($existing as $eid => $et) {
                if ($eid !== $id && isset($et['name']) && strtolower($et['name']) === strtolower($name)) {
                    $this->json_error('A leave type with this name already exists.');
                }
            }
        }

        $data = [
            'name'          => $name,
            'code'          => $code,
            'days_per_year' => $daysPerYear,
            'carry_forward' => $carryForward,
            'max_carry'     => $maxCarry,
            'paid'          => $paid,
            'description'   => $description,
            'status'        => $status,
            'updated_at'    => $now,
            // Additive flat mirrors of the version fields (Phases 3/5/6) —
            // extra keys on a Firestore doc the Teacher app already reads
            // directly are safe (its Kotlin model simply doesn't parse
            // them); nothing existing changes shape or is removed.
            'accrual_mode'        => $accrualMode,
            'max_balance'         => $maxBalance,
            'waiting_period_days' => $waitingPeriodDays,
        ];
        if ($isNew) {
            $data['created_at'] = $now;
        }

        // Allocation-affecting change detection — shared by the Phase 2
        // effective-dated version append below AND the legacy retroactive
        // propagation path further down, so both agree on what counts as
        // a real policy change (a description/name-only edit is neither).
        $allocAffecting = [
            'days_per_year', 'carry_forward', 'max_carry', 'status',
            'accrual_mode', 'max_balance', 'waiting_period_days',
        ];
        $allocChanged = false;
        if (!$isNew && is_array($prevType)) {
            foreach ($allocAffecting as $f) {
                $old = $prevType[$f] ?? null;
                $new = $data[$f] ?? null;
                if (is_bool($old) || is_bool($new)) {
                    if ((bool) $old !== (bool) $new) { $allocChanged = true; break; }
                } elseif ((string) $old !== (string) $new) {
                    $allocChanged = true; break;
                }
            }
        }

        // Leave Policy Programme Phase 2 (2026-08-18) — effective-dated
        // versions. Flat fields above stay the dual-written, currently-
        // effective SNAPSHOT (unchanged shape — the Teacher app reads
        // schools.leaveTypes directly), so nothing outside this block is
        // aware versions[] exists. Only written/appended when
        // LEAVE_EFFECTIVE_DATING_ENABLED — off by default, so this whole
        // block is inert (identical document shape to before) for every
        // school until explicitly turned on. Only appended on a genuine
        // allocation-affecting change (or type creation) — a no-op resave
        // or a description-only edit must not mint a redundant version.
        $versionAppendError = null;
        if ($this->_leave_flag('LEAVE_EFFECTIVE_DATING_ENABLED') && ($isNew || $allocChanged)) {
            require_once APPPATH . 'libraries/Leave_policy_service.php';
            $existingVersions = (!$isNew && is_array($prevType) && is_array($prevType['versions'] ?? null))
                ? $prevType['versions'] : [];
            $effectiveFrom = trim((string) $this->input->post('effective_from'));
            if ($effectiveFrom === '') $effectiveFrom = substr($now, 0, 10); // server today
            $check = \Leave_policy_service::canAppendVersion($existingVersions, $effectiveFrom);
            if ($check['ok']) {
                $existingVersions[] = [
                    'effectiveFrom'      => $effectiveFrom,
                    'days_per_year'      => $daysPerYear,
                    'carry_forward'      => $carryForward,
                    'max_carry'          => $maxCarry,
                    'paid'               => $paid,
                    'status'             => $status,
                    'accrual_mode'       => $accrualMode,
                    'max_balance'        => $maxBalance,
                    'waiting_period_days'=> $waitingPeriodDays,
                ];
                $data['versions'] = $existingVersions;
            } else {
                // Refuse ambiguity, never silently overwrite/last-write-wins
                // (mirrors Policy_assignment_service's convention). The base
                // type save still proceeds with its flat-field snapshot —
                // only the new dated version is rejected — so a mistaken
                // duplicate date doesn't block ordinary editing entirely.
                $versionAppendError = $check['error'];
                if (!empty($existingVersions)) $data['versions'] = $existingVersions;
            }
        }

        // 1. Firestore FIRST — update leaveTypes map on schools doc.
        // H4: check the write RESULT — never report success on a failed write.
        $ok = false;
        try {
            $allTypes = is_array($existing) ? $existing : [];
            $allTypes[$id] = $data;
            $ok = $this->fs->update('schools', $this->fs->schoolId(), [
                'leaveTypes' => $allTypes,
                'updatedAt'  => $now,
            ]);
        } catch (\Exception $e) {
            log_message('error', "HR: save_leave_type -> Firestore FAILED for {$id}: " . $e->getMessage());
            $ok = false;
        }
        if (!$ok) {
            $this->json_error('Could not save the leave type. Please try again.');
        }

        // Audit (MEDIUM #9): leave-type config changes are now audit-logged.
        // Phase F (2026-08-18) — the entry previously recorded only the NEW
        // value, so a later reader could see THAT days_per_year changed but
        // not the actual delta (e.g. "was 12, now 20") that drove a
        // retroactive balance rewrite via _propagate_leave_type_to_balances().
        // Purely additive traceability — does not change what gets
        // propagated or how; ground-laying for the still-undecided
        // effective-dating policy (retroactive vs. prospective quota edits),
        // not an implementation of it.
        $auditEntry = [
            'action'       => $isNew ? 'leave_type_created' : 'leave_type_updated',
            'leave_type_id'=> $id,
            'leave_type'   => $code,
            'type_name'    => $name,
            'paid_flag'    => $paid,
            'days_per_year'=> $daysPerYear,
        ];
        if (!$isNew && is_array($prevType)) {
            $auditEntry['previous_days_per_year'] = (int) ($prevType['days_per_year'] ?? 0);
            $auditEntry['previous_carry_forward'] = (bool) ($prevType['carry_forward'] ?? false);
            $auditEntry['previous_max_carry']     = (int) ($prevType['max_carry'] ?? 0);
            $auditEntry['previous_status']        = (string) ($prevType['status'] ?? 'Active');
            $auditEntry['previous_paid_flag']     = (bool) ($prevType['paid'] ?? false);
        }
        $this->_log_leave_audit($auditEntry);

        // Propagate to existing stored balances if an allocation-affecting field
        // changed (single source of truth, retroactive). New types have no stored
        // balances yet — get_leave_balances derives their entitlement live — so we
        // only propagate on EDITS. Propagation failure must NOT fail the type save;
        // it is surfaced as a non-fatal warning instead.
        //
        // Phase 2 — this retroactive rewrite is exactly what effective-dated
        // versioning replaces. When the flag is on, a new version was
        // already appended above (prospective — future Leave Years only);
        // running the retroactive propagator too would reintroduce the
        // exact "quota edit silently changes an already-consumed balance"
        // risk this phase exists to close, so it's skipped entirely.
        $warning = null;
        if ($versionAppendError !== null) {
            $warning = $versionAppendError === 'duplicate_effective_from'
                ? "Leave type saved, but a policy version effective {$effectiveFrom} already exists — choose a different effective date to record this change."
                : 'Leave type saved, but the effective date for this change was invalid — no new policy version was recorded.';
        } elseif (!$isNew && is_array($prevType) && !$this->_leave_flag('LEAVE_EFFECTIVE_DATING_ENABLED')) {
            if ($allocChanged) {
                try {
                    $propYear = date('Y');
                    $affected = $this->_propagate_leave_type_to_balances($id, $data, $propYear);
                    $this->_log_leave_audit([
                        'action'                 => 'leave_type_propagated',
                        'leave_type_id'          => $id,
                        'leave_type'             => $code,
                        'type_name'              => $name,
                        'days_per_year'          => $daysPerYear,
                        'previous_days_per_year' => (int) ($prevType['days_per_year'] ?? 0),
                        'year'                   => $propYear,
                        'staff_updated'          => $affected,
                    ]);
                } catch (\Exception $e) {
                    log_message('error', "HR: save_leave_type -> balance propagation FAILED for {$id}: " . $e->getMessage());
                    $warning = 'Leave type saved, but existing balances could not be updated automatically. Run "Initialize Balances" to reconcile.';
                }
            }
        }

        $resp = ['id' => $id, 'message' => $isNew ? 'Leave type created.' : 'Leave type updated.'];
        if ($warning !== null) $resp['warning'] = $warning;
        $this->json_success($resp);
    }

    /**
     * POST — Delete a leave type.
     * Params: id
     */
    public function delete_leave_type()
    {
        $this->_require_role(self::HR_ROLES, 'delete_leave_type', 'HR', 'manage');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        // Verify existence — Firestore-first via helper
        $allTypes = $this->_fsGetLeaveType('');
        $existing = is_array($allTypes) && isset($allTypes[$id]) ? $allTypes[$id] : null;
        if (!is_array($existing)) {
            $this->json_error('Leave type not found.');
        }

        // Check active requests — Firestore-first via helper
        $hasActive = false;
        $allReqs = $this->_fsGetAllLeaveRequests();
        foreach ($allReqs as $lr) {
            if (
                ($lr['type_id'] ?? '') === $id &&
                // H1: read status case-insensitively (legacy CapitalCase + lowercase).
                in_array(strtolower((string) ($lr['status'] ?? '')), ['pending', 'approved'], true)
            ) {
                $hasActive = true;
                break;
            }
        }
        if ($hasActive) {
            $this->json_error('Cannot delete: active leave requests exist for this type.');
        }

        // 1. Firestore FIRST — remove from leaveTypes map.
        // H4: check the write RESULT before reporting success.
        $ok = false;
        try {
            if (is_array($allTypes)) {
                unset($allTypes[$id]);
                $ok = $this->fs->update('schools', $this->fs->schoolId(), [
                    'leaveTypes' => $allTypes,
                    'updatedAt'  => date('c'),
                ]);
            }
        } catch (\Exception $e) {
            log_message('error', "HR: delete_leave_type -> Firestore FAILED for {$id}: " . $e->getMessage());
            $ok = false;
        }
        if (!$ok) {
            $this->json_error('Could not delete the leave type. Please try again.');
        }

        // Audit (MEDIUM #9)
        $this->_log_leave_audit([
            'action'        => 'leave_type_deleted',
            'leave_type_id' => $id,
            'leave_type'    => strtoupper(trim($existing['code'] ?? '')),
            'type_name'     => $existing['name'] ?? '',
        ]);

        $this->json_success(['message' => 'Leave type deleted.']);
    }

    /**
     * POST — Seed default leave types (idempotent).
     * Skips any that already exist (matched by uppercase code).
     */
    public function seed_leave_types()
    {
        $this->_require_role(self::HR_ROLES, 'seed_leave_types', 'HR', 'manage');

        $defaults = [
            ['name' => 'Casual Leave',        'code' => 'CL',       'days_per_year' => 12, 'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Short-duration personal leave for unforeseen needs'],
            ['name' => 'Sick Leave',           'code' => 'SL',       'days_per_year' => 12, 'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Leave for illness or medical treatment'],
            ['name' => 'Earned Leave',         'code' => 'EL',       'days_per_year' => 15, 'paid' => true,  'carry_forward' => true,  'max_carry' => 30, 'description' => 'Privilege leave earned through service, can be carried forward'],
            ['name' => 'Maternity Leave',      'code' => 'MATERNITY','days_per_year' => 180,'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Maternity leave as per government norms'],
            ['name' => 'Paternity Leave',      'code' => 'PATERNITY','days_per_year' => 15, 'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Paternity leave for new fathers'],
            ['name' => 'Leave Without Pay',    'code' => 'LWP',      'days_per_year' => 0,  'paid' => false, 'carry_forward' => false, 'max_carry' => 0,  'description' => 'Unpaid leave — deducted from salary'],
            ['name' => 'Compensatory Off',     'code' => 'COMP_OFF', 'days_per_year' => 0,  'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Compensatory leave for working on holidays or weekends'],
            ['name' => 'Academic Leave',       'code' => 'ACADEMIC', 'days_per_year' => 10, 'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Leave for academic pursuits, conferences, or training'],
            ['name' => 'On Duty Leave',        'code' => 'DUTY',     'days_per_year' => 0,  'paid' => true,  'carry_forward' => false, 'max_carry' => 0,  'description' => 'Official duty outside school premises'],
        ];

        // Fetch existing leave types — Firestore-first via helper
        $existing = $this->_fsGetLeaveType('');
        $existingCodes = [];
        if (is_array($existing)) {
            foreach ($existing as $et) {
                if (isset($et['code'])) {
                    $existingCodes[] = strtoupper(trim($et['code']));
                }
            }
        }

        $now     = date('c');
        $added   = [];
        $skipped = [];
        $allTypes = is_array($existing) ? $existing : [];

        foreach ($defaults as $def) {
            $code = strtoupper($def['code']);
            if (in_array($code, $existingCodes, true)) {
                $skipped[] = $code;
                continue;
            }

            $id   = $this->_next_id('LT', 'LeaveType');
            $data = [
                'name'          => $def['name'],
                'code'          => $code,
                'days_per_year' => $def['days_per_year'],
                'paid'          => $def['paid'],
                'carry_forward' => $def['carry_forward'],
                'max_carry'     => $def['max_carry'],
                'description'   => $def['description'],
                'status'        => 'Active',
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
            $allTypes[$id] = $data;
            $added[] = $code;
        }

        // 1. Firestore FIRST — sync all leave types to schools doc.
        // H4: check the write RESULT before reporting success.
        if (!empty($added)) {
            $ok = false;
            try {
                $ok = $this->fs->update('schools', $this->fs->schoolId(), [
                    'leaveTypes' => $allTypes,
                    'updatedAt'  => $now,
                ]);
            } catch (\Exception $e) {
                log_message('error', "HR: seed_leave_types -> Firestore FAILED: " . $e->getMessage());
                $ok = false;
            }
            if (!$ok) {
                $this->json_error('Could not seed leave types. Please try again.');
            }
            // Audit (MEDIUM #9)
            $this->_log_leave_audit([
                'action'    => 'leave_types_seeded',
                'added'     => $added,
                'skipped'   => $skipped,
            ]);
        }

        $msg = count($added) . ' leave type(s) added';
        if (count($skipped)) {
            $msg .= ', ' . count($skipped) . ' already existed';
        }

        $this->json_success([
            'message' => $msg,
            'added'   => $added,
            'skipped' => $skipped,
        ]);
    }

    /**
     * GET — Retrieve leave audit logs (most recent first, limit 200).
     */
    public function get_leave_audit_log()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_leave_audit_log', 'HR', 'view');

        $list = [];

        // 1. Firestore FIRST
        try {
            $fsDocs = $this->fs->schoolWhere('leaveApplications', [
                ['type', '==', 'audit'],
            ], 'timestamp', 'DESC', self::LEAVE_AUDIT_LIMIT);
            foreach ($fsDocs as $doc) {
                $d = $doc['data'];
                $d['id'] = $d['id'] ?? '';
                $list[] = $d;
            }
            if (!empty($list)) {
                $this->json_success(['audit_logs' => $list]);
                return;
            }
        } catch (\Exception $e) {
            log_message('error', "HR get_leave_audit_log: Firestore read failed: " . $e->getMessage());
        }

        usort($list, function ($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });
        $list = array_slice($list, 0, self::LEAVE_AUDIT_LIMIT);

        $this->json_success(['audit_logs' => $list]);
    }

    // ====================================================================
    //  LEAVE MANAGEMENT — BALANCES
    // ====================================================================

    /**
     * GET — Get leave balances for a year (defaults to current calendar year).
     * Optional: ?year=2026&staff_id=STAFF001
     */
    public function get_leave_balances()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_leave_balances', 'HR', 'view');

        $year    = trim($this->input->get('year') ?? date('Y'));
        $staffId = trim($this->input->get('staff_id') ?? '');

        if (!preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid year format.');
        }

        // H10 (privilege): non-HR viewers (e.g. Teacher) may only read their
        // OWN balances. Force staff_id to the caller's own id ($this->admin_id
        // is the raw uid = staffId) and ignore any requested staff_id.
        //
        // P0-1/D2 (HRMS alignment): the legacy `|| in_array($this->admin_role,
        // HR_ROLES)` re-grant was REMOVED. It defeated the graded map — a user
        // whose role NAME is in HR_ROLES (Admin/Principal/HR Manager) but who
        // has been deliberately graded down to HR:view regained cross-staff
        // reads. The graded capability is now the sole authority here, matching
        // _require_role()'s own graded-authoritative contract and the rules
        // layer, which already dropped the bare 'Principal' label (audit C3).
        // Bypass roles (Super Admin / School Super Admin) are unaffected —
        // has_permission() short-circuits true for them.
        $isHr = (function_exists('has_permission') && has_permission('HR', 'edit'));
        if (!$isHr) {
            $staffId = (string) ($this->admin_id ?? '');
            if ($staffId === '') {
                $this->json_error('You are not permitted to view these balances.');
            }
        }

        // Current active leave types — the LIVE single source of truth for
        // `allocated`. Derived into every balance below so the view always shows
        // the current entitlement, not the value snapshotted at allocation time.
        $activeTypes = $this->_activeLeaveTypes();

        if ($staffId !== '') {
            $staffId = $this->safe_path_segment($staffId, 'staff_id');
            // Single staff — stored snapshot merged with live leave-type config.
            $stored    = $this->_fsGetLeaveBalance($staffId, $year);
            $stored    = is_array($stored) ? $stored : [];
            $staffMeta = $this->_fsGetStaffProfile($staffId);
            // Phase 2 — resolve against the END of the requested calendar
            // year (Phase 1's balance-doc key is still the raw calendar
            // year, not yet cut over to a session label — see
            // Leave_year_service's docblock), so a mid-year policy edit is
            // reflected for the year it actually applies to.
            $balances  = $this->_deriveLiveBalances($stored, $activeTypes, is_array($staffMeta) ? $staffMeta : null, "{$year}-12-31");
            $this->json_success([
                'balances' => $balances,
                'staff_id' => $staffId,
                'year'     => $year,
            ]);
            return;
        }

        // All staff balances — Firestore-first via helper
        $result = $this->_fsGetLeaveBalance('', $year);
        if (!is_array($result)) $result = [];

        // Union of staff who have a stored BAL doc + the whole active-staff roster
        // so un-allocated staff still show their full live entitlement.
        $roster   = $this->_activeStaffRoster();
        $staffIds = array_unique(array_merge(array_keys($result), array_keys($roster)));

        $derived = [];
        foreach ($staffIds as $sid) {
            $stored = is_array($result[$sid] ?? null) ? $result[$sid] : [];
            $meta   = is_array($roster[$sid] ?? null) ? $roster[$sid] : null;
            $derived[$sid] = $this->_deriveLiveBalances($stored, $activeTypes, $meta, "{$year}-12-31");
        }

        // Leave type names — include ALL types (active + any stored-inactive) so
        // every rendered column has a header.
        $typeNames = [];
        $allTypes = $this->_fsGetLeaveType('');
        if (is_array($allTypes)) {
            foreach ($allTypes as $tid => $lt) {
                if (is_array($lt) && isset($lt['name'])) {
                    $typeNames[$tid] = $lt['name'];
                }
            }
        }

        $this->json_success(['balances' => $derived, 'types' => $typeNames, 'year' => $year]);
    }

    /**
     * POST — Allocate leave balances for all staff in the session roster.
     * Creates/updates balance records for each active leave type.
     * Params: year (calendar year e.g. '2026')
     */
    public function allocate_leave_balances()
    {
        $this->_require_role(self::HR_ROLES, 'allocate_leave', 'HR', 'edit');

        $year = trim($this->input->post('year') ?? date('Y'));
        if (!preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid year format.');
        }

        // Get all active leave types — Firestore-first via helper
        $leaveTypes = $this->_fsGetLeaveType('');
        $activeTypes = [];
        if (is_array($leaveTypes)) {
            foreach ($leaveTypes as $tid => $lt) {
                if (isset($lt['status']) && $lt['status'] === 'Active') {
                    $activeTypes[$tid] = $lt;
                }
            }
        }

        if (empty($activeTypes)) {
            $this->json_error('No active leave types found. Please create leave types first.');
        }

        // Get staff — Firestore-first
        $roster = [];
        try {
            $fsDocs = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            if (!empty($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? '';
                    if ($sid !== '') $roster[$sid] = $d;
                }
            }
        } catch (\Exception $e) {}
        // No RTDB fallback — Firestore is the only source of truth
        if (empty($roster)) {
            $this->json_error('No staff found. Add staff first.');
        }

        // Determine previous year for carry forward — Firestore-first via helper
        $prevYear = (string) ((int) $year - 1);
        $prevBalances = $this->_fsGetLeaveBalance('', $prevYear);
        if (!is_array($prevBalances)) $prevBalances = [];

        // Reconcile: scan all leave requests — Firestore-first via helper
        // (filter Approved in the loop below)
        $allRequests = $this->_fsGetAllLeaveRequests();
        $usedMap = []; // usedMap[staffId][typeId] = total days used
        if (is_array($allRequests)) {
            foreach ($allRequests as $rid => $req) {
                if (!is_array($req)) continue;
                // H1: read status case-insensitively (legacy CapitalCase + lowercase).
                if (strtolower((string) ($req['status'] ?? '')) !== 'approved') continue;
                $reqYear = date('Y', strtotime($req['from_date'] ?? ''));
                if ($reqYear !== $year) continue;
                $sid = $req['staff_id'] ?? '';
                $tid = $req['type_id'] ?? '';
                if ($sid === '' || $tid === '') continue;
                // FRACTION SAFETY (2026-08-19). This was `(int)`, and `days` is
                // provably fractional: a half-day request is 0.5, and under
                // Calendar Half-Day the calendar-aware count makes a FULL-day
                // request on such a day 0.5 as well (a multi-day span crossing
                // one yields e.g. 2.5). Truncating here meant a half-day leave
                // consumed ZERO balance in the reconciler that this very loop
                // calls the authoritative source — so the entitlement could be
                // taken repeatedly without ever being debited.
                //
                // Integer-preserving on purpose: a whole-day value is added as
                // an int, so a tenant with no fractional leave keeps integer
                // `used` and `balance` values and its stored documents do not
                // change type. Same precedent as the leave counters in payroll.
                $d = (float) ($req['days'] ?? 0);
                if (!isset($usedMap[$sid])) $usedMap[$sid] = [];
                if (!isset($usedMap[$sid][$tid])) $usedMap[$sid][$tid] = 0;
                $usedMap[$sid][$tid] += ($d == floor($d)) ? (int) $d : $d;
            }
        }

        $staffCount = 0;

        foreach ($roster as $staffId => $rosterData) {
            // Accumulate ALL types for this staff, then write ONCE
            $allBalances = [];
            foreach ($activeTypes as $typeId => $lt) {
                $allocated = (int) ($lt['days_per_year'] ?? 0);
                $carried = 0;

                // Carry forward from previous year if enabled
                if (!empty($lt['carry_forward'])) {
                    $prevBal = 0;
                    if (is_array($prevBalances) && isset($prevBalances[$staffId][$typeId]['balance'])) {
                        // Also fraction-safe: last year's closing balance is
                        // fractional whenever a half-day was taken, and an (int)
                        // here silently confiscated the half day on carry-forward.
                        $pb = (float) $prevBalances[$staffId][$typeId]['balance'];
                        $prevBal = ($pb == floor($pb)) ? (int) $pb : $pb;
                    }
                    // SEMANTICS UNIFIED (2026-08-20). This path treated
                    // max_carry = 0 as UNLIMITED — an undocumented magic value
                    // with no affordance in the UI, whose field is labelled
                    // plainly "Max Carry Days". preview_leave_year_closure()
                    // computes `min($unused, $maxCarry)`, i.e. 0 means ZERO
                    // days, and it is the newer effective-dated engine. The two
                    // therefore produced OPPOSITE entitlement from one config:
                    // closure forfeited the whole balance while this reconciler
                    // carried all of it forward.
                    //
                    // Unified on the closure semantics: 0 means zero. Census
                    // 2026-08-20 across all tenants found 13 leave types, 1 with
                    // carry_forward enabled and none with max_carry = 0, so no
                    // employee's balance changes today — this closes the trap
                    // before the first tenant sets 0 and silently gets unlimited
                    // carry-forward.
                    //
                    // Fraction-safe and integer-preserving for the same reason as
                    // $prevBal above: a whole-number cap stays an int so untouched
                    // tenants keep integer balances.
                    $mc       = (float) ($lt['max_carry'] ?? 0);
                    $maxCarry = ($mc == floor($mc)) ? (int) $mc : $mc;
                    $carried  = min($prevBal, $maxCarry);
                    if ($carried < 0) $carried = 0;
                }

                // Reconcile used count from approved requests (authoritative source)
                $used = $usedMap[$staffId][$typeId] ?? 0;

                $balance = $allocated + $carried - $used;

                $allBalances[$typeId] = [
                    'allocated' => $allocated,
                    'used'      => $used,
                    'carried'   => $carried,
                    'balance'   => $balance,
                ];

            }

            // Firestore: write ALL types at once (atomic CAS + floor via helper).
            if ($this->_fsSyncLeaveBalance($staffId, $year, $allBalances)) {
                $staffCount++;
            } else {
                log_message('error', "HR allocate_leave_balances: balance write failed for {$staffId}/{$year}");
            }
        }

        // Audit (MEDIUM #9): allocation is a balance-affecting action.
        $this->_log_leave_audit([
            'action'      => 'leave_balances_allocated',
            'year'        => $year,
            'staff_count' => $staffCount,
            'type_count'  => count($activeTypes),
        ]);

        $this->json_success([
            'message'     => "Leave balances allocated for {$staffCount} staff members across " . count($activeTypes) . " leave types.",
            'staff_count' => $staffCount,
            'type_count'  => count($activeTypes),
            'year'        => $year,
        ]);
    }

    // ====================================================================
    //  LEAVE MANAGEMENT — REQUESTS
    // ====================================================================

    /**
     * GET — List leave requests. Optional filters: ?status=Pending&staff_id=XXX
     */
    public function get_leave_requests()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_leave_requests', 'HR', 'view');

        $filterStatus  = trim($this->input->get('status') ?? '');
        $filterStaffId = trim($this->input->get('staff_id') ?? '');

        // H10 (privilege): non-HR viewers (e.g. Teacher) may only list their OWN
        // requests. Force the staff filter to the caller's own id regardless of
        // any requested staff_id. Holders of HR:edit keep cross-staff visibility.
        //
        // P0-1/D3 (HRMS alignment): legacy role-name re-grant REMOVED — see the
        // rationale on get_leave_balances(). Graded capability is the sole
        // authority; a name-matched but graded-down caller is now clamped to self.
        $isHr = (function_exists('has_permission') && has_permission('HR', 'edit'));
        if (!$isHr) {
            $filterStaffId = (string) ($this->admin_id ?? '');
            if ($filterStaffId === '') {
                $this->json_success(['leave_requests' => []]);
                return;
            }
        }

        $list = [];

        // 1. Firestore FIRST — query BOTH HR-created (type=request) AND
        //    Teacher-app-created (applicantType=staff) leave docs.
        // #12: bound each query with a hard limit. NOTE: true cursor pagination
        // is a TODO — LEAVE_QUERY_LIMIT is a safety cap, not a paged reader.
        try {
            // Query 1: HR-format docs (type=request)
            $conditions1 = [['type', '==', 'request']];
            if ($filterStatus !== '') $conditions1[] = ['status', '==', $filterStatus];
            if ($filterStaffId !== '') $conditions1[] = ['staffId', '==', $filterStaffId];
            $fsDocs1 = $this->fs->schoolWhere('leaveApplications', $conditions1, null, 'ASC', self::LEAVE_QUERY_LIMIT);

            // Query 2: Teacher-app-format docs (applicantType=staff)
            $conditions2 = [['applicantType', '==', 'staff']];
            if ($filterStatus !== '') $conditions2[] = ['status', '==', $filterStatus];
            if ($filterStaffId !== '') $conditions2[] = ['applicantId', '==', $filterStaffId];
            $fsDocs2 = $this->fs->schoolWhere('leaveApplications', $conditions2, null, 'ASC', self::LEAVE_QUERY_LIMIT);

            $allDocs = array_merge($fsDocs1 ?: [], $fsDocs2 ?: []);

            // De-duplicate by doc ID
            $seen = [];
            foreach ($allDocs as $doc) {
                $docId = $doc['id'] ?? '';
                if ($docId === '' || isset($seen[$docId])) continue;
                $seen[$docId] = true;

                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $d['id'] = $d['requestId'] ?? $docId;

                // Normalize Teacher-app format → HR format for consistent UI rendering
                if (!isset($d['staff_id'])) {
                    $d['staff_id'] = $d['staffId'] ?? $d['applicantId'] ?? '';
                }
                if (!isset($d['staff_name'])) {
                    $d['staff_name'] = $d['staffName'] ?? $d['applicantName'] ?? '';
                }
                if (!isset($d['type_code'])) {
                    $d['type_code'] = $d['typeCode'] ?? $d['leaveType'] ?? '';
                }
                if (!isset($d['type_name'])) {
                    $d['type_name'] = $d['typeName'] ?? $d['leaveType'] ?? '';
                }
                if (!isset($d['from_date'])) {
                    $d['from_date'] = $d['fromDate'] ?? $d['startDate'] ?? '';
                }
                if (!isset($d['to_date'])) {
                    $d['to_date'] = $d['toDate'] ?? $d['endDate'] ?? '';
                }
                if (!isset($d['applied_on'])) {
                    $d['applied_on'] = $d['appliedOn'] ?? $d['appliedAt'] ?? '';
                }
                if (!isset($d['decided_by'])) $d['decided_by'] = $d['decidedBy'] ?? $d['approvedBy'] ?? '';
                if (!isset($d['decided_on'])) $d['decided_on'] = $d['decidedOn'] ?? $d['approvedAt'] ?? '';
                if (!isset($d['paid_days'])) $d['paid_days'] = $d['paidDays'] ?? 0;
                if (!isset($d['lwp_days'])) $d['lwp_days'] = $d['lwpDays'] ?? 0;
                if (!isset($d['days'])) $d['days'] = $d['numberOfDays'] ?? 1;

                $list[] = $d;
            }

            if (!empty($list)) {
                usort($list, function ($a, $b) {
                    return strcmp($b['from_date'] ?? '', $a['from_date'] ?? '');
                });
                $this->json_success(['leave_requests' => $list]);
                return;
            }
        } catch (\Exception $e) {
            log_message('error', "HR get_leave_requests: Firestore read failed: " . $e->getMessage());
        }

        // No RTDB fallback — Firestore is the only source
        // If Firestore returned empty, that's the truth — no stale RTDB data
        usort($list, function ($a, $b) {
            return strcmp($b['from_date'] ?? '', $a['from_date'] ?? '');
        });

        $this->json_success(['leave_requests' => $list]);
    }

    /**
     * POST — Apply for leave (by or on behalf of a staff member).
     * Params: staff_id, type_id, from_date, to_date, reason
     */
    public function apply_leave()
    {
        $this->_require_role(self::VIEW_ROLES, 'apply_leave', 'HR', 'view');

        $staffId  = $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id');
        $typeId   = $this->safe_path_segment(trim($this->input->post('type_id') ?? ''), 'type_id');
        $fromDate = trim($this->input->post('from_date') ?? '');
        $toDate   = trim($this->input->post('to_date') ?? '');
        $reason   = trim($this->input->post('reason') ?? '');

        // H10 (privilege): a non-HR caller (e.g. Teacher) may only apply for
        // THEMSELVES. Force staff_id to the caller's own id ($this->admin_id is
        // the raw uid = staffId); only HR:edit holders may apply on behalf of others.
        //
        // P0-1/D4 (HRMS alignment): legacy role-name re-grant REMOVED — see the
        // rationale on get_leave_balances(). This was the highest-integrity of the
        // four: it let a name-matched but graded-down caller file leave ON BEHALF
        // OF another employee. Graded capability is now the sole authority.
        if (!(function_exists('has_permission') && has_permission('HR', 'edit'))) {
            $ownId = (string) ($this->admin_id ?? '');
            if ($ownId === '') {
                $this->json_error('You are not permitted to apply leave for another staff member.');
            }
            $staffId = $ownId;
        }

        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            $this->json_error('From date must be in YYYY-MM-DD format.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $this->json_error('To date must be in YYYY-MM-DD format.');
        }
        if ($toDate < $fromDate) {
            $this->json_error('To date cannot be before from date.');
        }

        // Half-day support — stored on the leave doc; payroll prorates later.
        // Half-day leave does NOT mark attendance 'L' (the staff worked the
        // other half), so we mix it into the leave system only.
        $halfDay = filter_var($this->input->post('half_day'), FILTER_VALIDATE_BOOLEAN);
        $halfDayPeriod = strtoupper(trim((string) ($this->input->post('half_day_period') ?? '')));
        if (!in_array($halfDayPeriod, ['AM', 'PM'], true)) $halfDayPeriod = '';
        if ($halfDay) {
            if ($fromDate !== $toDate) {
                $this->json_error('Half-day leave must be for a single day.');
            }
            if ($halfDayPeriod === '') $halfDayPeriod = 'AM';   // default to morning
        }

        // Calculate days. #12: cap the span to guard against unbounded ranges.
        $from = new DateTime($fromDate);
        $to   = new DateTime($toDate);
        $spanDays = (int) $from->diff($to)->days + 1;   // inclusive calendar span
        if ($spanDays > self::LEAVE_MAX_SPAN_DAYS) {
            $this->json_error('Leave span cannot exceed ' . self::LEAVE_MAX_SPAN_DAYS . ' days.');
        }

        // MEDIUM (#7): a half-day counts as 0.5 (matches payroll's 0.5 LWP and
        // the 0.5 balance deduction below); a full-day range's chargeable
        // count is calendar-resolved below once the staff profile (and so
        // department) is known.
        $days = $halfDay ? 0.5 : (float) $spanDays;
        $calendarAware = false;

        // Verify leave type — Firestore-first via helper
        $leaveType = $this->_fsGetLeaveType($typeId);
        if (!is_array($leaveType)) {
            $this->json_error('Leave type not found.');
        }
        if (isset($leaveType['status']) && $leaveType['status'] !== 'Active') {
            $this->json_error('This leave type is inactive.');
        }

        // Verify staff exists in Firestore
        $staffProfile = null;
        try {
            $fsDocs = $this->fs->schoolWhere('staff', [['staffId', '==', $staffId]]);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                $staffProfile = is_array($fsDocs[0]['data'] ?? null) ? $fsDocs[0]['data'] : $fsDocs[0];
            }
        } catch (\Exception $e) {}
        if (!is_array($staffProfile)) {
            $this->json_error('Staff member not found.');
        }

        // Phase HR-1 — block when staff is Inactive (case-insensitive + trim).
        // Reads camelCase first, falls back to PascalCase Status for legacy docs.
        $rawStatus = (string) ($staffProfile['status'] ?? $staffProfile['Status'] ?? 'Active');
        if (strtolower(trim($rawStatus)) !== 'active') {
            $this->json_error('Cannot perform this action for an inactive staff member.');
        }

        $staffName  = $staffProfile['Name'] ?? $staffProfile['name'] ?? $staffId;
        $department = (string) ($staffProfile['Department'] ?? $staffProfile['department'] ?? '');

        // Leave Policy Programme Phase 5 (2026-08-18) — waiting period.
        // Evaluated at APPLICATION time (not decision time — an applicant
        // shouldn't be able to queue a request they were never eligible to
        // file in the first place). OFF (default): no eligibility delay,
        // byte-identical to before this phase. Composes with Phase 2 —
        // resolves waiting_period_days from whichever version governs
        // $fromDate when effective-dating is also live.
        if ($this->_leave_flag('LEAVE_WAITING_PERIOD_ENABLED')) {
            $waitingDays = (int) ($leaveType['waiting_period_days'] ?? 0);
            if ($this->_leave_flag('LEAVE_EFFECTIVE_DATING_ENABLED')) {
                require_once APPPATH . 'libraries/Leave_policy_service.php';
                $ver = \Leave_policy_service::effectiveVersion($leaveType, $fromDate);
                if ($ver !== null) $waitingDays = (int) ($ver['waiting_period_days'] ?? 0);
            }
            if ($waitingDays > 0) {
                $joinDateRaw = $staffProfile['joinDate'] ?? $staffProfile['dateOfJoining']
                    ?? $staffProfile['Date Of Joining'] ?? $staffProfile['Joining Date'] ?? null;
                $joinDate = ($joinDateRaw !== null) ? $this->_normalize_date((string) $joinDateRaw, 'apply_leave.joinDate') : null;
                if ($joinDate !== null && $joinDate !== '') {
                    $eligibleFrom = date('Y-m-d', strtotime("{$joinDate} +{$waitingDays} days"));
                    if ($fromDate < $eligibleFrom) {
                        $this->json_error(
                            "This leave type has a {$waitingDays}-day waiting period from the join date. "
                            . "Eligible from {$eligibleFrom}."
                        );
                    }
                }
                // Unknown/unparseable join date → fail OPEN (no block) —
                // the eligibility gate must never punish missing profile
                // data by silently refusing every application; a missing
                // join date is a data-quality issue for HR to fix, not
                // grounds to block leave filing.
            }
        }

        // Phase F (2026-08-18) — calendar-aware chargeable-day count for a
        // full-day request. A leave request no longer deducts weekly-offs/
        // holidays that fall inside its span; only actual working days are
        // chargeable, using the SAME resolver Payroll's LOP already uses.
        // Half-day stays the pre-existing fixed 0.5 — untouched, separate
        // concept. Fails open to the raw calendar span (pre-existing
        // behaviour) when the employee's work-schedule calendar cannot be
        // resolved — filing leave must never hard-block on a payroll-config
        // gap.
        if (!$halfDay) {
            try {
                $this->load->library('holiday_service');
                $this->holiday_service->init($this->fs, (string) $this->school_id, (string) $this->session_year);
            } catch (\Throwable $e) {
                log_message('error', 'apply_leave: holiday_service load failed: ' . $e->getMessage());
            }
            $chargeable = $this->_count_chargeable_leave_days($staffId, $department, $fromDate, $toDate);
            $days = $chargeable['days'];
            $calendarAware = $chargeable['calendarAware'];
        }

        // Determine if leave type is paid — paid leaves never become LWP
        $isPaidType = filter_var($leaveType['paid'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $leaveCode  = strtoupper(trim($leaveType['code'] ?? ''));
        log_message('debug', "apply_leave: type_id={$typeId}, code={$leaveCode}, paid=" . ($isPaidType ? 'YES' : 'NO') . ", days={$days}, staff={$staffId}");

        // Check leave balance — Firestore-first via helper.
        // Comp-Off is deliberately NOT read from the standard allocated/
        // carried/used balance doc (its days_per_year is 0 by design — see
        // the COMP-OFF LEDGER section) — its balance comes from the earned
        // credit ledger instead.
        $year = date('Y', strtotime($fromDate));
        $currentBalance = 0.0;
        if ($leaveCode === self::COMP_OFF_TYPE_CODE) {
            $currentBalance = $this->_comp_off_available_balance($staffId);
        } else {
            $balance = $this->_fsGetLeaveBalance($staffId, $year, $typeId);
            if (is_array($balance) && isset($balance['balance'])) {
                // Balances may be fractional (0.5) after half-day deductions — use float.
                $currentBalance = max(0.0, (float) $balance['balance']);
                // Monthly accrual (per-school opt-in, 2026-09-19): a type that
                // accrues monthly can only be taken up to what has ACCRUED so
                // far, not the full annual the stored balance still reflects.
                // Cap here; any excess still degrades to LWP below (never hard-
                // blocked). Default (no accrual) leaves currentBalance untouched,
                // so every existing and brand-new school is byte-identical.
                if ($this->_type_accrues($leaveType, "{$year}-12-31")) {
                    $accrualAvail = max(0.0,
                        (float) ($balance['accruedToDate'] ?? 0)
                        + (float) ($balance['carried'] ?? 0)
                        - (float) ($balance['used'] ?? 0));
                    $currentBalance = min($currentBalance, $accrualAvail);
                }
            }
        }

        if (!$isPaidType) {
            // Unpaid leave type (e.g. LWP) — ALL days are LWP by definition
            $paidDays = 0;
            $lwpDays  = $days;
        } elseif ($currentBalance >= $days) {
            // Paid type with sufficient balance — fully paid
            $paidDays = $days;
            $lwpDays  = 0;
        } else {
            // Paid type but balance exhausted — excess becomes LWP
            $paidDays = $currentBalance;
            $lwpDays  = $days - $paidDays;
        }
        $lwpWarning = '';

        // Check for overlapping leave requests — Firestore-first via helper
        $existingReqs = $this->_fsGetAllLeaveRequests($staffId);
        if (!empty($existingReqs)) {
            foreach ($existingReqs as $rid => $er) {
                if (
                    isset($er['staff_id']) && $er['staff_id'] === $staffId &&
                    // H1: read status case-insensitively (legacy + lowercase).
                    in_array(strtolower((string) ($er['status'] ?? '')), ['pending', 'approved'], true)
                ) {
                    $erFrom = $er['from_date'] ?? '';
                    $erTo   = $er['to_date'] ?? '';
                    // Check overlap: two date ranges overlap when start1 <= end2 AND start2 <= end1
                    if ($fromDate <= $erTo && $toDate >= $erFrom) {
                        $this->json_error("Overlapping leave request exists ({$erFrom} to {$erTo}).");
                    }
                }
            }
        }

        $reqId = $this->_next_id('LR', 'LeaveRequest');

        if ($lwpDays > 0) {
            $lwpWarning = "Warning: {$lwpDays} day(s) will be treated as Leave Without Pay (balance: {$currentBalance}).";
        }

        // Build human-readable calculation reason
        $calSuffix = ($calendarAware && !$halfDay && $days != (float) $spanDays)
            ? " [{$days} working day(s) of {$spanDays} calendar day(s) — weekly-offs/holidays excluded]"
            : '';
        if (!$isPaidType) {
            $calcReason = "Unpaid leave type ({$leaveCode}) — all {$days} day(s) are LWP by definition" . $calSuffix;
            $payLabel   = 'Unpaid Leave (LWP)';
        } elseif ($lwpDays === 0) {
            $calcReason = "Paid leave ({$leaveCode}), balance sufficient ({$currentBalance} available)" . $calSuffix;
            $payLabel   = 'Fully Paid';
        } else {
            $calcReason = "Paid leave ({$leaveCode}) but balance exhausted — {$paidDays} paid (balance: {$currentBalance}), {$lwpDays} day(s) as LWP" . $calSuffix;
            $payLabel   = 'Partially Paid (Exceeds Balance)';
        }

        $data = [
            'staff_id'            => $staffId,
            'staff_name'          => $staffName,
            'type_id'             => $typeId,
            'type_name'           => $leaveType['name'] ?? '',
            'type_code'           => $leaveCode,
            'type_paid'           => $isPaidType,
            'from_date'           => $fromDate,
            'to_date'             => $toDate,
            'days'                => $days,
            'calendar_days'       => (float) $spanDays,
            'calendar_aware'      => $calendarAware,
            'paid_days'           => $paidDays,
            'lwp_days'            => $lwpDays,
            'half_day'            => $halfDay,
            'half_day_period'     => $halfDayPeriod,
            'pay_label'           => $payLabel,
            'calculation_reason'  => $calcReason,
            'balance_at_apply'    => $currentBalance,
            'reason'              => $reason,
            'status'              => 'pending',   // H1: lowercase status enum
            'applied_on'          => date('c'),
            'decided_by'          => '',
            'decided_on'          => '',
            'remarks'             => '',
        ];

        // 1. Firestore FIRST — write leave request
        try {
            $fsReqData = array_merge($data, [
                'schoolId'  => $this->school_id,
                'requestId' => $reqId,
                'type'      => 'request',
                'staffId'   => $staffId,
                'staffName' => $staffName,
                // Canonical apps-facing parity fields (contract §3): the Teacher
                // app "My Leave" history queries applicantId + applicantType=="staff"
                // and renders startDate/endDate/appliedAt. Without these, an
                // HR-filed staff leave is invisible in the applicant's own app.
                // Dual-written alongside the legacy staffId/fromDate/toDate.
                'applicantId'   => $staffId,
                'applicantType' => 'staff',
                'applicantName' => $staffName,
                'startDate'     => $fromDate,
                'endDate'       => $toDate,
                'appliedAt'     => $data['applied_on'],
                'typeId'    => $typeId,
                'typeName'  => $leaveType['name'] ?? '',
                'typeCode'  => $leaveCode,
                'typePaid'  => $isPaidType,
                'fromDate'  => $fromDate,
                'toDate'    => $toDate,
                'numberOfDays' => $days,   // #7: canonical field; 0.5 for half-day
                'paidDays'  => $paidDays,
                'lwpDays'   => $lwpDays,
                'halfDay'        => $halfDay,
                'halfDayPeriod'  => $halfDayPeriod,
                'payLabel'  => $payLabel,
                'calculationReason' => $calcReason,
                'balanceAtApply'    => $currentBalance,
                'appliedOn' => $data['applied_on'],
                'decidedBy' => '',
                'decidedOn' => '',
                'updatedAt' => date('c'),
            ]);
            $ok = $this->fs->set('leaveApplications', $this->fs->docId("LR_{$reqId}"), $fsReqData, true);
        } catch (\Exception $e) {
            log_message('error', "HR: apply_leave -> Firestore FAILED for {$reqId}: " . $e->getMessage());
            $ok = false;
        }
        // H4: never report success on a failed write. No balance was mutated at
        // apply time (deduction happens at decide), so a failed write leaves no
        // stranded state — the only cost is a consumed sequence id (harmless gap).
        if (!$ok) {
            $this->json_error('Could not submit the leave request. Please try again.');
        }

        // Audit log
        $this->_log_leave_audit([
            'action'           => 'leave_applied',
            'leave_request_id' => $reqId,
            'staff_id'         => $staffId,
            'staff_name'       => $staffName,
            'leave_type'       => $leaveCode,
            'leave_type_id'    => $typeId,
            'paid_flag'        => $isPaidType,
            'total_days'       => $days,
            'paid_days'        => $paidDays,
            'lwp_days'         => $lwpDays,
            'balance_before'   => $currentBalance,
            'decision_reason'  => $calcReason,
        ]);

        $msg = "Leave request submitted for {$days} day(s).";
        if ($lwpWarning !== '') {
            $msg .= ' ' . $lwpWarning;
        }
        $this->json_success(['id' => $reqId, 'message' => $msg, 'lwp_days' => $lwpDays, 'lwp_warning' => $lwpWarning]);
    }

    /**
     * POST — Approve or reject a leave request.
     * Params: id, decision (Approved|Rejected), remarks (optional)
     */
    public function decide_leave()
    {
        $this->_require_role(self::HR_ROLES, 'decide_leave', 'HR', 'edit');

        $id       = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');
        $decision = trim($this->input->post('decision') ?? '');
        $remarks  = trim($this->input->post('remarks') ?? '');

        if (!in_array($decision, ['Approved', 'Rejected'], true)) {
            $this->json_error('Decision must be Approved or Rejected.');
        }

        // Read request — Firestore-first via helper (normalizes field names)
        $request = $this->_fsGetLeaveRequest($id);
        if (!is_array($request)) {
            $this->json_error('Leave request not found.');
        }
        $fsDocId = $request['_fsDocId'] ?? '';

        // CRITICAL C3 — cross-tenant IDOR guard: the resolved request MUST belong
        // to the caller's own school. Reject BEFORE any mutation. ($this->school_id
        // is the session's SCH_XXXXXX id.)
        if ((string) ($request['schoolId'] ?? '') !== (string) $this->school_id) {
            $this->json_error('Leave request not found.');
        }

        // MEDIUM #11 — no self-approval: the approver may not decide their own
        // leave. ($this->admin_id is the raw uid = the applicant's staffId.)
        $applicantId = (string) ($request['staff_id'] ?? $request['applicantId'] ?? '');
        if ($applicantId !== '' && $applicantId === (string) ($this->admin_id ?? '')) {
            $this->json_error('You cannot approve or reject your own leave request.');
        }

        $currentStatus = strtolower($request['status'] ?? '');
        if ($currentStatus !== 'pending') {
            $this->json_error('Only pending requests can be decided.');
        }

        // MEDIUM #8 — overlap re-check at DECIDE time (closes the TOCTOU where two
        // separately-non-overlapping pendings become overlapping before approval).
        // Only relevant when approving.
        if ($decision === 'Approved' && $applicantId !== '') {
            $dFrom = (string) ($request['from_date'] ?? '');
            $dTo   = (string) ($request['to_date'] ?? '');
            $others = $this->_fsGetAllLeaveRequests($applicantId);
            foreach ($others as $oid => $er) {
                if ($oid === $fsDocId) continue;                 // skip self (doc id)
                if (($er['requestId'] ?? '') === ($request['requestId'] ?? '_x_') && ($request['requestId'] ?? '') !== '') continue;
                if (($er['staff_id'] ?? '') !== $applicantId) continue;
                // Only APPROVED others block (another pending doesn't consume days yet).
                if (strtolower((string) ($er['status'] ?? '')) !== 'approved') continue;
                $erFrom = (string) ($er['from_date'] ?? '');
                $erTo   = (string) ($er['to_date'] ?? '');
                if ($dFrom !== '' && $dTo !== '' && $erFrom !== '' && $erTo !== ''
                    && $dFrom <= $erTo && $dTo >= $erFrom) {
                    $this->json_error("Cannot approve — overlaps an already-approved leave ({$erFrom} to {$erTo}).");
                }
            }
        }

        // Past-date sanity guard — prevent decisions on stale requests
        // (start date older than 60 days). HR can still cancel via cancel_leave;
        // this only blocks new approve/reject decisions on ancient leaves.
        if ($decision === 'Approved') {
            $startTs = strtotime((string) ($request['from_date'] ?? ''));
            if ($startTs && (time() - $startTs) > (self::LEAVE_STALE_WINDOW_DAYS * 86400)) {
                $this->json_error('Cannot approve leave whose start date is older than ' . self::LEAVE_STALE_WINDOW_DAYS . ' days.');
            }
        }

        // HIGH H3 — REAL pending→processing transition via compare-and-set.
        // The old "set Processing then re-read" did NOT serialize (two callers
        // both write, both re-read 'Processing', both proceed). Now we CAS on the
        // request doc's server updateTime: only the writer whose precondition
        // holds wins the transition; the loser is told to refresh. If no CAS token
        // is available (Teacher-app doc read via legacy client, no __updateTime),
        // fall back to the best-effort set (documented weaker guarantee).
        $lockToken = (string) ($request['__updateTime'] ?? '');
        $wonLock   = false;
        if ($fsDocId !== '') {
            $client = $this->fs->raw_client();
            if ($lockToken !== '' && $client !== null && method_exists($client, 'commitBatch')) {
                $wonLock = $client->commitBatch([[
                    'op'           => 'set',
                    'collection'   => 'leaveApplications',
                    'docId'        => $fsDocId,
                    'data'         => ['status' => 'processing', 'updatedAt' => date('c')],
                    'merge'        => true,
                    'precondition' => ['updateTime' => $lockToken],   // H1: lowercase
                ]]);
            } else {
                // Weaker fallback (no CAS token) — best-effort lock + re-read.
                try {
                    $this->firebase->firestoreSet('leaveApplications', $fsDocId, ['status' => 'processing', 'updatedAt' => date('c')], true);
                    $recheckDoc = $this->_fsGetLeaveRequest($id);
                    $wonLock = is_array($recheckDoc) && strtolower((string) ($recheckDoc['status'] ?? '')) === 'processing';
                } catch (\Exception $e) { $wonLock = false; }
            }
        }
        if (!$wonLock) {
            $this->json_error('This request is being processed by another user. Please refresh.');
        }

        // Helper to release the lock back to 'pending' if a later step fails
        // (leaves the request RETRYABLE — never stranded in 'processing').
        $revertToPending = function () use ($fsDocId, $id) {
            $docId = $fsDocId !== '' ? $fsDocId : $this->fs->docId("LR_{$id}");
            try {
                $this->fs->update('leaveApplications', $docId, ['status' => 'pending', 'updatedAt' => date('c')]);
            } catch (\Exception $e) {
                log_message('error', "HR decide_leave: failed to revert {$docId} to pending: " . $e->getMessage());
            }
        };

        $staffId  = $request['staff_id'] ?? '';
        $typeId   = $request['type_id'] ?? '';
        $days     = (float) ($request['days'] ?? $request['numberOfDays'] ?? 0);  // #7: fractional for half-day
        $fromDate = $request['from_date'] ?? '';
        $year     = date('Y', strtotime($fromDate));

        // Phase HR-1 — block decisions on requests for now-Inactive staff.
        // Re-checks status at decision time (the staff may have been
        // deactivated between submission and approval).
        if ($staffId !== '') {
            $staffProfile = $this->_fsGetStaffProfile($staffId);
            if (is_array($staffProfile)) {
                $rawStatus = (string) ($staffProfile['status'] ?? $staffProfile['Status'] ?? 'Active');
                if (strtolower(trim($rawStatus)) !== 'active') {
                    $revertToPending();   // release the lock — retryable
                    $this->json_error('Cannot perform this action for an inactive staff member.');
                }
            }
        }

        // Resolve leave type — by ID or by name (Teacher app sends name, HR sends ID)
        $leaveType = null;
        $allTypes = $this->_fsGetLeaveType(''); // get all types
        if (is_array($allTypes)) {
            // Try by ID first
            if ($typeId !== '' && isset($allTypes[$typeId])) {
                $leaveType = $allTypes[$typeId];
            } else {
                // Resolve by name/code match (Teacher-app sends leaveType: "Casual Leave" or "Casual")
                $searchName = strtolower(trim($request['leaveType'] ?? $request['type_code'] ?? $typeId));
                foreach ($allTypes as $tid => $lt) {
                    if (!is_array($lt)) continue;
                    $ltName = strtolower(trim($lt['name'] ?? ''));
                    $ltCode = strtolower(trim($lt['code'] ?? ''));
                    if ($ltName === $searchName || $ltCode === $searchName
                        || strpos($ltName, $searchName) === 0
                        || $searchName === $ltCode) {
                        $leaveType = $lt;
                        $typeId = $tid; // resolved!
                        break;
                    }
                }
            }
        }

        $isPaidType = true; // safe default
        if (is_array($leaveType)) {
            $isPaidType = filter_var($leaveType['paid'] ?? false, FILTER_VALIDATE_BOOLEAN);
            log_message('debug', "decide_leave: type_id={$typeId}, code=" . ($leaveType['code'] ?? '?') . ", paid=" . ($isPaidType ? 'YES' : 'NO') . ", days={$days}, decision={$decision}");
        } else {
            $ltName = $request['leaveType'] ?? $request['type_code'] ?? '?';
            log_message('error', "decide_leave: leave type '{$typeId}' / '{$ltName}' not found — defaulting to paid");
        }

        // If approving, calculate paid/LWP split and deduct from balance.
        // HIGH H3 — the whole read→split→deduct is done inside ONE atomic CAS
        // mutator (fresh read, floored, single conditional write). This removes
        // the previous read-modify-write + post-write "verify negative then roll
        // back" dance, which could not actually serialize concurrent deductions.
        // #7 — half-day $days is 0.5, so the deduction is 0.5 (matches payroll).
        $isCompOff = is_array($leaveType) && strtoupper(trim($leaveType['code'] ?? '')) === self::COMP_OFF_TYPE_CODE;
        $compOffConsumedIds = [];
        $compOffTakes       = [];
        $paidDays = $days;
        $lwpDays  = 0.0;
        if ($decision === 'Approved' && $isCompOff) {
            // Comp-Off never touches the standard allocated/carried/used
            // balance doc — it consumes the earned-credit ledger instead
            // (see the COMP-OFF LEDGER section). Consumption is per-credit
            // CAS-protected already; no outer retry loop needed here.
            $result = $this->_consumeCompOffLedger($staffId, $days);
            $paidDays = $result['paid'];
            $lwpDays  = $result['lwp'];
            $compOffConsumedIds = $result['consumedIds'];
            $compOffTakes       = is_array($result['takes'] ?? null) ? $result['takes'] : [];
        } elseif ($decision === 'Approved') {
            $allocatedForNew = is_array($leaveType) ? (int) ($leaveType['days_per_year'] ?? 0) : 0;
            $capturedPaid = null;
            $capturedLwp  = null;
            $capturedOrig = null;   // pre-deduction snapshot of this type (for exact rollback)

            $balOk = $this->_casLeaveBalance($staffId, $year, function (array $balances)
                use ($typeId, $days, $isPaidType, $allocatedForNew, &$capturedPaid, &$capturedLwp, &$capturedOrig) {

                $cur = is_array($balances[$typeId] ?? null) ? $balances[$typeId] : null;
                $capturedOrig = $cur;   // null => the type had no record before this deduction

                // SINGLE SOURCE OF TRUTH: `allocated` is the CURRENT leave-type
                // days_per_year ($allocatedForNew), NOT a possibly-stale stored
                // snapshot. Effective available = live_allocated + carried - used,
                // so a just-changed allocation is honoured immediately on approval —
                // whether or not a stored record exists for this staff/type.
                $liveAlloc = (float) $allocatedForNew;
                $carried   = $cur !== null ? (float) ($cur['carried'] ?? 0) : 0.0;
                $used      = $cur !== null ? (float) ($cur['used'] ?? 0)    : 0.0;

                if (!$isPaidType) {
                    // Unpaid type — ALL days are LWP, but still tracked in `used`.
                    $capturedPaid = 0.0;
                    $capturedLwp  = $days;
                    $newUsed = $used + $days;
                    $bal     = max(0.0, $liveAlloc + $carried - $newUsed);          // floor at 0
                    $balances[$typeId] = [
                        'allocated' => $allocatedForNew,
                        'used'      => $newUsed,
                        'carried'   => $carried,
                        'balance'   => $bal,
                    ];
                } else {
                    // Paid type — pay from LIVE-derived available balance first,
                    // excess becomes LWP.
                    $effAvail = max(0.0, $liveAlloc + $carried - $used);
                    $paid = min($effAvail, $days);
                    $capturedPaid = $paid;
                    $capturedLwp  = $days - $paid;
                    $newUsed = $used + $paid;
                    $bal     = max(0.0, $liveAlloc + $carried - $newUsed);          // floor at 0
                    $balances[$typeId] = [
                        'allocated' => $allocatedForNew,
                        'used'      => $newUsed,
                        'carried'   => $carried,
                        'balance'   => $bal,
                    ];
                }
                return $balances;
            });

            if (!$balOk) {
                // H4 — balance write failed / lost every CAS attempt. Roll the
                // request lock back to pending (retryable) and surface an error.
                $revertToPending();
                $this->json_error('Leave balance was modified concurrently. Please try again.');
            }

            $paidDays = $capturedPaid !== null ? $capturedPaid : $days;
            $lwpDays  = $capturedLwp  !== null ? $capturedLwp  : 0.0;
        }

        // Build calculation reason for audit trail
        $ltCode = is_array($leaveType) ? strtoupper(trim($leaveType['code'] ?? '')) : '?';
        if ($decision === 'Rejected') {
            $calcReason = 'Leave rejected — no salary impact';
            $payLabel   = 'Rejected';
        } elseif (!$isPaidType) {
            $calcReason = "Unpaid leave type ({$ltCode}) — all {$days} day(s) deducted from salary";
            $payLabel   = 'Unpaid Leave (LWP)';
        } elseif ($lwpDays <= 0) {   // #7: $lwpDays is a float (0.0 for fully-paid)
            $calcReason = "Paid leave ({$ltCode}), fully covered by balance — no salary deduction";
            $payLabel   = 'Fully Paid';
        } else {
            $calcReason = "Paid leave ({$ltCode}) — {$paidDays} covered by balance, {$lwpDays} exceed balance (LWP, salary deducted)";
            $payLabel   = 'Partially Paid (Exceeds Balance)';
        }

        $statusWrite = strtolower($decision);   // H1: 'approved' | 'rejected'
        $updateData = [
            'status'              => $statusWrite,
            'decided_by'          => $this->admin_name,
            'decided_on'          => date('c'),
            'remarks'             => $remarks,
            'paid_days'           => $paidDays,
            'lwp_days'            => $lwpDays,
            'type_code'           => $ltCode,
            'type_paid'           => $isPaidType,
            'pay_label'           => $payLabel,
            'calculation_reason'  => $calcReason,
        ];

        // 1. Firestore FIRST — update leave request decision.
        // H4: check the write RESULT. On failure, roll BACK any balance deduction
        // (exact pre-deduction snapshot) AND release the lock to 'pending' so the
        // request is retryable — never a stranded 'processing' or a phantom deduct.
        $writeOk = false;
        try {
            $fsUpdateData = array_merge($updateData, [
                'decidedBy' => $updateData['decided_by'] ?? '',
                'decidedOn' => $updateData['decided_on'] ?? '',
                'approvedBy' => $updateData['decided_by'] ?? '',
                'paidDays'  => $paidDays,
                'lwpDays'   => $lwpDays,
                'typeCode'  => $ltCode,
                'typePaid'  => $isPaidType,
                'payLabel'  => $payLabel,
                'calculationReason' => $calcReason,
                'updatedAt' => date('c'),
                // Persisted so cancel_leave() can release exactly these
                // credits back to 'available' — never guesses which ones.
                'compOffLedgerIds'   => $compOffConsumedIds,
                // D-001: exact amount taken per credit, so a later cancellation
                // restores precisely that much rather than a whole credit.
                'compOffLedgerTakes' => $compOffTakes,
            ]);
            // Use the actual Firestore doc ID (works for both HR-format and Teacher-app-format)
            $writeDocId = $fsDocId !== '' ? $fsDocId : $this->fs->docId("LR_{$id}");
            $writeOk = $this->fs->set('leaveApplications', $writeDocId, $fsUpdateData, true);
        } catch (\Exception $e) {
            log_message('error', "HR: decide_leave -> Firestore FAILED for {$id}: " . $e->getMessage());
            $writeOk = false;
        }
        if (!$writeOk) {
            // Roll back the balance deduction (approvals only) to its exact prior state.
            if ($decision === 'Approved' && $isCompOff) {
                $this->_releaseCompOffLedger($compOffConsumedIds, $compOffTakes);
            } elseif ($decision === 'Approved') {
                $this->_casLeaveBalance($staffId, $year, function (array $balances) use ($typeId, $capturedOrig) {
                    if ($capturedOrig === null) {
                        unset($balances[$typeId]);           // type had no record before — remove it
                    } else {
                        $balances[$typeId] = $capturedOrig;  // restore exact pre-deduction snapshot
                    }
                    return $balances;
                });
            }
            $revertToPending();
            $this->json_error('Could not record the decision. Please try again.');
        }

        // On approval, automatically mark attendance as "L" (Leave) for each leave day —
        // EXCEPT for half-day leave, which lives only on the leave doc (payroll prorates).
        $isHalfDay = filter_var($request['half_day'] ?? $request['halfDay'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $attendanceWarnings = [];
        if ($decision === 'Approved' && !$isHalfDay) {
            $attendanceWarnings = $this->_apply_leave_to_attendance(
                $staffId,
                $request['from_date'] ?? '',
                $request['to_date'] ?? '',
                $isPaidType ? 'Paid' : 'Unpaid',
                $paidDays,
                $lwpDays,
                (string) $id
            );
        }

        // FCM — notify the staff member about the decision (universal CF dispatcher).
        $fromDate = (string) ($request['from_date'] ?? '');
        $toDate   = (string) ($request['to_date'] ?? '');
        $halfTag  = $isHalfDay ? ' (half-day)' : '';
        $title    = "Leave {$decision}";
        $body     = ($decision === 'Approved')
            ? "Your {$ltCode} leave from {$fromDate} to {$toDate}{$halfTag} has been approved."
            : ("Your {$ltCode} leave was rejected" . ($remarks !== '' ? ": {$remarks}" : '.'));
        $this->emit_push('STAFF_LEAVE_DECIDED', 'staffleave_' . $id . '_' . strtolower((string) $decision), [
            'staffId'   => $staffId,
            'pushType'  => 'leave_' . strtolower((string) $decision),
            'title'     => $title,
            'body'      => $body,
            'leaveId'   => $id,
            'startDate' => $fromDate,
            'endDate'   => $toDate,
        ]);

        // Audit log
        $this->_log_leave_audit([
            'action'           => 'leave_decided',
            'leave_request_id' => $id,
            'staff_id'         => $staffId,
            'staff_name'       => $request['staff_name'] ?? $staffId,
            'leave_type'       => $ltCode,
            'leave_type_id'    => $typeId,
            'paid_flag'        => $isPaidType,
            'total_days'       => $days,
            'paid_days'        => $paidDays,
            'lwp_days'         => $lwpDays,
            'decision'         => $decision,
            'decision_reason'  => $calcReason,
            'remarks'          => $remarks,
        ]);

        $msg = "Leave request {$decision}.";
        if ($decision === 'Approved' && $lwpDays > 0) {
            $msg .= " ({$paidDays} paid, {$lwpDays} LWP)";
        }
        if (!empty($attendanceWarnings)) {
            $msg .= ' Note: ' . implode(' ', $attendanceWarnings);
        }
        $this->json_success(['message' => $msg, 'paid_days' => $paidDays, 'lwp_days' => $lwpDays, 'attendance_warnings' => $attendanceWarnings]);
    }

    /**
     * POST — Cancel a leave request.
     * Params: id
     * If the request was Approved, restores the balance.
     */
    public function cancel_leave()
    {
        $this->_require_role(self::HR_ROLES, 'cancel_leave', 'HR', 'edit');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        // Read request — Firestore-first via helper (normalizes field names)
        $request = $this->_fsGetLeaveRequest($id);
        if (!is_array($request)) {
            $this->json_error('Leave request not found.');
        }

        // CRITICAL C4 — write to the RESOLVED doc id, NOT a hardcoded LR_{id}.
        // App-submitted staff leaves live under their own doc id; the old code
        // locked/cancelled LR_{id} (which didn't exist for those) so the balance
        // was restored while the request stayed 'approved' → repeatable inflation.
        $fsDocId = (string) ($request['_fsDocId'] ?? '');
        if ($fsDocId === '') $fsDocId = $this->fs->docId("LR_{$id}");

        // CRITICAL C3 — cross-tenant IDOR guard: the request MUST belong to the
        // caller's own school. Reject BEFORE any mutation.
        if ((string) ($request['schoolId'] ?? '') !== (string) $this->school_id) {
            $this->json_error('Leave request not found.');
        }

        // H1: read status case-insensitively (legacy CapitalCase + lowercase).
        $currentStatus = strtolower((string) ($request['status'] ?? ''));
        // Idempotency (C4): refuse if already cancelled (or mid-cancel) so a
        // repeated call can never restore the balance a second time.
        if (in_array($currentStatus, ['cancelled', 'cancelling'], true)) {
            $this->json_error('This leave request is already cancelled.');
        }
        if (!in_array($currentStatus, ['pending', 'approved'], true)) {
            $this->json_error('Only Pending or Approved requests can be cancelled.');
        }

        $staffId  = $request['staff_id'] ?? '';
        $typeId   = $request['type_id'] ?? '';
        $days     = (float) ($request['days'] ?? $request['numberOfDays'] ?? 0);   // #7: fractional
        $fromDate = $request['from_date'] ?? '';
        $year     = date('Y', strtotime($fromDate));

        // H3 lock — CAS the request pending/approved → 'cancelling' on the RESOLVED
        // doc id, so only one canceller wins. Weaker fallback if no CAS token.
        $lockToken = (string) ($request['__updateTime'] ?? '');
        $client    = $this->fs->raw_client();
        $wonLock   = false;
        if ($lockToken !== '' && $client !== null && method_exists($client, 'commitBatch')) {
            $wonLock = $client->commitBatch([[
                'op'           => 'set',
                'collection'   => 'leaveApplications',
                'docId'        => $fsDocId,
                'data'         => ['status' => 'cancelling', 'updatedAt' => date('c')],   // H1 lowercase
                'merge'        => true,
                'precondition' => ['updateTime' => $lockToken],
            ]]);
        } else {
            $wonLock = (bool) $this->fs->update('leaveApplications', $fsDocId, ['status' => 'cancelling', 'updatedAt' => date('c')]);
        }
        if (!$wonLock) {
            $this->json_error('This request is being processed by another user. Please refresh.');
        }

        // Revert the 'cancelling' lock to the original status on any later failure
        // (leaves the request retryable — never stranded in 'cancelling').
        $revertLock = function () use ($fsDocId, $currentStatus) {
            try {
                $this->fs->update('leaveApplications', $fsDocId, ['status' => $currentStatus, 'updatedAt' => date('c')]);
            } catch (\Exception $e) {
                log_message('error', "HR cancel_leave: failed to revert {$fsDocId} to {$currentStatus}: " . $e->getMessage());
            }
        };

        // Phase HR-1 — block cancellation on requests for now-Inactive staff.
        if ($staffId !== '') {
            $staffProfile = $this->_fsGetStaffProfile($staffId);
            if (is_array($staffProfile)) {
                $rawStatus = (string) ($staffProfile['status'] ?? $staffProfile['Status'] ?? 'Active');
                if (strtolower(trim($rawStatus)) !== 'active') {
                    $revertLock();
                    $this->json_error('Cannot perform this action for an inactive staff member.');
                }
            }
        }

        // If was Approved, restore balance (paid + LWP both tracked in `used`).
        // H3: atomic CAS restore computed against a FRESH read (no lost update).
        $paidDays = (float) ($request['paid_days'] ?? $days);   // legacy fallback = full days
        $lwpDays  = (float) ($request['lwp_days'] ?? 0);
        $totalUsedDays = $paidDays + $lwpDays;
        if ($totalUsedDays <= 0) $totalUsedDays = $days;        // legacy fallback
        $isCompOffCancel = strtoupper(trim((string) ($request['type_code'] ?? ''))) === self::COMP_OFF_TYPE_CODE;
        if ($currentStatus === 'approved' && $isCompOffCancel) {
            // Release exactly the credits this request consumed — recorded
            // on the request doc at approval time, never re-derived/guessed.
            //
            // D-001: `compOffLedgerTakes` carries how MUCH was taken from each
            // credit, so a partially-spent credit gets back precisely that
            // amount. Requests approved before partial consumption existed
            // carry only the id list; those credits were necessarily spent
            // whole, and the id-only release restores them correctly.
            $ledgerIds = is_array($request['compOffLedgerIds'] ?? null) ? $request['compOffLedgerIds'] : [];
            $ledgerTakes = is_array($request['compOffLedgerTakes'] ?? null) ? $request['compOffLedgerTakes'] : [];
            if (!empty($ledgerIds)) $this->_releaseCompOffLedger($ledgerIds, $ledgerTakes);
        } elseif ($currentStatus === 'approved' && $staffId !== '' && $typeId !== '' && $totalUsedDays > 0) {
            $this->_casLeaveBalance($staffId, $year, function (array $balances) use ($typeId, $totalUsedDays) {
                $cur = is_array($balances[$typeId] ?? null) ? $balances[$typeId] : null;
                if ($cur === null) return $balances;            // nothing to restore
                $newUsed    = max(0.0, (float) ($cur['used'] ?? 0) - $totalUsedDays);
                $newBalance = (float) ($cur['balance'] ?? 0) + $totalUsedDays;
                $balances[$typeId] = array_merge($cur, ['used' => $newUsed, 'balance' => $newBalance]);
                return $balances;
            });
        }

        // Revert attendance marks if the leave was previously approved
        $revertWarnings = [];
        if ($currentStatus === 'approved') {
            $revertWarnings = $this->_revert_leave_from_attendance(
                $staffId,
                $request['from_date'] ?? '',
                $request['to_date'] ?? ''
            );
        }

        // 1. Firestore FIRST — finalize to 'cancelled' (H1) on the RESOLVED doc id.
        // H4: check the write RESULT; on failure revert the lock (retryable).
        $cancelData = [
            'status'     => 'cancelled',   // H1 lowercase
            'decided_by' => $this->admin_name,
            'decided_on' => date('c'),
            'remarks'    => 'Cancelled' . ($currentStatus === 'approved' ? ' (balance restored, attendance reverted)' : ''),
            'decidedBy'  => $this->admin_name,
            'decidedOn'  => date('c'),
            'updatedAt'  => date('c'),
        ];
        $writeOk = false;
        try {
            $writeOk = $this->fs->update('leaveApplications', $fsDocId, $cancelData);
        } catch (\Exception $e) {
            log_message('error', "HR: cancel_leave -> Firestore FAILED for {$fsDocId}: " . $e->getMessage());
            $writeOk = false;
        }
        if (!$writeOk) {
            $revertLock();
            $this->json_error('Could not cancel the leave request. Please try again.');
        }

        // Audit (MEDIUM #9): cancellation is a balance-affecting action.
        $this->_log_leave_audit([
            'action'           => 'leave_cancelled',
            'leave_request_id' => $id,
            'staff_id'         => $staffId,
            'staff_name'       => $request['staff_name'] ?? $staffId,
            'leave_type'       => strtoupper(trim($request['type_code'] ?? '')),
            'leave_type_id'    => $typeId,
            'total_days'       => $days,
            'paid_days'        => $paidDays,
            'lwp_days'         => $lwpDays,
            'prior_status'     => $currentStatus,
            'decision_reason'  => ($currentStatus === 'approved' ? 'balance restored + attendance reverted' : 'pending request withdrawn'),
        ]);

        $cancelMsg = 'Leave request cancelled.' . ($currentStatus === 'approved' ? ' Balance restored, attendance reverted.' : '');
        if (!empty($revertWarnings)) {
            $cancelMsg .= ' Note: ' . implode(' ', $revertWarnings);
        }
        $this->json_success([
            'message' => $cancelMsg,
            'attendance_warnings' => $revertWarnings,
        ]);
    }

    // ====================================================================
    //  LEAVE ↔ ATTENDANCE INTEGRATION
    // ====================================================================

    /**
     * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
     * Phase D §5 follow-up (2026-08-18) — REWRITTEN to close a confirmed,
     * compounding defect: the previous implementation wrote directly via
     * $this->firebase->firestoreSet(), bypassing Staff_attendance_writer
     * entirely — no lock check (could silently write into a payroll-closed
     * month), no CAS on the summary rebuild (a race with a concurrent
     * punch/grid-save/finaliser write could clobber either side), a drifted
     * field name ('vacation' instead of the canonical 'void', with
     * halfDay/extraWorked/weeklyOff never written at all), and an unbounded
     * per-staff query scanning ALL of staffAttendance with no server-side
     * date filter.
     *
     * Now routes through Staff_attendance_writer::markSingleDay() — the
     * SAME canonical writer punch/mark_staff_day/bulk_mark_staff/the grid's
     * whole-month save already use. No second writer created. This also
     * fixes the field-name drift and the unbounded query as a direct
     * consequence (the writer owns the summary shape and reads by doc ID,
     * not a full collection scan).
     *
     * Mark attendance as "L" (Leave) for each day of an approved leave
     * request. Called automatically when leave is approved.
     *
     * A month that is locked (payroll-closed) is deliberately NOT written
     * to — the leave decision itself still succeeds (this function never
     * blocks the caller), but that specific date's attendance is skipped
     * and reported back as a warning string, exactly mirroring the
     * fail-tolerant "warnings[]" pattern payroll already uses elsewhere in
     * this file. Silently corrupting a closed month, or silently blocking
     * an otherwise-valid leave decision because of one unrelated locked
     * date, are both worse than a visible, explicit skip.
     *
     * @param string $staffId    Staff ID
     * @param string $fromDate   Leave start date (YYYY-MM-DD)
     * @param string $toDate     Leave end date (YYYY-MM-DD)
     * @param string $leaveType  "Paid" or "Unpaid"
     * @param int    $paidDays   Number of paid leave days
     * @param int    $lwpDays    Number of unpaid (LWP) leave days
     * @param string $leaveAppId The leave application's own doc/request id, for audit cross-reference
     * @return string[] Warnings for any date that could not be written (locked month / writer failure)
     */
    private function _apply_leave_to_attendance(
        string $staffId,
        string $fromDate,
        string $toDate,
        string $leaveType,
        int $paidDays,
        int $lwpDays,
        string $leaveAppId = ''
    ): array {
        if (!$staffId || !$fromDate || !$toDate) return [];

        $school  = $this->school_name;
        $session = $this->session_year;

        $this->load->library('staff_attendance_writer');
        $this->staff_attendance_writer->init($this->firebase, $this->school_id, $this->session_year);

        $warnings = [];
        $cursor = new DateTime($fromDate);
        $end    = new DateTime($toDate);
        while ($cursor <= $end) {
            $dateISO = $cursor->format('Y-m-d');
            try {
                $ctx = ['markedBy' => 'leave_approval', 'source' => 'leave_approval', 'leaveType' => $leaveType];
                if ($leaveAppId !== '') $ctx['leaveAppId'] = $leaveAppId;
                $this->staff_attendance_writer->markSingleDay($staffId, $dateISO, 'L', $ctx);
            } catch (MonthLockedException $e) {
                $warnings[] = "Attendance for {$dateISO} was not updated — that month is locked.";
            } catch (\Throwable $e) {
                log_message('error', "Hr::_apply_leave_to_attendance writer failed for {$staffId} {$dateISO}: " . $e->getMessage());
                $warnings[] = "Attendance for {$dateISO} could not be updated — please verify manually.";
            }
            $cursor->modify('+1 day');
        }

        // Store leave metadata for attendance cross-reference (Firestore) —
        // unchanged, not part of the bug being fixed here.
        $recordKey = str_replace('-', '', $fromDate) . '_' . str_replace('-', '', $toDate);
        try {
            $metaDocId = "{$school}_{$staffId}_leave_records";
            $existingMeta = $this->firebase->firestoreGet('staffAttendanceMeta', $metaDocId);
            if (!is_array($existingMeta)) $existingMeta = ['schoolId' => $this->school_id, 'staffId' => $staffId, 'session' => $session, 'records' => []];
            $existingMeta['records'][$recordKey] = [
                'from_date'  => $fromDate,
                'to_date'    => $toDate,
                'leave_type' => $leaveType,
                'paid_days'  => $paidDays,
                'lwp_days'   => $lwpDays,
                'marked_at'  => date('c'),
            ];
            $this->firebase->firestoreSet('staffAttendanceMeta', $metaDocId, $existingMeta, true);
        } catch (\Exception $e) {
            log_message('error', "Hr::_apply_leave_to_attendance meta write failed: " . $e->getMessage());
        }

        return $warnings;
    }

    /**
     * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
     * Phase D §5 follow-up (2026-08-18) — REWRITTEN to close two confirmed
     * defects, the second more serious than anything else found in this
     * file's leave↔attendance integration:
     *
     *   1. Same bypass as _apply_leave_to_attendance() — no lock check, no
     *      CAS, direct firestoreDelete() with no audit trail preserved.
     *   2. The previous implementation computed the corrected dayWise
     *      string (in-memory) and then NEVER WROTE IT BACK — only the
     *      per-day staffAttendance doc was deleted and the leave metadata
     *      record removed. staffAttendanceSummary.dayWise was left
     *      permanently stale, still showing 'L' for a cancelled leave.
     *
     * Now routes through Staff_attendance_writer::markSingleDay() and
     * restores each day to its RECORDED previousStatus — the status the
     * writer itself captured on the staffAttendance doc when the leave was
     * originally applied (Staff_attendance_writer::markSingleDay() always
     * stamps 'previousStatus' on the doc it writes). This is a genuine
     * correctness improvement over the pre-fix blind reset-to-'V': if the
     * employee had a real punch/manual mark on that date BEFORE the leave
     * was approved over it, cancelling the leave now restores that mark
     * instead of erasing it to Vacant. A day whose current status is no
     * longer 'L' (e.g. an admin already corrected it since) is left
     * strictly alone — never clobbered by a stale cancellation.
     *
     * Revert attendance marks when a leave is cancelled. Only reverts days
     * currently marked "L".
     *
     * @return string[] Warnings for any date that could not be reverted (locked month / writer failure)
     */
    private function _revert_leave_from_attendance(string $staffId, string $fromDate, string $toDate): array
    {
        if (!$staffId || !$fromDate || !$toDate) return [];

        $school  = $this->school_name;
        $session = $this->session_year;

        $this->load->library('staff_attendance_writer');
        $this->staff_attendance_writer->init($this->firebase, $this->school_id, $this->session_year);

        $warnings = [];
        $cursor = new DateTime($fromDate);
        $end    = new DateTime($toDate);
        while ($cursor <= $end) {
            $dateISO = $cursor->format('Y-m-d');
            $attDocId = "{$this->school_id}_{$dateISO}_{$staffId}";
            try {
                $existing = $this->firebase->firestoreGet('staffAttendance', $attDocId);
                $currentStatus = is_array($existing) ? strtoupper((string) ($existing['status'] ?? '')) : '';
                if ($currentStatus === 'L') {
                    $restoreTo = (is_array($existing) && !empty($existing['previousStatus']))
                        ? strtoupper((string) $existing['previousStatus']) : 'V';
                    if (!in_array($restoreTo, \Staff_attendance_writer::ALLOWED_STATUS, true)) $restoreTo = 'V';
                    $this->staff_attendance_writer->markSingleDay($staffId, $dateISO, $restoreTo, [
                        'markedBy' => 'leave_cancellation',
                        'source'   => 'leave_cancellation',
                    ]);
                }
                // currentStatus !== 'L' → a newer change already exists on this
                // date (manual correction, re-punch, etc.); leave it untouched.
            } catch (MonthLockedException $e) {
                $warnings[] = "Attendance for {$dateISO} was not reverted — that month is locked.";
            } catch (\Throwable $e) {
                log_message('error', "Hr::_revert_leave_from_attendance writer failed for {$staffId} {$dateISO}: " . $e->getMessage());
                $warnings[] = "Attendance for {$dateISO} could not be reverted — please verify manually.";
            }
            $cursor->modify('+1 day');
        }

        // Remove leave metadata record from Firestore — unchanged, not part
        // of the bug being fixed here.
        $recordKey = str_replace('-', '', $fromDate) . '_' . str_replace('-', '', $toDate);
        try {
            $metaDocId = "{$school}_{$staffId}_leave_records";
            $existingMeta = $this->firebase->firestoreGet('staffAttendanceMeta', $metaDocId);
            if (is_array($existingMeta) && is_array($existingMeta['records'] ?? null)) {
                unset($existingMeta['records'][$recordKey]);
                $this->firebase->firestoreSet('staffAttendanceMeta', $metaDocId, $existingMeta, true);
            }
        } catch (\Exception $e) {
            log_message('error', "Hr::_revert_leave_from_attendance meta delete failed: " . $e->getMessage());
        }

        return $warnings;
    }

    // ====================================================================
    //  SALARY STRUCTURES
    // ====================================================================

    /**
     * GET — Get all salary structures or a single one (?staff_id=XXX).
     */
    public function get_salary_structures()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_salary_structures', 'HR', 'manage');

        $staffId = trim($this->input->get('staff_id') ?? '');

        if ($staffId !== '') {
            $staffId = $this->safe_path_segment($staffId, 'staff_id');
            // Firestore first, RTDB fallback
            $structure = null;
            try {
                $fsDoc = $this->fs->get('salarySlips', $this->fs->docId("SAL_{$staffId}"));
                if (is_array($fsDoc) && !empty($fsDoc)) $structure = $fsDoc;
            } catch (\Exception $e) {}
            $this->json_success([
                'salary_structure' => is_array($structure) ? $structure : null,
                'staff_id'         => $staffId,
            ]);
            return;
        }

        // Firestore first for listing, RTDB fallback
        $list = [];
        try {
            $fsDocs = $this->fs->schoolWhere('salarySlips', [['type', '==', 'salary_structure']]);
            if (!empty($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $s = $doc['data'];
                    $s['staff_id'] = $s['staffId'] ?? '';
                    $list[] = $s;
                }
            }
        } catch (\Exception $e) {}

        // Count staff in roster to report coverage (Firestore)
        $roster = [];
        try {
            $fsStaff = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            if (is_array($fsStaff)) {
                foreach ($fsStaff as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? '';
                    if ($sid !== '') $roster[$sid] = $d;
                }
            }
        } catch (\Exception $e) {}
        $rosterCount = is_array($roster) ? count($roster) : 0;
        $coveredIds  = array_map(function($s) { return $s['staff_id'] ?? ($s['staffId'] ?? ''); }, $list);
        $rosterIds   = is_array($roster) ? array_keys($roster) : [];
        $missing     = array_diff($rosterIds, $coveredIds);

        $this->json_success([
            'salary_structures' => $list,
            'coverage' => [
                'total_staff'   => $rosterCount,
                'with_structure' => count($coveredIds),
                'missing'       => count($missing),
                'missing_ids'   => array_values($missing),
            ],
        ]);
    }

    /**
     * POST — Save salary structure for a staff member.
     */
    public function save_salary_structure()
    {
        $this->_require_role(self::ADMIN_ROLES, 'save_salary_structure', 'HR', 'manage');

        $staffId = $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id');

        // Verify staff exists (Firestore)
        $staffProfile = null;
        try {
            $fsDocs = $this->fs->schoolWhere('staff', [['staffId', '==', $staffId]]);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                $staffProfile = is_array($fsDocs[0]['data'] ?? null) ? $fsDocs[0]['data'] : $fsDocs[0];
            }
        } catch (\Exception $e) {}
        if (!is_array($staffProfile)) {
            $this->json_error('Staff member not found.');
        }

        $basic            = (float) ($this->input->post('basic') ?? 0);
        $hra              = (float) ($this->input->post('hra') ?? 0);
        $da               = (float) ($this->input->post('da') ?? 0);
        $ta               = (float) ($this->input->post('ta') ?? 0);
        $medical          = (float) ($this->input->post('medical') ?? 0);
        $otherAllowances  = (float) ($this->input->post('other_allowances') ?? $this->input->post('special_allowance') ?? 0);
        $pfEmployee       = (float) ($this->input->post('pf_employee') ?? 0);
        $pfEmployer       = (float) ($this->input->post('pf_employer') ?? 0);
        $esiEmployee      = (float) ($this->input->post('esi_employee') ?? 0);
        $esiEmployer      = (float) ($this->input->post('esi_employer') ?? 0);
        $professionalTax  = (float) ($this->input->post('professional_tax') ?? 0);
        $tds              = (float) ($this->input->post('tds') ?? 0);
        $otherDeductions  = (float) ($this->input->post('other_deductions') ?? 0);

        if ($basic <= 0) {
            $this->json_error('Basic salary must be greater than zero.');
        }

        $data = [
            'basic'            => $basic,
            'hra'              => $hra,
            'da'               => $da,
            'ta'               => $ta,
            'medical'          => $medical,
            'other_allowances' => $otherAllowances,
            'pf_employee'      => $pfEmployee,
            'pf_employer'      => $pfEmployer,
            'esi_employee'     => $esiEmployee,
            'esi_employer'     => $esiEmployer,
            'professional_tax' => $professionalTax,
            'tds'              => $tds,
            'other_deductions' => $otherDeductions,
            'source'           => 'manual',
            'updated_at'       => date('c'),
            'updated_by'       => $this->admin_name,
        ];

        // Preserve created_at, bump version, store audit trail (Firestore)
        $existingStruct = null;
        try {
            $fsDoc = $this->fs->get('salarySlips', $this->fs->docId("SAL_{$staffId}"));
            if (is_array($fsDoc) && !empty($fsDoc)) $existingStruct = $fsDoc;
        } catch (\Exception $e) {}
        $oldVersion = 0;
        if (is_array($existingStruct)) {
            $data['created_at'] = $existingStruct['created_at'] ?? date('c');
            $oldVersion = (int) ($existingStruct['_version'] ?? 0);
            $data['_prev'] = [
                'basic'      => $existingStruct['basic'] ?? 0,
                'source'     => $existingStruct['source'] ?? '',
                'updated_at' => $existingStruct['updated_at'] ?? '',
                'updated_by' => $existingStruct['updated_by'] ?? '',
            ];
        } else {
            $data['created_at'] = date('c');
        }
        $data['_version'] = $oldVersion + 1;

        // 1. Firestore FIRST (primary)
        $this->_fsSyncSalary($staffId, $data);

        $gross      = $basic + $hra + $da + $ta + $medical + $otherAllowances;
        // PF/ESI/TDS stored as percentages — compute actual amounts for summary
        $pfAmt      = round($basic * ($pfEmployee / 100), 2);
        $esiAmt     = round($gross * ($esiEmployee / 100), 2);
        $tdsAmt     = round($gross * ($tds / 100), 2);
        $deductions = $pfAmt + $esiAmt + $professionalTax + $tdsAmt + $otherDeductions;
        $net        = $gross - $deductions;

        $this->json_success([
            'message' => 'Salary structure saved.',
            'summary' => [
                'gross'      => round($gross, 2),
                'deductions' => round($deductions, 2),
                'net'        => round($net, 2),
            ],
        ]);
    }

    /**
     * POST — Delete a salary structure.
     * Params: id (staff_id key)
     */
    public function delete_salary_structure()
    {
        $this->_require_role(self::ADMIN_ROLES, 'delete_salary_structure', 'HR', 'manage');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'salary_id');

        $existing = null;
        try {
            $fsDoc = $this->fs->get('salarySlips', $this->fs->docId("SAL_{$id}"));
            if (is_array($fsDoc) && !empty($fsDoc)) $existing = $fsDoc;
        } catch (\Exception $e) {}
        if (!is_array($existing)) {
            $this->json_error('Salary structure not found.');
        }

        // Block if any Finalized or Paid payroll includes this staff (Firestore)
        try {
            $fsRuns = $this->fs->schoolWhere('salarySlips', [['type', '==', 'run']]);
            if (is_array($fsRuns)) {
                foreach ($fsRuns as $doc) {
                    $docId = $doc['id'] ?? '';
                    if ($docId === '') continue;
                    $run = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $status = $run['status'] ?? '';
                    if (!in_array($status, ['Finalized', 'Paid'], true)) continue;
                    $parts = explode('_RUN_', $docId, 2);
                    $runId = isset($parts[1]) ? $parts[1] : $docId;
                    try {
                        $fsSlip = $this->fs->get('salarySlips', $this->fs->docId("SLIP_{$runId}_{$id}"));
                        if (is_array($fsSlip) && !empty($fsSlip)) {
                            $this->json_error(
                                "Cannot delete: staff has a {$status} payroll ({$run['month']} {$run['year']}). "
                                . "Reverse or delete the payroll run first."
                            );
                        }
                    } catch (\Exception $e) {}
                }
            }
        } catch (\Exception $e) {}

        // Archive before deletion for audit trail (Firestore)
        $archiveData = array_merge($existing, [
            'deleted_at' => date('c'),
            'deleted_by' => $this->admin_name ?? $this->admin_id ?? '',
            'is_deleted' => true,
        ]);
        try {
            $archiveDocId = $this->fs->docId("SAL_ARCHIVE_{$id}_" . date('YmdHis'));
            $this->firebase->firestoreSet('salarySlips', $archiveDocId, array_merge($archiveData, [
                'type' => 'salary_archive', 'schoolId' => $this->school_id, 'staffId' => $id,
            ]), true);
        } catch (\Exception $e) {
            log_message('error', "HR delete_salary archive failed: " . $e->getMessage());
        }

        // Audit log
        $this->_log_leave_audit([
            'action'          => 'salary_structure_deleted',
            'staff_id'        => $id,
            'staff_name'      => $existing['updated_by'] ?? $id,
            'leave_type'      => 'N/A',
            'total_days'      => 0,
            'decision'        => 'Deleted',
            'decision_reason' => 'Salary structure deleted. Basic: ' . ($existing['basic'] ?? 0)
                . ', Version: ' . ($existing['_version'] ?? 1),
        ]);

        // 1. Firestore FIRST — remove salary doc
        try {
            $this->fs->remove('salarySlips', $this->fs->docId("SAL_{$id}"));
        } catch (\Exception $e) {
            log_message('error', "HR delete_salary FS failed: " . $e->getMessage());
        }

        $this->json_success(['message' => 'Salary structure deleted and archived.']);
    }

    // ====================================================================
    //  PAYROLL
    // ====================================================================

    /**
     * GET — List payroll runs for this session.
     */
    public function get_payroll_runs()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_payroll_runs', 'HR', 'manage');

        $list = [];

        // ── Firestore-first ──
        try {
            $fsDocs = $this->fs->schoolWhere('salarySlips', [['type', '==', 'run']]);
            if (!empty($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $docId = $doc['id'] ?? '';
                    if ($docId === '') continue;
                    $r = is_array($doc['data'] ?? null) ? $doc['data'] : [];
                    // Extract run ID from doc id (format: {schoolId}_RUN_{runId})
                    $parts = explode('_RUN_', $docId, 2);
                    $r['id'] = isset($parts[1]) ? $parts[1] : ($r['id'] ?? $docId);
                    $list[] = $r;
                }
            }
        } catch (\Exception $e) {
            log_message('error', "HR get_payroll_runs FS failed: " . $e->getMessage());
        }

        // Sort by created_at descending
        usort($list, function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        // ── M-5: Stale-payroll detection ────────────────────────────
        // For each run, check if any attendance correction was approved
        // AFTER the run was generated for any of the run's months. If so,
        // the slips are potentially out of date — mark stale=true so the
        // admin UI can surface a "regenerate?" hint. Cheap query: one
        // attendanceAuditLog lookup per call (not per run).
        try {
            $auditRows = $this->fs->schoolWhere('attendanceAuditLog', [
                ['action',     '==', 'CORRECTION_APPROVE'],
                ['targetType', '==', 'staff'],
            ], 'timestamp', 'DESC', 200);
            $approvalsByDate = [];   // 'YYYY-MM-DD' => latest approval timestamp
            foreach ($auditRows as $entry) {
                $d = is_array($entry['data'] ?? null) ? $entry['data'] : (is_array($entry) ? $entry : null);
                if (!is_array($d)) continue;
                $dateStr  = (string) ($d['date'] ?? '');
                $tsString = (string) ($d['timestamp'] ?? '');
                if ($dateStr === '' || $tsString === '') continue;
                if (!isset($approvalsByDate[$dateStr]) || $tsString > $approvalsByDate[$dateStr]) {
                    $approvalsByDate[$dateStr] = $tsString;
                }
            }

            $monthMap = ['January'=>1,'February'=>2,'March'=>3,'April'=>4,
                         'May'=>5,'June'=>6,'July'=>7,'August'=>8,
                         'September'=>9,'October'=>10,'November'=>11,'December'=>12];
            foreach ($list as &$r) {
                $r['stale']        = false;
                $r['stale_reason'] = '';
                $createdAt = (string) ($r['created_at'] ?? '');
                $regenAt   = (string) ($r['last_regenerated_at'] ?? '');
                $effective = ($regenAt !== '' && $regenAt > $createdAt) ? $regenAt : $createdAt;
                if ($effective === '') continue;

                $mn = $monthMap[$r['month'] ?? ''] ?? 0;
                $yr = (int) ($r['year'] ?? 0);
                if ($mn === 0 || $yr === 0) continue;
                $monthPrefix = sprintf('%04d-%02d', $yr, $mn);

                $staleCount = 0;
                $latestApprovalTs = '';
                foreach ($approvalsByDate as $dateStr => $apprTs) {
                    if (strpos($dateStr, $monthPrefix) !== 0) continue;
                    if ($apprTs > $effective) {
                        $staleCount++;
                        if ($apprTs > $latestApprovalTs) $latestApprovalTs = $apprTs;
                    }
                }
                if ($staleCount > 0) {
                    $r['stale']        = true;
                    $r['stale_reason'] = "{$staleCount} correction(s) approved after this run was generated; latest at " . substr($latestApprovalTs, 0, 19);
                }
            }
            unset($r);
        } catch (\Exception $e) {
            log_message('error', 'M-5 stale check failed: ' . $e->getMessage());
            // Don't block the listing — just leave stale flags absent.
        }

        $this->json_success(['payroll_runs' => $list]);
    }

    /**
     * GET — Pre-flight check before payroll generation.
     * Params: month (e.g. 'January'), year (e.g. '2026')
     *
     * Returns warnings and readiness status without creating any data.
     */
    public function preflight_payroll()
    {
        $this->_require_role(self::ADMIN_ROLES, 'preflight_payroll', 'HR', 'manage');

        $month = trim($this->input->get('month') ?? '');
        $year  = trim($this->input->get('year') ?? '');

        $validMonths = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];
        if (!in_array($month, $validMonths, true) || !preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid month or year.');
        }

        $warnings = [];

        // Check payroll month lock
        $this->_check_payroll_lock($month, $year);

        // ── Firestore-first: check for existing run ──
        $existingRunChecked = false;
        try {
            $fsRuns = $this->fs->schoolWhere('salarySlips', [
                ['type', '==', 'run'],
                ['month', '==', $month],
                ['year', '==', $year],
            ]);
            if (!empty($fsRuns)) {
                foreach ($fsRuns as $doc) {
                    $docId = $doc['id'] ?? '';
                    if ($docId === '') continue;

                    // OFF-CYCLE (2026-08-22) — preflight mirrors the guard in
                    // generate_payroll(): only an existing MONTHLY run blocks a
                    // new monthly run. An off-cycle run for the same period is
                    // a different event and must not make preflight refuse.
                    // Read with a monthly default so pre-`runType` documents
                    // still block, which is where they belong.
                    $dData = is_array($doc['data'] ?? null) ? $doc['data'] : [];
                    if ((string) ($dData['runType'] ?? self::RUN_TYPE_MONTHLY) !== self::RUN_TYPE_MONTHLY) {
                        continue;
                    }

                    $parts = explode('_RUN_', $docId, 2);
                    $rid = isset($parts[1]) ? $parts[1] : $docId;
                    $this->json_error("A payroll run already exists for {$month} {$year} (ID: {$rid}). Delete or use it.");
                }
            }
            $existingRunChecked = true;
        } catch (\Exception $e) {
            log_message('error', "HR preflight FS run check failed: " . $e->getMessage());
        }

        // Check roster (Firestore)
        $roster = [];
        try {
            $fsStaff = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            if (is_array($fsStaff)) {
                foreach ($fsStaff as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? '';
                    if ($sid !== '') $roster[$sid] = $d;
                }
            }
        } catch (\Exception $e) {}
        if (empty($roster)) {
            $this->json_error('No staff found in the current session roster.');
        }

        // ── Salary structure coverage — through the W3.1 compensation seam ──
        $rosterIds       = array_keys($roster);
        $periodEndDate   = date('Y-m-t', mktime(0, 0, 0, array_search($month, $validMonths) + 1, 1, (int) $year));
        $structures      = $this->_compensation_reader()->resolveMany($rosterIds, $periodEndDate);
        $staffWithSalary = array_keys($structures);

        $missingStructures = array_values(array_diff($rosterIds, $staffWithSalary));
        $coveredCount      = count(array_intersect($rosterIds, $staffWithSalary));

        if (empty($staffWithSalary)) {
            $this->json_error('No salary structures found. Please set up salary structures first.');
        }

        // W3.1 — preflight names the people it is going to skip.
        //
        // This block used to report `count($missingStructures) . ' staff
        // member(s) ... will be skipped'`. The named list was already computed
        // and then discarded into a count, which left the operator with a
        // number they could not act on: "3 will be skipped" gives you nothing
        // to go fix. Generation has always named them
        // ("Rahul Sharma: no salary data — skipped"), so preflight was the
        // weaker half of the same story — you only learned WHO after running
        // payroll. `blockers` carries that detail through to the UI; the prose
        // warning stays so nothing that reads `warnings` today regresses.
        $blockers = [];
        foreach ($missingStructures as $sid) {
            $p = $roster[$sid] ?? [];
            $blockers[] = [
                'staff_id'   => $sid,
                'staff_name' => (string) ($p['Name'] ?? $p['name'] ?? $sid),
                'department' => (string) ($p['Department'] ?? $p['department'] ?? ''),
                'severity'   => 'blocker',
                'reason'     => 'No salary structure — this employee will be skipped',
                'resolution' => 'Set up a salary structure for this employee',
            ];
        }
        if (!empty($missingStructures)) {
            $warnings[] = count($missingStructures) . ' staff member(s) in the roster have no salary structure and will be skipped.';
        }

        // Check accounting accounts — structured error for preflight UI
        $this->_validate_accounts(['5010', '5020', '2020'], true);

        // Check attendance data — Firestore-first via staffAttendanceSummary
        $monthYear  = "{$month} {$year}";
        $monthNum   = array_search($month, $validMonths) + 1;
        $monthKey   = sprintf('%04d-%02d', (int) $year, $monthNum);
        $attendance = [];

        try {
            $fsSummaries = $this->fs->schoolWhere('staffAttendanceSummary', [
                ['month', '==', $monthKey],
            ]);
            if (!empty($fsSummaries)) {
                foreach ($fsSummaries as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? '';
                    $dw  = $d['dayWise'] ?? '';
                    if ($sid !== '' && $dw !== '') {
                        $attendance[$sid] = $dw;
                    }
                }
            }
        } catch (\Exception $e) {}

        if (empty($attendance)) {
            $warnings[] = "No attendance data found for {$month} {$year}. All staff will be treated as fully present.";
        } else {
            $attendanceCount = count($attendance);
            if ($attendanceCount < $coveredCount) {
                $warnings[] = "Attendance data exists for {$attendanceCount} of {$coveredCount} staff. Missing staff will be treated as fully present.";
            }
        }

        // Check pending leave requests for this month — Firestore-first via helper
        $allLeaveReqs = $this->_fsGetAllLeaveRequests();
        $pendingCount = 0;
        $monthNum   = array_search($month, $validMonths) + 1;
        $monthStart = sprintf('%04d-%02d-01', (int) $year, $monthNum);
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        foreach ($allLeaveReqs as $rid => $lr) {
            // Case-insensitive — Teacher app writes "pending" lowercase.
            if (strtolower($lr['status'] ?? '') !== 'pending') continue;
            $lrFrom = $lr['from_date'] ?? '';
            $lrTo   = $lr['to_date'] ?? '';
            if ($lrFrom <= $monthEnd && $lrTo >= $monthStart) {
                $pendingCount++;
            }
        }
        if ($pendingCount > 0) {
            $warnings[] = "{$pendingCount} pending leave request(s) overlap with {$month} {$year}. Consider approving/rejecting them before generating payroll.";
        }

        // ── N-15 (W3.2.5) — payout-readiness blockers, named per employee ──
        //
        // `hasBankDetails()` and a PAN check already existed in
        // the W2.3 compensation readiness surface, and preflight already named
        // people (W3.1) — the two were simply never connected, so an operator
        // still got no warning that an employee could not actually be PAID.
        //
        // REUSED, NOT DUPLICATED: the bank-detail rule lives in
        // `payroll_readiness_helper.php` and the W2 readiness surface delegates
        // to the SAME function, so the two can never disagree. It sits in a
        // helper rather than the compensation service because "can this person
        // be paid" is a question about the staff document alone — and because
        // naming that service here would trip the W2 guards that keep the
        // compensation cutover un-wired. The guards are right; the predicate
        // was in the wrong place.
        //
        // DELIBERATELY NOT the full readiness check. That also asserts a W2
        // salary ASSIGNMENT exists, and the component model is off with an
        // empty collection — running it today would block EVERY employee in
        // EVERY tenant with "no salary assignment is effective". Payout checks
        // are independent of the component model; assignment checks belong to
        // the W2 cutover gate, not here.
        require_once APPPATH . 'helpers/payroll_readiness_helper.php';
        $rosterPii = $this->_apply_staff_private_map($roster);
        foreach ($rosterIds as $sid) {
            if (!isset($structures[$sid])) continue;   // already blocked above
            $s = is_array($rosterPii[$sid] ?? null) ? $rosterPii[$sid] : ($roster[$sid] ?? []);
            if (!is_array($s)) continue;

            $name = (string) ($s['Name'] ?? $s['name'] ?? $sid);
            $dept = (string) ($s['Department'] ?? $s['department'] ?? '');

            if (!payroll_has_bank_details($s)) {
                $blockers[] = [
                    'staff_id'   => $sid,
                    'staff_name' => $name,
                    'department' => $dept,
                    'severity'   => 'blocker',
                    'reason'     => 'Bank details missing — this employee cannot be paid',
                    'resolution' => 'Complete bank/payment details before payroll generation',
                ];
            }

            // PAN is a WARNING, not a blocker, until TDS is actually live for
            // the tenant. Blocking pay over a document nothing currently uses
            // would stop payroll for the wrong reason.
            $panOnRecord = payroll_pan_on_record($s);
            if (!$panOnRecord) {
                $blockers[] = [
                    'staff_id'   => $sid,
                    'staff_name' => $name,
                    'department' => $dept,
                    'severity'   => 'warning',
                    'reason'     => 'PAN not on record — required once TDS deduction is enabled',
                    'resolution' => 'Add PAN to the employee record',
                ];
            }
        }

        // W3.1 — name the employees missing attendance too. Same principle as
        // the structure blockers above: this is a WARNING not a blocker,
        // because "treated as fully present" is a defined outcome rather than
        // a failure — but the operator still deserves to know which people it
        // is about to apply to.
        if (!empty($attendance)) {
            foreach ($rosterIds as $sid) {
                if (isset($attendance[$sid]) || !isset($structures[$sid])) continue;
                $p = $roster[$sid] ?? [];
                $blockers[] = [
                    'staff_id'   => $sid,
                    'staff_name' => (string) ($p['Name'] ?? $p['name'] ?? $sid),
                    'department' => (string) ($p['Department'] ?? $p['department'] ?? ''),
                    'severity'   => 'warning',
                    'reason'     => "No attendance recorded for {$month} {$year} — will be treated as fully present",
                    'resolution' => 'Mark attendance for this employee, or accept full presence',
                ];
            }
        }

        // Blockers first, then warnings; within each, by name. An operator
        // works top-down, so the things that stop payroll must not be buried
        // under the things that merely inform it.
        usort($blockers, function ($a, $b) {
            if ($a['severity'] !== $b['severity']) return $a['severity'] === 'blocker' ? -1 : 1;
            return strcasecmp($a['staff_name'], $b['staff_name']);
        });

        $blockerCount = count(array_filter($blockers, fn($b) => $b['severity'] === 'blocker'));

        $this->json_success([
            'ready'          => true,
            'staff_covered'  => $coveredCount,
            'staff_total'    => count($rosterIds),
            'pending_leaves' => $pendingCount,
            'warnings'       => $warnings,
            // W3.1 — per-employee, named, actionable. `warnings` is retained
            // unchanged so existing readers do not regress.
            'blockers'       => $blockers,
            'blocker_count'  => $blockerCount,
            'warning_count'  => count($blockers) - $blockerCount,
        ]);
    }

    /**
     * POST — Auto-create missing accounting accounts.
     * Seeds the FULL default chart (62 accounts) — not just the 10 payroll ones.
     * Skips any account that already exists. Safe to run multiple times.
     */
    public function auto_create_payroll_accounts()
    {
        $this->_require_role(self::ADMIN_ROLES, 'auto_create_payroll_accounts', 'HR', 'manage');

        // Read existing CoA from Firestore
        $coa = [];
        try {
            // COA-D1: the chart lives in its OWN collection. Accounting.php owns it
            // (reads :158, writes :202, deletes :209) and records at :1063 that the
            // old `accounting` mirror became a no-op duplicate after the migration.
            // Reading the pre-migration shape here meant payroll saw an EMPTY chart
            // and refused to run — 65 accounts in chartOfAccounts, 0 in accounting.
            //
            // Canonical first, legacy second, so a tenant still on the old shape is
            // not stranded and nothing needs migrating for this to work.
            $fsDocs = $this->fs->schoolWhere('chartOfAccounts', []);
            if (!is_array($fsDocs) || $fsDocs === []) {
                $fsDocs = $this->fs->schoolWhere('accounting', [['type', '==', 'chartOfAccounts']]);
            }
            if (is_array($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $code = $d['code'] ?? '';
                    if ($code !== '') $coa[$code] = $d;
                }
            }
        } catch (\Exception $e) {}

        // Seed the FULL chart of accounts (62 accounts), not just the 10 payroll ones
        $now      = date('c');
        $defaults = $this->_full_coa_template($now);

        $created = [];
        $skipped = [];

        foreach ($defaults as $code => $acct) {
            if (isset($coa[$code]) && is_array($coa[$code]) && ($coa[$code]['status'] ?? '') === 'active') {
                $skipped[] = $code;
                continue;
            }
            try {
                $acct['type'] = 'chartOfAccounts';
                $acct['schoolId'] = $this->school_id;
                $this->firebase->firestoreSet('accounting', $this->fs->docId("COA_{$code}"), $acct, true);
                $created[] = $code;
            } catch (\Exception $e) {
                log_message('error', "HR auto_create_payroll_accounts Firestore write failed for {$code}: " . $e->getMessage());
            }
        }

        log_message('info',
            "auto_create_payroll_accounts: school=[{$this->school_name}] "
            . "created=[" . implode(',', $created) . "] "
            . "skipped=[" . implode(',', $skipped) . "] "
            . "admin=[{$this->admin_id}]"
        );

        $this->json_success([
            'message' => count($created) > 0
                ? count($created) . ' account(s) created successfully (full chart of accounts).'
                : 'All required accounts already exist.',
            'created' => $created,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Full Chart of Accounts template — mirrors Accounting::_default_coa_template().
     * Kept in sync: any new account added to Accounting should be added here too.
     */
    private function _full_coa_template(string $ts): array
    {
        $a = [];
        $add = function ($code, $name, $cat, $sub, $parent, $group = false, $bank = false) use (&$a, $ts) {
            $a[$code] = [
                'code' => $code, 'name' => $name, 'category' => $cat,
                'sub_category' => $sub, 'parent_code' => $parent,
                'is_group' => $group, 'is_bank' => $bank,
                'normal_side' => in_array($cat, ['Asset', 'Expense']) ? 'Dr' : 'Cr',
                'description' => '', 'opening_balance' => 0,
                'status' => 'active', 'is_system' => true,
                'sort_order' => (int) $code,
                'created_at' => $ts, 'updated_at' => $ts,
            ];
        };

        // Assets (1000-1999)
        $add('1000', 'Current Assets',        'Asset', 'Current Assets',  null, true);
        $add('1010', 'Cash in Hand',           'Asset', 'Current Assets',  '1000');
        $add('1020', 'Bank Account',            'Asset', 'Current Assets',  '1000', false, true);
        $add('1030', 'Accounts Receivable',    'Asset', 'Current Assets',  '1000');
        $add('1040', 'Advance to Staff',       'Asset', 'Current Assets',  '1000');
        $add('1050', 'Deposits & Prepayments', 'Asset', 'Current Assets',  '1000');
        $add('1060', 'Fees Receivable',        'Asset', 'Current Assets',  '1000');
        $add('1100', 'Fixed Assets',           'Asset', 'Fixed Assets',    null, true);
        $add('1110', 'Furniture & Fixtures',   'Asset', 'Fixed Assets',    '1100');
        $add('1120', 'Computer & Equipment',   'Asset', 'Fixed Assets',    '1100');
        $add('1130', 'Vehicles',               'Asset', 'Fixed Assets',    '1100');
        $add('1140', 'Building',               'Asset', 'Fixed Assets',    '1100');

        // Liabilities (2000-2999)
        $add('2000', 'Current Liabilities',       'Liability', 'Current Liabilities', null, true);
        $add('2010', 'Accounts Payable',           'Liability', 'Current Liabilities', '2000');
        $add('2020', 'Salary Payable',             'Liability', 'Current Liabilities',    '2000');
        $add('2030', 'PF Payable',                 'Liability', 'Statutory Liabilities', '2000');
        $add('2031', 'ESI Payable',                'Liability', 'Statutory Liabilities', '2000');
        $add('2032', 'TDS Payable',                'Liability', 'Statutory Liabilities', '2000');
        $add('2033', 'Professional Tax Payable',   'Liability', 'Statutory Liabilities', '2000');
        $add('2034', 'Other Deductions Payable',   'Liability', 'Statutory Liabilities', '2000');
        $add('2040', 'Security Deposits Received', 'Liability', 'Current Liabilities',   '2000');
        $add('2050', 'Advance Fees Received',      'Liability', 'Current Liabilities',   '2000');
        $add('2060', 'GST Payable',                'Liability', 'Statutory Liabilities', '2000');
        $add('2100', 'Long-term Liabilities',      'Liability', 'Long-term Liabilities', null, true);
        $add('2110', 'Loans Payable',              'Liability', 'Long-term Liabilities', '2100');

        // Equity (3000-3999)
        $add('3000', 'Equity',           'Equity', 'Equity', null, true);
        $add('3010', 'Trust Fund/Capital','Equity', 'Equity', '3000');
        $add('3020', 'Retained Surplus',  'Equity', 'Equity', '3000');

        // Income (4000-4999)
        $add('4000', 'Fee Income',         'Income', 'Fee Income',    null, true);
        $add('4010', 'Tuition Fees',       'Income', 'Fee Income',    '4000');
        $add('4020', 'Admission Fees',     'Income', 'Fee Income',    '4000');
        $add('4030', 'Examination Fees',   'Income', 'Fee Income',    '4000');
        $add('4040', 'Transport Fees',     'Income', 'Fee Income',    '4000');
        $add('4050', 'Hostel Fees',        'Income', 'Fee Income',    '4000');
        $add('4060', 'Late Fees/Penalty',  'Income', 'Fee Income',    '4000');
        $add('4100', 'Other Income',       'Income', 'Other Income',  null, true);
        $add('4110', 'Interest Income',    'Income', 'Other Income',  '4100');
        $add('4120', 'Donation Received',  'Income', 'Other Income',  '4100');
        $add('4130', 'Rent Income',        'Income', 'Other Income',  '4100');
        $add('4140', 'Miscellaneous Income','Income','Other Income',  '4100');

        // Expenses (5000-5999)
        $add('5000', 'Staff Expenses',           'Expense', 'Staff Expenses',  null, true);
        $add('5010', 'Teaching Staff Salary',    'Expense', 'Staff Expenses',  '5000');
        $add('5020', 'Non-Teaching Staff Salary','Expense', 'Staff Expenses',  '5000');
        $add('5030', 'PF/ESI Contribution',      'Expense', 'Staff Expenses',  '5000');
        $add('5100', 'Administrative Expenses',  'Expense', 'Admin Expenses',  null, true);
        $add('5110', 'Office Supplies',          'Expense', 'Admin Expenses',  '5100');
        $add('5120', 'Printing & Stationery',   'Expense', 'Admin Expenses',  '5100');
        $add('5130', 'Communication',            'Expense', 'Admin Expenses',  '5100');
        $add('5140', 'Travel & Conveyance',     'Expense', 'Admin Expenses',  '5100');
        $add('5150', 'Repairs & Maintenance',   'Expense', 'Admin Expenses',  '5100');
        $add('5160', 'Insurance',                'Expense', 'Admin Expenses',  '5100');
        $add('5170', 'Legal & Professional',    'Expense', 'Admin Expenses',  '5100');
        $add('5180', 'Bank Charges',             'Expense', 'Admin Expenses',  '5100');
        $add('5200', 'Educational Expenses',     'Expense', 'Educational',     null, true);
        $add('5210', 'Books & Library',          'Expense', 'Educational',     '5200');
        $add('5220', 'Laboratory Expenses',      'Expense', 'Educational',     '5200');
        $add('5230', 'Sports & Games',           'Expense', 'Educational',     '5200');
        $add('5240', 'Cultural Activities',      'Expense', 'Educational',     '5200');
        $add('5300', 'Utilities',                'Expense', 'Utilities',       null, true);
        $add('5310', 'Electricity',              'Expense', 'Utilities',       '5300');
        $add('5320', 'Water',                    'Expense', 'Utilities',       '5300');
        $add('5330', 'Generator/Fuel',           'Expense', 'Utilities',       '5300');

        return $a;
    }

    /**
     * Compute one employee's payslip amounts for a pay period.
     *
     * Shared by generate_payroll() and regenerate_staff_payroll() so both paths
     * run the identical calculation and cannot drift. Pure function — no Firestore,
     * no session, no side effects; all inputs are passed in and the breakdown is
     * returned, so it is safe to call inside the per-employee loop.
     *
     * Statutory deductions (PF/ESI/TDS) are % of the EARNED (prorated) wage — the
     * same base as earnings — so a deduction can never exceed what was earned, and
     * employer PF/ESI use the same base as their employee counterpart. Flat PT/Other
     * are reduced (Other first, then PT) only when needed to keep net pay ≥ 0. Net
     * is gross − total deductions, so the accrual journal balances by construction.
     *
     * Rates come verbatim from the salary structure. No statutory ceilings,
     * eligibility thresholds, slabs, tax engines, carry-forward, or per-school
     * policy are applied — those are out of scope here.
     *
     * @param array $sal              Validated salary structure (rates + flats).
     * @param float $staffWorkingDays Payable working days in the employment window.
     * @param float $daysAbsent       Unpaid absent days (already window-capped).
     * @param float $lwpDays          Leave-without-pay days (supports 0.5).
     * @param float $extraWorkedDays  P0-4/A2 — days worked on a scheduled off-day
     *                                (attendance status 'W'). Defaults to 0.0, so a
     *                                caller that does not pass it gets byte-identical
     *                                output to the pre-A2 behaviour.
     * @param float $extraWorkedRate  Multiplier applied to the daily rate for those
     *                                days. Defaults to 1.0 (pay the day at par).
     *                                Per-school override: schools.attendanceRules
     *                                .extra_worked_rate. NOT a statutory overtime
     *                                rate — see the note at the computation site.
     * @return array Per-employee breakdown consumed by both callers.
     */
    // ═══════════════════════════════════════════════════════════════════
    //  W1.2 — MONEY PRECISION
    //
    //  AUTHORITATIVE UNIT: integer PAISE (1/100 INR). Every addition,
    //  subtraction and comparison below is exact integer arithmetic.
    //
    //  ROUNDING POLICY — ONE policy, stated once:
    //    1. Rupees → paise happens ONCE, at the input boundary
    //       (_toPaise), round-half-up.
    //    2. Ratio application (proration, percentages) rounds ONCE per
    //       component, half-up, at paise granularity (_applyRatio). There is
    //       no intermediate re-rounding.
    //    3. Sums, differences and comparisons NEVER round — they are exact
    //       integers.
    //    4. Paise → rupees happens ONCE, at the return boundary (_toRupees).
    //
    //  WHY: previously every component was a float rounded to 2dp, then those
    //  floats were summed and re-rounded. Per-component precision was already
    //  paise-granular, so the gain is not per-component accuracy — it is that
    //  SUMS, COMPARISONS and the deduction waterfall become exact. That is what
    //  guarantees `Σ components == gross` and `gross − deductions == net` to
    //  the paise for every input, and what keeps Dr == Cr when totals are
    //  accumulated across N employees (the failure mode behind the historical
    //  journal imbalance PayrollAccountingService documents).
    //
    //  STORAGE BOUNDARY: unchanged. Firestore continues to store rupee
    //  decimals; paise is internal to the calculation only. No migration.
    //  BUSINESS POLICY: unchanged. No rate, threshold, multiplier or rule
    //  is altered here — only the arithmetic used to evaluate them.
    // ═══════════════════════════════════════════════════════════════════

    /** Rupees (int|float|numeric-string) → integer paise. Round-half-up, once. */
    private static function _toPaise($rupees): int
    {
        return (int) round(((float) $rupees) * 100.0);
    }

    /** Integer paise → rupees, 2dp. The single output boundary. */
    private static function _toRupees(int $paise): float
    {
        return round($paise / 100.0, 2);
    }

    /**
     * Apply a ratio to an exact paise amount with exactly ONE rounding.
     * Used for proration and percentage deductions so a value is never
     * rounded, re-multiplied and rounded again.
     */
    private static function _applyRatio(int $paise, float $ratio): int
    {
        if ($paise === 0 || $ratio == 0.0) return 0;
        return (int) round($paise * $ratio);
    }

    /**
     * W2.7 — two OPTIONAL parameters were added, both defaulting to exactly
     * today's behaviour:
     *
     *   $statutory       null  → PF/ESI/TDS stay flat percentages of the earned
     *                            base, ignoring ceilings and thresholds, exactly
     *                            as this engine has always behaved.
     *                     array → the tenant's effective statutoryConfig is
     *                            applied: PF wage ceiling and eligibility-above-
     *                            ceiling, ESI threshold coverage, PT slabs.
     *
     *   $extraComponents []    → nothing changes.
     *                     array → tenant-defined components outside the legacy
     *                            thirteen reach the payslip instead of being
     *                            silently dropped.
     *
     * Both defaults are load-bearing: with them, this function is
     * byte-identical to its W1.2-certified form, which is what lets the
     * compensation cutover happen without reopening the money contract.
     * `HrPayslipStatutoryTest` pins that equivalence.
     *
     * @param array|null $statutory        effective statutoryConfig, or null for legacy behaviour
     * @param array      $extraComponents  [['code'=>, 'kind'=>'earning'|'deduction'|'employer_contribution',
     *                                       'amountPaise'=>int, 'prorated'=>bool], …]
     */
    private function _compute_payslip(array $sal, float $staffWorkingDays, float $daysAbsent, float $lwpDays, float $extraWorkedDays = 0.0, float $extraWorkedRate = 1.0, ?array $statutory = null, array $extraComponents = [], array $statutoryProfile = [], string $periodEndDate = ''): array
    {
        $warnings = [];

        // Earned wages: unpaid absence + LWP reduce pay proportionally; paid leave does not.
        if ($daysAbsent > $staffWorkingDays) $daysAbsent = $staffWorkingDays;
        $deductionDays = $daysAbsent + $lwpDays;
        if ($deductionDays > $staffWorkingDays) $deductionDays = $staffWorkingDays;

        $absentFraction = ($staffWorkingDays > 0) ? ($deductionDays / $staffWorkingDays) : 0.0;
        $retention      = 1 - $absentFraction;

        $daysWorked = $staffWorkingDays - $daysAbsent - $lwpDays;
        if ($daysWorked < 0) $daysWorked = 0;

        // W1.2 — inputs converted to exact paise ONCE, here.
        $basicP = self::_toPaise($sal['basic'] ?? 0);

        $effectiveBasicP  = self::_applyRatio($basicP,                                        $retention);
        $hraP             = self::_applyRatio(self::_toPaise($sal['hra'] ?? 0),               $retention);
        $daP              = self::_applyRatio(self::_toPaise($sal['da'] ?? 0),                $retention);
        $taP              = self::_applyRatio(self::_toPaise($sal['ta'] ?? 0),                $retention);
        $medicalP         = self::_applyRatio(self::_toPaise($sal['medical'] ?? 0),           $retention);
        $otherAllowancesP = self::_applyRatio(self::_toPaise($sal['other_allowances'] ?? 0),  $retention);

        // EXACT — integer sum, no rounding. This is what guarantees
        // `Σ earnings == gross` to the paise for every possible input.
        $grossP = $effectiveBasicP + $hraP + $daP + $taP + $medicalP + $otherAllowancesP;

        // Display-only day accounting (unchanged semantics + unchanged value).
        $dailySalary = ($staffWorkingDays > 0)
            ? round(($basicP / 100.0) / $staffWorkingDays, 2)
            : 0.0;

        // W1.2 — the MONEY is derived in ONE step from the exact paise base
        // (basic × days ÷ workingDays) rather than rounding a daily rate to 2dp
        // and then multiplying it, which amplified the rounding error. The
        // displayed daily rate above is untouched.
        $lwpDeductionP = ($staffWorkingDays > 0)
            ? self::_applyRatio($basicP, $lwpDays / $staffWorkingDays)
            : 0;

        // P0-4/A2 — extra pay for days worked on a scheduled off-day (the current
        // Attendance writer's 'W' status, which exists specifically to trigger
        // extra pay and which payroll previously ignored entirely).
        //
        // Paid at the SAME daily rate the engine already uses for the LWP
        // deduction, so a day lost and a day gained are symmetric — no second
        // rate basis is introduced. Added to gross BEFORE the statutory block so
        // ESI/TDS treat it as earned wages (consistent with this function's
        // "statutory is a % of the EARNED wage" contract). It deliberately does
        // NOT inflate $effectiveBasic, so the PF base stays basic-only.
        //
        // NOTE: extra_worked_rate is a PAY-POLICY multiplier, defaulting to 1.0
        // (pay the extra day at par). It is NOT a statutory overtime multiplier —
        // no statutory OT engine exists here, consistent with this function's
        // documented "no ceilings/slabs/tax engines" scope.
        // W1.2 — same one-step derivation from the exact paise base.
        $extraWorkedPayP = ($staffWorkingDays > 0)
            ? self::_applyRatio($basicP, ($extraWorkedDays * $extraWorkedRate) / $staffWorkingDays)
            : 0;
        if ($extraWorkedPayP > 0) {
            $grossP += $extraWorkedPayP;   // EXACT
        }

        // W2.7 — tenant-defined EARNINGS join gross BEFORE the statutory block,
        // for the same reason the 'W' extra-pay does: they are earned wages, so
        // ESI/TDS must see them. Without this they were silently dropped from
        // the payslip, which is a money error rather than a missing feature.
        // Prorated extras are scaled by the same retention ratio as basic/HRA.
        $extraEarningsP   = 0;
        $extraDeductionsP = 0;
        $extraBreakdownP  = [];
        foreach ($extraComponents as $xc) {
            if (!is_array($xc)) continue;
            $code = (string) ($xc['code'] ?? '');
            $kind = (string) ($xc['kind'] ?? 'earning');
            $amtP = (int) ($xc['amountPaise'] ?? 0);
            if ($code === '' || $amtP <= 0) continue;
            if (!empty($xc['prorated'])) $amtP = self::_applyRatio($amtP, $retention);

            if ($kind === 'earning')          { $extraEarningsP   += $amtP; $extraBreakdownP[$code] = $amtP; }
            elseif ($kind === 'deduction')    { $extraDeductionsP += $amtP; $extraBreakdownP[$code] = -$amtP; }
            // employer_contribution does not touch gross or net; it is reported
            // by deriveCtc(), not deducted here.
        }
        if ($extraEarningsP > 0) {
            $grossP += $extraEarningsP;   // EXACT
        }

        // Statutory deductions: % of the earned basic/gross (same base as earnings),
        // so a deduction can never outstrip what was earned.
        // W1.2: rates and bases are UNCHANGED — only the arithmetic is exact.
        $pfPct      = (float) ($sal['pf_employee'] ?? 0);
        $esiPct     = (float) ($sal['esi_employee'] ?? 0);
        $tdsPct     = (float) ($sal['tds'] ?? 0);

        // Flat deductions (may be superseded by a PT slab table below).
        $professionalTaxP = self::_toPaise($sal['professional_tax'] ?? 0);
        $otherDeductionsP = self::_toPaise($sal['other_deductions'] ?? 0);

        if ($statutory === null) {
            // ── LEGACY PATH — unchanged since W1.2, byte for byte ──
            $pfEmployeeP  = self::_applyRatio($effectiveBasicP, $pfPct  / 100);
            $esiEmployeeP = self::_applyRatio($grossP,          $esiPct / 100);
            $tdsP         = self::_applyRatio($grossP,          $tdsPct / 100);

            // Employer contributions use the same earned base as their employee
            // counterpart (PF on earned basic, ESI on earned gross).
            $pfEmployerP  = self::_applyRatio($effectiveBasicP, ((float) ($sal['pf_employer']  ?? 0)) / 100);
            $esiEmployerP = self::_applyRatio($grossP,          ((float) ($sal['esi_employer'] ?? 0)) / 100);
        } else {
            // ── STATUTORY-AWARE PATH (W2.7) ──
            // Resolution is delegated to Statutory_config_service so the payslip,
            // the CTC preview and the statutory engine can never disagree — the
            // inconsistency W2.6 flagged and deliberately did not leave standing.
            //
            // The BASES are unchanged (PF on earned basic, ESI on earned gross);
            // only ceilings, eligibility and thresholds are now honoured. An
            // operator who configures a ₹15,000 PF ceiling now gets one, instead
            // of a setting that silently does nothing.
            if (!class_exists('Statutory_config_service')) {
                require_once APPPATH . 'libraries/Statutory_config_service.php';
            }

            $pf  = Statutory_config_service::computePf($statutory, $effectiveBasicP);
            // Feature 2: ESI applicability may have been decided at the work
            // location. Absent key ⇒ null ⇒ the tenant-wide setting stands.
            $esiLocationApplicable = array_key_exists('_esiLocationApplicable', $statutoryProfile)
                ? ($statutoryProfile['_esiLocationApplicable'] === null ? null : (bool) $statutoryProfile['_esiLocationApplicable'])
                : null;
            $esi = Statutory_config_service::computeEsi($statutory, $grossP, $esiLocationApplicable);
            if ($esiLocationApplicable === false && !empty($statutoryProfile['_esiReason'])) {
                $warnings[] = 'ESI not applied: ' . (string) $statutoryProfile['_esiReason'] . '.';
            }

            // CA-6: the EMPLOYEE's recorded membership outranks the wage-versus-
            // ceiling derivation.
            //
            // The derivation alone cannot express the commonest real case —
            // someone who joined below the ceiling, became a member, and now
            // earns above it. Such a person REMAINS a member, but a pure
// wage comparison drops them out of PF the month they cross it.
            //
            // Fail-safe: an employee with no recorded profile resolves to
            // `ceiling_derivation`, which returns exactly what computePf()
            // already decided. No staff document carries a profile today, so
            // this is inert until one is deliberately recorded.
            if ($statutoryProfile !== []) {
                if (!class_exists('Staff_statutory_profile_service')) {
                    require_once APPPATH . 'libraries/Staff_statutory_profile_service.php';
                }
                $pfDecision = Staff_statutory_profile_service::resolvePf(
                    $statutoryProfile,
                    $periodEndDate,
                    (bool) ($pf['covered'] ?? false)
                );

                if ($pfDecision['source'] === 'profile'
                    && $pfDecision['covered'] !== (bool) ($pf['covered'] ?? false)) {

                    if ($pfDecision['covered'] === true) {
                        // Recorded member the derivation would have excluded.
// Contributions are computed on the SAME base and rates the
// configuration already resolved — only coverage changes.
                        $pf = Statutory_config_service::computePf(
                            array_replace_recursive($statutory, ['pf' => ['eligibleAboveCeiling' => true]]),
                            $effectiveBasicP
                        );
                        $warnings[] = 'PF applied from the employee statutory profile: '
                            . $pfDecision['reason'] . '.';
                    } else {
                        // Recorded non-member the derivation would have covered.
                        $pf = ['employeePaise' => 0, 'employerPaise' => 0, 'basePaise' => 0,
                               'covered' => false, 'reason' => $pfDecision['reason']];
                        $warnings[] = 'PF withheld from the employee statutory profile: '
                            . $pfDecision['reason'] . '.';
                    }
                }
            }

            $pfEmployeeP  = (int) $pf['employeePaise'];
            $pfEmployerP  = (int) $pf['employerPaise'];
            $esiEmployeeP = (int) $esi['employeePaise'];
            $esiEmployerP = (int) $esi['employerPaise'];

            $tds  = Statutory_config_service::computeTds($statutory, $grossP);
            $tdsP = (int) $tds['amountPaise'];
            if (empty($tds['supported'])) {
                // Never guess a tax figure: fall back to the structure's flat
                // rate and say so on the payslip.
                $tdsP = self::_applyRatio($grossP, $tdsPct / 100);
                $warnings[] = 'TDS mode ' . (string) ($tds['mode'] ?? '?')
                    . ' is not implemented; the structure\'s flat rate was used.';
            }

            // PT slabs are authoritative WHEN CONFIGURED. With no slab table the
            // structure's flat amount stands, so configuring statutory policy
            // never silently zeroes an existing professional-tax deduction.
            $ptSlabs = is_array($statutory['pt']['slabs'] ?? null) ? $statutory['pt']['slabs'] : [];
            if (!empty($ptSlabs)) {
                $pt = Statutory_config_service::computePt($statutory, $grossP);
                $professionalTaxP = (int) $pt['amountPaise'];
            }

            if (!$pf['covered'] && $effectiveBasicP > 0) {
                $warnings[] = 'PF not applied: ' . (string) $pf['reason'] . '.';
            }
            if (!$esi['covered'] && $grossP > 0) {
                $warnings[] = 'ESI not applied: ' . (string) $esi['reason'] . '.';
            }
        }

        // Tenant-defined deductions join the employee deduction pool below.
        $otherDeductionsP += $extraDeductionsP;

        // Net pay. Statutory-on-earned-base is ≤ gross for any sane config, so this
        // normally does nothing. It engages only when flat PT+Other would push total
        // deductions past a (very low) earned gross: reduce Other first, then PT, so
        // net lands at 0 — never negative — and gross == net + Σ deductions exactly.
        // W1.2 — the entire waterfall is exact integer paise. Comparisons and
        // subtractions no longer need epsilons, and the residual step below is
        // an exact equality rather than an `abs(...) >= 0.01` approximation.
        $deferredOtherP = 0; $deferredPtP = 0; $deferredStatutoryP = 0;
        $employeeDeductionsP = $pfEmployeeP + $esiEmployeeP + $tdsP + $professionalTaxP + $otherDeductionsP;

        if ($employeeDeductionsP > $grossP) {
            $overflowP = $employeeDeductionsP - $grossP;

            $cut = min($otherDeductionsP, $overflowP);
            $otherDeductionsP -= $cut;
            $deferredOtherP    = $cut;
            $overflowP        -= $cut;

            if ($overflowP > 0) {
                $cut = min($professionalTaxP, $overflowP);
                $professionalTaxP -= $cut;
                $deferredPtP       = $cut;
                $overflowP        -= $cut;
            }

            // Last-resort guard: only reachable with an INVALID structure whose
            // statutory rates themselves sum to >100% of earnings. Clamp the
            // statutory trio proportionally to keep the two hard invariants
            // (net ≥ 0, journal balanced) and warn loudly so the misconfigured
            // salary structure is corrected. Not a normal-absence path.
            $statutoryP = $pfEmployeeP + $esiEmployeeP + $tdsP;
            // W1.2: `> 0` is exact in paise — the old `> 0.005` epsilon existed
            // only to tolerate float noise and is no longer needed.
            if ($overflowP > 0 && $statutoryP > 0) {
                $factor = ($grossP > 0) ? ($grossP / $statutoryP) : 0.0;
                $pfEmployeeP  = self::_applyRatio($pfEmployeeP,  $factor);
                $esiEmployeeP = self::_applyRatio($esiEmployeeP, $factor);
                $tdsP         = self::_applyRatio($tdsP,         $factor);
                $deferredStatutoryP = $overflowP;
                $warnings[] = 'statutory rates exceed 100% of earned wages — check salary structure (PF/ESI/TDS %)';
            }

            // Recompute total, then absorb any residual into the largest
            // remaining positive component so total === gross EXACTLY.
            // W1.2: `!== 0` is an exact integer test; the residual can now only
            // arise from the proportional clamp above, never from float drift.
            $employeeDeductionsP = $pfEmployeeP + $esiEmployeeP + $tdsP + $professionalTaxP + $otherDeductionsP;
            $residualP = $grossP - $employeeDeductionsP;
            if ($residualP !== 0) {
                if ($otherDeductionsP > 0)      { $otherDeductionsP += $residualP; }
                elseif ($professionalTaxP > 0)  { $professionalTaxP += $residualP; }
                elseif ($tdsP > 0)              { $tdsP             += $residualP; }
                elseif ($esiEmployeeP > 0)      { $esiEmployeeP     += $residualP; }
                else                            { $pfEmployeeP      += $residualP; }
                $employeeDeductionsP = $pfEmployeeP + $esiEmployeeP + $tdsP + $professionalTaxP + $otherDeductionsP;
            }
        }

        $netPayP = $grossP - $employeeDeductionsP;   // EXACT
        if ($netPayP < 0) $netPayP = 0; // invariant guard; unreachable after waterfall

        // ── W1.2 output boundary: paise → rupees, ONCE, here. ───────────────
        $effectiveBasic     = self::_toRupees($effectiveBasicP);
        $hra                = self::_toRupees($hraP);
        $da                 = self::_toRupees($daP);
        $ta                 = self::_toRupees($taP);
        $medical            = self::_toRupees($medicalP);
        $otherAllowances    = self::_toRupees($otherAllowancesP);
        $gross              = self::_toRupees($grossP);
        $lwpDeduction       = self::_toRupees($lwpDeductionP);
        $extraWorkedPay     = self::_toRupees($extraWorkedPayP);
        $pfEmployee         = self::_toRupees($pfEmployeeP);
        $pfEmployer         = self::_toRupees($pfEmployerP);
        $esiEmployee        = self::_toRupees($esiEmployeeP);
        $esiEmployer        = self::_toRupees($esiEmployerP);
        $tds                = self::_toRupees($tdsP);
        $professionalTax    = self::_toRupees($professionalTaxP);
        $otherDeductions    = self::_toRupees($otherDeductionsP);
        $employeeDeductions = self::_toRupees($employeeDeductionsP);
        $netPay             = self::_toRupees($netPayP);
        $deferredOther      = self::_toRupees($deferredOtherP);
        $deferredPt         = self::_toRupees($deferredPtP);
        $deferredStatutory  = self::_toRupees($deferredStatutoryP);

        return [
            // day accounting
            'days_worked'        => $daysWorked,
            'deduction_days'     => $deductionDays,
            'absent_fraction'    => $absentFraction,
            'daily_salary'       => $dailySalary,
            'lwp_deduction'      => $lwpDeduction,
            // P0-4/A2 — extra-worked ('W') day accounting + its earning line.
            'extra_worked_days'  => $extraWorkedDays,
            'extra_worked_pay'   => $extraWorkedPay,
            // earned wages
            'basic'              => $effectiveBasic,
            'hra'                => $hra,
            'da'                 => $da,
            'ta'                 => $ta,
            'medical'            => $medical,
            'other_allowances'   => $otherAllowances,
            'gross'              => $gross,
            // statutory + employer
            'pf_employee'        => $pfEmployee,
            'pf_employer'        => $pfEmployer,
            'esi_employee'       => $esiEmployee,
            'esi_employer'       => $esiEmployer,
            'tds'                => $tds,
            // voluntary / flat
            'professional_tax'   => $professionalTax,
            'other_deductions'   => $otherDeductions,
            // totals
            'total_deductions'   => $employeeDeductions,
            'net_pay'            => $netPay,
            // amounts not deducted because they exceeded earned gross (display only)
            'deferred'           => [
                'other_deductions' => $deferredOther,
                'professional_tax' => $deferredPt,
                'statutory'        => $deferredStatutory,
            ],
            // ── W3.2.4 — the bases and rates this calculation actually used ──
            //
            // Additive: every existing caller reads named keys, so a new key
            // cannot disturb them. Added because the trace must show WHY a
            // deduction is the size it is, and "PF is 12% of ₹28,500" is only
            // checkable if the ₹28,500 is stated. Deriving it in the trace
            // would be a second arithmetic path for a statutory base — the
            // engine states it instead.
            //
            // These are the exact integers the deductions were computed from,
            // not rounded rupee echoes of them.
            'bases'              => [
                'retention_ratio'   => $retention,
                'absent_fraction'   => $absentFraction,
                // PF is a percentage of EARNED basic; ESI and TDS of EARNED
                // gross. That asymmetry is the single most misread rule in the
                // engine, so both bases are named rather than implied.
                'pf_base_paise'     => $effectiveBasicP,
                'esi_base_paise'    => $grossP,
                'tds_base_paise'    => $grossP,
                'basic_paise'       => $effectiveBasicP,
                // Percentages as applied. Under a statutory policy these are
                // the CONFIGURED rates; with no policy they are the structure's.
                'pf_employee_pct'   => $pfPct,
                'esi_employee_pct'  => $esiPct,
                'tds_pct'           => $tdsPct,
                'pf_employer_pct'   => (float) ($sal['pf_employer']  ?? 0),
                'esi_employer_pct'  => (float) ($sal['esi_employer'] ?? 0),
            ],
            // W1.2 — EXACT paise, for callers that accumulate across employees.
            // Summing the rupee floats above across N employees reintroduces the
            // very drift this change removes, and the payroll → accounting
            // journal must balance Dr == Cr exactly (see the historical
            // imbalance documented in PayrollAccountingService). Callers should
            // accumulate these and convert once at the end.
            'paise'              => [
                'gross'              => $grossP,
                'net_pay'            => $netPayP,
                'total_deductions'   => $employeeDeductionsP,
                'pf_employee'        => $pfEmployeeP,
                'pf_employer'        => $pfEmployerP,
                'esi_employee'       => $esiEmployeeP,
                'esi_employer'       => $esiEmployerP,
                'tds'                => $tdsP,
                'professional_tax'   => $professionalTaxP,
                'other_deductions'   => $otherDeductionsP,
                'extra_worked_pay'   => $extraWorkedPayP,
            ],
            // W2.7 — tenant-defined components, in exact paise, positive for
            // earnings and negative for deductions. Empty on the legacy path, so
            // every existing consumer of this array is unaffected.
            'extra_components'   => $extraBreakdownP,
            'statutory_source'   => $statutory === null ? 'structure' : 'statutoryConfig',
            'warnings'           => $warnings,
        ];
    }

    /**
     * POST — Generate payroll for a given month/year.
     * Params: month (e.g. 'January'), year (e.g. '2026')
     *
     * Creates a Draft run with individual slips for all staff with salary structures.
     * Factors in attendance (absent days reduce basic proportionally).
     * Also creates expense journal entries (Debit salary expense, Credit salary payable).
     */
    public function generate_payroll()
    {
        $this->_require_role(self::ADMIN_ROLES, 'generate_payroll', 'HR', 'manage');

        $month = trim($this->input->post('month') ?? '');
        $year  = trim($this->input->post('year') ?? '');

        // ── OFF-CYCLE (Feature 7, 2026-08-22) ────────────────────────────
        //
        // `runType` had been declared, and every consumer read it defensively,
        // but NOTHING could ever set it to `offcycle` — the whole capability
        // was scaffolding with no producer.
        //
        // Built GENERICALLY rather than as a Full & Final special case. An
        // off-cycle run is "pay these specific people, outside the monthly
        // cycle": F&F is one use, arrears and a bonus payout are others, and a
        // mechanism that only understood settlements would have to be rebuilt
        // for each of them.
        //
        // Absent parameter ⇒ monthly ⇒ today's behaviour, byte for byte.
        $runType = strtolower(trim((string) ($this->input->post('run_type') ?? '')));
        if ($runType === '') $runType = self::RUN_TYPE_MONTHLY;
        if (!in_array($runType, self::RUN_TYPES, true)) {
            $this->json_error('Unknown run type. Expected one of: ' . implode(', ', self::RUN_TYPES) . '.');
        }

        $subsetRaw = $this->input->post('staff_ids');
        $subset = [];
        if (is_string($subsetRaw) && trim($subsetRaw) !== '') {
            $decoded = json_decode($subsetRaw, true);
            $subsetRaw = is_array($decoded) ? $decoded : explode(',', $subsetRaw);
        }
        if (is_array($subsetRaw)) {
            foreach ($subsetRaw as $sid) {
                $sid = trim((string) $sid);
                if ($sid !== '' && !in_array($sid, $subset, true)) $subset[] = $sid;
            }
        }

        // An off-cycle run MUST name its employees. A whole-roster off-cycle
        // run is indistinguishable from a second monthly payroll, which is the
        // one thing the uniqueness guard exists to prevent — so the guard is
        // reinstated here in the only form that makes sense for this run type.
        if ($runType === self::RUN_TYPE_OFFCYCLE && $subset === []) {
            $this->json_error('An off-cycle run must name the staff it covers.');
        }
        // Conversely a monthly run covers everyone, by definition.
        if ($runType === self::RUN_TYPE_MONTHLY && $subset !== []) {
            $this->json_error('A monthly run covers the whole roster and cannot be limited to a subset. '
                . 'Use an off-cycle run to pay specific staff.');
        }

        $validMonths = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];
        if (!in_array($month, $validMonths, true)) {
            $this->json_error('Invalid month.');
        }
        if (!preg_match('/^\d{4}$/', $year)) {
            $this->json_error('Invalid year.');
        }

        // Check payroll month lock
        $this->_check_payroll_lock($month, $year);

        // ── MONTHLY UNIQUENESS (scoped 2026-08-22) ───────────────────────
        //
        // Exactly ONE MONTHLY run may exist per school/month/year — a second
        // would pay the whole roster twice. That invariant is unchanged and is
        // what this guard still enforces.
        //
        // What changed is its SCOPE. It previously refused any second run for
        // the period, which also blocked an off-cycle run (a Full & Final for a
        // March leaver, arrears, a bonus payout) that is a different event
        // entirely and must be able to coexist with March's monthly payroll.
        //
        // The filter is applied in PHP rather than added to the query on
        // purpose: existing run documents predate `runType` and carry no such
        // field, so `['runType','==','monthly']` would not match them and the
        // guard would silently stop protecting every run written before today.
        // Reading the field with a `monthly` default keeps those documents in
        // scope, which is precisely where they belong.
        try {
            $fsExRuns = $this->fs->schoolWhere('salarySlips', [
                ['type', '==', 'run'], ['month', '==', $month], ['year', '==', $year],
            ]);

            foreach ((array) $fsExRuns as $exRun) {
                $exData = is_array($exRun['data'] ?? null) ? $exRun['data'] : (is_array($exRun) ? $exRun : []);
                $exType = (string) ($exData['runType'] ?? self::RUN_TYPE_MONTHLY);
                if ($exType !== self::RUN_TYPE_MONTHLY) continue;   // off-cycle may coexist

                $parts = explode('_RUN_', (string) ($exRun['id'] ?? ''), 2);
                $rid = isset($parts[1]) ? $parts[1] : (string) ($exRun['id'] ?? '');
                $this->json_error("A payroll run already exists for {$month} {$year} (ID: {$rid}). Delete or use it.");
            }
        } catch (\Exception $e) {}

        // Get staff from Firestore roster
        $roster = [];
        try {
            $fsStaff = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            if (is_array($fsStaff)) {
                foreach ($fsStaff as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? '';
                    if ($sid !== '') $roster[$sid] = $d;
                }
            }
        } catch (\Exception $e) {}

        // P0-6/C2 — FINAL-PAY ROSTER. Selecting purely on status=='Active' meant
        // an employee who left mid-period was removed from payroll RETROACTIVELY
        // and their final partial month was never paid. Employment during the
        // period — not the current status flag — decides inclusion.
        //
        // Second bounded query rather than dropping the status filter entirely:
        // loading every staff doc ever created would grow without limit, whereas
        // this is one extra equality query per RUN (not per employee), reusing the
        // same index shape as the Active query above. No new composite index.
        //
        // Only exits ON OR AFTER the period start qualify — an earlier leaver was
        // not employed in this period. An Inactive record with NO exitDate is
        // skipped: it predates P0-6/C1, so its window is unknowable, and including
        // it would retroactively pay every historical leaver. _staff_working_days()
        // then clamps the window, so an exit after period end simply pays in full.
        $periodMonthNum = array_search($month, $validMonths) + 1;
        $periodStart    = sprintf('%04d-%02d-01', (int) $year, $periodMonthNum);
        $exitedIncluded = 0;
        try {
            $fsExited = $this->fs->schoolWhere('staff', [['status', '==', 'Inactive']]);
            if (is_array($fsExited)) {
                foreach ($fsExited as $doc) {
                    $d   = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? '';
                    if ($sid === '' || isset($roster[$sid])) continue;

                    // _normalize_date() returns YYYY-MM-DD or NULL (never ''), so
                    // an unparseable/absent exit date is explicitly skipped rather
                    // than relying on a null-vs-string comparison.
                    $xd = $this->_normalize_date((string) ($d['exitDate'] ?? $d['lastWorkingDate'] ?? ''));
                    if ($xd === null || $xd < $periodStart) continue;

                    $roster[$sid] = $d;
                    $exitedIncluded++;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'HR generate_payroll exited-staff roster read failed: ' . $e->getMessage());
        }
        if ($exitedIncluded > 0) {
            log_message('info', "PAYROLL_FINAL_PAY_ROSTER school={$this->school_id} month={$month} {$year} exited_staff_included={$exitedIncluded}");
        }
        if (empty($roster)) {
            $this->json_error('No staff found in the current session roster.');
        }
        // Audit C1: merge the server-only PII mirror so salaryDetails is present
        // for the auto-create-structure fallback (_auto_create_from_profile).
        $roster = $this->_apply_staff_private_map($roster);

        // Feature 7: an off-cycle run pays only the staff it names. Narrowed
        // AFTER the privacy map so an off-cycle run can never see a record the
        // monthly run could not.
        if ($runType === self::RUN_TYPE_OFFCYCLE) {
            $unknown = [];
            $narrowed = [];
            foreach ($subset as $sid) {
                if (isset($roster[$sid])) $narrowed[$sid] = $roster[$sid];
                else $unknown[] = $sid;
            }
            // Naming the missing ones rather than paying a smaller set in
            // silence: an off-cycle run that quietly skipped a leaver would be
            // discovered by the leaver.
            if ($unknown !== []) {
                $this->json_error('Not in the active roster for this period: ' . implode(', ', $unknown));
            }
            $roster = $narrowed;
        }

        // Get all salary structures — through the W3.1 compensation seam.
        // Legacy mode performs the same single query this loop used to do
        // inline; the difference is that the cutover now has one place to move.
        //
        // The run resolves compensation as of the LAST day of the pay period:
        // an effective-dated raise mid-month is in force by period end, and
        // period end is stable for a closed month in a way that `today` is not
        // — regenerating a finalized run must not silently reprice it.
        $periodEndDate = date(
            'Y-m-t',
            mktime(0, 0, 0, array_search($month, $validMonths) + 1, 1, (int) $year)
        );
        $salaryStructures = $this->_compensation_reader()->resolveMany(
            array_keys($roster),
            $periodEndDate
        );

        // S-1: the tenant's statutory policy in force FOR THIS PERIOD, resolved
        // once for the whole run. Null when the tenant has configured none,
        // which is the legacy branch and today's behaviour byte-for-byte.
        $statutoryCfg = $this->_statutory_config_for($periodEndDate);
        if (empty($salaryStructures)) {
            $this->json_error('No salary structures found. Please set up salary structures first.');
        }

        // Get staff profiles for names/departments (Firestore — already in $roster)
        $staffProfiles = $roster;

        // Validate accounting accounts exist before proceeding (H3)
        // 2030=PF Payable, 2031=ESI Payable, 2032=TDS Payable,
        // 2033=Professional Tax Payable, 2034=Other Deductions Payable
        $this->_validate_accounts(['5010', '5020', '2020', '2030', '2031', '2032', '2033', '2034'], true);

        // Fetch all leave type configs — Firestore-first via helper
        $allLeaveTypes = $this->_fsGetLeaveType('');
        $leaveTypeConfig = is_array($allLeaveTypes) ? $allLeaveTypes : [];

        // Get approved leave requests for this month — Firestore-first via helper
        $allLeaveReqs = $this->_fsGetAllLeaveRequests();
        $lwpByStaff       = []; // staffId => total LWP days in this month
        $lwpDaysByStaff   = []; // staffId => array of day-of-month numbers that are LWP
        $paidDaysByStaff  = []; // staffId => [day => 0.5] covered by APPROVED PAID leave (Half-Day programme)
        $leaveDetailStaff = []; // staffId => array of leave detail objects for payslip transparency
        if (!empty($allLeaveReqs)) {
            $monthStart = sprintf('%04d-%02d-01', (int) $year, array_search($month, $validMonths) + 1);
            $monthEnd   = date('Y-m-t', strtotime($monthStart));
            foreach ($allLeaveReqs as $rid => $lr) {
                $sid = (string) ($lr['staff_id'] ?? '');
                if ($sid === '') continue;

                // Single source of truth — same helper used by regenerate_staff_payroll.
                // Applies CR-1 (LWP on paid types with exhausted balance), CR-3
                // (deleted-type fallback), CR-4 (employment-window filtering).
                $sp = is_array($roster[$sid] ?? null) ? $roster[$sid] : [];
                $res = $this->_process_leave_for_payroll($lr, $leaveTypeConfig, $sp, $monthStart, $monthEnd, (string) $rid);
                if ($res === null) continue;

                if (!isset($leaveDetailStaff[$sid])) $leaveDetailStaff[$sid] = [];
                $leaveDetailStaff[$sid][] = $res['leaveDetail'];

                if ($res['lwpDays'] > 0) {
                    if (!isset($lwpByStaff[$sid]))     $lwpByStaff[$sid]     = 0.0;
                    if (!isset($lwpDaysByStaff[$sid])) $lwpDaysByStaff[$sid] = [];
                    $lwpByStaff[$sid] = (float) $lwpByStaff[$sid] + $res['lwpDays'];
                    foreach ($res['lwpDaySet'] as $dn => $portion) {
                        // Existing full-day mark wins over a later half-day mark.
                        if (isset($lwpDaysByStaff[$sid][$dn]) && $lwpDaysByStaff[$sid][$dn] === true) continue;
                        $lwpDaysByStaff[$sid][$dn] = $portion;
                    }
                }

                // Half-Day programme (2026-08-19) — days covered by APPROVED
                // PAID leave. Carries no deduction of its own; it exists so
                // the absence loop can tell "covered by paid leave" apart
                // from "genuinely unworked". See _process_leave_for_payroll().
                if (!empty($res['paidDaySet'])) {
                    if (!isset($paidDaysByStaff[$sid])) $paidDaysByStaff[$sid] = [];
                    foreach ($res['paidDaySet'] as $dn => $portion) {
                        $paidDaysByStaff[$sid][$dn] = $portion;
                    }
                }
            }
        }

        // ── FINALISE-GATE (Release Integration Phase 1) ─────────────────────
        // Payroll MUST compute on FINALISED attendance.
        //  (a) Refuse an in-progress / future month — its days haven't happened,
        //      so absent/LWP counts would be wrong and under/over-pay staff.
        //  (b) For a past month, run the idempotent Staff_attendance_finaliser
        //      (fills only PAST vacant days, stamps _finalisedAt) BEFORE reading
        //      counts. Mirrors MY_Controller::_auto_finalise_staff_attendance().
        // Schools with no attendancePolicy don't use staff attendance → skip.
        try {
            $schoolDocAtt = $this->fs->get('schools', $this->school_id);
            $attPolicy = (is_array($schoolDocAtt) && is_array($schoolDocAtt['attendancePolicy'] ?? null))
                ? $schoolDocAtt['attendancePolicy'] : [];
            if (!empty($attPolicy)) {
                $attTz = (is_string($attPolicy['timezone'] ?? null) && $attPolicy['timezone'] !== '')
                    ? $attPolicy['timezone'] : 'Asia/Kolkata';
                try { $attNow = new DateTime('now', new DateTimeZone($attTz)); }
                catch (\Throwable $e) { $attNow = new DateTime('now', new DateTimeZone('Asia/Kolkata')); }
                $payrollMk = sprintf('%04d-%02d', (int) $year, array_search($month, $validMonths) + 1);
                if ($payrollMk >= $attNow->format('Y-m')) {
                    return $this->json_error(
                        "Attendance for {$month} {$year} is not finalised — the month is still in progress. "
                        . "Run payroll after month-end so pay reflects the full month's attendance."
                    );
                }
                $this->load->library('staff_attendance_finaliser');
                $this->staff_attendance_finaliser->init($this->fs, $this->school_id, $this->session_year);
                $this->staff_attendance_finaliser->run($attPolicy, $attNow->format('Y-m-d'), [$payrollMk]);
            }
        } catch (\Throwable $e) {
            log_message('error', 'HR generate_payroll finalise-gate failed: ' . $e->getMessage());
        }

        // Get attendance for the month — Firestore-first via staffAttendanceSummary
        $monthYear  = "{$month} {$year}";
        $monthNum   = array_search($month, $validMonths) + 1;
        $monthKey   = sprintf('%04d-%02d', (int) $year, $monthNum);
        $attendance = [];

        try {
            $fsSummaries = $this->fs->schoolWhere('staffAttendanceSummary', [
                ['month', '==', $monthKey],
            ]);
            if (!empty($fsSummaries)) {
                foreach ($fsSummaries as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? '';
                    $dw  = $d['dayWise'] ?? '';
                    if ($sid !== '' && $dw !== '') {
                        $attendance[$sid] = $dw;
                    }
                }
            }
        } catch (\Exception $e) {}

        // HR-5 FIX: Exclude Sundays, 2nd & 4th Saturdays, and school holidays
        $monthNum    = array_search($month, $validMonths) + 1;
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $monthNum, 1, (int) $year));

        // HC-2 (holiday convergence): holidays come ONLY from the canonical
        // Holiday_service (calendarEvents). The orphan Firestore stores
        // schools.holidays + schools.attendanceConfig.holidays are no longer
        // read. Shape is preserved — [int dayOfMonth => name] — and consumed
        // exactly as before via isset($holidays[$d]). Firestore-only; no RTDB.
        $holidays = [];
        try {
            $this->load->library('holiday_service');
            $this->holiday_service->init($this->fs, (string) $this->fs->schoolId(), (string) $this->session_year);
            $holidays = $this->holiday_service->holidays_in_month((int) $year, (int) $monthNum);
        } catch (\Throwable $e) {
            log_message('error', 'Hr::generate_payroll HC-2 holiday load failed: ' . $e->getMessage());
        }

        $nonWorkingDays = 0;
        $sundayCount    = 0;
        $offSatCount    = 0;
        $holidayCount   = 0;
        // ── N-31/N-33 — there is no longer ONE run-level calendar ──
        //
        // This block used to resolve a single tenant calendar for the whole run.
        // With per-employee work schedules that number is undefined: teaching
        // staff may have 21 working days in the same month admin staff have 26.
        // Resolving a tenant calendar here would also have blocked runs where
        // every employee IS correctly assigned, simply because no tenant-wide
        // policy exists — which is no longer a meaningful thing to require.
        //
        // `working_days` on the run document is therefore DERIVED from the
        // employees actually priced (see after the loop): the shared value when
        // they agree, and 0 meaning "varies by schedule" when they do not.
        // `sundays` / `off_saturdays` / `holidays` are retained as 0 for reader
        // back-compatibility rather than reporting one schedule's numbers as if
        // they applied to everyone.
        $workingDays        = 0;
        $employeeWorkingSet = [];   // distinct per-employee working-day counts

        // Generate run ID
        $runId = $this->_next_id('PR', 'PayrollRun');

        // W3.2.2 — payroll-period overrides for this run, in ONE query for the
        // whole roster. A fresh run id normally has none; the read exists so
        // BOTH engine call sites receive overrides identically. `regenerate` is
        // contractually required to produce the same numbers as `generate` for
        // the same inputs, and that contract silently breaks the moment one of
        // them can see an adjustment the other cannot.
        $overridesByStaff = $this->_payroll_overrides()->forRun($runId);

        // W1.2 — run totals accumulate in EXACT integer paise (see the
        // conversion boundary further down). Rupee equivalents are derived once,
        // after the loop, so no float addition happens across employees.
        $totalGrossP          = 0;
        $totalDeductionsP     = 0;
        $totalNetP            = 0;
        $totalPFP             = 0;
        $totalESIP            = 0;
        $totalTDSP            = 0;
        $totalProfTaxP        = 0;
        $totalOtherDedP       = 0;
        $staffCount           = 0;
        $totalTeachingExpP    = 0;
        $totalNonTeachingExpP = 0;
        $slips                = [];

        $payrollWarnings = [];
        $deptGross       = []; // department → total gross (cost center tracking)

        // Load attendance config for vacant-day handling (Firestore)
        $attConfig = [];
        try {
            $schoolDoc2 = $this->fs->get('schools', $this->fs->schoolId());
            if (is_array($schoolDoc2) && is_array($schoolDoc2['attendanceRules'] ?? null)) {
                $attConfig = $schoolDoc2['attendanceRules'];
            }
        } catch (\Exception $e) {}

        foreach ($roster as $staffId => $rosterData) {
            $profile = (isset($staffProfiles[$staffId]) && is_array($staffProfiles[$staffId]))
                ? $staffProfiles[$staffId]
                : [];

            // ── Safety: auto-create missing structure from profile ──
            if (!isset($salaryStructures[$staffId]) || !is_array($salaryStructures[$staffId])) {
                $autoStruct = $this->_auto_create_from_profile($staffId, $profile);
                if ($autoStruct === null) {
                    $payrollWarnings[] = ($profile['Name'] ?? $staffId) . ': no salary data — skipped';
                    continue;
                }
                $salaryStructures[$staffId] = $autoStruct;
            }

            // ── Validate structure ──
            $sal = $this->_validate_structure($salaryStructures[$staffId]);
            if ($sal === null) {
                $this->_log_payroll_warning('corrupt_structure', $staffId,
                    'Salary structure has invalid/corrupt values — staff skipped');
                $payrollWarnings[] = ($profile['Name'] ?? $staffId) . ': corrupt salary data — skipped';
                continue;
            }

            $staffName  = $profile['Name'] ?? $staffId;
            $department = $profile['Department'] ?? $profile['department'] ?? '';
            $position   = $profile['Position'] ?? '';

            // Trail 2 Phase J (2026-08-18) — resolve the department that was
            // ACTUALLY effective for this payroll PERIOD, not the live/
            // current value. Without this, an admin editing a staff
            // member's department today could silently change the computed
            // working-days/holiday-audience math for this same past period
            // if it is later regenerated. Resolves against the period
            // START date — a genuine mid-month department transfer split
            // is deferred pending the Staff Transfer feature (not yet
            // built; Trail 2 Phase B confirmed it doesn't exist today).
            // Falls open to the live $department on any resolver failure —
            // this must never block payroll generation.
            //
            // Consolidated reconciliation audit (2026-08-19) — POSITION is
            // resolved here too, for the same reason and against the same
            // period-start date. It is not cosmetic: $position feeds the
            // Teaching/Non-Teaching expense-account split below (GL 5010 vs
            // 5020), so leaving it live meant one boolean expression mixed
            // a period-effective $department with a live $position. A
            // promotion out of a teaching post would retroactively
            // reclassify a REGENERATED past month's salary expense. The
            // classification RULE is unchanged — only the date at which its
            // inputs are read. 'position' is already in the tracked
            // Staff_employment_history_service::ATTRIBUTES vocabulary, so
            // this needs no schema change.
            try {
                $this->load->library('staff_employment_history_service');
                $this->staff_employment_history_service->init($this->fs, $this->school_id, (string) ($this->admin_id ?? ''));
                // NO-N+1: this loop resolves TWO attributes per employee, each a
                // real Firestore query. Warm the whole tenant timeline once —
                // after the first employee every resolve is served from memory,
                // turning 2N queries into 1. Idempotent, and a failed warm just
                // leaves the per-staff reads in place.
                $this->staff_employment_history_service->warmTimeline();
                $periodStart = sprintf('%04d-%02d-01', (int) $year, $monthNum);
                $histDept = $this->staff_employment_history_service->resolveAttributeAsOf($staffId, 'department', $periodStart);
                if ($histDept !== null && $histDept !== '') $department = $histDept;
                $histPos = $this->staff_employment_history_service->resolveAttributeAsOf($staffId, 'position', $periodStart);
                if ($histPos !== null && $histPos !== '') $position = $histPos;
            } catch (\Throwable $e) {
                log_message('error', "generate_payroll: department/position history resolve failed for {$staffId}: " . $e->getMessage());
            }

            // ── Per-staff working days — pro-rated for join/exit ──
            // If the staff joined or left mid-month, only count working days
            // inside their employment window. Days outside the window are
            // ignored (not absent, not vacant).
            $jd = $profile['joinDate']  ?? $profile['Date Of Joining'] ?? $profile['Joining Date']    ?? null;
            $xd = $profile['exitDate']  ?? $profile['Resignation Date'] ?? $profile['leaveDate']       ?? null;
            $swd = $this->_staff_working_days($monthNum, (int) $year, $holidays, $jd, $xd, $staffId, $department);
            $staffWorkingDays = $swd['working'];
            $staffInWindow    = $swd['inWindow'];
            $staffIsWorking   = $swd['isWorking'] ?? [];
            // CALENDAR HALF-DAY — per-day EXPECTED WORK. A day the calendar
            // declared a half-day expects $halfDayWeight (0.5 when the tenant
            // has the model enabled for this period, else 1.0). Everything the
            // absence loop below charges is scaled by this, so reconciliation
            // happens against the REDUCED expectation, never the full day.
            $staffDayReason   = $swd['dayReason'] ?? [];
            $halfDayWeight    = $this->_half_day_weight((int) $year, $monthNum);
            // Calendar Half-Day contract audit (2026-08-19) — FRACTION SAFETY.
            // $staffWorkingDays is a float. Using it directly as a PHP array
            // key TRUNCATES it (21.5 → 21), which would both misreport the
            // run-level figure and, worse, make 21.5 and 21 collide into one
            // key so genuinely-differing schedules would look uniform. Keyed
            // as a string instead; the value carries the true number.
            if ($staffWorkingDays > 0) {
                $employeeWorkingSet[(string) $staffWorkingDays] = (float) $staffWorkingDays;
            }

            // ── N-32/N-33 — per-employee schedule blocker ──
            //
            // No default schedule is ever substituted. This employee is skipped
            // with a named reason and the run continues for everyone correctly
            // assigned — consistent with the existing "no salary structure —
            // skipped" model, so operators read one familiar warning list.
            if (!empty($swd['calBlocked'])) {
                $payrollWarnings[] = $staffName . ': ' . $swd['calBlockWhy']
                    . ' — slip not generated';
                log_message('error', 'PAYROLL_SCHEDULE_BLOCKED school=' . $this->school_id
                    . " staff={$staffId} code=" . ($swd['calBlockCode'] ?? '?'));
                continue;
            }

            // If the staff didn't work this month at all (joined after / left before)
            // skip the slip entirely — no salary, no warning that would clutter the UI.
            if ($staffWorkingDays <= 0) {
                $payrollWarnings[] = $staffName . ": outside employment window for {$month} {$year} — skipped";
                continue;
            }

            // Determine absent days and leave days from attendance string.
            // Attendance marks: P=Present, A=Absent, L=Leave, H=Holiday, T=Late, V=Vacant
            // "L" marks are set automatically when leave is approved (see _apply_leave_to_attendance).
            // NOTE: Skip days already covered by approved LWP leave to avoid double-counting.
            //
            // staffLwpSet entries:
            //   true → full-day LWP (one whole day deducted by leave)
            //   0.5  → half-day LWP (Phase 4 half-day support)
            $daysAbsent      = 0.0;
            $paidLeaveDays   = 0;
            $unpaidLeaveDays = 0;
            $vacantDays      = 0;
            $halfDays        = 0;     // P0-3/A1 — attendance 'M' count
            $extraWorkedDays = 0.0;   // P0-4/A2 — attendance 'W' count
            $staffLwpSet     = isset($lwpDaysByStaff[$staffId]) ? $lwpDaysByStaff[$staffId] : [];
            $staffPaidSet    = isset($paidDaysByStaff[$staffId]) ? $paidDaysByStaff[$staffId] : [];

            // W3.2.1 — captured for the reproducibility snapshot written with
            // the slip below. Declared here because the parse block scopes it.
            $attStr       = '';
            $attSnapSource = 'none';

            // Config: how to treat vacant (unmarked) days.
            //   'block'   = refuse to generate slip; admin must override (default — payroll-safe)
            //   'absent'  = treat as absent (legacy — risky)
            //   'present' = treat as present
            //   'ignore'  = exclude entirely
            $vacantTreatment = is_array($attConfig) ? ($attConfig['vacant_treatment'] ?? 'block') : 'block';

            if (is_array($attendance) && isset($attendance[$staffId])) {
                $attStr        = (string) $attendance[$staffId];
                $attSnapSource = 'staffAttendanceSummary';
                $len           = strlen($attStr);
                for ($i = 0; $i < $len; $i++) {
                    $dayNum = $i + 1;
                    // Days outside the employment window are not the staff's
                    // problem — pre-join and post-exit days don't count as
                    // absent or vacant.
                    if (!isset($staffInWindow[$dayNum])) continue;
                    $ch = strtoupper($attStr[$i]);

                    // ── N-23 — non-working days are not chargeable ──
                    //
                    // Previously this gated on the employment window ALONE, so
                    // an 'A' on a Sunday was charged as absence. Proven live:
                    // 6 of 28 'A' marks fell on non-working days, the clamp hid
                    // the overflow, and an employee on FULLY-PAID leave was paid
                    // ₹0 instead of ₹1,250.
                    //
                    // 'W' is the deliberate exception: it MEANS "worked on a
                    // scheduled non-working day", so suppressing it here would
                    // destroy the extra-worked-pay feature this gate must keep.
                    if ($ch !== 'W' && !isset($staffIsWorking[$dayNum])) continue;

                    // CALENDAR HALF-DAY — expected work for THIS day. 1.0
                    // normally; $halfDayWeight (0.5) when the calendar
                    // declared it a half-day and the tenant has the model on.
                    $dayExpected = (($staffDayReason[$dayNum] ?? '') === \Working_calendar_service::REASON_HALF_DAY)
                        ? $halfDayWeight : 1.0;

                    // Approved-leave coverage for THIS day, from one shared rule.
                    // See _leave_coverage_for_day() for why the three branches
                    // below no longer each carry their own version of it.
                    $dayCoverage = self::_leave_coverage_for_day(
                        $dayExpected,
                        $staffLwpSet[$dayNum]  ?? null,
                        $staffPaidSet[$dayNum] ?? null
                    );

                    if ($ch === 'A') {
                        // Absent for whatever the day expected, less anything
                        // approved leave already covers. Paid coverage counts
                        // here now: it previously did not, so an employee on
                        // approved PAID half-day leave who was also marked
                        // absent was charged the full day.
                        $daysAbsent += max(0.0, $dayExpected - $dayCoverage);
                    } elseif ($ch === 'L') {
                        // S2 — a leave day consumes the day's EXPECTED work, not a
                        // flat 1. On a Calendar Half-Day only 0.5 is expected, so a
                        // full-day leave falling on it consumes 0.5 leave day. The
                        // bare ++ reported 1.0, which disagreed with the leave
                        // engine's own chargeable-days weighting and told the
                        // employee their balance had been debited a whole day for
                        // half a day of expected work.
                        //
                        // Reporting only: neither counter is an input to
                        // _compute_payslip(), so this cannot move any money. Pay on
                        // such a day is already full and stays full.
                        // Adds a literal int 1 on a normal day so a flag-OFF run
                        // stays byte-identical in TYPE as well as value — `+= 1.0`
                        // would silently promote the stored counter to a float for
                        // every tenant. Same precedent as
                        // Working_calendar_service::resolveFromVersion().
                        $step = ($dayExpected == 1.0) ? 1 : $dayExpected;
                        if (isset($staffLwpSet[$dayNum]) && $staffLwpSet[$dayNum] === true) {
                            $unpaidLeaveDays += $step;
                        } else {
                            $paidLeaveDays += $step;
                        }
                    } elseif ($ch === 'M') {
                        // P0-3/A1 — attendance HALF-DAY. Previously fell through
                        // every branch and was silently paid as a FULL day.
                        // 'M' = half the day worked ⇒ 0.5 day unworked.
                        //
                        // Double-count guard (mirrors the 'A' branch above): if
                        // leave already accounts for this day, the missing half is
                        // already deducted by the leave engine — adding another
                        // 0.5 here would deduct the same half twice. Only an
                        // unleaved 'M' contributes.
                        //
                        // Half-Day programme (2026-08-19): the guard now also
                        // honours APPROVED PAID leave coverage. Previously it
                        // checked only the LWP set, so a paid half-day leave —
                        // which produces no LWP and deliberately writes no
                        // attendance mark — left this 'M' unguarded and the
                        // employee was docked 0.5 day despite approved leave.
                        // Paid coverage adds no deduction of its own, so the
                        // day correctly nets to zero uncovered work.
                        // S1 (authorized 2026-08-19): 'M' means the employee
                        // covered HALF of whatever was expected of them that
                        // day — half of a full day normally, half of the
                        // reduced day on a Calendar Half-Day. So a half-day
                        // mark on a declared half-day leaves 0.25 uncovered,
                        // not 0.5.
                        //
                        // Coverage is now SUBTRACTED from the half this mark
                        // leaves uncovered, rather than skipping the day
                        // wholesale when any leave exists. Identical wherever
                        // coverage meets or exceeds the missing half — which is
                        // every case the old all-or-nothing guard handled — but
                        // a partial LWP portion smaller than that half now
                        // correctly leaves the remainder chargeable instead of
                        // waiving the whole thing.
                        $halfDays++;
                        $daysAbsent += max(0.0, ($dayExpected * 0.5) - $dayCoverage);
                    } elseif ($ch === 'W') {
                        // P0-4/A2 — worked on a scheduled off-day. Previously
                        // ignored entirely, so the extra pay this status exists to
                        // trigger was never credited. Counted here and priced in
                        // _compute_payslip(); it does NOT reduce absence.
                        $extraWorkedDays += 1.0;
                    } elseif ($ch === 'V') {
                        $vacantDays++;
                        if ($vacantTreatment === 'absent') {
                            // Scaled like 'A' — an unmarked half-day cannot
                            // cost more than the half day that was expected —
                            // and, since 2026-08-19, it also honours approved
                            // leave. 'V' is the UNMARKED fill, and a half-day
                            // leave deliberately writes no attendance mark, so
                            // this is the branch a covered day actually lands
                            // in. Without the coverage term an approved leave
                            // was charged in full, and a full-day unpaid leave
                            // was charged twice — once as lwpDays by the leave
                            // engine and again as absence here.
                            $daysAbsent += max(0.0, $dayExpected - $dayCoverage);
                        }
                        // 'present', 'ignore', 'block' → don't add to absent here
                    }
                    // 'P' present, 'H' holiday, 'T' late, 'O' weekly-off → no pay
                    // effect. Non-working days are derived by _staff_working_days()
                    // from the holiday calendar + weekly-off policy, so 'H'/'O'
                    // marks are informational here and must NOT be double-counted.

                    // APPROVED PAID LEAVE THAT LEFT NO 'L' MARK (2026-08-20).
                    // The 'L' branch tallies leave-MARKED days, but a half-day
                    // leave deliberately writes no attendance mark — the day
                    // carries 'M', or nothing at all. Its balance is consumed
                    // and leave_details reports it, yet paid_leave_days counted
                    // zero, so the slip contradicted its own breakdown: "Paid
                    // leave 0" beside a leave_details row reading paid 0.5.
                    // The Teacher app gates its Paid-leave row on > 0, so the
                    // row vanished for exactly the employees who had taken it.
                    //
                    // Reporting only — paidLeaveDays is not an input to
                    // _compute_payslip(), so no money moves. Guarded on $ch to
                    // avoid double-counting a day that already had an 'L'.
                    if ($ch !== 'L' && isset($staffPaidSet[$dayNum])) {
                        $paidLeaveDays += (float) $staffPaidSet[$dayNum];
                    }
                }
            } else {
                // No attendance doc at all — every in-window day is vacant
                $vacantDays = $staffWorkingDays;
                if ($vacantTreatment === 'absent') {
                    $daysAbsent = $staffWorkingDays;
                }
            }

            // Payroll-safe block: refuse to generate this slip if any working
            // day is unmarked AND policy is 'block'. Admin must either backfill
            // attendance or change config to an explicit treatment.
            if ($vacantDays > 0 && $vacantTreatment === 'block') {
                $this->_log_payroll_warning('attendance_missing', $staffId,
                    "{$vacantDays} day(s) unmarked — slip skipped (set vacant_treatment to 'absent' or 'present' to override)");
                $payrollWarnings[] = $staffName . ": {$vacantDays} day(s) not marked — slip not generated (admin override required)";
                continue;
            }

            // Add warning if attendance has unmarked days (non-blocking modes)
            if ($vacantDays > 0) {
                $payrollWarnings[] = ($staffName) . ": {$vacantDays} day(s) not marked (treated as {$vacantTreatment})";
            }

            // LWP days for this staff from approved leave requests (float — supports half-day 0.5)
            $staffLwpDays = isset($lwpByStaff[$staffId]) ? (float) $lwpByStaff[$staffId] : 0.0;

            // Payable days = staff_working_days - absent - LWP days
            // Paid leave is counted as present (no salary deduction).
            // Mid-month joiner/exiter: $staffWorkingDays already excludes
            // pre-join / post-exit days, so deductions and base pay are
            // proportional to the employment window.
            if ($daysAbsent > $staffWorkingDays) $daysAbsent = $staffWorkingDays;

            // C1 — all payslip arithmetic (earned wages, statutory/employer
            // deductions on the earned base, voluntary waterfall, net pay) comes
            // from the single shared calculator so this path and
            // regenerate_staff_payroll() can never drift. Pure in-memory call —
            // no Firestore, safe inside this per-employee loop.
            // P0-4/A2 — extra-worked rate is a per-school pay policy read from the
            // existing attendanceRules config doc (absent ⇒ 1.0, i.e. pay the extra
            // day at par). No new config collection is introduced.
            $extraWorkedRate = is_array($attConfig)
                ? (float) ($attConfig['extra_worked_rate'] ?? 1.0)
                : 1.0;
            if ($extraWorkedRate < 0) $extraWorkedRate = 1.0;

            // W3.2.2 — same seam as regenerate_staff_payroll(). Overrides were
            // bulk-read once for the whole run before this loop, so applying
            // them costs no extra Firestore read per employee.
            $extraComponents = Payroll_override_service::toExtraComponents($overridesByStaff[$staffId] ?? []);

            // Same engine-derived baseline as regenerate_staff_payroll(). A run
            // generated while adjustments already exist must still record what
            // it would have paid without them — otherwise the slip carries
            // adjusted figures and no evidence that it was adjusted.
            $baselineCalc = $extraComponents
                ? $this->_compute_payslip($sal, (float) $staffWorkingDays, (float) $daysAbsent, (float) $staffLwpDays, (float) $extraWorkedDays, $extraWorkedRate, $statutoryCfg)
                : null;

            // CA-6: the employee's recorded statutory profile, taken from the
            // roster document ALREADY in memory — no extra read per employee.
            $staffStatProfile = $this->_staff_statutory_facts(
                is_array($roster[$staffId] ?? null) ? $roster[$staffId] : [],
                $statutoryCfg
            );

            $calc = $this->_compute_payslip($sal, (float) $staffWorkingDays, (float) $daysAbsent, (float) $staffLwpDays, (float) $extraWorkedDays, $extraWorkedRate, $statutoryCfg,
                $extraComponents, $staffStatProfile, $periodEndDate);

            $daysWorked         = $calc['days_worked'];
            $deductionDays      = $calc['deduction_days'];
            $absentFraction     = $calc['absent_fraction'];
            $dailySalary        = $calc['daily_salary'];
            $lwpDeduction       = $calc['lwp_deduction'];
            $effectiveBasic     = $calc['basic'];
            $hra                = $calc['hra'];
            $da                 = $calc['da'];
            $ta                 = $calc['ta'];
            $medical            = $calc['medical'];
            $otherAllowances    = $calc['other_allowances'];
            $gross              = $calc['gross'];
            $pfEmployee         = $calc['pf_employee'];
            $pfEmployer         = $calc['pf_employer'];
            $esiEmployee        = $calc['esi_employee'];
            $esiEmployer        = $calc['esi_employer'];
            $tds                = $calc['tds'];
            $professionalTax    = $calc['professional_tax'];
            $otherDeductions    = $calc['other_deductions'];
            $employeeDeductions = $calc['total_deductions'];
            $netPay             = $calc['net_pay'];
            $extraWorkedPay     = $calc['extra_worked_pay'];   // P0-4/A2
            foreach ($calc['warnings'] as $w) { $payrollWarnings[] = $staffName . ': ' . $w; }

            // Classify as Teaching or Non-Teaching for expense accounts
            // Primary: use staff_roles + role category from Config/StaffRoles
            $staffRoles = $profile['staff_roles'] ?? [];
            $isTeaching = false;
            $sawCategory = false;
            if (!empty($staffRoles) && is_array($staffRoles)) {
                if (!isset($_roleDefs)) {
                    try {
                        $schoolDoc3 = $this->fs->get('schools', $this->fs->schoolId());
                        $_roleDefs = (is_array($schoolDoc3) && is_array($schoolDoc3['staffRoles'] ?? null)) ? $schoolDoc3['staffRoles'] : [];
                    } catch (\Exception $e) { $_roleDefs = []; }
                    if (!is_array($_roleDefs)) $_roleDefs = [];
                }
                foreach ($staffRoles as $_rid) {
                    $cat = $_roleDefs[$_rid]['category'] ?? '';
                    if ($cat !== '') {
                        $sawCategory = true;
                        if ($cat === 'Teaching') { $isTeaching = true; break; }
                    }
                }
            }
            // Fallback to Position text when staff has no roles OR the role defs
            // carried no category (old staffRoles shape) — otherwise a teacher
            // would silently book as a Non-Teaching expense.
            if (!$isTeaching && !$sawCategory) {
                $isTeaching = (
                    stripos($position, 'teacher') !== false ||
                    stripos($position, 'lecturer') !== false ||
                    stripos($department, 'teaching') !== false
                );
            }
            // W1.2 — accumulate in EXACT paise. The expense split (Dr side) and
            // the net+statutory credits (Cr side) must still balance after N
            // employees; summing rupee floats is what allows a 1-paise Dr≠Cr
            // imbalance to reach the journal.
            if ($isTeaching) {
                $totalTeachingExpP += $calc['paise']['gross'];
            } else {
                $totalNonTeachingExpP += $calc['paise']['gross'];
            }

            // Track department-wise totals for cost center reporting
            $deptKey = $department ?: 'General';
            if (!isset($deptGross[$deptKey])) $deptGross[$deptKey] = 0;
            $deptGross[$deptKey] += $gross;

            $slips[$staffId] = [
                'staff_id'         => $staffId,
                'staff_name'       => $staffName,
                'department'       => $department,
                // Month context — needed for teacher app orderBy('month') and cross-system querying
                'month'            => $month,
                'month_key'        => $monthKey,
                'year'             => (string) $year,
                'run_id'           => $runId,
                'basic'            => $effectiveBasic,
                'hra'              => $hra,
                'da'               => $da,
                'ta'               => $ta,
                'medical'          => $medical,
                'other_allowances' => $otherAllowances,
                'gross'            => $gross,
                'pf_employee'      => $pfEmployee,
                'pf_employer'      => $pfEmployer,
                'esi_employee'     => $esiEmployee,
                'esi_employer'     => $esiEmployer,
                'professional_tax' => $professionalTax,
                'tds'              => $tds,
                'other_deductions' => $otherDeductions,
                'total_deductions' => $employeeDeductions,
                'net_pay'          => $netPay,
                'days_worked'      => $daysWorked,
                'days_absent'      => $daysAbsent,
                'paid_leave_days'  => $paidLeaveDays,
                'leave_days'       => $paidLeaveDays + $unpaidLeaveDays,
                'lwp_days'         => $staffLwpDays,
                'lwp_deduction'    => $lwpDeduction,
                'working_days'     => $staffWorkingDays,
                // P0-3/A1 + P0-4/A2 — attendance-derived day accounting that was
                // previously invisible to payroll. half_days is informational
                // (its 0.5 pay effect is already inside days_absent);
                // extra_worked_pay is a real earning included in gross.
                'half_days'        => $halfDays,
                'extra_worked_days'=> $extraWorkedDays,
                'extra_worked_pay' => $extraWorkedPay,
                'staff_window'     => [
                    'startDay' => $swd['startDay'],
                    'endDay'   => $swd['endDay'],
                    'joinDate' => $jd,
                    'exitDate' => $xd,
                ],
                // ── W3.2.1 — attendance day-wise reproducibility snapshot ──
                //
                // The slip already froze the attendance RESULT (days_absent,
                // half_days, lwp_days, …). What it did not freeze was the
                // EVIDENCE those numbers were derived from, so a later
                // attendance correction changed what a regeneration would
                // produce with no record of the original inputs — you could see
                // `days_absent: 3` but never which three days.
                //
                // This freezes the three inputs that, together with the scalars
                // already on the slip, fully determine the attendance side of
                // the calculation. They are positional strings of the same
                // length, indexed day-1 = offset-0 — the same convention
                // `dayWise` already uses, so this introduces no second encoding
                // and no second attendance system. Payroll consumes Attendance
                // and freezes the payroll-relevant result; the authoritative
                // Attendance architecture is untouched.
                '_attendance_snapshot' => [
                    'version'          => 1,
                    'month_key'        => $monthKey,
                    // The attendance string exactly as read. '' means no
                    // attendance document existed — which is a different fact
                    // from "all present", and the vacant treatment below is
                    // what decided how it was priced.
                    'day_wise'         => $attStr,
                    'source'           => $attSnapSource,
                    // '1' = inside the employment window. Stored as a per-day
                    // mask rather than re-derived from startDay/endDay so a
                    // non-contiguous window (rehire) reproduces correctly.
                    'window_mask'      => self::_att_window_mask($staffInWindow, $daysInMonth),
                    // '-' none · 'F' full-day LWP · 'H' half-day LWP.
                    'lwp_mask'         => self::_att_lwp_mask($staffLwpSet, $daysInMonth),
                    // The policy that priced unmarked days, and the calendar
                    // basis the working-day count came from.
                    'vacant_treatment' => $vacantTreatment,
                    'working_days'     => $staffWorkingDays,
                    // HISTORICAL SELF-DESCRIPTION. Without this a stored slip
                    // cannot state whether Calendar Half-Day pay was in force
                    // when it was produced: the reduced working_days implies it,
                    // but only to a reader who already knows that month's
                    // calendar. Freezing the weight lets a reviewer tell "the
                    // flag was off" apart from "there was no half-day that
                    // month", years later. 1.0 = not applied.
                    'calendar_half_day_weight' => $halfDayWeight,
                    'days_in_month'    => $daysInMonth,
                    'holiday_days'     => array_map('intval', array_keys($holidays)),
                    // ── N-23/N-30 §6 — the CALENDAR this slip was priced against ──
                    //
                    // Same positional convention as day_wise: '1' working,
                    // '0' non-working, '-' outside the employment window.
                    // Frozen here so a later policy change cannot alter the
                    // calendar evidence of an already-generated slip — the same
                    // guarantee W3.2.1 gave attendance, now extended to the
                    // working week itself.
                    'working_mask'          => (string) ($swd['workingMask'] ?? ''),
                    'policy_version'        => $swd['policyVersion'] ?? null,
                    'policy_effective_from' => (string) ($swd['policyEffectiveFrom'] ?? ''),
                    'policy_source'         => (string) ($swd['policySource'] ?? ''),
                    // WHOSE calendar priced this slip (N-32/N-33). Frozen so a
                    // later reassignment cannot reinterpret a closed period.
                    'schedule_id'               => (string) ($swd['scheduleId'] ?? ''),
                    'schedule_name'             => (string) ($swd['scheduleName'] ?? ''),
                    'assignment_effective_from' => (string) ($swd['assignmentEffectiveFrom'] ?? ''),
                    // The counts the calculation actually used. Redundant on
                    // purpose: an auditor can re-derive these from the masks
                    // above and confirm the two agree. A divergence means the
                    // snapshot and the payslip disagree, which is exactly the
                    // condition worth being able to detect.
                    'derived'          => [
                        'days_absent'       => $daysAbsent,
                        'half_days'         => $halfDays,
                        'extra_worked_days' => $extraWorkedDays,
                        'paid_leave_days'   => $paidLeaveDays,
                        'unpaid_leave_days' => $unpaidLeaveDays,
                        'vacant_days'       => $vacantDays,
                    ],
                ],
                // W3.2.2 — identical shape to regenerate_staff_payroll()'s block.
                // null when unadjusted, so an adjusted run that nets to zero is
                // still distinguishable from one that was never adjusted.
                '_payroll_adjustments' => $extraComponents ? [
                    'version'        => 1,
                    'count'          => count($extraComponents),
                    'lines'          => array_map(function ($xc) {
                        return [
                            'override_id' => $xc['code'],
                            'type'        => $xc['type']  ?? '',
                            'label'       => $xc['label'] ?? '',
                            'kind'        => $xc['kind'],
                            'amount'      => self::_toRupees($xc['amountPaise']),
                            'prorated'    => !empty($xc['prorated']),
                        ];
                    }, $extraComponents),
                    'original_gross' => $baselineCalc['gross']   ?? null,
                    'original_net'   => $baselineCalc['net_pay'] ?? null,
                    'final_gross'    => $calc['gross'],
                    'final_net'      => $calc['net_pay'],
                    'applied_at'     => date('c'),
                    'applied_by'     => $this->admin_name ?: $this->admin_id,
                ] : null,
                // ── W3.2.4 — frozen calculation trace ──
                //
                // A verbatim copy of what the engine RETURNED. Nothing here is
                // recomputed, reformatted or re-rounded — `paise` is the exact
                // integer arithmetic the payslip was built from, so a trace can
                // be checked against the stored slip to the paisa rather than
                // to a tolerance that would hide a one-paisa drift.
                //
                // Frozen for the same reason as the other snapshots: a payslip
                // must stay explainable after the salary structure, statutory
                // policy or component catalogue change.
                '_calculation_trace' => [
                    'version'          => 1,
                    'engine'           => '_compute_payslip',
                    'statutory_source' => $calc['statutory_source'] ?? 'structure',
                    'bases'            => $calc['bases']    ?? [],
                    'paise'            => $calc['paise']    ?? [],
                    'deferred'         => $calc['deferred'] ?? [],
                    'warnings'         => $calc['warnings'] ?? [],
                    'computed_at'      => date('c'),
                ],
                'daily_salary'     => $dailySalary,
                'deduction_days'   => $deductionDays,
                'deduction_reason' => $staffLwpDays > 0
                    ? "{$staffLwpDays} LWP day(s) deducted at {$dailySalary}/day = {$lwpDeduction}"
                    : ($daysAbsent > 0 ? "{$daysAbsent} absent day(s) deducted" : 'No deduction'),
                'leave_details'    => $leaveDetailStaff[$staffId] ?? [],
                'vacant_days'      => $vacantDays,
                'vacant_treatment' => $vacantTreatment,
                'att_warning'      => $vacantDays > 0
                    ? "Attendance incomplete: {$vacantDays} day(s) not marked (treated as {$vacantTreatment})"
                    : '',
                'status'           => 'Draft',
                // Snapshot: freeze the salary structure used for this payslip
                // Future edits to Salary_Structures won't retroactively change this slip
                '_salary_snapshot' => [
                    'basic' => (float) ($sal['basic'] ?? 0),
                    'hra' => (float) ($sal['hra'] ?? 0), 'da' => (float) ($sal['da'] ?? 0),
                    'ta' => (float) ($sal['ta'] ?? 0), 'medical' => (float) ($sal['medical'] ?? 0),
                    'other_allowances' => (float) ($sal['other_allowances'] ?? 0),
                    'pf_employee' => (float) ($sal['pf_employee'] ?? 0), 'esi_employee' => (float) ($sal['esi_employee'] ?? 0),
                    'professional_tax' => (float) ($sal['professional_tax'] ?? 0), 'tds' => (float) ($sal['tds'] ?? 0),
                    'source' => $sal['source'] ?? '', '_version' => $sal['_version'] ?? 1,
                ],
            ];

            // W1.2 — exact paise accumulation (see the expense-split note above).
            $totalGrossP      += $calc['paise']['gross'];
            $totalDeductionsP += $calc['paise']['total_deductions'];
            $totalNetP        += $calc['paise']['net_pay'];
            $totalPFP         += $calc['paise']['pf_employee'];
            $totalESIP        += $calc['paise']['esi_employee'];
            $totalTDSP        += $calc['paise']['tds'];
            $totalProfTaxP    += $calc['paise']['professional_tax'];
            $totalOtherDedP   += $calc['paise']['other_deductions'];
            $staffCount++;
        }

        if ($staffCount === 0) {
            // PAY-DIAG-1: the loop above records exactly why it skipped each
            // person ("no salary data", "corrupt salary data", …) in
            // $payrollWarnings, and this used to throw all of it away — leaving
            // the operator with "no staff with salary structures" and no way to
            // find out which employee or which reason. The information already
            // exists; withholding it just makes payroll undiagnosable.
            // json_error() takes (message, http_code) only — the reasons go in
            // the sentence itself, capped so one bad roster cannot produce an
            // unreadable wall of text.
            $detail = array_slice($payrollWarnings, 0, 10);
            $more   = count($payrollWarnings) - count($detail);

            $this->json_error(
                'No staff with salary structures found in the roster.'
                . ($detail !== [] ? ' Skipped: ' . implode('; ', $detail) : '')
                . ($more > 0 ? sprintf(' (and %d more)', $more) : ''),
                400
            );
        }

        // W1.2 — single conversion boundary for the run totals. Because the
        // accumulators above are exact integers, the journal identity
        //   (teachingExp + nonTeachingExp) == (net + PF + ESI + TDS + PT + other)
        // holds exactly in paise, and converting each side once preserves it.
        $totalGross          = self::_toRupees($totalGrossP);
        $totalDeductions     = self::_toRupees($totalDeductionsP);
        $totalNet            = self::_toRupees($totalNetP);
        $totalPF             = self::_toRupees($totalPFP);
        $totalESI            = self::_toRupees($totalESIP);
        $totalTDS            = self::_toRupees($totalTDSP);
        $totalProfTax        = self::_toRupees($totalProfTaxP);
        $totalOtherDed       = self::_toRupees($totalOtherDedP);
        $totalTeachingExp    = self::_toRupees($totalTeachingExpP);
        $totalNonTeachingExp = self::_toRupees($totalNonTeachingExpP);

        // N-31/N-33 — run-level working days: the shared value when every
        // priced employee agrees, otherwise 0 meaning "varies by schedule".
        // Reporting one schedule's number as if it applied to everyone would be
        // wrong the moment two schedules exist.
        // Fraction-safe (2026-08-19): read the VALUE, not the key, and do not
        // cast to int — a Calendar Half-Day makes this legitimately 21.5.
        $workingDays = (count($employeeWorkingSet) === 1)
            ? (float) reset($employeeWorkingSet)
            : 0;

        // The 0 above is a SENTINEL meaning "varies by schedule", not a real
        // count of zero working days. That distinction was previously carried
        // only by a comment, and Calendar Half-Day makes the varying case far
        // more common (any month where one department has a half-day and
        // another does not). Stating it as data means a future consumer cannot
        // silently render the sentinel as an employee's working days.
        //
        // Verified at the time of writing: no view, export or app reads the
        // run-level working_days — every surface reads the SLIP-level value,
        // which is per-employee and correct.
        $workingDaysVaries = (count($employeeWorkingSet) !== 1);

        // Create payroll run record
        $runData = [
            'month'            => $month,
            'year'             => $year,
            'status'           => 'Draft',
            'total_gross'      => $totalGross,
            'total_deductions' => $totalDeductions,
            'total_net'        => $totalNet,
            'staff_count'      => $staffCount,
            'working_days'     => $workingDays,
            'working_days_varies' => $workingDaysVaries,
            'days_in_month'    => $daysInMonth,
            'sundays'          => $sundayCount,
            'off_saturdays'    => $offSatCount,
            'holidays'         => $holidayCount,
            'created_at'       => date('c'),
            'created_by'       => $this->admin_name,
            'finalized_at'     => '',
            'finalized_by'     => '',
            'paid_at'          => '',
            'paid_by'          => '',
            'payment_mode'     => '',
            'journal_id'       => '',
            'total_paid'       => 0,
            'balance_due'      => $totalNet,
            'warnings'         => $payrollWarnings,
            'dept_breakdown'   => $deptGross,

            // Feature 7: what KIND of run this is, and — for an off-cycle run —
            // exactly who it covered. Recorded so a later reader can tell a
            // three-person arrears run from a monthly payroll that happened to
            // have three staff.
            'runType'          => $runType,
            'runScope'         => $runType === self::RUN_TYPE_OFFCYCLE
                ? ['staffIds' => array_keys($roster), 'count' => count($roster)]
                : ['staffIds' => [], 'count' => count($roster)],

            // S-1: the statutory policy this run was priced against, frozen
            // with the run. Without it a configuration created in September
            // would leave July's payslips unexplainable — the figures would be
            // right but nothing would record WHICH rules produced them.
            //
            // Stored even when no configuration exists, because "there was no
            // configuration" is itself the fact that explains the numbers.
            'statutory_snapshot' => $this->_statutory_snapshot($statutoryCfg),
        ];

        // Engine PRE-FLIGHT before any operational record is written: verify
        // every accrual posting account exists + is active AND the accounting
        // period is open; any failure hard-blocks (json_error) BEFORE the run or
        // slips are written, so no orphan operational record can be left behind.
        $accrualJournalDate = date('Y-m-d');
        $this->_preflight_engine_accrual(
            $totalTeachingExp, $totalNonTeachingExp,
            $totalPF, $totalESI, $totalTDS, $totalProfTax, $totalOtherDed
        );

        // Accrual transactional outbox (Phase 1): persist the DURABLE accrual
        // intent + the exact figures the reconciler needs to re-post it INTO the
        // run doc written just below — BEFORE the journal is posted. A failure
        // between here and the post leaves the run at accrual_journal_status
        // 'pending', which the PayrollReconciler re-posts idempotently. Rides the
        // SAME run write — no extra Firestore op.
        require_once APPPATH . 'services/PayrollAccountingService.php';
        $runData['accrual_journal_id']     = PayrollAccountingService::accrualJournalIdFor($runId);
        $runData['accrual_journal_status'] = 'pending';
        $runData['accrual_journal_date']   = $accrualJournalDate;
        $runData['accrual_payload']        = [
            'total_teaching_exp'     => $totalTeachingExp,
            'total_non_teaching_exp' => $totalNonTeachingExp,
            'total_net'              => $totalNet,
            'total_pf'               => $totalPF,
            'total_esi'              => $totalESI,
            'total_tds'              => $totalTDS,
            'total_prof_tax'         => $totalProfTax,
            'total_other_ded'        => $totalOtherDed,
        ];

        // 1. Firestore FIRST — sync payroll run + slips
        //
        // W3.2.5: slips are written in BATCHES instead of one round-trip per
        // employee. This was the entire scale bottleneck — every input was
        // already bulk-read in a single query, so the run's cost was N
        // sequential writes and nothing else. Same documents, same merge
        // semantics, same builder; only the number of round-trips changes.
        $this->_fsSyncPayrollRun($runId, $runData);
        $batchResult = $this->_fsSyncPayslipsBatch($runId, $slips);
        if (!empty($batchResult['failed'])) {
            // Named, not counted — an operator who is told "12 slips failed"
            // cannot act, and a silently dropped slip is an unpaid employee.
            foreach ($batchResult['failed'] as $failedDocId) {
                $payrollWarnings[] = "Payslip write failed for {$failedDocId} — regenerate this employee.";
            }
        }

        // ── Post the accrual journal through the canonical engine ───────
        // postRunAccrual builds the lines/narration/source ('HR_Payroll')/
        // voucher ('Journal') internally and posts idempotently
        // (JE_HR_PAYROLL_<runId>) with Dr=Cr + account + period-lock guards and
        // an atomic CAS balance batch → accountingClosingBalances.
        $payAcct = new PayrollAccountingService();
        $payAcct->init($this->firebase, $this->school_id, $this->session_year, $this->admin_id, $this);
        $journalId = (string) $payAcct->postRunAccrual([
            'run_id'                 => $runId,
            'month'                  => $month,
            'year'                   => $year,
            'journal_date'           => $accrualJournalDate,
            'total_teaching_exp'     => $totalTeachingExp,
            'total_non_teaching_exp' => $totalNonTeachingExp,
            'total_net'              => $totalNet,
            'total_pf'               => $totalPF,
            'total_esi'              => $totalESI,
            'total_tds'              => $totalTDS,
            'total_prof_tax'         => $totalProfTax,
            'total_other_ded'        => $totalOtherDed,
        ]);

        // If the accrual post did not confirm, the run is still created and
        // DURABLE at accrual_journal_status='pending'; the reconciler posts it
        // automatically (exactly once, deterministic id). Surface as a warning.
        if ($journalId === '') {
            $payrollWarnings[] = 'Accrual journal deferred — it will be posted automatically by the payroll reconciler.';
            $this->_log_payroll_warning('accrual_deferred', $runId,
                'postRunAccrual returned empty — accrual outbox left pending for reconciler recovery');
        }

        // Store expense journal ID in run (Firestore). This same write is the
        // accrual-outbox Phase-2 flip: mark the accrual 'posted' only when the
        // engine confirmed a journal id; otherwise leave it 'pending' so the
        // reconciler re-posts idempotently. One write — no extra Firestore op.
        $runPatch = [
            'expense_journal_id'     => $journalId,
            'warnings'               => $payrollWarnings,
            'accrual_journal_status' => ($journalId !== '') ? 'posted' : 'pending',
        ];
        try {
            $this->_fsSyncPayrollRun($runId, array_merge($runData, $runPatch));
        } catch (\Exception $e) {}

        $this->_log_payroll('generated', $runId, [
            'month' => $month, 'year' => $year,
            'staff_count' => $staffCount, 'total_net' => $totalNet,
            'journal_id' => $journalId,
        ]);
        // Run creation — the point at which a payroll liability first exists.
        // Run-level aggregates only; no per-employee figures.
        $this->_audit_payroll('payroll_generated', $runId,
            "Payroll generated for {$month} {$year}: {$staffCount} staff",
            [
                'month'        => (string) $month,
                'year'         => (string) $year,
                'toStatus'     => 'Draft',
                'staffCount'   => $staffCount,
                'totalNet'     => $totalNet,
                'journalId'    => $journalId,
                'warningCount' => count($payrollWarnings),
            ]);

        $msg = "Payroll generated for {$month} {$year}: {$staffCount} staff, Net: {$totalNet}.";
        if (!empty($payrollWarnings)) {
            $msg .= ' (' . count($payrollWarnings) . ' warning(s))';
        }

        $this->json_success([
            'id'       => $runId,
            'message'  => $msg,
            'warnings' => $payrollWarnings,
            'summary'  => [
                'total_gross'      => $totalGross,
                'total_deductions' => $totalDeductions,
                'total_net'        => $totalNet,
                'staff_count'      => $staffCount,
                'working_days'     => $workingDays,
                'journal_id'       => $journalId,
            ],
        ]);
    }

    // ═════════════════════════════════════════════════════════════════
    //  W3.2.3 — LOP REVIEW / CONFIRMATION
    // ═════════════════════════════════════════════════════════════════

    /**
     * GET — run-level loss-of-pay review.
     *
     * WHY THIS EXISTS
     * ---------------
     * Every fact below was already stored per slip, but only reachable by
     * opening one employee at a time. An operator finalising a 200-person run
     * had no way to answer "who is losing pay this month, and why" without 200
     * clicks — so in practice the deduction was approved unseen.
     *
     * READS FROZEN EVIDENCE, NOT LIVE ATTENDANCE
     * ------------------------------------------
     * Everything comes from the slip: the W3.2.1 `_attendance_snapshot`
     * (`lwp_mask` — which days, full or half) and `leave_details` (which
     * request, which type, paid or not). Re-deriving from live Attendance would
     * show a review that does not match the payslip the moment anyone corrects
     * an attendance record — which is exactly the drift the snapshot exists to
     * prevent.
     *
     * NO SECOND LEAVE ENGINE
     * ----------------------
     * Nothing here recomputes LOP. `_process_leave_for_payroll()` remains the
     * single resolver shared by generate and regenerate; this reports what it
     * decided.
     */
    public function lop_review(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'lop_review', 'HR', 'view');

        $runId = trim((string) ($this->input->get('run_id') ?? ''));
        if ($runId === '') $this->json_error('run_id is required.');

        $run = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$runId}"));
        if (!is_array($run) || empty($run)) $this->json_error('Payroll run not found.');

        $slips = $this->_fsGetAllSlips($runId);

        $rows = [];
        $totalLwpDays = 0.0;
        $totalDeduction = 0.0;
        $missingSnapshot = 0;

        foreach ($slips as $staffId => $s) {
            if (!is_array($s)) continue;

            $lwpDays    = (float) ($s['lwp_days'] ?? 0);
            $deduction  = (float) ($s['lwp_deduction'] ?? 0);
            $leaveDet   = is_array($s['leave_details'] ?? null) ? $s['leave_details'] : [];

            // Half-days live on the leave document, not on the attendance mark.
            $halfFromLeave = 0;
            foreach ($leaveDet as $ld) {
                if (is_array($ld) && !empty($ld['half_day'])) $halfFromLeave++;
            }

            if ($lwpDays <= 0 && $halfFromLeave === 0) continue;   // no loss of pay

            // Day-level detail from the frozen mask. Absent on slips generated
            // before W3.2.1 — reported as such rather than reconstructed from
            // today's attendance, which would be a fabricated history.
            $snap = is_array($s['_attendance_snapshot'] ?? null) ? $s['_attendance_snapshot'] : null;
            require_once APPPATH . 'helpers/payroll_snapshot_helper.php';
            $decoded  = payroll_lwp_days_from_mask($snap['lwp_mask'] ?? null);
            $fullDays = $decoded['full'];
            $halfDays = $decoded['half'];
            if ($snap === null) $missingSnapshot++;

            $sources = [];
            foreach ($leaveDet as $ld) {
                if (!is_array($ld)) continue;
                if (empty($ld['lwp_days']) && empty($ld['half_day'])) continue;
                $sources[] = [
                    'request_id' => (string) ($ld['request_id'] ?? ''),
                    'type_name'  => (string) ($ld['type_name'] ?? ''),
                    'type_code'  => (string) ($ld['type_code'] ?? ''),
                    'paid_type'  => !empty($ld['paid']),
                    'from'       => (string) ($ld['from'] ?? ''),
                    'to'         => (string) ($ld['to'] ?? ''),
                    // Fraction safety (2026-08-19): this fed the
                    // `balance_exhausted` flag below, which exists precisely
                    // so an operator does not report exhausted-balance LOP as
                    // a bug. (int) floored a 0.5-day LOP to 0, so the flag
                    // never fired for exactly the fractional case it was
                    // meant to explain.
                    'lwp_days'   => (float) ($ld['lwp_days'] ?? 0),
                    'half_day'   => !empty($ld['half_day']),
                    'pay_label'  => (string) ($ld['pay_label'] ?? ''),
                ];
            }

            // A paid leave type that still produced LOP means the balance was
            // exhausted when the leave was approved. Operators consistently
            // read that as a bug, so it is labelled rather than left to be
            // rediscovered.
            $balanceExhausted = false;
            foreach ($sources as $src) {
                if ($src['paid_type'] && $src['lwp_days'] > 0) { $balanceExhausted = true; break; }
            }

            $rows[] = [
                'staff_id'          => $staffId,
                'staff_name'        => (string) ($s['staff_name'] ?? $staffId),
                'department'        => (string) ($s['department'] ?? ''),
                'working_days'      => (float) ($s['working_days'] ?? 0),
                'days_worked'       => (float) ($s['days_worked'] ?? 0),
                'lwp_days'          => $lwpDays,
                'lwp_full_days'     => $fullDays,
                'lwp_half_days'     => $halfDays,
                'daily_salary'      => (float) ($s['daily_salary'] ?? 0),
                'lwp_deduction'     => $deduction,
                'net_pay'           => (float) ($s['net_pay'] ?? 0),
                'sources'           => $sources,
                'balance_exhausted' => $balanceExhausted,
                'day_detail_available' => $snap !== null,
            ];

            $totalLwpDays   += $lwpDays;
            $totalDeduction += $deduction;
        }

        usort($rows, function ($a, $b) {
            if ($a['lwp_deduction'] === $b['lwp_deduction']) {
                return strcasecmp($a['staff_name'], $b['staff_name']);
            }
            return $b['lwp_deduction'] <=> $a['lwp_deduction'];   // largest loss first
        });

        $confirmation = is_array($run['lop_confirmation'] ?? null) ? $run['lop_confirmation'] : null;

        $this->json_success([
            'run_id'            => $runId,
            // See payslip_trace(): a 'status' key would overwrite the
            // json_success() envelope and break every caller's success check.
            'run_status'        => (string) ($run['status'] ?? ''),
            'affected'          => $rows,
            'affected_count'    => count($rows),
            'total_lwp_days'    => $totalLwpDays,
            'total_deduction'   => $totalDeduction,
            'missing_day_detail'=> $missingSnapshot,
            'confirmation'      => $confirmation,
            'confirmed'         => $confirmation !== null,
            // Stated by the server so the UI cannot invent its own advice: LOP
            // is derived from Attendance and Leave, and the correction belongs
            // there. A payroll deduction that "fixes" LOP would make payroll a
            // second attendance system and leave the two disagreeing forever.
            'correction_guidance' => 'LOP is derived from approved leave and attendance. '
                . 'To change it, correct the leave request or the attendance record and then regenerate '
                . 'this employee. Do not use a payroll adjustment to offset LOP — that would leave '
                . 'payroll and attendance permanently disagreeing.',
        ]);
    }

    /**
     * GET — the full calculation trace for ONE employee's payslip (W3.2.4).
     *
     * Answers "why did this employee receive this exact payslip" from FROZEN
     * evidence only: the salary snapshot, the attendance snapshot, the
     * adjustments block and the calculation trace the engine itself produced.
     *
     * ONE READ. The whole trace is assembled from a single payslip document —
     * no per-step query, nothing fetched from the compensation, attendance,
     * leave or statutory collections. That is both the performance property and
     * the correctness one: a trace that consulted today's configuration would
     * stop explaining the payslip the moment any of it changed.
     *
     * Assembly is delegated to `payroll_build_trace()`, a pure function, so the
     * chain is unit-testable and this controller adds no arithmetic.
     */
    public function payslip_trace(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'payslip_trace', 'HR', 'view');

        $runId   = trim((string) ($this->input->get('run_id') ?? ''));
        $staffId = trim((string) ($this->input->get('staff_id') ?? ''));
        if ($runId === '' || $staffId === '') $this->json_error('run_id and staff_id are required.');

        // docId() prefixes the caller's own tenant, so a foreign run/staff pair
        // resolves to a document that does not exist rather than another
        // tenant's payslip.
        $slip = $this->fs->get('salarySlips', $this->fs->docId("SLIP_{$runId}_{$staffId}"));
        if (!is_array($slip) || empty($slip)) $this->json_error('Payslip not found in this run.');

        $owner = (string) ($slip['schoolId'] ?? '');
        if ($owner !== '' && $owner !== $this->school_id) {
            log_message('error', "PAYSLIP_TRACE_TENANT_MISMATCH run={$runId} staff={$staffId} owner={$owner}");
            $this->json_error('Payslip not found in this run.');
        }

        require_once APPPATH . 'helpers/payroll_snapshot_helper.php';
        require_once APPPATH . 'helpers/payroll_trace_helper.php';

        $trace = payroll_build_trace($slip);

        // S-1: the statutory policy is frozen on the RUN, not copied onto
        // every slip — a 200-employee run would otherwise carry 200 identical
        // copies of it. One extra read here keeps the run the single
        // authority for run-level policy.
        $run = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$runId}"));
        if (!is_array($run)) $run = [];

        $this->json_success([
            'run_id'      => $runId,
            'staff_id'    => $staffId,
            'staff_name'  => (string) ($slip['staff_name'] ?? $staffId),
            // NOT 'status': json_success() merges ['status' => 'success'] with
            // this payload, and a 'status' key here OVERWRITES the envelope —
            // so every caller's `r.status === 'success'` check failed and the
            // trace never loaded. Found in UAT on the first real click.
            'slip_status' => (string) ($slip['status'] ?? ''),
            'steps'       => $trace['steps'],
            'warnings'    => $trace['warnings'],
            'consistency' => $trace['consistency'],
            'evidence'    => $trace['evidence'],
            // S-1 (2026-08-22): the run now carries the statutory policy it was
            // priced against, so this reports what was actually frozen instead
            // of asserting that freezing is impossible.
            //
            // Read from the RUN, not re-resolved: re-resolving here would
            // answer "what would apply today", which is precisely the question
            // a reproducibility trace must not answer.
            'statutory_snapshot' => is_array($run['statutory_snapshot'] ?? null)
                ? $run['statutory_snapshot']
                : null,
            'statutory_snapshot_status' => is_array($run['statutory_snapshot'] ?? null)
                ? (!empty($run['statutory_snapshot']['configured'])
                    ? 'FROZEN — priced against the tenant statutory configuration effective '
                      . (string) ($run['statutory_snapshot']['effectiveFrom'] ?? '?') . '.'
                    : 'NO TENANT POLICY — rates came from the salary structure. That is itself '
                      . 'reproducible, because it is recorded.')
                : 'PRE-DATES SNAPSHOTTING — this run was generated before the statutory policy '
                  . 'was frozen with runs, so the policy in force at the time is not recorded.',
        ]);
    }

    /**
     * POST — confirm that the run's loss-of-pay has been reviewed.
     *
     * Records WHO accepted the deductions and when. This is the sign-off
     * `finalize_payroll()` now requires when a run deducts pay, so an unreviewed
     * LOP deduction cannot be finalised silently.
     *
     * The confirmation is bound to the exact figures reviewed. If a
     * regeneration later changes the LOP total, the stored figures no longer
     * match and the confirmation is treated as stale — otherwise a sign-off on
     * ₹2,000 of LOP would silently authorise ₹40,000.
     */
    public function confirm_lop(): void
    {
        $this->_require_role(self::ADMIN_ROLES, 'confirm_lop', 'HR', 'manage');

        $runId = trim((string) $this->input->post('run_id'));
        $note  = trim((string) $this->input->post('note'));
        if ($runId === '') $this->json_error('run_id is required.');

        $run = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$runId}"));
        if (!is_array($run) || empty($run)) $this->json_error('Payroll run not found.');
        if (($run['status'] ?? '') !== 'Draft') {
            $this->json_error('Only a Draft run can be reviewed. A finalized run is already frozen.');
        }

        [$lwpDays, $deduction, $affected] = $this->_lop_totals($runId);

        $confirmation = [
            'confirmed_by'      => $this->admin_name ?: $this->admin_id,
            'confirmed_at'      => date('c'),
            'note'              => $note,
            // The figures this sign-off actually covers.
            'lwp_days'          => $lwpDays,
            'lwp_deduction'     => $deduction,
            'affected_staff'    => $affected,
        ];

        $this->fs->set('salarySlips', $this->fs->docId("RUN_{$runId}"),
            ['lop_confirmation' => $confirmation], true);

        log_message('info', "PAYROLL_LOP_CONFIRMED school={$this->school_id} run={$runId} "
            . "staff={$affected} days={$lwpDays} deduction={$deduction} by={$this->admin_name}");
        // W3.2.3 makes this sign-off the on-the-record authorisation for
        // deducting pay, and finalize_payroll() refuses without it — so it
        // belongs in the permanent audit trail, not only in a log line.
        $this->_audit_payroll('payroll_lop_confirmed', $runId,
            "Loss of pay confirmed: {$lwpDays} day(s) across {$affected} employee(s)",
            [
                'lwpDays'       => $lwpDays,
                'lwpDeduction'  => $deduction,
                'affectedStaff' => $affected,
                'note'          => $note,
            ]);

        $this->json_success(['confirmation' => $confirmation],
            'Loss of pay reviewed and confirmed.');
    }

    /**
     * Current LOP totals for a run, from the frozen slips.
     *
     * @return array{0:float,1:float,2:int} [lwpDays, deduction, affectedStaff]
     */
    private function _lop_totals(string $runId): array
    {
        $days = 0.0; $ded = 0.0; $n = 0;
        foreach ($this->_fsGetAllSlips($runId) as $s) {
            if (!is_array($s)) continue;
            $d = (float) ($s['lwp_days'] ?? 0);
            if ($d <= 0) continue;
            $days += $d;
            $ded  += (float) ($s['lwp_deduction'] ?? 0);
            $n++;
        }
        return [$days, $ded, $n];
    }

    /**
     * GET — Get payroll slips for a run. ?run_id=PR0001
     */
    public function get_payroll_slips()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_payroll_slips', 'HR', 'manage');

        $runId = trim($this->input->get('run_id') ?? '');
        if ($runId === '') {
            $this->json_error('Payroll run ID is required.');
        }
        $runId = $this->safe_path_segment($runId, 'run_id');

        // ── Firestore-first: get run ──
        $run = null;
        try {
            $fsRun = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$runId}"));
            if (is_array($fsRun) && !empty($fsRun)) $run = $fsRun;
        } catch (\Exception $e) {
            log_message('error', "HR get_payroll_slips FS run failed: " . $e->getMessage());
        }
        if (!is_array($run)) {
            $run = $this->_fsGetRun($runId);
        }
        if (!is_array($run)) {
            $this->json_error('Payroll run not found.');
        }

        // ── Firestore-first: get slips for this run ──
        $list = [];
        try {
            $fsDocs = $this->fs->schoolWhere('salarySlips', [
                ['type', '==', 'payslip'],
                ['runId', '==', $runId],
            ]);
            if (!empty($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $s = $doc['data'];
                    $s['staff_id'] = $s['staffId'] ?? '';
                    $list[] = $s;
                }
            }
        } catch (\Exception $e) {
            log_message('error', "HR get_payroll_slips FS slips failed: " . $e->getMessage());
        }

        // Sort by staff name
        usort($list, function ($a, $b) {
            return strcmp($a['staff_name'] ?? '', $b['staff_name'] ?? '');
        });

        $this->json_success([
            'run'   => array_merge($run, ['id' => $runId]),
            'slips' => $list,
        ]);
    }

    /**
     * POST — Finalize a payroll run (lock it from edits).
     * Params: run_id
     */
    public function finalize_payroll()
    {
        $this->_require_role(self::ADMIN_ROLES, 'finalize_payroll', 'HR', 'manage');

        $runId = $this->safe_path_segment(trim($this->input->post('run_id') ?? ''), 'run_id');

        $run = $this->_fsGetRun($runId);
        if (!is_array($run)) {
            $this->json_error('Payroll run not found.');
        }
        if (($run['status'] ?? '') !== 'Draft') {
            $this->json_error('Only Draft payroll runs can be finalized.');
        }

        // ── W3.2.3 — loss of pay must be reviewed before it is finalised ──
        //
        // A run that deducts pay for LOP cannot be frozen until someone has
        // said, on the record, that the deductions are correct. Before this
        // gate the deduction was approved implicitly by whoever clicked
        // Finalize, with nothing stored about whether it had been looked at.
        //
        // The gate applies ONLY when the run actually deducts pay, so runs with
        // no LOP finalize exactly as before.
        //
        // The confirmation is bound to the figures it covered: if a
        // regeneration changed the LOP after sign-off, the old confirmation is
        // stale and must be renewed. Otherwise a sign-off on a small deduction
        // would silently authorise a much larger one.
        [$lopDays, $lopDeduction, $lopStaff] = $this->_lop_totals($runId);
        if ($lopDays > 0) {
            $conf = is_array($run['lop_confirmation'] ?? null) ? $run['lop_confirmation'] : null;

            if ($conf === null) {
                $this->json_error(
                    "This run deducts pay for {$lopDays} LOP day(s) across {$lopStaff} employee(s) "
                    . "(total {$lopDeduction}). Review and confirm loss of pay before finalizing.",
                    409
                );
            }

            $sameDays = abs(((float) ($conf['lwp_days'] ?? -1)) - $lopDays) < 0.001;
            $sameDed  = abs(((float) ($conf['lwp_deduction'] ?? -1)) - $lopDeduction) < 0.005;
            if (!$sameDays || !$sameDed) {
                $this->json_error(
                    'Loss of pay changed after it was confirmed (confirmed '
                    . ($conf['lwp_days'] ?? '?') . ' day(s) / ' . ($conf['lwp_deduction'] ?? '?')
                    . ', now ' . $lopDays . ' day(s) / ' . $lopDeduction
                    . '). Review and confirm again before finalizing.',
                    409
                );
            }
        }

        $updateData = [
            'status'       => 'Finalized',
            'finalized_at' => date('c'),
            'finalized_by' => $this->admin_name,
        ];

        // Firestore write
        $this->_fsSyncPayrollRun($runId, array_merge($run, $updateData));

        // Batch-update all slip statuses (Firestore)
        $slips = $this->_fsGetAllSlips($runId);
        foreach ($slips as $staffId => $s) {
            $this->_fsSyncPayslip($runId, $staffId, array_merge($s, ['status' => 'Finalized']));
        }

        $this->_log_payroll('finalized', $runId);
        $this->_audit_payroll('payroll_finalized', $runId,
            'Payroll run finalized (Draft → Finalized)',
            $this->_audit_run_meta($run, [
                'toStatus'     => 'Finalized',
                'lopDays'      => $lopDays,
                'lopDeduction' => $lopDeduction,
                'lopStaff'     => $lopStaff,
            ]));

        $this->json_success(['message' => 'Payroll run finalized.']);
    }

    /**
     * POST — Approve a finalized payroll run (approval workflow).
     * Adds an approval step between Finalize and Pay.
     * Only Admin/Principal can approve.
     */
    public function approve_payroll()
    {
        $this->_require_role(self::ADMIN_ROLES, 'approve_payroll', 'HR', 'manage');

        $runId = $this->safe_path_segment(trim($this->input->post('run_id') ?? ''), 'run_id');

        $run = $this->_fsGetRun($runId);
        if (!is_array($run)) {
            $this->json_error('Payroll run not found.');
        }
        $status = $run['status'] ?? '';
        if ($status !== 'Finalized') {
            $this->json_error("Only Finalized runs can be approved. Current status: {$status}.");
        }

        $approveData = [
            'status'      => 'Approved',
            'approved_at' => date('c'),
            'approved_by' => $this->admin_name,
        ];

        // Firestore write
        $this->_fsSyncPayrollRun($runId, array_merge($run, $approveData));

        // Update slip statuses (Firestore)
        $slips = $this->_fsGetAllSlips($runId);
        foreach ($slips as $sid => $s) {
            $this->_fsSyncPayslip($runId, $sid, array_merge($s, ['status' => 'Approved']));
        }

        $this->_log_payroll('approved', $runId);
        $this->_audit_payroll('payroll_approved', $runId,
            'Payroll run approved (Finalized → Approved)',
            $this->_audit_run_meta($run, ['toStatus' => 'Approved']));
        $this->json_success(['message' => 'Payroll approved. Ready for payment.']);
    }

    /**
     * GET — Download payslip as PDF.
     * Params: run_id, staff_id
     * Admin can download any; staff can download only their own.
     */
    public function download_payslip()
    {
        $this->_require_role(self::VIEW_ROLES, 'download_payslip', 'HR', 'view');

        $runId   = $this->safe_path_segment(trim($this->input->get('run_id') ?? ''), 'run_id');
        $staffId = trim($this->input->get('staff_id') ?? '');

        // Non-admin can only download own.
        //
        // P0-1/D1 (HRMS alignment): the legacy `|| in_array($this->admin_role,
        // ADMIN_ROLES)` re-grant was REMOVED — highest-impact of the four bypasses.
        // A caller whose role NAME sat in ADMIN_ROLES (Admin/Principal/HR Manager)
        // but who had been graded down to HR:view passed the gate above at
        // view>=view, then skipped this clamp and kept the request-supplied
        // staff_id — downloading ANY employee's payslip PDF (gross/net pay, bank
        // account, PAN/PF/ESI). Cross-staff payslip access is now strictly
        // HR:manage, matching every other cross-staff payroll read
        // (get_payslip / get_payroll_slips / export_payroll_report).
        // Self-service is unaffected: a caller without HR:manage is clamped to
        // their own id, and my_payslips() remains the dedicated self-service path.
        if (!(function_exists('has_permission') && has_permission('HR', 'manage'))) {
            $staffId = $this->admin_id;
        }
        $staffId = $this->safe_path_segment($staffId, 'staff_id');

        // ── Firestore-first: get run ──
        $run = null;
        try {
            $fsRun = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$runId}"));
            if (is_array($fsRun) && !empty($fsRun)) $run = $fsRun;
        } catch (\Exception $e) {
            log_message('error', "HR download_payslip FS run failed: " . $e->getMessage());
        }
        if (!is_array($run)) {
            $run = $this->_fsGetRun($runId);
        }
        if (!is_array($run)) show_error('Payroll run not found.', 404);

        // ── Firestore-first: get slip ──
        $slip = null;
        try {
            $fsSlip = $this->fs->get('salarySlips', $this->fs->docId("SLIP_{$runId}_{$staffId}"));
            if (is_array($fsSlip) && !empty($fsSlip)) $slip = $fsSlip;
        } catch (\Exception $e) {
            log_message('error', "HR download_payslip FS slip failed: " . $e->getMessage());
        }
        if (!is_array($slip)) {
            $slip = $this->_fsGetSlip($runId, $staffId);
        }
        if (!is_array($slip)) show_error('Payslip not found.', 404);

        // Only allow download of Finalized/Approved/Paid runs
        $st = $run['status'] ?? '';
        if (!in_array($st, ['Finalized', 'Approved', 'Paid', 'Partially Paid'], true)) {
            show_error('Payslip not available yet (still in draft).', 403);
        }

        $profile = $this->_fsGetStaffProfile($staffId);
        $schoolLogo = '';
        $schoolProfile = [];
        try {
            $schoolDoc = $this->fs->get('schools', $this->fs->schoolId());
            if (is_array($schoolDoc)) {
                $schoolLogo = $schoolDoc['logo'] ?? '';
                $schoolProfile = $schoolDoc['profile'] ?? ($schoolDoc['Profile'] ?? []);
            }
        } catch (\Exception $e) {}
        $schoolName = $schoolProfile['name'] ?? ($this->school_display_name ?? $this->school_name);
        $schoolAddr = $schoolProfile['address'] ?? '';

        $html = $this->_render_payslip_html($run, $slip, $profile, $schoolName, $schoolAddr, $schoolLogo, $staffId);

        $this->load->library('pdf_generator');
        $filename = preg_replace('/[^A-Za-z0-9_]/', '', $schoolName)
            . '_Payslip_' . ($run['month'] ?? '') . '_' . ($run['year'] ?? '') . '_' . $staffId . '.pdf';
        $this->pdf_generator->download($html, $filename);
    }

    /**
     * GET — Export payroll report as Excel.
     * Params: type (salary_register|department|payment), run_id or month+year
     */
    public function export_payroll_report()
    {
        $this->_require_role(self::ADMIN_ROLES, 'export_payroll_report', 'HR', 'manage');

        $type  = trim($this->input->get('type') ?? 'salary_register');
        $runId = trim($this->input->get('run_id') ?? '');
        $month = trim($this->input->get('month') ?? '');
        $year  = trim($this->input->get('year') ?? '');

        // ── Firestore-first: resolve run + slips ──
        $run = null;
        $slips = [];
        if ($runId !== '') {
            $runId = $this->safe_path_segment($runId, 'run_id');

            // Firestore first for run
            try {
                $fsRun = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$runId}"));
                if (is_array($fsRun) && !empty($fsRun)) $run = $fsRun;
            } catch (\Exception $e) {}
            if (!is_array($run)) {
                $run = $this->_fsGetRun($runId);
            }

            // Firestore first for slips
            try {
                $fsDocs = $this->fs->schoolWhere('salarySlips', [
                    ['type', '==', 'payslip'],
                    ['runId', '==', $runId],
                ]);
                if (!empty($fsDocs)) {
                    foreach ($fsDocs as $doc) {
                        $s = $doc['data'];
                        $sid = $s['staffId'] ?? '';
                        if ($sid !== '') $slips[$sid] = $s;
                    }
                }
            } catch (\Exception $e) {}
            if (empty($slips)) {
                $slips = $this->_fsGetAllSlips($runId);
            }
        } elseif ($month !== '' && $year !== '') {
            // Firestore first for finding run by month/year
            try {
                // OFF-CYCLE (2026-08-22) — resolve the MONTHLY run explicitly.
                //
                // This used to take LIMIT 1 and `[0]`, which was fine while a
                // period could only ever hold one run. Now that an off-cycle
                // run (F&F, arrears, bonus) may sit alongside March's monthly
                // payroll, `[0]` would return whichever the query happened to
                // yield — so exporting "the March salary register" could hand
                // an accountant a single leaver's settlement instead.
                //
                // The limit is dropped so the monthly run is actually reachable,
                // and `runType` is read with a monthly default so runs written
                // before the field existed still resolve.
                //
                // Callers that want a specific run already pass `run_id`, which
                // is the deterministic path and is untouched.
                $fsRuns = $this->fs->schoolWhere('salarySlips', [
                    ['type', '==', 'run'],
                    ['month', '==', $month],
                    ['year', '==', $year],
                ]);

                $doc = null;
                foreach ((array) $fsRuns as $candidate) {
                    $cData = is_array($candidate['data'] ?? null) ? $candidate['data'] : [];
                    if ((string) ($cData['runType'] ?? self::RUN_TYPE_MONTHLY) === self::RUN_TYPE_MONTHLY) {
                        $doc = $candidate;
                        break;
                    }
                }

                if ($doc !== null) {
                    $run = $doc['data'];
                    $parts = explode('_RUN_', $doc['id'], 2);
                    $runId = isset($parts[1]) ? $parts[1] : $doc['id'];

                    $fsDocs = $this->fs->schoolWhere('salarySlips', [
                        ['type', '==', 'payslip'],
                        ['runId', '==', $runId],
                    ]);
                    foreach ($fsDocs as $sd) {
                        $s = $sd['data'];
                        $sid = $s['staffId'] ?? '';
                        if ($sid !== '') $slips[$sid] = $s;
                    }
                }
            } catch (\Exception $e) {
                log_message('error', "HR export_payroll_report FS failed: " . $e->getMessage());
            }

            // Firestore fallback
            if (!is_array($run)) {
                $allRuns = $this->_fsGetAllRuns();
                foreach ($allRuns as $rid => $r) {
                    if (($r['month'] ?? '') === $month && ($r['year'] ?? '') === $year) {
                        $run = $r; $runId = $rid;
                        $slips = $this->_fsGetAllSlips($rid);
                        break;
                    }
                }
            }
        }
        if (!is_array($run)) show_error('Payroll run not found.', 404);
        if (!is_array($slips)) $slips = [];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $schoolName = preg_replace('/[^A-Za-z0-9_ ]/', '', $this->school_display_name ?? $this->school_name);
        $period = ($run['month'] ?? '') . ' ' . ($run['year'] ?? '');

        switch ($type) {
            case 'department':
                $this->_xl_dept_report($sheet, $run, $slips, $schoolName, $period);
                break;
            case 'payment':
                $this->_xl_payment_report($sheet, $run, $schoolName, $period);
                break;
            default: // salary_register
                $this->_xl_salary_register($sheet, $slips, $schoolName, $period);
                break;
        }

        // Auto-width + freeze
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A3');

        $filename = $schoolName . '_' . $type . '_' . str_replace(' ', '_', $period) . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        $spreadsheet->disconnectWorksheets();
        exit;
    }

    // ── Payslip PDF HTML renderer ──

    private function _render_payslip_html(array $run, array $slip, $profile, string $school, string $addr, string $logo, string $staffId): string
    {
        $p = is_array($profile) ? $profile : [];
        $month = $run['month'] ?? ''; $year = $run['year'] ?? '';
        $staffName = $p['Name'] ?? ($slip['staff_name'] ?? $staffId);
        $dept = $p['Department'] ?? ($slip['department'] ?? '');
        $pos = $p['designation'] ?? $p['Position'] ?? '';
        $doj = $p['Date Of Joining'] ?? '';
        $empId = $staffId;
        $bankName = $p['bankDetails']['bankName'] ?? '';
        $bankAcct = $p['bankDetails']['accountNumber'] ?? '';

        // Phase A statutory IDs
        $pan = $p['panNumber']    ?? '';
        $pf  = $p['pfNumber']     ?? '';
        $esi = $p['esiNumber']    ?? '';
        $uan = $p['aadharNumber'] ?? '';  // Aadhar displayed as UAN backup

        $gross = (float) ($slip['gross'] ?? 0);
        $totalDed = (float) ($slip['total_deductions'] ?? 0);
        $netPay = (float) ($slip['net_pay'] ?? 0);

        $fmt = function ($v) { return number_format((float) $v, 2, '.', ','); };

        $css = 'body{font-family:sans-serif;font-size:11px;color:#1a2e2a;margin:0;padding:20px 30px}
        .cur{font-family:"DejaVu Sans"}
        table{width:100%;border-collapse:collapse}
        .hdr{text-align:center;margin-bottom:16px}
        .hdr h1{font-size:16px;margin:0;color:#BC5A3C}
        .hdr p{margin:2px 0;color:#4a6a60;font-size:10px}
        .hdr .period{font-size:13px;font-weight:700;color:#BC5A3C;margin-top:8px}
        .info td{padding:4px 8px;font-size:10px;border:1px solid #d1ddd8}
        .info .lbl{font-weight:700;color:#4a6a60;width:25%}
        .section{font-size:11px;font-weight:700;color:#BC5A3C;padding:8px 0 4px;border-bottom:2px solid #BC5A3C;margin-top:14px}
        .pay td{padding:5px 8px;border:1px solid #d1ddd8;font-size:10px}
        .pay .r{text-align:right;font-family:monospace}
        .pay .total{font-weight:700;background:#F3E9E2}
        .net{text-align:center;font-size:14px;font-weight:700;color:#BC5A3C;padding:12px;background:#F3E9E2;border-radius:6px;margin:14px 0}
        .footer{font-size:9px;color:#7a9a8e;text-align:center;margin-top:20px;border-top:1px solid #d1ddd8;padding-top:8px}';

        $h = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';

        // Header
        $h .= '<div class="hdr">';
        if ($logo) $h .= '<img src="' . htmlspecialchars($logo) . '" style="max-height:50px;margin-bottom:6px"><br>';
        $h .= '<h1>' . htmlspecialchars($school) . '</h1>';
        if ($addr) $h .= '<p>' . htmlspecialchars($addr) . '</p>';
        $h .= '<div class="period">Payslip for ' . htmlspecialchars($month . ' ' . $year) . '</div>';
        $h .= '</div>';

        // Employee info
        $h .= '<table class="info"><tr><td class="lbl">Employee Name</td><td>' . htmlspecialchars($staffName) . '</td><td class="lbl">Employee ID</td><td>' . htmlspecialchars($empId) . '</td></tr>';
        $h .= '<tr><td class="lbl">Department</td><td>' . htmlspecialchars($dept) . '</td><td class="lbl">Designation</td><td>' . htmlspecialchars($pos) . '</td></tr>';
        $h .= '<tr><td class="lbl">Date of Joining</td><td>' . htmlspecialchars($doj) . '</td><td class="lbl">Working Days</td><td>' . ($slip['working_days'] ?? '-') . ' (' . ($slip['days_worked'] ?? '-') . ' worked)</td></tr>';
        if ($bankName) $h .= '<tr><td class="lbl">Bank</td><td>' . htmlspecialchars($bankName) . '</td><td class="lbl">Account No.</td><td>' . htmlspecialchars($bankAcct) . '</td></tr>';
        // Statutory IDs row — only show if at least one is filled
        if ($pan || $pf || $esi) {
            $h .= '<tr><td class="lbl">PAN</td><td>' . htmlspecialchars($pan ?: '—') . '</td><td class="lbl">PF/UAN No.</td><td>' . htmlspecialchars($pf ?: '—') . '</td></tr>';
            $h .= '<tr><td class="lbl">ESI No.</td><td>' . htmlspecialchars($esi ?: '—') . '</td><td class="lbl">Aadhar</td><td>' . htmlspecialchars($uan ?: '—') . '</td></tr>';
        }
        $h .= '</table>';

        // Earnings & Deductions side-by-side
        $h .= '<table style="margin-top:14px"><tr><td style="width:50%;vertical-align:top;padding-right:8px">';
        $h .= '<div class="section">Earnings</div>';
        $h .= '<table class="pay">';
        $h .= '<tr><td>Basic Salary</td><td class="r">' . $fmt($slip['basic'] ?? 0) . '</td></tr>';
        foreach (['hra' => 'HRA', 'da' => 'DA', 'ta' => 'TA', 'medical' => 'Medical', 'other_allowances' => 'Other Allowances'] as $k => $lbl) {
            $v = (float) ($slip[$k] ?? 0);
            if ($v > 0) $h .= '<tr><td>' . $lbl . '</td><td class="r">' . $fmt($v) . '</td></tr>';
        }
        $h .= '<tr class="total"><td>Gross Pay</td><td class="r">' . $fmt($gross) . '</td></tr>';
        $h .= '</table>';
        $h .= '</td><td style="width:50%;vertical-align:top;padding-left:8px">';
        $h .= '<div class="section">Deductions</div>';
        $h .= '<table class="pay">';
        foreach (['pf_employee' => 'PF (Employee)', 'esi_employee' => 'ESI (Employee)', 'professional_tax' => 'Professional Tax', 'tds' => 'TDS', 'other_deductions' => 'Other Deductions'] as $k => $lbl) {
            $v = (float) ($slip[$k] ?? 0);
            if ($v > 0) $h .= '<tr><td>' . $lbl . '</td><td class="r">' . $fmt($v) . '</td></tr>';
        }
        if ((float) ($slip['lwp_deduction'] ?? 0) > 0) {
            $h .= '<tr><td>LWP Deduction (' . ($slip['lwp_days'] ?? 0) . ' days)</td><td class="r">' . $fmt($slip['lwp_deduction']) . '</td></tr>';
        }
        $h .= '<tr class="total"><td>Total Deductions</td><td class="r">' . $fmt($totalDed) . '</td></tr>';
        $h .= '</table>';
        $h .= '</td></tr></table>';

        // Net Pay
        // RT-5: the symbol needs its own DejaVu span. dompdf resolves this
        // document's `sans-serif` to core Helvetica, which cannot encode U+20B9
        // and substitutes a literal '?' — so every payslip generated to date has
        // read "Net Pay: ? 71,940.25". Scoping the font to the symbol leaves all
        // other payslip text metrics on Helvetica, so nothing else reflows.
        $h .= '<div class="net">Net Pay: <span class="cur">₹</span> ' . $fmt($netPay) . '</div>';

        // Footer
        $h .= '<div class="footer">This is a computer-generated payslip. Generated on ' . date('d M Y, h:i A') . '</div>';
        $h .= '</body></html>';
        return $h;
    }

    // ── Excel report builders ──

    private function _xl_salary_register($sheet, array $slips, string $school, string $period): void
    {
        $sheet->setTitle('Salary Register');
        $sheet->setCellValue('A1', $school . ' — Salary Register — ' . $period);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $cols = ['#','Staff','Dept','Basic','HRA','DA','TA','Medical','Other','Gross','PF','ESI','PT','TDS','Deductions','Net Pay','Days Worked','Absent','LWP'];
        $c = 'A'; $r = 2;
        foreach ($cols as $label) {
            $sheet->setCellValue($c.$r, $label);
            $sheet->getStyle($c.$r)->getFont()->setBold(true);
            $sheet->getStyle($c.$r)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E6F4F1');
            $c++;
        }
        $r = 3; $i = 0;
        $tGross = 0; $tDed = 0; $tNet = 0;
        foreach ($slips as $sid => $s) {
            $i++; $c = 'A';
            $vals = [$i, $s['staff_name']??$sid, $s['department']??'', $s['basic']??0, $s['hra']??0, $s['da']??0, $s['ta']??0, $s['medical']??0, $s['other_allowances']??0, $s['gross']??0, $s['pf_employee']??0, $s['esi_employee']??0, $s['professional_tax']??0, $s['tds']??0, $s['total_deductions']??0, $s['net_pay']??0, $s['days_worked']??0, $s['days_absent']??0, $s['lwp_days']??0];
            foreach ($vals as $v) { $sheet->setCellValue($c.$r, $v); $c++; }
            $sheet->getStyle("D{$r}:P{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
            $tGross += (float)($s['gross']??0); $tDed += (float)($s['total_deductions']??0); $tNet += (float)($s['net_pay']??0);
            $r++;
        }
        // Totals
        $sheet->setCellValue("B{$r}", 'TOTALS'); $sheet->setCellValue("J{$r}", round($tGross,2)); $sheet->setCellValue("O{$r}", round($tDed,2)); $sheet->setCellValue("P{$r}", round($tNet,2));
        $sheet->getStyle("A{$r}:S{$r}")->getFont()->setBold(true);
        $sheet->getStyle("J{$r}:P{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
    }

    private function _xl_dept_report($sheet, array $run, array $slips, string $school, string $period): void
    {
        $sheet->setTitle('Dept Salary');
        $sheet->setCellValue('A1', $school . ' — Department Salary Report — ' . $period);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $depts = [];
        foreach ($slips as $s) {
            $d = $s['department'] ?? 'General';
            if (!isset($depts[$d])) $depts[$d] = ['count' => 0, 'gross' => 0, 'deductions' => 0, 'net' => 0];
            $depts[$d]['count']++;
            $depts[$d]['gross'] += (float)($s['gross']??0);
            $depts[$d]['deductions'] += (float)($s['total_deductions']??0);
            $depts[$d]['net'] += (float)($s['net_pay']??0);
        }

        $r = 2;
        foreach (['Department','Staff Count','Total Gross','Total Deductions','Total Net'] as $i => $lbl) {
            $c = chr(65+$i);
            $sheet->setCellValue($c.$r, $lbl);
            $sheet->getStyle($c.$r)->getFont()->setBold(true);
        }
        $r = 3;
        foreach ($depts as $name => $d) {
            $sheet->setCellValue("A{$r}", $name); $sheet->setCellValue("B{$r}", $d['count']);
            $sheet->setCellValue("C{$r}", round($d['gross'],2)); $sheet->setCellValue("D{$r}", round($d['deductions'],2)); $sheet->setCellValue("E{$r}", round($d['net'],2));
            $sheet->getStyle("C{$r}:E{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
            $r++;
        }
    }

    private function _xl_payment_report($sheet, array $run, string $school, string $period): void
    {
        $sheet->setTitle('Payment Report');
        $sheet->setCellValue('A1', $school . ' — Payment Report — ' . $period);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        // Summary
        $sheet->setCellValue('A2', 'Total Net: ' . number_format((float)($run['total_net']??0),2));
        $sheet->setCellValue('C2', 'Total Paid: ' . number_format((float)($run['total_paid']??0),2));
        $sheet->setCellValue('E2', 'Balance Due: ' . number_format((float)($run['balance_due']??0),2));
        $sheet->getStyle('A2:F2')->getFont()->setBold(true);

        $r = 4;
        foreach (['#','Date','Mode','Reference','Amount','Journal ID','Paid By'] as $i => $lbl) {
            $c = chr(65+$i); $sheet->setCellValue($c.$r, $lbl);
            $sheet->getStyle($c.$r)->getFont()->setBold(true);
        }
        $r = 5;
        $payments = $run['payments'] ?? [];
        if (is_array($payments)) {
            foreach ($payments as $seq => $pay) {
                $sheet->setCellValue("A{$r}", $pay['seq']??$seq); $sheet->setCellValue("B{$r}", $pay['payment_date']??'');
                $sheet->setCellValue("C{$r}", $pay['payment_mode']??''); $sheet->setCellValue("D{$r}", $pay['payment_reference']??'');
                $sheet->setCellValue("E{$r}", (float)($pay['amount']??0)); $sheet->setCellValue("F{$r}", $pay['journal_id']??'');
                $sheet->setCellValue("G{$r}", $pay['paid_by']??'');
                $sheet->getStyle("E{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                $r++;
            }
        }
    }

    /**
     * POST — Record a payroll payment. Supports PARTIAL payments.
     *
     * Params: run_id, payment_mode, payment_date, payment_reference, amount (optional)
     *
     * If amount is omitted or equals total_net → full payment (status=Paid).
     * If amount < total_net → partial payment (status=Partially Paid).
     * Multiple partial payments allowed until total is reached.
     *
     * Each payment creates its own journal entry (DR Salary Payable / CR Cash|Bank).
     * Payments are stored in run.payments[] array for audit trail.
     */
    public function mark_payroll_paid()
    {
        $this->_require_role(self::ADMIN_ROLES, 'mark_payroll_paid', 'HR', 'manage');

        $runId            = $this->safe_path_segment(trim($this->input->post('run_id') ?? ''), 'run_id');
        $paymentMode      = trim($this->input->post('payment_mode') ?? 'Bank Transfer');
        $paymentDate      = trim($this->input->post('payment_date') ?? '');
        $paymentReference = trim($this->input->post('payment_reference') ?? '');
        $payAmount        = $this->input->post('amount'); // null = full payment

        $validModes = ['Bank Transfer', 'Cash', 'UPI'];
        if (!in_array($paymentMode, $validModes, true)) {
            $paymentMode = 'Bank Transfer';
        }
        if ($paymentDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            $this->json_error('Invalid payment date format. Use YYYY-MM-DD.');
        }
        if ($paymentDate === '') $paymentDate = date('Y-m-d');

        $run = $this->_fsGetRun($runId);
        if (!is_array($run)) {
            $this->json_error('Payroll run not found.');
        }

        $runStatus = $run['status'] ?? '';
        if ($runStatus === 'Paid') {
            $this->json_error('This payroll run is already fully paid.');
        }
        if (!in_array($runStatus, ['Finalized', 'Approved', 'Partially Paid'], true)) {
            $this->json_error('Only Finalized, Approved, or Partially Paid payroll runs can receive payments.');
        }

        $totalNet   = round((float) ($run['total_net'] ?? 0), 2);
        $totalPaid  = round((float) ($run['total_paid'] ?? 0), 2);
        $remaining  = round($totalNet - $totalPaid, 2);
        $month      = $run['month'] ?? '';
        $year       = $run['year'] ?? '';

        // Determine payment amount
        if ($payAmount !== null && $payAmount !== '') {
            $payAmount = round((float) $payAmount, 2);
            if ($payAmount <= 0) {
                $this->json_error('Payment amount must be greater than zero.');
            }
            if ($payAmount > $remaining + 0.01) {
                $this->json_error("Payment amount ({$payAmount}) exceeds remaining balance ({$remaining}).");
            }
            // Clamp to remaining (handle rounding)
            if ($payAmount > $remaining) $payAmount = $remaining;
        } else {
            $payAmount = $remaining; // full payment
        }

        // ── Post payment + update run through the canonical engine ──────
        // CAS-FIRST, contention-only retry. The run write is the serialization
        // point (atomic seq claim); only the CAS winner posts the idempotent
        // journal. Concurrent payments get distinct seqs + distinct journals →
        // no duplicate state, no ledger/run mismatch, correct partial
        // sequencing. Uncontended: reuses the run already loaded ($run) — NO
        // extra read.
        $payAccount = ($paymentMode === 'Cash') ? '1010' : '1020';
        require_once APPPATH . 'services/PayrollAccountingService.php';
        $payAcct = new PayrollAccountingService();
        $payAcct->init($this->firebase, $this->school_id, $this->session_year, $this->admin_id, $this);

        $res = $this->_engine_apply_payment(
            $runId, $run, $totalNet, $month, $year,
            $paymentMode, $paymentDate, $paymentReference, $payAccount,
            $this->input->post('amount'), $payAcct
        );
        // Adopt the ACTUAL applied values — a retry may have re-derived the seq
        // and amount from a concurrently-updated run.
        $journalId     = (string) $res['journal_id'];
        $paymentSeq    = (int)    $res['payment_seq'];
        $payAmount     = (float)  $res['pay_amount'];
        $newTotalPaid  = (float)  $res['new_total_paid'];
        $isFullPayment = (bool)   $res['is_full'];
        $newStatus     = (string) $res['status'];
        $paidAt        = (string) $res['paid_at'];

        // Update slip statuses only on full payment
        if ($isFullPayment) {
            $slips = $this->_fsGetAllSlips($runId);
            foreach ($slips as $staffId => $s) {
                $this->_fsSyncPayslip($runId, $staffId, array_merge($s, [
                    'status' => 'Paid', 'paid_at' => $paidAt,
                    'payment_mode' => $paymentMode, 'payment_date' => $paymentDate,
                    'payment_reference' => $paymentReference,
                ]));
            }
        }

        $this->_log_payroll($isFullPayment ? 'paid' : 'partial_payment', $runId, [
            'payment_seq'       => $paymentSeq,
            'amount'            => $payAmount,
            'total_paid'        => $newTotalPaid,
            'balance_due'       => round($totalNet - $newTotalPaid, 2),
            'payment_mode'      => $paymentMode,
            'payment_reference' => $paymentReference,
            'journal_id'        => $journalId,
        ]);
        $this->_audit_payroll(
            $isFullPayment ? 'payroll_paid' : 'payroll_partial_payment',
            $runId,
            $isFullPayment
                ? "Payroll run marked fully paid via {$paymentMode}"
                : "Partial payroll payment recorded via {$paymentMode}",
            $this->_audit_run_meta($run, [
                'toStatus'         => $newStatus,
                'paymentSeq'       => $paymentSeq,
                'amount'           => $payAmount,
                'totalPaid'        => $newTotalPaid,
                'balanceDue'       => round($totalNet - $newTotalPaid, 2),
                'paymentMode'      => $paymentMode,
                'paymentDate'      => $paymentDate,
                'paymentReference' => $paymentReference,
                'journalId'        => $journalId,
            ]));

        $this->json_success([
            'message'     => $isFullPayment
                ? "Payroll fully paid via {$paymentMode}."
                : "Partial payment of " . number_format($payAmount, 2) . " recorded. Remaining: " . number_format($totalNet - $newTotalPaid, 2),
            'journal_id'  => $journalId,
            'total_paid'  => $newTotalPaid,
            'balance_due' => round($totalNet - $newTotalPaid, 2),
            'status'      => $newStatus,
        ]);
    }

    /**
     * GET — Payroll Health page (read-only admin view). Renders the dashboard
     * shell only; ALL data is fetched client-side from hr/payroll_health. No
     * business logic, no writes, no payroll/accounting impact.
     */
    public function payroll_health_page()
    {
        $this->_require_role(self::ADMIN_ROLES, 'payroll_health_page', 'HR', 'manage');
        $this->load->view('include/header');
        $this->load->view('hr/payroll_health');
        $this->load->view('include/footer');
    }

    /**
     * GET — Payroll Health / operational observability (R2). Read-only,
     * admin-visible; surfaces the outbox + reconciler state WITHOUT reading
     * logs. Reads the per-tenant reconciler status doc and scans this
     * school+session's payroll runs, aggregating pending / recovered / failed
     * postings with full diagnostics. Additive; NOT on the payroll write path,
     * so it imposes no cost on generation or payment.
     */
    public function payroll_health()
    {
        $this->_require_role(self::ADMIN_ROLES, 'payroll_health', 'HR', 'manage');

        $recon = null;
        try {
            $recon = $this->fs->get('payrollReconcilerStatus',
                "{$this->school_id}_{$this->session_year}");
        } catch (\Exception $e) {}

        $pending = 0; $recovered = 0; $failedCnt = 0; $retriesTotal = 0;
        $failedList = [];

        try {
            $runs = $this->fs->schoolWhere('salarySlips', [
                ['session', '==', $this->session_year],
                ['type',    '==', 'run'],
            ]);
            foreach ((array) $runs as $row) {
                $run   = is_array($row['data'] ?? null) ? $row['data'] : $row;
                $runId = (string) ($run['runId'] ?? '');
                $pmts  = is_array($run['payments'] ?? null) ? $run['payments'] : [];
                foreach ($pmts as $seqK => $p) {
                    if (!is_array($p)) continue;
                    $js   = (string) ($p['journal_status'] ?? 'posted');
                    $diag = is_array($p['posting_diag'] ?? null) ? $p['posting_diag'] : [];
                    $retriesTotal += (int) ($diag['retry_count'] ?? 0);
                    $lastRes = (string) ($diag['last_retry_result'] ?? '');
                    if ($js === 'pending') {
                        $pending++;
                        if ($lastRes === 'failed') {
                            $failedCnt++;
                            $failedList[] = [
                                'run_id'            => $runId,
                                'seq'               => (int) ($p['seq'] ?? $seqK),
                                'journal_id'        => (string) ($diag['journal_id'] ?? ($p['journal_id'] ?? '')),
                                'amount'            => (float) ($p['amount'] ?? 0),
                                'failure_reason'    => (string) ($diag['failure_reason'] ?? ''),
                                'required_action'   => (string) ($diag['required_action'] ?? ''),
                                'retry_count'       => (int) ($diag['retry_count'] ?? 0),
                                'first_failure_at'  => (string) ($diag['first_failure_at'] ?? ''),
                                'last_retry_at'     => (string) ($diag['last_retry_at'] ?? ''),
                                'last_retry_result' => $lastRes,
                            ];
                        }
                    } elseif ($lastRes === 'recovered') {
                        $recovered++;
                    }
                }

                // C2 — accrual outbox health (one per run). Same scan, no extra
                // reads. Mirrors the payment rollup so a pending/failed/recovered
                // accrual surfaces on the same Health view.
                $aStatus = (string) ($run['accrual_journal_status'] ?? '');
                if ($aStatus !== '') {
                    $aDiag    = is_array($run['accrual_posting_diag'] ?? null) ? $run['accrual_posting_diag'] : [];
                    $retriesTotal += (int) ($aDiag['retry_count'] ?? 0);
                    $aLastRes = (string) ($aDiag['last_retry_result'] ?? '');
                    if ($aStatus === 'pending') {
                        $pending++;
                        if ($aLastRes === 'failed') {
                            $failedCnt++;
                            $failedList[] = [
                                'run_id'            => $runId,
                                'seq'               => 'accrual',
                                'journal_id'        => (string) ($aDiag['journal_id'] ?? ($run['accrual_journal_id'] ?? '')),
                                'amount'            => (float) ($run['total_net'] ?? 0),
                                'failure_reason'    => (string) ($aDiag['failure_reason'] ?? ''),
                                'required_action'   => (string) ($aDiag['required_action'] ?? ''),
                                'retry_count'       => (int) ($aDiag['retry_count'] ?? 0),
                                'first_failure_at'  => (string) ($aDiag['first_failure_at'] ?? ''),
                                'last_retry_at'     => (string) ($aDiag['last_retry_at'] ?? ''),
                                'last_retry_result' => $aLastRes,
                            ];
                        }
                    } elseif ($aLastRes === 'recovered') {
                        $recovered++;
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', "payroll_health scan failed: " . $e->getMessage());
        }

        $this->json_success([
            'generated_at' => date('c'),
            'reconciler'   => [
                'status'          => is_array($recon) ? 'reporting' : 'never_run',
                'last_run_at'     => is_array($recon) ? ($recon['last_run_at'] ?? null)     : null,
                'last_run_result' => is_array($recon) ? ($recon['last_run_result'] ?? null) : null,
                'last_success_at' => is_array($recon) ? ($recon['last_success_at'] ?? null) : null,
                'found'           => is_array($recon) ? (int) ($recon['found'] ?? 0)    : 0,
                'reposted'        => is_array($recon) ? (int) ($recon['reposted'] ?? 0) : 0,
                'failed'          => is_array($recon) ? (int) ($recon['failed'] ?? 0)   : 0,
            ],
            'summary' => [
                'pending_postings'   => $pending,
                'recovered_postings' => $recovered,
                'failed_postings'    => $failedCnt,
                'retry_total'        => $retriesTotal,
            ],
            'failed_postings' => $failedList,
        ]);
    }

    /**
     * GET — Get a single payslip. ?run_id=PR0001&staff_id=XXX
     */
    public function get_payslip()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_payslip', 'HR', 'manage');

        $runId   = trim($this->input->get('run_id') ?? '');
        $staffId = trim($this->input->get('staff_id') ?? '');

        if ($runId === '' || $staffId === '') {
            $this->json_error('Both run_id and staff_id are required.');
        }
        $runId   = $this->safe_path_segment($runId, 'run_id');
        $staffId = $this->safe_path_segment($staffId, 'staff_id');

        // ── Firestore-first: get run ──
        $run = null;
        try {
            $fsRun = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$runId}"));
            if (is_array($fsRun) && !empty($fsRun)) $run = $fsRun;
        } catch (\Exception $e) {
            log_message('error', "HR get_payslip FS run failed: " . $e->getMessage());
        }
        if (!is_array($run)) {
            $run = $this->_fsGetRun($runId);
        }
        if (!is_array($run)) {
            $this->json_error('Payroll run not found.');
        }

        // ── Firestore-first: get slip ──
        $slip = null;
        try {
            $fsSlip = $this->fs->get('salarySlips', $this->fs->docId("SLIP_{$runId}_{$staffId}"));
            if (is_array($fsSlip) && !empty($fsSlip)) $slip = $fsSlip;
        } catch (\Exception $e) {
            log_message('error', "HR get_payslip FS slip failed: " . $e->getMessage());
        }
        if (!is_array($slip)) {
            $slip = $this->_fsGetSlip($runId, $staffId);
        }
        if (!is_array($slip)) {
            $this->json_error('Payslip not found for this staff member.');
        }

        // Get staff profile for additional info (Firestore)
        $staffProfile = $this->_fsGetStaffProfile($staffId);

        $this->json_success([
            'run' => [
                'id'     => $runId,
                'month'  => $run['month'] ?? '',
                'year'   => $run['year'] ?? '',
                'status' => $run['status'] ?? '',
            ],
            'slip' => array_merge($slip, ['staff_id' => $staffId]),
            'staff_profile' => is_array($staffProfile) ? [
                'name'          => $staffProfile['Name'] ?? '',
                'email'         => $staffProfile['Email'] ?? '',
                'phone'         => $staffProfile['Phone'] ?? '',
                'department'    => $staffProfile['Department'] ?? $staffProfile['department'] ?? '',
                'position'      => $staffProfile['Position'] ?? '',
                'qualification' => $staffProfile['Qualification'] ?? '',
            ] : null,
        ]);
    }

    /**
     * GET — Staff self-service: view own payslips.
     * Any logged-in staff (VIEW_ROLES) can see their own payslips only.
     * Optional ?run_id=PR0001 for a single slip; otherwise returns all.
     */
    public function my_payslips()
    {
        $this->_require_role(self::VIEW_ROLES, 'my_payslips', 'HR', 'view');

        $staffId = $this->admin_id;
        if (empty($staffId)) {
            $this->json_error('Could not determine your staff ID.');
        }
        $staffId = $this->safe_path_segment($staffId, 'staff_id');

        $runId = trim($this->input->get('run_id') ?? '');

        // If specific run requested, return single slip
        if ($runId !== '') {
            $runId = $this->safe_path_segment($runId, 'run_id');

            // ── Firestore-first: get run ──
            $run = null;
            try {
                $fsRun = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$runId}"));
                if (is_array($fsRun) && !empty($fsRun)) $run = $fsRun;
            } catch (\Exception $e) {}
            if (!is_array($run)) {
                $run = $this->_fsGetRun($runId);
            }
            if (!is_array($run)) {
                $this->json_error('Payroll run not found.');
            }
            // Only show finalized/paid runs to staff
            if (!in_array($run['status'] ?? '', ['Finalized', 'Paid'], true)) {
                $this->json_error('This payroll run is not yet finalized.');
            }

            // ── Firestore: get slip ──
            $slip = $this->_fsGetSlip($runId, $staffId);
            if (!is_array($slip)) {
                $this->json_error('No payslip found for you in this run.');
            }
            $this->json_success([
                'run'  => ['id' => $runId, 'month' => $run['month'] ?? '', 'year' => $run['year'] ?? '', 'status' => $run['status'] ?? ''],
                'slip' => array_merge($slip, ['staff_id' => $staffId]),
            ]);
        }

        // ── Firestore-first: list all own payslips ──
        $result = [];
        $usedFs = false;
        try {
            $fsDocs = $this->fs->schoolWhere('salarySlips', [
                ['type', '==', 'payslip'],
                ['staffId', '==', $staffId],
            ]);
            if (!empty($fsDocs)) {
                $usedFs = true;
                $runCache = [];
                foreach ($fsDocs as $doc) {
                    $s = $doc['data'];
                    $rid = $s['runId'] ?? '';
                    if ($rid === '') continue;

                    if (!isset($runCache[$rid])) {
                        $runDoc = null;
                        try {
                            $runDoc = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$rid}"));
                        } catch (\Exception $e) {}
                        $runCache[$rid] = is_array($runDoc) ? $runDoc : null;
                    }

                    $run = $runCache[$rid];
                    if (!is_array($run)) continue;
                    $status = $run['status'] ?? '';
                    if (!in_array($status, ['Finalized', 'Paid'], true)) continue;

                    $result[] = [
                        'run_id' => $rid,
                        'month'  => $run['month'] ?? '',
                        'year'   => $run['year'] ?? '',
                        'status' => $status,
                        'net_pay'          => $s['net_pay'] ?? 0,
                        'gross'            => $s['gross'] ?? 0,
                        'basic'            => $s['basic'] ?? 0,
                        'total_deductions' => $s['total_deductions'] ?? 0,
                        'days_worked'      => $s['days_worked'] ?? '',
                        'days_absent'      => $s['days_absent'] ?? 0,
                        'lwp_days'         => $s['lwp_days'] ?? 0,
                        'slip'             => $s,
                    ];
                }
            }
        } catch (\Exception $e) {
            log_message('error', "HR my_payslips FS failed: " . $e->getMessage());
        }

        // Firestore fallback if first query didn't work
        if (!$usedFs) {
            $allRuns = $this->_fsGetAllRuns();
            if (empty($allRuns)) {
                $this->json_success(['payslips' => []]);
                return;
            }

            foreach ($allRuns as $rid => $run) {
                if (!is_array($run)) continue;
                $status = $run['status'] ?? '';
                if (!in_array($status, ['Finalized', 'Paid'], true)) continue;

                $slip = $this->_fsGetSlip($rid, $staffId);
                if (!is_array($slip)) continue;

                $result[] = [
                    'run_id' => $rid,
                    'month'  => $run['month'] ?? '',
                    'year'   => $run['year'] ?? '',
                    'status' => $status,
                    'net_pay'          => $slip['net_pay'] ?? 0,
                    'gross'            => $slip['gross'] ?? 0,
                    'basic'            => $slip['basic'] ?? 0,
                    'total_deductions' => $slip['total_deductions'] ?? 0,
                    'days_worked'      => $slip['days_worked'] ?? '',
                    'days_absent'      => $slip['days_absent'] ?? 0,
                    'lwp_days'         => $slip['lwp_days'] ?? 0,
                    'slip'             => $slip,
                ];
            }
        }

        // Sort by year desc, month desc
        $monthOrder = array_flip(['January','February','March','April','May','June','July','August','September','October','November','December']);
        usort($result, function ($a, $b) use ($monthOrder) {
            $yc = ($b['year'] ?? 0) <=> ($a['year'] ?? 0);
            if ($yc !== 0) return $yc;
            return ($monthOrder[$b['month']] ?? 0) <=> ($monthOrder[$a['month']] ?? 0);
        });

        $this->json_success(['payslips' => $result]);
    }

    /**
     * POST — Delete a payroll run (Draft only).
     * Params: run_id
     */
    public function delete_payroll_run()
    {
        $this->_require_role(self::ADMIN_ROLES, 'delete_payroll_run', 'HR', 'manage');

        $runId = $this->safe_path_segment(trim($this->input->post('run_id') ?? ''), 'run_id');

        $run = $this->_fsGetRun($runId);
        if (!is_array($run)) {
            $this->json_error('Payroll run not found.');
        }
        if (($run['status'] ?? '') !== 'Draft') {
            $this->json_error('Only Draft payroll runs can be deleted.');
        }

        // Check month lock
        $this->_check_payroll_lock($run['month'] ?? '', $run['year'] ?? '');

        // Audit log before deletion (run data will be gone after)
        $this->_log_payroll('deleted', $runId, [
            'month' => $run['month'] ?? '', 'year' => $run['year'] ?? '',
            'total_net' => $run['total_net'] ?? 0,
        ]);
        // Canonical audit trail — emitted BEFORE the delete for the same
        // reason as the diagnostic line above: once _delete_payroll_docs()
        // runs, the run's period and totals are unrecoverable.
        $this->_audit_payroll('payroll_run_deleted', $runId,
            'Draft payroll run deleted; accrual journal reversed',
            $this->_audit_run_meta($run, [
                'toStatus'          => 'deleted',
                'expenseJournalId'  => (string) ($run['expense_journal_id'] ?? ''),
            ]));

        $expenseJournalId = $run['expense_journal_id'] ?? '';

        // Reverse the accrual through the canonical engine BEFORE removing
        // payroll data, so a period-lock/account refusal leaves the run intact
        // (never an orphaned, un-reversed accrual). The original journal stays
        // IMMUTABLE; the reversal entry is the audit trail. postReversal is
        // idempotent (JE_PAYROLL_REVERSAL_<id>).
        if ($expenseJournalId !== '') {
            require_once APPPATH . 'services/PayrollAccountingService.php';
            $payAcct = new PayrollAccountingService();
            $payAcct->init($this->firebase, $this->school_id, $this->session_year, $this->admin_id, $this);
            $payAcct->postReversal($expenseJournalId, "Payroll run {$runId} deleted");
        }
        $this->_delete_payroll_docs($runId);

        $this->json_success(['message' => 'Payroll run and associated slips deleted.']);
    }

    /**
     * Remove a payroll run's slip docs + the run doc from Firestore. Extracted
     * so the engine and legacy delete paths share one identical removal
     * implementation (behaviour is unchanged from the pre-H1 inline block).
     */
    private function _delete_payroll_docs(string $runId): void
    {
        try {
            $slips = $this->_fsGetAllSlips($runId);
            foreach (array_keys($slips) as $sid) {
                $this->fs->remove('salarySlips', $this->fs->docId("SLIP_{$runId}_{$sid}"));
            }
            $this->fs->remove('salarySlips', $this->fs->docId("RUN_{$runId}"));
        } catch (\Exception $e) {
            log_message('error', "HR delete_payroll FS failed: " . $e->getMessage());
        }
    }

    // ====================================================================
    //  APPRAISALS
    // ====================================================================

    /**
     * GET — List appraisals. Optional filters: ?staff_id=XXX&status=Draft
     */
    public function get_appraisals()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_appraisals', 'HR', 'view');

        $filterStaffId = trim($this->input->get('staff_id') ?? '');
        $filterStatus  = trim($this->input->get('status') ?? '');
        $filterPeriod  = trim($this->input->get('period') ?? '');
        $list = [];
        $fromFirestore = false;

        // ── Firestore-first read (use schoolWhere so we keep doc IDs) ──
        try {
            $conditions = [];
            if ($filterStaffId !== '') {
                $conditions[] = ['staff_id', '==', $filterStaffId];
            }
            if ($filterStatus !== '') {
                $conditions[] = ['status', '==', $filterStatus];
            }
            if ($filterPeriod !== '') {
                $conditions[] = ['period', '==', $filterPeriod];
            }
            $fsDocs = $this->fs->schoolWhere('appraisals', $conditions);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                $fromFirestore = true;
                foreach ($fsDocs as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $r = is_array($doc['data'] ?? null) ? $doc['data'] : [];
                    // Strip schoolId prefix from doc id (format: {schoolId}_APR0001)
                    // Wrapper bug (as in get_departments): the doc id is on the wrapper,
                    // not inside data. Reading it from the data dict yielded '' for every
                    // row, so the view fell back to the array index and row actions
                    // posted "0"/"1" as the entity id.
                    $rawId = (string) ($doc['id'] ?? ($d['id'] ?? ''));
                    $prefix = $this->school_id . '_';
                    $r['id'] = (strpos($rawId, $prefix) === 0)
                        ? substr($rawId, strlen($prefix))
                        : $rawId;
                    $list[] = $r;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'HR get_appraisals: Firestore read failed: ' . $e->getMessage());
        }

        // Sort by created_at descending
        usort($list, function ($a, $b) {
            return strcmp($b['created_at'] ?? $b['createdAt'] ?? '', $a['created_at'] ?? $a['createdAt'] ?? '');
        });

        $this->json_success(['appraisals' => $list]);
    }

    /**
     * POST — Create or update an appraisal (Draft state only for updates).
     */
    public function save_appraisal()
    {
        $this->_require_role(self::HR_ROLES, 'save_appraisal', 'HR', 'edit');

        $id               = trim($this->input->post('id') ?? '');
        $staffId          = $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id');
        $period           = trim($this->input->post('period') ?? '');
        $appraisalType    = trim($this->input->post('appraisal_type') ?? 'Annual');
        // Accept both field-name conventions (view sends teaching/behavior/innovation)
        $teachingQuality  = (float) ($this->input->post('teaching') ?? $this->input->post('teaching_quality') ?? 0);
        $punctuality      = (float) ($this->input->post('punctuality') ?? 0);
        $studentFeedback  = (float) ($this->input->post('behavior') ?? $this->input->post('student_feedback') ?? 0);
        $initiative       = (float) ($this->input->post('innovation') ?? $this->input->post('initiative') ?? 0);
        $teamwork         = (float) ($this->input->post('teamwork') ?? 0);
        $overallRating    = (float) ($this->input->post('overall_rating') ?? 0);
        $strengths        = trim($this->input->post('strengths') ?? '');
        $areasImprovement = trim($this->input->post('areas_of_improvement') ?? '');
        $recommendation   = trim($this->input->post('recommendation') ?? 'none');
        $comments         = trim($this->input->post('comments') ?? '');
        $goals            = trim($this->input->post('goals') ?? '');

        if ($period === '') {
            $this->json_error('Appraisal period is required (e.g., "2025-26 Term 1").');
        }

        // Validate appraisal type
        $validTypes = ['Annual', 'Probation', 'Promotion', 'Mid-Year', 'Special'];
        if (!in_array($appraisalType, $validTypes, true)) {
            $this->json_error('Invalid appraisal type.');
        }

        // Validate ratings (0-10 scale)
        $ratings = [$teachingQuality, $punctuality, $studentFeedback, $initiative, $teamwork, $overallRating];
        foreach ($ratings as $r) {
            if ($r < 0 || $r > 10) {
                $this->json_error('Ratings must be between 0 and 10.');
            }
        }

        // Verify staff exists (Firestore)
        $staffProfile = $this->_fsGetStaffProfile($staffId);
        if (!is_array($staffProfile)) {
            $this->json_error('Staff member not found.');
        }

        // Phase HR-1 — block appraisal create/edit for Inactive staff.
        // Reactivate the teacher first if you genuinely need to record a
        // final review; existing appraisal records remain editable until
        // status flips, which is the correct audit boundary.
        $rawStatus = (string) ($staffProfile['status'] ?? $staffProfile['Status'] ?? 'Active');
        if (strtolower(trim($rawStatus)) !== 'active') {
            $this->json_error('Cannot perform this action for an inactive staff member.');
        }

        $staffName  = $staffProfile['Name'] ?? $staffProfile['name'] ?? $staffId;
        $department = $staffProfile['Department'] ?? $staffProfile['department'] ?? '';

        if ($department === '') {
            $this->json_error('Selected staff has no department assigned. Please update staff profile first.');
        }

        $now   = date('c');
        $isNew = ($id === '');

        if ($isNew) {
            $id = $this->_next_id('APR', 'Appraisal');
        } else {
            // Only allow editing Draft appraisals. Firestore is the only source; a
            // failed read falls through to 'not found' (fail-closed). No RTDB path.
            $existing = null;
            try {
                $existing = $this->fs->getEntity('appraisals', $id);
            } catch (\Exception $e) {
                log_message('error', "HR save_appraisal: Firestore read FAILED for {$id}: " . $e->getMessage());
            }
            if (!is_array($existing)) {
                $this->json_error('Appraisal not found.');
            }
            if (($existing['status'] ?? '') !== 'Draft') {
                $this->json_error('Only Draft appraisals can be edited.');
            }
        }

        $reviewerId = $this->input->post('reviewer_id')
            ? $this->safe_path_segment(trim($this->input->post('reviewer_id')), 'reviewer_id')
            : $this->admin_id;

        $data = [
            'staff_id'              => $staffId,
            'staff_name'            => $staffName,
            'department'            => $department,
            'appraisal_type'        => $appraisalType,
            'period'                => $period,
            'reviewer_id'           => $reviewerId,
            'reviewer_name'         => $this->admin_name,
            'teaching_quality'      => $teachingQuality,
            'punctuality'           => $punctuality,
            'student_feedback'      => $studentFeedback,
            'initiative'            => $initiative,
            'teamwork'              => $teamwork,
            'overall_rating'        => $overallRating,
            'strengths'             => $strengths,
            'areas_of_improvement'  => $areasImprovement,
            'recommendation'        => $recommendation,
            'comments'              => $comments,
            'goals'                 => $goals,
            'status'                => 'Draft',
            'updated_at'            => $now,
            'updated_by'            => $this->admin_id,
            // Canonical camelCase mirror for cross-system consumers (Teacher app AppraisalDoc).
            'staffId'               => $staffId,
            'staffName'             => $staffName,
            'appraisalType'         => $appraisalType,
            'reviewerId'            => $reviewerId,
            'reviewerName'          => $this->admin_name,
            'teachingQuality'       => $teachingQuality,
            'studentFeedback'       => $studentFeedback,
            'overallRating'         => $overallRating,
            'areasOfImprovement'    => $areasImprovement,
        ];
        if ($isNew) {
            $data['created_at'] = $now;
            $data['created_by'] = $this->admin_id;
        }

        // ── Firestore-first write ────────────────────────────────────
        try {
            $this->fs->setEntity('appraisals', $id, $data);
            log_message('debug', "HR save_appraisal: Firestore OK for {$id}");
        } catch (\Exception $e) {
            log_message('error', "HR save_appraisal: Firestore FAILED for {$id}: " . $e->getMessage());
        }

        $this->json_success(['id' => $id, 'message' => $isNew ? 'Appraisal created.' : 'Appraisal updated.']);
    }

    /**
     * POST — Submit a draft appraisal for review.
     * Params: id
     */
    public function submit_appraisal()
    {
        $this->_require_role(self::HR_ROLES, 'submit_appraisal', 'HR', 'edit');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        // ── Firestore-first read ────────────────────────────────────
        $appraisal = null;
        try {
            $appraisal = $this->fs->getEntity('appraisals', $id);
        } catch (\Exception $e) {
            log_message('error', "HR submit_appraisal: Firestore read FAILED for {$id}: " . $e->getMessage());
        }
        // Firestore is the only source
        if (!is_array($appraisal)) {
            $this->json_error('Appraisal not found.');
        }
        if (($appraisal['status'] ?? '') !== 'Draft') {
            $this->json_error('Only Draft appraisals can be submitted.');
        }

        // Validate that key fields are filled
        $overallRating = (float) ($appraisal['overall_rating'] ?? 0);
        if ($overallRating <= 0) {
            $this->json_error('Overall rating must be set before submitting.');
        }

        $update = [
            'status'     => 'Submitted',
            'updated_at' => date('c'),
        ];

        // ── Firestore-first write ────────────────────────────────────
        try {
            $this->fs->updateEntity('appraisals', $id, $update);
            log_message('debug', "HR submit_appraisal: Firestore OK for {$id}");
        } catch (\Exception $e) {
            log_message('error', "HR submit_appraisal: Firestore FAILED for {$id}: " . $e->getMessage());
        }


        $this->json_success(['message' => 'Appraisal submitted for review.']);
    }

    /**
     * POST — Mark a submitted appraisal as reviewed (final).
     * Params: id, comments (optional additional reviewer comments)
     */
    public function review_appraisal()
    {
        $this->_require_role(self::HR_ROLES, 'review_appraisal', 'HR', 'edit');

        $id       = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');
        $comments = trim($this->input->post('comments') ?? '');

        // ── Firestore-first read ────────────────────────────────────
        $appraisal = null;
        try {
            $appraisal = $this->fs->getEntity('appraisals', $id);
        } catch (\Exception $e) {
            log_message('error', "HR review_appraisal: Firestore read FAILED for {$id}: " . $e->getMessage());
        }
        // Firestore is the only source
        if (!is_array($appraisal)) {
            $this->json_error('Appraisal not found.');
        }
        if (($appraisal['status'] ?? '') !== 'Submitted') {
            $this->json_error('Only Submitted appraisals can be reviewed.');
        }

        $update = [
            'status'     => 'Reviewed',
            'updated_at' => date('c'),
        ];
        if ($comments !== '') {
            $existingComments = $appraisal['comments'] ?? '';
            $update['comments'] = $existingComments . "\n[Review: " . date('Y-m-d') . '] ' . $comments;
        }

        // ── Firestore-first write ────────────────────────────────────
        try {
            $this->fs->updateEntity('appraisals', $id, $update);
            log_message('debug', "HR review_appraisal: Firestore OK for {$id}");
        } catch (\Exception $e) {
            log_message('error', "HR review_appraisal: Firestore FAILED for {$id}: " . $e->getMessage());
        }

        $this->json_success(['message' => 'Appraisal marked as reviewed.']);
    }

    /**
     * POST — Delete an appraisal (only Draft).
     * Params: id
     */
    public function delete_appraisal()
    {
        $this->_require_role(self::HR_ROLES, 'delete_appraisal', 'HR', 'manage');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        // ── Firestore-first read ────────────────────────────────────
        $appraisal = null;
        try {
            $appraisal = $this->fs->getEntity('appraisals', $id);
        } catch (\Exception $e) {
            log_message('error', "HR delete_appraisal: Firestore read FAILED for {$id}: " . $e->getMessage());
        }
        // Firestore is the only source
        if (!is_array($appraisal)) {
            $this->json_error('Appraisal not found.');
        }
        if (($appraisal['status'] ?? '') !== 'Draft') {
            $this->json_error('Only Draft appraisals can be deleted.');
        }

        // ── Firestore-first delete ──────────────────────────────────
        try {
            $this->fs->removeEntity('appraisals', $id);
            log_message('debug', "HR delete_appraisal: Firestore OK for {$id}");
        } catch (\Exception $e) {
            log_message('error', "HR delete_appraisal: Firestore FAILED for {$id}: " . $e->getMessage());
        }

        $this->json_success(['message' => 'Appraisal deleted.']);
    }

    // ====================================================================
    //  STAFF HR ADVANCED CAPABILITY PROGRAMME — Phase 7: Training /
    //  Development (2026-08-19)
    // ====================================================================
    //
    // Confirmed green-field by a dedicated research pass: no training,
    // certification, or skill-development concept existed anywhere for
    // staff (the one "training" hit in this file is unrelated free text in
    // a Leave Type description). Modeled directly on this SAME file's
    // Appraisals section immediately above — the closest existing
    // precedent for "an independently CRUD-able, per-staff HR record type"
    // — same collection shape (one doc per record, `_next_id()`-minted id,
    // dual snake_case/camelCase field mirror for cross-system consumers),
    // same RBAC grading (HR:view to list, HR:edit to save, HR:manage to
    // delete).
    //
    // Deliberately NO Draft→Submitted→Reviewed workflow (unlike
    // Appraisals) — a training/certification record is a factual log entry
    // HR maintains, not a graded evaluation requiring a review gate; adding
    // an approval workflow here would be inventing process the real-world
    // concept doesn't call for.
    //
    // Deliberately NO file/certificate upload here — Phase A's canonical
    // Document Service (Staff::upload_staff_document()) already exists for
    // exactly that, is already wired into edit_staff.php's "HR Documents"
    // section, and already supports arbitrary document types; attaching a
    // certificate scan is "upload it there", not a reason to build a
    // second, parallel upload path or a new writer.
    private const TRAINING_STATUSES = ['Planned', 'In Progress', 'Completed'];
    private const TRAINING_CATEGORIES = ['Training', 'Certification', 'Workshop', 'Course'];

    /**
     * GET — List training/development records. Optional filters:
     * ?staff_id=XXX&status=Completed&category=Certification
     */
    public function get_training_records()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_training_records', 'HR', 'view');

        $filterStaffId  = trim($this->input->get('staff_id') ?? '');
        $filterStatus   = trim($this->input->get('status') ?? '');
        $filterCategory = trim($this->input->get('category') ?? '');
        $list = [];

        try {
            $conditions = [];
            if ($filterStaffId !== '') $conditions[] = ['staff_id', '==', $filterStaffId];
            if ($filterStatus !== '') $conditions[] = ['status', '==', $filterStatus];
            if ($filterCategory !== '') $conditions[] = ['category', '==', $filterCategory];
            $fsDocs = $this->fs->schoolWhere('staffTraining', $conditions);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $r = is_array($doc['data'] ?? null) ? $doc['data'] : [];
                    // Wrapper bug (as in get_departments): the doc id is on the wrapper,
                    // not inside data. Reading it from the data dict yielded '' for every
                    // row, so the view fell back to the array index and row actions
                    // posted "0"/"1" as the entity id.
                    $rawId = (string) ($doc['id'] ?? ($r['id'] ?? ''));
                    $prefix = $this->school_id . '_';
                    $r['id'] = (strpos($rawId, $prefix) === 0) ? substr($rawId, strlen($prefix)) : $rawId;
                    $list[] = $r;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'HR get_training_records: Firestore read failed: ' . $e->getMessage());
        }

        usort($list, function ($a, $b) {
            return strcmp($b['created_at'] ?? $b['createdAt'] ?? '', $a['created_at'] ?? $a['createdAt'] ?? '');
        });

        $this->json_success(['training_records' => $list]);
    }

    /**
     * POST — Create or update a training/development record.
     */
    public function save_training_record()
    {
        $this->_require_role(self::HR_ROLES, 'save_training_record', 'HR', 'edit');

        $id            = trim($this->input->post('id') ?? '');
        $staffId       = $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id');
        $title         = trim($this->input->post('title') ?? '');
        $provider      = trim($this->input->post('provider') ?? '');
        $category      = trim($this->input->post('category') ?? 'Training');
        $status        = trim($this->input->post('status') ?? 'Completed');
        $completedDate = trim($this->input->post('completed_date') ?? '');
        $expiryDate    = trim($this->input->post('expiry_date') ?? '');
        $hours         = trim($this->input->post('hours') ?? '');
        $notes         = trim($this->input->post('notes') ?? '');

        if ($title === '') $this->json_error('Training/certification title is required.');
        if (!in_array($category, self::TRAINING_CATEGORIES, true)) $this->json_error('Invalid category.');
        if (!in_array($status, self::TRAINING_STATUSES, true)) $this->json_error('Invalid status.');
        foreach ([$completedDate, $expiryDate] as $d) {
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $this->json_error('Dates must be in YYYY-MM-DD format.');
        }
        if ($status === 'Completed' && $completedDate === '') {
            $this->json_error('Completed date is required for a Completed record.');
        }

        $staffProfile = $this->_fsGetStaffProfile($staffId);
        if (!is_array($staffProfile)) $this->json_error('Staff member not found.');

        $rawStatus = (string) ($staffProfile['status'] ?? $staffProfile['Status'] ?? 'Active');
        if (strtolower(trim($rawStatus)) !== 'active') {
            $this->json_error('Cannot perform this action for an inactive staff member.');
        }

        $staffName  = $staffProfile['Name'] ?? $staffProfile['name'] ?? $staffId;
        $department = $staffProfile['Department'] ?? $staffProfile['department'] ?? '';

        $now   = date('c');
        $isNew = ($id === '');

        if ($isNew) {
            $id = $this->_next_id('TRN', 'Training');
        } else {
            $existing = null;
            try {
                $existing = $this->fs->getEntity('staffTraining', $id);
            } catch (\Exception $e) {
                log_message('error', "HR save_training_record: Firestore read FAILED for {$id}: " . $e->getMessage());
            }
            if (!is_array($existing)) $this->json_error('Training record not found.');
        }

        $data = [
            'staff_id'        => $staffId,
            'staff_name'      => $staffName,
            'department'      => $department,
            'title'           => $title,
            'provider'        => $provider,
            'category'        => $category,
            'status'          => $status,
            'completed_date'  => $completedDate,
            'expiry_date'     => $expiryDate,
            'hours'           => $hours,
            'notes'           => $notes,
            'updated_at'      => $now,
            'updated_by'      => $this->admin_id,
            // Canonical camelCase mirror for cross-system consumers.
            'staffId'         => $staffId,
            'staffName'       => $staffName,
            'completedDate'   => $completedDate,
            'expiryDate'      => $expiryDate,
        ];
        if ($isNew) {
            $data['created_at'] = $now;
            $data['created_by'] = $this->admin_id;
        }

        try {
            $this->fs->setEntity('staffTraining', $id, $data);
            log_message('debug', "HR save_training_record: Firestore OK for {$id}");
        } catch (\Exception $e) {
            log_message('error', "HR save_training_record: Firestore FAILED for {$id}: " . $e->getMessage());
        }

        $this->json_success(['id' => $id, 'message' => $isNew ? 'Training record added.' : 'Training record updated.']);
    }

    /**
     * POST — Delete a training/development record.
     * Params: id
     */
    public function delete_training_record()
    {
        $this->_require_role(self::HR_ROLES, 'delete_training_record', 'HR', 'manage');

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');

        $record = null;
        try {
            $record = $this->fs->getEntity('staffTraining', $id);
        } catch (\Exception $e) {
            log_message('error', "HR delete_training_record: Firestore read FAILED for {$id}: " . $e->getMessage());
        }
        if (!is_array($record)) $this->json_error('Training record not found.');

        try {
            $this->fs->removeEntity('staffTraining', $id);
            log_message('debug', "HR delete_training_record: Firestore OK for {$id}");
        } catch (\Exception $e) {
            log_message('error', "HR delete_training_record: Firestore FAILED for {$id}: " . $e->getMessage());
        }

        $this->json_success(['message' => 'Training record deleted.']);
    }

    // ====================================================================
    //  STAFF HR ADVANCED CAPABILITY PROGRAMME — Phase 8: Reporting
    //  Hierarchy / Org Chart (2026-08-19)
    // ====================================================================
    //
    // Confirmed green-field by a dedicated research pass: `reportingManagerId`
    // has been STORED on the staff doc and cycle-VALIDATED at write time
    // (Staff_employment_history_service::validateManagerAssignment()) since
    // Phase 2C, but was never RENDERED as a hierarchy anywhere in the
    // codebase — HR could set a manager but never see the resulting shape.
    //
    // STRICTLY READ-ONLY. This endpoint writes nothing, and deliberately
    // creates no second source of truth for the hierarchy: the tree is
    // derived, every request, from the SAME `reportingManagerId` field the
    // existing writers already own. There is no denormalised "reports[]"
    // array, no cached tree document, nothing to drift.
    //
    // It is also a DIAGNOSTIC surface, modelled on
    // Work_schedules::_blockedStaff() — the closest existing precedent in
    // this codebase for "make the unresolvable visible rather than
    // silently skipping it." That matters here specifically because
    // validateManagerAssignment() deliberately tolerates several states it
    // cannot safely reject at write time, and this is the first surface
    // that can see them at all:
    //   • orphan_manager   — reportingManagerId points at a doc that does
    //                        not resolve in this tenant. validateManagerAssignment()
    //                        explicitly does NOT reject a broken link found
    //                        mid-chain ("a broken link ends the walk without
    //                        rejecting"), so these can accumulate.
    //   • inactive_manager — the manager was Active when assigned (that IS
    //                        validated) but was deactivated LATER; nothing
    //                        re-checks existing reports on deactivation.
    //   • cycle            — validateManagerAssignment() breaks out of a
    //                        PRE-EXISTING loop rather than blocking on it
    //                        ("pre-existing corrupt loop unrelated to this
    //                        new assignment — stop, don't block"), so a
    //                        legacy cycle stays invisible until rendered.
    //   • self_reference   — rejected at write time, but legacy/imported
    //                        data predates that check.
    // Reporting them is the whole point; a chart that silently dropped a
    // cyclic branch would read as "the hierarchy is fine."
    //
    // ONE bulk read for the whole roster, tree built in memory — the same
    // no-N+1 discipline _schedule_assignments() already established for
    // per-employee lookups that grow with headcount.
    private const ORG_CHART_MAX_DEPTH = 25; // mirrors REPORTING_MANAGER_MAX_CHAIN_DEPTH

    /**
     * GET — the reporting hierarchy for this tenant, as a flat node map plus
     * root ids plus a diagnostics list. Flat (not pre-nested) deliberately:
     * the client needs the flat map for the diagnostics panel anyway, and a
     * flat payload avoids deep-nesting serialisation limits on a large roster.
     */
    public function get_org_chart()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_org_chart', 'HR', 'view');

        // ALL staff, not just Active — an Inactive manager who still has
        // Active reports is precisely one of the defects this surface
        // exists to reveal, and filtering them out here would hide it.
        $nodes = [];
        try {
            $fsDocs = $this->fs->schoolWhere('staff', []);
            foreach ((is_array($fsDocs) ? $fsDocs : []) as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                if (!is_array($d)) continue;
                $sid = trim((string) ($d['staffId'] ?? $d['userId'] ?? ''));
                if ($sid === '') continue;
                $nodes[$sid] = [
                    'staffId'    => $sid,
                    'name'       => (string) ($d['Name'] ?? $d['name'] ?? $sid),
                    'position'   => (string) ($d['designation'] ?? $d['Position'] ?? $d['position'] ?? ''),
                    'department' => (string) ($d['Department'] ?? $d['department'] ?? ''),
                    'managerId'  => trim((string) ($d['reportingManagerId'] ?? '')),
                    'status'     => (string) ($d['status'] ?? $d['Status'] ?? 'Active'),
                ];
            }
        } catch (\Exception $e) {
            log_message('error', 'HR get_org_chart: Firestore read failed: ' . $e->getMessage());
            return $this->json_error('Could not load the reporting hierarchy.');
        }

        $issues = [];
        $cyclic = [];   // staffIds proven to sit on a cycle — excluded from roots

        // ── Per-node link integrity ──────────────────────────────────
        foreach ($nodes as $sid => $n) {
            $mid = $n['managerId'];
            if ($mid === '') continue;

            if ($mid === $sid) {
                $issues[] = [
                    'type' => 'self_reference', 'staffId' => $sid, 'staffName' => $n['name'],
                    'detail' => 'This employee is recorded as their own reporting manager.',
                ];
                continue;
            }
            if (!isset($nodes[$mid])) {
                $issues[] = [
                    'type' => 'orphan_manager', 'staffId' => $sid, 'staffName' => $n['name'],
                    'detail' => "Reporting manager '{$mid}' does not exist in this school's staff records.",
                ];
                continue;
            }
            if (strtolower(trim($nodes[$mid]['status'])) !== 'active'
                && strtolower(trim($n['status'])) === 'active') {
                $issues[] = [
                    'type' => 'inactive_manager', 'staffId' => $sid, 'staffName' => $n['name'],
                    'detail' => "Reports to {$nodes[$mid]['name']}, who is no longer active.",
                ];
            }
        }

        // ── Cycle detection (iterative, bounded) ─────────────────────
        // Walks each node's manager chain upward. Nodes proven to reach a
        // root are memoised as 'safe' so the whole roster costs one pass,
        // not one walk per node. The walk is kept as an ORDERED list, not
        // just a lookup set, because only the segment from the
        // loop-closing node onward is actually ON the loop — anything
        // before it is a tail feeding INTO the loop, and naming those in
        // the cycle path (or marking them cyclic) would misreport where
        // the corruption actually is. Tail nodes are caught instead by the
        // reachability pass below.
        $safe = [];
        foreach (array_keys($nodes) as $startId) {
            if (isset($safe[$startId]) || isset($cyclic[$startId])) continue;

            $order = [];    // ordered walk
            $pos    = [];   // staffId → index in $order
            $cursor = $startId;
            $depth  = 0;
            $hitCycle = false;
            while ($cursor !== '' && $depth++ < self::ORG_CHART_MAX_DEPTH) {
                if (isset($safe[$cursor])) break;                 // reaches a known-good root
                if (isset($cyclic[$cursor])) { $hitCycle = true; break; }
                if (isset($pos[$cursor])) {                       // closed a loop
                    $loop = array_slice($order, $pos[$cursor]);
                    foreach ($loop as $pid) $cyclic[$pid] = true;
                    $issues[] = [
                        'type' => 'cycle', 'staffId' => $cursor, 'staffName' => $nodes[$cursor]['name'],
                        'detail' => 'Circular reporting chain: ' . implode(' → ', array_map(
                            fn($pid) => $nodes[$pid]['name'], $loop)) . ' → ' . $nodes[$cursor]['name'],
                    ];
                    $hitCycle = true;
                    break;
                }
                $pos[$cursor] = count($order);
                $order[] = $cursor;
                $next = $nodes[$cursor]['managerId'] ?? '';
                if ($next === '' || !isset($nodes[$next]) || $next === $cursor) break;  // root / broken link / self
                $cursor = $next;
            }
            // Only a walk that genuinely terminated at a root proves its
            // whole path reaches one. A walk that ran into a cycle proves
            // the opposite for every node on it.
            if (!$hitCycle) {
                foreach ($order as $pid) $safe[$pid] = true;
            }
        }

        // ── Roots: no manager, or a manager that cannot be followed ──
        // A node whose manager is an orphan/self-reference is rendered as a
        // root so the branch below it stays visible instead of vanishing.
        // Cyclic nodes are deliberately NOT roots — they are reported as an
        // issue instead of being drawn as a fake top-level branch.
        $roots = [];
        foreach ($nodes as $sid => $n) {
            if (isset($cyclic[$sid])) continue;
            $mid = $n['managerId'];
            if ($mid === '' || $mid === $sid || !isset($nodes[$mid])) $roots[] = $sid;
        }

        usort($roots, fn($a, $b) => strcmp($nodes[$a]['name'], $nodes[$b]['name']));

        // ── Reachability — nothing may silently vanish from the chart ──
        // The client renders downward from $roots. Any node a root cannot
        // reach (e.g. an employee whose manager sits on a cycle) would
        // simply never be drawn, and an incomplete chart that LOOKS
        // complete is exactly the failure mode Work_schedules::_blockedStaff()
        // exists to prevent. Report them rather than lose them.
        $childrenOf = [];
        foreach ($nodes as $sid => $n) {
            if (isset($cyclic[$sid])) continue;   // never drawn as anyone's child
            $mid = $n['managerId'];
            if ($mid !== '' && $mid !== $sid && isset($nodes[$mid])) $childrenOf[$mid][] = $sid;
        }
        $reachable = [];
        $stack = $roots;
        while (!empty($stack)) {
            $id = array_pop($stack);
            if (isset($reachable[$id])) continue;
            $reachable[$id] = true;
            foreach ($childrenOf[$id] ?? [] as $childId) $stack[] = $childId;
        }
        foreach ($nodes as $sid => $n) {
            if (isset($reachable[$sid]) || isset($cyclic[$sid])) continue;
            $issues[] = [
                'type' => 'unreachable', 'staffId' => $sid, 'staffName' => $n['name'],
                'detail' => 'Not reachable from any top-level manager — the reporting chain above this employee is broken or circular.',
            ];
        }

        return $this->json_success([
            'nodes'       => $nodes,
            'roots'       => $roots,
            'issues'      => $issues,
            'cyclic_ids'  => array_keys($cyclic),
            'total_staff' => count($nodes),
        ]);
    }

    // ====================================================================
    //  UTILITY
    // ====================================================================

    /**
     * GET — Returns staff from session roster for dropdowns.
     * Returns: [{id, name, department, position, email, phone}]
     */
    public function get_staff_list()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_staff_list', 'HR', 'view');

        // Firestore-first: read staff directly from staff collection
        $list = [];
        try {
            $fsDocs = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            foreach ($fsDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $sid = $d['staffId'] ?? $d['userId'] ?? '';
                if ($sid === '') continue;
                $list[] = [
                    'id'           => $sid,
                    'name'         => $d['Name'] ?? $d['name'] ?? $sid,
                    'Name'         => $d['Name'] ?? $d['name'] ?? $sid,
                    'department'   => $d['Department'] ?? $d['department'] ?? '',
                    'position'     => $d['designation'] ?? $d['Position'] ?? $d['position'] ?? '',
                    'designation'  => $d['designation'] ?? $d['Position'] ?? '',
                    'email'        => $d['Email'] ?? $d['email'] ?? '',
                    'phone'        => $d['Phone Number'] ?? $d['phone'] ?? '',
                    'staff_roles'  => $d['staff_roles'] ?? [],
                    'primary_role' => $d['primary_role'] ?? '',
                ];
            }
        } catch (\Exception $e) {
            log_message('error', "HR get_staff_list: Firestore failed: " . $e->getMessage());
            // Firestore failed — return empty list
            log_message('error', "HR get_staff_list: Firestore failed completely");
        }

        usort($list, function ($a, $b) { return strcmp($a['name'], $b['name']); });
        $this->json_success(['staff' => $list]);
    }

    /**
     * POST — Backfill salary structures for all staff who have registration
     * salary data but no Salary_Structures entry.
     *
     * Safe to run multiple times — skips existing structures.
     * Uses the same allocation logic as Staff::_sync_salary_structure().
     */
    public function backfill_salary_structures()
    {
        $this->_require_role(self::ADMIN_ROLES, 'backfill_salary_structures', 'HR', 'manage');

        $school  = $this->school_name;
        $session = $this->session_year;

        // Get all staff profiles (Firestore)
        $profiles = [];
        try {
            $fsDocs = $this->fs->schoolWhere('staff', []);
            if (is_array($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? '';
                    if ($sid !== '') $profiles[$sid] = $d;
                }
            }
        } catch (\Exception $e) {}
        if (empty($profiles)) {
            $this->json_success(['message' => 'No staff found.', 'created' => 0, 'skipped' => 0]);
            return;
        }
        // Audit C1: merge server-only salaryDetails for the structure backfill.
        $profiles = $this->_apply_staff_private_map($profiles);

        // Get existing structures (Firestore)
        $existing = [];
        try {
            $fsSals = $this->fs->schoolWhere('salarySlips', [['type', '==', 'salary_structure']]);
            if (is_array($fsSals)) {
                foreach ($fsSals as $doc) {
                    $sid = $doc['data']['staffId'] ?? '';
                    if ($sid !== '') $existing[$sid] = $doc['data'];
                }
            }
        } catch (\Exception $e) {}

        // Use staff profiles as roster
        $roster = $profiles;

        $created = [];
        $skipped = [];
        $noSalary = [];
        $now = date('c');

        // Default split ratios (same as Staff.php)
        $hraPct = 40; $daPct = 10;

        foreach ($roster as $staffId => $_) {
            // Already has a structure → skip
            if (isset($existing[$staffId]) && is_array($existing[$staffId])) {
                $skipped[] = $staffId;
                continue;
            }

            $profile = $profiles[$staffId] ?? null;
            if (!is_array($profile)) { $skipped[] = $staffId; continue; }

            $salDet = $profile['salaryDetails'] ?? [];
            $basic  = (float) ($salDet['basicSalary'] ?? $salDet['basic_salary'] ?? $salDet['basic'] ?? 0);
            $allow  = (float) ($salDet['Allowances'] ?? $salDet['allowances'] ?? 0);

            if ($basic <= 0) {
                $noSalary[] = $staffId;
                continue;
            }

            // Compute breakdown
            $hra = round($basic * ($hraPct / 100), 2);
            $da  = round($basic * ($daPct / 100), 2);
            $remaining = max(0, $allow - $hra - $da);
            if ($allow < ($hra + $da)) {
                $hra = round($allow * 0.6, 2);
                $da  = round($allow * 0.3, 2);
                $remaining = max(0, $allow - $hra - $da);
            }
            $ta      = round($remaining * 0.30, 2);
            $medical = round($remaining * 0.25, 2);
            $other   = round($remaining - $ta - $medical, 2);

            $structure = [
                'basic' => $basic, 'hra' => $hra, 'da' => $da, 'ta' => $ta,
                'medical' => $medical, 'other_allowances' => $other,
                'pf_employee' => 12, 'pf_employer' => 12,
                'esi_employee' => 0.75, 'esi_employer' => 3.25,
                'professional_tax' => 200, 'tds' => 0, 'other_deductions' => 0,
                'source' => 'registration', 'created_at' => $now, 'updated_at' => $now,
                'updated_by' => $this->admin_name ?? 'system', 'last_synced_at' => $now,
            ];

            $this->_fsSyncSalary($staffId, $structure);
            $created[] = $staffId;
        }

        log_message('info',
            "backfill_salary_structures: school=[{$school}] "
            . "created=" . count($created) . " skipped=" . count($skipped)
            . " no_salary=" . count($noSalary)
        );

        $this->json_success([
            'message'   => count($created) > 0
                ? count($created) . ' salary structure(s) created.'
                : 'All staff already have salary structures.',
            'created'   => count($created),
            'skipped'   => count($skipped),
            'no_salary' => count($noSalary),
        ]);
    }

    // ====================================================================
    //  REPORTS
    // ====================================================================

    /**
     * GET — Get HR summary report for export/print.
     * ?type=staff|leaves|payroll|departments
     */
    public function get_report()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_report', 'HR', 'manage');

        $type = trim($this->input->get('type') ?? 'staff');

        switch ($type) {
            case 'staff':
                return $this->_report_staff();
            case 'leaves':
                return $this->_report_leaves();
            case 'payroll':
                return $this->_report_payroll();
            case 'departments':
                return $this->_report_departments();
            default:
                $this->json_error('Invalid report type. Use: staff, leaves, payroll, departments.');
        }
    }

    /**
     * Staff report: roster with departments and salary info.
     */
    private function _report_staff()
    {
        // Firestore roster
        $roster = [];
        try {
            $fsStaff = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            if (is_array($fsStaff)) {
                foreach ($fsStaff as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? '';
                    if ($sid !== '') $roster[$sid] = true;
                }
            }
        } catch (\Exception $e) {}

        // ── Firestore-first: staff profiles ──
        $profiles = null;
        try {
            $fsDocs = $this->fs->schoolWhere('staff', []);
            if (!empty($fsDocs)) {
                $profiles = [];
                foreach ($fsDocs as $doc) {
                    $d = $doc['data'];
                    $sid = $d['staffId'] ?? ($d['id'] ?? '');
                    if ($sid !== '') $profiles[$sid] = $d;
                }
            }
        } catch (\Exception $e) {
            log_message('error', "HR _report_staff FS profiles failed: " . $e->getMessage());
        }
        if ($profiles === null) $profiles = [];

        // ── Firestore-first: salary structures ──
        $salaries = null;
        try {
            $fsSals = $this->fs->schoolWhere('salarySlips', [['type', '==', 'salary_structure']]);
            if (!empty($fsSals)) {
                $salaries = [];
                foreach ($fsSals as $doc) {
                    $sid = $doc['data']['staffId'] ?? '';
                    if ($sid !== '') $salaries[$sid] = $doc['data'];
                }
            }
        } catch (\Exception $e) {
            log_message('error', "HR _report_staff FS salaries failed: " . $e->getMessage());
        }
        if ($salaries === null) $salaries = [];

        $list = [];
        if (is_array($roster)) {
            foreach ($roster as $staffId => $rd) {
                $p = (is_array($profiles) && isset($profiles[$staffId]) && is_array($profiles[$staffId]))
                    ? $profiles[$staffId]
                    : [];
                $s = (is_array($salaries) && isset($salaries[$staffId]) && is_array($salaries[$staffId]))
                    ? $salaries[$staffId]
                    : [];

                $gross = 0;
                if (!empty($s)) {
                    $gross = (float) ($s['basic'] ?? 0)
                        + (float) ($s['hra'] ?? 0)
                        + (float) ($s['da'] ?? 0)
                        + (float) ($s['ta'] ?? 0)
                        + (float) ($s['medical'] ?? 0)
                        + (float) ($s['other_allowances'] ?? 0);
                }

                $list[] = [
                    'staff_id'      => $staffId,
                    'name'          => $p['Name'] ?? $staffId,
                    'department'    => $p['Department'] ?? $p['department'] ?? '',
                    'position'      => $p['Position'] ?? '',
                    'phone'         => $p['Phone'] ?? '',
                    'email'         => $p['Email'] ?? '',
                    'qualification' => $p['Qualification'] ?? '',
                    'gross_salary'  => round($gross, 2),
                    'has_salary'    => !empty($s),
                ];
            }
        }

        usort($list, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $this->json_success(['report' => 'staff', 'data' => $list, 'total' => count($list)]);
    }

    /**
     * Leave report: summary of all leave requests and balances.
     */
    private function _report_leaves()
    {
        $year = trim($this->input->get('year') ?? date('Y'));

        // ── Firestore-first: leave requests via helper ──
        $requests = $this->_fsGetAllLeaveRequests();

        // ── Firestore-first: leave balances via helper ──
        $balances = $this->_fsGetLeaveBalance('', $year);

        $summary = [
            'total_requests' => 0,
            'pending'        => 0,
            'approved'       => 0,
            'rejected'       => 0,
            'cancelled'      => 0,
        ];

        $byStaff = [];
        if (is_array($requests)) {
            foreach ($requests as $r) {
                $summary['total_requests']++;
                $status = strtolower($r['status'] ?? '');
                if (isset($summary[$status])) {
                    $summary[$status]++;
                }

                $sid = $r['staff_id'] ?? '';
                if ($sid !== '') {
                    if (!isset($byStaff[$sid])) {
                        $byStaff[$sid] = [
                            'staff_name'    => $r['staff_name'] ?? $sid,
                            'total_days'    => 0,
                            'approved_days' => 0,
                        ];
                    }
                    $days = (float) ($r['days'] ?? $r['numberOfDays'] ?? 0);
                    $byStaff[$sid]['total_days'] += $days;
                    // H1: $status already lowercased above — compare lowercase.
                    if ($status === 'approved') {
                        $byStaff[$sid]['approved_days'] += $days;
                    }
                }
            }
        }

        $this->json_success([
            'report'   => 'leaves',
            'year'     => $year,
            'summary'  => $summary,
            'by_staff' => array_values($byStaff),
            'balances' => is_array($balances) ? $balances : [],
        ]);
    }

    /**
     * Payroll report: all runs for this session.
     */
    private function _report_payroll()
    {
        // ── Firestore-first ──
        $runs = null;
        try {
            $fsDocs = $this->fs->schoolWhere('salarySlips', [['type', '==', 'run']]);
            if (!empty($fsDocs)) {
                $runs = [];
                foreach ($fsDocs as $doc) {
                    // Disaster-recovery wrapper bug fix: Firestore doc id lives on
                    // the WRAPPER (`$doc['id']`), not inside data. Reading $d['id']
                    // emitted "Undefined array key 'id'" warnings on the dashboard.
                    $docId = $doc['id'] ?? '';
                    if ($docId === '') continue;
                    $parts = explode('_RUN_', $docId, 2);
                    $rid = isset($parts[1]) ? $parts[1] : $docId;
                    $runs[$rid] = $doc['data'] ?? [];
                }
            }
        } catch (\Exception $e) {
            log_message('error', "HR _report_payroll FS failed: " . $e->getMessage());
        }
        if ($runs === null) $runs = [];

        $list = [];
        $totalPaid = 0;

        if (is_array($runs)) {
            foreach ($runs as $id => $r) {
                $r['id'] = $id;
                $list[] = $r;
                if (($r['status'] ?? '') === 'Paid') {
                    $totalPaid += (float) ($r['total_net'] ?? 0);
                }
            }
        }

        usort($list, function ($a, $b) {
            return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
        });

        $this->json_success([
            'report'     => 'payroll',
            'session'    => $this->session_year,
            'runs'       => $list,
            'total_paid' => round($totalPaid, 2),
            'run_count'  => count($list),
        ]);
    }

    /**
     * Departments report: departments with staff counts.
     */
    private function _report_departments()
    {
        // Departments from Firestore
        $depts = [];
        try {
            $fsSchool = $this->fs->get('schools', $this->fs->schoolId());
            if (is_array($fsSchool) && is_array($fsSchool['departments'] ?? null)) {
                $depts = $fsSchool['departments'];
            }
        } catch (\Exception $e) {}

        // Roster from Firestore
        $roster = [];
        try {
            $fsStaff = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            if (is_array($fsStaff)) {
                foreach ($fsStaff as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? '';
                    if ($sid !== '') $roster[$sid] = true;
                }
            }
        } catch (\Exception $e) {}

        // ── Firestore-first: staff profiles ──
        $profiles = null;
        try {
            $fsDocs = $this->fs->schoolWhere('staff', []);
            if (!empty($fsDocs)) {
                $profiles = [];
                foreach ($fsDocs as $doc) {
                    $d = $doc['data'];
                    $sid = $d['staffId'] ?? ($d['id'] ?? '');
                    if ($sid !== '') $profiles[$sid] = $d;
                }
            }
        } catch (\Exception $e) {
            log_message('error', "HR _report_departments FS failed: " . $e->getMessage());
        }
        if ($profiles === null) $profiles = [];

        // Count staff per department — keyed on the normalized name so every
        // case/whitespace variant sums into one bucket; keep a display name.
        $countByDept  = [];
        $displayByKey = [];
        if (is_array($roster) && is_array($profiles)) {
            foreach ($roster as $sid => $rd) {
                $dept = (isset($profiles[$sid]) && is_array($profiles[$sid]))
                    ? ($profiles[$sid]['Department'] ?? $profiles[$sid]['department'] ?? 'Unassigned')
                    : 'Unassigned';
                if (trim((string) $dept) === '') $dept = 'Unassigned';
                $dk = dept_key($dept);
                $countByDept[$dk] = ($countByDept[$dk] ?? 0) + 1;
                if (!isset($displayByKey[$dk])) $displayByKey[$dk] = $dept;
            }
        }

        $list = [];
        $assignedKeys = [];
        if (is_array($depts)) {
            foreach ($depts as $id => $d) {
                $dName = $d['name'] ?? '';
                $d['id']          = $id;
                $d['staff_count'] = $countByDept[dept_key($dName)] ?? 0;
                $list[] = $d;
                $assignedKeys[dept_key($dName)] = true;
            }
        }

        // Add "Unassigned" or any departments not in the formal list
        foreach ($countByDept as $dk => $cnt) {
            if (!isset($assignedKeys[$dk])) {
                $list[] = [
                    'id'          => '',
                    'name'        => $displayByKey[$dk] ?? $dk,
                    'staff_count' => $cnt,
                    'status'      => 'N/A',
                ];
            }
        }

        $this->json_success(['report' => 'departments', 'data' => $list]);
    }

    /* ================================================================
       PAYROLL HARDENING — mid-month join/exit + unlock + regenerate
       ================================================================ */

    /**
     * M-4: Normalize a date string to canonical YYYY-MM-DD.
     * Accepts:
     *   • YYYY-MM-DD
     *   • YYYY/MM/DD
     *   • DD-MM-YYYY      (Indian school convention)
     *   • DD/MM/YYYY
     * Returns null on unparseable input and logs a warning with $context.
     */
    private function _normalize_date(?string $input, string $context = ''): ?string
    {
        if ($input === null) return null;
        $s = trim($input);
        if ($s === '') return null;

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m))   return "{$m[1]}-{$m[2]}-{$m[3]}";
        if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/', $s, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $s, $m))   return "{$m[3]}-{$m[2]}-{$m[1]}";
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";

        log_message('error', "M-4 date normalize failed for '{$s}'" . ($context !== '' ? " (context: {$context})" : ''));
        return null;
    }

    /**
     * Is $date (YYYY-MM-DD) within the staff's employment window?
     * Empty / unparseable join or exit dates are treated as no constraint
     * (defensive — better to pay than to silently under-deduct).
     * M-4: accepts join/exit dates in multiple formats via _normalize_date.
     */
    private function _in_employment_window(string $date, ?string $joinDate, ?string $exitDate): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return true;
        $jd = $this->_normalize_date($joinDate, 'employment_window.joinDate');
        $xd = $this->_normalize_date($exitDate, 'employment_window.exitDate');
        if ($jd !== null && $date < $jd) return false;
        if ($xd !== null && $date > $xd) return false;
        return true;
    }

    /**
     * Process a single approved leave doc and produce the payroll-relevant
     * fragments (leave_details entry + LWP day attribution).
     *
     * Single source of truth for CR-1 / CR-3 / CR-4 payroll behavior.
     * Used by BOTH:
     *   • generate_payroll (run-wide leaves loop)
     *   • regenerate_staff_payroll (single-staff leaves loop)
     * to guarantee identical outputs.
     *
     * Caller responsibility:
     *   - filter $leaveDoc by staff (regenerate side) or status (both sides)
     *     before calling — this fn returns null on status!=Approved or
     *     month-no-overlap so a generic foreach can call unconditionally.
     *   - merge the returned fragments into its own accumulators.
     *
     * Returns null when the leave should be ignored entirely. Otherwise:
     *   [
     *     'leaveDetail' => assoc array suitable for leave_details[] push,
     *     'lwpDays'     => float days to add to staff's lwpByStaff total,
     *     'lwpDaySet'   => array<int dayNum, true|0.5 portion>,
     *   ]
     */
    private function _process_leave_for_payroll(
        array $leaveDoc,
        array $leaveTypeConfig,
        array $staffProfile,
        string $monthStart,
        string $monthEnd,
        string $rid = ''
    ): ?array {
        // H1: read status case-insensitively (leaves are now written lowercase).
        if (strtolower((string) ($leaveDoc['status'] ?? '')) !== 'approved') return null;

        $lrFrom = (string) ($leaveDoc['from_date'] ?? '');
        $lrTo   = (string) ($leaveDoc['to_date']   ?? '');
        if ($lrFrom === '' || $lrTo === '') return null;
        if ($lrFrom > $monthEnd || $lrTo < $monthStart) return null;

        // Resolve leave type config + paid flag (CR-3 fallback)
        $ltId  = (string) ($leaveDoc['type_id'] ?? '');
        $ltCfg = $leaveTypeConfig[$ltId] ?? null;
        $ltCode = is_array($ltCfg)
            ? strtoupper(trim($ltCfg['code'] ?? ''))
            : (string) ($leaveDoc['type_code'] ?? '?');

        if (is_array($ltCfg)) {
            $ltPaid = ($ltCfg['paid'] === true || $ltCfg['paid'] === 'true' || $ltCfg['paid'] === '1');
        } else {
            // CR-3: deleted leave type → fall back to leave doc's stored type_paid.
            $ltPaid = filter_var(
                $leaveDoc['type_paid'] ?? $leaveDoc['typePaid'] ?? true,
                FILTER_VALIDATE_BOOLEAN
            );
            $ridLog = $rid !== '' ? "for '{$rid}'" : '';
            log_message('error', "_process_leave_for_payroll: type '{$ltId}' missing {$ridLog} — fallback type_paid=" . ($ltPaid ? 'true' : 'false'));
        }

        // Half-day flag (Phase 4)
        $isHalfDay  = filter_var($leaveDoc['half_day'] ?? $leaveDoc['halfDay'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $halfPeriod = strtoupper(trim((string) ($leaveDoc['half_day_period'] ?? $leaveDoc['halfDayPeriod'] ?? '')));

        // Always emit a leave detail (even when no LWP attribution applies)
        // so the slip's Leave Breakdown lists every overlapping leave.
        $leaveDetail = [
            'request_id'      => $rid !== '' ? $rid : (string) ($leaveDoc['requestId'] ?? $leaveDoc['id'] ?? ''),
            'type_code'       => $ltCode,
            'type_name'       => $leaveDoc['type_name'] ?? ($ltCfg['name'] ?? 'Unknown'),
            'paid'            => $ltPaid,
            'from'            => $lrFrom,
            'to'              => $lrTo,
            // Fraction safety (2026-08-19). These three were (int) casts, and
            // this $leaveDetail array is FROZEN onto the payslip as
            // leave_details[] — the permanent record of a pay decision. A
            // half-day leave was therefore DEDUCTED as 0.5 (the half-day
            // branch below hard-codes 0.5 and bypasses $lwpDaysOnDoc) while
            // being DOCUMENTED as 0, so the slip contradicted itself and an
            // auditor could not reconcile the deduction against the detail.
            'total_days'      => (float) ($leaveDoc['days']      ?? 0),
            'paid_days'       => (float) ($leaveDoc['paid_days'] ?? 0),
            'lwp_days'        => (float) ($leaveDoc['lwp_days']  ?? 0),
            'half_day'        => $isHalfDay,
            'half_day_period' => $isHalfDay ? $halfPeriod : '',
            'pay_label'       => $leaveDoc['pay_label'] ?? ($ltPaid ? 'Paid Leave' : 'Unpaid Leave (LWP)'),
        ];

        $emptyResult = ['leaveDetail' => $leaveDetail, 'lwpDays' => 0.0, 'lwpDaySet' => [], 'paidDaySet' => []];

        // CR-4: staff employment window for filtering pre-join/post-exit days.
        // Hoisted above the CR-1 early return below so the PAID half-day
        // branch can apply the same window filter.
        $jd = $staffProfile['joinDate']  ?? $staffProfile['Date Of Joining'] ?? $staffProfile['Joining Date'] ?? null;
        $xd = $staffProfile['exitDate']  ?? $staffProfile['Resignation Date'] ?? $staffProfile['leaveDate']  ?? null;

        // CR-1: only skip LWP attribution when paid type AND no LWP days. A
        // paid type with lwp_days>0 (balance was exhausted at decide time)
        // MUST still produce a deduction.
        //
        // ── MONEY DEFECT FIXED (2026-08-19) ──
        // This was `(int) (...)`, and lwp_days is provably fractional: a
        // half-day leave stores 0.5 (apply_leave ~:4964), and
        // `lwpDays = days - paidDays` is computed against a balance that is
        // explicitly float ("balances may be fractional (0.5) after half-day
        // deductions"). The cast manufactured exactly the integer that the
        // strict `=== 0` tests below were looking for, so:
        //   • a PAID half-day with lwp_days = 0.5 cast to 0, satisfied
        //     `=== 0`, and was treated as fully covered — the 0.5-day
        //     deduction was silently never taken;
        //   • a 3-day leave against a 1.5-day balance (lwp_days = 1.5) cast
        //     to 1, charging 1 day of LOP instead of 1.5.
        // Both are under-deductions, i.e. the employer pays for time that
        // was not covered. Now a float, with the comparisons below widened
        // from `=== 0` to `<= 0.0` so they still mean "no LWP at all".
        $lwpDaysOnDoc = (float) ($leaveDoc['lwp_days'] ?? 0);

        // ── HALF-DAY PROGRAMME (2026-08-19) — PAID half-day coverage ──
        //
        // A PAID half-day leave produces no LWP, so it used to fall straight
        // into the CR-1 early return below and report NOTHING. But half-day
        // leave deliberately writes no attendance mark (the employee really
        // did work the other half), so the day still carries whatever
        // attendance was recorded — typically 'M'.
        //
        // The payroll absence loop guards 'M' only against the LWP day set:
        //     if (!isset($staffLwpSet[$dayNum])) $daysAbsent += 0.5;
        // With nothing reported, that guard saw nothing and charged 0.5 day
        // absent — so an employee on APPROVED PAID half-day leave was docked
        // half a day's pay. (On a 44,000 gross over 22 working days that is
        // a silent 1,000 deduction; see HalfDayReconciliationTest.)
        //
        // Reporting the day in a separate `paidDaySet` lets the loop treat it
        // as COVERED without adding any LWP. The three concepts stay
        // separate: this records that approved leave covers the day, it does
        // not convert an attendance half-day into leave, and it does not
        // touch Calendar Half-Day (which remains a declared calendar fact
        // with no pay effect, pending an explicit policy decision).
        if ($isHalfDay && $ltPaid && $lwpDaysOnDoc <= 0.0) {
            if ($lrFrom < $monthStart || $lrFrom > $monthEnd) return $emptyResult;
            if (!$this->_in_employment_window($lrFrom, $jd, $xd)) return $emptyResult;
            return [
                'leaveDetail' => $leaveDetail,
                'lwpDays'     => 0.0,                                        // paid — never a deduction
                'lwpDaySet'   => [],
                'paidDaySet'  => [(int) date('j', strtotime($lrFrom)) => 0.5],
            ];
        }

        if ($ltPaid && $lwpDaysOnDoc <= 0.0) return $emptyResult;

        // Half-day branch — always single-day, contributes 0.5 LWP
        if ($isHalfDay) {
            if ($lrFrom < $monthStart || $lrFrom > $monthEnd) return $emptyResult;
            if (!$this->_in_employment_window($lrFrom, $jd, $xd)) return $emptyResult;
            $dn = (int) date('j', strtotime($lrFrom));
            return [
                'leaveDetail' => $leaveDetail,
                'lwpDays'     => 0.5,
                'lwpDaySet'   => [$dn => 0.5],
                'paidDaySet'  => [],
            ];
        }

        // Multi-day LWP branch — paid days are consumed FIRST, so the LWP
        // days sit at the END of the leave span.
        //
        // A fractional total is real: a 3-day leave against a 1.5-day balance
        // leaves lwp_days = 1.5. The span then covers ceil(1.5) = 2 calendar
        // days — the LAST day fully unpaid, and the day before it half
        // unpaid. Previously `$lwpDaysOnDoc - 1` was fed straight to
        // strtotime(), which cannot express a fractional day offset, and the
        // (int) cast upstream had already flattened 1.5 to 1 so the span was
        // simply one day short.
        if ($lwpDaysOnDoc <= 0.0) return $emptyResult;

        $fullDays = (int) floor($lwpDaysOnDoc);
        $fraction = round($lwpDaysOnDoc - $fullDays, 2);   // 0.0 or 0.5 in practice
        $spanDays = (int) ceil($lwpDaysOnDoc);

        $lwpStart = date('Y-m-d', strtotime("{$lrTo} -" . ($spanDays - 1) . " days"));
        if ($lwpStart > $monthEnd || $lrTo < $monthStart) return $emptyResult;
        $overlapStart = max($lwpStart, $monthStart);
        $overlapEnd   = min($lrTo, $monthEnd);

        $lwpInWindow = 0.0;
        $lwpDaySet   = [];
        $cursor = new DateTime($overlapStart);
        $end    = new DateTime($overlapEnd);
        while ($cursor <= $end) {
            $dateStr = $cursor->format('Y-m-d');
            if ($this->_in_employment_window($dateStr, $jd, $xd)) {
                // Only the EARLIEST day of the span can be partial — the
                // remaining days are fully unpaid.
                $isSpanStart = ($dateStr === $lwpStart);
                $portion     = ($isSpanStart && $fraction > 0.0) ? $fraction : true;
                $lwpInWindow += ($portion === true) ? 1.0 : $portion;
                $lwpDaySet[(int) $cursor->format('j')] = $portion;
            }
            $cursor->modify('+1 day');
        }

        return [
            'leaveDetail' => $leaveDetail,
            'lwpDays'     => (float) $lwpInWindow,
            'lwpDaySet'   => $lwpDaySet,
            'paidDaySet'  => [],
        ];
    }

    /**
     * Compute per-staff working days within their employment window
     * (joinDate / exitDate) for a given month. Excludes Sundays,
     * 2nd & 4th Saturdays, and configured holidays — same calendar
     * as generate_payroll's global computation, just clipped to the
     * employment window.
     *
     * @param array $holidays  ['dayNum' => true] for holidays in this month
     * @return array {
     *   'working'  => int,                   // working days in window
     *   'inWindow' => array<int,bool>,       // dayNum => true if inside window
     *   'startDay' => int,                   // 1-based day where window starts
     *   'endDay'   => int                    // 1-based day where window ends
     * }
     */
    /**
     * CALENDAR HALF-DAY — what one declared half-day contributes to EXPECTED
     * WORK for the given payroll period (2026-08-19, authorized model S1–S4).
     *
     * Returns 0.5 only when BOTH hold:
     *   1. the tenant has explicitly enabled the model, and
     *   2. the period start is on/after the tenant's chosen effective date.
     * Otherwise 1.0 — byte-identical to the pre-existing behaviour.
     *
     * Per-tenant, not deployment-wide: the setting lives on the school
     * document alongside the rest of the attendance policy, so one school
     * enabling it cannot move another school's payroll. The effective-date
     * boundary is what protects HISTORICAL runs — regenerating a period from
     * before the boundary still resolves 1.0 and reproduces the original
     * figures exactly.
     *
     * Fails CLOSED to 1.0 on any read error: an unreadable flag must never
     * silently reprice payroll.
     */
    private function _half_day_weight(int $year, int $monthNum): float
    {
        static $memo = null;   // per-request; the school doc cannot change mid-run
        if ($memo === null) {
            $memo = ['enabled' => false, 'from' => ''];
            try {
                $school = $this->fs->get('schools', $this->school_id);
                $pol = (is_array($school) && is_array($school['attendancePolicy'] ?? null))
                    ? $school['attendancePolicy'] : [];
                $memo['enabled'] = !empty($pol['calendarHalfDayPayEnabled']);
                $memo['from']    = (string) ($pol['calendarHalfDayPayEffectiveFrom'] ?? '');
            } catch (\Throwable $e) {
                log_message('error', 'Hr::_half_day_weight config read failed: ' . $e->getMessage());
                $memo = ['enabled' => false, 'from' => ''];
            }
        }
        if (empty($memo['enabled'])) return 1.0;

        // An enabled tenant with no effective date set is treated as NOT yet
        // rolled out — an explicit boundary is required, never assumed.
        $from = $memo['from'];
        if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) return 1.0;

        $periodStart = sprintf('%04d-%02d-01', $year, $monthNum);
        return ($periodStart >= $from) ? 0.5 : 1.0;
    }

    /**
     * N-40 — split an employment window into consecutive same-department runs.
     *
     * Returns at least one segment. With no employment-timeline history (every
     * tenant today) the department is constant and exactly ONE segment comes
     * back, so the caller behaves exactly as it did before segmentation existed.
     *
     * `$fallback` is the caller's period-start department, used for any day the
     * timeline cannot answer for — a missing history must never blank out a
     * department that payroll already had.
     *
     * @return array<int, array{department: string, from: int, to: int}>
     */
    private function _department_segments(
        string $staffId, int $year, int $monthNum, int $startDay, int $endDay, string $fallback
    ): array {
        if ($startDay > $endDay) return [];

        $segments = [];
        $current  = null;

        for ($d = $startDay; $d <= $endDay; $d++) {
            $dateISO = sprintf('%04d-%02d-%02d', $year, $monthNum, $d);

            $dept = $fallback;
            if ($staffId !== '') {
                $resolved = $this->_department_as_of($staffId, $dateISO, $fallback);
                if (is_string($resolved) && $resolved !== '') $dept = $resolved;
            }

            if ($current !== null && $current['department'] === $dept) {
                $current['to'] = $d;
                continue;
            }
            if ($current !== null) $segments[] = $current;
            $current = ['department' => $dept, 'from' => $d, 'to' => $d];
        }

        if ($current !== null) $segments[] = $current;
        return $segments;
    }

    /**
     * N-40 — resolve one segment's calendar for a single department.
     *
     * Extracted verbatim from `_staff_working_days()` so the same resolution
     * runs once per department segment. Nothing about the resolution changed;
     * only how many times it is invoked.
     */
    private function _resolve_segment_calendar(
        int $year, int $monthNum, array $holidays, string $staffId, string $department, array $window
    ): array {
        // Phase D — re-derive holidays / special-working-days / half-days
        // FILTERED for this employee AND this segment's department. The
        // holiday service caches its calendarEvents read per request, so
        // re-filtering per segment costs no extra Firestore read.
        $staffHolidays      = $holidays;
        $specialWorkingDays = [];
        $halfDays           = [];

        if (isset($this->holiday_service) && $this->holiday_service->isReady()) {
            $staffHolidays      = $this->holiday_service->holidays_in_month($year, $monthNum, 'staff', $department, $staffId);
            $specialWorkingDays = $this->holiday_service->special_working_days_in_month($year, $monthNum, 'staff', $department, $staffId);
            $halfDays           = $this->holiday_service->half_days_in_month($year, $monthNum, 'staff', $department, $staffId);
        }

        require_once APPPATH . 'libraries/Work_schedule_service.php';

        return Work_schedule_service::resolveEmployeeCalendar(
            $this->_attendance_policy(), $this->_schedule_assignments(), $staffId,
            $year, $monthNum, $staffHolidays, $window, $department,
            $specialWorkingDays, $halfDays,
            $this->_half_day_weight($year, $monthNum)
        );
    }

    private function _staff_working_days(
        int $monthNum, int $year, array $holidays,
        ?string $joinDate, ?string $exitDate,
        string $staffId = '', string $department = ''
    ): array {
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $monthNum, 1, $year));
        $monthStart  = sprintf('%04d-%02d-01',  $year, $monthNum);
        $monthEnd    = sprintf('%04d-%02d-%02d', $year, $monthNum, $daysInMonth);

        // M-4: Accept multiple date formats; unparseable → no constraint.
        $jd = $this->_normalize_date($joinDate, 'staff_working_days.joinDate') ?? '';
        $xd = $this->_normalize_date($exitDate, 'staff_working_days.exitDate') ?? '';

        $windowStart = ($jd !== '' && $jd > $monthStart) ? $jd : $monthStart;
        $windowEnd   = ($xd !== '' && $xd < $monthEnd)   ? $xd : $monthEnd;

        if ($windowStart > $windowEnd) {
            // Staff did not work this month at all (joined after / left before)
            return ['working' => 0, 'inWindow' => [], 'startDay' => 0, 'endDay' => 0];
        }

        $startDay = (int) substr($windowStart, 8, 2);
        $endDay   = (int) substr($windowEnd, 8, 2);

        // Employment window only. This loop no longer decides ANY weekday rule —
        // no Sunday check, no Saturday check, no nth-Saturday counter. Payroll
        // holds zero working-week business logic (N-23/P-021 §9).
        $inWindow = [];
        for ($d = $startDay; $d <= $endDay; $d++) $inWindow[$d] = true;

        // Phase D (2026-08-18) — re-derive holidays/special-working-days/
        // half-days FILTERED for this employee (audience=staff, department,
        // exceptStaff), rather than trusting the school-wide $holidays the
        // caller loaded once for the whole run. Holiday_service caches the
        // raw calendarEvents read per request, so re-filtering per employee
        // costs no extra Firestore read — it only re-scans the in-memory map.
        // Falls back to the unfiltered $holidays param if holiday_service
        // isn't loaded (defensive; both current call sites load it).
        // ── N-40 (2026-08-22) — DEPARTMENT IS RESOLVED PER DAY ───────────
        //
        // Payroll used to resolve department ONCE at $periodStart and apply that
        // department's calendar to the whole month, while Leave and Attendance
        // both resolve per day (Hr::_department_as_of, Attendance::
        // _department_as_of, Staff_attendance_finaliser::_departmentAsOf).
        // Payroll was the lone outlier, so an employee transferred mid-month had
        // days priced against the OLD department's holiday/half-day calendar —
        // and expected work is the denominator of the pay retention ratio.
        //
        // The month is split into consecutive same-department SEGMENTS and the
        // calendar is resolved once per segment. With no transfer there is
        // exactly ONE segment and the result is byte-identical to before, which
        // is every tenant today (0 timeline rows). A transfer produces two.
        //
        // This is NOT a per-day query: `_department_as_of()` is memoised per
        // (staffId|date) and the timeline is warmed once per run, so a segmented
        // month costs the same Firestore reads as an unsegmented one.
        $segments = $this->_department_segments($staffId, $year, $monthNum, $startDay, $endDay, $department);

        $merged = null;
        foreach ($segments as $seg) {
            $segDept = $seg['department'];

            // Only this segment's days are in scope for this resolution.
            $segWindow = [];
            for ($d = $seg['from']; $d <= $seg['to']; $d++) $segWindow[$d] = true;

            $segCal = $this->_resolve_segment_calendar(
                $year, $monthNum, $holidays, $staffId, $segDept, $segWindow
            );

            if ($merged === null) {
                $merged = $segCal;
                continue;
            }

            // Merge per-day maps; sum the counts. Each segment only ever
            // contributes days it owns, so no day is counted twice.
            foreach (['isWorking', 'reason', 'workingMask'] as $k) {
                foreach ((array) ($segCal[$k] ?? []) as $day => $v) {
                    if (isset($segWindow[$day])) $merged[$k][$day] = $v;
                }
            }
            $merged['workingCount'] = (float) $merged['workingCount'] + (float) $segCal['workingCount'];
            $merged['halfDayCount'] = (int) ($merged['halfDayCount'] ?? 0) + (int) ($segCal['halfDayCount'] ?? 0);

            // A block anywhere in the month blocks the employee — payroll must
            // not price a month it could only partly resolve.
            if (!empty($segCal['blocked'])) {
                $merged['blocked']     = true;
                $merged['blockReason'] = $segCal['blockReason'];
                $merged['blockCode']   = $segCal['blockCode'] ?? '';
            }
        }

        $cal = $merged ?? $this->_resolve_segment_calendar(
            $year, $monthNum, $holidays, $staffId, $department, $inWindow
        );

        // THIS EMPLOYEE's calendar (N-33). Two employees in the same run can
        // legitimately have different working days, so the calendar is resolved
        // per staff member from their effective schedule assignment — never
        // from a tenant-wide calendar reused for everyone.
        //
        // A missing/expired/ambiguous assignment comes back BLOCKED with a
        // named reason. Payroll refuses that employee rather than inventing a
        // calendar; the run continues for employees who are correctly assigned.
        return [
            'working'   => $cal['workingCount'],
            'inWindow'  => $inWindow,
            'startDay'  => $startDay,
            'endDay'    => $endDay,
            // Consumed by the attendance parse loops and frozen into the slip.
            'isWorking'    => $cal['isWorking'],
            'dayReason'    => $cal['reason'],
            'workingMask'  => $cal['workingMask'],
            'calBlocked'   => $cal['blocked'],
            'calBlockWhy'  => $cal['blockReason'],
            'calBlockCode' => $cal['blockCode'] ?? '',
            // Trail 2 Phase C (2026-08-18) — surfaces the Calendar Half Day
            // count Work_schedule_service already computes (its own docblock:
            // "informational only, does not change workingCount arithmetic").
            // Previously computed here and silently discarded — never read by
            // either call site. Exposed now so a caller CAN see it; 'working'
            // and every other key above are UNCHANGED, so this has zero effect
            // on today's payable-days math. Whether/how a half day should
            // reduce payable days is an unresolved financial-policy question
            // (full pay vs. 0.5 reduction vs. "half attendance excuses the
            // other half") — not decided here, not invented here.
            'halfDayCount' => $cal['halfDayCount'] ?? 0,
            // Identity of WHOSE calendar — frozen into the slip so a later
            // schedule change cannot reinterpret a closed period.
            'scheduleId'              => $cal['scheduleId'] ?? '',
            'scheduleName'            => $cal['scheduleName'] ?? '',
            'assignmentEffectiveFrom' => $cal['assignmentEffectiveFrom'] ?? '',
            'policyVersion'       => $cal['policyVersion'],
            'policyEffectiveFrom' => $cal['policyEffectiveFrom'],
            'policySource'        => $cal['policySource'],
        ];
    }

    /**
     * Workforce Policy Programme Phase F (2026-08-18) — Leave calendar-
     * awareness. Counts the CHARGEABLE (working) days within [fromDate,
     * toDate] for one staff member, excluding weekly-offs and holidays via
     * the SAME canonical resolver Payroll uses for LOP
     * (Work_schedule_service::resolveEmployeeCalendar /
     * Working_calendar_service) — so leave-balance deduction and LOP can
     * never disagree about "what counts as a working day". A Special
     * Working Day inside the range still counts as chargeable (it IS a day
     * the employee would otherwise have to attend).
     *
     * Fails OPEN to the pre-existing raw calendar-day span when the
     * employee's work-schedule calendar cannot be resolved (no assignment
     * configured, ambiguous, etc.) — filing leave is a self-service HR
     * action, not a financial calculation, and must never hard-block on a
     * payroll-config gap. Callers should treat `calendarAware=false` as
     * "unchanged legacy behaviour", never as a silent wrong answer.
     *
     * Deliberately NOT used for half-day leave — half-day stays a fixed 0.5
     * per the existing, separately-authorized Calendar-Half-Day/Attendance-
     * Half-Day/Half-Day-Leave boundary; this only affects multi/single
     * FULL-day span counting.
     *
     * @return array{days: float, calendarAware: bool, blockReason: string}
     */
    /**
     * ZenX Leave Policy Programme, Phase 1 (2026-08-18) — reads the two
     * OPTIONAL, additive tenant fields Leave_year_service consumes:
     * `schools/{id}.academicYearStartMonth` (int 1-12, default 4 — the
     * pre-existing April convention already duplicated at Sis.php/
     * Attendance.php/Fees.php, now centralised rather than reinvented) and
     * `schools/{id}.sessionBoundaries` (an optional per-session override
     * map). Neither field exists on any tenant today; their absence is the
     * expected, fully-supported case — Leave_year_service derives correct
     * bounds from the session label alone when both are empty/default.
     *
     * Read-only. Does not gate on LEAVE_SESSION_YEAR_ENABLED itself —
     * that's the caller's job, once a caller exists (Phase 2+). This
     * helper only has zero effect today because nothing calls it yet.
     *
     * @return array{startMonth:int, overrides:array}
     */
    private function _leave_year_config(): array
    {
        static $cfg = null;
        if ($cfg !== null) return $cfg;
        require_once APPPATH . 'libraries/Leave_year_service.php';
        $startMonth = \Leave_year_service::DEFAULT_START_MONTH;
        $overrides  = [];
        try {
            $school = $this->fs->get('schools', $this->school_id);
            if (is_array($school)) {
                $sm = (int) ($school['academicYearStartMonth'] ?? 0);
                if ($sm >= 1 && $sm <= 12) $startMonth = $sm;
                $ov = $school['sessionBoundaries'] ?? null;
                if (is_array($ov)) $overrides = $ov;
            }
        } catch (\Throwable $e) {
            log_message('error', 'Hr::_leave_year_config failed, using default April-start: ' . $e->getMessage());
        }
        $cfg = ['startMonth' => $startMonth, 'overrides' => $overrides];
        return $cfg;
    }

    /**
     * ZenX Leave Policy Programme, Phase 1 — is the session-year balance
     * keying live for this tenant? Gate lives here (not inline at each call
     * site) so Phase 2+ can add their own gates the same way without
     * re-deriving the config->load() boilerplate each time.
     */
    private function _leave_flag(string $name): bool
    {
        // PURE PER-SCHOOL (2026-09-20): a leave-policy capability is the SCHOOL's
        // own choice — resolved ONLY from schools.leavePolicy[$name]. Absent ⇒ OFF.
        // The legacy fleet-wide `leave_policy_v2_flags` fallback has been CUT: the
        // developer ships the machinery, the school decides the policy from its
        // own config page, and a new school defaults OFF with no code involvement.
        // Cache is keyed by school so the fleet cron (which re-points school_id)
        // reads each tenant fresh; within one web request the key never changes.
        static $lpBySchool = [];
        $sid = (string) ($this->school_id ?? '');
        if (!array_key_exists($sid, $lpBySchool)) {
            try {
                $doc = $this->fs->get('schools', $sid);
                $lpBySchool[$sid] = (is_array($doc) && is_array($doc['leavePolicy'] ?? null)) ? $doc['leavePolicy'] : [];
            } catch (\Throwable $e) {
                $lpBySchool[$sid] = [];
            }
        }
        $v = $lpBySchool[$sid][$name] ?? false;
        return ($v === true || $v === 1 || $v === '1' || $v === 'yes' || $v === 'true');
    }

    private function _count_chargeable_leave_days(string $staffId, string $liveFallbackDepartment, string $fromDate, string $toDate): array
    {
        $rawSpan = (float) ((new DateTime($fromDate))->diff(new DateTime($toDate))->days + 1);

        if (!isset($this->holiday_service) || !$this->holiday_service->isReady()) {
            return ['days' => $rawSpan, 'calendarAware' => false, 'blockReason' => 'Holiday service unavailable'];
        }

        require_once APPPATH . 'libraries/Work_schedule_service.php';
        $assignments = $this->_schedule_assignments();
        $attendancePolicy = $this->_attendance_policy();

        $from = new DateTime($fromDate);
        $to   = new DateTime($toDate);
        $chargeable = 0.0;

        // Trail 2 Priority 2 (2026-08-19) — resolve department AS OF the
        // relevant date(s), not just the live/passed-in value for the whole
        // span. Staff::transfer_staff() writes staff.Department immediately
        // regardless of the chosen effective date (Trail 2 Phase I/J
        // precedent), so a leave request spanning a transfer's effective
        // date must count old-department working days before that date and
        // new-department working days on/after it. Reuses the SAME
        // read-only Staff_employment_history_service::resolveAttributeAsOf()
        // Payroll and Attendance already use — no second history system, no
        // new writer.
        //
        // Performance (no-N+1 gate): resolveAttributeAsOf() is a real
        // Firestore query with no internal caching. Calling it once per
        // calendar day (up to LEAVE_MAX_SPAN_DAYS = 366) would be an N+1 on
        // every single leave application, the overwhelming majority of
        // which never cross a transfer date at all. Instead: probe just the
        // two span endpoints (2 queries, memoized). If they agree — the
        // common case — the whole span shares one department and the
        // original single-department month-slice loop below runs unchanged
        // (net cost: +2 queries vs. the pre-fix baseline of 0). Only when
        // the endpoints genuinely disagree (a transfer's effective date
        // truly falls inside this span) does the rarer, more expensive
        // per-day path run, bounded by the same 366-day span cap.
        // Known, disclosed limit: this endpoint probe cannot detect a
        // double transfer that lands the department back at its original
        // value entirely inside the span (e.g. A→B on day 3, B→A on day 5
        // of a 10-day request) — an extreme edge case Payroll's own Phase J
        // resolver does not attempt to handle either (it resolves once, at
        // period start, and explicitly defers mid-period splits). A single
        // transfer crossing the span — the actual scenario this priority
        // was scoped to fix — is always caught, since exactly that changes
        // the start/end probe values.
        $deptAtStart = $this->_department_as_of($staffId, $fromDate, $liveFallbackDepartment);
        $deptAtEnd   = $this->_department_as_of($staffId, $toDate, $liveFallbackDepartment);

        if ($deptAtStart === $deptAtEnd) {
            return $this->_chargeable_days_single_department($staffId, $deptAtStart, $from, $to, $rawSpan, $attendancePolicy, $assignments);
        }

        // A transfer's effective date genuinely falls inside this span —
        // resolve department per day (memoized by exact date, so a retried/
        // duplicate call for the same date is free) and cache each month's
        // resolved calendar per (year, month, department) pair, so a run of
        // consecutive same-department days within a month still resolves
        // that month's holiday/SWD/half-day batch only once.
        $monthCalCache = [];
        $cursor = clone $from;
        while ($cursor <= $to) {
            $dateISO    = $cursor->format('Y-m-d');
            $department = $this->_department_as_of($staffId, $dateISO, $liveFallbackDepartment);

            $year     = (int) $cursor->format('Y');
            $monthNum = (int) $cursor->format('n');
            $day      = (int) $cursor->format('j');
            $cacheKey = $year . '-' . $monthNum . '|' . $department;

            if (!isset($monthCalCache[$cacheKey])) {
                $monthEndDay = (int) $cursor->format('t');
                $inWindow = [];
                for ($d = 1; $d <= $monthEndDay; $d++) $inWindow[$d] = true;

                $holidays = $this->holiday_service->holidays_in_month($year, $monthNum, 'staff', $department, $staffId);
                $swd      = $this->holiday_service->special_working_days_in_month($year, $monthNum, 'staff', $department, $staffId);
                $halfDays = $this->holiday_service->half_days_in_month($year, $monthNum, 'staff', $department, $staffId);

                $monthCalCache[$cacheKey] = Work_schedule_service::resolveEmployeeCalendar(
                    $attendancePolicy, $assignments, $staffId, $year, $monthNum,
                    $holidays, $inWindow, $department, $swd, $halfDays
                );
            }

            $cal = $monthCalCache[$cacheKey];
            if (!empty($cal['blocked'])) {
                return ['days' => $rawSpan, 'calendarAware' => false, 'blockReason' => (string) ($cal['blockReason'] ?? '')];
            }
            if (!empty($cal['isWorking'][$day])) $chargeable += 1.0;

            $cursor->modify('+1 day');
        }

        return ['days' => $chargeable, 'calendarAware' => true, 'blockReason' => ''];
    }

    /**
     * The pre-Priority-2 month-slice chargeable-day loop, unchanged, for the
     * common case where the whole [$from, $to] span shares one resolved
     * department (no transfer's effective date falls inside it).
     */
    private function _chargeable_days_single_department(string $staffId, string $department, DateTime $from, DateTime $to, float $rawSpan, ?array $attendancePolicy, array $assignments): array
    {
        $chargeable = 0.0;
        $cursor = clone $from;

        while ($cursor <= $to) {
            $year        = (int) $cursor->format('Y');
            $monthNum    = (int) $cursor->format('n');
            $monthEndDay = (int) $cursor->format('t');
            $monthEnd    = new DateTime(sprintf('%04d-%02d-%02d', $year, $monthNum, $monthEndDay));
            $sliceEnd    = ($monthEnd < $to) ? $monthEnd : $to;

            $startDay = (int) $cursor->format('j');
            $endDay   = (int) $sliceEnd->format('j');
            $inWindow = [];
            for ($d = $startDay; $d <= $endDay; $d++) $inWindow[$d] = true;

            $holidays = $this->holiday_service->holidays_in_month($year, $monthNum, 'staff', $department, $staffId);
            $swd      = $this->holiday_service->special_working_days_in_month($year, $monthNum, 'staff', $department, $staffId);
            $halfDays = $this->holiday_service->half_days_in_month($year, $monthNum, 'staff', $department, $staffId);

            // S2 (authorized 2026-08-19): leave consumption is measured in
            // EXPECTED-WORK units, so the same half-day weight applies here.
            $hdWeight = $this->_half_day_weight($year, $monthNum);
            $cal = Work_schedule_service::resolveEmployeeCalendar(
                $attendancePolicy, $assignments, $staffId, $year, $monthNum,
                $holidays, $inWindow, $department, $swd, $halfDays, $hdWeight
            );

            if (!empty($cal['blocked'])) {
                return ['days' => $rawSpan, 'calendarAware' => false, 'blockReason' => (string) ($cal['blockReason'] ?? '')];
            }

            for ($d = $startDay; $d <= $endDay; $d++) {
                if (empty($cal['isWorking'][$d])) continue;
                // S2: a full-day leave on a Calendar Half-Day consumes only
                // 0.5, because only 0.5 of work was expected. You cannot be
                // absent from work that was never expected.
                $chargeable += (($cal['reason'][$d] ?? '') === \Working_calendar_service::REASON_HALF_DAY)
                    ? $hdWeight : 1.0;
            }

            $cursor = (clone $sliceEnd)->modify('+1 day');
        }

        return ['days' => $chargeable, 'calendarAware' => true, 'blockReason' => ''];
    }

    /**
     * Resolve the department that was ACTUALLY effective for $staffId on
     * $dateISO, per Staff_employment_history_service's read-only
     * staffEmploymentTimeline lookup. Falls open to $liveFallback on any
     * error or absent history (the common case — most staff have never
     * been transferred) — identical to pre-fix behaviour in that case, so
     * nothing regresses for the overwhelming majority of leave requests.
     * Memoised per (staffId, date) — the same pattern used by
     * Staff_attendance::_department_as_of(),
     * Staff_attendance_finaliser::_departmentAsOf(), and
     * Attendance::_department_as_of(). No second history system.
     */
    private function _department_as_of(string $staffId, string $dateISO, string $liveFallback): string
    {
        static $deptMemo = [];
        $k = $staffId . '|' . $dateISO;
        if (array_key_exists($k, $deptMemo)) return $deptMemo[$k];
        try {
            $this->load->library('staff_employment_history_service');
            $this->staff_employment_history_service->init($this->fs, $this->school_id, (string) ($this->admin_id ?? ''));
            $hist = $this->staff_employment_history_service->resolveAttributeAsOf($staffId, 'department', $dateISO);
            if ($hist !== null && $hist !== '') return $deptMemo[$k] = $hist;
        } catch (\Throwable $e) {
            log_message('error', 'Hr::_department_as_of failed for ' . $staffId . ' ' . $dateISO . ': ' . $e->getMessage());
        }
        return $deptMemo[$k] = $liveFallback;
    }

    /**
     * Every employee→schedule assignment on the tenant, in ONE query (N-32).
     *
     * Memoised per request and loaded in bulk deliberately: a payroll run
     * resolves the whole roster, so a per-employee read would be an N+1 that
     * grows with headcount — the same trap `Compensation_reader::resolveMany()`
     * and the W3.2.5 batched writes exist to avoid.
     *
     * @return array<string,array> staffId → assignment rows
     */
    /**
     * ALL active work-schedule assignments on the tenant, flat and
     * normalised — any attachment level. Not grouped by staffId: a
     * department-level assignment has no staffId field at all, and
     * `Policy_assignment_service::resolve()` (the canonical resolver,
     * converged onto 2026-08-18) does its own per-employee/per-department
     * filtering internally, so it needs the whole tenant list, not a
     * pre-filtered slice.
     */
    private function _schedule_assignments(): array
    {
        static $list = null;
        if ($list !== null) return $list;

        require_once APPPATH . 'libraries/Work_schedule_service.php';
        $svc = new Work_schedule_service();
        $svc->init($this->fs, $this->admin_name ?: $this->admin_id);
        $list = $svc->allAssignmentsForRun();
        return $list;
    }

    /**
     * The tenant's attendance policy document, memoised per request.
     * Read-only: payroll consumes policy, it never authors or defaults it.
     */
    private function _attendance_policy(): ?array
    {
        static $pol = null;
        static $loaded = false;
        if ($loaded) return $pol;
        $loaded = true;

        try {
            $school = $this->fs->get('schools', $this->school_id);
            $pol = (is_array($school) && is_array($school['attendancePolicy'] ?? null))
                ? $school['attendancePolicy'] : null;
        } catch (\Exception $e) {
            log_message('error', 'HR: attendance policy read failed: ' . $e->getMessage());
            $pol = null;
        }
        return $pol;
    }

    /**
     * POST — Unlock a Finalized payroll run so individual slips can be
     * regenerated. Reverses finalize_payroll. Reason ≥10 chars required;
     * audit-logged.
     */
    public function unlock_payroll()
    {
        $this->_require_role(self::ADMIN_ROLES, 'unlock_payroll', 'HR', 'manage');

        $runId  = $this->safe_path_segment(trim($this->input->post('run_id') ?? ''), 'run_id');
        $reason = trim((string) $this->input->post('reason'));

        if ($runId === '')             $this->json_error('run_id is required.');
        if (strlen($reason) < 10)      $this->json_error('Reason ≥10 chars required.');

        $run = $this->_fsGetRun($runId);
        if (!is_array($run)) $this->json_error('Payroll run not found.');
        $curStatus = (string) ($run['status'] ?? '');
        // M-6: accept both Finalized AND Approved (admin sometimes needs to
        // walk back from Approved when a late correction lands).
        if (!in_array($curStatus, ['Finalized', 'Approved'], true)) {
            $this->json_error("Only Finalized or Approved runs can be unlocked. Current status: " . ($curStatus !== '' ? $curStatus : 'unknown'));
        }

        // Flip run status
        $update = [
            'status'             => 'Draft',
            'unlocked_at'        => date('c'),
            'unlocked_by'        => $this->admin_name ?: $this->admin_id,
            'unlock_reason'      => $reason,
            'unlocked_from_status' => $curStatus,    // audit context: was it Finalized or Approved?
        ];
        $this->_fsSyncPayrollRun($runId, array_merge($run, $update));

        // Cascade slip statuses
        $slips = $this->_fsGetAllSlips($runId);
        foreach ($slips as $sid => $s) {
            $this->_fsSyncPayslip($runId, $sid, array_merge($s, ['status' => 'Draft']));
        }

        $this->_log_payroll('unlocked', $runId, [
            'reason'      => $reason,
            'unlocked_by' => $update['unlocked_by'],
            'slip_count'  => count($slips),
        ]);
        // Unlocking reopens a frozen financial period — the single most
        // sensitive payroll transition. The caller-supplied reason is
        // already mandatory here, so it is carried into the audit entry.
        $this->_audit_payroll('payroll_unlocked', $runId,
            "Payroll run unlocked ({$curStatus} → Draft): {$reason}",
            $this->_audit_run_meta($run, [
                'toStatus'   => 'Draft',
                'fromStatus' => $curStatus,
                'reason'     => $reason,
                'slipCount'  => count($slips),
            ]));

        $this->json_success([
            'message' => 'Payroll run unlocked. Edits and regeneration are now allowed.',
            'run_id'  => $runId,
            'status'  => 'Draft',
        ]);
    }

    /**
     * POST — Regenerate ONE staff member's slip in a Draft payroll run.
     * Re-reads attendance + leave + salary structure, applies the
     * same per-day formula as generate_payroll, archives the previous
     * slip, replaces it, and updates run-level totals.
     *
     * Body: run_id, staff_id, reason (≥10 chars)
     * Refuses if the run is Finalized — admin must call unlock_payroll first.
     */
    public function regenerate_staff_payroll()
    {
        $this->_require_role(self::ADMIN_ROLES, 'regenerate_staff_payroll', 'HR', 'manage');

        $runId   = $this->safe_path_segment(trim($this->input->post('run_id') ?? ''), 'run_id');
        $staffId = $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id');
        $reason  = trim((string) $this->input->post('reason'));

        if ($runId === '')         $this->json_error('run_id is required.');
        if ($staffId === '')       $this->json_error('staff_id is required.');
        if (strlen($reason) < 10)  $this->json_error('Reason ≥10 chars required.');

        // ── Validate run state ──────────────────────────────────────
        $run = $this->_fsGetRun($runId);
        if (!is_array($run)) $this->json_error('Payroll run not found.');
        $runStatus = $run['status'] ?? '';
        if ($runStatus === 'Finalized') {
            $this->json_error('Run is Finalized. Call /hr/unlock_payroll first.');
        }
        if ($runStatus !== 'Draft') {
            $this->json_error("Run status '{$runStatus}' does not support regeneration. Must be Draft.");
        }

        $month = $run['month'] ?? '';
        $year  = $run['year']  ?? '';
        $validMonths = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];
        $monthNum = array_search($month, $validMonths);
        if ($monthNum === false) $this->json_error('Run has invalid month.');
        $monthNum = $monthNum + 1;

        // ── Load existing slip (we'll archive it) ──────────────────
        $slipDocId = $this->fs->docId("SLIP_{$runId}_{$staffId}");
        $oldSlip   = $this->fs->get('salarySlips', $slipDocId);
        if (!is_array($oldSlip)) {
            $this->json_error('Slip not found in this run for that staff member.');
        }

        // H1 — deterministic per-regeneration counter. Drives the adjustment
        // journal's idempotency key on the engine path so distinct
        // regenerations post distinct journals while a retry of the same one
        // dedups to exactly one. Read from the loaded slip — no extra read.
        $regenCount = (int) ($oldSlip['regen_count'] ?? 0) + 1;

        // ── M-3: Atomic Processing flag (prevent concurrent regenerate) ─
        // Same pattern as decide_leave: flip to Processing, re-read to
        // confirm we won the race. Final new-slip write naturally clears
        // the flag because $oldSlip (in memory, status='Draft') is the
        // base of the merged write.
        $preStatus = (string) ($oldSlip['status'] ?? 'Draft');
        if ($preStatus === 'Processing') {
            $startedAt = (string) ($oldSlip['processingStartedAt'] ?? '');
            // Stale-flag escape: if Processing was set >5 min ago, assume
            // the prior request crashed and let this one proceed.
            $isStale = $startedAt !== '' && (strtotime($startedAt) + 300) < time();
            if (!$isStale) {
                $this->json_error('Slip is being regenerated by another admin. Try again in a moment.', 409);
            }

            // DEADLOCK FIX (2026-08-19). Having taken the stale escape, this
            // request must NOT carry 'Processing' forward as the resting
            // status. $preStatus is written back verbatim at the end of this
            // method, alongside `processingStartedAt => ''` — so a slip that
            // recovered from one crashed regeneration would come to rest at
            // status='Processing' with an EMPTY timestamp, and the stale escape
            // above ($startedAt !== '') could never be satisfied again. The
            // slip would be permanently unregenerable, with no way back through
            // the UI.
            //
            // 'Draft' is the correct resting status, not a guess: regeneration
            // already refuses unless the RUN is Draft, so every slip reaching
            // this point belongs to a Draft run. 'Processing' is a transient
            // lock state and is never a resting status.
            $preStatus = 'Draft';
        }
        $procOk = $this->fs->set('salarySlips', $slipDocId, [
            'status'              => 'Processing',
            'processingStartedAt' => date('c'),
            'processingBy'        => $this->admin_name ?: $this->admin_id,
        ], true);
        if (!$procOk) {
            $this->json_error('Failed to acquire processing lock.', 500);
        }
        $recheck = $this->fs->get('salarySlips', $slipDocId);
        if (!is_array($recheck) || ($recheck['status'] ?? '') !== 'Processing'
            || ($recheck['processingBy'] ?? '') !== ($this->admin_name ?: $this->admin_id)) {
            $this->json_error('Lost the race — another admin is regenerating this slip.', 409);
        }

        // ── Load profile + structure ───────────────────────────────
        $profile = null;
        try {
            $fsDocs = $this->fs->schoolWhere('staff', [['staffId', '==', $staffId]]);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                $profile = is_array($fsDocs[0]['data'] ?? null) ? $fsDocs[0]['data'] : $fsDocs[0];
            }
        } catch (\Exception $e) {}
        if (!is_array($profile)) $this->json_error('Staff profile not found.');
        // Audit C1: merge server-only salaryDetails for the auto-structure fallback.
        $profile = $this->_merge_staff_private($profile, $staffId);

        // ── M-2: Inactive-staff warning (don't block regenerate) ───
        $regenWarnings = [];
        $rawStaffStatus = (string) ($profile['status'] ?? $profile['Status'] ?? 'Active');
        if (strtolower(trim($rawStaffStatus)) !== 'active') {
            $regenWarnings[] = "Staff status is '{$rawStaffStatus}' — regenerating slip for an inactive staff member.";
        }

        // ── D-006 (2026-08-22) — REGENERATION RESOLVES SALARY THE SAME WAY
        //    GENERATION DOES.
        //
        // This read used to be a raw `SAL_{staffId}` fetch, bypassing
        // Compensation_reader entirely, while generate_payroll() resolves
        // through the reader as of the LAST DAY OF THE PAY PERIOD. Today both
        // land on the same legacy document because USE_COMPONENT_COMPENSATION
        // is false, so the two agree and nothing is wrong.
        //
        // They stop agreeing the moment that flag flips: generation would
        // resolve the effective-dated assignment for the period while
        // regeneration kept reading the live legacy structure — so regenerating
        // a closed month would reprice it at whatever salary the employee has
        // TODAY. Generation's own comment already names this as the thing to
        // avoid ("regenerating a finalized run must not silently reprice it");
        // it simply was not enforced on this path.
        //
        // The period-end date is computed identically to generate_payroll(), so
        // both engines ask the one reader the same question. Legacy mode is
        // byte-identical to the previous behaviour.
        $periodEndDate = date(
            'Y-m-t',
            mktime(0, 0, 0, (int) $monthNum, 1, (int) $year)
        );
        $structureDoc = $this->_compensation_reader()->resolve($staffId, $periodEndDate);
        $structure = is_array($structureDoc) ? $structureDoc : null;
        if (!is_array($structure)) {
            // E-1. The auto-create fallback belongs to the LEGACY store and only
            // makes sense there. It derives a structure from salaryDefaults and
            // writes it to `SAL_{staffId}` via _fsSyncSalary().
            //
            // Once the reader is resolving compensation the post-cutover way,
            // that legacy document is no longer the authority and is never read
            // back. Auto-creating then would do two wrong things at once: pay
            // the employee from DEFAULT percentages rather than from whatever
            // the reader would have resolved for them, and write into a store
            // nothing consults — silently, on every regeneration.
            //
            // Nothing behind the seam is named here, deliberately: the reader is
            // asked which mode it is in and nothing about what that mode reads.
            //
            // An unresolvable employee after cutover is a data gap, not a thing
            // to paper over with defaults. Refuse, and say what is missing.
            if ($this->_compensation_reader()->mode() === Compensation_reader::MODE_COMPONENT) {
                $this->json_error(
                    'Cannot recompute — no effective salary assignment for this staff member '
                    . 'as of ' . $periodEndDate . '. Assign a salary structure before regenerating.'
                );
            }
            $autoStruct = $this->_auto_create_from_profile($staffId, $profile);
            if ($autoStruct === null) $this->json_error('Cannot recompute — no salary data for staff.');
            $structure = $autoStruct;
        }
        $sal = $this->_validate_structure($structure);
        if ($sal === null) $this->json_error('Salary structure is corrupt.');

        // S-1: same resolution the generating run used, keyed on the same
        // period-end date — so a regeneration cannot silently price the month
        // against a statutory configuration created after the fact.
        $statutoryCfg = $this->_statutory_config_for($periodEndDate);

        // ── Holidays for the month — canonical Holiday_service (HC-2) ─
        // Same source as generate_payroll: calendarEvents via Holiday_service.
        // Orphan stores schools.holidays / attendanceConfig.holidays no longer read.
        $holidays = [];
        try {
            $this->load->library('holiday_service');
            $this->holiday_service->init($this->fs, (string) $this->fs->schoolId(), (string) $this->session_year);
            $holidays = $this->holiday_service->holidays_in_month((int) $year, (int) $monthNum);
        } catch (\Throwable $e) {
            log_message('error', 'Hr::regenerate_staff_payroll HC-2 holiday load failed: ' . $e->getMessage());
        }

        // ── Per-staff working days (includes join/exit pro-ration) ─
        $jd = $profile['joinDate']  ?? $profile['Date Of Joining'] ?? $profile['Joining Date'] ?? null;
        $xd = $profile['exitDate']  ?? $profile['Resignation Date'] ?? $profile['leaveDate'] ?? null;
        $department = (string) ($profile['Department'] ?? $profile['department'] ?? '');

        // Trail 2 Phase J (2026-08-18) — same fix as generate_payroll():
        // resolve the department effective for THIS period, not the live
        // value. Regeneration is exactly the scenario the confirmed bug
        // described (edit today, then regenerate a past period).
        try {
            $this->load->library('staff_employment_history_service');
            $this->staff_employment_history_service->init($this->fs, $this->school_id, (string) ($this->admin_id ?? ''));
            $periodStart = sprintf('%04d-%02d-01', (int) $year, $monthNum);
            $histDept = $this->staff_employment_history_service->resolveAttributeAsOf($staffId, 'department', $periodStart);
            if ($histDept !== null && $histDept !== '') $department = $histDept;
        } catch (\Throwable $e) {
            log_message('error', "regenerate_staff_payroll: department history resolve failed for {$staffId}: " . $e->getMessage());
        }

        $swd = $this->_staff_working_days($monthNum, (int) $year, $holidays, $jd, $xd, $staffId, $department);

        // N-32/N-33 — same schedule gate as generate_payroll(). Regeneration is
        // a single-employee action, so the block is a hard error here rather
        // than a skip-and-continue: there is no one else to carry on for.
        if (!empty($swd['calBlocked'])) {
            $this->json_error($swd['calBlockWhy'], 409);
        }

        $staffWorkingDays = $swd['working'];
        if ($staffWorkingDays <= 0) {
            $this->json_error('Staff has zero working days this month (joined after or left before the month).');
        }
        // CALENDAR HALF-DAY — must mirror generate_payroll() exactly;
        // regenerate is contractually required to reproduce identical numbers.
        $staffDayReason = $swd['dayReason'] ?? [];
        $halfDayWeight  = $this->_half_day_weight((int) $year, $monthNum);

        // ── Approved leaves overlapping the month, scoped to this staff ─
        $monthStart = sprintf('%04d-%02d-01', $year, $monthNum);
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        $monthKey   = sprintf('%04d-%02d', (int) $year, $monthNum);

        $allLeaveTypes   = $this->_fsGetLeaveType('');
        $leaveTypeConfig = is_array($allLeaveTypes) ? $allLeaveTypes : [];
        $allLeaveReqs    = $this->_fsGetAllLeaveRequests();

        $lwpDays      = 0.0;            // total LWP days for this staff (float — supports half-day)
        $lwpDaysSet   = [];             // dayNum => true | 0.5
        $paidDaysSet  = [];             // dayNum => 0.5, covered by APPROVED PAID leave (Half-Day programme)
        $leaveDetail  = [];

        if (!empty($allLeaveReqs)) {
            foreach ($allLeaveReqs as $rid => $lr) {
                // Filter to this one staff — generate_payroll runs across all
                // staff but regenerate is single-staff scoped.
                if ((string) ($lr['staff_id'] ?? '') !== $staffId) continue;

                // Single source of truth — same helper as generate_payroll.
                // CR-1, CR-3, CR-4 are now applied identically here.
                $res = $this->_process_leave_for_payroll($lr, $leaveTypeConfig, $profile, $monthStart, $monthEnd, (string) $rid);
                if ($res === null) continue;

                $leaveDetail[] = $res['leaveDetail'];
                if ($res['lwpDays'] > 0) {
                    $lwpDays = (float) $lwpDays + $res['lwpDays'];
                    foreach ($res['lwpDaySet'] as $dn => $portion) {
                        // Existing full-day mark wins over a later half-day mark.
                        if (isset($lwpDaysSet[$dn]) && $lwpDaysSet[$dn] === true) continue;
                        $lwpDaysSet[$dn] = $portion;
                    }
                }
                // Half-Day programme — must mirror generate_payroll() exactly;
                // regenerate is contractually required to produce identical
                // numbers for the same inputs.
                if (!empty($res['paidDaySet'])) {
                    foreach ($res['paidDaySet'] as $dn => $portion) {
                        $paidDaysSet[$dn] = $portion;
                    }
                }
            }
        }

        // ── Attendance for the month ────────────────────────────────
        $attStr = '';
        try {
            $sumDocs = $this->fs->schoolWhere('staffAttendanceSummary', [
                ['month',   '==', $monthKey],
                ['staffId', '==', $staffId],
            ]);
            if (!empty($sumDocs)) {
                $d = is_array($sumDocs[0]['data'] ?? null) ? $sumDocs[0]['data'] : $sumDocs[0];
                $attStr = (string) ($d['dayWise'] ?? '');
            }
        } catch (\Exception $e) {}

        // ── Walk attendance, applying same rules as generate_payroll ─
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $monthNum, 1, (int) $year));
        $attConfig = [];
        try {
            $sd = $this->fs->get('schools', $this->fs->schoolId());
            if (is_array($sd) && is_array($sd['attendanceRules'] ?? null)) {
                $attConfig = $sd['attendanceRules'];
            }
        } catch (\Exception $e) {}
        $vacantTreatment = is_array($attConfig) ? ($attConfig['vacant_treatment'] ?? 'block') : 'block';

        $daysAbsent      = 0.0;
        $paidLeaveDays   = 0;
        $unpaidLeaveDays = 0;
        $vacantDays      = 0;
        $halfDays        = 0;     // P0-3/A1 — attendance 'M' count
        $extraWorkedDays = 0.0;   // P0-4/A2 — attendance 'W' count

        if ($attStr !== '') {
            $len = strlen($attStr);
            for ($i = 0; $i < $len; $i++) {
                $dayNum = $i + 1;
                if (!isset($swd['inWindow'][$dayNum])) continue;   // outside employment window — ignore
                // N-23 — identical gate to generate_payroll(), including the
                // 'W' exemption. One rule, both writers; see that call site for
                // the full rationale.
                if (strtoupper($attStr[$i]) !== 'W'
                    && !isset($swd['isWorking'][$dayNum])) continue;
                $ch = strtoupper($attStr[$i]);

                // CALENDAR HALF-DAY — expected work for THIS day. Mirrors
                // generate_payroll() exactly.
                $dayExpected = (($staffDayReason[$dayNum] ?? '') === \Working_calendar_service::REASON_HALF_DAY)
                    ? $halfDayWeight : 1.0;

                // Approved-leave coverage from the SAME shared rule
                // generate_payroll() uses — regeneration is contractually
                // required to produce identical numbers for identical inputs,
                // so this must never carry its own copy of the logic.
                $dayCoverage = self::_leave_coverage_for_day(
                    $dayExpected,
                    $lwpDaysSet[$dayNum]  ?? null,
                    $paidDaysSet[$dayNum] ?? null
                );

                if ($ch === 'A') {
                    $daysAbsent += max(0.0, $dayExpected - $dayCoverage);
                } elseif ($ch === 'L') {
                    // S2 — mirrors generate_payroll() exactly; see that branch for
                    // the rationale. Regeneration is contractually required to
                    // produce identical numbers for identical inputs, so this
                    // weighting must never diverge from the generation engine.
                    $step = ($dayExpected == 1.0) ? 1 : $dayExpected;
                    if (isset($lwpDaysSet[$dayNum]) && $lwpDaysSet[$dayNum] === true) {
                        $unpaidLeaveDays += $step;
                    } else {
                        $paidLeaveDays += $step;
                    }
                } elseif ($ch === 'M') {
                    // P0-3/A1 — attendance half-day. MUST mirror generate_payroll()'s
                    // branch exactly; regenerate is contractually required to produce
                    // identical numbers to generate for the same inputs.
                    // Half-Day programme (2026-08-19): guard also honours
                    // APPROVED PAID leave coverage — mirrors generate_payroll().
                    // S1: 'M' covers half of whatever was expected that day.
                    // Coverage subtracted from the uncovered half — mirrors
                    // generate_payroll().
                    $halfDays++;
                    $daysAbsent += max(0.0, ($dayExpected * 0.5) - $dayCoverage);
                } elseif ($ch === 'W') {
                    // P0-4/A2 — worked on a scheduled off-day. Mirrors generate_payroll().
                    $extraWorkedDays += 1.0;
                } elseif ($ch === 'V') {
                    // 'V' is the UNMARKED fill and therefore the branch an
                    // approved half-day leave actually lands in — it must honour
                    // coverage. Mirrors generate_payroll().
                    $vacantDays++;
                    if ($vacantTreatment === 'absent') {
                        $daysAbsent += max(0.0, $dayExpected - $dayCoverage);
                    }
                }
                // 'P'/'H'/'T'/'O' → no pay effect (see generate_payroll() for why).

                // Approved paid leave that left no 'L' mark — mirrors
                // generate_payroll(); see that call site for the rationale.
                // Regeneration must report the same paid-leave figure as
                // generation for identical inputs.
                if ($ch !== 'L' && isset($paidDaysSet[$dayNum])) {
                    $paidLeaveDays += (float) $paidDaysSet[$dayNum];
                }
            }
        } else {
            // No attendance doc — treat all in-window days as vacant
            $vacantDays = $staffWorkingDays;
            if ($vacantTreatment === 'absent') $daysAbsent = $staffWorkingDays;
        }

        if ($vacantDays > 0 && $vacantTreatment === 'block') {
            $this->json_error("Cannot regenerate — {$vacantDays} day(s) unmarked. Backfill attendance or change vacant_treatment policy.");
        }

        // ── Pay computation — single shared calculator (C1) ─────────
        // Same _compute_payslip() as generate_payroll(): identical earned-base
        // statutory/employer math + voluntary waterfall. One source of truth,
        // so generate and regenerate can never produce different numbers.
        if ($daysAbsent > $staffWorkingDays) $daysAbsent = $staffWorkingDays;

        // P0-4/A2 — same per-school extra-worked rate as generate_payroll().
        $extraWorkedRate = is_array($attConfig)
            ? (float) ($attConfig['extra_worked_rate'] ?? 1.0)
            : 1.0;
        if ($extraWorkedRate < 0) $extraWorkedRate = 1.0;

        // W3.2.2 — payroll-period overrides for THIS employee in THIS run are
        // handed to the engine through the `$extraComponents` parameter W2.7
        // already added. No override arithmetic exists anywhere: earnings join
        // gross before the statutory block, deductions join the pool, all in
        // integer paise. That is what keeps `gross == net + deductions` true and
        // keeps this the only payroll engine.
        $staffOverrides = $this->_payroll_overrides()->forStaff($runId, $staffId);
        $extraComponents = Payroll_override_service::toExtraComponents($staffOverrides);

        // ORIGINAL → ADJUSTMENT → FINAL, all engine-derived.
        //
        // The baseline is computed by running the SAME engine with no
        // adjustments, not by subtracting the adjustments from the final
        // figures. Subtraction would be a second, independent arithmetic path
        // for money — and it would be wrong wherever an adjustment moves a
        // statutory base, because an added earning also changes ESI and TDS.
        // `_compute_payslip()` is pure computation with no Firestore access, so
        // the extra call costs nothing but keeps the engine the only source of
        // these numbers. Skipped entirely when there is nothing to adjust.
        $baselineCalc = $extraComponents
            ? $this->_compute_payslip($sal, (float) $staffWorkingDays, (float) $daysAbsent, (float) $lwpDays, (float) $extraWorkedDays, $extraWorkedRate, $statutoryCfg)
            : null;

        // CA-6: same profile, same period date, so a regeneration cannot reach a
        // different coverage conclusion than the run that produced the slip.
        $staffStatProfile = $this->_staff_statutory_facts($profile, $statutoryCfg);

        $calc = $this->_compute_payslip($sal, (float) $staffWorkingDays, (float) $daysAbsent, (float) $lwpDays, (float) $extraWorkedDays, $extraWorkedRate, $statutoryCfg,
            $extraComponents, $staffStatProfile, $periodEndDate);

        $daysWorked     = $calc['days_worked'];
        $deductionDays  = $calc['deduction_days'];
        $absentFraction = $calc['absent_fraction'];
        $dailySalary    = $calc['daily_salary'];
        $lwpDeduction   = $calc['lwp_deduction'];
        $effectiveBasic = $calc['basic'];
        $hra            = $calc['hra'];
        $da             = $calc['da'];
        $ta             = $calc['ta'];
        $medical        = $calc['medical'];
        $otherAll       = $calc['other_allowances'];
        $gross          = $calc['gross'];
        $pfEmployee     = $calc['pf_employee'];
        $esiEmployee    = $calc['esi_employee'];
        // EMPLOYER-SIDE STATUTORY COST. The engine recomputes these on every
        // regeneration (they derive from effectiveBasic and gross, both of which
        // move with attendance), but this path never read them and never wrote
        // them — so the array_merge over the old slip left employer PF and ESI
        // frozen at the PRE-correction figures while every employee-side number
        // was recomputed. That understates or overstates employer liability and
        // the statutory remittance derived from it.
        $pfEmployer     = $calc['pf_employer'];
        $esiEmployer    = $calc['esi_employer'];
        $tds            = $calc['tds'];
        $profTax        = $calc['professional_tax'];
        $otherDed       = $calc['other_deductions'];
        $totalDed       = $calc['total_deductions'];
        $netPay         = $calc['net_pay'];
        foreach ($calc['warnings'] as $w) { $regenWarnings[] = $w; }

        $oldNetPay = (float) ($oldSlip['net_pay'] ?? 0);

        // ── Archive old slip ─────────────────────────────────────────
        $archiveDocId = $this->fs->docId("SLIP_ARCHIVE_{$runId}_{$staffId}_" . time());
        $this->fs->set('salarySlips', $archiveDocId, array_merge($oldSlip, [
            'type'              => 'slip_archive',
            'archivedFromSlipId'=> $slipDocId,
            'regeneratedAt'     => date('c'),
            'regeneratedBy'     => $this->admin_name ?: $this->admin_id,
            'regeneratedReason' => $reason,
        ]), false);

        // ── Replace slip ────────────────────────────────────────────
        // Explicit `status` + cleared processing fields ensure the
        // M-3 Processing flag is released by this write.
        $newSlip = array_merge(is_array($oldSlip) ? $oldSlip : [], [
            'status'              => $preStatus,
            'processingStartedAt' => '',
            'processingBy'        => '',
            'staff_id'         => $staffId,
            'staff_name'       => $profile['Name'] ?? $profile['name'] ?? $staffId,
            // Restated for the same reason as the statutory pair below: without
            // it the merge kept the department captured at the ORIGINAL run,
            // even though $department here is the period-effective value that
            // this recomputation actually used for the calendar and the GL
            // expense classification. A slip must not name one department while
            // its numbers came from another.
            'department'       => $department,
            'month'            => $month,
            'year'             => $year,
            'monthKey'         => $monthKey,
            'working_days'     => $staffWorkingDays,
            'days_worked'      => $daysWorked,
            'days_absent'      => $daysAbsent,
            'paid_leave_days'  => $paidLeaveDays,
            'leave_days'       => $paidLeaveDays + $unpaidLeaveDays,
            'lwp_days'         => $lwpDays,
            'lwp_deduction'    => $lwpDeduction,
            // The engine recomputes the daily rate every regeneration
            // ($calc['daily_salary'], above) but it was never restated here.
            // `$newSlip` merges over the OLD slip, so the stale rate survived:
            // after a regeneration that changed the expected-day count, the
            // payslip rendered basic ÷ the PREVIOUS working days.
            'daily_salary'     => $dailySalary,
            // P0-3/A1 + P0-4/A2 — mirrors generate_payroll()'s slip payload.
            'half_days'        => $halfDays,
            'extra_worked_days'=> $extraWorkedDays,
            'extra_worked_pay' => $calc['extra_worked_pay'],
            'basic'            => $effectiveBasic,
            'hra'              => $hra,
            'da'               => $da,
            'ta'               => $ta,
            'medical'          => $medical,
            'other_allowances' => $otherAll,
            'gross'            => $gross,
            'pf_employee'      => $pfEmployee,
            'esi_employee'     => $esiEmployee,
            'pf_employer'      => $pfEmployer,
            'esi_employer'     => $esiEmployer,
            'tds'              => $tds,
            'professional_tax' => $profTax,
            'other_deductions' => $otherDed,
            'total_deductions' => $totalDed,
            'net_pay'          => $netPay,
            'leave_details'    => $leaveDetail,
            'attendance_notes' => ($vacantDays > 0)
                ? "{$vacantDays} day(s) unmarked (treated as {$vacantTreatment})"
                : '',
            'staff_window'     => [
                'startDay' => $swd['startDay'],
                'endDay'   => $swd['endDay'],
                'joinDate' => $jd,
                'exitDate' => $xd,
            ],
            // ── W3.2.1 — both snapshots MUST be rewritten on regeneration ──
            //
            // `$newSlip` is an array_merge over the OLD slip, so any snapshot
            // key not restated here survives untouched. That was already true
            // of `_salary_snapshot` before this wave: a regenerated slip kept
            // the pre-regeneration structure while its money was recomputed
            // from the current one — a snapshot that describes inputs the
            // numbers next to it did not come from.
            //
            // Regeneration is precisely when the inputs have changed, so it is
            // the one path where a stale snapshot is guaranteed to be wrong.
            // Both are therefore rewritten from the values THIS recomputation
            // actually used.
            '_salary_snapshot' => [
                'basic' => (float) ($sal['basic'] ?? 0),
                'hra' => (float) ($sal['hra'] ?? 0), 'da' => (float) ($sal['da'] ?? 0),
                'ta' => (float) ($sal['ta'] ?? 0), 'medical' => (float) ($sal['medical'] ?? 0),
                'other_allowances' => (float) ($sal['other_allowances'] ?? 0),
                'pf_employee' => (float) ($sal['pf_employee'] ?? 0),
                'esi_employee' => (float) ($sal['esi_employee'] ?? 0),
                'professional_tax' => (float) ($sal['professional_tax'] ?? 0),
                'tds' => (float) ($sal['tds'] ?? 0),
            ],
            '_attendance_snapshot' => [
                'version'          => 1,
                'month_key'        => $monthKey,
                'day_wise'         => $attStr,
                'source'           => $attStr !== '' ? 'staffAttendanceSummary' : 'none',
                'window_mask'      => self::_att_window_mask(
                    is_array($swd['inWindow'] ?? null) ? $swd['inWindow'] : [], $daysInMonth),
                'lwp_mask'         => self::_att_lwp_mask(
                    is_array($lwpDaysSet ?? null) ? $lwpDaysSet : [], $daysInMonth),
                'vacant_treatment' => $vacantTreatment,
                'working_days'     => $staffWorkingDays,
                // Identical to generate_payroll(); see that snapshot for the
                // rationale. Restated because $newSlip merges over the OLD slip,
                // so an un-restated weight would describe the previous
                // computation while the money beside it is new.
                'calendar_half_day_weight' => $halfDayWeight,
                'days_in_month'    => $daysInMonth,
                'holiday_days'     => array_map('intval', array_keys($holidays)),
                // N-23/N-30 §6 — identical to generate_payroll(). Restated on
                // regeneration because `$newSlip` merges over the OLD slip: an
                // un-restated mask would survive describing the previous
                // calendar while the money beside it was recomputed.
                'working_mask'          => (string) ($swd['workingMask'] ?? ''),
                'policy_version'        => $swd['policyVersion'] ?? null,
                'policy_effective_from' => (string) ($swd['policyEffectiveFrom'] ?? ''),
                'policy_source'         => (string) ($swd['policySource'] ?? ''),
                'schedule_id'             => (string) ($swd['scheduleId'] ?? ''),
                'schedule_name'           => (string) ($swd['scheduleName'] ?? ''),
                'assignment_effective_from' => (string) ($swd['assignmentEffectiveFrom'] ?? ''),
                'derived'          => [
                    'days_absent'       => $daysAbsent,
                    'half_days'         => $halfDays,
                    'extra_worked_days' => $extraWorkedDays,
                    'paid_leave_days'   => $paidLeaveDays,
                    'unpaid_leave_days' => $unpaidLeaveDays,
                    'vacant_days'       => $vacantDays,
                ],
                // Distinguishes a regenerated snapshot from the original run's,
                // so an auditor can tell which recomputation produced it.
                'regenerated'      => true,
            ],
            // W3.2.4 — same verbatim engine passthrough as generate_payroll().
            // Restated here because `$newSlip` is an array_merge over the OLD
            // slip: a trace left un-restated would survive describing the
            // previous computation while the money beside it is new — the same
            // staleness class W3.2.1 found in `_salary_snapshot`.
            '_calculation_trace' => [
                'version'          => 1,
                'engine'           => '_compute_payslip',
                'statutory_source' => $calc['statutory_source'] ?? 'structure',
                'bases'            => $calc['bases']    ?? [],
                'paise'            => $calc['paise']    ?? [],
                'deferred'         => $calc['deferred'] ?? [],
                'warnings'         => $calc['warnings'] ?? [],
                'computed_at'      => date('c'),
                'regenerated'      => true,
            ],
            // ── W3.2.2 — payroll-period adjustments, audit-shaped ──
            //
            // Persists the three figures an auditor needs to reconstruct the
            // change WITHOUT recomputing anything: what the run would have paid
            // with no adjustments, what each adjustment was, and what was
            // actually paid. Every value comes from the engine.
            //
            // Absent (null) when there are no adjustments, so an unadjusted
            // slip is distinguishable from an adjusted one that nets to zero.
            '_payroll_adjustments' => $extraComponents ? [
                'version'          => 1,
                'count'            => count($extraComponents),
                'lines'            => array_map(function ($xc) {
                    return [
                        'override_id' => $xc['code'],
                        'type'        => $xc['type']  ?? '',
                        'label'       => $xc['label'] ?? '',
                        'kind'        => $xc['kind'],
                        'amount'      => self::_toRupees($xc['amountPaise']),
                        'prorated'    => !empty($xc['prorated']),
                    ];
                }, $extraComponents),
                // Engine-derived, never subtracted.
                'original_gross'   => $baselineCalc['gross']   ?? null,
                'original_net'     => $baselineCalc['net_pay'] ?? null,
                'final_gross'      => $calc['gross'],
                'final_net'        => $calc['net_pay'],
                'applied_at'       => date('c'),
                'applied_by'       => $this->admin_name ?: $this->admin_id,
            ] : null,
            'regeneratedAt'    => date('c'),
            'regeneratedBy'    => $this->admin_name ?: $this->admin_id,
            'regenerationReason'=> $reason,
            'previousNetPay'   => $oldNetPay,
            'updatedAt'        => date('c'),
        ]);
        // Persist the regen counter — it drives the adjustment journal's
        // deterministic idempotency id.
        $newSlip['regen_count'] = $regenCount;
        // Written through the SHARED builder, exactly as generate_payroll() is.
        // This path used to write $newSlip raw, which meant the canonical
        // camelCase mirror the Teacher app reads (workingDays / presentDays /
        // daysAbsent / lwpDays / paidLeaveDays) was never rewritten: it survived
        // from the ORIGINAL run via the array_merge over $oldSlip. Every
        // regeneration — the normal correction path, not just Calendar Half-Day
        // — therefore left the employee's app showing the pre-correction day
        // counts beside admin-side money that had been recomputed. This is the
        // "two builders eventually disagree" failure the builder's own docblock
        // warns about; there is now one builder again.
        $this->fs->set('salarySlips', $slipDocId, $this->_buildPayslipDoc($runId, $staffId, $newSlip), true);

        // ── CR-2: Adjustment journal entry to keep books in sync ────
        // Compute slip-level deltas and post a corrective JE so the
        // accounting books match the regenerated slip. Sign convention:
        //   • dGross > 0 (salary increased): DR expense, CR liabilities
        //   • dGross < 0 (salary decreased): CR expense, DR liabilities
        // C1: PF/ESI/TDS now ride the EARNED base, so changed attendance shifts
        // the deductions too. The delta JE stays balanced BY CONSTRUCTION because
        // gross === net + Σ employee deductions on both the old and new slip, so
        // dGross === dNet + Σ deduction deltas for every regeneration.
        $dGross    = round($gross        - (float) ($oldSlip['gross']            ?? 0), 2);
        $dNet      = round($netPay       - (float) ($oldSlip['net_pay']          ?? 0), 2);
        $dPF       = round($pfEmployee   - (float) ($oldSlip['pf_employee']      ?? 0), 2);
        $dESI      = round($esiEmployee  - (float) ($oldSlip['esi_employee']     ?? 0), 2);
        $dTDS      = round($tds          - (float) ($oldSlip['tds']              ?? 0), 2);
        $dProfTax  = round($profTax      - (float) ($oldSlip['professional_tax'] ?? 0), 2);
        $dOtherDed = round($otherDed     - (float) ($oldSlip['other_deductions'] ?? 0), 2);

        $adjJournalId = '';
        $hasDelta = (abs($dGross) >= 0.005)
                 || (abs($dNet)   >= 0.005)
                 || (abs($dPF)    >= 0.005)
                 || (abs($dESI)   >= 0.005)
                 || (abs($dTDS)   >= 0.005)
                 || (abs($dProfTax) >= 0.005)
                 || (abs($dOtherDed) >= 0.005);

        if ($hasDelta) {
            // Determine teaching vs non-teaching for the expense account
            $staffRoles = $profile['staff_roles'] ?? [];
            $isTeaching = false;
            if (!empty($staffRoles) && is_array($staffRoles)) {
                $roleDefs = [];
                try {
                    $sdRoles = $this->fs->get('schools', $this->fs->schoolId());
                    if (is_array($sdRoles) && is_array($sdRoles['staffRoles'] ?? null)) {
                        $roleDefs = $sdRoles['staffRoles'];
                    }
                } catch (\Exception $e) {}
                foreach ($staffRoles as $_rid) {
                    if (is_array($roleDefs[$_rid] ?? null) && (($roleDefs[$_rid]['category'] ?? '') === 'Teaching')) {
                        $isTeaching = true;
                        break;
                    }
                }
            } else {
                // Consolidated reconciliation audit (2026-08-19) — DEFECT FIXED.
                // This branch previously re-read $profile['Position'] and
                // $profile['Department'] RAW, silently bypassing the
                // period-effective $department this very function had already
                // resolved ~400 lines above (Trail 2 Phase J, line ~11340).
                // The result picked the expense GL account (5010 Teaching vs
                // 5020 Non-Teaching) for the adjustment journal, so
                // regenerating a past month for someone who had since
                // transferred department booked that month's delta to the
                // wrong expense account. $department is now the already-
                // resolved value; $position is resolved the same way, against
                // the same period start. The classification RULE and the
                // account codes are unchanged.
                $_dep = $department;   // already period-effective (Phase J, above)
                $_pos = (string) ($profile['Position'] ?? '');
                try {
                    $this->load->library('staff_employment_history_service');
                    $this->staff_employment_history_service->init($this->fs, $this->school_id, (string) ($this->admin_id ?? ''));
                    $histPos = $this->staff_employment_history_service->resolveAttributeAsOf(
                        $staffId, 'position', sprintf('%04d-%02d-01', (int) $year, $monthNum));
                    if ($histPos !== null && $histPos !== '') $_pos = $histPos;
                } catch (\Throwable $e) {
                    log_message('error', "regenerate_staff_payroll: position history resolve failed for {$staffId}: " . $e->getMessage());
                }
                $isTeaching = (
                    stripos($_pos, 'teacher')  !== false ||
                    stripos($_pos, 'lecturer') !== false ||
                    stripos($_dep, 'teaching') !== false
                );
            }
            $expenseAcct = $isTeaching ? '5010' : '5020';

            $adjLines = [];
            // Expense accounts: positive delta = DR (more expense), negative = CR (less)
            if (abs($dGross) >= 0.005) {
                $adjLines[] = [
                    'account_code' => $expenseAcct,
                    'dr' => $dGross > 0 ? abs($dGross) : 0,
                    'cr' => $dGross < 0 ? abs($dGross) : 0,
                ];
            }
            // Liability accounts: positive delta = CR (more liability), negative = DR (less)
            $liabDeltas = [
                '2020' => $dNet, '2030' => $dPF, '2031' => $dESI,
                '2032' => $dTDS, '2033' => $dProfTax, '2034' => $dOtherDed,
            ];
            foreach ($liabDeltas as $code => $delta) {
                if (abs($delta) < 0.005) continue;
                $adjLines[] = [
                    'account_code' => $code,
                    'dr' => $delta < 0 ? abs($delta) : 0,
                    'cr' => $delta > 0 ? abs($delta) : 0,
                ];
            }

            if (!empty($adjLines)) {
                $narration = "Payroll adjustment - {$month} {$year} - regenerated slip for {$staffId}"
                           . " (net Δ " . ($netPay - $oldNetPay >= 0 ? '+' : '') . round($netPay - $oldNetPay, 2) . ")";

                // Post the DELTA through the canonical engine. Non-fatal: a
                // closed period DEFERS the journal (warn) rather than aborting the
                // regenerate. The deterministic source_ref makes retries idempotent.
                if ($this->_period_is_open()) {
                    try {
                        require_once APPPATH . 'services/PayrollAccountingService.php';
                        $payAcct = new PayrollAccountingService();
                        $payAcct->init($this->firebase, $this->school_id, $this->session_year, $this->admin_id, $this);
                        $adjJournalId = (string) $payAcct->postRunAdjustment([
                            'run_id'     => $runId,
                            'source_ref' => "{$runId}_{$staffId}_ADJ{$regenCount}",
                            'lines'      => $adjLines,
                            'narration'  => $narration,
                        ]);
                        if ($adjJournalId === '') {
                            $regenWarnings[] = 'Adjustment journal not posted (engine declined) — verify accounting and re-post if needed.';
                        }
                    } catch (\Throwable $e) {
                        log_message('error', 'regenerate_staff_payroll: engine adjustment failed: ' . $e->getMessage());
                        $regenWarnings[] = 'Adjustment journal not posted (engine error) — verify accounting and re-post if needed.';
                    }
                } else {
                    $regenWarnings[] = 'Adjustment journal deferred — accounting period is closed. Reopen the period and re-post.';
                    log_message('error', "regenerate_staff_payroll: period closed — adjustment deferred run={$runId} staff={$staffId}");
                }
            }
        }

        // ── Recompute run totals from all slips ─────────────────────
        $allSlips = $this->_fsGetAllSlips($runId);
        $totals = [
            'gross' => 0.0, 'net' => 0.0, 'pf' => 0.0, 'esi' => 0.0,
            'tds' => 0.0, 'profTax' => 0.0, 'otherDed' => 0.0, 'count' => 0,
        ];
        foreach ($allSlips as $sid => $sl) {
            $totals['gross']    += (float) ($sl['gross'] ?? 0);
            $totals['net']      += (float) ($sl['net_pay'] ?? 0);
            $totals['pf']       += (float) ($sl['pf_employee'] ?? 0);
            $totals['esi']      += (float) ($sl['esi_employee'] ?? 0);
            $totals['tds']      += (float) ($sl['tds'] ?? 0);
            $totals['profTax']  += (float) ($sl['professional_tax'] ?? 0);
            $totals['otherDed'] += (float) ($sl['other_deductions'] ?? 0);
            $totals['count']++;
        }
        $totals['totalDeductions'] = round($totals['pf'] + $totals['esi'] + $totals['tds'] + $totals['profTax'] + $totals['otherDed'], 2);

        // CR-2: append adjustment journal id to run's history list
        $existingAdj = (is_array($run['adjustment_journal_ids'] ?? null)) ? $run['adjustment_journal_ids'] : [];
        if ($adjJournalId !== '') {
            $existingAdj[] = [
                'journalId'  => $adjJournalId,
                'staffId'    => $staffId,
                'reason'     => $reason,
                'oldNetPay'  => $oldNetPay,
                'newNetPay'  => $netPay,
                'netDelta'   => round($netPay - $oldNetPay, 2),
                'grossDelta' => $dGross,
                'createdAt'  => date('c'),
                'createdBy'  => $this->admin_name ?: $this->admin_id,
            ];
        }

        $this->_fsSyncPayrollRun($runId, array_merge($run, [
            'total_gross'        => round($totals['gross'], 2),
            'total_net'          => round($totals['net'], 2),
            'total_pf'           => round($totals['pf'], 2),
            'total_esi'          => round($totals['esi'], 2),
            'total_tds'          => round($totals['tds'], 2),
            'total_professional_tax' => round($totals['profTax'], 2),
            'total_other_deductions' => round($totals['otherDed'], 2),
            'total_deductions'   => $totals['totalDeductions'],
            'staff_count'        => $totals['count'],
            'last_regenerated_at'=> date('c'),
            'last_regenerated_by'=> $this->admin_name ?: $this->admin_id,
            'adjustment_journal_ids' => $existingAdj,
        ]));

        // ── Audit ───────────────────────────────────────────────────
        $this->_log_payroll('slip_regenerated', $runId, [
            'staff_id'              => $staffId,
            'reason'                => $reason,
            'old_net_pay'           => $oldNetPay,
            'new_net_pay'           => $netPay,
            'archive_id'            => $archiveDocId,
            'working_days'          => $staffWorkingDays,
            'days_absent'           => $daysAbsent,
            'lwp_days'              => $lwpDays,
            'adjustment_journal_id' => $adjJournalId,
            'gross_delta'           => $dGross,
            'net_delta'             => round($netPay - $oldNetPay, 2),
        ]);
        // Individual pay was recomputed. The before/after net IS the
        // auditable fact here, so it is recorded deliberately — but nothing
        // further: no salary component breakdown, no bank or PII values.
        $this->_audit_payroll('payroll_slip_regenerated', $runId,
            "Payslip regenerated for {$staffId}: {$reason}",
            $this->_audit_run_meta($run, [
                'staffId'             => $staffId,
                'reason'              => $reason,
                'oldNetPay'           => $oldNetPay,
                'newNetPay'           => $netPay,
                'netDelta'            => round($netPay - $oldNetPay, 2),
                'archiveId'           => $archiveDocId,
                'adjustmentJournalId' => $adjJournalId,
            ]));

        $this->json_success([
            'message'              => 'Slip regenerated.',
            'run_id'               => $runId,
            'staff_id'             => $staffId,
            'old_net_pay'          => $oldNetPay,
            'new_net_pay'          => $netPay,
            'difference'           => round($netPay - $oldNetPay, 2),
            'warnings'             => $regenWarnings,   // M-2: inactive-staff hints
            'archive_id'           => $archiveDocId,
            'working_days'         => $staffWorkingDays,
            'days_absent'          => $daysAbsent,
            'lwp_days'             => $lwpDays,
            'adjustment_journal_id'=> $adjJournalId,
        ]);
    }
}
