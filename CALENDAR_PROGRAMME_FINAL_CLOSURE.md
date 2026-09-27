# ZenX Calendar Programme — Final Closure

> **SUPERSEDED — see `CALENDAR_INTEGRATION_READINESS.md` (authoritative).**
> That document corrects this one on dependencies, isolation and closure.
> Two specific corrections: `schoolWhereOrFail` IS required (transitively, via
> `Holiday_service::_query_firestore()` line 422), and an eighth symbol
> (`firestoreCommitBatchStatus`) exists and is already satisfied at HEAD.


**Branch `integration/phase2`, HEAD `25df816`. Nothing committed. Nothing deployed.**
Supersedes `CALENDAR_PROGRAMME_CLOSURE.md` where they differ.

---

## COMPLETE AND PROVEN

Proven means: implemented, executed, mutation-tested with every mutant applied
and killed, and — for security — proven differentially on a real emulator.

| item | proof |
|---|---|
| One canonical student calendar authority | census: **zero** duplicate `calendarEvents` readers outside `Holiday_service`; zero writers outside `Calendar_service` |
| Effective-dated, append-only versions | `validateVersionAgainst()` refuses retroactive and duplicate dates; store never edits or removes a version |
| Concurrent-write protection | compare-and-set on `__updateTime`; a concurrent save is refused, not merged |
| Tenant isolation | a `schoolId` in the payload cannot redirect the write; tenant comes from the session |
| Unreadable ≠ unconfigured | `load_ok()` / `source: unreadable` distinguished throughout |
| Historical immutability | future policy cannot change a past date — asserted over 300 consecutive dates |
| Explicit human marks preserved | `preserveExplicitMarks` stops a save about one day re-stamping `H` over `P/A/L/T` elsewhere |
| Holidays / SWD / half days / nth-weekday | precedence derived from observed behaviour, not assumed; half day is a modifier, never a closure |
| Staff/student separation | source-level: the student service references no staff symbol and performs no I/O; the nth-weekday duplication is pinned by a 175-combination agreement sweep |
| Legacy behaviour for unconfigured tenants | **byte-identical** to the old pipeline across 36 consecutive months, labels included |
| Exam integration | advisory only; a test pins that no exam date is ever refused on calendar grounds |
| Timetable/cover integration | timetable data untouched; projection stays weekday-keyed; gate runs before the write |
| CAL-D1 security | 17 cross-tenant operations proven to succeed before, all denied after, 9 legitimate paths still working |
| JS ↔ PHP agreement | the **real** `zxbIsWorkingDay()` compared to PHP over 400 dates — 84 non-teaching, exact match |
| Date arithmetic | leap years incl. the 1900/2000 century rule, all month lengths, 5th-occurrence overflow, 1,826-day weekday sweep |

**Totals:** PHP **7,997 tests / 17 errors / 19 failures** — failing set *identical*
to baseline, compared test-by-test. **63 mutants, 63 killed, 0 survived, 0 not
applied.** Rules suites relevant to Calendar, run together under an isolated
emulator config: **52 passed, 0 failed.**

---

## COMPLETE BUT NOT BROWSER-VERIFIED

Implemented and source-proven; **nobody has looked at them in a browser.**

- School Week screen (`/student_calendar`) — rendering, preview, save, 409 reload, read-only mode
- Divergence banner and its "load into editor" action
- Rollover readiness banner
- Substitute non-instruction warning and confirmation dialog
- Exam datesheet builder skipping the corrected date set

Route reachability **is** proven by HTTP: `/student_calendar` → 307 → `admin_login`,
identical to the known-good `/attendance`; a bogus route → 404. Rendering is not.

---

## EXTERNAL APP HANDOFFS

**APPS-CALENDAR-CONTRACT** — Teacher and Parent apps read `timetables` directly
from Firestore via the client SDK; no endpoint in this repository sits between
them and the data. Full contract in `CALENDAR_APPS_INTEGRATION_CONTRACT.md`.
Recommended: a read-only `/student_calendar/operational_dates` endpoint (~40
lines on the existing reader) rather than a second resolver in Kotlin.

---

## EXTERNAL PROGRAMME HANDOFFS

**SUBSTITUTE-CALENDAR-INTEGRATION** — *reduced, not closed.* Warn + explicit
confirmation is shipped on the Calendar side. Whether the Substitute programme
wants to harden that to a refusal is theirs; `Substitute_service` is untouched
and asserted so.

**SESSION-BOUNDARY-AUTHORITY** — **RESOLVED, no longer a handoff.** See below.

**HUB-DISCOVERY RULES SUITES** — 9 of 60 rules suites discover the emulator via
the hub rather than pinning `port: 18080`, and fail with `ECONNREFUSED` in an
aggregate run. Pre-existing, unrelated to Calendar, and owned by whoever wrote
them.

---

## DEPLOYMENT ITEMS

**CAL-D1** — patch implemented, emulator-proven, **NOT deployed**. Full
procedure in `firebase-rules/patches/CAL-D1-DEPLOY-PROCEDURE.md`. The one-line
summary: never deploy `firebase-rules/firestore.rules` wholesale — it is 1,351
added lines of which **7 arms are Calendar's**.

---

## ENVIRONMENT LIMITATIONS

**Aggregate rules suite is not a valid signal on this machine.** It reports ~890
failures, but every suite passes in isolation — including ones I never touched
(`payment_claim_primitive` 7/7 alone). The cause is the 9 hub-discovery suites,
not port collision: an isolated config (`firebase-rules/tests/firebase.isolated.json`,
hub 14400) removes every port warning and the aggregate still fails.

**This is an infrastructure failure and must never be reported as a Calendar
regression.** Use per-suite runs, or the isolated config with port-pinned suites.

**Authenticated browser verification is impossible here** — it needs a real
login; creating a test account is forbidden and using the operator's credentials
is not mine to do.

---

## SESSION BOUNDARY — investigated and decided

1. **Authoritative?** Yes. `Leave_year_service::boundsForSession()` resolves the
   real ZenX `YYYY-YY` label (`2026-27` → `2026-04-01 … 2027-03-31`), honours a
   tenant `academicYearStartMonth` (January start verified), honours a
   `sessionBoundaries` override, and returns null only for a malformed label.
2. **Safe to consume?** Yes — pure, static, no I/O, already live via
   `prorateEntitlement()`. Reading a pure function is not taking ownership.
3. **Would wiring preserve history?** **NO — proven.** For a school in 2026-27,
   passing the session window turns November 2025 from **5** non-teaching days
   into **30**; every month of every previous session becomes a total closure.
   Months inside the session are unaffected.
4. **Ambiguity?** A malformed label returns null, and no tenant has either
   config field today — so every school currently gets the April default.
5. **Decision — made autonomously on that evidence:** the authority is consumed
   for the advisory readiness window and **`REASON_OUTSIDE_SESSION` stays
   unwired for resolution.** A test now pins the reasoning so it cannot be wired
   casually.

---

## KNOWN NON-BLOCKING LIMITATIONS

- **CAL-P4-1 (LOW)** — `Calendar_service` lists with `sessionWhere` while
  `Holiday_service` reads with `schoolWhereOrFail`. Benign for resolution (dates
  are absolute, so history stays stable — which is correct), but an event dated
  into a later session is applied while invisible in that session's planner.
  Session-scoping the reader would break historical resolution, so it stands.
- **Two legacy fallbacks remain** (`attendance_helper.php:38`,
  `Attendance.php:5714`) — reached only when the calendar is unreadable, and
  documented as such.
- **`get_calendar_events` performance** — observed pending at 1.1 min on the
  Academic Planner. Real, and never investigated.
- **Academic Planner is un-migrated** — 309 lines of its own inline CSS, no
  shared design system, unlike the 14 views that use one. Not a defect.

---

## FILE OWNERSHIP

### Calendar-owned (wholly mine, untracked — safely committable as a unit)

```
application/libraries/Student_calendar_service.php   0627f59cc2ff
application/libraries/Student_calendar_reader.php    0fb40d9ce469
application/libraries/Student_calendar_store.php     036ffa9088ea
application/controllers/Student_calendar.php         05b286e4c5bd
application/views/student_calendar/index.php         523ce2389b77
scripts/student_calendar_mutation.php                d26569983ba7
scripts/calendar_view_js_lint.php                    9d3369d47f25
scripts/calendar_js_php_agreement.php                4776e5469769
firebase-rules/tests/cal_d1_tenant_binding.test.js   e767d4df6cad
tests/Unit/StudentCalendar{Service,Store,Reader…}Test.php  (9 files)
tests/Unit/{TimetableCalendarOperational,ExamCalendarAudience,AttendanceHistoricalRestamp}Test.php
```

### Shared — NOT committable wholesale

| file | added lines (all programmes) | Calendar's |
|---|---|---|
| `application/controllers/Attendance.php` | 1,669 | ~21 |
| `firebase-rules/firestore.rules` | 1,351 | **7 arms** |
| `application/views/academic/index.php` | 1,138 | ~8 |
| `application/controllers/Academic.php` | 929 | ~13 |
| `application/config/routes.php` | 329 | 5 |
| `application/views/include/header.php` | 221 | 1 |
| `application/controllers/Exam.php` | 169 | 18 |
| `application/controllers/Staff_role_check.php` | 43 | 5 |
| `application/helpers/attendance_helper.php` | 35 | 3 |
| `application/views/exam/create.php` | 10 | 2 |

### Protected TT-C8 fingerprints — unchanged for the whole programme

```
Timetable_plan_store.php     c435c8089eeb585586974743beab09e1
Timetable_plan_service.php   a2fcb038eaaa80802c1fce89100d9efa
```

### Commit isolation

**Calendar-owned files: isolatable and safe to commit as one unit.**
**Shared files: NOT isolatable** — each mixes several programmes' uncommitted
work in the same regions. Committing any of them wholesale would ship
unreviewed work from Placement, SIS, HRMS, Substitute and Lesson Planner.

**No commit has been made, and none should be until the shared hunks are either
landed by their owners or extracted deliberately.**

### Preserved patches

```
firebase-rules/patches/CAL-D1-tenant-binding.patch      (5,217 bytes, 7 arms)
firebase-rules/patches/CAL-D1-DEPLOY-PROCEDURE.md
firebase-rules/tests/firebase.isolated.json             (isolated emulator config)
```

---

## HUMAN VERIFICATION CHECKLIST

Roughly ten minutes. Console open throughout — a red line is a failure even if
the screen looks right.

**1. Navigation** — `localhost/ZenX/school/student_calendar`, or sidebar
**Attendance → School Week**.
*Expect:* the page loads with a "In force today" card.
*Failure:* 404, redirect to login, or a permanently spinning "Loading…".

**2. Unconfigured state** — a school that has never set a week.
*Expect:* Source reads *"Original rule (not yet configured)"*, history shows the
empty-state text, and non-teaching days are Sundays only.
*Failure:* it claims to be configured, or shows Saturdays closed.

**3. Preview** — tick **Sat**, watch the month grid.
*Expect:* Saturdays shade within a second, each labelled.
*Failure:* grid unchanged, or an error under the calendar.

**4. Effective-date guard** — try to set a date in a past month.
*Expect:* the picker will not offer it (minimum is the day after the settled
date). If typed anyway, the save is refused with an explanation.
*Failure:* it saves. **Report immediately** — that is a history rewrite.

**5. Save** — pick a valid future date, press Save.
*Expect:* green confirmation naming the date, history gains a row marked *in
force* on/after that date, past rows unchanged.
*Failure:* a blank page or silent nothing → CSRF (check console for a 403).

**6. Divergence** — only if Attendance Settings holds a different `working_days`.
*Expect:* an amber banner explaining the mismatch and a **Load these days into
the editor** button that fills the editor **without saving**.
*Failure:* it saves by itself. That would be the bug the whole flow exists to
prevent.

**7. Read-only** — a user with Attendance *view* but not *manage*.
*Expect:* banner explaining, all inputs disabled, no Save.
*Failure:* the form is usable.

**8. Substitute confirmation** — Academic Planner → Substitutes, pick a teacher
and a **declared holiday** date.
*Expect:* an amber note saying no classes are scheduled; periods still listed.
Pressing Save raises a confirm dialog; **Cancel** leaves nothing saved,
**OK** saves.
*Failure:* it saves with no dialog, or the dialog never appears (check console —
a JS error here would be exactly the class already fixed once).

---

## DEPLOYMENT CHECKLIST

1. **Nothing is deployable yet.** No commit exists.
2. Land or extract the shared hunks before any Calendar commit.
3. PHP/Firestore code needs no migration — unconfigured tenants behave exactly
   as before, by construction and by test.
4. `firestore.rules` deploys **separately** and only via
   `CAL-D1-DEPLOY-PROCEDURE.md` — pull live first, apply 7 arms to the pulled
   file, diff (must show exactly 7 arms), run the differential suite (26/26,
   including the same-tenant counter-tests), deploy from an isolated config,
   re-pull and re-verify.
5. No index changes are required.
6. Apps require their own release for the timetable contract.
