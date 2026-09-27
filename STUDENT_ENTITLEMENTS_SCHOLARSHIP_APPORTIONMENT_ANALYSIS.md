# SCHOLARSHIP APPORTIONMENT — READ-ONLY ANALYSIS

**STATUS: STOPPED for review, per your safety requirement #8.**
**The existing code and data do NOT contain enough information to determine the rule safely.**
**No production code changed · no live data · no flag · no commit, push or deploy**
**Date:** 2026-08-17 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Why this stops here

Your instruction: *"Stop for review if the existing code/data does not provide enough information to
safely determine the rule."*

It does not — and the reason is more specific than "the award has no period field". Tracing found that
**ZenX already has two different scholarship amount derivations with two different implicit periods**,
and a third defect that corrupts the base of one of them. A single apportionment rule applied on top of
that would fix one case and break another.

Critically: **`/12` would be actively wrong.** For percentage scholarships the current per-month
behaviour is *correct*, and dividing by 12 would under-discount those students by 12×.

Three new findings, all read-only, all in §3.

---

## 2. The 14 questions, answered from source

| # | Question | Answer from the code |
| --- | --- | --- |
| 1 | What is an applicable scholarship period? | **Undefined for an award.** The *policy* (`feeScholarships`) carries `academic_year = session_year` (`Fee_management.php:1210`). The *award* (`scholarshipAwards`) carries **no period, no session, no frequency** — only `amount`, `class`, `section`, `awarded_date`, `status` (`:1372-81`). |
| 2 | How are academic months / fee periods determined? | `Fees::ACADEMIC_MONTHS` (`:4583`) — April→March, 12 months. `_buildPeriodKey()` (`:4700`) maps a month name to `YYYY-MM` via `_resolveCalendarYear()`. Annual heads are merged into **April** (`:4818-22`). |
| 3 | Behaviour when only some months are generated? | **Undefined and currently unsafe.** Generation accepts a `months` subset (`:5165-74`); `_generateDemandsForMonth()` is called once per month and applies the **full award inside each one**. Generating 3 months applies the award 3 times. |
| 4 | Mid-session student? | No handling exists. Nothing reads `admissionDate`; a mid-session student simply has fewer months generated, so under the current rule they receive *fewer* full-award applications — arbitrary, not prorated. |
| 5 | Fee heads with different amounts? | Handled **within** a month: `$proportion = $amount / $monthTotal` (`:4937`), so a bigger head absorbs a bigger share. Never **across** months. |
| 6 | How should the annual award be divided? | **This is the open decision.** See §4 — and note that "equally across 12" breaks the percentage case (§3.1). |
| 7 | Award exceeds total eligible fee? | Per head only: `if ($discountAmount > $amount) $discountAmount = $amount;` (`:4942`). There is **no** cap against the *total* eligible fee, so an oversized award simply zeroes every head of every month (see Example A). |
| 8 | Multiple eligible fee heads? | Proportional within the month, then clamped per head. |
| 9 | Yearly fees? | Merged into April's bucket, so they compete for the same monthly award share — an annual head can absorb most of April's award. |
| 10 | Transport / hostel / service scope? | **No scope at all.** `_getStudentDiscounts()` returns one `_scholarship` aggregate and `_generateDemandsForMonth` spreads it across **every** head in the month, transport included. This directly contradicts the **F2 rule you approved** for entitlements (blanket concessions must not touch service heads). |
| 11 | Exempted fee head? | Excluded first — `if (($discountMap[$feeHead] ?? 0) === -1) continue;` (`:4891`) — so an exempt head is skipped and `$monthTotal` still counted it (`:4829` skips it too). Consistent. |
| 12 | Partial / mid-session generation? | Same as 3 — the award is re-applied per generation call. |
| 13 | What data must reach the canonical generator? | The award **amount**, its **period basis** (per-month vs whole-period), the **set of periods** it covers, and its **scope** (which heads are eligible). Only `amount` exists today. |
| 14 | Does the schema need more information? | **YES.** The fixed-amount case is undecidable from existing data, and the percentage case's base is currently computed incorrectly (§3.1). |

---

## 3. Three findings that block a safe rule

### 3.1 — P3D-F3 (HIGH): the percentage award base mixes monthly and annual heads

`award_scholarship()` computes a percentage award from the fee chart (`Fee_management.php:1350-60`):

```php
foreach ((array) ($feeStructDoc['feeHeads'] ?? []) as $h) {
    $totalFee += floatval($h['amount'] ?? 0);          // ← every head, any frequency
}
$amount = round(($totalFee * $scholValue) / 100, 2);
```

But chart heads carry an explicit **`frequency`**. Real data from the testing tenant
(`SCH_B56BB9A401_2026-27_Class 9th_Section A`, read-only):

| Head | Amount | Frequency |
| --- | --- | --- |
| Tuition Fee | 500 | monthly |
| Library Fee | 50 | monthly |
| Transport Fee | 200 | monthly |
| Admission Fee | 1000 | **annual** |

`totalMonthlyFee = 750`, `totalAnnualFee = 1000` — the document maintains them **separately**. The loop
sums them anyway: `500+50+200+1000 = 1750`.

A 10% scholarship therefore stores **₹175**, which is neither 10% of a month (₹75) nor 10% of the year
(₹10,000 → ₹1,000). It is a meaningless base.

**This matters for your decision:** for a *correctly computed* percentage award the amount is inherently
**per-month**, and applying it every month is right. Dividing it by 12 would be wrong. The bug is in the
base, not in the monthly application.

### 3.2 — the fixed-amount award has no definable period

`$amount = $scholValue` or an operator-typed figure (`:1345`, `:1362`). Nothing records whether ₹12,000
means *for the year* or *per month*. **This cannot be resolved from existing data** — only you can say
what the schools mean, or the schema must start recording it.

### 3.3 — P3D-F4 (MEDIUM): awards are not session-scoped

`readScholarshipAwards()` (`Fee_firestore_txn.php:1194`) filters on `studentId` + `status == 'active'`
**only** — no session, no academic year. The *policy* is session-scoped; the *award* is not. A student
awarded in 2025-26 keeps receiving that discount in 2026-27 until someone revokes it manually.

---

## 4. The rule I propose — for your approval, not implemented

Stated plainly:

> **A scholarship award has an explicit basis and an explicit eligible scope.**
>
> 1. **Basis.** Every award records whether its amount is **`per_period`** (applies to each generated
>    period) or **`whole_session`** (a single amount for the academic year).
> 2. **A `whole_session` award is apportioned by eligible fee weight, not by a fixed divisor.** Each
>    demand receives `award × (that demand's eligible gross ÷ the student's total eligible gross for the
>    session)`. Weighting by amount — not `/12` — is the only rule that stays correct when heads differ,
>    when annual heads exist, and when a student joins mid-session.
> 3. **Eligible gross** excludes exempt heads and, per your approved F2 rule, **excludes service
>    categories** (transport, hostel, meals) unless the award is explicitly scoped to them.
> 4. **The total award can never exceed total eligible gross**, and no single demand may go below zero.
> 5. **Percentage awards are computed against `totalMonthlyFee`** and are `per_period` by construction —
>    which makes today's monthly application correct once §3.1 is fixed.
> 6. **Awards are session-scoped**, matching the policy's `academic_year`.

**Why weighted rather than equal division:** with the real chart above, April carries ₹1,750 of fees and
May carries ₹750. Equal division gives April and May the same discount, so the student's April bill is
proportionally far less relieved. Weighting keeps the *effective discount rate* constant across the
year, which is what "a ₹6,000 scholarship" means to a parent.

---

## 5. Three worked examples (real chart: 500 + 50 + 200 monthly, 1000 annual)

Total eligible gross for a full year = (750 × 12) + 1,000 = **₹10,000**.
Under your approved F2 rule, Transport (₹200/mo = ₹2,400) is a **service** head and is excluded unless
explicitly scoped, so eligible gross = **₹7,600**.

### Example A — full-year student, ₹6,000 whole-session award

| | April | May … March (each) | Year total |
| --- | --- | --- | --- |
| Eligible gross | 1,350 (550 + 1,000 annual) | 550 | 7,600 |
| **Current** | award 6,000 spread over 1,750 → every head clamps → **0** | **0** | student pays **₹2,400** (transport only) — a ₹6,000 award wiped a ₹10,000 bill |
| **Proposed** | 6,000 × (1,350 ÷ 7,600) = **1,066** off | 6,000 × (550 ÷ 7,600) = **434** off | exactly **6,000** off |

### Example B — mid-session student, joins October (6 months), ₹6,000 whole-session award

| | Oct–Mar (each) | Total |
| --- | --- | --- |
| Eligible gross | 550 | 3,300 |
| **Current** | full 6,000 applied每 month → every head zeroed | pays **0** on 3,300 of fees |
| **Proposed** | award **capped at eligible gross 3,300**, spread 6,000×(550÷3,300) → clamped | student pays **0**, and the **₹2,700 unusable balance is reported, not silently discarded** |

### Example C — award ≈ eligible fees (₹7,500 award, ₹7,600 eligible)

| | Proposed |
| --- | --- |
| Each ₹550 month | 7,500 × (550 ÷ 7,600) = **542.76** off → pays ₹7.24 |
| April (₹1,350) | 7,500 × (1,350 ÷ 7,600) = **1,332.24** off → pays ₹17.76 |
| Year | pays ₹100 of academic fees + ₹2,400 transport |

Rounding is absorbed on the **final** period so the total is exactly the award — never `award ± n paise`.

---

## 6. Generation and recalculation producing identical results

The reason they diverge today (P3D-F2) is that generation reads the `_scholarship` aggregate and
recalculation does not. Under the proposed rule both must derive the per-demand share from the **same
inputs**: award amount + basis + the student's total eligible gross for the session.

That total is **not** knowable from one month's chart slice, which is why the current per-month engine
cannot express it. Concretely, the allocation must be computed **once per student per session** and
then applied per demand — and the natural place for that is the canonical generator, which already sees
the whole spec set at once. **This is the strongest argument yet that the scholarship correction and the
B3 routing belong in the same design**, even though you have (rightly) sequenced them separately.

Stability follows automatically: the share is always computed from **gross**, never from the previous
net, so repeated recalculation is idempotent — the same property already proven for concessions.

---

## 7. Test impact

| Test | Impact |
| --- | --- |
| `test_GM_scholarship_is_spread_proportionally_every_month` | **Must change.** It characterises the behaviour you have declared wrong. It would be re-pointed at the corrected rule and the old expectation recorded as retired, exactly as the two P3D-F1 tests were. |
| `test_P3D_F2_recalc_silently_drops_the_scholarship_that_generation_applied` | **Must change** — it asserts the divergence being fixed. |
| Everything else in the golden master (10 tests) | **Unchanged** — discount vocabulary, exempt-head skipping, clamping, gross-derivation, April merge, dedupe, the concession fence, no-delete-on-removed-head. |
| `Phase3dF1RecalcParameterFixTest` (12) | **Unchanged** — except `test_F1_12`, which asserts `_scholarship` is absent from the recalc engine and will need re-pointing once recalculation becomes scholarship-aware. |
| Concession / entitlement suites (Phase 2, 3B, 3C) | **Unchanged** — scholarship does not touch those paths. |
| **New tests required** | basis `per_period` vs `whole_session`; weighted apportionment across unequal months; annual-head-in-April; mid-session capping; award > eligible (with the reported remainder); service-scope exclusion per F2; generation == recalculation; repeated recalculation stable; rounding lands exactly on the award; session scoping (P3D-F4); percentage base uses `totalMonthlyFee` (P3D-F3). |

---

## 8. What I need before writing any code

Four answers. Three are business decisions; one is a schema authorisation.

1. **Fixed awards — what does the number mean?** Per month, or for the session? (§3.2 — undecidable from
   data.) My proposal: record it explicitly as `basis` and default **existing/undated awards to
   `whole_session`**, since that is what your decision said an award *is*.
2. **May I add `basis`, `session` and `scope` to `scholarshipAwards`?** Additive fields only, no
   migration — there are **0 live awards**, so nothing needs backfilling.
3. **Does the F2 service rule apply to scholarships?** i.e. does a plain scholarship discount transport?
   My proposal: **no**, consistent with the concession rule you approved — but it is your call, and it
   changes the numbers in §5.
4. **P3D-F3 — may I fix the percentage base** to use `totalMonthlyFee` rather than the mixed sum? This is
   a real financial correction and needs its own explicit yes.

**Nothing is mispriced today: 0 live scholarship awards, 0 live concessions, 0 live entitlements.**

---

## 9. Status

| Item | State |
| --- | --- |
| **Proven** | ZenX has two award derivations with different implicit periods; percentage base is corrupt (P3D-F3); awards are not session-scoped (P3D-F4); scholarship ignores service scope; the full award is re-applied per generated month |
| **Changed** | **Nothing.** Read-only analysis |
| **Not changed** | all production code, all flags, all live data, rules, indexes |
| **Registered defects** | P3D-F2 (open, authorised for combined fix) · **P3D-F3 (NEW, HIGH)** · **P3D-F4 (NEW, MEDIUM)** |
| **Business decisions** | `studentDiscounts` = C (accepted) · scholarship = annual + unfunded (accepted) · **4 open questions in §8** |
| Tests | **1795**, unchanged — no tests added or altered by this analysis |
| Baseline / new failures | **10 / 0** |
| Live data | **None touched.** 0 awards, 0 concessions, 0 entitlements, 1 `studentDiscounts` doc untouched |
| Rules / indexes | **Untouched.** `firestore.rules` baseline remains `ae0da664` (concurrent Staff-Lifecycle work, not mine) |
| RTDB | **None** |
| Commit / push / deploy | **NO / NO / NO** |
| `USE_ENTITLEMENT_BILLING` | **false** |
| `REAL_ADMISSION_READY` | **FALSE** |

**Exact next phase:** answer the four questions in §8 → I implement the scholarship correction
(apportionment + P3D-F2 + P3D-F3 + P3D-F4) as one controlled change with the tests in §7 → capture a NEW
golden master against corrected behaviour → **only then** design the B3 five-endpoint routing.

**Separately noted and recorded:** your requirement that **SRNO must work anywhere Fees accepts a
Student ID** is captured as a standing requirement (memory: `fees-srno-lookup-requirement`). It is not
part of this phase; I have flagged that it should be co-ordinated with the B3 routing work so the Fees
endpoints are not reworked twice.
