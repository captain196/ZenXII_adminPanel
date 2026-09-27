# Calendar — Dependency Integration Audit

> **SUPERSEDED — see `CALENDAR_INTEGRATION_READINESS.md` (authoritative).**
> That document corrects this one on dependencies, isolation and closure.
> Two specific corrections: `schoolWhereOrFail` IS required (transitively, via
> `Holiday_service::_query_firestore()` line 422), and an eighth symbol
> (`firestoreCommitBatchStatus`) exists and is already satisfied at HEAD.


**Branch `integration/phase2`, HEAD `5136f0d`. No Calendar file is staged, committed or deployed.**
Method: an isolated `git worktree` at clean HEAD, Calendar files copied in, entry
points **actually executed**. The worktree was removed afterwards; the main tree
was never touched.

---

## 1 · THE EXECUTED DEPENDENCY GRAPH

The earlier report listed five blockers of equal weight. **Execution shows that
was wrong.** Only one is a hard blocker; the rest degrade.

```
CLEAN HEAD + Calendar-owned files only
│
├── Student_calendar_service::resolveForDate()          ✅ PASSES  (no deps at all)
├── Student_calendar_reader   (class load)              ✅ PASSES
├── Student_calendar_store    (class load)              ✅ PASSES
│
├── ::effectiveVersion()                                ❌ FATAL — ed_select_for()
├── ::validateVersion()                                 ❌ FATAL — ed_is_iso_date()
└── ::validateVersionAgainst()                          ❌ FATAL — ed_find_overlaps()
                                                            └── application/helpers/effective_dating_helper.php

+ effective_dating_helper.php  (102 lines, ONE file)
│
└── ALL SIX ENTRY POINTS EXECUTE.
      reader->resolveMonth()  → nonWorking=4, source=configured
      reader->resolveDate()   → instruction=true, source=legacy
```

### Then three call-time dependencies — every one of them CAUGHT

Verified by execution that both a missing `require_once` and a missing method
raise `Error`, which **implements `Throwable`** and is therefore caught by the
existing handlers.

| missing symbol | reached by | catch site | actual effect on clean HEAD |
|---|---|---|---|
| `Leave_year_service::boundsForSession` | `Student_calendar::_session_window()` | `_calendar_readiness()` try/catch (line 10 → 34) | readiness returns `checked=false`; **the School Week screen still loads**, the rollover banner simply never appears |
| `Firebase::firestoreGetOrFail` | `Student_calendar_store::saveVersion()` | the store's own try/catch around the read | save returns `FAILED` with *"The attendance settings could not be read… Nothing has been changed."* — **a clean refusal, not a crash** |
| `Holiday_service::special_working_days_in_month` | reader callers in Attendance, Exam, Academic, Staff_role_check, Student_calendar | each caller's try/catch | caught; falls back to the legacy path. **Special-working-day overrides are lost** until it lands |
| `Firestore_service::schoolWhereOrFail` | `Holiday_service` internals | n/a | **not a blocker** — the HEAD version of `Holiday_service` predates it and does not call it |

### What clean HEAD + one file actually gives you

**Works:** School Week screen loads · policy display · preview (weekly pattern,
nth-weekday, holidays) · history · divergence detection · attendance resolution
through the canonical reader · exam and cover integrations.

**Degraded:** saving a school week fails cleanly · rollover-readiness banner
absent · special-working-day overrides not honoured.

That is **partially functional**, not broken and not complete.

---

## 2 · OWNERSHIP AND READINESS OF EACH BLOCKER

| dependency | owner | complete? | own tests | other consumers | Calendar needs it for |
|---|---|---|---|---|---|
| `application/helpers/effective_dating_helper.php` | **HRMS W2.3 / Compensation** — its docblock says so | yes, 102 lines, self-contained | **5 test files** | `Compensation.php`, `Hr.php`, `Working_week.php`, `Salary_assignment_service`, `Statutory_config_service` | effective dating — the core invariant |
| `application/libraries/Leave_year_service.php` | **Workforce Policy, Leave Phase 1** | yes, 227 lines, pure/static | **6 test files** | `Hr.php`, `Staff.php`, `Leave_policy_service`, `Chd_uat` | session window for the readiness banner |
| `Firebase::firestoreGetOrFail()` | platform / Firebase library | yes | **21 test files** | repo-wide | fail-loud read in the store |
| `Firestore_service::schoolWhereOrFail()` | Attendance hardening (ACAD-D17) | yes | **3 test files** | `Academic`, `School_config`, `Analytics_service`, `Holiday_service` | transitive only — **not a Calendar blocker** |
| `Holiday_service::special_working_days_in_month()` | Workforce Policy Phase D | yes | **9 test files** | `Academic`, `Attendance`, `Exam`, `Hr`, `Staff_role_check` | special-working-day resolution |

**Existing committed alternative at HEAD: none for any of them.** No older
function provides effective dating or session bounds.

---

## 3 · IS ANY OF THIS ACCIDENTAL COUPLING?

**No. Every dependency is a correctly adopted shared abstraction.**

The evidence is that Calendar is a **minority consumer** in each case:

- `ed_select_for()` — five other consumers, and its own docblock states the point
  explicitly: *"Two implementations would eventually disagree, and the
  disagreement would show up as a payroll period resolving to one statutory
  config but a different salary assignment: silently wrong."* Calendar reusing
  it is exactly what it was written for. Writing a Calendar-local copy to gain
  committability would create the second implementation that file exists to
  prevent.
- `Leave_year_service` — four other consumers, already live in production via
  `prorateEntitlement()`. This programme originally concluded no session
  authority existed and was **wrong**; adopting it corrected that error.
- `special_working_days_in_month` — five consumers, and it is the only reader of
  special working days. A Calendar copy would be a second calendar authority,
  which is the precise failure this programme spent its life removing.
- `firestoreGetOrFail` — repo-wide platform primitive with 21 test files.

**Recommendation: remove nothing.** The minimum correct resolution is
sequencing, not decoupling. No Calendar code should change.

One honest nuance: `Leave_year_service` is the *softest* dependency — it powers
only the readiness banner, and its absence is already handled. If landing it
ever became genuinely impossible, Calendar could drop the banner rather than
duplicate the service. That is a fallback, not a recommendation.

---

## 4 · EXACT INTEGRATION SEQUENCE

### Step 1 — HRMS/Compensation lands the effective-dating helper

**Unit:** `application/helpers/effective_dating_helper.php` (whole file, untracked).
**After:** run its 5 test files plus the Compensation/HR suites.
**Unblocks:** every Calendar resolver entry point. **This single file is the only
hard blocker.**

### Step 2 — Workforce Policy lands the session and SWD work

**Unit A:** `application/libraries/Leave_year_service.php` (whole file, untracked).
**Unit B:** the `special_working_days_in_month` hunk in `Holiday_service.php`.
**After:** 6 + 9 test files respectively; the HR/leave suites.
**Unblocks:** the readiness banner and special-working-day overrides.

### Step 3 — Platform lands the fail-loud read primitives

**Unit:** `firestoreGetOrFail` in `Firebase.php`; `schoolWhereOrFail` in
`Firestore_service.php`.
**After:** the 21 + 3 test files.
**Unblocks:** saving a school week.

### Step 4 — Calendar commits

**Unit:**
```
application/libraries/Student_calendar_service.php
application/libraries/Student_calendar_reader.php
application/libraries/Student_calendar_store.php
application/controllers/Student_calendar.php
application/views/student_calendar/index.php
application/config/routes.php            ← 10 contiguous lines
application/views/include/header.php     ←  3 lines
application/controllers/Attendance.php   ←  6 hunks
+ the 12 tests and 5 diagnostic scripts
```
**After:** full PHP suite (expect the baseline 19 failures, unchanged); the
67-mutant battery; both JS gates.
**At this point Calendar is runnable on a clean checkout.**

### Step 5 — independent fixes, any order after step 4

CAL-D9 (`Exam.php` + `exam/create.php`), CAL-P3/P3B (`Academic.php` +
`academic/index.php`), CAL-PERF-1 (`Calendar_service.php`), CAL-5E
(`attendance_helper.php` + its caller), CAL-B1 logging, CAL-D11
(`Staff_role_check.php`).

### Step 6 — CAL-D1 rules deployment

Separate authorisation, via `CAL-D1-DEPLOY-PROCEDURE.md`. Never with the code.

**Earliest point Calendar is clean-base runnable: after Step 1** (partially, as
described in §1) — **fully after Step 3.**

---

## 5 · HONEST PROGRAMME STATUS

| state | value |
|---|---|
| Feature implementation | **COMPLETE** |
| Repository integration | **BLOCKED** — 1 hard, 3 degrading dependencies |
| Clean-base runnable (partial) | **NO** until `effective_dating_helper.php` lands |
| Clean-base runnable (complete) | **NO** until Steps 1–3 land |
| Browser verified | **NO** — requires credentials |
| Independently verified | **NO** — self-review only |
| Commit-ready as a complete feature | **NO** |
| Committed / Merged / Deployed / Production-active | **NO / NO / NO / NO** |

**The Calendar Programme is not closed.** The code is complete and proven at the
levels reachable here, but a clean checkout cannot run the complete feature, and
saying otherwise would be false.

---

## 6 · RE-VERIFIED AGAINST A MOVED HEAD (2026-09-02)

HEAD advanced `6650720` → `5136f0d` mid-audit — another session committed
*"test(placement): make the atomic write ceiling testable without weakening it"*.
Unrelated to Calendar. Re-checked rather than assumed:

- All five blockers **still absent at the new HEAD**.
- **No Calendar file has been committed.**
- 22 files are staged by another session (Fees/PayOps). **None is a Calendar
  file.** Not touched.

### A live hazard for Step 3

`application/libraries/Firebase.php` is staged by that PayOps session, and the
**staged** copy does **not** contain `firestoreGetOrFail` — the function exists
only in the unstaged half of that file (`MM`).

**Consequence: when PayOps commits, Step 3 will NOT be satisfied.**
`firestoreGetOrFail` would be left stranded in the working tree with its own
file already committed around it — the easiest kind of dependency to lose track
of. Whoever executes Step 3 must confirm the function is actually present at
HEAD afterwards rather than inferring it from "Firebase.php was committed".

Verification command:

```bash
git show HEAD:application/libraries/Firebase.php | grep -c 'function firestoreGetOrFail'   # must be 1
```
