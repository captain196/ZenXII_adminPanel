# Phase 16.1 — Release 1: Section-Transfer Integrity (CS-1, CS-2, D16)

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED. NOT COMMITTED / PUSHED / MERGED / REBASED / DEPLOYED. UAT NOT EXECUTED.**
No production data touched. `firestore.rules` / `firestore.indexes.json` **NOT modified**.
No RTDB introduced. No Class/Section redesign; no bulk roll screen (that is R3).

---

## 1. EXECUTIVE SUMMARY

R1 closes the last hole in the roll-uniqueness guarantee, together with the two defects that sat on
the same method.

| ID | Defect | Status |
|---|---|---|
| **CS-1** 🔴 HIGH | Section transfer carried a roll into the destination with **no validation** → duplicate rolls | ✅ **FIXED** |
| **CS-2** 🟡 MED | Section transfer wrote **no audit trail** | ✅ **FIXED** |
| **D16** 🟡 MED | Transfer gated on `Configuration:manage`, not `SIS` | ✅ **REALIGNED to SIS** (your decision) |

**Tests: 913 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +17 new tests.**
All touched files lint clean. RTDB scan clean.

**One connected defect surfaced during implementation and was fixed rather than worked around.**
The audit fix needed `Sis::_log_history()`, which was **private to the SIS controller** — the precise
reason CS-2 existed at all: any lifecycle event raised outside SIS *could not* be recorded. Copying
the writer into `Classes.php` would have created a second definition of the same audit record, free
to drift. I extracted it into **`Student_history_service`**; `Sis::_log_history` now delegates to it,
so there is exactly one writer. Record shape, doc-id and `histKey` format are **byte-identical**, so
existing rows and `Student_timeline_reader` are unaffected.

---

## 2. WHAT WAS IMPLEMENTED

### CS-1 — roll-collision pre-flight (`Classes::transfer_students`)

Validates **before any write**, so a rejected batch leaves nobody half-moved. Two clash classes:

- **(a) destination clash** — the roll is already held by an Active student in the target
  class/section/session, via the shared `_rollNoConflict()`.
- **(b) in-batch clash** — two students *inside the same transfer* holding the same roll, which no
  destination check could catch.

**Nothing is silently renumbered.** Choosing who keeps Roll 18 is the school's decision; the batch is
rejected with the conflicting names so an operator resolves it deliberately — the same stance
promotion takes.

### CS-2 — audit trail
`SECTION_TRANSFER` history event per student, recording `from_class`, `from_section`, `to_class`,
`to_section`, `session`. **Written only after a confirmed student write**, so history can never claim
a move that did not happen. A failed audit never fails the transfer.

### D16 — RBAC realignment
`Configuration:manage` → **`SIS:manage`**, matching admission, import, promotion, edit and delete.
⚠ **Rollout note:** anyone who performs section transfers today needs SIS `manage`. This is a
deliberate tightening, not a regression.

### Incidental cleanup
Removed four dead RTDB-era variables (`$sessionRoot`, `$parentRoot`, `$toClassNode`,
`$toSectionNode`) left behind when the RTDB mirror was retired. Verified zero remaining references.

## 3. FILES CHANGED

| File | Change |
|---|---|
| `application/controllers/Classes.php` | CS-1 pre-flight · CS-2 audit · D16 gate · dead-var cleanup |
| `application/controllers/Sis.php` | `_log_history` body → delegates to the shared service |
| `application/libraries/Student_history_service.php` | **NEW** — the one `studentHistory` writer |
| `tests/Unit/Phase160SectionTransferIntegrityTest.php` | **NEW** — 17 tests |

## 4. VERIFICATION OF ASSUMPTIONS (checked, not assumed)

| Assumption | Verified |
|---|---|
| `Classes extends MY_Controller` → `_rollNoConflict` reachable | ✅ `Classes.php:4` |
| `_rollNoConflict` signature (5 args, `$exceptStudentId` last) | ✅ `MY_Controller.php:1107-1112` |
| Return keys `studentId` / `name` / `rollNo` | ✅ `MY_Controller.php:~1150` |
| `Firestore_service::get(collection, docId)` needs a FULL docId | ✅ `:310` |
| `docId($id)` → `{schoolId}_{id}` | ✅ `:178` |
| `$this->school_id` exists on `MY_Controller` | ✅ `:33` |
| All 23 existing `_log_history` callers unaffected | ✅ signature unchanged |

## 5. PERFORMANCE

| Operation | Before | After |
|---|---|---|
| Roll pre-flight | — | **+0 reads** for the conflict check — `_rollNoConflict()` reads the same per-request memoised snapshot every other guard uses |
| Student doc fetch | — | **+1 read per student in the batch** (needed to learn their current roll) |
| Capacity check | 1–2 | unchanged |
| Audit | — | +1 write per successfully transferred student |

**Honest note:** the pre-flight reads each batched student's document to obtain their roll — the
transfer POST carries only IDs. For a 40-student transfer that is 40 reads. This is bounded by the
**batch size an operator selected**, not by school size, and section transfers are occasional rather
than routine. **It is not an N+1 over the school**, and it does not grow at 500 / 1,000 / 5,000
students. If bulk transfers ever become large enough to matter, the fix is a chunked `IN` fetch —
noted, not pre-optimised.

## 6. CONCURRENCY — unchanged and honestly stated

The roll pre-flight is **DETECTION-GRADE, not atomic** — identical to admission, import, edit and
promotion. Two simultaneous transfers into the same section could both pass. That limitation is
pre-existing and programme-wide; **CAP-2 (one shared atomic claim primitive across capacity + roll +
promotion + transfer) remains the correct future fix, and R1 does not pretend otherwise.**

## 7. SECURITY / RBAC

| Operation | Gate now |
|---|---|
| Admission / import / promotion / edit / delete | `SIS` |
| **Section transfer** | **`SIS:manage`** ✅ consistent |

Server-side enforced via `_require_role(..., 'SIS', 'manage')`, not UI-hidden. Fails closed.

## 8. CROSS-MODULE

**No cross-module change required.** No module keys off roll — Attendance, Exam/Report Card,
Parent/Teacher apps and Certificates only *display* it, so preventing duplicates strictly improves
them. Fees, Homework, Communication, Transport, Library key on `studentId` and are untouched.
`studentId` remains the sole canonical FK.

## 9. TESTS

```
BASELINE:      798 tests, 2507 assertions, 10 failures
BEFORE R1:     896 tests, 2693 assertions, 10 failures
AFTER R1:      913 tests, 2724 assertions, 10 failures
NEW TESTS:     17      NEW FAILURES: 0
LINT:          clean       RTDB SCAN: clean       RULES/INDEXES: untouched
```
**Not "all tests pass"** — the 10 pre-existing baseline failures remain and are not mine
(Backup ×1, Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2).

Coverage: the exact Rahul/Priya Roll-18 scenario · free roll allowed · in-batch duplicate · partial
clash rejects whole batch · different section / class / session do **not** block · withdrawn student
does not hold a roll · student with no roll transfers freely (CRM case) · self-exclusion ·
alphanumeric `12A` clash · `12A` ≠ `12` (no numeric collapse) · case-insensitive · history shape ·
history only after confirmed write · crossed identity fixtures (`STU0012→SRNO 47→Roll 12`,
`STU0047→SRNO 12→Roll 47`).

## 10. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | Transfer into a section where the roll is free | Succeeds | **UAT PENDING** |
| 2 | **Transfer into a taken roll** | **Blocked, names shown** | **UAT PENDING** |
| 3 | Two students in one batch with the same roll | Blocked | **UAT PENDING** |
| 4 | Partial clash in a batch | **Whole batch rejected, nobody moved** | **UAT PENDING** |
| 5 | Student with no roll (CRM-enrolled) | Transfers freely | **UAT PENDING** |
| 6 | Withdrawn student holding that roll | Does not block | **UAT PENDING** |
| 7 | Alphanumeric roll `12A` | Clashes exactly; `12` does not | **UAT PENDING** |
| 8 | Capacity still enforced on transfer | Rejects over-capacity | **UAT PENDING** |
| 9 | **History row written** | `SECTION_TRANSFER` with both ends | **UAT PENDING** |
| 10 | Student timeline shows the move | Visible in Student 360 | **UAT PENDING** |
| 11 | **SIS-permitted user** can transfer | Succeeds | **UAT PENDING** |
| 12 | **Configuration-only user** | **403 refused** | **UAT PENDING** |
| 13 | Attendance register after transfer | No duplicate roll positions | **UAT PENDING** |
| 14 | Promotion / admission / import regression | Unchanged | **UAT PENDING** |
| 15 | Parent / Teacher app | Correct student, no duplicate | **UAT PENDING** |

**No browser or device UAT has been executed.**

## 11. GIT STATUS

```
Branch: integration/phase2      HEAD: 758c924   (unchanged — no commit)

Modified this release:  application/controllers/Classes.php
                        application/controllers/Sis.php
New this release:       application/libraries/Student_history_service.php
                        tests/Unit/Phase160SectionTransferIntegrityTest.php

Carried uncommitted from 15.5–15.8: Fee_lifecycle · Firestore_service ·
  FeeCollectionService · Fees · Fee_management · MY_Controller ·
  Fee_late_fee_service · Fee_reminder_service · Push_enqueue_service ·
  Fees_late_fee_cron · reminders.php · defaulter_report.php ·
  Phase155/156/157/158 tests

firebase-rules/* .... dirty BEFORE this programme — NOT touched by me
~440 other entries .. pre-existing work — untouched

COMMIT: none   PUSH: none   MERGE/REBASE: none   DEPLOY: none
```

**Commit recommendation:** R1 is a coherent, self-contained unit (transfer integrity + the shared
history writer it required). It is committable on its own once UAT rows 1–12 pass. I have **not**
committed and will not without explicit authorization.

## 12. DEFERRED WORK REGISTER — updated

**Closed this release:** CS-1 · CS-2 · CS-3/D16.

**Still open, unchanged:** CAP-1 (import preview → **R2**) · ROLL-1/ROLL-2 (bulk roll screen →
**R3**) · ROLL-3 (survey, needs **D5**) · ROLL-4 (promotion roll policy, needs **D4**) · SEC-1
(full-section override, needs **D3**) · CAP-2 (atomic primitive, future) · EXAM-1 (future).

**Fees register carried forward intact:** FEES-D7 · FEES-D9 · FEES-D13 · FEES-D14 · FEES-D15 ·
FEES-FROZEN-1 · FEES-UAT.

---

## WHAT THIS MEANS FOR A REAL SCHOOL

**Moving a student between sections can no longer create two children with the same roll number.**

Before this, if you moved Rahul with Roll 18 from 5-A into 5-B, and Priya in 5-B already had Roll 18,
ZenX let it through without a word. Your attendance register and mark sheets would then show two
children at the same position, and nothing would flag it. Every other route already checked — this
was the one gap left.

Now the move is refused, and you're told exactly who clashes with whom, so you can change a roll
number first and try again. **We don't renumber anyone automatically** — deciding who keeps Roll 18 is
your call, not the software's. If several students are moved at once and even one clashes, the whole
batch stops, so you never end up with half a class moved.

**Two other things on that screen are fixed.** Every move is now recorded — who moved the student,
when, and from which section to which — so you can answer "why is this child in 5-B?" months later.
Previously that left no trace at all, even though promotions did.

**And the permission has changed, which you should know before rolling out.** Moving students used to
sit under "Configuration" — school-settings territory — meaning someone with no student-record rights
could relocate children. It now requires student-management rights, matching admissions and
promotions. **Anyone who does transfers today will need SIS permissions granted.** That's a deliberate
tightening.

**Nothing has been committed or deployed, and none of this has been clicked through in a browser yet.**
The 15-row test list above should be run before this reaches a school — particularly the blocked
transfer, the whole-batch rejection, and the permission check.
