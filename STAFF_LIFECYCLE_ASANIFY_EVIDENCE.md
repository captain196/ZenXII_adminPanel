# Asanify evidence register

**2026-08-22.** Evidence classification for every Asanify observation used
anywhere in the Staff Lifecycle programme.

---

## 0. The video

**The Asanify product-demo video did not reach this environment.**

Checked before writing anything: a recursive search of the repository for
`*.mp4 / *.mov / *.mkv / *.webm / *.avi / *.vtt / *.srt / *transcript*` returns
only ZenX's own `demo_build/clips/*.webm` marketing clips. A search for anything
named `*asanify*` returns exactly one file — `ZENX_HRMS_ASANIFY_FUNCTIONAL_COVERAGE_MATRIX.md`
— and that document states its own provenance in its header:

> **Benchmark source:** the already-completed Asanify analysis and the agreed
> capability tree … **Not re-derived.**

It contains **zero** timestamps and zero video references (`grep -c` for
timestamp patterns returns 0). It is therefore a record of a prior capability
comparison, not video evidence.

**Consequently, every item in the requested Phase A list that can only be
established by watching the product demo is classified below as:**

> **C — NOT ESTABLISHED BY THE VIDEO.** Video unavailable in this environment;
> conclusion based only on accessible Asanify documentation/evidence.

No timestamps, screens or UI observations are reported anywhere in this
programme, because none were obtained. Nothing has been inferred from "what
HRMS products usually do" and presented as an Asanify finding.

If the video is supplied in a form this environment can read — a file path in
the repository, or a published transcript URL — Phase A can be completed
against it directly.

---

## 1. Classification key

| Class | Meaning |
|---|---|
| **A** | Directly shown in the video |
| **B** | Inferred from Asanify UI/workflow |
| **C** | Not established by the video |
| **DOC** | Established from Asanify's own published documentation (help centre / product pages) |

**No observation in this programme carries class A or B**, because no video was
available. Everything Asanify-derived is DOC or C.

---

## 2. The requested Phase A list, answered honestly

| # | Topic | Class | What IS established |
|---|---|---|---|
| 1 | Employee / People architecture | **C** | — |
| 2 | Employee 360 | **C** | — |
| 3 | Salary structure | **DOC** | Per-employee **Basic %** and **HRA %**, plus optional and custom components |
| 4 | Salary components | **DOC** | Company-level **Custom Earnings** and **Custom Deductions**; built-in PF, PT, ESI, TDS |
| 5 | Salary revision | **DOC** | Revise by increase-% or by entering a revised CTC |
| 6 | Effective-dated salary | **DOC** | Two distinct dates: **"Effective date — the date from which the revised CTC comes into play"** and **"Payout From — the day from which the new salary would be paid from"** |
| 7 | PF configuration | **DOC** | Per-employee **`HAS_PF`** (TRUE/FALSE) with the note *"Should be TRUE for Basic <= Rs. 15,000"* |
| 8 | PF component/base treatment | **DOC** | `EMPLOYER_CONTRI_PF` *"includes both EPF and EPS. (Typically 12% of Basic)"* |
| 9 | Employer PF / employee PF / EPS | **DOC (partial)** | Employer figure is documented as covering EPF **and** EPS together; the split itself is not published |
| 10 | Statutory calculations | **DOC** | *"Standard payroll deductions such as Professional Tax, Provident Fund and TDS get auto-calculated when you run payroll"* |
| 11 | Payroll run lifecycle | **C** | — |
| 12 | Payroll review / approval / confirmation | **C** | — |
| 13 | Payroll warnings / pre-flight validation | **C** | — |
| 14 | LOP integration | **C** | — |
| 15 | Payslip generation | **C** | — |
| 16 | Leave | **C** | — |
| 17 | Attendance | **C** | — |
| 18 | Investments / tax declarations | **C** | — |
| 19 | Expenses | **C** | — |
| 20 | Requests / approvals | **C** | — |
| 21 | Recruitment → candidate → offer → employee | **C** | — |
| 22 | Onboarding / lifecycle | **C** | — |
| 23 | Tasks / checklists | **C** | — |
| 24 | Reporting / dashboard behaviour | **C** | — |
| 25 | ESI eligibility | **DOC** | Per-employee **`HAS_ESI`** flag, same pattern as `HAS_PF` |

---

## 3. The two Asanify findings that actually influenced the architecture

Only two DOC-class observations carried any weight, and both are recorded with
their limits.

### 3.1 Effective date is separate from payout-from date

Asanify distinguishes *when a revision takes effect* from *when it starts being
paid*. That is how arrears are expressed without rewriting a closed period.

**Relevance to ZenX.** ZenX's `[effectiveFrom, effectiveTo)` assignment model
expresses the first cleanly and has **no equivalent of the second**. That is a
genuine modelling gap, recorded in the decision register — not copied, because
ZenX has no arrears mechanism to attach it to yet.

### 3.2 PF eligibility is a per-employee flag, and the documented base is "Basic"

`HAS_PF` is per employee, not per tenant. Asanify's guidance keys it off basic
against the ₹15,000 ceiling, and `EMPLOYER_CONTRI_PF` is documented as
*"Typically 12% of Basic"*.

**Relevance to ZenX — and the limit of it.** This is **vendor documentation
describing common practice, not a statement of law.** It is safe only where DA
is zero, which is true of most private-sector CTC employers and **not** true of
the aided-institution and pay-scale employers ZenX explicitly targets
(`docs/specs/hr-payroll-brief.md` §6.1 names `PAY_SCALE` as a first-class
structure type "for aided institutions and PSU-linked bodies").

Section 6 of the EPF & MP Act 1952 names dearness allowance in the contribution
base. **Where Asanify's documented shorthand and the statute diverge, the
statute governs.** Asanify is market evidence; it is not legal authority.

The per-employee eligibility flag, by contrast, IS a useful structural
observation: it confirms that PF membership is an attribute of a person, not of
a tenant — which is exactly the gap recorded as G9 (ZenX re-derives coverage
each run from wage-versus-ceiling and has no membership record).

---

## 4. What was deliberately NOT concluded

Recorded so the boundary is auditable:

- **No claim** that Asanify implements per-component statutory classification.
  Its published documentation describes per-employee flags and percentage-driven
  structures. Zoho Payroll is the vendor for which per-component wage
  classification IS documented — see
  `STAFF_PAYROLL_PF_MARKET_AND_STATUTORY_RESEARCH.md`.
- **No claim** about Asanify's payroll lifecycle, approval stages, LOP handling,
  pre-flight validation, F&F or payslip generation.
- **No claim** that ZenX should adopt any Asanify behaviour because Asanify does
  it. Every architectural conclusion in this programme rests on statute, on case
  law, or on ZenX's own governing spec — with market evidence used only to
  confirm that a design is buildable and conventional.
