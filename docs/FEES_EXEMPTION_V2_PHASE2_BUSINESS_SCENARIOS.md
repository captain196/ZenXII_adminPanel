# Fees Exemption v2 — Phase 2 (Option A) — Business verification scenarios

> **Audience:** School Super Admin + Accounts Lead, reviewing before authorizing the lockstep activation window.
> **Status:** Pre-activation reference. All numbers derived from the shipped code at HEAD `d52eaf75`. No flag has been flipped.

---

## Test student + chart used for every scenario

| Field | Value |
|---|---|
| Student | `STU0099` — "Aarav Sharma" |
| Class / Section | Class 8th / Section A |
| Session | 2026-27 (April 2026 – March 2027, 12 monthly cycles) |
| Bus Fee (head `BUS`) | **₹1,000 / month** monthly frequency |
| Tuition Fee (head `TUIT`) | **₹2,000 / month** monthly frequency |
| Annual billing at gross | 12 × ₹1,000 + 12 × ₹2,000 = **₹36,000** |

The student is freshly admitted with no prior demands. Concessions, when shown, are recorded immediately before admission so the very first generation event sees them. The exact behavior when concessions arrive *after* demands already exist is covered in §4 (Cross-cutting notes).

---

## Scenario A — Bus exemption only (`type: fullExempt`, head-scoped)

### A.1 — Concession record created

Operator action: Concessions & Services → New Concession → Student `STU0099`, scope `head`, target `BUS`, type `Full exempt`, effective from `2026-04-01`, reason `Walks to school`.

Doc written to `studentConcessions/{schoolId}_STU0099_{seq}`:

```
{
  "schoolId":         "SCH_xxx",
  "studentId":        "STU0099",
  "scope":            "head",
  "targetFeeHeadId":  "BUS",
  "targetCategory":   null,
  "type":             "fullExempt",
  "value":            0,
  "effectiveFrom":    "2026-04-01",
  "effectiveTo":      null,
  "reason":           "Walks to school",
  "approvedBy":       "SSA0001",
  "approvedAt":       "2026-04-01T09:30:00+05:30",
  "status":           "active",
  "createdBy":        "SSA0001",
  "createdAt":        "2026-04-01T09:30:00+05:30"
}
```

### A.2 — Generated fee demands

| | **Before (flags OFF — legacy)** | **After (flags ON — concession active)** |
|---|---|---|
| Tuition demands written | 12 × ₹2,000 = ₹24,000 | 12 × ₹2,000 = ₹24,000 (unchanged) |
| Bus demands written | 12 × ₹1,000 = ₹12,000 | **0 demands** (every bus spec dropped via `fullExempt`) |
| Total demands count | 24 docs | **12 docs** (bus heads silently absent) |
| Per-demand fields differ? | n/a | Tuition demands carry no `concessionApplied` field; bus has no docs at all |

Each tuition demand looks like (April 2026 example, `demandId = DEM_STU0099_2026-04_TUIT`):
```
{
  "studentId": "STU0099", "feeHead": "Tuition Fee", "feeHeadId": "TUIT",
  "period": "April 2026", "periodKey": "2026-04",
  "grossAmount": 2000, "netAmount": 2000,
  "paidAmount": 0, "balance": 2000, "status": "unpaid"
}
```

### A.3 — Parent app dues

Parent app reads `feeDemands` filtered by student. Shows 12 monthly rows of "Tuition Fee · ₹2,000". **No bus row appears at all** — the parent never sees a bus due, because no bus demand exists.

| Month | Before | After |
|---|---|---|
| April 2026 | Tuition ₹2,000 + Bus ₹1,000 = ₹3,000 due | Tuition ₹2,000 due |
| Each subsequent month | ₹3,000 due | ₹2,000 due |
| Annual total visible | ₹36,000 | ₹24,000 |

### A.4 — Fee collection behavior

Parent app payment endpoint allocates the paid amount against `demandId`s the parent ticks. Because no bus demand exists, the parent cannot pay bus fees through the app — there is nothing to pay against. Payment flow is otherwise identical (Razorpay Order → webhook → demand mark paid).

Admin-side manual cash receipt: same — the cash-receipt form lists demands; no bus demand appears for selection.

### A.5 — Receipt output (when parent pays April 2026)

Receipt PDF shows a single allocation row:

| # | Month | Fee Head | Amount (₹) |
|---|---|---|---|
| 1 | April | Tuition Fee | 2,000.00 |

Receipt total: ₹2,000.00. Concession is NOT itemized on the receipt — there is no separate "₹1,000 bus waived" line. The receipt is concession-transparent in Phase 2 (this is documented in the release note as additive treatment; Phase 5 may add a gross/net column).

### A.6 — Defaulter calculation

`feeDefaulters` doc is rebuilt by `syncDefaulterStatus()` from the unpaid `feeDemands` for this student. Bus contributes nothing because there are zero bus demands.

| Month elapsed unpaid | Before | After |
|---|---|---|
| April only | ₹3,000 defaulter | ₹2,000 defaulter |
| April–March (full year unpaid) | ₹36,000 defaulter | ₹24,000 defaulter |

### A.7 — Promotion behavior

Re-promote STU0099 forward into Class 8th / Section A (same class, same section — re-runs admission specs):

- Unified generator reads concession → still active → fullExempt for bus.
- Tuition demands re-written at gross (no change vs prior).
- Bus demands again NOT written — old bus demands (if any from a prior legacy run) **remain in place and are not auto-deleted**. See §4 caveat 1.
- Paid tuition demands are preserved at paid state via BUG-075 `preservePayment`.

Forward-promote to Class 9th: concession's `effectiveFrom` covers it, no `effectiveTo`, so still applies. Class 9th tuition + bus chart amounts are re-read fresh; bus demands again dropped.

### A.8 — Rollback behavior (Layer 1 — instant flag flip back to false)

After flipping `USE_UNIFIED_FEE_GEN = false`:

- The 12 tuition demands already in Firestore: **unchanged**. Parent still pays ₹2,000/month tuition.
- The zero bus demands: **still zero** in Firestore. Parent still sees no bus dues. **No automatic backfill of bus demands at gross.**
- Concession doc in `studentConcessions`: **unchanged** (still `status: active`), but inert — nothing reads it with `USE_UNIFIED_FEE_GEN=false`.
- Any future re-promote AFTER rollback: bus demands written back at gross ₹1,000/month because the legacy path doesn't read concessions.

If you want bus to start billing again after rollback: do nothing — just re-promote the student and the legacy generator will write bus demands at gross. To prevent that on future re-promote: revoke the concession or set `effectiveTo` to today's date before re-promoting.

---

## Scenario B — 10% tuition concession (`type: percent`, head-scoped, value: 10)

### B.1 — Concession record created

```
{
  "schoolId":         "SCH_xxx",
  "studentId":        "STU0099",
  "scope":            "head",
  "targetFeeHeadId":  "TUIT",
  "targetCategory":   null,
  "type":             "percent",
  "value":            10,
  "effectiveFrom":    "2026-04-01",
  "effectiveTo":      null,
  "reason":           "Sibling concession — older sibling STU0050",
  "approvedBy":       "SSA0001",
  "status":           "active",
  ...timestamps...
}
```

### B.2 — Generated fee demands

| | **Before** | **After** |
|---|---|---|
| Bus demands | 12 × ₹1,000 = ₹12,000 | 12 × ₹1,000 = ₹12,000 (unchanged — concession is head-scoped to TUIT) |
| Tuition demands | 12 × ₹2,000 = ₹24,000 | 12 × ₹1,800 = **₹21,600** (each demand reduced ₹200) |
| Total demand count | 24 docs | 24 docs |
| Annual billing total | ₹36,000 | **₹33,600** (saves ₹2,400) |

Each tuition demand (April 2026):
```
{
  ...
  "feeHead": "Tuition Fee", "feeHeadId": "TUIT",
  "period": "April 2026", "periodKey": "2026-04",
  "grossAmount": 2000,
  "netAmount":   1800,
  "concessionApplied": 200,
  "paidAmount": 0, "balance": 1800, "status": "unpaid"
}
```

Bus demand: unchanged from legacy, no `concessionApplied` field.

### B.3 — Parent app dues

| Month | Before | After |
|---|---|---|
| April 2026 | Tuition ₹2,000 + Bus ₹1,000 = ₹3,000 | Tuition ₹1,800 + Bus ₹1,000 = ₹2,800 |
| Each subsequent month | ₹3,000 | ₹2,800 |
| Annual total visible | ₹36,000 | ₹33,600 |

The parent app reads `balance` from the demand. Phase 2 parent app does not show "₹200 concession applied" — it just shows the lower due. The existing parent UI is concession-transparent (additive optional field is silently ignored).

### B.4 — Fee collection behavior

Parent pays ₹2,800 for April → allocator splits ₹1,800 against `DEM_STU0099_2026-04_TUIT` + ₹1,000 against `DEM_STU0099_2026-04_BUS`. Both demands move to status `paid` with `paidAmount = balance`.

Razorpay Order amount: ₹2,800 (not ₹3,000). The school receives ₹2,800 net of gateway fees.

If the parent attempts to overpay ₹3,000 by mistake: existing payment-amount-mismatch guards (BUG-050) block the overpayment; only the demand-sum amount can be charged.

### B.5 — Receipt output (when parent pays April 2026)

| # | Month | Fee Head | Amount (₹) |
|---|---|---|---|
| 1 | April | Tuition Fee | 1,800.00 |
| 2 | April | Bus Fee | 1,000.00 |

Receipt total: **₹2,800.00**. The tuition row shows the reduced amount; no separate "₹200 concession" line. Same concession-transparent treatment as Scenario A.

### B.6 — Defaulter calculation

| Month elapsed unpaid | Before | After |
|---|---|---|
| April only | ₹3,000 defaulter | ₹2,800 defaulter |
| April–March (full year unpaid) | ₹36,000 defaulter | ₹33,600 defaulter |

### B.7 — Promotion behavior

Re-promote forward into Class 8th / Section A:

- Unpaid tuition demands: re-written via merge at the same `demandId` — `netAmount` updates to ₹1,800, `balance` to ₹1,800 (if it wasn't already).
- **Paid** tuition demands (e.g., April–June already cleared at ₹2,000): preserved at ₹2,000 paid via BUG-075. The concession does NOT retroactively refund cleared months.
- Unpaid bus demands: unchanged at ₹1,000.

Forward-promote to Class 9th: concession still active (no `effectiveTo`); applies to whatever Class 9th tuition chart amount is. If Class 9th tuition were ₹2,500, the demand would be ₹2,250 (10% of ₹2,500 = ₹250 off).

### B.8 — Rollback behavior

After flipping `USE_UNIFIED_FEE_GEN = false`:

- 12 tuition demands at ₹1,800: **unchanged** in Firestore. Parent continues to be charged ₹1,800 (NOT auto-reverted to ₹2,000).
- Bus demands: unchanged.
- Concession doc: unchanged, inert.
- Future re-promote AFTER rollback: legacy path writes tuition demands at gross ₹2,000 — but only re-promotes any unpaid demands. **The unpaid ₹1,800 demands would get bumped back up to ₹2,000.** This is the rare case where rollback + re-promote changes data. Avoid unnecessary re-promote in the rollback window.

If you need to restore previous ₹2,000 billing immediately after rollback: re-promote the student. If you want to keep the ₹1,800 billing: do not re-promote and revoke the concession instead.

---

## Scenario C — Full fee waiver (`type: fullExempt`, scope: `all`)

### C.1 — Concession record created

```
{
  "schoolId":         "SCH_xxx",
  "studentId":        "STU0099",
  "scope":            "all",
  "targetFeeHeadId":  null,
  "targetCategory":   null,
  "type":             "fullExempt",
  "value":            0,
  "effectiveFrom":    "2026-04-01",
  "effectiveTo":      null,
  "reason":           "Management waiver — staff child policy",
  "approvedBy":       "SSA0001",
  "status":           "active",
  ...timestamps...
}
```

### C.2 — Generated fee demands

| | **Before** | **After** |
|---|---|---|
| Tuition demands | 12 × ₹2,000 = ₹24,000 | **0 demands** |
| Bus demands | 12 × ₹1,000 = ₹12,000 | **0 demands** |
| Total demand count | 24 docs | **0 docs** |
| Annual billing total | ₹36,000 | **₹0** |

Every spec from `buildAdmissionDemandSpecs` is matched by the `scope:all + fullExempt` concession → every spec is dropped → no demand docs are written for this student in this generation event.

### C.3 — Parent app dues

Parent app dues screen: **empty**. No outstanding fees. No payment prompts. No pending dues notifications.

### C.4 — Fee collection behavior

There is nothing to collect through any channel. Razorpay flow has nothing to trigger; manual cash-receipt form has no demands to select. If the operator tries to collect cash anyway, they would have to first record an "ad hoc fee" — out of scope.

### C.5 — Receipt output

No receipt is generated because no payment occurs. The student's receipt history is empty for the year.

### C.6 — Defaulter calculation

| Month elapsed unpaid | Before | After |
|---|---|---|
| April only | ₹3,000 defaulter | ₹0 — **not flagged as defaulter** |
| April–March (full year unpaid) | ₹36,000 defaulter | ₹0 — **not flagged as defaulter** |

`feeDefaulters/{schoolId}_STU0099`: either absent entirely, or present with `totalOutstanding: 0` and not appearing in the defaulter list views. The student does not show in the "Fee Defaulters" report for any month.

### C.7 — Promotion behavior

Re-promote forward into Class 8th / Section A: **0 new demand docs written.** No tuition, no bus.

Forward-promote to Class 9th: still scope-all + fullExempt → still 0 demands. The concession follows the student across class transitions until revoked or `effectiveTo` is set.

### C.8 — Rollback behavior

After flipping `USE_UNIFIED_FEE_GEN = false`:

- Zero demands in Firestore: **still zero**. The parent continues to see no dues.
- Concession doc: unchanged, inert.
- Future re-promote AFTER rollback: legacy path will write the full 24 demands (₹36,000 annual). **This is a large change.** Whatever the school's intent was in granting the waiver no longer holds at the billing layer.

If the school wants to truly maintain the waiver after rollback: do NOT re-promote. To preserve the waiver across the rollback period, treat re-promotion as a billing-impacting action and pause it for waived students. After re-instating the flag (if the rollback was temporary), re-promote will read the concession again and drop demands.

---

## 4. Cross-cutting notes — operator gotchas

These apply across all three scenarios. Read before activation.

1. **Concessions don't retroactively delete existing demands.** If a student already has unpaid demands written by the legacy path and you record a `fullExempt` concession, the existing demand docs **stay** in `feeDemands`. They will continue to show as defaulter until you either delete them in Firestore Console or re-promote (which will *not* write a replacement — fullExempt drops the spec, so the old doc just sits there). Solutions: (a) revoke the concession and use legacy collection, (b) manually delete the old demand doc, or (c) wait for Phase 4 mid-session reconciliation (auto-void unpaid future demands when a concession is added).

2. **Paid demands are never retroactively reduced.** BUG-075 `preservePayment` keeps any demand with `status in (paid, partial)` or `paidAmount > 0` at its original paid state. If the parent already paid ₹2,000 tuition for April, a later 10% concession does not refund ₹200. The concession only reduces FUTURE generation events.

3. **Chart-Save remains legacy in Phase 2 (Option A).** If you edit the fee chart (e.g., change tuition from ₹2,000 to ₹2,200) and click Save All Fees, the chart-save path writes new demands at the new gross amount **without applying any concession**. A student with an active 10% concession would see their unpaid tuition demands bumped to the gross ₹2,200, losing the concession reduction. The smart-confirm modal warns the operator before this happens. Phase 2.5 (chart-save shape convergence) resolves this; until then, after editing the chart, re-promote affected students to re-apply concessions.

4. **The 12-month picture assumes monthly frequency.** If a fee head is configured as yearly (`frequency: yearly`), exactly one demand is generated for the year. The concession math is identical (percent applies once, fixed deducts once, fullExempt drops the one demand).

5. **`effectiveFrom` is start-of-period inclusive; `effectiveTo` is null = no end.** A concession with `effectiveFrom: 2026-07-01` won't apply to April–June 2026 demands; tuition for those months bills at gross. An operator wanting the concession to apply to the whole session must back-date `effectiveFrom` to 2026-04-01.

6. **Rollback does NOT auto-revert demand data.** Layer 1 rollback flips the routing flag only. Demands written during the live window with concession reductions stay reduced after rollback. To revert demand data, follow §3 Layer 2 of the runbook (Firestore Console edits or delete-and-re-promote — but the latter only works if the rollback was *brief* and you re-flip-forward before re-promoting; otherwise re-promote writes gross).

7. **Manual / Bulk Generate / Recalc do NOT apply concessions in Phase 2.** Same as chart-save. Smart-confirm warns. Use Promotion to apply concessions to existing students.

---

## 5. Annual billing summary across all three scenarios

| Scenario | Gross annual | Concession annual save | Net annual billed | Reduction % |
|---|---|---|---|---|
| Baseline (no concession) | ₹36,000 | — | ₹36,000 | 0% |
| **A** — Bus exemption | ₹36,000 | ₹12,000 | ₹24,000 | 33.3% |
| **B** — 10% tuition concession | ₹36,000 | ₹2,400 | ₹33,600 | 6.7% |
| **C** — Full waiver | ₹36,000 | ₹36,000 | ₹0 | 100% |

---

## 6. Activation impact summary by dimension

| Dimension | Pre-cutover | Post-cutover (Scenario A active) | Post-cutover (B active) | Post-cutover (C active) |
|---|---|---|---|---|
| Demand count for student | 24 | 12 (bus dropped) | 24 (both reduced) | 0 (all dropped) |
| Parent app annual due | ₹36,000 | ₹24,000 | ₹33,600 | ₹0 |
| Razorpay charge per month | ₹3,000 | ₹2,000 | ₹2,800 | n/a |
| Receipt total per month | ₹3,000 | ₹2,000 | ₹2,800 | n/a |
| Defaulter total (if 0% paid) | ₹36,000 | ₹24,000 | ₹33,600 | ₹0 (not a defaulter) |
| Re-promote re-writes | All 24 demands | 12 tuition (no bus) | All 24 demands (tuition at ₹1,800) | 0 |
| Layer-1 rollback effect on this student | n/a | No demand data change | No demand data change | No demand data change |
| Re-promote AFTER rollback | All 24 demands at gross | Bus demands return at ₹1,000 | Tuition demands bumped to ₹2,000 | All 24 demands return at gross ₹36,000 |

---

*Document: 2026-05-30. Pre-activation reference. Numbers verified against shipped code at HEAD `d52eaf75`. Update if Phase 2.5 or Phase 3 changes the math.*
