# H2 — Privilege-Escalation / Segregation-of-Duties Remediation Package

**Status:** ANALYSIS ONLY — no implementation / commit / push / merge
**Date:** 2026-06-06 · **HEAD:** `3311fac` · branch `ank-yug_b1`
**Rules:** Firestore-canonical · no RTDB · no Mongo · fail-closed · least-privilege · commercial-ERP behavior.

---

## 0. CORRECTED THREAT BASELINE (important — refines the earlier audit)
Every mutating method in `AdminUsers` is **double-gated**:
1. Constructor `require_permission('Admin Users')` (:163) — module gate.
2. Per-method `_require_role([...])` (MY_Controller :735) — matches the caller's role **name** against an allow-list, and always passes `Super Admin` / `School Super Admin`.

`_require_role` keys on the role **name**, so a *custom* role can never reach a mutating method even if granted the `Admin Users` module. **Result: there is currently NO direct non-bypass→bypass escalation exploit — the name-gates fail closed.** The earlier audit's "HIGH escalation" is therefore **downgraded**: the real exposure is (a) a *fragile/inconsistent* gating model that is one refactor away from opening, and (b) **governance / segregation-of-duties gaps** expected to be closed for commercial-ERP maturity.

Bypass model: `RBAC_BYPASS_ROLES = ['Super Admin','School Super Admin','Admin']` (rbac_helper.php:32). Any account whose role name is `Admin` is omnipotent (bypasses all `has_permission`). SSA/Super Admin are **platform** roles, not in `schools/{id}.roles`, so they cannot be minted via `create_admin`.

---

## FINDINGS (1–9)

### 1. Current privilege-escalation paths
**F1 — Latent escalation via the gating inconsistency (MEDIUM)**
- File/method: `AdminUsers::__construct` (:163) + every method's `_require_role`; `rbac_helper::has_permission`.
- Exploit path: none *today* (name-gates block custom roles). **But** the system's safety depends on `_require_role` name-matching, NOT on the `Admin Users` module permission. If a future change "simplifies" auth to rely on `require_permission('Admin Users')` alone (the natural RBAC expectation), then **any custom role granted `Admin Users` → full admin-management → can assign the bypass `Admin` role → takeover.** `save_role` already lets a bypass admin grant `Admin Users` to any role.
- Business: a mis-"fix" silently opens tenant takeover. Security: latent EoP. Blast radius: whole tenant. Remediation: make the intent explicit + safe (see Target model §A) — gate by *capability + tier*, not role name; never allow assigning/【granting a role ≥ the actor's tier. Diff: ~40–60 LoC. Rollback: low (additive guards).

### 2. Role-creation risks
**F2 — `save_role` can grant sensitive modules to any role; no sensitive-grant guard; no audit diff (MEDIUM)**
- File/method: `AdminUsers::save_role` (:1035), permission whitelist `array_intersect(.., AVAILABLE_MODULES)` (:1058 area).
- Exploit path: a bypass `Admin` creates/edits a role granting `Configuration`/`Fees`/`Accounting`/`Admin Users` to an operational role (e.g. Front Office). `Admin Users` is *inert* (name-gates), but `Configuration`/`Fees`/etc. are **live** → broadens that role's reach (e.g. Front Office gains School Config). Only an `Admin` can do this (within its power), but there is **no guardrail on sensitive-module grants and no before/after audit diff** (log records a count only, :1087).
- Business: silent capability creep; weak forensics. Security: privilege broadening without trace. Blast radius: all holders of the edited role. Remediation: flag/segregate sensitive modules (`Admin Users`, `Configuration`, `Accounting`) — require an elevated approver to grant them; log permission deltas. Diff: ~40 LoC. Rollback: low.

**F3 — System-role permissions are editable in place (MEDIUM)**
- File/method: `save_role` (:1063-1075) preserves `is_system` but updates `permissions`.
- Exploit path: an `Admin` edits the `Teacher`/`Principal` system role to add modules → escalates *every* holder of that role at once. Within Admin's authority, but unbounded and unaudited (diff).
- Remediation: either lock system-role permission edits (clone-to-customise pattern) or require elevated approval + delta audit. Diff: ~20 LoC. Rollback: low.

### 3. Role-modification risks
**F4 — No role versioning / change-approval (MEDIUM, governance)**
- File/method: `save_role` / `delete_role`. No history, no maker-checker, no revert. A single Admin can rewrite the permission model unilaterally.
- Remediation: role version history (append to `rbacRoleHistory`), optional dual-approval for sensitive changes. Diff: ~50–80 LoC. Rollback: low (additive).

### 4. Admin-creation risks
**F5 — No tier guard on `create_admin` role assignment → unlimited peer-Admin minting (MEDIUM)**
- File/method: `create_admin` (:426); role validated to exist in `schools/{id}.roles` (:455) but **no tier check vs the creator**.
- Exploit path: a bypass `Admin` mints unlimited additional `Admin` (full-access) accounts. Cannot mint SSA/Super Admin (not in the school map — good). Lateral proliferation, not vertical escalation, but no cap, no approval, no SoD.
- Business: sprawl of omnipotent accounts; harder to govern/audit. Security: larger attack surface; no dual-control. Blast radius: tenant. Remediation: "cannot create a role of tier ≤ your own" (Admin tier-1 may NOT create tier-1); restrict bypass-role assignment to SSA; optional maker-checker. Diff: ~25 LoC. Rollback: low.

### 5. Admin-promotion risks
**F6 — `update_admin` can change any admin's role with no tier guard; self-role-change not blocked (MEDIUM/LOW)**
- File/method: `update_admin` (:584); self-protection exists only for disable/delete, not role change.
- Exploit path: an `Admin` promotes any non-self admin to `Admin` (peer). Self can edit own profile/role (no-op upward since already top). No "cannot elevate to/above your tier" guard.
- Remediation: same tier-guard as F5; explicitly block changing one's own role. Diff: ~15 LoC. Rollback: trivial.

### 6. Self-escalation risks
**F7 — Self-protection is partial (LOW)**
- File/method: `disable_admin` (:700 "cannot change own status"), `delete_admin` (:752 "cannot delete own account"), `reset_password` self-excluded, `reset_ssa_password` self-excluded. **Missing:** explicit block on self-role-change in `update_admin`.
- Exploit path: minimal (already-top actor). Remediation: add self-role-change guard. Diff: ~5 LoC. Rollback: trivial.

### 7. Cross-role escalation risks
**F8 — No cross-tenant escalation (PASS / LOW)** — tenant checks present (`reset_ssa_password` verifies target under caller's `school_code` :956-966; AdminUsers scoped via `schoolWhere`/`docId`). ✅ Note: `reset_ssa_password` tenant check uses an **RTDB read** `firebase->get("Users/Admin/{school_code}/{id}")` (:957-958) and `Ssa_reset::listSsasInSchool` likely RTDB — an **RTDB dependency in SSA management** (architecture-rule deviation, adjacent to H2). Remediation (separate): move SSA tenant check to Firestore `schoolSsa`. Diff: ~15 LoC.

### 8. Bypass-role protections
**F9 — Bypass is name-based, broad, and unsegmentable (MEDIUM)**
- File/method: `rbac_helper::has_permission` (:48) + `RBAC_BYPASS_ROLES` (:32). Any role literally named `Admin` → full access; SSA protected from Admin (verified). But you **cannot** create a constrained "User Administrator" (manages admins only) — bypass is all-or-nothing, and non-bypass custom roles are name-gated out of AdminUsers entirely.
- Business/ERP: no least-privilege delegation of admin-management; violates commercial-ERP SoD. Remediation: replace name-based bypass with capability+tier model (§A); allow a scoped `Admin Users` capability to manage admins *below* its tier. Diff: medium (part of §A). Rollback: medium.

### 9. Segregation-of-duties gaps
**F10 — No SoD / maker-checker anywhere (MEDIUM, governance)**
- A single bypass `Admin` can create admins, reset passwords, edit roles, and delete admins with no second approval, no role/permission separation (e.g., "who can manage users" ≠ "who can manage roles"), no four-eyes on sensitive actions.
- Remediation: governance model §C (maker-checker for sensitive ops; split "admin management" vs "role management" capabilities; elevated-approver for bypass/sensitive grants). Diff: medium-large. Rollback: medium.

---

## SEVERITY SUMMARY
| ID | Title | Severity | Diff | Rollback |
|---|---|---|---|---|
| F1 | Latent escalation via gating inconsistency | Medium | ~40-60 | low |
| F2 | Sensitive-module grants unguarded + no audit diff | Medium | ~40 | low |
| F3 | System-role permissions editable in place | Medium | ~20 | low |
| F4 | No role versioning / change-approval | Medium (gov) | ~50-80 | low |
| F5 | No tier guard on create_admin (peer-Admin minting) | Medium | ~25 | low |
| F6 | No tier guard on update_admin role change | Medium/Low | ~15 | trivial |
| F7 | Self-role-change not blocked | Low | ~5 | trivial |
| F8 | No cross-tenant escalation (RTDB-in-SSA aside) | Low | ~15 | low |
| F9 | Bypass is name-based/broad/unsegmentable | Medium | (in §A) | medium |
| F10 | No SoD / maker-checker | Medium (gov) | med-large | medium |

**No Critical / no active High exploit** (name-gates fail closed). Cluster is "harden the fragile model + add commercial-ERP governance."

---

## A. RECOMMENDED ROLE-HIERARCHY MODEL (capability + tier)
- Keep the existing **tier** field (1=Full … 6=Minimal, 7=Custom) as a first-class authority axis.
- Replace **name-based bypass** with **capability + tier**:
  - `has_permission(module)` stays for module visibility.
  - Add an authority predicate: an actor may manage a target/role **only if `actorTier < targetTier`** (strictly more privileged). Tier-1 cannot act on tier-1.
  - Define a discrete capability `MANAGE_ADMINS` and `MANAGE_ROLES` (distinct from the catch-all `Admin Users` module) so a constrained "User Administrator" can exist without full bypass.
  - "Sensitive modules" set = {`Admin Users`, `Configuration`, `Accounting`} — granting these to a role requires the actor to itself hold them AND be a higher tier (no lateral/ upward grant).
- Fail-closed: unknown tier → treat as lowest authority (cannot manage anything).

## B. RECOMMENDED ADMIN-MANAGEMENT MODEL
- **Tier-guarded CRUD:** create/update/disable/delete/reset only on admins of **strictly lower tier**; never on equal/higher; self-role-change blocked.
- **Bypass minting restricted:** only SSA (or a dedicated tier-0) may create/assign the `Admin` (tier-1 bypass) role; tier-1 Admins may create tier-≥2 only.
- **Lifecycle:** email-invite onboarding (stop echoing typed passwords), account expiry, force-logout/session-revoke (reuse `revokeRefreshTokens`), online/last-active from `security_events` (ties to H1).
- **Audit:** every action already `log_audit`'d; add permission/role **deltas** + actor tier.

## C. RECOMMENDED ENTERPRISE-ERP GOVERNANCE MODEL
- **Segregation of duties:** split `MANAGE_ADMINS` vs `MANAGE_ROLES` vs `RESET_CREDENTIALS`; no single role holds all unless tier-0.
- **Maker-checker (four-eyes):** sensitive ops (create bypass admin, grant sensitive module, delete admin, edit system role) enter a `pendingAdminApprovals` Firestore collection requiring a second qualifying approver before commit; fail-closed if unapproved.
- **Least privilege by default:** new custom roles start with zero modules; sensitive modules require elevated grant.
- **Tamper-evident audit:** permission-change diffs + approvals recorded; surfaced in an in-module audit viewer (ties to M5/H1).
- **Defense-in-depth retained:** keep a hard tier check even alongside capability checks (so a capability misconfig can't escalate).
- All state in Firestore (`rbacRoles` retired; `schools/{id}.roles` canonical; new `rbacRoleHistory`, `pendingAdminApprovals`) — no RTDB, no Mongo.

---

## RECOMMENDED REMEDIATION SEQUENCE (when authorized)
1. **P1 (small, high-value):** F5+F6+F7 tier-guards + self-role block (~45 LoC) — caps peer-Admin minting and enforces strictly-lower-tier management. Pure additive guards, low rollback.
2. **P1:** F1 — make the gating intent explicit (capability+tier helper) so the model isn't one refactor from opening; keep `_require_role` as defense-in-depth.
3. **P2:** F2+F3 — sensitive-module grant guard + system-role edit policy + permission-delta audit.
4. **P2:** F8 SSA tenant check → Firestore (removes the SSA-management RTDB read).
5. **P3 (initiative):** F9+F4+F10 — capability model (§A), role versioning, maker-checker (§B/§C). Architectural; scope separately.

*Analysis only. No files changed; no commits; nothing pushed or merged.*
