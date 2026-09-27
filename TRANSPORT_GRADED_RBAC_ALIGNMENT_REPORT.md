# Transport — Graded RBAC Alignment & Legacy Fallback Retirement

**Date:** 2026-08-07
**Branch:** `ank-yug_b1` (working tree — **no commit, no rebase, no release branch, no push, no deployment**)
**Scope:** Transport module only. No shared ERP module was modified.
**Status:** Implementation and validation complete — **held for review**.

---

## 1. What was asked, and what was done

| Requirement | Status |
|---|---|
| Convert all remaining Transport legacy RBAC call sites to the canonical graded pattern | Done — 9 controllers, 126 gates |
| Follow the upstream authorization style; do not invent a Transport-specific model | Done — uses `require_permission()`, an existing upstream helper (48 uses elsewhere) |
| Remove the legacy role-name fallback from Transport | Done — zero role-name authorization remains in Transport |
| Verify every Transport endpoint with the HTTP probe across all supported personas | Done — 125 endpoints × 7 personas, **870 pass / 0 fail** |
| Impact analysis | Done — §5 (17 roles lose access; remediation is a data grant) |
| Runtime validation, regression, verifier, cross-module review | Done — §4 |
| Stop before modifying shared ERP modules | Held — 42 other controllers still use `_require_role`, untouched |

---

## 2. The canonical shape chosen, and why

Every Transport endpoint is now gated by exactly one call:

```php
require_permission('Transport', '<action>', '<view|edit|manage>');
```

Upstream (`origin/yug_testing`) writes Transport gates as the 4-arg bridge
`_require_role(self::VIEW_ROLES, 'action', 'Transport', 'view')`. That form **carries the
role-name allow-list as its fallback argument** — so "keep the upstream style" and "remove the
fallback" cannot both be satisfied by the bridge. Retiring the fallback *inside* `_require_role`
was not an option either: `MY_Controller` is a shared module and 42 non-Transport controllers
depend on that fallback.

`require_permission()` resolves this without inventing anything:

* it is an **existing upstream API** (`application/helpers/rbac_helper.php`), already used at 48
  call sites across the ERP, including Transport's own constructors before this change;
* it is **purely graded** — no role list to pass, so the fallback is structurally absent;
* it leaves `MY_Controller` untouched, honouring the shared-module boundary.

**Behavioural equivalence** was checked against the bridge before adopting it:

| | `_require_role` (4-arg) | `require_permission` |
|---|---|---|
| Super Admin / School Super Admin | explicit `strcasecmp` bypass | `RBAC_BYPASS_ROLES` via `has_permission()` |
| Holder at ≥ required level | allow | allow |
| Holder below required level | deny | deny |
| Holds no module at all | **falls through to role-name list** | **deny** ← the intended change |
| AJAX denial | `json_error(msg, 403)` | `403` + `{status:'error', message, csrf_token}` |
| Non-AJAX denial | `redirect(rbac_denied_url(...))` | identical |

The AJAX denial payload is shape-compatible, so no Transport front-end handler required changes.

---

## 3. Changes made

### 3.1 Controllers — 126 graded gates, three legacy patterns retired

| Controller | Gates | Legacy pattern removed |
|---|---|---|
| `Transport.php` | 41 | 39 × 4-arg bridge + 38 `_require_view()`/`_require_manage()` predicate calls |
| `Transport_ops.php` | 19 | 18 × 2-arg `_require_role` + 18 predicate calls |
| `Transport_driver.php` | 16 | 15 × 2-arg + 15 predicate calls |
| `Transport_vendors.php` | 6 | 5 × 2-arg + 5 predicate calls |
| `Transport_rollover.php` | 4 | 3 × 2-arg + 3 predicate calls |
| `Transport_compliance.php` | 9 | 8 × 4-arg bridge |
| `Transport_trip.php` | 11 | blanket `REQUIRED_ROLES` constructor guard |
| `Transport_event.php` | 12 | blanket `REQUIRED_ROLES` constructor guard |
| `Transport_sos.php` | 4 | blanket `REQUIRED_ROLES` constructor guard |

Deleted outright: `VIEW_ROLES`, `MANAGE_ROLES`, `TRN_VIEW_ROLES`, `TRN_MANAGE_ROLES`,
`OPS_ADMIN_ROLES`, `REQUIRED_ROLES` constants and the `_require_view()` / `_require_manage()`
predicates they backed. A Transport-wide sweep confirms **zero** remaining role-name
authorization; the only surviving textual matches are comments and the verifier's own guard
patterns.

### 3.2 Two behavioural corrections folded in

**(a) Constructor gate widened from `Operations` to `Transport`.** Five Transport controllers
gated their constructor on `require_permission('Operations')`. Because `Transport` is a *child*
of `Operations` in `RBAC_MODULE_CHILDREN`, that gate rejected a role granted `Transport`
**directly** — the module the endpoints actually check. Upstream's `Transport.php:38` already
uses `require_permission('Transport')`; all five now match it. This is strictly more correct and
strictly more permissive only for direct-Transport grantees.

**(b) `trip` / `event` / `sos` moved from blanket to graded.** Their guard was
`['Admin', 'Super Admin', 'Transport Manager']` applied identically to reads and writes — and it
**omitted `School Super Admin`**, a bypass role everywhere else in the ERP. Each controller now
gates at `Transport:view` in the constructor, with per-endpoint `edit` on mutations and `manage`
on the irreversible ones (`trip/cancel`, `event/cancel`, `sos/resolve`), matching the level
convention already used by `void_expense` and `terminate_vendor`.

### 3.3 Verifier

* `CH-TRN-CMP-4` updated — it pinned the now-retired 4-arg regex and would have failed. It now
  asserts the `require_permission` form **and** that neither the bridge nor an allow-list constant
  has returned.
* `CH-TRN-RBAC-1` added — a new module-wide gate that fails if **any** Transport controller
  reintroduces `_require_role`, a role-name allow-list, or an inline `in_array($this->admin_role, …)`
  test. Documented exclusions: `Transport_analytics` (see §6) and `Transport_sos`'s
  `triggered_by_role` audit field, which is written but never read for an access decision.

### 3.4 Views

Twelve Transport view files carried comments describing the retired
`Transport::_require_role(...)` contract. Comments only — every view already gated its UI on
`has_permission('Transport', 'edit'|'manage')`, and the sidebar already uses `$can('Transport')`.
No view logic changed.

---

## 4. Validation

| Suite | Result |
|---|---|
| `php -l` on all 10 touched controllers | clean |
| **HTTP authorization probe** — 125 endpoints × 7 personas, real curl | **870 pass / 0 fail / 6 skipped** |
| Transport verifier (111 gates) | 90 PASS / 15 INFO / 1 WARN / **5 FAIL** — all 5 pre-existing runtime *data* failures, unchanged from the M5 baseline |
| Transport probe suite (36 probes) | **172 pass / 2 fail** — both pre-existing, in `transport_probe_f10` |

### 4.1 HTTP probe — per persona

| Persona | Role label | Holds | Pass | Fail |
|---|---|---|---|---|
| manage | `ZZ_PROBE_ROLE` (synthetic) | `Transport:manage` | 123 | 0 |
| edit | `ZZ_PROBE_ROLE` | `Transport:edit` | 125 | 0 |
| view | `ZZ_PROBE_ROLE` | `Transport:view` | 125 | 0 |
| umbrella | `ZZ_PROBE_ROLE` | `Operations:edit` | 125 | 0 |
| none | `ZZ_PROBE_ROLE` | nothing | 125 | 0 |
| **legacy** | **`Principal`** (real catalogue role) | nothing | **123** | **0** |
| ssa | `School Super Admin` | bypass | 123 | 0 |
| anon | no cookie | — | HTTP 401 | — |

The **`legacy` persona is the retirement regression guard**. `Principal` sat in the `MANAGE_ROLES`
allow-list of every Transport controller but holds neither `Transport` nor `Operations` in this
tenant. Its map is deliberately left empty so `MY_Controller` resolves Principal's *real* grants
from Firestore — exercising the live resolver end-to-end. Under the old fallback it reached every
endpoint by name; it is now denied on all 123.

The probe **derives its endpoint table by scanning the controllers** rather than hard-coding it,
so a newly added endpoint is covered automatically and the list cannot drift.

### 4.2 Coverage gap, stated explicitly

Two endpoints take no input and mutate other modules on entry, so no invalid payload reaches the
gate without also triggering the side effect. Their **allow path is not exercised** (deny path is,
across every under-privileged persona):

* `transport_ops/reconcile_pending_posts` — posts expenses to Accounting
* `transport/reconcile_pending_fees` — creates fee demands

This is the `skipped=6` in the probe summary and is printed in its output rather than silently
dropped.

### 4.3 Pre-existing failures (not caused by this change)

* **Verifier, 5 FAIL** — `CH-TRN-F1-3`, `F2-2`, `F3-2`, `F5-3`, `F7-3`. All runtime *data* integrity
  (broken vehicle→vendor FKs, orphan stops, stale pending posts, orphan attendance) originating in
  earlier probe residue in this tenant. Identical to the M5 baseline.
* **`transport_probe_f10`, 2 FAIL** — "mute NOT honored" and "rule fragments missing". That probe
  asserts against `Fee_lifecycle.php` and `firebase-rules/firestore.rules`, references **zero** of
  the controllers changed here, and never mentions RBAC.

---

## 5. Access impact — 17 roles lose Transport access

Measured against live tenant `SCH_D94FE8F7AD` (21 catalogue roles):

| Keeps access | How |
|---|---|
| `Admin` | holds `Transport` **and** `Operations` @ manage |
| `Transport Manager` | holds `Operations` @ manage (umbrella → Transport) |
| `Librarian` | holds `Operations` @ manage (umbrella → Transport) |
| `Hostel Warden` | holds `Operations` @ manage (umbrella → Transport) |
| `Super Admin`, `School Super Admin` | `RBAC_BYPASS_ROLES` (not tenant roles) |

**Loses access (17):** `Principal`, `Vice Principal`, `Accountant`, `Academic Coordinator`,
`Class Teacher`, `Teacher`, `HR Manager`, `Doctor`, `Security`, `House Warden`, `Clerk`,
`App Baseline`, `Staff`, `Lab Assistant`, `Peon/Attendant`, `Driver`, `Front Office`.

**This is the intended effect of retiring the fallback, not a defect** — those roles previously
reached Transport by role *name* while holding no Transport capability. **The remediation is a data
grant, not code:** add `Transport` (at the appropriate level) or `Operations` to the roles that
should retain access, via Staff Access. `Principal` and `Vice Principal` are the two most likely to
need it.

**Two observations worth your attention** (both pre-existing, neither introduced here):

1. **`Librarian` and `Hostel Warden` hold `Transport:manage`** through the `Operations` umbrella —
   a Librarian can delete vehicles. The umbrella grant is coarse.
2. **20 of 21 seeded default roles ship no `permissionLevels`**, so a granted module resolves to
   `manage` by default. Every newly-onboarded school starts flat at `manage`. This is the
   `Staff::DEFAULT_STAFF_ROLES` seeder gap recorded in the earlier analysis; it is a shared-module
   fix and therefore **out of scope here**, awaiting your direction.

---

## 6. Cross-module review

| Surface | Finding |
|---|---|
| **Parent app** (`SchoolSyncParent`) | No impact. The only matches are a Compose nav route (`transport_events`) and a Firestore collection name. No HTTP call to any Transport endpoint. |
| **Teacher app** (`SchoolSyncTeacher`) | No impact. Same — nav routes `transport_trip_detail`, `transport_sos`. No HTTP calls. |
| **Firestore rules / indexes** | No change required. This is server-side PHP authorization only; no collection, document shape, query, or index changed. The 13 `token.role in [...]` rule gates are a separate client-side mechanism on non-Transport collections. |
| **Admin views / JS** | Already graded (`has_permission('Transport', …)`). Sidebar already `$can('Transport')`. Comments corrected only. |
| **Cron / CLI jobs** | `Transport_activity_cron`, `Transport_expiry_cron`, `Transport_analytics_rollup_job`, `Transport_fleet_rollup_job`, `Transport_expense_reconciler` are CLI-gated (`defined('STDIN')`) and carry no HTTP RBAC surface — correctly excluded. |
| **`Transport_analytics`** | **Deliberately unchanged.** It gates on `has_permission('Operations')` plus the F11 per-role widget-visibility matrix. Verifier gates `CH-TRN-SEC3-1` and `CH-TRN-F11-4` pin that design; the matrix is presentation scoping, not the authorization boundary. Changing it was out of scope. |
| **Shared modules** | Untouched, per instruction. 42 non-Transport controllers still use `_require_role` with its fallback — that migration is a separate, larger task. |

---

## 7. Incident to disclose — one unintended mutation during probing

The **first** probe run used `Transport Manager` as the "legacy" persona. That label *is* in the
tenant catalogue, so `MY_Controller` re-resolved it from Firestore (it genuinely holds
`Operations:manage`), overwrote the seeded empty map, and granted access. Two consequences:

1. The run reported **125 false failures** — a probe defect, not an authorization defect. This is
   the exact trap the probe's own comment block warns about; I walked into it.
2. Because that persona was wrongly computed as "denied", the no-execute skip did not apply and
   **`transport_ops/reconcile_pending_posts` actually ran**: `retried: 3, posted: 0, failed: 1`.
   It retried the three stale pending expense posts (`TEXP000011`, `TEXP000014`, `TEXP000017`) that
   verifier gate `CH-TRN-F5-3` was already flagging for reconciliation. Benign in effect — it is the
   operation the verifier asks for — but it was **not** an intended mutation and is disclosed here.

Both are fixed: the persona is now `Principal` (a catalogue role that holds nothing), and a
no-execute endpoint is now only ever sent when the persona is synthetic **and** expected-denied, so
a mis-resolved persona can never execute one again. The corrected run is the 870/0 result in §4.

---

## 8. Files changed

**Controllers (10)**
`Transport.php`, `Transport_ops.php`, `Transport_driver.php`, `Transport_vendors.php`,
`Transport_rollover.php`, `Transport_compliance.php`, `Transport_trip.php`, `Transport_event.php`,
`Transport_sos.php`, `Transport_verifier.php`

**Probe (1)** `Transport_probe_httpauth.php` — widened from 8 compliance endpoints to all 125,
scan-derived coverage, `session_year` seeding added (trip/event/sos read a different session key
than `MY_Controller`; without it those three exit before authorization and the probe measures
nothing).

**Views (12)** `transport/index.php` + 11 partials — comments only.

**Awaiting your decision (not part of the module)**
* `application/controllers/Rbac_levels_inspect.php` — read-only diagnostic, dumps raw
  `schools/{id}.staffRoles`.
* `application/controllers/Rbac_transport_impact.php` — read-only diagnostic, prints the §5 table.
  Useful to re-run after granting roles, to confirm the remediation. Both are CLI-only, perform no
  writes, and can be deleted on request.
* 114 orphaned `vehicleCompliance` rows across `VH0106`–`VH0111` (earlier probe residue, previously
  reported) — still in place; cleanup is an operator decision.

---

## 9. Release constraints — honoured

No commits. No rebases. No release branch. No push. No deployment. All work is in the
`ank-yug_b1` working tree.
