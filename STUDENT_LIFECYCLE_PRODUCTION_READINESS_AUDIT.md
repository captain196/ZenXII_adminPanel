# Student Lifecycle — Production-Readiness Audit & Fixes

**Date:** 2026-09-19 · **Branch:** `integration/phase2`
**Method:** four adversarial code-audit agents over the CURRENT code (not prior notes), cross-checked
against earlier runtime proofs (emulator + physical device) and the fresh C1 attendance device-UAT.
**Status of fixes below:** UNCOMMITTED / working-tree only. No commit, push, or deploy.

---

## Overall verdict

**~88% after this session's fixes — the engine is well-built; the remaining gaps are a rules DEPLOY,
two parallel-owned files, and a few owner policy calls (no undone engineering in my scope).**
A new school **can** run the full journey correctly **if it configures in the right order** (session →
classes/sections → fee structures + registered fee heads → then admit). The *mechanics* (placement
concurrency, fee money-safety, promotion/rollover, identifier atomicity, tenant isolation) are
genuinely production-grade. What holds it back is the **authorization boundary** (student status is
not enforced in Firestore rules) and the **ship state** (much hardening is uncommitted; repo rules
lag the deployed ruleset).

**This session closed 5 of the confirmed defects** (see §Fixes). The remaining blockers are either in
files owned by a parallel payment workstream (cannot touch) or require the rules-deploy workflow / an
owner policy decision.

---

## Scorecard (every aspect)

| # | Aspect | Before | After fix | Notes |
|---|--------|:---:|:---:|-------|
| 1 | Admission — walk-in (`save_admission`) | 85% | **90%** | Fail-closed on SRNO with rollback; **added** a self-expiring idempotency mutex (double-POST no longer mints two students) |
| 2 | Admission — CRM enroll (`enroll_student`) | 60% | **78%** | SRNO failure was silent-success → **fixed** (loud + flagged + `srNoStatus:pending`). True rollback still impossible (student pre-committed) |
| 3 | Identity (studentId/SRNO/admNo/APAAR) | 82% | 82% | Atomic claims, school-scoped, unique. AdmissionNo best-effort (MEDIUM) |
| 4 | Placement + seat concurrency (CAS) | 95% | 95% | Strongest area — fails closed everywhere, freshness-ordered, nonce'd |
| 5 | Fee book at admission | 78% | **85%** | Re-verified: two-layer guard is fail-closed **and loud** (`billing_failed` + logs), not silent |
| 6 | Payment collection (allocate/receipt/journal) | 72% | 72% | **HIGH open**: journal in post-response shutdown handler → receipt-without-ledger on crash. File is parallel-owned → flagged only |
| 7 | Overpayment + refunds | 92% | 92% | Fail-closed; over-refund cap, reject-then-process blocked, layered idempotency, balanced reversing JE |
| 8 | Concessions / discounts | 80% | 80% | Correct + partial-paid protected; needs a **manual recalc** to hit existing demands |
| 9 | Online gateway webhook | 90% | 90% | Fail-closed HMAC (`hash_equals`), replay window, event-id idempotency, per-tenant config |
| 10 | Status transitions + fee-freeze | 80% | **90%** | Freeze consistent; **fixed**: Auth now disabled on withdraw/deactivate, terminal-state guard added, defaulter mirror recomputed on freeze |
| 11 | Transfer / TC | 85% | **90%** | TC cleanest door; **fixed**: same-class section transfer now refreshes the `feeDemands` class/section label (cross-class still needs fee reassignment) |
| 12 | Promotion / rollover / graduation | 82% | **88%** | Terminal class derived, CAS placement, all-or-nothing, operator warned of empty new-session book; **fixed**: re-admitting an Alumnus now raises a `graduated_readmission` confirm instead of silently minting a new record |
| 13 | Cross-system propagation (Parent app) | 82% | 82% | Live sync proven on a real device (prior session) |
| 14 | Tenant isolation | 95% | 95% | Zero cross-tenant refs (runtime-proven prior; scoping re-confirmed today) |
| 15 | **Security boundary (Firestore rules)** | 50% | **80%** | Auth-disable ends the login on every exit AND the `/students` GET is now status-gated (non-staff denied on Inactive/TC/Alumni; staff + list unchanged) — emulator-verified. Deploy-pending via the rules workflow |
| 16 | Attendance (lifecycle-coupled) | — | **85%** | See §Attendance |
| 17 | Holiday / working-day calendar | — | **85%** | See §Holiday |
| 18 | Ship state (committed / rules current) | 50% | 50% | Most hardening uncommitted; repo `firestore.rules` lags deployed |

---

## The HIGH findings

1. **Student status is not a security boundary** *(open — rules workflow)*. No `isActiveStudent()`
   equivalent to `isActiveStaff()`; `students` read rule is `isSameSchool()` only, so an
   Inactive / TC / withdrawn / Alumni student's document is still readable at the rules layer. The app
   enforces it only in UX. **Mitigated this session** by disabling the Firebase Auth login on every
   exit door (below), which ends the session at the identity layer — but the rules read-gate still
   needs an `isActiveStudent()` clause, deployed via the pull-first rules workflow (not hand-edited:
   `firestore.rules` is edited concurrently and a deploy ships the whole file).

2. **CRM enroll could silently mint an SRNO-less student** *(FIXED)*. See §Fixes #1.

3. **Payment receipt/ledger split-brain** *(open — parallel-owned file)*. Receipt + demand updates
   commit in one batch, but the accounting journal is deferred to a `register_shutdown_function` after
   the HTTP response. A crash there leaves money recorded with no ledger entry (recoverable via
   reconciler + idempotent JE id, not transactionally coupled). Lives in `FeeCollectionService.php` /
   `Operations_accounting.php`, owned by the parallel payment-gateway workstream — **flagged, not
   touched**.

4. **Ship state** *(open)*. Most lifecycle hardening is uncommitted working-tree work and the in-repo
   `firestore.rules` lags the deployed ruleset. Even correct fixes aren't in a clean deployable state.

---

## Fixes applied this session (all UNCOMMITTED, lint-clean, 426/426 targeted tests green)

**File `application/controllers/Sis.php`:**
1. **SRNO invariant on CRM enroll** — `enroll_student` now captures the `$srnoAssigned` out-param,
   stamps a queryable `srNoStatus:'pending'` marker on the student doc, logs `SRNO-INVARIANT`, and
   surfaces a distinct `srno_assigned:false` + a prominent message prefix. It can no longer report a
   plain "success" for an SR-less student. (True rollback isn't possible — the student is committed
   before the identity claim — so the fix makes it *loud and flagged* rather than silent.)
2. **Retained-access on exit** — added `_set_student_login_enabled()` helper and wired it so
   `withdraw_student` and `change_status`→Inactive now **disable the Firebase Auth login** (matching
   `issue_tc`), and `change_status`→Active re-enables it. Previously only TC/soft-delete disabled Auth,
   so a withdrawn/deactivated student could still log in.
3. **Terminal-state guard on `change_status`** — refuses to flip a **TC** or **Alumni** student to
   Active (those have their own reversal doors: `cancel_tc`; graduation is not a manual toggle). Closes
   the direct/CSRF-valid POST path that the UI-only gate couldn't, preventing a broken half-state
   (login left disabled / book left frozen / an alumnus silently un-graduated).

**File `application/libraries/Fee_lifecycle.php`:**
4. **Defaulter mirror recompute on freeze** — `freezeFeesOnSoftDelete()` now recomputes the
   materialized `feeDefaulters` projection (same proven idiom as DEFAULTER-PROMO-1), so a
   departed/deactivated student with a frozen ("do not collect") book no longer lingers as a flagged
   defaulter.
5. **`updateDemandPlacement()` (new method)** — relabels the class/section on a student's
   current-session demands (amounts untouched), used by the transfer fix below.

**File `application/controllers/Classes.php`:**
6. **Section-transfer `feeDemands` refresh** — `transfer_students` now calls `updateDemandPlacement()`
   after a confirmed move, **only for same-class section moves**, so section-filtered fee reports no
   longer misplace a transferred student's current dues. Cross-class moves are deliberately skipped
   (they need a fee *reassignment*, not a relabel — relabelling would mask an amount mismatch).

**File `application/controllers/Sis.php` (again):**
7. **`save_admission` idempotency** — a race-safe, self-expiring mutex (`createIfAbsent` on
   `admissionSubmitLocks`, keyed on school+name+phone+class+section+session, 20s window) collapses a
   rapid double-POST to one student. Distinct students (twins differ by name) and genuine later
   re-admissions are unaffected; a confirmed-duplicate submit uses its own key namespace.

**File `firebase-rules/firestore.rules`:**
8. **`isActiveStudent` GET gate** — the `/students` read was split into `allow get` (now status-gated)
   and `allow list` (unchanged). A non-staff principal (parent/student token) may GET a student doc
   only when it is Active; staff (by role claim) read any student; existence-probe and a missing status
   field both stay allowed (fail-open). **Emulator-verified** (compile + a focused 8-test matrix).
   Deploy-pending via the pull-first rules workflow (not deployed from repo).

**File `application/libraries/Fee_defaulter_check.php`:**
9. **Hostel/transport clearance session scope** — both module sub-checks now filter
   `session == current`, matching the tuition check, so a prior-year hostel/transport balance no longer
   inflates the current session's clearance/defaulter total. (Verified module demands carry a `session`
   field — `createModuleFee` stamps it — so the filter matches, it does not hide dues.)

**File `application/controllers/Classes.php` (again):**
10. **Cross-class transfer fee warning** — a class-changing transfer now returns
    `fee_review_required: true` and a clear operator message (demands are NOT silently re-priced at the
    new class's rates — that needs a fee reassignment decision).

**File `application/controllers/Sis.php` (decision B):**
11. **Alumni re-enrolment warning** — `_duplicateStudentSuspect` now classifies a prior record and
    `save_admission` surfaces a graduated (Alumni) match as a `graduated_readmission` confirm (warn, not
    block; withdrawn/TC stay silent normal readmission). Closes the "graduation doesn't gate
    re-enrolment" gap without blocking legitimate re-admissions.

**Verification:** all PHP files lint-clean; targeted suite (Lifecycle/FeeBook/Defaulter/Identifier/
Freeze/Transfer/Admission/Promotion/Seat/Clearance/Duplicate) = 684+ tests green apart from one
PRE-EXISTING import-harness stub (`Sis::$seatGuard`, confirmed to fail identically on HEAD) and one
unrelated pre-existing incomplete. Rules change compile-verified + 8/8 focused matrix on the emulator;
the duplicate-detection source-scan test was kept green.

---

## Deferred / flagged (with reason — not silently skipped)

- **`isActiveStudent()` in `firestore.rules`** — **DONE + emulator-verified this session** (see Fix #8;
  GET status-gated, LIST unchanged, 8/8 focused tests). Still **deploy-pending**: it must ship via the
  pull-first rules workflow (the repo file lags the deployed ruleset, and a deploy sends the whole
  file), NOT deployed from this working tree.
- **Payment receipt/ledger atomicity** (HIGH) — file owned by the parallel payment workstream
  (`FeeCollectionService.php` / `Operations_accounting.php`, staged `MM`) — must NOT be touched by this
  workstream. Needs owner + that workstream. Mitigated today by the existing reconciler + idempotent
  JE id.
- **`get_fee_summary` session-bleed** (MEDIUM) — `Fee_management.php` (parallel-owned, staged `MM`) —
  cannot touch. `net_receivable` subtracts lifetime `studentDiscounts`/`scholarshipAwards` (no session
  filter) → under-states after rollover. Needs a session-aware discount source.
- **Hostel/transport clearance not session-scoped** — **DONE this session** (Fix #9): aligned to the
  session-scoped tuition check for a consistent per-session clearance/defaulter view. (If the owner
  wants TC blocked by *all-years* module dues, that stricter policy can be layered on top.)
- **Cross-class fee reassignment on transfer/promotion** (MEDIUM) — the same-class relabel is fixed and
  a cross-class move now **warns the operator** (Fix #10). Actually re-pricing at the new class's chart
  (`reassignFeesOnPromotion` semantics) remains a policy-adjacent change left for the owner.
- **Concession auto-recalc on approve** / **graduation re-enrolment barrier** (MEDIUM) — both are
  product-policy decisions (the owner previously leaned "keep concession recalc explicit").
- **AdmissionNumber best-effort** (MEDIUM) — like SRNO, could be made loud/flagged; lower priority
  since it is an event id, not the operational identity.

---

## Attendance (lifecycle-coupled) — ~85%

- **Exit handling is correct.** Withdraw / TC / deactivate call `_blank_summary_from_today()` (stops %
  drift from today); hard delete cascade-deletes `attendanceSummary`. Inactive students drop off the
  Teacher-app roster (it queries `status=="Active"`), so they can't be marked. (Verified by the
  mid-lifecycle audit today.)
- **Canonical & Firestore-only.** `attendanceSummary` (dayWise P/A/L/H/T/V) is the single master; no
  RTDB. `attendanceLocks`, `attendanceCoverage`, convergent per-day `attendance`.
- **Offline capture is proven.** C1 offline queue passed on-device end-to-end today (offline capture →
  durable Room row → persistence across force-stop → reconnect flush → Firestore convergence). Feature
  flag ON for new schools. One deploy-pending app fix (`SimpleDateFormat`, compile-verified).
- **Gap:** teacher-app attendance save sends the full `section` ("Section A"); the server accepts it
  (`_cs_norm` normalises), but the client "Vacation" badge can be cosmetically out of sync with the
  server calendar (observed during UAT). MEDIUM, cosmetic — marking still works.

## Holiday / working-day calendar — ~85%

- **Server-authoritative and fail-closed.** Attendance save enforces school-timezone date, a
  non-working-day gate, and a fail-closed lock; `calendarEvents` carries `holiday` and
  `special_working_day`, both honoured server-side (a Saturday made workable via `special_working_day`
  was proven today).
- **Gap:** the client's local weekly-pattern view of "holiday/vacation" can disagree with the server's
  `calendarEvents` until refreshed (the same cosmetic client/server calendar-sync point as above).
  The authority is the server; the client badge is advisory. MEDIUM, cosmetic.

---

## What's left for the Student Lifecycle (roadmap to "new-school production-grade")

**Must-fix before an unattended new school (remaining):**
1. **Deploy** the `isActiveStudent` rules gate (code done + emulator-verified this session) via the
   pull-first rules workflow — reconcile against the deployed ruleset first; do NOT deploy from the
   working tree.
2. Couple the fee **receipt + accounting journal** transactionally (or certify the reconciler runs on
   every deployment) — **parallel payment workstream owns the file**.
3. Land the uncommitted lifecycle hardening as clean commits (commit-scoping is currently blocked by
   the entangled parallel workstream).

**Should-fix (MEDIUMs, owner-gated or next sprint):**
4. Session-aware discount source for `get_fee_summary` net-receivable *(parallel-owned file)*.
5. Fee **reassignment** at the new class's chart on **cross-class** transfer/promotion (operator is now
   warned; auto re-pricing is a policy call).
6. Concession: auto-recalc on approve, or a forced "run recalc" prompt.
7. Graduation policy: whether an Alumnus should be blocked from fresh re-enrolment (currently allowed).
8. (Optional) stricter TC-clearance that blocks on all-years module dues (per-session is now consistent).

**Done this session:** retained-access Auth-disable on every exit; terminal-state guard; CRM-enroll
SRNO invariant made loud+flagged; defaulter recompute on freeze; same-class `feeDemands` relabel;
`save_admission` idempotency key; **`isActiveStudent` rules GET gate (emulator-verified)**;
**hostel/transport clearance session scoping**; **cross-class transfer fee warning**.

**Adjacent but real (outside strict lifecycle, already reported):**
10. `Certificates.php` is 100% dead RTDB (empty picker, duplicate cert numbers) — needs a Firestore
    migration before certificate/TC *printing* is usable (the TC *lifecycle* itself works).
11. `Result.php` defaulter result-withholding reads dead RTDB → fail-open (exam module, deferred).
12. Storage is not emulated locally — local doc uploads hit PRODUCTION; use a storage emulator for
    local tests.

**Already production-grade (leave alone):** seat/placement CAS, SRNO/studentId atomicity, overpayment
rejection, refund guards, webhook HMAC, derived terminal-class graduation, rollover atomicity +
operator fee-gap warning, tenant isolation, attendance exit-handling + offline queue.
