# ZENX HRMS — W2.3 SALARY ASSIGNMENTS — VERIFICATION REPORT

**Date:** 2026-08-09
**Gate:** W2.3 — effective-dated salary assignments + dry-run migration
**Result:** **IMPLEMENTED AND VERIFIED — ZERO PAYROLL BEHAVIOUR CHANGE, NO DATA MIGRATED**
**Status:** UNCOMMITTED, UNPUSHED, rules **NOT DEPLOYED**
**Next gate:** W2.4 — `Compensation_reader` + shadow-compare parity gate

---

## Summary

Compensation is now **dated**. A revision supersedes rather than overwrites, so a past period stays reconstructable — the prerequisite for arrears, the payroll snapshot, and any audit that must answer *"why was this person paid this in March"*.

Three things landed beyond the plan, all driven by your guardrail:

1. **`readiness()`** — the "missing configuration → visible setup requirement" contract, as a first-class surface. It names the people who block payroll and why, rather than letting a run proceed with zeros.
2. **`BALANCING` calcType** — from the artifact comparison. Absorbs the remainder of gross so a structure's parts sum exactly to the whole.
3. **Effective-dating extracted to a shared helper** — W2.2 now delegates to it. One implementation, not two.

The migration ran **dry-run only**: 8 legacy structures in 1 tenant, **0 documents written**.

---

## 1. What "effective-dated" actually buys

```
BEFORE                                  AFTER
salarySlips/{school}_SAL_{staff}        staffSalaryAssignments/{school}_{staff}_{effectiveFrom}
  ├ one record, overwritten               ├ 2025-04-01 → 2026-03-31   ₹30,000   superseded
  ├ _version: 4                           └ 2026-04-01 → open          ₹42,000   active
  └ _prev: 4 fields of the last edit
                                        March 2026 still resolves to ₹30,000.
  March 2026 is unrecoverable.
```

`_version` + a four-field `_prev` is an audit breadcrumb. It cannot answer what a closed period was paid from, which is why arrears (cap 49) were impossible.

---

## 2. Readiness — the guardrail, implemented

Your flow, as code:

```
missing configuration → visible setup requirement → authorized admin configures
    → effective date → validation → payroll becomes eligible to run
```

`readiness($staff, $date)` returns **named people with actionable reasons**, not an aggregate:

| Blocked because | Reason returned |
|---|---|
| never configured | "No salary assignment is effective for this period." |
| still a draft | "The effective salary assignment is still a draft." |
| assignment has no components | "The effective salary assignment has no components." |

`summary.payrollEligible` is true only when **nobody** is blocked. `resolve()` returning `null` is documented as a real answer meaning *not configured* — callers must treat it as a block, never as zero pay.

This directly addresses cap 41 ("pre-run warnings are counts, not people"), whose full form lands in W2.5.

---

## 3. `BALANCING` — from the artifact comparison

The prototype's Components tab had a "Balancing" component: *"fills whatever is left of Gross."* That is how real CTC templates are built — an operator types a Monthly Gross and one line absorbs the remainder so the parts always sum to the whole.

Added at **schema level now** because introducing a calcType later forces a migration of every stored component and assignment line.

```
gross 50,000
  BASIC   FIXED             20,000
  HRA     PERCENT 40% BASIC  8,000
  SPECIAL BALANCING         22,000   ← derived, never stored
                            ------
                            50,000   balanced = true
```

Guards: it carries neither rate nor amount (a stored value would be a second source of truth); only one per structure (two cannot both claim the remainder); only an *earning* can balance; it needs a gross to balance against. An **over-subscribed** structure clamps the line at zero and reports a negative `remainderPaise` — visible, not silently absorbed.

---

## 4. Shared effective-dating (refactor)

W2.2 and W2.3 both need "which record is in force?" and "do these windows overlap?". Two implementations would eventually disagree, and the disagreement would surface as a period resolving to one statutory config but a different salary assignment — silently wrong, hard to trace.

Extracted to `helpers/effective_dating_helper.php`; `Statutory_config_service` now delegates. Same reasoning as extracting `hr_next_id()` in W1.3. **W2.2's 37 tests pass unchanged after the extraction**, and a W2.3 test asserts both paths return identical results.

Dates compare as strings — exact for zero-padded ISO, with no timezone or server-locale dependency.

---

## 5. Data model

```
staffSalaryAssignments/{schoolId}_{staffId}_{effectiveFrom}
  staffId · effectiveFrom · effectiveTo|null
  payBasis   MONTHLY_FIXED | DAILY | HOURLY     (only MONTHLY_FIXED is payable)
  status     draft | active | superseded
  templateId|null · payGrade|null · supersedesId|null · reason
  lines[]  { componentId, calcType, basis, rate|null, amountPaise|null }
  grossPaise|null · ctcPaise|null
  createdAt/By · updatedAt/By
```

**`calcType` and `basis` come from the catalogue, not the payload.** Taking them from input would let one employee's PF silently become a fixed amount. A line may only reference a component the tenant actually has.

**Money:** integer paise; rates stay percentages. `splitGross()` reuses `Statutory_config_service::applyRatio()` — runtime-proven identical to `Hr::_applyRatio()` — so there is no third money policy.

**`DAILY`/`HOURLY` are refused**, not silently accepted: wiring them means changing the W1.2-certified proration arithmetic, and their consumers (Retail) are deferred. Modelled so no migration is needed later.

---

## 6. Migration — dry-run only

`scripts/w2_3_migrate_salary_assignments.js`, **dry-run by default**:

```
MODE: DRY RUN (nothing will be written)
Tenant SCH_D94FE8F7AD — 8 structure(s)
  WOULD  STA0001  gross=₹15000.00  lines=13
  …
  WOULD  STA0008  gross=₹10000.00  lines=13
would write: 8   already present: 0
legacy salarySlips structures: UNTOUCHED
```

**`--commit` additionally requires `--effective-from=YYYY-MM-DD`.** The legacy record has `created_at`/`updated_at`, but neither is a compensation effective date — `updated_at` is when someone last edited the row, possibly years after the salary took effect. Deriving a date from it would fabricate exactly the history W2.3 exists to make trustworthy. **The operator states the date; the script refuses to guess.**

Idempotent: doc id is `{schoolId}_{staffId}_{effectiveFrom}`, so a re-run targets the same document. Existing assignments are **skipped, never overwritten**.

The PHP mapper and the JS script implement the same mapping, and a test asserts the mapper's output passes the service's own validation — so the migration cannot produce a record the service would reject.

---

## 7. Security rules

```
match /staffSalaryAssignments/{docId} {
  allow read: if isAdmin() && isSameSchool();
  allow write: if false;
}
```

Server-only, for the reasons in W2.1/W2.2 plus one specific to this collection: assignments are effective-dated, so a forged window would rewrite what a **closed** period resolves to — defeating the reconstructability the dating exists to provide.

**19 rules tests**, including: a staff member cannot raise their own salary · cannot forge a back-dated window · cannot close an existing window to make room for another · cross-tenant read/write/delete · forged and missing `schoolId`.

**Deliberately absent:** own-record read for ESS. It is a real requirement, but it belongs in W5 designed alongside the payslip surface — a half-predicate here would be the more dangerous half. A test pins the current denial so nobody assumes it already works.

**No index required** — the only query is `schoolId` + `staffId` equality with PHP-side ordering. *(If W2.4 adds `orderBy effectiveFrom`, a composite index becomes necessary — flagged for that gate.)*

---

## 8. Tests

| Suite | Count |
|---|---|
| `SalaryAssignmentTest` (behavioural) | **29 tests / 84 assertions** |
| `salary_assignments_sec.test.js` (rules) | **19 tests** |

Covering: validation · catalogue-bound calcType · duplicate lines · integer paise vs rupees · rate bounds · unwired pay bases · the four BALANCING guards · exact remainder split · over-subscription visibility · earnings-only split · effective-date resolution · supersede-not-overwrite · overlap detection · shared-helper equivalence · readiness (named/blocked/eligible) · legacy mapping (paise conversion, percent preservation, calculator bases, gross = earnings only, validation round-trip, determinism) · payroll-path boundary · no RTDB · no hardcoded tenant.

---

## 9. Regression

| Suite | Before W2.3 | After W2.3 | New failures |
|---|---|---|---|
| PHP unit | 255 / 838 / **10** | **284 / 922 / 10** | **0** |
| Firestore rules | 389 / 17 suites | **408 / 18 suites** | **0** |
| `hrms_p0_verify` (W1) | PASS | **PASS** | 0 |
| `hrms_w13_verify` | 44 / 0 / 1 | **44 / 0 / 1** | 0 |
| `hrms_closure_verify` | 24 / 0 / 0 | **24 / 0 / 0** | 0 |
| `hrms_w2_verify` | 24 / 0 / 0 | **24 / 0 / 0** | 0 |

10 PHP failures unchanged and pre-existing (1 `BackupTest` drift, 4 `Homework*`, 5 cross-repo Kotlin). W1 invariants green: `gross == net + deductions` · `Dr == Cr` · `M` half-day · `W` worked-on-off · LOP double-count · employment-window proration.

**W2.1 and W2.2 re-verified after their edits** (BALANCING, delegation): 26/26 and 37/37, unchanged.

---

## 10. Confirmations

- **Payroll unchanged** — `Hr.php`/`Payroll.php` contain no `staffSalaryAssignments`, no `Salary_assignment_service`, no `statutoryConfig`, no `salaryComponents`. Unit-tested and runtime-checked.
- **No data migrated** — dry-run only; 0 documents written; `salarySlips` untouched.
- **No RTDB**, **no hardcoded tenant** (grep-asserted in tests).
- **No new entitlement key**; existing `hrms_core`/`hrms_payroll`.
- **No new RBAC module** — graded `HR` (`view`/`manage`). Gap 92 remains W5.

---

## 11. Known limitations

1. `DAILY`/`HOURLY` modelled, not payable.
2. `FORMULA` still has no evaluator.
3. No UI — endpoints only (W2.5).
4. ESS own-record read deliberately absent (W5).
5. `payGrade` is stored but there is **no grade master or band validation yet** — W2.5. Storing the field now avoids a migration; the out-of-band warning from the artifact needs the master.
6. `ctcPaise` is accepted but not derived (CTC = gross + employer contributions needs the statutory engine wired — W2.4).
7. Readiness checks assignment presence/status/lines only; bank/PAN/statutory-ID checks are cap 41, W2.5.
8. Migration needs the W2.1 component seed run first — the catalogue is still unseeded on every tenant.

---

## 12. Decision still open (unchanged from W2.2 §17)

No statutory configuration has been written for any tenant. That decision — which tenants, what PF ceiling and ESI threshold, and the PT state normalisation — is still yours. **W2.3 did not touch it**, and the readiness surface now makes the consequence visible rather than silent: an unconfigured tenant will report blocked staff instead of producing wrong payslips.

---

## 13. Files changed

**New (5):** `application/helpers/effective_dating_helper.php` · `application/libraries/Salary_assignment_service.php` · `tests/Unit/SalaryAssignmentTest.php` · `firebase-rules/tests/salary_assignments_sec.test.js` · `scripts/w2_3_migrate_salary_assignments.js`

**Modified (5):** `Statutory_config_service.php` (delegates effective dating) · `Salary_component_service.php` (+`BALANCING`) · `Compensation.php` (+3 endpoints) · `routes.php` (+3) · `firestore.rules` (+1 block, **not deployed**)

**Not changed:** `Hr.php` · `Payroll.php` · `Staff.php` · `Attendance.php` · `salarySlips` · any existing rule · any view.

---

## 14. Next gate: W2.4 — the parity gate

`Compensation_reader` + `USE_COMPONENT_COMPENSATION` flag (**default false**) + shadow-compare. The reader returns the same 13-field shape `_compute_payslip()` already accepts, so the money contract never reopens. The gate is a shadow run over real data with **zero divergence**, reported as *"no divergence detected"* — never *"equivalence proven"*.

**Nothing committed, pushed, merged or deployed.** Awaiting review.
