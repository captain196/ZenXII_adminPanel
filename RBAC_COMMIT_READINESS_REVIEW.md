# RBAC Work Package — Commit-Readiness Review

**Status:** REVIEW ONLY — no commit / push / merge · **Date:** 2026-06-06 · **HEAD:** `5e2499f` · branch `ank-yug_b1`
**Determination:** RBAC production-hardened (49/49 validated, 0 defects). Fixes validated-but-uncommitted in the working tree.

---

## 1. EXACT FILES MODIFIED (RBAC scope)

| File | Hunks | Concern |
|---|---|---|
| `application/controllers/AdminUsers.php` | 7 hunks (`@@ -8`, `-190`, `-493`, `-659`, `-844`, `-1079`, `-1130`) | A (3) + B (4) |
| `application/controllers/Admin_login.php` | 2 hunks (`@@ -232`, `-330`) | C (1) + A (1) |

Diffstat: **2 files, 31 insertions / 37 deletions.**

**RBAC documentation artifacts (untracked):** `RBAC_ACCEPTANCE_TEST_CHECKLIST.md`, `RBAC_BLOCKER_create_admin_claims.md`, `RBAC_CLAIMS_PREFLIGHT.md`, `RBAC_CLAIMS_REMEDIATION_PACKAGE.md`, `RBAC_LIVE_VALIDATION_PACKAGE.md`, `RBAC_VALIDATION_EXECUTION_PACKAGE.md`, `RBAC_VALIDATION_RESULTS.md`, `RBAC_COMMIT_READINESS_REVIEW.md` (this file). (`SESSION_CONVERGENCE_AUDIT_ZENX.md` is a separate workstream — exclude from RBAC commits.)

> ⚠️ **Isolation alert:** the working tree ALSO contains unrelated, non-RBAC changes that are **NOT** part of this package and MUST be excluded: `.htaccess`, `Exam.php`, `Entity_firestore_sync.php`, `Exam_read.php`, `application/config/fees_exemption_v2_flags.php`, `application/cache/dashboard/...subscription_info.json`, `Superadmin_perf_probe.php`, `Superadmin_s3_validate.php`, `Phase2a_fidelity.php`, `SUPERADMIN_LATENCY_*.md`. Any RBAC commit must stage **only** `AdminUsers.php` + `Admin_login.php` (+ chosen RBAC docs).

---

## 2. EXACT PURPOSE OF EACH MODIFICATION

| # | File:hunk | Change | Purpose | Concern |
|---|---|---|---|---|
| 1 | AdminUsers `@@ -8` | docblock: drop `rbacRoles dual-write` line; `(primary)`→`(canonical authority)` | doc accuracy after retiring dead collection | A |
| 2 | AdminUsers `@@ -1079` | `save_role`: remove `rbacRoles` setEntity block | retire write-only collection (0 readers) | A |
| 3 | AdminUsers `@@ -1130` | `delete_role`: remove `rbacRoles` removeEntity block | retire write-only collection | A |
| 4 | Admin_login `@@ -330` | remove dead login-time `load_role_permissions` call | it no-op'd (no `fs` in Admin_login); hydration is MY_Controller's job | A |
| 5 | AdminUsers `@@ -493` | `create_admin` claims → camelCase + `roleLabel` | new admins get login-valid claims (fixes the blocker) | B |
| 6 | AdminUsers `@@ -659` | `update_admin` role-change claims → camelCase | role change keeps claims login-valid | B |
| 7 | AdminUsers `@@ -190` | `_process_pending_syncs` claims → camelCase | retry path emits login-valid claims | B |
| 8 | AdminUsers `@@ -844` | `reset_password` claims → camelCase (+`roleLabel`; `must_change_password` trio untouched) | reset keeps claims login-valid | B |
| 9 | Admin_login `@@ -232` | reader bridge `schoolId ?? school_id`, `schoolCode ?? school_code` | tolerate legacy snake claims (transitional) | C |

---

## 3. SEPARATION OF CONCERNS

- **Commit A — RBAC cleanup** (hunks 1-4): retire write-only `rbacRoles` dual-write, correct docblock, remove dead Admin_login hydration call. *Independent of the claims issue.*
- **Commit B — Claims remediation (writer/canonical)** (hunks 5-8): align all AdminUsers Firebase-claim writers to canonical camelCase + `roleLabel`. *Fixes the login blocker for new/updated admins.*
- **Commit C — Claims remediation (reader/transitional bridge)** (hunk 9): tolerate legacy snake claims in Admin_login. *Temporary; scheduled for retirement.*
- **Validation artifacts**: the RBAC `.md` docs — analysis/validation record; no runtime impact.

B + C together = the "claims remediation hybrid." A is orthogonal.

---

## 4. REMAINING CARRIES (non-blocking, tracked)
- **C-1 Claims convergence:** `Ssa_reset.php` + `Admin.php` still emit snake claims (admin-web consumers) → covered by the bridge for now; migrate to camelCase, then **retire Commit C's bridge**. Mobile writers (`Staff.php`, `Sis.php`) stay snake (Api_auth contract) until a separate mobile-convergence effort. (RBAC_CLAIMS_REMEDIATION_PACKAGE.md C3/C4.)
- **C-2 RBAC taxonomy:** `Homework`/`Device Management`/`Message Monitor`/`Red Flags` are bypass-only (not in RBAC_MODULES); `Operations` is coarse (Library/Transport/Inventory/Assets); HR Manager lacks SIS. Design decisions, out of scope.
- **C-3 Cosmetic:** bare `/fees` 404 (real entry `fees/dashboard`).
- **C-4 Existing-admin backfill:** NOT needed (census: 0 snake admins on tenant; SSA0001 already camelCase; bridge covers any stragglers).

---

## 5. RISK ASSESSMENT — **LOW**

| Dimension | Assessment |
|---|---|
| Data migration | None. No Firestore schema change. Claims overwrite on next write only. |
| Blast radius | `create_admin`/`update_admin`/`reset_password`/`pending_sync` (admin provisioning) + login reader. Admins are admin-web-only → no mobile impact. |
| Mobile contract | Untouched (`Staff`/`Sis` snake writers + `Api_auth` snake reader unchanged). ✅ |
| rbacRoles removal | Safe — 0 readers (grep-verified). |
| Bridge (C) | Additive/tolerant; cannot weaken fail-closed (empty in both casings → still rejected, verified). Risk = perpetuating snake if not retired → mitigated by C-1 tracking + explicit RETIRE comment. |
| Fail-closed | Preserved and live-verified. |
| Validation | End-to-end live across 5 roles + C8 (49/49). |
| Isolation | Main operational risk = accidentally bundling unrelated working-tree files → mitigated by precise staging (§7). |

---

## 6. ROLLBACK STRATEGY
- Pure code (claims = Firebase Auth data overwritten on next write; reader = logic). **No Firestore/RTDB data migration to undo.**
- `git revert <C>` → strict camelCase read (safe; new admins already camelCase via B).
- `git revert <B>` → writers back to snake (new admins rely on bridge C if still present).
- `git revert <A>` → restores rbacRoles dual-write + docblock + dead call (all inert anyway).
- Any/all revertable independently and instantly; no cleanup. (Single-package option reverts all-or-nothing.)

---

## 7. RECOMMENDED COMMIT STRATEGY

### Staging note (applies to both options)
Only **Commit A** needs hunk-level splitting (its hunks interleave with B in AdminUsers and with C in Admin_login). Method: temp-swap (backup working → `git checkout HEAD --` the 2 files → re-apply only A's edits → `git add` → restore working) — already rehearsed successfully.
**After A is committed, B and C become whole-file:** `AdminUsers.php` working-vs-HEAD = B only (`git add AdminUsers.php`), `Admin_login.php` = C only (`git add Admin_login.php`). No further hunk surgery.

### Option A — separate commits A / B / C  ✅ RECOMMENDED
```
A  refactor(rbac): retire write-only rbacRoles dual-write + stale docblock; drop dead Admin_login hydration call
B  fix(adminusers): align Firebase custom claims to canonical camelCase (+roleLabel)
C  fix(admin-login): transitional snake_case claims reader bridge [RETIRE after claims convergence]
```
Plus optionally: `D  docs(rbac): validation + remediation package` for the `.md` files.

**Rationale:**
- The **transitional bridge (C) will be deleted later** — isolating it makes that retirement a clean one-commit `git revert`/removal with zero collateral. Bundling it hides a temporary change inside permanent ones.
- A is a genuinely **separate concern** (dead-code/collection cleanup) from the claims fix — distinct bisect/blame story.
- Independent rollback semantics per concern.
- Matches the project's demonstrated discipline (clean, reviewable boundaries).

### Option B — single finalized RBAC hardening package
```
fix(rbac): production-harden AdminUsers/RBAC — retire rbacRoles, canonical camelCase claims, transitional login bridge
```
**Pros:** one atomic unit; simplest history. **Cons:** bundles a *temporary* bridge with *permanent* changes (messier future retirement); coarse bisect; all-or-nothing revert.

---

## 8. RECOMMENDATION
**Adopt Option A (separate A / B / C + optional docs commit D).** It keeps the temporary bridge isolated for clean future retirement, separates orthogonal cleanup from the claims fix, and gives per-concern rollback — at the cost of one extra staging step for Commit A only (already proven). Choose Option B only if a single atomic RBAC changeset is explicitly preferred for release packaging.

*Review only. Nothing committed, staged, pushed, or merged. RBAC change set = `AdminUsers.php` + `Admin_login.php` (+ RBAC docs); all other working-tree files are unrelated and excluded.*
