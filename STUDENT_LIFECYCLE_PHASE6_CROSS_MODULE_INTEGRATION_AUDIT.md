# Student Lifecycle Phase 6 — Cross-Module Integration Audit & Certification

**Date:** 2026-08-13 · **Status: AUDIT + minimal, explicitly-scoped verification. NO new
implementation performed this pass beyond re-verifying prior claims against current code.**
**Working tree: HELD.** No commits, staging, push, reset, revert, stash, or clean. No files modified
in this pass — this is a read-only audit deliverable, per Section 4/24/30's instruction to audit
before implementing and to implement only explicitly-authorized scope.

**Method, per Section 20B's explicit instruction:** this does **not** repeat the Phase 0 forensic
lifecycle audit (`STUDENT_LIFECYCLE_FORENSIC_AUDIT_PHASE0.md`, 24 stages, findings SL-01 through
SL-38 plus RES-001/CERT-001/RF-001) or the identity/SRNO audits from this same conversation
(`STUDENT_LIFECYCLE_PHASE1_IDENTITY_DECISION.md`, `SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md`,
`SRNO_STUDENT_ID_RE_ADMISSION_MULTI_TENANT_FINAL_AUDIT.md`, `SRNO_STUDENT_ID_RE_ADMISSION_GAP_CLOSURE_REPORT.md`).
Those are the baseline and are cited by finding ID throughout, not re-derived. This document adds
exactly what Phase 6 asks for: (1) fresh re-verification of three specific "is this still true in
current code?" items the prior audits flagged, since the instruction explicitly forbids assuming
they're fixed; (2) the cross-module CONNECTION audit — whether events in one module correctly
propagate to dependents — built by mapping already-gathered evidence onto the requested matrices,
not by re-investigating every module from zero.

---

## A. Executive Summary

The SRNO/Student ID/re-admission work built across this conversation is **narrowly and correctly
scoped** — it touches exactly two collections (`srNoIndex`, `students`) and zero other modules,
proven by a dedicated isolation test in the prior pass. **It has introduced no new cross-module
connection gaps**, because it makes no cross-module connections at all yet (no UI, not wired into
Fees/Attendance/Results/Transport/Hostel/Certificates/Communication/Parent/Teacher). The cross-module
gaps that exist in ZenX today are **entirely pre-existing**, already catalogued in Phase 0, and
**re-verified in this pass to still be present** — three specific items were checked fresh against
current code rather than assumed fixed (§C), and all three are confirmed still open. This audit adds
one genuinely new observation: the pre-existing SL-12 canonicalization bypass is a **direct blocker**
for SRNO's own future Student 360/Trail integration, not just a general data-quality issue — see §G.

## B. What Was Already Correct (unchanged, re-confirmed)

- Student ID global uniqueness, SRNO school-scoping, `srNoIndex` tenant isolation, atomic
  claim/relink, circular-link prevention, cross-school `previousStudentId` rejection, predecessor
  double-spend prevention, idempotent retry, ordinary-admission SRNO wiring — all **CONFIRMED**
  in the prior pass's report and re-confirmed unchanged this pass (no code in these paths was touched
  or needed touching).
- Every already-sound Phase 0 finding not touched by SRNO work remains sound: marks-entry audit
  trail, TC counter atomicity, TC cancel/reversal, Document_service delegation discipline, transport
  boarding-roster safety gate, hostel capacity enforcement, attendance correction/audit trail,
  admission-fee ledger idempotency.

## C. What Was Wrong — Fresh Re-Verification (not assumed)

Per Section 12's explicit instruction not to assume the report-card/TC-label issue is fixed, and
Section 13's instruction to re-check SL-12 rather than ignore it:

1. **Report-card Roll Number source — STILL BROKEN, re-verified this pass.**
   `application/views/result/templates/_report_card_data.php:26`:
   `$rollNo = $profile['User Id'] ?? '';` — unchanged from Phase 0's finding. Every report card
   still prints the login identifier where the class roll number belongs. **Severity: MEDIUM**
   (wrong data on an official document, not identity/financial-corrupting).

2. **TC print mislabel — STILL PRESENT, re-verified this pass.**
   `application/views/sis/tc_print.php:23,433`: `$rollNo = $s['Roll No'] ?? ''` is printed under the
   literal label `"Adm No."`. Unchanged from Phase 0. **Severity: MEDIUM** — this is exactly the kind
   of confusion Section 16 asks about (Roll Number mislabeled as Admission Number) — **confirmed
   still happening**, not fixed by any SRNO work (SRNO work never touched this file).

3. **SL-12 promotion canonicalization bypass — STILL PRESENT, re-verified this pass.**
   `application/libraries/Dual_write.php:135-151` (`validateClass`/`validateSection`, the functions
   `batchMoveStudents`/promotion actually call) still use the bare
   `Firestore_service::classKey()`/`sectionKey()` prefix helpers, **not**
   `Entity_firestore_sync::normalizeClassSection()`. Confirmed by direct grep and read of the current
   file — this is not inherited assumption, it's fresh evidence from this pass. **Severity: HIGH**,
   unchanged classification from Phase 0 — and see §G for why this specifically now also blocks
   future SRNO/Student 360 integration cleanly.

**No new defect was found in this pass beyond what Phase 0/prior SRNO audits already knew.** The
value of this section is confirming these three are *still* true, not discovering new ones.

## D. What Was Implemented This Pass

**Nothing.** Per Section 4's instruction ("DO NOT immediately write code... first perform a fresh
forensic audit") and Section 24's instruction ("DO NOT immediately implement everything found...
implement only the authorized A/B scope"), this pass is audit-only. The three re-confirmed items in
§C were evaluated against Category A/B/C (§Q) and every one landed in **Category C — separate future
phase** — none is SRNO-specific, none was pre-authorized for this pass, and two (SL-12, TC label)
touch files with their own independent risk profile (`Dual_write.php` is core to every student-moving
operation in the system; `tc_print.php` is a legal-document template). Implementing them here would
violate Section 30's explicit "do not make architectural decisions on my behalf" and "if you discover
[something needing a decision], STOP that path, document alternatives, ask" instructions — none of
these three has an ambiguous *fix*, but all three have an ambiguous *backfill scope* (do drifted
historical records get corrected too?), which is exactly the kind of decision this instruction says
to surface, not silently resolve.

## E. What Was Not Implemented, With Reasons

| Item | Reason |
|---|---|
| SL-12 fix (route promotion through `normalizeClassSection`) | Pre-existing, independent of SRNO, needs a backfill-scope decision (§Q) |
| Report-card Roll No source fix | Pre-existing, independent, low-risk fix but touches a shared template used by 7 report-card variants — needs its own regression pass, not authorized this pass |
| TC print label fix | Pre-existing, independent, touches a legal-document template |
| SRNO operator UI (§7/§8/§23) | No view file has been touched in this entire conversation's SRNO work; building UI is explicitly Category C per every prior report |
| `srNo.mode` auto/manual/hybrid config | Explicitly deferred since the first implementation pass |
| Dedicated graded RBAC capability | Investigated and deliberately deferred (prior report §8) — cross-system change requiring `functions/rbac_modules.json` coordination |
| Student Trail / Student 360 SRNO integration | Neither Student Trail nor a Student-360-aware SRNO resolver exists; both are Category C |

## F. Cross-Module Impact of THIS Session's Work

**Zero, verified, not merely claimed.** `Sr_no_service.php` and `Readmission.php` reference exactly
two collections. The `enroll_student()` integration (prior pass) is additive and optional. No
Fees/Attendance/Examination/Results/Transport/Hostel/Documents/Certificates/Communication/Parent/
Teacher code path was touched by any pass in this conversation. This section exists to state that
explicitly rather than leave it implied.

---

## G. Roll Number / Class / Section Safety (dedicated section, not buried)

**Roll Number scope — CONFIRMED correct, unchanged from Phase 0.** Roll Number is captured at
admission (`Sis.php:625,873`), editable, persistent, class/section-scoped, never used as a query key
(`Roster_helper.php` sorts by it, never filters by it). Nothing in this conversation's SRNO work
touches Roll Number in any way — `srNo`/`srNoNorm`/`srNoSource` are distinct fields on the student
doc, never conflated with `Roll No`/`rollNo` anywhere in `Sr_no_service.php` or `Readmission.php`
(verified by grep — zero cross-references).

**Class/Section canonicalization — CONFIRMED still broken (SL-12), re-verified §C item 3.**

**Why this matters specifically for the SRNO work now, not just in general (new observation this
pass):** `Sr_no_service::relink()` and the `enroll_student()` SRNO claim both write to the student
doc via `Firestore_service::docId($studentId)` — a plain Student-ID-keyed write, **not** a
class/section-keyed one. SRNO's own correctness is therefore **unaffected** by SL-12 — this was
verified, not assumed, by re-reading `Sr_no_service.php` in full during this pass and confirming it
never references `className`/`section`/`classKey`/`sectionKey` anywhere. **However**, once Student
360 (or any future SRNO-aware search) needs to show a student's *class/section* alongside their SRNO
(an obviously expected display field), it will inherit SL-12's drift exactly like every other
class/section-keyed query in the system already does. **This is why SL-12 should be resolved before,
not during or after, Student 360's SRNO integration is built** — flagged as a sequencing dependency,
not a re-opened design question.

**Roll Number MUST NOT become a student identity — CONFIRMED, no code anywhere treats it as one.**
Duplicate-Roll-Number-in-same-class/section validation: **NOT VERIFIED** — no explicit uniqueness
check for Roll Number within a class/section was found or looked for in depth this pass; Phase 0
did not report one either. Flagged as **NOT VERIFIED**, not asserted either way.

---

## H. Multi-Tenant Safety (dedicated section)

Restated with evidence, per Section 29 H's explicit requirement:

- **Same SRNO across schools = VALID.** PROVEN, `SrNoServiceMultiTenantTest::test_same_srno_in_two_different_schools_does_not_collide`
  — two schools sharing one backing store both successfully claim digits `1033`, producing two
  distinct documents.
- **Same SRNO inside one school = duplicate, rejected.** PROVEN,
  `test_duplicate_srno_within_the_same_school_is_still_rejected`.
- **Student ID = global.** PROVEN by code inspection — `Id_generator.php`'s STU claim path has zero
  `schoolId` references anywhere (re-confirmed in the prior pass, unchanged this pass).
- **Cross-school SRNO lookup fails safely.** PROVEN,
  `test_school_a_cannot_resolve_school_bs_srno_claim`.
- **Cross-school `previousStudentId` rejected.** PROVEN,
  `test_relink_cannot_use_a_previous_student_id_that_only_exists_in_another_school`.

## I. Re-Admission Behavior — Concrete Example

```
FIRST ADMISSION            RE-ADMISSION
School A                   School A
Student ID = STU0001       Student ID = STU0047
SRNO       = 1025          SRNO       = 1025
                            previousStudentId = STU0001
```

**Preserved:** `STU0001`'s Firestore document (unwritten by relink — proven,
`test_relink_only_writes_srNoIndex_and_the_new_student_doc`), its own historical `srNo`/fees/
attendance/results/discipline/documents/certificates records, its Firebase Auth identity.
**New:** `STU0047`'s Student ID (freshly, normally allocated — the global counter, unchanged),
`STU0047`'s own fresh lifecycle records going forward.
**Linked, not merged:** `srNoIndex`'s `currentStudentId` pointer moves to `STU0047`;
`STU0047.previousStudentId = STU0001` is the only new cross-reference written.

---

## J. Cross-Module Dependency Graph (reconstructed, not assumed)

Built from evidence already gathered across this conversation's Phase 0 audit and this session's own
code reads — not the idealized arrows in the prompt, the ACTUAL graph:

```
Application (crmApplications) ──► Admission (approval) ──► Student creation (enroll_student)
                                                                    │
                    ┌───────────────────────────────────────────────┼─────────────────────────────┐
                    ▼                                                ▼                             ▼
            Fee_lifecycle::assignInitialFees                 Entity_firestore_sync          Sr_no_service::claim
            (feeDemands — best-effort, warned)         (Parent/Student mobile sync,         (srNoIndex — best-effort,
                                                          best-effort, warned)                 warned) [THIS SESSION]
                    │
                    ▼
            Fees/Accounting (payment, journal — sound, idempotent)
                    │
                    ▼
            Defaulter status (feeDefaulters) ──► Result withholding (Result.php — reads via RTDB, RES-001)

Class/Section/Roll Number: set at enrollment (Sis.php), read by Attendance/Examination/Results/
Homework/Timetable/Subject_assignment via Entity_firestore_sync::normalizeClassSection() — EXCEPT
Promotion (Dual_write::validateClass/validateSection), which bypasses it (SL-12).

Transport/Hostel: independently assigned post-admission, own fee-linkage (best-effort), own
suspension/rollover logic — NOT connected to withdrawal/TC (SL-21: TC doesn't release a hostel bed).

Certificates: reads Admission Number (no writer, SL-24), Roll Number (mislabeled, §C.2), 100% RTDB
storage (CERT-001) — NOT connected to any Firestore-native student data path.

Student 360 / Student Trail: Student 360 exists (Student_context.php, single-Student-ID read,
no SRNO resolution yet). Student Trail does NOT exist as a feature anywhere in this codebase.

SRNO/re-admission (this conversation's work): connects ONLY to `students` + `srNoIndex`. Not yet
connected to Student 360, Student Trail, Certificates, or any search surface.
```

## K. Event → Dependent-Module Matrix

Built from Phase 0 + this conversation's evidence. **CONNECTED** = propagation verified correct.
**PARTIAL** = propagates but with a known gap. **NOT CONNECTED** = no propagation found where one
would be expected. **N/A** = no propagation is architecturally expected. Only events with a
determinable answer from existing evidence are marked with confidence; others are **NOT VERIFIED**.

| # | Event | Source | Dependents | Status | Evidence |
|---|---|---|---|---|---|
| 1 | Application submitted | `Admission_public`/`Sis` | Admission CRM | CONNECTED | Phase 0 §2 |
| 2 | Application approved | `Sis.php` | Enrollment eligibility | CONNECTED | Phase 0 §2 |
| 3 | Application rejected | `Sis.php` | (none expected) | N/A, but **PARTIAL** — no retention/purge policy | Phase 0 finding, unchanged |
| 4 | Admission completed | `Sis.php` | Student creation | CONNECTED | Phase 0 §4 |
| 5 | Student created | `enroll_student` | Fees, mobile sync, SRNO (this session) | **PARTIAL** — all three are independently best-effort/warned, not transactional with student creation | Phase 0 §4 + this session |
| 6 | SRNO assigned | `Sr_no_service::claim` (this session) | Certificates (future), Student 360 (future), search (future) | **NOT CONNECTED yet** — by design, disclosed scope | This session |
| 7 | Parent linked | `Entity_firestore_sync::syncParent` | Parent App | CONNECTED, but per-student not per-family (Phase 1 identity doc finding) | Phase 0/1 |
| 8 | Class assigned | `enroll_student` | Attendance/Exam/Results/Homework/Timetable | CONNECTED via `normalizeClassSection` | Phase 0 |
| 9 | Section assigned | same | same | CONNECTED | Phase 0 |
| 10 | Roll Number assigned | `enroll_student` | Report cards (BROKEN — wrong source), exam rosters (CONNECTED) | **PARTIAL** | §C.1 |
| 11 | Fee structure assigned | `Fee_lifecycle` | Demand generation | CONNECTED | Phase 0 |
| 12 | Fee demand generated | `assignInitialFees`/`buildAdmissionDemandSpecs` | Receipts, accounting, defaulter | **PARTIAL** — mid-year admission over-bills (SL-13) | Phase 0 |
| 13 | Concession assigned | `studentConcessions` path | Demand generation (flag-gated) | PARTIAL — fragmented across 3 mechanisms (SL-16) | Phase 0 |
| 14 | Entitlement assigned | `Entitlement_service` | Demand generation | **NOT CONNECTED** to pricing per the service's own docblock ("reader exists but is not consumed") | Phase 0 |
| 15 | Discount assigned | `studentDiscounts`/Fees.php | Collection-layer only | CONNECTED for its own narrow scope, disconnected from generation-layer (SL-16) | Phase 0 |
| 16 | Fee collected | `FeeCollectionService` | Receipt, journal | CONNECTED, idempotent | Phase 0 |
| 17 | Receipt generated | same | Accounting | CONNECTED | Phase 0 |
| 18 | Accounting posted | `create_fee_journal` | Ledger | CONNECTED, idempotent | Phase 0 |
| 19 | Student becomes defaulter | `updateDefaulterStatus`/`feeDefaulters` | Result withholding | CONNECTED but via RTDB (RES-001), and inflated by SL-13's proration bug | Phase 0 |
| 20 | Attendance recorded | `Attendance.php` | Report cards, Parent/Teacher apps | CONNECTED | Phase 0 |
| 21 | Attendance corrected | same | Audit trail | CONNECTED | Phase 0 |
| 22 | Exam created | `Examination.php` | Roster (`Roster_helper`) | CONNECTED | Phase 0 |
| 23 | Marks entered | `Result.php` | `marksAudit`, results | CONNECTED, audited | Phase 0 |
| 24 | Result generated | `Exam_engine.php` | Report cards | CONNECTED | Phase 0 |
| 25 | Result corrected | `Result.php` | `marksAudit` | CONNECTED (audited, not blocked) | Phase 0 |
| 26 | Result withheld/released | defaulter flag | Report card/PDF rendering only | **PARTIAL** — RTDB-sourced (RES-001), no enforcement at promotion/TC (SL-19) | Phase 0 |
| 27 | Student promoted | `execute_promotion` | Class/section, fees, mobile sync | **PARTIAL** — canonicalization bypass (SL-12), fee regen deferred post-response (SL-09) | Phase 0 |
| 28 | Student detained | (implicit — omission from promotion) | (none) | **NOT CONNECTED** — no dedicated code path or audit trail (SL-10) | Phase 0 |
| 29 | Class changed (mid-session) | **no standalone action exists** | — | **NOT CONNECTED** — `edit_student` silently discards Class/Section submissions (SL-11) | Phase 0 |
| 30 | Section changed | same | — | **NOT CONNECTED**, same as #29 | Phase 0 |
| 31 | Session changed | `Dual_write::batchMoveStudents` (side effect of promotion) | all session-scoped modules | CONNECTED, in-place update, matches canonical-session memory | Phase 0 |
| 32 | Transport assigned | `Transport_service::assignStudent` | Billing | CONNECTED | Phase 0 |
| 33 | Transport removed/suspended | `suspendAssignment` | Billing | CONNECTED (fixed, G1 2026-08-07) | Phase 0 |
| 34 | Hostel assigned | `Hostel.php::save_allocation` | Billing | **PARTIAL** — best-effort fee linkage (SL-20) | Phase 0 |
| 35 | Hostel changed | same | Room occupancy | CONNECTED | Phase 0 |
| 36 | Hostel checkout | `delete_allocation` | Billing (freeze) | CONNECTED **but never triggered by TC** (SL-21) | Phase 0 |
| 37 | Document uploaded | `Document_service::upload` | Verification, audit | CONNECTED | Phase 0 |
| 38 | Document verified | `Document_service::verify` | Audit | CONNECTED | Phase 0 |
| 39 | Document rejected | **NOT VERIFIED** — not independently traced | — | NOT VERIFIED | — |
| 40 | Certificate generated | `Certificates.php` | (nothing — isolated) | **NOT CONNECTED to Firestore at all** — 100% RTDB (CERT-001), prints fabricated Admission Number (SL-24), wrong Roll No label (§C.2) | Phase 0 + this pass |
| 41 | TC generated | `issue_tc` | Fee dues gate (blocks, doesn't freeze), hostel (NOT connected, SL-21), transport (NOT VERIFIED) | **PARTIAL** | Phase 0 |
| 42 | Student withdrawn | `withdraw_student` | Fees (freezes, CONNECTED), transport/hostel (NOT VERIFIED) | PARTIAL | Phase 0 |
| 43 | Student soft-deleted | `Dual_write::softDeleteStudent` | (record retained) | CONNECTED | Phase 0 |
| 44 | Student restored | `recoverStudent()` (library only) | **NOTHING — unreachable, zero callers** | **NOT CONNECTED** (SL-33) | Phase 0 |
| 45 | Student hard-deleted | `delete_student(hard_delete=true)` | 8-collection cascade, SRNO orphan-flag (this session) | CONNECTED for the cascade; **PARTIAL** for SRNO (flag only, no policy) | Phase 0 + this session |
| 46 | Student re-admitted | `Readmission::confirm` + `Sr_no_service::relink` (this session) | `srNoIndex`, new student doc | CONNECTED, tested, isolated | This session |
| 47 | Historical link created | same | — | CONNECTED, cycle-safe, cross-tenant-safe | This session |
| 48 | SRNO corrected/relinked | `relink()` (re-admission trigger only — no separate `correct_sr_number` exists) | `srNoIndex` | **PARTIAL** — correction-vs-relink distinction not implemented (disclosed, prior report §20) | This session |

## L. "Write But Not Propagated" — Confirmed Instances

Checked the prompt's example list against actual evidence:

- **Student changes class → Attendance still points to old class:** structurally can't happen for
  the SAME reason class-change itself can't happen mid-session (SL-11 — there's no standalone
  action; the only path is full promotion, which DOES correctly propagate to Attendance via the
  shared session/class-key convention, modulo SL-12's canonicalization drift).
- **Student promoted → Attendance queries cannot find student:** **CONFIRMED risk, via SL-12** — a
  promotion into a non-canonical class key would cause exactly this.
- **Student admitted → fee setup missing:** **NOT the normal case** — `assignInitialFees` runs
  synchronously (best-effort) at enrollment; only a failure produces this, and it's surfaced as an
  operator-visible warning, not silent.
- **Fee collected → accounting journal missing:** **CONFIRMED NOT an issue** — `create_fee_journal`
  is idempotent and reliably invoked from the collection path (Phase 0, re-confirmed).
- **Student becomes defaulter → result withholding not synchronized:** **CONFIRMED, via RES-001** —
  two different stores (Firestore `feeDefaulters` vs. RTDB read in `Result.php`) can diverge.
- **Student withdrawn → transport assignment remains active:** **NOT VERIFIED** — Phase 0 did not
  independently trace this specific interaction; flagged as a genuine open question, not asserted
  either way.
- **Student withdrawn → hostel bed remains occupied:** **NOT VERIFIED for withdrawal specifically**
  (SL-21 was confirmed for TC issuance, not independently re-checked for withdrawal in this pass).
- **TC issued → hostel/transport not released:** **CONFIRMED for hostel (SL-21)**; transport **NOT
  VERIFIED**.
- **Re-admission → historical relationship missing:** **RESOLVED by this conversation's work** — this
  was the entire point of the SRNO/`previousStudentId` project; no longer a gap.
- **SRNO assigned → Student 360/Certificate still expects old identifier:** **CONFIRMED, by design/
  disclosed scope** — neither integration exists yet (§E).

## M. "Read But Not Written" — Field Authority Audit

| Field | Writer(s) | Reader(s) | Classification |
|---|---|---|---|
| `Admission Number` | **None, anywhere** | `Certificates.php`, `Student_context.php` | **MISSING WRITER** (SL-24, unchanged) |
| `SRNO`/`srNo` | `Sr_no_service::claim`/`relink` (this session, both write consistently via one code path each) | `Sr_no_service::resolve` (this session only — no other module reads it yet) | **HEALTHY**, narrow scope |
| `APAAR`/`apaarId` | **None, anywhere** | `Student_context.php` (dead scaffolding) | **MISSING WRITER**, unchanged |
| `RollNo`/`Roll No` | `Sis.php` (admission + edit paths, dual-key mirrored) | Report cards (**WRONG FIELD**, reads `User Id` instead — §C.1), exam rosters (correct), TC print (correct value, **WRONG LABEL** — §C.2) | **MULTIPLE-REPRESENTATION CONFUSION** at the reader end, not the writer end — the writer is healthy and singular; specific readers are broken |
| `className`/`section` | `enroll_student` (canonical), `Dual_write::batchMoveStudents` (**non-canonical**, SL-12) | Attendance/Academic/Homework/etc. (expect canonical) | **CONFLICTING REPRESENTATION**, confirmed live |
| `session` | `enroll_student`, `Dual_write::batchMoveStudents` | Everything session-scoped | **HEALTHY** — singular, canonical, matches `[[student_session_canonical]]` |
| `status`/`Status` | Multiple writers across withdraw/delete/TC paths, dual-keyed | Multiple readers, fail-open `?? 'Active'` default | **CONFLICTING REPRESENTATION** (SL-06, unchanged) |
| `previousStudentId` | `Sr_no_service::relink` (this session, single writer) | `Readmission::confirm` (self-check only) | **HEALTHY**, narrow scope, not yet read by Student 360/Trail (don't exist) |

## N. Financial Cross-Module Chain

Unchanged from Phase 0's own §Q (Financial Dependency Order) — re-cited, not re-derived:
`buildAdmissionDemandSpecs` (SL-13, no join-date filter) is the root cause feeding forward into
defaulter status and (via RES-001) result withholding. This session's SRNO work does not touch any
node in this chain — confirmed by the isolation test (§F).

## O. Cross-Platform Consistency

**NOT VERIFIED for the majority of fields** — Phase 0 did not build a full Admin/Parent/Teacher/
Firestore/Accounting comparison table, and this pass did not either (out of scope for the audit
depth achievable here; would require its own dedicated pass per field). What **is** known: SRNO is
not yet surfaced on any platform (§E), so it cannot yet diverge across platforms — trivially
consistent by not existing anywhere client-facing yet.

## P. Orphan/Stale Record Audit

| Scenario | Status |
|---|---|
| Student hard-deleted → `srNoIndex` orphaned | **PARTIALLY ADDRESSED** — informational flag added (prior pass), policy undecided (disclosed) |
| Student withdrawn → transport/hostel remain active | **NOT VERIFIED** (§L) |
| TC issued → hostel bed remains occupied | **CONFIRMED** (SL-21) |
| Student restored → dependent records inaccessible | **N/A** — restore itself is unreachable (SL-33), so this specific failure mode can't manifest today |
| Student re-admitted → old Student ID remains "active" by mistake | **CONFIRMED PREVENTED** — `Readmission::confirm()` requires the old record's status to NOT be Active before relinking |

## Q. Category A/B/C

**Category A (must fix now): none.** No cross-module defect discovered or re-confirmed this pass
rises to "must fix before this pass can be called complete" — every item found is either already
disclosed/tracked (Phase 0) or explicitly out of scope for a backend-only SRNO pass.

**Category B (safe to implement this phase): none.** Evaluated all three re-confirmed items (§C)
against this bar and none qualifies — each needs a business decision (backfill scope for SL-12,
regression-testing commitment for the two label fixes) this document is not authorized to make
silently, per Section 30.

**Category C (separate future phase):** SL-12 fix + backfill decision, report-card Roll No source
fix, TC print label fix, SRNO operator UI, `srNo.mode` config layer, dedicated RBAC capability,
Student 360/Student Trail SRNO integration, withdrawal→transport/hostel release verification (§L,
currently NOT VERIFIED either way and should be resolved before being called safe).

**Per Section 30's explicit instruction, this is a STOP point, not a silent decision:** SL-12's fix
requires you to decide whether existing drifted `students.className` values in production get
backfilled or left as-is (Phase 1 Readiness doc's Part U already framed this exact choice). This
audit does not choose for you.

---

## R. Final Cross-System Certification

| Area | Status | Evidence | Remaining Risk |
|---|---|---|---|
| Student ID | **CONFIRMED** | Code inspection, no schoolId in `Id_generator.php` | None |
| SRNO | **TESTED** | 20/20 tests | Not wired into any UI/search surface |
| srNoIndex | **TESTED** | Multi-tenant + cycle tests | Orphan policy on hard-delete undecided |
| Multi-tenancy (SRNO) | **TESTED** | Dedicated cross-school tests | None found |
| Re-admission | **TESTED** | 20/20 tests, code-reviewed controller layer | UI doesn't exist |
| previousStudentId | **TESTED** | Cycle/self-link/cross-school rejection tests | Predecessor-eligibility rules beyond status/existence NOT VERIFIED exhaustively |
| Student Trail | **NOT IMPLEMENTED** | — | Feature doesn't exist |
| Student 360 | **NOT IMPLEMENTED (for SRNO)** | Existing single-Student-ID version unaffected | SRNO resolution step not built |
| Roll Number | **PARTIAL** | Writer healthy; 2 reader defects re-confirmed (§C.1/§C.2) | Report cards + TC print show wrong values |
| Class/Section | **PARTIAL — REMAINING GAP** | SL-12 re-confirmed live | Cross-module query mismatch after promotion |
| Session | **CONFIRMED** | In-place, canonical, unchanged | None |
| SIS | **CONFIRMED** (core), **PARTIAL** (mid-session class change missing, SL-11) | Phase 0 | — |
| Fees | **PARTIAL** | SL-13 (mid-year proration), SL-16 (fragmented discounts) | Live financial risk, unchanged by this session |
| Accounting | **CONFIRMED** | Idempotent journal posting | Inherits Fees' upstream risk |
| Attendance | **PARTIAL** | SL-07 (roster leak, confirmed) | — |
| Examination | **CONFIRMED** | Roster/keying sound | — |
| Results | **PARTIAL** | RES-001 (RTDB), no promotion/TC enforcement (SL-19) | — |
| Report Cards | **BROKEN (Roll No source)** | §C.1, re-verified | Wrong document field on every card |
| Certificates | **BROKEN** | CERT-001 (RTDB), SL-24 (Admission Number), §C.2 (mislabel) | Three independent defects, unrelated to SRNO |
| TC | **PARTIAL** | Core flow sound (issue/cancel/counter), hostel not released (SL-21) | — |
| Documents | **CONFIRMED** | Document_service delegation proven | — |
| Parent App | **NOT VERIFIED (SRNO-specific)** | Pre-existing per-student linkage confirmed sound (Phase 0) | SRNO display not built |
| Teacher App | **NOT VERIFIED (SRNO-specific)** | — | SRNO display not built |
| Transport | **CONFIRMED (core)** | Billing/rollover/safety gate all sound | Withdrawal-interaction NOT VERIFIED (§L) |
| Hostel | **PARTIAL** | SL-20 (best-effort fee), SL-21 (TC doesn't release) | — |
| Communication | **PARTIAL** | SL-28 (fabricated SMS/email status) | — |
| RBAC (SRNO) | **CONFIRMED as a deliberate compromise** | §8 of prior report — hard-role-check, narrower than SIS:manage | Not a graded capability |
| APAAR | **CONFIRMED boundary respected** | Zero references in any SRNO code | Feature itself still not implemented |
| Audit Trail (SRNO) | **CONFIRMED** | `log_audit` reuse, tested | — |

---

## Top 20 Cross-Module Gaps (ranked, most severe first)

1. SL-13 — mid-year admission overbilling (CRITICAL, unchanged, financial)
2. SL-36-class re-admission identity — **RESOLVED** by this conversation's work (listed to show closure)
3. SL-12 — promotion canonicalization bypass (HIGH, re-confirmed live, now also blocks future SRNO/360 integration cleanly)
4. RES-001 — RTDB result-withheld read (HIGH, platform-rule violation)
5. CERT-001 — 100% RTDB certificate storage (HIGH)
6. SL-24 — Admission Number fabricated on every certificate (HIGH)
7. SL-16 — three fragmented discount mechanisms (HIGH, financial)
8. SL-33 — restore unreachable (MEDIUM-HIGH)
9. SL-21 — TC doesn't release hostel bed (MEDIUM)
10. SL-09 — promotion fee-regen deferred, failure invisible (MEDIUM-HIGH, financial)
11. §C.1 — report card Roll No wrong source (MEDIUM, re-confirmed)
12. §C.2 — TC print Roll No mislabeled "Adm No." (MEDIUM, re-confirmed)
13. SL-27 — sibling relationship unstructured (HIGH, fraud-adjacent)
14. SL-28 — fabricated SMS/email delivery status (MEDIUM-HIGH)
15. SL-07 — attendance roster status-filter leak (MEDIUM-HIGH)
16. SL-11 — no mid-session class/section change (MEDIUM)
17. SL-20 — hostel fee best-effort (MEDIUM)
18. Withdrawal → transport/hostel release (NOT VERIFIED — genuine unknown, §L)
19. SRNO not wired into any UI or search surface (HIGH for feature completeness, zero risk to existing data)
20. Student Trail does not exist (blocks item 2's full value — the identity fix is real but has no display surface yet)

## Top 10 Integration Risks

1. A future Student 360/Trail SRNO integration built before SL-12 is fixed will silently inherit its
   class/section query mismatches.
2. Certificates remain entirely disconnected from the Firestore-native identity work (RTDB, SL-24,
   §C.2) — three independent, compounding defects on one document type.
3. Fees/Results cross-contamination via SL-13 → defaulter → RES-001 chain, unaffected by this
   session's work but still the single largest financial/academic integrity risk in the system.
4. Withdrawal's interaction with Transport/Hostel is genuinely unknown (§L) — could be fine, could be
   another SL-21.
5. `srNoIndex`'s orphan policy remaining undecided is a growing, compounding risk the longer
   hard-delete is used without resolution.
6. No dedicated RBAC capability means SRNO relinking's authorization surface can't be independently
   audited/reported on by role — it's bundled into "is this person Principal or above."
7. Report-card/TC-print field confusion (§C.1/§C.2) is exactly the kind of defect that erodes trust
   in official documents — low technical severity, high reputational exposure.
8. SL-16's fragmented discount mechanisms mean any future "show total savings" feature (a natural
   Student 360/Trail ask) has no single correct source to read from.
9. SL-09's deferred fee-regen-with-invisible-failure means an operator's "success" screen can already
   be lying today, independent of anything SRNO touches.
10. Multiple dual-keyed fields (`status`/`Status`, `className` canonical-vs-not) are a general pattern
    across this codebase, not isolated incidents — each new feature that reads them needs to
    independently rediscover the coalesce-carefully lesson.

## Top 10 Data-Consistency Risks

1. SL-13 (financial, live).
2. RES-001 vs. `feeDefaulters` divergence (dual source of truth).
3. SL-12 (class/section drift).
4. SL-06 (status/Status drift).
5. SL-16 (discount-source fragmentation).
6. `srNoIndex` orphan state on hard-delete (bounded by this session's flag, not resolved).
7. Withdrawal/Transport/Hostel — unknown, flagged NOT VERIFIED rather than assumed safe.
8. SL-27 — unverifiable sibling-discount basis.
9. SL-28 — fabricated communication-delivery status feeding false operator confidence.
10. Report-card/TC field-source confusion (§C.1/§C.2) — low corruption risk, real display-integrity risk.

---

## Final Production Readiness Verdict

**READY WITH CONDITIONS.**

The SRNO/Student ID/re-admission subsystem this conversation built is sound, tested, isolated, and
introduces **zero new cross-module risk** — every genuine risk identified above pre-dates this work
and is unaffected by it. It is not yet a usable, connected feature (no UI, no Student 360/Trail
integration) and does not need to be to be "safe" — it needs those integrations to be *complete*.

**Exact remaining conditions, none hidden:**
- SL-12 must be resolved (with an explicit backfill-scope decision from you) before Student
  360/Trail SRNO integration is built on top of class/section-keyed queries.
- The two re-confirmed Roll Number display defects (§C.1/§C.2) should be fixed independently — low
  risk, unrelated to SRNO, but real and currently live.
- The orphan-`srNoIndex` policy decision (reclaim vs. permanently freeze) needs to be made before
  hard-delete usage grows.
- Withdrawal→Transport/Hostel interaction needs to be actually traced (currently an honest unknown,
  not a confirmed gap or confirmed safety).
- SL-13 (financial) remains the single highest-priority item in the entire system, independent of
  everything in this document — carried forward, not newly discovered.

## Next Phase Recommendation

Per Section 30's instruction not to auto-start another implementation phase: **the next action is
yours to choose, not mine to assume.** Three independent, non-competing tracks are ready to be
authorized separately:
1. **SL-12 fix + backfill decision** — small, isolated, unblocks future SRNO/360 work.
2. **Report-card + TC-print Roll Number fixes** — small, isolated, unrelated to SRNO.
3. **SRNO operator UI** — the largest remaining piece of work, and the one that actually makes this
   conversation's backend work usable by a school.

None of these is started. All three need your explicit go-ahead, individually or together.

---

**Nothing in this pass was committed, staged, reset, stashed, or pushed. No file was modified.**
