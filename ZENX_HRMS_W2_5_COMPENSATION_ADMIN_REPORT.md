# ZENX HRMS — W2.5 COMPENSATION ADMINISTRATION — VERIFICATION REPORT

**Date:** 2026-08-09
**Gate:** W2.5 — grade master, band validation, payout readiness, CTC, and the compensation admin UI
**Result:** **IMPLEMENTED AND VERIFIED — ZERO PAYROLL BEHAVIOUR CHANGE**
**Status:** UNCOMMITTED, UNPUSHED, flags **OFF**, rules **NOT DEPLOYED**, no tenant data written
**Next gate:** W3 — payroll run experience

---

## 1. What was implemented

W2.5 is the first wave with an **administrator-facing surface**. The compensation layer stops being API-only.

**Admin UI** — `compensation/index.php`, four tabs following the existing `views/hr/index.php` conventions exactly (content-wrapper → section.content → wrap, icon header + breadcrumb, anchor tabs routing to one controller method, panels, inline `<style>`, vanilla JS + `fetch`). **No new frontend framework.**

| Tab | What an administrator does there |
|---|---|
| **Components** | See the catalogue; seed the 13 system components; create/edit tenant components; activate/deactivate |
| **Pay Grades** | Define named salary bands with floor and ceiling |
| **Statutory** | Configure PF / ESI / PT / TDS **per tenant**, effective-dated, with overlap detection surfaced |
| **Payroll Readiness** | See, **by name**, who can and cannot be paid for a period |

**Backend:** `Salary_grade_service` (grade master + band checking) · payout-prerequisite readiness (cap 41) · `deriveCtc()` · `hasBankDetails()`.

---

## 2. Existing ZenX functionality reused

| Reused | How |
|---|---|
| `views/hr/index.php` page conventions | Copied structurally — tabs, panels, header, breadcrumb, inline style |
| `include/header` / `include/footer` | Standard page composition |
| Graded RBAC (`require_permission`) | `HR:view` to read, `HR:manage` to write; **no new module** |
| `Firestore_service` tenant helpers | `schoolWhere()` / `getEntity()` / `setEntity()` throughout |
| `Statutory_config_service::applyRatio()` | CTC employer contributions — the one money policy |
| `Salary_component_service` catalogue | Band and CTC logic read component metadata rather than re-deriving it |
| W2.3 `readiness()` | Extended in place, not replaced |
| `safe_path_segment()` | Grade codes reaching `docId()` |
| CI CSRF | The UI reads the token from the page and refreshes it from every response |

## 3. Existing functionality modified

| File | Change | Why |
|---|---|---|
| `Salary_assignment_service.php` | `readiness()` gained payout-prerequisite checks, band warnings and a `warnings` bucket; added `hasBankDetails()` and `deriveCtc()` | Cap 41 and CTC both belong to the assignment, not a new service |
| `Compensation.php` | `index()` page + tab handling; grade endpoints; readiness options | One controller for the compensation layer |
| `routes.php` | +8 routes (page ×5, grades ×3) | — |
| `firestore.rules` | +1 `salaryGrades` block (**not deployed**) | — |

## 4. New functionality created

`Salary_grade_service` · `views/compensation/index.php` · `SalaryGradeAndReadinessTest` (23 tests) · `salary_grades_sec.test.js` (17 tests).

---

## 5. Three product decisions worth stating

**A band breach WARNS; it does not block.** Real organisations make deliberate exceptions — a retention offer, a hard-to-fill role. A system that refuses them gets worked around by putting people on the wrong grade, which is worse than a recorded exception. So the assignment saves, the breach is surfaced by name, and payroll still runs.

**Ungraded staff produce no warning at all.** Ungraded staff are legitimate; warning on them would be noise, and noise trains operators to ignore the panel that is supposed to protect them.

**Payout checks default OFF — and that is a correctness decision, not caution.** Bank / PAN / PF / ESI live in the server-only **PII mirror**, not on the staff document a caller typically passes in (W2.2 discovery found 0 of each on the main docs across every tenant). Defaulting them ON would report **every employee in every tenant** as missing a bank account and block all payroll — a false positive at scale, and the worst possible failure mode for a check whose only value is being trusted. The caller opts in once it supplies PII-merged rows; the UI exposes it as a checkbox.

> **This was caught by tests, not by inspection.** Turning the checks on by default broke two W2.3 tests. That was the code being wrong, not the tests — so the default flipped rather than the assertions.

`hasBankDetails()` is deliberately tolerant of four field shapes the codebase has used over time (`bankDetails.accountNumber`, `bankDetails.account_number`, `bankAccountNumber`, `account_number`), because a false *"missing bank account"* blocks a person who is genuinely payable — the most annoying possible false positive.

---

## 6. Statutory configuration capabilities delivered

The full model is now administrator-configurable, per tenant, effective-dated:

| Capability | In the UI |
|---|---|
| PF employee / employer rate | ✅ |
| **PF wage ceiling** | ✅ with an explicit *"cap contributions at the ceiling"* switch |
| **PF eligibility above ceiling** | ✅ separate switch, with help text spelling out that unchecking it means **excluded entirely**, not capped |
| ESI employee / employer rate | ✅ |
| **ESI wage threshold** | ✅ help text states coverage is on the **full** wage at or below it, and none above |
| ESI applicability | ✅ |
| **PT state** | ✅ free text, **never inferred** |
| **PT slabs** | ✅ `from,to,amount` lines; blank `to` = open-ended top band |
| TDS mode + rate | ✅ `FLAT_PERCENT` only, with help text saying a slab/regime engine is a later phase rather than approximating one |
| Effective dating | ✅ from/to, with overlaps surfaced as an error banner |

**Nothing is inferred.** The page states this explicitly: *"ZenX will never guess them from your school's name, city or address."* The W2.2 discovery found state present on only 4 of 8 tenants and inconsistently cased — that is exactly why it is operator-entered.

**Still your decision (W2.2 §17):** no tenant has been configured. The UI now exists to do it; the values remain yours to set.

---

## 7. Tenant isolation verified

All access via `schoolWhere()` / `getEntity()` / `setEntity()` (which apply `docId()` = `{schoolId}_{code}`). No bare `query()`. No hardcoded tenant (test-asserted by regex over the service source).

`salaryGrades` rules tests: cross-school read DENIED · cross-school write DENIED · cross-school delete DENIED · each tenant reads only its own bands (asserted both ways) · forged `schoolId` DENIED · missing `schoolId` DENIED.

## 8. RBAC integration verified

- Page: `require_permission('HR', 'compensation_view', 'view')`.
- Every mutating endpoint: `HR:manage`.
- The view renders forms only when `can_manage` is true — and the endpoints enforce it **again**, so hiding a form is a courtesy, never the control.
- A view-only operator sees an explicit banner instead of silently disabled controls.
- **No new RBAC module**; gap 92 (Payroll/HR split) remains W5.

The UI checks `r.ok` **and** `{status:'error'}` on every response — CI returns a blank 403 when CSRF fails, and `fetch()` does not reject on it, so a denied action would otherwise render as success.

## 9. Payroll integration status

**None, deliberately — and verified:**

```
grep -c "salaryComponents|salaryGrades|staffSalaryAssignments|statutoryConfig|Compensation_reader"
  Hr.php       0
  Payroll.php  0
```

Flags remain **false**. The reader is still unwired. `_compute_payslip()` and `generate_payroll()` are untouched. Payroll behaves exactly as it did before W2.1.

---

## 10. Remaining capabilities and their waves

**Nothing dropped.** Every item carries the wave that owns it.

| Remaining | Wave |
|---|---|
| Salary assignment editor UI (endpoints exist; the form is not built) | **W2.6** |
| Template / structure workflow (`templateId` stored, no template master) | **W2.6** |
| Reader wired into `Hr.php`; component seed run; migration `--commit`; flag cutover | **W2.6** (sequence in W2.4 §9) |
| `FORMULA` evaluator | W2.6+ |
| `DAILY` / `HOURLY` pay bases | W4 / W6 |
| Ceiling-aware CTC and ceiling-aware payslip | the gate that makes the calculator ceiling-aware |
| Tenant-defined components reaching the payslip | post-parity gate |
| Payroll run experience · snapshot · LOP confirm · trace · grid · async payslips | **W3** |
| Shifts · roster · overtime | **W4** |
| ESS · own-salary access · payslips · approvals · lifecycle · payroll RBAC split | **W5** |
| Corporate / Retail packs | W6 |
| Tenant `state` field on school config (PT prerequisite) | W2.6 — currently free-text on the policy |

**Scope note, stated rather than buried:** the plan listed the assignment editor UI under W2.5. It is not built. This wave delivered the three *configuration* surfaces (components, grades, statutory) plus readiness, because those are what an administrator must set up **before** assignments mean anything. The assignment editor moves to W2.6 alongside the cutover sequence it belongs with — deferred, not dropped.

---

## 11. Regression

| Suite | Before W2.5 | After W2.5 | New failures |
|---|---|---|---|
| PHP unit | 304 / 991 / **10** | **327 / 1066 / 10** | **0** |
| Firestore rules | 408 / 18 suites | **425 / 19 suites** | **0** |
| `hrms_p0_verify` (W1) | PASS | **PASS** | 0 |
| `hrms_w13_verify` | PASS | **PASS** | 0 |
| `hrms_closure_verify` | PASS | **PASS** | 0 |
| `hrms_w2_verify` | PASS | **PASS** | 0 |
| `hrms_w2_parity` | PASS | **PASS** | 0 |
| Page routing | — | 307 → `admin_login` on all four tabs (**fails closed**, no 500) | — |

10 PHP failures unchanged and pre-existing (1 `BackupTest` drift, 4 `Homework*`, 5 cross-repo Kotlin). W1 invariants green: `gross == net + deductions` · `Dr == Cr` · `M` half-day · `W` worked-on-off · LOP double-count · employment-window proration.

**W2.1–W2.4 all re-verified after this wave's edits** — no gate regressed.

---

## 12. Confirmation — nothing dropped from the 95-capability matrix

**Confirmed.** No capability was removed, reinterpreted or reduced. §10 assigns every outstanding item to a wave. The three configuration areas that were "missing data" in earlier waves now have **administrator-facing configuration surfaces** — which is what the guardrail asked for: *missing data is a setup requirement, not an absent feature.*

**And built on existing ZenX, not beside it:** one payroll engine (`_compute_payslip()`, unmodified) · one money policy (`applyRatio`, runtime-proven identical) · one effective-dating implementation · one employee master · one attendance system · one leave system · one accounting path · one RBAC · no new entitlement key · existing Admin Panel, no new frontend framework.

---

## Files changed

**New (4):** `application/libraries/Salary_grade_service.php` · `application/views/compensation/index.php` · `tests/Unit/SalaryGradeAndReadinessTest.php` · `firebase-rules/tests/salary_grades_sec.test.js`

**Modified (4):** `Salary_assignment_service.php` (readiness + CTC + bank detection) · `Compensation.php` (page, tabs, grade endpoints, readiness options) · `routes.php` (+8) · `firestore.rules` (+1 block, **not deployed**)

**Not changed:** `Hr.php` · `Payroll.php` · `Staff.php` · `Attendance.php` · `salarySlips` · any existing rule · any existing view.

**Nothing committed, pushed, merged or deployed. No tenant data written. No flag flipped.**
