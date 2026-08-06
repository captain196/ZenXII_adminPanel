# Transport Module — Final Independent Production Certification

**Date:** 2026-08-04 · **Scope:** the complete TMS stabilization program (Critical + High + Medium + Low + the Notification Recipient Contract item) plus the post-audit discovered defect NF-1. · **Release constraints observed throughout:** no commit / rebase / release branch / push / deploy — everything remains in the working tree.

---

## Objective
Independently certify that: no Critical/High/Medium/Low findings remain open, no regressions were introduced, all verifier gates are green, Firestore-only architecture is preserved, and cross-module behaviour (Accounting, Communication, Parent App, Teacher App, Transport) is consistent.

---

## 1. Deterministic validation

| Check | Result |
|---|---|
| Transport verifier gates | **93 PASS** — every source/logic gate incl. all stabilization gates + `CH-TRN-RCP-1` (recipient) + `CH-TRN-NF1-1` (NF-1) |
| Firestore Rules unit suite | **199 / 199** (7 suites) — emulator |
| Firestore-only | **Clean** — no RTDB (`getReference`/`->database()`/`firebaseio`) in any changed core file; the only "RTDB" token is the `FIRESTORE-ONLY. NO RTDB` assertion comment |
| Verifier FAILs | **5, all pre-existing runtime *data* conditions, NOT code regressions:** `F1-3` (vehicle vendor FK), `F2-2` (stop→route FK), `F3-2` (assignment session), `F7-3` (attendance→trip FK) — seeded/orphan test data on collections no change touches — and `F5-3` (old `transport_probe_f5` Voided-expense artifacts that crossed the 24h staleness line as the date advanced). None are introduced by stabilization. |

## 2. Runtime probe regression (independent delta re-validation) — 12 probes, all green
`recipient` 5/5 · `nf1` 5/5 · `fin3` 4/4 · `log45` 4/4 · `con5` 2/2 · `sec5` 2/2 · `perf3` 4/4 · `perf10` 2/2 · `perf11` 2/2 · `perf4` 4/4 · `log2` 2/2 · `xmod1` 3/3 — 40+ assertions covering FIN-3/4, LOG-2/4/5, CON-5, SEC-5, PERF-3/4/6/10/11, XMOD-1, the canonical recipient contract, and NF-1.

## 3. Adversarial delta correctness (independent re-read)
- **Recipient resolver** (`_resolveStudentRecipients`): reads the canonical `userId` field; `studentId IN` batching + per-student point-read fallback is robust whether or not the composite index is present (`where()` returns `[]` on missing index → the `foreach(chunk→missing)` fallback fires); all reads school-scoped → no cross-tenant leakage; dedup on `userId`. Confirmed correct.
- **`searchStudents` (NF-1)**: `schoolWhere('students', [])` with a null limit sets **no** `runQuery` limit (verified in `Firestore_rest_client::query` line 1538) → returns the full school set (no silent truncation), matching the prior Ops behaviour; school-scoped; 5-min per-school cache; identical result shape. Confirmed correct.
- **Analytics** (PERF-3 onboard single-scan bucketing; PERF-11 FMES canonical-docId filter via `fs->docId`; PERF-10 tenant-scoped memo key; SEC-5 `finally` restore + PERF-6 per-tenant `try/catch`; PERF-4 rollup + bounded fallback): re-read + probe-covered. Confirmed correct.
- **Expense/accounting** (FIN-3 posted-expense immutability; FIN-4 reverse-then-void; FIN-5 read-only `_accountPostable` pre-check): re-read + probe-covered; engine untouched. Confirmed correct.

## 4. Cross-module consistency (independent audit) — all 5 boundaries CONSISTENT
- **Accounting:** `_accountPostable` is transport-only (sole caller `postExpense`, itself only called by `_postToAccounting`), tenant-scoped, a read-only mirror of the engine's own `active + non-group` predicate, fails safe; payroll methods + shared `Operations_accounting` engine untouched.
- **Communication:** transport `notices` / `noticeRecipients` writes match the canonical `Communication_helper` / `NoticeAnnouncement` field shapes (extra fields additive; role-based `targetRoles` contract intact).
- **Parent App:** `notifications` doc shape matches `NotificationDoc.kt`; recipient `userId` == the app's `getUserId()` inbox query key; prefs `{schoolId}_{userId}` aligned. No app change required.
- **Teacher App:** `vehicles.currentRouteId` (camelCase) matches `VehicleDoc.kt`; no admin SOS change needed.
- **Fees:** nested-envelope unwrap correct at each site; FIN-1 `paidAmount` preservation contract-safe through `writeDemand` merge; FIN-2 case-fix coherent writer↔reader.

## 5. Two notification contracts (certified distinct, both production-supported)
1. **Parent** → per-user delivery keyed by canonical **`userId`** (the student's `userId` field) — normalized this program.
2. **Operations/Staff** → role-based delivery via **`targetRoles`** → `notices`/`noticeRecipients` — deliberately unchanged.

---

## Certification statement
The Transport module's stabilization work (all backlog tiers) plus the recipient-contract normalization and the NF-1 fix are **functionally complete and independently validated**: gates green, rules green, Firestore-only preserved, runtime probes green, cross-module contracts consistent. The 5 verifier FAILs are pre-existing test-tenant data artifacts, not code defects.

### Residual items (tracked, NOT regressions, outside admin-code scope)
- **XMOD-2 / XMOD-3** — Parent/Teacher `VehicleDoc` `@PropertyName` mappings — **app-repo release** (exact fixes specified; no admin change).
- **SEC-7** — `transportEvents`/`eventTripAssignments` parent-read tightening — **deferred**, needs a coordinated Admin+Parent+Rules doc-split.
- **CON-8** — `_resolveParentsOfRoute` 500 route-membership cap — unchanged (a single bus never approaches 500).
- **Cosmetic** — `notifications` docId double-prefix (`_fanoutNotifications`) — no delivery impact (app reads by `userId` field).
- **Housekeeping** — the `F5-3` verifier FAIL is stale `transport_probe_f5` Voided test expenses; running `transport_expense_reconciler` or deleting those probe artifacts clears it.

*Working artifact; not committed to git.*

---

## Addendum (2026-08-04) — Provisional-cert findings resolved + ops-notice subsystem certified

Three fresh adversarial agents challenged the provisional cert; verified findings were fixed one-by-one under operator approval (Transport-local scope only):
- **A1 FIN-3** — `expense_date` added to posted-expense immutability (gate `CH-TRN-FIN3-1`; probe 5/5).
- **B1 onboard** — canonicalized to the BARE trip id; false-green probe corrected; multi-tenant isolation proven (`transport_probe_mtonboard` 3/3); gate `CH-TRN-PERF3-1` hardened (bare id + schoolWhere scoping).
- **B3 ops-notice timestamp** — `_fanoutOps` emits canonical `timestamp`+`timestampMs` (Option A — converge on the one Communication contract; reader/index untouched); gate `CH-TRN-OPSN-1`.

**Full ops-notice subsystem certification (read-only):** all **19** SCOPE_OPS categories (`fleet.doc_expiring/vehicle_replaced/vehicle_retired`, `route.suspended/resumed`, `assignment.bulk_complete/rollover_complete`, `driver.replaced/critical_incident/licence_expiring`, `expense.post_failed/pending_stale`, `sos.raised/responded/resolved`, `trip.started/completed`, `event.trip_dispatched/completed`) route through the single `_fanoutOps` writer, which emits the canonical schema unconditionally. Runtime `transport_probe_opscert` — 19/19: each writes ONE `notices` doc with the full canonical schema and appears in the `ORDER BY timestamp DESC` admin bell. (`driver.licence_expired` and a temporary-driver category do not exist — no notice fired.) The 8 parent-facing categories correctly target the `notifications` inbox, not the admin bell. Gate count: **94 PASS** (5 pre-existing runtime data FAILs).

**Still open (NOT started, separate projects):** platform-wide security C1 (`notices` read = isSameSchool), C2 (`notifications` write = isStaff), C3 (isSameSchoolWrite schoolCode arm); and Transport-local A2–A6/B2 (documented, deferred by the operator). `Communication_helper::write_event_notice` shares the same timestamp gap (platform hardening project).
