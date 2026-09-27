# ZENX HRMS — POLICY ARCHITECTURE EXECUTION PLAN (v2)

**Date:** 2026-08-10 · **Type:** execution plan · **NOTHING IMPLEMENTED — awaiting approval**
**v2 supersedes v1** — rebuilt around the operator's department-keyed model. See §9 for what changed.

**Goal:** one assignable-policy mechanism covering schedules, shifts, holidays, attendance policy,
leave, rosters, overnight and overtime — without breaking 8 live tenants or any issued payslip.

---

## 0. WHAT "100% BULLETPROOF" MEANS — the binding definition

Not a slogan. Every phase must satisfy all nine; each is independently testable.

| # | Property | How it is proven |
|---|---|---|
| B1 | Every (employee, date) resolves to **exactly one** policy **or blocks** | block-code tests; **no default branch exists** |
| B2 | Overlapping assignments **refused**, never resolved by read order | overlap test per attachment type |
| B3 | Two rules matching at the **same level** = **refused**, never arbitrary | tie test |
| B4 | **No fallback path exists in code** | literal-source-regression guard asserting 0 occurrences |
| B5 | Resolution is **explainable** — returns the chain, not just the answer | trace test |
| B6 | Result **frozen** onto the payslip, never recomputed | snapshot fields asserted on both writers |
| B7 | **Historical payslips reproduce byte-identically** | April + PR0003 re-render diff = 0 |
| B8 | **Tenant isolation** on every read/write | `schoolWhere()` / `docId()` source guard |
| B9 | **Teacher + Parent apps** verified unaffected or updated | cross-platform statement per phase |

**A phase is not done until all nine pass. PARTIAL is a tracking state, never a product exclusion.**

## 1. THE MECHANISM — department-keyed, three levels

```
   DEPARTMENT   offers one or more schedules  +  names holidays
        |
        |   if the department offers MORE THAN ONE, the staff member picks
        v
   STAFF PICK   (e.g. Security-Day vs Security-Night)
        |
        |   an individual assignment overrides everything, with an effective date
        v
   INDIVIDUAL OVERRIDE
        |
        v
   nothing attached  ->  BLOCK  (never guessed, never defaulted)
```

**Verified facts this rests on:**

| Fact | Source | Consequence |
|---|---|---|
| `staff.department` is a **single string** | `Firestore_service.php:1070` | department is safe as a key — **ties are structurally impossible** |
| `staff_roles[]` is an **array** | RBAC `union(staff_roles[])` | **never key schedules on role** — a 2-role staff member would match 2 schedules |
| Departments already exist with CRUD | `Org.php` | the group layer is **mostly already built** |

**There is no organisation-default level.** An unassigned employee blocks. This is structural, not a
rule to remember — it is what makes B1 hold by construction.

**A personal schedule is just a schedule with one person attached.** No separate mechanism: a
schedule does not care whether 40 people use it or 1. Only the audience differs.

### 1.1 Extensibility hook — built in Phase 0, unused until needed

The assignment record stores **what it attaches to**:

```
attachment_type : 'department' | 'staff'          <- Phase 0 ships these two
                  'location' | 'employment_type'  <- reserved, resolver already tolerates
```

Adding a level later becomes **a new value in an existing field**, not a resolver rewrite. This keeps
two known future cases open at near-zero cost (§8).

## 2. MIGRATION SAFETY — the honest distinction

Two tenant-wide maps must move without breaking 8 live tenants. They are **not** the same case:

| Existing data | Treatment | Why |
|---|---|---|
| `schools/{id}.attendancePolicy` (timings, grace, autoAbsent) | **Migrate 1:1 to a department-less org policy object** | operator-declared, in active use — lossless, zero behaviour change |
| `schools/{id}.leaveTypes` | **Migrate 1:1** | same |
| `attendancePolicy.weeklyOffs` → a work **schedule** | **DO NOT auto-migrate. Pre-fill the admin form as an unconfirmed suggestion the operator must accept.** | declared for *attendance*, never for *payroll*. Auto-adopting makes payroll use a value never declared for pay — the exact inference refused in N-23 |
| Legacy 2nd/4th-Saturday rule | **Never migrated, never consulted** | already removed; survives only as `legacyRuleDescription()` |

**Migrating declared data is not inventing a default.** Payroll stays **blocked** until a human
confirms a schedule. The block is preserved. **This is decision D4.**

## 3. PHASES

Each phase = one production-ready capability = one commit candidate (held, not pushed).

### PHASE 0 — Policy assignment engine (foundation)
**Delivers:** `Policy_assignment_service` — generic resolver for any policy type.
**Why first:** everything else is a consumer.

- Three-level resolution: department → staff pick → individual override
- `attachment_type` field with the two reserved future values (§1.1)
- Effective dating via `ed_select_for()` / `ed_find_overlaps()`; overlaps refused (B2)
- Ties refused (B3) — though department being single-valued makes them near-impossible by construction
- Block codes; **no default branch** (B1, B4)
- `explain()` returning the resolution chain (B5)
- `Work_schedule_service::effectiveAssignment()` **becomes a caller, not a copy**

**Tests:** ~25 unit (down from ~35 — two fewer levels). Precedence, ties, overlaps, gaps, blocks,
explain-chain, source guards.
**Risk:** none at runtime — nothing calls it yet.

### PHASE 1 — Schedules + assignments admin surface  ← **UNBLOCKS EVERYTHING**
**Delivers:** the screen. Without it no tenant can configure anything and payroll stays blocked.

- Schedule CRUD — pattern, timings, effective dates, versions
- **Attach a schedule to a department** (the common case — inherited automatically)
- **Department offering 2+ schedules** → per-staff pick (day / night)
- **Personal schedule** — create and attach to one person
- **Individual override** with effective date
- Pre-filled `weeklyOffs` suggestion, **explicitly unconfirmed** (§2 / D4)
- **Blocked-employee panel** — who cannot be paid, and exactly why

**Consequence:** payroll runnable for the first time since N-23. UAT Test 5 unblocks.
**Tests:** RBAC gating, CSRF exclusion, tenant isolation, fail-closed panel JS (`r.ok` **and** `status`).
**Rules/indexes:** `workSchedules`, `staffScheduleAssignments` match blocks + composite indexes.

### PHASE 2 — N-35: migrate Attendance's three competing calendar authorities
**Delivers:** attendance and payroll agree, per employee, per date.

| Consumer | Change |
|---|---|
| `Staff_attendance_finaliser` (`O` overlay) | root `weeklyOffs` → resolved employee calendar |
| `Staff_attendance.php:206` (punch-time `W`) | same — **without this `W` can never be stamped correctly** |
| `views/attendance/staff.php:618` (grid edit lock) | hardcoded Sundays → resolved calendar (Addendum A) |

**Also decided here:** D2 — `A` marked on a rest day.
**Recommendation: reinterpret via `working_mask`; do not rewrite attendance history.**
**Tests:** Mon–Fri tenant, Sunday-working tenant, mixed-department tenant. B7 re-verified.

### PHASE 3 — Holidays keyed on department
**Delivers:** Diwali applies to Teaching + Accounts, not Security.

- `departments[]` on the holiday record — **currently no scoping field exists at all**
- `exceptStaff[]` for individual exceptions (same shape as schedule override)
- Resolver already accepts per-employee holidays — `resolveEmployeeCalendar(..., $holidays, ...)`. **No re-plumbing.**
- **Empty `departments[]` = applies to all** → every existing holiday behaves identically (B7)
- Student/academic holidays stay school-wide — unchanged

**Same mechanism as schedules. Not a second grouping system.**

### PHASE 4 — N-36: retire the interim `workingWeek` model
**Only safe after Phase 1 exists.** Removes `Working_week` controller, routes, UI card,
`Working_calendar_service::effectiveVersion()` / `resolveForMonth()`.
`legacyRuleDescription()` **retained** — historical interpretation (B7).

### PHASE 5 — Attendance policy becomes assignable  → closes **P-012**, **P-017**
Timings, grace, half/full-day hours, capture method, `allowWorkOnOff` move onto the schedule object.
`autoAbsent` and month-lock **stay tenant-level** — operational switches, not employment terms.
Existing map migrated 1:1 (§2). Day-one behaviour identical.

### PHASE 6 — Leave becomes assignable + effective-dated  → closes **P-028 / P-030 / P-032**
Quotas per department; effective dating so editing a quota **cannot rewrite last year's balances**.
Highest data-integrity value after Phase 2.

### PHASE 7 — Rotating / roster schedule type  → closes **P-013**
Third schedule kind alongside Fixed and Flexible. Roster published before period close; payroll
denominator = rostered days. **Assignment mechanism unchanged** — a rotating schedule attaches to a
department exactly like a fixed one.

**Operator decision D3 required:** for unfixed-schedule staff — roster-driven, or attendance-defined
(pay for what they worked)? Until declared, payroll **blocks** for those staff.

### PHASE 8 — Overnight shifts  → closes **P-014**
The only phase that is **not** a policy change. Attendance stores 0–1439 minutes with no day
rollover, so a 22:00→06:00 punch-out cannot be attributed to the previous day's shift. Requires a
shift-day-boundary concept on the attendance record. **Highest regression risk in the plan** — every
punch path is touched. Sequenced last for that reason.

### PHASE 9 — Overtime  → closes **P-015**
OT threshold + multiplier as fields on the schedule; OT hours flow to payroll as a priced component.
`W` extra-day pay retained and kept distinct — **`O`, `H`, `W` never collapse.**

## 4. PER-PHASE GATE — every phase, no exceptions

1. `php -l` on every touched file
2. `vendor/bin/phpunit --testsuite Unit` vs the **documented baseline** (511 / 10 failures), not vs green
3. New literal-source-regression guards for B4 (no fallback) and B8 (tenant isolation)
4. Cross-module impact: every reader **and** writer of touched collections, plus both apps (B9)
5. Firestore rules + indexes — `firestore.rules` re-read immediately before editing, `git diff` before any deploy
6. B1–B9 evidence table in the phase report
7. Historical reproduction check (B7) — April + PR0003
8. Performance: read/write budget, no N+1, batch limits, latency
9. Report → **STOP** → your approval before the next phase

## 5. SEQUENCING — dependencies, not preference

```
   0  engine
   |
   1  schedules admin surface        <-- unblocks payroll + UAT for all 8 tenants
   |
   +-- 2  N-35 attendance migration
   |     |
   |     +-- 4  N-36 retire interim model
   |
   +-- 3  holidays by department
   |
   +-- 5  attendance policy assignable
   |     |
   |     +-- 7  rotating / roster
   |     +-- 9  overtime
   |
   +-- 6  leave assignable
   |
   8  overnight shifts (attendance record change — last, highest risk)
```

Phases 2–6 are independent once Phase 1 lands and may be reordered by business priority.
**Phases 0 and 1 are strictly first. Phase 8 is strictly last.**

## 6. WHAT THIS PLAN DOES NOT DO

- Does **not** create a default schedule or policy to unblock payroll or UAT
- Does **not** infer statutory policy, PT state, leave eligibility, or working days from tenant data
- Does **not** key any schedule on `staff_roles[]` — multi-valued, would create ties
- Does **not** rewrite or delete frozen payroll evidence, journals, or issued payslips
- Does **not** retrofit effective dating by editing existing maps in place
- Does **not** commit, push, deploy, migrate or seed without separate explicit approval
- Does **not** touch statutory config (ST-01…ST-18) — BLOCKED on operator-declared policy

## 7. OPEN DECISIONS

| # | Decision | Gates | Status |
|---|---|---|---|
| ~~D1~~ | ~~department vs employment-type precedence~~ | ~~Phase 0~~ | **CLOSED** — the department-keyed model removes the employment-type level entirely |
| **D4** | Confirm the `weeklyOffs` pre-fill approach (§2) | **Phase 0/1** | **OPEN — required to start** |
| D2 | `A` on a rest day — rewrite to `O`, or reinterpret? | Phase 2 | open · recommend **reinterpret** |
| D3 | Unfixed-schedule staff — roster or attendance-defined? | Phase 7 | open · yours to declare |

## 8. KNOWN LIMITS OF THE DEPARTMENT-KEYED MODEL — accepted, not hidden

| Case | Handling today | If it becomes common |
|---|---|---|
| **Visiting faculty inside Teaching** | own department, or a personal schedule each | add `employment_type` via the §1.1 hook |
| **Two campuses, different holidays** | **not covered** — `locations` do not exist yet | add `location` via the §1.1 hook |

Both are deferred deliberately, both stay reachable through the reserved `attachment_type` values,
and neither is needed today. **Recorded so they are not mistaken for oversights.**

## 9. WHAT CHANGED FROM v1

| | v1 | v2 |
|---|---|---|
| Levels | 5 — employee > employment type > department > location > org default | **3** — department → staff pick → individual |
| Organisation default | included | **removed — unassigned now BLOCKS** |
| Group key | to be built | **`staff.department`, already exists** |
| Day/night shifts | awkward — needed a split department or extra level | **native — a department offers 2+ schedules** |
| Holidays | separate "appliesTo groups" concept | **same department key as schedules** |
| D1 | open question | **closed by design** |
| Phase 0 size | ~35 tests | ~25 tests |

**v1's organisation-default level was a defect** — it contradicted the N-23 rule that an
unconfigured employee must block rather than fall back. v2 makes blocking structural.

## 10. HONEST RISK STATEMENT

- **Phase 8 carries real regression risk** — it changes the attendance record shape and touches every punch path. Last, so everything else is stable and reproducible first.
- **Phase 6 changes leave-balance semantics.** Past balances stop moving when a quota is edited — correct, but it will look like a change. Needs an operator note, not just code.
- **Phase 1 will reveal blocked employees**, possibly many, across all 8 tenants. That is the N-23 exposure becoming visible, not a new fault. **N-26** (historical payslip audit) should run alongside it.
- **This plan does not make the system Asanify-complete.** It closes the *structural* gap behind the feature gaps. Statutory filings (ST-15…ST-18), bank disbursement (P-056), arrears and off-cycle runs remain separately tracked in the Master Register — though Phase 6's effective dating is a prerequisite for arrears.

---

**NOTHING IMPLEMENTED. No code, config, tenant data, seed, migration, commit, push or deploy.
PR0003 and all April evidence untouched. UAT remains paused at Test 4.**

**Required to proceed:** answer **D4**, and authorise Phase 0.
