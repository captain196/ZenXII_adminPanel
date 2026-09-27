# ZENX HRMS — LEGACY WORKING-CALENDAR RETIREMENT AUDIT

**Date:** 2026-08-10 · **Type:** audit + classification · **Nothing deleted in this pass**
**Regression:** 511 tests / 1,658 assertions / **10 failures (documented baseline)** · verifiers PASS

---

## 1. Proof that active PAYROLL paths use the canonical calendar

| Assertion | Evidence |
|---|---|
| Payroll resolves **per employee** | `Work_schedule_service::resolveEmployeeCalendar` — **1** call site in `Hr.php`, reached by both writers via `_staff_working_days()` |
| Payroll has **no** tenant-wide calendar call | `Working_calendar_service::resolveForMonth` in `Hr.php` — **0 occurrences** |
| Payroll has **no** hardcoded weekday rule | `satCount` in `Hr.php` — **0**; `date('w'` in `Hr.php` — **0** |
| Both writers pass `$staffId` | asserted `substr_count(... , '$xd, $staffId)') === 2` |
| Both writers enforce the block | asserted `substr_count(..., "!empty(\$swd['calBlocked'])") === 2` |
| No hidden fallback | `resolveEmployeeCalendar` returns a block state; no default branch exists — test-asserted across 5 block codes |

**Payroll is fully migrated.** No active payroll path can compute or inherit a calendar other than
the employee's resolved one.

## 2. Legacy inventory and classification

### 2.1 `Attendance::_get_sundays()` — **RETAIN**

`Attendance.php:4961`, three callers (1020, 1087, 1871). Returns Sunday day-numbers for **calendar
display shading** in attendance grids.

**RETAIN.** It is a UI helper, not a working-day authority — it never decides pay or attendance
status. Migrating it would couple a rendering hint to schedule resolution for no correctness gain.
*Flagged:* if a Mon–Fri school shades only Sundays the grid is cosmetically incomplete; that is a UI
nicety, tracked under N-35's UI work, not a calendar-authority defect.

### 2.2 Root `attendancePolicy.weeklyOffs` consumers — **MIGRATE (N-35)**

| Consumer | Role | Class |
|---|---|---|
| `Staff_attendance_finaliser.php:109,151,264` | Overlays `O` on daily attendance | **MIGRATE** |
| `Staff_attendance.php:206,329,646,669` | Punch-time weekly-off checks | **MIGRATE** |
| `Attendance.php:2621,2653` | Writes root `weeklyOffs` | **MIGRATE** (writer) |
| `views/attendance/settings.php` | Root weekly-off checkboxes | **MIGRATE** (UI) |

**This is the competing authority.** Attendance daily marking still asks the tenant root while
payroll asks the employee's schedule. **They can disagree today** — attendance could mark `O` on a
day payroll treats as working.

**Not removed in this pass**, per your instruction not to delete anything an active consumer still
needs. These are the active consumers; migrating them **is** N-35.

### 2.3 `attendancePolicy.workingWeek.versions[]` — **DEPRECATE (my own interim model)**

Created earlier in this gate as a **tenant-level** working week, then superseded by `workSchedules`
when the employee-level requirement landed.

| Artefact | Class |
|---|---|
| `Working_calendar_service::effectiveVersion()` (reads `workingWeek`) | **DEPRECATE** — retained only as the entry point `Working_week` still uses |
| `Working_calendar_service::resolveForMonth()` | **DEPRECATE** — 1 caller, `Working_week::preview()` |
| `Working_week` controller (3 endpoints) + its UI card | **DEPRECATE** — writes the superseded shape |
| `routes.php` `working_week/*` | **DEPRECATE** |

**Honest note:** this is legacy I introduced myself, one gate ago. It is **not yet removable** —
deleting it now would leave *no* configuration surface at all, and payroll is already blocked
tenant-wide. It must be **replaced** by the schedules + assignments admin surface, then removed in
the same change. Recorded as **N-36** so it cannot be forgotten.

**Risk if left:** an administrator could configure `workingWeek` and see "Configured", while payroll
still blocks because no `workSchedules` exist — a confusing dead end. That makes N-36 urgent rather
than cosmetic.

### 2.4 `Working_calendar_service::legacyRuleDescription()` — **RETAIN**

Documentation-only; never consulted during resolution (test-asserted). Explains how pre-fix payslips
were calculated. **Required for historical interpretation.**

### 2.5 Run-document `sundays` / `off_saturdays` — **RETAIN (read-only), DEPRECATE (semantics)**

Now written as **0** — a per-employee-schedule world has no single tenant answer. Existing run docs
keep their historical values.

`views/hr/index.php:3637-3638, 3739` still *label* these "2nd/4th Saturdays". **DEPRECATE the
labels** — they describe a rule that no longer governs anything. Cosmetic; no calculation reads them.
**Historical run documents must not be rewritten** to erase them.

### 2.6 Frozen payroll evidence — **RETAIN, IMMUTABLE**

`_salary_snapshot`, `_attendance_snapshot`, `_calculation_trace`, `_payroll_adjustments`,
`slip_archive`, journals, audit rows. **Never delete or rewrite.** April's slips were priced by the
legacy rule and must keep reproducing exactly as issued.

## 3. Duplicate-engine check

| Question | Answer |
|---|---|
| Two working-day engines? | **No** — `Working_calendar_service` holds the only calendar maths; `Work_schedule_service` decides *whose* and delegates (test-asserted: it contains no `date('w'`, no `satCount`) |
| Two payroll calendar paths? | **No** — one resolver, both writers |
| Two calendar *authorities*? | **YES — payroll vs attendance.** §2.2. This is the outstanding violation |

## 4. Acceptance criteria — honest status

| Requirement | Status |
|---|---|
| Zero active payroll paths on the old hardcoded calendar | ✅ **MET** |
| Zero payroll fallbacks to a global/default calendar | ✅ **MET** |
| Zero active Attendance paths on a competing authority | ❌ **NOT MET** — §2.2 (N-35) |
| No duplicate working-day engines | ✅ **MET** |
| No hidden compatibility fallback altering payroll | ✅ **MET** |
| Source audit proving legacy retirement | ⚠️ **PARTIAL** — payroll proven; attendance pending |
| Regression + verifiers PASS | ✅ **MET** — 511 / 10 baseline |
| Historical payroll reproducible | ✅ **MET** — no frozen evidence touched |
| Legacy config retained only where required | ⚠️ **PARTIAL** — `workingWeek` is redundant but not yet replaceable |

**The capability is NOT COMPLETE.** Two blockers: **N-35** (attendance migration) and **N-36**
(retire the interim tenant-level model once the schedules admin surface exists).

## 5. Retirement order — dependencies, not preference

```
1. Schedules + assignments admin surface        ← nothing configurable without it
2. N-36: retire workingWeek + Working_week      ← only safe AFTER 1
3. N-35: migrate Attendance consumers           ← closes the competing authority
4. Re-verify: attendance and payroll agree per employee/date
5. THEN remove root weeklyOffs writers/readers
```

Step 5 last, deliberately: removing the root field before its consumers migrate would break daily
attendance marking.

## 6. Register

| ID | Status |
|---|---|
| **N-35** | **MISSING** — Attendance daily-marking migration; **blocks COMPLETE** |
| **N-36** *(new)* | **MISSING** — retire interim `workingWeek` model + `Working_week` controller/UI/routes after the schedules surface lands. **Must not be deleted before replacement** |
| **N-37** *(new)* | **MISSING** — cosmetic: retire "2nd/4th Saturday" labels in the payroll slip UI |
| N-31/N-32/N-33/N-34 | **PARTIAL** |

## 7. What was changed in this pass

**Nothing.** This is an audit. No code deleted, no configuration altered, no tenant data written,
nothing migrated, seeded, committed, pushed or deployed. PR0003 untouched. UAT paused at Test 4.

---

## Decision needed

The retirement is blocked on the **schedules + assignments admin surface** — it gates N-36, and until
it exists no tenant can configure a schedule, so payroll stays blocked everywhere. Confirm I should
build it next, and whether N-35 follows immediately after or waits for its own gate.
