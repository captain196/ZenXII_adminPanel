# ZenX — School / Institution Lifecycle
## Deep Research, Competitive Benchmark, Gap Analysis & Future-State Architecture

**Status:** RESEARCH ONLY — Phase 0. No production code, no Firestore rules, no indexes, no RBAC,
no collections, no migrations were changed by this work.
**Date:** 2026-08-21 · **Branch context:** `integration/phase2` · **Author:** Architecture research pass
**Decision required:** approve/reject the recommendation in §28 before ANY implementation.

---

## 1. Executive Summary

**Verdict: (B) Yes — but as a thin orchestration / state layer, NOT a standalone module.**

The single most important finding of this research is that **ZenX already has two of the three
lifecycles it needs, and both are good** — but it is missing the third, and it is currently
*overloading one of the existing two* to do the missing one's job.

| Axis | Exists in ZenX today? | Where |
|---|---|---|
| **Commercial / tenant lifecycle** (did they pay, are they entitled) | ✅ Yes, mature | `schoolControl.lifecycle.state` ∈ {trialing, active, expiring_soon, grace, past_due, suspended, expired}; `B2_registry_service`; enforced in `firestore.rules::tenantActive()` and the `MY_Controller` bootstrap |
| **Academic session lifecycle** (year planning → rollover → archive) | ✅ Yes, mature | `schools.sessions[] / currentSession / archivedSessions[]`; `Session_lifecycle.php`; `School_config::rollover_session / archive_session`; session freeze + period lock in `MY_Controller` |
| **Institution lifecycle** (does this school exist, is it ready, has it closed) | ❌ **No** | *Nothing.* No institution state, no readiness model, no closure workflow, no archival workflow, no institutional identity beyond a name + affiliation string |

Today, "this school is suspended" is a **billing** verdict (`lifecycle.state = 'suspended'`, set by
`Superadmin_plans` when grace expires, `Superadmin_plans.php:451`). There is **no way to express**
"this institution has closed", "this institution is being onboarded and is not ready to operate", or
"this institution's records are retained for statutory audit but the institution no longer operates."

That conflation is the architectural defect. A school that closes in March and a school that missed
an invoice reach the **identical** system state, with identical side effects and identical (absent)
audit semantics. At 10 tenants that is tolerable. At 500 tenants across multiple states it becomes a
data-retention, statutory-audit and customer-trust problem.

**Recommendation in one sentence:** add a **second, orthogonal, thin axis** — `institutionState` —
with six states, a readiness *scorer* (not a workflow engine), a real closure & archival procedure,
and one access resolver that composes the two axes; and do **not** build a workflow engine, an event
bus, a campus hierarchy, a second configuration store, or any AI.

**Top 10 concrete gaps found (detail in §20):**

| # | Gap | Severity | Evidence |
|---|---|---|---|
| G1 | No institution state axis; closure ≡ non-payment | **High** | `B2_registry_service::ACCESS_LIFECYCLE_STATES`; `Superadmin_schools.php:602` allows only `active/inactive/suspended` |
| G2 | **Scheduled jobs do not check tenant state at all** | **High** | 11 cron/job controllers; only `B2_analytics_rollup_job` + `B2_lifecycle_job` reference lifecycle. `Fees_late_fee_cron`, `Backup_cron`, `Campus_access_cron` and all 4 Transport crons = 0 references |
| G3 | **Parent & Teacher apps have zero lifecycle awareness** | **High** | `grep tenantPublic\|lifecycleState` over 254 Parent + 249 Teacher `.kt` files → **0 hits**, despite `firestore.rules:48-50` documenting "clients SUBSCRIBE to tenantPublic … see H2/H3 reactive logout" |
| G4 | No closure / archival workflow of any kind | **High** | no `close`/`archive`/`purge` function in `Superadmin_schools.php` or `School_backup.php` |
| G5 | SA lifecycle audit writes to **RTDB** | **High** | `MY_Superadmin_Controller::sa_log()` → `firebase->push('System/Logs/Activity/…')` — violates the ZERO-RTDB hard line and is not lifecycle-grade |
| G6 | Institution identity fields largely absent (UDISE, school category, management type, legal entity, recognition validity, medium, geo/timezone, DPO/grievance contacts) | Medium | `School_config::save_profile()` allow-list at `School_config.php:787` |
| G7 | No readiness model — a tenant is "live" the instant the SA wizard finishes | Medium | `Superadmin_schools::onboard` writes tenant + SSA and stops |
| G8 | No two-person control and no captured reason on suspend; a single POST flips it | Medium | `Superadmin_schools.php:600-617` |
| G9 | No campus/branch concept and no reserved field for one | Medium (future) | `schools/{schoolId}` has no `orgId`/`campusId` |
| G10 | Backend service-account writes bypass rules by design — a "suspended" tenant still accepts admin-panel writes | Medium (intentional, but undocumented per module) | `firestore.rules:44-46` |

---

## 2. What "School Lifecycle" Actually Means

The term is used by vendors to mean at least eight different things. They must not be merged.

| # | Lifecycle | Subject | Cardinality | Owns |
|---|---|---|---|---|
| 1 | **Institution lifecycle** | The school as a legal/operational entity | 1 per school, spans decades | Existence, recognition, operational status, closure, archival |
| 2 | **Academic session lifecycle** | The academic year | N per school, ~1/yr, repeating | Planning, enrolment window, teaching, exams, results, promotion, closure, archive |
| 3 | **Student lifecycle** | A person | N per session | Enquiry → admission → enrolled → promoted → TC/withdrawn → alumni |
| 4 | **Staff lifecycle** | A person | N | Recruit → onboard → active → transfer → separation → F&F → ex-employee |
| 5 | **Campus / branch lifecycle** | A physical site | 0..N per institution | Site opening, capacity, closure, site merge |
| 6 | **ERP tenant lifecycle** | A paying customer account | 1 per tenant | Trial, subscribe, renew, grace, suspend, offboard |
| 7 | **Onboarding / implementation lifecycle** | A *project* | 1 per tenant, finite | Config, migration, validation, pilot, go-live |
| 8 | **Closure / archival lifecycle** | A *procedure* | 0..1 per institution | Freeze, resolve, export, retain, restrict |

**Critical distinction #1:** (1) and (6) are different subjects that happen to share an ID in a
one-tenant-per-school SaaS. A school can exist and operate while its ERP subscription lapses (they
still teach children); a tenant can exist commercially before the school opens. ZenX today has only
(6) and calls it (1).

**Critical distinction #2:** (7) and (8) are **procedures with a terminal state**, not persistent
states. Mature systems model them as checklists/jobs attached to a state, not as first-class states.
Modelling every checklist step as a lifecycle state (`CONFIGURING`, `MIGRATING`, `VALIDATING`,
`PILOT`) is the most common over-engineering failure in this space — it produces a 12-state machine
in which 6 states carry no distinct authorization semantics (see §22).

---

## 3. Is It Used in Real ERPs? — the honest answer

**Institution lifecycle as a *dedicated module*: rare.**
**Institution lifecycle as a *distributed set of capabilities*: universal.**

No mainstream K-12 product ships a menu item called "School Lifecycle". What they ship instead:

- an **institution/school registry** with an active/inactive flag (PowerSchool `Schools/School Info`,
  district-scoped; Gibbon "School Structure"),
- an **academic-year engine** with explicit statuses (Gibbon: exactly one **Current**, plus any number
  of **Past** and **Upcoming** years, each with a sequence number — a real, documented state machine),
- an **implementation/onboarding programme** run as a *project*, not as software (vendors advertise
  go-live from "same day" to "2–6 weeks"),
- and **archival by year, not by school** — rollover archives the year; the school row simply persists.

The strongest evidence that institution lifecycle is real comes not from ERP vendors but from
**regulators**, where it is mandatory and explicitly state-machined:

- **UDISE+ (MoE, India)** requires every school to carry a directory status, and requires **closure,
  merger and upgrade** to be reflected in the School Profile; the status change is made by the
  **District MIS Officer**, not the school — a two-actor, approval-gated transition. A merged school's
  DCF must be explicitly *closed* and teacher data explicitly merged. This is an institution lifecycle
  running in production across ~1.5M schools.
- **CBSE Affiliation Bye-Laws** define a genuine multi-state institutional lifecycle: provisional
  affiliation → extension → regular → (on deficiency) *revert to provisional* → withdrawal in a
  subject or in all subjects → disaffiliation; plus a **closure procedure** requiring a Society/Trust
  resolution, state government approval, an affidavit to retain infrastructure and teachers until
  classes IX–XII complete board exams, and a 3-year bar on re-application.
- **Azure Architecture Center — Tenant Life Cycle** (the canonical SaaS reference) names precisely the
  stages ZenX lacks: trial, onboarding, moving tenants, **merge and split tenants**,
  **deactivate/reactivate** ("intended to be a temporary state"), and **offboarding** with an explicit
  **retention period**, re-onboarding decision, and legally-mandated destruction timelines.

**Conclusion:** the concept is genuine, but its mature expression is (a) a registry + status,
(b) a year engine, (c) an offboarding/retention procedure, and (d) a compliance identity record. It is
**not** a workflow engine and **not** a new top-level module.

---

## 4. Market / ERP Research

**PowerSchool SIS (K-12, US, district model).** The hierarchy is District → School. The year
transition is a heavyweight, explicitly irreversible batch: EOY requires next-year setup at district
and school level, a valid school-year term in *all* schools, FTEs for every grade level, a full backup
and export, a dedicated **Perform EOY Validation** pass, and up to 4 hours of uninterrupted server
time ("This process is irreversible"). Students move *between schools* via a `Next School` indicator
(Next Grade Level 99 + Next School 999999 = graduate). Fee handling at rollover is an explicit
three-way operator choice: roll over balances, roll over unpaid fee details, or zero all balances.
Notably the EOY documentation says nothing about marking a school inactive — school existence is a
*registry* concern, not part of the year engine. **That separation is correct, and ZenX already
mirrors it.**

**Gibbon (open-source, international K-12).** Documented year statuses: exactly one **Current**, N
**Past**, N **Upcoming**, ordered by sequence number. Rollover is a guided tool with a "Getting Ready"
step, and the docs warn there is **no undo** without a database backup. Institution identity lives in
"School Structure" — a *configuration* surface, not a lifecycle.

**ERPNext / Frappe Education (open-source).** `Education Settings` holds institute defaults;
`Academic Year` (start/end dates) contains N `Academic Term`s; a *current* year and *current* term act
as system-wide defaults. There is **no institution state machine**; the institution is a Company
record from ERPNext core, which does carry genuine multi-company (multi-legal-entity) support.

**Indian K-12 ERP field (Entab CampusCare, Fedena, Teachmint, Vidyalaya, edumerge).** Publicly
verifiable capability is thinner than the marketing implies; treat vendor claims as claims. What is
credibly evidenced: multi-campus/group scale is a real marketed capability (edumerge markets
single-school → ~50-campus groups with multi-campus billing); per-school modular activation is
standard (Fedena); board-documentation depth is the Entab differentiator; physical-world integrations
(biometric, RFID, Tally) are the Vidyalaya differentiator. **No Indian vendor publicly documents a
school closure/archival state machine** — an unclaimed differentiator, and exactly the thing UDISE+
and CBSE force schools to handle manually today.

**Higher-ed (Ellucian Banner).** A different market, but instructive modelling: institution/campus are
*validation-table codes*, and terms are codes with their own dated open/close ranges — institutional
identity as reference data, lifecycle expressed through **dated records** rather than status flags.
**Dated records beat status flags for history**; ZenX should absorb this (§16).

**SaaS reference architecture (Azure).** Deactivation ≠ offboarding. Retention period is a first-class
design input. Re-onboarding must be an explicit decision. Merge/split must be considered up front
because "customers do not remain static."

---

## 5. Competitive Comparison Matrix

Legend: ✅ verified in primary docs · ⚠️ partial / configuration-only · ❌ absent or undocumented ·
📣 vendor marketing claim only · 🆕 proposed for ZenX

| Capability | PowerSchool SIS | Gibbon | ERPNext Education | Indian K-12 field | UDISE+ / CBSE (regulator) | **ZenX today** |
|---|---|---|---|---|---|---|
| **Institution foundation** | | | | | | |
| School registry record | ✅ | ✅ | ✅ (Company) | ✅ | ✅ | ✅ `schools/{schoolId}` |
| Unique school code | ✅ | ✅ | ✅ | ✅ | ✅ UDISE code (statutory) | ✅ `schoolCode` + `SCH_XXXXXX` |
| Legal entity (trust/society/company) | ⚠️ | ❌ | ✅ | ⚠️ | ✅ mandatory (CBSE) | ❌ |
| Board affiliation + affiliation no. | ⚠️ | ❌ | ❌ | ✅ | ✅ | ✅ `affiliationBoard` / `affiliationNo` |
| Recognition validity / renewal dates | ❌ | ❌ | ❌ | ⚠️ | ✅ core to CBSE | ❌ |
| School category, medium, management type | ⚠️ | ⚠️ | ❌ | ✅ | ✅ mandatory (UDISE) | ❌ |
| Establishment info, branding, contacts | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ logo, principal, est. year, address |
| **Organization structure** | | | | | | |
| District / group / trust above school | ✅ | ❌ | ✅ | 📣 | ✅ | ❌ |
| Campus / branch below school | ❌ | ⚠️ | ❌ | 📣 | ✅ | ❌ |
| Departments / academic units | ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ HR depts, `staffRoles` |
| **Onboarding** | | | | | | |
| Guided school creation wizard | ⚠️ | ✅ | ✅ | 📣 | n/a | ✅ 4-step SA wizard |
| Configuration checklist / readiness score | ❌ | ⚠️ | ❌ | 📣 | ⚠️ DCF completeness | ❌ |
| Data migration tooling | ✅ | ✅ | ✅ | ✅ | ✅ bulk DCF | ⚠️ `scripts/` one-offs + SIS import |
| Pre-go-live validation gate | ✅ EOY validation | ⚠️ | ❌ | 📣 | ✅ verification submit | ❌ |
| Pilot / limited-live mode | ❌ | ❌ | ❌ | 📣 | ❌ | ❌ |
| **Academic setup** | | | | | | |
| Academic year entity with status | ✅ | ✅ Current/Past/Upcoming | ✅ | ✅ | ✅ | ✅ `sessions[]` + `currentSession` + `archivedSessions[]` |
| Terms / semesters | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ exam/term structures; no first-class Term entity |
| Classes / sections / streams / subjects | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ `School_config` + capacity engine |
| Grading policy | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ board + grading pattern |
| Year rollover engine | ✅ irreversible batch | ✅ with warnings | ⚠️ manual | ✅ | n/a | ✅ `preview_rollover` + `rollover_session` |
| Year freeze / lock | ⚠️ | ❌ | ⚠️ | ⚠️ | ✅ | ✅ session freeze + period lock |
| **Operational setup** | | | | | | |
| Attendance, timetable, comms, transport, library, documents | ✅ | ✅ | ⚠️ | ✅ | n/a | ✅ all, incl. mature TMS |
| **Finance** | | | | | | |
| Fee structure & collection | ⚠️ | ✅ | ✅ | ✅ | n/a | ✅ authoritative `feeDemands` |
| Chart of accounts / ledgers | ❌ | ❌ | ✅ | ✅ | n/a | ✅ Accounting module |
| Fiscal-year close / opening balances | ❌ | ❌ | ✅ | ✅ | n/a | ⚠️ session-scoped; no institution-level FY close |
| Rollover fee-balance policy choice | ✅ explicit 3-way | ❌ | ⚠️ | ⚠️ | n/a | ⚠️ rollover has fee preview/exec; not the same 3-way operator choice |
| **HR** | | | | | | |
| Departments, designations, policies, payroll, leave | ⚠️ | ⚠️ | ✅ | ✅ | ✅ | ✅ full HR/Payroll |
| **Governance** | | | | | | |
| Approvals / signatories / authority hierarchy | ⚠️ | ⚠️ | ✅ | ⚠️ | ✅ DMIS officer approves | ⚠️ RBAC `manage`; no two-person control |
| Immutable audit of institutional acts | ⚠️ | ⚠️ | ✅ | ⚠️ | ✅ | ⚠️ per-module `auditLogs`; **SA acts → RTDB** (G5) |
| **Compliance (India)** | | | | | | |
| UDISE+ code & status | n/a | n/a | n/a | ✅ | ✅ statutory | ❌ |
| APAAR ID | n/a | n/a | n/a | ⚠️ | ✅ | ⚠️ designed, not built |
| DigiLocker issuance | n/a | n/a | n/a | ⚠️ | ✅ | ❌ |
| DPDPA'23 / Rules'25 retention & erasure | n/a | n/a | n/a | ⚠️ | ✅ statutory | ❌ no retention schedule anywhere |
| **Lifecycle proper** | | | | | | |
| Tenant suspend / reactivate | n/a | n/a | n/a | ⚠️ | n/a | ✅ **mature**, rules-enforced |
| Institution close | ❌ | ❌ | ❌ | ❌ | ✅ both UDISE & CBSE | ❌ 🆕 |
| Institution archive + restricted access | ❌ | ❌ | ⚠️ | ❌ | ✅ | ❌ 🆕 |
| Institution merge | ❌ | ❌ | ⚠️ | ❌ | ✅ UDISE merge flow | ❌ 🆕 (defer) |
| Re-open / re-onboard | ❌ | ❌ | ⚠️ | ❌ | ⚠️ 3-yr bar (CBSE) | ❌ 🆕 |

**Reading of the matrix:** ZenX is at or above the Indian market on *operational* capability (TMS,
graded RBAC, accounting, capacity engine, session freeze) and at or above PowerSchool on *tenant*
lifecycle. It is behind **the regulator**, not behind the vendors, on institution lifecycle. That is
the gap worth closing — and it is a differentiator precisely because no competitor documents it.

---

## 6. Indian School ERP Research — what actually binds

Three India-specific forces make institution lifecycle non-optional at scale:

1. **UDISE+ status is a legal fact about the school**, updated by a District MIS Officer, with defined
   outcomes for closure, merger and upgrade, and an explicit obligation that the Student and Teacher
   modules reflect the change. An ERP that cannot represent "this school merged into that school on
   this date" will be reconciled against UDISE by hand, forever.
2. **CBSE closure obligations survive closure.** The bye-laws require the school to *retain
   infrastructure and teachers* to see classes IX–XII through board exams, and impose a 3-year bar on
   re-application. Translation for ZenX: **CLOSED must not mean "switched off."** It must mean "no new
   intake; obligations continue; records complete." A hard cut-off at closure would actively prevent a
   school from meeting its statutory duty.
3. **DPDPA 2023 + DPDP Rules 2025** make retention a designed property, not an accident:
   purpose-limited retention, erasure on purpose fulfilment with advance notice to the principal,
   72-hour breach notification, verifiable parental consent and a ban on profiling/tracking/targeted
   advertising for under-18s (educational institutions receive *narrow* relief from some
   parental-consent mechanics for their functions — do not over-read it). ZenX holds minors' data in
   every module. **A closure workflow with no retention schedule is a compliance liability**, and
   over-eager deletion is equally a liability against board-audit needs.

**Explicit non-recommendation:** do not claim UDISE+/APAAR/DigiLocker "compliance" or "integration"
because competitors advertise it. §14/§24 recommend only *carrying the identifiers and the status* so
a human can satisfy the regulator — not building an unattested integration.

---

## 7. Institution Lifecycle vs Academic Session Lifecycle

**These must remain two separate state machines on two separate documents.** ZenX already has the
second; do not fold it into the first.

**Institution lifecycle** (proposed, `schools/{schoolId}.institution`):

```
PROSPECT → ONBOARDING → ACTIVE ⇄ (operationalHold) → CLOSING → CLOSED → ARCHIVED
                            ↑                                             │
                            └────────── REOPEN (SA + 2-person) ───────────┘
```

**Academic session lifecycle** (exists, `schools/{schoolId}.sessions[]` + freeze flag):

```
PLANNED → ENROLLING → ACTIVE → EXAMINATION → RESULTS → PROMOTION → FROZEN → ARCHIVED
```

ZenX implements the *ends* of this well (`add_session`, `set_active_session`, freeze / period-lock,
`rollover_session`, `archive_session`) but persists only two facts: `archivedSessions[]` membership
and `currentSession` identity. The middle states are **implicit**, inferred from exam/result/promotion
module data. That is a legitimate design choice and should be **kept**: persisting five more session
statuses buys little and risks divergence from the module data that actually determines them.

**Interaction rules (answering the questions posed):**

| Question | Answer | Rationale |
|---|---|---|
| School ACTIVE while its session is CLOSED/ARCHIVED? | **Yes, required** — every school sits here between March and April | PowerSchool EOY; Gibbon Current/Past |
| New session prepared while the current one is ACTIVE? | **Yes, required** | Gibbon "Upcoming"; `Session_lifecycle::add_session` already allows it |
| School suspended while a session stays archived? | **Yes** — archive is a property of the *year*, suspension of the *account* | Orthogonality |
| Session ACTIVE while the institution is CLOSING? | **Yes — this is the CBSE case**; closure is announced mid-year and the year must complete | CBSE affidavit requirement |
| Session ACTIVE while the institution is ARCHIVED? | **No** — the one hard cross-axis invariant | Prevents live writes into an archived tenant |
| Institution ONBOARDING with an ACTIVE session? | **No** — go-live *is* the act of making the first session live | Prevents half-configured live operation |

**Invariant set — only four; resist adding more:**
- **I1:** `institutionState = ARCHIVED` ⟹ every session ∈ `archivedSessions[]`.
- **I2:** `institutionState ∈ {PROSPECT, ONBOARDING}` ⟹ no student/fee/attendance writes outside
  migration paths (enforced by the access resolver, §21.4).
- **I3:** `currentSession ∉ archivedSessions[]` — **already enforced** under lock + CAS at
  `School_config.php:5545-5556`. Do not re-implement it.
- **I4:** No backwards move from ARCHIVED except an explicit, SA-only, approval-gated REOPEN that
  writes a new lifecycle event and never mutates history.

---

## 8. Student Lifecycle Integration

The Student Lifecycle stays authoritative. The institution axis **gates entry and exit; it never
mutates a student record.**

| Institution state | New admissions | Existing students | Promotion / rollover | TC / certificates | Parent visibility |
|---|---|---|---|---|---|
| PROSPECT | ❌ | n/a | n/a | ❌ | n/a |
| ONBOARDING | ⚠️ **migration-only** (bulk import allowed; public admission portal closed) | admin read/write | ❌ | ❌ | ❌ |
| ACTIVE | ✅ | ✅ | ✅ | ✅ | ✅ |
| operationalHold (commercial) | ❌ | frozen: admin read-only; app access denied | ❌ | ❌ (do not issue documents while unpaid) | ⚠️ read-only (§12) |
| CLOSING | ❌ **new intake barred** | ✅ full operation — **must continue** (CBSE) | ✅ within the closing year only | ✅ **prioritised** — TC issuance is the point of CLOSING | ✅ |
| CLOSED | ❌ | read-only | ❌ | ✅ on request, restricted actor set | ⚠️ read-only, time-boxed |
| ARCHIVED | ❌ | read-only, SA-mediated | ❌ | ⚠️ export/reprint only, logged | ❌ |

**Edge cases, with the researched answer:**

- **Closure while students remain active** — the *normal* case, not an exception. CLOSING exists
  precisely so TC generation, result publication and fee settlement can complete. Never auto-withdraw
  students on closure; produce a **work-list** of unresolved students instead.
- **School merger** — UDISE models this as a real transition. The honest v1 answer for ZenX: source
  school → CLOSING → bulk TC/export; target school bulk import; source → CLOSED with
  `closureReason = MERGED` and `mergedIntoSchoolId` recorded as a **pointer, not a data move**. Do
  **not** build cross-tenant record migration in v1 (§26) — it breaks tenant isolation, a hard rule in
  this codebase.
- **Campus/branch transfer** — out of scope until campuses exist (§12). Today it is a TC + admission.
- **Records after closure** — retained. Report cards, receipts, ledgers and documents stay readable
  under the restricted-actor policy for the retention period, because board audit and parent
  certificate requests both outlive the school.
- **Alumni** — closure must not orphan alumni; alumni records are institution-scoped and survive to
  ARCHIVED. Alumni *login*, if it ever exists, is a separate decision and is not granted here.
- **Fee records after closure** — never deleted at closure; see §9.

---

## 9. Staff Lifecycle Integration

**Verdict: the institution axis INFORMS staff lifecycle; it must never CONTROL it.**

Auto-separating staff when a school closes would be both wrong (CBSE requires teacher retention
through board exams) and dangerous — it would trigger payroll/F&F side effects without a human
decision, against the production-stability rule.

| Institution state | Staff effect |
|---|---|
| ONBOARDING | Staff import allowed; app/web login only after go-live (or explicitly for named pilot users) |
| ACTIVE | Normal |
| operationalHold | Staff app + web login denied (already the behaviour via `tenantActive()`); employment records untouched; payroll stays computable through back-office/service-account paths |
| CLOSING | Staff remain active. Generates a work-list: open leave balances, pending F&F, unreturned assets, active driver assignments |
| CLOSED | No new staff; existing records read-only; separations must be completed *before* CLOSED (a blocking checklist item) |
| ARCHIVED | Employment history retained for statutory periods; access SA-mediated |

Note the interaction with the known programme state: **F&F is the one remaining gap in the Staff
Lifecycle programme, blocked on open money questions.** Closure surfaces exactly that gap — closure
cannot be certified complete without an F&F answer. That is an argument for sequencing closure last
(Phase 8), not for building F&F now.

---

## 10. Fees / Accounting Integration

The most conservative area in the design. **The institution axis gets read access to financial state
and veto power over new financial obligations; it gets no write authority over ledgers whatsoever.**

| Institution state | Fee generation | Collection | Concessions | Refunds | Late fees | Accounting posting |
|---|---|---|---|---|---|---|
| ONBOARDING | ⚠️ dry-run / simulation only | ❌ | config only | ❌ | ❌ (cron must skip) | ⚠️ opening balances only |
| ACTIVE | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| operationalHold | ❌ new demands | ⚠️ **allow in-flight capture** | ❌ | ⚠️ approved only | ❌ **suspend accrual** | ✅ money that arrives must land |
| CLOSING | ❌ for future periods | ✅ settlement is the purpose | ⚠️ approvals only | ✅ prioritised | ❌ accrual stops at closure-effective date | ✅ |
| CLOSED | ❌ | ❌ | ❌ | ⚠️ SA + finance approval, audit-logged | ❌ | ⚠️ adjusting entries only, approval-gated |
| ARCHIVED | ❌ | ❌ | ❌ | ❌ | ❌ | read-only |

**Non-negotiables:**

- The lifecycle layer **must not bypass** the existing freeze / period-lock choreography. Reuse
  `_abort_if_session_frozen()` and the `PERIOD_LOCKED` contract rather than inventing a parallel
  guard. Note the deliberate carve-out already in `MY_Controller`: payment capture stays unblocked
  during freeze so "a payment captured one minute before freeze still lands cleanly." **Closure must
  inherit that carve-out**, or money in flight will be lost.
- **Late-fee accrual on a suspended or closing school is a real financial defect today** —
  `Fees_late_fee_cron` has zero lifecycle checks (G2). A tenant suspended for non-payment currently
  keeps accruing *parent-facing* penalties. This is the highest-value single fix in the whole
  research, and it does **not** require the lifecycle layer to exist (§24, Phase 1).
- Closure prerequisites must be *computed*, never assumed: outstanding receivables, unapplied
  advances, undeposited cash, unreconciled bank lines, open refunds, unposted journals. These already
  exist as concepts in `AccountingReconciler` / `Billing_integrity`; the closure checklist should
  **call them**, not re-derive them — and must respect the known collection-convergence debt of
  querying both `accounting` and `accountingLedger`.

---

## 11. TMS Integration

TMS carries the most live, physical, safety-relevant state, so it needs the sharpest rules.
**Transport must fail safe, not fail closed.**

| Institution state | Routes / vehicles / drivers | Live trips | GPS ingest | Transport fees | SOS | Historical trips |
|---|---|---|---|---|---|---|
| ONBOARDING | config allowed | ❌ | ❌ | ❌ | ❌ | n/a |
| ACTIVE | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| operationalHold | read-only | ⚠️ **in-flight trips MUST run to completion** | ⚠️ continue for in-flight trips only | ❌ new charges | ✅ **always allowed** | ✅ read |
| CLOSING | ✅ until effective date | ✅ | ✅ | ⚠️ settlement only | ✅ | ✅ |
| CLOSED | ❌ | ❌ | ❌ | ❌ | ❌ | read-only |
| ARCHIVED | ❌ | ❌ | ❌ | ❌ | ❌ | retained per schedule; breadcrumbs may already be TTL-expired |

**Two hard rules:**

1. **Never terminate an in-flight trip because of a billing state.** A bus with children aboard is a
   safety system. Suspension may block *new* trip starts — never an active trip, never SOS, never the
   parent's ability to see where the bus is. This is the clearest evidence that
   "suspended ⟹ deny everything" is the wrong default, and why §12's app policy is state-aware rather
   than binary.
2. **GPS breadcrumb TTL is already a manual deploy step** (`vehicleBreadcrumbs`). Archival must not
   assume breadcrumbs still exist; the closure export snapshots trip **summaries**, not raw
   breadcrumbs.

---

## 12. Parent App / Teacher App / Admin Panel

**Current state (verified): the apps are lifecycle-blind.** Zero occurrences of `tenantPublic` or
`lifecycleState` across 254 Parent and 249 Teacher Kotlin files, despite `firestore.rules:48-50`
documenting a reactive-logout design (H2/H3) that depends on exactly that subscription. So today, when
a tenant is suspended, both apps experience it as **raw `PERMISSION_DENIED` on every query** — which a
parent reads as a broken app, not as "your school's account is on hold." That is gap **G3**, a
user-trust issue rather than a cosmetic one.

**Proposed access policy — deliberately not binary:**

| State | Parent app | Teacher app | Admin panel |
|---|---|---|---|
| ONBOARDING | login blocked, "school is being set up" | named pilot users only | ✅ full — this is where the work happens |
| ACTIVE | ✅ | ✅ | ✅ |
| operationalHold | login allowed → **read-only banner state**: attendance & marks visible, **payments blocked**, SOS/live trip visible, no new submissions | login allowed → read-only + explicit message on attendance capture | ✅ with write gating; SA sees a suspension banner |
| CLOSING | ✅ full, plus a dated "school closing on DD-MM-YYYY" notice | ✅ full | ✅ + closure console |
| CLOSED | read-only, time-boxed to the retention window; documents downloadable | read-only for own historical records | restricted: SA + delegated admin only |
| ARCHIVED | ❌ | ❌ | SA-only, audit-logged access |

Read-only-with-explanation is strictly better than logout: it preserves a parent's access to their
child's records (to which they may be legally entitled), removes every write and payment path, and
converts a support-ticket generator into a self-explaining state.

**Implementation shape (apps):** subscribe to `tenantPublic/{schoolId}` — the thin client-readable
mirror that already exists and is already fanned out by `B2_registry_service::write_lifecycle_state()`
— and map state → UI mode. No new collection, no new rule, no RTDB. This is the cheapest high-value
app change available.

---

## 13. Multi-Tenant / Multi-Campus Architecture

**Recommendation: do NOT build an organization hierarchy now. Reserve the seams, and make three
decisions today that prevent a painful migration later.**

Evidence for the future need is real but not urgent: PowerSchool's whole model is District → School;
ERPNext carries multi-company; edumerge markets ~50-campus groups; UDISE has block/district above the
school. Evidence against building it now: ZenX's tenant isolation is a hard, load-bearing rule
enforced in claims, `docId()`/`schoolWhere` scoping and `firestore.rules`. Introducing a level above
the school means every rule, index and query either gains a second scope dimension or gains an
`IN`-style fan-out. That is a platform-wide change with no current customer demanding it.

**The three decisions to make now (cheap now, expensive later):**

| # | Decision | Why now |
|---|---|---|
| D1 | **`school_id` means "tenant + isolation boundary", permanently.** It must never come to mean "campus". A second campus = a second `SCH_*` tenant, related by a pointer. | Every rule and index already assumes one meaning; changing it later is a full-platform migration |
| D2 | **Reserve a nullable `orgId` (and `orgRole` ∈ {standalone, member, hq}) on `schools/{schoolId}`, unused and unenforced.** | Adding a field later is trivial; *back-filling relationships* across live tenants is not. A reserved field lets a group be described the day a group signs |
| D3 | **Group-level views are read-only aggregations over per-tenant data, never a shared write path.** | Preserves isolation; a "trust dashboard" is a fan-out read, not a new tenancy model |

**Explicitly deferred (§27):** cross-tenant queries, a shared `organizations` collection with
inherited configuration, cross-campus student transfer, group-level RBAC, consolidated group
accounting. Each of these is a genuine product someday; none is justified by evidence today.

**Target shape if/when a group signs:**

```
organizations/{orgId}            ← thin: name, legal entity, contact, member schoolIds[]
        │  (pointer only, no config inheritance, no cross-tenant writes)
        ├── schools/SCH_A  (full tenant, isolated)
        ├── schools/SCH_B  (full tenant, isolated)
        └── schools/SCH_C  (full tenant, isolated)
```

Configuration inheritance is the trap: the moment a group-level fee structure or grading scale is
"inherited", ZenX acquires a second source of truth for tenant configuration and every module must
learn to resolve it. Recommendation: **copy-on-create (templating), never inherit-at-read.**

---

## 14. Governance & Compliance

**What the institution record should carry (identity + compliance), and what it must not.**

Carry (all as *data*, not as integrations):
- UDISE+ code; UDISE status (functional / closed / merged / upgraded) and the date it changed.
- Board affiliation: board, affiliation/school number, affiliation type (provisional / regular),
  validity from–to. CBSE's provisional↔regular↔withdrawn progression is dated, so store dated
  records, not a flag.
- Legal entity: trust/society/Sec-8 company name, registration number, PAN; management type
  (Govt / Aided / Private unaided / Minority); recognition order number and validity.
- School category (Primary / Upper primary / Secondary / Sr. Secondary), medium of instruction,
  gender type (Boys/Girls/Co-ed), residential flag.
- Governance contacts: Principal, correspondent/manager, **Data Protection / grievance officer**
  (DPDPA), POSH Internal Committee presiding officer, POCSO-designated person.
- Operational identity: timezone, geo coordinates, working-days pattern, academic calendar
  start/end dates.

Do **not** carry or claim: automated UDISE submission, DigiLocker issuance, APAAR generation, or
"RTE compliance". Each is a real integration with its own attestation requirements; advertising them
because a competitor does would be a false claim. Carrying the identifier so a human can file is
honest, useful, and cheap.

**Governance controls that the lifecycle layer *should* introduce (and only these):**
- A **reason** is mandatory on every institution-state transition.
- An **effective date** is mandatory and may be in the future (CLOSING almost always is).
- **Two-person control** on the destructive transitions only: `→ CLOSED`, `→ ARCHIVED`, `REOPEN`.
- **Finance sign-off** as a prerequisite (not an approval chain) for `→ CLOSED`: the financial
  checklist must be green or explicitly overridden with a recorded justification.

---

## 15. Security / RBAC

**Finding: no change to ZenX's graded RBAC is required, and none should be made.**

The existing model — `has_permission($module, $level)` with `view | edit | manage`, effective access
= `union(staff_roles[]) + extra − denied`, catalogue in `schools.staffRoles` mirrored to
`functions/rbac_modules.json` — is sufficient to express every authorization this design needs. The
institution lifecycle is **not** a new permission model; it is a new *gate that runs before* the
permission check.

**Two-layer authorization (this is the whole security design):**

```
request
  → LAYER 1: institution + commercial state resolver   (can ANYONE do this here, right now?)
  → LAYER 2: existing graded RBAC                      (can THIS actor do this?)
  → LAYER 3: existing tenant-scope checks              (is this THEIR data?)
```

Layer 1 is new and must be **fail-closed for writes, fail-open-to-read-only for reads** — the same
posture `_is_session_frozen()` already takes. It must never grant anything RBAC denies; composition is
`AND`, never `OR`.

**Who may trigger what:**

| Transition | Actor | Control |
|---|---|---|
| create school (→ PROSPECT/ONBOARDING) | Superadmin only | existing SA auth |
| ONBOARDING → ACTIVE (go-live) | Superadmin, with school SSA acknowledgement | readiness must be green or overridden with reason |
| ACTIVE ⇄ operationalHold (commercial) | Superadmin / automated billing job | existing B2 path; **add reason capture** |
| ACTIVE → CLOSING | Superadmin **on a written school request**; school SSA raises, SA executes | reason + effective date mandatory |
| CLOSING → CLOSED | Superadmin | **two-person**; financial + student + staff checklists green or overridden |
| CLOSED → ARCHIVED | Superadmin | **two-person**; export produced and verified |
| ARCHIVED → REOPEN | Superadmin | **two-person**; new lifecycle event, never a history rewrite |

**Genuine RBAC deltas required:** one new module key (e.g. `institution_lifecycle`) in the RBAC
catalogue so a school-side SSA can be granted `view` (see readiness/closure status) and `edit`
(raise a closure request) without any `manage` capability. Executing transitions stays SA-only and
therefore needs no school-side permission at all. **That is the entire RBAC change.** The legacy
`_require_role()` name-gate must *not* be used for these new surfaces.

---

## 16. Audit Trail

**Current state: not lifecycle-grade.** Per-module `auditLogs` exist and are good, but the
*institution-level* acts are logged by `MY_Superadmin_Controller::sa_log()` into
**RTDB** (`System/Logs/Activity/{date}`) — which violates the ZERO-RTDB hard line, is not queryable
alongside the rest of the audit surface, and is not immutable in any meaningful sense.

**Required shape — append-only, immutable, Firestore-native:**

`schoolLifecycleEvents/{eventId}` (server-write only; no client write rule at all)

| Field | Notes |
|---|---|
| `schoolId` | tenant scope, indexed |
| `axis` | `institution` \| `commercial` \| `session` |
| `fromState` / `toState` | previous and new |
| `actorId`, `actorRole`, `actorSurface` | who, in what capacity, from where (SA console / admin panel / job) |
| `approverId` | second person, where two-person control applies |
| `ts` (server timestamp) | when it was recorded |
| `effectiveDate` | when it *takes effect* — often ≠ `ts` |
| `reason` | mandatory free text |
| `checklistSnapshot` | the readiness/closure result at decision time |
| `affectedConfig` | what the transition changed |
| `correlationId` | ties the transition to its side effects across modules |
| `idempotencyKey` | `{schoolId}:{toState}:{effectiveDate}` — replay protection |

**Two research-backed principles:**
1. **Dated records beat status flags** (the Banner lesson). The event log is the history; the state
   field on `schools/{schoolId}` is only a **materialized "current" pointer** for cheap reads and
   rules. If they ever disagree, the event log wins and the pointer is repaired.
2. **Never mutate or delete a lifecycle event**, including on REOPEN. Reopening appends; it does not
   erase the closure.

Field-level history for the *institution profile* (affiliation number, recognition validity, principal
name) is worth having and is cheap: append a `schoolProfileHistory` entry on change of a small
allow-list of governance-relevant fields. Do **not** build generic field-level history for all
configuration — that is a data-volume trap for near-zero benefit.

---

## 17. Closure / Archival

The most under-served capability in the entire market, and the one with real legal weight in India.

**The procedure (a checklist attached to states, NOT a workflow engine):**

```
Closure request (school SSA)         → recorded, no state change yet
      ↓
Impact assessment (computed)         → students, staff, money, assets, apps
      ↓
SA approval + effective date         → ACTIVE → CLOSING            [reason mandatory]
      ↓
Freeze new operations                 → no new admissions, no new demands, no new trips after date
      ↓
Resolve financial items               → receivables, refunds, deposits, unposted journals, bank recon
      ↓
Resolve student items                 → TCs, certificates, results published, report cards generated
      ↓
Resolve staff items                   → separations, F&F, assets returned, driver reassignment
      ↓
Resolve transport / assets            → routes closed, vehicles released, GPS sessions ended
      ↓
Produce archive export                → machine-readable + human-readable, checksummed
      ↓
Compliance archive                    → statutory documents, board records, retention schedule set
      ↓
CLOSED   [two-person]                 → restricted access, read-only
      ↓
ARCHIVED [two-person]                 → SA-mediated access only, retention clock running
```

**What happens to data — the classification that matters:**

| Class | Examples | Treatment |
|---|---|---|
| **Frozen** | attendance, marks, timetable, trips | read-only forever from CLOSED |
| **Retained** | ledgers, receipts, fee demands, report cards, TCs, employment records | retained for the statutory period; readable by an authorized actor |
| **Exported** | full tenant export at closure | delivered to the institution; checksummed; delivery logged |
| **Archived** | documents in Storage, certificates | retained; access logged per read |
| **Anonymized** | operational telemetry, analytics rollups, device/session logs, security events beyond their retention need | pseudonymize once purpose ends (DPDPA purpose limitation) |
| **Deleted** | *only* on a written retention-schedule basis or a lawful erasure request | **never** as a default step of closure |

**Hard rule:** closure never deletes. Deletion is a *separate, later, scheduled* act driven by a
documented retention schedule (§14), executed with its own two-person control and its own audit
event. The research is unambiguous on both sides — DPDPA requires erasure once purpose is served,
CBSE and board audit require records to survive closure — and the only safe resolution is an explicit
per-class retention schedule rather than a blanket rule.

**Re-onboarding:** must be an explicit decision (Azure guidance). ZenX should support REOPEN from
ARCHIVED because CBSE's 3-year bar implies schools *do* come back. Reopen restores read/write on the
existing tenant; it never re-creates the tenant, and never revives an archived session.

---

## 18. Edge Cases — the 25 scenarios, resolved

| # | Scenario | Correct lifecycle behaviour |
|---|---|---|
| 1 | School created mid-academic-year | ONBOARDING with `sessionYear` = the running session; migration-only writes; go-live sets first ACTIVE session. Already partially supported — `create_tenant` seeds `sessions[]` + `currentSession` atomically |
| 2 | Next session prepared before current closes | Allowed and normal (`add_session` without `set_active_session`) |
| 3 | School temporarily suspended | `operationalHold` on the **commercial** axis; institution stays ACTIVE. Records intact, apps read-only, no accrual |
| 4 | School reactivated | Commercial axis returns to active; **no data repair should be needed** — which is only true if suspension never mutated data (it doesn't today, and must not) |
| 5 | Principal changes | Profile change + `schoolProfileHistory` entry. Not a lifecycle transition |
| 6 | Management/trust changes | Profile + legal-entity change, dated, two-person if the legal entity changes. Not a lifecycle transition |
| 7 | Branch opens | New tenant + `orgId` pointer (D2). Not a state change on the parent |
| 8 | Branch closes | That tenant runs its own CLOSING → CLOSED |
| 9 | School merges | Source → CLOSING → CLOSED with `closureReason=MERGED` + `mergedIntoSchoolId`; target imports via existing bulk paths. **No cross-tenant data move in v1** |
| 10 | Students transferred during closure | Standard TC + admission at the receiving school. Closure must not invent a second transfer path |
| 11 | Staff transferred during closure | Standard separation + fresh onboarding. Institution axis informs only |
| 12 | Pending fee dues at closure | **Blocking checklist item, overridable with reason.** Dues survive closure; collection stops at CLOSED |
| 13 | Pending refunds | Blocking. Refund after CLOSED requires SA + finance approval and is audit-logged |
| 14 | Open accounting entries | Blocking: unposted journals, unreconciled bank lines, undeposited cash — computed via existing reconciler paths |
| 15 | Active transport assignments | Warning at CLOSING; blocking at CLOSED. Routes closed on the effective date |
| 16 | Active GPS / trip records | In-flight trips always complete. No new trips after the effective date. Breadcrumbs may TTL-expire; summaries are exported |
| 17 | Pending examinations | Blocking for CLOSED. CLOSING explicitly exists so exams finish (CBSE affidavit) |
| 18 | Unpublished results | Blocking for CLOSED — a closed school with unpublished results strands students |
| 19 | Parent accounts | Read-only through CLOSED for the retention window; disabled at ARCHIVED |
| 20 | Teacher accounts | Read-only for own historical records through CLOSED; disabled at ARCHIVED |
| 21 | Certificate request after closure | Allowed at CLOSED via a restricted actor set; at ARCHIVED, SA-mediated reprint from the archive, logged per issuance |
| 22 | Historical report cards after archive | Retained and retrievable; every read logged |
| 23 | Regulatory audit after closure | The archive export + immutable event log is the deliverable. This is the primary reason not to delete |
| 24 | Data export after closure | Allowed to authorized actors; **rate-limited and logged** (export abuse is a real threat, §23.3) |
| 25 | Reopening an archived institution | Two-person REOPEN; appends an event; archived sessions stay archived; a new session must be created to operate |

---

## 19. ZenX Current-State Assessment

**A. Already exists and is good — do not rebuild**
- Commercial tenant lifecycle with 7 states, derived entitlements, plan/module overrides, grace
  handling and automated transitions (`B2_registry_service`, `Superadmin_plans`, `B2_lifecycle_job`).
- **Rules-level enforcement** of tenant state — `tenantActive()` double-gates on
  `schoolControl.lifecycle.state` AND `schools.adminDisabled.value`, more-restrictive-wins, applied
  across ~10+ module blocks. This is genuinely strong design.
- `tenantPublic/{schoolId}` — a thin, client-readable mirror already fanned out on state change. The
  correct substrate for app-side lifecycle awareness; nothing new needs inventing.
- Academic session management: add / activate / archive / delete / rollover with **lock + CAS**
  concurrency control and an explicitly-protected invariant (archived ≠ active).
- Session **freeze** and **period lock** with a deliberate payment-capture carve-out.
- 4-step SA onboarding wizard with name/code availability checks, telemetry
  (`ONBOARD_RESULT`), Firebase-Auth rollback on failure, bounded retries on the SSA staff doc, and
  tenant quarantine when the contract can't be satisfied. This is a well-built onboarding *creator*.
- Graded RBAC, capacity engine, document service, accounting reconciliation, TMS.

**B. Partially exists**
- **Institution profile** — name, principal, established year, affiliation board + number, address,
  contacts, logo, board type, grading pattern. Real but shallow (§20 UI).
- **Archival** — exists for *sessions* only ("hidden from default dropdowns but data is preserved"),
  not for institutions.
- **Audit** — strong per module; institution-level acts land in RTDB (G5).
- **Readiness** — the wizard validates *inputs*, not *operational readiness*.
- **Suspension side effects** — enforced at the rules and login boundary; absent in crons and apps.

**C. Missing**
- Institution state axis; readiness engine; closure and archival procedure; institutional/compliance
  identity; lifecycle event log; retention schedule; group/campus seams; app-side state handling;
  cron-side state handling; reason capture and two-person control on institutional acts.

**D. Architecturally unsafe (today, regardless of this programme)**
- **G2 — crons ignore tenant state.** Late fees accrue, backups run, transport analytics roll up, and
  campus-access jobs execute against suspended tenants. The late-fee case is parent-facing and
  financial.
- **G5 — RTDB audit write** in the SA controller.
- **G8 — no reason/approval** on a state flip that revokes access for an entire school.
- **G3 — apps surface suspension as PERMISSION_DENIED**, which is indistinguishable from a bug.
- `Superadmin_schools.php:602` accepts an `inactive` status that no consumer interprets — a status
  value with no defined semantics is a latent divergence.

**E. Future-only**
- Organization/group hierarchy; campus lifecycle; cross-tenant merge; group dashboards; AI/analytics.

**F. Should NOT be implemented** — see §27.

---

## 20. ZenX Gaps — including UI/UX field gaps

### 20.1 System gaps (consolidated, prioritized)

| ID | Gap | Class | Severity | Fix locus |
|---|---|---|---|---|
| G1 | No institution axis; closure ≡ non-payment | C | High | `schools/{schoolId}.institution` + resolver |
| G2 | Crons ignore tenant/institution state | D | **High** | one shared guard helper called by all 11 jobs |
| G3 | Apps lifecycle-blind → PERMISSION_DENIED | D | **High** | `tenantPublic` subscription in both apps |
| G4 | No closure/archival procedure | C | High | new SA console + checklist |
| G5 | SA audit → RTDB | D | High | `schoolLifecycleEvents` (Firestore) |
| G6 | Institution identity/compliance fields absent | B | Medium | profile schema + UI (§20.2) |
| G7 | No readiness model | C | Medium | readiness scorer over existing module reads |
| G8 | No reason/approval on suspend | D | Medium | `Superadmin_schools::update_status` |
| G9 | No `orgId` seam | C | Medium (future) | reserved nullable field |
| G10 | Service-account writes bypass state gates | D | Medium | resolver called in `MY_Controller`, not only in rules |
| G11 | `inactive` status has no consumer semantics | D | Low | remove or define |
| G12 | Rollover fee-balance policy not an explicit operator choice (PowerSchool offers 3 ways) | B | Low | rollover UI |
| G13 | No first-class Term/semester entity | B | Low | future |
| G14 | No retention schedule anywhere (DPDPA) | C | Medium | policy doc + per-class schedule |

### 20.2 UI / UX gaps — missing fields and screens

**Screen: SA onboarding wizard** (`views/superadmin/schools/create.php`) — currently collects
school name, city, street, email, phone, logo; school code, admin login id/name/email/phone/password;
plan, expiry date, session year; then a review step.

| Missing field / affordance | Why it matters |
|---|---|
| UDISE+ code | Statutory identifier; every downstream report and government filing needs it |
| Board + affiliation number at onboarding | Currently only editable later in School Config; it drives report cards and certificates from day one |
| School category / level (Pre-primary → Sr. Secondary) | Determines valid class range; today classes can be configured outside the school's actual level |
| Medium of instruction, gender type, residential flag | UDISE-mandatory; also drives templates and communication defaults |
| Management type + legal entity (trust/society/company, reg. no., PAN) | Required for closure, invoicing and statutory filing |
| State (only city + street are captured) | Address is incomplete at creation; state drives state-specific rules |
| Timezone / geo coordinates | Attendance windows, transport ETAs and cron scheduling all assume a locale |
| Academic calendar start/end + working-days pattern | Currently inferred per module; a single institutional truth would remove drift |
| **Onboarding intent** (migration vs fresh start) | Changes whether historical data import is expected — the single biggest determinant of go-live effort |
| **"Create as PROSPECT / ONBOARDING" instead of instantly live** | Today, finishing the wizard produces a fully live tenant (G7) |
| Explicit confirmation of what will be created | The review step summarizes inputs, not consequences (tenant + auth user + SSA staff doc + session seed) |

**Screen: School Config → Profile** (`views/school_config/index.php`, fields `pf_*`) — currently
display name, principal, established year, affiliation board, affiliation number, address, state,
city, pincode, phone, email, website, logo.

| Missing field | Why it matters |
|---|---|
| UDISE code + UDISE status | Statutory; also the join key to any government data |
| Affiliation type + validity from/to | CBSE affiliation is dated and can revert provisional → the flag model can't express it |
| Recognition/registration order number + validity | Required at closure and inspection |
| Vice-principal / correspondent / manager | Certificates and official letters need more than one signatory |
| **Data Protection / grievance officer** contact | DPDPA-facing; must be publishable to parents |
| **POSH Internal Committee** presiding officer, **POCSO**-designated person | Statutory display obligations for a school |
| Alternate phone / office hours / support email | Parent app currently has one contact path |
| Bank/remittance identity for fee receipts | Receipt footers and refunds |
| GST / PAN / TAN | Invoicing and payroll statutory outputs |
| Social/website handles, motto, vision | Branding surface for report cards and the parent app |
| **Signature images** (principal, exam controller) | Certificates today rely on a narrow asset set |

**Screen: SA school detail** (`views/superadmin/schools/view.php`) — has Suspend / Activate / Disable
controls today.

| Missing affordance | Why it matters |
|---|---|
| **Reason field + confirmation** on suspend/activate | An access-revoking act for a whole school currently needs one click and records no motive |
| **Institution state** display, separate from billing state | An operator cannot tell "unpaid" from "closed" |
| **Readiness panel** (per-domain green/amber/red + blocking count) | Nothing tells an operator whether a tenant is actually usable |
| **Closure console** (start closing, effective date, live checklist, blocking items) | Entirely absent |
| **Lifecycle timeline** (who changed what, when, why) | The audit trail is unreadable — it's in RTDB under a date-keyed path |
| **Effective-dated / scheduled transitions** | Closure is almost always announced in advance |
| **Export / archive action** with checksum + delivery record | No way to hand a school its data |
| Impact preview before a state change ("this will affect 412 students, 38 staff, 6 routes") | Consequence-free destructive controls are the classic operator-error source |

**Cross-cutting UX gaps**
- **No user-facing explanation of a blocked state anywhere.** Suspended tenants get a login refusal
  (web) and raw permission errors (apps). Every state needs one canonical, human-readable message,
  centrally defined and reused by all three surfaces.
- **No banner/telemetry for "school in CLOSING"** — parents and staff should see a dated notice.
- **Phantom-success risk** (a documented trap in this codebase): the new SA lifecycle panels must
  check both `r.ok` and `{status:'error'}` before rendering success; a denied closure that reports as
  done would be severe.
- **Readiness should be actionable, not a score.** Each amber/red item needs a deep link to the exact
  configuration screen that fixes it, or it becomes decoration.

---

## 21. Recommended Target Architecture

### 21.1 The shape

```
                        INSTITUTION IDENTITY  (schools/{schoolId})
                     name · code · UDISE · board · legal entity · contacts
                                        │
        ┌───────────────────────────────┼───────────────────────────────┐
        │                               │                               │
  COMMERCIAL AXIS                INSTITUTION AXIS               ACADEMIC SESSION AXIS
  schoolControl.lifecycle        schools.institution.state      schools.sessions[] / freeze
  (EXISTS — untouched)           (NEW — thin, 6 states)         (EXISTS — untouched)
        │                               │                               │
        └───────────────┬───────────────┴───────────────┬───────────────┘
                        │                               │
                 ACCESS RESOLVER  (one function, most-restrictive-wins)
                 PHP: MY_Controller bootstrap · Rules: tenantActive() extended
                                        │
     ┌──────────────┬──────────────┬────┴────────┬──────────────┬──────────────┐
  Student LC     Staff LC        Fees/Acct        TMS         Comms/Docs     Crons
  (unchanged)   (unchanged)     (unchanged)   (unchanged)     (unchanged)   (+guard)
     └──────────────┴──────────────┴─────────────┴──────────────┴──────────────┘
                                        │
                        tenantPublic/{schoolId}  (EXISTS — thin mirror)
                                        │
                     Admin Panel  ·  Teacher App  ·  Parent App
                                        │
                  schoolLifecycleEvents/{eventId}  (NEW — append-only history)
```

**Everything below the resolver is unchanged.** That is the point of choosing (B): the lifecycle layer
is a gate and a ledger, not an owner. It holds **no** canonical student, staff, fee, accounting or
transport data, and it never writes into those modules.

### 21.2 Firestore-native modelling (no RTDB, no dual-write, no mirror)

| Data | Location | Access | Rationale |
|---|---|---|---|
| Institution state (current) | `schools/{schoolId}.institution.{state, since, effectiveDate, reason, closureReason, mergedIntoSchoolId}` | server-write; read via existing `schools` read paths | Same doc that `tenantActive()` **already** `get()`s — **zero additional document reads in rules** |
| Institution identity/compliance | `schools/{schoolId}.identity.{…}` | server-write; admin read | One doc, no new collection |
| Lifecycle history | `schoolLifecycleEvents/{eventId}` append-only | server-only (no client write rule) | Immutable audit; queryable by `schoolId + ts` |
| Readiness result | `schoolReadiness/{schoolId}` (last computed) | server-write, SA + school-admin read | A *cache of a computation*, explicitly not a source of truth |
| Closure checklist run | `schoolClosureRuns/{schoolId}_{runId}` | server-only | A procedure record, not a state |
| Profile field history | `schoolProfileHistory/{schoolId}_{seq}` | server-only | Small allow-list of governance fields |
| Client-visible state | `tenantPublic/{schoolId}.institutionState` (+ existing `lifecycleState`) | client-read | **Existing** mirror, one more field, already fanned out |

**Rules impact (design only — nothing to change during research):** extend the existing
`tenantActive()` to also reject institution states that deny access. Because `schools/{schoolId}` is
*already* fetched inside that function, this adds **no** document reads and no measurable latency. The
comment block at `firestore.rules:37-42` (2 `get()`s per eval, cached per request, "re-evaluate at
>1000 tenants") remains accurate.

**Indexes:** one composite on `schoolLifecycleEvents(schoolId, ts desc)`. Nothing else. Deploy indexes
before code, per the runbook.

**Concurrency / correctness requirements** (mirroring patterns already proven in this codebase):
- Transitions go through the **same lock + Firestore `__updateTime` CAS** pattern as
  `Session_lifecycle::add_session` / `School_config::archive_session`. Do not invent a new one.
- **Idempotency key** `{schoolId}:{toState}:{effectiveDate}` prevents double-execution on retry.
- **Server timestamps only** for `ts`; client clocks are never trusted.
- **Stale clients**: the state pointer is authoritative at read time in rules; a client holding an old
  state cannot write, because rules re-evaluate server-side.
- **TOCTOU**: the resolver's verdict must be re-checked inside the transaction for any write that
  depends on it, not only at request entry.

### 21.3 The Readiness Engine (a scorer, not a workflow)

Readiness is **computed on demand from module data**, cached, never hand-maintained. If a criterion
can't be computed from existing data, it doesn't belong in the engine.

| Domain | Blocking criteria (must be true to go live) | Warning criteria |
|---|---|---|
| Identity | school name, code, board, address, contact present | UDISE code, affiliation number, logo |
| Academic | ≥1 class configured; ≥1 section per active class; current session set and not archived | subjects mapped, grading scale set, calendar dates |
| Students | ≥1 student enrolled **or** explicit "starting empty" acknowledgement | students without section, missing SRNO |
| Staff | ≥1 SSA with a valid staff doc and working login | staff without roles, no class teachers assigned |
| Fees | fee structure exists for the current session | demands generated, due dates set (a known cross-cutting debt) |
| Accounting | opening balances entered or explicitly waived | chart of accounts customized |
| Attendance | attendance settings exist for the tenant | policy/holidays configured |
| Timetable | — | periods defined |
| Transport | — (optional module) | routes/vehicles/drivers when the module is enabled |
| Communication | at least one delivery channel configured | templates customized |
| Documents | document service reachable | certificate templates chosen |
| Compliance | — | DPO/grievance contact, retention acknowledgement |
| Apps | SSA can log in on the admin panel | ≥1 parent and ≥1 teacher successfully logged in |

**Rules of the engine:**
- Blocking ⇒ go-live is refused *unless* a Superadmin overrides **with a recorded reason** (recorded in
  the lifecycle event, visible forever).
- Warnings never block.
- **Module-aware**: a criterion for a module the tenant isn't entitled to (`activeModules`) is skipped,
  not failed. The existing `Tenant_capability_service` already answers this.
- **Revalidation** on demand, on go-live, and before any closure transition. Never on a timer (that
  would be N tenant-wide scans for no operational benefit).
- **History**: keep the readiness snapshot inside the lifecycle event, not as a separate time series.

Presentation is the "94% / 1 blocking / 4 warnings" panel — but only if every row deep-links to the
screen that fixes it (§20.2).

### 21.4 Access resolver — one function, two callers

```
resolve(schoolId) →
  commercial = schoolControl.lifecycle.state      (existing)
  institution = schools.institution.state          (new)
  verdict = MOST_RESTRICTIVE(commercial, institution)
  → { allowRead, allowWrite, allowLogin, allowPayments, allowNewIntake,
      allowJobs, message }
```
Implemented **twice, deliberately**: once in PHP (`MY_Controller` bootstrap + a helper the crons call)
and once in `firestore.rules`. That duplication already exists for the commercial axis and is the
codebase's defence-in-depth posture; it is correct and should be preserved — but the two
implementations must be kept in sync by a test that asserts they agree (a pattern
`B2_analytics_verify` already uses: *"tenant_is_active() matches H1 firestore.rules tenantActive()
exactly"*).

---

## 22. Canonical State Machine

### 22.1 Institution axis — six states (and why not twelve)

| State | Meaning | Entered by | Exits to |
|---|---|---|---|
| **PROSPECT** | Record exists; nothing configured; may never convert | SA creates | ONBOARDING, (delete while empty) |
| **ONBOARDING** | Being configured/migrated/validated. Admin-only, migration writes allowed, no live operations | SA | ACTIVE (go-live), CLOSED (abandoned) |
| **ACTIVE** | Normal operation | go-live | CLOSING; (commercial axis may impose operationalHold) |
| **CLOSING** | Announced closure, effective-dated. No new intake; all obligations continue | SA on school request | CLOSED |
| **CLOSED** | Operations ended. Read-only + restricted actions (certificates, refunds by approval) | SA, two-person | ARCHIVED, ACTIVE (reopen) |
| **ARCHIVED** | Retention-only. SA-mediated access, every read logged | SA, two-person | ACTIVE (reopen, two-person) |

**Rejected as states, and why:**
- `DRAFT` — identical authorization to PROSPECT. Merge.
- `CONFIGURING`, `MIGRATING`, `VALIDATING` — all identical authorization to ONBOARDING; they are
  *readiness attributes*, not states. Represent them as `onboardingStage` metadata if the SA console
  wants a progress bar. **No rule, no cron and no app behaves differently across them.**
- `READY` — a readiness *result*, not a state. Deriving it avoids a stale flag.
- `PILOT` — genuinely useful *someday*, but ZenX has no per-user feature gating for a
  "some users live, some not" mode. Approximate it with ONBOARDING + named pilot logins; promote it to
  a state only if a customer needs it.
- `SUSPENDED` — **belongs to the commercial axis, which already has it.** Duplicating it on the
  institution axis creates two suspension truths and an inevitable divergence bug. If a *non-billing*
  operational hold is ever needed (e.g. regulator-ordered), add a boolean `operationalHold` with its
  own reason — not a state.

That is the minimum safe machine: **6 states, 7 transitions.** Every state has distinct
authorization semantics; no state exists only to describe progress.

### 22.2 Per-state capability matrix (the 14 questions)

Composed verdict; `operationalHold` shown as the commercial-suspension column.

| Question | PROSPECT | ONBOARDING | ACTIVE | opHold | CLOSING | CLOSED | ARCHIVED |
|---|---|---|---|---|---|---|---|
| 1. Users generally can… | nothing | nothing | everything | read only | everything | read only | nothing |
| 2. Admins can… | edit tenant record (SA) | full config + import | full | read + billing actions | full + closure console | restricted actions | SA-mediated read |
| 3. Students can… | – | – | normal | – | normal | – | – |
| 4. Parents can… | – | – | full | read-only, no payment | full + closure notice | read-only, time-boxed | – |
| 5. Teachers can… | – | pilot only | full | read-only | full | read own history | – |
| 6. Staff can… | – | pilot only | full | read-only | full | – | – |
| 7. Fees collected? | ❌ | ❌ | ✅ | ⚠️ in-flight capture only | ✅ (settlement) | ❌ | ❌ |
| 8. Attendance recorded? | ❌ | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ |
| 9. New admissions? | ❌ | ⚠️ migration only | ✅ | ❌ | ❌ | ❌ | ❌ |
| 10. Documents generated? | ❌ | ❌ | ✅ | ❌ | ✅ prioritised | ⚠️ restricted actors | ⚠️ SA reprint, logged |
| 11. Notifications sent? | ❌ | ❌ | ✅ | ⚠️ billing/system only | ✅ + closure notices | ⚠️ system only | ❌ |
| 12. Scheduled jobs run? | ❌ | ⚠️ import/validation only | ✅ | ⚠️ **billing + safety only** | ✅ | ⚠️ retention jobs only | ⚠️ retention jobs only |
| 13. APIs execute? | ❌ | admin/import | ✅ | read + payment capture | ✅ | read + approved actions | SA only |
| 14. Historical data visible? | n/a | n/a | ✅ | ✅ (read) | ✅ | ✅ restricted | ✅ SA-mediated, logged |

Row 12 is the one that currently has **no implementation at all** (G2).

### 22.3 School Health / Executive Dashboard — what to include

Include only metrics that (a) already exist as data, and (b) change an operator's action:

**Keep:** enrolment (count + trend vs capacity), attendance % (today/7-day), fee collection %
(collected/demanded, overdue ageing), accounting exceptions (unposted/unreconciled count), staffing
(active staff, vacancies vs sanctioned), transport utilisation (occupied seats, active routes),
communication delivery failures, **operational readiness score**, and **lifecycle state with next
scheduled transition**.

**Exclude:** academic-performance indices (contested, easily misused, and comparative
school-vs-school scoring invites misuse), infrastructure inventory (no data), "compliance %" (a
percentage cannot represent a legal state), and any composite "school health score" presented as a
single number — see §26.

---

## 23. Lifecycle Events & Cross-System Integration

### 23.1 Do NOT build an event bus

ZenX has exactly one dispatcher pattern that works — the `MARK_REGISTRY` in `functions/index.js`, the
*only* push dispatcher, with `emit_push($mark, $dedupeKey, $fields)`. The correct architecture here is
the same: **an append-only event log + explicit synchronous calls**, not a pub/sub fabric.

- **Record** every transition in `schoolLifecycleEvents` (the ledger).
- **Act** synchronously and explicitly at the transition site for the small number of side effects
  that must happen (mirror `tenantPublic`, recompute readiness, emit operator notifications).
- **Consume** state everywhere else by *reading the current state through the resolver* — pull, not
  push. Modules do not subscribe to lifecycle events; they ask the resolver.

This avoids the classic failure where a module's behaviour depends on having *received* an event, so a
missed event leaves it permanently wrong. Reading current state is idempotent and self-healing.

**Canonical event names (log entries, not messages):** `SCHOOL_CREATED`, `SCHOOL_ONBOARDING_STARTED`,
`SCHOOL_GO_LIVE`, `SCHOOL_OPERATIONAL_HOLD_APPLIED` / `_RELEASED`, `SCHOOL_CLOSING_STARTED`,
`SCHOOL_CLOSED`, `SCHOOL_ARCHIVED`, `SCHOOL_REOPENED`. Session events (`ACADEMIC_SESSION_OPENED`,
`_FROZEN`, `_CLOSED`, `_ARCHIVED`) should be written to the **same** log with `axis: 'session'` — they
happen today with no durable record beyond per-module audit entries, and unifying them is cheap.
Student/staff events (`STUDENT_ADMITTED`, `STAFF_SEPARATED`, …) stay where they are; the lifecycle log
must not become a second copy of module history.

### 23.2 Cross-system integration table

Format: **Source → State → Consumer → Expected behaviour → Failure mode → Recovery**

| Source | State | Consumer | Expected behaviour | Failure mode | Recovery |
|---|---|---|---|---|---|
| Resolver | opHold / CLOSED | `firestore.rules` | client reads/writes denied | rules drift from PHP verdict | parity test (§25); rules are the backstop |
| Resolver | any deny | `MY_Controller` bootstrap | admin request refused with a typed message | fail-open on Firestore read error → over-permissive | keep existing fail-open posture for *reads*, fail-closed for *writes*; alert on read failure |
| Resolver | opHold / CLOSING / CLOSED | **all 11 crons** | job skips the tenant, logs a skip | **today: no check at all (G2)** | shared guard helper + a test asserting every job calls it |
| `tenantPublic` | any | Parent/Teacher apps | UI mode switch + explanatory banner | **today: none (G3)** → raw PERMISSION_DENIED | app-side subscription; until then, map denied errors to a friendly state screen |
| Institution → CLOSING | effective date | Admission (`Sis`, `Admission_public`) | new admissions refused with a reason | portal keeps accepting applications | resolver check at the admission request boundary |
| Institution → CLOSING/CLOSED | effective date | Fees generation + late-fee cron | no new demands; accrual stops | penalties accrue on a closed school | guard in `Fees_late_fee_cron` (Phase 1) |
| Institution → CLOSED | — | Accounting | postings restricted to approved adjustments | silent posting into a closed period | reuse period-lock (`PERIOD_LOCKED`) semantics |
| Institution → CLOSING | effective date | TMS | no new trips after date; in-flight complete; SOS always allowed | a trip cancelled mid-route | explicit carve-out in the resolver: `allowSafetyOps` is true in every state where a trip can exist |
| Institution → CLOSED | — | Documents/Certificates | restricted actor set; every issuance logged | certificates issued by anyone | RBAC `manage` + lifecycle gate, both |
| Institution → ARCHIVED | — | Storage | files retained; reads logged | orphaned files / silent deletion | retention schedule; deletion is a separate job |
| Institution → ARCHIVED | — | Notifications/`MARK_REGISTRY` | dispatcher skips archived tenants | pushes to parents of a closed school | tenant check in the dispatcher |
| Go-live | ONBOARDING→ACTIVE | Readiness engine | recompute + snapshot into the event | stale readiness approves a broken tenant | recompute at transition time, never trust the cache |
| Any transition | — | `schoolLifecycleEvents` | append-only record written before side effects | partial transition (state written, event lost) | write event first, then state, then mirror; idempotency key makes retry safe |

### 23.3 Security Threat Model

| # | Threat | Realistic today? | Mitigation |
|---|---|---|---|
| T1 | Unauthorized school activation (a non-SA flips a tenant live) | No — SA-only surfaces | Keep transitions SA-only; add reason + event; never expose a school-side "activate" |
| T2 | Unauthorized suspension (denial of service against a school) | **Partly** — one SA POST, no reason, no second person (G8) | Reason mandatory; notify the school; two-person for destructive states; full event log |
| T3 | Privilege escalation via a new lifecycle module key | Possible if built carelessly | New RBAC key grants `view`/`edit` only; execution stays SA-side; use `has_permission`, never `_require_role` |
| T4 | Tenant crossover through a group/campus feature | No (no groups today) | D1/D3 in §13: no cross-tenant writes, group views are read-only fan-outs |
| T5 | **Stale ID tokens after suspension** — a session minted pre-suspension keeps working | **Real.** Rules re-evaluate per request so Firestore denies, but a PHP session may persist until expiry | Resolver check in `MY_Controller` on every request (not only at login); short session TTL; force re-login on state change |
| T6 | Parent/teacher access after closure | Would be real once CLOSED exists | Time-boxed read-only window, then disabled; enforced in rules, not only in the app |
| T7 | **Scheduled jobs against suspended/closed tenants** | **Real today (G2)** — including parent-facing late-fee accrual | Shared guard in all 11 jobs; a test that fails if a job controller lacks the call |
| T8 | Payment collection after financial closure | Would be real | `allowPayments` in the resolver; reuse `PERIOD_LOCKED`; adjusting entries approval-gated |
| T9 | Document/certificate access after archive | Would be real | SA-mediated reads, logged per issuance; Storage rules gated on institution state |
| T10 | **Data-export abuse** (a compromised admin exfiltrates a tenant at closure) | Real once export exists | Rate-limit; two-person on full-tenant export; log actor + checksum + delivery target; never email raw data |
| T11 | Lifecycle transition replay (a retried request double-applies) | Real for any new endpoint | Idempotency key `{schoolId}:{toState}:{effectiveDate}`; event-first write |
| T12 | Concurrent transitions (two SAs act simultaneously) | Real | Same lock + `__updateTime` CAS as `Session_lifecycle`; reject with 409 as `archive_session` already does |
| T13 | TOCTOU (state checked at entry, changed before the write lands) | Real | Re-check inside the transaction for state-dependent writes |
| T14 | Incomplete transaction boundary (state flipped, side effects half-applied) | Real | Event → state → mirror ordering; mirror failure is non-fatal and self-heals on next read; never the reverse order |
| T15 | Rules/PHP verdict divergence | Real (already a known class of risk) | Parity test asserting both implementations agree on every state combination |
| T16 | Service-account writes bypassing the gate (by design) | **Real and currently undocumented per module** (G10) | Resolver enforced in PHP too; document which back-office paths are *intentionally* exempt (billing, audit, retention) |
| T17 | Suspension used to hide a dispute / destroy evidence | Real governance risk | Immutable event log; suspension never deletes; export right survives |

---

## 24. Implementation Phasing

Not all phases are necessary, and **Phase 1 delivers most of the value**. Each phase is independently
shippable and independently valuable; stop whenever the value stops.

| Phase | Scope | Justified? | Depends on |
|---|---|---|---|
| **0 — Research/architecture** *(this document)* | Decide (B); ratify the two-axis model | ✅ done | — |
| **1 — Safety fixes (no new architecture)** | (a) shared tenant-state guard called by **all 11 crons**, esp. `Fees_late_fee_cron`; (b) reason capture on suspend/activate; (c) SA audit off RTDB into `schoolLifecycleEvents`; (d) map app permission-denials to a friendly state screen | ✅ **highest value/effort ratio in the whole programme; do this even if everything else is rejected** | nothing |
| **2 — Institution state axis** | `schools.institution.state` (6 states) + resolver (PHP + rules) + event log + SA console showing both axes | ✅ | Phase 1 |
| **3 — Institution identity & compliance fields** | UDISE, affiliation type/validity, legal entity, category/medium/management, governance + DPO/POSH/POCSO contacts, timezone/geo; profile-history for governance fields | ✅ (statutory value, low risk) | can run parallel to 2 |
| **4 — Readiness engine + onboarding states** | Scorer over existing module data; wizard creates ONBOARDING, go-live is an explicit act | ✅ | 2, 3 |
| **5 — App lifecycle awareness** | `tenantPublic` subscription in Parent + Teacher; read-only modes; banners | ✅ | 2 |
| **6 — Closure procedure** | CLOSING + checklists that *call* existing reconcilers; closure console; work-lists | ✅ | 2, 4, and an F&F answer for the staff checklist |
| **7 — Archive & retention** | Export with checksum + delivery record; retention schedule per data class; ARCHIVED with logged access | ✅ | 6, plus a written retention policy decision |
| **8 — Group/campus seams** | Reserve `orgId`; read-only group dashboard | ⚠️ only when a group customer signs (reserve the field in Phase 2 regardless) | 2 |
| **9 — Analytics/AI readiness** | Nothing built; ensure the event log is queryable | ⚠️ optional | 2 |

**Sequencing note against existing programme state:** this work touches `schools/{schoolId}`,
`MY_Controller`, `firestore.rules` and every cron — the same surfaces as several parked programmes
(Entitlements cert, Transport freeze, Role-surface gate, Phase 20 capacity). Phase 2 onward should not
start until those are committed or explicitly parked, or the diff becomes unreviewable. **Phase 1 is
small and isolated enough to land independently.**

---

## 25. Testing Strategy

| Layer | What must be proven |
|---|---|
| **Unit** | State-machine legality: every allowed transition accepted, every illegal one rejected (36 ordered pairs over 6 states); resolver truth table over commercial × institution; readiness scorer per criterion, including "module not entitled ⇒ skipped, not failed" |
| **Integration** | Transition writes event → state → mirror in that order; mirror failure leaves state correct and self-heals; readiness recomputed at go-live |
| **Cross-module** | For each of the 14 questions in §22.2 × 7 states: assert the module actually refuses. Especially admissions, fee generation, payment capture, attendance capture, certificate issuance, trip start |
| **Firestore security rules** | Jest emulator suite (the repo already runs 199+): reads/writes denied per institution state, per collection; `schoolLifecycleEvents` unwritable by any client; `tenantPublic` remains client-readable |
| **Rules/PHP parity** | Assert the PHP resolver and `tenantActive()` agree on **every** (commercial, institution) pair — the pattern `B2_analytics_verify` already uses |
| **RBAC** | New module key grants view/edit only; no path lets a school-side role execute a transition; legacy `_require_role` not used on the new surfaces |
| **Concurrency** | Two simultaneous transitions → exactly one winner, 409 for the other (same probe shape as the capacity race test); lock timeout path returns a typed error |
| **State-transition** | The five named paths end-to-end: ACTIVE→opHold, opHold→ACTIVE, ACTIVE→CLOSING, CLOSING→CLOSED, CLOSED→ARCHIVED — each verified across **every** connected module, not just the controller |
| **Negative** | Replayed transition is a no-op; future-dated transition doesn't take effect early; override without a reason is rejected; go-live with blocking readiness is refused |
| **Regression** | Full existing suite against the documented baseline (4 failures + 27 skipped); rules suite green; no change in behaviour for tenants left in ACTIVE |
| **Cross-platform** | Parent and Teacher apps in each state: correct mode, correct message, no crash, no infinite retry loop on denial |
| **Migration** | Backfill every existing tenant to `institution.state = ACTIVE` idempotently; dry-run first (`--commit` discipline); re-run safe |
| **Closure/archive** | Checklist blocks on each unresolved class (dues, refunds, unposted journals, unpublished results, active trips, pending separations); export contains what it claims; checksum verifies |
| **Recovery** | Reopen from CLOSED and ARCHIVED restores access without touching history; a partially-applied transition is detectable and repairable |
| **Performance** | No additional Firestore reads in rules (the `schools` doc is already fetched); resolver adds ≤1 cached read per request; readiness computation bounded and never run on a timer across all tenants; no N+1 in the closure checklist |
| **Audit-trail** | Every transition produces exactly one event with all mandatory fields; events are immutable (update/delete denied server-side); the SA timeline renders them |

**Explicitly required negative test for G2:** a test that enumerates cron controllers and fails if any
of them lacks the tenant-state guard. Without it the gap silently returns.

---

## 26. Future AI / Analytics Opportunities

The lifecycle event log plus readiness snapshots create a genuinely useful dataset. Nothing should be
built now.

**Good candidates** (advisory, reversible, human-in-the-loop):
- Onboarding-effort and go-live-date prediction from readiness trajectories.
- Enrolment forecasting and section-capacity planning (the capacity engine already holds the truth).
- Fee-collection forecasting and cash-flow projection.
- Staffing-requirement forecasting from enrolment and timetable load.
- Transport capacity/route planning from occupancy history.
- Operational anomaly detection (attendance collapse, collection stall, sudden config change).
- Year-end closure assistant: "here are the 12 unresolved items blocking your rollover."
- Compliance reminders driven by *dated* fields (affiliation validity, recognition renewal).

**Dangerous / inappropriate** (do not automate, do not score):
- Dropout/withdrawal "risk" attached to a named child — high harm, contestable, and a profiling
  concern under DPDPA for under-18s.
- Any staff performance or disciplinary inference.
- Automated financial actions (writing off dues, issuing refunds, generating demands).
- Automated lifecycle transitions — **especially closure or suspension.** A model must never decide a
  school is closed.
- A single public "school health score" used comparatively between schools — it will be gamed,
  contested, and misread, and it hides the actionable detail underneath.

**Design consequence for now (free):** make the event log queryable, keep readiness snapshots with the
events, and keep timestamps server-side. That is the entire "AI readiness" work item.

---

## 27. What ZenX Should NOT Build

1. **A workflow/BPM engine.** Checklists attached to six states, evaluated on demand. Nothing more.
2. **An event bus / pub-sub fabric.** An append-only log plus pull-based resolver reads (§23.1).
3. **Microservices** for lifecycle. This is a library, a state field, and a console.
4. **A twelve-state machine.** `DRAFT`/`CONFIGURING`/`MIGRATING`/`VALIDATING`/`READY`/`PILOT` carry no
   distinct authorization semantics (§22.1).
5. **A `SUSPENDED` institution state.** The commercial axis owns suspension; two truths guarantee
   divergence.
6. **An organization/campus hierarchy now.** Reserve `orgId`; build nothing (§13).
7. **Configuration inheritance from a group.** Copy-on-create, never inherit-at-read.
8. **A second configuration store.** Institution identity lives on the existing `schools` doc.
9. **Duplicated student/staff/fee/ledger state** inside the lifecycle layer. It reads; it never copies.
10. **A parallel freeze mechanism.** Reuse session freeze + `PERIOD_LOCKED`.
11. **Approval chains** beyond two-person on the three destructive transitions.
12. **Cross-tenant data migration** for mergers in v1 — it breaks tenant isolation.
13. **Automatic staff separation or student withdrawal on closure.** Work-lists, not side effects.
14. **Deletion as part of closure.** Retention schedule only, separately executed.
15. **UDISE/APAAR/DigiLocker "integrations"** claimed but unattested. Carry the identifiers.
16. **Any AI in the lifecycle path.**
17. **A generic field-level history for all configuration.** Governance-field allow-list only.
18. **A new RBAC model.** One module key; graded RBAC untouched.

---

## 28. Final Recommendation

### **(B) Yes — implement School / Institution Lifecycle as an orchestration layer, not a module.**

**Why not (A) first-class module:** a module owns data. This layer must own almost none — the
authoritative data lives in Student Lifecycle, Staff Lifecycle, Fees, Accounting, HR, TMS and the
session engine, all of which are mature. Making it a module would create a second source of truth,
which is the one thing the platform's architecture rules forbid.

**Why not (D) sufficient as-is:** three findings make "do nothing" untenable — crons operating on
suspended tenants (including parent-facing penalty accrual), apps that render suspension as a bug, and
the complete absence of any way to represent closure while Indian regulators (UDISE+, CBSE) require
exactly that, with obligations that *outlive* the closure.

**Why not (C) partial:** the partial answer collapses into (B) anyway. What (C) would pick — cron
guards, reason capture, closure — needs a state to hang off, and that state *is* the orchestration
layer. The layer is genuinely small: one state field, one resolver, one append-only collection, one
console.

**The recommended architecture, restated:** two orthogonal axes (commercial — existing, untouched;
institution — new, six states), composed by one most-restrictive-wins resolver implemented in both PHP
and rules and held in sync by a parity test; an append-only Firestore event log as the history; a
readiness scorer computed from existing module data; a closure procedure that *calls* the existing
reconcilers rather than re-deriving anything; and the academic session lifecycle left exactly as it is.

**If only one thing is approved, approve Phase 1** — the cron guard, the reason capture, the RTDB
audit removal, and the app error mapping. It is small, isolated, fixes live defects, and prejudges
nothing.

---

## 29. Final Executive Answer

> *If you were designing ZenX today as a serious 10-year school ERP platform, would you create a
> School Lifecycle layer? If yes, what exactly would it be responsible for, and what would you
> deliberately leave inside the domain modules?*

**Yes — but a deliberately small one, and I would be strict about its job.**

I would make it responsible for exactly five things:

1. **Answering "does this institution exist, and in what operational state?"** — one state field, six
   values, with a reason and an effective date on every change.
2. **Being the single gate that every surface asks before acting** — admin panel, both apps, and
   *especially* every scheduled job. One resolver, most-restrictive-wins across the commercial and
   institution axes, implemented in PHP and in Firestore rules and tested for parity.
3. **Holding institutional identity and compliance facts** — UDISE code, board affiliation with dated
   validity, legal entity, category/medium/management type, governance and data-protection contacts.
   Facts a regulator asks for and no module owns.
4. **Being the immutable history of institutional acts** — an append-only event log of who changed
   what, when, why, effective when, and with which readiness/closure evidence.
5. **Running two procedures that no module can own because they span all of them** — go-live
   readiness, and closure/archival with retention.

And I would deliberately leave **outside** it:

- **Student Lifecycle** — admission, promotion, TC, alumni. The layer may block new intake; it may
  never mutate a student.
- **Staff Lifecycle** — separation, F&F, transfers. Closure produces a *work-list*; a human decides.
  The layer informs, never controls.
- **Academic session lifecycle** — planning, rollover, freeze, archive. Already correct, already
  concurrency-safe. Two lifecycles, not one.
- **Fees & Accounting** — demands, receipts, ledgers, reconciliation, period locks stay authoritative.
  The layer gets a veto on *new obligations* and zero write authority over money.
- **TMS** — routes, trips, GPS, SOS. The layer may block new trips; it may never stop a bus with
  children on it.
- **HR/Payroll, Communication, Documents, Exams** — untouched; they read the gate.
- **RBAC** — untouched. One new module key, graded permissions preserved.

The one-line version: **the lifecycle layer should know *whether* the school may act, and remember
*that it decided so*; every module keeps knowing *what* to do.** ZenX already has the hard parts —
a rules-enforced tenant gate and a concurrency-safe session engine. What's missing is the institution
itself: it has a billing state and an academic calendar, but no way to say "this school opened, this
school closed, and here is the evidence." For a ten-year platform operating under UDISE+ and CBSE,
that is the gap worth closing — and it is roughly one state field, one resolver, one log, and one
console away.

---

## 30. Research Sources / Evidence

**Primary — product documentation**
- PowerSchool SIS — End-of-Year Process: https://ps.powerschool-docs.com/pssis-admin/latest/end-of-year-process
- PowerSchool Special Programs — End-of-year rollover: https://sp-programs.powerschool-docs.com/special-programs-sys-admin/latest/end-of-year-rollover
- PowerSchool EOY manual (SC Dept. of Education): https://ed.sc.gov/data/information-systems/power-school/sis-documents/powerschool-end-of-year-process-manual-for-sc-users/
- Gibbon — Rollover School Year: https://docs.gibbonedu.org/administrators/timetable/rollover/
- Gibbon — School Structure: https://docs.gibbonedu.org/getting-started/school-setup/school-structure
- Gibbon — The Gibbon Lifecycle: https://docs.gibbonedu.org/guides/administration/gibbon-lifecycle
- Frappe/ERPNext — Education Settings: https://docs.frappe.io/education/education-settings
- ERPNext — Academic Year: https://docs.frappe.io/erpnext/user/manual/en/academic-year
- ERPNext v13 — Education Settings: https://docs.erpnext.com/docs/v13/user/manual/en/education/education-settings

**Primary — architecture reference**
- Microsoft Azure Architecture Center — Tenant Life Cycle Considerations in Multitenant Solutions: https://learn.microsoft.com/en-us/azure/architecture/guide/multitenant/considerations/tenant-life-cycle
- Azure Architecture Center — Architect Multitenant Solutions on Azure: https://learn.microsoft.com/en-us/azure/architecture/guide/multitenant/overview

**Primary — regulatory (India)**
- CBSE Affiliation Bye-Laws (official PDF): https://www.cbse.gov.in/examin~1/Affiliation%20Bye%20Laws%20Word%20(Final)%20for%20uploading%20on%20the%20website_2012.pdf
- UDISE+ 2025-26 — guidance on school closures, mergers, upgrades: https://educationforallinindia.com/guidance-note-for-udise-2025-26-addressing-school-closures-mergers-and-upgrades/ *(secondary summary of an official guidance note; fetch was 403 — claims here are corroborated by the FAQ and module sources below and should be re-verified against the official UDISE+ portal before implementation)*
- UDISE+ 2025-26 modules & user manual: https://educationforallinindia.com/udiseplus-2025-26-modules-user-manual/
- UDISE+ 2025-26 data-entry FAQs: https://educationforallinindia.com/comprehensive-faqs-for-udise-2025-26-data-entry/
- UDISE+ school merger handling: https://udisseplus.com/merging-schools/

**Secondary — compliance analysis (DPDPA 2023 / DPDP Rules 2025)**
- EY India — DPDP Act 2023 and DPDP Rules 2025 compliance guide: https://www.ey.com/en_in/insights/cybersecurity/decoding-the-digital-personal-data-protection-act-2023
- EY India — Transforming data privacy, DPDP Rules 2025: https://www.ey.com/en_in/insights/cybersecurity/transforming-data-privacy-digital-personal-data-protection-rules-2025
- Children's data under DPDP Act & Rules — EdTech compliance: https://www.mondaq.com/india/privacy-protection/1710322/childrens-day-under-the-dpdp-act-2023-and-dpdp-rules-2025-the-new-compliance-frontier-for-edtech-gaming-social-media-and-consumer-platforms
- DPDP compliance for EdTech & schools: https://ksandk.com/data-protection-and-data-privacy/dpdp-act-compliance-for-edtech-schools/
- Digital Personal Data Protection Rules, 2025 (overview): https://en.wikipedia.org/wiki/Digital_Personal_Data_Protection_Rules,_2025

**Secondary — market / vendor claims (treated as claims, not verified capability)**
- edumerge — school ERP / institution groups: https://www.edumerge.com/products/school-erp
- Indian school ERP comparisons: https://decentro.tech/blog/best-school-erp-software/ · https://nletschool.com/best-school-erp-in-2025-comparison-of-top-systems-for-indian-institutions
- ERPNext for educational institutions — implementation guide: https://www.ksolves.com/blog/erpnext/erpnext-for-educational-institutions
- PowerSchool vs Ellucian Banner positioning: https://www.trustradius.com/compare-products/ellucian-banner-vs-powerschool-student-information-system

**Primary — ZenX codebase (read-only inspection, this repository)**
- `application/libraries/B2_registry_service.php` — `ACCESS_LIFECYCLE_STATES`, `lifecycle_access()`, `login_access_view()`, `write_lifecycle_state()`, `create_tenant()`
- `firebase-rules/firestore.rules:18-66` — `tenantActive()` and its design commentary
- `application/core/MY_Controller.php` — bootstrap lifecycle gate (~L557-565), `_is_session_frozen()`, `_abort_if_session_frozen()`, period lock
- `application/libraries/Session_lifecycle.php` — `add_session()` lock + CAS
- `application/controllers/School_config.php` — `save_profile()`, `add_session()`, `set_active_session()`, `preview_rollover()`, `rollover_session()`, `archive_session()`, `delete_session()`
- `application/controllers/Superadmin_schools.php` — onboarding flow, `update_status` allow-list (L602), status derivation from `adminDisabled`
- `application/controllers/Superadmin_plans.php` — automated grace → suspended transitions
- `application/core/MY_Superadmin_Controller.php:184-199` — `sa_log()` writing to RTDB
- Cron/job controllers (11) — lifecycle-reference audit
- `application/views/superadmin/schools/create.php`, `application/views/superadmin/schools/view.php`, `application/views/school_config/index.php` — UI field inventory
- Parent/Teacher Kotlin sources (254 / 249 files) — zero `tenantPublic` / `lifecycleState` references

**Evidence classification used throughout:** ✅ verified product capability from primary docs ·
📣 vendor marketing claim · ⚠️ partial/inferred · 🆕 architectural recommendation by this document
(not an observed capability anywhere).
