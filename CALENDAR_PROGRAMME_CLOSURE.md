# ZenX Calendar Programme — Closure Report

> **SUPERSEDED — see `CALENDAR_INTEGRATION_READINESS.md` (authoritative).**
> That document corrects this one on dependencies, isolation and closure.
> Two specific corrections: `schoolWhereOrFail` IS required (transitively, via
> `Holiday_service::_query_firestore()` line 422), and an eighth symbol
> (`firestoreCommitBatchStatus`) exists and is already satisfied at HEAD.


**Branch `integration/phase2`. Nothing committed, nothing deployed.**
Every state below is reported separately and none are collapsed.

| State | Value |
|---|---|
| IMPLEMENTED | **YES** — Phases 1–4 |
| EXECUTED | **YES** — 7,972 PHP tests, 1,625 rules tests, 52 mutants |
| PROVEN | **YES at unit/mutation/emulator level.** No browser click-through; no production run |
| INDEPENDENTLY VERIFIED | **NO** — self-review only |
| COMMITTED / MERGED / DEPLOYED / PRODUCTION-ACTIVE | **NO / NO / NO / NO** |

---

## 1. The authority model, as it now stands

```
STUDENT DOMAIN                              STAFF DOMAIN
──────────────                              ────────────
Student_calendar_service   (pure resolver)  Working_calendar_service   (pure maths)
Student_calendar_reader    (settings I/O)   Policy_assignment_service  (whose schedule)
Student_calendar_store     (the ONE writer) Work_schedules             (admin surface)
Student_calendar           (admin surface)

              shared ONLY here, by audience filter
                          │
                   calendarEvents
            Calendar_service = sole WRITER
            Holiday_service  = sole READER
```

Verified by census: **zero** duplicate `calendarEvents` readers outside
`Holiday_service`, and **zero** remaining hardcoded student-week authorities
except the two documented legacy fallbacks (`attendance_helper.php:38`,
`Attendance.php:5714`), which are reached only when the calendar is unreadable.

---

## 2. Phase 4 — Academic Session Rollover

### The finding: rollover needs no Calendar carry, and must not have one

`scripts/rollover_academic_session.php` carries `curriculum`, `timetables`,
`subjectAssignments` and `timetableSettings` — all session-scoped
(`{school}_{session}`). It never touches `attendanceSettings/{schoolId}`.

That is **correct by construction**, because the student calendar is
**school-scoped and effective-dated**:

- A weekly pattern effective from `2026-04-01` continues into every later
  session automatically. No copy is needed.
- Copying it at rollover would duplicate a version — and
  `validateVersionAgainst()` would **refuse** it on the duplicate-`effectiveFrom`
  guard.
- Dated holidays are deliberately excluded (*"holidays change year-over-year"*),
  which is right: last year's Diwali is not this year's Diwali.

So principles A–F from the brief are already satisfied:

| principle | status |
|---|---|
| A. weekly pattern carries as draft/suggestion | **Better than asked** — it genuinely persists, and any CHANGE still requires an explicit, non-retroactive, effective-dated decision |
| B. nth-weekday rules carry | same mechanism, same guarantee |
| C. dated holidays do not carry | already true, deliberately |
| D. recurring events as templates | **no recurring-event concept exists** in `calendarEvents` — nothing to carry; building one is new scope, not rollover |
| E. government holidays are suggestions only | **no feed exists** — nothing to guard against |
| F. historical integrity | rollover cannot touch `attendanceSettings`, so past calendar versions are unreachable by it |

### The real gap, and what was built

A new session begins with the weekly pattern intact and **no dated holidays**,
and nobody is told. Attendance then treats every festival as an ordinary
teaching day until somebody notices.

Closed by **reporting**, not by copying: `Student_calendar::_calendar_readiness()`
counts declared student holidays in the twelve months ahead and the School Week
screen says so when there are none. It carries nothing, writes nothing, decides
nothing — the school still enters its own holidays.

**CORRECTED — see §9.** This originally said no session-window helper existed
and used a rolling twelve months. That was a search failure on my part:
`Leave_year_service::boundsForSession()` IS that authority. The readiness check
now asks it for the real session window and reads its `DEFAULT_START_MONTH`
rather than re-typing an April literal.

---

## 3. Findings this programme produced

| id | severity | status |
|---|---|---|
| **CAL-D1** | CRITICAL | **FIXED in repo, NOT deployed.** 17 cross-tenant ops proven to succeed on the emulator; patch closes all 17 and keeps 9 legitimate paths working |
| **CAL-D1b** | HIGH | **FIXED.** `isStaff()` on the LEFT of a disjunction short-circuited the same-school test — any staff of any school could read any school's exams |
| **CAL-D9** | HIGH | **FIXED.** Exam builder used the staff audience *and* hardcoded `weekend:[0]` in JavaScript |
| **CAL-D10** | HIGH | **CLOSED.** `attendanceSettings.working_days` was configurable, saved, displayed, health-checked — and read by nothing |
| **CAL-D11** | MEDIUM | **FIXED.** `Staff_role_check` built student summaries on the staff calendar |
| **CAL-B1** | MEDIUM | **CLOSED.** ACAD-D18's readability guard now covers all three marking paths |
| **CAL-5E** | MEDIUM | **FIXED.** Editing one day re-stamped `H` over explicit marks elsewhere in the month |
| **SCS-1…4** | — | four defects in my own resolver, found by adversarial probing after 39 tests and 14 mutants had passed |
| **CAL-P4-1** | LOW | **DOCUMENTED, not changed.** Writer is session-scoped (`sessionWhere`), reader is school-scoped (`schoolWhereOrFail`). Benign for resolution — dates are absolute, so history stays stable — but an event dated into a later session is applied while invisible in that session's planner list |

---

## 4. Handoffs — other programmes own these

| id | item |
|---|---|
| **SUBSTITUTE-CALENDAR-INTEGRATION** | Cover on a non-instruction date: warn only (shipped), warn + confirm, or refuse. `Substitute_service` **notifies** the covering teacher, so today a school can push someone to cover a lesson on a declared holiday. Calendar side is advisory; the write semantics belong to the Substitute programme |
| **APPS-CALENDAR-CONTRACT** | Teacher and Parent apps read `timetables` **directly from Firestore** via the client SDK. There is no endpoint in this repo to change. Contract needed: apps should combine the weekday timetable with the student calendar's `instruction` for the displayed date. Requires an app release |
| **EXAM-VISIBILITY-TEST** | `exam_visibility.test.js` → `admin → write exam ALLOWED` (dated 6 June) contradicts the server-only posture deployed 21 August, and asserts a capability no app exercises. Left red deliberately — it's the Exam programme's policy assertion |
| **CAL-D1-DEPLOY** | The rules patch is tested and preserved at `firebase-rules/patches/CAL-D1-tenant-binding.patch`. Deployment is a separate authorised action |

---

## 5. Evidence

- **PHP suite:** 7,972 tests / 17 errors / 19 failures — failure set identical to
  the pre-programme baseline. **0 introduced.** Every failing class was
  cross-checked against my changed symbols: no matches.
- **Rules suite:** 1,625 tests, 980 passing against the patched ruleset, 1
  deliberate red (the Exam handoff above).
- **Mutation:** **52 mutants, 52 killed, 0 survived, 0 not applied**, across ten
  files including two views and a JavaScript-bearing template.
- **Emulator:** CAL-D1 proven differentially — 17 attacks succeed before, all
  denied after, 9 legitimate paths unaffected.

### Harness defects found in my own work

Worth recording, because each was a *fake pass* that the discipline caught:

1. A **vacuous mutant** whose anchor made the injection a semantic no-op.
2. **CRLF vs LF** — multi-line anchors matched zero times in a CRLF file; three
   mutants silently NOT APPLIED.
3. **Substring collisions, four times** — asserting a name that the mutated code
   still contained (`working_days` inside `special_working_days_in_month`;
   `load_ok()` present at another call site; `_calendar_readiness` surviving as
   a method definition). Assertions now pin exact call sites or count occurrences.
4. **One-of-two render paths** — `assertStringContainsString` proved only that
   *one* path had a fail-safe; a mutant broke the other and survived.
5. A **test fake** using `''` as its "do not force" sentinel, so the
   empty-commit-status case was never exercised.
6. **A vacuous emulator green** — seeding only `schools` left `tenantActive()`
   false for both tenants, so every `assertFails` passed for the wrong reason.
   The same-tenant **counter-tests** are what exposed it.

---

## 6. Shared-file ownership

Nothing here is committable wholesale. Exact measured shares:

| file | added lines (all programmes) | mine |
|---|---|---|
| `application/config/routes.php` | 329 | **5** |
| `application/views/include/header.php` | 221 | **2** |
| `application/controllers/Attendance.php` | ~1,609 | **~60** |
| `application/controllers/Exam.php` | 169 | **18** |
| `application/controllers/Academic.php` | 859 | **10** |
| `application/views/academic/index.php` | 1,095 | **4** |
| `firebase-rules/firestore.rules` | 1,367 | **48** |

Byte-identical all programme: `Timetable_plan_store.php` (`c435c8089eeb`),
`Timetable_plan_service.php` (`a2fcb038eaaa`), `Working_calendar_service.php`,
`Holiday_service.php`.

---

## 7. What is NOT done

- **Not committed, not deployed, not production-active.**
- **No browser click-through** of the School Week screen or the cover banner.
- **Session-window enforcement is unwired.** `REASON_OUTSIDE_SESSION` is
  implemented and tested but no production caller supplies a window, because no
  session-window helper exists. Wiring it would change behaviour for existing
  tenants and needs a session-boundary authority first — deliberately deferred.
- **Recurring events / government-holiday import**: no model exists; both remain
  future scope rather than gaps.


---

## 8. Verification round (added after the closure report)

### Local verification — what was actually attempted, and what it proved

The previous report said browser verification was missing and stopped there.
It was attempted instead.

| check | method | result |
|---|---|---|
| server reachable | `curl localhost/ZenX/school/` | **HTTP 200** |
| `/student_calendar` route exists | `curl` | **307 -> admin_login**, identical to the known-good `/attendance` |
| route is not a false positive | `curl` a bogus path | **404** — so 307 means "exists and is auth-gated", not "everything redirects" |
| authenticated page render | — | **BLOCKED**: requires a real login. Creating a test account is forbidden by standing rule, and using the operator's credentials is not mine to do. This is a real blocker, not an excuse |

So **route reachability is now proven by HTTP rather than by reading source**.
Rendering, click-through and console behaviour remain unverified and are
explicitly NOT claimed.

### Two new verification tools, both proven capable of failing

**`scripts/calendar_view_js_lint.php`** — extracts every inline `<script>` from
the Calendar views, neutralises server tags, and runs `node --check`. This
closes the exact blind spot that shipped a real defect: `php -l` parses the PHP
that EMITS a page and never the JavaScript that comes out.
*Verified by reintroducing the shipped bug* — a real newline inside a
single-quoted JS string — and confirming the gate fails with
`SyntaxError: Invalid or unexpected token` at the right line, then restoring
byte-identical. 4 blocks parse clean.

**`scripts/calendar_js_php_agreement.php`** — extracts the REAL
`zxbIsWorkingDay()` from the exam view (never a re-typed copy, which would only
prove a copy agrees with itself), drives it in node with the payload the server
actually sends, and compares against PHP date-for-date over 400 dates.
Result: **84 non-teaching dates, exact agreement, 0 disagreements.**
*Verified by feeding it the PRE-CAL-D9 payload*, where it correctly reported
**26 dates the browser would have scheduled an exam on** (the 2nd/4th Saturdays)
plus 1 wrongly-skipped makeup Sunday. It also refuses to pass on a fixture with
fewer than 50 non-teaching dates, so it cannot go green on an empty comparison.

### Adversarial boundary suite

`StudentCalendarBoundaryAttackTest` — 13 tests attacking arithmetic the author
probably did not think about: leap years including the 1900/2000 century rule,
all four month lengths, the 5th-occurrence overflow on the 29th-31st, year
boundaries, and a 1,826-day sweep asserting `dayToken()` never drifts from PHP's
own calendar.

One test failed on first run and the CODE was right: a date before a version's
`effectiveFrom` returns null, because SCS-3 stops a policy reaching backwards.
The expectation was wrong and is now recorded as such.

---

## 9. SESSION-BOUNDARY — the earlier "absent" finding was WRONG

The closure report concluded no session-boundary authority existed. That was a
search failure: I looked for FIELD NAMES rather than for a SERVICE.

**`Leave_year_service` is the canonical session-boundary resolver.** Pure,
static, no I/O, already live in production through `prorateEntitlement()`, and
it exposes exactly what was needed:

```
Leave_year_service::boundsForSession($sessionLabel, $overrides, $startMonth)
Leave_year_service::sessionForDate($dateISO, $overrides, $startMonth)
```

It reads two OPTIONAL tenant fields — `schools/{id}.academicYearStartMonth`
(int 1-12) and `schools/{id}.sessionBoundaries` — and its own docblock is
explicit that the April default is *"the SAME already-established ZenX
convention ... centralized rather than reinvented"*, duplicated today as
literals in Sis.php, Attendance.php and Fees.php.

**Consequence.** `_calendar_readiness()` now asks that authority for the real
session window instead of using a rolling twelve months, and reads
`DEFAULT_START_MONTH` from the owning service rather than re-typing `4` — a
second literal here would have been a new hardcoded convention. A malformed
session label still degrades to the rolling window, so the reminder never
disappears entirely.

**`REASON_OUTSIDE_SESSION` remains UNWIRED, deliberately.** The authority now
exists, but wiring it into attendance would make every date outside the current
session non-teaching — which would change how historical months resolve for
every existing tenant. That is a behaviour change, not a fix, and it is not
backward-compatible. The handoff stands, now with a named authority attached
instead of a gap.
