# Phase 17 — SEC-17 Rollover Promotion Integrity Closure (D21 = all-or-nothing)

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED. NOT COMMITTED / PUSHED / MERGED / REBASED / DEPLOYED. UAT NOT EXECUTED.**
No production data touched. `firestore.rules` / `firestore.indexes.json` **NOT modified**.
**No Fees code touched.** No RTDB. No bulk roll assignment. No AI placement. **D20 not weakened.**

---

## 1. EXECUTIVE SUMMARY

**SEC-17 is closed.** Rollover promotion now validates the **entire** promotion set before moving a
single student, and refuses the whole operation if any student would violate capacity or roll
uniqueness.

| Item | Status |
|---|---|
| **SEC-17** — rollover promotion applied neither the capacity guard nor the roll-collision guard | ✅ **FIXED** — all-or-nothing pre-flight |
| **D21** — abort vs skip | ✅ **IMPLEMENTED as ABORT**, per your decision |
| **Transition logic drift risk** | ✅ **ELIMINATED** — extracted `_rolloverNextClass()`, used by both pre-flight and write |
| **Visibility bug I introduced and caught** | ✅ fixed before shipping — see below |

**A bug of my own that `php -l` could never have caught.** My first pre-flight called
`_cachedStudentDocsForRollCheck()`, which was **`private`** on `MY_Controller` — `School_config` is a
subclass and could not reach it. That is a **runtime fatal**, invisible to lint and invisible to unit
tests that model the rule rather than execute the controller. I checked every helper's visibility
before declaring done, found it, and widened it to `protected` (consistent with the rest of that
capacity/roll family, all already protected).

**Tests: 1065 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +26 new tests.**

---

## 2. D21 DECISION AS IMPLEMENTED

If **any** student in the requested promotion would cause a capacity violation or a roll collision —
whether against the destination's existing roster or against another student in the same batch — the
**entire promotion is refused and no student is moved.** 39 valid students and 1 conflict still means
zero promotions.

## 3. WHY ALL-OR-NOTHING IS SAFER HERE

A skip-and-report rollover leaves a school **half-promoted**: some children in the new session, some
stranded in the old, with attendance registers, mark sheets and fee demands generated against a split
roster. Unpicking that mid-year is far harder than re-running a refused operation after fixing two
roll numbers. It also matches `Sis::execute_promotion()`, which already refuses up front — so the two
promotion routes now behave identically, which was the deeper problem SEC-17 exposed.

## 4. EXTERNAL RESEARCH

| Question | Finding | Label |
|---|---|---|
| Do ERPs promote in bulk with a preview? | Structure-then-promote, with review, is documented | **SOURCE-VERIFIED** (Phase 16.6: Common Goal Systems, Arbor) |
| All-or-nothing vs partial promotion | **NOT ESTABLISHED BY SOURCES** — no vendor documents failure semantics |
| Capacity enforcement during promotion | **NOT ESTABLISHED BY SOURCES** |
| Roll-conflict handling during promotion | **NOT ESTABLISHED BY SOURCES** |

**Honest consequence:** external research did **not** decide D21 and I am not dressing it as industry
practice. The decision is yours, and the strongest architectural argument is **ZenX's own
`execute_promotion()`**, which already refuses all-or-nothing (**ZENX-VERIFIED**).

## 5. EXISTING ARCHITECTURE (traced before changing)

The write loop resolves each student's destination from `classOrder`:
`12 → Alumni` (no destination section) · `1–11 → Class N+1`, **same section letter** ·
foundational ladder `-5…-1 → Pre-Nursery…Class 1st` · anything else → skip.

**Structural fact that bounds the risk:** promotion **preserves the section letter** (5-A → 6-A). It
is a per-class 1:1 move, not a merge, so collisions require a *pre-occupied* destination and overflow
requires next year's section to be smaller than this year's cohort.

## 6. PRE-FLIGHT DESIGN

**Phase 1 — plan.** Walk the source cohort applying the *same* skip rules as the write loop
(non-array, empty id, inactive, **already in target session**) and resolve each destination through
the shared `_rolloverNextClass()`. Alumni and unmappable students take no seat. No writes.

**Phase 2 — validate.** Capacity per destination, then roll conflicts (destination roster, then
in-batch). Both classes of issue are collected so the operator can fix everything in one pass.

**Phase 3 — write.** Only reached when the plan is entirely clean; the original loop is untouched.

**Drift elimination:** the destination calculation is now **one** implementation. Had the pre-flight
computed `Class 6th` while the writer computed something else, validation would have guarded a
section the write never touched — correct-looking and worthless.

## 7. CAPACITY VALIDATION

Canonical semantics reused verbatim (Phase 16.5 / D17): configured positive value wins;
unset / zero / invalid → **40**; withdrawn students free their seat; **target session only**;
`sectionDocId()` throughout. **No second capacity rule was created.**

The running projection is per destination — `existing occupancy + planned incoming ≤ capacity` —
computed **before** any write, so the memoised snapshot cannot go stale mid-validation.

## 8. ROLL VALIDATION

Scope unchanged: **School + Session + Class + Section**, case-insensitive, alphanumeric-safe
(string comparison, never a numeric cast — `12A ≠ 12`). Blank rolls never collide. **Nothing is
renumbered and no roll is cleared** — the operator resolves the conflict and retries. **D19 is not
decided here.**

## 9. EXISTING DESTINATION STUDENTS

Explicitly handled, not assumed empty. The destination roster is indexed through the canonical
`_filterSectionRoster()`, which counts repeaters, students already admitted into the target session,
and anyone promoted by an earlier partial run — while excluding withdrawn students and other sessions.
Error text names the destination, the roll, the existing student and the incoming student.

## 10. LOCK / CAS / RESUME ANALYSIS — HONEST

**Preserved, not redesigned.** The intent map, `sessions` lock, CAS precondition and resume path are
untouched.

**Placement:** the pre-flight sits *inside* `if ($promoteStudents)`, **after** section copy and
**before** the first student write. Sections must already exist for capacity to resolve against real
configuration rather than the default — and `promoteStudents && !copySections` already force-enables
the copy.

**Consequence stated plainly:** a refused promotion may leave **sections already copied**. That is
**not a partial promotion** — it is idempotent structure setup which a retry skips as existing. The
D21 guarantee is specifically *no partially promoted students*, and that holds.

**Resume safety:** the pre-flight applies the same `session === $to` skip the write loop uses, so
students promoted by an earlier attempt are excluded from the plan and **not double-counted** against
capacity. Without that, a legitimate resume would be wrongly refused.

⚠ **What this is NOT:** this is **logical** all-or-nothing, not database-level atomicity. Firestore
writes here are per-student, so an **infrastructure** failure mid-loop (network, quota) can still
leave a partial state — exactly as before this phase. That case is what the existing intent/resume
architecture handles. **I am not claiming transactional atomicity, because the code does not provide
it.** What is now guaranteed: **no partial promotion caused by a validation failure.**

## 11. AUDIT

A blocked run writes a **distinct** audit action, `rollover_promotion_blocked`, with
`outcome: 'blocked'` and `studentsPromoted: 0` — so history can never imply a promotion occurred.
Reuses the canonical `log_audit()` with the payload nested under `meta` (the only keys that helper
persists — verified in Phase 16.8). **No new audit architecture.**

## 12. ERROR HANDLING

HTTP **409**, structured, capped at 10 items per class with an "…and N more" suffix:

```
Promotion blocked — no students were promoted.
 | Capacity: 6-A: 40 student(s) would occupy 38 seat(s) (already 0 active)
 | Roll Number conflicts: 6-A Roll 18: Rahul conflicts with Priya already in that section
```
Both conflict classes are reported together so the operator fixes everything and retries once.

## 13. PERFORMANCE / READ ANALYSIS

| Component | Cost |
|---|---|
| Source cohort | already loaded — **0 extra** |
| Destination occupancy + rolls | **1** request-memoised student snapshot, shared with every other guard |
| Capacity | **1 `sections` read per DISTINCT destination**, memoised per `session\|class\|section` |
| Plan / conflict maps | in-memory, O(students) CPU |

**O(destination sections), NOT O(students).** 2,000 students across 4 destinations = **4** capacity
resolutions. Destination rolls are indexed into maps **once**; calling `_rollNoConflict()` per student
would have been O(students²) CPU over the snapshot — deliberately avoided. **No N+1. No new index.**

## 14. CONCURRENCY — HONEST

Pre-flight runs inside the existing rollover flow, which is serialised by the `sessions` lock for its
intent/CAS stages. **The long promotion stage runs unlocked by pre-existing design (D1=B).** So:

- **Two concurrent rollovers** of the same `from → to` are protected by the intent map — the second
  sees `running`/`completed` and resumes or short-circuits.
- **A concurrent *admission*** into a destination section between pre-flight and write could still
  push that section one over. **This is a real, remaining race**, unchanged in kind from every other
  placement path — capacity is **detection-grade, not atomic**, programme-wide (CAP-2).

**I am not claiming this made promotion atomic.** It made it *validated*.

## 15. SECURITY / RBAC

**D20 preserved exactly** — promotion still requires `Configuration:manage` **+** `SIS:manage`, with
the empty-`$allowed` form so the legacy role-name list cannot grant it. The pre-flight sits *after*
that gate, so an unauthorised caller never reaches validation, let alone a write. No new permission.
No rules/indexes change.

## 16. STUDENT LIFECYCLE REGRESSION MATRIX

| Path | Capacity | Roll collision | Session | Audit | Auth |
|---|---|---|---|---|---|
| Direct admission | ✅ | ✅ | ✅ | ✅ | SIS |
| CRM enrolment | ✅ | ✖ none set | ✅ | ✅ | SIS |
| Bulk import | ✅ preview + commit | ✅ in-file + DB | ✅ | ✅ | SIS |
| Re-admission | ✅ | ✅ | ✅ | ✅ | SIS |
| `execute_promotion` | ✅ | ✅ | ✅ | ✅ | SIS |
| Section transfer | ✅ | ✅ | ✅ | ✅ | SIS (D16) |
| **Rollover promotion** | ✅ **(this phase)** | ✅ **(this phase)** | ✅ | ✅ | ✅ (D20) |
| Rollover structure | n/a | n/a | ✅ | ✅ | Configuration |

**Every student-placement path in ZenX now enforces capacity and roll uniqueness.**

Identity invariants re-verified: Student ID canonical · SR Number never derived · Roll scoped
School+Session+Class+Section · rollover copies no student identity · **no student renumbered**.

## 17. FEES CROSS-LIFECYCLE CHECK

**No Fees code touched.** Promotion writes only placement fields; it does not touch `feeDemands`,
`feeReceipts` or accounting. `carry_forward_fees` remains a separate, explicitly-invoked endpoint
under `Fees:manage` and is not called by rollover. **No cross-lifecycle Fees defect found.** All seven
Fees deferred items intact.

## 18. OTHER MODULE IMPACT

Attendance, Examination, Report Card, Homework, LMS, Transport, Hostel, Library, Communication,
Certificates, Parent App and Teacher App key on `studentId`. Refusing an invalid promotion **prevents**
bad state downstream; the write payload is unchanged. **No module modified.**

## 19. TESTS

```
PRE-PHASE BASELINE:  1039 tests, 3019 assertions, 10 failures
AFTER PHASE 17:      1065 tests, 3069 assertions, 10 failures
NEW TESTS: 26         NEW FAILURES: 0
LINT: clean · RTDB (touched regions): 0 · RULES/INDEXES: untouched
```
Pre-existing failures unchanged and **not mine**: Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2. **This is not "all tests pass".**

Coverage maps to your 27 scenarios: all fit ✅ · exactly full ✅ · **one over blocks everything** ✅ ·
**39 valid + 1 conflict still blocks** ✅ · per-destination projection ✅ · existing destination
students counted ✅ · withdrawn free seats ✅ · unset capacity = 40 ✅ · destination roll collision ✅ ·
in-batch collision ✅ · same roll different sections legal ✅ · alphanumeric + case-insensitive ✅ ·
`12A ≠ 12` ✅ · blank rolls ✅ · **both conflict types reported together** ✅ · Class 12 → Alumni takes
no seat ✅ · foundational ladder ✅ · unmappable students take no seat ✅ · **already-promoted not
double-counted (resume)** ✅ · inactive not promoted ✅ · **no writes on refusal** ✅ · **audit reports
zero promoted** ✅ · cross-session capacity ✅ · cross-session rolls ✅ · **O(destinations) not
O(students)** ✅ · identity invariants ✅ · no renumbering ✅.
RBAC scenarios (9–13) are covered by Phase 16.9's suite, which still passes unchanged.

## 20. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | 30 students → 6-A capacity 40 | Promotes | **UAT PENDING** |
| 2 | 40 → capacity 40 | Promotes (exactly full) | **UAT PENDING** |
| 3 | **40 → capacity 38** | **409, NO student promoted** | **UAT PENDING** |
| 4 | **Verify roster after (3)** | **All 40 still in the old session** | **UAT PENDING** |
| 5 | Destination already holds 10 repeaters | Counted against capacity | **UAT PENDING** |
| 6 | **Destination Roll 18 taken, incoming Roll 18** | **409 naming both students** | **UAT PENDING** |
| 7 | Two incoming students share Roll 7 | 409 | **UAT PENDING** |
| 8 | Capacity + roll conflicts together | Both listed in one message | **UAT PENDING** |
| 9 | Class 12 cohort | → Alumni, no capacity error | **UAT PENDING** |
| 10 | Re-run after a partial run | Already-promoted skipped, not double-counted | **UAT PENDING** |
| 11 | **Audit after a blocked run** | `rollover_promotion_blocked`, `studentsPromoted: 0` | **UAT PENDING** |
| 12 | Configuration-only user | Refused at the D20 gate | **UAT PENDING** |
| 13 | Alphanumeric roll `12A` vs `12` | Not a conflict | **UAT PENDING** |
| 14 | Withdrawn destination students | Do not consume seats | **UAT PENDING** |
| 15 | Structure-only rollover | Unaffected | **UAT PENDING** |

**Rows 3, 4 and 6 are the critical ones. No browser or device UAT has been executed at any point.**

## 21. REMAINING DEFECTS

**None HIGH or CRITICAL in the Class/Section/Roll/Promotion lifecycle.** Remaining items are
decisions and surveys, not correctness defects.

## 22. DEFERRED WORK REGISTER

**Fees (verified intact, untouched):** FEES-D7 · FEES-D9 · FEES-D13 · FEES-D14 · FEES-D15 ·
FEES-FROZEN-1 · FEES-UAT.

**Class/Section/Roll:** ROLL-1/2 (**D19** — bulk roll assignment, fully designed) · ROLL-3 (**D5**
survey) · ROLL-4 (**D4** promotion roll policy) · SEC-1 (**D3** full-section override) ·
SEC-5 (orphaned section docs survey) · SEC-6 (section CRUD audit — small, `log_audit` available) ·
SEC-16 (preview capacity editor **UI**) · ROLL-6 (closed by analysis) ·
**CAP-2 (atomic placement primitive — the remaining concurrency race, programme-wide)** ·
EXAM-1 · HC-RTDB · LMS-RTDB.

## 23. GIT STATUS

```
Branch: integration/phase2      HEAD: 758c924   (unchanged — no commit)
Modified this phase:  application/controllers/School_config.php
                      application/core/MY_Controller.php   (private → protected)
New this phase:       tests/Unit/Phase17RolloverPromotionPreflightTest.php
firebase-rules/* ...  dirty BEFORE this programme — NOT touched
Fees files .........  dirty from Phases 15.5–15.9 — NOT touched this phase
COMMIT: none  PUSH: none  MERGE/REBASE: none  DEPLOY: none
```

---

## 24. FINAL VERDICT: **READY FOR UAT**

SEC-17 is fixed, D21 is implemented as decided, tests show **zero new failures**, static verification
is clean, and **no HIGH or CRITICAL lifecycle defect remains** in Class/Section/Roll/Promotion.

**Not `CLOSED`**, for two honest reasons:
1. **No UAT has been executed** — not once in this entire programme.
2. **CAP-2 remains**: capacity is detection-grade, not atomic, on *every* placement path. A concurrent
   admission can still put a section one over. That is a known, programme-wide architectural limit,
   not a regression — and I will not call the lifecycle "closed" while pretending otherwise.

**D19 (bulk roll assignment) is now unblocked by correctness** and needs only your confirmation of
Option C.

---

## 25. WHAT THIS MEANS FOR A REAL SCHOOL

**Your year-end rollover can no longer half-promote the school.**

Until now, if you rolled over into the new year and ticked "promote students", ZenX moved every child
without checking whether next year's classrooms had room, or whether two children would end up with
the same roll number. It would happily put 40 children into a 38-seat section, or create two Roll 18s
in 6-A if someone was already sitting there — the very things the ordinary promotion screen refuses.

Now it checks the **whole** promotion first. If anything is wrong, **nothing happens at all** — not
one child is moved. You get a single message listing every problem: which sections are short of seats,
and which roll numbers clash and with whom. Fix them, run it again, and it goes through in one pass.

That "nothing happens at all" is deliberate, and it's the decision you made. The alternative — moving
the children who fit and leaving the rest behind — would split your school across two academic years
mid-rollover, with registers and mark sheets generated against half a class. Far easier to fix two
roll numbers and re-run.

**Two things I want to be straight about.**

First, if the promotion is refused, next year's **sections** may already have been created. That's
harmless — it's just the empty structure, and running rollover again simply reuses it. No child was
touched.

Second, I'm not claiming this is bulletproof against two people working at the same moment. If a
colleague admits a new student into next year's 6-A in the exact seconds between the check and the
move, that section could still end up one over. That limitation exists on every screen that places a
student, it's on our list as a known architectural item, and I'd rather name it than let you assume a
guarantee we haven't built.

**And one thing I nearly got wrong myself.** My first version of this check called an internal helper
that turned out to be off-limits to that part of the code — it would have crashed the moment a school
tried to promote, and neither the syntax check nor the tests would have caught it. I verified every
helper before declaring this done, found it, and fixed it.

**Nothing has been committed or deployed, and none of this has been clicked through in a browser.**
The three things worth testing first: promote 40 students into a 38-seat class and confirm **nobody**
moves; check they're all still in the old year afterwards; and try a duplicate roll number.
