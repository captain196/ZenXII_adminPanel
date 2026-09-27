# SRNO / Student ID / Re-admission — Multi-Tenant Final Audit

**Date:** 2026-08-13 · **Status: implementation + validation. NOT committed, staged, or pushed.**

## Upfront answers (as required)

1. **Does the current implementation satisfy the multi-tenant identity model? YES, with one real
   bug found and fixed during this pass** (circular-link — §V) and one pre-existing gap now closed
   (multi-tenant test coverage did not previously exist — it does now, §U).
2. **Is Student ID actually globally unique? CONFIRMED**, by direct code inspection — see §C.
3. **Is SRNO actually school-scoped? CONFIRMED**, by construction (doc-id prefixing) and now by a
   direct cross-tenant test — see §D.
4. **Is srNoIndex correctly tenant-scoped? CONFIRMED** — see §E/§F.
5. **Does re-admission safely preserve SRNO? CONFIRMED**, with the circular-link fix applied — see
   §I/§V.
6. **Is concurrent re-admission safe? CONFIRMED at the code level via deterministic simulation; NOT
   VERIFIED under true multi-process concurrency** (no live Firestore/multi-process test environment
   available here — stated honestly, not glossed over) — see §K.
7. **Any blocker that must be fixed before continuing? One was found and fixed in this pass**
   (circular-link, §V). No other blocker was found. Two previously-disclosed scope gaps remain open
   and are re-stated, not hidden: no dedicated RBAC capability (§M), and ordinary-admission SRNO
   claiming is still not wired into `Sis::enroll_student()` (§X).

---

## A. Executive Summary

This pass re-validated the prior implementation adversarially rather than trusting its passing
tests, per the explicit instruction not to equate "tests pass" with "correct." **One real defect was
found: a circular `previousStudentId` chain was not prevented** — an operator could, with two
`confirm()` calls, make `STU0001` and `STU0047` each other's ancestor. This was fixed by adding a
bounded ancestor-chain walk **inside `Sr_no_service::relink()` itself** (not only the controller),
which also closes a previously-disclosed gap: the service had no defense-in-depth against a careless
caller. Multi-tenant isolation was re-verified with genuine cross-tenant tests (a single shared fake
store standing in for the one real Firestore database every school shares), which did not exist
before this pass — the earlier test suite could not have caught a cross-tenant defect even if one
existed, because every test used one school's namespace only. None was found once tested for real.

## B. Final Identity Model

Unchanged from `STUDENT_LIFECYCLE_PHASE1_IDENTITY_DECISION.md` and
`SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md` — this document does not reopen it. Confirmed compatible
with the actual repository throughout this validation pass; no contradiction was found that would
require reopening it.

## C. Student ID Global Uniqueness Verification

**CONFIRMED**, re-verified this pass with direct evidence, not assumed from memory:

- `Id_generator.php:65-66` — `COL_COUNTERS = 'feeCounters'`, `DOC_PREFIX = '_sys_'`. Claim docs are
  `_sys_STU_claim_{N}` (`Id_generator.php:538-539`), pointer doc is `_sys_STU`
  (`Id_generator.php:154,511,521`).
- **A repo-wide grep of `Id_generator.php` for `schoolId` returns zero matches.** The counter has no
  school component anywhere in its storage key, claim mechanism, or pointer — it is not merely
  "unique within school," it is structurally incapable of being school-scoped as currently written.
- Claim is atomic create-if-not-exists (`Id_generator.php:533-551`, `firestoreCreate`) — the same
  primitive `Sr_no_service::claim()` reuses for SRNO, but SRNO's version IS schoolId-prefixed while
  STU's is not. This is the actual, load-bearing difference between the two identifiers' scope, and
  it is intentional and correct per the identity model (§B).
- This matches `identity_architecture_decision.md`'s prior finding exactly, now re-confirmed by
  reading the code directly in this pass rather than citing memory.

**Invariant 1: PROVEN.**

## D. SRNO School-Scoped Uniqueness Verification

**CONFIRMED**, and now backed by a genuine cross-tenant test that did not exist before this pass:

- `Sr_no_service::claim()` calls `$this->fs->docId($n['norm'])` →
  `Firestore_service::docId()` → `"{$this->schoolId}_{$entityId}"` (`Firestore_service.php:178-181`,
  unchanged, pre-existing). `$this->schoolId` is bound once at `init()` time to the CURRENT request's
  own school.
- **New test, this pass:** `SrNoServiceMultiTenantTest::test_same_srno_in_two_different_schools_does_not_collide`
  — two `Sr_no_service` instances, one per school, sharing ONE `FakeSrNoBackingStore` (modeling the
  one real Firestore database every school actually shares) — both successfully claim SRNO `1033`
  independently, producing two distinct documents (`SCH_A_1033`, `SCH_B_1033`) in the same store.
  **PASS.**
- **New test:** `test_duplicate_srno_within_the_same_school_is_still_rejected` — a second student in
  the SAME school attempting the same digits is rejected. **PASS.**

**Invariants 2, 3, 13: PROVEN**, not merely asserted from architecture — a real test exercises the
exact scenario.

## E. srNoIndex Architecture

Unchanged from `SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md` Part R / `SRNO_RE_ADMISSION_IMPLEMENTATION_REPORT.md`
§2 — re-confirmed still accurate against the current code:

- Doc id: `{schoolId}_{srNoNorm}` — **CONFIRMED**, `Sr_no_service.php` uses `$this->fs->docId($n['norm'])`
  consistently for every read/write/create against this collection (this consistency was itself a
  bug in the prior pass, caught and fixed then — re-verified still correct now).
- Fields: `srNo`, `currentStudentId`, `historicalStudentIds[]` (derived cache), `history[]` (bounded,
  cap 20, authoritative-alongside-`auditLogs`), `claimedAt`, `claimedBy`, `schoolId`, `updatedAt`.
- Claim: atomic create-if-not-exists (`claim()`).
- Release: not implemented as a standalone operation — a student's SRNO is only ever "released" as a
  side effect of a relink (the old id moves to `historicalStudentIds`, never deleted).
- Relink: CAS-guarded, atomic 2-document batch (`relink()`).
- Concurrency: `commitBatch` with `updateTime` precondition on the index side.
- Security rules: `firestore.rules:1062-1069`, deny-all for direct client access — **re-read this
  pass, confirmed unchanged and still isolated** from the surrounding 573 pre-existing pending lines.
- Tenant isolation: doc-id prefixing, now cross-tenant-tested (§D).
- Duplicate prevention: `firestoreCreate`'s atomic 409-equivalent.
- Stale-index / orphan-index behavior: **NOT VERIFIED** — no test or code path was found or built for
  detecting an `srNoIndex` doc whose `currentStudentId` points at a Student ID that no longer exists
  (e.g., hard-deleted per Phase 0's `SL` findings). This is a real, disclosed gap, not previously
  named this explicitly: **if a student is hard-deleted, their `srNoIndex` entry is not cleaned up or
  flagged.** Low likelihood (hard-delete is rare and explicit per Phase 0), but genuinely unhandled.
- Deleted/withdrawn student behavior: `relink()`'s caller (`Readmission::confirm()`) already requires
  the old record's status to not be `Active`, which correctly covers withdrawn/TC'd students (§I).
- Re-admission behavior: §I/§V.

**The index represents SCHOOL + SRNO, not SRNO globally — CONFIRMED.**

## F. Multi-Tenant Isolation Audit

Walking Section 6's 11-point checklist:

1. **SRNO uniqueness enforced per school** — CONFIRMED, §D.
2. **SRNO can legally repeat across schools** — CONFIRMED, §D.
3. **`srNoIndex` cannot collide across schools** — CONFIRMED (distinct doc ids by construction).
4. **SRNO resolution always includes schoolId** — CONFIRMED (`resolve()` calls `docId()` internally,
   there is no code path that omits it).
5. **Re-admission relinking cannot cross school boundaries** — **CONFIRMED, new test this pass**:
   `test_relink_cannot_use_a_previous_student_id_that_only_exists_in_another_school` — School A
   attempting to relink against a Student ID that only exists in School B's namespace hits
   `expected_owner_mismatch` (School B's record is structurally invisible to School A's `$this->fs`).
6. **`previousStudentId` cannot point to a Student ID belonging to another school** — CONFIRMED by
   the same test and the same structural reasoning: `Readmission::_student()` can never fetch a
   cross-school doc, so the value it would compare against is always absent, never wrongly present.
7. **Student Trail cannot accidentally aggregate students across tenants** — **NOT VERIFIED / N/A**
   — Student Trail does not exist in this codebase (confirmed repeatedly across this entire
   conversation's audits). Nothing to verify yet; flagged as a requirement for whenever it is built.
8. **Student 360 cannot resolve an SRNO belonging to another tenant** — **NOT VERIFIED / N/A** —
   Student 360 integration was not built in the prior pass and remains unbuilt. The underlying
   `Readmission::resolve()` endpoint IS tenant-safe (it uses the calling controller's own
   `$this->fs`), so whenever Student 360 does integrate, it inherits this safety automatically — but
   that integration itself has not happened, so this item cannot be marked CONFIRMED for the feature
   as a whole.
9. **APIs cannot omit tenant context when resolving SRNO** — CONFIRMED for the one API that exists
   (`Readmission::resolve()`) — schoolId comes from the authenticated session (`MY_Controller`), never
   a client-supplied parameter, so it cannot be omitted or spoofed via the request.
10. **Firestore rules cannot allow cross-tenant SRNO resolution** — CONFIRMED — the rule is
    `allow read, write: if false` for ALL clients regardless of tenant; only server-side Admin SDK
    code (which always goes through the schoolId-bound `Sr_no_service`) can read it at all.
11. **Server-side validation cannot accidentally enforce global SRNO uniqueness** — CONFIRMED, §D's
    test directly disproves this concern (two schools claiming the same digits both succeed).

## G. Re-admission Workflow Audit

Matches the intended workflow in Section 11 exactly, with one explicit, disclosed deviation: **the
"search/identify existing student" and "operator sees possible previous record" steps are not
implemented** — no candidate-matching UI or endpoint exists. The workflow today requires the operator
to already know the prior Student ID (found via, e.g., ordinary SIS search) and type it into
`confirm()`'s `previous_student_id` field manually. This is consistent with the explicit instruction
that automatic matching (name/phone/DOB/APAAR) must never establish a link — the current
implementation is maximally conservative (zero automatic candidate surfacing at all) rather than
partially automatic, which is a legitimate, safe, if less convenient, interpretation of "human
confirmation is mandatory."

## H. previousStudentId Safety Audit

Walking Section 12's 11 points:

1. **STU0001 exists** — CONFIRMED, checked (`Readmission::confirm()`, `$oldStu === null` rejection).
2. **STU0001 belongs to the same school** — CONFIRMED structurally, §F.6.
3. **STU0047 belongs to the same school** — CONFIRMED, same mechanism (`_student()` on the new id).
4. **STU0001 is genuinely eligible as historical predecessor** — PARTIAL: checked that status is not
   `Active` and that it has a non-empty `srNo`. **NOT checked**: whether STU0001 itself is already
   someone else's `previousStudentId` target from a DIFFERENT chain (i.e., whether STU0001 has
   already been "spent" as a predecessor once) — nothing prevents linking two different new records
   to the same old one. **This is a genuine, newly-identified gap**, disclosed here, not fixed in
   this pass (see §X) — low real-world likelihood (would require two separate re-admission
   confirmations against the same historical record) but not structurally prevented.
5. **STU0047 is a new Student ID** — not independently re-verified as "freshly created" vs. an
   arbitrary existing active record, beyond the existing "must not already have a previousStudentId"
   check. An operator could theoretically call `confirm()` against an unrelated, already-Active
   student that was never freshly enrolled for this purpose — the RBAC gate (Principal-only) is the
   real control here, same as the existing "no defense-in-depth beyond the caller" philosophy
   disclosed in the prior report.
6. **STU0001 is not modified** — **CONFIRMED, tested** (`test_relink_only_writes_srNoIndex_and_the_new_student_doc`,
   `assertArrayNotHasKey` on the old record).
7. **The link does not create a cycle** — **CONFIRMED, tested, after this pass's fix** (§V).
8. **The link cannot point to itself** — CONFIRMED, tested (`same_student_id` rejection, both the
   original and new multi-tenant test files).
9. **The link cannot cross tenants** — CONFIRMED, §F.5/§F.6.
10. **The operation is authorized** — CONFIRMED (Principal-only gate, §M).
11. **The operation is auditable** — CONFIRMED (§ audit trail, unchanged from prior report).

## I. SRNO Preservation Audit

**CONFIRMED, tested.** `relink()` never issues a new SRNO — it moves ownership of the SAME digits.
`test_verified_readmission_relinks_srno_to_the_new_student` asserts the new student's `srNo` field
equals the value that was originally claimed. `test_repeated_readmission_chain_preserves_full_history`
confirms this holds across a chain of 3.

## J. Historical Data Preservation Audit

Unchanged from the prior report's §19 finding, re-confirmed: `relink()` touches exactly two
documents (`srNoIndex`, the NEW student's own record) — verified by
`test_relink_only_writes_srNoIndex_and_the_new_student_doc`. No fee/attendance/exam/discipline/
transport/hostel/document/certificate collection is referenced anywhere in `Sr_no_service.php` or
`Readmission.php` (confirmed by re-reading both files in full during this pass).

## K. Concurrency/CAS Audit

**CONFIRMED at the code/simulated level; explicitly NOT VERIFIED under true concurrency** (stated
per Section 28's instruction, not converted into a false PASS):

- The precondition mechanism (`commitBatch` rejects the WHOLE batch if `updateTime` doesn't match)
  is unchanged from the prior pass and re-confirmed correct by reading `Firestore_rest_client.php`'s
  real implementation again this pass.
- `test_concurrent_readmission_only_one_relink_succeeds` remains a **deterministic simulation**, not
  a true multi-process race — this limitation is repeated here explicitly rather than left only in
  the prior report, per Section 28's instruction not to let an old caveat go stale/forgotten.
- **New this pass:** the circular-link check itself runs BEFORE the CAS loop, using a plain (non-CAS)
  read of each ancestor's `previousStudentId`. This is intentionally read-only and side-effect-free —
  it cannot itself introduce a race, because it never writes; if a concurrent write changes an
  ancestor's chain between this check and the later CAS-guarded write, the CAS precondition on the
  `srNoIndex` side still catches any resulting inconsistency in ownership (though a theoretical
  narrow window exists where a cycle could be introduced via a *different* concurrent relink call
  targeting a different SRNO entirely — extremely narrow, not exploitable through the primary
  `srNoIndex` CAS path, but named honestly as a residual theoretical gap rather than claimed airtight).

## L. Atomicity/Failure Recovery Audit

Walking Section 20's six cases:

- **Case 1 (student creation succeeds, SRNO claim fails):** **N/A today** — ordinary-admission SRNO
  claiming is still not wired into `Sis::enroll_student()` (disclosed, unchanged, §X). This failure
  mode cannot currently occur because the two operations are not yet joined at all.
- **Case 2 (SRNO ownership succeeds, previousStudentId write fails):** **Cannot happen** — both are
  one `commitBatch` call; Firestore's batch commit is all-or-nothing. Verified by the fake's own
  precondition-checked-then-applied-together implementation, which mirrors real Firestore semantics.
- **Case 3 (previousStudentId succeeds, SRNO ownership fails):** **Cannot happen**, same reasoning.
- **Case 4 (timeout after server-side success, retry):** **CONFIRMED safe** — `test_double_submitted_relink_is_idempotent_no_duplicate_ownership`.
- **Case 5 (double-click):** **CONFIRMED safe**, same mechanism as Case 4.
- **Case 6 (two admins, same re-admission, simultaneous):** **CONFIRMED safe at the simulated level**
  (§K), with the same true-concurrency caveat stated there.

## M. RBAC Audit

**Unchanged from the prior report — re-stated, not silently dropped.** `Readmission::confirm()` uses
a **hard-coded role-name check** (`Super Admin`, `School Super Admin`, `Principal`), NOT the graded
`SIS:manage` capability, because that graded capability is broader (includes Admin/Vice
Principal/Front Office) than the design's stated Principal-level intent. **This is explicitly a
disclosed compromise, not a resolved decision** — the original SR design's own §11.3 left this open;
no new graded RBAC capability was added in either pass, and none was silently introduced (per Section
22's explicit instruction not to). Section 22's specific role questions:

- Search for historical student: no dedicated endpoint exists (§G).
- View candidate matches: N/A, no candidate-matching feature exists.
- Confirm previousStudentId / relink SRNO: Principal/SA/SSA only, hard-coded check.
- Correct SRNO (typo fix, distinct from relink): **not implemented at all**, no role question
  applicable yet.
- View Student ID / APAAR / historical Student Trail: unaffected by this work — those are Student
  360/Trail concerns, neither of which this work integrates with (§F.7/§F.8).

## N. Security Audit

Re-walked the adversarial list from the prior report; nothing new found this pass beyond §V (already
fixed) and the two newly-named-but-unfixed gaps in §H.4/§H.5/§X. No IDOR was found in `Readmission.php` —
every id in every request is either school-scoped by construction (`_student()`) or validated against
live Firestore state before use; nothing is trusted from the client for a security decision.

## O. Student 360 Integration Audit

**NOT INTEGRATED — unchanged.** `Student360.php`/`Student_context.php` were not touched in either
pass. `Readmission::resolve()` exists as the API a future integration would call, tenant-safe by
construction (§F.8).

## P. Student Trail Integration Audit

**NOT INTEGRATED, and Student Trail itself does not exist as a feature anywhere in this codebase** —
confirmed repeatedly across every audit in this conversation. `Sr_no_service::getFullRecord()`
remains the intended future read surface; nothing calls it.

## Q. APAAR Boundary Audit

**Unaffected by this entire body of work — CONFIRMED by absence.** Neither `Sr_no_service.php` nor
`Readmission.php` references APAAR in any form. APAAR remains exactly what
`STUDENT_LIFECYCLE_PHASE1_IDENTITY_DECISION.md`'s dedicated APAAR section already established: design-
only, no live writer, not touched by any code in this implementation pass.

## R. Application Number Audit

**Unchanged from prior findings, not re-litigated here per the instruction not to reopen settled
decisions without a direct technical contradiction — none was found.** The dual-generator
inconsistency (`APP#####` vs. `APP_hex_random`) remains exactly as documented in Phase 0; this pass
did not touch either generator.

## S. Admission Number Audit

**Unchanged.** The existing (unimplemented) design decision — `Admission Number` dual-emits `srNo` so
`Certificates.php` needs zero changes — was **preserved, not touched**. This implementation pass does
not yet write to any `Admission Number`-shaped field (the SRNO-claim-into-enrolment wiring that would
do this remains unbuilt, §X) — no competing identity system was created, consistent with Section 17's
instruction.

## T. Roll Number Audit

**Unaffected, unchanged.** No file touched in either implementation pass references Roll Number.

## U. Test Matrix

Full status against the 33-item Section 24 list. **CONFIRMED** = executed and passing this pass;
**PRE-EXISTING** = covered by the prior report's test file, re-run and still passing;
**NOT TESTED** = honestly unverified, named per Section 28's instruction.

| # | Scenario | Status |
|---|---|---|
| 1 | First admission, School A, SRNO 1033 | **CONFIRMED** (new) |
| 2 | First admission, School B, SRNO 1033 — MUST PASS | **CONFIRMED** (new) |
| 3 | Duplicate SRNO within School A — MUST FAIL | **CONFIRMED** (new) |
| 4 | Same SRNO across different schools — MUST PASS | **CONFIRMED** (new) |
| 5 | Student ID global uniqueness | **CONFIRMED** by code inspection (§C) — no test needed, the counter's storage key structurally has no schoolId to test against |
| 6 | Re-admission preserving SRNO | **PRE-EXISTING**, re-run, passing |
| 7 | Re-admission creating new Student ID | **PRE-EXISTING**, re-run, passing |
| 8 | previousStudentId creation | **PRE-EXISTING**, re-run, passing |
| 9 | Historical Student ID untouched | **PRE-EXISTING**, re-run, passing |
| 10 | Historical SRNO remains attached to historical record | **PRE-EXISTING**, re-run, passing |
| 11 | Multiple re-admissions | **PRE-EXISTING** + **CONFIRMED (new, deeper — 3-hop cycle test)** |
| 12 | Circular-link prevention | **BROKEN, then FIXED this pass, now CONFIRMED** — see §V |
| 13 | Self-link prevention | **PRE-EXISTING**, re-run, passing; re-asserted in new file too |
| 14 | Cross-school previousStudentId prevention | **CONFIRMED** (new) |
| 15 | Concurrent SRNO claim | **NOT TESTED directly this pass** — the ORDINARY claim path (`firestoreCreate`) has no CAS-retry loop to race in the first place (it's a single atomic create, inherently race-free by Firestore's own guarantee); concurrent CLAIM of the SAME digits is exactly test #2/#3's mechanism (one wins, one gets `duplicate`) |
| 16 | Concurrent re-admission | **CONFIRMED, simulated** (§K caveat applies) |
| 17 | Stale relink request | **CONFIRMED** — covered by idempotency test's re-read-fresh-state mechanism |
| 18 | Duplicate confirmation request | **PRE-EXISTING**, re-run, passing |
| 19 | Failed SRNO claim | **CONFIRMED** — `duplicate` reason path, tested |
| 20 | Failed previousStudentId write | **N/A — cannot happen**, §L Case 3 |
| 21 | Transaction retry | **CONFIRMED**, §L Case 4 |
| 22 | Student 360 SRNO resolution | **NOT TESTED — feature not integrated**, §O |
| 23 | Student Trail historical aggregation | **NOT TESTED — feature does not exist**, §P |
| 24 | Cross-tenant Student 360 attempt | **NOT VERIFIED / N/A**, §F.8 |
| 25 | Cross-tenant SRNO lookup | **CONFIRMED** (new) |
| 26 | Unauthorized relink | **PRE-EXISTING** (RBAC gate code-reviewed, not unit-tested — requires CI bootstrap) |
| 27 | Unauthorized SRNO correction | **N/A — correction feature not implemented**, §M |
| 28 | APAAR must not resolve student | **CONFIRMED by absence** — zero APAAR references anywhere in this code, §Q |
| 29 | Existing Student ID relationships remain valid | **CONFIRMED** — nothing in either pass touches `Id_generator.php`'s STU logic or any existing student doc's identity fields |
| 30 | Existing accounting records untouched | **CONFIRMED by code isolation** (§J) — no live accounting data exists in this test environment to assert against directly |
| 31 | Existing attendance records untouched | **CONFIRMED by code isolation** (§J), same caveat |
| 32 | Existing result records untouched | **CONFIRMED by code isolation** (§J), same caveat |
| 33 | Existing documents/certificates untouched | **CONFIRMED by code isolation** (§J), same caveat |

**Full suite:** `vendor/bin/phpunit --testsuite Unit` → **634 tests, 10 failures.** The 10 are the
**exact same 10** as the pre-existing baseline before this session's work began (`BackupTest`
format-drift, Homework/Teacher-cascade cross-repo-path tests) — confirmed by name-for-name comparison
against the failure list captured before this pass's changes. **Zero new failures.**
New SRNO-related tests: **19/19 passing, 72 assertions** (12 original + 7 new this pass).

## V. Bugs Found

**One real, previously-undetected defect, found by adversarial reasoning per this prompt's explicit
instruction to review the "already implemented" code adversarially rather than trust it:**

**BUG — circular `previousStudentId` chain not prevented.** Before this pass, nothing stopped an
operator from calling `Readmission::confirm(student_id=STU0001, previous_student_id=STU0047)` AFTER
an earlier, legitimate `confirm(student_id=STU0047, previous_student_id=STU0001)` had already run —
producing `STU0047.previousStudentId=STU0001` AND `STU0001.previousStudentId=STU0047` simultaneously,
a genuine 2-node cycle. The existing "new record must not already have a previousStudentId" check did
NOT catch this, because in the reversed call, `STU0001` (playing the "new" role) had never had that
field written — only the forward direction did. **Severity: HIGH if it had shipped** — a circular
chain would make any future Student Trail aggregation (§P) infinite-loop or produce nonsensical
"history," and would corrupt the very invariant (a clean, walkable ancestor chain) the whole Strategy
A architecture depends on.

## W. Fixes Made

1. **Circular-link guard added inside `Sr_no_service::relink()`** (not only the controller) — a
   bounded (depth 25) backward walk from the asserted OLD id's own `previousStudentId` chain,
   rejecting if the proposed NEW id is found anywhere in it. Placed at the service layer specifically
   to close the previously-disclosed "no defense-in-depth" gap (`SRNO_RE_ADMISSION_IMPLEMENTATION_REPORT.md`
   §23) at the same time.
2. **First attempt at this fix was itself buggy** (walked the wrong direction, from the NEW id
   instead of the OLD id) and broke the legitimate idempotent-retry test — caught immediately by
   re-running the test suite, not shipped. Corrected to walk from the OLD id's own chain, which
   correctly distinguishes "this is the same direct link being re-confirmed" (safe, no false
   positive) from "this would close a genuine loop" (rejected). This self-correction is disclosed
   here deliberately, per Section 28's instruction not to hide the process, not just the destination.
3. **`FakeSrNoFirestore` refactored** to share a `FakeSrNoBackingStore` across multiple school-scoped
   instances, enabling genuine cross-tenant tests that were structurally impossible with the prior
   single-fake design (the prior fake could not have caught a cross-tenant bug even if one existed).
4. **7 new tests added** (`SrNoServiceMultiTenantTest.php`) covering cross-tenant claim independence,
   in-school duplicate rejection, cross-tenant lookup isolation, cross-tenant `previousStudentId`
   rejection, 2-hop and 3-hop circular-link rejection, and self-link rejection.

## X. Remaining Gaps

Carried forward, honestly, none silently dropped:

1. **Ordinary-admission SRNO claiming not wired into `Sis::enroll_student()`** — same deliberate
   blast-radius decision as the prior pass, unchanged.
2. **No dedicated RBAC capability** — hard-role-check compromise remains open (§M).
3. **No `correct_sr_number` (typo correction) implementation** — unchanged.
4. **No UI** — unchanged.
5. **New this pass, §H.4:** nothing prevents linking two different new Student IDs to the SAME
   historical predecessor (double-spending an old record as a predecessor). Low real-world
   likelihood, not structurally prevented.
6. **New this pass, §E:** no stale/orphan-index detection if a student is hard-deleted after being
   an SRNO's current or historical owner.
7. **True multi-process concurrency remains untested** — every concurrency claim in this and the
   prior report is a deterministic single-process simulation of the code path a real race would hit,
   not an actual race.

## Y. Production Readiness Verdict

**READY WITH CONDITIONS.**

The backend core (`Sr_no_service.php`, `Readmission.php`, the `srNoIndex` rule, `commitBatch`) is
sound, tenant-isolated, atomic, idempotent, and now cycle-safe. It is not production-*usable* as a
feature because it is not reachable from any UI and not wired into ordinary admission — that is a
scope/completeness gap, not a correctness defect in what exists.

**Blockers before this could be called a complete, shippable feature (not "unsafe," but
"incomplete"):**

- **HIGH:** wire `Sr_no_service::claim()` into `Sis::enroll_student()` — without this, SRNO is
  invisible to the actual admission workflow and the feature has no ordinary entry point.
- **HIGH:** build at minimum a UI for `Readmission::confirm()` — a Principal cannot use a feature
  that only exists as an unauthenticated-by-browser API endpoint.
- **MEDIUM:** replace the hard-role-check RBAC compromise with a proper graded capability.
- **MEDIUM:** close §H.4 (double-spent predecessor) and §E (orphan-index on hard-delete).
- **LOW:** true-concurrency testing (multi-process/staging), given the deterministic simulation
  already exercises the correct code path and the underlying Firestore primitives are proven
  elsewhere in this codebase.

**No CRITICAL blocker was found or reintroduced.** The one HIGH-severity code defect found this pass
(circular-link) was fixed and verified before this report was written, not merely noted.

---

## Invariants — proven from code and tests, per Section 32

1. **Student ID is globally unique across ZenX.** PROVEN — §C, code inspection, no schoolId anywhere
   in `Id_generator.php`'s STU path.
2. **SRNO is unique only within a school.** PROVEN — §D, test.
3. **The same SRNO can legally exist in multiple schools.** PROVEN — §D, test.
4. **SRNO resolution is schoolId + SRNO → Student ID → canonical student.** PROVEN — §E/§F, code +
   test.
5. **Student ID remains the canonical backend identity.** PROVEN — unchanged from every prior audit,
   re-confirmed no code in this pass contradicts it.
6. **SRNO remains the human-facing operational identifier.** DESIGN DECISION, unchanged, not
   re-litigated.
7. **Re-admission creates a NEW Student ID.** PROVEN — `relink()` never reuses or rewrites
   `expectedOldStudentId`'s own identity; `Readmission::confirm()` operates on an already-separately-
   created new id.
8. **Re-admission preserves the SAME SRNO for the same school.** PROVEN — §I, test.
9. **New Student ID contains a human-confirmed previousStudentId relationship.** PROVEN for the
   "written by this code" half (§H); the "human genuinely confirmed it" half is a workflow/RBAC
   property, not a code property — the RBAC gate (§M) is what makes this true, and it is Principal-
   only, code-reviewed.
10. **Historical Student ID and all historical records remain untouched.** PROVEN — §J, test.
11. **Two concurrent re-admissions cannot both become valid current owners of the same school's
    SRNO.** PROVEN at the simulated level (§K); NOT VERIFIED under true concurrency (stated
    honestly).
12. **A Student ID from School A can never be linked to a Student record from School B.** PROVEN —
    §F.5/§F.6, new test.
13. **School A SRNO 1033 and School B SRNO 1033 are completely independent.** PROVEN — §D, test.
14. **APAAR is only a student attribute and never an identity or join key.** PROVEN by absence — §Q,
    zero references anywhere in this code.
15. **Student Trail can reconstruct the complete historical chain without physically merging
    historical records.** DESIGN PROVEN SOUND (the `previousStudentId` chain is walkable and now
    provably cycle-free, §V) but the reconstruction code itself does not exist yet (§P) — the
    precondition for this invariant to hold once built is satisfied; the invariant itself cannot be
    fully proven until Student Trail exists to test against.

---

**Nothing in this pass was committed, staged, reset, stashed, or pushed.** Every file touched in this
validation pass was already touched by the prior implementation pass (`Sr_no_service.php`,
`Readmission.php`, `tests/Unit/SrNoServiceTest.php`, `tests/Support/FakeSrNoFirestore.php`) plus two
genuinely new files (`tests/Support/FakeSrNoBackingStore.php`,
`tests/Unit/SrNoServiceMultiTenantTest.php`). No pre-existing pending work in `Sis.php`,
`rbac_helper.php`, or `firestore.indexes.json` was touched, reverted, or reset — verified by `git
status` before and after this pass.
