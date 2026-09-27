# ZENX HRMS — W2.1 SALARY COMPONENT MASTER — VERIFICATION REPORT

**Date:** 2026-08-09
**Gate:** W2.1 — component catalogue (first gate of W2 Compensation Core)
**Result:** **IMPLEMENTED AND VERIFIED — ZERO PAYROLL BEHAVIOUR CHANGE**
**Status:** UNCOMMITTED, UNPUSHED, rules **NOT DEPLOYED**
**Next gate:** W2.2 — statutory engine

---

## Summary

Salary components are now **data** instead of thirteen hardcoded columns. The catalogue is tenant-scoped, validated, and seeded with thirteen `systemLocked` components that mirror the existing calculator exactly.

Nothing reads it yet. `Hr.php` is byte-identical to before this gate (its diff is still `465+/145-`, the W1.3 figure), the legacy `salarySlips` structures are untouched, no migration ran, and no flag was introduced. Payroll behaves precisely as it did before.

**The seed is the substantive work.** The legacy fields carry semantics that exist *only* inside `Hr::_compute_payslip()` — the stored record never says whether a number is a percentage or an amount, what base a rate applies to, or whether attendance prorates it. Those rules were read out of the calculator line by line and encoded explicitly, because W2.2 and W2.4 will trust the declared metadata rather than re-derive it.

One asymmetry would have been got wrong from field names alone, and is now pinned by tests:

> `professional_tax` and `other_deductions` are **flat money that attendance never prorates** — the calculator takes them via `_toPaise()` with no retention ratio applied — while every earning *is* scaled, and PF/ESI/TDS are percentages of an already-prorated base.

---

## 1. Exact files changed

**New (4):**

| File | Purpose |
|---|---|
| `application/libraries/Salary_component_service.php` | Catalogue model, validation, seed. All logic lives here. |
| `application/controllers/Compensation.php` | 5 AJAX endpoints. Deliberately **not** added to `Hr.php` (~8,900 lines; ruled out as the home for new HRMS surface). |
| `tests/Unit/SalaryComponentMasterTest.php` | 26 behavioural tests |
| `firebase-rules/tests/salary_components_sec.test.js` | 18 rules tests |

**Modified (2):**

| File | Change |
|---|---|
| `application/config/routes.php` | +5 `compensation/*` routes |
| `firebase-rules/firestore.rules` | +1 `salaryComponents` block (**not deployed**) |

**Not changed:** `Hr.php`, `Payroll.php`, `Staff.php`, `Attendance.php`, any view, any config flag, `salarySlips`, any existing rule.

---

## 2. New Firestore collection

`salaryComponents/{schoolId}_{CODE}`

The component **code is the document-id suffix**, which makes duplicate codes structurally impossible per tenant. `create()` still checks explicitly, so a duplicate reports a clear error instead of silently overwriting.

---

## 3. Data model

Exactly the approved W2 model; nothing invented.

```
code             A-Z0-9_ , 2-40 chars, starts with a letter (it is a doc-id segment)
name             ≤ 80 chars
kind             earning | deduction | employer_contribution
calcType         FIXED | PERCENT | FORMULA
basis            BASIC | GROSS | CTC | COMPONENT_SET | NONE
basisComponents  [codes]  — only when basis = COMPONENT_SET
rate             decimal percent 0–100  — PERCENT only
amountPaise      integer paise          — FIXED only
taxable · pfApplicable · esiApplicable · prorated    booleans
statutory        null | PF | ESI | PT | TDS
displayOrder     int ≥ 0
active           bool
systemLocked     bool   — settable ONLY by the seed
createdAt/By · updatedAt/By
```

**Money contract (W1.2) upheld:** amounts are integer paise; a **rate is not money** and is never converted to paise; this file performs **no rounding at all**. `amountPaise` rejects decimals outright — a decimal there means someone passed rupees, which is the precise regression W1.2 exists to prevent.

**`FORMULA`** stays in the enum so the schema needs no later migration, but **creation is refused** until W2.2 provides an evaluator. Storing a component nothing can compute would be worse than not offering it.

---

## 4. System component seed

Thirteen components, all `systemLocked = true`, `active = true`, amounts and rates **zero** (the catalogue defines what a component *is*; per-employee values stay on the legacy structure until W2.3).

| Code | kind | calcType | basis | prorated | statutory |
|---|---|---|---|---|---|
| `BASIC` | earning | FIXED | — | **yes** | — |
| `HRA` `DA` `TA` `MEDICAL` `OTHER_ALLOWANCES` | earning | FIXED | — | **yes** | — |
| `PF_EMPLOYEE` | deduction | **PERCENT** | **BASIC** | yes | PF |
| `ESI_EMPLOYEE` | deduction | **PERCENT** | **GROSS** | yes | ESI |
| `TDS` | deduction | **PERCENT** | **GROSS** | yes | TDS |
| `PROFESSIONAL_TAX` | deduction | **FIXED** | — | **no** | PT |
| `OTHER_DEDUCTIONS` | deduction | **FIXED** | — | **no** | — |
| `PF_EMPLOYER` | employer_contribution | PERCENT | BASIC | yes | PF |
| `ESI_EMPLOYER` | employer_contribution | PERCENT | GROSS | yes | ESI |

Each value traces to a line in `_compute_payslip()`:

```php
$hraP        = _applyRatio(_toPaise($sal['hra']), $retention);      // earnings prorate
$pfEmployeeP = _applyRatio($effectiveBasicP, $pfPct  / 100);        // PF  → PRORATED BASIC
$esiEmployeeP= _applyRatio($grossP,          $esiPct / 100);        // ESI → GROSS
$tdsP        = _applyRatio($grossP,          $tdsPct / 100);        // TDS → GROSS
$professionalTaxP = _toPaise($sal['professional_tax']);             // flat, NO retention
$otherDeductionsP = _toPaise($sal['other_deductions']);             // flat, NO retention
```

**`pfApplicable` / `esiApplicable` follow the real bases:** only `BASIC` is `pfApplicable` (PF's base is basic); every earning is `esiApplicable` (ESI's base is gross).

**`taxable = true` on all earnings** describes *this engine* — TDS is currently a flat percent of gross. It is a statement about current behaviour, **not** about Indian tax law; per-component taxability arrives with W2.2.

**Seeding is idempotent:** existing codes are skipped, never overwritten, so an operator's name/order edits survive a re-run and a second click is harmless.

---

## 5. Security rules

```
match /salaryComponents/{docId} {
  allow read: if isAdmin() && isSameSchool();
  allow write: if false;
}
```

### A deliberate deviation, flagged for your call

The brief anticipated `isAdmin() && isSameSchoolWrite()`. I went **tighter — server-only writes** — and want that visible rather than buried:

`hrJobs`/`locations` are shapes a client may legitimately author. This collection is not. Every write must pass `Salary_component_service::validate()` — the percent-vs-money split, the enum set, the basis rules, and the `systemLocked` guard protecting the thirteen components that *describe live payroll*. A direct client-SDK write with an admin token would bypass all of it and could store a `PERCENT` component carrying `amountPaise`, or silently unlock a system component.

No client writes this collection — the admin panel goes through PHP on the service account, which bypasses rules — so closing the write arm **costs nothing** and removes that whole class of hole. Same precedent as `transportComplianceRules` and `mapsConfig`.

Every test the brief asked for still holds (cross-tenant DENIED, forged `schoolId` DENIED, missing `schoolId` DENIED, unauthorized DENIED); the "same-tenant authorized operation ALLOWED" case is the **read**, since the authorized write path is PHP. Say the word and I'll switch it to `isSameSchoolWrite()`.

**No existing rule was modified.** Noted but **not touched** (out of scope, and W2.1 forbids changing `salarySlips`): the adjacent `salarySlips` block still carries the unbound `allow write: if isAdmin();` arm — the same defect class fixed for `hrJobs`/`hrApplicants` in W1.3.

**Not deployed.** Rules deploy in an authorized window only.

---

## 6. Index decision

> **No new index required.**

The only query is `schoolWhere('salaryComponents', [])` — a single-field `schoolId` equality, which Firestore's automatic single-field indexes cover. Ordering is done in PHP (`usort` on `displayOrder`, then `code`) over a catalogue of tens of rows, so no composite index is implied. No speculative index was added.

---

## 7. RBAC decision

Existing graded `HR` module only:

- reads → `require_permission('HR', 'compensation_view', 'view')`
- writes → `require_permission('HR', 'compensation_manage', 'manage')`

This is the boundary `Hr::save_salary_structure()` already applies to compensation data. **No new RBAC module, no role-name checks.** Separating Payroll from HR is gap 92 / G19, scheduled for **W5** and cross-system (PHP resolver + `staffCapabilities` Cloud Function + Firestore rules); introducing a partial split now would leave those three copies disagreeing. **W2.1 does not close gap 92.**

---

## 8. Entitlement decision

Rides the existing `hrms_core` / `hrms_payroll` capabilities through `MY_Controller`. **No new entitlement key** (`hrms_compensation` etc.) was created, and **no tenant entitlement data was modified.**

---

## 9. Tenant-isolation verification

Every Firestore access goes through a tenant-scoped helper — `getEntity` / `setEntity` / `updateEntity` (which apply `docId()` = `{schoolId}_{code}`) and `schoolWhere()` (which injects the `schoolId` predicate). No bare `query()`/`get()`. No hardcoded tenant id (grep-verified).

Rules tests: cross-school read DENIED · cross-school write DENIED · cross-school delete DENIED · each tenant reads only its own catalogue (asserted both ways).

---

## 10. Validation tests — 26 tests / 177 assertions, all passing

Behavioural: `validate()` and `systemComponents()` are pure, so these execute the real production code.

| Requirement (brief §Testing) | Covered |
|---|---|
| 1–4 create / read / update / deactivate | ✅ service methods + endpoint routing |
| 5 `systemLocked` protection | ✅ cannot be set via API; structural edits refused naming the field; cannot be deactivated |
| 6 tenant isolation | ✅ rules tests |
| 7 forged `schoolId` | ✅ rules test |
| 8 missing `schoolId` | ✅ rules test |
| 9 unauthorized access | ✅ staff / parent / anon denied |
| 10 duplicate code | ✅ explicit check + doc-id uniqueness |
| 11 kind validation | ✅ |
| 12 calcType validation | ✅ incl. FORMULA refused until W2.2 |
| 13 basis validation | ✅ incl. COMPONENT_SET membership + self-reference rejection |
| 14 **percentage-vs-money semantics** | ✅ PERCENT needs rate / refuses `amountPaise`; FIXED needs `amountPaise` / refuses rate; PERCENT needs a basis |
| 15 `amountPaise` validation | ✅ integer paise only — rejects negatives **and decimals** (rupees) |
| 16 `displayOrder` validation | ✅ non-negative integer; seed orders unique and kind-grouped |
| 17 statutory enum | ✅ all five values + null |
| 18 **no payroll behaviour change** | ✅ test asserts `Hr.php` contains neither `salaryComponents` nor `Salary_component_service` |

Plus seed-fidelity tests: exactly the 13 legacy codes · every seed entry passes the same validator the API uses (so the seed can never drift into a shape the service would reject) · percentage bases match the calculator · flat deductions are money **and not prorated** · earnings prorate · PF/ESI applicability matches the real bases · statutory tagging · kind partition (6/5/2) · zero amounts and rates.

---

## 11. Regression results

| Suite | Before W2.1 | After W2.1 | New failures |
|---|---|---|---|
| PHP unit suite | 192 tests / 549 assertions / **10 failures** | **218 / 726 / 10 failures** | **0** |
| Firestore rules | 352 / 15 suites | **370 / 16 suites, all passing** | **0** |
| `hrms_p0_verify` (money · attendance M/W · entitlement · isolation) | 125 / 0 / 1 SKIP | **125 / 0 / 1 SKIP** | 0 |
| `php -l` | clean | clean | — |

The 10 PHP failures are unchanged and pre-existing (1 `BackupTest` format drift, 4 `Homework*` source regressions, 5 cross-repo Kotlin tests). None hidden, none opportunistically "fixed".

**Invariants confirmed still green** by `hrms_p0_verify`: `gross == net + deductions` · `Dr == Cr` · Attendance `M` half-day · Attendance `W` worked-on-off · Leave/LOP double-count protection · employment-window proration.

**One environment note:** the first full rules run showed 98 failures confined to `h_lifecycle.test.js` — the documented flake (its `beforeAll` exceeding Jest's 5 s default hook timeout). A re-run gave 370/370. Not caused by this gate; recorded rather than hidden.

---

## 12. Confirmation — ZERO payroll behaviour change

- `Hr.php` diff is `465+/145-` = **610 changed lines, identical to the W1.3 figure** — untouched by W2.1.
- `grep salaryComponents|Salary_component_service application/controllers/Hr.php` → **0 hits**, enforced by a permanent test.
- `_compute_payslip()`, `generate_payroll()`, `save_salary_structure()`, `_validate_structure()` — all unmodified.
- No flag, no reader, no shadow compare.

## 13. Confirmation — legacy `salarySlips` untouched

No code in W2.1 reads or writes `salarySlips`. The single textual mention in the service is a docblock line explaining that it is deliberately left alone.

## 14. Confirmation — no migration occurred

No data migration, no legacy salary rewrite, no assignment migration, no template migration, no cutover, no flag flip. Seeding writes only to the **new** collection, is idempotent, and is operator-triggered — it did **not** run automatically, and no tenant has been seeded yet.

## 15. Confirmation — no RTDB introduced

Zero `firebase->`, `Schools/`, `getReference` occurrences in either new file. Firestore-only.

---

## 16. Known limitations

1. **`FORMULA` is modelled but refused** — no evaluator until W2.2. Deliberate: better than storing uncomputable data.
2. **No admin UI.** Endpoints only, per the brief's "keep it minimal"; the salary-structure UI is W2.5. The catalogue is currently reachable by API, and seeding is an authenticated POST.
3. **Seed not executed on any tenant.** It is operator-triggered by design; W2.2/W2.3 will need it run first.
4. **`taxable`/`pfApplicable`/`esiApplicable` describe today's engine**, not tax law — TDS is a flat percent of gross today. Real per-component taxability is W2.2.
5. **`prorated` for PERCENT components is inherited from the basis** (the rate applies to an already-prorated base) rather than applied to the component's own value. Flagged so W2.4 doesn't double-apply proration.
6. **Gap 92 not closed** — compensation still rides `HR` RBAC. W5.
7. **Adjacent `salarySlips` rule still has the unbound `isAdmin()` write arm** — documented in §5, out of W2.1 scope.

---

## 17. Next gate: W2.2 — statutory engine

`statutoryConfig` (effective-dated, tenant-level: PF ceiling, ESI threshold, PT slabs, TDS mode) plus its resolver. Still no payroll path change — W2.2 builds and tests the resolver in isolation.

**Open question carried forward from the plan (§9.1):** seeding a tenant-level statutory config requires choosing canonical rates, but today's percentages vary per employee. W2.2 will first *report* the actual distribution per tenant and ask before writing anything.

---

## Commit readiness

**Nothing committed, pushed or merged.**

| Item | Status |
|---|---|
| Files changed | 4 new, 2 modified (§1) |
| Tests | 26 unit (177 assertions) + 18 rules — all passing |
| Security | 18 rules assertions; tenant isolation, forged/missing `schoolId`, unauthorized access all DENIED |
| Integration | Zero payroll impact, proven by test and by diff |
| Regression | Unit 218/10 (baseline) · rules 370/370 · verifier 125/0/1 |
| Firestore | 1 new rules block — **written, NOT deployed** |
| Production impact | **None** until the rules deploy; the collection is unused by any running code |

Awaiting review before W2.2.
