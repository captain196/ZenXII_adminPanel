# H3 — Claims-Convergence Retirement Roadmap (path to retire Commit C)

**Status:** ANALYSIS ONLY — no implementation / commit / push / merge
**Date:** 2026-06-06 · **HEAD:** `3311fac` (A+B+C committed) · branch `ank-yug_b1`
**Objective:** define the exact, safe path to retire the Commit-C transitional snake_case bridge and reach a single canonical camelCase claims contract.

---

## 0. HEADLINE FINDING (read first)
There are **two live claim-consumer contracts**:
- **Admin-web** (`Admin_login::_try_firebase_admin_login`) → **camelCase** (`schoolId`, `schoolCode`, `roleLabel`) + Commit-C bridge tolerating snake.
- **Mobile** (`Api_auth` / `verifyFirebaseToken`, Parent + Teacher apps) → **snake_case** (`school_id`, `school_code`, `parent_db_key`).

**Staff-provisioned users (teachers) get snake_case claims AND have admin-web access** (`Admin::index` has no redirect for Teacher/Class Teacher → they land on the generic dashboard; verified live). Therefore the Commit-C bridge is **load-bearing for real teachers/staff today**, not merely cover for stragglers.

**Consequence:** Commit C **cannot be fully retired by migrating admin-only writers alone.** Full retirement requires resolving the staff/teacher dual-contract (either confirm teachers never use admin-web — they currently can — or converge the mobile contract to camelCase). This roadmap defines both the minimal step and the full path.

---

## 1. CURRENT STATE

### 1.1 Claim WRITERS

| File | Method | Claim keys | Schema | Affected user type | Web? | Diff to camelCase | Rollback |
|---|---|---|---|---|---|---|---|
| AdminUsers.php | create_admin / update_admin / reset_password / _process_pending_syncs | role, **roleLabel, schoolId, schoolCode, parentDbKey** | ✅ camelCase (Commit B) | School Admin / custom | yes | — (done) | n/a |
| Superadmin_schools.php | onboard (:361) / legacy onboard (:734) | role, roleLabel, schoolId, schoolCode, parentDbKey | ✅ camelCase | School Super Admin | yes | — (done) | n/a |
| **Ssa_reset.php** | SSA password reset (:67-73) | role, **school_id, school_code, parent_db_key**, must_change_password, password_reset_at | ❌ snake | School Super Admin | **yes** | ~8 LoC | revert 1 block |
| **Admin.php** | legacy admin create (:1169-1174) | role, **school_id, school_code, parent_db_key** | ❌ snake | School Admin | **yes** | ~6 LoC | revert 1 block |
| Staff.php | :777, :1414, :1793, :2133, :2328 | role, **school_id, school_code, parent_db_key** (+must_change_password on reset) | ❌ snake | Teacher / staff | **yes (web) + mobile** | ~30 LoC (5 sites) | revert 5 blocks |
| Sis.php | :2022 (parent), :2689 (student) | role, **school_id, school_code, parent_db_key** | ❌ snake | Parent / Student | **mobile only** | ~12 LoC | revert 2 blocks |
| Superadmin_admins.php | :126 | role=super_admin (no school ctx) | n/a | Platform Super Admin | separate login | excluded | n/a |

### 1.2 Claim READERS

| Reader | File:method | Keys read | Contract | Notes |
|---|---|---|---|---|
| Admin-web login | `Admin_login::_try_firebase_admin_login` :235-240 | `schoolId ?? school_id`, `schoolCode ?? school_code`, `roleLabel ?: role` | camelCase **+ Commit-C snake bridge** | the bridge to retire |
| Mobile API | `Api_auth` :51/129/177 | `school_id`, `school_code`, `parent_db_key`, `role` | **snake only** | Parent + Teacher apps |
| Token verify | `Firebase::verifyFirebaseToken` (docblock) | returns `school_id`… | snake | feeds Api_auth |
| Platform SA | `Superadmin_login` | `role` (super_admin) | separate | out of scope |
| MY_Controller | uses `$this->parent_db_key` from **session** (school-doc hydration, not claims) | — | n/a | claim `parentDbKey` is **not read at runtime** |

### 1.3 Affected user types (admin-web consumers needing camelCase to retire bridge)
- School Admin (AdminUsers ✅; Admin.php ❌), School Super Admin (Superadmin_schools ✅; Ssa_reset ❌), **Teacher/staff (Staff.php ❌, also mobile)**. Parent/Student are mobile-only (snake correct).

---

## 2. TARGET STATE
- **Single canonical claims contract = camelCase** (`role`, `roleLabel`, `schoolId`, `schoolCode`, `parentDbKey`).
- Every writer emits camelCase only. Both readers (admin-web + mobile) read camelCase only.
- Commit-C bridge removed → no dual-read. No dual-write. Firestore/Auth-only; no RTDB; fail-closed preserved.
- `parentDbKey` retained in claims for forward-compat though currently unread at runtime (consistent with SA precedent).

---

## 3. MIGRATION SEQUENCE

**The bridge protects admin-web. To retire it, ALL admin-web-reachable accounts must have camelCase claims.** Two tiers:

### Tier 1 — Admin-only writers (necessary, small)
1. **M-1 `Ssa_reset.php`** → camelCase + add `roleLabel` (keep must_change_password trio). ~8 LoC.
2. **M-2 `Admin.php` legacy create** → camelCase + `roleLabel`. ~6 LoC.
- After Tier 1: SSA-reset and legacy-admin-create produce login-valid claims. **But Staff-provisioned teachers still emit snake and use web → bridge still required.**

### Tier 2 — Staff/teacher dual-contract resolution (the real gate)
Pick ONE:
- **Option A — Confirm teachers do NOT use admin-web.** If product decision: teachers are mobile-only and any web access is removed/blocked → then Staff.php may stay snake (mobile), and after Tier 1 + backfill the bridge can retire. *(Requires a routing/role guard that denies Staff-role web login, OR confirmation none exists.)*
- **Option B — Converge the mobile contract to camelCase (recommended for true single-source).**
  3. **M-3 `Staff.php` (5 sites)** → camelCase + `roleLabel`.
  4. **M-4 `Sis.php` (2 sites)** → camelCase.
  5. **M-5 `Api_auth` + `verifyFirebaseToken`** → read camelCase (`schoolId`…), drop snake.
  - PREREQUISITE: confirm the **Android apps do not read custom-claim keys client-side** (if they call only server APIs, this is server-only and app-safe; if they parse `school_id` from the ID token on-device, the apps must ship first). This is an **external dependency to verify before M-3..M-5.**

### Tier 3 — Backfill (see §3.1) → then **retire Commit C**.

### 3.1 BACKFILL — REQUIRED
Existing accounts already carrying snake claims that reach admin-web must be re-set to camelCase before bridge removal:
- Scope: every `admins/*` doc (school admins) + all SSAs + (Option B) all `staff/*` (teachers) across **all tenants**.
- Method: idempotent CLI job — for each account, `getFirebaseUser(uid).customClaims`; if snake/has-no-`schoolId`, `setFirebaseClaims` with camelCase derived from existing claim values (or Firestore profile). Re-runnable; logs per-account result.
- Tenant `SCH_D94FE8F7AD` census: 0 snake admins (only SSA0001, already camelCase) — but **other tenants + all Staff-provisioned teachers are unmigrated** → backfill is non-trivial under Option B.

### 3.2 RUNTIME MIGRATION — NOT required
No request-time data migration. Claims are overwritten on next write (or by the backfill job). No Firestore schema change. No document migration.

---

## 4. VERIFICATION SEQUENCE
1. **Static:** grep — no admin-web writer emits `school_id/school_code/parent_db_key` (Tier 1: Ssa_reset, Admin; Tier 2: Staff, Sis). `php -l` each touched file.
2. **Claims census (read-only probe):** enumerate `admins` + `staff` + SSAs per tenant; assert **0 snake** among admin-web-reachable accounts after backfill.
3. **Live login proof (ephemeral, per writer):** create→login→delete for: SSA (post-Ssa_reset), legacy Admin (post-Admin.php), Teacher (post-Staff.php) → all authenticate via Admin_login with the **bridge already disabled in a test build** → success.
4. **Mobile regression (Option B):** verify Api_auth resolves `schoolId` from a camelCase token for a Parent + Teacher token; confirm Android app smoke (login + a data call) — gated on the app-claims-read dependency.
5. **Fail-closed:** account with neither casing → still rejected (`ADMIN_LOGIN_AUTHZ_MISSING`).
6. **Soak:** 24–72h post-migration before bridge removal; scan `security_events` for `ADMIN_LOGIN_AUTHZ_MISSING` spikes.

---

## 5. RETIREMENT CRITERIA FOR COMMIT C (all must hold)
- [ ] R1. All **admin-web** writers emit camelCase: AdminUsers ✅, Superadmin_schools ✅, **Ssa_reset (M-1)**, **Admin (M-2)**, and **Staff (M-3)** *(unless Option A removes teacher web access)*.
- [ ] R2. Backfill complete: claims census shows **0 snake** among all admin-web-reachable accounts across **all tenants**.
- [ ] R3. (Option B) Mobile reader migrated (M-5) + Android-claims-read dependency confirmed safe + mobile regression green; OR (Option A) teacher web-login is provably blocked.
- [ ] R4. Soak clean (no `ADMIN_LOGIN_AUTHZ_MISSING` from legitimate accounts).
- [ ] R5. Then revert Commit C (`git revert 3311fac` or remove the `?? $claims['school_id']` fallbacks) → Admin_login reads camelCase only. **No dual-read remains.**

**Until R1–R4 hold, Commit C MUST stay** — it is the strictly-required compatibility shim for the live dual-contract.

---

## 6. BLAST RADIUS ANALYSIS
- **Tier 1 (Ssa_reset, Admin):** low — admin-web-only accounts; no mobile impact; small, isolated blocks.
- **Tier 2 Option B (Staff, Sis, Api_auth):** **HIGH** — touches the mobile auth path for Parent + Teacher apps (the largest user population). Risk: if the Android apps read claim keys client-side, flipping to camelCase breaks app login until app ships. Must verify first.
- **Backfill:** medium-high — mutates Firebase Auth custom claims for potentially thousands of accounts (all staff). Idempotent + reversible, but wide.
- **Bridge removal itself:** low mechanically (revert ~6 LoC) but catastrophic if R1–R4 not met (locks out any remaining snake account at admin-web → fail-closed denial).
- **Unaffected:** RBAC authorization logic, role definitions, Firestore role authority, MY_Controller (parentDbKey from session), platform SA login.

---

## 7. ROLLBACK STRATEGY
- **Per writer (M-1..M-5):** pure code; `git revert` the block → reverts to snake emission. Claims already written camelCase remain valid (admin-web reads camel; mobile would need the snake reader still present → so revert M-5 last / first-in on rollback).
- **Backfill:** idempotent; an inverse job can re-set snake if ever needed (no reason to — camelCase is canonical). No Firestore data to undo.
- **Bridge removal (R5):** if post-removal lockouts appear, `git revert` the removal to restore the bridge instantly (no data impact); investigate the offending writer/account, re-backfill, retry.
- **Ordering safety:** never remove the bridge (R5) before the mobile reader is camelCase-capable (Option B) — otherwise a teacher who can't reach mobile-snake AND can't reach web-camel is fully locked out.

---

## RECOMMENDATION
1. **Now-ish (small, safe):** execute **Tier 1 (M-1 Ssa_reset, M-2 Admin)** + targeted backfill of `admins`/SSAs → removes the admin-only snake writers. *Bridge stays* (teachers still snake).
2. **Decision gate:** product confirms whether **teachers use admin-web**.
   - If **no** → block Staff-role web login (small guard) + backfill admins/SSAs → **retire Commit C**.
   - If **yes** → schedule **Tier 2 Option B** (mobile convergence) as its own initiative with the Android-claims-read verification, full backfill, soak → **then retire Commit C**.
3. Treat **Commit C as a medium-lived shim**, not a quick-retire — its lifetime is bounded by the dual-contract resolution, not by Tier 1 alone.

*Analysis only. No files changed; Commit C intact; nothing pushed or merged.*
