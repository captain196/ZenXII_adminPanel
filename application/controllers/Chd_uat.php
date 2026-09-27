<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CALENDAR HALF-DAY — end-to-end UAT harness.
 *
 * Follows the established runtime-verifier convention in this repo
 * (B2_runtime_verify, *_probe_*): extends CI_Controller so it carries no
 * session/auth dependency of its own, and refuses to run outside CLI.
 *
 * ── HOW IT REACHES THE REAL PAYROLL ENGINE ───────────────────────────
 * It establishes the session context MY_Controller reads (school_id,
 * admin_role, session year, …) and then instantiates the REAL Hr
 * controller. Payroll therefore runs through the genuine
 * generate_payroll() path — same RBAC gate, same Firestore service, same
 * calculation — not a reimplementation.
 *
 * ── DATA SAFETY ──────────────────────────────────────────────────────
 * Every write from THIS file goes through guardedSet()/guardedUpdate(),
 * which refuse unless the target is exactly UAT_SCHOOL. The session is
 * pinned to UAT_SCHOOL, so the Hr controller itself can only ever write
 * there too. Reads are unrestricted (read-only inspection is how tenant
 * isolation gets proven).
 *
 * Usage:
 *   php index.php chd_uat recon        read-only context + flag state
 *   php index.php chd_uat schools      read-only school discovery
 *   php index.php chd_uat isolation    prove non-UAT writes are refused
 *   php index.php chd_uat setup        create disposable UAT fixtures
 *   php index.php chd_uat baseline     generate payroll, flag OFF
 *   php index.php chd_uat enable       turn the flag ON with an effective date
 *   php index.php chd_uat disable      turn the flag OFF again
 *   php index.php chd_uat scenarios    run the Calendar Half-Day matrix
 *   php index.php chd_uat teardown     remove every UAT fixture
 */
class Chd_uat extends CI_Controller
{
    /** The ONLY school this harness may write to. */
    private const UAT_SCHOOL  = 'SCH_B56BB9A401';
    private const UAT_STAFF   = 'ZZUAT001';
    private const UAT_STAFF_2 = 'ZZUAT002';
    private const UAT_SCHED   = 'WS_ZZUAT';
    private const UAT_DEPT    = 'ZZ UAT Department';
    private const UAT_YEAR    = 2026;
    private const UAT_MONTH   = 'April';       // 30 days, 4 Sundays => 26 working
    private const UAT_MONTHNUM= 4;
    private const HALF_DAY_DOM= 15;            // day-of-month declared a half day

    private int $writes = 0;
    private int $refused = 0;

    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) { exit("CLI only\n"); }
        $this->load->library('session');
    }

    /**
     * Firebase accessor bound to the CURRENT CI super-object.
     *
     * Instantiating a second controller (Hr) makes it the CI instance, and
     * CI3's loader cannot re-load a library for it — it looks for a
     * `CI_Firebase` class that does not exist. Resolving through
     * get_instance() each time means this works both before Hr is created
     * (instance = Chd_uat) and after (instance = Hr), with one shared
     * library object either way.
     */
    private function fb()
    {
        $CI =& get_instance();
        if (!isset($CI->firebase)) $CI->load->library('firebase');
        return $CI->firebase;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Guarded write primitives
    // ─────────────────────────────────────────────────────────────────

    private function assertUatSchool(string $schoolId, string $what): bool
    {
        if ($schoolId === self::UAT_SCHOOL) return true;
        $this->refused++;
        printf("  REFUSED WRITE  %-42s schoolId='%s' != %s\n", $what, $schoolId, self::UAT_SCHOOL);
        return false;
    }

    private function guardedSet(string $schoolId, string $collection, string $docId, array $data, bool $merge = true): bool
    {
        if (!$this->assertUatSchool($schoolId, "{$collection}/{$docId}")) return false;
        $ok = $this->fb()->firestoreSet($collection, $docId, $data, $merge);
        if ($ok) $this->writes++;
        return (bool) $ok;
    }

    private function guardedUpdate(string $schoolId, string $collection, string $docId, array $data): bool
    {
        if (!$this->assertUatSchool($schoolId, "{$collection}/{$docId}")) return false;
        $ok = $this->fb()->firestoreUpdate($collection, $docId, $data);
        if ($ok) $this->writes++;
        return (bool) $ok;
    }

    private function guardedDelete(string $schoolId, string $collection, string $docId): bool
    {
        if (!$this->assertUatSchool($schoolId, "{$collection}/{$docId}")) return false;
        $ok = $this->fb()->firestoreDelete($collection, $docId);
        if ($ok) $this->writes++;
        return (bool) $ok;
    }

    private function d(string $id): string { return self::UAT_SCHOOL . '_' . $id; }

    // ─────────────────────────────────────────────────────────────────
    //  Session context so the REAL Hr controller can run
    // ─────────────────────────────────────────────────────────────────

    private function pinSessionToUatSchool(): void
    {
        $this->session->set_userdata([
            'admin_id'            => 'ZZUAT_RUNNER',
            'admin_name'          => 'CHD UAT Runner',
            'admin_role'          => 'Super Admin',   // RBAC_BYPASS_ROLES
            'school_id'           => self::UAT_SCHOOL,
            'schoolName'          => self::UAT_SCHOOL,
            'school_code'         => self::UAT_SCHOOL,
            'school_display_name' => 'CHD UAT',
            'session'             => '2026-27',
            'school_features'     => [],
            'available_sessions'  => ['2026-27'],
            'logged_in'           => true,
        ]);
    }

    /**
     * The REAL Hr controller, bound to the UAT school.
     *
     * `new Hr()` cannot be used: CI3 re-runs autoload for a second
     * controller and collides on the autoloaded Firebase library
     * ("Class CI_Firebase not found"). So the instance is created WITHOUT
     * the constructor and its dependencies are injected explicitly from the
     * live CI super-object.
     *
     * FIDELITY CAVEAT — stated plainly: this skips MY_Controller's
     * constructor, so its session-freeze / period-lock / capability
     * preamble does not run. The payroll CALCULATION path executed below
     * is the genuine one; the request preamble around it is not exercised.
     */
    private function hr()
    {
        static $hr = null;
        if ($hr !== null) return $hr;

        $this->pinSessionToUatSchool();
        require_once APPPATH . 'controllers/Hr.php';

        $CI =& get_instance();
        $rc  = new ReflectionClass('Hr');
        $hr  = $rc->newInstanceWithoutConstructor();

        // CI framework components, shared with the live super-object.
        foreach (['load', 'input', 'config', 'session', 'security', 'uri', 'output', 'lang', 'benchmark'] as $c) {
            if (isset($CI->$c)) $hr->$c = $CI->$c;
        }
        $hr->firebase = $this->fb();

        // Helpers the skipped constructors would have loaded. Without these
        // the real payroll path dies on hr_next_id()/log_audit().
        foreach (['url', 'rbac', 'capability', 'audit', 'recruitment', 'hr_id'] as $h) {
            $CI->load->helper($h);
        }

        // Static service classes: CI's loader cannot resolve static calls,
        // so production code require_once's these at their call sites. The
        // payroll path reaches them through helpers that assume they are
        // already present.
        foreach (['Policy_assignment_service', 'Working_calendar_service',
                  'Work_schedule_service', 'Leave_policy_service', 'Leave_year_service'] as $cls) {
            $f = APPPATH . 'libraries/' . $cls . '.php';
            if (is_file($f)) require_once $f;
        }

        // Firestore service, pinned to the UAT school.
        $CI->load->library('firestore_service', null, 'uatfs');
        $hr->fs = $CI->uatfs->init(self::UAT_SCHOOL, '2026-27', self::UAT_SCHOOL);

        // MY_Controller identity properties (protected — set reflectively).
        $props = [
            'school_id'           => self::UAT_SCHOOL,
            'school_name'         => self::UAT_SCHOOL,
            'school_code'         => self::UAT_SCHOOL,
            'school_display_name' => 'CHD UAT',
            'session_year'        => '2026-27',
            'admin_id'            => 'ZZUAT_RUNNER',
            'admin_name'          => 'CHD UAT Runner',
            'admin_role'          => 'Super Admin',
        ];
        foreach ($props as $name => $val) {
            try {
                $rp = new ReflectionProperty('MY_Controller', $name);
                $rp->setAccessible(true);
                $rp->setValue($hr, $val);
            } catch (\Throwable $e) { /* property may not exist */ }
        }

        // ── Make Hr the CI super-object ──────────────────────────────
        // Without this, every runtime `$this->load->library(...)` inside
        // generate_payroll() assigns to get_instance() — which would still
        // be Chd_uat — leaving $hr->staff_employment_history_service
        // undefined. Its try/catch then swallows the error and the
        // effective-dating resolution (the thing under test) is SILENTLY
        // SKIPPED. Pointing the static instance at $hr makes library
        // wiring behave exactly as it does under a real request.
        $ri = new ReflectionProperty('CI_Controller', 'instance');
        $ri->setAccessible(true);
        $ri->setValue(null, $hr);

        // Hard assertion — refuse to proceed unless bound to the UAT school.
        $rp = new ReflectionProperty('MY_Controller', 'school_id');
        $rp->setAccessible(true);
        $resolved = (string) $rp->getValue($hr);
        if ($resolved !== self::UAT_SCHOOL) {
            exit("ABORT — Hr school_id='{$resolved}', expected " . self::UAT_SCHOOL . "\n");
        }
        if ($hr->fs->schoolId() !== self::UAT_SCHOOL) {
            exit("ABORT — fs schoolId='" . $hr->fs->schoolId() . "', expected " . self::UAT_SCHOOL . "\n");
        }
        printf("  [Hr bound to %s, fs=%s]\n", $resolved, $hr->fs->schoolId());
        return $hr;
    }

    /** Calendar caches are TTL-based; bust them so fixtures take effect now. */
    private function bustCalendarCache(): void
    {
        $dir = APPPATH . 'cache/holidays/';
        $n = 0;
        foreach ((array) @glob($dir . 'hol_*.json') as $f) { @unlink($f); $n++; }
        printf("  calendar cache files cleared: %d\n", $n);
    }

    // ─────────────────────────────────────────────────────────────────
    //  STEP 1-3 — reconnaissance
    // ─────────────────────────────────────────────────────────────────

    public function recon(): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nCALENDAR HALF-DAY UAT — RECONNAISSANCE (read-only)\nTarget school: {$S}\n";
        echo str_repeat('=', 92) . "\n\n";

        echo "STEP 1 — school context\n";
        $school = $this->readSchool($S);
        if ($school === null) { echo "  FAIL  schools/{$S} unreadable.\n\n"; return; }
        printf("  PASS  school doc found            name=%s\n", $school['name'] ?? '(unnamed)');
        printf("        schoolId field               %s\n", $school['schoolId'] ?? '(absent)');
        printf("        session                      %s\n", $school['currentSession'] ?? '(unknown)');

        echo "\nSTEP 3 — Calendar Half-Day flag (must start OFF)\n";
        $pol = is_array($school['attendancePolicy'] ?? null) ? $school['attendancePolicy'] : [];
        printf("  %s  calendarHalfDayPayEnabled    %s\n",
            !empty($pol['calendarHalfDayPayEnabled']) ? 'NOTE' : 'PASS',
            var_export(!empty($pol['calendarHalfDayPayEnabled']), true));
        printf("        effectiveFrom                '%s'\n", (string) ($pol['calendarHalfDayPayEffectiveFrom'] ?? ''));
        printf("        workSchedules[]              %d\n", count((array) ($pol['workSchedules'] ?? [])));
        printf("        workingWeek.versions[]       %d\n", count((array) ($pol['workingWeek']['versions'] ?? [])));
        printf("        weeklyOffs                   %s\n", implode(',', (array) ($pol['weeklyOffs'] ?? [])));

        echo "\nPREREQUISITES\n";
        $this->countProbe('staff',                    [['schoolId', '==', $S]], 'staff records');
        $this->countProbe('staffScheduleAssignments', [['schoolId', '==', $S]], 'schedule assignments');
        $this->countProbe('salarySlips',              [['schoolId', '==', $S]], 'salarySlips docs');
        $this->countProbe('calendarEvents',           [['schoolId', '==', $S]], 'calendarEvents');
        $this->countProbe('staffAttendanceSummary',   [['schoolId', '==', $S]], 'attendance summaries');
        echo "\n" . str_repeat('=', 92) . "\n\n";
    }

    private function readSchool(string $S): ?array
    {
        try { $v = $this->fb()->firestoreGet('schools', $S); if (is_array($v) && $v) return $v; }
        catch (\Throwable $e) {}
        try {
            foreach ((array) $this->fb()->firestoreQuery('schools', [['schoolId', '==', $S]]) as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                if ((string) ($r['id'] ?? '') === $S) return $d;
            }
        } catch (\Throwable $e) {}
        return null;
    }

    public function schools(): void
    {
        echo "\nSCHOOLS COLLECTION — read-only discovery\n" . str_repeat('=', 92) . "\n\n";
        try { $rows = $this->fb()->firestoreQuery('schools', []); }
        catch (\Throwable $e) { echo "  ERROR: " . $e->getMessage() . "\n\n"; return; }
        printf("  %d document(s)\n\n", count((array) $rows));
        foreach ((array) $rows as $r) {
            $d  = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            $pol = is_array($d['attendancePolicy'] ?? null) ? $d['attendancePolicy'] : [];
            printf("  id=%-26s name=%-28s CHD=%s ws=%d\n",
                (string) ($r['id'] ?? '?'), substr((string) ($d['name'] ?? '-'), 0, 28),
                !empty($pol['calendarHalfDayPayEnabled']) ? 'ON' : 'off',
                count((array) ($pol['workSchedules'] ?? [])));
        }
        echo "\n";
    }

    private function countProbe(string $collection, array $where, string $label): void
    {
        try {
            $rows = $this->fb()->firestoreQuery($collection, $where);
            printf("  %-46s %d\n", $label, is_array($rows) ? count($rows) : 0);
        } catch (\Throwable $e) {
            printf("  %-46s ERROR %s\n", $label, substr($e->getMessage(), 0, 55));
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  STEP 20 — tenant isolation
    // ─────────────────────────────────────────────────────────────────

    public function isolation(): void
    {
        echo "\nTENANT ISOLATION — the write guard must refuse every non-UAT school\n";
        echo str_repeat('=', 92) . "\n\n";
        $probes = ['SCH_D94FE8F7AD', 'SCH_202512', 'SCH_000000', '',
                   self::UAT_SCHOOL . 'X', strtolower(self::UAT_SCHOOL)];
        foreach ($probes as $sid) {
            $ok = $this->guardedSet($sid, 'systemLogs', 'CHD_UAT_ISOLATION_PROBE', ['probe' => true]);
            printf("  %-30s -> %s\n", $sid === '' ? "''" : $sid, $ok ? 'WROTE (BAD)' : 'refused (good)');
        }
        echo "\n  Control — the UAT school must be ACCEPTED\n";
        printf("  %-30s -> %s\n", self::UAT_SCHOOL,
            $this->assertUatSchool(self::UAT_SCHOOL, 'control') ? 'accepted (good)' : 'REFUSED (BAD)');
        echo "\n" . str_repeat('=', 92) . "\n";
        printf("writes performed: %d   writes refused: %d\n", $this->writes, $this->refused);
        echo ($this->writes === 0 && $this->refused === 6)
            ? "ISOLATION PASS — no non-UAT write succeeded.\n\n" : "ISOLATION REVIEW REQUIRED.\n\n";
    }

    // ─────────────────────────────────────────────────────────────────
    //  Fixture setup — all disposable, all inside the UAT school
    // ─────────────────────────────────────────────────────────────────

    public function setup(): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nUAT FIXTURE SETUP — school {$S}\n" . str_repeat('=', 92) . "\n\n";

        // 1. Working week + a work schedule, written as DOTTED paths so the
        //    rest of attendancePolicy is untouched (the P0 data-loss fix).
        echo "1. attendance policy — working week + work schedule\n";
        $schedule = [
            'scheduleId' => self::UAT_SCHED,
            'name'       => 'ZZ UAT Standard',
            'weeklyOffs' => ['Sun'],
            'nthWeekdayOffs' => [],
            'version'        => 1,
            'effectiveFrom'  => '2026-01-01',
        ];
        $ok = $this->guardedUpdate($S, 'schools', $S, [
            'attendancePolicy.weeklyOffs'                 => ['Sun'],
            'attendancePolicy.workSchedules'              => [$schedule],
            'attendancePolicy.workingWeek.versions'       => [[
                'version' => 1, 'effectiveFrom' => '2026-01-01',
                'weeklyOffs' => ['Sun'], 'nthWeekdayOffs' => [],
            ]],
            'attendancePolicy.autoAbsent'                 => true,
            'attendancePolicy.allowWorkOnOff'             => false,
        ]);
        printf("   %s  weeklyOffs=Sun, 1 schedule, 1 working-week version\n", $ok ? 'OK  ' : 'FAIL');

        // 2. Organisation-level schedule assignment (covers every employee).
        echo "\n2. schedule assignment (organisation level)\n";
        $asgId = 'WSA_ZZUAT';
        $ok = $this->guardedSet($S, 'staffScheduleAssignments', $this->d($asgId), [
            'assignmentId'   => $asgId,
            'schoolId'       => $S,
            'attachmentType' => 'organisation',
            'attachmentId'   => $S,
            'policyId'       => self::UAT_SCHED,
            'policyType'     => 'work_schedule',
            'scheduleId'     => self::UAT_SCHED,
            'staffId'        => '',
            'effectiveFrom'  => '2026-01-01',
            'effectiveTo'    => null,
            'updatedAt'      => date('c'),
        ]);
        printf("   %s  %s -> %s\n", $ok ? 'OK  ' : 'FAIL', $asgId, self::UAT_SCHED);

        // 3. Two disposable UAT staff (second one proves department scoping).
        echo "\n3. UAT staff\n";
        foreach ([[self::UAT_STAFF, self::UAT_DEPT], [self::UAT_STAFF_2, 'ZZ UAT Other Dept']] as [$sid, $dept]) {
            $ok = $this->guardedSet($S, 'staff', $this->d($sid), [
                'schoolId'   => $S,
                'staffId'    => $sid,
                'userId'     => $sid,
                'Name'       => 'ZZ UAT ' . $sid,
                'name'       => 'ZZ UAT ' . $sid,
                'status'     => 'Active',
                'Department' => $dept,
                'department' => $dept,
                'Position'   => 'Teacher',
                'designation'=> 'Teacher',
                'joinDate'   => '2020-01-01',
                'session'    => '2026-27',
                'staff_roles'=> [],
                '_uat'       => true,
            ]);
            printf("   %s  %s  dept=%s\n", $ok ? 'OK  ' : 'FAIL', $sid, $dept);
        }

        // 4. Salary structures — 44,000 gross so figures match the design doc.
        echo "\n4. salary structures (gross 44,000)\n";
        foreach ([self::UAT_STAFF, self::UAT_STAFF_2] as $sid) {
            // ZZUAT002 carries NON-ZERO EMPLOYER statutory rates so the
            // regeneration fix for pf_employer / esi_employer is observable:
            // with 0% rates both the correct and the defective value are 0 and
            // a live run cannot tell them apart. Employer contributions are
            // employer COST, not employee deductions, so net pay is unaffected
            // and ZZUAT001's figures stay comparable with every other scenario.
            // BOTH staff now carry employer rates so every case in the final
            // matrix reports real employer PF/ESI. Employer contributions are
            // employer COST, not employee deductions, so gross and net are
            // unaffected and every previously recorded figure still compares.
            $employer = ['pf_employer' => 12, 'esi_employer' => 3.25];

            $ok = $this->guardedSet($S, 'salarySlips', $this->d("SAL_{$sid}"), array_merge([
                'schoolId' => $S, 'staffId' => $sid, 'type' => 'salary_structure',
                'basic' => 20000, 'hra' => 12000, 'da' => 8000, 'ta' => 4000,
                'medical' => 0, 'other_allowances' => 0,
                'pf_employee' => 0, 'esi_employee' => 0, 'professional_tax' => 0, 'tds' => 0,
                'other_deductions' => 0,
                'source' => 'uat', 'updatedAt' => date('c'), '_uat' => true,
            ], $employer));
            printf("   %s  SAL_%s%s\n", $ok ? 'OK  ' : 'FAIL', $sid,
                $employer['pf_employer'] ? '  (employer PF 12% / ESI 3.25%)' : '');
        }

        // 5. Calendar Half-Day on day 15, scoped to the UAT department only
        //    (so staff 2 proves department-specific applicability).
        echo "\n5. calendarEvents — Calendar Half-Day\n";
        $date = sprintf('%04d-%02d-%02d', self::UAT_YEAR, self::UAT_MONTHNUM, self::HALF_DAY_DOM);
        $ok = $this->guardedSet($S, 'calendarEvents', $this->d('CE_ZZUAT_HALFDAY'), [
            'schoolId'  => $S, 'type' => 'half_day', 'title' => 'ZZ UAT Founders Day (half)',
            'startDate' => $date, 'endDate' => $date,
            'appliesTo' => ['staff'], 'departments' => [self::UAT_DEPT], 'exceptStaff' => [],
            'status'    => 'active', 'updatedAt' => date('c'), '_uat' => true,
        ]);
        printf("   %s  half_day %s dept=%s\n", $ok ? 'OK  ' : 'FAIL', $date, self::UAT_DEPT);

        // 6. Attendance — present every working day (baseline: nothing uncovered).
        echo "\n6. attendance summaries (all present)\n";
        $this->writeAttendance(self::UAT_STAFF,   $this->allPresent());
        $this->writeAttendance(self::UAT_STAFF_2, $this->allPresent());

        $this->bustCalendarCache();
        echo "\n" . str_repeat('=', 92) . "\n";
        printf("writes: %d   refused: %d\n\n", $this->writes, $this->refused);
    }

    /** dayWise string: 'P' on every day (payroll ignores non-working days). */
    private function allPresent(): string
    {
        return str_repeat('P', (int) date('t', mktime(0, 0, 0, self::UAT_MONTHNUM, 1, self::UAT_YEAR)));
    }

    private function writeAttendance(string $staffId, string $dayWise): void
    {
        $S = self::UAT_SCHOOL;
        $monthKey = sprintf('%04d-%02d', self::UAT_YEAR, self::UAT_MONTHNUM);
        $ok = $this->guardedSet($S, 'staffAttendanceSummary', $this->d("{$staffId}_{$monthKey}"), [
            'schoolId' => $S, 'staffId' => $staffId, 'month' => $monthKey,
            'dayWise'  => $dayWise, 'updatedAt' => date('c'), '_uat' => true,
        ]);
        printf("   %s  %s %s  %s\n", $ok ? 'OK  ' : 'FAIL', $staffId, $monthKey, $dayWise);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Flag control
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param string $from Effective date, YYYY-MM-DD. Defaults to the UAT month
     *                     start. Accepting it is what makes the effective-date
     *                     BOUNDARY testable — the argument used to be ignored,
     *                     which would have made a boundary check silently
     *                     re-run the same case it was meant to contrast with.
     */
    public function enable(string $from = ''): void
    {
        if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = sprintf('%04d-%02d-01', self::UAT_YEAR, self::UAT_MONTHNUM);
        }
        $ok = $this->guardedUpdate(self::UAT_SCHOOL, 'schools', self::UAT_SCHOOL, [
            'attendancePolicy.calendarHalfDayPayEnabled'       => true,
            'attendancePolicy.calendarHalfDayPayEffectiveFrom' => $from,
        ]);
        printf("\nCHD flag ENABLED effectiveFrom=%s  -> %s\n", $from, $ok ? 'OK' : 'FAIL');
        $this->assertFlagState(true, $from);
    }

    /**
     * Read the flag back and prove it actually persisted.
     *
     * HARNESS FIDELITY (2026-08-19). firestoreUpdate() returned truthy once in
     * this programme WITHOUT the write landing — the flag still read false
     * afterwards. A UAT that trusts the return value would have gone on to
     * "verify" flag-ON behaviour against a tenant where the flag was off, and
     * reported the resulting flag-OFF numbers as a Calendar Half-Day pass.
     * Every flag change is therefore confirmed by reading it back.
     */
    private function assertFlagState(bool $wantEnabled, string $wantFrom): void
    {
        $school = $this->readSchool(self::UAT_SCHOOL);
        $pol    = is_array($school['attendancePolicy'] ?? null) ? $school['attendancePolicy'] : [];
        $gotEn  = !empty($pol['calendarHalfDayPayEnabled']);
        $gotFr  = (string) ($pol['calendarHalfDayPayEffectiveFrom'] ?? '');

        if ($gotEn === $wantEnabled && $gotFr === $wantFrom) {
            printf("  VERIFIED  enabled=%s from='%s'\n\n", var_export($gotEn, true), $gotFr);
            return;
        }

        printf("  *** WRITE DID NOT PERSIST *** wanted enabled=%s from='%s', read enabled=%s from='%s'\n",
            var_export($wantEnabled, true), $wantFrom, var_export($gotEn, true), $gotFr);
        printf("  Any result produced against this state is INVALID. Re-run the flag change.\n\n");
    }

    public function disable(): void
    {
        $ok = $this->guardedUpdate(self::UAT_SCHOOL, 'schools', self::UAT_SCHOOL, [
            'attendancePolicy.calendarHalfDayPayEnabled'       => false,
            'attendancePolicy.calendarHalfDayPayEffectiveFrom' => '',
        ]);
        printf("\nCHD flag DISABLED -> %s\n", $ok ? 'OK' : 'FAIL');
        $this->assertFlagState(false, '');
    }

    // ─────────────────────────────────────────────────────────────────
    //  Payroll execution — through the REAL Hr controller
    // ─────────────────────────────────────────────────────────────────

    /**
     * php index.php chd_uat gen [April] [2026]
     *
     * json_success()/json_error() both exit(), so this is necessarily the
     * last thing a CLI invocation does. Inspect results with `inspect`.
     */
    public function gen(string $month = self::UAT_MONTH, string $year = ''): void
    {
        $year = $year !== '' ? $year : (string) self::UAT_YEAR;
        echo "\nGENERATE PAYROLL via real Hr::generate_payroll()  {$month} {$year}\n";
        echo str_repeat('-', 92) . "\n";
        $_POST['month'] = $month;
        $_POST['year']  = $year;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $hr = $this->hr();
        $hr->generate_payroll();   // exits
    }

    /**
     * Payroll refuses to run without the chart of accounts. Uses the
     * canonical Hr::auto_create_payroll_accounts() rather than writing
     * accounting documents by hand.
     */
    public function accounts(): void
    {
        echo "\nCREATE PAYROLL ACCOUNTS via real Hr::auto_create_payroll_accounts()\n";
        echo str_repeat('-', 92) . "\n";
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $hr = $this->hr();
        $hr->auto_create_payroll_accounts();   // exits
    }

    /** php index.php chd_uat regen <runId> <staffId> */
    public function regen(string $runId, string $staffId = self::UAT_STAFF): void
    {
        echo "\nREGENERATE SLIP via real Hr::regenerate_staff_payroll()  run={$runId} staff={$staffId}\n";
        echo str_repeat('-', 92) . "\n";
        $_POST['run_id']  = $runId;
        $_POST['staff_id']= $staffId;
        $_POST['reason']  = 'CHD UAT regeneration parity check';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $hr = $this->hr();
        $hr->regenerate_staff_payroll();   // exits
    }

    /** Read back every payroll run + UAT slip and print the numbers. */
    public function inspect(): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nPAYROLL INSPECTION — {$S}\n" . str_repeat('=', 92) . "\n\n";
        try { $rows = $this->fb()->firestoreQuery('salarySlips', [['schoolId', '==', $S]]); }
        catch (\Throwable $e) { echo "  ERROR " . $e->getMessage() . "\n\n"; return; }

        $runs = []; $slips = [];
        foreach ((array) $rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $t = (string) ($d['type'] ?? '');
            // HARNESS FIDELITY: the run document's type is 'run', not
            // 'payroll_run' (Hr::generate_payroll's duplicate guard queries
            // ['type','==','run']). The wrong literal made `inspect` silently
            // report no runs at all and `clearpayroll` leave them behind.
            if ($t === 'run') $runs[]  = $d;
            if ($t === 'payslip')     $slips[] = $d;
        }

        foreach ($runs as $r) {
            printf("RUN %s  %s %s  status=%s\n", $r['run_id'] ?? '?', $r['month'] ?? '?', $r['year'] ?? '?', $r['status'] ?? '?');
            printf("   working_days=%s  staff_count=%s  total_gross=%s  total_net=%s\n",
                var_export($r['working_days'] ?? null, true), $r['staff_count'] ?? '?',
                $r['total_gross'] ?? '?', $r['total_net'] ?? '?');
        }
        echo "\n";
        foreach ($slips as $s) {
            $sid = (string) ($s['staff_id'] ?? $s['staffId'] ?? '?');
            if (strpos($sid, 'ZZUAT') !== 0) continue;
            printf("SLIP %s  run=%s\n", $sid, $s['run_id'] ?? '?');
            printf("   snake: working_days=%-6s days_worked=%-6s days_absent=%-5s lwp_days=%-5s paid_leave=%-4s\n",
                var_export($s['working_days'] ?? null, true), var_export($s['days_worked'] ?? null, true),
                var_export($s['days_absent'] ?? null, true), var_export($s['lwp_days'] ?? null, true),
                var_export($s['paid_leave_days'] ?? null, true));
            printf("   camel: workingDays=%-6s presentDays=%-6s daysAbsent=%-5s lwpDays=%-5s paidLeaveDays=%-4s\n",
                var_export($s['workingDays'] ?? null, true), var_export($s['presentDays'] ?? null, true),
                var_export($s['daysAbsent'] ?? null, true), var_export($s['lwpDays'] ?? null, true),
                var_export($s['paidLeaveDays'] ?? null, true));
            printf("   money: gross=%-10s net_pay=%-10s lwp_deduction=%-8s daily_salary=%s\n",
                $s['gross'] ?? '?', $s['net_pay'] ?? '?', $s['lwp_deduction'] ?? '?', $s['daily_salary'] ?? '?');

            // Decisive when a leave appears not to have been applied: the slip
            // emits a leave_detail for EVERY overlapping leave, even one that
            // produces no LWP. An EMPTY list therefore means the request was
            // never read (fixture/shape problem); a POPULATED list with no
            // coverage is a genuine engine finding. Without this, the two are
            // indistinguishable and a harness bug could be mistaken for a defect.
            // M-3 concurrency lock. A slip stuck at status='Processing' with an
            // EMPTY processingStartedAt can never satisfy the 5-minute stale
            // escape, so it would be permanently unregenerable.
            printf("   lock:     status=%-12s processingStartedAt='%s' by='%s'\n",
                $s['status'] ?? '(absent)', $s['processingStartedAt'] ?? '', $s['processingBy'] ?? '');

            // Employer statutory cost — recomputed by the engine every run and,
            // until 2026-08-19, never restated on regeneration.
            printf("   employer: pf_employer=%-10s esi_employer=%-10s dept=%s\n",
                $s['pf_employer'] ?? '(absent)', $s['esi_employer'] ?? '(absent)',
                $s['department'] ?? '(absent)');

            $ld = is_array($s['leave_details'] ?? null) ? $s['leave_details'] : [];
            printf("   leave_details: %d\n", count($ld));
            foreach ($ld as $l) {
                printf("      %-6s %s..%s total=%s paid=%s lwp=%s half=%s\n",
                    $l['type_code'] ?? '?', $l['from'] ?? '?', $l['to'] ?? '?',
                    var_export($l['total_days'] ?? null, true),
                    var_export($l['paid_days']  ?? null, true),
                    var_export($l['lwp_days']   ?? null, true),
                    var_export($l['half_day']   ?? null, true));
            }
        }
        echo "\n" . str_repeat('=', 92) . "\n\n";
    }

    /**
     * Mint a real CI session pinned to the UAT school and print its id.
     *
     * The reflection route (see hr()) proved too lossy: libraries load onto
     * the CI super-object rather than the injected controller, so
     * $hr->staff_employment_history_service was undefined and the
     * effective-dating resolution — the very thing under test — was being
     * silently skipped by its own try/catch.
     *
     * Driving the REAL HTTP endpoints with this session id instead exercises
     * the genuine request lifecycle: full constructor, autoload, RBAC gate,
     * library wiring. sess_match_ip is FALSE and the driver is `files`, so a
     * CLI-minted session is valid for curl.
     */
    public function mksession(): void
    {
        $this->pinSessionToUatSchool();
        $sid = session_id();
        echo "\nSESSION MINTED\n";
        printf("  cookie name : %s\n", $this->config->item('sess_cookie_name') ?: 'grader_session');
        printf("  session id  : %s\n", $sid);
        printf("  school_id   : %s\n", $this->session->userdata('school_id'));
        printf("  admin_role  : %s\n", $this->session->userdata('admin_role'));
        printf("\n  curl -b 'grader_session=%s' ...\n\n", $sid);
    }

    /** Reproduce the exact queries payroll uses, and report what they return. */
    public function diag(): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nQUERY DIAGNOSTICS — reproducing payroll's own reads\n" . str_repeat('=', 92) . "\n\n";

        $probe = function (string $label, string $col, array $cond) {
            try {
                $rows = $this->fb()->firestoreQuery($col, $cond);
                printf("  %-56s %d row(s)\n", $label, count((array) $rows));
                foreach ((array) $rows as $r) {
                    $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                    $sid = (string) ($d['staffId'] ?? '');
                    if (strpos($sid, 'ZZUAT') === 0 || count((array) $rows) <= 4) {
                        printf("      id=%-40s staffId=%-10s type=%s\n",
                            (string) ($r['id'] ?? '?'), $sid, (string) ($d['type'] ?? '-'));
                    }
                }
            } catch (\Throwable $e) {
                printf("  %-56s ERROR %s\n", $label, substr($e->getMessage(), 0, 70));
            }
        };

        $probe('staff  schoolId + status=Active',   'staff',
               [['schoolId', '==', $S], ['status', '==', 'Active']]);
        echo "\n";
        $probe('salarySlips  schoolId + type=salary_structure', 'salarySlips',
               [['schoolId', '==', $S], ['type', '==', 'salary_structure']]);
        echo "\n";
        $probe('salarySlips  schoolId only',        'salarySlips', [['schoolId', '==', $S]]);
        echo "\n";
        $probe('staffScheduleAssignments',          'staffScheduleAssignments', [['schoolId', '==', $S]]);
        echo "\n";
        $probe('calendarEvents',                    'calendarEvents', [['schoolId', '==', $S]]);
        echo "\n" . str_repeat('=', 92) . "\n\n";
    }

    /** php index.php chd_uat att ZZUAT001 PPPPMPP... — set a dayWise string. */
    public function att(string $staffId, string $dayWise): void
    {
        echo "\n";
        $this->writeAttendance($staffId, $dayWise);
        echo "\n";
    }

    /**
     * Create an APPROVED leave request overlapping the Calendar Half-Day.
     *
     * S2 says a FULL-DAY leave falling on a Calendar Half-Day consumes only
     * 0.5 leave day, because the day's expected work is only 0.5. The
     * observable on the payslip is paid_leave_days = 0.5, not 1.0 — with pay
     * still FULL, since approved paid leave covers the expectation.
     *
     * Field names mirror what _fsGetAllLeaveRequests() normalises, so the real
     * consumer reads this exactly as it reads an operator-created request.
     *
     * @param string $day  day-of-month to place the leave on (defaults to the half-day)
     * @param string $paid '1' paid leave, '0' unpaid
     */
    /**
     * Set the tenant's vacant-day treatment.
     *
     * Needed to exercise the 'V' (unmarked) branch at all: the default is
     * 'block', which skips the slip rather than charging, so the over-charge
     * that branch used to carry only reaches tenants that opted into 'absent'.
     */
    public function vacant(string $treatment = 'absent'): void
    {
        if (!in_array($treatment, ['absent', 'present', 'ignore', 'block'], true)) {
            echo "\n  refused: unknown vacant treatment '{$treatment}'\n\n";
            return;
        }
        // Payroll reads schools/{id}.attendanceRules.vacant_treatment — NOT
        // attendancePolicy. Writing the wrong branch produced a read-back that
        // dutifully "verified" a field the product never consults, which is
        // exactly the false-confidence this harness is supposed to prevent.
        // Both the write and the check now target what payroll reads.
        $ok = $this->guardedUpdate(self::UAT_SCHOOL, 'schools', self::UAT_SCHOOL, [
            'attendanceRules.vacant_treatment' => $treatment,
        ]);
        printf("\n  %s  attendanceRules.vacant_treatment = %s\n", $ok ? 'OK  ' : 'FAIL', $treatment);

        $school = $this->readSchool(self::UAT_SCHOOL);
        $pol    = is_array($school['attendanceRules'] ?? null) ? $school['attendanceRules'] : [];
        $got    = (string) ($pol['vacant_treatment'] ?? '');
        printf("  %s read back '%s'\n\n", $got === $treatment ? 'VERIFIED' : '*** DID NOT PERSIST ***', $got);
    }

    /**
     * @param string $half '1' to create a HALF-day leave (0.5), '0' for a full day
     */
    public function leave(string $staffId = self::UAT_STAFF, string $day = '', string $paid = '1', string $half = '0'): void
    {
        $S   = self::UAT_SCHOOL;
        $dom = ($day === '' || !ctype_digit($day)) ? (string) self::HALF_DAY_DOM : $day;
        $date = sprintf('%04d-%02d-%02d', self::UAT_YEAR, self::UAT_MONTHNUM, (int) $dom);
        $isPaid = ($paid === '1');
        $isHalf = ($half === '1');

        $docId = $this->d("LR_ZZUAT_{$staffId}_{$date}");
        $ok = $this->guardedSet($S, 'leaveApplications', $docId, [
            'schoolId'   => $S,
            'type'       => 'request',
            'staffId'    => $staffId,   'staff_id'   => $staffId,
            'staffName'  => $staffId,   'staff_name' => $staffId,
            'status'     => 'Approved',
            'fromDate'   => $date,      'from_date'  => $date,
            'toDate'     => $date,      'to_date'    => $date,
            // Full-day: the point of S2 is that the ENGINE, not the request,
            // decides this costs only half a leave day on a half-day.
            // Half-day: the locked rule — coverage 0.5, balance 0.5, full pay.
            'days'       => $isHalf ? 0.5 : 1,
            'paid_days'  => $isPaid ? ($isHalf ? 0.5 : 1) : 0,
            'lwp_days'   => $isPaid ? 0 : ($isHalf ? 0.5 : 1),
            'half_day'   => $isHalf,
            'half_day_period' => $isHalf ? 'AM' : '',
            // No type_id on purpose: exercises the CR-3 deleted-type fallback,
            // which reads type_paid. Keeps the fixture free of a leave-type doc.
            'type_id'    => '',
            'type_code'  => $isPaid ? 'CL' : 'LWP',
            'type_name'  => $isPaid ? 'ZZ UAT Casual Leave' : 'ZZ UAT Unpaid',
            'type_paid'  => $isPaid,
            'updatedAt'  => date('c'),
            '_uat'       => true,
        ]);
        printf("\n   %s  leave %s %s  days=" . ($isHalf ? '0.5 (HALF)' : '1') . " paid=%s\n\n",
            $ok ? 'OK  ' : 'FAIL', $staffId, $date, $isPaid ? 'yes' : 'no');
    }

    /**
     * Remove `_uat`-tagged docs from a collection the main teardown plan does
     * not enumerate — leaveApplications and staffEmploymentTimeline, both of
     * which had a baseline of 0 and are written by harness fixtures.
     *
     * Deliberately NOT extended to auditLogs / systemLogs: those are
     * append-only records of what was done, and deleting them to make a
     * teardown look tidy would destroy the evidence trail this UAT exists to
     * produce. They are declared as residual instead.
     */
    public function clearleave(string $collection = 'leaveApplications'): void
    {
        $S = self::UAT_SCHOOL;
        // staffScheduleAssignments is here because `fixassign` created
        // staff-level assignments AFTER the teardown plan was authored — the
        // plan only knew the original organisation-level one, so teardown left
        // these behind. Anything the harness creates must be enumerable here.
        if (!in_array($collection, ['leaveApplications', 'staffEmploymentTimeline', 'staffScheduleAssignments'], true)) {
            echo "\n  refused: {$collection} is not a harness-fixture collection\n\n";
            return;
        }
        echo "\nCLEARING UAT fixtures in {$collection}\n";
        try {
            $rows = $this->fb()->firestoreQuery($collection, [['schoolId', '==', $S]]);
            foreach ((array) $rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                // ONLY harness-created docs — never an operator's real record.
                if (empty($d['_uat'])) continue;
                $this->guardedDelete($S, $collection, (string) ($r['id'] ?? ''));
            }
        } catch (\Throwable $e) { echo "  error: " . substr($e->getMessage(), 0, 60) . "\n"; }
        printf("  deleted %d doc(s)\n\n", $this->writes);
    }

    /**
     * Evidence check before an IRREVERSIBLE delete.
     *
     * teardown removes every `accounting` doc for the UAT school on the grounds
     * that the baseline was 0 — inferred from payroll having refused with
     * "missing required accounting accounts". Inference is not good enough to
     * justify destroying a tenant's chart of accounts, so this prints the
     * creation timestamps: if they were all minted during this UAT they are
     * ours to remove, and if any predates it, teardown must spare that
     * collection and say so.
     */
    public function probeacct(): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nACCOUNTING PROVENANCE — {$S}\n" . str_repeat('=', 92) . "\n\n";
        try { $rows = (array) $this->fb()->firestoreQuery('accounting', [['schoolId', '==', $S]]); }
        catch (\Throwable $e) { echo "  ERROR " . $e->getMessage() . "\n\n"; return; }

        $dates = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $ts = (string) ($d['createdAt'] ?? $d['created_at'] ?? $d['updatedAt'] ?? '');
            $day = $ts !== '' ? substr($ts, 0, 10) : '(none)';
            $dates[$day] = ($dates[$day] ?? 0) + 1;
        }
        ksort($dates);
        printf("  %d accounting doc(s)\n\n  created-on histogram:\n", count($rows));
        foreach ($dates as $day => $n) printf("      %-12s %d\n", $day, $n);
        printf("\n  today = %s\n", date('Y-m-d'));
        echo "\n" . str_repeat('=', 92) . "\n\n";
    }

    /**
     * Create the payroll chart-of-accounts through the REAL endpoint.
     *
     * Payroll refuses to run without accounts 5010/5020/2020/2030-2034, and
     * teardown removes them (they were all created by this UAT — verified by
     * creation timestamp, see probeacct). Re-running the UAT therefore has to
     * recreate them, and it does so via the operator's own endpoint rather than
     * by hand-writing accounting documents.
     */
    public function mkaccounts(): void
    {
        echo "\nCREATE PAYROLL ACCOUNTS via real Hr::auto_create_payroll_accounts()\n";
        echo str_repeat('-', 92) . "\n";
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $hr = $this->hr();
        $hr->auto_create_payroll_accounts();   // exits
    }

    /**
     * Dump the raw top-level keys of the school doc and of attendancePolicy.
     *
     * Diagnoses the failure mode where a dotted-path update is written as a
     * LITERAL top-level key ("attendancePolicy.calendarHalfDayPayEnabled")
     * instead of nesting into the map — which looks like a successful write and
     * reads back as though nothing changed.
     */
    public function dumppolicy(): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nSCHOOL DOC FIELD NAMES — {$S}\n" . str_repeat('=', 92) . "\n\n";
        $school = $this->readSchool($S);

        $dotted = [];
        foreach (array_keys($school) as $k) {
            if (strpos((string) $k, '.') !== false) $dotted[] = $k;
        }
        printf("  top-level keys: %d\n", count($school));
        if ($dotted) {
            echo "\n  LITERAL DOTTED KEYS (this is the bug):\n";
            foreach ($dotted as $k) printf("      %s = %s\n", $k, var_export($school[$k], true));
        } else {
            echo "  no literal dotted keys\n";
        }

        $pol = is_array($school['attendancePolicy'] ?? null) ? $school['attendancePolicy'] : [];
        printf("\n  attendancePolicy keys (%d):\n", count($pol));
        foreach ($pol as $k => $v) {
            printf("      %-38s %s\n", $k,
                is_scalar($v) ? var_export($v, true) : gettype($v) . '(' . count((array) $v) . ')');
        }
        echo "\n" . str_repeat('=', 92) . "\n\n";
    }

    /**
     * READ-ONLY impact analysis for the leave-balance integer truncation.
     *
     * allocate_leave_balances() reconciled `used` from approved requests via
     * `(int) $req['days']`, so every fractional day count was truncated —
     * a 0.5-day leave contributed 0, a 2.5-day leave contributed 2. Stored
     * balances are therefore too HIGH by the truncated remainder, i.e. staff
     * appear to hold leave they have already taken.
     *
     * This performs NO writes. It recomputes `used` both ways from the approved
     * requests and reports the divergence, so the size of any correction is
     * known before anyone decides whether to make one.
     *
     * Scoped to the designated test school. The same method is what a
     * production reconciliation would use, but running it against a live tenant
     * is the operator's decision, not this harness's.
     */
    public function balanceimpact(): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nLEAVE BALANCE TRUNCATION — IMPACT ANALYSIS (READ ONLY)  {$S}\n";
        echo str_repeat('=', 92) . "\n\n";

        try { $rows = (array) $this->fb()->firestoreQuery('leaveApplications', [['schoolId', '==', $S]]); }
        catch (\Throwable $e) { echo "  ERROR " . $e->getMessage() . "\n\n"; return; }

        $agg = [];            // staffId|typeId|year => ['old'=>..,'new'=>..,'n'=>..]
        $fractional = 0;
        $approved   = 0;

        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            if (strtolower((string) ($d['status'] ?? '')) !== 'approved') continue;
            $approved++;

            $sid  = (string) ($d['staff_id'] ?? $d['staffId'] ?? $d['applicantId'] ?? '');
            $tid  = (string) ($d['type_id']  ?? $d['typeId']  ?? '');
            $from = (string) ($d['from_date'] ?? $d['fromDate'] ?? $d['startDate'] ?? '');
            if ($sid === '' || $tid === '' || $from === '') continue;

            $days = (float) ($d['days'] ?? 0);
            if ($days != floor($days)) $fractional++;

            $key = $sid . '|' . $tid . '|' . date('Y', strtotime($from));
            if (!isset($agg[$key])) $agg[$key] = ['old' => 0, 'new' => 0, 'n' => 0];
            $agg[$key]['old'] += (int) $days;                                   // the defect
            $agg[$key]['new'] += ($days == floor($days)) ? (int) $days : $days; // the fix
            $agg[$key]['n']++;
        }

        printf("  approved requests scanned : %d\n", $approved);
        printf("  with FRACTIONAL day counts: %d\n\n", $fractional);

        $affected = 0; $totalDrift = 0.0;
        foreach ($agg as $key => $a) {
            $drift = $a['new'] - $a['old'];
            if (abs($drift) < 0.0001) continue;
            $affected++;
            $totalDrift += $drift;
            [$sid, $tid, $yr] = explode('|', $key);
            printf("  %-12s type=%-14s %s   used %s -> %s   (balance overstated by %s)\n",
                $sid, $tid, $yr, $a['old'], $a['new'], $drift);
        }

        if ($affected === 0) {
            echo "  No staff/type/year combination changes. Nothing to reconcile here.\n";
        } else {
            printf("\n  affected staff/type/year rows : %d\n", $affected);
            printf("  total leave-days over-credited: %s\n", $totalDrift);
        }

        echo "\n  NOTE: balances are only rewritten when allocate_leave_balances() next runs.\n";
        echo "        Until then stored balances still reflect the truncation.\n";
        echo "\n" . str_repeat('=', 92) . "\n\n";
    }

    /**
     * Clear a stuck M-3 processing lock on the UAT slips.
     *
     * A regeneration that errors AFTER claiming the lock leaves the slip at
     * status='Processing' until the 5-minute stale escape expires. That is the
     * designed behaviour, not a defect — but during a UAT it just costs five
     * minutes per failed attempt, so this resets the fixture explicitly.
     *
     * Restores status='Draft', which is the correct resting status: the run must
     * be Draft for regeneration to be permitted at all.
     */
    public function unlock(): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nCLEARING M-3 PROCESSING LOCKS ON UAT SLIPS\n";
        try { $rows = (array) $this->fb()->firestoreQuery('salarySlips', [['schoolId', '==', $S]]); }
        catch (\Throwable $e) { echo "  error: " . substr($e->getMessage(), 0, 60) . "\n\n"; return; }

        $n = 0;
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            if ((string) ($d['type'] ?? '') !== 'payslip') continue;
            if ((string) ($d['status'] ?? '') !== 'Processing') continue;
            $id = (string) ($r['id'] ?? '');
            if ($this->guardedUpdate($S, 'salarySlips', $id, [
                'status'              => 'Draft',
                'processingStartedAt' => '',
                'processingBy'        => '',
            ])) { $n++; printf("   OK    %s -> Draft\n", $id); }
        }
        printf("  cleared %d lock(s)\n\n", $n);
    }

    /**
     * READ-ONLY census of vacant-day treatment across tenants.
     *
     * The shared leave-coverage rule changed behaviour only where unmarked days
     * are CHARGED, i.e. `attendanceRules.vacant_treatment === 'absent'`. The
     * default is 'block', which skips the slip instead of charging, so most
     * tenants are unaffected. This establishes which are, so the blast radius is
     * a measured number rather than an assumption.
     *
     * Deliberately narrow: reads ONE configuration field per school and reports
     * the distribution. No employee, payroll or leave data is read, and nothing
     * is written. Identifiers are shown only for tenants in the affected mode,
     * because those are the ones an operator must actually look at.
     */

    public function vacantcensus(): void
    {
        echo "\nVACANT-TREATMENT CENSUS (READ ONLY — configuration field only)\n";
        echo str_repeat('=', 92) . "\n\n";

        try { $rows = (array) $this->fb()->firestoreQuery('schools', []); }
        catch (\Throwable $e) { echo "  ERROR " . $e->getMessage() . "\n\n"; return; }

        $dist = []; $affected = [];
        foreach ($rows as $r) {
            $d  = is_array($r['data'] ?? null) ? $r['data'] : [];
            $ar = is_array($d['attendanceRules'] ?? null) ? $d['attendanceRules'] : [];
            // Absent field == the 'block' default; recorded distinctly so
            // "never configured" is not confused with "explicitly set to block".
            $vt = array_key_exists('vacant_treatment', $ar)
                ? (string) $ar['vacant_treatment']
                : '(unset → block)';
            $dist[$vt] = ($dist[$vt] ?? 0) + 1;
            if ($vt === 'absent') $affected[] = (string) ($r['id'] ?? '?');
        }

        printf("  schools scanned: %d\n\n  distribution:\n", count($rows));
        ksort($dist);
        foreach ($dist as $k => $n) printf("      %-22s %d\n", $k, $n);

        echo "\n  AFFECTED by the coverage-rule change (vacant_treatment = 'absent'):\n";
        if (empty($affected)) {
            echo "      none — no tenant charges unmarked days, so no historical payroll\n";
            echo "      was computed through the branch that ignored leave coverage.\n";
        } else {
            foreach ($affected as $id) printf("      %s\n", $id);
            echo "\n      For each: staff with approved leave on an UNMARKED day were\n";
            echo "      over-charged, and a full-day unpaid leave was charged twice.\n";
        }
        echo "\n" . str_repeat('=', 92) . "\n\n";
    }

    /**
     * READ-ONLY release audit across every tenant.
     *
     * Answers, in one pass and without a single write:
     *   §5 feature-flag safety   — is the flag off, and does it carry an effective date?
     *   §2 vacant treatment      — can unmarked days reach payroll at all?
     *   §2 leave-balance impact  — old vs corrected `used`, and the delta
     *   §3 'A'/'M' exposure      — days where an approved PAID half-day leave
     *                              coincided with an 'A' or unmarked attendance
     *                              mark, i.e. the over-charge the shared
     *                              coverage rule now prevents
     *   §4 payroll readiness     — active staff, salary structures, schedules,
     *                              assignments, required accounts
     *
     * Reports AGGREGATES per tenant. No employee names, no salary figures, no
     * individual records — the question is how many and how much, not who.
     *
     * PERFORMS NO WRITES. Enforced by review and asserted by
     * ReleaseAuditReadOnlyTest; nothing here may repair anything, and
     * allocate_leave_balances() is deliberately never called.
     */
    public function releaseaudit(): void
    {
        echo "\nRELEASE AUDIT — READ ONLY — all tenants\n";
        echo str_repeat('=', 108) . "\n\n";

        try { $schools = (array) $this->fb()->firestoreQuery('schools', []); }
        catch (\Throwable $e) { echo "  ERROR reading schools: " . $e->getMessage() . "\n\n"; return; }

        $q = function (string $col, array $cond) {
            try { return (array) $this->fb()->firestoreQuery($col, $cond); }
            catch (\Throwable $e) { return []; }
        };

        $totFlagOn = 0; $totDrift = 0.0; $totExposure = 0; $ready = 0;

        foreach ($schools as $srow) {
            $sid = (string) ($srow['id'] ?? '');
            if ($sid === '') continue;
            $d   = is_array($srow['data'] ?? null) ? $srow['data'] : [];
            $pol = is_array($d['attendancePolicy'] ?? null) ? $d['attendancePolicy'] : [];
            $ar  = is_array($d['attendanceRules']  ?? null) ? $d['attendanceRules']  : [];

            $flagOn = !empty($pol['calendarHalfDayPayEnabled']);
            $from   = (string) ($pol['calendarHalfDayPayEffectiveFrom'] ?? '');
            $vt     = array_key_exists('vacant_treatment', $ar) ? (string) $ar['vacant_treatment'] : '(unset→block)';
            if ($flagOn) $totFlagOn++;

            // ── leave: balance drift + paid half-day inventory ──
            $leave = $q('leaveApplications', [['schoolId', '==', $sid]]);
            $approved = 0; $fractional = 0; $paidHalf = [];
            $agg = [];
            foreach ($leave as $lr) {
                $ld = is_array($lr['data'] ?? null) ? $lr['data'] : [];
                if (strtolower((string) ($ld['status'] ?? '')) !== 'approved') continue;
                $approved++;

                $st  = (string) ($ld['staff_id'] ?? $ld['staffId'] ?? $ld['applicantId'] ?? '');
                $tid = (string) ($ld['type_id']  ?? $ld['typeId']  ?? '');
                $fr  = (string) ($ld['from_date'] ?? $ld['fromDate'] ?? $ld['startDate'] ?? '');
                if ($st === '' || $tid === '' || $fr === '') continue;

                $days = (float) ($ld['days'] ?? 0);
                if ($days != floor($days)) $fractional++;

                $k = $st . '|' . $tid . '|' . date('Y', strtotime($fr));
                if (!isset($agg[$k])) $agg[$k] = ['old' => 0, 'new' => 0];
                $agg[$k]['old'] += (int) $days;
                $agg[$k]['new'] += ($days == floor($days)) ? (int) $days : $days;

                // PAID half-day leave — the shape whose coverage 'A' used to ignore.
                $isHalf = filter_var($ld['half_day'] ?? $ld['halfDay'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $paid   = ((float) ($ld['lwp_days'] ?? 0)) <= 0.0;
                if ($isHalf && $paid) $paidHalf[] = [$st, $fr];
            }

            $rows = 0; $drift = 0.0;
            foreach ($agg as $a) {
                $dl = $a['new'] - $a['old'];
                if (abs($dl) > 0.0001) { $rows++; $drift += $dl; }
            }
            $totDrift += $drift;

            // ── 'A'/unmarked exposure: paid half-day leave meeting an absent mark ──
            $exposure = 0;
            foreach ($paidHalf as [$st, $date]) {
                $mk  = date('Y-m', strtotime($date));
                $dom = (int) date('j', strtotime($date));
                $sum = $q('staffAttendanceSummary', [['schoolId','==',$sid], ['staffId','==',$st], ['month','==',$mk]]);
                foreach ($sum as $srow2) {
                    $sd = is_array($srow2['data'] ?? null) ? $srow2['data'] : [];
                    $dw = (string) ($sd['dayWise'] ?? '');
                    $ch = strtoupper($dw[$dom - 1] ?? '');
                    // 'A' was charged in full; 'V' only when the tenant charges
                    // unmarked days at all.
                    if ($ch === 'A' || ($ch === 'V' && $vt === 'absent')) $exposure++;
                }
            }
            $totExposure += $exposure;

            // ── payroll readiness ──
            $staffN  = count($q('staff', [['schoolId','==',$sid], ['status','==','Active']]));
            $structN = count($q('salarySlips', [['schoolId','==',$sid], ['type','==','salary_structure']]));
            $asgN    = count($q('staffScheduleAssignments', [['schoolId','==',$sid]]));
            $schedN  = count((array) ($pol['workSchedules'] ?? []));
            $acctN   = count($q('accounting', [['schoolId','==',$sid], ['type','==','chartOfAccounts']]));
            $isReady = ($staffN > 0 && $structN > 0 && $schedN > 0 && $asgN > 0 && $acctN > 0);
            if ($isReady) $ready++;

            printf("%s\n", $sid);
            printf("   flag      enabled=%-5s from='%s'   vacant_treatment=%s\n",
                var_export($flagOn, true), $from, $vt);
            printf("   leave     approved=%-4d fractional=%-4d driftRows=%-3d driftDays=%s\n",
                $approved, $fractional, $rows, $drift);
            printf("   exposure  paidHalfDayLeave=%-4d meetingAbsentMark=%d\n",
                count($paidHalf), $exposure);
            printf("   payroll   activeStaff=%-4d structures=%-4d schedules=%-3d assignments=%-4d accounts=%-3d  => %s\n\n",
                $staffN, $structN, $schedN, $asgN, $acctN, $isReady ? 'READY' : 'not configured');
        }

        echo str_repeat('=', 108) . "\n";
        printf("  tenants=%d   flagEnabled=%d   payrollConfigured=%d\n", count($schools), $totFlagOn, $ready);
        printf("  total leave-days over-credited by the truncation : %s\n", $totDrift);
        printf("  total paid-half-day/absent-mark collisions       : %d\n", $totExposure);
        printf("  writes performed: %d   (must be 0)\n\n", $this->writes);
    }

    /** Delete all payroll runs/slips so the next generate starts clean. */
    public function clearpayroll(): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nCLEARING UAT payroll runs + slips\n";
        try {
            $rows = $this->fb()->firestoreQuery('salarySlips', [['schoolId', '==', $S]]);
            foreach ((array) $rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                $t = (string) ($d['type'] ?? '');
                // 'run' — see the fidelity note in inspect(). Also sweeps the
                // 'slip_archive' documents regeneration leaves behind, which
                // are UAT artefacts too and must not survive teardown.
                if ($t === 'run' || $t === 'payslip' || $t === 'slip_archive') {
                    $this->guardedDelete($S, 'salarySlips', (string) ($r['id'] ?? ''));
                }
            }
        } catch (\Throwable $e) { echo "  error: " . substr($e->getMessage(), 0, 60) . "\n"; }
        printf("  deleted %d doc(s)\n\n", $this->writes);
    }

    /**
     * Replace the organisation-level assignment with STAFF-level ones.
     *
     * Policy_assignment_service::resolve() documents that the organisation
     * level "has no assignment record — it applies only when the operator
     * declared one (D4)", i.e. via the organisationPolicyId passed into the
     * resolve context, which payroll passes as null. A
     * staffScheduleAssignments doc with attachmentType='organisation' is
     * therefore silently ignored, every employee blocks, and payroll
     * reports "No staff with salary structures found in the roster" — which
     * names the wrong cause. Fixture bug on my side, not a product defect,
     * but the misleading message is worth recording.
     */
    public function fixassign(): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nREPLACING organisation-level assignment with STAFF-level\n";
        echo str_repeat('=', 92) . "\n\n";

        $this->guardedDelete($S, 'staffScheduleAssignments', $this->d('WSA_ZZUAT'));
        echo "  deleted  WSA_ZZUAT (organisation level — never resolves)\n";

        foreach ([self::UAT_STAFF, self::UAT_STAFF_2] as $sid) {
            $aid = 'WSA_' . $sid;
            $ok = $this->guardedSet($S, 'staffScheduleAssignments', $this->d($aid), [
                'assignmentId'   => $aid,
                'schoolId'       => $S,
                'attachmentType' => 'staff',
                'attachmentId'   => $sid,
                'policyId'       => self::UAT_SCHED,
                'policyType'     => 'work_schedule',
                'scheduleId'     => self::UAT_SCHED,
                'staffId'        => $sid,
                'effectiveFrom'  => '2020-01-01',
                'effectiveTo'    => null,
                'status'         => 'active',
                'updatedAt'      => date('c'),
                '_uat'           => true,
            ]);
            printf("  %s  %s  staff-level -> %s\n", $ok ? 'created' : 'FAILED ', $aid, self::UAT_SCHED);
        }
        echo "\n" . str_repeat('=', 92) . "\n\n";
    }

    /**
     * Call the REAL Hr::_staff_working_days() for the UAT staff and print
     * exactly what it returns — including any calendar/schedule block.
     * "No staff with salary structures" is emitted when every employee was
     * `continue`d, so this exposes the actual per-employee cause.
     */
    public function whyblocked(string $staffId = self::UAT_STAFF): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nWHY IS {$staffId} SKIPPED?  (real _staff_working_days)\n";
        echo str_repeat('=', 92) . "\n\n";

        $hr = $this->hr();
        $rm = new ReflectionMethod('Hr', '_staff_working_days');
        $rm->setAccessible(true);

        // Holidays for the month, exactly as generate_payroll fetches them.
        $hr->load->library('holiday_service');
        $hr->holiday_service->init($hr->fs, $S, '2026-27');
        $holidays = $hr->holiday_service->holidays_in_month(self::UAT_YEAR, self::UAT_MONTHNUM, 'staff', '', $staffId);

        // Resolve the department the same way payroll does.
        $rdep = new ReflectionMethod('Hr', '_half_day_weight');
        $rdep->setAccessible(true);
        printf("  _half_day_weight(%d,%d) = %s\n\n",
            self::UAT_YEAR, self::UAT_MONTHNUM, var_export($rdep->invoke($hr, self::UAT_YEAR, self::UAT_MONTHNUM), true));

        foreach ([self::UAT_DEPT, 'ZZ UAT MOVED AWAY', ''] as $dept) {
            $swd = $rm->invoke($hr, self::UAT_MONTHNUM, self::UAT_YEAR, $holidays,
                               '2020-01-01', null, $staffId, $dept);
            printf("  department='%s'\n", $dept === '' ? '(empty)' : $dept);
            printf("     working        %s\n", var_export($swd['working'] ?? null, true));
            printf("     calBlocked     %s\n", var_export($swd['calBlocked'] ?? null, true));
            printf("     calBlockCode   %s\n", var_export($swd['calBlockCode'] ?? null, true));
            printf("     calBlockWhy    %s\n", substr((string) ($swd['calBlockWhy'] ?? ''), 0, 90));
            printf("     halfDayCount   %s\n", var_export($swd['halfDayCount'] ?? null, true));
            printf("     scheduleId     %s\n\n", var_export($swd['scheduleId'] ?? null, true));
        }
        echo str_repeat('=', 92) . "\n\n";
    }

    /** Probe the Firestore_service instance the injected Hr actually uses. */
    public function fsprobe(): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nFIRESTORE SERVICE PROBE (the instance Hr uses)\n" . str_repeat('=', 92) . "\n\n";
        $hr = $this->hr();
        $fs = $hr->fs;

        printf("  class            %s\n", get_class($fs));
        printf("  schoolId()       %s\n", method_exists($fs, 'schoolId') ? $fs->schoolId() : '(n/a)');
        printf("  isReady()        %s\n", method_exists($fs, 'isReady') ? var_export($fs->isReady(), true) : '(n/a)');

        // Is the internal REST client present?
        try {
            $rp = new ReflectionProperty(get_class($fs), 'client');
            $rp->setAccessible(true);
            $c = $rp->getValue($fs);
            printf("  internal client  %s\n", is_object($c) ? get_class($c) : var_export($c, true));
        } catch (\Throwable $e) { printf("  internal client  (unreadable: %s)\n", substr($e->getMessage(), 0, 40)); }

        echo "\n  schoolWhere through Hr's fs:\n";
        foreach ([
            ['salarySlips', [['type', '==', 'salary_structure']]],
            ['staff',       [['status', '==', 'Active']]],
        ] as [$col, $cond]) {
            try {
                $rows = $fs->schoolWhere($col, $cond);
                printf("    %-14s %d row(s)\n", $col, is_array($rows) ? count($rows) : -1);
            } catch (\Throwable $e) {
                printf("    %-14s ERROR %s\n", $col, substr($e->getMessage(), 0, 60));
            }
        }
        echo "\n" . str_repeat('=', 92) . "\n\n";
    }

    // ─────────────────────────────────────────────────────────────────
    //  PHASE 3 — harness fidelity
    // ─────────────────────────────────────────────────────────────────

    /**
     * Prove the historical department resolver is genuinely wired and
     * genuinely executed — not silently skipped by its own try/catch.
     *
     * The discriminator is real, not cosmetic: the Calendar Half-Day is
     * scoped to UAT_DEPT. So we move ZZUAT001's LIVE department elsewhere
     * while its employment TIMELINE still says UAT_DEPT for the payroll
     * period. If the historical resolver runs, the half-day applies. If it
     * is bypassed and the live value is used, the half-day does NOT apply
     * and expected work differs. One number separates the two paths.
     */
    public function fidelity(): void
    {
        $S = self::UAT_SCHOOL;
        $sid = self::UAT_STAFF;
        $period = sprintf('%04d-%02d-01', self::UAT_YEAR, self::UAT_MONTHNUM);
        echo "\nPHASE 3 — HARNESS FIDELITY\n" . str_repeat('=', 92) . "\n\n";

        // 1. Timeline: department = UAT_DEPT, effective well before the period.
        echo "1. seed employment timeline (historical department)\n";
        $ok = $this->guardedSet($S, 'staffEmploymentTimeline', $this->d('EHT_ZZUAT001_DEPT'), [
            'schoolId' => $S, 'staffId' => $sid, 'attribute' => 'department',
            'value' => self::UAT_DEPT, 'effectiveFrom' => '2020-01-01', 'effectiveTo' => null,
            'eventRef' => 'UAT', 'setBy' => 'chd_uat', 'reason' => 'CHD UAT fidelity control',
            '_uat' => true,
        ]);
        printf("   %s  timeline: department='%s' from 2020-01-01 (open)\n", $ok ? 'OK  ' : 'FAIL', self::UAT_DEPT);

        // 2. Move the LIVE department away — the negative control.
        echo "\n2. move LIVE department away (negative control)\n";
        $live = 'ZZ UAT MOVED AWAY';
        $ok = $this->guardedUpdate($S, 'staff', $this->d($sid), [
            'Department' => $live, 'department' => $live,
        ]);
        printf("   %s  live department now '%s'\n", $ok ? 'OK  ' : 'FAIL', $live);
        printf("   => historical='%s'  live='%s'  (they MUST differ)\n", self::UAT_DEPT, $live);

        // 3. Build Hr and prove the dependency is real.
        echo "\n3. dependency wiring\n";
        $hr = $this->hr();
        if (!isset($hr->staff_employment_history_service)) {
            // Force the load exactly as generate_payroll() does at runtime.
            $hr->load->library('staff_employment_history_service');
        }
        if (!isset($hr->staff_employment_history_service)) {
            echo "   FAIL  Hr::\$staff_employment_history_service is STILL undefined.\n";
            echo "         The harness cannot faithfully reproduce the real path — ABORT.\n\n";
            exit(2);
        }
        $svcClass = get_class($hr->staff_employment_history_service);
        printf("   PASS  Hr::\$staff_employment_history_service = %s\n", $svcClass);
        if ($svcClass !== 'Staff_employment_history_service') {
            echo "   FAIL  not the real implementation — ABORT.\n\n"; exit(2);
        }

        // 4. Execute the REAL resolver and assert it returns the HISTORICAL value.
        echo "\n4. execute the real resolveAttributeAsOf()\n";
        $svc = $hr->staff_employment_history_service->init($hr->fs, $S, 'chd_uat');
        $resolved = $svc->resolveAttributeAsOf($sid, 'department', $period);
        printf("   resolveAttributeAsOf('%s','department','%s') = %s\n",
            $sid, $period, var_export($resolved, true));

        $pass = ($resolved === self::UAT_DEPT);
        printf("   %s  historical value returned (expected '%s')\n",
            $pass ? 'PASS' : 'FAIL', self::UAT_DEPT);
        if (!$pass) {
            echo "   FAIL  the resolver did not return the historical department — ABORT.\n\n";
            exit(2);
        }

        // 5. Negative control — the live value is genuinely different, so a
        //    bypass would be observable rather than benign.
        echo "\n5. negative control\n";
        $staffDoc = $this->fb()->firestoreGet('staff', $this->d($sid));
        $liveNow = (string) ($staffDoc['Department'] ?? '');
        printf("   live department on the staff doc = '%s'\n", $liveNow);
        printf("   %s  live differs from historical — a bypass WOULD change the result\n",
            ($liveNow !== $resolved) ? 'PASS' : 'FAIL');
        if ($liveNow === $resolved) {
            echo "   FAIL  control is degenerate; the two paths are indistinguishable — ABORT.\n\n";
            exit(2);
        }

        // 6. And prove the difference is financially meaningful: the half-day
        //    is scoped to the HISTORICAL department only.
        echo "\n6. why it matters\n";
        printf("   Calendar Half-Day is scoped to departments=['%s'].\n", self::UAT_DEPT);
        printf("   historical resolution -> half-day APPLIES     -> expected work 25.5 (flag ON)\n");
        printf("   live resolution       -> half-day DOES NOT    -> expected work 26.0\n");
        echo "   The payroll run below therefore discriminates between the two.\n";

        echo "\n" . str_repeat('=', 92) . "\n";
        echo "FIDELITY PASS — real service, real resolver, non-degenerate control.\n\n";
    }

    // ─────────────────────────────────────────────────────────────────
    //  PHASE 1 — full artifact inventory
    // ─────────────────────────────────────────────────────────────────

    /** Every collection this UAT can have written to, itemised. */
    private const UAT_COLLECTIONS = [
        'schools', 'staff', 'salarySlips', 'staffScheduleAssignments',
        'calendarEvents', 'staffAttendanceSummary', 'accounting',
        'leaveApplications', 'auditLogs', 'systemLogs', 'staffEmploymentTimeline',
    ];

    public function inventory(): void
    {
        $S = self::UAT_SCHOOL;
        echo "\nUAT ARTIFACT INVENTORY — {$S}\n" . str_repeat('=', 92) . "\n\n";

        $totals = [];
        foreach (self::UAT_COLLECTIONS as $col) {
            if ($col === 'schools') continue;
            try { $rows = (array) $this->fb()->firestoreQuery($col, [['schoolId', '==', $S]]); }
            catch (\Throwable $e) { printf("  %-26s ERROR %s\n", $col, substr($e->getMessage(), 0, 50)); continue; }

            $byKind = []; $uat = 0;
            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                $kind = (string) ($d['type'] ?? $d['logType'] ?? '-');
                $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
                if (!empty($d['_uat']) || strpos((string) ($r['id'] ?? ''), 'ZZUAT') !== false) $uat++;
            }
            $totals[$col] = count($rows);
            printf("  %-26s %4d doc(s)   [_uat-tagged: %d]\n", $col, count($rows), $uat);
            foreach ($byKind as $k => $n) printf("        type=%-24s %d\n", $k, $n);
        }

        echo "\n  attendancePolicy branches on schools/{$S}:\n";
        $school = $this->readSchool($S);
        $pol = is_array($school['attendancePolicy'] ?? null) ? $school['attendancePolicy'] : [];
        printf("        workSchedules[]              %d\n", count((array) ($pol['workSchedules'] ?? [])));
        printf("        workingWeek.versions[]       %d\n", count((array) ($pol['workingWeek']['versions'] ?? [])));
        printf("        weeklyOffs                   %s\n", implode(',', (array) ($pol['weeklyOffs'] ?? [])));
        printf("        calendarHalfDayPayEnabled    %s\n", var_export(!empty($pol['calendarHalfDayPayEnabled']), true));
        printf("        calendarHalfDayPayEffectiveFrom '%s'\n", (string) ($pol['calendarHalfDayPayEffectiveFrom'] ?? ''));

        echo "\n  Baseline before this UAT (from the first recon run):\n";
        echo "        salarySlips 0 · staffScheduleAssignments 0 · calendarEvents 0\n";
        echo "        workSchedules 0 · workingWeek.versions 0 · weeklyOffs (empty)\n";
        echo "        => every salarySlips/accounting doc now present was created by this UAT.\n";
        echo "\n" . str_repeat('=', 92) . "\n\n";
    }

    // ─────────────────────────────────────────────────────────────────
    //  PHASE 2 — prove teardown scope BEFORE executing it
    // ─────────────────────────────────────────────────────────────────

    /**
     * Dry-run: list exactly what teardown WOULD delete, and assert every
     * target belongs to the UAT school. Executes no deletes.
     */
    public function teardown_plan(): void
    {
        $this->teardownRun(false);
    }

    public function teardown(): void
    {
        $this->teardownRun(true);
    }

    private function teardownRun(bool $execute): void
    {
        $S = self::UAT_SCHOOL;
        $mode = $execute ? 'EXECUTE' : 'DRY RUN';
        echo "\nUAT TEARDOWN — {$mode} — school {$S}\n" . str_repeat('=', 92) . "\n\n";

        $planned = []; $foreign = [];

        // 1. Explicit fixtures.
        $monthKey = sprintf('%04d-%02d', self::UAT_YEAR, self::UAT_MONTHNUM);
        foreach ([
            ['staffScheduleAssignments', 'WSA_ZZUAT'],
            ['staff',                    self::UAT_STAFF],
            ['staff',                    self::UAT_STAFF_2],
            ['calendarEvents',           'CE_ZZUAT_HALFDAY'],
            ['staffAttendanceSummary',   self::UAT_STAFF   . '_' . $monthKey],
            ['staffAttendanceSummary',   self::UAT_STAFF_2 . '_' . $monthKey],
        ] as [$col, $bare]) {
            $planned[] = [$col, $this->d($bare), 'fixture'];
        }

        // 2. EVERY salarySlips doc for this school — structures (incl. the 49
        //    auto-created ones), runs, slips. Baseline was 0, so all are ours.
        // 3. EVERY accounting chartOfAccounts doc — baseline was 0 (payroll
        //    refused for "missing required accounting accounts").
        foreach (['salarySlips', 'accounting'] as $col) {
            try {
                foreach ((array) $this->fb()->firestoreQuery($col, [['schoolId', '==', $S]]) as $r) {
                    $id = (string) ($r['id'] ?? '');
                    $d  = is_array($r['data'] ?? null) ? $r['data'] : [];
                    // Scope proof: the document's OWN schoolId must be ours.
                    if ((string) ($d['schoolId'] ?? '') !== $S) { $foreign[] = "{$col}/{$id}"; continue; }
                    $planned[] = [$col, $id, (string) ($d['type'] ?? '-')];
                }
            } catch (\Throwable $e) { echo "  query error {$col}: " . substr($e->getMessage(), 0, 50) . "\n"; }
        }

        // ── Scope assertions ──
        $bad = [];
        foreach ($planned as [$col, $id, $kind]) {
            if (strpos($id, $S) !== 0) $bad[] = "{$col}/{$id}";
        }
        printf("  planned deletions : %d\n", count($planned));
        printf("  docs whose id is NOT prefixed %s : %d\n", $S, count($bad));
        printf("  docs whose schoolId field is foreign : %d\n", count($foreign));
        foreach (array_slice($bad, 0, 5) as $b)     echo "      BAD  {$b}\n";
        foreach (array_slice($foreign, 0, 5) as $f) echo "      SKIPPED (foreign) {$f}\n";

        if ($bad) { echo "\n  ABORT — refusing to proceed with out-of-scope targets.\n\n"; return; }

        $byCol = [];
        foreach ($planned as [$col, , $kind]) {
            $byCol[$col] = ($byCol[$col] ?? 0) + 1;
        }
        echo "\n  by collection:\n";
        foreach ($byCol as $c => $n) printf("      %-26s %d\n", $c, $n);

        echo "\n  NOT touched by teardown (deliberately):\n";
        echo "      • schools/{$S}  — the school document itself survives; only the\n";
        echo "        attendancePolicy branches this UAT added are reset below.\n";
        echo "      • every other tenant — proven unreachable by the write guard.\n";
        echo "      • staff records that existed before this UAT (only ZZUAT* removed).\n";

        if (!$execute) {
            echo "\n  DRY RUN — nothing deleted. Re-run with `teardown` to execute.\n";
            echo str_repeat('=', 92) . "\n\n";
            return;
        }

        echo "\n  executing…\n";
        $done = 0;
        foreach ($planned as [$col, $id, $kind]) {
            if ($this->guardedDelete($S, $col, $id)) $done++;
        }
        printf("  deleted %d/%d\n", $done, count($planned));

        // Reset the attendancePolicy branches this UAT created.
        $this->guardedUpdate($S, 'schools', $S, [
            'attendancePolicy.workSchedules'                  => [],
            'attendancePolicy.workingWeek.versions'           => [],
            'attendancePolicy.weeklyOffs'                     => [],
            'attendancePolicy.calendarHalfDayPayEnabled'      => false,
            'attendancePolicy.calendarHalfDayPayEffectiveFrom'=> '',
        ]);
        echo "  attendancePolicy UAT branches reset\n";
        $this->bustCalendarCache();
        echo str_repeat('=', 92) . "\n";
        printf("writes: %d   refused: %d\n\n", $this->writes, $this->refused);
    }


    /**
     * READ-ONLY census of leave-type carry-forward configuration.
     *
     * Measures the blast radius of the carry-forward semantic contradiction:
     * Hr::preview_leave_year_closure treats max_carry=0 as "carry NOTHING"
     * (min(unused, 0)), while Hr::allocate_leave_balances treats it as
     * "carry EVERYTHING" (maxCarry > 0 ? min(...) : prevBal). A tenant with
     * carry_forward=true and max_carry=0 therefore gets opposite entitlement
     * depending on which engine ran.
     *
     * Reports counts only. No names, no balances, no writes.
     */
    public function leavetypecensus(): void
    {
        echo "\nLEAVE-TYPE CARRY-FORWARD CENSUS (READ ONLY)\n";
        echo str_repeat('=', 88) . "\n\n";

        try { $rows = (array) $this->fb()->firestoreQuery('leaveTypes', []); }
        catch (\Throwable $e) { echo "  leaveTypes query failed: " . substr($e->getMessage(), 0, 70) . "\n"; $rows = []; }

        if (empty($rows)) {
            // Leave types may live inside the school doc rather than a collection.
            try { $schools = (array) $this->fb()->firestoreQuery('schools', []); }
            catch (\Throwable $e) { echo "  ERROR: " . $e->getMessage() . "\n\n"; return; }
            $rows = [];
            foreach ($schools as $s) {
                $d = is_array($s['data'] ?? null) ? $s['data'] : [];
                foreach ((array) ($d['leaveTypes'] ?? []) as $lt) {
                    if (is_array($lt)) $rows[] = ['id' => (string) ($s['id'] ?? '?'), 'data' => $lt];
                }
            }
            echo "  (leaveTypes read from schools.leaveTypes)\n\n";
        }

        $tot = 0; $cfOn = 0; $affected = 0; $fractional = 0; $bySchool = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $tot++;
            $cf = filter_var($d['carry_forward'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $mc = (float) ($d['max_carry'] ?? 0);
            if ($mc != floor($mc)) $fractional++;
            if (!$cf) continue;
            $cfOn++;
            if ($mc <= 0.0) {
                $affected++;
                $sid = (string) ($r['id'] ?? '?');
                $bySchool[$sid] = ($bySchool[$sid] ?? 0) + 1;
            }
        }

        printf("  leave types scanned            : %d\n", $tot);
        printf("  with carry_forward = true      : %d\n", $cfOn);
        printf("  ...AND max_carry = 0 (AFFECTED): %d\n", $affected);
        printf("  with FRACTIONAL max_carry      : %d  (int cast in allocate path truncates these)\n\n", $fractional);
        if ($affected > 0) {
            echo "  AFFECTED — these get 'carry everything' from allocate_leave_balances\n";
            echo "  but 'carry nothing' from the year-closure preview:\n";
            foreach ($bySchool as $k => $n) printf("      %-24s %d type(s)\n", $k, $n);
        } else {
            echo "  No tenant is affected: no leave type has carry_forward=true with max_carry=0.\n";
            echo "  The contradiction is latent, not live.\n";
        }
        echo "\n" . str_repeat('=', 88) . "\n\n";
    }

    /** READ-ONLY: comp-off ledger usage census. Counts only, no writes. */
    public function compoffcensus(): void
    {
        echo "\nCOMP-OFF LEDGER CENSUS (READ ONLY)\n" . str_repeat('=', 80) . "\n\n";
        try { $rows = (array) $this->fb()->firestoreQuery('compOffLedger', []); }
        catch (\Throwable $e) { echo "  query failed: " . substr($e->getMessage(), 0, 70) . "\n\n"; return; }

        $tot = count($rows); $avail = 0; $consumed = 0; $frac = 0;
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $st = strtolower((string) ($d['status'] ?? ''));
            if ($st === 'consumed') $consumed++; else $avail++;
            $cd = (float) ($d['creditDays'] ?? 0);
            if ($cd != floor($cd)) $frac++;
        }
        printf("  ledger entries      : %d\n  available           : %d\n  consumed            : %d\n  fractional credits  : %d\n\n", $tot, $avail, $consumed, $frac);
        echo $tot === 0
            ? "  No comp-off ledger entries exist in any tenant — the partial-consumption\n  forfeiture is LATENT, not live.\n"
            : "  Comp-off is in use; partial-consumption forfeiture may have occurred.\n";
        echo "\n" . str_repeat('=', 80) . "\n\n";
    }

    /** READ-ONLY: mid-month transfer + department-scoped calendar exposure. */
    public function transfercensus(): void
    {
        echo "\nMID-MONTH TRANSFER / DEPT-SCOPED CALENDAR CENSUS (READ ONLY)\n" . str_repeat('=', 84) . "\n\n";
        $q = function (string $c) { try { return (array) $this->fb()->firestoreQuery($c, []); } catch (\Throwable $e) { return []; } };

        $tl = $q('staffEmploymentTimeline');
        $deptRows = 0; $midMonth = 0;
        foreach ($tl as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            if ((string) ($d['attribute'] ?? '') !== 'department') continue;
            $deptRows++;
            $ef = (string) ($d['effectiveFrom'] ?? '');
            if ($ef !== '' && substr($ef, 8, 2) !== '01') $midMonth++;
        }

        $ev = $q('calendarEvents');
        $scoped = 0;
        foreach ($ev as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $deps = (array) ($d['departments'] ?? []);
            if (!empty($deps)) $scoped++;
        }

        printf("  timeline rows (department)        : %d\n", $deptRows);
        printf("  ...effective MID-month (not day 1): %d\n", $midMonth);
        printf("  calendarEvents scoped to dept(s)  : %d  (of %d total)\n\n", $scoped, count($ev));
        echo ($midMonth === 0 || $scoped === 0)
            ? "  NOT EXPOSED: the payroll/leave department-calendar divergence needs BOTH a\n  mid-month department change AND a department-scoped calendar event. Latent.\n"
            : "  EXPOSED: both preconditions exist — payroll and leave may disagree on expected work.\n";
        echo "\n" . str_repeat('=', 84) . "\n\n";
    }

    /**
     * READ-ONLY. Draft appraisals are hidden from the Teacher app only by a
     * client-side filter; firestore.rules lets a teacher read their own
     * appraisal at ANY status. Counts what is currently exposed.
     */
    public function appraisalcensus(): void
    {
        echo "\nDRAFT APPRAISAL EXPOSURE CENSUS (READ ONLY)\n";
        echo str_repeat('=', 88) . "\n\n";

        try { $rows = (array) $this->fb()->firestoreQuery('appraisals', []); }
        catch (\Throwable $e) { echo "  appraisals query failed: " . substr($e->getMessage(), 0, 70) . "\n\n"; return; }

        $tot = 0; $draft = 0; $withNotes = 0; $bySchool = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $tot++;
            if ((string) ($d['status'] ?? '') !== 'Draft') continue;
            $draft++;
            $sid = (string) ($d['schoolId'] ?? '?');
            $bySchool[$sid] = ($bySchool[$sid] ?? 0) + 1;
            $notes = trim((string) ($d['areasOfImprovement'] ?? $d['areas_improvement'] ?? ''));
            if ($notes !== '') $withNotes++;
        }

        echo "  appraisals total ............... {$tot}\n";
        echo "  status = Draft ................. {$draft}\n";
        echo "  drafts carrying reviewer notes . {$withNotes}\n";
        echo "  tenants with drafts ............ " . count($bySchool) . "\n\n";
        foreach ($bySchool as $sid => $n) { echo "    {$sid} : {$n}\n"; }
        echo "\n  " . ($draft === 0
            ? 'NO EXPOSURE TODAY - defect is latent.'
            : "EXPOSED: {$draft} draft(s) readable by the staff member they describe.") . "\n\n";
    }

    /**
     * READ-ONLY. D-005: a staff member who is ACTIVE while their notice period
     * sits in a decided-but-incomplete state (approved/waived/reduced) is
     * deadlocked — cannot resign again, cannot withdraw (needs 'pending'),
     * cannot complete (needs Inactive).
     */
    public function rehiredeadlockcensus(): void
    {
        echo "\nD-005 REHIRE / NOTICE DEADLOCK CENSUS (READ ONLY)\n";
        echo str_repeat('=', 88) . "\n\n";

        try { $notices = (array) $this->fb()->firestoreQuery('staffNoticePeriods', []); }
        catch (\Throwable $e) { echo "  query failed: " . substr($e->getMessage(), 0, 70) . "\n\n"; return; }

        $stuckStates = ['approved', 'waived', 'reduced'];
        $tot = 0; $nonTerminal = 0; $deadlocked = 0; $bySchool = [];

        foreach ($notices as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $tot++;
            $st = strtolower(trim((string) ($d['status'] ?? '')));
            if (!in_array($st, $stuckStates, true)) continue;
            $nonTerminal++;

            $sid = (string) ($d['staffId'] ?? '');
            if ($sid === '') continue;
            try { $staff = $this->fb()->firestoreGet('staff', $this->fs->docId($sid)); }
            catch (\Throwable $e) { continue; }
            if (!is_array($staff)) continue;

            $ss = strtolower(trim((string) ($staff['status'] ?? $staff['Status'] ?? 'active')));
            if ($ss !== 'inactive') {
                $deadlocked++;
                $sc = (string) ($d['schoolId'] ?? '?');
                $bySchool[$sc] = ($bySchool[$sc] ?? 0) + 1;
                echo "    DEADLOCK staff={$sid} notice={$st} staffStatus={$ss}\n";
            }
        }

        echo "\n  staffNoticePeriods total ....... {$tot}\n";
        echo "  decided-but-incomplete ......... {$nonTerminal}\n";
        echo "  ACTIVE staff w/ stuck notice ... {$deadlocked}\n";
        echo "  tenants affected ............... " . count($bySchool) . "\n\n";
        echo "  " . ($deadlocked === 0
            ? 'NO EXPOSURE TODAY - defect is latent.'
            : "EXPOSED: {$deadlocked} staff member(s) can never resign again.") . "\n\n";
    }

    /** READ-ONLY. Exposure for the salary-effective-dating gap. */
    public function salaryhistorycensus(): void
    {
        echo "\nSALARY EFFECTIVE-DATING EXPOSURE CENSUS (READ ONLY)\n";
        echo str_repeat('=', 88) . "\n\n";

        try { $rows = (array) $this->fb()->firestoreQuery('salarySlips', []); }
        catch (\Throwable $e) { echo "  query failed: " . substr($e->getMessage(), 0, 70) . "\n\n"; return; }

        $struct = 0; $slips = 0; $runs = 0; $archives = 0; $schools = [];
        foreach ($rows as $r) {
            $id = (string) ($r['id'] ?? '');
            $d  = is_array($r['data'] ?? null) ? $r['data'] : [];
            $sc = (string) ($d['schoolId'] ?? '?');
            if (strpos($id, 'SAL_') !== false)               { $struct++;   $schools[$sc] = 1; }
            elseif (strpos($id, 'SLIP_ARCHIVE_') !== false)  { $archives++; }
            elseif (strpos($id, 'SLIP_') !== false)          { $slips++; }
            elseif (strpos($id, 'RUN_') !== false)           { $runs++; }
        }

        echo "  salary structures (SAL_) ....... {$struct}\n";
        echo "  payslips (SLIP_) ............... {$slips}\n";
        echo "  payroll runs (RUN_) ............ {$runs}\n";
        echo "  regeneration archives .......... {$archives}\n";
        echo "  tenants with a structure ....... " . count($schools) . "\n\n";
        echo "  " . ($archives === 0
            ? 'NO REGENERATION HAS EVER RUN - gap is latent.'
            : "{$archives} regeneration(s) exist - review against salary revisions.") . "\n\n";
    }
}
