# Calendar — Commit Plan, Deployment Readiness & Reviewer Pack

**Branch `integration/phase2`, HEAD `6650720`.**
**Nothing staged. Nothing committed. Nothing deployed.** Verified this round:
no Calendar file appears in `git diff --cached`, and none exists at HEAD.

Calendar behaviour is **FROZEN**. Changes from here only in response to a
verified defect.

---

# 1 · COMMIT PLAN

## Measured, not asserted

`scripts/calendar_commit_isolation.php` over the current tree:

```
41 hunks pure Calendar · 369 hunks other programmes · 1 genuinely mixed
```

**The analyser was wrong twice before it was right, and both corrections
mattered.** First it flagged a hunk MIXED if *any* line lacked a Calendar
marker — which is most lines — reporting all 41 of my hunks as blockers. Then,
after requiring markers from both sides, it still flagged 11, because Calendar
comments deliberately cite `ACAD-D18`, `ACAD-D13`, `SEC-3` and `TT-C8` as
provenance. A comment naming another programme is attribution, not shared code.
Only foreign markers in **code** now count. The honest number is **one**.

## A · Safe to commit immediately — OWNED, self-contained

11 source/script files and 12 test files, all untracked, referencing no other
programme's uncommitted work:

```
application/libraries/Student_calendar_service.php
application/libraries/Student_calendar_reader.php
application/libraries/Student_calendar_store.php
application/controllers/Student_calendar.php
application/controllers/Calendar_perf_probe.php        (read-only CLI diagnostic)
application/views/student_calendar/index.php
scripts/student_calendar_mutation.php
scripts/calendar_view_js_lint.php
scripts/calendar_js_php_agreement.php
scripts/calendar_commit_isolation.php
firebase-rules/tests/cal_d1_tenant_binding.test.js
tests/Unit/StudentCalendarServiceTest.php
tests/Unit/StudentCalendarStoreTest.php
tests/Unit/StudentCalendarReaderTest.php               (in LegacyEquivalence)
tests/Unit/StudentCalendarRegressionTest.php
tests/Unit/StudentCalendarLegacyEquivalenceTest.php
tests/Unit/StudentCalendarDomainSeparationTest.php
tests/Unit/StudentCalendarSurfaceContractTest.php
tests/Unit/StudentCalendarBoundaryAttackTest.php
tests/Unit/TimetableCalendarOperationalTest.php
tests/Unit/ExamCalendarAudienceTest.php
tests/Unit/AttendanceHistoricalRestampTest.php
tests/Unit/CalendarServiceCacheTest.php
```

**Caveat:** committing these ALONE leaves the feature unreachable — the routes,
menu entry and controller wiring live in shared files. Useful as a reviewable
unit; not a working feature on its own.

## B · Shared hunks — owner review required

Patch set: `firebase-rules/patches/calendar-hunks/` (10 files).

| file | CAL hunks | reviewer |
|---|---|---|
| `application/controllers/Attendance.php` | 10 | Attendance owner |
| `application/controllers/Academic.php` | 5 | Academic / Substitute owner |
| `application/controllers/Exam.php` | 8 | Exam owner |
| `application/libraries/Calendar_service.php` | 4 | Academic Planner owner |
| `application/views/academic/index.php` | 3 | Academic Planner owner |
| `application/controllers/Staff_role_check.php` | 2 | (CLI diagnostic — low risk) |
| `application/helpers/attendance_helper.php` | 2 | Attendance owner |
| `application/views/include/header.php` | 1 | navigation owner |
| `application/views/exam/create.php` | 1 | Exam owner |
| `firebase-rules/firestore.rules` | 5 | **security — deploy separately** |

## C · Must move together

| pair | why |
|---|---|
| `Exam.php` `_non_scheduling_dates` **+** `exam/create.php` `weekendDays` | the view reads a variable the controller supplies; either alone breaks the builder |
| `Academic.php` `_student_calendar_for_date` **+** `_non_instruction_cover_gate` | the gate calls the signal |
| `Academic.php` gate **+** `academic/index.php` confirm flow | server returns 409; without the client half a cover simply fails with no way to proceed |
| `routes.php` block **+** `header.php` line **+** `Student_calendar.php` | menu → route → controller; any one missing makes the page unreachable |

Independent of everything else: `academic/index.php` `.ac-shim` `grid-column`
fix — a pre-existing skeleton bug, unrelated to Calendar logic.

## D · The one genuinely mixed hunk

`application/config/routes.php` `@@ -886,0 +1029,102 @@` — 102 added lines of
several programmes' routes. **Calendar's share is 10 contiguous lines** (a
6-line comment block plus 4 `$route['student_calendar…']` entries), immediately
after the HRMS LOP comment. Extracting them is a copy of one contiguous block;
no interleaving.

## E · NEVER commit wholesale

Every file in section B. Each carries substantial uncommitted work from
Placement, SIS, HRMS, Fees/PayOps, Substitute and Lesson Planner.
`firebase-rules/firestore.rules` additionally must never be *deployed* wholesale.

---

# 2 · CAL-D1 DEPLOYMENT READINESS (verification only — nothing run)

Re-verified this round:

| check | result |
|---|---|
| Patch content | **5 hunks, 7 arms removed, 7 added, nothing else.** The two lines that look like non-arm removals are continuations of the multi-line read arms |
| Current repo state matches patch intent | all 5 write arms at `if false`; both read arms bind `isSameSchool()` first |
| Procedure pulls live first | yes — step 1 lists the live ruleset and its sha256 before anything |
| Repo file must not ship | stated explicitly; the 2026-08-21 deployment already had to use an isolated config for exactly this reason |
| Pre-deploy tests | `cal_d1_tenant_binding` **26/26**, differential — 17 attacks denied **and** 9 legitimate paths working |
| Post-deploy verification | re-pull, byte-compare, re-run the differential suite **against the deployed ruleset** |
| Rollback | previous ruleset id from step 1; Firebase retains releases; failure mode of rollback is returning to current exposure, not data loss |

**Internally consistent and current.** No deployment command has been run.

One caution the procedure already carries and which must not be skipped: an
earlier run of that suite passed 22 assertions **for the wrong reason** —
`tenantActive()` was false for both tenants because only `schools` was seeded
and not `schoolControl`. The same-tenant counter-tests are what exposed it.

---

# 3 · HANDOFF COMPLETENESS

| handoff | owner | problem | evidence | affected | recommendation | acceptance | risk of not doing |
|---|---|---|---|---|---|---|---|
| **APPS-CALENDAR-CONTRACT** | Teacher/Parent app teams | apps show a full timetable on a student holiday | `TimetableFirestoreRepository` queries `timetables` directly; no server endpoint between | both apps | read-only `/student_calendar/operational_dates`; **no** second Kotlin resolver | holiday shows schedule marked non-operational; `unreadable` never renders as "no classes" | students and parents told classes run on a closed day |
| **SESSION-BOUNDARY-AUTHORITY** | *resolved — no longer a handoff* | — | `Leave_year_service::boundsForSession()` is the authority; wiring it proven to turn Nov-2025 from 5 non-teaching days into 30 | — | stays unwired; locked by test | — | — |
| **PLATFORM-FIRESTORE-LATENCY** | platform owner | ~1.9 s per PHP process on a cold Firestore read returning **zero** documents | measured ×3: 1936/1899/1905 ms; warm ~440 ms; `sess_driver=files` serialises 41 POST sites | every module | connection reuse; non-locking session or `session_write_close()` on read-only endpoints; reduce page fan-out | Academic Planner first paint well under 10 s | the product feels broken on every page, not only Calendar |
| **SUBSTITUTE hardening** | Substitute owner | should cover on a non-instruction date be refused, not just confirmed? | `Substitute_service` **notifies** the covering teacher | substitute workflow | keep warn+confirm (shipped) or harden — theirs to choose | a teacher is never notified to cover a lesson that is not happening without a human having confirmed | a school can push cover on a declared holiday |
| **CAL-D1 deployment** | you | 5 unbound write arms + 2 unbound read arms in the repo ruleset | emulator: 17 cross-tenant ops succeed before, all denied after | calendarEvents, subjects, exams, examSchedule, appraisals | follow `CAL-D1-DEPLOY-PROCEDURE.md` | 26/26 against the **deployed** ruleset | if the repo file is ever deployed, production loses tenant binding on 5 collections |

Each row carries owner, problem, evidence, affected systems, recommendation,
acceptance criteria and risk. Complete.

---

# 4 · REVIEWER PACK — try to break this

## Architecture

Timetable = the normal weekday schedule. Student calendar = whether it operates
on a date. `timetables` is keyed `{school}_{session}_{sectionKey}_{WEEKDAY}`, so
there is no per-date document — suppression is composed at the consumer and no
timetable data was ever written.

## Authority map

```
STUDENT                                    STAFF
Student_calendar_service (pure)            Working_calendar_service (pure)
Student_calendar_reader  (settings I/O)    Policy_assignment_service
Student_calendar_store   (only writer)     Work_schedules
                shared ONLY via calendarEvents, audience-filtered
                Calendar_service = sole WRITER · Holiday_service = sole READER
Sessions: Leave_year_service (existing, reused — not duplicated)
```

## Invariants a reviewer should attack

1. Past dates never change meaning when a future policy is added.
2. Versions are append-only; effective dates are unique and non-retroactive.
3. Concurrent saves cannot lose each other (CAS on `__updateTime`).
4. A tenant id in a payload cannot redirect a write.
5. Unconfigured ≠ unreadable, everywhere.
6. Explicit human marks (`P/A/L/T`) are never overwritten by a save about
   another day.
7. Special working day beats holiday beats weekly off; half day is a modifier.
8. Unconfigured tenants behave **byte-identically** to the legacy pipeline.
9. The student resolver holds no staff dependency and performs no I/O.
10. No exam date is ever refused on calendar grounds.

## What I would challenge first, in your position

- **The equivalence claim.** It is asserted over 36 months with one exception
  fixture. Try other fixtures — overlapping ranges, `status: cancelled` events,
  events with only `start_date` snake-case.
- **The cache.** `Calendar_service` static memo persists within a request. Two
  different schools in one CLI process are tested; a long-running process is not.
- **`_settled_through()`** — last day of the previous month, computed not
  queried. A school still editing last month's register can be blocked from a
  legitimate change.
- **Server-local time.** `date('Y-m-d')` is the server's, not the school's.
  Documented, single-region, untested across a timezone boundary.
- **The 19 baseline failures.** I compared the failing *set* test-by-test, not
  just totals — but I never fixed them, and one could in principle mask a
  Calendar regression. Worth an independent look.
- **My own test style.** Seven substring collisions were found and fixed in this
  programme. Assume an eighth exists.

## Known limitations

Aggregate rules suite invalid here (9 hub-discovery suites; isolated config
provided). `REASON_OUTSIDE_SESSION` deliberately unwired. CAL-P4-1 session-scope
asymmetry documented, not changed. Two legacy fallbacks remain for the
unreadable-calendar path. **No browser verification has occurred.**

## Evidence to re-run

```
php -d memory_limit=2G vendor/phpunit/phpunit/phpunit --testsuite Unit
php -d max_execution_time=0 scripts/student_calendar_mutation.php     # expect 67/67
php scripts/calendar_view_js_lint.php                                  # expect 0 failures
php scripts/calendar_js_php_agreement.php                              # expect 0 disagreements
php scripts/calendar_commit_isolation.php                              # expect 41/369/1
cd firebase-rules/tests && npx firebase emulators:exec \
  --config firebase.isolated.json --only firestore --project=zenxii-rules-test \
  "npx jest cal_d1_tenant_binding --runInBand --forceExit"             # expect 26/26
```
