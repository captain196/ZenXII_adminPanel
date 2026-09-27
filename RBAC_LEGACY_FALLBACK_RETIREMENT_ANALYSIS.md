# RBAC Legacy Fallback — Retirement Readiness Analysis

**Date:** 2026-08-07 · **Type:** READ-ONLY architectural verification. No code changed, no migration performed.
**Question:** can the ERP standardize on pure graded RBAC and retire the legacy role-name fallback today?

> ## Verdict: **NO — not today**, but for a narrower reason than first assessed.
> **The graded RBAC *model* is sufficient — no new capability concepts are needed.** What blocks
> retirement is (a) the graded **data** is not yet populated — **11 of 13 roles carry no explicit
> `permissionLevels`**, so every module they hold silently defaults to `manage` — and (b) **55 call
> sites have no graded path in code**, 41 of them inside Transport. Both are mechanical. With **no
> production tenants**, risk is low: a schedulable cleanup, not a dangerous migration.
>
> ### ⚠ THREE CORRECTIONS (2026-08-07, all after operator challenge)
>
> **0 · The ERP baseline has ALREADY retired legacy 2-arg RBAC — the debt is OURS.** The operator
> asked why legacy RBAC keeps showing up when `origin/yug_testing` has retired it. Verified: that
> branch has **1** pure-legacy call ERP-wide (`Notifications.php:148`); our tree has **55**. `Sis`,
> `Hr`, `Fees`, `School_config` are all at **zero** there. **54 of the 55 were introduced by our own
> local work** — 41 in the four Transport sub-controllers, which do not exist in `yug_testing` at
> all. `yug_testing` is fully contained in HEAD (0 behind), so this was never a stale pull; I
> measured our merged tree and mis-attributed its debt to the platform. See §2.1.
>
> **1 · `Operations Manager` is NOT a blocker.** An earlier revision claimed it "cannot be graded".
> Wrong — I conflated the **`Operations` module** with the **`Operations Manager` role label**.
> `Operations` is a real, grantable module already held by four roles, and its umbrella grants
> Transport. See §2.2.
>
> **2 · Custom roles are the WELL-BEHAVED case; the SEEDER is the defect.** A second challenge asked
> whether I had actually studied custom roles. I had not. Doing so inverted the diagnosis: roles
> created through Staff Access → Role Editor **always** receive explicit levels, whereas **20 of the
> 21 shipped `DEFAULT_STAFF_ROLES` carry none** — so every newly-onboarded school starts flat at
> `manage`. See §2.2b. This is the finding that most affects the stated goal of onboarding future
> schools directly onto graded RBAC.

---

## 1. How the fallback actually works (so the risk is judged accurately)

`MY_Controller::_require_role($allowed, $action, $module, $level)` (lines 1200-1216):

1. `Super Admin` / `School Super Admin` → pass unconditionally (`RBAC_BYPASS_ROLES`).
2. If `$module` supplied and `has_permission($module, $level)` → pass.
3. **Graded-authoritative rule:** if the caller *holds* the module but *below* the required level →
   **deny**, and do **not** consult the role-name list.
4. Only if the caller holds the module **not at all** → fall through to the legacy name list.

So the name list is already unreachable for anyone the graded map governs. It is a **migration
bridge for un-migrated identities**, not a parallel authorization system — with two exceptions
documented below (§2.1 and §2.3), which are genuine dependencies.

---

## 2. The five blockers

### 2.1 — 55 pure-legacy call sites — **and 54 of them are OUR code, not the TL's** 🔴

*(Rewritten 2026-08-07 after the operator asked why legacy RBAC keeps appearing when
`origin/yug_testing` has already retired it. **They were right — the baseline is clean; our tree
reintroduced it.**)*

`origin/yug_testing` (tip `f8bac25`, 2026-08-05) is **fully contained in our HEAD** — 0 commits
behind — so this is not a stale-pull problem. It is a comparison problem: I measured our merged tree
and attributed its legacy debt to the ERP.

| Controller | `origin/yug_testing` | Our tree | Origin of the gap |
|---|---|---|---|
| `Sis.php` | **0** | 9 | local pre-RBAC code |
| `Hr.php` | **0** | 2 | local pre-RBAC code |
| `Fees.php` | **0** | 1 | local pre-RBAC code |
| `School_config.php` | **0** | 1 | local pre-RBAC code |
| `Notifications.php` | 1 | 1 | genuinely in the TL baseline |
| `Transport_ops.php` | *file absent* | **18** | **local-only TMS** |
| `Transport_driver.php` | *file absent* | **15** | **local-only TMS** |
| `Transport_vendors.php` | *file absent* | **5** | **local-only TMS** |
| `Transport_rollover.php` | *file absent* | **3** | **local-only TMS** |
| **Total** | **1** | **55** | **54 introduced locally** |

**The TL has already retired legacy 2-arg RBAC across every shared module.** `Sis`, `Hr`, `Fees` and
`School_config` are at **zero** in `yug_testing`. The single survivor there is
`Notifications.php:148` (`dismiss_alert`).

The 54 extra calls are ours:
- **41** from the four Transport sub-controllers, which **do not exist in `yug_testing` at all** —
  F1–F11 was never pushed, so the TL never had the chance to grade them;
- **13** from local pre-RBAC edits to Sis/Hr/Fees/School_config that survived the Phase 1/2 merges
  ungraded.

**Not a regression — checked.** `Fees.php` shows 77 graded in `yug_testing` vs 70 in ours; the 7
missing are all `job_*` endpoints (`jobs_list`, `job_dashboard`, `job_detail`, `job_finalize`,
`job_pause`, `job_resume`, `job_retry_failed`). Those methods are **absent from our `Fees.php`
entirely** — deliberately retired by commit `3f3fc5c` ("retire legacy Fee_generation_engine +
generate_fees + job_dashboard"), not silently downgraded. `Sis` (86 vs 80) and `Transport.php`
(39 vs 14, from F12.1 M4) are *ahead* of the baseline.

**Corrected framing:** the ERP baseline is essentially on graded RBAC already. Retirement is blocked
by **our own unshipped Transport work**, not by the platform.

### 2.2 — `Operations Manager` role label — ✅ **NOT A BLOCKER** (corrected)

**A previous revision marked this a 🔴 blocker, claiming `Operations Manager` "cannot be graded".
That was incorrect.** The mistake was conflating the **`Operations` module** with the
**`Operations Manager` role label**.

Verified against the live catalogue (`staff_access/get_catalogue`, 21 roles):

| Role holding the `Operations` module | Level |
|---|---|
| `ROLE_ADMIN` (Admin) | `manage` (explicit) |
| `ROLE_LIBRARIAN` (Librarian) | `manage` (unleveled default) |
| `ROLE_TRANSPORT_MANAGER` (Transport Manager) | `manage` (unleveled default) |
| `ROLE_HOSTEL_WARDEN` (Hostel Warden) | `manage` (unleveled default) |

`Operations` is a first-class RBAC module and the umbrella parent of Transport / Library / Hostel /
Inventory / Assets, so holding it grants Transport at the same level — **proven over real HTTP in
F12.1 M4** (the `umbrella` persona: `Operations:edit` → allowed on Transport `edit` endpoints,
correctly denied on `manage`).

There is simply no role *labelled* "Operations Manager" in this tenant. That is a **configuration
state, not a capability gap**: an admin creates it in Staff Access → Role Editor and ticks
`Operations`. No code change, no model change. The functional equivalent already exists as
`ROLE_TRANSPORT_MANAGER`.

**Operations access is fully expressible in graded RBAC today.**

*(`Vice Principal`, 79 references, **is** in the catalogue — an earlier partial read suggested
otherwise; corrected.)*

### 2.2b — **THE ROOT BLOCKER: the SEEDER ships roles with no levels** 🔴

*(Deepened 2026-08-07 after a second operator challenge — specifically about **custom roles**. That
challenge changed the diagnosis: custom roles turn out to be the **well-behaved** case, and the
defect is in the shipped defaults.)*

#### The asymmetry

| Path a role can be created by | Gets explicit `permissionLevels`? |
|---|---|
| **Custom role via Staff Access → Role Editor** | ✅ **Yes.** `set_role_level` / `save_role_access` write `$levels[$module] = $level` on every grant; the UI offers `none / view / edit / manage` |
| **Seeded system role via `Staff::DEFAULT_STAFF_ROLES`** | ❌ **No.** Only **1 of 21** defaults (`ROLE_BASELINE_APP`) ships a `permissionLevels` map. `ROLE_ADMIN`, `ROLE_PRINCIPAL`, `ROLE_TEACHER`, `ROLE_TRANSPORT_MANAGER` … define `permissions[]` only |

`ensure_unified_roles()` copies those defaults verbatim and backfills only
`permissions / tier / sort_order / description` — **never `permissionLevels`**.

#### Why this matters for the stated direction

The operator's plan is that **future schools onboard directly onto graded RBAC**. As the code stands
today they would not. A brand-new tenant is seeded with:

- `ROLE_TRANSPORT_MANAGER` → `permissions: ['Transport','Reports']`, **no levels** → resolves
  `Transport: manage`
- `ROLE_TEACHER` → 6 modules, **no levels** → all `manage`
- …and so on for 11 of the seeded roles

An unleveled grant resolves to **`manage`** by design (`rbac_granted_level()`, the deliberate
legacy-equivalent default). So graded RBAC arrives **structurally present but functionally flat** on
every new tenant — not as stale dev data, but as **shipped behaviour**.

Measured on this tenant: **2 roles fully leveled** (`Admin` 26/26 — from `Admin_role_backfill`, not
the seed; `App Baseline` 3/3 — the one seeded exception), **11 with zero levels**, 0 partial. The 3
custom roles here (`House Warden`, `Peon/Attendant`, `Doctor`) hold no modules at all, so they are
consistent — nothing granted, nothing to level.

My own M4 HTTP probe demonstrated the consequence live: a `Librarian` session resolved to
`Operations: manage` and legitimately passed every Transport endpoint.

#### Consequence

**Converting the legacy sites to graded checks before levels exist would WIDEN access, not tighten
it.** `('Attendance','manage')` currently admits every Attendance holder. This is exactly the
escalation `Rbac_levels_backfill` was written to prevent — its own header calls a blanket-manage
backfill *"a privilege-escalation bug"*, and it ships **dry-run by default** with a curated per-role
table requiring human sign-off per tenant.

#### Two fixes, and the second is the architectural one

1. **Run `Rbac_levels_backfill`** (dry-run → sign-off → commit) for every existing tenant.
2. **Add `permissionLevels` to `Staff::DEFAULT_STAFF_ROLES`**, the way `ROLE_BASELINE_APP` already
   does. Without this, *every future school repeats the problem* — the backfill would have to be run
   forever, once per onboarding. This is the change that actually delivers "new schools onboard
   directly onto graded RBAC."

| `permissionLevels` coverage | Roles |
|---|---|
| Fully leveled | **2** — `Admin` (26/26), `App Baseline` (3/3) |
| Partially leveled | 0 |
| **No explicit levels at all** | **11** — Principal, Vice Principal, Academic Coordinator, HR Manager, Accountant, Front Office, Class Teacher, Teacher, Librarian, Transport Manager, Hostel Warden |

An unleveled grant resolves to **`manage`** (`rbac_granted_level()` — the deliberate
legacy-equivalent default). So today, graded RBAC is **structurally present but functionally flat**:
a Teacher holding `Attendance` gets `manage`; a Librarian holding `Operations` gets `Transport:
manage`. My own M4 HTTP probe demonstrated exactly this — a `Librarian` session resolved to
`Operations: manage` and legitimately passed every Transport endpoint.

**Consequence: converting the legacy sites to graded checks *before* levels are populated would
WIDEN access, not tighten it.** `('Attendance','manage')` currently admits every Attendance holder.
That is precisely the escalation `Rbac_levels_backfill` was written to prevent — its own header
warns that a blanket-manage backfill is *"a privilege-escalation bug"* and ships **dry-run by
default** with a curated per-role table requiring human sign-off per tenant.

**`Rbac_levels_backfill` must run — and be signed off — before any conversion work begins.**

### 2.3 — 15 Attendance sites **deliberately** require the name gate 🟠 DESIGN DEPENDENCY
### *(downstream of 2.2b — resolvable once levels exist)*

`Attendance.php` and `Sis.php` override `_require_role` and honour a `'strict'` sentinel:

```php
$strict = ($module === 'strict');
if (!$strict && has_permission('Attendance', $level)) { return; }
// strict → skip the graded shortcut, fall through to the real role allow-list
```

15 call sites use it — attendance settings, attendance policy, device-key rotation, locks, and
correction/regularization decisions. The code comments (BE-C1, 2026-07-18) show this is intentional:
these admin actions must **not** be reachable by a mere module holder.

This is the one place the legacy layer is load-bearing *by design* rather than by inertia — **but
§2.2b explains why it exists, and why it is not a permanent model limitation.** `'strict'` was
introduced because, with every role defaulting to `manage`, the graded map could not distinguish "a
mere module holder" from "a real administrator". Once `Rbac_levels_backfill` assigns explicit levels,
`('Attendance','manage')` expresses exactly the intended rule and the sentinel becomes redundant.

So: **not a new capability concept, a sequencing dependency.** Convert these 15 sites *after* levels
are populated, never before — converting first would grant every Attendance holder access to
settings, policy and device-key rotation.

### 2.4 — Admin FOLD safety net is still armed 🟠 UNVERIFIED

`MY_Controller` (~line 710) still contains:

> `RBAC FOLD SAFETY NET engaged: Admin resolved EMPTY … granting full access. Run Admin_role_backfill.`

Its own comment states it may be removed **"only after `Admin_role_backfill` is verified run for
every tenant."** `Admin_role_backfill.php` exists; I found **no evidence it has been run
fleet-wide**. Until that is confirmed, a demoted/un-backfilled `Admin` depends on this net.

### 2.5 — The legacy `schools.roles` resolver fallback lives in **two** systems 🟠 COORDINATION

`rbac_helper.php:396-407` falls back to the legacy `schools.roles` map when a role misses the
unified catalogue, instrumented with `RBAC_LEGACY_FALLBACK_HIT` and documented as deletable **"ONLY
once this line logs ZERO hits fleet-wide."**

Critically, **`functions/staffCapabilities.js` carries the same fallback** (`legacyRoles`, lines
70/98/158/160), mirroring the PHP resolver for the app-side capability document. Retirement must
therefore be a **coordinated PHP + Cloud Function release**, or the two sides drift — the exact
failure mode `functions/rbac_modules.json` was created to prevent.

Local log evidence: **0** `RBAC_LEGACY_FALLBACK_HIT` entries. That is one dev tenant on rotated
logs, not fleet-wide proof.

---

## 3. What does **not** block retirement (verified clear)

| Surface | Finding |
|---|---|
| **Parent App** | **No authorization dependency on role names.** 0 hits for any operational role label. |
| **Teacher App** | **No authorization dependency.** The single `Transport Manager` hit is UI copy in `SosTriggerScreen.kt` ("Admin, Transport Manager, Principal … will receive this notification") — display text, not a gate. |
| **Firestore Rules** | 13 `token.role in [...]` gates exist, but they cover only bypass roles and parent/student, and are a **separate mechanism** from `_require_role`. Retiring the PHP fallback does not affect them. Worth tracking as a related workstream, not a blocker. |
| **Cloud Function catalogue** | `rbac_modules.json` is already the shared single source for PHP + CF module lists. Sound. |
| **F12.1 compliance endpoints** | All 8 already use the 4-arg graded form; none has a pure-legacy path. |

---

## 4. Answers to the four questions

**Q1 · Any modules still requiring the legacy fallback for correct operation?**
**Yes — two, and neither is a limitation of the graded model.**
(a) **55 pure-legacy call sites** with no graded path in code (41 Transport sub-controllers, 14
elsewhere). (b) **15 Attendance `'strict'` sites**, which depend on the name gate only because
graded levels are unpopulated (§2.2b) — not because the model cannot express them.
**Not a blocker:** `Operations Manager` (§2.2). The `Operations` module is fully gradable and
already held by four roles.

**Q2 · What would break if the fallback were removed today?**
- All **41** Transport sub-controller endpoints (ops, driver, vendors, rollover) → deny everyone
  except Super/School Super Admin.
- The **14** other pure-legacy endpoints (SIS, HR, School_config, Notifications, Fees) → same.
- The **15** Attendance strict endpoints → would **silently widen**, not lock out: with 11/13 roles
  unleveled, `('Attendance','manage')` currently admits every Attendance holder. This is the
  dangerous case, because it fails *open*.
- **Nothing** in the Parent App, Teacher App, Firestore Rules, or the Cloud Functions' module
  catalogue — all verified clear.

**Q3 · Is the Admin Fold process already sufficient to migrate every role?**
**No — and the fold is not the relevant gate.** The fold covers the `Admin` role, and its safety net
is still armed pending fleet-wide `Admin_role_backfill` verification. The real preconditions are
(i) **seeding levels in `DEFAULT_STAFF_ROLES`** so new tenants start graded, and (ii)
**`Rbac_levels_backfill`** for existing ones — 11 of 13 roles hold zero explicit levels today.
Fold ≠ level population.

**Custom roles are not the problem — they are the model working correctly.** Any role configured
through Staff Access → Role Editor gets an explicit level on every module grant. The gap is entirely
in the **shipped seed defaults** (20 of 21 lack `permissionLevels`).

**Q4 · Can the ERP operate entirely on graded RBAC without functional regressions?**
**The model can. The current data cannot.** No new capability concepts are required — `Operations`
proves the umbrella works, and `manage` expresses what `'strict'` protects. What is missing is
(i) explicit per-role levels and (ii) conversion of 55 call sites. Both are mechanical and, with no
production tenants, low-risk. **Retirement is a realistic, schedulable goal — just strictly ordered:
levels first, conversion second, fallback removal last.**

---

## 5. Recommended retirement sequence (proposal only — not authorized, not started)

Ordered so each step is independently verifiable and no step can lock anyone out:

**The ordering is not cosmetic — steps 2-4 are unsafe before step 1.**

| # | Step | Risk | Notes |
|---|---|---|---|
| **0** | **Add `permissionLevels` to `Staff::DEFAULT_STAFF_ROLES`** (20 of 21 defaults lack it) | Low | **Do this first.** Without it every future onboarding re-creates the flat-manage problem and step 1 must be repeated forever. `ROLE_BASELINE_APP` is the existing template |
| **1** | **Run `Rbac_levels_backfill` (dry-run → sign-off → commit) for every existing tenant** | **Medium** | **THE gate for existing data.** 11/13 roles unleveled today. Every conversion below silently widens access until this lands. Tool exists, dry-run by default, curated per-role table |
| 2 | Convert the **41 Transport** pure-legacy sites to the 4-arg graded form | Low | Same change already proven in `Transport.php` (M4); natural Transport follow-up |
| 3 | Convert the remaining **14** sites (SIS, HR, School_config, Notifications, Fees) | Low | Per-module, independently testable |
| 4 | Retire the **15 Attendance `'strict'`** sentinels → `('Attendance','manage')` | Low *(after 1)* | Mechanical once levels exist; **dangerous before** — would admit every Attendance holder |
| 5 | Verify `Admin_role_backfill` fleet-wide, then remove the **fold safety net** | Low | Gated on the evidence its own comment requires |
| 6 | Retire the `schools.roles` resolver fallback in **PHP + Cloud Function together** | Medium | Must ship as one coordinated release, or the two sides drift |
| 7 | Flip all 4-arg `_require_role` → `require_permission`, drop the allow-list arrays | Low | Only safe **after** 1–6; purely cosmetic by then |
| 8 | *(Related, separate)* revisit the 13 `token.role in [...]` Firestore rules gates | Medium | Distinct mechanism; own workstream |
| — | *Optional, anytime:* create an `Operations Manager` role in Staff Access if a school wants that label | None | Pure configuration, self-service |

**Each step is HTTP-verifiable** with the persona harness built in F12.1 M4
(`Transport_probe_httpauth`), which already proves view/edit/manage discrimination, umbrella
inheritance, bypass, and fail-closed denial over real HTTP.

---

## 6. Recommendation for F12.1 specifically

**Leave F12.1 on the 4-arg graded form.** Reasons:

1. It is the pattern the RBAC author used in `Staff_access.php`, the newest controller, written in
   the RBAC Phase A commit itself — and in 873 call sites ERP-wide.
2. Its graded arm is already authoritative; the name list cannot re-grant anyone the graded map
   governs. **Proven over HTTP in M4**: personas whose role name *was* in the allow-list were still
   denied 403 at insufficient level.
3. Converting only F12.1 today would leave the module's **other 41 endpoints on pure legacy** —
   Transport would then run three different authorization patterns at once. That is strictly worse
   than either end state.
4. More importantly, **with levels unpopulated the pure form buys nothing today.** Every role that
   reaches Transport does so at `manage` by default, so `require_permission('Transport','manage')`
   and the current 4-arg call admit exactly the same people. The change would be cosmetic now and
   meaningful only after step 1.
5. Under the recommended sequence F12.1 converts at **step 7**, with everything else, as one
   coordinated change.

**F12.1 does not depend on the fallback as a permanent design.** Its graded arm is already
authoritative — M4 proved over HTTP that a caller holding Transport below the required level is
denied 403 *even when their role name is in the allow-list*. The fallback serves only callers
holding no Transport capability at all, and step 7 removes it.

---

*Read-only verification. No code modified, no migration performed, no commits, no push, no deployment.*
