# Calendar Programme — Stage A/B Results and Phase 5 Decision Pack

**Stage A (verify) and Stage B (census) COMPLETE. Phase 5 NOT started — no
resolver is wired to anything. No production file modified.**

Branch `integration/phase2`, HEAD `a1801aa` (unmoved). `Attendance.php`,
`attendance_helper.php`, `firestore.rules`, `Holiday_service.php` and
`Timetable_plan_store.php` all byte-identical to the Phase 1 baseline.

---

## STAGE A — independent verification found FOUR defects in my own Phase 4 work

39 tests and 14 mutants had passed. All four survived that battery because it
only ever fed the resolver well-formed input assembled the intended way.

| id | defect | consequence |
|---|---|---|
| **SCS-1** | `configured: true` returned for an **unconfigured** tenant on the outsideSession path | `configured` is the exact field a caller reads to decide whether to apply the owner's unconfigured-tenant policy — it lied, silently skipping that policy |
| **SCS-2** | a malformed session window was **silently ignored** | a date years outside the session resolved as an ordinary teaching day |
| **SCS-3** | `resolveForDate()` honoured a version whose `effectiveTo` had **already passed** | an expired week pattern resolved as a normal teaching day when handed in directly |
| **SCS-4** | pre-existing **duplicate `effectiveFrom`** in stored data never detected | `ed_select_for()` keeps whichever it saw first, so resolution silently depends on storage order |

All four fixed **in my own dark, zero-consumer code**, with 12 new tests and 4
new mutants. Battery now **18 mutants, 18 killed, 0 survived, 0 not applied**;
`StudentCalendar*` suites **51 tests / 124 assertions**, green.

---

## STAGE B — STOP 1 TRIGGERED: FIVE competing student-date authorities

The brief asked for every production location that independently decides what a
student date means. There are five, not the two previously reported.

| # | authority | tenant-configurable? | has a UI? | actually consumed by |
|---|---|---|---|---|
| 1 | `attendance_helper.php:38` — hardcoded `date('w')===0` | **NO** | — | **the real authority**: marking gate (`:1516`, `:1633`), save (`:1219`), summaries (`:7109`), leave stamping (`:5528`), `_is_holiday` (`:8054`) |
| 2 | `Attendance::_get_sundays():5673` | **NO** | — | view payloads `:1094`, `:1162` — **duplicate implementation** |
| 3 | **`attendanceSettings.working_days`** | **YES** | **YES — `views/attendance/settings.php`** | **NOTHING** |
| 4 | `timetableSettings.Working_days` | **YES** | via Academic | `Academic.php:453/519` — only `maxPeriodsPerWeek` for subject-assignment limits |
| 5 | `Staff_role_check.php:386` | NO | — | builds **student** summaries (`'type' => 'student'`) using `holidays_in_month()` with **no audience ⇒ defaults to `'staff'`** |

### The finding that matters most — CAL-D10 (HIGH)

**`attendanceSettings.working_days` is a configured-but-inert setting.**

- Default `['Mon','Tue','Wed','Thu','Fri','Sat']`
- Saved by `Attendance::save_settings()` (`:2738`, `:2751`) — a real admin surface
- Read back for display (`:2719`), diagnosed by `Health_check:934` as
  *"Working days not configured"*
- **Zero resolution consumers.** Verified by exhaustive grep across
  `application/` and `assets/`: every occurrence is save, display, default, or
  health-check. Nothing reads it to decide a working day.

So today an administrator can open Attendance Settings, set a **five-day school
week**, save it successfully, see it persisted — and the system **silently
continues to treat Saturday as a teaching day and Sunday as the only closure.**

That is worse than the "no weekly pattern" framing in every earlier report:
the product already *asks* the school for its week, *stores the answer*, and
*ignores it*. My previous reports, including the ERP comparison, missed this
entirely and asserted no student weekly configuration existed.

### CAL-D11 (MEDIUM) — student summaries built on the staff calendar

`Staff_role_check.php:386` generates student attendance summaries but calls
`holidays_in_month($year, $monthNum)` with no audience, defaulting to `'staff'`.
Its catch comment — *"no holidays resolvable → Sundays only"* — confirms it also
fails open. Same class as CAL-D9 (`Exam::_holiday_dates`). Two independent sites
now apply the **staff** calendar to **student** data.

---

## THE HISTORICAL-INVARIANCE PICTURE IS DIFFERENT FROM WHAT I REPORTED

[REPO] Student attendance is **already partially materialised**, which no
earlier report established.

`enforce_holidays_on_string()` (`attendance_helper.php:65`) stamps `'H'` into the
stored `day_wise` string, and `save_student_attendance()` applies it to the
**whole month string** at `:1340` using **today's** calendar.

Two consequences, and they pull in opposite directions:

1. **Good:** a month never re-saved is frozen. Changing the weekly pattern does
   not silently rewrite stored history — the materialised string is the record.
2. **Bad — this is the real retroactive vector:** editing *one day* in an old
   month re-stamps the **entire month** against the *current* calendar. So a
   pattern change plus any later correction to an old month rewrites that whole
   month's non-working days.

**This means Option A ("preserve today's behaviour") is not automatically the
safe choice it appeared to be.** Today's behaviour already contains a retroactive
rewrite path; preserving it preserves that too.

---

## PHASE 5 DECISION PACK

### DECISION 1 — unconfigured tenants

**FACTS.** Six runtime paths depend on authority #1. A tenant-configuration
mechanism **already exists and already holds the answer** —
`attendanceSettings.working_days`, with a UI, a save endpoint and a health check
— it is simply never read. Academic sessions already carry dated boundaries
(`schools.sessions[]`), so they can host an effective-dated initial policy.
Stored `day_wise` strings are materialised, so a migration that only changes
*future* resolution cannot alter already-saved months.

**RISK.**
- Option A: preserves a UI that lies, and preserves the whole-month re-stamp
  vector.
- Option B: `working_days` is present on tenants but was never authoritative, so
  fail-closed would block attendance for schools that believe they configured it.
- Option C: seeding from a value that was never enforced could change a tenant's
  behaviour on first migration — if a school set Mon–Fri years ago and the system
  ignored it, honouring it now **changes Saturdays**.

**OPTIONS.**

| | behaviour | migration | risk |
|---|---|---|---|
| **A** legacy compatibility | unconfigured resolves as today (Sun + holidays) | none | keeps the lying UI; keeps the re-stamp vector |
| **B** fail closed | no marking until configured | none | blocks existing schools |
| **C** explicit migration | seed a dated baseline per tenant | required | first-run behaviour change if seeded from `working_days` |
| **C′** *(new — recommended)* | seed a dated baseline **from the legacy rule only** (`Sun`), then **surface** any divergent `working_days` to the admin for confirmation | required, but behaviour-neutral | none at migration; the school opts in |

**RECOMMENDATION — C′.** It is the only option that is behaviour-neutral on day
one *and* closes the lie. Every tenant is seeded with
`legacyStudentPattern()` (`Sun` only) effective from their current session start,
so nothing changes. Where `attendanceSettings.working_days` disagrees, the admin
screen shows: *"Your saved settings say Mon–Fri, but attendance is currently
running a six-day week. Apply Mon–Fri from [date]?"* — school reviews, school
decides, school calendar authority. Exactly the import-suggestion pattern the
brief mandates for government holidays, applied to the school's own stale data.

**REQUIRED DECISION:** approve **C′**, or choose A / B / C.

### DECISION 2 — retroactive policy changes

**FACTS.** `validateVersionAgainst()` already hard-refuses an effective date on
or before `$settledThrough` (proven, mutant-killed). But the whole-month re-stamp
at `:1340` is a *separate* retroactive vector that the resolver cannot see.

**RECOMMENDATION:** keep the hard refusal, **and** treat the re-stamp as its own
work item — `enforce_holidays_on_string()` should resolve each day against the
version in force *on that day*, not today's. Without that, Decision 2 is only
half-enforced.

**REQUIRED DECISION:** confirm hard-refuse; and confirm whether the re-stamp fix
is in Calendar scope or a separate attendance work item.

### DECISION 3 — surface all three dimensions?

**RECOMMENDATION unchanged:** build all three, consume only `attendance` in
Phase 5. `exam` events currently affect attendance **not at all** (Holiday_service
reads only `holiday`/`special_working_day`/`half_day`), so wiring `examOnly`
would change behaviour and must stay out of Phase 5 — which also resolves CAL-D9
conservatively: **do not** change `Exam::_holiday_dates` audience yet, because
switching it from staff to student holidays would silently move exam scheduling.

---

## INTEGRATION-READINESS MAP (Stage D preview — no code)

| surface | changes in Phase 5? | note |
|---|---|---|
| `mark_student_day()` `:1516` | **YES** | eligibility gate + missing CAL-B1 log |
| `bulk_mark_student()` `:1633` | **YES** | same |
| `save_student_attendance()` `:1219/:1340` | **YES — highest risk** | whole-month re-stamp |
| `_applyDayToSummary()` `:7109` | YES | summary denominators |
| `_stamp_leave_on_attendance()` `:5528` | YES | |
| `_is_holiday()` `:8054` / `save()` `:8152` | YES | already has the readability guard |
| `_get_sundays()` `:5673` → `:1094/:1162` | **YES — must collapse** | duplicate authority |
| `attendanceSettings.working_days` | **YES** | stop lying: either honour it or route it into the calendar |
| `Staff_role_check:386` | YES | CAL-D11 audience |
| `Exam::_holiday_dates` | **NO** | CAL-D9 — deferred deliberately |
| Timetable / `coversDate()` | **NO** | TT-C8 untouched |
| HR / Payroll / staff grid | **NO** | staff domain — boundary preserved |

---

## STATUS

| State | Value |
|---|---|
| Investigated | **YES** (Stage A + B) |
| Designed | **YES** |
| Implemented | **YES, DARK** — zero consumers |
| Executed | **YES** — 51 tests, 18 mutants, full suite |
| Proven | **resolver only.** Integration: **NOT PROVEN — not built** |
| Independently verified | **NO** — self-review only |
| Committed / Merged / Deployed / Production Active | **NO / NO / NO / NO** |

**Not proven:** anything about production behaviour; no emulator run; no live
tenant data inspected; the `working_days` census is source-exhaustive but no
tenant was queried to see how many have actually set it.

**Out of scope, recorded, not fixed:** CAL-D1 (unauthorised), CAL-D5, CAL-D9,
CAL-D11, CAL-B1 residual, the `:1340` re-stamp vector.
