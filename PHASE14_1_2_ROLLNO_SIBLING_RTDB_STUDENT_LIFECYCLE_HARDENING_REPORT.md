# Phase 14.1 / 14.2 — Roll Number, Sibling Index, RTDB Removal

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924` · **Type: IMPLEMENTATION**

**Nothing committed, staged, pushed, merged, rebased or deployed. No Firestore rules or indexes
touched. No production data read or written. No test account created. No RTDB introduced.**

---

## 1. Executive Summary

Three lifecycle correctness defects are closed, plus two more found while implementing them.

| ID | Defect | Status |
|---|---|---|
| **Roll uniqueness** | Two active students could hold the same Roll Number in one class/section | **FIXED** — server-side, 4 write paths |
| **Promotion roll collision** | `batchMoveStudents()` never writes `rollNo`, so promoted students carry old rolls forward and silently collide | **FIXED** — all-or-nothing pre-flight |
| **P14-1** | `indexPhones` was 1:1, so re-importing a sibling file created duplicate students | **FIXED** — multi-value index |
| **P14-1b [NEW]** | Hard-deleting one sibling deleted the whole phone entry, de-registering the rest of the family | **FIXED** — set-removal, document deleted only when empty |
| **P14-2** | Admission/import wrote 3 RTDB nodes per student | **FIXED** — removed, no fallback |

**You were right to push back on my Phase 14 Roll Number conclusion.** I had answered *"does anything
break technically?"* — nothing does, because every module keys on `studentId`. But that is the wrong
question. A class register with two Roll 18s is wrong on its own terms, and the ERP owns roster
correctness. That gap is now closed.

**Two things I caught mid-implementation that would otherwise have shipped broken:**

1. **My promotion check was initially inert.** `_get_students_in_class()` projects only
   `user_id/name/class/section` — **no `rollNo`** — so the collision check I first wrote would have
   read blank for every student and never fired. Fixed by adding `rollNo` to that projection (free —
   the source document is already loaded).
2. **My first RTDB-removal justification was factually wrong.** I wrote "no consumer exists." A
   reader sweep found **two**: `Health_check.php:417-440` and `Superadmin_migration.php:313-339`. The
   removal is still correct — but for a different, verifiable reason (§15) — and the in-code comment
   now states the real one.

**Verdict: READY WITH CONDITIONS** (§31) — implementation and tests complete; **no browser or device
UAT executed**, and pre-existing duplicate Roll Numbers in live data could not be surveyed (§5).

## 2. What Phase 14 Originally Found

| Finding | This phase |
|---|---|
| P14-1 sibling re-import duplicates | **FIXED** |
| P14-2 RTDB in admission path | **FIXED** |
| Roll uniqueness "no defect" | **CONCLUSION OVERTURNED — now enforced** |
| Certificates / LMS RTDB | untouched (out of scope) |
| Parent `findSiblings` full scan | untouched (latency, not correctness) |

## 3. Roll Number Investigation

**Write paths (authoritative student document) — 4 found, all now validated:**

| Path | Location | Roll source | Validated |
|---|---|---|---|
| Direct admission | `Sis::save_admission()` | `post('roll_no')` | **✔** |
| Bulk import | `Sis::_runStudentImport()` | `$rowData['Roll No']` | **✔** (in-file + vs-DB) |
| Student edit | `Sis::edit_student()` | `post` → `$updateData['Roll No']` | **✔** (self-update excepted) |
| Promotion | `Student_write_service::batchMoveStudents()` | **never written — carried forward** | **✔** (pre-flight) |
| CRM enrolment | `Sis::enroll_student()` | **does not set a roll** | n/a |

**Consumers — all display/sort only, none keys on roll:** Attendance, Marks, Results, Report Card,
Homework, Red Flags, PTM, Transport, Hostel, Library, Communication, student lists, profile, Parent
App, Teacher App, PDF, ID Card, Certificates, TC, import/export. Verified by sweep for roll-keyed
structures (`[$rollNo] =`, `byRoll`, `rollNo => studentId`) — **only field-mapping and display sites**.

**Answers to §5's ten questions:**

1. Stored on the student document as `rollNo` (legacy alias `Roll No`).
2. Authoritative class/section: `students.className` / `students.section` (canonical `Class 5th` / `Section A`).
3. Session: `students.session` (singular canonical).
4. Status: `students.status`, `Active` for a live roster position.
5. Production duplicates: **UNKNOWN — environment blocked** (§5).
6. Existing validation: **none anywhere** before this phase.
7. Silent overwrite of another student's roll: **no** — each student owns their own field.
8. Promotion: **carries the old roll forward** (never writes `rollNo`) → this was the silent collision source.
9. Section move: creates a collision if the destination already uses the roll → now blocked.
10. Imports: could create collisions both in-file and against existing students → now blocked.

## 4. Roll Number Business Rule — implemented exactly as specified

**Uniqueness scope: School + Academic Session + Class + Section + Roll Number.**

| Case | Result |
|---|---|
| A / 2026-27 / 5 / A / 18 **and** A / 2026-27 / 5 / **B** / 18 | **ALLOWED** |
| A / 2026-27 / **6** / A / 18 | **ALLOWED** |
| **B** / 2026-27 / 5 / A / 18 | **ALLOWED** |
| A / **2027-28** / 5 / A / 18 | **ALLOWED** |
| A / 2026-27 / 5 / A / 18 twice, both **active** | **REJECTED** |
| Same student re-saving their own 18 | **ALLOWED** (self-update exception) |
| A **withdrawn** student holding 18 | **does not block** a live assignment |
| Blank roll | **never conflicts**, never fabricated |

Roll Number remains free text (`A12`, `B-07`, `18A` preserved verbatim), is **never** a document key,
and is **never** a global or school-wide identity.

## 5. Existing Production Duplicate Analysis — **ENVIRONMENT BLOCKED**

**I could not survey live data, and I did not guess.** No service-account credential is available:
no `*service*/adminsdk*.json` in the repo, and `.env` defines no `GOOGLE_APPLICATION_CREDENTIALS` or
equivalent. The `scripts/` Node tooling requires `firebase-admin` credentials that are, by project
policy, not in git.

**Consequence, and how the design absorbs it:** enforcement is **forward-looking only**. The
validator reports a conflict on a *new* assignment; it never renumbers, moves, blanks or rewrites
anybody. A school that already contains duplicate Roll 18s keeps them until an operator resolves them
deliberately. **No production data was read or written.**

**Remediation plan (NOT executed, needs authorization):** a read-only script grouping active students
by `schoolId|session|className|section|rollNo`, reporting `count > 1` groups with student names and
IDs, for operator review. Categorise active/active, active/inactive, cross-session and cross-section
before proposing any renumbering. **No automatic renumbering — ever.**

## 6. Roll Number Implementation

**One canonical validator**, `MY_Controller::_rollNoConflict()`, used by every path — no per-module
copies.

```php
protected function _rollNoConflict(
    string $rollNo, string $classKey, string $sectionKey,
    string $session, string $exceptStudentId = ''
): ?array
```

**Why a cached snapshot rather than a query (§18 required proof):** a direct query needs five
equality filters (`schoolId + className + section + session + rollNo`). Whether Firestore serves that
without a new composite index **cannot be proven in this environment**, and the project rule is not
to add indexes blindly. So the validator reuses `students_raw` — the snapshot the SIS dashboard, ID
cards, enrolled-id lookups and the fee search already share, which
`Firestore_service::_bustDashboard()` deletes on **any** students write. It therefore cannot
authorise a roll against stale data.

- **Cache hit → 0 reads. Cache miss → 1 collection read**, already paid by most admin pages.
- **No new index. No N+1** — one in-memory filter pass, never a read per candidate.
- Per-request memo, so several validations in one request share a single snapshot.

## 7. Admission Validation

`save_admission()` rejects **before any write** — no Student ID burned, no SRNO claimed, no document
created:

> `Roll Number 18 is already assigned to another active student (Rahul Sharma) in Class 5-A. Choose a different Roll Number.`

## 8. Promotion Validation — the silent collision, closed

**The defect:** `batchMoveStudents()` (`Student_write_service.php:394-405`) writes `className`,
`section`, `sectionKey`, `status`, `session` — and **not `rollNo`**. Every promoted student keeps
their old roll. Promote 5-A (Rahul, 18) and 5-B (Priya, 18) into 6-A and the destination silently
ends with two Roll 18s.

**The fix:** an all-or-nothing pre-flight in `execute_promotion()`, placed beside the existing
capacity and fee-structure guards — the established shape for this endpoint. It checks **both**
collision sources:

- **(a) students already in the destination** — reuses the `$existingStudents` list the capacity
  guard already fetched (**0 extra reads** when a `maxStrength` is configured), else one scoped query;
- **(b) two students *within* the batch** holding the same roll — the merge-two-sections case, which
  no per-student check would catch.

**Deliberately NOT auto-renumbered and NOT silently blanked** — choosing who keeps Roll 18 is the
operator's decision, and inventing one here is exactly the silent conflict resolution §12 forbids.
No student is moved when a clash is found.

> **A mistake caught before it shipped:** my first version read `$sInfo['rollNo']`, but
> `_get_students_in_class()` projects only `user_id/name/class/section`. The check would have been
> **permanently inert** — worse than absent. `rollNo` was added to that projection (the source
> document is already loaded, so **0 extra reads**).

## 9. Class Transfer Validation

The edit form **preserves** Class/Section from the existing record
(`"Class" => $existing["Class"]`), so a transfer cannot occur there; the student's current
class/section is the correct validation scope. Section-to-section movement happens through promotion,
which is covered by §8.

## 10. Re-admission Validation

A re-admitted student is created through the normal admission path, so `save_admission()`'s check
applies: if the requested roll is taken in that class/section, the admission is **rejected before any
write** with the same message. **No silent conflict resolution was invented** — the operator picks a
free roll, or leaves it blank and assigns later (both explicitly permitted by §12). SRNO preservation
and the new Admission Number are unchanged.

## 11. Import Validation

Two collision sources, both rejected **before** the student is created — so no Student ID is burned
and no SRNO is claimed:

- **(a) an earlier row in the same file** — tracked in `$seenRolls`, keyed `class|section|roll`,
  because rows created during this run are not yet in the snapshot;
- **(b) an existing active student** — via the shared validator.

**Per-row skip**, matching this loop's established failure shape (increment `$error`, record a reason
in `$skipped`, continue). **No new partial-import policy was invented** — the existing one is
preserved. Rows in a *different* section keep their own Roll 18.

## 12. P14-1 — Sibling Index Fix

**Root cause:** `indexPhones/{schoolId}_{phone}` held one `userId`, written `set(..., merge = false)`.
A family shares a phone, so importing Rahul then Priya left the document pointing only at Priya.
Re-import then resolved Rahul's row to Priya's id, the Phase 13 name corroboration correctly said
"different person", and a **second Rahul** was created.

**New shape — backward compatible, no migration:**

```
{ phone, schoolId, type, userId: <first registrant>, userIds: [ STU0012, STU0047, … ] }
```

`userId` is **retained** as the first registrant so every existing reader (Staff pre-owner checks,
legacy lookups) works unchanged. A legacy single-value document reads as a one-element set — **no
backfill required**.

Two shared helpers, and **all four write sites now route through them**: `save_admission` (`:918`),
import create (`:5121`), import update (`:5520`), CRM enrolment (`:8852`). Zero `set('indexPhones'…)`
calls remain outside the helpers.

The dedup rule now name-corroborates **every** candidate registered against the phone, not just
whichever was written last.

**This is NOT a sibling graph** — it records "these students share a contact number", nothing more.
No relationship, no guardian entity, no ordering.

### P14-1b [NEW, found while implementing]

Hard delete did `remove('indexPhones', …)` — deleting the **whole** document. With a multi-value
index that would de-register every sibling of the deleted student, silently breaking duplicate
detection for the rest of the family. `_deregisterPhoneIndex()` now removes only that student and
deletes the document **only when the last one leaves**.

## 13. Idempotency

| Operation | Behaviour |
|---|---|
| Re-registering the same student | no-op (set membership) |
| Re-importing a sibling file | each row matches its **own** student; **no duplicates** |
| Same student re-imported | still deduped (phone + name) |
| Case / whitespace variants | normalised, still deduped |
| De-registering an absent id | no-op |
| Missing document | treated as empty set |

## 14. Concurrency

`_registerPhoneIndex()` is read-then-write, **not** atomic — two simultaneous admissions on the same
phone could interleave and lose one id. Stated plainly rather than glossed:

- **Why not fixed here:** no atomic array-union primitive is exposed on this path, and adding a
  transaction/CAS layer to the phone index is a larger change than this phase authorises.
- **Blast radius:** a lost id degrades duplicate *detection* for that sibling on a later import — it
  never corrupts a student record and never loses a student. The previous behaviour was strictly
  worse (it overwrote unconditionally, every time).
- **Realistic exposure:** two siblings admitted in the same second by two operators.
- **Recorded as a known limitation** (§29), not silently ignored.

Roll validation has the same read-then-write shape and the same reasoning: two operators assigning
Roll 18 in the same instant could both pass. Detection-grade, not lock-grade.

## 15. P14-2 — RTDB Removal

Removed from `Sis::_runStudentImport()`:

```php
$this->CM->addKey_pair_data("Schools/{$school_name}/Phone_Index/", [$phone => $studentId]);
$this->CM->addKey_pair_data('Exits/', [$phone => $school_id]);
$this->CM->addKey_pair_data('User_ids_pno/', [$phone => $studentId]);
```

`Common_model::addKey_pair_data()` calls `firebaseDatabase->getReference()->update()` — live RTDB.
**Zero `addKey_pair_data` calls remain in `Sis.php`.**

**Reader sweep — two readers exist, and neither depends on these writes continuing:**

1. **`Health_check.php:417-440`** verifies `Phone_Index`/`Exits` against RTDB profiles it enumerates
   from `Users/Parents/{school_id}`. New admissions have not written RTDB profiles since the no-RTDB
   migration, so a newly-admitted student **never appears in that enumeration** and is never checked.
   The check cannot start failing because of this removal; for a Firestore-era school it already
   short-circuits with "No profiles to check".
2. **`Superadmin_migration.php:313-339`** is a one-time backfill that **reads** the pre-existing
   legacy nodes to copy them into tenant-scoped paths. It operates on historical data.

**No fallback, mirror or dual-write replaces them.** `Health_check` was deliberately left untouched —
it is an RTDB-era check whose retirement belongs with the wider RTDB backlog, not this phase.

## 16. Indirect RTDB Sweep

`addKey_pair_data` call sites repo-wide: **`Staff.php` ×4, `Schools.php` ×1** remain (staff/school
paths, out of this phase's student-lifecycle scope). `Sis.php` is now **clean**.

Other known RTDB, unchanged: `Certificates.php` (24), `Lms.php` (68). `Homework.php` and
`Operations_accounting.php` are Firestore-only.

## 17–19. SRNO / Student ID / Admission Number Verification

| Identifier | Status |
|---|---|
| **SRNO** | school-scoped, auto-allocated, resolves to the CURRENT Student ID, **never a foreign key** — unchanged |
| **Student ID** | canonical persisted key in every module — unchanged; **zero `srNo` writes as a key** |
| **Admission Number** | system-generated, independent, no operator-entry path — unchanged |

Roll validation touches none of them. The crossed-pair invariant (`STU0012/SRNO 47`,
`STU0047/SRNO 12`) remains test-enforced across three suites.

## 20–23. Parent App · Teacher App · Fees · Receipt

**No changes.** Parent and Teacher repositories are untouched this phase (dirty counts unchanged at
23 and 31). Fees, the receipt and its SRNO display are unchanged from Phase 13. Roll Number rendering
remains safe end-to-end (`A12` verbatim, blank → `—`, never `-2147483648`/`0`).

## 24. Cross-Module Lifecycle Verification

Every module still keys on `studentId` — re-verified by sweep. Roll Number remains display/search/
sorting data only; SRNO remains human-lookup only, resolving to Student ID before persistence.

Fees · Attendance · Marks · Results · Homework · Red Flags · PTM · Transport · Hostel · Library ·
Communication · Documents · Certificates · TC — **all unchanged, all `studentId`-keyed.**

## 25. Performance / Latency

| Workflow | Reads before | Reads after | Writes before | after | Seq RTT | N+1 | New index |
|---|---:|---:|---:|---:|---:|---|---|
| Admission — roll check | 0 | **0** (cache hit) / 1 (miss) | n | n | +0/+1 | no | **no** |
| Edit — roll check | 0 | **0 / 1** | n | n | +0/+1 | no | **no** |
| Import — roll check per row | 0 | **0** (memoised after first) | n | n | 0 | **no** | **no** |
| Promotion — roll pre-flight | 0 | **0** (reuses capacity fetch) | n | n | 0 | no | **no** |
| Phone index write | 0 | **+1** (read to append) | 1 | 1 | +1 | no | no |
| Phone dedup lookup | 1 | 1 + up to 1/candidate | 0 | 0 | — | no | no |
| Hard delete phone entry | 0 | **+1** | 1 | 1 | +1 | no | no |
| **RTDB writes per admission** | **3** | **0** | — | — | **−3** | — | — |

**Net:** the roll checks are effectively free (shared, write-invalidated, memoised snapshot). The
phone index costs +1 read per registration — the unavoidable price of appending without evicting —
and that is more than offset by removing three RTDB round trips per admission.

**No N+1 introduced.** The import roll check memoises the snapshot for the whole run; the phone
candidate loop is bounded by siblings-per-phone (realistically 2–4) and only fires on a phone
collision.

## 26. Firestore Rules / Indexes — **NO CHANGE**

Required, and confirmed: no new collection; `indexPhones` gains an ordinary array field on an
existing document that nothing filters or orders by; the roll validator adds **no query at all**.

`firestore.rules` mtime **2026-08-13**; `firestore.indexes.json` mtime **2026-08-12** — untouched.
`integration/phase2` still has **no remote counterpart** (8 remote heads, none matching, no upstream),
so no pull/reconcile was possible or invented.

## 27. Tests

`tests/Unit/Phase141RollNoAndSiblingIndexTest.php` — **20 tests, 39 assertions, all pass.**

| §21 ref | Coverage |
|---|---|
| 1 | same class/section/session + same roll → **REJECT** |
| 2–5 | different section / class / session / school → **ALLOW** |
| 6 | self-update → **ALLOW** |
| 7 | alphanumeric roll allowed **and** enforced (case-insensitive) |
| 8 | blank roll never conflicts, never blocks |
| — | withdrawn student does not reserve a roll |
| 9–10 | promotion into an occupied roll **and** two batch students colliding with each other |
| 11 | import: same-section duplicate rejected, other section unaffected |
| 18–21 | siblings both registered; third sibling; idempotent; legacy single-value upgraded losslessly |
| — | delete one sibling → others survive; delete last → document removed |
| 19 | **re-import creates no duplicates** (the P14-1 regression guard) |
| 13–17 | Roll ≠ SRNO ≠ Student ID; crossed pairs asserted |

**Full suite: 772 tests, 2446 assertions, 10 failures — exactly the pre-existing baseline. Zero new
failures.** (752 → 772 = +20, all mine, all passing.)

**Coverage limits, stated plainly:** `_rollNoConflict`, `_registerPhoneIndex` and
`_deregisterPhoneIndex` live on CodeIgniter controllers that cannot be bootstrapped here. The tests
model the **decision rules exactly as implemented** and guard the properties that would silently
regress; the controller wiring is covered by the UAT matrix, not by these tests. §21 rows 23–25 (no
RTDB on admission paths) are verified **statically** — zero `addKey_pair_data` in `Sis.php` — not by
execution.

## 28. UAT — **NOTHING EXECUTED**

**No row may be marked PASS on source inspection.**

| # | Test | Status |
|---|---|---|
| 1 | Admission auto-assigns SRNO | **NOT RUN** |
| 2 | Admission auto-assigns Admission Number | **NOT RUN** |
| 3 | **Duplicate Roll rejected in same class/section** | **NOT RUN** |
| 4 | **Same Roll allowed in a different section** | **NOT RUN** |
| 5 | **Promotion blocked on destination Roll collision** | **NOT RUN** |
| 6 | Re-admission Roll collision handled | **NOT RUN** |
| 7 | **Sibling import — both created** | **NOT RUN** |
| 8 | **Same file re-imported — no duplicates** (P14-1 guard) | **NOT RUN** |
| 9–10 | Fee search / collection by SRNO | **NOT RUN** |
| 11–12 | Receipt shows SR No.; hides Student ID | **NOT RUN** |
| 13–16 | Teacher marks / attendance / homework / PTM | **NOT RUN** |
| 17 | Parent child switcher | **NOT RUN** |
| 18 | Cross-school SRNO | **NOT RUN** |
| 19 | Edit a student without changing Roll (self-update) | **NOT RUN** |
| 20 | Hard-delete one sibling — other stays detectable | **NOT RUN** |
| 21 | Admission on a school with **pre-existing** duplicate rolls still works | **NOT RUN** |

**Rows 3, 5, 7, 8, 19, 20 are load-bearing** — they exercise everything new in this phase.

## 29. Remaining Issues

| Class | Item |
|---|---|
| **D — production data** | **Pre-existing duplicate Roll Numbers are unsurveyed** (§5). Read-only analysis is credential-blocked. Enforcement is forward-only and safe against them. |
| **B — safe to defer** | Phone-index and roll checks are read-then-write, not atomic (§14). Detection-grade; strictly better than before. |
| **B** | `Health_check.php` still verifies retired RTDB nodes — harmless (never sees new students); belongs with the RTDB backlog. |
| **B** | `addKey_pair_data` remains in `Staff.php` ×4, `Schools.php` ×1 — outside student lifecycle. |
| **B** | Certificates (24) / LMS (68) RTDB — blocks SRNO there; unchanged. |
| **B** | Parent `findSiblings` full-collection scan — latency, not correctness. |
| **C — business decision** | Remediating existing duplicate rolls: who keeps the number? No automatic renumbering was built. |

## 30. Git Status

```
Branch: integration/phase2   HEAD: 758c924   (unchanged — nothing committed)
Remote: integration/phase2 does NOT exist on origin; no upstream.

 M application/core/MY_Controller.php          (roll validator + message helper)
 M application/controllers/Sis.php             (4 validation sites, phone-index helpers, RTDB removal)
?? tests/Unit/Phase141RollNoAndSiblingIndexTest.php
?? PHASE14_1_2_ROLLNO_SIBLING_RTDB_STUDENT_LIFECYCLE_HARDENING_REPORT.md

Teacher App  31 dirty  (UNCHANGED — not touched)
Parent App   23 dirty  (UNCHANGED — not touched)

firestore.rules        mtime 2026-08-13  — untouched
firestore.indexes.json mtime 2026-08-12  — untouched
```

**Two source files changed, both Admin Panel.** No mobile changes. No unrelated files. No production
writes. No deployment, commit, push, merge or rebase.

## 31. Final Verdict

# READY WITH CONDITIONS

**Not blocked, and none of §25's disqualifiers apply:** Roll Number uniqueness is enforced; P14-1
sibling re-import is fixed; the admission/import RTDB writes are gone; no new lifecycle defect
remains unfixed; and the regression suite is at its exact baseline with zero new failures.

**Conditions:**

1. **No browser or device UAT was executed.** Six load-bearing rows (§28: 3, 5, 7, 8, 19, 20) exercise
   behaviour nobody has yet watched run — including a *rejection* path, which is the kind that only
   proves itself in a real form.
2. **Pre-existing duplicate Roll Numbers are unsurveyed** (§5, credential-blocked). Enforcement is
   forward-only, so they cannot break anything — but you do not yet know how many exist.
3. **Concurrency is detection-grade, not lock-grade** (§14) — documented, strictly better than before.

**Decision required:** authorize the read-only duplicate-roll survey script (§5), so the size of the
existing backlog is known before anyone tries to clean it up. I did not write or run it — it touches
production data.

---

# WHAT THIS MEANS FOR A REAL SCHOOL

**The register is now trustworthy.** Before today, an operator could type Roll 18 for Priya when
Rahul already had Roll 18 in Class 5-A, and ZenX would accept it. The class register then had two
number 18s. Nothing crashed — every internal record still pointed at the right child — but the
register itself was wrong, and a register is the thing a teacher actually reads aloud.

Now ZenX refuses, and says why:

> Roll Number 18 is already assigned to another active student (Rahul Sharma) in Class 5-A. Choose a different Roll Number.

**What is still allowed** — because these are genuinely different rosters: Roll 18 in 5-A *and* 5-B.
Roll 18 in Class 5 *and* Class 6. Roll 18 this year *and* next year. Roll 18 in your school *and*
another school. And a student saving their own profile keeps their own Roll 18 without complaint.

**Promotion had a quiet version of the same bug.** When you promoted a class, ZenX moved the students
but never touched their roll numbers — so promoting 5-A and 5-B into one 6-A produced two Roll 18s
with nobody noticing. Now the promotion stops before moving anyone and names the clashes, so you
decide who keeps which number. It does not renumber anyone for you — that is your call, not the
software's.

**Siblings import properly now, in both directions.** Rahul and Priya share their father's phone. The
first fix (last phase) let both be created. But re-running the same spreadsheet — something people do
all the time after a correction — created a *second* Rahul, because the phone index could only
remember one child per number. It now remembers all of them. Re-import as many times as you like; you
get the same two children.

And deleting one child no longer makes the system forget the others share that phone.

**One less legacy system.** Admissions were still writing three entries into the old Realtime
Database that nothing current reads. Those are gone. Nothing replaced them.

**What I could not do:** I could not look at your live data to see how many duplicate roll numbers
already exist, because the credentials for that are not on this machine. So the new rule only applies
going forward — it will never renumber or move a student who is already in the system. If you want to
know the size of the existing backlog, I can write a read-only script that lists them for you to
review, but I have not run anything against production.

**And nobody has watched this work yet.** The code is written, checked and covered by tests, but a
rejection message is exactly the kind of thing that needs to be seen in a real form before you trust
it.

**Nothing has been committed, pushed or deployed.**
