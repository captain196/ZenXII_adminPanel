# ZENX HRMS — CAPABILITY BACKLOG (STANDING REGISTER)

**Created:** 2026-08-09 (W3.2.3, per the W3.2.2 direction lock §2–§3)
**Purpose:** every capability required by the Asanify benchmark and the locked 95-capability matrix
stays here until it is **implemented and verified**, **explicitly superseded**, **proven impossible
by a documented platform constraint**, or **explicitly removed by the operator**.

> `MISSING` · `REFUSED` · `DEFERRED` · `BLOCKED` · `NEEDS ENHANCEMENT` · `OPERATOR DECISION`
> **never mean "dropped from the product."**

**Status vocabulary:** COMPLETE · PARTIAL · NEEDS ENHANCEMENT · NEEDS REFACTORING · MISSING · BLOCKED

---

## Priority backlog item — created by W3.2.2, retained per direction-lock §2

### NON-TAXABLE PAYROLL EARNING / REIMBURSEMENT ENGINE — **MISSING**

**Why it is not built:** the certified engine has no non-taxable earning class. Every earning in
`_compute_payslip()` joins gross and is therefore exposed to ESI and TDS. Booking a reimbursement as
a bonus would tax an expense repayment — a money error.

**This is a statement about the engine, not about the product.** Reimbursement remains a required
commercial HRMS capability.

Required scope when its gate arrives: reimbursement type · taxable/non-taxable classification ·
eligibility · statutory treatment · payroll-period application · employee-level audit ·
supporting reason/reference · accounting treatment · payslip visibility · reporting · approval where
required · tenant configuration · effective dating where applicable.

**Implementation rule:** extend the canonical engine. **Do not** create a parallel reimbursement
calculation, and **do not** approximate it with `BONUS`.

**Architectural direction (to be validated against the W2 component model first, not assumed):**
component metadata should carry `affectsGross · affectsTaxableGross · affectsPFBase · affectsESIBase
· affectsPTBase · affectsTDSBase · affectsNet · affectsCTC · accountingTreatment`, so statutory
treatment is *derived from* canonical metadata rather than from scattered special cases. W2.1 already
ships `taxable`, `pfApplicable`, `esiApplicable`, `prorated` — **reuse and extend those; do not mint
a parallel field set.**

---

> ## ⚠ SUPERSEDED — 2026-08-09
>
> **`ZENX_HRMS_MASTER_CAPABILITY_REGISTER.md` is now the single source of truth** for HRMS
> completion (163 canonical capabilities). This file is retained as the historical A–Z
> direction-lock record; every item below is mapped into the master register, and no capability
> was lost in the merge. **Update the master register, not this file.**
>
> **Correction:** the "115 rows" claim in item AA below was **wrong** — the matrix has **95**
> capabilities; the count had included the 20-row Top-Gaps table. See master register §0.

## AA. MASTER-MATRIX RECONCILIATION — **COMPLETE** (2026-08-09) — see master register

`ZENX_HRMS_ASANIFY_FUNCTIONAL_COVERAGE_MATRIX.md` is the broader benchmark and contains
**115 numbered capability rows** — not 95. The A–Z register below is the W3/W5-oriented
direction-lock subset, **not** the whole product scope.

**The two registers currently disagree, and that gap is itself untracked work.** The matrix's
statuses are as-of-authoring and are now **stale for everything W1–W3.2.4 delivered** — e.g. row 34
"Salary component master" still reads **MISSING**, but W2.1 built it.

**Status tally as literally written in the matrix today** (94 of 115 rows parsed with a
strict status cell; the remaining ~21 use a different format and were not counted):

| MISSING | COMPLETE | PARTIAL | REFACTOR | ENHANCE |
|---|---|---|---|---|
| 39 | 25 | 22 | 4 | 4 |

**Do not read those numbers as current truth.** A row-by-row re-audit of all 115 against the
delivered W1–W3.2.4 state has **not** been performed.

**Acceptance criteria:** every one of the 115 rows carries a status verified against the current
repository, cross-linked to its A–Z backlog item where one exists, with any capability present in
the matrix but absent from this register **added here**.

**Owner:** first task of the next wave, before new implementation (master directive §20).

---

## Direction-lock §3 register — A to P

| # | Capability | Status | Where it stands | Gate |
|---|---|---|---|---|
| A | LOP review / confirmation | **COMPLETE** (W3.2.3) | Run-level review from frozen evidence + sign-off + finalization gate | ✔ |
| B | Full payroll calculation trace | **PARTIAL** — *implementation done, UAT open* | W3.2.4 built it: engine now states its bases; `_calculation_trace` frozen on **both** slip writers; `payroll_build_trace()` assembles the ordered chain from frozen evidence only; `hr/payslip_trace` endpoint; drill-down UI; 28 tests incl. exact-paise trace-vs-payslip reconciliation. **Stays PARTIAL** — browser unverified, and the statutory step cannot be complete until D | **UAT** + **W3.2.6** |
| C | Async / scalable payroll generation | **MISSING** | Not assessed. Synchronous today; scale behaviour at 500/1k/5k unmeasured | **W3.2.5** |
| D | Statutory snapshot | **BLOCKED** upstream | Cannot snapshot a policy that does not exist; W2 statutory config unset. Architecture must be ready regardless | **W3.2.6** |
| E | Non-taxable earning / reimbursement | **MISSING** | See the priority item above | engine gate |
| F | Separable payroll adjustment authorization | **NEEDS ENHANCEMENT** | `HR:manage` gates both adjusting and finalizing — no maker/checker separation | **W5** (gap 92) |
| G | Payroll adjustment approval workflow | **MISSING** | Deliberately not duplicated; run lifecycle used instead. A distinct adjustment approval is a real benchmark item | **W5** |
| H | Bulk payroll adjustment / import | **MISSING** | CSV/Excel bonus·incentive·deduction upload, validation preview, duplicate detection, employee matching, error report, import audit, rollback-before-apply | **W5** |
| I | Configurable payroll expense accounting mapping | **OPERATOR DECISION** | Reuses `5010`/`5020`; no codes invented. Configurable per-type mapping is a Chart-of-Accounts decision | operator → later gate |
| J | Full browser UAT | **MISSING** | Nothing browser-verified across W3.1–W3.2.3 | **Test-School UAT** |
| K | Full Test-School integration testing | **MISSING** | `tests/Integration` does not exist in this repo's PHPUnit config | **Test-School UAT** |
| L | Payroll reports | **PARTIAL** | `export_payroll_report` exists; commercial report set unassessed | later gate |
| M | Payslip generation / professional document output | **PARTIAL** | Slips retrievable + printable; no professional PDF/document pipeline | later gate |
| N | Payroll correction workflow | **PARTIAL** | `regenerate_staff_payroll` + audited `unlock_payroll` + delta journal exist; not assessed against the benchmark end-to-end | later gate |
| O | Payroll arrears | **PARTIAL** | `ARREARS` exists as an override type; retro salary-difference *computation* does not | later gate |
| P | Payroll revision history | **PARTIAL** | `slip_archive` per regeneration + `regen_count` + `previousNetPay` exist; no unified history surface | later gate |

## Direction-lock §3 register — Q to Z

| # | Capability | Status | Where it stands | Gate |
|---|---|---|---|---|
| Q | Employee self-service payroll/payslip surface | **PARTIAL** | Parent/Teacher apps read own payslips; no ESS surface | **W5** |
| R | Employee compensation visibility per RBAC | **PARTIAL** | Firestore rule scopes payslip reads to `staffId == uid`; graded self-access not modelled | **W5** |
| S | Statutory reports / compliance outputs | **MISSING** | No PF/ESI/PT/TDS return outputs. Downstream of statutory config | later gate |
| T | Payroll registers / bank payment output | **MISSING** | No bank advice / payment file | later gate |
| U | Payroll reconciliation | **PARTIAL** | `payroll_health` + accounting reconciler exist; no payroll-vs-ledger reconciliation report | later gate |
| V | Full audit history | **PARTIAL** | Rich per-action audit (overrides, LOP sign-off, regeneration, unlock, journals); no single audit timeline | later gate |
| W | Payroll-period locking / reopening controls | **COMPLETE** | `lock_payroll_month` · `unlock_payroll` (records `unlocked_from_status`) · Draft-only mutation | ✔ |
| X | Payroll approval workflow | **PARTIAL** | `approve_payroll` exists; maker/checker separation does not — see F | **W5** |
| Y | Payroll finalization / paid lifecycle | **COMPLETE** | Draft → Finalized → Paid, with the W3.2.3 LOP gate | ✔ |
| Z | Accounting reconciliation | **COMPLETE** | Transactional outbox + reconciler + `Dr == Cr` engine validation + idempotent delta journals | ✔ |

## Extensibility watch-list (direction-lock §5)

Not to be built now; the data model must not make them impossible. The current override model
(`type` → engine `kind`, plus `taxable`/`prorated` metadata) accommodates all of these by **adding
type rows and engine capability**, not by reshaping storage:

reimbursement · taxable/non-taxable earnings · statutory-affecting adjustments · retroactive salary
difference · one-time allowance · joining bonus · retention bonus · performance incentive ·
settlement adjustments · manual statutory correction where legally appropriate.

**Known model limitation, recorded honestly:** every adjustment currently maps to an engine `kind`
of `earning`/`deduction`/`employer_contribution`. A type needing different *statutory* behaviour
(reimbursement) cannot be expressed by adding a row — it needs the engine capability in the priority
item above. The storage shape does not block it; the engine does.

## Future RBAC capability model (direction-lock §14)

Not to be created now. Payroll endpoints must stay shaped so W5 can introduce these **without
rewriting them**: `payroll.view · payroll.manage · payroll.adjust · payroll.approve ·
payroll.finalize · payroll.pay · payroll.unlock · payroll.export`.

Current state: every payroll endpoint calls graded `require_permission`/`_require_role(…,'HR',level)`
with an explicit action name already threaded through — so the module/level pair is the only thing
W5 needs to change. No role-name gates remain in the W3 surfaces.
