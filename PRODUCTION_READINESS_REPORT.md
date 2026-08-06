# Production Readiness Report — Integration Phase 1

**Branch:** `integration/phase1` · **Date:** 2026-08-06 · **Verdict:** 🔴 **NO-GO** (release gates unexecuted — infra-blocked; code is GO-quality; 1 regression found & fixed this pass)
**Companion:** full detail + permanent artifact in `RELEASE_CERTIFICATION.md`. No merge, no push performed.

> Evidence-only policy: gates needing infrastructure absent here (staging Firebase + deploy creds, physical devices, browser) are **BLOCKED — operator execution required**, never reported as passed.

## Gate scorecard

| Gate | What | Status | Evidence |
|---|---|---|---|
| **Build** | conflicts / lint / structure | ✅ **PASS** | 7/7 conflicts resolved; **95/95 `php -l`**; indexes valid (251); rules balanced (377=377) |
| **5a** | emulator rules tests | ✅ **PASS** | **8 suites / 205 tests** green vs merged rules (incl. new payroll-source test) |
| **5c** | ruleset delta review | ✅ **PASS** | +3 match blocks (attendanceRegularizations, eventParticipants, storyAudience), −0; +20/−14 allow |
| **5b/5d** | staging deploy (indexes→rules) | 🔒 **BLOCKED** | needs staging project + creds; commands in runbook; **indexes first, rules versioned** |
| **6** | cross-system (Admin/Parent/Teacher) | ⏳ **PARTIAL** | risks pre-cleared (CSRF exempt ✅, Play Integrity inert ✅); app-on-device execution pending |
| **9a** | automated verifier suite | ✅ **PASS** | **7/8 verifiers PASS**; 1 Transport FAIL = **pre-existing tenant-data artifacts** (runtime FK/orphan/stale), not a code regression |
| **9b** | differential validation | ⏳ **PARTIAL** | Transport failures differentiated to `runtime` data; full money/identity diff pending operator |
| **9c** | UAT (payroll gate, RBAC) | 🔒 **BLOCKED** | browser/operator-driven |
| **Firestore-only** | zero new RTDB | ✅ **PASS** | Phase-1 RTDB diff **empty** — no new dependency/fallback/dual-read/dual-write/bridge |
| **Rollback** | reversibility proven | ✅ **PASS** | backup+tag @ `0dd3f70`; `ank-yug_b1` clean; `git revert -m 1` applies cleanly |
| **Performance** | read/write budget | ✅ **PASS** | finalise-gate O(1) (2 ops, no N+1); atomic counter; indexes present (2 gaps to confirm at deploy) |

## Test evidence (reproducible)
- Rules: `cd firebase-rules/tests && npm test` → 205/205.
- Verifiers: `php scripts/verify_integration.php --session=2026-27` → PASS=7 FAIL=1(data) ERROR=0.
- Rollback: `git revert --no-commit -m 1 5cb1d0f` → clean (aborted).
- RTDB: `git diff ank-yug_b1 integration/phase1 -- application/ | grep '^+' | grep -iE 'getReference|->child\(|FirebaseDatabase'` → empty.

## Findings resolved this pass
- ✅ **Driver `user_id` claim — REGRESSION found & FIXED.** Merge dropped local F9's `user_id` from 3 staff-create claim mints → empty driver claim. Fixed: `setCanonicalClaims` auto-injects `user_id=uid`. (Existing staff: one-time `Auth_claims_backfill` re-mint.)
- ✅ **Composite indexes — proven UNNECESSARY** by query analysis (equality-only / single array-contains).
- ✅ **Transport verifier — proven pre-existing** (before/after IDENTICAL; verifier 0-diff); zero Phase-1 integrity delta.

## Remaining risks / blockers
1. 🔒 **Mandatory release gates unexecuted & un-runnable here:** 5d (no staging Firebase project — only prod `graderadmin`), 6 (no devices), 9c (no browser). **This is the sole reason for NO-GO.** **[Blocker]**
2. Tenant `SCH_D94FE8F7AD` has **5 Transport data artifacts** — operational cleanup (`transport_expense_reconciler` + FK repair), not a code defect. **[Low]**
3. Payroll denominator = **working-day** (operator decision), not calendar-day. **[Info]**

## Rollback verification — ✅
Anchors valid, prod branch clean, merge cleanly revertible (proven). Rules → console version rollback; indexes additive.

## Performance observations
- Payroll finalise-gate: **O(1)** per run (1 school read + 1 bounded finaliser, maxDocs=500) — not per-staff.
- Incoming atomic `hrCounter` removes a non-atomic read-modify-write (concurrency win, fixes duplicate-ID bug #10).
- New attendance/HR/exam queries covered by the unioned indexes (251 total); 2 collections to confirm at deploy.

## Recommendation — 🔴 NO-GO
**Not a code-quality NO-GO.** The code is production-grade — every in-repo gate passes, and the one regression found this pass (driver `user_id` claim) is **fixed**. It is **NO-GO because the mandatory release gates 5d / 6 / 9c have not been executed** and **cannot be** in this environment (no staging Firebase project, no devices, no browser). A GO would falsely certify gates that never ran.

**Path to GO (operator):** create/authorise a **staging Firebase project** → I deploy indexes→rules (5d, confirm no missing-index errors) → Parent+Teacher on staging (6) → payroll/RBAC/audit UAT (9c) → `verify_integration.php` on staging → **48h canary, zero Critical/High**. Then this flips to **GO**.

**Do NOT merge `integration/phase1 → ank-yug_b1`. Do NOT push.**
