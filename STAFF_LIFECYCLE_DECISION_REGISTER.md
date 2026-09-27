# Staff Lifecycle — decision register

**2026-08-22.** Every open decision, re-evaluated against statute, case law,
official vendor documentation, ZenX's governing spec, and the shipped
architecture. Items resolved objectively have been **implemented**; items that
are genuinely contractual, policy or statutory-advice dependent remain blocked
with the exact evidence required named.

Status key: **RESOLVED+IMPLEMENTED** · **RESOLVED, GATED** (correct answer
known, wiring behind a flag pending owner/CA) · **BLOCKED** (needs input that
cannot be researched).

---

## Summary

| ID | Decision | Status | Blocks |
|---|---|---|---|
| **DR-01** | PF/statutory wage base architecture | **RESOLVED, GATED** | Real payroll |
| **DR-02** | Add-back denominator semantics (W-1) | **RESOLVED+IMPLEMENTED** | — |
| **DR-03** | Add-back threshold configurability (W-2) | **RESOLVED+IMPLEMENTED** | — |
| **DR-04** | Add-back rounding direction (W-3) | **RESOLVED+IMPLEMENTED** | — |
| **DR-05** | Q6 — deduction floor / negative net | **RESOLVED — already correct** | — |
| **DR-06** | Payment of Wages deduction ceiling | **RESOLVED, GATED** | — |
| **DR-07** | Q1 — gratuity eligibility | **RESOLVED, GATED** | F&F engine |
| **DR-08** | Q2 — gratuity formula | **RESOLVED, GATED** | F&F engine |
| **DR-09** | Q5 — notice recovery basis | **BLOCKED** | F&F engine |
| **DR-10** | Q8 — approval threshold | **BLOCKED** | F&F workflow |
| **DR-11** | Q10 — clearance override | **RESOLVED** | F&F workflow |
| **DR-12** | Q9 — cancellation / reopen | **RESOLVED** | F&F workflow |
| **DR-13** | Leave encashment in PF | **RESOLVED** | F&F engine |
| **DR-14** | `SALARY_PROCESSED` timing | **RESOLVED, GATED** | Nothing |
| **DR-15** | `USE_COMPONENT_COMPENSATION` cutover | **BLOCKED** on evidence | Effective dating |
| **DR-16** | Off-cycle in dashboards/reports | **RESOLVED+IMPLEMENTED** | — |
| **DR-17** | EPS split / PF membership record | **RESOLVED, GATED** | ECR filing |
| **DR-18** | Effective-date vs payout-from | **BLOCKED** on prerequisite | Arrears |

---

## DR-01 — PF / statutory wage base architecture

**Question.** Should PF use a hard-coded formula, a configurable formula, a
canonical wage engine, or component classification feeding a wage engine?

**Evidence.** EPF Act s.6 names basic + DA + retaining allowance.
*Vivekananda Vidyamandir* (SC 2019, **an unaided school**) makes universally-paid
allowances basic wages — a per-employer question of fact, not a list. Zoho
Payroll ships per-component three-state wage classification which explicitly
*"replaces the separate EPF and ESI options"*. ZenX spec §6.1 already requires
per-component statutory flags; §6.3 requires `statutory_wage_base` consumed by
all PF/gratuity/bonus/OT, "never raw basic".

**Market practice.** Zoho: per-component, three states. Keka/greytHR: basic+DA.
Asanify: per-employee eligibility flag, base documented as "typically Basic"
(safe only where DA is zero).

**Existing ZenX behaviour.** PF on earned basic, ESI on gross.
`pfApplicable`/`esiApplicable` exist on every catalogue row and are **read by
nothing** — `Compensation_reader` flattens components to six legacy fields and
drops the rest.

**Law constrains it?** Yes as to the definition; no as to which component is
which — that is the universality question of fact.

**Tenant configuration can represent it?** Yes — the catalogue already carries
per-component metadata and `LOCKED_FIELDS` makes reclassification deliberate.

**Recommended default.** Option D. Unclassified ⇒ **INCLUDE** (fail-safe).

**Safe to implement now?** The engine, yes — done. The **wiring**, no.

**Owner/CA input required.** Yes — CA-1 (which allowances are universal),
CA-2 (fixed vs reimbursement), OWN-1 (accept the cost).

**Blocked code.** `_compute_payslip()`'s two call sites.
**Unblocked.** Everything else: wage engine, classification vocabulary, config,
tests, parity harness.

---

## DR-02 — Add-back denominator semantics (W-1) · **IMPLEMENTED**

**Question.** Should the wage engine use an inclusion list or an exclusion list?

**Evidence.** s.2(y): wages are **all remuneration minus** clauses (a)–(i). An
inclusion-list model gives a component in neither list a weight of zero in the
base **and** in the denominator the 50% test measures against — under-triggering
the add-back, i.e. failing toward *less* statutory liability.

**Objectively resolvable?** Yes — the statutory structure settles it.

**Implemented.** `computeFromComponents()` defaults an unclassified component to
`INCLUDE` and **names it** in `unclassified[]` so the gap is visible rather than
absorbed. The legacy six-field path is unchanged, and safely so: its vocabulary
is closed and fully enumerated.

---

## DR-03 — Add-back threshold configurability (W-2) · **IMPLEMENTED**

**Evidence.** The first proviso reads *"one-half, **or such other percentage as
may be notified by the Central Government**"*. A notifiable percentage is a
dated configuration value, not a constant.

**Implemented.** `thresholdNum`/`thresholdDen` parameters, exact integer
fractions, default 1/2, seeded in `hrms_statutory_flags.php` with a source
comment and a `TODO: verify with CA` marker. Malformed values fall back to the
statutory default rather than dividing by zero.

---

## DR-04 — Add-back rounding direction (W-3) · **IMPLEMENTED**

**Found while implementing DR-02.** The threshold rounded **up**, and its
comment claimed that prevented understating the base. It does the opposite: a
higher threshold is harder to exceed, so it yields a smaller add-back.

On a 3-paise total with a 2-paise excluded pot, `2 > 1.5` is true and the
proviso applies — the old code suppressed it. An existing test pinned this under
the name *"An odd total cannot round the boundary in the employer's favour"*,
asserting the opposite of its own title.

**Implemented.** The trigger is now exact cross-multiplication
(`conditional × den > total × num`) requiring no rounding; the threshold
subtracted is floored so the excess rounds **up** by at most a paise — in the
employee's favour. Test inverted, not deleted.

---

## DR-05 — Deduction floor / negative net · **ALREADY CORRECT**

**Question.** Clamp at zero, allow negative, or clamp and flag?

**Evidence.** Payment of Wages Act s.7(3) caps deductions; s.7(4) preserves
other recovery routes. So a negative-net payslip is not lawful, but silently
forgiving the excess is wrong too.

**Existing ZenX behaviour — better than previously credited.**
`_compute_payslip()` already runs a deduction waterfall (Other → PT → statutory,
proportionally), clamps net at **≥ 0**, and persists exactly what was deferred
per category onto the payslip as `deferred.{other_deductions, professional_tax,
statutory}`. That **is** clamp-plus-recoverable-record.

**Action.** None. Corrected in the decision pack, which had recorded this as
open.

---

## DR-06 — Payment of Wages deduction ceiling · **RESOLVED, GATED**

**Evidence.** s.7(3): total deductions must not exceed **75%** where partly for
co-operative-society payments, **50%** otherwise.

**Existing ZenX behaviour.** No ceiling — deductions may reach 100% of gross
before the clamp engages.

**Law constrains it?** Yes, the cap exists. **Which** cap applies depends on
whether the tenant has co-operative-society deductions — tenant data that does
not exist in any tenant today.

**Recommended.** Configurable ceiling, statutory default seeded, feeding the
**existing** waterfall (not a parallel mechanism), enforcement behind the
statutory flag; warning unconditional.

**Safe to implement now?** The warning, yes. Enforcement changes money, so it
ships with DR-01.

---

## DR-07 / DR-08 — Gratuity eligibility and formula · **RESOLVED, GATED**

**Evidence.** Payment of Gratuity Act s.4(1): five years continuous service,
**expressly waived on death or disablement**; service beyond six months rounds
up. Formula: `(last drawn basic + DA) × 15/26 × completed years`, statutory
maximum ₹20,00,000.

**Objectively resolvable?** The floor and formula, yes. Whether a school pays
**more** generously is owner policy — so: statutory floor, tenant override
permitted upward only.

**The trap, recorded.** The divisor **26 is specific to gratuity** and is *not*
among the divisors ZenX's `days_basis` offers (calendar / fixed-30 / working
days). Routing gratuity through `days_basis` would silently under-pay statutory
gratuity for any tenant on "fixed 30".

**Blocked on.** DR-01 (the base), plus CA confirmation of the current ceiling
and of treatment for service spanning Nov 2025. No calculator built yet:
building it before the wage base is settled would hard-code the wrong base.

---

## DR-09 — Notice recovery basis · **BLOCKED**

**Question.** Recovery on basic, gross, or per-day CTC?

**Evidence found.** Every established vendor exposes it as a **configurable**
component rather than a fixed formula — itself evidence that no market default
exists. GST Circular 178/2022 establishes only that notice-pay recovery is not
taxable under GST; it constrains booking, not the basis.

**Why it cannot be researched further.** It is a term of the employment
contract. No statute sets it and no market default exists.

**Required.** Owner decision, per employment contract.
**Blocked code.** F&F recovery calculation. **Unblocked.** The notice state
model, which already records `waived` / `reduced` and returns `policy_required`
rather than guessing.

---

## DR-10 — Approval threshold · **BLOCKED**

Separation of duties is inferable and **already implemented** —
`Fnf_state_machine` fails closed when approver == initiator. The monetary
*threshold* for dual approval is an internal control decision reflecting the
school's delegation of authority. Not researchable.

---

## DR-11 — Clearance override · **RESOLVED**

**Recommended.** Compute, but block **approval**. Computing is not paying;
blocking computation makes it impossible to tell an employee what they are owed
while a library book is outstanding — a support burden with no control benefit.
Blocking approval preserves the actual control. Override-with-reason puts the
decision at the wrong point: it lets money out on one person's say-so.

**Safe to implement.** Yes, when the F&F workflow is built. No policy input
needed.

---

## DR-12 — Cancellation / reopen · **RESOLVED**

**Recommended.** Cancel stays terminal. Consistent with the ledger model already
in place (`postReversal()` — corrections are compensating entries, never
rewrites), and a school that cancels in error can initiate a new settlement,
because the guard is one *open* settlement per employee, not one ever. Reopening
would be the first place in the money path where a terminal state is not
terminal.

---

## DR-13 — Leave encashment in the PF base · **RESOLVED**

*Manipal Academy* (SC 2008): leave encashment is **not** basic wage, because
only some employees avail it — it fails universality. Implemented as a
per-statute carve-out in the design, not as a general exclusion, since the same
component may be wages for other purposes.

---

## DR-14 — `SALARY_PROCESSED` timing · **RESOLVED, GATED**

**Trigger semantics objectively established.** The run lifecycle is
generate → finalize → approve → mark_paid. `unlock_payroll` reverses **both**
finalized and approved back to draft, and regeneration can still change a slip.
Only **full payment** is irreversible. Under the rule applied throughout this
programme — a person is never notified about a figure that can still change —
fully-paid is the only defensible trigger.

**Still gated.** Whether to notify at all is a product choice: "your payslip is
available" and "your money has been paid" are different promises, and the second
depends on a bank's timing rather than the software's. Sending push to real
staff devices is outward-facing.

**Blocks nothing.**

---

## DR-15 — Component compensation cutover · **BLOCKED on evidence**

Preconditions unchanged: catalogue seeded, migration run, parity harness clean.
**The documented trap stands** — switching the A/B shadow on today compares
against empty collections and reports a meaningless "no divergence". Use the
offline harness first.

**Corrected understanding.** Flipping this flag alone does **not** fix PF, because
the reader flattens components to six legacy fields before the calculator sees
them. DR-01 and DR-15 are independent cutovers and must have independent flags —
which is why `USE_STATUTORY_WAGE_BASE` was created separately.

---

## DR-16 — Off-cycle runs in dashboards and reports · **IMPLEMENTED**

All nine `type == 'run'` consumers audited. Two defects found and fixed:
**G-1** an off-cycle settlement could satisfy `lock_payroll_month`'s
precondition and freeze out a payroll that had never run; **G-2** an off-cycle
run could become the dashboard's headline payroll figure. The run **count**
deliberately still includes off-cycle runs — they are runs. Structure-deletion
protection deliberately still sees every run, including a leaver's.

---

## DR-17 — EPS split and PF membership record · **RESOLVED, GATED**

**Evidence.** EPFO: employer 12% splits 8.33% to EPS **capped ₹1,250/month**,
balance to EPF. A joiner above the ceiling may join only under **Para 26(6)**
and gets **no EPS** — the whole 12% goes to PF.

**Existing ZenX behaviour.** Employer PF is a single figure. Coverage is
**re-derived each run** from wage versus ceiling, so ZenX cannot express "joined
below the ceiling, now earns above it, remains a member" — the commonest
real-world case.

**Recommended.** Split employer PF; add a per-employee PF membership record.
Asanify's per-employee `HAS_PF` flag confirms membership is an attribute of a
person, not of a tenant.

**Why now matters.** Adding a membership record after payslips exist is far more
expensive. Today there are none.

**Blocked on.** DR-01 sequencing and CA-6.

---

## DR-18 — Effective date vs payout-from · **BLOCKED on prerequisite**

Asanify distinguishes *when a revision takes effect* from *when it starts being
paid* — how arrears are expressed without rewriting a closed period. ZenX's
`[effectiveFrom, effectiveTo)` model expresses the first and has no equivalent
of the second.

**Not blocked on a decision — blocked on a prerequisite.** ZenX has no arrears
mechanism, and the spec (§6.1) requires retro-effective revisions to
"auto-generate arrears across affected past periods". Adding payout-from without
arrears would record an intent nothing acts on.

---

## What is NOT in this register, and why

- **Rules deployment (D-003/D-004/D-007)** — an authorization matter, not a
  decision. D-004 is applied locally and undeployed; the rules suite is BLOCKED
  on JDK 21+ (machine has 17) and that has not been worked around.
- **Anything requiring a flag flip** — no flag was flipped.
