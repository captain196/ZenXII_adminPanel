# Transport Management System — Final Production Certification

**Date:** 2026-08-07
**Branch:** `integration/phase2` @ `758c924` (working tree — uncommitted)
**Tenant under test:** `SCH_D94FE8F7AD`, session `2026-27`
**Scope:** Transport module close-out. No shared ERP module modified.

> **Release constraints honoured:** No commits. No rebases. No release branch. No push. No deployment.

---

## 1. Certification summary

| Area | Result | Verdict |
|---|---|---|
| Graded RBAC migration | 125 endpoints, 126 gates, zero legacy fallback | **PASS** |
| Endpoint permission matrix | 46 view / 52 edit / 27 manage | **COMPLETE** (§2) |
| Transport functionality | 36 runtime probes — 172 pass / 2 fail (both pre-existing, non-Transport) | **PASS** |
| Firestore-only compliance | Zero RTDB API usage in Transport source | **PASS** |
| Multi-tenant isolation | 101 school-scoped reads, **zero** raw `query()` | **PASS** |
| Cross-module integrations | Fees, Accounting, Communication, Parent App, Teacher App | **PASS** (§3.4) |
| Verifier suite | 111 gates — 90 PASS / 15 INFO / 1 WARN / 5 FAIL (all 5 = tenant data artifacts) | **PASS** |
| HTTP authorization probes | 125 endpoints × 7 personas — **870 pass / 0 fail** | **PASS** |
| Legacy Transport RBAC code | **None remains** (§4) | **CONFIRMED** |

**Overall: PRODUCTION READY**, subject to the one operator action in §10.

---

## 2. Final Transport endpoint permission matrix

Every Transport HTTP endpoint and its required graded permission. The gate is always:

```php
require_permission('Transport', '<action key>', '<view|edit|manage>');
```

Access is granted by holding the `Transport` module — directly, or inherited from its
`Operations` umbrella parent via `RBAC_MODULE_CHILDREN` — at or above the required level, or by a
`RBAC_BYPASS_ROLES` principal (`Super Admin`, `School Super Admin`). There is no role-name path.

**Level convention:** `view` = reads. `edit` = ordinary mutations. `manage` = destructive or
irreversible actions (delete, void, terminate, cancel, resolve, rollover, cache rebuild).

*`Transport_analytics` is excluded by design — see §3.4. Cron/CLI jobs
(`Transport_activity_cron`, `Transport_expiry_cron`, `Transport_analytics_rollup_job`,
`Transport_fleet_rollup_job`, `Transport_expense_reconciler`) carry no HTTP surface; they are
gated by `defined('STDIN')`.*

### `Transport` — 39 endpoints

| Endpoint | Verb | Required | Action key |
|---|---|---|---|
| `transport/index` | GET | **view** | `transport_view` |
| `transport/get_vehicles` | GET | **view** | `transport_view` |
| `transport/save_vehicle` | POST | **edit** | `save_vehicle` |
| `transport/delete_vehicle` | POST | **manage** | `delete_vehicle` |
| `transport/transition_vehicle_lifecycle` | POST | **manage** | `transition_vehicle_lifecycle` |
| `transport/get_lifecycle_log` | GET | **view** | `transport_view` |
| `transport/upload_vehicle_document` | POST | **edit** | `upload_vehicle_document` |
| `transport/delete_vehicle_document` | POST | **manage** | `delete_vehicle_document` |
| `transport/get_vehicle_documents` | GET | **view** | `transport_view` |
| `transport/get_expiring_documents` | GET | **view** | `transport_view` |
| `transport/record_replacement` | POST | **manage** | `record_replacement` |
| `transport/transfer_vehicle_between_routes` | POST | **edit** | `transfer_vehicle_between_routes` |
| `transport/get_routes` | GET | **view** | `transport_view` |
| `transport/save_route` | POST | **edit** | `save_route` |
| `transport/delete_route` | POST | **manage** | `delete_route` |
| `transport/unarchive_route` | POST | **manage** | `unarchive_route` |
| `transport/get_stops` | GET | **view** | `transport_view` |
| `transport/save_stop` | POST | **edit** | `save_stop` |
| `transport/delete_stop` | POST | **manage** | `delete_stop` |
| `transport/suspend_route` | POST | **edit** | `suspend_route` |
| `transport/resume_route` | POST | **edit** | `resume_route` |
| `transport/get_active_suspensions` | GET | **view** | `transport_view` |
| `transport/get_route_suspensions` | GET | **view** | `transport_view` |
| `transport/get_suspension_config` | GET | **view** | `transport_view` |
| `transport/preview_suspension_notify` | GET | **view** | `transport_view` |
| `transport/get_assignments` | GET | **view** | `transport_view` |
| `transport/save_assignment` | POST | **edit** | `save_assignment` |
| `transport/delete_assignment` | POST | **manage** | `delete_assignment` |
| `transport/suspend_assignment` | POST | **edit** | `suspend_assignment` |
| `transport/resume_assignment` | POST | **edit** | `resume_assignment` |
| `transport/change_stop` | POST | **edit** | `change_stop` |
| `transport/change_route` | POST | **edit** | `change_route` |
| `transport/change_subscription` | POST | **edit** | `change_subscription` |
| `transport/bulk_assign` | POST | **edit** | `bulk_assign` |
| `transport/get_assignment_history` | GET | **view** | `transport_view` |
| `transport/get_student_assignment` | GET | **view** | `transport_view` |
| `transport/reconcile_pending_fees` | POST | **manage** | `reconcile_pending_fees` |
| `transport/rebuild_capacity_cache` | POST | **manage** | `rebuild_capacity_cache` |
| `transport/search_students` | GET | **view** | `transport_view` |

### `Transport_compliance` — 8 endpoints

| Endpoint | Verb | Required | Action key |
|---|---|---|---|
| `transport_compliance/dashboard` | GET | **view** | `compliance_dashboard` |
| `transport_compliance/vehicle` | GET | **view** | `compliance_vehicle` |
| `transport_compliance/attest` | POST | **edit** | `compliance_attest` |
| `transport_compliance/evaluate` | POST | **edit** | `compliance_evaluate` |
| `transport_compliance/waive` | POST | **manage** | `compliance_waive` |
| `transport_compliance/rebuild` | POST | **manage** | `compliance_rebuild` |
| `transport_compliance/seed_pack` | POST | **manage** | `compliance_seed_pack` |
| `transport_compliance/rules` | POST | **manage** | `compliance_rules` |

### `Transport_driver` — 15 endpoints

| Endpoint | Verb | Required | Action key |
|---|---|---|---|
| `transport_driver/list` | GET | **view** | `transport_view` |
| `transport_driver/get` | GET | **view** | `transport_view` |
| `transport_driver/save_meta` | POST | **edit** | `save_driver_meta` |
| `transport_driver/replace_permanently` | POST | **manage** | `replace_driver` |
| `transport_driver/record_incident` | POST | **edit** | `record_incident` |
| `transport_driver/resolve_incident` | POST | **edit** | `resolve_incident` |
| `transport_driver/list_incidents` | GET | **view** | `transport_view` |
| `transport_driver/save_review` | POST | **edit** | `save_review` |
| `transport_driver/list_reviews` | GET | **view** | `transport_view` |
| `transport_driver/activity_snapshot` | GET | **view** | `transport_view` |
| `transport_driver/assign_temp` | POST | **edit** | `assign_temp` |
| `transport_driver/resolve_temp` | POST | **edit** | `resolve_temp` |
| `transport_driver/list_temp` | GET | **view** | `transport_view` |
| `transport_driver/effective` | GET | **view** | `transport_view` |
| `transport_driver/rebuild_activity` | POST | **manage** | `rebuild_activity` |

### `Transport_event` — 17 endpoints

| Endpoint | Verb | Required | Action key |
|---|---|---|---|
| `transport_event/create` | POST | **edit** | `event_create` |
| `transport_event/update` | POST | **edit** | `event_update` |
| `transport_event/assign_student` | POST | **edit** | `event_assign_student` |
| `transport_event/unassign_student` | POST | **edit** | `event_unassign_student` |
| `transport_event/update_consent` | POST | **edit** | `event_update_consent` |
| `transport_event/add_escort` | POST | **edit** | `event_add_escort` |
| `transport_event/remove_escort` | POST | **edit** | `event_remove_escort` |
| `transport_event/schedule` | POST | **edit** | `event_schedule` |
| `transport_event/dispatch_trip` | POST | **edit** | `event_dispatch_trip` |
| `transport_event/complete` | POST | **edit** | `event_complete` |
| `transport_event/cancel` | POST | **manage** | `event_cancel` |
| `transport_event/list_all` | GET | **view** | `(constructor gate)` |
| `transport_event/get` | GET | **view** | `(constructor gate)` |
| `transport_event/roster` | GET | **view** | `(constructor gate)` |
| `transport_event/trips` | GET | **view** | `(constructor gate)` |
| `transport_event/escorts` | GET | **view** | `(constructor gate)` |
| `transport_event/cost_summary` | GET | **view** | `(constructor gate)` |

### `Transport_ops` — 18 endpoints

| Endpoint | Verb | Required | Action key |
|---|---|---|---|
| `transport_ops/get_maintenance_logs` | GET | **view** | `transport_view` |
| `transport_ops/save_maintenance_log` | POST | **edit** | `save_maintenance_log` |
| `transport_ops/get_fuel_logs` | GET | **view** | `transport_view` |
| `transport_ops/save_fuel_log` | POST | **edit** | `save_fuel_log` |
| `transport_ops/get_expiry_dashboard` | GET | **view** | `transport_view` |
| `transport_ops/recompute_expiry_dashboard` | POST | **manage** | `recompute_expiry_dashboard` |
| `transport_ops/get_expenses` | GET | **view** | `transport_view` |
| `transport_ops/get_expense` | GET | **view** | `transport_view` |
| `transport_ops/save_expense` | POST | **edit** | `save_expense` |
| `transport_ops/void_expense` | POST | **manage** | `void_expense` |
| `transport_ops/create_expense_from_maintenance` | POST | **edit** | `create_expense_from_maintenance` |
| `transport_ops/create_expense_from_fuel` | POST | **edit** | `create_expense_from_fuel` |
| `transport_ops/reconcile_pending_posts` | POST | **manage** | `reconcile_pending_posts` |
| `transport_ops/retry_expense_post` | POST | **edit** | `retry_expense_post` |
| `transport_ops/post_expense_now` | POST | **edit** | `post_expense_now` |
| `transport_ops/get_expense_categories` | GET | **view** | `transport_view` |
| `transport_ops/get_expense_summary` | GET | **view** | `transport_view` |
| `transport_ops/rebuild_expense_summary` | POST | **manage** | `rebuild_expense_summary` |

### `Transport_rollover` — 4 endpoints

| Endpoint | Verb | Required | Action key |
|---|---|---|---|
| `transport_rollover/start` | POST | **manage** | `transport_rollover_start` |
| `transport_rollover/continue_rollover` | POST | **manage** | `transport_rollover_start (delegates to start())` |
| `transport_rollover/status` | GET | **view** | `transport_view` |
| `transport_rollover/reset` | POST | **manage** | `transport_rollover_reset` |

### `Transport_sos` — 5 endpoints

| Endpoint | Verb | Required | Action key |
|---|---|---|---|
| `transport_sos/raise` | POST | **edit** | `sos_raise` |
| `transport_sos/respond` | POST | **edit** | `sos_respond` |
| `transport_sos/resolve` | POST | **manage** | `sos_resolve` |
| `transport_sos/list_all` | GET | **view** | `(constructor gate)` |
| `transport_sos/get` | GET | **view** | `(constructor gate)` |

### `Transport_trip` — 14 endpoints

| Endpoint | Verb | Required | Action key |
|---|---|---|---|
| `transport_trip/create` | POST | **edit** | `trip_create` |
| `transport_trip/start` | POST | **edit** | `trip_start` |
| `transport_trip/checkpoint_reached` | POST | **edit** | `trip_checkpoint_reached` |
| `transport_trip/checkpoint_left` | POST | **edit** | `trip_checkpoint_left` |
| `transport_trip/checkpoint_skipped` | POST | **edit** | `trip_checkpoint_skipped` |
| `transport_trip/board_student` | POST | **edit** | `trip_board_student` |
| `transport_trip/drop_student` | POST | **edit** | `trip_drop_student` |
| `transport_trip/no_show_student` | POST | **edit** | `trip_no_show_student` |
| `transport_trip/complete` | POST | **edit** | `trip_complete` |
| `transport_trip/cancel` | POST | **manage** | `trip_cancel` |
| `transport_trip/list_all` | GET | **view** | `(constructor gate)` |
| `transport_trip/get` | GET | **view** | `(constructor gate)` |
| `transport_trip/roster` | GET | **view** | `(constructor gate)` |
| `transport_trip/events` | GET | **view** | `(constructor gate)` |

### `Transport_vendors` — 5 endpoints

| Endpoint | Verb | Required | Action key |
|---|---|---|---|
| `transport_vendors/get_vendors` | GET | **view** | `transport_view` |
| `transport_vendors/get_active_vendors` | GET | **view** | `transport_view` |
| `transport_vendors/save_vendor` | POST | **edit** | `save_vendor` |
| `transport_vendors/terminate_vendor` | POST | **manage** | `terminate_vendor` |
| `transport_vendors/link_vendor_to_vehicle` | POST | **edit** | `link_vendor_to_vehicle` |

**Totals — 125 endpoints:** view=46, edit=52, manage=27

---

## 3. Module-wide production verification

### 3.1 Transport functionality — runtime probes

36 probe controllers executed against the live tenant: **172 assertions passed, 2 failed.**

Both failures are in `transport_probe_f10` (`mute NOT honored`; `rule fragments missing`). That
probe asserts against `Fee_lifecycle.php` and `firebase-rules/firestore.rules`, references **zero**
Transport controllers, and never mentions RBAC — they are pre-existing and outside this module's
change surface. Every other probe returned `rc=0`.

Coverage spans fleet, routes/stops, assignments, drivers, vendors, maintenance/fuel, expenses and
accounting posting, expiry, trips, events, SOS, notifications and preferences, analytics, rollover,
capacity, CAS concurrency (`con1`–`con7`), financial integrity (`fin1`–`fin5`), performance
(`perf3`–`perf11`), envelope discipline, docId convention, and tenant isolation (`sec5`).

### 3.2 Firestore-only compliance

Source scan of all Transport controllers and libraries for `getReference(`, `->rtdb`,
`realtime database`, `databaseURL`, `Dual_write`, `firebaseio.com`:

**Zero occurrences.** The only textual matches in the module are inside
`Transport_verifier::_chTrn5` — the detector that enforces this rule (gate `CH-TRN-5`, PASS).
The `Dual_write` RTDB engine was retired in `758c924`. Transport is Firestore-only across all
three surfaces.

### 3.3 Multi-tenant isolation

| Library | School-scoped calls | Raw `query()` |
|---|---|---|
| `Transport_service.php` | 73 | **0** |
| `Transport_analytics_service.php` | 17 | **0** |
| `Transport_compliance_service.php` | 5 | **0** |
| `Transport_notifier.php` | 6 | **0** |

Every read goes through `schoolWhere` / `querySchool` / `querySchoolSession`. No unscoped `query()`
exists anywhere in the module — the single most repeated bug class in this codebase is absent here.

Reinforced by passing gates:
* `CH-TRN-SEC5-1` — cross-tenant KPI restores the Firestore singleton in `finally` and asserts ready (no cross-tenant bleed)
* `CH-TRN-PERF10-1` — analytics memo keys are schoolId-prefixed (tenant-isolated cache)
* `CH-TRN-PERF6-1` — cross-tenant rollup isolates per tenant; a failing tenant is logged and skipped
* `CH-TRN-PERF3-1` — live-ops joins are school-scoped before the bare-id join

### 3.4 Cross-module integrations

| Module | Integration surface | Status |
|---|---|---|
| **Fees** | `Fee_lifecycle` via `createModuleFee` (9), `proRateFees` (5), `cancelSessionDemands` (3), `init` (6). Gates `CH-TRN-FIN1-1` (paid demands preserved on create), `CH-TRN-FIN2-1` (case-insensitive category match + paid-skip on rollover cancel), `CH-TRN-CON4-1` (fee failure parks `pending` and is recoverable). | **PASS** |
| **Accounting** | `postExpense` (9), `postReversal` (5). Gates `CH-TRN-FIN3-1` (posted expense financial fields immutable; void reverses via `postReversal`, aborts if unreversible), `CH-TRN-FIN5-1` (pre-validates ledger + counter accounts → bad account parks `pending`, not an uncatchable halt), `CH-TRN-NF1-1` (**Transport is completely off `Operations_accounting`** — the only two remaining mentions are comments). | **PASS** |
| **Communication** | All notices route through `Transport_notifier` — `notify()` (6), `_fanoutNotifications`, `_fanoutOps`, `_fanoutParentsOfRoute` / `OfStudents` / `OfVehicle`. Gates `CH-TRN-CON7-1` (notice claim is CAS-atomic before fanout, with release-on-failure), `CH-TRN-LOG3-1` (idempotency salient set includes `event_id`/`trip_id`/`student_id`), `CH-TRN-RCP-1` (recipients resolved via the single canonical `userId` resolver; no legacy parent-id reads), `CH-TRN-OPSN-1` (canonical `timestamp`+`timestampMs` alongside `sentAt` — one notice contract for admin bell and app feeds). | **PASS** |
| **Parent App** | Reads `routes`, `transportStops`, `studentRoutes`, `transportEvents`, `sosAlerts`, `vehicles` directly from Firestore. **No HTTP call to any Transport endpoint** — the only source matches are a Compose nav route (`transport_events`) and a collection-name constant. Unaffected by the RBAC change. | **NO IMPACT** |
| **Teacher App** | Reads `tripLogs`, `transportAttendance`, `vehicles`, `sosAlerts`. **No HTTP call to any Transport endpoint** — matches are nav routes `transport_trip_detail`, `transport_sos`. Gate `CH-TRN-XMOD1-1` confirms `startTrip` sets and `completeTrip`/`cancelTrip` clear `vehicles.currentRouteId`, so the Teacher app's route context stays populated. | **NO IMPACT** |

**`Transport_analytics` is deliberately excluded from the graded migration.** It gates on
`has_permission('Operations')` plus the F11 per-role widget-visibility matrix. That matrix is
*presentation scoping* — which of 14 widgets an 11-role set sees — not the authorization boundary,
and it is pinned by gates `CH-TRN-SEC3-1` and `CH-TRN-F11-4`. Changing it was out of scope and
would have broken both gates.

### 3.5 Verifier suite

111 gates: **90 PASS · 15 INFO (trivial/empty-collection) · 1 WARN · 5 FAIL.**

All 5 FAIL are **runtime tenant-data** integrity checks, not source or authorization — see §9.
Every source-category gate passes, including the two touched by this work:

* `CH-TRN-RBAC-1` *(new)* — "no legacy role-name authorization anywhere in Transport — every endpoint gated by `require_permission('Transport', <action>, <level>)` alone"
* `CH-TRN-CMP-4` *(updated)* — "evidence linked via Document Service API only; all 8 endpoints graded; no service-layer authz"

### 3.6 HTTP authorization probes

125 endpoints × 7 personas over real curl requests with session cookies, AJAX headers and CSRF
tokens — **870 pass / 0 fail / 6 skipped.**

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

The **`legacy` persona is the retirement regression guard.** `Principal` sat in the `MANAGE_ROLES`
allow-list of every Transport controller but holds neither `Transport` nor `Operations`. Its map is
deliberately left empty so `MY_Controller` resolves Principal's *real* grants from Firestore,
exercising the live resolver end-to-end. Under the old fallback it reached every endpoint by name;
it is now denied on all 123.

The probe **derives its endpoint table by scanning the controllers**, so a newly added endpoint is
covered automatically and the list cannot drift out of date.

**Declared coverage gap (the `skipped=6`):** two endpoints take no input and mutate other modules on
entry, so no invalid payload reaches the gate without also triggering the side effect. Their
*allow* path is not exercised; their *deny* path is, across every under-privileged persona:
`transport_ops/reconcile_pending_posts` (posts to Accounting) and
`transport/reconcile_pending_fees` (creates fee demands). The skip is printed in the probe's own
summary rather than silently dropped.

---

## 4. Confirmation — no legacy Transport RBAC code remains

Source scan across **all** Transport controllers and libraries for `_require_role(`, `VIEW_ROLES`,
`MANAGE_ROLES`, `REQUIRED_ROLES`, and `in_array(… admin_role …)`, excluding comments:

**Zero matches.** Deleted outright: the `VIEW_ROLES` / `MANAGE_ROLES` / `TRN_VIEW_ROLES` /
`TRN_MANAGE_ROLES` / `OPS_ADMIN_ROLES` / `REQUIRED_ROLES` constants and the `_require_view()` /
`_require_manage()` predicates they backed.

Two intentional, documented survivors — neither is an access decision:
* `Transport_sos` writes `triggered_by_role` as an **audit field** (written, never read for authorization).
* `Transport_verifier` contains the strings as **detector patterns** in gate `CH-TRN-RBAC-1`.

This is now enforced, not just observed: `CH-TRN-RBAC-1` fails the build if any Transport
controller reintroduces the bridge, an allow-list constant, or an inline role-name test.

---

## 5. Completed features

| # | Feature | Surface |
|---|---|---|
| Phase 0 | Foundation — Firestore-native service layer, numbering, CAS primitive (`casUpdateEntity`), docId convention (RC-3), query fallback + composite indexes (RC-2) | `Transport_service` |
| F1 | Fleet / vehicles — lifecycle, documents, expiry dashboard, replacement, vendor FK | `Transport`, `Transport_ops` |
| F2 | Routes & stops — CRUD, archive/unarchive, suspend/resume with parent notification | `Transport` |
| F3 | Student assignments — assign, bulk assign, change stop/route/subscription, suspend/resume, history, capacity reservation | `Transport` |
| F4 | Drivers — identity/meta, permanent replacement, incidents, reviews, temporary assignment, activity rollup | `Transport_driver` |
| F5 | Expenses — maintenance/fuel logs, expense CRUD, void with reversal, accounting posting, reconciliation, monthly summary | `Transport_ops` |
| F6 | Notifications — CAS-claimed notice dispatch, idempotency, canonical recipient resolution | `Transport_notifier` |
| F7 | Trips / live ops — create, start, checkpoints, board/drop/no-show, complete, cancel, single-scan onboard | `Transport_trip` |
| F8 | Events & excursions — scheduling, roster, consent, escorts (HR-active gated), trip dispatch, cost summary | `Transport_event` |
| F9 | SOS — raise, respond, resolve, driver-scoped Firestore rules | `Transport_sos` |
| F10 | Notification preferences — prefs-honouring fanout, safety-mute protection | `Transport_notifier` |
| F11 | Analytics + Super Admin — 14 widgets, per-role visibility matrix, nightly fleet rollup, cross-tenant leaderboard | `Transport_analytics` |
| F12.1 | CBSE safety compliance — 19-rule pack (weight 144, 8 dispatch-blocking), evaluation engine, posture tiers, attestation, bounded waivers, evidence linked via the Canonical Document Service | `Transport_compliance` |
| — | Session rollover — checkpointed, resumable, deterministic history ids, fee-demand cancellation | `Transport_rollover` |
| — | Vendors — CRUD, termination, vehicle linkage | `Transport_vendors` |
| — | **Graded RBAC alignment** — 125 endpoints migrated, legacy fallback retired | module-wide |

---

## 6. Resolved findings

| Finding | Resolution |
|---|---|
| 43 stabilization-sprint findings | All resolved; 94 verifier gates + 199/199 rules tests (sprint closed 2026-08-04) |
| Legacy role-name RBAC across 9 Transport controllers | Retired — 125 endpoints on pure graded RBAC |
| Constructor gated on `Operations`, rejecting direct `Transport` grantees | Widened to `require_permission('Transport')` in 5 controllers, matching upstream |
| `trip`/`event`/`sos` blanket guard applied identically to reads and writes, and **omitted `School Super Admin`** | Replaced with graded per-endpoint levels; bypass role now honoured |
| `Operations_accounting` coupling | Fully removed (F1→F3); only comments remain |
| RTDB `Dual_write` engine | Retired (`758c924`) |
| Spec §2.4 arithmetic error (weight "154" vs tabulated 144) | Corrected to 144; verifier derives the total from the constant |
| Compliance service performing its own authorization | Prohibited and pinned by `CH-TRN-CMP-4` |
| Evidence copied rather than linked | Enforced link-only through the Canonical Document Service API |

---

## 7. Deferred Transport items

| Item | Status |
|---|---|
| **F13 — live GPS tracking** | Deferred. Blocked on the RTDB-elimination plan; live GPS was the one surface without a Firestore-native design. Not started. |
| **F12.1 deferred derived rules** | `CBSE_LADY_ATTENDANT` and `CBSE_OCCUPANCY_RATIO` ship in the rule pack but are declared in `DEFERRED_DERIVED_RULES` — they are not auto-evaluated pending a roster-derived data source. |
| **F12.2+ compliance roadmap** | Design-frozen, not authorized. |
| **`Transport_analytics` widget-visibility matrix** | Still role-name keyed (11 roles × 14 widgets). Presentation scoping only, not an access boundary; pinned by `CH-TRN-F11-4`. Converting it to graded is a separate UX decision. |
| **`routeFleetSummary` backfill** | `CH-TRN-F11-3` WARN — 2 active routes lack a summary. Run `computeRouteFleetSummary`. Cosmetic; analytics falls back to a bounded live query. |
| **Idempotency sweeper** | `CH-TRN-F6-3` WARN — 18 idempotency docs past retention. No functional impact; a retention sweeper is not yet scheduled. |
| **Promotion → transport billing recovery** | `PROMOTION_TRANSPORT_BILLING_RECOVERY_BACKLOG.md` — open backlog. |
| **Student exit → transport cleanup** | `STUDENT_EXIT_TRANSPORT_CLEANUP_BACKLOG.md` — open backlog. |
| **PF-1 — vehicle-deletion cascade** | Recorded 2026-08-08 as a **post-freeze validation** item in `TRANSPORT_POST_FREEZE_VALIDATION_BACKLOG.md`. Does a production vehicle deletion remove its `vehicleCompliance` rows and `vehicleCompliancePosture` doc? Untestable under the freeze (needs writes). Storage hygiene only — an orphan cannot reach the dashboard, the tier counts, or a dispatch decision (verified, QA §5). |

**Module status as of 2026-08-08: F1–F12.1 production-complete and FROZEN.** No further Transport
code changes. Next Transport development is **F13 (Live GPS)** as a separate feature per the
approved roadmap.

---

## 8. Deferred platform-wide items — **OUTSIDE TRANSPORT SCOPE**

> These are ERP-wide concerns surfaced during Transport work. **None is a Transport defect and none
> was modified.** Each needs its own authorization and its own regression scope.

| # | Item | Why it is out of scope |
|---|---|---|
| P1 | **42 non-Transport controllers still use `_require_role` with the legacy role-name fallback** (Fees, SIS, HR, Accounting, School Config, Notifications, Exam, Attendance, …) | Retiring it there means editing `MY_Controller` — a shared module — and re-certifying every one of those modules. Explicitly excluded by instruction. |
| P2 | **`Staff::DEFAULT_STAFF_ROLES` ships `permissionLevels` for only 1 of 21 seeded roles** | Consequence: a granted module resolves to `manage` by default, so **every newly-onboarded school starts flat at `manage`**. This is a seeder defect in a shared module, and the highest-leverage RBAC fix remaining in the ERP. |
| P3 | **`deptrole_helper::ensure_unified_roles()` does not backfill `permissionLevels`** | The backfill list is `['permissions','tier','sort_order','description']`. Existing tenants therefore never gain levels. Same shared-module surface as P2. |
| P4 | **`Operations` umbrella is coarse** — `Librarian` and `Hostel Warden` inherit `Transport:manage`, so a Librarian can delete vehicles | Pre-existing behaviour of `RBAC_MODULE_CHILDREN`, unchanged by this work. Fixing it is an RBAC-model decision affecting Library, Hostel, Inventory and Assets too. |
| P5 | **Firestore rules C1/C2/C3** — notices-read, notifications-write, `isSameSchoolWrite` schoolCode arm | Recorded in `TMS_STABILIZATION_BACKLOG.md` as a **separate ERP-wide security project**; the instruction there is explicit: do not touch these rules from Transport. |
| P6 | **`transport_probe_f10`'s 2 failures** point at `Fee_lifecycle.php` and `firestore.rules` | Non-Transport source. Listed here so the count is not mistaken for a Transport regression. |

---

## 9. Known probe data artifacts

These are **test residue in tenant `SCH_D94FE8F7AD`**, not code defects. They are why the verifier
reports 5 FAIL, and they are unchanged from the M5 baseline.

| Gate | Artifact |
|---|---|
| `CH-TRN-F1-3` | 2 vehicles with no `vendor_id` — `VH0102`, `VH0103` |
| `CH-TRN-F2-2` | 13 orphan stops with no `route_id` |
| `CH-TRN-F3-2` | 1 assignment with a missing/bad `session` |
| `CH-TRN-F5-3` | 3 expense posts stale >24h — `TEXP000011`, `TEXP000014`, `TEXP000017` |
| `CH-TRN-F7-3` | 2 orphan attendance events referencing deleted trips `TRP000015`, `TRP000016` |
| *(not a gate)* | **114 orphaned `vehicleCompliance` rows** across `VH0106`–`VH0111`, left by an earlier F12.1 probe run whose teardown did not remove compliance rows. Reported previously; still in place — cleanup is an operator decision. |
| *(not a gate)* | Seeded probe entities: `PROBE_F7_*` vehicles/routes/stops/students, `SOS0056`, and similar. |

**One disclosed incident.** During the first HTTP-probe run I used `Transport Manager` as the
"legacy" persona. That label *is* in the tenant catalogue, so `MY_Controller` re-resolved it from
Firestore (it genuinely holds `Operations:manage`) and overwrote the seeded empty map — producing
125 false failures, and, because the persona was wrongly scored as denied, allowing
`transport_ops/reconcile_pending_posts` to actually execute (`retried: 3, posted: 0, failed: 1`).
It retried the three stale posts that `CH-TRN-F5-3` was already flagging, so the effect was benign,
but it was **not** an intended mutation. Both causes are fixed: the persona is now `Principal`, and
a no-execute endpoint is only ever sent when the persona is synthetic **and** expected-denied.

---

## 10. Production readiness

**Verdict: PRODUCTION READY** — with one required operator action before Transport is used by staff.

### Required before go-live (operator action, data not code)

Retiring the fallback means **17 of 21 catalogue roles lose Transport access**, because they
previously reached it by role *name* while holding no Transport capability. Only `Admin`,
`Transport Manager`, `Librarian` and `Hostel Warden` retain access (plus the two bypass roles).

**Losing access:** `Principal`, `Vice Principal`, `Accountant`, `Academic Coordinator`,
`Class Teacher`, `Teacher`, `HR Manager`, `Doctor`, `Security`, `House Warden`, `Clerk`,
`App Baseline`, `Staff`, `Lab Assistant`, `Peon/Attendant`, `Driver`, `Front Office`.

**Action:** in Staff Access, grant `Transport` at the appropriate level (or `Operations` for the
umbrella) to every role that should retain access. `Principal` and `Vice Principal` are the most
likely to need it. This is intended behaviour, not a defect — and it is the point of the migration:
access is now explicit and auditable rather than implied by a name.

`application/controllers/Rbac_transport_impact.php` (CLI-only, read-only) prints this table and can
be re-run to confirm the remediation.

### Not blocking

* 5 verifier FAIL + 2 probe FAIL — tenant data artifacts and non-Transport source (§9, §8-P6).
* 2 WARN — `routeFleetSummary` backfill, idempotency retention (§7).
* 114 orphaned compliance rows (§9).

### Deploy sequencing (when authorized)

Transport requires **no new Firestore rules or indexes** — this release changed server-side PHP
authorization only, with no change to any collection, document shape, query, or index. The 11 RC-2
composite indexes it depends on are already present (`CH-TRN-RC2-2` PASS). Standard ordering still
applies for anything bundled alongside it: backfills → indexes → rules → code.

---

## 11. Artefacts

**Reports:** `TRANSPORT_GRADED_RBAC_ALIGNMENT_REPORT.md` (migration detail),
`TMS_F12_1_COMPLIANCE_ARCHITECTURE_SPEC.md` (design-frozen), this document.

**Diagnostics awaiting your decision** — both CLI-only, read-only, no writes; deletable on request:
`application/controllers/Rbac_levels_inspect.php` (raw `schools/{id}.staffRoles` dump),
`application/controllers/Rbac_transport_impact.php` (§10 access table).

**Housekeeping:** a junk untracked file with a corrupted name (`"[\357\200\242"`) sits in the repo
root — a stray shell artifact, harmless but it will appear in any future `git add`.

---

## 12. Release constraints

No commits. No rebases. No release branch. No push. No deployment.
All work remains uncommitted in the `integration/phase2` working tree.

**Stopped for approval.**
