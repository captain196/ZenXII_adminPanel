# Phase 16.0 — Class / Section / Capacity / Roll Number
## Research + Pre-Implementation Analysis

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**RESEARCH ONLY — NO CODE MODIFIED.** Nothing committed / pushed / merged / rebased / deployed.
No production data read or written. `firestore.rules` / `firestore.indexes.json` untouched.
**UAT NOT EXECUTED.**

---

## A. EXECUTIVE SUMMARY

The capacity work from Phase 15 holds up — I re-verified all five placement paths independently and
every one is guarded. **But the roll-uniqueness guarantee has a hole, on a routine operation, and it
produces exactly the outcome you said you don't want.**

**CS-1 🔴 HIGH — Section transfer creates duplicate roll numbers.**
`Classes::transfer_students()` ([Classes.php:565-577](application/controllers/Classes.php#L565))
updates class/section and **leaves `rollNo` untouched**, with **no roll validation anywhere in the
method**. Transfer Rahul (Roll 18) from 5-A into 5-B where Priya already holds Roll 18 → **5-B now has
two Roll 18s.** Capacity *is* checked on this path (line 538-547); roll is simply not.

Two more defects on the same method:

- **CS-2 🟡 MEDIUM — no audit trail.** Transfer writes no `_log_history` entry. Promotion does. A
  student's section changes with no record of who moved them or when.
- **CS-3 🟡 MEDIUM — RBAC mismatch.** Transfer is gated on `Configuration:manage`, while every other
  student-placement operation is gated on `SIS`. A user with Configuration rights but **no SIS
  rights** can move students between sections.

**Also corrected: my own momentary suspicion about promotion.** A grep for `_rollNoConflict(` did not
match inside `execute_promotion`, which looked like a gap. It is not — promotion has the Phase 14.1
pre-check implemented as a bespoke `$rollOwners` destination map (`Sis.php` ~1526-1573). **Verified
correct.** Method names and helper greps are not evidence; execution is.

**External research strengthened the roll model considerably** — see §G. Bulk roll assignment with
sort-by-name / admission-number / DOB is now **SOURCE-VERIFIED across three vendors**, not the single
source Phase 15.3 had, and vendor documentation explicitly states **batch-scoped roll uniqueness**,
which directly validates ZenX's School+Session+Class+Section scope.

---

## B. EXTERNAL ERP RESEARCH MATRIX

| # | Question | Finding | Label | Source |
|---|---|---|---|---|
| L–O | Roll assignment | Dedicated **"generate roll numbers"** operation, separate from admission | **SOURCE-VERIFIED** | [NextERP](https://helpcenter.nexterp.in/helpcenter/how-to-generate-roll-numbers-for-the-students/), [AMS ERP](https://helpdesk.amserp.in/knowledge-base/how-can-i-assign-roll-numbers-for-students/), [Fedena](https://support.fedena.com/support/solutions/articles/206013-how-do-i-assign-student-roll-numbers-during-admission-) |
| Q–S | Ordering | Sort by **alphabetical (A-Z / Z-A), admission number, date of joining, age** | **SOURCE-VERIFIED** | NextERP, AMS ERP |
| P | Scope | *"Roll numbers are unique to students **within a batch**; two students of a batch cannot be assigned the same roll number"* | **SOURCE-VERIFIED** | AMS ERP |
| — | Cross-batch collision | Vendors recommend a **common prefix per batch** to disambiguate | SOURCE-VERIFIED | AMS ERP |
| — | Format | Roll format composable from **class + section + text fields**, drag-drop ordered | **SOURCE-VERIFIED** | NextERP |
| M | Manual entry | Supported | SOURCE-VERIFIED | Fedena, VirtueSkool |
| O | Bulk operation | Class+Section → fetch roster → assign → update | SOURCE-VERIFIED | [VirtueSkool](https://virtueskool.com/HelpAndManual/HTML/assign-roll-numbers.html) |
| T | Separate **exam roll number** | Exists as a distinct field | SOURCE-VERIFIED | VirtueSkool |
| U–V | Promotion / renumbering | **No vendor documents what happens to rolls on promotion** | **NOT ESTABLISHED** | — |
| W | Duplicate prevention mechanics | Stated as a rule, mechanism undocumented | INDUSTRY PRACTICE | AMS ERP |
| X | Reassignment tools | Exist (same bulk screen) | SOURCE-VERIFIED | NextERP |
| A–B | Class/Section at admission | Manual selection in an admission workflow | INDUSTRY PRACTICE | Fedena, NextERP |
| C–E | Capacity mandatory? Full-section behaviour? | **No vendor documents capacity enforcement or a full-section policy** | **NOT ESTABLISHED** | — |
| F–G | Live-active strength / withdrawn handling | **Not documented** | **NOT ESTABLISHED** | — |
| H–K | Capacity during promotion / transfer / import / re-admission | **Not documented** | **NOT ESTABLISHED** | — |
| — | Section **balancing algorithms** | **Not documented by any vendor** | **NOT ESTABLISHED** | — |

**Two honest conclusions from the gaps:** capacity enforcement and full-section policy are **not**
established industry behaviour — ZenX's enforcement is a deliberate product decision (a good one),
not a catch-up. And nothing supports building a balancing algorithm.

## C. ZENX CURRENT-STATE LIFECYCLE MATRIX (traced, not assumed)

| Path | Entry point | Class/Section source | Capacity | Roll | Cache bust | Audit | RBAC |
|---|---|---|---|---|---|---|---|
| Direct admission | `Sis::save_admission` | form | ✅ `:653` | ✅ `:665` | ✅ | ✅ | SIS |
| Bulk import | `Sis::_runStudentImport` | file | ✅ `:5023` (running projection) | ✅ `:5058` | ✅ | ✅ | SIS |
| CRM enrolment | `Sis::enroll_student` | application + picker | ✅ `:8928` | ✖ not set | ✅ | ✅ | SIS |
| Edit student | `Sis::save_student` | form | n/a | ✅ `:6625` | ✅ | ✅ | SIS |
| Promotion | `Sis::execute_promotion` | target class | ✅ `:1512` | ✅ `$rollOwners` pre-check | ✅ (15.5 fix) | ✅ | SIS |
| **Section transfer** | **`Classes::transfer_students`** | operator | ✅ `:538` | ❌ **NONE** | ✅ `updateEntity` | ❌ **NONE** | ⚠ **Configuration** |
| Re-admission | via `enroll_student` | new placement | ✅ inherited | ✅ inherited | ✅ | ✅ | SIS |
| Withdrawal / TC | `Sis` | preserved | seat freed | preserved | ✅ | ✅ | SIS |
| Deletion | `Sis::delete_student` | — | seat freed | — | ✅ `removeEntity` | ✅ | SIS |
| Admission backfill | `Sis_admission_backfill` | **history events only** | n/a | n/a | n/a | ✅ | SIS |
| Session rollover | `Sis` / promotion | per-session | ✅ | per-session | ✅ | ✅ | SIS |

**No previously-unknown student-creation path was found.** `Sis_admission_backfill` was audited for
the first time this phase and writes only `studentHistory` — correctly, no placement, no guard needed.

## D. CLASS ASSIGNMENT ANALYSIS

**Question: should ZenX auto-assign Class from DOB/age?**
**NOT ESTABLISHED BY SOURCES.** No vendor documents an age→class rule ZenX could copy, and
age-for-grade norms are board- and state-specific (CBSE/ICSE/state/international differ), so any
hardcoded rule would be wrong for some tenants by construction (**INFERENCE**, labelled).

**ZENX RECOMMENDATION: DO NOT BUILD.** Class stays an explicit administrative decision. Age
*validation* — if ever wanted — is per-tenant configurable policy and a separate authorized project,
not a universal rule. Classification: **H. DO NOT BUILD.**

## E. SECTION ASSIGNMENT ANALYSIS

Current: operator selects; the UI suggests the section with the most headroom; the **server** enforces
capacity. Of your options A–F:

| Option | Verdict |
|---|---|
| A require operator selection | ✅ **current — keep** |
| B suggest most-headroom | ✅ **current — keep** (exceeds what any vendor documents) |
| C automatic assignment | **DO NOT BUILD** — NOT ESTABLISHED |
| D configurable policy | **FUTURE** — no evidence of need yet |
| E waitlist | **DO NOT BUILD** — NOT ESTABLISHED, no ZenX demand |
| F full-section override | **NEEDS YOUR DECISION** — §I |

## F. CAPACITY ANALYSIS

**Re-verified independently: all five placement paths are guarded** (admission, import, CRM,
promotion, transfer). Semantics confirmed: School+Session+Class+Section scope · Active-only occupancy
(withdrawn frees the seat) · `maxStrength <= 0` = unlimited · session embedded in `sectionDocId` and
in the memo key · import uses a running seat projection so a 60-row upload into a 40-seat section
fills exactly the real seats.

**No unguarded path found.** UI/server disagreement is not possible in the failure direction: the UI
displays counts, the server independently re-derives and rejects, so a stale browser cannot push a
section over capacity.

**Remaining capacity gaps (unchanged, both previously known):**
- **CAP-1 🟢 LOW** — import preview shows no capacity projection; rejections are learned post-hoc.
- **CAP-2 🟡 MED** — validation is **detection-grade, not atomic** (read-then-write). Two concurrent
  admissions into the final seat can both pass. Self-correcting on the next attempt.

## G. ROLL NUMBER RESEARCH → CS-1

ZenX today: scoped School+Session+Class+Section · manual entry · opt-in atomic "Suggest" using
`firestoreCreate` on `rollNoClaims` · **string** comparison throughout (alphanumeric-safe, no numeric
cast) · Active-only duplicate detection.

**The research validates this scope directly** — AMS ERP states roll numbers are unique *within a
batch*, which is the same shape as School+Session+Class+Section. **Global uniqueness is contradicted
by the evidence; do not build it.**

**CS-1 — the hole.** Guards exist at admission (`:665`), import (`:5058`), edit (`:6625`) and
promotion (`$rollOwners`). **Section transfer has none.** `transfer_students` writes only
`Class/Section/className/section/updatedAt` — `rollNo` rides along unchanged and unchecked.

**Consequence:** the destination section silently gains a duplicate roll. Attendance registers, mark
sheets and printed lists that order by roll then show two students at the same position. Nothing in
the system will report it, because the guarded paths never see the moved student.

## H. ROLL ASSIGNMENT RECOMMENDATION

| Option | Evidence | Verdict |
|---|---|---|
| **A** Manual + server uniqueness | SOURCE-VERIFIED | ✅ **already built — keep** |
| **B** Manual + "Suggest Next Available" | Exceeds documented practice | ✅ **already built — keep** |
| **C** Bulk assignment tool | **SOURCE-VERIFIED ×3 vendors** | ✅ **BUILD — the one real gap** |
| **D** Alphabetical / name ordering | **SOURCE-VERIFIED** as a sort option *within* C | ✅ **build as an option of C**, not standalone |
| **E** Configurable roll policy | NOT ESTABLISHED | ⚪ FUTURE |
| **F** Separate Exam Roll Number | SOURCE-VERIFIED it exists elsewhere | ⚪ FUTURE — no ZenX consumer today |

**Smallest professional model to implement NOW:** keep A + B, **close CS-1**, and add **C with D's
ordering options**. Everything else is future.

## I. BULK ROLL ASSIGNMENT ANALYSIS

**Does it solve a real ZenX gap?** Yes — two, evidenced rather than assumed:
1. After promotion, rolls are **carried forward** from mixed source sections. A merged 6-A can hold
   rolls 1-40 from 5-A *and* 1-40 from 5-B, blocked only where the pre-check fires. An operator's only
   current remedy is editing students one at a time.
2. CRM enrolment sets **no roll at all** (`:8928` guards capacity; roll is never assigned), so those
   students need a batch pass.

**Proposed workflow** (matches the documented vendor shape):
`Class → Section → roster → ordering (Manual | Name A-Z | Admission No | Existing Roll) → preview →
duplicate validation → atomic save → audit`.

**Cost:** 1 roster read (shared cached snapshot) + N writes, batched. **No N+1.**
**Ordering by name must be `mb_`-safe and locale-stable** — a naive `sort()` on multi-byte names
reorders unpredictably, which would silently renumber a class differently on each run.

## J. DUPLICATE ROLL ANALYSIS

**Existing duplicates cannot be counted here — no service-account credential is present on this
machine. I will not estimate.** Enforcement is forward-only and cannot corrupt existing data.
CS-1 means **new** duplicates are still being created today via transfer, so the survey is worth more
*after* CS-1 is fixed. Read-only survey (School / Session / Class / Section / Roll / Student IDs /
names / count) is specified and needs **D5** authorization. **Never auto-renumber.**

## K. IDENTITY INVARIANT AUDIT

Crossed fixtures asserted across five suites: `STU0012 → SRNO 47 → Roll 12` and
`STU0047 → SRNO 12 → Roll 47`. **No digit-derived logic exists anywhere** — verified again this
phase. `studentId` is the sole persisted FK; SRNO is never a key; Roll is roster position only.
Receipt shows SR No. + Roll, hides Student ID, stores it canonically. ✅

## L. CROSS-MODULE IMPACT

| Consumer | Uses roll? | Impact of CS-1 |
|---|---|---|
| Attendance | display/order | **duplicate positions in the register** |
| Examination / Report Card | display/order | **ambiguous ordering on mark sheets** |
| Homework · Fees · Communication · Transport · Library · Documents | `studentId` only | none |
| Parent App / Teacher App | display only | shows the duplicate |
| Certificates / TC | display | shows the duplicate |

**No module keys off roll**, so CS-1 causes no data corruption — it is an **operational correctness**
defect: the roster is wrong, in a way people rely on daily. Fixing transfer requires **no
cross-module change**.

## M. PERFORMANCE

| Operation | Now | After proposed fixes |
|---|---|---|
| Transfer capacity check | 1–2 reads | unchanged |
| **Transfer roll check** | — | **+0** (same memoised snapshot the other guards use) |
| Bulk roll assign (500) | — | 1 roster read + batched writes |
| Admission / import / promotion | memoised | unchanged |

At 500 / 1,000 / 5,000 students the guards remain **O(1) reads** because they share one
write-invalidated snapshot. **No N+1 introduced by any proposal.**

## N. CONCURRENCY — honest classification

| Operation | Class |
|---|---|
| Roll "Suggest" | **ATOMIC** (`rollNoClaims` create-if-not-exists) |
| Manual roll entry | **DETECTION-GRADE** |
| Section capacity (all paths) | **DETECTION-GRADE** |
| Promotion / transfer | **DETECTION-GRADE** |

**A shared atomic seat/roll claim primitive is justified on evidence but NOT yet necessary** — the
failure mode is one seat or one duplicate under genuine simultaneity, self-correcting on the next
attempt. **Do not introduce distributed locking.** If built later, build it **once** and share it
across capacity + roll + promotion + transfer rather than per-path.

## O. SECURITY / RBAC

| Operation | Gate | Verdict |
|---|---|---|
| Admission / import / promotion / edit | `SIS` | ✅ consistent |
| Delete student | `SIS` | ✅ |
| **Section transfer** | **`Configuration:manage`** | ⚠ **CS-3 — inconsistent** |

Transfer moves students but is governed by the *Configuration* module. A Configuration manager with
no SIS rights can relocate children between sections. Every other placement operation is SIS-gated.

## P. FIRESTORE / RTDB

**The live Class/Section/Roll path is Firestore-only** — verified. `transfer_students` even carries
the note *"RTDB mirror removed per no-RTDB policy."* ✅
**No rules or indexes change is required** by anything proposed here: no new collection, no new query
predicate. `rollNoClaims` is already denied by the catch-all.

## Q. FINDINGS CLASSIFIED

| ID | Finding | Severity | Class |
|---|---|---|---|
| **CS-1** | Section transfer creates duplicate rolls | 🔴 **HIGH** | **FIX NOW** |
| **CS-2** | Section transfer has no audit trail | 🟡 MEDIUM | **FIX NOW** (same method) |
| **CS-3** | Transfer gated on Configuration, not SIS | 🟡 MEDIUM | **BUSINESS DECISION** (D16) |
| CAP-1 | Import preview lacks capacity projection | 🟢 LOW | FIX THIS PHASE |
| CAP-2 | Capacity is detection-grade | 🟡 MEDIUM | FUTURE (shared primitive) |
| ROLL-1 | No bulk roll assignment screen | 🟡 MEDIUM | **FIX THIS PHASE** (SOURCE-VERIFIED) |
| ROLL-2 | CRM enrolment assigns no roll | 🟡 MEDIUM | Resolved by ROLL-1 |
| ROLL-3 | Duplicate-roll survey not run | 🟡 MEDIUM | **PRODUCTION AUTH** (D5) |
| ROLL-4 | Promotion roll carry-vs-clear policy | 🟡 MEDIUM | **BUSINESS DECISION** (D4) |
| SEC-1 | Full-section override absent | 🟡 MEDIUM | **BUSINESS DECISION** (D3) |
| EXAM-1 | No separate Exam Roll Number | 🟢 LOW | FUTURE |
| AI-1 | AI placement | ⚪ | **DO NOT BUILD** |
| CLASS-1 | Auto class from DOB | ⚪ | **DO NOT BUILD** |

## R. EXACT IMPLEMENTATION PLAN (awaiting your authorization)

**Release 1 — Transfer correctness (CS-1 + CS-2). The connected chain; ship together.**
- `Classes::transfer_students()`: call the shared `_rollNoConflict()` per student **before** any
  write, reject the batch with concrete names, and add `_log_history` on success.
- *Why this location:* it is the only unguarded placement path; the validator already exists and
  shares the memoised snapshot, so cost is **+0 reads**.
- Rollback: pure guard + log addition; removing it restores today's behaviour.
- Tests: duplicate blocked · non-duplicate allowed · batch partial-clash rejected · capacity still
  enforced · history written · crossed identity fixtures.

**Release 2 — CAP-1 import capacity preview.** Reuses the existing validator, +0 reads.

**Release 3 — ROLL-1 bulk roll assignment** (closes ROLL-2). Ordering options Manual / Name A-Z /
Admission No / Existing Roll, with `mb_`-safe stable sorting, preview, duplicate validation, batched
atomic-claim save, audit.

**Release 4 — after decisions:** SEC-1 override + audit (D3) · ROLL-4 promotion policy (D4) ·
ROLL-3 survey (D5) · CS-3 RBAC realignment (D16).

**Not now:** CAP-2 shared atomic primitive · Exam Roll Number · AI placement · auto class assignment.

---

## DEFERRED WORK REGISTER (carried forward — nothing lost)

| ID | Item | Sev | Location | Why deferred | Workstream | Prerequisite | Acceptance condition |
|---|---|---|---|---|---|---|---|
| **FEES-D7** | Historical due-date backfill | 🟡 MED | `Fee_late_fee_service::planDueDateBackfill` | Production data + no credential | Fees / data | **Your authorization** | Dry-run reviewed; ambiguous rows never guessed |
| **FEES-D9** | Installments | ⚪ | absent | Greenfield; Gujarat Rule 7(7) constrains | Future feature | Design phase | Own phase |
| **FEES-D13** | Transport/Hostel late-fee policy | 🟡 MED | frozen surface | Business policy | Fees / entitlements | **Your decision** | Per-module opt-in, default OFF, suspension/cancellation/exemption aware |
| **FEES-D14** | SMS/WhatsApp/email provider | 🟡 MED | `Fee_management::send_reminder` | External provider | Notifications | **Provider choice** | Real delivery; no speculative abstraction |
| **FEES-D15** | `firestore_bulk_sync` false success + RTDB N+1 | 🟡 MED | `Fee_management.php:4913`, `Fee_firestore_sync.php:704` | Spans Fees+Homework+Accounting | **RTDB elimination** | Your call: retire / neutralise / defer | Endpoint no longer reports false success |
| **FEES-FROZEN-1** | `createModuleFee` fine-erasing balance | 🟡 MED | `Fee_lifecycle.php:1043` | Frozen surface; unreachable today | Entitlements cutover | Unfreeze | Module fee + posted fine → re-run → `balance == net + fine − paid`, `fine_amount` unchanged |
| **FEES-UAT** | Full Fees UAT (31 rows) | 🔴 | — | Never executed | UAT | Test environment | Matrix executed, especially override / receipt / paid-before-reminder race |
| **CS-3** | Transfer RBAC on Configuration | 🟡 MED | `Classes.php:525` | Permission-model decision | This phase, R4 | **D16** | Transfer gated consistently with other placement ops |
| **CAP-2** | Atomic seat/roll primitive | 🟡 MED | shared | Should be built once, shared | Future | — | One primitive across capacity+roll+promotion+transfer |
| **ROLL-3** | Duplicate-roll survey | 🟡 MED | — | Production data | This phase, R4 | **D5** | Read-only survey run; no auto-renumber |

---

## FINAL ANSWERS TO YOUR TEN QUESTIONS

**1. Already correct:** capacity on all five paths · promotion roll pre-check · identity separation ·
alphanumeric-safe roll comparison · atomic Suggest · session/tenant scoping · Firestore-only ·
promotion cache invalidation · no unknown student-creation path.

**2. Broken:** **CS-1** duplicate rolls via transfer · **CS-2** no transfer audit · **CS-3** RBAC
mismatch · CAP-1 preview · ROLL-1/2 no bulk tool, CRM sets no roll.

**3. Severity:** CS-1 🔴 HIGH · CS-2/CS-3/CAP-2/ROLL-1/2/3/4/SEC-1 🟡 MEDIUM · CAP-1/EXAM-1 🟢 LOW.

**4. Fix NOW:** **CS-1 + CS-2** — same method, same chain, +0 reads.

**5. Same lifecycle phase:** CAP-1 · ROLL-1 (closing ROLL-2).

**6. Another workstream:** FEES-D15 → RTDB elimination · FEES-FROZEN-1 → entitlements cutover ·
CAP-2 → shared-primitive project.

**7. Your business decision:** **D3** override authority · **D4** promotion roll carry-vs-clear ·
**D13** module-fee penalties · **D16 (new)** should transfer be SIS-gated?

**8. Production authorization:** **D5** duplicate-roll survey · **FEES-D7** due-date backfill.

**9. Genuinely future:** Exam Roll Number · configurable roll policy · waitlist · atomic primitive ·
installments.

**10. Sequence:** **R1 CS-1+CS-2 → R2 CAP-1 → R3 ROLL-1 → R4 decisions (D3/D4/D5/D16).**

---

## WHAT THIS MEANS FOR A REAL SCHOOL

**The good news: you cannot overfill a section from any route.** I re-checked all five ways a student
can be placed — admission, import, the CRM, promotion and transfer — and every one is enforced on the
server, so a stale browser can't sneak a 41st child into a 40-seat class.

**The problem is roll numbers, and it's the exact thing you told me you didn't want.**

When a student is moved from one section to another, ZenX carries their roll number with them and
**never checks whether that number is already taken in the new section.** Move Rahul with Roll 18 from
5-A into 5-B, where Priya is already Roll 18, and 5-B now has two Roll 18s. Every other route —
admission, import, editing, even promotion — does check. Transfer is the one that doesn't.

Nothing crashes, and no money or records are corrupted, because the system identifies students
internally by a different number. But your attendance registers and mark sheets are ordered by roll,
so two children sit at the same position on the list, and nobody gets told.

**Two smaller things on that same screen:** moving a student leaves no record of who moved them or
when — promotion records this, transfer doesn't. And the permission to move students belongs to
"Configuration" rather than to student records, so someone who administers school settings can
relocate children between sections even without student-management rights. That last one is a policy
question for you, not something I should decide.

**One thing I want to be straight about:** at one point this trace made it look like promotion had the
same hole. It doesn't — it checks, just in a different way than I searched for. I'd rather tell you I
double-checked than let a wrong alarm stand.

**What I'd do next, in order:** fix the transfer check and add the missing record of the move — small,
safe, and it closes the last real hole in roll numbers. Then show capacity in the import preview so
operators aren't surprised after uploading. Then build the "assign roll numbers for a class" screen
that other school systems have — it's the practical way to tidy up a class after promotion, and right
now the only alternative is editing students one at a time.

**Nothing has been changed. Awaiting your go-ahead.**
