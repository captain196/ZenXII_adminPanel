# Phase 16.9 — D20 Authorization Closure + Student Lifecycle Integrity Audit

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED (D20). NOT COMMITTED / PUSHED / MERGED / REBASED / DEPLOYED. UAT NOT EXECUTED.**
No production data touched. `firestore.rules` / `firestore.indexes.json` **NOT modified**.
**No Fees code touched.** No RTDB. No bulk roll assignment. No AI placement.

---

## 1. EXECUTIVE SUMMARY

**D20 was not a consistency preference. It was a privilege-escalation bypass**, and tracing it
uncovered a second, worse defect on the same path.

| ID | Finding | Sev | Status |
|---|---|---|---|
| **D20** | `rollover_session(promote_students=1)` moved every student — class, section, session, Class-12→Alumni — under **`Configuration:manage`**, while the canonical `Sis::execute_promotion` requires **`SIS:manage`**. A Configuration-only admin denied at the front door could walk in through the rollover screen | 🔴 **HIGH (security)** | ✅ **FIXED** |
| **SEC-17** | The same rollover promotion loop applies **neither the capacity guard nor the roll-collision guard** that `execute_promotion` applies | 🔴 **HIGH** | ⛔ **BOUNDARY — D21** (§12) |

**The subtle part of the D20 fix, which is why it works:** `_require_role()` falls back to a **legacy
role-name allow-list** for users who hold no such module. Writing the promotion gate as
`_require_role(self::ADMIN_ROLES, …, 'SIS', 'manage')` would have let `'Admin'` pass **on its role
name**, making the check decorative. It is written as `_require_role([], …)` so the graded capability
is the sole authority — the established pattern in `Entitlements.php`. A test asserts that the
role-name form *would* have granted it.

**Tests: 1039 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +12 new tests.**

---

## 2. D20 IN PLAIN LANGUAGE

Two screens could promote students. One asked "do you have permission to manage student records?"
The other only asked "do you have permission to manage school settings?" — and then moved every
student in the school. Now both ask the same question.

## 3. CURRENT RBAC ARCHITECTURE (traced, not assumed)

`_require_role(array $allowed, string $action, ?string $module, string $level)`:

1. **`Super Admin` / `School Super Admin` always pass** — unchanged by design.
2. If `$module` supplied and `has_permission($module, $level)` → **pass**.
3. If the user **holds** the module but at a **lower** level → graded map is **authoritative → deny**,
   with no legacy fallback.
4. Otherwise → legacy **role-name** allow-list (migration fallback).
5. Denial → `json_error(…, 403)`, which **calls `exit`** — verified, so the guard genuinely terminates.

**Authorization matrix — before / after**

| Operation | Before | After |
|---|---|---|
| Rollover preview | `Configuration:view` | unchanged |
| Rollover **structure** (sections, capacity) | `Configuration:manage` | unchanged |
| **Rollover promotion** | **`Configuration:manage`** ⚠ | **`Configuration:manage` + `SIS:manage`** ✅ |
| `Sis::execute_promotion` | `SIS:manage` | unchanged |
| `Classes::transfer_students` | `SIS:manage` (D16) | unchanged |
| Direct admission / import / CRM | `SIS` | unchanged |

## 4. EXTERNAL RESEARCH

| Question | Finding | Label |
|---|---|---|
| Who controls academic-year rollover? | **NOT ESTABLISHED BY SOURCES** | — |
| Who controls student promotion? | **NOT ESTABLISHED BY SOURCES** | — |
| Are configuration and student-record permissions separated? | **NOT ESTABLISHED** — no vendor documents its permission model at this granularity | — |
| Is promotion a student-lifecycle operation? | Vendors describe promotion within student/admission management | **INDUSTRY PRACTICE** (weak — descriptive placement, not a permission model) |
| Does any credible ERP document this exact split? | **NOT ESTABLISHED BY SOURCES** | — |

**Honest consequence: external research did not decide this.** The decision rests on **ZenX's own
source**, which is unambiguous — two other student-movement endpoints already require `SIS:manage`.
Option A is therefore not borrowed authority; it is **internal consistency closing a bypass**.

## 5. SOURCE EVIDENCE (ZENX-VERIFIED)

| Site | Gate |
|---|---|
| `Sis.php:1419` `execute_promotion` | `_require_role(self::MANAGE_ROLES, 'sis_execute_promotion', 'SIS', 'manage')` |
| `Classes.php:575` `transfer_students` | `_require_role(self::MANAGE_ROLES, 'transfer_students', 'SIS', 'manage')` |
| `School_config.php:3653` `rollover_session` | `_require_role(self::ADMIN_ROLES, …, 'Configuration', 'manage')` |
| `School_config.php:4056` promotion loop | writes `session`, `className`, `classOrder`, `sectionKey` per student |
| `School_config.php:4031` graduation | writes `status = 'Alumni'` |

## 6. OPTIONS COMPARED

| Option | Verdict |
|---|---|
| **A** Structure = Configuration; promotion = SIS | ✅ **CHOSEN** — closes the bypass, matches two existing endpoints, no new permission |
| **B** Keep both under Configuration | ❌ leaves a live escalation path |
| **C** New dedicated permission | ❌ Part 8 forbids inventing one without proving necessity; A reuses what exists |
| **D** Other | none found |

## 7. IMPLEMENTATION

One guard, placed **before every write** — above the target-session creation and the lock/CAS block —
so a denied caller cannot leave a half-created session behind:

```php
if ($promoteStudents) {
    $this->_require_role([], 'rollover_promote_students', 'SIS', 'manage');
}
```

## 8. PROMOTION EXECUTION TRACE

`rollover_session` → validate sessions → **D20 gate** → intent/lock/CAS → copy sections (capacity
carried, editable) → **promotion loop**: per student, `classOrder` → next class label, writes
`session`, `className`, `classOrder`, `sectionKey`, `promotedFrom`; `classOrder === 12` → `Alumni` →
intent finalise → `log_audit` with `promotionRequested` / `studentsPromoted`.

**Key structural fact:** promotion **keeps the student's existing section letter** (5-A → 6-A). It is
a per-class 1:1 move, not a merge — which narrows, but does not remove, the SEC-17 risk.

## 9. ALTERNATE-PATH / BYPASS AUDIT

Swept for every endpoint that writes a student's class/section/session:

| Path | Gate | Verdict |
|---|---|---|
| `Sis::execute_promotion` | `SIS:manage` | ✅ |
| `Classes::transfer_students` | `SIS:manage` | ✅ |
| `School_config::rollover_session` | Configuration **+ SIS:manage for promotion** | ✅ **closed** |
| `Fee_management::carry_forward_fees` | `Fees:manage` | ✅ **not a mover** — writes `feeCarryForward` records only; **verified, not assumed** |

**No other student-movement endpoint exists.** No remaining bypass.

## 10. STUDENT LIFECYCLE REGRESSION MATRIX

| Path | Capacity | Roll collision | Session correct | Audit | Auth |
|---|---|---|---|---|---|
| Direct admission | ✅ | ✅ | ✅ | ✅ | SIS |
| CRM enrolment | ✅ | ✖ none set | ✅ | ✅ | SIS |
| Bulk import | ✅ preview + commit | ✅ in-file + DB | ✅ | ✅ | SIS |
| Re-admission | ✅ | ✅ | ✅ | ✅ | SIS |
| `execute_promotion` | ✅ | ✅ `$rollOwners` | ✅ target session | ✅ | SIS |
| Section transfer | ✅ | ✅ (16.1) | ✅ | ✅ (16.1) | SIS (D16) |
| **Rollover promotion** | ❌ **SEC-17** | ❌ **SEC-17** | ✅ | ✅ | ✅ **SIS (D20)** |
| Rollover structure | n/a | n/a | ✅ | ✅ | Configuration |

**Identity invariants re-verified:** Student ID canonical · SR Number independent (never derived) ·
Roll scoped School+Session+Class+Section, alphanumeric-safe · rollover copies **no** student identity
or rolls.

## 11. FEES CROSS-LIFECYCLE CHECK (no Fees code touched)

`carry_forward_fees` is a **separate, explicitly-invoked** endpoint under `Fees:manage`; rollover does
not call it. Promotion writes only student placement fields — it does not touch `feeDemands`,
`feeReceipts` or accounting. **No cross-lifecycle Fees defect found.** All seven Fees deferred items
remain intact and untouched.

## 12. SEC-17 — FOUND, TRACED, AND WHY I STOPPED

**Evidence (ZENX-VERIFIED):** the rollover promotion loop (`School_config.php:4051-4077`) writes each
student's new class with **no `_sectionCapacityExceeded()` and no `_rollNoConflict()`** — grep of the
whole loop returns zero. `Sis::execute_promotion` applies both.

**Consequences:** rollover-with-promotion can push a section past its configured capacity, and can
create duplicate roll numbers where the destination already holds students (repeaters, or a prior
partial rollover).

**Why this is not fixed here — a real decision, not convenience:**

1. **The canonical validators read a per-request memoised snapshot** taken *before* the loop runs. In
   a run promoting hundreds of students it is stale by construction, so a correct guard needs
   in-run running counters (the pattern bulk import uses). That is a substantive change inside a
   lock/CAS/resume-protected bulk function with abort-on-consecutive-failure semantics.
2. **It embeds a genuine policy question.** `execute_promotion` refuses **all-or-nothing** up front.
   Rollover's loop skips individuals. Applying skip semantics to capacity would leave a school
   **half-promoted mid-rollover** — some students in the new session, some stranded in the old.
   Whether rollover should abort entirely or skip-and-report is an operational decision with real
   consequences for a school on rollover day. **I will not invent it.**

**Mitigating factor, stated so the severity is not overstated:** promotion preserves the section
letter (5-A → 6-A), so it is a 1:1 class move rather than a merge. Roll collisions therefore require
a *pre-occupied* destination, and capacity overflow requires next year's section to be smaller than
this year's cohort.

**D21 — decision required:** on capacity/roll violation during rollover promotion, should ZenX
**(a)** refuse the whole promotion up front (matching `execute_promotion`), or **(b)** skip the
affected students and report them? Once answered, the fix is mechanical.

## 13. SECURITY AUDIT

- Bypass roles unchanged (Super Admin / School Super Admin) — deliberate.
- **No reverse escalation:** `SIS:manage` alone still cannot reach session configuration — the
  structure gate runs first. Asserted by test.
- **Lower-level SIS is denied, not downgraded** (`view`/`edit` do not satisfy `manage`).
- **Fail-closed and side-effect-free:** the guard precedes every write; `json_error` exits.
- **No new permission invented.** No rules/indexes change.

## 14. OTHER-MODULE IMPACT

Rollover promotion changes `session`/`className`/`classOrder`/`sectionKey`. Attendance, Examination,
Report Card, Homework, LMS, Transport, Hostel, Library, Communication, Certificates, Parent App and
Teacher App all key on **`studentId`** and read class/section for display or scoping. **Tightening
*who may trigger* promotion cannot create an incorrect state in any of them** — the write payload is
unchanged. **No module modified.**

## 15. PERFORMANCE

**Zero measurable impact.** The D20 guard is one in-memory capability check on an already-loaded
session; no Firestore read or write is added. No N+1.

## 16. TESTS

```
PRE-PHASE BASELINE:  1027 tests, 2996 assertions, 10 failures
AFTER 16.9:          1039 tests, 3019 assertions, 10 failures
NEW TESTS: 12         NEW FAILURES: 0
LINT: clean · RTDB: none · RULES/INDEXES: untouched
```
Pre-existing failures unchanged and **not mine**: Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2. **This is not "all tests pass".**

Coverage: Configuration-only cannot promote · Configuration-only **can** still roll structure ·
both capabilities can promote · **the role-name form would have granted it (the bug avoided)** ·
`SIS:view`/`edit` denied · **no reverse escalation** · SIS-only cannot reach promotion here ·
Super Admin unaffected · all movement paths consistent · structure stays under Configuration ·
denial is side-effect free · identity invariants.

## 17. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | Configuration-only user, rollover **structure only** | Succeeds | **UAT PENDING** |
| 2 | **Configuration-only user, tick "promote students"** | **403 refused** | **UAT PENDING** |
| 3 | Same user — verify **no target session created** by the denied attempt | Nothing written | **UAT PENDING** |
| 4 | Configuration + SIS user, promote | Succeeds | **UAT PENDING** |
| 5 | User with `SIS:view` only | Refused | **UAT PENDING** |
| 6 | SIS-only user attempts rollover | Refused at structure gate | **UAT PENDING** |
| 7 | Super Admin | Unaffected | **UAT PENDING** |
| 8 | Section transfer still works for SIS:manage | Unchanged | **UAT PENDING** |
| 9 | `Sis::execute_promotion` unchanged | Unchanged | **UAT PENDING** |
| 10 | Audit shows `promotionRequested` / `studentsPromoted` | Recorded | **UAT PENDING** |
| 11 | Capacity carried + editable (16.7/16.8) | Unchanged | **UAT PENDING** |
| 12 | **SEC-17: promote into a smaller target section** | ⚠ **currently over-fills — expected until D21** | **UAT PENDING** |

**Row 2 is the critical one. No browser or device UAT has been executed at any point.**

⚠ **I cannot inspect live role assignments from here.** Any operator who performs
rollover-with-promotion today and holds `Configuration:manage` **without** `SIS:manage` will be
blocked on first use and must be granted `SIS:manage`. This is the intended tightening, but it is a
rollout consideration you should know before deploying.

## 18. REMAINING DEFECTS

**SEC-17** 🔴 (D21) — the only HIGH item, evidence complete, blocked on one policy answer.

## 19. DEFERRED WORK REGISTER

**Fees (verified intact, untouched):** FEES-D7 · FEES-D9 · FEES-D13 · FEES-D14 · FEES-D15 ·
FEES-FROZEN-1 · FEES-UAT.

**Class/Section/Roll:** **SEC-17/D21** (rollover promotion guards) · ROLL-1/2 (**D19**) ·
ROLL-3 (**D5**) · ROLL-4 (**D4**) · SEC-1 (**D3**) · SEC-5 (orphaned section docs survey) ·
SEC-6 (section CRUD audit — small now that `log_audit` is confirmed available) ·
ROLL-6 (closed by analysis) · CAP-2 (atomic primitive) · EXAM-1 · HC-RTDB · LMS-RTDB.

## 20. GIT STATUS

```
Branch: integration/phase2      HEAD: 758c924   (unchanged — no commit)
Modified this phase:  application/controllers/School_config.php
New this phase:       tests/Unit/Phase169RolloverPromotionAuthorizationTest.php
firebase-rules/* ...  dirty BEFORE this programme — NOT touched
Fees files .........  dirty from Phases 15.5–15.9 — NOT touched this phase
COMMIT: none  PUSH: none  MERGE/REBASE: none  DEPLOY: none
```

---

## 21. FINAL VERDICT: **READY WITH CONDITIONS**

D20 is closed and the escalation path is gone. **Not `CLOSED`**, because SEC-17 — a HIGH defect on the
same endpoint — remains open pending **D21**, and because **no UAT has been executed**.

**Conditions:**
1. Answer **D21** (abort vs skip) so SEC-17 can be fixed — it is mechanical once decided.
2. Grant `SIS:manage` to anyone who performs rollover-with-promotion, before deploying.
3. Execute the UAT matrix, especially row 2.

**Bulk roll assignment (D19) is still not blocked by a correctness defect** — SEC-17 concerns
rollover promotion, not roll assignment. But I would fix SEC-17 first: it is the last known HIGH item
in this lifecycle.

---

## 22. WHAT THIS MEANS FOR A REAL SCHOOL

**Two different screens could promote your students, and they were asking different questions.**

The main promotion screen checks whether you're allowed to manage student records. The session
rollover screen only checked whether you're allowed to manage school settings — and then, if you
ticked "promote students", it moved every child in the school into the next class and graduated your
Class 12 to alumni. Someone deliberately restricted from the promotion screen could do exactly the
same thing from the rollover screen.

Both now ask the same question. Setting up next year's classes, sections and seat limits still only
needs settings permission — that genuinely is configuration work. Moving children between classes
needs student-records permission.

**Before you deploy this, one practical thing:** if a member of staff currently does the year rollover
*with* promotion and only has settings permission, they will be refused the first time they try. They
need student-records permission granted. I can't see your staff's actual permissions from here, so
please check before rolling it out rather than discovering it on rollover day.

**And one thing I found but did not fix, deliberately.** While tracing that promotion path, I found
the rollover promotes students **without checking class sizes or duplicate roll numbers** — the checks
the main promotion screen does apply. So a rollover could overfill next year's section, or create two
children with the same roll number if the destination class already has students in it.

I stopped short of fixing it for one honest reason: it needs a decision only you can make. The main
promotion screen refuses the whole operation if anything would go wrong. The rollover works
student-by-student, so applying the same check would mean some children move up and others are left
behind in the old year — a school half-promoted. Which of those two behaviours you want is an
operational judgement, not a technical one. Once you tell me, the fix itself is straightforward.

**Nothing has been committed or deployed, and none of this has been clicked through in a browser.**
