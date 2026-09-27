# ZENX HRMS — W1.2 MONEY PRECISION — IMPLEMENTATION REPORT

**Status: COMPLETE AND VERIFIED — STOPPED FOR APPROVAL.** Not committed, not pushed, not merged, not deployed. W1.3 and W2 not started.

**Result: 126 runtime assertions, 0 failures** (50 W1.2-specific). Unit suite back to the exact 10-failure baseline. **No migration performed. No tenant data touched. No business rule changed.**

---

## 1. Current money-calculation architecture (as audited)

```
salary structure (Firestore, rupee decimals)
      ↓
_compute_payslip()                    ← the single canonical calculator
      ↓ per-employee breakdown
generate_payroll() loop → run totals  ← accumulated across N employees
      ↓
payslip docs + accrual_payload
      ↓
PayrollAccountingService::postRunAccrual()   ← validates Dr == Cr
      ↓
accounting journal
```

Two callers of `_compute_payslip()`: `generate_payroll()` and `regenerate_staff_payroll()`. Only `generate_payroll()` accumulates across employees.

## 2. Exact floating-point risks found

| # | Risk | Where | Consequence |
|---|---|---|---|
| 1 | **Round-then-sum.** Six earning components each `round(x, 2)` as floats, then summed and re-rounded | earnings block | `Σ components == gross` was approximate, not guaranteed |
| 2 | **Round-then-multiply.** `dailySalary = round(basic / workingDays, 2)` then multiplied by `lwpDays` / `extraWorkedDays × rate` | LWP + extra-pay | Rounding error amplified by the multiplier. `30000/31 = 967.741935…` → `967.74`, ×2 = `1935.48` vs true `1935.4839…` |
| 3 | **Float percentage bases.** `round($effectiveBasic * ($pfPct / 100), 2)` — `12/100` is not exactly representable in binary | PF / ESI / TDS / employer contributions | Sub-paise drift on every statutory line |
| 4 | **Epsilon comparisons.** `$employeeDeductions > $gross`, `$overflow > 0.005`, `abs($residual) >= 0.01` | deduction waterfall | Correctness depended on float tolerances rather than exact values |
| 5 | **Cross-employee accumulation.** `$totalNet += $netPay` etc. over N employees, each side rounded independently at the end | `generate_payroll()` | **The critical one.** Per-slip `gross == net + deductions` can hold while the *sums* differ by a paise — and `PayrollAccountingService` independently validates Dr == Cr. Its own docblock cites a historical journal imbalance (`JE_20260414193106_f55be96d`) as its defining context, which is exactly this failure mode |

## 3. Chosen representation

**Integer paise (1/100 INR) is the authoritative unit for all calculation.** Floats are demoted to a *storage and presentation* form only.

**Storage boundary: unchanged — no migration.** Firestore continues to store rupee decimals exactly as before. Every existing document, payslip PDF, Excel export, accounting payload and mobile-app consumer keeps working with no data change. This satisfies the objective ("floating-point must not be the authoritative representation of monetary *calculations*") while requiring zero migration, so the **STOP-and-report condition was never triggered**.

**Currency:** INR only, matching the existing product contract. No multi-currency abstraction introduced — the audit found nothing in the data model requiring it.

## 4. Rounding policy (one policy, stated once)

1. **Rupees → paise happens ONCE**, at the input boundary (`_toPaise`), round-half-up.
2. **Ratio application** — proration and percentages — rounds **once per component**, half-up, at paise granularity (`_applyRatio`). No value is rounded, re-multiplied and rounded again.
3. **Sums, differences and comparisons NEVER round.** They are exact integer operations.
4. **Paise → rupees happens ONCE**, at the output boundary (`_toRupees`).

Three helpers implement it: `_toPaise()`, `_toRupees()`, `_applyRatio()`. Statutory rounding rules are untouched — the rates, bases and order of the statutory block are byte-for-byte the same business logic, evaluated exactly instead of approximately.

## 5. Exact code changes

**`_compute_payslip()`** — inputs converted to paise at entry; six earning components prorated via `_applyRatio`; `$grossP` is an **exact integer sum**; LWP deduction and extra-worked pay derived in **one step** from the exact basic base (`basic × days ÷ workingDays`) instead of via a pre-rounded daily rate; statutory and employer contributions via `_applyRatio`; the entire deduction waterfall converted to exact integer arithmetic (`> 0` replaces `> 0.005`; `!== 0` replaces `abs(...) >= 0.01`); one conversion block back to rupees before the return.

**Return shape extended, not changed** — every existing rupee key is still present and identical in meaning. A new `paise` sub-array exposes the exact integers for callers that accumulate.

**`generate_payroll()`** — all ten run-total accumulators now sum `$calc['paise'][...]` as exact integers and convert **once** after the loop. This is what makes the journal identity `(teachingExp + nonTeachingExp) == (net + PF + ESI + TDS + PT + other)` hold exactly across N employees.

**`regenerate_staff_payroll()`** — needed no change; it consumes the unchanged rupee keys and does not accumulate.

**Displayed `daily_salary` deliberately unchanged** (still the 2dp rupee value) so nothing downstream shifts; only the money derived *from* it is now exact.

## 6. Storage-boundary analysis

| Location | Type before | Type after | Migration |
|---|---|---|---|
| `salarySlips` (`type=salary_structure`) inputs | rupee number | **unchanged** | none |
| `salarySlips` (`type=payslip`) amounts | rupee number | **unchanged** | none |
| `salarySlips` (`type=run`) totals | rupee number | **unchanged** | none |
| `accrual_payload` totals | rupee number | **unchanged** | none |
| `accounting` journal amounts | rupee number | **unchanged** | none |

The new `paise` array is **in-memory only** — it is part of the calculator's return value and is not persisted. Verified: no `paise` key reaches any Firestore write.

## 7. Accounting-boundary analysis

The accounting payload receives the ten run totals, which are now derived from exact integer accumulators and converted once. Because the per-employee identity `grossP == netP + Σ deductionsP` holds exactly and integer addition is associative, the summed Dr side equals the summed Cr side exactly — proven by test case Q over a deliberately awkward six-employee roster:

```
Dr (Σ gross) = 14925918 paise
Cr (Σ net + PF + ESI + TDS + PT + other) = 14925918 paise
diff = 0
```

`PayrollAccountingService` itself was **not modified** — no change to journal shaping, the transactional outbox, `journalIdFor()`, the reconciler, period-open guards or CoA validation. It simply now receives amounts that cannot drift.

## 8. Tests created / updated

Added `group_money_precision()` to the existing runtime verifier — **50 assertions covering all required cases A–Q**, executing the real `_compute_payslip()` (no duplicate implementation; the harness invokes the genuine private method by reflection).

**Updated one existing test.** `AttendancePayrollStatusContractTest::test_worked_on_off_feeds_the_canonical_calculator` asserted the *literal* pre-W1.2 float expression `round($dailySalary * $extraWorkedDays * $extraWorkedRate, 2)`. W1.2 legitimately replaced that arithmetic, so the assertion broke. It was pinning an implementation detail rather than a contract — a flaw in my own P0 test. Rewritten to assert the **behaviour** (extra pay is priced inside the canonical calculator from `extraWorkedDays × extraWorkedRate`) via regex, which is robust to representation changes. **This was the only new failure, it was caused by the test not the engine, and it is resolved.**

## 9. Runtime test results — 50/50 PASS

| Case | Assertion | Result |
|---|---|---|
| **A** whole-rupee | gross exactly `3000000` paise | PASS |
| **B** paise values | `30000.55 + 1234.49` ⇒ exactly `3123504` | PASS |
| **C** multiple earnings | Σ six components == gross **exactly** | PASS |
| **D** multiple deductions | Σ deduction components == total **exactly** | PASS |
| **E** percentage | PF == 12% of earned basic, exact to the paise | PASS |
| **F** division | 1 absent day of **31** (repeating decimal) — invariants hold | PASS |
| **G** half-day | invariants hold | PASS |
| **H** LOP | invariants hold | PASS |
| **I** worked-on-off | extra pay exactly `200000` paise | PASS |
| **J** statutory | awkward rates (0.75%, 3.33%) — invariants hold | PASS |
| **K/L** gross & net | `gross − deductions == net` exactly, every case | PASS |
| **M** zero | zero salary ⇒ 0/0, no negative; zero working days ⇒ no divide-by-zero | PASS |
| **N** repeat | identical inputs ⇒ **byte-identical** breakdown (idempotent) | PASS |
| **O** boundary | ₹1.5 crore ⇒ exactly `1500000000` paise, no precision loss | PASS |
| **P** regression | baseline gross/PF/ESI/net **unchanged** (30000 / 3600 / 225 / 26175); half-day still deducts exactly 500 | PASS |
| **Q** accounting | **Dr == Cr exactly over 6 employees, diff = 0** | PASS |
| extra | summation **order** does not change the total | PASS |
| extra | waterfall: net never negative, invariants still exact | PASS |
| extra | **no `round($x, 2)` money rounding remains** inside the calculator | PASS |

## 10. Regression results

| Suite | Baseline | After W1.2 | Verdict |
|---|---|---|---|
| **Runtime verifier (all groups)** | 76 PASS / 0 FAIL | **126 PASS / 0 FAIL / 0 SKIP** | +50, no regressions |
| `phpunit --testsuite Unit` | 170 tests, **10 failures** | 170 tests, 440 assertions, **10 failures** | **identical baseline** |
| `B2_foundation_verify f1` | PASS 27/0 | **PASS 27/0** | unchanged |
| `php -l` (all changed files) | clean | **clean** | — |
| RTDB audit on the diff | — | **clean, none introduced** | — |
| Firestore rules | not touched | **not touched** | no run required |

**Baseline failures (10):** `BackupTest::size_human` (documented format drift), 4 × Homework source-regression tests (read `Homework.php`, untouched), 5 × cross-repo Kotlin tests (`D:/Projects/SchoolSync{Parent,Teacher}`, separate repositories).
**New failures: 1, transient** — my own contract test (§8), **resolved**.
**Resolved failures: 0** pre-existing (none were in scope).

No index change required. No rules change required.

## 11. Before / after examples

| Scenario | Before (float engine) | After (paise engine) |
|---|---|---|
| Ordinary whole-rupee month | gross 30000.00, PF 3600.00, ESI 225.00, net 26175.00 | **identical** |
| Half-day | deducts 500.00 | **identical** |
| Six components with paise (`20000.33 + 5000.27 + …`) | `Σ components == gross` held only after a corrective re-round | `Σ == gross` **exact by construction** |
| Extra pay, 31-day month | `round(round(30000/31,2) × 2, 2)` — daily rate rounded then multiplied | one-step `30000 × 2 ÷ 31` in paise — **no amplification** |
| Deduction overflow waterfall | residual absorbed only if `abs(residual) >= 0.01` | residual test is exact `!== 0` |
| 6-employee run | Dr and Cr each rounded independently → possible 1-paise imbalance | **Dr == Cr exactly (diff = 0, verified)** |

## 12. Business rules unchanged — confirmed

No rate, threshold, multiplier, policy or rule was altered. PF/ESI/TDS percentages and their bases, `extra_worked_rate` (still 1.0 default), LOP rules, leave rules, attendance rules, the proration model, the deduction waterfall's *order* and the statutory clamp semantics are all identical. Only the arithmetic used to evaluate them changed. Proven by case P: baseline outputs are unchanged for ordinary inputs.

## 13. No tenant data modified — confirmed

W1.2 is pure calculation. No Firestore write, no tenant read, no entitlement/billing/subscription change, no test data created. The runtime verifier remains read-only (the two live-tenant reads in the isolation group are pre-existing and read-only). **No tenant ID is referenced anywhere in the W1.2 changes.**

## 14. No migration performed — confirmed

None was required (§6) and none was performed. The STOP-and-report condition did not arise.

## 15. Remaining risks

1. **Ratio inputs remain floats.** `retention`, `pct/100` and `days/workingDays` are floats by nature (proportional pay is inherently rational). They are applied to an *exact* integer base with a *single* rounding, so no drift accumulates — but a ratio is still a float. Eliminating this entirely would need rational arithmetic and is not warranted at this precision.
2. **Storage remains rupee decimals.** Deliberate (§3). A value written and re-read crosses a float boundary. Every calculation re-derives from inputs rather than accumulating stored values, so this does not compound — but it is the one place floats still touch money.
3. **Other money paths were not converted.** `Payroll.php`'s manual-entry accounting poster and fee/accounting modules still use floats. Out of W1.2's scope (the canonical *payroll* path), and flagged rather than silently expanded.
4. **`daily_salary` remains a 2dp display value.** Intentional, to avoid shifting downstream consumers; the money derived from it is now exact.
5. **No live payroll run executed.** Verification was against the real calculator with synthetic inputs; running an actual payroll would create documents. Deferred to the end-to-end UAT.

## 16. Recommendation for W2 (compensation core)

W1.2 makes the arithmetic safe **before** the component count multiplies, which was the whole reason for sequencing it first. For W2:

1. **Build the component master on the paise contract from day one** — every component amount should enter the engine via `_toPaise()` and be summed as integers. The `paise` return array is the interface to build against.
2. **Promote the three helpers** (`_toPaise` / `_toRupees` / `_applyRatio`) from private methods to a small shared money helper when the component engine needs them outside `Hr.php`. They were deliberately kept private here to stay within W1.2's minimum scope; W2 is the natural moment to move them.
3. **Keep the invariants as executable gates** — `Σ components == gross` and `gross − deductions == net` should be asserted for every new component type, extending the W1.2 group rather than writing a parallel suite.
4. **Formula evaluator must return paise integers**, never floats, and must round once at the component boundary — the same policy as §4.
5. **W1.3 (ATS RTDB migration) is independent** of both and can be sequenced by preference.

---

## Confirmations

- No second payroll engine, calculator or accounting calculator created — `_compute_payslip()` remains the single canonical path.
- No floating-point monetary calculation remains on the canonical payroll path (verified by an executable assertion).
- Existing payroll inputs remain compatible; ordinary results unchanged.
- Accounting receives deterministic amounts; Dr == Cr proven exactly.
- Attendance, RBAC, Entitlement, Staff, Leave, Transport, Fees, Accounting architectures untouched.
- No RTDB introduced. No rules change. No index change. No migration. No tenant data modified.
- **Not committed, not pushed, not merged, not deployed. W1.3 / W2 not started.**
