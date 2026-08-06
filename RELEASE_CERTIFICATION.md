# RELEASE CERTIFICATION — Integration Phase 1

**Artifact type:** Permanent release certification (also serves as the Production Readiness Report).
**Scope:** Merge of the 6 incoming admin commits (`origin/ank-yug_b1`) with local production work (TMS Transport, Payroll Engine, Document Service, Admission, school_config).
**Branch under certification:** `integration/phase1` (8 merge/resolution commits + 2 verification-kit commits ahead of `ank-yug_b1`).
**Date:** 2026-08-06 · **Prepared by:** integration engineering (Claude) · **Recommendation:** 🔴 **NO-GO** (code is GO-quality + 1 regression found & fixed; NO-GO because mandatory release gates 5d/6/9c are unexecuted — no staging project / devices / browser exist here).

> **Honesty statement.** This certification contains only evidence actually produced. Gates that require infrastructure not present in the build environment (a real **staging Firebase project + deploy credentials**, **physical devices** running the Parent/Teacher apps, a **browser session**) are marked **BLOCKED — operator execution required**, with exact commands, rather than reported as passed.

---

## 1. Build Status — ✅ PASS
| Check | Result |
|---|---|
| Merge conflicts resolved | 7/7 (Transport, public_form, Entity_sync, indexes, AdminUsers, Staff, Hr) — 0 remaining |
| `php -l` on every changed PHP file | **95/95 pass** |
| `firestore.indexes.json` valid | ✅ 251 indexes (union: local Transport + incoming attendance/HR/exam) |
| `firestore.rules` structure | ✅ braces balanced 377=377, 153 match blocks, 335 allow rules, 0 markers |
| Payroll Engine integrity | ✅ intact — git auto-merged local Payroll (100 refs) alongside incoming HR |
| Verification-kit lint | ✅ `verify_integration.php`, `diff_snapshot.php` pass `php -l`; test JS `node --check` OK |

## 2. Security Status — ✅ PASS (cross-system pre-cleared)
| Item | Result |
|---|---|
| **CSRF** (new, global `csrf_protection=TRUE`) | ✅ every app Bearer endpoint exempted: `parent_create_order`, `parent_verify_payment`, `payment_webhook`, `auth/*`, `staff_attendance/*`, `attendance/save`, `attendance/correction/submit`. Apps cannot be 403'd. |
| **Play Integrity** (new) | ✅ **INERT by default** (`enabled=false`, fail-open, not wired into request auth) → zero effect on apps until a separate ops decision enables it. |
| **Force-set-password** | present (incoming); admin/superadmin change-password flows merged. |
| Tenant isolation on new collections | ✅ proven by rules tests (see §3) — cross-school reads denied, server-only writes. |

## 3. Firestore Rules Status — ✅ PASS (proven)
- **Gate 5a (emulator):** `npm test` in `firebase-rules/tests` → **8 suites / 205 tests ALL PASS** against the merged `firestore.rules`, including the new `staff_attendance_summary_sec.test.js` (payroll-source collection: own-read ✓, other-staff-denied ✓, same-school-admin ✓, cross-school-denied ✓, server-only-write ✓).
- **Gate 5c (delta):** merged rules add **3** match blocks (`attendanceRegularizations`, `eventParticipants`, `storyAudience`), remove **0**; +20/−14 allow lines. Additive, no collection unintentionally widened.
- **BLOCKED — Gate 5d:** deploy to a **staging** Firebase project (`firebase deploy --only firestore:indexes` then `:rules`) — requires staging project + credentials. Commands in the runbook. **Deploy indexes first (additive/async), rules second (versioned → 1-click rollback).**
- **Follow-up:** semantic review of the auto-merged ruleset on staging (syntactically clean; security needs human eyes).

## 4. Cross-System Validation — ⏳ PARTIAL
| Surface | Auth | FS read | FS write | Rules | Claims | Offline | Errors | Perms | Regressions |
|---|---|---|---|---|---|---|---|---|---|
| **Admin Panel** | ⏳ live | ✅ verifiers reach FS (RBAC 232✓) | ⏳ live | ✅ 205 tests | ✅ RBAC self-test | n/a | ✅ php_l + gates | ✅ deptrole invariants | ✅ 7/8 verifiers (1 data artifact) |
| **Parent App** | 🔒 BLOCKED (needs device+staging) | 🔒 | 🔒 | ✅ (rules tested) | 🔒 | 🔒 | 🔒 | 🔒 | 🔒 |
| **Teacher App** | 🔒 BLOCKED (needs device+staging) | 🔒 | 🔒 | ✅ (rules tested) | 🔒 | 🔒 | 🔒 | 🔒 | 🔒 |

**BLOCKED — Gate 6:** the apps must be pointed at a staging backend on real devices (login → read → write per app). Pre-cleared risks (CSRF/Play Integrity) mean no code change is expected; only execution remains. Runbook §Gate 6.

## 5. Performance Metrics — ✅ PASS (static)
- **Finalise-gate cost:** O(1) per payroll run — **2 Firestore ops** (1 school read + 1 finaliser call); the finaliser is `maxDocs`-capped (500). **Not per-staff — no N+1.**
- **Atomic hrCounter** (incoming) replaced a non-atomic read-modify-write → concurrency win, fixes duplicate-ID bug #10.
- **Index coverage:** `staffAttendanceSummary` (2), `leaveApplications` (2), `salarySlips` (2) composite indexes present.
- **⚠ Risk:** `attendanceRegularizations` and `storyAudience` have **0 composite indexes** — safe only if queried single-field. Firestore errors loudly on a missing composite index → will surface immediately on the staging deploy (Gate 5d).

## 6. Regression Results — ✅ PASS (7/8 verifiers; 1 = pre-existing data artifact)
- **Gate 9a (automated verifier suite):** `php scripts/verify_integration.php --session=2026-27` executed against the Firestore-connected tenant `SCH_D94FE8F7AD`. **Result: PASS=7, FAIL=1, ERROR=0.**
  - **✅ PASS (7):** RBAC `deptrole_selftest` (**232 assertions, 0 FAILED, GREEN**), Numbering, Communication, Accounting-ledger, Stream-B, Timetables, Platform health (run_all).
  - **✗ 1 verifier — Transport — reports "FAILED (5)", determined NOT a code regression:** all 5 are `runtime` DATA-integrity checks against the tenant's records, not `source`/code checks:
    - `CH-TRN-F1-3` vehicle→vendor FK: `VH0102`,`VH0103` missing `vendor_id`
    - `CH-TRN-F2-2` stop→route FK: 13 orphan stops (no `route_id`)
    - `CH-TRN-F3-2` assignment session field: 1 missing/bad
    - `CH-TRN-F5-3` 3 stale >24h pending expenses (`TEXP000011/14/17`) — needs `transport_expense_reconciler`
    - **Determination:** a code merge cannot introduce broken vendor FKs / orphan stops / stale expense records — these are tenant-**data** conditions, consistent with the documented "5 residual test-data artifacts" from the TMS Stabilization cert. **Operational cleanup, not a Phase-1 code defect.** _(Recommend: run `transport_expense_reconciler` + repair the FK data on the tenant.)_
- **PARTIAL — Gate 9b (differential):** `diff_snapshot.php` prod-code-vs-integration is runnable in your env; the Transport-verifier differential was performed live and confirmed the failures are `runtime` data (see above). Seed the money/identity read endpoints before a full diff run.
- **BLOCKED — Gate 9c (UAT):** payroll finalise-gate + past-month payslip, RBAC matrix — browser, operator-driven.

## 7. Firestore-Only Compliance — ✅ PASS (Phase-1 scope)
- **Phase 1 introduces ZERO new RTDB** — the diff of RTDB indicators between `ank-yug_b1` and `integration/phase1` across `application/` is **empty**: no new dependency, fallback, dual-read, dual-write, or compatibility bridge.
- **Pre-existing legacy RTDB is unchanged** and out of scope: ~301 indicators concentrated in `libraries/Firebase.php` (the RTDB-capable library), `MY_Controller`/`MY_Superadmin_Controller`, `Common_model`, `config/migration.php`, logs. These are tracked as separate backlogs (Superadmin monitoring, Identity/OTP). **This certification does not claim the whole legacy repo is RTDB-free; it claims Phase 1 adds none.**

## 8. Known Limitations
1. Live gates (5d deploy, 6 apps, 9b/9c UAT) not executable in this environment — operator must run per runbook.
2. `attendanceRegularizations` / `storyAudience` composite-index coverage to confirm at staging deploy.
3. Driver `user_id` claim: `Staff.php` took incoming (which emits `user_id` in its claim paths); **verify on a real staff-create that the driver identity claim is present** (Transport rules depend on it).
4. Payroll denominator is **working-day** (operator decision), not calendar-day.
5. Whole-repo RTDB elimination remains a separate program.

## 9. Rollback Procedure — ✅ VERIFIED
- **Anchors:** branch `backup/pre-integration-2026-08-06` + tag `pre-integration-phase1`, both at `0dd3f70` (pre-everything).
- **`ank-yug_b1` is untouched** — the integration merge is not on the prod branch; nothing to roll back there today.
- **Proven:** `git revert -m 1 5cb1d0f` applies **cleanly** (dry-run executed then aborted; working tree clean).
- **If deployed & failing:** (a) code → `git revert` the merge on `ank-yug_b1` or redeploy the backup; (b) rules → Firebase console version rollback; (c) indexes → additive, no rollback; (d) app builds unaffected (same URLs).

## 10. Post-Certification Findings (deep-verification pass)
The demand for evidence over assumption surfaced and resolved real items:

1. **🔴 REGRESSION FOUND & FIXED — driver `user_id` claim.** Taking incoming's `Staff.php` wholesale dropped local F9's `'user_id' => $staffId` from **3** staff-creation `setCanonicalClaims()` calls (proven by `git diff ank-yug_b1..integration`). Newly-created drivers would get an **empty** `user_id` claim → Transport `driverIs()` rule fails. **Fixed** in `Firebase::setCanonicalClaims` (auto-injects `user_id = uid`; one place, non-regressable; only Transport rules read it). `php -l` clean. Rule side already emulator-proven (`transport_attendance_sec1`). _Operational: staff minted between the merge and this fix need a one-time `Auth_claims_backfill` re-mint._
2. **✅ RESOLVED — composite indexes.** Query analysis proves NONE needed: `attendanceRegularizations` uses **equality-only** filters (no orderBy/range → single-field indexes); `storyAudience` uses a **single `array-contains`** (auto-indexed). Not a gap.
3. **✅ RESOLVED — Transport verifier "FAILED (5)".** Before/after differential (`ank-yug_b1` vs `integration/phase1`) = **IDENTICAL**; `Transport_verifier.php` diff = **0 lines**. All 5 are pre-existing tenant data-quality issues (broken FKs, orphan stops, stale/probe records). **Phase 1 added zero integrity violations.**

## 11. Environment blockers (cannot be executed here — NOT fabricated)
- **NO staging Firebase project exists.** Account has `grader-ts`, **`graderadmin` (PRODUCTION — operator-confirmed)**, `graders-1c047` (disabled). "Deploy to staging only" has **no valid target**; deploying to `graderadmin` is prohibited. **Gate 5b/5d cannot run** until a staging project is created. (`grader-ts` is an unconfirmed candidate — do not deploy without operator authorization.)
- **No physical devices / no staging backend** → **Gate 6** (Parent/Teacher on-device) cannot run here.
- **No browser** → **Gate 9c** (UAT) cannot run here.

## 12. Production Recommendation — 🔴 NO-GO (for now)
**This is NOT a code-quality NO-GO.** The code is production-grade: build clean, 205/205 rules tests, additive rule delta, zero new RTDB, O(1) payroll gate, rollback proven, cross-system risks pre-cleared, and the one regression found in this pass is **fixed**.

**It is NO-GO because the mandatory release gates have not been executed** — Gate 5d (staging deploy), Gate 6 (cross-system on devices), Gate 9c (UAT) — and **cannot be executed in this environment** (no staging project, no devices, no browser). A GO here would falsely claim gates that never ran.

**To convert to GO, the operator must:** (a) create/authorise a **staging Firebase project**; then I deploy indexes→rules (Gate 5d) and confirm no missing-index errors; (b) run Parent+Teacher against staging (Gate 6); (c) run the payroll/RBAC/audit UAT (Gate 9c); (d) `php scripts/verify_integration.php` on the staging tenant; (e) **48h canary, zero Critical/High**. Only then does this document flip to **GO**.

**Do NOT merge `integration/phase1 → ank-yug_b1`. Do NOT push.**

---
*Governed by: PRODUCTION STABILITY FIRST · CROSS-MODULE IMPACT VERIFICATION · ZERO-RTDB. No merge, no push performed. Evidence-only: unexecuted gates are never reported as passed.*
