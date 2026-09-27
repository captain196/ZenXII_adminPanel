# PHASE 19B — FEES FINAL COMPLETION: FROZEN-FORMULA FIX + MODULE LATE-FEE PREPARATION

**Date:** 2026-08-15 · **Branch:** `integration/phase2` · **Status:** IMPLEMENTED, UNCOMMITTED, NOT DEPLOYED
**Scope honoured:** Fees only. No Attendance / Examination / Results / Library work. `createModuleFee()` was
**not** replaced, Fees Exemption V2 was **not** redesigned, Phase 3 convergence was **not** performed.

**Evidence labels:** `SOURCE-VERIFIED` (read in this repo) · `ZENX-VERIFIED` (behaviour proven by test) ·
`INDUSTRY PRACTICE` (external, cited) · `INFERENCE` · `BUSINESS DECISION REQUIRED` · `NOT ESTABLISHED`.

---

## 1. EXECUTIVE SUMMARY

Two defects were found and fixed, both instances of one root cause: **code that re-derived a fee balance
without the late fee**, while five neighbouring sites already used the canonical equation
`balance = max(0, net + fine − paid)`.

| ID | Site | Severity | Status |
|---|---|---|---|
| **FEES-FROZEN-1** | `Fee_lifecycle::createModuleFee()` | **HIGH** — money silently stopped being owed | **FIXED** |
| **FEES-FROZEN-2** | `Fee_summary_writer` + `Fee_summary_reader` (3 aggregation sites) | **HIGH** — fine-only debtors dropped off the defaulters list | **FIXED** |

**Refinement to the previously reported defect (FEES-FROZEN-1):** earlier reports flagged only the
`$paidSoFar > 0` branch. `SOURCE-VERIFIED` — **both branches** were wrong. The `else` branch set
`'balance' => $net` outright, so an **unpaid** demand carrying a fine also lost it. That is the *more*
likely case, because a fine is normally applied before payment.

**Broader than expected (FEES-FROZEN-2):** the sweep required by Part 5 found the same equation error in
the fee **summary** layer. That one is not module-specific — it understated *every* summary carrying a
fine, from *any* generator, including core tuition.

**Regression:** 1114 tests / 3165 assertions / **10 failures — identical to the 10-failure baseline.**
No new failures. `php -l` clean on all three changed files. RTDB scan clean.

**Fees is NOT declared complete.** See §9 and §10.

---

## 2. PART 1 — IMPACT TRACE

`SOURCE-VERIFIED` — `Fee_lifecycle::createModuleFee()` is both a generator **and** a re-entrant mutator.
The Transport demandId is route-independent, so the same document is rewritten by:

| Caller | Site |
|---|---|
| Transport — change route | `Transport_service.php:3022` |
| Transport — change subscription | `Transport_service.php:3109` |
| Transport — resume assignment | `Transport_service.php:2875` |
| Transport — assignment create | `Transport_service.php:2690` |
| Transport — session rollover | `Transport_service.php:3356` |
| Transport — reconcile | `Transport_service.php:3489` |
| Hostel — allocation | `Hostel.php:360` |

Every one of those re-invocations erased the fine from what was owed. The existing regression guard
`Transport_verifier::CH-TRN-FIN1-1` covered **payment** preservation (`paidAmount`) but not **fine**
preservation — which is precisely why this survived. That check still passes after the fix (it greps for
`firestoreGet('feeDemands'` and `'paidAmount' => $paidSoFar`, both retained).

**Why it was invisible:** `Fee_firestore_txn::writeDemand()` writes with `merge: true`
(`Fee_firestore_txn.php:831`). So `fineAmount` **survived on the document**. The demand read
`fineAmount: 200` next to a balance that excluded it — recorded, displayed, and uncollectable. Nothing
threw, nothing logged, and the ledger looked internally plausible.

---

## 3. PART 2 — RESEARCHING THE CORRECT FORMULA

The equation was **proven from existing sites, not assumed** — `SOURCE-VERIFIED`:

| Site | Expression |
|---|---|
| `Fee_late_fee_service.php:205` | `round(max(0, $netAmount + $fine - $paidAmount), 2)` |
| `Fee_lifecycle::proRateFees` (`:784`) | `newNet + fineAmt - paidAmt` |
| `Fee_refund_service.php:535` | `net + fine - newPaid` |
| `Fee_integrity_checker.php:322` | `$totalDemanded += ($netAmount + $fineAmount)` |
| `Fees.php:753` | `$demandedAmt = $net + $fine` |

All five agree. **No double-counting** — `SOURCE-VERIFIED`: `netAmount` is the post-discount charge
(`createModuleFee` sets `netAmount => $net` after the Phase D entitlement deduction), and the fine lives
only in its own field, never folded into `netAmount`.

---

## 4. PART 3 — THE FIX

### FEES-FROZEN-1 — `application/libraries/Fee_lifecycle.php` (~:1037)

```php
$fineSoFar = is_array($existing)
    ? (float) ($existing['fine_amount'] ?? $existing['fineAmount'] ?? 0) : 0.0;
if ($fineSoFar < 0) $fineSoFar = 0.0;

if ($paidSoFar > 0) {
    $balance  = max(0.0, round($net + $fineSoFar - $paidSoFar, 2));
    $payState = ['paidAmount' => $paidSoFar, 'balance' => $balance,
                 'status' => $balance <= 0.005 ? 'paid' : 'partial'];
} else {
    $balance  = max(0.0, round($net + $fineSoFar, 2));
    $payState = ['paidAmount' => 0.0, 'balance' => $balance, 'status' => 'unpaid'];
}
```

Both key spellings are read, matching the precedent at `Fee_late_fee_service.php:200` verbatim.
Negative fines are clamped so a bad value can never *credit* a balance.

### FEES-FROZEN-2 — the summary layer (3 sites)

`Fee_summary_writer::_aggregateStudentDemands()`, `Fee_summary_reader::_recomputeClass()`,
`Fee_summary_reader::_recomputeStudent()` all aggregated `netAmount − paidAmount`.

**Worst case, `ZENX-VERIFIED` by test:** a family that paid the charge in full but not the penalty
aggregated to a **zero** balance, was classified `'paid'`, and **dropped out of the defaulters list** —
`Fees::get_defaulter_data` skips rows at `balance <= 0.005` (`Fees.php:526`). The debt sat on the demand
and was invisible in every roll-up above it.

This also means the summary writer and `Fee_integrity_checker` **disagreed on the same quantity** — which
is what marks this as drift rather than a deliberate choice.

---

## 5. PART 4 — TESTS

Two new suites, **35 tests / 69 assertions, all passing**:

- `tests/Unit/Phase19ModuleFeeFineAwareBalanceTest.php` — 20 tests
- `tests/Unit/Phase19FeeSummaryFineAwareTest.php` — 15 tests

Every scenario the phase brief listed is covered:

| Brief | Covered by |
|---|---|
| A no fine | `test_no_fine_unpaid_is_identical_to_before`, `…_partially_paid_…` |
| B fine + unpaid | `test_unpaid_demand_now_owes_net_plus_fine` |
| C fine + partial | `test_partially_paid_demand_now_owes_net_plus_fine_minus_paid` |
| D fine + full | `test_fully_paid_including_the_fine_becomes_paid` |
| E overpayment | `test_overpayment_floors_at_zero` |
| **F repeated `createModuleFee()`** | `test_repeated_regeneration_is_idempotent_and_never_erases_the_fine` |
| G existing demand with fine | `test_a_changed_fare_recomputes_against_the_new_net_keeping_the_fine` |
| H regeneration | `test_fine_is_never_doubled_by_regeneration` |
| I/J/K Transport, Hostel, other callers | `test_module_and_tuition_fines_aggregate_together` (shared code path — §2) |
| L refund / reversal | `test_matches_the_refund_service_equation` |
| M accounting | `test_now_agrees_with_the_integrity_checker`, `…_collection_analytics` |
| N defaulter calculation | `test_the_worst_case_a_fine_only_debt_vanished_entirely` |
| O receipt | `test_summary_balance_equals_the_sum_of_demand_balances` |
| P reminder eligibility | §6 below — reads `balance`, propagates automatically |

**The headline requirement — run `createModuleFee()` twice.** `ZENX-VERIFIED`: the second run does not
erase the fine, does not duplicate it, does not duplicate the demand (same `demandId`, `merge: true`), and
does not corrupt the balance. Three consecutive regenerations return byte-identical state.

Both suites also retain the **legacy** implementation alongside the fixed one, so the tests prove the
defect existed *and* that fine-free behaviour is unchanged — not merely that the new code self-agrees.

---

## 6. PART 5 — SWEEP FOR THE SAME BUG ELSEWHERE

Every balance/dues derivation in the Fees layer was read individually (**not** blindly replaced):

| Site | Verdict |
|---|---|
| `Fee_lifecycle::createModuleFee` | ❌ **DEFECT — FIXED** (FEES-FROZEN-1) |
| `Fee_summary_writer::_aggregateStudentDemands` | ❌ **DEFECT — FIXED** (FEES-FROZEN-2) |
| `Fee_summary_reader::_recomputeClass` / `_recomputeStudent` | ❌ **DEFECT — FIXED** (FEES-FROZEN-2) |
| `Fee_lifecycle::proRateFees` (`:784`) | ✅ already fine-aware |
| `Fee_lifecycle::reverseAdmissionFees` (`:1167`) | ✅ `balance => 0` with `status => 'reversed'` — **deliberate**, not a defect |
| `Fee_refund_service.php:535` | ✅ already fine-aware |
| `FeeCollectionService:804` / `:1412` | ✅ `bal − alloc` decrements an already fine-inclusive balance |
| `Fee_late_fee_service::applyTo` | ✅ canonical source of the equation |
| `Fee_defaulter_check` (`:149`, `:602`, `:682`, `:717`) | ✅ sums `d['balance']` — inherits the fix automatically |
| `Fee_reminder_service` (`:149`, `:233`, `:277`) | ✅ reads `balance` — inherits the fix automatically |
| `Fee_integrity_checker:322`, `Fees.php:753` | ✅ already fine-aware |

**Downstream consequence of the fix** — `SOURCE-VERIFIED`: because `Fee_defaulter_check` and
`Fee_reminder_service` both read `balance` rather than recomputing it, module fines now correctly flow
into defaulter dues, TC clearance, hostel/transport dues sub-checks, and reminder eligibility. Before the
fix a module fine was invisible to **all** of them.

---

## 7. PART 6 — MODULE LATE-FEE PREPARATION (RESEARCH ONLY — NOT ENABLED)

### The finding

`SOURCE-VERIFIED` — `Fee_late_fee_service::computeForSchool()` queries `feeDemands` with **no category
filter**. Transport and Hostel demands are *already inside* the engine's scan. They escape only at one
guard in `fineFor()` (`:169`):

```php
if ($dueDate === '' || $dueDate >= $cutoff) return null;   // no due date → never overdue
```

And `SOURCE-VERIFIED` — `createModuleFee()` writes `periodKey` but **never `dueDate`**. Phase 15.5's
stamping (`'dueDate' => $this->_dueDateFor($periodKey)`) exists at exactly **one** site,
`Fee_lifecycle.php:397`, on the tuition convergence path.

> **Module fees are therefore silently exempt from late fees — by omission, not by policy.**

### What enabling it would take, and why it is NOT a one-line change

The mechanical change is one line in the `createModuleFee()` payload. The consequences are not mechanical:

1. **Retroactive fining.** `computeForSchool()` scans all demands for the session. The next cron run after
   the field appears would fine **every already-overdue** Transport/Hostel demand at once. `INFERENCE`.
2. **Policy reuse.** There is one policy (`late_fee_*` in `feeSettings`). Enabling module fees applies the
   *tuition* fine — flat or percentage-of-net — to transport. `SOURCE-VERIFIED`.
3. **FEES-FROZEN-1 was the precondition.** Without the fix in §4, every route change would have erased the
   new fines. The fix must land *before* any module late fee is ever switched on.

### External grounding

`INDUSTRY PRACTICE` — Indian schools do levy late fees on transport, and transport fees are typically
treated as **separate from tuition, with their own payment schedule and independently applied late-fee
structure** — not as an extension of the tuition policy.

`INDUSTRY PRACTICE` — there is **no single national rule**. Fee regulation is **state** jurisdiction, and
the Delhi High Court has held that the Delhi School Education Rules' prescribed late-fee fine does not
bind private unaided schools, which retain substantial autonomy over fee structure.

`INDUSTRY PRACTICE` — schools without a clearly communicated written policy and a service contract
covering the charge are the ones exposed to challenge.

**Correcting an earlier overstatement in this programme:** a grace period is *good practice*, not a legal
requirement, and prior phases described it too strongly. That correction stands.

### Recommendation

`ZENX RECOMMENDATION` — do **not** enable by stamping `dueDate` in `createModuleFee()`. Instead:

1. Add a **separate, explicitly-off** module late-fee policy (`late_fee_modules_enabled`, own rate and cap)
   rather than silently inheriting the tuition policy. This matches how schools actually structure it.
2. Gate activation on a **forward-only cutoff** so switching on cannot retroactively fine historical
   demands.
3. Require the school to have the charge in its published fee policy first.

**`BUSINESS DECISION REQUIRED` (FEES-D16):** should Transport/Hostel fees attract late fees at all, and if
so at what rate and from which date? *Not implemented in this phase.* No behaviour changed — module fees
remain exempt exactly as before.

---

## 8. PART 10 — CROSS-SYSTEM CHECK (Parent + Teacher apps)

`SOURCE-VERIFIED`. Both apps model the demand as `FeeDemandDoc.kt` with `fineAmount: Double` and
`balance: Double`, no `@PropertyName` overrides.

I initially read this as a snake/camel shape drift. **It is not.** `Fee_firestore_txn::updateDemand()` and
`_buildDemandDoc()` consume legacy snake-case inputs and emit **single-source camelCase** — so the stored
field is `fineAmount`, which is exactly what both apps deserialize. The engine's `'fine_amount' => $fine`
is an *input* key to that canonicaliser, never the stored one.

| Surface | Reads | Effect of this phase |
|---|---|---|
| Parent — `PaymentSession.kt:419` | `balance` | Payable amount now correctly includes module fines |
| Parent — `ReceiptDetailViewModel.kt:306` | `balance` | Correct |
| Parent — `FeesScreen.kt:1537` | `fineAmount` | Already correct, unchanged |
| Teacher — `FeeDemandDoc.kt` | `fineAmount`, `balance` | Already correct, unchanged |

**Verdict: no Parent-app or Teacher-app change required.** No Firestore rules or index change either — no
new field, no new collection, no new query shape.

---

## 9. PART 11 — FINAL RE-SCAN

| Check | Result |
|---|---|
| `php -l` — `Fee_lifecycle`, `Fee_summary_writer`, `Fee_summary_reader`, both test files | ✅ clean |
| Full unit suite | **1114 tests / 3165 assertions / 10 failures** |
| Baseline comparison | **10 pre-existing failures, identical set** (Backup ×1, Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2) — **no new failures** |
| New tests | 35, all passing |
| RTDB usage in changed files | ✅ none (single hit is a comment noting RTDB was *replaced*) |
| `Transport_verifier::CH-TRN-FIN1-1` source-scan | ✅ still satisfied |
| Tenant/session scoping | ✅ unchanged — no query was touched |
| Firestore rules / indexes | ✅ no change required |

---

## 10. HONEST CLOSURE STATEMENT

**Fees is NOT complete.** Per the phase brief's own gate, "complete" requires UAT actually executed. It has
not been.

**Done in this phase:** FEES-FROZEN-1 fixed (both branches), FEES-FROZEN-2 found and fixed (3 sites), 35
regression tests, full sweep of every balance derivation in the Fees layer, cross-system verification,
module late-fee research with the enabling mechanism identified and deliberately left off.

**Explicit remaining boundaries — each with a stated reason, none silently dropped:**

| Item | Boundary |
|---|---|
| **FEES-UAT** | Not executed. Requires a running tenant and an operator. **This alone blocks "Fees complete."** |
| **FEES-D16** — module late fees | `BUSINESS DECISION REQUIRED` (§7). Researched and prepared; not enabled. |
| **FEES-D7** — historical `dueDate` backfill | Mutates production financial records; needs credentials + authorization. `planDueDateBackfill()` measures scope without writing. No numbers invented. |
| **FEES-FROZEN-2 historical data** | The fix corrects all **future** aggregation. Existing `studentFeeSummary` docs stay understated until re-derived. `updateStudentSummary()` recomputes from demands, so any normal fee event self-heals that student — but a deliberate re-derivation sweep is a production-data operation and is **not** authorized here. |
| FEES-D9, D13, D14, D15 | Previously registered, unchanged. |
| **SEC-ID-1 / -2 / -3** | Security, separate workspace as instructed — **not** folded into Fees. Unsigned legacy QR tokens are still accepted (`SEC-ID-2`, HIGH). |
| **EXAM-LC1** | `Roster_helper::for_class` camelCase-only. Outside Fees; module-at-a-time rule applies. |

**Not committed. Not pushed. Not deployed.** Three source files and two test files are modified in the
working tree on `integration/phase2`, awaiting your verification on `localhost:8080`.

Note: `git diff --stat` shows `Fee_lifecycle.php` at +359 lines — that is cumulative uncommitted work from
Phases 15.5 onward, **not** this phase. This phase's change to that file is the ~20-line block in §4.

---

## SOURCES

- [Late Fee Payments: Legal Implications for Schools — Prospect Legal](https://prospectlegal.co.in/late-fee-payments-at-school/)
- [Delhi HC: rule prescribing late-fee fine not applicable to private unaided schools — LiveLaw](https://www.livelaw.in/news-updates/rule-prescribing-fine-of-five-paise-per-day-on-late-fee-payment-not-applicable-to-private-unaided-schools-delhi-high-court-214495)
- [Missed School Fee Deadline in India? Know Your Rights — FeeMonk](https://www.feemonk.com/blog/what-happens-if-students-miss-fee-deadlines-in-india)
- [Policy for School Transport Service — St. Francis de Sales, Guwahati](https://sfsguwahati.ac.in/policy-for-school-transport-service/)
