# Remediation Package — Firebase Custom-Claims Contract Mismatch (admin login blocker)

**Status:** DESIGN ONLY — no implementation · **Date:** 2026-06-06 · **HEAD:** `5e2499f` · branch `ank-yug_b1`
**Defect:** AdminUsers-provisioned admins write snake_case Firebase claims; `Admin_login` reads camelCase → `ADMIN_LOGIN_AUTHZ_MISSING` → cannot authenticate (pre-RBAC failure).

---

## 0. THE TWO-CONSUMER REALITY (root of the whole issue)

Firebase custom claims are consumed by **two readers with different key schemas**:

| Consumer | Reads | Keys | Writers that must match |
|---|---|---|---|
| **Admin web** — `Admin_login::_try_firebase_admin_login` | camelCase | `schoolId`, `schoolCode`, `roleLabel`, `role` | admin-account provisioning |
| **Mobile/API** — `Api_auth` (Parent + Teacher apps) | snake_case | `school_id`, `school_code`, `parent_db_key`, `role` | student/parent/teacher provisioning |

Evidence: `Admin_login.php:235-238` (camelCase) vs `Api_auth.php:51,129,177` (snake_case). Only **SA onboarding** (`Superadmin_schools.php:361-365, 734-738`) emits camelCase + `roleLabel`. **Everyone else emits snake_case.**

**Consequence:** any account whose *consumer* is admin-web but whose *writer* emits snake_case cannot log in. That is exactly the AdminUsers admin accounts.

---

## 1. EXACT FILES & METHODS AFFECTED

### In scope (admin-web consumers writing snake_case → broken)
| File | Method | Line | Notes |
|---|---|---|---|
| `application/controllers/AdminUsers.php` | `create_admin` | 494-499 | **the reported blocker** |
| `application/controllers/AdminUsers.php` | `update_admin` (role-change) | 659-664 | role change re-emits snake |
| `application/controllers/AdminUsers.php` | `_process_pending_syncs` | 192-197 | retry path re-emits snake |
| `application/controllers/AdminUsers.php` | `reset_password` | 842-849 | snake + `must_change_password` |

### Same-family (admin-web consumers) — flag for bundling/sequencing
| File | Method | Line | Risk |
|---|---|---|---|
| `application/libraries/Ssa_reset.php` | SSA password reset | 67-73 | **resetting an SSA reverts claims to snake → SSA can no longer log into admin-web** |
| `application/controllers/Admin.php` | legacy admin create | 1169-1174 | if reachable, produces broken admins |

### Explicitly OUT of scope — DO NOT TOUCH (mobile consumers; snake is correct)
`Staff.php` (777, 1414, 1793, 2133, 2328), `Sis.php` (2022 parent, 2689 student). These feed `Api_auth` (mobile). Flipping them to camelCase would **break the Parent/Teacher Android apps**. (Caveat below: Staff-provisioned users who *also* need admin-web access are affected — see §5 Option B.)

`Superadmin_admins.php:126` (`role: super_admin`, platform super-admin) uses a separate login path — out of scope.

---

## 2. CURRENT CLAIM SCHEMA (admin writers, broken)
```php
setFirebaseClaims($adminId, [
    'role'          => $role,                 // ok (also read camel-side)
    'school_id'     => $this->school_id,      // snake → Admin_login reads schoolId  => ''
    'school_code'   => $this->school_code,    // snake → reads schoolCode            => ''
    'parent_db_key' => $this->parent_db_key,  // snake
    // 'roleLabel' MISSING → displayRole falls back to role (ok), but schoolId='' kills login first
]);
```

## 3. TARGET CLAIM SCHEMA (canonical — matches Admin_login + SA precedent)
```php
setFirebaseClaims($adminId, [
    'role'        => $role,        // machine/role name
    'roleLabel'   => $role,        // display (admins: same as role)
    'schoolId'    => $this->school_id,
    'schoolCode'  => $this->school_code,
    'parentDbKey' => $this->parent_db_key,
    // reset_password only: keep must_change_password / password_reset_at (verify reader casing — see §4)
]);
```
Mirrors `Superadmin_schools.php:362-364` exactly. camelCase canonical, single schema for the admin-web consumer.

---

## 4. COMPATIBILITY CONSIDERATIONS

- **Admins are admin-web-only** — they are never consumed by `Api_auth`/mobile. So flipping admin writers to camelCase has **no mobile blast radius**. Safe.
- **`must_change_password` / `password_reset_at`** (reset_password): confirm which casing the first-login gate reads before renaming; if a reader expects `must_change_password`, keep it. (Verification item, not assumed.)
- **`parentDbKey` vs `parent_db_key`**: `MY_Controller` uses `$this->parent_db_key` (session-derived) for the admin-status RTDB check; confirm whether that value comes from claims or from the school-doc hydration. If from claims, camelCase rename must be consumed consistently. (Verification item.)
- **No new RTDB usage** — this is a Firebase Auth claims change only; Firestore/Auth-first preserved.
- **Fail-closed preserved** — login already fails closed on missing `schoolId`; the fix makes legitimate admins resolve a non-empty `schoolId`, it does not weaken any guard.
- **Single source of truth** — the deeper tension (admin=camel, mobile=snake) is NOT resolved by this package; it is a documented divergence. True single-schema convergence (unify mobile to camelCase) is a separate cross-system effort touching `Api_auth` + the Android apps. **Out of scope.**

---

## 5. TWO REMEDIATION STRATEGIES

### Option A — Writer-side camelCase (canonical; RECOMMENDED for rule-alignment)
Change the 4 AdminUsers writers (+ Ssa_reset, Admin) to emit the §3 schema.
- ✅ Aligns with "camelCase canonical", "no compat layer".
- ✅ No mobile impact.
- ❌ Fixes only the writers changed; a Staff-provisioned user who needs admin-web is **not** fixed.
- ❌ Requires a **one-time claims backfill** for already-created snake admins (they keep broken claims until re-set).

### Option B — Reader-side tolerance in `Admin_login` (single-point; mild compat shim)
`$schoolId = $claims['schoolId'] ?? $claims['school_id'] ?? '';` (and `schoolCode`, `roleLabel ?? role`).
- ✅ One small change fixes **every** admin-web login regardless of writer (AdminUsers, Ssa_reset, Admin, and Staff-provisioned users needing web).
- ✅ **No claims backfill needed** — existing snake admins work immediately.
- ✅ No mobile impact.
- ❌ Introduces a reader-side normalization = a compatibility layer (rule tension), and does not advance camelCase-for-writers.

**Recommendation:** Given the dual-consumer reality and the existing-admin population, a **hybrid** is cleanest:
1. **Now (this package):** Option A on the 4 AdminUsers writers (canonical fix for the reported blocker) **+** Option B reader tolerance as a *strictly-required* bridge (it's the only way to (a) avoid a risky multi-writer sweep + backfill and (b) cover Staff-provisioned web users). Mark the reader shim as temporary.
2. **Follow-up (separate):** migrate Ssa_reset + Admin + (eventually) unify mobile to camelCase, then **remove** the reader shim → true single source of truth.

Operator decides A-only, B-only, or hybrid. The rest of this package covers both.

---

## 6. IMPACT — create_admin
- New admins get camelCase claims → `Admin_login` resolves `schoolId`/`roleLabel` → login proceeds to RBAC. Unblocks C1/C2.
- `roleLabel = role` → `displayRole = role name` → `load_role_permissions(role)` resolves the correct Firestore role set (already verified). No RBAC-logic change.
- staff doc lookup already satisfied: `saveStaff` writes `staff/{schoolId}_{adminId}` via `docId()` (`Firestore_service.php:164`), status defaults to Active. (Verify status field present.)

## 7. IMPACT — update_admin
- Role change re-emits camelCase → role/permission change propagates to claims correctly (and session perms refresh ≤300s per existing RBAC path).
- No change to the Firestore `admins`/`staff` writes already present.

## 8. IMPACT — existing admins
- **Option A:** existing snake-claim admins remain non-loginable until a **one-time backfill** re-sets their claims to camelCase. Backfill = idempotent job: read `admins` collection for the tenant(s), for each call `setFirebaseClaims(adminId, {camelCase…})`. (On test tenant `SCH_D94FE8F7AD` the `admins` collection is reportedly sparse/empty — confirm; if empty, no backfill needed there.)
- **Option B / hybrid:** no backfill needed — reader tolerates existing snake claims.

## 9. IMPACT — Firebase Auth custom claims
- Claims objects for admin accounts change key casing (data change in Firebase Auth, not Firestore). Custom claims are overwritten wholesale by `setCustomUserClaims` (verbatim) — so each affected account's claims are replaced with the camelCase object on next create/update/reset (Option A) or left as-is (Option B).
- No claim is removed that a live consumer needs (admins aren't mobile consumers).
- Token propagation: existing logged-in sessions are unaffected (claims read at login); next login picks up new claims.

---

## 10. ROLLBACK PLAN
- **Option A:** pure code revert (`git revert`) of the writer edits → reverts to snake_case emission. Backfilled claims (if run) are camelCase and harmless to admin-web; to fully revert, re-run an inverse backfill (snake) — but there's no reason to (camelCase is canonical). No Firestore data change to undo.
- **Option B:** `git revert` the Admin_login reader change → reverts to strict camelCase read. Instant, no data.
- All changes are code + Auth-claims (no Firestore schema change) → low-risk, fast rollback.

## 11. VERIFICATION PLAN
- **Pre:** confirm `must_change_password` reader casing; confirm `parent_db_key` session source; confirm `saveStaff` writes `staff/{schoolId}_{adminId}` with status (login defaults Active if absent).
- **Unit/probe (read-only):** after the fix, create one ephemeral admin → inspect its Auth claims = camelCase + roleLabel → real login via `admin_login/check_credentials` returns a session (no `ADMIN_LOGIN_AUTHZ_MISSING`) → delete. (This is exactly the live acceptance run we halted.)
- **Regression:** SA SSA login still works (unchanged path); **mobile** Parent/Teacher token auth still works (snake writers untouched) — smoke `Api_auth` on a teacher token.
- **RBAC matrix:** resume the 15-cell acceptance run (C1/C2/C8) — now unblocked.
- **Grep gate:** no admin-web writer emits `school_id`/`school_code` after Option A; (Option B) Admin_login reads `?? school_id`.

## 12. EXPECTED DIFF SIZE
- **Option A (AdminUsers 4 writers):** ~16-20 LoC across 1 file. +Ssa_reset +Admin: ~+10 LoC, 2 files.
- **Option B (Admin_login reader):** ~3-5 LoC, 1 file.
- **Hybrid:** ~20-25 LoC, 2 files (AdminUsers + Admin_login).
- **Backfill (Option A only):** ~40-60 LoC one-off idempotent job (separate, not committed to runtime).

## 13. COMMIT BOUNDARIES
- **C1 — AdminUsers claims → camelCase** (create_admin, update_admin, _process_pending_syncs, reset_password). The blocker fix.
- **C2 — (if hybrid) Admin_login reader tolerance** (`schoolId ?? school_id`, `roleLabel ?? role`).
- **C3 — (follow-up, separate) Ssa_reset + Admin.php claims → camelCase.**
- **C4 — (follow-up, separate) existing-admin claims backfill** (one-off job, if Option A path chosen).
Each independently revertable. C1 alone unblocks the reported defect for new admins; C2 covers existing + Staff-provisioned web users.

---

## 14. ARCHITECTURAL RECONCILIATION
| Rule | This package |
|---|---|
| Firestore-first | ✅ no DB authority change; Firebase Auth claims only |
| camelCase canonical | ✅ Option A makes admin writers camelCase (matches SA precedent) |
| no new RTDB usage | ✅ none added |
| no compat layer unless strictly required | ⚠️ Option B reader shim is a compat layer — justified only as a "strictly required" bridge for existing + Staff-provisioned web users; removed after full convergence |
| fail closed | ✅ preserved; fix lets legitimate admins resolve schoolId, no guard weakened |
| single source of truth | ⚠️ partial — admin(camel)/mobile(snake) split remains; full unification is a separate cross-system effort, explicitly out of scope |

---

## 15. RECOMMENDATION
Approve **Hybrid (C1 + C2)**: it unblocks all admin-web logins immediately (new + existing + Staff-provisioned), needs no risky backfill, and keeps mobile intact — then schedule C3 + (optional) C4 + mobile convergence to retire the reader shim and reach a true single source of truth. If strict no-compat-layer is mandatory, approve **C1 only + C4 backfill** (Option A, canonical-pure) and accept that Staff-provisioned web users remain a separate fix.

*No implementation performed. Live validation remains halted pending this fix. RBAC authorization logic remains verified (34/49, 0 defects).*
