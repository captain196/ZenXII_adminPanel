# Staff Lifecycle — owner & CA decision pack

**2026-08-22 · engineering phase CLOSED · system in controlled holding state.**

Nothing committed, pushed, deployed, migrated, or flag-flipped. All four safety
flags verified `false`. Every fix listed in the freeze inventory verified present.

This is the **single** decision document. The research behind it lives in
`STAFF_PAYROLL_PF_MARKET_AND_STATUTORY_RESEARCH.md`,
`STAFF_LIFECYCLE_ASANIFY_EVIDENCE.md` and `STAFF_LIFECYCLE_DECISION_REGISTER.md`;
the verification state lives in `STAFF_LIFECYCLE_FINAL_PRODUCTION_READINESS_GATE.md`.

---

## How every claim below is labelled

| Label | Meaning |
|---|---|
| **① STATUTORY** | Required by statute or binding case law |
| **② MARKET** | What established products do — evidence, never authority |
| **③ ZENX** | What the shipped architecture already does |
| **④ OWNER** | The school's commercial or contractual decision |
| **⑤ CA/LEGAL** | Requires a chartered accountant or legal confirmation |

**Market practice is never converted into ZenX policy.** Where a recommendation
appears, it is because ① or ③ makes it objectively defensible — not because a
competitor does it.

---

## The twelve open decisions

| ID | Decision | Owner | CA | Blocks |
|---|---|---|---|---|
| **OWN-1** | Accept the PF cost impact | ● | | The PF correction |
| **OWN-4** | Are pay-scale / DA structures used? | ● | | Scope of the PF correction |
| **CA-1** | Which allowances are paid universally? | | ● | Component classification |
| **CA-2** | Medical: allowance or reimbursement? | | ● | Component classification |
| **CA-3** | Current PF/ESI rates and ceilings | | ● | Statutory config seed |
| **CA-4** | Current add-back percentage | | ● | Wage-engine threshold |
| **CA-5** | Restrict PF to ceiling, or above? | ● | ● | PF calculation |
| **CA-6** | Members above ceiling / Para 26(6) | | ● | PF membership model |
| **CA-7** | Gratuity across the Nov 2025 transition | | ● | Gratuity calculator |
| **CA-8** | ESI applicability by location | | ● | ESI enablement |
| **DR-09** | Notice-recovery basis | ● | | F&F calculation |
| **DR-10** | Approval threshold / dual sign-off | ● | | F&F workflow |

---

## OWN-1 — Do you accept the PF cost impact?

**Why it matters.** Correcting the PF wage base raises **both** the employee
deduction and the school's employer contribution. It is the only decision here
with a direct, recurring cash cost.

**Current ZenX behaviour ③.** PF is computed on **earned basic alone**, in both
the legacy and statutory-aware branches. ESI is computed on gross.

**Statutory constraint ①.** EPF & MP Act 1952 **s.6** makes the contribution
payable on *"the basic wages, dearness allowance and retaining allowance (if
any)"*. *RPFC (II) West Bengal v. Vivekananda Vidyamandir* (SC, 2019) adds any
allowance paid **"universally, uniformly, necessarily and ordinarily"** to all
employees. The respondent in that case was **an unaided school**.

**Market evidence ②.** Keka and greytHR document basic + DA. Asanify documents
*"typically 12% of Basic"* — shorthand that is safe only where DA is zero.

**Financial impact — SCENARIO MEASUREMENTS, NOT YOUR SCHOOL'S COST.**

Measured by the parity harness across five synthetic profiles. **These are
illustrative scenarios chosen to span the market, not a forecast for any actual
school.** Your real figure depends on your actual structures and on CA-1/CA-2.

| Scenario profile | Legacy base | Statutory base | Delta |
|---|---|---|---|
| Private CTC school, **no DA** | ₹25,000 | ₹35,500 | **+₹10,500** |
| Aided pay-scale, large DA | ₹38,000 | ₹42,400 | +₹4,400 |
| Token basic, heavy allowances | ₹25,000 | ₹27,000 | +₹2,000 |
| Compliant, basic > half | ₹35,000 | ₹39,000 | +₹4,000 |
| At the PF ceiling | ₹15,000 | ₹18,000 | +₹3,000 |

**Maximum observed delta in these scenarios: ₹10,500/month of base, which is
₹2,520 per employee per month at 12% + 12%.** Again: a scenario measurement.

Note the first row. That school has **no DA at all**, yet shows the largest
delta — because its exposure is a ₹9,250 residual allowance, not DA. A
"basic + DA" fix would have changed nothing for it.

**Options.** (a) Correct before the first payroll · (b) run the parity harness
on your real structures first, then decide · (c) leave as-is.

**If left unresolved.** Nothing breaks today — 0 payslips exist. But the
correction gets permanently more expensive: the moment the first payslip is
issued on the wrong base, correcting it means arrears, revised returns and
employee communication. **Today there is nothing to unwind.**

**Recommendation — objectively defensible ①.** (a) or (b), not (c). (c) means
knowingly under-contributing against a statute and a Supreme Court decision. The
choice between (a) and (b) is yours; (b) costs one harness run.

**What you must provide.** A yes/no on accepting the corrected base, ideally
after seeing the figure for your own structures.

**What follows.** `USE_STATUTORY_WAGE_BASE` moves to `true` **per tenant**, and
two call sites in `_compute_payslip()` switch from `$effectiveBasicP` /
`$grossP` to the resolved wage base. No other code changes.

---

## OWN-4 — Do you use pay-scale / DA structures?

**Why it matters.** It decides whether the PF defect is **live or theoretical**
for your school.

**Current ZenX behaviour ③.** `da` is a real, populated salary-structure field
that already counts toward gross. ZenX's own specification names `PAY_SCALE`
("pay-band + grade-pay + increment-date for aided institutions") as a
first-class structure type alongside `CTC`.

**Statutory constraint ①.** DA is named in the EPF contribution base.

**Financial impact.** If DA is used, the DA amount enters the PF base directly.
If it is not, the exposure is entirely in the residual/medical allowances
(CA-1/CA-2) — which the scenarios show can be larger.

**If left unresolved.** The scope of the correction cannot be estimated.

**No recommendation** — it is a fact about your school, not a choice.

**What you must provide.** Whether any staff are on pay-scale/grade-pay terms
with a DA component, and roughly what proportion.

**What follows.** Determines which scenario profile is representative and
therefore the real cost in OWN-1.

---

## CA-1 — Which allowances are paid universally, uniformly, necessarily and ordinarily?

**The single most consequential CA question in this pack.**

**Why it matters.** This is the legal test that decides whether an allowance is
PF wages. It is a question of fact about **how your school pays its staff** —
not about the allowance's name.

**Current ZenX behaviour ③.** `OTHER_ALLOWANCES` is seeded as **not**
PF-applicable, and the payroll engine excludes it from the PF base.

**Statutory constraint ①.** *Vivekananda Vidyamandir* (SC, 2019): allowances
meeting the universality test **are** basic wages for PF. The case concerned a
school's special allowance paid to teaching and non-teaching staff. Separately,
Code on Wages s.2(y) lists the excluded components — and a residual allowance is
**not** among them.

The same test explains why leave encashment is **excluded** (*Manipal Academy*,
SC 2008): only some employees avail it, so it fails universality.

**Market evidence ②.** Zoho Payroll exposes this as a **per-component**
three-state classification, and its documentation notes the setting *"replaces
the separate EPF and ESI options that were available earlier"* — i.e. the market
has moved to exactly this model. Zoho publishes **no default classifications**,
only guidance — because the answer is the employer's.

**Financial impact.** Directly drives the OWN-1 figure. In the scenarios above,
the residual allowance was the largest single contributor.

**If left unresolved.** The engineered default is **INCLUDE** (wages), because
s.2(y) makes an unnamed component wages. That default is safe — it cannot
under-contribute — but it may over-contribute for a school whose residual
allowance genuinely is not universal.

**No recommendation.** ZenX must not decide how your school pays its staff.

**What your CA must provide.** For each allowance component — is it paid to all
staff, uniformly, ordinarily and necessarily? A yes/no per component.

**What follows.** A `wageClassification` value per component
(`INCLUDE` / `CONDITIONAL` / `EXCLUDE`) on the tenant's component catalogue.
**Configuration only — no code change.** The override already outranks the
statutory default.

---

## CA-2 — Is medical a fixed allowance or reimbursement of actuals?

**Why it matters.** A fixed **allowance** is remuneration and therefore wages. A
**reimbursement** of actual expense falls under s.2(y)(e), "sums paid to defray
special expenses", and is outside both the wage base and the add-back
denominator.

**Current ZenX behaviour ③.** `MEDICAL` is a fixed structure field, excluded
from the PF base, seeded as not PF-applicable.

**Statutory constraint ①.** Medical is **not** a named exclusion. Only genuine
reimbursement qualifies under clause (e).

**If left unresolved.** Default is **INCLUDE** — safe, possibly generous.

**No recommendation** — it depends on how you actually operate the component.

**What your CA must provide.** Whether the medical component is a flat monthly
amount paid regardless of expense, or reimbursement against bills.

**What follows.** `wageClassification` = `INCLUDE` (allowance) or `EXCLUDE`
(reimbursement). Configuration only.

---

## CA-3 — Current PF/ESI rates, ceilings and thresholds

**Why it matters.** ZenX's governing spec forbids statutory values from memory:
*"Never invent rates, slabs or ceilings from memory. Every value goes into a
seeded, versioned config table with a source-URL comment and a `TODO: verify with
CA` marker."*

**Current ZenX behaviour ③.** `Statutory_config_service` is fully table-driven
and **base-agnostic** — it already honours ceiling, employee/employer rates,
apply-ceiling and eligible-above-ceiling. **No tenant has a `statutoryConfig`
document yet** (census: 0).

**Statutory reference ① — for your CA to confirm, not for ZenX to adopt.** EPFO
publishes: 12% employee; 12% employer split 8.33% to EPS **capped ₹1,250/month**
with the balance to EPF; wage ceiling **₹15,000/month**. None of these values is
written into ZenX code, and none should be until your CA confirms them.

**If left unresolved.** No tenant can run a statutory-aware payroll at all.

**No recommendation on values** — that is the definition of CA sign-off.

**What your CA must provide.** Confirmed rates, ceilings and thresholds for the
current financial year, with sources.

**What follows.** A seeded, effective-dated `statutoryConfig` document per
tenant. Configuration only.

---

## CA-4 — The currently applicable add-back percentage

**Why it matters.** The Code on Wages first proviso reads *"one-half, **or such
other percentage as may be notified by the Central Government**"*. It is a dated
value, not a constant.

**Current ZenX behaviour ③.** Seeded as `1/2` in
`STATUTORY_ADDBACK_THRESHOLD_NUM/DEN`, expressed as an exact integer fraction so
the arithmetic stays paise-exact. It was previously hard-coded; that is fixed.

**If left unresolved.** The seeded one-half stands, which is the current position
— but nobody is watching for a notification.

**Recommendation — objectively defensible ①.** Keep `1/2` and re-verify each
financial year, per the spec's compliance register.

**What your CA must provide.** Confirmation that one-half is in force, and
notification of any change.

**What follows.** Two integers in config. No code change.

---

## CA-5 — Restrict PF to the statutory ceiling, or contribute above it?

**Why it matters.** This is an employer election with a direct cost consequence.

**Current ZenX behaviour ③.** Both options are **already implemented** —
`pf.applyCeiling` and `pf.eligibleAboveCeiling`. Neither is configured anywhere.

**Statutory constraint ①.** Contribution above the ceiling is permitted, not
required. An employee joining above the ceiling may become a member only by
option under **Para 26(6)** of the EPF Scheme, and in that case gets **no EPS** —
the whole 12% goes to PF.

**Market evidence ②.** Zoho and greytHR both expose restrict-to-ceiling as a
toggle, confirming it is conventionally an election rather than a default.

**Financial impact.** Restricting to ₹15,000 caps employee contribution at
₹1,800/month; contributing on actual wages does not.

**If left unresolved.** No default is applied — PF cannot be computed correctly
for anyone above the ceiling.

**No recommendation** — it is a cost election, and part contractual.

**What you and your CA must provide.** Which election applies, and whether it is
uniform across staff.

**What follows.** Two booleans in the tenant's statutory config.

---

## CA-6 — Existing members above the ceiling / Para 26(6) cases

**Why it matters.** This is a **data-model** question, not a rate question, and
it is the one that gets expensive later.

**Current ZenX behaviour ③ — and the gap.** ZenX has **no per-employee PF
membership record**. Coverage is **re-derived on every payroll run** from wage
versus ceiling. That cannot express the commonest real case: *joined below the
ceiling, now earns above it, remains a member.*

**Statutory constraint ①.** An existing member continues as a member on crossing
the ceiling. A new joiner above the ceiling needs the Para 26(6) option and gets
no EPS.

**Market evidence ②.** Asanify uses a per-**employee** `HAS_PF` flag,
confirming membership is an attribute of a person rather than of a tenant.

**If left unresolved.** Any school with staff above ₹15,000 who were members
before crossing it will have PF computed wrongly — and this is **not** latent
once payroll starts.

**Recommendation — objectively defensible ①③.** Add a per-employee PF membership
record **before the first payroll**. Adding it afterwards means reconstructing
membership history for every employee.

**What your CA must provide.** Whether any staff are existing members above the
ceiling, and whether any joined above it under Para 26(6).

**What follows.** A new per-employee field plus a read in the eligibility path.
**This is the one item here that requires code, not just configuration.**

---

## CA-7 — Gratuity for service spanning November 2025

**Why it matters.** The labour codes changed the wage definition mid-service for
existing employees.

**Current ZenX behaviour ③.** No gratuity calculator exists. Nothing has been
computed, so nothing is wrong yet.

**Statutory constraint ①.** Payment of Gratuity Act s.4: five years continuous
service, **expressly waived on death or disablement**; `(last drawn basic + DA)
× 15/26 × completed years`; statutory maximum ₹20,00,000. ZenX's own spec records
that gratuity is *"in transition for service spanning Nov 2025"* and that state
procedural rules were still being notified through 2026.

**A trap worth stating ③.** The divisor **26 is specific to gratuity** and is
**not** among the divisors ZenX's `days_basis` offers (calendar / fixed-30 /
working days). Gratuity must never be routed through `days_basis`, or a tenant
on "fixed 30" would silently under-pay statutory gratuity.

**If left unresolved.** No gratuity can be calculated — which is the current
state and is safe.

**No recommendation on the transition treatment** — it is precisely the
unsettled area.

**What your CA must provide.** How to treat service before and after 21 Nov 2025.

**What follows.** A pure `Gratuity_calculator` library, built only after CA-3
(the base) and this are settled.

---

## CA-8 — ESI applicability for your locations

**Why it matters.** ESI applicability is **location-based** (implemented areas),
and multi-site schools can differ site to site.

**Current ZenX behaviour ③.** ESI is computed on **gross** for everyone, with no
location logic. Under the codes it should derive from the same wage base as PF.

**If left unresolved.** ESI may be applied where it does not apply, or omitted
where it does.

**No recommendation** — it is a factual question about your locations.

**What your CA must provide.** Which locations are in implemented areas, and the
current wage threshold and rates.

**What follows.** ESI moves onto the statutory wage base with the same flag, plus
per-location applicability.

---

## DR-09 — Notice-recovery basis

**Why it matters.** When an employee leaves without serving full notice, the
recovery amount differs greatly by basis.

**Current ZenX behaviour ③.** The notice model already records `waived` and
`reduced` as decisions, and `Fnf_notice_resolver` returns `policy_required`
rather than guessing. The mechanism is built; only the rate is missing.

**Statutory constraint ①.** None sets the basis. GST Circular 178/2022
establishes only that notice-pay recovery is **not subject to GST** and should be
booked as an internal recovery, not a taxable charge — that constrains booking,
not the basis.

**Market evidence ②.** greytHR, Keka, Zoho and Darwinbox all expose it as a
**configurable** component. That every product makes it configurable is itself
evidence that no market default exists.

**If left unresolved.** F&F cannot compute a recovery. Everything else in F&F
can still be built.

**No recommendation.** This is a term of your employment contracts.

**What you must provide.** The basis your appointment letters specify —
basic, gross, or per-day CTC — and whether it varies by grade.

**What follows.** A tenant configuration value consumed by the F&F calculation.

---

## DR-10 — Approval threshold / dual sign-off

**Why it matters.** It is an internal financial control.

**Current ZenX behaviour ③.** Separation of duties is **already implemented and
fails closed** — `Fnf_state_machine` refuses when the approver is the initiator.
Only the monetary **threshold** is missing.

**If left unresolved.** Single-approver behaviour stands, which is safe but may
not match your delegation of authority.

**No recommendation.** Authority, not engineering.

**What you must provide.** Whether dual approval is required above an amount,
and what that amount is.

**What follows.** A threshold value plus a guard in the F&F approval transition.

---

## What is already decided and needs nothing from you

Recorded so the boundary stays auditable. Each was resolved by ① or ③, not by
preference.

| Settled | Basis |
|---|---|
| Leave encashment excluded from the PF base | ① *Manipal Academy* — fails universality |
| Encashable leave is a **per-type** flag, defaulting **off** | ③ `schools.leaveTypes` already models it; defaulting it on would invent a liability |
| Encashment divisor = the tenant's existing `days_basis` | ③ any other divisor makes a leave day worth a different amount than a salary day |
| `available = max(0, allocated + carried − used)` | ③ mirrors the shipped leave engine, clamp included |
| Net pay clamped at ≥ 0, excess deductions recorded as recoverable | ① Payment of Wages s.7(3)/(4) · ③ **already implemented** |
| Cancelled settlements stay terminal | ③ matches the ledger's compensating-correction model |
| Clearance flagged → compute, but block **approval** | ③ computing is not paying |
| Rehire keys off the most recent **non-cancelled** separation | ② established HRMS behaviour |
| Comp-off partial consumption | ③ a half-day leave consumes a half credit |
| Per-day department in payroll | ③ leave and attendance already resolved per day |
| `SALARY_PROCESSED` fires on **full payment** only, if it fires | ③ only full payment is irreversible |
| Unclassified component defaults to **wages** | ① s.2(y) is "all remuneration minus named exclusions" |

---

## What happens next

1. You answer **OWN-1** and **OWN-4**.
2. Your CA answers **CA-1** through **CA-8**.
3. You answer **DR-09** and **DR-10** from your appointment terms and delegation
   of authority.
4. Engineering resumes: configuration is seeded, `USE_STATUTORY_WAGE_BASE` is
   flipped **per tenant**, CA-6 gets its code change, and the F&F calculation is
   completed.

**Until then the system holds.** All four safety flags are `false`, all fixes are
preserved, and the statutory engine is connected to nobody's pay.
