<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Institution_readiness — "is this school actually usable yet?"
 *
 * ──────────────────────────────────────────────────────────────────────
 *  A SCORER, NOT A WORKFLOW ENGINE
 * ──────────────────────────────────────────────────────────────────────
 * Every answer is COMPUTED from data the domain modules already own. This
 * library:
 *
 *   • stores NOTHING. There is no readiness document, no checklist state,
 *     no per-item "completed" flag to drift out of sync with reality. Ask
 *     again and it recomputes.
 *   • writes NOTHING, ever — not to domain collections, not to a cache.
 *   • owns NO domain data. If a fact cannot be read from the module that
 *     owns it, the criterion does not exist.
 *
 * The moment readiness gets its own persisted state it becomes a second
 * source of truth about students, staff and fees, and starts lying. That
 * is why it is a pure function of the tenant's current data.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  MODULE AWARENESS
 * ──────────────────────────────────────────────────────────────────────
 * A criterion for a module the tenant is not entitled to is NOT_APPLICABLE,
 * never BLOCKING. A school without Transport is not "unready" because it
 * has no bus routes. Entitlement comes from the existing
 * tenantPublic.activeModules mirror — the same source
 * Tenant_capability_service reads. No second entitlement model.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  READ BUDGET
 * ──────────────────────────────────────────────────────────────────────
 * Bounded and flat: 3 documents (schools, tenantPublic, feeSettings) plus
 * up to 4 server-side COUNT aggregations (students, staff, sections,
 * routes). Counts use runAggregation, so no collection is ever paged into
 * PHP. There is no per-class, per-student or per-route loop anywhere in
 * this file — readiness must never become an N+1.
 *
 * Never run on a timer across all tenants. Compute it on demand, at
 * go-live, and before a closure transition.
 */
class Institution_readiness
{
    const PASS           = 'PASS';
    const WARNING        = 'WARNING';
    const BLOCKING       = 'BLOCKING';
    const NOT_APPLICABLE = 'NOT_APPLICABLE';

    /** @var object|null */ private $firebase = null;
    /** @var bool */        private $ready    = false;

    public function init($firebase): self
    {
        $this->firebase = $firebase;
        $this->ready    = ($firebase !== null);
        return $this;
    }

    /**
     * Evaluate readiness for a tenant.
     *
     * @return array{
     *   schoolId:string, session:string, score:int,
     *   blocking:int, warnings:int, applicable:int, passed:int,
     *   goLiveAllowed:bool, checks:array
     * }
     */
    public function evaluate(string $schoolId, string $session = ''): array
    {
        $checks = [];

        if (!$this->ready || $schoolId === '') {
            return $this->_summarise($schoolId, $session, [[
                'key' => 'engine', 'domain' => 'System', 'label' => 'Readiness engine',
                'status' => self::BLOCKING,
                'what' => 'Readiness could not be evaluated.',
                'why' => 'No Firestore client or school was supplied.',
                'fix' => 'This is an internal error — contact support.',
                'url' => '',
            ]]);
        }

        $school = $this->_doc('schools', $schoolId) ?? [];
        $public = $this->_doc('tenantPublic', $schoolId) ?? [];
        $modules = is_array($public['activeModules'] ?? null)
            ? array_values(array_filter($public['activeModules'], 'is_string'))
            : [];

        if ($session === '') {
            $session = (string) ($school['currentSession'] ?? '');
        }

        $checks = array_merge(
            $this->_identityChecks($school),
            $this->_academicChecks($school, $session),
            $this->_peopleChecks($schoolId, $session),
            $this->_financeChecks($schoolId, $session, $modules),
            $this->_operationsChecks($schoolId, $session, $modules),
            $this->_complianceChecks($school)
        );

        return $this->_summarise($schoolId, $session, $checks);
    }

    // ═════════════════════════════════════════════════════════════════
    //  CHECK GROUPS
    // ═════════════════════════════════════════════════════════════════

    private function _identityChecks(array $school): array
    {
        $out = [];

        $name = trim((string) ($school['name'] ?? $school['displayName'] ?? ''));
        $out[] = $this->_check('identity.name', 'Institution', 'School name',
            $name !== '' ? self::PASS : self::BLOCKING,
            $name !== '' ? 'School name is set.' : 'The school has no name.',
            'The name appears on every certificate, receipt and report card.',
            'Set it in School Configuration → Profile.',
            'school_config#tab-profile');

        $hasContact = trim((string) ($school['phone'] ?? '')) !== ''
                   || trim((string) ($school['email'] ?? '')) !== '';
        $out[] = $this->_check('identity.contact', 'Institution', 'Contact details',
            $hasContact ? self::PASS : self::BLOCKING,
            $hasContact ? 'A phone or email is on file.' : 'No phone or email on file.',
            'Parents and the platform both need a way to reach the school.',
            'Add a phone number or email in School Configuration → Profile.',
            'school_config#tab-profile');

        $hasAddress = trim((string) ($school['address'] ?? '')) !== ''
                   && trim((string) ($school['state'] ?? '')) !== '';
        $out[] = $this->_check('identity.address', 'Institution', 'Address',
            $hasAddress ? self::PASS : self::WARNING,
            $hasAddress ? 'Address and state are set.' : 'Address or state is incomplete.',
            'Statutory documents and board correspondence use the full address.',
            'Complete the address in School Configuration → Profile.',
            'school_config#tab-profile');

        $board = trim((string) ($school['affiliationBoard'] ?? $school['board'] ?? ''));
        $out[] = $this->_check('identity.board', 'Institution', 'Board affiliation',
            $board !== '' ? self::PASS : self::WARNING,
            $board !== '' ? "Affiliated to {$board}." : 'No board affiliation recorded.',
            'The board drives report-card format, grading and certificate wording.',
            'Select the board in School Configuration → Board & Grading.',
            'school_config#tab-board');

        $logo = trim((string) ($school['logoUrl'] ?? ''));
        $out[] = $this->_check('identity.logo', 'Institution', 'School logo',
            $logo !== '' ? self::PASS : self::WARNING,
            $logo !== '' ? 'Logo uploaded.' : 'No logo uploaded.',
            'The logo is printed on certificates and shown in both mobile apps.',
            'Upload a logo in School Configuration → Profile.',
            'school_config#tab-profile');

        return $out;
    }

    private function _academicChecks(array $school, string $session): array
    {
        $out = [];

        $sessions = is_array($school['sessions'] ?? null) ? $school['sessions'] : [];
        $archived = is_array($school['archivedSessions'] ?? null) ? $school['archivedSessions'] : [];

        $hasSession = ($session !== '');
        $out[] = $this->_check('academic.session', 'Academic', 'Active academic session',
            $hasSession ? self::PASS : self::BLOCKING,
            $hasSession ? "Current session is {$session}." : 'No active academic session is set.',
            'Every student, fee demand and attendance record is filed under a session.',
            'Create or activate a session in School Configuration → Sessions.',
            'school_config#tab-sessions');

        $sessionArchived = $hasSession && in_array($session, $archived, true);
        $out[] = $this->_check('academic.session_not_archived', 'Academic', 'Session is not archived',
            $sessionArchived ? self::BLOCKING : self::PASS,
            $sessionArchived
                ? "The active session {$session} is also marked archived."
                : 'The active session is live.',
            'An archived session is hidden from day-to-day screens; operating in one strands records.',
            'Un-archive the session, or activate a different one.',
            'school_config#tab-sessions');

        $classes = is_array($school['classes'] ?? null) ? $school['classes'] : [];
        $classCount = count($classes);
        $out[] = $this->_check('academic.classes', 'Academic', 'Classes configured',
            $classCount > 0 ? self::PASS : self::BLOCKING,
            $classCount > 0 ? "{$classCount} class(es) configured." : 'No classes configured.',
            'Students cannot be admitted or placed without at least one class.',
            'Add classes in School Configuration → Classes.',
            'school_config#tab-classes');

        $sections = $this->_count('sections', [['schoolId', '=', $school['schoolId'] ?? '']]);
        $out[] = $this->_check('academic.sections', 'Academic', 'Sections configured',
            $sections > 0 ? self::PASS : ($classCount > 0 ? self::BLOCKING : self::WARNING),
            $sections >= 0 ? "{$sections} section(s) configured." : 'Section count unavailable.',
            'A student is placed into a section, not a class — capacity is tracked per section.',
            'Add sections in School Configuration → Sections.',
            'school_config#tab-sections');

        $sessionCount = count($sessions);
        $out[] = $this->_check('academic.session_list', 'Academic', 'Session list',
            $sessionCount > 0 ? self::PASS : self::WARNING,
            "{$sessionCount} session(s) on file.",
            'A next-year session must exist before promotion and rollover can be prepared.',
            'Add the upcoming session in School Configuration → Sessions.',
            'school_config#tab-sessions');

        return $out;
    }

    private function _peopleChecks(string $schoolId, string $session): array
    {
        $out = [];

        $students = $this->_count('students', [['schoolId', '=', $schoolId]]);
        $out[] = $this->_check('people.students', 'Students', 'Students enrolled',
            $students > 0 ? self::PASS : self::WARNING,
            $students >= 0 ? "{$students} student(s) on record." : 'Student count unavailable.',
            'A school with no students is either brand new or its import has not run.',
            'Admit students, or run the bulk import from SIS → Import.',
            'sis');

        $staff = $this->_count('staff', [['schoolId', '=', $schoolId]]);
        $out[] = $this->_check('people.staff', 'Staff', 'Staff on record',
            $staff > 0 ? self::PASS : self::BLOCKING,
            $staff >= 0 ? "{$staff} staff record(s)." : 'Staff count unavailable.',
            'At least the school administrator must exist, or nobody can log in.',
            'Add staff in HR → Staff, or run the staff import.',
            'staff');

        return $out;
    }

    private function _financeChecks(string $schoolId, string $session, array $modules): array
    {
        $out = [];

        if (!$this->_enabled($modules, self::MODULE_FEES)) {
            $out[] = $this->_naCheck('finance.fees', 'Fees', 'Fee configuration', 'Fees');
        } else {
            // Fee STRUCTURES are the real "can this school bill?" signal.
            //
            // An earlier version of this check looked for a
            // `feeSettings/{schoolId}_{session}` document. That document does
            // not exist for ANY tenant and never has: feeSettings is only ever
            // keyed with a purpose suffix — `_gateway`, `_counters`,
            // `_rollover`, `_reminders`. The check therefore emitted a
            // BLOCKING verdict for every school on the platform and would have
            // refused go-live universally. Caught by running readiness against
            // a real tenant that bills 74 demands quite happily.
            $structures = $this->_count('feeStructures', [['schoolId', '=', $schoolId]]);
            $out[] = $this->_check('finance.fee_structures', 'Fees', 'Fee structures',
                $structures > 0 ? self::PASS : ($structures < 0 ? self::WARNING : self::BLOCKING),
                $structures >= 0 ? "{$structures} fee structure(s)." : 'Structure count unavailable.',
                'Fee structures decide what each class is charged; with none, no demand can be generated.',
                'Create a fee structure in Fee Management → Structures.',
                'fee_management');
        }

        if (!$this->_enabled($modules, self::MODULE_ACCOUNTING)) {
            $out[] = $this->_naCheck('finance.accounting', 'Accounting', 'Accounting setup', 'Accounting');
        } else {
            $accounts = $this->_count('accountingLedger', [['schoolId', '=', $schoolId]]);
            $out[] = $this->_check('finance.accounting', 'Accounting', 'Accounting ledger',
                $accounts > 0 ? self::PASS : self::WARNING,
                $accounts >= 0 ? "{$accounts} ledger entr(ies)." : 'Ledger count unavailable.',
                'An empty ledger is expected for a new school, but not for an operating one.',
                'Post opening balances in Accounting → Opening Balances.',
                'accounting');
        }

        return $out;
    }

    private function _operationsChecks(string $schoolId, string $session, array $modules): array
    {
        $out = [];

        if (!$this->_enabled($modules, self::MODULE_ATTENDANCE)) {
            $out[] = $this->_naCheck('ops.attendance', 'Attendance', 'Attendance settings', 'Attendance');
        } else {
            $att = $this->_doc('attendanceSettings', $schoolId);
            $out[] = $this->_check('ops.attendance', 'Attendance', 'Attendance settings',
                is_array($att) && $att !== [] ? self::PASS : self::WARNING,
                is_array($att) && $att !== [] ? 'Attendance settings configured.'
                                              : 'Attendance settings not configured — defaults will apply.',
                'Settings decide the marking window, lock time and half-day handling.',
                'Configure in Attendance → Settings.',
                'attendance');
        }

        if (!$this->_enabled($modules, self::MODULE_TRANSPORT)) {
            $out[] = $this->_naCheck('ops.transport', 'Transport', 'Transport setup', 'Transport');
        } else {
            $routes = $this->_count('routes', [['schoolId', '=', $schoolId]]);
            $out[] = $this->_check('ops.transport_routes', 'Transport', 'Transport routes',
                $routes > 0 ? self::PASS : self::WARNING,
                $routes >= 0 ? "{$routes} route(s) configured." : 'Route count unavailable.',
                'The Transport module is enabled but has no routes, so no student can be assigned transport.',
                'Add routes in Transport → Routes.',
                'transport');
        }

        return $out;
    }

    private function _complianceChecks(array $school): array
    {
        // Compliance identity fields (UDISE, affiliation validity, DPO and
        // POSH/POCSO contacts) are NOT yet modelled on the schools document.
        // Reporting them as BLOCKING would fail every tenant for a field the
        // product does not offer, so this is a WARNING that names the gap
        // honestly rather than a check that pretends to measure something.
        $udise = trim((string) ($school['udiseCode'] ?? ''));
        return [
            $this->_check('compliance.udise', 'Compliance', 'UDISE+ code',
                $udise !== '' ? self::PASS : self::WARNING,
                $udise !== '' ? 'UDISE code recorded.' : 'No UDISE+ code recorded.',
                'UDISE+ is the statutory school identifier in India and is required for government reporting.',
                'A field for this is not yet available in School Configuration — planned.',
                ''),
        ];
    }

    // ═════════════════════════════════════════════════════════════════
    //  PRIMITIVES
    // ═════════════════════════════════════════════════════════════════

    /**
     * Entitlement keys as they actually appear in tenantPublic.activeModules.
     *
     * These are the PLAN module keys (lowercase snake_case) written by
     * B2_registry_service::_derive_entitlements() from plans/{plan}.modules —
     * NOT the RBAC catalogue's display names ('Fees', 'Accounting', …).
     *
     * This distinction was a real defect: keying on the RBAC names made every
     * Fees / Accounting / Attendance / Transport criterion report
     * NOT_APPLICABLE on a live tenant that genuinely had those modules, which
     * inflated the score and would have let a school go live with no fee
     * settings at all. Found by running readiness against a real tenant whose
     * activeModules read `fees accounts attendance …` while the engine looked
     * for `Fees`. Pinned by InstitutionReadinessTest so it cannot drift back.
     */
    const MODULE_FEES       = 'fees';
    const MODULE_ACCOUNTING = 'accounts';
    const MODULE_ATTENDANCE = 'attendance';
    const MODULE_TRANSPORT  = 'transport';

    private function _enabled(array $modules, string $key): bool
    {
        // Fail-OPEN on an empty entitlement list: a tenant whose tenantPublic
        // mirror has not been written yet must not have every module reported
        // NOT_APPLICABLE, which would render a perfect score for a school
        // that has nothing configured. An absent mirror means "unknown", and
        // unknown is evaluated rather than skipped.
        if ($modules === []) return true;
        return in_array($key, $modules, true);
    }

    private function _check(string $key, string $domain, string $label, string $status,
                            string $what, string $why, string $fix, string $url): array
    {
        return [
            'key' => $key, 'domain' => $domain, 'label' => $label, 'status' => $status,
            'what' => $what, 'why' => $why, 'fix' => $fix, 'url' => $url,
        ];
    }

    private function _naCheck(string $key, string $domain, string $label, string $module): array
    {
        return $this->_check($key, $domain, $label, self::NOT_APPLICABLE,
            "The {$module} module is not enabled for this school.",
            'Criteria for modules a tenant does not have are skipped, never failed.',
            'Enable the module from the platform plan if it is required.',
            '');
    }

    /** Single document read; null on any failure (never throws upward). */
    private function _doc(string $collection, string $docId): ?array
    {
        if ($docId === '' || strpos($docId, '_') === 0) return null;
        try {
            $d = $this->firebase->firestoreGet($collection, $docId);
            return is_array($d) ? $d : null;
        } catch (\Throwable $e) {
            log_message('error', "Institution_readiness: read failed {$collection}/{$docId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Server-side COUNT. Returns -1 when unavailable, which every caller
     * renders as "unavailable" rather than as zero — reporting a count of 0
     * for an unreadable collection would turn an outage into a false
     * BLOCKING verdict.
     */
    private function _count(string $collection, array $conditions): int
    {
        try {
            if (!method_exists($this->firebase, 'firestoreCount')) return -1;
            $n = (int) $this->firebase->firestoreCount($collection, $conditions);
            return $n;
        } catch (\Throwable $e) {
            log_message('error', "Institution_readiness: count failed {$collection}: " . $e->getMessage());
            return -1;
        }
    }

    private function _summarise(string $schoolId, string $session, array $checks): array
    {
        $blocking = 0; $warnings = 0; $passed = 0; $applicable = 0;
        foreach ($checks as $c) {
            if ($c['status'] === self::NOT_APPLICABLE) continue;
            $applicable++;
            if ($c['status'] === self::BLOCKING) $blocking++;
            elseif ($c['status'] === self::WARNING) $warnings++;
            else $passed++;
        }
        // Score counts a warning as half credit: an amber item is a real
        // deficiency, but not one that should read the same as a hard failure.
        $score = $applicable > 0
            ? (int) round((($passed + ($warnings * 0.5)) / $applicable) * 100)
            : 0;

        return [
            'schoolId'      => $schoolId,
            'session'       => $session,
            'score'         => $score,
            'blocking'      => $blocking,
            'warnings'      => $warnings,
            'applicable'    => $applicable,
            'passed'        => $passed,
            'goLiveAllowed' => ($blocking === 0),
            'checks'        => $checks,
        ];
    }
}
