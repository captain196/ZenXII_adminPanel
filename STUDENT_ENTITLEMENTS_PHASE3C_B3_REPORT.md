# STUDENT ENTITLEMENTS — PHASE 3C / B3: THIRD DISCOUNT AUTHORITY

**B3 = BLOCKED — two stop conditions met. Audit complete, remediation NOT implemented.**
**No production code changed · no flag changed · no live data touched · nothing committed**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Executive verdict

Two of your declared stop conditions are met, so I audited and stopped rather than implementing:

> - *a third discount authority is still capable of silently changing the bill*
> - *initial admission and recalculation use different entitlement authorities*

And the problem is **larger than the brief describes** in two ways:

1. **It is not one endpoint.** `recalculate_demands()` was the example; the legacy authority is
   actually used by **five** endpoints, including **`generate_demands_for_student()`** and the **bulk
   generation job** — i.e. the "Generate Demands" button the Phase 3B `billing_failed` message tells
   operators to press. That message currently sends them to a path that cannot see the entitlement.
2. **There is a FOURTH authority the audit had not recorded:** `studentDiscounts`
   (`onDemandDiscount` / `scholarshipDiscount` / `totalDiscount`), written by a live Fee-management
   surface, and it is the **only one of the five with live data** — 1 document, in a tenant that is
   not our test school.

Nothing is at risk *today*: every collection the two canonical readers use is empty, and the two
pricing paths cannot see each other's sources, so they cannot double-deduct on a single run. The
hazard is **divergence** — a different total depending on which button was pressed — which becomes
live the moment `USE_ENTITLEMENT_BILLING` is turned on.

---

## 2. Step 0 — the complete authority map

| # | Source | Written by | Read by | Live data | Status |
| --- | --- | --- | --- | --- | --- |
| 1 | `studentConcessions` | `Fee_concessions.php:249,268` | `Fee_concession_reader` → unified generator | **0** | legacy billing authority (flag OFF) |
| 2 | `studentEntitlements` | `Entitlement_service` only | `Fee_entitlement_reader` → unified generator | **0** | canonical authority (flag ON) |
| 3 | `students.exemptedFees` | **NOTHING** — writes removed at Fees v2 Phase 0-b (`Sis.php:1103`, `:7826`) | `Fees::_getStudentDiscounts():4749`, `:2599`, `:4144` | **0** | **orphaned reader** |
| 4 | `students.discountHeads` | **NOTHING** anywhere | `Fees::_getStudentDiscounts():4753` | **0** | **orphaned reader** |
| 5 | `scholarshipAwards` | `Fee_management.php:1389,1518` | `Fee_firestore_txn::readScholarshipAwards()` → `_getStudentDiscounts()` | **0** | **live business feature** |
| 6 | **`studentDiscounts`** | `Fee_management.php:1084,1414,1549`; `Fees.php:1876,1911,1962` | `Fees.php:1870,1955,2525,2581,3493` | **1 doc** | **live, and NOT in the brief's list** |

**Consumers of the legacy map `_getStudentDiscounts()` — five, not one:**

| Endpoint | Line | What it is to an operator |
| --- | --- | --- |
| `save_updated_fees()` | 1528 | saving the class fee chart |
| `recalc_unpaid_discounts()` | 1718 | recalculate discounts |
| **`generate_demands_for_student()`** | **5162** | **"Generate Demands" for one student** |
| **`_processGenerationJob()`** | **5571** | **bulk demand generation** |
| `recalculate_demands()` | 7036 | recalculate demands |

None of them routes through `Fee_lifecycle`, so **none is affected by `USE_ENTITLEMENT_BILLING`.**

The UI inputs `exempted_fees_multiple[]` still exist in `studentAdmission.php` and `edit_student.php`,
but **no controller reads them from POST** — dead form fields left behind by Phase 0-b.

---

## 3. Steps 1 & 2 — are these real business features? The semantic matrix

| Source | Business meaning | Discount type | Fee heads | Dates | Approval | Live UI | Maps to an entitlement? |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `exemptedFees` | "this student does not pay head X" | full exemption, per head | named heads | none | none | **no** (dead inputs) | **Yes** — `scope: head:<name>`, `valueType: fullExempt` |
| `discountHeads` | "reduce head X by ₹N" | fixed, per head | named heads | none | none | **no writer at all** | **Yes** — `scope: head:<name>`, `valueType: fixed` |
| `scholarshipAwards` | an **award** to a student, often sponsored, with its own lifecycle | fixed amount | aggregated, not head-scoped | award/revoke | **yes** — Fee_management | **Partially** — see below |
| `studentDiscounts` | an operator's ad-hoc per-student discount + a scholarship mirror | fixed amount | aggregate | session-scoped | none | **yes** — Fee_management | **Yes** — `scope: all`, `valueType: fixed` |
| `studentConcessions` | dated, audited per-student concession | percent/fixed/fullExempt | all/head/category | yes | status | yes | **direct** (Phase 1 migration) |
| `studentEntitlements` | the unified successor | percent/fixed/fullExempt | all/head:/category: | yes | maker-checker | yes | **is the target** |

**The distinctions that must NOT be collapsed:**

- **A scholarship is not a concession.** A concession is a school-granted price reduction. A scholarship
  is an *award*, frequently with an external sponsor and a receivable, and it has its own
  grant/revoke lifecycle and reporting. The entitlement model already anticipates this —
  `concessionType: 'scholarship'` and the `govtReceivable` structure exist — but folding
  `scholarshipAwards` into `studentEntitlements` would need the award lifecycle and any sponsor
  accounting to come with it. **That is a migration, not a mapping**, and I have not attempted it.
- **`studentDiscounts` is an aggregate, not a rule.** It records *how much* was discounted, not *why* or
  *against which head*. Translating it into an entitlement requires inventing a scope and a reason that
  the data does not contain.
- **`exemptedFees` / `discountHeads` map cleanly** and have no writers and no data — they are the safe
  part to retire.

---

## 4. Step 3 — the conflict matrix, executable

`tests/Unit/Phase3cThirdAuthorityTest.php` — **13 tests, 44 assertions, all passing.** Real generator,
real entitlement reader, real deduction arithmetic; source read with comments stripped so no comment can
satisfy an assertion.

| Case | Result |
| --- | --- |
| 1. legacy third authority only | discount map empty → **₹1000** |
| 2. entitlement only | **₹800** — canonical path applies it |
| **1 vs 2** | **the two authorities disagree on the same student** |
| 3. both exist | **no double-deduction** — neither path can see the other's source; the hazard is divergence, not summing |
| 4. rejected entitlement | ₹1000 |
| 5. expired entitlement | ₹1000 |
| 6. full exemption | demand removed |
| 9/10/11. recalculation after admission / repeated / after approval | the **partial fence** at `_recalculateStudentUnpaidDemands` preserves demands carrying `concessionApplied` — but gives the legacy path **no way to apply** an entitlement |
| Idempotency (Step 7) | generate → recalc → recalc → regenerate is **stable at ₹800**; deductions are computed from **gross** every run, never compounded |

The fence is real and worth keeping: a canonically-priced demand cannot be overwritten by the legacy
path. What it does not do is help a student admitted *before* the entitlement was recorded — that demand
has no `concessionApplied`, so recalculation re-derives it from the legacy sources and the entitlement
stays unapplied forever.

---

## 5. Step 6 — historical data

**Quantified, read-only, platform-wide:**

| Measure | Count |
| --- | --- |
| Students scanned | **150** |
| Students carrying `exemptedFees` | **0** |
| Students carrying `discountHeads` | **0** |
| Schools affected | **0** |
| `scholarshipAwards` documents | **0** |
| `studentConcessions` / `studentEntitlements` | **0 / 0** |
| **`studentDiscounts` documents** | **1** |

The single `studentDiscounts` document belongs to tenant `SCH_218AAF5C23` — **not** the authorized test
school — and carries `onDemandDiscount: 1800`, `totalDiscount: 1800`, session `2026-27`. I did not read
its student's other data, and did not touch it.

**No migration is required for `exemptedFees` / `discountHeads` / `scholarshipAwards`: there is nothing
to migrate.** `studentDiscounts` has exactly one record and needs a decision, not a migration.

---

## 6. Steps 4 & 5 — the recommended resolution (designed, not implemented)

**Canonical authority: `studentEntitlements`,** reached through the reader boundary that B2 built.

| Source | Disposition | Why |
| --- | --- | --- |
| `exemptedFees` | **Retire the readers (B).** No writer, no data, maps cleanly. | Removing three read sites is far safer than leaving an input nothing can set but billing consults. |
| `discountHeads` | **Retire the readers (B).** | Same. |
| `scholarshipAwards` | **Keep as a separate business concept (B)** — but it must stop being a *pricing input* to a second generator. Its amount should reach billing as an entitlement, or as an explicitly separate demand line. | A scholarship is an award with its own lifecycle; collapsing it into a concession loses the sponsor/receivable dimension. |
| `studentDiscounts` | **Decision required.** One live record. Either translate it into an entitlement or declare it a reporting aggregate that must never price a demand. | The data does not carry a scope or a reason, so translation is a judgement call, not a mechanical map. |
| `studentConcessions` | Keep read-only as the migration source and audit trail; retire as an authority when the flag flips. | Already the plan from Phase 1. |

**The load-bearing change — and the reason B3 is blocked:** the five endpoints in §2 must route through
`Fee_lifecycle` so that **one** switch governs every pricing path:

```
admission · generate · regenerate · recalculate · bulk job · session change
        ↓
Fee_lifecycle::_routedBuildAdmissionSpecs()   ← the ONE authority boundary
        ↓
Fee_generation_service  →  reader (concession | entitlement)
```

That is a substantial change to `Fees.php` — five call sites, a different demand-builder
(`_generateDemandsForMonth` vs the unified spec builder), and its own golden-master obligations. It is
**not** a safe extension of this audit, and you asked me to stop on exactly this condition.

---

## 7. Required answers

| Question | Answer |
| --- | --- |
| **B3** | **BLOCKED** |
| **REAL_ADMISSION_READY** | **FALSE** |
| Canonical billing authority | `studentEntitlements`, via `Fee_entitlement_reader`, behind `USE_ENTITLEMENT_BILLING` (currently **OFF**) |
| Third authority status | **Still live as a pricing input** on five endpoints; two of its three sources are orphaned readers with no writer and no data; a **fourth** authority (`studentDiscounts`) was found |
| Initial admission authority | `Fee_lifecycle` → unified generator → concession reader (flag OFF) / entitlement reader (flag ON) |
| Recalculation authority | **`Fees::_getStudentDiscounts()`** — `exemptedFees` + `discountHeads` + `scholarshipAwards`. **Different from admission.** |
| Historical data requiring migration | **None** for exemptedFees / discountHeads / scholarshipAwards (0 records). **1 record** in `studentDiscounts` needs a decision. |
| Any double-discount path | **No.** The two paths cannot see each other's sources, so they never sum on a single run. The risk is **divergence between runs**. |
| Any accounting divergence | **None observed.** No accounting record is written by either pricing path; demands are the input to accounting, and the fence at `Fees.php:6944` stops a canonical demand being silently re-priced. |
| Tests before / after | 1756 → **1769** (13 added) |
| Baseline / new failures | **10 / 0** |
| Files changed | **None in production.** One new test file. |
| Flags changed | **None.** `USE_ENTITLEMENT_BILLING = false`. |
| Live data changed | **None.** Read-only throughout. |
| Rules / indexes changed | **None** — `firestore.rules 4f9ed6e1`, `firestore.indexes.json c473c914` |
| Commit / push / deploy | **NO / NO / NO** |

---

## 8. What Phase 3D must do, in order

1. **Decide `studentDiscounts`** (1 live record): entitlement, or reporting-only and never a pricing input.
2. **Decide `scholarshipAwards`**: reaches billing as an entitlement, or as its own demand line — but it
   must stop being an input to a *second* generator.
3. **Route the five endpoints through `Fee_lifecycle`** so one switch governs every pricing path. This is
   the real B3 fix and needs its own phase, with golden masters over the existing generation behaviour.
4. **Then** retire the `exemptedFees` / `discountHeads` readers and the dead
   `exempted_fees_multiple[]` form inputs.
5. Only after that does `USE_ENTITLEMENT_BILLING = true` mean the same thing on every button.

**Do not enable `USE_ENTITLEMENT_BILLING` before step 3.** With it on today, an admission would price at
₹800 and the very next "Generate Demands" would silently re-price the same student at ₹1000.

**Stopping for your review. No commit, no push, no deploy.**
