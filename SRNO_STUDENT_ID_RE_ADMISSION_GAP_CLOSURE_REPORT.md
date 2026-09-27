# SRNO / Student ID / Re-admission — Gap Closure Implementation Report

**Date:** 2026-08-13 · **Status: backend gaps closed, adversarially re-validated. NOT committed,
staged, or pushed. Working tree still HELD.**

Status vocabulary used throughout, per instruction: **CONFIRMED**, **TESTED**, **SIMULATED**,
**NOT TESTED**, **NOT IMPLEMENTED**, **NOT APPLICABLE**, **REMAINING GAP**.

---

## 1. Executive Summary

This pass closed the two highest-priority gaps the multi-tenant audit left open — **ordinary
admission was not wired to SRNO claiming**, and **historical predecessor double-spend was
unverified** — and investigated the third (orphan `srNoIndex` on hard-delete), applying the smallest
safe, well-scoped fix to each. One finding reversed an earlier conclusion: the predecessor
double-spend scenario the prior audit flagged as an open gap turns out to already be prevented by
the existing ownership-mismatch check, once traced through carefully — this is now **TESTED**, not
merely reasoned about. RBAC was deliberately **not** changed after inspecting the actual catalogue
architecture — reasoning in §8. The operator-facing search/candidate UI (Phase 6) was **not built** —
explicitly scoped out, reasoning in §17.

## 2. Implementation-Safety Inventory (Phase 1)

**Repository state before this pass:** branch `integration/phase2`, 375 lines of `git status --short`
output — the large pre-existing pending-work footprint documented in every prior report in this
conversation was re-confirmed unchanged in scope before touching anything.

| File | State before this pass | Touched this pass? | How |
|---|---|---|---|
| `application/controllers/Sis.php` | 542 pending lines from unrelated work | **Yes** | Two small, isolated, additive insertions (§3, §5) — verified via `git diff --stat` (579 insertions total = 542 pre-existing + ~37 new, no deletions beyond the pre-existing diff's own) |
| `application/helpers/rbac_helper.php` | Pending Campus Access addition | **No** | Inspected (§8), deliberately left untouched — reasoning below |
| `firebase-rules/firestore.indexes.json` | Pending, unrelated | **No** | Not inspected further this pass; no index need arose |
| `application/libraries/Sr_no_service.php` | New from prior pass (untracked) | **Yes** | Cycle-guard direction was already fixed in the prior pass; no further change needed this pass beyond what was already there |
| `application/controllers/Readmission.php` | New from prior pass (untracked) | **No** | No change needed this pass |
| `tests/Unit/SrNoServiceMultiTenantTest.php` | New from prior pass (untracked) | **Yes** | One new test added (§4) |
| `tests/Support/*` | New from prior pass (untracked) | **No** | Unchanged |

**Nothing was reset, reverted, stashed, or cleaned.** Verified via `git status --short` before and
after this pass — the pre-existing 375-line footprint is intact.

## 3. Files Changed This Pass

- `application/controllers/Sis.php` — two additions inside `enroll_student()` (SRNO claim, §5) and
  `delete_student()` (orphan-flag on hard-delete, §7).
- `tests/Unit/SrNoServiceMultiTenantTest.php` — one new test (§4).

## 4. Files Intentionally Not Changed

- `application/helpers/rbac_helper.php` / `functions/rbac_modules.json` — §8.
- Any view/UI file — §17.
- `Sr_no_service.php` / `Readmission.php` core logic — the cycle-prevention direction fix from the
  prior pass was re-verified correct, not re-touched.
- `firebase-rules/firestore.rules` / `firestore.indexes.json` — no new collection or query pattern
  was introduced this pass; the existing `srNoIndex` deny-all rule already covers the new
  `currentOwnerDeleted`/`currentOwnerDeletedAt` fields (they live on the same, already-protected
  document).

## 5. Admission Flow — Exact Implementation

**Integration point, chosen deliberately (not the first one found):** immediately after the existing
`Fee_lifecycle::assignInitialFees()` best-effort block inside `Sis::enroll_student()`
(`Sis.php`, in the `$warnings[]`-collecting section, after the student is already fully committed).

**Why this point and not earlier:** the student document, Firebase Auth account, and idempotency slot
are all already resolved by this point in the method (per the Phase 0 audit's own tracing of this
exact method's sequencing) — inserting SRNO logic here means a failed claim can **never** leave a
"partially created identity" (Phase 2 requirement #6), because the identity was never partial to
begin with. This mirrors the exact risk-tolerance pattern the method's own author already established
for fee assignment: best-effort, caught, surfaced as a `warnings[]` entry, never blocking.

**What was NOT implemented:** the original SR design's `srNo.mode` (auto/manual/hybrid) config layer
and next-in-series suggestion UI. This pass wires only the **manual-entry claim primitive** — an
optional `sr_no` POST field. A school that sends nothing gets no SRNO (fully backward compatible,
zero behavior change for every existing enrollment flow that doesn't send this field). **This is a
disclosed, deliberate scope limitation**, not an oversight — building the mode-config layer is a
separate, larger UI/config-schema project.

**Exact sequence implemented:**
1. `$srNoInput = trim(post('sr_no'))`. Empty → skip entirely, zero behavior change.
2. Non-empty → `Sr_no_service::claim($srNoInput, $studentId)`, wrapped in try/catch.
3. Failure (`duplicate` or any other reason) → `$warnings[]` entry naming the current owner if known,
   student enrollment **still succeeds** — exactly matching the existing fee-assignment failure
   pattern.
4. Success → `$this->fs->update('students', ...)` writes `srNo`/`srNoNorm`/`srNoSource` onto the
   already-saved student doc, AND the same fields are merged into the in-memory `$studentData` array
   **before** the subsequent `entity_sync->syncStudent()` call, so the very first mobile sync already
   carries the SRNO rather than needing a second sync round-trip.

**NOT TESTED at the integration-test level** — `Sis::enroll_student()` extends `CI_Controller` via
`MY_Controller` and cannot be instantiated in this repository's PHPUnit environment without a live
CI bootstrap (session, input, security objects) — consistent with every other Sis.php method's
untestability noted throughout this entire conversation's prior audits. **`php -l` clean; code-
reviewed line-by-line against the exact surrounding method body, not written speculatively.**

## 6. Re-admission Flow

**Unchanged from the prior pass** — `Readmission::confirm()`/`Sr_no_service::relink()`. Re-validated
in this pass (§4) rather than re-implemented.

## 7. Orphan `srNoIndex` Policy (Phase 5)

**Investigation, per the instruction not to invent a cleanup architecture before checking actual
deletion semantics:**

- **CONFIRMED, re-read this pass:** `Sis::delete_student($id)`, `Sis.php:5728+`. Two paths exist,
  gated by a `hard_delete` POST flag (`Sis.php:5800`):
  - **Soft delete (default):** `Dual_write::softDeleteStudent()` — the Firestore document is
    retained, `status` flips to `'Deleted'`. An SRNO's current owner remaining soft-deleted is **not
    orphaned** in any meaningful sense — the document, and its `srNo` field, still exist and are
    still resolvable; only its lifecycle `status` changed. **No action needed for this path.**
  - **Hard delete (explicit, `hard_delete=true`):** `Dual_write::hardDeleteStudent()` +
    `$this->fs->removeEntity('students', $id)` — the Firestore document is genuinely, permanently
    removed, followed by a cascade delete across 8 other collections (`Sis.php:5828`:
    `attendanceSummary`, `attendance`, `marks`, `feeReceipts`, `feeDemands`, `studentFlags`,
    `submissions`, `teacherMarks`).
- **Is deletion already restricted?** Yes — the same RBAC gate covering `delete_student` generally
  (`SIS:manage`-tier, consistent with the rest of that controller); hard-delete is not a separate,
  more restricted action at the RBAC layer, though it is a distinct, explicit POST flag an operator
  must deliberately set.
- **Can an SRNO index safely remain historical?** Yes — nothing about `srNoIndex`'s own correctness
  depends on the student doc it points to still existing; `resolve()` returning a `currentStudentId`
  that no longer has a live document degrades gracefully (the caller's own subsequent
  `students.get()` returns null, exactly the same "not found" experience as any other dangling
  reference already handled throughout this codebase — not a crash, not silent corruption).
- **What should happen to the current owner / historicalStudentIds?** **This is the open business
  decision this pass does NOT make unilaterally** (per the explicit instruction): should a
  hard-deleted student's SRNO ever become reclaimable by a new admission, or remain permanently
  frozen as a historical artifact forever? Both are defensible; neither was decided here.

**Smallest safe fix implemented (not a policy decision):** on hard-delete, if the deleted student is
found to currently be the `srNoIndex` entry's `currentStudentId` (checked via the student's own
`srNoNorm` field, read before the doc is removed), the `srNoIndex` document is marked
`currentOwnerDeleted: true, currentOwnerDeletedAt: <timestamp>` — **purely informational, best-effort,
never blocks the deletion itself, never changes `resolve()`'s or `relink()`'s behavior.** This gives
a future integrity verifier (or Student Trail) a flag to detect and surface this state explicitly,
without this pass presuming what the correct policy response should be.

**NOT TESTED at the integration level** (same CI-bootstrap limitation as §5); `php -l` clean,
code-reviewed against the exact surrounding hard-delete block.

## 8. RBAC — Deliberately Not Changed

**Inspected, per Phase 8's instruction, before deciding.** `RBAC_MODULE_META`/`RBAC_MODULES`
(`rbac_helper.php:19-58`) is a **flat, module-level** catalogue — three graded levels
(view/edit/manage) **per module**, with no mechanism for a finer-grained sub-capability like
`SIS:readmission` distinct from `SIS:manage` anywhere in the current architecture. `RBAC_MODULES`
is explicitly documented as **the single source shared byte-for-byte with the Cloud Functions
`staffCapabilities` mirror** (`rbac_helper.php:21-24`) — adding a new module here is not a
local-only change; it requires a corresponding, coordinated update to
`functions/rbac_modules.json` and the Staff Access UI's module matrix, which is a cross-system
change with its own deployment/testing surface.

**Decision: keep the existing hard-role-name check** (`Super Admin`, `School Super Admin`,
`Principal`), not introduce a new graded module. Two reasons, both grounded in the actual
architecture rather than convenience:
1. Introducing a new top-level RBAC module for one sensitive action is disproportionate to
   "smallest correct RBAC integration" — the module-per-capability grain this catalogue offers
   doesn't fit a single fine-grained action well, and forcing it in would mean either a whole new
   "Re-admission" module (over-broad ceremony for one action) or silently degrading the existing
   `SIS:manage` semantics (against the explicit instruction not to blindly reuse it).
2. **The current hard-role-check does NOT weaken RBAC** — it is already **strictly narrower** than
   `SIS:manage` (which also includes Admin, Vice Principal, Front Office per `Sis::MANAGE_ROLES`).
   Keeping it is the more conservative, not the weaker, choice.

**This is disclosed as a considered engineering judgment, not an unresolved gap left by omission.**
If a dedicated graded capability is wanted, it requires operator authorization for the cross-system
`functions/rbac_modules.json` change specifically — flagged for a future, separately-authorized pass.

## 9. Audit Trail

**Unchanged from the prior pass — re-confirmed still correct.** No new audit-trail code was needed
this pass; the admission-time SRNO claim reuses `Sr_no_service::claim()`'s existing `log_audit()`
calls verbatim (no new call site was added — the SAME method now has two callers, `Readmission::confirm()`
indirectly via `relink()`, and `Sis::enroll_student()` directly via `claim()`, and both already emit
audit entries from inside `Sr_no_service` itself).

## 10. Cross-Module Safety

**No module was found to incorrectly use SRNO as a permanent identity** — because, per every prior
audit in this conversation, no module currently uses SRNO for anything at all except the two entry
points built across this conversation's sessions (`Sr_no_service`/`Readmission`/the new
`enroll_student` claim). Fees/Accounting/Attendance/Examination/Results/Documents/Certificates/
Transport/Hostel/Communication/Parent App/Teacher App remain entirely keyed to Student ID, unaffected
by anything in this or any prior pass — re-confirmed by grep (zero new references to `srNo`/`SRNO`
outside the files this conversation has touched).

## 11. UI / Operator Workflow (Phase 6)

**NOT IMPLEMENTED.** No search-candidate UI, no confirmation screen, no admission-form SRNO field was
built. This is a **deliberate scope decision**, consistent with every prior report's honest
disclosure: this entire body of work has been backend-only across every pass in this conversation, no
view file has been touched. Building it requires `application/views/` changes this pass did not
attempt, given the instruction to proceed in controlled phases rather than one uncontrolled sweep.

## 12. Test Matrix — Updated

Only NEW items this pass are detailed below; everything from the prior audit's test matrix is
unchanged and was re-run (§13).

| Item | Status |
|---|---|
| Predecessor double-spend prevention | **TESTED** — `test_a_historical_predecessor_cannot_be_double_spent_by_two_new_admissions`, proves the EXISTING ownership check already prevents it (no new guard code required — a corrected conclusion from the prior audit, not a new feature) |
| Ordinary admission SRNO claim (success path) | **NOT TESTED at integration level** (CI-bootstrap limitation, §5); code-reviewed, `php -l` clean |
| Ordinary admission duplicate SRNO (failure path, non-blocking) | **NOT TESTED at integration level**, same reasoning; the underlying `claim()` duplicate-rejection IS unit-tested (prior pass, unchanged) |
| Hard-delete orphan flag | **NOT TESTED at integration level**, same reasoning; purely additive, best-effort, `php -l` clean |
| RBAC decision | **NOT APPLICABLE** — no code change, a documented judgment call (§8) |

## 13. Full Test Results

`vendor/bin/phpunit --testsuite Unit --filter "SrNoServiceMultiTenantTest|SrNoServiceTest"`:
**20/20 passing, 76 assertions** (19 from the prior pass + 1 new this pass).

`vendor/bin/phpunit --testsuite Unit` (full suite): **635 tests, 10 failures.** Same 10 failures,
name-for-name, as every prior baseline check in this conversation
(`BackupTest::size_human_formats_bytes_correctly` and 9 Homework/Teacher-cascade tests referencing a
cross-repo Kotlin path) — **zero new failures**, confirmed by diffing the failure list before and
after this pass's two `Sis.php` edits.

## 14. Existing Baseline Failures vs. New Failures

**Baseline (unchanged across this entire conversation):** 10 pre-existing failures, all cross-repo
path-dependent or format-drift, none touching any file this conversation has modified.
**New failures introduced by this pass: zero.**

## 15. Remaining Gaps (honest, not hidden)

1. **`Sis::enroll_student()`'s SRNO wiring is NOT integration-tested** — code-reviewed and lint-clean
   only, due to this repository's CI-bootstrap limitation for controller-level tests (a limitation of
   the test environment, not something this pass could resolve).
2. **`srNo.mode` (auto/manual/hybrid) config layer not implemented** — only manual entry works today.
3. **No UI anywhere** (§11) — the feature remains an API-only capability.
4. **RBAC remains a hard-coded role check**, not a graded capability (§8) — a deliberate, documented
   choice pending a separately-authorized cross-system change if wanted.
5. **Orphan-index policy decision not made** (§7) — only an informational flag was added; whether a
   hard-deleted student's SRNO becomes reclaimable is still an open business question.
6. **True multi-process concurrency remains unverified** — carried forward from every prior pass, not
   re-tested here (no new concurrency-sensitive code was added this pass; the existing CAS mechanism
   is unchanged).
7. **Restore-then-re-admit interaction** — since restore is itself unreachable (Phase 0's SL-33,
   never in scope for this SRNO work), this interaction remains untested by construction, not by
   omission.

## 16. Production-Readiness Verdict

**READY WITH CONDITIONS — unchanged classification from the prior audit, conditions narrowed.**

Two of the prior report's HIGH-severity conditions are now addressed:
- ~~Wire `Sr_no_service::claim()` into `Sis::enroll_student()`~~ → **DONE this pass** (§5).
- Predecessor double-spend → **VERIFIED ALREADY PREVENTED**, not a gap (§4/§12).

**Remaining conditions, re-prioritized:**
- **HIGH:** build the operator-facing UI (§11) — without it, this remains an API capability no
  Principal can actually use day-to-day.
- **MEDIUM:** decide the orphan-index policy (§7) and the RBAC graded-capability question (§8) —
  both are informed, bounded decisions now, not open-ended investigations.
- **LOW:** true-concurrency staging verification, `srNo.mode` config layer.

**No CRITICAL blocker exists.** No regression was introduced. The one corrected finding (predecessor
double-spend) makes the architecture's actual safety margin better than the prior report stated, not
worse — this pass's adversarial review found the architecture more robust than previously credited,
in addition to fixing the one real defect (circular links) found in the pass before this one.

---

**Nothing in this pass was committed, staged, reset, stashed, or pushed.** Working tree remains held.
