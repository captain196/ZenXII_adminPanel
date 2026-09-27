# SRNO Re-Admission Implementation Report — Strategy A

**Date:** 2026-08-13 · **Status: Backend core implemented and unit-tested. NOT integrated into any
UI. NOT committed, staged, or pushed.**

**Scope honesty up front:** this delivers a working, tested, atomic SRNO-relink primitive and one
new Principal-gated controller endpoint that exercises it end-to-end at the code level. It does
**not** deliver a user-facing feature — no Student 360/Student Trail/SIS-search wiring was built.
Section 20 below lists exactly what remains before an operator could click through this in a
browser. Nothing here overclaims completeness the acceptance checklist (Section 30 of the original
prompt) would not actually support — several boxes on that checklist are marked open, honestly,
in Section 24.

---

## 1. Existing architecture discovered

Re-confirmed before writing any code (per Section 1's instruction not to trust the design docs as
implementation):

- `srNoIndex`, all SRNO claim/correction logic: **zero matches** anywhere in `application/` —
  design-only, exactly as the prior audits found. No regression risk from "assuming it existed."
- `previousStudentId`, `linkedStudentIds`: zero matches — genuinely new fields, introduced here for
  the first time.
- Reusable primitives found and reused (no new framework invented, per Section 6's instruction):
  - `Firestore_service::casUpdateEntity()` / `Firestore_rest_client::casAppendBounded()`
    (`application/libraries/Firestore_service.php:820-870`, `Firestore_rest_client.php:1040-1180`) —
    the CAS-with-abort and bounded-recent-cache primitives already used throughout this codebase
    (Transport trip state, CRM application status).
  - `Firestore_rest_client::commitBatch()` (`Firestore_rest_client.php:953+`) — atomic multi-document
    batch commit with per-op preconditions. **This existed but was not exposed on
    `Firestore_service`** — the one genuinely new capability this pass adds to a shared library (see
    §2).
  - `Id_generator::_tryClaim()` (`Id_generator.php:533-551`) — confirmed the exact
    create-if-not-exists pattern `Sr_no_service::claim()` mirrors for ordinary SRNO allocation.
  - `log_audit()` (`application/helpers/audit_helper.php:35`) — the existing global, silently-
    degrading audit writer, autoloaded on every controller via `MY_Controller.php:287`. Reused as-is,
    not reimplemented.
  - `Entitlements.php` / `Entitlement_service.php` — the "one controller, one service, no direct
    collection writes elsewhere" pattern this implementation's `Readmission.php`/`Sr_no_service.php`
    pair deliberately mirrors.

---

## 2. Final srNoIndex schema (and why it differs from the audit's original proposal)

Per Section 2's explicit instruction not to blindly implement the prior audit's 7-field proposal,
here is the hardening review's outcome, documented **before** the code that implements it
(`Sr_no_service.php`'s own docblock carries the same reasoning, so the decision travels with the
code):

| Original proposal field | Kept? | Reasoning |
|---|---|---|
| `currentStudentId` | Yes | The only field ordinary lookups read. Load-bearing. |
| `historicalStudentIds[]` | Yes, **demoted to a derived cache** | Never independently written — recomputed from `history[]` in the same write every time, by the same single code path (`relink()`). Cannot diverge from `history[]` by construction, because there is exactly one writer. |
| `history[]` | Yes, **redefined as a bounded (cap 20) recent cache**, not the permanent record | Mirrors this codebase's own already-documented philosophy in `casAppendBounded` ("the array is a RECENT CACHE; permanent truth is auditLogs") — not a new pattern, the SAME one. |
| `claimedAt` / `claimedBy` | Yes, unchanged | First-ever claim only, never overwritten by a relink. |
| `currentSince` | **DROPPED** | Redundant with the last `history[]` entry's `timestamp` — a second field that could only ever agree with or silently drift from the array is worse than no field. |

**Authoritative-field answer (Section 2 Q4/Q7):** `history[]` is authoritative for *recent* ownership
history; **`auditLogs`** (via `log_audit()`) is authoritative *permanently* — every `relink()` call
writes both, in that order, mirroring the exact two-tier pattern already proven in this codebase
rather than inventing a competing audit system (Q6: **yes, the existing pattern was reused, not
duplicated**).

**Minimum schema (Q5), as implemented:**

```
srNoIndex/{schoolId}_{srNoNorm} => {
  srNo:                 "1025",
  currentStudentId:     "STU0047",
  historicalStudentIds: ["STU0001"],
  history: [ { studentId, event, actor, reason, timestamp }, ... ],   // cap 20
  claimedAt, claimedBy, schoolId, updatedAt
}
```

**Q8 (repeated re-admission, doc size):** 20-entry cap comfortably exceeds any realistic re-admission
count for one SRNO over a school's lifetime; `auditLogs` remains complete regardless (Q9).
**Q10 (long-term maintainability):** this shape has exactly one writer method (`relink()`) for the
two fields that could diverge, which is the single strongest maintainability property available —
verified in `tests/Unit/SrNoServiceTest.php::test_double_submitted_relink_is_idempotent_no_duplicate_ownership`.

---

## 3. What was implemented

| Capability | Status |
|---|---|
| SRNO format normalization (digits-only, leading-zero-insensitive) | **Implemented, unit-tested** |
| Ordinary SRNO claim (create-if-not-exists) | **Implemented, unit-tested** |
| SRNO -> current Student ID resolution | **Implemented, unit-tested** |
| Verified re-admission relink (atomic, CAS-guarded, idempotent) | **Implemented, unit-tested** |
| Full-record read for future Student Trail aggregation | **Implemented** (not yet called by any Trail code — Trail itself does not exist in this repo yet, confirmed Phase 0) |
| Principal-gated confirm endpoint | **Implemented, lint-clean, not browser-tested** |
| SRNO resolve endpoint (view-level) | **Implemented, lint-clean, not browser-tested** |
| Firestore deny-all rule for `srNoIndex` | **Implemented** |
| `commitBatch` passthrough on `Firestore_service` | **Implemented** (new capability on a shared library) |
| SRNO claiming wired into ordinary enrolment (`Sis::enroll_student`) | **NOT implemented** — deliberate scope decision, see §20 |
| Student 360 / SIS search / Student Trail UI integration | **NOT implemented** — deliberate scope decision, see §20 |
| Principal-level `correct_sr_number` (typo correction, distinct from relink) | **NOT implemented** — out of scope, flagged in §20/§24 |

## 4. Files changed

**New files (5):**
- `application/libraries/Sr_no_service.php` — the service (claim/resolve/relink/getFullRecord).
- `application/controllers/Readmission.php` — the Principal-gated confirm/resolve endpoints.
- `tests/Unit/SrNoServiceTest.php` — 12 tests, 48 assertions.
- `tests/Support/FakeSrNoFirestore.php` — in-memory Firestore double driving real control flow.
- `tests/Support/audit_helper_stub.php` — `get_instance()` stub so `log_audit()` degrades silently
  under test exactly as it does in production before `MY_Controller`'s bootstrap.

**Modified files (3), all additive, all verified isolated from concurrent pending work (§25):**
- `application/libraries/Firestore_service.php` — added `commitBatch()` passthrough (41 lines,
  pure addition, no existing method touched).
- `application/config/routes.php` — added 2 routes, isolated block near the existing
  `entitlement_request/*` routes.
- `firebase-rules/firestore.rules` — added 1 isolated `match /srNoIndex/{docId}` deny-all block,
  8 lines including comment, next to the existing `crmEnrollLocks`/`admissionPayments` deny-all
  matches.

**Untouched, deliberately:** `application/controllers/Sis.php` (§20), `firebase-rules/firestore.indexes.json`
(§7 — no index needed, deterministic doc-id reads only), `application/helpers/rbac_helper.php` (no
new graded RBAC module added — see §10's honest disclosure).

## 5. Firestore collections affected

`srNoIndex` (new, deny-all) and `students` (existing collection, two new fields on relink:
`previousStudentId`, plus `srNo`/`srNoNorm`/`srNoSource` display fields — no existing field renamed
or removed).

## 6. Firestore rules changed

One isolated `match /srNoIndex/{docId} { allow read, write: if false; }` block. Re-read the file
immediately before editing (per CLAUDE.md's standing instruction on this specific file), confirmed
573 pre-existing unrelated pending lines untouched, inserted at a stable anchor
(`firestore.rules:1061-1069`, next to `crmEnrollLocks`/`admissionPayments`).

## 7. Firestore indexes changed

**None.** Per Section 23: the doc-id is `{schoolId}_{srNoNorm}`, so every real lookup
(`resolve()`, `claim()`'s collision check, `relink()`'s read) is a deterministic single-document
`get()`/`firestoreCreate()`, never a query. No composite index is needed, and none was added.

## 8. RBAC changes

**No new graded RBAC module or capability was added.** `Readmission::confirm()` gates via a hard
role-name check (`Super Admin`, `School Super Admin`, `Principal`) instead of the graded
`SIS:manage` capability, because `SIS:manage` (per `Sis::MANAGE_ROLES`) also includes Admin/Vice
Principal/Front Office — broader than the design's explicit "Principal-level" intent for this class
of action. **This is a disclosed scope compromise, not a resolved decision** — the original SR
design's own §11.3 already flagged "confirm the role... given the current RBAC label-allowlist gap"
as an open item; this implementation inherits that same open item rather than closing it, because
closing it properly means adding a dedicated graded capability to `rbac_helper.php`/
`functions/rbac_modules.json`, both of which already carry large unrelated pending changes in this
working tree (§25) — adding to them was judged higher-risk than the honest, narrower hard-role-check
compromise. **Flagged in §24 as a remaining TODO**, not silently resolved.

## 9. Re-admission flow (as implemented)

1. Operator enrolls the returning student through the **existing, unmodified** `Sis::enroll_student()`
   — gets a new Student ID normally, no special-casing.
2. Operator (Principal/SA/SSA only) calls `Readmission::confirm(student_id=NEW, previous_student_id=OLD,
   reason=...)`.
3. `confirm()` validates: both ids present and distinct; the new record exists and has no
   `previousStudentId` yet (prevents re-linking twice); **the old record must exist** (Section 20 —
   if not found, rejected outright, no false continuity established); the old record's status must
   not be `Active` (can't supersede a currently-enrolled record); the old record must have a non-empty
   `srNo` (nothing to preserve otherwise).
4. `Sr_no_service::relink()` performs the atomic transition (§13/§14).
5. Response: `{newStudentId, previousStudentId, srNo, linked:true}` via `json_success` (verified this
   payload carries **no top-level `status` key** — the exact FEE-ENVELOPE-AUDIT collision class this
   codebase has been bitten by before; checked explicitly, see §21).

## 10. SRNO claim flow (as implemented)

`Sr_no_service::claim($srNoRaw, $studentId)` — normalize, then a single atomic
`firestoreCreate('srNoIndex', {schoolId}_{srNoNorm}, {...})`. Collision (`firestoreCreate` returns
false) is reported with the current owner's Student ID, matching the design's own error-message
intent (`SR_NUMBER_AND_APAAR_ID_DESIGN.md §3.3`). **Not wired into any admission flow** — see §20.

## 11. SRNO relink flow (as implemented)

`Sr_no_service::relink($srNoRaw, $expectedOldStudentId, $newStudentId, $reason)`:

1. Normalize; reject malformed/self-referential input immediately (no read needed).
2. Loop (max 5 attempts): read `srNoIndex` fresh; if `currentStudentId` already equals the requested
   new id, return `ok=true, reason=noop` (idempotency, TEST 6); if it doesn't match the asserted old
   id, return `ok=false, reason=expected_owner_mismatch` (TEST 4/5/7); otherwise compute the updated
   `historicalStudentIds` and attempt one `commitBatch` with **two ops in the same atomic commit**:
   the `srNoIndex` update (CAS-preconditioned on `updateTime`) and the new student's `previousStudentId`/
   `srNo*` update (unconditional — it's a fresh write to a record only this operation touches for
   these fields).
3. On success: best-effort bounded-cache append (`casAppendBounded`, cap 20) + a permanent
   `log_audit()` entry. On CAS conflict: loop retries against fresh state.
4. Exhausted retries: `ok=false, reason=contention_exhausted` — fails closed, never an unguarded write
   (matches the existing `casUpdateEntity` philosophy exactly).

## 12. Transaction guarantees

The `srNoIndex` ownership move and the new student's `previousStudentId` write are **one Firestore
batch commit** — genuinely atomic (both land or neither does), not two sequential calls with a
compensating-rollback risk between them. Verified in
`test_verified_readmission_relinks_srno_to_the_new_student` (both writes present after one call) and
`test_relink_only_writes_srNoIndex_and_the_new_student_doc` (nothing else is ever touched).

## 13. Concurrency guarantees

CAS-guarded via `updateTime` precondition on the `srNoIndex` side of the batch — a losing concurrent
writer's commit is rejected outright (whole batch, not partial), and the retry loop re-reads fresh
state, which then correctly resolves to `expected_owner_mismatch` (the loser's asserted old-owner no
longer matches reality). Verified deterministically in
`test_concurrent_readmission_only_one_relink_succeeds` — true multi-thread concurrency cannot be
exercised in a PHPUnit process (honestly stated, not glossed over), but the code path exercised is
the exact one a real race would hit.

## 14. Idempotency guarantees

Idempotent **by postcondition**, not a separate dedupe-key claim doc: if `currentStudentId` already
equals the requested new id when `relink()` runs (e.g., a retried request whose first attempt
actually committed server-side but the client timed out), it returns a clean no-op with **zero**
further writes — no duplicate history entry, no duplicate audit entry. Verified in
`test_double_submitted_relink_is_idempotent_no_duplicate_ownership`.

## 15. Audit logging

Every successful relink writes a permanent `auditLogs` entry via the existing `log_audit()` helper
(`action='re-admission-srno-relink'`, carrying `oldStudentId`/`newStudentId`/`srNo`/`schoolId`/
`reason`/`actor` — all server-derived, per Section 19's instruction not to rely on client-provided
audit fields, except `reason` which is operator free-text by design, same as every other audited
action in this codebase). Rejected relinks (wrong expected owner) are **also** audited
(`srno_relink_rejected`) — an attempted collision is itself a signal worth keeping. Rejected ordinary
claims are audited too (`srno_claim_rejected_duplicate`). No second, parallel audit system was
introduced (Section 19 Q2, answered: reuse, not duplication).

## 16. Student Trail behavior

**Not implemented — Student Trail does not exist anywhere in this codebase** (confirmed, consistent
with every prior audit in this conversation). `Sr_no_service::getFullRecord()` exists specifically so
a future Trail implementation has the read surface it needs (`currentStudentId`,
`historicalStudentIds`, `history[]`) without needing to know `srNoIndex`'s internal shape — but no
Trail code calls it, because there is no Trail code.

## 17. Student 360 behavior

**Not integrated.** `Student360.php`/`Student_context.php` were not modified. A future integration
point is exactly what `STUDENT_LIFECYCLE_PHASE1_IDENTITY_DECISION.md` Part L already specified: a
resolution step ahead of `Student_context::build()` when the route segment looks like an SRNO rather
than a Student ID — `Readmission::resolve()` (§10 of this report, GET `readmission/resolve?srno=...`)
is the API that step would call, already implemented and tested, but nothing calls it yet.

## 18. Cross-module impact

**None found, and none should exist.** `Sr_no_service`/`Readmission` reference exactly two
collections (`srNoIndex`, `students`) — verified by the isolation test (§4). No Fees/Attendance/
Results/Transport/Hostel/Certificates/Communication code was touched or needs to be for this backend
core to function correctly; those modules remain entirely unaware this exists, exactly as intended
(Section 17's instruction).

## 19. Historical data preservation

**Verified, not just claimed:** `test_relink_only_writes_srNoIndex_and_the_new_student_doc` asserts
the ONLY two collections written are `srNoIndex` and `students`, and
`test_verified_readmission_relinks_srno_to_the_new_student` explicitly asserts the OLD student's own
record (`students/SCH_TEST_STU0001`) is never present in the fake's write log after a relink — the
old record is provably untouched by this code, not merely assumed to be.

## 20. What remains — honest gap list (this is the real acceptance-criteria answer)

This backend core is real, tested, and safe to build on — but an operator cannot use this feature
today. Specifically NOT done in this pass:

1. **`Sis::enroll_student()` was not modified** to call `Sr_no_service::claim()` for ordinary
   first-time SRNO assignment. This was a deliberate blast-radius decision (§9's docblock states the
   reasoning): that method is the single most complex, already-heavily-modified controller action in
   this repository (542 pending unrelated lines in the working tree already touch this exact file).
   Wiring ordinary-claim into it is a small, well-scoped follow-up, but was not done here to avoid
   compounding risk on top of unrelated in-flight work.
2. **No UI exists** for the SRNO admission field, the re-admission search/confirm flow, the SIS list
   column, the Student 360 header, or the SR-number search box. Nothing in `application/views/` was
   touched.
3. **No RBAC catalogue entry** was added (§8) — the hard-role-check compromise remains open.
4. **`correct_sr_number` (Principal-level typo correction, distinct from re-admission relink) was not
   implemented.** The original design's §3.4 describes it; this pass implements only `claim()` and
   `relink()`. Section 14's "correction vs re-admission collision" scenario is therefore **currently
   unreachable** (there is nothing to collide with yet) — flagged honestly as future work that MUST
   share `relink()`'s exact CAS discipline on the same `srNoIndex` document when built, not a
   competing mutator.
5. **Cross-tenant isolation (adversarial item 12) was not independently re-tested in this pass** — it
   relies on the same `docId()` schoolId-prefixing mechanism Phase 0 already verified sound for every
   other collection in this codebase, but a dedicated two-school test was not written here.

## 21. Tests executed

`vendor/bin/phpunit --testsuite Unit --filter SrNoServiceTest`: **12/12 passed, 48 assertions.**
Full suite `vendor/bin/phpunit --testsuite Unit`: **627 tests, 10 failures — identical failure set
before and after this change** (all 10 are pre-existing, unrelated: `BackupTest::size_human_…`
format-drift and Homework/Teacher-cascade tests that reference a cross-repo Kotlin path, matching
CLAUDE.md's documented baseline pattern exactly). **Zero regressions introduced.**

`php -l` clean on all 7 new/modified PHP files (§4).

**Not executed, and explicitly not claimed:** any browser/UI test, any test against a real Firestore
project (no service-account credentials exist in this repository, consistent with CLAUDE.md), any
true multi-process concurrency test (simulated deterministically instead, per §13's honest caveat).

## 22. Test results — mapped to the required TEST 1-14 list

| # | Scenario | Status |
|---|---|---|
| 1 | First admission claims SRNO | **PASS** (`test_first_admission_claims_the_srno`) |
| 2 | Ordinary duplicate SRNO rejected | **PASS** (`test_unrelated_student_cannot_claim_an_already_owned_srno`) |
| 3 | Verified re-admission relinks correctly | **PASS** (`test_verified_readmission_relinks_srno_to_the_new_student`) |
| 4 | Wrong previous Student ID rejected | **PASS** (`test_relink_rejects_a_wrong_expected_previous_student_id`) |
| 5 | Unrelated student attempts relink | **PASS, with an honest caveat** (`test_relink_rejects_self_referential_and_mismatched_assertions`) — true "unrelated-ness" is a controller-layer (RBAC + old-record-must-exist-and-not-be-Active) guarantee, not a service-layer one; documented explicitly in the test and in `Readmission.php`'s docblock, not hidden |
| 6 | Double submission is idempotent | **PASS** (`test_double_submitted_relink_is_idempotent_no_duplicate_ownership`) |
| 7 | Concurrent re-admission — exactly one succeeds | **PASS, deterministic simulation** (`test_concurrent_readmission_only_one_relink_succeeds`) — see §13's caveat on true concurrency |
| 8 | Re-admit again (chain of 3) | **PASS** (`test_repeated_readmission_chain_preserves_full_history`) |
| 9 | Old financial records untouched | **PASS at the code-isolation level** (`test_relink_only_writes_srNoIndex_and_the_new_student_doc`) — no live Fees/Attendance/Results collections exist in this test environment to assert against directly, but the code provably never references them |
| 10 | New financial lifecycle belongs to new Student ID | **NOT TESTED** — requires the ordinary enrolment + Fees flow, which this pass does not touch or wire together (§20 item 1) |
| 11 | Student 360 resolves SRNO to current student | **NOT TESTED** — Student 360 integration not built (§17) |
| 12 | Historical Student record intact, not redirected | **PASS** (`test_verified_readmission_relinks_srno_to_the_new_student`'s `assertArrayNotHasKey` on the old student doc) |
| 13 | Old printed document remains historically correct | **NOT TESTED** — no document-generation/printing code was touched or exercised; the guarantee follows logically from #12 (the old record's own data is never rewritten) but was not independently verified against a real printed artifact |
| 14 | No old ZenX record — system does not falsely establish continuity | **PASS** (`test_relink_against_a_never_claimed_srno_fails_closed` at the service layer; `Readmission::confirm()`'s explicit `$oldStu === null` rejection at the controller layer, code-reviewed, not unit-tested since it requires Firestore/CI bootstrap) |

## 23. Adversarial findings

Full walk-through of Section 27's attack list, classified honestly:

| Attempt | Outcome | Severity if unmitigated | Status |
|---|---|---|---|
| Duplicate SRNO claim | Rejected (`firestoreCreate` 409-equivalent) | — | **Mitigated, tested** |
| Unauthorized relink (no RBAC) | Blocked by `_require_principal()` | High | **Mitigated at controller** — but see next row |
| **No defense-in-depth at the library layer** | `Sr_no_service::relink()` itself has no authorization check — it trusts its caller entirely, same philosophy as `Entitlement_service` ("this class assumes an authorized caller") | **Medium** | **Disclosed, not fixed** — a future careless second call site could bypass RBAC entirely. Currently only one call site exists (`Readmission::confirm()`), so this is not exploitable today, but it is a real structural risk worth naming |
| Wrong `previousStudentId` | Rejected, `expected_owner_mismatch` | — | **Mitigated, tested** |
| Concurrent relink | Exactly one succeeds | — | **Mitigated, tested (simulated)** |
| Duplicate request | Idempotent no-op | — | **Mitigated, tested** |
| Timeout/retry | Same as duplicate request — safe by the same idempotency check | — | **Mitigated** |
| Stale browser submission | Every check re-reads live Firestore state; client-supplied state never trusted for the decision | — | **Mitigated by design** |
| Stale cached SRNO mapping | Not this code's problem to solve — a UI that caches SRNO→student resolution client-side would show a stale mapping after a relink, an accepted tradeoff already named in `SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md` Part J §5 | Low | **Informational, accepted tradeoff, not a defect** |
| Correction vs. re-admission collision | **Currently unreachable** — correction (`correct_sr_number`) was not implemented in this pass | — | **N/A yet — flagged for when correction is built (§20 item 4)** |
| Direct Firestore write | Blocked by the new deny-all rule (§6); Admin SDK server-side writes bypass rules, consistent with every other collection in this codebase | — | **Mitigated** |
| Malformed `srNoNorm` | Rejected by `normalize()`'s regex before any Firestore call | — | **Mitigated, tested** |
| Cross-tenant access | Architecturally prevented by `docId()`'s schoolId-prefixing (the same mechanism proven sound for every other collection in this codebase) | — | **Mitigated by construction, not independently re-tested this pass (§20 item 5)** |
| Historical Student mutation | Never happens — verified | — | **Mitigated, tested** |
| Accidental financial record copying | Never happens — code touches only 2 collections, verified | — | **Mitigated, tested** |
| Repeated re-admission | Chain of 3+ verified correct | — | **Mitigated, tested** |
| Re-admission after withdrawal | Works correctly — status check requires old record to be non-Active | — | **Mitigated by design, code-reviewed** |
| Re-admission after restore | Self-consistent — a restored (now-Active) record correctly fails the "must not be Active" check, since restoring already returns them to active status without needing a new admission | — | **Mitigated by design, code-reviewed. Moot today since restore is itself unreachable (Phase 0 SL-33, out of scope here)** |
| Cancellation/failure halfway through transaction | `commitBatch` is a single atomic Firestore commit — no partial-apply state is possible | — | **Mitigated by construction (Firestore's own atomicity guarantee), matches an already-proven codebase pattern** |

## 24. Remaining risks

1. **No RBAC catalogue entry (§8)** — hard-role-check compromise, not a resolved design decision.
2. **No defense-in-depth at the service layer (§23)** — `relink()` trusts its caller; safe today
   because exactly one caller exists, fragile if that invariant is ever violated by future code
   without review.
3. **`correct_sr_number` not implemented** — when it is, it MUST share `relink()`'s CAS discipline on
   the same document, not be built as an independent mutator.
4. **Cross-tenant isolation not independently re-tested this pass** (relies on an already-proven
   mechanism, but no dedicated test was written here).

## 25. Remaining TODOs

Everything in §20, plus: wiring `Sr_no_service::claim()` into `Sis::enroll_student()` for ordinary
first-time SRNO assignment (the single highest-value next step to make this feature actually usable),
a dedicated graded RBAC capability to replace the hard-role-check compromise, and the UI layer
entirely (SIS list, Student 360, search).

## 26. Security assessment

Sound for what's built: atomic, CAS-guarded, idempotent, audited, RBAC-gated at the only entry point
that exists, server-only Firestore access. The two open items (§24.1, §24.2) are honest, named
residual risk, not hidden defects — both are standard "authorization is the caller's job" tradeoffs
already accepted elsewhere in this exact codebase (`Entitlement_service`'s own docblock states the
identical philosophy).

## 27. Data-integrity assessment

Strong. Every guarantee the architecture audit asked for (Section 9-11 of the original prompt) is
either tested or code-reviewed and traced above. The one genuinely new atomic primitive
(`commitBatch` passthrough) was verified against a fake that mirrors Firestore's real
precondition-rejects-the-whole-batch semantics, not a simplified always-succeeds stub.

## 28. Performance / read-write cost

Ordinary claim: 1 write (create). Resolve: 1 read. Relink (success path, no contention): 2 reads
(index + eventual no-op check folds into the same loop) + 1 atomic 2-document batch write + 1 bounded
cache append + 1 auditLogs write = **on the order of 2 reads, 3 writes**, in line with the original
architecture audit's own budget estimate (Part R of `SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md`
implied a similarly small footprint). No N+1, no unbounded fan-out, no new composite index.

## 29. Final recommendation

**Ship the backend core as-is** (it is safe, tested, and isolated) but **do not consider this feature
complete or operator-usable** until at minimum: (a) ordinary-claim is wired into enrolment, and
(b) a dedicated RBAC capability replaces the hard-role-check compromise. Both are small, well-scoped,
independently reviewable follow-ups — neither requires revisiting anything built in this pass.

---

**Working tree still HELD per instruction: nothing in this pass was committed, staged, or pushed.**
All new files are untracked; all modified files (`Firestore_service.php`, `routes.php`,
`firestore.rules`) carry only the additive, isolated changes documented above, verified via targeted
`git diff` against each file before and after editing — no pre-existing pending work (542 lines in
`Sis.php`, 573 in `firestore.rules`, unrelated Campus Access RBAC changes, etc.) was overwritten,
touched, or lost.
