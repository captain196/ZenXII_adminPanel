# PHASE 1 — ZENX HRMS FOUNDATION CERTIFICATION

**Date:** 2026-08-08
**Scope:** Organization/Location model + tenant capability enforcement + terminology layer + isolation validation
**Status:** **CODE COMPLETE — STOPPED FOR APPROVAL.** Not committed, not pushed, not merged, not deployed. Firestore rules written but **NOT deployed**. Phase 2 not started.

> **One item requires your attention before this can be functionally verified end-to-end** — see §11 and §16. The static/structural work is complete and verified; the runtime browser walkthrough is blocked on a tenant-side prerequisite that is your decision to make, not mine to assume.

---

## 1. Files changed

**New (5):**

| File | Purpose |
|---|---|
| `application/libraries/Tenant_capability_service.php` | Centralized capability resolver — `isEnabled($key)` against `tenantPublic/{schoolId}.activeModules`. Request-scoped memo, fail-closed on read error or missing doc. |
| `application/helpers/capability_helper.php` | `has_capability()` / `require_capability()` + the `HRMS_CORE_CAPABILITY` constant. Deliberately mirrors `rbac_helper.php`'s shape so both gates read alike at a call site, while answering different questions. |
| `application/helpers/terminology_helper.php` | `org_default_labels()`, `org_term()`, `org_locations_for_school()`, `ensure_org_profile()`. |
| `application/controllers/Workforce_locations.php` | Location CRUD (AJAX-only, no page of its own). |
| `tests/Unit/LocationSchoolIdPredicateTest.php`, `tests/Unit/HrmsCapabilityGateTest.php` | Isolation + capability/RBAC-separation regression tests (11 tests, 47 assertions). |

**Modified (9):**

| File | Change |
|---|---|
| `application/core/MY_Controller.php` | +2 lines loading `tenant_capability_service` as `$this->capability` and the `capability` helper; +1 block initializing the service from `$this->school_id`. Purely additive — no existing line altered. |
| `application/libraries/Firestore_service.php` | `saveSchool()` fieldMap +1 entry (`orgType`); `saveStaff()` +1 optional field (`locationId`, only written when supplied). |
| `application/controllers/School_config.php` | `get_config()` +capability-gated `organisation` payload; `save_profile()` +capability-gated `org_type`; new `save_terminology()` endpoint. |
| `application/controllers/Staff.php` | `new_staff()`/`edit_staff()` +capability-gated location dropdown data; both write paths +capability-gated optional `locationId`. |
| `application/controllers/Hr.php` | `index()` +capability-gated `staff_term` resolution (the single terminology proof-of-concept). |
| `application/config/routes.php` | +4 routes (`school_config/save_terminology`, `workforce_locations/{get_data,save,delete}`). |
| `application/views/school_config/index.php` | +hidden-by-default "Organisation" tab, +pane, +client JS (`renderOrganisation()` and location CRUD). |
| `application/views/new_staff.php`, `application/views/edit_staff.php` | +capability-gated Location `<select>`. |
| `application/views/hr/index.php` | 1 heading now reads `$staff_term` (defaults to the previous hardcoded `Staff`). |
| `firebase-rules/firestore.rules` | +1 `match /locations/{docId}` block. **Not deployed.** |

---

## 2. Firestore collections changed/created

| Collection | Change | Migration needed? |
|---|---|---|
| `locations` | **NEW.** Doc id `{schoolId}_{locationId}`. Fields: `schoolId, locationId, name, isDefault, createdAt, updatedAt`. | No — created lazily by `ensure_org_profile()` on first capability-enabled read. |
| `schools/{schoolId}` | +2 optional fields: `orgType` (`EDUCATION`\|`CORPORATE`\|`RETAIL`), `labels` (3-key map). | **No.** Schemaless doc, merge-write only, every reader null-coalesces. Absent fields behave exactly as before. |
| `staff` | +1 optional field: `locationId`. | **No backfill.** Missing/null means "the default location," consistent with existing conventions. |
| `tenantPublic/{schoolId}` | **Read-only consumer added.** Not written by any Phase 1 code. | None. |

No collection deleted, no field deleted, no document rewritten, no mass update performed.

---

## 3. Firestore rules changed

One new block, following the existing `staff` idiom verbatim (re-read immediately before editing, per this repo's concurrent-edit convention):

```
match /locations/{docId} {
  allow read: if isSameSchool();
  allow write: if isAdmin();
}
```

`git diff --stat` on `firestore.rules`: **+14 / −0** (block + explanatory comment; nothing else in the file touched). Needs no new claim — `orgId == schoolId`, so the existing `school_id` claim that every other school-scoped collection already uses is sufficient. **NOT DEPLOYED** — and Phase 1 does not depend on the deploy, because PHP (service account) bypasses rules entirely and no mobile client reads `locations` yet.

---

## 4. Indexes changed

**None.** Every new query is a single-field `schoolId` equality (`schoolWhere('locations', [])`) or a direct doc-id `get()` — both served without a composite index. The existing 260-entry composite index footprint is unchanged. No `firestore.indexes.json` edit was made or is required.

---

## 5. Capability enforcement implementation

Per your correction, **no raw `activeModules` array checks were scattered into controllers.** One service, one helper, one constant:

```
tenantPublic/{schoolId}.activeModules        (existing Firestore entitlement mirror, plan-driven)
        ↓ read once per request, memoized, fail-closed
Tenant_capability_service::isEnabled($key)   (library, bound in MY_Controller as $this->capability)
        ↓
has_capability($key) / require_capability($key, $action)   (helper — the only thing call sites use)
        ↓
HRMS_CORE_CAPABILITY = 'hrms_core'           (one constant, never a repeated magic string)
```

**Fail-closed at three levels:** an unreadable `tenantPublic` doc logs and returns `[]` (not "everything on"); a missing capability key is disabled by default, so shipping a new key never silently activates it for existing tenants; and if the service itself were somehow not loaded, `has_capability()` logs and returns `false` rather than granting.

This is the **first server-side enforced consumer** of `tenantPublic.activeModules`. The pre-existing `school_features` session variable (sidebar visibility only) was deliberately **not** reused — it's a lossy, display-name-only projection that is never checked server-side, so building enforcement on it would have produced a gate that silently does nothing.

---

## 6. RBAC interaction

The two gates are independent, unconditional, side-by-side statements at every new endpoint — neither nested in the other, neither substituting for the other:

```php
require_permission('HR', 'workforce_locations_save', 'edit');      // can THIS USER?
require_capability(HRMS_CORE_CAPABILITY, 'workforce_locations_save'); // does THIS TENANT have it?
```

**No change was made to the RBAC system.** No new RBAC module string was introduced — Location rides under the existing `'HR'` module, org-profile/terminology under the existing `'Configuration'` module, so `functions/rbac_modules.json`, `RBAC_MODULE_META`, and each school's `staffRoles` catalogue are all untouched.

`HrmsCapabilityGateTest` enforces the separation structurally: all 3 `Workforce_locations` endpoints carry both gates, the counts must be **equal** (so a future endpoint can't get one gate and not the other), and a regex assertion fails the build if `require_capability()` is ever nested inside a `require_permission()`-gated conditional.

---

## 7. Organization model

No `organizations` collection was created — `orgId == schoolId` for every tenant today, so organization metadata (`orgType`, `labels`) lives as two optional fields on the existing `schools/{schoolId}` doc. Documented in-code as **provisional**: if multi-school-per-organisation is ever needed, these lift into a real `organisations` collection above `schools`.

```
Organization / Legal Entity   = schools/{schoolId}   (EXISTING, unchanged)
      └── Location            = locations/{schoolId}_{locationId}   (NEW)
            └── Department     = schools/{id}.departments   (EXISTING, via Org.php — untouched)
                  └── Employee = staff/{schoolId}_{staffId}   (EXISTING, +1 optional field)
```

---

## 8. Location model

One generic entity serving all three verticals — Campus / Office / Store differ only by the terminology label, never by schema or code path.

**Location IDs are opaque** (`LOC_` + 4 random bytes hex), not sequential. A sequential scheme would require read-all-then-pick-next-free against a separate collection on every create, which is a genuine TOCTOU race under concurrent saves — unlike `Org.php`'s `_generate_role_id()`, which is safe only because it operates on one in-memory map inside a single document write.

**`delete()` has three independent guards:** refuse if default · refuse if any staff assigned · refuse if it's the last remaining location. The third matters even when the first two pass (a school's only non-default location with nobody assigned yet), so a school can never be left with zero locations.

---

## 9. Terminology model

Three canonical keys (`person`, `location`, `manager`), seeded from `orgType`, editable per tenant, resolved through `org_term()`:

| Key | EDUCATION | CORPORATE | RETAIL |
|---|---|---|---|
| `person` | Staff | Employee | Associate |
| `location` | Campus | Office | Store |
| `manager` | Principal / HOD | Manager | Store Manager |

Resolution order: tenant override → org-type default → `ucfirst(key)`. No entity is duplicated because a label differs. Phase 1 wires exactly **one** heading (`hr/index.php`) as proof the mechanism renders end-to-end; a full retrofit of every hardcoded English string is later-phase work (this codebase has no i18n layer at all — an `L`-effort item flagged in the blueprint).

---

## 10. Migration / backward compatibility

Everything is additive; **no migration script exists or is needed.**

- Seeding is lazy and idempotent via `ensure_org_profile()`, mirroring the existing `ensure_unified_roles()` pattern — writes only what's missing, safe to call on every load, called from every endpoint that needs the data (not just the settings screen) so no consumer ever sees an empty state.
- Existing `staff` docs are **not** backfilled with `locationId`; readers treat missing as "default."
- Rollback is trivial by construction: delete the `locations` collection and the two `schools` fields, and the system is byte-for-byte as before. No pre-existing field or document was rewritten.

---

## 11. Existing-school regression results

**Static verification — complete and passing:**

- `php -l` clean on all 12 new/modified PHP files.
- `vendor/bin/phpunit --testsuite Unit`: **166 tests, 411 assertions, 10 failures.** All 11 new tests pass (`OK (11 tests, 47 assertions)` when run in isolation).
- **All 10 failures are pre-existing and provably unrelated**, verified non-destructively (I did not stash — the working tree holds a large body of your held Transport/Fees/Entitlements work): those tests read only `application/controllers/Homework.php` (confirmed **unmodified** by `git status`) and Kotlin sources under `D:/Projects/SchoolSync{Parent,Teacher}/` (separate repositories). `BackupTest::size_human` is the documented baseline format-drift failure.
- **Note on the baseline count:** CLAUDE.md documents "4 failures + 27 skipped." This machine shows **10 failures + 0 skipped** because the cross-repo Kotlin paths *exist here*, so the 27 previously-skipped tests now execute rather than skip — 5 of them fail against drifted Kotlin source. This is a pre-existing environment difference, not a regression introduced by Phase 1. Net-new failures from this change: **zero.**

**Runtime verification — NOT YET PERFORMED. This is the one gap in this certification.** See §16.

---

## 12-14. Education / Corporate / Retail isolation results

**Structurally enforced and unit-tested; not yet runtime-verified** (same blocker as §11).

What is proven by construction and by test:

- **Education tenant cannot access Corporate/Retail capabilities:** there are no Corporate/Retail capability keys or code paths in Phase 1 — Timesheet, Projects, Store operations, and Shift Roster were explicitly not implemented (§7 of your directive). There is nothing for an Education tenant to reach.
- **Corporate/Retail tenants cannot reach Education-only capability:** `orgType` is *not* consulted at runtime by any Phase 1 code path. Grep-verifiable: `orgType` is read only by `org_term()`/`org_default_labels()` (label resolution) and written by `save_profile()` — it never gates behavior. Academic-session scoping (`session_year`, `sessions[]`) was left entirely untouched and remains Education-specific, exactly as your correction required.
- **A tenant without `hrms_core` sees no HRMS surface at all:** the Organisation tab is `display:none` until the server says the capability is on; the Location dropdown is absent from both staff forms; `get_config()` returns no `organisation` data and performs no extra read or write; the HR heading shows its original hardcoded `Staff`; and all four new endpoints hard-403 via `require_capability()`.

The three-outcome matrix you specified is **structurally guaranteed** rather than behaviorally executed:

| Capability | RBAC permission | Outcome | Enforced by |
|---|---|---|---|
| disabled | granted | **DENY** | `require_capability()` → 403 |
| enabled | denied | **DENY** | `require_permission()` → 403 |
| enabled | granted | **ALLOW** | both pass |

`HrmsCapabilityGateTest` proves the precondition this matrix rests on: both gates present at every endpoint, in equal number, never nested. Given `require_permission()`'s and `require_capability()`'s independently-established fail-closed behavior, the matrix follows.

---

## 15. Test results

| Test | Proves |
|---|---|
`LocationSchoolIdPredicateTest` (6 tests) | Every `locations` read/write is school-scoped: list via `schoolWhere()`, single-doc ops via `docId()` (≥4 sites), delete's in-use guard scoped to `staff` of the acting school, creates stamp `schoolId`, and single-location reads re-verify the fetched doc's `schoolId` (≥2 sites).
`HrmsCapabilityGateTest` (5 tests) | Both gates at all 3 endpoints, in **equal** count, never nested; `save_terminology()` independently carries both; the helper is `function_exists()`-guarded and fails closed.

Both test classes strip PHP comments via `token_get_all()` before asserting, so they measure **executable code only**. This was a real bug I caught and fixed mid-implementation: my first version's `substr_count` matched the controller's own docblock, which both inflated a count *and* — more dangerously — could let a mere comment satisfy a security assertion.

---

## 16. Known issues

1. **Runtime browser verification not performed — the only real gap.** Every new surface is gated on `hrms_core` being present in `tenantPublic/{schoolId}.activeModules`, and **no tenant has that key today** (it's a new key, and by design absent = disabled). Enabling it means changing tenant entitlement data — either a subscription-plan `modules` change via `B2_registry_service`, or a direct `tenantPublic` write. **That is a production-data change on a live tenant, which your §8/§12 gates and this project's standing rules put outside what I should do unauthorized.** So I verified the "capability disabled" path is invisible/inert (which is the regression-safety half, and the half that protects existing schools), but I have **not** clicked through the enabled path in a browser. I am flagging this rather than implying coverage I did not obtain.
2. **`Tenant_capability_service` uses `$this->firebase->firestoreGet()`**, matching `B2_registry_service`'s own read of this exact collection, rather than `$this->fs`. Deliberate: it must work before/independently of `$this->fs->init()`, which requires an academic session that a non-Education tenant may not have.
3. **IDE language-server noise.** The VS Code PHP extension flags scalar type hints (`string`, `bool`, `void`) as "unknown classes" across these files. This is tooling misconfiguration, not defects — it would flag `rbac_helper.php`'s own long-standing signatures identically. `php -l` is clean on every file.

---

## 17. Risks

| Risk | Severity | Mitigation |
|---|---|---|
Runtime path unexercised (§16.1) | **Medium** — highest open risk in this phase | Enable `hrms_core` on one non-production tenant and walk §10 of your directive. Cannot proceed without your authorization.
`ensure_org_profile()` writes on first capability-enabled read | Low | Only fires when the capability is ON *and* fields are missing; idempotent; mirrors the existing `ensure_unified_roles()` precedent. Note it is a write-on-read, which `School_config::get_config()` deliberately removed once before (BUG-031) for quota/race reasons — bounded here because it self-disables after the first successful seed.
A future HRMS endpoint gets RBAC but not capability | Low | `HrmsCapabilityGateTest`'s equal-count assertion fails the build.
`orgType`/`labels` on `schools` will need lifting if multi-school-per-org ships | Low (deferred, documented) | Documented in-code and in the blueprint as provisional.
Rules block undeployed | Low | No mobile client reads `locations` in Phase 1; PHP bypasses rules. Must deploy before any app-side read.

---

## 18. Exact next dependency for Phase 2

**Phase 2 = payroll engine consolidation, and it is blocked on a prerequisite you yourself specified (§4 of your corrections): the payroll↔accounting chart-of-accounts reconciliation.**

That reconciliation must answer, with evidence, before any Phase 2 code:

- **A.** What `PayrollAccountingService.php`'s own `chartOfAccounts` collection actually contains.
- **B.** What the canonical ZenX Accounting chart of accounts (`accounting/type=chartOfAccounts`, read by `Hr.php`) contains.
- **C.** Whether any **live payroll data** depends on the Payroll-side representation (this is the decisive question — it determines whether consolidation is a code change or a data migration).
- **D.** How journal posting works today, end to end, in both paths.
- **E.** Exactly how the canonical payroll engine will post into the existing ZenX Accounting architecture.

Phase 1 created no dependency of its own on Phase 2 — the two are independent. Nothing in Phase 1 touched payroll, accounting, attendance, or leave.

---

## Explicit confirmations

- ✅ **No separate HRMS created** — no new application, repository, authentication, or tenant model. Everything is inside the existing ZenX platform, using `MY_Controller`, `$this->fs`, existing RBAC, existing session/claims.
- ✅ **No separate tenant system created** — `school_id` remains the sole tenant key; no new claim was added; no re-login required.
- ✅ **No duplicate employee master created** — `staff` remains canonical, extended by exactly one optional field.
- ✅ **No duplicate capability system created** — the existing `tenantPublic.activeModules` is reused; it was given a centralized resolver and its first *enforced* consumer, not a replacement.
- ✅ **No RTDB introduced** — Firestore only. No RTDB read, write, fallback, mirror, or config anywhere in this change set.
- ✅ **No Accounting duplication introduced** — Phase 1 does not touch accounting, payroll, or any chart of accounts.
- ✅ **No destructive migration performed** — zero deletions, zero rewrites, zero mass updates, no migration script. Fully additive and trivially reversible.
- ✅ **No duplicate attendance/leave/payroll engine created** — none of those subsystems were modified at all.
- ✅ **Not committed, not pushed, not merged, not deployed. Rules written but not deployed. Phase 2 not started.**

---

**STOPPED FOR APPROVAL.** The one thing I need from you to close §16.1 and finish §12-14 as *runtime-verified* rather than *structurally-verified*: tell me which tenant (ideally a non-production one) may have `hrms_core` added to its `tenantPublic.activeModules`, and whether I should do that via `B2_registry_service`'s plan mechanism or a direct data write. I will not touch tenant entitlement data until you say so.
