# Phase 16.5 — Section Capacity Semantics + Roll Claim Integrity

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED. NOT COMMITTED / PUSHED / MERGED / REBASED / DEPLOYED. UAT NOT EXECUTED.**
No production data touched. `firestore.rules` / `firestore.indexes.json` **NOT modified**.
No RTDB introduced. **No Fees code touched.** No AI placement, no automatic class assignment.

---

## 1. EXECUTIVE SUMMARY

D17 and D18 are implemented. Tracing them surfaced **three further defects on the same paths —
including one I introduced in Phase 16.4** — all fixed here.

| ID | Finding | Sev | Status |
|---|---|---|---|
| **SEC-4** | Unset capacity meant **40** in admission/import/CRM but **unlimited** in promotion/transfer | 🟡 MED | ✅ **FIXED** — one semantic |
| **SEC-9** | ⚠ **REGRESSION I INTRODUCED IN 16.4.** Transfer still hand-built the **session-less** section doc-id. After my SEC-2 fix it found nothing → `maxStrength = 0` → **the transfer capacity check was silently disabled** | 🔴 **HIGH** | ✅ **FIXED** |
| **SEC-7** | Promotion read the capacity document for the **current** session while promoting **into another** | 🔴 HIGH | ✅ **FIXED** |
| **SEC-8** | Promotion **and** transfer counted occupancy with `schoolWhere()` — school-scoped but **not session-scoped** — so other sessions' students consumed this session's seats | 🔴 HIGH | ✅ **FIXED** |
| **SEC-10** | `Staff.php` (×2) wrote class-teacher / subject-teacher assignments to the **session-less** section doc — also diverged by my 16.4 fix | 🟡 MED | ✅ **FIXED** |
| **ROLL-5** | `claimedAt` written but never read → an abandoned "Suggest" blackholed that number permanently | 🟡 MED | ✅ **FIXED** — 30-min server-side TTL |

**Tests: 976 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +18 new tests.**

**The most important admission in this report:** my Phase 16.4 SEC-2 fix corrected `create_section`
but I did not sweep for the *other* places that built the same id by hand. Three existed
(transfer, Staff ×2). One of them — transfer — silently **switched off a capacity guard**. Fixing one
writer and not its siblings is precisely the half-fix this programme forbids, and the sweep that
should have run in 16.4 ran here instead.

---

## 2. PART 1 — CAPACITY LIFECYCLE VERIFIED FROM SOURCE

> *"Can a school configure 30 seats for 5-A, 40 for 5-B, 35 for 5-C, and does ZenX enforce exactly
> those values?"*

**Before this phase: NO. After: yes** — subject to UAT.

| # | Question | Source answer |
|---|---|---|
| 1 | Where is a Class+Section created? | `Classes::create_section()` |
| 2 | Where is capacity set? | same form, and `Classes::save_section_settings()` |
| 3 | Field | **`maxStrength`** (readers also accept `max_strength`) |
| 4 | Collection | `sections` |
| 5 | Session | stored on the doc **and** embedded in `sectionDocId()` |
| 6 | Settings screen | `get_section_settings` `:392` / `save_section_settings` `:422` — both canonical |
| 7 | Per-section capacities? | **Yes** — one document per class+section+session |
| 8–10 | Respected consistently? | **NO before this phase** — see below |

**Path-by-path, before → after:**

| Path | Capacity source (before) | Unset meant | Occupancy scope | After |
|---|---|---|---|---|
| Direct admission | `_sectionCapacity()` | 40 | session-scoped ✅ | unchanged ✅ |
| CRM enrolment | `_sectionCapacity()` | 40 | session-scoped ✅ | unchanged ✅ |
| Bulk import | `_sectionCapacity()` | 40 | session-scoped ✅ | unchanged ✅ |
| **Promotion** | raw doc, **wrong session** | **unlimited** | **all sessions** ❌ | canonical ✅ |
| **Section transfer** | raw doc, **session-less id → not found** | **unlimited (guard skipped)** | **all sessions** ❌ | canonical ✅ |
| Re-admission | inherits admission | 40 | session-scoped ✅ | unchanged ✅ |

## 3. DECISIONS IMPLEMENTED

**D17 — canonical capacity (your policy, implemented verbatim):**
configured positive value → use exactly that; missing / unset / zero / invalid → **40**; identical on
every path. Implemented by routing promotion and transfer through the **existing**
`_sectionCapacityExceeded()` rather than patching five `if` statements — there is now **one**
capacity interpretation, not six.

**D18 — roll-claim TTL: 30 minutes**, server-side. Verified safe against your ten checks (§6).

## 4. EXTERNAL ERP RESEARCH (re-confirmed, no new claims invented)

| Topic | Finding | Label |
|---|---|---|
| Capacity configured **per section** | ZenX model matches the batch/section model | **ZENX-VERIFIED**; per-section capacity **NOT ESTABLISHED BY SOURCES** as a documented vendor feature |
| Roll uniqueness scope | *"two students of a batch cannot be assigned the same roll number"* | **SOURCE-VERIFIED** (AMS ERP) |
| Bulk generation + sort by name / admission no / date of joining / age | documented | **SOURCE-VERIFIED** (NextERP, AMS ERP, Fedena) |
| Alphanumeric rolls | roll format composable from class+section+text | **SOURCE-VERIFIED** (NextERP) |
| Automatic roll at admission | **NOT ESTABLISHED** — manual or a separate explicit operation |
| Section config carried across sessions | **NOT ESTABLISHED BY SOURCES** — see SEC-3 |
| Claim/TTL mechanics | **NOT ESTABLISHED** — no vendor documents internals; the 30-minute value is a **ZENX RECOMMENDATION** |

## 5. PART 4 — SEC-3 (session rollover) — INVESTIGATED, NOT RESOLVED

Architecture is **session-scoped**: `2025-26/Class 5/A` and `2026-27/Class 5/A` are separate
documents (ZENX-VERIFIED). Of your four options:

- **(C) already copied by a rollover process? — NO.** I searched the promotion/session flow; **no
  code copies section definitions or capacities into a new session.**
- **(D) currently ambiguous — YES.** Nothing creates next year's sections, so after rollover every
  section is unconfigured and now resolves to the canonical default of **40** until someone creates
  it for the new session.

⛔ **STOPPING AT THIS BOUNDARY.** Whether section definitions should auto-carry-forward is a product
decision, and building a rollover copier would invent policy. **Registered as SEC-3.** Note that D17
makes the consequence *safe and predictable* (40) rather than *silently unlimited*, which is a strict
improvement either way.

## 6. ROLL-5 — TTL SAFETY, against your ten checks

| # | Check | Result |
|---|---|---|
| 1 | How claims are created | `firestoreCreate` (create-if-not-exists) on `{schoolId}_{md5(session_class_section_candidate)}` |
| 2 | Interaction with a successful save | **NONE** — no release exists; the claim simply ages out |
| 3 | Can a claim belong to another operator/session? | Scoped to school+session+class+section; `claimedBy` records the actor |
| 4 | Is 30 minutes safe? | **Yes** — far longer than Suggest→Save, far shorter than "never" |
| 5 | Explicit release on save also appropriate? | **Yes, and better** — but it needs a release call in every save path (admission, edit, import). Deliberately **not** bolted on here; registered as **ROLL-6** |
| 6 | Are both required? | No — TTL alone restores correctness; release would only free numbers sooner |
| 7 | Can an active claim be stolen? | **No** — 30 min ≫ form lifetime |
| 8 | Can expiry create a duplicate? | **No** — `$taken` is built from the **actual roster** before any claim is inspected, so expiry only frees a number nobody owns; `_rollNoConflict()` remains authoritative at save |
| 9 | Manual entry affected? | **No** — untouched |
| 10 | Canonical validator still authoritative? | **Yes** — unchanged |

**Fails closed:** if the claim cannot be re-read, it stays held and the loop moves on — handing out a
possibly-live number is the worse outcome. A missing/unparseable `claimedAt` counts as expired,
because such a claim could otherwise never expire.

## 7. FILES CHANGED

| File | Change |
|---|---|
| `application/controllers/Sis.php` | promotion → canonical validator with **`$toSession`** (SEC-4/7/8); ROLL-5 TTL + `ROLL_CLAIM_TTL_SECONDS` |
| `application/controllers/Classes.php` | transfer → canonical validator (**SEC-9** regression, SEC-4, SEC-8) |
| `application/controllers/Staff.php` | ×2 canonical `sectionDocId()` (SEC-10) |
| `tests/Unit/Phase165CapacitySemanticsAndRollClaimTest.php` | **NEW** — 18 tests |

## 8. PART 8 — CROSS-MODULE AUDIT

| Check | Result |
|---|---|
| Any module treating **rollNo as a lookup key** / globally unique | **NONE FOUND** — repo-wide sweep returned nothing |
| Any module deriving **SR Number from Student ID** | **NONE** — the only hits are `Sr_no_service::claim($srNo, $studentId)`, which *associates* the two (an index), never derives |
| Any module using **Roll Number as identity** | None — every module keys on `studentId` |
| Remaining hand-built section doc-ids | **NONE** — transfer and Staff ×2 were the last; all now use `sectionDocId()` |
| Wrong-session reads on placement paths | Fixed (SEC-7/8) |

Attendance, Marks, Results, Homework, Communication, Transport, Library, Hostel, PTM, Student 360,
Reports, Certificates, Timetable, Examination, Parent App, Teacher App: **display roll, key on
`studentId`** — no change required and none made.

## 9. CAPACITY SEMANTICS — BEFORE / AFTER

```
BEFORE
  admission/import/CRM : capacity = configured ?: 40   · occupancy = session-scoped
  promotion            : capacity = raw(WRONG session) · unset = UNLIMITED · occupancy = ALL sessions
  transfer             : capacity = raw(session-less id → NOT FOUND) → guard SKIPPED ENTIRELY

AFTER  (every path, one implementation)
  capacity  = configured positive value, else 40
  occupancy = Active students in school + session + class + section
  promotion uses the TARGET session
```

## 10. ROLL CLAIM SEMANTICS — BEFORE / AFTER

```
BEFORE   claim created → never read, never released → number blackholed FOREVER
AFTER    claim created → binding 30 min → then reclaimable; reclaim logged
         (roster remains the first filter; _rollNoConflict remains authoritative)
```

## 11. PERFORMANCE

| Operation | Before | After |
|---|---|---|
| Promotion capacity | 1 `sections` get **+ 1 unbounded students query** | **0 new** — memoised snapshot |
| Transfer capacity | 1 `sections` get **+ 1 unbounded students query** | **0 new** — memoised snapshot |
| Suggest (no collision) | 1 create | unchanged |
| Suggest (collision) | 0 | **+1 read per collision** (rare path only) |

**Net: two Firestore queries removed from the placement hot paths.** The replaced student queries
were unbounded (`schoolWhere` with no session predicate) and grew with total school size; the
canonical validator reads the shared per-request snapshot. **No N+1 introduced.**

## 12. CONCURRENCY

| Operation | Class |
|---|---|
| Roll "Suggest" claim | **ATOMIC** (create-if-not-exists) |
| Claim reclaim after TTL | **read-then-set — NOT atomic.** Two operators reclaiming the *same expired* number in the same instant could both proceed. **Bounded and safe:** `_rollNoConflict()` still rejects the duplicate at save. Stated rather than glossed. |
| Capacity (all paths) | **DETECTION-GRADE** — unchanged; CAP-2 remains the future shared primitive |

## 13. RTDB VERIFICATION
`Sis.php` (touched regions) · `Classes.php` · `Staff.php` (touched regions): **no `shallow_get`, no
`getReference`**. **No repo-wide eradication claimed** — HC-RTDB and LMS-RTDB remain registered.

## 14. TESTS

```
PRE-PHASE BASELINE:  958 tests, 2826 assertions, 10 failures
AFTER 16.5:          976 tests, 2869 assertions, 10 failures
NEW TESTS: 18        NEW FAILURES: 0
LINT: clean · RTDB: none introduced · RULES/INDEXES: untouched
```
Pre-existing failures unchanged and **not mine**: Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2. **This is not "all tests pass".**

Coverage: configured 30 enforced · **configured 50 not capped at 40** · three sections, three
capacities · unset → 40 on every path · **promotion and admission now agree** · transfer agrees ·
**other sessions don't consume this session's seats** · independent per-session capacity · withdrawn
frees a seat · **promotion uses the target session** · fresh claim not stealable · claim just under
TTL held · claim at TTL expires · missing timestamp = expired · **expiry can't hand out a held
number** · **gap reused after expiry** · alphanumeric rolls · identity invariants.

## 15. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | Create 5-A with capacity 30 | Saved | **UAT PENDING** |
| 2 | Reopen Section Settings | Shows **30** | **UAT PENDING** |
| 3 | Admit 30 students | All accepted | **UAT PENDING** |
| 4 | **Admit the 31st** | **Rejected** | **UAT PENDING** |
| 5 | Create 5-B with capacity 50 | 50 enforced, **not 40** | **UAT PENDING** |
| 6 | **Promote into a section at capacity** | Blocked with the same numbers | **UAT PENDING** |
| 7 | **Promote into next session's empty section** | **Allowed** — last year's students no longer counted | **UAT PENDING** |
| 8 | **Transfer into a full section** | **Rejected** (guard was disabled pre-fix) | **UAT PENDING** |
| 9 | Import into a full section | Rejected per row | **UAT PENDING** |
| 10 | CRM enrolment into a full section | Rejected | **UAT PENDING** |
| 11 | Section with no capacity configured | Caps at **40** on every path | **UAT PENDING** |
| 12 | Two sessions, different capacities | Independent | **UAT PENDING** |
| 13 | Suggest Roll → save immediately | Number retained | **UAT PENDING** |
| 14 | Suggest Roll → abandon → retry **within** 30 min | Skips that number | **UAT PENDING** |
| 15 | Suggest Roll → abandon → retry **after** 30 min | **Reuses it**; `ROLL_CLAIM_RECLAIMED` logged | **UAT PENDING** |
| 16 | Manual roll entry | Unaffected | **UAT PENDING** |
| 17 | Alphanumeric roll `12A` | Accepted; `12A ≠ 12` | **UAT PENDING** |
| 18 | **Assign a class teacher** | Lands on the section the rest of the system reads (SEC-10) | **UAT PENDING** |

**Rows 7, 8 and 18 are the critical ones** — they cover the two session-scoping fixes and the
regression I introduced in 16.4. **No browser or device UAT has been executed at any point.**

## 16. FEES DEFERRED REGISTER — VERIFIED INTACT, NO FEES CODE TOUCHED

FEES-D7 · FEES-D9 · FEES-D13 · FEES-D14 · FEES-D15 · FEES-FROZEN-1 · FEES-UAT — all present and
unchanged. *(The Fees files shown as dirty in `git status` are the uncommitted work of Phases
15.5–15.9; **none was modified this phase**.)*

## 17. CLASS/SECTION/ROLL REGISTER

| ID | Sev | Location | Dependency | Acceptance condition |
|---|---|---|---|---|
| **SEC-3** | 🟡 MED | session rollover | **Product decision** | Carry-forward policy defined, or documented as "create per session" |
| **ROLL-6** (new) | 🟢 LOW | roll claim release on save | — | Claim released when the student is saved, freeing the number immediately |
| SEC-5 | 🟢 LOW | orphaned session-less section docs | Authorization | Read-only survey; no production write |
| SEC-6 | 🟢 LOW | section CRUD unaudited | — | Section create/edit/delete write history |
| ROLL-1/2 | 🟡 MED | bulk assign screen | **D19** | Screen built; CRM students receive rolls |
| ROLL-3 | 🟡 MED | duplicate-roll survey | **D5** | Survey run; no auto-renumber |
| ROLL-4 | 🟡 MED | promotion roll policy | **D4** | Carry-forward vs clear decided |
| SEC-1 | 🟡 MED | full-section override | **D3** | Override authority + audit |
| CAP-2 | 🟡 MED | shared guards | Design | One atomic primitive across capacity+roll+promotion+transfer |
| EXAM-1 · HC-RTDB · LMS-RTDB | — | as registered in 16.3/16.4 | — | unchanged |

## 18. BUSINESS DECISIONS STILL REQUIRED

**SEC-3** section carry-forward at rollover · **D19** existing-roll policy (blocks bulk assignment) ·
**D3** override authority · **D4** promotion roll carry-vs-clear · **D5** duplicate-roll survey.

## 19. RECOMMENDED NEXT PHASE

**Bulk Roll Assignment** — fully designed in 16.4, and now unblocked technically; it needs only
**D19**. Answering SEC-3 alongside it would close the section lifecycle end to end.

---

## 20. WHAT THIS MEANS FOR A REAL SCHOOL

**Set 30 seats and you now get 30. Set 50 and you get 50 — on every route a student can arrive by.**

Until now the answer depended on *how* a child was placed. Admissions, imports and the enquiry-to-
enrolment flow used the number you configured. Promotions and transfers used different rules
entirely — and if you had never set a capacity at all, they treated the section as having no limit
while admissions quietly capped it at 40. One section, two different answers.

There is now one rule everywhere: **the number you set, and 40 if you never set one.**

**Three things were badly wrong on promotions and transfers, and I want to be straightforward that
one of them was my own doing.**

Promotion was reading last year's capacity setting to decide next year's limit. It was also counting
*this* year's students as though they already occupied next year's classroom — so promoting a class
into a room that was actually empty could be refused for being "full".

And transfers: **my fix in the previous phase accidentally switched the transfer capacity check off
entirely.** I corrected where section settings are saved, but missed two other places in the code
that still looked in the old location. One of them was the transfer screen, which then found no
capacity and stopped checking. A related miss meant assigning a class teacher was writing to a record
nothing else reads. Both are fixed, and I've swept for others — there are none left.

**The Suggest Roll Number button no longer loses numbers.** Previously, clicking it and then closing
the form reserved that number forever, so suggestions crept upward and skipped free ones. A
suggestion now holds a number for half an hour — long enough that nobody working on a form loses it,
short enough that an abandoned one comes back the same day.

**One question I've left with you:** when a new academic year starts, nothing currently carries your
section setup forward. Each section needs creating again for the new session, and until then it falls
back to 40 seats. That's now predictable rather than unpredictable — but whether ZenX should copy
your sections forward automatically is your decision, and I won't invent it.

**Nothing has been committed or deployed, and none of this has been clicked through in a browser.**
The three tests worth doing first are promoting into next year's empty section, transferring into a
full one, and assigning a class teacher.
