# Transport — Post-Freeze Validation Backlog

**Opened:** 2026-08-08
**Module status:** Transport (F1–F12.1) is **production-complete and FROZEN.**
**Purpose:** hold validation work that requires writes, or otherwise cannot run under the freeze, so
it is not lost and does not expand the current scope.

> These are **validation** items, not defects and not features. Nothing here blocks Transport
> acceptance. Each needs explicit authorization before it runs.

---

## PF-1 — Vehicle-deletion cascade *(recorded 2026-08-08, operator-directed)*

**Question:** when a vehicle is deleted through the admin UI, are its compliance children deleted
with it?

**Children in scope**
| Collection | Key |
|---|---|
| `vehicleCompliance` | `{vehicleId}_{ruleCode}` — one row per rule (19 per vehicle) |
| `vehicleCompliancePosture` | `{vehicleId}` — one posture doc |

**Why it is open.** The F12.1 QA harness is strictly read-only, so it could observe the *result* of
past deletions but never exercise the deletion path itself. What it observed in tenant
`SCH_D94FE8F7AD`: **114 orphaned `vehicleCompliance` rows and 6 orphaned
`vehicleCompliancePosture` docs** across `VH0106`–`VH0111`.

**What is already known — do not re-derive**
* Those orphans came from a **probe teardown** that removed its fixture vehicles but not their
  compliance children. That is test-harness behaviour. It does **not** by itself prove the
  production `Transport::delete_vehicle` path has the same gap — that is exactly what PF-1 tests.
* **Functional impact of an orphan is nil, and this was verified rather than assumed.**
  `Transport_compliance::dashboard()` drives from `listVehicles()` and looks up posture *per live
  vehicle*; it never enumerates the posture collection. `rebuildFleetPosture()` iterates vehicles
  the same way. `isDispatchBlocked()` is per-vehicle by id. An orphan cannot surface in the
  dashboard, skew the tier counts, or block a dispatch.
* So PF-1 is a **storage-hygiene** question, not a correctness or safety one. Priority: low.

**Test to run (requires writes → requires unfreezing, or a disposable tenant)**
1. Create a throwaway vehicle.
2. Evaluate it so all 19 `vehicleCompliance` rows and its posture doc exist. Record the counts.
3. Delete the vehicle via `POST transport/delete_vehicle` — the production path, not the service.
4. Assert both collections have zero rows for that `vehicleId`.
5. If rows survive, the finding is a **cascade gap in production deletion** and should be raised as
   a normal defect against the then-unfrozen module — with the fix in the deletion path, never a
   one-off cleanup script.

**Independent of PF-1:** the existing 114 + 6 orphans can be deleted as operator cleanup at any
time. That removes the residue but does not answer the question above.

---

## Candidates — noted only, NOT authorized and NOT in scope

Listed so the coverage boundary stays visible. None is a known defect; each is simply an area the
read-only QA could not reach.

| # | Area | Why untested |
|---|---|---|
| C-1 | Concurrent `attestRule` / `waiveRule` on the same `{vehicleId}_{ruleCode}` row | CAS is covered generally by the `CH-TRN-CON*` gates but not specifically for compliance rows. Needs concurrent writes. |
| C-2 | The 2 deferred derived rules — `CBSE_LADY_ATTENDANT`, `CBSE_OCCUPANCY_RATIO` | Declared `evaluation=derived` and deliberately not auto-evaluated. There is no logic to test yet; this belongs with whatever ships their roster-derived data source. |
| C-3 | Waiver expiry transition — a `Waived` row crossing its `waiver_expires_on` | Requires either a write or a clock shift. Bounds enforcement at write time is fully covered (QA §D). |

---

## Related

* `TRANSPORT_MODULE_PRODUCTION_CERTIFICATION.md` — module close-out; §7 deferred Transport items.
* `TRANSPORT_F12_QA_TEST_REPORT.md` — §5 orphan audit, §7 what was not tested.
* Next Transport development: **F13 (Live GPS)**, a separate feature per the approved roadmap.
