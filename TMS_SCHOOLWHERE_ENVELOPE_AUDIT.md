# schoolWhere Nested-Envelope Audit (systemic)

**Generated:** 2026-07-22 · **Trigger:** FIN-2/CON-4 exposed that `Firestore_service::schoolWhere()/sessionWhere()/where()` return NESTED rows `[['id'=>docId, 'data'=>{…fields…}], ...]`, and several transport/fees methods read domain fields flat off the row (`$row['field']`) → silent NULL.
**Method:** read-only repo-wide audit (fan-out) of every `schoolWhere`/`sessionWhere` consumer. **Status:** transport findings FIXED this sprint; fees findings CATALOGED for a dedicated cleanup.

## Scope covered
**386 `schoolWhere`/`sessionWhere` sites across 39 files.** Result: **9 incorrect** (transport 6 + fees 3), **3 already fixed** (FIN-2, CON-4 chunk, CON-4 reconciler), rest clean.

| Area | Sites | Incorrect | Verdict |
|---|---|---|---|
| Rest of ERP (libraries + ~20 controllers: HR/Attendance/SIS/School_config/Communication/Academic/Classes/Staff/Admin/…) | ~226 | **0** | ✅ clean (double-confirmed by two independent passes) — consistent `$row['data']` unwrap |
| Transport module | ~82 | **6** (+2 fixed) | 🔧 FIXED this sprint |
| Fees/Accounting | 19 | **3** (+1 fixed) | 📋 cataloged (mostly out-of-transport-scope) |

## Transport findings — FIXED (systemic cleanup, one group)
All fixed by unwrapping the row (`_unwrapNestedRow($row,'schoolId')`, or inline `$row['data']` in the controller) before field access. Gate `CH-TRN-ENV-1`; runtime `transport_probe_env` (bulk rebuildCapacityCache: max_capacity 0→40).

| Location | Method | Was reading flat | Impact (pre-fix) |
|---|---|---|---|
| Transport_service.php:1611 | computeFleetExpiryDashboard | number, insurance/fitness/permit/pollution/road_tax_expiry | expiry dashboard reported **zero** expiries |
| Transport_service.php:3185 | rebuildCapacityCache (bulk) | vehicle_id | `max_capacity=0` for all routes in bulk rebuild |
| Transport_service.php:3420 | listActiveDrivers (fallback filter) | flags.can_drive_transport | **zero drivers** when index missing |
| Transport_service.php:4117 | computeDriverActivitySummary | effective_from/effective_to | `temp_cover_days` always 0 |
| Transport_service.php:5027 | _recomputeKmpl | odometer_km, litres | `average_kmpl` never updated |
| Transport.php:214 | get_lifecycle_log | eventId + shipped raw envelope | lifecycle-log UI fields empty |

Already fixed earlier this sprint: `cancelSessionDemands` (FIN-2), rollover chunk loop + `reconcilePendingFees` (CON-4).

## Fees/Accounting findings — CATALOGED (dedicated cleanup, NOT transport scope)
Variant: domain fields unwrapped correctly, but the **docId read from `$d['id']` (data level) instead of `$row['id']` (envelope)** → empty → row skipped.

| Location | Method | Severity | Impact |
|---|---|---|---|
| **Fee_firestore_txn.php:1485** | **allocationsForStudent** | **HIGH / production** | ✅ **FIXED** (2026-07-22, operator-authorized Fees task) — now reads `_docId` from the envelope `$r['id']`. Gate `CH-TRN-FEE-1`; probe `fee_probe_alloc` 2/2 (returns the 2 non-reversed allocations with real doc-ids, was `[]` → refund stale-allocation guard restored). |
| Fees.php:3187 | void_test_receipt | Low | feeIdempotency never deleted (+ a variable-ordering bug: `$d` read before assignment) — test/void helper |
| Fees.php:3454 | test_reset_student | Low | "wipe test data" deletes nothing — test helper |

Fix for all three: read the docId from `$row['id']` (envelope), not `$d['id']`.

## Recommendation
- Transport 6: DONE + gated + validated.
- Fees 3: single "Fees nested-envelope docId cleanup" task, **prioritizing `allocationsForStudent`** (production financial-integrity) — run as a controlled Fees-module change outside the transport sprint (operator decision).
- No other module is affected.

*Working artifact; not committed to git.*
