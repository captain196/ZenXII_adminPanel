# Staff Lifecycle — PF statutory research & architecture report

**2026-08-22 · research and architecture only · no production code changed.**

Two files were added: a strictly read-only census script (`scripts/pf_statutory_census.js`)
and one test that pins existing architectural facts
(`tests/Unit/StatutoryClassificationReachTest.php`). Nothing else in the working tree moved.
Nothing committed, pushed, deployed, migrated, or flag-flipped.

---

## 1. Executive conclusion

**The question "should PF be basic, or basic + DA?" is the wrong question, and answering
it would build the wrong thing.**

The research produced three findings that change the shape of the answer:

1. **Under the EPF Act the base is not a component list at all — it is a test.** The
   Supreme Court in *Vivekananda Vidyamandir* (2019) held that allowances paid
   "universally, uniformly, necessarily and ordinarily" to all employees **are** basic
   wages for PF. The respondent in that case was **an unaided school** paying a special
   allowance to teaching and non-teaching staff. That is ZenX's exact market. So a
   school's "other allowance" is very often PF wages, and no hard-coded component list
   can decide that — only per-component classification can.

2. **The same universality test also explains the leave-encashment carve-out.** *Manipal
   Academy* (2008) excluded leave encashment from basic wages precisely because some
   employees avail it and some do not — it fails universality. One test explains both
   cases, which means the model is a classification model, not a formula model.

3. **The market has already moved, and moved past what ZenX has.** Zoho Payroll now
   documents a single per-component **Wages** classification which explicitly *"replaces
   the separate EPF and ESI options that were available earlier"*, with three states:
   do not include / always include / **"Included if total wages < 50% of total
   remuneration (Conditionally Excluded)"**. ZenX's separate `pfApplicable` +
   `esiApplicable` booleans are the model Zoho retired.

**Recommended architecture: option D** — per-component statutory classification feeding a
canonical, effective-dated statutory wage engine, which in turn feeds PF, ESI, gratuity,
bonus and leave-encashment calculators. This is also what ZenX's own governing spec
(`docs/specs/hr-payroll-brief.md` §6.1 and §6.3) already requires, so it is not a new
direction — it is the direction the codebase was already pointed at and stopped short of.

**Two defects were found in the existing, unwired `Statutory_wage_base`** that would have
shipped had it simply been switched on (§12). Both understate liability. Both are latent.

**The census was re-verified and is emptier than previously reported.** Nine tenants;
zero salary structures, zero payslips, zero runs, zero assignments, zero catalogue rows,
zero payroll journals, zero locked months, zero statutory configs, and zero schools with
`salaryDefaults`. **There is no historical payroll to migrate and nothing to correct.**
This is the cheapest possible moment to fix the architecture, and that will not be true
again.

---

## 2. Current ZenX PF behaviour

`Hr::_compute_payslip()` derives six earning components and sums all six into gross:

```
basic, hra, da, ta, medical, other_allowances     →  gross
```

PF is then computed on **earned basic alone**, in both branches:

```php
// statutory-aware
$pf  = Statutory_config_service::computePf($statutory, $effectiveBasicP);
$esi = Statutory_config_service::computeEsi($statutory, $grossP);

// legacy
$pfEmployeeP = self::_applyRatio($effectiveBasicP, $pfPct / 100);
$pfEmployerP = self::_applyRatio($effectiveBasicP, $sal['pf_employer'] / 100);
```

So: **PF on basic, ESI on gross.** That is the pre-codes model. Under the codes both
should derive from one wage base.

`Statutory_config_service::computePf()` is **base-agnostic** — it accepts
`(array $cfg, int $wagePaise)` and applies the tenant's rates, ceiling, and
eligibility-above-ceiling toggle to whatever wage it is handed. Verified by reading it
and asserted by test. **The base is chosen entirely by the caller**, which is why
remediation is small.

---

## 3. Current ZenX statutory architecture

More exists than a naive audit would assume, and it is well-built. What is missing is
the wiring between the layers, not the layers.

| Layer | File | State |
|---|---|---|
| Component master | `Salary_component_service.php` | **Exists.** Carries `kind`, `calcType`, `basis`, `statutory` (PF/ESI/PT/TDS), `taxable`, **`pfApplicable`**, **`esiApplicable`**, `prorated`. Flags are in `LOCKED_FIELDS` — immutable after creation. |
| Effective-dated assignment | `Salary_assignment_service.php` | **Exists.** `resolve(staffId, date)`, balancing split, draft-not-payable. |
| Reader / seam | `Compensation_reader.php` | **Exists.** Three modes (legacy / shadow / component). |
| Statutory calculators | `Statutory_config_service.php` | **Exists.** PF, ESI, PT slabs, TDS — table-driven, base-agnostic, ceiling + eligibility aware. |
| Labour-codes wage base | `Statutory_wage_base.php` | **Exists, wired to nothing** (asserted by test). |
| Compliance register | `docs/compliance/labour-codes-notes.md` | **MISSING.** Required by spec §6.3. `docs/compliance/` does not exist. |

### The break in the chain

This is the crux of the whole report, and it is confirmed by execution, not inference:

**`pfApplicable` and `esiApplicable` are captured in the compensation UI, validated,
stored on every catalogue row — and read by nothing in the calculation path.**
`grep` finds them in `Compensation.php` (write), `views/compensation/index.php` (write),
and the component service (schema). They appear **nowhere** in `Hr.php`.

The chain breaks in two documented places:

1. `Compensation_reader::resolveFromComponents()` maps each assignment line onto one of
   six `LEGACY_FIELDS` via `COMPONENT_TO_FIELD`, and **drops any component with no legacy
   counterpart** — its own log line says the component *"will not reach the calculator
   until the component-aware payslip gate."*
2. `_compute_payslip()` therefore receives six rupee fields, never a classified set.

So even after `USE_COMPONENT_COMPENSATION` is flipped, the calculator still sees the
legacy six and still computes PF on basic. **The cutover flag alone does not fix PF.**
That is a materially important correction to the previous report's framing.

### The seed agrees with the engine

`Salary_component_service` seeds thirteen components that *describe* the shipping engine:

```php
$earning('DA', 'Dearness Allowance', 30, false),                  // false = NOT pfApplicable
$pct('PF_EMPLOYEE', 'Provident Fund (Employee)', 'deduction', 'BASIC', 'PF', 110),
```

and `SalaryComponentMasterTest` pins it: *"PF is charged on basic only, not {$code}"*.

**Engine, seed and test agree.** They are coherently wrong together, which is far better
than being incoherently wrong — but it means a fix must move all three, plus the test,
in one change.

---

## 4. Asanify findings

> **The previously supplied 21-minute Asanify video is not available in this
> environment.** No video, transcript, or accessible source for it could be obtained.
> Everything below is based only on accessible Asanify documentation. No video findings
> are claimed.

| Aspect | Finding | Class |
|---|---|---|
| Salary components | Basic % and HRA % set per employee; plus **Custom Earnings** and **Custom Deductions** defined at company level | B |
| Statutory components | PF, PT, ESI, TDS built in; *"get auto-calculated when you run payroll"* | B |
| PF eligibility | Per-employee **`HAS_PF`** flag (TRUE/FALSE), with the documented note *"Should be TRUE for Basic <= Rs. 15,000"* | C |
| ESI eligibility | Per-employee **`HAS_ESI`** flag, same pattern | C |
| PF base | `EMPLOYER_CONTRI_PF` *"includes both EPF and EPS. (Typically 12% of Basic)"* | B/D |
| Effective dating | **Two distinct dates**: *"Effective date — the date from which the revised CTC comes into play"* and *"Payout From — the day from which the new salary would be paid from"* | B |
| Salary revision | Revise by increase-% or by entering revised CTC | B |
| F&F / payslips | Not established from accessible documentation | — |

**Reading.** Asanify's model is *per-employee eligibility flags* + *percentage-driven
structure*, with PF documented as "typically 12% of Basic". The separation of **effective
date** from **payout-from date** is the notable design idea — it is how arrears are
expressed without rewriting history, and ZenX's `[effectiveFrom, effectiveTo)` assignment
model can express the first but has no equivalent of the second.

**Caution.** "Typically 12% of Basic" is vendor documentation describing common practice,
**not a statement of law**, and it is safe only where DA is zero — which is true for most
private-sector employers and *not* true for the aided-school and pay-scale employers
ZenX explicitly targets (spec §6.1: `structure_type: CTC | PAY_SCALE | WAGE_RATE`,
"pay-band + grade-pay + increment-date for aided institutions").

---

## 5. greytHR findings

| Aspect | Finding | Class |
|---|---|---|
| PF base | PF calculated on **Basic + DA** up to the ₹15,000 ceiling | B |
| Configuration location | Settings → Statutory Components → PF Configuration | B |
| Notice-pay recovery | Exposed as a configurable F&F component, not a fixed formula | C |
| F&F | Dedicated Final Settlement screen under Payroll → Payroll Inputs | B |

Per-component EPF-applicability configuration could not be established from accessible
greytHR documentation. Recorded as **not established** rather than assumed.

---

## 6. Zoho Payroll findings — the decisive evidence

From official Zoho Payroll help (Settings → Salary Components):

> *"This setting decides whether the earning counts toward **Wages**. It **replaces the
> separate EPF and ESI options** that were available earlier."*

Three mutually exclusive per-component states:

1. **"Do not include this earning in Wages"**
2. **"Always include this earning in Wages"**
3. **"Included if total wages < 50% of total remuneration (Conditionally Excluded)"**

Other per-component flags: taxable, part of salary structure (CTC), show on payslip,
calculate on pro-rata basis, Flexible Benefit Plan component, scheduled earning.

Zoho additionally supports: restrict contribution to the ₹15,000 statutory wage ceiling
(capping employee contribution at ₹1,800/month), PF on full basic, and voluntary PF.

**Why this matters more than any other vendor finding.** Zoho has implemented the labour
codes as a **component classification with a conditional third state**, and has
**collapsed EPF and ESI into one wage concept** — because the codes define one wage
across PF, ESI, gratuity, bonus and overtime. This is independent confirmation of the
target architecture, from a shipping product, and it is exactly what ZenX's own spec §6.3
demands. It also tells us ZenX's two-boolean model is the previous generation.

Zoho's documentation does **not** publish default classifications for Basic / HRA /
conveyance / special allowance. It gives guidance only: "always include" for core wages,
"conditionally excluded" for adjustable allowances, "do not include" for non-wage
components. **So the defaults are the employer's (and their CA's) call, not the vendor's**
— which is itself an important design signal for ZenX.

---

## 7. Keka findings

| Aspect | Finding | Class |
|---|---|---|
| PF base | *"12% of basic salary (plus dearness allowances)"* | B |
| Employer split | **3.67% to EPF, 8.33% to EPS** | A/B |
| Enablement | PF toggled on per establishment; filing details required once enabled | B |
| Setup shape | Four steps: pay schedule → contributions and deductions → salary structure → salary components | B |

Keka's documented base is **basic + DA**, which agrees with the EPF Act and disagrees
with Asanify's "typically 12% of Basic" shorthand.

---

## 8. Darwinbox findings

**Not established.** No authoritative Darwinbox documentation on per-component PF
applicability, wage classification, or labour-codes handling could be obtained in this
environment. Recorded as a gap rather than filled with inference.

---

## 9. Statutory / EPFO findings

Every value below carries its source. **None is hard-coded anywhere as a result of this
report** — all belong in effective-dated config with a `TODO: verify with CA` marker,
per spec §6.2.

### EPF Act 1952

| Item | Position | Source |
|---|---|---|
| Contribution base | *"the basic wages, dearness allowance and retaining allowance (if any)"* | EPF & MP Act 1952, **s.6** |
| DA definition | Deemed to include the cash value of any food concession | s.6 explanation |
| Retaining allowance | Allowance payable to retain services while the establishment is not working | s.6 explanation |
| **Universality test** | Allowances paid **universally, uniformly, necessarily and ordinarily** to all employees **are** basic wages | *RPFC (II) West Bengal v. Vivekananda Vidyamandir* (SC, 28 Feb 2019) |
| Leave encashment | **Not** basic wage — fails universality (some avail, some do not) | *Manipal Academy of Higher Education v. PF Commissioner* (SC, 2008) |
| Wage ceiling | ₹15,000/month | EPFO |
| Employee contribution | 12% | EPFO |
| Employer contribution | 12%, split **8.33% to EPS capped at ₹1,250/month**, balance to EPF | EPFO |
| Mandatory coverage | Employees drawing basic wage ≤ ₹15,000 | EPFO |
| Above ceiling | May join only by option under **Para 26(6)** of the EPF Scheme; **no EPS membership** — both shares go to PF | EPFO |
| VPF | Employee may contribute more; **employer is not required to match** | EPFO |

### Code on Wages 2019 — s.2(y)

**Includes:** basic pay, dearness allowance, retaining allowance — as part of "all
remuneration ... expressed in terms of money or capable of being so expressed".

**Excludes** (clauses (a)–(i) as reported by the sources consulted): bonus; value of
housing accommodation; employer contributions to pension/provident fund; conveyance
allowance and travelling concessions; sums paid to defray special expenses; house rent
allowance; overtime allowance; commission; gratuity, retrenchment compensation, other
retirement benefits and ex-gratia.

**First proviso — the add-back:**
> if payments under the excluded clauses exceed **one-half, or such other percentage as
> may be notified by the Central Government**, of all remuneration, the excess is added
> back into wages.

**Second proviso — remuneration in kind:** remuneration in kind not exceeding **15%** of
total wages is deemed part of wages.

**Commencement:** the four codes took effect **21 November 2025**.

### Two consequences that are easy to miss

- **"or such other percentage as may be notified"** — 50% is a *default that the Central
  Government may change by notification*. It is therefore a **dated configuration value**,
  not a constant. ZenX currently hard-codes it.
- **The structure is exclusion-list, not inclusion-list.** Wages = all remuneration minus
  named exclusions. A component in neither list is **wages**. ZenX models it the other
  way round (§12).

---

## 10. Component classification matrix

Classes: **A** statutory requirement · **B** vendor implementation · **C** vendor
configuration option · **D** vendor recommendation · **E** inference.

Confidence is about *the classification of that component in a given school*, not about
the statute.

| Component | In gross? | Statutory wage (codes)? | PF wage? | ESI wage? | Gratuity base? | Subject to add-back? | Configurable? | Source | Class | Confidence |
|---|---|---|---|---|---|---|---|---|---|---|
| **Basic** | Yes | **Yes** | **Yes** | Yes | **Yes** | n/a (is wages) | No | s.2(y); EPF s.6; Gratuity Act s.4 | **A** | High |
| **Dearness allowance** | Yes | **Yes** | **Yes** | Yes | **Yes** | n/a (is wages) | No | s.2(y); EPF s.6 (named) | **A** | High |
| **Retaining allowance** | Yes | **Yes** | **Yes** | Yes | **Yes** | n/a (is wages) | No | s.2(y); EPF s.6 (named) | **A** | High |
| **HRA** | Yes | No (excluded) | No* | Yes | No | **Yes** — counts toward the excluded pot | Should not be | s.2(y) excl. | **A** | High |
| **Conveyance / TA** | Yes | No (excluded) | No* | Yes | No | **Yes** | Should not be | s.2(y) excl. | **A** | High |
| **Overtime allowance** | Yes | No (excluded) | No | Yes | No | **Yes** | No | s.2(y) excl. | **A** | High |
| **Commission** | Yes | No (excluded) | No | Varies | No | **Yes** | No | s.2(y) excl. | **A** | Medium |
| **Statutory bonus** | Yes | No (excluded) | No | No | No | **Yes** | No | s.2(y) excl. | **A** | High |
| **Employer PF contribution** | No | No (excluded) | No | No | No | **Yes** | No | s.2(y) excl. | **A** | High |
| **Gratuity** | No | No (excluded) | No | No | n/a | **Yes** | No | s.2(y) excl. | **A** | High |
| **Special / other allowance** | Yes | **Yes if universal** | **Yes if universal** | Yes | **Yes if universal** | Only if genuinely excluded | **Yes — must be** | *Vivekananda Vidyamandir* | **A** | **High that the test applies; per-school determination required** |
| **Medical allowance (fixed)** | Yes | Likely wages | Likely, if universal | Yes | Likely | — | **Yes — must be** | Universality test | **A/E** | **Medium — CA call** |
| **Medical reimbursement (actuals)** | No | No — "sums to defray special expenses" | No | No | No | Yes | Yes | s.2(y)(e) | **A** | Medium |
| **Leave encashment** | Payout | — | **No** | — | n/a | — | No | *Manipal Academy* | **A** | High |
| **Remuneration in kind** | — | Yes up to **15%** of total wages | Follows wages | — | Follows | — | Yes | s.2(y) 2nd proviso | **A** | Medium |

\* HRA and conveyance are excluded *by name*, but if the add-back triggers, part of the
excess re-enters the wage base. The exclusion is from the base, not from the 50% test.

**The single most important row is "Special / other allowance."** It is the residual
bucket in almost every Indian salary structure, ZenX seeds it as `other_allowances` with
`pfApplicable = false`, and *Vivekananda Vidyamandir* — a school — says that is wrong
whenever the allowance is universally paid. It cannot be resolved by a default; it must
be classified per component per tenant, with CA confirmation.

---

## 11. PF architecture comparison

| | **A. Hard-coded formula** | **B. Configurable formula** | **C. Canonical wage engine** | **D. Component classification → wage engine → calculators** |
|---|---|---|---|---|
| Market alignment | None | Asanify-ish | Partial | **Zoho (shipping); ZenX spec §6.1+§6.3** |
| Satisfies universality test | No | No | No | **Yes** |
| Satisfies s.2(y) add-back | No | Poorly | Yes | **Yes** |
| One wage for PF/ESI/gratuity/bonus | No | No | **Yes** | **Yes** |
| Per-statute carve-outs (e.g. Manipal) | No | Hack | Hard | **Yes** |
| Effective dating | No | Partial | Yes | **Yes** |
| Auditability (why *this* number?) | None | Weak | Good | **Best — per-component trace** |
| Survives a notified % change | No | No | **Yes if dated** | **Yes** |
| Tenant configuration | No | Some | Some | **Yes** |
| Reproducible historical payroll | No | Risky | Yes | **Yes** |
| Reuses what ZenX already has | — | — | Partly | **Almost entirely** |
| New concepts to introduce | 0 | 1 | 2 | **1** (classification reaching the engine) |

**A** is what exists. **B** is Asanify's documented shape and would encode "12% of Basic"
as configuration — it would not survive a school with DA, and it cannot express
universality. **C** alone (wire `Statutory_wage_base` to a fixed component list) is what
the previous report implied and is the trap: it hard-codes the very classification the
case law says must be per-component. **D** is the recommendation.

---

## 12. 50% wage-rule analysis — and two defects in the existing implementation

The rule is **not** "basic + DA + 50%". Precisely:

1. Compute **total remuneration** (all remuneration).
2. Compute the **excluded pot** — the s.2(y)(a)–(i) components.
3. If excluded pot > **50%** (or the notified percentage) of total remuneration, the
   **excess over that half** is added back into wages.
4. Wages = included components + add-back.

`Statutory_wage_base::compute()` implements steps 3 and 4 correctly, and its
`intdiv($total, 2) + ($total % 2)` ceiling is exactly right — it never understates by a
paise. But two things are wrong:

### Defect W-1 — inclusion-list model silently shrinks the denominator

```php
const INCLUDED = ['basic', 'da', 'retaining_allowance'];
const EXCLUDED = ['hra', 'conveyance', 'ta', 'medical', 'other_allowances', 'overtime_allowance'];
...
$total = $included + $excluded;
```

`total` is the sum of **two named lists**. A component in neither — a tenant-defined
`SPECIAL_ALLOWANCE`, a new statutory component, anything the catalogue allows — is
weighted **zero** in the wage base *and* zero in the total remuneration the 50% test is
measured against.

Under s.2(y) wages are *all remuneration minus named exclusions*, so an unlisted
component should default to **included**. The current model defaults it to **invisible**,
which shrinks the denominator and therefore **under-triggers the add-back**. It fails in
the direction that reduces statutory liability. Asserted by test; not fixed.

### Defect W-2 — the threshold is a literal, but the statute makes it notifiable

The first proviso says *"one-half, **or such other percentage as may be notified by the
Central Government**"*. The 50% is therefore a dated configuration value belonging
alongside rates and ceilings in `Statutory_config_service`, not a constant in a pure
function. Asserted by test; not fixed.

### Does the rule apply identically across statutes?

**Mostly yes, and that is the point of the codes** — one wage definition across PF, ESI,
gratuity, bonus and overtime (spec §6.3 states this; Zoho's collapse of EPF+ESI into one
`Wages` setting is the market acting on it).

**But not entirely**, and the exceptions must be modelled:

- **Leave encashment / PF** — excluded per *Manipal*, on universality grounds.
- **Gratuity's divisor** — 15/26 is specific to the Gratuity Act and must not be routed
  through the tenant's `days_basis` (which offers calendar / fixed-30 / working days, and
  **not** 26).
- **ESI** has its own wage threshold and contribution-period stickiness, and
  location-based applicability (spec §6.2).
- **Gratuity is in transition** for service spanning Nov 2025, and state procedural rules
  were still being notified through 2026 (spec §6.3).

So: one wage base, **plus** per-statute carve-out hooks. Not one number used five times.

---

## 13. PF ceiling analysis

| Item | Value | Source | ZenX today |
|---|---|---|---|
| Statutory wage ceiling | ₹15,000/month | EPFO | `pf.wageCeilingPaise`, tenant-configured |
| Restrict-to-ceiling toggle | Employer choice | EPFO / spec §6.2 | `pf.applyCeiling` — **implemented** |
| Contribute above ceiling | Permitted by option | Para 26(6) | `pf.eligibleAboveCeiling` — **implemented** |
| Employee contribution | 12% | EPFO | `pf.employeeRate` |
| Employer contribution | 12% | EPFO | `pf.employerRate` |
| EPS split | 8.33%, **capped ₹1,250/month** | EPFO | **NOT MODELLED** |
| EPF residual | 3.67% | Keka / EPFO | **NOT MODELLED** |
| Admin charges / EDLI | Applicable | spec §6.2 | **NOT MODELLED** |

`computePf()` already does the right thing with ceiling and eligibility:

```php
if ($ceiling > 0 && $wagePaise > $ceiling && !$eligibleAbove) return not-covered;
$base = ($apply && $ceiling > 0) ? min($wagePaise, $ceiling) : $wagePaise;
```

**Gap: the employer's 12% is returned as one number.** EPS (8.33%, capped ₹1,250) versus
EPF residual is not split. That matters for ECR filing and for accounting posting, and it
interacts with eligibility — an employee who joins above the ceiling under Para 26(6) gets
**no EPS**, so the whole 12% goes to PF. Modelling employer PF as a single figure cannot
express that.

**No rate or ceiling above has been written into code by this report.**

---

## 14. PF eligibility analysis

Eligibility is **per employee**, not per tenant — Asanify's `HAS_PF` flag and Keka's
per-establishment toggle plus employee coverage both reflect this.

| Rule | Position | ZenX |
|---|---|---|
| Basic wage ≤ ₹15,000 at joining | Mandatory coverage | Inferred from wage vs ceiling |
| Above ceiling at joining | Only by Para 26(6) option; **no EPS** | `eligibleAboveCeiling` flag exists; EPS consequence not modelled |
| Existing member who crosses ceiling | Continues as member | **Not modelled** — ZenX has no membership state, only a wage comparison |
| International workers | Different rules | Not modelled |
| Excluded employees | Defined by scheme | Not modelled |
| VPF | Employee-only, no employer match | **Not modelled** |

**The structural gap: ZenX has no per-employee PF membership record.** It re-derives
coverage each run from wage versus ceiling. That cannot express "joined below the ceiling,
now earns above it, remains a member" — which is the single most common real-world case.
This is a **data-model** gap, not a calculation gap, and it is worth naming now because
adding a membership record later, after payslips exist, is far more expensive.

---

## 15. ZenX gaps

| # | Gap | Severity | Latent? |
|---|---|---|---|
| G1 | PF base excludes DA (EPF Act s.6 names it) | **High** | Yes — 0 payslips |
| G2 | PF base excludes universally-paid allowances (*Vidyamandir*) | **High** | Yes |
| G3 | `pfApplicable` / `esiApplicable` reach nothing | **High** | Yes |
| G4 | Component reader flattens to 6 legacy fields; unmapped components dropped | **High** | Yes |
| G5 | `Statutory_wage_base` inclusion-list model shrinks the 50% denominator (W-1) | **High** | Yes — unwired |
| G6 | 50% threshold hard-coded though notifiable (W-2) | Medium | Yes |
| G7 | ESI on gross, not on the codes' wage base | Medium | Yes |
| G8 | Employer PF not split into EPS / EPF residual; ₹1,250 cap absent | Medium | Yes |
| G9 | No per-employee PF membership record | Medium | Yes |
| G10 | No deemed-wages report (spec §6.3 requires one) | Medium | Yes |
| G11 | No "basic < 50% of remuneration" validation warning (spec §6.3) | Low | Yes |
| G12 | `docs/compliance/labour-codes-notes.md` missing (spec §6.3) | Low | n/a |
| G13 | Gratuity/bonus/leave-encashment calculators do not exist yet | — | By design |

Every calculation gap is latent. **Zero payslips exist in any tenant.**

---

## 16. Recommended target architecture

**Option D**, in three layers, reusing what exists.

```
salaryComponents (catalogue)
  └─ wageClassification: INCLUDE | EXCLUDE | CONDITIONAL     ← replaces two booleans
  └─ per-statute overrides (only where law diverges)
        │
        ▼
Statutory_wage_base  (canonical, effective-dated, exclusion-list semantics)
  └─ returns: wageBasePaise, totalRemunerationPaise, excludedPaise,
              addBackPaise, addBackApplied, perComponentTrace
        │
        ▼
Statutory_config_service  (unchanged, already base-agnostic)
  ├─ computePf(cfg, wageBasePaise)      ← the ONLY change at this seam
  ├─ computeEsi(cfg, wageBasePaise)
  └─ later: gratuity, bonus, leave encashment
```

**Why one `wageClassification` enum instead of ZenX's two booleans.** The codes define
one wage across PF, ESI, gratuity, bonus and overtime; Zoho collapsed its separate EPF and
ESI options for exactly this reason. Two independent booleans cannot express the
**conditional** state the add-back requires, and keeping them invites PF and ESI to drift
apart with no statutory basis for the divergence. Per-statute overrides then carry the
genuine exceptions (leave encashment / PF), which is a short, evidence-backed list rather
than a free-for-all.

**Why not extend `Statutory_wage_base` in place with more component names.** That is
option C, and it re-hard-codes the classification the case law says is a per-component,
per-employer determination.

---

## 17. Exact minimum implementation changes

Deliberately minimal. Each is small; the sequencing is what matters.

1. **`Statutory_wage_base` → exclusion-list semantics.** Accept a classified component
   set. Unknown/unclassified ⇒ **included** (fail-safe). Emit a per-component trace.
2. **Threshold from config.** Take the add-back percentage from effective-dated statutory
   config; keep 50% as the seeded default with a source comment and `TODO: verify with CA`.
3. **Catalogue: add `wageClassification`.** Derive it once from the existing
   `pfApplicable`/`esiApplicable` for continuity; keep the old fields readable until the
   cutover completes. Add to `LOCKED_FIELDS`.
4. **Reader: stop flattening.** `resolveFromComponents()` must return the classified
   component set alongside the legacy six, so nothing breaks while the calculator learns
   to read it. This is the "component-aware payslip gate" the code already names.
5. **Engine: one line each.** `computePf($statutory, $wageBase)` and
   `computeEsi($statutory, $wageBase)` — replacing `$effectiveBasicP` and `$grossP`.
   Gate both behind the new flag.
6. **Split employer PF** into EPS (capped) and EPF residual.
7. **Re-seed** the thirteen components with correct classifications, and **invert
   `SalaryComponentMasterTest`** so it asserts the new model.

Not in the minimum: gratuity/bonus/LE calculators, PF membership records, VPF, admin
charges/EDLI. Each is separately justified and separately sequenced.

---

## 18. Required configuration

Per tenant, per legal entity, **effective-dated**, every value sourced and CA-marked:

- add-back percentage (default 50%), remuneration-in-kind cap (15%)
- PF: wage ceiling, employee/employer rates, apply-ceiling, eligible-above-ceiling,
  EPS rate + monthly cap, EPF residual, admin charges, EDLI
- ESI: rates, wage threshold, contribution-period stickiness, location applicability
- per-component `wageClassification` and any per-statute override

---

## 19. Required UI

- **Component editor:** replace the two PF/ESI checkboxes with one three-state wage
  classification, each option labelled in the statute's own language.
- **Structure builder:** live warning when basic < 50% of remuneration (spec §6.3), shown
  at entry rather than discovered on a payslip.
- **Payslip:** show the statutory wage base as a distinct line when it differs from basic,
  with the add-back visible. Silently paying PF on a number the employee cannot see is how
  disputes start.
- **Statutory config screen:** dated rows with sources.

---

## 20. Required accounting / reporting changes

- **Deemed-wages report** — add-back per employee per month (spec §6.3). Currently absent.
- **Employer PF must post as two lines** (EPS, EPF) once split — today one account.
- **ECR export** needs the split and the wage base.
- Existing payroll journals are unaffected: **zero exist.**

---

## 21. Cross-platform impact

**None required now, and that is worth stating precisely.**

- **Teacher app** reads payslips as stored documents; adding a `statutoryWageBase` field
  is additive and ignored by existing readers. No app change is *required* — though a
  payslip that shows PF without showing its base is a support question waiting to happen,
  so a display update is *desirable* once the field exists.
- **Parent app** has no payroll surface.
- **Firestore rules** — `salaryComponents` and `statutoryConfig` blocks already exist;
  adding a field needs no rule change. No new collection is proposed.
- **Indexes** — no new query shapes.

---

## 22. Historical-data / cutover impact

Re-verified by read-only census on 2026-08-22 (`scripts/pf_statutory_census.js`, proven
to contain zero write calls before it was run):

| Metric | Count |
|---|---|
| Tenants | 9 |
| Salary structures | **0** |
| …with `da` populated | **0** |
| Payslips | **0** |
| Payroll runs | **0** |
| Effective-dated assignments | **0** |
| Catalogue rows | **0** |
| Payroll accounting journals | **0** |
| Locked payroll months | **0** |
| `statutoryConfig` docs | **0** |
| Schools with `salaryDefaults` | **0** |

**Would enabling the new engine change historical payroll? No — there is none.**

There is no migration, no backfill, no recomputation, no arrears, no reversal, and no
employee whose contribution was understated. Every finding in this report is latent in the
strongest sense: not merely unexercised, but with no data on which it *could* have been
exercised.

**This is the cheapest possible moment to make this change, and it will never be this
cheap again.** The first tenant to create a salary structure ends it.

---

## 23. Testing strategy

1. **Statutory fixtures from published examples only** — never invented figures. Where a
   worked example cannot be sourced, assert *properties* (monotonicity, the add-back never
   decreasing the base, ceiling clamping) rather than magic numbers.
2. **Property tests on the wage engine** — an unclassified component must never reduce the
   wage base; add-back is monotonic in the excluded pot; `intdiv` ceiling never understates.
3. **The universality cases as named fixtures** — a school paying a universal special
   allowance (Vidyamandir shape) and a leave-encashment payout (Manipal shape), each
   asserting the opposite outcome, with the citation in the docblock.
4. **Real-engine execution**, via the existing `tests/Support/PayrollEngine.php` harness —
   not a mirror. The mirror is already known to drift by a paise.
5. **Parity harness** comparing legacy and new bases over generated structures, reporting
   the delta per component — the evidence an owner and CA actually need.
6. **Invert, don't delete** — `SalaryComponentMasterTest`'s "PF is charged on basic only"
   becomes an assertion of the new classification. `StatutoryClassificationReachTest`
   (added today) is expected to fail on the day the cutover lands; that is its job.

---

## 24. Shadow-mode strategy

`Compensation_reader` already has `MODE_SHADOW`, and its documented trap applies here too:
**switching a shadow on against empty collections produces a meaningless green.**

Recommended sequence, and the reason each step exists:

1. **Offline parity harness first** — synthesise representative school structures
   (private CTC-style with no DA; aided pay-scale with DA and grade pay; structure with a
   large universal special allowance) and report old vs new PF per structure. Needs no
   tenant data, which is the point: there is none.
2. **Statutory shadow in payroll** — compute both bases, log the divergence, **pay the
   legacy figure.** Same discipline as the compensation shadow.
3. **Per-tenant enable**, never global.
4. **Production** only after a CA has signed the classification for that tenant.

The statutory shadow must be a **separate flag** from `USE_COMPONENT_COMPENSATION`. They
are independent concerns and coupling them would force two risky cutovers into one.

---

## 25. CA / statutory advisor decisions

| # | Question for the CA | Why | Evidence found | What depends on it |
|---|---|---|---|---|
| CA-1 | For this school, which allowances are paid **universally, uniformly, necessarily and ordinarily** to all staff? | *Vidyamandir* makes this the test for PF wages, and it is a facts-of-this-employer question | SC 2019, respondent was an unaided school | `wageClassification` per component |
| CA-2 | Is the medical/other allowance a **fixed allowance** or a **reimbursement of actuals**? | Reimbursements fall under s.2(y)(e) "sums to defray special expenses"; fixed allowances generally do not | s.2(y) exclusion list | Classification of `medical`, `other_allowances` |
| CA-3 | Confirm PF rates, ₹15,000 ceiling, EPS 8.33% / ₹1,250 cap for the current year | Spec §6.2 forbids values from memory | EPFO | Seeded statutory config |
| CA-4 | Confirm the add-back percentage currently in force | The proviso allows the Centre to notify another percentage | s.2(y) first proviso | Wage-engine threshold config |
| CA-5 | Does this establishment apply restrict-to-ceiling, or contribute on actual wages? | Employer election | EPFO / Zoho / greytHR expose it as a toggle | `pf.applyCeiling` |
| CA-6 | Any employees under Para 26(6), or existing members now above the ceiling? | Determines EPS treatment; ZenX has no membership record | Para 26(6) | Whether G9 must be fixed before go-live |
| CA-7 | Gratuity treatment for service spanning Nov 2025 | The codes left this in transition | spec §6.3 | Gratuity calculator |
| CA-8 | ESI applicability for this location | Location-based | spec §6.2 | ESI enablement |

---

## 26. School-owner decisions

Deliberately short. **None of these are technical.**

| # | Question | Why it is the owner's |
|---|---|---|
| OWN-1 | Do you want PF computed on the correct statutory base from the first payroll, accepting higher employer cost — or do you want to see the cost impact first? | It is a cost and compliance-posture decision. The recommendation is to fix before the first run, because there is no data to migrate today. |
| OWN-2 | Will you engage a CA to sign the component classification before the first payroll? | CA-1 and CA-2 cannot be answered by ZenX or by the owner alone. |
| OWN-3 | Are any staff paid partly **in kind** (accommodation, meals)? | Triggers the 15% second proviso and the housing-accommodation exclusion. |
| OWN-4 | Do you operate pay-scale / grade-pay structures (aided-institution style) as well as CTC? | Decides whether DA is a live component. This is what makes the defect real rather than theoretical for ZenX's market. |

---

## 27. Engineering decisions Claude can make autonomously

Once implementation is authorised, and **only then**:

- exclusion-list semantics with fail-safe "unknown ⇒ included"
- the shape of the per-component trace and payslip storage
- deriving `wageClassification` from the existing booleans for continuity
- keeping `Statutory_config_service` base-agnostic (no change to it)
- returning the classified set alongside the legacy six so nothing breaks mid-cutover
- a separate statutory flag, independent of `USE_COMPONENT_COMPENSATION`
- test structure, fixtures, parity harness, and inverting the affected tests
- paise-exact arithmetic and rounding, following the existing one-rounding rule

**Not autonomous:** any rate, ceiling, threshold, percentage or classification default; any
flag flip; anything that changes a figure an employee sees.

---

## 28. What must remain disabled

- `USE_COMPONENT_COMPENSATION` — **false**
- `COMPENSATION_SHADOW_COMPARE` — **false** (would compare against empty collections)
- `Statutory_wage_base` — **wired to nothing**, and asserted so by test
- the proposed statutory-wage flag — **does not exist yet; must default false**
- no seeded rates, ceilings or classifications treated as authoritative until CA-confirmed

---

## 29. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Fixing the base raises employer cost and take-home falls | High (commercial) | Parity harness quantifies it per structure **before** any flip; owner decision OWN-1 |
| Misclassifying an allowance under-contributes → EPFO liability + damages | High | CA-1/CA-2 sign-off; fail-safe default is *include* |
| Over-classifying over-contributes → employee complaint | Medium | Same classification review; shadow mode shows the delta |
| Half-wired engine applies to some payslips only | High | Single flag, whole-tenant, plus the existing "wired to nothing" test |
| Hard-coded 50% goes stale on a notification | Medium | W-2: move to dated config |
| Inclusion-list model under-triggers add-back | **High** | W-1: exclusion-list semantics |
| Coupling this to the component cutover | Medium | Separate flags, separate gates |
| `firestore.rules` shared with other programmes | Medium | No rules change proposed here |
| Acting on this report's statutory summaries as if they were advice | High | Every value is sourced; all are CA-marked; none written into code |

---

## 30. Final recommendation

**Adopt option D.** Per-component statutory classification → canonical, effective-dated
statutory wage engine → PF/ESI/gratuity/bonus calculators. It is what the Supreme Court's
universality test requires, what the Code on Wages structure implies, what a shipping
competitor has already built, and what ZenX's own governing spec has required since it was
written.

**Do it now, before the first salary structure exists.** The census is not merely "0
payslips" — it is zero of everything, across all nine tenants. There is no migration to
plan, no arrears to compute, no journal to reverse and no employee to make whole. That
changes permanently the day a school creates its first salary structure.

**Do not simply wire `Statutory_wage_base` to a fixed component list.** It carries two
defects that would ship with it (W-1 inclusion-list denominator, W-2 hard-coded
threshold), and more fundamentally it would hard-code exactly the determination that
*Vivekananda Vidyamandir* — a case about a school — says depends on how that particular
employer pays its staff.

**And do not stop at "basic + DA".** For a private CTC-style school with no DA component,
"basic + DA" changes nothing at all while the real exposure — a universally-paid special
allowance — remains untouched. It would look like a fix and would not be one.

### Immediate next steps, in order

1. Owner answers **OWN-1** and **OWN-4**.
2. CA engaged for **CA-1** through **CA-5**.
3. Build the **offline parity harness** — no tenant data needed, quantifies the cost.
4. Only then: authorise implementation, in the §17 sequence, behind a new flag defaulting
   to false.

**Nothing implemented. Nothing enabled. Nothing committed, pushed or deployed.**
