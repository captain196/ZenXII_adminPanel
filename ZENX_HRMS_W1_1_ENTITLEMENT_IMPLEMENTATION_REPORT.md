# ZENX HRMS — W1.1 ENTITLEMENT REACHABILITY — IMPLEMENTATION REPORT

**Status: COMPLETE AND VERIFIED — STOPPED FOR APPROVAL.** Not committed, not pushed, not merged, not deployed. W1.2 and W1.3 not started.

**Result: 76 runtime assertions, 0 failures.** Cases A–G all proven.

---

## 1. Problem

Phase 1 shipped an *enforced* capability gate (`Tenant_capability_service` → `require_capability(HRMS_CORE_CAPABILITY)`, fail-closed) but `hrms_core` was **not a registered module key**. `Superadmin_plans::AVAILABLE_MODULES` is a hard whitelist on both the plan-edit UI **and** the create/update POST handlers, and both entitlement writers derived strictly from `plans.modules`. Net effect: **HRMS was hard-off for every tenant with no supported path to turn it on.**

A second problem sat behind it: the only available lever was *whole-plan assignment*, and all live tenants share one base plan — so a plan-level grant would have enabled HRMS for **every** tenant at once, which the product requirement explicitly forbids.

## 2. Existing architecture reused (nothing parallel built)

| Reused | How |
|---|---|
| `B2_derive::computeEntitlements()` | Already implemented `plan ∪ addOns − revoke-overrides` with time-bounded grants — unit-tested, but never called from the live path. Now it is. |
| `B2_derive::moduleVisible()` | Already implemented "flags gate, never grant". Now applied when producing `activeModules`. |
| `schoolControl.overrides` | Already the documented per-tenant axis (`b2_collections.php:47`, modes enumerated at `:69`). Now written and read. |
| `B2_registry_service` | Extended with two methods; no new service created. |
| `Tenant_capability_service` | **Untouched** — it already owns enforcement and is already fail-closed. |
| `tenantPublic.activeModules` | Remains the single client-readable mirror. No new collection. |
| `Superadmin_schools` | Two endpoints added mirroring `assign_plan()`'s exact shape (validate → `_b23c_registry()` → `sa_log()` → `json_success`). |
| `B2_ENTITLEMENT_RECOMPUTE` | Telemetry event was already allow-listed but never emitted. Now emitted. |

**No new** entitlement service, collection, plan system, subscription system, billing system or capability resolver.

## 3. Root cause

Two inline `plan.modules`-only loops — one in `assign_plan_to_school()`, one in `create_tenant()` — reimplemented a subset of `computeEntitlements()` and silently dropped the add-on and override axes. Because nothing else ever wrote `entitlements`, the per-tenant axis was unreachable by construction, and the designed model was dead code.

## 4. Exact implementation

**4.1 Capability registration** — `Superadmin_plans::AVAILABLE_MODULES` += `hrms_core`, `hrms_payroll`, `hrms_corporate`, `hrms_retail`. `capability_helper.php` += the three new constants + `HRMS_CAPABILITIES`. **No `hrms_attendance` / `hrms_leave`** — Attendance and Leave already exist as ZenX capabilities and must not get second switches.

**4.2 One shared derivation** — new private `B2_registry_service::_derive_entitlements(array $plan, array $control)`, routing through `B2_derive::computeEntitlements()` then filtering `activeModules` through `moduleVisible()`. **Both** writers now call it, so they cannot drift.

**4.3 Override-shape tolerance** — `_normalize_overrides()` accepts *both* the list shape (what `B2_derive` consumes and what live data is compatible with) and the module-keyed map shape (what `b2_collections.php:47` documents). The two disagreed; rather than pick one and risk a hand-authored map silently failing to grant, both are honoured.

**4.4 `recompute_entitlements($schoolId, $actor)`** — the missing refresh primitive. Re-derives from the tenant's *current* plan + add-ons + overrides + flags, writes `schoolControl.entitlements` and mirrors `tenantPublic.activeModules`, emits `B2_ENTITLEMENT_RECOMPUTE` when the module set actually changes. Idempotent, tenant-scoped, fail-closed on read/write errors.

**4.5 `set_module_override($schoolId, $module, $mode, $actor, $reason, $expiresAt)`** — per-tenant grant / revoke / clear. Writes only that tenant's `schoolControl.overrides`, appends a `tenantAudit` row, then recomputes. Enforces the HRMS dependency rule: `hrms_payroll` / `hrms_corporate` / `hrms_retail` **require** `hrms_core` (returns `requires_hrms_core`).

**4.6 SA surface** — `POST superadmin/schools/set_module_override` and `POST superadmin/schools/recompute_entitlements`, plus two routes. Input validation mirrors `assign_plan()`. No CSRF config change needed (`superadmin/schools(.*)` is already excluded).

**Deliberately NOT done:** the `b2.addons` **priced add-on catalog** and `b2.flags_global` remain `false` and unread. Those gate a *commercial billing* subsystem, not the entitlement grant path; activating them is a later commercial decision. Add-ons are still *honoured* if present (because `B2_derive` reads them) but nothing here writes one. **The override axis carries no migration flag** — verified: the only reader of `b2.addons` / `b2.flags_global` anywhere is the verifier's own key-existence assertion.

## 5. Multi-tenant behaviour

No tenant id appears anywhere in the implementation. Every read and write is keyed by the `$schoolId` argument. Derivation is a **pure function of one tenant's own plan + control document**, so one tenant's grant is structurally incapable of affecting another's.

## 6. Plan-sharing behaviour

The requirement — *sell HRMS to one tenant without unrelated tenants on the same base plan receiving it* — is met by using the **override** axis rather than the plan. `set_module_override()` never touches a plan document. Proven at runtime (CASE C/E): two tenants on the *same* plan object, one granted, one not; the shared plan is unmutated.

Plan-level HRMS still works too, for a genuinely HRMS-inclusive plan tier — both axes are supported, and a `revoke` override can suspend a plan-granted module without editing the plan.

## 7. Add-on / override behaviour

| Axis | Status |
|---|---|
| Override `grant` | **Live** — the per-tenant enablement path |
| Override `revoke` | **Live** — suspends without touching the plan; beats a plan grant |
| Time-bounded (`expiresAt`) | **Live** — expired grants do not entitle (proven) |
| `addOns` | Honoured if present; **no writer added** (priced catalog stays deferred) |
| `featureFlags` | Honoured as a **veto only** — cannot grant (proven) |

## 8. Security model

- **Tenant-scoped by construction** — all reads/writes keyed by `$schoolId`; no cross-tenant path exists.
- **Fail-closed everywhere** — `recompute_entitlements()` returns `ok:false` on any read/write failure rather than writing a partial/empty entitlement set; `Tenant_capability_service` remains fail-closed on a missing key.
- **Least privilege preserved** — endpoints live in the superadmin controller behind its existing SA authentication; `sa_log()` on every mutation, plus a `tenantAudit` row with `grantedBy` / `grantedAt` / `reason`.
- **Enforcement unchanged** — `require_capability()` and RBAC are untouched. Capability (tenant) and RBAC (user) remain two independent gates.
- **Input validation** — module keys constrained to `^[a-z0-9_]+$`, mode to `grant|revoke|''`, expiry to `YYYY-MM-DD`.
- **Telemetry** — entitlement changes are no longer silent.

## 9. Files changed

| File | Δ | What |
|---|---|---|
| `application/libraries/B2_registry_service.php` | +~250 / −10 | `_derive_entitlements()`, `_normalize_overrides()`, `recompute_entitlements()`, `set_module_override()`; both writers rewired |
| `application/controllers/Superadmin_plans.php` | +16 | 4 HRMS keys registered |
| `application/controllers/Superadmin_schools.php` | +~95 | 2 SA endpoints |
| `application/helpers/capability_helper.php` | +26 | 3 constants + `HRMS_CAPABILITIES` |
| `application/config/routes.php` | +5 | 2 routes |
| `application/controllers/Hrms_p0_verify.php` | +~105 | `group_entitlement()` — cases A–G |

## 10. Tests executed

**Runtime verifier — 76 PASS / 0 FAIL / 0 SKIP** (`php index.php hrms_p0_verify run`), executing the real `B2_derive` and real `rbac_helper` functions:

| Case | Assertion | Result |
|---|---|---|
| **A** | No grant ⇒ `hrms_core` absent | PASS |
| **B** | Override grant ⇒ `hrms_core = true`; plan modules undisturbed | PASS |
| **C** | Same plan, A enabled / B disabled | PASS |
| **D** | Revoke disables A; B unchanged | PASS |
| **E** | Shared plan object unmutated by a per-tenant grant | PASS |
| **F** | HRMS-disabled tenant keeps Staff/Attendance/Fees | PASS |
| **G** | HRMS-enabled tenant keeps them too (purely additive) | PASS |

Plus: expired grant does not entitle · unexpired does · plan-level axis still works · revoke beats plan grant · flag can veto but **cannot grant** · 4 keys registered · no `hrms_attendance`/`hrms_leave` · constants defined · both writers use the shared derivation · recompute + override primitives exist · telemetry emitted · **`b2.addons` and `b2.flags_global` still false**.

## 11. Regression results

| Suite | Result |
|---|---|
| **`B2_foundation_verify f1`** (the gate that unit-tests this exact derivation model) | **PASS — 27/0** |
| `vendor/bin/phpunit --testsuite Unit` | **170 tests, 440 assertions, 10 failures — identical pre-existing set, zero new** |
| `php -l` on all 6 changed files | **clean** |
| RTDB audit on the W1.1 diff | **clean — no RTDB primitive introduced** |
| Prior P0 runtime groups (payroll/lifecycle/RBAC/isolation) | still **PASS**, unchanged |

**`B2_foundation_verify flags` reports REVIEW (15 PASS / 2 FAIL)** — `b2.registry_firestore` and `b2.reports_firestore` are `true`. **Pre-existing and not mine**: `b2_migration_flags.php` is `git status`-clean (unmodified by this work) and was last changed 2026-06-13; the config's own comments document both as deliberately ACTIVATED in production, which the verifier's "must be FALSE on declaration" assertion predates. **`b2.addons` and `b2.flags_global` are not among the failures** — still false, exactly as required.

## 12. Firestore rules results

**No rules change was required, and none was made.** All affected collections (`schoolControl`, `plans`, `tenantAudit`) are already server-only deny-all to clients; `tenantPublic` is already client-readable and same-school-scoped, and its shape is unchanged (`activeModules` remains a string array). The rules suite was last run at **10 suites / 224 tests / 224 passed** and no rules file was touched since.

**No new indexes required.** All new queries are single-document `firestoreGet`/`firestoreUpdate` by `schoolId` — no new query shape, no composite index.

## 13. Tenant isolation results

Verified at runtime against **two live tenants** (read-only):
- `docId()` prefixes the active tenant.
- `schoolWhere('staff')` returned 11 rows, **0 foreign**.
- **Tenant-B staff `SSA0014` not readable through tenant-A scope.**
- The exited-staff roster query is school-scoped (2 rows, 0 foreign).

Plus the derivation-level proof (CASE C/D/E) that one tenant's entitlement cannot influence another's.

## 14. Existing module compatibility

**Nothing was re-gated.** No existing surface (Staff, HR, Payroll, Attendance, Leave, Fees, Accounting) now requires an HRMS capability. `hrms_core` continues to gate only the *new* Phase-1 HRMS surfaces.

The derivation rewire is a **provable no-op for current tenants**: with empty `addOns`/`overrides`/`featureFlags`, `computeEntitlements()` reduces exactly to the former plan-only loop — and `B2_foundation_verify f1` asserts precisely that reduction, passing 27/0. All live tenants have all three empty.

## 15. Remaining limitations

1. **No end-to-end grant executed against a live tenant.** `set_module_override()` writes real entitlement data; per the test-tenant rule I did not mutate any tenant's entitlement merely to demonstrate it. The full path is verified at the derivation and code level, and the two endpoints follow an already-proven controller pattern — but the *write* path has not been exercised in production. **To close this**, name a tenant and I will grant `hrms_core`, verify the capability flips, and revoke it again — a reversible, audited, single-tenant change.
2. **No SA UI widget** — the endpoints exist and are callable; no button was added to the school detail view (UI was out of scope for this task).
3. **Overrides have no expiry sweep.** A time-bounded grant stops entitling the moment `recompute_entitlements()` next runs, but nothing runs it on a schedule, so an expired grant can linger in the mirror until something triggers a recompute. Wiring recompute into the existing lifecycle sweep is the natural fix — flagged for a later wave, not silently assumed.
4. **Plan edits still do not fan out.** `update_plan()` does not recompute subscribed tenants; the primitive now exists to do it, but wiring the fan-out was outside W1.1's minimum scope.
5. `b2_collections.php`'s `overrides` comment still documents the map shape. Tolerated in code (§4.3); correcting the comment is cosmetic and was left alone to keep the diff minimal.

## 16. Recommendation for W1.2 (money precision)

W1.1 unblocks enablement; **W1.2 is now the highest-value next step and is independent of it**. Recommended approach when approved:

- Treat it as a **representation change, not an architectural one** — `_compute_payslip()` is already a pure function with a single call path, which makes it the natural containment boundary.
- Establish the invariants first as executable tests (`sum(components) == gross`, `gross − deductions == net`, to the paisa) — the P0 verifier already asserts these and can be extended.
- Do it **before** W2's component master: every component added afterwards multiplies the surface that must be converted.
- Sequence it **ahead of** W1.3 (ATS RTDB migration), which is self-contained and carries no dependency for other waves.

---

## Confirmations

- No second HRMS, no second Staff/Employee master, no second entitlement/capability/plan/subscription/billing system.
- Attendance **not** redesigned. RBAC **not** redesigned. Existing HR + Payroll foundation **not** replaced.
- No `hrms_attendance` / `hrms_leave` duplicate keys.
- No tenant id hardcoded; no test-tenant assumption; no tenant's entitlement modified.
- No RTDB introduced. No rules change. No index change. No destructive migration.
- Existing tenants keep every capability they had — provable no-op, gated by `B2_foundation_verify f1`.
- **Not committed, not pushed, not merged, not deployed. W1.2 / W1.3 / W2 / W3 not started.**
