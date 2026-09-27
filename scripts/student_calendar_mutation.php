<?php
/**
 * MUTATION BATTERY — Student_calendar_service.
 *
 * A green suite is not evidence. This harness deliberately breaks one invariant
 * at a time and asserts the suite NOTICES. A mutant that survives means the
 * property it breaks is claimed but not actually proven.
 *
 * TT-C8's lesson applies: a test placed where it cannot observe the defect
 * proves nothing. Every mutant below is therefore paired with the specific test
 * expected to kill it, and a mutant that fails to APPLY is reported as loudly as
 * one that survives — a silently-skipped mutant is a fake pass.
 *
 * Read-only w.r.t. git. Restores the original file on any exit path, including
 * a fatal error, via register_shutdown_function.
 *
 *   php -d max_execution_time=0 scripts/student_calendar_mutation.php
 */

chdir(__DIR__ . '/..');

$DEFAULT_TARGET = 'application/libraries/Student_calendar_service.php';

// Every file this battery is allowed to touch, snapshotted up front. Phase 5
// spread the invariants across three files — the pure resolver, the I/O reader,
// and the attendance helper — so a single-target harness would have silently
// left two thirds of the new behaviour unmutated, which is exactly the kind of
// fake coverage this script exists to prevent.
$TARGETS = [
    $DEFAULT_TARGET,
    'application/libraries/Student_calendar_reader.php',
    'application/helpers/attendance_helper.php',
    'application/libraries/Student_calendar_store.php',
    'application/views/student_calendar/index.php',
    'application/controllers/Exam.php',
    'application/views/exam/create.php',
    'application/controllers/Academic.php',
    'application/libraries/Timetable_projection_service.php',
    'application/controllers/Student_calendar.php',
    'application/views/academic/index.php',
    'application/libraries/Calendar_service.php',
];


$ORIGINALS = [];
foreach ($TARGETS as $t) {
    $c = file_get_contents($t);
    if ($c === false) { fwrite(STDERR, "cannot read {$t}\n"); exit(1); }
    $ORIGINALS[$t] = $c;
}

$restored = false;
$restore = function () use ($ORIGINALS, &$restored) {
    if ($restored) return;
    foreach ($ORIGINALS as $path => $content) file_put_contents($path, $content);
    $restored = true;
};
register_shutdown_function($restore);

/** [name, find, replace, test-file expected to kill it, OPTIONAL target file] */
$MUTANTS = [
    // NOTE ON THIS MUTANT'S ANCHOR.
    // The first attempt anchored on "// 4. Holiday", which inserted the holiday
    // check AFTER the special-working-day block — a semantic no-op that survived
    // and looked like a missing test. It was a vacuous mutant, not a gap in the
    // suite. The precedence only actually changes if the holiday check is moved
    // ABOVE the SWD block, which is what this anchor does.
    ['special working day no longer beats a holiday',
     "        // 3. Special working day — overrides BOTH holiday and weekly off. A",
     "        if (!empty(\$exceptions['holiday'])) {\n            return self::result(false, false, false, false,\n                self::REASON_HOLIDAY, true, \$from, 'exception');\n        }\n        // 3. Special working day — overrides BOTH holiday and weekly off. A",
     'StudentCalendarServiceTest'],

    ['effective-date boundary becomes exclusive',
     "        if (!is_array(\$v) || !self::describesAWeek(\$v)) return null;",
     "        if (!is_array(\$v) || !self::describesAWeek(\$v)) return null;\n        if ((string) (\$v['effectiveFrom'] ?? '') === \$date) return null;",
     'StudentCalendarServiceTest'],

    ['retroactive gate stops protecting the settled date itself',
     "            && \$from <= \$settledThrough) {",
     "            && \$from < \$settledThrough) {",
     'StudentCalendarServiceTest'],

    ['unconfigured invents a closed school instead of returning NULL',
     "            return self::result(null, null, null, \$halfDay,\n                self::REASON_UNCONFIGURED, false, '', 'unconfigured');",
     "            return self::result(false, false, false, \$halfDay,\n                self::REASON_UNCONFIGURED, false, '', 'unconfigured');",
     'StudentCalendarServiceTest'],

    ['unconfigured silently applies the legacy Sunday rule',
     "        if (\$version === null) {",
     "        if (\$version === null) { \$version = self::legacyStudentPattern('1970-01-01'); }\n        if (false) {",
     'StudentCalendarServiceTest'],

    ['a half day is treated as a closure',
     "        // 7. A normal teaching day.\n        return self::result(true, true, true, \$halfDay,",
     "        // 7. A normal teaching day.\n        if (\$halfDay) { return self::result(false, false, false, true,\n            self::REASON_WORKING, true, \$from, 'pattern'); }\n        return self::result(true, true, true, \$halfDay,",
     'StudentCalendarServiceTest'],

    ['an exam-only day claims regular instruction is running',
     "            return self::result(true, false, true, \$halfDay,\n                self::REASON_EXAM_ONLY, true, \$from, 'exception');",
     "            return self::result(true, true, true, \$halfDay,\n                self::REASON_EXAM_ONLY, true, \$from, 'exception');",
     'StudentCalendarServiceTest'],

    ['the academic session window stops being enforced',
     "        \$start = (string) (\$session['start'] ?? '');",
     "        \$session = []; \$start = (string) (\$session['start'] ?? '');",
     'StudentCalendarServiceTest'],

    ['nth-weekday occurrence is computed with floor instead of ceil',
     "        return (int) ceil(\$d / 7);",
     "        return (int) floor(\$d / 7);",
     'StudentCalendarServiceTest'],

    ['an empty week descriptor counts as configured',
     "        return !empty(\$weekly) || !empty(\$nth);",
     "        return true;",
     'StudentCalendarServiceTest'],

    ['duplicate effective dates are accepted',
     "                    \$errors[] = 'Another school week already takes effect on ' . \$from . '.';",
     "                    \$dup = true;",
     'StudentCalendarServiceTest'],

    ['the nth-weekday predicate drifts from the workforce implementation',
     "                if ((int) \$w === \$occurrence) return true;",
     "                if ((int) \$w === \$occurrence + 1) return true;",
     'StudentCalendarDomainSeparationTest'],

    ['the student service acquires a dependency on the staff authority',
     "        \$dow        = self::dayToken(\$date);",
     "        \$dow        = self::dayToken(\$date); \$unused = Working_calendar_service::DAYS;",
     'StudentCalendarDomainSeparationTest'],

    // ── SCS-1..SCS-4 — the four defects adversarial probing found AFTER the
    //    first battery passed 14/14. Each mutant restores the original bug.
    ['SCS-1: outsideSession claims an unconfigured tenant is configured',
     "                self::REASON_OUTSIDE_SESSION,\n                \$version !== null,",
     "                self::REASON_OUTSIDE_SESSION,\n                true,",
     'StudentCalendarRegressionTest'],

    ['SCS-2: a malformed session window is silently ignored',
     "        if ((\$start !== '' && !self::isIsoDate(\$start))\n            || (\$end !== '' && !self::isIsoDate(\$end))) {",
     "        if (false) {",
     'StudentCalendarRegressionTest'],

    ['SCS-3: an expired version handed in directly is trusted',
     "        if (\$version !== null) {\n            \$vFrom = (string) (\$version['effectiveFrom'] ?? '');",
     "        if (false) {\n            \$vFrom = (string) (\$version['effectiveFrom'] ?? '');",
     'StudentCalendarRegressionTest'],

    ['SCS-4: pre-existing duplicate effective dates stay invisible',
     "            if (isset(\$seen[\$ef])) {",
     "            if (false && isset(\$seen[\$ef])) {",
     'StudentCalendarRegressionTest'],

    ['a weekly-off day is ignored',
     "        if (in_array(\$dow, \$weekly, true)) {",
     "        if (false && in_array(\$dow, \$weekly, true)) {",
     'StudentCalendarServiceTest'],

    // ── PHASE 5 — the reader (I/O layer) ─────────────────────────────────
    $R = 'application/libraries/Student_calendar_reader.php',

    ['5B: the month is resolved against ONE version instead of per day',
     "            [\$version, \$vSource] = \$this->versionFor(\$date);",
     "            [\$version, \$vSource] = \$this->versionFor(sprintf('%04d-%02d-01', \$year, \$monthNum));",
     'StudentCalendarLegacyEquivalenceTest', $R],

    ['5B: an unconfigured tenant stops falling back to the legacy pattern',
     "        return [\n            Student_calendar_service::legacyStudentPattern('1970-01-01'),",
     "        return [\n            null,",
     'StudentCalendarLegacyEquivalenceTest', $R],

    ['5D: the stale working_days setting is applied as truth',
     "        \$v = Student_calendar_service::effectiveVersion(\$this->versions, \$date);",
     "        \$v = Student_calendar_service::effectiveVersion(\$this->versions, \$date);\n        if (\$v === null && !empty(\$this->workingDays)) {\n            \$offs = array_values(array_diff(Student_calendar_service::DAYS,\n                array_map(fn(\$d) => ucfirst(strtolower(substr((string) \$d, 0, 3))), \$this->workingDays)));\n            \$v = ['effectiveFrom' => '1970-01-01', 'weeklyOffs' => \$offs];\n        }",
     'StudentCalendarLegacyEquivalenceTest', $R],

    ['5D: divergence stops being reported to the school',
     "        if (\$savedOffs === \$activeOffs) return null;",
     "        return null;\n        if (\$savedOffs === \$activeOffs) return null;",
     'StudentCalendarLegacyEquivalenceTest', $R],

    ['5B: a settings read failure is reported as a clean empty calendar',
     "            \$this->loadOk      = false;\n        }",
     "            \$this->loadOk      = true;\n        }",
     'StudentCalendarLegacyEquivalenceTest', $R],

    ['tenant isolation: the reader ignores its own schoolId',
     "            \$doc = \$this->fs->get(self::SETTINGS_COLLECTION, \$this->schoolId);",
     "            \$doc = \$this->fs->get(self::SETTINGS_COLLECTION, 'SCH_A');",
     'StudentCalendarLegacyEquivalenceTest', $R],

    // ── PHASE 5E — historical protection in the attendance helper ────────
    $H = 'application/helpers/attendance_helper.php',

    ['5E: editing one day again re-stamps explicit marks elsewhere',
     "        if (\$preserveExplicitMarks\n            && in_array(\$attStr[\$dayNum - 1], ['P', 'A', 'L', 'T'], true)) {\n            continue;\n        }",
     "        if (false) { continue; }",
     'AttendanceHistoricalRestampTest', $H],

    ['5E: protection leaks into the bulk path and stops holidays filling in',
     "        if (\$preserveExplicitMarks\n            && in_array(\$attStr[\$dayNum - 1], ['P', 'A', 'L', 'T'], true)) {",
     "        if (in_array(\$attStr[\$dayNum - 1], ['P', 'A', 'L', 'T'], true)) {",
     'AttendanceHistoricalRestampTest', $H],

    ['5E: an unmarked day stops receiving the holiday stamp',
     "        \$attStr[\$dayNum - 1] = 'H';\n    }\n    return \$attStr;",
     "    }\n    return \$attStr;",
     'AttendanceHistoricalRestampTest', $H],

    // ── THE WRITER — append-only + compare-and-set ───────────────────────
    $W = 'application/libraries/Student_calendar_store.php',

    ['writer: the commit stops carrying a precondition (lost update)',
     "            'precondition' => (\$updateTime !== '')\n                ? ['updateTime' => \$updateTime]\n                : ['exists' => false],",
     "            'precondition' => [],",
     'StudentCalendarStoreTest', $W],

    ['writer: a stale precondition is accepted by using no version',
     "        \$updateTime = (string) (\$doc['__updateTime'] ?? '');",
     "        \$updateTime = '';",
     'StudentCalendarStoreTest', $W],

    ['writer: saving REPLACES history instead of appending',
     "        \$merged = \$existing;\n        \$merged[] = \$record;",
     "        \$merged = [\$record];",
     'StudentCalendarStoreTest', $W],

    ['writer: validation is skipped so retroactive versions land',
     "        if (empty(\$v['ok'])) {\n            return \$this->out(self::INVALID, \$v['errors'], \$existing);\n        }",
     "        if (false) {\n            return \$this->out(self::INVALID, \$v['errors'], \$existing);\n        }",
     'StudentCalendarStoreTest', $W],

    ['writer: a read failure degrades to an empty list instead of refusing',
     "            return \$this->out(self::FAILED, [\n                'The attendance settings could not be read, so the school week was not saved. '\n                . 'Nothing has been changed. Please try again.',\n            ]);",
     "            \$doc = [];",
     'StudentCalendarStoreTest', $W],

    ['writer: a non-SUCCESS commit is reported as success',
     "        if (\$status === 'SUCCESS') {",
     "        if (\$status !== 'PRECONDITION_CONFLICT') {",
     'StudentCalendarStoreTest', $W],

    ['writer: a conflict is downgraded to a plain failure',
     "        if (\$status === 'PRECONDITION_CONFLICT') {\n            return \$this->out(self::CONFLICT, [",
     "        if (false) {\n            return \$this->out(self::CONFLICT, [",
     'StudentCalendarStoreTest', $W],

    ['writer: the tenant id is taken from the record instead of the session',
     "            'docId'        => \$this->schoolId,",
     "            'docId'        => (string) (\$proposed['schoolId'] ?? \$this->schoolId),",
     'StudentCalendarStoreTest', $W],
    // ── THE SCREEN — traps this codebase has hit before ─────────────────
    $V = 'application/views/student_calendar/index.php',

    ['UI: the settled boundary is parsed as LOCAL time (off-by-one east of UTC)',
     "j.settledThrough + 'T00:00:00Z'",
     "j.settledThrough + 'T00:00:00'",
     'StudentCalendarSurfaceContractTest', $V],

    ['UI: a server message reaches innerHTML unescaped',
     "note(\$('scPrevMsg'), escapeHtml(e.message), 'err');",
     "note(\$('scPrevMsg'), e.message, 'err');",
     'StudentCalendarSurfaceContractTest', $V],

    ['UI: the client stops checking the HTTP status (phantom success)',
     "if (!r.ok || !j || j.status === 'error') {",
     "if (false) {",
     'StudentCalendarSurfaceContractTest', $V],

    ['UI: the CSRF hash is not rotated from responses',
     "if (j && j.csrf_token) { CSRF_HASH = j.csrf_token; }",
     "if (false) { }",
     'StudentCalendarSurfaceContractTest', $V],

    // ── CAL-D9 — the exam datesheet builder ──────────────────────────────
    $E  = 'application/controllers/Exam.php',
    $EV = 'application/views/exam/create.php',

    ['CAL-D9: the holiday read drops back to the default staff audience',
     "holidays_in_month(\$y, \$m, 'students')",
     "holidays_in_month(\$y, \$m)",
     'ExamCalendarAudienceTest', $E],

    ['CAL-D9: the staff side is dropped (nobody left to invigilate)',
     "all_holiday_dates('staff')",
     "all_holiday_dates('staff_DISABLED')",
     'ExamCalendarAudienceTest', $E],

    // Anchored with its trailing comment because the ternary appears in BOTH
    // render paths; a bare anchor matched twice and was reported NOT APPLIED.
    ['CAL-D9: an unreadable calendar degrades to NO skipping at all',
     "'weekendDays' => \$examCal['calendarOk'] ? [] : [0],\n            // UX-2.0.1-B P7-B",
     "'weekendDays' => [],\n            // UX-2.0.1-B P7-B",
     'ExamCalendarAudienceTest', $E],

    ['CAL-D9: special working days stop re-opening a declared makeup day',
     "special_working_days_in_month(\$y, \$m, 'students')",
     "special_working_days_in_month_OFF(\$y, \$m, 'students')",
     'ExamCalendarAudienceTest', $E],

    ['CAL-D9: the view goes back to hardcoding Sunday',
     "weekend: <?= json_encode(\$weekendDays ?? [0]) ?> },",
     "weekend: [0] },",
     'ExamCalendarAudienceTest', $EV],

    // ── CAL-P3 — timetable operational-date integration ──────────────────
    $A  = 'application/controllers/Academic.php',
    $PR = 'application/libraries/Timetable_projection_service.php',
    $RD = 'application/libraries/Student_calendar_reader.php',

    ['CAL-P3: cover keys off attendance instead of instruction',
     "\$out['instruction'] = \$r['instruction'];",
     "\$out['instruction'] = \$r['attendance'];",
     'TimetableCalendarOperationalTest', $A],

    ['CAL-P3: the cover screen falls back to the staff audience',
     "holidays_in_month(\$y, \$m, 'students')",
     "holidays_in_month(\$y, \$m)",
     'TimetableCalendarOperationalTest', $A],

    ['CAL-P3: the calendar signal is dropped from the empty-periods response',
     "'completeness' => ['complete' => \$complete, 'cap' => self::SUBSTITUTE_SCAN_LIMIT],\n                'calendar'     => \$this->_student_calendar_for_date(\$dateStr),",
     "'completeness' => ['complete' => \$complete, 'cap' => self::SUBSTITUTE_SCAN_LIMIT],",
     'TimetableCalendarOperationalTest', $A],

    // Anchored on OWNED_FIELDS — the first attempt guessed a constant name that
    // does not exist in this file and was reported NOT APPLIED, which is exactly
    // what that status is for.
    ['CAL-P3: the projection becomes calendar-aware (a timetable semantic change)',
     "    const OWNED_FIELDS = [",
     "    const CAL_MARKER = 'Student_calendar_reader';\n    const OWNED_FIELDS = [",
     'TimetableCalendarOperationalTest', $PR],

    ['CAL-P3: resolveDate stops reporting where its answer came from',
     "\$r['source'] = \$this->isConfigured() ? self::SOURCE_CONFIGURED",
     "\$r['source'] = true ? self::SOURCE_CONFIGURED",
     'TimetableCalendarOperationalTest', $RD],

    // ── CAL-P4 — rollover readiness ──────────────────────────────────────
    $SC = 'application/controllers/Student_calendar.php',

    ['CAL-P4: readiness counts staff holidays instead of student ones',
     "holidays_in_range(\$from, \$to, 'students')",
     "holidays_in_range(\$from, \$to)",
     'StudentCalendarSurfaceContractTest', $SC],

    ['CAL-P4: an unreadable calendar is reported as "no holidays declared"',
     "if (!\$this->holiday_service->load_ok()) return \$out;",
     "if (false) return \$out;",
     'StudentCalendarSurfaceContractTest', $SC],

    ['CAL-P4: readiness is dropped from the policy response',
     "'readiness'       => \$this->_calendar_readiness(),",
     "",
     'StudentCalendarSurfaceContractTest', $SC],

    // ── CAL-P3B — warn + confirm before cover on a closure ───────────────
    $AV = 'application/views/academic/index.php',

    ['CAL-P3B: the gate stops running before the cover is written',
     "        \$gate = \$this->_non_instruction_cover_gate(",
     "        \$gate = null; \$unusedGate = (\n",
     'TimetableCalendarOperationalTest', $A],

    ['CAL-P3B: null (unconfigured / unreadable) also gates, blocking a real lesson',
     "if ((\$cal['instruction'] ?? null) !== false) return null;",
     "if ((\$cal['instruction'] ?? null) === true) return null;",
     'TimetableCalendarOperationalTest', $A],

    ['CAL-P3B: a confirmed override leaves no trace',
     "'Academic::save_substitute — cover confirmed on a non-instruction date '",
     "'' . '",
     'TimetableCalendarOperationalTest', $A],

    ['CAL-P3B: the client confirms automatically without asking a human',
     "if(window.confirm(d.message + '\\n\\nArrange cover anyway?')){",
     "if(true){",
     'TimetableCalendarOperationalTest', $AV],

    ['CAL-P3B: the first attempt already carries the confirmation flag',
     "        _sendCover(false);",
     "        _sendCover(true);",
     'TimetableCalendarOperationalTest', $AV],

    ['CAL-P4b: the session window reverts to a locally invented April literal',
     "\$startMonth = \\Leave_year_service::DEFAULT_START_MONTH;",
     "\$startMonth = 4;",
     'StudentCalendarSurfaceContractTest', $SC],

    ['CAL-P4b: the tenant override is ignored',
     "if (\$sm >= 1 && \$sm <= 12) \$startMonth = \$sm;",
     "if (false) \$startMonth = \$sm;",
     'StudentCalendarSurfaceContractTest', $SC],

    ['CAL-P4b: an unparseable label leaves no reminder at all',
     "'the next twelve months',",
     "'',",
     'StudentCalendarSurfaceContractTest', $SC],

    // ── FALSE-GREEN AUDIT — the two weak assertions found and repaired ───
    ['CAL-P4c: the per-session override is read then discarded',
     "\$overrides = \$school['sessionBoundaries'];",
     "\$discard = \$school['sessionBoundaries'];",
     'StudentCalendarSurfaceContractTest', $SC],

    ['CAL-P4c: the readiness banner exists but never renders',
     "note(\$('scReadiness'), escapeHtml(rd.message), 'warn');",
     "/* removed */;",
     'StudentCalendarSurfaceContractTest', $V],

    ['CAL-P4c: readiness renders even when the check did not run',
     "if (rd.checked && rd.message) {",
     "if (rd.message) {",
     'StudentCalendarSurfaceContractTest', $V],

    // ── CAL-PERF-1 — the calendar read cache ─────────────────────────────
    $CS = 'application/libraries/Calendar_service.php',

    // Anchored on the SET call plus its comment — the bare `_invalidateCache();`
    // appears on both write paths, and the first guessed anchor matched neither.
    ['CAL-PERF-1: a save no longer invalidates the cache (admin cannot see their own event)',
     "        // CAL-PERF-1 — this class is the ONLY writer, so the cached list must die\n        // here or an administrator would not see their own save.\n        \$this->_invalidateCache();",
     "",
     'CalendarServiceCacheTest', $CS],

    ['CAL-PERF-1: a failed read is cached as "no calendar"',
     "            log_message('error', 'Calendar_service::_cachedDocs read failed: ' . \$e->getMessage());\n            return null;",
     "            \$docs = [];",
     'CalendarServiceCacheTest', $CS],

    ['CAL-PERF-1: the cache key drops the tenant (cross-tenant leak)',
     "             . md5(\$this->schoolId . '|' . \$this->session);",
     "             . md5('shared');",
     'CalendarServiceCacheTest', $CS],

    ['CAL-PERF-1: the cache key drops the session',
     "        return 'cal_events_v' . self::CACHE_VERSION . '_'\n             . md5(\$this->schoolId . '|' . \$this->session);",
     "        return 'cal_events_v' . self::CACHE_VERSION . '_' . md5(\$this->schoolId);",
     'CalendarServiceCacheTest', $CS],
];

// The two assignments above are expressions used purely to name the target
// files inline; strip the strings they leave behind in the mutant list.
$MUTANTS = array_values(array_filter($MUTANTS, 'is_array'));

function runSuite(string $filter): bool
{
    $cmd = 'php -d max_execution_time=0 -d memory_limit=2G vendor/phpunit/phpunit/phpunit '
         . '--testsuite Unit --filter ' . escapeshellarg($filter) . ' 2>&1';
    exec($cmd, $out, $code);
    return $code === 0;
}

echo "=== BASELINE ===\n";
foreach (['StudentCalendarServiceTest', 'StudentCalendarDomainSeparationTest'] as $f) {
    if (!runSuite($f)) {
        fwrite(STDERR, "BASELINE FAILS for {$f} — fix before mutating.\n");
        exit(1);
    }
    echo "  {$f}: PASS\n";
}

$killed = $survived = $notApplied = 0;
$problems = [];

echo "\n=== MUTANTS ===\n";
foreach ($MUTANTS as $i => $m) {
    [$name, $find, $repl, $suite] = $m;
    $TARGET   = $m[4] ?? $DEFAULT_TARGET;
    $ORIGINAL = $ORIGINALS[$TARGET];

    $n = $i + 1;
    $count = 0;
    $mutated = str_replace($find, $repl, $ORIGINAL, $count);

    // Line-ending tolerance. attendance_helper.php is CRLF while the library
    // files are LF, so every multi-line anchor written with "\n" silently
    // matched zero times against it — three real mutants reported NOT APPLIED.
    // They were caught only because an unapplied mutant is treated as a failure
    // here; a harness that counted them as passes would have claimed coverage of
    // the historical-protection code while never once breaking it.
    if ($count === 0 && strpos($find, "\n") !== false) {
        $crlfFind = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $find));
        $crlfRepl = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $repl));
        $mutated  = str_replace($crlfFind, $crlfRepl, $ORIGINAL, $count);
    }

    if ($count !== 1) {
        // An ambiguous or missing anchor means the mutant never ran. That is a
        // BROKEN TEST OF THE TESTS, not a pass — report it as a failure.
        $notApplied++;
        $problems[] = "#{$n} NOT APPLIED (anchor matched {$count}x): {$name}";
        printf("  %2d. NOT APPLIED  %s\n", $n, $name);
        continue;
    }

    file_put_contents($TARGET, $mutated);
    $stillGreen = runSuite($suite);
    file_put_contents($TARGET, $ORIGINAL);

    if ($stillGreen) {
        $survived++;
        $problems[] = "#{$n} SURVIVED: {$name}  (expected {$suite} to kill it)";
        printf("  %2d. SURVIVED     %s\n", $n, $name);
    } else {
        $killed++;
        printf("  %2d. killed       %s\n", $n, $name);
    }
}

$restore();

echo "\n=== RESULT ===\n";
echo "  mutants:      " . count($MUTANTS) . "\n";
echo "  killed:       {$killed}\n";
echo "  survived:     {$survived}\n";
echo "  not applied:  {$notApplied}\n";

if ($problems) {
    echo "\nPROBLEMS:\n  " . implode("\n  ", $problems) . "\n";
    exit(1);
}
echo "\nAll mutants killed. The properties are proven, not merely asserted.\n";
exit(0);
