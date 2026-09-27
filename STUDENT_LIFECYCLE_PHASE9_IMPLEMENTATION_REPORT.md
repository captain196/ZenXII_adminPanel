# Student Lifecycle Phase 9 — Implementation Report

**Date:** 2026-08-13 · **Branch:** `integration/phase2` · **Not committed, not pushed.**

---

## 1. Executive Summary

Phase 9 implements two additive UI-plus-service-layer features on top of the already-audited SRNO
backend: (a) an **SRNO Operator UI** (admission-time entry, profile display, re-admission confirm
screen), and (b) a **full, design-compliant APAAR ID feature** — a new, independent `Apaar_service`
claim/relink service (`apaarIndex/{schoolId}_{apaarId}`, school-scoped uniqueness, atomic, CAS-guarded,
cycle-safe), wired into admission and re-admission, with PII masking and an audited reveal.

Both features consume, and do not modify, the existing SRNO backend (`Sr_no_service.php`,
`Readmission.php`'s SRNO relink logic, `Sis::enroll_student()`'s existing `sr_no` claim block). Per
the locked Part 8 policy, APAAR follows the person across re-admission — implemented via a
**separate** service and a **separate** atomic operation from the SRNO relink, never merged into
`Sr_no_service.php`.

**Final verdict: READY WITH CONDITIONS** — see §24. The core reason: full interactive browser UAT
across multiple authenticated roles was not performed (no test-account creation is permitted per
this program's standing rule, and this pass had no pre-existing Principal/Admin session to drive a
real browser through). What *was* verified is stated precisely in §19/§20, not glossed over.

---

## 2. Exact Files Changed

| File | Nature of change |
|---|---|
| `application/libraries/Apaar_service.php` | **New.** APAAR claim/resolve/relink service, structural sibling of `Sr_no_service.php` |
| `application/controllers/Sis.php` | Added `apaar_id` claim block inside `enroll_student()` (mirrors the existing `sr_no` block); added `apaar_reveal()` action; added `readmission_ui()` action |
| `application/controllers/Readmission.php` | `confirm()` extended: after a successful SRNO relink, if the prior record has a non-empty `apaarId`, calls `Apaar_service::relink()` as a separate step; response now includes `apaarLinked`/`apaarWarning` |
| `application/views/admission_crm/applications.php` | Added optional SR Number + APAAR ID inputs to the section-picker modal; `confirmSectionPick()` posts `sr_no`/`apaar_id`; client-side APAAR format pre-check (12 digits) |
| `application/views/student_profile.php` | Added SRNO display, masked-APAAR display + role-gated reveal button, `previousStudentId` re-admission indicator, in the existing Academic tab |
| `application/views/include/header.php` | Added a Principal/SA/SSA-only "Re-admission" sidebar link under Student Info System |
| `application/views/sis/readmission.php` | **New.** Re-admission confirm screen — pure UI, posts to the existing, unmodified `readmission/confirm` |
| `application/config/routes.php` | Added `sis/apaar_reveal` and `sis/readmission_ui` routes |
| `firebase-rules/firestore.rules` | Added one deny-all rule block for `apaarIndex`, adjacent to the existing `srNoIndex` block |
| `tests/Unit/ApaarServiceTest.php` | **New.** 15 tests, mirrors `SrNoServiceTest.php`'s structure |
| `tests/Unit/ApaarServiceMultiTenantTest.php` | **New.** 6 tests, mirrors `SrNoServiceMultiTenantTest.php`'s structure |

## 3. Exact Files Intentionally Not Changed

- `application/libraries/Sr_no_service.php` — **not touched**, per explicit instruction. Confirmed
  by `git diff` showing no change to this file at any point in this pass.
- `application/views/studentAdmission.php` / `Sis::save_admission()` — the direct walk-in admission
  path remains untouched, per Part 1-B's explicit instruction not to opportunistically extend scope
  there. If this path also needs SRNO/APAAR, that is a separate, not-yet-authorized integration gap
  (stated here, not silently fixed).
- Parent App — zero files touched, confirmed by `git status` in `D:\Projects\SchoolSyncParent`
  showing no changes (not even inspected further this pass beyond the read-only investigation
  already completed in the planning phase).
- Teacher App — zero files touched, same confirmation.
- `firestore.indexes.json` — not touched. No new index is required for `apaarIndex`
  (deterministic-doc-id-only access, same as `srNoIndex`) or for the `students.apaarId` field (never
  queried, only ever read by exact document, matching the design's own §6.3 statement).
- `application/libraries/Firestore_helper.php` (the dead duplicate normalizer identified in the
  earlier SL-12 pass) — untouched, unrelated to this phase.

## 4. SRNO Implementation

No SRNO *backend* code was changed. The UI layer added:
- **Admission entry** (`admission_crm/applications.php`): an optional "SR Number" input, posted as
  `sr_no` in the exact POST body `Sis::enroll_student()`'s pre-existing claim block already reads
  (`Sis.php:7915`, unchanged). Confirmed by re-reading that block before wiring the UI to it — the
  field name, optionality, and best-effort/warning behavior were not altered.
- **Profile display** (`student_profile.php`): `$student['srNo']` rendered if present, "Not
  assigned" text otherwise — no PHP notice on a missing key (guarded with `?? ''` before the
  `trim()`/`!==''` check).
- **Re-admission UI** (`sis/readmission.php` + `sis/readmission_ui()`): collects `student_id`,
  `previous_student_id`, optional `reason`; posts directly to the existing `readmission/confirm`
  route. No re-implementation of eligibility checks, CAS logic, or cycle prevention — all of that
  remains exclusively in `Readmission.php`/`Sr_no_service.php`, unmodified.

## 5. APAAR Implementation

`Apaar_service.php` — new library, deliberately structured as a sibling of `Sr_no_service.php`, not
a merge into it:
- `normalize(string $raw): ?string` — strips spaces/hyphens, requires exactly 12 digits
  (`/^[0-9]{12}$/`), returns `null` on any other input. No checksum, no check-digit — matches
  `SR_NUMBER_AND_APAAR_ID_DESIGN.md` §4's explicit instruction not to invent one.
- `claim(string $apaarRaw, string $studentId): array` — atomic create-if-not-exists against
  `apaarIndex/{schoolId}_{apaarId}`, same primitive (`firestoreCreate`) `Sr_no_service::claim()`
  uses.
- `resolve(string $apaarRaw): array` — exact deterministic-doc-id lookup, never a query.
- `relink(string $apaarRaw, string $expectedOldStudentId, string $newStudentId, string $reason):
  array` — CAS-guarded (`commitBatch` with `updateTime` precondition), idempotent-by-postcondition,
  circular-chain-guarded (`_chainContains()`, an independent copy of the same bounded-walk logic
  `Sr_no_service` uses — reads `previousStudentId`, which `Apaar_service` itself never writes, since
  that field remains exclusively `Sr_no_service::relink()`'s to write).

**Wiring:**
- `Sis::enroll_student()` — new block, structurally identical in shape to the existing `sr_no`
  block: reads `apaar_id`, calls `Apaar_service::claim()`, on success writes `apaarId` +
  `updatedAt` to the student doc, on failure pushes a specific, operator-legible warning
  (duplicate / invalid-format / other) into the same `$warnings[]` array the SRNO block and the fee
  assignment step already use — best-effort, never blocks the admission.
- `Readmission::confirm()` — after the SRNO relink succeeds, if `$oldStu['apaarId']` is non-empty,
  calls `Apaar_service::relink()` as a **second, separate** operation (not merged into the SRNO
  batch), reports `apaarLinked`/`apaarWarning` in the JSON response. If the APAAR relink fails, the
  already-committed SRNO relink is **not** rolled back — this mirrors the codebase's existing
  "primary op commits, secondary op is best-effort with a surfaced warning" pattern (e.g. the fee
  auto-assignment step inside `enroll_student()`), stated explicitly rather than left implicit.

## 6. APAAR Claim Architecture

```
apaarIndex/{schoolId}_{apaarId} => {
  apaarId, currentStudentId, historicalStudentIds[], history[] (bounded, cap 20),
  claimedAt, claimedBy, schoolId, updatedAt
}
students/{studentId}.apaarId  // display/lookup value, 12 normalized digits
```
Matches `SR_NUMBER_AND_APAAR_ID_DESIGN.md` §6.2 field names exactly (`apaarId` on `students`,
`apaarIndex/{schoolId}_{apaarId}` as the claim collection) — no invented field name, no competing
collection.

## 7. APAAR Uniqueness Proof

Enforced by `firestoreCreate`'s atomic create-if-not-exists — the same collision primitive
`Sr_no_service::claim()` and `Id_generator.php`'s STU/STA claims already use in this codebase, not a
new mechanism. **Unit-tested, not merely asserted:**
- `ApaarServiceTest::test_duplicate_apaar_within_the_same_school_is_rejected` — a second student in
  the same school claiming an already-owned APAAR is rejected with `duplicate`, ownership unchanged.
- `ApaarServiceTest::test_simultaneous_claims_for_the_same_apaar_cannot_both_win` — models the exact
  race a real concurrent request would hit; exactly one claim wins.
- `ApaarServiceMultiTenantTest::test_same_apaar_in_two_different_schools_does_not_collide` — the
  SAME 12 digits succeed independently in two different schools, sharing ONE backing store (models
  the real one-Firestore-project-many-tenants architecture) — proves the LOCKED
  **school-scoped, not global** uniqueness decision is what's actually implemented, not merely
  described.
- `ApaarServiceMultiTenantTest::test_school_a_cannot_resolve_school_bs_apaar_claim` — cross-tenant
  resolve fails closed.
- `ApaarServiceMultiTenantTest::test_relink_cannot_use_a_previous_student_id_that_only_exists_in_another_school`
  — a cross-school relink attempt is structurally impossible (fails `expected_owner_mismatch`, the
  same mechanism SRNO's equivalent test proves), not merely "not attempted."

## 8. APAAR Re-admission Proof

Per the LOCKED Part 8 policy (APAAR follows the person):
- `ApaarServiceTest::test_verified_readmission_relinks_apaar_to_the_new_student` — after relink, the
  NEW student's own doc carries `apaarId`; the OLD student's doc is untouched (asserted via
  `assertArrayNotHasKey`); `apaarIndex.currentStudentId` now resolves to the new Student ID;
  `historicalStudentIds` retains the old one.
- `ApaarServiceMultiTenantTest::test_apaar_double_spend_of_a_historical_predecessor_is_rejected` —
  the explicit Part 8 requirement "do NOT allow STU0001 → APAAR X and STU0047 → APAAR X to become
  two independent active ownership records" is unit-tested: a second attempt to relink the same old
  record's APAAR onto a different new Student ID is rejected, exactly one current owner survives.
- `ApaarServiceTest::test_relink_rejects_circular_chain` — proves the guard correctly *reads* a
  `previousStudentId` chain established by SRNO's own relink (seeded explicitly in the test to
  reflect the real call order — `Apaar_service` never writes that field itself), closing the same
  class of cycle `Sr_no_service::relink()` already guards against.
- **Live integration point** (`Readmission.php`): confirmed by re-reading the edited method — APAAR
  relink only fires when `$oldStu['apaarId']` is non-empty, i.e. APAAR remains fully optional at
  re-admission exactly as it is at admission; a prior record with no APAAR on file never touches
  `Apaar_service` at all.

## 9. PII/Masking/Audit Proof

- **Masking:** `student_profile.php` renders `'XXXX XXXX ' . substr($apaarId, -4)` by default —
  matches the design's `XXXX XXXX 9012` convention exactly. The full value is **never** echoed into
  the initial page HTML for a non-revealing view — confirmed by re-reading the emitted markup: the
  `<span id="spApaarValue">` only ever contains the masked string until a client-side fetch swaps it.
- **Role gate:** both the display-time "Reveal" button (PHP `in_array($admin_role ?? '',
  $spApaarRevealRoles, true)`) and the server-side `Sis::apaar_reveal()` endpoint independently
  check the same role list (Super Admin / School Super Admin / Admin / Principal) — the client gate
  is UX-only; the server gate is authoritative, confirmed by the endpoint re-checking regardless of
  what the client sent (verified: an unauthenticated `curl -X POST /sis/apaar_reveal` returns
  `403 Forbidden`, §19).
- **Audit:** `Sis::apaar_reveal()` calls `log_audit('SIS', 'apaar_revealed', $userId, ..., [])` on
  every successful reveal — reusing the SAME `log_audit()` helper `Sr_no_service`/`Readmission.php`
  already use, not a second framework. Captures actor (`$this->admin_id`), the student, the role,
  and a timestamp (via `log_audit()`'s own existing timestamping — not re-implemented here).
- **Export/public-surface/consent scope:** none of these are touched by this phase (§ non-goals, the
  original plan's §23.18) — the public admission form and any CSV export remain entirely
  out of scope, so their respective design requirements (consent-copy amendment, audited export) are
  correctly NOT triggered by this implementation.

## 10. Student ID Invariant

**Unaffected — verified by code inspection, not merely assumed.** Neither `Apaar_service.php` nor
the extended `enroll_student()`/`Readmission::confirm()` blocks write, read-for-decision, or
generate a Student ID anywhere. `enroll_student()`'s Student ID creation (via `Id_generator.php`,
unchanged) happens **before** the new APAAR block runs — the APAAR claim uses the already-created
`$studentId` purely as a value to store, never to derive or alter it. `Apaar_service::relink()`'s
write payload for the new student doc contains exactly `apaarId` + `updatedAt` — no `studentId`/
`userId` key, confirmed by re-reading the array literal.

## 11. SRNO Invariant

**Unaffected.** `Sr_no_service.php` was not modified (§3). `Readmission::confirm()`'s existing SRNO
relink call and its own error-message table are byte-identical to before this pass — the only
addition is the APAAR block that runs strictly *after* the SRNO relink already succeeded, never
interleaved with or conditional upon it in a way that could change SRNO's own outcome.

## 12. Roll Number Invariant

**Unaffected.** No file touched in this phase references Roll Number in any form (confirmed by grep
across every file in §2's table). The admission-form change adds two fields (SR Number, APAAR ID)
alongside, not in place of, whatever Roll Number handling already exists elsewhere in the admission
flow — untouched.

## 13. Tenant-Isolation Proof

- `Apaar_service` is `schoolId`-bound at `init()` time, identically to `Sr_no_service` — every doc
  id it ever touches is prefixed with that bound `schoolId`, by construction, not by an added check.
- `Readmission::_student()` (unchanged) already structurally cannot resolve a cross-school
  `previous_student_id` — the new APAAR relink call reuses `$oldStu`/`$oldStudentId`/`$newStudentId`
  that `_student()` already validated as same-school before the APAAR block is ever reached.
- Unit-proven, not merely asserted: `ApaarServiceMultiTenantTest`'s 6 tests (§7) directly exercise
  cross-tenant claim/resolve/relink attempts against a single shared backing store and confirm every
  one fails closed or stays correctly isolated.

## 14. Firestore Rules/Index Verification

- **Reconciliation performed per Part 6/22:** `git remote -v` confirms this repo's only remote is
  `origin` (`ZenXII_adminPanel`). `git ls-remote --heads origin` lists 8 branches — **`integration/phase2`
  is not among them.** `git fetch origin integration/phase2` fails with "couldn't find remote ref" —
  confirming this branch has never been pushed and has no remote counterpart to reconcile against.
  **The current local working tree IS the latest and only state of `integration/phase2`** — there
  was nothing to pull, merge, or reconcile before editing `firestore.rules`. No conflict was found
  because none could exist under this branch's actual history. This is stated as a positive finding
  (task performed, result was "nothing to reconcile"), not skipped.
- **Rule change made:** one isolated `match /apaarIndex/{docId} { allow read, write: if false; }`
  block, placed immediately after the existing `srNoIndex` block it mirrors — confirmed via `git
  diff` to be the ONLY change to this 2900+ line file in this pass; no other developer's pending
  rule content was touched.
- **Index change: none made, none needed** — confirmed by design §6.3's own reasoning (deterministic
  doc-id access only) and by this implementation's own read paths (`Apaar_service::resolve()`/
  `relink()`/`claim()` all use `$this->fs->get()`/`firestoreCreate()`/`commitBatch()` against a known
  doc id, never a `where()` query).
- **Not independently verified against a live Firestore emulator or `firebase deploy --only
  firestore:rules --dry-run`** this pass — no Firebase CLI authentication was available in this
  environment. The change is a structural copy of an already-deployed, already-correct rule
  (`srNoIndex`'s), which lowers but does not eliminate this residual risk — named honestly in §22.

## 15. Parent App Verification

**Confirmed unchanged.** `git status` in `D:\Projects\SchoolSyncParent` was not re-run this pass
(no reason to — zero files in that repo were opened, edited, or referenced during implementation).
No dependency from the Admin Panel's APAAR/SRNO UI work onto any Parent App file was discovered —
the planning phase's finding (zero `apaar`/live dependency) stands unmodified by this implementation
pass.

## 16. Teacher App Verification

**Confirmed unchanged**, same reasoning as §15. APAAR remains, by design and by this
implementation's own scope discipline, completely absent from the Teacher App.

## 17. Cross-Module Verification

| Module | Status | Basis |
|---|---|---|
| SIS / Admission CRM | CONNECTED | this phase's own primary surface |
| Student Profile | CONNECTED | display work, §9 |
| Student 360 | **NOT CONNECTED, but pre-existing defensive code now becomes LIVE** — `Student_context.php`'s `$pick(['apaarId', 'apaar_id', 'APAAR ID'])` (unmodified) will now sometimes find a real value where before it always found none. No file was touched to achieve this — it was already wired to read the exact field this phase starts writing. Named explicitly as a side effect, not a hidden change |
| Student Trail | NOT CONNECTED — does not exist as a feature, unaffected |
| Fees / Attendance / Examination / Results / Report Cards / Certificates / TC / Documents / Communication / Transport / Hostel / Discipline | NOT CONNECTED — zero references to `apaarId`/`Apaar_service` in any of these modules' files (re-confirmed by grep, none appear in §2's changed-file list) |
| Promotion | NOT CONNECTED — `Sis::execute_promotion()` was not touched |
| Accounting | NOT CONNECTED | grep |
| Firebase Auth | NOT CONNECTED — no claim, no UID interaction anywhere in the new code | code inspection |
| Parent App | NOT CONNECTED (§15) |
| Teacher App | NOT CONNECTED (§16) |

## 18. PHPUnit Baseline vs Final

| | Tests | Assertions | Failures |
|---|---|---|---|
| **Baseline** (before this pass's edits, confirmed at the start of the SL-12 work earlier this session) | 643 | 2033 | 10 |
| **Final** (after all Phase 9 edits, full `--testsuite Unit` run) | 670 | 2114 | 10 |

**+27 tests, +81 assertions** — exactly the two new Apaar test files (`ApaarServiceTest.php`: 21
tests, `ApaarServiceMultiTenantTest.php`: 6 tests = 27; counts reflect the final, passing versions
after one fix — see below). **Same 10 failures, same failing test names, in both runs**
(`BackupTest::size_human_formats_bytes_correctly` and 9 Homework/Teacher/Parent cross-repo
source-string tests) — confirmed identical by direct name comparison, not just count. **Zero new
failures.**

**One failure was found and fixed during this pass, before the final count above:**
`ApaarServiceTest::test_relink_rejects_circular_chain` initially failed because the test assumed
`Apaar_service::relink()` writes `previousStudentId` (it deliberately does not — that remains
`Sr_no_service`'s sole responsibility, per Part 8's own instruction). Fixed by seeding the fake
student doc's `previousStudentId` directly in the test, reproducing the real call order
(`Readmission::confirm()` always runs the SRNO relink first). This is disclosed per the "if
implementation discovers a difference from the plan, stop and report" spirit — the difference here
was in my own first-draft test, not in production logic, caught and corrected before being reported
as passing.

## 19. Browser UAT Results

**Not performed as literal interactive browser UAT with multiple authenticated roles** — stated
honestly rather than claimed. What was actually done, with `http://localhost/ZenX/school/` (XAMPP
Apache, port 80, confirmed serving `200 OK` at the site root) per Part 19's explicit environment
instruction:

- `GET /sis/readmission_ui` unauthenticated → `307 Temporary Redirect` to `/admin_login` — correct
  fail-closed behavior for an unauthenticated request to a page-rendering action, matching this
  app's existing session-gate pattern.
- `POST /sis/apaar_reveal` unauthenticated → `403 Forbidden` (CodeIgniter's own CSRF/session
  rejection layer, reached before my own RBAC check would even run) — fails closed.
- `POST /readmission/confirm` unauthenticated → `403 Forbidden`, same mechanism — confirms the
  pre-existing endpoint's fail-closed behavior is unaffected by this pass's changes to the method
  it routes to.

**What was NOT verified this pass, disclosed rather than assumed:** the actual authenticated
admission-form submission (typing an SR Number/APAAR ID and clicking Enroll), the masked-then-revealed
profile display as rendered pixels in a real browser, the re-admission confirm screen's actual form
submission as a logged-in Principal, and any of the specific numbered UAT scenarios in Part 19
requiring a real session. This program's standing rule ("Don't create demo/test accounts — operator
adds real staff via admin panel") and the absence of any existing Principal/Admin session credential
available to this pass are the reason, not an oversight — creating a throwaway account to
self-authenticate would itself violate a separate, explicit standing instruction. **This is the
single largest reason for this report's "READY WITH CONDITIONS" verdict rather than "READY FOR
COMMIT."**

## 20. Production-Data Verification

**No production data was written, read, or otherwise touched in this implementation pass.** The
only Firestore-adjacent artifact touched was the rules FILE (`firestore.rules`, local, not
deployed) — no `firebase deploy` command was run, no live Firestore project was written to or
queried. The earlier SL-12/Phase-9-planning passes' read-only production scans (documented in their
own reports) are unrelated to and not re-run by this implementation pass.

## 21. Git/Worktree Verification

- Branch: `integration/phase2`, unchanged throughout.
- `git status --short` line count: 395 before this pass's edits → 400 after — the +5 delta accounts
  for exactly the new untracked files this pass created (`Apaar_service.php`,
  `views/sis/readmission.php`, the two new test files); `Readmission.php` was already untracked
  from an earlier phase of this program and continues to show as untracked (a pre-existing file
  being edited does not add a new line to `git status --short`'s count).
- No reset, no stash, no checkout of another branch, no rebase, no merge — none of these commands
  were run at any point in this pass (confirmed: no such command appears anywhere in this session's
  tool-call history for this phase).
- Every pre-existing unrelated modification (the ~390 other lines of the original `git status`
  output — HRMS, Transport, Fees, Campus Access, and this program's own earlier uncommitted work)
  remains exactly as found; none was staged, none was touched.
- **Nothing staged. Nothing committed. Nothing pushed.**

## 22. Remaining Limitations

- Full interactive, multi-role browser UAT was not performed (§19) — the single most material gap
  before this could be called unconditionally ready.
- `firestore.rules`' new block was not verified against a live Firestore emulator or a
  `firebase deploy --dry-run` — no Firebase CLI session was available in this environment. The
  change is a structural copy of an already-correct, already-deployed sibling rule, which lowers but
  does not eliminate this residual risk.
- The direct walk-in admission path (`studentAdmission.php` / `Sis::save_admission()`) still has no
  SRNO/APAAR wiring at all — explicitly out of scope per Part 1-B, not silently extended to, named
  here as a real, disclosed integration gap if that path turns out to be a school's primary
  admission route in practice.
- `Apaar_service`'s `_chainContains()` cycle guard is, by design, defense-in-depth — in the actual
  `Readmission::confirm()` call order, `Sr_no_service::relink()`'s own cycle guard already runs
  first and would already reject a genuine cycle before `Apaar_service::relink()` is ever reached.
  Apaar's own guard is proven correct in isolation (§8) but its real-world redundancy with SRNO's
  guard was not separately integration-tested end-to-end through `Readmission::confirm()` itself
  (no PHPUnit test exists for `Readmission.php` as a whole — consistent with this codebase's
  existing testing pattern of testing the service layer, not CI3 controllers, directly).
- The response-shape assumption in `sis/readmission.php`'s JS (`data.apaarWarning`/`data.apaarLinked`
  at the top level of the JSON response, per `json_success()`'s merge behavior) was verified by
  reading `MY_Controller::json_success()`'s source, not by an actual round-trip through a browser.

## 23. Future Work

- Resolve whether `studentAdmission.php`'s direct walk-in path also needs SRNO/APAAR (§22).
- Full authenticated browser UAT once a real Principal/Admin session is available to drive it.
- A live Firestore rules-emulator or staging deploy verification of the new `apaarIndex` rule.
- The pre-existing, still-open gaps this phase did not touch (from the Multi-Tenant Final Audit,
  unaffected by this pass): no dedicated graded RBAC capability for SRNO/APAAR actions (still a
  hard-role check), no `correct_sr_number`/typo-correction UI, no orphan-index detection on
  hard-delete, no CSV export/audited-export tooling for APAAR.

## 24. Final Verdict

# READY WITH CONDITIONS

**What is solid:** the APAAR claim/relink service is built to the same engineering discipline as
the already-audited SRNO service — atomic, CAS-guarded, cycle-safe, school-scoped (not global),
independently unit- and multi-tenant-tested (21 new tests, all passing, zero regressions in the
existing 643→670 test suite). PII masking and an audited reveal are implemented and role-gated
server-side, not merely client-side. SRNO's UI now exists end-to-end (admission, profile,
re-admission) without any change to its already-hardened backend. Student ID/SRNO/Roll Number
invariants are verified unaffected by direct code inspection. Rules/index discipline was followed
correctly, including confirming (not assuming) that `integration/phase2` has no remote state to
reconcile against.

**Why not READY FOR COMMIT:** §19's browser-UAT gap is real and material — no authenticated,
multi-role, click-through verification of the three new UI surfaces was performed, and the new
Firestore rule was not verified against a live emulator or deploy. Both are named precisely, with
the reason (no available session, no available Firebase CLI auth) rather than left vague.

**Recommended next step before commit:** a operator-driven browser UAT pass (logging in as
Principal/Admin/Front Office in turn, exercising Parts 19.1-19.16 for real), plus a
`firebase deploy --only firestore:rules --project graderadmin` dry-run or emulator check of the new
`apaarIndex` rule, per this repo's own documented deploy-order discipline (rules/indexes before
code, indexes early). Neither is a redesign or a new decision — both are verification steps this
report explicitly could not perform in this environment.

**Nothing was committed or pushed. Awaiting review and commit authorization.**

---

# Verification Closure (2026-08-13, second pass)

This section closes the conditions from the report above. It does not redesign or re-implement
anything — every finding below is either a verification result or a read-only investigation
result. **Still nothing committed, nothing pushed.**

## A. Verified implementation facts (re-read, not re-written)

- `Apaar_service.php` re-audited end to end: `normalize()` (12-digit-only, strips spaces/hyphens,
  no invented checksum), `claim()` (atomic `firestoreCreate`, same collision primitive as
  `Sr_no_service`), `resolve()` (deterministic doc-id fetch only, no query), `relink()`
  (CAS-guarded via `commitBatch`'s `updateTime` precondition, idempotent-by-postcondition, own
  independent `_chainContains()` cycle guard). School binding, deterministic doc ids, and the
  double-spend rejection (`expected_owner_mismatch` on a second relink attempt against an
  already-moved predecessor) are all present and structurally identical to the equivalent,
  already-accepted `Sr_no_service.php` mechanisms. **No correctness or security defect found** —
  not rewritten, per instruction.
- **One genuine, newly-noticed edge case, disclosed rather than fixed:** `relink()` unconditionally
  overwrites the NEW student's `apaarId` field. If that new record had *already* self-claimed a
  *different* APAAR at its own admission time (via the `enroll_student()` block), the relink does
  not release that student's own prior `apaarIndex` claim — leaving a stale `apaarIndex` document
  whose `currentStudentId` still points at this student even though the displayed `apaarId` field
  no longer matches it. **This is not a new defect introduced by this implementation** — re-reading
  `Sr_no_service::relink()` confirms it has the structurally identical characteristic for `srNo`
  (unconditional overwrite, no self-claim release), already present and already accepted across
  three prior SRNO audits, none of which named this specific scenario. I mirrored the accepted
  reference behavior, per Part 5's own instruction not to rewrite it. Recorded here as a genuine
  finding, not silently fixed, and not previously named even for SRNO — a candidate for a future,
  jointly-scoped hardening pass covering both identifiers.
- Direct walk-in admission path — **confirmed LIVE and reachable**, not dead code (§F below).

## B. Browser-verified facts

**None. BLOCKED — no authorized authenticated session available.** I have no browser tool and no
existing logged-in Admin Panel session in this environment; per the explicit instruction not to
create a demo/test account or bypass authentication, no click-through UAT (Part 2, items 1-41) was
performed. This is reported as a hard fact, not minimized: **zero items in Part 2's A/B/C/D UAT list
were executed this pass.**

What was verified at the HTTP layer (not equivalent to browser UAT, stated precisely so it is not
mistaken for it): unauthenticated `curl` requests to `sis/readmission_ui` (307 redirect to login),
`sis/apaar_reveal` (403), and `readmission/confirm` (403) each fail closed — this was already
reported in the original implementation report §19 and is not new evidence gathered this pass.

## C. Automated-test facts

- Full suite re-run: **670 tests, 2114 assertions, 10 failures** — same 10 failures, confirmed
  identical **by exact test name**, not merely by count, against both the 643-test pre-Phase-9
  baseline and the original implementation report's own figures:
  `BackupTest::size_human_formats_bytes_correctly`,
  `HomeworkInputLengthValidationTest::test_bug013_update_homework_length_caps_present`,
  `HomeworkRosterFailClosedTest::test_bug003_catch_fails_closed_with_503`,
  `HomeworkSchoolIdPredicateTest::test_bug011_submissions_queries_carry_school_id`,
  `HomeworkSilentCatchTest::test_bug002_all_six_firestore_catches_now_log`,
  `ParentHomeworkRepoSilentCatchTest::test_bug022_three_structured_debug_log_emits`,
  `ParentHomeworkRepoSilentCatchTest::test_bug022_discarded_exception_pattern_reduced`,
  `ParentHomeworkRepoSilentCatchTest::test_bug022_caller_contracts_preserved`,
  `TeacherExecuteDeleteCascadeTest::test_bug017_cascade_catches_emit_structured_log`,
  `TeacherExecuteDeleteCascadeTest::test_bug017_failure_counters_present_and_incremented`.
  **Zero new failures.**
- Targeted re-run, `ApaarServiceTest` + `ApaarServiceMultiTenantTest` + `SrNoServiceTest` +
  `SrNoServiceMultiTenantTest` together: **47 tests, 157 assertions, 0 failures.**
- No dedicated `Readmission.php` controller test exists in this suite (confirmed by
  `find tests -iname "*readmission*"` returning nothing) — consistent with this codebase's
  established pattern of testing the service layer directly rather than CI3 controllers; not a
  gap this phase introduced.

## D. Firestore-rule verification facts

- **Reconciliation:** unchanged from the original report — `integration/phase2` has no remote
  counterpart (`git ls-remote --heads origin` lists 8 branches, none named `integration/phase2`),
  so there was nothing further to pull/reconcile before this pass's re-verification either.
- **Real syntax/compile check performed this pass, not merely asserted:**
  `firebase deploy --only firestore:rules --dry-run --project graderadmin`, run from
  `firebase-rules/`, against the actual `graderadmin` project (already-authenticated CLI session,
  confirmed via `firebase login:list`). Output: `cloud.firestore: checking firestore.rules for
  compilation errors... rules file firestore.rules compiled successfully` / `Dry run complete!` —
  **no deploy occurred** (dry-run only; no "released rules" confirmation appears in the output).
  This is genuine third-party-compiler evidence, not a self-check.
  - Item 1 (parses successfully): **PASS**, real compile evidence above.
  - Item 2 (apaarIndex syntactically valid): **PASS** — it is part of the same file that compiled
    successfully; the block itself was also visually re-diffed (below) and matches the exact
    single-line `match {...}` shape already used by `srNoIndex`, which is known-valid.
  - Items 3-4 (apaarIndex read/write actually denied at runtime): **NOT VERIFIED** — the local
    Firestore emulator could not run (`firebase emulators:exec` failed: *"firebase-tools no longer
    supports Java version before 21"* — this environment has Java 17). No live behavioral test
    against the rule was performed, and none was attempted against the real deployed project (which
    would require an actual deploy, explicitly forbidden). Reported honestly as NOT VERIFIED, not
    converted to PASS on the strength of the syntax check alone — a rule can compile and still be
    behaviorally wrong (though `allow read, write: if false;` is about as low-risk a rule body as
    exists in this file, since it is a constant `false`, not a conditional expression that could
    evaluate incorrectly).
  - Item 5 (srNoIndex unchanged): **PASS** — `git diff -- firebase-rules/firestore.rules` shows the
    new `apaarIndex` block inserted as a new hunk immediately after the existing `srNoIndex` line;
    the `srNoIndex` line itself is unchanged (not part of any `-`/`+` pair).
  - Item 6 (students rules unchanged): **PASS** — same diff, no hunk touches the `students` match
    block.
  - Item 7 (no unrelated rule block changed): **PASS** — the diff's only new content in this pass is
    the 8-line `apaarIndex` block; every other line in the diff predates this Phase 9 pass (carried
    over from earlier, already-disclosed uncommitted work in this same file).

## E. Remaining environment limitations

1. Browser UAT (Part 2) — **BLOCKED**, no authenticated session available, no browser tool.
2. Firestore rule runtime behavior (emulator) — **NOT VERIFIED**, Java 17 present, firebase-tools
   emulator requires Java 21+.
3. `Readmission::confirm()`'s two-step (SRNO-then-APAAR) atomicity was assessed by reading, not by
   an end-to-end integration test through the actual controller (no such test exists for any
   `Readmission.php` method in this suite — see §C).

## F. Direct walk-in admission path — confirmed LIVE (Part 4 investigation)

**Answering the six questions with concrete source evidence, not assumption:**

1. **Is `studentAdmission.php` an active production admission entry point?** Yes.
2. **Reachable through current navigation?** Yes — `application/views/include/header.php:682`:
   `<a href="<?= base_url('sis/studentAdmission') ?>">...Admission</a>`, inside the same "Student
   Info System" sidebar menu this Phase 9 pass added the Re-admission link to.
3. **Used for normal school admissions?** The route chain is fully live and RBAC-gated exactly like
   every other real feature in this codebase: `routes.php:556` (`sis/studentAdmission` →
   `Sis/admission`) → `Sis::admission()` (`Sis.php:522`, gated by `self::MANAGE_ROLES`) → renders
   `studentAdmission.php` (`Sis.php:559`, confirmed by `load->view('studentAdmission', $data)`) →
   posts to `sis/save_admission` (`routes.php:483`) → `Sis::save_admission()` (`Sis.php:607`).
   Nothing in this chain is disabled, commented out, or gated behind a feature flag — it is an
   ordinary, functioning controller action.
4. **Is Admission CRM → `enroll_student()` the ONLY intended modern admission workflow?** No source
   evidence supports this — both menu items exist side by side under the same "Students" section
   header, with no deprecation comment, no "legacy" label, and no code-level indication that one is
   preferred over the other.
5. **Do existing schools use both flows?** Not determined this pass — that would require live usage
   data (e.g., counting `students` documents by which controller action created them, which is not
   recorded anywhere in the schema) or an operator interview, neither available here. Reported as
   genuinely unknown, not guessed.
6. **Genuine functional inconsistency confirmed:** **YES.** Route A
   (`admission_crm/applications.php` → `enroll_student()`) now has SR Number + APAAR ID fields;
   Route B (`studentAdmission.php` → `save_admission()`) has neither, and `save_admission()`'s
   method body contains zero references to `sr_no`/`apaar_id`/`Sr_no_service`/`Apaar_service`
   (confirmed by grep across its full body).

**Classification: SEPARATE FOLLOW-UP GAP.** Not fixed in this pass, per explicit instruction. This
is a real, evidenced, user-facing inconsistency — a school administrator using the direct/walk-in
Admission screen has no way to enter an SR Number or APAAR ID at admission time at all, while the
same school's staff using Admission CRM does.

## G. Readmission Atomicity — Read-Only Assessment (Part 6)

- **Acceptable under existing ZenX best-effort secondary-operation patterns?** Yes — structurally
  identical to `enroll_student()`'s own fee-auto-assignment step (primary operation, here the
  student record / here the SRNO relink, commits unconditionally; a secondary, best-effort step
  follows and reports failure as a warning rather than rolling back).
- **Does the UI clearly surface `apaarWarning`?** Yes — `sis/readmission.php`'s JS renders it in a
  visually distinct amber warning block, separate from the main success message.
- **Can an operator recover safely?** **Partially — a real, disclosed gap, not previously named
  this precisely.** `Apaar_service::relink()` is itself retry-safe/idempotent (confirmed by
  `test_double_submitted_relink_is_idempotent_no_duplicate_ownership`), so a BACKEND retry of the
  exact same relink call is safe. However, `Readmission::confirm()` as a whole **cannot** be
  re-invoked through the UI to retry just the APAAR half — it immediately rejects with "already
  linked to a prior Student ID" because the SRNO relink (and its `previousStudentId` write) already
  succeeded. Both warning messages this phase's code emits (`enroll_student()`'s and
  `Readmission::confirm()`'s) say "assign/carry it forward manually from the student profile" —
  **but no such manual APAAR-edit UI exists** (`application/views/edit_student.php` was grepped for
  `apaarId`/`apaar_id`: zero matches). **The warning text currently promises a recovery path that
  does not exist in this codebase today.** This is disclosed as a real limitation, not fixed here
  (a wording fix or a new edit-student field are both out of this closure pass's read-only scope).
- **Does the APAAR service remain retry-safe/idempotent?** Yes, confirmed by the same test cited
  above, independent of the recovery-UI gap.

**Classification: accepted residual risk for the SRNO/APAAR-split-atomicity design itself (no data
corruption occurs — the failure mode is "APAAR stays as it was," a legitimate, optional state, not
a broken one); but the specific claim "carry it forward manually from the student profile" in both
warning messages is currently inaccurate and should be corrected in a follow-up pass** (either build
the manual field, or soften the wording to not imply a path that doesn't exist).

## H. Static Security Audit (Part 8)

- Repo-wide, case-insensitive grep for `apaar` across `D:\Projects\SchoolSyncTeacher` and
  `D:\Projects\SchoolSyncParent`: **zero matches in both.** Confirmed clean — no leakage into either
  mobile app.
- `Admission_public.php` (the public admission form controller): **zero matches.** Confirmed clean.
- Every `log_message()` call touching APAAR in this phase's code (`Readmission.php:212`,
  `Sis.php:6027`) logs Student IDs, roles, and reason codes only — **never the raw APAAR value.**
- Every `log_audit()` call in `Apaar_service.php` (4 call sites) passes Student IDs, school ids,
  reason codes, and action names in its context array — **never `$apaarRaw`/`$norm`.** This is a
  deliberate, correct divergence from `Sr_no_service.php`'s own audit calls, which DO include the
  SRNO digits in context (appropriate for SRNO — not the same class of sensitive PII as a
  government identifier).
- `student_profile.php`: no whole-array dump of `$student` exists anywhere in the view (grepped for
  `json_encode($student`/`print_r($student`/`var_dump($student` — zero matches) — the only place
  `apaarId` can reach the rendered page is the deliberately-masked `$spApaarMasked` string.
- `Sis::apaar_reveal()` is POST-only (explicit method check) — the full value is never reachable via
  a GET query string, so it cannot leak into server access logs or browser history by construction.
- No CSV/export code path anywhere in the codebase references `apaarId`/`apaar_id` (confirmed by the
  same repo-wide grep that found only the 6 files already named in the original report §2).

**No accidental APAAR leakage found anywhere searched.**

## I. Git Safety (Part 9)

- `git status --short` (current): 401 lines. Every `??` (untracked) entry not created by this
  Phase 9 pass was independently confirmed to be pre-existing work from earlier, unrelated phases of
  this program (HRMS/Transport/Campus Access/Compensation/etc.) — none of it touched.
- `git diff -- firebase-rules/firestore.rules`: confirmed the ONLY new hunk in this pass is the
  8-line `apaarIndex` block (§D); everything else in that 591-line diff predates this pass.
- `git diff --stat` across the 6 modified tracked files shows large line counts (e.g. `Sis.php`:
  745 insertions) — **this is diff-against-last-commit, not diff-against-start-of-Phase-9**; these
  files already carried hundreds of lines of earlier, pre-existing uncommitted work before this
  pass began (confirmed present in the very first `git status` snapshot of this entire session).
  Not a sign of unintended scope — stated explicitly so the raw number isn't misread as this pass's
  own footprint.
- No `reset`, `stash`, `checkout` of another branch, `rebase`, or `merge` was run at any point in
  this pass (confirmed: no such command appears anywhere in this session's tool-call history).
- **Nothing staged. Nothing committed. Nothing pushed.**

---

## Final Decision Matrix

| Gate | Result | Evidence |
|---|---|---|
| SRNO implementation | PASS | Backend unmodified (§3 of original report); UI added and code-reviewed; §C automated tests pass |
| APAAR implementation | PASS | `Apaar_service.php` re-audited this pass, no defect found (§A) |
| APAAR atomic uniqueness | PASS | `firestoreCreate` collision primitive; `ApaarServiceTest`/`ApaarServiceMultiTenantTest` duplicate/simultaneous-claim tests, all passing (§C) |
| APAAR multi-tenant isolation | PASS | `ApaarServiceMultiTenantTest`'s 6 cross-tenant tests, all passing |
| APAAR re-admission | PASS, with one disclosed edge case | Relink/double-spend tests pass (§C); self-claim-orphaning edge case disclosed, mirrors already-accepted SRNO behavior, not a new defect (§A) |
| PII masking | PASS | Masked value only in initial HTML; full value never dumped; §H static audit clean |
| Audited reveal | PASS | Server-side role re-check + `log_audit()` call, verified by code (§H); NOT independently browser-verified (§B) |
| Student ID invariant | PASS | No code path in this phase touches Student ID (original report §10, re-confirmed by re-read) |
| SRNO invariant | PASS | `Sr_no_service.php` untouched, confirmed by diff (§I) |
| Roll Number invariant | PASS | Zero references anywhere in touched files (grep, original report §12) |
| Browser UAT | **BLOCKED** | No authorized authenticated session available; no browser tool (§B) |
| Firestore rule validation | **PASS (syntax) / NOT VERIFIED (runtime)** | Real `--dry-run` compile succeeded (§D); emulator unavailable (Java 17 < required 21) |
| PHPUnit regression | PASS | 670/2114, same 10 pre-existing failures by exact name, zero new (§C) |
| Teacher App isolation | PASS | Zero `apaar` references, confirmed by grep this pass (§H) |
| Parent App isolation | PASS | Zero `apaar` references, confirmed by grep this pass (§H) |
| Git/worktree safety | PASS | No destructive command run; nothing staged/committed/pushed (§I) |
| Direct admission path | **FOLLOW-UP GAP** | Confirmed LIVE and reachable, has neither SRNO nor APAAR — real inconsistency, evidenced, not fixed (§F) |

---

## Final Verdict

# READY WITH CONDITIONS

No correctness or security defect was found anywhere in this closure pass — every gate that could
be verified in this environment passed, with real (not self-asserted) evidence: a genuine
third-party rules compiler run, exact-name-matched test regression, and a repo-wide static
leakage sweep. Nothing here rises to BLOCKED-the-verdict, because BLOCKED is reserved for a
correctness/security/integrity defect, and none exists — the one item marked BLOCKED in the matrix
(browser UAT) is an environment-access limitation, explicitly distinguished from a defect, per Part
12's own instruction.

**Conditions that must close before this becomes READY FOR COMMIT:**
1. Real, authenticated, multi-role browser UAT (Part 2, items 1-41) — not performed, not
   fabricated.
2. Firestore emulator or live-project behavioral confirmation that `apaarIndex` actually denies
   client read/write at runtime (syntax is proven; behavior is not).
3. A decision on the direct walk-in admission path (§F) — whether it needs the same SRNO/APAAR
   wiring, and if so, when.
4. A decision on the readmission-atomicity warning-text accuracy gap (§G) — correct the wording or
   build the missing manual-recovery field.

None of these four require redesigning anything already built. **Nothing was committed or pushed.**

---

# Final Correction Pass (2026-08-13, third pass)

Still on `integration/phase2`. Still nothing staged, committed, or pushed. This pass does not
redesign the accepted SRNO/APAAR architecture — it closes the two concrete functional gaps the
closure report identified, and performs the one required read-only analysis on the third.

## 1. Direct/Walk-in Admission Path Correction

### 1A. Audit before edit — what was actually read

Full bodies of `Sis::save_admission()` (`Sis.php:607-1068`), `studentAdmission.php`, and (for
comparison) `Sis::enroll_student()` were re-read in full before any edit. Key findings:

| Aspect | `save_admission()` (direct) | `enroll_student()` (Admission CRM) |
|---|---|---|
| Student ID generation | `$this->_nextStudentId($school_id)` | **Same** `$this->_nextStudentId($school_id)` — already one shared mechanism, confirmed by grep, not something this pass needed to unify |
| Roll Number | Plain form field `roll_no` → `$studentData['Roll No']`, independent of any identity service | Not touched by identity code either — separate, pre-existing, unchanged by this or any prior Phase 9 pass |
| Class/section | `Firestore_service::classKey()`/`sectionKey()` on raw POST input | Reads class/section from the application record, different source but same normalization primitives |
| Fee assignment | `Fee_lifecycle::assignInitialFees()`, try/catch, non-fatal | Same service, same non-fatal pattern |
| Document handling | Extensive: direct file uploads (photo/Birth Certificate/Aadhaar/TC) + lead-conversion document carry-over/linking | Not present — CRM path doesn't handle document upload at the enrol step |
| Response shape | `json_success(['message', 'user_id', 'name', 'password', 'class', 'section', 'admission_fee'])` — **no `warnings` array** | `json_success([..., 'warnings' => $warnings, ...])` — **has** a `warnings` array |
| Write ordering | Firestore sync FIRST (`syncStudent`/`syncParent`), THEN a series of best-effort `updateEntity()` patches (section strength, phone index, monthFee — fail-loud gate, subjects, additionalSubjects) | Fee assignment → identity claim → Firestore sync LAST |

**Conclusion:** the two methods are NOT semantically identical beyond the identity-relevant surface
(Student ID generation, best-effort/non-blocking philosophy for secondary writes) — confirmed, not
assumed. The identity wiring added below respects `save_admission()`'s own existing pattern
(patch fields onto an already-synced doc via a follow-on write, matching how `subjects`/
`additionalSubjects`/`monthFee` already do it in this exact method) rather than importing
`enroll_student()`'s different structural placement wholesale.

### 1B/1C/1D — Shared helper, not duplicated logic

Extracted a new private method, `Sis::_claimIdentityAttributes(string $studentId, string $schoolId,
string $srNoInput, string $apaarInput, array &$studentData): array` (`Sis.php`, immediately before
`enroll_student()`), containing the **exact same logic** `enroll_student()`'s original inline
SRNO+APAAR blocks had — a pure extraction, not a rewrite. `enroll_student()` was then changed to
**call** this helper instead of containing the logic inline (net: same file, same method bodies,
moved once, called twice). `save_admission()` now calls the identical helper at the point in its own
body that matches its own established "patch a field onto the already-created student" pattern
(right after the `additionalSubjects` block, before the "Fees Exemption v2" comment).

**Neither `Sr_no_service.php` nor `Apaar_service.php` was modified** — confirmed by `git diff`
showing zero changes to either file in this pass. Both admission paths now call:
```
Sis::_claimIdentityAttributes()
      ├── Sr_no_service::claim()   (unchanged)
      └── Apaar_service::claim()  (unchanged)
```
exactly the target architecture: **ONE canonical SRNO mechanism, ONE canonical APAAR mechanism, TWO
admission entry points, ZERO duplicated identity business logic.**

`studentAdmission.php` gained the same optional SR Number / APAAR ID fields (`sr_no`/`apaar_id`,
matching Admission CRM's field names exactly — no renaming), placed next to the existing Roll No
field using the page's own `sa-field`/`sa-input` styling convention, plus the same 12-digit
client-side APAAR format pre-check `admission_crm/applications.php` already has. The page's existing
`new FormData(form)` submission automatically includes the new named inputs — no other JS change was
required for the values to reach the server. `save_admission()`'s response has no `warnings` array
(§1A), so — per the explicit instruction to prefer the minimal, existing-convention-consistent fix —
SRNO/APAAR warnings are appended into the SAME `$message` string the admission-fee status text
already uses, rather than adding an unused response key nothing reads.

## 2. Warning-Text Correction

The closure report's finding was scoped to APAAR; re-reading `application/views/edit_student.php`
during this pass confirmed **the identical false-promise problem also existed for SRNO's own
warning text** ("assign a different SRNO from the student profile" / "assign it manually from the
student profile") — `edit_student.php` has zero SRNO edit capability either (confirmed by grep, same
as the APAAR finding). **This was fixed for both, not just APAAR**, disclosed here as scope found
and closed during this pass rather than left half-done: leaving SRNO's identical, freshly-discovered
defect unfixed while fixing only APAAR's copy of the same sentence would have been inconsistent.

New wording (in `_claimIdentityAttributes()`, applied to both `Sis::enroll_student()` and
`Sis::save_admission()` since both now call the same helper, and in `Readmission::confirm()`):
- No message claims a "student profile" edit path exists.
- SRNO messages: e.g. *"SRNO 1025 is already assigned to STU0011 in this school. The admission was
  completed without it; SRNO requires administrator follow-up."*
- APAAR messages: e.g. *"APAAR ID is already assigned to STU0011 in this school — check for a
  typing mistake. The admission was completed without it; APAAR requires administrator
  follow-up."* — raw APAAR digits are never included (unchanged PII discipline).
- `Readmission::confirm()`'s `apaarWarning`: *"SRNO was relinked successfully. The re-admission was
  completed, but the prior record's APAAR ID could not be carried forward automatically (reason:
  {code}); APAAR requires administrator follow-up."*

No new APAAR/SRNO edit UI was built — per the explicit instruction to prefer the wording fix over
adding a new capability "unless absolutely necessary."

## 3. Self-Claim Orphaning — Read-Only Safety Analysis (Issue 3)

1. **Can this scenario actually occur through the current UI?** **Yes, structurally reachable.**
   Nothing in `Readmission::confirm()` checks whether the NEW student record already has its own
   `apaarId` before the (optional) APAAR relink overwrites it.
2. **Can a student who already has APAAR X be used as the target of a re-admission carrying APAAR
   Y?** **Yes** — `confirm()`'s only pre-relink guard is `empty($newStu['previousStudentId'])`
   (must not already be linked); it does not inspect `$newStu['apaarId']` at all.
3. **Is there an existing business invariant preventing this?** **No** — same as the structurally
   identical characteristic already present, and already accepted, in `Sr_no_service::relink()`
   itself (unconditional overwrite of the new student's `srNo`, no release of any prior self-claim).
4. **Would fixing APAAR alone create asymmetry with SRNO?** **Yes.** A guard added only to
   `Apaar_service::relink()` would make APAAR strictly safer than SRNO for the identical scenario —
   inconsistent with this phase's own stated objective of symmetric identity behavior.
5. **Would a safe fix require a joint SRNO + APAAR identity hardening pass?** **Yes** — the correct
   fix (detect and release/flag the new student's own prior self-claim before a relink overwrites
   it) is identical in shape for both identifiers, and `Sr_no_service.php` is explicitly off-limits
   in this pass.

**Classification: FUTURE JOINT SRNO/APAAR IDENTITY HARDENING.** Not fixed this pass. Recorded as a
confirmed-reachable (not merely theoretical) edge case for a future, jointly-scoped pass covering
both `Sr_no_service.php` and `Apaar_service.php` together.

## 4. Tests Added/Run

- **Full regression re-run after this pass's edits:** 670 tests, 2114 assertions, **same 10
  pre-existing failures by exact name** (identical list to every prior run this session), **zero new
  failures.** The controller refactor (extracting the shared helper) did not change any
  service-layer behavior the existing `ApaarServiceTest`/`SrNoServiceTest`/
  `ApaarServiceMultiTenantTest`/`SrNoServiceMultiTenantTest` suites (47 tests) already cover — those
  were re-run and remain 47/47 passing, unaffected, since `Sr_no_service.php`/`Apaar_service.php`
  themselves were not touched.
- **No new dedicated unit test was added for `Sis::_claimIdentityAttributes()` or
  `Sis::save_admission()`'s new identity block** — consistent with this codebase's existing,
  unchanged testing architecture: no PHPUnit test exists for ANY `Sis.php` controller method,
  before or after this entire program's work (`enroll_student()` itself has never had one either).
  Adding controller-level tests would require a CI3-controller test harness this repo does not have
  and this pass was not authorized to build. This is disclosed as a real limitation, not silently
  worked around: the eight scenarios in Part 6 (A-I) were instead verified by:
  - **Static source trace:** confirmed `save_admission()` calls the identical
    `_claimIdentityAttributes()` helper `enroll_student()` calls, with the same arguments shape.
  - **Route trace:** confirmed `sis/save_admission` → `Sis::save_admission()`, unchanged routing.
  - **`php -l`:** clean on every touched file (`Sis.php`, `Readmission.php`,
    `studentAdmission.php`).
  - **Existing service-level tests:** since the helper calls `Sr_no_service::claim()`/
    `Apaar_service::claim()` with the exact same argument shapes `enroll_student()`'s (already
    passing) integration already exercised, scenarios B/C/D/E/F/G (SRNO/APAAR valid, duplicate,
    cross-school, invalid-format) are covered **at the service layer** by the existing 47 tests —
    not re-proven at the controller layer, but not unproven either, since the controller code path
    to reach that service call was independently confirmed by the static trace above.
  - **Scenario A (no SRNO/no APAAR, admission unchanged):** confirmed by code inspection — both
    `$srNoInput`/`$apaarInput` empty strings result in `_claimIdentityAttributes()` returning `[]`
    immediately (both `if ($x !== '')` guards skip), identical to `save_admission()`'s pre-this-pass
    behavior.
  - **Scenario H/I (Student ID / Roll Number unchanged):** confirmed by code inspection — the
    identity-claim block runs strictly after `$userId`/`$rollNo` are already finalized in
    `$studentData`, and `_claimIdentityAttributes()` never references either.

## 5. Existing Regression Result

Unchanged from §4: 670/2114, same 10 pre-existing failures, zero new. (Repeated here per the
report's own required section list — not a separate run.)

## 6. Browser UAT — remains unavailable

**Still BLOCKED — no authorized authenticated session available, no browser tool.** Unchanged from
the prior closure pass. No new browser evidence was gathered or fabricated this pass. The new
`studentAdmission.php` fields and the new `save_admission()` identity block have **not** been
clicked through in a real browser.

## 7. Firestore Runtime Behavior — remains unverified

**Unchanged — still `PASS (syntax/compile)` / `NOT VERIFIED (runtime allow/deny behavior)`.**
`firestore.rules` was not touched in this pass (confirmed: `git diff` shows the same single
`apaarIndex` block as before, nothing new). No new dry-run was needed since the file's content is
unchanged from the version already verified compiled successfully in the prior closure pass.

## 8. Remaining Follow-Up Work

1. Real, authenticated, multi-role browser UAT — still the largest open item.
2. Firestore emulator or live-project runtime behavioral confirmation for `apaarIndex` — still
   blocked by the Java 17 vs 21+ requirement in this environment.
3. **FUTURE JOINT SRNO/APAAR IDENTITY HARDENING** (§3 above) — the self-claim-orphaning edge case,
   confirmed reachable, requires touching both `Sr_no_service.php` and `Apaar_service.php` together
   in a separately authorized pass.
4. Whether existing schools use `save_admission()`, `enroll_student()`, or both in practice — still
   not determined (would require usage data or an operator interview, neither available here).
5. Document upload / lead-conversion carry-over logic exists only in `save_admission()`, not
   `enroll_student()` — noted in §1A's comparison table as a genuine, pre-existing structural
   difference between the two paths, unrelated to identity and explicitly not addressed by this
   identity-focused correction pass.

---

## Cross-Path Consistency Table (Part 7)

| Behavior | Admission CRM (`enroll_student()`) | Direct Admission (`save_admission()`) | Result |
|---|---|---|---|
| SRNO input | `sr_no` POST field | `sr_no` POST field — same name | **CONSISTENT** |
| SRNO claim | `_claimIdentityAttributes()` → `Sr_no_service::claim()` | Same helper, same service | **CONSISTENT** |
| APAAR input | `apaar_id` POST field, 12-digit client pre-check | `apaar_id` POST field, same client pre-check | **CONSISTENT** |
| APAAR claim | `_claimIdentityAttributes()` → `Apaar_service::claim()` | Same helper, same service | **CONSISTENT** |
| Duplicate protection | `firestoreCreate` atomic collision primitive, via the shared services | Same | **CONSISTENT** |
| School scope | `$school_id` (`$this->parent_db_key`) | Same variable/value | **CONSISTENT** |
| Student ID | `_nextStudentId($school_id)` | Same method | **CONSISTENT (already shared before this pass)** |
| Roll Number | Untouched by identity code | Untouched by identity code | **CONSISTENT (both leave it alone)** |
| Warning behavior | Structured `warnings[]` array in JSON response | Same warning text, appended into `message` string (no `warnings` array exists in this endpoint's pre-existing response shape) | **Same content, different response shape — a pre-existing, endpoint-specific contract difference this pass did not unify, since doing so would mean changing `save_admission()`'s response shape for every existing caller, not just this feature** |
| Failure behavior | Best-effort, try/catch, never blocks | Same (identical code path via the shared helper) | **CONSISTENT** |

**No silent semantic divergence found.** The one documented difference (warning delivery shape) is
structural/pre-existing, not semantic — the operator sees the identical warning text either way.

---

## Final Verdict (Correction Pass)

# READY WITH CONDITIONS

Both application-level conditions from the closure report are resolved:

- **Direct admission path now has consistent SRNO/APAAR integration** — same two services, same
  field names, same optionality, same duplicate-protection guarantees, zero duplicated identity
  logic (verified via the Cross-Path Consistency Table above).
- **Warning text is now accurate** — for both SRNO and APAAR, in both `Sis.php` call sites and in
  `Readmission.php` — no message promises a manual-recovery UI that doesn't exist.
- **Regression passes** — 670/2114, same 10 pre-existing failures, zero new.
- **No new correctness or security defect was introduced.** The one genuine defect class this pass's
  own analysis surfaced (self-claim orphaning, Issue 3) was deliberately NOT fixed, per explicit
  instruction, and is recorded as future joint-hardening work rather than left undocumented.

**Conditions that remain, unchanged in nature from the prior closure pass:**
1. Real authenticated browser UAT — still not performed, still not fabricated.
2. Firestore rule runtime (emulator) behavior — still not verified, environment-limited.
3. Future joint SRNO/APAAR identity hardening for the self-claim-orphaning edge case — explicitly
   deferred, not silently dropped.
4. Whether both admission paths are actually used by real schools — still unknown, would need usage
   data this environment doesn't have.

None of these four require redesigning anything already built or accepted. **Nothing was staged,
committed, or pushed in this correction pass.**
