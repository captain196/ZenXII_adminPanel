# ZENX HRMS — W2.6 COMPENSATION ASSIGNMENT WORKFLOW — VERIFICATION REPORT

**Date:** 2026-08-09
**Gate:** W2.6 — employee salary assignment workflow, salary structures, CTC experience, statutory integration
**Result:** **IMPLEMENTED AND VERIFIED — ZERO PAYROLL BEHAVIOUR CHANGE**
**Status:** UNCOMMITTED, UNPUSHED, flags **OFF**, reader **NOT WIRED**, rules **NOT DEPLOYED**, no tenant data written
**Next gate:** W3 — payroll run experience

---

## A. What was implemented

The bridge from **employee → payroll inputs** is now walkable by an administrator.

**Two new tabs** on the compensation page (six total):

| Tab | What it does |
|---|---|
| **Structures** | Define reusable salary shapes — which components, at what rate |
| **Employee Salary** | Select an employee, see their dated salary history, build/preview/save a new or revised salary |

**Backend:** `Salary_template_service` (structure master + gross-down `apply()`) · `preview_assignment` (server-side evaluation before saving) · template endpoints.

The workflow an administrator now follows:

```
pick employee → see history (which period is in force)
   → pick a structure (or build lines manually)
   → enter monthly gross → Preview
   → see: component split · CTC + employer breakdown · band verdict · errors
   → Save (only unlocks when the preview is valid)
```

---

## B. Existing ZenX functionality reused

| Reused | How |
|---|---|
| `views/hr/index.php` conventions | Tabs, panels, header, breadcrumb, inline `<style>` — no new framework |
| `Salary_assignment_service` | `validate()`, `splitGross()`, `deriveCtc()`, `readiness()` — the preview calls the **same** code the save path uses |
| `Salary_component_service` | Templates and assignments both take `calcType`/`basis` from the catalogue |
| `Salary_grade_service` | Band verdict in the preview |
| `Statutory_config_service::applyRatio()` | All money — one policy |
| `effective_dating_helper` | Overlap detection in the preview and the save |
| Graded RBAC | `HR:view` / `HR:manage`, no new module |
| `Firestore_service` tenant helpers | Throughout |
| CI CSRF | Token read from page, refreshed from every response |

## C. Existing functionality modified

| File | Change |
|---|---|
| `Compensation.php` | +4 endpoints (templates ×3, preview ×1); `_catalogue()` helper; allowed-tab list extended to six |
| `views/compensation/index.php` | +2 tabs with full forms and JS |
| `routes.php` | +6 routes |
| `firestore.rules` | +1 `salaryStructureTemplates` block (**not deployed**) |

## D. New functionality created

`Salary_template_service` · `SalaryTemplateAndIntegrationTest` (16 tests) · `salary_templates_sec.test.js` (17 tests).

---

## E. Employee salary assignment workflow

Every item from the brief's list, and where it lives:

| Requirement | Delivered |
|---|---|
| select an employee | dropdown, populated from the live roster |
| view current assignment | history table marks the row **in force** for today |
| create an assignment | Preview → Save |
| future-effective assignment | any `effectiveFrom`; overlaps refused |
| select components / enter values | line editor; balancing lines offer no input |
| FIXED vs PERCENT distinguished | the catalogue decides; the row shows `% of BASIC` or `Fixed ₹` |
| respect component metadata | `calcType`/`basis` are **never** taken from the form |
| select pay grade + see floor/ceiling | grade dropdown; band verdict in the preview |
| see band warnings | shown, and explicitly labelled *not a block* |
| effective from / to | both, with help text |
| current / future / history | one table, oldest first, in-force highlighted |
| prevent overlapping assignments | `ed_find_overlaps()` in **both** preview and save |
| status / draft-final | `active` / `draft`; draft is not payable and readiness says so |
| validate required information | server-side `validate()`; Save disabled until valid |
| see CTC + employer impact | three tiles + per-component employer breakdown |
| see payroll readiness | Readiness tab, by name |
| actionable warnings/errors | every error is a sentence naming the field or person |

**Save only writes what the preview showed.** The button unlocks on a valid preview, and any edit re-disables it. The preview is computed **server-side by the same functions the save path uses** — a browser-side estimate that disagreed with the saved result would be worse than no preview at all.

## F. Salary structure / template workflow

**Reuse check first:** `templateId` existed on assignments since W2.3, but nothing defined a template. The other `templateId` hits in the codebase belong to **Certificates** and **Communication** — unrelated. So this was genuinely missing, and building it duplicates nothing.

A template is the **shape** of a salary, not its size. `apply()` turns template + one gross figure into an ordinary assignment payload that still goes through `validate()` and is still priced by `splitGross()`. **Deleting every template would change no payslip** — it is a convenience for building lines, never a parallel path to pay.

Two deliberate choices:
- **A generated assignment starts as `draft`**, so a structure-derived salary is reviewed before it pays — and the draft status is *enforced*: readiness reports it as a blocker (test-pinned, so the review step isn't decoration).
- **PERCENT lines keep their rate** rather than being frozen into an amount, so if the catalogue later changes a basis the assignment follows it instead of silently keeping a stale number.

## G. CTC workflow

`deriveCtc()` returns gross, employer total, CTC and a per-component breakdown; the preview renders all four. Verified: `₹50,000 gross + PF-er ₹2,400 + ESI-er ₹1,625 = ₹54,025 CTC`.

Employee deductions are correctly **excluded** — they reduce take-home, not employer cost.

**One boundary stated rather than hidden:** CTC does **not** apply statutory ceilings, because `_compute_payslip()` does not either. A ceiling-aware CTC would disagree with the payslip it summarises. Both become ceiling-aware in the same later gate.

## H. Statutory integration

Verified end-to-end in `test_the_whole_compensation_chain_holds_together`, which walks template → assignment → split → CTC → band → **statutory resolution** → readiness, each stage consuming the previous stage's real output:

- the policy in force for the period resolves via the **shared** effective-dating helper;
- PF computes on the component-declared basis (**BASIC**) and caps: basic ₹20,000 against a ₹15,000 ceiling → base ₹15,000, employee ₹1,800;
- ESI computes on **GROSS** and, at ₹50,000 against a ₹21,000 threshold, correctly returns **not covered** rather than a capped amount.

A separate test asserts statutory config and salary assignments resolve **identically** for the same date — the failure that shared helper exists to prevent.

**Not yet flowing into live payroll.** That is the reader-wiring step in the W2.4 §9 sequence, deliberately still ahead.

## I. Payroll readiness

W2.5 model kept and now exercised by the workflow. Blockers: no effective assignment · draft assignment · no components · missing bank/PAN/PF/ESI (opt-in) . Warnings: band breach · grade referencing a deleted band. Overlaps surface in the preview *and* the history view.

**Missing configuration is never zero pay** — it is a named blocker.

## J. Effective dating and history

`ed_select_for` / `ed_find_overlaps`, shared with statutory. Both bounds inclusive; open-ended supported; latest `effectiveFrom` wins deterministically; a revision **supersedes** rather than overwrites, so a closed period still resolves to the salary that applied then.

## K. RBAC integration

Page: `HR:view`. Every mutating endpoint: `HR:manage`. Forms render only when `can_manage`, **and the endpoints enforce it again** — hiding a form is a courtesy, never the control. `preview_assignment` is `HR:view` (it writes nothing). View-only operators see an explicit banner.

The UI checks `r.ok` **and** `{status:'error'}` on every response, because CI returns a blank 403 on CSRF failure and `fetch()` does not reject on it.

## L. Tenant isolation

All access via `schoolWhere()` / `getEntity()` / `setEntity()`. No bare `query()`. No hardcoded tenant (test-asserted by regex). `salaryStructureTemplates` rules tests: cross-tenant read/write/delete DENIED · each tenant reads only its own · forged and missing `schoolId` DENIED.

## M. Admin UI verification

All six tabs return **307 → `admin_login`** — routed, auth fails closed, no 500. `php -l` clean on the view. Bootstrap 3 / AdminLTE conventions, FontAwesome icons, vanilla JS + `fetch`; **no new frontend framework**.

## N. Migration / parity status

**Unchanged and deliberate.** Component catalogue unseeded; W2.3 migration still dry-run only; parity gate still passing on in-memory mappings (8/8, no divergence). No tenant data written by this wave.

## O. Compensation reader wiring status

**NOT WIRED.** `Hr.php` and `Payroll.php` contain no reference to `Compensation_reader`, `staffSalaryAssignments`, `salaryStructureTemplates`, `salaryComponents`, `salaryGrades` or `statutoryConfig` — unit-tested and runtime-checked.

## P. Cutover status

**Not started.** Both flags false. The W2.4 §9 sequence is unchanged and still gated on your statutory decision (W2.2 §17) — steps 2–4 (configure, migrate, re-run parity) are now *possible through the UI*, which is what W2.5/W2.6 existed to enable.

---

## Q. Regression

| Suite | Before W2.6 | After W2.6 | New failures |
|---|---|---|---|
| PHP unit | 327 / 1066 / **10** | **343 / 1125 / 10** | **0** |
| Firestore rules | 425 / 19 suites | **442 / 20 suites** | **0** |
| `hrms_p0_verify` (W1) | PASS | **PASS** | 0 |
| `hrms_w13_verify` | PASS | **PASS** | 0 |
| `hrms_closure_verify` | PASS | **PASS** | 0 |
| `hrms_w2_verify` | PASS | **PASS** | 0 |
| `hrms_w2_parity` | PASS | **PASS** | 0 |
| Page routing (6 tabs) | — | 307, fails closed | — |

10 PHP failures unchanged and pre-existing (1 `BackupTest` drift, 4 `Homework*`, 5 cross-repo Kotlin). W1 invariants green. **W2.1–W2.5 all re-verified — no gate regressed.**

## R. Remaining gaps

1. `FORMULA` evaluator — modelled, refused.
2. `DAILY` / `HOURLY` pay bases — modelled, refused (changing them touches W1.2 proration).
3. Ceiling-aware CTC and ceiling-aware payslip — one coupled gate.
4. Tenant-defined components reaching the payslip — needs `_compute_payslip()` to iterate components.
5. Reader wiring · seed · migration `--commit` · shadow · flag cutover — the W2.4 §9 sequence.
6. Tenant `state` field on school config — PT state is currently free text on the policy.
7. Bulk assignment (apply a structure to many employees at once).
8. Assignment approval workflow (`approvedBy`/`approvedAt` reserved, unused).
9. Arrears / retro — now *possible* because history exists.

## S. Gap → wave mapping

| Gap | Wave |
|---|---|
| Reader wiring, seed, migration, shadow, cutover (1 sequence) | **W2.7 / cutover gate** |
| `FORMULA` evaluator · bulk assignment · tenant `state` field | **W2.7** |
| Payroll run experience · snapshot · LOP confirm · per-employee warnings · trace · editable grid · async payslips | **W3** |
| Ceiling-aware calculator + CTC | **W3** (with the run experience) |
| Tenant components on the payslip | **W3** (post-cutover) |
| Shifts · roster · overtime · `DAILY`/`HOURLY` | **W4** |
| ESS · own-salary access · payslip experience · approvals (incl. assignment approval) · lifecycle · payroll RBAC split (gap 92) | **W5** |
| Arrears / retro | **W5** |
| Corporate / Retail packs | **W6** |

## T. Confirmation — nothing dropped from the 95-capability matrix

**Confirmed.** No capability was removed, reinterpreted, downgraded or moved out of scope. Every gap in §R carries a wave in §S. The statutory capabilities you listed as mandatory — PF rates, ceiling, eligibility-above-ceiling, ceiling behaviour, ESI rates, threshold, applicability, full-wage behaviour, PT state and slabs, TDS configuration, effective dating, overlap detection, tenant-specific configuration — are **all present, configurable and tested**, and none was weakened because tenant data is incomplete.

**Built on existing ZenX, not beside it:** one payroll engine (`_compute_payslip()`, unmodified) · one money policy · one effective-dating implementation · one employee master (`staff`) · one attendance system · one leave system · one accounting path · one RBAC · one salary calculation path · no new entitlement key · existing Admin Panel.

---

## Files changed

**New (3):** `application/libraries/Salary_template_service.php` · `tests/Unit/SalaryTemplateAndIntegrationTest.php` · `firebase-rules/tests/salary_templates_sec.test.js`

**Modified (4):** `Compensation.php` · `views/compensation/index.php` · `routes.php` · `firestore.rules` (**not deployed**)

**Not changed:** `Hr.php` · `Payroll.php` · `Staff.php` · `Attendance.php` · `salarySlips` · any existing rule or view.

**Nothing committed, pushed, merged or deployed. No tenant data written. No flag flipped. No migration run.**
