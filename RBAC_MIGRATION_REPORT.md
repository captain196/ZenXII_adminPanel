# RBAC Migration Report — Phase A

**Date:** 2026-08-08
**Scope:** every RBAC check touched by Phase A (`Fee_concessions.php` — G3; `Transport.php` /
`Transport_service.php` — G1/G2). Triggered by an explicit instruction not to extend the legacy
role-name fallback (`MANAGE_ROLES` + `'Admin'`) and to migrate toward the canonical graded RBAC
(`has_permission()` / `schools.staffRoles`) wherever it is safe to do so.

## 1. How the two systems actually relate (read directly from code, not inferred)

`MY_Controller::_require_role(array $allowed, $action, $module, $level)` — [MY_Controller.php:1178](application/core/MY_Controller.php#L1178) — is not "legacy OR graded." It's graded-first with a documented, self-limiting fallback:

1. `Super Admin` / `School Super Admin` always pass — hardcoded, unconditional, independent of `$allowed`.
2. If `$module` is supplied and `has_permission($module, $level)` is true → pass. **Graded is authoritative whenever the caller holds the module at all** — even at a *lower* level than required, the code deliberately does **not** fall through to the legacy list (a deliberately-lowered grant must stay lowered).
3. Only a role that does **not** hold `$module` in the graded map at all still consults the per-controller `$allowed` array.

So a legacy array entry is only ever load-bearing for a role that (a) isn't Super/School-Super-Admin, and (b) the graded catalogue genuinely doesn't grant the module to. It is dead weight for every role the catalogue already covers — which is exactly what made G3's original fix (adding `'Admin'`) pointless rather than merely inelegant.

`has_permission()` itself already has its own internal two-tier fallback (`resolve_role_access()`, [rbac_helper.php:341](application/helpers/rbac_helper.php#L341)): primary lookup against the school's unified `staffRoles` catalogue (matched by label or `ROLE_*` id), then a **secondary, centrally-instrumented** fallback to the legacy `schools.roles[name]` map for tenants not yet "folded" into the unified catalogue — logged as `RBAC_LEGACY_FALLBACK_HIT` specifically so its fleet-wide retirement can be measured. That is the architecturally correct place for a "protect not-yet-migrated tenants" safety net. A hardcoded array duplicated per-controller is a second, uninstrumented copy of the same idea, and is exactly the drift risk that produced G3 in the first place (one controller's copy silently missing an entry another controller's copy had).

## 2. Fee_concessions.php (G3) — retired, not extended

**Evidence gathered before touching anything:**

- `ROLE_ADMIN` (label `'Admin'`) is in the graded catalogue with `permissions = ALL_MODULES` ([Staff.php:69](application/controllers/Staff.php#L69)), backfilled fleet-wide by `Admin_role_backfill.php`. `MY_Controller.php:700-717` additionally carries a **FOLD SAFETY NET**: if a tenant somehow still resolves `Admin` to zero modules (backfill not yet run there), the role self-heals to full access and logs loudly — `Admin` can never be silently locked out, with or without a per-controller array.
- `ROLE_ACCOUNTANT` is catalogue-seeded with `permissions = ['Fees','Accounting','Reports']` at the default `manage` level ([Staff.php:75](application/controllers/Staff.php#L75)) — `has_permission('Fees','manage')` is true for any staff member on this role without any per-controller help.
- `Super Admin` / `School Super Admin` bypass unconditionally inside `_require_role` itself — never touch `$allowed` regardless of its contents.
- `'School Admin'` — the one entry my original fix would have kept company with `'Admin'` — is **not a role in the graded catalogue's `DEFAULT_STAFF_ROLES` at all**, and a repo-wide grep found it referenced in exactly one other file (`Stories.php`), for an unrelated display-name default, not a permission check. `Fee_concessions.php` was the only controller in the whole app still gating on it.

**Change:** removed the `MANAGE_ROLES`/`VIEW_ROLES` constants; all 9 `_require_role()` call sites now pass `[]` for the legacy allow-list, relying purely on `has_permission('Fees', view|manage)` (plus the unconditional Super-Admin bypass already inside `_require_role`).

**Residual risk, stated plainly:** a tenant that (a) has a custom staff role literally labeled `"School Admin"`, **and** (b) has not yet been "folded" into the unified `staffRoles` catalogue, **and** (c) whose legacy `schools.roles['School Admin']` map doesn't itself grant `Fees` — would lose access it previously had only via this controller's own duplicate list. I do not have live Firestore access in this session to enumerate every tenant's fold status, so I can't claim zero-tenant-affected with certainty. But note two things: first, if that tenant's legacy `schools.roles` map *does* grant Fees, `has_permission()` already resolves it correctly via its own internal fallback — no regression. Second, this exposure existed identically for every one of the other 51 controllers in the fleet that never had a `'School Admin'` entry to begin with; `Fee_concessions.php` was the outlier, not the norm.

## 3. Transport.php / Transport_service.php (G1/G2 surface) — audited, **not** touched

`Transport_service.php` (the library layer, where G1/G2 live) performs **zero** RBAC checks of its own — confirmed by grep, zero hits for `_require_role`/`has_permission` in that file. All authorization is at the controller layer, so G1/G2's implementation has no RBAC surface at all.

`Transport.php` does carry hardcoded arrays (`MANAGE_ROLES`, `VIEW_ROLES`, `OPS_ADMIN_ROLES`, `TRN_MANAGE_ROLES`, `TRN_VIEW_ROLES` — [Transport.php:20-27](application/controllers/Transport.php#L20)), pre-existing and **not modified this session** (they belong to the already-in-flight, unrelated F12.1/graded-RBAC-args rollout on this branch). I audited them because they gate the same suspend/resume endpoints G1 touches, and found they are **currently load-bearing, not dead weight**:

`DEFAULT_STAFF_ROLES`'s `ROLE_PRINCIPAL` and `ROLE_VICE_PRINCIPAL` entries do **not** include `'Transport'` in their `permissions` list at all ([Staff.php:69-70](application/controllers/Staff.php#L69)). Per the `_require_role` precedence rules above, a Principal/VP genuinely doesn't hold the `Transport` module in the graded map — so they fall through to, and currently depend on, the legacy array (both list `Principal`/`Vice Principal`). `'Operations Manager'` is not a catalogue role at all (no `ROLE_OPERATIONS_MANAGER` entry exists anywhere) — any school relying on that literal role name has no graded path to fall back to.

**Recommendation for Transport.php: do not touch.** Removing its legacy arrays today would lock out Principal/VP/Operations-Manager-labeled staff from Transport actions with no equivalent graded grant to catch them. Closing this gap is a **catalogue** decision (should Principal/VP be granted `Transport` at the catalogue level, and is `'Operations Manager'` a role that should be formally added, retired, or aliased to `'Transport Manager'`?) — outside Phase A's scope and outside what G1/G2 touch.

## 4. Fleet-wide scale (found while auditing, not fixed — out of scope)

Repo-wide: **52 controllers** carry hardcoded `*_ROLES` array constants. Of those, **37 already dual-gate** (pass graded `(module, level)` args alongside the legacy array, same migration-in-progress shape as `Fee_concessions`/`Transport`); **15 have zero graded args anywhere** — pure legacy, a materially bigger and riskier population than anything Phase A touches. This confirms an active, partially-completed RBAC migration already exists in this codebase (`Admin_role_backfill.php`, `Staff_roles_backfill.php`, `Rbac_levels_backfill.php`, the `RBAC_LEGACY_FALLBACK_HIT` instrumentation, the `RBAC_RETIREMENT_INVESTIGATION` runbook referenced in `MY_Controller.php:709`). Project memory also already tracks a broader, explicitly-deferred program in this space (`enterprise_rbac_program.md`, `rbac_remediation_package.md`). I'm flagging this scale finding, not acting on it — a fleet-wide retirement is a dedicated program, not a Phase A side effect, and doing it here would be exactly the "unsafe mass replacement" the brief warned against.

## 5. Cross-surface validation

`Fee_concessions.php` and `Transport.php` are Admin-Panel-only controllers (session-cookie auth, PHP `_require_role`/`has_permission`). Neither has a Parent-app or Teacher-app counterpart — those apps authenticate via Firebase custom claims and are governed entirely by `firestore.rules`, a separate authorization boundary this change doesn't touch. Confirmed by architecture, not applicable to validate there.

## 6. Re-verification after the RBAC change

- **Firestore impact:** zero. `has_permission()` reads only already-session-cached `rbac_permissions`/`rbac_levels` (populated once per request by `MY_Controller`'s bootstrap, independent of which controller runs). Removing a legacy array is a pure in-memory/CPU change — no new reads, writes, or queries, in either direction.
- **Performance impact:** negligible improvement (one fewer array iteration per call); no regression possible.
- **Regression analysis:** re-confirmed via the precedence trace above — every role Fee_concessions previously admitted through its legacy list (`School Super Admin`, `School Admin`, `Admin`, `Accountant`) still gets in, all through the graded/bypass paths already proven to grant them, except the theoretical not-yet-folded-`'School Admin'`-tenant edge case documented in §2, which `php -l`-level static analysis cannot resolve and which I'm surfacing rather than papering over.
- **AC / G3 validation:** G3's original defect ("an operator with a `Fees`-manage grant under a not-yet-fully-migrated role name gets a 403") is closed the *correct* way — via the graded capability, not via a longer hardcoded name list — which is what actually stops this class of bug from recurring on the next controller.
- **Cross-module compatibility:** no change to `Fee_lifecycle`, `Transport_service`, Student Entitlements, Student 360, Action Registry, `Entitlement_service`, `Fee_service`, or any Firestore schema — this is purely an authorization-layer edit in one controller.

## 7. Recommendation

**Do not attempt full, fleet-wide legacy RBAC retirement now.** Retire it incrementally, module by module, the same way `Fee_concessions.php` was just done: for each of the 52 controllers, prove (not assume) that every role in its legacy array already resolves via the graded catalogue before deleting the array; where a role genuinely isn't graded-covered (as with `Transport`'s Principal/VP/Operations-Manager gap), extend the catalogue first, then retire the array. Fee_concessions is closed. Transport's legacy arrays stay, documented and justified above, pending a catalogue decision that's outside Phase A.
