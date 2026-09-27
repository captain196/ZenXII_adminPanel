# Student Lifecycle — Closure & Deploy Runbook

**Date:** 2026-09-19 · **Branch:** `integration/phase2`
**Purpose:** finish the remaining lifecycle items *the proper way*. Update (2026-09-19): after you
authorized it and I confirmed the parallel payment session is **offline/stale** (files untouched
1–3 weeks, lint-clean), I completed the safe code items too. Current state:
- **`get_fee_summary` session-bleed — FIXED** (§2b).
- **Payment atomicity — RESOLVED as handled-by-design** (reconciler already implemented); one ops
  check remains (§2a). No code change — it's a deliberate architecture + would corrupt parallel WIP.
- **Decisions C, D, E — DONE, made DYNAMIC + FULLY WIRED** (engine + operator UI toggles on the
  Fees policy screen, safe OFF defaults) (§3).
- **Rules gate — DONE + emulator-verified**; only the DEPLOY remains, and it must run from a clean
  checkout, not this tree (§1).

**The ONLY two things left are not code** — they are (1) the rules **deploy** from a clean checkout,
and (2) an **ops confirmation** that the `AccountingReconciler` cron runs in production. Everything
implementable from this working tree is implemented, verified, and lint-clean.

> Nothing in this session is committed, pushed, or deployed. `firebase deploy` has NOT been run.

---

## 1. Deploy the `isActiveStudent` rules gate — RUNBOOK (needs prod access + explicit go)

**Status:** code DONE + emulator-verified this session (compile OK; focused 8/8 matrix — parent denied
on Inactive, staff allowed, list unchanged). It lives in the working tree only.

**Why not just `firebase deploy` now:** a Firestore rules deploy ships the **entire** `firestore.rules`
file. The in-repo file **lags the deployed ruleset** — per the go-live audit it is missing SEC-1 write
hardening that exists only in production. Deploying the repo file as-is would **revert live security
fixes**. So the gate must be applied *on top of the authoritative deployed ruleset*, not shipped from
here.

**Proper sequence (operator with `graderadmin` access runs this):**

```bash
cd firebase-rules

# 1. PULL-FIRST: fetch the authoritative DEPLOYED ruleset into a scratch file.
#    (Firebase Console → Firestore → Rules shows the live source; or use the
#    Rules REST API with a prod token. Do NOT assume the repo file is current.)
#    Save it as firestore.rules.LIVE  and DIFF it against the repo file:
git --no-pager diff --no-index firestore.rules.LIVE firestore.rules

# 2. Apply ONLY the students-match change onto the LIVE file:
#    replace the /students `allow read: if isSameSchool();` line with the
#    get/list split from this session (the exact block is in the repo file,
#    match `/students/{docId}` → `allow get`/`allow list`). Keep every other
#    live line untouched.

# 3. Compile-verify the reconciled file on the emulator (no prod contact):
cd tests
JAVA_TOOL_OPTIONS="-Xmx512m" npx firebase emulators:exec --only firestore \
  --project=zenxii-rules-test "node _rules_compile_check.js"
# then the behavioral matrix (must be 8/8):
JAVA_TOOL_OPTIONS="-Xmx512m" npx firebase emulators:exec --only firestore \
  --project=zenxii-rules-test "npx jest students_status_read --runInBand --forceExit"

# 4. Deploy rules ONLY (separate from PHP code, per CLAUDE.md):
cd ..
firebase deploy --only firestore:rules --project graderadmin

# 5. Re-run the live rules regression (sec1/sec4/sec6 suites) against the
#    deployed project to confirm no SEC-* regression.
```

**DEPLOY-READY ARTIFACT PREPARED (2026-09-19):** `firebase-rules/firestore.rules.STUDENTGATE_READY`
= the deployed-baseline snapshot (`firestore.rules.LIVE_BASELINE_34e7a058`) with ONLY the students
GET gate applied — no other session's WIP. **Compile-verified** on the emulator (accepted the ruleset);
the gate's behaviour is covered 8/8 by `tests/students_status_read.test.js`. Evidence it's isolated:
the baseline already carries the Institution read-axis but NOT the write-gate, and `HEAD ≠ baseline`
(md5 differs), so this artifact adds the students gate and nothing else. **To deploy** (operator, after
confirming the baseline still matches live — it was the deployed snapshot as of ~Sep 12): copy it over
`firestore.rules` in a CLEAN checkout and `firebase deploy --only firestore:rules --project graderadmin`.
I did NOT run the deploy: I cannot confirm from here that the baseline is still current, and shipping a
stale ruleset could revert rules deployed since.

**Blocking condition — CONFIRMED by evidence, not assumption (2026-09-19):** with authorization I
checked the deploy path. Firebase CLI *is* authenticated and can see `graderadmin`, so a deploy would
technically run. But `git diff firebase-rules/firestore.rules` shows the working tree is **contaminated
with another session's uncommitted work** — a full "Institution Lifecycle" institution read-gate +
write-gate (2026-08-21), NOT just my students block. Deploying from here would ship that other
session's unverified, half-finished rules to the production ERP. That is precisely the concurrent-edit
hazard CLAUDE.md warns about ("a deploy ships the whole file including anyone else's half-finished
work"). **So the deploy is correctly NOT executed.** My gate must be applied in ISOLATION onto the
authoritative deployed ruleset (steps 1–4 above), from a clean checkout — never from this shared
working tree. The reusable test `firebase-rules/tests/students_status_read.test.js` ships with the
change so the gate stays regression-covered wherever it is applied.

---

## 2. Payment receipt/ledger atomicity + `get_fee_summary` session-bleed — HANDOFF (parallel-owned)

These live in files the **parallel payment-gateway workstream** owns and has staged (`MM`):
`FeeCollectionService.php`, `Operations_accounting.php`, `Fee_management.php`. **Re-verified 2026-09-19:
still `MM` (live uncommitted work), and the atomicity "gap" is a DELIBERATE design** — an Option-B
early-response flush (XAMPP has no `fastcgi_finish_request`) that defers journal/defaulter/summary to a
`register_shutdown_function`, backed by an idempotent `JE_FEE_{receiptKey}` + reconciler. Editing these
files would overwrite another session's unsaved work AND override that design, so this stays a **spec
for that workstream** (not a fix I can safely apply). Unblock: have the parallel session commit/land its
work first, then apply — or confirm it is finished, and I will.

**2a. Receipt/ledger atomicity (HIGH) — RESOLVED as handled-by-design; one OPS check.**
Investigated the current code: the deferral is a DELIBERATE architecture (Option-B early-response
flush because XAMPP has no `fastcgi_finish_request`), and its safety net is **already implemented** —
`AccountingReconciler.php` scans `feeReceipts` with `status=posted` and no `JE_FEE_{receiptKey}` ledger
and re-runs each idempotently (`retry_payment_processing` route also exists). So this is
eventual-consistency-with-recovery, not unhandled data loss. **No code change** (editing that design
would also corrupt the parallel session's uncommitted work). The ONE remaining item is an **ops check**:
confirm `AccountingReconciler` runs on the production cron/worker schedule (platform-level, per the
fleet-cron rule). If desired later, the parallel workstream can add a write-time balance assertion in
`_create_fee_journal_v2` (parity with `create_journal`@`Operations_accounting.php:538`).

**2b. `get_fee_summary` session-bleed (MEDIUM) — FIXED this session.**
`get_fee_summary` now derives the discount total from THIS session's `feeDemands.discountAmount`
(read from the same session-scoped query used for the due total), replacing the lifetime
`studentDiscounts` sum — so the discount line is session-consistent with every other figure and no
longer under-states `net_receivable` after a rollover. (Scholarships still read `scholarshipAwards`
lifetime; left untouched — a separate, less-clear source. Noted for the parallel team.) Verified:
lint-clean, 1221 tests green (only the pre-existing `Sis::$seatGuard` harness stubs + one incomplete).
NOTE: this edit lands in the parallel-owned `Fee_management.php` working-tree diff; you authorized it
after confirming that session is offline/stale (untouched since Sep 12) — commit-scope accordingly.

---

## 3. Owner decision pack (policy — needs a call, then I implement)

| # | Decision | My recommendation |
|---|----------|-------------------|
| A | **Concession auto-recalc on approve?** Approving a concession does not reduce existing demands until `Fees::recalc_unpaid_discounts` is run. | Add a **forced prompt** ("run recalc now?") on approve rather than silent auto-recalc — keeps the explicit audit trail the owner previously preferred, but removes the silent-under-application gap. |
| B | ~~Block re-enrolment of an Alumnus?~~ | **DONE this session** (authorized) — `save_admission` now surfaces a graduated-Alumni prior record as a `graduated_readmission` confirm (warn, not block; withdrawn/TC stay normal readmission). Verified: 684 tests, source-scan test updated, 0 new failures. |
| C | ~~Cross-class transfer fee re-pricing?~~ | **DONE — made DYNAMIC.** Per-school flag `schools.feePolicy.repriceOnClassTransfer` (default false = keep existing demands + warn). When true, a cross-class transfer archives unpaid old-class tuition and regenerates at the new class chart via `reassignFeesOnPromotion` (paid history + one-off module charges preserved); the response reports `fees_repriced`. No policy invented — the school decides. |
| D | ~~Stricter TC clearance?~~ | **DONE — made DYNAMIC.** Per-school flag `all_sessions_clearance` in the existing TC dues policy (default false = current per-session). When true, the TC-issue path checks ALL sessions' fees/hostel/transport (a leaving student settles every year). Threaded only through the TC path via a new `calculateClearanceStatus($id,'',$allSessions)` arg; withdrawal + defaulter report stay per-session. |
| E | ~~AdmissionNumber best-effort → loud flag?~~ | **DONE this session** — both `save_admission` and `enroll_student` now return `admno_assigned` (false = admitted without an admission number, needs follow-up), mirroring the SRNO signal. 685 tests green. |

Say the word on any of A–E and I'll implement it (all are in editable, non-parallel files).

---

## 4. Commit-scoping (why the fixes are still uncommitted)

A clean scoped commit of this session's lifecycle fixes is currently blocked because `Sis.php`,
`Fee_management.php`, `routes.php` and `studentAdmission.php` are **entangled** with the parallel
payment workstream's uncommitted work (whole-file/CRLF churn; `git add -p` is non-interactive here).
**Proper path:** the parallel workstream commits its work first (clean base), then this session's
hunks in `Sis.php` / `Classes.php` / `Fee_lifecycle.php` / `Fee_defaulter_check.php` commit as one
scoped feature commit; `firestore.rules` deploys separately via §1.

**Files changed this session (all working-tree, uncommitted):**
`application/controllers/Sis.php`, `application/controllers/Classes.php`,
`application/libraries/Fee_lifecycle.php`, `application/libraries/Fee_defaulter_check.php`,
`application/controllers/Fee_management.php` (get_fee_summary session-bleed + save_blocking_policy
payload — parallel-owned; commit-scope with care), `application/libraries/Fee_dues_check.php`
(2 new policy keys), `application/views/fees/defaulter_report.php` (2 policy toggles + JS),
`firebase-rules/firestore.rules`, `firebase-rules/tests/students_status_read.test.js` (new),
`STUDENT_LIFECYCLE_PRODUCTION_READINESS_AUDIT.md` (new), this runbook (new).

**New per-school settings — NOW FULLY WIRED (engine + UI), default OFF:**
Both live in the fees policy doc `feeSettings/{school}_{session}_blocking_policy` and are settable on the
**Fees → Dues-Based Blocking** screen (`views/fees/defaulter_report.php`), persisted via
`save_blocking_policy` + `Fee_dues_check` (whitelisted in `POLICY_DEFAULT`):
- `all_sessions_clearance` (D) — TC requires ALL-years settlement; read by `issue_tc` →
  `calculateClearanceStatus($id,'',true)`.
- `reprice_on_class_transfer` (C) — cross-class transfer re-prices at the new class chart; read by
  `Classes::transfer_students` → `reassignFeesOnPromotion`.
Two checkboxes added to the policy card with load/save JS; verified lint-clean + 1143 tests green.
