# Phase D Cutover Gate — `proRateFees()` is not entitlement-aware

**Status:** DEFECT CONFIRMED. Not fixed — deliberately. See §5.
**Hard gate:** `USE_ENTITLEMENT_SERVICE_PRICING` must NOT be set `true` until this is closed.
**Origin:** Phase D implementation (2026-08-08); dedicated audit performed at operator request.
**Updated 2026-08-09** after operator UAT on live data — see §1A.

> ⚠️ **The "currently inert" claim in the original version of this document was only ever true of
> ONE of the three problems here.** The entitlement-erasure issue (§2) is genuinely inert while the
> flag is false. The two proration-semantics defects found during UAT (§1A) are **LIVE IN PRODUCTION
> RIGHT NOW** — they ride on Phase A's G1 fix, which is active regardless of any feature flag.
> Correcting that framing, because "inert" across the whole document would have been misleading.

---

## 1A. UAT findings — three inconsistent proration behaviours (2026-08-09)

Operator UAT on **STU0159 (Konda Reddy)**, tenant `SCH_D94FE8F7AD`, assigned mid-session in **August**
(8 of 12 academic months remaining). Real money, live production data.

| Action | Route rate | Billed | Prorates? | Correct? |
|---|---|---|---|---|
| **Assign** (North Circle) | ₹1,500 | **₹1,000** | ✅ yes — `1500 × 8/12` | ✅ correct |
| **Suspend** | — | **₹1,000** (unchanged) | ⚠️ yes, but with the *join* formula | ❌ wrong semantics |
| **Edit route while suspended** (→ South Circle) | ₹1,400 | **₹1,000** (held) | n/a — guard fired | ✅ correct (G1 guard proven) |
| **Resume** | ₹1,400 | **₹1,400** | ❌ **no** | ❌ over-bills by ~₹467 |

**One concept, three behaviours.** Phase A made suspend and resume *reach* billing at all — before it,
neither did anything, which was worse. But it did not make their proration semantics agree with
assignment, and UAT exposed that on real money.

### Finding U1 — suspension uses JOIN proration, not SUSPENSION proration

`proRateFees()` computes *"bill the months REMAINING from date X"* — the join semantic. Suspension needs
the opposite: *"bill only the months SERVED; stop billing from X onward."*

Suspending in August therefore bills for **August → March** (the months the student will **not**
travel) instead of **April → August** (what they used). Phase A's inline comment claimed it would
"shrink the balance to owed through `$from`". **It does not.** That comment is wrong and should be
corrected when this gate is closed.

Not visible in this UAT run because assign and suspend happened the same day, so both produced the same
factor. To reproduce: assign in April, suspend in August — the join formula yields `8/12`, correct
suspension proration yields `5/12`.

### Finding U2 — resume restores the FULL rate, never prorated

`Transport_service::resumeAssignment()` calls `createModuleFee()` with the full route amount and does
**not** prorate afterwards — unlike `assignStudent()`, which does. A student resumed in August is billed
the entire ₹1,400 instead of `1400 × 8/12 = ₹933.33`.

**This over-bills families and is live today.** It is the mirror image of U1: assignment prorates,
resume does not.

### Finding U3 — the `prorated` / `prorateFactor` flags never persist

Verified on the live demand document: `netAmount` and `balance` update correctly, but `prorated` reads
`undefined`. `Fee_firestore_txn::updateDemand()` canonicalises writes through a field allow-list that
does not include `prorated` or `prorateFactor`, so both are silently dropped.

**No financial impact** — nothing in the codebase reads either field (verified during Phase A) — but the
diagnostic flag intended to mark a prorated demand does not work, and Phase A's "reset stale prorated
flags" logic is therefore also a no-op. Either wire them through the allow-list or remove them; a field
that silently vanishes is worse than no field.

### What this adds to the gate

The redesign in §7 must now also settle, as ONE coherent model:

* **assign** → prorate to months remaining ✅ (already correct)
* **suspend** → bill months **served**, not months remaining (U1)
* **resume** → prorate to the remaining session, not the full rate (U2)
* **flags** → persist or delete (U3)

Fixing any of these individually would create a fourth parallel pricing path — the exact defect class
(G4/G12) this project exists to eliminate.

---

## 1. Every path that can invoke `proRateFees()` after a service fee is generated

All five are in `Transport_service.php`; all pass `feeType='Transport'`. **`Hostel.php` never prorates** — it calls `createModuleFee()` only, so hostel fees are unaffected by this defect entirely.

| # | Caller | Line | Runs `createModuleFee` first? | Exposure |
|---|---|---|---|---|
| 1 | `assignStudent()` | 2692 | **Yes** (Phase A reorder) | Discount written, then overwritten |
| 2 | `unassignStudent()` | 2724 | No | Overwrites a previously-discounted demand |
| 3 | `suspendAssignment()` | 2805 | No | Overwrites a previously-discounted demand |
| 4 | `changeRoute()` | 3014 | **Yes** (Phase A reorder) | Discount written, then overwritten |
| 5 | `changeSubscription()` | 3096 | **Yes** (Phase A addition) | Discount written, then overwritten |

**All five are affected** once `USE_ENTITLEMENT_SERVICE_PRICING` is on. In paths 1/4/5 the sequence is especially clear: Phase A deliberately moved `proRateFees` to run *after* `createModuleFee` (because the pre-Phase-A ordering meant the proration was silently clobbered). Phase D now writes a discounted `netAmount` in `createModuleFee` — and `proRateFees`, running immediately after, recomputes `netAmount` from **`grossAmount`**, discarding the entitlement deduction.

## 2. The mechanism, exactly

```php
$grossAmt = (float) ($d['grossAmount'] ?? $d['netAmount'] ?? 0);
$newNet   = round($grossAmt * $factor, 2);
```

`grossAmount` is the **pre-concession** figure. Phase D stores the concession in `netAmount` (and, when non-zero, in `concessionApplied`/`discountAmount`). Prorating from gross therefore erases the discount.

Worked example — ₹1,200 fare, 25% transport concession, suspended at month 8 (factor 8/12):

| | netAmount |
|---|---|
| After `createModuleFee` (Phase D) | 900 |
| After `proRateFees` (today's code) | **800** ← discount gone; correct answer is 600 |

The student is **over-billed**, and no report surfaces it because `netAmount` looks like a plausible prorated figure.

## 3. Why the naive fix is wrong — the load-bearing reason for the gross base

The obvious patch is "prorate `netAmount` instead of `grossAmount`". **That breaks idempotency and must not be done.**

`proRateFees()` writes `netAmount`, `balance`, `prorated`, `prorateFactor` — it never writes `grossAmount`. Reading from `grossAmount` is what makes repeated invocation safe: every call recomputes from the same immutable base. Switching the base to `netAmount` makes each call compound on the previous one:

> suspend (×0.667) → 1200 → 800; then unassign (×0.667) → 800 → **533**

Two legitimate operator actions would silently halve the bill. The gross base is deliberate and load-bearing, not an oversight.

**A correct narrow fix does exist**, using the deduction Phase D now persists:

```php
$deduction = (float) ($d['concessionApplied'] ?? 0);
$newNet    = round(($grossAmt - $deduction) * $factor, 2);
```

Idempotent (both inputs immutable under proration) and entitlement-aware. It is recorded here as *viable*, not as the recommendation — see §5.

## 4. Correct commercial behaviour

A service concession is granted against a service period. If the period is curtailed, the benefit curtails proportionally — so **both the charge and the concession prorate**. For the example above: charge 800, concession 200 → net **600**.

For a *fixed* concession this is the point of judgement. ₹200 off an annual fare, used 8 of 12 months, should relieve **₹133**, not the full ₹200 against a partial period. Prorating gross-minus-deduction produces exactly that, and matches mature K-12 ERP behaviour, where service charges recover a real per-student cost (a seat, a bed) and concessions against them are period-proportional.

## 5. Recommendation — DEFER to certification, do not fix now

**Zero production risk today.** `USE_ENTITLEMENT_SERVICE_PRICING` is `false`, so no service demand carries an entitlement deduction, so there is nothing for proration to erase. The defect cannot manifest.

Four reasons a narrow patch now is the wrong move:

1. **It would be the third independent pricing implementation.** Deduction logic already exists in `Fee_generation_service::_applyDeductions()` (Phase C, frozen) and `Service_entitlement_pricing` (Phase D). Teaching `proRateFees()` about entitlements adds a third — directly contrary to the "one action, one canonical implementation" principle enforced throughout this project, and the exact divergence class (G4/G12) it exists to close.

2. **Three structural gaps converge on this one function**, and fixing them separately means patching money-handling code three times:
   - **entitlement-awareness** (this document)
   - **fine-awareness** — `LATE_FEE_PENALTY_MODULE_PREREQUISITES.md` Blocker 2
   - **credit notes on over-collection** — baseline Phase C / AC4, already instrumented via `FEE_PRORATE_OVERCOLLECTION`
   
   That they all land on `proRateFees()` is strong evidence this is **one structural problem**, not three defects.

3. **The frozen baseline already supersedes proration.** §0A.2's canonical regeneration strategy is *archive-then-regenerate*, reusing the single generation path — which is entitlement-aware by construction and needs no parallel proration math. A narrow patch is work that regeneration would discard.

4. **The operator's certification plan already has the slot** — step 6, "fix only verified defects", after the shadow cycle makes divergences observable against real data.

## 6. Cross-module consistency (verified)

| Module | Status |
|---|---|
| **Transport** | The 5 affected paths (§1). Inert while flag off |
| **Hostel** | **Unaffected** — never calls `proRateFees()` |
| **Admission / Promotion** | Unaffected — tuition path never calls `proRateFees()`; only the 5 Transport sites do |
| **Accounting** | Unaffected — journals post at *collection* against receipts, not from `netAmount` |
| **Parent / Teacher App** | Read `feeDemands` document-level; field shape unchanged. Would display the over-billed figure if the flag were flipped before this is fixed — which this gate prevents |
| **Student 360 / Action Registry** | Not built; no contract affected |
| **Reporting** | `gross − discount == net` holds today. It would **break** under the flag, since `netAmount` would be re-derived from gross while `concessionApplied` still reads non-zero — an internally contradictory demand document |

The reporting row is the sharpest consequence and the clearest reason this must gate the cutover rather than follow it.

## 7. Exit criteria

Close this gate by resolving service-fee proration **once**, as a single coherent model covering **all six** requirements now on it:

| # | Requirement | Source | Live today? |
|---|---|---|---|
| 1 | Entitlement-aware — stop erasing Phase D discounts | §2 | No — inert while flag false |
| 2 | Fine-aware | `LATE_FEE_PENALTY_MODULE_PREREQUISITES.md` Blocker 2 | No |
| 3 | Credit notes on over-collection | baseline Phase C / AC4 | Observable via `FEE_PRORATE_OVERCOLLECTION` |
| 4 | **Suspension bills months SERVED, not remaining** | **U1** | **YES** |
| 5 | **Resume prorates instead of restoring the full rate** | **U2** | **YES — over-bills** |
| 6 | `prorated` / `prorateFactor` persist, or are removed | U3 | Yes (diagnostic only) |

Prefer convergence on the baseline §0A.2 archive-and-regenerate path over a fourth parallel calculation.

**Re-verify after the redesign:** all five Transport call sites; idempotency under repeated invocation (the §3 compounding trap); and the four-step UAT sequence in §1A — assign mid-session → suspend → edit route while suspended → resume — checking the arithmetic at each step, not merely that "the number moved".

**Scheduling note.** U1 and U2 are live production billing errors and are **not** gated by `USE_ENTITLEMENT_SERVICE_PRICING`; they affect any school using transport suspend/resume today. That does not justify a rushed patch — the six requirements above must still land together — but it does mean this gate is no longer purely a pre-cutover concern and should be scheduled on its own merits rather than waiting on the Phase D cutover.
