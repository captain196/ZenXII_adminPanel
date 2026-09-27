# D-001 / D-002 / D-005 / D-006 — evidence-based resolution

**Date:** 2026-08-21

## What I actually studied — and one thing I did not

**The 21-minute Asanify video did not reach me.** No attachment, file reference or
transcript arrived in this conversation, and a search of the repo, the scratchpad
and the temp directory found no media or transcript file. I have not seen it, and
nothing below is derived from it.

What I *did* find is better suited to the question anyway: **five Asanify-derived
documents already in the repo**, evidently distilled by an earlier session —

| Document | Lines | Role |
|---|---|---|
| `docs/specs/hr-payroll-brief.md` | 538 | the substantive spec; "benchmarked against Asanify-class Indian HRMS" (line 109) |
| `ZENX_HRMS_ASANIFY_FUNCTIONAL_COVERAGE_MATRIX.md` | 355 | 73-row feature gap matrix vs Asanify |
| `ZENX_HRMS_MASTER_CAPABILITY_REGISTER.md` | 624 | per-capability status |
| `ZENX_HRMS_POLICY_EXECUTION_PLAN.md` | 271 | sequencing |
| `ZENX_HRMS_CAPABILITY_BACKLOG.md` | 140 | backlog |

Everything below cites these. Where they are silent, I say so rather than filling
the gap.

**Two of those documents are stale in ways that matter**, and I would have drawn
wrong conclusions by trusting them:

- Matrix row 30 says comp-off has "**no earn/consume ledger**, and attendance `W`
  does not credit it". Untrue today — `compOffLedger` exists with a `'W'`-gated
  earn, FIFO consumption, per-credit CAS and release-on-failure. Verified this
  session.
- Matrix row 69 says "**no notice-period tracking**". Untrue today —
  `staffNoticePeriods` exists with a full state machine.

**On statutory values specifically**, the brief sets a rule I am deliberately
obeying (line 339):

> ⚠️ **Never invent rates, slabs or ceilings from memory.** Every value goes into
> a seeded, versioned config table with a source-URL comment and a
> `TODO: verify with CA` marker.

So I have not proposed a gratuity divisor, a wage ceiling or a PT slab from recall
— not from my own knowledge and not from vendor marketing pages either. That rule
exists because a secondary source is not an authority for a statutory number. It
is the single most important constraint on what follows.

---

## RESOLVED BY EVIDENCE

### D-006 — salary effective dating: **RESOLVED. It is required.**

I had queued this as a scope decision. It is not — the brief states the governing
principle directly (line 268):

> stored as a **versioned policy document with `effective_from` / `effective_to`**
> — changing a policy in October must not retroactively alter September's payroll.

Salary is the same class of period-sensitive input as the LOP policy that sentence
is about, and §5.5 makes the immutability rule explicit for payroll history
(line 312): a locked or paid run is *never* mutated; corrections become arrears.

Today `salarySlips/{school}_SAL_{staffId}` is a single document with no
`effectiveFrom`, overwritten in place, and both payroll engines read it live —
so regenerating March after an April revision prices March at April's salary.
That is precisely the retroactive alteration the brief forbids.

**Resolution:** compensation joins the effective-dated timeline, exactly as
`department` already has. The architecture already answers "what was true for this
period"; salary simply isn't asked.

**Still yours to decide:** *when*. Employment History Phase 1 is recorded FROZEN,
and this is a real feature (new attribute, writers, backfill, resolver in both
engines). Exposure is currently zero — 0 salary structures, 0 payslips, 0
regenerations across all tenants — so nothing is wrong today. Scheduling is a
product call; the direction no longer is.

### D-002 Q2 — gratuity wage base: **RESOLVED. `statutory_wage_base`.**

Not a choice between basic / basic+DA / gross. The brief §6.3 (line 348):

> All PF / gratuity / bonus / OT calculations consume `statutory_wage_base`,
> **never** raw `basic`.

This follows India's four labour codes, in force since **21 November 2025**
(line 343): wages = basic + DA + retaining allowance, and **where excluded
components exceed 50% of total remuneration the excess is added back**.

**Resolution:** F&F gratuity consumes `statutory_wage_base` per period. My
original option list was wrong to offer the alternatives — the codes removed that
freedom.

**Dependency:** `statutory_wage_base` does not exist in ZenX yet. F&F gratuity
cannot be built before it, and building it is a prerequisite, not part of F&F.

### D-002 Q4 — leave encashment divisor: **RESOLVED. The tenant's existing `days_basis`.**

I had offered 26 / 30 / actual / tenant-defined. The house model is different and
already decided (brief §5.4, lines 299–302) — three options **per legal entity**:

1. calendar days — `÷ days_in_month`
2. fixed 30 days
3. working days — `÷ (calendar_days − week_offs − holidays)`

**26 is not among them.**

**Resolution:** encashment uses the *same* `days_basis` the tenant already uses for
`MONTHLY_FIXED` payroll. Any other divisor would make one day of leave worth a
different amount than one day of salary for the same employee in the same month —
indefensible to an employee and to an auditor. There is no separate decision to
take: the value is already configured.

### D-002 — correction model: **RESOLVED, and it confirms the F&F design.**

Brief §5.5 (line 312):

> Run **locked/paid** → never mutate history. Raise an **arrear or recovery
> adjustment** in the next period, linked to the original run.

This is exactly the rule `Fnf_state_machine` already implements: `paid` and
`closed` are terminal, and a correction after payment is a new compensating
settlement rather than a mutation. The design was arrived at independently and the
brief corroborates it.

### D-002 Q1 / Q7 — gratuity eligibility, statutory treatment: **RESOLVED as to FORM.**

These are config, not code, and not mine to fill in — line 339 above, reinforced
by line 354: gratuity "is in transition for service spanning Nov 2025, and state
procedural rules were still being notified through 2026. Keep this layer
configurable and dated."

**Resolution:** a seeded, versioned, **dated** config table with source URLs and
CA sign-off per financial year. No eligibility threshold or formula constant is
hard-coded, by me or anyone.

### F&F architecture: **RESOLVED. It is an off-cycle payroll run.**

Matrix row 70: "Full & final settlement — **Not present** — needs off-cycle run
(50)". Brief line 369 lists off-cycle runs as "F&F, bonus payout, incentive
payout, arrears-only".

**Resolution:** F&F is not a parallel money system. It is an off-cycle *run type*
over the existing payroll engine. This refines `STAFF_FNF_SETTLEMENT_DESIGN.md`:
`staffSettlements` remains the lifecycle/approval record, but the computation must
execute as an off-cycle run rather than calling the engine ad hoc. Off-cycle runs
(capability 50) are therefore a **hard prerequisite** for F&F (70), which the
matrix already sequences that way.

---

## GENUINELY YOUR DECISION

Each of these is an entitlement or authority choice. Evidence constrains the shape
but cannot supply the answer, and inventing one would be inventing your policy.

### D-001 — may a half-day leave consume half a comp-off credit?

**Evidence for allowing it:** ZenX represents half-days natively everywhere else —
"Half-day → 0.5 LOP" (line 270), `lop_days` and `payable_days` are `decimal(5,2)`
(lines 256–257), leave balances are fraction-safe, attendance `'M'` is 0.5.
Refusing here would make comp-off the *only* place in the lifecycle where a day
cannot be halved.

**Evidence for refusing:** none in the documents either way. And it is not merely
a representation question — a comp-off credit is earned by working a **whole**
rest day (the `'W'` gate), so splitting it is a genuine benefits choice. Some
employers keep comp-off whole-day deliberately, to stop it fragmenting into
untrackable slivers.

**My recommendation:** allow 0.5, for lifecycle consistency — but note it needs a
ledger change (a credit gains a remaining-balance rather than a binary
`available`/`consumed`), which is why the status quo refuses it.

**Exposure: zero.** No comp-off ledger entries exist in any tenant.

### D-002 Q5 — notice-period shortfall recovery basis

The brief names the feature — "**Notice period** tracking, shortfall recovery,
garden leave, absconding handling" (line 398) — but never states the basis.
Recovery on basic, on gross, or on per-day CTC differ by a large multiple, and the
choice is contractual: it is whatever your appointment letters say.

### D-002 Q6 — deduction floor

When recoveries exceed dues, does net clamp to zero, or become a receivable the
school pursues? The brief is silent. This is a debt-collection posture, not an
engineering question.

### D-002 Q8 — approval authority threshold

Separation of duties is already resolved and implemented (actor ≠ subject ≠
initiator). Whether a settlement above some amount needs a second approver, and
what that amount is, is your governance.

### D-002 Q10 — clearance override

May a settlement compute while a department is `flagged`? The machine supports an
explicit, audited override; whether anyone may exercise it is policy.

### D-005 — the terminal state for an abandoned notice after rehire

**Evidence narrows this to one answer.** The house rule is that a record must not
misstate history (§5.5). Of the three existing terminal states, `rejected` (HR
didn't), `withdrawn` (the employee didn't) and `completed` (the separation didn't)
are each factually false of a separation abandoned by rehiring. The two sibling
records — clearance and onboarding — both supersede a terminal record freely, and
the brief expects non-happy-path terminals ("absconding handling", line 398).

**Recommendation: add a `superseded` state**, set when a rehire occurs while a
notice is non-terminal.

**Why I am still asking:** it adds a state to a state machine this programme
treats as locked, and you told me not to choose a new lifecycle state silently.
The engineering is otherwise complete and the deadlock is proven and pinned.

**Exposure: zero.** No notice-period records exist in any tenant.

---

## What I did not do

I did not fetch Asanify's public documentation. Two reasons: the in-repo brief has
already benchmarked against it (line 109) and is more specific to ZenX than vendor
pages would be; and for the one class of fact where an external source might have
helped — statutory rates and formulas — the brief's own rule forbids taking them
from any secondary source, mine or a vendor's, in favour of CA verification. Say
the word if you want the vendor docs pulled anyway and I will.

I did not implement anything from this document. Every resolution above is a
decision record, not a code change.
