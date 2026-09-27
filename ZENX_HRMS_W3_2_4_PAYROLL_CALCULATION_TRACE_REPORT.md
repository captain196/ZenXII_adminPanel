# ZENX HRMS — W3.2.4 — FULL PAYROLL CALCULATION TRACE — GATE REPORT

**Date:** 2026-08-09
**Gate:** W3.2.4 — fourth of six W3.2 sub-gates
**Result:** **DELIVERED** (unit-verified) — **browser UAT open**, statutory step blocked upstream
**Status:** UNCOMMITTED · flags **OFF** · mode **LEGACY** · nothing deployed, migrated or seeded

---

## 1. Existing trace/evidence discovered — the inspection that shaped the gate

**The engine already emitted almost the entire trace.** `_compute_payslip()` returns, per employee:

`absent_fraction` · `daily_salary` · `deduction_days` · `lwp_deduction` · `extra_worked_days` ·
`extra_worked_pay` · every **prorated** earning (basic/hra/da/ta/medical/other) · `gross` ·
`pf_employee` · `pf_employer` · `esi_employee` · `esi_employer` · `tds` · `professional_tax` ·
`other_deductions` · `total_deductions` · `net_pay` · a **`deferred`** block (amounts that exceeded
earned gross and were *not* taken) · `extra_components` · `statutory_source` · `warnings` · and a
**`paise` block of exact integers**.

Combined with W3.2.1's `_attendance_snapshot`, W3.2.2's `_payroll_adjustments` and the pre-existing
`_salary_snapshot`, **the trace was an assembly problem, not a computation problem.**

Only two things were genuinely missing:

1. the engine never **stated its statutory bases** — you could see "PF 3,420" and "12%" but not the
   ₹28,500 they came from, so the deduction was unverifiable;
2. none of the engine's intermediate output was **persisted** — the slip stored rounded rupees only.

## 2. Reuse analysis

| Reused unchanged | How |
|---|---|
| `_compute_payslip()` | Still the only thing that prices a payslip. No second engine. |
| `_salary_snapshot` (W2/pre-existing) | Contracted structure step |
| `_attendance_snapshot` (W3.2.1) | Attendance step + named LOP days via `payroll_lwp_days_from_mask()` |
| `_payroll_adjustments` (W3.2.2) | Adjustments step, original → final |
| `payroll_snapshot_helper` (W3.2.1/3) | Mask decode reused, not reimplemented |
| Compensation seam · accounting · RBAC · tenant primitives · payroll lifecycle | Untouched |

**Nothing new was created that already existed.** No second calculation engine, compensation
resolver, attendance resolver, statutory calculator, accounting path or audit framework.

## 3 / 4. The calculation chain and trace data model

Two additions, both minimal:

**(a) The engine states its bases** — a new `bases` key on the return value:

```
retention_ratio · absent_fraction
pf_base_paise (= earned BASIC)   esi_base_paise (= earned GROSS)   tds_base_paise (= earned GROSS)
pf_employee_pct · esi_employee_pct · tds_pct · pf_employer_pct · esi_employer_pct
```

Additive — every existing caller reads named keys, so a new key cannot disturb them; `hrms_p0_verify`
re-run PASS confirms the money contract is untouched. The PF-on-basic vs ESI-on-gross asymmetry is
the single most misread rule in the engine, so **both bases are named rather than implied**.

**(b) `_calculation_trace` frozen on the slip** — a **verbatim copy** of what the engine returned:
`statutory_source · bases · paise · deferred · warnings · computed_at`. Nothing recomputed,
reformatted or re-rounded. Written by **both** slip writers, because `regenerate_staff_payroll()`
merges over the old slip and an un-restated trace would survive describing the previous computation —
the same staleness class W3.2.1 found in `_salary_snapshot`.

**Assembly** lives in `application/helpers/payroll_trace_helper.php` — a **pure function over one
stored slip**. It has no `$fs`, no Firestore access primitives, and no payroll arithmetic.

## 5. Snapshot dependencies

```
_salary_snapshot  +  _attendance_snapshot  +  _payroll_adjustments  +  _calculation_trace
                                   +
                          statutory snapshot  ← STILL MISSING (W3.2.6, blocked on W2 policy)
                                   =
                        complete payroll evidence
```

**Four of five are in place.** The trace declares the fifth as absent — `evidence.statutory_snapshot`
is `false` and the endpoint returns `statutory_snapshot_status: "NOT CONFIGURED — …"`. **No statutory
history was fabricated.**

## 6–13. The trace steps

| # | Step | Source | Notes |
|---|---|---|---|
| 1 | Employment window | `staff_window` | joined/exit, employed day range |
| 2 | **Contracted** compensation | frozen salary snapshot | the ₹30,000 on the contract |
| 3 | Attendance & LOP | frozen attendance snapshot | day-wise record, unmarked-day policy, **named LOP days** (full/half) |
| 4 | Proration | engine `bases` | retention ratio, absent fraction, daily salary |
| 5 | **Earned** earnings | slip | the ₹28,500 actually earned — deliberately distinct from step 2 |
| 6 | Adjustments *(when present)* | `_payroll_adjustments` | original gross → each line → revised gross |
| 7 | Gross | engine paise | |
| 8 | Statutory | engine `bases` + paise | **base, rate and result for each** of PF/ESI/TDS/PT |
| 9 | Other deductions | engine paise | |
| 10 | Amounts **not** deducted *(when present)* | `deferred` | answers "why was PT not deducted" |
| 11 | Net pay | engine paise | gross − deductions = net |
| 12 | Employer cost | engine paise | employer PF/ESI + CTC, flagged as cost not deduction |

Steps 2 and 5 are separate on purpose: "contracted ₹30,000, earned ₹28,500" is the explanation;
one number alone is not.

**Statutory (§10 of the brief):** where no tenant policy exists the step says so —
*"No tenant statutory policy is configured, so no PF ceiling or ESI threshold was applied. The rates
come from the salary structure."* It never presents a rate as though a policy had been configured.

## 14. Accounting trace — **PARTIAL, declared**

The slip already carries `journal_id`, the run carries `accrual_journal_id` /
`accrual_journal_status` / `accrual_payload`, and regeneration posts an idempotent delta via
`postRunAdjustment()` with `Dr == Cr` engine-validated. **Those references are not yet surfaced as a
trace step** — §4.K of the brief asked for debit/credit lines and reconciliation status per employee,
and journal lines are run-level, not per-slip.

Rather than invent a per-employee ledger view, this is **declared PARTIAL** and carried in the
backlog (item Z/V). Assembling it needs a journal read, which would break this gate's one-read
property — a design decision worth taking deliberately in its own gate.

## 15. Reproducibility

The trace reads **the frozen slip and nothing else**. Test-asserted on **access primitives**
(`schoolWhere`, `getEntity`, `->query(`, `$this->fs`, `$fs`, `firebase`) rather than on collection
names — see §19 for why that correction mattered.

So a finalized payslip stays explainable after today's salary assignment, attendance, leave,
component catalogue or payroll settings change. **The one exception is statutory policy**, and the
trace states that exception on its own face rather than leaving it to be discovered.

## 16. Performance / read budget

| Operation | Firestore reads |
|---|---|
| One employee's full trace | **1** — a single payslip document |
| Trace for a 1,000-employee run | 1 per employee **opened**; the grid loads none |

**No N+1.** The trace is loaded on demand from the drill-down, not with the grid — a full trace per
row would be dead weight on a 200-row run nobody has drilled into. Generation and regeneration each
gained **zero** reads; the trace rides writes that were already happening.

## 17. RBAC

`hr/payslip_trace` → **`HR:view`**, consistent with `get_payroll_slips` (a trace exposes the same
salary data as the payslip it explains). Graded permission with an explicit action name; no
role-name gates. Test-asserted by regex on the exact triple.

**Verified security property:** the frozen `_payroll_adjustments` block stores
`override_id/type/label/kind/amount/prorated` but **not `reason`**. The internal justification for an
adjustment ("retention adjustment pending exit") lives only in `payrollOverrides`, which is
server-only and admin-read. So §8's "no accidental exposure of internal adjustment reasons" holds
**by construction**, not by filtering. Confirmed by inspection this gate.

No client write path was added. No existing rule was weakened.

## 18. Tenant isolation

The payslip is addressed through `docId()`, which prefixes the caller's own school_id — a forged
`run_id`/`staff_id` pair from another tenant resolves to a document that does not exist. An explicit
`schoolId !== $this->school_id` re-check follows and logs `PAYSLIP_TRACE_TENANT_MISMATCH`. Both
test-asserted. No new collection, no new tenant surface.

## 19. Tests — 28 new, all passing

**Consistency (the central property):** trace reconciles with the stored payslip at **exact integer
paise** · `gross == net + deductions` on the frozen numbers · **a one-paisa injection breaks it** ·
a trace disagreeing with the stored rupee figure is detected · deduction components must sum to the
total.

> §10 required no tolerances. A one-paisa test exists precisely so the consistency check cannot be
> quietly weakened into decoration.

**Chain:** ordered employment→employer-cost · net after statutory · employer cost after net ·
contracted ≠ earned · LOP days named from the frozen mask · retention ratio exposed · **both
statutory bases stated and the stated base × rate reproduces the stated deduction** · net matches
stored · employer cost does not reduce net · CTC = gross + employer contributions.

**Adjustments:** original/final shown · step absent when there are none.

**Missing evidence:** missing statutory policy stated not faked · statutory snapshot declared
unavailable · **missing attendance snapshot declared and day detail NOT invented** · missing
compensation snapshot declared · missing trace reports unavailable (and `consistent` is `null`, not
`true` — unknown is not the same as consistent).

**Architecture:** the trace reaches no live data · performs no payroll arithmetic · is deterministic ·
both slip writers freeze it · the engine states its bases · endpoint `HR:view`-gated and
tenant-scoped.

**One correction during this gate.** My "reads nothing live" guard initially failed on the literal
`'statutoryConfig'` — which turned out to be legitimate code comparing the engine's own
`statutory_source` marker, not a query. **The assertion was too blunt**, banning a frozen value for
sharing a collection's spelling. Re-pointed at access primitives, which is both correct and
stronger: the helper now provably has no means of reaching Firestore at all.

## 20. Browser verification — **NOT PERFORMED**

**OPERATOR VERIFICATION REQUIRED.** The drill-down button, trace rendering, consistency banner,
per-step tables and the on-demand load are written, lint-clean and routed — **no browser test was
run and none is claimed.**

## 21. Test-School verification — **NOT PERFORMED**

No Test-School data was read destructively, written or manufactured.

## 22. Commercial HRMS gap status

Against §4 of the brief: **A–J and K-partial** are delivered. The trace answers *"why did this
employee receive this exact payslip"* from frozen evidence, for every step **except** the statutory
policy in force (blocked) and per-employee accounting lines (PARTIAL, §14).

This does not claim benchmark parity. It claims the calculation moved from *opaque* to *explained
and self-checking*.

---

## BACKLOG RECONCILIATION

| Capability | Previous Status | New Status | Evidence | Remaining Work | Next Gate |
|---|---|---|---|---|---|
| **B** Full payroll calculation trace | PARTIAL | **PARTIAL** *(implementation done, UAT open)* | Engine `bases`; `_calculation_trace` on both writers; `payroll_build_trace()`; `hr/payslip_trace`; drill-down UI; 28 tests incl. exact-paise reconciliation | Browser UAT; accounting step (§14); statutory step needs D | **UAT** + W3.2.6 |
| **D** Statutory snapshot | BLOCKED | **BLOCKED** *(unchanged)* | Trace declares `statutory_snapshot: false` and returns NOT CONFIGURED | Depends on W2 statutory policy | W3.2.6 |
| **V** Full audit history | PARTIAL | **PARTIAL** *(advanced)* | Trace is now a per-employee audit view over frozen evidence | Unified audit timeline; accounting lines | later gate |
| **Z** Accounting reconciliation | COMPLETE | **COMPLETE** *(unchanged)* | `Dr == Cr` engine-validated; verifiers PASS | Per-employee ledger view tracked under V | — |
| **AA** Master-matrix reconciliation | *(new)* | **NOT DONE** | Matrix has **115** rows, not 95; statuses stale (row 34 reads MISSING but W2.1 built it) | Row-by-row re-audit of all 115 | **first task of next wave** |

**Deliberately NOT marked COMPLETE:** B stays PARTIAL despite the implementation being finished,
because browser UAT has not run and the statutory step cannot be complete while D is blocked. An
endpoint existing is not completion.

## UNTOUCHED BACKLOG — not addressed by this wave

**A** LOP review *(COMPLETE, W3.2.3)* · **C** Async/scalable generation *(MISSING — next gate)* ·
**E** Non-taxable earning / reimbursement *(MISSING — engine gate)* · **F** Separable payroll
authorization / maker-checker *(NEEDS ENHANCEMENT)* · **G** Adjustment approval workflow *(MISSING)* ·
**H** Bulk adjustment / import *(MISSING)* · **I** Configurable accounting mapping *(OPERATOR
DECISION)* · **J** Full browser UAT *(MISSING)* · **K** Test-School integration testing *(MISSING)* ·
**L** Payroll reports *(PARTIAL)* · **M** Professional payslip / document output *(PARTIAL)* ·
**N** Payroll correction workflow *(PARTIAL)* · **O** Arrears *(PARTIAL)* · **P** Revision history
*(PARTIAL)* · **Q** Employee self-service *(PARTIAL)* · **R** Compensation visibility per RBAC
*(PARTIAL)* · **S** Statutory reports *(MISSING)* · **T** Registers / bank payment output
*(MISSING)* · **U** Payroll reconciliation *(PARTIAL)* · **W** Period locking *(COMPLETE)* ·
**X** Payroll approval *(PARTIAL)* · **Y** Finalization lifecycle *(COMPLETE)* · plus the priority
**reimbursement engine** item and the **115-row matrix** itself.

**Nothing was removed, collapsed or reinterpreted.**

## 23 / 24. Regression, deployment and commit status

| Suite | Before W3.2.4 | After | New failures |
|---|---|---|---|
| PHP unit | 420 / 1355 / **10** | **448 / 1447 / 10** (+28) | **0** |
| `hrms_w2_parity` | PASS | **PASS** | 0 |
| `hrms_w2_verify` | 45 / 0 | **PASS** | 0 |
| `hrms_p0_verify` | PASS | **PASS** | 0 |
| `hrms_closure_verify` | PASS | **PASS** | 0 |
| `hrms_w13_verify` | PASS | **PASS** | 0 |

The 10 are the documented pre-existing set, unchanged in identity and count. **No baseline failure
was "fixed" in passing.** `php -l` clean on every touched file. `hrms_p0_verify` PASS is the specific
evidence that adding `bases` to the engine's return changed no money.

- **Production data changed:** **none.**
- **Deployed:** **nothing.**
- **Committed / pushed:** **nothing.**
- **Browser verification:** **not performed, not claimed.**
- **Integration / UAT verification:** **not performed, not claimed.**

## Files

**New:** `application/helpers/payroll_trace_helper.php` · `tests/Unit/PayrollCalculationTraceTest.php`
**Modified:** `application/controllers/Hr.php` (engine `bases`; `_calculation_trace` ×2;
`payslip_trace()`) · `application/config/routes.php` · `application/views/hr/index.php` ·
`ZENX_HRMS_CAPABILITY_BACKLOG.md` (B updated, AA added)
