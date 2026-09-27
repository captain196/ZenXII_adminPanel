# PF — market and statutory research

**2026-08-22.** Fresh evidence-based investigation. The previous conclusion was
**not** taken as given, and re-examining it changed the answer materially.

Classification used throughout:

| Tag | Meaning |
|---|---|
| **OR** | Objectively resolved — statute or binding case law settles it |
| **CFG** | Configuration decision — law permits a choice; model it |
| **OWN** | Owner/policy decision |
| **CA** | Requires CA / statutory advisor sign-off |
| **NEE** | Not enough evidence |

---

## 1. What changed versus the previous conclusion

The earlier report concluded "PF should be basic + DA". That is **necessary but
not sufficient**, and stating it alone would have produced a fix that looks
right and misses most of the exposure.

**The EPF base is defined by a test, not by a component list.** In *RPFC (II)
West Bengal v. Vivekananda Vidyamandir* (SC, 28 Feb 2019) the Supreme Court held
that allowances paid **"universally, uniformly, necessarily and ordinarily"** to
all employees are basic wages for PF. The respondent was **an unaided school**
paying a special allowance to teaching and non-teaching staff — ZenX's exact
market, on the exact component ZenX seeds as `other_allowances` with
`pfApplicable = false`.

The same test explains *Manipal Academy* (SC, 2008), which excluded leave
encashment **because** only some employees avail it. One test, two opposite
outcomes — which is what tells you the model must be a classification, not a
formula.

**Consequence.** For a private CTC-style school with no DA component,
"basic + DA" changes nothing at all, while the real exposure — a universally
paid special allowance — remains untouched.

---

## 2. The comparison matrix

Sources: EPFO published material; EPF & MP Act 1952 s.6; Code on Wages 2019
s.2(y); Supreme Court judgments as cited; official vendor documentation.

| Question | Indian law / official source | Asanify | Zoho Payroll | greytHR | Keka | ZenX current | Recommended ZenX | Class |
|---|---|---|---|---|---|---|---|---|
| **PF wage base** | "basic wages, dearness allowance and retaining allowance (if any)" — EPF Act **s.6** | Documented as *"typically 12% of Basic"* (DOC) | Per-component **Wages** classification | Basic + DA | *"12% of basic salary (plus dearness allowances)"* | **Earned basic only** | Classified wage base | **OR** |
| **Universally-paid allowances** | **Are** basic wages — universality test, *Vivekananda Vidyamandir* (2019) | Not established | Expressible via classification | Not established | Not established | **Excluded** | Classify per component | **OR** (test) + **CA** (application) |
| **Leave encashment in PF** | **Not** basic wage — *Manipal Academy* (2008) | Not established | Not established | Not established | Not established | n/a (no F&F) | Per-statute carve-out | **OR** |
| **One wage across PF/ESI/gratuity/bonus/OT** | Codes introduce one definition; ZenX spec §6.3 | Not established | **Yes** — one `Wages` setting that *"replaces the separate EPF and ESI options"* | Not established | Not established | **No** — PF on basic, ESI on gross | One base + carve-outs | **OR** |
| **Component statutory classification** | Spec §6.1 requires `pf_applicable? esi_applicable?` | Per-**employee** `HAS_PF` / `HAS_ESI` | **Per-component, 3 states** | Not established | Not established | Fields exist, **read by nothing** | 3-state classification | **OR** (design) |
| **50% add-back** | s.2(y) first proviso; excess over one-half added back | Not established | **"Included if total wages < 50% of total remuneration"** | Not established | Not established | Implemented, **unwired** | Wire behind flag | **OR** |
| **Add-back percentage** | *"one-half, **or such other percentage as may be notified**"* | — | — | — | — | **Was hard-coded** | **Dated config** (now a parameter) | **CFG** |
| **Remuneration in kind** | Deemed wages up to **15%** of total wages — s.2(y) 2nd proviso | Not established | Not established | Not established | Not established | Not modelled | Model if tenant pays in kind | **CFG** + **OWN** |
| **PF wage ceiling** | ₹15,000/month — EPFO | Guidance keys `HAS_PF` off ₹15,000 | Restrict-to-ceiling supported | Ceiling supported | — | `pf.wageCeilingPaise` ✅ | Keep; seed + CA-mark | **CFG** |
| **Restrict to ceiling vs actual** | Employer election | Not established | **Supported** | **Supported** | — | `pf.applyCeiling` ✅ | Keep | **CFG** |
| **Employee contribution** | 12% — EPFO | — | Configurable | Configurable | 12% | `pf.employeeRate` ✅ | Keep | **CFG** |
| **Employer contribution** | 12% — EPFO | `EMPLOYER_CONTRI_PF` (EPF+EPS combined) | Configurable | Configurable | 12% | `pf.employerRate` ✅ | Keep | **CFG** |
| **EPS split** | 8.33%, capped **₹1,250/month** — EPFO | Combined, not split | Not established | Not established | **3.67% EPF / 8.33% EPS** | **Not modelled** | Split employer PF | **OR** |
| **Above-ceiling joiner** | Only by **Para 26(6)** option; **no EPS** — EPFO | Not established | Not established | Not established | Not established | `eligibleAboveCeiling` flag; EPS consequence absent | Add membership record | **OR** + **CA** |
| **Existing member crossing ceiling** | Continues as member | Not established | Not established | Not established | Not established | **Cannot express** — re-derived each run | Per-employee membership | **OR** |
| **VPF** | Employee-only; **no employer match** — EPFO | Not established | **Supported** | Not established | Not established | Not modelled | Later phase | **CFG** |
| **EDLI / admin charges** | Applicable; spec §6.2 | Not established | Not established | Not established | Not established | Not modelled | Later phase | **NEE** on rates → **CA** |
| **Effective-dated salary** | — | **Effective date + Payout-from** | Not established | Not established | Not established | `[effectiveFrom, effectiveTo)` — no payout-from | Add payout-from with arrears | **CFG** |
| **Gratuity base** | (last drawn basic + DA) × **15/26** × years; cap **₹20,00,000** — Gratuity Act s.4 | Not established | Not established | Not established | Not established | No calculator | Statutory floor, tenant may exceed | **OR** + **OWN** (generosity) |
| **Gratuity eligibility** | 5 years continuous; **waived on death/disablement** — s.4(1) | Not established | Not established | Not established | Not established | No calculator | Statutory floor + override | **OR** |
| **Deduction ceiling** | 50% (75% co-op) — Payment of Wages Act **s.7(3)**; excess still recoverable **s.7(4)** | Not established | Not established | Not established | Not established | **No cap**; net clamped ≥0 with deferral recorded ✅ | Add configurable ceiling | **OR** (cap exists) + **CFG** (which cap) |

---

## 3. Where the market disagrees with itself — and why that matters

Asanify documents PF as *"typically 12% of Basic"*. Keka and greytHR document
basic **+ DA**. Both are describing real products.

They are not actually in conflict: **in most private-sector Indian structures
there is no DA component at all**, so "of Basic" and "of Basic + DA" coincide.
DA is largely a public-sector, PSU and *aided-institution* construct.

**This is exactly why the vendor shorthand is unsafe for ZenX.** ZenX sells to
schools, and its own spec names `PAY_SCALE` — "pay-band + grade-pay +
increment-date for aided institutions and PSU-linked bodies" — as a first-class
structure type. For that segment DA is real, recurring and often large.

**Conclusion: no vendor's default is portable to ZenX's market. The statute is.**

---

## 4. Conclusions by class

### Objectively resolved (implemented or specified)

| Finding | Status |
|---|---|
| PF base is basic + DA + retaining allowance, **plus** universally-paid allowances | Architecture designed; **wiring gated** |
| The add-back is exclusion-list based; unlisted components are wages | **IMPLEMENTED** (W-1) |
| The add-back threshold is notifiable, not constant | **IMPLEMENTED** (W-2) |
| The add-back trigger must not round in the employer's favour | **IMPLEMENTED** (W-3, below) |
| One wage across PF/ESI/gratuity/bonus, with per-statute carve-outs | Designed |
| Employer PF splits into EPS (capped) + EPF residual | Recorded as gap G8 |
| Net pay must never be negative; excess deductions remain recoverable | **ALREADY CORRECT** in ZenX |

### W-3 — a third defect found while implementing

The previous threshold rounded **up** (`intdiv($total,2) + ($total % 2)`), and
its comment claimed this stopped the base being understated. **It did the
opposite.** A higher threshold is harder to exceed, so it yields a *smaller*
add-back and a *smaller* wage base.

On a 3-paise total with a 2-paise excluded pot, `2 > 1.5` is true and the
proviso applies — but rounding the threshold to 2 suppressed the add-back
entirely. An existing test pinned that behaviour under the name
*"An odd total cannot round the boundary in the employer's favour"*, asserting
the exact opposite of its own title.

The trigger is now an exact cross-multiplied integer comparison
(`conditional × den > total × num`) which needs no rounding at all, and the
threshold subtracted is floored so the excess is rounded **up** by at most a
paise — in the employee's favour. The test was inverted, not deleted.

### Configuration decisions (modelled, defaults seeded, CA-marked)

Add-back percentage · PF ceiling · restrict-to-ceiling · employee/employer rates
· ESI rates and threshold · remuneration-in-kind cap · deduction ceiling
(50 vs 75).

### CA sign-off required

Which allowances are universally paid at **this** school · fixed allowance vs
reimbursement of actuals · current-year rates and ceilings · the in-force
add-back percentage · Para 26(6) cases and existing members above ceiling ·
gratuity treatment for service spanning Nov 2025 · ESI applicability by location.

### Owner decisions

Whether to correct the PF base before the first payroll (cost impact) · whether
to engage a CA before go-live · whether any staff are paid partly in kind ·
whether pay-scale/grade-pay structures are used.

### Not enough evidence

Darwinbox behaviour · greytHR per-component EPF applicability · Asanify's
payroll lifecycle, LOP, F&F and payslip behaviour (no video) · EDLI and admin
charge rates.

---

## 5. The answer to the question actually asked

> *"How should ZenX model the statutory wage base and component eligibility so
> that PF is legally correct, configurable where law permits, and
> explainable/auditable?"*

**Three layers, reusing what already exists:**

1. **Per-component classification** — one three-state field
   (`INCLUDE` / `CONDITIONAL` / `EXCLUDE`), not two booleans. Three states are
   required because an excluded allowance is still *remuneration* (it belongs in
   the add-back denominator) whereas a reimbursement is not (it belongs in
   neither). Zoho ships exactly this and retired the two-boolean model.

2. **A canonical, effective-dated wage engine** — `Statutory_wage_base`, now
   exclusion-list based, with a configurable threshold and a per-component
   **trace** so "why is my PF this number?" has an answer. Legally correct by
   construction, because an unclassified component defaults *into* wages.

3. **Base-agnostic calculators** — `Statutory_config_service` already accepts a
   wage and applies tenant rates, ceilings and eligibility. It needs **no
   change**; only its two call sites do, and only behind a flag.

**Explainability** comes from the trace plus a payslip line showing the wage
base when it differs from basic. **Auditability** comes from the classification
being immutable after creation and from the add-back being reportable per
employee per month (the spec's deemed-wages report).

**Configurability** is confined to what the law actually leaves open — rates,
ceilings, the notifiable percentage, and the per-employer classification
question the universality test poses. It is not extended to the definition of
wages itself, which is not the tenant's to choose.
