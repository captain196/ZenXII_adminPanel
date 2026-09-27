# ZENX HRMS — W2 COMPENSATION CORE — IMPLEMENTATION PLAN

**Date:** 2026-08-09
**Scope (settled by the matrix, §I):** **G2 component master · G14 pay bases · G9 statutory engine**
**Prerequisite:** G1 money precision — **DONE (W1.2)**
**Status:** PLAN — no code written yet. Awaiting review before W2.1.

---

## 1. What exists today (inspection result, not a re-audit)

**Storage.** One flat record per employee: `salarySlips/{schoolId}_SAL_{staffId}` with `type == 'salary_structure'` — the same collection as actual payslips.

**Shape — 13 hardcoded numeric fields**, whitelisted in `Hr::_validate_structure()` and consumed verbatim by `Hr::_compute_payslip()`:

| Group | Fields |
|---|---|
| Earnings (6) | `basic`, `hra`, `da`, `ta`, `medical`, `other_allowances` |
| Employee deductions (5) | `pf_employee`, `esi_employee`, `professional_tax`, `tds`, `other_deductions` |
| Employer contributions (2) | `pf_employer`, `esi_employer` |

**Five properties that matter for W2:**

1. **Components are columns, not rows.** Adding "Conveyance" or "Night allowance" requires a code change in at least four places (`save_salary_structure`, `_validate_structure`, `_compute_payslip`, the view).
2. **Mixed semantics, undeclared.** `pf_employee`/`esi_employee`/`tds` are **percentages**; `professional_tax`/`other_deductions` are **amounts**. Nothing in the record says which — the calculator knows by position. This is the single most dangerous property to carry forward.
3. **Calculation bases are hardcoded.** PF on `basic`, ESI on gross, TDS on gross — inside `_compute_payslip()`.
4. **No effective dating.** `_version` + a 4-field `_prev` snapshot is an audit breadcrumb, not history. A mid-year revision overwrites; a past run cannot be reconstructed from the record.
5. **No statutory master.** No PF ceiling, no ESI eligibility threshold, no PT slabs. The code says so in its own comment: *"No statutory ceilings, eligibility thresholds, slabs, tax engines… out of scope."*

**Already present and reusable — do not rebuild:**

| Asset | Status |
|---|---|
| `_compute_payslip()` | W1.2 integer-paise engine, exact arithmetic, one rounding per ratio. **The calculator is sound** — it is the *inputs* that are impoverished. |
| Attendance contract | 9-status vocabulary; `M` half-day and `W` worked-on-off correctly priced (W1 P0-3/P0-4). `_staff_working_days()` yields payable days. |
| Leave / LOP | Existing engine; `_compute_payslip()` already takes `lwpDays` and guards Attendance∩Leave double-counting. |
| Employment-window proration | `_in_employment_window()`, exit-date handling (P0-6). |
| Accounting | `postRunAccrual` / `postRunPayment`, mandatory posting accounts, period-lock guard. |
| Staff PII | `panNumber`, `aadharNumber`, `pfNumber`, `esiNumber`, `bankDetails`, `salaryDetails` **already stored** as server-only PII keys. |
| Tenant scoping | `docId()` / `schoolWhere()`. |
| Entitlements | `hrms_core` / `hrms_payroll` (W1.1). |

**Consequence:** W2 adds **no new employee master, no second payroll engine, no second money policy**. It replaces a 13-column input record with a modelled, effective-dated, statutory-aware one — and feeds the *same* calculator.

---

## 2. Gap map — EXISTING → REQUIRED → ACTION

| Cap. | Required (benchmark) | Existing | Action |
|---|---|---|---|
| 34 | Salary **component master** | none — hardcoded fields | **CREATE** `salaryComponents` |
| 33 | Salary structure / CTC templates | per-employee flat record | **CREATE** templates + **MODIFY** assignment |
| 36 | Multiple **pay bases** | monthly-fixed only | **CREATE** `payBasis` on assignment; wire MONTHLY only (§6) |
| 57 | PF/ESI/PT/TDS ceilings, slabs, eligibility | flat operator-typed % | **CREATE** `statutoryConfig`, effective-dated |
| 51 | Effective-dated revisions | `_version`/`_prev` | **CREATE** effective-dated assignments |
| 45 | Calculation trace | breakdown exists, trace doesn't | **PARTIAL** — emit trace rows (consumed in W3) |
| 41 | Pre-run exception report naming people | aggregate counts | **MODIFY** `preflight_payroll` to name people + check bank/PAN/statutory |
| 92 | Payroll RBAC separable from HR | rides on `HR` | **NOT W2** — G19/W5, cross-system (PHP + CF + rules) |
| 49 | Arrears / retro | none | **NOT W2** — needs effective dating first (this wave provides it) |

---

## 3. Data model (additive; nothing dropped)

```
salaryComponents/{schoolId}_{componentId}          ← NEW: the catalogue
  code, name, kind: earning|deduction|employer_contribution,
  calcType: FIXED|PERCENT|FORMULA,
  basis: BASIC|GROSS|CTC|COMPONENT_SET|NONE,
  basisComponents[], rate|amountPaise,
  taxable, pfApplicable, esiApplicable, prorated,
  statutory: null|PF|ESI|PT|TDS,
  displayOrder, active, systemLocked

salaryStructureTemplates/{schoolId}_{templateId}   ← NEW: reusable CTC shapes
  name, description, lines[{componentId, calcType, rate|amountPaise, basis}],
  active

staffSalaryAssignments/{schoolId}_{staffId}_{effectiveFrom}   ← NEW: effective-dated
  staffId, effectiveFrom (YYYY-MM-DD), effectiveTo|null,
  templateId|null, payBasis: MONTHLY_FIXED|DAILY|HOURLY,
  lines[{componentId, calcType, rate|amountPaise, basis}],
  ctcPaise, status: draft|active|superseded,
  supersedesId, reason, createdBy/At, approvedBy/At

statutoryConfig/{schoolId}_{effectiveFrom}         ← NEW: effective-dated, tenant-level
  pf {employeeRate, employerRate, wageCeilingPaise, applyCeiling, eligibleAboveCeiling}
  esi {employeeRate, employerRate, wageThresholdPaise, applicable}
  pt  {slabs[{fromPaise, toPaise, amountPaise}], state}
  tds {mode: FLAT_PERCENT|SLAB, ...}
  effectiveFrom, effectiveTo|null, createdBy/At
```

**Money:** every stored amount is **integer paise** (`*Paise` suffix), per the W1.2 contract. Rates stay decimal percentages (a rate is not money) and are applied through the existing `_applyRatio()` — one controlled rounding, at the calculator, never at storage.

**Legacy:** `salarySlips` type=salary_structure is **retained and untouched**. It remains the reader source until parity is proven (§5).

**Rules + indexes:** four new collections → four new `firestore.rules` blocks (server-only writes; `isAdmin() && isSameSchoolWrite()` idiom) + rules tests, and a check for whether any new composite index is required (single-field `schoolId` equality needs none; `staffId + effectiveFrom` ordering will). Per the standing rule, rules/indexes are evaluated **for every feature** and deployed only in an authorized window.

---

## 4. The one architectural decision worth stating

**How to introduce all this without breaking a single existing payroll run.**

Reuse the pattern this codebase has already proven on Student Entitlements: **additive collections + an injected reader + a flag + shadow-compare**.

```
Hr::generate_payroll()
        │
        ▼
Compensation_reader::resolve(staffId, periodStart, periodEnd)   ← NEW library
        │
        ├── flag OFF (default): read legacy salarySlips structure   ← today's behaviour, byte-for-byte
        ├── SHADOW: read both, compute both, LOG divergence, RETURN LEGACY
        └── flag ON: resolve assignment + components + statutory
        │
        ▼
returns the SAME 13-field shape _compute_payslip() already accepts
```

Why this shape:

- `_compute_payslip()` is a **W1.2-certified money engine**. Changing its signature would put the money contract back in play. It stays untouched; only its input source changes.
- Shadow mode gives a real parity proof against live data before anything switches — the same evidence standard used for the entitlement reader.
- Flag default **false** means W2 can land, be reviewed, and sit inert. Cutover is a separate, explicit decision.
- Rollback is a flag flip, not a revert.

**Deliberate constraint:** the reader's output shape is the *legacy* 13 fields in W2. Richer component sets (arbitrary catalogue components) can only reach the payslip once `_compute_payslip()` learns to iterate components — that is a **W2.5/W3 step**, gated behind proven parity. W2 builds the model and the resolution path; it does not widen the calculator in the same wave.

---

## 5. Sequenced gates (each stops for review)

| Gate | Deliverable | Verification |
|---|---|---|
| **W2.1** | `salaryComponents` catalogue + seed of the 13 system components (`systemLocked`) mirroring today's fields exactly | Unit tests; rules block + rules tests; tenant isolation; **zero behaviour change** |
| **W2.2** | `statutoryConfig` + effective-dated resolver; seed per tenant from existing per-employee percentages | Resolver unit tests (ceiling/threshold/slab boundaries); no payroll path touched yet |
| **W2.3** | `staffSalaryAssignments` (effective-dated) + migration that reads each legacy structure into one `effectiveFrom` assignment — **read-only dry-run first, `--commit` separately** | Migration idempotency; legacy untouched; per-tenant counts |
| **W2.4** | `Compensation_reader` + `USE_COMPONENT_COMPENSATION` flag (**false**) + shadow-compare | **Parity gate**: shadow run over a real tenant, zero divergence, reported honestly (wording: "no divergence detected", never "equivalence proven") |
| **W2.5** | `salaryStructureTemplates` + assignment UI + `preflight_payroll` naming people (cap 41) incl. bank/PAN/statutory checks | RBAC (`HR:manage` write / `HR:view` read); no PII leak to non-privileged roles |
| **W2.6** | Calculation-trace rows emitted by the resolver (cap 45 data half) | Trace matches computed payslip exactly |

**Cutover (flag flip) is NOT in W2.** It is a separate authorized step after the parity gate — same discipline as the entitlement reader.

---

## 6. Pay bases (G14) — modelled, not wired

`payBasis` is stored on the assignment with `MONTHLY_FIXED` as the default and only wired basis in W2.

`DAILY` / `HOURLY` are modelled but **not** connected to `_compute_payslip()`, because doing so means changing the proration formula — the exact arithmetic W1.2 certified. Their consumers (Retail 87/88) are P2 and explicitly deferred until a tenant is signed. Modelling now avoids a schema migration later; wiring now would risk the money contract for no current consumer.

**Stated plainly so it is not mistaken for completeness:** W2 delivers the pay-basis *model*, not hourly/daily payroll.

---

## 7. Contracts W2 must not break

| Contract | How it is protected |
|---|---|
| **Money** — integer paise, one rounding per ratio, rupees only at the boundary | `_compute_payslip()` untouched; all new amounts `*Paise`; no new `round()` in business logic |
| **`gross == net + deductions`** | Unchanged — same calculator |
| **`Dr == Cr`** | Payroll→Accounting path untouched; posting accounts and period-lock guards unchanged |
| **Attendance** | Payroll adapts to Attendance, never the reverse. `M`/`W` treatment must not regress — pinned by `AttendancePayrollStatusContractTest` |
| **Leave / LOP** | Existing engine consumed as-is; no second leave source; double-count guard preserved |
| **RBAC** | Existing graded `HR` module (`view`/`edit`/`manage`). **No new module, no role-name checks.** Gap 92 (Payroll split) stays W5 |
| **Tenant isolation** | `docId()`/`schoolWhere()` on every access; rules tests per collection; no hardcoded tenant |
| **Firestore-only** | No RTDB, no fallback |
| **Entitlements** | Rides existing `hrms_core`/`hrms_payroll`; **no new entitlement keys** |
| **Multi-vertical** | Component master is vertical-neutral; no school/corporate/retail branching in the engine |

---

## 8. Explicitly NOT in W2

Arrears/retro (49) · off-cycle & F&F (50/70) · payroll RBAC split (92/G19 → W5) · payroll snapshot, LOP confirm, editable grid, async payslips (W3) · shift/roster/overtime (W4) · ESS (W5) · Retail/Corporate packs (W6) · statutory filings/registers (60) · widening `_compute_payslip()` to arbitrary components (post-parity) · the flag cutover itself.

---

## 9. Open questions for you (do not block W2.1)

1. **Statutory seed source.** Existing per-employee percentages vary by employee. Seeding a tenant-level `statutoryConfig` requires choosing a canonical set (most common value? operator-entered?). W2.2 will surface the actual distribution per tenant and ask before writing.
2. **PT slabs are state-specific.** No state field exists on the tenant today. W2.2 will model `pt.state` but leave it operator-set rather than inferring it.
3. **`salaryDetails` vs salary structure drift.** `_auto_create_from_profile()` builds a structure from `salaryDetails.basicSalary`/`Allowances` — a second, simpler shape. W2.3 will report how many staff currently sit on each path before converging them.

---

## 10. First action on approval

**W2.1** — `salaryComponents` catalogue + system-component seed, behind no flag (pure addition, zero read path), with rules block, rules tests, unit tests and tenant-isolation verification. Nothing in the payroll path changes.

No commits until the gate is verified and its file/test/security/regression summary is reported.
