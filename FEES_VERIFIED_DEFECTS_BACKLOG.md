# Fees Module — Verified Defects Backlog

**Opened:** 2026-08-09
**Origin:** defects surfaced by operator UAT of the Student Entitlements module. **None of these are
Student Entitlements regressions** — all were verified against unmodified files or pre-existing data.
**Status:** documentation only. **No fix is authorized** except where explicitly noted.

> **Scope rule (operator directive, 2026-08-09):** issues found outside Student Entitlements are
> investigated to root cause, assessed for blocker status, then **documented and left alone**. Student
> Entitlements remains the active workstream. Nothing here is to be implemented without explicit
> authorization.

---

## FEE-001 — `submit_fees` returns HTTP 500 at the fee counter

| Field | Value |
|---|---|
| **Severity** | **Critical** |
| **Module** | Fees (counter) / Core |
| **Type** | Implementation |
| **Blocks Student Entitlements?** | **YES — it did.** Test 8.1 could not run at all. |
| **Status** | ✅ **FIXED 2026-08-09 with explicit operator authorization** |

**Root cause.** `FeeCollectionService` is an extracted *service*, not a `MY_Controller` subclass. At
`FeeCollectionService.php:41` it calls `$controller->_abort_if_period_locked()`, which was declared
`protected` in `MY_Controller.php`. PHP therefore raised
*"Call to protected method MY_Controller::_abort_if_period_locked() from scope FeeCollectionService"*.

**Why it survived review.** The call is guarded by `method_exists($controller, '_abort_if_period_locked')`.
`method_exists()` returns **TRUE for protected methods** — it tests *existence*, not *accessibility*. The
guard therefore passed and the call threw anyway. The author's intent was right; the check was wrong.

**Why it wasn't caught sooner.** The guard also requires `$writeToOutput === true`, which is only set on
the **counter** path. The Parent-app verify path passes `false` and skips the call entirely — so online
payments kept working while the counter was completely broken.

**Technical evidence.**
```
ERROR - 2026-08-09 06:12:31 --> Severity: error --> Exception: Call to protected method
MY_Controller::_abort_if_period_locked() from scope FeeCollectionService
.../application/services/FeeCollectionService.php 41
```

**Affected workflows:** Fee Counter **BROKEN**. Accounting (no journal, since no payment). Student Ledger
(no update). Dashboard/Reports (collections under-reported). Parent App **unaffected**. Promotion, TC,
Defaulters unaffected.

**Business impact.** No fee could be collected at the counter. Total revenue-collection outage on the
primary path.

**Fix applied (minimal).** `protected` → `public` on `MY_Controller::_abort_if_period_locked()`. Verified
safe: only one declaration exists repo-wide (no subclass reduces visibility → no fatal), and all four
callers are compatible. Widening cannot break existing callers.

**Files:** `application/core/MY_Controller.php` (changed), `application/services/FeeCollectionService.php`
(unchanged, root of the mismatch).

**Follow-up (not done):** replace the `method_exists()` guard with `is_callable()`, which respects
visibility. Left alone deliberately — out of scope.

---

## FEE-002 — Student Ledger TOTAL DUE includes archived demands

| Field | Value |
|---|---|
| **Severity** | **High** |
| **Module** | Fees (Student Ledger summary) |
| **Type** | Implementation / Reporting |
| **Blocks Student Entitlements?** | **No** — no workaround needed; certification can proceed |
| **Status** | ❌ **NOT FIXED — documented only** |

**Root cause.** The ledger's summary tiles (TOTAL DUE / OUTSTANDING) sum `balance` across **every**
demand document for the student, without excluding `status = 'archived'`. The per-row list *does* render
the status correctly — only the aggregate ignores it.

Promotion archives the outgoing class's unpaid demands and regenerates for the new class
(`Fee_lifecycle::reassignFeesOnPromotion`). Each promotion therefore adds a full archived generation.
After repeated promotions the archived set dwarfs the live one, and the total inflates proportionally.

**Technical evidence** — STU0005 (Mahendra kumar), after promotion to Class 9th:
```
111 demand docs : 37 unpaid · 74 archived
SUM balance ALL    = ₹1,09,000.00   ← what the ledger displays
SUM balance ACTIVE = ₹   37,200.00   ← correct figure
Inflation          = ₹   71,800.00  (~3×)
```

**Related precedent.** This is the same family as the documented **BUG-075 / BUG-076 "phantom dues"**
work. Archived-skip filters were added across many read surfaces in 2026-05 (the `SW4-companion-*`
series — at least eight sites in `Fees.php` carry
`if (strtolower($d['status']) === 'archived') continue;`). **The ledger summary was evidently missed.**

**Affected workflows:**

| Surface | Affected? |
|---|---|
| Student Ledger summary | **YES — inflated** |
| Student Ledger row list | No — statuses render correctly |
| TC dues check | **Likely** — if it reads the same aggregate, a paid-up student could be blocked |
| Defaulter Reports | **Likely** — same aggregation risk |
| Dashboard / Reports | **Likely** — collections vs dues distorted |
| Parent App | **Needs verification** — computes its own totals; if it filters archived, panel and app will *disagree* |
| Fee Counter | No — works from selected months |
| Accounting | **No** — journals post from receipts, never from `balance` |
| Promotion | No — archiving itself is correct |

**Business impact.** A family can be told they owe ~3× the true amount. Could wrongly block a Transfer
Certificate, wrongly flag a defaulter, and distort management reporting. Only manifests after **repeated
promotions**, which is why normal operation has not surfaced it.

**Production risk of fixing:** low — display-layer aggregation only; no data migration; demand documents
are already correct.

**Recommended future fix (smallest).** Exclude `archived` (and `cancelled`) when summing the ledger
totals, reusing the existing `SW4-companion` predicate rather than inventing a new one.

**Mandatory before shipping that fix — the audit I was told to stop:** sweep every Fees aggregation for
the same omission. The pattern to search is a `balance` sum with no status guard. Candidate surfaces:
`Fee_defaulter_check`, TC clearance, dashboard collection tiles, and the Parent App's own totals. Fixing
the ledger alone would leave panel and app disagreeing — which is worse than both being wrong the same way.

**Files:** `application/controllers/Fees.php` (ledger summary), `application/views/fees/student_ledger.php`,
plus whatever the sweep identifies.

---

## FEE-003 — One-off discount attribution is arbitrary

| Field | Value |
|---|---|
| **Severity** | **Low** |
| **Module** | Fees (counter / discounts) |
| **Type** | Reporting / UI |
| **Blocks Student Entitlements?** | No |
| **Status** | ❌ Not fixed — documented |

**Root cause.** "Grant a one-off discount" writes a single flat amount to
`studentDiscounts.onDemandDiscount` with an optional `validUntil`. It is **not** attached to a fee head.
The counter must display it against *something*, so it surfaces against one line (observed: Annual Fee).

**Business impact.** The money is right and applied once; only the *attribution* is arbitrary. A school
asking "how much did we discount on Annual Fee?" gets a misleading answer.

**Wider context.** `studentDiscounts` is the **Family B** legacy discount source documented in
`STUDENT_ENTITLEMENTS_ARCHITECTURE_BASELINE.md` §0A.4 — one of three disjoint discount mechanisms. Its
convergence is exactly what Student Entitlements exists to achieve. **Do not fix in isolation**; it should
be resolved by that convergence, not patched.

**Files:** `application/controllers/Fees.php` (~2493).

---

## FEE-004 — 98 orphaned load-test demands in production

| Field | Value |
|---|---|
| **Severity** | **Medium** |
| **Module** | Fees (data) |
| **Type** | Data hygiene |
| **Blocks Student Entitlements?** | No |
| **Status** | ❌ Not fixed — deletion is irreversible, needs explicit authorization |

**Root cause.** Load-test residue: `DEM_TEST_LOAD_000` … `_097`, carrying `grossAmount = 0`,
`netAmount = 1`, no `frequency`, and **no `schoolId`**.

**Technical evidence.** Production scan of 1,946 demands found **98** violating the
`gross − discount == net` invariant — **all 98 are `DEM_TEST_LOAD_*`**. Non-test violations: **zero**.

**Why nothing has flagged them.** They carry no `schoolId`, so every school-scoped query misses them
entirely. They surface only in collection-group scans.

**Business impact.** None to billing today. They would pollute any cross-tenant aggregate or integrity
report.

**Recommended fix.** Authorized deletion, export-first. **Irreversible** — must be a deliberate act.

---

## FEE-005 — 1,759 demands carry a blank `category`

| Field | Value |
|---|---|
| **Severity** | **Low** |
| **Module** | Fees (generation) |
| **Type** | Implementation consistency |
| **Blocks Student Entitlements?** | No — **verified** |
| **Status** | ❌ Not fixed — documented |

**Root cause.** Two generators disagree. `Fee_lifecycle::createModuleFee()` always writes a lowercase
`category` (`transport`, `hostel`). `Fees.php`'s bulk/tuition generator does not set one at all.

**Technical evidence.** Of 1,946 live demands: `""` = 1,857 (1,759 excluding load-test), `Academic` = 48,
`Transport` = 24, `General` = 17.

**AC12 explicitly verified as SAFE.** The TC clearance check identifies Hostel/Transport dues by
`category`, and **every** transport demand does carry one. The blanks are all tuition. AC12 is unaffected.

**Also observed — mixed casing.** Live data holds `Transport` (capital, legacy) while new writes use
`transport`. This is precisely why the Student Entitlements G2 fix made the comparison case-insensitive,
and production data confirms both forms coexist. **Do not "normalise" this without auditing every reader.**

**Files:** `application/controllers/Fees.php` (generator), `application/libraries/Fee_lifecycle.php`.

---

## Summary

| ID | Severity | Blocks SE? | Status |
|---|---|---|---|
| FEE-001 | Critical | Yes (did) | ✅ Fixed, authorized |
| FEE-002 | High | No | Documented |
| FEE-003 | Low | No | Documented — resolve via SE convergence |
| FEE-004 | Medium | No | Documented — needs deletion authorization |
| FEE-005 | Low | No | Documented |

**Only FEE-001 met the bar for immediate repair** (blocked certification, no workaround, minimal fix,
explicitly authorized). Everything else is documented and left untouched.

**The FEE-002 sweep across all Fees aggregations is the single highest-value follow-up** and should be
scheduled as its own effort, not folded into Student Entitlements.
