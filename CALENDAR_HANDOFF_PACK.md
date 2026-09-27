# Calendar Programme — Handoff & Independent Review Pack

**Branch `integration/phase2`. Nothing committed, nothing deployed.**
Companion documents: `CALENDAR_PROGRAMME_FINAL_CLOSURE.md` (evidence),
`CALENDAR_BROWSER_VERIFICATION.md` (16-step human checklist),
`firebase-rules/patches/CAL-D1-DEPLOY-PROCEDURE.md` (deployment).

---

# PHASE A — FINAL INVENTORY

## Calendar-owned source — OWNED

| file | role |
|---|---|
| `application/libraries/Student_calendar_service.php` | pure resolver — no I/O, no staff dependency |
| `application/libraries/Student_calendar_reader.php` | settings I/O + per-day month resolution |
| `application/libraries/Student_calendar_store.php` | the only writer — append-only + CAS |
| `application/controllers/Student_calendar.php` | admin surface (index/get_policy/preview/save_version) |
| `application/views/student_calendar/index.php` | School Week screen |
| `application/controllers/Calendar_perf_probe.php` | read-only CLI latency probe (diagnostic) |
| `scripts/student_calendar_mutation.php` | 67-mutant battery |
| `scripts/calendar_view_js_lint.php` | JS syntax gate |
| `scripts/calendar_js_php_agreement.php` | JS↔PHP agreement gate |
| `scripts/calendar_commit_isolation.php` | hunk-ownership analyser (writes nothing) |
| `firebase-rules/tests/cal_d1_tenant_binding.test.js` | differential security suite |

## Calendar-owned tests — OWNED

`StudentCalendarServiceTest`, `StudentCalendarStoreTest`,
`StudentCalendarReaderTest`(*within* LegacyEquivalence), `StudentCalendarRegressionTest`,
`StudentCalendarLegacyEquivalenceTest`, `StudentCalendarDomainSeparationTest`,
`StudentCalendarSurfaceContractTest`, `StudentCalendarBoundaryAttackTest`,
`TimetableCalendarOperationalTest`, `ExamCalendarAudienceTest`,
`AttendanceHistoricalRestampTest`, `CalendarServiceCacheTest`.

## Shared files carrying Calendar hunks — SHARED

| file | Calendar hunks | purpose |
|---|---|---|
| `application/controllers/Attendance.php` | 5 | canonical resolution; `_weekly_closure_days`; CAL-B1 logging |
| `application/controllers/Academic.php` | 3 | cover calendar signal; non-instruction gate |
| `application/controllers/Exam.php` | 8 | CAL-D9 audience union + weekly pattern |
| `application/controllers/Staff_role_check.php` | 2 | CAL-D11 student audience |
| `application/libraries/Calendar_service.php` | 4 | CAL-PERF-1 cache + invalidation |
| `application/helpers/attendance_helper.php` | 2 | CAL-5E explicit-mark preservation |
| `application/views/academic/index.php` | 3 | cover banner, confirm flow, skeleton fix |
| `application/views/exam/create.php` | 1 | server-supplied weekend |
| `application/views/include/header.php` | 1 | School Week menu entry |
| `application/config/routes.php` | 4 lines | 4 routes |
| `firebase-rules/firestore.rules` | 5 hunks / 7 arms | CAL-D1 + CAL-D1b |

## External / platform — EXTERNAL, PLATFORM, HUMAN BLOCKER

| item | class |
|---|---|
| Teacher app timetable display | EXTERNAL |
| Parent app timetable display | EXTERNAL |
| Firestore round-trip latency (~1.9 s/request) | PLATFORM |
| PHP `sess_driver = files` request serialisation | PLATFORM |
| Academic Planner design-system migration | PLATFORM |
| 9 hub-discovery rules suites | PLATFORM |
| Browser verification | HUMAN BLOCKER (credentials) |
| CAL-D1 deployment | HUMAN BLOCKER (authorisation) |

## Dark / duplicate / stale check — CLEAN

Every public Calendar API has production call sites:
`resolveDate` 1, `resolveMonth` 5, `versionFor` 1, `divergence` 5,
`isConfigured` 1, `load_ok` 13, `legacyStudentPattern` 1,
`legacyRuleDescription` 3, `effectiveVersion` 9, `saveVersion` 1, and
`validateVersionAgainst` via the store. Inbound references:
service ← 3 files, reader ← **5 controllers**, store ← 1 controller.
**Nothing is dark, duplicated, stale or unwired.**

---

# PHASE B — COMMIT PREPARATION

## What can be committed cleanly

The **OWNED** lists above — 11 source/script files and 12 test files. They are
new, untracked, and reference no other programme's uncommitted work. They form a
self-contained unit.

## What cannot

Every SHARED file. Measured, not assumed, by
`scripts/calendar_commit_isolation.php`:

```
31 hunks pure Calendar · 369 hunks other programmes · 11 flagged mixed
```

**The analyser's own limitation, stated plainly:** most of those 11 are Calendar
hunks whose *comments* cite other programmes' identifiers (`ACAD-D18`, `SEC-3`,
`TT-C8`) as context. Marker-based separation therefore cannot be trusted to
decide them. An earlier version of the analyser was worse — it flagged a hunk
mixed if *any* line lacked a Calendar marker, which is most lines, and reported
all 41 as blockers.

**Conclusion: extraction is a human review task, not an automated one.**

## Patch manifest

Emitted to `firebase-rules/patches/calendar-hunks/` (9 files). Each hunk's
purpose, dependency and proving test:

| hunk | purpose | depends on | proven by | independently extractable |
|---|---|---|---|---|
| `Attendance.php` `_resolve_non_working_days` | route resolution to the canonical reader | reader | `StudentCalendarLegacyEquivalenceTest` | yes |
| `Attendance.php` `_weekly_closure_days` | collapse the duplicate Sunday authority | reader | `StaffAttendanceGridPolicyMigrationTest` | yes — but the *guard test* must move with it |
| `Attendance.php` CAL-B1 logging ×2 | readability logging on 2 marking paths | none | — (logging only) | yes |
| `attendance_helper.php` `preserveExplicitMarks` | CAL-5E historical protection | none | `AttendanceHistoricalRestampTest` | yes |
| `Exam.php` `_non_scheduling_dates` | CAL-D9 audience union + pattern | reader, Holiday_service | `ExamCalendarAudienceTest` | yes |
| `exam/create.php` `weekendDays` | remove the JS Sunday hardcode | Exam.php hunk | `ExamCalendarAudienceTest` | **no — pairs with Exam.php** |
| `Academic.php` `_student_calendar_for_date` | cover calendar signal | reader | `TimetableCalendarOperationalTest` | yes |
| `Academic.php` `_non_instruction_cover_gate` | warn + confirm | previous hunk | `TimetableCalendarOperationalTest` | **no — depends on the signal hunk** |
| `academic/index.php` confirm flow | client half of the gate | Academic.php gate | `TimetableCalendarOperationalTest` | **no — pairs with the gate** |
| `academic/index.php` `.ac-shim` grid-column | pre-existing skeleton bug | none | `TimetableCalendarOperationalTest` | yes — unrelated to the rest |
| `Calendar_service.php` cache + invalidation | CAL-PERF-1 | none | `CalendarServiceCacheTest` | yes |
| `Staff_role_check.php` | CAL-D11 audience | reader | — (CLI diagnostic) | yes |
| `routes.php` 4 lines | reachability | controller | `StudentCalendarSurfaceContractTest` | yes |
| `header.php` 1 line | menu entry | routes | `StudentCalendarSurfaceContractTest` | yes |
| `firestore.rules` 7 arms | CAL-D1/D1b | none | `cal_d1_tenant_binding.test.js` | yes — **but deploy separately** |

**No commit has been made.** Three hunk pairs must move together; the rest are
independent.

---

# PHASE E — HANDOFFS

## 1 · Teacher app Calendar integration — EXTERNAL

**Problem** The app shows the weekday timetable regardless of whether the school
is teaching that date. A student holiday displays a full schedule.
**Current** `TimetableFirestoreRepository` queries `timetables` directly by
`schoolId` + `sectionKey`. No server endpoint sits between.
**Target** For the displayed date, combine the weekday timetable with the student
calendar's `instruction`. `true` → normal. `false` → still show the schedule but
clearly labelled not operating. `null` → show as today, claim nothing.
**Contract** `CALENDAR_APPS_INTEGRATION_CONTRACT.md`. Recommended: a read-only
`/student_calendar/operational_dates` endpoint (~40 lines on the existing
reader). **Do not** re-implement the resolver in Kotlin — this codebase already
grew six independent week authorities.
**Affected** Teacher app timetable + lesson-plan screens.
**Risks** A second resolver drifting from PHP; `attendanceSettings` has no
Firestore read rule (deny-all), so client-side resolution also widens the
security surface.
**Acceptance** A declared holiday shows the schedule marked non-operational;
`unreadable` never renders as "no classes".
**Tests** Agreement test against the PHP resolver, mirroring
`scripts/calendar_js_php_agreement.php`.

## 2 · Parent app Calendar integration — EXTERNAL

Identical to (1); same repository pattern, same contract, same acceptance.
Parent-facing wording should avoid implying a cancellation when `instruction` is
`null`.

## 3 · PLATFORM-FIRESTORE-LATENCY — PLATFORM

**Problem** A cold `sessionWhere` costs **~1.9 s returning zero documents**,
per PHP process. Measured across three processes: 1936/1899/1905 ms; ~440 ms warm
in-process. Combined with `sess_driver = files` — which locks the session and
serialises concurrent AJAX — and 41 POST call sites on the Academic page, this
produces the observed **1.1-minute** page finish.
**Current** Every module pays it on every request. Calendar is not special.
**Target** Reduce per-request Firestore round-trip cost and stop same-user AJAX
serialising.
**Candidates** (not investigated — platform's call): connection reuse/keep-alive
in the REST client; a non-locking session driver, or `session_write_close()` on
read-only endpoints; batching the page's fan-out.
**Affected** Every module.
**Risks** Session driver changes affect auth everywhere.
**Acceptance** Academic Planner first paint well under 10 s.
**Tests** `application/controllers/Calendar_perf_probe.php` already measures the
read path and can be reused as the before/after instrument.

## 4 · Academic Planner design-system migration — PLATFORM

**Problem** The module carries **309 lines of its own inline CSS** and links no
shared design system, unlike the 14 views that use
`attendance_design_system.css`. The owner's read that it "looks legacy" is
correct.
**Current** `ac-*` classes defined in-view.
**Target** Migrate to the shared system, as the Attendance module did.
**Risks** It is a 5,000-line view; a wholesale restyle risks regressions across
Curriculum, Timetable, Substitutes and Calendar tabs.
**Acceptance** Visual parity plus shared tokens; no functional change.
**Tests** The existing tab contract tests, plus browser review.
**Note** One real defect in it was already fixed as a by-product: `.ac-shim`
lacked `grid-column: 1/-1`, so the loading skeleton rendered at 1/7 width.

---

# PHASE F — INDEPENDENT REVIEW PACK

## Architecture

Timetable = the normal weekday schedule. Student calendar = whether that
schedule is operational on a specific date. The data model already enforces the
split: `timetables` is keyed `{school}_{session}_{sectionKey}_{WEEKDAY}`, so
there is no per-date document to suppress, and none was created.

## Authority map

```
STUDENT                                   STAFF
Student_calendar_service  (pure)          Working_calendar_service   (pure)
Student_calendar_reader   (settings I/O)  Policy_assignment_service  (whose schedule)
Student_calendar_store    (only writer)   Work_schedules             (admin)
Student_calendar          (admin)

              shared ONLY here, by audience filter
                       calendarEvents
              Calendar_service = sole WRITER
              Holiday_service  = sole READER
```

Session boundaries: `Leave_year_service` (existing, reused, **not** duplicated).

## Effective-dating model

Versions live on `attendanceSettings/{schoolId}.studentCalendar.versions[]` —
school-scoped, append-only, selected by `ed_select_for()`. Resolution is
**per-day**, so a month spanning a policy change resolves each half correctly and
a future change cannot reinterpret a past month. Writes refuse a version landing
on or before settled attendance, and refuse duplicate effective dates.

## Security changes

CAL-D1 + CAL-D1b: 5 unbound `isAdmin()` write arms → `if false`; 2 read arms
reordered so `isSameSchool()` binds the tenant before `isStaff()` can
short-circuit it. Proven differentially: 17 cross-tenant operations succeeded
before, all denied after, 9 legitimate paths unaffected.

## Performance changes

CAL-PERF-1: two-tier cache on `Calendar_service::getEvents()`, invalidated on
both write paths, failures never cached. **1,333 ms → 0.9 ms** per subsequent
month load.

## Evidence

- PHP **8,005 tests / 17 errors / 19 failures** — failing set identical to
  baseline, compared test-by-test
- **67 mutants, 67 killed, 0 survived, 0 not applied**, across 12 files
- Emulator: CAL-D1 differential, 26/26; Calendar-relevant rules suites run
  together under isolation, 52 passed
- Gates: JS syntax + JS↔PHP agreement, both green, both proven capable of failing

## Known limitations

Aggregate rules suite invalid on this machine (9 hub-discovery suites);
`REASON_OUTSIDE_SESSION` deliberately unwired; CAL-P4-1 session-scope asymmetry
documented not changed; two legacy fallbacks remain for the unreadable-calendar
path.

## True unresolved blockers

Browser verification (credentials) · CAL-D1 deployment (authorisation) ·
app integration (external repos) · shared-file commit sequencing (owners).

## Files/hunks requiring owner review

`Attendance.php`, `Academic.php`, `Exam.php`, `Calendar_service.php`,
`attendance_helper.php`, `Staff_role_check.php`, `academic/index.php`,
`exam/create.php`, `header.php`, `routes.php`, `firestore.rules` — patch set in
`firebase-rules/patches/calendar-hunks/`.

Also for their owners: two guard tests I updated —
`Phase19LCredentialWiringTest` and `StaffAttendanceGridPolicyMigrationTest`
(re-pointed at the canonical symbol, original intent preserved and documented),
`DraftAppraisalVisibilityTest` (assertion strengthened from a literal to the
property), `fold_isadmin.test.js` (probe collection moved), and
`exam_visibility.test.js` (stale assertion corrected after tracing the
authority).
