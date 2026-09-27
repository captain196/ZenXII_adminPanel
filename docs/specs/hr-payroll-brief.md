# Attendance + HR & Payroll — Multi-Vertical Workforce Platform
## Implementation brief for Claude Code (VS Code) — v2

> **How to use this file:** save as `docs/specs/hr-payroll-brief.md`, then in Claude Code say:
> *"Read `docs/specs/hr-payroll-brief.md`. Execute Phase 0 only and produce `docs/audit/hr-payroll-gap-analysis.md`. Write no feature code yet."*
> Work phase by phase. Do not let it attempt Phases 0→6 in one go.

---

## 0. Fill these in before sending

| Field | Value |
|---|---|
| Backend stack / framework | `<fill>` |
| DB + ORM / migration tool | `<fill>` |
| Admin panel stack | `<fill>` |
| Mobile apps (staff app / second app) | `<fill>` |
| Existing HR & Payroll module path(s) | `<fill>` |
| Existing Attendance module path(s) | `<fill>` |
| Multi-tenant isolation strategy | `<fill: shared-schema + tenant_id / schema-per-tenant / db-per-tenant>` |
| Verticals to support at launch | `<fill: e.g. Education + Corporate + Retail; others later>` |
| States we will operate in (PT, LWF, minimum wages) | `<fill>` |
| Largest expected tenant (headcount / locations) | `<fill>` |

---

## 1. Context and product positioning

We operate a **multi-tenant workforce platform**: an admin web panel plus mobile apps, sold per organisation. It began as a school product, but the **Attendance module is now complete and in production, and the HR & Payroll module exists but is incomplete** — critically, payroll does not consume attendance to drive salary.

**The scope has changed. This is no longer a school product.**

The same Attendance + HR & Payroll system must be sellable, unchanged in its core, to:

- **Educational institutions** — schools, colleges, coaching centres, universities
- **Corporates / SMEs / professional services** — offices, IT, agencies, consultancies
- **Private retail** — single stores and multi-store chains, malls, showrooms, franchises
- **Manufacturing / factories / warehouses** — shift labour, contract labour
- **Healthcare** — clinics, hospitals, diagnostics (24×7 rosters)
- **Hospitality / F&B** — hotels, restaurants, cafés
- **NGOs, trusts, societies, clubs**

### The architectural principle that governs everything below

> **A vertical-agnostic core, plus thin, optional, config-driven Vertical Packs.**
>
> Nothing about a school, a shop or a factory may be hard-coded into the core engine. If a rule varies by industry, it becomes **configuration or a pluggable strategy**, never an `if (school)` branch.

The test for every piece of code you write: *could this same engine run payroll for a 12-teacher school in Agra, a 40-person software company in Pune, and a 6-store retail chain in Indore — with only configuration differing?* If not, the abstraction is wrong.

**This is a gap-fill, not a rewrite.** Existing tables, APIs and screens stay unless there is a documented reason to change them; every change ships with a migration and backfill.

---

## 2. Phase 0 — Audit before writing any feature code

Produce `docs/audit/hr-payroll-gap-analysis.md`:

### 2.1 Inventory
For **both** Attendance and HR & Payroll:
- Every table/collection: name, columns, types, indexes, FKs, tenant-scoping column.
- Every API endpoint: method, path, role required, request/response shape.
- Every background job, cron, scheduled task.
- Every admin-panel and mobile screen touching these modules.
- Where business rules live (service layer? controllers? DB triggers? client-side?).

### 2.2 Attendance contract — the most important output
Answer precisely, with file+line references:
1. Atomic unit of an attendance record — per day, per punch, or per period/session?
2. What statuses exist (Present / Absent / Half Day / Late / On Leave / Holiday / Week Off / Missing Punch / Comp Off / On Duty)? Where is the enum defined?
3. Capture modes — biometric device, GPS/geofence, selfie, QR, web, manual admin marking, WhatsApp/kiosk? Is raw punch data retained separately from the derived daily status?
4. Is there a **shift / roster / schedule** concept? Rotational shifts? Night shifts crossing midnight? Split shifts? Grace period? Half-day threshold? Overtime capture?
5. Is there a **Leave module** — types, balances, accrual, carry-forward, encashment, approval workflow, paid/unpaid flag?
6. Holiday calendar per tenant? Weekly-off patterns (fixed, rotational, alternate Saturdays)? Long closure periods?
7. Can past attendance be edited? Versioned? Audited? Is there a **regularisation request** flow?
8. Is there **multi-location** support — can a person be assigned to a branch/store/campus, and can they punch at a different one?

### 2.3 Vertical-coupling audit (new, and important)
Grep the entire codebase for school-specific vocabulary and logic: `school`, `teacher`, `student`, `class`, `section`, `principal`, `period`, `academic_year`, `parent`.

Produce a table: `Occurrence | File:line | Is it core or vertical-specific? | Rename/abstract to | Effort`.

Then answer:
- Are entity names school-bound (`teachers` table vs `employees`)?
- Are enums school-bound (`role: TEACHER | PRINCIPAL`)?
- Is the org hierarchy school-bound (school → class → section) or generic (org → location → department)?
- Are UI labels hard-coded strings or resolved through a label/i18n layer?
- Is "academic year" assumed anywhere payroll would need "financial year"?

**Do not rename anything yet.** Produce the plan; I will approve it.

### 2.4 Payroll inventory
- What exists: salary structure, components, payslip generation, statutory deductions, payroll run entity? Which are stubs?
- Are money amounts stored as integers/decimals or **floats**? Flag every float money column as a defect.
- Is there a payroll **run** that can be locked/finalised, or does it recompute on every view?

### 2.5 Gap table
`Capability | Exists? (Full/Partial/None) | Where | Gap | Effort (S/M/L) | Priority (P0/P1/P2)` — covering every capability in §§4–11.

### 2.6 Risk register
Money-precision bugs, missing tenant scoping (cross-tenant leakage), missing audit trail, mutable history, N+1 or unindexed queries at 2,000 staff × 12 months × 20 locations, timezone/midnight handling, PII exposure.

**Stop after Phase 0. Wait for my review.**

---

## 3. Reference product behaviour

Benchmarked against Asanify-class Indian HRMS. Attendance capture spans multiple channels — mobile app, web, biometric devices, GPS/geofencing, RFID — with policy-driven handling of lateness, early exit, overtime, half-days and shortfalls, and attendance auto-syncs into payroll so salary is computed from actual presence rather than re-keyed. Employee self-service lets people mark attendance, check balances, apply for leave and download payslips without going through HR.

That **attendance → payroll auto-sync** is the feature we are chasing. Everything else supports it.

---

## 4. The vertical abstraction layer (build this in Phase 1 — everything depends on it)

### 4.1 Canonical org model

```
Tenant (Organisation)
  └── Legal Entity          (PAN/TAN/PF/ESI registration lives here — a group may have several)
        └── Location        (branch / store / campus / plant / clinic / office)
              └── Department (or Grade / Cost Centre)
                    └── Employee
```

Rules:
- **`Employee` is the universal person entity.** Teacher, associate, operator, nurse, consultant are all `Employee` rows differentiated by `employee_category` and `designation` — never separate tables.
- **`Location` is the universal site entity.** Campus, store, plant, branch are all Locations. Geofences, shifts, holiday calendars, minimum-wage state and PT state all hang off Location.
- **`Legal Entity`** carries statutory registrations. A retail chain with 8 stores under one company has 1 legal entity, 8 locations. A trust running a school and a college may have 1 entity, 2 locations. A franchise group may have several entities. **Payroll runs and statutory filings are scoped to Legal Entity, not Tenant.** Get this right in Phase 1 or you will re-migrate later.

### 4.2 Org Profile (per tenant, chosen at onboarding)

```
org_profile:
  industry_type: EDUCATION | CORPORATE | RETAIL | MANUFACTURING |
                 HEALTHCARE | HOSPITALITY | NGO | OTHER
  establishment_type: SHOPS_AND_ESTABLISHMENT | FACTORY |
                      EDUCATIONAL_INSTITUTION | OTHER
  workforce_model: FIXED_MONTHLY | SHIFT_BASED | MIXED
  operating_pattern: SINGLE_LOCATION | MULTI_LOCATION
  fiscal_year_start: (default 1 April)
```

`industry_type` **only selects defaults at onboarding** — which policy templates, leave types, salary components, attendance modes and terminology to seed. After onboarding, every one of those is independently editable. It must never be consulted at runtime inside the payroll engine.

`establishment_type` **is** consulted at runtime, but only by the statutory applicability engine (§4.5) — because which labour law applies genuinely depends on it.

### 4.3 Terminology layer

Every user-facing noun resolves through a per-tenant label dictionary, seeded from `industry_type` and editable:

| Canonical key | Education | Corporate | Retail | Manufacturing | Healthcare |
|---|---|---|---|---|---|
| `employee` | Staff | Employee | Associate | Worker | Staff |
| `location` | Campus | Office | Store | Plant | Unit |
| `department` | Department | Team | Section | Shop Floor | Ward |
| `manager` | HOD / Principal | Manager | Store Manager | Supervisor | In-charge |
| `shift` | Session | Shift | Shift | Shift | Duty |

Implement once, in the existing i18n layer. **No hard-coded English nouns in UI or API responses.**

### 4.4 Capability flags (per tenant, per legal entity)

Features switch on and off; nothing is deleted for a vertical that doesn't need it:

```
enable_shift_roster            enable_overtime
enable_geofence                enable_biometric_sync
enable_multi_location          enable_hourly_wages
enable_piece_rate              enable_incentives_commission
enable_contract_labour         enable_substitution_pay
enable_period_timetable        enable_grade_payscale
enable_loans_advances          enable_tds_declarations
enable_grant_in_aid_split      enable_shift_allowance
enable_expense_claims          enable_notice_period_recovery
```

Onboarding a new vertical should mean **flipping flags and loading a template pack** — not shipping code.

### 4.5 Statutory applicability engine

Which laws apply is derived from `establishment_type` + `location.state` + headcount + wage levels, and is evaluated **per legal entity, per period**:

| Statute | Applicability driver |
|---|---|
| EPF | Establishment registered; typically headcount threshold; per-employee wage rules |
| ESI | Registered; wage threshold; **notified district/area** — varies by location |
| Professional Tax | State of the location (some states have none) |
| Labour Welfare Fund | State; periodicity varies |
| Minimum Wages | State + establishment type + **skill category** (unskilled/semi-skilled/skilled/highly-skilled) + zone |
| Overtime | Establishment type; typically a multiple of ordinary wage rate |
| Statutory Bonus | Eligibility wage ceiling; profitability/allocable surplus |
| Gratuity | Service duration; wage definition |
| Shops & Establishment / Factories registers | Establishment type — different prescribed registers and forms |

Model as a **rules table with `effective_from`**, seeded per state and establishment type. Never a `switch (industry)` in the payroll service.

### 4.6 Extension points (mandatory design)

Each of these is an interface with pluggable implementations, resolved per tenant from configuration:

1. `AttendancePolicyStrategy` — raw attendance → daily status
2. `LopCalculationStrategy` — daily statuses → LOP days
3. `EarningsCalculationStrategy` — payable days + structure → gross (monthly / hourly / per-shift / per-period / piece-rate)
4. `StatutoryCalculator` — one per statute, versioned by effective date
5. `PayslipTemplateRenderer` — per tenant branding/format
6. `BankFileFormatter` — per bank
7. `IntegrationAdapter` — biometric devices, accounting, HRIS import

Plus **custom fields** on Employee (per tenant, typed, validated) so verticals can capture what they need — teacher subject specialisation, store associate sales ID, nurse licence number — without schema changes.

---

## 5. The core: Attendance → Payroll bridge

Build as an explicit, inspectable, replayable pipeline. Never arithmetic buried in a payslip view.

### 5.1 Pipeline

```
Raw punches / manual marks / biometric sync / roster
        ↓  (attendance policy engine — exists, do not rewrite)
Daily attendance status per employee per day
        ↓  (Phase 2: NEW)
Attendance Summary  ← immutable per-employee, per-pay-period snapshot
        ↓
Payable units (days / hours / shifts / pieces) + LOP
        ↓  (Phase 3–4: NEW)
Gross earnings → statutory deductions → net pay
        ↓
Payroll Run (draft → locked → approved → paid) → Payslip → Bank file
```

### 5.2 `attendance_summary` — the contract table

Per `(legal_entity_id, employee_id, pay_period_id)`. Frozen when the run locks; payroll then never re-queries raw attendance. This is what makes payroll reproducible.

| Field | Notes |
|---|---|
| `calendar_days` | |
| `week_offs` | fixed or rotational pattern |
| `holidays` | from the location's holiday calendar |
| `scheduled_days` / `scheduled_hours` | from roster, where rostering is enabled |
| `present_days` | |
| `half_days` | |
| `worked_hours` | decimal — for hourly/OT verticals |
| `overtime_hours` | split by `ot_normal` / `ot_holiday` / `ot_night` |
| `night_shift_count` | drives shift allowance |
| `paid_leave_days` | JSON breakdown by leave type |
| `unpaid_leave_days` | |
| `absent_days` | |
| `late_marks`, `early_exits` | counts |
| `on_duty_days` | official duty / field visit / exam duty / training |
| `comp_off_earned` / `comp_off_consumed` | |
| `lop_days` | **the computed output** — decimal(5,2) |
| `payable_days` | decimal(5,2) |
| `units_produced` | piece-rate verticals |
| `variable_units` | JSON — substitution periods, extra shifts, trips, sales units |
| `computation_trace` | JSON — every rule that fired, with inputs and outputs |
| `source_data_hash` | detects drift/tampering vs underlying rows |
| `frozen_at`, `frozen_by` | |

`computation_trace` is non-negotiable. When anyone disputes a payslip — a teacher, a store associate, a machine operator — the admin opens one screen and sees *"3 late marks in the cycle → 1 LOP day, policy `LATE_MARK_3_TO_1`, v2, effective 2026-04-01"*.

### 5.3 LOP rules engine

Per-tenant configurable, stored as a **versioned policy document with `effective_from` / `effective_to`** — changing a policy in October must not retroactively alter September's payroll.

- Half-day → 0.5 LOP
- **Late-mark aggregation** — N late marks = 1 LOP; grace minutes; reset cycle monthly/quarterly
- **Early exit aggregation** — same shape
- **Minimum hours shortfall** → half or full LOP
- **Sandwich rule** — absent before *and* after a holiday/week-off makes the intervening day(s) LOP; toggleable, with an "unapproved absence only" variant
- **Unapproved absence** → full LOP
- **Leave without balance** → converts to LOP
- **Missing punch** → configurable: absent / present-pending-regularisation / block payroll
- **No-show against roster** (shift verticals) → LOP + optional penalty
- **Mid-month joiner / leaver** → pro-rate from DOJ / to LWD; pre-joining days are not LOP
- **Probation vs confirmed** differences
- **Weekly rest entitlement** — flag if an employee worked without a weekly off (compliance risk, not just pay)

### 5.4 Pay basis — support all of these, selected per employee, not per tenant

`payment_model` on the employee's compensation record:

| Model | Formula | Typical vertical |
|---|---|---|
| `MONTHLY_FIXED` | `monthly_gross ÷ days_basis × payable_days` | Schools, corporate, salaried retail |
| `DAILY_WAGE` | `daily_rate × payable_days` | Contract, support staff, casual labour |
| `HOURLY` | `hourly_rate × worked_hours` (+ OT multiple) | Retail part-time, hospitality |
| `PER_SHIFT` | `shift_rate × shifts_worked` | Security, healthcare, F&B |
| `PER_SESSION` | `rate × sessions_taken` | Visiting faculty, trainers, consultants |
| `PIECE_RATE` | `rate × units_produced`, floored at minimum wage | Manufacturing, packing |
| `COMMISSION_LINKED` | base + % of sales/targets | Retail, sales teams |

A single tenant will mix these. A retail chain has salaried managers, hourly associates and commission-linked sales staff **in the same payroll run**. The engine must handle mixed models in one run without branching on tenant type.

For `MONTHLY_FIXED`, support all three `days_basis` options per legal entity:
1. **Calendar days** — `÷ days_in_month`
2. **Fixed 30 days**
3. **Working days** — `÷ (calendar_days − week_offs − holidays)`

Per component, a `prorate_on_lop: bool` flag decides what LOP touches (usually basic, HRA, allowances) and what it doesn't (fixed reimbursements, employer contributions).

**Minimum wage floor check**: after computing gross, compare against the applicable state minimum wage for that skill category and flag (or block) any employee falling below. This is a real liability in retail and manufacturing and is usually missing from generic HRMS builds.

### 5.5 Recompute & drift

Attendance edited **after** the summary is frozen:
- Run still **draft** → recompute, log the delta.
- Run **locked/paid** → never mutate history. Raise an **arrear or recovery adjustment** in the next period, linked to the original run, shown as a labelled payslip line ("Arrear — May attendance regularisation").

First-class flow, not an afterthought.

---

## 6. Payroll engine

### 6.1 Compensation structure
- **CTC → component breakdown** templates, assignable per employee, grade, designation or location.
- Component master: `code, name, type (earning / deduction / employer_contribution / reimbursement / variable), calc_type (fixed / % of basic / % of CTC / formula / slab / attendance-linked), taxable?, pf_applicable?, esi_applicable?, prorate_on_lop?, show_on_payslip?, display_order`.
- **Safe formula evaluator** — whitelisted expression parser, no `eval`.
- **Revisions** with `effective_from`; retro-effective revisions auto-generate arrears across affected past periods.
- **`structure_type: CTC | PAY_SCALE | WAGE_RATE`** — CTC for corporate/private, pay-band + grade-pay + increment-date for aided institutions and PSU-linked bodies, flat wage rates for daily/hourly labour.
- **Variable pay**: incentives, sales commission, target bonuses, tips pooling, shift allowance, night differential, attendance bonus, referral bonus. Support a **variable pay input sheet** per period (imported or API-fed from a sales/POS system) so retail incentives don't need manual keying.

### 6.2 Statutory (India) — table-driven with `effective_from`, never hard-coded

- **EPF** — employee/employer split, EPS, wage ceiling, VPF, admin charges, EDLI; "restrict to ceiling vs actual" toggle per entity.
- **ESI** — rates, wage threshold, contribution-period stickiness, **location-based applicability** (implemented areas).
- **Professional Tax** — state-wise slab tables keyed by state + effective date, including state-specific quirks (e.g. Maharashtra's February slab). Multi-state tenants need PT resolved **per location**, not per tenant.
- **Labour Welfare Fund** — state-wise, varying periodicity.
- **TDS (Sec 192)** — old vs new regime election, projected annual income, declared investments, perquisites, month-wise averaging, recomputation on every earnings change. Form 16 Part B inputs, Form 24Q quarterly data.
- **Gratuity** — accrual/provisioning and exit payout.
- **Statutory bonus** — eligibility ceiling and computation.
- **Overtime** — statutory multiple of the ordinary wage rate, configurable by establishment type and state.

> ⚠️ **Never invent rates, slabs or ceilings from memory.** Every value goes into a seeded, versioned config table with a source-URL comment and a `TODO: verify with CA` marker, surfaced in the phase report.

### 6.3 New Labour Codes — design for this now

India's four labour codes took effect **21 November 2025**, introducing one standardised definition of "wages" across PF, ESI, gratuity, bonus and overtime. Wages = basic + DA + retaining allowance; excluded items include HRA, conveyance, overtime allowance, employer PF contribution, statutory bonus and gratuity — and **where excluded components exceed 50% of total remuneration, the excess is added back into the wage base**. Effectively basic must be ≥50% of CTC, or the system must compute "deemed wages" every month.

This cuts across **every** vertical — it is not a corporate-only concern. Requirements:

- A `statutory_wage_base` computed per employee per period, distinct from `basic`, implementing the add-back.
- All PF / gratuity / bonus / OT calculations consume `statutory_wage_base`, **never** raw `basic`.
- Validation warning when a structure is created with basic < 50% of remuneration.
- A **deemed-wages report** showing the add-back per employee per month.
- **Automated full-and-final settlement** — the codes push hard toward fast exit settlement; F&F must be a workflow, not a spreadsheet.
- Fixed-term employment now attracts benefit parity — relevant to contract-heavy retail and manufacturing tenants.

Gratuity is in transition for service spanning Nov 2025, and state procedural rules were still being notified through 2026. Keep this layer configurable and dated, and maintain `docs/compliance/labour-codes-notes.md` listing what must be re-verified with a CA each financial year.

### 6.4 Payroll run lifecycle

```
DRAFT → attendance frozen → COMPUTED → REVIEWED → LOCKED → APPROVED → PAID → PUBLISHED
                                ↑                                        ↓
                        (recompute allowed)                  (immutable; corrections flow
                                                              as next-period arrears)
```

- Scoped to **legal entity + pay period**; a group tenant runs several in parallel.
- **Idempotent** — identical inputs produce byte-identical output.
- **Pre-lock exception report**: >30% variance vs last period, negative net, zero payable days, below minimum wage, missing bank/PAN/UAN/ESIC, LOP > 15 days, unapproved regularisations pending, roster no-shows. Hard errors block locking.
- **Hold payroll** for an individual (dispute, notice period, absconding) without blocking the run.
- **Off-cycle runs** — F&F, bonus payout, incentive payout, arrears-only.
- **Multi-level approval** with role-gated transitions (HR → Manager/Principal → Finance/Director).
- **Full audit trail** — who computed, who approved, before/after values.

### 6.5 Money handling — hard rules
- Integer minor units (paise) or `DECIMAL(14,2)`. **No floats anywhere.**
- One documented rounding policy; `sum(components) == gross` exactly, residual allocated to a designated component.
- Every computed amount stores its inputs in the trace JSON.

---

## 7. Vertical Packs

Each pack = seed data (policy templates, leave types, salary components, label dictionary, capability flags) + a small number of pluggable strategies. **No pack may add a branch to core code.**

### 7.1 Education Pack
- Teaching vs non-teaching categories with different attendance policies and structures.
- **Period/timetable-linked attendance** — present in the building but absent from assigned periods. Decide and document whether this affects pay (usually not) or only reporting and substitution.
- **Substitution / extra-period pay** — covering an absent colleague's period earns a per-period allowance; feed from the timetable module as a variable earning.
- **Vacation calendars** — teaching staff paid in full during summer/winter break without attendance marking; non-teaching staff still report. Per-category calendar that suppresses LOP.
- **Exam / board duty, invigilation, training, field trips** — paid on-duty with an approval trail.
- **Visiting / guest faculty** on `PER_SESSION`.
- **Grade / pay-scale structures** and **grant-in-aid split** (government-funded portion vs management portion shown separately on the payslip, disbursed via separate bank files) — ask before building; confirm which tenants need it.
- Annual increment on a fixed date (commonly 1 April) with automatic arrears.

### 7.2 Corporate / Professional Services Pack
- WFH / hybrid / flexible-hours attendance policies; work-from-anywhere with IP or geofence checks.
- **Multi-state distributed teams** — PT and LWF resolved per employee's assigned location, not the HQ.
- Comp-off accrual for weekend/holiday work.
- **Notice period** tracking, shortfall recovery, garden leave, absconding handling.
- Variable pay / performance bonus cycles decoupled from monthly payroll (off-cycle runs).
- **Expense claims and reimbursements** with approval workflow, feeding payroll or a separate payout.
- Probation confirmation workflow; appraisal-linked revisions with retro arrears.
- Salary advances / loans with EMI recovery schedules that halt correctly on exit.

### 7.3 Retail Pack (single store to chain)
- **Rotational shift rosters** published per store per week; open shifts, shift swaps with manager approval.
- **Multi-store**: employee home-store assignment plus **cross-store deputation** — attendance punched at Store B must be attributed correctly, and payroll must attribute cost to the right store cost centre.
- **Geofence per store**, with a mall/high-street tolerance radius; selfie punch where devices aren't installed.
- **Part-time and hourly associates** alongside salaried managers in the same run.
- **Night differential and Sunday/holiday working** premiums.
- **Sales incentives and commission** — imported per period from POS/sales sheet, target-slab based, with clawback support.
- **Weekly-off compliance** flag under Shops & Establishment rules.
- **High churn** — bulk onboarding and bulk F&F must be fast; a 30% monthly attrition tenant will break naive flows.
- Store-wise labour cost report and labour-cost-to-sales ratio — the single metric retail owners actually care about.

### 7.4 Manufacturing / Warehouse Pack
- Fixed rotating shifts (A/B/C), shift allowance, night shift, continuous-process handling across midnight.
- **Contract labour** — contractor entity, per-contractor registers, compliance documents, separate payment run; keep contractor workers distinct from direct employees.
- **Piece-rate wages** with a minimum-wage floor guarantee.
- **Overtime at the statutory multiple**, with weekly and quarterly OT-hour caps flagged.
- Canteen / uniform / advance deductions.
- Skill-category-based minimum wage mapping per state and zone.
- Factories-Act-style muster roll, wage register and overtime register outputs.

### 7.5 Healthcare Pack
- 24×7 rosters, on-call and standby allowances, duty-hour caps.
- Doctor/consultant models: retainer, per-session, per-procedure revenue share.
- Licence/registration expiry tracking on the employee record (custom fields).

### 7.6 Hospitality / F&B Pack
- Split shifts, banquet/event-based extra shifts.
- **Tips and service charge pooling and distribution** rules.
- Seasonal and casual staff onboarding.

### 7.7 NGO / Trust Pack
- **Grant/project-wise cost allocation** — one person's salary split across funding projects by percentage, with donor-wise reporting.
- Volunteer vs employee distinction (attendance without payroll).

> Build **Education + Corporate + Retail** first. The rest are data packs on the same engine — deliver them only when a tenant is signed.

---

## 8. Outputs and reports

- **Payslip PDF** — tenant-branded, multilingual (use the platform's existing i18n approach), showing earnings, deductions, employer contributions, LOP days, leave balances, YTD figures. Template pluggable per tenant.
- **Bank disbursement files** — NEFT/RTGS bulk formats (HDFC/ICICI/SBI/Axis differ); pluggable formatter. Support **cash and cheque payment modes** too — small retail and daily-wage tenants still pay in cash and need a signed disbursement sheet.
- **Statutory outputs** — EPF ECR file, ESI contribution file, PT challan summary per state, Form 24Q data, Form 16 Part B inputs, LWF returns.
- **Registers** — wage register, muster roll, LOP register, overtime register, statutory registers by establishment type.
- **Management analytics** — salary cost trend, cost per location / department / project, attendance vs payroll variance, absenteeism heatmap, overtime spend, attrition, labour cost ratio, headcount by category.
- **Accounting export** — journal entry / Tally / Zoho Books / QuickBooks-compatible export, mapped to configurable GL codes. Every business tenant will ask for this in week two.
- Everything exportable to Excel and PDF, everything scoped to tenant and legal entity.

---

## 9. Self-service and roles

**Employee app:** mark attendance, view live attendance summary and LOP **before the run locks**, see the reason for each LOP day, apply for leave, view balances, request regularisation, view roster and request swaps, download payslips and tax statements, submit tax declarations with proof upload, view salary structure, raise a payroll query.

**Manager / supervisor / principal / store manager:** approve leave, regularisations, OT, shift swaps, substitutions; view team attendance and roster; publish rosters.

**HR / admin:** structures, runs, approvals, statutory config, onboarding, exits, reports.

**Finance / owner:** approvals, cost analytics, disbursement.

> **Transparency before lock is the killer feature across every vertical.** Show people their LOP days and the reason for each, several days before the run locks. Most payroll disputes die there — in a school, an office and a shop alike.

---

## 10. Non-functional requirements

- **Tenant + legal-entity isolation** — every query scoped; enforce with a repository guard or row-level security so it cannot be forgotten. Write a test proving cross-tenant reads fail.
- **RBAC** — payroll is the most sensitive data in the platform. Salary visibility strictly scoped (a store manager sees their store only; a teacher never sees another teacher's pay). Test negative cases explicitly.
- **Append-only audit log** on every payroll and structure mutation.
- **Timezone** — store UTC, render IST; handle punches crossing midnight for night shifts correctly.
- **Performance** — a run for 2,000 employees across 20 locations completes in seconds. Batch queries; no N+1 over employees.
- **Offline tolerance** on the mobile app — retail and factory floors have poor connectivity; queue punches and sync.
- **Data retention** — payslips and statutory records for 7+ years.
- **Encryption at rest** — bank account, PAN, Aadhaar, UAN, ESIC.
- **No vertical hard-coding.** A reviewer should be able to grep the payroll engine for `school`, `store`, `factory`, `teacher`, `industry_type` and find zero hits outside configuration resolution.

---

## 11. Tests Claude Code must write (non-negotiable)

1. Full period present → expected net, zero LOP.
2. Mid-period joiner; mid-period leaver; both in the same period.
3. 3 late marks → 1 LOP; 2 → 0; late marks spanning a period boundary.
4. Sandwich rule on/off with a holiday between two absences.
5. Half-day + paid leave + unpaid leave in one period.
6. Leave without balance → LOP conversion.
7. Regularisation approved **after lock** → arrear next period, prior payslip unchanged.
8. Retro-effective salary revision → arrears across N past periods.
9. February (28/29) under all three `days_basis` options.
10. Statutory wage add-back when excluded components exceed 50%.
11. ESI crossing the threshold mid contribution period → contribution continues.
12. Zero payable days → net ≥ 0, statutory handled correctly.
13. Cross-tenant and cross-legal-entity read attempts → denied.
14. Same run computed twice → byte-identical (idempotency).
15. `sum(components) == gross`, `gross − deductions == net`, to the paisa, across 1,000 randomised structures (property test).
16. **Mixed-model run** — one payroll run containing `MONTHLY_FIXED`, `HOURLY`, `PER_SHIFT` and `COMMISSION_LINKED` employees; all compute correctly.
17. **Night shift crossing midnight** attributed to the correct day and period.
18. **Cross-location deputation** — attendance at Store B, cost attributed to Store B, PT applied per the employee's assigned state.
19. **Multi-state tenant** — employees in two states get different PT and LWF in the same run.
20. **Piece-rate below minimum wage** → floor applied and flagged.
21. **Same engine, three verticals** — seeded school, corporate and retail tenants each run a full period end-to-end with only configuration differing. *This is the acceptance test for the whole architecture.*

---

## 12. Delivery phases — do not skip ahead

| Phase | Deliverable | Gate |
|---|---|---|
| **0** | Gap analysis, vertical-coupling audit, risk register (§2) | My review |
| **1** | Canonical org model (legal entity / location / department), employee unification, terminology layer, org profile, capability flags — with migrations and backfill | Existing school tenants run unchanged after migration |
| **2** | `attendance_summary`, freeze job, LOP policy engine, computation trace, admin summary screen | Tests 1–6, 17 |
| **3** | Compensation structures, component master, formula evaluator, all `payment_model`s, revisions and arrears | Tests 7–8, 16 |
| **4** | Statutory engine (PF/ESI/PT/LWF/TDS/OT/minimum wage) as versioned config, applicability engine | Tests 10–12, 19–20; rates externally verified |
| **5** | Payroll run lifecycle, exception report, approvals, payslip, bank/cash outputs, accounting export | End-to-end run, 500-employee seeded tenant |
| **6** | Vertical packs — Education, Corporate, Retail (seed data + strategies only) | **Test 21** |
| **7** | Self-service screens, reports and analytics, remaining packs | — |

Each phase ends with `docs/audit/phase-N-report.md`: what was built, what deviated from this brief and why, what remains open.

---

## 13. Rules of engagement for Claude Code

1. **No vertical hard-coding.** If you are about to write `if (industry_type == ...)` inside the engine, stop and make it configuration or a strategy.
2. **Do not invent statutory rates, slabs or ceilings.** Seed config with source comments and `TODO: verify with CA`; list them in the phase report.
3. **Do not rewrite the attendance module.** Consume it. If its contract is insufficient, propose the minimal additive change and ask first.
4. **Migrations only** — no in-place schema edits; every change has up/down migration and a backfill plan. Existing school tenants must keep working through Phase 1 renaming.
5. **Ask before assuming** on any unspecified business rule — especially sandwich-rule default, `days_basis` default, vacation-pay treatment, grant-in-aid split, tip pooling, commission clawback. Collect them in `docs/audit/open-questions.md` rather than guessing.
6. **No floats for money. No `eval`. No unscoped queries.**
7. Match the repo's existing conventions, naming, error handling and test framework — don't introduce new ones.
8. Every new endpoint: auth check, tenant + legal-entity scope, input validation, audit log entry, test.

---

*Compliance details in §6 reflect the position as of mid-2026 and must be confirmed with a chartered accountant before any tenant runs live payroll. State-level rules under the labour codes were still being notified through 2026, and minimum wage notifications change twice yearly in most states — design for these to be updated as data, never as code.*
