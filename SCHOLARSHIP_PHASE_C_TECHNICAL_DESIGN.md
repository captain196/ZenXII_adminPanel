# PHASE C — SCHOLARSHIP TECHNICAL DESIGN (READ-ONLY)

**DESIGN ONLY. No production code written. No live data touched. No commit, push or deploy.**
**`USE_ENTITLEMENT_BILLING` = FALSE · `REAL_ADMISSION_READY` = FALSE**
**Date:** 2026-08-17 · **Branch:** `integration/phase2` @ `758c924`

Implements the decisions already approved: fixed ⇒ `whole_session`, percentage ⇒ `per_period`,
service heads excluded unless explicitly scoped, P3D-F2/F3/F4 authorised.

---

## 1. Schema — `scholarshipAwards`

Three additive fields. **No migration, no backfill** (0 live awards).

| Field | Type | Allowed values | Required | Meaning |
| --- | --- | --- | --- | --- |
| `basis` | string | `whole_session` · `per_period` | **yes** (new writes) | Whether `amount` is one total award for the session, or an amount that applies to each generated period |
| `session` | string | `YYYY-YY` | **yes** (new writes) | The academic session the award belongs to (P3D-F4) |
| `scope` | string | `academic` · `all` · `category:<c>` · `head:<name-or-id>` | **yes** (new writes) | Which fee heads are eligible |

Existing fields (`scholarship_id`, `student_id`, `amount`, `status`, `awarded_date`, `awarded_by`,
`class`, `section`) are unchanged.

**Deliberately reusing the entitlement scope vocabulary** (`all` / `category:` / `head:`) rather than
inventing a second one — `Fee_entitlement_reader::appliesTo()` already parses exactly these, so the
scholarship path can share the matcher instead of growing a parallel implementation. `academic` is the
one addition, and it is the **default**.

### Validation (refuse, never coerce)

| Rule | Reason |
| --- | --- |
| `basis` ∈ the two values | An unknown basis has no defined arithmetic |
| percentage award ⇒ `basis` MUST be `per_period` | A percentage is inherently a rate per period |
| fixed award ⇒ `basis` MUST be `whole_session` | Your decision |
| `session` matches `/^\d{4}-\d{2}$/` | P3D-F4 |
| `amount > 0` | A zero award is not an award |
| `scope` parses to one of the four shapes | An unparseable scope must match **nothing**, fail-closed |
| award **cannot** be created for a session with no fee chart | Otherwise eligible gross is 0 and the award silently does nothing |

**Legacy/undated awards** (none exist today) would be read as `basis = whole_session`, `scope =
academic`, `session` absent ⇒ **not applicable to any session** — the fail-closed reading. They bill
nothing until an operator states the session.

---

## 2. The percentage base — P3D-F3

**Today (wrong):** `Fee_management.php:1350-60` sums **all** `feeHeads[].amount` regardless of
`frequency`, mixing monthly and annual. Real chart: 500 + 50 + 200 + **1000 (annual)** = **1750**.

**Corrected:**

```
percentageBase = Σ feeHeads[].amount WHERE frequency = 'monthly' AND head is in scope
awardAmount    = round(percentageBase × value / 100, 2)      // basis = per_period
```

Same chart, 10%, scope `academic` (Transport excluded as a service head): base = 500 + 50 = **550**
⇒ award **₹55 per period**. Not ₹175.

Annual heads are excluded from the *percentage base* because the award is per-period; an annual head is
billed once and would distort every month's rate.

---

## 3. Eligible demand set

The scholarship acts on the **canonical spec set** the generator already builds, filtered by:

| Excluded | Why |
| --- | --- |
| Head is fully exempt (`-1` sentinel / `fullExempt` entitlement) | Nothing left to discount; already skipped at `Fees:4891` |
| Head is **out of scope** (see §4) | Your approved rule |
| Demand is not `unpaid` | Never re-price a paid or partial demand — the existing `_recalculateStudentUnpaidDemands` rule |
| Demand carries `concessionApplied` from a *different* authority | Preserves the existing fence |

**Eligible gross** = Σ `grossAmount` over the surviving specs, for the award's `session`.

---

## 4. Scope model

| `scope` | Matches |
| --- | --- |
| `academic` **(default)** | every head **except** service categories |
| `all` | every head **including** service — must be chosen deliberately |
| `category:transport` etc. | only heads in that category |
| `head:<name-or-id>` | one head, matched by `feeHeadId` **or** `feeHead` name |

**Service classification — and an honest gap.** Service categories are `transport`, `hostel`, `meals`.
Three signals exist and none is sufficient alone:

1. `feeCategories` collection — **0 documents live**, so `_resolveFeeCategory()` falls through.
2. Its name-substring fallback (`stripos($feeHead,'transport') || 'bus'`) — heuristic.
3. Canonical specs carry **no `category` field at all** (`Fee_lifecycle:377-402`) — only
   `createModuleFee()` sets one.

**Design decision:** classification lives in **one** helper on the canonical path, consulted in this
order — explicit `feeCategories` mapping → the head's `category` if present → name heuristic → default
`academic`. It is a single function so the rule cannot drift, and the heuristic is the *last* resort,
not the first.

**⚠ This is the one place the design is weaker than I would like**, and I am flagging it rather than
hiding it: with `feeCategories` empty, a head named "Bus Fee" is classified by a substring match. If you
want service exclusion to be *robust* rather than heuristic, the real fix is populating `feeCategories`
(or adding `category` to chart heads) — a data/config task, not code. **I have not assumed either.**

---

## 5. The allocation algorithm

### 5.1 `per_period` (percentage)

```
for each eligible spec S:
    share(S) = awardAmount × ( S.gross ÷ Σ gross of eligible specs in S.period )
    deduction(S) += min(share(S), S.gross)
```

Applied **within each period**, exactly as today — correct once §2 fixes the base.

### 5.2 `whole_session` (fixed) — eligible-fee-weighted

```
E        = eligible spec set for the award's session          (§3)
totalE   = Σ S.gross for S in E
effective = min(awardAmount, totalE)                          // cap (§6)

for each S in E, in deterministic order (periodKey ASC, feeHeadId ASC):
    raw(S)   = effective × ( S.gross ÷ totalE )
    share(S) = round(raw(S), 2)

// deterministic remainder: the LAST spec absorbs the rounding drift
share(last) += effective − Σ share(S)
```

**Properties this guarantees:**

- Σ share = `effective`, exactly — never `award ± n paise`.
- No share exceeds its own gross, because share is a fraction of gross scaled by ≤ 1.
- The order is deterministic (`periodKey`, then `feeHeadId`), so the same inputs always place the
  remainder on the same demand — generation and recalculation cannot disagree by a paisa.
- **Computed from GROSS every run**, never from a previous net ⇒ repeated recalculation is idempotent.

### 5.3 Why weighted, not `/12`

`/12` fails on every real case: unequal periods (April carries the annual head), mid-session students
(fewer than 12 periods), and partial generation. Weighting keeps the *effective discount rate* constant
across the year, which is what "a ₹6,000 scholarship" means to a parent.

---

## 6. Cap and remainder

- **Cap:** `effective = min(award, totalEligibleGross)`. No demand can go below zero and the student
  can never receive credit.
- **Unused remainder** = `award − effective`, when the award exceeds eligible fees. It is **recorded on
  the award, not silently discarded** — proposed fields `allocatedAmount` and `unallocatedAmount`, so a
  school can see that ₹2,700 of a ₹6,000 award had nothing to attach to. **This is the one place I am
  proposing behaviour you have not explicitly ruled on**; the alternative is to drop it silently, which
  I do not recommend.

---

## 7. Session applicability — P3D-F4

`readScholarshipAwards()` gains a session filter: an award applies **only** to the session named on it.
Today it filters `studentId` + `status` only, so a 2025-26 award still discounts 2026-27.

**Note the deliberate asymmetry with SRNO:** an SRNO is **school-scoped and session-independent** — it
follows the student across sessions. A scholarship award is **session-scoped**, because it is a
financial grant for a particular year. These are different kinds of object and must not be given the
same scoping rule.

---

## 8. Generation ≡ recalculation (P3D-F2)

The divergence exists because generation reads `_scholarship` and recalculation does not.

**Both must call one function** — `allocateScholarship(specSet, award) → map[demandId ⇒ share]` — on the
canonical path, because a `whole_session` allocation needs the **complete** eligible spec set and a
per-month engine structurally cannot see it.

| Path | Today | Design |
| --- | --- | --- |
| Generation | applies `_scholarship` per month | one allocation over the full session set |
| Recalculation | drops it | same allocation, same inputs, same result |

This is the concrete reason the scholarship correction and the B3 routing converge: the allocator must
live at the canonical boundary. **Bulk generation** gets it for free, since it uses the same path.

---

## 9. Worked examples

Real chart: Tuition 500/mo, Library 50/mo, Transport 200/mo (**service**), Admission 1000/annual.
Scope `academic` ⇒ eligible = Tuition + Library + Admission. Full year eligible gross =
(550 × 12) + 1000 = **₹7,600**.

### A — full-year student, ₹6,000 `whole_session`

| Period | Eligible gross | Share |
| --- | --- | --- |
| April | 1,550 (550 + 1,000 annual) | 6,000 × 1,550/7,600 = **1,223.68** |
| May–March (11 ×) | 550 | 6,000 × 550/7,600 = **434.21** each |
| **Total** | **7,600** | **6,000.00** (remainder on the last period) |

Transport stays **₹200/month, undiscounted**. *Today this award zeroes every month — a ₹6,000 award
wiping a ₹10,000 bill.*

### B — mid-session, joins October (6 periods), ₹6,000 `whole_session`

Eligible = 550 × 6 = **₹3,300**. `effective = min(6000, 3300) = 3,300` ⇒ every eligible head goes to
**₹0**; `unallocatedAmount = ₹2,700` **recorded**, not silently dropped. Transport still billed.

### C — award ≈ eligible: ₹7,500 vs ₹7,600

April 7,500 × 1,550/7,600 = **1,529.61** → pays 20.39; each other month **429.28** → pays 120.72.
Total allocated exactly **7,500.00**.

### D — percentage, 10% `per_period`

Base = 550 (monthly academic heads only) ⇒ **₹55/period**. April: 55 × (500/1,550) off Tuition, etc.
*Today the base would be 1,750 ⇒ ₹175.*

---

## 10. Test matrix — your 23, mapped

| # | Requirement | Test |
| --- | --- | --- |
| 1 | percentage uses correct monthly base | `test_pct_base_is_monthly_only` |
| 2 | annual heads don't contaminate the base | `test_pct_base_excludes_annual_heads` |
| 3 | fixed ⇒ whole_session | `test_fixed_award_is_whole_session` |
| 4 | percentage ⇒ per_period | `test_percentage_award_is_per_period` |
| 5 | whole_session not multiplied across months | `test_whole_session_total_equals_award` |
| 6 | unequal periods weighted | `test_weighted_allocation_unequal_periods` |
| 7 | annual head in April | `test_april_annual_head_weighting` |
| 8 | mid-session | `test_mid_session_eligible_set` |
| 9 | award > eligible capped | `test_award_capped_at_eligible_gross` |
| 10 | unused award deterministic | `test_unallocated_remainder_recorded` |
| 11 | service excluded by default | `test_transport_hostel_meals_excluded` |
| 12 | explicit service scope applies | `test_explicit_category_transport_applies` |
| 13 | exempt heads excluded | `test_exempt_head_excluded` |
| 14 | no cross-session bleed | `test_award_scoped_to_its_session` |
| 15 | generation == recalculation | `test_generation_equals_recalculation` |
| 16 | recalc == recalc | `test_repeated_recalculation_stable` |
| 17 | regeneration stable | `test_regeneration_stable` |
| 18 | bulk stable | `test_bulk_generation_matches_single` |
| 19 | rounding deterministic | `test_rounding_sums_exactly_to_award` |
| 20 | no double deduction | `test_scholarship_and_concession_do_not_stack_twice` |
| 21 | entitlement/concession unchanged | existing Phase 2 / 3B / 3C suites must stay green |
| 22 | no accounting duplication | `test_no_accounting_side_effects` |
| 23 | no duplicate demands | `test_demand_ids_unchanged_by_allocation` |

**Golden-master impact:** `test_GM_scholarship_is_spread_proportionally_every_month` and
`test_P3D_F2_recalc_silently_drops_the_scholarship...` characterise behaviour you have declared
financially incorrect. Per your §12 they will be **retired with an explicit reason**, not preserved. The
other 10 golden-master tests and all 12 P3D-F1 tests are unaffected. A **new** golden master is captured
after implementation.

---

## 11. Open items before implementation

| # | Item | Status |
| --- | --- | --- |
| 1 | **Unallocated remainder** — record on the award (`allocatedAmount` / `unallocatedAmount`) vs drop silently | **My proposal; you have not ruled on it.** I recommend recording. |
| 2 | **Service classification robustness** (§4) — `feeCategories` is empty, so exclusion falls back to a name heuristic | Flagged. Populating `feeCategories` is a data task; I have assumed nothing. |
| 3 | Whether `scope: academic` should be the default for awards created by existing UI | Proposed default; confirm. |

Everything else in your §8/§10 list is fully specified above.

---

## 12. State

| Item | Value |
| --- | --- |
| Production code changed | **None** |
| Tests | **1806**, unchanged — no tests added or altered |
| Baseline / new failures | **10 / 0** |
| Live data | **None touched** — 0 scholarship awards, 150 legacy students untouched |
| Rules / indexes | **Untouched** · `firestore.rules` baseline `b2e78398`, concurrent work left alone |
| RTDB | **None** |
| `USE_ENTITLEMENT_BILLING` | **false** |
| `REAL_ADMISSION_READY` | **FALSE** |
| SRNO-F2 / SRNO-F3 | registered, unimplemented |
| Commit / push / deploy | **NO / NO / NO** |

**Next:** your ruling on the three items in §11, then Phase D implements this as one controlled change
and captures the corrected golden master.
