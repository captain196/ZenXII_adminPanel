# ZENX MULTI-TENANT HRMS ENTITLEMENT ANALYSIS

**Status: ANALYSIS ONLY.** No entitlement data was modified. No plan was created or edited. No subscription was created. No billing was touched. No tenant's entitlement changed. All investigation was read-only; live-data probes ran from a scratchpad directory outside the repo and wrote nothing.

**Scope question:** *How should ZenX enable HRMS independently for one eligible tenant without unintentionally enabling it for unrelated tenants sharing the same base plan — as a product architecture, not as a test-tenant workaround?*

**Evidence typing:** **[REPO]** = observed in code with file:line. **[LIVE]** = observed in the live Firestore dataset via read-only probe. **[RECOMMENDATION]** / **[INFERENCE]** = flagged as such.

---

## 0. Executive summary — the four findings that decide this

1. **HRMS is currently impossible to enable for *any* tenant.** `Superadmin_plans::AVAILABLE_MODULES` (`Superadmin_plans.php:16-41`) is a 19-key hard whitelist enforced on both the plan-edit UI *and* the POST handlers, and both entitlement writers derive strictly from `plans.modules`. `hrms_core` is not in it. Phase 1 shipped an *enforced* capability gate with **no supported way to turn it on** — HRMS is hard-off platform-wide. **[REPO]**

2. **All 8 live tenants share one plan (`PLAN_7FBE49` "Premium").** Adding HRMS to that plan enables it for all 8 simultaneously. The plan-level route cannot express per-tenant enablement at all. **[LIVE]**

3. **The per-tenant mechanism you want already exists, fully specified and unit-tested — and is 100% dead code.** `B2_derive::computeEntitlements()` implements `plan.modules ∪ addOns − revoke-overrides` with time-bounded grants; `moduleVisible()` implements "flags gate, never grant". Both are called **only** from a CLI self-test (`B2_foundation_verify.php`). The two live writers reimplement a plan-only subset inline and drop the add-on and override axes entirely. **[REPO]**

4. **Entitlements are never refreshed.** They are written at exactly two moments — tenant onboard and SA plan-assign — and nothing recomputes them afterwards. Editing a plan does **not** propagate to subscribed tenants; there is no cron, no Cloud Function, no reconciler, and the reserved `B2_ENTITLEMENT_RECOMPUTE` telemetry event has no emitter. **[REPO]**

**Conclusion:** the correct architecture is not a new entitlement system and not an HRMS-specific plan. It is to **wire up the entitlement model ZenX already designed**, then sell/enable HRMS as a **per-tenant add-on** on top of any base plan. Critically, that refactor is a **provable no-op for all 8 current tenants** (§14), which makes it the safe path as well as the correct one.

---

## 1. Current plan architecture **[REPO]** **[LIVE]**

| Aspect | Reality |
|---|---|
| Catalogue collection | `plans/{planFamilyId}__v1` |
| Specified as | *"immutable versioned catalog"* — `application/config/b2_collections.php:25` |
| Actually | **Mutated in place.** `Superadmin_plans::update()` (`:132-178`) merge-patches `modules` on the existing `__v1` doc (`:162`) via `B2_registry_service::update_plan()` (`:484-494`). The immutability contract is documented but not enforced. |
| Version resolution | `_plan_doc_id()` (`B2_registry_service.php:380-389`) is a literal `$planFamilyId . '__v1'` concatenation. All 5 call sites hard-resolve to `__v1`. |
| Can a `__v2` be created? | **No.** `create_plan` takes the doc id from `_plan_doc_id()`; passing `version: 2` would overwrite the `__v1` doc with `version:2` inside it. The SA "new plan" flow mints an unrelated random family id (`Superadmin_plans.php:107`). |
| Tenant→version migration | **NOT FOUND** — no mechanism anywhere. |
| Live plans | 5: Standard (12 modules), **Premium (15)**, Sample (15), Free (19), Basic (6). Union of all keys = 19. **[LIVE]** |
| Live plan sharing | **8 of 8 active tenants on `PLAN_7FBE49`.** **[LIVE]** |
| Module vocabulary | 19 snake_case keys, single producer: `Superadmin_plans::AVAILABLE_MODULES` (`:16-41`). No `hr`, no `transport`, no `hostel` key exists. `staff_management` is the de-facto HR entitlement. |

**Pre-existing defects found incidentally** (not caused by, and not to be fixed by, HRMS work):
- `PLAN_7FBE49.activeSubscriptionCount = 0` while 8 tenants are actively subscribed — any billing/reporting surface reading that counter is wrong. **[LIVE]**
- Route `superadmin/plans/sync_to_firestore` (`routes.php:262`) maps to a method that does not exist — dead route. **[REPO]**
- `b2_subscription_statuses` includes `scheduled` and `superseded` (`b2_collections.php:74-75`) but no writer ever sets them; prior subscription rows are never marked superseded. **[REPO]**

---

## 2. Current tenant entitlement architecture **[REPO]**

```
plans/{fam}__v1.modules          ← SA-edited catalogue (mutable)
        │  read ONCE, at onboard or plan-assign
        ▼
schoolControl.entitlements       ← {<moduleKey>:bool}   WRITE-ONLY: read by NOTHING
        │  array_keys()
        ▼
tenantPublic.activeModules       ← client-readable mirror; the only consumed surface
        ├──► B2_registry_service::get_features()  →  session school_features
        │         →  _normalize_features()  →  header.php sidebar        [UI ONLY]
        └──► Tenant_capability_service::isEnabled()                      [ENFORCED ✓]
```

- **`schoolControl.entitlements` is read by nothing.** Every occurrence is a write (`B2_registry_service.php:1289`, `:1412`, `:1512`). It is a write-only shadow of the mirror. **[REPO]**
- **`tenantPublic.activeModules` has exactly two consumers.** `get_features()` (`:335-352`) → sidebar visibility only, blocking no request and no data. And `Tenant_capability_service` (Phase 1, uncommitted) — **the first and only enforced module-granularity gate in the platform**, on 4 endpoints. **[REPO]**
- **Firestore rules never read `activeModules`.** Grepped the whole ruleset. Rules gate at *tenant-lifecycle* granularity via `tenantActive()` (`firestore.rules:51-63`) — `lifecycle.state` + `adminDisabled` only, zero module granularity. Mobile apps *can* read `activeModules` (`:2520-2526`) but no rule enforces it; app-side module gating is presentation only. **[REPO]**
- Both enforced access gates — `lifecycle_access()` (5-minute session recheck, `MY_Controller.php:550`) and `login_access_view()` (login block) — are **entitlement-blind**. **[REPO]**

**Net gate topology:** access is enforced at tenant-lifecycle granularity; module granularity is enforced in exactly one place (the new capability service); everything else module-level is sidebar cosmetics.

---

## 3. `plans/{plan}.modules` **[REPO]**

Shape `{<moduleKey>: bool}`. Only truthy keys become entitlements. Written solely by `Superadmin_plans::create()/update()`, strictly whitelisted against `AVAILABLE_MODULES` (`:103`, `:153`) — **no key outside the constant can ever reach a plan doc.** A key present-but-`false` is meaningfully different from absent: `B2_foundation_verify.php:65-66` proves `gallery:false` is *not entitled but is add-on-purchasable*, which is the designed hook for exactly this kind of upsell.

---

## 4. `tenantPublic.activeModules` **[REPO]**

Specified as ***`moduleVisible`-filtered*** (`b2_collections.php:56`). **Actual writers do a raw `array_keys($entitlements)`** with no `moduleVisible()` call (`B2_registry_service.php:1278`, `:1408`) — so the axis-5 flag veto is specified into this field and absent from it.

Two live-data consequences observed: one tenant's `entitlements` map contains a `computedAt` **timestamp string as if it were a module key** (16 keys vs 15 real modules), written by the historical backfill — and since `activeModules` is derived by `array_keys()`, any non-module key in that map can leak into the capability list. **[LIVE]** Separately, `scripts/sa_b2_schools_backfill.js:205-212` never wrote `activeModules` at all despite its header claiming to, so a backfilled-and-never-reassigned tenant has no `activeModules`, which under the new service's fail-closed read means *every capability off*. **[REPO]**

---

## 5. `schoolControl.entitlements` **[REPO]**

`{<moduleKey>:bool, computedAt}` (`b2_collections.php:46`, "axis 4 (derived)"). Server-only (`firestore.rules:2505` deny-all to clients). **Write-only in practice** — see §2. Its existence as an unread audit shadow is why a mirror/authority drift would currently be undetectable.

---

## 6. `assign_plan_to_school()` **[REPO]**

`B2_registry_service.php:1238-1310`. Per call it: creates a new `subscriptions/SUB_*` row (`:1253-1266`); repoints `schoolControl.subscription`, sets `lifecycle.state='active'`, `subscriptionPeriodEnd`, and `entitlements` (`:1281-1291`); mirrors `tenantPublic.activeModules` best-effort (`:1297-1304`); writes a `tenantAudit` row.

The entitlement computation, verbatim (`:1272-1278`):

```php
$modules = is_array($plan['modules'] ?? null) ? $plan['modules'] : [];
$entitlements = [];
foreach ($modules as $m => $enabled) { if (!empty($enabled)) $entitlements[$m] = true; }
$activeModules = array_keys($entitlements);
```

**This is the whole problem in five lines** — plan-only, `addOns` and `overrides` ignored, `moduleVisible()` not applied. `create_tenant()` (`:1404-1408`) duplicates the identical logic. These are the only two entitlement writers in the codebase.

Side effects worth noting for any operational use: it **resets `periodStart` to today** (`:1258`) and requires an `$expiryDate` (`:1240`), i.e. it is a *billing* operation, not a capability toggle.

---

## 7. `B2_registry_service` **[REPO]**

Entitlement-relevant public API: `get_plan`/`create_plan`/`update_plan`/`delete_plan`, `count_schools_on_plan`/`count_schools_by_plan`, `assign_plan_to_school`, `create_tenant`, `get_features`, `write_lifecycle_state`, `set_admin_disabled`, `record_payment_completion`.

**There is no per-tenant module API.** No `grant_module()`, no `add_addon()`, no `set_override()`, no `recompute_entitlements()`. The service can change a tenant's *whole plan* or nothing.

---

## 8. Existing plan/subscription lifecycle **[REPO]**

`b2_lifecycle_states`: `trialing, active, expiring_soon, grace, past_due, suspended, expired` (`b2_collections.php:64`). `B2_derive::computeLifecycle()` implements the state machine with strict precedence; `computeEffectiveAccess()` composes `adminDisabled ∧ lifecycle`. **Both are unwired** — `lifecycle_access()` and `login_access_view()` reimplement the logic inline, and the access-state list exists in three separate copies (`B2_derive.php:47-50`, the service constant, `b2_collections.php:78`). `B2_lifecycle_job::run()/reconcile()` are flag-gated stubs whose bodies are comments (`B2_lifecycle_job.php:49-75`), and `scripts/cron.txt` schedules **no B2 job at all**.

---

## 9. Are tenant-level module overrides actually supported? **NO — designed, tested, dead.** **[REPO]** **[LIVE]**

| Layer | Designed | Wired | Ever used |
|---|---|---|---|
| `subscription.addOns[]` (`{module, kind:'priced', effectiveUntil?}`) | ✅ `B2_derive.php:137-145`; tested `B2_foundation_verify.php:65-66,71-72` | ❌ zero readers/writers outside B2_derive | ❌ 0 / 8 tenants **[LIVE]** |
| `schoolControl.overrides[]` (`{module, mode:'grant'\|'revoke', expiresAt?}`) | ✅ `B2_derive.php:148-161`; tested `:67-70` | ❌ zero readers/writers | ❌ 0 / 8 tenants **[LIVE]** |
| `schoolControl.featureFlags` (axis 5, "gate not grant") | ✅ `moduleVisible()` `B2_derive.php:268-284`; tested `:98-106` | ❌ written empty once (`B2_registry_service.php:1514`), **read by nothing** | ❌ 0 / 8 tenants **[LIVE]** |

Corroborating: the migration flags `b2.addons` and `b2.flags_global` are *required to be `false`* by `B2_foundation_verify.php:153` — a formal, tested statement that these subsystems are deliberately un-activated. And 7 of 8 tenants don't even have the `addOns`/`overrides` fields provisioned (absent, not empty). **[LIVE]**

**One contract defect to resolve before any writer is built:** `b2_collections.php:47` specifies `overrides` as a **map keyed by module** (`{<module>:{mode,reason,grantedBy,grantedAt,expiresAt?}}`), while `B2_derive::computeEntitlements()` consumes a **list of rows** (`:121`, `:148-151`). Live data (`[]`) is compatible with the list. **[RECOMMENDATION]** adopt the list shape (matches the tested function and live data) and correct the config comment — do not build a writer against the map shape.

---

## 10. Can HRMS be enabled independently per tenant today? **NO — on three independent counts.** **[REPO]**

1. `hrms_core` is not in `AVAILABLE_MODULES`, so it cannot enter any plan doc — and both writers read only plan docs. No UI path, no service method, no data path. **HRMS is unreachable for every tenant.**
2. Even with the key added, the only enablement lever is *whole-plan assignment*. All 8 tenants share `PLAN_7FBE49`, so plan-level enablement is all-or-nothing.
3. The per-tenant lever (add-on / override) is dead code.

**Therefore the three superficially-available options all fail:**

| Option | Verdict |
|---|---|
Add `hrms_core` to `PLAN_7FBE49` | **Rejected** — enables HRMS for all 8 unrelated tenants at once. |
Direct write to `tenantPublic.activeModules` | **Rejected** — bypasses the canonical writer, and desynchronizes the mirror from `schoolControl.entitlements`; the drift is undetectable because nothing reads `entitlements` (§5). |
New "Premium + HRMS" plan, reassign one tenant | **Works today, but wrong as product architecture** — see §12. |

---

## 11. Minimum safe extension required **[RECOMMENDATION]**

Seven steps, ordered by dependency. Steps 1–3 are the minimum to make HRMS enablable at all; 4–5 are required before any *time-bounded* or *plan-edited* entitlement is trustworthy; 6–7 are operability.

| # | Change | Files | Size | Why non-optional |
|---|---|---|---|---|
| **1** | Add HRMS capability keys to `AVAILABLE_MODULES` | `Superadmin_plans.php:16-41` | ~4 lines | Without this HRMS is permanently dark. Nothing else can matter first. |
| **2** | Replace both inline plan-only loops with `B2_derive::computeEntitlements($planVersion,$addOns,$overrides,$now)`, and filter `activeModules` through `moduleVisible()` | `B2_registry_service.php:1272-1278`, `:1404-1408` | ~10 lines | Activates the add-on + override + flag axes. Both functions are already unit-tested. **Provably a no-op for all current tenants (§14).** |
| **3** | New `recompute_entitlements(string $schoolId): bool` — re-derive from current plan + addOns + overrides, rewrite `entitlements` + `activeModules`, emit the reserved `B2_ENTITLEMENT_RECOMPUTE` telemetry event | `B2_registry_service.php` | ~40 lines | The missing refresh primitive. Decouples *capability change* from *billing change* — today the only way to alter entitlements is to create a subscription row and reset `periodStart` (§6). |
| **4** | Fan out on plan edit: after `update_plan`, recompute every tenant on that plan | `Superadmin_plans.php:174` | ~10 lines | Closes the silent-desync hole in §12. `count_schools_on_plan()` plumbing already exists and is unused on this path. |
| **5** | Periodic recompute for time-bounded grants (fold into the existing `expire_check()` sweep) | `Superadmin_plans.php:408-454` | ~15 lines | **Design consequence:** `addOns.effectiveUntil` / `overrides.expiresAt` mean entitlements are time-dependent, but the stored mirror is a static snapshot. Without a sweep, an expired add-on stays *active* forever. Skipping this makes trials and time-boxed pilots unsafe. |
| **6** | SA UI: per-tenant add-on grant/revoke + an entitlement viewer | `Superadmin_schools` view + controller | moderate | Operators currently cannot even **see** a tenant's entitlements, let alone grant one. |
| **7** | Fix the `overrides` map-vs-list contract (§9) | `b2_collections.php:47` | comment | Prevents building a writer against the wrong shape. |

**Explicitly NOT recommended:** a second entitlement system; a new per-tenant flag store; extending `_normalize_features()`; reusing the rules' `hasCapability()`.

---

## 12. How plan + add-on capabilities should work **[RECOMMENDATION]**

**Model: base plan = what the tier includes. Add-on = what this tenant additionally purchased. Override = comp/pilot/revocation exception. Flag = emergency veto.**

```
effective = plan.modules  ∪  active addOns  −  active revoke-overrides
            then filtered by  moduleVisible(global flags, per-school flags)
```

This is verbatim what `B2_derive` already implements — adopting it is *conformance*, not new invention.

**Why add-on and not a plan tier:** HRMS is an upsell to an existing school, not a different kind of school. Plan-per-combination explodes combinatorially (5 base plans × HRMS × payroll × 2 vertical packs), and — decisively — changing a tenant's HRMS status via plan assignment is a *billing* event that creates a subscription row and resets `periodStart` (§6). Capability changes must not be forced through billing churn.

**Division of responsibility:**

| Mechanism | Use for | Billing |
|---|---|---|
Plan modules | Tier-included capability | In plan price |
Add-on (`kind:'priced'`) | Purchased HRMS / vertical pack; time-boxed trials via `effectiveUntil` | Add-on line item |
Override `grant` | Comps, pilots, migration windows (audited via `grantedBy`/`grantedAt`) | None |
Override `revoke` | Suspend one capability without touching plan or lifecycle | None |
`featureFlags` | Emergency kill-switch — **can veto, never grant** (invariant tested at `B2_foundation_verify.php:100`) | None |

---

## 13. How Education / Corporate / Retail capabilities fit **[RECOMMENDATION]**

Vertical packs are **plan-key-vocabulary capabilities**, not RBAC modules, and not `orgType`. Keep three axes strictly separate:

| Axis | Question | Where | Consulted at runtime by the engine? |
|---|---|---|---|
Tenant capability | "Has this tenant bought it?" | `tenantPublic.activeModules` → `has_capability()` | Yes — gates endpoints |
RBAC | "May this user do it?" | `schools.staffRoles` → `has_permission()` | Yes — gates actions |
`orgType` + `labels` | "What do we *call* things here?" | `schools.orgType` / `.labels` (Phase 1) | **No — presentation only.** Must never gate behaviour. |

**Recommended key set — deliberately coarse.** Over-granular keys recreate the combinatorial problem that add-ons solve:

```
hrms_core                 Employee · Org · Location · terminology        (Phase 1)
hrms_payroll              Salary structures · payroll runs · payslips ·
                          compliance                                     (the premium SKU)
hrms_vertical_corporate   Timesheets · projects · utilisation
hrms_vertical_retail      Stores · shift roster · rotating weekly-offs
```

Education needs **no** vertical key — it is the base case that `hrms_core` already serves; a vertical key for it would be dead weight.

**Two decisions to make explicitly** (do not let these be resolved by accident):
- **Attendance/Leave: do NOT mint `hrms_attendance`/`hrms_leave`.** An `attendance` plan key already exists and staff HR already rides on `staff_management` (`header.php:591`, `:612`). Minting parallel keys would create two entitlements for one capability. **[RECOMMENDATION]** HRMS attendance/leave ride under `hrms_core`; the existing `attendance` key keeps meaning student attendance.
- **`staff_management` vs `hrms_core` relationship.** `staff_management` currently gates the existing HR & Payroll sidebar. Decide whether `hrms_core` *supersedes* it (and `staff_management` becomes legacy) or *layers on top of* it. **[RECOMMENDATION]** layer on top for Phase 1 — zero disruption to the 8 tenants that already hold `staff_management`.

---

## 14. How existing customers remain unaffected **[REPO]** — the key safety proof

**The §11 step-2 refactor is a provable no-op for every current tenant.** `computeEntitlements(plan, [], [], now)` with empty `addOns` and `overrides` reduces exactly to the current inline loop — and `B2_foundation_verify.php:63-64` is precisely that assertion, already passing. All 8 tenants have empty/absent `addOns` and `overrides` **[LIVE]**, so the derived output is byte-identical.

Likewise `moduleVisible()` with no flags set returns "entitled ⇒ visible" unchanged, and all 8 tenants have `featureFlags = []` **[LIVE]**.

And because a missing capability key is **disabled by default** (fail-closed, `Tenant_capability_service`), introducing HRMS keys turns nothing on for anyone. Enablement remains a deliberate, per-tenant, audited act.

**Residual risk:** none of these steps should be shipped without re-running `B2_foundation_verify` (`php index.php b2_foundation_verify f1`), which is the existing regression harness for exactly this logic.

---

## 15. Firestore schema changes **[RECOMMENDATION]**

**None required.** Every field is already in the specified schema:
- `subscription.addOns[]` — documented `b2_collections.php:50`; provisioned on 1 of 8 tenants, absent on 7. Absent is safely treated as empty by `computeEntitlements`, so **no backfill is required**; optionally provision `[]` for consistency.
- `schoolControl.overrides[]` — documented `:47`; same situation. Adopt the list shape (§9).
- `schoolControl.featureFlags` — documented `:49`; already `{}`/`[]` everywhere.

Phase 1's own additions (`schools.orgType`, `schools.labels`, `locations`, `staff.locationId`) are unrelated to entitlement and already additive.

---

## 16. Firestore rules changes **[RECOMMENDATION]**

**None required for Phase 1**, and none for the entitlement wiring: PHP writes via the Admin SDK (bypasses rules), `tenantPublic` is already client-readable same-school-scoped (`firestore.rules:2520-2526`), and `schoolControl`/`plans` are already deny-all to clients.

**Required only if/when a mobile app must be module-gated at the rules layer.** That needs a new helper, which does not exist today:

```
function tenantHasModule(m) {
  return get(/databases/$(database)/documents/tenantPublic/$(request.auth.token.school_id))
           .data.get('activeModules', []).hasAny([m]);
}
```

Two cautions: **(a)** budget the extra document access — the ruleset already performs ~15 `get()`/`exists()` calls across 6 helpers, and Firestore caps accesses per request; **(b)** **do not reuse `hasCapability()`** (`firestore.rules:203-210`) for this. It is a *per-user* capability read from `staffCapabilities/{uid}`, it **fails open** by design (`return !staffCapsExist() || ...`), and it is deliberately confined to write arms (`:200-202`). A tenant-entitlement gate must fail closed, so reusing it would let un-backfilled staff write HRMS data on an unentitled tenant.

Phase 1's own `locations` rules block is separately covered: a real cross-tenant write hole was found and fixed during runtime validation, with 13 new tests added (`firebase-rules/tests/locations_sec.test.js`), full suite now 224/224.

---

## 17. Migration requirements **[RECOMMENDATION]**

Non-destructive throughout; nothing to rewrite or delete.

1. **No data migration for the refactor** — proven no-op (§14).
2. **Optional hygiene** (independent of HRMS; recommend as separate authorized work, not bundled): provision absent `addOns`/`overrides` as `[]`; repair `PLAN_7FBE49.activeSubscriptionCount` (0 → 8); strip the stray `computedAt` key from the one tenant's `entitlements` map; remove the dead `sync_to_firestore` route.
3. **Rollback:** revert the two writer call sites; the stored mirrors remain valid because the refactor produces identical output for current data.
4. **Recompute is idempotent** by construction — safe to re-run per tenant at will.

---

## 18. Backward compatibility **[RECOMMENDATION]**

- Existing tenants: unchanged entitlements, unchanged sidebar, unchanged access. **[proven §14]**
- Existing plans: untouched; no plan gains a key unless an operator checks it.
- Unknown-key safety already holds: `_normalize_features()` passes an unrecognised key through inert (`MY_Controller.php:862-865`), so `hrms_core` appearing in `activeModules` cannot corrupt the sidebar.
- **Do not extend `_normalize_features()`** for HRMS. It force-adds three labels unconditionally (`:868-871`), already has four orphaned plan keys (`admission`, `library`, `events`, `ptm`), and is a private snake→Title bridge with no tests. Gate new sidebar sections on `has_capability()` directly — already loaded and callable in `header.php` with zero new wiring.

---

## 19. Billing / entitlement implications **[RECOMMENDATION]**

- `addOns` carry `kind:'priced'` (`B2_foundation_verify.php:65`) — the *only* `kind` value anywhere, and no `b2_addon_kinds` enum exists. Enumerate kinds (e.g. `priced` / `included` / `trial`) before the first paid add-on ships.
- `computeBillingSummary()` projects invoices/payments only; it has **no notion of add-on pricing**. Invoicing must gain add-on line items before HRMS is *sold* — this is a commercial-launch dependency, **not** a blocker for enabling HRMS on a tenant for validation.
- Capability change is decoupled from billing by design here: `recompute_entitlements()` (§11.3) changes capability without creating a subscription row or resetting `periodStart`, which `assign_plan_to_school` cannot avoid doing.
- The designed-but-unimplemented `scheduled`/`superseded` subscription statuses (§1) are the natural home for future scheduled add-on start/end; out of scope now, worth not contradicting.

---

## 20. Security implications **[RECOMMENDATION]**

1. **Preserve "flags gate, never grant."** `moduleVisible()` enforces it and `B2_foundation_verify.php:100` tests it. A feature flag must never substitute for a paid entitlement.
2. **Fail-closed everywhere.** Already correct in `Tenant_capability_service` (empty on read error, absent key ⇒ disabled). Any new resolver must match; never fail open on an unreadable entitlement doc.
3. **Capability ≠ RBAC ≠ isolation — three orthogonal gates.** Entitlement must never be used as an authorization check, and RBAC must never be used as an entitlement check. Enforced structurally by `HrmsCapabilityGateTest` (equal-count, non-nested assertions).
4. **Tenant isolation is unaffected** by any of this — entitlement governs *whether a feature exists* for a tenant, never *whose data is reachable*. Isolation remains enforced by `docId()`/`schoolWhere()` scoping plus rules.
5. **Audit grant paths.** The config skeleton already specifies `grantedBy`/`grantedAt`/`reason` on overrides (`b2_collections.php:47`) — populate them, and write a `tenantAudit` row per grant/revoke, matching `assign_plan_to_school`'s existing behaviour.
6. **Emit `B2_ENTITLEMENT_RECOMPUTE`.** The event is already reserved and required by the telemetry gate; a silent entitlement change on a live tenant should be impossible.
7. **Do not reuse the rules' fail-open `hasCapability()`** for tenant entitlement (§16).

---

## Recommendation

**Adopt the entitlement model ZenX already designed, and sell HRMS as a per-tenant add-on.** Concretely: §11 steps 1–3 make HRMS enablable at all and are a proven no-op for current tenants; steps 4–5 must land before any time-bounded or plan-edited entitlement can be trusted; 6–7 are operability and contract hygiene.

For Phase 1 runtime validation specifically, this yields a path that is **the production path, not a test workaround** — no test-tenant special case, no hardcoded id, no plan surgery, no direct mirror write, and identical for the 1st and the 1000th tenant: add the HRMS keys, wire `computeEntitlements`, add `recompute_entitlements()`, then grant `hrms_core` to one tenant as an add-on (or an audited `grant` override) and recompute that tenant only.

**Nothing above has been implemented.** Steps 1–5 modify `B2_registry_service` and `Superadmin_plans` — billing/entitlement infrastructure shared by all 8 live tenants — so I am not touching them without explicit approval of this analysis, and I would want the `B2_foundation_verify f1` gate re-run as the acceptance check.

---

## Confirmations

- No separate HRMS system, tenant model, employee master, capability system, or accounting duplication is proposed — the recommendation is strictly to *conform to* the existing designed model.
- No RTDB anywhere.
- No destructive migration; no live entitlement, plan, subscription, or billing data was modified during this analysis.
- `SCH_D94FE8F7AD` receives no architectural privilege. It is referenced in this document only as one of 8 tenants in the live-data evidence; every recommendation is tenant-agnostic and contains no tenant-specific branch.
