<?php
defined('BASEPATH') OR exit('No direct script access allowed');
// Phase 20.3 — Section_seat_guard is a STATIC utility, so CI's loader never brings
// it in (load->library() instantiates; nothing here holds an instance). Without this
// the class is undefined at every call site and placement fatals with
// "Class Section_seat_guard not found". require_once at file level is this repo's
// existing pattern for static libraries; idempotent, one include, no behaviour change
// beyond making the already-wired guard resolvable.
require_once APPPATH . 'libraries/Section_seat_guard.php';


use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Student Information System (SIS) Controller
 *
 * Consolidated module: merges Student.php + Admission_crm.php into SIS.
 * Single entry point for the entire student lifecycle:
 *   Inquiry → Application → Admission → Enrollment → Profile/Edit →
 *   Promotion → Transfer Certificate → Alumni
 *
 * Handles: SIS dashboard, student list, admission, profile management,
 *          batch promotion, transfer certificates, documents, history, ID cards.
 *
 * Firebase schema additions:
 *   Users/Parents/{school_id}/{userId}/History/{push_key}
 *       { action, description, changed_by, changed_at, metadata:{} }
 *   Users/Parents/{school_id}/{userId}/TC/
 *       { tc_no, issued_date, issued_by, reason, destination, status:active|cancelled }
 *   Schools/{school_name}/SIS/TC_Counter           → integer
 *   Schools/{school_name}/SIS/Promotions/{batch_id}/
 *       { session_from, session_to, promoted_at, promoted_by,
 *         from_class, to_class, students:[{userId, name}] }
 */
class Sis extends MY_Controller
{
    /** Roles for student information management */
    /** SEAT-RACE-1: bounded re-decide attempts for a lost placement race. */
    private const SEAT_RETRY_ATTEMPTS = 4;

    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Front Office'];

    /**
     * ADM-INPUT-1 — accepted age at admission, in whole years.
     *
     * Bounds, not policy. Class-appropriate age is a school decision (and varies by
     * board and state), so this refuses only what cannot be a real school admission:
     * a toddler below playgroup age, or an adult. It exists to catch a mistyped or
     * coerced year — 01-01-1970 was silently accepted before this.
     */
    private const ADMISSION_MIN_AGE = 2;
    private const ADMISSION_MAX_AGE = 30;

    /** Roles that may view student information */
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher', 'Front Office'];

    /** CRM base Firebase path */
    private $crm_base;

    /** Methods accessible without SIS RBAC permission (e.g., public-facing admission form) */
    private const PUBLIC_METHODS = ['online_form', 'submit_online_form'];

    /**
     * Phase 16.5 (ROLL-5) — how long a "Suggest Roll Number" hold is binding.
     *
     * 30 minutes: comfortably longer than the seconds between clicking Suggest
     * and saving the student (so a genuinely open form is never robbed), and
     * short enough that an abandoned suggestion returns the number to
     * circulation the same working session rather than never.
     *
     * This is a courtesy hold only — `_rollNoConflict()` remains the
     * uniqueness authority at save time, so no value of this constant can
     * cause a duplicate Roll Number.
     */
    private const ROLL_CLAIM_TTL_SECONDS = 1800;

    public function __construct()
    {
        parent::__construct();
        // FIXED: online_form/submit_online_form must be accessible to any logged-in user,
        // not just those with SIS module permission (e.g., parents filling admission form)
        $method = $this->router->fetch_method();
        if (!in_array($method, self::PUBLIC_METHODS, true)) {
            require_permission('SIS');
        }
        $this->crm_base = "Schools/{$this->school_name}/CRM/Admissions"; // Legacy path (being retired)
        $this->load->helper('notification');

        // Fee lifecycle & defaulter check libraries
        $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
        $this->load->library('Fee_defaulter_check', null, 'feeDefaulter');
        $this->feeLifecycle->init($this->firebase, $this->school_name, $this->session_year, $this->admin_id ?? 'system');
        $this->feeDefaulter->init($this->firebase, $this->school_name, $this->session_year);

        // Entity sync for Firestore dual-writes (Android app data)
        $this->load->library('entity_firestore_sync', null, 'entity_sync');
        $this->entity_sync->init($this->firebase, $this->school_name, $this->session_year, $this->school_code);
    }

    /**
     * Build class → sections map from Firestore sections collection.
     * Returns ['9th' => ['A','B'], 'Nursery' => ['A'], ...]
     */
    private function _fs_class_map(): array
    {
        $sectionDocs = $this->fs->schoolWhere('sections', []);
        $classMap = [];
        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'];
            $className = $sd['className'] ?? '';
            $sectionName = $sd['section'] ?? '';
            if (!$className || !$sectionName) continue;
            $ordinal = str_replace('Class ', '', $className);
            $sectionLetter = str_replace('Section ', '', $sectionName);
            if (!isset($classMap[$ordinal])) $classMap[$ordinal] = [];
            if (!in_array($sectionLetter, $classMap[$ordinal])) {
                $classMap[$ordinal][] = $sectionLetter;
            }
        }
        return $classMap;
    }

    /**
     * Session-scoped class→section map: { session: { classOrd: [sections] } }.
     * Unlike _fs_class_map (which unions sections across ALL sessions), this
     * preserves the session dimension so the promote dialog can show the
     * SOURCE class's sections in the current session and the DESTINATION
     * class's sections in the selected target session — instead of a
     * session-agnostic union that offered sections not present in the target
     * session.
     */
    private function _fs_session_class_map(): array
    {
        $sectionDocs = $this->fs->schoolWhere('sections', []);
        $map = [];
        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'] ?? $doc;
            $session     = (string) ($sd['session']   ?? '');
            $className   = (string) ($sd['className'] ?? '');
            $sectionName = (string) ($sd['section']   ?? '');
            if ($session === '' || $className === '' || $sectionName === '') continue;
            $ordinal       = str_replace('Class ', '', $className);
            $sectionLetter = str_replace('Section ', '', $sectionName);
            if (!isset($map[$session]))            $map[$session] = [];
            if (!isset($map[$session][$ordinal]))  $map[$session][$ordinal] = [];
            if (!in_array($sectionLetter, $map[$session][$ordinal], true)) {
                $map[$session][$ordinal][] = $sectionLetter;
            }
        }
        return $map;
    }

    /* ══════════════════════════════════════════════════════════════════════
       DASHBOARD
    ══════════════════════════════════════════════════════════════════════ */

    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_view', 'SIS', 'view');
        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        // Read all students from the cached snapshot (one Firestore read per
        // 90s, busted on write) — same smoothing as the student-list page.
        $studentList = $this->_cached_student_docs();
        $index = [];
        foreach ($studentList as $s) {
            $d = $s['data'] ?? $s;
            $uid = $d['studentId'] ?? $d['User Id'] ?? $d['userId'] ?? '';
            if ($uid === '') continue;
            $index[$uid] = [
                'name'    => $d['name'] ?? $d['Name'] ?? '',
                'class'   => $d['className'] ?? $d['Class'] ?? '',
                'section' => $d['section'] ?? $d['Section'] ?? '',
                'status'  => $d['status'] ?? $d['Status'] ?? 'Active',
                'gender'  => $d['gender'] ?? $d['Gender'] ?? '',
            ];
        }

        // Enrolled in current session (OPT 3: single bulk read)
        $enrolledIds = $this->_get_enrolled_ids();

        $totalStudents = count($index);
        $tcCount       = 0;
        $classCounts   = [];

        foreach ($index as $uid => $entry) {
            if (!is_array($entry)) continue;
            $status = $entry['status'] ?? 'Active';

            // TC count
            if ($status === 'TC') $tcCount++;

            // Class-wise enrolled count
            if (isset($enrolledIds[$uid])) {
                $cls = trim($entry['class'] ?? 'Unknown');
                $classCounts[$cls] = ($classCounts[$cls] ?? 0) + 1;
            }
        }
        ksort($classCounts);

        $enrolledCount = 0;
        foreach ($enrolledIds as $uid => $_) {
            if (isset($index[$uid])) $enrolledCount++;
        }

        // Recent promotions from school doc
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $promotions = $schoolDoc['promotions'] ?? [];
        if (!is_array($promotions)) $promotions = [];
        arsort($promotions);
        $recentPromotions = array_slice($promotions, 0, 5, true);

        $data['total_students']    = $totalStudents;
        $data['enrolled_count']    = $enrolledCount;
        $data['tc_count']          = $tcCount;
        $data['class_counts']      = $classCounts;
        $data['recent_promotions'] = $recentPromotions;
        $data['session_year']      = $session;

        $this->load->view('include/header');
        $this->load->view('sis/index', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT LIST
    ══════════════════════════════════════════════════════════════════════ */

    public function students()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_students', 'SIS', 'view');
        $session = $this->session_year;

        $data['class_map']    = $this->_fs_class_map();
        $data['session_year'] = $session;

        $this->load->view('include/header');
        $this->load->view('sis/students', $data);
        $this->load->view('include/footer');
    }

    /**
     * RBAC gate override — fixes the Layer-2 role-name gate (F3).
     *
     * The base MY_Controller::_require_role() hard-matches the ROLE NAME
     * against the allow-list. That silently locks out custom tenant roles
     * which legitimately hold the SIS module permission, and makes the
     * per-action labels ('crm_view'/'crm_approve'/…) purely decorative.
     * Mirroring the Attendance controller, defer to the real RBAC
     * permission: any role granted the SIS module (already required in the
     * constructor for every non-public method) passes. This also resolves the
     * teacher-PII over-exposure (F8) — access is now governed by the SIS grant
     * rather than a hardcoded name list, so a Teacher without SIS can't reach
     * the CRM at all. Pass $strict = true to force the hard name floor where a
     * specific role is genuinely required.
     */
    protected function _require_role(array $allowed, string $action = '', ?string $module = null, string $level = 'view'): void
    {
        // Signature matches the base MY_Controller::_require_role (Unified-RBAC
        // capability bridge: ?string $module + string $level). Graded path: a
        // role holding the SIS module AT THE REQUESTED LEVEL ($level:
        // view|edit|manage) passes without appearing in the hardcoded name
        // list. Pass the sentinel module 'strict' (was the boolean `true`) to
        // force the hard name floor. Callers pass module 'SIS' + the site's
        // real level; a bare/absent module keeps the legacy view-floor default.
        $strict = ($module === 'strict');
        if (!$strict && has_permission('SIS', $level)) {
            return;
        }
        // Non-strict sites pass the real module + level so the base enforces
        // graded authority (a holder at a LOWER level is denied, not re-granted by
        // the legacy name list). Strict sites keep the hard name floor.
        if ($strict) {
            parent::_require_role($allowed, $action);
        } else {
            parent::_require_role($allowed, $action, 'SIS', $level);
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       ADMISSION
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * G10 (2026-08-08) — active bank ledgers for the admission-fee collection
     * selector. Same predicate collect_admission_fee validates against
     * (is_bank && !is_group && status==='active'), so the dropdown can never
     * offer a code the server-side check would then reject.
     *
     * @return array<int, array{code:string,name:string}>
     */
    private function _active_bank_ledgers(): array
    {
        $out = [];
        try {
            foreach ((array) $this->firebase->firestoreQuery('chartOfAccounts', [['schoolId', '==', $this->school_name]]) as $row) {
                $cd = $row['data'] ?? $row;
                if (!is_array($cd)) continue;
                if (empty($cd['is_bank']) || !empty($cd['is_group'])) continue;
                if (($cd['status'] ?? '') !== 'active') continue;
                $code = (string) ($cd['code'] ?? '');
                if ($code === '') continue;
                $out[] = ['code' => $code, 'name' => (string) ($cd['name'] ?? $code)];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Sis::_active_bank_ledgers failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * G10 (2026-08-08) — POST: resolve the admission fee for a class so the
     * direct-admission screen can show the operator what will be charged
     * BEFORE they submit. Read-only; the authoritative resolution still happens
     * server-side in _admission_fee_precheck() at save time — this endpoint is
     * display-only and its response is never trusted as an amount.
     */
    public function resolve_admission_fee()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_resolve_admission_fee', 'SIS', 'manage');

        $cls = Firestore_service::classKey(trim((string) $this->input->post('class')));
        if ($cls === '') {
            return $this->json_success(['enabled' => false, 'amount' => 0, 'label' => '']);
        }
        $this->safe_path_segment($cls, 'class');

        $this->load->helper('admission_crm');
        $schoolDoc = $this->firebase->firestoreGet('schools', $this->school_name) ?? [];
        $feeInfo   = admission_resolve_fee(
            $this->fs, is_array($schoolDoc) ? $schoolDoc : [], $this->session_year, $cls
        );
        $amount = round((float) ($feeInfo['amount'] ?? 0), 2);

        return $this->json_success([
            'enabled' => (!empty($feeInfo['enabled']) && $amount > 0),
            'amount'  => $amount,
            'label'   => (string) ($feeInfo['label'] ?? 'Admission Fee'),
        ]);
    }

    /**
     * G10 (2026-08-08) — admission-fee PRE-CHECK for the direct admission
     * screen (pathway 3).
     *
     * Background: the ERP has three admission pathways — (1) the public online
     * form, (2) the CRM application converted at the counter
     * (Sis::collect_admission_fee), and (3) THIS screen, direct entry with no
     * lead. Pathways 1 and 2 both charge the admission fee through
     * admission_resolve_fee() + Admission_collection::record_payment(); pathway
     * 3 had no payment logic whatsoever, so every student admitted directly was
     * silently never charged. Operator decision (recorded in
     * STUDENT_ENTITLEMENTS_ARCHITECTURE_BASELINE.md): charge it by default,
     * through the SAME shared path — no second collection engine — plus a
     * deliberate, separately-permissioned, audited waive override.
     *
     * Runs BEFORE the student doc is created so a mis-configured or blocked
     * collection fails the request cleanly instead of leaving an admitted
     * student with an uncollected fee. Every rejection path calls json_error(),
     * which exits.
     *
     * Waive authority is the graded `Fees:manage` capability — deliberately NOT
     * a side effect of the `SIS:manage` grant this screen already requires, so
     * an admissions clerk who can admit cannot also waive. (Reference: the
     * default role catalogue in Staff.php gives ROLE_FRONT_OFFICE `SIS` without
     * `Fees`.) Granting waive authority to a role that holds SIS but not Fees —
     * Principal, in the default catalogue — is a catalogue/per-person `extra`
     * decision, not a code change.
     *
     * @return array{due:bool,waived:bool,amount:float,fee_head:string,fee_label:string,
     *               mode:string,mode_label:string,bank_code:string,bank_reference:string,
     *               waive_reason:string}
     */
    private function _admission_fee_precheck(string $classOrd): array
    {
        $plan = [
            'due' => false, 'waived' => false, 'amount' => 0.0,
            'fee_head' => 'Admission Fee', 'fee_label' => 'Admission Fee',
            'mode' => '', 'mode_label' => '', 'bank_code' => '', 'bank_reference' => '',
            'waive_reason' => '',
        ];

        $this->load->helper('admission_crm');
        $schoolDoc = $this->firebase->firestoreGet('schools', $this->school_name) ?? [];
        $feeInfo   = admission_resolve_fee(
            $this->fs, is_array($schoolDoc) ? $schoolDoc : [], $this->session_year, $classOrd
        );
        $amount = round((float) ($feeInfo['amount'] ?? 0), 2);
        if (empty($feeInfo['enabled']) || $amount <= 0) {
            return $plan;   // school hasn't configured an admission fee — nothing to charge
        }

        $plan['due']       = true;
        $plan['amount']    = $amount;
        $plan['fee_head']  = (string) ($feeInfo['fee_head'] ?? 'Admission Fee');
        $plan['fee_label'] = (string) ($feeInfo['label']    ?? $plan['fee_head']);

        // ── Waive override — separate graded capability + mandatory reason ──
        if ((string) $this->input->post('waive_admission_fee') === '1') {
            if (!has_permission('Fees', 'manage')) {
                log_message('error',
                    'G10 waive DENIED: admin=[' . ($this->admin_id ?? '') . '] role=['
                    . ($this->admin_role ?? '') . '] school=[' . $this->school_name
                    . '] lacks Fees:manage');
                $this->json_error('You do not have permission to waive the admission fee.', 403);
            }
            $reason = trim((string) ($this->input->post('waive_reason') ?? ''));
            if ($reason === '') {
                $this->json_error('A reason is required to waive the admission fee.');
            }
            $plan['waived']       = true;
            $plan['waive_reason'] = $reason;
            return $plan;
        }

        // ── Collection inputs — mirror collect_admission_fee's validation ──
        $mode = trim((string) $this->input->post('admission_payment_mode'));
        if ($mode === '') {
            $this->json_error('Select how the admission fee of ' . number_format($amount, 2)
                . ' was collected, or waive it.');
        }
        $modeMap = [
            'cash' => 'cash', 'upi' => 'upi', 'cheque' => 'cheque', 'neft' => 'neft', 'rtgs' => 'rtgs',
            'dd' => 'bank_transfer', 'demand draft' => 'bank_transfer', 'bank transfer' => 'bank_transfer',
            'pos' => 'bank_transfer', 'pos/card' => 'bank_transfer', 'card' => 'bank_transfer',
        ];
        $acctMode = $modeMap[strtolower($mode)] ?? null;
        if ($acctMode === null) {
            $this->json_error('Invalid payment mode: ' . $mode);
        }
        $bankCode = trim((string) $this->input->post('admission_bank_ledger_code'));
        if ($acctMode === 'cash') {
            $bankCode = '';
        } else {
            if ($bankCode === '') {
                $this->json_error('Select a bank ledger for a non-cash admission-fee collection.');
            }
            $okBank = false;
            foreach ((array) $this->firebase->firestoreQuery('chartOfAccounts', [['schoolId', '==', $this->school_name]]) as $row) {
                $cd = $row['data'] ?? $row;
                if ((string) ($cd['code'] ?? '') !== $bankCode) continue;
                if (!empty($cd['is_bank']) && empty($cd['is_group']) && ($cd['status'] ?? '') === 'active') $okBank = true;
                break;
            }
            if (!$okBank) {
                $this->json_error('Invalid or inactive bank ledger selected.');
            }
        }

        // ── Period lock — a collection must never post into a closed period ──
        $this->load->library('Accounting_cache', null, 'acctCacheAdm');
        $this->load->library('Accounting_period_lock', null, 'acctLockAdm');
        $this->acctCacheAdm->init($this->school_name, $this->session_year);
        $this->acctLockAdm->init($this->firebase, $this->acctCacheAdm, $this->school_name, $this->session_year);
        $lock = $this->acctLockAdm->forceValidate(date('Y-m-d'));
        if (!empty($lock['locked'])) {
            $this->json_error('The accounting period is closed up to ' . ($lock['lockedUntil'] ?? '')
                . '. Reopen it before admitting a student with an admission fee.');
        }

        $plan['mode']           = $acctMode;
        $plan['mode_label']     = $mode;
        $plan['bank_code']      = $bankCode;
        $plan['bank_reference'] = trim((string) ($this->input->post('admission_bank_reference') ?? ''));
        return $plan;
    }

    /**
     * G10 (2026-08-08) — execute the plan built by _admission_fee_precheck().
     * Either collects through the shared Admission_collection path (the SAME
     * receipt + journal engine pathways 1 and 2 use — no second implementation)
     * or records the audited waive. Never throws: the student is already
     * admitted by this point, so the outcome is reported back to the operator
     * in the response payload instead of failing the request.
     *
     * @return array reportable outcome for the JSON response
     */
    private function _admission_fee_apply(array $plan, string $userId, string $studentName, string $fatherName, string $classOrd, string $leadId): array
    {
        if (empty($plan['due'])) {
            return ['status' => 'not_applicable'];
        }

        if (!empty($plan['waived'])) {
            // Audited exception path — never a silent skip. Uses the same
            // auditLogs mechanism the rest of SIS already writes through.
            log_audit('Fees', 'admission_fee_waived', $userId,
                'Admission fee ' . number_format((float) $plan['amount'], 2)
                . ' WAIVED for ' . $studentName . ' (' . $userId . ') — reason: ' . $plan['waive_reason']);
            $this->_log_history($this->parent_db_key, $userId, 'ADMISSION_FEE_WAIVED',
                'Admission fee of ' . number_format((float) $plan['amount'], 2) . ' waived',
                [
                    'amount'    => (float) $plan['amount'],
                    'fee_head'  => $plan['fee_head'],
                    'reason'    => $plan['waive_reason'],
                    'waived_by' => (string) ($this->admin_id ?? ''),
                    'session'   => $this->session_year,
                ]
            );
            return [
                'status' => 'waived',
                'amount' => (float) $plan['amount'],
                'reason' => $plan['waive_reason'],
            ];
        }

        try {
            $this->load->library('Admission_collection', null, 'admissionCol');
            $this->admissionCol->init($this->firebase, $this->fs, $this->school_name, $this->session_year);
            // app_id: the originating lead when this admission converted one (so
            // the receipt/timeline correlate to it), else the new student id —
            // which keeps Admission_collection's app_id-derived idempotency key
            // stable and unique per admission. append_timeline() is a safe no-op
            // when no crmApplications doc exists for the id.
            $rc = $this->admissionCol->record_payment([
                'app_id'         => $leadId !== '' ? $leadId : $userId,
                'amount'         => (float) $plan['amount'],
                'currency'       => 'INR',
                'student_name'   => $studentName,
                'father_name'    => $fatherName,
                'applied_class'  => $classOrd,
                'fee_head'       => $plan['fee_head'],
                'fee_label'      => $plan['fee_label'],
                'payment_mode'   => $plan['mode'],
                'mode_label'     => $plan['mode_label'],
                'bank_code'      => $plan['bank_code'],
                'bank_reference' => $plan['bank_reference'],
                'remarks'        => 'Direct admission (SIS admission screen)',
                'collected_by'   => $this->admin_name ?: ($this->admin_id ?: 'Admin'),
                'source'         => 'offline',
                'admission_ref'  => 'ADMREF-' . strtoupper($userId),
            ]);
            if (!is_array($rc)) {
                log_message('error',
                    "G10 admission-fee collection returned null for {$userId} (amount={$plan['amount']}) — student admitted, fee NOT collected");
                return ['status' => 'failed', 'amount' => (float) $plan['amount']];
            }
            // A `deduped` result means Admission_collection's idempotency gate
            // matched an EXISTING receipt for this app_id — the correct outcome
            // when a lead's admission fee was already collected at the CRM
            // counter (pathway 2) and that lead is now being converted here. No
            // second receipt, no second journal, no double charge; report the
            // original receipt so the operator isn't told to collect again.
            return [
                'status'     => !empty($rc['deduped']) ? 'already_collected' : 'collected',
                'amount'     => (float) ($rc['amount'] ?? $plan['amount']),
                'receipt_no' => (string) ($rc['receipt_no'] ?? ''),
            ];
        } catch (\Throwable $e) {
            log_message('error', "G10 admission-fee collection failed for {$userId}: " . $e->getMessage());
            return ['status' => 'failed', 'amount' => (float) $plan['amount']];
        }
    }

    public function admission()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_admission', 'SIS', 'manage');
        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        $classMap = $this->_fs_class_map();

        // Preview next student ID
        $userId = $this->_peekNextStudentId($school_id);

        // Fees Exemption v2 (P0-b): the legacy exemption-checkbox feed was
        // removed here. The checkbox iterated schoolList('feeStructures')'s
        // doc-FIELD names (schoolId/session/feeHeads/...) as checkbox values,
        // so it could never store a real fee-head name. Concessions are now
        // captured via the dedicated Fee_concessions screen (Phase 0+).
        $data['class_map']     = $classMap;
        $data['session_year']  = $session;
        $data['school_name']   = $school_name;
        $data['user_Id']       = $userId;

        // G10 (2026-08-08) — admission-fee capture on the direct admission
        // screen. The amount is CLASS-SPECIFIC (feeStructures first, then the
        // global schools.admissionFee default), so it can't be resolved until
        // the operator picks a class — the view fetches it per class change via
        // resolve_admission_fee() and shows/hides the section on the result.
        // Waive authority = graded Fees:manage, deliberately independent of the
        // SIS:manage grant that gates this screen (see _admission_fee_precheck).
        $data['can_waive_admission_fee'] = has_permission('Fees', 'manage');
        $data['bank_ledgers']            = $this->_active_bank_ledgers();

        // LEAD SYSTEM — pass lead_id to view for prefill via AJAX
        $data['lead_id'] = trim($this->input->get('lead_id') ?? '');

        $this->load->view('include/header');
        $this->load->view('studentAdmission', $data);
        $this->load->view('include/footer');
    }

    /**
     * BUG-076 Part 2-A shared guard (extracted 2026-08-12).
     *
     * Returns '' when a usable fee structure exists for the given
     * class/section/session, otherwise the operator-facing error message.
     *
     * A student must never land Active-with-zero-demands because the fee
     * structure for their class/section/session was never configured — that
     * is the STU0004-11 incident. assignInitialFees() reads
     * feeStructures/{schoolId}_{session}_{class}_{section} and silently
     * returns [] when the doc is missing or carries no feeHeads, so the only
     * safe place to detect it is BEFORE any student/counter/Auth write.
     *
     * This body previously existed as three separate copies — in
     * execute_promotion() and enroll_student() (both correct) and NOT AT ALL
     * in save_admission(), which is how the walk-in path reproduced the
     * incident the other two paths were hardened against. One implementation,
     * three callers. Do not re-inline it.
     *
     * $classKey/$sectionKey MUST already be normalized through
     * Firestore_service::classKey()/sectionKey() — the docId has to match the
     * exact key assignInitialFees will read. ($school_name == school_id ==
     * SCH_xxxxxx.)
     *
     * @param string $verb Operation name for the message ('Admission',
     *                     'Promotion', 'Enrollment').
     */
    private function _fee_structure_missing(
        string $classKey,
        string $sectionKey,
        string $session,
        string $verb
    ): string {
        $docId  = "{$this->school_name}_{$session}_{$classKey}_{$sectionKey}";
        $struct = $this->fs->get('feeStructures', $docId);
        if (is_array($struct) && !empty($struct['feeHeads'])) {
            return '';
        }

        return "No fee structure exists for {$classKey} / {$sectionKey} in session "
            . "{$session}. {$verb} aborted to prevent the student being enrolled "
            . "without fee demands. Set up the fee structure for this class/section "
            . "in session {$session} first, then retry.";
    }

    /**
     * ── PHASE 20.3c (P20.2-RACE-1) — ATOMIC SEAT-GUARDED STUDENT WRITE ──────
     *
     * The single entry point every seat-consuming path in this controller uses
     * to write a student into a section. Replaces a bare
     * `entity_sync->syncStudent()` at each placement site.
     *
     * WHY: the capacity check and the student write were separate operations
     * with nothing between them, so two requests could both pass a 39/40 check
     * and both write — 41/40. This routes the write through
     * Section_seat_guard, which commits it atomically with a CAS marker on the
     * destination section. A concurrent writer that already moved that section
     * causes the whole batch to roll back — the student is NOT written — and
     * the placement is refused.
     *
     * IDENTITY SAFETY: this mints nothing. `$studentId` is fixed by the caller
     * before this is reached, the student document id is derived from it, and
     * a refusal writes nothing at all — so no SRNO, Student ID or Admission No
     * can be issued twice by a refused placement. In save_admission() the SRNO
     * / APAAR claim (_claimIdentityAttributes) runs AFTER this call, so a
     * refusal never reaches it.
     *
     * NOT used for non-placement student writes (profile edit, TC, withdrawal,
     * status change) — those consume no seat and keep calling syncStudent()
     * directly.
     *
     * @return array{status:string, message:string, attempts:int}
     */
    /**
 * The initialised seat guard. One accessor so every caller — admission and
 * reactivation alike — gets the same authority, initialised the same way,
 * rather than each repeating the load/init dance.
 */
    /**
 * Is atomic capacity enforcement on? Loads the class first.
 *
 * Every caller used to reach $this->_seatGuardEnabled() as a bare static
 * call, which only worked because MY_Controller happened to construct
 * Student_write_service, which loads this library for its own reasons. The
 * committed MY_Controller does not, so the static call died with "class not
 * found" — in save_admission, change_status, enroll_student and the importer,
 * one after another, each discovered only by running the real path. One
 * accessor that declares the dependency ends that whole class of bug.
 */
    private function _seatGuardEnabled(): bool
    {
        require_once APPPATH . 'libraries/Section_seat_guard.php';
        return Section_seat_guard::enabled();
    }

    private function _seatGuard()
    {
        if (!isset($this->seatGuard)) {
            $this->load->library('section_seat_guard', null, 'seatGuard');
            $this->seatGuard->init(
                $this->fs,
                $this->firebase,
                (string) $this->school_id,
                (string) ($this->admin_id ?? 'system')
            );
        }
        return $this->seatGuard;
    }

    private function _placeStudentGuarded(
        string $studentId,
        array $studentData,
        string $classNode,
        string $sectionNode,
        string $session,
        string $context = '',
        string $decisionVersion = ''
    ): array {
        if (!isset($this->seatGuard)) {
            $this->load->library('section_seat_guard', null, 'seatGuard');
            $this->seatGuard->init(
                $this->fs,
                $this->firebase,
                (string) $this->school_id,
                (string) ($this->admin_id ?? 'system')
            );
        }

        $res = $this->seatGuard->placeStudent(
            $this->entity_sync, $studentId, $studentData, $classNode, $sectionNode, $session,
            $decisionVersion
        );

        if (($res['status'] ?? '') !== Section_seat_guard::PLACED) {
            log_message('error',
                "ACC_PLACEMENT_REFUSED context={$context} schoolId={$this->school_id} "
                . "student={$studentId} section={$classNode}/{$sectionNode} session={$session} "
                . "status=" . ($res['status'] ?? '?'));
        }
        return $res;
    }

    public function save_admission()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_save_admission', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        // ── Basic fields ────────────────────────────────────────────────
        $name        = trim($this->input->post('name')           ?? '');
        $userId      = trim($this->input->post('user_id')       ?? '');
        $classOrd    = Firestore_service::classKey(trim($this->input->post('class') ?? ''));   // "Class 9th"
        $section     = Firestore_service::sectionKey(trim($this->input->post('section') ?? ''));   // "Section A"
        $phone       = trim($this->input->post('phone_number')  ?? $this->input->post('phone') ?? '');
        $email       = trim($this->input->post('email')         ?? '');
        $rollNo      = trim($this->input->post('roll_no')       ?? '');
        // Phase 9.1 (2026-08-13) — SRNO is no longer read from POST here
        // (or anywhere in the admission flow) — it is ALWAYS server-
        // allocated inside _claimIdentityAttributes(), same as
        // enroll_student(). Any `sr_no` a client might still send is
        // silently ignored. APAAR remains optional/operator-entered.
        $apaarInput  = trim((string) $this->input->post('apaar_id'));

        // M-07 FIX: Validate email format before storing
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json_error('Invalid email address format.');
        }

        // ══ ADM-INPUT-1 (2026-09-19) — INPUT GATE ════════════════════════════
        //
        // These checks used to sit AFTER the capacity and roll-number guards, so
        // Firestore reads were spent deciding seats for payloads that were never
        // admissible. Cheap local checks run first now.
        //
        // Several of them did not exist at all. The form was the only thing enforcing
        // a real date or a real mobile number, and this endpoint is a POST API also
        // reached by the CRM prefill and the lifecycle probe.

        if ($name === '' || $classOrd === '' || $section === '') {
            return $this->json_error('Name, class, and section are required.');
        }

        // DOB is REQUIRED as of ADM-INPUT-1. It drives age eligibility, the RTE age
        // band, the UDISE+ return and the ID card, and no mechanism existed to collect
        // it later. A school admitting a child has seen the birth certificate.
        $dobRes = $this->_strictDate((string) $this->input->post('dob'), 'Date of birth');
        if (!$dobRes['ok'])          return $this->json_error($dobRes['error']);
        if ($dobRes['value'] === '') return $this->json_error('Date of birth is required.');

        // A future DOB used to be accepted silently, and so did 01-01-1970 — which is
        // what date('d-m-Y', strtotime(<unparseable>)) returns. Both refuse now.
        $today = new DateTimeImmutable('today');
        $dobDt = $dobRes['dt'];
        if ($dobDt > $today) {
            return $this->json_error('Date of birth cannot be in the future.');
        }
        $ageYears = (int) $dobDt->diff($today)->y;
        if ($ageYears < self::ADMISSION_MIN_AGE || $ageYears > self::ADMISSION_MAX_AGE) {
            return $this->json_error(sprintf(
                'That date of birth makes the student %d years old at admission, outside the '
                . 'accepted range of %d-%d. Please correct it.',
                $ageYears, self::ADMISSION_MIN_AGE, self::ADMISSION_MAX_AGE
            ));
        }

        // ── ADM-AGE-1 · the school's own class-vs-age rule ───────────────────
        // Separate from the 2-30 sanity bounds above: those refuse what cannot be a
        // real school admission anywhere; this applies THIS school's policy, which
        // it configures in Academic Setup. Off by default — a school that has not
        // stated a rule is not silently given one.
        $ageVerdict = $this->_ageBandCheck($classOrd, $dobDt);
        if ($ageVerdict !== null && $ageVerdict['severity'] === 'block') {
            return $this->json_error($ageVerdict['message']);
        }
        // A 'warn' verdict is carried to the response and shown after the admission
        // succeeds — warning BEFORE the write would be a block wearing a soft word.
        $ageWarning = ($ageVerdict !== null) ? $ageVerdict['message'] : '';

        // Admission date: blank means today. A back-dated admission is legitimate;
        // one a year into the future is a typo, not an intention.
        $admRes = $this->_strictDate((string) $this->input->post('admission_date'), 'Admission date');
        if (!$admRes['ok']) return $this->json_error($admRes['error']);
        if ($admRes['value'] === '') {
            $admDate = $today->format('d-m-Y');
        } else {
            if ($admRes['dt'] > $today->modify('+1 year')) {
                return $this->json_error('Admission date is more than a year in the future. Please check it.');
            }
            $admDate = $admRes['value'];
        }
        $dob = $dobRes['value'];

        // Phone is REQUIRED and must be a real Indian mobile. The old server pattern
        // accepted six spaces while the form enforced ^[6-9]\d{9}$, so the browser was
        // the only real validator — and whatever got through was written into
        // indexPhones, the shared index that sibling and duplicate detection rely on.
        $phone10 = $this->_normalisePhone10($phone);
        if ($phone10 === '') {
            return $this->json_error(
                'A valid 10-digit mobile number is required. It is how the school reaches '
                . 'the family, how the Parent app finds this child, and how a duplicate '
                . 'admission is detected.'
            );
        }
        $phone = $phone10;

        // ── ADM-DUP-1 · duplicate PERSON, not duplicate id ───────────────────
        // The only guard that called itself a duplicate check tested whether the ID
        // GENERATOR had collided. Admitting the same child twice was unobstructed and
        // produced two SR numbers, two Auth accounts, two fee ledgers and two heads
        // counted against one human being.
        //
        // The rule is deliberately the one the IMPORT path already proved (SIB-B1 /
        // P14-1): phone AND name must both match. Phone alone is not proof — siblings
        // share a phone, and blocking on it would refuse every second child. Both
        // lookups are indexed reads, not a roster scan.
        //
        // It WARNS rather than forbids: two children really can share a name and a
        // phone. The operator confirms, and the confirmation is recorded.
        $nameNorm = strtolower(preg_replace('/\s+/', ' ', trim($name)));
        $dupe     = $this->_duplicateStudentSuspect($nameNorm, $phone10);
        $dupeConfirmed = (string) $this->input->post('confirm_duplicate') === '1';
        if ($dupe !== null && !$dupeConfirmed) {
            // 200 with a distinct status. A 4xx lands in the client's error handler,
            // which cannot offer the operator a choice.
            $isGrad = (($dupe['kind'] ?? '') === 'graduated');
            header('Content-Type: application/json');
            echo json_encode([
                'status'     => 'confirm_required',
                'reason'     => $isGrad ? 'graduated_readmission' : 'duplicate_student',
                'duplicate'  => $dupe,
                'message'    => $isGrad
                    ? sprintf(
                        '%s (%s) previously GRADUATED from %s %s (Alumni) on this mobile number. '
                        . 'Admitting again creates a NEW student record — confirm only if this is a '
                        . 'genuine fresh re-admission.',
                        $dupe['name'], $dupe['studentId'], $dupe['class'], $dupe['section']
                    )
                    : sprintf(
                        '%s (%s) is already admitted in %s %s on this same mobile number. '
                        . 'Admitting again creates a second student record, a second SR number '
                        . 'and a second fee ledger.',
                        $dupe['name'], $dupe['studentId'], $dupe['class'], $dupe['section']
                    ),
                'csrf_token' => $this->security->get_csrf_hash(),
            ]);
            exit;
        }

        // ── Phase 15 (HIGH-1) — Section capacity, SERVER-AUTHORITATIVE ──────
        // Direct admission previously had NO capacity awareness at all: an
        // operator could admit the 41st student into a 40-seat section and
        // nothing objected. The CRM screen showed live headroom via
        // get_class_sections(), but that is a UI SUGGESTION helper — source
        // check confirmed enroll_student() had no server-side guard either,
        // so the client was the only thing standing between a school and an
        // over-full register on BOTH paths.
        //
        // Checked here, at commit time, against the LIVE roster — not against
        // whatever the browser saw when the form was opened. A stale tab whose
        // seats were consumed while the operator typed is correctly rejected.
        //
        // Placed BEFORE the roll check and before any write, so a rejection
        // burns no Student ID, claims no SRNO, and creates no document.
        // SEAT-RACE-1: capture the section version BEFORE counting occupancy, so
        // the version covers the count itself. It is carried into the placement
        // commit as the CAS precondition, which is what makes this decision
        // unfalsifiable by a concurrent admission rather than merely unchanged
        // during the commit. Empty means the version could not be established;
        // the guard then refuses, which is the fail-closed direction.
        require_once APPPATH . 'libraries/Section_seat_guard.php';
        $decisionVersion = $this->_sectionDecisionVersion($classOrd, $section, $this->session_year);
        $__occ = $this->_sectionActiveCountFresh($classOrd, $section, $this->session_year);
        Section_seat_guard::trace('decide', ['ver' => substr($decisionVersion, 11, 15), 'occ' => $__occ, 'cap' => $this->_sectionCapacity($classOrd, $section, $this->session_year)]);
        $capOver = $this->_sectionCapacityExceeded($classOrd, $section, $this->session_year, 1, '', /* fresh */ true);
        if ($capOver !== null) {
            return $this->json_error($this->_sectionCapacityMessage($capOver, $classOrd, $section));
        }

        // ── Phase 14.1 — Roll Number roster uniqueness ──────────────────────
        // Rejected BEFORE any write, so a collision can never leave a
        // half-created student (no Student ID burned, no SRNO claimed, no
        // Firestore document). Scoped to School+Session+Class+Section, so the
        // same roll stays legal in another section, class, session or school.
        // A blank roll skips the check entirely — assigning later is normal.
        if ($rollNo !== '') {
            $rollConflict = $this->_rollNoConflict($rollNo, $classOrd, $section, $this->session_year);
            if ($rollConflict !== null) {
                return $this->json_error($this->_rollNoConflictMessage($rollConflict, $classOrd, $section));
            }
        }

        // Phone, DOB and admission date are validated and normalised by the
        // ADM-INPUT-1 gate above, before any Firestore read. The permissive
        // 6-to-20-character phone pattern and the strtotime() coercion that turned an
        // unparseable date into 01-01-1970 both used to live here.

        // ── Personal ────────────────────────────────────────────────
        $gender      = trim($this->input->post('gender')        ?? '');
        $category    = trim($this->input->post('category')      ?? '');
        $bloodGroup  = trim($this->input->post('blood_group')   ?? '');
        $religion    = trim($this->input->post('religion')      ?? '');
        // FIXED: "Other" religion should use the custom value (matches edit_student logic)
        if ($religion === 'Other') {
            $otherReligion = trim($this->input->post('other_religion') ?? '');
            if ($otherReligion !== '') $religion = $otherReligion;
        }
        $nationality = trim($this->input->post('nationality')   ?? '');

        // ── Family ──────────────────────────────────────────────────
        $father       = trim($this->input->post('father_name')       ?? '');
        $fatherOcc    = trim($this->input->post('father_occupation') ?? '');
        $mother       = trim($this->input->post('mother_name')       ?? '');
        $motherOcc    = trim($this->input->post('mother_occupation') ?? '');
        $guardContact = trim($this->input->post('guard_contact')     ?? '');
        $guardRelation= trim($this->input->post('guard_relation')    ?? '');

        // ── Previous Education ──────────────────────────────────────
        $preClass  = trim($this->input->post('pre_class')  ?? '');
        $preSchool = trim($this->input->post('pre_school') ?? '');
        $preMarks  = trim($this->input->post('pre_marks')  ?? '');
        if ($preMarks !== '' && substr($preMarks, -1) !== '%') {
            $preMarks .= '%';
        }

        // ── Address (separate fields matching Student.php) ──────────
        $street     = trim($this->input->post('street')      ?? $this->input->post('address') ?? '');
        $city       = trim($this->input->post('city')        ?? '');
        $state      = trim($this->input->post('state')       ?? '');
        $postalCode = trim($this->input->post('postal_code') ?? '');

        // Name/class/section presence is asserted by the ADM-INPUT-1 gate above, so a
        // payload missing them never reaches the capacity or roll-number reads.

        // Sanitize path segments (exits with json_error on invalid input)
        $this->safe_path_segment($classOrd, 'class');
        $this->safe_path_segment($section, 'section');

        // BUG-076 Part 2-A (walk-in path, 2026-08-12) — fee-structure
        // pre-flight. execute_promotion() and enroll_student() have carried
        // this guard since 2026-05-28/05-31; save_admission() never did, so the
        // walk-in path could still admit a student Active-with-zero-demands
        // (the STU0004-11 incident) whenever the operator had not configured
        // the destination fee structure. Placement is load-bearing: this runs
        // BEFORE _nextStudentId() below, so a blocked admission does not burn a
        // student ID off the counter (the burned-ID hazard documented further
        // down this method).
        $feeStructErr = $this->_fee_structure_missing($classOrd, $section, $session, 'Admission');
        if ($feeStructErr !== '') {
            return $this->json_error($feeStructErr);
        }

        // G10 (2026-08-08) — resolve + validate the admission fee BEFORE any
        // student/counter/Auth write, so a blocked or mis-configured collection
        // rejects the request cleanly rather than admitting a student whose fee
        // then can't be charged. Exits via json_error on any problem. Applied
        // after the student exists (see _admission_fee_apply below).
        $admissionFeePlan = $this->_admission_fee_precheck($classOrd);

        // ── ADM-IDEMPOTENCY (lifecycle-critic fix) ───────────────────────────
        // A double-POST of the SAME admission (double-click, proxy retry, or the
        // lifecycle probe firing twice) would mint TWO students, TWO SR numbers
        // and TWO fee ledgers — the seat CAS stops OVER-FILLING a section but not
        // DUPLICATE creation of the same child. A short-lived, self-expiring
        // mutex keyed on the submission identity collapses a rapid re-submit to
        // one student. It does NOT block a genuine later re-admission (window is
        // seconds) and never blocks distinct students (twins differ by name →
        // different key). A confirmed-duplicate submit (confirm_duplicate=1) gets
        // its own key namespace, so a deliberate second record is not blocked by
        // the first child's lock. Race-safe via createIfAbsent (mirrors the
        // crmEnrollLocks pattern enroll_student uses).
        $idemWindowSec = 20;
        $admitKey = $this->fs->docId('ADMIT_' . sha1(
            $school_id . '|' . $nameNorm . '|' . $phone10 . '|' . $classOrd . '|'
            . $section . '|' . $session . ($dupeConfirmed ? '|dup' : '')
        ));
        $admitLock = ['schoolId' => $school_id, 'ts' => time(),
                      'by' => (string) ($this->admin_id ?? 'system')];
        if (!$this->fs->createIfAbsent('admissionSubmitLocks', $admitKey, $admitLock)) {
            $held = $this->fs->get('admissionSubmitLocks', $admitKey);
            if (is_array($held) && (int) ($held['ts'] ?? 0) > time() - $idemWindowSec) {
                return $this->json_error(
                    'This admission was just submitted and is still being processed — '
                    . 'please wait a moment and refresh the student list rather than '
                    . 'submitting again.'
                );
            }
            // Stale lock (older than the window): reclaim it and proceed.
            $this->fs->set('admissionSubmitLocks', $admitKey, $admitLock, false);
        }

        // Always generate userId at save time (counter increments only here, not on page load)
        $generated = $this->_nextStudentId($school_id);
        if (!$generated) {
            return $this->json_error('Failed to generate student ID. Please try again.');
        }
        $userId = $generated;

        // Check for duplicate — ensures no existing profile is overwritten
        $existing = $this->_getStudent($userId);
        if (!empty($existing)) {
            return $this->json_error("Student ID {$userId} already exists.");
        }

        // ── Password — same generation method as Student.php ────────
        $password = $this->_generatePassword($name, $dob);

        // ── Photo & Document uploads ────────────────────────────────
        $classKeyForPath = $classOrd;  // Already "Class 8th"
        $combinedClassPath = "{$classKeyForPath}/{$section}";  // "Class 8th/Section A"

        $profilePicUrl = '';
        $docData = [
            'Birth Certificate'    => ['url' => '', 'thumbnail' => ''],
            'Aadhar Card'          => ['url' => '', 'thumbnail' => ''],
            'Transfer Certificate' => ['url' => '', 'thumbnail' => ''],
            'Photo'                => ['url' => '', 'thumbnail' => ''],
        ];

        // Student photo (optional in SIS — can upload later via documents page)
        if (!empty($_FILES['student_photo']['tmp_name']) && is_uploaded_file($_FILES['student_photo']['tmp_name'])) {
            $photoResult = $this->_uploadStudentFile(
                $_FILES['student_photo'], $school_name, $combinedClassPath, $userId, 'profile', 'profile'
            );
            if ($photoResult) {
                $profilePicUrl = $photoResult['document'];
                $docData['Photo'] = ['url' => $photoResult['document'], 'thumbnail' => $photoResult['thumbnail']];
            }
        }

        // Documents (Birth Certificate, Aadhar Card, Transfer Certificate)
        $docInputs = [
            'birthCertificate'    => 'Birth Certificate',
            'aadharCard'          => 'Aadhar Card',
            'transferCertificate' => 'Transfer Certificate',
        ];
        foreach ($docInputs as $inputKey => $label) {
            if (!empty($_FILES[$inputKey]['tmp_name']) && is_uploaded_file($_FILES[$inputKey]['tmp_name'])) {
                $uploadResult = $this->_uploadStudentFile(
                    $_FILES[$inputKey], $school_name, $combinedClassPath, $userId, $label, 'document'
                );
                if ($uploadResult) {
                    $docData[$label] = ['url' => $uploadResult['document'], 'thumbnail' => $uploadResult['thumbnail']];
                }
            }
        }

        // ── Carry over files uploaded on the public admission form (lead) ──
        // When converting a public application, reuse the photo / Aadhaar /
        // supporting documents the parent already uploaded (they live in
        // Firebase Storage as permanent tokenized URLs) instead of forcing the
        // admin to re-scan them. URLs are read SERVER-SIDE from the lead doc —
        // never from client input — and only fill slots the admin did not just
        // upload above, so a fresh upload always wins.
        $leadIdForFiles = trim((string) $this->input->post('lead_id'));
        if ($leadIdForFiles !== '' && preg_match('/^[A-Za-z0-9_]+$/', $leadIdForFiles)) {
            $leadDocForFiles = $this->fs->get('crmApplications', $this->fs->docId($leadIdForFiles));
            if (is_array($leadDocForFiles)) {
                $leadPhoto = trim((string) ($leadDocForFiles['photo_url'] ?? ''));
                if ($profilePicUrl === '' && $leadPhoto !== '') {
                    $profilePicUrl    = $leadPhoto;
                    $docData['Photo'] = ['url' => $leadPhoto, 'thumbnail' => $leadPhoto];
                }
                $leadAadhaar = trim((string) ($leadDocForFiles['aadhaar_url'] ?? ''));
                if (($docData['Aadhar Card']['url'] ?? '') === '' && $leadAadhaar !== '') {
                    $docData['Aadhar Card'] = ['url' => $leadAadhaar, 'thumbnail' => ''];
                }
                if (is_array($leadDocForFiles['documents'] ?? null)) {
                    foreach ($leadDocForFiles['documents'] as $ld) {
                        if (!is_array($ld)) continue;
                        $lurl = trim((string) ($ld['url'] ?? ''));
                        $lname = trim((string) ($ld['name'] ?? ''));
                        if ($lurl === '') continue;
                        $key = $lname !== '' ? substr(preg_replace('/[^A-Za-z0-9 _.-]/', '', $lname), 0, 60) : '';
                        if ($key === '' || isset($docData[$key])) $key = 'Application Document ' . (count($docData) + 1);
                        $docData[$key] = ['url' => $lurl, 'thumbnail' => ''];
                    }
                }

                // ── Phase 1 (revised D1): REFERENCE the same canonical documents ──
                // Enrollment does NOT move or copy bytes. For every canonical
                // document the parent uploaded on the lead, add a 'student' link
                // so the one physical Storage object is now referenced by BOTH the
                // admission application and the student (reference-counted; central
                // retention). The Doc[] URLs above remain denormalized display
                // pointers. Old leads without documentRefs skip this untouched.
                if (is_array($leadDocForFiles['documentRefs'] ?? null) && $leadDocForFiles['documentRefs']) {
                    $this->load->library('document_service', null, 'docsvc');
                    $this->docsvc->init($this->firebase, $school_id);
                    $predefLabels = [
                        'birth_certificate'    => 'Birth Certificate',
                        'transfer_certificate' => 'Transfer Certificate',
                        'marksheet'            => 'Marksheet',
                        'medical_certificate'  => 'Medical Certificate',
                    ];
                    foreach ($leadDocForFiles['documentRefs'] as $ref) {
                        if (!is_array($ref)) continue;
                        $docId = trim((string) ($ref['documentId'] ?? ''));
                        if ($docId === '') continue;
                        $role  = (string) ($ref['role'] ?? ($ref['type'] ?? 'document'));
                        // Link the canonical document to the enrolled student (no copy).
                        $this->docsvc->link($docId, 'student', $userId, $role, (string) ($this->admin_id ?? 'system'));
                        // Surface the 4 predefined certificate types in the student
                        // Doc[] map (photo/aadhaar/additional are carried above already).
                        $rtype = (string) ($ref['type'] ?? '');
                        if (isset($predefLabels[$rtype])) {
                            $lbl  = $predefLabels[$rtype];
                            $rurl = trim((string) ($ref['url'] ?? ''));
                            if ($rurl !== '' && ($docData[$lbl]['url'] ?? '') === '') {
                                $docData[$lbl] = ['url' => $rurl, 'thumbnail' => ''];
                            }
                        }
                    }
                }
            }
        }

        // ── Build student data — exact schema match with Student.php ─
        $studentData = [
            'Name'           => $name,
            'User Id'        => $userId,
            'DOB'            => $dob,
            'Admission Date' => $admDate,

            'Class'          => $classOrd,
            'Section'        => $section,

            'Phone Number'   => $phone,
            'Email'          => $email,
            'Password'       => $password,

            'Category'       => $category,
            'Gender'         => $gender,
            'Blood Group'    => $bloodGroup,
            'Religion'       => $religion,
            'Nationality'    => $nationality,

            'Father Name'        => $father,
            'Father Occupation'  => $fatherOcc,
            'Mother Name'        => $mother,
            'Mother Occupation'  => $motherOcc,
            'Guard Contact'      => $guardContact,
            'Guard Relation'     => $guardRelation,

            'Pre Class'      => $preClass,
            'Pre School'     => $preSchool,
            'Pre Marks'      => $preMarks,

            'Address' => [
                'Street'     => $street,
                'City'       => $city,
                'State'      => $state,
                'PostalCode' => $postalCode,
            ],

            'Profile Pic'    => $profilePicUrl,
            'Doc'            => $docData,

            'Roll No'        => $rollNo,
            'Status'         => 'Active',
        ];

        $classKey   = $classOrd;   // Already "Class 8th"
        $sectionKey = $section;    // Already "Section A"

        // ══════════════════════════════════════════════════════════════
        // 1. FIRESTORE FIRST (primary) — Student profile for Android apps
        // ══════════════════════════════════════════════════════════════
        // SIS Wave-2 S6 (2026-05-31): capture sync return so silent failure
        // is visible in logs. Observability-only; no behavior change.
        // ── PHASE 20.3h — PATH 1/5: ADMISSION (P20.2-RACE-1) ────────────
        // Primary student write. When the atomic capacity authority is ON this
        // commits the student AND the destination-section CAS marker in one
        // Firestore batch; a concurrent writer that already moved the section
        // causes the whole batch to roll back and the admission is refused
        // without writing anything.
        //
        // A refusal returns HERE, before _claimIdentityAttributes() below, so
        // no SRNO or APAAR is claimed for an admission that did not happen
        // (ordering verified in Phase 20.3e-1).
        if ($this->_seatGuardEnabled()) {
            // ── SEAT-RACE-1 · BOUNDED RE-DECIDE ─────────────────────────────
            //
            // A CAS conflict means a competing admission committed first, so
            // this request's capacity decision is stale — not wrong. Refusing
            // outright would be safe but would under-admit: five simultaneous
            // requests for two seats would fill one seat and reject four.
            //
            // So a loser re-decides against the roster as it is now, using the
            // UNCACHED count (the per-request memo still holds the roster that
            // lost) and a freshly captured section version, then retries. It
            // stops as soon as the section is genuinely full, which is the
            // answer the operator should get.
            //
            // The student id is minted before this point and is NOT re-minted
            // on retry, so a retry consumes no additional id.
            $placement = ['status' => '', 'message' => ''];
            $capacityMsg = '';
            for ($attempt = 1; $attempt <= self::SEAT_RETRY_ATTEMPTS; $attempt++) {
                if ($attempt > 1) {
                    // Re-decide FIRST: if the seats are gone, say so plainly
                    // instead of reporting a concurrency conflict.
                    // ORDER IS LOAD-BEARING — capture the version BEFORE counting.
                    // The initial decision already does this; the retry did not, and
                    // that asymmetry was the residual race. Captured:
                    //   .213710 STU0253 PLACED            -> occupancy becomes 2
                    //   .223117 STU0254 redecide occ=1    -> stale roster
                    //   .226557 STU0254 version captured  -> NEWER than that roster
                    //   .233429 STU0254 commit SUCCESS    -> CAS passes honestly
                    // A version taken after the count cannot witness a placement that
                    // landed during the count, so the CAS proves nothing about the
                    // decision. Taken before, any such placement invalidates it and we
                    // retry instead of consuming a seat that is already gone. The count
                    // may still be stale; the version makes that harmless.
                    $decisionVersion = $this->_sectionDecisionVersion(
                        $classOrd, $section, $this->session_year
                    );
                    $cap = $this->_sectionCapacity($classOrd, $section, $this->session_year);
                    if ($cap > 0) {
                        $now = $this->_sectionActiveCountFresh(
                            $classOrd, $section, $this->session_year
                        );
                        if ($now + 1 > $cap) {
                            // Match _sectionCapacityExceeded()'s return shape exactly —
                            // _sectionCapacityMessage() reads 'incoming' and 'over', and a
                            // hand-built array missing them produced a PHP warning instead
                            // of the operator-facing "section is full" message.
                            $capacityMsg = $this->_sectionCapacityMessage(
                                [
                                    'capacity'  => $cap,
                                    'current'   => $now,
                                    'incoming'  => 1,
                                    'projected' => $now + 1,
                                    'over'      => ($now + 1) - $cap,
                                ],
                                $classOrd, $section
                            );
                            break;
                        }
                    }
                    Section_seat_guard::trace('redecide', ['occ' => ($now ?? -1), 'cap' => ($cap ?? -1), 'ver' => substr($decisionVersion, 11, 15)]);
                }

                Section_seat_guard::trace('attempt', ['n' => $attempt, 'ver' => substr($decisionVersion, 11, 15), 'stu' => $userId]);
                $placement = $this->_placeStudentGuarded(
                    $userId, $studentData, $classOrd, $section, $this->session_year, 'save_admission',
                    $decisionVersion
                );

                Section_seat_guard::trace('outcome', ['n' => $attempt, 'status' => ($placement['status'] ?? '?'), 'stu' => $userId]);
                if (($placement['status'] ?? '') === Section_seat_guard::PLACED) break;

                // Only a CONFLICT is retryable. FAILURE means the outcome is
                // unknown and re-committing an unknown outcome is how duplicate
                // students are made.
                if (($placement['status'] ?? '') !== Section_seat_guard::CONFLICT) break;
            }

            if (($placement['status'] ?? '') !== Section_seat_guard::PLACED) {
                return $this->json_error(
                    $capacityMsg !== ''
                        ? $capacityMsg
                        : ($placement['message'] ?: 'Admission could not be saved.')
                );
            }
        } elseif (!$this->entity_sync->syncStudent($userId, $studentData)) {
            log_message('warning', "syncStudent returned false for {$userId} (save_admission)");
        }
        if (!$this->entity_sync->syncParent($userId, $studentData)) {
            log_message('warning', "syncParent returned false for {$userId} (save_admission)");
        }

        // G2 — Refresh sections.currentStrength after a new admit so the
        // admission section-picker's strength bars stay accurate.
        $this->_recompute_section_strength($classKey, $sectionKey);

        // Phone index — P14-1: append, never overwrite, so a sibling admitted
        // later does not evict this student from the shared phone.
        if (!empty($phone)) {
            $this->_registerPhoneIndex($phone, $userId, 'student');
        }

        // Fee month markers
        // SIS Wave-2 fix F5 (2026-05-31): fail-loud guard. Pre-fix, a
        // Firestore failure here was logged but admission continued —
        // student would exist with no monthFee field, defaulter engine
        // would report incorrect status, and admin would have no signal.
        // The current ordering ALREADY puts monthFee init before Firebase
        // Auth creation at L500+, so a fail-here abort cleanly avoids
        // orphan-Auth side-effects (Option-3 intent achieved without
        // reorder — current code is already correct).
        $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
        $monthFeeInit = [];
        foreach ($months as $m) $monthFeeInit[$m] = 0;
        $monthFeeOk = false;
        for ($attempt = 1; $attempt <= 3 && !$monthFeeOk; $attempt++) {
            try {
                $monthFeeOk = (bool) $this->fs->updateEntity('students', $userId, ['monthFee' => $monthFeeInit]);
            } catch (\Throwable $e) {
                log_message('error', "SIS admit fee init failed for {$userId} (attempt {$attempt}): " . $e->getMessage());
            }
        }
        if (!$monthFeeOk) {
            // The student doc, phone index and section strength were ALREADY
            // written above — the student DOES exist. The old message ("the
            // student record was not created") was false and made the operator
            // re-submit → a duplicate person + a burned student ID. Tell the
            // truth and direct them to re-initialize fees instead of re-adding.
            return $this->json_error('Student ' . $userId . ' was created, but fee tracking could not be initialized. Do NOT re-add the student — open their Fees page and retry fee initialization.');
        }

        // Subject assignment
        $classNumber = 0;
        if (preg_match('/\d+/', $classOrd, $classMatch)) {
            $classNumber = (int)$classMatch[0];
        }
        if ($classNumber > 0) {
            $subjectDocs = $this->fs->schoolWhere('subjects', [['classKey', '==', (string)$classNumber]]);
            $coreSubjects = [];
            foreach ($subjectDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $item = $doc['data'];
                $code = $item['subjectCode'] ?? $item['code'] ?? $d['id'];
                $subName = trim($item['name'] ?? $item['subject_name'] ?? '');
                if ($subName === '') continue;
                $type = strtolower(trim($item['category'] ?? ''));
                if ($type === 'core') $coreSubjects[(string)$code] = ['name' => $subName, 'type' => 'core'];
            }
            if (!empty($coreSubjects)) {
                $this->fs->updateEntity('students', $userId, ['subjects' => $coreSubjects]);
            }
        }

        // Additional subjects
        $additionalSubjects = $this->input->post('additional_subjects');
        if (!empty($additionalSubjects) && is_array($additionalSubjects)) {
            $addSubData = [];
            foreach ($additionalSubjects as $sub) {
                $sub = trim($sub);
                if ($sub !== '') $addSubData[$sub] = '';
            }
            if (!empty($addSubData)) {
                $this->fs->updateEntity('students', $userId, ['additionalSubjects' => $addSubData]);
            }
        }

        // Phase 9 correction pass (2026-08-13) — optional SRNO + APAAR
        // claim, wired at the SAME best-effort point subjects/
        // additionalSubjects already use (student record already exists
        // and is committed by this point). SAME shared helper
        // enroll_student() uses — one canonical wiring to
        // Sr_no_service/Apaar_service across both admission entry points.
        $srnoAssigned     = true;
        $identityWarnings = $this->_claimIdentityAttributes($userId, $school_id, $apaarInput, $studentData, $srnoAssigned);

        // ── SRNO-F1 (2026-08-17) — ADMISSION IS AN IDENTITY TRANSACTION ─────
        //
        // INVARIANT: student creation + SRNO allocation = successful admission.
        // `studentId` is the global system identity; SRNO is the school-scoped
        // OPERATIONAL identity the school works by. Half of that pair is not an
        // admission, and reporting it as one — which is exactly what this method
        // used to do, appending "SRNO requires administrator follow-up" to a
        // SUCCESS response — leaves an unusable student the operator believes is
        // finished.
        //
        // WHY FAILING HERE IS SAFE. At this point the student document, parent
        // record, phone index, section strength, monthFee and subjects exist —
        // but the Firebase Auth user, the entitlement, the fee demands, the
        // history/audit entry and the admission-fee receipt do NOT. Nothing
        // financial and no credential has been created, so this is the last
        // point at which the request can be undone cheaply. The ordering is
        // asserted by PhaseSrnoF1AdmissionIdentityTest, so a future reorder that
        // moved money above this line would fail the build.
        //
        // COMPENSATION mirrors the PROVEN Import contract (Sis:6190 and
        // Phase203kStep0ImportHarnessTest::test_GOLDEN_03_...): delete the
        // student and return the Student ID to the counter, so a refused
        // admission burns no identity. Section strength is recomputed because
        // THIS method inflated it above — leaving it would permanently consume a
        // seat for a student that does not exist, corrupting the very data the
        // atomic-capacity guard reads.
        //
        // DELIBERATELY NOT UNDONE: the phone index and the parent record. Both
        // are SHARED, append-shaped structures — the phone index is explicitly
        // append-never-overwrite so siblings can share a number — so removing
        // this student's entry risks damaging a DIFFERENT student's data. That
        // residue is reported rather than silently cleaned; a wrong repair here
        // is worse than a known one.
        if (!$srnoAssigned) {
            log_message('error', "ACC_ADMISSION_SRNO_FAILED student=[{$userId}] school=[{$school_id}] "
                . '— admission REFUSED and rolled back; no SRNO could be allocated.');

            try {
                $this->entity_sync->deleteStudent($userId);
            } catch (\Throwable $e) {
                log_message('error', "ACC_ADMISSION_SRNO_ROLLBACK_FAILED student=[{$userId}]: " . $e->getMessage());
            }
            $this->id_generator->releaseClaim('STU', (int) preg_replace('/\D/', '', $userId));
            $this->_recompute_section_strength($classKey, $sectionKey);

            return $this->json_error(
                'Admission could not be completed: an SR Number could not be allocated for this '
                . 'student, and every admitted student must have one. No student record, fee demand '
                . 'or receipt was created. Please retry; if it keeps failing, check the SR Number '
                . 'counter for this school before re-admitting.'
            );
        }

        // Fees Exemption v2 (P0-b): the exempted_fees_multiple admission write
        // was removed. Concessions are captured via Fee_concessions (Phase 0+),
        // applied by the unified generator (Phase 2+). Admission billing here
        // is unchanged — assignInitialFees still runs for this student.

        // RTDB mirror removed per no-RTDB policy. Firestore `students` is the sole source.

        // ══════════════════════════════════════════════════════════════
        // 2. FIREBASE AUTH — Parent app login
        // ══════════════════════════════════════════════════════════════
        // SIS Wave-3 D3 (2026-05-31): consolidated via _createFirebaseAuthStudent.
        // Return value intentionally ignored — save_admission's pre-fix
        // behavior was silent-continue on Auth failure. B3 fix (surface
        // Auth failure to operator) is separate Tier-2 territory.
        $this->_createFirebaseAuthStudent($userId, $password, $name, 'SIS save_admission');

        // ══════════════════════════════════════════════════════════════
        // 3.5 ENTITLEMENT RESOLUTION — PHASE 3B / B1 (must precede pricing)
        // ══════════════════════════════════════════════════════════════
        //
        // THE DEFECT THIS CLOSES. Entitlement capture used to be possible only
        // AFTER this request returned (ENTCAP.mount() runs in the response
        // handler), so a concession agreed at the admission desk could not
        // affect the demands this method was about to write. The operator saw
        // "Admission successful", the parent saw a full-price invoice, and
        // nothing anywhere reported a problem.
        //
        // An entitlement KNOWN at admission time is now submitted with the form
        // and resolved HERE — after identity, before pricing — so
        // assignInitialFees() below prices against it. Post-hoc capture through
        // ENTCAP is unchanged and still supported for concessions agreed later.
        //
        // Placement is load-bearing: after _claimIdentityAttributes() so the
        // student is complete, and before assignInitialFees() so the demand is
        // priced once, correctly, rather than written and then repaired.
        $entitlementState = $this->_resolve_admission_entitlement($userId, $school_id);

        // ══════════════════════════════════════════════════════════════
        // 4. POST-ADMISSION — Fees, history, leads, notifications
        // ══════════════════════════════════════════════════════════════
        //
        // PHASE 3B / B6 — A FINANCIAL OPERATION MUST NOT FAIL SILENTLY.
        // This used to catch, log, and carry on: the student was admitted, the
        // response said "success", and the student had ZERO demands. The
        // operator's next signal was a parent asking why no fee was due.
        //
        // The student genuinely exists by this point, so aborting with
        // json_error would be a lie in the other direction — hence an explicit
        // BILLING STATE rather than a boolean success. The admission stands; the
        // billing outcome is reported truthfully.
        $billingState = 'billed';
        $billingNote  = '';
        try {
            $assigned = $this->feeLifecycle->assignInitialFees($userId, $classOrd, $section, $school_id);
            if (!is_array($assigned) || $assigned === []) {
                // assignInitialFees() returns the assigned heads. Empty means no
                // demand was created — a missing/blocked fee structure, or a
                // failed commit that it logged internally.
                $billingState = 'billing_failed';
                $billingNote  = 'No fee demands were created. Open the student\'s Fees page and run '
                              . 'Generate Demands before collecting any payment.';
                log_message('error', "ACC_ADMISSION_BILLING_EMPTY student=[{$userId}] class=[{$classOrd}/{$section}] "
                    . '— admission succeeded with ZERO demands.');
            }
        } catch (\Throwable $e) {
            $billingState = 'billing_failed';
            $billingNote  = 'Fee generation failed for this admission. Do NOT collect payment until '
                          . 'demands have been generated from the student\'s Fees page.';
            log_message('error', "ACC_ADMISSION_BILLING_FAILED student=[{$userId}]: " . $e->getMessage());
        }

        // An entitlement that could not be resolved means the demand above was
        // priced WITHOUT it. That is a pending billing decision, not a success.
        if ($billingState === 'billed' && ($entitlementState['status'] ?? 'none') === 'failed') {
            $billingState = 'billing_pending';
            $billingNote  = 'The student was admitted and billed, but the concession could not be '
                          . 'recorded, so it is NOT reflected in these demands. Add it from the '
                          . 'student\'s record, then regenerate the demands.';
        }

        // ADM-ENT-1 — recorded, but into a store this tenant's pricing engine
        // does not read, so the demands above are FULL PRICE. Same operator
        // consequence as 'failed' (a concession not reflected in the demands),
        // so it gets the same treatment rather than a success message that is
        // not true. The wording differs because the remedy differs: re-adding
        // it from the student record will NOT help — the tenant needs the
        // canonical billing authority.
        if ($billingState === 'billed' && ($entitlementState['status'] ?? 'none') === 'recorded_not_priced') {
            $billingState = 'billing_pending';
            $billingNote  = 'The student was admitted and billed, and the concession WAS recorded — '
                          . 'but this school is not yet on entitlement-based billing, so the '
                          . 'concession is NOT reflected in these demands. They are at full price. '
                          . 'Do not collect against them until the concession has been applied.';
        }

        // Phase 3D (2026-05-09) — admission-time defaulter projection sync.
        // assignInitialFees creates feeDemands but does NOT emit the defaulter
        // event, so a never-paid student would have unpaid demands but no
        // feeDefaulters doc until first payment (verified leak: STU0004,
        // STU0005). updateDefaulterStatus reads feeDemands canonically and
        // writes the projection idempotently. Fail-soft: admission still
        // succeeds even if the projection write fails — the doc can be
        // recreated by a future payment event or by backfill_defaulters.js.
        try {
            $this->feeDefaulter->updateDefaulterStatus($userId);
        } catch (\Throwable $e) {
            log_message('error', "Phase 3D admission defaulter sync failed for {$userId}: " . $e->getMessage());
        }

        $this->_log_history($school_id, $userId, 'ADMISSION',
            "Student admitted to {$classOrd} / {$section} ({$session})",
            ['class' => $classOrd, 'section' => $section, 'session' => $session]
        );
        log_audit('SIS', 'admit_student', $userId, "Admitted student '{$name}' to {$classOrd} {$section}");

        // Lead conversion
        $leadId = trim($this->input->post('lead_id') ?? '');
        if ($leadId !== '' && preg_match('/^[A-Za-z0-9_]+$/', $leadId)) {
            $now = date('Y-m-d H:i:s');
            $lead = $this->fs->get('crmApplications', $this->fs->docId($leadId));
            if (is_array($lead)) {
                // M7-B: single CAS-atomic write (conversion fields + bounded history), concurrency-safe.
                $this->load->helper('admission_crm');
                $this->fs->casAppendBounded('crmApplications', $leadId, 'history',
                    ['action' => "Converted to student {$userId}", 'by' => $this->admin_name, 'timestamp' => $now],
                    crm_history_cap(),
                    ['status' => 'admitted', 'stage' => 'enrolled', 'student_id' => $userId, 'updated_at' => $now]);
            }
        }

        // G10 (2026-08-08) — collect (or record the audited waive of) the
        // admission fee, through the SAME Admission_collection receipt +
        // accounting path the online and CRM-counter pathways use. Runs after
        // lead conversion so a converted lead's crmApplications doc already
        // carries student_id when the receipt timeline is appended. Non-fatal:
        // the student is admitted at this point, so the outcome is reported
        // back rather than failing the request.
        $admissionFee = $this->_admission_fee_apply(
            $admissionFeePlan, $userId, $name, $father, $classOrd,
            ($leadId !== '' && preg_match('/^[A-Za-z0-9_]+$/', $leadId)) ? $leadId : ''
        );

        // Notify parent
        if ($phone !== '') {
            notify_admission_confirmed($phone, $this->school_display_name ?? $this->school_name, $userId, $name);
        }

        // PHASE 3B / B1 — the message must never claim more than the financial
        // state supports. "Admission successful" on a student with no demands,
        // or with a concession that was not applied, is the failure mode this
        // phase exists to remove.
        $message = $billingState === 'billed'
            ? 'Student admitted successfully.'
            : ($billingState === 'billing_pending'
                ? 'Student admitted — BILLING PENDING.'
                : 'Student admitted — BILLING FAILED.');
        if ($billingNote !== '') $message .= ' ' . $billingNote;
        if (($entitlementState['status'] ?? 'none') === 'applied') {
            $message .= ' Concession recorded and applied to the initial demands.';
        } elseif (($entitlementState['status'] ?? 'none') === 'recorded_not_priced') {
            // ADM-ENT-1: say what actually happened. Claiming "applied" here was
            // the whole defect — the operator had no way to know the invoice the
            // parent receives is at full price.
            $message .= ' Concession recorded, but NOT applied to these demands —'
                      . ' this school is not yet on entitlement-based billing.';
        } elseif (($entitlementState['status'] ?? 'none') === 'duplicate') {
            $message .= ' This concession was already on file — not added a second time.';
        }
        if (($admissionFee['status'] ?? '') === 'collected') {
            $message .= ' Admission fee ' . number_format((float) $admissionFee['amount'], 2)
                . ' collected — receipt ' . ($admissionFee['receipt_no'] ?? '') . '.';
        } elseif (($admissionFee['status'] ?? '') === 'already_collected') {
            $message .= ' Admission fee was already collected for this application — receipt '
                . ($admissionFee['receipt_no'] ?? '') . '. Not charged again.';
        } elseif (($admissionFee['status'] ?? '') === 'waived') {
            $message .= ' Admission fee waived.';
        } elseif (($admissionFee['status'] ?? '') === 'failed') {
            $message .= ' WARNING: the admission fee could not be collected — collect it from the'
                . ' student ledger before issuing a receipt.';
        }

        // Phase 9 correction pass — surface any SRNO/APAAR claim warnings
        // the SAME way this method already surfaces admission-fee status:
        // appended to $message. save_admission()'s response has no
        // separate `warnings` array (unlike enroll_student()), and its own
        // view (studentAdmission.php) only ever displays `res.message` —
        // adding an unused response key nothing reads would be dead
        // surface area, not a real fix.
        if (!empty($identityWarnings)) {
            $message .= ' ' . implode(' ', $identityWarnings);
        }

        // ── ONE CANONICAL TRANSPORT LIFECYCLE, BOTH ADMISSION PATHS ─────────
        //
        // Online admission already does this: public_form captures a transport
        // DECLARATION, and enroll_student() turns it into a pending
        // transportRequests row for the Transport → Requests queue.
        //
        // Direct staff admission did not, so a family admitted at the counter
        // never entered that lifecycle at all. The Services & Concessions panel
        // on this screen offered "Service enrolment (adds fee)", which wrote an
        // ACTIVE studentEntitlements kind='service' row that nothing reads — no
        // request, no queue entry, no route, no fee. Staff got a success
        // message for an outcome that never happened (SVC-ORPHAN-1).
        //
        // A DECLARATION IS NOT AN ASSIGNMENT. At this moment the school usually
        // has no route or stop for this child, and assignStudent() would raise a
        // transport FEE — so a bare declaration must never become an assignment,
        // or the family is billed for a service nobody has arranged. Route and
        // stop stay an operational decision made at approval, where the atomic
        // capacity reservation also lives.
        //
        // requestTransport() is idempotent per (student, session), so a
        // resubmitted admission cannot produce two requests.
        //
        // Best-effort BY DESIGN, exactly as enroll_student() does it: a
        // worklist entry must never fail an admission that has already created
        // the student and their Auth account.
        $transportRequest = null;
        try {
            if ((string) $this->input->post('decl_transport') === '1') {
                $this->load->library('numbering_service', null, 'trnNumbering');
                $this->trnNumbering->init($this->fs, $this->school_name, $this->session_year);
                $this->load->library('transport_service', null, 'trnSvc');
                $this->trnSvc->init($this->fs, $this->trnNumbering,
                    $this->school_name, $this->session_year, $this->admin_id ?? 'admission');
                $transportRequest = $this->trnSvc->requestTransport([
                    'studentId'      => $userId,
                    'student_name'   => (string) ($studentData['name'] ?? ''),
                    'student_class'  => (string) ($studentData['className'] ?? ''),
                    // MODEL A: a request is DESCRIPTIVE. No route_id/stop_id —
                    // management supplies the canonical pair at approval.
                    'type'           => (string) ($this->input->post('decl_transport_type') ?? 'both'),
                    'requested_area' => substr(trim((string) $this->input->post('decl_transport_area')), 0, 120),
                ], 'admission');
                $message .= ' Transport requested — it is now in Operations → Transport → Requests'
                          . ' for route and stop assignment. No transport fee has been raised yet.';
            }
        } catch (\Throwable $e) {
            log_message('error', "F14 direct-admission transport request failed for {$userId}: "
                . $e->getMessage());
        }

        // Verified relationships declared on this form. Every note is reported
        // to the operator — including the failures — because a relationship
        // that silently did not happen is the defect this replaced.
        foreach ($this->_captureAdmissionRelationships(
            $userId,
            (string) $this->input->post('decl_sibling_srno'),
            (string) $this->input->post('decl_staff_ward_id'),
            (string) $this->input->post('decl_rte_ref')
        ) as $note) {
            $message .= ' ' . $note;
        }

        // ── ADM-COMPLETE-1 (2026-09-19) — say what is still missing ──────────
        //
        // The form requires name, class, section, DOB and phone. Everything else is
        // genuinely optional AT THE COUNTER — but "complete it later" had no later:
        // no queue, no count, no reminder, so a thin record stayed thin until someone
        // happened to open it.
        //
        // This does not block anything. It names the gaps while the family is still
        // standing there, which is the only cheap moment to close them, and stores
        // them on the student so a roster view can count incomplete records later.
        // Uses the SAME definition the roster counts with (_profileGaps), fed the
        // document that was just written — so the desk and the roster can never
        // disagree about what "incomplete" means.
        $pendingFields = $this->_profileGaps($studentData);
        if ($pendingFields) {
            $message .= ' Still to complete: ' . implode(', ', $pendingFields) . '.';
            // Best-effort: a reporting aid must never fail an admission that has
            // already created the student, the Auth account and the demands.
            try {
                $this->fs->updateEntity('students', $userId, [
                    'pendingProfileFields' => $pendingFields,
                ]);
            } catch (\Throwable $e) {
                log_message('error', "ADM-COMPLETE-1 pending-field write failed for {$userId}: " . $e->getMessage());
            }
        }

        // An operator admitting over a duplicate warning is a decision worth keeping:
        // it is the audit answer to "why are there two of this child?".
        if (!empty($dupeConfirmed) && !empty($dupe)) {
            log_audit('SIS', 'admission_duplicate_override', $userId, sprintf(
                '%s was admitted as %s despite matching existing student %s (%s, %s %s) '
                . 'on name and mobile number. The operator confirmed they are different people.',
                $name, $userId, $dupe['name'], $dupe['studentId'], $dupe['class'], $dupe['section']
            ), [
                'newStudentId'      => $userId,
                'matchedStudentId'  => $dupe['studentId'],
                'matchedOn'         => 'name+phone',
                'phone'             => $phone,
            ]);
        }

        return $this->json_success([
            'message'       => $message,
            'user_id'       => $userId,
            'sr_no'         => $studentData['srNo'] ?? '',   // Phase 9.1 — server-allocated, for the credentials/identity panel
            'admission_no'  => $studentData['Admission Number'] ?? '',   // Phase 10.1 — admission-event identifier, YYYY/CLASS/XXX
            // Decision E — surface whether the (best-effort) admission number was
            // actually issued, so the operator can catch a missing one instead of
            // discovering it later. Mirrors the srno signal on the CRM path.
            'admno_assigned' => ($studentData['Admission Number'] ?? '') !== '',
            'name'          => $name,
            'password'      => $password,
            'class'         => str_replace('Class ', '', $classOrd),
            'section'       => str_replace('Section ', '', $section),
            'admission_fee' => $admissionFee,
            // ADM-COMPLETE-1 — rendered as a follow-up list, not an error. The
            // student IS admitted; these are the fields worth catching now.
            'pending_fields' => $pendingFields,
            // ADM-AGE-1 — empty unless the school runs a warn-mode age policy and
            // this admission fell outside the band.
            'age_warning'    => $ageWarning,
            // PHASE 3B / B1 — explicit financial state. The UI renders this
            // rather than inferring success from the HTTP status, so an
            // operator can never be told "successful" while billing is
            // incomplete. billed | billing_pending | billing_failed.
            'billing_state' => $billingState,
            'billing_note'  => $billingNote,
            'entitlement'   => $entitlementState,
        ]);
    }

    /**
     * ── PHASE 3B / B1 — resolve an entitlement submitted WITH the admission ──
     *
     * Runs after the student and their identity exist, and BEFORE
     * assignInitialFees() prices the demands, so a concession agreed at the
     * admission desk is priced into the first invoice rather than discovered
     * afterwards.
     *
     * Returns a state the caller folds into the admission's billing state:
     *   none      — no entitlement was submitted (the overwhelming common case)
     *   applied   — recorded and ACTIVE, so pricing below will see it
     *   pending   — recorded but awaiting approval; pricing will NOT see it yet
     *   duplicate — identical entitlement already on file (a retry); not re-added
     *   failed    — could not be recorded; the caller downgrades the billing state
     *
     * Deliberately NON-FATAL. The student already exists at this point, so
     * aborting would leave a half-made admission. The failure is surfaced
     * through the billing state instead, which is the honest signal.
     *
     * Approval policy is NOT re-implemented here: it defers to the same
     * has_permission('Fees','manage') rule Entitlements::_approval_required()
     * applies, so the desk and the API cannot diverge.
     */
    private function _resolve_admission_entitlement(string $studentId, string $schoolId): array
    {
        $kind = trim((string) $this->input->post('ent_kind'));
        if ($kind === '') return ['status' => 'none'];

        try {
            $this->load->library('Entitlement_service', null, 'entitlementSvc');
            // ── TENANT IDENTITY BOUNDARY (2026-08-18) ────────────────────────
            //
            // CANONICAL `school_id`, NOT the `$schoolId` argument.
            //
            // save_admission() sets `$school_id = $this->parent_db_key`, and
            // `parent_db_key = school_code ?: school_id` — on this tenant the
            // login code is "10012" while the canonical id is
            // "SCH_B56BB9A401". Passing the legacy key made
            // Entitlement_service ask Fee_head_resolver for
            // `10012_FH_DF3C200439E2`, a document that cannot exist: the Fee
            // Head Registry is keyed by canonical school_id
            // (`SCH_B56BB9A401_FH_DF3C200439E2`). Every head-scoped admission
            // entitlement therefore failed closed with "fee head not
            // registered", and the student was admitted BILLING PENDING.
            //
            // The rule this restores: a store keyed by canonical school_id must
            // be addressed with canonical school_id. `parent_db_key` stays
            // correct for the legacy/RTDB-shaped paths that own that key space
            // — including SRNO, whose `srNoIndex` rows are self-consistently
            // stored under the login code and would be ORPHANED by a blanket
            // rename. This is deliberately the narrowest possible change: one
            // argument, one boundary. See DEC-008.
            $this->entitlementSvc->init(
                $this->firebase, $this->fs, (string) $this->school_id, $this->session_year,
                (string) ($this->admin_id ?? 'system')
            );

            $concessionType = trim((string) ($this->input->post('ent_concession_type') ?? 'other'));
            // Same maker-checker rule as the standalone endpoint.
            $approvalRequired = ($kind === 'concession') && !has_permission('Fees', 'manage');

            $res = $this->entitlementSvc->propose([
                'studentId'            => $studentId,
                'kind'                 => $kind,
                'serviceType'          => trim((string) ($this->input->post('ent_service_type') ?? '')),
                'concessionType'       => $concessionType,
                'scope'                => trim((string) $this->input->post('ent_scope')),
                'valueType'            => trim((string) $this->input->post('ent_value_type')),
                'value'                => $this->input->post('ent_value'),
                // No `frequency` — retired at the P2 contract freeze (I1). The
                // server side of the same obsolete dependency the admission
                // bridge dropped in REG-P6-R1; the service already ignored it.
                'effectiveFrom'        => trim((string) $this->input->post('ent_effective_from')),
                'effectiveTo'          => trim((string) ($this->input->post('ent_effective_to') ?? '')),
                'reason'               => trim((string) ($this->input->post('ent_reason') ?? '')),
                'govtReceivableAmount' => $this->input->post('ent_govt_receivable_amount'),
                'source'               => 'direct_admission',
                'approvalRequired'     => $approvalRequired,
            ]);

            if (empty($res['ok'])) {
                log_message('error', "ACC_ADMISSION_ENTITLEMENT_FAILED student=[{$studentId}]: "
                    . (string) ($res['error'] ?? 'unknown'));
                return ['status' => 'failed', 'error' => (string) ($res['error'] ?? 'Could not record the concession.')];
            }

            if (!empty($res['duplicate'])) {
                return ['status' => 'duplicate', 'id' => (string) $res['id'], 'entitlement_status' => (string) $res['status']];
            }
            // ── ADM-ENT-1 · "RECORDED" IS NOT "APPLIED" ─────────────────────
            // Entitlement_service writes to `studentEntitlements` (canonical)
            // and is NOT gated by USE_ENTITLEMENT_BILLING. But pricing chooses
            // its reader FROM that flag (Fee_lifecycle ~L748): canonical when
            // true, `studentConcessions` when false.
            //
            // So on a tenant that has not been granted the canonical authority,
            // an admission-desk concession lands in a store the pricing engine
            // does not read. assignInitialFees() then prices at full rate, and
            // reporting this as 'applied' would tell the operator
            // "Concession recorded and applied to the initial demands" — which
            // would be false.
            //
            // That is precisely the failure this method's own header describes
            // as fixed: "The operator saw 'Admission successful', the parent saw
            // a full-price invoice, and nothing anywhere reported a problem."
            // The ORDERING was fixed; the store mismatch reproduces the same
            // symptom, so it is reported truthfully instead.
            //
            // Deliberately NOT resolved by making pricing read both stores: the
            // architecture enforces ONE authority ("NEVER BOTH"), and a fallback
            // would resurrect the split-authority hazard that design exists to
            // prevent. The real resolution is the per-tenant grant, which is
            // rollout — not something to fake here.
            $__canonicalPricing = false;
            try {
                $__canonicalPricing = (bool) $this->config->item(
                    'USE_ENTITLEMENT_BILLING', 'fees_exemption_v2_flags'
                );
            } catch (\Throwable $_) {
                $__canonicalPricing = false;   // unknown ⇒ assume not priced
            }

            $__isActive = ((string) $res['status'] === 'active');

            if ($__isActive && !$__canonicalPricing) {
                log_message('error',
                    'ACC_ADMISSION_ENTITLEMENT_NOT_PRICED student=[' . $studentId . '] — recorded in '
                    . 'studentEntitlements but this tenant prices from studentConcessions '
                    . '(USE_ENTITLEMENT_BILLING=false), so the initial demands are FULL PRICE.');
                return [
                    'status'             => 'recorded_not_priced',
                    'id'                 => (string) $res['id'],
                    'entitlement_status' => (string) $res['status'],
                ];
            }

            return [
                'status'             => $__isActive ? 'applied' : 'pending',
                'id'                 => (string) $res['id'],
                'entitlement_status' => (string) $res['status'],
            ];
        } catch (\Throwable $e) {
            log_message('error', "ACC_ADMISSION_ENTITLEMENT_EXCEPTION student=[{$studentId}]: " . $e->getMessage());
            return ['status' => 'failed', 'error' => 'The concession could not be recorded.'];
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       PROFILE
    ══════════════════════════════════════════════════════════════════════ */

    public function profile($userId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_profile', 'SIS', 'view');
        if (empty($userId) || !$this->safe_path_segment($userId)) show_404();

        // Delegate to the comprehensive student_profile view
        $this->student_profile($userId);
    }

    public function update_profile()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_update_profile', 'SIS', 'edit');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id = $this->parent_db_key;
        $userId    = trim($this->input->post('user_id'));

        if (empty($userId)) return $this->json_error('User ID required.');
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');

        // Field names must exactly match those written by Student.php
        $allowed = [
            'Name', 'Father Name', 'Mother Name', 'Father Occupation', 'Mother Occupation',
            'Guard Contact', 'Guard Relation',
            'DOB', 'Gender', 'Blood Group', 'Category', 'Religion', 'Nationality',
            'Phone Number',   // existing field — NOT "Phone"
            'Email',
            'Roll No', 'Pre School', 'Pre Class', 'Pre Marks',
        ];

        $updates = [];
        foreach ($allowed as $field) {
            $val = $this->input->post($field);
            if ($val !== null) {
                $updates[$field] = trim($val);
            }
        }

        // Phone aliases: the admission form posts "Phone Number" (Title Case),
        // but other edit screens / Android push "phone" or "phoneNumber".
        // Accept any of them and normalize to the canonical "Phone Number" key
        // (which then maps to Firestore camelCase `phone` below).
        if (!isset($updates['Phone Number'])) {
            foreach (['phone', 'phoneNumber', 'phone_number'] as $alias) {
                $v = $this->input->post($alias);
                if ($v !== null && trim($v) !== '') {
                    $updates['Phone Number'] = trim($v);
                    break;
                }
            }
        }

        // M-07 FIX: Validate email format on profile update
        if (isset($updates['Email']) && $updates['Email'] !== '' && !filter_var($updates['Email'], FILTER_VALIDATE_EMAIL)) {
            return $this->json_error('Invalid email address format.');
        }

        // ── ADM-INPUT-1 · PHONE, ON THE EDIT PATH TOO (2026-09-19) ───────────
        //
        // Hardening admission alone was half a fix. Across a school year far more
        // phone numbers are CHANGED than created — a new SIM, a father's number
        // replaced by a mother's, a digit fat-fingered during a correction — so this
        // is where clean data actually rots.
        //
        // It is not merely a contact field. It is written to indexPhones, which
        // resolves WHICH CHILDREN belong to a number; sibling detection, duplicate
        // detection, fee reminders, transport alerts and the Parent app all read it.
        // A malformed edit does not error — the write succeeds and the index quietly
        // points at the wrong key, so a parent who could sign in yesterday cannot
        // today and nothing says why.
        //
        // Safe to tighten: a fleet-wide read-only survey (scripts/phone_format_survey.js)
        // found 154 students — 119 already strict, 0 needing normalisation, and ONE
        // genuinely bad row. This normalises what a human might reasonably type and
        // refuses only what cannot be a mobile number.
        if (isset($updates['Phone Number']) && trim((string) $updates['Phone Number']) !== '') {
            $__p10 = $this->_normalisePhone10((string) $updates['Phone Number']);
            if ($__p10 === '') {
                // GRANDFATHER an UNCHANGED legacy value. The survey found one stored
                // number that cannot be normalised; refusing it outright would make
                // that profile unsaveable — the operator could not correct the child's
                // address because of an unrelated bad phone, which is how a validation
                // rule earns a reputation for being in the way.
                //
                // A NEW bad value is still refused. Only re-submitting what is already
                // on the document passes, and _profileGaps() reports it as missing, so
                // it stays visible on the roster rather than being quietly blessed.
                $__existing = $this->_getStudent($userId);
                $__stored   = is_array($__existing)
                    ? trim((string) ($__existing['Phone Number'] ?? $__existing['phone'] ?? ''))
                    : '';
                if ($__stored === '' || $__stored !== trim((string) $updates['Phone Number'])) {
                    return $this->json_error(
                        'Enter a valid 10-digit mobile number (it may start with +91). '
                        . 'This number is how the Parent app finds this student, so a '
                        . 'malformed one would silently lock the family out.'
                    );
                }
                // Unchanged and already bad: let the rest of the edit through, but do
                // not pretend it is usable.
                unset($updates['Phone Number']);
                $__p10 = '';
            }
            if ($__p10 !== '') {
                // Store the canonical form, not what was typed, so indexPhones and
                // every lookup that joins on it agree.
                $updates['Phone Number'] = $__p10;
            }
        }

        // Pre-update snapshot — fetched lazily and reused by both the
        // Address-merge branch (Step 4) AND the audit-log diff capture
        // below. Captured BEFORE the Firestore write so the before-state
        // is available for the timeline UI's old → new rendering.
        $beforeStudent = null;

        // Address is a nested object — posted as Address[Street],
        // Address[City], etc. Partial updates are common (parent only
        // wants to change the City), so we MUST merge on top of the
        // current doc rather than overwrite — otherwise unposted
        // sub-fields go blank.
        //
        // Tier-A Step 4: previously, when `_getStudent` returned null
        // (transient Firestore read failure / permission flake), the
        // code defaulted `$existing = []` and the merge produced a
        // patch containing only the posted sub-fields — silently
        // wiping every unposted sub-field on the doc. We now refuse
        // to write a partial address whenever the existing address
        // can't be confirmed, returning an explicit error so the
        // caller can retry instead of losing data.
        $addrPost = $this->input->post('Address');
        if (is_array($addrPost)) {
            $beforeStudent = $this->_getStudent($userId);
            if ($beforeStudent === null) {
                return $this->json_error(
                    'Could not load current address — please refresh and try again. ' .
                    'No fields were changed.'
                );
            }
            $existing = $beforeStudent['Address'] ?? $beforeStudent['address'] ?? [];
            $existing = is_array($existing) ? $existing : [];

            // Per-sub-field merge: only overwrite a sub-field when the
            // POST explicitly carried it. Empty-string values DO clear
            // the field (intentional — the form's "clear" UX is to
            // submit an empty input). Sub-fields absent from the POST
            // keep their current value, period.
            $merged = $existing;
            foreach (['Street', 'City', 'State', 'PostalCode'] as $sub) {
                if (array_key_exists($sub, $addrPost)) {
                    $merged[$sub] = trim((string) $addrPost[$sub]);
                }
            }
            $updates['Address'] = $merged;
        }

        if (empty($updates)) {
            return $this->json_error('No valid fields to update.');
        }

        // RTDB mirror removed per no-RTDB policy. Firestore `students` is the sole source.
        $updates['updatedAt'] = date('c');

        // ── Firestore with camelCase mapping ──────────
        // The doc gets both PascalCase (legacy admin readers) and
        // camelCase (mobile apps' StudentDoc). Mirroring Step-3's
        // edit_student coverage so a profile edit lands every field
        // the parent / teacher app reads, not just Name + Phone +
        // Email + parents. Address (nested map) is mirrored to
        // canonical `address` so the mobile profile screen sees
        // changes immediately, not only after the trailing
        // syncStudent call merges canonical keys in.
        $fsUpdates = $updates;
        if (isset($fsUpdates['Name']))              $fsUpdates['name']             = $fsUpdates['Name'];
        if (isset($fsUpdates['Phone Number'])) {
            // Mirror Entity_firestore_sync::syncStudent, which writes BOTH
            // `phone` (Android canonical) and `phoneNumber` (backward compat).
            $fsUpdates['phone']       = $fsUpdates['Phone Number'];
            $fsUpdates['phoneNumber'] = $fsUpdates['Phone Number'];
        }
        if (isset($fsUpdates['Email']))             $fsUpdates['email']            = $fsUpdates['Email'];
        if (isset($fsUpdates['DOB']))               $fsUpdates['dob']              = $fsUpdates['DOB'];
        if (isset($fsUpdates['Gender']))            $fsUpdates['gender']           = $fsUpdates['Gender'];
        if (isset($fsUpdates['Category']))          $fsUpdates['category']         = $fsUpdates['Category'];
        if (isset($fsUpdates['Blood Group']))       $fsUpdates['bloodGroup']       = $fsUpdates['Blood Group'];
        if (isset($fsUpdates['Religion']))          $fsUpdates['religion']         = $fsUpdates['Religion'];
        if (isset($fsUpdates['Nationality']))       $fsUpdates['nationality']      = $fsUpdates['Nationality'];
        if (isset($fsUpdates['Roll No']))           $fsUpdates['rollNo']           = $fsUpdates['Roll No'];
        if (isset($fsUpdates['Father Name']))       $fsUpdates['fatherName']       = $fsUpdates['Father Name'];
        if (isset($fsUpdates['Father Occupation'])) $fsUpdates['fatherOccupation'] = $fsUpdates['Father Occupation'];
        if (isset($fsUpdates['Mother Name']))       $fsUpdates['motherName']       = $fsUpdates['Mother Name'];
        if (isset($fsUpdates['Mother Occupation'])) $fsUpdates['motherOccupation'] = $fsUpdates['Mother Occupation'];
        if (isset($fsUpdates['Guard Contact']))     $fsUpdates['guardContact']     = $fsUpdates['Guard Contact'];
        if (isset($fsUpdates['Guard Relation']))    $fsUpdates['guardRelation']    = $fsUpdates['Guard Relation'];
        if (isset($fsUpdates['Pre Class']))         $fsUpdates['preClass']         = $fsUpdates['Pre Class'];
        if (isset($fsUpdates['Pre School']))        $fsUpdates['preSchool']        = $fsUpdates['Pre School'];
        if (isset($fsUpdates['Pre Marks']))         $fsUpdates['preMarks']         = $fsUpdates['Pre Marks'];
        if (isset($fsUpdates['Address']))           $fsUpdates['address']          = $fsUpdates['Address'];

        // Audit diff — captured BEFORE the write so the timeline UI can
        // show the parent / admin exactly what changed. Lazy fetch:
        // skipped if the Address branch already loaded the doc.
        if ($beforeStudent === null) {
            $beforeStudent = $this->_getStudent($userId);
        }
        $auditDiff = [];
        if (is_array($beforeStudent)) {
            foreach ($updates as $auditKey => $newVal) {
                if ($auditKey === 'updatedAt') continue;
                $oldVal = $beforeStudent[$auditKey] ?? null;
                if ($oldVal === $newVal) continue;
                $auditDiff[$auditKey] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        $this->fs->updateEntity('students', $userId, $fsUpdates);

        // ── FIX 4c: Update Firebase Auth displayName if name changed ──
        if (isset($updates['Name'])) {
            try {
                $this->firebase->updateFirebaseUser($userId, ['displayName' => $updates['Name']]);
            } catch (\Exception $e) {
                log_message('error', "update_profile: Firebase Auth update failed for {$userId}: " . $e->getMessage());
            }
        }

        $changed = implode(', ', array_keys($updates));
        // Pass the diff into metadata so the timeline UI can render
        // a clean old → new table per changed field. `fields` is
        // retained for back-compat with the existing collapsible
        // JSON view in history.php.
        $this->_log_history($school_id, $userId, 'PROFILE_UPDATE',
            "Profile updated: {$changed}",
            ['fields' => array_keys($updates), 'changes' => $auditDiff]
        );

        // Entity sync for Android apps
        try {
            // SIS Wave-2 S6 (2026-05-31): observability for sync return.
            if (!$this->entity_sync->syncStudent($userId, $updates)) {
                log_message('warning', "syncStudent returned false for {$userId} (update_profile)");
            }
        } catch (\Exception $e) {
            log_message('error', "entity_sync syncStudent failed for {$userId}: " . $e->getMessage());
        }

        log_audit('SIS', 'update_profile', $userId, "Updated student profile: {$changed}");

        return $this->json_success(['message' => 'Profile updated successfully.']);
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT PROMOTION
    ══════════════════════════════════════════════════════════════════════ */

    public function promote()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_promote', 'SIS', 'manage');
        $session     = $this->session_year;

        $data['class_map']         = $this->_fs_class_map();
        $data['session_class_map'] = $this->_fs_session_class_map();
        $data['session_year']      = $session;

        // Build session options. Issue B (2026-05-28): list ONLY sessions that
        // actually exist. Previously the computed next academic year was
        // silently appended to the list as if it were a real session, which
        // confused operators into promoting into a non-existent (and
        // fee-structure-less) session. The next year is now offered separately
        // as an explicit, clearly-labeled "create new" choice the view renders
        // distinctly; selecting it still auto-registers the session on submit
        // via the existing BUG-056 SW1 path in execute_promotion.
        $available = $this->session->userdata('available_sessions') ?? [];
        rsort($available);
        $parts     = explode('-', $session);
        $nextYear  = ((int)$parts[0] + 1) . '-' . substr((string)((int)$parts[0] + 2), -2);
        $data['session_options'] = $available;                                       // existing only
        $data['create_session']  = in_array($nextYear, $available, true) ? '' : $nextYear; // '' if it already exists
        $data['next_session']    = $nextYear;                                        // label the next academic year "(next)" when it already exists

        $this->load->view('include/header');
        $this->load->view('sis/promote', $data);
        $this->load->view('include/footer');
    }

    public function promote_preview()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_promote_preview', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        $fromClass   = trim($this->input->post('from_class'));   // "9th"
        $fromSection = trim($this->input->post('from_section')); // "A" or "all"

        if (empty($fromClass)) return $this->json_error('Source class is required.');
        if (!$this->safe_path_segment($fromClass)) return $this->json_error('Invalid class value.');
        if ($fromSection && $fromSection !== 'all' && !$this->safe_path_segment($fromSection)) {
            return $this->json_error('Invalid section value.');
        }

        $students = $this->_get_students_in_class($fromClass, $fromSection, $session);

        return $this->json_success([
            'message'      => 'Preview ready.',
            'students'     => array_values($students),
            'count'        => count($students),
            'from_class'   => $fromClass,
            'from_section' => $fromSection,
        ]);
    }

    public function execute_promotion()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_execute_promotion', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        $fromClass   = trim($this->input->post('from_class'));
        $fromSection = trim($this->input->post('from_section'));
        $toClass     = trim($this->input->post('to_class'));
        $toSection   = trim($this->input->post('to_section'));
        $toSession   = trim($this->input->post('to_session') ?? '') ?: $session;

        if (empty($fromClass) || empty($toClass) || empty($toSection)) {
            return $this->json_error('Source class, destination class, and section are required.');
        }
        if (!$this->safe_path_segment($fromClass)) return $this->json_error('Invalid source class.');
        if ($fromSection && $fromSection !== 'all' && !$this->safe_path_segment($fromSection)) {
            return $this->json_error('Invalid source section.');
        }
        if (!$this->safe_path_segment($toClass))   return $this->json_error('Invalid destination class.');
        if (!$this->safe_path_segment($toSection))  return $this->json_error('Invalid destination section.');

        // SC-Step9 Part A (Session Convergence — 2026-06-02): fail-loud
        // validation. Pre-Step-9 silently fell back to $session on malformed
        // to_session, masking input bugs. Per NEW-Q12 fail-closed mandate,
        // reject malformed input with HTTP 400 so callers can correct it.
        // The empty-string default to current session (L870) still applies
        // BEFORE this check — UI sending empty to_session for same-session
        // promotion is unaffected.
        if (!preg_match('/^\d{4}-\d{2}$/', $toSession)) {
            return $this->json_error(
                "Invalid target session format. Expected YYYY-YY (e.g. 2026-27), got: "
                . substr($toSession, 0, 32),
                400
            );
        }

        // SC-Step9 Part B (Session Convergence — 2026-06-02): route
        // session-add through canonical Session_lifecycle library per
        // operator-locked EX-C(b) decision. The library applies the SAME
        // lock + Firestore __updateTime CAS that School_config's canonical
        // lifecycle writers use, with cross-controller serialization
        // preserved via the shared lock-file path. Pre-Step-9 inline
        // fs->update at L902 was the LAST unhardened session writer in
        // the codebase; Step 9 closes it.
        //
        // BUG-056-SW1 invariant PRESERVED: promotion writes ONLY sessions[],
        // never currentSession. Active-session changes route exclusively
        // through School_config::set_active_session. The library's
        // add_session() method has no path to currentSession.
        $available = $this->session->userdata('available_sessions') ?? [];
        if (!in_array($toSession, $available, true)) {
            $this->load->library('Session_lifecycle', null, 'session_lifecycle');
            $libResult = $this->session_lifecycle->add_session(
                $this->school_id,
                $toSession,
                (string) $this->admin_id,
                'promotion'
            );
            if (!$libResult['success']) {
                log_message('error',
                    'SC-Step9: Session_lifecycle::add_session failed for promotion '
                    . 'target=[' . $toSession . '] school=[' . $this->school_id
                    . '] error=[' . ($libResult['error'] ?? 'unknown')
                    . '] — aborting promotion to prevent orphan state.');
                return $this->json_error(
                    'Could not register the target session in school configuration. '
                    . 'Promotion has been aborted to prevent partial state. Please retry.');
            }
            // Refresh PHP userdata cache from the post-write canonical sessions[]
            // returned by the library (includes the new session, sorted).
            $this->session->set_userdata('available_sessions', $libResult['sessions']);
        }

        $students = $this->_get_students_in_class($fromClass, $fromSection, $session);
        if (empty($students)) {
            return $this->json_error('No students found in the selected class/section.');
        }

        // ── PM-SELECT 2026-05-26: per-student selection filter ──
        $rawSelectedIds = $this->input->post('student_ids');
        if (is_array($rawSelectedIds) && !empty($rawSelectedIds)) {
            $cleanIds = [];
            foreach ($rawSelectedIds as $rid) {
                $rid = trim((string) $rid);
                if ($rid === '') continue;
                if (!preg_match('/^[A-Za-z0-9_]+$/', $rid)) continue;
                $cleanIds[$rid] = true;
            }
            if (!empty($cleanIds)) {
                $students = array_intersect_key($students, $cleanIds);
            }
            if (empty($students)) {
                return $this->json_error(
                    'None of the selected students were found in the source class. '
                    . 'Refresh the preview and try again.'
                );
            }
        }

        // ── Phase 16.5 (SEC-4 / SEC-7 / SEC-8) — CANONICAL CAPACITY ─────
        //
        // This block did its own capacity arithmetic and got three things
        // wrong:
        //
        //  1. WRONG SESSION FOR THE CAPACITY DOCUMENT. `sectionDocId($toClass,
        //     $toSection)` defaults to $this->session — the session the
        //     operator is currently viewing — but a promotion targets
        //     $toSession. It therefore read LAST year's section document to
        //     decide NEXT year's capacity (SEC-7).
        //
        //  2. WRONG SESSION FOR OCCUPANCY. schoolWhere() is school-scoped but
        //     NOT session-scoped, so it counted Active students in that
        //     class/section across EVERY session. Promoting 5th → 6th counted
        //     the CURRENT 6th-graders as already occupying next year's 6-A,
        //     over-counting the destination and wrongly BLOCKING legitimate
        //     promotions (SEC-8).
        //
        //  3. Unset capacity meant UNLIMITED here but 40 in admission/import/
        //     CRM (SEC-4). Operator decision 2026-08-15: configured positive
        //     value wins; missing/zero/invalid means 40, everywhere.
        //
        // The canonical validator fixes all three and reads the shared
        // memoised student snapshot, so this REMOVES a Firestore query.
        // $toSession is passed explicitly — that is the whole point of (1).
        $newClassKey   = Firestore_service::classKey($toClass);
        $newSectionKey = Firestore_service::sectionKey($toSection);
        // Version BEFORE the occupancy it protects, and read uncached — the same
        // ordering admission, reactivation, enrolment and import already use. A
        // version taken after the count cannot witness a placement that landed
        // during the count, so the CAS would prove nothing about the decision.
        require_once APPPATH . 'libraries/Section_seat_guard.php';
        $promoVersion = $this->_sectionDecisionVersion($newClassKey, $newSectionKey, $toSession);
        $capOver = $this->_sectionCapacityExceeded(
            $newClassKey, $newSectionKey, $toSession, count($students), '', /* fresh */ true
        );
        if ($capOver !== null) {
            return $this->json_error(
                $this->_sectionCapacityMessage($capOver, $newClassKey, $newSectionKey)
            );
        }

        // ── Phase 14.1 — Roll Number collisions in the DESTINATION section ───
        //
        // THE DEFECT THIS CLOSES: `batchMoveStudents()` writes className,
        // section, sectionKey, status and session — and deliberately NOT
        // rollNo. Each promoted student therefore CARRIES THEIR OLD ROLL
        // FORWARD. Promote 5-A (Rahul, Roll 18) and 5-B (Priya, Roll 18) into
        // 6-A and the destination silently ends up with two Roll 18s. Nothing
        // in the system objected, because every module keys on studentId.
        //
        // Blocked all-or-nothing BEFORE any student moves, mirroring the
        // capacity and fee-structure guards immediately above/below — the
        // established shape for this endpoint. Deliberately NOT auto-renumbered
        // and NOT silently blanked: choosing who keeps Roll 18 is a business
        // decision for the operator, and inventing one here would be exactly
        // the "silent conflict resolution" this phase forbids.
        //
        // Two collision sources are considered:
        //   (a) students ALREADY in the destination (e.g. a partial earlier
        //       promotion, or a section that is not empty), and
        //   (b) two students WITHIN this promotion batch holding the same roll
        //       — the merge-two-sections case, which no per-student check on
        //       its own would catch.
        //
        // Cost: ZERO extra reads on the (a) path — it reuses the
        // `$existingStudents` list the capacity guard already fetched when a
        // maxStrength is configured, and otherwise falls back to the shared
        // write-invalidated snapshot. (b) is pure in-memory.
        $rollOwners = [];       // lowercased roll => owner label, in destination
        try {
            $destRows = $existingStudents ?? null;
            if (!is_array($destRows)) {
                $destRows = $this->fs->schoolWhere('students', [
                    ['className', '==', $newClassKey], ['section', '==', $newSectionKey], ['status', '==', 'Active'],
                ]);
            }
            foreach ((array) $destRows as $dr) {
                $dd = is_array($dr) ? ($dr['data'] ?? $dr) : [];
                if (!is_array($dd)) continue;
                $r = mb_strtolower(trim((string) ($dd['rollNo'] ?? $dd['Roll No'] ?? '')));
                if ($r === '') continue;
                $rollOwners[$r] = (string) ($dd['name'] ?? $dd['Name'] ?? $dd['studentId'] ?? 'an existing student');
            }
        } catch (\Throwable $e) {
            log_message('error', 'promotion roll pre-check (destination) failed: ' . $e->getMessage());
        }

        $rollClashes = [];
        foreach ($students as $__uid => $__info) {
            $sInfo = is_array($__info) ? ($__info['data'] ?? $__info) : [];
            if (!is_array($sInfo)) continue;
            $sRoll = trim((string) ($sInfo['rollNo'] ?? $sInfo['Roll No'] ?? ''));
            if ($sRoll === '') continue;                     // blank carries no claim
            $sName = (string) ($sInfo['name'] ?? $sInfo['Name'] ?? $__uid);
            $k     = mb_strtolower($sRoll);
            if (isset($rollOwners[$k])) {
                $rollClashes[] = "Roll {$sRoll} ({$sName} vs {$rollOwners[$k]})";
            } else {
                $rollOwners[$k] = $sName;                    // now claimed by this batch
            }
        }

        if (!empty($rollClashes)) {
            $shown = array_slice($rollClashes, 0, 5);
            $more  = count($rollClashes) - count($shown);
            return $this->json_error(
                "Cannot promote: " . count($rollClashes) . " Roll Number conflict(s) in "
                . "{$newClassKey}/{$newSectionKey} — " . implode('; ', $shown)
                . ($more > 0 ? " and {$more} more" : '')
                . ". Resolve the Roll Numbers before promoting; no student was moved."
            );
        }

        // BUG-076 Part 2-A (2026-05-28): destination fee-structure guard.
        // A promotion regenerates the destination class's demands via
        // assignInitialFees, which reads
        // feeStructures/{schoolId}_{toSession}_{class}_{section}. If that
        // structure is absent, regeneration silently produces nothing and the
        // promoted students land Active-with-zero-demands (the STU0004-11
        // incident root cause). Validate BEFORE moving any student so the
        // promotion is all-or-nothing. Operator decision 2026-05-28: BLOCK if
        // missing. ($school_name == school_id == SCH_xxx; classKey/sectionKey
        // are already applied to $newClassKey/$newSectionKey — identical to
        // the key assignInitialFees will read.)
        // Shared implementation — see _fee_structure_missing() above.
        $feeStructErr = $this->_fee_structure_missing(
            $newClassKey,
            $newSectionKey,
            $toSession,
            'Promotion'
        );
        if ($feeStructErr !== '') {
            // All-or-nothing: aborts before moving any student.
            return $this->json_error($feeStructErr);
        }

        $adminName     = $this->session->userdata('admin_name') ?? 'Admin';
        $promoted      = [];
        $now           = date('Y-m-d H:i:s');
        $batchId       = date('YmdHis');
        $oldClassKey   = Firestore_service::classKey($fromClass);
        $oldSectionKey = Firestore_service::sectionKey($fromSection);
        $historyDesc   = "Promoted from {$oldClassKey}/{$oldSectionKey} to {$newClassKey}/{$newSectionKey} ({$toSession})";
        $historyMeta   = [
            'from_class' => $oldClassKey, 'from_section' => $oldSectionKey,
            'to_class' => $newClassKey, 'to_section' => $newSectionKey, 'to_session' => $toSession,
        ];

        // Build batch map: [ userId => ['name'=>..., 'oldSection'=>...] ]
        $batchMap = [];
        foreach ($students as $userId => $studentInfo) {
            $stuOldSection = ($fromSection === 'all')
                ? Firestore_service::sectionKey($studentInfo['section'] ?? '')
                : $oldSectionKey;
            $batchMap[$userId] = [
                'name'       => $studentInfo['name'] ?? $userId,
                'oldSection' => $stuOldSection,
            ];
        }

        // Firestore-only bulk promotion (RTDB retired) — per-student syncStudent
        // with inline retry + async retry-queue; returns moved[]/failed[].
        // ── BOUNDED RE-DECIDE, matching every other protected workflow ──────
        // A CONFLICT means a competing placement committed first: this promotion's
        // capacity decision is stale, not wrong. Re-decide against the destination
        // as it is NOW — version first, then a fresh occupancy read — and retry.
        // Only CONFLICT is retryable; a FAILURE outcome is unknown and re-issuing
        // it is how duplicate placements are made.
        $moveResult = ['moved' => [], 'failed' => []];
        for ($__pa = 1; $__pa <= self::SEAT_RETRY_ATTEMPTS; $__pa++) {
            if ($__pa > 1) {
                $promoVersion = $this->_sectionDecisionVersion($newClassKey, $newSectionKey, $toSession);
                $__cap = $this->_sectionCapacity($newClassKey, $newSectionKey, $toSession);
                if ($__cap > 0) {
                    $__now = $this->_sectionActiveCountFresh($newClassKey, $newSectionKey, $toSession);
                    if ($__now + count($students) > $__cap) {
                        return $this->json_error($this->_sectionCapacityMessage(
                            ['capacity' => $__cap, 'current' => $__now, 'incoming' => count($students),
                             'projected' => $__now + count($students),
                             'over' => ($__now + count($students)) - $__cap],
                            $newClassKey, $newSectionKey));
                    }
                }
            }
            $moveResult = $this->dw->batchMoveStudents(
                $batchMap, $oldClassKey, $session,
                $newClassKey, $newSectionKey, $toSession, $promoVersion
            );
            if (!empty($moveResult['moved'])) break;
            if (($moveResult['guardStatus'] ?? '') !== Section_seat_guard::CONFLICT) break;
        }

        // ── Phase 15.5 (L-1): INVALIDATE THE SHARED STUDENT SNAPSHOT ────
        //
        // THE DEFECT THIS CLOSES: batchMoveStudents writes each student via
        // Entity_firestore_sync::syncStudent() → _write() → raw
        // firebase->firestoreSet(). That bypasses Firestore_service entirely,
        // so Firestore_service::_bustDashboard() — the hook that normally
        // drops these cache keys on any students write — NEVER FIRES for a
        // promotion. Admission, import, deletion and transfers all route
        // through fs->update/remove and are unaffected; promotion was the one
        // student-lifecycle flow that left the snapshot stale (90 s TTL, see
        // MY_Controller::_cachedStudentDocsForRollCheck).
        //
        // WHY IT MATTERED: the roll-uniqueness check and the section-capacity
        // check both read that snapshot. For 90 s after a promotion the
        // promoted students were still filed under their OLD class/section,
        // so a roll number already taken in the DESTINATION class read as
        // free — a hole in the Phase 14.1 guarantee that opened at exactly
        // the moment operators sit down to assign new-session roll numbers.
        //
        // Fixed at the CALLER, deliberately: rerouting _write() through
        // Firestore_service would change the write path of every entity sync
        // in the ERP (staff, sections, routes, vehicles…) to fix one flow.
        // Cache-only, no Firestore I/O — cost is strictly negative latency.
        try {
            $this->load->driver('cache', ['adapter' => 'file']);
            $this->cache->delete($this->fs->listCacheKey('students'));
            $this->cache->delete($this->fs->listCacheKey('students_raw'));
        } catch (\Throwable $e) {
            // Never fail a completed promotion over a cache delete; worst case
            // is the pre-existing 90 s staleness this fix removes.
            log_message('error', 'Sis::execute_promotion — student cache bust failed: ' . $e->getMessage());
        }

        // Log history for moved students
        foreach ($moveResult['moved'] as $userId) {
            $name = $batchMap[$userId]['name'] ?? $userId;
            $this->_log_history($school_id, $userId, 'PROMOTION', $historyDesc, $historyMeta);
            $promoted[] = ['user_id' => $userId, 'name' => $name];
        }

        $skipped = [];
        foreach ($moveResult['failed'] as $userId) {
            // LC-2 · The reason a promotion skipped a student must name a
            // system that still exists. This said "RTDB atomic write failed"
            // long after the Dual_write RTDB engine was retired (758c924,
            // ZERO-RTDB). `$this->dw` is now an alias for Student_write_service,
            // which is Firestore-only — so an operator reading this message was
            // sent to debug a datastore ZenX no longer talks to, while the real
            // failure (a Firestore write for that student) went unnamed.
            $skipped[] = ['user_id' => $userId, 'reason' => 'Firestore write failed for this student'];
        }

        // G2 — Refresh section strength on every (old, new) section
        // touched by the batch. Old sections drop students; the new
        // section gains them. Dedup via assoc-key set so we don't
        // recompute the same section twice when many students share
        // an oldSection.
        $touchedSections = [];
        $touchedSections["{$newClassKey}|{$newSectionKey}"] = [$newClassKey, $newSectionKey];
        foreach ($batchMap as $bmInfo) {
            $oldSec = $bmInfo['oldSection'] ?? '';
            if ($oldSec === '') continue;
            $touchedSections["{$oldClassKey}|{$oldSec}"] = [$oldClassKey, $oldSec];
        }
        // BUG-045 Item 1 (perf, 2026-05-29): the synchronous section-strength
        // recompute that used to run here was REMOVED. It duplicated the
        // recompute already performed in the post-response shutdown handler
        // (Phase D2 below), wasting ~6 Firestore round-trips on the operator-
        // facing critical path for zero correctness benefit — currentStrength
        // is informational (admission section-picker bars) and self-healing on
        // the next lifecycle write.
        // INVARIANT: the deferred Phase-D2 loop is now the SOLE section-strength
        // recompute path. $touchedSections is captured into the shutdown closure
        // ($__touchedLocal) and consumed there — do not re-add a synchronous
        // recompute here.

        // ── BUG-045 Phase 1 B1 (2026-05-25): post-response shutdown handler ──
        // Pre-fix: fee-reassignment + section-strength recompute + promotion-batch
        // save ran SYNCHRONOUSLY post-batchMoveStudents. For 60 students this
        // exceeded 120s PHP ceiling (4.7×). Now: write a promote_jobs/{batchId}
        // status doc, flush response to operator immediately, then defer the
        // heavy work to a shutdown closure that runs after Apache releases the
        // client socket. Pattern source: FeeCollectionService.php:1465-1648.

        // Out-of-band visibility: persist initial job status BEFORE response
        // so operator (or a future status-poll endpoint) can observe progress.
        $promoteJobDocId = "{$this->school_name}_{$batchId}";
        try {
            $this->firebase->firestoreSet('promote_jobs', $promoteJobDocId, [
                'schoolId'        => $this->school_name,
                'session'         => $session,
                'batchId'         => $batchId,
                'sessionFrom'     => $session,
                'sessionTo'       => $toSession,
                'fromClass'       => $oldClassKey,
                'fromSection'     => $oldSectionKey,
                'toClass'         => $newClassKey,
                'toSection'       => $newSectionKey,
                'expectedCount'   => count($promoted),
                // ── LC-5 · RECORD THE TARGET SET, NOT JUST ITS SIZE ─────────
                // The students are ALREADY moved by this point — batchMoveStudents
                // committed synchronously above. Only fee regeneration is
                // deferred to the shutdown handler.
                //
                // If that handler dies mid-loop (fatal, OOM, timeout, worker
                // kill), some promoted students have regenerated demands and
                // some do not — the "Active-with-zero-demands" state this code
                // already names as the danger. A count alone cannot tell anyone
                // WHICH students those are, so the damage was undiscoverable
                // and unrepairable. The id list makes recovery a reconciliation
                // rather than a guess.
                'promotedStudentIds' => array_values(array_map(
                    static fn($p) => (string) ($p['user_id'] ?? ''), $promoted
                )),
                'processedCount'  => 0,
                'lastProcessedStudentId' => '',
                'failedStudents'  => [],
                'status'          => 'deferred-fees-pending',
                'startedAt'       => $now,
                'promotedBy'      => $adminName,
            ]);
        } catch (\Exception $e) {
            // Fail-safe: if status doc write fails, log and continue.
            // The shutdown handler still runs; only out-of-band visibility is lost.
            log_message('warning', "promote: promote_jobs status doc write failed for {$batchId}: " . $e->getMessage());
        }

        // Build response payload BEFORE flushing.
        $__responseJson = json_encode([
            'status'         => 'success',
            'message'        => count($promoted) . ' student(s) promoted successfully.',
            'promoted'       => $promoted,
            'skipped'        => $skipped,
            'batch_id'       => $batchId,
            'job_status_doc' => $promoteJobDocId,
            'csrf_token'     => $this->security->get_csrf_hash(),
        ]);

        // Apache mod_php early-response flush (mirror FCS:1481-1494).
        // XAMPP has no fastcgi_finish_request, so combine Connection: close +
        // Content-Length + ob_end_flush() + flush() to release the socket.
        @set_time_limit(0);
        @ignore_user_abort(true);
        if (!headers_sent()) {
            @header('Connection: close');
            @header('Content-Type: application/json');
            @header('Content-Length: ' . strlen($__responseJson));
            @header('Content-Encoding: none'); // disable mod_deflate so length is honest
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        echo $__responseJson;
        @flush();
        $this->output->set_output('');
        // From here on, every line runs after the operator's browser has
        // received the success banner. Errors below are background-only.

        // Capture by-value into the closure — no $this references that might
        // be torn down at shutdown time. Same hygiene pattern as FCS:1517-1521.
        $__feeLifecycle    = $this->feeLifecycle;
        $__firebase        = $this->firebase;
        $__fs              = $this->fs;
        $__schoolName      = $this->school_name;
        $__schoolId        = $this->school_id;
        $__oldClassKey     = $oldClassKey;
        $__oldSectionKey   = $oldSectionKey;
        $__newClassKey     = $newClassKey;
        $__newSectionKey   = $newSectionKey;
        $__sessionLocal    = $session;
        $__toSessionLocal  = $toSession;
        $__nowLocal        = $now;
        $__adminNameLocal  = $adminName;
        $__batchIdLocal    = $batchId;
        $__promotedLocal   = $promoted;
        $__touchedLocal    = $touchedSections;
        $__jobDocId        = $promoteJobDocId;

        register_shutdown_function(function () use (
            $__feeLifecycle, $__firebase, $__fs,
            $__schoolName, $__schoolId,
            $__oldClassKey, $__oldSectionKey, $__newClassKey, $__newSectionKey,
            $__sessionLocal, $__toSessionLocal, $__nowLocal, $__adminNameLocal,
            $__batchIdLocal, $__promotedLocal, $__touchedLocal, $__jobDocId
        ) {
            $deferFailedStudents = [];
            $processedCount      = 0;

            // BUG-076 Part 2-B (2026-05-28): session-threading. Demands for the
            // destination class must be created in the session students are
            // promoted INTO (toSession), not the admin's controller-boot
            // session. Re-point the shared fsTxn + Fee_lifecycle to toSession
            // before regeneration. The archive loop inside reassignFeesOnPromotion
            // uses _demandsForAllSessions (session-agnostic), so only the
            // regeneration target is affected; the boot session is restored
            // after the loop so Phase D2/D3 (which use $__fs) are unaffected.
            // Operator decision 2026-05-28: demands live in destination session.
            $__sessionThreaded = ($__toSessionLocal !== '' && $__toSessionLocal !== $__sessionLocal);
            if ($__sessionThreaded) {
                try {
                    $CI =& get_instance();
                    if (isset($CI->fsTxn) && is_object($CI->fsTxn)) {
                        $CI->fsTxn->init($__firebase, $__fs, $__schoolName, $__toSessionLocal);
                    }
                    $__feeLifecycle->init($__firebase, $__schoolName, $__toSessionLocal, $__adminNameLocal);
                } catch (\Throwable $e) {
                    log_message('error', "promote(deferred): session-thread re-point to {$__toSessionLocal} failed: " . $e->getMessage());
                    $__sessionThreaded = false;
                }
            }

            // Phase D1 — reassign fees per promoted student (the ~94% cost).
            foreach ($__promotedLocal as $p) {
                try {
                    // SIS Tier-1 fix B5 (2026-05-31): capture the return so we
                    // can detect the silent-zero-regenerated case. The upfront
                    // guard at L967 blocks promotion when the destination
                    // structure is missing AT REQUEST TIME, but this deferred
                    // loop runs later in a shutdown handler — a structure
                    // deleted between the guard and this call (admin race,
                    // Firestore transient, mid-loop deletion) would leave
                    // affected students Active-with-zero-demands while the
                    // promotion reported clean success. We now surface them
                    // in $deferFailedStudents like a thrown failure would.
                    $__regenResult = $__feeLifecycle->reassignFeesOnPromotion(
                        $p['user_id'], $__oldClassKey, $__oldSectionKey,
                        $__newClassKey, $__newSectionKey, $__schoolId
                    );
                    $__regenCount = is_array($__regenResult) ? (int) ($__regenResult['regenerated'] ?? 0) : 0;
                    if ($__regenCount === 0) {
                        $deferFailedStudents[] = [
                            'user_id' => $p['user_id'],
                            'name'    => $p['name'] ?? $p['user_id'],
                            'reason'  => "Zero demands regenerated for {$__newClassKey}/{$__newSectionKey} in session {$__toSessionLocal}. Destination fee structure may have been deleted after the upfront guard ran. Verify the structure exists and re-promote this student.",
                        ];
                    } else {
                        $processedCount++;

                        // SIS Wave-4 fix S1 (2026-05-31): propagate the new
                        // class/section to parent + teacher apps via entity_sync.
                        // Pre-fix, promoted students remained on their OLD
                        // class in the parents collection until next admin
                        // edit triggered a re-sync — parent app showed stale
                        // attendance/marks/fees for the old class; teacher
                        // app's new-class roster filter excluded them.
                        //
                        // Sync failures are LOG-AND-CONTINUE per operator
                        // direction (promotion + fee-regen are the PRIMARY
                        // transactional outcomes; app sync is post-write
                        // observability). We deliberately do NOT add
                        // sync-only failures to $deferFailedStudents — that
                        // queue is reserved for fee-regen failures.
                        //
                        // S7 (commit d19e9e0a) made syncParent safe under
                        // partial payloads — its $pick helper preserves
                        // identity fields when the payload omits them.
                        // Safe to pass a narrow 5-field update here.
                        //
                        // entity_sync is not in the closure's `use` list;
                        // we re-acquire it via get_instance() like the
                        // L1161 fsTxn re-init pattern (BUG-076 Part 2-B).
                        try {
                            $CI =& get_instance();
                            $entitySync = ($CI !== null && isset($CI->entity_sync)) ? $CI->entity_sync : null;
                            if ($entitySync !== null) {
                                $syncPayload = [
                                    'Name'    => $p['name'] ?? $p['user_id'],
                                    'Class'   => $__newClassKey,
                                    'Section' => $__newSectionKey,
                                    'Status'  => 'Active',
                                    'session' => $__toSessionLocal,
                                ];
                                if (!$entitySync->syncStudent($p['user_id'], $syncPayload)) {
                                    log_message('warning', "promote(deferred): syncStudent returned false for {$p['user_id']}");
                                }
                                if (!$entitySync->syncParent($p['user_id'], $syncPayload)) {
                                    log_message('warning', "promote(deferred): syncParent returned false for {$p['user_id']}");
                                }
                            } else {
                                log_message('warning', "promote(deferred): entity_sync library not accessible in shutdown context for {$p['user_id']} — app sync skipped (fee regen succeeded)");
                            }
                        } catch (\Throwable $eSync) {
                            log_message('error', "promote(deferred): entity_sync threw for {$p['user_id']}: " . $eSync->getMessage());
                        }
                    }
                } catch (\Throwable $e) {
                    log_message('error', "promote(deferred): reassignFeesOnPromotion failed for {$p['user_id']}: " . $e->getMessage());
                    $deferFailedStudents[] = [
                        'user_id' => $p['user_id'],
                        'name'    => $p['name'] ?? $p['user_id'],
                        'reason'  => $e->getMessage(),
                    ];
                }

                    // ── LC-5 · CHECKPOINT, SO AN INTERRUPTION IS DISCOVERABLE ──
                    // processedCount was written ONLY at the end of the handler.
                    // A handler killed at student 59 of 60 therefore left the job
                    // doc claiming processedCount: 0 — a figure that is not merely
                    // stale but actively false, and which no recovery could act on.
                    //
                    // Checkpointing every few students bounds the extra writes
                    // (6 for a 60-student batch) and keeps them on the DEFERRED
                    // path, which exists precisely so slow work is off the
                    // operator-facing request. Best-effort: a checkpoint that
                    // fails must never abort the regeneration it is describing.
                    if (($processedCount % 10) === 0 && $processedCount > 0) {
                        try {
                            $__firebase->firestoreSet('promote_jobs', $__jobDocId, [
                                'processedCount'         => $processedCount,
                                'lastProcessedStudentId' => (string) ($p['user_id'] ?? ''),
                                'checkpointAt'           => date('c'),
                            ], true);
                        } catch (\Throwable $__cpErr) {
                            log_message('warning', 'promote(deferred): checkpoint failed for '
                                . $__batchIdLocal . ': ' . $__cpErr->getMessage());
                        }
                    }
            }

            // BUG-076 Part 2-B: restore the boot session on the shared fsTxn +
            // Fee_lifecycle so subsequent Phase D2/D3 work (and any later
            // shutdown code) operates against the original session.
            if ($__sessionThreaded) {
                try {
                    $CI =& get_instance();
                    if (isset($CI->fsTxn) && is_object($CI->fsTxn)) {
                        $CI->fsTxn->init($__firebase, $__fs, $__schoolName, $__sessionLocal);
                    }
                    $__feeLifecycle->init($__firebase, $__schoolName, $__sessionLocal, $__adminNameLocal);
                } catch (\Throwable $_) {}
            }

            // Phase D2 — section strength recompute. Outside the foreach
            // because it's O(touched-sections), not O(students).
            // Note: requires controller context; rebuild a minimal Sis instance
            // is too heavy. Instead inline the schoolWhere + fs->set pattern
            // here (mirror of _recompute_section_strength at Sis.php:2329-2353).
            foreach ($__touchedLocal as $pair) {
                try {
                    list($cKey, $sKey) = $pair;
                    $rows = $__fs->schoolWhere('students', [
                        ['className', '==', $cKey],
                        ['section',   '==', $sKey],
                        ['status',    '==', 'Active'],
                    ]);
                    $strength = is_array($rows) ? count($rows) : 0;
                    $secDocId = $__fs->sectionDocId($cKey, $sKey);
                    // BUG (2026-05-28): only update strength on an EXISTING section.
                    // set(merge=true) on a missing section created a field-less
                    // "ghost" doc (currentStrength/updatedAt only — no schoolId/
                    // className/section/session), which then blocked section
                    // creation ("already exists" by docId) while being invisible
                    // to the className+session list query. Strength recompute must
                    // never materialize a section.
                    if (is_array($__fs->get('sections', $secDocId))) {
                        $__fs->set('sections', $secDocId, [
                            'currentStrength' => $strength,
                            'updatedAt'       => date('c'),
                        ], true);
                    }
                } catch (\Throwable $e) {
                    log_message('warning', "promote(deferred): _recompute_section_strength failed for {$pair[0]}/{$pair[1]}: " . $e->getMessage());
                }
            }

            // Phase D3 — schools.promotions batch record.
            try {
                $schoolDoc = $__fs->get('schools', $__schoolId);
                $promotions = (is_array($schoolDoc) && isset($schoolDoc['promotions']) && is_array($schoolDoc['promotions']))
                              ? $schoolDoc['promotions'] : [];
                $promotions[$__batchIdLocal] = [
                    'session_from' => $__sessionLocal, 'session_to' => $__toSessionLocal,
                    'promoted_at' => $__nowLocal, 'promoted_by' => $__adminNameLocal,
                    'from_class' => $__oldClassKey, 'from_section' => $__oldSectionKey,
                    'to_class' => $__newClassKey, 'to_section' => $__newSectionKey,
                    'count' => count($__promotedLocal),
                ];
                $__fs->update('schools', $__schoolId, ['promotions' => $promotions]);
            } catch (\Throwable $e) {
                log_message('warning', "promote(deferred): schools.promotions update failed for {$__batchIdLocal}: " . $e->getMessage());
            }

            // Phase D4 — final promote_jobs status update for out-of-band observability.
            try {
                $finalStatus = empty($deferFailedStudents) ? 'completed' : 'completed_with_errors';
                $__firebase->firestoreSet('promote_jobs', $__jobDocId, [
                    'status'         => $finalStatus,
                    'processedCount' => $processedCount,
                    'failedStudents' => $deferFailedStudents,
                    'completedAt'    => date('c'),
                ], true);
            } catch (\Throwable $e) {
                log_message('error', "promote(deferred): final promote_jobs status write failed for {$__batchIdLocal}: " . $e->getMessage());
            }
        });

        return; // response already flushed; CI's _display() is silenced via set_output('').
    }

    /* ══════════════════════════════════════════════════════════════════════
       TRANSFER CERTIFICATES
    ══════════════════════════════════════════════════════════════════════ */

    public function tc_list()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_tc_list', 'SIS', 'view');
        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        // Read TC index from school doc
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $tcIndex = $schoolDoc['tcIndex'] ?? [];
        if (!is_array($tcIndex)) $tcIndex = [];

        $tcRecords = [];
        if (!empty($tcIndex)) {
            foreach ($tcIndex as $tcKey => $tc) {
                if (!is_array($tc)) continue;
                $tcRecords[] = [
                    'user_id'    => $tc['user_id']     ?? '',
                    'name'       => $tc['student_name'] ?? $tc['name'] ?? '',
                    'sr_no'      => (string) ($tc['sr_no'] ?? ''),
                    'class'      => $tc['class']        ?? '',
                    'section'    => $tc['section']      ?? '',
                    'tc_key'     => $tc['tc_key']       ?? $tcKey,
                    'tc_no'      => $tc['tc_no']        ?? '',
                    'issued_date'=> $tc['issued_date']  ?? '',
                    'issued_by'  => $tc['issued_by']    ?? '',
                    'destination'=> $tc['destination']  ?? '',
                    'status'     => $tc['status']       ?? '',
                ];
            }
        } else {
            // Fallback: scan students with TC status
            $tcStudents = $this->fs->schoolWhere('students', [['status', '==', 'TC']]);
            if (empty($tcStudents)) {
                $tcStudents = $this->fs->schoolWhere('students', [['Status', '==', 'TC']]);
            }

            foreach ($tcStudents as $doc) {
                $d = $doc['data'] ?? $doc;
                $student = $this->_normalizeStudentDoc($doc['data']);
                if (!is_array($student)) continue;
                $uid = $student['User Id'] ?? $student['studentId'] ?? $d['id'];
                $tcs = $student['TC'] ?? [];
                if (!is_array($tcs)) continue;
                foreach ($tcs as $tcKey => $tc) {
                    if (!is_array($tc)) continue;
                    $tcRecords[] = [
                        'user_id'    => $uid,
                        'name'       => $student['Name']    ?? $uid,
                        'sr_no'      => (string) ($tc['sr_no'] ?? $student['srNo'] ?? ''),
                        'class'      => $student['Class']   ?? '',
                        'section'    => $student['Section'] ?? '',
                        'tc_key'     => $tcKey,
                        'tc_no'      => $tc['tc_no']        ?? '',
                        'issued_date'=> $tc['issued_date']  ?? '',
                        'issued_by'  => $tc['issued_by']    ?? '',
                        'destination'=> $tc['destination']  ?? '',
                        'status'     => $tc['status']       ?? '',
                    ];
                }
            }
        }

        // Sort by issued_date desc
        usort($tcRecords, fn($a, $b) =>
            strcmp($b['issued_date'] ?? '', $a['issued_date'] ?? '')
        );

        // Fix 5: Server-side pagination (50 per page)
        $perPage    = 50;
        $page       = max(1, (int)($this->input->get('page') ?? 1));
        $total      = count($tcRecords);
        $totalPages = (int)ceil($total / $perPage);
        $offset     = ($page - 1) * $perPage;
        $pagedTcs   = array_slice($tcRecords, $offset, $perPage);

        $data['tc_records']   = $pagedTcs;
        $data['tc_total']     = $total;
        $data['tc_page']      = $page;
        $data['tc_per_page']  = $perPage;
        $data['tc_pages']     = $totalPages;
        $data['session_year'] = $session;

        $this->load->view('include/header');
        $this->load->view('sis/tc_list', $data);
        $this->load->view('include/footer');
    }

    public function issue_tc()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_issue_tc', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;

        $userId      = trim($this->input->post('user_id')      ?? '');
        $reason      = trim($this->input->post('reason')      ?? '') ?: 'Transfer';
        $destination = trim($this->input->post('destination') ?? '');

        if (empty($userId)) return $this->json_error('Student ID required.');
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');

        $student = $this->_getStudent($userId);
        if (empty($student)) return $this->json_error('Student not found.');

        // Check outstanding fees — block TC if dues remain (unless force_override is set)
        $forceOverride = $this->input->post('force_override') === 'true';
        if (!$forceOverride) {
            $dues = $this->_check_outstanding_dues($userId, (array)$student);
            if ($dues['has_dues']) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode([
                    'status'  => 'error',
                    'message' => $dues['summary'] . '. Clear all dues before issuing a Transfer Certificate.',
                    'dues'    => $dues,
                    'can_override' => true,
                ]);
                return;
            }
        }

        // ── ADM-COMPLETE-1 · A TC IS A LEGAL DOCUMENT (2026-09-19) ───────────
        //
        // Admitting a thin record is right: the child needs a seat, and the paperwork
        // follows. ISSUING A CERTIFICATE FROM ONE IS NOT. A Transfer Certificate is
        // what the next school admits on and what a board registration is built from;
        // it carries the name, the date of birth and the father's name, and those are
        // precisely the fields a receiving school and UDISE+ reject it for.
        //
        // This is the "block at the point of consequence" rule: never at the counter,
        // where refusing hurts a child, but always at the moment the missing data
        // becomes somebody else's problem.
        //
        // Narrower than _profileGaps() on purpose — a TC does not need the mother's
        // occupation or a category. Only what the certificate itself prints.
        $__tcGaps = [];
        if (trim((string) ($student['Name']        ?? $student['name']        ?? '')) === '') $__tcGaps[] = 'Student name';
        if (trim((string) ($student['DOB']         ?? $student['dob']         ?? '')) === '') $__tcGaps[] = 'Date of birth';
        if (trim((string) ($student['Father Name'] ?? $student['fatherName']  ?? '')) === '') $__tcGaps[] = "Father's name";
        if (trim((string) ($student['Class']       ?? $student['className']   ?? '')) === '') $__tcGaps[] = 'Class';
        if ($__tcGaps) {
            return $this->json_error(
                'This student\'s record is missing ' . implode(', ', $__tcGaps)
                . '. A Transfer Certificate prints these, and the receiving school will '
                . 'refuse one without them. Complete the profile, then issue the TC.'
            );
        }

        // Check not already TC issued
        $existing = $student['TC'] ?? [];
        if (is_array($existing)) {
            foreach ($existing as $tc) {
                if (is_array($tc) && ($tc['status'] ?? '') === 'active') {
                    return $this->json_error('An active TC is already issued for this student.');
                }
            }
        }

        $tcNo      = $this->_get_tc_number($school_name);
        // SIS Wave-3 fix F4 (2026-05-31): _get_tc_number now returns '' when
        // the atomic claim is unavailable (previously it silently fell back
        // to stale-mirror math, risking duplicate TC numbers). Surface the
        // failure to the operator instead of writing a TC with empty number.
        if ($tcNo === '') {
            return $this->json_error('Could not allocate a TC number atomically (Firestore counter unavailable). Please retry in a moment. If the issue persists, contact support.');
        }
        $adminName = $this->session->userdata('admin_name') ?? 'Admin';
        $tcKey     = 'TC_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $tcData    = [
            'tc_no'       => $tcNo,
            'issued_date' => date('Y-m-d'),
            'issued_by'   => $adminName,
            'reason'      => $reason,
            'destination' => $destination,
            'status'      => 'active',
            'student_name'=> $student['Name'] ?? $userId,
            'class'       => $student['Class'] ?? '',
            'section'     => $student['Section'] ?? '',
            // SRNO stamped into the TC record at issue time — the student doc is
            // already loaded, so it costs no extra read and lets the TC register
            // show the permanent school register number without re-reading each
            // student. Historical tcIndex entries (pre-change) simply lack it.
            'sr_no'       => (string) ($student['srNo'] ?? $student['srNumber'] ?? $student['SR Number'] ?? ''),
            'user_id'     => $userId,
            'tc_key'      => $tcKey,
        ];

        // Update Firestore student doc
        $tcHistory = $student['TC'] ?? [];
        $tcHistory[$tcKey] = $tcData;
        $this->fs->updateEntity('students', $userId, [
            'status'    => 'TC',
            'TC'        => $tcHistory,
            'updatedAt' => date('c'),
        ]);

        // Store TC in school's tcIndex for fast listing
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $tcIndex = $schoolDoc['tcIndex'] ?? [];
        $tcIndex[$tcKey] = $tcData;
        $this->fs->update('schools', $this->school_id, ['tcIndex' => $tcIndex]);

        // ── FIX 2a: Remove from RTDB roster ──────────────────────────
        $stuClass   = Firestore_service::classKey($student['Class'] ?? $student['className'] ?? '');
        $stuSection = Firestore_service::sectionKey($student['Section'] ?? $student['section'] ?? '');
        if ($stuClass && $stuSection) {
            $this->dw->removeFromRoster($stuClass, $stuSection, $userId);
        }

        // Firestore student doc already carries status=TC (see fs->updateEntity
        // above). No RTDB mirror — Firestore is the source of truth.

        // ── Disable Firebase Auth (prevent login) ────────────────────
        try {
            $this->firebase->updateFirebaseUser($userId, ['disabled' => true]);
        } catch (\Exception $e) {
            log_message('error', "TC: disableFirebaseUser failed for {$userId}: " . $e->getMessage());
        }

        // Entity sync for Android apps
        try {
            // SIS Wave-2 S6 (2026-05-31): observability for sync return.
            if (!$this->entity_sync->syncStudent($userId, [
                'Name'    => $student['Name'] ?? $userId,
                'Class'   => $stuClass,
                'Section' => $stuSection,
                'Status'  => 'TC',
            ])) {
                log_message('warning', "syncStudent returned false for {$userId} (issue_tc)");
            }
            // S2 (2026-05-31) + S6 (2026-05-31): propagate Status=TC to
            // parent doc with array_merge to preserve identity (S7 anti-
            // pattern guard), and check the return for visibility.
            if (!$this->entity_sync->syncParent($userId, array_merge($student, [
                'Status' => 'TC', 'status' => 'TC',
            ]))) {
                log_message('warning', "syncParent returned false for {$userId} (issue_tc)");
            }
        } catch (\Exception $e) {
            log_message('error', "entity_sync sync TC failed for {$userId}: " . $e->getMessage());
        }

        // G1 — Blank current-month attendance from today onwards so the
        // student's % doesn't drift after TC issue.
        $this->_blank_summary_from_today($userId);

        // G2 — Section strength drops by one (student leaves the roster).
        $this->_recompute_section_strength($stuClass, $stuSection);

        // Phase 19F — a TC is a TERMINAL exit, so the student's active ID card
        // is revoked. Best-effort and deliberately after the TC has already
        // been recorded: the student's Status=TC is what the scanner actually
        // enforces, so a failure here degrades to pre-19F behaviour and must
        // never abort an issued TC.
        $this->_revoke_id_card_on_exit($userId, 'issue_tc');

        // ── LC-6 · THE TC DOOR NEVER FROZE THE FEE BOOK ────────────────────
        //
        // Every other exit freezes: withdraw_student, delete_student, Hostel,
        // and change_status (LC-1). Issuing a Transfer Certificate — the most
        // final exit ZenX has, and the one that also disables the student's
        // Firebase Auth user above — was the single door that left the fee
        // book collectable.
        //
        // The consequence is the same one FRZ-1 created for withdrawals and
        // FRZ-4 then closed: `Fee_late_fee_service` exempts only 'frozen'
        // demands, so a departed student went on accruing late fees, went on
        // appearing in defaulter reports, and went on showing dues in the
        // Parent App — for a school they had already left.
        //
        // This is not a new policy. `cancel_tc()` ALREADY calls
        // `unfreezeFeesOnRestore()` when a TC is reversed: the code already
        // asserts that a TC'd student's book is frozen. It simply never froze
        // it, so the reversal was restoring a state that had never been set —
        // and the pairing was written against withdraw_student (see FRZ-4's
        // docblock, which names withdraw_student as the freeze counterpart)
        // rather than against issue_tc, which is what cancel_tc actually undoes.
        //
        // Frozen preserves the money; it does not forgive it. Every demand
        // keeps its amounts, and unfreezeFeesOnRestore re-derives each status
        // from its own money — so a genuine outstanding balance survives a
        // TC-and-cancel round trip intact.
        //
        // Best-effort and placed AFTER the TC is recorded, matching the
        // withdrawal side exactly: a fee-book failure must never abort an
        // issued Transfer Certificate.
        try {
            $this->feeLifecycle->freezeFeesOnSoftDelete($userId);
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::freezeFeesOnSoftDelete failed for {$userId} (issue_tc): " . $e->getMessage());
        }

        $this->_log_history($school_id, $userId, 'TC_ISSUED',
            "Transfer Certificate issued (TC#{$tcNo}) — Reason: {$reason}",
            ['tc_no' => $tcNo, 'destination' => $destination]
        );

        return $this->json_success([
            'message' => "Transfer Certificate {$tcNo} issued.",
            'tc_no'   => $tcNo,
            'tc_key'  => $tcKey,
            'user_id' => $userId,
        ]);
    }

    public function print_tc($userId = null, $tcKey = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_print_tc', 'SIS', 'view');
        if (empty($userId)) show_404();
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $userId)) show_404();
        if (!empty($tcKey) && !preg_match('/^[A-Za-z0-9_\-]+$/', $tcKey)) show_404();

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;

        // Dues-based blocking — applied per the school's policy doc
        // `feeSettings/{school}_{session}_blocking_policy.block_tc`.
        // Admins can bypass with ?force_override=1 if the policy
        // permits it. Runs BEFORE student fetch so we don't leak an
        // unauthorised preview.
        try {
            // Phase TC-2 (2026-05-09) — canonical-reader alignment.
            // Was: Fee_dues_check::check (denormalized feeDefaulters;
            //   fees-only). Stale defaulter docs (e.g. STU0004/STU0005
            //   baseline pre-Phase-3D) could leak through this gate.
            // Now: Fee_dues_check for POLICY only (block_tc, threshold,
            //   admin_override_allowed); Fee_defaulter_check for canonical
            //   cross-module dues calculation (feeDemands + library +
            //   hostel + transport). Brings print_tc to parity with
            //   issue_tc + withdraw_student, which already use this path
            //   via _check_outstanding_dues().
            //
            // Side effect: calculateClearanceStatus refreshes the
            // studentClearance/{schoolId}_{studentId} doc (idempotent
            // merge, fail-soft). This same write already occurs on
            // every issue_tc + withdraw_student call today. No new write
            // class is introduced — only a new write-trigger location.

            // Step 1 — policy lookup (decides whether to block at all).
            $this->load->library('Fee_dues_check', null, 'duesCheck');
            $this->duesCheck->init($this->firebase, $this->school_name, $this->session_year);
            $policy = $this->duesCheck->getPolicy();

            // Step 2 — only run dues check if school policy says block_tc.
            if (!empty($policy['block_tc'])) {
                $override = (bool) $this->input->get('force_override');
                // Policy can disallow override.
                if (empty($policy['admin_override_allowed'])) $override = false;

                // Step 3 — canonical clearance via Fee_defaulter_check.
                // DYNAMIC TC-CLEARANCE SCOPE (decision D): the school's TC dues
                // policy decides whether a leaving student must settle ALL years'
                // dues (the common "No Dues" norm) or only the current session.
                // `all_sessions_clearance` lives alongside block_tc/threshold in the
                // same per-school policy; absent ⇒ false ⇒ current per-session
                // behaviour, so no school is silently given a stricter rule.
                $allSessionsClearance = !empty($policy['all_sessions_clearance']);
                $this->load->library('Fee_defaulter_check', null, 'feeDefaulter');
                $this->feeDefaulter->init($this->firebase, $this->school_name, $this->session_year);
                $clearance = $this->feeDefaulter->calculateClearanceStatus($userId, '', $allSessionsClearance);

                $threshold = (float) ($policy['threshold_amount'] ?? 0);
                $totalDues = (float) ($clearance['total_dues'] ?? 0);
                $blocked   = !$override && ($totalDues > $threshold);

                if ($blocked) {
                    // Compose summary across modules for operator clarity.
                    $parts = [];
                    if (!($clearance['fees_clear']      ?? true)) $parts[] = 'Fees Rs ' . number_format((float)($clearance['fees_dues']      ?? 0), 2);
                    if (!($clearance['library_clear']   ?? true)) {
                        $libPart = 'Library Rs ' . number_format((float)($clearance['library_dues'] ?? 0), 2);
                        if ((int)($clearance['library_unreturned_books'] ?? 0) > 0) $libPart .= ' + ' . (int)$clearance['library_unreturned_books'] . ' book(s)';
                        $parts[] = $libPart;
                    }
                    if (!($clearance['hostel_clear']    ?? true)) $parts[] = 'Hostel Rs ' . number_format((float)($clearance['hostel_dues']    ?? 0), 2);
                    if (!($clearance['transport_clear'] ?? true)) $parts[] = 'Transport Rs ' . number_format((float)($clearance['transport_dues'] ?? 0), 2);
                    $message = !empty($parts)
                        ? 'Outstanding dues — ' . implode('; ', $parts) . ' (Total Rs ' . number_format($totalDues, 2) . ').'
                        : 'Outstanding dues found (Rs ' . number_format($totalDues, 2) . ').';

                    // Keep the HTML error (this is a printable page, not JSON).
                    $this->output->set_status_header(403);
                    echo '<!DOCTYPE html><html><head><title>TC Withheld</title><style>body{font:15px/1.5 system-ui;padding:60px;color:#334155;text-align:center;}h1{color:#dc2626;}a{color:#BC5A3C;}</style></head><body>'
                       . '<h1>Transfer Certificate Withheld</h1>'
                       . '<p>' . htmlspecialchars($message) . '</p>'
                       . '<p><a href="' . base_url('sis/tc_list') . '">← Back to TC list</a></p>'
                       . '</body></html>';
                    return;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'print_tc: dues check failed: ' . $e->getMessage());
        }

        // Fetch student profile from Firestore
        $student = $this->_getStudent($userId);
        if (empty($student)) {
            log_message('error', "print_tc: student not found — userId={$userId}");
            show_404();
        }

        $tc = null;
        if ($tcKey && isset($student['TC'][$tcKey])) {
            $tc = $student['TC'][$tcKey];
        }
        // Fallback: get the active TC
        if (empty($tc) && is_array($student['TC'] ?? null)) {
            foreach ($student['TC'] as $k => $t) {
                if (is_array($t) && ($t['status'] ?? '') === 'active') {
                    $tc = $t;
                    break;
                }
            }
        }

        if (empty($tc)) {
            log_message('error', "print_tc: TC not found — userId={$userId} tcKey={$tcKey}");
            show_404();
        }

        // School profile for header
        $schoolProfile = $this->fs->get('schools', $this->school_id) ?? [];

        $data['student']       = $student;
        $data['tc']            = $tc;
        $data['school_profile']= $schoolProfile;
        $data['school_name']   = $school_name;

        // Standalone print view (no header/footer chrome)
        $this->load->view('sis/tc_print', $data);
    }

    public function cancel_tc()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_cancel_tc', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $userId      = trim($this->input->post('user_id'));
        $tcKey       = trim($this->input->post('tc_key'));

        if (empty($userId) || empty($tcKey)) {
            return $this->json_error('User ID and TC key required.');
        }
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');
        if (!$this->safe_path_segment($tcKey))  return $this->json_error('Invalid TC key.');

        // Cancel TC in student doc
        $student = $this->_getStudent($userId);
        $tcHistory = $student['TC'] ?? [];
        if (isset($tcHistory[$tcKey])) {
            $tcHistory[$tcKey]['status'] = 'cancelled';
            $tcHistory[$tcKey]['cancelled_at'] = date('Y-m-d H:i:s');
        }
        // ── CANCELLING A TC RE-ACQUIRES A SEAT ─────────────────────────────
        //
        // TC -> Active moves a student from consuming no seat to consuming
        // one. That is the same capacity event as an admission, and this
        // endpoint performed it with no capacity check at all. Reproduced
        // deterministically, no concurrency needed: fill a maxStrength=2
        // section, issue a TC to one student, admit a replacement into the
        // freed seat, then cancel the TC. Active occupancy became 3, and the
        // endpoint answered "TC cancelled and student re-activated."
        //
        // This is the fourth door to Active found in this programme, after
        // change_status reactivation, section transfer and rollover. Only the
        // ACQUIRING direction is guarded — issue_tc and withdraw_student
        // RELEASE a seat and must never require capacity.
        //
        // The TC history and the status flip go in ONE commit, so the guard
        // can never leave a TC cancelled while the student stays inactive, or
        // a student reactivated with the TC still standing.
        require_once APPPATH . 'libraries/Section_seat_guard.php';
        $tcRestoreData = [
            'status'    => 'Active',
            'TC'        => $tcHistory,
            'updatedAt' => date('c'),
        ];
        $priorStatus  = (string) ($student['status'] ?? $student['Status'] ?? '');
        $acquiresSeat = ($priorStatus !== 'Active');

        if ($acquiresSeat && $this->_seatGuardEnabled()) {
            $classNode   = (string) ($student['className'] ?? $student['Class'] ?? '');
            $sectionNode = (string) ($student['section'] ?? $student['Section'] ?? '');
            $sessionNode = (string) ($student['session'] ?? $this->session_year);

            if ($classNode === '' || $sectionNode === '') {
                // No placement means no seat to acquire and no section to
                // guard. Refusing is the fail-closed direction.
                return $this->json_error(
                    'This student has no class/section on record, so their TC cannot be '
                    . 'cancelled. Set their placement first.'
                );
            }

            $placed      = false;
            $capacityMsg = '';
            for ($attempt = 1; $attempt <= self::SEAT_RETRY_ATTEMPTS; $attempt++) {
                // Version FIRST — it must witness the count that follows.
                $decisionVersion = $this->_sectionDecisionVersion($classNode, $sectionNode, $sessionNode);
                $cap = $this->_sectionCapacity($classNode, $sectionNode, $sessionNode);
                if ($cap > 0) {
                    $now = $this->_sectionActiveCountFresh($classNode, $sectionNode, $sessionNode, $userId);
                    if ($now + 1 > $cap) {
                        $capacityMsg = $this->_sectionCapacityMessage(
                            ['capacity' => $cap, 'current' => $now, 'incoming' => 1,
                             'projected' => $now + 1, 'over' => ($now + 1) - $cap],
                            $classNode, $sectionNode
                        );
                        break;
                    }
                }

                $res = $this->_seatGuard()->commitPlacement(
                    $classNode, $sectionNode, $sessionNode,
                    [[
                        'collection' => 'students',
                        'docId'      => $this->fs->docId($userId),
                        'data'       => $tcRestoreData,
                        'merge'      => true,
                    ]],
                    $decisionVersion
                );
                if (($res['status'] ?? '') === Section_seat_guard::PLACED) { $placed = true; break; }
                if (($res['status'] ?? '') !== Section_seat_guard::CONFLICT) break;
            }

            if (!$placed) {
                return $this->json_error(
                    $capacityMsg !== '' ? $capacityMsg
                    : 'Could not cancel this TC because the section changed while the '
                      . 'request was in progress. Nothing was changed. Please retry.'
                );
            }
        } else {
            $this->fs->updateEntity('students', $userId, $tcRestoreData);
        }

        // Update school's tcIndex
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $tcIdx = $schoolDoc['tcIndex'] ?? [];
        if (isset($tcIdx[$tcKey])) {
            $tcIdx[$tcKey]['status'] = 'cancelled';
            $tcIdx[$tcKey]['cancelled_at'] = date('Y-m-d H:i:s');
            $this->fs->update('schools', $this->school_id, ['tcIndex' => $tcIdx]);
        }

        // Re-add student to RTDB roster
        $stuClass   = Firestore_service::classKey($student['Class'] ?? $student['className'] ?? '');
        $stuSection = Firestore_service::sectionKey($student['Section'] ?? $student['section'] ?? '');
        $stuName    = $student['Name'] ?? $student['name'] ?? $userId;
        if ($stuClass && $stuSection) {
            $this->dw->addToRoster($stuClass, $stuSection, $userId, $stuName);
        }

        // Firestore student doc already carries status=Active (see updateEntity
        // above). No RTDB mirror — Firestore is the source of truth.

        // Re-enable Firebase Auth
        try { $this->firebase->updateFirebaseUser($userId, ['disabled' => false]); } catch (\Exception $e) {}

        // Entity sync for Android apps
        try {
            // SIS Wave-2 S6 (2026-05-31): observability for sync return.
            if (!$this->entity_sync->syncStudent($userId, [
                'Name' => $stuName, 'Class' => $stuClass, 'Section' => $stuSection, 'Status' => 'Active',
            ])) {
                log_message('warning', "syncStudent returned false for {$userId} (cancel_tc)");
            }
            // S2 + S6: parent propagation with S7-guarded array_merge + return check.
            if (!$this->entity_sync->syncParent($userId, array_merge($student, [
                'Status' => 'Active', 'status' => 'Active',
            ]))) {
                log_message('warning', "syncParent returned false for {$userId} (cancel_tc)");
            }
        } catch (\Exception $e) {
            log_message('error', "entity_sync cancel_tc failed for {$userId}: " . $e->getMessage());
        }

        // ── FRZ-4 · UNFREEZE THE FEE BOOK ──────────────────────────────────
        //
        // `withdraw_student()` calls `freezeFeesOnSoftDelete()`, which stamps
        // `status: 'frozen'` — "do not collect" — on every collectable demand.
        // This method restores the student to Active and, until now, touched
        // Fee_lifecycle zero times. There was no unfreeze anywhere in the
        // codebase, so a re-instated student came back with their entire fee
        // book still marked uncollectable.
        //
        // The consequence became real only recently. Before FRZ-1 the late-fee
        // engine used a denylist that did not include 'frozen', so a
        // re-instated student kept accruing fines — accidentally correct, from
        // a defect. FRZ-1 correctly exempted frozen demands, which turned this
        // dormant gap into a live one: the student is silently exempt from late
        // fees for the rest of the session and nothing reports it.
        //
        // Best-effort, mirroring the freeze on the withdrawal side: a
        // re-instatement must not fail because a demand could not be restored.
        // Each demand's status is re-derived from its own money, so a payment
        // taken during the TC window is respected rather than overwritten.
        try {
            $this->feeLifecycle->unfreezeFeesOnRestore($userId);
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::unfreezeFeesOnRestore failed for {$userId}: " . $e->getMessage());
        }

        // G1 — Blank current-month attendance from today onwards. The
        // student is reactivating; days during the TC window stay as
        // whatever they were (likely V), and from today onwards they
        // get a clean slate that future marks can fill in.
        $this->_blank_summary_from_today($userId);

        // G2 — Section strength rises by one (student returns to roster).
        $this->_recompute_section_strength($stuClass, $stuSection);

        $this->_log_history($school_id, $userId, 'TC_CANCELLED',
            'Transfer Certificate cancelled — student re-activated.'
        );

        return $this->json_success(['message' => 'TC cancelled and student re-activated.']);
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT WITHDRAWAL & STATUS
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Soft-withdraw a student: mark Inactive, remove from session roster, log.
     * Does NOT delete any data — student profile and documents are preserved.
     */
    /**
     * Enable/disable the student's Firebase Auth login. Best-effort and never
     * throws — a status transition must not fail because Auth could not be
     * toggled. issue_tc already disables login on its terminal exit; this is the
     * shared helper that extends the SAME action to the other doors that reach a
     * terminal/inactive state (withdraw_student, change_status→Inactive) and back
     * (change_status→Active). Retained-access lifecycle fix: student status is not
     * yet a Firestore-rules boundary, so leaving Auth enabled after an exit is the
     * difference between "cannot log in" and "silently still can".
     */
    private function _set_student_login_enabled(string $userId, bool $enabled, string $context): void
    {
        try {
            $this->firebase->updateFirebaseUser($userId, ['disabled' => !$enabled]);
        } catch (\Throwable $e) {
            log_message('error', "{$context}: set login enabled=" . ($enabled ? '1' : '0')
                . " failed for {$userId}: " . $e->getMessage());
        }
    }

    public function withdraw_student()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_withdraw', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;
        $userId      = trim($this->input->post('user_id'));
        $reason      = trim($this->input->post('reason') ?? '') ?: 'Withdrawn';

        if (empty($userId)) return $this->json_error('User ID required.');
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');

        $student = $this->_getStudent($userId);
        if (empty($student) || !is_array($student)) {
            return $this->json_error('Student not found.');
        }

        if (($student['status'] ?? $student['Status'] ?? '') === 'Inactive') {
            return $this->json_error('Student is already inactive.');
        }

        // Check outstanding fees — block withdrawal if dues remain (unless force_override is set)
        $forceOverride = $this->input->post('force_override') === 'true';
        if (!$forceOverride) {
            $dues = $this->_check_outstanding_dues($userId, $student);
            if ($dues['has_dues']) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode([
                    'status'  => 'error',
                    'message' => $dues['summary'] . '. Clear all dues before withdrawing.',
                    'dues'    => $dues,
                    'can_override' => true,
                ]);
                return;
            }
        }

        // ── FIX 2: Remove from RTDB roster ───────────────────────────
        $stuClass   = Firestore_service::classKey($student['Class'] ?? $student['className'] ?? '');
        $stuSection = Firestore_service::sectionKey($student['Section'] ?? $student['section'] ?? '');
        if ($stuClass && $stuSection) {
            $this->dw->removeFromRoster($stuClass, $stuSection, $userId);
        }

        // Mark as Inactive in Firestore (source of truth — no RTDB mirror).
        $this->fs->updateEntity('students', $userId, ['status' => 'Inactive', 'updatedAt' => date('c')]);

        // Entity sync for Android apps
        try {
            // SIS Wave-2 S6 (2026-05-31): observability for sync return.
            if (!$this->entity_sync->syncStudent($userId, [
                'Name'    => $student['Name'] ?? $student['name'] ?? $userId,
                'Class'   => $stuClass,
                'Section' => $stuSection,
                'Status'  => 'Inactive',
            ])) {
                log_message('warning', "syncStudent returned false for {$userId} (withdraw_student)");
            }
            // S3 + S6: parent propagation with S7-guarded array_merge + return check.
            if (!$this->entity_sync->syncParent($userId, array_merge($student, [
                'Status' => 'Inactive', 'status' => 'Inactive',
            ]))) {
                log_message('warning', "syncParent returned false for {$userId} (withdraw_student)");
            }
        } catch (\Exception $e) {
            log_message('error', "entity_sync withdraw_student sync failed for {$userId}: " . $e->getMessage());
        }

        // Phase 19F — withdrawal is a TERMINAL exit; revoke the active card.
        // See _revoke_id_card_on_exit() for why this is best-effort and why
        // it is not wired to any reversible status change.
        $this->_revoke_id_card_on_exit($userId, 'withdraw_student');

        // Freeze fee records for withdrawn student
        try {
            $this->feeLifecycle->freezeFeesOnSoftDelete($userId);
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::freezeFeesOnSoftDelete failed for {$userId}: " . $e->getMessage());
        }

        // Retained-access fix: withdrawal is a terminal exit like TC — end the
        // login too. issue_tc already does this; withdraw_student was the door
        // that left the Firebase Auth user enabled. Best-effort (see helper).
        $this->_set_student_login_enabled($userId, false, 'withdraw_student');

        // G1 — Blank current-month attendance from today onwards so the
        // withdrawn student's % stops drifting upward as the rest of
        // the month gets recorded for everyone else.
        $this->_blank_summary_from_today($userId);

        // G2 — Section strength drops by one.
        $this->_recompute_section_strength($stuClass, $stuSection);

        $this->_log_history($school_id, $userId, 'WITHDRAWAL',
            "Student withdrawn: {$reason}",
            ['reason' => $reason, 'session' => $session, 'class' => $stuClass, 'section' => $stuSection]
        );

        return $this->json_success(['message' => 'Student withdrawn and marked Inactive.']);
    }

    /**
     * Toggle or explicitly set a student's Status field (Active / Inactive).
     * TC status is managed through issue_tc / cancel_tc, not here.
     */
    public function change_status()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_change_status', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id = $this->parent_db_key;
        $userId    = trim($this->input->post('user_id'));
        $newStatus = trim($this->input->post('status'));

        if (empty($userId)) return $this->json_error('User ID required.');
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');
        if (!in_array($newStatus, ['Active', 'Inactive'], true)) {
            return $this->json_error('Status must be Active or Inactive.');
        }

        $student = $this->_getStudent($userId);
        if (empty($student)) return $this->json_error('Student not found.');

        // Prior status, read BEFORE the write, so the fee-book consequence
        // below fires only on a real transition and not on a re-save.
        $priorStatus = trim((string) ($student['status'] ?? $student['Status'] ?? ''));

        // TERMINAL-STATE GUARD (lifecycle-critic fix). change_status only knows
        // Active<->Inactive. TC and Alumni are terminal exits with their OWN
        // reversal doors: a TC is undone by cancel_tc (which re-enables login and
        // unfreezes the fee book), and graduation is not a manual toggle. Letting
        // this endpoint flip a TC/Alumni student to Active would set the field
        // without the matching restore — a broken half-state (login stays
        // disabled, book stays frozen; an alumnus silently un-graduated). Refuse
        // and name the correct action. The UI only shows Reactivate for Inactive
        // rows, so this closes the direct/CSRF-valid POST path the UX can't.
        if ($newStatus === 'Active' && in_array($priorStatus, ['TC', 'Alumni'], true)) {
            return $this->json_error(
                $priorStatus === 'TC'
                    ? 'This student holds a Transfer Certificate. Use "Cancel TC" to bring them back — that restores login and the fee book correctly.'
                    : 'This student has graduated (Alumni) and cannot be reactivated from here.'
            );
        }

        // Phase 1 (2026-04-08): write camelCase only. The legacy `Status`
        // (capital S) duplicate caused case-sensitivity collisions in the
        // Teacher app's StudentDoc Kotlin class — see
        // memory/firestore_class_section_canonical.md for the full story.
        // ── TYPE C · REACTIVATION ACQUIRES A SEAT ───────────────────────────
        //
        // A status flip is not "just a field update". Inactive -> Active moves a
        // student from consuming no seat to consuming one, which is the same
        // capacity event as an admission — and this endpoint performed it with
        // no capacity check at all. Reproduced deterministically, no concurrency
        // needed: fill a maxStrength=2 section, deactivate one student, admit a
        // replacement into the freed seat, then reactivate the original. Active
        // occupancy became 3.
        //
        // Only the ACQUIRING transition is guarded. Active -> Inactive releases a
        // seat and must never require capacity; Active -> Active and
        // Inactive -> Inactive change nothing. Guarding every status write would
        // make releases fail when a section is full, which is precisely backwards.
        //
        // The seat is acquired through the SAME authority admission uses —
        // decision version captured BEFORE the occupancy it protects, then the
        // status write committed under that version's CAS — rather than a second
        // copy of the concurrency algorithm.
        // Declare the dependency where it is used. Committed MY_Controller does
        // not load this library, so relying on some other constructor having
        // pulled it in leaves the guard as "class not found" — which is a
        // fatal, not a silent bypass, but still the wrong failure.
        require_once APPPATH . 'libraries/Section_seat_guard.php';
        $acquiresSeat = ($priorStatus !== $newStatus && $newStatus === 'Active');
        if ($acquiresSeat && $this->_seatGuardEnabled()) {
            $classNode   = (string) ($student['className'] ?? $student['Class'] ?? '');
            $sectionNode = (string) ($student['section'] ?? $student['Section'] ?? '');
            $sessionNode = (string) ($student['session'] ?? $this->session_year);

            if ($classNode === '' || $sectionNode === '') {
                // Without a placement there is no seat to acquire and no section
                // to guard. Refusing is the fail-closed direction: silently
                // reactivating an unplaced student is how occupancy drifts.
                return $this->json_error(
                    'This student has no class/section on record, so they cannot be '
                    . 'reactivated. Set their placement first.'
                );
            }

            $placed = false;
            $capacityMsg = '';
            for ($attempt = 1; $attempt <= self::SEAT_RETRY_ATTEMPTS; $attempt++) {
                // Version FIRST — it must witness the count that follows.
                $decisionVersion = $this->_sectionDecisionVersion($classNode, $sectionNode, $sessionNode);
                $cap = $this->_sectionCapacity($classNode, $sectionNode, $sessionNode);
                if ($cap > 0) {
                    $now = $this->_sectionActiveCountFresh($classNode, $sectionNode, $sessionNode, $userId);
                    if ($now + 1 > $cap) {
                        $capacityMsg = $this->_sectionCapacityMessage(
                            ['capacity' => $cap, 'current' => $now, 'incoming' => 1,
                             'projected' => $now + 1, 'over' => ($now + 1) - $cap],
                            $classNode, $sectionNode
                        );
                        break;
                    }
                }

                $res = $this->_seatGuard()->commitPlacement(
                    $classNode, $sectionNode, $sessionNode,
                    [[
                        'collection' => 'students',
                        'docId'      => $this->fs->docId($userId),
                        'data'       => ['status' => $newStatus, 'updatedAt' => date('c')],
                        'merge'      => true,
                    ]],
                    $decisionVersion
                );
                if (($res['status'] ?? '') === Section_seat_guard::PLACED) { $placed = true; break; }
                if (($res['status'] ?? '') !== Section_seat_guard::CONFLICT) break;
            }

            if (!$placed) {
                return $this->json_error(
                    $capacityMsg !== '' ? $capacityMsg
                    : 'Could not reactivate this student because the section changed while the '
                      . 'request was in progress. Nothing was changed. Please retry.'
                );
            }
        } else {
            $this->fs->updateEntity('students', $userId, ['status' => $newStatus, 'updatedAt' => date('c')]);
        }

        // ── LC-1 · A STATUS FLIP HERE MUST MOVE THE FEE BOOK TOO ───────────
        //
        // This endpoint reaches the SAME two states that withdraw_student and
        // cancel_tc reach, but it was the one path that never touched the fee
        // book. FRZ-4's docblock records "freezeFeesOnSoftDelete() has three
        // live callers" — there are four doors to Inactive (withdraw_student,
        // delete_student, Hostel, and this one), and this is the door that was
        // missed. Both directions were financially wrong:
        //
        //   Active -> Inactive   demands stayed COLLECTABLE. The student kept
        //                        accruing late fees (Fee_late_fee_service
        //                        exempts only 'frozen'), kept appearing in
        //                        defaulter reports, and kept showing dues in
        //                        the Parent App — while withdraw_student, which
        //                        reaches the identical state, froze them.
        //
        //   Inactive -> Active   a previously withdrawn student came back with
        //                        the whole fee book still 'frozen', i.e.
        //                        silently exempt from fees AND late fees for
        //                        the rest of the session, with no report saying
        //                        so. That is exactly FRZ-4, reached through a
        //                        different door than cancel_tc.
        //
        // Only fires on an actual transition: re-saving Active over Active must
        // not unfreeze a book that was frozen for an unrelated reason.
        //
        // Best-effort, mirroring both existing call sites: a status change must
        // not fail because a demand could not be re-stamped. \Throwable, not
        // \Exception — a missing collaborator raises an Error (FEE-W1).
        if ($priorStatus !== $newStatus) {
            try {
                if ($newStatus === 'Inactive') {
                    $this->feeLifecycle->freezeFeesOnSoftDelete($userId);
                } else {
                    $this->feeLifecycle->unfreezeFeesOnRestore($userId);
                }
            } catch (\Throwable $e) {
                log_message('error', "change_status: fee-book transition failed for {$userId} "
                    . "({$priorStatus} -> {$newStatus}): " . $e->getMessage());
            }

            // Retained-access fix: mirror the fee-book transition on the LOGIN.
            // Active→Inactive must end the session (Auth was left enabled here,
            // unlike issue_tc); Inactive→Active restores it. Best-effort.
            $this->_set_student_login_enabled($userId, $newStatus === 'Active', 'change_status');
        }

        $this->_log_history($school_id, $userId, 'STATUS_CHANGE',
            "Status changed to {$newStatus}", ['status' => $newStatus]
        );

        // Firestore sync for Android apps (entity_sync loaded in constructor)
        // SIS Wave-2 S6 (2026-05-31): observability for sync return.
        // Note: the syncParent call below passes a Status-only payload which
        // is the S7 known-issue (full-doc rewrite clobbers fatherName/etc.).
        // S6 only adds return visibility; S7 fix is Wave-4 territory.
        if (!$this->entity_sync->syncStudent($userId, ['Status' => $newStatus])) {
            log_message('warning', "syncStudent returned false for {$userId} (toggle_status)");
        }
        if (!$this->entity_sync->syncParent($userId, ['Status' => $newStatus])) {
            log_message('warning', "syncParent returned false for {$userId} (toggle_status)");
        }

        // G1 — Blank current-month attendance from today onwards on
        // every status flip. For Active→Inactive this stops the % drift
        // for the rest of the month; for Inactive→Active it gives a
        // clean slate from today.
        $this->_blank_summary_from_today($userId);

        // G2 — Section strength changes by ±1 either direction. Pull
        // the student's class/section from the (already-fetched) doc
        // so we recompute the right section.
        $stuClass   = $student['Class']   ?? $student['className'] ?? '';
        $stuSection = $student['Section'] ?? $student['section']   ?? '';
        if ($stuClass !== '' && $stuSection !== '') {
            $this->_recompute_section_strength($stuClass, $stuSection);
        }

        return $this->json_success(['message' => "Status updated to {$newStatus}."]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PASSWORD RESET (admin-driven)
       Admins/Principals reset student/parent passwords here.
       Firebase Auth is the sole auth source for SIS users (Parent app).
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * POST /sis/reset_password
     *
     * Reset a student/parent Firebase Auth password on behalf of the user.
     * Sets must_change_password=true so the Parent app gates first launch
     * after reset and forces a self-chosen password.
     *
     */
    public function reset_password()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_reset_password', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $user_id      = trim((string) $this->input->post('user_id', TRUE));
        $new_password = (string) $this->input->post('new_password', FALSE);

        if ($user_id === '' || !$this->safe_path_segment($user_id)) {
            return $this->json_error('Invalid user id.');
        }
        if ($new_password === '') {
            return $this->json_error('New password is required.');
        }
        if (strlen($new_password) < 8 || strlen($new_password) > 72) {
            return $this->json_error('Password must be 8–72 characters.');
        }
        if (!preg_match('/[A-Z]/', $new_password)
            || !preg_match('/[a-z]/', $new_password)
            || !preg_match('/[0-9]/', $new_password)) {
            return $this->json_error('Password must contain an uppercase letter, a lowercase letter, and a digit.');
        }

        // Tenant check — student must belong to the current school.
        $student = $this->_getStudent($user_id);
        if (empty($student)) {
            return $this->json_error('Student not found.', 404);
        }
        $studentSchool = (string) ($student['schoolId'] ?? $student['School_id'] ?? '');
        if ($studentSchool !== $this->school_id) {
            log_message('error',
                "RBAC tenant breach attempt: admin={$this->admin_id} school={$this->school_id} "
                . "tried to reset student={$user_id} of school={$studentSchool}"
            );
            return $this->json_error('Student not found in your school.', 404);
        }

        $name = (string) ($student['Name'] ?? $student['name'] ?? $user_id);

        // 1. Update Firebase Auth password.
        $updated = $this->firebase->updateFirebaseUser($user_id, ['password' => $new_password]);
        if ($updated === null) {
            // Self-heal: a student can have a Firestore/RTDB record but no
            // Firebase Auth account (never created at admission, or later
            // deleted). In that case updateUser throws "no user record" and the
            // reset would dead-end. Detect the missing account and create it now
            // with uid = studentId + the requested password, so the reset
            // succeeds. A genuine failure on an EXISTING account still errors.
            if ($this->firebase->getFirebaseUser($user_id) === null) {
                $authEmail = Firebase::authEmail($user_id);
                $created   = $this->firebase->createFirebaseUser($authEmail, $new_password, [
                    'uid'         => $user_id,
                    'displayName' => $name,
                ]);
                if ($created === null) {
                    log_message('error', "Sis::reset_password self-heal create failed for {$user_id}");
                    return $this->json_error('Failed to update Firebase Auth password.');
                }
                log_message('info', "Sis::reset_password self-healed missing Auth account for {$user_id}");
            } else {
                return $this->json_error('Failed to update Firebase Auth password.');
            }
        }

        // 2. Set must-change-password claim — Parent app gates on this.
        $this->firebase->setCanonicalClaims($user_id, [
            'role'          => 'Parent',
            'school_id'     => $this->school_id,
            'school_code'   => $this->school_code,
            'parent_db_key' => $this->parent_db_key,
            // Parent logs in AS the student — auth.uid == studentId. Emit the
            // student identity claims the Parent app's red-flag listener needs.
            'student_id'    => $user_id,
            'extra'         => [
                'must_change_password' => true,
                'password_reset_at'    => time(),
                'password_reset_by'    => (string) ($this->admin_id ?? ''),
            ],
        ]);

        // 3. Revoke refresh tokens — kicks active app sessions.
        $this->firebase->revokeRefreshTokens($user_id);

        // 4. Mirror flag to Firestore students doc — the Parent app reads
        //    this field to trigger its force-change-password screen.
        try {
            $this->fs->set('students', $this->fs->docId($user_id), [
                'mustChangePassword' => true,
                'updatedAt'          => date('c'),
            ], true);
        } catch (\Exception $e) {
            log_message('error', 'Sis::reset_password Firestore mustChange write failed: ' . $e->getMessage());
        }

        log_audit('SIS', 'reset_password', $user_id, "Password reset for '{$name}'");

        return $this->json_success([
            'message' => "Password reset for '{$name}'. They will be required to change it on next login.",
            'user_id' => $user_id,
            'name'    => $name,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       DOCUMENTS
    ══════════════════════════════════════════════════════════════════════ */

    public function documents($userId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_documents', 'SIS', 'view');
        if (empty($userId) || !$this->safe_path_segment($userId)) show_404();

        $school_id = $this->parent_db_key;
        $student = $this->_getStudent($userId);
        if (empty($student)) show_404();

        $data['student'] = $student;

        $this->load->view('include/header');
        $this->load->view('sis/documents', $data);
        $this->load->view('include/footer');
    }

    public function upload_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_upload_doc', 'SIS', 'edit');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id = $this->parent_db_key;
        $userId    = trim($this->input->post('user_id'));
        $docLabel  = trim($this->input->post('doc_label'));

        // Sanitize label — Firebase keys cannot contain . $ # [ ] /
        $docLabel = trim(preg_replace('/[.\$#\[\]\/]/', '_', $docLabel));

        if (empty($userId) || empty($docLabel)) {
            return $this->json_error('User ID and document label are required.');
        }
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');

        if (empty($_FILES['document']['name'])) {
            return $this->json_error('No file uploaded.');
        }

        // ── Fix 1: Extension whitelist ────────────────────────────────────
        $allowedExt  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
        $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $ext  = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        // M-03 FIX: Use finfo for server-side MIME detection (don't trust client-supplied type)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['document']['tmp_name']);

        if (!in_array($ext, $allowedExt, true)) {
            return $this->json_error('Invalid file type. Allowed: JPG, PNG, GIF, WebP, PDF.');
        }
        if (!in_array($mime, $allowedMime, true)) {
            return $this->json_error('Invalid MIME type for uploaded file.');
        }
        if ($_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            return $this->json_error('File upload error (code ' . $_FILES['document']['error'] . ').');
        }

        // SIS Wave-3 DM6 (2026-05-31): per-student quota check. Replaces the
        // hardcoded 5 MB per-file check with config-driven per-file + aggregate
        // size + doc-count caps. Per-school override at
        // schools.{schoolId}.documentQuota takes precedence over defaults
        // (see application/config/sis_document_quota.php). Surface the quota
        // reason to the operator so they know exactly what limit was hit.
        $fileBytes = (int) $_FILES['document']['size'];
        $quota = $this->_check_doc_quota($userId, $fileBytes);
        if (!$quota['ok']) {
            return $this->json_error($quota['reason']);
        }

        // Canonical Storage scheme: schools/{schoolId}/students/{userId}/documents/...
        // matches _uploadStudentFile() so admission-time and later doc uploads share
        // ONE tree. Keyed by school_id (SCH_XXXXXX), not parent_db_key (which can be a
        // login code). Was: Students/{parent_db_key}/{userId}/docs/{label}.
        $storagePath = "schools/{$this->school_id}/students/{$userId}/documents/{$docLabel}";

        // ─────────────────────────────────────────────────────────────────
        // Storage-orphan fix (2026-05-30): delete the previous Storage
        // object before uploading the replacement. Mirrors the proven
        // edit_student@3434-3435 pattern via _deleteOldStorageFile. Closes
        // the cross-path orphan case where the prior upload landed at the
        // admission path (_uploadStudentFile: {school}/Students/{class}/...)
        // and the re-upload lands here at the deterministic docs path;
        // without this delete, the admission file lingers. Same-path
        // re-uploads remain correct (delete-then-write is idempotent).
        // ─────────────────────────────────────────────────────────────────
        $priorStudentDoc = $this->_getStudent($userId);
        $priorDocNode    = is_array($priorStudentDoc['documents'][$docLabel] ?? null)
            ? $priorStudentDoc['documents'][$docLabel]
            : (is_array($priorStudentDoc['Doc'][$docLabel] ?? null)
                ? $priorStudentDoc['Doc'][$docLabel]
                : []);

        // HARDENING (Objective 1/4) — the legacy label-keyed uploader must not
        // clobber a Canonical Document Service slot: doing so would raw-delete a
        // service object and diverge the Doc[] map from documentRefs. If this
        // label already holds a managed document, refuse and direct the caller to
        // the managed uploader (the Documents page). The service is the single
        // authority for managed-document bytes + lifecycle.
        if (is_array($priorDocNode) && !empty($priorDocNode['documentId'])) {
            return $this->json_error('This document is managed by the Document Service — use the Documents page to replace it.');
        }

        if (!empty($priorDocNode)) {
            $this->_deleteOldStorageFile($priorDocNode);
        }

        try {
            // FIXED: args were swapped (localPath, remotePath) and return is bool not URL
            $uploaded = $this->firebase->uploadFile($_FILES['document']['tmp_name'], $storagePath);
            if (!$uploaded) {
                return $this->json_error('Failed to upload file to storage.');
            }
            $url      = $this->firebase->getDownloadUrl($storagePath);
            $thumbUrl = '';
            // FIXED: use validated $mime instead of untrusted $_FILES type
            if (strpos($mime, 'image/') === 0) {
                $thumbUrl = $url;
            }

            // R1: write to the CANONICAL document map keys (documents + Doc) via
            // read-modify-write — NOT the dotted "doc.{label}" literal field, which
            // no reader sees (normalizer maps only documents<->Doc) and the REST
            // client mis-encodes. Mirrors delete_document() + edit_student()'s
            // Doc->documents mirror.
            $studentDoc = $this->_getStudent($userId);
            $docMap = is_array($studentDoc['documents'] ?? null) ? $studentDoc['documents']
                    : (is_array($studentDoc['Doc'] ?? null) ? $studentDoc['Doc'] : []);
            // SIS Wave-3 DM6 (2026-05-31): record `bytes` so future quota
            // aggregations sum accurately. Additive — readers that don't
            // care about size ignore the field; grandfathered docs without
            // bytes continue to render normally.
            $docMap[$docLabel] = [
                'url'         => $url,
                'thumbnail'   => $thumbUrl,
                'uploaded_at' => date('Y-m-d H:i:s'),
                'bytes'       => $fileBytes,
            ];
            $ok = $this->fs->updateEntity('students', $userId, ['documents' => $docMap, 'Doc' => $docMap]);
            // R2: do not report success if the persistence write failed.
            if (!$ok) {
                return $this->json_error('File uploaded to storage, but saving the document record failed. Please retry.');
            }

            $this->_log_history($school_id, $userId, 'DOCUMENT_UPLOAD',
                "Document uploaded: {$docLabel}", ['doc_label' => $docLabel]
            );

            return $this->json_success(['message' => 'Document uploaded.', 'url' => $url]);
        } catch (\Exception $e) {
            return $this->json_error('Upload failed: ' . $e->getMessage());
        }
    }

    public function delete_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_delete_doc', 'SIS', 'manage');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id = $this->parent_db_key;
        $userId    = trim($this->input->post('user_id'));
        $docLabel  = trim($this->input->post('doc_label'));

        // Sanitize label — same as upload_document()
        $docLabel = trim(preg_replace('/[.\$#\[\]\/]/', '_', $docLabel));

        if (empty($userId) || empty($docLabel)) {
            return $this->json_error('User ID and doc label required.');
        }
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');

        // Remove document entry from student's documents map.
        // Phase 1 (2026-04-08): canonical key is `documents` (camelCase). The
        // legacy capitalised `Doc` key was the second leak alongside `Status`
        // — see memory/firestore_class_section_canonical.md.
        $studentDoc = $this->_getStudent($userId);
        $docMap = $studentDoc['documents'] ?? $studentDoc['Doc'] ?? [];

        $oldDocNode = $docMap[$docLabel] ?? [];

        // ─────────────────────────────────────────────────────────────────
        // HARDENING (Objective 1) — the legacy label-keyed delete must NEVER
        // touch a Canonical Document Service object. There is exactly ONE
        // authoritative deletion lifecycle for managed documents.
        //   (a) Node carries a `documentId` → it is a service-managed document:
        //       route through the service lifecycle (unlink + reference-counted
        //       purge + audit). No raw Storage delete, no bypass.
        //   (b) Node has no marker but its bytes live under the service prefix
        //       `documents/{schoolId}/…` (e.g. an admission document carried onto
        //       the student at enrollment, still referenced by the application):
        //       drop ONLY the student's display reference — never raw-delete a
        //       shared service object (that would break the admission record, W4).
        //   (c) A genuine legacy student-owned object → the historical raw delete.
        // ─────────────────────────────────────────────────────────────────
        $managedId = is_array($oldDocNode) ? trim((string) ($oldDocNode['documentId'] ?? '')) : '';
        if ($managedId !== '') {
            return $this->_delete_managed_student_document($userId, $managedId);
        }
        $svcPath = is_array($oldDocNode) ? $this->_extractStoragePathFromUrl((string) ($oldDocNode['url'] ?? '')) : '';
        if ($svcPath !== '' && strpos($svcPath, 'documents/' . $this->school_name . '/') === 0) {
            unset($docMap[$docLabel]);
            $this->fs->updateEntity('students', $userId, ['documents' => $docMap, 'Doc' => $docMap]);
            $this->_log_history($this->school_id, $userId, 'DOCUMENT_DELETE',
                "Document reference removed: {$docLabel}",
                ['doc_label' => $docLabel, 'note' => 'service-owned object retained (may be shared)']);
            return $this->json_success(['message' => 'Document removed.']);
        }

        // ─────────────────────────────────────────────────────────────────
        // (c) Genuine legacy object. Storage-orphan fix (2026-05-30): delete the
        // Storage object BEFORE removing the Firestore reference (mirrors the
        // edit_student@3435 pattern via _deleteOldStorageFile).
        // ─────────────────────────────────────────────────────────────────
        if (is_array($oldDocNode) && !empty($oldDocNode)) {
            $this->_deleteOldStorageFile($oldDocNode);
        }

        unset($docMap[$docLabel]);
        // R4: keep BOTH canonical keys in sync (upload now dual-writes documents+Doc).
        $ok = $this->fs->updateEntity('students', $userId, ['documents' => $docMap, 'Doc' => $docMap]);
        if (!$ok) {
            return $this->json_error('Could not update the document record. Please retry.');
        }

        $this->_log_history($school_id, $userId, 'DOCUMENT_DELETE',
            "Document deleted: {$docLabel}", ['doc_label' => $docLabel]
        );

        return $this->json_success(['message' => 'Document deleted.']);
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT DOCUMENTS — Canonical Document Service consumer (Phase 2)

       The Student "Documents" experience is the first production consumer of the
       (frozen) Document Service. New uploads become canonical documents
       (id-addressed, verified, versioned, reference-counted, audited) linked to
       the student; a denormalized `documentRefs[]` pointer on the student drives
       the admin UI, while the legacy `documents`/`Doc` map is kept in sync so the
       Parent/Teacher apps keep displaying documents with NO app change. Existing
       students' legacy `Doc[]` entries render read-only (view/download) for full
       backward compatibility — no migration. The Document Service, its APIs,
       schema, config, and concurrency model are NOT modified.
    ══════════════════════════════════════════════════════════════════════ */

    /** Student document types (reuse the frozen document_service config types; no 'photo' — profile pic is separate). */
    private function _student_doc_types(): array
    {
        return ['aadhaar', 'birth_certificate', 'transfer_certificate', 'marksheet', 'medical_certificate', 'additional'];
    }

    /** Display label for a student document type (mirrors the frozen document_service config labels). */
    private function _student_doc_label(string $type, string $custom = ''): string
    {
        $map = [
            'aadhaar'              => 'Aadhaar Card',
            'birth_certificate'    => 'Birth Certificate',
            'transfer_certificate' => 'Transfer Certificate',
            'marksheet'            => 'Marksheet',
            'medical_certificate'  => 'Medical Certificate',
        ];
        if (isset($map[$type])) return $map[$type];
        $c = trim(preg_replace('/[.\$#\[\]\/]/', '_', $custom));
        return $c !== '' ? substr($c, 0, 60) : 'Document';
    }

    /** Ownership guard — the documentId must be referenced by THIS student. */
    private function _student_doc_owned(array $student, string $documentId): bool
    {
        if ($documentId === '') return false;
        foreach ((array) ($student['documentRefs'] ?? []) as $r) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $documentId) return true;
        }
        return false;
    }

    /**
     * Upsert a document pointer onto the student: the `documentRefs[]` entry (the
     * admin-UI lifecycle source) AND the legacy `documents`/`Doc` map keyed by
     * label (Parent/Teacher app compatibility). Read-modify-write; every field is
     * a merge so no other student field is touched.
     */
    private function _student_sync_docref(string $userId, array $doc, string $label, string $type, int $bytes): void
    {
        $student = $this->_getStudent($userId) ?? [];
        $docId   = (string) ($doc['documentId'] ?? '');
        if ($docId === '') return;

        $ref = [
            'documentId'         => $docId,
            'type'               => $type,
            'role'               => $type,
            'label'              => $label,
            'url'                => (string) ($doc['downloadUrl'] ?? ''),
            'category'           => (string) ($doc['category'] ?? ($type === 'additional' ? 'additional' : 'predefined')),
            'verificationStatus' => (string) ($doc['verificationStatus'] ?? 'pending'),
            'version'            => (int) ($doc['version'] ?? 1),
            'uploaded_at'        => date('Y-m-d H:i:s'),
        ];
        $refs = is_array($student['documentRefs'] ?? null) ? array_values($student['documentRefs']) : [];
        $found = false;
        foreach ($refs as $i => $r) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) { $refs[$i] = $ref; $found = true; break; }
        }
        if (!$found) $refs[] = $ref;

        // Legacy map (documents + Doc), keyed by unique label — app compatibility.
        $map = is_array($student['documents'] ?? null) ? $student['documents']
             : (is_array($student['Doc'] ?? null) ? $student['Doc'] : []);
        $key = $label;
        if (!$found) {
            // Reuse an EMPTY placeholder slot of the same label (fill it) — or the
            // same document — instead of creating a "Label (2)" duplicate. Only
            // suffix when a DIFFERENT real document already holds that label.
            $n = 2;
            while (isset($map[$key])) {
                $ex    = $map[$key];
                $exId  = is_array($ex) ? (string) ($ex['documentId'] ?? '') : '';
                $exUrl = is_array($ex) ? (string) ($ex['url'] ?? '') : (string) $ex;
                if ($exId === $docId || ($exId === '' && $exUrl === '')) break; // same doc, or empty slot → use this key
                $key = $label . ' (' . $n++ . ')';
            }
        } else {
            // replace: reuse whatever label key currently points at this documentId
            foreach ($map as $k => $v) { if (is_array($v) && (string) ($v['documentId'] ?? '') === $docId) { $key = $k; break; } }
        }
        $thumb = strpos((string) ($doc['mime'] ?? ''), 'image/') === 0 ? (string) ($doc['downloadUrl'] ?? '') : '';
        $map[$key] = [
            'url'         => (string) ($doc['downloadUrl'] ?? ''),
            'thumbnail'   => $thumb,
            'uploaded_at' => date('Y-m-d H:i:s'),
            'bytes'       => $bytes,
            'documentId'  => $docId,
        ];
        $this->fs->updateEntity('students', $userId, ['documentRefs' => $refs, 'documents' => $map, 'Doc' => $map]);
    }

    /**
     * POST — upload a student document through the Document Service. Predefined
     * types are single-slot: re-uploading an existing type REPLACES it (new
     * version). `additional` always adds a new document. Per-student quota is
     * still enforced (existing business rule; not storage logic).
     */
    public function student_upload_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_upload_doc');
        if ($this->input->method() !== 'post') return $this->json_error('POST required');

        $userId = trim((string) $this->input->post('user_id'));
        $type   = trim((string) $this->input->post('doc_type'));
        $custom = trim((string) $this->input->post('doc_label'));
        if ($userId === '' || !$this->safe_path_segment($userId))   return $this->json_error('Invalid User ID.');
        if (!in_array($type, $this->_student_doc_types(), true))     return $this->json_error('Invalid document type.');
        if (empty($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->json_error('Please choose a file to upload.');
        }
        $student = $this->_getStudent($userId);
        if (empty($student)) return $this->json_error('Student not found.');

        // Reuse the existing per-student quota (business rule; not storage logic).
        $fileBytes = (int) ($_FILES['document']['size'] ?? 0);
        $quota = $this->_check_doc_quota($userId, $fileBytes);
        if (!$quota['ok']) return $this->json_error($quota['reason']);

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        $actor = (string) ($this->admin_id ?? 'system');
        $label = $this->_student_doc_label($type, $custom);
        $tmp   = (string) $_FILES['document']['tmp_name'];
        $orig  = (string) ($_FILES['document']['name'] ?? 'file');

        // Predefined single-slot: replace the existing document of this type.
        $existingId = '';
        if ($type !== 'additional') {
            foreach ((array) ($student['documentRefs'] ?? []) as $r) {
                if (is_array($r) && (string) ($r['type'] ?? '') === $type) { $existingId = (string) ($r['documentId'] ?? ''); break; }
            }
        }

        if ($existingId !== '') {
            $res = $this->docsvc->replace($existingId, $tmp, $orig, $actor);
            if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Upload failed.');
            $this->_student_sync_docref($userId, $res['document'], $label, $type, $fileBytes);
            $this->_log_history($this->school_id, $userId, 'DOCUMENT_REPLACE', "Document replaced: {$label}", ['documentId' => $existingId, 'type' => $type]);
            return $this->json_success(['message' => 'Document updated.', 'documentId' => $existingId, 'url' => $res['document']['downloadUrl'] ?? '']);
        }

        $res = $this->docsvc->upload($tmp, $orig, $type, ['uploadedBy' => $actor, 'label' => $label]);
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Upload failed.');
        $doc = $res['document'];
        $this->docsvc->link($doc['documentId'], 'student', $userId, $type, $actor);
        $this->_student_sync_docref($userId, $doc, $label, $type, $fileBytes);
        $this->_log_history($this->school_id, $userId, 'DOCUMENT_UPLOAD', "Document uploaded: {$label}", ['documentId' => $doc['documentId'], 'type' => $type]);
        return $this->json_success(['message' => 'Document uploaded.', 'documentId' => $doc['documentId'], 'url' => $doc['downloadUrl'] ?? '']);
    }

    /** POST — set a student document's verification status (verified|rejected|pending). */
    public function verify_student_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_upload_doc');
        $userId = trim((string) $this->input->post('user_id'));
        $docId  = trim((string) $this->input->post('document_id'));
        $status = trim((string) $this->input->post('status'));
        if ($userId === '' || !$this->safe_path_segment($userId))       return $this->json_error('Invalid User ID.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId))                    return $this->json_error('Invalid document id.');
        if (!in_array($status, ['verified', 'rejected', 'pending'], true)) return $this->json_error('Invalid status.');
        $student = $this->_getStudent($userId);
        if (empty($student))                          return $this->json_error('Student not found.');
        if (!$this->_student_doc_owned($student, $docId)) return $this->json_error('Document does not belong to this student.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        $res = $this->docsvc->verify($docId, $status, (string) ($this->admin_id ?? 'system'));
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Verification failed.');

        $refs = array_map(function ($r) use ($docId, $status) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) $r['verificationStatus'] = $status;
            return $r;
        }, (array) ($student['documentRefs'] ?? []));
        $this->fs->updateEntity('students', $userId, ['documentRefs' => array_values($refs)]);
        $this->_log_history($this->school_id, $userId, 'DOCUMENT_VERIFY', "Document {$docId} marked {$status}", ['documentId' => $docId, 'status' => $status]);
        // NOTE: do NOT return a 'status' key — json_success() already sets
        // status:'success', and a data 'status' would overwrite it (making the
        // frontend think the call failed). Return the value under a distinct key.
        return $this->json_success(['verificationStatus' => $status]);
    }

    /** POST (multipart) — replace a student document's bytes (new version, old retired). */
    public function replace_student_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_upload_doc');
        $userId = trim((string) $this->input->post('user_id'));
        $docId  = trim((string) $this->input->post('document_id'));
        if ($userId === '' || !$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId))             return $this->json_error('Invalid document id.');
        if (empty($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->json_error('Please choose a replacement file.');
        }
        $student = $this->_getStudent($userId);
        if (empty($student))                          return $this->json_error('Student not found.');
        if (!$this->_student_doc_owned($student, $docId)) return $this->json_error('Document does not belong to this student.');

        $fileBytes = (int) ($_FILES['document']['size'] ?? 0);
        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        $res = $this->docsvc->replace($docId, (string) $_FILES['document']['tmp_name'], (string) ($_FILES['document']['name'] ?? 'file'), (string) ($this->admin_id ?? 'system'));
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Replace failed.');

        $label = ''; $type = 'additional';
        foreach ((array) ($student['documentRefs'] ?? []) as $r) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) { $label = (string) ($r['label'] ?? ''); $type = (string) ($r['type'] ?? 'additional'); break; }
        }
        if ($label === '') $label = $this->_student_doc_label($type);
        $this->_student_sync_docref($userId, $res['document'], $label, $type, $fileBytes);
        $this->_log_history($this->school_id, $userId, 'DOCUMENT_REPLACE', "Document {$docId} replaced", ['documentId' => $docId]);
        return $this->json_success(['url' => $res['document']['downloadUrl'] ?? '', 'version' => $res['document']['version'] ?? 2]);
    }

    /**
     * The SINGLE authoritative deletion lifecycle for a service-managed student
     * document. Ownership-guarded → `docsvc->delete('student', …)` (unlink;
     * reference-counted purge only if orphaned & retention-eligible — a document
     * still linked elsewhere, e.g. its admission application, is never destroyed)
     * → strip the student's documentRefs pointer + legacy map entry → audit.
     * Reused by both the managed endpoint and the hardened legacy endpoint so no
     * managed document can ever bypass the Document Service lifecycle.
     */
    private function _delete_managed_student_document(string $userId, string $docId)
    {
        $student = $this->_getStudent($userId);
        if (empty($student))                              return $this->json_error('Student not found.');
        if (!$this->_student_doc_owned($student, $docId)) return $this->json_error('Document does not belong to this student.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        $res = $this->docsvc->delete($docId, 'student', $userId, (string) ($this->admin_id ?? 'system'), false);
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Delete failed.');

        $refs = [];
        foreach ((array) ($student['documentRefs'] ?? []) as $r) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) continue;
            $refs[] = $r;
        }
        $map = is_array($student['documents'] ?? null) ? $student['documents']
             : (is_array($student['Doc'] ?? null) ? $student['Doc'] : []);
        foreach ($map as $k => $v) {
            if (is_array($v) && (string) ($v['documentId'] ?? '') === $docId) unset($map[$k]);
        }
        $this->fs->updateEntity('students', $userId, ['documentRefs' => array_values($refs), 'documents' => $map, 'Doc' => $map]);
        $this->_log_history($this->school_id, $userId, 'DOCUMENT_DELETE', 'Document ' . $docId . ' deleted (purged=' . (!empty($res['purged']) ? 'yes' : 'no') . ')', ['documentId' => $docId, 'purged' => !empty($res['purged'])]);
        return $this->json_success(['purged' => !empty($res['purged'])]);
    }

    /** POST — delete a service-managed student document (via the single authoritative lifecycle). */
    public function delete_student_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_delete_doc');
        $userId = trim((string) $this->input->post('user_id'));
        $docId  = trim((string) $this->input->post('document_id'));
        if ($userId === '' || !$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId))             return $this->json_error('Invalid document id.');
        return $this->_delete_managed_student_document($userId, $docId);
    }

    /** GET/POST — the document audit trail for a student (DOCUMENT_* events from studentHistory; no new index). */
    public function student_document_audit()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_documents');
        $userId = trim((string) $this->input->get_post('user_id'));
        if ($userId === '' || !$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');
        $trail = [];
        try {
            $rows = $this->firebase->firestoreQuery('studentHistory', [
                ['schoolId',  '==', $this->school_id],
                ['studentId', '==', $userId],
            ], 'changed_at', 'DESC', 500);
            foreach ((array) $rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                if (!empty($d) && strpos((string) ($d['action'] ?? ''), 'DOCUMENT_') === 0) $trail[] = $d;
            }
        } catch (\Throwable $e) {
            log_message('error', "Sis::student_document_audit query failed for {$userId}: " . $e->getMessage());
        }
        return $this->json_success(['trail' => $trail]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       HISTORY
    ══════════════════════════════════════════════════════════════════════ */

    public function history($userId = null)
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_history', 'SIS', 'view');
        if (empty($userId) || !$this->safe_path_segment($userId)) show_404();

        $student = $this->_getStudent($userId);
        if (empty($student)) show_404();

        // History Canonicalization (2026-06-02): read from canonical
        // studentHistory collection (firestoreQuery composite index:
        // schoolId ASC + studentId ASC + changed_at DESC). Replaces
        // legacy students.{id}.History map read which is now retired
        // (writer cutover this same commit).
        $history = [];
        try {
            $rows = $this->firebase->firestoreQuery('studentHistory', [
                ['schoolId',  '==', $this->school_id],
                ['studentId', '==', $userId],
            ], 'changed_at', 'DESC', 5000);
            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                if (!empty($d)) $history[] = $d;
            }
        } catch (\Throwable $e) {
            log_message('error', "Sis::history studentHistory query failed for {$userId}: " . $e->getMessage());
        }

        $data['student'] = $student;
        $data['history'] = $history;

        $this->load->view('include/header');
        $this->load->view('sis/history', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       ID CARD
    ══════════════════════════════════════════════════════════════════════ */

    public function id_card()
    {
        $this->_require_role(self::VIEW_ROLES, 'id_card', 'SIS', 'view');
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        // Active students from the cached snapshot (one Firestore read per 90s,
        // busted on write). The status=='Active' gate that used to be a
        // Firestore `where` is applied in PHP — checking both field casings,
        // so it's strictly more tolerant than the old camelCase/TitleCase pair.
        $allStudentDocs = $this->_cached_student_docs();
        $allStudents = [];
        foreach ($allStudentDocs as $doc) {
            $d = $doc['data'] ?? $doc;
            $rowStatus = (string) ($d['status'] ?? $d['Status'] ?? 'Active');
            if (strcasecmp($rowStatus, 'Active') !== 0) continue;
            $s = $this->_normalizeStudentDoc($d);
            if (!$s) continue;
            $uid = $s['User Id'] ?? $s['studentId'] ?? $d['id'];
            $s['User Id'] = $uid;
            // SRNO-4 / SRNO-5 (Phase 19F) — carry the two PERMANENT
            // school-facing identifiers through to the view. The normalizer
            // does not surface admissionNo, so without this the card could
            // not print it and the operator could not search by it. Read from
            // the RAW doc so both field casings are tolerated.
            $s['srNo']        = trim((string) ($d['srNo'] ?? $d['srNumber'] ?? $d['SR Number'] ?? ($s['srNo'] ?? '')));
            $s['admissionNo'] = trim((string) ($d['admissionNo'] ?? $d['admission_no'] ?? ''));
            $allStudents[$uid] = $s;
        }

        // Enrolled IDs from Firestore
        $enrolledIds = $this->_get_enrolled_ids();

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

        // Fetch school profile for display
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $profile = is_array($schoolDoc) ? $schoolDoc : [];

        // ── ID-MINT-1 (Phase 19K) — the printed card must carry the credential
        // the card RECORD says the student holds.
        //
        // Until now the view always minted a V1 token (qr_token_encode), while
        // Id_card_service::issue() recorded a versioned V2 card. The two paths
        // had silently diverged: an operator could "reissue" a student, see
        // card v2 in the register, and print a card whose QR was still an
        // un-versioned V1 token. migrationReadiness() would then report that
        // every student holds a managed card — "safe to enforce" — while not a
        // single physical card in the school was actually V2. The migration
        // could never have completed, and the failure was invisible.
        //
        // One query for the whole school (the same register the panel loads),
        // passed as {studentId: version}. Students with no managed card get 0
        // and keep the V1 mint, which is correct: there is no card version to
        // name, and V1 remains accepted.
        $cardVersions = [];
        try {
            // Hoisted deliberately. This happened to work inside the foreach
            // expression, but relying on evaluation order to make a static
            // reference legal is exactly how the id_card_issue() fatal
            // happened. Load first, then use statics.
            $svc = $this->_idCardSvc();
            foreach ($svc->registerForSchool() as $sid => $card) {
                if ((string) ($card['status'] ?? '') === Id_card_service::STATUS_ACTIVE) {
                    $cardVersions[$sid] = (int) ($card['currentVersion'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            // Degrade to V1 minting rather than printing no QR at all — a V1
            // card still scans everywhere today.
            log_message('error', 'Sis::id_card card-version load failed: ' . $e->getMessage());
        }

        $data['card_versions']  = $cardVersions;
        $data['students']       = $students;
        $data['session_year']   = $session_year;
        $data['school_name']    = $school_name;
        $data['school_profile'] = [
            'school_name' => $profile['name'] ?? $profile['display_name'] ?? $this->school_display_name ?? '',
            'address'     => $profile['address'] ?? $profile['city'] ?? '',
            'logo'        => $profile['logoUrl'] ?? $profile['logo_url'] ?? $profile['logo'] ?? '',
            'phone'       => $profile['phone'] ?? '',
        ];

        $this->load->view('include/header');
        $this->load->view('sis/id_card', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       ID CARD — OPERATIONAL LIFECYCLE (Phase 19F)
       ──────────────────────────────────────────────────────────────────────
       Before this, ZenX could only PRINT cards. These endpoints expose the
       Id_card_service lifecycle built in 19E so an operator can actually
       issue, replace and revoke a specific card.

       AUTHORIZATION — mutations use `_require_role([], …, 'SIS', 'manage')`.
       The EMPTY allow-list is deliberate: it removes the legacy role-name
       fallback entirely, so the graded SIS capability is the sole authority
       (the pattern Entitlements.php established). Printing/reading stays at
       'view' — an operator who may print a class set must not thereby be able
       to revoke a credential. Configuration/manage grants nothing here: this
       is a student-lifecycle operation, not a settings one.

       CSRF — these are `sis/*` POSTs and are NOT in `csrf_exclude_uris`, so
       CI's global CSRF applies. That is correct and intentional; the panel JS
       sends the token from the header meta tags, the same pattern
       sis/promote.php and sis/documents.php already use. `csrf_regenerate` is
       FALSE, so one token serves repeated AJAX calls.
    ══════════════════════════════════════════════════════════════════════ */

    /** Lazily build the card service, bound to this tenant + actor. */
    private function _idCardSvc()
    {
        $this->load->library('Id_card_service', null, 'idCardSvc');
        if (!$this->idCardSvc->isReady()) {
            $this->idCardSvc->init($this->firebase, $this->fs, $this->school_id, (string) $this->admin_id);
        }
        return $this->idCardSvc;
    }

    /**
     * Identity fields denormalised onto a card record. Read from the RAW
     * student doc so both field casings are tolerated.
     *
     * Roll No is deliberately NOT included — it is session/class-scoped and
     * reassigned on promotion, so it must never become card identity.
     */
    private function _idCardIdentity(array $stu): array
    {
        return [
            'srNo'        => trim((string) ($stu['srNo'] ?? $stu['srNumber'] ?? $stu['SR Number'] ?? '')),
            'admissionNo' => trim((string) ($stu['admissionNo'] ?? $stu['admission_no'] ?? '')),
            'studentName' => (string) ($stu['Name'] ?? $stu['name'] ?? ''),
            'className'   => (string) ($stu['Class'] ?? $stu['className'] ?? ''),
            'section'     => (string) ($stu['Section'] ?? $stu['section'] ?? ''),
        ];
    }

    /**
     * The whole school's card register — ONE Firestore query for any number
     * of students (never one read per student). The panel joins it against
     * the already-rendered student rows client-side.
     */
    public function id_card_register()
    {
        $this->_require_role(self::VIEW_ROLES, 'id_card_register', 'SIS', 'view');
        try {
            return $this->json_success(['cards' => $this->_idCardSvc()->registerForSchool()]);
        } catch (\Throwable $e) {
            log_message('error', 'Sis::id_card_register failed: ' . $e->getMessage());
            return $this->json_error('Could not load the card register.');
        }
    }

    /**
     * Issue a card — the first one, or a replacement.
     *
     * The service decides which: a student with no card may only receive
     * `first_issue`, and a student who already holds one may not. That guard
     * lives in the service so it cannot be bypassed by a second caller.
     */
    public function id_card_issue()
    {
        $this->_require_role([], 'id_card_issue', 'SIS', 'manage');

        // CI3 has NO autoloader for libraries: a bare `Id_card_service::…`
        // static reference fatals with "Class not found" unless the library
        // has already been loaded. Building the service FIRST is what makes
        // every static below safe — it is not an incidental ordering.
        // (Runtime fatal, Phase 19M: validReasons() was called before this.)
        $svc = $this->_idCardSvc();

        $studentId = trim((string) $this->input->post('student_id'));
        $reason    = trim((string) $this->input->post('reason'));
        if ($studentId === '') return $this->json_error('Student is required.');
        if (!in_array($reason, Id_card_service::validReasons(), true)) {
            return $this->json_error('Select a valid reason.');
        }

        $stu = $this->fs->get('students', $this->fs->docId($studentId));
        if (!is_array($stu)) return $this->json_error('Student not found.', 404);

        // A card is a credential for a CURRENT student. Issuing one to a
        // withdrawn/TC record would hand out a credential the scanner rejects
        // anyway (scan_qr gates on status Active) — fail honestly instead.
        $status = (string) ($stu['status'] ?? $stu['Status'] ?? 'Active');
        if (strcasecmp($status, 'Active') !== 0) {
            return $this->json_error("Cannot issue a card to a student whose status is {$status}.");
        }

        try {
            // The note is CONTEXT only (e.g. an FIR reference). The canonical
            // reason stays the validated enum above — a typed note can never
            // become the reason that drives audit/reporting/fee eligibility.
            // ID-RACE-1 — the panel sends the card version it displayed. If the
            // live card has moved on (a double-submit that beat the client-side
            // guard, a retry, or a second operator), the service refuses rather
            // than minting a second card and a second replacement fee for one
            // real event. Absent/blank keeps the previous behaviour.
            $expectedRaw = $this->input->post('expected_version');
            $expected    = ($expectedRaw === null || $expectedRaw === '') ? null : (int) $expectedRaw;

            $res = $svc->issue(
                $studentId, $reason, $this->_idCardIdentity($stu),
                (string) $this->input->post('note'),
                $expected
            );
        } catch (\Throwable $e) {
            log_message('error', "Sis::id_card_issue failed for {$studentId}: " . $e->getMessage());
            return $this->json_error('Could not issue the card.');
        }

        if (empty($res['ok'])) {
            // Surface the service's actual refusal rather than a generic
            // failure — the operator needs to know WHICH rule stopped them.
            $map = [
                'card_already_issued'         => 'This student already holds a card. Use Replace instead.',
                'no_existing_card_to_replace' => 'This student has no card yet. Use Issue First Card.',
                'invalid_reason'              => 'Select a valid reason.',
                'version_claim_exhausted'     => 'Another operator is issuing this card right now. Try again.',
                // ID-RACE-1: almost always a double-submit. Say so plainly so
                // the operator does not "retry" and create a real second card.
                'version_conflict'            => 'This card has already changed — it may have just been replaced. '
                                               . 'Refresh the page and check the card status before trying again.',
            ];
            return $this->json_error($map[$res['reason'] ?? ''] ?? 'Could not issue the card.');
        }

        // ── Replacement fee (Phase 19I) ─────────────────────────────────
        // Raised AFTER the card exists and deliberately best-effort: the
        // credential is the student's identity and must never be withheld
        // because a receivable could not be written. A failure here leaves an
        // uncharged replacement — recoverable — whereas aborting would leave a
        // child without a card, which is not.
        $fee = $this->_maybe_charge_id_card_replacement($studentId, $reason, (int) $res['version']);

        return $this->json_success([
            'message'    => "Card v{$res['version']} issued."
                          . ($fee['charged']
                                ? " Replacement fee of {$fee['amount']} raised."
                                : (isset($fee['note']) ? ' ' . $fee['note'] : '')),
            'student_id' => $studentId,
            'version'    => $res['version'],
            'fee'        => $fee,
        ]);
    }

    /**
     * Raise a replacement charge through the CANONICAL fee generator, or do
     * nothing when this school's policy makes the reason free.
     *
     * Uses `Fee_lifecycle::createModuleFee()` exactly as Transport and Hostel
     * do — no second fee engine, no second balance formula. The version-scoped
     * fee head (Id_card_fee_policy) is what stops a second replacement in the
     * same session from overwriting the first demand (ID-D2); `Fee_lifecycle`
     * itself is not modified.
     *
     * Every amount defaults to ZERO, so this is inert until a school
     * deliberately configures a price.
     */
    private function _maybe_charge_id_card_replacement(string $studentId, string $reason, int $cardVersion): array
    {
        $out = ['charged' => false, 'amount' => 0.0, 'reason' => $reason, 'demandId' => null];
        try {
            $this->config->load('id_card_flags', true);
            $this->load->library('Id_card_fee_policy', null, 'idCardFeePolicy');
            $this->idCardFeePolicy->init(
                $this->fs,
                (array) $this->config->item('IDCARD_REPLACEMENT_FEE', 'id_card_flags'),
                (string) $this->config->item('IDCARD_FEE_HEAD', 'id_card_flags'),
                (string) $this->config->item('IDCARD_FEE_MODULE', 'id_card_flags')
            );

            // ID-DUE-1 — a replacement charge is a one-off with a payment
            // window, not part of the monthly cycle. Without a dueDate the
            // demand is invisible to both the reminder service and the late-fee
            // engine, so it would sit unpaid and unchased indefinitely.
            $this->idCardFeePolicy->withDueInDays(Id_card_fee_policy::DEFAULT_DUE_DAYS);

            $overrides = $this->idCardFeePolicy->schoolOverrides($this->school_id, $this->session_year);
            $payload   = $this->idCardFeePolicy->demandPayloadFor($reason, $cardVersion, $overrides);
            if ($payload === null) {
                // IDC-FREE-1 — "no charge" has two very different causes and the
                // operator needs to know which one they are looking at:
                //
                //   * the reason is never chargeable (first issue, normal wear, a
                //     correction the school made) — working as designed; or
                //   * the reason IS chargeable but this school has never set a price,
                //     so a lost card is silently replaced free.
                //
                // The second is the one that costs money quietly. Saying only "Card
                // v2 issued" let it read as a completed, charged replacement.
                if (in_array($reason, Id_card_fee_policy::chargeableReasons(), true)) {
                    $out['note'] = 'No replacement charge was raised: this school has not set '
                        . 'an amount for a ' . $reason . ' card. Set one under Replacement Charges.';
                }
                return $out;   // free under this school's policy
            }

            $demandId = $this->feeLifecycle->createModuleFee(
                $studentId, $this->idCardFeePolicy->module(), $payload
            );
            if ($demandId === null) {
                log_message('error', "Sis::_maybe_charge_id_card_replacement createModuleFee returned null for {$studentId} v{$cardVersion}");
                return $out;
            }

            $out = ['charged' => true, 'amount' => $payload['amount'], 'reason' => $reason, 'demandId' => $demandId];

            log_audit('SIS', 'id_card_replacement_fee_raised', $studentId,
                "Replacement fee raised for card v{$cardVersion} ({$reason})", [
                    'entityType' => 'idCard',
                    'meta' => [
                        'version'  => $cardVersion,
                        'reason'   => $reason,
                        'amount'   => $payload['amount'],
                        'feeHead'  => $payload['fee_head'],
                        'demandId' => $demandId,
                        'schoolId' => $this->school_id,
                    ],
                ]);
        } catch (\Throwable $e) {
            log_message('error', "Sis::_maybe_charge_id_card_replacement failed for {$studentId}: " . $e->getMessage());
        }
        return $out;
    }

    /**
     * Revoke the active card WITHOUT issuing a successor — "the card is gone
     * and the student should hold nothing". Distinct from a replacement.
     */
    public function id_card_revoke()
    {
        $this->_require_role([], 'id_card_revoke', 'SIS', 'manage');

        // Load BEFORE any static reference — see the note in id_card_issue().
        $svc = $this->_idCardSvc();

        $studentId = trim((string) $this->input->post('student_id'));
        $reason    = trim((string) $this->input->post('reason'));
        if ($studentId === '') return $this->json_error('Student is required.');
        if (!in_array($reason, Id_card_service::validReasons(), true)) {
            return $this->json_error('Select a valid reason.');
        }

        try {
            $res = $svc->revoke($studentId, $reason, (string) $this->input->post('note'));
        } catch (\Throwable $e) {
            log_message('error', "Sis::id_card_revoke failed for {$studentId}: " . $e->getMessage());
            return $this->json_error('Could not revoke the card.');
        }

        if (empty($res['ok'])) {
            $map = ['no_card' => 'This student holds no card to revoke.'];
            return $this->json_error($map[$res['reason'] ?? ''] ?? 'Could not revoke the card.');
        }

        // 'noop' means it was already out of service — report that truthfully
        // rather than claiming a revocation just happened.
        $already = (($res['reason'] ?? '') === 'noop');
        return $this->json_success([
            'message'    => $already ? 'That card was already out of service.' : "Card v{$res['version']} revoked.",
            'student_id' => $studentId,
            'version'    => $res['version'] ?? 0,
            'already'    => $already,
        ]);
    }

    /* ── ID-CFG-1 (Phase 19N) — per-school replacement charges ───────────
       Id_card_fee_policy::schoolOverrides() reads
       `feeSettings/{schoolId}_{session}_idcard`, but NOTHING ever wrote it and
       no UI existed. "Per-school configurable amounts" was therefore a dead
       read: a school could never actually set a price, so the feature could
       only ever charge the config fallback of ₹0. These two endpoints are the
       missing write path.

       RBAC — deliberately gated on **Fees:manage, not SIS:manage**. Setting
       what a family is charged is a money decision, not a student-records one.
       An office operator who may issue and replace cards must not thereby be
       able to decide what those replacements cost; that is a separation of
       duties, and it is why these two endpoints differ from every other
       endpoint in this workspace.
    ─────────────────────────────────────────────────────────────────────── */

    /** Current per-school replacement charges, merged over the config fallback. */
    public function id_card_fee_settings()
    {
        $this->_require_role([], 'id_card_fee_settings', 'Fees', 'view');
        try {
            $this->config->load('id_card_flags', true);
            $this->load->library('Id_card_fee_policy', null, 'idCardFeePolicy');
            $fallback = (array) $this->config->item('IDCARD_REPLACEMENT_FEE', 'id_card_flags');
            $this->idCardFeePolicy->init($this->fs, $fallback);

            $overrides = $this->idCardFeePolicy->schoolOverrides($this->school_id, $this->session_year);
            $out = [];
            foreach (Id_card_fee_policy::chargeableReasons() as $r) {
                $out[$r] = $this->idCardFeePolicy->amountFor($r, $overrides);
            }
            return $this->json_success([
                'amounts'    => $out,
                'configured' => $overrides !== [],
                // Named explicitly so the UI can state WHY the other reasons
                // are absent rather than looking like it forgot them.
                'never_chargeable' => ['first_issue', 'wear', 'data_correction', 'admin'],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Sis::id_card_fee_settings failed: ' . $e->getMessage());
            return $this->json_error('Could not load replacement charges.');
        }
    }

    /** Persist per-school replacement charges. */
    public function id_card_fee_settings_save()
    {
        $this->_require_role([], 'id_card_fee_settings_save', 'Fees', 'manage');

        $this->load->library('Id_card_fee_policy', null, 'idCardFeePolicy');

        $amounts = [];
        foreach (Id_card_fee_policy::chargeableReasons() as $r) {
            $raw = $this->input->post($r);
            if ($raw === null || $raw === '') { $amounts[$r] = 0.0; continue; }
            if (!is_numeric($raw)) {
                return $this->json_error('Amounts must be numeric.');
            }
            $v = (float) $raw;
            if ($v < 0) {
                return $this->json_error('An amount cannot be negative.');
            }
            // A hard ceiling. Not a policy statement — a typo guard. Nothing in
            // ZenX should silently bill a family six figures for a plastic card
            // because a zero was held down.
            if ($v > 100000) {
                return $this->json_error('That amount looks like a typo. Enter a value up to 100000.');
            }
            $amounts[$r] = round($v, 2);
        }

        try {
            $docId = "{$this->school_id}_{$this->session_year}_idcard";
            $this->fs->set('feeSettings', $docId, [
                'schoolId'         => $this->school_id,
                'session'          => $this->session_year,
                'kind'             => 'idcard',
                'replacementFees'  => $amounts,
                'updatedAt'        => date('c'),
                'updatedBy'        => (string) $this->admin_id,
            ], true);
        } catch (\Throwable $e) {
            log_message('error', 'Sis::id_card_fee_settings_save failed: ' . $e->getMessage());
            return $this->json_error('Could not save replacement charges.');
        }

        log_audit('Fees', 'id_card_replacement_fees_updated', $this->school_id,
            'ID card replacement charges updated', [
                'entityType' => 'feeSettings',
                'meta' => ['amounts' => $amounts, 'session' => $this->session_year,
                           'schoolId' => $this->school_id, 'actor' => (string) $this->admin_id],
            ]);

        return $this->json_success(['message' => 'Replacement charges saved.', 'amounts' => $amounts]);
    }

    /** Full issuance history for one student, newest first. Bounded reads. */
    public function id_card_history()
    {
        $this->_require_role(self::VIEW_ROLES, 'id_card_history', 'SIS', 'view');

        $studentId = trim((string) $this->input->post('student_id'));
        if ($studentId === '') return $this->json_error('Student is required.');

        try {
            return $this->json_success(['history' => $this->_idCardSvc()->historyFor($studentId)]);
        } catch (\Throwable $e) {
            log_message('error', "Sis::id_card_history failed for {$studentId}: " . $e->getMessage());
            return $this->json_error('Could not load card history.');
        }
    }

    /**
     * Revoke a student's active card when they leave the school.
     *
     * Called from issue_tc() and withdraw_student() — the two TERMINAL exits.
     * Deliberately NOT called for any temporary/non-terminal status change:
     * a revocation cannot be undone (only superseded by a fresh issue), so
     * revoking on a reversible state would strand the student's card.
     *
     * Best-effort by design. The student's own status is already the
     * authority the scanner enforces (scan_qr rejects non-Active), so a
     * failure here degrades to the pre-19F behaviour and must never abort the
     * withdrawal or TC itself.
     */
    private function _revoke_id_card_on_exit(string $studentId, string $context): void
    {
        try {
            $svc = $this->_idCardSvc();
            if ($svc->currentVersion($studentId) < 1) return;   // nothing to revoke
            $res = $svc->revoke($studentId, Id_card_service::REASON_ADMIN);
            if (empty($res['ok'])) {
                log_message('error', "Sis::_revoke_id_card_on_exit({$context}) refused for {$studentId}: " . ($res['reason'] ?? '?'));
            }
        } catch (\Throwable $e) {
            log_message('error', "Sis::_revoke_id_card_on_exit({$context}) failed for {$studentId}: " . $e->getMessage());
        }
    }


    /**
     * Same-origin QR endpoint — local SVG generation (no external API).
     *
     * Post Tier-A QR upgrade: backed by `chillerlan/php-qrcode`. No
     * external network dependency, works offline, scales perfectly
     * for print. Returned as `image/svg+xml` so any consumer (HTML
     * `<img>`, CSS `background-image: url(…)`, Dompdf) can embed
     * the result without further processing. The legacy `?size=`
     * parameter is accepted but no longer meaningful — SVG is vector
     * and scales to any container size.
     *
     * No auth gate — the QR is just a visual encoding of identifiers
     * that are already printed in plaintext on the same ID card,
     * AND the token now carries an HMAC signature so a printed QR
     * doesn't reveal the secret either.
     *
     * Most call sites should prefer `qr_svg_data_uri()` directly to
     * skip the HTTP round-trip entirely; this endpoint stays for
     * back-compat and any consumer that needs a real URL.
     */
    public function qr_image($token = '')
    {
        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 256) {
            show_404();
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+={0,2}$/', $token)) {
            show_404();
        }

        $this->load->helper('qr_token');
        try {
            $options = new \chillerlan\QRCode\QROptions([
                'outputType'   => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
                'outputBase64' => false,        // raw SVG bytes for `image/svg+xml`
                'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::L,
                'scale'        => 5,
                'addQuietzone' => true,
            ]);
            $svg = (new \chillerlan\QRCode\QRCode($options))->render($token);
        } catch (\Throwable $e) {
            log_message('error', 'Sis::qr_image render failed: ' . $e->getMessage());
            // Tiny inline placeholder so the layout doesn't shift.
            header('Content-Type: image/svg+xml');
            header('Cache-Control: no-store');
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';
            return;
        }

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=86400, immutable');
        header('Content-Length: ' . strlen($svg));
        echo $svg;
    }

    /**
     * One-time utility: rebuild the Students_Index from the full Users/Parents tree.
     * Call via GET: sis/rebuild_index — idempotent, safe to re-run.
     */
    public function rebuild_index()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_rebuild_index', 'SIS', 'manage');

        // In Firestore, the students collection IS the index — no separate index needed.
        $count = $this->fs->count('students', [['schoolId', '==', $this->school_id]]);

        return $this->json_success([
            'message' => 'Students index is the Firestore students collection. No rebuild needed.',
            'count'   => $count,
        ]);
    }

    /**
     * Roll Number Research phase — EXPLICIT, OPT-IN "next available Roll
     * Number" suggestion for a Class + Section + Session.
     *
     * ── WHY THIS EXISTS, AND WHY IT IS NOT THE DEFAULT ──────────────────────
     * Source-verified research (STUDENT_LIFECYCLE_ROLL_NUMBER_RESEARCH_AND_
     * IMPLEMENTATION_REPORT.md §4) found no mainstream school ERP that
     * silently auto-assigns a Roll Number as a side effect of admission.
     * Fedena requires manual entry. NextERP offers automatic generation only
     * as a SEPARATE, explicit, previewable operation the operator chooses to
     * run. ZenX's own admission form has always treated Roll No. as optional
     * manual text. Flipping that to silent-automatic-by-default would be an
     * unproven behaviour change with no supporting evidence.
     *
     * So this is a CONVENIENCE the operator opts into (a "Suggest" action),
     * never a hidden default. Manual entry remains the primary path and is
     * completely unaffected — this endpoint changes nothing about how a
     * typed Roll No. is validated or saved.
     *
     * ── POLICY: lowest available number (gap-filling) ───────────────────────
     * Chosen for register hygiene — a class register with 1..N and no
     * permanent holes reads more naturally than an ever-growing counter that
     * never reuses a withdrawn student's number. Labelled a ZenX
     * recommendation, not an industry-mandated rule — research did not
     * establish one model as standard (see report §6-7).
     *
     * ── CONCURRENCY: ATOMIC, unlike the read-then-write roll validator ──────
     * Two operators clicking "Suggest" at the same moment for the same
     * class+section+session must NEVER receive the same number. This claims
     * ownership via `firestoreCreate()` — the SAME create-if-not-exists
     * primitive `Sr_no_service::claim()` already uses — so the second
     * concurrent caller's claim on that number fails atomically and the loop
     * advances to the next candidate. This is a genuine improvement over the
     * detection-grade `_rollNoConflict()` check (Phase 14.1 §14/§21).
     *
     * The claim is PROVISIONAL: it reserves the number for this suggestion,
     * not for a specific student. `_rollNoConflict()` at actual save time
     * remains the authoritative gate — this endpoint only avoids handing out
     * an obviously-taken number.
     *
     * Cost: 1 read (shared snapshot, same cache `_rollNoConflict` uses) to
     * find candidates cheaply, then at most a handful of atomic claim writes
     * (one per collision, realistically 0-1 in normal operation). No new
     * index — claim doc ids are deterministic, so this is always a doc-id
     * operation, never a query.
     */
    /**
     * AJAX GET sis/pincode_lookup?pin=560001 — resolve an Indian PIN to its
     * State + District via the India Post public API so the admission form can
     * auto-fill the address instead of the operator hand-picking from a static
     * list. Implemented as a SERVER-SIDE PROXY (not a browser fetch) on purpose:
     *   1. the panel's CSP connect-src does not allow api.postalpincode.in, so a
     *      client fetch would be blocked;
     *   2. a proxy lets us cache the (effectively static) PIN->place mapping.
     * Fails SOFT — on any error the client just keeps the manual State/District
     * selects, so a lookup outage never blocks an admission.
     */
    public function pincode_lookup()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_pincode_lookup', 'SIS', 'view');
        $this->output->set_content_type('application/json');

        $pin = trim((string) $this->input->get('pin'));
        if (!preg_match('/^[1-9][0-9]{5}$/', $pin)) {
            return $this->json_error('Enter a valid 6-digit PIN code.');
        }

        // PIN -> place is static, so cache it on disk for 30 days to avoid
        // hammering the external API (and to answer instantly on re-entry).
        $cacheFile = APPPATH . 'cache/pincode_' . $pin . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 2592000) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['state'])) {
                $cached['source'] = 'cache';
                return $this->json_success($cached);
            }
        }

        $raw = false;
        if (function_exists('curl_init')) {
            $ch = curl_init('https://api.postalpincode.in/pincode/' . $pin);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'ZenXii-SIS/1.0',
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
        }
        if ($raw === false || $raw === '') {
            return $this->json_error('PIN lookup is unavailable right now — please pick State and District manually.');
        }

        $data = json_decode((string) $raw, true);
        $po   = (is_array($data) && isset($data[0]['PostOffice'][0]) && is_array($data[0]['PostOffice'][0]))
              ? $data[0]['PostOffice'][0] : null;
        $status = is_array($data) ? (string) ($data[0]['Status'] ?? '') : '';
        if ($status !== 'Success' || !$po) {
            return $this->json_error('No location found for PIN ' . $pin . ' — please pick manually.');
        }

        $out = [
            'pin'      => $pin,
            'state'    => trim((string) ($po['State'] ?? '')),
            'district' => trim((string) ($po['District'] ?? '')),
            'area'     => trim((string) ($po['Name'] ?? $po['Block'] ?? '')),
            'source'   => 'india_post',
        ];
        @file_put_contents($cacheFile, json_encode($out));
        return $this->json_success($out);
    }

    public function suggest_roll_no()
    {
        $this->_require_role(self::MANAGE_ROLES, 'suggest_roll_no', 'SIS', 'manage');
        $this->output->set_content_type('application/json');

        $classKey   = Firestore_service::classKey(trim($this->input->post('class') ?? ''));
        $sectionKey = Firestore_service::sectionKey(trim($this->input->post('section') ?? ''));
        if ($classKey === '' || $sectionKey === '') {
            return $this->json_error('Class and section are required.');
        }
        $session = (string) $this->session_year;

        // Collect NUMERIC rolls already active in this scope from the same
        // shared, write-invalidated snapshot _rollNoConflict() uses — so this
        // suggestion can never disagree with the authoritative check that
        // runs at save time. Non-numeric rolls (A12, B-07 …) are simply not
        // candidates for a NUMERIC suggestion and are ignored here; an
        // operator using an alphanumeric convention keeps typing manually.
        $taken = [];
        try {
            $this->load->driver('cache', ['adapter' => 'file']);
            $docs = $this->cache->get($this->fs->listCacheKey('students_raw'));
            if (!is_array($docs)) {
                $docs = $this->fs->schoolWhere('students');
                if (!is_array($docs)) $docs = [];
                $this->cache->save($this->fs->listCacheKey('students_raw'), $docs, 90);
            }
            foreach ($docs as $row) {
                $d = is_array($row) ? ($row['data'] ?? $row) : null;
                if (!is_array($d)) continue;
                $status = trim((string) ($d['status'] ?? $d['Status'] ?? 'Active'));
                if ($status !== '' && strcasecmp($status, 'Active') !== 0) continue;
                if (mb_strtolower(trim((string) ($d['className'] ?? $d['Class'] ?? ''))) !== mb_strtolower($classKey)) continue;
                if (mb_strtolower(trim((string) ($d['section'] ?? $d['Section'] ?? ''))) !== mb_strtolower($sectionKey)) continue;
                $rs = mb_strtolower(trim((string) ($d['session'] ?? '')));
                if ($rs !== '' && $rs !== mb_strtolower($session)) continue;
                $r = trim((string) ($d['rollNo'] ?? $d['Roll No'] ?? ''));
                if ($r !== '' && ctype_digit($r)) $taken[(int) $r] = true;
            }
        } catch (\Throwable $e) {
            log_message('error', 'suggest_roll_no snapshot failed: ' . $e->getMessage());
        }

        // Walk 1..N for the first free number, claiming it atomically so two
        // concurrent suggestions can never both walk away with the same one.
        $scopeTag = mb_strtolower("{$session}_{$classKey}_{$sectionKey}");
        for ($candidate = 1; $candidate <= 500; $candidate++) {
            if (isset($taken[$candidate])) continue;
            // docId() prefixes with the caller's OWN schoolId (Firestore_service,
            // set from authenticated session/claims) — so the claim document is
            // tenant-scoped by construction, same as every other identity index.
            $claimId = $this->fs->docId(md5("{$scopeTag}_{$candidate}"));
            $claimed = false;
            // ── PHASE 20.3b (P20.3-CLAIM-1) ─────────────────────────────
            // Was `(bool) firestoreCreate(...)`, where false meant EITHER
            // "this number is claimed" OR "the write failed and we do not know
            // why". The loop below then treated both as "occupied — try the
            // next number", so a single timeout or 500 silently skipped a
            // genuinely free roll number and drifted suggestions upward.
            //
            // `$claimStatus` now carries SUCCESS | COLLISION | FAILURE, and a
            // FAILURE aborts the walk instead of consuming candidates.
            $claimStatus = ['status' => 'FAILURE', 'code' => 0, 'message' => 'not attempted'];
            try {
                $claimStatus = $this->firebase->firestoreCreateStatus('rollNoClaims', $claimId, [
                    'schoolId' => $this->school_id, 'session' => $session,
                    'className' => $classKey, 'section' => $sectionKey,
                    'rollNo' => (string) $candidate, 'claimedAt' => date('c'),
                    'claimedBy' => (string) ($this->admin_id ?? 'system'),
                    // Provisional hold. See the expiry handling below: a claim
                    // is only binding for self::ROLL_CLAIM_TTL_SECONDS.
                ]);
            } catch (\Throwable $e) {
                log_message('error', "suggest_roll_no claim failed for {$candidate}: " . $e->getMessage());
                $claimStatus = ['status' => 'FAILURE', 'code' => 0, 'message' => $e->getMessage()];
            }

            // P20.3b — FAIL FAST on infrastructure trouble. A timeout, 500 or
            // auth error tells us NOTHING about whether this number is free,
            // so continuing the walk would skip candidates that were never
            // claimed. Surface the failure and let the operator retry or type
            // a number manually — the roll policy itself is unchanged.
            if (($claimStatus['status'] ?? '') === 'FAILURE') {
                log_message('error',
                    "ACC_ROLL_CLAIM_INFRA_FAILURE schoolId={$this->school_id} "
                    . "scope={$scopeTag} candidate={$candidate} "
                    . "code=" . ($claimStatus['code'] ?? 0) . " msg=" . ($claimStatus['message'] ?? ''));
                return $this->json_error(
                    'Could not reserve a Roll Number right now — the service did not respond. '
                    . 'Nothing was assigned. Please retry, or enter a Roll Number manually.'
                );
            }

            $claimed = (($claimStatus['status'] ?? '') === 'SUCCESS');

            // ── Phase 16.5 (ROLL-5) — EXPIRE ABANDONED CLAIMS ───────────
            //
            // THE DEFECT THIS CLOSES: `claimedAt` was written but NEVER READ.
            // Nothing released a claim when a suggestion was abandoned, and no
            // Firestore TTL policy exists on this collection, so an operator
            // who clicked "Suggest" and then closed the form blackholed that
            // number PERMANENTLY. Suggestions drifted upward and stopped
            // reusing genuine gaps.
            //
            // Now a collision re-reads the existing claim and treats it as
            // free once it is older than the TTL. Server-side by design — a
            // client-side timer would be trivially bypassed and would not
            // survive a page reload.
            //
            // WHY THIS CANNOT PRODUCE A DUPLICATE:
            //   • `$taken` above is built from the ACTUAL roster, so any
            //     number a real student already holds was skipped before we
            //     ever got here — expiry only ever frees a number nobody owns.
            //   • `_rollNoConflict()` remains the authority at save time and
            //     is unchanged; the claim is a courtesy hold for the
            //     suggestion, never the uniqueness guarantee.
            //
            // WHY AN ACTIVE OPERATOR CANNOT BE ROBBED: the TTL is far longer
            // than the seconds between clicking "Suggest" and saving, so a
            // form that is genuinely open still holds its number.
            //
            // COST: one extra read ONLY on a collision, which is the rare path.
            if (!$claimed) {
                try {
                    $existing = $this->firebase->firestoreGet('rollNoClaims', $claimId);
                    $claimedAt = is_array($existing) ? trim((string) ($existing['claimedAt'] ?? '')) : '';
                    $age = ($claimedAt !== '') ? (time() - (int) strtotime($claimedAt)) : PHP_INT_MAX;
                    // A missing/unparseable timestamp counts as expired: such a
                    // claim can never expire on its own and would otherwise
                    // blackhole the number for good.
                    if ($age >= self::ROLL_CLAIM_TTL_SECONDS) {
                        $claimed = (bool) $this->firebase->firestoreSet('rollNoClaims', $claimId, [
                            'schoolId' => $this->school_id, 'session' => $session,
                            'className' => $classKey, 'section' => $sectionKey,
                            'rollNo' => (string) $candidate, 'claimedAt' => date('c'),
                            'claimedBy' => (string) ($this->admin_id ?? 'system'),
                            'reclaimedFrom' => (string) ($existing['claimedBy'] ?? 'unknown'),
                        ]);
                        if ($claimed) {
                            log_message('info', sprintf(
                                'ROLL_CLAIM_RECLAIMED school=%s session=%s class=%s section=%s roll=%d age=%ds',
                                $this->school_id, $session, $classKey, $sectionKey, $candidate, $age
                            ));
                        }
                    }
                } catch (\Throwable $e) {
                    // Fail CLOSED: if the claim cannot be inspected, leave it
                    // held and move to the next candidate. Handing out a
                    // possibly-live number is the worse outcome.
                    log_message('error', "suggest_roll_no expiry check failed for {$candidate}: " . $e->getMessage());
                }
            }

            if ($claimed) {
                return $this->json_success(['rollNo' => (string) $candidate]);
            }
            // Claim collision (another operator got here first) OR a stale
            // claim from an abandoned earlier suggestion — either way this
            // number is not usable right now; try the next one.
        }

        return $this->json_error('Could not determine a free Roll Number automatically. Enter one manually.');
    }

    /* ══════════════════════════════════════════════════════════════════════
       AJAX HELPERS
    ══════════════════════════════════════════════════════════════════════ */

    public function search_student()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_search', 'SIS', 'view');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $query       = strtolower(trim($this->input->post('query')));
        $classFilter = trim($this->input->post('class') ?? '');
        $secFilter   = trim($this->input->post('section') ?? '');
        $filterGender  = trim($this->input->post('gender')  ?? '');
        // Status filter — Active / Inactive / TC / '' (means All except
        // Deleted). Default to Active to match pre-toggle behaviour;
        // admins surface Inactive students by switching the dropdown.
        $statusFilter  = trim($this->input->post('status') ?? 'Active');
        $page        = max(1, (int)($this->input->post('page') ?? 1));
        $perPage     = 30;

        // PERF — `search_student` was making ~32 Firestore round-trips
        // per page on a 500-student school: 1 full schoolList read for
        // the index, 1 separate `_get_enrolled_ids()` collection read,
        // then a `_getStudent()` call per row in the current page.
        //
        // We now do ONE collection read and keep the full doc data in
        // memory so the per-row `_getStudent()` calls are gone, and
        // the enrolled-set is derived from the same in-memory map
        // instead of re-querying. Net: ~32 reads → 1 read.
        //
        // PERF-2 (2026-06-13) — that one read still fired on EVERY request:
        // every debounced keystroke, every filter flip, every page change
        // re-downloaded the whole students collection and rebuilt the index,
        // which is why search-as-you-type felt like it "loaded first, then
        // showed". The session/Deleted-filtered snapshot below does not depend
        // on the query/class/section/gender/status filters (those are applied
        // afterwards), so we cache it under a school+session key for 90s. The
        // snapshot is busted immediately on any students write (see
        // Firestore_service::_bustDashboard), so admissions/deletions/status
        // changes still show up on the next load with no staleness.
        $currentSession = $this->session_year;

        $this->load->driver('cache', ['adapter' => 'file']);
        $cacheKey = $this->fs->studentsListCacheKey();
        $snapshot = $this->cache->get($cacheKey);

        if (is_array($snapshot) && isset($snapshot['index'], $snapshot['rawDocs'])) {
            $index   = $snapshot['index'];
            $rawDocs = $snapshot['rawDocs'];
        } else {
            $studentList = $this->fs->schoolList('students');

            $index   = [];   // [uid => filter-fields]
            $rawDocs = [];   // [uid => full Firestore doc]   ← used for the page's row data
            foreach ($studentList as $s) {
                $d = $s['data'] ?? $s;
                $uid = $d['studentId'] ?? $d['User Id'] ?? $d['userId'] ?? '';
                if ($uid === '') continue;

                // Session enrollment check (mirrors _get_enrolled_ids).
                // SW-CONVERGE-STUDENTS-A (2026-05-26): canonical `session` singular
                // takes precedence; legacy `sessions[]` array is honored ONLY when
                // the singular field is absent/empty (back-compat for pre-convergence
                // admission records). v7 target = singular as sole student-side
                // authority across all filter sites.
                $session  = $d['session']  ?? null;
                $sessions = $d['sessions'] ?? null;
                if (is_string($session) && $session !== '') {
                    if ($session !== $currentSession) continue;
                } elseif (is_array($sessions) && !empty($sessions)) {
                    if (!in_array($currentSession, $sessions, true)) continue;
                }
                // else: no enrollment record on doc — preserve prior behavior
                // (do not skip here; downstream filters apply).

                $rowStatus = (string) ($d['status'] ?? $d['Status'] ?? 'Active');
                // Always exclude hard-deleted rows from the listing.
                if (strcasecmp($rowStatus, 'Deleted') === 0) continue;

                $rawDocs[$uid] = $d;
                $index[$uid] = [
                    'name'    => $d['name']     ?? $d['Name']    ?? '',
                    'class'   => $d['className']?? $d['Class']   ?? '',
                    'section' => $d['section']  ?? $d['Section'] ?? '',
                    'status'  => $rowStatus,
                    'gender'  => $d['gender']   ?? $d['Gender']  ?? '',
                    // Phase 11.3 — SR Number in the cached search index. Built
                    // from the document already read here, so it costs ZERO extra
                    // Firestore reads; it simply makes the school's own register
                    // number searchable the way the school actually uses it.
                    // Cache-shape note: entries written before this change lack
                    // the key and read as '' until the 90s TTL (or the next
                    // students write, which busts the snapshot) refreshes them.
                    'srNo'    => (string) ($d['srNo'] ?? $d['srNumber'] ?? $d['SR Number'] ?? ''),
                ];
            }

            // 90s TTL — short enough that a TTL-only expiry is barely
            // perceptible, but the explicit write-bust means it's almost
            // always the bust (not the TTL) that refreshes the data.
            $this->cache->save($cacheKey, ['index' => $index, 'rawDocs' => $rawDocs], 90);
        }

        // Filter using index fields (name, class, section, status) + userId
        // Dropdown sends stripped values ("8th", "A") but index has prefixed ("Class 8th", "Section A")
        $incompleteOnly = (string) $this->input->post('incomplete') === '1';

        $filtered = [];
        foreach ($index as $uid => $entry) {
            if (!is_array($entry)) continue;
            // Status filter: blank means "any non-Deleted" (already
            // enforced above); a specific value matches case-insensitively.
            if ($statusFilter !== '' && strcasecmp($entry['status'] ?? '', $statusFilter) !== 0) continue;
            $entryClass = str_replace('Class ', '', $entry['class'] ?? '');
            $entrySec   = str_replace('Section ', '', $entry['section'] ?? '');
            if ($classFilter && $entryClass !== $classFilter) continue;
            if ($secFilter   && $entrySec   !== $secFilter) continue;
            if ($filterGender !== '' && strcasecmp($entry['gender'] ?? '', $filterGender) !== 0) continue;
            if ($query) {
                // Searchable by ID, Name, Class, Phone and (Phase 11.3) SR Number.
                // Roll Number is deliberately excluded: it is class+section
                // scoped and repeats across the school, so matching it would
                // imply a global uniqueness that does not exist.
                $p     = $rawDocs[$uid] ?? [];
                $phone = (string) ($p['Phone Number'] ?? $p['phone'] ?? $p['Phone'] ?? '');
                $haystack = strtolower(
                    ($entry['name'] ?? '') . ' ' . $uid . ' '
                    . ($entry['class'] ?? '') . ' ' . $phone . ' '
                    . ($entry['srNo'] ?? '')
                );
                if (strpos($haystack, $query) === false) continue;
            }

            // ADM-COMPLETE-1 — "show me only the thin records", so the count on the
            // roster is a WORKLIST rather than a statistic. Applied last, and only
            // when asked, so the normal listing pays nothing for it.
            if ($incompleteOnly && !$this->_profileGaps($rawDocs[$uid] ?? [])) continue;

            $filtered[$uid] = $entry;
        }

        // Sort by class then name
        uasort($filtered, function ($a, $b) {
            $c = strcmp($a['class'] ?? '', $b['class'] ?? '');
            return $c ?: strcmp($a['name'] ?? '', $b['name'] ?? '');
        });

        $total     = count($filtered);

        // ADM-COMPLETE-1 — counted across the whole FILTERED set, not the current
        // page. "27 incomplete" is only useful if it means the school; a per-page
        // number would change as the operator paged and mean nothing.
        $incompleteTotal = 0;
        foreach (array_keys($filtered) as $__uid) {
            if ($this->_profileGaps($rawDocs[$__uid] ?? [])) $incompleteTotal++;
        }

        $offset    = ($page - 1) * $perPage;
        $pagedKeys = array_slice(array_keys($filtered), $offset, $perPage);

        // Build the page's response rows from the in-memory `$rawDocs`
        // map — no Firestore round-trips here. Every field that used
        // to come from `_getStudent()` is already on the bulk doc.
        $results = [];
        foreach ($pagedKeys as $uid) {
            $entry = $filtered[$uid];
            $p     = $rawDocs[$uid] ?? [];

            // Photo: check all possible field names
            $photo = $p['Profile Pic'] ?? $p['profilePic'] ?? $p['profile_pic'] ?? '';
            if ($photo === '' && !empty($p['Doc']['Photo'])) {
                $dp = $p['Doc']['Photo'];
                $photo = is_array($dp) ? ($dp['url'] ?? '') : (string)$dp;
            }

            // ADM-COMPLETE-1 — computed from the bulk doc already in memory, so the
            // roster gains a completeness signal for ZERO extra Firestore reads and
            // without a backfill: students admitted before pendingProfileFields
            // existed are assessed by the same rule as today's.
            $__gaps = $this->_profileGaps($p);

            $results[] = [
                'user_id'        => $uid,
                'gaps'           => $__gaps,
                'gap_count'      => count($__gaps),
                'name'           => $entry['name'] ?? $p['name'] ?? $p['Name'] ?? '',
                'father_name'    => $p['Father Name'] ?? $p['fatherName'] ?? $p['father_name'] ?? '',
                'class'          => str_replace('Class ', '', $entry['class'] ?? $p['className'] ?? $p['Class'] ?? ''),
                'section'        => str_replace('Section ', '', $entry['section'] ?? $p['section'] ?? $p['Section'] ?? ''),
                'phone'          => $p['Phone Number'] ?? $p['phone'] ?? $p['Phone'] ?? '',
                'gender'         => $entry['gender'] ?? $p['Gender'] ?? $p['gender'] ?? '',
                'admission_date' => $p['Admission Date'] ?? $p['admissionDate'] ?? $p['admission_date'] ?? '',
                'dob'            => $p['DOB'] ?? $p['dob'] ?? '',
                'status'         => $entry['status'] ?? $p['status'] ?? $p['Status'] ?? 'Active',
                'photo'          => $photo,
                // Phase 11.3 — display field from the already-loaded document.
                'sr_no'          => $entry['srNo'] ?? $p['srNo'] ?? '',
            ];
        }

        return $this->json_success([
            'students' => $results,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            // ADM-COMPLETE-1 — the roster renders this as a clickable count. Invisible
            // data debt is never repaid; a number on the screen is the whole point.
            'incomplete_total' => $incompleteTotal,
        ]);
    }

    /**
     * ══ ADMISSION RELATIONSHIP LOOKUPS ═════════════════════════════════════
     *
     * Resolve-and-show, so the operator confirms a PERSON rather than a
     * number. Both are read-only and tenant-scoped; neither writes anything and
     * neither implies any fee effect. The write happens server-side in
     * save_admission / enroll_student, which RE-RESOLVES the identifier — the
     * ids these endpoints return are for display, never trusted on the way back.
     */

    /** POST sr_no — find an existing student by SR Number, this school only. */
    public function lookup_sibling()
    {
        $this->_require_role([], 'sis_lookup_sibling', 'SIS', 'view');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $srNo = trim((string) $this->input->post('sr_no'));
        if ($srNo === '') return $this->json_error('SR Number is required.');

        // SR No, not admission number: an admission number is not something a
        // parent can recite at the counter (operator requirement 2026-08-30).
        // _resolveStudentRef() is the ONE canonical resolver — it delegates to
        // Sr_no_service scoped to $this->school_id, so a number belonging to
        // another tenant simply does not resolve.
        $ref = $this->_resolveStudentRef($srNo);
        if (empty($ref['ok']) || ($ref['via'] ?? '') !== 'srNo') {
            return $this->json_error("SR No. '{$srNo}' not found in this school.");
        }

        $studentId = (string) $ref['studentId'];
        $stu       = $this->fs->get('students', $this->school_id . '_' . $studentId);
        if (!is_array($stu) || $stu === []) {
            return $this->json_error("SR No. '{$srNo}' not found in this school.");
        }

        // Surfaced so the operator sees WHY a match may be unexpected — a
        // sibling who has left is still a real sibling, but the operator
        // should know before confirming.
        $status = trim((string) ($stu['status'] ?? $stu['Status'] ?? 'Active'));

        return $this->json_success(['student' => [
            'studentId' => $studentId,
            'srNo'      => $srNo,
            'name'      => trim((string) ($stu['name'] ?? $stu['Name'] ?? '')),
            'className' => trim((string) ($stu['className'] ?? $stu['class'] ?? '')),
            'section'   => trim((string) ($stu['section'] ?? '')),
            'status'    => $status,
        ]]);
    }

    /** POST staff_id — find an ACTIVE employee of this school. */
    public function lookup_staff_member()
    {
        $this->_require_role([], 'sis_lookup_staff', 'SIS', 'view');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $staffId = trim((string) $this->input->post('staff_id'));
        if ($staffId === '') return $this->json_error('Staff ID is required.');

        $res = $this->_relationshipService()->lookupStaff($staffId);
        if (empty($res['ok'])) return $this->json_error((string) ($res['error'] ?? 'Not found.'));

        return $this->json_success(['staff' => [
            'staffId' => $res['staffId'],
            'name'    => $res['name'],
        ]]);
    }

    /**
     * POST student_id, sr_no — link a sibling AFTER admission.
     *
     * Relationships are not only discovered at the counter: the second child is
     * often admitted months later, and staff correct records afterwards. Without
     * this the link could only ever be created during one admission, which would
     * push people back to the free-text habit this replaced.
     *
     * `manage`, not `edit`: this asserts a verified fact about two students.
     */
    public function link_sibling()
    {
        $this->_require_role([], 'sis_link_sibling', 'SIS', 'manage');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $studentId = trim((string) $this->input->post('student_id'));
        $srNo      = trim((string) $this->input->post('sr_no'));
        if ($studentId === '' || !$this->safe_path_segment($studentId)) {
            return $this->json_error('Invalid student_id.');
        }
        if ($srNo === '') return $this->json_error('Sibling SR Number is required.');

        // Re-resolved here, tenant-scoped — the caller supplies an SR Number,
        // never a student id, so a crafted request cannot name a student in
        // another school.
        $ref = $this->_resolveStudentRef($srNo);
        if (empty($ref['ok']) || ($ref['via'] ?? '') !== 'srNo') {
            return $this->json_error("SR No. '{$srNo}' not found in this school.");
        }
        $sibId = (string) $ref['studentId'];
        $sib   = $this->fs->get('students', $this->school_id . '_' . $sibId);

        $res = $this->_relationshipService()->linkSibling($studentId, [
            'studentId' => $sibId,
            'name'      => is_array($sib) ? trim((string) ($sib['name'] ?? $sib['Name'] ?? '')) : '',
            'srNo'      => $srNo,
        ], 'manual');

        if (empty($res['ok'])) return $this->json_error((string) ($res['error'] ?? 'Could not link.'));

        return $this->json_success([
            'relationshipId' => $res['relationshipId'],
            // Stated on every success so nobody infers a discount from a link.
            'message'        => 'Sibling link recorded. No fee change — raise any concession '
                              . 'from Services & Concessions.',
        ]);
    }

    /** POST student_id — every verified relationship on a student. Read-only. */
    public function relationships_for_student()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_relationships', 'SIS', 'view');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $studentId = trim((string) $this->input->post('student_id'));
        if ($studentId === '' || !$this->safe_path_segment($studentId)) {
            return $this->json_error('Invalid student_id.');
        }

        return $this->json_success([
            'relationships' => $this->_relationshipService()->forStudent($studentId),
        ]);
    }

    /** The one place this service is constructed, so init stays consistent. */
    private function _relationshipService()
    {
        $this->load->library('student_relationship_service', null, 'relSvc');
        return $this->relSvc->init(
            $this->fs, $this->firebase, $this->school_id,
            $this->session_year, (string) ($this->admin_id ?? 'system')
        );
    }

    /**
     * Record whatever relationships this admission VERIFIED.
     *
     * Runs after the student exists, best-effort: a relationship is valuable
     * but it is not worth failing an admission that already created the
     * student and their Auth account. Every identifier is re-resolved here —
     * the form's hidden ids are display state, and trusting them would let a
     * tampered field link a child to another tenant's student.
     *
     * NO MONEY. This writes relationships only. Any sibling or staff-ward
     * concession must still go through the canonical entitlement path with a
     * reason and an approver — see Student_relationship_service's header.
     *
     * @return string[] human-readable notes to append to the success message
     */
    private function _captureAdmissionRelationships(
        string $studentId,
        string $srNoRaw,
        string $staffIdRaw,
        string $rteRefRaw = ''
    ): array {
        $notes = [];

        // ── RTE / EWS · PERSIST THE REFERENCE, DECIDE NO MONEY ─────────────
        // The certificate reference was captured on the form and then dropped:
        // stored on the online application, never read, and on the direct path
        // not stored at all. A government-category claim that leaves no trace
        // on the student record cannot be audited or produced on request.
        //
        // ZenX ALREADY has the canonical financial path for this — an
        // entitlement of concessionType 'rte_ews' carrying govtReceivableAmount,
        // raised through the capture panel under maker-checker. So the only
        // thing missing was persistence of the identifier, which is a record-
        // keeping fix, not a policy one. Deliberately NO fee effect here: how
        // much an RTE/EWS student pays, and how much is recoverable from the
        // state, is a school-and-statute question this code must not guess.
        $rteRef = trim($rteRefRaw);
        if ($rteRef !== '') {
            try {
                $this->fs->set('students', $this->school_id . '_' . $studentId, [
                    'rteEwsDeclared' => true,
                    'rteEwsRef'      => substr($rteRef, 0, 120),
                    'rteEwsSource'   => 'admission',
                ], /* merge */ true);
                $notes[] = 'RTE/EWS reference recorded. Raise the concession from Services & Concessions '
                         . '— it is not applied automatically.';
            } catch (\Throwable $e) {
                log_message('error', "REL rte capture failed for {$studentId}: " . $e->getMessage());
                $notes[] = 'RTE/EWS reference could not be saved — please add it on the student record.';
            }
        }

        if (trim($srNoRaw) !== '') {
            try {
                $ref = $this->_resolveStudentRef(trim($srNoRaw));
                if (!empty($ref['ok']) && ($ref['via'] ?? '') === 'srNo') {
                    $sibId = (string) $ref['studentId'];
                    $sib   = $this->fs->get('students', $this->school_id . '_' . $sibId);
                    $res   = $this->_relationshipService()->linkSibling($studentId, [
                        'studentId' => $sibId,
                        'name'      => is_array($sib) ? trim((string) ($sib['name'] ?? $sib['Name'] ?? '')) : '',
                        'srNo'      => trim($srNoRaw),
                    ], 'admission');
                    $notes[] = !empty($res['ok'])
                        ? 'Sibling link recorded and verified.'
                        : 'Sibling link NOT recorded: ' . (string) ($res['error'] ?? 'unknown reason');
                } else {
                    // Truthful over convenient: the operator must know the
                    // number did not match rather than assume it did.
                    $notes[] = "Sibling NOT linked — SR No. '" . trim($srNoRaw) . "' did not match a student in this school.";
                }
            } catch (\Throwable $e) {
                log_message('error', "REL sibling capture failed for {$studentId}: " . $e->getMessage());
                $notes[] = 'Sibling link could not be recorded — please add it from the student record.';
            }
        }

        if (trim($staffIdRaw) !== '') {
            try {
                $svc  = $this->_relationshipService();
                $look = $svc->lookupStaff(trim($staffIdRaw));
                if (!empty($look['ok'])) {
                    $res = $svc->linkStaffWard($studentId, $look, 'admission');
                    $notes[] = !empty($res['ok'])
                        ? 'Employee relationship recorded and verified.'
                        : 'Employee link NOT recorded: ' . (string) ($res['error'] ?? 'unknown reason');
                } else {
                    $notes[] = 'Employee NOT linked — ' . (string) ($look['error'] ?? 'staff member not found.');
                }
            } catch (\Throwable $e) {
                log_message('error', "REL staff_ward capture failed for {$studentId}: " . $e->getMessage());
                $notes[] = 'Employee link could not be recorded — please add it from the student record.';
            }
        }

        return $notes;
    }

    public function get_student()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_get_student', 'SIS', 'view');
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required');
        }

        $school_id = $this->parent_db_key;
        $entered   = trim($this->input->post('user_id'));

        if (empty($entered)) return $this->json_error('User ID required.');

        // Phase 11.3 — accept the school's SR Number as well as the Student ID,
        // through the SAME shared resolver the Fees counter uses. Resolution
        // precedes the student read, so there is still exactly one student read.
        $ref = $this->_resolveStudentRef($entered);
        if (empty($ref['ok'])) {
            return $this->json_error(
                (($ref['via'] ?? '') === 'srNo')
                    ? "SR No. '{$entered}' not found in this school."
                    : 'Student not found.'
            );
        }
        $userId = (string) $ref['studentId'];
        if (!$this->safe_path_segment($userId)) return $this->json_error('Invalid User ID.');

        $student = $this->_getStudent($userId);
        if (empty($student)) return $this->json_error('Student not found.');

        return $this->json_success([
            'student'      => $student,
            'resolved_via' => (string) ($ref['via'] ?? 'studentId'),
        ]);
    }

    public function get_classes()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_get_classes', 'SIS', 'view');
        $classMap = $this->_fs_class_map();
        return $this->json_success(['classes' => array_keys($classMap)]);
    }

    public function get_sections()
    {
        $this->_require_role(self::VIEW_ROLES, 'sis_get_sections', 'SIS', 'view');
        $classOrd = trim($this->input->get('class') ?? $this->input->post('class') ?? '');

        if (empty($classOrd)) return $this->json_error('Class required.');

        $classMap = $this->_fs_class_map();
        $sections = $classMap[$classOrd] ?? [];

        return $this->json_success(['sections' => $sections]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PRIVATE HELPERS
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Build Students_Index from Users/Parents data and persist it.
     * Called automatically when the index is empty (first visit or migration).
     */
    private function _build_index_from_parents(string $school_id, string $school_name): array
    {
        // In Firestore, students collection IS the index
        $studentList = $this->fs->schoolList('students');
        $index = [];
        foreach ($studentList as $s) {
            $d = $s['data'] ?? $s;
            $uid = $d['studentId'] ?? $d['User Id'] ?? $d['userId'] ?? '';
            if ($uid === '') continue;
            $index[$uid] = [
                'name'    => $d['name'] ?? $d['Name'] ?? '',
                'class'   => $d['className'] ?? $d['Class'] ?? '',
                'section' => $d['section'] ?? $d['Section'] ?? '',
                'status'  => $d['status'] ?? $d['Status'] ?? 'Active',
                'gender'  => $d['gender'] ?? $d['Gender'] ?? '',
            ];
        }
        return $index;
    }

    /**
     * Generate student password — exact copy of Student.php::generatePassword().
     * Format: Ucfirst(first 3 letters of name) + first 4 DOB digits + @
     */
    private function _generatePassword(string $name, string $dob): string
    {
        // CRED-DERIVE-1 (2026-09-19) — this used to return
        // ucfirst(first 3 letters of the name) . (first 4 digits of the DOB) . '@',
        // so "Rahul Sharma" born 12-05-2015 always got "Rah1205@". Both inputs are
        // public inside the school: the name is on the roster, the DOB is on the ID
        // card. The username is no secret either — Firebase::authEmail() is a pure
        // function of a SEQUENTIAL student id that is printed on the card. That made
        // the whole credential pair guessable by anyone in the school.
        //
        // mustChangePassword forces a rotation at FIRST login, which is exactly the
        // moment an attacker wants: whoever signs in first sets the new password and
        // locks the family out of their own child's account.
        //
        // $name and $dob are retained in the signature ONLY so the existing call sites
        // do not all have to change; they are deliberately unused. Ambiguous glyphs
        // (0/O, 1/l/I) are excluded because this gets read aloud at the front desk.
        unset($name, $dob);

        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max      = strlen($alphabet) - 1;
        $out      = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        // Firebase Auth needs >= 6 chars; Sis::reset_password additionally requires an
        // uppercase, a lowercase and a digit. Pin all three so a generated credential
        // always satisfies the stricter of the two rules rather than relying on luck.
        return 'A' . $out . '7@';
    }

    /**
     * Upload a student file to Firebase Storage — mirrors Student.php::uploadStudentFile().
     * Returns ['document' => url, 'thumbnail' => url] or false on failure.
     */
    /**
     * SIS Wave-3 fix D3 (2026-05-31): consolidated Firebase Auth user creation.
     *
     * Replaces 3 duplicated inline blocks (save_admission L515-529,
     * import_students L3500-3514, enroll_student L5022-5044) with a single
     * helper. Pre-fix, each call site had its own empty-password handling
     * (only enroll_student checked), its own success-info-log (only
     * enroll_student emitted), and its own error-log message format (3
     * different strings).
     *
     * Helper provides a UNIFORM RETURN SHAPE so each caller decides what
     * to do on failure. Behavioral preservation at call sites:
     *   - save_admission: ignores return (silent continue, B3 territory)
     *   - import_students: ignores return (silent continue)
     *   - enroll_student: captures return into $authCreated + $authError
     *     for the existing json_success response. B3 (success-when-auth-
     *     fails anti-pattern) is NOT touched — separate Tier-2 fix.
     *
     * Returns ['success' => bool, 'error' => string].
     *   ['success' => true,  'error' => '']        Auth + claims succeeded
     *   ['success' => false, 'error' => 'reason']  Auth or claims failed,
     *                                              or password was empty
     *
     * $context is the originating method name (free-form string) — used
     * in log lines for forensic triage. Defaults to 'sis' for safety.
     */
    private function _createFirebaseAuthStudent(
        string $studentId,
        string $password,
        string $displayName,
        string $context = 'sis'
    ): array {
        if ($password === '') {
            $err = 'Generated password is empty.';
            log_message('error', "{$context}: Firebase Auth create skipped for {$studentId}: {$err}");
            return ['success' => false, 'error' => $err];
        }
        try {
            $authEmail = Firebase::authEmail($studentId);
            $this->firebase->createFirebaseUser($authEmail, $password, [
                'uid'         => $studentId,
                'displayName' => $displayName,
            ]);
            $this->firebase->setCanonicalClaims($studentId, [
                'role'          => 'student',
                'school_id'     => $this->school_id,
                'school_code'   => $this->school_code,
                'parent_db_key' => $this->parent_db_key,
                // auth.uid == studentId; emit student identity for studentFlags.
                'student_id'    => $studentId,
                // Force set-new-password on first login. Parent app gates on the
                // Firestore mirror below, not the claim, but emit both for parity
                // with the reset flow (reset_password@2048).
                'extra'         => [
                    'must_change_password' => true,
                ],
            ]);
            // Mirror to the students doc — the Parent app reads this field to
            // trigger its force-change-password screen on first login. Merge
            // write (upsert): the primary student doc already exists at every
            // caller (save_admission/import/enroll write it before this helper),
            // and Entity_firestore_sync::syncStudent never touches this key, so
            // the flag survives all subsequent syncs.
            try {
                $this->fs->set('students', $this->fs->docId($studentId), [
                    'mustChangePassword' => true,
                    'updatedAt'          => date('c'),
                ], true);
            } catch (\Exception $e) {
                log_message('error', "{$context}: Firestore mustChange write failed for {$studentId}: " . $e->getMessage());
            }
            log_message('info', "{$context}: Firebase Auth user created for {$studentId} (email={$authEmail}).");
            return ['success' => true, 'error' => ''];
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            log_message('error', "{$context}: Firebase Auth create failed for {$studentId}: {$msg}");
            return ['success' => false, 'error' => $msg];
        }
    }

    /**
     * SIS Wave-3 fix DM6 (2026-05-31): per-student document quota check.
     *
     * Defaults come from application/config/sis_document_quota.php. Per-school
     * override comes from schools.{schoolId}.documentQuota.{maxDocs|maxFileBytes|
     * maxTotalBytes} when present.
     *
     * Returns ['ok' => true] on pass, or ['ok' => false, 'reason' => msg] on fail.
     * Reason is operator-facing — call sites surface it via json_error.
     *
     * Grandfathered docs without a `bytes` field count toward the COUNT cap
     * but are treated as 0 in the SIZE aggregate. COUNT is the primary
     * safeguard for grandfathered students; SIZE becomes accurate over time
     * as docs are re-uploaded through upload_document (which now records
     * bytes). Admission-form uploads via _uploadStudentFile do NOT yet
     * record bytes — a residual gap deliberately left out of DM6 scope
     * (single-issue / surgical change discipline).
     */
    private function _check_doc_quota(string $userId, int $newFileBytes): array
    {
        $this->config->load('sis_document_quota', true);
        $defaults = $this->config->item('sis_document_quota') ?: [];
        $defaultMaxDocs       = (int) ($defaults['max_docs']        ?? 10);
        $defaultMaxFileBytes  = (int) ($defaults['max_file_bytes']  ?? 5 * 1024 * 1024);
        $defaultMaxTotalBytes = (int) ($defaults['max_total_bytes'] ?? 50 * 1024 * 1024);

        $schoolDoc = $this->fs->get('schools', $this->school_id) ?: [];
        $override  = is_array($schoolDoc['documentQuota'] ?? null) ? $schoolDoc['documentQuota'] : [];
        $maxDocs       = (int) ($override['maxDocs']       ?? $defaultMaxDocs);
        $maxFileBytes  = (int) ($override['maxFileBytes']  ?? $defaultMaxFileBytes);
        $maxTotalBytes = (int) ($override['maxTotalBytes'] ?? $defaultMaxTotalBytes);

        // Per-file size cap (operator-tunable; default 5 MB)
        if ($newFileBytes > $maxFileBytes) {
            $maxMb = number_format($maxFileBytes / 1024 / 1024, 1);
            $newMb = number_format($newFileBytes / 1024 / 1024, 1);
            return ['ok' => false, 'reason' => "File too large ({$newMb} MB). Per-file limit is {$maxMb} MB."];
        }

        // Read student's existing document map
        $studentDoc = $this->_getStudent($userId);
        $docMap = is_array($studentDoc['documents'] ?? null) ? $studentDoc['documents']
                : (is_array($studentDoc['Doc'] ?? null) ? $studentDoc['Doc'] : []);
        // Count only REAL documents (entries that actually hold a file). Empty
        // placeholder slots created at enrollment (Photo / Aadhar Card / Birth
        // Certificate / Transfer Certificate with no url) are NOT documents and
        // must not consume the per-student limit — otherwise a fresh student can
        // only ever add (maxDocs − 4) files. Bugfix 2026-07-22.
        $currentCount = 0;
        $currentBytes = 0;
        foreach ($docMap as $entry) {
            $url = is_array($entry) ? (string) ($entry['url'] ?? '') : (string) $entry;
            if ($url === '') continue;                 // empty placeholder slot → not a document
            $currentCount++;
            if (is_array($entry) && isset($entry['bytes'])) {
                $currentBytes += (int) $entry['bytes'];
            }
        }

        // Doc-count cap (new upload would push count to currentCount + 1).
        // A same-label re-upload does NOT add to the count — it replaces an
        // existing entry. Detect this and skip the count cap if applicable.
        // Per-site convention: callers pass $newFileBytes only; we cannot
        // know the doc label here, so the count check applies uniformly.
        // Net effect: a re-upload at full count (=maxDocs) is blocked — the
        // operator must delete-first then upload. Acceptable tradeoff for
        // surgical scope; callers that want the smarter re-upload-aware
        // check can do their own count-vs-replacement detection before
        // calling _check_doc_quota.
        if ($currentCount >= $maxDocs) {
            return ['ok' => false, 'reason' => "Student already has {$currentCount} documents (limit: {$maxDocs}). Delete an existing document first."];
        }

        // Aggregate size cap (tracked bytes only; grandfathered docs count
        // as 0 — see method docblock for rationale)
        if (($currentBytes + $newFileBytes) > $maxTotalBytes) {
            $currentMb = number_format($currentBytes / 1024 / 1024, 1);
            $newMb     = number_format($newFileBytes / 1024 / 1024, 1);
            $maxMb     = number_format($maxTotalBytes / 1024 / 1024, 1);
            return ['ok' => false, 'reason' => "Student total storage: {$currentMb} MB. Cannot upload {$newMb} MB file (would exceed {$maxMb} MB limit). Delete some documents first."];
        }

        return ['ok' => true];
    }

    private function _uploadStudentFile($file, $schoolName, $combinedClassPath, $studentId, $folderLabel, $type = 'document')
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return false;

        $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed   = ($type === 'profile') ? ['jpg','jpeg','png','webp'] : ['jpg','jpeg','png','webp','pdf'];
        if (!in_array($ext, $allowed, true)) return false;

        // SIS Wave-3 DM6 (2026-05-31): per-student quota check. Replaces the
        // hardcoded 5 MB check below with config-driven per-file + aggregate
        // size + doc-count caps. Per-school override at schools.{id}.documentQuota
        // takes precedence over the config default. Returns false on quota
        // fail (matches existing _uploadStudentFile failure convention; caller
        // sees a false return and can choose to surface error to operator).
        $quota = $this->_check_doc_quota($studentId, (int) $file['size']);
        if (!$quota['ok']) {
            log_message('warning', "Sis::_uploadStudentFile DM6 quota block for {$studentId}: " . $quota['reason']);
            return false;
        }

        // M-03 FIX: Validate MIME via finfo (don't trust client-supplied type)
        $allowedMimes = ($type === 'profile')
            ? ['image/jpeg','image/png','image/webp']
            : ['image/jpeg','image/png','image/webp','application/pdf'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, $allowedMimes, true)) return false;

        $timestamp = time();
        $random    = substr(md5(uniqid()), 0, 6);
        $safeLabel = str_replace([' ', '.', '#', '$', '[', ']'], '_', $folderLabel);
        $fileName  = "{$safeLabel}_{$timestamp}_{$random}.{$ext}";
        // Canonical Storage scheme: schools/{schoolId}/students/{studentId}/... so a
        // school's whole footprint sits under one ID-keyed prefix, matching
        // upload_document(). Previously rooted at the bare school id string with a
        // class segment ({schoolName}/Students/{class}/{studentId}/...).
        $basePath  = "schools/{$this->school_id}/students/{$studentId}/";

        $documentPath = ($type === 'profile')
            ? $basePath . "profile/{$fileName}"
            : $basePath . "documents/{$fileName}";

        if ($this->firebase->uploadFile($file['tmp_name'], $documentPath) !== true) return false;

        $documentUrl  = $this->firebase->getDownloadUrl($documentPath);
        $thumbnailUrl = '';

        // Image thumbnail (document mode)
        if ($type === 'document' && in_array($ext, ['jpg','jpeg','png','webp'])) {
            $thumbPath = $basePath . "documents/thumbnail/{$fileName}";
            if ($this->firebase->uploadFile($file['tmp_name'], $thumbPath) === true) {
                $thumbnailUrl = $this->firebase->getDownloadUrl($thumbPath);
            }
        }

        // PDF thumbnail (document mode)
        if ($type === 'document' && $ext === 'pdf') {
            $thumbnailUrl = $this->_generatePdfThumbnail($file['tmp_name'], $basePath."documents/", $safeLabel, $timestamp, $random);
        }

        // Profile photo thumbnail
        if ($type === 'profile' && in_array($ext, ['jpg','jpeg','png','webp'])) {
            $thumbPath = $basePath . "profile/thumbnail/{$fileName}";
            if ($this->firebase->uploadFile($file['tmp_name'], $thumbPath) === true) {
                $thumbnailUrl = $this->firebase->getDownloadUrl($thumbPath);
            }
        }

        return ['document' => $documentUrl, 'thumbnail' => $thumbnailUrl];
    }

    /**
     * Write/update the lightweight Students_Index entry for a student.
     * Path: Schools/{sn}/SIS/Students_Index/{userId}
     */
    private function _update_student_index(
        string $schoolName,
        string $userId,
        string $name,
        string $class,
        string $section,
        string $status,
        string $gender = ''
    ): void {
        // No-op: Students_Index is no longer needed — data lives in students collection
    }

    /**
     * Read a student from Firestore and normalize field names.
     * Single read — no duplicates.
     */
    private function _getStudent(string $userId): ?array
    {
        $doc = $this->fs->getEntity('students', $userId);
        return $this->_normalizeStudentDoc($doc);
    }

    /**
     * Normalize a Firestore student doc to include both Title Case (RTDB legacy)
     * and camelCase (Firestore native) field names.
     *
     * The controller and views historically used Title Case keys ('Name', 'Father Name',
     * 'Phone Number', 'Class', etc.) from RTDB. Firestore docs use camelCase
     * ('name', 'fatherName', 'phone', 'className', etc.).
     *
     * This method ensures both conventions exist so all downstream code works
     * regardless of which format the source document used.
     */
    private function _normalizeStudentDoc(?array $doc): ?array
    {
        if (!is_array($doc) || empty($doc)) return $doc;

        // Map: camelCase → Title Case (only set if missing)
        $camelToTitle = [
            'name'             => 'Name',
            'fatherName'       => 'Father Name',
            'motherName'       => 'Mother Name',
            'phone'            => 'Phone Number',
            'phoneNumber'      => 'Phone Number',
            'email'            => 'Email',
            'className'        => 'Class',
            'section'          => 'Section',
            'rollNo'           => 'Roll No',
            'gender'           => 'Gender',
            'dob'              => 'DOB',
            'admissionDate'    => 'Admission Date',
            'status'           => 'Status',
            'profilePic'       => 'Profile Pic',
            'studentId'        => 'User Id',
            'bloodGroup'       => 'Blood Group',
            'category'         => 'Category',
            'religion'         => 'Religion',
            'nationality'      => 'Nationality',
            'fatherOccupation' => 'Father Occupation',
            'motherOccupation' => 'Mother Occupation',
            'guardContact'     => 'Guard Contact',
            'guardRelation'    => 'Guard Relation',
            'preClass'         => 'Pre Class',
            'preSchool'        => 'Pre School',
            'preMarks'         => 'Pre Marks',
            'address'          => 'Address',
            'documents'        => 'Doc',
        ];

        // Map: Title Case → camelCase
        $titleToCamel = [
            'Name'              => 'name',
            'Father Name'       => 'fatherName',
            'Mother Name'       => 'motherName',
            'Phone Number'      => 'phone',
            'Email'             => 'email',
            'Class'             => 'className',
            'Section'           => 'section',
            'Roll No'           => 'rollNo',
            'Gender'            => 'gender',
            'DOB'               => 'dob',
            'Admission Date'    => 'admissionDate',
            'Status'            => 'status',
            'Profile Pic'       => 'profilePic',
            'User Id'           => 'studentId',
            'Blood Group'       => 'bloodGroup',
            'Category'          => 'category',
            'Religion'          => 'religion',
            'Nationality'       => 'nationality',
            'Father Occupation' => 'fatherOccupation',
            'Mother Occupation' => 'motherOccupation',
            'Guard Contact'     => 'guardContact',
            'Guard Relation'    => 'guardRelation',
            'Pre Class'         => 'preClass',
            'Pre School'        => 'preSchool',
            'Pre Marks'         => 'preMarks',
            'Address'           => 'address',
            'Doc'               => 'documents',
        ];

        // Fill missing Title Case from camelCase
        foreach ($camelToTitle as $camel => $title) {
            if (!isset($doc[$title]) && isset($doc[$camel])) {
                $doc[$title] = $doc[$camel];
            }
        }

        // Fill missing camelCase from Title Case
        foreach ($titleToCamel as $title => $camel) {
            if (!isset($doc[$camel]) && isset($doc[$title])) {
                $doc[$camel] = $doc[$title];
            }
        }

        return $doc;
    }

    /**
     * Append an entry to the canonical studentHistory collection.
     *
     * History Canonicalization (2026-06-02): D3.B cutover landing.
     * Writes one document per history event keyed
     * {schoolId}_{userId}_{histKey}. Replaces the prior dotted-path
     * PATCH on students.{id}.History map (F2, 47913a6f, 2026-05-31).
     *
     * createDocument is idempotent (fails-if-exists); $histKey
     * collision (timestamp + 6 hex random) remains astronomically
     * unlikely — same risk profile as the legacy-map writer.
     */
    private function _log_history(
        string $schoolId,
        string $userId,
        string $action,
        string $description,
        array  $metadata = []
    ): void {
        // Phase 16.0 (R1) — the writer now lives in Student_history_service so
        // that lifecycle events raised OUTSIDE this controller can be audited
        // through the same code. This method was private, which is why
        // Classes::transfer_students() recorded nothing (CS-2) while the
        // structurally identical promotion was fully audited.
        //
        // Record shape, doc-id and histKey format are unchanged, so existing
        // rows and Student_timeline_reader are unaffected.
        $adminName = $this->session->userdata('admin_name') ?? 'System';
        $this->load->library('Student_history_service', null, 'studentHistorySvc');
        $this->studentHistorySvc
             ->init($this->firebase, (string) $adminName)
             ->log($schoolId, $userId, $action, $description, $metadata);
    }

    /**
     * G1 — Blank the current month's `attendanceSummary.dayWise` from
     * today through end-of-month, so a student's withdrawal / TC /
     * status flip mid-month doesn't leave inflated percentages or stale
     * `V` (vacant) days that the next teacher mark would overwrite.
     *
     * The chosen replacement char is `'V'` — same as the "no mark yet"
     * character used elsewhere — so the parent / teacher / admin
     * dashboards naturally read these days as "no working day for this
     * student" and the working-day count drops accordingly. After
     * blanking we recompute counters + percentage and merge-set the
     * doc; older days (1..today-1) and other fields are preserved.
     *
     * Best-effort: any failure is logged but never blocks the parent
     * lifecycle op (status flip succeeded → audit trail intact, the
     * summary catch-up can be done later via a maintenance script).
     */
    private function _blank_summary_from_today(string $studentId): void
    {
        try {
            $today    = (int) date('j');
            $year     = (int) date('Y');
            $monthNum = (int) date('n');
            $monthKey = sprintf('%04d-%02d', $year, $monthNum);
            $daysInMonth = (int) cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);

            $docId = $this->fs->docId2($studentId, $monthKey);
            $doc   = $this->fs->get('attendanceSummary', $docId);
            if (!is_array($doc)) return;     // no summary yet → nothing to blank

            $dayWise = (string) ($doc['dayWise'] ?? '');
            $dayWise = str_pad($dayWise, $daysInMonth, 'V');
            if (strlen($dayWise) > $daysInMonth) {
                $dayWise = substr($dayWise, 0, $daysInMonth);
            }
            for ($d = $today - 1; $d < $daysInMonth; $d++) {
                $dayWise[$d] = 'V';
            }

            $present = $absent = $leave = $holiday = $tardy = $working = 0;
            for ($i = 0, $n = strlen($dayWise); $i < $n; $i++) {
                $ch = $dayWise[$i];
                if      ($ch === 'P') { $present++; $working++; }
                elseif  ($ch === 'A') { $absent++;  $working++; }
                elseif  ($ch === 'L') { $leave++;   $working++; }
                elseif  ($ch === 'H') { $holiday++;             }
                elseif  ($ch === 'T') { $tardy++;   $working++; }
            }
            $pct = $working > 0 ? round(($present + $tardy) / $working * 100, 1) : 0;

            $this->fs->set('attendanceSummary', $docId, [
                'dayWise'    => $dayWise,
                'present'    => $present,
                'absent'     => $absent,
                'leave'      => $leave,
                'holiday'    => $holiday,
                'tardy'      => $tardy,
                'percentage' => $pct,
                'updatedAt'  => date('c'),
                'updatedBy'  => $this->admin_id ?? 'system',
            ], true);
        } catch (\Exception $e) {
            log_message('error',
                "_blank_summary_from_today({$studentId}) failed: " . $e->getMessage()
            );
        }
    }

    /**
     * G2 — Recompute `sections.currentStrength` from the live count of
     * Active students in the (className, section) tuple, and merge-write
     * it onto the canonical sections doc.
     *
     * Cheap: one indexed `students` query per call (covered by the
     * deployed schoolId+className+section+status compound index) and
     * one merge-set on `sections/{schoolId}_{session}_{class}_{section}`.
     * Synchronous, no background job, no schema change.
     *
     * Best-effort: failures are logged and never block the parent
     * lifecycle op. The strength field is informational (drives the
     * admission section-picker bars) — staleness for one request is
     * acceptable; we'll be back in sync on the next lifecycle write.
     */
    private function _recompute_section_strength(string $classKey, string $sectionKey): void
    {
        try {
            $ck = Firestore_service::classKey($classKey);
            $sk = Firestore_service::sectionKey($sectionKey);
            if ($ck === '' || $sk === '') return;

            $rows = $this->fs->schoolWhere('students', [
                ['className', '==', $ck],
                ['section',   '==', $sk],
                ['status',    '==', 'Active'],
            ]);
            $count = is_array($rows) ? count($rows) : 0;

            $sectionDocId = $this->fs->sectionDocId($ck, $sk);
            // BUG (2026-05-28): only update strength on an EXISTING section.
            // set(merge=true) on a missing section created a field-less "ghost"
            // doc that blocked section creation ("already exists") while staying
            // invisible to the list query. Recompute must not materialize a
            // section — that's saveSection/activate_classes' responsibility.
            if (is_array($this->fs->get('sections', $sectionDocId))) {
                $this->fs->set('sections', $sectionDocId, [
                    'currentStrength' => $count,
                    'updatedAt'       => date('c'),
                ], true);
            }
        } catch (\Exception $e) {
            log_message('error',
                "G2 _recompute_section_strength({$classKey}/{$sectionKey}) failed: " . $e->getMessage()
            );
        }
    }

    /**
     * Preview the next student ID (read-only, does NOT increment).
     * Routes through Id_generator's STU_PEEK path so the preview and
     * the save-time generate() read the SAME Firestore pointer doc
     * (collection feeCounters, doc _sys_STU). Previously read from
     * Firestore_service::getCounter() which queried a separate
     * SYSTEM_COUNTERS/global doc that the post-2026-04-27 migration
     * stopped maintaining — the admission page mis-displayed STU0001
     * while the real save would correctly produce STU0012.
     *
     * Firestore-only path. No RTDB, no MongoDB, no Auth API.
     */
    private function _peekNextStudentId(string $schoolId): string
    {
        try {
            $this->load->library('id_generator');
            // Id_generator::generate('<PREFIX>_PEEK') is a first-class
            // read-only path (Id_generator.php:103-105 → _peek():591-595):
            // reads the pointer, does NOT increment, does NOT create a
            // claim doc, formats with canonical padding.
            $peek = $this->id_generator->generate('STU_PEEK');
            if (is_string($peek) && $peek !== '') return $peek;
        } catch (Exception $e) {
            log_message('error', 'peekNextStudentId failed: ' . $e->getMessage());
        }
        return 'STU****';
    }

    /**
     * Generate the next student ID via Id_generator (Firestore-backed
     * atomic claim-and-pointer counter). Globally unique — no two
     * students anywhere share the same ID. Only call this when actually
     * saving a student (not on page load).
     *
     * Counter is stored in Firestore collection feeCounters
     * (pointer doc _sys_STU + per-value claim docs _sys_STU_claim_{N}).
     * Migrated from RTDB on 2026-04-27 (Id_generator.php:45-48).
     * Firestore-only — no RTDB, no MongoDB, no Auth API.
     */
    private function _nextStudentId(string $schoolId): ?string
    {
        try {
            $this->load->library('id_generator');
            $userId = $this->id_generator->generate('STU');
            if ($userId) return $userId;
        } catch (Exception $e) {
            log_message('error', 'nextStudentId failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Get the next TC serial number and increment the counter.
     * Format: TC-{school_code}-{YYYY}-{0001}
     */
    private function _get_tc_number(string $schoolName): string
    {
        // Atomic per-school allocation via claim-doc CAS — closes the
        // read-increment-write race that could hand two concurrent TCs the
        // same number. Seeds from the legacy schools.tcCounter so numbering
        // continues without restart (no re-issue of already-used numbers).
        $schoolDoc = $this->fs->get('schools', $this->school_id);
        $current   = (int) ($schoolDoc['tcCounter'] ?? 0);

        $next = $this->fs->nextSchoolCounter('tc', $current);
        if ($next <= 0) {
            // SIS Wave-3 fix F4 (2026-05-31): the previous fallback computed
            // $current + 1 from a possibly-stale schools.tcCounter mirror
            // without claim-doc verification. If a prior call's mirror-write
            // had failed silently, the mirror was stale and this fallback
            // could compute a number ALREADY ISSUED by an earlier atomic
            // claim — duplicate TC. We now abort TC issuance instead. Atomic
            // unavailability is rare and operator-retryable; better to block
            // briefly than to issue a duplicate. Caller (issue_tc) must
            // check for empty return and surface a json_error.
            log_message('error',
                "Sis::_get_tc_number atomic counter unavailable; TC issuance aborted to prevent duplicate-number race from stale-mirror fallback. " .
                "Retry the operation; if persistent, investigate feeCounters/{$this->school_id}_tc pointer doc.");
            return '';
        }

        // Mirror into schools.tcCounter (monotonic) so existing verifiers
        // (Sis_canonical_verify / Sis_tier2_verify) stay coherent.
        // SIS Wave-3 fix F4: capture return + ERROR log on failure. Pre-fix,
        // a silent failure here left the mirror stale, which contributed to
        // the duplicate-number window in the (now-removed) fallback path.
        // The atomic claim doc remains the source of truth; mirror failure
        // does NOT block the current TC issuance (the claim is already
        // recorded) but the next-call's duplicate-number risk via fallback
        // is now closed (fallback removed above).
        if ($next > $current) {
            $mirrorOk = false;
            try {
                $mirrorOk = (bool) $this->fs->update('schools', $this->school_id, ['tcCounter' => $next]);
            } catch (\Throwable $e) {
                log_message('error', "Sis::_get_tc_number mirror update threw for tcCounter={$next}: " . $e->getMessage());
            }
            if (!$mirrorOk) {
                log_message('error',
                    "Sis::_get_tc_number mirror update returned false; schools.tcCounter is now stale relative to atomic claim ({$next}). " .
                    "Verifiers may report drift until next successful mirror write. The atomic claim doc feeCounters/{$this->school_id}_tc_claim_{$next} remains authoritative.");
            }
        }

        $year = date('Y');
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($schoolName, 0, 6)));
        return "TC-{$code}-{$year}-" . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Check if a student has outstanding (unpaid) fees.
     * Returns ['has_dues' => bool, 'total_due' => float, 'unpaid_months' => [...], 'summary' => string]
     *
     * Fee payment status: Students/{userId}/Month Fee/{month} → 0=unpaid, 1=paid
     * Fee amounts: Accounts/Fees/Classes Fees/Class {X}/Section {Y}/{month}/{feeType} → amount
     */
    private function _check_outstanding_dues(string $userId, array $student): array
    {
        // Comprehensive clearance check via Fee_defaulter_check (enhanced)
        try {
            $clearance = $this->feeDefaulter->calculateClearanceStatus($userId);
            if (!$clearance['all_clear']) {
                $modules = [];
                foreach (($clearance['modules'] ?? []) as $mod => $info) {
                    if (!empty($info['has_dues'])) {
                        $modules[] = $mod . ': ' . ($info['summary'] ?? 'dues pending');
                    }
                }
                return [
                    'has_dues'      => true,
                    'total_due'     => $clearance['total_due'] ?? 0,
                    'unpaid_months' => $clearance['unpaid_months'] ?? [],
                    'summary'       => !empty($modules) ? implode('; ', $modules) : ($clearance['summary'] ?? 'Outstanding dues found'),
                    'clearance'     => $clearance,
                ];
            }
        } catch (Exception $e) {
            log_message('error', "Fee_defaulter_check::calculateClearanceStatus failed: " . $e->getMessage());
            // Fall through to existing check as fallback
        }

        $school_name = $this->school_name;
        $session     = $this->session_year;
        // Accept both Title Case (legacy admission doc) and camelCase (Firestore
        // canonical from Entity_firestore_sync::syncStudent). Without the
        // fallback a student doc that only carries `className`/`section`
        // would short-circuit the dues check and silently allow a TC
        // through when the primary clearance service is unavailable.
        $classOrd    = trim($student['Class']   ?? $student['className'] ?? '');
        $sectionLtr  = trim($student['Section'] ?? $student['section']   ?? '');

        $result = ['has_dues' => false, 'total_due' => 0, 'unpaid_months' => [], 'summary' => ''];

        if ($classOrd === '' || $sectionLtr === '') return $result;

        // FIXED: use Firestore_service helpers (idempotent — safe if already prefixed)
        $classKey    = Firestore_service::classKey($classOrd);
        $sectionKey  = Firestore_service::sectionKey($sectionLtr);

        // Read student's month-wise payment status from student doc
        $studentDoc = $this->_getStudent($userId);
        $studentFees = $studentDoc['monthFee'] ?? $studentDoc['Month Fee'] ?? [];
        if (!is_array($studentFees)) $studentFees = [];

        // Read class fee structure from Firestore (docId includes session)
        $feeDocId = $this->fs->sectionDocId($classOrd, $sectionLtr);
        $feeStructDoc = $this->fs->get('feeStructures', $feeDocId);
        $classFees = $feeStructDoc['heads'] ?? $feeStructDoc ?? [];
        if (!is_array($classFees)) $classFees = [];

        $months = [
            'April','May','June','July','August','September',
            'October','November','December','January','February','March',
        ];

        $totalDue     = 0;
        $unpaidMonths = [];

        foreach ($months as $month) {
            $isPaid = (int)($studentFees[$month] ?? 0);
            if ($isPaid) continue; // already paid

            // Calculate this month's fee total from the class fee structure
            $monthFees = $classFees[$month] ?? [];
            if (!is_array($monthFees)) continue;

            $monthTotal = 0;
            foreach ($monthFees as $feeType => $amount) {
                $monthTotal += (float)$amount;
            }

            if ($monthTotal > 0) {
                $totalDue += $monthTotal;
                $unpaidMonths[] = $month;
            }
        }

        // Check yearly fees too
        $yearlyPaid = (int)($studentFees['Yearly Fees'] ?? 0);
        if (!$yearlyPaid) {
            $yearlyFees = $classFees['Yearly Fees'] ?? [];
            if (is_array($yearlyFees)) {
                $yearlyTotal = 0;
                foreach ($yearlyFees as $feeType => $amount) {
                    $yearlyTotal += (float)$amount;
                }
                if ($yearlyTotal > 0) {
                    $totalDue += $yearlyTotal;
                    $unpaidMonths[] = 'Yearly Fees';
                }
            }
        }

        if ($totalDue > 0) {
            $result['has_dues']      = true;
            $result['total_due']     = round($totalDue, 2);
            $result['unpaid_months'] = $unpaidMonths;

            $monthCount = count($unpaidMonths);
            $monthList  = implode(', ', array_slice($unpaidMonths, 0, 4));
            if ($monthCount > 4) $monthList .= ' +' . ($monthCount - 4) . ' more';
            $result['summary'] = "Outstanding dues: \u{20B9}" . number_format($totalDue, 2)
                               . " ({$monthCount} unpaid: {$monthList})";
        }

        return $result;
    }

    /**
     * Build map of enrolled student IDs for the current session.
     * Returns [ userId => true ]
     *
     * OPT 3: Single bulk read of the session root instead of 1 + C + S per-section reads.
     */
    /**
     * Cached raw snapshot of the school's full `students` collection.
     *
     * Same "smooth" pattern as Sis::search_student / Staff::all_staff — one
     * Firestore read per 90s instead of one per page visit, busted instantly
     * on any students write (see Firestore_service::_bustDashboard). Returns
     * the `[['id'=>…, 'data'=>[…]], …]` shape (schoolWhere), so callers that
     * read `$doc['data']` / `$doc['id']` work unchanged. Filtering by status /
     * session stays in PHP at each call site, which is exactly what the
     * read-only display pages (dashboard, ID cards, enrolled-id lookups)
     * already do — so this is a drop-in source swap, not a behaviour change.
     */
    private function _cached_student_docs(): array
    {
        $this->load->driver('cache', ['adapter' => 'file']);
        $cacheKey = $this->fs->listCacheKey('students_raw');
        $docs = $this->cache->get($cacheKey);
        if (!is_array($docs)) {
            $docs = $this->fs->schoolWhere('students');
            if (!is_array($docs)) $docs = [];
            $this->cache->save($cacheKey, $docs, 90);
        }
        return $docs;
    }

    private function _get_enrolled_ids(bool $includeNonActive = false): array
    {
        // Get students for this school. By default `status='Active'` only,
        // matching the legacy contract. When `$includeNonActive` is true
        // we accept any non-`Deleted` status so the students-list page
        // can surface Inactive / TC rows for review + reactivation —
        // pre-fix an Inactive student was invisible the moment admin
        // flipped the status, leaving no UI path back.
        //
        // Field-name compat:
        //   - 'status' (camelCase, new docs)
        //   - 'Status' (Title Case, legacy docs)
        //   - 'session' (single string) or 'sessions' (array)
        //
        // Sourced from the cached full-collection snapshot; the status gate
        // that used to be a Firestore `where` is applied in PHP below (it
        // checks both field-name casings, so it's strictly more tolerant
        // than the old camelCase-then-TitleCase fallback query).
        $studentDocs = $this->_cached_student_docs();

        $enrolledIds = [];
        $currentSession = $this->session_year;
        foreach ($studentDocs as $doc) {
            $d   = $doc['data'];
            $uid = $d['studentId'] ?? $d['User Id'] ?? $d['userId'] ?? $d['id'];

            // Status gate (now applied in PHP since the snapshot is unfiltered):
            //   - default        → Active only (legacy contract)
            //   - includeNonActive→ any status except hard-deleted
            $rowStatus = (string) ($d['status'] ?? $d['Status'] ?? 'Active');
            if ($includeNonActive) {
                if (strcasecmp($rowStatus, 'Deleted') === 0) continue;
            } else {
                if (strcasecmp($rowStatus, 'Active') !== 0) continue;
            }

            // Check session enrollment: canonical `session` singular takes
            // precedence; legacy `sessions[]` array is honored ONLY when the
            // singular field is absent/empty.
            // SW-CONVERGE-STUDENTS-A (2026-05-26): see Sis.php:2036 for full
            // rationale. v7 target = singular session as sole student-side
            // authority across all filter sites.
            $session  = $d['session']  ?? null;
            $sessions = $d['sessions'] ?? null;
            if (is_string($session) && $session !== '') {
                if ($session !== $currentSession) continue;
            } elseif (is_array($sessions) && !empty($sessions)) {
                if (!in_array($currentSession, $sessions, true)) continue;
            }
            // else: no enrollment record on doc — preserve prior behavior
            // (do not skip; caller may apply additional gates).

            $enrolledIds[$uid] = true;
        }
        return $enrolledIds;
    }

    /**
     * Get students enrolled in a specific class (and optionally section).
     * Returns [ userId => ['name'=>..., 'class'=>..., 'section'=>...] ]
     */
    private function _get_students_in_class(
        string $classOrd,
        string $section,
        string $session
    ): array {
        $classKey = Firestore_service::classKey($classOrd);
        // Scope by session too — without this, bulk operations pull students
        // whose record still references the queried class but whose session
        // has already moved on (e.g. pending-rollback cases, Alumni with
        // stale className).
        $conditions = [
            ['className', '==', $classKey],
            ['status',    '==', 'Active'],
            ['session',   '==', $session],
        ];
        if ($section && $section !== 'all') {
            $sectionKey = Firestore_service::sectionKey($section);
            $conditions[] = ['section', '==', $sectionKey];
        }
        $studentDocs = $this->fs->schoolWhere('students', $conditions, 'name', 'ASC');

        $students = [];
        foreach ($studentDocs as $doc) {
            $d = $doc['data'] ?? $doc;
            $s = $doc['data'];
            $uid = $s['studentId'] ?? $s['User Id'] ?? $d['id'];
            $students[$uid] = [
                'user_id' => $uid,
                'name'    => $s['name'] ?? $s['Name'] ?? $uid,
                // SRNO display (promotion roster) — the source document is
                // already loaded here, so surfacing the permanent school
                // register number costs ZERO extra reads. The operator
                // confirms identity against SR No. before a bulk promotion.
                'sr_no'   => (string) ($s['srNo'] ?? $s['srNumber'] ?? $s['SR Number'] ?? ''),
                'class'   => $s['className'] ?? $s['Class'] ?? $classOrd,
                'section' => $s['section'] ?? $s['Section'] ?? $section,
                // Phase 14.1 — carried so execute_promotion() can detect Roll
                // Number collisions in the destination section BEFORE moving
                // anyone. The source document is already loaded here, so this
                // costs ZERO extra reads. Display/validation only; promotion
                // still keys on user_id and never writes rollNo.
                'rollNo'  => (string) ($s['rollNo'] ?? $s['Roll No'] ?? ''),
            ];
        }
        return $students;
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT LIST (legacy all_student view)
       Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function all_student()
    {
        redirect('sis/students');
    }

    /* ══════════════════════════════════════════════════════════════════════
       BULK IMPORT — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function master_student()
    {
        $this->_require_role(self::VIEW_ROLES, 'master_student', 'SIS', 'view');
        $this->load->view('include/header');
        $this->load->view('import_students');
        $this->load->view('include/footer');
    }

    /**
     * Legacy direct-upload import (exact-template path). Parses the file and
     * runs every row through the shared importer. Kept as a fallback; the
     * primary UX is now the map → validate → commit flow (import_preview /
     * import_commit), which accepts any column layout.
     */
    public function import_students()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');
        try {
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) log_message('debug', '=== IMPORT FUNCTION STARTED ===');

            if (!isset($_FILES['excelFile']) || $_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
                redirect('sis/all_student');
                return;
            }

            $file = $_FILES['excelFile'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $reader = ($extension === 'csv') ? IOFactory::createReader('Csv') : IOFactory::createReader('Xlsx');
            $spreadsheet = $reader->load($file['tmp_name']);
            $sheetData   = $spreadsheet->getActiveSheet()->toArray();

            if (count($sheetData) <= 1) {
                redirect('sis/all_student');
                return;
            }

            $headers = array_map('trim', $sheetData[0]);
            unset($sheetData[0]);
            $sheetData = array_values($sheetData);

            // Build canonical associative rows (exact-template: header names
            // already match the canonical keys) and hand to the shared importer.
            $rows = [];
            foreach ($sheetData as $row) {
                if (!array_filter($row)) continue;
                if (count($headers) != count($row)) continue;
                $rows[] = array_combine($headers, $row);
            }

            $res = $this->_runStudentImport($rows);
            $this->session->set_flashdata('import_result', $this->_formatImportSummary($res));
            redirect('sis/all_student');
        } catch (Exception $e) {
            log_message('error', 'IMPORT ERROR: ' . $e->getMessage());
            $this->session->set_flashdata('import_result', "Import Failed! Check logs.");
            redirect('sis/all_student');
        }
    }

    /**
     * Shared per-row student persistence. Accepts a list of associative rows
     * keyed by canonical field names ("Name", "Class", "Section", "DOB", …).
     * Both the legacy exact-template path and the new mapped/validated commit
     * path funnel through here, so admission/fee/Auth/sync semantics stay
     * identical regardless of how the file was shaped.
     *
     * Returns ['success' => int, 'error' => int, 'skipped' => string[], 'feesPending' => string[]].
     */
    private function _runStudentImport(array $rows, array $opts = []): array
    {
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        // Duplicate policy (Phase 3, 2026-06-12):
        //   'create' — always create (legacy behaviour; default so the direct
        //              exact-template path is byte-for-byte unchanged).
        //   'skip'   — if a student with the same phone already exists (in the
        //              DB or earlier in this file), don't create; report it.
        //   'update' — merge the row's contact/demographic fields onto the
        //              existing student (class/section/status/fees preserved).
        // Default to the SAFE 'skip' (was 'create'): the legacy one-shot
        // import_students() path passes no opts, so defaulting to 'create' there
        // meant a re-run silently duplicated the whole roster. Callers that
        // truly want create-always must request it explicitly.
        $dupPolicy = in_array(($opts['dupPolicy'] ?? 'skip'), ['create', 'skip', 'update'], true)
            ? $opts['dupPolicy'] : 'skip';

        // Phase 9.2 (2026-08-13) — identity import mode. '' (unspecified)
        // is the exact pre-Phase-9.2 behavior: the legacy exact-template
        // caller (Sis::import_students(), confirmed dead/unreachable via
        // any current UI — no view posts to its route) passes no opts at
        // all, so it continues to create students with ZERO SRNO/APAAR
        // handling, exactly as before this phase. Retrofitting a mode
        // selector onto an unreachable path was judged unnecessary scope
        // expansion (documented in the implementation report). Only the
        // mapped-flow caller (import_commit(), reachable and live) ever
        // passes 'historical' or 'new'.
        $importMode = in_array(($opts['importMode'] ?? ''), ['historical', 'new'], true)
            ? $opts['importMode'] : '';

        $success     = 0;
        $error       = 0;
        $skipped     = [];
        $feesPending = []; // rows BLOCKED because their class/section has no fee chart (P1 #10)
        $feeChartMemo = []; // "class|section" => '' when a usable fee chart exists, else the error
        $duplicates  = []; // rows skipped as duplicates (skip policy)
        $updated     = 0;  // existing students updated (update policy)
        $credentials = []; // {name,phone,userId,password} per newly-created student (for the handout PDF)

        // SIB-B1 (Phase 13): phone(10) => [ normalisedName => studentId ].
        // NESTED BY NAME on purpose — a phone maps to potentially SEVERAL
        // students (siblings), so a flat phone=>id map structurally cannot
        // represent a family and was the root of the SIB-B1 data loss.
        $seenPhones  = [];
        $seenNameDob = []; // "name|dob" => studentId created earlier in THIS file
        // Phase 14.1: "class|section|roll" => studentName claimed earlier in
        // THIS file. Needed because rows created during this run are not yet in
        // the students snapshot the shared validator reads.
        $seenRolls   = [];
        // Phase 15 (HIGH-2): "class|section" => seats consumed by rows this run
        // has ALREADY created. The shared capacity validator reads a snapshot
        // that is memoised per request, so it cannot see students created
        // moments ago inside this same loop. Projecting
        // (live count + seats used this run + 1) is what makes an
        // over-capacity RESULT impossible without re-reading per row.
        $seatsUsedThisRun = [];

        // Each row does ~13-15 sequential network writes. Relax the limit on
        // EVERY call — the commit is chunked into 25-row batches, so the old
        // `> 50` guard never fired on the chunked path, leaving each batch
        // exposed to the default max_execution_time.
        @set_time_limit(0);
        @ignore_user_abort(true);

        // Load ID generator for globally unique student IDs
        $this->load->library('id_generator');

        // Phase 9.2 — same canonical services every other admission path
        // uses (Sis::_claimIdentityAttributes()); loaded once per import
        // rather than per row.
        if ($importMode !== '') {
            $this->load->library('Sr_no_service', null, 'srnoSvc');
            $this->srnoSvc->init($this->firebase, $this->fs, $school_id, (string) ($this->admin_id ?? 'system'));
        }

        $subjectCache = [];

        // ── PHASE 20.3K-Step1 — POST-WRITE ROUTINE (EXTRACTION ONLY) ────
        //
        // The per-row work that follows a successful saveStudent(), moved out
        // of the row loop VERBATIM apart from one mechanical change: the five
        // `continue;` statements that controlled the OUTER row loop are now
        // `return 'SKIP';`, and the caller performs the continue. A closure
        // cannot continue the loop it was called from.
        //
        // The two inner `$rawList` continues are UNCHANGED — they still
        // control only the subjects loop.
        //
        // No business logic, ordering, rollback or result semantics changed.
        // Equivalence is proven by the Phase 20.3K golden master comparing
        // ordered side-effect traces before and after this move.
        //
        // Per-row values arrive via $ctx (compact/extract) rather than a
        // 16-parameter signature; mutable result state is captured by
        // reference so the existing contract keeps working untouched.
        $postWrite = function (array $ctx) use (
            &$success, &$error, &$skipped, &$feesPending, &$credentials,
            &$seenPhones, &$seenRolls, &$seenNameDob, &$seatsUsedThisRun, &$subjectCache,
            $school_id, $school_name, $session_year, $importMode
        ) {
            extract($ctx, EXTR_OVERWRITE);
                // Phase 9.2 — the ACTUAL atomic identity claim/allocation.
                // The pre-flight check above (Sr_no_service::resolve())
                // catches the common case before a Student ID is burned;
                // this is the race-safe, authoritative step — reuses the
                // SAME Sr_no_service::claim()/allocateNext() primitives
                // every other admission path calls, never a second
                // mechanism. A failure HERE (a genuine race the pre-check
                // could not see, or a row whose SR Number was claimed by a
                // concurrent import row a moment earlier) must not leave a
                // half-identified student behind: the just-created student
                // document AND the Student ID claim are both rolled back,
                // and the row is rejected outright — never a silent
                // reassignment, never an overwrite of the existing owner.
                if ($importMode === 'historical' && $srNoRaw !== '') {
                    $srRes = $this->srnoSvc->claim($srNoRaw, $studentId);
                    if (empty($srRes['ok'])) {
                        $this->entity_sync->deleteStudent($studentId);
                        $this->id_generator->releaseClaim('STU', (int) preg_replace('/\D/', '', $studentId));
                        $reason = (string) ($srRes['reason'] ?? 'unknown');
                        $skipped[] = "Row {$rowNo}: {$studentName} — SR Number {$srNoRaw} could not be claimed (reason: {$reason}); row rejected, no student created";
                        $error++;
                        return 'SKIP';   // P20.3K-Step1: was `continue;` (outer row loop)
                    }
                    $srnoFields = ['srNo' => $srNoRaw, 'srNoNorm' => \Sr_no_service::normalize($srNoRaw)['norm'] ?? '', 'srNoSource' => 'import_historical', 'updatedAt' => date('c')];
                    // Phase 10.2 — Admission Number, system-generated via the
                    // SAME service every other path uses. Never operator-
                    // supplied: there is no 'Admission Number' import column
                    // in Import_schema, so a spreadsheet cannot inject one.
                    // HISTORICAL-MODE YEAR CAVEAT (unchanged from 10.1, still
                    // disclosed): the YEAR component is the CURRENT session,
                    // not the student's real original admission year — the
                    // import file has no canonical field supplying it. See
                    // the report §J for why this is left as-is rather than
                    // guessed at.
                    $importAdmNo = $this->_allocateAdmissionNumber($studentId, $school_id);
                    if ($importAdmNo !== '') {
                        $srnoFields['Admission Number'] = $importAdmNo;
                        $srnoFields['admissionNumber']  = $importAdmNo;
                    }
                    $this->fs->update('students', $this->fs->docId($studentId), $srnoFields);
                    $studentData = array_merge($studentData, $srnoFields);
                } elseif ($importMode === 'new') {
                    $srRes = $this->srnoSvc->allocateNext($school_id, $studentId);
                    if (empty($srRes['ok'])) {
                        $this->entity_sync->deleteStudent($studentId);
                        $this->id_generator->releaseClaim('STU', (int) preg_replace('/\D/', '', $studentId));
                        $reason = (string) ($srRes['reason'] ?? 'unknown');
                        $skipped[] = "Row {$rowNo}: {$studentName} — SR Number could not be allocated automatically (reason: {$reason}); row rejected, no student created";
                        $error++;
                        return 'SKIP';   // P20.3K-Step1: was `continue;` (outer row loop)
                    }
                    $assignedSrNo = (string) $srRes['srNo'];
                    $srnoFields = ['srNo' => $assignedSrNo, 'srNoNorm' => \Sr_no_service::normalize($assignedSrNo)['norm'] ?? '', 'srNoSource' => 'import_auto', 'updatedAt' => date('c')];
                    // Phase 10.2 — same service; for new-admission import the
                    // current session IS the correct admission year, so no
                    // caveat applies here (unlike the historical branch).
                    $importAdmNo = $this->_allocateAdmissionNumber($studentId, $school_id);
                    if ($importAdmNo !== '') {
                        $srnoFields['Admission Number'] = $importAdmNo;
                        $srnoFields['admissionNumber']  = $importAdmNo;
                    }
                    $this->fs->update('students', $this->fs->docId($studentId), $srnoFields);
                    $studentData = array_merge($studentData, $srnoFields);
                }
                // $importMode === '' (the dead legacy exact-template caller,
                // see the $importMode assignment's own comment above) — no
                // SRNO handling at all, exactly the pre-Phase-9.2 behavior.

                // APAAR — optional in BOTH modes, per the locked decision
                // ("do NOT make APAAR mandatory merely because bulk import
                // supports it"). SAME Apaar_service::claim() every other
                // path uses; a duplicate is rejected (row skipped, student
                // rolled back), never silently overwritten — no different
                // from how a duplicate SRNO is handled above.
                $apaarRaw = trim((string) ($rowData['APAAR ID'] ?? ''));
                if ($apaarRaw !== '') {
                    $this->load->library('Apaar_service', null, 'apaarSvc');
                    $apaarRes = $this->apaarSvc->init($this->firebase, $this->fs, $school_id, (string) ($this->admin_id ?? 'system'))
                        ->claim($apaarRaw, $studentId);
                    if (empty($apaarRes['ok'])) {
                        $this->entity_sync->deleteStudent($studentId);
                        $this->id_generator->releaseClaim('STU', (int) preg_replace('/\D/', '', $studentId));
                        $reason = (string) ($apaarRes['reason'] ?? 'unknown');
                        $skipped[] = "Row {$rowNo}: {$studentName} — APAAR ID could not be claimed (reason: {$reason}); row rejected, no student created";
                        $error++;
                        return 'SKIP';   // P20.3K-Step1: was `continue;` (outer row loop)
                    }
                    $apaarFields = ['apaarId' => \Apaar_service::normalize($apaarRaw), 'updatedAt' => date('c')];
                    $this->fs->update('students', $this->fs->docId($studentId), $apaarFields);
                    $studentData = array_merge($studentData, $apaarFields);
                }

                $phone = trim($rowData['Phone Number'] ?? '');
                if ($phone !== '') {
                    // P14-2 (Phase 14.1) — the three RTDB writes that stood here
                    // are REMOVED:
                    //     Schools/{school}/Phone_Index/   User_ids_pno/   Exits/
                    // They went through Common_model::addKey_pair_data(), i.e.
                    // firebaseDatabase->getReference()->update() — live Realtime
                    // Database writes in the admission path, contrary to the
                    // Firestore-only architecture. Every previous RTDB sweep
                    // missed them because they use a different call shape than
                    // the `$this->firebase->set/get` pattern those greps matched.
                    //
                    // READER SWEEP (done before removal — two readers DO exist,
                    // and neither depends on these writes continuing):
                    //
                    //  1. Health_check.php:417-440 verifies Phone_Index/Exits
                    //     against RTDB profiles it enumerates from
                    //     `Users/Parents/{school_id}`. New admissions have not
                    //     written RTDB profiles since the no-RTDB migration, so
                    //     a newly-admitted student never appears in that
                    //     enumeration and is never checked. The check therefore
                    //     cannot start failing because of this removal; for a
                    //     Firestore-era school it already short-circuits with
                    //     "No profiles to check".
                    //
                    //  2. Superadmin_migration.php:313-339 is a one-time
                    //     backfill that READS the pre-existing legacy nodes to
                    //     copy them into tenant-scoped paths. It operates on
                    //     historical data and does not require new writes.
                    //
                    // Phone resolution for all live logic is served by the
                    // Firestore `indexPhones` collection (_existingStudentIdsByPhone).
                    //
                    // No fallback, mirror or dual-write replaces them.
                    //
                    // P14-1: append-not-overwrite, so siblings sharing this phone
                    // all remain registered.
                    $this->_registerPhoneIndex($phone, $studentId, 'student');
                }

                // Update Students_Index
                $this->_update_student_index($school_name, $studentId, $studentName, $className, $section, 'Active', trim($rowData['Gender'] ?? ''));

                // Initialize Month Fee markers as unpaid (0) for all 12 months
                // SIS Wave-2 fix F5 (2026-05-31): fail-loud guard per row.
                // Pre-fix, a Firestore failure here was logged but the loop
                // continued — row counted as $success while student doc had
                // no monthFee. Current ordering already places this BEFORE
                // Firebase Auth at L3336+, so a per-row skip cleanly aborts
                // before any Auth/sync side-effect (Option-3 intent achieved
                // without reorder — current code is already correct).
                $classKey   = $className;   // Already prefixed ("Class 8th")
                $sectionKey = $section;    // Already prefixed ("Section A")
                $studentFeePath = "Schools/{$school_name}/{$session_year}/{$classKey}/{$sectionKey}/Students/{$studentId}";
                $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
                $monthFeeInit = [];
                foreach ($months as $m) {
                    $monthFeeInit[$m] = 0;
                }
                $monthFeeOk = false;
                try {
                    // Firestore-only per no-RTDB policy.
                    $monthFeeOk = (bool) $this->fs->updateEntity('students', $studentId, ['monthFee' => $monthFeeInit]);
                } catch (Exception $e) {
                    log_message('error', "SIS import fee init failed for {$studentId}: " . $e->getMessage());
                }
                if (!$monthFeeOk) {
                    $skipped[] = "Row {$rowNo}: {$studentName} — monthFee init failed (see log); student doc remains without fee tracking";
                    $error++;
                    return 'SKIP';   // P20.3K-Step1: was `continue;` (outer row loop)
                }

                // Auto-assign class fees for imported student
                try {
                    $this->feeLifecycle->assignInitialFees($studentId, $className, $section, $school_id);

                    // ── CONS-2 · SEED THE DEFAULTER PROJECTION ──────────────
                    // Same gap Phase 3D closed for direct admission:
                    // assignInitialFees writes feeDemands but emits NO defaulter
                    // event, so every imported student would carry unpaid
                    // demands and NO feeDefaulters document.
                    //
                    // On a bulk import that is the worst version of the defect:
                    // an ENTIRE INTAKE is invisible to the school's defaulter
                    // report at once. Their dues are correct everywhere that
                    // reads feeDemands — including the Parent app — so nothing
                    // looks wrong until the school wonders why the report is
                    // short.
                    //
                    // COST: one write per student, inside a loop that already
                    // performs several Firestore writes per row (student doc,
                    // monthFee init, fee assignment, subject assignment). It
                    // does not change the order of the import's cost.
                    //
                    // Placed INSIDE the success branch deliberately: if fee
                    // assignment threw, there are no demands to project, and
                    // writing a "not a defaulter" record would assert something
                    // untrue about a student whose billing is broken.
                    //
                    // Fail-soft per student — one bad projection must not abort
                    // an import of hundreds.
                    try {
                        $this->feeDefaulter->updateDefaulterStatus($studentId);
                    } catch (\Throwable $e) {
                        log_message('error', "CONS-2 import defaulter sync failed for {$studentId}: " . $e->getMessage());
                    }
                } catch (Exception $e) {
                    log_message('error', "Fee_lifecycle bulk import fee assign failed for {$studentId}: " . $e->getMessage());
                }

                // Subject assignment
                if (!isset($subjectCache[$classNumber])) {
                    $subjectCache[$classNumber] = ['core' => [], 'allSubjects' => [], 'additionalSubjects' => []];
                    // Firestore first → RTDB fallback
                    $rawList = [];
                    $fsDocs = $this->fs->schoolWhere('subjects', [['classKey', '==', (string)$classNumber]]);
                    if (is_array($fsDocs) && !empty($fsDocs)) {
                        foreach ($fsDocs as $doc) {
                            $d = $doc['data'] ?? $doc;
                            $code = $d['subject_code'] ?? $d['code'] ?? '';
                            if ($code !== '') $rawList[$code] = $d;
                        }
                    }
                    // RTDB subject fallback removed per no-RTDB policy.
                    if (is_array($rawList)) {
                        foreach ($rawList as $code => $item) {
                            if (!is_array($item)) continue;
                            $subName = trim($item['subject_name'] ?? $item['name'] ?? '');
                            if ($subName === '') continue;
                            $type = strtolower(trim($item['category'] ?? ''));
                            if ($type === 'additional') {
                                $subjectCache[$classNumber]['additionalSubjects'][$subName] = "";
                            } else {
                                $subjectCache[$classNumber]['allSubjects'][(string)$code] = $subName;
                                if ($type === 'core') {
                                    $subjectCache[$classNumber]['core'][(string)$code] = ['name' => $subName, 'type' => 'core'];
                                }
                            }
                        }
                    }
                    // RTDB All Subjects mirror removed per no-RTDB policy.
                }

                if (!empty($subjectCache[$classNumber]['core'])) {
                    // Firestore-only per no-RTDB policy.
                    $this->fs->updateEntity('students', $studentId, ['subjects' => $subjectCache[$classNumber]['core']]);
                }
                if (!empty($subjectCache[$classNumber]['additionalSubjects'])) {
                    $this->fs->updateEntity('students', $studentId, ['additionalSubjects' => $subjectCache[$classNumber]['additionalSubjects']]);
                }

                // Create Firebase Auth user (best-effort, don't block import on failure)
                // SIS Wave-3 D3 (2026-05-31): consolidated via _createFirebaseAuthStudent.
                // Return value intentionally ignored — import_students's pre-fix
                // behavior was silent-continue per row on Auth failure. Each row
                // becomes a separate Auth attempt; one row's failure doesn't
                // abort the rest of the import (matches B2 per-row pattern).
                $this->_createFirebaseAuthStudent($studentId, $password, $studentName, 'SIS import_students');

                // Firestore sync for Android apps (entity_sync loaded in constructor)
                // SIS Wave-2 S6 (2026-05-31): observability for sync return.
                if (!$this->entity_sync->syncStudent($studentId, $studentData)) {
                    log_message('warning', "syncStudent returned false for {$studentId} (import_students)");
                }
                if (!$this->entity_sync->syncParent($studentId, $studentData)) {
                    log_message('warning', "syncParent returned false for {$studentId} (import_students)");
                }

                // SIS Tier 2 carry (2026-05-30): emit ADMISSION history entry
                // mirroring save_admission@536. Without this, students imported
                // via the bulk-Excel path had no ADMISSION row in their History
                // array (forensic-documented gap).
                $this->_log_history($school_id, $studentId, 'ADMISSION',
                    "Student admitted to {$className} / {$section} ({$session_year})",
                    ['class' => $className, 'section' => $section, 'session' => $session_year]
                );

                // P1 #10: rows without a fee chart are now blocked and `continue`d
                // above, so control only reaches here when $hasFeeChart is true.
                // The former `if (!$hasFeeChart) $feesPending[] = ...` here is
                // therefore unreachable and has been removed — $feesPending is
                // populated at the block site instead.

                // Record keys so a later row in the SAME file matching this
                // student (skip/update policies) is caught as a duplicate.
                // SIB-B1 (Phase 13): keyed by phone AND name so a later sibling
                // row sharing this phone is not mistaken for this student.
                if ($phone10 !== '' && strlen($phone10) === 10 && $nameNorm !== '') {
                    $seenPhones[$phone10][$nameNorm] = $studentId;
                }
                // Phase 14.1: claim this roll for the rest of the file.
                if ($rowRoll !== '') {
                    $seenRolls[mb_strtolower("{$className}|{$section}|{$rowRoll}")] = $studentName;
                }
                // Phase 15: this row consumed a seat — subsequent rows for the
                // same class+section must project against it.
                $seatKey = mb_strtolower("{$className}|{$section}");
                $seatsUsedThisRun[$seatKey] = ($seatsUsedThisRun[$seatKey] ?? 0) + 1;
                if ($dobKey !== '') $seenNameDob[$nameKey] = $studentId;

                // Capture the login handout row (plaintext password is only known
                // here at creation time). Missing phone stays '' → blank in the PDF.
                // Phase 11 (P11-G1) — carry the school identity fields onto the
                // handout row. Both were merged into $studentData by the SRNO /
                // Admission Number blocks earlier in this same iteration, so this
                // costs ZERO extra Firestore reads. APAAR is deliberately absent:
                // it is PII under the masked + audited-reveal policy and must
                // never reach a printable handout.
                $credentials[] = [
                    'name'        => $studentName,
                    'phone'       => $phone,
                    'userId'      => $studentId,
                    'password'    => $password,
                    'srNo'        => (string) ($studentData['srNo'] ?? ''),
                    'admissionNo' => (string) ($studentData['admissionNumber'] ?? $studentData['Admission Number'] ?? ''),
                ];

                $success++;
            return 'OK';
        };

        // P20.3K-Step2 — Pass 1 buffer, keyed by destination section. Stays
        // empty while the atomic capacity flag is OFF, so flag-off Import is
        // byte-for-byte the Step 1 path (proven by the frozen golden master).
        $importGroups = [];
        foreach ($rows as $idx => $rowData) {
            if (!is_array($rowData)) { $error++; continue; }
            $rowNo = $idx + 1;

                $studentName = trim($rowData['Name'] ?? '');
                $classRaw    = trim($rowData['Class'] ?? '');
                $section     = trim($rowData['Section'] ?? '');
                if (!$studentName || !$classRaw || !$section) {
                    $skipped[] = "Row {$rowNo}: missing required Name/Class/Section";
                    $error++;
                    continue;
                }

                preg_match('/\d+/', $classRaw, $match);
                if (!isset($match[0])) {
                    $skipped[] = "Row {$rowNo}: {$studentName} — Class \"{$classRaw}\" has no recognizable number (1–12)";
                    $error++;
                    continue;
                }
                $classNumber = (int)$match[0];

                // ── Duplicate detection (skip/update policies) ───────────────
                $phone10 = preg_replace('/\D+/', '', (string) ($rowData['Phone Number'] ?? ''));
                if (strlen($phone10) > 10) $phone10 = substr($phone10, -10);
                $dobKey  = trim((string) ($rowData['DOB'] ?? ''));
                $nameKey = strtolower($studentName) . '|' . $dobKey;

                // SIB-B1 FIX (Phase 13, Workstream B) — a shared parent phone is
                // NOT proof of a duplicate student.
                //
                // Siblings legitimately share a father's/mother's/guardian's
                // mobile. The previous rule treated phone equality ALONE as
                // "same student", so importing two siblings from one file meant
                // the second child was either silently discarded (skip policy)
                // or OVERWROTE the first child's record (update policy). That is
                // data loss, and it is the documented SIB-B1 defect.
                //
                // A duplicate now requires phone match AND name corroboration:
                //   - two siblings     → same phone, DIFFERENT names → both imported ✔
                //   - re-imported file → same phone, SAME name       → still deduped ✔
                //
                // Name (not name+DOB) is the corroborator on purpose: the same
                // student re-exported with DOB present in one file and blank in
                // another must still dedupe, which a name+DOB key would miss.
                $nameNorm = strtolower(preg_replace('/\s+/', ' ', trim($studentName)));

                if ($dupPolicy !== 'create') {
                    $existingId = null; $dupReason = '';
                    if ($phone10 !== '' && strlen($phone10) === 10 && $nameNorm !== '') {
                        if (isset($seenPhones[$phone10][$nameNorm])) {
                            $existingId = $seenPhones[$phone10][$nameNorm];
                            $dupReason = 'duplicate phone + name within file';
                        } else {
                            // P14-1 (Phase 14.1): a phone can legitimately belong
                            // to SEVERAL students (siblings), so every
                            // registered candidate is name-corroborated — not
                            // just whichever one happened to be written last.
                            // Without this, re-importing a sibling file created
                            // duplicate students.
                            //
                            // Costs 1 read for the index, plus at most one read
                            // per candidate until a name matches — and only on
                            // rows whose phone is already known. An ordinary
                            // first-time admission row reads nothing extra.
                            foreach ($this->_existingStudentIdsByPhone($phone10) as $candidateId) {
                                if ($this->_studentNameMatches($candidateId, $nameNorm)) {
                                    $existingId = $candidateId;
                                    $dupReason = 'existing student (phone + name match)';
                                    break;
                                }
                            }
                        }
                    }
                    if (!$existingId && $dobKey !== '' && isset($seenNameDob[$nameKey])) {
                        $existingId = $seenNameDob[$nameKey]; $dupReason = 'duplicate name+DOB within file';
                    }

                    if ($existingId) {
                        if ($dupPolicy === 'update') {
                            if ($this->_updateExistingStudent($existingId, $rowData)) {
                                $updated++;
                            } else {
                                $skipped[] = "Row {$rowNo}: {$studentName} — update of {$existingId} failed (see log)";
                                $error++;
                            }
                        } else {
                            $duplicates[] = "Row {$rowNo}: {$studentName} — {$dupReason} ({$existingId})";
                        }
                        continue;
                    }
                }

                // Phase 16.2 — shared with import_validate() so the preview and
                // the commit can never derive different placement keys. See
                // _importPlacementKeys().
                $__keys    = $this->_importPlacementKeys($classNumber, $section);
                $className = $__keys['className'];
                $section   = $__keys['section'];
                $combinedClass = "{$className}/{$section}";

                // ── Phase 15 (HIGH-2) — Section capacity on IMPORT ───────────
                //
                // Bulk import could previously drop 50 students into a 40-seat
                // section without a word. Now every row is projected against
                // the section's real remaining headroom BEFORE it is created,
                // so no combination of rows can produce an over-capacity
                // section.
                //
                // WHY A RUNNING COUNTER, NOT A PER-ROW QUERY: the shared
                // validator reads a request-memoised snapshot, so rows created
                // earlier in this same loop are invisible to it.
                // `$seatsUsedThisRun` closes that window. Net effect for a
                // 500-row import spanning 3 sections: ONE roster snapshot +
                // THREE section-capacity reads total — NOT 500 capacity
                // queries. No N+1.
                //
                // TRANSACTION MODEL PRESERVED: this loop is chunked into
                // 25-row batches by design and already has row-level
                // skip/error semantics. A capacity-blocked row is therefore
                // skipped with an explicit reason (increment $error, record in
                // $skipped, continue) — exactly like the fee-chart and roll
                // guards — rather than aborting the whole file. That keeps the
                // documented partial-commit behaviour while making an
                // over-capacity outcome unreachable. Valid rows for OTHER
                // sections are unaffected: each class+section is projected
                // independently.
                $capUsed  = $seatsUsedThisRun[mb_strtolower("{$className}|{$section}")] ?? 0;
                $capOver  = $this->_sectionCapacityExceeded(
                    $className, $section, $session_year, $capUsed + 1
                );
                if ($capOver !== null) {
                    $clsDisp = trim(preg_replace('/^Class\s+/i', '', $className));
                    $secDisp = trim(preg_replace('/^Section\s+/i', '', $section));
                    $skipped[] = "Row {$rowNo}: {$studentName} — Class {$clsDisp} Section {$secDisp} is full "
                               . "(capacity {$capOver['capacity']}, already {$capOver['current']} active"
                               . ($capUsed > 0 ? " + {$capUsed} from this import" : '')
                               . "); row not imported";
                    $error++;
                    continue;
                }

                // ── Phase 14.1 — Roll Number roster uniqueness on IMPORT ─────
                // Two collision sources, both rejected BEFORE the student is
                // created (so no Student ID is burned and no SRNO is claimed):
                //   (a) an EARLIER ROW in this same file, tracked in
                //       $seenRolls — the DB snapshot cannot see rows this run
                //       has not written yet;
                //   (b) an EXISTING active student, via the shared validator.
                //
                // Per-row skip, matching this loop's established failure shape
                // (increment $error, record a reason, continue) — deliberately
                // NOT a whole-file abort, and NOT a new partial-import policy.
                // A blank roll is skipped: assigning later is normal.
                $rowRoll = trim((string) ($rowData['Roll No'] ?? ''));
                if ($rowRoll !== '') {
                    $rollScopeKey = mb_strtolower("{$className}|{$section}|{$rowRoll}");
                    if (isset($seenRolls[$rollScopeKey])) {
                        $skipped[] = "Row {$rowNo}: {$studentName} — Roll Number {$rowRoll} is already used by "
                                   . "{$seenRolls[$rollScopeKey]} earlier in this file for {$className}/{$section}";
                        $error++;
                        continue;
                    }
                    $rollConflict = $this->_rollNoConflict($rowRoll, $className, $section, $session_year);
                    if ($rollConflict !== null) {
                        $who = $rollConflict['name'] !== '' ? $rollConflict['name'] : $rollConflict['studentId'];
                        $skipped[] = "Row {$rowNo}: {$studentName} — Roll Number {$rowRoll} is already assigned to "
                                   . "an active student ({$who}) in {$className}/{$section}";
                        $error++;
                        continue;
                    }
                }

                // P1 #10 (2026-08-12, operator decision): bulk import now
                // BLOCKS rows whose class/section has no fee chart, matching
                // save_admission()/execute_promotion()/enroll_student(). A
                // student must never become Active without the fee setup that
                // makes them billable.
                //
                // This REVERSES the Phase 1 decision (2026-06-12) that imported
                // such rows as Active and merely listed them in $feesPending for
                // the summary. That was a deliberate onboarding concession — a
                // school importing its roster before configuring Fees → Chart
                // would otherwise import nothing — but it is exactly the
                // Active-with-zero-demands state the STU0004-11 incident was
                // about, and the operator has chosen data integrity over
                // onboarding convenience.
                //
                // Per-row skip (not a whole-file abort) to match this loop's
                // established failure shape: increment $error, record a reason
                // in $skipped, continue. Placed BEFORE _nextStudentId() so a
                // blocked row does not burn a student ID off the central
                // counter. $feesPending is retained and still populated so the
                // operator gets the same "which class/sections need a chart"
                // guidance — it now lists BLOCKED rows rather than admitted ones.
                //
                // Memoised per class/section: the pre-fix code issued one
                // feeStructures read per ROW, so a 500-row single-class import
                // cost 500 identical reads. Keyed reads collapse that to one
                // per distinct class/section.
                $feeChartKey = "{$className}|{$section}";
                if (!isset($feeChartMemo[$feeChartKey])) {
                    $feeChartMemo[$feeChartKey] = $this->_fee_structure_missing(
                        $className,
                        $section,
                        $session_year,
                        'Import'
                    );
                }
                $hasFeeChart = ($feeChartMemo[$feeChartKey] === '');
                if (!$hasFeeChart) {
                    $feesPending[] = "{$studentName} ({$className}/{$section})";
                    $skipped[]     = "Row {$rowNo}: {$studentName} — no fee structure for {$className}/{$section} in {$session_year}; set up Fees → Chart and re-import";
                    $error++;
                    continue;
                }

                // Phase 9.2 — Historical-mode SR Number pre-flight, BEFORE
                // _nextStudentId() burns a Student ID off the global
                // counter, matching the exact same placement rationale the
                // fee-chart gate above already documents. This is a
                // read-only pre-check (Sr_no_service::resolve(), no write)
                // to catch the COMMON case cheaply; the actual atomic,
                // race-safe ownership claim happens below, AFTER the
                // student document exists — see that block for why a
                // pre-check alone is not sufficient on its own.
                $srNoRaw = trim((string) ($rowData['SR Number'] ?? ''));
                if ($importMode === 'historical') {
                    if ($srNoRaw === '') {
                        $skipped[] = "Row {$rowNo}: {$studentName} — SR Number is required in Historical mode";
                        $error++;
                        continue;
                    }
                    $preCheck = $this->srnoSvc->resolve($srNoRaw);
                    if (!empty($preCheck['ok'])) {
                        $skipped[] = "Row {$rowNo}: {$studentName} — SR Number {$srNoRaw} is already assigned to {$preCheck['currentStudentId']} in this school";
                        $error++;
                        continue;
                    }
                } elseif ($importMode === 'new' && $srNoRaw !== '') {
                    // Structural guarantee, not merely a UI convention: even
                    // if a client somehow sent a non-blank SR Number for a
                    // 'new'-mode row (import_commit()'s own validateRow()
                    // call already blanks this server-side, so reaching
                    // here with a non-empty value should be unreachable —
                    // defense-in-depth only), it is NEVER read for
                    // allocation purposes below.
                    $srNoRaw = '';
                }

                // Generate globally unique student ID from central counter
                $studentId = $this->_nextStudentId($school_id);
                if (!$studentId) {
                    $skipped[] = "Row {$rowNo}: {$studentName} — ID generation failed";
                    $error++;
                    continue;
                }

                $formattedDOB = '';
                if (!empty($rowData['DOB'])) $formattedDOB = date('d-m-Y', strtotime($rowData['DOB']));
                $formattedAdmDate = '';
                if (!empty($rowData['Admission Date'])) $formattedAdmDate = date('d-m-Y', strtotime($rowData['Admission Date']));

                $password = $this->_generatePassword($studentName, $formattedDOB);

                $studentData = [
                    "Name" => $studentName, "User Id" => $studentId, "DOB" => $formattedDOB,
                    "Admission Date" => $formattedAdmDate, "Class" => $className, "Section" => $section,  // Already prefixed via classKey/sectionKey
                    "Gender" => trim($rowData['Gender'] ?? ''), "Blood Group" => trim($rowData['Blood Group'] ?? ''),
                    "Category" => trim($rowData['Category'] ?? ''), "Religion" => trim($rowData['Religion'] ?? ''),
                    "Nationality" => trim($rowData['Nationality'] ?? ''),
                    "Father Name" => trim($rowData['Father Name'] ?? ''), "Father Occupation" => trim($rowData['Father Occupation'] ?? ''),
                    "Mother Name" => trim($rowData['Mother Name'] ?? ''), "Mother Occupation" => trim($rowData['Mother Occupation'] ?? ''),
                    "Guard Contact" => trim($rowData['Guard Contact'] ?? ''), "Guard Relation" => trim($rowData['Guard Relation'] ?? ''),
                    "Phone Number" => trim($rowData['Phone Number'] ?? ''), "Email" => trim($rowData['Email'] ?? ''),
                    "Password" => $password,
                    "Address" => [
                        "Street" => trim($rowData['Street'] ?? ''), "City" => trim($rowData['City'] ?? ''),
                        "State" => trim($rowData['State'] ?? ''), "PostalCode" => trim($rowData['PostalCode'] ?? ''),
                    ],
                    "Pre School" => trim($rowData['Pre School'] ?? ''), "Pre Class" => trim($rowData['Pre Class'] ?? ''),
                    "Pre Marks" => trim($rowData['Pre Marks'] ?? ''),
                    // Phase 9.2 — free text, no forced numeric conversion,
                    // matching save_admission()'s own Roll No handling
                    // exactly (the audited existing architecture has no
                    // uniqueness enforcement for this field anywhere — not
                    // invented here either).
                    "Roll No" => trim($rowData['Roll No'] ?? ''),
                    "Profile Pic" => "",
                    "Status" => "Active",
                    "Doc" => [
                        "Aadhar Card" => ["thumbnail" => "", "url" => ""],
                        "Birth Certificate" => ["thumbnail" => "", "url" => ""],
                        "Photo" => ["thumbnail" => "", "url" => ""],
                        "Transfer Certificate" => ["thumbnail" => "", "url" => ""],
                    ],
                ];

                // Firestore-only per no-RTDB policy. RTDB profile + roster mirror removed.
                // SIS Tier-1 fix B2 (2026-05-31): saveStudent must be fail-loud
                // per row. Pre-fix, this call had no try/catch and no return
                // check — a Firestore failure either threw (killing the whole
                // import mid-loop via the outer catch) or returned false
                // silently (loop continued, row was counted as $success, but
                // the student doc never existed). The import summary lied
                // about which rows imported.
                // Mirrors the B1 pattern from enroll_student@4627; the
                // loop-shape difference is that a per-row failure increments
                // $error and adds a $skipped[] entry then `continue`s to the
                // next row, instead of aborting the entire import.
                // ── PHASE 20.3K-Step2 — PATH 3/5: IMPORT (P20.2-RACE-1) ─────
                //
                // PASS 1: everything above already ran — validation, supplied
                // SRNO resolution, Student ID mint, payload build. Instead of
                // writing now, buffer against the DESTINATION SECTION so the
                // write happens once per destination in Pass 2: one section
                // read and one atomic commit per group, never one per row.
                //
                // Nothing identity-bearing has been claimed yet — SRNO, APAAR
                // and Admission Number all live inside postWrite(), which Pass 3
                // runs ONLY for students whose group actually committed. A
                // capacity or CAS refusal therefore consumes no SRNO, preserving
                // the ordering proven in 20.3e-1 and observed in the golden master.
                if ($this->_seatGuardEnabled()) {
                    $__dk = mb_strtolower("{$className}|{$section}");
                    if (!isset($importGroups[$__dk])) {
                        $importGroups[$__dk] = ['className' => $className, 'section' => $section, 'rows' => []];
                    }
                    $importGroups[$__dk]['rows'][] = [
                        'studentId'   => $studentId,
                        'studentData' => $studentData,
                        'rowNo'       => $rowNo,
                        'studentName' => $studentName,
                        'ctx'         => compact(
                            'studentId', 'studentData', 'rowNo', 'studentName',
                            'className', 'section', 'classNumber', 'dobKey',
                            'nameKey', 'nameNorm', 'password', 'phone10',
                            'rowRoll', 'srNoRaw', 'hasFeeChart', 'rowData'
                        ),
                    ];
                    continue;   // Pass 2 commits it; Pass 3 replays postWrite()
                }

                $studentSaved = false;
                try {
                    $studentSaved = (bool) $this->fs->saveStudent($studentId, $studentData);
                } catch (\Exception $e) {
                    log_message('error', "SIS import saveStudent failed for {$studentId}: " . $e->getMessage());
                }
                if (!$studentSaved) {
                    // Release the claimed STU id so a failed write doesn't burn the number.
                    $this->id_generator->releaseClaim('STU', (int) preg_replace('/\D/', '', $studentId));
                    $skipped[] = "Row {$rowNo}: {$studentName} — Firestore save failed (see log)";
                    $error++;
                    continue;
                }

                // P20.3K-Step1 — post-write routine (was inline here).
                if ($postWrite(compact(
                    'studentId', 'studentData', 'rowNo', 'studentName',
                    'className', 'section', 'classNumber', 'dobKey',
                    'nameKey', 'nameNorm', 'password', 'phone10',
                    'rowRoll', 'srNoRaw', 'hasFeeChart', 'rowData'
                )) === 'SKIP') {
                    continue;
                }
        }

        // ── PHASE 20.3K-Step2 — PASS 2 (atomic group) + PASS 3 (postWrite) ──
        //
        // One destination section = ONE capacity decision = ONE atomic commit.
        //
        // $seatsUsedThisRun is NOT consulted here and takes no part in this
        // decision. It remains the flag-OFF running projection, incremented
        // inside postWrite() which now runs only AFTER a group has committed —
        // so there is exactly one authoritative capacity decision per group and
        // no second occupancy authority. Occupancy comes from
        // _sectionCapacityExceeded(), which derives it solely from student docs.
        if ($this->_seatGuardEnabled() && !empty($importGroups)) {
            $this->load->library('section_seat_guard', null, 'seatGuard');
            $this->seatGuard->init(
                $this->fs, $this->firebase, (string) $this->school_id,
                (string) ($this->admin_id ?? 'system')
            );
            $__max = Section_seat_guard::groupMaxStudents();

            foreach ($importGroups as $__g) {
                $__rows = $__g['rows'];
                $__n    = count($__rows);
                $__cls  = $__g['className'];
                $__sec  = $__g['section'];
                $__lbl  = "{$__cls} / {$__sec}";

                // Mark every row of a refused group — never silently succeed.
                //
                // ── PHASE 20.3M (P20.3L-F1) — RETURN THE CLAIMED STUDENT IDs ────
                // Pass 1 minted a Student ID per row (:6090) before the group's
                // fate was known. A refusal that kept them would burn numbers off
                // a GLOBAL, monotonic counter forever — the defect Phase 20.3L
                // measured live.
                //
                // Releasing is safe because allocation is CAS: generate() claims
                // by CREATING `feeCounters/_sys_STU_claim_{N}` with
                // create-if-not-exists, and that claim doc — not the pointer —
                // is the authority (Id_generator.php:516). A freed number can
                // therefore be re-claimed by exactly one future allocator, and a
                // stale pointer only costs extra linear probing, never a
                // duplicate. No student document exists for a refused group, so
                // nothing is holding the number we return.
                //
                // Released HIGHEST FIRST: releaseClaim() rewinds the pointer only
                // when it still equals the value being freed, so returning the
                // top of the run first gives the pointer the best chance to walk
                // back instead of stranding the whole block.
                $__refuse = function (string $why, bool $releaseIds = true) use ($__rows, &$skipped, &$error) {
                    foreach ($__rows as $__r) {
                        $skipped[] = "Row {$__r['rowNo']}: {$__r['studentName']} — {$why}";
                        $error++;
                    }
                    if (!$releaseIds) return;

                    $__vals = [];
                    foreach ($__rows as $__r) {
                        $__n = (int) preg_replace('/\D/', '', (string) $__r['studentId']);
                        if ($__n > 0) $__vals[] = $__n;
                    }
                    rsort($__vals);
                    foreach ($__vals as $__n) {
                        $__ok = false;
                        try {
                            $__ok = (bool) $this->id_generator->releaseClaim('STU', $__n);
                        } catch (\Throwable $__e) {
                            log_message('error', 'ACC_IMPORT_ID_RELEASE_FAILED schoolId=' . $this->school_id
                                . " value={$__n} err=" . $__e->getMessage());
                            continue;
                        }
                        if (!$__ok) {
                            // Never silent: a leaked number must be greppable, or
                            // it is unfindable in production. The row result is
                            // unaffected — the student was not imported either way.
                            log_message('error', 'ACC_IMPORT_ID_RELEASE_FAILED schoolId=' . $this->school_id
                                . " value={$__n} — Student ID leaked; run Id_generator::reconcile('STU')");
                        }
                    }
                };

                // 499 students + 1 marker = 500 writes. A larger group is
                // REFUSED, never chunked: a first chunk would move the section's
                // updateTime, so the second would no longer be the same atomic
                // capacity decision — reopening the race.
                if ($__n > $__max) {
                    $__refuse("{$__lbl} received {$__n} students in one import; the safe maximum "
                        . "per section is {$__max}. No student was imported for this section — "
                        . 'split the file by section and retry.');
                    continue;
                }

                // Same authority, same ordering: capture the destination section's
                // version BEFORE the occupancy decision it protects, then carry it
                // into the group commit. Without it the guard fails closed and the
                // whole group is refused — which is what this path did, reporting
                // "the placement outcome could not be confirmed" for every row.
                $__ver  = $this->_sectionDecisionVersion($__cls, $__sec, $this->session_year);
                $__over = $this->_sectionCapacityExceeded($__cls, $__sec, $this->session_year, $__n, '', /* fresh */ true);
                if ($__over !== null) {
                    $__refuse($this->_sectionCapacityMessage($__over, $__cls, $__sec)
                        . ' No student was imported for this section.');
                    continue;
                }

                $__group = [];
                foreach ($__rows as $__r) {
                    $__group[] = ['studentId' => $__r['studentId'], 'data' => $__r['studentData']];
                }

                $__res = $this->seatGuard->placeStudentGroup(
                    $this->entity_sync, $__group, $__cls, $__sec, $this->session_year, $__ver
                );

                if (($__res['status'] ?? '') !== Section_seat_guard::PLACED) {
                    // Whole batch rolled back — NOT ONE student exists, so no
                    // postWrite() and therefore no SRNO / Admission Number /
                    // APAAR claimed for a placement that did not happen.
                    //
                    // ── PHASE 20.3M — WHY CONFLICT AND FAILURE DIFFER HERE ─────
                    // CONFLICT is a GUARANTEED full rollback: Firestore rejected
                    // the batch on the precondition, so no student document was
                    // written and the claimed Student IDs are provably free to
                    // return.
                    //
                    // FAILURE means the outcome is UNKNOWN — the writes may have
                    // landed. Returning those numbers could later hand one to a
                    // second student while an orphan document already holds it:
                    // a DUPLICATE IDENTITY, which is strictly worse than a gap in
                    // a monotonic counter. So FAILURE deliberately KEEPS its
                    // claims, and says so loudly enough to be reconciled later.
                    $__conflict = ($__res['status'] ?? '') === Section_seat_guard::CONFLICT;
                    $__refuse("{$__lbl}: " . ($__conflict
                            ? 'the section changed while the import was in progress'
                            : 'the placement outcome could not be confirmed')
                        . '. No student was imported for this section — please retry.',
                        $__conflict);
                    log_message('error',
                        "ACC_IMPORT_GROUP_REFUSED schoolId={$this->school_id} dest={$__lbl} "
                        . "count={$__n} status=" . ($__res['status'] ?? '?'));
                    if (!$__conflict) {
                        log_message('error',
                            "ACC_IMPORT_ID_RETAINED_UNKNOWN schoolId={$this->school_id} dest={$__lbl} "
                            . "count={$__n} — outcome unknown, Student IDs deliberately NOT released; "
                            . "verify whether the students exist, then run Id_generator::reconcile('STU')");
                    }
                    continue;
                }

                // PASS 3 — committed; replay the existing post-write routine.
                foreach ($__rows as $__r) {
                    $postWrite($__r['ctx']);
                }
            }
        }

        return [
            'success'     => $success,
            'error'       => $error,
            'skipped'     => $skipped,
            'feesPending' => $feesPending,
            'duplicates'  => $duplicates,
            'updated'     => $updated,
            'credentials' => $credentials,
        ];
    }

    /**
     * Look up an existing student in this school by 10-digit phone via the
     * indexPhones index. Returns the studentId or null. (Phase 3 dedup.)
     */
    /**
     * SIB-B1 (Phase 13) — name corroboration for a phone-based duplicate match.
     *
     * `_existingStudentIdByPhone()` answers "does this phone belong to a student
     * in this school?" — which for a family is true for EVERY sibling. It can
     * therefore never, on its own, prove that an import row is the same student.
     * This adds the second signal: does that student also carry the same name?
     *
     * Cost: at most ONE document read, and only on rows whose phone already
     * matches an existing student. An ordinary first-time admission row never
     * reaches here. Never throws — an unreadable student is reported as "not a
     * match", which fails SAFE (the row is imported as a new student rather than
     * silently overwriting someone).
     */
    /**
     * P14-1 (Phase 14.1) — register a student against a phone WITHOUT evicting
     * the siblings who share it.
     *
     * ── THE DEFECT THIS CLOSES ──────────────────────────────────────────────
     * `indexPhones/{schoolId}_{phone}` held ONE `userId`, written with
     * `set(..., merge = false)`. A family shares a phone, so importing Rahul
     * then Priya left the document pointing only at Priya. Re-importing the
     * same file then resolved Rahul's row to PRIYA's id, the Phase 13 name
     * corroboration correctly said "different person", and a SECOND Rahul was
     * created. The Phase 13 fix corrected the dedup RULE; this corrects the
     * INDEX SHAPE, which is the actual root cause and exactly what
     * ZENX_ADMISSION_DOMAIN_SPEC.md already prescribed ("append, not
     * overwrite").
     *
     * ── SHAPE, AND WHY IT IS BACKWARD COMPATIBLE ────────────────────────────
     *   { phone, schoolId, type, userId: <first/primary>, userIds: [ … ] }
     *
     * `userId` is RETAINED as the first-registered student so every existing
     * reader (Staff.php's pre-owner checks, legacy lookups) keeps working
     * unchanged. `userIds[]` is the new authoritative set. A legacy document
     * with only `userId` reads as a single-element set — **no backfill and no
     * migration are required**.
     *
     * This is NOT a sibling graph: it records "these students share a contact
     * number", nothing more. No relationship, no guardian entity, no ordering.
     *
     * Cost: 1 read + 1 write per phone registration, replacing the previous
     * blind 1 write. The read is required to append without evicting; there is
     * no atomic array-union primitive exposed on this path. Idempotent —
     * re-registering the same student is a no-op set membership add.
     */
    private function _registerPhoneIndex(string $phone, string $studentId, string $type = 'student'): void
    {
        if ($phone === '' || $studentId === '') return;

        // ── PHONE-IDX-1 (2026-09-19) · NORMALISE AT THE CHOKEPOINT ───────────
        //
        // Four paths write this index — direct admission, CRM enrolment, bulk
        // import and the import update — and only one of them had validated the
        // number. The CRM pathway in particular accepts `^\+?\d{10,15}$` at the
        // enquiry stage, which is RIGHT for a lead (a landline or an overseas number
        // is a perfectly good enquiry) and wrong the moment that lead becomes a
        // student, because enroll_student() passed it straight to this index.
        //
        // Fixing it here rather than at each caller means the index cannot hold a
        // key no lookup will ever produce: _existingStudentIdsByPhone() is called
        // with a normalised 10-digit number, so a row filed under "+91 98765 43210"
        // or "0412-2334455" is invisible — and invisible is exactly what a duplicate
        // check and sibling detection must never be.
        //
        // Not indexing is the FAIL-CLOSED direction: a missing index row degrades
        // duplicate detection to "none found", which is the behaviour before this
        // work existed. A wrong row would actively mislead.
        $phone10 = $this->_normalisePhone10($phone);
        if ($phone10 === '') {
            log_message('error', "PHONE-IDX-1 refusing to index an unusable number for {$studentId} "
                . '(shape: ' . preg_replace('/\d/', '9', $phone) . '). '
                . 'Sibling and duplicate detection will not see this student.');
            return;
        }
        $phone = $phone10;
        try {
            $docId    = $this->fs->docId($phone);
            $existing = $this->fs->get('indexPhones', $docId);

            $ids = [];
            if (is_array($existing)) {
                if (is_array($existing['userIds'] ?? null)) {
                    $ids = array_values(array_filter(array_map('strval', $existing['userIds'])));
                }
                // Legacy single-value document → seed the set from it.
                $legacy = trim((string) ($existing['userId'] ?? ''));
                if ($legacy !== '' && !in_array($legacy, $ids, true)) $ids[] = $legacy;
            }
            if (!in_array($studentId, $ids, true)) $ids[] = $studentId;

            $this->fs->set('indexPhones', $docId, [
                'schoolId' => $this->school_id,
                'phone'    => $phone,
                // Primary stays STABLE (first registrant) so legacy readers do
                // not silently change meaning when a sibling is added.
                'userId'   => $ids[0],
                'userIds'  => array_values($ids),
                'type'     => $type,
            ]);
        } catch (\Throwable $e) {
            log_message('error', "indexPhones registration failed for {$studentId}/{$phone}: " . $e->getMessage());
        }
    }

    /**
     * P14-1 — remove ONE student from a shared phone entry.
     *
     * Deleting the whole `indexPhones` document (the pre-Phase-14.1 behaviour)
     * would de-register every sibling who shares the number, silently breaking
     * duplicate detection for the rest of the family on the next import. The
     * document is deleted only when the last student leaves it.
     *
     * Best-effort and idempotent: a missing document, an already-absent id, or
     * a read failure all leave the system in a consistent state and never block
     * the deletion itself.
     */
    private function _deregisterPhoneIndex(string $phone, string $studentId): void
    {
        if ($phone === '' || $studentId === '') return;
        try {
            $docId = $this->fs->docId($phone);
            $ids   = $this->_existingStudentIdsByPhone($phone);
            $ids   = array_values(array_filter($ids, static fn($v) => (string) $v !== $studentId));

            if (empty($ids)) {
                $this->fs->remove('indexPhones', $docId);
                return;
            }
            $this->fs->set('indexPhones', $docId, [
                'schoolId' => $this->school_id,
                'phone'    => $phone,
                'userId'   => $ids[0],
                'userIds'  => $ids,
                'type'     => 'student',
            ]);
        } catch (\Throwable $e) {
            log_message('error', "indexPhones de-registration failed for {$studentId}/{$phone}: " . $e->getMessage());
        }
    }

    /**
     * P14-1 — every student currently registered against a phone.
     * Legacy single-value documents yield a one-element list.
     */
    /**
     * ADM-INPUT-1 — parse a date STRICTLY, or refuse it.
     *
     * Replaces `date('d-m-Y', strtotime($raw))`, which had two silent failure modes:
     *   strtotime('garbage')    === false  ->  date(..., false)  ->  01-01-1970
     *   strtotime('31-02-2020')            ->  02-03-2020 (rolled, not rejected)
     * Both wrote a wrong date with no error, and the first produced a 1970 DOB that
     * then flowed into age logic and the ID card.
     *
     * createFromFormat with a leading `!` zeroes the time part, and the warning count
     * is what distinguishes a real date from a rolled one — checkdate() alone would
     * not, because the roll has already happened by then.
     *
     * @return array{ok:bool,value:string,error:string,dt:?DateTimeImmutable}
     */
    /**
     * ADM-COMPLETE-1 — which operationally-required fields this student is missing.
     *
     * THE single definition. save_admission() reports it at the counter and
     * search_student() counts it on the roster; if each had its own list they would
     * drift, and the roster would quietly stop counting the thing the desk warned
     * about.
     *
     * Computed from the document rather than read from `pendingProfileFields`, so it
     * is correct for the students admitted BEFORE that field existed. No backfill,
     * and no extra Firestore read: callers already hold the document.
     *
     * These are not bureaucratic niceties. Guardian contact is the number dialled
     * when a child is hurt; DOB and father's name are what UDISE+ and a board
     * registration are rejected for.
     */
    /**
     * ADM-AGE-1 — evaluate the school's class-vs-age band for this admission.
     *
     * AGE IS MEASURED AT THE CUT-OFF, NOT TODAY. That is the whole point of the
     * rule as a registrar applies it: "6 years old as on 31 March". A child turning
     * 6 on 2 April is five at the cut-off and waits a year — measuring from today
     * would admit them and quietly disagree with every other school in the state.
     *
     * The cut-off belongs to the ACADEMIC year the admission is for, which is the
     * session, not the calendar year. session_year is "2026-27", so the cut-off
     * falls in 2026.
     *
     * Returns null when there is nothing to say: policy off, no band for this class,
     * or the child is inside it. Never throws — an unreadable policy must not block
     * an admission, so it degrades to "no opinion".
     *
     * @return array{severity:string,message:string}|null
     */
    /**
     * ADM-AGE-1 — apply the tenant's class-vs-age rule to this admission.
     *
     * Reads the policy; the DECIDING is done by Admission_age_policy, which has no
     * CodeIgniter, Firestore or session dependency and is therefore unit-tested
     * against real dates (see AdmissionAgePolicyTest). The arithmetic used to live
     * here, where it could only be source-asserted — and a cut-off comparison that
     * is wrong by one day decides who gets a seat, so "the file mentions diff()" is
     * not the assurance that logic needs.
     *
     * Never throws: an unreadable policy must not block an admission, so it
     * degrades to "no opinion".
     */
    private function _ageBandCheck(string $classOrd, \DateTimeImmutable $dob): ?array
    {
        try {
            require_once APPPATH . 'libraries/Admission_age_policy.php';

            // Memoized per request. The policy cannot change mid-request, and a bulk
            // path calling this per row would otherwise re-read the school document
            // once per student.
            static $__policyCache = null;
            if ($__policyCache === null) {
                $school = $this->fs->get('schools', $this->fs->schoolId());
                $__policyCache = is_array($school['admissionAgePolicy'] ?? null)
                    ? $school['admissionAgePolicy'] : [];
            }

            return Admission_age_policy::evaluate(
                $__policyCache, $classOrd, $dob, (string) $this->session_year
            );
        } catch (\Throwable $e) {
            log_message('error', 'ADM-AGE-1 policy read failed: ' . $e->getMessage());
            return null;
        }
    }

    private function _profileGaps(array $d): array
    {
        $get = function (array $keys) use ($d) {
            foreach ($keys as $k) {
                if (trim((string) ($d[$k] ?? '')) !== '') return true;
            }
            return false;
        };

        // Address is stored either flat or nested under Address{} depending on the
        // path that wrote it, so both shapes count as present.
        $addr = is_array($d['Address'] ?? null) ? $d['Address'] : [];
        $hasAddress = trim((string) ($d['street'] ?? $d['Street'] ?? $addr['Street'] ?? '')) !== '';

        $gaps = [];
        if (!$get(['dob', 'DOB']))                                   $gaps[] = 'Date of birth';
        // PHONE-IDX-1 — present-but-unusable counts as missing. A number that cannot
        // be normalised is not indexed, so the family cannot be found by it and the
        // Parent app cannot resolve the child: functionally there is no phone. This
        // is what surfaces legacy bad numbers on the roster instead of leaving them
        // to be discovered by a failed login.
        $__ph = '';
        foreach (['phone', 'Phone Number', 'phoneNumber'] as $__k) {
            if (trim((string) ($d[$__k] ?? '')) !== '') { $__ph = (string) $d[$__k]; break; }
        }
        if ($__ph === '' || $this->_normalisePhone10($__ph) === '') $gaps[] = 'Phone number';
        if (!$get(['fatherName', 'Father Name', 'father_name']))     $gaps[] = "Father's name";
        if (!$get(['motherName', 'Mother Name', 'mother_name']))     $gaps[] = "Mother's name";
        if (!$get(['gender', 'Gender']))                             $gaps[] = 'Gender';
        if (!$get(['category', 'Category']))                         $gaps[] = 'Category';
        if (!$hasAddress)                                            $gaps[] = 'Address';
        if (!$get(['guardContact', 'Guard Contact', 'guardian_contact'])) $gaps[] = 'Guardian contact';

        return $gaps;
    }

    private function _strictDate(string $raw, string $label): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => true, 'value' => '', 'error' => '', 'dt' => null];
        }

        // `Y-m-d` first: that is what <input type="date"> posts.
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y'] as $fmt) {
            $dt  = DateTimeImmutable::createFromFormat('!' . $fmt, $raw);
            $err = DateTimeImmutable::getLastErrors();
            // PHP 8.2 returns false when clean; 8.1 returns zeroed counts.
            if ($err === false) $err = ['warning_count' => 0, 'error_count' => 0];
            if ($dt instanceof DateTimeImmutable
                && (int) $err['warning_count'] === 0
                && (int) $err['error_count'] === 0) {
                return ['ok' => true, 'value' => $dt->format('d-m-Y'), 'error' => '', 'dt' => $dt];
            }
        }

        return [
            'ok'    => false,
            'value' => '',
            'error' => $label . ' is not a valid calendar date. Use DD-MM-YYYY.',
            'dt'    => null,
        ];
    }

    /**
     * ADM-INPUT-1 — reduce a typed phone to a 10-digit Indian mobile, or ''.
     *
     * Accepts what a human types (+91, 0 prefix, spaces, dashes, brackets) and returns
     * the canonical form the rest of the system indexes on. Returns '' — never a
     * partially-cleaned string — so a caller cannot accidentally store junk.
     */
    private function _normalisePhone10(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') return '';

        // Trailing 10 covers +91XXXXXXXXXX, 0XXXXXXXXXX and 91XXXXXXXXXX alike.
        if (strlen($digits) > 10) $digits = substr($digits, -10);

        // Same rule the admission form enforces: a real mobile starts 6-9.
        return preg_match('/^[6-9]\d{9}$/', $digits) === 1 ? $digits : '';
    }

    /**
     * ADM-DUP-1 — is this person already admitted?
     *
     * Phone AND name, exactly as _runStudentImport()/the import preview decide it
     * (SIB-B1, P14-1). Phone alone is not proof of a duplicate: siblings share one,
     * and P14-1 exists precisely because indexPhones holds SEVERAL students per
     * number. Name alone is not proof either — two families pick the same name.
     *
     * Returns the matched student for the operator to look at, or null. Never throws:
     * a lookup failure must not block an admission, so it degrades to "no duplicate
     * known" — the same fail-open direction the import preview takes, and the reason
     * this warns rather than forbids.
     */
    private function _duplicateStudentSuspect(string $nameNorm, string $phone10): ?array
    {
        if ($nameNorm === '' || strlen($phone10) !== 10) return null;

        try {
            foreach ($this->_existingStudentIdsByPhone($phone10) as $candidateId) {
                if (!$this->_studentNameMatches($candidateId, $nameNorm)) continue;

                $doc = $this->fs->get('students', $this->fs->docId($candidateId));
                $doc = is_array($doc) ? $doc : [];

                // Classify the prior record by status (lifecycle-critic decision B):
                //   • active  → a true DUPLICATE (same child being admitted twice).
                //   • alumni  → a GRADUATE being re-admitted: unusual (they completed
                //               schooling), so SURFACE it as a confirm rather than
                //               silently minting a new record — closes the
                //               "graduation does not gate re-enrolment" gap.
                //   • withdrawn/TC/inactive → normal readmission; NOT flagged, because
                //     warning there would train the operator to click through the
                //     warning that matters.
                $status = strtolower(trim((string) ($doc['status'] ?? $doc['Status'] ?? 'active')));
                // withdrawn/TC/inactive are NOT duplicates (normal readmission) — only
                // an active record (true duplicate) or an alumni record (a graduate
                // being re-admitted) is surfaced. Keeping the explicit `!== 'active'`
                // skip so a withdrawn student is never flagged.
                if ($status !== '' && $status !== 'active' && $status !== 'alumni') continue;
                $kind = ($status === 'alumni') ? 'graduated' : 'active_duplicate';

                return [
                    'studentId' => $candidateId,
                    'kind'      => $kind,
                    'name'      => (string) ($doc['name']    ?? $doc['Name']    ?? ''),
                    'class'     => (string) ($doc['className'] ?? $doc['Class']  ?? ''),
                    'section'   => (string) ($doc['section'] ?? $doc['Section'] ?? ''),
                    'srNo'      => (string) ($doc['srNo']    ?? $doc['SR No']   ?? ''),
                    'session'   => (string) ($doc['session'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            log_message('error', 'ADM-DUP-1 duplicate lookup failed: ' . $e->getMessage());
        }

        return null;
    }

    private function _existingStudentIdsByPhone(string $phone): array
    {
        if ($phone === '') return [];
        try {
            $rec = $this->fs->get('indexPhones', $this->fs->docId($phone));
            if (!is_array($rec)) return [];
            if ((string) ($rec['type'] ?? 'student') !== 'student') return [];

            $ids = [];
            if (is_array($rec['userIds'] ?? null)) {
                $ids = array_values(array_filter(array_map('strval', $rec['userIds'])));
            }
            $legacy = trim((string) ($rec['userId'] ?? ''));
            if ($legacy !== '' && !in_array($legacy, $ids, true)) $ids[] = $legacy;
            return $ids;
        } catch (\Throwable $e) {
            log_message('error', 'dedup phone lookup failed: ' . $e->getMessage());
            return [];
        }
    }

    private function _studentNameMatches(string $studentId, string $nameNorm): bool
    {
        if ($studentId === '' || $nameNorm === '') return false;
        try {
            $doc = $this->fs->get('students', $this->fs->docId($studentId));
            if (!is_array($doc)) return false;
            $existing = (string) ($doc['name'] ?? $doc['Name'] ?? '');
            if ($existing === '') return false;
            return strtolower(preg_replace('/\s+/', ' ', trim($existing))) === $nameNorm;
        } catch (\Throwable $e) {
            log_message('error', "SIB-B1 name-corroboration read failed for {$studentId}: " . $e->getMessage());
            return false;
        }
    }

    private function _existingStudentIdByPhone(string $phone): ?string
    {
        if ($phone === '') return null;
        try {
            $rec = $this->fs->get('indexPhones', $this->fs->docId($phone));
            if (is_array($rec)) {
                $uid  = (string) ($rec['userId'] ?? '');
                $type = (string) ($rec['type'] ?? 'student');
                if ($uid !== '' && $type === 'student') return $uid;
            }
        } catch (\Throwable $e) {
            log_message('error', 'dedup phone lookup failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Merge a row's contact/demographic fields onto an existing student
     * (update policy). Class/Section/Status/Password/fees are PRESERVED —
     * this is a profile refresh, not a promotion or re-admission, so it never
     * touches fee state. saveStudent() is a merge-write, so untouched fields
     * (Doc, Profile Pic, subjects, monthFee) survive. Returns true on success.
     */
    private function _updateExistingStudent(string $existingId, array $rowData): bool
    {
        try {
            $existing = $this->fs->get('students', $this->fs->docId($existingId));
            if (!is_array($existing)) $existing = [];

            $keepClass   = (string) ($existing['Class']   ?? $existing['className'] ?? '');
            $keepSection = (string) ($existing['Section'] ?? $existing['section']   ?? '');
            $keepStatus  = (string) ($existing['Status']  ?? $existing['status']    ?? 'Active');
            // No $keepPass: saveStudent() strips Password on every write (CRED-PLAIN-1),

            // Prefer incoming non-empty value, else keep what's on the doc.
            $val = function (string $k) use ($rowData, $existing) {
                $v = trim((string) ($rowData[$k] ?? ''));
                return $v !== '' ? $v : (string) ($existing[$k] ?? '');
            };
            $ea = is_array($existing['Address'] ?? null) ? $existing['Address'] : [];
            $addr = function (string $k) use ($rowData, $ea) {
                $v = trim((string) ($rowData[$k] ?? ''));
                return $v !== '' ? $v : (string) ($ea[$k] ?? '');
            };

            $dob = trim((string) ($rowData['DOB'] ?? ''));
            $formattedDOB = $dob !== '' ? date('d-m-Y', strtotime($dob)) : (string) ($existing['DOB'] ?? '');
            $adm = trim((string) ($rowData['Admission Date'] ?? ''));
            $formattedAdm = $adm !== '' ? date('d-m-Y', strtotime($adm)) : (string) ($existing['Admission Date'] ?? '');

            $merged = [
                'Name' => $val('Name'), 'User Id' => $existingId, 'DOB' => $formattedDOB,
                'Admission Date' => $formattedAdm, 'Class' => $keepClass, 'Section' => $keepSection,
                // so carrying it through this merge preserved nothing and only kept a
                // secret alive in memory and in logs.
                'Status' => $keepStatus,
                'Gender' => $val('Gender'), 'Blood Group' => $val('Blood Group'),
                'Category' => $val('Category'), 'Religion' => $val('Religion'), 'Nationality' => $val('Nationality'),
                'Father Name' => $val('Father Name'), 'Father Occupation' => $val('Father Occupation'),
                'Mother Name' => $val('Mother Name'), 'Mother Occupation' => $val('Mother Occupation'),
                'Guard Contact' => $val('Guard Contact'), 'Guard Relation' => $val('Guard Relation'),
                'Phone Number' => $val('Phone Number'), 'Email' => $val('Email'),
                'Address' => [
                    'Street' => $addr('Street'), 'City' => $addr('City'),
                    'State' => $addr('State'), 'PostalCode' => $addr('PostalCode'),
                ],
                'Pre School' => $val('Pre School'), 'Pre Class' => $val('Pre Class'), 'Pre Marks' => $val('Pre Marks'),
            ];

            if (!$this->fs->saveStudent($existingId, $merged)) return false;

            try {
                $this->entity_sync->syncStudent($existingId, $merged);
                $this->entity_sync->syncParent($existingId, $merged);
            } catch (\Throwable $e) {
                log_message('warning', "import update sync failed for {$existingId}: " . $e->getMessage());
            }

            // Refresh phone index if a phone is present.
            $ph = preg_replace('/\D+/', '', $merged['Phone Number']);
            if (strlen($ph) > 10) $ph = substr($ph, -10);
            if ($ph !== '' && strlen($ph) === 10) {
                // P14-1: append — an import "update" of one sibling must not
                // evict the other sibling from the shared phone entry.
                $this->_registerPhoneIndex($ph, $existingId, 'student');
            }

            $this->_log_history($this->parent_db_key, $existingId, 'UPDATE',
                'Profile updated via bulk import', []);

            return true;
        } catch (\Throwable $e) {
            log_message('error', "import update failed for {$existingId}: " . $e->getMessage());
            return false;
        }
    }

    /** Build the human-readable one-line summary shown after an import. */
    private function _formatImportSummary(array $res): string
    {
        $success     = (int) ($res['success'] ?? 0);
        $error       = (int) ($res['error'] ?? 0);
        $updated     = (int) ($res['updated'] ?? 0);
        $skipped     = is_array($res['skipped'] ?? null) ? $res['skipped'] : [];
        $feesPending = is_array($res['feesPending'] ?? null) ? $res['feesPending'] : [];
        $duplicates  = is_array($res['duplicates'] ?? null) ? $res['duplicates'] : [];

        $msg = "Imported Successfully: {$success} | Failed: {$error}";
        if ($updated > 0) {
            $msg .= " | Updated existing: {$updated}";
        }
        if (!empty($duplicates)) {
            $msg .= " | Duplicates skipped: " . count($duplicates) . " — " . implode('; ', $duplicates);
        }
        // P1 #10 (2026-08-12): these rows are now BLOCKED, not imported-pending.
        // The old wording ("Fees pending … then demands auto-assign") told the
        // operator the students were in and would be billed later — the
        // opposite of what now happens. They were NOT imported and must be
        // re-imported after the chart exists.
        if (!empty($feesPending)) {
            $msg .= " | NOT imported — no fee chart: " . count($feesPending)
                 . " (set up Fees → Chart for these class/sections, then re-import these rows): "
                 . implode('; ', $feesPending);
        }
        if (!empty($skipped)) {
            $msg .= " | Skipped: " . count($skipped) . " — " . implode('; ', $skipped);
        }
        return $msg;
    }

    /* ══════════════════════════════════════════════════════════════════════
       FLEXIBLE IMPORT (Phase 2, 2026-06-12) — map → validate → commit.
       Accepts any column layout: the uploaded file's headers are fuzzy-mapped
       to canonical fields (Import_schema), values are normalized, and every
       row is dry-run validated in a preview before a single write happens.
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Step 1 — parse the uploaded file and render the mapping + preview UI.
     * No writes. The parsed rows are embedded in the page; the browser drives
     * mapping/validation against import_validate, then commits.
     */
    public function import_preview()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');

        // Single-page flow: the upload posts via AJAX and expects JSON back;
        // a plain (no-JS) POST still renders the standalone map view as before.
        $isAjax = $this->input->is_ajax_request();
        $fail = function ($msg) use ($isAjax) {
            if ($isAjax) {
                $this->output->set_content_type('application/json')
                     ->set_output(json_encode(['status' => 'error', 'message' => $msg]));
            } else {
                $this->session->set_flashdata('import_result', $msg);
                redirect('sis/master_student');
            }
        };

        if (!isset($_FILES['excelFile']) || $_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
            $fail('No file received, or the upload failed. Please choose a CSV or XLSX file.');
            return;
        }

        $file      = $_FILES['excelFile'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx', 'xls'], true)) {
            $fail('Unsupported file type. Please upload a .csv or .xlsx file.');
            return;
        }

        try {
            $reader      = ($extension === 'csv') ? IOFactory::createReader('Csv') : IOFactory::createReader('Xlsx');
            $spreadsheet = $reader->load($file['tmp_name']);
            // Formatted values, numeric column indices.
            $sheetData   = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            log_message('error', 'IMPORT PREVIEW read error: ' . $e->getMessage());
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

        // Normalise ragged rows to header width so column alignment holds.
        $width = count($headers);
        foreach ($dataRows as &$r) {
            $r = array_map(function ($v) { return (string) $v; }, array_values($r));
            if (count($r) < $width) $r = array_pad($r, $width, '');
            elseif (count($r) > $width) $r = array_slice($r, 0, $width);
        }
        unset($r);

        // v1 cap — very large files belong on a background job (Phase 3).
        $cap    = 1500;
        $capped = count($dataRows) > $cap;
        if ($capped) $dataRows = array_slice($dataRows, 0, $cap);

        $this->load->library('import_schema');

        // Apply this school's learned header→field mappings from past imports.
        $learned = $this->_loadImportMapping();

        $data = [
            'headers'        => $headers,
            'rows'           => $dataRows,
            'autoMap'        => $this->import_schema->autoMap($headers, $learned),
            'schema'         => $this->import_schema->fieldsForUi(),
            'capped'         => $capped,
            'capLimit'       => $cap,
            'fileName'       => $file['name'],
            'learnedApplied' => $this->import_schema->learnedHitCount($headers, $learned),
        ];

        if ($isAjax) {
            $data['status'] = 'success';
            $this->output->set_content_type('application/json')->set_output(json_encode($data));
            return;
        }

        $this->load->view('include/header');
        $this->load->view('import_students_map', $data);
        $this->load->view('include/footer');
    }

    /**
     * Step 2 — dry-run validation (AJAX). Receives canonical rows as a JSON
     * string in the `payload` POST field; returns per-row normalized values
     * and OK/warning/error status. No writes.
     */
    /**
     * Phase 16.2 — canonical class/section key derivation for bulk import.
     *
     * Extracted so `import_validate()` (preview) and `_runStudentImport()`
     * (commit) derive placement keys through ONE definition. If the preview
     * computed keys even slightly differently — a different ordinal suffix, a
     * different normaliser — it would project capacity for a section the
     * commit never writes to, and the operator would be shown a seat count
     * that does not correspond to reality.
     *
     * Pure: no Firestore access, no side effects.
     */
    private function _importPlacementKeys(int $classNumber, string $sectionRaw): array
    {
        $suffix = 'th';
        if (!in_array(($classNumber % 100), [11, 12, 13], true)) {
            switch ($classNumber % 10) {
                case 1: $suffix = 'st'; break;
                case 2: $suffix = 'nd'; break;
                case 3: $suffix = 'rd'; break;
            }
        }
        return [
            'className' => Firestore_service::classKey($classNumber . $suffix),
            'section'   => Firestore_service::sectionKey($sectionRaw),
        ];
    }

    public function import_validate()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');
        $this->output->set_content_type('application/json');

        $payload = json_decode((string) $this->input->post('payload'), true);
        $rows    = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        // Phase 9.2 — the operator's explicit, all-or-nothing mode choice.
        // Anything other than the two recognized values is treated as
        // unspecified ('') — never silently guessed from row contents (per
        // explicit instruction: do not infer historical vs new from
        // whether SR Number happens to be blank).
        $importMode = (string) ($payload['importMode'] ?? '');
        if (!in_array($importMode, ['historical', 'new'], true)) $importMode = '';

        $this->load->library('import_schema');

        $out = [];
        $ok = 0; $warn = 0; $err = 0;
        // Phase 9.2 — in-file duplicate SR Number / APAAR detection. Pure
        // (no Firestore read) — this is the SAME class of check
        // _runStudentImport()'s own $seenPhones/$seenNameDob already does
        // for phone/name+DOB, just performed here at the dry-run stage so
        // the operator sees it BEFORE committing, not only after.
        // Existing-in-database conflicts (a row's SRNO/APAAR already owned
        // by a DIFFERENT, previously-imported-or-admitted student) require
        // a Firestore read and are therefore checked separately, in
        // import_dedupe_check() (opt-in) and authoritatively in
        // import_commit() itself — never inferred here.
        $seenSrNo  = [];
        $seenApaar = [];
        // Phase 16.2 — in-file Roll Number duplicates, scoped class+section.
        $seenRollsInFile = [];
        foreach ($rows as $r) {
            $v = $this->import_schema->validateRow(is_array($r) ? $r : [], $importMode);

            $srNorm = $v['data']['SR Number'] ?? '';
            if ($srNorm !== '') {
                $srKey = \Sr_no_service::normalize($srNorm)['norm'] ?? $srNorm;
                if (isset($seenSrNo[$srKey])) {
                    $v['errors'][] = "SR Number \"{$srNorm}\" is duplicated within this file (also on an earlier row)";
                    $v['status'] = 'error';
                } else {
                    $seenSrNo[$srKey] = true;
                }
            }

            $apaarNorm = $v['data']['APAAR ID'] ?? '';
            if ($apaarNorm !== '') {
                if (isset($seenApaar[$apaarNorm])) {
                    $v['errors'][] = 'APAAR ID is duplicated within this file (also on an earlier row)';
                    $v['status'] = 'error';
                } else {
                    $seenApaar[$apaarNorm] = true;
                }
            }

            // ── Phase 16.2 — in-file ROLL NUMBER duplicates ──────────────
            // The commit already rejects these ($seenRolls in
            // _runStudentImport), but the operator only learned about them
            // AFTER importing. This is the same PURE, no-Firestore-read class
            // of check as the SR Number / APAAR duplicates directly above, so
            // by this method's own stated principle it belongs here too.
            // Scoped to class+section: the same roll in 5-A and 5-B is legal.
            $rollRaw = trim((string) ($v['data']['Roll No'] ?? ''));
            $clsRaw  = trim((string) ($v['data']['Class'] ?? ''));
            $secRaw  = trim((string) ($v['data']['Section'] ?? ''));
            if ($rollRaw !== '' && $clsRaw !== '' && $secRaw !== '') {
                preg_match('/\d+/', $clsRaw, $__m);
                if (isset($__m[0])) {
                    $k    = $this->_importPlacementKeys((int) $__m[0], $secRaw);
                    $rKey = mb_strtolower("{$k['className']}|{$k['section']}|{$rollRaw}");
                    if (isset($seenRollsInFile[$rKey])) {
                        $v['errors'][] = "Roll Number \"{$rollRaw}\" is already used by "
                                       . "{$seenRollsInFile[$rKey]} earlier in this file for the same class and section";
                        $v['status'] = 'error';
                    } else {
                        $seenRollsInFile[$rKey] = (string) ($v['data']['Name'] ?? 'an earlier row');
                    }
                }
            }

            if ($v['status'] === 'error') $err++;
            elseif ($v['status'] === 'warning') $warn++;
            else $ok++;
            $out[] = $v;
        }

        // ── Phase 16.2 (CAP-1) — SECTION CAPACITY PROJECTION ────────────
        //
        // THE GAP THIS CLOSES: commit has enforced capacity since Phase 15,
        // but the preview said nothing about it. An operator could upload 60
        // students for a 40-seat section, wait out the import, and only then
        // discover 20 rows were rejected. The information needed to warn them
        // was already available for free.
        //
        // SAME RULE AS COMMIT: this uses the canonical
        // _sectionCapacityExceeded() with the identical running-seat
        // projection ($seatsUsed + 1) that _runStudentImport() applies, and
        // the placement keys come from the shared _importPlacementKeys().
        // Preview and commit therefore cannot disagree about the rule.
        //
        // ⚠ PREVIEW IS NOT AUTHORIZATION. Seats can be taken by another
        // operator between preview and commit, so the commit re-runs this
        // check against LIVE data and remains the only authority. Overflow
        // rows are therefore marked WARNING here, not error — the preview
        // reports a forecast, it does not decide.
        //
        // COST: O(1) Firestore reads for the whole file regardless of row
        // count — _sectionCapacityExceeded() reads the per-request memoised
        // student snapshot, and _sectionCapacity() memoises per
        // session|class|section. A 5,000-row file costs the same as a 10-row
        // file. Explicitly NOT a per-row query.
        $capacityTable = [];
        $seatsUsed     = [];
        foreach ($out as $i => $v) {
            if (($v['status'] ?? '') === 'error') continue;   // already rejected
            $clsRaw = trim((string) ($v['data']['Class'] ?? ''));
            $secRaw = trim((string) ($v['data']['Section'] ?? ''));
            if ($clsRaw === '' || $secRaw === '') continue;
            preg_match('/\d+/', $clsRaw, $__m);
            if (!isset($__m[0])) continue;

            $k       = $this->_importPlacementKeys((int) $__m[0], $secRaw);
            $groupId = mb_strtolower("{$k['className']}|{$k['section']}");

            if (!isset($capacityTable[$groupId])) {
                $cap = $this->_sectionCapacity($k['className'], $k['section'], $this->session_year);
                $capacityTable[$groupId] = [
                    'classKey'  => $k['className'],   // canonical, for re-querying
                    'sectionKey'=> $k['section'],
                    'class'     => trim(preg_replace('/^Class\s+/i', '', $k['className'])),
                    'section'   => trim(preg_replace('/^Section\s+/i', '', $k['section'])),
                    'capacity'  => $cap,          // 0 = unlimited, matching commit semantics
                    'current'   => null,          // filled on first real check below
                    'incoming'  => 0,
                    'accept'    => 0,
                    'reject'    => 0,
                ];
                $seatsUsed[$groupId] = 0;
            }
            $capacityTable[$groupId]['incoming']++;

            $over = $this->_sectionCapacityExceeded(
                $k['className'], $k['section'], $this->session_year, $seatsUsed[$groupId] + 1
            );

            if ($over === null) {
                $seatsUsed[$groupId]++;
                $capacityTable[$groupId]['accept']++;
                continue;
            }

            // Over capacity — forecast only, never a hard rejection here.
            $capacityTable[$groupId]['reject']++;
            if ($capacityTable[$groupId]['current'] === null) {
                $capacityTable[$groupId]['current'] = (int) $over['current'];
            }
            $out[$i]['warnings'][] = sprintf(
                'Class %s Section %s is expected to be full — capacity %d, %d already active. '
                . 'This row will most likely be rejected at import.',
                $capacityTable[$groupId]['class'], $capacityTable[$groupId]['section'],
                (int) $over['capacity'], (int) $over['current']
            );
            if (($out[$i]['status'] ?? '') === 'ok') {
                $out[$i]['status'] = 'warning';
                $ok--; $warn++;
            }
        }

        // Fill `current` for groups that never hit the limit, and compute the
        // projected strength an operator actually wants to read.
        foreach ($capacityTable as &$row) {
            if ($row['current'] === null) {
                $row['current'] = max(0, $this->_sectionCurrentStrengthForPreview(
                    $row['classKey'], $row['sectionKey']
                ));
            }
            $row['projected'] = $row['current'] + $row['incoming'];
            $row['unlimited'] = ($row['capacity'] <= 0);
        }
        unset($row);

        echo json_encode([
            'rows'     => $out,
            'summary'  => [
                'ok' => $ok, 'warning' => $warn, 'error' => $err, 'total' => count($rows),
                'capacity_overflow' => array_sum(array_column($capacityTable, 'reject')),
            ],
            'capacity' => array_values($capacityTable),
            // Stated explicitly so the UI can never present the forecast as a
            // guarantee, and so a reader of this payload knows where authority
            // actually lives.
            'capacity_note' => 'Forecast only. Seats are re-checked against live data at import; '
                             . 'another operator may take seats in the meantime.',
        ]);
    }

    /**
     * Phase 16.2 — current ACTIVE strength for a `class|section` group key,
     * used to complete the preview's capacity table.
     *
     * Delegates to the canonical `_sectionActiveCount()`, so the number the
     * operator reads is produced by the same rule the commit enforces. Reuses
     * the shared memoised snapshot — no additional Firestore reads.
     */
    private function _sectionCurrentStrengthForPreview(string $className, string $section): int
    {
        return $this->_sectionActiveCount($className, $section, $this->session_year);
    }

    /**
     * Step 3 — commit (AJAX). Receives canonical rows (`payload` JSON field),
     * re-validates server-side (never trust the client), imports every non-error
     * row via the shared importer, and returns the summary as JSON.
     */
    public function import_commit()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');
        $this->output->set_content_type('application/json');

        $payload = json_decode((string) $this->input->post('payload'), true);
        $rows    = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        // Duplicate policy chosen in the UI; default to the safe 'skip'.
        // 'create' is intentionally NOT accepted here — it would bypass dedupe
        // and let a re-run of the same file create a full duplicate roster. The
        // flexible (preview) path must only skip or update existing students.
        $dupPolicy = in_array(($payload['dupPolicy'] ?? 'skip'), ['skip', 'update'], true)
            ? $payload['dupPolicy'] : 'skip';

        // Phase 9.2 — the import mode is REQUIRED at commit time (unlike
        // import_validate(), which tolerates '' for an early preview
        // before the operator has chosen). Never trust import_validate()'s
        // client-reported status for this — re-validated fully below,
        // exactly like every other field. An invalid/missing mode aborts
        // the whole commit rather than guessing — this is the "all-or-
        // nothing mode per file" requirement's server-side enforcement
        // point, not merely a UI convention.
        $importMode = (string) ($payload['importMode'] ?? '');
        if (!in_array($importMode, ['historical', 'new'], true)) {
            echo json_encode(['status' => 'error', 'message' => 'Select an import mode (Historical or New Admissions) before committing.']);
            return;
        }

        if (empty($rows)) {
            echo json_encode(['status' => 'error', 'message' => 'No rows to import.']);
            return;
        }

        $this->load->library('import_schema');

        // Re-validate authoritatively; only OK/warning rows are written.
        // Phase 9.2 — in-file SR Number/APAAR duplicate detection, SAME
        // logic as import_validate()'s own pre-commit check, re-run here
        // because import_validate()'s results are never trusted at commit
        // time (matches this method's own pre-existing doc comment: "never
        // trust the client's prior validation").
        $toImport  = [];
        $preErrors = 0;
        $seenSrNo  = [];
        $seenApaar = [];
        foreach ($rows as $r) {
            $v = $this->import_schema->validateRow(is_array($r) ? $r : [], $importMode);

            $srNorm = $v['data']['SR Number'] ?? '';
            if ($srNorm !== '') {
                $srKey = \Sr_no_service::normalize($srNorm)['norm'] ?? $srNorm;
                if (isset($seenSrNo[$srKey])) { $v['status'] = 'error'; }
                else { $seenSrNo[$srKey] = true; }
            }
            $apaarNorm = $v['data']['APAAR ID'] ?? '';
            if ($apaarNorm !== '') {
                if (isset($seenApaar[$apaarNorm])) { $v['status'] = 'error'; }
                else { $seenApaar[$apaarNorm] = true; }
            }

            if ($v['status'] === 'error') { $preErrors++; continue; }
            $toImport[] = $v['data'];
        }

        try {
            $res = $this->_runStudentImport($toImport, ['dupPolicy' => $dupPolicy, 'importMode' => $importMode]);
        } catch (\Throwable $e) {
            log_message('error', 'IMPORT COMMIT error: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Import failed — check server logs.']);
            return;
        }

        // Learn this school's column layout from a successful import so the next
        // upload of the same format auto-maps. Only persist when something was
        // actually imported/updated (don't memorise a failed/empty attempt).
        if (((int) ($res['success'] ?? 0) + (int) ($res['updated'] ?? 0)) > 0
            && is_array($payload['mapping'] ?? null)) {
            $this->_saveImportMapping($payload['mapping']);
        }

        // Rows the client sent that we refused before writing (defense-in-depth;
        // the UI already excludes error rows from the commit set).
        $res['error'] = (int) ($res['error'] ?? 0) + $preErrors;

        // Stash this batch's created logins so import_credentials_pdf() can build
        // the handout after the whole (chunked) import finishes. Reset on the
        // first batch so a fresh import doesn't append to a previous one.
        $this->_stashImportCredentials(
            'import_creds_student',
            is_array($res['credentials'] ?? null) ? $res['credentials'] : [],
            !empty($payload['firstBatch'])
        );

        echo json_encode([
            'status'  => 'success',
            'summary' => $this->_formatImportSummary($res),
            'counts'  => [
                'success'     => (int) ($res['success'] ?? 0),
                'updated'     => (int) ($res['updated'] ?? 0),
                'duplicates'  => count($res['duplicates'] ?? []),
                'error'       => (int) ($res['error'] ?? 0),
                'feesPending' => count($res['feesPending'] ?? []),
            ],
            'skipped'     => $res['skipped'] ?? [],
            'duplicates'  => $res['duplicates'] ?? [],
            'feesPending' => $res['feesPending'] ?? [],
            // Phase 11 (P11-G1) — per-student identity for the import RESULT
            // screen, so the operator can see what SRNO / Admission Number the
            // system allocated without opening the PDF or the student list.
            //
            // Built from the SAME $res['credentials'] rows already assembled
            // during the import loop — zero additional Firestore reads.
            //
            // The DEFAULT PASSWORD IS DELIBERATELY OMITTED here: this payload is
            // rendered into the page, while the password stays in the
            // session-stashed handout that only import_credentials_pdf() reads.
            // Do not add it. APAAR is likewise never included.
            'created'     => array_map(static function ($c) {
                return [
                    'name'        => (string) ($c['name'] ?? ''),
                    'userId'      => (string) ($c['userId'] ?? ''),
                    'srNo'        => (string) ($c['srNo'] ?? ''),
                    'admissionNo' => (string) ($c['admissionNo'] ?? ''),
                ];
            }, is_array($res['credentials'] ?? null) ? $res['credentials'] : []),
        ]);
    }

    /**
     * Pre-commit duplicate scan (AJAX, no writes). Receives canonical rows in
     * the `payload` field; for each row with a phone, checks the DB phone index
     * and earlier rows in the same file. Returns per-row match info so the
     * preview can flag duplicates BEFORE the operator commits. Costs one
     * indexed read per row-with-phone, so it is opt-in (a button), not part of
     * every validate.
     */
    public function import_dedupe_check()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');
        $this->output->set_content_type('application/json');

        $payload = json_decode((string) $this->input->post('payload'), true);
        $rows    = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        // Phase 9.2 — only relevant for the SR Number existing-owner check
        // below; APAAR's existing-owner check applies regardless of mode
        // (APAAR is optionally supplied in either mode).
        $importMode = (string) ($payload['importMode'] ?? '');
        if (!in_array($importMode, ['historical', 'new'], true)) $importMode = '';

        $seenPhones  = [];
        $seenNameDob = [];
        $out = [];
        $dupCount = 0;

        // Phase 9.2 — load once, outside the loop (same pattern as the
        // existing phone-index check's own per-row DB read).
        if ($importMode === 'historical') {
            $this->load->library('Sr_no_service', null, 'srnoSvc');
            $this->srnoSvc->init($this->firebase, $this->fs, $this->parent_db_key, (string) ($this->admin_id ?? 'system'));
        }
        $this->load->library('Apaar_service', null, 'apaarSvc');
        $this->apaarSvc->init($this->firebase, $this->fs, $this->parent_db_key, (string) ($this->admin_id ?? 'system'));

        foreach ($rows as $i => $r) {
            $r = is_array($r) ? $r : [];
            $name = trim((string) ($r['Name'] ?? ''));
            $phone10 = preg_replace('/\D+/', '', (string) ($r['Phone Number'] ?? ''));
            if (strlen($phone10) > 10) $phone10 = substr($phone10, -10);
            $dob = trim((string) ($r['DOB'] ?? ''));
            $nameKey = strtolower($name) . '|' . $dob;

            // SIB-B1 (Phase 13) — this preview MUST apply the SAME rule as the
            // commit path (`_runStudentImport`), otherwise the operator would be
            // warned that two siblings are duplicates and then watch the import
            // correctly create both — or worse, trust the warning and delete a
            // legitimate row. Phone alone is not proof; name must corroborate.
            $nameNorm = strtolower(preg_replace('/\s+/', ' ', trim($name)));

            $match = null; $reason = '';
            if ($phone10 !== '' && strlen($phone10) === 10 && $nameNorm !== '') {
                if (isset($seenPhones[$phone10][$nameNorm])) {
                    $match = '(earlier row)'; $reason = 'duplicate phone + name in file';
                } else {
                    // P14-1: check EVERY student registered against this phone.
                    foreach ($this->_existingStudentIdsByPhone($phone10) as $candidateId) {
                        if ($this->_studentNameMatches($candidateId, $nameNorm)) {
                            $match = $candidateId; $reason = 'existing student (phone + name)';
                            break;
                        }
                    }
                }
            }
            if (!$match && $dob !== '' && isset($seenNameDob[$nameKey])) { $match = '(earlier row)'; $reason = 'duplicate name+DOB in file'; }

            // Phase 9.2 — historical SR Number against the school's EXISTING
            // srNoIndex ownership (a row's own in-file duplicate is already
            // caught separately by import_validate()/import_commit(); this
            // is specifically the "already owned by a student NOT in this
            // file" case, requiring a Firestore read, hence this opt-in
            // endpoint rather than the pure validateRow()).
            $srNoConflict = null;
            if (!$match && $importMode === 'historical') {
                $srRaw = trim((string) ($r['SR Number'] ?? ''));
                if ($srRaw !== '') {
                    $res = $this->srnoSvc->resolve($srRaw);
                    if (!empty($res['ok'])) {
                        $srNoConflict = "SR Number {$srRaw} already assigned to {$res['currentStudentId']} in this school";
                    }
                }
            }

            // Phase 9.2 — same check for APAAR, either mode.
            $apaarConflict = null;
            if (!$match) {
                $apaarRaw = trim((string) ($r['APAAR ID'] ?? ''));
                if ($apaarRaw !== '') {
                    $res = $this->apaarSvc->resolve($apaarRaw);
                    if (!empty($res['ok'])) {
                        $apaarConflict = "APAAR ID already assigned to {$res['currentStudentId']} in this school";
                    }
                }
            }

            if ($match !== null) {
                $dupCount++;
                $out[] = ['index' => $i, 'duplicate' => true, 'existingId' => $match, 'reason' => $reason];
            } elseif ($srNoConflict !== null || $apaarConflict !== null) {
                $dupCount++;
                $out[] = ['index' => $i, 'duplicate' => true, 'existingId' => null, 'reason' => trim(($srNoConflict ?? '') . ' ' . ($apaarConflict ?? ''))];
            } else {
                $out[] = ['index' => $i, 'duplicate' => false];
            }

            // SIB-B1 (Phase 13) — nested by name, mirroring the commit path.
            if ($phone10 !== '' && strlen($phone10) === 10 && $nameNorm !== '') {
                $seenPhones[$phone10][$nameNorm] = true;
            }
            if ($dob !== '') $seenNameDob[$nameKey] = true;
        }

        echo json_encode(['rows' => $out, 'duplicates' => $dupCount, 'total' => count($rows)]);
    }

    /**
     * Download the canonical CSV template (headers + one example row).
     */
    /**
     * Load this school's learned header→field mappings (Phase 3 saved mappings).
     * Returns [normalizedHeaderKey => fieldKey]; empty on first-ever import.
     */
    private function _loadImportMapping(): array
    {
        try {
            $doc = $this->fs->get('importMappings', $this->fs->docId('student_import'));
            if (is_array($doc) && is_array($doc['headerMap'] ?? null)) {
                return $doc['headerMap'];
            }
        } catch (\Throwable $e) {
            log_message('error', 'load import mapping failed: ' . $e->getMessage());
        }
        return [];
    }

    /**
     * Merge the column layout the operator used into this school's saved
     * mapping. Keys are normalized (Firestore-safe) so "D.O.B" and "DOB" learn
     * the same slot; values are canonical field keys. $mapping = [{header, field}].
     */
    private function _saveImportMapping(array $mapping): void
    {
        try {
            $this->load->library('import_schema');
            $docId    = $this->fs->docId('student_import');
            $existing = $this->fs->get('importMappings', $docId);
            $headerMap = is_array($existing['headerMap'] ?? null) ? $existing['headerMap'] : [];

            foreach ($mapping as $m) {
                if (!is_array($m)) continue;
                $key   = $this->import_schema->normalizeHeaderKey((string) ($m['header'] ?? ''));
                $field = trim((string) ($m['field'] ?? ''));
                if ($key !== '' && $field !== '') $headerMap[$key] = $field;
            }

            $this->fs->set('importMappings', $docId, [
                'schoolId'  => $this->school_id,
                'type'      => 'student',
                'headerMap' => $headerMap,
                'updatedAt' => date('c'),
            ], true);
        } catch (\Throwable $e) {
            log_message('error', 'save import mapping failed: ' . $e->getMessage());
        }
    }

    public function import_template()
    {
        $this->_require_role(self::VIEW_ROLES, 'import_template', 'SIS', 'view');
        $this->load->library('import_schema');

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $this->import_schema->templateHeaders());
        fputcsv($fh, $this->import_schema->exampleRow());
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $this->output
            ->set_content_type('text/csv')
            ->set_header('Content-Disposition: attachment; filename="ZenXii_student_import_template.csv"')
            ->set_header('Cache-Control: no-store, no-cache')
            ->set_output($csv);
    }

    /**
     * Download a PDF handout of the logins created by the most recent import
     * (Name · Mobile Number · User ID · Default Password). Reads the credentials
     * stashed in the session by import_commit(); a blank mobile is left blank.
     * GET (no CSRF) — the session bucket is the source of truth, so the browser
     * never has to send plaintext passwords back.
     */
    public function import_credentials_pdf()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');

        $creds = $this->session->userdata('import_creds_student');
        if (!is_array($creds) || empty($creds)) {
            show_error('No student credentials are available to export. Run an import first, then download from the result screen.', 404, 'Nothing to export');
            return;
        }

        $this->load->library('pdf_generator');
        $html = $this->load->view('import_credentials_pdf', [
            'rows'        => $creds,
            'entityLabel' => 'Student',
            'title'       => 'Student Login Credentials',
            'schoolName'  => $this->school_display_name ?: $this->school_name,
            'sessionYear' => $this->session_year,
            'generatedAt' => date('d-m-Y H:i'),
        ], true);

        $this->pdf_generator->download($html, 'Student_Credentials_' . date('Ymd_His') . '.pdf');
    }

    /**
     * Append a chunked import's created logins to a session bucket so the whole
     * roster can be exported as one PDF after all batches finish. $reset clears
     * the bucket first (used on the first batch of a fresh import). Capped to
     * keep the session small on very large rosters.
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

    /* ══════════════════════════════════════════════════════════════════════
       LEGACY ADMISSION FORM — Merged from Student.php
       (Includes photo/doc uploads and subject assignment)
    ══════════════════════════════════════════════════════════════════════ */

    public function studentAdmission()
    {
        redirect('sis/admission');
    }

    /* ══════════════════════════════════════════════════════════════════════
       AJAX HELPERS — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function get_sections_by_class()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_sections_by_class', 'SIS', 'view');
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $className = trim((string)$this->input->post('class_name'));
        if ($className === '') {
            header('Content-Type: application/json');
            echo json_encode([]);
            return;
        }
        $className = $this->safe_path_segment($className, 'class_name');

        // Normalize to canonical "Class 8th" format. The JS may send raw
        // values like "8th", "8", "LKG", or already-prefixed "Class 8th".
        // Use the Phase 1 normalizer which handles all variants.
        require_once APPPATH . 'libraries/Entity_firestore_sync.php';
        $cs = Entity_firestore_sync::normalizeClassSection($className, '');
        $classKey = $cs['className'] !== '' ? $cs['className'] : 'Class ' . $className;

        // Firestore first → RTDB fallback
        $sections = [];
        try {
            $fsDocs = $this->fs->schoolWhere('sections', [['className', '==', $classKey]]);
            if (is_array($fsDocs) && !empty($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    // Firestore_rest_client::query() returns [{id, data: {...}}]
                    $d   = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sec = $d['section'] ?? '';
                    if ($sec !== '') {
                        // Return just the letter: "Section A" → "A"
                        $sections[] = str_replace('Section ', '', $sec);
                    }
                }
                $sections = array_values(array_unique($sections));
                sort($sections);
            }
        } catch (\Exception $e) {}

        // RTDB fallback removed per no-RTDB policy. Firestore `sections` is the sole source.
        header('Content-Type: application/json');
        echo json_encode($sections);
    }

    public function fetch_subjects()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_subjects', 'SIS', 'view');
        header('Content-Type: application/json');
        $school_name = $this->school_name;
        $rawClass = trim((string) $this->input->post('class_name'));
        if ($rawClass === '') {
            $input = json_decode(file_get_contents('php://input'), true);
            $rawClass = trim($input['class_name'] ?? '');
        }
        if ($rawClass === '' || !preg_match('/\d+/', $rawClass, $m)) {
            echo json_encode([]);
            return;
        }
        $classKey = (int)$m[0];
        // Firestore is the canonical (and only) store for subjects. The old
        // RTDB fallback ($this->CM->get_data("Schools/.../Subject_list/...")) was
        // a dead read post ZERO-RTDB migration — it returned nothing and, when
        // the Firestore result was empty, risked a fatal if Common_model was not
        // loaded in this scope. Removed; an empty Firestore result now correctly
        // yields an empty subject list.
        $subjectData = [];
        $fsDocs = $this->fs->schoolWhere('subjects', [['classKey', '==', (string)$classKey]]);
        if (is_array($fsDocs) && !empty($fsDocs)) {
            foreach ($fsDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $code = $d['subject_code'] ?? $d['code'] ?? '';
                if ($code !== '') $subjectData[$code] = $d;
            }
        }
        $subjects = [];
        if (is_array($subjectData)) {
            foreach ($subjectData as $code => $item) {
                if (!is_array($item)) continue;
                $category = strtolower(trim($item['category'] ?? ''));
                $name     = trim($item['subject_name'] ?? $item['name'] ?? '');
                if ($name !== '' && in_array($category, ['additional', 'skill-based'], true)) {
                    $subjects[] = $name;
                }
            }
        }
        echo json_encode(array_values(array_unique($subjects)));
    }

    /* ══════════════════════════════════════════════════════════════════════
       EDIT STUDENT — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function edit_student($userId)
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'edit');
        if (empty($userId) || !preg_match('/^[A-Za-z0-9_]+$/', $userId)) { show_404(); return; }

        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $existing = $this->_getStudent($userId);
        if (!$existing) { show_404(); return; }

        $classKey          = Firestore_service::classKey(trim($existing['Class'] ?? ''));
        $sectionKey        = Firestore_service::sectionKey(trim($existing['Section'] ?? ''));
        $combinedClassPath = "{$classKey}/{$sectionKey}";

        if ($this->input->method() !== 'post') {
            // Read additional subjects from student doc.
            $data['additional_subjects'] = $existing['additionalSubjects'] ?? $existing['Additional Subjects'] ?? [];
            // Fees Exemption v2 (P0-b): the legacy exemption-checkbox feed was
            // removed (kept fee-head NAMES nowhere — see save_admission comment).
            // Concessions are now managed via Fee_concessions screen.

            $classNumKey = null;
            if (preg_match('/\d+/', $existing['Class'] ?? '', $m)) $classNumKey = (int)$m[0];
            $allSubjects = [];
            if ($classNumKey) {
                $subjectDocs = $this->fs->schoolWhere('subjects', [['classKey', '==', (string)$classNumKey]]);
                foreach ($subjectDocs as $doc) {
                    $item = $doc['data'];
                    $category = strtolower(trim($item['category'] ?? ''));
                    $name     = trim($item['name'] ?? $item['subject_name'] ?? '');
                    if ($name !== '' && in_array($category, ['additional', 'skill-based'], true)) $allSubjects[] = $name;
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

        // POST mode
        header('Content-Type: application/json');
        $post = $this->input->post();

        $dob           = !empty($post['dob'])            ? trim($post['dob'])            : ($existing['DOB']            ?? '');
        $admissionDate = !empty($post['admission_date']) ? trim($post['admission_date']) : ($existing['Admission Date'] ?? '');
        $religion = $post['religion'] ?? ($existing['Religion'] ?? '');
        if ($religion === 'Other' && !empty($post['other_religion'])) $religion = trim($post['other_religion']);
        $preMarks = trim($post['pre_marks'] ?? '');
        if ($preMarks !== '' && substr($preMarks, -1) !== '%') $preMarks .= '%';

        $updateData = [
            "Name" => $post['Name'] ?? ($existing['Name'] ?? ''),
            "DOB" => $dob, "Admission Date" => $admissionDate,
            "Phone Number" => $post['phone_number'] ?? ($existing['Phone Number'] ?? ''),
            "Email" => $post['email'] ?? ($existing['Email'] ?? ''),
            "Gender" => $post['gender'] ?? ($existing['Gender'] ?? ''),
            "Category" => $post['category'] ?? ($existing['Category'] ?? ''),
            "Blood Group" => $post['blood_group'] ?? ($existing['Blood Group'] ?? ''),
            "Religion" => $religion,
            "Nationality" => $post['nationality'] ?? ($existing['Nationality'] ?? ''),
            "Father Name" => $post['father_name'] ?? ($existing['Father Name'] ?? ''),
            "Father Occupation" => $post['father_occupation'] ?? ($existing['Father Occupation'] ?? ''),
            "Mother Name" => $post['mother_name'] ?? ($existing['Mother Name'] ?? ''),
            "Mother Occupation" => $post['mother_occupation'] ?? ($existing['Mother Occupation'] ?? ''),
            "Guard Contact" => $post['guard_contact'] ?? ($existing['Guard Contact'] ?? ''),
            "Guard Relation" => $post['guard_relation'] ?? ($existing['Guard Relation'] ?? ''),
            "Pre Class" => $post['pre_class'] ?? ($existing['Pre Class'] ?? ''),
            "Pre School" => $post['pre_school'] ?? ($existing['Pre School'] ?? ''),
            "Pre Marks" => $preMarks !== '' ? $preMarks : ($existing['Pre Marks'] ?? ''),
            "Address" => [
                "Street" => $post['street'] ?? ($existing['Address']['Street'] ?? ''),
                "City" => $post['city'] ?? ($existing['Address']['City'] ?? ''),
                "State" => $post['state'] ?? ($existing['Address']['State'] ?? ''),
                "PostalCode" => $post['postal_code'] ?? ($existing['Address']['PostalCode'] ?? ''),
            ],
            "Class" => $existing["Class"], "Section" => $existing["Section"],
            "User Id" => $existing["User Id"], "Password" => $existing["Password"] ?? '',
        ];

        $updateData["Profile Pic"] = $existing["Profile Pic"] ?? '';
        $existingDoc = is_array($existing["Doc"] ?? null) ? $existing["Doc"] : [];
        foreach (['Birth Certificate', 'Aadhar Card', 'Transfer Certificate', 'Photo'] as $docKey) {
            if (isset($existingDoc[$docKey]) && !is_array($existingDoc[$docKey])) {
                $existingDoc[$docKey] = ['url' => (string)$existingDoc[$docKey], 'thumbnail' => ''];
            }
        }
        $updateData["Doc"] = $existingDoc;

        // Document re-upload
        $documents = ['birthCertificate' => 'Birth Certificate', 'aadharCard' => 'Aadhar Card', 'transferCertificate' => 'Transfer Certificate'];
        foreach ($documents as $inputKey => $label) {
            if (empty($_FILES[$inputKey]['tmp_name'])) continue;
            $oldDoc = $existingDoc[$label] ?? [];
            $this->_deleteOldStorageFile($oldDoc);
            $uploadResult = $this->_uploadStudentFile($_FILES[$inputKey], $school_name, $combinedClassPath, $userId, $label, 'document');
            if ($uploadResult) {
                $updateData["Doc"][$label] = ['url' => $uploadResult['document'] ?? '', 'thumbnail' => $uploadResult['thumbnail'] ?? ''];
            }
        }

        // Photo replace
        $photoUpdated = false;
        if (!empty($_FILES['student_photo']['tmp_name'])) {
            $photo = $_FILES['student_photo'];
            $ext = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $this->_deleteOldStorageFile($existingDoc['Photo'] ?? []);
                $photoResult = $this->_uploadStudentFile($photo, $school_name, $combinedClassPath, $userId, 'profile', 'profile');
                if ($photoResult) {
                    $updateData["Profile Pic"]  = $photoResult['document'];
                    $updateData["Doc"]["Photo"] = ['url' => $photoResult['document'] ?? '', 'thumbnail' => $photoResult['thumbnail'] ?? ''];
                    $photoUpdated = true;
                }
            }
        }

        // Update student in Firestore.
        //
        // Title→camelCase aliasing (Tier-A Step 3) — every PascalCase
        // key the admin form posts gets a camelCase mirror so the
        // mobile apps' StudentDoc (which reads camelCase only) sees
        // the change immediately on this `fs->updateEntity` write,
        // without waiting for the trailing `syncStudent` call to
        // canonicalise the doc. Pre-fix, only Name/Phone/Email/Class/
        // Section were aliased — DOB, Religion, Category, parents,
        // Guard*, Pre*, Address all landed on the doc in PascalCase
        // and silently disappeared from the parent / teacher screens
        // until the next syncStudent merge ran (or never, on partial
        // edits that didn't touch every field).
        $updateData['updatedAt'] = date('c');

        if (isset($updateData['Name']))              $updateData['name']             = $updateData['Name'];
        if (isset($updateData['Phone Number']))      {
            // Mirror Entity_firestore_sync::syncStudent — both `phone`
            // (Android canonical) and `phoneNumber` (back-compat).
            $updateData['phone']       = $updateData['Phone Number'];
            $updateData['phoneNumber'] = $updateData['Phone Number'];
        }
        if (isset($updateData['Email']))             $updateData['email']            = $updateData['Email'];
        if (isset($updateData['Class']))             $updateData['className']        = $updateData['Class'];
        if (isset($updateData['Section']))           $updateData['section']          = $updateData['Section'];
        if (isset($updateData['DOB']))               $updateData['dob']              = $updateData['DOB'];
        if (isset($updateData['Gender']))            $updateData['gender']           = $updateData['Gender'];
        if (isset($updateData['Category']))          $updateData['category']         = $updateData['Category'];
        if (isset($updateData['Blood Group']))       $updateData['bloodGroup']       = $updateData['Blood Group'];
        if (isset($updateData['Religion']))          $updateData['religion']         = $updateData['Religion'];
        if (isset($updateData['Nationality']))       $updateData['nationality']      = $updateData['Nationality'];
        if (isset($updateData['Admission Date']))    $updateData['admissionDate']    = $updateData['Admission Date'];
        if (isset($updateData['Father Name']))       $updateData['fatherName']       = $updateData['Father Name'];
        if (isset($updateData['Father Occupation'])) $updateData['fatherOccupation'] = $updateData['Father Occupation'];
        if (isset($updateData['Mother Name']))       $updateData['motherName']       = $updateData['Mother Name'];
        if (isset($updateData['Mother Occupation'])) $updateData['motherOccupation'] = $updateData['Mother Occupation'];
        if (isset($updateData['Guard Contact']))     $updateData['guardContact']     = $updateData['Guard Contact'];
        if (isset($updateData['Guard Relation']))    $updateData['guardRelation']    = $updateData['Guard Relation'];
        if (isset($updateData['Pre Class']))         $updateData['preClass']         = $updateData['Pre Class'];
        if (isset($updateData['Pre School']))        $updateData['preSchool']        = $updateData['Pre School'];
        if (isset($updateData['Pre Marks']))         $updateData['preMarks']         = $updateData['Pre Marks'];
        if (isset($updateData['Address']))           $updateData['address']          = $updateData['Address'];
        if (isset($updateData['Profile Pic']))       $updateData['profilePic']       = $updateData['Profile Pic'];
        if (isset($updateData['Roll No']))           $updateData['rollNo']           = $updateData['Roll No'];

        // ── Phase 14.1 — Roll Number roster uniqueness on EDIT ──────────────
        // Checked BEFORE the write so a rejected edit changes nothing.
        //
        // SELF-UPDATE EXCEPTION (§8): `$userId` is excluded, so re-saving a
        // profile without touching the roll — the overwhelmingly common case —
        // is always allowed and never trips on the student's own roll.
        //
        // Scope note: this edit form preserves Class/Section from the existing
        // record (see the `"Class" => $existing["Class"]` above), so a transfer
        // cannot happen here; the student's CURRENT class/section is the
        // correct scope to validate against.
        $editRoll = trim((string) ($updateData['Roll No'] ?? ''));
        if ($editRoll !== '') {
            $rollConflict = $this->_rollNoConflict(
                $editRoll, $classKey, $sectionKey, (string) $this->session_year, $userId
            );
            if ($rollConflict !== null) {
                return $this->json_error($this->_rollNoConflictMessage($rollConflict, $classKey, $sectionKey));
            }
        }
        // The `Doc` nested map (Photo, BirthCert, AadharCard, …) is
        // mirrored to the canonical `documents` key the parent app
        // reads. Both shapes are kept on the doc so any legacy reader
        // that still expects `Doc` continues to work.
        if (isset($updateData['Doc']))               $updateData['documents']        = $updateData['Doc'];

        // Additional subjects
        $additionalSubjects = [];
        if (!empty($post['additional_subjects']) && is_array($post['additional_subjects'])) {
            foreach ($post['additional_subjects'] as $sub) {
                $sub = trim($sub);
                if ($sub !== '') $additionalSubjects[$sub] = "";
            }
        }
        $updateData['additionalSubjects'] = $additionalSubjects;

        // Fees Exemption v2 (P0-b): the exempted_fees_multiple edit-write was
        // removed. Concessions are captured via Fee_concessions (Phase 0+),
        // applied by the unified generator (Phase 2+). Edit billing unchanged.

        $this->fs->updateEntity('students', $userId, $updateData);

        // SIS Wave-2 fix H1 (2026-05-31): emit PROFILE_UPDATE history entry,
        // mirroring the canonical pattern from Sis::update_profile@736-771.
        // Pre-fix, edit_student persisted comprehensive profile edits to
        // Firestore but emitted no audit entry, leaving zero forensic trail
        // for compliance reviews. Diff computed against $existing (loaded at
        // L3499 via _getStudent). Skip 'updatedAt' (timestamp churn) and
        // camelCase mirrors (the L3624-3669 aliasing block duplicates
        // Title-Case keys for the Android apps — including both would
        // double-count every change). Use === for type-safe comparison.
        $auditDiff = [];
        foreach ($updateData as $auditKey => $newVal) {
            if ($auditKey === 'updatedAt') continue;
            if ($auditKey === '' || ctype_lower($auditKey[0])) continue;
            $oldVal = $existing[$auditKey] ?? null;
            if ($oldVal === $newVal) continue;
            $auditDiff[$auditKey] = ['old' => $oldVal, 'new' => $newVal];
        }
        if (!empty($auditDiff)) {
            $changedKeys = array_keys($auditDiff);
            $this->_log_history($school_id, $userId, 'PROFILE_UPDATE',
                "Profile updated: " . implode(', ', $changedKeys),
                ['fields' => $changedKeys, 'changes' => $auditDiff]
            );
        }

        // RTDB mirror removed per no-RTDB policy.

        // Entity sync: update student in Firestore (Android apps)
        try {
            // SIS Wave-2 S6 (2026-05-31): observability for sync return.
            if (!$this->entity_sync->syncStudent($userId, $updateData)) {
                log_message('warning', "syncStudent returned false for {$userId} (edit_student)");
            }
            if (!$this->entity_sync->syncParent($userId, $updateData)) {
                log_message('warning', "syncParent returned false for {$userId} (edit_student)");
            }
        } catch (\Exception $e) { log_message('error', "entity_sync syncStudent failed for {$userId}: " . $e->getMessage()); }

        $response = ['status' => 'success', 'message' => 'Student updated successfully'];
        if ($photoUpdated) $response['photo_notice'] = 'Profile photo updated with thumbnail.';
        echo json_encode($response);
    }

    /* ══════════════════════════════════════════════════════════════════════
       DELETE STUDENT — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function delete_student($id)
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'SIS', 'manage');

        // ── LC-3 · VALIDATE THE ID BEFORE THE MOST DESTRUCTIVE TRANSITION ──
        //
        // `$id` arrives as a URL ROUTE SEGMENT, not as POST, and this method
        // is the only student transition that never checked it. Every other
        // one does — safe_path_segment() appears 53 times in this controller —
        // and this is the one that hard-deletes the student, strips them from
        // the class roster and freezes their fee book.
        //
        // CodeIgniter's permitted_uri_chars narrows the character set, so this
        // was probably not exploitable today. That is a property of the ROUTER
        // configuration, not of this endpoint: relying on it means a future
        // change to permitted_uri_chars, or a new caller that passes $id from
        // somewhere other than the URL, silently removes the only thing
        // standing between a crafted id and a Firestore document path.
        //
        // The id also flows into roster removal and the fee-book freeze, so a
        // malformed one would leave those side effects pointing at nothing.
        $id = trim((string) $id);
        if ($id === '' || !$this->safe_path_segment($id)) {
            return $this->input->is_ajax_request()
                ? $this->json_error('Invalid User ID.')
                : redirect('sis');
        }

        // FIXED: return JSON for AJAX requests instead of redirect (was breaking bulk delete)
        $isAjax = $this->input->is_ajax_request();
        if ($this->input->method() !== 'post') {
            if ($isAjax) return $this->json_error('POST required');
            redirect('sis/students'); return;
        }
        if (empty($id) || !preg_match('/^[A-Za-z0-9_]+$/', $id)) {
            if ($isAjax) return $this->json_error('Invalid student ID');
            redirect('sis/students'); return;
        }

        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $student = $this->_getStudent($id);
        if (!$student) {
            if ($isAjax) return $this->json_error('Student not found');
            redirect('sis/students'); return;
        }

        $phoneNumber = $student['Phone Number'] ?? '';
        $class       = $student['Class']   ?? '';
        $section     = $student['Section'] ?? '';
        if (!$class || !$section) {
            if ($isAjax) return $this->json_error('Class or Section missing from student profile');
            $this->session->set_flashdata('error', 'Class or Section missing');
            redirect('sis/students'); return;
        }
        $class   = Firestore_service::classKey($class);
        $section = Firestore_service::sectionKey($section);
        $combinedClassPath = "{$class}/{$section}";

        // Preserve fee records
        try {
            $this->feeLifecycle->freezeFeesOnSoftDelete($id);
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::freezeFeesOnSoftDelete failed for {$id}: " . $e->getMessage());
        }

        // Cascade — clear the back-link on any CRM application that
        // enrolled this student. Without this, the application doc
        // keeps a stale `student_id` field pointing at a deleted record.
        // We don't change `status` (keep it as "enrolled" for history)
        // but blank the link and append an audit-style history entry.
        try {
            $appId = (string) ($student['application_id'] ?? '');
            if ($appId !== '') {
                $existingApp = $this->_crm_get('crmApplications', $appId);
                if (is_array($existingApp)) {
                    $now = date('Y-m-d H:i:s');
                    $hist = is_array($existingApp['history'] ?? null) ? $existingApp['history'] : [];
                    $hist[] = [
                        'action'    => "Linked student {$id} was deleted from SIS",
                        'by'        => $this->admin_name,
                        'timestamp' => $now,
                    ];
                    $this->_crm_update('crmApplications', $appId, [
                        'student_id'      => '',
                        'student_deleted' => true,
                        'updated_at'      => $now,
                        'history'         => $hist,
                    ]);
                }
            }
        } catch (\Exception $e) {
            log_message('error', "delete_student: crmApplications back-link cleanup failed for {$id}: " . $e->getMessage());
        }

        // Determine if this is a hard delete or soft delete
        $hardDelete = $this->input->post('hard_delete') === 'true';

        if ($hardDelete) {
            // ── HARD DELETE: permanent removal ──────────────────────
            $this->dw->removeFromRoster($class, $section, $id);
            $this->dw->hardDeleteStudent($id);

            // Clean storage. Canonical path first (all post-2026-06-13 uploads
            // live here); the two legacy paths below are best-effort cleanup for
            // files admitted before the storage-path cutover and not yet migrated.
            $this->CM->delete_folder_from_firebase_storage("schools/{$this->school_id}/students/{$id}");
            $this->CM->delete_folder_from_firebase_storage("{$school_name}/Students/{$combinedClassPath}/{$id}");
            $this->CM->delete_folder_from_firebase_storage("Students/{$school_id}/{$id}");

            // Clean Firestore + phone index
            $this->fs->removeEntity('students', $id);
            if (!empty($phoneNumber)) {
                // P14-1: the phone entry may now be shared by SIBLINGS. Deleting
                // the whole document would silently de-register every brother and
                // sister of the deleted student, so only THIS student is removed
                // from the set; the document is deleted only when it becomes
                // empty. Without this, hard-deleting one child would break
                // duplicate detection for the rest of the family.
                $this->_deregisterPhoneIndex($phoneNumber, $id);
            }

            // G3 — Cascade delete orphan rows in collections keyed by
            // studentId. Pre-fix, hard-deleting a student left behind
            // months of attendanceSummary rows, every daily attendance
            // doc, every marks entry, and every fee record — surfacing
            // as ghost data on parent/teacher dashboards. Each
            // collection is queried+deleted in isolation; a failure on
            // one (e.g. a missing collection on a fresh school)
            // doesn't block the others.
            foreach (['attendanceSummary', 'attendance', 'marks', 'feeReceipts', 'feeDemands', 'studentFlags', 'submissions', 'teacherMarks'] as $cascadeCol) {
                try {
                    $rows = $this->fs->schoolWhere($cascadeCol, [
                        ['studentId', '==', $id],
                    ]);
                    foreach ($rows as $row) {
                        $docId = is_array($row) ? ($row['id'] ?? '') : '';
                        if ($docId === '') continue;
                        try { $this->fs->remove($cascadeCol, $docId); }
                        catch (\Exception $inner) {
                            log_message('error', "G3 cascade {$cascadeCol}/{$docId} delete failed: " . $inner->getMessage());
                        }
                    }
                } catch (\Exception $e) {
                    log_message('error', "G3 cascade {$cascadeCol} query failed for {$id}: " . $e->getMessage());
                }
            }

            // Delete Firebase Auth account
            try { $this->firebase->deleteFirebaseUser($id); } catch (Exception $e) {}

            // SRNO_STUDENT_ID_RE_ADMISSION_MULTI_TENANT_FINAL_AUDIT.md Phase 5 —
            // deliberately NOT deleting or reassigning the srNoIndex entry here.
            // Whether a hard-deleted student's SRNO should ever be released for
            // reuse, or permanently frozen as historical-only, is a business
            // decision this pass does not make unilaterally (mirrors the
            // existing "gap, never reuse" philosophy already established for
            // withdrawn students in SR_NUMBER_AND_APAAR_ID_DESIGN.md §3.4/§9.2).
            // This is the smallest safe action: mark the fact that the CURRENT
            // owner was hard-deleted, purely informational, so a future
            // integrity verifier (or Student Trail) can detect and surface it
            // rather than silently resolving SRNO -> a Student ID that no
            // longer has a Firestore document. Best-effort; never blocks the
            // deletion itself.
            try {
                $srNoNorm = trim((string) ($student['srNoNorm'] ?? ''));
                if ($srNoNorm !== '') {
                    $idxDocId = $this->fs->docId($srNoNorm);
                    $idxDoc = $this->fs->get('srNoIndex', $idxDocId);
                    if (is_array($idxDoc) && ($idxDoc['currentStudentId'] ?? '') === $id) {
                        $this->fs->update('srNoIndex', $idxDocId, [
                            'currentOwnerDeleted'   => true,
                            'currentOwnerDeletedAt' => date('c'),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', "SRNO orphan-flag on hard-delete failed for {$id}: " . $e->getMessage());
            }

            // Phase 11 (P11-G2) — the SAME informational orphan marker, extended
            // to the other two identity indexes. Before this, a hard delete left
            // `admissionNoIndex` and `apaarIndex` documents resolving to a Student
            // ID with no Firestore document, and with no marker distinguishing
            // that from a healthy entry — `srNoIndex` was flagged and these two
            // were simply missed.
            //
            // This is INTEGRITY MARKING ONLY. It deliberately does NOT make the
            // Admission Number or the APAAR ID reclaimable: no document is
            // deleted, no ownership is released, and resolve()/relink() behaviour
            // is untouched. Whether a hard-deleted student's identifiers should
            // ever be reusable remains the open business decision recorded above
            // for SRNO — this pass does not answer it for these two either.
            //
            // Tenant-safe: every doc id goes through `$this->fs->docId()`, which
            // prefixes the caller's own schoolId, so School A can never flag
            // School B's index entry. Idempotent: re-running sets the same two
            // fields to the same values. Guarded by an owner check, so an index
            // entry already relinked to a DIFFERENT (live) student — the normal
            // re-admission case — is never mis-flagged. Best-effort: a failure is
            // logged and never blocks the deletion.
            //
            // Cost: at most +2 reads / +2 writes, ONLY on an explicit, rare
            // hard-delete action. No read is added to any normal student path.
            try {
                $admNo = trim((string) ($student['Admission Number'] ?? $student['admissionNumber'] ?? ''));
                if ($admNo !== '') {
                    $admDocId = $this->fs->docId(str_replace('/', '_', $admNo));
                    $admDoc   = $this->fs->get('admissionNoIndex', $admDocId);
                    // ── LC-8 · THIS GUARD READ A FIELD THAT DOES NOT EXIST ──
                    //
                    // P11-G2 extended the srNoIndex orphan marker to this
                    // collection and to apaarIndex by copying the srNoIndex
                    // guard verbatim, including its owner field.
                    //
                    // That was right for apaarIndex and wrong here. The three
                    // indexes do not share a schema:
                    //
                    //   Sr_no_service     writes `currentStudentId`
                    //   Apaar_service     writes `currentStudentId`
                    //   Admission_no_service writes `studentId`   <-- this one
                    //
                    // So `$admDoc['currentStudentId']` was always null, the
                    // comparison was always false, and the marker P11-G2
                    // promises was never written once. Measured on the live
                    // tenant: 16 orphaned entries, 16 of them unmarked —
                    // indistinguishable from healthy entries, which is exactly
                    // the state the marker exists to prevent.
                    //
                    // It failed SILENTLY and in the safe-looking direction: no
                    // error, no exception, just a write that never happened.
                    //
                    // Both names are accepted rather than swapping one for the
                    // other. `studentId` is what Admission_no_service writes
                    // today (its own resolve() reads it at line ~204), and
                    // tolerating `currentStudentId` means this guard keeps
                    // working if the collection is ever converted to the
                    // srNoIndex shape. Neither is defaulted to a value that
                    // could make an unrelated entry match: an entry with
                    // neither field simply is not ours to mark.
                    $admOwner = trim((string) (
                        $admDoc['studentId'] ?? $admDoc['currentStudentId'] ?? ''
                    ));
                    if (is_array($admDoc) && $admOwner !== '' && $admOwner === $id) {
                        $this->fs->update('admissionNoIndex', $admDocId, [
                            'currentOwnerDeleted'   => true,
                            'currentOwnerDeletedAt' => date('c'),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', "Admission Number orphan-flag on hard-delete failed for {$id}: " . $e->getMessage());
            }

            try {
                // `students.apaarId` already holds the NORMALIZED 12-digit value
                // (Apaar_service::normalize() returns null rather than a partial
                // transform), which is exactly the doc-id suffix claim() used.
                // Load the library first: the static call needs the class in
                // scope, and this method has no other Apaar_service use.
                $this->load->library('Apaar_service', null, 'apaarSvc');
                $apaarNorm = \Apaar_service::normalize((string) ($student['apaarId'] ?? ''));
                if ($apaarNorm !== null) {
                    $apaarDocId = $this->fs->docId($apaarNorm);
                    $apaarDoc   = $this->fs->get('apaarIndex', $apaarDocId);
                    if (is_array($apaarDoc) && ($apaarDoc['currentStudentId'] ?? '') === $id) {
                        $this->fs->update('apaarIndex', $apaarDocId, [
                            'currentOwnerDeleted'   => true,
                            'currentOwnerDeletedAt' => date('c'),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', "APAAR orphan-flag on hard-delete failed for {$id}: " . $e->getMessage());
            }

            log_audit('SIS', 'hard_delete_student', $id, "Permanently deleted student '{$student['Name']}' from {$class} {$section}");
        } else {
            // ── SOFT DELETE (default): recoverable ─────────────────
            $reason = trim($this->input->post('reason') ?? '') ?: 'Deleted by admin';
            $this->dw->softDeleteStudent($id, $class, $section, $reason);

            $this->_log_history($school_id, $id, 'DELETED',
                "Student soft-deleted: {$reason}",
                ['class' => $class, 'section' => $section, 'reason' => $reason]
            );

            log_audit('SIS', 'soft_delete_student', $id, "Soft-deleted student '{$student['Name']}' from {$class} {$section}");
        }

        // G2 — Section strength drops by one regardless of hard / soft
        // delete: both flip the row out of the `status='Active'` filter
        // (hard removes the doc entirely, soft sets `status='Deleted'`).
        $this->_recompute_section_strength($class, $section);

        // FIXED: return JSON for AJAX, redirect for direct form POST
        if ($isAjax) {
            return $this->json_success(['message' => 'Student deleted successfully.']);
        }
        $this->session->set_flashdata('success', 'Student deleted successfully');
        redirect('sis/students');
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT PROFILE (with fees) — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function student_profile($userId)
    {
        $this->_require_role(self::VIEW_ROLES, 'student_profile', 'SIS', 'view');
        if (empty($userId) || !preg_match('/^[A-Za-z0-9_]+$/', $userId)) { show_404(); return; }

        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;

        $studentData = $this->_getStudent($userId);
        if (!$studentData) { show_error("Student not found"); return; }

        $class   = Firestore_service::classKey($studentData['Class'] ?? '');
        $section = Firestore_service::sectionKey($studentData['Section'] ?? '');
        $basePath = "Schools/$school_name/$session_year/{$class}/{$section}";

        // Subjects
        $subjectsList = [];
        if (!empty($class)) {
            $classNumber = (int) preg_replace('/[^0-9]/', '', $class);
            if ($classNumber > 0) {
                $subjectDocs = $this->fs->schoolWhere('subjects', [['classKey', '==', (string)$classNumber]]);
                foreach ($subjectDocs as $doc) {
                    $sn = $doc['data']['name'] ?? $doc['data']['subject_name'] ?? '';
                    if ($sn !== '') $subjectsList[] = $sn;
                }
            }
        }

        // Read additional subjects and fees from student doc in Firestore
        $additionalSubjects = $studentData['additionalSubjects'] ?? $studentData['Additional Subjects'] ?? [];
        $finalSubjectsList = array_unique(array_merge($subjectsList, array_keys(is_array($additionalSubjects) ? $additionalSubjects : [])));

        $rawExempted = $studentData['exemptedFees'] ?? $studentData['Exempted Fees'] ?? [];
        $exemptedFees = is_array($rawExempted) ? $rawExempted : [];

        // 2026-06-02: Firestore-only — read the canonical
        // studentDiscounts/{schoolId}_{userId} doc that Fees::submit_discount,
        // Fees::set_student_discount, and Fee_management::apply_discount all
        // write to. The legacy embedded students.{id}.Discount path was
        // verified to have zero active writers + zero readers + zero
        // production data across all tenants (audit 2026-05-29..2026-06-02)
        // and is no longer kept as a fallback per the Firestore-only
        // convergence policy. Field map: canonical onDemandDiscount -> view's
        // "Current Discount"; canonical totalDiscount -> view's "Total".
        // Legacy PascalCase 'OnDemandDiscount' is still tolerated for any
        // doc written by Fee_management::apply_discount before Fix #3
        // landed; with zero such docs in production it's a no-op today.
        $discountDoc = $this->fs->get('studentDiscounts', "{$this->fs->schoolId()}_{$userId}");
        $totalDiscount   = is_array($discountDoc) ? (float) ($discountDoc['totalDiscount'] ?? 0) : 0;
        $currentDiscount = is_array($discountDoc)
            ? (float) ($discountDoc['onDemandDiscount'] ?? $discountDoc['OnDemandDiscount'] ?? 0)
            : 0;

        $feesJson = $this->_getFees($class, $section);
        $feesData = json_decode($feesJson, true);

        $data = [
            'student' => $studentData, 'class' => $class, 'section' => $section,
            'fees' => $feesData['fees'] ?? null, 'monthlyTotals' => $feesData['monthlyTotals'] ?? null,
            'overallTotal' => $feesData['overallTotal'] ?? null, 'subjects' => $finalSubjectsList,
            'discount' => $totalDiscount, 'totaldiscount' => $totalDiscount,
            'currentdiscount' => $currentDiscount, 'exempted_fees' => $exemptedFees,
        ];

        $this->load->view('include/header');
        $this->load->view('student_profile', $data);
        $this->load->view('include/footer');
    }

    /**
     * GET /sis/readmission_ui — the SRNO re-admission confirm screen.
     *
     * Phase 9 (2026-08-13). Pure render — collects the two inputs
     * Readmission::confirm() already requires (new Student ID, previous
     * Student ID) plus an optional reason, and submits to that EXISTING,
     * unmodified endpoint. No business logic here or in the view: this
     * controller method does not call Sr_no_service/Apaar_service/relink
     * itself — Readmission::confirm() remains the sole authoritative path,
     * per the explicit instruction not to duplicate its logic.
     */
    public function readmission_ui()
    {
        $roles = ['Super Admin', 'School Super Admin', 'Principal'];
        $role = (string) ($this->admin_role ?? '');
        $allowed = false;
        foreach ($roles as $r) {
            if (strcasecmp($role, $r) === 0) { $allowed = true; break; }
        }
        if (!$allowed) {
            show_error('This page requires Principal-level authorization.', 403);
            return;
        }
        $this->load->view('include/header');
        $this->load->view('sis/readmission', []);
        $this->load->view('include/footer');
    }

    /**
     * POST /sis/apaar_reveal — audited full-value APAAR reveal.
     *
     * Phase 9 (2026-08-13). Per SR_NUMBER_AND_APAAR_ID_DESIGN.md §4.1 PII
     * treatment: full APAAR value is restricted to Admin/Principal/School
     * Super Admin; every reveal is audited (who, which student, when).
     * This endpoint is the ONLY server-side path that returns the plaintext
     * value to the browser — the profile page itself only ever renders the
     * masked form. Client-side role gating in the view is UX-only; this
     * check is authoritative.
     */
    public function apaar_reveal()
    {
        $revealRoles = ['Super Admin', 'School Super Admin', 'Admin', 'Principal'];
        $role = (string) ($this->admin_role ?? '');
        $allowed = false;
        foreach ($revealRoles as $r) {
            if (strcasecmp($role, $r) === 0) { $allowed = true; break; }
        }
        if (!$allowed) {
            log_message('warning', "Sis::apaar_reveal denied for role=[{$role}] admin={$this->admin_id}");
            return $this->json_error('This action requires Admin/Principal-level authorization.', 403);
        }
        if (strtolower((string) $this->input->method()) !== 'post') {
            return $this->json_error('POST required.');
        }

        $userId = trim((string) $this->input->post('user_id', TRUE));
        if ($userId === '' || !$this->safe_path_segment($userId)) {
            return $this->json_error('Invalid user_id.');
        }

        $student = $this->_getStudent($userId);
        if (!is_array($student) || empty($student)) {
            return $this->json_error('Student not found.', 404);
        }

        $apaarId = trim((string) ($student['apaarId'] ?? ''));
        if ($apaarId === '') {
            return $this->json_error('No APAAR ID on file for this student.', 404);
        }

        log_audit('SIS', 'apaar_revealed', $userId, "APAAR ID revealed by {$this->admin_id} ({$role})", [
            'action' => 'apaar-reveal',
        ]);

        return $this->json_success(['apaarId' => $apaarId]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       DOWNLOAD DOCUMENT — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function download_document()
    {
        $this->_require_role(self::VIEW_ROLES, 'download_document', 'SIS', 'view');
        $fileUrl = $this->input->get('file', TRUE);
        if (empty($fileUrl) || !filter_var($fileUrl, FILTER_VALIDATE_URL)) { show_error("Invalid file URL.", 400); return; }

        $parts = parse_url($fileUrl);
        if (empty($parts['scheme']) || empty($parts['host'])) { show_error("Malformed URL.", 400); return; }
        if ($parts['scheme'] !== 'https') { show_error("Only HTTPS allowed.", 403); return; }

        $allowedHosts = ['firebasestorage.googleapis.com', 'storage.googleapis.com'];
        if (!in_array($parts['host'], $allowedHosts, true)) { show_error("Access denied.", 403); return; }

        $ip = gethostbyname($parts['host']);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            show_error("Invalid host.", 403); return;
        }

        $fileName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($parts['path']));
        $ch = curl_init($fileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        curl_exec($ch);
        if (curl_errno($ch)) { curl_close($ch); show_error("Download failed.", 500); return; }
        curl_close($ch);
    }

    /* ══════════════════════════════════════════════════════════════════════
       ATTENDANCE — Merged from Student.php
    ══════════════════════════════════════════════════════════════════════ */

    public function attendance()
    {
        $this->_require_role(self::VIEW_ROLES, 'attendance', 'Attendance', 'view');
        $sectionDocs = $this->fs->schoolWhere('sections', []);
        $ClassesData = [];
        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'];
            $ClassesData[] = [
                'class_name' => $sd['className'] ?? '',
                'section'    => str_replace('Section ', '', $sd['section'] ?? ''),
            ];
        }
        $viewData['Classes'] = $ClassesData;
        $this->load->view('include/header');
        $this->load->view('attendance', $viewData);
        $this->load->view('include/footer');
    }

    public function fetchAttendance()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_attendance', 'Attendance', 'view');
        header('Content-Type: application/json');
        $school_name  = $this->school_name;
        $session_year = $this->session_year;
        $class   = $this->input->post('class');
        $section = $this->input->post('section');
        $month   = $this->input->post('month');
        if (empty($class) || empty($section) || empty($month)) {
            echo json_encode(["error" => "Class, Section and Month are required"]);
            return;
        }

        $monthToNumber = [
            'January'=>1,'February'=>2,'March'=>3,'April'=>4,'May'=>5,'June'=>6,
            'July'=>7,'August'=>8,'September'=>9,'October'=>10,'November'=>11,'December'=>12,
        ];
        $monthNumber = $monthToNumber[trim($month)] ?? 0;
        if ($monthNumber === 0) { echo json_encode(["error" => "Invalid month name."]); return; }

        $sessionParts = explode('-', $session_year);
        $startYear = (int)($sessionParts[0] ?? date('Y'));
        $endYear   = isset($sessionParts[1]) ? (int)$sessionParts[1] : $startYear + 1;
        $year = ($monthNumber >= 4) ? $startYear : $endYear;

        $class   = $this->safe_path_segment($class, 'class');
        $section = $this->safe_path_segment($section, 'section');

        // Get students from Firestore (use prefixed format for queries)
        $classKey = Firestore_service::classKey($class);
        $sectionKey = Firestore_service::sectionKey($section);
        $studentDocs = $this->fs->schoolWhere('students', [
            ['Class', '==', $classKey], ['Section', '==', $sectionKey], ['Status', '==', 'Active'],
        ], 'Name', 'ASC');

        if (empty($studentDocs)) {
            echo json_encode(["error" => "No students found for this class/section."]);
            return;
        }

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNumber, $year);
        $sundays     = $this->_getSundays($year, $monthNumber);
        $studentsData = [];
        foreach ($studentDocs as $doc) {
            $d = $doc['data'] ?? $doc;
            $s = $doc['data'];
            $studentId = $s['User Id'] ?? $s['studentId'] ?? $d['id'];
            $studentName = $s['Name'] ?? $s['name'] ?? $studentId;
            // Attendance from Firestore attendance collection
            $attDocId = $this->fs->docId2($studentId, date('Y-m', mktime(0, 0, 0, $monthNumber, 1, $year)));
            $attDoc = $this->fs->get('attendanceSummary', $attDocId);
            $attendanceString = $attDoc['dayWise'] ?? '';
            if (empty($attendanceString) || !is_string($attendanceString)) $attendanceString = str_repeat('V', $daysInMonth);
            $attendanceArray = array_pad(str_split($attendanceString), $daysInMonth, 'V');
            $displayName = is_string($studentName) ? $studentName : ($studentName['Name'] ?? (string)$studentId);
            $studentsData[] = ["userId" => $studentId, "name" => $displayName, "attendance" => $attendanceArray];
        }
        echo json_encode(["students" => $studentsData, "daysInMonth" => $daysInMonth, "sundays" => $sundays, "month" => $month, "year" => $year]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       CRM Firestore helpers — all CRM entities moved to Firestore:
         crmApplications, crmInquiries, crmWaitlist, crmSettings
       Replaces Schools/{school}/CRM/Admissions/* RTDB paths.
    ══════════════════════════════════════════════════════════════════════ */

    /** List all docs in a CRM collection, keyed by entity ID. */
    private function _crm_list(string $collection, array $conditions = []): array
    {
        // SCALE (closure item I): bound the scan to the CURRENT session instead of
        // reading every document across all historical sessions. Every _crm_list
        // caller (analytics / dashboard / inquiries / waitlist) already discards
        // non-current-session rows in PHP, so this is behaviour-identical while
        // preventing unbounded all-history accumulation. schoolId + session are two
        // equality filters (zig-zag merge) — no composite index required.
        try {
            // Push equality filters (e.g. session==) to Firestore instead of
            // fetching the whole collection and filtering in PHP (F5). Two
            // equality conditions (schoolId== injected by schoolWhere + the
            // caller's session==) are served from single-field indexes — no
            // composite index required.
            $docs = $this->fs->schoolWhere($collection, $conditions);
        } catch (\Exception $e) {
            log_message('error', "CRM list {$collection} failed: " . $e->getMessage());
            return [];
        }
        if (!is_array($docs)) return [];
        $result = [];
        $prefix = $this->school_id . '_';
        foreach ($docs as $d) {
            $d = $d['data'] ?? $d;
            $r = is_array($d['data'] ?? null) ? $d['data'] : $d;
            $rawId = (string) ($d['id'] ?? '');
            $id = (strpos($rawId, $prefix) === 0) ? substr($rawId, strlen($prefix)) : $rawId;
            if ($id !== '') $result[$id] = $r;
        }
        return $result;
    }

    /**
     * C7 — server-side, index-backed, cursor-paginated CRM query. Replaces the
     * unbounded _crm_list() full-scan for the Applications / Leads grids. School
     * + session isolation is enforced INSIDE Firestore_service::cursorQuery().
     *
     * Search picks exactly ONE indexed strategy so every path is backed by a
     * deployed index and FAILS LOUD on a missing index instead of silently
     * full-scanning: exact application-ID (doc get), exact phone (equality query,
     * zig-zag merge — no composite index), or name token (searchTokens
     * array-contains, composite index #5). During an active search, the
     * status/class/source filters are intentionally NOT applied — no deployed
     * index combines them with searchTokens, and adding one is out of C7 scope.
     *
     * @param array      $filters ['status'|'class'|'source'|'stage' => value]
     * @param string     $search  raw search text (name | appID | phone)
     * @param int        $limit   page size (clamped 1..100)
     * @param array|null $cursor  opaque ['created_at','id'] from a prior page
     * @return array ['items'=>[{...,id}], 'nextCursor'=>?, 'hasMore'=>bool, 'mode'=>string, 'error'=>?]
     */
    private function _crm_page(array $filters, string $search, int $limit, ?array $cursor): array
    {
        $this->load->helper('admission_search');
        $limit  = max(1, min(100, (int) $limit));
        $search = trim($search);

        // Each allowed equality-filter combo maps to exactly one deployed index
        // (#1-#4, #6-#9). Any other combo is rejected — never silently degraded
        // to a full scan.
        static $ALLOWED_COMBOS = ['', 'status', 'class', 'class|status', 'source', 'source|status', 'stage', 'class|stage'];

        $prefix = $this->school_id . '_';
        $strip = function (array $items) use ($prefix): array {
            $out = [];
            foreach ($items as $it) {
                $r = is_array($it['data'] ?? null) ? $it['data'] : $it;
                $rawId = (string) ($it['id'] ?? '');
                $r['id'] = (strpos($rawId, $prefix) === 0) ? substr($rawId, strlen($prefix)) : $rawId;
                $out[] = $r;
            }
            return $out;
        };

        // ── SEARCH MODES (mutually exclusive; browse filters ignored) ──
        if ($search !== '') {
            // (a) Exact application-ID — IDs start with "APP".
            if (preg_match('/^APP[_A-Za-z0-9]+$/i', $search)) {
                $doc   = $this->_crm_get('crmApplications', $search);
                $items = is_array($doc) ? [['id' => $prefix . $search, 'data' => $doc]] : [];
                return ['items' => $strip($items), 'nextCursor' => null, 'hasMore' => false, 'mode' => 'appid'];
            }
            // (b) Exact phone — predominantly digits, >=6 after normalization.
            $digits = preg_replace('/[^0-9]/', '', $search);
            $bare   = preg_replace('/\s/', '', $search);
            if (strlen($digits) >= 6 && strlen($digits) >= (int) floor(strlen($bare) * 0.6)) {
                $rows = $this->fs->where('crmApplications', [
                    ['schoolId', '==', $this->school_id],
                    ['session',  '==', $this->session_year],
                    ['phone_norm', '==', $digits],
                ], null, 'ASC', $limit);
                $rows = is_array($rows) ? $rows : [];
                usort($rows, fn($a, $b) => strcmp(($b['data']['created_at'] ?? ''), ($a['data']['created_at'] ?? '')));
                return ['items' => $strip($rows), 'nextCursor' => null, 'hasMore' => false, 'mode' => 'phone'];
            }
            // (c) Name token — searchTokens array-contains (composite index #5).
            $token = admission_search_query_token($search);
            if ($token === '') {
                return ['items' => [], 'nextCursor' => null, 'hasMore' => false, 'mode' => 'name'];
            }
            try {
                $res = $this->fs->cursorQuery('crmApplications', [['searchTokens', 'array-contains', $token]], $limit, $cursor);
            } catch (\Exception $e) {
                log_message('error', 'CRM name-search failed: ' . $e->getMessage());
                return ['items' => [], 'nextCursor' => null, 'hasMore' => false, 'mode' => 'name', 'error' => 'search_failed'];
            }
            return ['items' => $strip($res['items']), 'nextCursor' => $res['nextCursor'], 'hasMore' => $res['hasMore'], 'mode' => 'name'];
        }

        // ── BROWSE (no search) — indexed equality filters + cursor ──
        $conds = [];
        $keys  = [];
        foreach (['status', 'class', 'source', 'stage'] as $f) {
            $raw = $filters[$f] ?? '';
            if (is_array($raw)) {
                // Disjunctive filter (e.g. leads "New" bucket -> [new, pending]).
                // Uses the SAME composite index as the equality filter.
                $vals = array_values(array_filter($raw, fn($x) => trim((string) $x) !== ''));
                if (!empty($vals)) { $conds[] = [$f, 'in', $vals]; $keys[] = $f; }
            } else {
                $v = trim((string) $raw);
                if ($v !== '') { $conds[] = [$f, '==', $v]; $keys[] = $f; }
            }
        }
        sort($keys);
        if (!in_array(implode('|', $keys), $ALLOWED_COMBOS, true)) {
            return ['items' => [], 'nextCursor' => null, 'hasMore' => false, 'mode' => 'browse', 'error' => 'unsupported_filter'];
        }
        try {
            $res = $this->fs->cursorQuery('crmApplications', $conds, $limit, $cursor);
        } catch (\Exception $e) {
            log_message('error', 'CRM browse query failed: ' . $e->getMessage());
            return ['items' => [], 'nextCursor' => null, 'hasMore' => false, 'mode' => 'browse', 'error' => 'query_failed'];
        }
        return ['items' => $strip($res['items']), 'nextCursor' => $res['nextCursor'], 'hasMore' => $res['hasMore'], 'mode' => 'browse'];
    }

    /** Reconstruct the opaque pagination cursor from request input (null = page 1). */
    private function _crm_cursor_from_input(): ?array
    {
        $ca = trim((string) $this->input->get_post('cursor_created_at'));
        $id = trim((string) $this->input->get_post('cursor_id'));
        if ($ca === '' || $id === '') return null;
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $id)) return null; // opaque doc id
        return ['created_at' => $ca, 'id' => $id];
    }

    /** Get a single CRM doc. */
    private function _crm_get(string $collection, string $id): ?array
    {
        try {
            $d = $this->fs->getEntity($collection, $id);
            return (is_array($d) && !empty($d)) ? $d : null;
        } catch (\Exception $e) {
            log_message('error', "CRM get {$collection}/{$id} failed: " . $e->getMessage());
            return null;
        }
    }

    /** Write a CRM doc (create or overwrite). */
    private function _crm_set(string $collection, string $id, array $data): void
    {
        try { $this->fs->setEntity($collection, $id, $data); }
        catch (\Exception $e) { log_message('error', "CRM set {$collection}/{$id} failed: " . $e->getMessage()); }
    }

    /** Merge-update fields on a CRM doc. */
    private function _crm_update(string $collection, string $id, array $data): void
    {
        try { $this->fs->updateEntity($collection, $id, $data); }
        catch (\Exception $e) { log_message('error', "CRM update {$collection}/{$id} failed: " . $e->getMessage()); }
    }

    /** Delete a CRM doc. */
    private function _crm_delete(string $collection, string $id): void
    {
        try { $this->fs->removeEntity($collection, $id); }
        catch (\Exception $e) { log_message('error', "CRM delete {$collection}/{$id} failed: " . $e->getMessage()); }
    }

    /** CRM counter — allocates sequential IDs (INQ0001, APP0001, WL0001). */
    private function _crm_next_id(string $type, string $prefix, int $pad = 4): string
    {
        // Atomic claim-doc allocator (the same primitive TC uses). Replaces the
        // old racy read-increment-write that could hand two concurrent
        // submit_online_form / save_application calls the SAME id and then
        // silently overwrite one lead. Seed from any legacy crmCounters value so
        // a switchover never re-issues an existing id.
        $seed = 0;
        try {
            $doc = $this->fs->get('schools', $this->fs->docId('profile'));
            $flatKey = "crmCounters.{$type}";
            if (is_array($doc) && isset($doc[$flatKey]) && is_numeric($doc[$flatKey])) {
                $seed = (int) $doc[$flatKey];
            }
        } catch (\Throwable $e) { /* non-fatal — allocator handles base itself */ }

        $next = $this->fs->nextSchoolCounter('crm_' . $type, $seed);
        if ($next <= 0) {
            // Fail closed (F11): the old `$seed + 1` fallback handed two
            // concurrent callers the SAME id during a Firestore outage,
            // silently overwriting one admission record. A duplicate/clobbered
            // record is worse than a retry, so refuse to allocate rather than
            // risk a collision. This fires only when the allocator is down and
            // is thrown BEFORE any caller write, so it leaves no partial state.
            log_message('error', "CRM atomic counter unavailable for {$type}; refusing racy fallback id (fail-closed)");
            throw new \RuntimeException('CRM ID allocator is temporarily unavailable — please retry.');
        }
        return $prefix . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
    }

    /** CRM settings — single doc per school. */
    private function _crm_get_settings(): array
    {
        return $this->_crm_get('crmSettings', 'config') ?? [];
    }
    private function _crm_save_settings(array $data): void
    {
        $this->_crm_set('crmSettings', 'config', $data);
    }

    /* ══════════════════════════════════════════════════════════════════════
       LEAD SYSTEM — Public admission leads management
       View, filter, and convert public form leads into student admissions
    ══════════════════════════════════════════════════════════════════════ */

    // LEAD SYSTEM — List all public admission leads
    public function admission_leads()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $data['session_year'] = $this->session_year;
        $data['school_name']  = $this->school_name;
        $this->load->view('include/header');
        $this->load->view('sis/admission_leads', $data);
        $this->load->view('include/footer');
    }

    // LEAD SYSTEM — AJAX: fetch leads data
    public function fetch_leads()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        // C7 — server-side paginated + filtered + searched (was: full-scan +
        // client-side filter). The Leads grid's status dropdown uses NORMALIZED
        // buckets; expand each to the raw statuses it folds in (legacy spellings)
        // so an `in` filter surfaces them all — parity with the old client-side
        // normalizeStatus().
        $statusFilter = $this->_crm_lead_status_bucket((string) $this->input->get_post('status'));
        $page = $this->_crm_page(
            ['status' => $statusFilter, 'source' => $this->input->get_post('source')],
            (string) $this->input->get_post('search'),
            (int) ($this->input->get_post('limit') ?: 25),
            $this->_crm_cursor_from_input()
        );
        return $this->json_success([
            'leads'      => $page['items'],
            'nextCursor' => $page['nextCursor'],
            'hasMore'    => $page['hasMore'],
            'mode'       => $page['mode'],
            'error'      => $page['error'] ?? null,
        ]);
    }

    /** Expand a normalized Leads status bucket to the raw statuses it folds in. */
    private function _crm_lead_status_bucket(string $bucket)
    {
        $bucket = trim($bucket);
        if ($bucket === '') return '';
        static $MAP = [
            'new'       => ['new', 'pending'],
            'contacted' => ['contacted', 'interested'],
            'approved'  => ['approved'],
            'enrolled'  => ['enrolled', 'admitted'],
            'rejected'  => ['rejected'],
        ];
        return $MAP[$bucket] ?? $bucket; // unknown -> exact match
    }

    /**
     * C7 — Leads funnel counts via server-side COUNT aggregation (no document
     * reads, no full scan). Replaces the old client-side tally over the full
     * dataset. Reuses Firestore_service::count (runAggregationQuery); legacy
     * status spellings are folded to match the grid's normalized buckets.
     */
    public function fetch_leads_stats()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $base = [['schoolId', '==', $this->school_id], ['session', '==', $this->session_year]];
        $cnt  = fn($status) => $this->fs->count('crmApplications', array_merge($base, [['status', '==', $status]]));
        $stats = [
            'total'     => $this->fs->count('crmApplications', $base),
            'new'       => $cnt('new') + $cnt('pending'),
            'contacted' => $cnt('contacted') + $cnt('interested'),
            'approved'  => $cnt('approved'),
            'enrolled'  => $cnt('enrolled') + $cnt('admitted'),
            'rejected'  => $cnt('rejected'),
        ];
        return $this->json_success(['stats' => $stats]);
    }

    // LEAD SYSTEM — Single lead detail (AJAX)
    public function admission_lead()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $leadId = trim($this->input->get_post('lead_id') ?? '');
        if ($leadId === '') return $this->json_error('Lead ID required.');
        $leadId = $this->safe_path_segment($leadId, 'lead_id');

        $lead = $this->_crm_get('crmApplications', $leadId);
        if (!is_array($lead)) return $this->json_error('Lead not found.');
        $lead['id'] = $leadId;
        return $this->json_success(['lead' => $lead]);
    }

    // LEAD SYSTEM — Update lead status (AJAX)
    public function update_lead_status()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_manage', 'SIS', 'manage');
        $leadId = trim($this->input->post('lead_id') ?? '');
        $status = trim($this->input->post('status') ?? '');
        if ($leadId === '' || $status === '') return $this->json_error('Lead ID and status required.');
        $leadId = $this->safe_path_segment($leadId, 'lead_id');

        // MED-16: 'enrolled'/'admitted' are TERMINAL and must not be set by a
        // direct status write — enrollment creates a real student + Auth + fees
        // and only happens via enroll_student(). Writing status=enrolled here
        // produced a phantom "enrolled" lead with no backing student record.
        $allowed = ['new', 'contacted', 'interested', 'approved', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            return $this->json_error($status === 'enrolled' || $status === 'admitted'
                ? 'Use the Enroll action to enroll a student — status can’t be set to enrolled directly.'
                : 'Invalid status.');
        }

        $lead = $this->_crm_get('crmApplications', $leadId);
        if (!is_array($lead)) return $this->json_error('Lead not found.');

        $now = date('Y-m-d H:i:s');
        // M7-B: single CAS-atomic write — business fields (status) + bounded history
        // in one commit; concurrency-safe (no lost update); one Firestore write (M3).
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $leadId, 'history',
            ['action' => "Status changed to {$status}", 'by' => $this->admin_name, 'timestamp' => $now],
            crm_history_cap(),
            ['status' => $status, 'updated_at' => $now]);
        log_audit('CRM', 'update_lead_status', $leadId, "Lead status changed to '{$status}' for " . ($lead['student_name'] ?? ''),
            ['appId' => $leadId, 'entityType' => 'application', 'meta' => ['status' => $status]]);
        return $this->json_success(['message' => 'Status updated.']);
    }

    // LEAD SYSTEM — Fetch lead data for admission form prefill
    public function get_lead_data()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_admission', 'SIS', 'manage');
        $leadId = trim($this->input->get('lead_id') ?? '');
        if ($leadId === '') return $this->json_error('Lead ID required.');
        $leadId = $this->safe_path_segment($leadId, 'lead_id');

        $lead = $this->_crm_get('crmApplications', $leadId);
        if (!is_array($lead)) return $this->json_error('Lead not found.');
        $lead['id'] = $leadId;
        return $this->json_success(['lead' => $lead]);
    }

    // LEAD SYSTEM — Admission analytics dashboard (single Firebase read, all PHP computation)
    public function admission_analytics()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $session = $this->session_year;

        // Single read, session-scoped at Firestore (F5) — analytics is per-session.
        $applications = $this->_crm_list('crmApplications', [['session', '==', $session]]);
        if (!is_array($applications)) $applications = [];

        // Filter to current session + compute all metrics in one pass
        $total = 0;
        $byStatus = ['new'=>0,'contacted'=>0,'interested'=>0,'approved'=>0,'admitted'=>0,'enrolled'=>0,'rejected'=>0];
        $byClass  = [];
        $bySource = ['public_form'=>0,'manual'=>0,'crm'=>0];
        $byMonth  = [];  // month label → count
        $recentLeads = [];

        foreach ($applications as $id => $app) {
            if (!is_array($app)) continue;
            if (($app['session'] ?? '') !== $session) continue;

            $total++;
            $status = strtolower($app['status'] ?? 'new');
            if (isset($byStatus[$status])) $byStatus[$status]++;
            else $byStatus[$status] = 1;

            $cls = $app['class'] ?? 'Unknown';
            $byClass[$cls] = ($byClass[$cls] ?? 0) + 1;

            $src = $app['source'] ?? 'crm';
            if ($src === 'public_form') $bySource['public_form']++;
            else $bySource['crm']++;

            // Monthly trend from created_at
            $created = $app['created_at'] ?? '';
            if ($created !== '') {
                $ts = strtotime($created);
                if ($ts) {
                    $monthKey = date('Y-m', $ts);
                    $byMonth[$monthKey] = ($byMonth[$monthKey] ?? 0) + 1;
                }
            }

            // Collect recent 10 for quick-view table
            if (count($recentLeads) < 10) {
                $recentLeads[] = [
                    'id'     => $id,
                    'name'   => $app['student_name'] ?? '',
                    'class'  => $cls,
                    'status' => $status,
                    'source' => $src,
                    'date'   => substr($created, 0, 10),
                ];
            }
        }

        // Sort class keys naturally
        uksort($byClass, 'strnatcmp');
        ksort($byMonth);

        $admitted = ($byStatus['admitted'] ?? 0) + ($byStatus['enrolled'] ?? 0);
        $conversionRate = $total > 0 ? round(($admitted / $total) * 100, 1) : 0;

        $data = [
            'session_year'    => $session,
            'total'           => $total,
            'admitted'        => $admitted,
            'conversion_rate' => $conversionRate,
            'by_status'       => $byStatus,
            'by_class'        => $byClass,
            'by_source'       => $bySource,
            'by_month'        => $byMonth,
            'recent_leads'    => $recentLeads,
        ];

        $this->load->view('include/header');
        $this->load->view('sis/admission_analytics', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       ADMISSION CRM — All methods merged from Admission_crm.php
       Manages: Inquiry → Application → Pipeline → Approval → Waitlist → Enrollment
    ══════════════════════════════════════════════════════════════════════ */

    public function crm_dashboard()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $session = $this->session_year;

        // Session-scoped reads (F5): the dashboard only reports the active
        // session, so filter at Firestore instead of fetching every all-time
        // doc and discarding most in PHP (was 3 full-collection scans/load).
        $sessionCond  = [['session', '==', $session]];
        $inquiries    = $this->_crm_list('crmInquiries', $sessionCond);
        $applications = $this->_crm_list('crmApplications', $sessionCond);
        $waitlist     = $this->_crm_list('crmWaitlist', $sessionCond);
        $settings     = $this->_crm_get_settings();
        if (!is_array($inquiries))    $inquiries = [];
        if (!is_array($applications)) $applications = [];
        if (!is_array($waitlist))     $waitlist = [];
        if (!is_array($settings))     $settings = [];

        $sessionInquiries = array_filter($inquiries, fn($i) => is_array($i) && ($i['session'] ?? '') === $session);
        $sessionApps = array_filter($applications, fn($a) => is_array($a) && ($a['session'] ?? '') === $session);
        $sessionWaitlist = array_filter($waitlist, fn($w) => is_array($w) && ($w['session'] ?? '') === $session);

        $stats = ['total_inquiries' => count($sessionInquiries), 'total_applications' => count($sessionApps),
            'total_waitlist' => count($sessionWaitlist), 'pending_approval' => 0, 'approved' => 0, 'rejected' => 0, 'enrolled' => 0];
        foreach ($sessionApps as $app) {
            $status = $app['status'] ?? 'pending';
            if (isset($stats[$status])) $stats[$status]++;
            elseif ($status === 'pending') $stats['pending_approval']++;
        }

        $classBreakdown = [];
        foreach ($sessionApps as $app) {
            $cls = $app['class'] ?? 'Unknown';
            if (!isset($classBreakdown[$cls])) $classBreakdown[$cls] = ['applied'=>0,'approved'=>0,'enrolled'=>0,'waitlisted'=>0];
            $classBreakdown[$cls]['applied']++;
            $st = $app['status'] ?? '';
            if (isset($classBreakdown[$cls][$st])) $classBreakdown[$cls][$st]++;
        }
        foreach ($sessionWaitlist as $w) {
            $cls = $w['class'] ?? 'Unknown';
            if (!isset($classBreakdown[$cls])) $classBreakdown[$cls] = ['applied'=>0,'approved'=>0,'enrolled'=>0,'waitlisted'=>0];
            $classBreakdown[$cls]['waitlisted']++;
        }
        ksort($classBreakdown);

        $sourceBreakdown = [];
        foreach ($sessionInquiries as $inq) { $src = $inq['source'] ?? 'Walk-in'; $sourceBreakdown[$src] = ($sourceBreakdown[$src] ?? 0) + 1; }

        $monthlyTrend = [];
        foreach ($sessionInquiries as $inq) { $dt = $inq['created_at'] ?? ''; if ($dt) { $m = substr($dt,0,7); $monthlyTrend[$m] = ($monthlyTrend[$m] ?? 0)+1; } }
        ksort($monthlyTrend);
        $monthlyTrend = array_slice($monthlyTrend, -6, 6, true);

        // ── Overview redesign (2026-07): "In review" funnel count + the
        // "Needs attention" (aging / unpaid) and "Recent activity" feeds the
        // hub renders. Computed from the apps already loaded above. ──
        $inReview = 0; $attention = []; $activity = [];
        $nowTs = time();
        $reviewStages = ['document_collection','under_review','docs','interview','assessment'];
        foreach ($sessionApps as $app) {
            $stage  = (string) ($app['stage'] ?? '');
            $status = (string) ($app['status'] ?? 'pending');
            if (in_array($stage, $reviewStages, true)) $inReview++;
            $dt   = (string) ($app['updated_at'] ?? $app['created_at'] ?? '');
            $days = $dt !== '' ? (int) floor(($nowTs - strtotime($dt)) / 86400) : null;
            $unpaid = !empty($app['payment_status']) && $app['payment_status'] !== 'paid';
            if ($status !== 'enrolled' && $status !== 'rejected' && (($days !== null && $days > 7) || $unpaid)) {
                $attention[] = ['name'=>(string)($app['student_name']??''), 'class'=>(string)($app['class']??''),
                    'stage'=>$stage, 'days'=>$days, 'unpaid'=>$unpaid];
            }
            foreach ((is_array($app['history'] ?? null) ? $app['history'] : []) as $h) {
                $activity[] = ['name'=>(string)($app['student_name']??''), 'action'=>(string)($h['action']??''),
                    'ts'=>(string)($h['timestamp']??''), 'by'=>(string)($h['by']??'')];
            }
        }
        usort($attention, fn($a,$b) => ($b['days'] ?? 0) <=> ($a['days'] ?? 0));
        $attention = array_slice($attention, 0, 6);
        usort($activity, fn($a,$b) => strcmp($b['ts'] ?? '', $a['ts'] ?? ''));
        $activity = array_slice($activity, 0, 6);
        $stats['in_review'] = $inReview;

        $data = compact('stats') + ['class_breakdown'=>$classBreakdown,'source_breakdown'=>$sourceBreakdown,'monthly_trend'=>$monthlyTrend,'settings'=>$settings,'session_year'=>$session,'attention'=>$attention,'activity'=>$activity];
        $this->load->view('include/header');
        $this->load->view('admission_crm/index', $data);
        $this->load->view('include/footer');
    }

    public function inquiries()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_crm_classes();
        $this->load->view('include/header');
        $this->load->view('admission_crm/inquiries', $data);
        $this->load->view('include/footer');
    }

    public function fetch_inquiries()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $session = $this->session_year;
        // Session-scoped read (F5): inquiries screen shows only the active session.
        $inquiries = $this->_crm_list('crmInquiries', [['session', '==', $session]]);
        if (!is_array($inquiries)) $inquiries = [];
        $result = [];
        foreach ($inquiries as $id => $inq) {
            if (!is_array($inq) || ($inq['session'] ?? '') !== $session) continue;
            $inq['id'] = $id;
            $result[] = $inq;
        }
        usort($result, fn($a,$b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return $this->json_success(['inquiries' => $result]);
    }

    public function save_inquiry()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_inquiry', 'SIS', 'edit');
        $id = trim($this->input->post('id') ?? '');
        if ($id !== '') $id = $this->safe_path_segment($id, 'id');
        $student_name   = trim($this->input->post('student_name') ?? '');
        $parent_name    = trim($this->input->post('parent_name') ?? '');
        $phone          = trim($this->input->post('phone') ?? '');
        $email          = trim($this->input->post('email') ?? '');
        $class          = trim($this->input->post('class') ?? '');
        $source         = trim($this->input->post('source') ?? 'Walk-in');
        $notes          = trim($this->input->post('notes') ?? '');
        $status         = trim($this->input->post('status') ?? 'new');
        $follow_up_date = trim($this->input->post('follow_up_date') ?? '');

        if ($student_name === '' || $parent_name === '' || $phone === '') return $this->json_error('Student name, parent name, and phone are required');
        if (!preg_match('/^\+?\d{10,15}$/', preg_replace('/[\s\-]/', '', $phone))) return $this->json_error('Invalid phone number format');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->json_error('Invalid email address');

        $now = date('Y-m-d H:i:s');
        if ($id) {
            $existing = $this->_crm_get('crmInquiries', $id);
            if (!is_array($existing)) return $this->json_error('Inquiry not found');
            $data = array_merge($existing, compact('student_name','parent_name','phone','email','class','source','notes','status','follow_up_date') + ['updated_at'=>$now]);
            $this->_crm_set("crmInquiries", $id, $data);
            // M3: _crm_set is the single canonical Firestore write (removed redundant dual-write).
            // M7-A: permanent audit (canonical auditLogs).
            log_audit('CRM', 'update_inquiry', $id, "Updated inquiry for {$student_name}",
                ['entityType' => 'inquiry', 'meta' => ['status' => $status]]);
        } else {
            $id = $this->_crm_next_id('Inquiry', 'INQ', 5);
            $data = compact('student_name','parent_name','phone','email','class','source','notes','status','follow_up_date') + [
                'inquiry_id'=>$id, 'session'=>$this->session_year, 'created_at'=>$now, 'updated_at'=>$now, 'created_by'=>$this->admin_name,
            ];
            $this->_crm_set("crmInquiries", $id, $data);
            // Counter managed by _crm_next_id — no separate write needed.
            // M3: _crm_set is the single canonical Firestore write (removed redundant dual-write).
            // M7-A: permanent audit (canonical auditLogs).
            log_audit('CRM', 'create_inquiry', $id, "Created inquiry for {$student_name}",
                ['entityType' => 'inquiry', 'meta' => ['source' => $source]]);
        }
        return $this->json_success(['id' => $id, 'message' => 'Inquiry saved.']);
    }

    public function delete_inquiry()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_delete_inquiry', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Inquiry ID required');
        $safeId = $this->safe_path_segment($id, 'id');
        $this->_crm_delete('crmInquiries', $safeId);
        // M3: _crm_delete is the single canonical Firestore delete (removed redundant dual-write).
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'delete_inquiry', $safeId, "Deleted inquiry {$safeId}", ['entityType' => 'inquiry']);
        return $this->json_success();
    }

    public function convert_to_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_convert', 'SIS', 'manage');
        $inquiry_id = trim($this->input->post('inquiry_id') ?? '');
        if (!$inquiry_id) return $this->json_error('Inquiry ID required');
        $inquiry_id = $this->safe_path_segment($inquiry_id, 'inquiry_id');
        $inquiry = $this->_crm_get('crmInquiries', $inquiry_id);
        if (!is_array($inquiry)) return $this->json_error('Inquiry not found');
        // Idempotency (F6): a double-click / concurrent convert would mint a
        // second application and orphan the first (inquiry.application_id gets
        // overwritten). Reject if this inquiry was already converted.
        if (($inquiry['status'] ?? '') === 'converted' || !empty($inquiry['application_id'])) {
            return $this->json_error('This inquiry has already been converted to an application.');
        }

        $app_id = $this->_crm_next_id('Application', 'APP', 5);
        $now = date('Y-m-d H:i:s');
        $application = [
            'application_id'=>$app_id, 'inquiry_id'=>$inquiry_id,
            'student_name'=>$inquiry['student_name']??'', 'parent_name'=>$inquiry['parent_name']??'',
            'phone'=>$inquiry['phone']??'', 'email'=>$inquiry['email']??'', 'class'=>$inquiry['class']??'',
            'session'=>$inquiry['session']??$this->session_year, 'status'=>'pending', 'stage'=>'document_collection',
            'created_at'=>$now, 'updated_at'=>$now, 'created_by'=>$this->admin_name,
            'source'=>'admin', 'possible_duplicate'=>false,
            'source_inquiry'=>$inquiry_id, 'dob'=>'', 'gender'=>'', 'address'=>'',
            'father_name'=>$inquiry['parent_name']??'', 'mother_name'=>'', 'documents'=>[], 'notes'=>$inquiry['notes']??'',
            'history'=>[['action'=>'Application created from inquiry '.$inquiry_id, 'by'=>$this->admin_name, 'timestamp'=>$now]],
        ];
        $this->load->helper('admission_search');
        $application['searchTokens'] = admission_search_tokens($application['student_name'] ?? '');
        $this->_crm_set("crmApplications", $app_id, $application);
        // Counter managed by _crm_next_id — no separate write needed.
        $this->_crm_update("crmInquiries", $inquiry_id, ['status'=>'converted','application_id'=>$app_id,'updated_at'=>$now]);
        // M3: _crm_set/_crm_update above are the single canonical Firestore writes
        // (removed redundant dual-write of identical application + inquiry data).
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'convert_inquiry', $app_id, "Converted inquiry {$inquiry_id} to application for " . ($application['student_name'] ?? ''),
            ['appId' => $app_id, 'entityType' => 'application', 'meta' => ['from_inquiry' => $inquiry_id]]);
        return $this->json_success(['application_id' => $app_id]);
    }

    public function applications()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        // IA reorg (Phase 3): standalone → the tabbed Applications shell hosting
        // Table / Board / Funnel in isolated iframes. When loaded inside that
        // shell (?embed=1) → render the Table view itself, chrome-less. Same
        // proven pattern as the Attendance tab-shell; no JS merge, no collisions.
        if ($this->input->get('embed') === '1') {
            $data['session_year'] = $this->session_year;
            $data['classes']      = $this->_get_crm_classes();
            $this->load->view('include/header');
            $this->load->view('admission_crm/applications', $data);
            $this->load->view('include/footer');
            return;
        }
        $this->load->view('include/header');
        $this->load->view('admission_crm/applications_shell');
        $this->load->view('include/footer');
    }

    public function fetch_applications()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        // C7 — server-side paginated + filtered + searched (was: full-scan +
        // client-side filter). session/school isolation enforced in _crm_page.
        $page = $this->_crm_page(
            ['status' => $this->input->get_post('status'), 'class' => $this->input->get_post('class')],
            (string) $this->input->get_post('search'),
            (int) ($this->input->get_post('limit') ?: 25),
            $this->_crm_cursor_from_input()
        );
        return $this->json_success([
            'applications' => $page['items'],
            'nextCursor'   => $page['nextCursor'],
            'hasMore'      => $page['hasMore'],
            'mode'         => $page['mode'],
            'error'        => $page['error'] ?? null,
        ]);
    }

    public function save_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_app', 'SIS', 'edit');
        $id = trim($this->input->post('id') ?? '');
        if ($id !== '') $id = $this->safe_path_segment($id, 'id');
        $now = date('Y-m-d H:i:s');
        $fields = ['student_name','parent_name','father_name','mother_name','phone','email','class','section','dob','gender',
            'address','city','state','pincode','previous_school','previous_class','previous_marks',
            'blood_group','category','religion','nationality','father_occupation','mother_occupation',
            'guardian_name','guardian_phone','guardian_relation','notes'];
        $data = [];
        foreach ($fields as $f) $data[$f] = trim($this->input->post($f) ?? '');
        if ($data['student_name'] === '' || $data['class'] === '') return $this->json_error('Student name and class are required');
        if ($data['phone'] !== '' && !preg_match('/^\+?\d{10,15}$/', preg_replace('/[\s\-]/','',$data['phone']))) return $this->json_error('Invalid phone number format');
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) return $this->json_error('Invalid email address');

        // C7 — regenerate searchTokens from the (possibly edited) student name.
        // Applies to BOTH the update and create branches below; array_merge in the
        // create branch preserves this key.
        $this->load->helper('admission_search');
        $data['searchTokens'] = admission_search_tokens($data['student_name']);

        if ($id) {
            $existing = $this->_crm_get('crmApplications', $id);
            if (!is_array($existing)) return $this->json_error('Application not found');
            $data['updated_at'] = $now;
            // M7-B: single CAS-atomic write — the full edit payload (incl.
            // searchTokens) + bounded history in ONE commit; concurrency-safe; one
            // write (M3). schoolId/session preserved by the merge/patch.
            $this->load->helper('admission_crm');
            $this->fs->casAppendBounded('crmApplications', $id, 'history',
                ['action'=>'Application updated','by'=>$this->admin_name,'timestamp'=>$now],
                crm_history_cap(),
                $data);
            // M7-A: permanent audit (canonical auditLogs).
            log_audit('CRM', 'edit_application', $id, "Edited application for " . ($data['student_name'] ?? ''),
                ['appId' => $id, 'entityType' => 'application']);
            return $this->json_success(['id' => $id]);
        } else {
            $app_id = $this->_crm_next_id('Application', 'APP', 5);
            // `source: admin` distinguishes admin-created applications
            // from public-form ones in the CRM grid; admin-created apps
            // never get the soft-duplicate flag.
            $data = array_merge($data, ['application_id'=>$app_id,'session'=>$this->session_year,'status'=>'pending','stage'=>'document_collection',
                'source'=>'admin','possible_duplicate'=>false,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$this->admin_name,'documents'=>[],
                'history'=>[['action'=>'Application created directly','by'=>$this->admin_name,'timestamp'=>$now]]]);
            $this->_crm_set("crmApplications", $app_id, $data);
            // Counter managed by _crm_next_id — no separate write needed.
            // M3: _crm_set is the single canonical Firestore write (removed redundant dual-write).
            // M7-A: permanent audit (canonical auditLogs).
            log_audit('CRM', 'create_application', $app_id, "Created application for " . ($data['student_name'] ?? '') . " (class " . ($data['class'] ?? '') . ")",
                ['appId' => $app_id, 'entityType' => 'application', 'meta' => ['source' => 'admin']]);
            return $this->json_success(['id' => $app_id]);
        }
    }

    public function get_application()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $id = trim($this->input->get('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found');
        $app['id'] = $id;
        return $this->json_success(['application' => $app]);
    }

    /**
     * M7-B — Full permanent audit trail for one application, for the "View Full
     * Audit History" affordance. The crmApplications.history[]/timeline[] arrays
     * are bounded recent caches; this reads the COMPLETE trail from the canonical
     * auditLogs + auditArchive (M7-A) via the shared reader. Role-guarded +
     * tenant-scoped (audit_trail_for_app is schoolId-scoped internally).
     */
    public function admission_audit_trail()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $appId = trim($this->input->get_post('app_id') ?? '');
        if ($appId === '') return $this->json_error('Application ID required');
        $appId = $this->safe_path_segment($appId, 'app_id');
        $trail = function_exists('audit_trail_for_app') ? audit_trail_for_app($appId, 500) : [];
        return $this->json_success(['trail' => $trail, 'appId' => $appId]);
    }

    /**
     * POST — Admission counter: collect the admission fee OFFLINE.
     *
     * Cash / UPI / Cheque / DD / Bank Transfer / POS-Card. The amount is
     * SERVER-resolved (from the application; never the client) and NON-EDITABLE.
     * Reuses the SINGLE shared receipt + accounting path (Admission_collection)
     * — the same one the online flow uses — so there is no duplicate receipt or
     * accounting logic. Posts to the configured admission income account, tagged
     * collection_type='admission'. Hard period-lock pre-check (commercial-correct).
     */
    public function collect_admission_fee()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_manage');

        $id       = trim($this->input->post('app_id') ?? $this->input->post('id') ?? '');
        $mode     = trim((string) $this->input->post('payment_mode'));
        $bankCode = trim((string) $this->input->post('bank_ledger_code'));
        $bankRef  = trim((string) $this->input->post('bank_reference'));
        $remarks  = trim((string) $this->input->post('remarks'));

        if (!$id)          return $this->json_error('Application ID required.');
        if ($mode === '')  return $this->json_error('Payment mode required.');
        $id  = $this->safe_path_segment($id, 'id');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found.');
        if (($app['payment_status'] ?? '') === 'paid') {
            return $this->json_error('Admission fee has already been collected for this application.');
        }

        // ── Server-resolved amount (NEVER from the client) ──
        // Fix D — use the SINGLE canonical resolver shared with the online flow, so
        // the counter charges the exact same CLASS-SPECIFIC admission fee
        // (feeStructures → global schools.admissionFee). Previously the offline path
        // read only $app['fee_amount'] (unset for admin-created apps) then fell back
        // to the GLOBAL flat fee, silently under/over-charging where a class-specific
        // fee exists.
        $schoolDoc = $this->firebase->firestoreGet('schools', $this->school_name) ?? [];
        $this->load->helper('admission_crm');
        $feeInfo = admission_resolve_fee($this->fs, is_array($schoolDoc) ? $schoolDoc : [], $this->session_year, (string) ($app['class'] ?? ''));
        $amount  = round((float) ($feeInfo['amount'] ?? 0), 2);
        if ($amount <= 0) {
            // Legacy fallback: an amount explicitly stamped on the application
            // (e.g. by the online flow) when no structure/global fee resolves.
            $amount = round((float) ($app['fee_amount'] ?? 0), 2);
        }
        if ($amount <= 0) {
            return $this->json_error('Admission fee is not configured for this application. Set the fee before collecting.');
        }
        $resolved = ((float) ($feeInfo['amount'] ?? 0)) > 0;
        $feeHead  = $resolved ? (string) $feeInfo['fee_head'] : (string) ($app['fee_head'] ?? 'Admission Fee');
        $feeLabel = $resolved ? (string) $feeInfo['label']    : (string) ($app['fee_label'] ?? $feeHead);

        // ── Map display mode → accounting mode; validate bank ledger (non-cash) ──
        $modeMap = [
            'cash' => 'cash', 'upi' => 'upi', 'cheque' => 'cheque', 'neft' => 'neft', 'rtgs' => 'rtgs',
            'dd' => 'bank_transfer', 'demand draft' => 'bank_transfer', 'bank transfer' => 'bank_transfer',
            'pos' => 'bank_transfer', 'pos/card' => 'bank_transfer', 'card' => 'bank_transfer',
        ];
        $acctMode = $modeMap[strtolower($mode)] ?? 'bank_transfer';
        $isCash   = ($acctMode === 'cash');
        if ($isCash) {
            $bankCode = '';
        } else {
            if ($bankCode === '') return $this->json_error('Select a bank ledger for a non-cash collection.');
            $okBank = false;
            foreach ((array) $this->firebase->firestoreQuery('chartOfAccounts', [['schoolId', '==', $this->school_name]]) as $row) {
                $cd = $row['data'] ?? $row;
                if ((string) ($cd['code'] ?? '') !== $bankCode) continue;
                if (!empty($cd['is_bank']) && empty($cd['is_group']) && ($cd['status'] ?? '') === 'active') $okBank = true;
                break;
            }
            if (!$okBank) return $this->json_error('Invalid or inactive bank ledger selected.');
        }

        // ── Period-lock pre-check (reuse Accounting_period_lock) ──
        $this->load->library('Accounting_cache', null, 'acctCacheAdm');
        $this->load->library('Accounting_period_lock', null, 'acctLockAdm');
        $this->acctCacheAdm->init($this->school_name, $this->session_year);
        $this->acctLockAdm->init($this->firebase, $this->acctCacheAdm, $this->school_name, $this->session_year);
        $lock = $this->acctLockAdm->forceValidate(date('Y-m-d'));
        if (!empty($lock['locked'])) {
            return $this->json_error('The accounting period is closed up to ' . ($lock['lockedUntil'] ?? '') . '. Reopen it before collecting the admission fee.');
        }

        // ── SHARED receipt + accounting path (one path with online) ──
        $this->load->library('Admission_collection', null, 'admissionCol');
        $this->admissionCol->init($this->firebase, $this->fs, $this->school_name, $this->session_year);
        $rc = $this->admissionCol->record_payment([
            'app_id'         => $id,
            'amount'         => $amount,
            'currency'       => 'INR',
            'student_name'   => (string) ($app['student_name'] ?? ''),
            'father_name'    => (string) ($app['father_name'] ?? $app['parent_name'] ?? ''),
            'applied_class'  => (string) ($app['class'] ?? ''),
            'fee_head'       => $feeHead,
            'fee_label'      => $feeLabel,
            'payment_mode'   => $acctMode,
            'mode_label'     => $mode,
            'bank_code'      => $bankCode,
            'bank_reference' => $bankRef,
            'remarks'        => $remarks,
            'collected_by'   => $this->admin_name ?: ($this->admin_id ?: 'Admin'),
            'source'         => 'offline',
            'admission_ref'  => (string) ($app['admission_ref'] ?? ('ADMREF-' . strtoupper($id))),
        ]);
        if (!is_array($rc)) return $this->json_error('Collection failed — invalid amount.');
        $receiptNo = (string) $rc['receipt_no'];

        // ── Mark the lead paid + history ──
        // M7-B: single CAS-atomic write (payment fields + bounded history),
        // concurrency-safe. dedupeKey on the receipt prevents a duplicate history
        // entry if this collection is retried; financial truth is in
        // admissionReceipts + accounting journal (record_payment already ran).
        $now = date('Y-m-d H:i:s');
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $id, 'history',
            [
                'action'    => "Admission fee collected offline ({$mode}) — Receipt {$receiptNo}",
                'by'        => $this->admin_name ?: 'Admin',
                'timestamp' => $now,
            ],
            crm_history_cap(),
            [
                'payment_status' => 'paid',
                'payment_mode'   => $mode,
                'receipt_no'     => $receiptNo,
                'updated_at'     => $now,
            ],
            'paid_' . $receiptNo);

        return $this->json_success([
            'message'    => "Admission fee collected. Receipt {$receiptNo}.",
            'receipt_no' => $receiptNo,
            'amount'     => $amount,
        ]);
    }

    /**
     * GET — Print the commercial admission-fee receipt (PDF) by ADM number.
     * Reuses the shared receipt view; reads ONLY this tenant's admissionReceipts.
     */
    public function admission_receipt()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $rk = trim((string) $this->input->get('receipt'));
        if ($rk === '' || !preg_match('/^ADM[0-9]{4,10}$/', $rk)) return show_404();

        $receipt = $this->fs->get('admissionReceipts', $this->fs->docId($rk));
        if (!is_array($receipt) || (string) ($receipt['schoolId'] ?? '') !== $this->school_name) {
            return show_404();   // tenant-scoped: never expose another school's receipt
        }

        $schoolDoc = $this->firebase->firestoreGet('schools', $this->school_name) ?? [];
        // Embed the logo as a data: URI (dompdf can't fetch Firebase Storage URLs
        // reliably). CACHED + SSRF-hardened resolver (M4) — Firebase Storage is the
        // source of truth; this is a disposable CI-file-cache render optimization.
        $this->load->helper('public_url');
        $logoRaw = (string) ($schoolDoc['logo'] ?? $schoolDoc['logoUrl'] ?? $schoolDoc['logo_url'] ?? '');
        $logoEmbed = $logoRaw !== '' ? cached_school_logo_data_uri($this->school_name, $logoRaw) : '';
        $profile = array_merge(is_array($schoolDoc) ? $schoolDoc : [], [
            'display_name' => $schoolDoc['name'] ?? $schoolDoc['displayName'] ?? $this->school_name,
            'school_name'  => $schoolDoc['name'] ?? '',
            'logo_url'     => $logoEmbed, // '' → view hides it (no broken box)
            'address'      => $schoolDoc['address'] ?? '',
            'phone'        => $schoolDoc['phone']  ?? $schoolDoc['contact'] ?? '',
            'email'        => $schoolDoc['email']  ?? '',
        ]);

        $this->load->library('pdf_generator');
        $html = $this->load->view('admission/fee_receipt', ['receipt' => $receipt, 'school_profile' => $profile], true);
        $this->pdf_generator->inline($html, 'admission_receipt_' . $rk . '.pdf');
    }

    /**
     * GET — Admission QR poster (SCAN TO APPLY). HTML (print + PNG download) or
     * PDF via ?pdf=1. Client-side print; the QR encodes ONLY this school's
     * public admission URL (no secrets, tenant-scoped).
     */
    public function admission_poster()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $wantPdf = ((string) $this->input->get('pdf')) === '1';

        $schoolDoc  = $this->firebase->firestoreGet('schools', $this->school_name) ?? [];

        // Parent-facing link → canonical PRODUCTION origin (www.zenxii.com), never
        // the admin's current request host. A printed poster must resolve publicly
        // even though the admin may be on localhost/LAN/ngrok while generating it.
        // Prefer the readable slug over the raw SCH_ id (both resolve).
        $this->load->helper('public_url');
        $admissionRef = trim((string) ($schoolDoc['admissionSlug'] ?? ''));
        if ($admissionRef === '') {
            // Self-heal: name set (via School Profile page / onboarding) but no
            // slug yet — mint it now so the poster URL is professional too.
            $nm = trim((string) ($schoolDoc['name'] ?? $schoolDoc['displayName'] ?? ''));
            if ($nm !== '' && $nm !== $this->school_name) {
                $admissionRef = ensure_admission_slug($this->firebase, $this->school_name, $nm);
                if ($admissionRef !== '') $schoolDoc['admissionSlug'] = $admissionRef;
            }
            if ($admissionRef === '') $admissionRef = $this->school_name;
        }
        $admissionUrl = public_admission_form_url($admissionRef);

        // Render the QR locally as a data: URI — never an external QR API. External
        // hosts are blocked by our CSP img-src in-browser and fail offline; the local
        // SVG is vector so it stays crisp at any poster/banner print size.
        $this->load->helper('qr_token');

        // Resolve the school's branding from its profile. When the School Profile
        // has never been filled in, every field is blank — do NOT fall back to the
        // raw SCH_* id (that looks like a bug on a printed poster). Flag it instead
        // so the view can show a clean placeholder + an admin-only "configure" hint.
        $schoolName = trim((string) ($schoolDoc['name'] ?? $schoolDoc['displayName'] ?? $schoolDoc['schoolName'] ?? ''));
        if ($schoolName === '' || $schoolName === $this->school_name) {
            $schoolName = trim((string) ($this->school_display_name ?? ''));
        }
        $nameConfigured = ($schoolName !== '' && $schoolName !== $this->school_name);

        $addressParts = array_filter([
            trim((string) ($schoolDoc['address'] ?? '')),
            trim((string) ($schoolDoc['city'] ?? '')),
            trim((string) ($schoolDoc['state'] ?? '')),
            trim((string) ($schoolDoc['pincode'] ?? '')),
        ], fn($v) => $v !== '');

        // Logo. dompdf can't fetch the Firebase Storage URL (XAMPP SSL), so for
        // the PDF we embed the bytes as a data: URI; the browser HTML can use the
        // URL directly (CSP allows *.googleapis.com). If the PDF fetch fails, we
        // omit the logo rather than render a broken-image box.
        $logoUrl = (string) ($schoolDoc['logo'] ?? $schoolDoc['logoUrl'] ?? $schoolDoc['logo_url'] ?? '');
        $logoForView = $logoUrl;
        if ($wantPdf && $logoUrl !== '') {
            // CACHED + SSRF-hardened resolver (M4); '' on failure → view hides it.
            $logoForView = cached_school_logo_data_uri($this->school_name, $logoUrl);
        }

        $data = [
            'school_name'     => $nameConfigured ? $schoolName : 'Your School Name',
            'name_configured' => $nameConfigured,
            'logo_url'        => $logoForView,
            'address'         => implode(', ', $addressParts),
            'admission_url'   => $admissionUrl,
            'qr_svg'          => qr_svg_data_uri($admissionUrl),
            'want_pdf'        => $wantPdf,
        ];

        if ($wantPdf) {
            $this->load->library('pdf_generator');
            $html = $this->load->view('admission_crm/poster', $data, true);
            $this->pdf_generator->inline($html, 'admission_poster.pdf');
            return;
        }
        $this->load->view('include/header');
        $this->load->view('admission_crm/poster', $data);
        $this->load->view('include/footer');
    }

    public function delete_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_delete_app', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $safeId = $this->safe_path_segment($id, 'id');
        $this->_crm_delete('crmApplications', $safeId);
        // M3: _crm_delete is the single canonical Firestore delete (removed redundant dual-write).
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'delete_application', $safeId, "Deleted application {$safeId}",
            ['appId' => $safeId, 'entityType' => 'application']);
        return $this->json_success();
    }

    public function pipeline()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_crm_classes();
        $settings = $this->_crm_get_settings();
        $data['settings'] = is_array($settings) ? $settings : [];
        $this->load->view('include/header');
        $this->load->view('admission_crm/pipeline', $data);
        $this->load->view('include/footer');
    }

    public function fetch_pipeline()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $settings = $this->_crm_get_settings();
        $stages   = $settings['stages'] ?? $this->_default_stages();
        // C7 — each Kanban column is now a BOUNDED, index-backed per-stage query
        // (index #8 schoolId+session+stage+created_at, or #9 when a class filter
        // is applied) instead of the old unbounded full-scan grouped in memory.
        // Each column returns `hasMore` + `nextCursor` for per-column "Load More".
        $class    = trim((string) $this->input->get_post('class'));
        $pipeline = [];
        foreach ($stages as $key => $label) {
            $col = $this->_crm_pipeline_column((string) $key, $class, 50, null);
            $pipeline[$key] = array_merge(['label' => $label], $col);
        }
        return $this->json_success(['pipeline' => $pipeline, 'stages' => $stages]);
    }

    /**
     * One BOUNDED Kanban column page. Uses index #8 (stage) or #9 (stage+class).
     * `enrolled` is defensively excluded (it is not a pipeline stage). The
     * continuation cursor tracks the raw last row so it is stable regardless of
     * the display-side enrolled filter.
     */
    private function _crm_pipeline_column(string $stage, string $class, int $limit, ?array $cursor): array
    {
        $limit = max(1, min(100, $limit));
        $conds = [['stage', '==', $stage]];
        if ($class !== '') $conds[] = ['class', '==', $class];
        $items = [];
        $hasMore = false;
        $nextCursor = null;
        try {
            $res    = $this->fs->cursorQuery('crmApplications', $conds, $limit, $cursor);
            $prefix = $this->school_id . '_';
            foreach ($res['items'] as $it) {
                $r = is_array($it['data'] ?? null) ? $it['data'] : $it;
                if (($r['status'] ?? '') === 'enrolled') continue;
                $rawId   = (string) ($it['id'] ?? '');
                $r['id'] = (strpos($rawId, $prefix) === 0) ? substr($rawId, strlen($prefix)) : $rawId;
                $items[] = $r;
            }
            $hasMore    = $res['hasMore'];
            $nextCursor = $res['nextCursor'];
        } catch (\Exception $e) {
            log_message('error', "CRM pipeline stage {$stage} query failed: " . $e->getMessage());
        }
        return ['items' => $items, 'hasMore' => $hasMore, 'nextCursor' => $nextCursor];
    }

    /** Load one more bounded page for a SINGLE Kanban column (per-column pagination). */
    public function fetch_pipeline_column()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $stage = trim((string) $this->input->get_post('stage'));
        if ($stage === '') return $this->json_error('Stage required');
        $settings = $this->_crm_get_settings();
        $stages   = $settings['stages'] ?? $this->_default_stages();
        if (!array_key_exists($stage, $stages)) return $this->json_error('Invalid stage');
        $class = trim((string) $this->input->get_post('class'));
        $col   = $this->_crm_pipeline_column($stage, $class, 50, $this->_crm_cursor_from_input());
        return $this->json_success(array_merge(['stage' => $stage], $col));
    }

    public function update_stage()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_update_stage', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        $stage = $this->input->post('stage');
        if (!$id || !$stage) return $this->json_error('Application ID and stage required');
        $id = $this->safe_path_segment($id, 'id');
        $settings = $this->_crm_get_settings();
        $allowedStages = (is_array($settings) && !empty($settings['stages'])) ? array_keys($settings['stages']) : array_keys($this->_default_stages());
        if (!in_array($stage, $allowedStages, true)) return $this->json_error('Invalid stage: '.$stage);
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found');
        $now = date('Y-m-d H:i:s');
        $fromStage = (string) ($app['stage'] ?? '');
        // M7-B: single CAS-atomic write (stage + bounded history), concurrency-safe.
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $id, 'history',
            ['action'=>"Stage changed: {$fromStage} → {$stage}",'by'=>$this->admin_name,'timestamp'=>$now],
            crm_history_cap(),
            ['stage'=>$stage,'updated_at'=>$now]);
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'change_stage', $id, "Stage changed: {$fromStage} → {$stage} for " . ($app['student_name'] ?? ''),
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['from' => $fromStage, 'to' => $stage]]);
        return $this->json_success();
    }

    public function approve_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_approve', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        $remarks = trim($this->input->post('remarks') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found');
        $cs = $app['status'] ?? 'pending';
        if ($cs === 'enrolled') return $this->json_error('Cannot approve an already enrolled application');
        if ($cs === 'approved') return $this->json_error('Application is already approved');
        $now = date('Y-m-d H:i:s');
        // M7-B: single CAS-atomic write (approval fields + bounded history), concurrency-safe.
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $id, 'history',
            ['action'=>'Application approved'.($remarks?": {$remarks}":''),'by'=>$this->admin_name,'timestamp'=>$now],
            crm_history_cap(),
            ['status'=>'approved','stage'=>'approved','approved_by'=>$this->admin_name,'approved_at'=>$now,'remarks'=>$remarks,'updated_at'=>$now]);
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'approve_application', $id, "Approved application for " . ($app['student_name'] ?? '') . ($remarks ? " — {$remarks}" : ''),
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['status' => 'approved']]);
        return $this->json_success();
    }

    public function reject_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_reject', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        $reason = trim($this->input->post('reason') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found');
        $cs = $app['status'] ?? 'pending';
        if ($cs === 'enrolled') return $this->json_error('Cannot reject an already enrolled application');
        if ($cs === 'rejected') return $this->json_error('Application is already rejected');
        $now = date('Y-m-d H:i:s');
        // M7-B: single CAS-atomic write (rejection fields + bounded history), concurrency-safe.
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $id, 'history',
            ['action'=>'Application rejected'.($reason?": {$reason}":''),'by'=>$this->admin_name,'timestamp'=>$now],
            crm_history_cap(),
            ['status'=>'rejected','stage'=>'rejected','rejected_by'=>$this->admin_name,'rejected_at'=>$now,'reject_reason'=>$reason,'updated_at'=>$now]);
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'reject_application', $id, "Rejected application for " . ($app['student_name'] ?? '') . ($reason ? " — {$reason}" : ''),
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['status' => 'rejected', 'reason' => $reason]]);
        return $this->json_success();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Admission document lifecycle (Phase 1 — Canonical Document Service)
    //  verify / replace / delete a document attached to an application. Each is
    //  role-gated, tenant-scoped (docsvc init'd with this admin's school) and
    //  ownership-guarded (the documentId must be referenced by THIS application),
    //  then keeps the application's denormalized pointers in sync.
    // ─────────────────────────────────────────────────────────────────────

    /** True only if $documentId is referenced by this application (ownership guard). */
    private function _crm_doc_owned(array $app, string $documentId): bool
    {
        if ($documentId === '') return false;
        foreach ((array) ($app['documentRefs'] ?? []) as $r) {
            if (is_array($r) && (string) ($r['documentId'] ?? '') === $documentId) return true;
        }
        foreach ((array) ($app['documents'] ?? []) as $d) {
            if (is_array($d) && (string) ($d['documentId'] ?? '') === $documentId) return true;
        }
        return (string) ($app['photoDocId'] ?? '') === $documentId
            || (string) ($app['aadhaarDocId'] ?? '') === $documentId;
    }

    /**
     * Build the student Profile Pic + Doc[] map from an application's uploaded
     * documents, and return the canonical document references to link on
     * enrollment (revised D1 — link, not copy). Prefers the enriched
     * documentRefs (Phase 1); falls back to the legacy *_url / documents[]
     * fields for applications created before the Document Service, so old
     * applications enroll identically with NO migration.
     *
     * @return array{profilePic:string, doc:array, links:array<int,array{documentId:string,role:string}>}
     */
    private function _admission_docs_for_student(array $app): array
    {
        $doc = []; $profilePic = ''; $links = [];
        $typeLabel = [
            'aadhaar'              => 'Aadhar Card',
            'birth_certificate'    => 'Birth Certificate',
            'transfer_certificate' => 'Transfer Certificate',
            'marksheet'            => 'Marksheet',
            'medical_certificate'  => 'Medical Certificate',
        ];
        $refs = is_array($app['documentRefs'] ?? null) ? $app['documentRefs'] : [];
        if ($refs) {
            foreach ($refs as $r) {
                if (!is_array($r)) continue;
                $docId = trim((string) ($r['documentId'] ?? ''));
                $type  = (string) ($r['type'] ?? '');
                $url   = trim((string) ($r['url'] ?? ''));
                if ($docId !== '') $links[] = ['documentId' => $docId, 'role' => (string) ($r['role'] ?? ($type !== '' ? $type : 'document'))];
                if ($url === '') continue;
                if ($type === 'photo')            { $profilePic = $url; $doc['Photo'] = ['url' => $url, 'thumbnail' => $url]; }
                elseif (isset($typeLabel[$type])) { $doc[$typeLabel[$type]] = ['url' => $url, 'thumbnail' => '']; }
                else {
                    $nm  = trim((string) ($r['label'] ?? 'Document'));
                    $key = $nm !== '' ? substr(preg_replace('/[^A-Za-z0-9 _.-]/', '', $nm), 0, 60) : '';
                    if ($key === '' || isset($doc[$key])) $key = 'Application Document ' . (count($doc) + 1);
                    $doc[$key] = ['url' => $url, 'thumbnail' => ''];
                }
            }
        } else {
            // Legacy fallback (pre-Document-Service applications) — reference the
            // stored URLs exactly as the prior behavior did (no links to create).
            $ph = trim((string) ($app['photo_url'] ?? ''));
            if ($ph !== '') { $profilePic = $ph; $doc['Photo'] = ['url' => $ph, 'thumbnail' => $ph]; }
            $aa = trim((string) ($app['aadhaar_url'] ?? ''));
            if ($aa !== '') $doc['Aadhar Card'] = ['url' => $aa, 'thumbnail' => ''];
            if (is_array($app['documents'] ?? null)) {
                foreach ($app['documents'] as $ld) {
                    if (!is_array($ld)) continue;
                    $lurl = trim((string) ($ld['url'] ?? ''));
                    if ($lurl === '') continue;
                    $nm  = trim((string) ($ld['name'] ?? ''));
                    $key = $nm !== '' ? substr(preg_replace('/[^A-Za-z0-9 _.-]/', '', $nm), 0, 60) : '';
                    if ($key === '' || isset($doc[$key])) $key = 'Application Document ' . (count($doc) + 1);
                    $doc[$key] = ['url' => $lurl, 'thumbnail' => ''];
                }
            }
        }
        return ['profilePic' => $profilePic, 'doc' => $doc, 'links' => $links];
    }

    /** POST — set a document's verification status: verified | rejected | pending. */
    public function verify_admission_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_verify_doc');
        $id     = $this->safe_path_segment(trim((string) $this->input->post('app_id')), 'app_id');
        $docId  = trim((string) $this->input->post('document_id'));
        $status = trim((string) $this->input->post('status'));
        if ($id === '' || $docId === '')                                return $this->json_error('Application and document are required.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId))                   return $this->json_error('Invalid document id.');
        if (!in_array($status, ['verified', 'rejected', 'pending'], true)) return $this->json_error('Invalid verification status.');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app))                    return $this->json_error('Application not found.');
        if (!$this->_crm_doc_owned($app, $docId)) return $this->json_error('Document does not belong to this application.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        $res = $this->docsvc->verify($docId, $status, (string) ($this->admin_id ?? 'system'));
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Verification failed.');

        // Keep the denormalized pointer fresh so the CRM reflects the new status
        // with no extra read.
        if (is_array($app['documentRefs'] ?? null)) {
            $refs = array_map(function ($r) use ($docId, $status) {
                if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) $r['verificationStatus'] = $status;
                return $r;
            }, $app['documentRefs']);
            $this->fs->updateEntity('crmApplications', $id, ['documentRefs' => $refs, 'updated_at' => date('Y-m-d H:i:s')]);
        }
        log_audit('CRM', 'verify_admission_document', $id, "Document {$docId} marked {$status}",
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['documentId' => $docId, 'status' => $status]]);
        // Distinct key — a data 'status' would overwrite json_success()'s
        // status:'success' and make the frontend read the call as failed.
        return $this->json_success(['verificationStatus' => $status]);
    }

    /** POST (multipart) — replace a document's bytes (new version; old object retired). */
    public function replace_admission_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_replace_doc');
        $id    = $this->safe_path_segment(trim((string) $this->input->post('app_id')), 'app_id');
        $docId = trim((string) $this->input->post('document_id'));
        if ($id === '' || $docId === '')             return $this->json_error('Application and document are required.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId)) return $this->json_error('Invalid document id.');
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->json_error('Please choose a replacement file.');
        }
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app))                    return $this->json_error('Application not found.');
        if (!$this->_crm_doc_owned($app, $docId)) return $this->json_error('Document does not belong to this application.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        $res = $this->docsvc->replace($docId, (string) $_FILES['file']['tmp_name'], (string) $_FILES['file']['name'], (string) ($this->admin_id ?? 'system'));
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Replace failed.');
        $newUrl = (string) ($res['document']['downloadUrl'] ?? '');

        // Refresh ALL denormalized pointers (URL changes; verification resets to
        // pending) so every existing reader — CRM, enrollment carry-over — sees
        // the new object.
        $now = date('Y-m-d H:i:s'); $patch = ['updated_at' => $now];
        if (is_array($app['documentRefs'] ?? null)) {
            $patch['documentRefs'] = array_map(function ($r) use ($docId, $newUrl) {
                if (is_array($r) && (string) ($r['documentId'] ?? '') === $docId) { $r['url'] = $newUrl; $r['verificationStatus'] = 'pending'; }
                return $r;
            }, $app['documentRefs']);
        }
        if (is_array($app['documents'] ?? null)) {
            $patch['documents'] = array_map(function ($d) use ($docId, $newUrl) {
                if (is_array($d) && (string) ($d['documentId'] ?? '') === $docId) $d['url'] = $newUrl;
                return $d;
            }, $app['documents']);
        }
        if ((string) ($app['photoDocId'] ?? '')   === $docId) $patch['photo_url']   = $newUrl;
        if ((string) ($app['aadhaarDocId'] ?? '') === $docId) $patch['aadhaar_url'] = $newUrl;
        $this->fs->updateEntity('crmApplications', $id, $patch);
        log_audit('CRM', 'replace_admission_document', $id, "Document {$docId} replaced",
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['documentId' => $docId]]);
        return $this->json_success(['url' => $newUrl]);
    }

    /** POST — remove a document from this application (unlink; purge only if orphaned). */
    public function delete_admission_document()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_delete_doc');
        $id    = $this->safe_path_segment(trim((string) $this->input->post('app_id')), 'app_id');
        $docId = trim((string) $this->input->post('document_id'));
        if ($id === '' || $docId === '')             return $this->json_error('Application and document are required.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $docId)) return $this->json_error('Invalid document id.');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app))                    return $this->json_error('Application not found.');
        if (!$this->_crm_doc_owned($app, $docId)) return $this->json_error('Document does not belong to this application.');

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_name);
        // Unlink from THIS application. The service purges the Storage object ONLY
        // when no other module still references it (e.g. an enrolled student) AND
        // retention has elapsed — so deleting a lead's document can never break an
        // enrolled student's file (resolves hazard W4).
        $res = $this->docsvc->delete($docId, 'admission', $id, (string) ($this->admin_id ?? 'system'), false);
        if (empty($res['ok'])) return $this->json_error($res['error'] ?? 'Delete failed.');

        // Strip the denormalized pointers from the application.
        $now = date('Y-m-d H:i:s'); $patch = ['updated_at' => $now];
        if (is_array($app['documentRefs'] ?? null)) {
            $patch['documentRefs'] = array_values(array_filter($app['documentRefs'],
                fn($r) => !(is_array($r) && (string) ($r['documentId'] ?? '') === $docId)));
        }
        if (is_array($app['documents'] ?? null)) {
            $patch['documents'] = array_values(array_filter($app['documents'],
                fn($d) => !(is_array($d) && (string) ($d['documentId'] ?? '') === $docId)));
        }
        if ((string) ($app['photoDocId'] ?? '')   === $docId) { $patch['photo_url']   = ''; $patch['photoDocId']   = ''; }
        if ((string) ($app['aadhaarDocId'] ?? '') === $docId) { $patch['aadhaar_url'] = ''; $patch['aadhaarDocId'] = ''; }
        $this->fs->updateEntity('crmApplications', $id, $patch);
        log_audit('CRM', 'delete_admission_document', $id, 'Document ' . $docId . ' deleted (purged=' . (!empty($res['purged']) ? 'yes' : 'no') . ')',
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['documentId' => $docId, 'purged' => !empty($res['purged'])]]);
        return $this->json_success(['purged' => !empty($res['purged'])]);
    }

    /**
     * Phase 10.2 (2026-08-13) — CORRECTION of the Phase 10.1 design.
     *
     * Phase 10.1 generated YYYY/CLASS/SRNO (e.g. "2026/01/012"). That was
     * WRONG per the corrected business rule: an Admission Number must be
     * INDEPENDENT of class, section, SRNO and Roll Number. Embedding SRNO
     * made the two identifiers redundant (SRNO already IS the school's
     * sequential register number) and embedding class made the value
     * conceptually tied to an academic position the student outgrows.
     *
     * Corrected: a system-generated, collision-claimed, random-token
     * admission-EVENT identifier — YYYY/TTTTTT (e.g. "2026/7K4P92") —
     * produced by the dedicated Admission_no_service, a structural sibling
     * of Sr_no_service/Apaar_service. See that class for the format,
     * entropy, scope and atomic-claim rationale.
     *
     * This wrapper exists only to keep the service wiring in ONE place for
     * every admission path (the same "one canonical wiring" discipline
     * _claimIdentityAttributes() already applies to SRNO and APAAR).
     *
     * @param string $studentId  the student this admission event belongs to
     * @param string $schoolId   server-derived tenant (never client input)
     * @return string  the allocated Admission Number, or '' on failure
     *                 (callers treat '' as a non-fatal warning, exactly as
     *                 they already do for a failed SRNO/fee step — an
     *                 admission is never blocked by this)
     */
    private function _allocateAdmissionNumber(string $studentId, string $schoolId): string
    {
        $year = substr((string) $this->session_year, 0, 4);
        if (!preg_match('/^\d{4}$/', $year)) $year = date('Y'); // defensive only — session_year is always set in every real caller

        try {
            $this->load->library('Admission_no_service', null, 'admNoSvc');
            $res = $this->admNoSvc->init($this->firebase, $this->fs, $schoolId, (string) ($this->admin_id ?? 'system'))
                ->allocate($schoolId, $studentId, $year);
            if (!empty($res['ok'])) return (string) $res['admissionNo'];
            log_message('error', "Admission_no_service::allocate failed for {$studentId} school={$schoolId} reason=" . ($res['reason'] ?? 'unknown'));
        } catch (\Throwable $e) {
            log_message('error', "Admission_no_service::allocate threw for {$studentId}: " . $e->getMessage());
        }
        return '';
    }

    /**
     * Phase 9 correction pass (2026-08-13) — the ONE shared SRNO + APAAR
     * claim step for BOTH admission entry points (enroll_student() below
     * AND save_admission()), so there is exactly one place either flow
     * wires to Sr_no_service/Apaar_service, never two independent copies
     * of this logic. Extracted from enroll_student()'s original inline
     * blocks with NO behavior change — same services, same optionality,
     * same best-effort/never-blocks semantics, same field shapes written
     * to the student doc.
     *
     * Both inputs are optional: an empty string is simply a no-op for
     * that attribute, never an error. A failed claim never throws back to
     * the caller — it degrades to a warning string in the returned array,
     * exactly like a failed fee assignment already does elsewhere in both
     * callers. $studentData is accepted by reference purely so a caller
     * whose OWN primary Firestore sync still runs after this call (only
     * enroll_student() does) can have the claimed fields already present
     * in that sync — harmless, unused by save_admission(), whose primary
     * sync already ran before this is called.
     *
     * Warning wording deliberately does NOT claim a manual-recovery path
     * that doesn't exist in the UI (Phase 9 closure §G finding) — neither
     * message says "from the student profile" anymore.
     *
     * Phase 9.1 (2026-08-13) — SRNO is no longer operator-supplied. It is
     * MANDATORY, automatic, and server-allocated via
     * Sr_no_service::allocateNext() (school-scoped sequential counter) —
     * the $srNoInput parameter is gone entirely; there is nothing for a
     * caller to pass, because there is no operator SRNO input anywhere
     * anymore. Unlike APAAR (still optional), the SRNO block below always
     * runs — a failure here is surfaced as a warning (never blocks the
     * already-committed admission, same philosophy as every other
     * best-effort step in this method), but is not gated on any input
     * being present.
     *
     * Phase 10.2 (2026-08-13) — the $className parameter Phase 10.1 added
     * has been REMOVED again: it existed solely to embed the class into the
     * Admission Number, which the corrected design forbids. Admission
     * Number is now allocated by Admission_no_service and depends on
     * neither class nor SRNO — so this helper no longer needs to know the
     * class at all. Signature deliberately narrowed rather than left with a
     * silently-unused argument.
     *
     * @return string[] warnings — empty array if nothing to report
     */
    /**
     * ── SRNO-F1 (2026-08-17) — SIGNAL SRNO FAILURE, DO NOT NARRATE IT ────────
     *
     * `$srnoAssigned` is an OUT parameter: it reports whether the SR Number was
     * actually allocated, so a caller can enforce the product invariant
     *
     *     student creation + SRNO allocation = successful admission
     *
     * rather than having to parse prose out of the warnings array.
     *
     * WHY. `studentId` is the global system identity; SRNO is the school-scoped
     * OPERATIONAL identity that schools actually work by. A student admitted
     * without one is not a complete student, and this method used to say the
     * opposite in its own message text ("The admission was completed; SRNO
     * requires administrator follow-up") while returning a warning that
     * save_admission() appended to a SUCCESS response.
     *
     * Defaulting to TRUE keeps every existing caller byte-compatible: a caller
     * that does not pass the parameter behaves exactly as before, which is why
     * enroll_student() is unaffected by this change (see SRNO-F2 there).
     */
    private function _claimIdentityAttributes(string $studentId, string $schoolId, string $apaarInput, array &$studentData, bool &$srnoAssigned = true): array
    {
        $warnings = [];
        $srnoAssigned = true;

        try {
            $this->load->library('Sr_no_service', null, 'srnoSvc');
            $srnoRes = $this->srnoSvc->init($this->firebase, $this->fs, $schoolId, (string) ($this->admin_id ?? 'system'))
                ->allocateNext($schoolId, $studentId);
            if (empty($srnoRes['ok'])) {
                $reason = (string) ($srnoRes['reason'] ?? 'unknown');
                $srnoAssigned = false;
                $warnings[] = "SRNO could not be assigned automatically (reason: {$reason}).";
                log_message('error', "Sr_no_service::allocateNext failed for {$studentId} school={$schoolId} reason={$reason}");
            } else {
                $assignedSrNo = (string) $srnoRes['srNo'];
                // Mirror the same display fields Sr_no_service::relink() writes
                // on the re-admission path, so both first-time and re-admitted
                // students converge on one field shape on the student doc.
                $norm = \Sr_no_service::normalize($assignedSrNo);
                $srnoFields = [
                    'srNo'       => $assignedSrNo,
                    'srNoNorm'   => $norm['norm'] ?? '',
                    'srNoSource' => 'admission_auto',
                    'updatedAt'  => date('c'),
                ];
                // Phase 10.2 — Admission Number for this FIRST-TIME admission
                // event, allocated by Admission_no_service (random token,
                // atomically claimed). Independent of class/section/SRNO/
                // Roll Number by construction. Frozen for the life of THIS
                // admission record: promotion never recomputes it, and a
                // future re-admission gets its own BRAND-NEW number issued
                // by Readmission::confirm() (which no longer leaves the
                // field stale — the Phase 10.1 open gap is now closed).
                $allocatedAdmNo = $this->_allocateAdmissionNumber($studentId, $schoolId);
                if ($allocatedAdmNo !== '') {
                    $srnoFields['Admission Number'] = $allocatedAdmNo;
                    $srnoFields['admissionNumber']  = $allocatedAdmNo; // camelCase dual-emit, Android/Firestore convention
                } else {
                    $warnings[] = 'Admission Number could not be generated automatically. The admission was completed; it requires administrator follow-up.';
                }
                // allocateNext() already wrote srNoIndex + these same fields
                // via claim() internally — this second write is redundant
                // for srNo/srNoNorm but harmless (same values); it exists
                // only to set srNoSource distinctly ('admission_auto') and
                // to merge into $studentData for enroll_student()'s later
                // sync, mirroring the pre-Phase-9.1 pattern exactly.
                $this->fs->update('students', $this->fs->docId($studentId), $srnoFields);
                $studentData = array_merge($studentData, $srnoFields);
                log_message('info', "Sr_no_service: auto-allocated SRNO {$assignedSrNo} for {$studentId} school={$schoolId}");
            }
        } catch (\Throwable $e) {
            // An exception is no less a failure than a returned error — the SRNO
            // does not exist either way, so the invariant is equally violated.
            $srnoAssigned = false;
            log_message('error', "Sr_no_service::allocateNext threw for {$studentId}: " . $e->getMessage());
            $warnings[] = 'SRNO could not be assigned automatically.';
        }

        // Deliberately a SEPARATE service (Apaar_service, not Sr_no_service)
        // per SR_NUMBER_AND_APAAR_ID_DESIGN.md §4/§6 — APAAR is a distinct
        // identity domain with its own claim collection (apaarIndex, not
        // srNoIndex). Format is validated (exactly 12 digits after
        // stripping spaces/hyphens — Apaar_service::normalize()); an
        // invalid format is rejected as a warning, never silently coerced
        // into "valid" data. Warning text never includes the raw APAAR
        // digits (PII discipline — matches Apaar_service's own audit calls,
        // which likewise never log the value).
        if ($apaarInput !== '') {
            try {
                $this->load->library('Apaar_service', null, 'apaarSvc');
                $apaarRes = $this->apaarSvc->init($this->firebase, $this->fs, $schoolId, (string) ($this->admin_id ?? 'system'))
                    ->claim($apaarInput, $studentId);
                if (empty($apaarRes['ok'])) {
                    $reason = (string) ($apaarRes['reason'] ?? 'unknown');
                    if ($reason === 'duplicate') {
                        $existingOwner = (string) ($apaarRes['currentStudentId'] ?? 'another student');
                        $warnings[] = "APAAR ID is already assigned to {$existingOwner} in this school — check for a typing mistake. The admission was completed without it; APAAR requires administrator follow-up.";
                    } elseif ($reason === 'invalid_format') {
                        $warnings[] = 'APAAR ID must be exactly 12 digits — it was not saved. The admission was completed; APAAR requires administrator follow-up.';
                    } else {
                        $warnings[] = "APAAR ID could not be linked automatically (reason: {$reason}). The admission was completed; APAAR requires administrator follow-up.";
                    }
                    log_message('warning', "Apaar_service::claim failed for {$studentId} reason={$reason}");
                } else {
                    $apaarFields = [
                        'apaarId'   => \Apaar_service::normalize($apaarInput),
                        'updatedAt' => date('c'),
                    ];
                    $this->fs->update('students', $this->fs->docId($studentId), $apaarFields);
                    $studentData = array_merge($studentData, $apaarFields);
                    log_message('info', "Apaar_service: claimed APAAR for {$studentId}");
                }
            } catch (\Throwable $e) {
                log_message('error', "Apaar_service::claim threw for {$studentId}: " . $e->getMessage());
                $warnings[] = 'APAAR ID could not be linked automatically. The admission was completed, but APAAR requires administrator follow-up.';
            }
        }

        return $warnings;
    }

    public function enroll_student()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_enroll', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');
        $app = $this->_crm_get('crmApplications', $id);
        if (!is_array($app)) return $this->json_error('Application not found');
        if (($app['status'] ?? '') !== 'approved') return $this->json_error('Only approved applications can be enrolled');

        // Concurrency guard (F1) — TRUE atomic mutex. createIfAbsent() on a
        // per-application lock doc is a real cross-process compare-and-set
        // (Firestore returns 409 if the doc already exists), so two genuinely
        // simultaneous enrolls of the same application can NEVER both proceed —
        // exactly one wins the create; the loser is rejected. This closes the
        // duplicate student + Firebase Auth + double-fee race for good (not just
        // the double-click case). Stale-lock recovery: if an existing lock is
        // older than 120s (a crashed/abandoned enroll), reclaim it. The lock is
        // released on the success path and the common failure paths below, and
        // self-heals after 120s as a safety net. On success the status flips to
        // 'enrolled', which the approved-check above independently blocks.
        $lockId   = $this->fs->docId($id);   // crmEnrollLocks/{schoolId}_{appId}
        $lockData = ['appId' => $id, 'lockedBy' => $this->admin_name, 'lockedAt' => date('c'), 'ts' => time()];
        if (!$this->fs->createIfAbsent('crmEnrollLocks', $lockId, $lockData)) {
            $held   = $this->fs->get('crmEnrollLocks', $lockId);
            $heldTs = is_array($held) ? (int) ($held['ts'] ?? 0) : 0;
            if ($heldTs > 0 && (time() - $heldTs) < 120) {
                return $this->json_error('This application is already being enrolled. Please wait a moment and refresh before retrying.');
            }
            // Stale lock from a crashed enroll — take it over.
            $this->fs->set('crmEnrollLocks', $lockId, $lockData, false);
        }

        $school_id = $this->parent_db_key;
        $school_name = $this->school_name;
        $session = $this->session_year;
        // FIXED: use _nextStudentId() for duplicate-safe ID with retry loop (was inline with no check)
        $studentId = $this->_nextStudentId($school_id);
        if (!$studentId) {
            return $this->json_error('Failed to generate unique student ID. Please try again.');
        }
        $className = Firestore_service::classKey(trim($app['class'] ?? ''));
        if ($className === '') return $this->json_error('Class not specified in application');

        // Section resolution. Public-form applications never carry a
        // section (parents pick a class, the school assigns the section
        // at enroll time). Order:
        //   1. Admin override via POST `section`
        //   2. The application doc's own `section` field (internal flow)
        //   3. First alphabetical section that exists in the `sections`
        //      collection for this class + active session
        //   4. Hard fallback 'A'
        // The previous code went straight to step 4 — every public-form
        // enrollment landed in Section A even when other sections existed.
        $sectionRaw = trim((string) $this->input->post('section'));
        if ($sectionRaw === '') $sectionRaw = trim((string) ($app['section'] ?? ''));
        if ($sectionRaw === '') {
            try {
                $sectionDocs = $this->fs->schoolList('sections', [
                    ['session',   '==', $session],
                    ['className', '==', $className],
                ]);
                $sectionLetters = [];
                foreach ($sectionDocs as $sd) {
                    $sl = trim((string) ($sd['section'] ?? ''));
                    if ($sl !== '') $sectionLetters[$sl] = true;
                }
                if (!empty($sectionLetters)) {
                    $keys = array_keys($sectionLetters);
                    sort($keys);
                    $sectionRaw = (string) $keys[0];
                }
            } catch (\Exception $e) {
                log_message('error', "enroll_student: sections lookup failed for {$className}: " . $e->getMessage());
            }
        }
        if ($sectionRaw === '') $sectionRaw = 'A';
        $section = Firestore_service::sectionKey($sectionRaw);

        // SIS Tier-1 fix B6 (2026-05-31): destination fee-structure pre-flight,
        // mirroring the promotion guard at Sis.php:965-974 (BUG-076 Part 2-A).
        // Without this check, an enrollment whose destination class/section
        // has no feeStructure doc — or has one with empty feeHeads — would
        // proceed: student doc saved, Firebase Auth created, SMS dispatched,
        // CRM marked enrolled. Then assignInitialFees would silently return
        // [] and the student would land Active-with-zero-demands. Block here,
        // before any Firestore write or Auth side-effect, so the operator can
        // set up the fee structure first and retry the enrollment.
        // Shared implementation — see _fee_structure_missing() above.
        $feeStructErr = $this->_fee_structure_missing($className, $section, $session, 'Enrollment');
        if ($feeStructErr !== '') {
            // Release the enroll lock so the operator can retry immediately
            // after creating the fee structure (the documented "set it up and
            // retry" workflow — a wait here would be pure friction).
            $this->fs->remove('crmEnrollLocks', $lockId);
            return $this->json_error($feeStructErr);
        }

        // ── Phase 15 (HIGH-1, CRM path) — Section capacity, SERVER-SIDE ─────
        // CORRECTION TO A PRIOR REPORT: the Class/Section research stated the
        // CRM path "already enforces capacity". Source says otherwise —
        // get_class_sections() computes headroom for the PICKER, but
        // enroll_student() itself had no guard, so the CRM path was equally
        // capable of overfilling a section if the client was stale or bypassed.
        // Both paths are now enforced by the same canonical validator.
        //
        // Released the enroll lock on rejection, matching the fee-structure
        // guard immediately above — the operator fixes the section choice and
        // retries without waiting out the mutex.
        // Same authority, same ordering as admission: the section version must be
        // captured BEFORE the occupancy it protects, and carried into the placement
        // commit. Without it the guard fails closed (ACC_SEAT_NO_DECISION_VERSION)
        // and refuses EVERY enrolment — which is what this path did before, since
        // it called the guard but supplied no version.
        // Declare the dependency at the point of use — committed MY_Controller
        // does not load this library, and relying on another constructor to have
        // pulled it in has now failed in three different callers.
        require_once APPPATH . 'libraries/Section_seat_guard.php';
        $decisionVersion = $this->_sectionDecisionVersion($className, $section, $session);
        $capOver = $this->_sectionCapacityExceeded($className, $section, $session, 1, '', /* fresh */ true);
        if ($capOver !== null) {
            $this->fs->remove('crmEnrollLocks', $lockId);
            return $this->json_error($this->_sectionCapacityMessage($capOver, $className, $section));
        }

        $combinedPath = "{$className}/{$section}";
        $formattedDOB = !empty($app['dob']) ? date('d-m-Y', strtotime($app['dob'])) : '';
        $now = date('Y-m-d H:i:s');

        // Generate the student's initial password up-front so we can
        // validate it. The previous inline call inside the array literal
        // gave us no chance to fail loudly when DOB was missing or the
        // generator returned a blank string — the enroll then "succeeded"
        // but the parent could never log in. Now it's a single named
        // value with a guard.
        $generatedPassword = $this->_generatePassword($app['student_name'] ?? '', $formattedDOB);
        if (strlen(trim($generatedPassword)) < 4) {
            log_message('error', "enroll_student: password generation produced too-short result for {$studentId} (name='" . ($app['student_name'] ?? '') . "', dob='{$formattedDOB}'). Aborting enrollment.");
            return $this->json_error('Could not generate a valid password (student name or date of birth is missing). Please edit the application first.');
        }

        // Carry the parent-uploaded documents onto the enrolled student. Prefers
        // the canonical documentRefs (Phase 1); falls back to legacy URLs for
        // pre-Document-Service applications. The empty canonical slots are kept
        // (merged under) so the SIS profile always shows them; uploaded files
        // fill them. Links are created AFTER saveStudent (revised D1 — no copy).
        $carry       = $this->_admission_docs_for_student($app);
        $docTemplate = [
            "Aadhar Card"=>["thumbnail"=>"","url"=>""], "Birth Certificate"=>["thumbnail"=>"","url"=>""],
            "Photo"=>["thumbnail"=>"","url"=>""], "Transfer Certificate"=>["thumbnail"=>"","url"=>""],
        ];

        $studentData = [
            "Name"=>$app['student_name']??'', "User Id"=>$studentId, "DOB"=>$formattedDOB,
            "Admission Date"=>date('d-m-Y'), "Class"=>$className, "Section"=>$section,  // Already prefixed via classKey/sectionKey
            "Gender"=>$app['gender']??'', "Blood Group"=>$app['blood_group']??'',
            "Category"=>$app['category']??'', "Religion"=>$app['religion']??'',
            "Nationality"=>$app['nationality']??'',
            "Father Name"=>$app['father_name']??'', "Father Occupation"=>$app['father_occupation']??'',
            "Mother Name"=>$app['mother_name']??'', "Mother Occupation"=>$app['mother_occupation']??'',
            "Guard Contact"=>$app['guardian_phone']??'', "Guard Relation"=>$app['guardian_relation']??'',
            "Phone Number"=>$app['phone']??'', "Email"=>$app['email']??'',
            "Password"=>$generatedPassword,
            "Address"=>["Street"=>$app['address']??'',"City"=>$app['city']??'',"State"=>$app['state']??'',"PostalCode"=>$app['pincode']??''],
            "Pre School"=>$app['previous_school']??'', "Pre Class"=>$app['previous_class']??'', "Pre Marks"=>$app['previous_marks']??'',
            "Profile Pic"=>$carry['profilePic'],
            "Doc"=>array_merge($docTemplate, $carry['doc']),
            "Status"=>"Active",
            // Phase A — first-login force-change. Cleared once the parent
            // successfully changes the password from the parent app.
            "mustChangePassword" => true,
            // Back-link to the originating CRM application so audit /
            // reporting can trace any enrolled student to their admission
            // record without scanning crmApplications by student_id.
            "application_id" => $id,
        ];

        // Firestore-only per no-RTDB policy.
        // SIS Tier-1 fix B1 (2026-05-31): saveStudent must be fail-loud.
        // Pre-fix, a Firestore failure here was logged but the enrollment
        // continued — admin saw success while parent had no profile, the
        // Firebase Auth account was orphaned, no fee demands were
        // generated, and the CRM application was marked enrolled despite
        // no underlying student record. We now abort immediately and
        // surface the error so admin can retry cleanly; downstream Auth
        // creation, fee assignment, sync, and ADMISSION history are all
        // skipped, so no orphan side-effect can be left behind.
        // saveStudent returns bool; an exception is treated as a false
        // return so a single guard covers both failure modes.
        // NEW-1 — SERVER-SIDE ENROLLMENT IDEMPOTENCY. Reuse the SAME
        // production-certified primitive the admission payment path uses
        // (Accounting_idempotency), with the deterministic key ENROLL_{appId}.
        // This makes the whole create-student / Firebase-Auth / assignInitialFees /
        // mark-enrolled sequence EXACTLY-ONCE under concurrent admins, browser/
        // network retry, refresh, or duplicate POST. Acquired HERE — after every
        // abort-able validation (status/class/section/fee-structure/password) and
        // right before the first duplicating side-effect (saveStudent) — so a
        // validation failure can never leave the slot stuck 'processing'.
        $this->load->library('Accounting_idempotency', null, 'enrollIdemp');
        $this->enrollIdemp->init($this->firebase, $this->school_name, $this->session_year);
        $enrollKey = 'ENROLL_' . $id;
        $claim = $this->enrollIdemp->claim($enrollKey, 'crm_enroll');
        if (!empty($claim['dedup'])) {
            // Already enrolled — return the canonical already-processed response.
            // No second student / Auth / fee-demand is created.
            $existingSid = (string) ($claim['entryId'] ?? ($app['student_id'] ?? ''));
            return $this->json_success([
                'student_id'       => $existingSid,
                'already_enrolled' => true,
                'message'          => 'This application is already enrolled' . ($existingSid !== '' ? " as {$existingSid}" : '') . '.',
            ]);
        }
        if (!empty($claim['in_progress'])) {
            // A concurrent request owns the slot — safe no-op (creates nothing).
            return $this->json_error('Enrollment is already in progress for this application. Please wait a moment and refresh.');
        }
        if (isset($claim['error'])) {
            // Fail CLOSED — proceeding without the gate risks a duplicate student.
            log_message('error', "enroll_student: idempotency claim error key={$enrollKey}: " . $claim['error']);
            return $this->json_error('Could not acquire the enrollment lock. Please retry.');
        }
        // first_claim | stale_override ⇒ we own the slot; enroll exactly once.

        // ── PHASE 20.3h — PATH 2/5: ENROLL (P20.2-RACE-1) ───────────────
        // The guard protects saveStudent(), NOT the later syncStudent().
        // Phase 20.3e-1 proved saveStudent() is the document-CREATING primary
        // write here; guarding the follow-up sync instead would leave the real
        // creation unprotected — a bypass that reads as protected.
        //
        // A refusal returns before _claimIdentityAttributes() further below, so
        // no SRNO is claimed for an enrollment that did not happen.
        $studentSaved = false;
        $capacityRefusal = '';
        try {
            if ($this->_seatGuardEnabled()) {
                $placement = ['status' => '', 'message' => ''];
                for ($attempt = 1; $attempt <= self::SEAT_RETRY_ATTEMPTS; $attempt++) {
                    if ($attempt > 1) {
                        // Re-decide on a genuine conflict, version first.
                        $decisionVersion = $this->_sectionDecisionVersion($className, $section, $session);
                        $cap2 = $this->_sectionCapacity($className, $section, $session);
                        if ($cap2 > 0) {
                            $now2 = $this->_sectionActiveCountFresh($className, $section, $session);
                            if ($now2 + 1 > $cap2) {
                                $placement = ['status' => '', 'message' => $this->_sectionCapacityMessage(
                                    ['capacity' => $cap2, 'current' => $now2, 'incoming' => 1,
                                     'projected' => $now2 + 1, 'over' => ($now2 + 1) - $cap2],
                                    $className, $section)];
                                break;
                            }
                        }
                    }
                    $placement = $this->_placeStudentGuarded(
                        $studentId, $studentData, $className, $section, $session, 'enroll_student',
                        $decisionVersion
                    );
                    if (($placement['status'] ?? '') === Section_seat_guard::PLACED) break;
                    if (($placement['status'] ?? '') !== Section_seat_guard::CONFLICT) break;
                }
                $studentSaved = (($placement['status'] ?? '') === Section_seat_guard::PLACED);
                if (!$studentSaved) $capacityRefusal = (string) ($placement['message'] ?? '');
            } else {
                $studentSaved = (bool) $this->fs->saveStudent($studentId, $studentData);
            }
        } catch (\Exception $e) {
            log_message('error', "Firestore saveStudent failed for {$studentId}: " . $e->getMessage());
        }
        if (!$studentSaved) {
            // Release the slot so a corrected retry can immediately reclaim it.
            $this->enrollIdemp->markFailed($enrollKey, 'saveStudent failed');
            return $this->json_error($capacityRefusal !== ''
                ? $capacityRefusal
                : 'Failed to create student profile in database. Please retry. If the issue persists, contact support.');
        }

        // ── F14 Phase 1g (2026-08-12) — admission transport declaration ──
        //
        // The family ticked "transport" on the admission form. That is a REQUEST,
        // not an entitlement: at this point the school normally has no route or
        // stop for them, and assignStudent() would create a transport FEE — so
        // converting a bare declaration into an assignment would bill a family
        // for a service nobody has arranged yet.
        //
        // Hybrid F-3: route + stop present ⇒ assign for real; otherwise ⇒ a
        // pending worklist row for the office to complete. requestTransport() is
        // idempotent per (student, session), so the enroll-retry path above
        // cannot produce two requests.
        //
        // Wrapped and best-effort BY DESIGN: a transport worklist entry must
        // never be able to fail an enrollment that has already created the
        // student and their Auth account.
        $transportRequest = null;
        try {
            $decl = (array) ($app['entitlementDeclarations'] ?? []);
            if (!empty($decl['transport'])) {
                $this->load->library('numbering_service', null, 'trnNumbering');
                $this->trnNumbering->init($this->fs, $this->school_name, $this->session_year);
                $this->load->library('transport_service', null, 'trnSvc');
                $this->trnSvc->init($this->fs, $this->trnNumbering,
                    $this->school_name, $this->session_year, $this->admin_id ?? 'admission');
                $transportRequest = $this->trnSvc->requestTransport([
                    'studentId'      => $studentId,
                    'student_name'   => (string) ($app['student_name'] ?? ''),
                    'student_class'  => (string) ($app['class'] ?? ''),
                    // MODEL A (ratified 2026-08-15): a request is DESCRIPTIVE. It
                    // never establishes a canonical route/stop — management
                    // supplies those at approve_transport_request, which is where
                    // assignStudent() runs. route_id/stop_id were only ever read
                    // by the assign-now shortcut removed in Phase 4, so passing
                    // them here is dropped rather than silently ignored.
                    'type'           => (string) ($this->input->post('transport_type') ?? 'both'),
                    'requested_area' => (string) ($decl['transportArea'] ?? ''),
                ], 'admission');
            }
        } catch (\Throwable $e) {
            log_message('error', "F14 admission transport request failed for {$studentId}: " . $e->getMessage());
        }

        // Relationship capture happens further down, AFTER $warnings exists —
        // see "Verified relationships from the ONLINE application". Putting it
        // here would have appended to a $warnings that is reassigned to []
        // later in this method, silently discarding every note.

        // Revised D1 — reference (do NOT copy) the canonical documents on the
        // enrolled student. One physical object referenced by both the admission
        // application and the student; retention governed centrally by link-count.
        // link() is idempotent, so an enroll-idempotency retry never double-counts.
        if (!empty($carry['links'])) {
            $this->load->library('document_service', null, 'docsvc');
            $this->docsvc->init($this->firebase, $school_id);
            foreach ($carry['links'] as $lk) {
                $this->docsvc->link($lk['documentId'], 'student', $studentId, $lk['role'], (string) ($this->admin_id ?? 'system'));
            }
        }

        // PHONE-IDX-1 — the CRM application carries whatever the enquiry accepted,
        // so canonicalise here, where a lead becomes a STUDENT and the number stops
        // being a contact detail and starts being an identity key.
        $phone = $this->_normalisePhone10((string) ($app['phone'] ?? ''));
        if ($phone !== '') {
            // P14-1: append, never overwrite (CRM enrolment path).
            $this->_registerPhoneIndex($phone, $studentId, 'student');
        }

        // Update Students_Index (matches save_admission pattern)
        $gender = $app['gender'] ?? '';
        $this->_update_student_index($school_name, $studentId, $app['student_name'] ?? '', $className, $section, 'Active', $gender);

        // Initialize Month Fee markers as unpaid (0) for all 12 months
        // SIS Wave-2 fix F5 (2026-05-31): fail-loud guard. Pre-fix, a
        // Firestore failure here was logged but enrollment continued —
        // student doc + CRM marker + Auth + SMS all proceeded against a
        // student with no monthFee. Current ordering already places this
        // BEFORE Firebase Auth at L4835+, so a fail-here abort cleanly
        // avoids orphan-Auth side-effects (Option-3 intent achieved
        // without reorder — current code is already correct).
        $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
        $monthFeeData = array_fill_keys($months, 0);
        $monthFeeOk = false;
        try {
            // Firestore-only per no-RTDB policy.
            $monthFeeOk = (bool) $this->fs->updateEntity('students', $studentId, ['monthFee' => $monthFeeData]);
        } catch (\Exception $e) {
            log_message('error', "Firestore dual-write monthFee failed for {$studentId}: " . $e->getMessage());
        }
        if (!$monthFeeOk) {
            // Release the slot so a corrected retry can immediately reclaim it.
            $this->enrollIdemp->markFailed($enrollKey, 'monthFee init failed');
            return $this->json_error('Failed to initialize fee tracking for student. Please retry. CRM application has NOT been marked enrolled; no Firebase Auth account was created.');
        }

        // M7-B: single CAS-atomic write (enrollment fields + bounded history),
        // concurrency-safe. schoolId/session/searchTokens on the existing doc are
        // preserved by the merge/patch (one write — M3-safe).
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $id, 'history',
            ['action'=>"Enrolled as {$studentId} in {$className} {$section}",'by'=>$this->admin_name,'timestamp'=>$now],
            crm_history_cap(),
            ['status'=>'enrolled','stage'=>'enrolled','student_id'=>$studentId,'enrolled_at'=>$now,'enrolled_by'=>$this->admin_name,'updated_at'=>$now]);
        // M7-A: permanent audit of the CRM enrollment event (canonical auditLogs).
        // Note: the SIS student-record side is separately audited via studentHistory;
        // this records the Admission-CRM lifecycle transition, correlated by appId.
        log_audit('CRM', 'enroll_student', $id, "Enrolled application for " . ($app['student_name'] ?? '') . " as student {$studentId} in {$className} {$section}",
            ['appId' => $id, 'entityType' => 'application', 'meta' => ['student_id' => $studentId, 'class' => $className, 'section' => $section]]);

        // ── Create Firebase Auth user — REQUIRED, not best-effort ─────
        // Previously this was wrapped in try/catch with only a log_message.
        // Result: Firestore student doc was created, but if Auth creation
        // failed silently (network blip, duplicate, missing creds) the
        // parent could never log in and admin had no signal. Now we
        // surface the failure as a clear error response so admin can
        // retry. Enrollment side effects (Firestore docs, fees) stay in
        // place — admin can re-run a "create auth account" repair flow
        // if needed (Phase A2 will add that).
        // SIS Wave-3 D3 (2026-05-31): consolidated via _createFirebaseAuthStudent.
        // Preserved behavior: captures result into $authCreated + $authError
        // for the existing json_success response below (which surfaces the
        // failure state to the operator — but does NOT abort enrollment).
        // The B3 issue (success-when-auth-fails) is intentionally preserved;
        // its fix is Tier-2 territory paired with this consolidation.
        // Note: $password is sourced from $studentData (set at L4905+);
        // the helper's empty-password guard handles the corner case the
        // pre-fix block guarded inline.
        $authResult  = $this->_createFirebaseAuthStudent($studentId, $studentData['Password'] ?? '', $app['student_name'] ?? '', 'SIS enroll');
        $authCreated = (bool) $authResult['success'];
        $authError   = (string) $authResult['error'];

        // Phase A Part 1 — fire SMS with login credentials immediately
        // after a successful Firebase Auth creation. Skipped if Auth
        // creation failed (no point sending credentials that don't work).
        // Fire-and-forget; never blocks the enrollment response.
        $smsSent = false;
        if ($authCreated) {
            try {
                $this->load->helper('notification');
                $smsPhone = trim((string) ($app['phone'] ?? ''));
                $studentNameForSms = (string) ($app['student_name'] ?? $studentId);
                $schoolDisplayName = (string) (
                    $this->school_display_name
                    ?? $this->school_name
                    ?? 'your school'
                );
                if ($smsPhone !== '') {
                    $smsSent = notify_enrollment_credentials(
                        $smsPhone,
                        $schoolDisplayName,
                        $studentNameForSms,
                        $studentId,
                        $generatedPassword
                    );
                    log_message('info', "SIS enroll: credentials SMS dispatch=" . ($smsSent ? 'sent' : 'failed') . " for {$studentId} → {$smsPhone}");
                }
            } catch (\Exception $e) {
                log_message('error', "SIS enroll credentials-SMS failed for {$studentId}: " . $e->getMessage());
            }
        }

        // Post-enrollment steps that run AFTER the student is committed. These
        // were previously log-only, so an operator saw "Enrolled" success while
        // fees silently failed to assign or the app-sync didn't fire. Collect
        // any failures into $warnings and surface them in the response (MED-11)
        // so the operator can act (re-assign fees / re-sync) instead of finding
        // out later. Full transactional/outbox handling of these is a larger
        // follow-up; this at least makes partial failure VISIBLE.
        $warnings = [];

        // ── Verified relationships from the ONLINE application ──────────────
        // The public form is filled by a PARENT, so what it carries is a CLAIM
        // ("we have a sibling here", plus an SR No) and never a verified link.
        // It is verified HERE, at enrolment, by the same server-side resolver
        // the counter uses — this is the first moment an authenticated member
        // of staff is accountable for the record. An unresolvable number
        // produces a warning the operator can act on, not a silent link.
        try {
            $declRel = (array) ($app['entitlementDeclarations'] ?? []);
            foreach ($this->_captureAdmissionRelationships(
                $studentId,
                (string) ($declRel['siblingSrNo'] ?? ''),
                (string) ($declRel['staffWardId'] ?? ''),
                (string) ($declRel['rteRef'] ?? '')
            ) as $note) {
                if (stripos($note, 'NOT ') !== false || stripos($note, 'could not') !== false) {
                    $warnings[] = $note;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', "REL enrol capture failed for {$studentId}: " . $e->getMessage());
        }

        // Auto-assign class fees for enrolled student
        try {
            $parentDbKey = $school_id;
            $this->feeLifecycle->assignInitialFees($studentId, $className, $section, $parentDbKey);
            log_message('info', "Fee_lifecycle: auto-assigned fees for new enrollment {$studentId}");
        } catch (Exception $e) {
            log_message('error', "Fee_lifecycle::assignInitialFees failed for {$studentId}: " . $e->getMessage());
            $warnings[] = 'Initial fees could not be assigned automatically — assign them manually from Fees.';
        }

        // ── CONS-2 · THE DEFAULTER PROJECTION MUST BE SEEDED HERE TOO ────────
        // Same gap Phase 3D closed for direct admission and CONS-1 closed for
        // single-student generation: assignInitialFees writes feeDemands but
        // emits NO defaulter event, so a student enrolled through this path has
        // unpaid demands and NO feeDefaulters document.
        //
        // The consequence is a collection blind spot rather than a wrong
        // figure: the family's dues are correct everywhere that reads
        // feeDemands (including the Parent app), but the school's DEFAULTER
        // REPORT — which reads the projection — simply does not list them. A
        // school cannot chase a student it cannot see, and nothing anywhere
        // reports the omission.
        //
        // updateDefaulterStatus reads feeDemands canonically and writes the
        // projection idempotently, so this is safe to run unconditionally and
        // safe to re-run.
        //
        // Fail-soft, exactly as Phase 3D is: enrolment must still succeed if the
        // projection write fails. The doc is recreated by any future payment
        // event or by backfill_defaulters.js.
        try {
            $this->feeDefaulter->updateDefaulterStatus($studentId);
        } catch (\Throwable $e) {
            log_message('error', "CONS-2 enrolment defaulter sync failed for {$studentId}: " . $e->getMessage());
        }

        // SRNO_STUDENT_ID_RE_ADMISSION_MULTI_TENANT_FINAL_AUDIT.md Phase 2 /
        // Phase 9 correction pass (2026-08-13) — ordinary first-time SRNO +
        // APAAR claim, wired at the SAME "best-effort, never blocks the
        // already-committed admission" point as the fee assignment
        // immediately above. Both are entirely OPTIONAL input (a school
        // that hasn't adopted either sends nothing and this is a no-op),
        // the student is already fully created and committed by this point
        // in the method, and a failed claim degrades to a warning exactly
        // like a failed fee assignment does — it can never leave behind a
        // "partially created identity" because the identity (the Student
        // ID) was never partial to begin with; only the display attributes
        // are affected. Logic lives in the ONE shared
        // _claimIdentityAttributes() helper (also used by save_admission()
        // — the direct/walk-in admission path) so both entry points wire
        // to Sr_no_service/Apaar_service identically, never two copies.
        // Phase 9.1 (2026-08-13): SRNO is no longer read from POST — it is
        // ALWAYS server-allocated inside _claimIdentityAttributes(). Any
        // `sr_no` value a client might still send (stale UI, direct API
        // call) is silently ignored; the server is the sole authority.
        $apaarInput = trim((string) $this->input->post('apaar_id'));
        $srnoAssigned = true;
        $warnings = array_merge($warnings, $this->_claimIdentityAttributes($studentId, $school_id, $apaarInput, $studentData, $srnoAssigned));

        // SRNO-INVARIANT (lifecycle-critic fix). save_admission (walk-in) treats a
        // failed SR-Number as fatal and rolls the student back; this CRM path runs
        // the identity claim only AFTER the student, fees and Auth are committed, so
        // it cannot roll back. It previously buried an SRNO failure in warnings[]
        // and still returned SUCCESS — silently minting an SR-less student. It can
        // no longer do so quietly: stamp a queryable pending marker on the doc and
        // surface a distinct signal (see the response below) so the operator must
        // complete the SR Number rather than believe the admission is finished.
        if (!$srnoAssigned) {
            try {
                $this->fs->update('students', $this->fs->docId($studentId),
                    ['srNoStatus' => 'pending', 'updatedAt' => date('c')]);
            } catch (\Throwable $e) {
                log_message('error', "enroll_student: srNoStatus marker write failed for {$studentId}: " . $e->getMessage());
            }
            log_message('error', "SRNO-INVARIANT: student {$studentId} enrolled WITHOUT an SR Number (school={$school_id}); requires admin follow-up");
        }

        // Firestore sync for Android apps (entity_sync loaded in constructor)
        // SIS Wave-2 S6 (2026-05-31): observability for sync return.
        if (!$this->entity_sync->syncStudent($studentId, $studentData)) {
            log_message('warning', "syncStudent returned false for {$studentId} (enroll_student)");
            $warnings[] = 'Student record did not sync to the mobile apps — it may take a moment or need a manual re-sync.';
        }
        if (!$this->entity_sync->syncParent($studentId, $studentData)) {
            log_message('warning', "syncParent returned false for {$studentId} (enroll_student)");
            $warnings[] = 'Parent record did not sync to the mobile apps — it may take a moment or need a manual re-sync.';
        }

        // SIS Tier 2 carry (2026-05-30): emit ADMISSION history entry
        // mirroring save_admission@536. Without this, students enrolled via
        // the CRM-approval path had no ADMISSION row in their History array
        // (forensic-documented gap, 7/9 students at SCH_D94FE8F7AD missing).
        $this->_log_history($school_id, $studentId, 'ADMISSION',
            "Student admitted to {$className} / {$section} ({$session})",
            ['class' => $className, 'section' => $section, 'session' => $session]
        );

        // NEW-1 — mark the enrollment slot SUCCESS, storing the studentId so any
        // later retry/duplicate POST dedups to THIS enrollment instead of creating
        // a second student. Reached on the success path even when Firebase Auth
        // creation failed (the student WAS created; Auth is repaired via
        // repair_auth) — so a retry must NOT re-create the student.
        $this->enrollIdemp->markSuccess($enrollKey, $studentId);

        // Response carries the credentials separately from the user-
        // facing message so the JS can show a clean toast AND a
        // copyable credentials panel. Password deliberately NOT in
        // `message` — toasts auto-dismiss and showing secrets in a
        // toast is bad UX + bad security.
        $this->fs->remove('crmEnrollLocks', $lockId);   // release enroll mutex — enrollment complete
        // SRNO-INVARIANT: prepend a prominent note when the SR Number was not
        // assigned, so the operator does not read a plain success and move on.
        $srnoNote = $srnoAssigned ? '' : 'SR Number could NOT be assigned automatically — open this student and complete it. ';
        return $this->json_success([
            'student_id'   => $studentId,
            'sr_no'        => $studentData['srNo'] ?? '',   // Phase 9.1 — server-allocated, for the credentials/identity panel
            'srno_assigned'=> $srnoAssigned,                // SRNO-INVARIANT: false = admitted without an SR Number, needs follow-up
            'admission_no' => $studentData['Admission Number'] ?? '',   // Phase 10.1 — admission-event identifier, YYYY/CLASS/XXX
            'admno_assigned'=> ($studentData['Admission Number'] ?? '') !== '',   // Decision E — false = admitted without an admission number, needs follow-up
            'class'        => $className,
            'section'      => $section,
            'password'     => $generatedPassword,           // plain — for the credentials panel
            'auth_created' => $authCreated,
            'auth_error'   => $authCreated ? '' : $authError,
            'sms_sent'     => $smsSent,
            // F14 Phase 1g — surface the transport outcome so the operator knows
            // a request is waiting rather than assuming transport is live. Null
            // when the family did not ask for transport.
            'transport'    => $transportRequest,
            'warnings'     => $warnings,   // non-fatal post-enroll issues (fees/sync) for the operator
            'message'      => $srnoNote . ($authCreated
                ? "Enrolled as {$studentId} in {$className} / {$section}." . ($smsSent ? ' Login credentials sent via SMS.' : '')
                : "Enrolled as {$studentId} but parent login is NOT ready — Firebase Auth account creation failed. Error: {$authError}"),
        ]);
    }

    /**
     * SIS Tier-2 fix B3 (post-soak 2026-06-01): retry Firebase Auth
     * creation for a student whose original enrollment left an
     * orphan-Auth row (enroll_student returned json_success with
     * auth_created=false because Firebase Auth creation failed but
     * the student profile + fee assignments succeeded).
     *
     * Pre-fix: operator saw the warning modal but had no in-app
     * repair flow — only tech-support escalation could create the
     * missing Auth account. This endpoint re-runs the same
     * _createFirebaseAuthStudent helper used at enrollment, with no
     * password rotation, no operator override, and no auto-notify
     * (operator hand-delivers credentials from the json_success
     * payload).
     *
     * Q-decisions locked 2026-05-31:
     *   Q1 Option 1 — helper call only, no getFirebaseUserByEmail
     *      pre-check (deferred Option 2)
     *   Q2 MANAGE_ROLES gate (parity with change_status)
     *   Q3 NO force-rotate — does NOT set mustChangePassword
     *   Q4 Reuse stored password silently — no operator override
     *   Q5 No auto-notify — silent JSON-only return
     *   Q7 Password exposed in json_success (parity with
     *      enroll_student credentials panel)
     *
     * Idempotent retry semantics inherited from
     * _createFirebaseAuthStudent: on duplicate email Kreait throws,
     * Firebase::createFirebaseUser silently catches and returns null,
     * setFirebaseClaims then idempotently overwrites the 4-key
     * claim set, helper returns success=true honestly.
     *
     * Known carry per Q-pre-4: the AUTH_REPAIR audit entry lands in
     * legacy students.History map (not studentHistory collection)
     * until History Canonicalization ships as Slot 4. Visible in
     * admin History UI; invisible to studentHistory-only readers.
     *
     * POST  /sis/repair_auth  OR  /admission_crm/repair_auth
     *   user_id (required)
     *
     * Returns json_success with credentials-panel shape mirroring
     * enroll_student, OR json_error on any pre-flight failure or
     * helper-reported Auth-create failure.
     */
    public function repair_student_auth()
    {
        $this->_require_role(self::MANAGE_ROLES, 'sis_repair_auth', 'SIS', 'manage');

        if (strtolower((string) $this->input->method()) !== 'post') {
            return $this->json_error('POST required.');
        }

        $userId = trim((string) $this->input->post('user_id', TRUE));
        if ($userId === '') {
            return $this->json_error('user_id is required.');
        }
        if (!$this->safe_path_segment($userId)) {
            return $this->json_error('Invalid user_id.');
        }

        $student = $this->_getStudent($userId);
        if (!is_array($student) || empty($student)) {
            return $this->json_error('Student not found.');
        }

        $status = (string) ($student['Status'] ?? $student['status'] ?? '');
        if (in_array($status, ['Withdrawn', 'Inactive'], true)) {
            return $this->json_error(
                'Cannot repair Auth for a withdrawn/inactive student.'
            );
        }

        // CRED-PLAIN-1 (2026-09-19) — this used to read the plaintext password back
        // off the student document ("Q4: reuse stored password silently"). Nothing is
        // stored any more, so there is nothing to recover: repair now MINTS a fresh
        // random credential and reports it once, exactly as reset_password does.
        //
        // The old fallback — _generatePassword(Name, DOB) — was the worse half of this
        // path even before the change: it rebuilt the guessable name+DOB password for
        // any row without a stored one. Repairing an account must never restore a
        // credential a stranger could have derived.
        $password = $this->_generatePassword('', '');
        if ($password === '') {
            return $this->json_error(
                'Could not generate a credential for this student. Nothing was changed.'
            );
        }

        $displayName = (string) ($student['Name'] ?? $userId);
        $result = $this->_createFirebaseAuthStudent(
            $userId,
            $password,
            $displayName,
            'SIS repair'
        );
        if (!($result['success'] ?? false)) {
            return $this->json_error(
                'Failed to repair Firebase Auth account: ' . ($result['error'] ?? 'unknown error')
            );
        }

        $school_id = $this->parent_db_key;
        $this->_log_history(
            $school_id,
            $userId,
            'AUTH_REPAIR',
            'Firebase Auth account repaired by ' . ($this->admin_name ?? 'system'),
            ['context' => 'SIS repair']
        );

        return $this->json_success([
            'user_id'      => $userId,
            'password'     => $password,
            'auth_created' => true,
            'message'      => "Firebase Auth account repaired for {$userId}. A NEW password was set"
                            . ' — share it with the family now, it is not stored and cannot be shown again.',
        ]);
    }

    /**
     * GET — list section letters that exist for a given class in the
     * current session. Used by the enrollment JS to let admin pick which
     * section to enroll a CRM application into instead of silently
     * defaulting to "A".
     */
    public function get_class_sections()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $cls = trim((string) $this->input->get('class'));
        if ($cls === '') return $this->json_success(['sections' => [], 'detail' => [], 'suggested' => '']);
        $className = Firestore_service::classKey($cls);

        $detail = [];
        try {
            $docs = $this->fs->schoolList('sections', [
                ['session',   '==', $this->session_year],
                ['className', '==', $className],
            ]);
            // Live counts in ONE query, tallied per section in PHP — avoids an
            // N+1 count() RPC per section (was one aggregation round-trip each
            // time the enroll dialog opened). Null signals a query failure so
            // the loop falls back to the section doc's stored studentCount.
            $secCounts = [];
            try {
                // ── ADM-2 (2026-08-16) — DUAL-SHAPE STATUS + SECTION ────────
                // The `['Status','==','Active']` predicate that used to sit in
                // this query silently excluded EVERY student whose document
                // carries the lowercase `status` field — which is the shape
                // the current admission path writes. Firestore equality binds
                // to one exact field name, so the tally came back empty, every
                // section reported `0/40`, "Suggested" was meaningless and a
                // FULL section could never be detected. The operator sees a
                // confident, wrong number.
                //
                // Status is therefore filtered in PHP against BOTH spellings,
                // exactly as _filterSectionRoster() does elsewhere. A blank
                // status counts as Active (that is how legacy rows read).
                // Section is read in both shapes for the same reason.
                foreach ($this->fs->schoolList('students', [
                    ['className', '==', $className],
                ]) as $st) {
                    $stat = trim((string) ($st['status'] ?? $st['Status'] ?? ''));
                    if ($stat !== '' && strcasecmp($stat, 'Active') !== 0) continue;
                    $sec = trim((string) ($st['section'] ?? $st['Section'] ?? ''));
                    if ($sec !== '') $secCounts[$sec] = ($secCounts[$sec] ?? 0) + 1;
                }
            } catch (\Exception $e) {
                log_message('error', "get_class_sections student tally failed for {$className}: " . $e->getMessage());
                $secCounts = null;
            }
            foreach ($docs as $d) {
                $s = trim((string) ($d['section'] ?? ''));
                if ($s === '') continue;
                // Strip the "Section " prefix so the picker shows just "A", "B", etc.
                $sNorm = preg_replace('/^Section\s+/i', '', $s);
                if ($sNorm === '') continue;

                // Capacity (admin-set on section creation; default 40 if missing).
                $capacity = (int) ($d['maxStrength'] ?? $d['max_strength'] ?? 40);
                if ($capacity <= 0) $capacity = 40;

                // Live count from `students` collection — more reliable than
                // the cached `studentCount` field on the section doc, which
                // can drift if other flows forget to bump it.
                $sectionStored = $d['section'] ?? $sNorm; // exact stored value (might be "A" or "Section A")
                // Use the pre-computed tally; fall back to the section doc's
                // stored studentCount only if the students query above failed.
                $current = is_array($secCounts)
                    ? (int) ($secCounts[$sectionStored] ?? 0)
                    : (int) ($d['studentCount'] ?? 0);

                $available = max(0, $capacity - $current);
                $detail[$sNorm] = [
                    'section'   => $sNorm,
                    'current'   => $current,
                    'capacity'  => $capacity,
                    'available' => $available,
                    'full'      => $available <= 0,
                ];
            }
        } catch (\Exception $e) {
            log_message('error', "get_class_sections failed for {$className}: " . $e->getMessage());
        }

        ksort($detail);
        $sections = array_keys($detail);

        // Suggestion: the non-full section with the most headroom. Falls
        // back to the first non-full alphabetically if all have the same
        // headroom. Empty when every section is full (admin must create
        // a new one or manually override).
        $suggested = '';
        $bestHead = -1;
        foreach ($detail as $s => $info) {
            if ($info['full']) continue;
            if ($info['available'] > $bestHead) {
                $bestHead = $info['available'];
                $suggested = $s;
            }
        }

        return $this->json_success([
            'class'     => $className,
            'sections'  => $sections,
            'detail'    => array_values($detail),
            'suggested' => $suggested,
        ]);
    }

    public function waitlist()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_crm_classes();
        $this->load->view('include/header');
        $this->load->view('admission_crm/waitlist', $data);
        $this->load->view('include/footer');
    }

    public function fetch_waitlist()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $session = $this->session_year;
        // Session-scoped read (F5): waitlist screen shows only the active session.
        $waitlist = $this->_crm_list('crmWaitlist', [['session', '==', $session]]);
        if (!is_array($waitlist)) $waitlist = [];
        $result = [];
        foreach ($waitlist as $id => $w) {
            if (!is_array($w) || ($w['session']??'') !== $session) continue;
            $w['id'] = $id;
            $result[] = $w;
        }
        usort($result, function($a,$b) {
            $p = ($a['priority']??999) - ($b['priority']??999);
            return $p !== 0 ? $p : strcmp($a['created_at']??'',$b['created_at']??'');
        });
        return $this->json_success(['waitlist' => $result]);
    }

    public function add_to_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_add', 'SIS', 'manage');
        $app_id = trim($this->input->post('application_id') ?? '');
        $reason = trim($this->input->post('reason') ?? '');
        $priority = (int)($this->input->post('priority') ?? 99);
        if (!$app_id) return $this->json_error('Application ID required');
        $app_id = $this->safe_path_segment($app_id, 'application_id');
        $app = $this->_crm_get('crmApplications', $app_id);
        if (!is_array($app)) return $this->json_error('Application not found');
        $now = date('Y-m-d H:i:s');
        $wl_id = $this->_crm_next_id('Waitlist', 'WL', 5);
        $waitEntry = ['waitlist_id'=>$wl_id,'application_id'=>$app_id,'student_name'=>$app['student_name']??'','parent_name'=>$app['parent_name']??'',
            'phone'=>$app['phone']??'','class'=>$app['class']??'','session'=>$app['session']??$this->session_year,
            'priority'=>$priority,'reason'=>$reason,'status'=>'waiting','created_at'=>$now,'updated_at'=>$now];
        $this->_crm_set("crmWaitlist", $wl_id, $waitEntry);
        // Counter managed by _crm_next_id — no separate write needed.
        // M7-B: single CAS-atomic write (waitlist status + bounded history), concurrency-safe.
        $this->load->helper('admission_crm');
        $this->fs->casAppendBounded('crmApplications', $app_id, 'history',
            ['action'=>'Added to waitlist','by'=>$this->admin_name,'timestamp'=>$now],
            crm_history_cap(),
            ['status'=>'waitlisted','stage'=>'waitlisted','updated_at'=>$now]);
        // (crmWaitlist entry written above via _crm_set — a separate document.)
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'waitlist_add', $app_id, "Added " . ($app['student_name'] ?? '') . " to waitlist (WL {$wl_id})",
            ['appId' => $app_id, 'entityType' => 'application', 'meta' => ['waitlist_id' => $wl_id, 'priority' => $priority]]);
        return $this->json_success(['id' => $wl_id]);
    }

    public function remove_from_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_remove', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Waitlist ID required');
        $id = $this->safe_path_segment($id, 'id');
        $entry = $this->_crm_get('crmWaitlist', $id);
        if (is_array($entry) && !empty($entry['application_id'])) {
            $this->_crm_update('crmApplications', $entry['application_id'], ['status'=>'pending','stage'=>'document_collection','updated_at'=>date('Y-m-d H:i:s')]);
            // M3: _crm_update is the single canonical Firestore write (removed redundant dual-write).
            // M7-A: permanent audit (canonical auditLogs).
            log_audit('CRM', 'waitlist_remove', (string) $entry['application_id'], "Removed application from waitlist (WL {$id})",
                ['appId' => (string) $entry['application_id'], 'entityType' => 'application', 'meta' => ['waitlist_id' => $id]]);
        }
        $this->_crm_delete('crmWaitlist', $id);
        // M3: _crm_delete is the single canonical Firestore delete (removed redundant dual-write).
        return $this->json_success();
    }

    public function promote_from_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_promote', 'SIS', 'manage');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Waitlist ID required');
        $id = $this->safe_path_segment($id, 'id');
        $entry = $this->_crm_get('crmWaitlist', $id);
        if (!is_array($entry)) return $this->json_error('Waitlist entry not found');
        $app_id = $entry['application_id'] ?? '';
        if (!$app_id) return $this->json_error('No linked application');
        $now = date('Y-m-d H:i:s');
        $app = $this->_crm_get('crmApplications', $app_id);
        if (is_array($app)) {
            // M7-B: single CAS-atomic write (approval fields + bounded history), concurrency-safe.
            $this->load->helper('admission_crm');
            $this->fs->casAppendBounded('crmApplications', $app_id, 'history',
                ['action'=>'Promoted from waitlist and approved','by'=>$this->admin_name,'timestamp'=>$now],
                crm_history_cap(),
                ['status'=>'approved','stage'=>'approved','approved_by'=>$this->admin_name,'approved_at'=>$now,'updated_at'=>$now]);
            // M7-A: permanent audit (canonical auditLogs).
            log_audit('CRM', 'waitlist_promote', (string) $app_id, "Promoted " . ($app['student_name'] ?? '') . " from waitlist to approved (WL {$id})",
                ['appId' => (string) $app_id, 'entityType' => 'application', 'meta' => ['waitlist_id' => $id, 'status' => 'approved']]);
        }
        $this->_crm_delete('crmWaitlist', $id);
        // M3: _crm_delete is the single canonical Firestore delete (removed redundant dual-write).
        return $this->json_success();
    }

    public function crm_settings()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $settings = $this->_crm_get_settings();
        if (!is_array($settings)) $settings = [];
        // school_id (SCH_…) powers the Public Admission Form shareable link now
        // surfaced in the Settings hub (IA reorg 2026-07).
        $data = ['settings'=>$settings,'session_year'=>$this->session_year,'classes'=>$this->_get_crm_classes(),'school_id'=>$this->school_id];
        $this->load->view('include/header');
        $this->load->view('admission_crm/settings', $data);
        $this->load->view('include/footer');
    }

    public function save_crm_settings()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_settings', 'SIS', 'manage');
        $settings = $this->_crm_get_settings();
        if (!is_array($settings)) $settings = [];
        foreach (['stages','class_limits','form_fields','notifications'] as $key) {
            $val = $this->input->post($key);
            if (!$val) continue;
            $decoded = json_decode($val, true);
            if (!is_array($decoded)) continue;
            // Validation (F13): the payload was previously accepted with only an
            // is_array check, allowing unbounded / malformed settings to be
            // persisted. Cap collection size and sanitise stage keys/labels so
            // a bad payload can't bloat the doc or inject odd stage keys the
            // pipeline then can't render.
            if (count($decoded) > 100) {
                return $this->json_error("Too many entries for '{$key}' (max 100).");
            }
            if ($key === 'stages') {
                $clean = [];
                foreach ($decoded as $sk => $label) {
                    $sk = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $sk));
                    if ($sk === '') continue;                       // drop unusable keys
                    $clean[$sk] = mb_substr(trim((string) $label), 0, 60); // cap label length
                }
                if (empty($clean)) {
                    return $this->json_error('At least one valid stage is required.');
                }
                $decoded = $clean;
            }
            $settings[$key] = $decoded;
        }
        $settings['updated_at'] = date('Y-m-d H:i:s');
        $this->_crm_save_settings($settings);
        // M3: _crm_save_settings (-> _crm_set) is the single canonical Firestore write (removed redundant dual-write).
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'update_crm_settings', 'config', 'Updated CRM settings (stages / class limits / form fields / notifications)',
            ['entityType' => 'crm_settings']);
        return $this->json_success();
    }

    public function get_crm_settings()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        $settings = $this->_crm_get_settings();
        if (!is_array($settings)) $settings = [];
        if (empty($settings['stages'])) $settings['stages'] = $this->_default_stages();
        return $this->json_success(['settings' => $settings]);
    }

    public function online_form()
    {
        // MED-6: this is behind admin auth (the real public form is
        // Admission_public::form) but had no role gate — any tenant user could
        // reach it. Require CRM view access.
        $this->_require_role(self::VIEW_ROLES, 'crm_view', 'SIS', 'view');
        $school_name = $this->school_name;
        $settings = $this->_crm_get_settings();
        $classes  = $this->_get_crm_classes();
        $profileDoc = $this->fs->get('schools', $this->school_id);
        $profile = is_array($profileDoc) ? $profileDoc : [];
        $data = ['school_name'=>$school_name,'session_year'=>$this->session_year,'settings'=>is_array($settings)?$settings:[],'classes'=>$classes,'profile'=>is_array($profile)?$profile:[]];
        $this->load->view('admission_crm/online_form', $data);
    }

    public function submit_online_form()
    {
        // MED-6: gate this authenticated-but-unauthorized writer (it creates
        // crmInquiries + crmApplications and burns counter IDs). Requires CRM
        // manage access, matching save_application.
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_app', 'SIS', 'edit');
        // ── Rate limiting — max 10 submissions per IP per 15 minutes ──
        // Firestore-based: one doc per IP, stores recent submission timestamps.
        $clientIp = $this->input->ip_address();
        $ipKey    = preg_replace('/[^a-zA-Z0-9]/', '_', $clientIp);
        $rlDocId  = "online_form_{$ipKey}";
        $windowStart = time() - 900;
        try {
            $rlDoc = $this->fs->get('rateLimits', $rlDocId);
            $timestamps = is_array($rlDoc['timestamps'] ?? null) ? $rlDoc['timestamps'] : [];
            $recent = array_filter($timestamps, fn($ts) => (int) $ts >= $windowStart);
            if (count($recent) >= 10) {
                return $this->json_error('Too many submissions. Please try again later.', 429);
            }
            $recent[] = time();
            $this->fs->set('rateLimits', $rlDocId, ['timestamps' => array_values($recent), 'ip' => $clientIp, 'updatedAt' => date('c')], true);
        } catch (\Exception $e) {
            log_message('error', 'Rate limit check failed: ' . $e->getMessage());
            // Fail-open: allow submission if rate-limit check fails
        }

        $now = date('Y-m-d H:i:s');

        // H-05 FIX: Server-side input length limits per field
        $fieldLimits = [
            'student_name'=>100, 'parent_name'=>100, 'father_name'=>100, 'mother_name'=>100,
            'phone'=>20, 'email'=>150, 'class'=>50, 'dob'=>15, 'gender'=>15,
            'address'=>300, 'city'=>100, 'state'=>100, 'pincode'=>10,
            'previous_school'=>150, 'previous_class'=>50, 'blood_group'=>10,
            'category'=>50, 'religion'=>50, 'nationality'=>50,
            'father_occupation'=>100, 'mother_occupation'=>100, 'notes'=>500,
        ];
        $data = [];
        foreach ($fieldLimits as $f => $maxLen) {
            $val = trim($this->input->post($f) ?? '');
            if (mb_strlen($val) > $maxLen) {
                return $this->json_error("Field '{$f}' exceeds maximum length of {$maxLen} characters.");
            }
            $data[$f] = $val;
        }
        if ($data['student_name']===''||$data['phone']===''||$data['class']==='') return $this->json_error('Student name, phone, and class are required');
        if (!preg_match('/^\+?\d{10,15}$/', preg_replace('/[\s\-]/','',$data['phone']))) return $this->json_error('Invalid phone number format');
        if ($data['email']!==''&&!filter_var($data['email'],FILTER_VALIDATE_EMAIL)) return $this->json_error('Invalid email address');

        // SCALE (closure item I / Finding 1): duplicate-phone check via a BOUNDED
        // equality query (schoolId+session+phone — zig-zag, no composite index)
        // instead of a full-collection scan. Same exact-match semantics as before,
        // but reads only the 0–few docs sharing this phone rather than every
        // application in the school.
        $dupCandidates = $this->fs->schoolWhere('crmApplications', [
            ['session', '==', $this->session_year],
            ['phone',   '==', $data['phone']],
        ]);
        if (is_array($dupCandidates)) {
            foreach ($dupCandidates as $row) {
                $ea = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
                if (in_array($ea['status'] ?? '', ['pending','approved','waitlisted','enrolled'], true)) {
                    return $this->json_error('An application with this phone number already exists for this session (ID: '.($ea['application_id']??'N/A').')');
                }
            }
        }

        $inq_id = $this->_crm_next_id('Inquiry', 'INQ', 5);
        $inqData = ['inquiry_id'=>$inq_id,'student_name'=>$data['student_name'],'parent_name'=>$data['parent_name'],'phone'=>$data['phone'],'email'=>$data['email'],'class'=>$data['class'],'source'=>'Online Form','status'=>'converted','session'=>$this->session_year,'created_at'=>$now,'updated_at'=>$now,'created_by'=>'Online'];
        $this->_crm_set('crmInquiries', $inq_id, $inqData);
        $app_id = $this->_crm_next_id('Application', 'APP', 5);
        $appData = array_merge($data, ['application_id'=>$app_id,'inquiry_id'=>$inq_id,'session'=>$this->session_year,'status'=>'pending','stage'=>'document_collection','created_at'=>$now,'updated_at'=>$now,'created_by'=>'Online','documents'=>[],'history'=>[['action'=>'Application submitted via online form','by'=>'Online','timestamp'=>$now]]]);
        $this->load->helper('admission_search');
        $appData['searchTokens'] = admission_search_tokens($appData['student_name'] ?? '');
        $this->_crm_set("crmApplications", $app_id, $appData);
        $this->_crm_update("crmInquiries", $inq_id, ['application_id'=>$app_id]);
        // Counter managed by _crm_next_id — no separate write needed.
        // M3: _crm_set (inquiry + application) and _crm_update (inquiry application_id)
        // above are the single canonical Firestore writes. The removed dual-write
        // re-set the same crmApplications doc and re-set crmInquiries with the same
        // inqData+application_id already produced by _crm_set + _crm_update.
        // M7-A: permanent audit (canonical auditLogs).
        log_audit('CRM', 'create_application', $app_id, "Application submitted via online form for " . ($appData['student_name'] ?? ''),
            ['appId' => $app_id, 'entityType' => 'application', 'meta' => ['source' => 'online_form', 'inquiry_id' => $inq_id]]);
        return $this->json_success(['application_id' => $app_id]);
    }

    public function fetch_analytics()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch', 'SIS', 'view');
        // Scope to the current session at the query layer (was an all-time read
        // of every inquiry/application/waitlist doc, then filtered in PHP).
        $session = $this->session_year;
        $inquiries    = $this->_crm_list('crmInquiries',    [['session', '==', $session]]);
        $applications = $this->_crm_list('crmApplications', [['session', '==', $session]]);
        $waitlist     = $this->_crm_list('crmWaitlist',     [['session', '==', $session]]);
        if (!is_array($inquiries)) $inquiries=[]; if (!is_array($applications)) $applications=[]; if (!is_array($waitlist)) $waitlist=[];
        $sInq = array_filter($inquiries, fn($i) => is_array($i)&&($i['session']??'')===$session);
        $sApp = array_filter($applications, fn($a) => is_array($a)&&($a['session']??'')===$session);
        $sWl  = array_filter($waitlist, fn($w) => is_array($w)&&($w['session']??'')===$session);
        $funnel = ['inquiries'=>count($sInq),'applications'=>count($sApp),'approved'=>count(array_filter($sApp,fn($a)=>($a['status']??'')==='approved')),'enrolled'=>count(array_filter($sApp,fn($a)=>($a['status']??'')==='enrolled')),'rejected'=>count(array_filter($sApp,fn($a)=>($a['status']??'')==='rejected')),'waitlisted'=>count($sWl)];
        $sources = []; foreach ($sInq as $i) { $s=$i['source']??'Walk-in'; $sources[$s]=($sources[$s]??0)+1; }
        $classes = []; foreach ($sApp as $a) { $c=$a['class']??'Unknown'; $st=$a['status']??'pending'; if (!isset($classes[$c])) $classes[$c]=['total'=>0,'approved'=>0,'enrolled'=>0,'pending'=>0,'rejected'=>0]; $classes[$c]['total']++; if (isset($classes[$c][$st])) $classes[$c][$st]++; }
        $monthly = []; foreach ($sInq as $i) { $m=substr($i['created_at']??'',0,7); if ($m) $monthly[$m]=($monthly[$m]??0)+1; } ksort($monthly);
        return $this->json_success(['funnel'=>$funnel,'sources'=>$sources,'classes'=>$classes,'monthly'=>$monthly]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       ADDITIONAL PRIVATE HELPERS
       Merged from Student.php and Admission_crm.php
    ══════════════════════════════════════════════════════════════════════ */

    private function _generatePdfThumbnail($pdfTmpPath, $storagePath, $label, $timestamp, $random)
    {
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
        $placeholder = FCPATH . 'tools/image/pdf.png';
        if (file_exists($placeholder)) {
            $thumbPath = $storagePath . "thumbnail/{$label}_{$timestamp}_{$random}.png";
            if ($this->firebase->uploadFile($placeholder, $thumbPath) === true) {
                return $this->firebase->getDownloadUrl($thumbPath);
            }
        }
        return '';
    }

    private function _deleteOldStorageFile($docNode)
    {
        if (!is_array($docNode)) $docNode = ['url' => (string)$docNode, 'thumbnail' => ''];
        foreach (['url', 'thumbnail'] as $key) {
            $url = $docNode[$key] ?? '';
            if (!empty($url)) {
                $path = $this->_extractStoragePathFromUrl($url);
                if ($path) $this->CM->delete_file_from_firebase($path);
            }
        }
    }

    private function _extractStoragePathFromUrl($url)
    {
        if (empty($url)) return '';
        if (preg_match('#/o/([^?]+)#', $url, $matches)) return urldecode($matches[1]);
        return '';
    }

    private function _getFees($className, $section)
    {
        // Read fee structure from Firestore (docId includes session).
        $feeDocId = $this->fs->sectionDocId($className, $section);
        $feeDoc = $this->fs->get('feeStructures', $feeDocId);
        if (!is_array($feeDoc) || empty($feeDoc)) {
            return json_encode(["fees"=>[],"monthlyTotals"=>[]]);
        }

        $formattedFees = [];

        // Canonical 2026+ schema: a flat `feeHeads` array, each item
        // carrying { name, amount, frequency: 'monthly'|'annual' }.
        // Project monthly heads across the 12 academic-year months
        // (Apr–Mar) and bucket annual heads under the "Yearly Fees"
        // key, so the existing student-profile view — which expects
        // a { 'Yearly Fees' | <MonthName> => { title => amount } }
        // map — renders correctly without any view changes.
        if (!empty($feeDoc['feeHeads']) && is_array($feeDoc['feeHeads'])) {
            $months = ['April','May','June','July','August','September',
                       'October','November','December','January','February','March'];
            foreach ($feeDoc['feeHeads'] as $head) {
                if (!is_array($head)) continue;
                $name = trim((string)($head['name'] ?? ''));
                if ($name === '') continue;
                $amt  = (float)($head['amount'] ?? 0);
                $freq = strtolower((string)($head['frequency'] ?? 'monthly'));
                if ($freq === 'annual' || $freq === 'yearly') {
                    $formattedFees['Yearly Fees'][$name] = $amt;
                } else {
                    foreach ($months as $m) {
                        $formattedFees[$m][$name] = $amt;
                    }
                }
            }
        } else {
            // Pre-2026 legacy shape: heads.<Month>.<Title> = amount, or
            // the same map at the doc root. Retained so a school that
            // hasn't yet been migrated to the feeHeads schema still
            // renders.
            $feesData = $feeDoc['heads'] ?? $feeDoc;
            if (is_array($feesData)) {
                foreach ($feesData as $month => $fees) {
                    if (is_array($fees)) {
                        $formattedFees[$month] = $fees;
                    }
                }
            }
        }

        $monthlyTotals = [];
        foreach ($formattedFees as $month => $row) {
            $monthlyTotals[$month] = array_sum(array_map('floatval', $row));
        }
        return json_encode([
            "fees"          => $formattedFees,
            "monthlyTotals" => $monthlyTotals,
            "overallTotal"  => array_sum($monthlyTotals),
        ]);
    }

    private function _getSundays($year, $month)
    {
        $sundays = [];
        $date = new DateTime("$year-$month-01");
        while ($date->format('n') == $month) {
            if ($date->format('w') == 0) $sundays[] = (int)$date->format('j');
            $date->modify('+1 day');
        }
        return $sundays;
    }

    private function _default_stages()
    {
        return [
            'document_collection' => 'Document Collection',
            'under_review'        => 'Under Review',
            'interview'           => 'Interview / Test',
            'approved'            => 'Approved',
            'rejected'            => 'Rejected',
            'waitlisted'          => 'Waitlisted',
        ];
    }

    private function _get_crm_classes()
    {
        // Deduplicate by className. The CRM application form decides
        // section at enrollment time (via the section picker), not at
        // submission, so the dropdown should list each class exactly
        // once. Earlier this returned one entry per (class, section)
        // pair which made the Edit modal show "Class 8th / Section A"
        // for an unenrolled application.
        $sectionDocs = $this->fs->schoolWhere('sections', []);
        $seen = [];
        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'];
            $className = $sd['className'] ?? '';
            if ($className && !isset($seen[$className])) {
                $seen[$className] = true;
            }
        }
        $names = array_keys($seen);
        // Natural sort: "Class 1st", "Class 2nd", ... "Class 10th".
        usort($names, 'strnatcmp');

        $classes = [];
        foreach ($names as $cn) {
            $classes[] = [
                'class_name' => $cn,
                'section'    => '',     // resolved later, at enroll time
                'label'      => $cn,    // dropdown text — just the class
            ];
        }
        return $classes;
    }
}
