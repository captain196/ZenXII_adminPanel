# RC-3 — docId Double-Prefix Convention (status + deferred cleanup)

**Part of:** TMS Stabilization Sprint · **Root cause:** RC-3 (writers that embed `schoolId` in the entity id get re-prefixed by `setEntity`/`getEntity`, producing `{schoolId}_{schoolId}_…`).
**Status:** implemented + validated (uncommitted). This documents what was fixed and what is deliberately deferred.

Canonical convention: pass a **bare** entity id to `setEntity`/`getEntity`/`casUpdateEntity`/`createEntity`; the service layer adds the single `{schoolId}` prefix. (The correct sibling `RFS_{routeId}` already followed this.)

---

## Fixed under RC-3

### FMES + VEXPCACHE — real defect (PERF-5)
`fleetMonthlyExpenseSummary` (`FMES__{ym}`) and `vehicleExpiryDashboardCache` (`VEXPCACHE`) had a **read/write inconsistency**: the writers double-prefixed, the ops readers passed prefixed ids (and hit the double), but the **analytics/service readers passed bare ids and missed → read 0**. Fix: writers + ops readers switched to **bare** ids → all readers resolve to the canonical `{schoolId}_…`.
- Gate `CH-TRN-RC3-1`; probe `transport_probe_rc3` (3/3, incl. an explicit demonstration that a school-prefixed write is invisible to a bare read).
- Transition: old `{sid}_{sid}_…` docs are superseded when the derived cache next rebuilds (release **Stage-5 analytics warm-up** runs the rollup). No manual migration.

### CAPCACHE — normalized (self-consistent, no defect, but unified for cleanliness)
`busCapacityCache` was double-prefixed but read/write self-consistent (worked). Normalized to a **single canonical helper** `Transport_service::_capacityCacheId($routeId)` (bare); the 4 accessors (rebuild, `_checkCapacityAvailable`, `_bumpCapacityCache`, `_reserveCapacitySeat`) route through it. `rebuildCapacityCache` additionally **self-heal-purges** the legacy double-prefixed doc so no stale orphan lingers (which `CH-TRN-F3-5` would otherwise flag as drift).
- Dependency audit (approved pre-change): id construction was 100% inside `Transport_service.php`; **zero** references in Fees/Accounting/HR/SIS/Communication, the Teacher app, the Parent app, or any cron/job. The cache holds no authoritative data (rebuild recomputes `current_count` from `studentRoutes` and `max_capacity` from `vehicles.capacity`), so ignoring/deleting it cannot corrupt business data.
- Gate `CH-TRN-RC3-2` (forbids inline CAPCACHE id construction in the accessors — enforces single-source-of-truth for future modules); probe `transport_probe_capnorm` (2/2: canonical write recomputed from source = 2 not the stale 99; legacy orphan purged).
- Transition: self-healing (rebuild-on-miss recomputes from source), idempotent, no manual migration, no business-data impact.

---

## Deferred — future migration task (NOT changed)

`transportAttendance` boarding docs and `transportNoticeIdempotency` claim docs are **double-prefixed but read/write self-consistent** — they are **not defective**:

- **Boarding attendance**: written with a deterministic id and read almost exclusively via `tripId` **field-queries** (id-convention-agnostic). Per-trip dedup stays consistent going forward. Changing the convention would orphan real attendance history from any by-id lookup for **zero** functional benefit, and — unlike a cache — there is no rebuild to self-heal it.
- **Idempotency claims**: `createEntity`/`getEntity`/`removeEntity` all use the same `{schoolId}_{key}` id. Changing it would orphan in-flight claims (harmless — they TTL-expire) for zero benefit.

**Decision (operator-approved):** leave both unchanged during stabilization. Normalizing them provides no production benefit and only introduces churn + orphaned records. If uniformity is later desired, treat it as a **dedicated data-migration task** (move existing docs to the canonical id, or accept forward-only convention with a documented cutover) executed outside the stabilization sprint.

If that migration is undertaken later, mirror the RC-3 pattern: introduce a single canonical id helper per collection, route all accessors through it, add a verifier gate forbidding inline id construction, and (for records without a rebuild path) include an explicit backfill/move step rather than relying on self-heal.

---

*Working artifact; not committed to git.*
