# Admin Users / RBAC — ERP-Grade Forensic Audit

**Status:** ANALYSIS ONLY — no implementation, no commits, no change to Commit C
**Date:** 2026-06-06 · **HEAD:** `be65ad3` (A+B committed; C uncommitted) · branch `ank-yug_b1`
**Scope:** AdminUsers.php (16 methods), Admin_login.php, rbac_helper.php, MY_Controller, views/admin_users/index.php (740 LoC), Ssa_reset.php, Admin.php.

---

## A. CURRENT MATURITY ASSESSMENT

| Dimension | Level (1–5) | Notes |
|---|---|---|
| RBAC core (authority/enforcement) | **4 / 5** | Firestore-canonical, fail-closed, role→module enforcement live-verified (49/49). Module-level only. |
| Role lifecycle | 3 / 5 | Create/edit/delete custom roles, tier hierarchy, system-role protection. No cloning/versioning/effective-preview. |
| Admin lifecycle | 3 / 5 | Create/update/disable/delete + self-protection + reset + self-change-password. No invite flow, bulk, scheduling, session-revoke UI. |
| Security architecture | 3 / 5 | Firebase Auth + claims, lockout, IP rate-limit, CSRF, fail-closed. No MFA, no SoD, no tier-guarded assignment. |
| Governance | 2 / 5 | Self-protection + system-role lock only. No maker-checker, approvals, SoD, expiry, rotation. |
| Audit & traceability | 2 / 5 | `log_audit` on mutations, but **Last Login broken** (stale source), no in-module audit viewer/search/export, no permission-change diffs. |
| UX | 3 / 5 | Tabbed, modals, search/filter. No pagination, bulk, export, copy-password, strength meter. |
| ERP feature parity | 3 / 5 | Solid module-visibility RBAC. Missing CRUD/field/data-scope perms, delegation, approvals, MFA. |
| **Firestore canonical compliance** | **5 / 5** | After A+B+C: write→read→session cache→enforcement all Firestore; no RTDB role authority; no Mongo. ✅ |

**Overall: 3.1 / 5 — "Functional, not yet enterprise-governed."** The authorization engine is correct and canonical; the gaps are in governance, audit, granularity, and ERP-depth.

---

## B–E. FINDINGS REGISTER
Each: severity · files · methods · business / security / UX / ERP impact · remediation · diff · rollback.

### 🟠 HIGH

**H1 — "Last Login" / Login Activity reads retired `AccessHistory.LastLogin` (stale/empty)**
- Files/methods: `AdminUsers.php` `get_dashboard` (:353/:372), `get_admins`/`_normalize_admin` (:228), `get_login_logs` (:1146/:1158).
- Business: admins see blank/stale "last login"; "Login Activity" tab is effectively dead. Security: no visibility into account usage / dormant accounts / anomalous logins. UX: a whole tab + column show nothing. ERP: login-audit is table-stakes; currently absent.
- Root: Wave C retired the `AccessHistory` writer; canonical source is now `security_events` (`event_type=ADMIN_LOGIN_SUCCESS`, `detail.schoolId`).
- Remediation: repoint the 3 readers to query `security_events`; add composite index `(event_type ASC, detail.schoolId ASC, ts DESC)`.
- Diff: ~40–60 LoC, 1 file + 1 index. Rollback: low (read-path only).

**H2 — No role-tier guard on assignment → privilege-escalation path**
- Files/methods: `create_admin` (:426), `update_admin` (:584); also `save_role` (:1035) can grant `Admin Users` to any custom role.
- Business: a school could grant a custom role the `Admin Users` permission; that holder can then create/assign the bypass `Admin` role → full takeover. Security: **privilege escalation / no segregation-of-duties** — assigner can mint a role more powerful than itself. UX: none. ERP: enterprise RBAC forbids assigning above your own tier.
- Remediation: enforce "cannot assign a role whose tier ≤ (more privileged than) the actor's tier"; restrict who may grant `Admin Users`/bypass roles to bypass roles only; block non-bypass roles from creating bypass admins.
- Diff: ~25–40 LoC across create_admin/update_admin/save_role. Rollback: low (additive guards).

**H3 — Claims convergence incomplete: SSA reset + legacy admin create still emit snake_case**
- Files/methods: `Ssa_reset.php` (:67-73), `Admin.php` (:1169-1174).
- Business: after an SSA password reset, the SSA's claims revert to snake_case → SSA cannot log into admin-web (until Commit C's bridge tolerates it). Security: inconsistent claim schema; bridge masks it. ERP: single-source-of-truth not yet reached.
- Remediation: migrate both to camelCase + `roleLabel` (RBAC_CLAIMS_REMEDIATION_PACKAGE C3); then retire the Commit-C bridge.
- Diff: ~12 LoC, 2 files. Rollback: low.
- Note: **Commit C (transitional bridge) currently covers this** — do not retire the bridge until H3 lands + backfill verified.

### 🟡 MEDIUM

**M1 — Constructor gate vs `_require_role('Principal')` contradiction (dead/misleading authz)**
- `AdminUsers::__construct` `require_permission('Admin Users')` (:163) blocks any non-bypass role; yet `index/get_dashboard/get_admins/create_admin/get_roles/get_login_logs` list `'Principal'` in `_require_role` (:337/:355/:405/:428/:998/:1148). Principal lacks `Admin Users` → never reaches them.
- Impact: misleading authz intent; risk if constructor gate is ever loosened (Principal would gain create_admin → escalation). UX/ERP: none. Remediation: remove dead `'Principal'` entries OR intentionally grant Principal a scoped read. Diff: ~6 LoC. Rollback: trivial.

**M2 — Coarse module taxonomy + non-canonical controller gates**
- `Operations` lumps Library/Transport/Inventory/Assets/Hostel; `Homework`/`Device Management`/`Message Monitor`/`Red Flags` are gated by strings absent from `RBAC_MODULES` (bypass-only). Files: `rbac_helper.php` RBAC_MODULES; controllers Library/Transport/Inventory/Assets/Homework/etc.
- Business: can't grant a Librarian library-only without also granting transport; teachers can't be granted Homework via any role. ERP: granularity below commercial norms. Security: over-broad grants. Remediation: split `Operations` into Library/Transport/Hostel/Inventory; add Homework/RedFlags/etc. to RBAC_MODULES + role presets + sidebar. Diff: ~60–120 LoC across helper + AdminUsers consts + header.php. Rollback: medium (touches sidebar + role seeds).

**M3 — Module-level only; no action-level (CRUD) or data-scope permissions**
- Files: rbac_helper (`has_permission`), all controllers. Business: "can see Fees" = full Fees control; no view-vs-edit-vs-approve, no class/section scoping. ERP: major depth gap vs commercial ERPs. Remediation: extend permission model to `module:action` and optional data-scope; large. Diff: large (architectural). Rollback: high. (Roadmap item, not a quick fix.)

**M4 — No pagination / virtualization on admins + login-activity tables**
- File: `views/admin_users/index.php` (#adminsTable, logs table). Business/UX: large tenants render hundreds of rows unpaginated; slow. ERP: list views should paginate/search server-side. Remediation: server-side paginated `get_admins`/`get_login_logs` + UI. Diff: ~60 LoC. Rollback: medium.

**M5 — No in-module audit viewer; permission-change logging is shallow**
- `save_role` logs only a permission **count** (:1087), not the before/after diff. No audit search/filter/export; `AuditLogs` controller exists but isn't surfaced here. Business/ERP/Security: weak change forensics. Remediation: log permission deltas; embed an audit viewer (filter by actor/action/date) reading `auditLogs`/`security_events`. Diff: ~50–90 LoC. Rollback: low-medium.

**M6 — Full-collection scans (perf) + stale "MongoDB" terminology**
- `create_admin` email-uniqueness scan (:470 loop over all admins), `delete_role` role-usage scan (:1119 loop over all admins), `_process_pending_syncs` runs on every page load (:166) with comment "pending MongoDB syncs" (it's Firebase Auth). Business: O(n) at scale; misleading comment. Remediation: indexed `where('Email','==')` / `where('Role','==')`; gate pending-sync to mutating actions/cron; fix comment. Diff: ~25 LoC. Rollback: low.

**M7 — Password handling UX/security**
- View shows the chosen password in a credentials modal ("cannot be retrieved later", :329) with no copy-to-clipboard; min length 8 only, no strength/policy/breach check; password is admin-typed then echoed in the JSON response (:510). Business/Security: plaintext in response/DOM/history; weak policy. Remediation: optional auto-generate + copy button; enforce policy (length/complexity/breach); avoid echoing where possible. Diff: ~30 LoC. Rollback: low.

### 🟢 LOW

**L1 — Bare `/fees` 404** (no `index`; real entry `fees/dashboard`). Cosmetic routing; affects deep-link UX. Diff: ~3 LoC (route or stub). Rollback: trivial.
**L2 — Transitional bridge (Commit C) must be retired** post-H3 + backfill. Tracked. Diff: revert ~6 LoC. Rollback: trivial.
**L3 — No MFA/2FA for admin login** (OTP exists only in password-reset). ERP/security gap. Roadmap.
**L4 — Role UI lacks "members count / who has this role" + effective-permission preview + clone.** UX/ERP. Diff: ~40 LoC. Rollback: low.

---

## C. SECURITY ASSESSMENT (summary)
- ✅ Firebase Auth credential authority; per-account lockout (5/30m) + per-IP rate-limit (20/15m); CSRF on; session fixation prevented; fail-closed verified; no RTDB role authority; Firestore-only.
- ⚠️ **Privilege escalation (H2)** — no tier-guarded assignment / SoD.
- ⚠️ **Audit blind spot (H1/M5)** — login history dead; permission-change diffs not recorded.
- ⚠️ **No MFA (L3)**, no admin-panel IP allowlist, no concurrent-session/force-logout UI.
- ⚠️ Password echoed to client (M7).
Net: **credential & enforcement security is solid; governance & audit security is weak.**

## D. UX ASSESSMENT (summary)
- ✅ Clean 4-tab IA (Dashboard / Admin Users / Roles & Permissions / Login Activity), modals, search, tier-grouped role picker with colour coding.
- ⚠️ No pagination (M4), no bulk actions, no export/CSV, no copy-password (M7), destructive ops use raw `confirm()` (3×), Login Activity tab shows nothing (H1).
- ⚠️ No effective-permission preview / role member counts (L4).

## E. ERP FEATURE ASSESSMENT vs commercial school ERPs
| Capability | This module | Commercial norm |
|---|---|---|
| Role→module visibility | ✅ | ✅ |
| Role hierarchy/tiers | ✅ | ✅ |
| Custom roles | ✅ | ✅ |
| Action-level (CRUD) perms | ❌ (M3) | ✅ |
| Data-scope perms (class/section/branch) | ❌ | ✅ |
| Maker-checker / approval workflows | ❌ | ✅ (enterprise) |
| Segregation of duties / tier-guard | ❌ (H2) | ✅ |
| MFA for admins | ❌ (L3) | ✅ |
| Login/audit history + analytics | ❌ (H1/M5) | ✅ |
| Bulk admin import/invite | ❌ | ✅ |
| Session management / force-logout UI | ❌ | ✅ |
| Role cloning/versioning/effective-preview | ❌ (L4) | ✅ |

---

## MISSING CAPABILITIES (consolidated)
- **ERP capabilities:** action-level + field-level + data-scope permissions; delegation/temporary elevation; role cloning/versioning; permission templates; effective-permission simulator.
- **Governance controls:** maker-checker for admin creation & role edits; tier-guarded assignment / SoD (H2); admin account expiry; forced periodic password rotation; MFA (L3); admin-panel IP allowlist; concurrent-session limits.
- **Audit controls:** working login history (H1); permission-change before/after diffs (M5); in-module audit search/filter/export; tamper-evident trail; per-admin activity timeline.
- **Role-management:** clone, version history, "members with this role" count, effective-permission preview, bulk reassignment, granular (CRUD) editor.
- **Admin-management:** email-invite/onboarding flow (vs typed+echoed password), bulk import/export, scheduled deactivation, force-logout/session-revoke from UI, online/last-active status (currently broken), per-admin login history.

---

## F. PRIORITIZED ROADMAP
1. **Finish in-flight (now):** Commit C (bridge) → then **H3** (Ssa_reset/Admin camelCase) → retire bridge. Closes claims convergence. *(small)*
2. **P1 — H1 Login-history to `security_events`** — restores audit/traceability; high value, low risk. *(~50 LoC)*
3. **P1 — H2 Tier-guarded assignment + SoD** — closes the escalation path. *(~30 LoC)*
4. **P2 — M5 audit-viewer + permission-change diffs**, **M6 indexed scans + pending-sync gating**, **M1 dead-authz cleanup**. *(med)*
5. **P2 — M4 pagination**, **M7 password policy + copy/auto-gen + stop echo**, **L4 role UX (clone/members/preview)**. *(med)*
6. **P3 — M2 module-taxonomy split** (Operations→Library/Transport/Hostel/Inventory; add Homework/RedFlags/etc.). *(med, touches sidebar)*
7. **P3 — L3 MFA for admins**, admin-panel IP allowlist, force-logout UI, session limits. *(med-large)*
8. **P4 — M3 action-level + data-scope permission model** — the big ERP-depth upgrade; architectural, scope as its own initiative. *(large)*

---

## DETERMINATION
Authorization engine: **enterprise-correct and Firestore-canonical** (compliant with all stated architecture rules once C lands). The module is **production-ready for module-level RBAC** but **below enterprise governance/audit maturity**. Highest-value next steps are H1 (audit), H2 (escalation), and finishing claims convergence (C→H3). M3 (granular perms) is the strategic ERP-depth investment.

*Analysis only. No files changed; Commit C remains uncommitted; no push/merge.*
