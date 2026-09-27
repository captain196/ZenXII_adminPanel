# Calendar — Isolation & Dependency Proof

**Branch `integration/phase2`, HEAD `6650720`. Nothing staged, committed or deployed.**
Git-isolation exercise only. No code was changed.

> **SUPERSEDED IN PART — see `CALENDAR_DEPENDENCY_AUDIT.md`.**
> This document was written from static analysis (`git show HEAD | grep`). The
> later audit **executed** the Calendar entry points in an isolated clean-HEAD
> worktree and proved the §1 table below overstates the problem: only
> `effective_dating_helper.php` is a **hard load-time blocker**. The other three
> symbols are reached at call time and are **caught** by existing handlers, so
> the feature degrades rather than crashing. `schoolWhereOrFail` is not a
> Calendar blocker at all. §2–§7 below remain accurate.

---

## VERDICT

**A complete, working Calendar change set CANNOT currently be isolated.**

Not because of hunk interleaving — that turned out to be nearly clean (41 pure
Calendar hunks, 1 genuinely mixed). It is because **symbols the Calendar
feature depends on do not exist at HEAD.** They exist only in other programmes'
uncommitted work.

Applied to a clean base, the Calendar change set would fatal on the first call
to `ed_select_for()` — the function that implements effective dating, this
programme's central invariant. (The audit confirms this specific claim by
execution; it corrects the weighting of the other four.)

---

## 1 · THE BLOCKER, PROVEN

| symbol | lives in | tracked? | at HEAD? | needed by |
|---|---|---|---|---|
| `ed_select_for()` | `application/helpers/effective_dating_helper.php` | **UNTRACKED** | **NO** | `Student_calendar_service::effectiveVersion()` |
| `ed_is_iso_date()` | same file | **UNTRACKED** | **NO** | `Student_calendar_service::validateVersion()` |
| `ed_find_overlaps()` | same file | **UNTRACKED** | **NO** | `Student_calendar_service::validateVersionAgainst()` |
| `Leave_year_service::boundsForSession()` + `DEFAULT_START_MONTH` | `application/libraries/Leave_year_service.php` | **UNTRACKED** | **NO** | `Student_calendar::_session_window()` |
| `Firebase::firestoreGetOrFail()` | `application/libraries/Firebase.php` | tracked, `MM` | **NO** — pending only | `Student_calendar_store::saveVersion()` |
| `Firestore_service::schoolWhereOrFail()` | `Firestore_service.php` | tracked, `M` | **NO** — pending only | `Holiday_service` internals (transitive) |
| `Holiday_service::special_working_days_in_month()` | `Holiday_service.php` | tracked, `M` | **NO** — pending only | reader callers in Attendance, Exam, Academic, Staff_role_check, Student_calendar |

Verified by `git show HEAD:<file> | grep -c "function <name>"` against the same
grep on the working tree.

### Whose work these are

- `effective_dating_helper.php` and `Leave_year_service.php` — **Workforce /
  Leave Policy programme.** Both are entire files that have never been committed.
- The three pending functions — **Attendance / platform hardening work**
  (`schoolWhereOrFail` is ACAD-D17's fail-loud read; `special_working_days_in_month`
  is Workforce Phase D).

**Calendar did not create any of these and must not commit them.**

---

## 2 · WHY THIS IS NOT A HUNK PROBLEM

The hunk-level picture is close to clean:

```
41 hunks pure Calendar · 369 hunks other programmes · 1 genuinely mixed
```

The single mixed hunk is `application/config/routes.php`
`@@ -886,0 +1029,102 @@`, where Calendar owns **10 contiguous lines** —
positions 47–56 of the added block, immediately after the HRMS LOP comment:

```
// ── CALENDAR PROGRAMME — student academic week administration.        ← boundary start
//    Writes attendanceSettings.studentCalendar.versions[], the canonical
//    STUDENT teaching-day authority read by Student_calendar_reader.
//    Separate from working_week/* and work_schedules/* above: those answer
//    'is this EMPLOYEE expected to work'; this answers 'is the SCHOOL
//    operating for students'. The two must be able to disagree.
$route['student_calendar']            = 'Student_calendar/index';
$route['student_calendar/get_policy'] = 'Student_calendar/get_policy';
$route['student_calendar/preview']    = 'Student_calendar/preview';
$route['student_calendar/save_version'] = 'Student_calendar/save_version';   ← boundary end
```

No interleaving. Lifting it is a copy of one contiguous block.

**So the hunks are separable; the runtime dependencies are not.**

---

## 3 · DEPENDENCY MAP

### Calendar-owned — REQUIRED for the feature

| file | role |
|---|---|
| `application/libraries/Student_calendar_service.php` | pure resolver **← needs `ed_*` (BLOCKED)** |
| `application/libraries/Student_calendar_reader.php` | settings I/O + month resolution |
| `application/libraries/Student_calendar_store.php` | the only writer **← needs `firestoreGetOrFail` (BLOCKED)** |
| `application/controllers/Student_calendar.php` | admin surface **← needs `boundsForSession` (BLOCKED)** |
| `application/views/student_calendar/index.php` | School Week screen |

### Calendar-owned — OPTIONAL (diagnostics, not runtime)

`Calendar_perf_probe.php`, `student_calendar_mutation.php`,
`calendar_view_js_lint.php`, `calendar_js_php_agreement.php`,
`calendar_commit_isolation.php`, `cal_d1_tenant_binding.test.js`, and the 12
`tests/Unit/*` files. None is needed at runtime.

### Shared hunks — REQUIRED for the feature to work end-to-end

| file | hunks | purpose | moves with |
|---|---|---|---|
| `config/routes.php` | 10 lines | reachability — without it `/student_calendar` 404s | header + controller |
| `views/include/header.php` | 3 lines | navigation entry | routes |
| `controllers/Attendance.php` | 6 | route resolution to the canonical reader; `_weekly_closure_days` + its 2 call sites | each other |

Without the Attendance hunks the screen still renders and saves, but the
calendar **does not affect attendance** — which is the point of the feature.

### Shared hunks — INDEPENDENT defect/perf fixes (not required for School Week)

| file | hunks | fix | depends on |
|---|---|---|---|
| `controllers/Exam.php` + `views/exam/create.php` | 8 + 1 | CAL-D9 audience + weekly pattern | reader; **the two files pair** |
| `controllers/Academic.php` + `views/academic/index.php` | 5 + 3 | CAL-P3/P3B cover signal + confirm | reader; **gate pairs with client** |
| `libraries/Calendar_service.php` | 4 | CAL-PERF-1 cache | nothing — fully independent |
| `helpers/attendance_helper.php` + `Attendance.php` ×1 | 2 + 1 | CAL-5E explicit-mark preservation | **must pair** — the helper gains a parameter the caller passes |
| `controllers/Attendance.php` ×3 | 3 | CAL-B1 logging | nothing — logging only |
| `controllers/Staff_role_check.php` | 2 | CAL-D11 audience | reader + helper signature |
| `views/academic/index.php` ×1 | 1 | `.ac-shim` skeleton fix | nothing — pre-existing bug, unrelated |

### Separate deployment unit

`firebase-rules/firestore.rules` — 5 hunks / 7 arms (CAL-D1). Not part of the
code change set; deploys via `CAL-D1-DEPLOY-PROCEDURE.md`.

---

## 4 · MUST-MOVE-TOGETHER

1. `routes.php` block + `header.php` line + `Student_calendar.php` + view — menu → route → controller.
2. `Exam.php` `_non_scheduling_dates` + `exam/create.php` `weekendDays`.
3. `Academic.php` signal + `Academic.php` gate + `academic/index.php` confirm flow.
4. `attendance_helper.php` signature + `Attendance.php` `preserveExplicitMarks` caller.
5. `Attendance.php` `_weekly_closure_days` + its two call sites (lines 1094, 1162).

---

## 5 · FOREIGN-WORK CAPTURE CHECK

Each emitted patch in `firebase-rules/patches/calendar-hunks/` was re-generated
against the current tree and compared byte-for-byte with the previous
generation: **all unchanged** despite HEAD advancing four commits.

No patch contains a line bearing a Placement, SIS, HRMS, Fees/PayOps, Substitute
or Lesson Planner marker in **code**. Comments citing `ACAD-D18`, `ACAD-D13`,
`SEC-3` and `TT-C8` appear as provenance only, and are excluded from the
classifier for that reason.

---

## 6 · THE MINIMUM COMPLETE SET — and why it is not yet committable

For the Calendar feature to be reachable and correct after checkout:

```
OWNED (5 runtime files)
  application/libraries/Student_calendar_service.php
  application/libraries/Student_calendar_reader.php
  application/libraries/Student_calendar_store.php
  application/controllers/Student_calendar.php
  application/views/student_calendar/index.php

SHARED HUNKS (19 lines + 6 hunks)
  application/config/routes.php            10 contiguous lines
  application/views/include/header.php      3 lines
  application/controllers/Attendance.php    6 hunks (resolution + weekly closure)

PLUS — NOT CALENDAR'S TO COMMIT
  application/helpers/effective_dating_helper.php   ENTIRE FILE, untracked
  application/libraries/Leave_year_service.php      ENTIRE FILE, untracked
  application/libraries/Firebase.php                firestoreGetOrFail
  application/libraries/Firestore_service.php       schoolWhereOrFail
  application/libraries/Holiday_service.php         special_working_days_in_month
```

**The answer to the key question is NO.** Applied to a clean base, this set
would not function: `Student_calendar_service::effectiveVersion()` calls
`ed_select_for()`, which would not exist.

Calendar cannot resolve this by committing those files — they belong to the
Workforce/Leave Policy and Attendance-hardening programmes, and committing them
would ship their unreviewed work under a Calendar commit. That is precisely the
boundary this programme has held throughout.

---

## 7 · WHAT UNBLOCKS IT

**Sequencing, not engineering.** In order:

1. Workforce/Leave Policy commits `effective_dating_helper.php` and
   `Leave_year_service.php`.
2. Attendance/platform commits `firestoreGetOrFail`, `schoolWhereOrFail` and
   `special_working_days_in_month`.
3. Calendar then commits the OWNED files plus the three shared hunk groups —
   at which point the set is self-contained and the key question becomes YES.

No Calendar code needs to change for any of this. The feature is complete; its
foundations are simply not landed yet.
