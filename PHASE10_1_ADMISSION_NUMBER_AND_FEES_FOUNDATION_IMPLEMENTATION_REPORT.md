# Phase 10.1 — Admission Number Architecture + Fees Foundation

**Date:** 2026-08-13 · **Branch:** `integration/phase2` · **Not committed, not pushed, not staged.**

---

## A. Executive Summary

Implemented the locked Admission Number model: a **distinct, fourth identifier** — an
admission-EVENT identifier in canonical format `YYYY/CLASS/XXX` (e.g. `2026/01/012`) — computed
server-side once at admission, frozen thereafter, never recomputed on promotion. It is explicitly
**not** the SRNO (the previously-recommended "Option B" is rejected and was not implemented).

Wired into all three real admission paths through the single existing shared helper, reusing the
canonical SL-12 class normalization for the CLASS component and the just-allocated SRNO for the XXX
component — zero new Firestore reads, zero new service, zero new collection.

**One genuinely ambiguous business rule was found and deliberately NOT resolved** (§E): what should
happen to Admission Number on re-admission. `Readmission.php` was left untouched, and the resulting
known behavior is documented precisely rather than silently decided.

The Fees workstream (§J-O) is **audit/analysis only in this pass** — no Fees code was written, per
the instruction to trace the due-date architecture before building anything on top of it.

---

## B. Admission Number Architecture

- **Canonical stored field: `Admission Number`** (TitleCase, with a `admissionNumber` camelCase
  dual-emit written alongside it). This is not a new invention — it is the **exact field name
  `Certificates.php` already reads** (`Certificates.php:384,460`), chosen precisely so that
  certificate generation needs **zero changes** and its existing fallback logic starts working
  correctly the moment the field is populated.
- **Legacy aliases confirmed and preserved:** `Student_context.php:446` already reads
  `['admissionNo', 'admission_no', 'Admission Number', 'admissionNumber']` defensively — all four
  variants continue to work; the two this phase writes are both already in that list, so Student 360
  picks it up with no change there either.
- **Pre-existing data:** confirmed by grep that **no writer of any of these field names existed
  anywhere in the codebase** before this phase — every certificate ever generated silently printed
  the Student ID via `Certificates.php`'s `?? $student['User Id']` fallback. **No existing data is
  overwritten**, because there was none.

## C. Student ID / SRNO / Roll Number / Admission Number Separation

| Identifier | Scope | Changes on promotion? | Changes on re-admission? | Source |
|---|---|---|---|---|
| Student ID | **Global** across all ZenX | No | New ID issued; old preserved + linked | `Id_generator::generate('STU')` |
| SRNO | **School-scoped** | No | **Preserved** (relinked) | `Sr_no_service::allocateNext()`/`claim()` |
| Roll Number | **Class + section scoped** | Yes (may) | Independent | Operator-entered, free text |
| **Admission Number** | **Admission-event scoped** | **No — frozen by design** | **See §E — open question** | `Sis::_computeAdmissionNumber()` (new) |

All four are now displayed with unambiguous, distinct labels on the student profile (§ UI below) —
no surface labels SRNO as "Admission Number" or Roll Number as SRNO.

## D. Admission Number Generation

`Sis::_computeAdmissionNumber(string $className, string $srNo): string` — a **pure function, zero
Firestore access**, deriving every component from already-authoritative in-hand server state:

| Component | Source | Decision rationale |
|---|---|---|
| `YYYY` | `substr($this->session_year, 0, 4)` | Session year is the canonical admission-context year every path already has; **not** a client-suppliable date, **not** `date('Y')` (which would be wrong for an admission recorded in a different calendar year than the session). A defensive `date('Y')` fallback exists only for a malformed session, unreachable in every real caller. |
| `CLASS` | `Entity_firestore_sync::normalizeClassSection($className, '')['classOrder']`, zero-padded to 2 | **Not guessed** — this is the SL-12-hardened `CLASS_ORDER_MAP`, the same canonical source every class comparison in this codebase already uses. Accepts every class spelling variant ("1", "1st", "Class 1", "Class 1st") and maps all to `01`. |
| `XXX` | The SRNO just allocated/claimed, digits only, zero-padded to a **minimum** of 3 | Padding is a minimum, never a maximum — a school past 999 students gets `1000`, never a truncated `000`. Unit-tested. |

**Disclosed design decision (not silently invented):** pre-primary classes (LKG/UKG/Nursery/
Playgroup) have **negative** `classOrder` values in the canonical map (they sort before Class 1) and
therefore have no established 2-digit code anywhere in the existing architecture. They fall into an
explicit `00` bucket. This is a real choice made here — flagged, unit-tested, and open to revision
if the business prefers a different code for pre-primary.

**Never client-trusted:** there is no Admission Number input field anywhere in any UI, matching the
same "no manual entry" invariant SRNO already enforces.

## E. Re-Admission Semantics — AMBIGUOUS, NOT RESOLVED, `Readmission.php` UNTOUCHED

Per the explicit instruction ("if the exact behavior on RE-ADMISSION is ambiguous, DO NOT guess"),
this was investigated and deliberately left undecided.

**What the code does today, precisely** (traced, not assumed): `Readmission::confirm()` →
`Sr_no_service::relink()` overwrites the new Student ID's `srNo`/`srNoNorm` with the **preserved old
SRNO**. It does **not** touch `Admission Number`. Therefore a re-admitted student ends up with:

```
Student ID       = STU0047 (new)
SRNO             = 12      (preserved from STU0001)
Admission Number = 2027/02/045   ← computed at STU0047's OWN admission, from the
                                   FRESH SRNO 45 that allocateNext() had assigned
                                   moments earlier — BEFORE relink() replaced it with 12
```
**The Admission Number's XXX component can therefore disagree with the student's final SRNO after a
re-admission.** This is a real, newly-identified consequence, disclosed rather than papered over.

**The three interpretations, with consequences:**
- **(A) Original admission event** — Admission Number should be *inherited from the prior record*
  (STU0001's own `2026/01/012`), making it a true lifetime admission-event identifier for the
  person. Consequence: needs a new write in `Readmission::confirm()` to copy it forward.
- **(B) Current admission event** — Admission Number should be *recomputed* at re-admission using
  the preserved SRNO and the new class/year (`2027/02/012`). Consequence: needs a recompute in
  `Readmission::confirm()`; the number then changes across the person's lifetime, which sits
  uneasily with "must not automatically change merely because the student is promoted."
- **(C) Leave as-is (current behavior)** — the number reflects neither cleanly, and can carry a
  stale SRNO component. **Not recommended**, but it is what the code does today and this pass did
  not unilaterally change it.

**Recommendation: (A)** — it best matches the locked definition ("an admission-event identifier...
tied to the admission event"), and preserves the person-level continuity SRNO/APAAR already have.
**Requires your decision before `Readmission.php` is touched.**

## F. Admission-Path Matrix

| Path | Student ID | SRNO | **Admission Number** | APAAR | Roll No | Class/Section | Session | School source | Validation | Dup protection | Atomicity | Rollback | UI display | Credentials output | Profile output |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Admission CRM (`enroll_student`) | `_nextStudentId()` | auto | **NEW — computed** | optional | separate | classKey/sectionKey | `$this->session_year` | server (`parent_db_key`) | server-side | inherited | inherited | inherited | ✔ | **✔ NEW** | ✔ |
| Direct/walk-in (`save_admission`) | `_nextStudentId()` | auto | **NEW — computed** | optional | `roll_no` POST | classKey/sectionKey | same | server | server-side | inherited | inherited | inherited | ✔ | **✔ NEW** | ✔ |
| Bulk import — new mode | `_nextStudentId()` | auto | **NEW — computed** | optional | free text | classKey/sectionKey | same | server | server-side | in-file + DB | per-row + rollback | ✔ | ✔ | ✖ (see §U) | ✔ |
| Bulk import — historical mode | `_nextStudentId()` | operator-supplied, claimed | **NEW — computed (year caveat, §U)** | optional | free text | classKey/sectionKey | same | server | server-side | in-file + DB | per-row + rollback | ✔ | ✔ | ✖ (see §U) | ✔ |
| Bulk import — legacy exact-template | `_nextStudentId()` | **none** (dead path) | **none** | none | none | classKey/sectionKey | same | server | minimal | phone/name+DOB | per-row | partial | n/a | n/a | n/a |
| Re-admission (`Readmission::confirm`) | unchanged | **preserved** | **UNTOUCHED — see §E** | preserved | unchanged | unchanged | unchanged | server | server-side | inherited | atomic batch | n/a | ✔ | n/a | ✔ |

**No fourth/hidden student-creation path was found** — re-confirmed this pass, same result as
Phase 9.2's independent search.

## G. Cross-Module Impact

| Module | Reads Admission Number? | Change needed |
|---|---|---|
| **Certificates** | **Yes** — `Certificates.php:384,460`, already reads `Admission Number` with a Student ID fallback | **NONE.** By choosing the field name it already reads, new certificates automatically start printing the real Admission Number; historical PDFs are static and unaffected. This is exactly the "historical vs new document behavior" separation required. |
| **Student 360** | **Yes** — `Student_context.php:446` already reads it via a 4-alias defensive `$pick()` | **NONE** — both field names this phase writes are already in its alias list |
| Student Profile | Now yes (added this phase) | Done |
| SIS student list | No | None — but see §U (no SRNO/Admission Number column exists; pre-existing gap) |
| Fees / Attendance / Results / Transport / Communication / HR / Accounting | No — all key off Student ID | **NONE.** Confirmed: no module anywhere uses SRNO or Admission Number as a query/join key. |
| TC / Report cards / Receipts | Not confirmed to read it — **NOT VERIFIED this pass** | Flagged; not changed |

## H. Parent App Audit — DIRECT SOURCE INSPECTION, NOT INFERRED

Read `StudentDoc.kt` in full. Confirmed fields: `userId`, `studentId`, `rollNo`, `className`,
`section`, `classOrder`, `sectionKey`, etc. — **no `srNo`, no `admissionNumber`, no `admissionNo`**.
Grep across the whole Parent App source tree for all four alias spellings: **zero matches**.

The class's own docblock states: *"Adding a new camelCase field here is always safe — missing fields
deserialise to their type's default."* — so the two new Firestore fields cannot break deserialization.

**PARENT APP — NO CODE CHANGE REQUIRED.** Reason: it never reads the field, and cannot be broken by
its addition.

## I. Teacher App Audit — DIRECT SOURCE INSPECTION, NOT INFERRED

Read `StudentDoc.kt` in the Teacher repo. Confirmed: `studentId`, `rollNo` present; **no `srNo`, no
`admissionNumber`**. Grep across the whole source tree for all four aliases: **zero matches**.

**TEACHER APP — NO CODE CHANGE REQUIRED.** Same reason.

## J-O. Fees Foundation — AUDIT/ANALYSIS ONLY, NO CODE WRITTEN

Per the instruction to trace the due-date architecture before building anything, and to not
implement Fees features in this pass. Findings carried forward from the Phase 10 audit,
re-confirmed:

- **§K Due-date analysis — THE ROOT BLOCKER.** `Fee_lifecycle::buildAdmissionDemandSpecs` (the
  admission-time generator every normally-admitted AND bulk-imported student's demands come from)
  **never writes a `dueDate`**. `Fees.php::_generateDemandsForMonth` (the separate, manually-invoked
  bulk generator) **does**. Late-fee calculation (`Fees.php::compute_fines_for_student`) skips any
  demand with an empty due date — so late fees are **structurally unreachable for the majority of
  real demands**. **This must be fixed before any automatic penalty work. Not fixed in this pass.**
- **§L Late-fee analysis.** Real calculation exists, posts correctly to Accounting code `4060`, but
  is manual/per-student only. **Idempotency is UNVERIFIED** — the method appears to overwrite
  `fine_amount` on each call, a real double-charge risk if a scheduler were built on it today.
  **Must be verified/hardened before automation.**
- **§M Parent reminder analysis.** Push channel works end-to-end. `auto_remind`/`days_before_due`
  settings exist with **no consumer** — no scheduler reads them. `FEE_DUE` push mark is registered
  in `functions/index.js:123` but **never emitted** by any PHP code. WhatsApp/SMS/email **queue
  only** — no dispatcher exists in-repo; must not be represented as working.
- **§N ID-card replacement analysis.** Confirmed **entirely absent** — `Sis::id_card()` is
  print-only with no card record, status, numbering, or fee linkage. Recommended architecture
  (unchanged from Phase 10): reuse `Fee_lifecycle::createModuleFee()`'s existing ad-hoc-demand
  pattern + the existing `FeeCollectionService` — never a separate payment system.
- **§O Accounting/RTDB dependency — RE-CONFIRMED AND RESTATED, NOT WORKED AROUND.**
  `Operations_accounting.php` (the only path posting any fee to the Chart of Accounts) is fully
  RTDB-backed (`Operations_accounting.php:16-21`). `Fee_refund_service.php:7-10` independently
  calls it "the only external RTDB touchpoint left." **This pass created no new RTDB path, no
  dual-write, no mirror, and did not route around it** — because no Fees code was written at all.
  **Your decision from Phase 10 §H is still required before any new fee-accounting behavior is
  built.**

## P. Performance / Latency Analysis

For the one thing actually implemented this phase (Admission Number generation):

| Metric | Value |
|---|---|
| **READS** | **0** — pure function; session year is already on `$this`, class is already a parameter, SRNO was just returned by the call above |
| **WRITES** | **0 additional** — the two new fields are merged into the **already-existing** `$this->fs->update()` call that was already writing `srNo`/`srNoNorm`/`srNoSource`. Not one extra Firestore round-trip anywhere. |
| **QUERIES** | 0 |
| **BATCHES/TRANSACTIONS** | 0 new |
| **EXPECTED HOTSPOTS** | None |
| **N+1 RISK** | **None** — in bulk import the computation is per-row but adds zero I/O per row; the existing per-row write it piggybacks on is unchanged in count |
| **IDEMPOTENCY** | Deterministic — the same (session, class, SRNO) always yields the identical string; recomputing is harmless |
| **CONCURRENCY SAFETY** | Inherited — the value is derived from an SRNO that was itself allocated through the already-atomic, already-proven `Sr_no_service` primitive |

This is the cheapest possible implementation shape given the elevated-Firestore-latency constraint:
**it adds no I/O whatsoever.**

## Q. Security / Tenant Isolation

- Every Admission Number component is server-derived; **no client input reaches it**. There is no
  input field, and no controller reads an `admission_no`/`admissionNumber` POST parameter anywhere
  (confirmed by grep).
- School context re-verified this pass by fresh grep across `Sis.php` and `Readmission.php` for
  `input->post('school`/`post('schoolId'`/`post('school_id'`/`post('tenant`: **zero matches**.
  Tenant identity remains exclusively server-derived from session/Firebase-Auth claims.
- Cross-school collision: `2026/01/012` in School A and School B is **intentionally acceptable** —
  Admission Number is inherently school-scoped (it lives on a school-scoped student document,
  reached only through school-scoped queries) and, like SRNO, requires **no global uniqueness and no
  new claim/index mechanism**. **No second identity system was created** — a deliberate decision
  consistent with the instruction not to create unnecessary global uniqueness.

## R. Firestore Rules / Index Reconciliation

- Re-confirmed unchanged: `integration/phase2` has no remote counterpart (`git ls-remote --heads
  origin`), nothing to pull or reconcile.
- **`firestore.rules` NOT MODIFIED** — verified by `git diff --stat` showing the identical
  591-line/567-insertion figure as the Phase 9.2 state, i.e. zero new hunks.
- **`firestore.indexes.json` NOT MODIFIED** — no new query shape introduced; Admission Number is
  never queried, only stored and displayed.
- Neither needed a change: the new fields live on the existing `students` document, already covered
  by its existing rule block.

## S. Tests

| Item | Status |
|---|---|
| Class→2-digit-code mapping (1..12, all spelling variants) | **PASS** (unit) |
| Pre-primary/unrecognised class → `00` fallback | **PASS** (unit) |
| SRNO padding to min 3 digits | **PASS** (unit) |
| SRNO beyond 999 never truncated | **PASS** (unit) |
| Session-year slicing | **PASS** (unit) |
| Full `YYYY/CC/NNN` shape contract | **PASS** (unit) |
| `_computeAdmissionNumber()`'s own string assembly | **SOURCE VERIFIED only** — private CI3 controller method; this repo has zero controller tests for any `Sis.php` method, before or after this phase. Explicitly not claimed as PASS. |
| Wiring into the 3 admission paths | **SOURCE VERIFIED** (`php -l` + call-site trace) |
| Certificates picking up the new field | **SOURCE VERIFIED** — field name matches the existing reader exactly; not browser-tested |
| Parent App / Teacher App unaffected | **SOURCE VERIFIED** — direct model-file read + repo-wide grep, both repos |
| **Full regression** | **PASS** — 710 tests, 2237 assertions, **same 10 pre-existing failures by exact name, zero new** (baseline 700 → 710, exactly the 10 new tests) |

## T. Browser / UAT Status

**BROWSER NOT TESTED — ENVIRONMENT BLOCKED.** No browser tool and no authenticated session are
available; per standing instruction, no test account was created and no UAT is claimed. The three
new UI surfaces (two credentials panels, profile identity block) have not been visually verified.

## U. Remaining Gaps

1. **§E re-admission Admission Number semantics** — open decision, `Readmission.php` untouched.
2. **Historical-import year caveat** — in Historical mode the `YYYY` component is the **current
   session**, not the student's real original admission year (the import file has no canonical
   field for it). Disclosed in-code and here; a future enhancement could add an optional
   `Admission Year` import column.
3. **Bulk-import result screen / credentials PDF** still show neither SR Number nor Admission Number
   (pre-existing gap first identified in Phase 10 §A2, not closed this pass — the PDF's
   `$credentials[]` array would need extending).
4. **SIS student list** has no SRNO/Admission Number column (pre-existing).
5. **TC / report card / receipt** consumers not verified for Admission Number (NOT VERIFIED).
6. All Fees items (§J-O) — audited, none implemented.

## V. Exact Files Changed

| File | Change |
|---|---|
| `application/controllers/Sis.php` | Added `_computeAdmissionNumber()`; `_claimIdentityAttributes()` gained a `$className` param and now writes `Admission Number`/`admissionNumber`; both call sites updated; both bulk-import branches (historical + new) write the field; both admission JSON responses return `admission_no` |
| `application/views/student_profile.php` | Added Admission Number and Roll Number as distinctly-labelled fields alongside SR Number |
| `application/views/studentAdmission.php` | Credentials box now shows ADMISSION NUMBER |
| `application/views/admission_crm/applications.php` | Credentials panel now shows Admission Number |
| `tests/Unit/AdmissionNumberComponentsTest.php` | **New** — 10 tests |

**Not changed:** `Sr_no_service.php`, `Apaar_service.php`, `Id_generator.php`, `Readmission.php`,
`Certificates.php`, `Student_context.php`, `firestore.rules`, `firestore.indexes.json`, Parent App,
Teacher App, any Fees file.

## W. Git Status

Branch `integration/phase2`, unchanged. `git status --short`: 408 → 409 lines (the one new test
file; this report accounts for the rest of the program's accumulating docs). No `reset`, `stash`,
`checkout`, `rebase`, or `merge` was run. **Nothing staged. Nothing committed. Nothing pushed.**

## X. Commit Recommendation

**Do not commit yet.** Two items warrant your decision first: the §E re-admission semantics (which
may require a follow-up edit to `Readmission.php` in the same logical change), and confirmation that
the `00` pre-primary class code is acceptable. Once both are settled, this becomes a clean,
self-contained commit.

**Recommended next phase:** the Fees due-date fix (§K) — the single highest-leverage unblock, and a
prerequisite for both automatic late fees and meaningful reminders.

**Nothing was committed or pushed.**