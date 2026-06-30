# Fees V7 — Deferred Enhancement Backlog

Items found during workflow-by-workflow validation that are **not** production blockers
(existing workflows operate correctly; canonical invariants hold tenant-wide) but are
required for full commercial-ERP maturity. Deferred per operator decision.

Tenant validated: `SCH_D94FE8F7AD`. Branch: `ank-yug_b1`.

---

## FH — Fee Head Management (registry write-path) — DEFERRED, Medium

Origin: Workflow 1 (Fee Head Management) validation, 2026-06-29.

Production-impact assessment (tenant-wide, read-only) confirmed:
- 6 canonical heads, all `status=active`; all 20 chart heads → registry (INV-5 PASS);
  all 666 demands → registry (INV-1/2 PASS); 0 titles/receipt heads outside registry.
- Every operational, financial, accounting, reporting, Parent App, and Teacher App
  workflow functions on the existing six heads. Nothing is blocked.
- Even creating a new head does **not** break operations/money (name/label/amount-keyed,
  with `?? feeHeadId` runtime fallbacks); only canonical invariants degrade for that
  new head, and recoverably.

**FH-1 (Medium) — Canonical `feeHeads` registry has no application write-path.**
Registry was seeded once by `phaseA_mint_registry.js`; no controller/library writes to
the `feeHeads` collection. `save_fee_title` writes only `feeStructures/{}_titles`. New
heads created via the UI are therefore non-canonical (no registry doc; chart sync mints a
chart-local `feeHeadId`; demands' `canonicalFeeHeadId` won't resolve to the registry).
Classification: Implementation (frozen-architecture chokepoint never built).
Proposed fix: `Fee_head_registry` owner library with the single chokepoint —
`createHead(name, billingType)`, `renameHead`, `setBillingType` (guarded: reject if any
demand/receipt exists), `retireHead` (guarded: reject if referenced by an active chart);
re-point `save_fee_title`/`delete_fee_title` to it; add edit/rename route + minimal UI;
verifier reasserts INV-1/2/5 after each op.

**FH-2 (Medium) — No edit/rename/re-classify head action exists.** No route/endpoint to
rename a head or set `billingType` in a governed way; registry `status` lifecycle
unreachable. Classification: Implementation (missing functionality).

**FH-3 (Medium) — `delete_fee_title` is unguarded and registry-blind.** Removes only the
`_titles` entry with no in-use check and no registry deactivation. Blast radius confirmed
small: charts/demands keep working off the name; desyncs the title dropdown from charts
but does not break demands or money. Classification: Implementation + Data-consistency.

Caveat: priority rises to High if the school needs to add heads in the near term, or if
any downstream tooling begins enforcing registry membership at runtime. A cheap interim
guard (append a registry doc in `save_fee_title`, or surface a "not yet canonical" notice
/ disable new-head creation) would neutralize the UX trap immediately.

---

## WF2 — Fee Structure — DEFERRED items (Medium/Low)

Origin: Workflow 2 (Fee Structure) validation, 2026-06-29.
Live-data validation PASSED: 5/5 charts well-formed; 0 cadence mismatches vs registry;
0 negative amounts; 0 demand-cadence violations; demand grossAmount == chart amount (0
mismatches). Workflow ACCEPTED for current production. The following are latent.

**WF2-1 (Medium) — Generation cadence + demand `billingType` driven by chart BUCKET
position, not enforced against registry `billingType`.** `_auto_generate_student_demands`
sets `frequency = ($month === 'Yearly Fees') ? 'yearly' : 'monthly'`, and `_buildDemandDoc`
derives `billingType` from that frequency — so charting a registry-Yearly head into the
monthly buckets would generate 12 monthly demands (over-billing) with `billingType=Monthly`
diverging from the registry. Live data is clean (heads correctly bucketed), so no current
impact. Hardening: enforce/validate chart placement against the head's canonical
billingType on save (`save_updated_fees`/`syncFeeStructure`). Classification: Implementation
(input-validation/canonical-cadence enforcement).

**WF2-2 (Medium/Low) — Additive chart merge cannot remove a head.** `save_updated_fees`
does `array_merge(existing, incoming)` per month, so omitting a head from the save payload
retains its prior amount (billing continues); true removal requires explicitly sending 0.
Needs chart-edit UI behavior confirmation. Recoverable (set amount 0). Classification:
Implementation (admin convenience / removal semantics).

**WF2-3 (Low / performance, scale-dependent) — `save_updated_fees` generates demands
synchronously per student in the request.** For large sections (e.g. 50 students × ~16
heads ≈ 800 sequential `writeDemand` round-trips) this risks request latency/timeout. No
impact at this tenant's section sizes; an async job path (`generate_monthly_demands` /
`_processGenerationJob`) already exists for bulk. Rises to High at commercial scale.
Classification: Implementation (performance).

**WF4-1 (Medium, latent) — `_generateDemandsForMonth` (manual "Generate Monthly Demands"/job path) folds yearly heads into April with `month="April"`/`period="April YYYY"`; pre-Phase-D apps detect yearly by `month=="Yearly Fees"` so such demands would display as an April monthly line. Live data clean (all 8 yearly demands correctly labeled via the save-chart path); manifests only if the manual path generates yearly fees. Classification: Implementation. Recoverable.**

---

## DEFAULTER-PROMO-1 — ✅ RESOLVED 2026-06-29 (was HIGH code defect)

**FIXED:** `Fee_lifecycle::reassignFeesOnPromotion` now calls `Fee_defaulter_check::updateDefaulterStatus($studentId)` (the same business logic Admission uses) instead of the hardcoded `is_defaulter=false` placeholder. `php -l` clean; regression verifier 10/10 PASS (owing student stays in feeDefaulters; zero-dues student removed; idempotent; no duplicate demands; no financial/receipt/accounting/summary changes). Scope: single file, single block. Uncommitted on `ank-yug_b1`. Original defect description retained below for traceability.

---
### (original finding)

**Promotion deletes the defaulter projection and never recomputes it.**
- `Sis::execute_promotion` (shutdown closure) → `Fee_lifecycle::reassignFeesOnPromotion` → line 496 writes a **placeholder** `syncDefaulterStatus(['is_defaulter'=>false,'total_dues'=>0])`.
- `Fee_firestore_sync::syncDefaulterStatus` line 511-513: `is_defaulter=false` ⇒ **DELETES** the `feeDefaulters` doc.
- Unlike admission (`Sis::enroll_student:547` calls `updateDefaulterStatus` — Phase 3D fix), the promotion path **never** runs the real recompute. The Phase 3D fix was applied to admission but NOT promotion.
- **Effect:** after every promotion, a student who still owes fees in the new class is **removed from the defaulter report** and not re-added until their next payment. Recurring on the annual promotion cycle for a real school.
- **Severity: HIGH** (financial-reporting correctness on a routine operation; no money corruption; self-heals on next payment; remediable by recompute).
- **Surgical fix (1 call):** in the `execute_promotion` shutdown closure, after `reassignFeesOnPromotion`, call `$this->feeDefaulter->updateDefaulterStatus($userId)` per promoted student (mirror admission line 547). Optionally remove the misleading placeholder at `Fee_lifecycle:496`.
- **Proves DR-1 is not purely data hygiene:** a brand-new school is correct after admission but would require a defaulter recompute after its first promotion — because of this code defect.

---

## ALTERNATE CLASS-MUTATION PATHS (Medium — separate from DEFAULTER-PROMO-1)

Promotion is not a single path. The deletion bug is confined to path #1; these two do NOT delete defaulters but also do not reassign fees themselves.
- **WF-PROMO-2** `School_config` session-rollover promote (L3317-3830) — advances `className`/`session`/`classOrder` only; generates zero demands; relies on the separate new-session chart-setup + bulk generation (`_processGenerationJob`, which refreshes defaulter+summary). By-design two-step; verify the rollover runbook always includes the bulk-generation step.
- **WF-PROMO-3** `Classes::transfer_students` — section move; updates `className`/`section` without re-charting demands; matters only when sections carry different fees. Medium.

---

## STALE-READER-1 — RECLASSIFIED to Examination module (not a Fees defect)

Reclassified 2026-06-29 as an **Examination-module integration issue**, removed from Fees V7
scope. Full detail + required canonical migration: see **EXAMINATION_BACKLOG.md →
EXAM-FEE-ELIGIBILITY-1**. (Fail-open exam-fee gating that reads legacy `feeItems`; pre-existing
Phase-2.5 schema gap; does NOT affect any Fees workflow, money, accounting, receipts, or apps.)

## DEV-TOOL-1 (Low) — `Fee_simulation` controller reads RTDB demand paths

`Fee_simulation` (routes `fee_simulation/*`, dev/load-test harness, not in any customer
nav) reads `firebase->get("…/Fees/Demands")` (RTDB, eliminated → no-op). Dev tooling; not a
production path. Recommend retire/gate alongside `firestore_bulk_sync`.

---

## LEGACY-WRITER-1 (Low dormant / Medium if-run) — `firestore_bulk_sync` legacy RTDB-backfill

`Fee_firestore_sync::syncDemandsForMonth` (feeDemands) + `syncReceipt` (feeReceipts) write the
**legacy month-aggregated schema** (`feeItems` map; no `canonicalFeeHeadId`/`billingType`/`periodKey`).
Reachable only via `Fee_management::firestore_bulk_sync()` (ADMIN-gated "one-off" endpoint) →
`syncAllDemandsForAllStudents`/`syncAllReceipts`, which read from **RTDB** (`firebase->get("Schools/.../Fees/Demands")`).
RTDB is eliminated project-wide, so the endpoint is **inert (no-op on empty RTDB)** today. `drainRetryQueue`
also dispatches these kinds, but `queueForRetry` is never called with `feeDemands`/`feeReceipt` (dead branch).
**Hazard:** if RTDB ever held stale data and an admin ran the endpoint, it would write non-canonical
demands/receipts (INV-1/2 violation). **Recommendation:** retire or Fees-V7-guard `firestore_bulk_sync`.
Not a live runtime defect; not on any transaction path.

---

## RECOMPUTE-STALENESS GAPS (Medium — projection lag, NOT deletion)

These balance-mutating paths do **not** refresh the `feeDefaulters`/`studentFeeSummary` projections, but they also do **not** delete them — the existing defaulter doc stays (amount may lag); self-heals on next payment/recompute; demand balances themselves are correct. Distinct from DEFAULTER-PROMO-1 (which deletes).
- **WF-REC-1** `save_updated_fees` (roster re-charting) — adds/changes demands, no projection refresh.
- **WF-REC-2** `recalculate_demands` / `recalc_unpaid_discounts` — rebuild unpaid demands, no projection refresh.
- **WF-REC-3** `auto_compute_fines` — adds fines to balances, no projection refresh.
- **WF-REC-4** `generate_demands_for_student` (single-student manual) — no projection refresh (bulk `generate_monthly_demands`→`_processGenerationJob` DOES refresh both).
- Suggested remediation (optional, low-risk): append `updateDefaulterStatus($sid)` (+ summary) to these admin-triggered paths, mirroring admission/bulk-gen.

---

## GO-LIVE DATA REMEDIATION (High, Data — NOT a code defect)

**DR-1 — Live aggregates are stale/empty due to test-data injection via Node (which bypassed the PHP app's consistency writers).** Affects Carry Forward, Defaulters, Reports, Dashboards.
- `studentFeeSummary`: 8 docs, stale (updatedAt 2026-05-27), diverge from current `feeDemands` (both directions).
- `feeDefaulters`: **0 docs** → defaulter report shows ~0 defaulters while 8 students owe ₹34,600+.
- `feeReceiptAllocations`: 19 orphans (STU0001) with 0 parent receipts.
- `accountingLedger`: 35 balanced entries (Dr==Cr ✓) but `feeReceipts=0` (manual resets cleared receipts without void/refund reversal).
- **Remediation (one-time, before go-live):** rebuild `studentFeeSummary` (via `Fee_summary_writer`) + `feeDefaulters` (via `Fee_defaulter_check::updateDefaulterStatus`/`syncDefaulterStatus`) from canonical `feeDemands`; delete STU0001 orphan allocations/index; reconcile or accept orphaned accounting test entries. Rebuild paths EXIST in the app and fire on normal flows — this is data hygiene, not code.

---

## WF7 — Receipts — DEFERRED items

**WF7-1 (Data, Medium, isolated) — 19 orphan `feeReceiptAllocations` + 19 `feeReceiptIndex` docs for test student STU0001 (receiptKeys F10–F28), with 0 parent `feeReceipts` and 0 paid demands.** Residue from the earlier manual STU0001 fee-data reset (cleared demands+receipts but not allocations/index). Would render a phantom ledger for STU0001 only; 8 real students unaffected. Fix: delete the 19 orphan allocation + 19 index docs for STU0001 (completes the reset). Classification: Data (test residue).

**WF7-2 (Low, QA-only, unverified) — `void_test_receipt` may not delete `feeReceiptAllocations`** (deletes feeReceipts+feeReceiptIndex). QA/dev utility only (real refunds use `Fee_refund_service`); verify and align if confirmed. Classification: Implementation (QA utility).

---

## WF2 — continued

**Not a defect (by design):** editing a chart amount does not update already-issued unpaid
demands — consistent with the frozen "immutable issued demands" model; the governed update
path is `recalculate_demands` (deletes unpaid + regenerates). UX note: the chart screen
should make clear that amount edits to existing periods require a recalculate.
