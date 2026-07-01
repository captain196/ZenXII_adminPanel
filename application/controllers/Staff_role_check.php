<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Staff_role_check — TEMP read-only CLI diagnostic (NOT web-routable).
 *   php index.php staff_role_check index      STA0001 SCH_D94FE8F7AD
 *   php index.php staff_role_check attendance STA0001 SCH_D94FE8F7AD 2026-06-30
 */
class Staff_role_check extends CI_Controller
{
    public function index($staffId = 'STA0001', $schoolId = 'SCH_D94FE8F7AD')
    {
        if (!is_cli()) { show_404(); return; }
        echo "=== Staff role check: {$schoolId}_{$staffId} ===\n";
        try {
            $this->load->library('firestore_service');
            $this->firestore_service->init($schoolId);
            $doc = $this->firestore_service->get('staff', "{$schoolId}_{$staffId}");
            if (is_array($doc) && $doc) {
                echo "[Firestore staff doc] role=" . ($doc['Role'] ?? $doc['role'] ?? '(none)')
                   . "  status=" . ($doc['Status'] ?? $doc['status'] ?? '(none)') . "\n";
            } else { echo "[Firestore staff doc] NOT FOUND\n"; }
        } catch (\Throwable $e) { echo "[staff doc] ERROR: " . $e->getMessage() . "\n"; }
        try {
            $this->load->library('firebase');
            $user   = $this->firebase->getFirebaseUser($staffId);
            $claims = method_exists($user, 'customClaims') ? $user->customClaims() : ($user->customClaims ?? []);
            echo "[Auth claims] role=" . ($claims['role'] ?? '(EMPTY)') . "  school_id=" . ($claims['school_id'] ?? '(none)') . "\n";
        } catch (\Throwable $e) { echo "[Auth claims] ERROR: " . $e->getMessage() . "\n"; }
        echo "=== end ===\n";
    }

    public function report($staffId = 'STA0001', $schoolId = 'SCH_D94FE8F7AD')
    {
        if (!is_cli()) { show_404(); return; }
        $this->load->library('firestore_service');
        $this->firestore_service->init($schoolId);
        echo "=== Report query test: staffAttendanceSummary where staffId == {$staffId} ===\n";
        try {
            $docs = $this->firestore_service->schoolWhere('staffAttendanceSummary', [
                ['staffId', '==', $staffId],
            ]);
            $docs = (array) $docs;
            echo "rows returned: " . count($docs) . "\n";
            foreach ($docs as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : [];
                echo "  - month=" . ($d['month'] ?? '?') . " staffId=" . ($d['staffId'] ?? '?')
                   . " present=" . ($d['present'] ?? '?') . " dayWise=" . substr((string)($d['dayWise'] ?? ''), 0, 35) . "\n";
            }
        } catch (\Throwable $e) {
            echo "QUERY THREW: " . get_class($e) . " :: " . $e->getMessage() . "\n";
        }
        echo "=== end ===\n";
    }

    /**
     * P5 historical coverage: compare per-day `attendance` docs against the
     * per-month `attendanceSummary.dayWise`. Gaps ⇒ backfill needed before
     * RTDB removal. READ-ONLY.
     *   php index.php staff_role_check coverage SCH_D94FE8F7AD
     */
    public function coverage($schoolId = 'SCH_D94FE8F7AD')
    {
        if (!is_cli()) { show_404(); return; }
        $this->load->library('firestore_service');
        $this->firestore_service->init($schoolId);
        echo "=== P5 coverage: per-day `attendance` vs `attendanceSummary.dayWise` ({$schoolId}) ===\n";

        $rows = [];
        try { $rows = (array) $this->firestore_service->schoolWhere('attendance', []); }
        catch (\Throwable $e) { echo "attendance query failed: " . $e->getMessage() . "\n"; }
        echo "per-day 'attendance' docs: " . count($rows) . "\n";

        $checked = $match = $mismatch = $missingSummary = $shortDayWise = 0;
        $cache = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            $sid = (string)($d['studentId'] ?? ''); $date = (string)($d['date'] ?? '');
            $status = strtoupper((string)($d['status'] ?? ''));
            if ($sid === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $status === '') continue;
            $monthKey = substr($date, 0, 7); $day = (int) substr($date, 8, 2);
            $docId = "{$schoolId}_{$sid}_{$monthKey}";
            if (!array_key_exists($docId, $cache)) {
                try { $cache[$docId] = $this->firestore_service->get('attendanceSummary', $docId); }
                catch (\Throwable $e) { $cache[$docId] = null; }
            }
            $sum = $cache[$docId]; $checked++;
            if (!is_array($sum) || empty($sum)) { $missingSummary++; continue; }
            $dw = (string)($sum['dayWise'] ?? '');
            if (strlen($dw) < $day) { $shortDayWise++; continue; }
            $sChar = strtoupper($dw[$day - 1]);
            if ($checked <= 20) echo "  rec {$sid} {$date}: attendance={$status}  summary.dayWise[{$day}]={$sChar}  " . ($sChar === $status ? 'MATCH' : 'MISMATCH') . "\n";
            if ($sChar === $status) { $match++; }
            else { $mismatch++; }
        }
        echo "checked={$checked} match={$match} mismatch={$mismatch} missingSummaryDoc={$missingSummary} dayWiseTooShort={$shortDayWise}\n";
        echo (($mismatch + $missingSummary + $shortDayWise) > 0
            ? ">>> BACKFILL NEEDED (attendanceSummary gaps vs per-day attendance)\n"
            : ">>> No gaps in sample — attendanceSummary consistent with per-day attendance\n");
        echo "=== end ===\n";
    }

    /**
     * Dump a teacher's subjectAssignments (class-teacher authorization source).
     *   php index.php staff_role_check assignments STA0001 SCH_D94FE8F7AD 2026-27
     */
    public function assignments($teacherId = 'STA0001', $schoolId = 'SCH_D94FE8F7AD', $session = '')
    {
        if (!is_cli()) { show_404(); return; }
        $this->load->library('firestore_service');
        $this->firestore_service->init($schoolId);
        if ($session === '') {
            $sc = $this->firestore_service->get('schools', $schoolId);
            $session = is_array($sc) ? (string)($sc['currentSession'] ?? '2026-27') : '2026-27';
        }
        echo "=== subjectAssignments for teacherId={$teacherId} session={$session} ({$schoolId}) ===\n";
        try {
            $rows = (array) $this->firestore_service->schoolWhere('subjectAssignments', [
                ['teacherId', '==', $teacherId],
                ['session', '==', $session],
            ]);
            echo "rows: " . count($rows) . "\n";
            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                echo "  - className=[" . ($d['className'] ?? '?') . "] section=[" . ($d['section'] ?? '?') . "]"
                   . " subject=[" . ($d['subjectName'] ?? $d['subjectCode'] ?? '') . "]"
                   . " isClassTeacher=" . (!empty($d['isClassTeacher']) ? 'true' : 'false')
                   . " archived=" . (!empty($d['archived']) ? 'true' : 'false') . "\n";
            }
            echo "\n  >> map keys _teacher_can_access would build: {className}|{section}\n";
            echo "  >> caller queries: {class}|Section {section}\n";
        } catch (\Throwable $e) { echo "query failed: " . $e->getMessage() . "\n"; }
        echo "=== end ===\n";
    }

    /**
     * Simulate the FIXED _teacher_can_access against real assignment data.
     *   php index.php staff_role_check canaccess STA0001 SCH_D94FE8F7AD
     */
    public function canaccess($teacherId = 'STA0001', $schoolId = 'SCH_D94FE8F7AD', $session = '')
    {
        if (!is_cli()) { show_404(); return; }
        $this->load->library('firestore_service');
        $this->firestore_service->init($schoolId);
        if ($session === '') {
            $sc = $this->firestore_service->get('schools', $schoolId);
            $session = is_array($sc) ? (string)($sc['currentSession'] ?? '2026-27') : '2026-27';
        }
        $this->load->library('subject_assignment_service', null, 'sas');
        $this->sas->init($this->firestore_service, $schoolId, $session);
        $assignments = $this->sas->getAssignmentsForTeacher($teacherId);

        $norm = function ($c, $s) {
            $c = preg_replace('/^(?:class\s+)+/i', '', trim($c));
            $s = preg_replace('/^(?:section\s+)+/i', '', trim($s));
            return strtolower(trim((string)$c)) . '|' . strtolower(trim((string)$s));
        };
        $map = [];
        foreach ($assignments as $a) {
            if (!empty($a['archived'])) continue;
            $cn = (string)($a['className'] ?? ''); $se = (string)($a['section'] ?? '');
            if ($cn === '' || $se === '') continue;
            $map[$norm($cn, $se)] = true;
        }
        echo "=== FIXED _teacher_can_access simulation for {$teacherId} (session {$session}) ===\n";
        echo "normalized assignment keys: " . implode(', ', array_keys($map)) . "\n\n";
        $tests = [
            ['Class 8th', 'Section Section A'], // app sends "Section A"; save() prepends "Section "
            ['Class 8th', 'Section A'],
            ['Class 8th', 'A'],
            ['Class 9th', 'Section Section B'],
            ['Class 7th', 'Section A'],         // NOT assigned → must DENY
        ];
        foreach ($tests as $t) {
            $k = $norm($t[0], $t[1]);
            echo "  can_access('{$t[0]}', '{$t[1]}') -> key[{$k}] -> " . (isset($map[$k]) ? 'ALLOW' : 'DENY') . "\n";
        }
        echo "=== end ===\n";
    }

    public function attendance($staffId = 'STA0001', $schoolId = 'SCH_D94FE8F7AD', $date = '')
    {
        if (!is_cli()) { show_404(); return; }
        if ($date === '') $date = date('Y-m-d');
        $monthKey = substr($date, 0, 7);
        $day      = (int) substr($date, 8, 2);

        $this->load->library('firestore_service');
        $this->firestore_service->init($schoolId);

        echo "=== Attendance verify: {$schoolId} / {$staffId} / {$date} (day {$day}) ===\n\n";

        // 1) Canonical per-month summary (read by register, dashboard, payroll, /me)
        $sumId = "{$schoolId}_{$staffId}_{$monthKey}";
        $sum   = $this->firestore_service->get('staffAttendanceSummary', $sumId);
        if (is_array($sum)) {
            $dw   = (string) ($sum['dayWise'] ?? '');
            $mark = strlen($dw) >= $day ? $dw[$day - 1] : '(none)';
            echo "[staffAttendanceSummary/{$sumId}]\n";
            echo "  dayWise      : {$dw}\n";
            echo "  >> day {$day} mark : {$mark}  (P=present T=late A=absent L=leave H=holiday V=vacant)\n";
            echo "  counts P/T/A/L/H : " . ((int)($sum['present']??0)) . "/" . ((int)($sum['tardy']??0))
               . "/" . ((int)($sum['absent']??0)) . "/" . ((int)($sum['leave']??0)) . "/" . ((int)($sum['holiday']??0)) . "\n";
            echo "  session      : " . ($sum['session'] ?? '(none)') . "   workingDays=" . ((int)($sum['workingDays']??0)) . "\n\n";
        } else { echo "[staffAttendanceSummary/{$sumId}] NOT FOUND\n\n"; }

        // 2) Per-day attendance doc
        $dayId = "{$schoolId}_{$date}_{$staffId}";
        $d = $this->firestore_service->get('staffAttendance', $dayId);
        if (is_array($d)) {
            echo "[staffAttendance/{$dayId}]\n";
            echo "  status/mark  : " . ($d['status'] ?? $d['mark'] ?? '(none)')
               . "   source=" . ($d['source'] ?? '(none)') . "   lateMinutes=" . ((int)($d['lateMinutes']??0)) . "\n\n";
        } else { echo "[staffAttendance/{$dayId}] NOT FOUND\n\n"; }

        // 3) Audit punches (the OP-7 evidence the admin Punch Log shows)
        try {
            $rows = $this->firestore_service->schoolWhere('attendancePunches', [
                ['staffId', '==', $staffId], ['date', '==', $date],
            ]);
            $rows = (array) $rows;
            echo "[attendancePunches] " . count($rows) . " row(s):\n";
            foreach ($rows as $r) {
                $p = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                echo "  - dir=" . ($p['direction'] ?? '?')
                   . " outcome=" . ($p['outcome'] ?? '?')
                   . " mark=" . ($p['mark'] ?? '-')
                   . " dist=" . ($p['distanceMeters'] ?? '?') . "m"
                   . " acc=" . ($p['accuracy'] ?? '?') . "m"
                   . " mock=" . (!empty($p['mock']) ? 'yes' : 'no')
                   . " lat=" . ($p['lat'] ?? '?') . " lng=" . ($p['lng'] ?? '?')
                   . " at=" . ($p['serverTime'] ?? '?') . "\n";
            }
        } catch (\Throwable $e) {
            echo "[attendancePunches] query failed (composite index likely undeployed — expected): " . $e->getMessage() . "\n";
        }
        echo "\n=== end ===\n";
    }

    /**
     * List all schools + status (read-only) — used to enumerate ACTIVE schools
     * for per-school Gate-3 validation.
     *   php index.php staff_role_check list_schools
     */
    public function list_schools()
    {
        if (!is_cli()) { show_404(); return; }
        $this->load->library('firestore_service');
        $this->firestore_service->init('SCH_D94FE8F7AD'); // any init; 'schools' query is top-level (unscoped)
        echo "=== schools (top-level collection) ===\n";
        try {
            $rows = (array) $this->firestore_service->where('schools', []);
            echo "total school docs: " . count($rows) . "\n";
            foreach ($rows as $r) {
                $id = (string)($r['id'] ?? '');
                $d  = is_array($r['data'] ?? null) ? $r['data'] : [];
                $status = (string)($d['status'] ?? $d['Status'] ?? $d['accountStatus'] ?? '(none)');
                $name   = (string)($d['schoolName'] ?? $d['name'] ?? $d['SchoolName'] ?? '');
                echo "  - {$id}  status={$status}  name={$name}\n";
            }
        } catch (\Throwable $e) { echo "query failed: " . $e->getMessage() . "\n"; }
        echo "=== end ===\n";
    }

    /**
     * GATE-3 one-time reconciliation — rebuild MISSING/STALE `attendanceSummary`
     * docs from the canonical per-day `attendance` collection.
     *
     *   php index.php staff_role_check reconcile_summaries SCH_D94FE8F7AD
     *
     * Firestore-ONLY. ADDITIVE (no RTDB touched, no deletes). IDEMPOTENT
     * (re-running rebuilds nothing once consistent). Reuses the EXISTING
     * summary-generation helpers (Holiday_service + nw_days_from_holidays +
     * enforce_holidays_on_string + parse_attendance_string) and writes the
     * SAME doc shape as Attendance::_syncStudentSummaryToFirestore — no new
     * business logic. Only rebuilds a (student, month) whose summary doc is
     * missing, too short, or disagrees with a per-day record.
     */
    public function reconcile_summaries($schoolId = 'SCH_D94FE8F7AD', $apply = '1')
    {
        if (!is_cli()) { show_404(); return; }
        $doApply = ($apply === '1' || $apply === 'apply' || $apply === 'true');

        $this->load->helper('attendance');
        $this->load->library('firestore_service');
        $this->firestore_service->init($schoolId);
        $fs = $this->firestore_service;

        // Canonical active session (same source the other probes use).
        $sc      = $fs->get('schools', $schoolId);
        $session = is_array($sc) ? (string)($sc['currentSession'] ?? '') : '';

        $monthNames = [1=>'January','February','March','April','May','June',
                       'July','August','September','October','November','December'];

        echo "=== GATE-3 reconcile attendanceSummary ({$schoolId}) mode=" . ($doApply ? 'APPLY' : 'DRY-RUN') . " ===\n";

        // 1) Gather every per-day attendance record (canonical source).
        $rows = [];
        try { $rows = (array) $fs->schoolWhere('attendance', []); }
        catch (\Throwable $e) { echo "attendance query failed: " . $e->getMessage() . "\n"; echo "=== end ===\n"; return; }

        $scanned = 0;
        $groups  = [];   // "sid|YYYY-MM" => ['sid','monthKey','class','section','name','days'=>[day=>mark]]
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            $sid    = (string)($d['studentId'] ?? '');
            $date   = (string)($d['date'] ?? '');
            $status = strtoupper((string)($d['status'] ?? ''));
            if ($sid === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $status === '') continue;
            $monthKey = substr($date, 0, 7);
            $day      = (int) substr($date, 8, 2);
            // dayWise encodes a late/tardy present as 'T' (same rule as save()/_syncDailyToFirestore).
            $mark = ($status === 'T' || !empty($d['late'])) ? 'T' : $status;
            $key  = "{$sid}|{$monthKey}";
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'sid' => $sid, 'monthKey' => $monthKey,
                    'class' => (string)($d['className'] ?? ''),
                    'section' => (string)($d['section'] ?? ''),
                    'name' => (string)($d['studentName'] ?? ''),
                    'days' => [],
                ];
            }
            $groups[$key]['days'][$day] = $mark;
            $scanned++;
        }

        // Scan helper — returns [missingDocs, mismatchRecords, shortDocs] for the current store state.
        $scan = function () use ($fs, $schoolId, $groups) {
            $missing = $mismatch = $short = 0;
            foreach ($groups as $g) {
                $docId = "{$schoolId}_{$g['sid']}_{$g['monthKey']}";
                $sum = null;
                try { $sum = $fs->get('attendanceSummary', $docId); } catch (\Throwable $e) {}
                if (!is_array($sum) || empty($sum)) { $missing++; continue; }
                $dw = (string)($sum['dayWise'] ?? '');
                foreach ($g['days'] as $day => $mark) {
                    if (strlen($dw) < $day) { $short++; continue; }
                    if (strtoupper($dw[$day - 1]) !== $mark) $mismatch++;
                }
            }
            return [$missing, $mismatch, $short];
        };

        // 2) BEFORE snapshot.
        [$missBefore, $mismBefore, $shortBefore] = $scan();

        // 3) Rebuild only the groups that need it.
        $rebuilt = 0; $skipped = 0; $failed = 0;
        foreach ($groups as $g) {
            $sid = $g['sid']; $monthKey = $g['monthKey'];
            $year = (int) substr($monthKey, 0, 4);
            $monthNum = (int) substr($monthKey, 5, 2);
            if ($monthNum < 1 || $monthNum > 12) { continue; }
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
            $docId = "{$schoolId}_{$sid}_{$monthKey}";

            $existing = null;
            try { $existing = $fs->get('attendanceSummary', $docId); } catch (\Throwable $e) {}

            // Decide if rebuild is needed (missing / short / any disagreement).
            $need = !is_array($existing) || empty($existing);
            $dwCur = (is_array($existing) ? (string)($existing['dayWise'] ?? '') : '');
            if (!$need) {
                foreach ($g['days'] as $day => $mark) {
                    if (strlen($dwCur) < $day || strtoupper($dwCur[$day - 1]) !== $mark) { $need = true; break; }
                }
            }
            if (!$need) { $skipped++; continue; }

            // Build dayWise: preserve existing marks, overlay this month's per-day records.
            $dayWise = ($dwCur !== '') ? $dwCur : str_repeat('V', $daysInMonth);
            $dayWise = str_pad($dayWise, $daysInMonth, 'V');
            foreach ($g['days'] as $day => $mark) {
                if ($day >= 1 && $day <= $daysInMonth) $dayWise[$day - 1] = $mark;
            }

            // Holiday/Sunday enforcement — SAME helpers production uses.
            $holidayMap = [];
            try {
                $this->load->library('holiday_service');
                $this->holiday_service->init($fs, (string)$schoolId, (string)$session);
                $holidayMap = $this->holiday_service->holidays_in_month($year, $monthNum);
            } catch (\Throwable $e) { /* no holidays resolvable → Sundays only */ }
            $nonWorking = nw_days_from_holidays($holidayMap, $monthNum, $year);
            $dayWise    = enforce_holidays_on_string($dayWise, $daysInMonth, $nonWorking);

            // Counts/percentage — reuse the shared parser (no new arithmetic).
            $counts = parse_attendance_string($dayWise, $daysInMonth, false);

            $doc = [
                'schoolId'   => $schoolId,
                'studentId'  => $sid,
                'type'       => 'student',
                'className'  => Firestore_service::classKey($g['class']),
                'section'    => Firestore_service::sectionKey($g['section']),
                'month'      => $monthKey,
                'monthLabel' => "{$monthNames[$monthNum]} {$year}",
                'session'    => $session,
                'dayWise'    => $dayWise,
                'present'    => $counts['present'],
                'absent'     => $counts['absent'],
                'leave'      => $counts['leave'],
                'holiday'    => $counts['holiday'],
                'tardy'      => $counts['late'],
                'percentage' => $counts['percent'],
                'updatedAt'  => date('c'),
                'updatedBy'  => 'cli_reconcile',
            ];
            if ($g['name'] !== '') $doc['studentName'] = $g['name'];

            if (!$doApply) {
                echo "  [DRY] would rebuild {$docId} dayWise={$dayWise}\n";
                $rebuilt++;
                continue;
            }
            try {
                $ok = (bool) $fs->set('attendanceSummary', $docId, $doc, true);
                if ($ok) { $rebuilt++; echo "  rebuilt {$docId} dayWise={$dayWise}\n"; }
                else { $failed++; echo "  FAILED  {$docId} (set returned false)\n"; }
            } catch (\Throwable $e) {
                $failed++; echo "  FAILED  {$docId}: " . $e->getMessage() . "\n";
            }
        }

        // 4) AFTER snapshot (fresh reads).
        [$missAfter, $mismAfter, $shortAfter] = $doApply ? $scan() : [$missBefore, $mismBefore, $shortBefore];

        // 5) Verification report.
        echo "\n---------- RECONCILIATION REPORT ----------\n";
        echo "total per-day records scanned : {$scanned}\n";
        echo "(student, month) groups       : " . count($groups) . "\n";
        echo "missing summary docs (before) : {$missBefore}\n";
        echo "mismatch records     (before) : {$mismBefore}\n";
        echo "too-short docs       (before) : {$shortBefore}\n";
        echo "groups rebuilt                : {$rebuilt}\n";
        echo "groups already consistent     : {$skipped}\n";
        echo "write failures                : {$failed}\n";
        echo "remaining missing docs (after): {$missAfter}\n";
        echo "remaining mismatch     (after): {$mismAfter}\n";
        echo "remaining too-short    (after): {$shortAfter}\n";
        $pass = $doApply && ($missAfter === 0 && $mismAfter === 0 && $shortAfter === 0 && $failed === 0);
        echo ($pass ? ">>> GATE-3 TARGET MET: missingSummaryDoc=0 mismatch=0\n"
                    : ($doApply ? ">>> GATE-3 NOT MET — review failures above\n" : ">>> DRY-RUN only — re-run with apply flag to write\n"));
        echo "=== end ===\n";
    }
}
