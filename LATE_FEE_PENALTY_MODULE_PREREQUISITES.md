# Late Fee / Penalty Module — MANDATORY Prerequisites

**Status:** NOT STARTED. Module not authorized. This document exists so the two blockers below are
**mandatory, non-skippable items** the moment penalty work begins — not rediscovered late.
**Origin:** Phase A final verification pass (2026-08-08), performed at operator request to confirm nothing
in Phase A would obstruct a future penalty module. Phase A itself introduces **no** obstruction; these two
blockers are **pre-existing** and independent of Phase A.

> **Operator directive (2026-08-08):** these are implementation prerequisites, not backlog suggestions.
> Both must be closed **before** penalties are enabled for any fee category.

---

## What already exists (verified live, not dormant)

A partial late-fee mechanism is already wired and functional:

| Piece | Location | State |
|---|---|---|
| Policy config | `feeSettings/{schoolId}_{session}_reminders` — `late_fee_enabled`, `late_fee_type` (`fixed`\|`percentage`), `late_fee_value` | Live |
| Computation | [`Fees::compute_fines_for_student()`](application/controllers/Fees.php#L4406) | Live, **manual trigger only** |
| Trigger | `fees/auto_compute_fines` POST, from the Student Ledger screen | Live, no cron |
| Demand field | `fineAmount` (canonical camelCase; `fine_amount` alias via `Fee_firestore_txn::normalizeDemandDoc`) | Live |
| Accounting | `Operations_accounting::create_fee_journal` already accepts `fine_amount` | Live, **at collection time only** |
| Parent App visibility | `firestore.rules` grants document-level read on `feeDemands` to own-student; writes admin-only | **No rules change needed** |

Verification note: an initial reading suggested this mechanism was dead code, because
`compute_fines_for_student()` reads snake_case (`due_date`, `net_amount`, `paid_amount`) while the canonical
writer `Fee_firestore_txn::_buildDemandDoc()` emits camelCase only. That was **wrong** —
`normalizeDemandDoc()` bridges both directions on every read path. The mechanism works. Do not "fix" a
naming bug here that does not exist.

---

## BLOCKER 1 — `dueDate` is set by only one of the two fee generators

**Evidence.** Two independent generators write `feeDemands`:

- [`Fees.php::_processGenerationJob()`](application/controllers/Fees.php#L4932) (bulk/manual generation) — **sets `dueDate`**, computed from the school's configured due-day.
- [`Fee_lifecycle`](application/libraries/Fee_lifecycle.php) — `assignInitialFees()` (runs at **admission**), `reassignFeesOnPromotion()` (runs at **promotion**), `createModuleFee()` (Transport/Hostel) — **never set `dueDate`**. Grep count for `dueDate|due_date` across the whole file: **0**.

**Why it blocks.** Fine accrual gates on `if ($dueDate === '' || $dueDate >= $today) continue;`. A demand with
no `dueDate` can never accrue a penalty. So every student admitted or promoted through `Fee_lifecycle` — i.e.
every mid-year admission and every promoted student whose demands were regenerated — would be **silently
exempt from late fees forever**, while students in a bulk-generated class accrue them normally. Penalties
applied to an arbitrary, invisible subset of the roll is worse than no penalties at all: it is unexplainable
to parents and indefensible commercially.

**Required before penalties ship.** Every fee-generation path must assign `dueDate` consistently, from one
shared resolver (the due-day logic already exists in `Fees.php` — reuse it, do not reimplement). Include a
backfill decision for demands already written without `dueDate`: penalties must not retroactively appear on
historical demands the school never intended to penalize.

---

## BLOCKER 2 — `proRateFees()` is not fine-aware

**Evidence.** [`Fee_lifecycle::proRateFees()`](application/libraries/Fee_lifecycle.php#L544) recomputes the
demand as:

```php
$newNet = round($grossAmt * $factor, 2);
$newBal = round($newNet - $paidAmt, 2);   // fineAmount is not a term
```

`fineAmount` is absent from both the recomputation and the written patch. Compare
[`compute_fines_for_student()`](application/controllers/Fees.php#L4406), which defines the balance as
`netAmount + fine − paidAmount`. The two functions disagree on what `balance` means.

**Why it blocks.** Any proration of a demand carrying an accrued fine silently erases that fine from the
balance while `fineAmount` still reads non-zero on the document — an internally contradictory record, and a
revenue loss that no report would surface.

**Currently harmless, and exactly why it is easy to miss:** module fees (Transport/Hostel) carry no
`dueDate` (Blocker 1), so they accrue no fines, so today no prorated demand ever has a fine to erase. The
collision becomes live the moment **either** of these lands:

1. Blocker 1 is fixed (module fees gain `dueDate` → they start accruing fines → proration erases them), **or**
2. **G11** is fixed — the mid-year tuition proration gap in
   `buildAdmissionDemandSpecs()`. Tuition demands from the bulk generator *do* have `dueDate` and *do*
   accrue fines, so introducing tuition proration collides immediately.

Note the trap: fixing Blocker 1 **activates** Blocker 2. They must be closed together, in that order, or
fixing the first silently creates the second.

**Required before penalties ship.** `proRateFees()` must treat `fineAmount` as a first-class term and adopt
the same balance definition as the fine calculator. Settle explicitly whether an accrued penalty survives
proration at all — a defensible school policy could argue a penalty for late payment of a fee that is
subsequently reduced should be recomputed, waived, or retained. That is a **policy decision to be made
explicitly**, not left to whichever function happens to write last.

---

## Related, already-tracked (do not duplicate here)

- **Over-collection on proration.** `proRateFees()` can push `netAmount` below `paidAmount`; the balance
  floors at 0 and the family's credit is not recorded. Made *reachable* by Phase A's G1/G2 fix and therefore
  made **observable** in Phase A via the `FEE_PRORATE_OVERCOLLECTION` error log
  ([Fee_lifecycle.php](application/libraries/Fee_lifecycle.php#L588)). The remedy — credit notes — is
  already specified as **Phase C / AC4** in `STUDENT_ENTITLEMENTS_ARCHITECTURE_BASELINE.md`. Not a penalty
  prerequisite; listed so the penalty work does not re-solve it.

## Gaps to build (design work, NOT blockers)

Not obstructions — these are simply the module's scope, recorded so it is not under-scoped later:

- **Grace periods** — none exist; fines fire the day after `dueDate`.
- **Configurable accrual start** — not configurable.
- **Recurring accrual** — the current rule is strictly **one-time**: `if (abs($existingFine - $fine) < 0.01) continue;` prevents any growth on re-run. Daily/weekly/monthly accrual needs a different model (accrual ledger or last-accrued-date + period count), not a tweak to this guard.
- **Scheduled accrual** — no cron; today a human presses a per-student button on the Student Ledger. Production-grade penalties need scheduled, idempotent, tenant-scoped accrual.
- **Fine waiver with audit** — does not exist. The Phase A G10 admission-fee waive is a directly reusable template: separate graded capability (not a side effect of the module's own grant) + mandatory reason + `log_audit` + student-history entry.
- **Accounting recognition point** — fines are journaled at **collection**, never at **accrual**. Whether accrued-but-uncollected penalties should be recognised as receivable is an accounting-policy decision to take deliberately, with the accountant, before implementation.
- **Reporting** — no penalty-specific aging/collection reports exist.

## Architecture compatibility (verified — no redesign required)

- **Firestore rules / Parent App:** `feeDemands` already grants document-level read to own-student, so
  `fineAmount`/`dueDate` are visible to the Parent App with **no rules change**. Writes stay admin-only.
- **Student Entitlements (frozen baseline):** penalties are a natural sibling of concessions — a
  student-scoped, effective-dated, audited modifier on generated demands. The frozen model accommodates them
  without redesign.
- **Student 360 / Action Registry:** a `Penalty_service` exposing `Action_provider` slots in exactly as
  `Entitlement_service` does — one domain, one facade, one provider. "Waive Penalty" becomes an
  `ActionDescriptor` with its own `requiredPermission`, consistent with the frozen contract.
- **Phase A:** introduces no obstruction. G10 and G12 touch neither demand generation nor the fine path.
