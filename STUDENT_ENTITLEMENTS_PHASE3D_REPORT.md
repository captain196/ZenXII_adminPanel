# STUDENT ENTITLEMENTS — PHASE 3D: UNIFY FEE-GENERATION PATHS

**B3 = BLOCKED. Objective 3 gated on ONE decision (scholarship). Golden master CAPTURED.**
**Two live defects found and registered — P3D-F1 (HIGH) and P3D-F2 (MEDIUM).**
**Zero production change · no flag changed · no live data touched · nothing committed**
**Date:** 2026-08-17 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. What changed since the previous 3D report

**A correction to my own prior report.** I said Objectives 1 *and* 2 gate the routing. Re-tracing shows
**Objective 1 does not.** `Fees::_getStudentDiscounts()` never reads `studentDiscounts` — that discount
is applied at the **collection counter** (`fetch_months():2539`), a surface the routing does not touch.
Only **Objective 2 (scholarship)** gates Objective 3, because `_getStudentDiscounts()` *does* read
`readScholarshipAwards()`.

So rather than repeat "blocked", I did the brief's other mandated, ungated work: **capture the golden
master**. That turned up two defects.

---

## 2. P3D-F1 (HIGH) — the recalc engine ignores its own parameter, and kills the request

`_recalculateStudentUnpaidDemands(string $studentId, array $feeChart, array $discountMap)` is a
**private helper that receives the student as a parameter** — and immediately discards it:

```php
private function _recalculateStudentUnpaidDemands(string $studentId, …): array
{
    $this->_require_post();
    $this->_require_role(…);
    $studentId = trim($this->input->post('student_id') ?? '');   // ← Fees.php:6899
    if ($studentId === '') {
        return $this->json_error('Student ID is required.');     // ← :6901
    }
```

**The reachable victim is `save_updated_fees()`** — saving a **class** fee chart. It loops the roster:

```php
foreach ($roster as $sid => $stu) {                              // :1525
    $dMap = $this->_getStudentDiscounts($sid, $class, $section); // :1528
    $res  = $this->_recalculateStudentUnpaidDemands($sid, …);    // :1529
```

`save_updated_fees()` contains **zero** occurrences of `student_id` — verified by count over its whole
body. So the engine reads `''`, hits `json_error()`, and `json_error()` ends in **`exit`**
(`MY_Controller:1546`).

**Consequences, all confirmed by trace:**

- The class fee-chart save **terminates on the FIRST student in the roster**.
- Every student after the first is **never recalculated**.
- The operator sees *"Student ID is required."* on a screen that has no student on it.
- The surrounding `try { … }` at `:1520` offers no protection — **`exit` is not an exception.**
- The comment at `:1518` says *"the structure + generation already succeeded, so a propagation hiccup
  must never fail the request"* — the intent was fail-soft, and the actual behaviour is fail-hard.

`recalculate_demands()` and `recalc_unpaid_discounts()` appear to work, but only because they happen to
POST the same student they pass. They are **correct by coincidence, not by contract.**

**Registered, NOT fixed** — it is outside Phase 3D's authorised scope and it changes financial
behaviour. It also **blocks the flag-OFF equivalence gate**: you cannot prove "current == routed" for
`save_updated_fees()` against a path that cannot execute past its first student.

---

## 3. P3D-F2 (MEDIUM) — the two legacy engines disagree about scholarship

- `_generateDemandsForMonth()` apportions the scholarship across the month's heads (`:4936-38`).
- `_recalculateStudentUnpaidDemands()` reads **only** `$discountMap[$feeHead]` and never touches the
  reserved `_scholarship` key.

So generating a month **applies** the scholarship, and recalculating that same month **silently removes
it** — the student's bill goes **up** on a recalculation that changed nothing else. Registered, not
fixed.

---

## 4. Golden master — captured

`tests/Unit/Phase3dLegacyEngineGoldenMasterTest.php` — **14 tests, 51 assertions, all passing.** A
characterisation suite: it asserts what the code does *today*, and where today's behaviour is wrong the
test name and message say so, so it can never be mistaken for an endorsement.

| Captured contract | Behaviour |
| --- | --- |
| Discount vocabulary | `-1` = fully exempt · positive = per-head fixed · `_scholarship` = reserved aggregate |
| Fully-exempt head | **no demand row written** — the head is skipped, not zero-valued |
| Scholarship spreading | full award × (head ÷ monthTotal), **on every month generated** |
| Clamping | discount can never exceed the head; net never negative |
| Net derivation | `net = amount − discount`, always from **gross** → repeated generation cannot compound |
| April | merges `Yearly Fees` into the month |
| Idempotency | deduped by `periodKey \| canonicalFeeHeadId`, seeded from existing demands **and** guarded within the run; `archived` demands excluded so a head can be re-created |
| Recalc fence | demands carrying `concessionApplied` are **preserved**, never re-derived |
| Recalc deletion | a head removed from the chart leaves its demand **untouched** — billing history is never destroyed |
| Recalc error path | empty student id ⇒ `json_error` ⇒ **`exit`** (P3D-F1) |

**Routing feasibility, now evidenced rather than asserted:** the canonical builder
(`buildAdmissionDemandSpecs`) contains **no scholarship concept at all** and **no `$discountMap`** — it
emits gross specs and leaves every deduction to `Fee_generation_service`. That separation is what makes
one authority possible, and it is also why **routing would silently DROP scholarship pricing** unless
scholarship is carried across deliberately. That is Objective 2, and it is the gate.

---

## 5. Objectives 1 & 2 — decisions still required

Unchanged from the previous report; restated compactly.

**Objective 1 — `studentDiscounts`.** `onDemandDiscount` is a **live pricing input at the counter**
(`netDue = totalRemaining − discAmt`, expiring via `validUntil`), no head scope, no `effectiveFrom`, no
approval. `scholarshipDiscount` / `totalDiscount` are display aggregates nothing prices from. It
**stacks** on top of any demand-level concession — inert today (0 concessions), live the moment the flag
flips. **Recommendation: C** — keep as an explicit counter-level discount that may legitimately stack,
shown on the receipt. **Does not gate Objective 3.** 1 live record, `SCH_218AAF5C23`, untouched.

**Objective 2 — `scholarshipAwards`. THIS IS THE GATE.** It is **pricing, not merely recording**. No
sponsor, funder or receivable field exists; `award_scholarship()` writes no journal and no receipt. Two
questions:

1. Is an award amount **per month** or **for the year**? The full amount is applied to every month
   generated, so a ₹5,000 award reduces April by ₹5,000, May by ₹5,000, … Either that is intended, or
   it is a 12× over-discount. Nothing in the record distinguishes them.
2. Is it **funded by a sponsor**? If yes, the correct model is a **receivable + a full-price demand**
   (the shape `govtReceivable` already uses for RTE/EWS), not a concession — collapsing it would erase
   the receivable and understate income.

**0 award records live**, so nothing is mispriced today.

---

## 6. Unexpected: `firestore.rules` changed — not by me

`firestore.rules` moved from `4f9ed6e1` to **`3d835a56`** during this phase. **I did not touch it.**
The diff (657 insertions / 31 deletions, file mtime 23:49:52) is Staff-Lifecycle / HRMS Phase 0E–1
`isSameSchoolWrite()` hardening — entirely unrelated to entitlements. This is the same concurrent-session
pattern already recorded as P20-F14.

Reported, not acted on. The digest quoted in my earlier reports is now stale; the current baseline is
`3d835a56`.

---

## 7. Required answers

| Item | Status |
| --- | --- |
| **B3** | **BLOCKED** |
| **`studentDiscounts` decision** | **REQUIRED** — recommendation **C**. *Does not gate Objective 3.* |
| **Scholarship decision** | **REQUIRED — this is the gate.** per-month vs annual, funded vs unfunded |
| Five endpoints routed | **No** — gated on the scholarship decision |
| **Flag-OFF golden master** | **CAPTURED** — 14 tests, 51 assertions |
| Flag-ON equivalence | Not attempted — nothing routed yet |
| Admission / Generate / Recalculate totals | **₹800 / ₹1000 / ₹1000** with the flag ON (unchanged) |
| Repeated recalculation stable | **Yes** — net is derived from gross every run, proven in both engines |
| Accounting consistent | **Yes.** No pricing path writes an accounting entry; `award_scholarship()` writes none either |
| Double deduction possible | **Yes** — demand-level concession + counter-level `onDemandDiscount`. Inert today |
| Historical migration required | **None** (all collections 0 except 1 `studentDiscounts` record) |
| Legacy readers retired | **No** — gated behind successful routing, per the brief |
| `USE_ENTITLEMENT_BILLING` | **false** |
| `REAL_ADMISSION_READY` | **FALSE** |
| Tests before / after | **1769 → 1783** (14 added) |
| Baseline / new failures | **10 / 0** |
| Files changed | **None in production.** One new test file |
| Rules / indexes changed | **Not by me.** `firestore.rules` changed by another session (§6); `firestore.indexes.json c473c914` unchanged |
| Live data changed | **None** |
| Commit / push / deploy | **NO / NO / NO** |

---

## 8. What I need to finish Objective 3

**One answer: the scholarship question in §5.** With it, the routing becomes a defined job:

1. **Fix P3D-F1 first** — the routing cannot be equivalence-tested against a path that exits mid-loop.
   This needs its own authorisation; it changes behaviour (`save_updated_fees` starts completing).
2. Decide how scholarship reaches the canonical generator (entitlement, or its own demand line).
3. Route the five endpoints through `Fee_lifecycle`, proving flag-OFF equivalence against the golden
   master captured here.
4. Then prove ₹800 on all five with the flag on, and only then retire the orphaned readers.

**Stopping for your review. No commit, no push, no deploy. `USE_ENTITLEMENT_BILLING` remains false.**
